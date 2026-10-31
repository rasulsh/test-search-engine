-- Rollback of 2026-10-31_search_logs_is_burst.sql: drops the column (its data is lost).
-- Safe to run repeatedly. Apply: mysql search_db < db/migrations/2026-10-31_search_logs_is_burst.down.sql

SET NAMES utf8mb4;

ALTER TABLE search_logs
  DROP COLUMN IF EXISTS is_burst;
