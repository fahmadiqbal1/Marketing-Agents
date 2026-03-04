"""
Text Scanner — screens AI-generated captions and user-supplied text
for toxicity, healthcare compliance violations, and brand-safety issues.

Uses OpenAI's **free** Moderation endpoint (no token cost) for toxicity,
plus custom regex / keyword checks for healthcare-specific compliance.

Usage:
    from security.text_scanner import TextScanner

    scanner = TextScanner()
    result  = await scanner.full_scan(text, context="caption")
"""

from __future__ import annotations

import logging
import re
from dataclasses import dataclass, field
from typing import Optional

from openai import AsyncOpenAI

from config.settings import get_settings

logger = logging.getLogger(__name__)


# ── Result ────────────────────────────────────────────────────────────────────


@dataclass
class TextScanResult:
    """Outcome of a text safety scan."""

    is_safe: bool = True
    toxicity_flagged: bool = False
    compliance_flagged: bool = False
    brand_safety_flagged: bool = False
    issues: list[str] = field(default_factory=list)
    moderation_categories: dict = field(default_factory=dict)   # OpenAI results
    details: str = ""


# ── Healthcare compliance patterns ────────────────────────────────────────────

# Claims that require medical evidence / FDA approval
MEDICAL_CLAIM_PATTERNS: list[re.Pattern] = [
    re.compile(r"\bcures?\b", re.I),
    re.compile(r"\bguaranteed?\s+(results?|cure|healing)\b", re.I),
    re.compile(r"\b100\s*%\s*(effective|safe|cure|success)\b", re.I),
    re.compile(r"\bno\s+side\s+effects?\b", re.I),
    re.compile(r"\bmiracle\s+(cure|treatment|solution)\b", re.I),
    re.compile(r"\bFDA[\s-]?approved\b", re.I),
    re.compile(r"\bclinically\s+proven\b", re.I),
    re.compile(r"\bscientifically\s+proven\b", re.I),
    re.compile(r"\bpermanent\s+(cure|solution|fix)\b", re.I),
    re.compile(r"\brisk[\s-]?free\b", re.I),
    re.compile(r"\binstant\s+(results?|cure|healing|recovery)\b", re.I),
    re.compile(r"\bdiagnos(e|es|ing)\b.*\byourself\b", re.I),
]

# Before/after claims without disclaimers
BEFORE_AFTER_PATTERNS: list[re.Pattern] = [
    re.compile(r"\bbefore\s+and\s+after\b", re.I),
    re.compile(r"\btransformation\s+(results?|journey)\b", re.I),
    re.compile(r"\bamazing\s+results?\b", re.I),
]

# Required disclaimers for certain types of content
DISCLAIMER_TRIGGERS: dict[str, str] = {
    "before_after": "Individual results may vary.",
    "medical_advice": "This is not medical advice. Consult your doctor.",
    "pricing": "Prices are subject to change. Contact us for current pricing.",
}

# Brand-safety: words/phrases that should never appear in business content
BANNED_WORDS: list[re.Pattern] = [
    re.compile(r"\b(fuck|shit|damn|ass|bitch|bastard|crap|hell)\b", re.I),
    re.compile(r"\b(dumbass|moron|idiot|stupid|retard)\b", re.I),
    re.compile(r"\b(sexy|seductive|erotic|nude|naked)\b", re.I),
    re.compile(r"\b(kill|murder|suicide|self[\s-]?harm)\b", re.I),
    re.compile(r"\bscam\b", re.I),
    re.compile(r"\b(fake|fraud|hoax)\b", re.I),
    # Competitor mentions
    re.compile(r"\b(competitor\s+name|other\s+clinic\s+name)\b", re.I),
]


class TextScanner:
    """Scan text for toxicity, healthcare compliance, and brand safety."""

    def __init__(self):
        self._openai: Optional[AsyncOpenAI] = None

    def _get_client(self) -> AsyncOpenAI:
        if self._openai is None:
            settings = get_settings()
            self._openai = AsyncOpenAI(api_key=settings.openai_api_key)
        return self._openai

    # ── public entry point ────────────────────────────────────────────────

    async def full_scan(
        self,
        text: str,
        context: str = "caption",
    ) -> TextScanResult:
        """
        Run all three checks on *text* and merge results.

        Args:
            text:    The text to scan (caption, description, job posting, etc.)
            context: One of "caption", "job_posting", "comment_reply", "description"
        """
        result = TextScanResult()

        if not text or not text.strip():
            return result   # empty text is fine

        # 1. Toxicity (OpenAI Moderation — free)
        tox = await self._check_toxicity(text)
        if tox:
            result.toxicity_flagged = True
            result.moderation_categories = tox["categories"]
            result.issues.append(f"Toxicity flagged: {', '.join(tox['flagged'])}")

        # 2. Healthcare compliance
        compliance_issues = self._check_healthcare_compliance(text, context)
        if compliance_issues:
            result.compliance_flagged = True
            result.issues.extend(compliance_issues)

        # 3. Brand safety
        brand_issues = self._check_brand_safety(text)
        if brand_issues:
            result.brand_safety_flagged = True
            result.issues.extend(brand_issues)

        result.is_safe = not (
            result.toxicity_flagged
            or result.compliance_flagged
            or result.brand_safety_flagged
        )
        result.details = "; ".join(result.issues) if result.issues else "All checks passed"
        return result

    # ── toxicity (OpenAI Moderation — free tier) ──────────────────────────

    async def _check_toxicity(self, text: str) -> Optional[dict]:
        """
        Call OpenAI's Moderation endpoint (free, no tokens consumed).
        Returns None if safe, or a dict with flagged categories.
        """
        try:
            client = self._get_client()
            response = await client.moderations.create(
                model="omni-moderation-latest",
                input=text,
            )
            result = response.results[0]
            if result.flagged:
                flagged_cats = [
                    cat for cat, val in result.categories.model_dump().items()
                    if val is True
                ]
                return {
                    "flagged": flagged_cats,
                    "categories": result.category_scores.model_dump(),
                }
            return None
        except Exception as e:
            logger.error("OpenAI moderation call failed — FAIL-CLOSED: %s", e)
            # Fail-closed: treat as toxic when scan is unavailable
            return {
                "flagged": ["scan_unavailable"],
                "categories": {"error": str(e)[:100]},
            }

    # ── healthcare compliance ─────────────────────────────────────────────

    def _check_healthcare_compliance(
        self,
        text: str,
        context: str,
    ) -> list[str]:
        """Check for medical claims that could violate ad regulations."""
        issues: list[str] = []

        for pattern in MEDICAL_CLAIM_PATTERNS:
            match = pattern.search(text)
            if match:
                issues.append(
                    f"Potential medical claim: '{match.group()}' — "
                    "may require evidence or disclaimer"
                )

        # Check before/after claims
        for pattern in BEFORE_AFTER_PATTERNS:
            match = pattern.search(text)
            if match:
                disclaimer = DISCLAIMER_TRIGGERS["before_after"]
                if disclaimer.lower() not in text.lower():
                    issues.append(
                        f"Before/after claim detected ('{match.group()}') "
                        f"without disclaimer: '{disclaimer}'"
                    )

        return issues

    # ── brand safety ──────────────────────────────────────────────────────

    def _check_brand_safety(self, text: str) -> list[str]:
        """Ensure content is appropriate for the business brand."""
        issues: list[str] = []

        for pattern in BANNED_WORDS:
            match = pattern.search(text)
            if match:
                issues.append(f"Banned word/phrase detected: '{match.group()}'")

        return issues
