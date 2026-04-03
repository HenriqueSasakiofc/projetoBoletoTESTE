from fastapi import APIRouter, Depends, File, HTTPException, UploadFile
from sqlalchemy.orm import Session

from .. import models, schemas
from ..dependencies import get_db, require_permission
from ..services.importer import (
    cleanup_old_staging,
    create_customer_from_pending,
    create_upload_batch,
    get_batch_preview,
    merge_batch,
    resolve_pending_with_customer,
)
from ..services.notifier import mask_document

router = APIRouter(prefix="/api", tags=["imports"])


@router.post("/upload-batches", response_model=schemas.UploadBatchPreviewOut)
async def upload_batch(
    clientes: UploadFile = File(...),
    contas: UploadFile = File(...),
    db: Session = Depends(get_db),
    user=Depends(require_permission("upload")),
):
    clients_bytes = await clientes.read()
    receivables_bytes = await contas.read()
    cleanup_old_staging(db, user.company_id)
    batch = create_upload_batch(
        db,
        user=user,
        clients_filename=clientes.filename or "clientes.xlsx",
        clients_bytes=clients_bytes,
        receivables_filename=contas.filename or "contas.xlsx",
        receivables_bytes=receivables_bytes,
    )
    return get_batch_preview(db, batch)


@router.get("/upload-batches/{batch_id}", response_model=schemas.BatchSummaryOut)
def get_batch(batch_id: int, db: Session = Depends(get_db), user=Depends(require_permission("upload"))):
    batch = (
        db.query(models.UploadBatch)
        .filter(models.UploadBatch.company_id == user.company_id, models.UploadBatch.id == batch_id)
        .first()
    )
    if not batch:
        raise HTTPException(status_code=404, detail="Lote não encontrado")
    return batch


@router.post("/upload-batches/{batch_id}/approve-merge")
def approve_merge(
    batch_id: int,
    payload: schemas.ApproveBatchIn,
    db: Session = Depends(get_db),
    user=Depends(require_permission("approve_import")),
):
    if not payload.confirm:
        raise HTTPException(status_code=400, detail="Confirmação obrigatória")
    batch = (
        db.query(models.UploadBatch)
        .filter(models.UploadBatch.company_id == user.company_id, models.UploadBatch.id == batch_id)
        .first()
    )
    if not batch:
        raise HTTPException(status_code=404, detail="Lote não encontrado")
    merge_batch(db, batch=batch, approved_by=user)
    db.commit()
    return {"ok": True, "batch_id": batch.id, "status": batch.status}


@router.get("/upload-batches/{batch_id}/pendings", response_model=list[schemas.PendingLinkOut])
def get_pendings(batch_id: int, db: Session = Depends(get_db), user=Depends(require_permission("manage_clients"))):
    batch = (
        db.query(models.UploadBatch)
        .filter(models.UploadBatch.company_id == user.company_id, models.UploadBatch.id == batch_id)
        .first()
    )
    if not batch:
        raise HTTPException(status_code=404, detail="Lote não encontrado")
    pendings = (
        db.query(models.CustomerLinkPending, models.StagingReceivable)
        .join(models.StagingReceivable, models.StagingReceivable.id == models.CustomerLinkPending.staging_receivable_id)
        .filter(models.CustomerLinkPending.company_id == user.company_id, models.CustomerLinkPending.upload_batch_id == batch_id)
        .order_by(models.CustomerLinkPending.created_at.asc())
        .all()
    )
    return [
        {
            "id": pending.id,
            "staging_receivable_id": staging.id,
            "customer_name": staging.customer_name,
            "customer_document_masked": mask_document(staging.customer_document_number),
            "receivable_number": staging.receivable_number,
            "nosso_numero": staging.nosso_numero,
            "status": pending.status,
            "suggested_customer_id": pending.suggested_customer_id,
        }
        for pending, staging in pendings
    ]


@router.post("/pendings/{pending_id}/link-existing")
def link_existing_customer(
    pending_id: int,
    payload: schemas.LinkPendingIn,
    db: Session = Depends(get_db),
    user=Depends(require_permission("manage_clients")),
):
    pending = (
        db.query(models.CustomerLinkPending)
        .filter(models.CustomerLinkPending.company_id == user.company_id, models.CustomerLinkPending.id == pending_id)
        .first()
    )
    if not pending:
        raise HTTPException(status_code=404, detail="Pendência não encontrada")
    customer = (
        db.query(models.Customer)
        .filter(models.Customer.company_id == user.company_id, models.Customer.id == payload.customer_id, models.Customer.is_active.is_(True))
        .first()
    )
    if not customer:
        raise HTTPException(status_code=404, detail="Cliente não encontrado")
    resolve_pending_with_customer(db, pending=pending, customer=customer, user=user, note="Vinculado manualmente")
    db.commit()
    return {"ok": True, "pending_id": pending.id, "status": pending.status}


@router.post("/pendings/{pending_id}/create-customer")
def create_customer_pending(
    pending_id: int,
    payload: schemas.CreateCustomerFromPendingIn,
    db: Session = Depends(get_db),
    user=Depends(require_permission("manage_clients")),
):
    pending = (
        db.query(models.CustomerLinkPending)
        .filter(models.CustomerLinkPending.company_id == user.company_id, models.CustomerLinkPending.id == pending_id)
        .first()
    )
    if not pending:
        raise HTTPException(status_code=404, detail="Pendência não encontrada")
    customer = create_customer_from_pending(db, pending=pending, payload=payload.model_dump(), user=user)
    db.commit()
    return {"ok": True, "pending_id": pending.id, "customer_id": customer.id, "status": pending.status}