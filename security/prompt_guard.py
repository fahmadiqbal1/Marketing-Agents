"""
Prompt Guard — protects AI agents from prompt injection attacks.

Covers two vectors:
1. **User-supplied text** — sanitised before being interpolated into LLM
   prompts (Telegram messages, filenames, job-posting fields, etc.).
2. **Vision output** — the JSON returned by Gemini is validated so that
   injected "instructions" embedded in an image can't hijack downstream
   agents.

Usage:
    from security.prompt_guard import sanitize_for_llm, validate_vision_output
"""

from __future__ import annotations

import json
import logging
import re
from typing import Any

logger = logging.getLogger(__name__)


# ── Known injection patterns ─────────────────────────────────────────────────

# Phrases commonly used to escape prompt boundaries
_INJECTION_PATTERNS: list[re.Pattern] = [
    # Role-switching attempts
    re.compile(r"\b(ignore|forget|disregard)\b.{0,40}\b(previous|above|prior|all)\b.{0,30}\b(instructions?|prompts?|rules?|context)\b", re.I),
    re.compile(r"\byou\s+are\s+now\b", re.I),
    re.compile(r"\bact\s+as\b", re.I),
    re.compile(r"\bpretend\s+(you\s+are|to\s+be)\b", re.I),
    re.compile(r"\bnew\s+(instructions?|rules?|role)\b", re.I),
    # System-prompt leaks
    re.compile(r"\b(system|assistant)\s*:", re.I),
    re.compile(r"\[INST\]|\[/INST\]|<\|im_start\|>|<\|im_end\|>", re.I),
    re.compile(r"```\s*(system|instruction)", re.I),
    # Delimiter abuse
    re.compile(r"[-=]{5,}"),                       # ===== / ----- dividers
    re.compile(r"#{3,}\s*(system|instruction)", re.I),
    # Prompt-in-prompt tricks
    re.compile(r"\bdo\s+not\s+follow\b", re.I),
    re.compile(r"\boverride\b.{0,20}\b(safety|filter|restriction|rule)\b", re.I),
    re.compile(r"\bjailbreak\b", re.I),
    re.compile(r"\bDAN\s+mode\b", re.I),
]

# Unicode control characters that can confuse tokenisers
_CONTROL_CHAR_RE = re.compile(
    r"[\u200b\u200c\u200d\u200e\u200f"       # zero-width chars
    r"\u202a-\u202e"                          # bidi overrides
    r"\ufeff\ufffe"                            # BOM / non-char
    r"\u0000-\u0008\u000e-\u001f]"            # C0 controls (except \t \n \r)
)


def sanitize_for_llm(text: str, max_length: int = 5000) -> str:
    """
    Clean user-supplied text before it enters an LLM prompt.

    - Strips Unicode control characters
    - Removes known injection patterns (replaced with ``[FILTERED]``)
    - Truncates to *max_length*
    - Returns the cleaned string (never raises)
    """
    if not text:
        return ""

    # 1. Remove invisible / control chars
    cleaned = _CONTROL_CHAR_RE.sub("", text)

    # 2. Replace known injection patterns
    for pattern in _INJECTION_PATTERNS:
        if pattern.search(cleaned):
            cleaned = pattern.sub("[FILTERED]", cleaned)
            logger.warning(
                "Prompt injection pattern detected and filtered: %s",
                pattern.pattern[:60],
            )

    # 3. Truncate
    if len(cleaned) > max_length:
        cleaned = cleaned[:max_length] + "…"

    return cleaned


def validate_vision_output(
    raw_json: dict[str, Any],
    expected_fields: set[str] | None = None,
) -> dict[str, Any]:
    """
    Validate the JSON returned by the vision model (Gemini).

    Checks:
    - Only expected top-level keys are kept.
    - String values are scanned for injection patterns.
    - Unexpected nested dicts / deeply nested structures are rejected.
    """
    if expected_fields is None:
        expected_fields = {
            "content_type", "content_category", "mood", "quality_score",
            "description", "suggested_platforms", "improvement_tips",
            "people_detected", "text_detected", "is_before_after",
            "healthcare_services", "safety_assessment",
        }

    safe: dict[str, Any] = {}

    for key, value in raw_json.items():
        if key not in expected_fields:
            logger.warning("Unexpected field '%s' in vision output — dropped", key)
            continue

        if isinstance(value, str):
            # Scan string values for injection patterns
            has_injection = any(p.search(value) for p in _INJECTION_PATTERNS)
            if has_injection:
                logger.warning(
                    "Injection pattern found in vision field '%s' — scrubbed", key
                )
                value = sanitize_for_llm(value)
            safe[key] = value

        elif isinstance(value, (int, float, bool)):
            safe[key] = value

        elif isinstance(value, list):
            # Allow lists of simple types only
            safe[key] = [
                sanitize_for_llm(str(item)) if isinstance(item, str) else item
                for item in value
                if isinstance(item, (str, int, float, bool))
            ]

        else:
            # dict or complex nested — keep but sanitise string leaves
            safe[key] = _deep_sanitize(value)

    return safe


def escape_for_ffmpeg(text: str) -> str:
    """
    Escape a string for safe interpolation into FFmpeg filter expressions.

    FFmpeg's ``drawtext`` filter uses ``:``, ``'``, ``\\``, ``[`` and ``]``
    as special characters; they must be escaped with a backslash.
    """
    # Order matters — escape backslash first
    for ch in ("\\", "'", ":", "[", "]", ";"):
        text = text.replace(ch, f"\\{ch}")
    return text


# ── helpers ───────────────────────────────────────────────────────────────────

def _deep_sanitize(obj: Any, depth: int = 0) -> Any:
    """Recursively sanitize nested structures (max depth 3)."""
    if depth > 3:
        return "[TRUNCATED]"

    if isinstance(obj, str):
        return sanitize_for_llm(obj)
    elif isinstance(obj, dict):
        return {
            sanitize_for_llm(str(k)): _deep_sanitize(v, depth + 1)
            for k, v in obj.items()
        }
    elif isinstance(obj, list):
        return [_deep_sanitize(item, depth + 1) for item in obj]
    elif isinstance(obj, (int, float, bool, type(None))):
        return obj
    else:
        return str(obj)
