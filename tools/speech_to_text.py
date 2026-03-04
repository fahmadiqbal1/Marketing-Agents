"""
Speech-to-Text — local Whisper transcription for voice notes.

Uses OpenAI's open-source Whisper model (runs locally, completely free).
Supports .ogg, .mp3, .m4a, .wav, .webm voice files from Telegram.

Usage:
    from tools.speech_to_text import transcribe_voice_note, classify_voice_intent

    text = await transcribe_voice_note("/path/to/voice.ogg")
    intent = await classify_voice_intent(text)
"""

from __future__ import annotations

import asyncio
import logging
import subprocess
import tempfile
from pathlib import Path
from typing import Optional

logger = logging.getLogger(__name__)

# Lazy-loaded Whisper model (loaded once, reused)
_whisper_model = None
_model_lock = asyncio.Lock()

SUPPORTED_AUDIO_EXTENSIONS = {".ogg", ".oga", ".mp3", ".m4a", ".wav", ".webm", ".flac", ".aac"}

# Intent classification prompt for GPT-4o-mini
INTENT_CLASSIFICATION_PROMPT = """\
You are an intent classifier for a marketing platform. Classify the following transcribed voice message into ONE of these intents:

- **post_media**: User wants to post something, add a caption, or share content on social media
- **create_job**: User wants to create a job posting, hire someone, find a doctor/staff
- **ask_question**: User is asking a question about the platform, how-to, or general inquiry
- **command**: User is giving a specific command (e.g., "show my analytics", "check growth report")
- **schedule**: User wants to schedule a post or set a reminder
- **platform_setup**: User wants to connect or disconnect a social media platform

Respond with ONLY a JSON object:
{
  "intent": "<intent_name>",
  "confidence": <0.0-1.0>,
  "summary": "<1-sentence summary of what the user wants>",
  "extracted_data": {<any structured data you can extract, e.g., job_title, platform, etc.>}
}

Transcribed message:
"""


async def _load_whisper_model(model_size: str = "base"):
    """Lazy-load the Whisper model. Downloads on first use (~140MB for 'base')."""
    global _whisper_model

    async with _model_lock:
        if _whisper_model is not None:
            return _whisper_model

        try:
            import whisper
            logger.info(f"Loading Whisper '{model_size}' model (first time may download)...")
            _whisper_model = await asyncio.to_thread(whisper.load_model, model_size)
            logger.info(f"Whisper '{model_size}' model loaded successfully")
            return _whisper_model
        except ImportError:
            logger.error(
                "openai-whisper not installed. Run: pip install openai-whisper\n"
                "Also ensure ffmpeg is in PATH."
            )
            raise RuntimeError(
                "Whisper not installed. Run: pip install openai-whisper"
            )


def _convert_to_wav(input_path: str) -> str:
    """Convert any audio format to WAV using FFmpeg (required by Whisper)."""
    output_path = tempfile.mktemp(suffix=".wav")
    try:
        subprocess.run(
            [
                "ffmpeg", "-y", "-i", input_path,
                "-ar", "16000",  # 16kHz sample rate (Whisper expects this)
                "-ac", "1",      # Mono
                "-c:a", "pcm_s16le",
                output_path,
            ],
            capture_output=True,
            check=True,
            timeout=60,
        )
        return output_path
    except subprocess.CalledProcessError as e:
        logger.error(f"FFmpeg conversion failed: {e.stderr.decode()[:500]}")
        raise RuntimeError(f"Failed to convert audio: {e.stderr.decode()[:200]}")
    except FileNotFoundError:
        raise RuntimeError("FFmpeg not found. Install FFmpeg and add to PATH.")


async def transcribe_voice_note(
    file_path: str,
    language: str | None = None,
    model_size: str = "base",
) -> dict:
    """Transcribe a voice note file to text using Whisper.

    Args:
        file_path: Path to the audio file (.ogg, .mp3, .m4a, .wav, etc.)
        language: Language code (e.g., 'en', 'ur'). None = auto-detect.
        model_size: Whisper model size ('tiny', 'base', 'small', 'medium', 'large').

    Returns:
        dict with keys: text, language, duration_seconds
    """
    path = Path(file_path)
    if not path.exists():
        raise FileNotFoundError(f"Audio file not found: {file_path}")

    if path.suffix.lower() not in SUPPORTED_AUDIO_EXTENSIONS:
        raise ValueError(
            f"Unsupported audio format: {path.suffix}. "
            f"Supported: {', '.join(SUPPORTED_AUDIO_EXTENSIONS)}"
        )

    # Convert to WAV for Whisper
    wav_path = None
    try:
        wav_path = await asyncio.to_thread(_convert_to_wav, str(path))

        # Load model and transcribe
        model = await _load_whisper_model(model_size)
        options = {}
        if language:
            options["language"] = language

        result = await asyncio.to_thread(
            model.transcribe, wav_path, **options
        )

        text = result.get("text", "").strip()
        detected_lang = result.get("language", "unknown")

        # Estimate duration from segments
        segments = result.get("segments", [])
        duration = segments[-1]["end"] if segments else 0.0

        logger.info(
            f"Transcribed {path.name}: {len(text)} chars, "
            f"lang={detected_lang}, duration={duration:.1f}s"
        )

        return {
            "text": text,
            "language": detected_lang,
            "duration_seconds": round(duration, 1),
            "segments": [
                {"start": s["start"], "end": s["end"], "text": s["text"].strip()}
                for s in segments
            ],
        }

    finally:
        # Clean up temp WAV file
        if wav_path:
            Path(wav_path).unlink(missing_ok=True)


