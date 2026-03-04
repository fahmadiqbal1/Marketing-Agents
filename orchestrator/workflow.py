"""
LangGraph Orchestrator — the central workflow that connects all agents.

Workflow: analyze → route → edit → music → caption → quality_gate → preview → (approval) → publish

Uses LangGraph's graph-based orchestration with:
- MySQL checkpointing (survives restarts)
- Human-in-the-loop (Telegram approval before publishing)
- Quality gate (auto-validates captions with BJ Fogg psychology scoring)
- Conditional routing (skip steps when not needed)
"""

from __future__ import annotations

import json
import uuid
from datetime import datetime
from typing import Annotated, Any, TypedDict

from langgraph.graph import StateGraph, START, END
from langgraph.graph.message import add_messages
from langgraph.checkpoint.mysql.aio import AIOMySQLSaver

from config.settings import get_settings
from agents.schemas import (
    MediaAnalysis,
    CaptionRequest,
    CaptionResult,
    EditInstructions,
    WorkflowState,
)


# ── Workflow State Definition ─────────────────────────────────────────────────


class OrchestratorState(TypedDict):
    """State passed through the LangGraph workflow."""

    # Multi-tenant
    business_id: int  # FK → businesses.id

    # Input
    media_item_id: int
    file_path: str
    media_type: str
    width: int
    height: int
    duration_seconds: float

    # Analysis
    analysis: dict | None  # Serialized MediaAnalysis
    content_category: str

    # Routing
    target_platforms: list[str]

    # Per-platform outputs
    edited_files: dict[str, str]     # platform -> edited file path
    captions: dict[str, dict]        # platform -> serialized CaptionResult
    hashtags: dict[str, list[str]]   # platform -> hashtag list

    # Approval
    approval_status: str             # "pending", "approved", "denied", "partial"
    approved_platforms: list[str]
    denied_platforms: list[str]

    # Publishing results
    publish_results: dict[str, dict] # platform -> result dict
    gmb_post_suggestion: dict | None # Google My Business post
    whatsapp_status_image: str | None # WhatsApp status image path

    # Music
    music_tracks: dict[str, dict]    # platform -> {title, path, mood}

    # Growth hacking data (populated by preview_node)
    engagement_analysis: dict[str, dict]   # platform -> engagement score & tips
    posting_schedule: dict[str, str]       # platform -> best time string
    viral_suggestions: dict[str, dict]     # platform -> viral format suggestion

    # Control
    current_step: str
    error: str | None
    thread_id: str

    # Accumulation proposals (collage/compilation suggestions)
    accumulation_proposals: list[dict]


# ── Node Functions ────────────────────────────────────────────────────────────


