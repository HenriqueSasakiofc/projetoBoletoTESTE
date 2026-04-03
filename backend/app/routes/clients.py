from datetime import date

from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy import or_
from sqlalchemy.orm import Session

from .. import models, schemas
from ..dependencies import get_db, require_permission
from ..services.notifier import mask_document, mask_email, mask_phone

router = APIRouter(prefix="/api", tags=["clients"])


def summarize_customer_status(customer: models.Customer) -> tuple[str, int]:
    active_receivables = [r for r in customer.receivables if r.is_active]
    if not active_receivables:
        return "sem_cobranca", 0
    statuses = {r.status for r in active_receivables}
    if "inadimplente" in statuses:
        return "inadimplente", len(active_receivables)
    if "vencendo" in statuses:
        return "vencendo", len(active_receivables)
    if "em_aberto" in statuses:
        return "em_aberto", len(active_receivables)
    if all(status == "pago" for status in statuses):
        return "pago", len(active_receivables)
    if all(status == "cancelado" for status in statuses):
        return "cancelado", len(active_receivables)
    return sorted(statuses)[0], len(active_receivables)


@router.get("/clients", response_model=list[schemas.CustomerListItemOut])
def list_clients(
    q: str = Query(default="", max_length=200),
    status_filter: str | None = Query(default=None, alias="status"),
    page: int = Query(default=1, ge=1),
    page_size: int = Query(default=10, ge=1, le=100),
    db: Session = Depends(get_db),
    user=Depends(require_permission("manage_clients")),
):
    query = db.query(models.Customer).filter(models.Customer.company_id == user.company_id, models.Customer.is_active.is_(True))
    if q:
        like = f"%{q.strip()}%"
        query = query.filter(or_(models.Customer.full_name.ilike(like), models.Customer.email_billing.ilike(like)))
    customers = (
        query.order_by(models.Customer.full_name.asc())
        .offset((page - 1) * page_size)
        .limit(page_size)
        .all()
    )
    response = []
    for customer in customers:
        current_status, active_count = summarize_customer_status(customer)
        if status_filter and current_status != status_filter:
            continue
        response.append(
            {
                "id": customer.id,
                "full_name": customer.full_name,
                "email_billing_masked": mask_email(customer.email_billing),
                "phone_masked": mask_phone(customer.phone),
                "document_masked": mask_document(customer.document_number),
                "status": current_status,
                "active_receivables": active_count,
            }
        )
    return response


@router.get("/clients/{customer_id}", response_model=schemas.CustomerProfileOut)
def get_customer_profile(customer_id: int, db: Session = Depends(get_db), user=Depends(require_permission("manage_clients"))):
    customer = (
        db.query(models.Customer)
        .filter(models.Customer.company_id == user.company_id, models.Customer.id == customer_id, models.Customer.is_active.is_(True))
        .first()
    )
    if not customer:
        raise HTTPException(status_code=404, detail="Cliente não encontrado")

    current_status, _ = summarize_customer_status(customer)
    receivables = []
    receivable_ids = []
    for item in sorted(customer.receivables, key=lambda r: (r.due_date, r.id), reverse=True):
        if not item.is_active:
            continue
        receivable_ids.append(item.id)
        receivables.append(
            {
                "id": item.id,
                "customer_id": item.customer_id,
                "customer_name": item.customer_name_snapshot,
                "nosso_numero": item.nosso_numero,
                "due_date": item.due_date,
                "amount_total": item.amount_total,
                "balance_amount": item.balance_amount,
                "status": item.status,
            }
        )

    histories = (
        db.query(models.ReceivableHistory)
        .filter(models.ReceivableHistory.company_id == user.company_id, models.ReceivableHistory.receivable_id.in_(receivable_ids or [-1]))
        .order_by(models.ReceivableHistory.created_at.desc())
        .all()
    )
    message_history = (
        db.query(models.OutboxMessage)
        .filter(models.OutboxMessage.company_id == user.company_id, models.OutboxMessage.customer_id == customer.id)
        .order_by(models.OutboxMessage.created_at.desc())
        .all()
    )

    return {
        "id": customer.id,
        "full_name": customer.full_name,
        "email_billing_masked": mask_email(customer.email_billing),
        "email_financial_masked": mask_email(customer.email_financial),
        "phone_masked": mask_phone(customer.phone),
        "document_masked": mask_document(customer.document_number),
        "other_contacts": customer.other_contacts,
        "status": current_status,
        "receivables": receivables,
        "receivable_history": [
            {
                "id": h.id,
                "receivable_id": h.receivable_id,
                "event_type": h.event_type,
                "old_status": h.old_status,
                "new_status": h.new_status,
                "note": h.note,
                "created_at": h.created_at,
            }
            for h in histories
        ],
        "message_history": [
            {
                "id": m.id,
                "recipient_email_masked": mask_email(m.recipient_email),
                "subject": m.subject,
                "status": m.status,
                "message_kind": m.message_kind,
                "created_at": m.created_at,
                "sent_at": m.sent_at,
            }
            for m in message_history
        ],
    }


@router.get("/receivables", response_model=list[schemas.ReceivableListItemOut])
def list_receivables(
    status_filter: str | None = Query(default=None, alias="status"),
    db: Session = Depends(get_db),
    user=Depends(require_permission("manage_clients")),
):
    query = db.query(models.Receivable).filter(models.Receivable.company_id == user.company_id, models.Receivable.is_active.is_(True))
    if status_filter:
        query = query.filter(models.Receivable.status == status_filter)
    items = query.order_by(models.Receivable.due_date.asc()).limit(200).all()
    return [
        {
            "id": item.id,
            "customer_id": item.customer_id,
            "customer_name": item.customer_name_snapshot,
            "nosso_numero": item.nosso_numero,
            "due_date": item.due_date,
            "amount_total": item.amount_total,
            "balance_amount": item.balance_amount,
            "status": item.status,
        }
        for item in items
    ]


@router.post("/receivables/{receivable_id}/mark-paid")
def mark_paid(
    receivable_id: int,
    payload: schemas.MarkPaidIn,
    db: Session = Depends(get_db),
    user=Depends(require_permission("manage_clients")),
):
    receivable = (
        db.query(models.Receivable)
        .filter(models.Receivable.company_id == user.company_id, models.Receivable.id == receivable_id)
        .first()
    )
    if not receivable:
        raise HTTPException(status_code=404, detail="Título não encontrado")
    old_status = receivable.status
    receivable.status = "pago"
    receivable.balance_amount = 0
    receivable.balance_without_interest = 0
    db.add(
        models.ReceivableHistory(
            company_id=user.company_id,
            receivable_id=receivable.id,
            event_type="status_change",
            old_status=old_status,
            new_status="pago",
            note=f"Baixado manualmente em {(payload.paid_at or date.today()).isoformat()}",
            created_by_user_id=user.id,
        )
    )
    db.add(
        models.AuditLog(
            company_id=user.company_id,
            user_id=user.id,
            entity_type="receivable",
            entity_id=str(receivable.id),
            action="mark_paid",
            details=f'{{"paid_at": "{(payload.paid_at or date.today()).isoformat()}"}}',
        )
    )
    db.commit()
    return {"ok": True, "receivable_id": receivable.id, "status": receivable.status}