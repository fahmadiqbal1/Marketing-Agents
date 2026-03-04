"""
Telegram Bot — the main interface between you and the AI marketing system.

Handles:
- Receiving photos/videos → starts the processing workflow
- Approval flow with inline keyboards
- /job command for job postings
- Resume forwarding for screening
- Interview scheduling confirmations
- /status, /packages, /suggest commands
"""

from __future__ import annotations

import json
import logging
from pathlib import Path

from telegram import (
    Update,
    InlineKeyboardButton,
    InlineKeyboardMarkup,
    ReplyKeyboardMarkup,
)
from telegram.ext import (
    Application,
    CommandHandler,
    MessageHandler,
    CallbackQueryHandler,
    ConversationHandler,
    ContextTypes,
    filters,
)

from config.settings import get_settings
from tools.media_utils import (
    inbox_path,
    resumes_path,
    generate_filename,
    get_image_metadata,
    get_video_metadata,
)

logger = logging.getLogger(__name__)

# ── Conversation states ───────────────────────────────────────────────────────

(
    JOB_TITLE,
    JOB_DEPARTMENT,
    JOB_EXPERIENCE,
    JOB_SKILLS,
    JOB_SALARY,
    JOB_NOTES,
    JOB_CONFIRM,
    RESUME_SELECT_JOB,
    SCHEDULE_CONFIRM,
) = range(9)


# ── Authorization ────────────────────────────────────────────────────────────


def admin_only(func):
    """Decorator to restrict commands to authorized admin chat IDs.

    Checks per-tenant admin list from bot_data first (multi-tenant mode),
    then falls back to the global setting (single-tenant / legacy mode).
    """
    async def wrapper(update: Update, context: ContextTypes.DEFAULT_TYPE):
        chat_id = update.effective_chat.id

        # Multi-tenant: check per-bot admin list
        tenant_admins = context.bot_data.get("admin_chat_ids", [])
        if tenant_admins:
            if chat_id in tenant_admins:
                return await func(update, context)
        else:
            # Fallback: legacy single-tenant check
            settings = get_settings()
            if settings.telegram_admin_chat_id and chat_id == settings.telegram_admin_chat_id:
                return await func(update, context)

        await update.message.reply_text("⛔ Unauthorized. This bot is private.")
        return
    return wrapper


def _get_business_id(context: ContextTypes.DEFAULT_TYPE) -> int:
    """Get the business ID for the current bot instance.

    In multi-tenant mode, each bot has a business_id injected at build time.
    Falls back to 1 for legacy single-tenant setups.
    """
    return context.bot_data.get("business_id", 1)


# =============================================================================
# COMMAND HANDLERS
# =============================================================================


