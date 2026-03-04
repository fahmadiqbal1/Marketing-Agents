"""
Content Scanner — detects NSFW, violent, or otherwise harmful visual content.

Uses a lightweight local classifier (Falconsai/nsfw_image_classification) so
no images leave the machine.  For videos, frames are sampled at regular
intervals and each frame is classified independently.

Usage:
    from security.content_scanner import ContentScanner

    scanner = ContentScanner()
    result  = scanner.scan_image("path/to/photo.jpg")
    result  = scanner.scan_video("path/to/video.mp4", sample_every=5)
"""

from __future__ import annotations

import logging
import subprocess
import tempfile
from dataclasses import dataclass, field
from pathlib import Path
from typing import Optional

from PIL import Image

logger = logging.getLogger(__name__)

# ── Result dataclass ──────────────────────────────────────────────────────────


@dataclass
class ContentScanResult:
    """Outcome of a visual-content safety scan."""

    is_safe: bool = True
    overall_score: float = 0.0          # 0 = perfectly safe, 1 = certainly unsafe
    flagged_categories: list[str] = field(default_factory=list)
    frame_scores: list[float] = field(default_factory=list)  # per-frame (videos)
    details: str = ""


# ── Scanner class ─────────────────────────────────────────────────────────────


class ContentScanner:
    """
    Detect NSFW / harmful visual content using a local HuggingFace model.

    The model is lazily loaded on first use so there is no startup cost if the
    scanner is never invoked.
    """

    _pipeline = None
    _model_id = "Falconsai/nsfw_image_classification"

    def __init__(self, threshold: float = 0.70):
        """
        Args:
            threshold: probability above which content is flagged unsafe.
        """
        self.threshold = threshold

    # ── lazy model loading ────────────────────────────────────────────────

    def _get_pipeline(self):
        if ContentScanner._pipeline is None:
            try:
                from transformers import pipeline as hf_pipeline

                ContentScanner._pipeline = hf_pipeline(
                    "image-classification",
                    model=self._model_id,
                    device=-1,       # CPU — keeps footprint small
                )
                logger.info("NSFW classifier loaded: %s", self._model_id)
            except Exception as e:
                logger.error("Failed to load NSFW classifier: %s", e)
                raise RuntimeError(
                    f"Could not load NSFW model ({self._model_id}). "
                    "Install transformers + torch: pip install transformers torch"
                ) from e
        return ContentScanner._pipeline

    # ── public API ────────────────────────────────────────────────────────

    def scan_image(self, image_path: str) -> ContentScanResult:
        """
        Classify a single image.

        Returns a ContentScanResult.  ``is_safe`` is True when the NSFW
        probability is **below** ``self.threshold``.
        """
        try:
            pipe = self._get_pipeline()
            img = Image.open(image_path).convert("RGB")
            predictions = pipe(img)

            nsfw_score = 0.0
            flagged: list[str] = []
            for pred in predictions:
                label = pred["label"].lower()
                score = pred["score"]
                if label in ("nsfw", "porn", "sexy", "hentai", "unsafe"):
                    nsfw_score = max(nsfw_score, score)
                    if score >= self.threshold:
                        flagged.append(f"{label} ({score:.2f})")

            is_safe = nsfw_score < self.threshold
            return ContentScanResult(
                is_safe=is_safe,
                overall_score=nsfw_score,
                flagged_categories=flagged,
                details=f"NSFW score: {nsfw_score:.3f} (threshold {self.threshold})",
            )
        except RuntimeError:
            raise
        except Exception as e:
            logger.error("Content scan failed for %s: %s", image_path, e)
            # Fail-closed: if we can't scan, treat as unsafe
            return ContentScanResult(
                is_safe=False,
                overall_score=1.0,
                flagged_categories=["scan_error"],
                details=f"Scan error — fail-closed: {e}",
            )

    def scan_video(
        self,
        video_path: str,
        sample_every: int = 5,
        max_frames: int = 20,
    ) -> ContentScanResult:
        """
        Sample frames from a video and classify each one.

        Args:
            video_path:    Path to the video file.
            sample_every:  Extract one frame every N seconds.
            max_frames:    Cap on total frames to check.
        """
        frames = self._extract_frames(video_path, sample_every, max_frames)
        if not frames:
            return ContentScanResult(
                is_safe=False,
                overall_score=1.0,
                flagged_categories=["frame_extraction_failed"],
                details="Could not extract any frames from video — fail-closed.",
            )

        all_scores: list[float] = []
        all_flags: list[str] = []

        for frame_path in frames:
            res = self.scan_image(frame_path)
            all_scores.append(res.overall_score)
            all_flags.extend(res.flagged_categories)

        worst = max(all_scores)
        is_safe = worst < self.threshold

        return ContentScanResult(
            is_safe=is_safe,
            overall_score=worst,
            flagged_categories=list(set(all_flags)),
            frame_scores=all_scores,
            details=(
                f"Scanned {len(frames)} frames. "
                f"Worst score: {worst:.3f} (threshold {self.threshold})"
            ),
        )

    # ── internal helpers ──────────────────────────────────────────────────

    @staticmethod
    def _extract_frames(
        video_path: str,
        sample_every: int,
        max_frames: int,
    ) -> list[str]:
        """Use FFmpeg to extract sample frames into a temp directory."""
        tmp_dir = tempfile.mkdtemp(prefix="nsfw_frames_")
        output_pattern = str(Path(tmp_dir) / "frame_%04d.jpg")

        cmd = [
            "ffmpeg", "-y",
            "-i", video_path,
            "-vf", f"fps=1/{sample_every}",
            "-frames:v", str(max_frames),
            "-q:v", "2",
            output_pattern,
        ]

        try:
            subprocess.run(
                cmd, capture_output=True, text=True, timeout=120, check=True,
            )
        except Exception as e:
            logger.error("FFmpeg frame extraction failed: %s", e)
            return []

        frames = sorted(Path(tmp_dir).glob("frame_*.jpg"))
        return [str(f) for f in frames]
