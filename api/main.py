"""
FastAPI REST Layer — bridges the Laravel dashboard to the Python marketing agents.

Endpoints:
  POST /api/upload          — Upload media files and start workflow
  GET  /api/posts           — List all posts with status, platform, captions
  POST /api/posts/{id}/approve — Approve a pending post for publishing
  POST /api/posts/{id}/deny    — Deny a pending post
  GET  /api/analytics       — Engagement metrics and performance data
  GET  /api/platforms       — Platform connection status
  GET  /api/strategy        — Weekly strategy brief
  GET  /api/growth-ideas    — Marketing ideas (filtered by service/platform/effort)
  GET  /api/growth-report   — Daily growth report
  GET  /api/schedule        — Optimal posting schedule
  GET  /api/pillar-balance  — Content pillar distribution analysis
  GET  /api/content-gaps    — Content gap detection
  GET  /api/health          — Service health check

  # Multi-tenant / SaaS
  POST /api/auth/register       — Register new user + business
  POST /api/auth/login          — Login and get JWT token
  POST /api/auth/logout         — Logout and revoke current JWT token
  GET  /api/auth/me             — Get current user profile + connected platforms & AI providers
  POST /api/business/setup      — Create a new business (onboarding)
  GET  /api/business/{id}/profile — Get business profile
  PUT  /api/business/{id}/profile — Update business profile
  POST /api/platforms/{p}/connect    — Save platform credentials
  POST /api/platforms/{p}/test       — Test platform connection
  POST /api/platforms/{p}/disconnect — Disconnect a platform
  GET  /api/platforms/status         — All platform connection statuses
  GET  /api/platforms/fields/{p}     — Get required fields for a platform
  POST /api/telegram/configure       — Configure Telegram bot
  POST /api/telegram/test            — Test Telegram bot token

  # AI Model Management
  GET  /api/ai-models                — List configured AI providers
  POST /api/ai-models                — Add/update AI provider (openai, gemini, anthropic, ollama, etc.)
  POST /api/ai-models/{p}/test       — Test AI provider connection
  DELETE /api/ai-models/{p}          — Remove AI provider

  # Multi-Business
  GET  /api/businesses               — List all businesses user can access
  POST /api/businesses               — Create new business with agent cloning
  POST /api/auth/switch-business     — Switch active business context

Runs on port 8001 (Laravel on 8000).
"""

from __future__ import annotations

import asyncio
import hashlib
import json
import logging
import re
import shutil
from datetime import datetime, timedelta
from pathlib import Path
from typing import Optional

import secrets
import time
from collections import defaultdict

import jwt
from fastapi import Depends, FastAPI, File, Form, HTTPException, Query, Request, UploadFile
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from pydantic import BaseModel, EmailStr, field_validator

try:
    from passlib.context import CryptContext
    _pwd_ctx = CryptContext(schemes=["bcrypt"], deprecated="auto")
except ImportError:
    _pwd_ctx = None

# Import settings early to ensure .env is loaded
from config.settings import get_settings, MEDIA_ROOT
from security.api_auth import require_api_key, require_api_key_optional

logger = logging.getLogger(__name__)

# ── JWT Config ────────────────────────────────────────────────────────────────

_settings = get_settings()
JWT_SECRET = _settings.encryption_key or secrets.token_hex(32)
if not _settings.encryption_key:
    logger.warning("ENCRYPTION_KEY not set — generated ephemeral JWT secret (tokens won't survive restarts)")
JWT_ALGORITHM = "HS256"
JWT_EXPIRY_HOURS = 72
_bearer_scheme = HTTPBearer(auto_error=False)

# ── Token Blocklist (for logout) ──────────────────────────────────────────────
# In-memory set of revoked JWT IDs.  Tokens are short-lived (72 h) so
# the set is periodically pruned to avoid unbounded growth.
_revoked_tokens: dict[str, float] = {}  # jti → expiry timestamp

# ── App Setup ─────────────────────────────────────────────────────────────────

