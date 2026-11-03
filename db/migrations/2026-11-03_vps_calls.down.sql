-- Rollback of 2026-11-03_vps_calls.sql: drops the VPS call history. Safe to run repeatedly.
-- Apply: mysql search_db < db/migrations/2026-11-03_vps_calls.down.sql

SET NAMES utf8mb4;

DROP TABLE IF EXISTS vps_calls;
