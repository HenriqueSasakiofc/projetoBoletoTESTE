from datetime import datetime, timedelta, timezone
from typing import Any

from jose import jwt
from passlib.context import CryptContext
from sqlalchemy.orm import Session

from .config import settings
from . import models

pwd_context = CryptContext(schemes=["bcrypt"], deprecated="auto")

ROLE_PERMISSIONS = {
    "ADMIN": {"upload", "approve_import", "prepare_send", "dispatch", "audit", "manage_clients"},
    "IMPORTER": {"upload"},
    "APPROVER": {"approve_import"},
    "SENDER": {"prepare_send", "dispatch"},
    "AUDITOR": {"audit"},
    "CLIENT_OPERATOR": {"manage_clients", "prepare_send"},
}


def hash_password(password: str) -> str:
    return pwd_context.hash(password)


def verify_password(plain_password: str, hashed_password: str) -> bool:
    return pwd_context.verify(plain_password, hashed_password)


def create_access_token(subject: str, company_id: int, role: str, expires_minutes: int | None = None) -> str:
    expire_minutes = expires_minutes or settings.access_token_minutes
    expire = datetime.now(timezone.utc) + timedelta(minutes=expire_minutes)
    payload: dict[str, Any] = {
        "sub": subject,
        "company_id": company_id,
        "role": role,
        "exp": expire,
    }
    return jwt.encode(payload, settings.app_secret_key, algorithm=settings.jwt_algorithm)


def decode_access_token(token: str) -> dict[str, Any]:
    return jwt.decode(token, settings.app_secret_key, algorithms=[settings.jwt_algorithm])


def authenticate_user(db: Session, email: str, password: str) -> models.User | None:
    user = (
        db.query(models.User)
        .filter(models.User.email == email.strip().lower(), models.User.is_active.is_(True))
        .first()
    )
    if not user:
        return None
    if not verify_password(password, user.password_hash):
        return None
    return user


def user_has_permission(role: str, permission: str) -> bool:
    return permission in ROLE_PERMISSIONS.get(role, set())


def bootstrap_initial_data(db: Session) -> None:
    company = (
        db.query(models.Company)
        .filter(models.Company.slug == settings.bootstrap_company_slug)
        .first()
    )
    if not company:
        company = models.Company(
            legal_name=settings.bootstrap_company_name,
            trade_name=settings.bootstrap_company_name,
            slug=settings.bootstrap_company_slug,
            is_active=True,
        )
        db.add(company)
        db.flush()

    admin = (
        db.query(models.User)
        .filter(models.User.email == settings.bootstrap_admin_email.strip().lower())
        .first()
    )
    if not admin:
        admin = models.User(
            company_id=company.id,
            full_name="Administrador Inicial",
            email=settings.bootstrap_admin_email.strip().lower(),
            password_hash=hash_password(settings.bootstrap_admin_password),
            role="ADMIN",
            is_active=True,
        )
        db.add(admin)

    template = (
        db.query(models.MessageTemplate)
        .filter(models.MessageTemplate.company_id == company.id, models.MessageTemplate.is_active.is_(True))
        .first()
    )
    if not template:
        db.add(
            models.MessageTemplate(
                company_id=company.id,
                name="Padrão",
                subject_template="Atualização de cobrança",
                body_template=(
                    "Olá, {{nome_cliente}}.\n\n"
                    "Identificamos a cobrança {{tipo_cobranca}} referente ao título {{nosso_numero}}.\n"
                    "Valor: {{valor_fatura}}\n"
                    "Saldo: {{saldo_fatura}}\n"
                    "Vencimento: {{data_vencimento}}\n\n"
                    "Empresa: {{nome_empresa}}"
                ),
                is_active=True,
            )
        )
    db.commit()