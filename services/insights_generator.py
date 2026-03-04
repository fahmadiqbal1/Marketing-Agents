"""
Auto-Insights Generator — periodic analysis of engagement data.

Generates actionable insights like:
  - Best performing platform / content category
  - Optimal posting times based on actual engagement
  - Content gap alerts
  - Growth trends (week-over-week)
"""

from __future__ import annotations

import logging
from datetime import datetime, timedelta
from typing import Any

logger = logging.getLogger(__name__)


async def generate_insights(business_id: int, days: int = 7) -> dict[str, Any]:
    """Generate a full insights report for a business."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    session_factory = get_session_factory()
    now = datetime.utcnow()
    start = now - timedelta(days=days)
    prev_start = start - timedelta(days=days)

    insights = {
        "period_days": days,
        "generated_at": now.isoformat(),
        "summary": {},
        "platform_performance": [],
        "content_performance": [],
        "best_times": [],
        "growth_trends": {},
        "recommendations": [],
    }

    async with session_factory() as session:
        # ── Total posts this period ──────────────────────────────────
        result = await session.execute(
            text(
                "SELECT COUNT(*) as cnt, "
                "SUM(likes) as total_likes, "
                "SUM(views) as total_views, "
                "SUM(shares) as total_shares, "
                "SUM(comments_count) as total_comments "
                "FROM posts "
                "WHERE business_id = :bid "
                "AND published_at >= :start "
                "AND status = 'published'"
            ),
            {"bid": business_id, "start": start},
        )
        row = result.fetchone()
        total_posts = row[0] or 0
        total_likes = row[1] or 0
        total_views = row[2] or 0
        total_shares = row[3] or 0
        total_comments = row[4] or 0

        # ── Previous period for comparison ───────────────────────────
        prev_result = await session.execute(
            text(
                "SELECT COUNT(*) as cnt, "
                "SUM(likes) as total_likes, "
                "SUM(views) as total_views "
                "FROM posts "
                "WHERE business_id = :bid "
                "AND published_at >= :prev_start "
                "AND published_at < :start "
                "AND status = 'published'"
            ),
            {"bid": business_id, "prev_start": prev_start, "start": start},
        )
        prev_row = prev_result.fetchone()
        prev_posts = prev_row[0] or 0
        prev_likes = prev_row[1] or 0
        prev_views = prev_row[2] or 0

        # Growth calculations
        def pct_change(current: int, previous: int) -> float:
            if previous == 0:
                return 100.0 if current > 0 else 0.0
            return round(((current - previous) / previous) * 100, 1)

        insights["summary"] = {
            "total_posts": total_posts,
            "total_likes": total_likes,
            "total_views": total_views,
            "total_shares": total_shares,
            "total_comments": total_comments,
            "engagement_rate": round(
                ((total_likes + total_comments + total_shares) / max(total_views, 1)) * 100, 2
            ),
        }

        insights["growth_trends"] = {
            "posts_change": pct_change(total_posts, prev_posts),
            "likes_change": pct_change(total_likes, prev_likes),
            "views_change": pct_change(total_views, prev_views),
            "direction": "up" if total_likes > prev_likes else ("down" if total_likes < prev_likes else "flat"),
        }

        # ── Per-platform performance ─────────────────────────────────
        plat_result = await session.execute(
            text(
                "SELECT platform, COUNT(*) as cnt, "
                "SUM(likes) as lk, SUM(views) as vw, "
                "SUM(shares) as sh, SUM(comments_count) as cm "
                "FROM posts "
                "WHERE business_id = :bid "
                "AND published_at >= :start "
                "AND status = 'published' "
                "GROUP BY platform ORDER BY lk DESC"
            ),
            {"bid": business_id, "start": start},
        )
        for row in plat_result.fetchall():
            views = row[3] or 1
            engagement = ((row[2] or 0) + (row[5] or 0) + (row[4] or 0))
            insights["platform_performance"].append({
                "platform": row[0],
                "posts": row[1],
                "likes": row[2] or 0,
                "views": row[3] or 0,
                "shares": row[4] or 0,
                "comments": row[5] or 0,
                "engagement_rate": round((engagement / views) * 100, 2),
            })

        # ── Content category performance ─────────────────────────────
        cat_result = await session.execute(
            text(
                "SELECT cc.content_category, COUNT(*) as cnt, "
                "AVG(p.likes) as avg_likes, AVG(p.views) as avg_views "
                "FROM content_calendar cc "
                "JOIN posts p ON cc.post_id = p.id "
                "WHERE cc.business_id = :bid "
                "AND cc.posted_at >= :start "
                "GROUP BY cc.content_category "
                "ORDER BY avg_likes DESC"
            ),
            {"bid": business_id, "start": start},
        )
        for row in cat_result.fetchall():
            insights["content_performance"].append({
                "category": row[0],
                "posts": row[1],
                "avg_likes": round(row[2] or 0, 1),
                "avg_views": round(row[3] or 0, 1),
            })

        # ── Best posting times (by hour) ─────────────────────────────
        time_result = await session.execute(
            text(
                "SELECT HOUR(published_at) as hr, "
                "AVG(likes) as avg_lk, COUNT(*) as cnt "
                "FROM posts "
                "WHERE business_id = :bid "
                "AND published_at >= :start "
                "AND status = 'published' "
                "GROUP BY HOUR(published_at) "
                "ORDER BY avg_lk DESC "
                "LIMIT 5"
            ),
            {"bid": business_id, "start": start},
        )
        for row in time_result.fetchall():
            insights["best_times"].append({
                "hour": row[0],
                "avg_likes": round(row[1] or 0, 1),
                "post_count": row[2],
            })

        # ── Failed posts count ───────────────────────────────────────
        fail_result = await session.execute(
            text(
                "SELECT COUNT(*) FROM posts "
                "WHERE business_id = :bid AND status = 'failed' "
                "AND created_at >= :start"
            ),
            {"bid": business_id, "start": start},
        )
        failed_count = (fail_result.fetchone()[0] or 0)

    # ── Generate recommendations ─────────────────────────────────────
    recs = []

    if total_posts == 0:
        recs.append({
            "type": "warning",
            "title": "No posts this period",
            "message": "You haven't published any content in the last {} days. Upload media to get started!".format(days),
        })
    else:
        if insights["platform_performance"]:
            best = insights["platform_performance"][0]
            recs.append({
                "type": "success",
                "title": f"Top Platform: {best['platform'].title()}",
                "message": f"{best['platform'].title()} is your best performer with {best['engagement_rate']}% engagement rate.",
            })

        if insights["growth_trends"]["direction"] == "up":
            recs.append({
                "type": "success",
                "title": "Growing Engagement",
                "message": f"Likes increased {insights['growth_trends']['likes_change']}% compared to the previous period. Keep it up!",
            })
        elif insights["growth_trends"]["direction"] == "down":
            recs.append({
                "type": "warning",
                "title": "Engagement Declining",
                "message": f"Likes decreased {abs(insights['growth_trends']['likes_change'])}%. Consider varying your content types or posting times.",
            })

        if insights["best_times"]:
            best_hr = insights["best_times"][0]["hour"]
            recs.append({
                "type": "info",
                "title": f"Best Time: {best_hr}:00",
                "message": f"Posts at {best_hr}:00 get the highest average engagement. Schedule your content around this time.",
            })

        if failed_count > 0:
            recs.append({
                "type": "danger",
                "title": f"{failed_count} Failed Posts",
                "message": "Some posts failed to publish. Check the Posts page for details and retry.",
            })

        if total_posts < 3:
            recs.append({
                "type": "info",
                "title": "Post More Frequently",
                "message": "Posting at least 3-5 times per week improves visibility. Try scheduling content in advance.",
            })

    insights["recommendations"] = recs

    return insights
