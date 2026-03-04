"""
All database models for the marketing SaaS platform.
Tables: businesses, users, platform_connections, telegram_bots,
        media_items, posts, hashtag_cache, jobs, candidates,
        promotional_packages, music_tracks, engagement_metrics,
        content_calendar, audit_log.
"""

from __future__ import annotations

import enum
from datetime import datetime
from typing import Optional

from sqlalchemy import (
    BigInteger,
    Boolean,
    DateTime,
    Enum,
    Float,
    ForeignKey,
    Index,
    Integer,
    String,
    Text,
    UniqueConstraint,
    func,
)
from sqlalchemy.orm import Mapped, mapped_column, relationship

from memory.database import Base


# ── Enums ─────────────────────────────────────────────────────────────────────


class MediaType(str, enum.Enum):
    PHOTO = "photo"
    VIDEO = "video"
    DOCUMENT = "document"


class Platform(str, enum.Enum):
    INSTAGRAM = "instagram"
    FACEBOOK = "facebook"
    YOUTUBE = "youtube"
    LINKEDIN = "linkedin"
    TIKTOK = "tiktok"
    TWITTER = "twitter"
    SNAPCHAT = "snapchat"
    PINTEREST = "pinterest"
    THREADS = "threads"


class PostStatus(str, enum.Enum):
    PENDING_ANALYSIS = "pending_analysis"
    ANALYZED = "analyzed"
    EDITING = "editing"
    EDITED = "edited"
    CAPTIONING = "captioning"
    READY_FOR_APPROVAL = "ready_for_approval"
    APPROVED = "approved"
    DENIED = "denied"
    PUBLISHING = "publishing"
    PUBLISHED = "published"
    FAILED = "failed"


class ContentCategory(str, enum.Enum):
    """Default content categories — businesses can define custom ones."""
    PRODUCT = "product"
    SERVICE = "service"
    BEHIND_THE_SCENES = "behind_the_scenes"
    EDUCATIONAL = "educational"
    PROMOTIONAL = "promotional"
    EVENT = "event"
    TESTIMONIAL = "testimonial"
    TEAM = "team"
    FACILITY = "facility"
    BEFORE_AFTER = "before_after"
    JOB_POSTING = "job_posting"
    LIFESTYLE = "lifestyle"
    NEWS = "news"
    GENERAL = "general"


class JobStatus(str, enum.Enum):
    OPEN = "open"
    CLOSED = "closed"
    FILLED = "filled"


class CandidateStatus(str, enum.Enum):
    NEW = "new"
    SCREENED = "screened"
    SHORTLISTED = "shortlisted"
    INTERVIEW_SCHEDULED = "interview_scheduled"
    INTERVIEW_COMPLETED = "interview_completed"
    HIRED = "hired"
    REJECTED = "rejected"


class PackageStatus(str, enum.Enum):
    PROPOSED = "proposed"
    APPROVED = "approved"
    DENIED = "denied"
    POSTED = "posted"


class UserRole(str, enum.Enum):
    OWNER = "owner"
    ADMIN = "admin"
    VIEWER = "viewer"


class ConnectionStatus(str, enum.Enum):
    ACTIVE = "active"
    EXPIRED = "expired"
    REVOKED = "revoked"
    ERROR = "error"


# ── Models ────────────────────────────────────────────────────────────────────


