"""
Google OAuth2 setup helper — run this once to authenticate with Google
for YouTube uploads and Google Calendar access.

Usage:
    python tools/google_auth_setup.py
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))


SCOPES = [
    "https://www.googleapis.com/auth/youtube.upload",
    "https://www.googleapis.com/auth/youtube.readonly",
    "https://www.googleapis.com/auth/calendar",
    "https://www.googleapis.com/auth/calendar.events",
]


def setup_google_oauth():
    """Run the OAuth2 flow and save credentials."""
    from google_auth_oauthlib.flow import InstalledAppFlow
    from config.settings import get_settings, PROJECT_ROOT

    settings = get_settings()

    if not settings.google_client_id or not settings.google_client_secret:
        print("ERROR: Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in your .env file first.")
        print("Get these from: https://console.cloud.google.com/apis/credentials")
        return

    # Create client config from env vars
    client_config = {
        "installed": {
            "client_id": settings.google_client_id,
            "client_secret": settings.google_client_secret,
            "redirect_uris": ["http://localhost:8080/"],
            "auth_uri": "https://accounts.google.com/o/oauth2/auth",
            "token_uri": "https://oauth2.googleapis.com/token",
        }
    }

    flow = InstalledAppFlow.from_client_config(client_config, SCOPES)
    credentials = flow.run_local_server(port=8080)

    # Save credentials
    creds_dir = PROJECT_ROOT / "config" / "credentials"
    creds_dir.mkdir(parents=True, exist_ok=True)
    token_path = creds_dir / "google_token.json"

    token_path.write_text(credentials.to_json())
    print(f"\n✅ Google credentials saved to: {token_path}")
    print("You can now use YouTube uploads and Google Calendar features.")


if __name__ == "__main__":
    setup_google_oauth()
