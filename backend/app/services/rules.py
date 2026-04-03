from datetime import date, datetime


def should_send_today(today: date, due_date: date, last_sent: datetime | None) -> bool:
    if last_sent and last_sent.date() == today:
        return False

    days = (due_date - today).days
    if days > 30:
        return False
    if 8 <= days <= 30:
        if last_sent is None:
            return True
        return (today - last_sent.date()).days >= 7
    if last_sent is None:
        return True
    return (today - last_sent.date()).days >= 1


def days_late(today: date, due_date: date) -> int:
    return max(0, (today - due_date).days)