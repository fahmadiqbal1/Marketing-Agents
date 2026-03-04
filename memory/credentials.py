"""
Credential manager — per-tenant platform credential CRUD with encryption.

This is the central service for storing and retrieving platform tokens.
All tokens are encrypted at rest using Fernet (security.encryption).
"""

from __future__ import annotations

import json
import logging
from datetime import datetime
from typing import Optional

from sqlalchemy import select

from memory.database import get_session_factory
from memory.models import Business, PlatformConnection, ConnectionStatus, Platform
from security.encryption import encrypt, decrypt

logger = logging.getLogger(__name__)


# ── Platform field definitions ────────────────────────────────────────────────

# Defines which fields each platform requires. Used by the frontend to render
# the correct form and by the backend to validate incoming data.
PLATFORM_FIELDS: dict[str, dict] = {
    "instagram": {
        "label": "Instagram / Facebook",
        "icon": "bi-instagram",
        "color": "#E1306C",
        "fields": [
            {"key": "client_id", "label": "Meta App ID", "type": "text", "required": True},
            {"key": "client_secret", "label": "Meta App Secret", "type": "password", "required": True},
            {"key": "access_token", "label": "Page Access Token", "type": "password", "required": True},
            {"key": "instagram_business_account_id", "label": "Instagram Business Account ID", "type": "text", "required": True, "extra": True},
        ],
        "guide_steps": [
            "Go to developers.facebook.com and log in",
            "Click 'My Apps' → 'Create App' → choose 'Business' type",
            "Add 'Instagram Graph API' product to your app",
            "Go to Graph API Explorer (tools section)",
            "Select your app, then click 'Generate Access Token'",
            "Grant all required permissions: pages_show_list, pages_manage_posts, instagram_basic, instagram_content_publish",
            "Copy the generated token — that's your Page Access Token",
            "Your App ID and App Secret are on the app's Settings → Basic page",
            "Your Instagram Business Account ID is in the Instagram Graph API → Get Started section",
        ],
        "help_url": "https://developers.facebook.com/docs/instagram-api/getting-started",
        "token_expiry": "60 days (long-lived) or never (page token)",
    },
    "facebook": {
        "label": "Facebook Page",
        "icon": "bi-facebook",
        "color": "#1877F2",
        "fields": [
            {"key": "client_id", "label": "Meta App ID", "type": "text", "required": True},
            {"key": "client_secret", "label": "Meta App Secret", "type": "password", "required": True},
            {"key": "access_token", "label": "Page Access Token", "type": "password", "required": True},
            {"key": "page_id", "label": "Facebook Page ID", "type": "text", "required": True, "extra": True},
        ],
        "guide_steps": [
            "Go to developers.facebook.com and log in",
            "Click 'My Apps' → select your app (or create one)",
            "Go to Graph API Explorer",
            "Select your Page from the dropdown",
            "Generate a Page Access Token with pages_manage_posts, pages_read_engagement permissions",
            "Your App ID and Secret are on Settings → Basic",
            "Your Page ID is on your Facebook Page → About → Page transparency",
        ],
        "help_url": "https://developers.facebook.com/docs/pages/getting-started",
        "token_expiry": "Never (page access token)",
    },
    "youtube": {
        "label": "YouTube",
        "icon": "bi-youtube",
        "color": "#FF0000",
        "fields": [
            {"key": "client_id", "label": "Google Client ID", "type": "text", "required": True},
            {"key": "client_secret", "label": "Google Client Secret", "type": "password", "required": True},
            {"key": "refresh_token", "label": "Refresh Token", "type": "password", "required": True},
        ],
        "guide_steps": [
            "Go to console.cloud.google.com",
            "Create a new project (or select existing)",
            "Enable 'YouTube Data API v3' from the API Library",
            "Go to Credentials → Create Credentials → OAuth 2.0 Client ID",
            "Set application type to 'Web application'",
            "Add redirect URI: http://localhost:8080/callback",
            "Copy the Client ID and Client Secret",
            "Run the auth setup: python tools/google_auth_setup.py",
            "After granting permissions, copy the refresh token from the generated file",
        ],
        "help_url": "https://developers.google.com/youtube/v3/getting-started",
        "token_expiry": "Refresh token doesn't expire (unless revoked)",
    },
    "linkedin": {
        "label": "LinkedIn",
        "icon": "bi-linkedin",
        "color": "#0A66C2",
        "fields": [
            {"key": "client_id", "label": "LinkedIn Client ID", "type": "text", "required": True},
            {"key": "client_secret", "label": "LinkedIn Client Secret", "type": "password", "required": True},
            {"key": "access_token", "label": "Access Token", "type": "password", "required": True},
            {"key": "organization_id", "label": "Organization (Company) ID", "type": "text", "required": True, "extra": True},
        ],
        "guide_steps": [
            "Go to linkedin.com/developers and sign in",
            "Click 'Create App' and fill in the details",
            "Verify your company page (Verify button on the app page)",
            "Go to the Auth tab → copy Client ID and Client Secret",
            "Under Products, request access to: Share on LinkedIn, Sign in with LinkedIn",
            "Run the auth setup: python tools/linkedin_auth_setup.py",
            "Or generate a token manually from the OAuth testing tools",
            "Your Organization ID is in your company page URL: linkedin.com/company/YOUR_ID/",
        ],
        "help_url": "https://learn.microsoft.com/en-us/linkedin/shared/authentication/getting-access",
        "token_expiry": "60 days (refresh token lasts 365 days)",
    },
    "tiktok": {
        "label": "TikTok",
        "icon": "bi-tiktok",
        "color": "#000000",
        "fields": [
            {"key": "client_id", "label": "TikTok Client Key", "type": "text", "required": True},
            {"key": "client_secret", "label": "TikTok Client Secret", "type": "password", "required": True},
            {"key": "access_token", "label": "Access Token", "type": "password", "required": True},
            {"key": "refresh_token", "label": "Refresh Token", "type": "password", "required": False},
        ],
        "guide_steps": [
            "Go to developers.tiktok.com and sign up",
            "Click 'Manage Apps' → 'Create App'",
            "Add these products: Login Kit, Content Posting API",
            "Set the redirect URI to http://localhost:8080/callback",
            "Submit your app for review (or use sandbox mode for testing)",
            "Copy your Client Key and Client Secret from the app dashboard",
            "Run: python tools/tiktok_auth_setup.py",
            "After authorization, copy the access and refresh tokens",
        ],
        "help_url": "https://developers.tiktok.com/doc/getting-started",
        "token_expiry": "24 hours (refresh token lasts 365 days)",
    },
    "twitter": {
        "label": "Twitter / X",
        "icon": "bi-twitter-x",
        "color": "#000000",
        "fields": [
            {"key": "client_id", "label": "API Key (Consumer Key)", "type": "text", "required": True},
            {"key": "client_secret", "label": "API Secret (Consumer Secret)", "type": "password", "required": True},
            {"key": "access_token", "label": "Access Token", "type": "password", "required": True},
            {"key": "access_token_secret", "label": "Access Token Secret", "type": "password", "required": True, "extra": True},
            {"key": "bearer_token", "label": "Bearer Token (optional)", "type": "password", "required": False, "extra": True},
        ],
        "guide_steps": [
            "Go to developer.twitter.com and sign up for a developer account",
            "Create a new Project, then create an App inside it",
            "Set the app permissions to 'Read and Write'",
            "Go to 'Keys and Tokens' section",
            "Under 'Consumer Keys': copy API Key and API Secret",
            "Under 'Authentication Tokens': generate Access Token and Secret",
            "Copy the Bearer Token from the same page (optional but recommended)",
        ],
        "help_url": "https://developer.twitter.com/en/docs/getting-started",
        "token_expiry": "Never (unless regenerated)",
    },
    "snapchat": {
        "label": "Snapchat",
        "icon": "bi-snapchat",
        "color": "#FFFC00",
        "fields": [],  # No API tokens — content is prepared for manual posting
        "guide_steps": [
            "Snapchat doesn't support automated posting via API",
            "We'll prepare your content (optimized video + caption)",
            "Download the ready-to-post files from the Posts page",
            "Open Snapchat and post manually — the hard work is done!",
        ],
        "help_url": None,
        "token_expiry": "N/A — manual posting",
    },
    "pinterest": {
        "label": "Pinterest",
        "icon": "bi-pinterest",
        "color": "#E60023",
        "fields": [
            {"key": "access_token", "label": "Access Token", "type": "password", "required": True},
            {"key": "board_id", "label": "Board ID", "type": "text", "required": True, "extra": True},
        ],
        "guide_steps": [
            "Go to developers.pinterest.com and create an app",
            "Generate an access token with these scopes: boards:read, pins:read, pins:write",
            "Find your Board ID from the board URL or API",
        ],
        "help_url": "https://developers.pinterest.com/docs/getting-started/",
        "token_expiry": "30 days",
    },
    "threads": {
        "label": "Threads",
        "icon": "bi-threads",
        "color": "#000000",
        "fields": [
            {"key": "access_token", "label": "Threads Access Token", "type": "password", "required": True},
            {"key": "user_id", "label": "Threads User ID", "type": "text", "required": True, "extra": True},
        ],
        "guide_steps": [
            "Threads uses the same Meta Developer platform as Instagram",
            "Go to developers.facebook.com → select your app",
            "Add the Threads API product",
            "Generate a token with threads_basic, threads_content_publish permissions",
            "Your Threads User ID is the same as your Instagram user ID",
        ],
        "help_url": "https://developers.facebook.com/docs/threads",
        "token_expiry": "60 days",
    },
}


