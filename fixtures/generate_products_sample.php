<?php

declare(strict_types=1);

/**
 * Regenerates fixtures/products.sample.sql from the raw catalog below, filling
 * normalized_title / normalized_desc via the real Normalizer. Run this whenever
 * the normalization rules change:
 *
 *   php fixtures/generate_products_sample.php
 *
 * ProductLoaderTest asserts the committed file stays consistent with the
 * Normalizer, so drift is caught in CI.
 */

require dirname(__DIR__) . '/server/bootstrap.php';

use App\Normalizer;

/** @var list<array<string, mixed>> $products */
$products = [
    ['product_id' => 1001, 'title' => 'Apple iPhone 15 Pro Max 256GB', 'description' => 'Apple iPhone 15 Pro Max smartphone with 256GB storage and titanium body', 'brand' => 'Apple', 'category' => 'Mobile Phone', 'model' => 'IP15PROMAX', 'price' => 1299.0, 'stock' => 12, 'url' => '/product/iphone-15-pro-max', 'image' => '/image/iphone15.jpg', 'popularity' => 1000],
    ['product_id' => 1002, 'title' => 'گوشی موبایل اپل آیفون ۱۵ پرو مکس', 'description' => 'گوشی موبایل اپل آیفون ۱۵ پرو مکس با حافظه ۲۵۶ گیگابایت و بدنه تیتانیوم', 'brand' => 'Apple', 'category' => 'گوشی موبایل', 'model' => 'IP15PROMAX', 'price' => 1299.0, 'stock' => 12, 'url' => '/product/iphone-15-pro-max-fa', 'image' => '/image/iphone15.jpg', 'popularity' => 980],
    ['product_id' => 1003, 'title' => 'Samsung Galaxy S24 Ultra', 'description' => 'Samsung Galaxy S24 Ultra smartphone with S Pen and 200MP camera', 'brand' => 'Samsung', 'category' => 'Mobile Phone', 'model' => 'SM-S928', 'price' => 1199.0, 'stock' => 20, 'url' => '/product/galaxy-s24-ultra', 'image' => '/image/s24.jpg', 'popularity' => 850],
    ['product_id' => 1004, 'title' => 'گوشی سامسونگ گلکسی اس ۲۴ اولترا', 'description' => 'گوشی سامسونگ گلکسی اس ۲۴ اولترا با قلم اس پن و دوربین ۲۰۰ مگاپیکسل', 'brand' => 'Samsung', 'category' => 'گوشی موبایل', 'model' => 'SM-S928', 'price' => 1199.0, 'stock' => 20, 'url' => '/product/galaxy-s24-ultra-fa', 'image' => '/image/s24.jpg', 'popularity' => 830],
    ['product_id' => 1005, 'title' => 'ASUS VivoBook Laptop 15', 'description' => 'ASUS VivoBook 15 laptop with Intel Core i7 processor and 16GB RAM', 'brand' => 'ASUS', 'category' => 'Laptop', 'model' => 'X1504', 'price' => 799.0, 'stock' => 7, 'url' => '/product/asus-vivobook-15', 'image' => '/image/vivobook.jpg', 'popularity' => 500],
    ['product_id' => 1006, 'title' => 'لپ تاپ ایسوس ویووبوک ۱۵', 'description' => 'لپ تاپ ایسوس ویووبوک با پردازنده اینتل کور آی هفت و رم ۱۶ گیگابایت', 'brand' => 'ASUS', 'category' => 'لپ تاپ', 'model' => 'X1504', 'price' => 799.0, 'stock' => 7, 'url' => '/product/asus-vivobook-15-fa', 'image' => '/image/vivobook.jpg', 'popularity' => 480],
    ['product_id' => 1007, 'title' => 'Apple MacBook Air M2 13 inch', 'description' => 'Apple MacBook Air laptop with M2 chip and 13 inch Retina display', 'brand' => 'Apple', 'category' => 'Laptop', 'model' => 'MLY33', 'price' => 1099.0, 'stock' => 5, 'url' => '/product/macbook-air-m2', 'image' => '/image/macbook.jpg', 'popularity' => 700],
    ['product_id' => 1008, 'title' => 'لپ تاپ اپل مک بوک ایر ام ۲', 'description' => 'لپ تاپ اپل مک بوک ایر با تراشه ام ۲ و نمایشگر ۱۳ اینچ رتینا', 'brand' => 'Apple', 'category' => 'لپ تاپ', 'model' => 'MLY33', 'price' => 1099.0, 'stock' => 5, 'url' => '/product/macbook-air-m2-fa', 'image' => '/image/macbook.jpg', 'popularity' => 680],
    ['product_id' => 1009, 'title' => 'Sony WH-1000XM5 Wireless Headphones', 'description' => 'Sony WH-1000XM5 noise cancelling wireless over-ear headphones', 'brand' => 'Sony', 'category' => 'Headphone', 'model' => 'WH1000XM5', 'price' => 349.0, 'stock' => 30, 'url' => '/product/sony-wh-1000xm5', 'image' => '/image/sony-headphones.jpg', 'popularity' => 600],
    ['product_id' => 1010, 'title' => 'هدفون بی‌سیم سونی مدل WH-1000XM5', 'description' => 'هدفون بی‌سیم سونی با قابلیت حذف نویز و صدای فراگیر روی گوش', 'brand' => 'Sony', 'category' => 'هدفون', 'model' => 'WH1000XM5', 'price' => 349.0, 'stock' => 30, 'url' => '/product/sony-wh-1000xm5-fa', 'image' => '/image/sony-headphones.jpg', 'popularity' => 560],
    ['product_id' => 1011, 'title' => 'Sony Alpha A7 IV Mirrorless Camera', 'description' => 'Sony Alpha A7 IV full-frame mirrorless camera with 33MP sensor', 'brand' => 'Sony', 'category' => 'Camera', 'model' => 'ILCE-7M4', 'price' => 2499.0, 'stock' => 4, 'url' => '/product/sony-a7-iv', 'image' => '/image/sony-a7.jpg', 'popularity' => 300],
    ['product_id' => 1012, 'title' => 'دوربین بدون آینه سونی آلفا A7 مارک ۴', 'description' => 'دوربین بدون آینه سونی آلفا فول‌فریم با سنسور ۳۳ مگاپیکسل', 'brand' => 'Sony', 'category' => 'دوربین', 'model' => 'ILCE-7M4', 'price' => 2499.0, 'stock' => 4, 'url' => '/product/sony-a7-iv-fa', 'image' => '/image/sony-a7.jpg', 'popularity' => 280],
    ['product_id' => 1013, 'title' => 'LG OLED TV 55 inch C3', 'description' => 'LG OLED evo C3 55 inch 4K smart TV with webOS', 'brand' => 'LG', 'category' => 'Television', 'model' => 'OLED55C3', 'price' => 1499.0, 'stock' => 9, 'url' => '/product/lg-oled-c3-55', 'image' => '/image/lg-oled.jpg', 'popularity' => 400],
    ['product_id' => 1014, 'title' => 'تلویزیون ال‌جی اولد ۵۵ اینچ مدل C3', 'description' => 'تلویزیون هوشمند ال‌جی اولد ۴K پنجاه و پنج اینچ با سیستم عامل وب او اس', 'brand' => 'LG', 'category' => 'تلویزیون', 'model' => 'OLED55C3', 'price' => 1499.0, 'stock' => 9, 'url' => '/product/lg-oled-c3-55-fa', 'image' => '/image/lg-oled.jpg', 'popularity' => 380],
];