@admin_only
async def start_command(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Welcome message with available commands."""
    business_name = context.bot_data.get("business_name", "Your Business")
    await update.message.reply_text(
        f"💼 *{business_name} Marketing Bot*\n\n"
        "Send me a photo or video and I'll:\n"
        "1️⃣ Analyze the content\n"
        "2️⃣ Edit it for each platform\n"
        "3️⃣ Write platform-specific captions\n"
        "4️⃣ Research optimal hashtags\n"
        "5️⃣ Send you a preview for approval\n"
        "6️⃣ Publish to approved platforms\n\n"
        "*Commands:*\n"
        "/create — Content creation wizard\n"
        "/job — Create a job posting\n"
        "/jobs — View open job listings\n"
        "/packages — Get AI promotional package ideas\n"
        "/suggest — Get content gap suggestions\n"
        "/growth — Daily growth brief + best posting times\n"
        "/freeads — Free advertising strategies\n"
        "/series — This week's content series plan\n"
        "/recycle — Repurpose your best content\n"
        "/engage — Auto-reply templates & FAQ\n"
        "/train — Teach the bot Q&A examples\n"
        "/personality — Set bot tone & style\n"
        "/analytics — Post performance summary\n"
        "/usage — AI token usage summary\n"
        "/status — Check system status\n"
        "/help — Show this message\n\n"
        "💬 You can also just type naturally — "
        "try \"create a post\" or \"show my stats\"!",
        parse_mode="Markdown",
    )


@admin_only
async def status_command(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Show system status."""
    from memory.database import get_session_factory
    from memory.models import MediaItem, Post, Job, PostStatus
    from sqlalchemy import select, func

    session_factory = get_session_factory()
    async with session_factory() as session:
        total_media = await session.scalar(
            select(func.count()).select_from(MediaItem)
        ) or 0
        total_posts = await session.scalar(
            select(func.count()).select_from(Post)
        ) or 0
        published = await session.scalar(
            select(func.count()).select_from(Post).where(Post.status == PostStatus.PUBLISHED)
        ) or 0
        pending = await session.scalar(
            select(func.count()).select_from(Post).where(
                Post.status == PostStatus.READY_FOR_APPROVAL
            )
        ) or 0
        open_jobs = await session.scalar(
            select(func.count()).select_from(Job).where(Job.status == "open")
        ) or 0

    await update.message.reply_text(
        "📊 *System Status*\n\n"
        f"📸 Total media received: {total_media}\n"
        f"📝 Total posts created: {total_posts}\n"
        f"✅ Published: {published}\n"
        f"⏳ Pending approval: {pending}\n"
        f"💼 Open jobs: {open_jobs}",
        parse_mode="Markdown",
    )


@admin_only
async def suggest_command(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Suggest content gaps to fill."""
    from memory.database import get_session_factory
    from memory.content_memory import get_content_gap_suggestions

    session_factory = get_session_factory()
    async with session_factory() as session:
        gaps = await get_content_gap_suggestions(session)

    if gaps:
        gap_text = "\n".join(f"• {g.replace('_', ' ').title()}" for g in gaps)
        await update.message.reply_text(
            f"💡 *Content suggestions*\n\n"
            f"You haven't posted about these recently:\n{gap_text}\n\n"
            f"Send me a photo or video of any of these!",
            parse_mode="Markdown",
        )
    else:
        await update.message.reply_text(
            "✅ Great coverage! All service categories have been posted recently."
        )


@admin_only
async def packages_command(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Generate promotional package ideas."""
    await update.message.reply_text("🧠 Thinking of promotional packages...")

    from agents.package_brain import generate_package_proposals
    from memory.database import get_session_factory
    from memory.content_memory import get_recent_post_categories

    session_factory = get_session_factory()
    async with session_factory() as session:
        recent = await get_recent_post_categories(session)

    proposals = await generate_package_proposals(recent_categories=recent)

    for i, pkg in enumerate(proposals):
        services = ", ".join(pkg.services_included)
        text = (
            f"📦 *Package {i+1}: {pkg.name}*\n"
            f"_{pkg.tagline}_\n\n"
            f"*Services:* {services}\n"
            f"*Discount:* {pkg.discount_details}\n"
            f"*Target:* {pkg.target_audience}\n"
            f"{'*Occasion:* ' + pkg.occasion + chr(10) if pkg.occasion else ''}"
            f"{'*Price:* ' + pkg.suggested_price + chr(10) if pkg.suggested_price else ''}\n"
            f"*Content ideas:*\n"
            + "\n".join(f"  • {idea}" for idea in pkg.content_ideas)
        )

        keyboard = InlineKeyboardMarkup([
            [
                InlineKeyboardButton("✅ Approve", callback_data=f"pkg_approve_{i}"),
                InlineKeyboardButton("❌ Deny", callback_data=f"pkg_deny_{i}"),
            ]
        ])

        await update.message.reply_text(text, parse_mode="Markdown", reply_markup=keyboard)


# =============================================================================
# GROWTH HACKING COMMANDS
# =============================================================================


@admin_only
async def growth_command(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Daily growth brief — best posting times, content series, tips."""
    from agents.growth_hacker import generate_growth_report

    report = generate_growth_report()
    await update.message.reply_text(report, parse_mode="Markdown")


@admin_only
async def freeads_command(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Show all free advertising strategies."""
    from agents.growth_hacker import get_free_ad_strategies

    text = get_free_ad_strategies()
    # Split into chunks if too long for Telegram (4096 char limit)
    if len(text) > 4000:
        parts = text.split("\n\n")
        chunk = ""
        for part in parts:
            if len(chunk) + len(part) > 3900:
                await update.message.reply_text(chunk, parse_mode="Markdown")
                chunk = part
            else:
                chunk += "\n\n" + part if chunk else part
        if chunk:
            await update.message.reply_text(chunk, parse_mode="Markdown")
    else:
        await update.message.reply_text(text, parse_mode="Markdown")


@admin_only
async def series_command(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Show this week's content series plan."""
    from agents.growth_hacker import get_weekly_content_plan, get_todays_series
    from agents.content_recycler import suggest_next_in_series

    plan = get_weekly_content_plan()
    today_series = get_todays_series()

    lines = ["📺 *Weekly Content Series Plan*\n"]

    for series in plan:
        is_today = series == today_series
        marker = "👉 " if is_today else "  "
        today_label = " *(TODAY!)* " if is_today else ""
        platforms = ", ".join(p.title() for p in series["platforms"])
        hashtags = " ".join("#" + h for h in series["hashtags"])

        lines.append(
            f"{marker}*{series['day']}: {series['name']}*{today_label}\n"
            f"  _{series['theme']}_\n"
            f"  📱 {platforms}\n"
            f"  🏷️ {hashtags}\n"
        )

    if today_series:
        suggestion = suggest_next_in_series(today_series["name"], 0)
        lines.append(
            f"\n💡 *Suggested topic for today:*\n"
            f"  _{suggestion['suggested_topic']}_"
        )

    await update.message.reply_text("\n".join(lines), parse_mode="Markdown")


@admin_only
async def recycle_command(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Suggest content to repurpose from old posts."""
    from agents.content_recycler import (
        get_top_performing_posts,
        get_due_evergreen_content,
        get_repurpose_plan,
    )

    await update.message.reply_text("♻️ Analyzing your content library...")

    # Check evergreen content that's due
    evergreen = get_due_evergreen_content()

    lines = ["♻️ *Content Recycling Suggestions*\n"]

    if evergreen:
        lines.append("📌 *Evergreen posts due for reposting:*\n")
        for eg in evergreen[:3]:
            platforms = ", ".join(p.title() for p in eg["platforms"])
            lines.append(
                f"  • *{eg['title']}*\n"
                f"    Platforms: {platforms}\n"
                f"    Category: {eg['category']}\n"
            )
        lines.append("")

    # Check recently published posts for cross-posting
    try:
        recent = await get_top_performing_posts(days=14, limit=3)
        if recent:
            lines.append("📊 *Recent posts to cross-post:*\n")
            for post in recent:
                source_fmt = f"{post['platform']}_post"
                options = get_repurpose_plan(source_fmt)
                if options:
                    targets = ", ".join(o["target"] for o in options[:3])
                    lines.append(
                        f"  • Post on {post['platform'].title()} → also post on: {targets}\n"
                    )
    except Exception as e:
        logger.warning(f"Could not fetch recent posts: {e}")

    lines.append(
        "\n💡 *Tip:* Send me any old photo/video and I'll "
        "automatically adapt it for all your platforms!"
    )

    await update.message.reply_text("\n".join(lines), parse_mode="Markdown")


@admin_only
async def engage_command(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Show auto-reply templates and FAQ responses."""
    from agents.auto_engagement import FAQ_RESPONSES

    lines = ["🤖 *Auto-Engagement Templates*\n"]
    lines.append(
        "These responses are auto-suggested when someone asks:\n"
    )
    for key, faq in FAQ_RESPONSES.items():
        keywords = ", ".join(faq["keywords"][:4])
        preview = faq["response"][:100].replace("\n", " ")
        lines.append(f"*{key.title()}*  _(triggers: {keywords})_\n  _{preview}..._\n")

    lines.append(
        "\n💡 *How to use:*\n"
        "Copy these responses when replying to DMs and comments. "
        "Questions matching these keywords will get auto-reply suggestions!"
    )

    await update.message.reply_text("\n".join(lines), parse_mode="Markdown")


@admin_only
async def seo_command(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Show local SEO keywords for captions."""
    from agents.growth_hacker import LOCAL_SEO_KEYWORDS

    lines = ["🔍 *Local SEO Keywords for Your Business*\n"]
    lines.append("_These keywords help people find you on Google:_\n")

    for category, keywords in LOCAL_SEO_KEYWORDS.items():
        cat_name = category.replace("_", " ").title()
        kw_list = ", ".join(f'"{k}"' for k in keywords[:3])
        lines.append(f"*{cat_name}:* {kw_list}\n")

    lines.append(
        "\n💡 *How it works:*\n"
        "These keywords are automatically injected into your YouTube titles, "
        "Instagram captions, and Google My Business posts. People searching "
        "these on Google will find YOUR content — free advertising! 🚀"
    )

    await update.message.reply_text("\n".join(lines), parse_mode="Markdown")


# =============================================================================
# BOT TRAINING & PERSONALITY COMMANDS
# =============================================================================


@admin_only
async def cmd_train(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Start bot training — admin teaches the bot Q&A pairs."""
    business_id = _get_business_id(context)
    if not business_id:
        await update.message.reply_text("⚠️ Connect your business first with /start")
        return

    context.user_data["training_mode"] = True
    context.user_data["training_step"] = "question"
    context.user_data["training_pairs"] = []
    await update.message.reply_text(
        "🎓 *Bot Training Mode*\n\n"
        "I'll learn from your Q&A examples.\n\n"
        "Step 1: Type a *question* that customers might ask.\n"
        "Type /done when finished training.",
        parse_mode="Markdown",
    )


@admin_only
async def cmd_done(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Exit bot training mode."""
    if not context.user_data.get("training_mode"):
        await update.message.reply_text("ℹ️ You're not currently in training mode.")
        return

    pairs_saved = len(context.user_data.get("training_pairs", []))
    context.user_data.pop("training_mode", None)
    context.user_data.pop("training_step", None)
    context.user_data.pop("training_pairs", None)
    context.user_data.pop("training_question", None)

    await update.message.reply_text(
        f"✅ *Training Complete!*\n\n"
        f"Saved *{pairs_saved}* Q&A pair(s) this session.\n"
        f"The bot will use these examples to improve its responses.",
        parse_mode="Markdown",
    )


async def handle_training_message(
    update: Update, context: ContextTypes.DEFAULT_TYPE
) -> None:
    """Handle text messages while in training mode — collect Q&A pairs."""
    text = update.message.text.strip()
    step = context.user_data.get("training_step", "question")
    business_id = _get_business_id(context)

    if step == "question":
        # User is providing a question
        context.user_data["training_question"] = text
        context.user_data["training_step"] = "answer"
        await update.message.reply_text(
            f"📝 Got it! Question recorded:\n_{text}_\n\n"
            f"Step 2: Now type the *ideal answer* for this question.",
            parse_mode="Markdown",
        )

    elif step == "answer":
        # User is providing the answer — save the Q&A pair
        question = context.user_data.pop("training_question", "")
        new_example = {"q": question, "a": text}

        # Save to database
        try:
            from memory.database import get_session_factory
            from sqlalchemy import text as sql_text

            session_factory = get_session_factory()
            async with session_factory() as session:
                row = await session.execute(
                    sql_text(
                        "SELECT id, trained_examples_json FROM bot_personalities "
                        "WHERE business_id = :bid LIMIT 1"
                    ),
                    {"bid": business_id},
                )
                existing = row.fetchone()

                if existing:
                    examples = json.loads(existing.trained_examples_json or "[]")
                    examples.append(new_example)
                    await session.execute(
                        sql_text(
                            "UPDATE bot_personalities SET trained_examples_json = :te, "
                            "updated_at = NOW() WHERE business_id = :bid"
                        ),
                        {"te": json.dumps(examples), "bid": business_id},
                    )
                else:
                    await session.execute(
                        sql_text(
                            "INSERT INTO bot_personalities (business_id, persona_name, "
                            "trained_examples_json) VALUES (:bid, 'default', :te)"
                        ),
                        {"te": json.dumps([new_example]), "bid": business_id},
                    )
                await session.commit()

            # Track pairs saved this session
            pairs = context.user_data.get("training_pairs", [])
            pairs.append(new_example)
            context.user_data["training_pairs"] = pairs

            total = len(pairs)
            await update.message.reply_text(
                f"✅ *Q&A pair #{total} saved!*\n\n"
                f"Q: _{question}_\n"
                f"A: _{text}_\n\n"
                f"Type your next *question*, or /done to finish training.",
                parse_mode="Markdown",
            )
        except Exception as e:
            logger.error(f"Training save error: {e}", exc_info=True)
            await update.message.reply_text(
                f"❌ Error saving training pair: {str(e)[:200]}\n"
                f"Please try again or /done to exit."
            )

        # Reset to question step for next pair
        context.user_data["training_step"] = "question"


@admin_only
async def cmd_personality(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Show personality settings with inline keyboard."""
    # Show current tone from database
    business_id = _get_business_id(context)
    current_tone = "professional"
    try:
        from memory.database import get_session_factory
        from sqlalchemy import text as sql_text

        session_factory = get_session_factory()
        async with session_factory() as session:
            row = await session.execute(
                sql_text(
                    "SELECT tone FROM bot_personalities "
                    "WHERE business_id = :bid LIMIT 1"
                ),
                {"bid": business_id},
            )
            result = row.fetchone()
            if result:
                current_tone = result.tone or "professional"
    except Exception:
        pass

    keyboard = [
        [
            InlineKeyboardButton("🎯 Professional", callback_data="set_tone_professional"),
            InlineKeyboardButton("😊 Friendly", callback_data="set_tone_friendly"),
        ],
        [
            InlineKeyboardButton("😎 Casual", callback_data="set_tone_casual"),
            InlineKeyboardButton("🤪 Witty", callback_data="set_tone_witty"),
        ],
    ]
    await update.message.reply_text(
        f"🤖 *Bot Personality Settings*\n\n"
        f"Current tone: *{current_tone.title()}*\n\n"
        f"Choose a new tone:",
        reply_markup=InlineKeyboardMarkup(keyboard),
        parse_mode="Markdown",
    )


async def handle_set_tone_callback(
    update: Update, context: ContextTypes.DEFAULT_TYPE
) -> None:
    """Handle tone selection from /personality inline keyboard."""
    query = update.callback_query
    await query.answer()

    tone = query.data.replace("set_tone_", "")
    business_id = _get_business_id(context)

    tone_labels = {
        "professional": "🎯 Professional",
        "friendly": "😊 Friendly",
        "casual": "😎 Casual",
        "witty": "🤪 Witty",
    }

    try:
        from memory.database import get_session_factory
        from sqlalchemy import text as sql_text

        session_factory = get_session_factory()
        async with session_factory() as session:
            existing = await session.execute(
                sql_text("SELECT id FROM bot_personalities WHERE business_id = :bid"),
                {"bid": business_id},
            )
            if existing.fetchone():
                await session.execute(
                    sql_text(
                        "UPDATE bot_personalities SET tone = :tone, "
                        "updated_at = NOW() WHERE business_id = :bid"
                    ),
                    {"tone": tone, "bid": business_id},
                )
            else:
                await session.execute(
                    sql_text(
                        "INSERT INTO bot_personalities (business_id, persona_name, tone) "
                        "VALUES (:bid, 'default', :tone)"
                    ),
                    {"tone": tone, "bid": business_id},
                )
            await session.commit()

        label = tone_labels.get(tone, tone.title())
        await query.edit_message_text(
            f"✅ Bot tone updated to {label}!\n\n"
            f"All future responses will use the *{tone}* style.",
            parse_mode="Markdown",
        )
    except Exception as e:
        logger.error(f"Tone update error: {e}", exc_info=True)
        await query.edit_message_text(
            f"❌ Error updating tone: {str(e)[:200]}"
        )


# =============================================================================
# CONTENT CREATION COMMAND
# =============================================================================


@admin_only
async def cmd_create(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Start content creation workflow via orchestrator."""
    keyboard = [
        [
            InlineKeyboardButton("📸 Photo Post", callback_data="create_type_photo"),
            InlineKeyboardButton("🎬 Video Post", callback_data="create_type_video"),
        ],
        [
            InlineKeyboardButton("💼 Job Posting", callback_data="create_type_job"),
            InlineKeyboardButton("📦 Package Promo", callback_data="create_type_package"),
        ],
    ]
    await update.message.reply_text(
        "🎨 *Content Creation Wizard*\n\n"
        "What would you like to create?\n\n"
        "You can also just send me a photo or video directly "
        "and I'll run the full workflow automatically!",
        reply_markup=InlineKeyboardMarkup(keyboard),
        parse_mode="Markdown",
    )


async def handle_create_type_callback(
    update: Update, context: ContextTypes.DEFAULT_TYPE
) -> None:
    """Handle content type selection from /create inline keyboard."""
    query = update.callback_query
    await query.answer()

    content_type = query.data.replace("create_type_", "")

    if content_type == "job":
        await query.edit_message_text(
            "💼 Redirecting to job creation...\n\nUse /job to start."
        )
        return

    if content_type == "package":
        await query.edit_message_text(
            "📦 Redirecting to package ideas...\n\nUse /packages to get AI-generated proposals."
        )
        return

    # Photo or video — prompt user to send media with optional description
    media_label = "photo" if content_type == "photo" else "video"
    context.user_data["create_mode"] = content_type
    await query.edit_message_text(
        f"📸 *{media_label.title()} Post Creation*\n\n"
        f"Step 1: Send me the {media_label} you want to post.\n\n"
        f"_Optional: Type a description first and then send the {media_label}. "
        f"I'll use your description to guide the captions._",
        parse_mode="Markdown",
    )


# =============================================================================
# MEDIA HANDLERS (photos and videos)
# =============================================================================


@admin_only
async def handle_photo(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Handle incoming photos — validate then start the marketing workflow."""
    from security.file_guard import validate_file, strip_exif_metadata
    from security.audit_log import audit, AuditEvent, Severity

    msg = update.message
    photo = msg.photo[-1]  # Highest resolution

    # Download the photo
    file = await photo.get_file()
    filename = generate_filename(file.file_path or "photo.jpg", prefix="inbox")
    local_path = inbox_path() / filename
    await file.download_to_drive(str(local_path))

    # ── Security: validate the uploaded file ──────────────────────────
    validation = validate_file(
        file_path=str(local_path),
        original_filename=filename,
        allow_documents=False,
    )
    if not validation.is_safe:
        local_path.unlink(missing_ok=True)
        await audit(
            event=AuditEvent.FILE_REJECTED,
            severity=Severity.HIGH,
            actor="telegram_bot",
            details={"filename": filename, "issues": validation.issues},
        )
        await msg.reply_text(
            f"⚠️ Photo rejected by security scan:\n"
            + "\n".join(f"• {i}" for i in validation.issues)
        )
        return

    # Strip EXIF metadata
    strip_exif_metadata(str(local_path))
    await audit(
        event=AuditEvent.FILE_UPLOAD,
        severity=Severity.INFO,
        actor="telegram_bot",
        details={"filename": filename, "type": "photo", "size": validation.file_size},
    )

    await msg.reply_text("📸 Photo received! Analyzing...")

    # Get metadata
    meta = get_image_metadata(str(local_path))

    # Save to database
    from memory.database import get_session_factory
    from memory.models import MediaItem, MediaType

    session_factory = get_session_factory()
    async with session_factory() as session:
        media_item = MediaItem(
            business_id=_get_business_id(context),
            telegram_file_id=photo.file_id,
            file_path=str(local_path),
            file_name=filename,
            media_type=MediaType.PHOTO,
            width=meta.get("width", photo.width),
            height=meta.get("height", photo.height),
            file_size_bytes=meta.get("file_size_bytes", photo.file_size),
        )
        session.add(media_item)
        await session.commit()
        await session.refresh(media_item)
        media_id = media_item.id

    # Start the orchestrator workflow
    try:
        from orchestrator.workflow import start_media_workflow

        thread_id = await start_media_workflow(
            media_item_id=media_id,
            file_path=str(local_path),
            media_type="photo",
            width=meta.get("width", photo.width),
            height=meta.get("height", photo.height),
            business_id=_get_business_id(context),
        )

        # Store thread_id for later approval callbacks
        context.bot_data[f"thread_{media_id}"] = thread_id

        # Send preview with approval buttons
        await _send_preview(update, context, media_id, thread_id)

    except Exception as e:
        logger.error(f"Workflow error: {e}", exc_info=True)
        await msg.reply_text(f"❌ Error processing photo: {str(e)[:200]}")


@admin_only
async def handle_video(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Handle incoming videos — validate then start the marketing workflow."""
    from security.file_guard import validate_file
    from security.audit_log import audit, AuditEvent, Severity

    msg = update.message
    video = msg.video

    # Download the video
    file = await video.get_file()
    filename = generate_filename(file.file_path or "video.mp4", prefix="inbox")
    local_path = inbox_path() / filename
    await file.download_to_drive(str(local_path))

    # ── Security: validate the uploaded file ──────────────────────────
    validation = validate_file(
        file_path=str(local_path),
        original_filename=filename,
        allow_documents=False,
    )
    if not validation.is_safe:
        local_path.unlink(missing_ok=True)
        await audit(
            event=AuditEvent.FILE_REJECTED,
            severity=Severity.HIGH,
            actor="telegram_bot",
            details={"filename": filename, "issues": validation.issues},
        )
        await msg.reply_text(
            f"⚠️ Video rejected by security scan:\n"
            + "\n".join(f"• {i}" for i in validation.issues)
        )
        return

    await audit(
        event=AuditEvent.FILE_UPLOAD,
        severity=Severity.INFO,
        actor="telegram_bot",
        details={"filename": filename, "type": "video", "size": validation.file_size},
    )

    await msg.reply_text("🎬 Video received! Analyzing...")

    # Get metadata
    meta = get_video_metadata(str(local_path))

    # Save to database
    from memory.database import get_session_factory
    from memory.models import MediaItem, MediaType

    session_factory = get_session_factory()
    async with session_factory() as session:
        media_item = MediaItem(
            business_id=_get_business_id(context),
            telegram_file_id=video.file_id,
            file_path=str(local_path),
            file_name=filename,
            media_type=MediaType.VIDEO,
            width=meta.get("width", video.width or 0),
            height=meta.get("height", video.height or 0),
            duration_seconds=meta.get("duration_seconds", video.duration or 0),
            file_size_bytes=meta.get("file_size_bytes", video.file_size),
        )
        session.add(media_item)
        await session.commit()
        await session.refresh(media_item)
        media_id = media_item.id

    # Start workflow
    try:
        from orchestrator.workflow import start_media_workflow

        thread_id = await start_media_workflow(
            media_item_id=media_id,
            file_path=str(local_path),
            media_type="video",
            width=meta.get("width", video.width or 0),
            height=meta.get("height", video.height or 0),
            duration_seconds=meta.get("duration_seconds", video.duration or 0),
            business_id=_get_business_id(context),
        )

        context.bot_data[f"thread_{media_id}"] = thread_id
        await _send_preview(update, context, media_id, thread_id)

    except Exception as e:
        logger.error(f"Workflow error: {e}", exc_info=True)
        await msg.reply_text(f"❌ Error processing video: {str(e)[:200]}")


async def _send_preview(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
    media_id: int,
    thread_id: str,
) -> None:
    """Send the processed preview back to the user for approval."""
    from orchestrator.workflow import create_compiled_workflow

    compiled = await create_compiled_workflow()
    config = {"configurable": {"thread_id": thread_id}}
    state_snapshot = await compiled.aget_state(config)

    if not state_snapshot or not state_snapshot.values:
        await update.message.reply_text("❌ Could not retrieve workflow state.")
        return

    state = state_snapshot.values
    analysis = state.get("analysis", {})
    platforms = state.get("target_platforms", [])
    captions = state.get("captions", {})
    hashtags = state.get("hashtags", {})
    accumulation = state.get("accumulation_proposals", [])

    # Build analysis summary
    analysis_text = (
        f"🔍 *Analysis:*\n"
        f"  Content: {analysis.get('description', 'N/A')}\n"
        f"  Category: {analysis.get('content_category', 'N/A').replace('_', ' ').title()}\n"
        f"  Quality: {'⭐' * int(analysis.get('quality_score', 5))} ({analysis.get('quality_score', 'N/A')}/10)\n"
        f"  Mood: {analysis.get('mood', 'N/A')}\n"
    )

    # Build platform details
    platform_text = "📱 *Target Platforms:*\n"
    for p in platforms:
        cap = captions.get(p, {})
        tags = hashtags.get(p, [])
        caption_preview = cap.get("caption", "")[:100]
        tag_preview = " ".join(f"#{t}" for t in tags[:5])
        platform_text += (
            f"\n*{p.upper()}:*\n"
            f"  Caption: _{caption_preview}{'...' if len(cap.get('caption', '')) > 100 else ''}_\n"
            f"  Hashtags: {tag_preview}{'...' if len(tags) > 5 else ''}\n"
        )

    # Music info
    music_tracks = state.get("music_tracks", {})
    music_text = ""
    if music_tracks:
        music_text = "\n🎵 *Background Music:*\n"
        for p, minfo in music_tracks.items():
            title = minfo.get("title", "None")
            mood = minfo.get("mood", "")
            has_file = "✅" if minfo.get("path") else "💡"
            music_text += f"  {has_file} {p.title()}: _{title}_ ({mood})\n"
        if any(not m.get("path") for m in music_tracks.values()):
            music_text += "  _💡 = suggestion only (download MP3 to music\\_library)_\n"

    # Growth intelligence (zero tokens — rule-based analysis)
    growth_text = ""
    engagement = state.get("engagement_analysis", {})
    schedule = state.get("posting_schedule", {})
    viral = state.get("viral_suggestions", {})

    if engagement or schedule:
        growth_text = "\n📈 *Growth Intelligence:*\n"

        # Engagement scores
        for p, eng in engagement.items():
            score = eng.get("engagement_score", 0)
            verdict = eng.get("verdict", "")
            growth_text += f"  {p.title()}: {verdict} ({score}/100)\n"
            tips = eng.get("suggestions", [])[:2]
            for tip in tips:
                growth_text += f"    💡 _{tip}_\n"

        # Best posting times
        if schedule:
            growth_text += "\n⏰ *Best time to post:*\n"
            for p, time_str in schedule.items():
                growth_text += f"  • {p.title()}: *{time_str}*\n"

    # Build approval buttons
    buttons = []
    # Individual platform buttons
    for p in platforms:
        buttons.append([
            InlineKeyboardButton(f"✅ {p.title()}", callback_data=f"approve_{media_id}_{p}"),
            InlineKeyboardButton(f"❌ {p.title()}", callback_data=f"deny_{media_id}_{p}"),
        ])
    # Bulk buttons
    buttons.append([
        InlineKeyboardButton("✅ Approve ALL", callback_data=f"approve_all_{media_id}"),
        InlineKeyboardButton("❌ Deny ALL", callback_data=f"deny_all_{media_id}"),
    ])
    # Edit row with music button
    edit_row = [InlineKeyboardButton("✏️ Edit Caption", callback_data=f"edit_caption_{media_id}")]
    if music_tracks:
        edit_row.append(
            InlineKeyboardButton("🎵 Music", callback_data=f"music_{media_id}"),
        )
        edit_row.append(
            InlineKeyboardButton("🔇 No Music", callback_data=f"nomusic_{media_id}"),
        )
    buttons.append(edit_row)

    keyboard = InlineKeyboardMarkup(buttons)

    full_text = f"{analysis_text}\n{platform_text}{music_text}{growth_text}"

    # Truncate if needed (Telegram message limit)
    if len(full_text) > 4000:
        full_text = full_text[:3997] + "..."

    await update.message.reply_text(
        full_text,
        parse_mode="Markdown",
        reply_markup=keyboard,
    )

    # Notify about accumulation proposals
    if accumulation:
        for proposal in accumulation:
            ptype = proposal.get("type", "collage")
            cat = proposal.get("category", "general").replace("_", " ").title()
            count = proposal.get("count", 0)
            await update.message.reply_text(
                f"📂 *Content Accumulation*\n\n"
                f"You have *{count}* {cat} {'photos' if ptype == 'collage' else 'videos'} "
                f"ready for a {'collage' if ptype == 'collage' else 'compilation'}!\n\n"
                f"Would you like me to create one?",
                parse_mode="Markdown",
                reply_markup=InlineKeyboardMarkup([
                    [
                        InlineKeyboardButton(
                            f"✅ Create {ptype.title()}",
                            callback_data=f"create_{ptype}_{proposal.get('category', 'general')}",
                        ),
                        InlineKeyboardButton("❌ Skip", callback_data="skip_accumulation"),
                    ]
                ]),
            )


# =============================================================================
# CALLBACK HANDLERS (approval buttons)
# =============================================================================


async def handle_approval_callback(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Handle inline keyboard button presses for approval/denial."""
    query = update.callback_query
    await query.answer()

    data = query.data

    if data.startswith("approve_all_"):
        media_id = int(data.split("_")[-1])
        await _process_approval(query, context, media_id, approve_all=True)

    elif data.startswith("deny_all_"):
        media_id = int(data.split("_")[-1])
        await _process_approval(query, context, media_id, deny_all=True)

    elif data.startswith("approve_"):
        parts = data.split("_")
        media_id = int(parts[1])
        platform = parts[2]
        # Track per-platform approvals
        key = f"approvals_{media_id}"
        if key not in context.bot_data:
            context.bot_data[key] = {"approved": [], "denied": []}
        context.bot_data[key]["approved"].append(platform)
        await query.edit_message_text(
            f"✅ *{platform.title()}* approved. Use 'Approve ALL' or deny remaining platforms.",
            parse_mode="Markdown",
        )

    elif data.startswith("deny_"):
        parts = data.split("_")
        if len(parts) >= 3 and parts[0] == "deny":
            media_id = int(parts[1])
            platform = parts[2]
            key = f"approvals_{media_id}"
            if key not in context.bot_data:
                context.bot_data[key] = {"approved": [], "denied": []}
            context.bot_data[key]["denied"].append(platform)
            await query.edit_message_text(
                f"❌ *{platform.title()}* denied.",
                parse_mode="Markdown",
            )

    elif data.startswith("pkg_approve_"):
        await query.edit_message_text("✅ Package approved! Creating content...")

    elif data.startswith("pkg_deny_"):
        await query.edit_message_text("❌ Package denied.")

    elif data.startswith("create_collage_") or data.startswith("create_compilation_"):
        ptype = "collage" if "collage" in data else "compilation"
        category = data.split("_", 2)[-1] if data.count("_") >= 2 else "general"
        await query.edit_message_text(
            f"🚧 *Coming Soon*\n\n"
            f"Auto-{ptype} creation for _{category.replace('_', ' ')}_ content "
            f"is under development. Stay tuned!",
            parse_mode="Markdown",
        )

    elif data == "skip_accumulation":
        await query.edit_message_text("⏭️ Skipped.")


async def handle_music_callback(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Handle music-related inline button presses (show alternatives, remove music)."""
    query = update.callback_query
    await query.answer()

    data = query.data

    if data.startswith("nomusic_"):
        media_id = int(data.split("_")[1])
        # Clear music from workflow state
        thread_id = context.bot_data.get(f"thread_{media_id}")
        if thread_id:
            try:
                from orchestrator.workflow import create_compiled_workflow
                compiled = await create_compiled_workflow()
                config = {"configurable": {"thread_id": thread_id}}
                await compiled.aupdate_state(config, {"music_tracks": {}})
            except Exception:
                pass
        await query.edit_message_text(
            "🔇 Background music removed. Re-approve to publish without music.",
            parse_mode="Markdown",
        )

    elif data.startswith("music_"):
        media_id = int(data.split("_")[1])
        thread_id = context.bot_data.get(f"thread_{media_id}")
        if not thread_id:
            await query.edit_message_text("❌ Workflow not found.")
            return

        # Show music recommendations
        try:
            from orchestrator.workflow import create_compiled_workflow
            compiled = await create_compiled_workflow()
            config = {"configurable": {"thread_id": thread_id}}
            state_snapshot = await compiled.aget_state(config)

            if not state_snapshot or not state_snapshot.values:
                await query.edit_message_text("❌ Could not retrieve workflow state.")
                return

            state = state_snapshot.values
            analysis = state.get("analysis", {})
            platforms = state.get("target_platforms", [])[:1]  # Show for primary platform
            platform = platforms[0] if platforms else "instagram"

            from agents.music_researcher import (
                recommend_music_for_content,
                format_music_for_telegram,
            )
            from memory.database import get_session_factory

            session_factory = get_session_factory()
            async with session_factory() as session:
                recs = await recommend_music_for_content(
                    session,
                    content_category=state.get("content_category", "general"),
                    content_description=analysis.get("description", ""),
                    content_mood=analysis.get("mood", "professional"),
                    platform=platform,
                    use_ai=True,
                )

            msg = format_music_for_telegram(recs, platform)
            await query.edit_message_text(
                msg,
                parse_mode="Markdown",
            )

        except Exception as e:
            await query.edit_message_text(
                f"❌ Music search error: {str(e)[:200]}",
            )


async def _process_approval(
    query,
    context: ContextTypes.DEFAULT_TYPE,
    media_id: int,
    approve_all: bool = False,
    deny_all: bool = False,
) -> None:
    """Process the approval and resume the workflow."""
    thread_id = context.bot_data.get(f"thread_{media_id}")
    if not thread_id:
        await query.edit_message_text("❌ Workflow not found. Please send the media again.")
        return

    from orchestrator.workflow import create_compiled_workflow

    compiled = await create_compiled_workflow()
    config = {"configurable": {"thread_id": thread_id}}
    state_snapshot = await compiled.aget_state(config)

    if not state_snapshot or not state_snapshot.values:
        await query.edit_message_text("❌ Workflow state not found.")
        return

    state = state_snapshot.values
    platforms = state.get("target_platforms", [])

    if approve_all:
        approved = platforms
        denied = []
    elif deny_all:
        approved = []
        denied = platforms
    else:
        key = f"approvals_{media_id}"
        approvals = context.bot_data.get(key, {"approved": [], "denied": []})
        approved = approvals["approved"]
        denied = approvals["denied"]

    if not approved and not deny_all:
        await query.edit_message_text("ℹ️ No platforms approved. Nothing to publish.")
        return

    if deny_all:
        await query.edit_message_text("❌ All platforms denied. Media archived.")
        return

    await query.edit_message_text(
        f"🚀 Publishing to: {', '.join(p.title() for p in approved)}..."
    )

    # Resume the workflow
    from orchestrator.workflow import resume_workflow_with_approval

    results = await resume_workflow_with_approval(thread_id, approved, denied)

    # Send results
    result_text = "📤 *Publishing Results:*\n\n"
    for platform, result in results.items():
        if result.get("success"):
            url = result.get("url", result.get("post_id", ""))
            note = result.get("note", "")
            result_text += f"✅ *{platform.title()}*: Published"
            if url:
                result_text += f" — {url}"
            if note:
                result_text += f" ({note})"
            result_text += "\n"
        else:
            error = result.get("error", "Unknown error")
            result_text += f"❌ *{platform.title()}*: Failed — {str(error)[:100]}\n"

    await query.message.reply_text(result_text, parse_mode="Markdown")

    # Send bonus growth content (GMB post + WhatsApp image)
    try:
        final_state = await compiled.aget_state(config)
        if final_state and final_state.values:
            fstate = final_state.values

            # Google My Business post suggestion
            gmb = fstate.get("gmb_post_suggestion")
            if gmb and gmb.get("post"):
                await query.message.reply_text(
                    f"🔍 *Google My Business Post (copy & paste):*\n\n"
                    f"_{gmb['post']}_\n\n"
                    f"CTA: {gmb.get('cta_type', 'LEARN_MORE')}\n"
                    f"📋 Post this at business.google.com for free Google visibility!",
                    parse_mode="Markdown",
                )

            # WhatsApp status image
            wa_img = fstate.get("whatsapp_status_image")
            if wa_img:
                from pathlib import Path
                wa_path = Path(wa_img)
                if wa_path.exists():
                    await query.message.reply_photo(
                        photo=open(str(wa_path), "rb"),
                        caption=(
                            "📱 *WhatsApp Status Ready!*\n"
                            "Save this image and share it on your WhatsApp Status "
                            "for free marketing to all your contacts!"
                        ),
                        parse_mode="Markdown",
                    )
    except Exception as e:
        logger.debug(f"Bonus growth content skipped: {e}")


# =============================================================================
# VOICE NOTE HANDLER
# =============================================================================


@admin_only
async def handle_voice_note(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Handle incoming voice notes — transcribe and route by intent.

    Supported intents:
      - create_job: Extract job details and start creation flow
      - post_media: Treat as caption/instruction for recent media
      - command: Execute the spoken command
      - schedule: Schedule a post
      - platform_setup: Guide through platform connection
      - ask_question: Answer using AI
    """
    from tools.speech_to_text import transcribe_voice_note, classify_voice_intent
    from security.audit_log import audit, AuditEvent, Severity

    msg = update.message
    voice = msg.voice or msg.audio

    if not voice:
        await msg.reply_text("❌ Could not process the audio.")
        return

    await msg.reply_text("🎙️ Transcribing your voice note...")

    # Download the voice note
    file = await voice.get_file()
    ext = ".ogg" if msg.voice else ".mp3"
    filename = f"voice_{msg.message_id}{ext}"
    local_path = Path(f"media/inbox/{filename}")
    local_path.parent.mkdir(parents=True, exist_ok=True)
    await file.download_to_drive(str(local_path))

    try:
        # Transcribe
        result = await transcribe_voice_note(str(local_path))
        text = result.get("text", "").strip()
        lang = result.get("language", "en")
        duration = result.get("duration_seconds", 0)

        if not text:
            await msg.reply_text("🤷 I couldn't understand the voice note. Please try again.")
            return

        await audit(
            event=AuditEvent.FILE_UPLOAD,
            severity=Severity.INFO,
            actor="telegram_bot",
            details={"type": "voice_note", "duration": duration, "language": lang, "text_length": len(text)},
        )

        # Show transcription
        await msg.reply_text(
            f"📝 *Transcription:*\n_{text}_\n\n🤖 Analyzing intent...",
            parse_mode="Markdown",
        )

        # Classify intent
        intent_result = await classify_voice_intent(text)
        intent = intent_result["intent"]
        confidence = intent_result["confidence"]
        summary = intent_result["summary"]
        extracted = intent_result.get("extracted_data", {})

        logger.info(f"Voice intent: {intent} (confidence={confidence:.2f}): {summary}")

        # Route by intent
        if intent == "create_job":
            await _handle_voice_job_creation(update, context, text, extracted)

        elif intent == "post_media":
            await msg.reply_text(
                f"💬 *Got it!* I'll use this as instructions for your next post:\n"
                f"_{summary}_\n\n"
                f"📸 Now send me a photo or video to create the post.",
                parse_mode="Markdown",
            )
            # Store the voice instruction for the next media upload
            context.user_data["voice_instruction"] = text

        elif intent == "schedule":
            await msg.reply_text(
                f"📅 *Scheduling request noted:*\n_{summary}_\n\n"
                f"Send me the content you'd like to schedule, and I'll set it up.",
                parse_mode="Markdown",
            )
            context.user_data["voice_instruction"] = text

        elif intent == "platform_setup":
            platform = extracted.get("platform", "")
            await msg.reply_text(
                f"🔗 Let me help you set up {platform or 'a platform'}!\n\n"
                f"Use the dashboard at http://localhost:8000/platforms for the easiest setup, "
                f"or type /setup here for step-by-step guidance.",
                parse_mode="Markdown",
            )

        elif intent == "command":
            await _handle_voice_command(update, context, text, summary, extracted)

        else:  # ask_question
            await _handle_voice_question(update, context, text)

    except Exception as e:
        logger.error(f"Voice note processing error: {e}", exc_info=True)
        await msg.reply_text(f"❌ Error processing voice note: {str(e)[:200]}")
    finally:
        # Clean up voice file
        local_path.unlink(missing_ok=True)


async def _handle_voice_job_creation(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
    transcribed_text: str,
    extracted_data: dict,
) -> None:
    """Create a job posting from a voice note transcription."""
    from tools.speech_to_text import extract_job_from_voice
    from agents.schemas import JobRequest
    from agents.job_manager import create_job_listing
    from memory.database import get_session_factory

    msg = update.message

    # Extract structured job info from the voice transcript
    job_data = await extract_job_from_voice(transcribed_text)

    # Merge with any data already extracted by intent classifier
    if extracted_data:
        for key in ("title", "department", "experience_required", "salary_range"):
            if extracted_data.get(key) and not job_data.get(key):
                job_data[key] = extracted_data[key]

    if not job_data.get("title"):
        await msg.reply_text(
            "🤔 I couldn't determine the job title from your voice note.\n"
            "Please try again or use /job for the guided flow."
        )
        return

    # Show extracted job details for confirmation
    skills_str = ", ".join(job_data.get("key_skills", [])) or "Not specified"
    summary = (
        f"💼 *Job from Voice Note:*\n\n"
        f"*Title:* {job_data['title']}\n"
        f"*Department:* {job_data.get('department', 'General')}\n"
        f"*Experience:* {job_data.get('experience_required', 'Not specified')}\n"
        f"*Skills:* {skills_str}\n"
        f"*Salary:* {job_data.get('salary_range', 'Competitive')}\n"
        f"*Notes:* {job_data.get('notes', 'None')}\n\n"
        f"Create and post this job?"
    )

    # Store job data for the callback
    context.user_data["voice_job_data"] = job_data

    keyboard = InlineKeyboardMarkup([
        [
            InlineKeyboardButton("✅ Create & Post", callback_data="voice_job_confirm"),
            InlineKeyboardButton("✏️ Edit via /job", callback_data="voice_job_edit"),
            InlineKeyboardButton("❌ Cancel", callback_data="voice_job_cancel"),
        ]
    ])

    await msg.reply_text(summary, parse_mode="Markdown", reply_markup=keyboard)


async def _handle_voice_command(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
    text: str,
    summary: str,
    extracted: dict,
) -> None:
    """Route a spoken command to the appropriate handler."""
    msg = update.message
    text_lower = text.lower()

    if any(w in text_lower for w in ["status", "statistics", "stats"]):
        await status_command(update, context)
    elif any(w in text_lower for w in ["growth", "report", "brief"]):
        await growth_command(update, context)
    elif any(w in text_lower for w in ["suggest", "idea", "gap"]):
        await suggest_command(update, context)
    elif any(w in text_lower for w in ["package", "promotion", "deal"]):
        await packages_command(update, context)
    elif any(w in text_lower for w in ["seo", "keyword", "search"]):
        await seo_command(update, context)
    elif any(w in text_lower for w in ["recycle", "repurpose", "reuse"]):
        await recycle_command(update, context)
    elif any(w in text_lower for w in ["series", "plan", "weekly"]):
        await series_command(update, context)
    elif any(w in text_lower for w in ["engage", "reply", "comment", "faq"]):
        await engage_command(update, context)
    elif any(w in text_lower for w in ["free ad", "free market", "advertis"]):
        await freeads_command(update, context)
    else:
        await msg.reply_text(
            f"🤖 I understood: _{summary}_\n\n"
            f"But I'm not sure which command to run. Try one of these:\n"
            f"/status /growth /suggest /packages /seo /recycle /series /engage /freeads",
            parse_mode="Markdown",
        )


async def _handle_voice_question(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
    text: str,
) -> None:
    """Answer a question using GPT-4o-mini with platform context."""
    msg = update.message

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
                        "You are the AI assistant for a marketing platform. "
                        "You help with social media marketing, content creation, platform setup, "
                        "job postings, and growth strategies. Keep answers concise and helpful. "
                        "If the user asks about connecting platforms, tell them to use the dashboard "
                        "or the /setup command in Telegram."
                    ),
                },
                {"role": "user", "content": text},
            ],
            temperature=0.7,
            max_tokens=500,
        )

        answer = response.choices[0].message.content
        await msg.reply_text(f"🤖 {answer}", parse_mode="Markdown")

    except Exception as e:
        await msg.reply_text(
            f"I heard your question but couldn't generate an answer right now.\n"
            f"Try one of these commands instead: /status /growth /suggest /help"
        )


async def handle_voice_job_callback(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Handle voice job creation confirmation/cancellation."""
    query = update.callback_query
    await query.answer()

    if query.data == "voice_job_cancel":
        await query.edit_message_text("❌ Job posting from voice note cancelled.")
        context.user_data.pop("voice_job_data", None)
        return

    if query.data == "voice_job_edit":
        await query.edit_message_text(
            "✏️ Switching to guided job flow. Use /job to fill in details step by step."
        )
        context.user_data.pop("voice_job_data", None)
        return

    if query.data == "voice_job_confirm":
        job_data = context.user_data.pop("voice_job_data", None)
        if not job_data:
            await query.edit_message_text("❌ Job data expired. Please send the voice note again.")
            return

        await query.edit_message_text("🔨 Creating job posting from voice note...")

        from agents.schemas import JobRequest
        from agents.job_manager import create_job_listing
        from agents.caption_writer import generate_job_description
        from memory.database import get_session_factory

        request = JobRequest(
            title=job_data["title"],
            department=job_data.get("department", "General"),
            experience_required=job_data.get("experience_required", ""),
            key_skills=job_data.get("key_skills", []),
            salary_range=job_data.get("salary_range", ""),
            additional_notes=job_data.get("notes", ""),
        )

        session_factory = get_session_factory()
        async with session_factory() as session:
            job = await create_job_listing(session, request)

        # Generate platform-specific versions
        platforms_to_post = ["linkedin", "facebook", "instagram"]
        for platform in platforms_to_post:
            caption = await generate_job_description(
                title=request.title,
                department=request.department,
                experience=request.experience_required,
                skills=request.key_skills,
                salary_range=request.salary_range,
                notes=request.additional_notes,
                platform=platform,
            )
            await query.message.reply_text(
                f"📋 *{platform.title()} version:*\n\n{caption.caption}\n\n"
                f"{'  '.join('#' + h for h in caption.hashtags[:10])}",
                parse_mode="Markdown",
                reply_markup=InlineKeyboardMarkup([
                    [
                        InlineKeyboardButton(
                            f"✅ Post to {platform.title()}",
                            callback_data=f"post_job_{job.id}_{platform}",
                        ),
                        InlineKeyboardButton("❌ Skip", callback_data="skip_job_platform"),
                    ]
                ]),
            )

        await query.message.reply_text(
            "🎙️ Job created from voice note! Review the versions above and approve posting."
        )


# =============================================================================
# JOB POSTING CONVERSATION
# =============================================================================


@admin_only
async def job_start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    """Start the job posting flow."""
    await update.message.reply_text(
        "💼 *Create Job Posting*\n\nWhat is the job title?",
        parse_mode="Markdown",
    )
    return JOB_TITLE


async def job_title(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data["job_title"] = update.message.text

    # Load configurable departments from business settings
    default_departments = [
        ["OPD", "Laboratory"],
        ["Pharmacy", "Aesthetics"],
        ["Radiology", "Administration"],
    ]

    # Check if business has custom departments
    business_id = _get_business_id(context)
    try:
        from memory.database import get_session_factory
        from memory.models import Business
        from sqlalchemy import select
        import json

        session_factory = get_session_factory()
        async with session_factory() as session:
            business = (await session.execute(
                select(Business).where(Business.id == business_id)
            )).scalar_one_or_none()

            if business and business.custom_categories:
                custom = json.loads(business.custom_categories)
                departments = custom.get("departments", None)
                if departments:
                    # Group into rows of 2 for keyboard
                    default_departments = [
                        departments[i:i+2] for i in range(0, len(departments), 2)
                    ]
    except Exception:
        pass  # Use defaults on any error

    keyboard = ReplyKeyboardMarkup(
        default_departments,
        one_time_keyboard=True,
        resize_keyboard=True,
    )
    await update.message.reply_text("Which department?", reply_markup=keyboard)
    return JOB_DEPARTMENT


async def job_department(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data["job_department"] = update.message.text
    await update.message.reply_text("Experience required? (e.g., '2-3 years', 'Fresh graduate')")
    return JOB_EXPERIENCE


async def job_experience(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data["job_experience"] = update.message.text
    await update.message.reply_text("Key skills needed? (comma-separated)")
    return JOB_SKILLS


async def job_skills(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data["job_skills"] = [s.strip() for s in update.message.text.split(",")]
    await update.message.reply_text("Salary range? (or 'skip' for 'Competitive')")
    return JOB_SALARY


async def job_salary(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    text = update.message.text
    context.user_data["job_salary"] = "" if text.lower() == "skip" else text
    await update.message.reply_text("Any additional notes? (or 'skip')")
    return JOB_NOTES


async def job_notes(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    text = update.message.text
    context.user_data["job_notes"] = "" if text.lower() == "skip" else text

    # Show summary for confirmation
    ud = context.user_data
    summary = (
        f"💼 *Job Posting Summary:*\n\n"
        f"*Title:* {ud['job_title']}\n"
        f"*Department:* {ud['job_department']}\n"
        f"*Experience:* {ud['job_experience']}\n"
        f"*Skills:* {', '.join(ud['job_skills'])}\n"
        f"*Salary:* {ud['job_salary'] or 'Competitive'}\n"
        f"*Notes:* {ud['job_notes'] or 'None'}\n\n"
        f"Create and post this job?"
    )

    keyboard = InlineKeyboardMarkup([
        [
            InlineKeyboardButton("✅ Create & Post", callback_data="job_confirm"),
            InlineKeyboardButton("❌ Cancel", callback_data="job_cancel"),
        ]
    ])

    await update.message.reply_text(summary, parse_mode="Markdown", reply_markup=keyboard)
    return JOB_CONFIRM


async def job_confirm_callback(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    """Handle job creation confirmation."""
    query = update.callback_query
    await query.answer()

    if query.data == "job_cancel":
        await query.edit_message_text("❌ Job posting cancelled.")
        return ConversationHandler.END

    await query.edit_message_text("🔨 Creating job posting...")

    from agents.schemas import JobRequest
    from agents.job_manager import create_job_listing
    from agents.caption_writer import generate_job_description
    from memory.database import get_session_factory

    ud = context.user_data
    request = JobRequest(
        title=ud["job_title"],
        department=ud["job_department"],
        experience_required=ud["job_experience"],
        key_skills=ud["job_skills"],
        salary_range=ud.get("job_salary", ""),
        additional_notes=ud.get("job_notes", ""),
    )

    session_factory = get_session_factory()
    async with session_factory() as session:
        job = await create_job_listing(session, request)

    # Generate platform-specific versions
    platforms_to_post = ["linkedin", "facebook", "instagram"]
    for platform in platforms_to_post:
        caption = await generate_job_description(
            title=request.title,
            department=request.department,
            experience=request.experience_required,
            skills=request.key_skills,
            salary_range=request.salary_range,
            notes=request.additional_notes,
            platform=platform,
        )
        await query.message.reply_text(
            f"📋 *{platform.title()} version:*\n\n{caption.caption}\n\n"
            f"{'  '.join('#' + h for h in caption.hashtags[:10])}",
            parse_mode="Markdown",
            reply_markup=InlineKeyboardMarkup([
                [
                    InlineKeyboardButton(
                        f"✅ Post to {platform.title()}",
                        callback_data=f"post_job_{job.id}_{platform}",
                    ),
                    InlineKeyboardButton("❌ Skip", callback_data="skip_job_platform"),
                ]
            ]),
        )

    return ConversationHandler.END


async def job_cancel(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    await update.message.reply_text("❌ Job posting cancelled.")
    return ConversationHandler.END


# =============================================================================
# RESUME HANDLING
# =============================================================================


@admin_only
async def handle_document(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Handle document uploads — validate then check if it's a resume (PDF/DOCX)."""
    from security.file_guard import validate_file
    from security.audit_log import audit, AuditEvent, Severity

    doc = update.message.document
    file_name = doc.file_name or "document"
    ext = Path(file_name).suffix.lower()

    if ext not in (".pdf", ".docx", ".doc"):
        await update.message.reply_text(
            "📄 I received a document but it doesn't look like a resume (PDF/DOCX). "
            "If it's a resume, please send it as a PDF or DOCX file."
        )
        return

    # It's likely a resume — download it
    file = await doc.get_file()
    filename = generate_filename(file_name, prefix="resume")
    local_path = resumes_path() / filename
    await file.download_to_drive(str(local_path))

    # ── Security: validate the uploaded document ──────────────────────
    validation = validate_file(
        file_path=str(local_path),
        original_filename=filename,
        allow_documents=True,
    )
    if not validation.is_safe:
        local_path.unlink(missing_ok=True)
        await audit(
            event=AuditEvent.FILE_REJECTED,
            severity=Severity.HIGH,
            actor="telegram_bot",
            details={"filename": filename, "issues": validation.issues},
        )
        await update.message.reply_text(
            f"⚠️ Document rejected by security scan:\n"
            + "\n".join(f"• {i}" for i in validation.issues)
        )
        return

    await audit(
        event=AuditEvent.FILE_UPLOAD,
        severity=Severity.INFO,
        actor="telegram_bot",
        details={"filename": filename, "type": "document", "size": validation.file_size},
    )

    # Check for open jobs
    from agents.job_manager import get_open_jobs
    from memory.database import get_session_factory

    session_factory = get_session_factory()
    async with session_factory() as session:
        jobs = await get_open_jobs(session)

    if not jobs:
        await update.message.reply_text(
            "📄 Resume received, but there are no open job listings. "
            "Create one with /job first."
        )
        return

    # Let user pick which job to screen against
    buttons = []
    for job in jobs:
        buttons.append([
            InlineKeyboardButton(
                f"{job.title} ({job.department})",
                callback_data=f"screen_resume_{job.id}_{filename}",
            )
        ])

    await update.message.reply_text(
        "📄 Resume received! Which job should I screen this against?",
        reply_markup=InlineKeyboardMarkup(buttons),
    )

    # Store resume path for callback
    context.bot_data[f"resume_{filename}"] = str(local_path)


async def handle_resume_screening_callback(
    update: Update, context: ContextTypes.DEFAULT_TYPE
) -> None:
    """Process resume screening after job selection."""
    query = update.callback_query
    await query.answer()

    if not query.data.startswith("screen_resume_"):
        return

    parts = query.data.replace("screen_resume_", "").split("_", 1)
    job_id = int(parts[0])
    filename = parts[1]

    resume_path = context.bot_data.get(f"resume_{filename}")
    if not resume_path:
        await query.edit_message_text("❌ Resume file not found. Please send again.")
        return

    await query.edit_message_text("🔍 Screening resume...")

    from agents.job_manager import screen_resume
    from memory.database import get_session_factory

    session_factory = get_session_factory()
    async with session_factory() as session:
        candidate = await screen_resume(session, job_id, resume_path)

    # Parse strengths/weaknesses
    try:
        strengths = json.loads(candidate.strengths) if candidate.strengths else []
        weaknesses = json.loads(candidate.weaknesses) if candidate.weaknesses else []
    except (json.JSONDecodeError, TypeError):
        strengths = [candidate.strengths] if candidate.strengths else []
        weaknesses = [candidate.weaknesses] if candidate.weaknesses else []

    score_emoji = "🟢" if candidate.match_score >= 70 else "🟡" if candidate.match_score >= 40 else "🔴"

    result_text = (
        f"📋 *Resume Screening Result*\n\n"
        f"*Candidate:* {candidate.name}\n"
        f"{'*Email:* ' + candidate.email + chr(10) if candidate.email else ''}"
        f"{'*Phone:* ' + candidate.phone + chr(10) if candidate.phone else ''}"
        f"*Match Score:* {score_emoji} {candidate.match_score:.0f}%\n\n"
        f"*AI Assessment:*\n_{candidate.ai_summary}_\n\n"
        f"*Strengths:*\n"
        + "\n".join(f"  ✅ {s}" for s in strengths[:5])
        + "\n\n*Gaps:*\n"
        + "\n".join(f"  ⚠️ {w}" for w in weaknesses[:5])
    )

    keyboard = InlineKeyboardMarkup([
        [
            InlineKeyboardButton(
                "📅 Schedule Interview",
                callback_data=f"schedule_{candidate.id}",
            ),
            InlineKeyboardButton("❌ Pass", callback_data=f"reject_{candidate.id}"),
        ]
    ])

    await query.message.reply_text(result_text, parse_mode="Markdown", reply_markup=keyboard)


# =============================================================================
# INTERVIEW SCHEDULING CALLBACK
# =============================================================================


async def handle_schedule_callback(
    update: Update, context: ContextTypes.DEFAULT_TYPE
) -> None:
    """Handle interview scheduling request."""
    query = update.callback_query
    await query.answer()

    if query.data.startswith("schedule_"):
        candidate_id = int(query.data.split("_")[1])

        await query.edit_message_text("📅 Checking your calendar for available slots...")

        from agents.job_manager import get_available_slots

        slots = await get_available_slots(date_range_days=7)

        if not slots:
            await query.message.reply_text(
                "⚠️ Could not fetch calendar availability. "
                "Please check Google Calendar API credentials."
            )
            return

        # Show top 3 slots
        buttons = []
        for slot in slots[:3]:
            buttons.append([
                InlineKeyboardButton(
                    slot["display"],
                    callback_data=f"confirm_schedule_{candidate_id}_{slot['start']}_{slot['end']}",
                )
            ])
        buttons.append([
            InlineKeyboardButton("📅 Suggest other time", callback_data=f"custom_time_{candidate_id}")
        ])

        await query.message.reply_text(
            "📅 *Available slots:*\nPick a time for the interview:",
            parse_mode="Markdown",
            reply_markup=InlineKeyboardMarkup(buttons),
        )

    elif query.data.startswith("confirm_schedule_"):
        parts = query.data.split("_")
        candidate_id = int(parts[2])
        slot_start = parts[3]
        slot_end = parts[4]

        from agents.job_manager import schedule_interview
        from memory.database import get_session_factory
        from memory.models import Candidate, CandidateStatus
        from sqlalchemy import select

        session_factory = get_session_factory()
        async with session_factory() as session:
            result = await session.execute(
                select(Candidate).where(Candidate.id == candidate_id)
            )
            candidate = result.scalar_one_or_none()

            if not candidate:
                await query.edit_message_text("❌ Candidate not found.")
                return

            schedule_result = await schedule_interview(candidate, slot_start, slot_end)

            if schedule_result.get("success"):
                candidate.status = CandidateStatus.INTERVIEW_SCHEDULED
                candidate.interview_datetime = datetime.fromisoformat(slot_start)
                candidate.calendar_event_id = schedule_result.get("event_id")
                await session.commit()

                await query.edit_message_text(
                    f"✅ Interview scheduled!\n\n"
                    f"*Candidate:* {candidate.name}\n"
                    f"*Time:* {slot_start}\n"
                    f"*Calendar:* [Open]({schedule_result.get('link', '')})",
                    parse_mode="Markdown",
                )
            else:
                await query.edit_message_text(
                    f"❌ Scheduling failed: {schedule_result.get('error', 'Unknown error')}"
                )

    elif query.data.startswith("custom_time_"):
        candidate_id = int(query.data.replace("custom_time_", ""))
        context.user_data["scheduling_candidate_id"] = candidate_id
        await query.edit_message_text(
            "📅 *Custom Interview Time*\n\n"
            "Send me the date and time in this format:\n"
            "`YYYY-MM-DD HH:MM`\n\n"
            "Example: `2026-03-15 14:30`\n"
            "Or send /cancel to go back.",
            parse_mode="Markdown",
        )

    elif query.data.startswith("reject_"):
        candidate_id = int(query.data.split("_")[1])

        from memory.database import get_session_factory
        from memory.models import Candidate, CandidateStatus
        from sqlalchemy import select

        session_factory = get_session_factory()
        async with session_factory() as session:
            result = await session.execute(
                select(Candidate).where(Candidate.id == candidate_id)
            )
            candidate = result.scalar_one_or_none()
            if candidate:
                candidate.status = CandidateStatus.REJECTED
                await session.commit()

        await query.edit_message_text("❌ Candidate passed.")


# =============================================================================
# INSIGHTS & USAGE COMMANDS
# =============================================================================


@admin_only
async def cmd_insights(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Show recent post performance summary."""
    from memory.database import get_session_factory
    from memory.models import Post, PostStatus
    from sqlalchemy import select, desc
    from datetime import timedelta

    session_factory = get_session_factory()
    async with session_factory() as session:
        since = datetime.utcnow() - timedelta(days=30)
        posts = (await session.execute(
            select(Post)
            .where(Post.status == PostStatus.PUBLISHED)
            .where(Post.published_at >= since)
            .order_by(desc(Post.published_at))
            .limit(20)
        )).scalars().all()

        total = len(posts)
        if not total:
            await update.message.reply_text(
                "📊 No published posts in the last 30 days.\n"
                "Send me a photo or video to get started!"
            )
            return

        platform_counts: dict[str, int] = {}
        for p in posts:
            plat = p.platform or "unknown"
            platform_counts[plat] = platform_counts.get(plat, 0) + 1

        lines = ["📊 *Post Performance — Last 30 Days*\n"]
        lines.append(f"Total published: *{total}*\n")
        lines.append("*By platform:*")
        for plat, count in sorted(platform_counts.items(), key=lambda x: -x[1]):
            lines.append(f"  • {plat.title()}: {count}")

        lines.append(
            "\n💡 _Use the dashboard for detailed analytics with engagement metrics._"
        )

    await update.message.reply_text("\n".join(lines), parse_mode="Markdown")


@admin_only
async def cmd_usage(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Show AI token usage summary."""
    await _show_usage_summary(update, context)


async def _show_usage_summary(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Fetch and display AI token usage for the business."""
    business_id = _get_business_id(context)

    try:
        from services.ai_usage import get_usage_summary

        summary = await get_usage_summary(business_id)

        lines = ["💰 *AI Usage Summary*\n"]
        lines.append(f"*Business ID:* {business_id}")
        lines.append(f"*Period:* Last 30 days\n")

        total_tokens = summary.get("total_tokens", 0)
        total_cost = summary.get("total_cost_usd", 0.0)
        calls = summary.get("total_calls", 0)

        lines.append(f"🔢 API calls: *{calls}*")
        lines.append(f"🪙 Tokens used: *{total_tokens:,}*")
        lines.append(f"💵 Estimated cost: *${total_cost:.4f}*")

        by_model = summary.get("by_model", {})
        if by_model:
            lines.append("\n*By model:*")
            for model, info in by_model.items():
                lines.append(
                    f"  • {model}: {info.get('tokens', 0):,} tokens "
                    f"(${info.get('cost', 0):.4f})"
                )

        await update.message.reply_text("\n".join(lines), parse_mode="Markdown")

    except Exception as e:
        logger.warning(f"Could not fetch usage summary: {e}")
        await update.message.reply_text(
            "💰 *AI Usage*\n\n"
            "Usage tracking is not yet configured.\n"
            "Check the dashboard for detailed usage reports.",
            parse_mode="Markdown",
        )


# =============================================================================
# NATURAL LANGUAGE TEXT HANDLER
# =============================================================================


@admin_only
async def handle_text_message(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Handle natural language text messages — route by intent."""
    text = update.message.text.strip()

    # ── Training mode — collect Q&A pairs ─────────────────────────────
    if context.user_data.get("training_mode"):
        await handle_training_message(update, context)
        return

    # ── Create mode — store description for next media upload ─────────
    if context.user_data.get("create_mode"):
        context.user_data["voice_instruction"] = text
        media_type = context.user_data["create_mode"]
        await update.message.reply_text(
            f"📝 Got it! I'll use this description for your {media_type} post:\n"
            f"_{text}_\n\n"
            f"Now send me the {media_type}!",
            parse_mode="Markdown",
        )
        return

    # ── Caption editing mode ──────────────────────────────────────────
    editing_media_id = context.user_data.get("editing_caption_for")
    if editing_media_id:
        await _apply_caption_edit(update, context, editing_media_id, text)
        return

    # ── Custom interview time input ───────────────────────────────────
    candidate_id = context.user_data.get("scheduling_candidate_id")
    if candidate_id:
        await _apply_custom_schedule(update, context, candidate_id, text)
        return

    text_lower = text.lower()

    # ── Simple keyword intent detection ───────────────────────────────
    if any(w in text_lower for w in ["post", "create", "publish", "share"]):
        await update.message.reply_text(
            "📸 Sure! Send me a photo or video and I'll help you create a post.\n"
            "Or tell me what kind of content you'd like to create."
        )
    elif any(w in text_lower for w in ["schedule", "when", "plan"]):
        await update.message.reply_text(
            "📅 To schedule posts, use /posts to see your pending posts, "
            "then tap Schedule on any approved post."
        )
    elif any(w in text_lower for w in ["analytics", "stats", "report", "performance", "engagement"]):
        await cmd_insights(update, context)
    elif any(w in text_lower for w in ["help", "what can you do", "commands"]):
        await start_command(update, context)
    elif any(w in text_lower for w in ["billing", "usage", "credits", "cost", "tokens"]):
        await _show_usage_summary(update, context)
    elif any(w in text_lower for w in ["hello", "hi", "hey", "good morning", "good evening"]):
        user_name = update.effective_user.first_name or "there"
        await update.message.reply_text(
            f"👋 Hey {user_name}! How can I help you today? Type /help to see what I can do."
        )
    else:
        await _ai_assistant_response(update, context, text)


async def _ai_assistant_response(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
    message_text: str,
) -> None:
    """Send user message to AI assistant with bot personality."""
    business_id = _get_business_id(context)
    business_name = context.bot_data.get("business_name", "Your Business")

    try:
        from openai import AsyncOpenAI
        from config.settings import get_settings

        settings = get_settings()
        client = AsyncOpenAI(api_key=settings.openai_api_key)

        # Default system prompt
        system_prompt = (
            f"You are the AI marketing assistant for {business_name}. "
            "You help with social media marketing, content creation, scheduling, "
            "job postings, and growth strategies. Keep answers concise and helpful. "
            "If the user wants to create a post, tell them to send a photo or video. "
            "Available commands: /start, /status, /suggest, /packages, /growth, "
            "/freeads, /series, /recycle, /engage, /seo, /job, /jobs, "
            "/analytics, /usage, /help"
        )

        # Try to load bot personality from database
        try:
            from memory.database import get_session_factory
            from memory.models import Business
            from sqlalchemy import select

            session_factory = get_session_factory()
            async with session_factory() as session:
                business = (await session.execute(
                    select(Business).where(Business.id == business_id)
                )).scalar_one_or_none()
                if (
                    business
                    and hasattr(business, "bot_personality")
                    and business.bot_personality
                ):
                    system_prompt = business.bot_personality
        except Exception:
            pass  # Use default personality

        response = await client.chat.completions.create(
            model="gpt-4o-mini",
            messages=[
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": message_text},
            ],
            temperature=0.7,
            max_tokens=500,
        )

        answer = response.choices[0].message.content
        await update.message.reply_text(f"🤖 {answer}")

    except Exception as e:
        logger.warning(f"AI assistant error: {e}")
        await update.message.reply_text(
            "🤔 I'm not sure how to help with that.\n"
            "Try /help to see what I can do, or send me a photo/video to create a post!"
        )


async def _apply_caption_edit(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
    media_id: int,
    new_caption: str,
) -> None:
    """Apply a user-provided caption edit to the workflow state."""
    thread_id = context.user_data.pop("editing_caption_thread", None)
    context.user_data.pop("editing_caption_for", None)

    if not thread_id:
        await update.message.reply_text(
            "❌ Workflow not found. Please send the media again."
        )
        return

    try:
        from orchestrator.workflow import create_compiled_workflow

        compiled = await create_compiled_workflow()
        config = {"configurable": {"thread_id": thread_id}}
        state_snapshot = await compiled.aget_state(config)

        if not state_snapshot or not state_snapshot.values:
            await update.message.reply_text("❌ Could not retrieve workflow state.")
            return

        state = state_snapshot.values
        platforms = state.get("target_platforms", [])

        # Update captions for all platforms with the new text
        updated_captions = {}
        for p in platforms:
            existing = state.get("captions", {}).get(p, {})
            updated_captions[p] = {**existing, "caption": new_caption}

        await compiled.aupdate_state(config, {"captions": updated_captions})

        platform_list = ", ".join(p.title() for p in platforms)
        preview = new_caption[:200] + ("..." if len(new_caption) > 200 else "")
        await update.message.reply_text(
            f"✅ Caption updated for: {platform_list}\n\n"
            f"New caption:\n_{preview}_\n\n"
            "Re-approve to publish with the new caption.",
            parse_mode="Markdown",
        )

    except Exception as e:
        logger.error(f"Caption edit error: {e}", exc_info=True)
        await update.message.reply_text(f"❌ Error updating caption: {str(e)[:200]}")


async def _apply_custom_schedule(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
    candidate_id: int,
    time_text: str,
) -> None:
    """Parse a custom interview time and schedule it."""
    context.user_data.pop("scheduling_candidate_id", None)

    try:
        # Parse the user-provided datetime
        dt = datetime.strptime(time_text.strip(), "%Y-%m-%d %H:%M")
        if dt < datetime.utcnow():
            await update.message.reply_text(
                "⚠️ That time is in the past. Please provide a future date and time."
            )
            context.user_data["scheduling_candidate_id"] = candidate_id
            return

        from datetime import timedelta
        slot_start = dt.isoformat()
        slot_end = (dt + timedelta(hours=1)).isoformat()

        from agents.job_manager import schedule_interview
        from memory.database import get_session_factory
        from memory.models import Candidate, CandidateStatus
        from sqlalchemy import select

        session_factory = get_session_factory()
        async with session_factory() as session:
            result = await session.execute(
                select(Candidate).where(Candidate.id == candidate_id)
            )
            candidate = result.scalar_one_or_none()

            if not candidate:
                await update.message.reply_text("❌ Candidate not found.")
                return

            schedule_result = await schedule_interview(candidate, slot_start, slot_end)

            if schedule_result.get("success"):
                candidate.status = CandidateStatus.INTERVIEW_SCHEDULED
                candidate.interview_datetime = dt
                candidate.calendar_event_id = schedule_result.get("event_id")
                await session.commit()

                await update.message.reply_text(
                    f"✅ Interview scheduled!\n\n"
                    f"*Candidate:* {candidate.name}\n"
                    f"*Time:* {slot_start}\n"
                    f"*Calendar:* [Open]({schedule_result.get('link', '')})",
                    parse_mode="Markdown",
                )
            else:
                await update.message.reply_text(
                    f"❌ Scheduling failed: {schedule_result.get('error', 'Unknown')}"
                )

    except ValueError:
        await update.message.reply_text(
            "⚠️ Invalid format. Please use `YYYY-MM-DD HH:MM`.\n"
            "Example: `2026-03-15 14:30`",
            parse_mode="Markdown",
        )
        context.user_data["scheduling_candidate_id"] = candidate_id


# =============================================================================
# MISSING CALLBACK HANDLERS
# =============================================================================


async def handle_edit_caption_callback(
    update: Update, context: ContextTypes.DEFAULT_TYPE
) -> None:
    """Handle edit caption button — prompt user to send new caption."""
    query = update.callback_query
    await query.answer()

    media_id = int(query.data.replace("edit_caption_", ""))
    thread_id = context.bot_data.get(f"thread_{media_id}")

    if not thread_id:
        await query.edit_message_text(
            "❌ Workflow not found. Please send the media again."
        )
        return

    # Store state so the next text message is treated as a new caption
    context.user_data["editing_caption_for"] = media_id
    context.user_data["editing_caption_thread"] = thread_id

    await query.edit_message_text(
        "✏️ *Edit Caption*\n\n"
        "Send me the new caption text. I'll update all platform versions.\n"
        "Or send /cancel to keep the current captions.",
        parse_mode="Markdown",
    )


async def handle_post_job_callback(
    update: Update, context: ContextTypes.DEFAULT_TYPE
) -> None:
    """Handle job posting to a specific platform or skipping."""
    query = update.callback_query
    await query.answer()

    data = query.data

    if data == "skip_job_platform":
        await query.edit_message_text("⏭️ Skipped this platform.")
        return

    if data.startswith("post_job_"):
        parts = data.replace("post_job_", "").split("_", 1)
        job_id = int(parts[0])
        platform = parts[1] if len(parts) > 1 else "unknown"

        await query.edit_message_text(
            f"🚀 Posting job to {platform.title()}...\n"
            f"This feature is coming soon! For now, copy the text above "
            f"and post it manually to {platform.title()}."
        )


# =============================================================================
# BOT BUILDER
# =============================================================================


from datetime import datetime  # noqa: E402 (already imported above but needed for scheduling)


# ── Bot builder helpers ──────────────────────────────────────────────
def _register_handlers(app: Application) -> Application:
    """Register all command/message/callback handlers on an Application."""
    # Command handlers
    app.add_handler(CommandHandler("start", start_command))
    app.add_handler(CommandHandler("help", start_command))
    app.add_handler(CommandHandler("status", status_command))
    app.add_handler(CommandHandler("suggest", suggest_command))
    app.add_handler(CommandHandler("packages", packages_command))

    # Growth hacking commands
    app.add_handler(CommandHandler("growth", growth_command))
    app.add_handler(CommandHandler("freeads", freeads_command))
    app.add_handler(CommandHandler("series", series_command))
    app.add_handler(CommandHandler("recycle", recycle_command))
    app.add_handler(CommandHandler("engage", engage_command))
    app.add_handler(CommandHandler("seo", seo_command))

    # Insights and usage commands
    app.add_handler(CommandHandler("analytics", cmd_insights))
    app.add_handler(CommandHandler("insights", cmd_insights))
    app.add_handler(CommandHandler("usage", cmd_usage))

    # Training & personality commands
    app.add_handler(CommandHandler("train", cmd_train))
    app.add_handler(CommandHandler("done", cmd_done))
    app.add_handler(CommandHandler("personality", cmd_personality))

    # Content creation command
    app.add_handler(CommandHandler("create", cmd_create))

    # Job posting conversation
    job_conv = ConversationHandler(
        entry_points=[CommandHandler("job", job_start)],
        states={
            JOB_TITLE: [MessageHandler(filters.TEXT & ~filters.COMMAND, job_title)],
            JOB_DEPARTMENT: [MessageHandler(filters.TEXT & ~filters.COMMAND, job_department)],
            JOB_EXPERIENCE: [MessageHandler(filters.TEXT & ~filters.COMMAND, job_experience)],
            JOB_SKILLS: [MessageHandler(filters.TEXT & ~filters.COMMAND, job_skills)],
            JOB_SALARY: [MessageHandler(filters.TEXT & ~filters.COMMAND, job_salary)],
            JOB_NOTES: [MessageHandler(filters.TEXT & ~filters.COMMAND, job_notes)],
            JOB_CONFIRM: [CallbackQueryHandler(job_confirm_callback, pattern="^job_")],
        },
        fallbacks=[CommandHandler("cancel", job_cancel)],
    )
    app.add_handler(job_conv)

    # Media handlers
    app.add_handler(MessageHandler(filters.PHOTO, handle_photo))
    app.add_handler(MessageHandler(filters.VIDEO, handle_video))
    app.add_handler(MessageHandler(filters.Document.ALL, handle_document))

    # Voice note handler
    app.add_handler(MessageHandler(filters.VOICE | filters.AUDIO, handle_voice_note))

    # Callback handlers for inline buttons
    app.add_handler(CallbackQueryHandler(
        handle_edit_caption_callback,
        pattern="^edit_caption_",
    ))
    app.add_handler(CallbackQueryHandler(
        handle_post_job_callback,
        pattern="^(post_job_|skip_job_platform)",
    ))
    app.add_handler(CallbackQueryHandler(
        handle_approval_callback,
        pattern="^(approve_|deny_|pkg_|create_|skip_)",
    ))
    app.add_handler(CallbackQueryHandler(
        handle_music_callback,
        pattern="^(music_|nomusic_)",
    ))
    app.add_handler(CallbackQueryHandler(
        handle_voice_job_callback,
        pattern="^voice_job_",
    ))
    app.add_handler(CallbackQueryHandler(
        handle_resume_screening_callback,
        pattern="^screen_resume_",
    ))
    app.add_handler(CallbackQueryHandler(
        handle_schedule_callback,
        pattern="^(schedule_|confirm_schedule_|custom_time_|reject_)",
    ))
    app.add_handler(CallbackQueryHandler(
        handle_set_tone_callback,
        pattern="^set_tone_",
    ))
    app.add_handler(CallbackQueryHandler(
        handle_create_type_callback,
        pattern="^create_type_",
    ))

    # Natural language text handler (MUST be last — after all command/callback handlers)
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, handle_text_message))

    return app


def _build_tenant_bot(
    token: str,
    business_id: int,
    business_name: str = "Your Business",
    admin_chat_ids: list[int] | None = None,
) -> Application:
    """Build an Application for a specific tenant bot."""
    settings = get_settings()

    builder = Application.builder().token(token)

    # Configure proxy if set
    if settings.telegram_proxy_url:
        from telegram.request import HTTPXRequest
        req = HTTPXRequest(
            proxy=settings.telegram_proxy_url,
            connect_timeout=20.0,
            read_timeout=30.0,
        )
        builder = builder.request(req).get_updates_request(HTTPXRequest(
            proxy=settings.telegram_proxy_url,
            connect_timeout=20.0,
            read_timeout=30.0,
        ))

    app = builder.build()

    # Inject tenant metadata into bot_data for handler access
    app.bot_data["business_id"] = business_id
    app.bot_data["business_name"] = business_name
    app.bot_data["admin_chat_ids"] = admin_chat_ids or []

    return _register_handlers(app)


def build_bot_application(
    token: str | None = None,
    business_id: int = 1,
    business_name: str = "Your Business",
    admin_chat_ids: list[int] | None = None,
) -> Application:
    """Build and configure the Telegram bot application.

    Args:
        token: Bot token. Falls back to settings.telegram_bot_token.
        business_id: Tenant business ID (default 1 for legacy single-tenant).
        business_name: Business name shown in /start greeting.
        admin_chat_ids: List of authorized Telegram chat IDs.
    """
    settings = get_settings()
    bot_token = token or settings.telegram_bot_token
    admins = admin_chat_ids or (
        [settings.telegram_admin_chat_id] if settings.telegram_admin_chat_id else []
    )

    return _build_tenant_bot(bot_token, business_id, business_name, admins)


# =============================================================================
# TENANT BOT MANAGER — multi-tenant bot orchestration
# =============================================================================


class TenantBotManager:
    """Manages multiple Telegram bot instances — one per tenant business.

    Loads all active TelegramBotConfig rows from the database on startup,
    starts a polling loop for each, and supports hot-adding/removing bots
    when businesses configure (or disconnect) Telegram from the dashboard.
    """

    def __init__(self):
        self._bots: dict[int, Application] = {}  # business_id → Application
        self._running: bool = False

    @property
    def active_bots(self) -> int:
        return len(self._bots)

    async def start(self) -> None:
        """Load all active tenant bots from the database and start polling."""
        from memory.database import get_session_factory
        from memory.models import TelegramBotConfig, Business
        from security.encryption import decrypt_value
        from sqlalchemy import select
        from sqlalchemy.orm import selectinload

        self._running = True
        session_factory = get_session_factory()

        async with session_factory() as session:
            configs = (await session.execute(
                select(TelegramBotConfig)
                .options(selectinload(TelegramBotConfig.business))
                .where(TelegramBotConfig.is_active == True)
            )).scalars().all()

            for config in configs:
                try:
                    token = decrypt_value(config.bot_token)
                    admin_ids = []
                    if config.admin_chat_ids:
                        import json
                        admin_ids = [int(x) for x in json.loads(config.admin_chat_ids)]

                    business_name = config.business.name if config.business else "Business"
                    await self.add_bot(
                        business_id=config.business_id,
                        token=token,
                        business_name=business_name,
                        admin_chat_ids=admin_ids,
                    )
                except Exception as e:
                    logger.error(
                        f"Failed to start bot for business {config.business_id}: {e}"
                    )

        logger.info(f"TenantBotManager started with {self.active_bots} bot(s)")

    async def add_bot(
        self,
        business_id: int,
        token: str,
        business_name: str = "Your Business",
        admin_chat_ids: list[int] | None = None,
    ) -> bool:
        """Add and start a new tenant bot. Returns True on success."""
        if business_id in self._bots:
            logger.warning(f"Bot for business {business_id} already running, restarting...")
            await self.remove_bot(business_id)

        try:
            app = _build_tenant_bot(token, business_id, business_name, admin_chat_ids)
            await app.initialize()
            await app.start()
            await app.updater.start_polling(drop_pending_updates=True)
            self._bots[business_id] = app
            logger.info(f"Started bot for business {business_id} ({business_name})")
            return True
        except Exception as e:
            logger.error(f"Failed to add bot for business {business_id}: {e}")
            return False

    async def remove_bot(self, business_id: int) -> bool:
        """Stop and remove a tenant bot. Returns True if found and stopped."""
        app = self._bots.pop(business_id, None)
        if not app:
            return False

        try:
            await app.updater.stop()
            await app.stop()
            await app.shutdown()
            logger.info(f"Stopped bot for business {business_id}")
            return True
        except Exception as e:
            logger.error(f"Error stopping bot for business {business_id}: {e}")
            return False

    async def restart_bot(self, business_id: int) -> bool:
        """Restart a bot with updated config from the database."""
        from memory.database import get_session_factory
        from memory.models import TelegramBotConfig, Business
        from security.encryption import decrypt_value
        from sqlalchemy import select
        from sqlalchemy.orm import selectinload
        import json

        session_factory = get_session_factory()
        async with session_factory() as session:
            config = (await session.execute(
                select(TelegramBotConfig)
                .options(selectinload(TelegramBotConfig.business))
                .where(TelegramBotConfig.business_id == business_id)
            )).scalar_one_or_none()

            if not config or not config.is_active:
                await self.remove_bot(business_id)
                return False

            token = decrypt_value(config.bot_token)
            admin_ids = []
            if config.admin_chat_ids:
                admin_ids = [int(x) for x in json.loads(config.admin_chat_ids)]
            business_name = config.business.name if config.business else "Business"

        return await self.add_bot(business_id, token, business_name, admin_ids)

    async def stop_all(self) -> None:
        """Stop all running tenant bots."""
        self._running = False
        business_ids = list(self._bots.keys())
        for bid in business_ids:
            await self.remove_bot(bid)
        logger.info("TenantBotManager: all bots stopped")

    def get_status(self) -> list[dict]:
        """Get status of all running bots."""
        statuses = []
        for bid, app in self._bots.items():
            statuses.append({
                "business_id": bid,
                "business_name": app.bot_data.get("business_name", "Unknown"),
                "bot_username": f"@{app.bot.username}" if app.bot and app.bot.username else "Unknown",
                "admin_count": len(app.bot_data.get("admin_chat_ids", [])),
            })
        return statuses


# Singleton manager
_tenant_manager: TenantBotManager | None = None


def get_tenant_manager() -> TenantBotManager:
    """Get or create the singleton TenantBotManager."""
    global _tenant_manager
    if _tenant_manager is None:
        _tenant_manager = TenantBotManager()
    return _tenant_manager
