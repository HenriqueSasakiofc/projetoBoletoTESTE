from __future__ import annotations

import hashlib
import re
import smtplib
from datetime import datetime, timezone
from email.message import EmailMessage
from email.utils import parseaddr

from fastapi import HTTPException
from sqlalchemy import select
from sqlalchemy.orm import Session

from ..config import settings
from ..models import (
    Customer,
    ManualMessage,
    MessageStatusEnum,
    MessageTemplate,
    OutboxMessage,
    Receivable,
)

ALLOWED_PLACEHOLDERS = [
    "customer_name",
    "customer_document",
    "customer_email",
    "receivable_number",
    "nosso_numero",
    "due_date",
    "amount_total",
    "balance_amount",
    "status",
]

_PLACEHOLDER_PATTERN = re.compile(r"\{\{\s*([a-zA-Z0-9_]+)\s*\}\}")


def _format_money(value) -> str:
    if value is None:
        return ""
    try:
        return f"{float(value):.2f}".replace(".", ",")
    except Exception:
        return str(value)


def _format_date(value) -> str:
    if value is None:
        return ""
    try:
        return value.strftime("%d/%m/%Y")
    except Exception:
        return str(value)


def validate_email_address(email: str) -> str:
    email = (email or "").strip()
    if not email:
        raise HTTPException(status_code=400, detail="E-mail do destinatário não informado.")

    if "\n" in email or "\r" in email:
        raise HTTPException(status_code=400, detail="E-mail inválido.")

    _, parsed = parseaddr(email)
    if not parsed or "@" not in parsed:
        raise HTTPException(status_code=400, detail="E-mail inválido.")

    return parsed.lower()


def validate_header_value(value: str, field_name: str) -> str:
    value = (value or "").strip()
    if not value:
        raise HTTPException(status_code=400, detail=f"{field_name} é obrigatório.")
    if "\n" in value or "\r" in value:
        raise HTTPException(status_code=400, detail=f"{field_name} inválido.")
    return value


def build_customer_context(customer: Customer | None) -> dict:
    if not customer:
        return {}

    return {
        "customer_name": customer.full_name or "",
        "customer_document": customer.document_number or "",
        "customer_email": customer.email_billing or "",
    }


def build_receivable_context(customer: Customer | None, receivable: Receivable | None) -> dict:
    data = build_customer_context(customer)

    if not receivable:
        return data

    data.update(
        {
            "receivable_number": receivable.receivable_number or "",
            "nosso_numero": receivable.nosso_numero or "",
            "due_date": _format_date(receivable.due_date),
            "amount_total": _format_money(receivable.amount_total),
            "balance_amount": _format_money(receivable.balance_amount),
            "status": receivable.status.value if receivable.status else "",
        }
    )
    return data


def render_template(subject: str, body: str, context: dict) -> tuple[str, str]:
    subject = validate_header_value(subject, "Assunto")
    body = (body or "").strip()
    if not body:
        raise HTTPException(status_code=400, detail="Corpo da mensagem é obrigatório.")

    placeholders = set(_PLACEHOLDER_PATTERN.findall(subject + "\n" + body))
    invalid = sorted([name for name in placeholders if name not in ALLOWED_PLACEHOLDERS])
    if invalid:
        raise HTTPException(
            status_code=400,
            detail=f"Placeholders inválidos: {', '.join(invalid)}",
        )

    def replacer(match: re.Match) -> str:
        key = match.group(1).strip()
        return str(context.get(key, ""))

    rendered_subject = _PLACEHOLDER_PATTERN.sub(replacer, subject)
    rendered_body = _PLACEHOLDER_PATTERN.sub(replacer, body)
    return rendered_subject, rendered_body


