"""
Scheduler Service — publishes scheduled posts and retries failed ones.

Runs as an asyncio background task inside the FastAPI process.
Checks the database every N seconds for:
  1. Posts with scheduled_for <= now() and status = 'approved'
  2. Posts with status = 'failed' and retry_count < max, next_retry_at <= now()
"""

from __future__ import annotations

import asyncio
import logging
from datetime import datetime, timedelta

from config.settings import get_settings

logger = logging.getLogger(__name__)

_scheduler_task: asyncio.Task | None = None


async def _publish_single_post(post_row: dict) -> dict:
    """Publish one post using the publisher agent. Returns result dict."""
    from agents.publisher import publish_to_platform

    result = await publish_to_platform(
        platform=post_row["platform"],
        file_path=post_row["edited_file_path"] or post_row["file_path"],
        caption=post_row.get("caption", ""),
        hashtags=(post_row.get("hashtags") or "").split(",") if post_row.get("hashtags") else [],
        media_type=post_row["media_type"],
        title=post_row.get("title", ""),
        description=post_row.get("description", ""),
        media_item_id=post_row["id"],
        business_id=post_row["business_id"],
    )
    return result


async def _handle_publish_result(post_id: int, result: dict, is_retry: bool = False):
    """Update post status in DB based on publish result."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    settings = get_settings()
    session_factory = get_session_factory()

    async with session_factory() as session:
        if result.get("success"):
            await session.execute(
                text(
                    "UPDATE posts SET status = 'published', "
                    "published_at = :now, "
                    "platform_post_id = :pid, "
                    "platform_url = :url, "
                    "last_error = NULL "
                    "WHERE id = :id"
                ),
                {
                    "now": datetime.utcnow(),
                    "pid": result.get("post_id", ""),
                    "url": result.get("url", ""),
                    "id": post_id,
                },
            )
            # Emit success event
            from services.event_bus import emit

            await emit("post_published", {
                "post_id": post_id,
                "platform": result.get("platform", ""),
                "url": result.get("url", ""),
            })
            logger.info(f"Post {post_id} published successfully")
        else:
            error_msg = result.get("error", "Unknown error")
            # Calculate next retry time with exponential backoff
            retry_count_result = await session.execute(
                text("SELECT retry_count FROM posts WHERE id = :id"),
                {"id": post_id},
            )
            row = retry_count_result.fetchone()
            current_retries = (row[0] if row else 0) + 1

            next_retry = None
            new_status = "failed"

            if current_retries < settings.retry_max_attempts:
                delay = settings.retry_delay_seconds * (
                    settings.retry_backoff_multiplier ** (current_retries - 1)
                )
                next_retry = datetime.utcnow() + timedelta(seconds=delay)
                new_status = "failed"  # Keep as failed, scheduler will pick it up

            await session.execute(
                text(
                    "UPDATE posts SET status = :status, "
                    "retry_count = :rc, "
                    "last_error = :err, "
                    "next_retry_at = :nrt "
                    "WHERE id = :id"
                ),
                {
                    "status": new_status,
                    "rc": current_retries,
                    "err": error_msg[:1000],
                    "nrt": next_retry,
                    "id": post_id,
                },
            )

            from services.event_bus import emit

            await emit("post_failed", {
                "post_id": post_id,
                "error": error_msg[:200],
                "retry_count": current_retries,
                "max_retries": settings.retry_max_attempts,
                "next_retry_at": str(next_retry) if next_retry else None,
            })
            logger.warning(
                f"Post {post_id} failed (attempt {current_retries}/{settings.retry_max_attempts}): {error_msg[:100]}"
            )

        await session.commit()


async def _scheduler_loop():
    """Main scheduler loop — runs forever, checking for due posts."""
    settings = get_settings()
    interval = settings.scheduler_check_interval_seconds
    logger.info(f"Scheduler started (interval={interval}s, max_retries={settings.retry_max_attempts})")

    while True:
        try:
            await _check_scheduled_posts()
            await _check_retry_posts()
        except Exception as e:
            logger.error(f"Scheduler error: {e}", exc_info=True)

        await asyncio.sleep(interval)


async def _check_scheduled_posts():
    """Find and publish posts whose scheduled_for time has arrived."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    session_factory = get_session_factory()
    now = datetime.utcnow()

    async with session_factory() as session:
        result = await session.execute(
            text(
                "SELECT p.id, p.business_id, p.platform, p.caption, p.hashtags, "
                "p.title, p.description, p.edited_file_path, "
                "m.file_path, m.media_type "
                "FROM posts p "
                "JOIN media_items m ON p.media_item_id = m.id "
                "WHERE p.scheduled_for <= :now "
                "AND p.status = 'approved' "
                "AND p.published_at IS NULL "
                "ORDER BY p.scheduled_for ASC "
                "LIMIT 10"
            ),
            {"now": now},
        )
        rows = result.fetchall()

    for row in rows:
        post = {
            "id": row[0],
            "business_id": row[1],
            "platform": row[2],
            "caption": row[3],
            "hashtags": row[4],
            "title": row[5],
            "description": row[6],
            "edited_file_path": row[7],
            "file_path": row[8],
            "media_type": row[9],
        }

        # Mark as publishing
        async with session_factory() as session:
            await session.execute(
                text("UPDATE posts SET status = 'publishing' WHERE id = :id"),
                {"id": post["id"]},
            )
            await session.commit()

        try:
            result = await _publish_single_post(post)
            await _handle_publish_result(post["id"], result)
        except Exception as e:
            await _handle_publish_result(post["id"], {"success": False, "error": str(e)})


