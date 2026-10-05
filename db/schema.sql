-- Search service schema (MySQL 8 / MariaDB 10.4+, InnoDB, utf8mb4).
--
-- The FULLTEXT index lives on dedicated `normalized_*` columns, never on the raw
-- text. MySQL/MariaDB FULLTEXT has no Persian stemming and mishandles ZWNJ, so
-- M2's Normalizer will populate these columns canonically. In M1 they hold a
-- passthrough copy of the raw text; this keeps the index shape stable across
-- milestones (no schema migration in M2).
--
-- pipeline/build.py copies the products definition below into
-- products.load.sql as the staging table's DDL, so a rebuilt bundle carries
-- schema changes to the live table through the reload swap (no ALTER needed).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS products (
    product_id       INT UNSIGNED    NOT NULL,
    title            VARCHAR(512)    NOT NULL,
    description      MEDIUMTEXT      NOT NULL,
    -- FULLTEXT-indexed, normalized copies (passthrough in M1, canonical in M2).
    -- Title (fa + en, 511 max) plus the normalized SKU (64 max).
    normalized_title VARCHAR(640)    NOT NULL,
    normalized_desc  MEDIUMTEXT      NOT NULL,
    -- M13: attribute pairs + feature titles (high-signal, not length-capped).
    -- Expression default: MySQL 8 rejects a literal default on TEXT columns.
    normalized_specs MEDIUMTEXT      NOT NULL DEFAULT (''),
    -- M21: store tag names (oc_tag via oc_product_tag), brand and category,
    -- normalized. Tags are franchise / alternate product names: weighted just
    -- below the title; brand and category are weighted below tags (config).
    normalized_tags     TEXT         NOT NULL DEFAULT (''),
    normalized_brand    VARCHAR(255) NOT NULL DEFAULT '',
    normalized_category VARCHAR(255) NOT NULL DEFAULT '',
    -- M23: Normalizer::collapse of the identity fields (title, brand, tags, space-
    -- separated, cut to 700 characters), so "farcry" meets "Far Cry 5" and
    -- "far cry" meets "FarCry". Only substring-scanned (LIKE), via the covering
    -- index below; 700 characters keeps that index under InnoDB's 3072-byte key limit.
    normalized_collapsed VARCHAR(700) NOT NULL DEFAULT '',
    brand            VARCHAR(255)    NOT NULL DEFAULT '',
    category         VARCHAR(255)    NOT NULL DEFAULT '',
    model            VARCHAR(255)    NOT NULL DEFAULT '',
    sku              VARCHAR(64)     NOT NULL DEFAULT '',
    -- Normalizer::normalizeSku(sku): exact/prefix SKU lookups ranked first.
    normalized_sku   VARCHAR(64)     NOT NULL DEFAULT '',
    price            DECIMAL(15, 4)  NOT NULL DEFAULT 0,
    stock            INT             NOT NULL DEFAULT 0,
    url              VARCHAR(1024)   NOT NULL DEFAULT '',
    image            VARCHAR(1024)   NOT NULL DEFAULT '',
    popularity       INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (product_id),
    KEY idx_model (model),
    KEY idx_normalized_sku (normalized_sku),
    -- M15: alias variants match the title only; this covering index lets that
    -- scan read the short titles instead of every row's long text columns.
    KEY idx_title_scan (normalized_title, popularity),
    KEY idx_collapsed_scan (normalized_collapsed, popularity),
    -- Column order is the order Keyword.php lists in MATCH(): keep them in sync.
    FULLTEXT KEY ft_normalized (
        normalized_title, normalized_tags, normalized_brand, normalized_category,
        normalized_specs, normalized_desc
    )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS search_logs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ts           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    raw_q        VARCHAR(512)    NOT NULL,
    normalized_q VARCHAR(512)    NOT NULL DEFAULT '',
    had_vector   TINYINT(1)      NOT NULL DEFAULT 0,
    result_count INT UNSIGNED    NOT NULL DEFAULT 0,
    top_ids      VARCHAR(1024)   NOT NULL DEFAULT '',
    customer_id  VARCHAR(64)     DEFAULT NULL,
    latency_ms   INT UNSIGNED    NOT NULL DEFAULT 0,
    -- M21: the suggestion offered (or applied) and the tier that answered:
    -- 'keyword_only' or 'hybrid'. Logger.php adds both to an older table.
    did_you_mean VARCHAR(512)    DEFAULT NULL,
    tier         VARCHAR(16)     NOT NULL DEFAULT 'keyword_only',
    -- M26: 1 when the result came from the Redis result cache (Logger.php adds it).
    cache_hit    TINYINT(1)      NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_ts (ts)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
