"""
Platform Router — RULE-BASED, zero token cost.

Decides which platforms each piece of media should be posted to,
based on dimensions, duration, content category, recent posting history,
platform algorithm intelligence, and buyer journey stage.

Marketing Strategy Knowledge:
- Platform algorithm awareness (Reels 2x reach, LinkedIn first-hour, TikTok hook timing)
- Buyer-stage routing (Awareness → Consideration → Decision)
- Content-type optimization (Searchable vs Shareable framework)
"""

from __future__ import annotations

from typing import Optional

from agents.schemas import MediaAnalysis, PlatformSpec

# ── Platform specifications ───────────────────────────────────────────────────

PLATFORM_SPECS: dict[str, PlatformSpec] = {
    "instagram": PlatformSpec(
        platform="instagram",
        width=1080,
        height=1080,
        aspect_ratio="1:1",
        caption_max_length=2200,
        max_hashtags=20,
    ),
    "instagram_reels": PlatformSpec(
        platform="instagram",
        width=1080,
        height=1920,
        max_duration_seconds=90,
        aspect_ratio="9:16",
        caption_max_length=2200,
        max_hashtags=20,
    ),
    "instagram_stories": PlatformSpec(
        platform="instagram",
        width=1080,
        height=1920,
        max_duration_seconds=60,
        aspect_ratio="9:16",
        caption_max_length=None,
        max_hashtags=10,
    ),
    "facebook": PlatformSpec(
        platform="facebook",
        width=1200,
        height=630,
        aspect_ratio="16:9",
        caption_max_length=63206,
        max_hashtags=3,
    ),
    "youtube": PlatformSpec(
        platform="youtube",
        width=1920,
        height=1080,
        aspect_ratio="16:9",
        format="mp4",
        caption_max_length=5000,
        max_hashtags=15,
    ),
    "youtube_shorts": PlatformSpec(
        platform="youtube",
        width=1080,
        height=1920,
        max_duration_seconds=60,
        aspect_ratio="9:16",
        caption_max_length=100,
        max_hashtags=15,
    ),
    "linkedin": PlatformSpec(
        platform="linkedin",
        width=1200,
        height=627,
        aspect_ratio="16:9",
        caption_max_length=3000,
        max_hashtags=5,
    ),
    "tiktok": PlatformSpec(
        platform="tiktok",
        width=1080,
        height=1920,
        max_duration_seconds=600,
        aspect_ratio="9:16",
        caption_max_length=2200,
        max_hashtags=5,
    ),
    "snapchat": PlatformSpec(
        platform="snapchat",
        width=1080,
        height=1920,
        max_duration_seconds=60,
        aspect_ratio="9:16",
        caption_max_length=250,
        max_hashtags=0,
        supports_hashtags=False,
    ),
}


# ── Content category → platform affinity mapping ─────────────────────────────

# Higher score = better fit. Scale 0-10.
CATEGORY_PLATFORM_AFFINITY: dict[str, dict[str, float]] = {
    "opd": {
        "instagram": 6, "facebook": 8, "linkedin": 7,
        "youtube": 5, "tiktok": 4, "snapchat": 3,
    },
    "laboratory": {
        "instagram": 5, "facebook": 7, "linkedin": 6,
        "youtube": 6, "tiktok": 3, "snapchat": 2,
    },
    "hydrafacial": {
        "instagram": 10, "facebook": 7, "linkedin": 3,
        "youtube": 6, "tiktok": 9, "snapchat": 7,
    },
    "laser_hair_removal": {
        "instagram": 9, "facebook": 7, "linkedin": 3,
        "youtube": 6, "tiktok": 8, "snapchat": 6,
    },
    "xray": {
        "instagram": 4, "facebook": 6, "linkedin": 7,
        "youtube": 7, "tiktok": 3, "snapchat": 2,
    },
    "ultrasound_echo": {
        "instagram": 5, "facebook": 7, "linkedin": 7,
        "youtube": 8, "tiktok": 4, "snapchat": 2,
    },
    "ecg": {
        "instagram": 5, "facebook": 6, "linkedin": 7,
        "youtube": 7, "tiktok": 4, "snapchat": 2,
    },
    "pharmacy": {
        "instagram": 5, "facebook": 7, "linkedin": 4,
        "youtube": 4, "tiktok": 3, "snapchat": 3,
    },
    "team": {
        "instagram": 7, "facebook": 8, "linkedin": 10,
        "youtube": 5, "tiktok": 5, "snapchat": 4,
    },
    "facility": {
        "instagram": 7, "facebook": 8, "linkedin": 8,
        "youtube": 7, "tiktok": 5, "snapchat": 4,
    },
    "patient_testimonial": {
        "instagram": 7, "facebook": 9, "linkedin": 6,
        "youtube": 9, "tiktok": 5, "snapchat": 3,
    },
    "before_after": {
        "instagram": 10, "facebook": 8, "linkedin": 3,
        "youtube": 6, "tiktok": 9, "snapchat": 7,
    },
    "promotional": {
        "instagram": 9, "facebook": 9, "linkedin": 5,
        "youtube": 5, "tiktok": 7, "snapchat": 6,
    },
    "job_posting": {
        "instagram": 5, "facebook": 7, "linkedin": 10,
        "youtube": 2, "tiktok": 3, "snapchat": 1,
    },
    "general": {
        "instagram": 7, "facebook": 7, "linkedin": 5,
        "youtube": 5, "tiktok": 5, "snapchat": 4,
    },
}


