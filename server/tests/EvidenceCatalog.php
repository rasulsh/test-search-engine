<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * A small gaming catalog that reproduces the owner's live "test page" failures (M44): the products the seven
 * queries of the evidence table touched, plus enough games for "بازی" to be a generic word. Shared by
 * EvidenceQueriesTest and the before / after printout of the PR.
 */
final class EvidenceCatalog
{
    public const ITUNES_10 = 1;
    public const ITUNES_25 = 2;
    public const NINTENDO_10 = 3;
    public const NINTENDO_20 = 4;
    public const NINTENDO_50 = 5;
    public const ROUTER_AX6000 = 30;
    public const ROUTER_AXE11000 = 31;
    public const GTA_CAP_2 = 40;
    public const GTA_CAP_3 = 41;
    public const CONSOLE_PS4_SLIM = 50;
    public const CONSOLE_PS4_PRO = 51;
    public const CONSOLE_PS4_USED_SLIM = 52;
    public const CONSOLE_PS4_USED = 53;
    public const CONSOLE_XBOX_USED = 54;
    public const GAME_A_WAY_OUT = 60;
    public const GAME_RDR2_PS4 = 61;
    public const GAME_RDR2_XBOX = 62;
    public const MONITOR_ASUS = 70;
    public const MONITOR_LG = 71;
    public const MONITOR_SAMSUNG = 72;
    public const PHONE_XIAOMI = 73;

    /** @return list<array<string, mixed>> */
    public static function rows(): array
    {
        $rows = [
            self::row(self::ITUNES_10, 'گیفت کارت آیتونز ۱۰ دلاری', '', 'گیفت کارت', 50),
            self::row(self::ITUNES_25, 'گیفت کارت آیتونز ۲۵ دلاری', '', 'گیفت کارت', 45),
            self::row(self::NINTENDO_10, 'گیفت کارت نینتندو eShop ۱۰ دلاری', 'Nintendo', 'گیفت کارت', 90),
            self::row(self::NINTENDO_20, 'گیفت کارت نینتندو eShop ۲۰ دلاری', 'Nintendo', 'گیفت کارت', 85),
            self::row(self::NINTENDO_50, 'گیفت کارت نینتندو eShop ۵۰ دلاری', 'Nintendo', 'گیفت کارت', 80),
            self::row(6, 'گیفت کارت پلی استیشن ۱۰ دلاری', 'Sony', 'گیفت کارت', 70),
            self::row(7, 'گیفت کارت استیم ۱۰ دلاری', 'Valve', 'گیفت کارت', 75),
            self::row(
                self::ROUTER_AX6000,
                'روتر بی‌سیم ایسوس ROG Rapture GT-AX6000',
                'Asus',
                'روتر',
                95,
                'روتر دو باند مناسب بازی با ظرفیت 2 دستگاه همزمان و پورت 2.5 گیگابیت'
            ),
            self::row(self::ROUTER_AXE11000, 'روتر بی‌سیم ایسوس ROG Rapture GT-AXE11000', 'Asus', 'روتر', 93),
            self::row(self::GTA_CAP_2, 'اکانت قانونی بازی GTA V ظرفیت 2 پلی استیشن 4', '', 'اکانت بازی', 60),
            self::row(self::GTA_CAP_3, 'اکانت قانونی بازی GTA V ظرفیت 3 پلی استیشن 4', '', 'اکانت بازی', 55),
            self::row(
                self::CONSOLE_PS4_SLIM,
                'کنسول پلی استیشن 4 اسلیم یک ترابایت',
                'Sony',
                'کنسول',
                99,
                'همراه با دسته و بازی، راهنمای فارسی و خروجی HDMI'
            ),
            self::row(
                self::CONSOLE_PS4_PRO,
                'کنسول پلی استیشن 4 پرو یک ترابایت باندل بازی',
                'Sony',
                'کنسول',
                98,
                'بسته کامل با راهنما و خروجی 4K'
            ),
            self::row(self::CONSOLE_PS4_USED_SLIM, 'کنسول PS4 Slim کارکرده ۵۰۰ گیگابایت', 'Sony', 'کنسول', 40),
            self::row(self::CONSOLE_PS4_USED, 'کنسول پلی استیشن 4 کارکرده', 'Sony', 'کنسول', 35),
            self::row(self::CONSOLE_XBOX_USED, 'کنسول ایکس باکس وان کارکرده', 'Microsoft', 'کنسول', 30),
            self::row(self::GAME_A_WAY_OUT, 'بازی A Way Out راه خروج PS4', 'EA', 'بازی', 20),
            self::row(
                self::GAME_RDR2_PS4,
                'بازی Red Dead Redemption 2 PS4',
                'Rockstar',
                'بازی',
                88,
                '',
                'red dead redemption'
            ),
            self::row(
                self::GAME_RDR2_XBOX,
                'بازی Red Dead Redemption 2 ایکس باکس وان',
                'Rockstar',
                'بازی',
                70,
                '',
                'red dead redemption'
            ),
            self::row(self::MONITOR_ASUS, 'مانیتور گیمینگ ایسوس ۲۷ اینچ', 'Asus', 'مانیتور', 100),
            self::row(self::MONITOR_LG, 'مانیتور ال جی ۲۷ اینچ', 'LG', 'مانیتور', 90),
            self::row(self::MONITOR_SAMSUNG, 'مانیتور سامسونگ ۲۴ اینچ', 'Samsung', 'مانیتور', 80),
            self::row(self::PHONE_XIAOMI, 'گوشی موبایل شیائومی ردمی نوت', 'Xiaomi', 'موبایل', 85),
        ];
        // Enough games for "بازی" to be a generic word (more than 15% of the catalog).
        foreach (
            ['FIFA 24', 'Mortal Kombat 11', 'God of War', 'The Last of Us', 'Spider-Man', 'Horizon', 'Forza Horizon 5',
            'Halo Infinite', 'Elden Ring', 'Cyberpunk 2077', 'Resident Evil 4', 'Gran Turismo 7', 'Uncharted 4',
            'Bloodborne', 'Hogwarts Legacy'] as $i => $name
        ) {
            $rows[] = self::row(100 + $i, 'بازی ' . $name, 'Various', 'بازی', 10 + $i);
        }

        return $rows;
    }

    /** @return list<list<string>> the owner's alias groups the scenarios rely on */
    public static function aliases(): array
    {
        return [
            ['gta', 'grand theft auto', 'جی تی ای'],
            ['ps4', 'playstation 4', 'پلی استیشن 4'],
            ['Xiaomi', 'شیائومی', 'شیامی'],
        ];
    }

    /** @return array<string, mixed> */
    private static function row(
        int $id,
        string $title,
        string $brand,
        string $category,
        int $popularity,
        string $description = '',
        string $tags = ''
    ): array {
        return [
            'product_id' => $id, 'title' => $title, 'brand' => $brand, 'category' => $category,
            'description' => $description, 'tags' => $tags, 'stock' => 5, 'popularity' => $popularity,
        ];
    }
}
