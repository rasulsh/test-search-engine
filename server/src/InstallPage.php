<?php

declare(strict_types=1);

namespace App;

/**
 * HTML for public/install.php (M14): a self-contained RTL Persian page (UI
 * text is Persian by request; code stays English). Values are always escaped;
 * the DB password is never rendered back.
 */
final class InstallPage
{
    public static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function faDigits(int|string $value): string
    {
        return strtr((string) $value, array_combine(str_split('0123456789'), mb_str_split('۰۱۲۳۴۵۶۷۸۹')));
    }

    public static function page(string $title, string $body, int $status = 200): void
    {
        http_response_code($status);
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<meta name="robots" content="noindex, nofollow">',
            '<title>', self::h($title), '</title><style>',
            "@font-face{font-family:'Vazirmatn';src:url('./client/fonts/Vazirmatn-Variable.woff2') format('woff2');",
            'font-weight:100 900;font-display:swap}',
            ':root{--bg:#fff;--text:#202124;--muted:#5f6368;--border:#dfe1e5;--accent:#1a73e8;',
            '--error-bg:#fce8e6;--error-text:#a50e0e;--ok-bg:#e6f4ea;--ok-text:#137333}',
            '@media (prefers-color-scheme:dark){:root{--bg:#202124;--text:#e8eaed;--muted:#9aa0a6;',
            '--border:#5f6368;--accent:#8ab4f8;--error-bg:#3c1e1e;--error-text:#f28b82;',
            '--ok-bg:#1e3a2a;--ok-text:#81c995}}',
            '*{box-sizing:border-box}',
            "body{margin:0;background:var(--bg);color:var(--text);font:15px/1.7 'Vazirmatn',Tahoma,sans-serif}",
            'main{max-width:720px;margin:0 auto;padding:24px 16px 48px}',
            'fieldset{border:1px solid var(--border);border-radius:8px;margin:0 0 16px;padding:8px 16px 16px}',
            'legend{font-weight:700;padding:0 6px}',
            'label{display:block;margin-top:12px;font-weight:600}',
            'input{width:100%;padding:8px 10px;margin-top:4px;border:1px solid var(--border);border-radius:6px;',
            'background:var(--bg);color:var(--text);font:inherit}',
            '.hint{color:var(--muted);font-size:13px;margin:2px 0 0}',
            '.field-error{color:var(--error-text);font-size:13px;margin:2px 0 0}',
            '.box{border-radius:8px;padding:12px 16px;margin:0 0 16px}',
            '.error{background:var(--error-bg);color:var(--error-text)}',
            '.ok{background:var(--ok-bg);color:var(--ok-text)}',
            'button{background:var(--accent);color:#fff;border:0;border-radius:6px;padding:10px 24px;',
            'font:inherit;font-weight:700;cursor:pointer}',
            'pre,code{direction:ltr;unicode-bidi:embed;font-family:ui-monospace,Consolas,monospace;font-size:13px}',
            'pre{text-align:left;background:rgba(127,127,127,.12);padding:10px;border-radius:6px;overflow-x:auto}',
            'a{color:var(--accent)}',
            '</style></head><body><main><h1>', self::h($title), '</h1>', $body, '</main></body></html>';
    }

    /** Manual catalog load, for when the in-PHP load cannot finish on this host. */
    public static function fallbackHelp(): string
    {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
        $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'shop.example.com')
            . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/install.php')), '/');

