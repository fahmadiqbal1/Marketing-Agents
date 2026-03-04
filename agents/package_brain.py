"""
Promotional Package Brain — proposes service bundles and seasonal promotions.

Marketing Psychology Applied:
- Charm Pricing: Rs 1,999 instead of Rs 2,000 (left-digit effect)
- Anchoring: Show original price first, then discounted (perceived savings)
- Decoy Effect: 3 tiers where middle tier is the obvious best value
- Loss Aversion: Frame as "don't miss" instead of "get this"
- Bundle Framing: "Save Rs X when you bundle" vs individual prices
"""

from __future__ import annotations

import json
from datetime import datetime

from openai import AsyncOpenAI
from pydantic import Field

from config.settings import get_settings
from agents.schemas import PackageProposal
from security.prompt_guard import sanitize_for_llm
from services.ai_usage import track_openai_usage


# Business context loaded dynamically from DB
SYSTEM_PROMPT = """You are the promotional strategist for the business.

The business's services and offerings will be provided in the user prompt.

Your task: Propose creative, medically appropriate promotional packages that combine
services in ways that make clinical sense and attract patients.

═══ PRICING PSYCHOLOGY (apply these to EVERY package) ═══

1. CHARM PRICING: End prices in 9 (Rs 1,999 not Rs 2,000) — left-digit effect
   increases perceived savings by ~20%

2. ANCHORING: Always show the "individual price" first (higher anchor),
   then the bundle price. "Individually Rs 5,000 → Bundle Rs 3,499"
   The anchor makes the bundle feel like an incredible deal.

3. DECOY EFFECT: When possible, propose 3 tiers:
   - Basic (stripped down, slightly too little)
   - Standard (sweet spot, best value — this is what most people pick)
   - Premium (everything, higher price makes Standard look reasonable)

4. LOSS AVERSION: Frame urgency as what they'll LOSE:
   "Don't let this month pass without checking your heart health"
   NOT "Get your heart checked this month"

5. BUNDLE FRAMING: Show "You save Rs X" explicitly.
   People respond to concrete savings more than percentages.

6. SCARCITY: Add ethical urgency — "Limited to 50 slots this month"
   or "Available this month only" (only when genuinely limited)

Guidelines:
- Packages must be medically logical (don't combine unrelated services randomly)
- Include seasonal/calendar awareness (health awareness days, seasons, holidays)
- Target specific demographics (women, men, seniors, corporate employees, brides/grooms)
- Apply Charm Pricing to all price suggestions
- Apply Anchoring by showing individual vs bundle price
- Content ideas should use psychology triggers (hooks, FOMO, social proof)
- Always prioritize patient safety in messaging

Example good combinations:
- "Heart Health Package" = ECG + Echo + Lipid Profile blood test
- "Summer Glow Bundle" = Hydrafacial + Full Body Laser Hair Removal
- "Executive Health Checkup" = Full blood panel + ECG + X-ray + consultation
- "Pre-Wedding Glow" = Hydrafacial x3 sessions + Laser Hair Removal
- "Women's Wellness" = thyroid panel + CBC + pelvic ultrasound + consultation"""


async def generate_package_proposals(
    current_month: int | None = None,
    recent_categories: list[str] | None = None,
    custom_context: str = "",
) -> list[PackageProposal]:
    """Generate 2-3 promotional package proposals based on timing and context."""
    settings = get_settings()
    client = AsyncOpenAI(api_key=settings.openai_api_key)

    now = datetime.now()
    month = current_month or now.month
    month_name = now.strftime("%B")

    # Build context about what health days/seasons are relevant
    health_calendar = _get_health_calendar(month)

    user_prompt = f"""Today's date: {now.strftime('%B %d, %Y')}
Month: {month_name}
Relevant health awareness events this month: {health_calendar}
{"Recent content categories (avoid over-promoting these): " + ", ".join(recent_categories) if recent_categories else ""}
{"Additional context: " + sanitize_for_llm(custom_context) if custom_context else ""}

Propose 2-3 promotional packages for the business that are timely and appealing.
For each package, provide:
- name: catchy package name
- tagline: one-liner marketing hook
- services_included: list of specific services in this bundle
- discount_details: what discount or value-add to offer
- target_audience: who this package is for
- occasion: what event/season this ties to (if any)
- suggested_price: ballpark price suggestion
- content_ideas: 3-4 specific social media post ideas to promote this

Return a JSON array of package objects."""

    response = await client.chat.completions.create(
        model="gpt-4o-mini",
        messages=[
            {"role": "system", "content": SYSTEM_PROMPT},
            {"role": "user", "content": user_prompt},
        ],
        response_format={"type": "json_object"},
        temperature=0.8,  # Creative proposals
        max_tokens=2000,
    )

    try:
        await track_openai_usage(response, 0, "package_brain", "generate_package_proposals")
    except Exception:
        pass

    data = json.loads(response.choices[0].message.content)
    packages_raw = data.get("packages", data if isinstance(data, list) else [data])

    if isinstance(packages_raw, dict):
        # Sometimes the model returns {"packages": [...]}
        packages_raw = packages_raw.get("packages", [packages_raw])

    proposals = []
    for p in packages_raw:
        try:
            proposals.append(PackageProposal(**p))
        except Exception:
            continue

    return proposals


def _get_health_calendar(month: int) -> str:
    """Return notable health awareness events for a given month."""
    calendar = {
        1: "Cervical Cancer Awareness Month, Thyroid Awareness Month",
        2: "Heart Health Month, Cancer Prevention Month",
        3: "Kidney Health Month, World Kidney Day (2nd Thu), Nutrition Week",
        4: "World Health Day (Apr 7), Autism Awareness, Stress Awareness Month",
        5: "World Asthma Day, Mental Health Awareness Month, Skin Cancer Awareness",
        6: "Men's Health Month, World Blood Donor Day (Jun 14), Cancer Survivors Day",
        7: "UV Safety Month, Hepatitis Awareness, World Population Day",
        8: "Immunization Awareness, World Breastfeeding Week",
        9: "Blood Cancer Awareness, World Heart Day (Sep 29), PCOS Awareness",
        10: "Breast Cancer Awareness Month, World Mental Health Day (Oct 10)",
        11: "Diabetes Awareness Month, Lung Cancer Awareness, Movember",
        12: "AIDS Awareness, Handwashing Day, Universal Health Coverage Day",
    }
    return calendar.get(month, "General wellness promotion")
