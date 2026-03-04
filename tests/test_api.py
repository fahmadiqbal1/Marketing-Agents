"""Comprehensive API endpoint test."""
import requests

BASE = "http://127.0.0.1:8001"
OK = "\u2705"
FAIL = "\u274c"

def test(method, path, expected_status=200, json_body=None, headers=None, label=None):
    url = f"{BASE}{path}"
    try:
        r = getattr(requests, method.lower())(url, json=json_body, headers=headers, timeout=10)
        symbol = OK if r.status_code == expected_status else FAIL
        detail = ""
        if r.status_code != expected_status:
            detail = f" (body: {r.text[:120]})"
        print(f"  {symbol} {method.upper()} {path} -> {r.status_code}{detail}")
        return r
    except Exception as e:
        print(f"  {FAIL} {method.upper()} {path} -> ERROR: {e}")
        return None

# ── Public endpoints ──
print("\n=== Public Endpoints ===")
test("GET", "/api/health")
test("GET", "/api/plans")
test("GET", "/api/docs")

# ── Auth ──
print("\n=== Auth ===")
test("POST", "/api/auth/register", json_body={"name": "Test2", "email": "test2@example.com", "password": "StrongPass1!", "business_name": "Corp2"})
login = test("POST", "/api/auth/login", json_body={"email": "test@example.com", "password": "TestPass123!"})

if login and login.status_code == 200:
    token = login.json()["token"]
    auth = {"Authorization": f"Bearer {token}"}

    # ── Authenticated endpoints ──
    print("\n=== Usage & Billing ===")
    test("GET", "/api/usage/summary", headers=auth)
    test("GET", "/api/usage/limits", headers=auth)
    test("GET", "/api/billing/history", headers=auth)
    test("POST", "/api/billing/request-credit", json_body={"reason": "Need shared keys"}, headers=auth)

    print("\n=== Bot Personality ===")
    test("GET", "/api/bot/personality", headers=auth)
    test("PUT", "/api/bot/personality", json_body={"persona_name": "test_bot", "tone": "friendly", "response_style": "concise"}, headers=auth)
    test("GET", "/api/bot/personality", headers=auth)
    test("POST", "/api/bot/train", json_body={"question": "What is your name?", "answer": "I am the marketing bot."}, headers=auth)
    test("POST", "/api/bot/test-response", json_body={"message": "Hello there"}, headers=auth)

    print("\n=== Input Validation ===")
    # Short password
    test("POST", "/api/auth/register", expected_status=422, json_body={"name": "X", "email": "x@x.com", "password": "short", "business_name": "X"})
    # Long message
    test("POST", "/api/ai-assistant", expected_status=422, json_body={"message": "x" * 2001}, headers=auth)
    # Invalid URL for training (route is /api/agents/train-from-repo)
    test("POST", "/api/agents/train-from-repo", expected_status=422, json_body={"url": "http://evil.com/repo", "platform": "instagram"}, headers=auth)

    print("\n=== Captions & Calendar ===")
    test("POST", "/api/captions/ab-test", json_body={"content_description": "summer sale photo", "content_category": "promotional", "count": 2}, headers=auth)
    test("POST", "/api/calendar/auto-fill", json_body={"days_ahead": 3}, headers=auth)

    print("\n=== Rate Limiting ===")
    # Exceed auth rate limit (30/60s) - we won't fully test this, just verify header
    r = requests.get(f"{BASE}/api/usage/summary", headers=auth)
    rl = r.headers.get("X-RateLimit-Remaining", "N/A")
    print(f"  Rate-Limit-Remaining header: {rl}")

    print("\n=== 401 without token ===")
    test("GET", "/api/usage/summary", expected_status=401)
    test("GET", "/api/billing/history", expected_status=401)

print("\n=== DONE ===")