async def analyze_node(state: OrchestratorState) -> dict:
    """Analyze the media using Gemini Vision, then run NSFW safety scan."""
    from agents.vision_analyzer import analyze_media

    try:
        analysis = await analyze_media(
            state["file_path"],
            state["media_type"],
        )

        # ── Security: NSFW content scan ──────────────────────────────────
        from config.settings import get_settings
        settings = get_settings()

        if settings.enable_nsfw_scan:
            from security.content_scanner import ContentScanner
            from security.audit_log import audit, send_critical_alert, AuditEvent, Severity

            scanner = ContentScanner(threshold=settings.nsfw_threshold)
            if state["media_type"] == "video":
                nsfw_result = scanner.scan_video(state["file_path"])
            else:
                nsfw_result = scanner.scan_image(state["file_path"])

            await audit(
                event=AuditEvent.NSFW_SCAN,
                severity=Severity.INFO,
                actor="workflow",
                details={
                    "file": state["file_path"],
                    "is_safe": nsfw_result.is_safe,
                    "score": nsfw_result.overall_score,
                },
                related_id=state["media_item_id"],
            )

            if not nsfw_result.is_safe:
                await audit(
                    event=AuditEvent.NSFW_BLOCKED,
                    severity=Severity.CRITICAL,
                    actor="workflow",
                    details={
                        "file": state["file_path"],
                        "score": nsfw_result.overall_score,
                        "flagged": nsfw_result.flagged_categories,
                    },
                    related_id=state["media_item_id"],
                )
                await send_critical_alert(
                    AuditEvent.NSFW_BLOCKED,
                    f"NSFW content blocked (score: {nsfw_result.overall_score:.2f}). "
                    f"File: {state['file_path']}",
                )
                # Quarantine the file
                from pathlib import Path
                quarantine_dir = Path(settings.quarantine_path)
                quarantine_dir.mkdir(parents=True, exist_ok=True)
                src = Path(state["file_path"])
                src.rename(quarantine_dir / src.name)

                return {
                    "error": f"Content blocked by NSFW scanner: {nsfw_result.details}",
                    "current_step": "error",
                }

        # ── Check Gemini's own safety assessment ─────────────────────
        safety = analysis.safety_assessment
        if safety and not safety.get("is_safe", True):
            from security.audit_log import audit, send_critical_alert, AuditEvent, Severity
            concerns = safety.get("concerns", [])
            await audit(
                event=AuditEvent.NSFW_BLOCKED,
                severity=Severity.HIGH,
                actor="workflow",
                details={"gemini_concerns": concerns},
                related_id=state["media_item_id"],
            )
            await send_critical_alert(
                AuditEvent.NSFW_BLOCKED,
                f"Gemini flagged content as unsafe: {', '.join(concerns)}",
            )
            return {
                "error": f"Content flagged by vision analysis: {', '.join(concerns)}",
                "current_step": "error",
            }

        return {
            "analysis": analysis.model_dump(),
            "content_category": analysis.content_category,
            "current_step": "route",
        }
    except Exception as e:
        return {"error": f"Analysis failed: {str(e)}", "current_step": "error"}


async def route_node(state: OrchestratorState) -> dict:
    """Route media to appropriate platforms (rule-based, zero tokens)."""
    from agents.platform_router import route_media

    analysis_data = state.get("analysis")
    if not analysis_data:
        return {"error": "No analysis available", "current_step": "error"}

    analysis = MediaAnalysis(**analysis_data)

    platforms = route_media(
        media_type=state["media_type"],
        width=state["width"],
        height=state["height"],
        duration_seconds=state["duration_seconds"],
        analysis=analysis,
    )

    return {
        "target_platforms": platforms,
        "current_step": "edit",
    }