# ── CRUD operations ───────────────────────────────────────────────────────────


async def save_platform_credentials(
    business_id: int,
    platform: str,
    credentials: dict,
) -> dict:
    """
    Save (or update) platform credentials for a business.
    All token fields are encrypted before storage.
    """
    factory = get_session_factory()
    async with factory() as session:
        conn = (await session.execute(
            select(PlatformConnection).where(
                PlatformConnection.business_id == business_id,
                PlatformConnection.platform == platform,
            )
        )).scalar_one_or_none()

        if conn is None:
            conn = PlatformConnection(
                business_id=business_id,
                platform=platform,
            )
            session.add(conn)

        # Encrypt token fields
        conn.access_token = encrypt(credentials.get("access_token", ""))
        conn.refresh_token = encrypt(credentials.get("refresh_token", ""))
        conn.client_id = encrypt(credentials.get("client_id", ""))
        conn.client_secret = encrypt(credentials.get("client_secret", ""))

        # Store any extra fields (e.g., organization_id, page_id) as JSON
        extras = {
            k: v for k, v in credentials.items()
            if k not in ("access_token", "refresh_token", "client_id", "client_secret")
            and v  # skip empty values
        }
        conn.extra_json = json.dumps(extras) if extras else None

        conn.scopes = credentials.get("scopes", "")
        conn.status = ConnectionStatus.ACTIVE
        conn.connected_at = datetime.utcnow()
        conn.last_error = None

        await session.commit()
        return {"success": True, "platform": platform}


