"""
Publisher Agent — posts content to all social media platforms.
Each platform has its own publisher function with API-specific logic.
All publish functions accept a `credentials` dict (from per-tenant storage)
instead of reading global settings.
"""

from __future__ import annotations

import json
import logging
import time
from pathlib import Path
from typing import Optional

import httpx
import requests

from config.settings import get_settings

logger = logging.getLogger(__name__)


# =============================================================================
# INSTAGRAM (via Meta Graph API — requires Business Account + Facebook Page)
# =============================================================================

async def publish_to_instagram(
    file_path: str,
    caption: str,
    hashtags: list[str],
    media_type: str = "photo",
    credentials: dict | None = None,
) -> dict:
    """
    Publish to Instagram via the Graph API.
    Flow: upload media → create container → publish container.
    """
    if not credentials:
        # Fallback to legacy settings
        settings = get_settings()
        credentials = {
            "access_token": settings.meta_page_access_token,
            "instagram_business_account_id": settings.instagram_business_account_id,
        }
    base_url = "https://graph.facebook.com/v19.0"
    account_id = credentials.get("instagram_business_account_id", "")
    token = credentials.get("access_token", "")

    full_caption = f"{caption}\n\n{'  '.join('#' + h for h in hashtags)}"

    if media_type == "photo":
        # Step 1: Create media container
        resp = requests.post(
            f"{base_url}/{account_id}/media",
            data={
                "image_url": file_path,  # Must be a publicly accessible URL
                "caption": full_caption,
                "access_token": token,
            },
        )
        container = resp.json()
        container_id = container.get("id")

        if not container_id:
            return {"success": False, "error": container}

    elif media_type == "video":
        # Upload video as Reel
        resp = requests.post(
            f"{base_url}/{account_id}/media",
            data={
                "media_type": "REELS",
                "video_url": file_path,  # Must be publicly accessible
                "caption": full_caption,
                "access_token": token,
            },
        )
        container = resp.json()
        container_id = container.get("id")

        if not container_id:
            return {"success": False, "error": container}

        # Wait for video processing
        for _ in range(30):
            status_resp = requests.get(
                f"{base_url}/{container_id}",
                params={"fields": "status_code", "access_token": token},
            )
            status = status_resp.json().get("status_code")
            if status == "FINISHED":
                break
            time.sleep(5)

    # Step 2: Publish
    pub_resp = requests.post(
        f"{base_url}/{account_id}/media_publish",
        data={
            "creation_id": container_id,
            "access_token": token,
        },
    )
    result = pub_resp.json()

    return {
        "success": "id" in result,
        "post_id": result.get("id"),
        "platform": "instagram",
    }


# =============================================================================
# FACEBOOK (via Graph API — Page posting only)
# =============================================================================

async def publish_to_facebook(
    file_path: str,
    caption: str,
    hashtags: list[str],
    media_type: str = "photo",
    credentials: dict | None = None,
) -> dict:
    """Publish to a Facebook Page."""
    if not credentials:
        settings = get_settings()
        credentials = {"access_token": settings.meta_page_access_token}
    base_url = "https://graph.facebook.com/v19.0"
    token = credentials.get("access_token", "")

    full_caption = f"{caption}\n\n{'  '.join('#' + h for h in hashtags)}"

    # Get page ID from token
    me_resp = requests.get(
        f"{base_url}/me", params={"access_token": token, "fields": "id"}
    )
    page_id = me_resp.json().get("id")

    if media_type == "photo":
        with open(file_path, "rb") as f:
            resp = requests.post(
                f"{base_url}/{page_id}/photos",
                data={"message": full_caption, "access_token": token},
                files={"source": f},
            )
    else:
        with open(file_path, "rb") as f:
            resp = requests.post(
                f"{base_url}/{page_id}/videos",
                data={"description": full_caption, "access_token": token},
                files={"source": f},
            )

    result = resp.json()
    post_id = result.get("id") or result.get("post_id")

    return {
        "success": post_id is not None,
        "post_id": post_id,
        "platform": "facebook",
    }


