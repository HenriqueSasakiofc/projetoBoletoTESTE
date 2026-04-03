import hashlib
import html
import re
import smtplib
from datetime import datetime, timezone
from decimal import Decimal
from email.message import EmailMessage

from fastapi import HTTPException
from sqlalchemy.orm import Session

from ..config import settings
from .. import models

ALLOWED_PLACEHOLDERS = {
    "{{nome_cliente}}",
    "{{nome_empresa}}",
    "{{valor_fatura}}",
    "{{data_vencimento}}",
    "{{data_emissao}}",
    "{{saldo_fatura}}",
    "{{saldo_sem_juros}}",
    "{{tipo_cobranca}}",
    "{{razao_social_cliente}}",
    "{{nosso_numero}}",
}
PLACEHOLDER_RE = re.compile(r"\{\{\s*([a-zA-Z0-9_]+)\s*\}\}")
HEADER_INJECTION_RE = re.compile(r"[\r\n]")


def mask_document(value: str | None) -> str | None:
    if not value:
        return None
    digits = re.sub(r"\D", "", value)
    if len(digits) <= 4:
        return "*" * len(digits)
    return f"{'*' * (len(digits) - 4)}{digits[-4:]}"


def mask_email(value: str | None) -> str | None:
    if not value:
        return None
    parts = value.split("@")
    if len(parts) != 2:
        return "***"
    local, domain = parts
    if len(local) <= 2:
        local_masked = local[0] + "*"
    else:
        local_masked = local[:2] + "*" * max(2, len(local) - 2)
    return f"{local_masked}@{domain}"


def mask_phone(value: str | None) -> str | None:
    if not value:
        return None
    digits = re.sub(r"\D", "", value)
    if len(digits) <= 4:
        return "*" * len(digits)
    return f"{'*' * (len(digits) - 4)}{digits[-4:]}"


def sanitize_subject(subject: str) -> str:
    subject = HEADER_INJECTION_RE.sub(" ", subject).strip()
    if not subject:
        raise HTTPException(status_code=400, detail="Assunto inválido")
    return subject[:180]


def validate_placeholders(text: str) -> None:
    found = {f"{{{{{name}}}}}" for name in PLACEHOLDER_RE.findall(text)}
    invalid = sorted(found - ALLOWED_PLACEHOLDERS)
    if invalid:
        raise HTTPException(status_code=400, detail=f"Placeholders inválidos: {', '.join(invalid)}")


def format_money(value: Decimal | None) -> str:
    value = value or Decimal("0.00")
    normalized = f"{value:.2f}".replace(".", ",")
    return f"R$ {normalized}"


def render_template(template_text: str, context: dict[str, str]) -> str:
    validate_placeholders(template_text)

    def replacer(match: re.Match) -> str:
        placeholder = f"{{{{{match.group(1)}}}}}"
        return context.get(placeholder, "")

    rendered = PLACEHOLDER_RE.sub(replacer, template_text)
    return rendered


def build_context(company: models.Company, customer: models.Customer | None, receivable: models.Receivable | None) -> dict[str, str]:
    customer_name = customer.full_name if customer else (receivable.customer_name_snapshot if receivable else "")
    return {
        "{{nome_cliente}}": customer_name or "",
        "{{nome_empresa}}": company.trade_name or company.legal_name,
        "{{valor_fatura}}": format_money(receivable.amount_total if receivable else Decimal("0.00")),
        "{{data_vencimento}}": receivable.due_date.strftime("%d/%m/%Y") if receivable and receivable.due_date else "",
        "{{data_emissao}}": receivable.issue_date.strftime("%d/%m/%Y") if receivable and receivable.issue_date else "",
        "{{saldo_fatura}}": format_money(receivable.balance_amount if receivable else Decimal("0.00")),
        "{{saldo_sem_juros}}": format_money(receivable.balance_without_interest if receivable else Decimal("0.00")),
        "{{tipo_cobranca}}": receivable.charge_type or "cobrança" if receivable else "cobrança",
        "{{razao_social_cliente}}": customer.full_name if customer else customer_name,
        "{{nosso_numero}}": receivable.nosso_numero or "" if receivable else "",
    }


class SmtpMailer:
    def __init__(self) -> None:
        self.host = settings.smtp_host
        self.port = settings.smtp_port
        self.user = settings.smtp_user
        self.password = settings.smtp_pass
        self.use_tls = settings.smtp_tls
        self.mail_from = settings.mail_from or settings.smtp_user

    def _validate_to(self, to_email: str) -> str:
        email = to_email.strip().lower()
        if HEADER_INJECTION_RE.search(email):
            raise HTTPException(status_code=400, detail="Destinatário inválido")
        return email

    def send(self, *, to_email: str, subject: str, body: str) -> None:
        if not self.host or not self.port or not self.user or not self.password:
            raise HTTPException(status_code=500, detail="SMTP não configurado")
        safe_to = self._validate_to(to_email)
        if settings.safe_mode:
            if not settings.test_email:
                raise HTTPException(status_code=500, detail="SAFE_MODE ativo sem TEST_EMAIL")
            if safe_to != settings.test_email.strip().lower():
                raise HTTPException(status_code=400, detail="SAFE_MODE bloqueou envio fora do e-mail de teste")

        msg = EmailMessage()
        msg["From"] = self.mail_from
        msg["To"] = safe_to
        msg["Subject"] = sanitize_subject(subject)
        msg.set_content(body)

        with smtplib.SMTP(self.host, self.port, timeout=20) as server:
            if self.use_tls:
                server.starttls()
            server.login(self.user, self.password)
            server.send_message(msg)


