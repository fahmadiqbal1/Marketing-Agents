"""
Main entry point for the AI Marketing Platform Bot.
Initializes database, seeds data, and starts the Telegram bot.
"""

from __future__ import annotations

import asyncio
import logging
import sys
from pathlib import Path

# Add project root to path
sys.path.insert(0, str(Path(__file__).resolve().parent))

import structlog

from config.settings import get_settings


def setup_logging():
    """Configure structured logging."""
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s | %(levelname)-8s | %(name)s | %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )
    # Quiet noisy libraries
    logging.getLogger("httpx").setLevel(logging.WARNING)
    logging.getLogger("httpcore").setLevel(logging.WARNING)
    logging.getLogger("telegram").setLevel(logging.WARNING)
    logging.getLogger("chromadb").setLevel(logging.WARNING)


async def initialize():
    """Initialize database, seed data, and verify configuration."""
    logger = logging.getLogger("startup")
    settings = get_settings()

    # 1. Verify critical config
    if not settings.telegram_bot_token:
        logger.error("TELEGRAM_BOT_TOKEN not set! Copy config/.env.example to config/.env and fill in values.")
        sys.exit(1)

    if not settings.telegram_admin_chat_id:
        logger.warning("TELEGRAM_ADMIN_CHAT_ID not set. Bot will reject all messages.")

    # 2. Initialize MySQL database
    logger.info("Initializing MySQL database...")
    try:
        from memory.database import init_db
        await init_db()
        logger.info("Database tables created successfully.")
    except Exception as e:
        logger.error(f"Database initialization failed: {e}")
        logger.error(
            "Make sure MySQL is running and the database exists. "
            "Run: CREATE DATABASE marketing_platform;"
        )
        sys.exit(1)

    # 3. Seed hashtag database
    logger.info("Seeding hashtag database...")
    try:
        from memory.database import get_session_factory
        from agents.hashtag_researcher import seed_hashtags

        session_factory = get_session_factory()
        async with session_factory() as session:
            await seed_hashtags(session)
        logger.info("Hashtag database ready.")
    except Exception as e:
        logger.warning(f"Hashtag seeding skipped: {e}")

    # 3b. Seed music library
    logger.info("Seeding music library...")
    try:
        from agents.music_researcher import seed_music

        session_factory = get_session_factory()
        async with session_factory() as session:
            await seed_music(session)
        logger.info("Music library ready.")
    except Exception as e:
        logger.warning(f"Music seeding skipped: {e}")

    # 4. Verify media directories
    from tools.media_utils import (
        inbox_path, processed_path, snapchat_ready_path,
        collages_path, compilations_path, resumes_path,
    )
    for p in [inbox_path, processed_path, snapchat_ready_path,
              collages_path, compilations_path, resumes_path]:
        p()  # Each call ensures the directory exists

    # Music library folder
    from pathlib import Path
    music_dir = Path(settings.music_library_path)
    music_dir.mkdir(parents=True, exist_ok=True)

    logger.info("Media directories ready.")

    # 5. Check API keys
    api_checks = [
        ("Google Gemini", settings.google_gemini_api_key, "Vision analysis"),
        ("OpenAI", settings.openai_api_key, "Caption generation"),
        ("Meta/Instagram", settings.meta_page_access_token, "Instagram/Facebook posting"),
        ("LinkedIn", settings.linkedin_access_token, "LinkedIn posting"),
        ("TikTok", settings.tiktok_access_token, "TikTok posting"),
    ]

    for name, key, purpose in api_checks:
        if key and key != f"your_{name.lower().replace('/', '_')}_key_here":
            logger.info(f"  ✓ {name} API key configured ({purpose})")
        else:
            logger.warning(f"  ✗ {name} API key missing ({purpose} will be unavailable)")

    logger.info("Initialization complete!")


def main():
    """Start the bot."""
    setup_logging()
    logger = logging.getLogger("main")

    logger.info("=" * 60)
    logger.info("  AI Marketing Platform Bot")
    logger.info("  Starting up...")
    logger.info("=" * 60)

    # Run initialization
    asyncio.run(initialize())

    # Build and run the Telegram bot
    logger.info("Starting Telegram bot...")

    from bot.telegram_bot import build_bot_application

    app = build_bot_application()
    app.run_polling(
        allowed_updates=[
            "message",
            "callback_query",
        ],
        drop_pending_updates=True,
    )


if __name__ == "__main__":
    main()
