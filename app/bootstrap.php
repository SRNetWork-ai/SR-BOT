<?php
declare(strict_types=1);

/**
 * راه‌انداز اصلی برنامه
 */

if (!defined('APP_ROOT')) define('APP_ROOT', dirname(__DIR__));
/** نسخه از version.json خوانده می‌شود تا همیشه با به‌روزرسانی‌ها هماهنگ باشد */
define('APP_VERSION', (static function (): string {
    $f = APP_ROOT . '/version.json';
    if (is_file($f)) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j) && !empty($j['version'])) return (string)$j['version'];
    }
    return '0.0.0';
})());
define('APP_BRAND', 'SR-BOT');
define('APP_COPYRIGHT', '© این سورس متعلق به مجموعهٔ SR-BOT است – هرگونه فروش، بازفروش یا سوءاستفاده ممنوع است.');

mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require APP_ROOT . '/app/Helpers.php';

/** بارگذاری خودکار کلاس‌ها */
spl_autoload_register(function (string $class): void {
    $paths = [
        APP_ROOT . '/app/' . $class . '.php',
        APP_ROOT . '/app/Bot/' . $class . '.php',
        APP_ROOT . '/app/Panel/' . $class . '.php',
        APP_ROOT . '/app/Service/' . $class . '.php',
    ];
    foreach ($paths as $p) {
        if (is_file($p)) { require_once $p; return; }
    }
});

/* ------------------------------------------------ لاگ یکپارچه خطاها */

function app_report_error(string $kind, string $msg, string $file = '', int $line = 0): void
{
    $where = $file !== '' ? (basename($file) . ':' . $line) : '-';
    app_log('error', $kind . ' | ' . $msg . ' | ' . $where);

    try {
        if (class_exists('DB', false) && DB::connected() && class_exists('Logs')) {
            Logs::send('errors', Logs::fmt('⛔️ خطای سیستمی', [
                'نوع'  => $kind,
                'پیام' => mb_substr($msg, 0, 300),
                'محل'  => $where,
                'زمان' => date('Y-m-d H:i:s'),
            ]));
        }
    } catch (Throwable $e) {
        /* گزارش خطا نباید خودش باعث خطا شود */
    }
}

/* ------------------------------------------------------------------
 * مهم: بعضی ورودی‌های برنامه (مثل miniapp/api.php) پیش از require شدنِ این
 * فایل، هندلر مخصوص خودشان را نصب می‌کنند تا خروجی خطا هم JSON باشد.
 * اگر اینجا آن هندلرها را بازنویسی کنیم، هر استثنا فقط لاگ می‌شود و پاسخ با
 * کد 500 و بدنهٔ خالی برمی‌گردد؛ آن‌وقت مینی‌اپ و پنل نماینده پیام
 * «پاسخ سرور معتبر نبود (کد 500)» و «اجازهٔ دسترسی نیست» را نشان می‌دهند.
 * پس هندلر قبلی را نگه می‌داریم و بعد از لاگ‌کردن، آن را هم صدا می‌زنیم.
 * ------------------------------------------------------------------ */

$__prevExceptionHandler = set_exception_handler(static function (Throwable $e): void {
    app_report_error('exception', $e->getMessage(), $e->getFile(), $e->getLine());
});

if (is_callable($__prevExceptionHandler)) {
    set_exception_handler(static function (Throwable $e) use ($__prevExceptionHandler): void {
        app_report_error('exception', $e->getMessage(), $e->getFile(), $e->getLine());
        /* هندلر ورودی برنامه خروجی مناسب (مثلاً JSON) را تولید می‌کند */
        $__prevExceptionHandler($e);
    });
}
unset($__prevExceptionHandler);

$__prevErrorHandler = set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $no)) return true;
    if (in_array($no, [E_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, E_USER_NOTICE, E_WARNING, E_USER_WARNING], true)) {
        app_log('php', $str . ' @ ' . basename($file) . ':' . $line);
        return true;
    }
    app_report_error('php_error', $str, $file, $line);
    return true;
});

if (is_callable($__prevErrorHandler)) {
    set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0) use ($__prevErrorHandler) {
        if (!(error_reporting() & $no)) return true;
        if (in_array($no, [E_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, E_USER_NOTICE, E_WARNING, E_USER_WARNING], true)) {
            app_log('php', $str . ' @ ' . basename($file) . ':' . $line);
            return true;
        }
        app_report_error('php_error', $str, $file, $line);
        /* تصمیم نهایی با هندلر ورودی برنامه است (مثلاً تبدیل به ErrorException) */
        return $__prevErrorHandler($no, $str, $file, $line);
    });
}
unset($__prevErrorHandler);

register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e && in_array((int)$e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        app_report_error('fatal', (string)$e['message'], (string)$e['file'], (int)$e['line']);
    }
});

function cfg(?string $key = null, $default = null, bool $reload = false)
{
    static $config = null;
    if ($config === null || $reload) {
        $file = APP_ROOT . '/config.php';
        $config = is_file($file) ? (array)require $file : [];
    }
    if ($key === null) return $config;
    $ref = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($ref) || !array_key_exists($part, $ref)) return $default;
        $ref = $ref[$part];
    }
    return $ref;
}