async def edit_node(state: OrchestratorState) -> dict:
    """Edit media for each target platform with AI-guided image enhancements."""
    import logging
    from pathlib import Path
    from agents.media_editor import (
        edit_image,
        edit_video,
        enhance_image_quality,
        apply_platform_filter,
    )
    from agents.platform_router import get_platform_spec
    from tools.media_utils import is_vertical

    logger = logging.getLogger(__name__)

    analysis_data = state.get("analysis", {})
    quality_score = analysis_data.get("quality_score", 7)
    improvement_tips = analysis_data.get("improvement_tips", [])

    # ── Log vision-analysis improvement recommendations ───────────────
    if improvement_tips:
        logger.info("Vision analysis improvement tips:")
        for tip in improvement_tips:
            logger.info(f"  → {tip}")

    # Determine edit instructions based on AI analysis
    brightness = 1.0
    contrast = 1.0
    if quality_score < 6:
        brightness = 1.15  # Brighten low-quality images
        contrast = 1.1

    # Check if tips mention specific adjustments
    tips_text = " ".join(improvement_tips).lower()
    if "bright" in tips_text or "dark" in tips_text:
        brightness = 1.2
    if "contrast" in tips_text:
        contrast = 1.15

    edited_files = {}
    vert = is_vertical(state["width"], state["height"])

    for platform in state["target_platforms"]:
        try:
            spec = get_platform_spec(platform, state["media_type"], vert)

            if state["media_type"] == "photo":
                instructions = EditInstructions(
                    brightness_adjustment=brightness,
                    contrast_adjustment=contrast,
                    add_watermark=True,
                )
                edited_path = edit_image(
                    state["file_path"],
                    spec,
                    instructions,
                    output_prefix=platform,
                )
            else:
                # Look up business name for watermark
                watermark_text = "Marketing"
                try:
                    from memory.database import get_session_factory
                    from memory.models import Business
                    from sqlalchemy import select
                    sf = get_session_factory()
                    async with sf() as _s:
                        biz = (await _s.execute(
                            select(Business.name).where(Business.id == state.get("business_id", 1))
                        )).scalar_one_or_none()
                        if biz:
                            watermark_text = biz
                except Exception:
                    pass

                edited_path = edit_video(
                    state["file_path"],
                    spec,
                    brightness=brightness,
                    add_watermark_text=watermark_text,
                )

            edited_files[platform] = edited_path

        except Exception as e:
            # If editing fails for a platform, use the original file
            edited_files[platform] = state["file_path"]

    # ── AI-guided image enhancement ───────────────────────────────────
    # Auto-enhance quality and apply platform filters for image media
    IMAGE_EXTS = {".jpg", ".jpeg", ".png", ".webp"}
    platform_media_versions = {}

    if state["media_type"] == "photo":
        for platform, edited_path in edited_files.items():
            if edited_path and Path(edited_path).suffix.lower() in IMAGE_EXTS:
                # 1. Auto-enhance image quality
                try:
                    enhanced_path = enhance_image_quality(edited_path)
                    edited_files[platform] = enhanced_path
                    logger.info(f"Auto-enhanced image quality for {platform}: {enhanced_path}")
                except Exception as e:
                    enhanced_path = edited_path
                    logger.warning(f"Image enhancement failed for {platform}: {e}")

                # 2. Apply platform-specific colour-grade / filter
                try:
                    filtered_path = apply_platform_filter(enhanced_path, platform)
                    platform_media_versions[platform] = filtered_path
                    edited_files[platform] = filtered_path
                    logger.info(f"Applied {platform} filter: {filtered_path}")
                except Exception as e:
                    platform_media_versions[platform] = enhanced_path
                    logger.warning(f"Platform filter failed for {platform}: {e}")

    return {
        "edited_files": edited_files,
        **({"platform_media_versions": platform_media_versions} if platform_media_versions else {}),
        "current_step": "music",
    }


async def music_node(state: OrchestratorState) -> dict:
    """
    Select and mix background music for video content.
    Skipped for photos.  Uses royalty-free library + zero-token mood matching.
    """
    from config.settings import get_settings

    settings = get_settings()
    if not settings.music_enabled or state["media_type"] != "video":
        return {"music_tracks": {}, "current_step": "caption"}

    from agents.music_researcher import recommend_music_for_content
    from agents.media_editor import mix_background_music
    from memory.database import get_session_factory
    from pathlib import Path

    analysis_data = state.get("analysis", {})
    music_tracks: dict[str, dict] = {}
    session_factory = get_session_factory()

    for platform in state["target_platforms"]:
        try:
            async with session_factory() as session:
                recs = await recommend_music_for_content(
                    session,
                    content_category=state.get("content_category", "general"),
                    content_description=analysis_data.get("description", ""),
                    content_mood=analysis_data.get("mood", "professional"),
                    platform=platform,
                )

            best = recs.get("best_track")
            if not best or not best.local_filename:
                continue

            music_file = Path(settings.music_library_path) / best.local_filename
            if not music_file.exists():
                # Track hasn't been downloaded yet — record suggestion only
                music_tracks[platform] = {
                    "title": best.title,
                    "path": None,
                    "mood": best.mood or recs.get("mood", "upbeat"),
                }
                continue

            # Mix music into the edited video
            edited_path = state.get("edited_files", {}).get(platform)
            if not edited_path:
                continue

            mixed_path = mix_background_music(
                video_path=edited_path,
                music_path=str(music_file),
                music_volume=settings.default_music_volume,
                fade_in=settings.music_fade_in_seconds,
                fade_out=settings.music_fade_out_seconds,
            )

            # Update edited file to the music-mixed version
            state["edited_files"][platform] = mixed_path
            music_tracks[platform] = {
                "title": best.title,
                "path": mixed_path,
                "mood": best.mood or recs.get("mood", "upbeat"),
            }

        except Exception as e:
            import logging
            logging.getLogger(__name__).warning(
                f"Music mixing skipped for {platform}: {e}"
            )

    return {"music_tracks": music_tracks, "current_step": "caption"}