class Business(Base):
    """A business / brand managed by the system (multi-tenant core)."""

    __tablename__ = "businesses"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    name: Mapped[str] = mapped_column(String(255))
    slug: Mapped[str] = mapped_column(String(100), unique=True, index=True)
    industry: Mapped[Optional[str]] = mapped_column(String(100), nullable=True)
    website: Mapped[Optional[str]] = mapped_column(String(500), nullable=True)
    phone: Mapped[Optional[str]] = mapped_column(String(50), nullable=True)
    address: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    timezone: Mapped[str] = mapped_column(String(50), default="UTC")
    brand_voice: Mapped[Optional[str]] = mapped_column(
        Text, nullable=True
    )  # Tone/style guidelines for AI
    logo_path: Mapped[Optional[str]] = mapped_column(String(500), nullable=True)
    custom_categories: Mapped[Optional[str]] = mapped_column(
        Text, nullable=True
    )  # JSON list of custom content categories
    subscription_plan: Mapped[str] = mapped_column(String(50), default="free")
    uses_platform_api_keys: Mapped[bool] = mapped_column(Boolean, default=False)
    credit_approved: Mapped[bool] = mapped_column(Boolean, default=False)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now()
    )

    # Relationships
    users: Mapped[list["User"]] = relationship(back_populates="business")
    media_items: Mapped[list["MediaItem"]] = relationship(back_populates="business")
    posts: Mapped[list["Post"]] = relationship(back_populates="business")
    platform_connections: Mapped[list["PlatformConnection"]] = relationship(
        back_populates="business"
    )
    telegram_bots: Mapped[list["TelegramBotConfig"]] = relationship(
        back_populates="business"
    )


class User(Base):
    """A user account — each user belongs to one business."""

    __tablename__ = "users"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    business_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("businesses.id"), nullable=False
    )
    email: Mapped[str] = mapped_column(String(255), unique=True, index=True)
    password_hash: Mapped[str] = mapped_column(String(255))
    name: Mapped[str] = mapped_column(String(255))
    role: Mapped[UserRole] = mapped_column(
        Enum(UserRole), default=UserRole.OWNER
    )
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    last_login: Mapped[Optional[datetime]] = mapped_column(DateTime, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now()
    )

    # Relationships
    business: Mapped["Business"] = relationship(back_populates="users")


class PlatformConnection(Base):
    """Encrypted credential storage for a platform connected to a business."""

    __tablename__ = "platform_connections"
    __table_args__ = (
        UniqueConstraint("business_id", "platform", name="uq_business_platform"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    business_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("businesses.id"), nullable=False
    )
    platform: Mapped[Platform] = mapped_column(Enum(Platform))
    status: Mapped[ConnectionStatus] = mapped_column(
        Enum(ConnectionStatus), default=ConnectionStatus.ACTIVE
    )

    # Encrypted credentials
    access_token: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    refresh_token: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    client_id: Mapped[Optional[str]] = mapped_column(String(500), nullable=True)
    client_secret: Mapped[Optional[str]] = mapped_column(String(500), nullable=True)

    # Platform-specific extras (JSON) — e.g., instagram_business_account_id,
    # linkedin_organization_id, page_access_token
    extra_json: Mapped[Optional[str]] = mapped_column(Text, nullable=True)

    scopes: Mapped[Optional[str]] = mapped_column(String(1000), nullable=True)
    expires_at: Mapped[Optional[datetime]] = mapped_column(DateTime, nullable=True)
    connected_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now()
    )
    last_used_at: Mapped[Optional[datetime]] = mapped_column(DateTime, nullable=True)
    last_error: Mapped[Optional[str]] = mapped_column(Text, nullable=True)

    # Relationships
    business: Mapped["Business"] = relationship(back_populates="platform_connections")


class TelegramBotConfig(Base):
    """Telegram bot configuration per business."""

    __tablename__ = "telegram_bots"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    business_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("businesses.id"), nullable=False, unique=True
    )
    bot_token: Mapped[str] = mapped_column(Text)  # encrypted
    bot_username: Mapped[Optional[str]] = mapped_column(String(255), nullable=True)
    admin_chat_ids: Mapped[Optional[str]] = mapped_column(
        Text, nullable=True
    )  # JSON list of authorized chat IDs
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now()
    )

    # Relationships
    business: Mapped["Business"] = relationship(back_populates="telegram_bots")