function app_installed(): bool { return is_file(APP_ROOT . '/config.php') && (bool)cfg('installed', false); }

function boot(bool $requireInstall = true): void
{
    static $booted = false;
    if ($booted) return;

    date_default_timezone_set((string)cfg('app.timezone', 'Asia/Tehran'));

    if (!app_installed()) {
        if (!$requireInstall) { $booted = true; return; }
        if (PHP_SAPI === 'cli') { fwrite(STDERR, "برنامه نصب نشده است. ابتدا نصاب را اجرا کنید.\n"); exit(1); }
        header('Location: install/index.php');
        exit;
    }

    DB::init((array)cfg('db', []));
    Tg::setToken((string)cfg('bot.token', ''));
    DB::loadSettings();
    $booted = true;

    /*
     * اگر نام یا مسیر پوشهٔ برنامه عوض شده باشد، آدرس ذخیره‌شده
     * خودکار اصلاح و وب‌هوک دوباره تنظیم می‌شود؛ بنابراین تغییر نام
     * پوشه دیگر باعث بی‌پاسخ شدن ربات نمی‌شود.
     */
    if (app_sync_url()) {
        try {
            if (rsbot_id() === 0 && (string)cfg('bot.token', '') !== '') {
                Tg::setToken((string)cfg('bot.token', ''));
                Tg::setWebhook(app_url('index.php'), (string)cfg('bot.secret', ''));
                app_log('app', 'webhook re-pointed to ' . app_url('index.php'));
            }
        } catch (Throwable $e) {
            app_log('app', 'webhook re-set failed: ' . $e->getMessage());
        }
    }
}

/** آیا این آی‌دی عددی تلگرام ادمین است؟ */
function is_admin_id($tgId): bool
{
    /* در بستر ربات اختصاصی نماینده، فقط مالک همان ربات مدیر است */
    $ctx = $GLOBALS['RSBOT'] ?? null;
    if (is_array($ctx) && !empty($ctx['owner_id'])) {
        return (string)$tgId === (string)$ctx['owner_id'];
    }

    $ids = (array)cfg('bot.admins', []);
    $extra = (string)DB::setting('extra_admins', '');
    if ($extra !== '') $ids = array_merge($ids, array_map('trim', explode(',', $extra)));
    return in_array((string)$tgId, array_map('strval', $ids), true);
}

/** ردیف ربات نمایندهٔ جاری (فقط در rbot.php پر می‌شود) */
function rsbot_ctx(): ?array
{
    $ctx = $GLOBALS['RSBOT'] ?? null;
    return is_array($ctx) && !empty($ctx['id']) ? $ctx : null;
}

/** شناسهٔ ربات نمایندهٔ جاری؛ ۰ = ربات اصلی */
function rsbot_id(): int
{
    $ctx = rsbot_ctx();
    return $ctx ? (int)$ctx['id'] : 0;
}

function currency(): string { return (string)DB::setting('currency', (string)cfg('app.currency', 'تومان')); }

function app_url(string $path = ''): string
{
    return rtrim((string)cfg('app.url', ''), '/') . '/' . ltrim($path, '/');
}

/**
 * همگام‌سازی خودکار app.url با محل واقعی فایل‌ها.
 * فقط وقتی دامنه یکسان باشد اصلاح می‌کند (یعنی صرفاً نام/مسیر پوشه عوض شده)
 * تا اگر سایت با چند دامنه در دسترس است، تنظیمات به‌هم نریزد.
 *
 * @return bool آیا آدرس اصلاح شد؟
 */
function app_sync_url(): bool
{
    static $done = false;
    if ($done || PHP_SAPI === 'cli') return false;
    $done = true;

    if (!function_exists('app_detect_base_url')) return false;

    $live = rtrim((string)app_detect_base_url(), '/');
    if ($live === '') return false;

    $saved = rtrim((string)cfg('app.url', ''), '/');
    if ($saved === '' || $saved === $live) return false;

    $a = parse_url($saved, PHP_URL_HOST);
    $b = parse_url($live, PHP_URL_HOST);
    if (!$a || !$b || strcasecmp((string)$a, (string)$b) !== 0) return false;

    if (!class_exists('Cfg')) return false;

    $w = Cfg::set(['app.url' => $live]);
    if (empty($w['ok'])) {
        app_log('app', 'app.url auto-correct failed: ' . (string)($w['message'] ?? ''));
        return false;
    }

    cfg(null, null, true);

    /* آدرس‌های دستی مینی‌اپ هم با مسیر تازه هماهنگ می‌شوند */
    try {
        if (class_exists('DB') && DB::connected()) {
            foreach (['miniapp_url', 'rs_app_url', 'card_app_url'] as $k) {
                $old = trim((string)DB::setting($k, ''));
                if ($old !== '' && strpos($old, $saved) === 0) {
                    DB::setSetting($k, $live . substr($old, strlen($saved)));
                }
            }
        }
    } catch (Throwable $e) {
        app_log('app', 'miniapp url sync failed: ' . $e->getMessage());
    }

    app_log('app', 'app.url auto-corrected: ' . $saved . ' -> ' . $live);
    return true;
}
