-- GENERATED from fixtures/products.input.sql by build.py's load-file writer
-- (statement cap 700 bytes, so rows span several statements). The PHP
-- StagingLoader tests load it. Regenerate after a format or schema change:
--   REGENERATE_FIXTURES=1 pytest -k load_sample
SET NAMES utf8mb4;

DROP TABLE IF EXISTS `products_new`;
CREATE TABLE `products_new` (
    product_id       INT UNSIGNED    NOT NULL,
    title            VARCHAR(512)    NOT NULL,
    description      MEDIUMTEXT      NOT NULL,
    normalized_title VARCHAR(640)    NOT NULL,
    normalized_desc  MEDIUMTEXT      NOT NULL,
    normalized_specs MEDIUMTEXT      NOT NULL DEFAULT (''),
    brand            VARCHAR(255)    NOT NULL DEFAULT '',
    category         VARCHAR(255)    NOT NULL DEFAULT '',
    model            VARCHAR(255)    NOT NULL DEFAULT '',
    sku              VARCHAR(64)     NOT NULL DEFAULT '',
    normalized_sku   VARCHAR(64)     NOT NULL DEFAULT '',
    price            DECIMAL(15, 4)  NOT NULL DEFAULT 0,
    stock            INT             NOT NULL DEFAULT 0,
    url              VARCHAR(1024)   NOT NULL DEFAULT '',
    image            VARCHAR(1024)   NOT NULL DEFAULT '',
    popularity       INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (product_id),
    KEY idx_model (model),
    KEY idx_normalized_sku (normalized_sku),
    FULLTEXT KEY ft_normalized (normalized_title, normalized_specs, normalized_desc)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

REPLACE INTO `products_new`
    (product_id, title, description, normalized_title, normalized_desc, normalized_specs, brand, category, model, sku, normalized_sku, price, stock, url, image, popularity)
VALUES
    (2001, 'گوشی اپل آیفون ۱۵ پرو Apple iPhone 15 Pro', 'flagship smartphone, titanium body', 'گوشی اپل ایفون 15 پرو apple iphone 15 pro aplip15p128', 'flagship smartphone, titanium body', '', 'Apple', 'Mobile Phone', 'IP15PRO', 'APL-IP15P-128', 'aplip15p128', 1199.0, 8, '/p/iphone-15-pro', '/img/ip15.jpg', 900);

REPLACE INTO `products_new`
    (product_id, title, description, normalized_title, normalized_desc, normalized_specs, brand, category, model, sku, normalized_sku, price, stock, url, image, popularity)
VALUES
    (2002, 'هدفون بی‌سیم سونی Sony WH-1000XM5 Wireless Headphones', 'world''s best noise cancelling, over-ear', 'هدفون بیسیم سونی sony wh1000xm5 wireless headphones snyxm5blk', 'world''s best noise cancelling, overear', '', 'Sony', 'Headphone', 'WH-1000XM5', 'SNY-XM5-BLK', 'snyxm5blk', 349.0, 25, '/p/sony-xm5', '/img/xm5.jpg', 500);

REPLACE INTO `products_new`
    (product_id, title, description, normalized_title, normalized_desc, normalized_specs, brand, category, model, sku, normalized_sku, price, stock, url, image, popularity)
VALUES
    (2003, 'لپ تاپ ایسوس ویووبوک ASUS VivoBook 15', 'core i7, 16gb ram', 'لپ تاپ ایسوس ویووبوک asus vivobook 15', 'core i7, 16gb ram', 'پردازنده: core i7 | حافظه رم: 16gb', 'ASUS', 'Laptop', 'X1504', '', '', 799.0, 6, '/p/vivobook', '/img/vivobook.jpg', 300);

REPLACE INTO `products_new`
    (product_id, title, description, normalized_title, normalized_desc, normalized_specs, brand, category, model, sku, normalized_sku, price, stock, url, image, popularity)
VALUES
    (2004, 'تلویزیون ال‌جی اولد ۵۵ LG OLED TV 55 C3', '4k smart tv with webos', 'تلویزیون الجی اولد 55 lg oled tv 55 c3', '4k smart tv with webos', '', 'LG', 'Television', 'OLED55C3', '', '', 1499.0, 4, '/p/lg-c3', '', 250);

REPLACE INTO `products_new`
    (product_id, title, description, normalized_title, normalized_desc, normalized_specs, brand, category, model, sku, normalized_sku, price, stock, url, image, popularity)
VALUES
    (2005, 'مانیتور گیمینگ ۲۷ اینچ Gaming Monitor 27 inch', 'esports monitor with a fast panel', 'مانیتور گیمینگ 27 اینچ gaming monitor 27 inch pg27aqdp', 'esports monitor with a fast panel', 'برند: asus rog | اندازه صفحه نمایش: 27 اینچ | کیفیت تصویر 2k hdr10 | نرخ نوسازی تصویر 540 هرتز | زمان پاسخدهی 002 میلی ثانیه | پنل 27 اینچ tandem oled', 'ASUS', 'Monitor', 'PG27AQDP', 'PG27AQDP', 'pg27aqdp', 1099.0, 3, '/p/pg27', '/img/pg27.jpg', 120);

REPLACE INTO `products_new`
    (product_id, title, description, normalized_title, normalized_desc, normalized_specs, brand, category, model, sku, normalized_sku, price, stock, url, image, popularity)
VALUES
    (2006, 'کابل دیسپلی پورت DisplayPort Cable', 'for ASUS ROG monitors up to 540 هرتز', 'کابل دیسپلی پورت displayport cable', 'for asus rog monitors up to 540 هرتز', 'طول: 2 متر', 'Ugreen', 'Cable', 'DP21', '', '', 25.0, 40, '/p/dp21', '/img/dp21.jpg', 900);
