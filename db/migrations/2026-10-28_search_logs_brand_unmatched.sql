-- M44: search_logs.brand_unmatched, the brand(s) a query named that none of the candidate products carries
-- ("asked for, not stocked"; the analytics page lists them). NULL when the query named no brand or it was matched.
--
-- Target: MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS). Safe to run any number of times. The table name below is the
-- default (db.search_logs_table); rename it to match your config.php before running if you changed it.
--
-- Apply:    mysql search_db < db/migrations/2026-10-28_search_logs_brand_unmatched.sql
-- Rollback: mysql search_db < db/migrations/2026-10-28_search_logs_brand_unmatched.down.sql
--
-- The app also adds the column itself on its first log write (Logger), when its database user may ALTER.

SET NAMES utf8mb4;

ALTER TABLE search_logs
  ADD COLUMN IF NOT EXISTS brand_unmatched VARCHAR(190) NULL;
