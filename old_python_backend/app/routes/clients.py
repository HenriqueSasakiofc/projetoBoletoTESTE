from datetime import datetime, timezone

from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy import func, or_, select
from sqlalchemy.orm import Session, selectinload

from ..dependencies import get_current_user, get_db, require_permission
from ..models import (
    Customer,
    MessageStatusEnum,
    OutboxMessage,
    Receivable,
    ReceivableHistory,
    ReceivableStatusEnum,
    RoleEnum,
)
from ..schemas import (
    ClientListResponse,
    CustomerDetail,
    CustomerSummary,
    OutboxItem,
    ReceivableHistoryItem,
    ReceivableListResponse,
    ReceivableSummary,
)

router = APIRouter(prefix="/api", tags=["clients"])


def mask_document(value: str | None) -> str | None:
    if not value:
        return None
    digits = "".join(ch for ch in value if ch.isdigit())
    if len(digits) <= 4:
        return "*" * len(digits)
    return f"{'*' * (len(digits) - 4)}{digits[-4:]}"


def mask_email(value: str | None) -> str | None:
    if not value or "@" not in value:
        return value
    name, domain = value.split("@", 1)
    if len(name) <= 2:
        masked = "*" * len(name)
    else:
        masked = name[0] + "*" * (len(name) - 2) + name[-1]
    return f"{masked}@{domain}"


def mask_phone(value: str | None) -> str | None:
    if not value:
        return None
    digits = "".join(ch for ch in value if ch.isdigit())
    if len(digits) <= 4:
        return "*" * len(digits)
    return f"{'*' * (len(digits) - 4)}{digits[-4:]}"


def _customer_summary(customer: Customer) -> CustomerSummary:
    receivables_total = len(customer.receivables)
    open_receivables_total = len(
        [r for r in customer.receivables if r.status in {ReceivableStatusEnum.EM_ABERTO, ReceivableStatusEnum.VENCENDO}]
    )
    overdue_receivables_total = len(
        [r for r in customer.receivables if r.status == ReceivableStatusEnum.INADIMPLENTE]
    )

    return CustomerSummary(
        id=customer.id,
        external_code=customer.external_code,
        full_name=customer.full_name,
        email_billing=customer.email_billing,
        email_billing_masked=mask_email(customer.email_billing),
        document_number_masked=mask_document(customer.document_number),
        phone_masked=mask_phone(customer.phone),
        receivables_total=receivables_total,
        open_receivables_total=open_receivables_total,
        overdue_receivables_total=overdue_receivables_total,
    )


@router.get(
    "/clients",
    response_model=ClientListResponse,
    dependencies=[Depends(require_permission(RoleEnum.CLIENT_OPERATOR, RoleEnum.AUDITOR, RoleEnum.IMPORTER, RoleEnum.APPROVER, RoleEnum.SENDER))],
)
def list_clients(
    search: str | None = None,
    status_filter: str | None = None,
    page: int = 1,
    page_size: int = 20,
    db: Session = Depends(get_db),
    current_user=Depends(get_current_user),
):
    if page < 1:
        page = 1
    if page_size < 1 or page_size > 100:
        page_size = 20

    stmt = (
        select(Customer)
        .where(Customer.company_id == current_user.company_id)
        .options(selectinload(Customer.receivables))
        .order_by(Customer.full_name.asc())
    )

    if search:
        like = f"%{search.strip()}%"
        stmt = stmt.where(
            or_(
                Customer.full_name.ilike(like),
                Customer.external_code.ilike(like),
                Customer.document_number.ilike(like),
                Customer.email_billing.ilike(like),
            )
        )

    customers = list(db.execute(stmt).scalars().unique().all())

    if status_filter:
        wanted = status_filter.strip().lower()
        filtered: list[Customer] = []
        for customer in customers:
            statuses = {r.status.value for r in customer.receivables}
            if wanted in statuses:
                filtered.append(customer)
        customers = filtered

    total = len(customers)
    start = (page - 1) * page_size
    end = start + page_size
    items = [_customer_summary(customer) for customer in customers[start:end]]

    return ClientListResponse(total=total, page=page, page_size=page_size, items=items)