async def classify_voice_intent(
    transcribed_text: str,
) -> dict:
    """Classify the intent of a transcribed voice message using GPT-4o-mini.

    Returns:
        dict with keys: intent, confidence, summary, extracted_data
    """
    if not transcribed_text.strip():
        return {
            "intent": "unknown",
            "confidence": 0.0,
            "summary": "Empty voice message",
            "extracted_data": {},
        }

    try:
        from openai import AsyncOpenAI
        from config.settings import get_settings

        settings = get_settings()
        client = AsyncOpenAI(api_key=settings.openai_api_key)

        response = await client.chat.completions.create(
            model="gpt-4o-mini",
            messages=[
                {
                    "role": "system",
                    "content": INTENT_CLASSIFICATION_PROMPT,
                },
                {
                    "role": "user",
                    "content": transcribed_text,
                },
            ],
            temperature=0.1,
            max_tokens=300,
            response_format={"type": "json_object"},
        )

        import json
        result = json.loads(response.choices[0].message.content)

        # Ensure required keys exist
        return {
            "intent": result.get("intent", "unknown"),
            "confidence": float(result.get("confidence", 0.5)),
            "summary": result.get("summary", transcribed_text[:100]),
            "extracted_data": result.get("extracted_data", {}),
        }

    except Exception as e:
        logger.error(f"Intent classification failed: {e}")
        # Fallback: simple keyword matching
        text_lower = transcribed_text.lower()
        if any(w in text_lower for w in ["hire", "job", "doctor", "nurse", "staff", "position", "vacancy"]):
            return {"intent": "create_job", "confidence": 0.6, "summary": transcribed_text[:100], "extracted_data": {}}
        elif any(w in text_lower for w in ["post", "share", "upload", "publish"]):
            return {"intent": "post_media", "confidence": 0.6, "summary": transcribed_text[:100], "extracted_data": {}}
        elif any(w in text_lower for w in ["schedule", "tomorrow", "later", "time"]):
            return {"intent": "schedule", "confidence": 0.6, "summary": transcribed_text[:100], "extracted_data": {}}
        elif any(w in text_lower for w in ["connect", "setup", "instagram", "facebook", "tiktok", "linkedin"]):
            return {"intent": "platform_setup", "confidence": 0.6, "summary": transcribed_text[:100], "extracted_data": {}}
        else:
            return {"intent": "ask_question", "confidence": 0.4, "summary": transcribed_text[:100], "extracted_data": {}}


async def extract_job_from_voice(transcribed_text: str) -> dict:
    """Extract job posting details from a transcribed voice message.

    Uses GPT-4o-mini to parse natural language into structured job data.

    Returns:
        dict with: title, department, experience_required, key_skills, salary_range, notes
    """
    try:
        from openai import AsyncOpenAI
        from config.settings import get_settings

        settings = get_settings()
        client = AsyncOpenAI(api_key=settings.openai_api_key)

        response = await client.chat.completions.create(
            model="gpt-4o-mini",
            messages=[
                {
                    "role": "system",
                    "content": (
                        "Extract job posting details from this voice message transcript. "
                        "Return a JSON object with these fields:\n"
                        '- "title": job title (e.g., "Dental Surgeon")\n'
                        '- "department": department name (e.g., "OPD", "Laboratory", "Aesthetics")\n'
                        '- "experience_required": years/level (e.g., "5 years", "Fresh graduate")\n'
                        '- "key_skills": array of skills (e.g., ["RCT", "Dental Surgery"])\n'
                        '- "salary_range": salary info or empty string\n'
                        '- "notes": any additional details\n\n'
                        "If a field is not mentioned, use a sensible default or empty string/array. "
                        "Respond with ONLY the JSON object."
                    ),
                },
                {"role": "user", "content": transcribed_text},
            ],
            temperature=0.1,
            max_tokens=400,
            response_format={"type": "json_object"},
        )

        import json
        result = json.loads(response.choices[0].message.content)

        return {
            "title": result.get("title", ""),
            "department": result.get("department", "General"),
            "experience_required": result.get("experience_required", ""),
            "key_skills": result.get("key_skills", []),
            "salary_range": result.get("salary_range", ""),
            "notes": result.get("notes", ""),
        }

    except Exception as e:
        logger.error(f"Job extraction from voice failed: {e}")
        return {
            "title": "",
            "department": "General",
            "experience_required": "",
            "key_skills": [],
            "salary_range": "",
            "notes": transcribed_text,
        }