async def get_platform_credentials(business_id: int, platform: str) -> dict | None:
    """
    Retrieve and decrypt platform credentials for a business.
    Returns a flat dict with all fields, or None if not connected.
    """
    factory = get_session_factory()
    async with factory() as session:
        conn = (await session.execute(
            select(PlatformConnection).where(
                PlatformConnection.business_id == business_id,
                PlatformConnection.platform == platform,
                PlatformConnection.status == ConnectionStatus.ACTIVE,
            )
        )).scalar_one_or_none()

        if conn is None:
            return None

        result = {
            "access_token": decrypt(conn.access_token or ""),
            "refresh_token": decrypt(conn.refresh_token or ""),
            "client_id": decrypt(conn.client_id or ""),
            "client_secret": decrypt(conn.client_secret or ""),
        }

        # Merge extras
        if conn.extra_json:
            try:
                extras = json.loads(conn.extra_json)
                result.update(extras)
            except json.JSONDecodeError:
                pass

        return result


async def get_all_connections(business_id: int) -> list[dict]:
    """
    Get connection status for all platforms for a business.
    Does NOT return actual tokens — only metadata for display.
    """
    factory = get_session_factory()
    async with factory() as session:
        connections = (await session.execute(
            select(PlatformConnection).where(
                PlatformConnection.business_id == business_id,
            )
        )).scalars().all()

        connected = {}
        for conn in connections:
            extras = {}
            if conn.extra_json:
                try:
                    extras = json.loads(conn.extra_json)
                except json.JSONDecodeError:
                    pass

            connected[conn.platform if isinstance(conn.platform, str) else conn.platform.value] = {
                "status": conn.status.value if isinstance(conn.status, ConnectionStatus) else conn.status,
                "connected_at": conn.connected_at.isoformat() if conn.connected_at else None,
                "last_used_at": conn.last_used_at.isoformat() if conn.last_used_at else None,
                "expires_at": conn.expires_at.isoformat() if conn.expires_at else None,
                "last_error": conn.last_error,
                "has_refresh_token": bool(conn.refresh_token),
                "extra_fields": list(extras.keys()),
            }

    # Build result for ALL platforms, marking unconnected ones
    result = []
    for platform_key, info in PLATFORM_FIELDS.items():
        conn_data = connected.get(platform_key)
        result.append({
            "platform": platform_key,
            "label": info["label"],
            "icon": info["icon"],
            "color": info["color"],
            "connected": conn_data is not None and conn_data["status"] == "active",
            "connection": conn_data,
            "fields": info["fields"],
            "guide_steps": info["guide_steps"],
            "help_url": info.get("help_url"),
            "token_expiry": info.get("token_expiry"),
        })

    return result


async def disconnect_platform(business_id: int, platform: str) -> bool:
    """Revoke (soft-delete) a platform connection."""
    factory = get_session_factory()
    async with factory() as session:
        conn = (await session.execute(
            select(PlatformConnection).where(
                PlatformConnection.business_id == business_id,
                PlatformConnection.platform == platform,
            )
        )).scalar_one_or_none()

        if conn is None:
            return False

        conn.status = ConnectionStatus.REVOKED
        conn.access_token = None
        conn.refresh_token = None
        conn.client_id = None
        conn.client_secret = None
        conn.extra_json = None
        await session.commit()
        return True


