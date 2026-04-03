from datetime import datetime, timezone

from sqlalchemy import (
    Boolean,
    Column,
    Date,
    DateTime,
    ForeignKey,
    Integer,
    Numeric,
    String,
    Text,
    UniqueConstraint,
    Index,
)
from sqlalchemy.orm import relationship

from .db import Base


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


class Company(Base):
    __tablename__ = "companies"

    id = Column(Integer, primary_key=True, index=True)
    legal_name = Column(String(200), nullable=False)
    trade_name = Column(String(200), nullable=True)
    slug = Column(String(120), nullable=False, unique=True, index=True)
    is_active = Column(Boolean, nullable=False, default=True)
    created_at = Column(DateTime(timezone=True), nullable=False, default=utcnow)
    updated_at = Column(DateTime(timezone=True), nullable=False, default=utcnow, onupdate=utcnow)


class User(Base):
    __tablename__ = "users"

    id = Column(Integer, primary_key=True, index=True)
    company_id = Column(Integer, ForeignKey("companies.id", ondelete="RESTRICT"), nullable=False, index=True)
    full_name = Column(String(200), nullable=False)
    email = Column(String(255), nullable=False, unique=True, index=True)
    password_hash = Column(String(255), nullable=False)
    role = Column(String(30), nullable=False, index=True)
    is_active = Column(Boolean, nullable=False, default=True)
    created_at = Column(DateTime(timezone=True), nullable=False, default=utcnow)
    updated_at = Column(DateTime(timezone=True), nullable=False, default=utcnow, onupdate=utcnow)

    company = relationship("Company")


class UploadBatch(Base):
    __tablename__ = "upload_batches"

    id = Column(Integer, primary_key=True, index=True)
    company_id = Column(Integer, ForeignKey("companies.id", ondelete="RESTRICT"), nullable=False, index=True)
    uploaded_by_user_id = Column(Integer, ForeignKey("users.id", ondelete="RESTRICT"), nullable=False)
    batch_reference = Column(String(64), nullable=False, unique=True, index=True)
    clients_filename = Column(String(255), nullable=False)
    receivables_filename = Column(String(255), nullable=False)
    clients_file_hash = Column(String(64), nullable=False)
    receivables_file_hash = Column(String(64), nullable=False)
    status = Column(String(30), nullable=False, default="uploaded", index=True)
    preview_total_customers = Column(Integer, nullable=False, default=0)
    preview_total_receivables = Column(Integer, nullable=False, default=0)
    preview_total_pending_links = Column(Integer, nullable=False, default=0)
    preview_total_errors = Column(Integer, nullable=False, default=0)
    approved_at = Column(DateTime(timezone=True), nullable=True)
    approved_by_user_id = Column(Integer, ForeignKey("users.id", ondelete="SET NULL"), nullable=True)
    created_at = Column(DateTime(timezone=True), nullable=False, default=utcnow)
    updated_at = Column(DateTime(timezone=True), nullable=False, default=utcnow, onupdate=utcnow)

    company = relationship("Company")
    uploaded_by = relationship("User", foreign_keys=[uploaded_by_user_id])
    approved_by = relationship("User", foreign_keys=[approved_by_user_id])


class StagingCustomer(Base):
    __tablename__ = "staging_customers"
    __table_args__ = (
        UniqueConstraint("upload_batch_id", "row_number", name="uq_staging_customers_batch_row"),
        Index("ix_staging_customers_company_batch", "company_id", "upload_batch_id"),
    )

    id = Column(Integer, primary_key=True, index=True)
    company_id = Column(Integer, ForeignKey("companies.id", ondelete="RESTRICT"), nullable=False, index=True)
    upload_batch_id = Column(Integer, ForeignKey("upload_batches.id", ondelete="CASCADE"), nullable=False, index=True)
    row_number = Column(Integer, nullable=False)
    external_code = Column(String(120), nullable=True)
    full_name = Column(String(200), nullable=True)
    normalized_name = Column(String(200), nullable=True, index=True)
    document_number = Column(String(30), nullable=True)
    email_billing = Column(String(255), nullable=True)
    email_financial = Column(String(255), nullable=True)
    phone = Column(String(50), nullable=True)
    other_contacts = Column(Text, nullable=True)
    raw_payload = Column(Text, nullable=False, default="{}")
    validation_status = Column(String(20), nullable=False, default="pending", index=True)
    validation_errors = Column(Text, nullable=False, default="[]")
    created_at = Column(DateTime(timezone=True), nullable=False, default=utcnow)