async def caption_node(state: OrchestratorState) -> dict:
    """Generate captions and hashtags using platform-specialist AI agents."""
    from agents.platform_agents import get_or_create_agent
    from agents.caption_writer import generate_caption as generate_caption_fallback
    from agents.hashtag_researcher import get_hashtags
    from agents.growth_hacker import get_seo_keywords
    from memory.database import get_session_factory

    analysis_data = state.get("analysis", {})
    business_id = state.get("business_id", 1)
    captions = {}
    hashtags = {}

    session_factory = get_session_factory()
    category = state.get("content_category", "general")

    # Get local SEO keywords for this category (zero tokens)
    seo_keywords = get_seo_keywords(category, count=2)

    for platform in state["target_platforms"]:
        try:
            # Use platform-specialist agent (RAG-powered, self-learning)
            agent = await get_or_create_agent(business_id, platform)

            caption_result = await agent.generate_caption(
                content_description=analysis_data.get("description", "Content for publishing"),
                content_category=category,
                mood=analysis_data.get("mood", "professional"),
                healthcare_services=analysis_data.get("healthcare_services", []),
            )

            # Inject SEO keywords naturally into YouTube descriptions
            if platform == "youtube" and seo_keywords:
                seo_line = " | ".join(seo_keywords)
                caption_result["description"] = (
                    f"{caption_result.get('description') or ''}\n\n"
                    f"🔍 {seo_line}"
                )

            # Get agent-curated hashtags (combines DB cache + learned top tags)
            agent_hashtags = await agent.select_hashtags(category)

            # Merge AI-generated and agent-curated hashtags, prioritizing AI ones
            merged_tags = list(dict.fromkeys(
                caption_result.get("hashtags", []) + agent_hashtags
            ))[:agent.max_hashtags]

            captions[platform] = caption_result
            hashtags[platform] = merged_tags

        except Exception as e:
            # Fallback to generic caption writer if specialist agent fails
            import logging
            logging.getLogger(__name__).warning(
                f"Specialist agent failed for {platform}, falling back: {e}"
            )
            try:
                request = CaptionRequest(
                    platform=platform,
                    content_description=analysis_data.get("description", "Content for publishing"),
                    content_category=category,
                    mood=analysis_data.get("mood", "professional"),
                    healthcare_services=analysis_data.get("healthcare_services", []),
                )
                fallback_result = await generate_caption_fallback(request)
                captions[platform] = fallback_result.model_dump()
                hashtags[platform] = fallback_result.hashtags
            except Exception:
                captions[platform] = {"caption": "Check out our latest update!", "hashtags": []}
                hashtags[platform] = ["marketing", "business"]

    return {
        "captions": captions,
        "hashtags": hashtags,
        "current_step": "quality_gate",
    }