# =============================================================================
# YOUTUBE (via YouTube Data API v3 — OAuth2 required)
# =============================================================================

async def publish_to_youtube(
    file_path: str,
    title: str,
    description: str,
    tags: list[str],
    is_short: bool = False,
    privacy: str = "private",  # Start private, change after review
    credentials: dict | None = None,
) -> dict:
    """Upload a video to YouTube."""
    try:
        from googleapiclient.discovery import build
        from googleapiclient.http import MediaFileUpload
        from google.oauth2.credentials import Credentials

        # Load stored credentials — prefer tenant-specific, fallback to file
        if credentials and credentials.get("refresh_token"):
            creds = Credentials(
                token=credentials.get("access_token"),
                refresh_token=credentials.get("refresh_token"),
                client_id=credentials.get("client_id"),
                client_secret=credentials.get("client_secret"),
                token_uri="https://oauth2.googleapis.com/token",
            )
        else:
            creds = _load_google_credentials()
        if not creds:
            return {"success": False, "error": "Google OAuth credentials not found"}

        youtube = build("youtube", "v3", credentials=creds)

        if is_short:
            title = f"{title} #Shorts" if "#Shorts" not in title else title

        body = {
            "snippet": {
                "title": title[:100],
                "description": description[:5000],
                "tags": tags[:30],
                "categoryId": "26",  # How-to & Style (good for healthcare)
            },
            "status": {
                "privacyStatus": privacy,
                "selfDeclaredMadeForKids": False,
            },
        }

        media = MediaFileUpload(
            file_path,
            mimetype="video/mp4",
            resumable=True,
            chunksize=1024 * 1024,  # 1MB chunks
        )

        request = youtube.videos().insert(
            part="snippet,status",
            body=body,
            media_body=media,
        )

        response = None
        while response is None:
            _, response = request.next_chunk()

        video_id = response.get("id")

        return {
            "success": True,
            "post_id": video_id,
            "url": f"https://youtube.com/watch?v={video_id}",
            "platform": "youtube",
        }

    except Exception as e:
        return {"success": False, "error": str(e), "platform": "youtube"}


# =============================================================================
# LINKEDIN (via Community Management API)
# =============================================================================

async def publish_to_linkedin(
    file_path: str,
    caption: str,
    hashtags: list[str],
    media_type: str = "photo",
    credentials: dict | None = None,
) -> dict:
    """Publish to LinkedIn organization page."""
    if not credentials:
        settings = get_settings()
        credentials = {
            "access_token": settings.linkedin_access_token,
            "organization_id": settings.linkedin_organization_id,
        }
    token = credentials.get("access_token", "")
    org_id = credentials.get("organization_id", "")

    headers = {
        "Authorization": f"Bearer {token}",
        "Content-Type": "application/json",
        "X-Restli-Protocol-Version": "2.0.0",
    }

    full_text = f"{caption}\n\n{'  '.join('#' + h for h in hashtags)}"

    try:
        if media_type == "photo":
            # Step 1: Register upload
            register_body = {
                "registerUploadRequest": {
                    "recipes": ["urn:li:digitalmediaRecipe:feedshare-image"],
                    "owner": f"urn:li:organization:{org_id}",
                    "serviceRelationships": [
                        {
                            "relationshipType": "OWNER",
                            "identifier": "urn:li:userGeneratedContent",
                        }
                    ],
                }
            }

            reg_resp = requests.post(
                "https://api.linkedin.com/v2/assets?action=registerUpload",
                headers=headers,
                json=register_body,
            )
            reg_data = reg_resp.json()

            upload_url = reg_data["value"]["uploadMechanism"][
                "com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest"
            ]["uploadUrl"]
            asset = reg_data["value"]["asset"]

            # Step 2: Upload binary
            with open(file_path, "rb") as f:
                requests.put(
                    upload_url,
                    headers={"Authorization": f"Bearer {token}"},
                    data=f,
                )

            # Step 3: Create post
            post_body = {
                "author": f"urn:li:organization:{org_id}",
                "lifecycleState": "PUBLISHED",
                "specificContent": {
                    "com.linkedin.ugc.ShareContent": {
                        "shareCommentary": {"text": full_text},
                        "shareMediaCategory": "IMAGE",
                        "media": [
                            {
                                "status": "READY",
                                "media": asset,
                            }
                        ],
                    }
                },
                "visibility": {
                    "com.linkedin.ugc.MemberNetworkVisibility": "PUBLIC"
                },
            }

            post_resp = requests.post(
                "https://api.linkedin.com/v2/ugcPosts",
                headers=headers,
                json=post_body,
            )

            result = post_resp.json()
            return {
                "success": post_resp.status_code == 201,
                "post_id": result.get("id"),
                "platform": "linkedin",
            }

        else:
            # Video upload is similar but uses video recipe
            # Simplified: post as text with video reference
            post_body = {
                "author": f"urn:li:organization:{org_id}",
                "lifecycleState": "PUBLISHED",
                "specificContent": {
                    "com.linkedin.ugc.ShareContent": {
                        "shareCommentary": {"text": full_text},
                        "shareMediaCategory": "NONE",
                    }
                },
                "visibility": {
                    "com.linkedin.ugc.MemberNetworkVisibility": "PUBLIC"
                },
            }

            post_resp = requests.post(
                "https://api.linkedin.com/v2/ugcPosts",
                headers=headers,
                json=post_body,
            )

            result = post_resp.json()
            return {
                "success": post_resp.status_code == 201,
                "post_id": result.get("id"),
                "platform": "linkedin",
            }

    except Exception as e:
        return {"success": False, "error": str(e), "platform": "linkedin"}


