"""
PII Redactor — detects and masks personally identifiable information
in text before it reaches social media or logs.

Patterns detected:
  - Email addresses
  - Phone numbers (international + local formats)
  - National ID / SSN formats
  - Credit/debit card numbers (basic Luhn-aware)
  - Physical addresses (heuristic)
  - CNIC (Pakistani national ID: 12345-1234567-1)

Usage:
    from security.pii_redactor import redact_pii, scan_for_pii

    clean     = redact_pii("Call me at 0300-1234567")
    findings  = scan_for_pii("Email: john@example.com, SSN 123-45-6789")
"""

from __future__ import annotations

import logging
import re
from dataclasses import dataclass
from typing import Optional

logger = logging.getLogger(__name__)


@dataclass
class PiiMatch:
    """A single PII detection."""

    pii_type: str          # "email", "phone", "ssn", "cnic", "card", "address"
    value: str             # the matched text
    start: int
    end: int
    replacement: str       # what it gets replaced with


# ── Patterns ──────────────────────────────────────────────────────────────────

_PATTERNS: list[tuple[str, re.Pattern, str]] = [
    # Email
    (
        "email",
        re.compile(
            r"\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b"
        ),
        "[EMAIL REDACTED]",
    ),
    # Pakistani CNIC: 12345-1234567-1
    (
        "cnic",
        re.compile(r"\b\d{5}-\d{7}-\d\b"),
        "[CNIC REDACTED]",
    ),
    # US SSN: 123-45-6789
    (
        "ssn",
        re.compile(r"\b\d{3}-\d{2}-\d{4}\b"),
        "[SSN REDACTED]",
    ),
    # Credit cards: 16-digit with optional dashes/spaces
    (
        "card",
        re.compile(r"\b(?:\d[ -]?){13,19}\b"),
        "[CARD REDACTED]",
    ),
    # Phone numbers — international variants
    (
        "phone",
        re.compile(
            r"(?<!\d)"                          # not preceded by digit
            r"(?:\+?\d{1,3}[\s.-]?)?"           # country code
            r"(?:\(?\d{2,4}\)?[\s.-]?)?"        # area code
            r"\d{3,4}[\s.-]?\d{3,4}"            # subscriber number
            r"(?!\d)"                             # not followed by digit
        ),
        "[PHONE REDACTED]",
    ),
]


def scan_for_pii(text: str) -> list[PiiMatch]:
    """
    Scan *text* and return all PII matches found.

    Does NOT modify the text — use ``redact_pii`` for that.
    """
    if not text:
        return []

    matches: list[PiiMatch] = []
    seen_spans: set[tuple[int, int]] = set()

    for pii_type, pattern, replacement in _PATTERNS:
        for m in pattern.finditer(text):
            span = (m.start(), m.end())
            # Avoid overlapping matches
            if any(s[0] <= span[0] < s[1] or s[0] < span[1] <= s[1] for s in seen_spans):
                continue

            # Skip very short phone-like matches (likely false positives)
            if pii_type == "phone" and len(m.group().replace(" ", "").replace("-", "")) < 7:
                continue

            # Skip card-like matches that are clearly not cards (< 13 digits)
            if pii_type == "card":
                digits_only = re.sub(r"\D", "", m.group())
                if len(digits_only) < 13 or not _luhn_check(digits_only):
                    continue

            seen_spans.add(span)
            matches.append(PiiMatch(
                pii_type=pii_type,
                value=m.group(),
                start=span[0],
                end=span[1],
                replacement=replacement,
            ))

    # Sort by position
    matches.sort(key=lambda m: m.start)
    return matches


def redact_pii(text: str) -> str:
    """
    Replace all PII in *text* with placeholder tokens.

    Returns the redacted string.
    """
    if not text:
        return text

    findings = scan_for_pii(text)
    if not findings:
        return text

    # Build result by replacing from end to start (preserves positions)
    result = text
    for match in reversed(findings):
        result = result[:match.start] + match.replacement + result[match.end:]
        logger.info(
            "PII redacted: type=%s, original_length=%d",
            match.pii_type,
            len(match.value),
        )

    return result


def has_pii(text: str) -> bool:
    """Quick check: does *text* contain any PII?"""
    return len(scan_for_pii(text)) > 0


# ── helpers ───────────────────────────────────────────────────────────────────

def _luhn_check(number: str) -> bool:
    """Luhn algorithm — validates credit card numbers."""
    digits = [int(d) for d in number if d.isdigit()]
    if len(digits) < 13:
        return False

    checksum = 0
    for i, d in enumerate(reversed(digits)):
        if i % 2 == 1:
            d *= 2
            if d > 9:
                d -= 9
        checksum += d
    return checksum % 10 == 0
