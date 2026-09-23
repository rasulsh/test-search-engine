<?php

/**
 * First-deploy web installer (M14): extract release.zip, open this page, fill
 * in the form. Writes ../config.php from config.example.php, creates the schema
 * and loads the bundle staged in data_incoming/ (App\Installer). Does nothing
 * at all once config.php exists. UI text is Persian by request; code stays English.
 */

declare(strict_types=1);

use App\Installer;
use App\InstallerException;
use App\InstallPage;

$serverDir = dirname(__DIR__);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

// Refuse before bootstrap (which would load config.php): an installed service
// is never touched.
require $serverDir . '/src/InstallPage.php';
if (file_exists($serverDir . '/config.php') || is_link($serverDir . '/config.php')) {
    InstallPage::page(
        'سرویس قبلاً نصب شده است',
        '<div class="box ok">فایل <code>config.php</code> وجود دارد، پس نصب‌کننده هیچ تغییری نمی‌دهد. '
        . 'برای تغییر تنظیمات، خود <code>config.php</code> را ویرایش کنید.</div>'
        . InstallPage::doneHelp() . InstallPage::fallbackHelp(),
        403
    );
    return;
}

require $serverDir . '/bootstrap.php';

$schemaPath = is_file($serverDir . '/db/schema.sql')
    ? $serverDir . '/db/schema.sql'
    : dirname($serverDir) . '/db/schema.sql'; // repository checkout
$installer = new Installer($serverDir, $schemaPath);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    if ($method !== 'GET' && $method !== 'HEAD') {
        InstallPage::page('روش درخواست پشتیبانی نمی‌شود', '', 405);
        return;
    }
    InstallPage::form($installer->defaults(), []);
    return;
}

// Secrets are never echoed back: a re-shown form gets an empty password and a
// fresh token.
$redisplay = array_merge(
    $installer->defaults(),
    array_map(static fn ($v): string => is_string($v) ? $v : '', $_POST),
    ['reload_token' => $installer->defaults()['reload_token'], 'db_password' => '']
);

// The initial load can outlast default limits; a disconnecting browser must not
// abort it half-way. A fatal time/memory limit is still reported with the
// manual fallback instead of a blank page.
if (function_exists('ignore_user_abort')) {
    ignore_user_abort(true);
}
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}
$finished = false;
register_shutdown_function(static function () use (&$finished): void {
    $error = error_get_last();
    if ($finished || $error === null || !in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    InstallPage::page(
        'بارگذاری اولیه کامل نشد',
        '<div class="box error">اجرای نصب پیش از پایان متوقف شد، احتمالاً به‌دلیل محدودیت زمان یا حافظهٔ هاست. '
        . 'اگر <code>config.php</code> ساخته شده باشد، کاتالوگ را به روش دستی زیر بارگذاری کنید؛ وگرنه '
        . 'این صفحه را دوباره باز کنید.<pre>' . InstallPage::h(mb_substr($error['message'], 0, 300)) . '</pre></div>'
        . InstallPage::fallbackHelp(),
        500
    );
});

ob_start();
try {
    $result = $installer->install($_POST);
} catch (InstallerException $e) {
    $finished = true;
    ob_end_clean();
    $details = $e->details();
    switch ($e->reason()) {
        case 'invalid_input':
            InstallPage::form($redisplay, $details, InstallPage::errorBox('چند مقدار نیاز به اصلاح دارد.'));
            break;
        case 'already_installed':
            InstallPage::page('سرویس قبلاً نصب شده است', InstallPage::doneHelp(), 403);
            break;
        case 'bundle_mismatch':
            $bundle = $details['bundle'];
            InstallPage::form($redisplay, [], InstallPage::errorBox(
                'بستهٔ موجود در data_incoming با تنظیمات مدل این فرم نمی‌خواند. مقدارهای مدل را مطابق بسته وارد کنید:',
                "model: {$bundle['model_name']}\ndim: {$bundle['model_dim']}\n"
                . "normalization_version: {$bundle['normalization_version']}"
            ));
            break;
        case 'db_connect_failed':
            InstallPage::form($redisplay, [], InstallPage::errorBox(
                'اتصال به پایگاه داده برقرار نشد. میزبان، نام پایگاه داده، نام کاربری و رمز را بررسی کنید.',
                (string) ($details['error'] ?? '')
            ));
            break;
        case 'schema_failed':
            InstallPage::form($redisplay, [], InstallPage::errorBox(
                'ساخت جدول‌ها ناموفق بود. کاربر باید روی این پایگاه داده دسترسی کامل (ALL PRIVILEGES) داشته باشد.',
                (string) ($details['error'] ?? '')
            ));
            break;
        case 'config_write_failed':
            InstallPage::form($redisplay, [], InstallPage::errorBox(
                'نوشتن config.php ممکن نشد. دسترسی نوشتن در پوشهٔ سرویس (کنار config.example.php) را بررسی کنید.'
            ));
            break;
        default:
            InstallPage::form($redisplay, [], InstallPage::errorBox(
                'فایل config.example.php پیدا نشد یا با این نسخه سازگار نیست. بستهٔ انتشار را دوباره استخراج کنید.',
                $e->reason()
            ));
    }
    return;
}
$finished = true;
ob_end_clean();

if ($result['status'] === 'loaded') {
    InstallPage::page(
        'نصب کامل شد',
        '<div class="box ok">تنظیمات ذخیره شد، جدول‌ها ساخته شدند و '
        . InstallPage::faDigits((int) $result['count']) . ' محصول بارگذاری شد.</div>' . InstallPage::doneHelp()
    );
} elseif ($result['status'] === 'no_bundle') {
    InstallPage::page(
        'نصب انجام شد، کاتالوگی بارگذاری نشد',
        '<div class="box ok">تنظیمات ذخیره شد و جدول‌ها ساخته شدند، اما بسته‌ای در '
        . '<code>data_incoming/</code> نبود.</div><p>فایل release.zip را در پوشهٔ سرویس استخراج کنید و '
        . 'سپس بارگذاری را اجرا کنید:</p><pre>curl -sS -X POST -H "X-Reload-Token: RELOAD_TOKEN" '
        . '.../reload.php?load=1</pre>' . InstallPage::doneHelp()
    );
} else {
    $detail = $result['reason'] . ($result['details'] !== []
        ? "\n" . json_encode($result['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : '');
    InstallPage::page(
        'نصب انجام شد، بارگذاری کاتالوگ ناموفق بود',
        '<div class="box error">تنظیمات ذخیره شد و جدول‌ها ساخته شدند، اما بارگذاری اولیهٔ کاتالوگ انجام نشد. '
        . 'چیزی جابه‌جا نشده است.<pre>' . InstallPage::h(mb_substr($detail, 0, 500)) . '</pre></div>'
        . InstallPage::fallbackHelp() . InstallPage::doneHelp(),
        500
    );
}
