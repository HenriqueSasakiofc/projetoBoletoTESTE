from pathlib import Path

from fastapi import APIRouter
from fastapi.responses import FileResponse

from ..config import settings

router = APIRouter(tags=["pages"])


def _static_file(name: str) -> FileResponse:
    path = Path(settings.STATIC_DIR) / name
    return FileResponse(path)


@router.get("/", include_in_schema=False)
def page_index():
    return _static_file("index.html")


@router.get("/cadastro", include_in_schema=False)
def page_cadastro():
    return _static_file("cadastro.html")


@router.get("/clientes", include_in_schema=False)
def page_clientes():
    return _static_file("clientes.html")


@router.get("/importacao", include_in_schema=False)
def page_importacao():
    return _static_file("importacao.html")


@router.get("/cliente", include_in_schema=False)
def page_cliente():
    return _static_file("cliente.html")


@router.get("/pendencias", include_in_schema=False)
def page_pendencias():
    return _static_file("pendencias.html")