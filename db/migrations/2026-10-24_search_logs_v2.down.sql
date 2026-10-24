-- Rollback of 2026-10-24_search_logs_v2.sql: drops the v2 columns, their indexes and
-- search_events. The data in them is lost; take a dump first. Safe to run repeatedly.
-- Apply: mysql search_db < db/migrations/2026-10-24_search_logs_v2.down.sql

SET NAMES utf8mb4;


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