class StagingReceivable(Base):
    __tablename__ = "staging_receivables"
    __table_args__ = (
        UniqueConstraint("upload_batch_id", "row_number", name="uq_staging_receivables_batch_row"),
        Index("ix_staging_receivables_company_batch", "company_id", "upload_batch_id"),
    )

    id = Column(Integer, primary_key=True, index=True)
    company_id = Column(Integer, ForeignKey("companies.id", ondelete="RESTRICT"), nullable=False, index=True)
    upload_batch_id = Column(Integer, ForeignKey("upload_batches.id", ondelete="CASCADE"), nullable=False, index=True)
    row_number = Column(Integer, nullable=False)
    customer_external_code = Column(String(120), nullable=True)
    customer_name = Column(String(200), nullable=True)
    normalized_customer_name = Column(String(200), nullable=True, index=True)
    customer_document_number = Column(String(30), nullable=True)
    receivable_number = Column(String(120), nullable=True)
    nosso_numero = Column(String(120), nullable=True, index=True)
    charge_type = Column(String(50), nullable=True)
    issue_date = Column(Date, nullable=True)
    due_date = Column(Date, nullable=True, index=True)
    amount_total = Column(Numeric(14, 2), nullable=True)
    balance_amount = Column(Numeric(14, 2), nullable=True)
    balance_without_interest = Column(Numeric(14, 2), nullable=True)
    status_raw = Column(String(50), nullable=True)
    email_billing = Column(String(255), nullable=True)
    raw_payload = Column(Text, nullable=False, default="{}")
    validation_status = Column(String(20), nullable=False, default="pending", index=True)
    validation_errors = Column(Text, nullable=False, default="[]")
    created_at = Column(DateTime(timezone=True), nullable=False, default=utcnow)


class Customer(Base):
    __tablename__ = "customers"
    __table_args__ = (
        UniqueConstraint("company_id", "external_code", name="uq_customers_company_external_code"),
        Index("ix_customers_company_name", "company_id", "full_name"),
        Index("ix_customers_company_document", "company_id", "document_number"),
    )

    id = Column(Integer, primary_key=True, index=True)
    company_id = Column(Integer, ForeignKey("companies.id", ondelete="RESTRICT"), nullable=False, index=True)
    external_code = Column(String(120), nullable=True)
    full_name = Column(String(200), nullable=False)
    normalized_name = Column(String(200), nullable=False, index=True)
    document_number = Column(String(30), nullable=True)
    email_billing = Column(String(255), nullable=True, index=True)
    email_financial = Column(String(255), nullable=True)
    phone = Column(String(50), nullable=True)
    other_contacts = Column(Text, nullable=True)
    is_active = Column(Boolean, nullable=False, default=True, index=True)
    created_at = Column(DateTime(timezone=True), nullable=False, default=utcnow)
    updated_at = Column(DateTime(timezone=True), nullable=False, default=utcnow, onupdate=utcnow)

    receivables = relationship("Receivable", back_populates="customer")


class Receivable(Base):
    __tablename__ = "receivables"
    __table_args__ = (
        UniqueConstraint("company_id", "nosso_numero", name="uq_receivables_company_nosso_numero"),
        Index("ix_receivables_company_customer", "company_id", "customer_id"),
        Index("ix_receivables_company_status_due", "company_id", "status", "due_date"),
    )

    id = Column(Integer, primary_key=True, index=True)
    company_id = Column(Integer, ForeignKey("companies.id", ondelete="RESTRICT"), nullable=False, index=True)
    customer_id = Column(Integer, ForeignKey("customers.id", ondelete="SET NULL"), nullable=True, index=True)
    upload_batch_id = Column(Integer, ForeignKey("upload_batches.id", ondelete="SET NULL"), nullable=True)
    receivable_number = Column(String(120), nullable=True)
    nosso_numero = Column(String(120), nullable=True)
    charge_type = Column(String(50), nullable=True)
    issue_date = Column(Date, nullable=True)
    due_date = Column(Date, nullable=False)
    amount_total = Column(Numeric(14, 2), nullable=False)
    balance_amount = Column(Numeric(14, 2), nullable=False, default=0)
    balance_without_interest = Column(Numeric(14, 2), nullable=False, default=0)
    status = Column(String(30), nullable=False, default="em_aberto", index=True)
    customer_name_snapshot = Column(String(200), nullable=False)
    billing_email_snapshot = Column(String(255), nullable=True)
    document_snapshot = Column(String(30), nullable=True)
    is_active = Column(Boolean, nullable=False, default=True, index=True)
    last_standard_message_at = Column(DateTime(timezone=True), nullable=True)
    created_at = Column(DateTime(timezone=True), nullable=False, default=utcnow)
    updated_at = Column(DateTime(timezone=True), nullable=False, default=utcnow, onupdate=utcnow)

    customer = relationship("Customer", back_populates="receivables")


class CustomerLinkPending(Base):
    __tablename__ = "customer_link_pendings"
    __table_args__ = (
        Index("ix_customer_link_pending_company_status", "company_id", "status"),
    )

    id = Column(Integer, primary_key=True, index=True)
    company_id = Column(Integer, ForeignKey("companies.id", ondelete="RESTRICT"), nullable=False, index=True)
    upload_batch_id = Column(Integer, ForeignKey("upload_batches.id", ondelete="CASCADE"), nullable=False, index=True)
    staging_receivable_id = Column(Integer, ForeignKey("staging_receivables.id", ondelete="CASCADE"), nullable=False, unique=True)
    suggested_customer_id = Column(Integer, ForeignKey("customers.id", ondelete="SET NULL"), nullable=True)
    status = Column(String(20), nullable=False, default="open", index=True)
    resolution_note = Column(Text, nullable=True)
    created_at = Column(DateTime(timezone=True), nullable=False, default=utcnow)
    resolved_at = Column(DateTime(timezone=True), nullable=True)
    resolved_by_user_id = Column(Integer, ForeignKey("users.id", ondelete="SET NULL"), nullable=True)


