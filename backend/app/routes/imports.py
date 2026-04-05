from fastapi import APIRouter, Depends, File, HTTPException, UploadFile, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from ..dependencies import get_current_user, get_db, require_permission
from ..models import CustomerLinkPending, RoleEnum, UploadBatch
from ..schemas import (
    CreateCustomerFromPendingPayload,
    LinkExistingPayload,
    PendingItem,
    UploadBatchSummary,
)
from ..services.importer import (
    approve_batch_merge,
    create_upload_batch_from_files,
    resolve_pending_with_existing_customer,
    resolve_pending_with_new_customer,
)

router = APIRouter(prefix="/api", tags=["imports"])


def _get_batch_or_404(db: Session, batch_id: int, company_id: int) -> UploadBatch:
    batch = db.execute(
        select(UploadBatch).where(
            UploadBatch.id == batch_id,
            UploadBatch.company_id == company_id,
        )
    ).scalar_one_or_none()
    if not batch:
        raise HTTPException(status_code=404, detail="Lote não encontrado.")
    return batch


def _get_pending_or_404(db: Session, pending_id: int, company_id: int) -> CustomerLinkPending:
    pending = db.execute(
        select(CustomerLinkPending).where(
            CustomerLinkPending.id == pending_id,
            CustomerLinkPending.company_id == company_id,
        )
    ).scalar_one_or_none()
    if not pending:
        raise HTTPException(status_code=404, detail="Pendência não encontrada.")
    return pending


@router.post(
    "/upload-batches",
    response_model=UploadBatchSummary,
    dependencies=[Depends(require_permission(RoleEnum.IMPORTER, RoleEnum.APPROVER))],
)
async def upload_batches(
    customers_file: UploadFile = File(...),
    receivables_file: UploadFile = File(...),
    db: Session = Depends(get_db),
    current_user=Depends(get_current_user),
):
    batch = await create_upload_batch_from_files(
        db=db,
        company_id=current_user.company_id,
        uploaded_by_user_id=current_user.id,
        customers_upload=customers_file,
        receivables_upload=receivables_file,
    )
    return batch


@router.get(
    "/upload-batches/{batch_id}",
    response_model=UploadBatchSummary,
    dependencies=[Depends(require_permission(RoleEnum.IMPORTER, RoleEnum.APPROVER, RoleEnum.AUDITOR))],
)
def get_batch(
    batch_id: int,
    db: Session = Depends(get_db),
    current_user=Depends(get_current_user),
):
    return _get_batch_or_404(db, batch_id, current_user.company_id)


@router.post(
    "/upload-batches/{batch_id}/approve-merge",
    response_model=UploadBatchSummary,
    dependencies=[Depends(require_permission(RoleEnum.APPROVER))],
)
def approve_merge(
    batch_id: int,
    db: Session = Depends(get_db),
    current_user=Depends(get_current_user),
):
    batch = _get_batch_or_404(db, batch_id, current_user.company_id)
    return approve_batch_merge(db=db, batch=batch, current_user=current_user)


@router.get(
    "/upload-batches/{batch_id}/pendings",
    response_model=list[PendingItem],
    dependencies=[Depends(require_permission(RoleEnum.IMPORTER, RoleEnum.APPROVER, RoleEnum.CLIENT_OPERATOR))],
)
def list_batch_pendings(
    batch_id: int,
    db: Session = Depends(get_db),
    current_user=Depends(get_current_user),
):
    batch = _get_batch_or_404(db, batch_id, current_user.company_id)
    items: list[PendingItem] = []

    for pending in batch.pendings:
        staging = pending.staging_receivable
        items.append(
            PendingItem(
                id=pending.id,
                status=pending.status.value,
                note=pending.note,
                suggested_customer_id=pending.suggested_customer_id,
                resolved_customer_id=pending.resolved_customer_id,
                staging_receivable_id=staging.id,
                customer_name=staging.customer_name,
                customer_document_number=staging.customer_document_number,
                receivable_number=staging.receivable_number,
                nosso_numero=staging.nosso_numero,
                due_date=staging.due_date,
                amount_total=staging.amount_total,
            )
        )

    return items


@router.post(
    "/pendings/{pending_id}/link-existing",
    response_model=PendingItem,
    dependencies=[Depends(require_permission(RoleEnum.CLIENT_OPERATOR, RoleEnum.APPROVER))],
)
def link_existing_customer(
    pending_id: int,
    payload: LinkExistingPayload,
    db: Session = Depends(get_db),
    current_user=Depends(get_current_user),
):
    pending = _get_pending_or_404(db, pending_id, current_user.company_id)
    pending = resolve_pending_with_existing_customer(
        db=db,
        pending=pending,
        customer_id=payload.customer_id,
        current_user=current_user,
    )
    staging = pending.staging_receivable
    return PendingItem(
        id=pending.id,
        status=pending.status.value,
        note=pending.note,
        suggested_customer_id=pending.suggested_customer_id,
        resolved_customer_id=pending.resolved_customer_id,
        staging_receivable_id=staging.id,
        customer_name=staging.customer_name,
        customer_document_number=staging.customer_document_number,
        receivable_number=staging.receivable_number,
        nosso_numero=staging.nosso_numero,
        due_date=staging.due_date,
        amount_total=staging.amount_total,
    )


@router.post(
    "/pendings/{pending_id}/create-customer",
    response_model=PendingItem,
    dependencies=[Depends(require_permission(RoleEnum.CLIENT_OPERATOR, RoleEnum.APPROVER))],
)
def create_customer_from_pending(
    pending_id: int,
    payload: CreateCustomerFromPendingPayload,
    db: Session = Depends(get_db),
    current_user=Depends(get_current_user),
):
    pending = _get_pending_or_404(db, pending_id, current_user.company_id)
    pending = resolve_pending_with_new_customer(
        db=db,
        pending=pending,
        payload=payload,
        current_user=current_user,
    )
    staging = pending.staging_receivable
    return PendingItem(
        id=pending.id,
        status=pending.status.value,
        note=pending.note,
        suggested_customer_id=pending.suggested_customer_id,
        resolved_customer_id=pending.resolved_customer_id,
        staging_receivable_id=staging.id,
        customer_name=staging.customer_name,
        customer_document_number=staging.customer_document_number,
        receivable_number=staging.receivable_number,
        nosso_numero=staging.nosso_numero,
        due_date=staging.due_date,
        amount_total=staging.amount_total,
    )