"""
Credential encryption utility — Fernet symmetric encryption for tokens at rest.

Uses a master key from ENCRYPTION_KEY env var. If not set, generates one
and logs a critical warning (unsafe for production).
"""

from __future__ import annotations

import base64
import hashlib
import logging
import os

from cryptography.fernet import Fernet, InvalidToken

logger = logging.getLogger(__name__)

_fernet: Fernet | None = None


def _get_fernet() -> Fernet:
    """Lazy-init a Fernet instance from ENCRYPTION_KEY env var."""
    global _fernet
    if _fernet is not None:
        return _fernet

    raw_key = os.getenv("ENCRYPTION_KEY", "")
    if not raw_key:
        # Derive a deterministic (but weak) key from a fixed seed — dev only
        logger.critical(
            "ENCRYPTION_KEY not set! Using insecure fallback. "
            "Set ENCRYPTION_KEY in your .env for production."
        )
        raw_key = "INSECURE-DEV-KEY-CHANGE-ME"

    # Derive a valid 32-byte Fernet key from whatever string the user provides
    digest = hashlib.sha256(raw_key.encode()).digest()
    fernet_key = base64.urlsafe_b64encode(digest)
    _fernet = Fernet(fernet_key)
    return _fernet


def encrypt(plaintext: str) -> str:
    """Encrypt a string and return a URL-safe base64-encoded ciphertext."""
    if not plaintext:
        return ""
    return _get_fernet().encrypt(plaintext.encode()).decode()


def decrypt(ciphertext: str) -> str:
    """Decrypt a URL-safe base64-encoded ciphertext back to plaintext."""
    if not ciphertext:
        return ""
    try:
        return _get_fernet().decrypt(ciphertext.encode()).decode()
    except InvalidToken:
        logger.error("Failed to decrypt credential — wrong ENCRYPTION_KEY?")
        return ""


def generate_encryption_key() -> str:
    """Generate a fresh Fernet-compatible key for .env."""
    return Fernet.generate_key().decode()
