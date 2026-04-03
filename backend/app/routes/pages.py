from pathlib import Path

from fastapi import APIRouter
from fastapi.responses import FileResponse

router = APIRouter(tags=["pages"])
BASE_DIR = Path(__file__).resolve().parent.parent
STATIC_DIR = BASE_DIR / "static"


@router.get("/")
def home_page():
    return FileResponse(STATIC_DIR / "index.html")


@router.get("/clientes")
def clients_page():
    return FileResponse(STATIC_DIR / "clients.html")


@router.get("/cliente")
def client_page():
    return FileResponse(STATIC_DIR / "client.html")


@router.get("/pendencias")
def pendings_page():
    return FileResponse(STATIC_DIR / "pendencias.html")