-- Search service schema (MySQL 8 / MariaDB 10.4+, InnoDB, utf8mb4).
--
-- The FULLTEXT index lives on dedicated `normalized_*` columns, never on the raw
-- text. MySQL/MariaDB FULLTEXT has no Persian stemming and mishandles ZWNJ, so
-- M2's Normalizer will populate these columns canonically. In M1 they hold a
-- passthrough copy of the raw text; this keeps the index shape stable across
-- milestones (no schema migration in M2).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS products (
    product_id       INT UNSIGNED    NOT NULL,
    title            VARCHAR(512)    NOT NULL,
    description      MEDIUMTEXT      NOT NULL,
    -- FULLTEXT-indexed, normalized copies (passthrough in M1, canonical in M2).
    normalized_title VARCHAR(512)    NOT NULL,
    normalized_desc  MEDIUMTEXT      NOT NULL,
    brand            VARCHAR(255)    NOT NULL DEFAULT '',
    category         VARCHAR(255)    NOT NULL DEFAULT '',
    model            VARCHAR(255)    NOT NULL DEFAULT '',
    price            DECIMAL(15, 4)  NOT NULL DEFAULT 0,
    stock            INT             NOT NULL DEFAULT 0,
    url              VARCHAR(1024)   NOT NULL DEFAULT '',
    image            VARCHAR(1024)   NOT NULL DEFAULT '',
    popularity       INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (product_id),
    KEY idx_model (model),
    FULLTEXT KEY ft_normalized (normalized_title, normalized_desc)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS search_logs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ts           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    raw_q        VARCHAR(512)    NOT NULL,
    norm_q       VARCHAR(512)    NOT NULL DEFAULT '',
    had_vector   TINYINT(1)      NOT NULL DEFAULT 0,
    result_count INT UNSIGNED    NOT NULL DEFAULT 0,
    top_ids      VARCHAR(1024)   NOT NULL DEFAULT '',
    customer_id  VARCHAR(64)     DEFAULT NULL,
    latency_ms   INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_ts (ts)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
