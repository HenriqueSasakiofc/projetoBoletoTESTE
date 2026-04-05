from __future__ import annotations

import hashlib
import io
import re
import unicodedata
from datetime import date, datetime
from decimal import Decimal, InvalidOperation

import pandas as pd
from fastapi import HTTPException, UploadFile
from sqlalchemy import and_, select
from sqlalchemy.orm import Session

from ..config import settings
from ..models import (
    AuditLog,
    BatchStatusEnum,
    Customer,
    CustomerLinkPending,
    PendingStatusEnum,
    Receivable,
    ReceivableHistory,
    ReceivableStatusEnum,
    StagingCustomer,
    StagingReceivable,
    UploadBatch,
    ValidationStatusEnum,
)

EMAIL_REGEX = re.compile(r"[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}", re.IGNORECASE)


def _strip_accents(value: str) -> str:
    value = unicodedata.normalize("NFKD", value)
    return "".join(ch for ch in value if not unicodedata.combining(ch))


def _slugify(value: str) -> str:
    value = _strip_accents(str(value or "").strip().lower())
    value = re.sub(r"[^a-z0-9]+", "_", value)
    return value.strip("_")


def normalize_text(value) -> str:
    if value is None:
        return ""
    value = _strip_accents(str(value).strip().lower())
    value = re.sub(r"\s+", " ", value)
    return value


def sanitize_cell(value):
    if value is None:
        return None

    if isinstance(value, float) and pd.isna(value):
        return None

    if isinstance(value, pd.Timestamp):
        return value.isoformat()

    if isinstance(value, datetime):
        return value.isoformat()

    if isinstance(value, date):
        return value.isoformat()

    if isinstance(value, Decimal):
        return str(value)

    if isinstance(value, bool):
        return value

    if isinstance(value, int):
        return value

    if isinstance(value, float):
        return float(value)

    text = str(value).strip()
    if not text:
        return None

    if text.startswith(("=", "+", "-", "@")):
        text = "'" + text

    return text


def sanitize_document(value) -> str | None:
    if not value:
        return None
    digits = "".join(ch for ch in str(value) if ch.isdigit())
    return digits or None


def sanitize_email(value) -> str | None:
    if not value:
        return None

    text = str(value).strip().lower()
    match = EMAIL_REGEX.search(text)
    if match:
        return match.group(0)

    return None


def sanitize_external_code(value) -> int | None:
    if value is None:
        return None

    if isinstance(value, float) and pd.isna(value):
        return None

    if isinstance(value, bool):
        return None

    if isinstance(value, int):
        return value

    if isinstance(value, float):
        try:
            return int(value)
        except Exception:
            return None

    text = str(value).strip()
    if not text:
        return None

    if text.isdigit():
        return int(text)

    return None


def parse_decimal(value) -> Decimal | None:
    if value is None or value == "":
        return None

    if isinstance(value, Decimal):
        return value.quantize(Decimal("0.01"))

    if isinstance(value, int):
        return Decimal(str(value)).quantize(Decimal("0.01"))

    if isinstance(value, float):
        if pd.isna(value):
            return None
        return Decimal(str(value)).quantize(Decimal("0.01"))

    text = str(value).strip().replace("R$", "").replace(" ", "")

    if "," in text and "." in text:
        text = text.replace(".", "").replace(",", ".")
    else:
        text = text.replace(",", ".")

    try:
        return Decimal(text).quantize(Decimal("0.01"))
    except (InvalidOperation, ValueError):
        return None


def parse_date_value(value) -> date | None:
    if value is None or value == "":
        return None

    if isinstance(value, date) and not isinstance(value, datetime):
        return value

    if isinstance(value, datetime):
        return value.date()

    text = str(value).strip()

    for fmt in ("%d/%m/%Y", "%Y-%m-%d", "%d-%m-%Y", "%d/%m/%y"):
        try:
            return datetime.strptime(text, fmt).date()
        except ValueError:
            pass

    parsed = pd.to_datetime(text, errors="coerce")
    if pd.isna(parsed):
        return None
    return parsed.date()