app = FastAPI(
    title="AI Marketing Platform API",
    version="2.0.0",
    docs_url="/api/docs",
    openapi_url="/api/openapi.json",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://localhost:8000", "http://127.0.0.1:8000"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


# ── Rate Limiter ──────────────────────────────────────────────────────────────


class SlidingWindowRateLimiter:
    """In-memory sliding-window rate limiter keyed by IP + path tier."""

    TIERS: dict[str, tuple[int, int]] = {
        # path_prefix → (max_requests, window_seconds)
        "/api/auth": (30, 60),
        "/api/seo": (20, 60),
        "/api/hr": (20, 60),
        "/api/agents/train": (5, 60),
        "/api/ai-assistant": (20, 60),
        "default": (120, 60),
    }

    def __init__(self):
        self._hits: dict[str, list[float]] = defaultdict(list)

    def _get_tier(self, path: str) -> tuple[int, int]:
        for prefix, limits in self.TIERS.items():
            if prefix != "default" and path.startswith(prefix):
                return limits
        return self.TIERS["default"]

    def is_allowed(self, key: str, path: str) -> bool:
        max_req, window = self._get_tier(path)
        now = time.time()
        bucket = f"{key}:{path.split('/')[2] if len(path.split('/')) > 2 else 'root'}"
        hits = self._hits[bucket]
        # Prune old entries
        cutoff = now - window
        self._hits[bucket] = [t for t in hits if t > cutoff]
        if len(self._hits[bucket]) >= max_req:
            return False
        self._hits[bucket].append(now)
        return True


_rate_limiter = SlidingWindowRateLimiter()

# Owner business IDs bypass the rate limiter entirely.
_owner_bids: set[int] = set()
try:
    _owner_bids = {
        int(x.strip())
        for x in _settings.owner_business_ids.split(",")
        if x.strip().isdigit()
    }
except Exception:
    _owner_bids = {1}


def _is_owner_request(request: Request) -> bool:
    """Check the Authorization header for an owner JWT without raising."""
    auth = request.headers.get("authorization", "")
    if not auth.lower().startswith("bearer "):
        return False
    try:
        payload = jwt.decode(
            auth.split(" ", 1)[1], JWT_SECRET, algorithms=[JWT_ALGORITHM]
        )
        return payload.get("bid") in _owner_bids
    except Exception:
        return False


@app.middleware("http")
async def rate_limit_middleware(request: Request, call_next):
    """Apply sliding-window rate limiting per client IP.

    Owner businesses (configured via OWNER_BUSINESS_IDS in .env) are
    exempt — they still get usage-tracked but never receive a 429.
    """
    client_ip = request.client.host if request.client else "unknown"
    path = request.url.path

    # Skip rate limiting for health checks, docs, and owner requests
    if path in ("/api/health", "/api/docs", "/api/openapi.json"):
        return await call_next(request)

    if _is_owner_request(request):
        return await call_next(request)

    if not _rate_limiter.is_allowed(client_ip, path):
        from security.audit_log import audit, AuditEvent, Severity
        try:
            await audit(
                event=AuditEvent.RATE_LIMITED,
                severity=Severity.MEDIUM,
                actor=client_ip,
                details={"path": path, "ip": client_ip},
            )
        except Exception:
            pass
        return JSONResponse(
            status_code=429,
            content={"detail": "Too many requests — please slow down"},
        )
    return await call_next(request)


# ── Lifecycle Events ──────────────────────────────────────────────────────────


@app.on_event("startup")
async def startup_event():
    """Initialize database and start background services."""
    from memory.database import init_db
    from services.scheduler import start_scheduler

    try:
        await init_db()
        logger.info("Database initialized")
    except Exception as e:
        logger.error(f"Database init failed: {e}")

    # Seed existing AI provider keys from .env into DB for business 1
    try:
        await _seed_ai_keys()
    except Exception as e:
        logger.warning(f"AI key seeding skipped: {e}")

    start_scheduler()
    logger.info("Scheduler started")


async def _seed_ai_keys():
    """Seed .env API keys into ai_provider_configs for business 1 if not already present."""
    from sqlalchemy import text
    from security.encryption import encrypt
    from memory.database import get_session_factory

    settings = get_settings()
    seeds = []
    if settings.openai_api_key:
        seeds.append(("openai", settings.openai_api_key, "gpt-4o-mini"))
    if settings.google_gemini_api_key:
        seeds.append(("google_gemini", settings.google_gemini_api_key, "gemini-2.0-flash"))

    if not seeds:
        return

    async with get_session_factory()() as session:
        for provider, key, model in seeds:
            existing = await session.execute(
                text("SELECT id FROM ai_provider_configs WHERE business_id = 1 AND provider = :p"),
                {"p": provider},
            )
            encrypted = encrypt(key)
            if existing.fetchone():
                # Update existing row with fresh encryption
                await session.execute(
                    text(
                        "UPDATE ai_provider_configs SET api_key_encrypted = :k, model_name = :m, "
                        "status = 'active', is_active = 1 WHERE business_id = 1 AND provider = :p"
                    ),
                    {"p": provider, "k": encrypted, "m": model},
                )
                logger.info(f"Updated AI provider seed: {provider}")
                continue
            await session.execute(
                text(
                    "INSERT INTO ai_provider_configs (business_id, provider, api_key_encrypted, model_name, "
                    "status, is_active, created_at) VALUES (1, :p, :k, :m, 'active', 1, NOW())"
                ),
                {"p": provider, "k": encrypted, "m": model},
            )
            logger.info(f"Seeded AI provider: {provider}")
        await session.commit()


@app.on_event("shutdown")
async def shutdown_event():
    """Stop background services and close connections."""
    from services.scheduler import stop_scheduler
    from memory.database import close_db

    stop_scheduler()
    await close_db()
    logger.info("Shutdown complete")


# ── Response Models ───────────────────────────────────────────────────────────

class UploadResponse(BaseModel):
    success: bool
    media_item_id: int | None = None
    thread_id: str | None = None
    message: str = ""


class PostItem(BaseModel):
    id: int
    platform: str
    caption: str | None = None
    media_type: str | None = None
    media_path: str | None = None
    status: str = "pending"
    posted_at: str | None = None
    thread_id: str | None = None


class ApprovalResponse(BaseModel):
    success: bool
    message: str = ""
    publish_results: dict | None = None


# ── Multi-tenant response / request models ────────────────────────────────────

class RegisterRequest(BaseModel):
    email: str
    password: str
    name: str
    business_name: str
    industry: str | None = None

    @field_validator("name", "business_name")
    @classmethod
    def _max_200(cls, v: str) -> str:
        if len(v) > 200:
            raise ValueError("Field must be 200 characters or fewer")
        return v.strip()

    @field_validator("password")
    @classmethod
    def _password_strength(cls, v: str) -> str:
        if len(v) < 8:
            raise ValueError("Password must be at least 8 characters")
        return v

    @field_validator("email")
    @classmethod
    def _email_format(cls, v: str) -> str:
        v = v.strip().lower()
        if len(v) > 320 or "@" not in v:
            raise ValueError("Invalid email address")
        return v


class LoginRequest(BaseModel):
    email: str
    password: str

    @field_validator("email")
    @classmethod
    def _email_clean(cls, v: str) -> str:
        return v.strip().lower()

class AuthResponse(BaseModel):
    success: bool
    token: str | None = None
    user: dict | None = None
    message: str = ""

class BusinessSetupRequest(BaseModel):
    name: str
    industry: str | None = None
    website: str | None = None
    phone: str | None = None
    address: str | None = None
    timezone: str = "UTC"
    brand_voice: str | None = None

    @field_validator("name")
    @classmethod
    def _name_len(cls, v: str) -> str:
        if len(v.strip()) > 200:
            raise ValueError("Name must be 200 characters or fewer")
        return v.strip()

    @field_validator("website")
    @classmethod
    def _website_len(cls, v: str | None) -> str | None:
        if v and len(v) > 500:
            raise ValueError("Website URL too long")
        return v

class BusinessProfileResponse(BaseModel):
    success: bool
    business: dict | None = None
    message: str = ""

class PlatformConnectRequest(BaseModel):
    business_id: int
    credentials: dict  # Platform-specific credential fields

class PlatformActionResponse(BaseModel):
    success: bool
    platform: str = ""
    message: str = ""
    details: dict | None = None

class TelegramConfigRequest(BaseModel):
    business_id: int
    bot_token: str
    admin_chat_ids: list[str] = []

class TelegramTestRequest(BaseModel):
    bot_token: str


# ── JWT helpers ───────────────────────────────────────────────────────────────

def _create_jwt(user_id: int, business_id: int, role: str) -> str:
    payload = {
        "sub": str(user_id),
        "bid": business_id,
        "role": role,
        "jti": secrets.token_hex(16),
        "exp": datetime.utcnow() + timedelta(hours=JWT_EXPIRY_HOURS),
        "iat": datetime.utcnow(),
    }
    return jwt.encode(payload, JWT_SECRET, algorithm=JWT_ALGORITHM)


def _decode_jwt(token: str) -> dict:
    try:
        payload = jwt.decode(token, JWT_SECRET, algorithms=[JWT_ALGORITHM])
    except jwt.ExpiredSignatureError:
        raise HTTPException(status_code=401, detail="Token expired")
    except jwt.InvalidTokenError:
        raise HTTPException(status_code=401, detail="Invalid token")

    # Check if token has been revoked (logout)
    jti = payload.get("jti")
    if jti and jti in _revoked_tokens:
        raise HTTPException(status_code=401, detail="Token has been revoked")

    return payload


async def get_current_user(
    credentials: HTTPAuthorizationCredentials = Depends(_bearer_scheme),
) -> dict:
    """Extract and validate JWT from Authorization header."""
    if credentials is None:
        raise HTTPException(status_code=401, detail="Not authenticated")
    payload = _decode_jwt(credentials.credentials)
    return {
        "user_id": int(payload["sub"]),
        "business_id": payload["bid"],
        "role": payload["role"],
    }


async def get_current_user_or_api_key(
    credentials: HTTPAuthorizationCredentials = Depends(_bearer_scheme),
    api_key: str | None = Depends(require_api_key_optional),
) -> dict:
    """Accept EITHER JWT bearer token OR API key for backwards compatibility.

    JWT provides full tenant context (user_id, business_id, role).
    API key provides minimal context (business_id=1, role=admin).
    """
    # Try JWT first
    if credentials is not None:
        try:
            payload = _decode_jwt(credentials.credentials)
            return {
                "user_id": payload["sub"],
                "business_id": payload["bid"],
                "role": payload["role"],
            }
        except HTTPException:
            pass  # Fall through to API key check

    # Fall back to API key
    if api_key and api_key != "no-key":
        return {
            "user_id": 0,
            "business_id": 1,
            "role": "admin",
        }

    raise HTTPException(status_code=401, detail="Not authenticated — provide Bearer token or X-API-Key")


def _hash_password(password: str) -> str:
    """Hash password using bcrypt (via passlib) with fallback to SHA-256."""
    if _pwd_ctx:
        return _pwd_ctx.hash(password)
    return hashlib.sha256(password.encode()).hexdigest()


def _verify_password(plain: str, hashed: str) -> bool:
    """Verify password against hash — supports bcrypt and legacy SHA-256."""
    if _pwd_ctx:
        try:
            return _pwd_ctx.verify(plain, hashed)
        except Exception:
            # Fallback: might be a legacy SHA-256 hash
            return hashlib.sha256(plain.encode()).hexdigest() == hashed
    return hashlib.sha256(plain.encode()).hexdigest() == hashed


def _slugify(text: str) -> str:
    """Convert text to URL-safe slug."""
    text = text.lower().strip()
    text = re.sub(r"[^\w\s-]", "", text)
    text = re.sub(r"[\s_]+", "-", text)
    return re.sub(r"-+", "-", text).strip("-")


# ── Routes ────────────────────────────────────────────────────────────────────

@app.get("/api/health")
async def health_check():
    """Service health check — no auth required, but no credentials exposed."""
    return {
        "status": "ok",
        "service": "AI Marketing Platform API",
        "version": "2.0.0",
        "security": "enabled",
    }


@app.post("/api/upload", response_model=UploadResponse)
async def upload_media(
    file: UploadFile = File(...),
    business_id: int = Form(default=0),
    user: dict = Depends(get_current_user_or_api_key),
):
    # Prefer business_id from JWT, fall back to form field
    if business_id == 0:
        business_id = user["business_id"]
    """
    Upload a media file and start the processing workflow.
    The workflow runs: analyze → route → edit → caption → quality_gate → preview.
    """
    from security.file_guard import validate_file, strip_exif_metadata
    from security.audit_log import audit, AuditEvent, Severity

    inbox = MEDIA_ROOT / "inbox"
    inbox.mkdir(parents=True, exist_ok=True)

    # Save uploaded file to temp location
    filename = f"{datetime.now().strftime('%Y%m%d_%H%M%S')}_{file.filename}"
    file_path = inbox / filename
    with open(file_path, "wb") as f:
        shutil.copyfileobj(file.file, f)

    # ── Security: validate the uploaded file ──────────────────────────
    validation = validate_file(
        file_path=str(file_path),
        original_filename=file.filename or "upload",
        allow_documents=False,
    )
    if not validation.is_safe:
        # Delete the rejected file
        file_path.unlink(missing_ok=True)
        await audit(
            event=AuditEvent.FILE_REJECTED,
            severity=Severity.HIGH,
            actor="api",
            details={"filename": file.filename, "issues": validation.issues},
        )
        raise HTTPException(
            status_code=400,
            detail=f"File rejected: {'; '.join(validation.issues)}",
        )

    # Rename to sanitised filename
    if validation.sanitized_name != filename:
        new_path = inbox / f"{datetime.now().strftime('%Y%m%d_%H%M%S')}_{validation.sanitized_name}"
        file_path.rename(new_path)
        file_path = new_path

    # Strip EXIF metadata from photos
    if validation.file_type == "photo":
        strip_exif_metadata(str(file_path))
        await audit(
            event=AuditEvent.EXIF_STRIPPED,
            severity=Severity.INFO,
            actor="api",
            details={"filename": file_path.name},
        )

    await audit(
        event=AuditEvent.FILE_UPLOAD,
        severity=Severity.INFO,
        actor="api",
        details={
            "filename": file_path.name,
            "file_type": validation.file_type,
            "file_size": validation.file_size,
        },
    )

    # Detect media type
    ext = Path(file.filename).suffix.lower()
    media_type = "video" if ext in (".mp4", ".mov", ".avi", ".mkv", ".webm") else "photo"

    # Get dimensions
    width, height, duration = 0, 0, 0.0
    try:
        if media_type == "photo":
            from PIL import Image
            with Image.open(str(file_path)) as img:
                width, height = img.size
        else:
            from tools.media_utils import get_video_info
            info = get_video_info(str(file_path))
            width = info.get("width", 0)
            height = info.get("height", 0)
            duration = info.get("duration", 0.0)
    except Exception:
        pass

    # Store in database
    from memory.database import get_session_factory
    from sqlalchemy import text

    session_factory = get_session_factory()
    async with session_factory() as session:
        result = await session.execute(
            text(
                "INSERT INTO media_items (business_id, file_path, media_type, width, height, "
                "duration_seconds, status, created_at) "
                "VALUES (:bid, :path, :type, :w, :h, :dur, 'pending', :now)"
            ),
            {
                "bid": business_id,
                "path": str(file_path),
                "type": media_type,
                "w": width,
                "h": height,
                "dur": duration,
                "now": datetime.utcnow(),
            },
        )
        await session.commit()
        media_item_id = result.lastrowid

    # Start the workflow
    try:
        from orchestrator.workflow import start_media_workflow
        thread_id = await start_media_workflow(
            media_item_id=media_item_id,
            file_path=str(file_path),
            media_type=media_type,
            width=width,
            height=height,
            duration_seconds=duration,
            business_id=business_id,
        )
        return UploadResponse(
            success=True,
            media_item_id=media_item_id,
            thread_id=thread_id,
            message="Workflow started — content will be ready for review shortly",
        )
    except Exception as e:
        return UploadResponse(
            success=False,
            media_item_id=media_item_id,
            message=f"Upload saved but workflow failed: {str(e)}",
        )


@app.get("/api/posts")
async def list_posts(
    status: Optional[str] = Query(None),
    platform: Optional[str] = Query(None),
    limit: int = Query(default=50, le=200),
    user: dict = Depends(get_current_user_or_api_key),
):
    """List posts with optional filters."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    business_id = user["business_id"]
    query = "SELECT id, platform, caption, media_type, edited_file_path, status, posted_at FROM posts"
    conditions = ["business_id = :bid"]
    params = {"bid": business_id}

    if status:
        conditions.append("status = :status")
        params["status"] = status
    if platform:
        conditions.append("platform = :platform")
        params["platform"] = platform

    if conditions:
        query += " WHERE " + " AND ".join(conditions)
    query += " ORDER BY id DESC LIMIT :limit"
    params["limit"] = limit

    session_factory = get_session_factory()
    async with session_factory() as session:
        result = await session.execute(text(query), params)
        rows = result.fetchall()

    return [
        PostItem(
            id=row[0], platform=row[1], caption=row[2],
            media_type=row[3], media_path=row[4], status=row[5],
            posted_at=str(row[6]) if row[6] else None,
        )
        for row in rows
    ]


@app.post("/api/posts/{post_id}/approve", response_model=ApprovalResponse)
async def approve_post(post_id: int, thread_id: str = Form(...), user: dict = Depends(get_current_user_or_api_key)):
    """Approve a pending post for publishing."""
    try:
        from orchestrator.workflow import resume_workflow_with_approval
        from memory.database import get_session_factory
        from sqlalchemy import text

        # Get platform from post
        session_factory = get_session_factory()
        async with session_factory() as session:
            result = await session.execute(
                text("SELECT platform FROM posts WHERE id = :id"),
                {"id": post_id},
            )
            row = result.fetchone()
            if not row:
                raise HTTPException(status_code=404, detail="Post not found")
            platform = row[0]

        results = await resume_workflow_with_approval(
            thread_id=thread_id,
            approved_platforms=[platform],
            denied_platforms=[],
        )
        return ApprovalResponse(success=True, message="Published!", publish_results=results)
    except Exception as e:
        return ApprovalResponse(success=False, message=str(e))


@app.post("/api/posts/{post_id}/deny", response_model=ApprovalResponse)
async def deny_post(post_id: int, thread_id: str = Form(...), user: dict = Depends(get_current_user_or_api_key)):
    """Deny a pending post."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    session_factory = get_session_factory()
    async with session_factory() as session:
        await session.execute(
            text("UPDATE posts SET status = 'denied' WHERE id = :id"),
            {"id": post_id},
        )
        await session.commit()
    return ApprovalResponse(success=True, message="Post denied and archived.")


# ── Scheduled Publishing ─────────────────────────────────────────────────────


class ScheduleRequest(BaseModel):
    scheduled_for: str  # ISO datetime string


@app.post("/api/posts/{post_id}/schedule")
async def schedule_post(
    post_id: int,
    req: ScheduleRequest,
    user: dict = Depends(get_current_user_or_api_key),
):
    """Schedule a post for future publishing."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    try:
        scheduled_dt = datetime.fromisoformat(req.scheduled_for.replace("Z", "+00:00"))
    except ValueError:
        raise HTTPException(status_code=400, detail="Invalid datetime format. Use ISO 8601.")

    if scheduled_dt < datetime.utcnow():
        raise HTTPException(status_code=400, detail="Scheduled time must be in the future.")

    session_factory = get_session_factory()
    async with session_factory() as session:
        result = await session.execute(
            text("SELECT id, status FROM posts WHERE id = :id"),
            {"id": post_id},
        )
        row = result.fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="Post not found")

        await session.execute(
            text(
                "UPDATE posts SET scheduled_for = :sf, status = 'approved' WHERE id = :id"
            ),
            {"sf": scheduled_dt, "id": post_id},
        )
        await session.commit()

    from services.event_bus import emit

    await emit("post_scheduled", {
        "post_id": post_id,
        "scheduled_for": req.scheduled_for,
    })

    return {
        "success": True,
        "message": f"Post scheduled for {scheduled_dt.strftime('%Y-%m-%d %H:%M UTC')}",
        "scheduled_for": req.scheduled_for,
    }


@app.get("/api/posts/scheduled")
async def list_scheduled_posts(user: dict = Depends(get_current_user_or_api_key)):
    """List all posts scheduled for future publishing."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    business_id = user["business_id"]
    session_factory = get_session_factory()

    async with session_factory() as session:
        result = await session.execute(
            text(
                "SELECT p.id, p.platform, p.caption, p.scheduled_for, p.status, "
                "m.media_type, m.file_path "
                "FROM posts p "
                "JOIN media_items m ON p.media_item_id = m.id "
                "WHERE p.business_id = :bid "
                "AND p.scheduled_for IS NOT NULL "
                "AND p.status IN ('approved', 'publishing') "
                "ORDER BY p.scheduled_for ASC"
            ),
            {"bid": business_id},
        )
        rows = result.fetchall()

    return {
        "success": True,
        "posts": [
            {
                "id": r[0],
                "platform": r[1],
                "caption": r[2],
                "scheduled_for": str(r[3]) if r[3] else None,
                "status": r[4],
                "media_type": r[5],
            }
            for r in rows
        ],
    }


# ── Retry Failed Posts ───────────────────────────────────────────────────────


@app.post("/api/posts/{post_id}/retry")
async def retry_post(post_id: int, user: dict = Depends(get_current_user_or_api_key)):
    """Manually retry a failed post."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    session_factory = get_session_factory()
    async with session_factory() as session:
        result = await session.execute(
            text("SELECT status, retry_count FROM posts WHERE id = :id"),
            {"id": post_id},
        )
        row = result.fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="Post not found")
        if row[0] != "failed":
            raise HTTPException(status_code=400, detail=f"Post status is '{row[0]}', not 'failed'")

        # Reset for immediate retry by scheduler
        await session.execute(
            text(
                "UPDATE posts SET next_retry_at = :now, status = 'failed' WHERE id = :id"
            ),
            {"now": datetime.utcnow(), "id": post_id},
        )
        await session.commit()

    return {"success": True, "message": "Post queued for immediate retry"}


# ── Collage Creation ─────────────────────────────────────────────────────────


class CollageRequest(BaseModel):
    media_item_ids: list[int]
    layout: str = "grid"  # "grid", "before_after", "vertical_strip"
    target_width: int = 1080
    target_height: int = 1080
    auto_publish: bool = False
    platforms: list[str] = []


@app.post("/api/collage/create")
async def create_collage_endpoint(
    req: CollageRequest,
    user: dict = Depends(get_current_user_or_api_key),
):
    """Create a collage from selected media items."""
    from memory.database import get_session_factory
    from sqlalchemy import text
    from agents.media_editor import create_collage

    if len(req.media_item_ids) < 2:
        raise HTTPException(status_code=400, detail="At least 2 images required for a collage")

    business_id = user["business_id"]
    session_factory = get_session_factory()

    # Get file paths for the media items
    async with session_factory() as session:
        placeholders = ", ".join([f":id{i}" for i in range(len(req.media_item_ids))])
        params = {f"id{i}": mid for i, mid in enumerate(req.media_item_ids)}
        params["bid"] = business_id

        result = await session.execute(
            text(
                f"SELECT id, file_path, media_type FROM media_items "
                f"WHERE id IN ({placeholders}) AND business_id = :bid "
                f"AND media_type = 'photo'"
            ),
            params,
        )
        rows = result.fetchall()

    if len(rows) < 2:
        raise HTTPException(status_code=400, detail="Need at least 2 valid photo items")

    image_paths = [row[1] for row in rows]
    item_ids = [row[0] for row in rows]

    # Create the collage
    try:
        collage_path = create_collage(
            image_paths=image_paths,
            layout=req.layout,
            target_width=req.target_width,
            target_height=req.target_height,
        )
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Collage creation failed: {str(e)}")

    # Mark source items as used in collage
    async with session_factory() as session:
        for mid in item_ids:
            await session.execute(
                text("UPDATE media_items SET is_used_in_collage = 1 WHERE id = :id"),
                {"id": mid},
            )

        # Create a new media item for the collage
        result = await session.execute(
            text(
                "INSERT INTO media_items (business_id, file_path, file_name, media_type, "
                "content_category, created_at) "
                "VALUES (:bid, :path, :name, 'photo', 'general', :now)"
            ),
            {
                "bid": business_id,
                "path": collage_path,
                "name": Path(collage_path).name,
                "now": datetime.utcnow(),
            },
        )
        await session.commit()
        collage_media_id = result.lastrowid

    from services.event_bus import emit

    await emit("collage_created", {
        "media_item_id": collage_media_id,
        "source_items": item_ids,
        "layout": req.layout,
    })

    response = {
        "success": True,
        "collage_path": collage_path,
        "media_item_id": collage_media_id,
        "source_items": item_ids,
    }

    # Optionally start the workflow for the collage
    if req.auto_publish and req.platforms:
        try:
            from orchestrator.workflow import start_media_workflow

            thread_id = await start_media_workflow(
                media_item_id=collage_media_id,
                file_path=collage_path,
                media_type="photo",
                business_id=business_id,
            )
            response["thread_id"] = thread_id
            response["message"] = "Collage created and workflow started"
        except Exception as e:
            response["message"] = f"Collage created but workflow failed: {str(e)}"
    else:
        response["message"] = "Collage created successfully"

    return response


# ── Real-Time Notifications (SSE) ────────────────────────────────────────────


@app.get("/api/events")
async def sse_stream(request: Request, user: dict = Depends(get_current_user_or_api_key)):
    """Server-Sent Events stream for real-time dashboard notifications."""
    from starlette.responses import StreamingResponse
    from services.event_bus import subscribe, unsubscribe

    queue = await subscribe()

    async def event_generator():
        try:
            while True:
                # Check if client disconnected
                if await request.is_disconnected():
                    break

                try:
                    event = await asyncio.wait_for(queue.get(), timeout=30.0)
                    data = json.dumps(event)
                    yield f"event: {event['type']}\ndata: {data}\n\n"
                except asyncio.TimeoutError:
                    # Send keepalive
                    yield f": keepalive\n\n"
        finally:
            await unsubscribe(queue)

    return StreamingResponse(
        event_generator(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "Connection": "keep-alive",
            "X-Accel-Buffering": "no",
        },
    )


@app.get("/api/notifications/recent")
async def recent_notifications(user: dict = Depends(get_current_user_or_api_key)):
    """Get recent events/notifications for the dashboard."""
    from services.event_bus import get_recent_events

    events = get_recent_events(20)
    return {"success": True, "events": events}


# ── Content Calendar ─────────────────────────────────────────────────────────


@app.get("/api/calendar")
async def get_calendar(
    start: Optional[str] = Query(None, description="Start date (YYYY-MM-DD)"),
    end: Optional[str] = Query(None, description="End date (YYYY-MM-DD)"),
    user: dict = Depends(get_current_user_or_api_key),
):
    """Get calendar entries for the content calendar view."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    business_id = user["business_id"]

    # Default to current month
    now = datetime.utcnow()
    try:
        start_date = datetime.fromisoformat(start) if start else now.replace(day=1, hour=0, minute=0, second=0)
    except ValueError:
        start_date = now.replace(day=1, hour=0, minute=0, second=0)

    try:
        end_date = datetime.fromisoformat(end) if end else (start_date + timedelta(days=42))
    except ValueError:
        end_date = start_date + timedelta(days=42)

    session_factory = get_session_factory()

    async with session_factory() as session:
        # Published posts
        published = await session.execute(
            text(
                "SELECT p.id, p.platform, p.caption, p.published_at, p.status, "
                "p.likes, p.views, m.media_type, "
                "cc.content_category "
                "FROM posts p "
                "JOIN media_items m ON p.media_item_id = m.id "
                "LEFT JOIN content_calendar cc ON cc.post_id = p.id "
                "WHERE p.business_id = :bid "
                "AND p.published_at >= :start AND p.published_at <= :end "
                "AND p.status = 'published' "
                "ORDER BY p.published_at"
            ),
            {"bid": business_id, "start": start_date, "end": end_date},
        )
        published_rows = published.fetchall()

        # Scheduled posts (future)
        scheduled = await session.execute(
            text(
                "SELECT p.id, p.platform, p.caption, p.scheduled_for, p.status, "
                "0, 0, m.media_type, NULL "
                "FROM posts p "
                "JOIN media_items m ON p.media_item_id = m.id "
                "WHERE p.business_id = :bid "
                "AND p.scheduled_for >= :start AND p.scheduled_for <= :end "
                "AND p.status = 'approved' "
                "ORDER BY p.scheduled_for"
            ),
            {"bid": business_id, "start": start_date, "end": end_date},
        )
        scheduled_rows = scheduled.fetchall()

    events = []

    for row in published_rows:
        events.append({
            "id": row[0],
            "title": f"{row[1].title()}: {(row[2] or '')[:40]}",
            "start": row[3].isoformat() if row[3] else None,
            "platform": row[1],
            "status": "published",
            "type": "published",
            "media_type": row[7],
            "category": row[8],
            "likes": row[5],
            "views": row[6],
            "color": _platform_color(row[1]),
        })

    for row in scheduled_rows:
        events.append({
            "id": row[0],
            "title": f"[Scheduled] {row[1].title()}: {(row[2] or '')[:40]}",
            "start": row[3].isoformat() if row[3] else None,
            "platform": row[1],
            "status": "scheduled",
            "type": "scheduled",
            "media_type": row[7],
            "color": _platform_color(row[1]),
            "borderColor": "#ffc107",
        })

    return {"success": True, "events": events}


def _platform_color(platform: str) -> str:
    """Get brand color for a platform (for calendar display)."""
    colors = {
        "instagram": "#E1306C",
        "facebook": "#1877F2",
        "youtube": "#FF0000",
        "linkedin": "#0A66C2",
        "tiktok": "#00f2ea",
        "twitter": "#1DA1F2",
        "snapchat": "#FFFC00",
        "pinterest": "#BD081C",
        "threads": "#000000",
    }
    return colors.get(platform, "#6c63ff")


# ── Auto-Insights ────────────────────────────────────────────────────────────


@app.get("/api/insights")
async def get_insights(
    days: int = Query(default=7, le=90),
    user: dict = Depends(get_current_user_or_api_key),
):
    """Get auto-generated insights for the dashboard."""
    from services.insights_generator import generate_insights

    business_id = user["business_id"]
    try:
        insights = await generate_insights(business_id, days)
        return {"success": True, **insights}
    except Exception as e:
        return {"success": False, "error": str(e)}


@app.get("/api/analytics")
async def get_analytics(days: int = Query(default=7, le=90), user: dict = Depends(get_current_user_or_api_key)):
    """Get engagement analytics for the dashboard."""
    from agents.auto_engagement import get_engagement_summary
    try:
        summary = await get_engagement_summary(days=days, business_id=user["business_id"])
        return {"success": True, "days": days, "data": summary}
    except Exception as e:
        return {"success": False, "error": str(e), "data": {}}


@app.get("/api/platforms")
async def get_platforms(
    user: dict = Depends(get_current_user_or_api_key),
):
    """Get connection status for all platforms — uses per-tenant credentials."""
    from memory.credentials import get_all_connections

    business_id = user["business_id"]
    connections = await get_all_connections(business_id)

    # get_all_connections already returns enriched platform dicts
    # with 'connected', 'platform', 'label', 'icon', etc.
    platforms = []
    for c in connections:
        platforms.append({
            "name": c.get("label", c["platform"].title()),
            "key": c["platform"],
            "icon": c.get("icon", ""),
            "connected": c.get("connected", False),
            "status": "active" if c.get("connected") else "disconnected",
            "connected_at": c.get("connection", {}).get("connected_at") if c.get("connection") else None,
        })

    return {"platforms": platforms}


@app.get("/api/strategy")
async def get_strategy(user: dict = Depends(get_current_user_or_api_key)):
    """Get the weekly strategy brief."""
    from agents.content_strategist import generate_strategy_brief
    try:
        brief = await generate_strategy_brief()
        return {"success": True, "brief": brief}
    except Exception as e:
        return {"success": False, "error": str(e)}


@app.get("/api/growth-ideas")
async def get_growth_ideas(
    service: Optional[str] = Query(None),
    platform: Optional[str] = Query(None),
    effort: Optional[str] = Query(None),
    count: int = Query(default=5, le=20),
    user: dict = Depends(get_current_user_or_api_key),
):
    """Get filtered marketing ideas."""
    from agents.growth_hacker import suggest_marketing_ideas
    ideas = suggest_marketing_ideas(
        service=service, platform=platform, effort=effort, count=count,
    )
    return {"success": True, "ideas": ideas}


@app.get("/api/growth-report")
async def get_growth_report(user: dict = Depends(get_current_user_or_api_key)):
    """Get the daily growth strategy report."""
    from agents.growth_hacker import generate_growth_report
    return {"success": True, "report": generate_growth_report()}


@app.get("/api/schedule")
async def get_posting_schedule(user: dict = Depends(get_current_user_or_api_key)):
    """Get optimal posting times for all platforms."""
    from agents.growth_hacker import get_posting_schedule
    platforms = ["instagram", "facebook", "tiktok", "youtube", "linkedin"]
    schedule = get_posting_schedule(platforms)
    return {"success": True, "schedule": schedule}


@app.get("/api/pillar-balance")
async def get_pillar_balance(user: dict = Depends(get_current_user_or_api_key)):
    """Analyze content pillar distribution."""
    from agents.content_recycler import analyze_pillar_balance
    from agents.auto_engagement import get_engagement_summary

    # For now, return the framework (will use real post data when available)
    result = analyze_pillar_balance([])
    return {"success": True, "analysis": result}


@app.get("/api/content-gaps")
async def get_content_gaps(user: dict = Depends(get_current_user_or_api_key)):
    """Detect content gaps in recent posting."""
    from agents.content_strategist import detect_content_gaps
    result = detect_content_gaps([])
    return {"success": True, "analysis": result}


# ── Multi-Tenant Auth Endpoints ──────────────────────────────────────────────


@app.post("/api/auth/register", response_model=AuthResponse)
async def register(req: RegisterRequest):
    """Register a new user and business."""
    from memory.database import get_session_factory
    from memory.models import User, Business, UserRole
    from sqlalchemy import select

    session_factory = get_session_factory()
    async with session_factory() as session:
        # Check existing email
        existing = (await session.execute(
            select(User).where(User.email == req.email)
        )).scalar_one_or_none()
        if existing:
            return AuthResponse(success=False, message="Email already registered")

        # Create business
        business = Business(
            name=req.business_name,
            slug=_slugify(req.business_name),
            industry=req.industry or "",
        )
        session.add(business)
        await session.flush()

        # Create user
        user = User(
            business_id=business.id,
            email=req.email,
            password_hash=_hash_password(req.password),
            name=req.name,
            role=UserRole.OWNER,
        )
        session.add(user)
        await session.commit()
        await session.refresh(user)
        await session.refresh(business)

        # Create user → business link in junction table
        try:
            from memory.models import UserBusinessLink
            link = UserBusinessLink(user_id=user.id, business_id=business.id, role="owner")
            session.add(link)
            await session.commit()
        except Exception:
            pass  # Table may not exist yet

        token = _create_jwt(user.id, business.id, user.role.value)
        return AuthResponse(
            success=True,
            token=token,
            user={
                "id": user.id,
                "email": user.email,
                "name": user.name,
                "role": user.role.value,
                "business_id": business.id,
                "business_name": business.name,
            },
        )


@app.post("/api/auth/login", response_model=AuthResponse)
async def login(req: LoginRequest):
    """Login with email and password, receive JWT."""
    from memory.database import get_session_factory
    from memory.models import User, Business
    from sqlalchemy import select
    from sqlalchemy.orm import selectinload

    session_factory = get_session_factory()
    async with session_factory() as session:
        user = (await session.execute(
            select(User).where(User.email == req.email)
        )).scalar_one_or_none()

        if not user or not _verify_password(req.password, user.password_hash):
            return AuthResponse(success=False, message="Invalid credentials")

        if not user.is_active:
            return AuthResponse(success=False, message="Account disabled")

        # Get business name
        business = (await session.execute(
            select(Business).where(Business.id == user.business_id)
        )).scalar_one_or_none()

        token = _create_jwt(user.id, user.business_id, user.role.value)
        return AuthResponse(
            success=True,
            token=token,
            user={
                "id": user.id,
                "email": user.email,
                "name": user.name,
                "role": user.role.value,
                "business_id": user.business_id,
                "business_name": business.name if business else "",
            },
        )


@app.get("/api/auth/me")
async def get_me(user: dict = Depends(get_current_user)):
    """Return current user profile from JWT."""
    from memory.database import get_session_factory
    from memory.models import User, Business
    from sqlalchemy import select

    session_factory = get_session_factory()
    async with session_factory() as session:
        db_user = (await session.execute(
            select(User).where(User.id == user["user_id"])
        )).scalar_one_or_none()
        if not db_user:
            raise HTTPException(status_code=404, detail="User not found")

        business = (await session.execute(
            select(Business).where(Business.id == db_user.business_id)
        )).scalar_one_or_none()

        # Fetch connected platforms summary
        from sqlalchemy import text
        platform_rows = await session.execute(
            text(
                "SELECT platform, status FROM platform_connections "
                "WHERE business_id = :bid AND status = 'active'"
            ),
            {"bid": db_user.business_id},
        )
        connected_platforms = [
            {"platform": r.platform, "status": r.status}
            for r in platform_rows.fetchall()
        ]

        # Fetch configured AI providers summary
        ai_rows = await session.execute(
            text(
                "SELECT provider, model_name, status, is_active "
                "FROM ai_provider_configs WHERE business_id = :bid ORDER BY provider"
            ),
            {"bid": db_user.business_id},
        )
        ai_providers = [
            {
                "provider": r.provider,
                "model_name": r.model_name,
                "status": r.status,
                "is_active": bool(r.is_active),
            }
            for r in ai_rows.fetchall()
        ]

        return {
            "id": db_user.id,
            "email": db_user.email,
            "name": db_user.name,
            "role": db_user.role.value,
            "business_id": db_user.business_id,
            "business_name": business.name if business else "",
            "business_slug": business.slug if business else "",
            "connected_platforms": connected_platforms,
            "ai_providers": ai_providers,
        }


@app.post("/api/auth/logout")
async def logout(
    credentials: HTTPAuthorizationCredentials = Depends(_bearer_scheme),
):
    """Logout by revoking the current JWT token."""
    if credentials is None:
        raise HTTPException(status_code=401, detail="Not authenticated")

    payload = _decode_jwt(credentials.credentials)
    jti = payload.get("jti")
    if jti:
        exp = payload.get("exp", 0)
        _revoked_tokens[jti] = exp

        # Prune expired entries to prevent unbounded growth
        now = datetime.utcnow().timestamp()
        expired_jtis = [k for k, v in _revoked_tokens.items() if v < now]
        for k in expired_jtis:
            _revoked_tokens.pop(k, None)

    return {"success": True, "message": "Logged out successfully"}


# ── Business Setup Endpoints ─────────────────────────────────────────────────


@app.post("/api/business/setup", response_model=BusinessProfileResponse)
async def setup_business(
    req: BusinessSetupRequest,
    user: dict = Depends(get_current_user),
):
    """Create or update business profile (onboarding)."""
    from memory.database import get_session_factory
    from memory.models import Business
    from sqlalchemy import select

    session_factory = get_session_factory()
    async with session_factory() as session:
        business = (await session.execute(
            select(Business).where(Business.id == user["business_id"])
        )).scalar_one_or_none()

        if not business:
            business = Business(id=user["business_id"])
            session.add(business)

        business.name = req.name
        business.slug = _slugify(req.name)
        business.industry = req.industry or business.industry
        business.website = req.website or business.website
        business.phone = req.phone or business.phone
        business.address = req.address or business.address
        business.timezone = req.timezone or business.timezone
        business.brand_voice = req.brand_voice or business.brand_voice

        await session.commit()
        await session.refresh(business)

        return BusinessProfileResponse(
            success=True,
            business={
                "id": business.id,
                "name": business.name,
                "slug": business.slug,
                "industry": business.industry,
                "website": business.website,
                "phone": business.phone,
                "address": business.address,
                "timezone": business.timezone,
                "brand_voice": business.brand_voice,
            },
        )


@app.get("/api/business/{business_id}/profile", response_model=BusinessProfileResponse)
async def get_business_profile(
    business_id: int,
    user: dict = Depends(get_current_user),
):
    """Get business profile."""
    if user["business_id"] != business_id:
        raise HTTPException(status_code=403, detail="Access denied")

    from memory.database import get_session_factory
    from memory.models import Business
    from sqlalchemy import select

    session_factory = get_session_factory()
    async with session_factory() as session:
        business = (await session.execute(
            select(Business).where(Business.id == business_id)
        )).scalar_one_or_none()
        if not business:
            raise HTTPException(status_code=404, detail="Business not found")

        return BusinessProfileResponse(
            success=True,
            business={
                "id": business.id,
                "name": business.name,
                "slug": business.slug,
                "industry": business.industry,
                "website": getattr(business, "website", ""),
                "phone": getattr(business, "phone", ""),
                "address": getattr(business, "address", ""),
                "timezone": business.timezone,
                "brand_voice": business.brand_voice,
                "logo_path": business.logo_path,
                "subscription_plan": business.subscription_plan,
            },
        )


@app.put("/api/business/{business_id}/profile", response_model=BusinessProfileResponse)
async def update_business_profile(
    business_id: int,
    req: BusinessSetupRequest,
    user: dict = Depends(get_current_user),
):
    """Update business profile."""
    if user["business_id"] != business_id:
        raise HTTPException(status_code=403, detail="Access denied")

    # Reuse setup logic
    user["business_id"] = business_id
    return await setup_business(req, user)


# ── Platform Connection Endpoints ────────────────────────────────────────────


@app.post("/api/platforms/{platform}/connect", response_model=PlatformActionResponse)
async def connect_platform(
    platform: str,
    req: PlatformConnectRequest,
    user: dict = Depends(get_current_user),
):
    """Save encrypted credentials for a platform."""
    from memory.credentials import save_platform_credentials

    if user["business_id"] != req.business_id:
        raise HTTPException(status_code=403, detail="Access denied")

    try:
        await save_platform_credentials(
            business_id=req.business_id,
            platform=platform,
            credentials=req.credentials,
        )

        # Auto-clone: create a specialist agent for this platform
        from agents.platform_agents import get_or_create_agent
        try:
            agent = await get_or_create_agent(req.business_id, platform)
            logger.info(f"Auto-created {platform} agent for business {req.business_id}")
        except Exception as e:
            logger.warning(f"Agent auto-clone failed for {platform}: {e}")

        return PlatformActionResponse(
            success=True,
            platform=platform,
            message=f"{platform.title()} connected successfully",
        )
    except Exception as e:
        return PlatformActionResponse(
            success=False,
            platform=platform,
            message=str(e),
        )


@app.post("/api/platforms/{platform}/test", response_model=PlatformActionResponse)
async def test_platform(
    platform: str,
    user: dict = Depends(get_current_user),
):
    """Test if saved credentials work for a platform."""
    from memory.credentials import test_platform_connection

    try:
        result = await test_platform_connection(
            business_id=user["business_id"],
            platform=platform,
        )
        return PlatformActionResponse(
            success=result.get("success", False),
            platform=platform,
            message=result.get("message", ""),
            details=result,
        )
    except Exception as e:
        return PlatformActionResponse(
            success=False,
            platform=platform,
            message=str(e),
        )


@app.post("/api/platforms/{platform}/disconnect", response_model=PlatformActionResponse)
async def disconnect_platform_endpoint(
    platform: str,
    user: dict = Depends(get_current_user),
):
    """Disconnect a platform — deletes stored credentials."""
    from memory.credentials import disconnect_platform

    try:
        await disconnect_platform(
            business_id=user["business_id"],
            platform=platform,
        )
        return PlatformActionResponse(
            success=True,
            platform=platform,
            message=f"{platform.title()} disconnected",
        )
    except Exception as e:
        return PlatformActionResponse(
            success=False,
            platform=platform,
            message=str(e),
        )


@app.get("/api/platforms/status")
async def get_platforms_status(user: dict = Depends(get_current_user)):
    """Get per-tenant platform connection statuses — alias for /api/platforms."""
    return await get_platforms(user=user)


@app.get("/api/platforms/fields/{platform}")
async def get_platform_fields(platform: str):
    """Get the required credential fields and setup guide for a platform."""
    from memory.credentials import PLATFORM_FIELDS

    fields = PLATFORM_FIELDS.get(platform)
    if not fields:
        raise HTTPException(status_code=404, detail=f"Unknown platform: {platform}")

    return {
        "platform": platform,
        "fields": fields["fields"],
        "guide_steps": fields.get("guide_steps", []),
        "help_url": fields.get("help_url", ""),
        "token_expiry": fields.get("token_expiry"),
    }


# ── Telegram Bot Configuration ───────────────────────────────────────────────


@app.post("/api/telegram/configure", response_model=PlatformActionResponse)
async def configure_telegram(
    req: TelegramConfigRequest,
    user: dict = Depends(get_current_user),
):
    """Save Telegram bot token and admin chat IDs for a business."""
    if user["business_id"] != req.business_id:
        raise HTTPException(status_code=403, detail="Access denied")

    from memory.database import get_session_factory
    from memory.models import TelegramBotConfig
    from security.encryption import encrypt
    from sqlalchemy import select

    session_factory = get_session_factory()
    async with session_factory() as session:
        config = (await session.execute(
            select(TelegramBotConfig).where(
                TelegramBotConfig.business_id == req.business_id
            )
        )).scalar_one_or_none()

        encrypted_token = encrypt(req.bot_token)

        if config:
            config.bot_token = encrypted_token
            config.admin_chat_ids = json.dumps(req.admin_chat_ids)
            config.is_active = True
        else:
            config = TelegramBotConfig(
                business_id=req.business_id,
                bot_token=encrypted_token,
                admin_chat_ids=json.dumps(req.admin_chat_ids),
                is_active=True,
            )
            session.add(config)

        await session.commit()

    return PlatformActionResponse(
        success=True,
        platform="telegram",
        message="Telegram bot configured — it will start polling automatically.",
    )


@app.post("/api/telegram/test", response_model=PlatformActionResponse)
async def test_telegram(req: TelegramTestRequest):
    """Test a Telegram bot token by calling getMe."""
    import httpx

    try:
        async with httpx.AsyncClient() as client:
            resp = await client.get(
                f"https://api.telegram.org/bot{req.bot_token}/getMe",
                timeout=10,
            )
            data = resp.json()

        if data.get("ok"):
            bot_info = data["result"]
            return PlatformActionResponse(
                success=True,
                platform="telegram",
                message=f"Token valid — bot: @{bot_info.get('username', '?')}",
                details=bot_info,
            )
        else:
            return PlatformActionResponse(
                success=False,
                platform="telegram",
                message=data.get("description", "Invalid token"),
            )
    except Exception as e:
        return PlatformActionResponse(
            success=False,
            platform="telegram",
            message=f"Connection error: {str(e)}",
        )


# ── Tenant Bot Management ────────────────────────────────────────────────────


@app.post("/api/telegram/start-bot", response_model=PlatformActionResponse)
async def start_tenant_bot(
    user: dict = Depends(get_current_user),
):
    """Start (or restart) the Telegram bot for the current business."""
    from bot.telegram_bot import get_tenant_manager
    manager = get_tenant_manager()
    success = await manager.restart_bot(user["business_id"])
    return PlatformActionResponse(
        success=success,
        platform="telegram",
        message="Bot started successfully" if success else "Failed to start bot — check configuration",
    )


@app.get("/api/telegram/bots-status")
async def get_bots_status(user: dict = Depends(get_current_user)):
    """Get status of all running tenant bots (admin only)."""
    if user["role"] not in ("owner", "admin"):
        raise HTTPException(status_code=403, detail="Admin access required")
    from bot.telegram_bot import get_tenant_manager
    return {"bots": get_tenant_manager().get_status()}


# ── Jobs API ──────────────────────────────────────────────────────────────────


@app.get("/api/jobs")
async def list_jobs(
    status: Optional[str] = Query(None),
    user: dict = Depends(get_current_user_or_api_key),
):
    """List job postings for the current business."""
    from memory.database import get_session_factory
    from memory.models import Job
    from sqlalchemy import select

    business_id = user["business_id"]
    session_factory = get_session_factory()
    async with session_factory() as session:
        query = select(Job).where(Job.business_id == business_id).order_by(Job.created_at.desc())
        if status:
            query = query.where(Job.status == status)
        result = await session.execute(query)
        jobs = result.scalars().all()

    return {
        "success": True,
        "jobs": [
            {
                "id": j.id,
                "title": j.title,
                "department": j.department,
                "status": j.status.value if hasattr(j.status, 'value') else j.status,
                "experience_required": getattr(j, "experience_required", ""),
                "created_at": str(j.created_at) if j.created_at else None,
            }
            for j in jobs
        ],
    }


@app.get("/api/jobs/{job_id}/candidates")
async def list_candidates(
    job_id: int,
    user: dict = Depends(get_current_user_or_api_key),
):
    """List candidates for a specific job."""
    from memory.database import get_session_factory
    from memory.models import Candidate
    from sqlalchemy import select

    session_factory = get_session_factory()
    async with session_factory() as session:
        result = await session.execute(
            select(Candidate).where(Candidate.job_id == job_id).order_by(Candidate.created_at.desc())
        )
        candidates = result.scalars().all()

    return {
        "success": True,
        "candidates": [
            {
                "id": c.id,
                "name": c.name,
                "email": getattr(c, "email", ""),
                "match_score": getattr(c, "match_score", 0),
                "status": c.status.value if hasattr(c.status, 'value') else c.status,
                "resume_path": getattr(c, "resume_path", ""),
                "created_at": str(c.created_at) if c.created_at else None,
            }
            for c in candidates
        ],
    }


@app.post("/api/jobs/{job_id}/candidates/{candidate_id}/approve")
async def approve_candidate(
    job_id: int,
    candidate_id: int,
    user: dict = Depends(get_current_user_or_api_key),
):
    """Approve/shortlist a candidate."""
    from memory.database import get_session_factory
    from memory.models import Candidate, CandidateStatus
    from sqlalchemy import select

    session_factory = get_session_factory()
    async with session_factory() as session:
        result = await session.execute(
            select(Candidate).where(Candidate.id == candidate_id, Candidate.job_id == job_id)
        )
        candidate = result.scalar_one_or_none()
        if not candidate:
            raise HTTPException(status_code=404, detail="Candidate not found")
        candidate.status = CandidateStatus.SHORTLISTED
        await session.commit()

    return {"success": True, "message": f"Candidate {candidate_id} shortlisted"}


@app.post("/api/jobs/{job_id}/candidates/{candidate_id}/reject")
async def reject_candidate(
    job_id: int,
    candidate_id: int,
    user: dict = Depends(get_current_user_or_api_key),
):
    """Reject a candidate."""
    from memory.database import get_session_factory
    from memory.models import Candidate, CandidateStatus
    from sqlalchemy import select

    session_factory = get_session_factory()
    async with session_factory() as session:
        result = await session.execute(
            select(Candidate).where(Candidate.id == candidate_id, Candidate.job_id == job_id)
        )
        candidate = result.scalar_one_or_none()
        if not candidate:
            raise HTTPException(status_code=404, detail="Candidate not found")
        candidate.status = CandidateStatus.REJECTED
        await session.commit()

    return {"success": True, "message": f"Candidate {candidate_id} rejected"}


# ── AI Setup Assistant API ────────────────────────────────────────────────────


class AiAssistantRequest(BaseModel):
    message: str
    context: str = "general"  # "platform_setup", "general", "troubleshoot"

    @field_validator("message")
    @classmethod
    def _msg_len(cls, v: str) -> str:
        v = v.strip()
        if len(v) > 2000:
            raise ValueError("Message must be 2000 characters or fewer")
        if not v:
            raise ValueError("Message cannot be empty")
        return v


@app.post("/api/ai-assistant")
async def ai_assistant_chat(
    req: AiAssistantRequest,
    user: dict = Depends(get_current_user_or_api_key),
):
    """AI assistant endpoint for the dashboard chat widget and Telegram /setup."""
    try:
        from openai import AsyncOpenAI
        from memory.credentials import PLATFORM_FIELDS

        settings = get_settings()
        client = AsyncOpenAI(api_key=settings.openai_api_key)

        # Build platform setup context
        platform_guides = ""
        if req.context == "platform_setup":
            for name, fields in PLATFORM_FIELDS.items():
                guide_steps = fields.get("guide_steps", [])
                if guide_steps:
                    platform_guides += f"\n\n## {name.title()} Setup:\n"
                    for i, step in enumerate(guide_steps, 1):
                        platform_guides += f"{i}. {step}\n"

        # Load business name dynamically
        from memory.database import load_business_context
        biz_ctx = await load_business_context(user["business_id"])
        biz_name = biz_ctx.get("name", "your business")

        system_prompt = (
            f"You are the AI setup assistant for {biz_name}'s marketing platform. "
            "You help non-technical users connect social media platforms, configure settings, "
            "and troubleshoot issues. Be patient, friendly, and provide step-by-step guidance. "
            "Always use simple, non-technical language.\n\n"
            "Platform Connection Guides:" + platform_guides if platform_guides else ""
        )

        response = await client.chat.completions.create(
            model="gpt-4o-mini",
            messages=[
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": req.message},
            ],
            temperature=0.7,
            max_tokens=800,
        )

        return {
            "success": True,
            "response": response.choices[0].message.content,
        }

    except Exception as e:
        return {
            "success": False,
            "response": f"I'm having trouble responding right now. Please try again. ({str(e)[:100]})",
        }


# ── AI Agent Management Endpoints ─────────────────────────────────────────────


class AgentTrainRequest(BaseModel):
    repo_url: str
    platform: str  # Which agent to train (instagram, seo, hr, etc.)

    @field_validator("repo_url")
    @classmethod
    def _valid_url(cls, v: str) -> str:
        v = v.strip()
        if not v.startswith(("https://github.com/", "https://gitlab.com/")):
            raise ValueError("Only GitHub / GitLab URLs are allowed")
        if len(v) > 500:
            raise ValueError("URL too long")
        return v

    @field_validator("platform")
    @classmethod
    def _valid_platform(cls, v: str) -> str:
        allowed = {"instagram", "tiktok", "linkedin", "facebook", "twitter",
                   "snapchat", "pinterest", "youtube", "threads", "seo", "hr"}
        if v.lower() not in allowed:
            raise ValueError(f"Platform must be one of: {', '.join(sorted(allowed))}")
        return v.lower()


@app.get("/api/agents")
async def list_agents(user: dict = Depends(get_current_user)):
    """List all AI agents for the current business."""
    from agents.platform_agents import get_all_agents

    agents = await get_all_agents(user["business_id"])
    return {
        "success": True,
        "agents": [a.to_dict() for a in agents],
    }


@app.get("/api/agents/{platform}")
async def get_agent_detail(platform: str, user: dict = Depends(get_current_user)):
    """Get detailed info about a specific agent."""
    from agents.platform_agents import get_or_create_agent

    agent = await get_or_create_agent(user["business_id"], platform)
    if not agent:
        raise HTTPException(status_code=404, detail=f"No agent for platform: {platform}")
    return {"success": True, "agent": agent.to_dict()}


@app.post("/api/agents/train-from-repo")
async def train_agent_from_repo(
    req: AgentTrainRequest,
    user: dict = Depends(get_current_user),
):
    """Train an agent by analyzing a GitHub repository."""
    from agents.platform_agents import train_agent_from_github

    result = await train_agent_from_github(
        business_id=user["business_id"],
        platform=req.platform,
        repo_url=req.repo_url,
    )
    return result


@app.post("/api/agents/{platform}/learn")
async def agent_learn_from_post(
    platform: str,
    post_id: int = Form(...),
    likes: int = Form(0),
    comments: int = Form(0),
    shares: int = Form(0),
    views: int = Form(0),
    user: dict = Depends(get_current_user),
):
    """Feed engagement data to an agent so it can learn."""
    from agents.platform_agents import get_or_create_agent

    agent = await get_or_create_agent(user["business_id"], platform)
    if not agent:
        raise HTTPException(status_code=404, detail=f"No agent for platform: {platform}")

    await agent.learn_from_result(post_id, {
        "post_id": post_id,
        "likes": likes,
        "comments": comments,
        "shares": shares,
        "views": views,
    })
    return {"success": True, "message": f"{platform} agent updated with engagement data"}


# ── SEO Endpoints ─────────────────────────────────────────────────────────────


class SEOKeywordRequest(BaseModel):
    topic: str
    location: str = ""

    @field_validator("topic")
    @classmethod
    def _topic_len(cls, v: str) -> str:
        if len(v.strip()) > 500:
            raise ValueError("Topic must be 500 characters or fewer")
        return v.strip()


class SEOAuditRequest(BaseModel):
    content: str
    target_keyword: str = ""

    @field_validator("content")
    @classmethod
    def _content_len(cls, v: str) -> str:
        if len(v) > 10000:
            raise ValueError("Content must be 10,000 characters or fewer")
        return v


class GMBPostRequest(BaseModel):
    category: str = "general"
    description: str = ""


@app.post("/api/seo/keywords")
async def generate_seo_keywords(
    req: SEOKeywordRequest,
    user: dict = Depends(get_current_user),
):
    """Generate SEO keyword suggestions."""
    from agents.platform_agents import get_or_create_agent

    agent = await get_or_create_agent(user["business_id"], "seo")
    if not agent:
        raise HTTPException(status_code=500, detail="SEO agent unavailable")
    keywords = await agent.generate_keywords(req.topic, req.location)
    return {"success": True, "keywords": keywords}


@app.post("/api/seo/audit")
async def audit_seo_content(
    req: SEOAuditRequest,
    user: dict = Depends(get_current_user),
):
    """Audit content for SEO quality."""
    from agents.platform_agents import get_or_create_agent

    agent = await get_or_create_agent(user["business_id"], "seo")
    if not agent:
        raise HTTPException(status_code=500, detail="SEO agent unavailable")
    result = await agent.audit_seo_content(req.content, req.target_keyword)
    return {"success": True, "audit": result}


@app.post("/api/seo/gmb-post")
async def generate_gmb_post(
    req: GMBPostRequest,
    user: dict = Depends(get_current_user),
):
    """Generate a Google My Business post."""
    from agents.platform_agents import get_or_create_agent

    agent = await get_or_create_agent(user["business_id"], "seo")
    if not agent:
        raise HTTPException(status_code=500, detail="SEO agent unavailable")
    result = await agent.generate_gmb_post(req.category, req.description)
    return {"success": True, "post": result}


# ── HR Endpoints ──────────────────────────────────────────────────────────────


class JobPostingRequest(BaseModel):
    title: str
    department: str
    requirements: str = ""
    experience: str = ""

    @field_validator("title", "department")
    @classmethod
    def _title_len(cls, v: str) -> str:
        if len(v.strip()) > 200:
            raise ValueError("Field must be 200 characters or fewer")
        return v.strip()

    @field_validator("requirements")
    @classmethod
    def _req_len(cls, v: str) -> str:
        if len(v) > 5000:
            raise ValueError("Requirements must be 5000 characters or fewer")
        return v


class ResumeScreenRequest(BaseModel):
    resume_text: str
    job_description: str

    @field_validator("resume_text")
    @classmethod
    def _resume_len(cls, v: str) -> str:
        if len(v) > 10000:
            raise ValueError("Resume text must be 10000 characters or fewer")
        return v

    @field_validator("job_description")
    @classmethod
    def _jd_len(cls, v: str) -> str:
        if len(v) > 5000:
            raise ValueError("Job description must be 5000 characters or fewer")
        return v


class EmployerBrandRequest(BaseModel):
    topic: str = "culture"


@app.post("/api/hr/create-job-posting")
async def create_job_posting(
    req: JobPostingRequest,
    user: dict = Depends(get_current_user),
):
    """Generate an AI-powered job listing."""
    from agents.platform_agents import get_or_create_agent

    agent = await get_or_create_agent(user["business_id"], "hr")
    if not agent:
        raise HTTPException(status_code=500, detail="HR agent unavailable")
    result = await agent.create_job_posting(req.title, req.department, req.requirements, req.experience)
    return {"success": True, "job_posting": result}


@app.post("/api/hr/screen-resume")
async def screen_resume(
    req: ResumeScreenRequest,
    user: dict = Depends(get_current_user),
):
    """AI-powered resume screening."""
    from agents.platform_agents import get_or_create_agent

    agent = await get_or_create_agent(user["business_id"], "hr")
    if not agent:
        raise HTTPException(status_code=500, detail="HR agent unavailable")
    result = await agent.screen_resume(req.resume_text, req.job_description)
    return {"success": True, "screening": result}


@app.post("/api/hr/employer-brand-post")
async def generate_employer_brand_post(
    req: EmployerBrandRequest,
    user: dict = Depends(get_current_user),
):
    """Generate employer branding content for social media."""
    from agents.platform_agents import get_or_create_agent

    agent = await get_or_create_agent(user["business_id"], "hr")
    if not agent:
        raise HTTPException(status_code=500, detail="HR agent unavailable")
    result = await agent.generate_employer_brand_post(req.topic)
    return {"success": True, "post": result}


# ── AI Usage & Billing Endpoints ──────────────────────────────────────────────


@app.get("/api/usage/summary")
async def get_usage_summary(
    days: int = 30,
    user: dict = Depends(get_current_user),
):
    """Get AI usage summary — breakdown by agent, daily totals, cost."""
    from services.ai_usage import get_usage_summary as _usage_summary
    summary = await _usage_summary(user["business_id"], days=days)
    return {"success": True, **summary}


@app.get("/api/usage/limits")
async def get_usage_limits(user: dict = Depends(get_current_user)):
    """Check current quota / remaining tokens for this business."""
    from services.ai_usage import check_quota
    quota = await check_quota(user["business_id"])
    return {"success": True, **quota}


@app.get("/api/billing/history")
async def get_billing_history(user: dict = Depends(get_current_user)):
    """Get billing records (for tenants using platform-owner API keys)."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    session_factory = get_session_factory()
    async with session_factory() as session:
        rows = await session.execute(
            text(
                "SELECT id, period_start, period_end, ai_tokens_used, "
                "ai_cost_usd, status, created_at "
                "FROM billing_records WHERE business_id = :bid "
                "ORDER BY period_start DESC LIMIT 24"
            ),
            {"bid": user["business_id"]},
        )
        records = [dict(r._mapping) for r in rows.fetchall()]
    return {"success": True, "records": records}


class CreditRequestPayload(BaseModel):
    reason: str = ""


@app.post("/api/billing/request-credit")
async def request_credit_access(
    req: CreditRequestPayload,
    user: dict = Depends(get_current_user),
):
    """Tenant requests permission to use platform-owner's API keys."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    session_factory = get_session_factory()
    async with session_factory() as session:
        await session.execute(
            text(
                "UPDATE businesses SET uses_platform_api_keys = 1 "
                "WHERE id = :bid"
            ),
            {"bid": user["business_id"]},
        )
        await session.commit()

    from security.audit_log import audit, AuditEvent, Severity
    try:
        await audit(
            event=AuditEvent.CREDIT_REQUEST,
            severity=Severity.HIGH,
            actor=str(user["user_id"]),
            details={
                "business_id": user["business_id"],
                "reason": req.reason[:500],
            },
        )
    except Exception:
        pass

    return {
        "success": True,
        "message": "Credit access request submitted. An admin will review shortly.",
    }


@app.post("/api/billing/approve-credit/{business_id}")
async def approve_credit_access(
    business_id: int,
    user: dict = Depends(get_current_user),
):
    """Admin approves a tenant's credit request."""
    if user.get("role") != "admin":
        raise HTTPException(status_code=403, detail="Admin only")

    from memory.database import get_session_factory
    from sqlalchemy import text

    session_factory = get_session_factory()
    async with session_factory() as session:
        await session.execute(
            text(
                "UPDATE businesses SET credit_approved = 1 "
                "WHERE id = :bid"
            ),
            {"bid": business_id},
        )
        await session.commit()

    return {"success": True, "message": f"Credit approved for business {business_id}"}


@app.post("/api/billing/revoke-credit/{business_id}")
async def revoke_credit_access(
    business_id: int,
    user: dict = Depends(get_current_user),
):
    """Admin revokes a tenant's credit access."""
    if user.get("role") != "admin":
        raise HTTPException(status_code=403, detail="Admin only")

    from memory.database import get_session_factory
    from sqlalchemy import text

    session_factory = get_session_factory()
    async with session_factory() as session:
        await session.execute(
            text(
                "UPDATE businesses SET credit_approved = 0, "
                "uses_platform_api_keys = 0 WHERE id = :bid"
            ),
            {"bid": business_id},
        )
        await session.commit()

    return {"success": True, "message": f"Credit revoked for business {business_id}"}


# ── Subscription Plan Endpoints ───────────────────────────────────────────────


@app.get("/api/plans")
async def list_subscription_plans():
    """List available subscription plans (public)."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    session_factory = get_session_factory()
    async with session_factory() as session:
        rows = await session.execute(
            text(
                "SELECT name, display_name, monthly_token_limit, monthly_cost_usd, "
                "max_platforms, max_posts_per_month, max_ai_calls_per_day, "
                "features_json FROM subscription_plans WHERE is_active = 1 "
                "ORDER BY monthly_cost_usd"
            )
        )
        plans = [dict(r._mapping) for r in rows.fetchall()]
    return {"success": True, "plans": plans}


# ── Bot Personality Endpoints ─────────────────────────────────────────────────


class BotPersonalityRequest(BaseModel):
    persona_name: str = "default"
    system_prompt_override: str | None = None
    tone: str = "professional"
    response_style: str = "detailed"
    industry_context: str | None = None
    custom_commands_json: str | None = None

    @field_validator("tone")
    @classmethod
    def _valid_tone(cls, v: str) -> str:
        allowed = {"professional", "casual", "friendly", "witty"}
        if v.lower() not in allowed:
            raise ValueError(f"Tone must be one of: {', '.join(sorted(allowed))}")
        return v.lower()

    @field_validator("response_style")
    @classmethod
    def _valid_style(cls, v: str) -> str:
        allowed = {"detailed", "concise", "bullet"}
        if v.lower() not in allowed:
            raise ValueError(f"Style must be one of: {', '.join(sorted(allowed))}")
        return v.lower()

    @field_validator("persona_name")
    @classmethod
    def _persona_len(cls, v: str) -> str:
        if len(v.strip()) > 100:
            raise ValueError("Persona name must be 100 characters or fewer")
        return v.strip()


@app.get("/api/bot/personality")
async def get_bot_personality(user: dict = Depends(get_current_user)):
    """Get bot personality settings for this business."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    session_factory = get_session_factory()
    async with session_factory() as session:
        row = await session.execute(
            text(
                "SELECT persona_name, system_prompt_override, tone, "
                "response_style, industry_context, custom_commands_json, "
                "trained_examples_json FROM bot_personalities "
                "WHERE business_id = :bid LIMIT 1"
            ),
            {"bid": user["business_id"]},
        )
        personality = row.fetchone()
        if personality:
            return {"success": True, "personality": dict(personality._mapping)}
        return {"success": True, "personality": None}


@app.put("/api/bot/personality")
async def update_bot_personality(
    req: BotPersonalityRequest,
    user: dict = Depends(get_current_user),
):
    """Create or update bot personality settings."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    session_factory = get_session_factory()
    async with session_factory() as session:
        existing = await session.execute(
            text("SELECT id FROM bot_personalities WHERE business_id = :bid"),
            {"bid": user["business_id"]},
        )
        if existing.fetchone():
            await session.execute(
                text(
                    "UPDATE bot_personalities SET persona_name = :pn, "
                    "system_prompt_override = :spo, tone = :tone, "
                    "response_style = :rs, industry_context = :ic, "
                    "custom_commands_json = :cc, updated_at = NOW() "
                    "WHERE business_id = :bid"
                ),
                {
                    "bid": user["business_id"],
                    "pn": req.persona_name,
                    "spo": req.system_prompt_override,
                    "tone": req.tone,
                    "rs": req.response_style,
                    "ic": req.industry_context,
                    "cc": req.custom_commands_json,
                },
            )
        else:
            await session.execute(
                text(
                    "INSERT INTO bot_personalities (business_id, persona_name, "
                    "system_prompt_override, tone, response_style, "
                    "industry_context, custom_commands_json) VALUES "
                    "(:bid, :pn, :spo, :tone, :rs, :ic, :cc)"
                ),
                {
                    "bid": user["business_id"],
                    "pn": req.persona_name,
                    "spo": req.system_prompt_override,
                    "tone": req.tone,
                    "rs": req.response_style,
                    "ic": req.industry_context,
                    "cc": req.custom_commands_json,
                },
            )
        await session.commit()

    return {"success": True, "message": "Bot personality updated"}


class BotTrainRequest(BaseModel):
    question: str
    answer: str

    @field_validator("question", "answer")
    @classmethod
    def _train_len(cls, v: str) -> str:
        if len(v) > 2000:
            raise ValueError("Training text must be 2000 characters or fewer")
        if not v.strip():
            raise ValueError("Field cannot be empty")
        return v.strip()


@app.post("/api/bot/train")
async def train_bot(
    req: BotTrainRequest,
    user: dict = Depends(get_current_user),
):
    """Add a training example (Q&A pair) to the bot personality."""
    import json
    from memory.database import get_session_factory
    from sqlalchemy import text

    session_factory = get_session_factory()
    async with session_factory() as session:
        row = await session.execute(
            text(
                "SELECT id, trained_examples_json FROM bot_personalities "
                "WHERE business_id = :bid"
            ),
            {"bid": user["business_id"]},
        )
        existing = row.fetchone()

        new_example = {"q": req.question, "a": req.answer}

        if existing:
            examples = json.loads(existing.trained_examples_json or "[]")
            examples.append(new_example)
            await session.execute(
                text(
                    "UPDATE bot_personalities SET trained_examples_json = :te, "
                    "updated_at = NOW() WHERE business_id = :bid"
                ),
                {"te": json.dumps(examples), "bid": user["business_id"]},
            )
        else:
            await session.execute(
                text(
                    "INSERT INTO bot_personalities (business_id, persona_name, "
                    "trained_examples_json) VALUES (:bid, 'default', :te)"
                ),
                {"te": json.dumps([new_example]), "bid": user["business_id"]},
            )
        await session.commit()

    return {"success": True, "message": "Training example added"}


@app.post("/api/bot/test-response")
async def test_bot_response(
    req: AiAssistantRequest,
    user: dict = Depends(get_current_user),
):
    """Preview how the bot would respond with current personality settings."""
    import json
    from openai import AsyncOpenAI
    from memory.database import get_session_factory, load_business_context
    from sqlalchemy import text

    settings = get_settings()
    client = AsyncOpenAI(api_key=settings.openai_api_key)

    session_factory = get_session_factory()
    async with session_factory() as session:
        row = await session.execute(
            text(
                "SELECT persona_name, system_prompt_override, tone, "
                "response_style, industry_context, trained_examples_json "
                "FROM bot_personalities WHERE business_id = :bid LIMIT 1"
            ),
            {"bid": user["business_id"]},
        )
        personality = row.fetchone()

    biz_ctx = await load_business_context(user["business_id"])
    biz_name = biz_ctx.get("name", "your business")

    if personality and personality.system_prompt_override:
        system = personality.system_prompt_override
    else:
        tone = personality.tone if personality else "professional"
        style = personality.response_style if personality else "detailed"
        system = (
            f"You are the AI assistant for {biz_name}. "
            f"Your tone is {tone} and your response style is {style}. "
        )
        if personality and personality.industry_context:
            system += f"Industry context: {personality.industry_context}. "

    messages = [{"role": "system", "content": system}]

    # Inject trained examples as few-shot context
    if personality and personality.trained_examples_json:
        examples = json.loads(personality.trained_examples_json or "[]")
        for ex in examples[-10:]:
            messages.append({"role": "user", "content": ex["q"]})
            messages.append({"role": "assistant", "content": ex["a"]})

    messages.append({"role": "user", "content": req.message})

    try:
        response = await client.chat.completions.create(
            model="gpt-4o-mini",
            messages=messages,
            temperature=0.7,
            max_tokens=600,
        )
        return {
            "success": True,
            "response": response.choices[0].message.content,
            "preview": True,
        }
    except Exception as e:
        return {"success": False, "response": str(e)[:200]}


# ── A/B Testing & Calendar Endpoints ──────────────────────────────────────────


class ABCaptionRequest(BaseModel):
    content_description: str
    content_category: str = ""
    mood: str = ""
    count: int = 3

    @field_validator("content_description")
    @classmethod
    def _desc_len(cls, v: str) -> str:
        if len(v) > 2000:
            raise ValueError("Description must be 2000 characters or fewer")
        return v


@app.post("/api/captions/ab-test")
async def generate_ab_captions(
    req: ABCaptionRequest,
    platform: str = "instagram",
    user: dict = Depends(get_current_user),
):
    """Generate A/B caption variants for testing."""
    from agents.platform_agents import get_or_create_agent

    agent = await get_or_create_agent(user["business_id"], platform)
    if not agent:
        raise HTTPException(status_code=500, detail=f"Agent for {platform} unavailable")

    variants = await agent.generate_ab_captions(
        content_description=req.content_description,
        content_category=req.content_category,
        mood=req.mood,
        count=req.count,
    )
    return {"success": True, "variants": variants, "platform": platform}


class CalendarRequest(BaseModel):
    days_ahead: int = 7
    themes: list[str] = []

    @field_validator("days_ahead")
    @classmethod
    def _days_range(cls, v: int) -> int:
        if v < 1 or v > 30:
            raise ValueError("days_ahead must be between 1 and 30")
        return v


@app.post("/api/calendar/auto-fill")
async def auto_fill_calendar(
    req: CalendarRequest,
    user: dict = Depends(get_current_user),
):
    """Auto-generate a content calendar for the next N days."""
    from agents.content_strategist import auto_fill_calendar as _auto_fill

    calendar = await _auto_fill(
        business_id=user["business_id"],
        days_ahead=req.days_ahead,
        themes=req.themes,
    )
    return {"success": True, "calendar": calendar, "days": req.days_ahead}


@app.post("/api/media/enhance")
async def enhance_media(
    post_id: int = Form(...),
    platform: str = Form(""),
    user: dict = Depends(get_current_user),
):
    """Enhance an image's quality and optionally apply a platform filter."""
    from memory.database import get_session_factory
    from sqlalchemy import text
    from agents.media_editor import enhance_image_quality, apply_platform_filter

    session_factory = get_session_factory()
    async with session_factory() as session:
        row = await session.execute(
            text("SELECT file_path FROM media_items WHERE id = :id"),
            {"id": post_id},
        )
        media = row.fetchone()
        if not media:
            raise HTTPException(status_code=404, detail="Media not found")

    file_path = media.file_path
    try:
        enhanced = enhance_image_quality(file_path)
        result = {"enhanced": str(enhanced)}

        if platform:
            filtered = apply_platform_filter(enhanced, platform)
            result["filtered"] = str(filtered)

        return {"success": True, **result}
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Enhancement failed: {str(e)[:200]}")


# ── File Serving ──────────────────────────────────────────────────────────────


@app.get("/api/media/{path:path}")
async def serve_media(path: str, user: dict = Depends(get_current_user_or_api_key)):
    """Serve media files (edited photos, processed videos) for the dashboard."""
    from fastapi.responses import FileResponse

    full_path = MEDIA_ROOT / path
    if not full_path.exists():
        raise HTTPException(status_code=404, detail="File not found")

    # Ensure path is within MEDIA_ROOT (security)
    try:
        full_path.resolve().relative_to(MEDIA_ROOT.resolve())
    except ValueError:
        raise HTTPException(status_code=403, detail="Access denied")

    return FileResponse(str(full_path))


# ── AI Model Management ──────────────────────────────────────────────────────


class AiModelRequest(BaseModel):
    provider: str
    api_key: str
    model_name: str | None = None
    base_url: str | None = None  # For ollama / openai_compatible endpoints


@app.get("/api/ai-models")
async def list_ai_models(user: dict = Depends(get_current_user)):
    """List all configured AI model providers for this business."""
    from sqlalchemy import select, text
    from memory.database import get_session_factory

    async with get_session_factory()() as session:
        rows = await session.execute(
            text(
                "SELECT id, provider, api_key_encrypted, model_name, base_url, is_active, status, "
                "last_checked_at, last_error, created_at "
                "FROM ai_provider_configs WHERE business_id = :bid ORDER BY provider"
            ),
            {"bid": user["business_id"]},
        )
        models = []
        for r in rows.fetchall():
            key_enc = r.api_key_encrypted or ""
            # Mask: show first 4 and last 4 chars
            try:
                from security.encryption import decrypt
                raw_key = decrypt(key_enc)
                masked = raw_key[:6] + "..." + raw_key[-4:] if len(raw_key) > 10 else "****"
            except Exception:
                masked = "****"
            models.append({
                "id": r.id,
                "provider": r.provider,
                "masked_key": masked,
                "model_name": r.model_name,
                "base_url": r.base_url if hasattr(r, "base_url") else None,
                "is_active": bool(r.is_active),
                "status": r.status or "pending",
                "last_checked_at": str(r.last_checked_at) if r.last_checked_at else None,
                "last_error": r.last_error,
            })
    return {"success": True, "models": models}


@app.post("/api/ai-models")
async def save_ai_model(req: AiModelRequest, user: dict = Depends(get_current_user)):
    """Save or update an AI model provider configuration."""
    from sqlalchemy import text
    from security.encryption import encrypt
    from memory.database import get_session_factory

    valid_providers = {"openai", "google_gemini", "anthropic", "mistral", "deepseek", "groq", "ollama", "openai_compatible"}
    if req.provider not in valid_providers:
        raise HTTPException(status_code=400, detail=f"Invalid provider. Must be one of: {', '.join(sorted(valid_providers))}")

    encrypted_key = encrypt(req.api_key)
    bid = user["business_id"]

    async with get_session_factory()() as session:
        # Upsert
        existing = await session.execute(
            text("SELECT id FROM ai_provider_configs WHERE business_id = :bid AND provider = :p"),
            {"bid": bid, "p": req.provider},
        )
        row = existing.fetchone()

        if row:
            await session.execute(
                text(
                    "UPDATE ai_provider_configs SET api_key_encrypted = :key, model_name = :model, "
                    "base_url = :base_url, status = 'pending', updated_at = NOW() WHERE id = :id"
                ),
                {"key": encrypted_key, "model": req.model_name, "base_url": req.base_url, "id": row.id},
            )
        else:
            await session.execute(
                text(
                    "INSERT INTO ai_provider_configs (business_id, provider, api_key_encrypted, model_name, base_url, status, created_at) "
                    "VALUES (:bid, :p, :key, :model, :base_url, 'pending', NOW())"
                ),
                {"bid": bid, "p": req.provider, "key": encrypted_key, "model": req.model_name, "base_url": req.base_url},
            )
        await session.commit()

    # Test immediately
    test_result = await _test_ai_provider(req.provider, req.api_key, req.model_name, req.base_url)

    async with get_session_factory()() as session:
        await session.execute(
            text(
                "UPDATE ai_provider_configs SET status = :status, last_checked_at = NOW(), "
                "last_error = :err WHERE business_id = :bid AND provider = :p"
            ),
            {
                "status": "active" if test_result["success"] else "error",
                "err": test_result.get("error"),
                "bid": bid,
                "p": req.provider,
            },
        )
        await session.commit()

    return {
        "success": True,
        "message": f"{req.provider} configured" + (" and verified" if test_result["success"] else " (test failed)"),
        "test_result": test_result,
    }


@app.post("/api/ai-models/{provider}/test")
async def test_ai_model_endpoint(provider: str, user: dict = Depends(get_current_user)):
    """Test an already-configured AI model provider."""
    from sqlalchemy import text
    from security.encryption import decrypt
    from memory.database import get_session_factory

    async with get_session_factory()() as session:
        row = await session.execute(
            text("SELECT api_key_encrypted, model_name, base_url FROM ai_provider_configs WHERE business_id = :bid AND provider = :p"),
            {"bid": user["business_id"], "p": provider},
        )
        config = row.fetchone()
        if not config:
            raise HTTPException(status_code=404, detail=f"No {provider} configuration found")

        api_key = decrypt(config.api_key_encrypted)
        model_name = config.model_name
        provider_base_url = config.base_url if hasattr(config, "base_url") else None

    result = await _test_ai_provider(provider, api_key, model_name, provider_base_url)

    async with get_session_factory()() as session:
        await session.execute(
            text(
                "UPDATE ai_provider_configs SET status = :status, last_checked_at = NOW(), "
                "last_error = :err WHERE business_id = :bid AND provider = :p"
            ),
            {
                "status": "active" if result["success"] else "error",
                "err": result.get("error"),
                "bid": user["business_id"],
                "p": provider,
            },
        )
        await session.commit()

    return {"success": result["success"], "message": result.get("message", ""), "error": result.get("error")}


@app.delete("/api/ai-models/{provider}")
async def delete_ai_model(provider: str, user: dict = Depends(get_current_user)):
    """Remove an AI model provider configuration."""
    from sqlalchemy import text
    from memory.database import get_session_factory

    async with get_session_factory()() as session:
        await session.execute(
            text("DELETE FROM ai_provider_configs WHERE business_id = :bid AND provider = :p"),
            {"bid": user["business_id"], "p": provider},
        )
        await session.commit()

    return {"success": True, "message": f"{provider} configuration removed"}


async def _test_ai_provider(provider: str, api_key: str, model_name: str | None = None, base_url: str | None = None) -> dict:
    """Test an AI provider API key by sending a minimal request."""
    import httpx

    try:
        start = time.time()

        if provider == "openai":
            model = model_name or "gpt-4o-mini"
            async with httpx.AsyncClient(timeout=15) as client:
                resp = await client.post(
                    "https://api.openai.com/v1/chat/completions",
                    headers={"Authorization": f"Bearer {api_key}"},
                    json={"model": model, "messages": [{"role": "user", "content": "Say hello in 3 words"}], "max_tokens": 10},
                )
            latency = round(time.time() - start, 2)
            if resp.status_code == 200:
                return {"success": True, "message": f"OpenAI ({model}) responding — {latency}s latency", "latency": latency}
            else:
                return {"success": False, "error": f"HTTP {resp.status_code}: {resp.text[:200]}"}

        elif provider == "google_gemini":
            model = model_name or "gemini-2.0-flash"
            async with httpx.AsyncClient(timeout=15) as client:
                resp = await client.post(
                    f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={api_key}",
                    json={"contents": [{"parts": [{"text": "Say hello in 3 words"}]}]},
                )
            latency = round(time.time() - start, 2)
            if resp.status_code == 200:
                return {"success": True, "message": f"Gemini ({model}) responding — {latency}s latency", "latency": latency}
            else:
                return {"success": False, "error": f"HTTP {resp.status_code}: {resp.text[:200]}"}

        elif provider == "anthropic":
            model = model_name or "claude-sonnet-4-20250514"
            async with httpx.AsyncClient(timeout=15) as client:
                resp = await client.post(
                    "https://api.anthropic.com/v1/messages",
                    headers={"x-api-key": api_key, "anthropic-version": "2023-06-01", "content-type": "application/json"},
                    json={"model": model, "max_tokens": 10, "messages": [{"role": "user", "content": "Say hello in 3 words"}]},
                )
            latency = round(time.time() - start, 2)
            if resp.status_code == 200:
                return {"success": True, "message": f"Claude ({model}) responding — {latency}s latency", "latency": latency}
            else:
                return {"success": False, "error": f"HTTP {resp.status_code}: {resp.text[:200]}"}

        elif provider == "mistral":
            model = model_name or "mistral-large-latest"
            async with httpx.AsyncClient(timeout=15) as client:
                resp = await client.post(
                    "https://api.mistral.ai/v1/chat/completions",
                    headers={"Authorization": f"Bearer {api_key}"},
                    json={"model": model, "messages": [{"role": "user", "content": "Say hello in 3 words"}], "max_tokens": 10},
                )
            latency = round(time.time() - start, 2)
            if resp.status_code == 200:
                return {"success": True, "message": f"Mistral ({model}) responding — {latency}s latency", "latency": latency}
            else:
                return {"success": False, "error": f"HTTP {resp.status_code}: {resp.text[:200]}"}

        elif provider == "deepseek":
            model = model_name or "deepseek-chat"
            async with httpx.AsyncClient(timeout=15) as client:
                resp = await client.post(
                    "https://api.deepseek.com/chat/completions",
                    headers={"Authorization": f"Bearer {api_key}"},
                    json={"model": model, "messages": [{"role": "user", "content": "Say hello in 3 words"}], "max_tokens": 10},
                )
            latency = round(time.time() - start, 2)
            if resp.status_code == 200:
                return {"success": True, "message": f"DeepSeek ({model}) responding — {latency}s latency", "latency": latency}
            else:
                return {"success": False, "error": f"HTTP {resp.status_code}: {resp.text[:200]}"}

        elif provider == "groq":
            model = model_name or "llama-3.1-70b-versatile"
            async with httpx.AsyncClient(timeout=15) as client:
                resp = await client.post(
                    "https://api.groq.com/openai/v1/chat/completions",
                    headers={"Authorization": f"Bearer {api_key}"},
                    json={"model": model, "messages": [{"role": "user", "content": "Say hello in 3 words"}], "max_tokens": 10},
                )
            latency = round(time.time() - start, 2)
            if resp.status_code == 200:
                return {"success": True, "message": f"Groq ({model}) responding — {latency}s latency", "latency": latency}
            else:
                return {"success": False, "error": f"HTTP {resp.status_code}: {resp.text[:200]}"}

        elif provider == "ollama":
            base = base_url or "http://localhost:11434"
            model = model_name or "llama3"
            async with httpx.AsyncClient(timeout=15) as client:
                resp = await client.post(
                    f"{base.rstrip('/')}/api/generate",
                    json={"model": model, "prompt": "Say hello in 3 words", "stream": False},
                )
            latency = round(time.time() - start, 2)
            if resp.status_code == 200:
                return {"success": True, "message": f"Ollama ({model}) responding at {base} — {latency}s latency", "latency": latency}
            else:
                return {"success": False, "error": f"HTTP {resp.status_code}: {resp.text[:200]}"}

        elif provider == "openai_compatible":
            base = base_url or "http://localhost:8080"
            model = model_name or "default"
            async with httpx.AsyncClient(timeout=15) as client:
                resp = await client.post(
                    f"{base.rstrip('/')}/v1/chat/completions",
                    headers={"Authorization": f"Bearer {api_key}"} if api_key else {},
                    json={"model": model, "messages": [{"role": "user", "content": "Say hello in 3 words"}], "max_tokens": 10},
                )
            latency = round(time.time() - start, 2)
            if resp.status_code == 200:
                return {"success": True, "message": f"Custom endpoint ({model}) at {base} — {latency}s latency", "latency": latency}
            else:
                return {"success": False, "error": f"HTTP {resp.status_code}: {resp.text[:200]}"}

        else:
            return {"success": False, "error": f"Unknown provider: {provider}"}

    except Exception as e:
        return {"success": False, "error": str(e)[:200]}


# ── Bot Training (File / URL / Knowledge) ────────────────────────────────────


@app.post("/api/bot/train-from-file")
async def train_bot_from_file(
    file: UploadFile = File(...),
    user: dict = Depends(get_current_user),
):
    """Upload a ZIP/text/PDF file to train the bot's knowledge base."""
    import tempfile
    import zipfile

    bid = user["business_id"]
    allowed_ext = {".txt", ".md", ".pdf", ".csv", ".json", ".html", ".zip", ".docx"}
    fext = Path(file.filename or "upload").suffix.lower()

    if fext not in allowed_ext:
        raise HTTPException(status_code=400, detail=f"Unsupported file type. Allowed: {', '.join(sorted(allowed_ext))}")

    # Save to temp
    with tempfile.TemporaryDirectory() as tmpdir:
        file_path = Path(tmpdir) / (file.filename or "upload")
        content = await file.read()
        file_path.write_bytes(content)

        texts = []
        source_name = file.filename or "upload"

        if fext == ".zip":
            with zipfile.ZipFile(file_path, "r") as zf:
                for name in zf.namelist():
                    if any(name.lower().endswith(e) for e in [".txt", ".md", ".csv", ".json", ".html"]):
                        try:
                            texts.append(zf.read(name).decode("utf-8", errors="ignore"))
                        except Exception:
                            pass
        elif fext == ".pdf":
            try:
                import fitz  # PyMuPDF
                doc = fitz.open(str(file_path))
                for page in doc:
                    texts.append(page.get_text())
            except ImportError:
                # Fallback: read as binary and extract text-like content
                raw = file_path.read_text(errors="ignore")
                texts.append(raw)
        else:
            texts.append(file_path.read_text(encoding="utf-8", errors="ignore"))

    if not texts:
        raise HTTPException(status_code=400, detail="No readable text found in uploaded file")

    combined = "\n\n".join(texts)
    chunks = _chunk_text(combined, max_chunk=1000)

    # Store in ChromaDB knowledge collection
    stored = await _store_knowledge_chunks(bid, source_name, chunks)

    return {
        "success": True,
        "message": f"Processed '{source_name}': {stored} chunks stored in knowledge base",
        "source": source_name,
        "chunks_stored": stored,
    }


@app.post("/api/bot/train-from-url")
async def train_bot_from_url(request: Request, user: dict = Depends(get_current_user)):
    """Fetch a URL and extract text content to train the bot."""
    import httpx

    body = await request.json()
    url = body.get("url", "").strip()
    if not url:
        raise HTTPException(status_code=400, detail="URL is required")

    bid = user["business_id"]

    try:
        async with httpx.AsyncClient(timeout=30, follow_redirects=True, verify=False) as client:
            resp = await client.get(url, headers={"User-Agent": "Mozilla/5.0 (compatible; AvivaBot/1.0)"})
            resp.raise_for_status()
            html = resp.text
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"Failed to fetch URL: {str(e)[:200]}")

    # Extract text from HTML
    text_content = _extract_text_from_html(html)
    if len(text_content.strip()) < 50:
        raise HTTPException(status_code=400, detail="URL returned too little readable text")

    chunks = _chunk_text(text_content, max_chunk=1000)
    source_name = url[:100]
    stored = await _store_knowledge_chunks(bid, source_name, chunks)

    return {
        "success": True,
        "message": f"Extracted {stored} knowledge chunks from URL",
        "source": source_name,
        "chunks_stored": stored,
    }


