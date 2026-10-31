-- search_logs.is_burst: set on the 4th and later identical page-1 rows of one session (or, without a
-- session, one normalized query) within 60 seconds. The analytics leave those rows out of "a search";
-- the raw log list still shows them. Not the same as is_suspicious, which marks SQL-probe text.
--
-- Target: MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS). Safe to run any number of times. The table name below is the
-- default (db.search_logs_table); rename it to match your config.php before running if you changed it.
--
-- Apply:    mysql search_db < db/migrations/2026-10-31_search_logs_is_burst.sql
-- Rollback: mysql search_db < db/migrations/2026-10-31_search_logs_is_burst.down.sql
--
-- The app also adds the column itself on its first log write (Logger), when its database user may ALTER.

SET NAMES utf8mb4;

ALTER TABLE search_logs
  ADD COLUMN IF NOT EXISTS is_burst TINYINT(1) NOT NULL DEFAULT 0;
