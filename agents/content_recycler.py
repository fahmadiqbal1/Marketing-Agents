"""
Content Recycler Agent — get 6x more out of every piece of content.

The most underrated growth strategy: repurposing old content.
90% of your audience didn't see your best posts. This agent:

1. Finds your top-performing content for repurposing
2. Transforms content into new formats for different platforms
3. Schedules reposts at staggered times for maximum reach
4. Creates variations (different hooks, CTAs, captions)
5. Tracks content pillar allocation (30/25/25/15/5 rule)
6. Generates strategic repurposing chains

Marketing Strategy Knowledge:
- Content Pillar Framework: 30% insights, 25% BTS, 25% educational, 15% personal, 5% promo
- Repurpose Chain: Blog → Social thread → Carousel → Reel → Shorts → Stories
- Searchable vs Shareable framework for content categorization
"""

from __future__ import annotations

import json
import logging
from datetime import datetime, timedelta
from typing import Optional

from config.settings import get_settings
from security.prompt_guard import sanitize_for_llm
from services.ai_usage import track_openai_usage

logger = logging.getLogger(__name__)


# =============================================================================
# CONTENT REPURPOSING MATRIX
# =============================================================================

# For every original format, here's what we can create from it
REPURPOSE_MAP: dict[str, list[dict]] = {
    "instagram_post": [
        {
            "target": "facebook_post",
            "transform": "direct_repost",
            "description": "Share as Facebook Page post (slightly different caption)",
        },
        {
            "target": "linkedin_post",
            "transform": "professional_rewrite",
            "description": "Rewrite caption for professional audience",
        },
        {
            "target": "whatsapp_status",
            "transform": "status_image",
            "description": "Resize to 9:16 with branding for WhatsApp Status",
        },
        {
            "target": "google_my_business",
            "transform": "gmb_post",
            "description": "Generate Google My Business update from the same content",
        },
    ],
    "instagram_reel": [
        {
            "target": "tiktok",
            "transform": "direct_repost",
            "description": "Post as TikTok (replace watermark if present)",
        },
        {
            "target": "youtube_short",
            "transform": "direct_repost",
            "description": "Upload as YouTube Short (free Google SEO!)",
        },
        {
            "target": "facebook_reel",
            "transform": "direct_repost",
            "description": "Post as Facebook Reel",
        },
        {
            "target": "whatsapp_status",
            "transform": "trim_15sec",
            "description": "Trim to 15 seconds for WhatsApp Status",
        },
    ],
    "tiktok_video": [
        {
            "target": "instagram_reel",
            "transform": "remove_watermark",
            "description": "Post as Instagram Reel (without TikTok watermark)",
        },
        {
            "target": "youtube_short",
            "transform": "direct_repost",
            "description": "Upload as YouTube Short",
        },
        {
            "target": "facebook_reel",
            "transform": "direct_repost",
            "description": "Post as Facebook Reel",
        },
    ],
    "youtube_video": [
        {
            "target": "instagram_carousel",
            "transform": "key_frames",
            "description": "Extract 5-10 key frames as image carousel",
        },
        {
            "target": "tiktok",
            "transform": "highlight_clip",
            "description": "Extract best 30-60 sec clip for TikTok",
        },
        {
            "target": "linkedin_article",
            "transform": "transcript_blog",
            "description": "Transcribe and turn into LinkedIn article",
        },
        {
            "target": "instagram_reel",
            "transform": "highlight_clip",
            "description": "Extract best 30-60 sec for Instagram Reel",
        },
    ],
    "fb_post": [
        {
            "target": "instagram_post",
            "transform": "direct_repost",
            "description": "Post on Instagram with adapted caption",
        },
        {
            "target": "linkedin_post",
            "transform": "professional_rewrite",
            "description": "Rewrite for LinkedIn",
        },
    ],
}


def get_repurpose_plan(source_format: str) -> list[dict]:
    """Get all platform repurposing options for a given content format."""
    return REPURPOSE_MAP.get(source_format, [])


# =============================================================================
# CONTENT VARIATION GENERATOR
# =============================================================================


