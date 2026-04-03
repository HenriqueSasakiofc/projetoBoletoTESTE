from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session

from .. import schemas
from ..auth import authenticate_user, create_access_token
from ..dependencies import get_current_user, get_db

router = APIRouter(prefix="/auth", tags=["auth"])


@router.post("/login", response_model=schemas.TokenOut)
def login(payload: schemas.LoginIn, db: Session = Depends(get_db)):
    user = authenticate_user(db, payload.email, payload.password)
    if not user:
        raise HTTPException(status_code=401, detail="Credenciais inválidas")
    token = create_access_token(subject=str(user.id), company_id=user.company_id, role=user.role)
    return {
        "access_token": token,
        "token_type": "bearer",
        "role": user.role,
        "company_id": user.company_id,
        "user_name": user.full_name,
    }


@router.get("/me", response_model=schemas.MeOut)
def me(user=Depends(get_current_user)):
    return {
        "id": user.id,
        "full_name": user.full_name,
        "email": user.email,
        "role": user.role,
        "company_id": user.company_id,
    }