$sql = <<<'HEADER'
-- Tiny bilingual (Persian + English) catalog for tests.
-- GENERATED by fixtures/generate_products_sample.php — do not edit by hand.
-- normalized_title / normalized_desc are produced by the canonical Normalizer
-- (contract 1). Loading this file requires db/schema.sql to be applied first.

SET NAMES utf8mb4;

INSERT INTO products
    (product_id, title, description, normalized_title, normalized_desc,
     brand, category, model, price, stock, url, image, popularity)
VALUES

HEADER;

$quote = static fn (string $value): string => "'" . str_replace("'", "''", $value) . "'";

$rows = [];
foreach ($products as $p) {
    $rows[] = sprintf(
        "    (%d, %s, %s, %s, %s, %s, %s, %s, %.4f, %d, %s, %s, %d)",
        $p['product_id'],
        $quote($p['title']),
        $quote($p['description']),
        $quote(Normalizer::normalize($p['title'])),
        $quote(Normalizer::normalize($p['description'])),
        $quote($p['brand']),
        $quote($p['category']),
        $quote($p['model']),
        $p['price'],
        $p['stock'],
        $quote($p['url']),
        $quote($p['image']),
        $p['popularity'],
    );
}

$sql .= implode(",\n", $rows) . ";\n";

file_put_contents(dirname(__DIR__) . '/fixtures/products.sample.sql', $sql);
echo 'Wrote ' . count($products) . " products to fixtures/products.sample.sql\n";
