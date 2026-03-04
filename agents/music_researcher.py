"""
Music Researcher Agent — discovers trending background music/sounds for
short-form video platforms (TikTok, YouTube Shorts, Instagram Reels).

Strategies:
1. Curated royalty-free library (Pixabay / YouTube Audio Library tracks)
   tagged by mood, genre and business content category — safe for monetisation.
2. SerpAPI periodic search for currently trending sounds per platform.
3. Rule-based mood matching (zero tokens) derived from Gemini's MediaAnalysis.
4. Optional GPT-4o-mini AI suggestions (~200 tokens per call) that name the
   exact sound to search for in each platform's in-app music library.

Copyright notes:
* TikTok / Instagram have licensed music libraries — the system *suggests*
  which trending song to search for inside the app.
* For YouTube Shorts we only attach royalty-free tracks (safe to monetise).
"""

from __future__ import annotations

import json
import logging
from datetime import datetime, timedelta
from typing import Optional

from sqlalchemy import select, and_, or_, func
from sqlalchemy.ext.asyncio import AsyncSession
from openai import AsyncOpenAI

from config.settings import get_settings

logger = logging.getLogger(__name__)


# =============================================================================
# MOOD ↔ CONTENT-CATEGORY MAPPING  (zero tokens)
# =============================================================================

CATEGORY_MOOD_MAP: dict[str, list[str]] = {
    "opd":                  ["calm", "warm", "professional"],
    "laboratory":           ["professional", "calm", "ambient"],
    "hydrafacial":          ["trendy", "upbeat", "happy", "dramatic"],
    "laser_hair_removal":   ["trendy", "modern", "dramatic", "upbeat"],
    "xray":                 ["professional", "calm", "ambient"],
    "ultrasound_echo":      ["calm", "warm", "professional"],
    "ecg":                  ["calm", "professional", "ambient"],
    "pharmacy":             ["chill", "calm", "warm"],
    "team":                 ["upbeat", "inspiring", "motivational"],
    "facility":             ["inspiring", "upbeat", "professional"],
    "patient_testimonial":  ["warm", "inspiring", "calm"],
    "before_after":         ["dramatic", "trendy", "upbeat", "happy"],
    "promotional":          ["upbeat", "happy", "motivational", "energetic"],
    "job_posting":          ["motivational", "upbeat", "professional"],
    "general":              ["upbeat", "calm", "chill"],
}


# =============================================================================
# CURATED ROYALTY-FREE LIBRARY  (safe for YouTube monetisation)
#
# Format mirrors the HashtagCache SEED pattern.
# Each entry is a dict with metadata; *no actual file* — the admin downloads
# tracks once into  media/music_library/  and the `local_filename` field
# points there.  If the file doesn't exist the track is skipped gracefully.
# =============================================================================

