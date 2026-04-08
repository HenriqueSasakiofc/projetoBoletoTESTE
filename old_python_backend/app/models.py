from __future__ import annotations

import enum
from datetime import date, datetime

from sqlalchemy import (
    JSON,
    Boolean,
    Date,
    DateTime,
    Enum,
    ForeignKey,
    Integer,
    Numeric,
    String,
    Text,
    UniqueConstraint,
    func,
)
from sqlalchemy.orm import Mapped, mapped_column, relationship

from .db import Base


class RoleEnum(str, enum.Enum):
    ADMIN = "ADMIN"
    IMPORTER = "IMPORTER"
    APPROVER = "APPROVER"
    SENDER = "SENDER"
    AUDITOR = "AUDITOR"
    CLIENT_OPERATOR = "CLIENT_OPERATOR"


class BatchStatusEnum(str, enum.Enum):
    PROCESSING = "processing"
    PREVIEW_READY = "preview_ready"
    PENDING_REVIEW = "pending_review"
    MERGED = "merged"
    FAILED = "failed"


class ValidationStatusEnum(str, enum.Enum):
    VALID = "valid"
    INVALID = "invalid"


class PendingStatusEnum(str, enum.Enum):
    OPEN = "open"
    RESOLVED = "resolved"


class ReceivableStatusEnum(str, enum.Enum):
    PAGO = "pago"
    EM_ABERTO = "em_aberto"
    VENCENDO = "vencendo"
    INADIMPLENTE = "inadimplente"
    CANCELADO = "cancelado"


class MessageStatusEnum(str, enum.Enum):
    PENDING = "pending"
    SENT = "sent"
    ERROR = "error"
    CANCELLED = "cancelled"


class TimestampMixin:
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), nullable=False)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(), nullable=False
    )


class Company(Base, TimestampMixin):
    __tablename__ = "companies"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    slug: Mapped[str] = mapped_column(String(120), unique=True, index=True, nullable=False)
    legal_name: Mapped[str] = mapped_column(String(255), nullable=False)
    trade_name: Mapped[str] = mapped_column(String(255), nullable=False)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)

    users: Mapped[list["User"]] = relationship(back_populates="company")
    customers: Mapped[list["Customer"]] = relationship(back_populates="company")
    receivables: Mapped[list["Receivable"]] = relationship(back_populates="company")


