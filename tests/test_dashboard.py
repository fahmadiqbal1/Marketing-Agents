"""Test Laravel dashboard login flow end-to-end."""
import re
import requests

BASE = "http://127.0.0.1:8000"
API = "http://127.0.0.1:8001"

s = requests.Session()

# 1. Get login page — need CSRF token
print("1. Loading login page...")
r = s.get(f"{BASE}/login")
print(f"   GET /login: {r.status_code}")
csrf = ""
m = re.search(r'name="_token"\s+value="([^"]+)"', r.text)
if m:
    csrf = m.group(1)
    print(f"   CSRF token: {csrf[:20]}...")
else:
    print("   WARNING: No CSRF token found")

# 2. Login via Laravel
print("\n2. Logging in...")
r = s.post(f"{BASE}/login", data={
    "email": "test@example.com",
    "password": "TestPass123!",
    "_token": csrf,
}, allow_redirects=False)
print(f"   POST /login: {r.status_code}")
if r.status_code in (302, 301):
    location = r.headers.get("Location", "")
    print(f"   Redirect to: {location}")

# 3. Follow redirect to dashboard
print("\n3. Loading dashboard...")
r = s.get(f"{BASE}/")
print(f"   GET /: {r.status_code}")
title = re.search(r"<title>(.*?)</title>", r.text)
if title:
    print(f"   Title: {title.group(1).strip()}")

if "Sign in" in r.text or "login" in r.url:
    print("   FAIL: Still on login page")
else:
    print("   OK: Dashboard loaded")

body_snippet = r.text[:500].replace("\n", " ").strip()
print(f"   Body preview: {body_snippet[:200]}")

# 4. Test protected pages
print("\n4. Testing protected pages...")
for page in ["/upload", "/posts", "/analytics", "/platforms", "/billing", "/bot-training", "/calendar"]:
    r = s.get(f"{BASE}{page}", allow_redirects=False)
    status = r.status_code
    if status == 302:
        dest = r.headers.get("Location", "")
        tag = "-> login" if "login" in dest else f"-> {dest}"
    elif status == 200:
        tag = "OK"
    else:
        tag = f"Error"
    print(f"   GET {page}: {status} {tag}")

# 5. Test API proxy endpoints
print("\n5. Testing proxy endpoints...")
for path in ["/api/calendar", "/api/posts/scheduled", "/api/notifications/recent"]:
    r = s.get(f"{BASE}{path}")
    print(f"   GET {path}: {r.status_code}")

print("\nDashboard test complete.")
