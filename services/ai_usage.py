"""
AI Usage Tracker — meters every OpenAI / Gemini API call per tenant.

Tracks token counts, estimates cost, enforces plan quotas, and logs
usage to the `ai_usage_log` table for billing.

Pricing (as of 2025):
  GPT-4o-mini:   $0.15 / 1M input tokens,  $0.60 / 1M output tokens
  Gemini Flash:  $0.075 / 1M input tokens,  $0.30 / 1M output tokens
"""

from __future__ import annotations

import logging
from datetime import datetime, timedelta
from typing import Optional

logger = logging.getLogger(__name__)

# ── Pricing per million tokens ────────────────────────────────────────────────

MODEL_PRICING: dict[str, dict[str, float]] = {
    "gpt-4o-mini": {"input": 0.15, "output": 0.60},
    "gpt-4o": {"input": 2.50, "output": 10.00},
    "gemini-2.0-flash": {"input": 0.075, "output": 0.30},
    "gemini-1.5-flash": {"input": 0.075, "output": 0.30},
    "omni-moderation-latest": {"input": 0.0, "output": 0.0},  # Free
}

# ── Default plan limits (monthly tokens) ──────────────────────────────────────

PLAN_LIMITS: dict[str, int] = {
    "free": 100_000,
    "starter": 1_000_000,
    "pro": 5_000_000,
    "enterprise": 50_000_000,
}


def _estimate_cost(model: str, input_tokens: int, output_tokens: int) -> float:
    """Estimate USD cost for a single API call."""
    pricing = MODEL_PRICING.get(model, MODEL_PRICING["gpt-4o-mini"])
    cost = (input_tokens * pricing["input"] + output_tokens * pricing["output"]) / 1_000_000
    return round(cost, 6)


async def track_openai_usage(
    response,
    business_id: int,
    agent_name: str,
    operation: str,
    model: str = "gpt-4o-mini",
    user_id: int = 0,
) -> dict:
    """
    Extract token usage from an OpenAI chat completion response and log it.

    Args:
        response: The OpenAI ChatCompletion response object
        business_id: Tenant identifier
        agent_name: Which agent made the call (e.g. 'instagram_agent', 'seo_agent')
        operation: What the call was for (e.g. 'generate_caption', 'screen_resume')
        model: Model name used
        user_id: Optional user who triggered the operation

    Returns:
        Dict with token counts and cost
    """
    usage = getattr(response, "usage", None)
    if usage is None:
        return {"input_tokens": 0, "output_tokens": 0, "cost_usd": 0.0}

    input_tokens = getattr(usage, "prompt_tokens", 0) or 0
    output_tokens = getattr(usage, "completion_tokens", 0) or 0
    total_tokens = input_tokens + output_tokens
    cost_usd = _estimate_cost(model, input_tokens, output_tokens)

    # Log to database
    try:
        await _log_usage(
            business_id=business_id,
            user_id=user_id,
            agent_name=agent_name,
            model=model,
            operation=operation,
            input_tokens=input_tokens,
            output_tokens=output_tokens,
            cost_usd=cost_usd,
        )
    except Exception as e:
        logger.warning("Failed to log AI usage: %s", e)

    return {
        "input_tokens": input_tokens,
        "output_tokens": output_tokens,
        "total_tokens": total_tokens,
        "cost_usd": cost_usd,
    }