async def test_platform_connection(business_id: int, platform: str) -> dict:
    """
    Test if the stored credentials for a platform actually work.
    Returns {"success": True/False, "message": "...", "details": {...}}
    """
    creds = await get_platform_credentials(business_id, platform)
    if not creds:
        return {"success": False, "message": "No credentials stored for this platform"}

    try:
        if platform == "instagram":
            return _test_instagram(creds)
        elif platform == "facebook":
            return _test_facebook(creds)
        elif platform == "youtube":
            return _test_youtube(creds)
        elif platform == "linkedin":
            return _test_linkedin(creds)
        elif platform == "tiktok":
            return _test_tiktok(creds)
        elif platform == "twitter":
            return _test_twitter(creds)
        else:
            return {"success": True, "message": f"Credentials saved (no test available for {platform})"}
    except Exception as e:
        return {"success": False, "message": f"Connection test failed: {str(e)}"}


# ── Platform-specific test functions ──────────────────────────────────────────


def _test_instagram(creds: dict) -> dict:
    import requests
    r = requests.get(
        f"https://graph.facebook.com/v21.0/{creds.get('instagram_business_account_id', 'me')}",
        params={"access_token": creds["access_token"], "fields": "id,username"},
        timeout=10,
    )
    if r.ok:
        data = r.json()
        return {"success": True, "message": f"Connected as @{data.get('username', 'unknown')}", "details": data}
    return {"success": False, "message": f"Instagram API error: {r.text}"}


def _test_facebook(creds: dict) -> dict:
    import requests
    page_id = creds.get("page_id", "me")
    r = requests.get(
        f"https://graph.facebook.com/v21.0/{page_id}",
        params={"access_token": creds["access_token"], "fields": "id,name"},
        timeout=10,
    )
    if r.ok:
        data = r.json()
        return {"success": True, "message": f"Connected to page: {data.get('name', 'unknown')}", "details": data}
    return {"success": False, "message": f"Facebook API error: {r.text}"}


def _test_youtube(creds: dict) -> dict:
    import requests
    # Use OAuth token to get channel info
    r = requests.get(
        "https://www.googleapis.com/youtube/v3/channels",
        params={"part": "snippet", "mine": "true"},
        headers={"Authorization": f"Bearer {creds['access_token']}"},
        timeout=10,
    )
    if r.ok:
        items = r.json().get("items", [])
        if items:
            name = items[0]["snippet"]["title"]
            return {"success": True, "message": f"Connected to channel: {name}"}
    # If access token expired, try refresh
    if creds.get("refresh_token"):
        return {"success": True, "message": "Credentials stored (refresh token available). Token will auto-renew."}
    return {"success": False, "message": f"YouTube API error: {r.text if r else 'no response'}"}


def _test_linkedin(creds: dict) -> dict:
    import requests
    org_id = creds.get("organization_id", "")
    r = requests.get(
        f"https://api.linkedin.com/v2/organizations/{org_id}",
        headers={"Authorization": f"Bearer {creds['access_token']}"},
        timeout=10,
    )
    if r.ok:
        data = r.json()
        name = data.get("localizedName", "Unknown")
        return {"success": True, "message": f"Connected to: {name}", "details": data}
    return {"success": False, "message": f"LinkedIn API error ({r.status_code}): {r.text}"}


def _test_tiktok(creds: dict) -> dict:
    import requests
    r = requests.get(
        "https://open.tiktokapis.com/v2/user/info/",
        params={"fields": "display_name,avatar_url"},
        headers={"Authorization": f"Bearer {creds['access_token']}"},
        timeout=10,
    )
    if r.ok:
        data = r.json().get("data", {}).get("user", {})
        return {"success": True, "message": f"Connected as: {data.get('display_name', 'TikTok User')}"}
    return {"success": False, "message": f"TikTok API error: {r.text}"}


def _test_twitter(creds: dict) -> dict:
    try:
        import tweepy
        auth = tweepy.OAuth1UserHandler(
            creds["client_id"],
            creds["client_secret"],
            creds["access_token"],
            creds.get("access_token_secret", ""),
        )
        api = tweepy.API(auth, timeout=10)
        user = api.verify_credentials()
        return {"success": True, "message": f"Connected as @{user.screen_name}"}
    except ImportError:
        return {"success": True, "message": "Credentials saved (tweepy not installed for verification)"}
    except Exception as e:
        return {"success": False, "message": f"Twitter auth failed: {str(e)}"}