class MediaItem(Base):
    """A photo or video received from Telegram."""

    __tablename__ = "media_items"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    business_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("businesses.id"), nullable=False
    )
    telegram_file_id: Mapped[str] = mapped_column(String(255), nullable=True)
    file_path: Mapped[str] = mapped_column(String(500))
    file_name: Mapped[str] = mapped_column(String(255))
    media_type: Mapped[MediaType] = mapped_column(Enum(MediaType))
    mime_type: Mapped[Optional[str]] = mapped_column(String(100), nullable=True)

    # Dimensions & metadata
    width: Mapped[Optional[int]] = mapped_column(Integer, nullable=True)
    height: Mapped[Optional[int]] = mapped_column(Integer, nullable=True)
    duration_seconds: Mapped[Optional[float]] = mapped_column(Float, nullable=True)
    file_size_bytes: Mapped[Optional[int]] = mapped_column(BigInteger, nullable=True)

    # AI analysis results
    content_category: Mapped[Optional[ContentCategory]] = mapped_column(
        Enum(ContentCategory), nullable=True
    )
    analysis_json: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    quality_score: Mapped[Optional[float]] = mapped_column(Float, nullable=True)

    # Tracking
    is_used_in_collage: Mapped[bool] = mapped_column(Boolean, default=False)
    is_used_in_compilation: Mapped[bool] = mapped_column(Boolean, default=False)
    created_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now()
    )

    # Relationships
    business: Mapped["Business"] = relationship(back_populates="media_items")
    posts: Mapped[list["Post"]] = relationship(back_populates="media_item")


class Post(Base):
    """A platform-specific post derived from a media item."""

    __tablename__ = "posts"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    business_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("businesses.id"), nullable=False
    )
    media_item_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("media_items.id"), nullable=False
    )
    platform: Mapped[Platform] = mapped_column(Enum(Platform))
    status: Mapped[PostStatus] = mapped_column(
        Enum(PostStatus), default=PostStatus.PENDING_ANALYSIS
    )

    # Edited media
    edited_file_path: Mapped[Optional[str]] = mapped_column(String(500), nullable=True)
    thumbnail_path: Mapped[Optional[str]] = mapped_column(String(500), nullable=True)
    file_hash: Mapped[Optional[str]] = mapped_column(
        String(64), nullable=True
    )  # SHA-256 of the edited file for integrity verification

    # Content
    caption: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    hashtags: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    title: Mapped[Optional[str]] = mapped_column(String(500), nullable=True)  # YouTube
    description: Mapped[Optional[str]] = mapped_column(Text, nullable=True)  # YouTube

    # Platform response
    platform_post_id: Mapped[Optional[str]] = mapped_column(String(255), nullable=True)
    platform_url: Mapped[Optional[str]] = mapped_column(String(500), nullable=True)

    # Engagement (updated later)
    likes: Mapped[int] = mapped_column(Integer, default=0)
    views: Mapped[int] = mapped_column(Integer, default=0)
    shares: Mapped[int] = mapped_column(Integer, default=0)
    comments_count: Mapped[int] = mapped_column(Integer, default=0)

    # Timestamps
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
    published_at: Mapped[Optional[datetime]] = mapped_column(DateTime, nullable=True)
    scheduled_for: Mapped[Optional[datetime]] = mapped_column(DateTime, nullable=True)

    # Music
    music_track_title: Mapped[Optional[str]] = mapped_column(String(255), nullable=True)
    music_track_path: Mapped[Optional[str]] = mapped_column(String(500), nullable=True)

    # LangGraph workflow tracking
    workflow_thread_id: Mapped[Optional[str]] = mapped_column(
        String(255), nullable=True
    )

    # Retry tracking
    retry_count: Mapped[int] = mapped_column(Integer, default=0)
    last_error: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    next_retry_at: Mapped[Optional[datetime]] = mapped_column(DateTime, nullable=True)

    # Relationships
    business: Mapped["Business"] = relationship(back_populates="posts")
    media_item: Mapped["MediaItem"] = relationship(back_populates="posts")


class HashtagCache(Base):
    """Pre-built hashtag database per category and platform."""

    __tablename__ = "hashtag_cache"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    category: Mapped[ContentCategory] = mapped_column(Enum(ContentCategory))
    platform: Mapped[Platform] = mapped_column(Enum(Platform))
    hashtag: Mapped[str] = mapped_column(String(100))
    relevance_score: Mapped[float] = mapped_column(Float, default=1.0)
    is_trending: Mapped[bool] = mapped_column(Boolean, default=False)
    last_updated: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now(), onupdate=func.now()
    )


