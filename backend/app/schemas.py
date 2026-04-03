from datetime import date, datetime
from decimal import Decimal
from typing import Literal, Optional

from pydantic import BaseModel, EmailStr, Field, field_validator


class LoginIn(BaseModel):
    email: EmailStr
    password: str = Field(min_length=8, max_length=128)


class TokenOut(BaseModel):
    access_token: str
    token_type: str
    role: str
    company_id: int
    user_name: str


class MeOut(BaseModel):
    id: int
    full_name: str
    email: EmailStr
    role: str
    company_id: int


class UploadBatchPreviewOut(BaseModel):
    batch_id: int
    status: str
    total_customer_rows: int
    total_receivable_rows: int
    valid_customer_rows: int
    valid_receivable_rows: int
    pending_links: int
    errors: list[str]


class BatchSummaryOut(BaseModel):
    id: int
    batch_reference: str
    status: str
    clients_filename: str
    receivables_filename: str
    preview_total_customers: int
    preview_total_receivables: int
    preview_total_pending_links: int
    preview_total_errors: int
    created_at: datetime


class ApproveBatchIn(BaseModel):
    confirm: bool


class PendingLinkOut(BaseModel):
    id: int
    staging_receivable_id: int
    customer_name: Optional[str]
    customer_document_masked: Optional[str]
    receivable_number: Optional[str]
    nosso_numero: Optional[str]
    status: str
    suggested_customer_id: Optional[int]


class LinkPendingIn(BaseModel):
    customer_id: int


class CreateCustomerFromPendingIn(BaseModel):
    full_name: str = Field(min_length=2, max_length=200)
    document_number: Optional[str] = Field(default=None, max_length=30)
    email_billing: Optional[EmailStr] = None
    email_financial: Optional[EmailStr] = None
    phone: Optional[str] = Field(default=None, max_length=50)
    other_contacts: Optional[str] = Field(default=None, max_length=1000)


class TemplateIn(BaseModel):
    subject_template: str = Field(min_length=3, max_length=200)
    body_template: str = Field(min_length=10, max_length=5000)


class TemplatePreviewIn(BaseModel):
    receivable_id: int
    body_template: str = Field(min_length=10, max_length=5000)
    subject_template: str = Field(min_length=3, max_length=200)


class TemplateOut(BaseModel):
    id: int
    subject_template: str
    body_template: str
    placeholders: list[str]


class PreviewOut(BaseModel):
    subject: str
    body: str


class QueueStandardMessageIn(BaseModel):
    receivable_id: int


class ManualMessageIn(BaseModel):
    receivable_id: Optional[int] = None
    subject: str = Field(min_length=3, max_length=200)
    body: str = Field(min_length=3, max_length=5000)


class OutboxItemOut(BaseModel):
    id: int
    recipient_email_masked: str
    subject: str
    status: str
    message_kind: str
    created_at: datetime
    sent_at: Optional[datetime]


class DispatchOut(BaseModel):
    sent: int
    failed: int


class CustomerListItemOut(BaseModel):
    id: int
    full_name: str
    email_billing_masked: Optional[str]
    phone_masked: Optional[str]
    document_masked: Optional[str]
    status: str
    active_receivables: int


class ReceivableListItemOut(BaseModel):
    id: int
    customer_id: Optional[int]
    customer_name: str
    nosso_numero: Optional[str]
    due_date: date
    amount_total: Decimal
    balance_amount: Decimal
    status: str


class CustomerProfileOut(BaseModel):
    id: int
    full_name: str
    email_billing_masked: Optional[str]
    email_financial_masked: Optional[str]
    phone_masked: Optional[str]
    document_masked: Optional[str]
    other_contacts: Optional[str]
    status: str
    receivables: list[ReceivableListItemOut]
    receivable_history: list[dict]
    message_history: list[dict]


class MarkPaidIn(BaseModel):
    paid_at: Optional[date] = None


class CustomerQueryParams(BaseModel):
    q: str = ""
    status: Optional[str] = None
    page: int = 1
    page_size: int = 10

    @field_validator("page")
    @classmethod
    def validate_page(cls, value: int) -> int:
        if value < 1:
            raise ValueError("page inválida")
        return value

    @field_validator("page_size")
    @classmethod
    def validate_page_size(cls, value: int) -> int:
        if value < 1 or value > 100:
            raise ValueError("page_size inválido")
        return value


AllowedStatus = Literal["pago", "em_aberto", "vencendo", "inadimplente", "cancelado"]