"""
SQLAlchemy async engine, session factory, and base model.
Uses MySQL via aiomysql driver.
"""

from __future__ import annotations

from collections.abc import AsyncGenerator

from sqlalchemy.ext.asyncio import (
    AsyncSession,
    async_sessionmaker,
    create_async_engine,
)
from sqlalchemy.orm import DeclarativeBase

from config.settings import get_settings


class Base(DeclarativeBase):
    """Base class for all ORM models."""
    pass


_engine = None
_session_factory = None


def get_engine():
    global _engine
    if _engine is None:
        settings = get_settings()
        _engine = create_async_engine(
            settings.mysql_url,
            echo=False,
            pool_size=5,
            max_overflow=10,
            pool_recycle=3600,
        )
    return _engine


def get_session_factory() -> async_sessionmaker[AsyncSession]:
    global _session_factory
    if _session_factory is None:
        _session_factory = async_sessionmaker(
            bind=get_engine(),
            class_=AsyncSession,
            expire_on_commit=False,
        )
    return _session_factory


async def get_session() -> AsyncGenerator[AsyncSession, None]:
    """Yield an async session (use as context manager)."""
    factory = get_session_factory()
    async with factory() as session:
        yield session


async def init_db():
    """Create all tables. Call once at startup."""
    # Import models so they register with Base.metadata
    import memory.models  # noqa: F401

    engine = get_engine()
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)


async def close_db():
    """Dispose the engine. Call on shutdown."""
    global _engine, _session_factory
    if _engine:
        await _engine.dispose()
        _engine = None
        _session_factory = None


# ── Business Context Utility ──────────────────────────────────────────────────

_business_cache: dict[int, dict] = {}


async def load_business_context(business_id: int, use_cache: bool = True) -> dict:
    """
    Load business context from DB for dynamic agent prompts.
    Replaces all hardcoded brand references with dynamic business context.
    Returns a dict with: name, website, phone, address, industry,
                         brand_voice, custom_categories, timezone, slug.
    """
    if use_cache and business_id in _business_cache:
        return _business_cache[business_id]

    from memory.models import Business
    from sqlalchemy import select

    factory = get_session_factory()
    async with factory() as session:
        biz = (await session.execute(
            select(Business).where(Business.id == business_id)
        )).scalar_one_or_none()

    if not biz:
        ctx = {
            "name": "Your Business",
            "website": "",
            "phone": "",
            "address": "",
            "industry": "general",
            "brand_voice": "Professional, friendly, and helpful",
            "custom_categories": [],
            "timezone": "UTC",
            "slug": "business",
        }
    else:
        import json as _json
        cats = []
        if biz.custom_categories:
            try:
                cats = _json.loads(biz.custom_categories)
            except Exception:
                cats = []
        ctx = {
            "name": biz.name or "Your Business",
            "website": biz.website or "",
            "phone": biz.phone or "",
            "address": biz.address or "",
            "industry": biz.industry or "general",
            "brand_voice": biz.brand_voice or "Professional, friendly, and helpful",
            "custom_categories": cats,
            "timezone": biz.timezone or "UTC",
            "slug": biz.slug or "business",
        }

    _business_cache[business_id] = ctx
    return ctx


def clear_business_cache(business_id: int | None = None):
    """Clear cached business context. Call after profile updates."""
    if business_id:
        _business_cache.pop(business_id, None)
    else:
        _business_cache.clear()