class Job(Base):
    """Job listing created via the /job command."""

    __tablename__ = "jobs"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    title: Mapped[str] = mapped_column(String(255))
    department: Mapped[str] = mapped_column(String(100))
    description: Mapped[str] = mapped_column(Text)
    requirements: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    experience_required: Mapped[Optional[str]] = mapped_column(
        String(100), nullable=True
    )
    salary_range: Mapped[Optional[str]] = mapped_column(String(100), nullable=True)
    status: Mapped[JobStatus] = mapped_column(Enum(JobStatus), default=JobStatus.OPEN)
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
    closed_at: Mapped[Optional[datetime]] = mapped_column(DateTime, nullable=True)

    # Relationships
    candidates: Mapped[list["Candidate"]] = relationship(back_populates="job")


class Candidate(Base):
    """A candidate who applied (resume forwarded via Telegram)."""

    __tablename__ = "candidates"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    job_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("jobs.id"), nullable=False
    )
    name: Mapped[str] = mapped_column(String(255))
    email: Mapped[Optional[str]] = mapped_column(String(255), nullable=True)
    phone: Mapped[Optional[str]] = mapped_column(String(50), nullable=True)
    resume_path: Mapped[str] = mapped_column(String(500))
    resume_text: Mapped[Optional[str]] = mapped_column(Text, nullable=True)

    # AI screening results
    parsed_data_json: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    match_score: Mapped[Optional[float]] = mapped_column(Float, nullable=True)
    strengths: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    weaknesses: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    ai_summary: Mapped[Optional[str]] = mapped_column(Text, nullable=True)

    # Status
    status: Mapped[CandidateStatus] = mapped_column(
        Enum(CandidateStatus), default=CandidateStatus.NEW
    )
    interview_datetime: Mapped[Optional[datetime]] = mapped_column(
        DateTime, nullable=True
    )
    calendar_event_id: Mapped[Optional[str]] = mapped_column(
        String(255), nullable=True
    )

    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())

    # Relationships
    job: Mapped["Job"] = relationship(back_populates="candidates")


class PromotionalPackage(Base):
    """AI-proposed promotional packages for business services."""

    __tablename__ = "promotional_packages"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    name: Mapped[str] = mapped_column(String(255))
    description: Mapped[str] = mapped_column(Text)
    services_included: Mapped[str] = mapped_column(Text)  # JSON list
    discount_details: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    target_audience: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    occasion: Mapped[Optional[str]] = mapped_column(
        String(255), nullable=True
    )  # e.g., "World Heart Day"
    status: Mapped[PackageStatus] = mapped_column(
        Enum(PackageStatus), default=PackageStatus.PROPOSED
    )
    graphic_path: Mapped[Optional[str]] = mapped_column(String(500), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())


class MusicTrack(Base):
    """Background music track (royalty-free library + trending discoveries)."""

    __tablename__ = "music_tracks"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    title: Mapped[str] = mapped_column(String(255))
    artist: Mapped[Optional[str]] = mapped_column(String(255), nullable=True)
    platform: Mapped[str] = mapped_column(String(50), default="all")  # tiktok/instagram/youtube/all

    # Mood / genre / category tags for matching
    mood: Mapped[Optional[str]] = mapped_column(String(100), nullable=True)
    genre: Mapped[Optional[str]] = mapped_column(String(100), nullable=True)
    categories: Mapped[Optional[str]] = mapped_column(String(500), nullable=True)  # comma-separated

    # Files & source
    local_filename: Mapped[Optional[str]] = mapped_column(String(255), nullable=True)
    source_url: Mapped[Optional[str]] = mapped_column(String(500), nullable=True)
    duration_seconds: Mapped[Optional[float]] = mapped_column(Float, nullable=True)

    # Rights
    is_royalty_free: Mapped[bool] = mapped_column(Boolean, default=False)
    license_info: Mapped[Optional[str]] = mapped_column(String(255), nullable=True)

    # Trending
    trending_score: Mapped[float] = mapped_column(Float, default=0.0)
    is_trending: Mapped[bool] = mapped_column(Boolean, default=False)

    note: Mapped[Optional[str]] = mapped_column(String(500), nullable=True)

    # Timestamps
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
    last_verified: Mapped[Optional[datetime]] = mapped_column(
        DateTime, nullable=True, onupdate=func.now()
    )


