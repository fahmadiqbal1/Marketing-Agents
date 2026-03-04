"""
Media Editor Agent — local image/video processing, zero API cost.
Uses Pillow for images and FFmpeg for videos.
"""

from __future__ import annotations

import subprocess
from pathlib import Path
from typing import Optional

from PIL import Image, ImageDraw, ImageEnhance, ImageFilter, ImageFont, ImageOps

from agents.schemas import EditInstructions, PlatformSpec
from tools.media_utils import processed_path, generate_filename
from config.settings import get_settings


# ── Image Editing ─────────────────────────────────────────────────────────────


def edit_image(
    source_path: str,
    platform_spec: PlatformSpec,
    instructions: EditInstructions,
    output_prefix: str = "",
) -> str:
    """
    Edit an image according to instructions and platform spec.
    Returns the path to the edited image.
    """
    img = Image.open(source_path).convert("RGB")

    # 1. Brightness
    if instructions.brightness_adjustment != 1.0:
        enhancer = ImageEnhance.Brightness(img)
        img = enhancer.enhance(instructions.brightness_adjustment)

    # 2. Contrast
    if instructions.contrast_adjustment != 1.0:
        enhancer = ImageEnhance.Contrast(img)
        img = enhancer.enhance(instructions.contrast_adjustment)

    # 3. Saturation
    if instructions.saturation_adjustment != 1.0:
        enhancer = ImageEnhance.Color(img)
        img = enhancer.enhance(instructions.saturation_adjustment)

    # 4. Smart crop to target aspect ratio
    target_w = platform_spec.width
    target_h = platform_spec.height
    img = smart_crop(img, target_w, target_h)

    # 5. Resize to exact platform dimensions
    img = img.resize((target_w, target_h), Image.LANCZOS)

    # 6. Watermark — uses business name if provided, otherwise falls back
    if instructions.add_watermark:
        settings = get_settings()
        if settings.watermark_enabled:
            watermark_text = getattr(instructions, 'watermark_text', None) or "Your Business"
            img = add_watermark(img, watermark_text, settings.watermark_opacity)

    # 7. Text overlay
    if instructions.add_text_overlay:
        img = add_text_overlay(img, instructions.add_text_overlay)

    # Save
    out_name = generate_filename(Path(source_path).name, prefix=output_prefix or platform_spec.platform)
    out_path = processed_path() / out_name
    img.save(str(out_path), quality=95, optimize=True)
    return str(out_path)


def smart_crop(img: Image.Image, target_w: int, target_h: int) -> Image.Image:
    """
    Crop image to match target aspect ratio, centering the crop.
    Does NOT resize — just crops to the right ratio.
    """
    src_w, src_h = img.size
    target_ratio = target_w / target_h
    src_ratio = src_w / src_h

    if abs(src_ratio - target_ratio) < 0.01:
        return img  # Already correct ratio

    if src_ratio > target_ratio:
        # Image is wider than target — crop sides
        new_w = int(src_h * target_ratio)
        left = (src_w - new_w) // 2
        return img.crop((left, 0, left + new_w, src_h))
    else:
        # Image is taller than target — crop top/bottom
        new_h = int(src_w / target_ratio)
        top = (src_h - new_h) // 2
        return img.crop((0, top, src_w, top + new_h))