async def _check_retry_posts():
    """Find failed posts that are due for retry."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    settings = get_settings()
    session_factory = get_session_factory()
    now = datetime.utcnow()

    async with session_factory() as session:
        result = await session.execute(
            text(
                "SELECT p.id, p.business_id, p.platform, p.caption, p.hashtags, "
                "p.title, p.description, p.edited_file_path, "
                "m.file_path, m.media_type "
                "FROM posts p "
                "JOIN media_items m ON p.media_item_id = m.id "
                "WHERE p.status = 'failed' "
                "AND p.retry_count < :max_retries "
                "AND (p.next_retry_at IS NULL OR p.next_retry_at <= :now) "
                "ORDER BY p.next_retry_at ASC "
                "LIMIT 5"
            ),
            {"max_retries": settings.retry_max_attempts, "now": now},
        )
        rows = result.fetchall()

    for row in rows:
        post = {
            "id": row[0],
            "business_id": row[1],
            "platform": row[2],
            "caption": row[3],
            "hashtags": row[4],
            "title": row[5],
            "description": row[6],
            "edited_file_path": row[7],
            "file_path": row[8],
            "media_type": row[9],
        }

        logger.info(f"Retrying post {post['id']} on {post['platform']}")

        # Mark as publishing
        async with session_factory() as session:
            await session.execute(
                text("UPDATE posts SET status = 'publishing' WHERE id = :id"),
                {"id": post["id"]},
            )
            await session.commit()

        try:
            result = await _publish_single_post(post)
            await _handle_publish_result(post["id"], result, is_retry=True)
        except Exception as e:
            await _handle_publish_result(post["id"], {"success": False, "error": str(e)}, is_retry=True)


def start_scheduler():
    """Start the scheduler as a background task. Call from FastAPI startup."""
    global _scheduler_task
    settings = get_settings()

    if not settings.scheduler_enabled:
        logger.info("Scheduler disabled in settings")
        return

    if _scheduler_task and not _scheduler_task.done():
        logger.warning("Scheduler already running")
        return

    loop = asyncio.get_event_loop()
    _scheduler_task = loop.create_task(_scheduler_loop())
    logger.info("Scheduler background task started")


def stop_scheduler():
    """Stop the scheduler background task."""
    global _scheduler_task
    if _scheduler_task and not _scheduler_task.done():
        _scheduler_task.cancel()
        logger.info("Scheduler stopped")
    _scheduler_task = None
