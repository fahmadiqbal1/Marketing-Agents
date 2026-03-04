"""
API Authentication — simple API-key gate for the FastAPI layer.

The key is stored in `config/.env` as ``API_SECRET_KEY``.  Every request
must include it in the ``X-API-Key`` header.

Usage (in FastAPI):
    from security.api_auth import require_api_key

    @app.get("/api/something", dependencies=[Depends(require_api_key)])
    async def something(): ...
"""

from __future__ import annotations

import hashlib
import hmac
import logging
import secrets

from fastapi import Depends, HTTPException, Request, Security, status
from fastapi.security import APIKeyHeader

from config.settings import get_settings

logger = logging.getLogger(__name__)

_API_KEY_HEADER = APIKeyHeader(name="X-API-Key", auto_error=False)


async def require_api_key(
    api_key: str | None = Security(_API_KEY_HEADER),
) -> str:
    """
    FastAPI dependency that enforces the API key.

    Raises 401 if the key is missing or incorrect.
    """
    settings = get_settings()
    expected = settings.api_secret_key

    if not expected:
        # If no key is configured, allow access (first-run / dev mode)
        logger.warning(
            "API_SECRET_KEY is not set — all requests are allowed. "
            "Set it in config/.env for production."
        )
        return "no-key-configured"

    if not api_key:
        logger.warning("Missing API key in request")
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Missing API key. Include X-API-Key header.",
        )

    # Constant-time comparison to prevent timing attacks
    if not hmac.compare_digest(api_key, expected):
        logger.warning("Invalid API key attempt")
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid API key.",
        )

    return api_key


async def require_api_key_optional(
    api_key: str | None = Security(_API_KEY_HEADER),
) -> str | None:
    """Non-raising variant — returns the key if valid, None otherwise.

    Used by the dual-auth dependency (JWT-or-API-key) in api/main.py.
    """
    settings = get_settings()
    expected = settings.api_secret_key

    if not expected:
        return "no-key"  # dev mode — accept anything

    if not api_key:
        return None

    if hmac.compare_digest(api_key, expected):
        return api_key

    return None


def generate_api_key() -> str:
    """Generate a cryptographically secure random API key (64 hex chars)."""
    return secrets.token_hex(32)