def add_watermark(img: Image.Image, text: str, opacity: float = 0.3) -> Image.Image:
    """Add a semi-transparent text watermark to the bottom-right corner."""
    txt_layer = Image.new("RGBA", img.size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(txt_layer)

    # Use a reasonable font size relative to image size
    font_size = max(16, img.size[0] // 30)
    try:
        font = ImageFont.truetype("arial.ttf", font_size)
    except (OSError, IOError):
        font = ImageFont.load_default()

    alpha = int(255 * opacity)
    bbox = draw.textbbox((0, 0), text, font=font)
    text_w = bbox[2] - bbox[0]
    text_h = bbox[3] - bbox[1]

    x = img.size[0] - text_w - 20
    y = img.size[1] - text_h - 20

    draw.text((x, y), text, fill=(255, 255, 255, alpha), font=font)

    img_rgba = img.convert("RGBA")
    composite = Image.alpha_composite(img_rgba, txt_layer)
    return composite.convert("RGB")


def add_text_overlay(
    img: Image.Image,
    text: str,
    position: str = "bottom",
    font_size: int = 0,
) -> Image.Image:
    """Add text overlay with a semi-transparent background bar."""
    draw = ImageDraw.Draw(img)
    if font_size == 0:
        font_size = max(20, img.size[0] // 20)

    try:
        font = ImageFont.truetype("arial.ttf", font_size)
    except (OSError, IOError):
        font = ImageFont.load_default()

    bbox = draw.textbbox((0, 0), text, font=font)
    text_w = bbox[2] - bbox[0]
    text_h = bbox[3] - bbox[1]

    padding = 20
    if position == "bottom":
        bar_y = img.size[1] - text_h - padding * 3
        bar = Image.new("RGBA", (img.size[0], text_h + padding * 2), (0, 0, 0, 150))
        img_rgba = img.convert("RGBA")
        img_rgba.paste(bar, (0, bar_y), bar)
        draw = ImageDraw.Draw(img_rgba)
        x = (img.size[0] - text_w) // 2
        draw.text((x, bar_y + padding), text, fill="white", font=font)
        return img_rgba.convert("RGB")

    return img


# ── Background Removal ────────────────────────────────────────────────────────


def remove_background(source_path: str) -> str:
    """Remove background using rembg. Returns path to the result."""
    from rembg import remove

    img = Image.open(source_path)
    result = remove(img)

    out_name = generate_filename(Path(source_path).name, prefix="nobg")
    out_path = processed_path() / out_name
    # Save as PNG to preserve transparency
    out_path = out_path.with_suffix(".png")
    result.save(str(out_path))
    return str(out_path)


# ── Video Editing (FFmpeg) ────────────────────────────────────────────────────


def edit_video(
    source_path: str,
    platform_spec: PlatformSpec,
    brightness: float = 1.0,
    add_watermark_text: Optional[str] = None,
) -> str:
    """
    Edit a video for a specific platform using FFmpeg.
    Handles: resize, pad to aspect ratio, brightness, watermark overlay.
    Returns path to edited video.
    """
    out_name = generate_filename(Path(source_path).name, prefix=platform_spec.platform)
    out_path = processed_path() / Path(out_name).with_suffix(".mp4")

    target_w = platform_spec.width
    target_h = platform_spec.height

    # Build FFmpeg filter chain
    filters = []

    # Scale to fit within target dimensions, maintaining aspect ratio, then pad
    filters.append(
        f"scale={target_w}:{target_h}:force_original_aspect_ratio=decrease,"
        f"pad={target_w}:{target_h}:(ow-iw)/2:(oh-ih)/2:black"
    )

    # Brightness adjustment (eq filter: brightness range is -1.0 to 1.0)
    if brightness != 1.0:
        br_val = brightness - 1.0  # Convert multiplier to FFmpeg offset
        br_val = max(-1.0, min(1.0, br_val))
        filters.append(f"eq=brightness={br_val}")

    # Watermark text
    if add_watermark_text:
        from security.prompt_guard import escape_for_ffmpeg

        settings = get_settings()
        opacity = settings.watermark_opacity
        safe_text = escape_for_ffmpeg(add_watermark_text)
        filters.append(
            f"drawtext=text='{safe_text}':"
            f"fontsize={target_w // 30}:fontcolor=white@{opacity}:"
            f"x=w-tw-20:y=h-th-20"
        )

    filter_str = ",".join(filters)

    # Build command
    cmd = [
        "ffmpeg", "-y",
        "-i", str(source_path),
        "-vf", filter_str,
        "-c:v", "libx264",
        "-preset", "medium",
        "-crf", "23",
        "-c:a", "aac",
        "-b:a", "128k",
        "-movflags", "+faststart",
    ]

    # Duration limit
    if platform_spec.max_duration_seconds:
        cmd.extend(["-t", str(platform_spec.max_duration_seconds)])

    cmd.append(str(out_path))

    subprocess.run(cmd, capture_output=True, text=True, timeout=300, check=True)
    return str(out_path)


def trim_video(source_path: str, start_seconds: float, end_seconds: float) -> str:
    """Trim a video to a specific time range."""
    out_name = generate_filename(Path(source_path).name, prefix="trimmed")
    out_path = processed_path() / Path(out_name).with_suffix(".mp4")

    cmd = [
        "ffmpeg", "-y",
        "-i", str(source_path),
        "-ss", str(start_seconds),
        "-to", str(end_seconds),
        "-c:v", "libx264",
        "-c:a", "aac",
        "-movflags", "+faststart",
        str(out_path),
    ]
    subprocess.run(cmd, capture_output=True, text=True, timeout=300, check=True)
    return str(out_path)


def concatenate_videos(video_paths: list[str], output_name: str = "compilation") -> str:
    """Concatenate multiple videos into one using FFmpeg concat demuxer."""
    out_name = generate_filename(f"{output_name}.mp4", prefix="compilation")
    out_path = processed_path() / out_name

    # Create concat file list
    concat_file = processed_path() / f"concat_{out_name}.txt"
    with open(concat_file, "w") as f:
        for vp in video_paths:
            f.write(f"file '{vp}'\n")

    cmd = [
        "ffmpeg", "-y",
        "-f", "concat",
        "-safe", "0",
        "-i", str(concat_file),
        "-c:v", "libx264",
        "-c:a", "aac",
        "-movflags", "+faststart",
        str(out_path),
    ]
    subprocess.run(cmd, capture_output=True, text=True, timeout=600, check=True)

    # Clean up concat file
    concat_file.unlink(missing_ok=True)
    return str(out_path)


def add_audio_to_video(video_path: str, audio_path: str) -> str:
    """Add/replace audio track (voiceover) on a video."""
    out_name = generate_filename(Path(video_path).name, prefix="voiced")
    out_path = processed_path() / Path(out_name).with_suffix(".mp4")

    cmd = [
        "ffmpeg", "-y",
        "-i", str(video_path),
        "-i", str(audio_path),
        "-map", "0:v:0",
        "-map", "1:a:0",
        "-c:v", "copy",
        "-c:a", "aac",
        "-shortest",
        "-movflags", "+faststart",
        str(out_path),
    ]
    subprocess.run(cmd, capture_output=True, text=True, timeout=300, check=True)
    return str(out_path)


def mix_background_music(
    video_path: str,
    music_path: str,
    music_volume: float = 0.2,
    fade_in: float = 1.0,
    fade_out: float = 2.0,
    voiceover_path: str | None = None,
) -> str:
    """
    Mix background music into a video, keeping the original audio.

    Uses FFmpeg amix filter to layer:
      - Original video audio (volume 1.0)
      - Background music (volume `music_volume`, looped, with fade in/out)
      - Optional voiceover (volume 1.0, ducking music further)

    Returns path to the output video with mixed audio.
    """
    out_name = generate_filename(Path(video_path).name, prefix="music")
    out_path = processed_path() / Path(out_name).with_suffix(".mp4")

    # Probe video duration for fade-out start position
    try:
        probe = subprocess.run(
            [
                "ffprobe", "-v", "error",
                "-show_entries", "format=duration",
                "-of", "default=noprint_wrappers=1:nokey=1",
                str(video_path),
            ],
            capture_output=True, text=True, timeout=30,
        )
        vid_duration = float(probe.stdout.strip()) if probe.stdout.strip() else 60.0
    except Exception:
        vid_duration = 60.0

    fade_out_start = max(0, vid_duration - fade_out)

    # Build input list
    inputs = ["-y", "-i", str(video_path), "-stream_loop", "-1", "-i", str(music_path)]
    if voiceover_path:
        inputs.extend(["-i", str(voiceover_path)])

    # Build complex filter
    # [1] = music: apply volume, fade-in, fade-out, trim to video length
    music_filter = (
        f"[1:a]volume={music_volume},"
        f"afade=t=in:st=0:d={fade_in},"
        f"afade=t=out:st={fade_out_start}:d={fade_out},"
        f"atrim=0:{vid_duration}[bgm]"
    )

    if voiceover_path:
        # Mix original audio + music + voiceover (3 inputs)
        # Duck music by extra 50% when voiceover is present
        vo_filter = f"[2:a]volume=1.0[vo]"
        mix_filter = "[0:a][bgm][vo]amix=inputs=3:duration=shortest:dropout_transition=2[out]"
        filter_complex = f"{music_filter};{vo_filter};{mix_filter}"
    else:
        # Mix original audio + music (2 inputs)
        mix_filter = "[0:a][bgm]amix=inputs=2:duration=shortest:dropout_transition=2[out]"
        filter_complex = f"{music_filter};{mix_filter}"

    cmd = [
        "ffmpeg",
        *inputs,
        "-filter_complex", filter_complex,
        "-map", "0:v:0",
        "-map", "[out]",
        "-c:v", "copy",
        "-c:a", "aac",
        "-b:a", "192k",
        "-movflags", "+faststart",
        str(out_path),
    ]

    subprocess.run(cmd, capture_output=True, text=True, timeout=600, check=True)
    return str(out_path)


# ── Image Enhancement & Platform Filters ─────────────────────────────────────


def enhance_image_quality(image_path: str, output_path: str | None = None) -> str:
    """
    Enhance image quality with auto-contrast, sharpening, color boost,
    and an approximate auto white-balance.  Returns the output path.
    """
    img = Image.open(image_path).convert("RGB")

    # 1. Auto contrast
    img = ImageOps.autocontrast(img)

    # 2. Moderate sharpening
    img = ImageEnhance.Sharpness(img).enhance(1.3)

    # 3. Slight color boost
    img = ImageEnhance.Color(img).enhance(1.1)

    # 4. Auto white-balance approximation (pure Pillow, no numpy)
    #    Compute per-channel mean via histogram, then scale each channel
    #    so that all means converge to the overall average.
    r, g, b = img.split()
    channel_means = []
    for ch in (r, g, b):
        hist = ch.histogram()  # 256 bins
        total_pixels = sum(hist)
        mean_val = sum(i * count for i, count in enumerate(hist)) / total_pixels
        channel_means.append(mean_val)

    overall_mean = sum(channel_means) / 3.0

    if all(m > 0 for m in channel_means):
        scales = [overall_mean / m for m in channel_means]
        channels = list(img.split())
        for idx, scale in enumerate(scales):
            channels[idx] = channels[idx].point(lambda v, s=scale: min(255, int(v * s)))
        img = Image.merge("RGB", channels)

    # Determine output path
    if output_path is None:
        p = Path(image_path)
        output_path = str(p.with_stem(p.stem + "_enhanced"))

    img.save(output_path, quality=95, optimize=True)
    return output_path


# Platform-specific filter presets (each maps to Pillow ImageEnhance params)
_PLATFORM_FILTER_PRESETS: dict[str, dict] = {
    "instagram": {
        "red_offset": 10,
        "saturation": 1.15,
        "contrast": 1.05,
    },
    "linkedin": {
        "saturation": 0.95,
        "contrast": 1.1,
        "brightness": 1.02,
    },
    "tiktok": {
        "saturation": 1.3,
        "contrast": 1.15,
        "brightness": 1.05,
    },
    "pinterest": {
        "brightness": 1.1,
        "contrast": 1.05,
        "saturation": 1.1,
    },
    "facebook": {
        "contrast": 1.05,
        "saturation": 1.05,
    },
    "twitter": {
        "contrast": 1.15,
        "sharpness": 1.2,
    },
    "snapchat": {
        "saturation": 1.2,
        "brightness": 1.08,
    },
}


def apply_platform_filter(
    image_path: str,
    platform: str,
    output_path: str | None = None,
) -> str:
    """
    Apply a platform-specific colour-grade / filter preset to an image.

    Supported platforms: instagram, linkedin, tiktok, pinterest,
    facebook, twitter, snapchat.  Unknown platforms return the
    original image unmodified.

    Returns the output path.
    """
    img = Image.open(image_path).convert("RGB")
    preset = _PLATFORM_FILTER_PRESETS.get(platform.lower(), {})

    # Red channel offset (warm tone for Instagram)
    red_offset = preset.get("red_offset")
    if red_offset:
        r, g, b = img.split()
        r = r.point(lambda v: min(255, v + red_offset))
        img = Image.merge("RGB", (r, g, b))

    # Brightness
    brightness = preset.get("brightness")
    if brightness and brightness != 1.0:
        img = ImageEnhance.Brightness(img).enhance(brightness)

    # Contrast
    contrast = preset.get("contrast")
    if contrast and contrast != 1.0:
        img = ImageEnhance.Contrast(img).enhance(contrast)

    # Saturation
    saturation = preset.get("saturation")
    if saturation and saturation != 1.0:
        img = ImageEnhance.Color(img).enhance(saturation)

    # Sharpness
    sharpness = preset.get("sharpness")
    if sharpness and sharpness != 1.0:
        img = ImageEnhance.Sharpness(img).enhance(sharpness)

    # Determine output path
    if output_path is None:
        p = Path(image_path)
        output_path = str(p.with_stem(p.stem + f"_{platform.lower()}"))

    img.save(output_path, quality=95, optimize=True)
    return output_path


# ── Collage Creation ──────────────────────────────────────────────────────────


def create_collage(
    image_paths: list[str],
    layout: str = "grid",  # "grid", "before_after", "vertical_strip"
    target_width: int = 1080,
    target_height: int = 1080,
) -> str:
    """Create a collage from multiple images."""
    images = [Image.open(p).convert("RGB") for p in image_paths]

    if layout == "before_after" and len(images) >= 2:
        return _create_before_after(images[:2], target_width, target_height)
    elif layout == "vertical_strip":
        return _create_vertical_strip(images, target_width)
    else:
        return _create_grid(images, target_width, target_height)


def _create_grid(images: list[Image.Image], w: int, h: int) -> str:
    """Create a grid collage (2x2, 3x3, etc.)."""
    n = len(images)
    if n <= 2:
        cols, rows = 2, 1
    elif n <= 4:
        cols, rows = 2, 2
    elif n <= 6:
        cols, rows = 3, 2
    else:
        cols, rows = 3, 3

    cell_w = w // cols
    cell_h = h // rows
    gap = 4

    canvas = Image.new("RGB", (w, h), (255, 255, 255))

    for i, img in enumerate(images[: cols * rows]):
        col = i % cols
        row = i // cols
        resized = smart_crop(img, cell_w - gap, cell_h - gap)
        resized = resized.resize((cell_w - gap, cell_h - gap), Image.LANCZOS)
        x = col * cell_w + gap // 2
        y = row * cell_h + gap // 2
        canvas.paste(resized, (x, y))

    out_name = generate_filename("collage.jpg", prefix="collage")
    out_path = processed_path() / out_name
    canvas.save(str(out_path), quality=95)
    return str(out_path)


def _create_before_after(images: list[Image.Image], w: int, h: int) -> str:
    """Side-by-side before/after comparison."""
    canvas = Image.new("RGB", (w, h), (255, 255, 255))
    half_w = w // 2 - 2

    for i, img in enumerate(images[:2]):
        resized = smart_crop(img, half_w, h)
        resized = resized.resize((half_w, h), Image.LANCZOS)
        canvas.paste(resized, (i * (half_w + 4), 0))

    # Add labels
    draw = ImageDraw.Draw(canvas)
    try:
        font = ImageFont.truetype("arial.ttf", max(20, w // 25))
    except (OSError, IOError):
        font = ImageFont.load_default()

    draw.text((20, h - 50), "BEFORE", fill="white", font=font)
    draw.text((half_w + 24, h - 50), "AFTER", fill="white", font=font)

    out_name = generate_filename("before_after.jpg", prefix="ba")
    out_path = processed_path() / out_name
    canvas.save(str(out_path), quality=95)
    return str(out_path)


def _create_vertical_strip(images: list[Image.Image], w: int) -> str:
    strip_h = w  # Each panel is square
    total_h = strip_h * len(images) + 4 * (len(images) - 1)
    canvas = Image.new("RGB", (w, total_h), (255, 255, 255))

    for i, img in enumerate(images):
        resized = smart_crop(img, w, strip_h)
        resized = resized.resize((w, strip_h), Image.LANCZOS)
        canvas.paste(resized, (0, i * (strip_h + 4)))

    out_name = generate_filename("strip.jpg", prefix="strip")
    out_path = processed_path() / out_name
    canvas.save(str(out_path), quality=95)
    return str(out_path)