def hash_bytes(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def validate_upload_file(upload: UploadFile, max_size_mb: int) -> bytes:
    filename = (upload.filename or "").lower().strip()

    if not filename.endswith(".xlsx"):
        raise HTTPException(status_code=400, detail="Somente arquivos .xlsx são aceitos.")

    if filename.endswith(".xlsm"):
        raise HTTPException(status_code=400, detail="Arquivos com macro não são permitidos.")

    content = upload.file.read()
    if not content:
        raise HTTPException(status_code=400, detail=f"Arquivo {upload.filename} está vazio.")

    max_size = max_size_mb * 1024 * 1024
    if len(content) > max_size:
        raise HTTPException(status_code=400, detail="Arquivo excede o tamanho máximo permitido.")

    if b"vbaProject.bin" in content:
        raise HTTPException(status_code=400, detail="Arquivo com macro detectado e bloqueado.")

    return content


def _deduplicate_headers(headers: list[str]) -> list[str]:
    counts: dict[str, int] = {}
    result: list[str] = []

    for raw in headers:
        header = str(raw or "").strip()
        if not header:
            header = "coluna"

        count = counts.get(header, 0)
        if count == 0:
            result.append(header)
        else:
            result.append(f"{header}_{count + 1}")

        counts[header] = count + 1

    return result


def _looks_like_exported_header_row(df: pd.DataFrame) -> bool:
    if df.empty:
        return False

    column_names = [str(col).strip() for col in df.columns]
    first_row = df.iloc[0].tolist()

    generic_columns = sum(
        1
        for col in column_names
        if col.lower().startswith("unnamed:") or not col.strip()
    )

    text_cells = sum(
        1
        for value in first_row
        if isinstance(value, str) and value.strip()
    )

    return generic_columns >= max(2, len(column_names) // 2) and text_cells >= max(2, len(column_names) // 2)


def _promote_first_row_to_header(df: pd.DataFrame) -> pd.DataFrame:
    raw_headers = [sanitize_cell(value) or f"coluna_{idx + 1}" for idx, value in enumerate(df.iloc[0].tolist())]
    headers = _deduplicate_headers([str(value) for value in raw_headers])

    df = df.iloc[1:].copy()
    df.columns = headers
    df.reset_index(drop=True, inplace=True)
    return df


def _read_excel_as_records(content: bytes) -> tuple[list[dict], int]:
    df = pd.read_excel(io.BytesIO(content), dtype=object)
    df.columns = [str(col).strip() for col in df.columns]

    start_row = 2
    if _looks_like_exported_header_row(df):
        df = _promote_first_row_to_header(df)
        start_row = 3

    records = df.to_dict(orient="records")
    cleaned: list[dict] = []

    for row in records:
        cleaned_row: dict[str, object] = {}
        for key, value in row.items():
            cleaned_row[str(key).strip()] = sanitize_cell(value)
        cleaned.append(cleaned_row)

    return cleaned, start_row


def _pick_value(row: dict, aliases: list[str]):
    normalized = {_slugify(k): v for k, v in row.items()}
    for alias in aliases:
        alias_key = _slugify(alias)
        if alias_key in normalized:
            return normalized[alias_key]
    return None


def _row_contains_export_footer(row: dict) -> bool:
    joined = " ".join(str(v) for v in row.values() if v is not None).lower()
    return "gerado em" in joined


def _map_customer_row(row: dict, row_number: int) -> dict:
    full_name = _pick_value(
        row,
        [
            "nome",
            "nome_cliente",
            "cliente",
            "razao_social",
            "razao social",
            "full_name",
        ],
    )
    external_code = _pick_value(
        row,
        [
            "codigo",
            "código",
            "codigo_cliente",
            "cod_cliente",
            "id_cliente",
            "external_code",
        ],
    )
    document_number = _pick_value(
        row,
        [
            "cpf",
            "cnpj",
            "cpf_cnpj",
            "cnpj_cpf",
            "cpf/cnpj",
            "cnpj/cpf",
            "documento",
            "document_number",
        ],
    )
    email_billing = _pick_value(
        row,
        [
            "email_para_cobranca",
            "email para cobranca",
            "email para cobrança",
            "email_cobranca",
            "email de cobranca",
            "email de cobrança",
            "email_do_faturamento",
            "email do faturamento",
            "email_do_financeiro",
            "email do financeiro",
            "email",
            "e_mail",
            "email_billing",
        ],
    )
    email_financial = _pick_value(
        row,
        [
            "email_financeiro",
            "email do financeiro",
            "email_do_financeiro",
            "email do faturamento",
            "email_do_faturamento",
            "email_financial",
        ],
    )
    phone = _pick_value(row, ["telefone", "celular", "phone"])
    other_contacts = _pick_value(
        row,
        [
            "outros_contatos",
            "other_contacts",
            "contatos",
            "email_do_comprador",
            "email do comprador",
        ],
    )

    full_name = sanitize_cell(full_name)
    external_code = sanitize_external_code(external_code)
    document_number = sanitize_document(document_number)
    email_billing = sanitize_email(email_billing)
    email_financial = sanitize_email(email_financial)
    phone = sanitize_cell(phone)
    other_contacts = sanitize_cell(other_contacts)

    errors = []
    if not full_name:
        errors.append("Nome do cliente não encontrado.")

    return {
        "row_number": row_number,
        "external_code": external_code,
        "full_name": str(full_name or ""),
        "normalized_name": normalize_text(full_name),
        "document_number": document_number,
        "email_billing": email_billing,
        "email_financial": email_financial,
        "phone": phone,
        "other_contacts": other_contacts,
        "raw_payload": row,
        "validation_status": ValidationStatusEnum.INVALID if errors else ValidationStatusEnum.VALID,
        "validation_errors": errors or None,
    }


def _map_receivable_row(row: dict, row_number: int) -> dict:
    customer_external_code = _pick_value(
        row,
        [
            "codigo_cliente",
            "cod_cliente",
            "id_cliente",
            "codigo",
            "código",
            "customer_external_code",
        ],
    )
    customer_name = _pick_value(
        row,
        [
            "nome",
            "nome_cliente",
            "cliente",
            "sacado",
            "razao_social",
            "razao social",
            "customer_name",
        ],
    )
    customer_document_number = _pick_value(
        row,
        [
            "cpf",
            "cnpj",
            "cpf_cnpj",
            "cnpj_cpf",
            "cpf/cnpj",
            "cnpj/cpf",
            "documento_cliente",
            "customer_document_number",
        ],
    )
    receivable_number = _pick_value(
        row,
        [
            "numero_titulo",
            "numero titulo",
            "titulo",
            "título",
            "documento",
            "receivable_number",
        ],
    )
    nosso_numero = _pick_value(
        row,
        [
            "nosso_numero",
            "nosso numero",
            "nosso_num",
        ],
    )
    charge_type = _pick_value(
        row,
        [
            "tipo_cobranca",
            "tipo",
            "carteira",
            "charge_type",
        ],
    )
    issue_date = _pick_value(
        row,
        [
            "data_emissao",
            "emissao",
            "emissão",
            "issue_date",
        ],
    )
    due_date = _pick_value(
        row,
        [
            "vencimento",
            "data_vencimento",
            "due_date",
        ],
    )
    amount_total = _pick_value(
        row,
        [
            "valor",
            "valor_total",
            "amount_total",
        ],
    )
    balance_amount = _pick_value(
        row,
        [
            "saldo",
            "saldo_atual",
            "balance_amount",
        ],
    )
    balance_without_interest = _pick_value(
        row,
        [
            "saldo_sem_juros",
            "saldo sem juros",
            "saldo sem juros multa",
            "saldo sem juros/multa",
            "balance_without_interest",
        ],
    )
    status_raw = _pick_value(
        row,
        [
            "status",
            "situacao",
            "situação",
            "status_raw",
        ],
    )
    email_billing = _pick_value(
        row,
        [
            "email_cobranca",
            "email para cobranca",
            "email para cobrança",
            "email",
            "email_billing",
        ],
    )

    customer_name = sanitize_cell(customer_name)
    customer_external_code = sanitize_external_code(customer_external_code)
    customer_document_number = sanitize_document(customer_document_number)
    receivable_number = sanitize_cell(receivable_number)
    nosso_numero = sanitize_cell(nosso_numero)
    charge_type = sanitize_cell(charge_type)
    issue_date_parsed = parse_date_value(issue_date)
    due_date_parsed = parse_date_value(due_date)
    amount_total_parsed = parse_decimal(amount_total)
    balance_amount_parsed = parse_decimal(balance_amount)
    balance_without_interest_parsed = parse_decimal(balance_without_interest)
    status_raw = sanitize_cell(status_raw)
    email_billing = sanitize_email(email_billing)

    errors = []
    if not any([customer_name, customer_external_code, customer_document_number]):
        errors.append("Identificação do cliente da cobrança não encontrada.")
    if not due_date_parsed:
        errors.append("Data de vencimento inválida ou ausente.")
    if amount_total_parsed is None:
        errors.append("Valor total inválido ou ausente.")

    return {
        "row_number": row_number,
        "customer_external_code": customer_external_code,
        "customer_name": str(customer_name or ""),
        "normalized_customer_name": normalize_text(customer_name),
        "customer_document_number": customer_document_number,
        "receivable_number": receivable_number,
        "nosso_numero": nosso_numero,
        "charge_type": charge_type,
        "issue_date": issue_date_parsed,
        "due_date": due_date_parsed,
        "amount_total": amount_total_parsed,
        "balance_amount": balance_amount_parsed if balance_amount_parsed is not None else amount_total_parsed,
        "balance_without_interest": (
            balance_without_interest_parsed
            if balance_without_interest_parsed is not None
            else amount_total_parsed
        ),
        "status_raw": status_raw,
        "email_billing": email_billing,
        "raw_payload": row,
        "validation_status": ValidationStatusEnum.INVALID if errors else ValidationStatusEnum.VALID,
        "validation_errors": errors or None,
    }


def _infer_receivable_status(status_raw: str | None, due_date: date | None, balance_amount) -> ReceivableStatusEnum:
    raw = normalize_text(status_raw)

    if raw in {"pago", "baixado", "quitado", "liquidado"}:
        return ReceivableStatusEnum.PAGO

    if raw in {"cancelado", "cancelada"}:
        return ReceivableStatusEnum.CANCELADO

    if balance_amount is not None:
        try:
            if Decimal(balance_amount) <= 0:
                return ReceivableStatusEnum.PAGO
        except Exception:
            pass

    today = date.today()
    if not due_date:
        return ReceivableStatusEnum.EM_ABERTO

    if due_date < today:
        return ReceivableStatusEnum.INADIMPLENTE

    if (due_date - today).days <= 7:
        return ReceivableStatusEnum.VENCENDO

    return ReceivableStatusEnum.EM_ABERTO


def _find_secure_customer_in_db(
    db: Session,
    company_id: int,
    external_code: int | None,
    document_number: str | None,
) -> Customer | None:
    conditions = []

    if external_code is not None:
        conditions.append(and_(Customer.company_id == company_id, Customer.external_code == external_code))

    if document_number:
        conditions.append(and_(Customer.company_id == company_id, Customer.document_number == document_number))

    if not conditions:
        return None

    for condition in conditions:
        customer = db.execute(select(Customer).where(condition)).scalar_one_or_none()
        if customer:
            return customer

    return None


def _find_secure_customer_in_staging(
    staging_customers: list[StagingCustomer],
    external_code: int | None,
    document_number: str | None,
) -> StagingCustomer | None:
    for customer in staging_customers:
        if customer.validation_status != ValidationStatusEnum.VALID:
            continue
        if external_code is not None and customer.external_code is not None and customer.external_code == external_code:
            return customer
        if document_number and customer.document_number and customer.document_number == document_number:
            return customer
    return None


def _find_name_suggestion(
    db: Session,
    company_id: int,
    normalized_name: str,
) -> Customer | None:
    if not normalized_name:
        return None

    rows = db.execute(
        select(Customer).where(
            Customer.company_id == company_id,
            Customer.normalized_name == normalized_name,
        )
    ).scalars().all()

    if len(rows) == 1:
        return rows[0]
    return None


def _create_audit_log(
    db: Session,
    company_id: int | None,
    user_id: int | None,
    entity: str,
    entity_id: str | None,
    action: str,
    details: dict | None = None,
):
    db.add(
        AuditLog(
            company_id=company_id,
            user_id=user_id,
            entity=entity,
            entity_id=entity_id,
            action=action,
            details=details or {},
        )
    )


async def create_upload_batch_from_files(
    db: Session,
    company_id: int,
    uploaded_by_user_id: int,
    customers_upload: UploadFile,
    receivables_upload: UploadFile,
) -> UploadBatch:
    customers_content = validate_upload_file(customers_upload, settings.MAX_UPLOAD_SIZE_MB)
    receivables_content = validate_upload_file(receivables_upload, settings.MAX_UPLOAD_SIZE_MB)

    customers_records, customers_start_row = _read_excel_as_records(customers_content)
    receivables_records, receivables_start_row = _read_excel_as_records(receivables_content)

    batch = UploadBatch(
        company_id=company_id,
        uploaded_by_user_id=uploaded_by_user_id,
        customers_filename=customers_upload.filename or "clientes.xlsx",
        receivables_filename=receivables_upload.filename or "contas_receber.xlsx",
        customers_hash=hash_bytes(customers_content),
        receivables_hash=hash_bytes(receivables_content),
        status=BatchStatusEnum.PROCESSING,
    )
    db.add(batch)
    db.flush()

    staging_customers: list[StagingCustomer] = []
    staging_receivables: list[StagingReceivable] = []

    invalid_customers = 0
    invalid_receivables = 0

    for idx, row in enumerate(customers_records, start=customers_start_row):
        if _row_contains_export_footer(row):
            continue

        mapped = _map_customer_row(row, idx)

        if not any(
            [
                mapped.get("external_code"),
                mapped.get("full_name"),
                mapped.get("document_number"),
                mapped.get("email_billing"),
                mapped.get("email_financial"),
                mapped.get("phone"),
                mapped.get("other_contacts"),
            ]
        ):
            continue

        staging = StagingCustomer(
            company_id=company_id,
            upload_batch_id=batch.id,
            **mapped,
        )
        if staging.validation_status == ValidationStatusEnum.INVALID:
            invalid_customers += 1
        db.add(staging)
        staging_customers.append(staging)

    db.flush()

    for idx, row in enumerate(receivables_records, start=receivables_start_row):
        if _row_contains_export_footer(row):
            continue

        mapped = _map_receivable_row(row, idx)

        if not any(
            [
                mapped.get("customer_external_code"),
                mapped.get("customer_name"),
                mapped.get("customer_document_number"),
                mapped.get("receivable_number"),
                mapped.get("nosso_numero"),
                mapped.get("due_date"),
                mapped.get("amount_total"),
            ]
        ):
            continue

        staging = StagingReceivable(
            company_id=company_id,
            upload_batch_id=batch.id,
            **mapped,
        )
        if staging.validation_status == ValidationStatusEnum.INVALID:
            invalid_receivables += 1
        db.add(staging)
        staging_receivables.append(staging)

    db.flush()

    pending_count = 0

    for staging in staging_receivables:
        if staging.validation_status == ValidationStatusEnum.INVALID:
            continue

        secure_db_customer = _find_secure_customer_in_db(
            db=db,
            company_id=company_id,
            external_code=staging.customer_external_code,
            document_number=staging.customer_document_number,
        )

        secure_staging_customer = _find_secure_customer_in_staging(
            staging_customers=staging_customers,
            external_code=staging.customer_external_code,
            document_number=staging.customer_document_number,
        )

        if secure_db_customer or secure_staging_customer:
            continue

        suggested = _find_name_suggestion(
            db=db,
            company_id=company_id,
            normalized_name=staging.normalized_customer_name,
        )

        pending = CustomerLinkPending(
            company_id=company_id,
            upload_batch_id=batch.id,
            staging_receivable_id=staging.id,
            suggested_customer_id=suggested.id if suggested else None,
            status=PendingStatusEnum.OPEN,
            note="Cobrança sem vínculo seguro com cliente. Resolver antes do merge.",
        )
        db.add(pending)
        pending_count += 1

    batch.preview_customers_total = len(staging_customers)
    batch.preview_receivables_total = len(staging_receivables)
    batch.preview_invalid_customers = invalid_customers
    batch.preview_invalid_receivables = invalid_receivables
    batch.preview_pending_links = pending_count
    batch.status = BatchStatusEnum.PENDING_REVIEW if pending_count > 0 else BatchStatusEnum.PREVIEW_READY

    _create_audit_log(
        db=db,
        company_id=company_id,
        user_id=uploaded_by_user_id,
        entity="upload_batch",
        entity_id=str(batch.id),
        action="created_preview",
        details={
            "customers_total": batch.preview_customers_total,
            "receivables_total": batch.preview_receivables_total,
            "invalid_customers": invalid_customers,
            "invalid_receivables": invalid_receivables,
            "pending_links": pending_count,
        },
    )

    db.commit()
    db.refresh(batch)
    return batch


def resolve_pending_with_existing_customer(
    db: Session,
    pending: CustomerLinkPending,
    customer_id: int,
    current_user,
) -> CustomerLinkPending:
    customer = db.execute(
        select(Customer).where(
            Customer.id == customer_id,
            Customer.company_id == current_user.company_id,
        )
    ).scalar_one_or_none()

    if not customer:
        raise HTTPException(status_code=404, detail="Cliente não encontrado para vinculação.")

    pending.resolved_customer_id = customer.id
    pending.status = PendingStatusEnum.RESOLVED
    pending.resolved_by_user_id = current_user.id
    pending.resolved_at = datetime.utcnow()
    pending.note = "Pendência resolvida por vinculação manual a cliente existente."

    _create_audit_log(
        db=db,
        company_id=current_user.company_id,
        user_id=current_user.id,
        entity="customer_link_pending",
        entity_id=str(pending.id),
        action="linked_existing_customer",
        details={"customer_id": customer.id},
    )

    batch = db.execute(select(UploadBatch).where(UploadBatch.id == pending.upload_batch_id)).scalar_one()
    open_count = db.execute(
        select(CustomerLinkPending).where(
            CustomerLinkPending.upload_batch_id == batch.id,
            CustomerLinkPending.status == PendingStatusEnum.OPEN,
        )
    ).scalars().all()
    batch.preview_pending_links = len(open_count)
    if batch.preview_pending_links == 0:
        batch.status = BatchStatusEnum.PREVIEW_READY

    db.commit()
    db.refresh(pending)
    return pending


def resolve_pending_with_new_customer(
    db: Session,
    pending: CustomerLinkPending,
    payload,
    current_user,
) -> CustomerLinkPending:
    full_name = (payload.full_name or "").strip()
    if not full_name:
        raise HTTPException(status_code=400, detail="Nome do cliente é obrigatório.")

    document_number = sanitize_document(payload.document_number)
    existing = _find_secure_customer_in_db(
        db=db,
        company_id=current_user.company_id,
        external_code=None,
        document_number=document_number,
    )
    if existing:
        pending.resolved_customer_id = existing.id
        pending.status = PendingStatusEnum.RESOLVED
        pending.resolved_by_user_id = current_user.id
        pending.resolved_at = datetime.utcnow()
        pending.note = "Pendência resolvida utilizando cliente já existente com mesmo documento."
    else:
        customer = Customer(
            company_id=current_user.company_id,
            external_code=None,
            full_name=full_name,
            normalized_name=normalize_text(full_name),
            document_number=document_number,
            email_billing=sanitize_email(payload.email_billing),
            email_financial=sanitize_email(payload.email_financial),
            phone=sanitize_cell(payload.phone),
            other_contacts=sanitize_cell(payload.other_contacts),
            is_active=True,
        )
        db.add(customer)
        db.flush()

        pending.resolved_customer_id = customer.id
        pending.status = PendingStatusEnum.RESOLVED
        pending.resolved_by_user_id = current_user.id
        pending.resolved_at = datetime.utcnow()
        pending.note = "Pendência resolvida por criação manual de cliente."

    _create_audit_log(
        db=db,
        company_id=current_user.company_id,
        user_id=current_user.id,
        entity="customer_link_pending",
        entity_id=str(pending.id),
        action="created_or_linked_customer_from_pending",
        details={"resolved_customer_id": pending.resolved_customer_id},
    )

    batch = db.execute(select(UploadBatch).where(UploadBatch.id == pending.upload_batch_id)).scalar_one()
    open_count = db.execute(
        select(CustomerLinkPending).where(
            CustomerLinkPending.upload_batch_id == batch.id,
            CustomerLinkPending.status == PendingStatusEnum.OPEN,
        )
    ).scalars().all()
    batch.preview_pending_links = len(open_count)
    if batch.preview_pending_links == 0:
        batch.status = BatchStatusEnum.PREVIEW_READY

    db.commit()
    db.refresh(pending)
    return pending


def _upsert_customer_from_staging(
    db: Session,
    company_id: int,
    staging: StagingCustomer,
) -> Customer:
    customer = None

    if staging.external_code is not None:
        customer = db.execute(
            select(Customer).where(
                Customer.company_id == company_id,
                Customer.external_code == staging.external_code,
            )
        ).scalar_one_or_none()

    if not customer and staging.document_number:
        customer = db.execute(
            select(Customer).where(
                Customer.company_id == company_id,
                Customer.document_number == staging.document_number,
            )
        ).scalar_one_or_none()

    if not customer:
        customer = Customer(
            company_id=company_id,
            external_code=staging.external_code,
            full_name=staging.full_name,
            normalized_name=staging.normalized_name,
            document_number=staging.document_number,
            email_billing=staging.email_billing,
            email_financial=staging.email_financial,
            phone=staging.phone,
            other_contacts=staging.other_contacts,
            is_active=True,
        )
        db.add(customer)
        db.flush()
        return customer

    customer.full_name = staging.full_name
    customer.normalized_name = staging.normalized_name
    customer.document_number = staging.document_number
    customer.email_billing = staging.email_billing
    customer.email_financial = staging.email_financial
    customer.phone = staging.phone
    customer.other_contacts = staging.other_contacts
    customer.is_active = True

    if staging.external_code is not None:
        customer.external_code = staging.external_code

    db.flush()
    return customer


def _resolve_customer_for_receivable(
    db: Session,
    company_id: int,
    batch: UploadBatch,
    staging: StagingReceivable,
) -> Customer:
    pending = db.execute(
        select(CustomerLinkPending).where(
            CustomerLinkPending.upload_batch_id == batch.id,
            CustomerLinkPending.staging_receivable_id == staging.id,
        )
    ).scalar_one_or_none()

    if pending:
        if pending.status != PendingStatusEnum.RESOLVED or not pending.resolved_customer_id:
            raise HTTPException(
                status_code=400,
                detail=f"Pendência da cobrança na linha {staging.row_number} ainda não foi resolvida.",
            )
        customer = db.execute(
            select(Customer).where(
                Customer.id == pending.resolved_customer_id,
                Customer.company_id == company_id,
            )
        ).scalar_one_or_none()
        if not customer:
            raise HTTPException(status_code=400, detail="Cliente resolvido da pendência não encontrado.")
        return customer

    customer = _find_secure_customer_in_db(
        db=db,
        company_id=company_id,
        external_code=staging.customer_external_code,
        document_number=staging.customer_document_number,
    )
    if customer:
        return customer

    raise HTTPException(
        status_code=400,
        detail=f"Não foi possível vincular com segurança a cobrança da linha {staging.row_number}.",
    )


def _upsert_receivable_from_staging(
    db: Session,
    company_id: int,
    batch: UploadBatch,
    customer: Customer,
    staging: StagingReceivable,
    current_user,
) -> Receivable:
    receivable = None

    if staging.nosso_numero:
        receivable = db.execute(
            select(Receivable).where(
                Receivable.company_id == company_id,
                Receivable.nosso_numero == staging.nosso_numero,
            )
        ).scalar_one_or_none()

    if not receivable and staging.receivable_number and staging.due_date:
        receivable = db.execute(
            select(Receivable).where(
                Receivable.company_id == company_id,
                Receivable.receivable_number == staging.receivable_number,
                Receivable.due_date == staging.due_date,
            )
        ).scalar_one_or_none()

    new_status = _infer_receivable_status(
        status_raw=staging.status_raw,
        due_date=staging.due_date,
        balance_amount=staging.balance_amount,
    )

    if not receivable:
        receivable = Receivable(
            company_id=company_id,
            customer_id=customer.id,
            upload_batch_id=batch.id,
            receivable_number=staging.receivable_number,
            nosso_numero=staging.nosso_numero,
            charge_type=staging.charge_type,
            issue_date=staging.issue_date,
            due_date=staging.due_date,
            amount_total=staging.amount_total,
            balance_amount=staging.balance_amount,
            balance_without_interest=staging.balance_without_interest,
            status=new_status,
            snapshot_customer_name=customer.full_name,
            snapshot_customer_document=customer.document_number,
            snapshot_email_billing=staging.email_billing or customer.email_billing,
            is_active=True,
        )
        db.add(receivable)
        db.flush()

        db.add(
            ReceivableHistory(
                company_id=company_id,
                receivable_id=receivable.id,
                changed_by_user_id=current_user.id,
                old_status=None,
                new_status=new_status.value,
                note="Cobrança criada por merge de lote.",
            )
        )
        return receivable

    old_status = receivable.status.value if receivable.status else None

    receivable.customer_id = customer.id
    receivable.upload_batch_id = batch.id
    receivable.receivable_number = staging.receivable_number
    receivable.nosso_numero = staging.nosso_numero
    receivable.charge_type = staging.charge_type
    receivable.issue_date = staging.issue_date
    receivable.due_date = staging.due_date
    receivable.amount_total = staging.amount_total
    receivable.balance_amount = staging.balance_amount
    receivable.balance_without_interest = staging.balance_without_interest
    receivable.status = new_status
    receivable.snapshot_customer_name = customer.full_name
    receivable.snapshot_customer_document = customer.document_number
    receivable.snapshot_email_billing = staging.email_billing or customer.email_billing
    receivable.is_active = True

    db.flush()

    if old_status != new_status.value:
        db.add(
            ReceivableHistory(
                company_id=company_id,
                receivable_id=receivable.id,
                changed_by_user_id=current_user.id,
                old_status=old_status,
                new_status=new_status.value,
                note="Status atualizado por merge de lote.",
            )
        )

    return receivable


def approve_batch_merge(
    db: Session,
    batch: UploadBatch,
    current_user,
) -> UploadBatch:
    if batch.company_id != current_user.company_id:
        raise HTTPException(status_code=403, detail="Sem permissão para aprovar este lote.")

    open_pendings = db.execute(
        select(CustomerLinkPending).where(
            CustomerLinkPending.upload_batch_id == batch.id,
            CustomerLinkPending.status == PendingStatusEnum.OPEN,
        )
    ).scalars().all()

    if open_pendings:
        raise HTTPException(status_code=400, detail="Ainda existem pendências abertas neste lote.")

    merged_customers = 0
    merged_receivables = 0

    staging_customers = db.execute(
        select(StagingCustomer).where(
            StagingCustomer.upload_batch_id == batch.id,
            StagingCustomer.validation_status == ValidationStatusEnum.VALID,
        )
    ).scalars().all()

    for staging_customer in staging_customers:
        _upsert_customer_from_staging(
            db=db,
            company_id=batch.company_id,
            staging=staging_customer,
        )
        merged_customers += 1

    db.flush()

    staging_receivables = db.execute(
        select(StagingReceivable).where(
            StagingReceivable.upload_batch_id == batch.id,
            StagingReceivable.validation_status == ValidationStatusEnum.VALID,
        )
    ).scalars().all()

    for staging_receivable in staging_receivables:
        customer = _resolve_customer_for_receivable(
            db=db,
            company_id=batch.company_id,
            batch=batch,
            staging=staging_receivable,
        )
        _upsert_receivable_from_staging(
            db=db,
            company_id=batch.company_id,
            batch=batch,
            customer=customer,
            staging=staging_receivable,
            current_user=current_user,
        )
        merged_receivables += 1

    batch.approved_by_user_id = current_user.id
    batch.status = BatchStatusEnum.MERGED
    batch.merged_customers_count = merged_customers
    batch.merged_receivables_count = merged_receivables

    _create_audit_log(
        db=db,
        company_id=batch.company_id,
        user_id=current_user.id,
        entity="upload_batch",
        entity_id=str(batch.id),
        action="approved_merge",
        details={
            "merged_customers": merged_customers,
            "merged_receivables": merged_receivables,
        },
    )

    db.commit()
    db.refresh(batch)
    return batch