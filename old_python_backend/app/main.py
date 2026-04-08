from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles
from sqlalchemy import select

from .auth import hash_password
from .config import settings
from .db import Base, SessionLocal, engine
from .models import Company, MessageTemplate, RoleEnum, User
from .routes.auth import router as auth_router
from .routes.clients import router as clients_router
from .routes.imports import router as imports_router
from .routes.messages import router as messages_router
from .routes.pages import router as pages_router

app = FastAPI(title=settings.APP_NAME)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


def seed_initial_data():
    db = SessionLocal()
    try:
        company = db.execute(select(Company).where(Company.slug == "empresa-padrao")).scalar_one_or_none()
        if not company:
            company = Company(
                slug="empresa-padrao",
                legal_name=settings.DEFAULT_COMPANY_NAME,
                trade_name=settings.DEFAULT_COMPANY_NAME,
            )
            db.add(company)
            db.flush()

        user = db.execute(select(User).where(User.email == settings.ADMIN_EMAIL.lower().strip())).scalar_one_or_none()
        if not user:
            user = User(
                company_id=company.id,
                email=settings.ADMIN_EMAIL.lower().strip(),
                full_name="Administrador",
                password_hash=hash_password(settings.ADMIN_PASSWORD),
                role=RoleEnum.ADMIN,
                is_active=True,
            )
            db.add(user)

        template = db.execute(select(MessageTemplate).where(MessageTemplate.company_id == company.id)).scalar_one_or_none()
        if not template:
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

        db.commit()
    finally:
        db.close()


@app.on_event("startup")
def on_startup():
    Base.metadata.create_all(bind=engine)
    seed_initial_data()


app.mount("/static", StaticFiles(directory=str(settings.STATIC_DIR)), name="static")

app.include_router(auth_router)
app.include_router(imports_router)
app.include_router(clients_router)
app.include_router(messages_router)
app.include_router(pages_router)


@app.get("/health")
def health():
    return {"status": "ok", "app": settings.APP_NAME}