@app.get("/api/bot/knowledge")
async def list_knowledge_base(user: dict = Depends(get_current_user)):
    """List knowledge sources stored for this business."""
    bid = user["business_id"]
    try:
        import chromadb
        client = chromadb.PersistentClient(path="chroma_data")
        col_name = f"knowledge_{bid}"
        try:
            col = client.get_collection(col_name)
        except Exception:
            return {"success": True, "sources": [], "total_chunks": 0}

        # Get all documents metadata
        results = col.get(include=["metadatas"])
        sources_map = {}
        for meta in (results.get("metadatas") or []):
            src = meta.get("source", "unknown")
            if src not in sources_map:
                sources_map[src] = {"source": src, "chunks": 0, "added_at": meta.get("added_at", "")}
            sources_map[src]["chunks"] += 1

        return {
            "success": True,
            "sources": list(sources_map.values()),
            "total_chunks": len(results.get("metadatas") or []),
        }
    except Exception as e:
        return {"success": True, "sources": [], "total_chunks": 0, "note": str(e)[:100]}


@app.delete("/api/bot/knowledge/{source_id}")
async def delete_knowledge_source(source_id: str, user: dict = Depends(get_current_user)):
    """Delete all chunks from a knowledge source."""
    bid = user["business_id"]
    import urllib.parse
    source_name = urllib.parse.unquote(source_id)

    try:
        import chromadb
        client = chromadb.PersistentClient(path="chroma_data")
        col = client.get_collection(f"knowledge_{bid}")

        # Find all IDs with this source
        results = col.get(include=["metadatas"], where={"source": source_name})
        ids = results.get("ids") or []
        if ids:
            col.delete(ids=ids)

        return {"success": True, "message": f"Deleted {len(ids)} chunks from '{source_name}'"}
    except Exception as e:
        return {"success": False, "message": str(e)[:200]}


