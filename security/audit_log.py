"""
Audit Logger — centralized, structured logging for all security-relevant
events in the marketing system.

All events are written to both:
  1. The ``audit_log`` MySQL table (queryable, persistent)
  2. The Python logger (stdout / file)

Usage:
    from security.audit_log import audit, AuditEvent, Severity

    await audit(
        event=AuditEvent.FILE_UPLOAD,
        severity=Severity.INFO,
        actor="telegram_bot",
        details={"filename": "photo.jpg", "result": "passed"},
    )
"""

from __future__ import annotations

import enum
import json
import logging
from datetime import datetime
from typing import Any, Optional

logger = logging.getLogger("security.audit")


class AuditEvent(str, enum.Enum):
    """Categorised security events."""

    # File security
    FILE_UPLOAD = "file_upload"
    FILE_REJECTED = "file_rejected"
    MALWARE_DETECTED = "malware_detected"
    EXIF_STRIPPED = "exif_stripped"

    # Content scanning
    NSFW_SCAN = "nsfw_scan"
    NSFW_BLOCKED = "nsfw_blocked"
    TOXICITY_SCAN = "toxicity_scan"
    TOXICITY_BLOCKED = "toxicity_blocked"
    COMPLIANCE_FLAG = "compliance_flag"

    # Prompt injection
    PROMPT_INJECTION = "prompt_injection_detected"

    # PII
    PII_REDACTED = "pii_redacted"

    # Publishing
    PUBLISH_GATE_PASS = "publish_gate_pass"
    PUBLISH_GATE_BLOCK = "publish_gate_block"
    POST_PUBLISHED = "post_published"
    POST_APPROVED = "post_approved"
    POST_DENIED = "post_denied"

    # Auth
    AUTH_SUCCESS = "auth_success"
    AUTH_FAILURE = "auth_failure"
    RATE_LIMITED = "rate_limited"

    # System
    QUARANTINE = "file_quarantined"
    AGENT_ERROR = "agent_error"
    CREDIT_REQUEST = "credit_request"


class Severity(str, enum.Enum):
    INFO = "info"
    WARNING = "warning"
    HIGH = "high"
    CRITICAL = "critical"


async def audit(
    event: AuditEvent,
    severity: Severity,
    actor: str = "system",
    details: dict[str, Any] | None = None,
    related_id: int | None = None,
) -> None:
    """
    Record a security event.

    Writes to the database (best-effort) and always logs.

    Args:
        event:      The event type.
        severity:   How serious this is.
        actor:      Who/what triggered it (e.g. "telegram_bot", "api", "workflow").
        details:    Arbitrary JSON-serialisable detail dict.
        related_id: Optional FK to a media_item or post id.
    """
    details_json = json.dumps(details or {}, default=str)

    # Always log to Python logger
    log_line = (
        f"[AUDIT] {severity.value.upper()} | {event.value} | "
        f"actor={actor} | details={details_json}"
    )
    _log_at_level(severity, log_line)

    # Write to database (best-effort — never crash the caller)
    try:
        from memory.database import get_session_factory
        from sqlalchemy import text

        session_factory = get_session_factory()
        async with session_factory() as session:
            await session.execute(
                text(
                    "INSERT INTO audit_log "
                    "(event_type, severity, actor, details_json, related_id, created_at) "
                    "VALUES (:event, :sev, :actor, :details, :rid, :now)"
                ),
                {
                    "event": event.value,
                    "sev": severity.value,
                    "actor": actor,
                    "details": details_json,
                    "rid": related_id,
                    "now": datetime.utcnow(),
                },
            )
            await session.commit()
    except Exception as e:
        logger.error("Failed to write audit log to DB: %s", e)


async def send_critical_alert(
    event: AuditEvent,
    message: str,
) -> None:
    """
    Send an immediate Telegram alert to the admin for critical events.

    This is fire-and-forget — failures are logged but never propagated.
    """
    try:
        from config.settings import get_settings
        import httpx

        settings = get_settings()
        if not settings.telegram_bot_token or not settings.telegram_admin_chat_id:
            return

        alert_text = (
            f"🚨 *SECURITY ALERT*\n\n"
            f"*Event:* {event.value}\n"
            f"*Details:* {message}\n"
            f"*Time:* {datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S UTC')}"
        )

        async with httpx.AsyncClient() as client:
            await client.post(
                f"https://api.telegram.org/bot{settings.telegram_bot_token}/sendMessage",
                json={
                    "chat_id": settings.telegram_admin_chat_id,
                    "text": alert_text,
                    "parse_mode": "Markdown",
                },
                timeout=10,
            )
    except Exception as e:
        logger.error("Failed to send critical alert via Telegram: %s", e)


def _log_at_level(severity: Severity, message: str) -> None:
    """Map severity to Python log level."""
    if severity == Severity.CRITICAL:
        logger.critical(message)
    elif severity == Severity.HIGH:
        logger.error(message)
    elif severity == Severity.WARNING:
        logger.warning(message)
    else:
        logger.info(message)