async def create_content_variation(
    original_caption: str,
    original_platform: str,
    target_platform: str,
) -> str:
    """
    Rewrite a caption for a different platform.
    Uses GPT-4o-mini — ~100 tokens per variation.
    """
    settings = get_settings()
    if not settings.openai_api_key:
        return original_caption  # Fallback: use same caption

    from openai import AsyncOpenAI
    client = AsyncOpenAI(api_key=settings.openai_api_key)

    platform_rules = {
        "instagram": "Use emojis, line breaks, hashtags at end. 150-200 words.",
        "tiktok": "Very short and snappy. 10-50 words, trendy language.",
        "linkedin": "Professional, thoughtful, no hashtags in body. 100-200 words.",
        "facebook": "Conversational and warm. 50-150 words. Ask a question.",
        "youtube": "SEO-friendly title and description. Include keywords.",
        "whatsapp": "Short, direct, personal. 20-50 words. Include contact info.",
    }

    response = await client.chat.completions.create(
        model="gpt-4o-mini",
        messages=[
            {
                "role": "system",
                "content": (
                    "You rewrite social media captions for the business. "
                    "Adapt the tone and format "
                    f"for {target_platform}.\n\n"
                    f"Rules for {target_platform}: {platform_rules.get(target_platform, '')}\n\n"
                    "Keep the core message but change the presentation. "
                    "Don't just copy — truly adapt the content."
                ),
            },
            {
                "role": "user",
                "content": (
                    f"Original ({original_platform}) caption:\n"
                    f"{sanitize_for_llm(original_caption)}\n\n"
                    f"Rewrite this for {target_platform}:"
                ),
            },
        ],
        temperature=0.8,
        max_tokens=300,
    )

    try:
        await track_openai_usage(response, 0, "content_recycler", "create_content_variation")
    except Exception:
        pass

    return response.choices[0].message.content.strip()


# =============================================================================
# BEST-PERFORMING CONTENT FINDER
# =============================================================================


async def get_top_performing_posts(
    days: int = 30,
    limit: int = 5,
) -> list[dict]:
    """
    Find the best-performing posts from the database.
    These are candidates for repurposing.
    """
    from memory.database import async_session
    from sqlalchemy import text

    since = datetime.utcnow() - timedelta(days=days)

    async with async_session() as session:
        result = await session.execute(
            text(
                "SELECT id, platform, caption, media_type, media_path, "
                "posted_at, status "
                "FROM posts "
                "WHERE posted_at >= :since AND status = 'published' "
                "ORDER BY posted_at DESC "
                "LIMIT :limit"
            ),
            {"since": since, "limit": limit},
        )
        rows = result.fetchall()

    posts = []
    for row in rows:
        posts.append({
            "id": row[0],
            "platform": row[1],
            "caption": row[2],
            "media_type": row[3],
            "media_path": row[4],
            "posted_at": str(row[5]),
        })

    return posts


# =============================================================================
# EVERGREEN CONTENT CALENDAR
# =============================================================================

# Posts that can be recycled every month/quarter
EVERGREEN_TEMPLATES: list[dict] = [
    {
        "title": "Services Overview",
        "caption_seed": (
            "Did you know? We offer ALL these services under one roof:\n\n"
            "🩺 OPD Consultations\n"
            "🔬 Laboratory Tests\n"
            "✨ HydraFacial\n"
            "💫 Laser Hair Removal\n"
            "🦴 Digital X-Ray\n"
            "❤️ Ultrasound & Echo\n"
            "💓 ECG\n"
            "💊 Pharmacy\n\n"
            "All in one place, all affordable! 📍 Visit us today."
        ),
        "frequency_days": 30,
        "platforms": ["instagram", "facebook", "whatsapp"],
        "category": "promotional",
    },
    {
        "title": "Why Choose Us",
        "caption_seed": (
            "Why families trust our healthcare team:\n\n"
            "✅ Modern equipment\n"
            "✅ Experienced doctors\n"
            "✅ Affordable prices\n"
            "✅ Clean & comfortable facility\n"
            "✅ Quick lab results\n"
            "✅ One-stop healthcare\n\n"
            "📞 Book your appointment today!"
        ),
        "frequency_days": 21,
        "platforms": ["instagram", "facebook", "linkedin"],
        "category": "promotional",
    },
    {
        "title": "HydraFacial Spotlight",
        "caption_seed": (
            "Get that glow ✨\n\n"
            "Our HydraFacial treatment:\n"
            "• Deep cleanses your skin\n"
            "• Instant hydration & glow\n"
            "• Painless — 30 min session\n"
            "• Visible results from day 1\n\n"
            "📱 DM us to book your session!"
        ),
        "frequency_days": 14,
        "platforms": ["instagram", "tiktok", "facebook"],
        "category": "hydrafacial",
    },
    {
        "title": "Lab Test Reminder",
        "caption_seed": (
            "When was your last health checkup? 🤔\n\n"
            "Regular lab tests can detect problems early:\n"
            "🔬 Blood sugar — Diabetes\n"
            "🔬 Thyroid panel — Thyroid disorders\n"
            "🔬 Lipid profile — Heart risk\n"
            "🔬 CBC — Overall health\n\n"
            "Prevention is better than cure! 💪\n"
            "Walk-in lab tests available, no appointment needed."
        ),
        "frequency_days": 21,
        "platforms": ["instagram", "facebook", "linkedin"],
        "category": "laboratory",
    },
]


