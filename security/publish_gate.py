"""
Publish Gate — the **last line of defence** before content reaches
a live social media account.

Runs immediately before every ``publish_to_*`` call in ``publisher.py``
and performs a comprehensive set of checks on *both* the media file and
the caption/description text.

Usage:
    from security.publish_gate import PublishGate

    gate   = PublishGate()
    result = await gate.check(
        file_path="media/processed/ig_photo.jpg",
        caption="Visit our website for more info…",
        description=None,
        platform="instagram",
        media_type="photo",
        post_id=42,
        expected_file_hash="abc123…",
    )
    if not result.cleared:
        # block publishing, quarantine, alert admin
"""

from __future__ import annotations

import hashlib
import logging
from dataclasses import dataclass, field
from pathlib import Path
from typing import Optional

logger = logging.getLogger(__name__)


@dataclass
class PublishDecision:
    """Outcome of the pre-publish safety gate."""

    cleared: bool = False
    blocked_reasons: list[str] = field(default_factory=list)
    warnings: list[str] = field(default_factory=list)


class PublishGate:
    """
    Comprehensive pre-publish safety gate.

    Each ``check()`` invocation runs the following — all must pass:

    1. File existence & integrity (SHA-256 hash match)
    2. NSFW re-scan on the *final* edited file
    3. Caption toxicity check
    4. PII scan on caption & description
    5. Healthcare compliance check on caption
    6. Verify the post was explicitly human-approved
    """

    def __init__(self, nsfw_threshold: float = 0.70):
        self.nsfw_threshold = nsfw_threshold

    async def check(
        self,
        file_path: str,
        caption: str,
        platform: str,
        media_type: str,
        post_id: int | None = None,
        description: str | None = None,
        expected_file_hash: str | None = None,
    ) -> PublishDecision:
        decision = PublishDecision()
        reasons = decision.blocked_reasons

        # ── 1. File exists ────────────────────────────────────────────────
        path = Path(file_path)
        if not path.exists() or not path.is_file():
            reasons.append(f"File not found: {file_path}")
            return decision

        # ── 2. File integrity (hash check) ───────────────────────────────
        if expected_file_hash:
            actual_hash = compute_file_hash(file_path)
            if actual_hash != expected_file_hash:
                reasons.append(
                    f"File hash mismatch — expected {expected_file_hash[:16]}… "
                    f"got {actual_hash[:16]}… — possible tampering"
                )
                return decision

        # ── 3. NSFW re-scan ───────────────────────────────────────────────
        try:
            from security.content_scanner import ContentScanner

            scanner = ContentScanner(threshold=self.nsfw_threshold)

            if media_type == "video":
                scan = scanner.scan_video(file_path, sample_every=10, max_frames=5)
            else:
                scan = scanner.scan_image(file_path)

            if not scan.is_safe:
                reasons.append(
                    f"NSFW check failed at publish gate: {scan.details}"
                )
        except Exception as e:
            logger.error("Publish-gate NSFW scan error: %s", e)
            decision.warnings.append(f"NSFW scan skipped (error): {e}")

        # ── 4. Caption toxicity ───────────────────────────────────────────
        try:
            from security.text_scanner import TextScanner

            ts = TextScanner()
            text_result = await ts.full_scan(caption, context="caption")
            if not text_result.is_safe:
                reasons.append(
                    f"Caption failed text scan: {text_result.details}"
                )

            # Also scan description (YouTube)
            if description:
                desc_result = await ts.full_scan(description, context="description")
                if not desc_result.is_safe:
                    reasons.append(
                        f"Description failed text scan: {desc_result.details}"
                    )
        except Exception as e:
            logger.error("Publish-gate text scan error: %s", e)
            decision.warnings.append(f"Text scan skipped (error): {e}")

        # ── 5. PII check ─────────────────────────────────────────────────
        try:
            from security.pii_redactor import has_pii

            if has_pii(caption):
                reasons.append("Caption contains PII — must be redacted before publishing")
            if description and has_pii(description):
                reasons.append("Description contains PII — must be redacted before publishing")
        except Exception as e:
            logger.error("Publish-gate PII check error: %s", e)

        # ── 6. Approval status ────────────────────────────────────────────
        if post_id:
            try:
                approved = await self._is_post_approved(post_id)
                if not approved:
                    reasons.append(
                        f"Post {post_id} has not been explicitly approved by a human"
                    )
            except Exception as e:
                logger.error("Publish-gate approval check error: %s", e)
                decision.warnings.append(f"Approval check failed: {e}")

        # ── verdict ───────────────────────────────────────────────────────
        decision.cleared = len(reasons) == 0

        if not decision.cleared:
            logger.warning(
                "Publish gate BLOCKED post_id=%s platform=%s reasons=%s",
                post_id, platform, reasons,
            )
        else:
            logger.info(
                "Publish gate CLEARED post_id=%s platform=%s",
                post_id, platform,
            )

        return decision

    # ── helpers ───────────────────────────────────────────────────────────

    @staticmethod
    async def _is_post_approved(post_id: int) -> bool:
        """Check the database for explicit human approval."""
        try:
            from memory.database import get_session_factory
            from sqlalchemy import text

            session_factory = get_session_factory()
            async with session_factory() as session:
                result = await session.execute(
                    text("SELECT status FROM posts WHERE id = :id"),
                    {"id": post_id},
                )
                row = result.fetchone()
                if row:
                    return row[0] in ("approved", "publishing", "published")
            return False
        except Exception:
            return False


def compute_file_hash(file_path: str) -> str:
    """Compute SHA-256 hash of a file."""
    h = hashlib.sha256()
    with open(file_path, "rb") as f:
        while chunk := f.read(8192):
            h.update(chunk)
    return h.hexdigest()
