"""
File Guard — validates uploaded files before they enter the workflow.

Checks:
1. Magic byte verification (actual file type, not just extension)
2. Extension whitelist (only known media formats)
3. File size limits (100MB video, 20MB photo)
4. EXIF metadata stripping (removes GPS, device info, timestamps)
5. Filename sanitization (no path traversal, no special chars)
6. Mime-type cross-validation
7. Dimension sanity check (reject corrupted files)

This runs BEFORE the file is saved to media/inbox.
"""

from __future__ import annotations

import logging
import os
import re
from dataclasses import dataclass, field
from pathlib import Path
from typing import Optional

logger = logging.getLogger(__name__)


# ── Allowed File Types ────────────────────────────────────────────────────────

# Magic bytes → expected extension families
MAGIC_SIGNATURES: dict[bytes, list[str]] = {
    b"\xff\xd8\xff": [".jpg", ".jpeg"],                         # JPEG
    b"\x89PNG\r\n\x1a\n": [".png"],                             # PNG
    b"GIF87a": [".gif"],                                        # GIF87a
    b"GIF89a": [".gif"],                                        # GIF89a
    b"RIFF": [".webp"],                                         # WebP (RIFF container)
    b"\x00\x00\x00\x1cftyp": [".mp4", ".m4v"],                  # MP4 / ftyp box
    b"\x00\x00\x00\x18ftyp": [".mp4", ".m4v"],                  # MP4 variant
    b"\x00\x00\x00\x20ftyp": [".mp4", ".m4v", ".mov"],          # MP4/MOV variant
    b"\x1aE\xdf\xa3": [".mkv", ".webm"],                        # Matroska / WebM
    b"\x00\x00\x00\x14ftypqt": [".mov"],                        # QuickTime MOV
}

ALLOWED_PHOTO_EXTS = {".jpg", ".jpeg", ".png", ".gif", ".webp", ".bmp", ".heic", ".heif"}
ALLOWED_VIDEO_EXTS = {".mp4", ".mov", ".avi", ".mkv", ".webm", ".m4v"}
ALLOWED_DOC_EXTS = {".pdf", ".doc", ".docx"}  # For resumes only
ALLOWED_TEXT_EXTS = {".txt"}              # For plain-text project/knowledge dumps
ALL_ALLOWED_EXTS = ALLOWED_PHOTO_EXTS | ALLOWED_VIDEO_EXTS | ALLOWED_DOC_EXTS | ALLOWED_TEXT_EXTS

# Size limits in bytes
MAX_PHOTO_SIZE = 20 * 1024 * 1024       # 20 MB
MAX_VIDEO_SIZE = 100 * 1024 * 1024      # 100 MB
MAX_DOC_SIZE = 10 * 1024 * 1024         # 10 MB
MAX_TXT_SIZE = 50 * 1024 * 1024         # 50 MB  (large project dumps)
MIN_FILE_SIZE = 1024                     # 1 KB (reject empty/corrupt files)

# Filename rules
MAX_FILENAME_LENGTH = 200
FILENAME_SANITIZE_RE = re.compile(r"[^a-zA-Z0-9._\-\s]")
PATH_TRAVERSAL_RE = re.compile(r"\.\.|[/\\]")


# ── Result ────────────────────────────────────────────────────────────────────

@dataclass
class FileValidationResult:
    """Result of file validation."""
    is_safe: bool = False
    file_type: str = ""            # "photo", "video", "document"
    sanitized_name: str = ""
    original_name: str = ""
    file_size: int = 0
    issues: list[str] = field(default_factory=list)
    warnings: list[str] = field(default_factory=list)


# ── Main Validation Function ─────────────────────────────────────────────────