def _chunk_text(text: str, max_chunk: int = 1000) -> list[str]:
    """Split text into overlapping chunks."""
    sentences = re.split(r'(?<=[.!?])\s+', text)
    chunks = []
    current = ""
    for s in sentences:
        if len(current) + len(s) > max_chunk and current:
            chunks.append(current.strip())
            # Overlap: keep last sentence
            current = s
        else:
            current += " " + s if current else s
    if current.strip():
        chunks.append(current.strip())
    return [c for c in chunks if len(c) > 20]  # Drop tiny chunks


def _extract_text_from_html(html: str) -> str:
    """Extract readable text from HTML, removing scripts/styles."""
    # Remove scripts, styles, and HTML tags
    html = re.sub(r'<script[^>]*>.*?</script>', '', html, flags=re.DOTALL | re.IGNORECASE)
    html = re.sub(r'<style[^>]*>.*?</style>', '', html, flags=re.DOTALL | re.IGNORECASE)
    html = re.sub(r'<[^>]+>', ' ', html)
    html = re.sub(r'\s+', ' ', html)
    # Decode HTML entities
    import html as html_mod
    return html_mod.unescape(html).strip()


async def _store_knowledge_chunks(business_id: int, source_name: str, chunks: list[str]) -> int:
    """Store text chunks in ChromaDB knowledge collection with deduplication."""
    import chromadb
    import hashlib

    client = chromadb.PersistentClient(path="chroma_data")
    col_name = f"knowledge_{business_id}"
    col = client.get_or_create_collection(col_name)

    # Deduplicate: check existing hashes
    existing = col.get(include=["metadatas"])
    existing_hashes = set()
    for meta in (existing.get("metadatas") or []):
        h = meta.get("content_hash")
        if h:
            existing_hashes.add(h)

    added = 0
    now = datetime.now().isoformat()
    for i, chunk in enumerate(chunks):
        content_hash = hashlib.sha256(chunk.encode()).hexdigest()[:16]
        if content_hash in existing_hashes:
            continue

        doc_id = f"{source_name}_{i}_{content_hash}"
        try:
            col.add(
                documents=[chunk],
                metadatas=[{"source": source_name, "content_hash": content_hash, "added_at": now}],
                ids=[doc_id],
            )
            added += 1
        except Exception:
            pass

    return added


