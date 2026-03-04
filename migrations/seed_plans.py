"""Seed subscription plans."""
import asyncio

async def seed():
    from memory.database import get_session_factory
    from sqlalchemy import text

    sf = get_session_factory()
    async with sf() as session:
        r = await session.execute(text("SELECT COUNT(*) FROM subscription_plans"))
        count = r.scalar()
        if count > 0:
            print(f"{count} plans already exist — skipping seed")
            return

        plans = [
            ("free", "Free", 100000, 0.00, 2, 30, 20),
            ("starter", "Starter", 1000000, 29.00, 5, 150, 100),
            ("pro", "Pro", 5000000, 79.00, 10, 500, 500),
            ("enterprise", "Enterprise", 50000000, 199.00, 99, 9999, 9999),
        ]
        for name, display, tokens, cost, plat, posts, calls in plans:
            await session.execute(
                text(
                    "INSERT INTO subscription_plans "
                    "(name, display_name, monthly_token_limit, monthly_cost_usd, "
                    "max_platforms, max_posts_per_month, max_ai_calls_per_day, is_active) "
                    "VALUES (:n, :d, :t, :c, :p, :po, :ca, 1)"
                ),
                {"n": name, "d": display, "t": tokens, "c": cost, "p": plat, "po": posts, "ca": calls},
            )
        await session.commit()
        print("Seeded 4 subscription plans")

if __name__ == "__main__":
    asyncio.run(seed())