def route_media(
    media_type: str,
    width: int,
    height: int,
    duration_seconds: float,
    analysis: MediaAnalysis,
    min_affinity: float = 5.0,
) -> list[str]:
    """
    Decide which platforms to target.

    Returns a list of platform keys (e.g., ["instagram", "facebook", "tiktok"]).
    Logic is purely rule-based — zero LLM tokens used.
    """
    is_vert = height > width
    is_horiz = width > height
    is_video = media_type == "video"
    is_short_video = is_video and duration_seconds <= 60
    is_medium_video = is_video and 60 < duration_seconds <= 90
    is_long_video = is_video and duration_seconds > 90

    category = analysis.content_category
    affinity = CATEGORY_PLATFORM_AFFINITY.get(category, CATEGORY_PLATFORM_AFFINITY["general"])

    platforms: list[str] = []

    # ── Video routing ─────────────────────────────────────────────────────
    if is_video:
        if is_vert and is_short_video:
            # Perfect for short-form vertical: TikTok, Reels, Shorts, Snapchat
            if affinity.get("tiktok", 0) >= min_affinity:
                platforms.append("tiktok")
            if affinity.get("instagram", 0) >= min_affinity:
                platforms.append("instagram")  # will be formatted as Reels
            if affinity.get("youtube", 0) >= min_affinity:
                platforms.append("youtube")  # will be formatted as Shorts
            if affinity.get("snapchat", 0) >= min_affinity:
                platforms.append("snapchat")
            if affinity.get("facebook", 0) >= min_affinity:
                platforms.append("facebook")

        elif is_vert and is_medium_video:
            # Reels support up to 90s, TikTok up to 10min
            if affinity.get("tiktok", 0) >= min_affinity:
                platforms.append("tiktok")
            if affinity.get("instagram", 0) >= min_affinity:
                platforms.append("instagram")
            if affinity.get("facebook", 0) >= min_affinity:
                platforms.append("facebook")

        elif is_horiz and is_short_video:
            # Horizontal short — YouTube, Facebook, LinkedIn
            if affinity.get("youtube", 0) >= min_affinity:
                platforms.append("youtube")
            if affinity.get("facebook", 0) >= min_affinity:
                platforms.append("facebook")
            if affinity.get("linkedin", 0) >= min_affinity:
                platforms.append("linkedin")

        elif is_long_video:
            # Long video — YouTube primary, LinkedIn and Facebook secondary
            if affinity.get("youtube", 0) >= 3:  # lower threshold for YouTube with long content
                platforms.append("youtube")
            if affinity.get("linkedin", 0) >= min_affinity:
                platforms.append("linkedin")
            if affinity.get("facebook", 0) >= min_affinity:
                platforms.append("facebook")

        else:
            # Fallback for other video dimensions
            for p in ["youtube", "facebook", "instagram", "tiktok", "linkedin"]:
                if affinity.get(p, 0) >= min_affinity:
                    platforms.append(p)

    # ── Photo routing ─────────────────────────────────────────────────────
    else:
        # Photos go to most platforms
        for p in ["instagram", "facebook", "linkedin", "tiktok", "snapchat"]:
            if affinity.get(p, 0) >= min_affinity:
                platforms.append(p)

    # Always ensure at least one platform
    if not platforms:
        platforms = ["instagram", "facebook"]

    # Deduplicate while preserving order
    seen = set()
    unique = []
    for p in platforms:
        if p not in seen:
            seen.add(p)
            unique.append(p)

    return unique


def get_platform_spec(platform: str, media_type: str, is_vertical: bool) -> PlatformSpec:
    """Get the appropriate spec for a platform, considering media type and orientation."""
    if platform == "instagram" and media_type == "video" and is_vertical:
        return PLATFORM_SPECS["instagram_reels"]
    if platform == "youtube" and media_type == "video" and is_vertical:
        return PLATFORM_SPECS["youtube_shorts"]
    return PLATFORM_SPECS.get(platform, PLATFORM_SPECS["instagram"])


# ── Platform Algorithm Intelligence ──────────────────────────────────────────
# Zero-cost rules that boost organic reach per platform

