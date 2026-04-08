import re

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from ..auth import authenticate_user, create_access_token, hash_password
from ..dependencies import get_current_user, get_db
from ..models import AuditLog, Company, MessageTemplate, RoleEnum, User
from ..schemas import CompanySignupRequest, LoginRequest, TokenResponse, UserMe

router = APIRouter(prefix="/auth", tags=["auth"])


def _slugify(value: str) -> str:
    value = (value or "").strip().lower()
    value = re.sub(r"[^a-z0-9]+", "-", value)
    value = re.sub(r"-+", "-", value).strip("-")
    return value[:120] or "empresa"


def _build_unique_company_slug(db: Session, company_name: str) -> str:
    base_slug = _slugify(company_name)
    slug = base_slug
    counter = 2

    while db.execute(select(Company).where(Company.slug == slug)).scalar_one_or_none():
        slug = f"{base_slug}-{counter}"
        counter += 1

    return slug


@router.post("/login", response_model=TokenResponse)
def login(payload: LoginRequest, db: Session = Depends(get_db)):
    user = authenticate_user(db, payload.email, payload.password)
    if not user:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="E-mail ou senha inválidos.")

    token = create_access_token(user)
    return TokenResponse(
        access_token=token,
        user=UserMe(
            id=user.id,
            company_id=user.company_id,
            email=user.email,
            full_name=user.full_name,
            role=user.role.value,
            is_active=user.is_active,
        ),
    )


@router.post("/register-company", response_model=TokenResponse, status_code=201)
def register_company(payload: CompanySignupRequest, db: Session = Depends(get_db)):
    company_name = payload.company_name.strip()
    admin_full_name = payload.admin_full_name.strip()
    admin_email = payload.admin_email.lower().strip()
    admin_password = payload.admin_password
    admin_password_confirm = payload.admin_password_confirm
    website = (payload.website or "").strip()

    if website:
        raise HTTPException(status_code=400, detail="Cadastro inválido.")

    if not payload.terms_accepted:
        raise HTTPException(status_code=400, detail="Você precisa aceitar os termos para continuar.")

    if admin_password != admin_password_confirm:
        raise HTTPException(status_code=400, detail="As senhas não conferem.")

    if len(admin_password) < 8:
        raise HTTPException(status_code=400, detail="A senha precisa ter pelo menos 8 caracteres.")

    existing_user = db.execute(select(User).where(User.email == admin_email)).scalar_one_or_none()
    if existing_user:
        raise HTTPException(status_code=409, detail="Este e-mail já está em uso.")

    slug = _build_unique_company_slug(db, company_name)

    company = Company(
        slug=slug,
        legal_name=company_name,
        trade_name=company_name,
        is_active=True,
    )
    db.add(company)
    db.flush()

    user = User(
        company_id=company.id,
        email=admin_email,
        full_name=admin_full_name,
        password_hash=hash_password(admin_password),
        role=RoleEnum.ADMIN,
        is_active=True,
    )
    db.add(user)
    db.flush()

    db.add(
        MessageTemplate(
            company_id=company.id,
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
    )

    db.add(
        AuditLog(
            company_id=company.id,
            user_id=user.id,
            entity="company",
            entity_id=str(company.id),
            action="public_company_signup",
            details={
                "company_name": company.trade_name,
                "admin_email": user.email,
            },
        )
    )

    db.commit()
    db.refresh(user)

    token = create_access_token(user)
    return TokenResponse(
        access_token=token,
        user=UserMe(
            id=user.id,
            company_id=user.company_id,
            email=user.email,
            full_name=user.full_name,
            role=user.role.value,
            is_active=user.is_active,
        ),
    )


@router.get("/me", response_model=UserMe)
def me(current_user=Depends(get_current_user)):
    return UserMe(
        id=current_user.id,
        company_id=current_user.company_id,
        email=current_user.email,
        full_name=current_user.full_name,
        role=current_user.role.value,
        is_active=current_user.is_active,
    )