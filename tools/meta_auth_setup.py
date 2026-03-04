"""
Meta (Facebook + Instagram) API Setup Helper

This script helps you:
1. Exchange a short-lived token for a long-lived token
2. Get your Page Access Token (never-expiring)
3. Find your Instagram Business Account ID

Usage:
    python tools/meta_auth_setup.py
"""

from __future__ import annotations

import sys
import webbrowser
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import requests
from config.settings import get_settings


def get_long_lived_token(app_id: str, app_secret: str, short_token: str) -> str:
    """Exchange short-lived user token for a long-lived one (60 days)."""
    resp = requests.get(
        "https://graph.facebook.com/v19.0/oauth/access_token",
        params={
            "grant_type": "fb_exchange_token",
            "client_id": app_id,
            "client_secret": app_secret,
            "fb_exchange_token": short_token,
        },
    )
    data = resp.json()
    if "access_token" in data:
        print(f"\n✅ Long-lived user token obtained (expires in ~60 days)")
        return data["access_token"]
    else:
        print(f"\n❌ Error: {data}")
        return ""


def get_page_token(long_lived_user_token: str) -> tuple[str, str]:
    """Get a never-expiring Page Access Token."""
    resp = requests.get(
        "https://graph.facebook.com/v19.0/me/accounts",
        params={"access_token": long_lived_user_token},
    )
    data = resp.json()

    if "data" not in data:
        print(f"\n❌ Error getting pages: {data}")
        return "", ""

    pages = data["data"]
    if not pages:
        print("\n❌ No Facebook Pages found. Create one first!")
        return "", ""

    print("\n📄 Your Facebook Pages:")
    for i, page in enumerate(pages):
        print(f"  [{i + 1}] {page['name']} (ID: {page['id']})")

    if len(pages) == 1:
        choice = 0
    else:
        choice = int(input("\nSelect page number: ")) - 1

    selected = pages[choice]
    page_token = selected["access_token"]
    page_id = selected["id"]

    print(f"\n✅ Never-expiring Page Access Token for '{selected['name']}'")
    return page_id, page_token


def get_instagram_account_id(page_id: str, page_token: str) -> str:
    """Get the Instagram Business Account ID linked to the Facebook Page."""
    resp = requests.get(
        f"https://graph.facebook.com/v19.0/{page_id}",
        params={
            "fields": "instagram_business_account",
            "access_token": page_token,
        },
    )
    data = resp.json()

    ig = data.get("instagram_business_account", {})
    ig_id = ig.get("id", "")

    if ig_id:
        print(f"\n✅ Instagram Business Account ID: {ig_id}")
    else:
        print("\n⚠️  No Instagram Business Account linked to this page.")
        print("    Go to Instagram → Settings → Account → Switch to Business → Link Facebook Page")

    return ig_id


def main():
    settings = get_settings()

    print("=" * 60)
    print("  META (FACEBOOK + INSTAGRAM) API SETUP")
    print("=" * 60)

    # Check if app credentials exist
    app_id = settings.meta_app_id
    app_secret = settings.meta_app_secret

    if not app_id or not app_secret:
        print("\n📋 STEP 1: Create a Meta Developer App")
        print("─" * 50)
        print("1. Go to: https://developers.facebook.com/")
        print("2. Sign in with your Facebook account")
        print("3. Click 'Create App' → Select 'Business' type")
        print("4. App name: 'Your Business Marketing Bot'")
        print("5. In App Dashboard → Add Products:")
        print("   • Instagram Graph API → Set Up")
        print("   • Facebook Login for Business → Set Up")
        print("6. Go to Settings → Basic:")
        print("   • Copy 'App ID' and 'App Secret'")
        print()
        app_id = input("Enter your Meta App ID: ").strip()
        app_secret = input("Enter your Meta App Secret: ").strip()

        if not app_id or not app_secret:
            print("❌ App ID and Secret are required. Exiting.")
            return

        print(f"\n📝 Add these to your config/.env:")
        print(f"   META_APP_ID={app_id}")
        print(f"   META_APP_SECRET={app_secret}")

    print("\n📋 STEP 2: Get a Short-Lived User Token")
    print("─" * 50)
    print("1. Go to: https://developers.facebook.com/tools/explorer/")
    print(f"2. Select your app (App ID: {app_id})")
    print("3. Click 'Generate Access Token'")
    print("4. Grant ALL these permissions:")
    print("   • pages_show_list")
    print("   • pages_read_engagement")
    print("   • pages_manage_posts")
    print("   • pages_manage_metadata")
    print("   • instagram_basic")
    print("   • instagram_content_publish")
    print("   • instagram_manage_comments")
    print("   • instagram_manage_insights")
    print("5. Copy the access token shown")

    # Open Graph Explorer
    webbrowser.open("https://developers.facebook.com/tools/explorer/")

    short_token = input("\nPaste your short-lived access token here: ").strip()
    if not short_token:
        print("❌ Token is required. Exiting.")
        return

    # Exchange for long-lived token
    print("\n🔄 Exchanging for long-lived token...")
    long_token = get_long_lived_token(app_id, app_secret, short_token)
    if not long_token:
        return

    # Get page token (never expiring)
    print("\n🔄 Getting never-expiring Page Access Token...")
    page_id, page_token = get_page_token(long_token)
    if not page_token:
        return

    # Get Instagram Business Account ID
    print("\n🔄 Finding Instagram Business Account...")
    ig_id = get_instagram_account_id(page_id, page_token)

    # Summary
    print("\n" + "=" * 60)
    print("  ✅ SETUP COMPLETE — Add these to config/.env")
    print("=" * 60)
    print(f"\nMETA_APP_ID={app_id}")
    print(f"META_APP_SECRET={app_secret}")
    print(f"META_PAGE_ACCESS_TOKEN={page_token}")
    if ig_id:
        print(f"INSTAGRAM_BUSINESS_ACCOUNT_ID={ig_id}")
    print()

    # Ask to auto-update .env
    update = input("Auto-update your .env file? (y/n): ").strip().lower()
    if update == "y":
        env_path = Path(__file__).resolve().parent.parent / "config" / ".env"
        content = env_path.read_text(encoding="utf-8")
        content = content.replace("META_APP_ID=", f"META_APP_ID={app_id}")
        content = content.replace("META_APP_SECRET=", f"META_APP_SECRET={app_secret}")
        content = content.replace("META_PAGE_ACCESS_TOKEN=", f"META_PAGE_ACCESS_TOKEN={page_token}")
        if ig_id:
            content = content.replace("INSTAGRAM_BUSINESS_ACCOUNT_ID=", f"INSTAGRAM_BUSINESS_ACCOUNT_ID={ig_id}")
        env_path.write_text(content, encoding="utf-8")
        print("✅ .env file updated!")
    else:
        print("Copy the values above and paste them into config/.env manually.")


if __name__ == "__main__":
    main()