@router.get(
    "/clients/{customer_id}",
    response_model=CustomerDetail,
    dependencies=[Depends(require_permission(RoleEnum.CLIENT_OPERATOR, RoleEnum.AUDITOR, RoleEnum.IMPORTER, RoleEnum.APPROVER, RoleEnum.SENDER))],
)
def get_client_detail(
    customer_id: int,
    db: Session = Depends(get_db),
    current_user=Depends(get_current_user),
):
    customer = db.execute(
        select(Customer)
        .where(
            Customer.id == customer_id,
            Customer.company_id == current_user.company_id,
        )
        .options(selectinload(Customer.receivables))
    ).scalar_one_or_none()

    if not customer:
        raise HTTPException(status_code=404, detail="Cliente não encontrado.")

    history_rows = db.execute(
        select(ReceivableHistory)
        .join(Receivable, Receivable.id == ReceivableHistory.receivable_id)
        .where(
            Receivable.company_id == current_user.company_id,
            Receivable.customer_id == customer.id,
        )
        .order_by(ReceivableHistory.created_at.desc())
    ).scalars().all()

    message_rows = db.execute(
        select(OutboxMessage)
        .where(
            OutboxMessage.company_id == current_user.company_id,
            OutboxMessage.customer_id == customer.id,
        )
        .order_by(OutboxMessage.created_at.desc())
    ).scalars().all()

    receivables = [
        ReceivableSummary(
            id=r.id,
            receivable_number=r.receivable_number,
            nosso_numero=r.nosso_numero,
            due_date=r.due_date,
            amount_total=r.amount_total,
            balance_amount=r.balance_amount,
            status=r.status.value,
            snapshot_email_billing=r.snapshot_email_billing,
            last_standard_message_at=r.last_standard_message_at,
        )
        for r in sorted(customer.receivables, key=lambda x: (x.due_date or datetime.now().date()), reverse=True)
    ]

    history = [
        ReceivableHistoryItem(
            id=h.id,
            old_status=h.old_status,
            new_status=h.new_status,
            note=h.note,
            created_at=h.created_at,
        )
        for h in history_rows
    ]

    messages = [
        OutboxItem(
            id=m.id,
            message_kind=m.message_kind,
            recipient_email=m.recipient_email,
            subject=m.subject,
            status=m.status.value,
            error_message=m.error_message,
            sent_at=m.sent_at,
            created_at=m.created_at,
        )
        for m in message_rows
    ]

    return CustomerDetail(
        id=customer.id,
        external_code=customer.external_code,
        full_name=customer.full_name,
        email_billing=customer.email_billing,
        email_billing_masked=mask_email(customer.email_billing),
        email_financial=customer.email_financial,
        email_financial_masked=mask_email(customer.email_financial),
        phone=customer.phone,
        phone_masked=mask_phone(customer.phone),
        document_number_masked=mask_document(customer.document_number),
        other_contacts=customer.other_contacts,
        receivables=receivables,
        history=history,
        messages=messages,
    )


@router.get(
    "/receivables",
    response_model=ReceivableListResponse,
    dependencies=[Depends(require_permission(RoleEnum.CLIENT_OPERATOR, RoleEnum.AUDITOR, RoleEnum.IMPORTER, RoleEnum.APPROVER, RoleEnum.SENDER))],
)
def list_receivables(
    search: str | None = None,
    status_filter: str | None = None,
    page: int = 1,
    page_size: int = 20,
    db: Session = Depends(get_db),
    current_user=Depends(get_current_user),
):
    if page < 1:
        page = 1
    if page_size < 1 or page_size > 100:
        page_size = 20

    stmt = select(Receivable).where(Receivable.company_id == current_user.company_id).order_by(Receivable.due_date.desc())

    if status_filter:
        stmt = stmt.where(Receivable.status == ReceivableStatusEnum(status_filter))

    if search:
        like = f"%{search.strip()}%"
        stmt = stmt.where(
            or_(
                Receivable.receivable_number.ilike(like),
                Receivable.nosso_numero.ilike(like),
                Receivable.snapshot_customer_name.ilike(like),
                Receivable.snapshot_customer_document.ilike(like),
            )
        )

    rows = list(db.execute(stmt).scalars().all())
    total = len(rows)
    start = (page - 1) * page_size
    end = start + page_size

    items = [
        ReceivableSummary(
            id=r.id,
            receivable_number=r.receivable_number,
            nosso_numero=r.nosso_numero,
            due_date=r.due_date,
            amount_total=r.amount_total,
            balance_amount=r.balance_amount,
            status=r.status.value,
            snapshot_email_billing=r.snapshot_email_billing,
            last_standard_message_at=r.last_standard_message_at,
        )
        for r in rows[start:end]
    ]

    return ReceivableListResponse(total=total, page=page, page_size=page_size, items=items)


@router.post(
    "/receivables/{receivable_id}/mark-paid",
    response_model=ReceivableSummary,
    dependencies=[Depends(require_permission(RoleEnum.CLIENT_OPERATOR, RoleEnum.APPROVER))],
)
def mark_receivable_paid(
    receivable_id: int,
    db: Session = Depends(get_db),
    current_user=Depends(get_current_user),
):
    receivable = db.execute(
        select(Receivable).where(
            Receivable.id == receivable_id,
            Receivable.company_id == current_user.company_id,
        )
    ).scalar_one_or_none()

    if not receivable:
        raise HTTPException(status_code=404, detail="Cobrança não encontrada.")

    old_status = receivable.status.value
    receivable.status = ReceivableStatusEnum.PAGO
    receivable.balance_amount = 0
    receivable.updated_at = datetime.now(timezone.utc)

    db.add(
        ReceivableHistory(
            company_id=current_user.company_id,
            receivable_id=receivable.id,
            changed_by_user_id=current_user.id,
            old_status=old_status,
            new_status=ReceivableStatusEnum.PAGO.value,
            note="Cobrança marcada como paga manualmente.",
        )
    )
    db.commit()
    db.refresh(receivable)

    return ReceivableSummary(
        id=receivable.id,
        receivable_number=receivable.receivable_number,
        nosso_numero=receivable.nosso_numero,
        due_date=receivable.due_date,
        amount_total=receivable.amount_total,
        balance_amount=receivable.balance_amount,
        status=receivable.status.value,
        snapshot_email_billing=receivable.snapshot_email_billing,
        last_standard_message_at=receivable.last_standard_message_at,
    )