class EngagementMetric(Base):
    """Tracks engagement events across platforms for analytics."""

    __tablename__ = "engagement_metrics"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    business_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("businesses.id"), nullable=False
    )
    platform: Mapped[str] = mapped_column(String(50))
    post_id: Mapped[Optional[str]] = mapped_column(String(255), nullable=True)
    event_type: Mapped[str] = mapped_column(String(50))  # like, comment, share, save, click
    count: Mapped[int] = mapped_column(Integer, default=1)
    extra_data: Mapped[Optional[str]] = mapped_column(Text, nullable=True)  # JSON
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())


class ContentCalendar(Base):
    """Tracks what was posted when for balanced content scheduling."""

    __tablename__ = "content_calendar"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    business_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("businesses.id"), nullable=False
    )
    post_id: Mapped[Optional[int]] = mapped_column(
        Integer, ForeignKey("posts.id"), nullable=True
    )
    platform: Mapped[Platform] = mapped_column(Enum(Platform))
    content_category: Mapped[ContentCategory] = mapped_column(Enum(ContentCategory))
    posted_at: Mapped[datetime] = mapped_column(DateTime)
    notes: Mapped[Optional[str]] = mapped_column(Text, nullable=True)


class PlatformAgentConfig(Base):
    """Per-business, per-platform AI agent configuration and learning data."""

    __tablename__ = "platform_agents"
    __table_args__ = (
        UniqueConstraint("business_id", "platform", name="uq_agent_business_platform"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    business_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("businesses.id"), nullable=False
    )
    platform: Mapped[Platform] = mapped_column(Enum(Platform))

    # Agent customization
    system_prompt_override: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    agent_type: Mapped[str] = mapped_column(
        String(50), default="social"
    )  # social, seo, hr

    # RAG-based self-learning
    learning_profile: Mapped[Optional[str]] = mapped_column(
        Text, nullable=True
    )  # JSON: {best_times, top_hashtags, preferred_caption_length, audience_insights}
    performance_stats: Mapped[Optional[str]] = mapped_column(
        Text, nullable=True
    )  # JSON: {avg_engagement, post_count, trend_data, win_rate}
    rag_collection_id: Mapped[Optional[str]] = mapped_column(
        String(255), nullable=True
    )  # ChromaDB collection name

    # GitHub training
    trained_from_repos: Mapped[Optional[str]] = mapped_column(
        Text, nullable=True
    )  # JSON list of repo URLs + extracted skills
    skill_version: Mapped[int] = mapped_column(Integer, default=1)

    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
    updated_at: Mapped[Optional[datetime]] = mapped_column(
        DateTime, nullable=True, onupdate=func.now()
    )

    # Relationships
    business: Mapped["Business"] = relationship()


class AiProviderConfig(Base):
    """Per-business AI provider credentials and configuration."""

    __tablename__ = "ai_provider_configs"
    __table_args__ = (
        UniqueConstraint("business_id", "provider", name="uq_business_ai_provider"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    business_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("businesses.id"), nullable=False, index=True
    )
    provider: Mapped[str] = mapped_column(String(50))  # openai, google_gemini, anthropic, mistral, deepseek, groq
    api_key_encrypted: Mapped[str] = mapped_column(Text)
    model_name: Mapped[Optional[str]] = mapped_column(String(100), nullable=True)
    is_default_text: Mapped[bool] = mapped_column(Boolean, default=False)
    is_default_vision: Mapped[bool] = mapped_column(Boolean, default=False)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    status: Mapped[str] = mapped_column(String(20), default="pending")  # pending, active, error
    last_checked_at: Mapped[Optional[datetime]] = mapped_column(DateTime, nullable=True)
    last_error: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now()
    )
    updated_at: Mapped[Optional[datetime]] = mapped_column(
        DateTime, nullable=True, onupdate=func.now()
    )

    business: Mapped["Business"] = relationship()

class AuditLog(Base):
    """Centralized security audit log — all security events recorded here."""

    __tablename__ = "audit_log"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    business_id: Mapped[Optional[int]] = mapped_column(
        Integer, ForeignKey("businesses.id"), nullable=True
    )
    event_type: Mapped[str] = mapped_column(String(100), nullable=False, index=True)
    severity: Mapped[str] = mapped_column(String(20), nullable=False, index=True)
    actor: Mapped[str] = mapped_column(String(100), nullable=False, default="system")
    details_json: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    related_id: Mapped[Optional[int]] = mapped_column(Integer, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now(), index=True
    )


class AIUsageLog(Base):
    """Tracks every AI API call per tenant for billing and quota enforcement."""

    __tablename__ = "ai_usage_log"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    business_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("businesses.id"), nullable=False, index=True
    )
    user_id: Mapped[int] = mapped_column(Integer, default=0)
    agent_name: Mapped[str] = mapped_column(String(100), nullable=False)
    model: Mapped[str] = mapped_column(String(100), nullable=False)
    operation: Mapped[str] = mapped_column(String(100), nullable=False)
    input_tokens: Mapped[int] = mapped_column(Integer, default=0)
    output_tokens: Mapped[int] = mapped_column(Integer, default=0)
    estimated_cost_usd: Mapped[float] = mapped_column(Float, default=0.0)
    created_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now(), index=True
    )

    __table_args__ = (
        Index("idx_usage_biz_date", "business_id", "created_at"),
    )