SEED_MUSIC: list[dict] = [
    # ── Upbeat / Positive ─────────────────────────
    {
        "title": "Uplifting Corporate",
        "artist": "Pixabay",
        "mood": "upbeat",
        "genre": "corporate",
        "categories": "facility,team,promotional",
        "local_filename": "uplifting_corporate.mp3",
        "source_url": "https://pixabay.com/music/",
        "duration_seconds": 120,
        "note": "Great for clinic tours, team introductions",
    },
    {
        "title": "Happy Day",
        "artist": "Pixabay",
        "mood": "happy",
        "genre": "pop",
        "categories": "hydrafacial,before_after,promotional",
        "local_filename": "happy_day.mp3",
        "source_url": "https://pixabay.com/music/",
        "duration_seconds": 90,
        "note": "Perfect for transformation / before-after reveals",
    },
    {
        "title": "Inspiring Cinematic",
        "artist": "Pixabay",
        "mood": "inspiring",
        "genre": "cinematic",
        "categories": "facility,team,patient_testimonial",
        "local_filename": "inspiring_cinematic.mp3",
        "source_url": "https://pixabay.com/music/",
        "duration_seconds": 150,
        "note": "Facility showcase, patient success stories",
    },
    # ── Calm / Professional ───────────────────────
    {
        "title": "Gentle Healing",
        "artist": "Pixabay",
        "mood": "calm",
        "genre": "ambient",
        "categories": "opd,laboratory,ecg,xray,ultrasound_echo",
        "local_filename": "gentle_healing.mp3",
        "source_url": "https://pixabay.com/music/",
        "duration_seconds": 180,
        "note": "Medical procedures, clinical content",
    },
    {
        "title": "Soft Piano",
        "artist": "Pixabay",
        "mood": "warm",
        "genre": "piano",
        "categories": "patient_testimonial,opd,team",
        "local_filename": "soft_piano.mp3",
        "source_url": "https://pixabay.com/music/",
        "duration_seconds": 130,
        "note": "Patient stories, emotional content",
    },
    {
        "title": "Ambient Science",
        "artist": "Pixabay",
        "mood": "professional",
        "genre": "electronic",
        "categories": "laboratory,xray,ecg,ultrasound_echo",
        "local_filename": "ambient_science.mp3",
        "source_url": "https://pixabay.com/music/",
        "duration_seconds": 140,
        "note": "Lab equipment, diagnostic procedures",
    },
    # ── Trendy / Modern ──────────────────────────
    {
        "title": "Modern Fashion Beat",
        "artist": "Pixabay",
        "mood": "trendy",
        "genre": "electronic",
        "categories": "hydrafacial,laser_hair_removal,before_after",
        "local_filename": "modern_fashion_beat.mp3",
        "source_url": "https://pixabay.com/music/",
        "duration_seconds": 100,
        "note": "Beauty / aesthetic treatments, TikTok-style",
    },
    {
        "title": "Lo-fi Chill",
        "artist": "Pixabay",
        "mood": "chill",
        "genre": "lofi",
        "categories": "pharmacy,general,facility",
        "local_filename": "lofi_chill.mp3",
        "source_url": "https://pixabay.com/music/",
        "duration_seconds": 160,
        "note": "Casual behind-the-scenes, day-in-the-life",
    },
    {
        "title": "Motivational Drums",
        "artist": "Pixabay",
        "mood": "motivational",
        "genre": "percussion",
        "categories": "team,facility,promotional,job_posting",
        "local_filename": "motivational_drums.mp3",
        "source_url": "https://pixabay.com/music/",
        "duration_seconds": 110,
        "note": "Team motivation, hiring announcements",
    },
    # ── Dramatic / Reveal ─────────────────────────
    {
        "title": "Dramatic Reveal",
        "artist": "Pixabay",
        "mood": "dramatic",
        "genre": "cinematic",
        "categories": "before_after,hydrafacial,laser_hair_removal",
        "local_filename": "dramatic_reveal.mp3",
        "source_url": "https://pixabay.com/music/",
        "duration_seconds": 80,
        "note": "Before/after transformations with suspense build-up",
    },
    {
        "title": "Energetic Promo",
        "artist": "Pixabay",
        "mood": "energetic",
        "genre": "edm",
        "categories": "promotional,before_after,team",
        "local_filename": "energetic_promo.mp3",
        "source_url": "https://pixabay.com/music/",
        "duration_seconds": 95,
        "note": "High-energy promos, quick transitions",
    },
    {
        "title": "Modern Ambient",
        "artist": "Pixabay",
        "mood": "ambient",
        "genre": "ambient",
        "categories": "opd,laboratory,ecg,xray,ultrasound_echo,pharmacy",
        "local_filename": "modern_ambient.mp3",
        "source_url": "https://pixabay.com/music/",
        "duration_seconds": 200,
        "note": "Subtle background for clinical settings",
    },
]


# =============================================================================
# DATABASE OPERATIONS  —  mirror hashtag_researcher.py pattern
# =============================================================================


async def seed_music(session: AsyncSession) -> None:
    """Populate the music_tracks table with curated royalty-free library (idempotent)."""
    from memory.models import MusicTrack

    result = await session.execute(select(func.count(MusicTrack.id)))
    count = result.scalar_one()
    if count > 0:
        logger.info(f"Music library already seeded ({count} tracks).")
        return

    settings = get_settings()
    music_dir = settings.music_library_path

    for entry in SEED_MUSIC:
        track = MusicTrack(
            title=entry["title"],
            artist=entry.get("artist"),
            mood=entry["mood"],
            genre=entry.get("genre"),
            categories=entry.get("categories", "general"),
            local_filename=entry.get("local_filename"),
            source_url=entry.get("source_url"),
            duration_seconds=entry.get("duration_seconds"),
            is_royalty_free=True,
            is_trending=False,
            trending_score=0.0,
            platform="all",
            note=entry.get("note", ""),
        )
        session.add(track)

    await session.commit()
    logger.info(f"Seeded {len(SEED_MUSIC)} royalty-free music tracks.")


