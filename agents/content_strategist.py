"""
Content Strategist Agent — weekly planning, gap detection, priority scoring.

The brain of the marketing operation. This agent:
1. Plans weekly content based on pillar balance and buyer journey
2. Detects content gaps (underserved platforms, missing categories)
3. Scores content priority using the Searchable vs Shareable framework
4. Tracks posting frequency per platform and suggests adjustments
5. Generates a weekly strategy brief with actionable recommendations

Marketing Strategy Knowledge:
- Content Pillar Framework: 30% insights, 25% BTS, 25% educational, 15% personal, 5% promo
- Buyer Journey: Awareness → Consideration → Decision stage routing
- Searchable vs Shareable: SEO-driven vs viral-driven content classification
- 80/20 Rule: Focus on the 20% of content that drives 80% of results
"""

from __future__ import annotations

import json
import logging
from datetime import datetime, timedelta
from typing import Optional

from config.settings import get_settings

logger = logging.getLogger(__name__)


# =============================================================================
# WEEKLY CONTENT PLAN GENERATOR
# =============================================================================

# Content slot templates — what to post each day
WEEKLY_PLAN_TEMPLATE: list[dict] = [
    {
        "day": "Monday",
        "theme": "MedFact Monday",
        "content_type": "educational",
        "buyer_stage": "awareness",
        "description": "Health facts, myth-busting, awareness content",
        "platforms": ["instagram", "tiktok", "linkedin"],
        "effort": "low",
    },
    {
        "day": "Tuesday",
        "theme": "Transformation Tuesday",
        "content_type": "personal",
        "buyer_stage": "consideration",
        "description": "Before/after results, patient stories, testimonials",
        "platforms": ["instagram", "tiktok", "facebook"],
        "effort": "low",
    },
    {
        "day": "Wednesday",
        "theme": "Wellness Wednesday",
        "content_type": "insights",
        "buyer_stage": "awareness",
        "description": "Health tips, prevention advice, wellness motivation",
        "platforms": ["instagram", "facebook", "linkedin"],
        "effort": "medium",
    },
    {
        "day": "Thursday",
        "theme": "Tech Thursday",
        "content_type": "behind_the_scenes",
        "buyer_stage": "consideration",
        "description": "Equipment showcase, clinic technology, expertise demos",
        "platforms": ["instagram", "linkedin", "youtube"],
        "effort": "medium",
    },
    {
        "day": "Friday",
        "theme": "Team Friday",
        "content_type": "behind_the_scenes",
        "buyer_stage": "awareness",
        "description": "Team spotlights, behind the scenes, culture",
        "platforms": ["instagram", "facebook", "linkedin"],
        "effort": "low",
    },
    {
        "day": "Saturday",
        "theme": "Self-Care Saturday",
        "content_type": "insights",
        "buyer_stage": "consideration",
        "description": "Skincare tips, aesthetic services, self-care motivation",
        "platforms": ["instagram", "tiktok", "facebook"],
        "effort": "low",
    },
    {
        "day": "Sunday",
        "theme": "Rest & Reflect",
        "content_type": "personal",
        "buyer_stage": "awareness",
        "description": "Inspirational health quotes, community engagement, light content",
        "platforms": ["instagram", "facebook"],
        "effort": "low",
    },
]


def get_weekly_plan() -> list[dict]:
    """Return the weekly content plan template."""
    return WEEKLY_PLAN_TEMPLATE


def get_today_plan() -> dict | None:
    """Get today's content plan."""
    today = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"]
    day_name = today[datetime.now().weekday()]
    for plan in WEEKLY_PLAN_TEMPLATE:
        if plan["day"] == day_name:
            return plan
    return None


# =============================================================================
# CONTENT GAP DETECTOR
# =============================================================================

