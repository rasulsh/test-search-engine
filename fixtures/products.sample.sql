-- Tiny bilingual (Persian + English) catalog for tests.
-- normalized_title / normalized_desc are a passthrough copy of the raw text
-- (M1 behaviour); M2's Normalizer will populate them canonically. Loading this
-- file requires db/schema.sql to have been applied first.

SET NAMES utf8mb4;

INSERT INTO products
    (product_id, title, description, normalized_title, normalized_desc,
     brand, category, model, price, stock, url, image, popularity)
VALUES
    (1001,
     'Apple iPhone 15 Pro Max 256GB',
     'Apple iPhone 15 Pro Max smartphone with 256GB storage and titanium body',
     'Apple iPhone 15 Pro Max 256GB',
     'Apple iPhone 15 Pro Max smartphone with 256GB storage and titanium body',
     'Apple', 'Mobile Phone', 'IP15PROMAX', 1299.0000, 12,
     '/product/iphone-15-pro-max', '/image/iphone15.jpg', 1000),

    (1002,
     'گوشی موبایل اپل آیفون ۱۵ پرو مکس',
     'گوشی موبایل اپل آیفون ۱۵ پرو مکس با حافظه ۲۵۶ گیگابایت و بدنه تیتانیوم',
     'گوشی موبایل اپل آیفون ۱۵ پرو مکس',
     'گوشی موبایل اپل آیفون ۱۵ پرو مکس با حافظه ۲۵۶ گیگابایت و بدنه تیتانیوم',
     'Apple', 'گوشی موبایل', 'IP15PROMAX', 1299.0000, 12,
     '/product/iphone-15-pro-max-fa', '/image/iphone15.jpg', 980),

    (1003,
     'Samsung Galaxy S24 Ultra',
     'Samsung Galaxy S24 Ultra smartphone with S Pen and 200MP camera',
     'Samsung Galaxy S24 Ultra',
     'Samsung Galaxy S24 Ultra smartphone with S Pen and 200MP camera',
     'Samsung', 'Mobile Phone', 'SM-S928', 1199.0000, 20,
     '/product/galaxy-s24-ultra', '/image/s24.jpg', 850),

    (1004,
     'گوشی سامسونگ گلکسی اس ۲۴ اولترا',
     'گوشی سامسونگ گلکسی اس ۲۴ اولترا با قلم اس پن و دوربین ۲۰۰ مگاپیکسل',
     'گوشی سامسونگ گلکسی اس ۲۴ اولترا',
     'گوشی سامسونگ گلکسی اس ۲۴ اولترا با قلم اس پن و دوربین ۲۰۰ مگاپیکسل',
     'Samsung', 'گوشی موبایل', 'SM-S928', 1199.0000, 20,
     '/product/galaxy-s24-ultra-fa', '/image/s24.jpg', 830),

    (1005,
     'ASUS VivoBook Laptop 15',
     'ASUS VivoBook 15 laptop with Intel Core i7 processor and 16GB RAM',
     'ASUS VivoBook Laptop 15',
     'ASUS VivoBook 15 laptop with Intel Core i7 processor and 16GB RAM',
     'ASUS', 'Laptop', 'X1504', 799.0000, 7,
     '/product/asus-vivobook-15', '/image/vivobook.jpg', 500),

    (1006,
     'لپ تاپ ایسوس ویووبوک ۱۵',
     'لپ تاپ ایسوس ویووبوک با پردازنده اینتل کور آی هفت و رم ۱۶ گیگابایت',
     'لپ تاپ ایسوس ویووبوک ۱۵',
     'لپ تاپ ایسوس ویووبوک با پردازنده اینتل کور آی هفت و رم ۱۶ گیگابایت',
     'ASUS', 'لپ تاپ', 'X1504', 799.0000, 7,
     '/product/asus-vivobook-15-fa', '/image/vivobook.jpg', 480),

    (1007,
     'Apple MacBook Air M2 13 inch',
     'Apple MacBook Air laptop with M2 chip and 13 inch Retina display',
     'Apple MacBook Air M2 13 inch',
     'Apple MacBook Air laptop with M2 chip and 13 inch Retina display',
     'Apple', 'Laptop', 'MLY33', 1099.0000, 5,
     '/product/macbook-air-m2', '/image/macbook.jpg', 700),

    (1008,
     'لپ تاپ اپل مک بوک ایر ام ۲',
     'لپ تاپ اپل مک بوک ایر با تراشه ام ۲ و نمایشگر ۱۳ اینچ رتینا',
     'لپ تاپ اپل مک بوک ایر ام ۲',
     'لپ تاپ اپل مک بوک ایر با تراشه ام ۲ و نمایشگر ۱۳ اینچ رتینا',
     'Apple', 'لپ تاپ', 'MLY33', 1099.0000, 5,
     '/product/macbook-air-m2-fa', '/image/macbook.jpg', 680),

    (1009,
     'Sony WH-1000XM5 Wireless Headphones',
     'Sony WH-1000XM5 noise cancelling wireless over-ear headphones',
     'Sony WH-1000XM5 Wireless Headphones',
     'Sony WH-1000XM5 noise cancelling wireless over-ear headphones',
     'Sony', 'Headphone', 'WH1000XM5', 349.0000, 30,
     '/product/sony-wh-1000xm5', '/image/sony-headphones.jpg', 600),

    (1010,
     'هدفون بی‌سیم سونی مدل WH-1000XM5',
     'هدفون بی‌سیم سونی با قابلیت حذف نویز و صدای فراگیر روی گوش',
     'هدفون بی‌سیم سونی مدل WH-1000XM5',
     'هدفون بی‌سیم سونی با قابلیت حذف نویز و صدای فراگیر روی گوش',
     'Sony', 'هدفون', 'WH1000XM5', 349.0000, 30,
     '/product/sony-wh-1000xm5-fa', '/image/sony-headphones.jpg', 560),

    (1011,
     'Sony Alpha A7 IV Mirrorless Camera',
     'Sony Alpha A7 IV full-frame mirrorless camera with 33MP sensor',
     'Sony Alpha A7 IV Mirrorless Camera',
     'Sony Alpha A7 IV full-frame mirrorless camera with 33MP sensor',
     'Sony', 'Camera', 'ILCE-7M4', 2499.0000, 4,
     '/product/sony-a7-iv', '/image/sony-a7.jpg', 300),

    (1012,
     'دوربین بدون آینه سونی آلفا A7 مارک ۴',
     'دوربین بدون آینه سونی آلفا فول‌فریم با سنسور ۳۳ مگاپیکسل',
     'دوربین بدون آینه سونی آلفا A7 مارک ۴',
     'دوربین بدون آینه سونی آلفا فول‌فریم با سنسور ۳۳ مگاپیکسل',
     'Sony', 'دوربین', 'ILCE-7M4', 2499.0000, 4,
     '/product/sony-a7-iv-fa', '/image/sony-a7.jpg', 280),

    (1013,
     'LG OLED TV 55 inch C3',
     'LG OLED evo C3 55 inch 4K smart TV with webOS',
     'LG OLED TV 55 inch C3',
     'LG OLED evo C3 55 inch 4K smart TV with webOS',
     'LG', 'Television', 'OLED55C3', 1499.0000, 9,
     '/product/lg-oled-c3-55', '/image/lg-oled.jpg', 400),

    (1014,
     'تلویزیون ال‌جی اولد ۵۵ اینچ مدل C3',
     'تلویزیون هوشمند ال‌جی اولد ۴K پنجاه و پنج اینچ با سیستم عامل وب او اس',
     'تلویزیون ال‌جی اولد ۵۵ اینچ مدل C3',
     'تلویزیون هوشمند ال‌جی اولد ۴K پنجاه و پنج اینچ با سیستم عامل وب او اس',
     'LG', 'تلویزیون', 'OLED55C3', 1499.0000, 9,
     '/product/lg-oled-c3-55-fa', '/image/lg-oled.jpg', 380);
