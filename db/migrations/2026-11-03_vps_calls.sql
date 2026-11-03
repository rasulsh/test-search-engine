-- vps_calls: one row per PHP -> VPS call (search, reload, probe) with outcome and timings, shown on the
-- admin panel's VPS page (?p=vps). Timestamps are UTC. Rows older than vps.log_retention_days (default 30)
-- are purged by the app. Not swapped by /reload.
--
-- (The ALTER at the end brings a table created by an earlier revision of this file up to date.)
--
-- Target: MariaDB 10.4+ (DEFAULT (expression) needs 10.2.1). Safe to run any number of times. The table name is
-- fixed (vps_calls).
--
-- Apply:    mysql search_db < db/migrations/2026-11-03_vps_calls.sql
-- Rollback: mysql search_db < db/migrations/2026-11-03_vps_calls.down.sql
--           (a separate file; the call history is lost, take a dump first).
--
-- Unlike search_logs, the app does not create this table itself: without it the VPS page says so and the
-- search keeps working (a failed insert is only an error_log line).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS vps_calls (
    id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    ts            DATETIME         NOT NULL DEFAULT (UTC_TIMESTAMP()),
    kind          VARCHAR(8)       NOT NULL,              -- search|reload|probe
    outcome       VARCHAR(16)      NOT NULL,              -- ok|timeout|unreachable|http_error|bad_body|not_configured|skipped
    source        VARCHAR(8)       NOT NULL DEFAULT 'live', -- live|cache|stale|none (none = the call was skipped by design)
    http_status   SMALLINT UNSIGNED DEFAULT NULL,
    duration_ms   INT UNSIGNED     DEFAULT NULL,
    namelookup_ms INT UNSIGNED     DEFAULT NULL,          -- curl timings, cumulative from the start of the call:
    connect_ms    INT UNSIGNED     DEFAULT NULL,          -- NULL = that phase was never reached (or took no time,
    appconnect_ms INT UNSIGNED     DEFAULT NULL,          -- as for an IP address or a plain-http URL)
    ttfb_ms       INT UNSIGNED     DEFAULT NULL,          -- NULL = no first byte
    timeout_ms    INT UNSIGNED     DEFAULT NULL,          -- the configured limit of this call
    error         VARCHAR(200)     DEFAULT NULL,          -- short reason, never the URL or the token
    response_bytes INT UNSIGNED    DEFAULT NULL,
    neighbours    INT UNSIGNED     DEFAULT NULL,
    request_id    CHAR(32)         DEFAULT NULL,
    vps_queue_ms  DECIMAL(9,2)     DEFAULT NULL,          -- from the VPS response headers, NULL on an old VPS
    vps_embed_ms  DECIMAL(9,2)     DEFAULT NULL,
    vps_search_ms DECIMAL(9,2)     DEFAULT NULL,
    vps_took_ms   DECIMAL(9,2)     DEFAULT NULL,          -- took_ms of the VPS's JSON body (search)
    vps_inflight  SMALLINT UNSIGNED DEFAULT NULL,
    normalized_q  VARCHAR(120)     DEFAULT NULL,          -- search calls only
    sent_differs  TINYINT(1)       NOT NULL DEFAULT 0,    -- the text sent to the VPS differs from normalized_q
    sent_hash     CHAR(8)          DEFAULT NULL,          -- sha1 prefix of the text sent
    PRIMARY KEY (id),
    KEY idx_ts (ts),
    KEY idx_outcome_ts (outcome, ts)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE vps_calls
    ADD COLUMN IF NOT EXISTS source VARCHAR(8) NOT NULL DEFAULT 'live' AFTER outcome,
    ADD COLUMN IF NOT EXISTS namelookup_ms INT UNSIGNED DEFAULT NULL AFTER duration_ms,
    ADD COLUMN IF NOT EXISTS appconnect_ms INT UNSIGNED DEFAULT NULL AFTER connect_ms,
    ADD COLUMN IF NOT EXISTS vps_took_ms DECIMAL(9,2) DEFAULT NULL AFTER vps_search_ms,
    ADD COLUMN IF NOT EXISTS sent_differs TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS sent_hash CHAR(8) DEFAULT NULL;