# =============================================================================
# TIKTOK (via Content Posting API)
# =============================================================================

async def publish_to_tiktok(
    file_path: str,
    caption: str,
    hashtags: list[str],
    publish_to_inbox: bool = True,  # Safer — sends to drafts
    credentials: dict | None = None,
) -> dict:
    """
    Upload video to TikTok.
    By default publishes to inbox (drafts) — user manually publishes from app.
    Direct publish requires extra API approval.
    """
    if not credentials:
        settings = get_settings()
        credentials = {"access_token": settings.tiktok_access_token}
    token = credentials.get("access_token", "")

    full_caption = f"{caption} {'  '.join('#' + h for h in hashtags)}"

    headers = {
        "Authorization": f"Bearer {token}",
        "Content-Type": "application/json",
    }

    try:
        file_size = Path(file_path).stat().st_size

        # Step 1: Initialize upload
        init_body = {
            "post_info": {
                "title": full_caption[:2200],
                "privacy_level": "PUBLIC_TO_EVERYONE",
                "disable_duet": False,
                "disable_comment": False,
                "disable_stitch": False,
            },
            "source_info": {
                "source": "FILE_UPLOAD",
                "video_size": file_size,
                "chunk_size": file_size,  # Single chunk for small files
                "total_chunk_count": 1,
            },
        }

        init_resp = requests.post(
            "https://open.tiktokapis.com/v2/post/publish/inbox/video/init/"
            if publish_to_inbox
            else "https://open.tiktokapis.com/v2/post/publish/video/init/",
            headers=headers,
            json=init_body,
        )
        init_data = init_resp.json()

        publish_id = init_data.get("data", {}).get("publish_id")
        upload_url = init_data.get("data", {}).get("upload_url")

        if not upload_url:
            return {"success": False, "error": init_data, "platform": "tiktok"}

        # Step 2: Upload video
        with open(file_path, "rb") as f:
            upload_resp = requests.put(
                upload_url,
                headers={
                    "Content-Range": f"bytes 0-{file_size - 1}/{file_size}",
                    "Content-Type": "video/mp4",
                },
                data=f,
            )

        return {
            "success": upload_resp.status_code in (200, 201),
            "publish_id": publish_id,
            "platform": "tiktok",
            "note": "Published to inbox/drafts" if publish_to_inbox else "Published directly",
        }

    except Exception as e:
        return {"success": False, "error": str(e), "platform": "tiktok"}