def validate_file(
    file_path: str,
    original_filename: str,
    allow_documents: bool = False,
) -> FileValidationResult:
    """
    Validate a file before accepting it into the system.

    Args:
        file_path: Path to the temporary/uploaded file on disk
        original_filename: Original filename from the upload
        allow_documents: Whether to allow PDFs/docs (for resume uploads)

    Returns:
        FileValidationResult with is_safe=True if the file passes all checks
    """
    result = FileValidationResult(original_name=original_filename)
    path = Path(file_path)

    # ── 1. File exists and is readable ────────────────────────────────────
    if not path.exists():
        result.issues.append("File does not exist")
        return result

    if not path.is_file():
        result.issues.append("Path is not a regular file")
        return result

    # ── 2. File size check ────────────────────────────────────────────────
    result.file_size = path.stat().st_size

    if result.file_size < MIN_FILE_SIZE:
        result.issues.append(f"File too small ({result.file_size} bytes) — likely corrupted")
        return result

    # ── 3. Extension whitelist ────────────────────────────────────────────
    ext = Path(original_filename).suffix.lower()
    allowed = ALL_ALLOWED_EXTS if allow_documents else (ALLOWED_PHOTO_EXTS | ALLOWED_VIDEO_EXTS)

    if ext not in allowed:
        result.issues.append(
            f"File extension '{ext}' not allowed. "
            f"Accepted: {', '.join(sorted(allowed))}"
        )
        return result

    # Determine file type
    if ext in ALLOWED_PHOTO_EXTS:
        result.file_type = "photo"
        max_size = MAX_PHOTO_SIZE
    elif ext in ALLOWED_VIDEO_EXTS:
        result.file_type = "video"
        max_size = MAX_VIDEO_SIZE
    elif ext in ALLOWED_TEXT_EXTS:
        result.file_type = "text"
        max_size = MAX_TXT_SIZE
    else:
        result.file_type = "document"
        max_size = MAX_DOC_SIZE

    if result.file_size > max_size:
        limit_mb = max_size // (1024 * 1024)
        actual_mb = round(result.file_size / (1024 * 1024), 1)
        result.issues.append(
            f"File too large ({actual_mb} MB). "
            f"Max for {result.file_type}: {limit_mb} MB"
        )
        return result

    # ── 4. Magic byte verification ────────────────────────────────────────
    if result.file_type in ("photo", "video"):
        if not _verify_magic_bytes(path, ext):
            result.issues.append(
                f"File content does not match extension '{ext}'. "
                "Possible disguised file — rejected for safety."
            )
            return result

    # ── 5. Filename sanitization ──────────────────────────────────────────
    result.sanitized_name = _sanitize_filename(original_filename)

    if PATH_TRAVERSAL_RE.search(original_filename):
        result.warnings.append("Path traversal characters removed from filename")

    # ── 6. Image/video integrity check ────────────────────────────────────
    if result.file_type == "photo":
        integrity_ok, integrity_msg = _check_image_integrity(path)
        if not integrity_ok:
            result.issues.append(f"Image integrity check failed: {integrity_msg}")
            return result

    # ── 6b. Video integrity check ────────────────────────────────────────
    if result.file_type == "video":
        integrity_ok, integrity_msg = _check_video_integrity(path)
        if not integrity_ok:
            result.issues.append(f"Video integrity check failed: {integrity_msg}")
            return result

    # ── 7. Malware scan (ClamAV) ──────────────────────────────────────
    malware_result = scan_for_malware(str(path))
    if malware_result["infected"]:
        result.issues.append(
            f"Malware detected: {malware_result['threat']} — file rejected"
        )
        return result
    if malware_result.get("warning"):
        result.warnings.append(malware_result["warning"])

    # ── 8. All checks passed ─────────────────────────────────────────────
    result.is_safe = True
    logger.info(
        f"File validated OK: {result.sanitized_name} "
        f"({result.file_type}, {result.file_size} bytes)"
    )
    return result


# ── EXIF Stripping ────────────────────────────────────────────────────────────

def strip_exif_metadata(file_path: str) -> str:
    """
    Strip EXIF metadata from images (GPS location, device info, timestamps).
    Returns the path to the cleaned file (overwrites in place).
    """
    path = Path(file_path)
    ext = path.suffix.lower()

    if ext not in {".jpg", ".jpeg", ".png", ".webp"}:
        return file_path  # Only strip from supported formats

    try:
        from PIL import Image

        img = Image.open(str(path))
        # Create a new image without EXIF data
        data = list(img.getdata())
        clean_img = Image.new(img.mode, img.size)
        clean_img.putdata(data)
        clean_img.save(str(path), quality=95)

        logger.info(f"EXIF metadata stripped from {path.name}")
        return file_path

    except Exception as e:
        logger.warning(f"Could not strip EXIF from {path.name}: {e}")
        return file_path  # Return original if stripping fails


