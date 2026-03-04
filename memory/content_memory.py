"""
Content Accumulator & Memory — tracks media inventory, detects when enough
similar content has accumulated to propose collages or compilation videos.
Uses ChromaDB for vector similarity and MySQL for structured metadata.
"""

from __future__ import annotations

import json
from datetime import datetime, timedelta
from pathlib import Path
from typing import Optional

# chromadb is imported lazily inside get_chroma_client() to avoid heavy module load
from sqlalchemy import select, func, and_
from sqlalchemy.ext.asyncio import AsyncSession

from memory.models import MediaItem, Post, ContentCalendar, ContentCategory, Platform, PostStatus
from config.settings import get_settings, PROJECT_ROOT


# ── ChromaDB setup (embedded, local, free) ────────────────────────────────────

_chroma_client = None


def get_chroma_client():
    import chromadb
    global _chroma_client
    if _chroma_client is None:
        chroma_path = str(PROJECT_ROOT / "chroma_data")
        _chroma_client = chromadb.PersistentClient(path=chroma_path)
    return _chroma_client


def get_media_collection():
    """Get or create the media collection for content similarity search."""
    client = get_chroma_client()
    return client.get_or_create_collection(
        name="media_content",
        metadata={"description": "Media content embeddings for similarity search"},
    )


# ── Content tracking ─────────────────────────────────────────────────────────


async def store_media_embedding(
    media_item_id: int,
    description: str,
    category: str,
    metadata: dict,
) -> None:
    """Store a media item's description embedding for similarity search."""
    collection = get_media_collection()
    collection.upsert(
        ids=[str(media_item_id)],
        documents=[description],
        metadatas=[{
            "category": category,
            "media_item_id": media_item_id,
            "created_at": datetime.now().isoformat(),
            **{k: str(v) for k, v in metadata.items()},
        }],
    )


async def find_similar_content(
    description: str,
    n_results: int = 5,
    category_filter: Optional[str] = None,
) -> list[dict]:
    """Find similar past content by semantic similarity."""
    collection = get_media_collection()
    where_filter = {"category": category_filter} if category_filter else None

    try:
        results = collection.query(
            query_texts=[description],
            n_results=n_results,
            where=where_filter,
        )
    except Exception:
        return []

    items = []
    if results and results["ids"] and results["ids"][0]:
        for i, doc_id in enumerate(results["ids"][0]):
            items.append({
                "media_item_id": int(doc_id),
                "document": results["documents"][0][i] if results["documents"] else "",
                "distance": results["distances"][0][i] if results["distances"] else 0,
                "metadata": results["metadatas"][0][i] if results["metadatas"] else {},
            })
    return items


async def is_duplicate_content(
    description: str,
    similarity_threshold: float = 0.15,  # Lower = more similar
) -> bool:
    """Check if very similar content was already posted recently."""
    similar = await find_similar_content(description, n_results=1)
    if similar and similar[0]["distance"] < similarity_threshold:
        return True
    return False


# ── Accumulation detection ────────────────────────────────────────────────────


async def get_category_counts(
    session: AsyncSession,
    since_days: int = 30,
) -> dict[str, int]:
    """Count unposted media items per category from the last N days."""
    cutoff = datetime.now() - timedelta(days=since_days)

    result = await session.execute(
        select(
            MediaItem.content_category,
            func.count(MediaItem.id),
        )
        .where(
            and_(
                MediaItem.created_at >= cutoff,
                MediaItem.is_used_in_collage == False,
                MediaItem.is_used_in_compilation == False,
            )
        )
        .group_by(MediaItem.content_category)
    )

    return {str(row[0].value) if row[0] else "general": row[1] for row in result.fetchall()}


async def get_items_for_collage(
    session: AsyncSession,
    category: str,
    limit: int = 4,
) -> list[MediaItem]:
    """Get unused media items of a specific category for collage creation."""
    try:
        cat = ContentCategory(category)
    except ValueError:
        cat = ContentCategory.GENERAL

    result = await session.execute(
        select(MediaItem)
        .where(
            and_(
                MediaItem.content_category == cat,
                MediaItem.is_used_in_collage == False,
                MediaItem.media_type == "photo",
            )
        )
        .order_by(MediaItem.created_at.desc())
        .limit(limit)
    )
    return list(result.scalars().all())


async def get_items_for_compilation(
    session: AsyncSession,
    category: str,
    limit: int = 5,
) -> list[MediaItem]:
    """Get unused video items for creating a compilation."""
    try:
        cat = ContentCategory(category)
    except ValueError:
        cat = ContentCategory.GENERAL

    result = await session.execute(
        select(MediaItem)
        .where(
            and_(
                MediaItem.content_category == cat,
                MediaItem.is_used_in_compilation == False,
                MediaItem.media_type == "video",
            )
        )
        .order_by(MediaItem.created_at.desc())
        .limit(limit)
    )
    return list(result.scalars().all())


async def check_accumulation_triggers(
    session: AsyncSession,
    photo_collage_threshold: int = 4,
    video_compilation_threshold: int = 3,
) -> list[dict]:
    """
    Check if any content category has accumulated enough items
    to propose a collage or compilation.

    Returns a list of proposals like:
    [{"type": "collage", "category": "hydrafacial", "count": 5}, ...]
    """
    proposals = []
    counts = await get_category_counts(session)

    for category, count in counts.items():
        # Check photos for collage
        photos = await get_items_for_collage(session, category, limit=photo_collage_threshold)
        if len(photos) >= photo_collage_threshold:
            proposals.append({
                "type": "collage",
                "category": category,
                "count": len(photos),
                "media_ids": [p.id for p in photos],
            })

        # Check videos for compilation
        videos = await get_items_for_compilation(session, category, limit=video_compilation_threshold)
        if len(videos) >= video_compilation_threshold:
            proposals.append({
                "type": "compilation",
                "category": category,
                "count": len(videos),
                "media_ids": [v.id for v in videos],
            })

    return proposals


# ── Content calendar helpers ──────────────────────────────────────────────────


async def get_recent_post_categories(
    session: AsyncSession,
    days: int = 7,
) -> list[str]:
    """Get categories posted in the last N days to avoid repetition."""
    cutoff = datetime.now() - timedelta(days=days)

    result = await session.execute(
        select(ContentCalendar.content_category)
        .where(ContentCalendar.posted_at >= cutoff)
        .distinct()
    )
    return [str(row[0].value) for row in result.fetchall()]


async def get_content_gap_suggestions(
    session: AsyncSession,
) -> list[str]:
    """
    Identify which service categories haven't been posted about recently,
    suggesting content gaps to fill.
    """
    all_categories = [c.value for c in ContentCategory if c not in (
        ContentCategory.JOB_POSTING, ContentCategory.GENERAL
    )]
    recent = await get_recent_post_categories(session, days=14)

    gaps = [c for c in all_categories if c not in recent]
    return gaps


async def log_post_to_calendar(
    session: AsyncSession,
    post_id: int,
    platform: Platform,
    category: ContentCategory,
) -> None:
    """Record a post in the content calendar for tracking."""
    entry = ContentCalendar(
        post_id=post_id,
        platform=platform,
        content_category=category,
        posted_at=datetime.now(),
    )
    session.add(entry)
    await session.commit()