async def track_gemini_usage(
    response,
    business_id: int,
    agent_name: str,
    operation: str,
    model: str = "gemini-2.0-flash",
    user_id: int = 0,
) -> dict:
    """
    Extract token usage from a Gemini GenerateContentResponse and log it.

    Gemini response has `usage_metadata` with `prompt_token_count` and
    `candidates_token_count`.
    """
    usage = getattr(response, "usage_metadata", None)
    if usage is None:
        return {"input_tokens": 0, "output_tokens": 0, "cost_usd": 0.0}

    input_tokens = getattr(usage, "prompt_token_count", 0) or 0
    output_tokens = getattr(usage, "candidates_token_count", 0) or 0
    total_tokens = input_tokens + output_tokens
    cost_usd = _estimate_cost(model, input_tokens, output_tokens)

    try:
        await _log_usage(
            business_id=business_id,
            user_id=user_id,
            agent_name=agent_name,
            model=model,
            operation=operation,
            input_tokens=input_tokens,
            output_tokens=output_tokens,
            cost_usd=cost_usd,
        )
    except Exception as e:
        logger.warning("Failed to log Gemini usage: %s", e)

    return {
        "input_tokens": input_tokens,
        "output_tokens": output_tokens,
        "total_tokens": total_tokens,
        "cost_usd": cost_usd,
    }


async def _log_usage(
    business_id: int,
    user_id: int,
    agent_name: str,
    model: str,
    operation: str,
    input_tokens: int,
    output_tokens: int,
    cost_usd: float,
):
    """Persist a usage record to the database."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    session_factory = get_session_factory()
    async with session_factory() as session:
        await session.execute(
            text(
                "INSERT INTO ai_usage_log "
                "(business_id, user_id, agent_name, model, operation, "
                "input_tokens, output_tokens, estimated_cost_usd, created_at) "
                "VALUES (:bid, :uid, :agent, :model, :op, :inp, :out, :cost, :now)"
            ),
            {
                "bid": business_id,
                "uid": user_id,
                "agent": agent_name,
                "model": model,
                "op": operation,
                "inp": input_tokens,
                "out": output_tokens,
                "cost": cost_usd,
                "now": datetime.utcnow(),
            },
        )
        await session.commit()


async def check_quota(business_id: int) -> dict:
    """
    Check if a business has remaining AI quota for the current billing period.

    Returns:
        {
            "allowed": bool,
            "plan": str,
            "tokens_used": int,
            "tokens_limit": int,
            "tokens_remaining": int,
            "cost_usd": float,
            "uses_platform_keys": bool,
        }
    """
    from memory.database import get_session_factory
    from sqlalchemy import text

    session_factory = get_session_factory()
    async with session_factory() as session:
        # Get business plan
        result = await session.execute(
            text("SELECT subscription_plan, uses_platform_api_keys FROM businesses WHERE id = :bid"),
            {"bid": business_id},
        )
        row = result.fetchone()
        if not row:
            return {"allowed": False, "plan": "none", "tokens_used": 0,
                    "tokens_limit": 0, "tokens_remaining": 0, "cost_usd": 0.0,
                    "uses_platform_keys": False}

        plan = row[0] or "free"
        uses_platform_keys = bool(row[1]) if row[1] is not None else True

        # Get current month usage
        month_start = datetime.utcnow().replace(day=1, hour=0, minute=0, second=0, microsecond=0)
        usage_result = await session.execute(
            text(
                "SELECT COALESCE(SUM(input_tokens + output_tokens), 0), "
                "COALESCE(SUM(estimated_cost_usd), 0) "
                "FROM ai_usage_log "
                "WHERE business_id = :bid AND created_at >= :start"
            ),
            {"bid": business_id, "start": month_start},
        )
        usage_row = usage_result.fetchone()
        tokens_used = int(usage_row[0])
        cost_usd = float(usage_row[1])

        limit = PLAN_LIMITS.get(plan, PLAN_LIMITS["free"])
        remaining = max(0, limit - tokens_used)

        return {
            "allowed": tokens_used < limit,
            "plan": plan,
            "tokens_used": tokens_used,
            "tokens_limit": limit,
            "tokens_remaining": remaining,
            "cost_usd": cost_usd,
            "uses_platform_keys": uses_platform_keys,
        }


async def get_usage_summary(business_id: int, days: int = 30) -> dict:
    """Get usage summary broken down by agent and model."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    session_factory = get_session_factory()
    since = datetime.utcnow() - timedelta(days=days)

    async with session_factory() as session:
        # By agent
        agent_result = await session.execute(
            text(
                "SELECT agent_name, model, "
                "SUM(input_tokens) as inp, SUM(output_tokens) as out_t, "
                "SUM(estimated_cost_usd) as cost, COUNT(*) as calls "
                "FROM ai_usage_log "
                "WHERE business_id = :bid AND created_at >= :since "
                "GROUP BY agent_name, model "
                "ORDER BY cost DESC"
            ),
            {"bid": business_id, "since": since},
        )
        by_agent = [
            {
                "agent": r[0], "model": r[1],
                "input_tokens": int(r[2]), "output_tokens": int(r[3]),
                "cost_usd": round(float(r[4]), 4), "calls": int(r[5]),
            }
            for r in agent_result.fetchall()
        ]

        # Daily totals
        daily_result = await session.execute(
            text(
                "SELECT DATE(created_at) as day, "
                "SUM(input_tokens + output_tokens) as tokens, "
                "SUM(estimated_cost_usd) as cost, COUNT(*) as calls "
                "FROM ai_usage_log "
                "WHERE business_id = :bid AND created_at >= :since "
                "GROUP BY DATE(created_at) ORDER BY day"
            ),
            {"bid": business_id, "since": since},
        )
        daily = [
            {
                "date": str(r[0]),
                "tokens": int(r[1]),
                "cost_usd": round(float(r[2]), 4),
                "calls": int(r[3]),
            }
            for r in daily_result.fetchall()
        ]

        # Totals
        totals_result = await session.execute(
            text(
                "SELECT SUM(input_tokens), SUM(output_tokens), "
                "SUM(estimated_cost_usd), COUNT(*) "
                "FROM ai_usage_log "
                "WHERE business_id = :bid AND created_at >= :since"
            ),
            {"bid": business_id, "since": since},
        )
        t = totals_result.fetchone()

    return {
        "period_days": days,
        "totals": {
            "input_tokens": int(t[0] or 0),
            "output_tokens": int(t[1] or 0),
            "cost_usd": round(float(t[2] or 0), 4),
            "total_calls": int(t[3] or 0),
        },
        "by_agent": by_agent,
        "daily": daily,
    }


