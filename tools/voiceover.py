"""
Voiceover generation using Edge-TTS (completely free, Microsoft voices).
Generates MP3 audio from text for video narration.
"""

from __future__ import annotations

import asyncio
from pathlib import Path

import edge_tts

from config.settings import get_settings
from tools.media_utils import processed_path, generate_filename


# Available voices well-suited for healthcare content
VOICES = {
    "warm_female": "en-US-JennyNeural",     # Warm, professional
    "friendly_female": "en-US-AriaNeural",   # Friendly, clear
    "professional_male": "en-US-GuyNeural",  # Deep, authoritative
    "calm_female": "en-GB-SoniaNeural",      # British, calming
    "energetic_female": "en-US-SaraNeural",  # Upbeat, engaging
}


async def generate_voiceover(
    text: str,
    voice: str | None = None,
    output_name: str = "voiceover",
    rate: str = "+0%",    # Speech rate: "-20%" slower, "+20%" faster
    volume: str = "+0%",  # Volume adjustment
) -> str:
    """
    Generate a voiceover audio file from text.
    Returns the path to the generated MP3 file.
    """
    settings = get_settings()
    voice_name = voice or settings.default_voice

    # Map friendly names to Edge-TTS voice IDs
    if voice_name in VOICES:
        voice_name = VOICES[voice_name]

    out_name = generate_filename(f"{output_name}.mp3", prefix="vo")
    out_path = processed_path() / out_name

    communicate = edge_tts.Communicate(
        text=text,
        voice=voice_name,
        rate=rate,
        volume=volume,
    )

    await communicate.save(str(out_path))
    return str(out_path)


async def generate_narration_for_compilation(
    category: str,
    item_descriptions: list[str],
) -> str:
    """Generate a narration script and audio for a compilation video."""
    # Build narration script
    category_intros = {
        "hydrafacial": "Experience the glow! Here's a look at our hydrafacial treatments.",
        "laser_hair_removal": "Smooth, painless, permanent. Discover our laser hair removal results.",
        "laboratory": "Your health, our priority. Inside our state-of-the-art clinical laboratory.",
        "opd": "Compassionate care, every visit. See what makes our OPD special.",
        "facility": "Modern healthcare meets comfort. Take a tour of our facility.",
        "team": "Meet our dedicated team.",
        "before_after": "Real results, real customers. See the transformations.",
    }

    intro = category_intros.get(
        category,
        f"Discover excellence with our latest work."
    )

    outro = "Contact us today to learn more. Visit our website or call us now."

    script = f"{intro} {outro}"

    return await generate_voiceover(
        text=script,
        voice="warm_female",
        output_name=f"narration_{category}",
        rate="-5%",  # Slightly slower for clarity
    )
