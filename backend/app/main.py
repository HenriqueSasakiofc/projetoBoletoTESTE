from pathlib import Path

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles

from .auth import bootstrap_initial_data
from .config import settings
from .db import Base, SessionLocal, engine
from .routes import auth, clients, imports, messages, pages

Base.metadata.create_all(bind=engine)

app = FastAPI(title=settings.app_name)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

BASE_DIR = Path(__file__).resolve().parent
app.mount("/static", StaticFiles(directory=BASE_DIR / "static"), name="static")

app.include_router(pages.router)
app.include_router(auth.router)
app.include_router(imports.router)
app.include_router(clients.router)
app.include_router(messages.router)


@app.on_event("startup")
def on_startup():
    db = SessionLocal()
    try:
        bootstrap_initial_data(db)
    finally:
        db.close()


@app.get("/health")
def health():
    return {"ok": True, "app": settings.app_name}