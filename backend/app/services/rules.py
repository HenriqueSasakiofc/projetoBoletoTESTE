from __future__ import annotations

from datetime import date, datetime


def days_overdue(due_date: date | None, today: date | None = None) -> int:
    if not due_date:
        return 0
    today = today or date.today()
    delta = (today - due_date).days
    return max(delta, 0)


def should_send_today(
    due_date: date | None,
    last_sent_at: datetime | None,
    today: date | None = None,
) -> bool:
    """
    Regra:
    - mais de 30 dias antes: não envia
    - entre 30 e 8 dias antes: semanal
    - 7 dias ou menos antes: diário
    - após vencimento: diário
    - nunca 2x no mesmo dia
    """
    if not due_date:
        return False

    today = today or date.today()

    if last_sent_at and last_sent_at.date() == today:
        return False

    days_until_due = (due_date - today).days

    if days_until_due > 30:
        return False

    if 8 <= days_until_due <= 30:
        if not last_sent_at:
            return True
        return (today - last_sent_at.date()).days >= 7

    return True