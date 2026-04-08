from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy import select
from sqlalchemy.orm import Session

from ..dependencies import get_current_user, get_db, require_permission
from ..models import Customer, MessageTemplate, OutboxMessage, Receivable, RoleEnum
from ..schemas import (
    DispatchResponse,
    ManualMessageCreate,
    ManualMessageQueuedResponse,
    MessageTemplatePayload,
    MessageTemplateResponse,
    OutboxItem,
    PreviewResponse,
)
from ..services.notifier import (
    ALLOWED_PLACEHOLDERS,
    build_customer_context,
    build_receivable_context,
    dispatch_pending_outbox,
    queue_manual_message,
    queue_standard_message,
    render_template,
)

router = APIRouter(prefix="/api", tags=["messages"])


def _get_or_create_template(db: Session, company_id: int) -> MessageTemplate:
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


@router.get(
    "/message-template",
    response_model=MessageTemplateResponse,
    dependencies=[Depends(require_permission(RoleEnum.SENDER, RoleEnum.APPROVER, RoleEnum.CLIENT_OPERATOR))],
)
def get_template(
    db: Session = Depends(get_db),
    current_user=Depends(get_current_user),
):
    template = _get_or_create_template(db, current_user.company_id)
    return MessageTemplateResponse(
        subject=template.subject,
        body=template.body,
        allowed_placeholders=ALLOWED_PLACEHOLDERS,
    )


@router.put(
    "/message-template",
    response_model=MessageTemplateResponse,
    dependencies=[Depends(require_permission(RoleEnum.SENDER, RoleEnum.APPROVER))],
)
def update_template(
    payload: MessageTemplatePayload,
    db: Session = Depends(get_db),
    current_user=Depends(get_current_user),
):
    template = _get_or_create_template(db, current_user.company_id)
    render_template(payload.subject, payload.body, {})  # valida placeholders
    template.subject = payload.subject
    template.body = payload.body
    db.commit()
    db.refresh(template)

    return MessageTemplateResponse(
        subject=template.subject,
        body=template.body,
        allowed_placeholders=ALLOWED_PLACEHOLDERS,
    )


@router.post(
    "/message-template/preview",
    response_model=PreviewResponse,
    dependencies=[Depends(require_permission(RoleEnum.SENDER, RoleEnum.APPROVER, RoleEnum.CLIENT_OPERATOR))],
)
def preview_template(
    payload: MessageTemplatePayload,
    customer_id: int | None = None,
    receivable_id: int | None = None,
    db: Session = Depends(get_db),
    current_user=Depends(get_current_user),
):
    context: dict = {}

    if customer_id is not None:
        customer = db.execute(
            select(Customer).where(Customer.id == customer_id, Customer.company_id == current_user.company_id)
        ).scalar_one_or_none()
        if not customer:
            raise HTTPException(status_code=404, detail="Cliente não encontrado.")
        context.update(build_customer_context(customer))

    if receivable_id is not None:
        receivable = db.execute(
            select(Receivable).where(Receivable.id == receivable_id, Receivable.company_id == current_user.company_id)
        ).scalar_one_or_none()
        if not receivable:
            raise HTTPException(status_code=404, detail="Cobrança não encontrada.")
        customer = db.execute(
            select(Customer).where(Customer.id == receivable.customer_id, Customer.company_id == current_user.company_id)
        ).scalar_one_or_none()
        context.update(build_receivable_context(customer, receivable))

    subject, body = render_template(payload.subject, payload.body, context)
    return PreviewResponse(subject=subject, body=body, context_used=context)


@router.post(
    "/receivables/{receivable_id}/queue-standard-message",
    response_model=ManualMessageQueuedResponse,
    dependencies=[Depends(require_permission(RoleEnum.SENDER, RoleEnum.APPROVER))],
)
def queue_standard_receivable_message(
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

    queued = queue_standard_message(
        db=db,
        company_id=current_user.company_id,
        receivable=receivable,
        user_id=current_user.id,
    )
    return ManualMessageQueuedResponse(outbox_message_id=queued.id, status=queued.status.value)


@router.post(
    "/customers/{customer_id}/send-manual-message",
    response_model=ManualMessageQueuedResponse,
    dependencies=[Depends(require_permission(RoleEnum.SENDER, RoleEnum.APPROVER, RoleEnum.CLIENT_OPERATOR))],
)
def send_manual_message(
    customer_id: int,
    payload: ManualMessageCreate,
    db: Session = Depends(get_db),
    current_user=Depends(get_current_user),
):
    customer = db.execute(
        select(Customer).where(
            Customer.id == customer_id,
            Customer.company_id == current_user.company_id,
        )
    ).scalar_one_or_none()

    if not customer:
        raise HTTPException(status_code=404, detail="Cliente não encontrado.")

    queued = queue_manual_message(
        db=db,
        company_id=current_user.company_id,
        customer=customer,
        user_id=current_user.id,
        recipient_email=payload.recipient_email,
        subject=payload.subject,
        body=payload.body,
    )
    return ManualMessageQueuedResponse(outbox_message_id=queued.id, status=queued.status.value)


@router.get(
    "/outbox",
    response_model=list[OutboxItem],
    dependencies=[Depends(require_permission(RoleEnum.SENDER, RoleEnum.APPROVER, RoleEnum.AUDITOR))],
)
def list_outbox(
    status_filter: str | None = None,
    db: Session = Depends(get_db),
    current_user=Depends(get_current_user),
):
    stmt = (
        select(OutboxMessage)
        .where(OutboxMessage.company_id == current_user.company_id)
        .order_by(OutboxMessage.created_at.desc())
    )

    rows = list(db.execute(stmt).scalars().all())
    if status_filter:
        rows = [row for row in rows if row.status.value == status_filter]

    return [
        OutboxItem(
            id=row.id,
            message_kind=row.message_kind,
            recipient_email=row.recipient_email,
            subject=row.subject,
            status=row.status.value,
            error_message=row.error_message,
            sent_at=row.sent_at,
            created_at=row.created_at,
        )
        for row in rows
    ]


@router.post(
    "/outbox/dispatch",
    response_model=DispatchResponse,
    dependencies=[Depends(require_permission(RoleEnum.SENDER, RoleEnum.APPROVER))],
)
def dispatch_outbox(
    limit: int = 20,
    db: Session = Depends(get_db),
    current_user=Depends(get_current_user),
):
    result = dispatch_pending_outbox(
        db=db,
        company_id=current_user.company_id,
        limit=limit,
    )
    return DispatchResponse(**result)