def detect_content_gaps(recent_posts: list[dict]) -> dict:
    """
    Analyze recent posts to find content gaps.
    Returns underserved platforms, missing content types, and buyer stages.
    """
    if not recent_posts:
        return {
            "status": "no_data",
            "gaps": ["No posts found — start with Awareness content on Instagram and TikTok"],
            "priority_actions": [
                "Post a before/after Hydrafacial photo on Instagram",
                "Create a 15-sec clinic tour TikTok",
                "Share a health tip on Facebook",
            ],
        }

    # Count posts per platform
    platform_counts: dict[str, int] = {}
    category_counts: dict[str, int] = {}
    stage_counts: dict[str, int] = {}

    for post in recent_posts:
        platform = post.get("platform", "unknown")
        platform_counts[platform] = platform_counts.get(platform, 0) + 1

        category = post.get("content_category", post.get("category", "general"))
        category_counts[category] = category_counts.get(category, 0) + 1

        # Map to buyer stage
        from agents.platform_router import get_buyer_stage
        stage = get_buyer_stage(category)
        stage_counts[stage] = stage_counts.get(stage, 0) + 1

    # Find gaps
    gaps = []
    priority_actions = []

    # Platform gaps
    all_platforms = {"instagram", "facebook", "tiktok", "youtube", "linkedin"}
    posted_platforms = set(platform_counts.keys())
    missing_platforms = all_platforms - posted_platforms
    if missing_platforms:
        gaps.append(f"No posts on: {', '.join(missing_platforms)}")
        for mp in missing_platforms:
            priority_actions.append(f"Create content for {mp.title()}")

    # Low-frequency platforms (posted less than 2x in period)
    for platform, count in platform_counts.items():
        if count < 2 and platform in all_platforms:
            gaps.append(f"{platform.title()} underserved — only {count} post(s)")

    # Buyer stage gaps
    total = sum(stage_counts.values()) or 1
    awareness_pct = stage_counts.get("awareness", 0) / total * 100
    consideration_pct = stage_counts.get("consideration", 0) / total * 100
    decision_pct = stage_counts.get("decision", 0) / total * 100

    if awareness_pct < 20:
        gaps.append("Not enough Awareness content (educational, team, facility)")
        priority_actions.append("Post educational or behind-the-scenes content")
    if consideration_pct < 15:
        gaps.append("Not enough Consideration content (testimonials, service details)")
        priority_actions.append("Share a patient testimonial or service explainer")
    if decision_pct < 5:
        gaps.append("No Decision content (promotions, offers, CTAs)")
        priority_actions.append("Create a promotional package or limited-time offer post")

    return {
        "status": "gaps_found" if gaps else "balanced",
        "platform_distribution": platform_counts,
        "stage_distribution": {
            "awareness": round(awareness_pct),
            "consideration": round(consideration_pct),
            "decision": round(decision_pct),
        },
        "gaps": gaps or ["Content distribution looks healthy!"],
        "priority_actions": priority_actions[:5],
    }


# =============================================================================
# CONTENT PRIORITY SCORER
# =============================================================================

def score_content_priority(
    content_category: str,
    platform: str,
    media_type: str,
    has_hook: bool = False,
    has_cta: bool = False,
    is_trending_format: bool = False,
    is_peak_time: bool = False,
) -> dict:
    """
    Score content priority based on multiple factors.
    Higher score = post this first. 100% rule-based.
    """
    score = 0

    # Platform algorithm weight
    platform_weights = {
        "tiktok": 15,       # Highest organic reach potential
        "instagram": 12,    # Strong for healthcare
        "youtube": 10,      # Evergreen SEO value
        "facebook": 8,      # Community engagement
        "linkedin": 8,      # Professional authority
        "snapchat": 5,      # Limited reach
    }
    score += platform_weights.get(platform, 5)

    # Content type weight (video > image for algorithms)
    if media_type == "video":
        score += 15
    else:
        score += 8

    # High-engagement categories
    category_weights = {
        "before_after": 20,
        "patient_testimonial": 18,
        "hydrafacial": 15,
        "laser_hair_removal": 12,
        "team": 10,
        "facility": 8,
        "promotional": 8,
        "laboratory": 6,
        "opd": 6,
    }
    score += category_weights.get(content_category, 5)

    # Engagement boosters
    if has_hook:
        score += 10
    if has_cta:
        score += 8
    if is_trending_format:
        score += 12
    if is_peak_time:
        score += 8

    # Normalize to 0-100
    max_possible = 15 + 15 + 20 + 10 + 8 + 12 + 8  # 88
    normalized = min(100, round(score / max_possible * 100))

    return {
        "priority_score": normalized,
        "raw_score": score,
        "verdict": (
            "🔥 Post immediately — high potential" if normalized >= 80
            else "✅ Strong content — schedule for peak time" if normalized >= 60
            else "📈 Decent — enhance with better hook/CTA" if normalized >= 40
            else "📝 Consider improving before posting"
        ),
    }