class User(Base, TimestampMixin):
    __tablename__ = "users"
    __table_args__ = (UniqueConstraint("company_id", "email", name="uq_user_company_email"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    company_id: Mapped[int] = mapped_column(ForeignKey("companies.id"), index=True, nullable=False)
    email: Mapped[str] = mapped_column(String(255), nullable=False)
    full_name: Mapped[str] = mapped_column(String(255), nullable=False)
    password_hash: Mapped[str] = mapped_column(String(255), nullable=False)
    role: Mapped[RoleEnum] = mapped_column(Enum(RoleEnum), nullable=False, default=RoleEnum.ADMIN)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)

    company: Mapped["Company"] = relationship(back_populates="users")


class UploadBatch(Base, TimestampMixin):
    __tablename__ = "upload_batches"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    company_id: Mapped[int] = mapped_column(ForeignKey("companies.id"), index=True, nullable=False)
    uploaded_by_user_id: Mapped[int] = mapped_column(ForeignKey("users.id"), nullable=False)
    approved_by_user_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"))
    customers_filename: Mapped[str] = mapped_column(String(255), nullable=False)
    receivables_filename: Mapped[str] = mapped_column(String(255), nullable=False)
    customers_hash: Mapped[str] = mapped_column(String(64), nullable=False)
    receivables_hash: Mapped[str] = mapped_column(String(64), nullable=False)
    status: Mapped[BatchStatusEnum] = mapped_column(
        Enum(BatchStatusEnum), default=BatchStatusEnum.PROCESSING, nullable=False
    )
    preview_customers_total: Mapped[int] = mapped_column(Integer, default=0, nullable=False)
    preview_receivables_total: Mapped[int] = mapped_column(Integer, default=0, nullable=False)
    preview_invalid_customers: Mapped[int] = mapped_column(Integer, default=0, nullable=False)
    preview_invalid_receivables: Mapped[int] = mapped_column(Integer, default=0, nullable=False)
    preview_pending_links: Mapped[int] = mapped_column(Integer, default=0, nullable=False)
    merged_customers_count: Mapped[int] = mapped_column(Integer, default=0, nullable=False)
    merged_receivables_count: Mapped[int] = mapped_column(Integer, default=0, nullable=False)
    error_message: Mapped[str | None] = mapped_column(Text)

    staging_customers: Mapped[list["StagingCustomer"]] = relationship(
        back_populates="upload_batch", cascade="all, delete-orphan"
    )
    staging_receivables: Mapped[list["StagingReceivable"]] = relationship(
        back_populates="upload_batch", cascade="all, delete-orphan"
    )
    pendings: Mapped[list["CustomerLinkPending"]] = relationship(
        back_populates="upload_batch", cascade="all, delete-orphan"
    )


class StagingCustomer(Base, TimestampMixin):
    __tablename__ = "staging_customers"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    company_id: Mapped[int] = mapped_column(ForeignKey("companies.id"), index=True, nullable=False)
    upload_batch_id: Mapped[int] = mapped_column(ForeignKey("upload_batches.id"), index=True, nullable=False)
    row_number: Mapped[int] = mapped_column(Integer, nullable=False)
    external_code: Mapped[str | None] = mapped_column(String(120), index=True)
    full_name: Mapped[str] = mapped_column(String(255), nullable=False)
    normalized_name: Mapped[str] = mapped_column(String(255), index=True, nullable=False)
    document_number: Mapped[str | None] = mapped_column(String(20), index=True)
    email_billing: Mapped[str | None] = mapped_column(String(255))
    email_financial: Mapped[str | None] = mapped_column(String(255))
    phone: Mapped[str | None] = mapped_column(String(40))
    other_contacts: Mapped[str | None] = mapped_column(Text)
    raw_payload: Mapped[dict | None] = mapped_column(JSON)
    validation_status: Mapped[ValidationStatusEnum] = mapped_column(
        Enum(ValidationStatusEnum), default=ValidationStatusEnum.VALID, nullable=False
    )
    validation_errors: Mapped[list | None] = mapped_column(JSON)

    upload_batch: Mapped["UploadBatch"] = relationship(back_populates="staging_customers")


class StagingReceivable(Base, TimestampMixin):
    __tablename__ = "staging_receivables"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    company_id: Mapped[int] = mapped_column(ForeignKey("companies.id"), index=True, nullable=False)
    upload_batch_id: Mapped[int] = mapped_column(ForeignKey("upload_batches.id"), index=True, nullable=False)
    row_number: Mapped[int] = mapped_column(Integer, nullable=False)
    customer_external_code: Mapped[str | None] = mapped_column(String(120), index=True)
    customer_name: Mapped[str] = mapped_column(String(255), nullable=False)
    normalized_customer_name: Mapped[str] = mapped_column(String(255), index=True, nullable=False)
    customer_document_number: Mapped[str | None] = mapped_column(String(20), index=True)
    receivable_number: Mapped[str | None] = mapped_column(String(120), index=True)
    nosso_numero: Mapped[str | None] = mapped_column(String(120), index=True)
    charge_type: Mapped[str | None] = mapped_column(String(120))
    issue_date: Mapped[date | None] = mapped_column(Date)
    due_date: Mapped[date | None] = mapped_column(Date, index=True)
    amount_total: Mapped[float | None] = mapped_column(Numeric(14, 2))
    balance_amount: Mapped[float | None] = mapped_column(Numeric(14, 2))
    balance_without_interest: Mapped[float | None] = mapped_column(Numeric(14, 2))
    status_raw: Mapped[str | None] = mapped_column(String(120))
    email_billing: Mapped[str | None] = mapped_column(String(255))
    raw_payload: Mapped[dict | None] = mapped_column(JSON)
    validation_status: Mapped[ValidationStatusEnum] = mapped_column(
        Enum(ValidationStatusEnum), default=ValidationStatusEnum.VALID, nullable=False
    )
    validation_errors: Mapped[list | None] = mapped_column(JSON)

    upload_batch: Mapped["UploadBatch"] = relationship(back_populates="staging_receivables")
    pendings: Mapped[list["CustomerLinkPending"]] = relationship(back_populates="staging_receivable")


class Customer(Base, TimestampMixin):
    __tablename__ = "customers"
    __table_args__ = (
        UniqueConstraint("company_id", "external_code", name="uq_customer_company_external_code"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    company_id: Mapped[int] = mapped_column(ForeignKey("companies.id"), index=True, nullable=False)
    external_code: Mapped[str | None] = mapped_column(String(120), index=True)
    full_name: Mapped[str] = mapped_column(String(255), index=True, nullable=False)
    normalized_name: Mapped[str] = mapped_column(String(255), index=True, nullable=False)
    document_number: Mapped[str | None] = mapped_column(String(20), index=True)
    email_billing: Mapped[str | None] = mapped_column(String(255))
    email_financial: Mapped[str | None] = mapped_column(String(255))
    phone: Mapped[str | None] = mapped_column(String(40))
    other_contacts: Mapped[str | None] = mapped_column(Text)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)

    company: Mapped["Company"] = relationship(back_populates="customers")
    receivables: Mapped[list["Receivable"]] = relationship(back_populates="customer")
    manual_messages: Mapped[list["ManualMessage"]] = relationship(back_populates="customer")


class Receivable(Base, TimestampMixin):
    __tablename__ = "receivables"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    company_id: Mapped[int] = mapped_column(ForeignKey("companies.id"), index=True, nullable=False)
    customer_id: Mapped[int] = mapped_column(ForeignKey("customers.id"), index=True, nullable=False)
    upload_batch_id: Mapped[int | None] = mapped_column(ForeignKey("upload_batches.id"), index=True)
    receivable_number: Mapped[str | None] = mapped_column(String(120), index=True)
    nosso_numero: Mapped[str | None] = mapped_column(String(120), index=True)
    charge_type: Mapped[str | None] = mapped_column(String(120))
    issue_date: Mapped[date | None] = mapped_column(Date)
    due_date: Mapped[date | None] = mapped_column(Date, index=True)
    amount_total: Mapped[float | None] = mapped_column(Numeric(14, 2))
    balance_amount: Mapped[float | None] = mapped_column(Numeric(14, 2))
    balance_without_interest: Mapped[float | None] = mapped_column(Numeric(14, 2))
    status: Mapped[ReceivableStatusEnum] = mapped_column(Enum(ReceivableStatusEnum), index=True, nullable=False)
    snapshot_customer_name: Mapped[str | None] = mapped_column(String(255))
    snapshot_customer_document: Mapped[str | None] = mapped_column(String(20))
    snapshot_email_billing: Mapped[str | None] = mapped_column(String(255))
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)
    last_standard_message_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))

    company: Mapped["Company"] = relationship(back_populates="receivables")
    customer: Mapped["Customer"] = relationship(back_populates="receivables")
    outbox_messages: Mapped[list["OutboxMessage"]] = relationship(back_populates="receivable")
    history_entries: Mapped[list["ReceivableHistory"]] = relationship(back_populates="receivable")


