"""
LinkedIn API Setup Helper

This script helps you:
1. Generate the authorization URL
2. Run a local callback server to capture the auth code
3. Exchange the code for an access token
4. Find your Organization ID

Usage:
    python tools/linkedin_auth_setup.py
"""

from __future__ import annotations

import sys
import json
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
                b"<html><body><h1>&#10004; LinkedIn Authorization Successful!</h1>"
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
        pass  # Suppress server logs


def get_access_token(client_id: str, client_secret: str, code: str) -> dict:
    """Exchange authorization code for access token."""
    resp = requests.post(
        "https://www.linkedin.com/oauth/v2/accessToken",
        data={
            "grant_type": "authorization_code",
            "code": code,
            "redirect_uri": REDIRECT_URI,
            "client_id": client_id,
            "client_secret": client_secret,
        },
    )
    return resp.json()


def get_organization_id(access_token: str) -> str:
    """Find the LinkedIn Organization (Company Page) ID."""
    headers = {"Authorization": f"Bearer {access_token}"}

    resp = requests.get(
        "https://api.linkedin.com/v2/organizationAcls?q=roleAssignee",
        headers=headers,
    )
    data = resp.json()

    elements = data.get("elements", [])
    if not elements:
        print("\n⚠️  No LinkedIn Organization Pages found for this account.")
        print("   Create a Company Page first on LinkedIn.")
        return ""

    print("\n📄 Your LinkedIn Organization Pages:")
    org_ids = []
    for i, elem in enumerate(elements):
        org_urn = elem.get("organization", "")
        org_id = org_urn.split(":")[-1] if org_urn else ""
        org_ids.append(org_id)

        # Try to get org name
        org_resp = requests.get(
            f"https://api.linkedin.com/v2/organizations/{org_id}",
            headers=headers,
        )
        org_data = org_resp.json()
        name = org_data.get("localizedName", "Unknown")
        print(f"  [{i + 1}] {name} (ID: {org_id})")

    if len(org_ids) == 1:
        return org_ids[0]

    choice = int(input("\nSelect organization number: ")) - 1
    return org_ids[choice]


def main():
    print("=" * 60)
    print("  LINKEDIN API SETUP")
    print("=" * 60)

    print("\n📋 STEP 1: Create a LinkedIn Developer App")
    print("─" * 50)
    print("1. Go to: https://www.linkedin.com/developers/apps")
    print("2. Click 'Create App'")
    print("3. App name: 'Your Business Marketing'")
    print("4. LinkedIn Page: Select your company's LinkedIn Page")
    print("5. App logo: Upload your business logo or any image")
    print("6. Check the legal agreement → Create")
    print()
    print("📋 STEP 2: Configure the App")
    print("─" * 50)
    print("1. In your app → Products tab")
    print("   • Request 'Share on LinkedIn'")
    print("   • Request 'Sign In with LinkedIn using OpenID Connect'")
    print("2. Go to Auth tab:")
    print(f"   • Add Redirect URL: {REDIRECT_URI}")
    print("   • Copy Client ID and Client Secret")
    print()

    client_id = input("Enter LinkedIn Client ID: ").strip()
    client_secret = input("Enter LinkedIn Client Secret: ").strip()

    if not client_id or not client_secret:
        print("❌ Client ID and Secret are required. Exiting.")
        return

    # Build authorization URL
    scopes = "w_member_social%20w_organization_social%20r_organization_social%20openid%20profile"
    auth_url = (
        f"https://www.linkedin.com/oauth/v2/authorization?"
        f"response_type=code&client_id={client_id}&"
        f"redirect_uri={REDIRECT_URI}&scope={scopes}"
    )

    # Start callback server
    server = HTTPServer(("localhost", 8080), CallbackHandler)
    server_thread = threading.Thread(target=server.handle_request, daemon=True)
    server_thread.start()

    print(f"\n🌐 Opening LinkedIn authorization page...")
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
    token_data = get_access_token(client_id, client_secret, code)

    access_token = token_data.get("access_token")
    if not access_token:
        print(f"❌ Error: {token_data}")
        return

    expires_in = token_data.get("expires_in", 0)
    days = expires_in // 86400
    print(f"✅ Access token obtained! (expires in {days} days)")

    # Get Organization ID
    print("\n🔄 Finding your LinkedIn Organization...")
    org_id = get_organization_id(access_token)

    # Summary
    print("\n" + "=" * 60)
    print("  ✅ SETUP COMPLETE — Add these to config/.env")
    print("=" * 60)
    print(f"\nLINKEDIN_CLIENT_ID={client_id}")
    print(f"LINKEDIN_CLIENT_SECRET={client_secret}")
    print(f"LINKEDIN_ACCESS_TOKEN={access_token}")
    if org_id:
        print(f"LINKEDIN_ORGANIZATION_ID={org_id}")
    print()

    # Ask to auto-update .env
    update = input("Auto-update your .env file? (y/n): ").strip().lower()
    if update == "y":
        env_path = Path(__file__).resolve().parent.parent / "config" / ".env"
        content = env_path.read_text(encoding="utf-8")
        content = content.replace("LINKEDIN_CLIENT_ID=", f"LINKEDIN_CLIENT_ID={client_id}")
        content = content.replace("LINKEDIN_CLIENT_SECRET=", f"LINKEDIN_CLIENT_SECRET={client_secret}")
        content = content.replace("LINKEDIN_ACCESS_TOKEN=", f"LINKEDIN_ACCESS_TOKEN={access_token}")
        if org_id:
            content = content.replace("LINKEDIN_ORGANIZATION_ID=", f"LINKEDIN_ORGANIZATION_ID={org_id}")
        content = content.replace("LINKEDIN_ORGANIZATION_ID=\n", f"LINKEDIN_ORGANIZATION_ID={org_id}\n")
        env_path.write_text(content, encoding="utf-8")
        print("✅ .env file updated!")
    else:
        print("Copy the values above and paste them into config/.env manually.")


if __name__ == "__main__":
    main()
