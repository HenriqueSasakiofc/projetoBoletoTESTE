from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session

from .. import models, schemas
from ..dependencies import get_db, require_permission
from ..services.notifier import (
    ALLOWED_PLACEHOLDERS,
    dispatch_outbox,
    get_active_template,
    mask_email,
    preview_message,
    queue_manual_message,
    queue_standard_message,
    upsert_active_template,
)

router = APIRouter(prefix="/api", tags=["messages"])


@router.get("/message-template", response_model=schemas.TemplateOut)
def get_message_template(db: Session = Depends(get_db), user=Depends(require_permission("prepare_send"))):
    template = get_active_template(db, user.company_id)
    return {
        "id": template.id,
        "subject_template": template.subject_template,
        "body_template": template.body_template,
        "placeholders": sorted(ALLOWED_PLACEHOLDERS),
    }


@router.put("/message-template", response_model=schemas.TemplateOut)
def update_message_template(payload: schemas.TemplateIn, db: Session = Depends(get_db), user=Depends(require_permission("prepare_send"))):
    template = upsert_active_template(db, user.company_id, payload.subject_template, payload.body_template)
    db.add(
        models.AuditLog(
            company_id=user.company_id,
            user_id=user.id,
            entity_type="message_template",
            entity_id=str(template.id),
            action="updated",
            details='{"source": "api"}',
        )
    )
    db.commit()
    return {
        "id": template.id,
        "subject_template": template.subject_template,
        "body_template": template.body_template,
        "placeholders": sorted(ALLOWED_PLACEHOLDERS),
    }


@router.post("/message-template/preview", response_model=schemas.PreviewOut)
def preview_template(payload: schemas.TemplatePreviewIn, db: Session = Depends(get_db), user=Depends(require_permission("prepare_send"))):
    company = db.query(models.Company).filter(models.Company.id == user.company_id).first()
    receivable = (
        db.query(models.Receivable)
        .filter(models.Receivable.company_id == user.company_id, models.Receivable.id == payload.receivable_id)
        .first()
    )
    if not receivable:
        raise HTTPException(status_code=404, detail="Título não encontrado")
    subject, body = preview_message(db, company, receivable, payload.subject_template, payload.body_template)
    return {"subject": subject, "body": body}


@router.post("/receivables/{receivable_id}/queue-standard-message")
def queue_receivable_message(receivable_id: int, db: Session = Depends(get_db), user=Depends(require_permission("prepare_send"))):
    company = db.query(models.Company).filter(models.Company.id == user.company_id).first()
    receivable = (
        db.query(models.Receivable)
        .filter(models.Receivable.company_id == user.company_id, models.Receivable.id == receivable_id, models.Receivable.is_active.is_(True))
        .first()
    )
    if not receivable:
        raise HTTPException(status_code=404, detail="Título não encontrado")
    template = get_active_template(db, user.company_id)
    item = queue_standard_message(db, company, receivable, template)
    db.commit()
    return {"ok": True, "outbox_id": item.id, "status": item.status}


@router.post("/customers/{customer_id}/send-manual-message")
def send_manual_message(
    customer_id: int,
    payload: schemas.ManualMessageIn,
    db: Session = Depends(get_db),
    user=Depends(require_permission("prepare_send")),
):
    company = db.query(models.Company).filter(models.Company.id == user.company_id).first()
    customer = (
        db.query(models.Customer)
        .filter(models.Customer.company_id == user.company_id, models.Customer.id == customer_id, models.Customer.is_active.is_(True))
        .first()
    )
    if not customer:
        raise HTTPException(status_code=404, detail="Cliente não encontrado")
    receivable = None
    if payload.receivable_id is not None:
        receivable = (
            db.query(models.Receivable)
            .filter(models.Receivable.company_id == user.company_id, models.Receivable.id == payload.receivable_id)
            .first()
        )
        if not receivable:
            raise HTTPException(status_code=404, detail="Título não encontrado")
    item = queue_manual_message(
        db,
        company=company,
        customer=customer,
        receivable=receivable,
        user=user,
        subject=payload.subject,
        body=payload.body,
    )
    db.commit()
    return {"ok": True, "outbox_id": item.id, "status": item.status}


@router.get("/outbox", response_model=list[schemas.OutboxItemOut])
def list_outbox(db: Session = Depends(get_db), user=Depends(require_permission("prepare_send"))):
    items = (
        db.query(models.OutboxMessage)
        .filter(models.OutboxMessage.company_id == user.company_id)
        .order_by(models.OutboxMessage.created_at.desc())
        .limit(200)
        .all()
    )
    return [
        {
            "id": item.id,
            "recipient_email_masked": mask_email(item.recipient_email),
            "subject": item.subject,
            "status": item.status,
            "message_kind": item.message_kind,
            "created_at": item.created_at,
            "sent_at": item.sent_at,
        }
        for item in items
    ]


@router.post("/outbox/dispatch", response_model=schemas.DispatchOut)
def dispatch(db: Session = Depends(get_db), user=Depends(require_permission("dispatch"))):
    sent, failed = dispatch_outbox(db, user.company_id)
    db.add(
        models.AuditLog(
            company_id=user.company_id,
            user_id=user.id,
            entity_type="outbox",
            entity_id="company",
            action="dispatch",
            details=f'{{"sent": {sent}, "failed": {failed}}}',
        )
    )
    db.commit()
    return {"sent": sent, "failed": failed}