from functools import lru_cache
from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    APP_NAME: str = "Projeto Boleto"
    DATABASE_URL: str = "sqlite:///./boleto.db"
    SECRET_KEY: str = "change-me"
    ALGORITHM: str = "HS256"
    ACCESS_TOKEN_EXPIRE_MINUTES: int = 60 * 24

    SAFE_MODE: bool = True
    TEST_EMAIL: str = "teste@example.com"

    SMTP_HOST: str = "localhost"
    SMTP_PORT: int = 587
    SMTP_USERNAME: str = ""
    SMTP_PASSWORD: str = ""
    SMTP_FROM_NAME: str = "Projeto Boleto"
    SMTP_FROM_EMAIL: str = "nao-responder@example.com"
    SMTP_USE_TLS: bool = True

    DEFAULT_COMPANY_NAME: str = "Empresa Padrão"
    ADMIN_EMAIL: str = "admin@example.com"
    ADMIN_PASSWORD: str = "123456"
    MAX_UPLOAD_SIZE_MB: int = 10

    BASE_DIR: Path = Path(__file__).resolve().parent
    STATIC_DIR: Path = BASE_DIR / "static"


@lru_cache
def get_settings() -> Settings:
    return Settings()


settings = get_settings()