import os
from dataclasses import dataclass


def _get_bool(name: str, default: str = "false") -> bool:
    return os.getenv(name, default).strip().lower() in {"1", "true", "yes", "y", "on"}


@dataclass(frozen=True)
class Settings:
    app_name: str = os.getenv("APP_NAME", "Projeto Boleto")
    app_secret_key: str = os.getenv("APP_SECRET_KEY", "troque-esta-chave-em-producao")
    jwt_algorithm: str = os.getenv("JWT_ALGORITHM", "HS256")
    access_token_minutes: int = int(os.getenv("ACCESS_TOKEN_MINUTES", "120"))

    database_url: str = os.getenv(
        "DATABASE_URL",
        "postgresql+psycopg2://postgres:postgres@localhost:5432/projeto_boleto",
    )

    smtp_host: str = os.getenv("SMTP_HOST", "")
    smtp_port: int = int(os.getenv("SMTP_PORT", "587"))
    smtp_user: str = os.getenv("SMTP_USER", "")
    smtp_pass: str = os.getenv("SMTP_PASS", "")
    smtp_tls: bool = _get_bool("SMTP_TLS", "true")
    mail_from: str = os.getenv("MAIL_FROM", "")
    safe_mode: bool = _get_bool("SAFE_MODE", "true")
    test_email: str = os.getenv("TEST_EMAIL", "")

    upload_max_bytes: int = int(os.getenv("UPLOAD_MAX_BYTES", str(8 * 1024 * 1024)))
    staging_retention_days: int = int(os.getenv("STAGING_RETENTION_DAYS", "45"))
    old_data_inactivation_days: int = int(os.getenv("OLD_DATA_INACTIVATION_DAYS", "120"))

    bootstrap_company_name: str = os.getenv("BOOTSTRAP_COMPANY_NAME", "Empresa Inicial")
    bootstrap_company_slug: str = os.getenv("BOOTSTRAP_COMPANY_SLUG", "empresa-inicial")
    bootstrap_admin_email: str = os.getenv("BOOTSTRAP_ADMIN_EMAIL", "admin@empresa.local")
    bootstrap_admin_password: str = os.getenv("BOOTSTRAP_ADMIN_PASSWORD", "Troque123!")


settings = Settings()