class CustomerLinkPending(Base, TimestampMixin):
    __tablename__ = "customer_link_pendings"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    company_id: Mapped[int] = mapped_column(ForeignKey("companies.id"), index=True, nullable=False)
    upload_batch_id: Mapped[int] = mapped_column(ForeignKey("upload_batches.id"), index=True, nullable=False)
    staging_receivable_id: Mapped[int] = mapped_column(ForeignKey("staging_receivables.id"), index=True, nullable=False)
    suggested_customer_id: Mapped[int | None] = mapped_column(ForeignKey("customers.id"))
    resolved_customer_id: Mapped[int | None] = mapped_column(ForeignKey("customers.id"))
    status: Mapped[PendingStatusEnum] = mapped_column(
        Enum(PendingStatusEnum), default=PendingStatusEnum.OPEN, nullable=False
    )
    note: Mapped[str | None] = mapped_column(Text)
    resolved_by_user_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"))
    resolved_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))

    upload_batch: Mapped["UploadBatch"] = relationship(back_populates="pendings")
    staging_receivable: Mapped["StagingReceivable"] = relationship(back_populates="pendings")
    suggested_customer: Mapped["Customer"] = relationship(foreign_keys=[suggested_customer_id])
    resolved_customer: Mapped["Customer"] = relationship(foreign_keys=[resolved_customer_id])