# ── Multi-Business ────────────────────────────────────────────────────────────


@app.get("/api/businesses")
async def list_businesses(user: dict = Depends(get_current_user)):
    """List all businesses the user has access to (via user_business_links or direct ownership)."""
    from sqlalchemy import text
    from memory.database import get_session_factory

    async with get_session_factory()() as session:
        # Ensure user_business_links table exists (graceful fallback)
        try:
            rows = await session.execute(
                text(
                    "SELECT b.id, b.name, b.slug, b.industry, b.subscription_plan, b.is_active, "
                    "ubl.role AS link_role "
                    "FROM user_business_links ubl "
                    "JOIN businesses b ON b.id = ubl.business_id "
                    "WHERE ubl.user_id = :uid ORDER BY b.name"
                ),
                {"uid": user["user_id"]},
            )
            businesses = []
            for r in rows.fetchall():
                businesses.append({
                    "id": r.id,
                    "name": r.name,
                    "slug": r.slug,
                    "industry": r.industry,
                    "plan": r.subscription_plan,
                    "is_active": bool(r.is_active),
                    "role": r.link_role,
                })
        except Exception:
            # Fallback: use the direct user → business link
            rows = await session.execute(
                text(
                    "SELECT b.id, b.name, b.slug, b.industry, b.subscription_plan, b.is_active "
                    "FROM businesses b JOIN users u ON u.business_id = b.id "
                    "WHERE u.id = :uid ORDER BY b.name"
                ),
                {"uid": user["user_id"]},
            )
            businesses = []
            for r in rows.fetchall():
                businesses.append({
                    "id": r.id,
                    "name": r.name,
                    "slug": r.slug,
                    "industry": r.industry,
                    "plan": r.subscription_plan,
                    "is_active": bool(r.is_active),
                })

    return {"success": True, "businesses": businesses, "current_business_id": user["business_id"]}


