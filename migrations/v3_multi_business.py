"""
Database Migration Script — v3.0 Multi-Business & AI Provider Enhancements

Creates/alters tables for:
  - user_business_links   — Junction table for multi-business user access
  - ai_provider_configs   — Adds base_url column for local/custom AI endpoints

Run with:  python -m migrations.v3_multi_business
"""

import asyncio
import logging

logger = logging.getLogger(__name__)

MIGRATION_SQL = """
-- ──────────────────────────────────────────────────────────────────────────────
-- 1. User–Business junction table (multi-business support)
-- ──────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_business_links (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    business_id     INT NOT NULL,
    role            VARCHAR(20) NOT NULL DEFAULT 'owner',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_business (user_id, business_id),
    INDEX idx_ubl_user (user_id),
    INDEX idx_ubl_business (business_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────────────────────────────
-- 2. Add base_url to ai_provider_configs (for ollama / custom endpoints)
-- ──────────────────────────────────────────────────────────────────────────────
-- MySQL IF NOT EXISTS for columns is not supported natively, so we use a
-- stored procedure approach.  If the column already exists, this is a no-op.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'ai_provider_configs'
      AND column_name = 'base_url'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE ai_provider_configs ADD COLUMN base_url VARCHAR(500) NULL AFTER model_name',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ──────────────────────────────────────────────────────────────────────────────
-- 3. Seed existing users into user_business_links (backfill)
-- ──────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO user_business_links (user_id, business_id, role, created_at)
SELECT id, business_id, role, COALESCE(created_at, NOW())
FROM users
WHERE business_id IS NOT NULL AND business_id > 0;
"""


async def run():
    """Execute the migration statements."""
    from memory.database import get_session_factory
    from sqlalchemy import text

    session_factory = get_session_factory()
    async with session_factory() as session:
        for stmt in MIGRATION_SQL.split(";"):
            stmt = stmt.strip()
            if not stmt or stmt.startswith("--"):
                continue
            try:
                await session.execute(text(stmt))
            except Exception as e:
                # Ignore duplicate column / already-exists errors
                err = str(e).lower()
                if "duplicate" in err or "already exists" in err or "1060" in err:
                    logger.info(f"Skipped (already exists): {stmt[:80]}...")
                else:
                    logger.warning(f"Migration statement warning: {e}")
        await session.commit()
    logger.info("v3 multi-business migration complete")


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO)
    asyncio.run(run())