def get_due_evergreen_content(posted_history: list[dict] | None = None) -> list[dict]:
    """
    Check which evergreen content templates are due for reposting.
    Returns templates that should be posted based on frequency.
    """
    now = datetime.now()
    due = []

    for template in EVERGREEN_TEMPLATES:
        # Find last time this was posted
        last_posted = None
        if posted_history:
            for post in posted_history:
                if template["title"].lower() in (post.get("caption", "") or "").lower():
                    post_date = post.get("posted_at")
                    if post_date and (not last_posted or post_date > last_posted):
                        last_posted = post_date

        if last_posted is None:
            due.append(template)
        else:
            if isinstance(last_posted, str):
                last_posted = datetime.fromisoformat(last_posted)
            days_since = (now - last_posted).days
            if days_since >= template["frequency_days"]:
                due.append(template)

    return due


# =============================================================================
# CROSS-PLATFORM POST SCHEDULER
# =============================================================================


def create_cross_post_schedule(
    original_platform: str,
    original_post_time: datetime,
) -> list[dict]:
    """
    Create a staggered schedule for cross-posting to other platforms.

    Strategy: Don't post everywhere at once!
    Stagger posts for algorithm-friendly behaviour and maximum total reach.
    """
    from agents.growth_hacker import get_next_optimal_time

    schedule = []
    repurpose_options = get_repurpose_plan(f"{original_platform}_post")

    if not repurpose_options:
        # Try reel/video format
        repurpose_options = get_repurpose_plan(f"{original_platform}_reel")

    for i, option in enumerate(repurpose_options):
        target = option["target"].split("_")[0]  # e.g., "facebook" from "facebook_post"

        if target == original_platform:
            continue

        optimal_time = get_next_optimal_time(target)

        # Ensure at least 2 hours between posts
        min_time = original_post_time + timedelta(hours=2 * (i + 1))
        if optimal_time < min_time:
            optimal_time = min_time

        schedule.append({
            "platform": target,
            "scheduled_time": optimal_time,
            "transform": option["transform"],
            "description": option["description"],
        })

    return schedule


# =============================================================================
# CONTENT SERIES TRACKER
# =============================================================================


def suggest_next_in_series(
    series_name: str,
    episode_count: int,
) -> dict:
    """
    Suggest the next post in a content series.
    Builds momentum and grows followers who expect regular content.
    """
    series_configs = {
        "Wellness Wednesday": {
            "topics": [
                "Water intake tips — how much you really need",
                "5 foods that boost immunity naturally",
                "Signs you need a health checkup",
                "Sleep hygiene — the foundation of health",
                "Stress management techniques that actually work",
                "Heart health — what your ECG tells you",
                "Skin health — habits that make a difference",
                "Blood sugar management tips",
                "Eye health in the screen age",
                "Dental hygiene and overall health",
            ],
        },
        "Transformation Tuesday": {
            "topics": [
                "HydraFacial before/after — instant glow",
                "Laser hair removal progress — session 1 vs 6",
                "Skin texture transformation",
                "Dark circles treatment results",
                "Acne scars improvement journey",
            ],
        },
        "Tech Thursday": {
            "topics": [
                "How our digital X-ray works",
                "Inside our laboratory — modern equipment",
                "Echocardiography explained simply",
                "ECG — reading your heart's electrical signals",
                "Ultrasound technology at our clinic",
            ],
        },
        "MedFact Monday": {
            "topics": [
                "MYTH: Cracking knuckles causes arthritis",
                "FACT: Your body makes 3.8 million cells per second",
                "MYTH: Cold weather causes colds",
                "FACT: Your heart beats 100,000 times per day",
                "MYTH: You only use 10% of your brain",
            ],
        },
    }

    config = series_configs.get(series_name, {"topics": ["General health tip"]})
    topic_index = episode_count % len(config["topics"])
    topic = config["topics"][topic_index]

    return {
        "series": series_name,
        "episode": episode_count + 1,
        "suggested_topic": topic,
        "caption_prefix": f"📺 {series_name} — Episode {episode_count + 1}",
    }