class CreateBusinessRequest(BaseModel):
    name: str
    industry: str | None = None
    selected_platforms: list[str] = []  # Platforms to auto-clone agents for

    @field_validator("name")
    @classmethod
    def _name_check(cls, v: str) -> str:
        v = v.strip()
        if not v or len(v) > 200:
            raise ValueError("Business name is required and must be 200 characters or fewer")
        return v


@app.post("/api/businesses")
async def create_business(req: CreateBusinessRequest, user: dict = Depends(get_current_user)):
    """Create a new business and link to current user. Auto-clones trained agents."""
    from sqlalchemy import text
    from memory.database import get_session_factory

    slug = re.sub(r'[^a-z0-9]+', '-', req.name.lower()).strip('-')

    async with get_session_factory()() as session:
        # Check slug uniqueness
        existing = await session.execute(text("SELECT id FROM businesses WHERE slug = :s"), {"s": slug})
        if existing.fetchone():
            slug = f"{slug}-{int(time.time()) % 10000}"

        result = await session.execute(
            text(
                "INSERT INTO businesses (name, slug, industry, subscription_plan, is_active, created_at) "
                "VALUES (:name, :slug, :industry, 'free', 1, NOW())"
            ),
            {"name": req.name, "slug": slug, "industry": req.industry or None},
        )
        new_id = result.lastrowid

        # Link user → new business via junction table (do NOT overwrite active business)
        try:
            await session.execute(
                text(
                    "INSERT INTO user_business_links (user_id, business_id, role, created_at) "
                    "VALUES (:uid, :bid, 'owner', NOW())"
                ),
                {"uid": user["user_id"], "bid": new_id},
            )
        except Exception:
            pass  # Table may not exist yet in older installations

        # Also ensure current business is linked
        try:
            await session.execute(
                text(
                    "INSERT IGNORE INTO user_business_links (user_id, business_id, role, created_at) "
                    "VALUES (:uid, :bid, 'owner', NOW())"
                ),
                {"uid": user["user_id"], "bid": user["business_id"]},
            )
        except Exception:
            pass

        # Auto-clone trained platform agents from user's current business
        source_bid = user["business_id"]
        if req.selected_platforms:
            platforms_list = ", ".join(f"'{p}'" for p in req.selected_platforms if p.isalpha())
            if platforms_list:
                try:
                    agents = await session.execute(
                        text(
                            f"SELECT platform, system_prompt_override, agent_type, learning_profile, "
                            f"performance_stats, trained_from_repos, skill_version "
                            f"FROM platform_agents WHERE business_id = :bid AND platform IN ({platforms_list})"
                        ),
                        {"bid": source_bid},
                    )
                    for agent in agents.fetchall():
                        await session.execute(
                            text(
                                "INSERT INTO platform_agents "
                                "(business_id, platform, system_prompt_override, agent_type, learning_profile, "
                                "performance_stats, trained_from_repos, skill_version, is_active, created_at) "
                                "VALUES (:bid, :platform, :prompt, :atype, :learn, :perf, :repos, :skill, 1, NOW())"
                            ),
                            {
                                "bid": new_id,
                                "platform": agent.platform,
                                "prompt": agent.system_prompt_override,
                                "atype": agent.agent_type,
                                "learn": agent.learning_profile,
                                "perf": agent.performance_stats,
                                "repos": agent.trained_from_repos,
                                "skill": agent.skill_version,
                            },
                        )
                except Exception as e:
                    logger.warning(f"Agent cloning partial failure: {e}")

        await session.commit()

    return {
        "success": True,
        "message": f"Business '{req.name}' created",
        "business_id": new_id,
        "agents_cloned": len(req.selected_platforms),
    }


