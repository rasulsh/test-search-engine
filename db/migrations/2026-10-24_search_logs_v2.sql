-- Search log v2: per-search id, session, source, stage latencies, flags, and the
-- search_events table (click / cart / order / dym_click tracking).
--
-- Target: MariaDB 10.4+ (ADD COLUMN / ADD INDEX IF NOT EXISTS). Safe to run any
-- number of times. Rows written before it keep log_version = 1; the app writes 2.
-- Table names below are the defaults (db.search_logs_table); rename them to match
-- your config.php before running if you changed them.
--
-- Apply:    mysql search_db < db/migrations/2026-10-24_search_logs_v2.sql
-- Rollback: run only the DOWN section (everything after the marker line below).
--           It drops the new columns, so v2 data is lost; take a dump first.
--
-- The app also adds the missing COLUMNS itself on its first log write (Logger),
-- when its database user may ALTER. The indexes only come from this file.

SET NAMES utf8mb4;

-- ==== UP ====

ALTER TABLE search_logs
  ADD COLUMN IF NOT EXISTS log_version    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS search_uuid    CHAR(36)    NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS session_id     VARCHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS source         VARCHAR(16) NOT NULL DEFAULT 'page',   -- page|autocomplete|filter|paging
  ADD COLUMN IF NOT EXISTS page           TINYINT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS expanded_q     VARCHAR(512) NULL,
  ADD COLUMN IF NOT EXISTS alias_hit      TINYINT(1)  NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS dym_shown      TINYINT(1)  NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS is_capped      TINYINT(1)  NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS lat_keyword_ms INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS lat_vector_ms  INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS lat_spell_ms   INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS is_suspicious  TINYINT(1)  NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS is_duplicate   TINYINT(1)  NOT NULL DEFAULT 0,
  ADD INDEX IF NOT EXISTS idx_session (session_id, ts),
  ADD INDEX IF NOT EXISTS idx_nq (normalized_q(64), ts),
  ADD INDEX IF NOT EXISTS idx_uuid (search_uuid);

CREATE TABLE IF NOT EXISTS search_events (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  search_uuid CHAR(36) NOT NULL,
  product_id  INT UNSIGNED NOT NULL,
  position    SMALLINT UNSIGNED NOT NULL,
  action      VARCHAR(12) NOT NULL DEFAULT 'click',  -- click|cart|order|dym_click
  ts          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_search (search_uuid),
  KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==== DOWN (rollback) ====

DROP TABLE IF EXISTS search_events;

ALTER TABLE search_logs
  DROP INDEX IF EXISTS idx_uuid,
  DROP INDEX IF EXISTS idx_nq,
  DROP INDEX IF EXISTS idx_session,
  DROP COLUMN IF EXISTS is_duplicate,
  DROP COLUMN IF EXISTS is_suspicious,
  DROP COLUMN IF EXISTS lat_spell_ms,
  DROP COLUMN IF EXISTS lat_vector_ms,
  DROP COLUMN IF EXISTS lat_keyword_ms,
  DROP COLUMN IF EXISTS is_capped,
  DROP COLUMN IF EXISTS dym_shown,
  DROP COLUMN IF EXISTS alias_hit,
  DROP COLUMN IF EXISTS expanded_q,
  DROP COLUMN IF EXISTS page,
  DROP COLUMN IF EXISTS source,
  DROP COLUMN IF EXISTS session_id,
  DROP COLUMN IF EXISTS search_uuid,
  DROP COLUMN IF EXISTS log_version;