# =============================================================================
# WEEKLY STRATEGY BRIEF GENERATOR
# =============================================================================

async def generate_strategy_brief(
    recent_posts: list[dict] | None = None,
) -> str:
    """
    Generate a comprehensive weekly strategy brief.
    Combines gap analysis, pillar balance, and actionable recommendations.
    """
    from agents.content_recycler import analyze_pillar_balance
    from agents.growth_hacker import suggest_marketing_ideas

    now = datetime.now()

    lines = [
        "📋 *Weekly Content Strategy Brief*",
        f"📅 Week of {now.strftime('%B %d, %Y')}\n",
    ]

    # Content pillar analysis
    pillar_analysis = analyze_pillar_balance(recent_posts or [])
    lines.append("📊 *Content Pillar Balance:*")
    if pillar_analysis["status"] == "no_data":
        lines.append("  ⚠️ No recent posts — start posting to build data!")
    else:
        for pillar, pct in pillar_analysis.get("distribution", {}).items():
            target = pillar_analysis.get("targets", {}).get(pillar, 0)
            icon = "✅" if abs(pct - target) <= 10 else "⚠️"
            lines.append(f"  {icon} {pillar.title()}: {pct}% (target: {target}%)")
    lines.append("")

    # Content gap analysis
    gap_analysis = detect_content_gaps(recent_posts or [])
    lines.append("🔍 *Content Gaps:*")
    for gap in gap_analysis.get("gaps", [])[:3]:
        lines.append(f"  • {gap}")
    lines.append("")

    # Priority actions
    lines.append("🎯 *Priority Actions This Week:*")
    actions = gap_analysis.get("priority_actions", [])
    if not actions:
        actions = ["Continue current strategy — looking good!"]
    for i, action in enumerate(actions[:5], 1):
        lines.append(f"  {i}. {action}")
    lines.append("")

    # Marketing ideas
    lines.append("💡 *Fresh Marketing Ideas:*")
    ideas = suggest_marketing_ideas(count=3)
    for idea in ideas:
        lines.append(f"  • {idea['idea']}")
    lines.append("")

    # Weekly plan
    today_plan = get_today_plan()
    if today_plan:
        lines.append(f"📌 *Today's Focus: {today_plan['theme']}*")
        lines.append(f"  {today_plan['description']}")
        lines.append(f"  Platforms: {', '.join(p.title() for p in today_plan['platforms'])}")

    return "\n".join(lines)


# =============================================================================
# CONTENT CALENDAR AUTO-FILL
# =============================================================================