async def log_billing_record(
    business_id: int,
    platform_owner_id: int,
    tokens_used: int,
    cost_usd: float,
):
    """Create a billing record when a tenant uses the platform owner's API keys."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    now = datetime.utcnow()
    period_start = now.replace(day=1, hour=0, minute=0, second=0, microsecond=0)
    if now.month == 12:
        period_end = period_start.replace(year=now.year + 1, month=1)
    else:
        period_end = period_start.replace(month=now.month + 1)

    session_factory = get_session_factory()
    async with session_factory() as session:
        # Upsert: update existing record for this period or create new
        existing = await session.execute(
            text(
                "SELECT id, ai_tokens_used, ai_cost_usd FROM billing_records "
                "WHERE business_id = :bid AND period_start = :ps"
            ),
            {"bid": business_id, "ps": period_start},
        )
        row = existing.fetchone()

        if row:
            await session.execute(
                text(
                    "UPDATE billing_records SET "
                    "ai_tokens_used = ai_tokens_used + :tokens, "
                    "ai_cost_usd = ai_cost_usd + :cost, "
                    "updated_at = :now "
                    "WHERE id = :id"
                ),
                {"tokens": tokens_used, "cost": cost_usd, "now": now, "id": row[0]},
            )
        else:
            await session.execute(
                text(
                    "INSERT INTO billing_records "
                    "(business_id, period_start, period_end, ai_tokens_used, "
                    "ai_cost_usd, platform_owner_id, status, created_at) "
                    "VALUES (:bid, :ps, :pe, :tokens, :cost, :owner, 'pending', :now)"
                ),
                {
                    "bid": business_id, "ps": period_start, "pe": period_end,
                    "tokens": tokens_used, "cost": cost_usd,
                    "owner": platform_owner_id, "now": now,
                },
            )
        await session.commit()