# =============================================================================
# CONTENT PILLAR TRACKER
# =============================================================================

# Ideal content mix ratios (from marketing strategy research)
CONTENT_PILLARS: dict[str, dict] = {
    "insights": {
        "target_pct": 30,
        "description": "Industry insights, health tips, expert opinions",
        "categories": ["opd", "ecg", "laboratory", "xray", "ultrasound_echo"],
    },
    "behind_the_scenes": {
        "target_pct": 25,
        "description": "Clinic life, team stories, day-in-the-life, equipment",
        "categories": ["team", "facility"],
    },
    "educational": {
        "target_pct": 25,
        "description": "How-tos, myth-busting, explainers, health awareness",
        "categories": ["general"],
    },
    "personal": {
        "target_pct": 15,
        "description": "Patient stories, testimonials, community engagement",
        "categories": ["patient_testimonial", "before_after"],
    },
    "promotional": {
        "target_pct": 5,
        "description": "Offers, packages, calls-to-action, job postings",
        "categories": ["promotional", "pharmacy", "hydrafacial", "laser_hair_removal", "job_posting"],
    },
}


def get_content_pillar(content_category: str) -> str:
    """Map a content category to its content pillar."""
    for pillar, info in CONTENT_PILLARS.items():
        if content_category in info["categories"]:
            return pillar
    return "educational"  # Default


def analyze_pillar_balance(recent_posts: list[dict]) -> dict:
    """
    Analyze the content pillar balance of recent posts.
    Returns current distribution vs target, with recommendations.
    """
    if not recent_posts:
        return {
            "status": "no_data",
            "recommendation": "Start posting! Aim for 30% insights, 25% BTS, 25% educational, 15% personal, 5% promo",
        }

    # Count posts per pillar
    pillar_counts = {p: 0 for p in CONTENT_PILLARS}
    for post in recent_posts:
        category = post.get("content_category", post.get("category", "general"))
        pillar = get_content_pillar(category)
        pillar_counts[pillar] += 1

    total = sum(pillar_counts.values()) or 1
    distribution = {p: round(c / total * 100) for p, c in pillar_counts.items()}

    # Find imbalances
    recommendations = []
    for pillar, info in CONTENT_PILLARS.items():
        actual = distribution.get(pillar, 0)
        target = info["target_pct"]
        diff = actual - target
        if diff > 10:
            recommendations.append(f"Too much {pillar} content ({actual}% vs {target}% target) — reduce")
        elif diff < -10:
            recommendations.append(f"Not enough {pillar} content ({actual}% vs {target}% target) — increase")

    return {
        "distribution": distribution,
        "targets": {p: info["target_pct"] for p, info in CONTENT_PILLARS.items()},
        "pillar_counts": pillar_counts,
        "total_posts": total,
        "recommendations": recommendations or ["Content mix looks healthy!"],
        "status": "balanced" if not recommendations else "imbalanced",
    }


# =============================================================================
# SEARCHABLE vs SHAREABLE FRAMEWORK
# =============================================================================

def classify_content_type(content_category: str, platform: str) -> dict:
    """
    Classify content as Searchable (SEO/discovery) or Shareable (viral/engagement).
    Different platforms favor different types.
    """
    # Searchable: optimized for search/discovery (evergreen, SEO)
    # Shareable: optimized for sharing/virality (trending, emotional)
    searchable_categories = {"laboratory", "opd", "xray", "ultrasound_echo", "ecg", "pharmacy"}
    shareable_categories = {"hydrafacial", "laser_hair_removal", "before_after",
                           "patient_testimonial", "team", "promotional"}

    search_platforms = {"youtube", "linkedin", "google"}
    share_platforms = {"tiktok", "instagram", "snapchat"}

    is_searchable = content_category in searchable_categories or platform in search_platforms
    is_shareable = content_category in shareable_categories or platform in share_platforms

    if is_searchable and not is_shareable:
        content_type = "searchable"
        tips = [
            "Include target keywords naturally in caption",
            "Use long-tail phrases people actually search for",
            "Write a detailed description with location tags",
        ]
    elif is_shareable and not is_searchable:
        content_type = "shareable"
        tips = [
            "Lead with emotion — surprise, curiosity, or delight",
            "Make it easy to share (relatable, tag-worthy)",
            "Use trending formats and sounds when possible",
        ]
    else:
        content_type = "hybrid"
        tips = [
            "Balance keywords with emotional hooks",
            "Searchable title + shareable thumbnail/hook",
        ]

    return {"type": content_type, "tips": tips}
