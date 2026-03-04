"""
Database Migration Script — v2.0 Enhancement Tables

Creates new tables and columns added in the security/billing/bot enhancement phase:
  - ai_usage_log           — AI API call tracking per tenant
  - subscription_plans     — Available plan tiers
  - billing_records        — Monthly billing for shared-key tenants
  - bot_personalities      — Bot persona / training data per business
  - businesses columns     — uses_platform_api_keys, credit_approved

Run with:  python -m migrations.v2_enhance
"""

import asyncio
import logging

logger = logging.getLogger(__name__)

MIGRATION_SQL = """
-- ──────────────────────────────────────────────────────────────────────────────
-- 1. AI Usage Log
-- ──────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ai_usage_log (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    business_id     INT NOT NULL,
    user_id         INT NOT NULL DEFAULT 0,
    agent_name      VARCHAR(100) NOT NULL DEFAULT 'unknown',
    model           VARCHAR(100) NOT NULL DEFAULT 'gpt-4o-mini',
    operation       VARCHAR(200) NOT NULL DEFAULT '',
    input_tokens    INT NOT NULL DEFAULT 0,
    output_tokens   INT NOT NULL DEFAULT 0,
    estimated_cost_usd DECIMAL(12,6) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_aul_business_date (business_id, created_at),
    INDEX idx_aul_agent (agent_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────────────────────────────
-- 2. Subscription Plans
-- ──────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS subscription_plans (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    name                  VARCHAR(50)  NOT NULL UNIQUE,
    display_name          VARCHAR(100) NOT NULL DEFAULT '',
    monthly_token_limit   BIGINT NOT NULL DEFAULT 100000,
    monthly_cost_usd      DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_platforms         INT NOT NULL DEFAULT 3,
    max_posts_per_month   INT NOT NULL DEFAULT 100,
    max_ai_calls_per_day  INT NOT NULL DEFAULT 50,
    features_json         TEXT,
    is_active             TINYINT(1) NOT NULL DEFAULT 1,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default plans
INSERT IGNORE INTO subscription_plans (name, display_name, monthly_token_limit, monthly_cost_usd, max_platforms, max_posts_per_month, max_ai_calls_per_day, features_json) VALUES
('free',       'Free',       100000,    0.00, 2,  30,   20,  '["basic_captions","1_platform"]'),
('starter',    'Starter',    1000000,   29.00, 5,  150,  100, '["all_captions","5_platforms","seo_basic","analytics"]'),
('pro',        'Pro',        5000000,   79.00, 10, 500,  500, '["all_features","10_platforms","seo_full","hr","ab_testing","calendar"]'),
('enterprise', 'Enterprise', 50000000, 199.00, 99, 9999, 9999, '["all_features","unlimited_platforms","priority_support","custom_training","api_access"]');


-- ──────────────────────────────────────────────────────────────────────────────
-- 3. Billing Records
-- ──────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS billing_records (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    business_id       INT NOT NULL,
    period_start      DATE NOT NULL,
    period_end        DATE NOT NULL,
    ai_tokens_used    BIGINT NOT NULL DEFAULT 0,
    ai_cost_usd       DECIMAL(12,6) NOT NULL DEFAULT 0,
    platform_owner_id INT,
    status            VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_br_business (business_id),
    INDEX idx_br_period (period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────────────────────────────
-- 4. Bot Personalities
-- ──────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bot_personalities (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    business_id             INT NOT NULL,
    persona_name            VARCHAR(100) NOT NULL DEFAULT 'default',
    system_prompt_override  TEXT,
    tone                    VARCHAR(30) NOT NULL DEFAULT 'professional',
    response_style          VARCHAR(30) NOT NULL DEFAULT 'detailed',
    industry_context        VARCHAR(500),
    custom_commands_json    TEXT,
    trained_examples_json   TEXT,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_bp_business (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────────────────────────────
-- 5. New columns on businesses table
-- ──────────────────────────────────────────────────────────────────────────────
-- uses_platform_api_keys: tenant is requesting to use platform-owner's API keys
-- credit_approved:         admin has approved credit usage

-- Safe ALTER: add columns only if they don't exist (MySQL 8 doesn't have IF NOT EXISTS on columns)
-- We use a stored procedure to conditionally add them.

DROP PROCEDURE IF EXISTS _add_business_columns;

DELIMITER //
CREATE PROCEDURE _add_business_columns()
BEGIN
    -- uses_platform_api_keys
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'businesses'
          AND COLUMN_NAME = 'uses_platform_api_keys'
    ) THEN
        ALTER TABLE businesses ADD COLUMN uses_platform_api_keys TINYINT(1) NOT NULL DEFAULT 0;
    END IF;

    -- credit_approved
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'businesses'
          AND COLUMN_NAME = 'credit_approved'
    ) THEN
        ALTER TABLE businesses ADD COLUMN credit_approved TINYINT(1) NOT NULL DEFAULT 0;
    END IF;
END //
DELIMITER ;

CALL _add_business_columns();
DROP PROCEDURE IF EXISTS _add_business_columns;
"""


async def run_migration():
    """Execute the migration SQL against the configured database."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    session_factory = get_session_factory()

    # Split into individual statements (skip empty / comments-only)
    statements = []
    current = []
    delimiter = ";"

    for line in MIGRATION_SQL.split("\n"):
        stripped = line.strip()
        if stripped.upper().startswith("DELIMITER"):
            parts = stripped.split()
            if len(parts) >= 2:
                delimiter = parts[1]
            continue
        current.append(line)
        if stripped.endswith(delimiter):
            stmt = "\n".join(current)
            if delimiter != ";":
                stmt = stmt.rstrip(delimiter)
            stmt = stmt.strip()
            if stmt and not all(
                l.strip().startswith("--") or not l.strip()
                for l in stmt.split("\n")
            ):
                statements.append(stmt)
            current = []

    # Handle any remaining
    if current:
        stmt = "\n".join(current).strip()
        if stmt:
            statements.append(stmt)

    async with session_factory() as session:
        success = 0
        for i, stmt in enumerate(statements, 1):
            try:
                await session.execute(text(stmt))
                success += 1
            except Exception as e:
                # Skip "already exists" type errors
                err_str = str(e).lower()
                if "already exists" in err_str or "duplicate" in err_str:
                    logger.info(f"Statement {i}: Already exists (skipped)")
                    success += 1
                else:
                    logger.error(f"Statement {i} failed: {e}")
                    logger.debug(f"SQL: {stmt[:200]}")
        await session.commit()

    logger.info(f"Migration complete: {success}/{len(statements)} statements executed")
    print(f"✅ Migration complete: {success}/{len(statements)} statements executed")


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO)
    asyncio.run(run_migration())