async def auto_fill_calendar(
    business_id: int,
    days_ahead: int = 7,
    themes: list[str] | None = None,
) -> list[dict]:
    """Auto-generate a content calendar for the next N days.

    Uses GPT to produce a varied, platform-aware posting plan tailored to the
    business's industry and brand voice.

    Returns:
        [{"date": "2025-01-15", "platform": "instagram", "content_type": "photo",
          "topic": "Behind the scenes", "suggested_time": "10:00", "notes": "..."}, ...]
    """
    from openai import AsyncOpenAI
    from memory.database import load_business_context
    from security.prompt_guard import sanitize_for_llm
    from services.ai_usage import track_openai_usage

    settings = get_settings()
    client = AsyncOpenAI(api_key=settings.openai_api_key)

    # Load business context
    ctx = await load_business_context(business_id)
    biz_name = ctx.get("name", "the business")
    industry = ctx.get("industry", "general")
    voice = ctx.get("brand_voice", "professional and friendly")

    days_ahead = max(1, min(days_ahead, 30))  # Clamp 1-30

    # Build date range
    start_date = datetime.now() + timedelta(days=1)
    date_strs = [(start_date + timedelta(days=i)).strftime("%Y-%m-%d")
                 for i in range(days_ahead)]

    # Sanitize optional themes
    safe_themes = ""
    if themes:
        safe_themes = ", ".join(sanitize_for_llm(t, max_length=200) for t in themes[:10])

    system_prompt = f"""You are a social-media content strategist for {biz_name} ({industry} industry).
Brand voice: {voice}.
You create practical, varied content calendars."""

    user_prompt = f"""Generate a content calendar for the following dates:
{chr(10).join(date_strs)}

Rules:
- Cover these platforms: Instagram, Facebook, TikTok, LinkedIn, YouTube (pick 1-2 per day).
- Vary content types across: photo, video, carousel, story, reel.
- Suggest an optimal posting time per entry (HH:MM, 24-h format) based on platform best practices.
- Each entry should have a unique, specific topic relevant to the {industry} industry.
{f'- Incorporate these themes where appropriate: {safe_themes}' if safe_themes else ''}
- Include brief notes with creative direction or tips.

Return a JSON object with a "calendar" array. Each element must have:
- "date": "{date_strs[0]}" format
- "platform": lowercase platform name
- "content_type": one of photo, video, carousel, story, reel
- "topic": a concise topic title
- "suggested_time": "HH:MM"
- "notes": 1-2 sentence creative direction

Generate at least one entry per date. Aim for {min(days_ahead * 2, 30)} total entries.
"""

    try:
        response = await client.chat.completions.create(
            model="gpt-4o-mini",
            messages=[
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_prompt},
            ],
            response_format={"type": "json_object"},
            temperature=0.8,
            max_tokens=1500,
        )
        try:
            await track_openai_usage(response, business_id, "content_strategist", "auto_fill_calendar")
        except Exception:
            pass

        result = json.loads(response.choices[0].message.content)
        calendar = result.get("calendar", [])

        # Normalize and validate entries
        cleaned: list[dict] = []
        valid_types = {"photo", "video", "carousel", "story", "reel"}
        valid_platforms = {"instagram", "facebook", "tiktok", "linkedin", "youtube",
                          "twitter", "snapchat", "pinterest", "threads"}
        for entry in calendar:
            platform = entry.get("platform", "instagram").lower()
            content_type = entry.get("content_type", "photo").lower()
            cleaned.append({
                "date": entry.get("date", date_strs[0]),
                "platform": platform if platform in valid_platforms else "instagram",
                "content_type": content_type if content_type in valid_types else "photo",
                "topic": entry.get("topic", "General content"),
                "suggested_time": entry.get("suggested_time", "12:00"),
                "notes": entry.get("notes", ""),
            })
        return cleaned

    except Exception as e:
        logger.error(f"Auto-fill calendar generation failed: {e}")
        # Return a minimal fallback calendar
        fallback: list[dict] = []
        platforms_cycle = ["instagram", "facebook", "tiktok", "linkedin", "youtube"]
        types_cycle = ["photo", "video", "carousel", "story", "reel"]
        for i, d in enumerate(date_strs):
            fallback.append({
                "date": d,
                "platform": platforms_cycle[i % len(platforms_cycle)],
                "content_type": types_cycle[i % len(types_cycle)],
                "topic": f"{industry.title()} content",
                "suggested_time": "12:00",
                "notes": "Auto-generated fallback — customize as needed.",
            })
        return fallback