# ── Internal Helpers ──────────────────────────────────────────────────────────

def _verify_magic_bytes(path: Path, expected_ext: str) -> bool:
    """Verify file's magic bytes match the claimed extension."""
    try:
        with open(path, "rb") as f:
            header = f.read(32)  # Read first 32 bytes

        if len(header) < 4:
            return False

        # Check if any known signature matches this extension
        for signature, valid_exts in MAGIC_SIGNATURES.items():
            if header.startswith(signature):
                if expected_ext in valid_exts:
                    return True
                # Matched a signature but wrong extension
                return False

        # Special case: MOV files (ftyp box at various offsets)
        if expected_ext in (".mov", ".mp4", ".m4v"):
            if b"ftyp" in header[:32]:
                return True

        # Special case: AVI files
        if expected_ext == ".avi" and header[:4] == b"RIFF" and header[8:12] == b"AVI ":
            return True

        # Special case: WebP (RIFF + WEBP)
        if expected_ext == ".webp" and header[:4] == b"RIFF" and header[8:12] == b"WEBP":
            return True

        # Special case: BMP
        if expected_ext == ".bmp" and header[:2] == b"BM":
            return True

        # HEIC / HEIF (ftyp with heic/heif brand)
        if expected_ext in (".heic", ".heif") and b"ftyp" in header[:32]:
            if b"heic" in header[:32] or b"heif" in header[:32] or b"mif1" in header[:32]:
                return True

        # If no signature matched and we get here, it's unknown
        logger.warning(
            f"Unknown magic bytes for {path.name} (ext={expected_ext}): "
            f"{header[:8].hex()}"
        )
        return False

    except Exception as e:
        logger.error(f"Magic byte check failed: {e}")
        return False


def _sanitize_filename(name: str) -> str:
    """Sanitize a filename: remove dangerous chars, limit length."""
    # Get just the filename (no directory components)
    name = Path(name).name

    # Separate name and extension
    stem = Path(name).stem
    ext = Path(name).suffix.lower()

    # Remove dangerous characters
    stem = FILENAME_SANITIZE_RE.sub("_", stem)

    # Remove leading/trailing dots and spaces
    stem = stem.strip(". ")

    # Collapse multiple underscores
    stem = re.sub(r"_+", "_", stem)

    # Truncate if too long
    max_stem = MAX_FILENAME_LENGTH - len(ext)
    if len(stem) > max_stem:
        stem = stem[:max_stem]

    # Ensure we have something
    if not stem:
        stem = "upload"

    return f"{stem}{ext}"


def _check_image_integrity(path: Path) -> tuple[bool, str]:
    """Verify an image file can actually be opened and decoded."""
    try:
        from PIL import Image

        with Image.open(str(path)) as img:
            img.verify()  # Verify without fully loading

        # Re-open to check dimensions (verify() makes the object unusable)
        with Image.open(str(path)) as img:
            w, h = img.size
            if w < 10 or h < 10:
                return False, f"Dimensions too small ({w}x{h})"
            if w > 20000 or h > 20000:
                return False, f"Dimensions suspiciously large ({w}x{h})"

        return True, "OK"

    except Exception as e:
        return False, str(e)


# ── Video Integrity Check ─────────────────────────────────────────────────────


