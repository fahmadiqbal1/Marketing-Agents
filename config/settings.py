"""
Centralized application settings loaded from environment variables.
Uses pydantic-settings for validation and type coercion.
"""

from __future__ import annotations

import os
from pathlib import Path
from functools import lru_cache

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


PROJECT_ROOT = Path(__file__).resolve().parent.parent
MEDIA_ROOT = PROJECT_ROOT / "media"


class Settings(BaseSettings):
    """All configuration lives here. Values come from .env file or env vars."""

    model_config = SettingsConfigDict(
        env_file=str(PROJECT_ROOT / "config" / ".env"),
        env_file_encoding="utf-8",
        extra="ignore",
    )

    # ── Telegram ──────────────────────────────────────────────────────────
    telegram_bot_token: str = ""
    telegram_admin_chat_id: int = 0

    # ── MySQL ─────────────────────────────────────────────────────────────
    mysql_host: str = "localhost"
    mysql_port: int = 3306
    mysql_user: str = "marketing_platform"
    mysql_password: str = ""
    mysql_database: str = "marketing_platform"

    @property
    def mysql_url(self) -> str:
        return (
            f"mysql+aiomysql://{self.mysql_user}:{self.mysql_password}"
            f"@{self.mysql_host}:{self.mysql_port}/{self.mysql_database}"
        )

    @property
    def mysql_url_sync(self) -> str:
        return (
            f"mysql+pymysql://{self.mysql_user}:{self.mysql_password}"
            f"@{self.mysql_host}:{self.mysql_port}/{self.mysql_database}"
        )

    # ── Anthropic Claude ──────────────────────────────────────────────────
    anthropic_api_key: str = ""
    claude_model: str = "claude-opus-4-6"

    # ── OpenAI ────────────────────────────────────────────────────────────
    openai_api_key: str = ""

    # ── Google Gemini ─────────────────────────────────────────────────────
    google_gemini_api_key: str = ""

    # ── Google OAuth (YouTube + Calendar) ─────────────────────────────────
    google_client_id: str = ""
    google_client_secret: str = ""

    # ── Meta / Facebook / Instagram (DEPRECATED — use DB credentials) ────
    meta_app_id: str = ""
    meta_app_secret: str = ""
    meta_page_access_token: str = ""
    instagram_business_account_id: str = ""

    # ── LinkedIn (DEPRECATED — use DB credentials) ────────────────────────
    linkedin_client_id: str = ""
    linkedin_client_secret: str = ""
    linkedin_access_token: str = ""
    linkedin_organization_id: str = ""

    # ── TikTok (DEPRECATED — use DB credentials) ─────────────────────────
    tiktok_client_key: str = ""
    tiktok_client_secret: str = ""
    tiktok_access_token: str = ""
    tiktok_refresh_token: str = ""

    # ── Twitter / X (DEPRECATED — use DB credentials) ─────────────────────
    twitter_api_key: str = ""
    twitter_api_secret: str = ""
    twitter_access_token: str = ""
    twitter_access_token_secret: str = ""
    twitter_bearer_token: str = ""

    # ── SerpAPI ───────────────────────────────────────────────────────────
    serpapi_api_key: str = ""

    # ── Redis ─────────────────────────────────────────────────────────────
    redis_url: str = "redis://localhost:6379/0"

    # ── Media Storage ─────────────────────────────────────────────────────
    media_storage_path: str = str(MEDIA_ROOT)

    # ── Legacy Brand Info (use Business table instead) ─────────────────
    # These are fallback defaults; all brand info should come from DB
    default_website: str = ""
    default_phone: str = ""
    default_address: str = ""

    # ── Operational Defaults ──────────────────────────────────────────────
    max_hashtags_instagram: int = 20
    max_hashtags_tiktok: int = 5
    max_hashtags_linkedin: int = 5
    max_hashtags_facebook: int = 3
    max_hashtags_twitter: int = 5
    max_hashtags_youtube: int = 15
    default_voice: str = "en-US-JennyNeural"  # Edge-TTS voice
    watermark_enabled: bool = True
    watermark_opacity: float = 0.3

    # ── Proxy / VPN (for Telegram in restricted regions) ────────────────
    # Set to a SOCKS5 or HTTP proxy URL if Telegram is blocked
    # WARP example: socks5://127.0.0.1:40000
    # Leave empty to connect directly
    telegram_proxy_url: str = ""

    # ── Background Music ──────────────────────────────────────────────────
    music_enabled: bool = True
    default_music_volume: float = 0.2
    music_fade_in_seconds: float = 1.0
    music_fade_out_seconds: float = 2.0
    music_library_path: str = str(MEDIA_ROOT / "music_library")
    # ── Scheduler ─────────────────────────────────────────────────────────────
    scheduler_enabled: bool = True
    scheduler_check_interval_seconds: int = 60  # How often to check for due posts
    retry_max_attempts: int = 3
    retry_delay_seconds: int = 300  # 5 minutes between retries
    retry_backoff_multiplier: float = 2.0  # Exponential backoff

    # ── Owner / Platform-Operator ─────────────────────────────────────────────
    # Comma-separated business IDs that belong to the platform owner.
    # These businesses are never rate-limited and bypass the HTTP request
    # limiter entirely.  Credit / token spend is still tracked normally so
    # the owner can monitor costs, but the quota check will not block them.
    owner_business_ids: str = "1"   # Set to your business ID(s) in .env

    # ── Security ───────────────────────────────────────────────────────────────
    api_secret_key: str = ""       # Set in .env — protects all FastAPI endpoints
    dashboard_password: str = ""   # Simple admin password for Laravel dashboard
    encryption_key: str = ""       # Fernet encryption key for platform tokens at rest
    nsfw_threshold: float = 0.70   # NSFW classifier confidence to block (0.0–1.0)
    enable_malware_scan: bool = True
    enable_nsfw_scan: bool = True
    enable_text_scan: bool = True
    enable_pii_redaction: bool = True
    quarantine_path: str = str(MEDIA_ROOT / "quarantine")

@lru_cache()
def get_settings() -> Settings:
    """Return a cached singleton of the application settings."""
    return Settings()