async def get_music(
    session: AsyncSession,
    category: str,
    platform: str,
    mood_override: str | None = None,
    max_count: int = 5,
) -> list:
    """
    Return best-matching tracks for a given content category and platform.
    Scoring: category match (+3) · mood match (+2, +1 for top-pick) · trending (+2).
    """
    from memory.models import MusicTrack

    preferred_moods = (
        [mood_override] + CATEGORY_MOOD_MAP.get(category, ["upbeat"])
        if mood_override
        else CATEGORY_MOOD_MAP.get(category, ["upbeat"])
    )

    result = await session.execute(
        select(MusicTrack).where(
            or_(
                MusicTrack.platform == platform,
                MusicTrack.platform == "all",
            )
        )
    )
    all_tracks = result.scalars().all()

    scored: list[tuple[float, object]] = []
    for track in all_tracks:
        score = 0.0
        # Category match
        track_cats = (track.categories or "").split(",")
        if category in track_cats:
            score += 3
        # Mood match
        if track.mood in preferred_moods:
            score += 2
            if preferred_moods and track.mood == preferred_moods[0]:
                score += 1
        # Trending bonus
        if track.is_trending:
            score += 2
        if score > 0:
            scored.append((score, track))

    scored.sort(key=lambda x: x[0], reverse=True)
    return [t for _, t in scored[:max_count]]


async def update_trending_music(session: AsyncSession) -> int:
    """
    Search the web via SerpAPI for currently trending sounds on each platform.
    Mirrors the pattern in hashtag_researcher.update_trending_hashtags().
    Returns number of new tracks added.
    """
    from memory.models import MusicTrack

    settings = get_settings()
    if not settings.serpapi_api_key:
        logger.warning("SerpAPI key not set — skipping trending music update.")
        return 0

    try:
        from serpapi import GoogleSearch
    except ImportError:
        logger.warning("google-search-results package not installed.")
        return 0

    month_year = datetime.now().strftime("%B %Y")
    searches = [
        ("tiktok", f"trending TikTok sounds {month_year}"),
        ("instagram", f"trending Instagram Reels music {month_year}"),
        ("youtube", f"trending YouTube Shorts background music {month_year}"),
    ]

    added = 0
    for platform, query in searches:
        try:
            search = GoogleSearch({
                "q": query,
                "api_key": settings.serpapi_api_key,
                "num": 5,
            })
            results = search.get_dict()

            for item in results.get("organic_results", [])[:5]:
                title = (item.get("title") or "")[:255]
                snippet = (item.get("snippet") or "")[:500]
                link = item.get("link", "")

                if not title:
                    continue

                # Check if already exists
                exists = await session.execute(
                    select(MusicTrack).where(
                        and_(
                            MusicTrack.title == title,
                            MusicTrack.platform == platform,
                        )
                    )
                )
                if exists.scalars().first():
                    continue

                track = MusicTrack(
                    title=title,
                    platform=platform,
                    mood="trendy",
                    categories="general",
                    source_url=link,
                    is_royalty_free=False,
                    is_trending=True,
                    trending_score=5.0,
                    note=snippet[:255] if snippet else "",
                )
                session.add(track)
                added += 1

        except Exception as e:
            logger.warning(f"Trending music search failed for {platform}: {e}")
            continue

    if added:
        await session.commit()
    logger.info(f"Added {added} trending music entries.")
    return added


# =============================================================================
# AI-POWERED MUSIC SUGGESTIONS  (~200 tokens)
# =============================================================================


async def suggest_music_with_ai(
    content_category: str,
    content_description: str,
    platform: str,
) -> list[dict]:
    """
    Use GPT-4o-mini to suggest trending songs/sounds that match this content.
    Returns list of dicts with title, artist, reason, mood, search_term,
    is_safe_for_business.
    """
    settings = get_settings()
    if not settings.openai_api_key:
        return []

    try:
        client = AsyncOpenAI(api_key=settings.openai_api_key)

        response = await client.chat.completions.create(
            model="gpt-4o-mini",
            messages=[
                {
                    "role": "system",
                    "content": (
                        "You are a social-media music expert who knows which sounds are "
                        "trending on TikTok, Instagram Reels, and YouTube Shorts. You "
                        "specialise in healthcare and beauty content.\n\n"
                        "For each suggestion indicate:\n"
                        "- Whether it's a trending song, original audio, or SFX\n"
                        "- Why it fits this specific content\n"
                        "- How to find it in the platform's music library\n"
                        "- Whether it's safe for business / commercial use"
                    ),
                },
                {
                    "role": "user",
                    "content": (
                        f"Platform: {platform}\n"
                        f"Business: the client's business\n"  # Business context loaded dynamically from DB
                        f"Content category: {content_category.replace('_', ' ')}\n"
                        f"Description: {content_description}\n\n"
                        f"Suggest 3 trending songs/sounds. Return JSON object with key "
                        f"'suggestions' containing an array. Each element has keys: "
                        f"title, artist, reason, mood, search_term, is_safe_for_business."
                    ),
                },
            ],
            response_format={"type": "json_object"},
            temperature=0.7,
            max_tokens=500,
        )

        data = json.loads(response.choices[0].message.content)
        suggestions = data.get("suggestions", [])
        if isinstance(suggestions, dict):
            suggestions = [suggestions]
        return suggestions[:3]

    except Exception as e:
        logger.warning(f"AI music suggestion failed: {e}")
        return []