# =============================================================================
# TWITTER / X (via API v2 — OAuth 1.0a User Context)
# =============================================================================

async def publish_to_twitter(
    file_path: str,
    caption: str,
    hashtags: list[str],
    media_type: str = "photo",
    credentials: dict | None = None,
) -> dict:
    """
    Publish to Twitter/X using tweepy (API v2 with v1.1 media upload).
    """
    if not credentials:
        settings = get_settings()
        credentials = {
            "client_id": settings.twitter_api_key,
            "client_secret": settings.twitter_api_secret,
            "access_token": settings.twitter_access_token,
            "access_token_secret": settings.twitter_access_token_secret,
        }

    try:
        import tweepy

        api_key = credentials.get("client_id", "")
        api_secret = credentials.get("client_secret", "")
        access_token = credentials.get("access_token", "")
        access_token_secret = credentials.get("access_token_secret", "")

        # OAuth 1.0a for media upload + tweet creation
        auth = tweepy.OAuth1UserHandler(
            api_key, api_secret, access_token, access_token_secret,
        )

        # v1.1 API for media upload
        api_v1 = tweepy.API(auth)
        # v2 client for tweet creation
        client = tweepy.Client(
            consumer_key=api_key,
            consumer_secret=api_secret,
            access_token=access_token,
            access_token_secret=access_token_secret,
        )

        full_text = f"{caption}\n\n{'  '.join('#' + h for h in hashtags)}"
        # Twitter limit: 280 chars
        if len(full_text) > 280:
            # Trim caption, keep hashtags
            hashtag_str = '  '.join('#' + h for h in hashtags)
            max_caption = 280 - len(hashtag_str) - 5  # space for \n\n and ...
            full_text = f"{caption[:max_caption]}...\n\n{hashtag_str}"

        # Upload media
        media_ids = []
        if file_path and Path(file_path).exists():
            if media_type == "video":
                media = api_v1.media_upload(
                    file_path,
                    media_category="tweet_video",
                    chunked=True,
                )
            else:
                media = api_v1.media_upload(file_path)
            media_ids = [media.media_id]

        # Create tweet
        response = client.create_tweet(
            text=full_text,
            media_ids=media_ids if media_ids else None,
        )

        tweet_id = response.data["id"]
        return {
            "success": True,
            "post_id": tweet_id,
            "url": f"https://x.com/i/status/{tweet_id}",
            "platform": "twitter",
        }

    except Exception as e:
        return {"success": False, "error": str(e), "platform": "twitter"}


# =============================================================================
# SNAPCHAT (manual — prepare content only)
# =============================================================================

async def prepare_for_snapchat(
    file_path: str,
    caption: str,
) -> dict:
    """
    Snapchat has no organic posting API.
    Save the prepared content to the snapchat_ready folder.
    User posts manually.
    """
    from tools.media_utils import snapchat_ready_path, generate_filename

    src = Path(file_path)
    dest = snapchat_ready_path() / generate_filename(src.name, prefix="snap")

    # Copy file
    import shutil
    shutil.copy2(str(src), str(dest))

    # Save caption as companion text file
    caption_file = dest.with_suffix(".txt")
    caption_file.write_text(caption, encoding="utf-8")

    return {
        "success": True,
        "file_path": str(dest),
        "caption_file": str(caption_file),
        "platform": "snapchat",
        "note": "Content saved to snapchat_ready folder. Please post manually.",
    }


# =============================================================================
# Unified publisher
# =============================================================================