async def quality_gate_node(state: OrchestratorState) -> dict:
    """
    Quality gate — validates captions using marketing strategy AND security scanning.

    Checks:
    1. Engagement score via BJ Fogg model (Motivation × Ability × Trigger)
    2. Platform-appropriate length and format
    3. Psychology trigger presence
    4. Hook quality
    5. Text toxicity, healthcare compliance, brand safety (security)
    6. PII redaction (security)

    Auto-regenerates weak captions (score < 50) once.
    """
    from agents.growth_hacker import analyze_caption_for_engagement
    from agents.caption_writer import generate_caption
    from agents.schemas import CaptionRequest
    from agents.platform_agents import get_or_create_agent
    from config.settings import get_settings

    settings = get_settings()
    analysis_data = state.get("analysis", {})
    captions = dict(state.get("captions", {}))
    category = state.get("content_category", "general")
    business_id = state.get("business_id", 1)

    for platform in state.get("target_platforms", []):
        caption_data = captions.get(platform, {})
        caption_text = caption_data.get("caption", "")
        if not caption_text:
            continue

        # ── Security: text scanning (toxicity + compliance + brand safety) ──
        if settings.enable_text_scan:
            from security.text_scanner import TextScanner
            from security.audit_log import audit, AuditEvent, Severity

            text_scanner = TextScanner()
            scan_result = await text_scanner.full_scan(caption_text, context="caption")

            await audit(
                event=AuditEvent.TOXICITY_SCAN,
                severity=Severity.INFO,
                actor="workflow",
                details={
                    "platform": platform,
                    "is_safe": scan_result.is_safe,
                    "issues": scan_result.issues,
                },
                related_id=state.get("media_item_id"),
            )

            if not scan_result.is_safe:
                # Try to regenerate a safe caption via specialist agent
                try:
                    agent = await get_or_create_agent(business_id, platform)
                    improved_dict = await agent.generate_caption(
                        content_description=(
                            analysis_data.get("description", "Content for publishing")
                            + " | IMPORTANT: Keep caption professional, brand-safe, "
                            "and compliant. No medical claims without evidence."
                        ),
                        content_category=category,
                        mood=analysis_data.get("mood", "professional"),
                        healthcare_services=analysis_data.get("healthcare_services", []),
                    )
                    captions[platform] = improved_dict
                    caption_text = improved_dict.get("caption", "")

                    # Re-scan the regenerated caption
                    rescan = await text_scanner.full_scan(caption_text, context="caption")
                    if not rescan.is_safe:
                        await audit(
                            event=AuditEvent.TOXICITY_BLOCKED,
                            severity=Severity.HIGH,
                            actor="workflow",
                            details={
                                "platform": platform,
                                "issues": rescan.issues,
                                "action": "using fallback caption",
                            },
                        )
                        # Use a safe fallback caption
                        captions[platform] = {
                            "caption": "Discover our latest services and updates!",
                            "hashtags": ["business", "services"],
                        }
                except Exception:
                    captions[platform] = {
                        "caption": "Discover our latest services and updates!",
                        "hashtags": ["business", "services"],
                    }

        # ── Security: PII redaction ─────────────────────────────────────
        if settings.enable_pii_redaction:
            from security.pii_redactor import redact_pii, has_pii
            from security.audit_log import audit, AuditEvent, Severity

            current_caption = captions.get(platform, {}).get("caption", "")
            if has_pii(current_caption):
                redacted = redact_pii(current_caption)
                captions[platform]["caption"] = redacted
                await audit(
                    event=AuditEvent.PII_REDACTED,
                    severity=Severity.WARNING,
                    actor="workflow",
                    details={"platform": platform, "field": "caption"},
                    related_id=state.get("media_item_id"),
                )

            # Also check YouTube description
            desc = captions.get(platform, {}).get("description", "")
            if desc and has_pii(desc):
                captions[platform]["description"] = redact_pii(desc)
                await audit(
                    event=AuditEvent.PII_REDACTED,
                    severity=Severity.WARNING,
                    actor="workflow",
                    details={"platform": platform, "field": "description"},
                    related_id=state.get("media_item_id"),
                )

        # ── Quality: engagement scoring ───────────────────────────────
        caption_text = captions.get(platform, {}).get("caption", "")
        if caption_text:
            score_result = analyze_caption_for_engagement(caption_text, platform)
            engagement_score = score_result.get("engagement_score", 0)

            # If score is too low, regenerate ONCE with improvement hints
            if engagement_score < 50:
                try:
                    # Use specialist agent for regeneration (RAG-powered)
                    agent = await get_or_create_agent(business_id, platform)
                    improved = await agent.generate_caption(
                        content_description=(
                            analysis_data.get("description", "Content for publishing")
                            + " | MUST include: strong hook + psychology trigger + clear CTA"
                        ),
                        content_category=category,
                        mood=analysis_data.get("mood", "professional"),
                        healthcare_services=analysis_data.get("healthcare_services", []),
                    )
                    captions[platform] = improved
                except Exception:
                    pass  # Keep original if regeneration fails

    return {
        "captions": captions,
        "current_step": "check_accumulation",
    }