        return '<h2>بارگذاری دستی کاتالوگ</h2>'
            . '<p>اگر بارگذاری اولیه به محدودیت زمان یا حافظهٔ هاست خورد، فایل '
            . '<code>data_incoming/products.load.sql</code> (در پوشهٔ سرویس) را خودتان در پایگاه داده '
            . 'وارد کنید. با SSH:</p>'
            . '<pre>mysql -u DB_USER -p DB_NAME &lt; data_incoming/products.load.sql</pre>'
            . '<p>بدون SSH، همین فایل را در phpMyAdmin با گزینهٔ Import وارد کنید (فشرده‌شده با gzip '
            . 'حجم کمتری دارد). سپس جابه‌جایی را بدون <code>?load=1</code> اجرا کنید:</p>'
            . '<pre>curl -sS -X POST -H "X-Reload-Token: RELOAD_TOKEN" ' . self::h($base) . '/reload.php</pre>'
            . '<p>RELOAD_TOKEN همان توکنی است که هنگام نصب ثبت کردید (در <code>config.php</code> هم هست). '
            . 'برای امتحان دوبارهٔ بارگذاری خودکار، همین دستور را با <code>reload.php?load=1</code> اجرا کنید.</p>';
    }

    public static function doneHelp(): string
    {
        return '<p><strong>اکنون فایل <code>install.php</code> را از سرور حذف کنید.</strong> '
            . 'تا وقتی <code>config.php</code> وجود دارد، این صفحه هیچ کاری انجام نمی‌دهد.</p>'
            . '<p>وضعیت سرویس: <a href="./health.php">health.php</a> · '
            . 'صفحهٔ آزمایش جستجو: <a href="./test.html">test.html</a></p>';
    }

    private const FIELDS = [
        'پایگاه داده' => [
            'db_host' => ['میزبان', 'معمولاً localhost'],
            'db_port' => ['پورت', 'خالی بگذارید تا پورت پیش‌فرض (۳۳۰۶) استفاده شود.'],
            'db_name' => ['نام پایگاه داده', 'در cPanel با پیشوند نام کاربری حساب، مثل cpuser_search'],
            'db_user' => ['نام کاربری', 'کاربری که روی این پایگاه داده دسترسی کامل (ALL PRIVILEGES) دارد.'],
            'db_password' => ['رمز عبور', ''],
        ],
        'امنیت' => [
            'reload_token' => [
                'توکن بارگذاری مجدد',
                'یک مقدار تصادفی تازه که برای به‌روزرسانی‌های بعدی (reload.php) لازم است. پیش از ثبت، آن را '
                . 'جایی امن نگه دارید؛ بعد از نصب دیگر نمایش داده نمی‌شود. دست‌کم ۳۲ نویسه و بدون فاصله.',
            ],
        ],
        'مدل' => [
            'model_name' => ['نام مدل', 'باید با مدلی که بسته با آن ساخته شده یکی باشد.'],
            'model_dim' => ['بُعد بردار', ''],
            'normalization_version' => [
                'نسخهٔ نرمال‌سازی',
                'مقدارهای پیش‌فرض را فقط وقتی تغییر دهید که مدل عوض شده باشد.',
            ],
        ],
        'فروشگاه' => [
            'store_base' => [
                'نشانی فروشگاه (STORE_BASE)',
                'مثل https://shop.example.com/ ؛ اگر خالی بماند، پیوند محصولات همان‌طور که صادر شده‌اند برمی‌گردند.',
            ],
            'image_base' => ['نشانی تصاویر (IMAGE_BASE)', 'مثل https://shop.example.com/image/'],
        ],
        'تنظیمات جستجو (مقدارهای پیش‌فرض معمولاً مناسب‌اند)' => [
            'semantic_min_score' => ['حداقل امتیاز معنایی', 'بین ۱- و ۱'],
            'title_weight' => ['وزن عنوان', ''],
            'desc_weight' => ['وزن توضیحات', ''],
            'spec_weight' => ['وزن مشخصات', ''],
            'phrase_bonus' => ['امتیاز عبارت پیوسته', ''],
        ],
    ];

    private const INVALID = [
        'db_host' => 'فقط حروف انگلیسی، عدد، نقطه و خط تیره.',
        'db_port' => 'عددی بین ۱ و ۶۵۵۳۵.',
        'db_name' => 'فقط حروف انگلیسی، عدد، _ و خط تیره.',
        'reload_token' => 'دست‌کم ۳۲ نویسهٔ انگلیسی، بدون فاصله.',
        'store_base' => 'نشانی کامل با http:// یا https://',
        'image_base' => 'نشانی کامل با http:// یا https://',
    ];

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    public static function form(array $values, array $errors, string $message = ''): void
    {
        $body = $message;
        $body .= '<p>مقدارها را وارد کنید. نصب‌کننده اتصال به پایگاه داده را می‌آزماید، '
            . '<code>config.php</code> را می‌نویسد، جدول‌ها را می‌سازد و کاتالوگِ موجود در '
            . '<code>data_incoming/</code> را بارگذاری می‌کند.</p><form method="post" autocomplete="off">';
        foreach (self::FIELDS as $legend => $fields) {
            $body .= '<fieldset><legend>' . self::h($legend) . '</legend>';
            foreach ($fields as $name => [$label, $hint]) {
                $type = $name === 'db_password' ? 'password' : 'text';
                $value = $name === 'db_password' ? '' : ($values[$name] ?? '');
                $body .= '<label for="' . $name . '">' . self::h($label) . '</label>'
                    . '<input dir="ltr" type="' . $type . '" id="' . $name . '" name="' . $name
                    . '" value="' . self::h($value) . '" spellcheck="false">';
                if ($hint !== '') {
                    $body .= '<p class="hint">' . self::h($hint) . '</p>';
                }
                if (isset($errors[$name])) {
                    $text = $errors[$name] === 'required'
                        ? 'این مقدار لازم است.'
                        : (self::INVALID[$name] ?? 'مقدار معتبر نیست.');
                    $body .= '<p class="field-error">' . self::h($text) . '</p>';
                }
            }
            $body .= '</fieldset>';
        }
        $body .= '<button type="submit">نصب</button></form>';
        self::page('نصب سرویس جستجو', $body, $errors === [] && $message === '' ? 200 : 422);
    }

    public static function errorBox(string $text, string $detail = ''): string
    {
        return '<div class="box error">' . self::h($text)
            . ($detail !== '' ? '<pre>' . self::h(mb_substr($detail, 0, 500)) . '</pre>' : '') . '</div>';
    }
}
