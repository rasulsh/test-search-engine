-- Rollback of 2026-10-25_admin_audit.sql: drops the admin audit table. The audit history
-- is lost; take a dump first. Safe to run repeatedly.
-- Apply: mysql search_db < db/migrations/2026-10-25_admin_audit.down.sql

SET NAMES utf8mb4;

DROP TABLE IF EXISTS admin_audit;