async def check_accumulation_node(state: OrchestratorState) -> dict:
    """Check if enough similar content has accumulated for collages/compilations."""
    from memory.content_memory import (
        check_accumulation_triggers,
        store_media_embedding,
    )
    from memory.database import get_session_factory

    analysis_data = state.get("analysis", {})

    # Store this media's embedding for future similarity search
    await store_media_embedding(
        media_item_id=state["media_item_id"],
        description=analysis_data.get("description", ""),
        category=state.get("content_category", "general"),
        metadata={
            "media_type": state["media_type"],
            "width": state["width"],
            "height": state["height"],
        },
    )

    # Check for accumulation triggers
    session_factory = get_session_factory()
    async with session_factory() as session:
        proposals = await check_accumulation_triggers(session)

    return {
        "accumulation_proposals": proposals,
    }


async def preview_node(state: OrchestratorState) -> dict:
    """
    Prepare preview data for Telegram approval.
    Includes engagement analysis, growth suggestions, and agent-specific posting times.
    This node sets the state to 'awaiting_approval' and the workflow pauses.
    The Telegram bot will resume the workflow when the user responds.
    """
    from agents.growth_hacker import (
        get_posting_schedule,
        suggest_viral_format,
        analyze_caption_for_engagement,
    )
    from agents.platform_agents import get_or_create_agent

    business_id = state.get("business_id", 1)

    # Analyze captions for engagement quality (zero tokens)
    engagement_analysis = {}
    for platform in state.get("target_platforms", []):
        caption_data = state.get("captions", {}).get(platform, {})
        caption_text = caption_data.get("caption", "")
        if caption_text:
            engagement_analysis[platform] = analyze_caption_for_engagement(
                caption_text, platform
            )

    # Get posting schedule — prefer agent-learned optimal times
    schedule = get_posting_schedule(state.get("target_platforms", []))
    for platform in state.get("target_platforms", []):
        try:
            agent = await get_or_create_agent(business_id, platform)
            best_time = await agent.get_best_posting_time()
            schedule[platform] = best_time
        except Exception:
            pass  # Keep default schedule

    # Suggest viral format for this content (zero tokens)
    category = state.get("content_category", "general")
    viral_suggestions = {}
    for platform in state.get("target_platforms", []):
        suggestion = suggest_viral_format(category, platform)
        if suggestion:
            viral_suggestions[platform] = suggestion

    # Store analysis in state for the Telegram preview message
    return {
        "approval_status": "pending",
        "current_step": "awaiting_approval",
        "engagement_analysis": engagement_analysis,
        "posting_schedule": schedule,
        "viral_suggestions": viral_suggestions,
    }


