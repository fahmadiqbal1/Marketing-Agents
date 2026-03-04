"""
Twitter/X API Setup Helper

Twitter uses OAuth 1.0a with API Key + Secret + Access Token + Access Token Secret.
These are all available directly from the developer portal — no OAuth flow needed!

Usage:
    python tools/twitter_auth_setup.py
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))


def main():
    print("=" * 60)
    print("  TWITTER / X API SETUP")
    print("=" * 60)

    print("\n📋 STEP 1: Create a Twitter Developer Account")
    print("─" * 50)
    print("1. Go to: https://developer.x.com/en/portal/dashboard")
    print("2. Sign in with your Twitter/X account")
    print("3. If new: Apply for a Developer account (Free tier is fine)")
    print("   • Use case: 'Building a social media management tool'")
    print("   • It's usually approved instantly for Free tier")
    print()

    print("📋 STEP 2: Create a Project & App")
    print("─" * 50)
    print("1. In the Developer Portal → Projects & Apps")
    print("2. Create a new Project: 'Your Business Marketing'")
    print("3. Create a new App inside it: 'Your Business Marketing Bot'")
    print("4. IMPORTANT: Set App permissions to 'Read and Write'")
    print("   (Default is Read-only — you need Write to post tweets)")
    print("   Go to: App Settings → User authentication settings → Edit")
    print("   • App permissions: Read and write")
    print("   • Type of App: Web App, Automated App or Bot")
    print("   • Callback URL: http://localhost:8080/callback")
    print("   • Website URL: https://www.yourbusiness.com")
    print()

    print("📋 STEP 3: Generate API Keys")
    print("─" * 50)
    print("1. In your App → Keys and tokens tab")
    print("2. Under 'Consumer Keys':")
    print("   • Click 'Regenerate' → Copy API Key and API Secret")
    print("3. Under 'Authentication Tokens':")
    print("   • Click 'Generate' → Copy Access Token and Access Token Secret")
    print("4. Under 'Bearer Token':")
    print("   • Click 'Generate' → Copy Bearer Token")
    print()
    print("⚠️  IMPORTANT: After setting permissions to 'Read and Write',")
    print("   you MUST regenerate the Access Token & Secret!")
    print()

    api_key = input("Enter API Key (Consumer Key): ").strip()
    api_secret = input("Enter API Secret (Consumer Secret): ").strip()
    access_token = input("Enter Access Token: ").strip()
    access_secret = input("Enter Access Token Secret: ").strip()
    bearer_token = input("Enter Bearer Token (optional, press Enter to skip): ").strip()

    if not all([api_key, api_secret, access_token, access_secret]):
        print("❌ All 4 credentials are required (Bearer Token is optional). Exiting.")
        return

    # Verify credentials
    print("\n🔄 Verifying credentials...")
    try:
        import tweepy

        client = tweepy.Client(
            consumer_key=api_key,
            consumer_secret=api_secret,
            access_token=access_token,
            access_token_secret=access_secret,
        )
        me = client.get_me()
        if me and me.data:
            print(f"✅ Authenticated as: @{me.data.username} ({me.data.name})")
        else:
            print("⚠️  Could not verify credentials, but they may still work.")
    except Exception as e:
        print(f"⚠️  Verification failed: {e}")
        print("   The credentials may still work — try posting a test tweet.")

    # Summary
    print("\n" + "=" * 60)
    print("  ✅ SETUP COMPLETE — Add these to config/.env")
    print("=" * 60)
    print(f"\nTWITTER_API_KEY={api_key}")
    print(f"TWITTER_API_SECRET={api_secret}")
    print(f"TWITTER_ACCESS_TOKEN={access_token}")
    print(f"TWITTER_ACCESS_TOKEN_SECRET={access_secret}")
    if bearer_token:
        print(f"TWITTER_BEARER_TOKEN={bearer_token}")
    print()

    # Ask to auto-update .env
    update = input("Auto-update your .env file? (y/n): ").strip().lower()
    if update == "y":
        env_path = Path(__file__).resolve().parent.parent / "config" / ".env"
        content = env_path.read_text(encoding="utf-8")
        content = content.replace("TWITTER_API_KEY=", f"TWITTER_API_KEY={api_key}")
        content = content.replace("TWITTER_API_SECRET=", f"TWITTER_API_SECRET={api_secret}")
        content = content.replace("TWITTER_ACCESS_TOKEN=", f"TWITTER_ACCESS_TOKEN={access_token}")
        content = content.replace("TWITTER_ACCESS_TOKEN_SECRET=", f"TWITTER_ACCESS_TOKEN_SECRET={access_secret}")
        if bearer_token:
            content = content.replace("TWITTER_BEARER_TOKEN=", f"TWITTER_BEARER_TOKEN={bearer_token}")
        env_path.write_text(content, encoding="utf-8")
        print("✅ .env file updated!")
    else:
        print("Copy the values above and paste them into config/.env manually.")


if __name__ == "__main__":
    main()
