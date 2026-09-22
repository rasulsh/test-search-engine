-- Sample catalog export in build.py's documented input format (NOT the raw
-- OpenCart shape). Column order matches build.py INPUT_COLUMNS. See README for
-- how to derive these columns from OpenCart 2.0.3.1. Column names are
-- backtick-quoted so `desc` is a valid identifier.
INSERT INTO products_import
    (`id`, `title_fa`, `title_en`, `desc`, `brand`, `category`, `model`, `price`, `stock`, `url`, `image`, `popularity`)
VALUES
    (2001, 'گوشی اپل آیفون ۱۵ پرو', 'Apple iPhone 15 Pro', 'flagship smartphone, titanium body', 'Apple', 'Mobile Phone', 'IP15PRO', 1199.00, 8, '/p/iphone-15-pro', '/img/ip15.jpg', 900),
    (2002, 'هدفون بی‌سیم سونی', 'Sony WH-1000XM5 Wireless Headphones', 'world''s best noise cancelling, over-ear', 'Sony', 'Headphone', 'WH-1000XM5', 349.00, 25, '/p/sony-xm5', '/img/xm5.jpg', 500),
    (2003, 'لپ تاپ ایسوس ویووبوک', 'ASUS VivoBook 15', 'core i7, 16gb ram', 'ASUS', 'Laptop', 'X1504', 799.00, 6, '/p/vivobook', '/img/vivobook.jpg', 300),
    (2004, 'تلویزیون ال‌جی اولد ۵۵', 'LG OLED TV 55 C3', '4k smart tv with webos', 'LG', 'Television', 'OLED55C3', 1499.00, 4, '/p/lg-c3', NULL, 250);
