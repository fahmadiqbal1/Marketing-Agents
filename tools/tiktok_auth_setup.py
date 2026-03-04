"""
TikTok API Setup Helper

This script helps you:
1. Generate the authorization URL
2. Capture the auth code via callback
3. Exchange for an access token

Usage:
    python tools/tiktok_auth_setup.py
"""

from __future__ import annotations

import sys
import os
import hashlib
import base64
import secrets
import webbrowser
import threading
from pathlib import Path
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import requests

REDIRECT_URI = "http://localhost:8080/callback"
auth_code_result = {"code": None}


class CallbackHandler(BaseHTTPRequestHandler):
    """HTTP handler that captures the OAuth callback."""

    def do_GET(self):
        parsed = urlparse(self.path)
        params = parse_qs(parsed.query)

        if "code" in params:
            auth_code_result["code"] = params["code"][0]
            self.send_response(200)
            self.send_header("Content-Type", "text/html")
            self.end_headers()
            self.wfile.write(
                b"<html><body><h1>&#10004; TikTok Authorization Successful!</h1>"
                b"<p>You can close this window and return to the terminal.</p></body></html>"
            )
        elif "error" in params:
            self.send_response(400)
            self.send_header("Content-Type", "text/html")
            self.end_headers()
            error = params.get("error_description", ["Unknown error"])[0]
            self.wfile.write(f"<html><body><h1>Error: {error}</h1></body></html>".encode())
        else:
            self.send_response(404)
            self.end_headers()

    def log_message(self, format, *args):
        pass


def main():
    print("=" * 60)
    print("  TIKTOK API SETUP")
    print("=" * 60)

    print("\n📋 STEP 1: Create a TikTok Developer App")
    print("─" * 50)
    print("1. Go to: https://developers.tiktok.com/")
    print("2. Sign in with your TikTok account")
    print("3. Click 'Manage apps' → 'Connect an app' or 'Create'")
    print("4. App name: 'Your Business Marketing'")
    print("5. App icon: Upload your business logo")
    print("6. Category: Healthcare")
    print("7. In your app → Add Products:")
    print("   • Content Posting API → Select")
    print("   • Login Kit → Select")
    print(f"8. Set Redirect URL: {REDIRECT_URI}")
    print("9. Submit for review (or use Sandbox mode first)")
    print("10. Go to app details → copy Client Key and Client Secret")
    print()

    client_key = input("Enter TikTok Client Key: ").strip()
    client_secret = input("Enter TikTok Client Secret: ").strip()

    if not client_key or not client_secret:
        print("❌ Client Key and Secret are required. Exiting.")
        return

    # Generate PKCE code_verifier and code_challenge
    code_verifier = secrets.token_urlsafe(64)[:128]
    code_challenge = base64.urlsafe_b64encode(
        hashlib.sha256(code_verifier.encode("ascii")).digest()
    ).rstrip(b"=").decode("ascii")

    # Build authorization URL
    scopes = "user.info.basic,user.info.profile,user.info.stats,video.list,video.publish,video.upload"
    auth_url = (
        f"https://www.tiktok.com/v2/auth/authorize/?"
        f"client_key={client_key}&"
        f"scope={scopes}&"
        f"response_type=code&"
        f"redirect_uri={REDIRECT_URI}&"
        f"code_challenge={code_challenge}&"
        f"code_challenge_method=S256"
    )

    # Start callback server
    server = HTTPServer(("localhost", 8080), CallbackHandler)
    server_thread = threading.Thread(target=server.handle_request, daemon=True)
    server_thread.start()

    print(f"\n🌐 Opening TikTok authorization page...")
    print(f"   (If it doesn't open, visit this URL manually:)")
    print(f"   {auth_url}")
    webbrowser.open(auth_url)

    print("\n⏳ Waiting for authorization... (approve in your browser)")
    server_thread.join(timeout=120)
    server.server_close()

    code = auth_code_result["code"]
    if not code:
        print("❌ No authorization code received. Timed out or user denied.")
        return

    print(f"\n✅ Authorization code received!")

    # Exchange for access token
    print("🔄 Exchanging for access token...")
    resp = requests.post(
        "https://open.tiktokapis.com/v2/oauth/token/",
        data={
            "client_key": client_key,
            "client_secret": client_secret,
            "code": code,
            "grant_type": "authorization_code",
            "redirect_uri": REDIRECT_URI,
            "code_verifier": code_verifier,
        },
        headers={"Content-Type": "application/x-www-form-urlencoded"},
    )
    token_data = resp.json()

    access_token = token_data.get("access_token")
    if not access_token:
        print(f"❌ Error: {token_data}")
        return

    expires_in = token_data.get("expires_in", 0)
    days = expires_in // 86400
    hours = (expires_in % 86400) // 3600
    print(f"✅ Access token obtained! (expires in {days}d {hours}h)")

    refresh_token = token_data.get("refresh_token", "")
    if refresh_token:
        print(f"   Refresh token also saved (for renewal)")

    # Summary
    print("\n" + "=" * 60)
    print("  ✅ SETUP COMPLETE — Add these to config/.env")
    print("=" * 60)
    print(f"\nTIKTOK_CLIENT_KEY={client_key}")
    print(f"TIKTOK_CLIENT_SECRET={client_secret}")
    print(f"TIKTOK_ACCESS_TOKEN={access_token}")
    print()

    # Ask to auto-update .env
    update = input("Auto-update your .env file? (y/n): ").strip().lower()
    if update == "y":
        env_path = Path(__file__).resolve().parent.parent / "config" / ".env"
        content = env_path.read_text(encoding="utf-8")
        content = content.replace("TIKTOK_CLIENT_KEY=", f"TIKTOK_CLIENT_KEY={client_key}")
        content = content.replace("TIKTOK_CLIENT_SECRET=", f"TIKTOK_CLIENT_SECRET={client_secret}")
        content = content.replace("TIKTOK_ACCESS_TOKEN=", f"TIKTOK_ACCESS_TOKEN={access_token}")
        env_path.write_text(content, encoding="utf-8")
        print("✅ .env file updated!")
    else:
        print("Copy the values above and paste them into config/.env manually.")


if __name__ == "__main__":
    main()
