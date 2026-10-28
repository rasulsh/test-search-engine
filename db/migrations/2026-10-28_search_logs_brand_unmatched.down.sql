-- Rollback of 2026-10-28_search_logs_brand_unmatched.sql: drops the column (its data is lost).
-- Safe to run repeatedly. Apply: mysql search_db < db/migrations/2026-10-28_search_logs_brand_unmatched.down.sql

SET NAMES utf8mb4;

ALTER TABLE search_logs
  DROP COLUMN IF EXISTS brand_unmatched;