async def publish_to_platform(
    platform: str,
    file_path: str,
    caption: str,
    hashtags: list[str],
    media_type: str = "photo",
    title: str = "",
    description: str = "",
    media_item_id: int | None = None,
    business_id: int | None = None,
) -> dict:
    """Route to the correct platform publisher, with pre-publish safety gate."""

    # ── Security: pre-publish safety gate ──────────────────────────────
    from security.publish_gate import PublishGate, compute_file_hash
    from security.audit_log import audit, send_critical_alert, AuditEvent, Severity

    gate = PublishGate()
    decision = await gate.check(
        file_path=file_path,
        caption=caption,
        media_item_id=media_item_id,
    )

    await audit(
        event=AuditEvent.PUBLISH_GATE_PASS if decision.cleared else AuditEvent.PUBLISH_GATE_BLOCK,
        severity=Severity.INFO if decision.cleared else Severity.HIGH,
        actor="publisher",
        details={
            "platform": platform,
            "cleared": decision.cleared,
            "blocked_reasons": decision.blocked_reasons,
            "warnings": decision.warnings,
        },
        related_id=media_item_id,
    )

    if not decision.cleared:
        await send_critical_alert(
            AuditEvent.PUBLISH_GATE_BLOCK,
            f"Publish BLOCKED for {platform}: {', '.join(decision.blocked_reasons)}",
        )
        return {
            "success": False,
            "error": "Blocked by publish safety gate",
            "reasons": decision.blocked_reasons,
            "platform": platform,
        }

    # Store file hash for integrity tracking
    try:
        file_hash = compute_file_hash(file_path)
        if media_item_id:
            from memory.database import get_session
            from sqlalchemy import text

            async with get_session() as session:
                await session.execute(
                    text("UPDATE posts SET file_hash = :h WHERE id = :id"),
                    {"h": file_hash, "id": media_item_id},
                )
                await session.commit()
    except Exception:
        pass  # Non-blocking — hash storage is best-effort

    # ── Fetch per-tenant credentials ────────────────────────────────────
    platform_creds = None
    if business_id and platform != "snapchat":
        try:
            from memory.credentials import get_platform_credentials
            platform_creds = await get_platform_credentials(business_id, platform)
        except Exception as e:
            logger.warning(f"Could not load tenant credentials for {platform}: {e}")

    # ── Route to platform publisher ────────────────────────────────────
    publishers = {
        "instagram": lambda: publish_to_instagram(file_path, caption, hashtags, media_type, credentials=platform_creds),
        "facebook": lambda: publish_to_facebook(file_path, caption, hashtags, media_type, credentials=platform_creds),
        "youtube": lambda: publish_to_youtube(
            file_path, title or caption[:100], description or caption, hashtags,
            is_short=(media_type == "video"), credentials=platform_creds,
        ),
        "linkedin": lambda: publish_to_linkedin(file_path, caption, hashtags, media_type, credentials=platform_creds),
        "tiktok": lambda: publish_to_tiktok(file_path, caption, hashtags, credentials=platform_creds),
        "twitter": lambda: publish_to_twitter(file_path, caption, hashtags, media_type, credentials=platform_creds),
        "snapchat": lambda: prepare_for_snapchat(file_path, caption),
    }

    publisher = publishers.get(platform)
    if not publisher:
        return {"success": False, "error": f"Unknown platform: {platform}"}

    try:
        result = await publisher()

        # Audit successful publish
        if result.get("success"):
            await audit(
                event=AuditEvent.POST_PUBLISHED,
                severity=Severity.INFO,
                actor="publisher",
                details={"platform": platform, "post_id": result.get("post_id")},
                related_id=media_item_id,
            )

        return result
    except Exception as e:
        return {"success": False, "error": str(e), "platform": platform}


# =============================================================================
# Google credentials helper
# =============================================================================

def _load_google_credentials():
    """Load Google OAuth2 credentials from stored token."""
    from config.settings import PROJECT_ROOT

    token_path = PROJECT_ROOT / "config" / "credentials" / "google_token.json"
    if not token_path.exists():
        return None

    try:
        from google.oauth2.credentials import Credentials
        from google.auth.transport.requests import Request

        creds = Credentials.from_authorized_user_file(
            str(token_path),
            scopes=[
                "https://www.googleapis.com/auth/youtube.upload",
                "https://www.googleapis.com/auth/calendar",
            ],
        )

        if creds.expired and creds.refresh_token:
            creds.refresh(Request())
            token_path.write_text(creds.to_json())

        return creds
    except Exception:
        return None
