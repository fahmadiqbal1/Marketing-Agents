"""
Quick TikTok sandbox OAuth flow using ngrok tunnel.

TikTok requires a publicly reachable redirect URI — ngrok tunnels
localhost:8080 to an https:// URL that TikTok can redirect to.

IMPORTANT: After starting, copy the ngrok redirect URL shown in the
terminal and add it to your TikTok Developer App's redirect URIs
*before* clicking the authorization link.

Usage:
    python tools/_tiktok_sandbox_auth.py
"""

import webbrowser, threading, requests, time, sys
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs, quote
from pathlib import Path

from pyngrok import ngrok, conf

# ── Ngrok auth token ────────────────────────────────────────────────────
NGROK_AUTH_TOKEN = "3ANhfa3BueBhmNTmhFjrcfah0tR_7Qwudg82X9rvp7KEYxhBt"

# ── TikTok sandbox credentials ──────────────────────────────────────────
CLIENT_KEY = "sbawsvljw5k8ux9dpl"
CLIENT_SECRET = "dvoCjHrmtGazkFrqkV25jGpTzz6c74XV"

LOCAL_PORT = 8080
result = {"code": None}


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        params = parse_qs(urlparse(self.path).query)
        if "code" in params:
            result["code"] = params["code"][0]
            self.send_response(200)
            self.send_header("Content-Type", "text/html")
            self.end_headers()
            self.wfile.write(
                b"<html><body style='font-family:sans-serif;text-align:center;padding:60px'>"
                b"<h1>&#10004; TikTok Authorization Successful!</h1>"
                b"<p>You can close this window and return to the terminal.</p>"
                b"</body></html>"
            )
        else:
            self.send_response(400)
            self.end_headers()
            err = params.get("error_description", ["Unknown"])[0]
            self.wfile.write(f"<h1>Error: {err}</h1>".encode())

    def log_message(self, fmt, *args):
        pass


def main():
    # ── 1. Configure and start ngrok tunnel ──────────────────────────────
    print("=" * 60)
    print("  TIKTOK SANDBOX AUTH (via ngrok)")
    print("=" * 60)

    conf.get_default().auth_token = NGROK_AUTH_TOKEN
    print(f"\nStarting ngrok tunnel on port {LOCAL_PORT}...")

    try:
        tunnel = ngrok.connect(LOCAL_PORT, "http")
    except Exception as e:
        print(f"ERROR starting ngrok: {e}")
        print("Make sure ngrok is installed and the auth token is valid.")
        sys.exit(1)

    public_url = tunnel.public_url
    if public_url.startswith("http://"):
        public_url = public_url.replace("http://", "https://", 1)

    redirect_uri = f"{public_url}/callback"

    print(f"\n  Ngrok tunnel active:")
    print(f"  Public URL : {public_url}")
    print(f"  Redirect URI: {redirect_uri}")
    print()
    print("=" * 60)
    print("  IMPORTANT — ADD THIS REDIRECT URI TO YOUR TIKTOK APP")
    print("=" * 60)
    print(f"\n  1. Go to https://developers.tiktok.com/apps/")
    print(f"  2. Open your sandbox app")
    print(f"  3. Under 'Platform settings', add this redirect URI:")
    print(f"\n     {redirect_uri}\n")
    input("  Press ENTER after you've added it... ")

    # ── 2. Start local callback server ───────────────────────────────────
    server = HTTPServer(("0.0.0.0", LOCAL_PORT), Handler)
    t = threading.Thread(target=server.handle_request, daemon=True)
    t.start()
    time.sleep(1)

    # ── 3. Build auth URL and open browser ───────────────────────────────
    scopes = "user.info.basic,user.info.profile,user.info.stats,video.list,video.publish,video.upload"
    auth_url = (
        f"https://www.tiktok.com/v2/auth/authorize/?"
        f"client_key={CLIENT_KEY}&scope={scopes}&"
        f"response_type=code&redirect_uri={quote(redirect_uri, safe='')}"
    )

    print("\nOpening browser for TikTok authorization...")
    print(f"If browser doesn't open, visit:\n{auth_url}\n")
    webbrowser.open(auth_url)

    print("Waiting for authorization (up to 3 min)...")
    t.join(timeout=180)
    server.server_close()

    code = result["code"]
    if not code:
        print("\nERROR: No auth code received. Timed out or denied.")
        ngrok.disconnect(tunnel.public_url)
        sys.exit(1)

    print(f"\nAuth code received!")

    # ── 4. Exchange code for access token ────────────────────────────────
    print("Exchanging for access token...")

    resp = requests.post(
        "https://open.tiktokapis.com/v2/oauth/token/",
        data={
            "client_key": CLIENT_KEY,
            "client_secret": CLIENT_SECRET,
            "code": code,
            "grant_type": "authorization_code",
            "redirect_uri": redirect_uri,
        },
        headers={"Content-Type": "application/x-www-form-urlencoded"},
    )

    data = resp.json()
    token = data.get("access_token")

    if token:
        expires = data.get("expires_in", 0)
        refresh = data.get("refresh_token", "")
        open_id = data.get("open_id", "")

        print(f"\nSUCCESS! Token expires in {expires // 86400}d {(expires % 86400) // 3600}h")
        if open_id:
            print(f"Open ID: {open_id}")

        # Save to .env
        env_path = Path(__file__).resolve().parent.parent / "config" / ".env"
        content = env_path.read_text(encoding="utf-8")

        # Replace only the value part (handle both empty and existing values)
        import re
        content = re.sub(r"TIKTOK_ACCESS_TOKEN=.*", f"TIKTOK_ACCESS_TOKEN={token}", content)
        if refresh:
            if "TIKTOK_REFRESH_TOKEN=" in content:
                content = re.sub(r"TIKTOK_REFRESH_TOKEN=.*", f"TIKTOK_REFRESH_TOKEN={refresh}", content)
            else:
                content = content.replace(
                    f"TIKTOK_ACCESS_TOKEN={token}",
                    f"TIKTOK_ACCESS_TOKEN={token}\nTIKTOK_REFRESH_TOKEN={refresh}",
                )

        env_path.write_text(content, encoding="utf-8")
        print("Access token saved to config/.env!")

        if refresh:
            print(f"Refresh token also saved.")
    else:
        print(f"\nFAILED: {data}")

    # ── 5. Cleanup ───────────────────────────────────────────────────────
    ngrok.disconnect(tunnel.public_url)
    print("\nNgrok tunnel closed. Done!")


if __name__ == "__main__":
    main()