async def publish_node(state: OrchestratorState) -> dict:
    """Publish approved content to platforms."""
    from agents.publisher import publish_to_platform

    results = {}
    approved = state.get("approved_platforms", [])
    business_id = state.get("business_id", 1)

    for platform in approved:
        caption_data = state.get("captions", {}).get(platform, {})
        edited_path = state.get("edited_files", {}).get(platform, state["file_path"])
        platform_hashtags = state.get("hashtags", {}).get(platform, [])

        try:
            result = await publish_to_platform(
                platform=platform,
                file_path=edited_path,
                caption=caption_data.get("caption", ""),
                hashtags=platform_hashtags,
                media_type=state["media_type"],
                title=caption_data.get("title", ""),
                description=caption_data.get("description", ""),
                business_id=business_id,
            )
            results[platform] = result
        except Exception as e:
            results[platform] = {"success": False, "error": str(e)}

    # Log to content calendar
    from memory.database import get_session_factory
    from memory.content_memory import log_post_to_calendar
    from memory.models import Platform as PlatformEnum, ContentCategory

    session_factory = get_session_factory()
    async with session_factory() as session:
        for platform, result in results.items():
            if result.get("success"):
                try:
                    await log_post_to_calendar(
                        session,
                        post_id=state["media_item_id"],
                        platform=PlatformEnum(platform),
                        category=ContentCategory(state.get("content_category", "general")),
                    )
                except Exception:
                    pass

    # Feed engagement data back to platform agents for self-learning
    for platform in approved:
        try:
            from agents.platform_agents import get_or_create_agent
            agent = await get_or_create_agent(business_id, platform)
            caption_data = state.get("captions", {}).get(platform, {})
            await agent.learn_from_result(
                post_id=state["media_item_id"],
                engagement_data={
                    "caption": caption_data.get("caption", ""),
                    "hashtags": ", ".join(state.get("hashtags", {}).get(platform, [])),
                    "category": state.get("content_category", "general"),
                    "posted_at": datetime.now(),
                    "likes": results.get(platform, {}).get("likes", 0),
                    "comments": results.get(platform, {}).get("comments", 0),
                    "shares": results.get(platform, {}).get("shares", 0),
                    "views": results.get(platform, {}).get("views", 0),
                },
            )
        except Exception as e:
            import logging
            logging.getLogger(__name__).debug(f"Agent learning skipped for {platform}: {e}")

    # Auto-generate Google My Business post suggestion (free SEO)
    gmb_post = None
    try:
        from agents.platform_agents import get_or_create_agent, SEOAgent
        # Use SEO agent if available
        seo_agent = await get_or_create_agent(business_id, "seo")
        description = state.get("analysis", {}).get("description", "")
        if description and isinstance(seo_agent, SEOAgent):
            gmb_result = await seo_agent.generate_gmb_post(
                content_category=state.get("content_category", "general"),
                content_description=description,
            )
            gmb_post = gmb_result
        elif description:
            from agents.growth_hacker import generate_gmb_post
            gmb_post = await generate_gmb_post(
                content_category=state.get("content_category", "general"),
                content_description=description,
            )
    except Exception:
        pass

    # Auto-generate WhatsApp status image
    whatsapp_image = None
    try:
        from agents.growth_hacker import generate_whatsapp_image
        first_edited = next(iter(state.get("edited_files", {}).values()), None)
        if first_edited and state.get("media_type") == "photo":
            whatsapp_image = generate_whatsapp_image(first_edited)
    except Exception:
        pass

    return {
        "publish_results": results,
        "gmb_post_suggestion": gmb_post,
        "whatsapp_status_image": whatsapp_image,
        "current_step": "done",
    }


async def error_node(state: OrchestratorState) -> dict:
    """Handle errors gracefully."""
    return {"current_step": "error"}


# ── Conditional Edge Logic ────────────────────────────────────────────────────


def should_publish(state: OrchestratorState) -> str:
    """Decide whether to publish or end (based on approval)."""
    status = state.get("approval_status", "pending")
    if status == "pending":
        return "wait"  # Will be resumed by Telegram bot
    elif status in ("approved", "partial"):
        return "publish"
    else:
        return "end"


def check_error(state: OrchestratorState) -> str:
    """Check if an error occurred."""
    if state.get("error"):
        return "error"
    return "continue"


# ── Build the Graph ───────────────────────────────────────────────────────────


