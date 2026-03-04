"""Full system test including rate limiter bypass."""
import requests

BASE = "http://127.0.0.1:8001"
OK = "\u2705"
FAIL = "\u274c"

# ── Public ──
r = requests.get(f"{BASE}/api/health")
print(f"  {OK if r.status_code==200 else FAIL} Health: {r.status_code} - {r.json()['service']}")

r = requests.get(f"{BASE}/api/plans")
print(f"  {OK if r.status_code==200 else FAIL} Plans: {r.status_code} - {len(r.json()['plans'])} plans")

# ── Auth ──
r = requests.post(f"{BASE}/api/auth/login", json={"email": "test@example.com", "password": "TestPass123!"})
assert r.status_code == 200
token = r.json()["token"]
auth = {"Authorization": f"Bearer {token}"}
bid = r.json()["user"]["business_id"]
print(f"  {OK} Login: bid={bid} (owner)")

# ── Authenticated endpoints ──
for ep in ["/api/usage/summary", "/api/usage/limits", "/api/billing/history", "/api/bot/personality"]:
    r = requests.get(f"{BASE}{ep}", headers=auth)
    print(f"  {OK if r.status_code==200 else FAIL} GET {ep}: {r.status_code}")

# ── Rate limiter bypass test ──
# Owner bid=1 should NEVER get 429, even with rapid requests
statuses = []
for i in range(35):
    r = requests.get(f"{BASE}/api/usage/summary", headers=auth, timeout=10)
    statuses.append(r.status_code)
got_429 = 429 in statuses
if not got_429:
    print(f"  {OK} Rate limiter bypass: 35 rapid owner calls -> no 429 (bypassed correctly)")
else:
    print(f"  {FAIL} Rate limiter bypass: owner got blocked at request {statuses.index(429)+1}")

# ── Non-owner rate limit should still apply ──
# Register a new user (business_id=2) and test they ARE limited
r = requests.post(f"{BASE}/api/auth/register", json={
    "name": "Tenant User", "email": "tenant_test@example.com",
    "password": "TenantPass1!", "business_name": "Other Corp"
})
if r.status_code == 200 and r.json().get("success"):
    tenant_token = r.json()["token"]
    tenant_auth = {"Authorization": f"Bearer {tenant_token}"}
    tenant_bid = r.json()["user"]["business_id"]
    print(f"  {OK} Tenant registered: bid={tenant_bid}")
    # Non-owner: default tier is 120/60s so won't easily hit, but at least verify it works
    r2 = requests.get(f"{BASE}/api/usage/summary", headers=tenant_auth)
    print(f"  {OK if r2.status_code==200 else FAIL} Tenant GET /api/usage/summary: {r2.status_code}")
else:
    print(f"  -- Tenant already exists (skip rate limit test for non-owner)")

# ── Unauthed ──
r = requests.get(f"{BASE}/api/usage/summary")
print(f"  {OK if r.status_code==401 else FAIL} Unauthed: {r.status_code}")

# ── Input validation ──
r = requests.post(f"{BASE}/api/auth/register", json={"name": "X", "email": "x@x.com", "password": "short", "business_name": "X"})
print(f"  {OK if r.status_code==422 else FAIL} Short password rejected: {r.status_code}")

print("\n  All tests complete.")
