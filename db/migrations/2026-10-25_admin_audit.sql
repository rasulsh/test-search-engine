-- Admin panel audit trail (M39): login success / failure and every write action.
-- The failed-login throttle counts the failure rows. The table name is the default
-- (db.admin_audit_table); rename it to match your config.php if you changed it.
-- Safe to run any number of times.
--
-- Apply:    mysql search_db < db/migrations/2026-10-25_admin_audit.sql
-- Rollback: mysql search_db < db/migrations/2026-10-25_admin_audit.down.sql
--           (a separate file, so applying this one never undoes itself; the audit
--           history is lost, take a dump first).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS admin_audit (
    id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ts     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip     VARCHAR(45)     NOT NULL DEFAULT '',
    action VARCHAR(32)     NOT NULL,
    detail VARCHAR(512)    NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_ts (ts),
    KEY idx_action_ip_ts (action, ip, ts)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