def build_workflow() -> StateGraph:
    """Build and compile the LangGraph workflow."""
    workflow = StateGraph(OrchestratorState)

    # Add nodes
    workflow.add_node("analyze", analyze_node)
    workflow.add_node("route", route_node)
    workflow.add_node("edit", edit_node)
    workflow.add_node("music", music_node)
    workflow.add_node("caption", caption_node)
    workflow.add_node("quality_gate", quality_gate_node)
    workflow.add_node("check_accumulation", check_accumulation_node)
    workflow.add_node("preview", preview_node)
    workflow.add_node("publish", publish_node)
    workflow.add_node("error", error_node)

    # Define edges
    workflow.add_edge(START, "analyze")

    # After analysis, check for errors
    workflow.add_conditional_edges(
        "analyze",
        check_error,
        {"error": "error", "continue": "route"},
    )

    workflow.add_edge("route", "edit")
    workflow.add_edge("edit", "music")
    workflow.add_edge("music", "caption")
    workflow.add_edge("caption", "quality_gate")
    workflow.add_edge("quality_gate", "check_accumulation")
    workflow.add_edge("check_accumulation", "preview")

    # Preview is the human-in-the-loop checkpoint
    # After approval is received, the workflow continues
    workflow.add_conditional_edges(
        "preview",
        should_publish,
        {"publish": "publish", "end": END, "wait": END},
    )

    workflow.add_edge("publish", END)
    workflow.add_edge("error", END)

    return workflow


async def get_checkpointer():
    """Get a MySQL-backed checkpointer for workflow persistence."""
    settings = get_settings()

    # Connection config for aiomysql
    conn_config = {
        "host": settings.mysql_host,
        "port": settings.mysql_port,
        "user": settings.mysql_user,
        "password": settings.mysql_password,
        "db": settings.mysql_database,
    }

    checkpointer = AIOMySQLSaver(conn_config)
    await checkpointer.setup()
    return checkpointer


async def create_compiled_workflow():
    """Create and compile the workflow with MySQL checkpointing."""
    workflow = build_workflow()
    checkpointer = await get_checkpointer()
    return workflow.compile(checkpointer=checkpointer)


async def start_media_workflow(
    media_item_id: int,
    file_path: str,
    media_type: str,
    width: int = 0,
    height: int = 0,
    duration_seconds: float = 0,
    business_id: int = 1,
) -> str:
    """
    Start a new media processing workflow.
    Returns the thread_id for tracking and resuming.
    """
    compiled = await create_compiled_workflow()
    thread_id = f"media_{media_item_id}_{uuid.uuid4().hex[:8]}"

    initial_state: OrchestratorState = {
        "business_id": business_id,
        "media_item_id": media_item_id,
        "file_path": file_path,
        "media_type": media_type,
        "width": width,
        "height": height,
        "duration_seconds": duration_seconds,
        "analysis": None,
        "content_category": "",
        "target_platforms": [],
        "edited_files": {},
        "captions": {},
        "hashtags": {},
        "approval_status": "pending",
        "approved_platforms": [],
        "denied_platforms": [],
        "publish_results": {},
        "current_step": "analyze",
        "error": None,
        "thread_id": thread_id,
        "accumulation_proposals": [],
        "music_tracks": {},
    }

    config = {"configurable": {"thread_id": thread_id}}

    # Run the workflow — it will pause at the preview node
    result = await compiled.ainvoke(initial_state, config)

    return thread_id


async def resume_workflow_with_approval(
    thread_id: str,
    approved_platforms: list[str],
    denied_platforms: list[str],
) -> dict:
    """
    Resume a paused workflow after user approval/denial.
    Returns the final publish results.
    """
    compiled = await create_compiled_workflow()
    config = {"configurable": {"thread_id": thread_id}}

    # Get current state
    state = await compiled.aget_state(config)

    if not state or not state.values:
        return {"error": "Workflow not found"}

    # Determine approval status
    if approved_platforms and not denied_platforms:
        approval_status = "approved"
    elif approved_platforms and denied_platforms:
        approval_status = "partial"
    else:
        approval_status = "denied"

    # Update state with approval
    update = {
        "approved_platforms": approved_platforms,
        "denied_platforms": denied_platforms,
        "approval_status": approval_status,
    }

    await compiled.aupdate_state(config, update)

    # Resume the workflow
    result = await compiled.ainvoke(None, config)

    return result.get("publish_results", {})