class MessageTemplate(Base):
    __tablename__ = "message_templates"
    __table_args__ = (
        Index("ix_message_templates_company_active", "company_id", "is_active"),
    )

    id = Column(Integer, primary_key=True, index=True)
    company_id = Column(Integer, ForeignKey("companies.id", ondelete="RESTRICT"), nullable=False, index=True)
    name = Column(String(120), nullable=False)
    subject_template = Column(String(200), nullable=False)
    body_template = Column(Text, nullable=False)
    is_active = Column(Boolean, nullable=False, default=True)
    created_at = Column(DateTime(timezone=True), nullable=False, default=utcnow)
    updated_at = Column(DateTime(timezone=True), nullable=False, default=utcnow, onupdate=utcnow)


class ManualMessage(Base):
    __tablename__ = "manual_messages"
    __table_args__ = (
        Index("ix_manual_messages_company_customer", "company_id", "customer_id"),
    )

    id = Column(Integer, primary_key=True, index=True)
    company_id = Column(Integer, ForeignKey("companies.id", ondelete="RESTRICT"), nullable=False, index=True)
    customer_id = Column(Integer, ForeignKey("customers.id", ondelete="RESTRICT"), nullable=False, index=True)
    receivable_id = Column(Integer, ForeignKey("receivables.id", ondelete="SET NULL"), nullable=True, index=True)
    created_by_user_id = Column(Integer, ForeignKey("users.id", ondelete="RESTRICT"), nullable=False)
    subject = Column(String(200), nullable=False)
    body = Column(Text, nullable=False)
    recipient_email = Column(String(255), nullable=False)
    preview_hash = Column(String(64), nullable=False, index=True)
    created_at = Column(DateTime(timezone=True), nullable=False, default=utcnow)


class OutboxMessage(Base):
    __tablename__ = "outbox_messages"
    __table_args__ = (
        UniqueConstraint("company_id", "dedupe_key", name="uq_outbox_company_dedupe"),
        Index("ix_outbox_company_status", "company_id", "status"),
    )

    id = Column(Integer, primary_key=True, index=True)
    company_id = Column(Integer, ForeignKey("companies.id", ondelete="RESTRICT"), nullable=False, index=True)
    customer_id = Column(Integer, ForeignKey("customers.id", ondelete="SET NULL"), nullable=True, index=True)
    receivable_id = Column(Integer, ForeignKey("receivables.id", ondelete="SET NULL"), nullable=True, index=True)
    manual_message_id = Column(Integer, ForeignKey("manual_messages.id", ondelete="SET NULL"), nullable=True)
    template_id = Column(Integer, ForeignKey("message_templates.id", ondelete="SET NULL"), nullable=True)
    message_kind = Column(String(20), nullable=False)
    recipient_email = Column(String(255), nullable=False)
    subject = Column(String(200), nullable=False)
    body = Column(Text, nullable=False)
    dedupe_key = Column(String(64), nullable=False)
    status = Column(String(20), nullable=False, default="queued")
    error_message = Column(Text, nullable=True)
    sent_at = Column(DateTime(timezone=True), nullable=True)
    created_at = Column(DateTime(timezone=True), nullable=False, default=utcnow)


class ReceivableHistory(Base):
    __tablename__ = "receivable_histories"
    __table_args__ = (
        Index("ix_receivable_histories_company_receivable", "company_id", "receivable_id"),
    )

    id = Column(Integer, primary_key=True, index=True)
    company_id = Column(Integer, ForeignKey("companies.id", ondelete="RESTRICT"), nullable=False, index=True)
    receivable_id = Column(Integer, ForeignKey("receivables.id", ondelete="CASCADE"), nullable=False, index=True)
    event_type = Column(String(40), nullable=False)
    old_status = Column(String(30), nullable=True)
    new_status = Column(String(30), nullable=True)
    note = Column(Text, nullable=True)
    created_by_user_id = Column(Integer, ForeignKey("users.id", ondelete="SET NULL"), nullable=True)
    created_at = Column(DateTime(timezone=True), nullable=False, default=utcnow)


class AuditLog(Base):
    __tablename__ = "audit_logs"
    __table_args__ = (
        Index("ix_audit_logs_company_created", "company_id", "created_at"),
    )

    id = Column(Integer, primary_key=True, index=True)
    company_id = Column(Integer, ForeignKey("companies.id", ondelete="RESTRICT"), nullable=False, index=True)
    user_id = Column(Integer, ForeignKey("users.id", ondelete="SET NULL"), nullable=True, index=True)
    entity_type = Column(String(60), nullable=False)
    entity_id = Column(String(120), nullable=False)
    action = Column(String(40), nullable=False)
    details = Column(Text, nullable=False, default="{}")
    created_at = Column(DateTime(timezone=True), nullable=False, default=utcnow)