def get_active_template(db: Session, company_id: int) -> models.MessageTemplate:
    template = (
        db.query(models.MessageTemplate)
        .filter(models.MessageTemplate.company_id == company_id, models.MessageTemplate.is_active.is_(True))
        .order_by(models.MessageTemplate.id.desc())
        .first()
    )
    if not template:
        raise HTTPException(status_code=404, detail="Template padrão não encontrado")
    return template


def upsert_active_template(db: Session, company_id: int, subject_template: str, body_template: str) -> models.MessageTemplate:
    validate_placeholders(subject_template)
    validate_placeholders(body_template)
    current = get_active_template(db, company_id)
    current.subject_template = sanitize_subject(subject_template)
    current.body_template = body_template.strip()
    return current


def preview_message(db: Session, company: models.Company, receivable: models.Receivable, subject_template: str, body_template: str) -> tuple[str, str]:
    customer = receivable.customer
    context = build_context(company, customer, receivable)
    subject = sanitize_subject(render_template(subject_template, context))
    body = render_template(body_template, context)
    return subject, body


def build_dedupe_key(*parts: str) -> str:
    raw = "|".join(parts)
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()


def queue_standard_message(db: Session, company: models.Company, receivable: models.Receivable, template: models.MessageTemplate) -> models.OutboxMessage:
    if receivable.status in {"pago", "cancelado"}:
        raise HTTPException(status_code=400, detail="Título não permite cobrança")
    recipient = receivable.billing_email_snapshot or (receivable.customer.email_billing if receivable.customer else None)
    if not recipient:
        raise HTTPException(status_code=400, detail="Cliente sem e-mail de cobrança")
    subject, body = preview_message(db, company, receivable, template.subject_template, template.body_template)
    dedupe_key = build_dedupe_key("standard", str(company.id), str(receivable.id), datetime.now(timezone.utc).strftime("%Y-%m-%d"))
    existing = (
        db.query(models.OutboxMessage)
        .filter(models.OutboxMessage.company_id == company.id, models.OutboxMessage.dedupe_key == dedupe_key)
        .first()
    )
    if existing:
        return existing
    item = models.OutboxMessage(
        company_id=company.id,
        customer_id=receivable.customer_id,
        receivable_id=receivable.id,
        template_id=template.id,
        message_kind="standard",
        recipient_email=recipient,
        subject=subject,
        body=body,
        dedupe_key=dedupe_key,
        status="queued",
    )
    db.add(item)
    receivable.last_standard_message_at = datetime.now(timezone.utc)
    db.add(
        models.ReceivableHistory(
            company_id=company.id,
            receivable_id=receivable.id,
            event_type="message_queued",
            old_status=receivable.status,
            new_status=receivable.status,
            note="Mensagem padrão adicionada à outbox",
        )
    )
    return item


def queue_manual_message(
    db: Session,
    *,
    company: models.Company,
    customer: models.Customer,
    receivable: models.Receivable | None,
    user: models.User,
    subject: str,
    body: str,
) -> models.OutboxMessage:
    recipient = customer.email_billing
    if not recipient:
        raise HTTPException(status_code=400, detail="Cliente sem e-mail de cobrança")
    clean_subject = sanitize_subject(subject)
    preview_hash = build_dedupe_key("manual-preview", str(company.id), str(customer.id), clean_subject, body)
    manual = models.ManualMessage(
        company_id=company.id,
        customer_id=customer.id,
        receivable_id=receivable.id if receivable else None,
        created_by_user_id=user.id,
        subject=clean_subject,
        body=body,
        recipient_email=recipient,
        preview_hash=preview_hash,
    )
    db.add(manual)
    db.flush()
    dedupe_key = build_dedupe_key("manual", str(company.id), str(customer.id), preview_hash)
    existing = (
        db.query(models.OutboxMessage)
        .filter(models.OutboxMessage.company_id == company.id, models.OutboxMessage.dedupe_key == dedupe_key)
        .first()
    )
    if existing:
        return existing
    item = models.OutboxMessage(
        company_id=company.id,
        customer_id=customer.id,
        receivable_id=receivable.id if receivable else None,
        manual_message_id=manual.id,
        message_kind="manual",
        recipient_email=recipient,
        subject=clean_subject,
        body=body,
        dedupe_key=dedupe_key,
        status="queued",
    )
    db.add(item)
    return item


def dispatch_outbox(db: Session, company_id: int, limit: int = 20) -> tuple[int, int]:
    mailer = SmtpMailer()
    sent = 0
    failed = 0
    items = (
        db.query(models.OutboxMessage)
        .filter(models.OutboxMessage.company_id == company_id, models.OutboxMessage.status == "queued")
        .order_by(models.OutboxMessage.created_at.asc())
        .limit(limit)
        .all()
    )
    for item in items:
        try:
            mailer.send(to_email=item.recipient_email, subject=item.subject, body=item.body)
            item.status = "sent"
            item.sent_at = datetime.now(timezone.utc)
            sent += 1
        except Exception as exc:
            item.status = "failed"
            item.error_message = html.escape(str(exc))[:1500]
            failed += 1
    return sent, failed