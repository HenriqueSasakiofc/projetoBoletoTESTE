from __future__ import annotations

from datetime import date, datetime
from decimal import Decimal
from typing import Any

from pydantic import BaseModel, ConfigDict, EmailStr, Field


class UserMe(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    company_id: int
    email: str
    full_name: str
    role: str
    is_active: bool


class LoginRequest(BaseModel):
    email: EmailStr
    password: str = Field(min_length=1)


class TokenResponse(BaseModel):
    access_token: str
    token_type: str = "bearer"
    user: UserMe


class CompanySignupRequest(BaseModel):
    company_name: str = Field(min_length=2, max_length=120)
    admin_full_name: str = Field(min_length=2, max_length=255)
    admin_email: EmailStr
    admin_password: str = Field(min_length=8, max_length=128)
    admin_password_confirm: str = Field(min_length=8, max_length=128)
    terms_accepted: bool
    website: str | None = ""


class UploadBatchSummary(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    status: str
    customers_filename: str
    receivables_filename: str
    preview_customers_total: int
    preview_receivables_total: int
    preview_invalid_customers: int
    preview_invalid_receivables: int
    preview_pending_links: int
    merged_customers_count: int
    merged_receivables_count: int
    error_message: str | None
    created_at: datetime
    updated_at: datetime


class PendingItem(BaseModel):
    id: int
    status: str
    note: str | None
    suggested_customer_id: int | None
    resolved_customer_id: int | None
    staging_receivable_id: int
    customer_name: str
    customer_document_number: str | None
    receivable_number: str | None
    nosso_numero: str | None
    due_date: date | None
    amount_total: Decimal | None


class CustomerSummary(BaseModel):
    id: int
    external_code: str | None
    full_name: str
    email_billing: str | None
    email_billing_masked: str | None
    document_number_masked: str | None
    phone_masked: str | None
    receivables_total: int
    open_receivables_total: int
    overdue_receivables_total: int


class ReceivableSummary(BaseModel):
    id: int
    receivable_number: str | None
    nosso_numero: str | None
    due_date: date | None
    amount_total: Decimal | None
    balance_amount: Decimal | None
    status: str
    snapshot_email_billing: str | None
    last_standard_message_at: datetime | None


class ReceivableHistoryItem(BaseModel):
    id: int
    old_status: str | None
    new_status: str | None
    note: str | None
    created_at: datetime


class OutboxItem(BaseModel):
    id: int
    message_kind: str
    recipient_email: str
    subject: str
    status: str
    error_message: str | None
    sent_at: datetime | None
    created_at: datetime


class ManualMessageCreate(BaseModel):
    recipient_email: EmailStr
    subject: str = Field(min_length=1, max_length=255)
    body: str = Field(min_length=1)


class CustomerDetail(BaseModel):
    id: int
    external_code: str | None
    full_name: str
    email_billing: str | None
    email_billing_masked: str | None
    email_financial: str | None
    email_financial_masked: str | None
    phone: str | None
    phone_masked: str | None
    document_number_masked: str | None
    other_contacts: str | None
    receivables: list[ReceivableSummary]
    history: list[ReceivableHistoryItem]
    messages: list[OutboxItem]


class ClientListResponse(BaseModel):
    total: int
    page: int
    page_size: int
    items: list[CustomerSummary]


class ReceivableListResponse(BaseModel):
    total: int
    page: int
    page_size: int
    items: list[ReceivableSummary]


class MessageTemplatePayload(BaseModel):
    subject: str = Field(min_length=1, max_length=255)
    body: str = Field(min_length=1)


class MessageTemplateResponse(BaseModel):
    subject: str
    body: str
    allowed_placeholders: list[str]


class MessagePreviewPayload(BaseModel):
    subject: str = Field(min_length=1, max_length=255)
    body: str = Field(min_length=1)
    customer_id: int | None = None
    receivable_id: int | None = None


class PreviewResponse(BaseModel):
    subject: str
    body: str
    context_used: dict[str, Any]


class LinkExistingPayload(BaseModel):
    customer_id: int


class CreateCustomerFromPendingPayload(BaseModel):
    full_name: str
    document_number: str | None = None
    email_billing: EmailStr | None = None
    email_financial: EmailStr | None = None
    phone: str | None = None
    other_contacts: str | None = None


class ManualMessageQueuedResponse(BaseModel):
    outbox_message_id: int
    status: str


class DispatchResponse(BaseModel):
    sent: int
    errors: int
    processed_ids: list[int]