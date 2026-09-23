-- Sample catalog export in build.py's documented input format (NOT the raw
-- OpenCart shape). Column order matches build.py INPUT_COLUMNS. See README for
-- how to derive these columns from OpenCart 2.0.3.1. Column names are
-- backtick-quoted so `desc` is a valid identifier.
INSERT INTO products_import
    (`id`, `title_fa`, `title_en`, `desc`, `brand`, `category`, `model`, `sku`, `price`, `stock`, `url`, `image`, `popularity`, `attributes`, `feature`)
VALUES
    (2001, 'گوشی اپل آیفون ۱۵ پرو', 'Apple iPhone 15 Pro', 'flagship smartphone, titanium body', 'Apple', 'Mobile Phone', 'IP15PRO', 'APL-IP15P-128', 1199.00, 8, '/p/iphone-15-pro', '/img/ip15.jpg', 900, NULL, NULL),
    (2002, 'هدفون بی‌سیم سونی', 'Sony WH-1000XM5 Wireless Headphones', 'world''s best noise cancelling, over-ear', 'Sony', 'Headphone', 'WH-1000XM5', 'SNY-XM5-BLK', 349.00, 25, '/p/sony-xm5', '/img/xm5.jpg', 500, '', ''),
    (2003, 'لپ تاپ ایسوس ویووبوک', 'ASUS VivoBook 15', 'core i7, 16gb ram', 'ASUS', 'Laptop', 'X1504', '', 799.00, 6, '/p/vivobook', '/img/vivobook.jpg', 300, 'پردازنده: Core i7 | حافظه رم: 16GB', NULL),
    (2004, 'تلویزیون ال‌جی اولد ۵۵', 'LG OLED TV 55 C3', '4k smart tv with webos', 'LG', 'Television', 'OLED55C3', NULL, 1499.00, 4, '/p/lg-c3', NULL, 250, NULL, NULL),
    (2005, 'مانیتور گیمینگ ۲۷ اینچ', 'Gaming Monitor 27 inch', 'esports monitor with a fast panel', 'ASUS', 'Monitor', 'PG27AQDP', 'PG27AQDP', 1099.00, 3, '/p/pg27', '/img/pg27.jpg', 120, 'برند: ASUS ROG | اندازه صفحه نمایش: ۲۷ اینچ', 'a:4:{i:0;a:1:{s:5:"title";s:30:"کیفیت تصویر 2K HDR10";}i:1;a:1:{s:5:"title";s:43:"نرخ نوسازی تصویر 540 هرتز";}i:2;a:1:{s:5:"title";s:51:"زمان پاسخ‌دهی 0.02 میلی ثانیه";}i:3;a:1:{s:5:"title";s:32:"پنل ۲۷ اینچ Tandem OLED";}}'),
    (2006, 'کابل دیسپلی پورت', 'DisplayPort Cable', 'for ASUS ROG monitors up to 540 هرتز', 'Ugreen', 'Cable', 'DP21', '', 25.00, 40, '/p/dp21', '/img/dp21.jpg', 900, 'طول: ۲ متر', 'a:1:{i:0;a:1:{s:5:"title";s:99:"broken";}}');