class SubscriptionPlan(Base):
    """Defines available subscription plans and their limits."""

    __tablename__ = "subscription_plans"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    name: Mapped[str] = mapped_column(String(50), unique=True, nullable=False)
    display_name: Mapped[str] = mapped_column(String(100), nullable=False)
    monthly_token_limit: Mapped[int] = mapped_column(Integer, default=100_000)
    monthly_cost_usd: Mapped[float] = mapped_column(Float, default=0.0)
    max_platforms: Mapped[int] = mapped_column(Integer, default=3)
    max_posts_per_month: Mapped[int] = mapped_column(Integer, default=50)
    max_ai_calls_per_day: Mapped[int] = mapped_column(Integer, default=100)
    features_json: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now()
    )


class BillingRecord(Base):
    """Monthly billing record per tenant — tracks AI usage costs."""

    __tablename__ = "billing_records"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    business_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("businesses.id"), nullable=False, index=True
    )
    period_start: Mapped[datetime] = mapped_column(DateTime, nullable=False)
    period_end: Mapped[datetime] = mapped_column(DateTime, nullable=False)
    ai_tokens_used: Mapped[int] = mapped_column(Integer, default=0)
    ai_cost_usd: Mapped[float] = mapped_column(Float, default=0.0)
    platform_owner_id: Mapped[Optional[int]] = mapped_column(Integer, nullable=True)
    status: Mapped[str] = mapped_column(String(20), default="pending")  # pending, paid, overdue
    created_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now()
    )
    updated_at: Mapped[Optional[datetime]] = mapped_column(
        DateTime, nullable=True, onupdate=func.now()
    )


class BotPersonality(Base):
    """Per-business Telegram bot personality and training data."""

    __tablename__ = "bot_personalities"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    business_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("businesses.id"), nullable=False, index=True
    )
    persona_name: Mapped[str] = mapped_column(String(100), default="Marketing Assistant")
    system_prompt_override: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    tone: Mapped[str] = mapped_column(String(50), default="professional")  # professional, casual, friendly, witty
    response_style: Mapped[str] = mapped_column(String(50), default="detailed")  # detailed, concise, bullet
    industry_context: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    custom_commands_json: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    trained_examples_json: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now()
    )
    updated_at: Mapped[Optional[datetime]] = mapped_column(
        DateTime, nullable=True, onupdate=func.now()
    )

    business: Mapped["Business"] = relationship()