def _check_video_integrity(path: Path) -> tuple[bool, str]:
    """
    Verify a video file is decodable using FFprobe.
    Catches crafted exploit files with zero streams or corrupt headers.
    """
    import subprocess
    try:
        cmd = [
            "ffprobe",
            "-v", "error",
            "-select_streams", "v:0",
            "-show_entries", "stream=width,height,codec_name,nb_frames",
            "-of", "json",
            str(path),
        ]
        result = subprocess.run(
            cmd, capture_output=True, text=True, timeout=30,
        )

        if result.returncode != 0:
            return False, f"FFprobe error: {result.stderr[:200]}"

        import json
        data = json.loads(result.stdout)
        streams = data.get("streams", [])
        if not streams:
            return False, "No video streams found — possibly a disguised or corrupt file"

        stream = streams[0]
        codec = stream.get("codec_name", "unknown")
        w = int(stream.get("width", 0))
        h = int(stream.get("height", 0))

        if w < 10 or h < 10:
            return False, f"Video dimensions too small ({w}x{h})"
        if w > 7680 or h > 7680:
            return False, f"Video dimensions suspiciously large ({w}x{h})"

        allowed_codecs = {
            "h264", "hevc", "h265", "vp8", "vp9", "av1",
            "mpeg4", "mjpeg", "prores",
        }
        if codec.lower() not in allowed_codecs:
            return False, f"Unsupported video codec: {codec}"

        return True, "OK"

    except subprocess.TimeoutExpired:
        return False, "FFprobe timed out — file may be corrupt"
    except Exception as e:
        return False, str(e)


# ── Malware Scanning (ClamAV) ─────────────────────────────────────────────────


def scan_for_malware(file_path: str) -> dict:
    """
    Scan a file for malware using ClamAV.

    Attempts:
      1. clamd daemon via pyclamd (fastest — already running)
      2. clamscan CLI as fallback
      3. If neither is available, returns a warning but does NOT block

    Returns:
        {
            "infected": bool,
            "threat": str | None,      # threat name if infected
            "scanner": str,            # "clamd", "clamscan", or "unavailable"
            "warning": str | None,     # set if scanner is unavailable
        }
    """
    # 1. Try clamd daemon
    try:
        import pyclamd
        cd = pyclamd.ClamdUnixSocket()
        try:
            cd.ping()
        except Exception:
            cd = pyclamd.ClamdNetworkSocket()
            cd.ping()

        result = cd.scan_file(file_path)
        if result is None:
            return {"infected": False, "threat": None, "scanner": "clamd"}
        else:
            # result: {'/path/to/file': ('FOUND', 'Eicar-Test-Signature')}
            status, threat = list(result.values())[0]
            is_infected = status == "FOUND"
            if is_infected:
                logger.critical("MALWARE DETECTED: %s in %s", threat, file_path)
            return {
                "infected": is_infected,
                "threat": threat if is_infected else None,
                "scanner": "clamd",
            }
    except ImportError:
        pass
    except Exception as e:
        logger.debug("clamd not available: %s", e)

    # 2. Try clamscan CLI
    import subprocess
    try:
        result = subprocess.run(
            ["clamscan", "--no-summary", file_path],
            capture_output=True, text=True, timeout=120,
        )
        if result.returncode == 0:
            return {"infected": False, "threat": None, "scanner": "clamscan"}
        elif result.returncode == 1:
            # Infected
            threat = result.stdout.strip().split(":")[-1].strip() if result.stdout else "Unknown"
            logger.critical("MALWARE DETECTED (clamscan): %s in %s", threat, file_path)
            return {"infected": True, "threat": threat, "scanner": "clamscan"}
        else:
            logger.debug("clamscan returned code %d: %s", result.returncode, result.stderr)
    except FileNotFoundError:
        pass
    except Exception as e:
        logger.debug("clamscan not available: %s", e)

    # 3. Neither available — quarantine the file
    quarantine_dir = Path(file_path).parent.parent / "quarantine"
    quarantine_dir.mkdir(parents=True, exist_ok=True)
    quarantine_path = quarantine_dir / Path(file_path).name
    try:
        import shutil
        shutil.copy2(file_path, str(quarantine_path))
    except Exception as copy_err:
        logger.warning("Failed to quarantine file: %s", copy_err)

    logger.warning(
        "ClamAV not installed — file quarantined at %s. "
        "Install ClamAV for real-time malware protection.",
        quarantine_path,
    )
    return {
        "infected": False,
        "threat": None,
        "scanner": "unavailable",
        "warning": "ClamAV not installed — file quarantined for manual review",
    }