@app.post("/api/auth/switch-business")
async def switch_business(request: Request, user: dict = Depends(get_current_user)):
    """Switch the user's active business and return a new JWT."""
    from sqlalchemy import text
    from memory.database import get_session_factory

    body = await request.json()
    target_bid = body.get("business_id")
    if not target_bid:
        raise HTTPException(status_code=400, detail="business_id required")

    async with get_session_factory()() as session:
        # Verify business exists and is active
        row = await session.execute(
            text("SELECT id FROM businesses WHERE id = :bid AND is_active = 1"),
            {"bid": target_bid},
        )
        if not row.fetchone():
            raise HTTPException(status_code=404, detail="Business not found")

        # Verify user has access to this business (via junction table or direct ownership)
        try:
            link = await session.execute(
                text(
                    "SELECT id FROM user_business_links WHERE user_id = :uid AND business_id = :bid"
                ),
                {"uid": user["user_id"], "bid": target_bid},
            )
            if not link.fetchone():
                raise HTTPException(status_code=403, detail="Access denied — not linked to this business")
        except HTTPException:
            raise
        except Exception:
            pass  # Junction table may not exist yet; allow the switch

        # Update user's active business_id
        await session.execute(
            text("UPDATE users SET business_id = :bid WHERE id = :uid"),
            {"bid": target_bid, "uid": user["user_id"]},
        )
        await session.commit()

    # Issue new JWT with updated business_id
    new_token = _create_jwt(int(user["user_id"]), target_bid, user["role"])

    return {"success": True, "message": "Switched business", "token": new_token, "business_id": target_bid}


# ── Main Entry Point ─────────────────────────────────────────────────────────

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(
        "api.main:app",
        host="127.0.0.1",
        port=8001,
        reload=True,
    )