def _sha256_text(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def _build_standard_dedupe_key(company_id: int, receivable_id: int, subject: str, body: str) -> str:
    base = f"standard|{company_id}|{receivable_id}|{subject}|{body}"
    return _sha256_text(base)


def _build_manual_dedupe_key(company_id: int, customer_id: int, recipient_email: str, subject: str, body: str) -> str:
    base = f"manual|{company_id}|{customer_id}|{recipient_email}|{subject}|{body}"
    return _sha256_text(base)


def _get_message_template(db: Session, company_id: int) -> MessageTemplate:
    template = db.execute(
        select(MessageTemplate).where(MessageTemplate.company_id == company_id)
    ).scalar_one_or_none()

    if template:
        return template

    template = MessageTemplate(
        company_id=company_id,
        subject="Lembrete de vencimento - {{receivable_number}}",
        body=(
            "Olá, {{customer_name}}.\n\n"
            "Este é um lembrete sobre o título {{receivable_number}}.\n"
            "Vencimento: {{due_date}}\n"
            "Valor: R$ {{amount_total}}\n"
            "Saldo: R$ {{balance_amount}}\n\n"
            "Se o pagamento já foi realizado, desconsidere."
        ),
        is_active=True,
    )
    db.add(template)
    db.commit()
    db.refresh(template)
    return template


def queue_standard_message(
    db: Session,
    company_id: int,
    receivable: Receivable,
    user_id: int | None = None,
) -> OutboxMessage:
    customer = db.execute(
        select(Customer).where(
            Customer.id == receivable.customer_id,
            Customer.company_id == company_id,
        )
    ).scalar_one_or_none()

    if not customer:
        raise HTTPException(status_code=404, detail="Cliente da cobrança não encontrado.")

    recipient = validate_email_address(receivable.snapshot_email_billing or customer.email_billing or "")
    template = _get_message_template(db, company_id)

    context = build_receivable_context(customer, receivable)
    subject, body = render_template(template.subject, template.body, context)

    dedupe_key = _build_standard_dedupe_key(company_id, receivable.id, subject, body)

    existing = db.execute(
        select(OutboxMessage).where(
            OutboxMessage.company_id == company_id,
            OutboxMessage.dedupe_key == dedupe_key,
        )
    ).scalar_one_or_none()
    if existing:
        return existing

    outbox = OutboxMessage(
        company_id=company_id,
        receivable_id=receivable.id,
        customer_id=customer.id,
        created_by_user_id=user_id,
        message_kind="standard",
        recipient_email=recipient,
        subject=subject,
        body=body,
        dedupe_key=dedupe_key,
        status=MessageStatusEnum.PENDING,
    )
    db.add(outbox)
    db.commit()
    db.refresh(outbox)
    return outbox


def queue_manual_message(
    db: Session,
    company_id: int,
    customer: Customer,
    user_id: int | None,
    recipient_email: str,
    subject: str,
    body: str,
) -> OutboxMessage:
    recipient = validate_email_address(recipient_email)
    subject = validate_header_value(subject, "Assunto")
    body = (body or "").strip()
    if not body:
        raise HTTPException(status_code=400, detail="Corpo da mensagem é obrigatório.")

    dedupe_key = _build_manual_dedupe_key(company_id, customer.id, recipient, subject, body)

    existing = db.execute(
        select(OutboxMessage).where(
            OutboxMessage.company_id == company_id,
            OutboxMessage.dedupe_key == dedupe_key,
        )
    ).scalar_one_or_none()
    if existing:
        return existing

    preview_hash = _sha256_text(f"{recipient}|{subject}|{body}")

    manual = ManualMessage(
        company_id=company_id,
        customer_id=customer.id,
        created_by_user_id=user_id or 0,
        recipient_email=recipient,
        subject=subject,
        body=body,
        preview_hash=preview_hash,
    )
    db.add(manual)
    db.flush()

    outbox = OutboxMessage(
        company_id=company_id,
        customer_id=customer.id,
        created_by_user_id=user_id,
        message_kind="manual",
        recipient_email=recipient,
        subject=subject,
        body=body,
        dedupe_key=dedupe_key,
        status=MessageStatusEnum.PENDING,
    )
    db.add(outbox)
    db.commit()
    db.refresh(outbox)
    return outbox


class SmtpMailer:
    def __init__(self):
        self.host = settings.SMTP_HOST
        self.port = settings.SMTP_PORT
        self.username = settings.SMTP_USERNAME
        self.password = settings.SMTP_PASSWORD
        self.from_name = settings.SMTP_FROM_NAME
        self.from_email = settings.SMTP_FROM_EMAIL
        self.use_tls = settings.SMTP_USE_TLS

    def send(self, recipient_email: str, subject: str, body: str) -> None:
        recipient_email = validate_email_address(recipient_email)
        subject = validate_header_value(subject, "Assunto")
        body = (body or "").strip()
        if not body:
            raise HTTPException(status_code=400, detail="Corpo da mensagem vazio.")

        final_recipient = recipient_email
        final_body = body

        if settings.SAFE_MODE:
            final_recipient = validate_email_address(settings.TEST_EMAIL)
            final_body = (
                f"[SAFE_MODE ATIVO]\n"
                f"Destinatário original: {recipient_email}\n\n"
                f"{body}"
            )

        msg = EmailMessage()
        msg["From"] = f"{self.from_name} <{self.from_email}>"
        msg["To"] = final_recipient
        msg["Subject"] = subject
        msg.set_content(final_body)

        with smtplib.SMTP(self.host, self.port, timeout=30) as server:
            if self.use_tls:
                server.starttls()
            if self.username:
                server.login(self.username, self.password)
            server.send_message(msg)


def dispatch_pending_outbox(db: Session, company_id: int, limit: int = 20) -> dict:
    if limit < 1:
        limit = 1
    if limit > 200:
        limit = 200

    mailer = SmtpMailer()

    rows = db.execute(
        select(OutboxMessage)
        .where(
            OutboxMessage.company_id == company_id,
            OutboxMessage.status == MessageStatusEnum.PENDING,
        )
        .order_by(OutboxMessage.created_at.asc())
        .limit(limit)
    ).scalars().all()

    sent = 0
    errors = 0
    processed_ids: list[int] = []

    for row in rows:
        try:
            mailer.send(
                recipient_email=row.recipient_email,
                subject=row.subject,
                body=row.body,
            )
            row.status = MessageStatusEnum.SENT
            row.error_message = None
            row.sent_at = datetime.now(timezone.utc)

            if row.message_kind == "standard" and row.receivable_id:
                receivable = db.execute(
                    select(Receivable).where(Receivable.id == row.receivable_id)
                ).scalar_one_or_none()
                if receivable:
                    receivable.last_standard_message_at = datetime.now(timezone.utc)

            sent += 1
            processed_ids.append(row.id)
        except Exception as exc:
            row.status = MessageStatusEnum.ERROR
            row.error_message = str(exc)[:1000]
            errors += 1
            processed_ids.append(row.id)

    db.commit()

    return {
        "sent": sent,
        "errors": errors,
        "processed_ids": processed_ids,
    }