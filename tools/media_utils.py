"""
Media file management — download from Telegram, store locally, get metadata.
"""

from __future__ import annotations

import os
import uuid
import json
import subprocess
from datetime import datetime
from pathlib import Path
from typing import Optional

from PIL import Image

from config.settings import get_settings


def _media_root() -> Path:
    return Path(get_settings().media_storage_path)


def inbox_path() -> Path:
    p = _media_root() / "inbox"
    p.mkdir(parents=True, exist_ok=True)
    return p


def processed_path() -> Path:
    p = _media_root() / "processed"
    p.mkdir(parents=True, exist_ok=True)
    return p


def snapchat_ready_path() -> Path:
    p = _media_root() / "snapchat_ready"
    p.mkdir(parents=True, exist_ok=True)
    return p


def collages_path() -> Path:
    p = _media_root() / "collages"
    p.mkdir(parents=True, exist_ok=True)
    return p


def compilations_path() -> Path:
    p = _media_root() / "compilations"
    p.mkdir(parents=True, exist_ok=True)
    return p


def resumes_path() -> Path:
    p = _media_root() / "resumes"
    p.mkdir(parents=True, exist_ok=True)
    return p


def generate_filename(original_name: str, prefix: str = "") -> str:
    """Generate a unique filename preserving the original extension."""
    ext = Path(original_name).suffix or ".jpg"
    ts = datetime.now().strftime("%Y%m%d_%H%M%S")
    uid = uuid.uuid4().hex[:8]
    prefix_str = f"{prefix}_" if prefix else ""
    return f"{prefix_str}{ts}_{uid}{ext}"


def get_image_metadata(file_path: str) -> dict:
    """Extract width, height, file size from an image file."""
    p = Path(file_path)
    if not p.exists():
        return {}
    try:
        with Image.open(p) as img:
            width, height = img.size
        return {
            "width": width,
            "height": height,
            "file_size_bytes": p.stat().st_size,
        }
    except Exception:
        return {"file_size_bytes": p.stat().st_size}


def get_video_metadata(file_path: str) -> dict:
    """Extract width, height, duration, file size from a video using ffprobe."""
    p = Path(file_path)
    if not p.exists():
        return {}
    try:
        result = subprocess.run(
            [
                "ffprobe",
                "-v", "quiet",
                "-print_format", "json",
                "-show_format",
                "-show_streams",
                str(p),
            ],
            capture_output=True,
            text=True,
            timeout=30,
        )
        probe = json.loads(result.stdout)

        video_stream = next(
            (s for s in probe.get("streams", []) if s.get("codec_type") == "video"),
            None,
        )
        fmt = probe.get("format", {})

        metadata = {"file_size_bytes": p.stat().st_size}
        if video_stream:
            metadata["width"] = int(video_stream.get("width", 0))
            metadata["height"] = int(video_stream.get("height", 0))
        if fmt:
            metadata["duration_seconds"] = float(fmt.get("duration", 0))

        return metadata
    except Exception:
        return {"file_size_bytes": p.stat().st_size}


def is_vertical(width: int, height: int) -> bool:
    return height > width


def is_horizontal(width: int, height: int) -> bool:
    return width > height


def is_square(width: int, height: int) -> bool:
    return abs(width - height) < max(width, height) * 0.05  # 5% tolerance


def aspect_ratio_label(width: int, height: int) -> str:
    """Return a human-readable aspect ratio label."""
    if is_square(width, height):
        return "1:1"
    ratio = width / height if height else 1
    if 0.5 <= ratio <= 0.6:
        return "9:16"
    if 1.7 <= ratio <= 1.8:
        return "16:9"
    if 0.74 <= ratio <= 0.76:
        return "3:4"
    if 1.3 <= ratio <= 1.35:
        return "4:3"
    return f"{ratio:.2f}:1"