# =============================================================================
# MAIN ENTRY POINT  — recommend music for a video
# =============================================================================


async def recommend_music_for_content(
    session: AsyncSession,
    content_category: str,
    content_description: str,
    content_mood: str,
    platform: str,
    use_ai: bool = False,
) -> dict:
    """
    Recommend background music for a piece of video content.

    Returns dict with:
        best_track    – top royalty-free MusicTrack (object) or None
        alternatives  – list of alternative MusicTrack objects
        trending      – list of trending sound suggestions (dicts)
        ai_picks      – list of AI-suggested songs (dicts, only if use_ai=True)
        mood          – the mood used for matching
    """
    # 1. Rule-based mood from category (zero tokens)
    preferred_moods = CATEGORY_MOOD_MAP.get(content_category, ["upbeat", "calm"])
    # Blend Gemini's detected mood if it maps to something useful
    if content_mood and content_mood not in preferred_moods:
        preferred_moods = [content_mood] + preferred_moods

    # 2. Query local royalty-free library
    tracks = await get_music(
        session,
        category=content_category,
        platform=platform,
        mood_override=preferred_moods[0] if preferred_moods else None,
        max_count=5,
    )

    best_track = tracks[0] if tracks else None
    alternatives = tracks[1:] if len(tracks) > 1 else []

    # 3. Trending sounds (from cached SerpAPI results)
    trending: list[dict] = []
    try:
        from memory.models import MusicTrack as MT
        result = await session.execute(
            select(MT).where(
                and_(
                    or_(MT.platform == platform, MT.platform == "all"),
                    MT.is_trending == True,
                )
            ).order_by(MT.trending_score.desc()).limit(3)
        )
        for t in result.scalars().all():
            trending.append({
                "title": t.title,
                "artist": t.artist,
                "mood": t.mood,
                "source_url": t.source_url,
                "note": t.note,
            })
    except Exception:
        pass

    # 4. Optional AI suggestions
    ai_picks: list[dict] = []
    if use_ai:
        ai_picks = await suggest_music_with_ai(
            content_category, content_description, platform,
        )

    return {
        "best_track": best_track,
        "alternatives": alternatives,
        "trending": trending,
        "ai_picks": ai_picks,
        "mood": preferred_moods[0] if preferred_moods else "upbeat",
    }


# =============================================================================
# TELEGRAM FORMATTING
# =============================================================================


def format_music_for_telegram(recommendations: dict, platform: str) -> str:
    """Format music recommendations as a readable Telegram message."""
    lines: list[str] = [f"🎵 *Music for {platform.replace('_', ' ').title()}*\n"]

    # Best pick
    best = recommendations.get("best_track")
    if best:
        lines.append(
            f"*Selected:* {best.title} — _{best.mood} / {best.genre}_\n"
            f"  _{best.note or 'Royalty-free'}_\n"
        )

    # Alternatives
    alts = recommendations.get("alternatives", [])
    if alts:
        lines.append("*Alternatives:*")
        for i, t in enumerate(alts[:3], 1):
            lines.append(f"  {i}. {t.title} ({t.mood})")
        lines.append("")

    # Trending
    trending = recommendations.get("trending", [])
    if trending:
        lines.append(f"*🔥 Trending on {platform.title()}:*")
        for i, t in enumerate(trending[:3], 1):
            title = t.get("title", "Unknown")[:60]
            lines.append(f"  {i}. {title}")
        lines.append("")

    # AI picks
    ai = recommendations.get("ai_picks", [])
    if ai:
        lines.append("*🤖 AI Trending Picks:*")
        for i, pick in enumerate(ai[:3], 1):
            title = pick.get("title", "Unknown")
            artist = pick.get("artist", "")
            search_term = pick.get("search_term", title)
            safe = "✅" if pick.get("is_safe_for_business") else "⚠️"
            lines.append(
                f"  {i}. {safe} *{title}*{' — ' + artist if artist else ''}\n"
                f"     Search: `{search_term}`"
            )
        lines.append("")

    mood = recommendations.get("mood", "upbeat")
    lines.append(f"💡 Mood: _{mood}_ — search `{mood} healthcare` in-app")
    return "\n".join(lines)