class MessageTemplate(Base, TimestampMixin):
    __tablename__ = "message_templates"
    __table_args__ = (UniqueConstraint("company_id", name="uq_message_template_company"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    company_id: Mapped[int] = mapped_column(ForeignKey("companies.id"), index=True, nullable=False)
    subject: Mapped[str] = mapped_column(String(255), nullable=False)
    body: Mapped[str] = mapped_column(Text, nullable=False)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)


class ManualMessage(Base, TimestampMixin):
    __tablename__ = "manual_messages"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    company_id: Mapped[int] = mapped_column(ForeignKey("companies.id"), index=True, nullable=False)
    customer_id: Mapped[int] = mapped_column(ForeignKey("customers.id"), index=True, nullable=False)
    created_by_user_id: Mapped[int] = mapped_column(ForeignKey("users.id"), nullable=False)
    recipient_email: Mapped[str] = mapped_column(String(255), nullable=False)
    subject: Mapped[str] = mapped_column(String(255), nullable=False)
    body: Mapped[str] = mapped_column(Text, nullable=False)
    preview_hash: Mapped[str] = mapped_column(String(64), nullable=False)

    customer: Mapped["Customer"] = relationship(back_populates="manual_messages")


class OutboxMessage(Base, TimestampMixin):
    __tablename__ = "outbox_messages"
    __table_args__ = (UniqueConstraint("company_id", "dedupe_key", name="uq_outbox_company_dedupe"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    company_id: Mapped[int] = mapped_column(ForeignKey("companies.id"), index=True, nullable=False)
    receivable_id: Mapped[int | None] = mapped_column(ForeignKey("receivables.id"), index=True)
    customer_id: Mapped[int | None] = mapped_column(ForeignKey("customers.id"), index=True)
    created_by_user_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"))
    message_kind: Mapped[str] = mapped_column(String(50), nullable=False)
    recipient_email: Mapped[str] = mapped_column(String(255), nullable=False)
    subject: Mapped[str] = mapped_column(String(255), nullable=False)
    body: Mapped[str] = mapped_column(Text, nullable=False)
    dedupe_key: Mapped[str] = mapped_column(String(255), nullable=False)
    status: Mapped[MessageStatusEnum] = mapped_column(
        Enum(MessageStatusEnum), default=MessageStatusEnum.PENDING, nullable=False
    )
    error_message: Mapped[str | None] = mapped_column(Text)
    sent_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))

    receivable: Mapped["Receivable"] = relationship(back_populates="outbox_messages")


class ReceivableHistory(Base, TimestampMixin):
    __tablename__ = "receivable_history"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    company_id: Mapped[int] = mapped_column(ForeignKey("companies.id"), index=True, nullable=False)
    receivable_id: Mapped[int] = mapped_column(ForeignKey("receivables.id"), index=True, nullable=False)
    changed_by_user_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"))
    old_status: Mapped[str | None] = mapped_column(String(50))
    new_status: Mapped[str | None] = mapped_column(String(50))
    note: Mapped[str | None] = mapped_column(Text)

    receivable: Mapped["Receivable"] = relationship(back_populates="history_entries")


class AuditLog(Base):
    __tablename__ = "audit_logs"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    company_id: Mapped[int | None] = mapped_column(ForeignKey("companies.id"), index=True)
    user_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"))
    entity: Mapped[str] = mapped_column(String(100), nullable=False)
    entity_id: Mapped[str | None] = mapped_column(String(100))
    action: Mapped[str] = mapped_column(String(100), nullable=False)
    details: Mapped[dict | None] = mapped_column(JSON)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), nullable=False)