ALGORITHM_TIPS: dict[str, dict] = {
    "instagram": {
        "boost_factors": [
            "Reels get 2x reach vs static posts — prefer video when possible",
            "Saves and shares weigh more than likes in the algorithm",
            "Carousel posts get 3x engagement — use for educational content",
            "Reply to comments within 1 hour for algorithm boost",
            "Post during peak hours and engage for 15 min after posting",
        ],
        "hook_window": "First line of caption — makes or breaks engagement",
        "ideal_format": "Reels (9:16) for reach, Carousels for engagement, Stories for retention",
    },
    "tiktok": {
        "boost_factors": [
            "Hook must land in first 1-2 seconds — or viewers scroll past",
            "Watch time is #1 ranking factor — keep it compelling throughout",
            "Trending sounds give 30-50% algorithm boost",
            "Reply to comments with video — creates engagement loops",
            "Post 1-3x daily for best algorithm treatment",
        ],
        "hook_window": "First 1-2 seconds of video",
        "ideal_format": "15-30 second vertical video with trending sound",
    },
    "youtube": {
        "boost_factors": [
            "First 48 hours CTR determines lifetime reach",
            "Longer watch time = higher ranking (aim for 50%+ retention)",
            "Shorts feed is separate from main — both are valuable",
            "SEO in title + description ranks on Google Search forever",
            "End screens and cards drive subscriber growth",
        ],
        "hook_window": "First 30 seconds + thumbnail",
        "ideal_format": "Long-form (8-12 min) for watch time, Shorts for discovery",
    },
    "linkedin": {
        "boost_factors": [
            "First-hour engagement determines total reach (post when active)",
            "Document/carousel posts get 3x reach vs text-only",
            "Links in post body get penalized — put links in first comment",
            "Comment on others' posts before and after your own post",
            "Dwell time matters — longer posts with line breaks perform well",
        ],
        "hook_window": "First 2 lines before 'See more' cutoff",
        "ideal_format": "Document/PDF carousel or long-form text with line breaks",
    },
    "facebook": {
        "boost_factors": [
            "Native content wins — external links get reduced reach",
            "Comments and meaningful interactions boost reach most",
            "Groups content gets 5x more reach than Page posts",
            "Facebook Reels are getting heavy algorithm push (2024-2025)",
            "Ask questions to drive comment threads",
        ],
        "hook_window": "First 2 lines before 'See more'",
        "ideal_format": "Native video, Reels, or discussion-provoking text posts",
    },
    "snapchat": {
        "boost_factors": [
            "Consistency is key — daily posts keep you in rotation",
            "Spotlight favors authentic, unpolished content",
            "Use trending sounds and lenses for discoverability",
        ],
        "hook_window": "Instant — swipe-away is immediate",
        "ideal_format": "Vertical video, 10-30 seconds, authentic feel",
    },
}


def get_algorithm_tips(platform: str) -> dict:
    """Get platform-specific algorithm intelligence for maximizing reach."""
    return ALGORITHM_TIPS.get(platform, ALGORITHM_TIPS["instagram"])


# ── Buyer Journey Stage Routing ──────────────────────────────────────────────
# Maps content categories to the buyer journey stage they serve

BUYER_STAGE_MAP: dict[str, str] = {
    # Awareness — top of funnel, casting wide net
    "general": "awareness",
    "facility": "awareness",
    "team": "awareness",
    "before_after": "awareness",
    # Consideration — middle of funnel, building trust
    "opd": "consideration",
    "laboratory": "consideration",
    "patient_testimonial": "consideration",
    "hydrafacial": "consideration",
    "laser_hair_removal": "consideration",
    "xray": "consideration",
    "ultrasound_echo": "consideration",
    "ecg": "consideration",
    # Decision — bottom of funnel, driving action
    "promotional": "decision",
    "pharmacy": "decision",
    "job_posting": "decision",
}

# Which platforms are best for each buyer stage
STAGE_PLATFORM_BOOST: dict[str, dict[str, float]] = {
    "awareness": {
        "tiktok": 2.0, "instagram": 1.5, "youtube": 1.5,
        "facebook": 1.0, "snapchat": 1.0, "linkedin": 0.8,
    },
    "consideration": {
        "youtube": 2.0, "instagram": 1.5, "facebook": 1.5,
        "linkedin": 1.5, "tiktok": 0.8, "snapchat": 0.5,
    },
    "decision": {
        "facebook": 2.0, "instagram": 1.5, "linkedin": 1.0,
        "youtube": 0.8, "tiktok": 0.5, "snapchat": 0.5,
    },
}


def get_buyer_stage(content_category: str) -> str:
    """Determine the buyer journey stage for a content category."""
    return BUYER_STAGE_MAP.get(content_category, "awareness")


def get_routing_context(content_category: str, platforms: list[str]) -> dict:
    """
    Get marketing intelligence context for routed platforms.
    Used by caption writer and quality gate for smarter content.
    """
    stage = get_buyer_stage(content_category)
    return {
        "buyer_stage": stage,
        "platforms": {
            p: {
                "algorithm_tips": ALGORITHM_TIPS.get(p, {}).get("boost_factors", [])[:2],
                "hook_window": ALGORITHM_TIPS.get(p, {}).get("hook_window", ""),
                "ideal_format": ALGORITHM_TIPS.get(p, {}).get("ideal_format", ""),
            }
            for p in platforms
        },
        "stage_guidance": {
            "awareness": "Focus on reach — entertaining, educational, shareable content",
            "consideration": "Focus on trust — testimonials, expertise, detailed info",
            "decision": "Focus on action — clear CTAs, offers, urgency",
        }.get(stage, ""),
    }
