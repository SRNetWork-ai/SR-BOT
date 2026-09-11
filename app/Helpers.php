<?php
declare(strict_types=1);

/**
 * توابع کمکی عمومی
 */

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function jenc($v): string { return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

function jdec(?string $v, $default = []) {
    if ($v === null || $v === '') return $default;
    $d = json_decode($v, true);
    return is_array($d) ? $d : $default;
}

/* ==================== امنیت ارتباطات (TLS) ==================== */

/**
 * آیا گواهی SSL سرورهای بیرونی بررسی شود؟
 * پیش‌فرض «بله». فقط در صورت اجبار با 'ssl_verify' => false در بخش app فایل config.php خاموش کنید.
 */
function app_ssl_verify(): bool
{
    static $v = null;
    if ($v === null) $v = function_exists('cfg') ? (bool)cfg('app.ssl_verify', true) : true;
    return $v;
}

/** گزینه‌های cURL مربوط به بررسی گواهی */
function curl_ssl_opts(?bool $force = null): array
{
    $on = $force === null ? app_ssl_verify() : $force;
    return [
        CURLOPT_SSL_VERIFYPEER => $on,
        CURLOPT_SSL_VERIFYHOST => $on ? 2 : 0,
    ];
}

/* ==================== رمزنگاری داده‌های حساس ==================== */

/** کلید رمزنگاری از config.php ساخته می‌شود */
function app_crypt_key(): string
{
    static $key = null;
    if ($key !== null) return $key;
    $raw = '';
    if (function_exists('cfg')) {
        $raw = (string)cfg('app.key', '');
        if ($raw === '') $raw = (string)cfg('bot.secret', '');
    }
    $key = $raw === '' ? '' : hash('sha256', 'sr-bot|' . $raw, true);
    return $key;
}

/** رمزنگاری AES-256-GCM – خروجی با پیشوند enc:v1: مشخص می‌شود */
function app_encrypt(string $plain): string
{
    if ($plain === '') return '';
    $key = app_crypt_key();
    if ($key === '' || !function_exists('openssl_encrypt')) return $plain;
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) return $plain;
    return 'enc:v1:' . base64_encode($iv . $tag . $ct);
}

/** رمزگشایی؛ مقدار رمزنشده بدون تغییر برمی‌گردد (سازگاری با نصب‌های قبلی) */
function app_decrypt(string $blob): string
{
    if ($blob === '' || strncmp($blob, 'enc:v1:', 7) !== 0) return $blob;
    $key = app_crypt_key();
    if ($key === '' || !function_exists('openssl_decrypt')) return '';
    $raw = base64_decode(substr($blob, 7), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $out === false ? '' : $out;
}

function rnd(int $len = 10, string $chars = 'abcdefghijklmnopqrstuvwxyz0123456789'): string {
    $out = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, $max)];
    return $out;
}

function uuidv4(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function gb2bytes($gb): int { return (int)round(((float)$gb) * 1024 * 1024 * 1024); }

function bytes2gb($b, int $p = 2): float { return round(((float)$b) / 1073741824, $p); }

function human_bytes($b): string {
    $b = (float)$b;
    if ($b <= 0) return '0';
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int)floor(log($b, 1024));
    $i = max(0, min($i, count($u) - 1));
    return round($b / pow(1024, $i), 2) . ' ' . $u[$i];
}

/** تبدیل اعداد انگلیسی به فارسی */
function fa_num($v): string {
    static $mode = null;
    if ($mode === null) {
        $mode = 'en';
        try {
            if (class_exists('DB') && DB::connected()) {
                $mode = (string)DB::setting('num_style', 'en') === 'fa' ? 'fa' : 'en';
            }
        } catch (Throwable $e) { $mode = 'en'; }
    }
    if ($mode === 'en') return en_num((string)$v);
    return str_replace(['0','1','2','3','4','5','6','7','8','9'], ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], (string)$v);
}

function en_num($v): string {
    return str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
        ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'], (string)$v);
}

function money($amount, bool $fa = true): string {
    $s = number_format((float)$amount);
    return $fa ? fa_num($s) : $s;
}

function now(): string { return date('Y-m-d H:i:s'); }

/** تبدیل تاریخ میلادی به شمسی */
function to_jalali(?string $datetime = null, bool $withTime = false): string {
    $ts = $datetime ? strtotime($datetime) : time();
    if ($ts === false) return '-';
    [$gy, $gm, $gd] = [(int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts)];
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100))
        + ((int)(($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * ((int)($days / 12053)));
    $days %= 12053;
    $jy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int)($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    $out = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    if ($withTime) $out .= ' ' . date('H:i', $ts);
    return fa_num($out);
}

/** فاصله زمانی تا یک تاریخ به صورت خوانا */
function remaining_human(?string $expireAt): string {
    if (!$expireAt) return 'نامحدود';
    $diff = strtotime($expireAt) - time();
    if ($diff <= 0) return 'منقضی شده';
    $days = (int)floor($diff / 86400);
    $hours = (int)floor(($diff % 86400) / 3600);
    if ($days > 0) return fa_num($days) . ' روز';
    if ($hours > 0) return fa_num($hours) . ' ساعت';
    return fa_num((int)floor($diff / 60)) . ' دقیقه';
}

function clean_text(?string $t, int $max = 3000): string {
    $t = trim((string)$t);
    $t = preg_replace("/[\x00-\x08\x0B\x0C\x0E-\x1F]/u", '', $t) ?? '';
    return mb_substr($t, 0, $max);
}

function app_log(string $channel, string $message, array $ctx = []): void {
    $dir = APP_ROOT . '/storage/logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $line = '[' . now() . "] [$channel] $message";
    if ($ctx) $line .= ' ' . jenc($ctx);
    @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND);
}

function http_json(string $url, array $data = [], string $method = 'GET', array $headers = [], int $timeout = 20): array {
    $ch = curl_init();
    if (strtoupper($method) === 'GET' && $data) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($data);
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => app_ssl_verify(),
        CURLOPT_SSL_VERIFYHOST => app_ssl_verify() ? 2 : 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ]);
    if (strtoupper($method) !== 'GET') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, jenc($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers));
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body, 'json' => jdec(is_string($body) ? $body : '', []), 'error' => $err];
}

/**
 * تقسیم متن بلند به تکه‌های امن (سقف پیام تلگرام ۴۰۹۶ کاراکتر)
 * تا جای ممکن از انتهای خط می‌شکند تا جمله نصفه نماند.
 */
if (!function_exists('str_split_unicode_safe')) {
    function str_split_unicode_safe(string $text, int $max = 3600): array
    {
        $text = trim($text);
        if ($text === '') return [''];
        if (mb_strlen($text) <= $max) return [$text];

        $out = [];
        while (mb_strlen($text) > $max) {
            $chunk = mb_substr($text, 0, $max);
            $cut   = mb_strrpos($chunk, "\n");
            if ($cut === false || $cut < (int)($max * 0.5)) $cut = $max;
            $out[] = rtrim(mb_substr($text, 0, $cut));
            $text  = ltrim(mb_substr($text, $cut));
        }
        if ($text !== '') $out[] = $text;
        return $out;
    }
}


/* ==========================================================
 *  تشخیص خودکار آدرس پایهٔ برنامه
 *  نام پوشه هرچه باشد (حتی فارسی، دارای فاصله یا تودرتو)
 *  آدرس درست ساخته می‌شود. از هر نقطهٔ ورودی هم کار می‌کند:
 *  index.php ، admin/index.php ، miniapp/api.php ، install/index.php
 * ========================================================== */

if (!function_exists('app_detect_scheme')) {
    function app_detect_scheme(): string
    {
        $cf = strtolower((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''));
        if ($cf !== '' && strpos($cf, '"https"') !== false) return 'https';

        $xf = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($xf !== '') {
            $xf = trim((string)(explode(',', $xf)[0] ?? ''));
            if ($xf === 'https' || $xf === 'http') return $xf;
        }

        if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') return 'https';

        $h = (string)($_SERVER['HTTPS'] ?? '');
        if ($h !== '' && strtolower($h) !== 'off') return 'https';

        if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return 'https';

        return 'http';
    }
}

if (!function_exists('app_detect_host')) {
    function app_detect_host(): string
    {
        $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '');
        if ($host !== '') $host = trim((string)(explode(',', $host)[0] ?? ''));
        if ($host === '') $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') $host = (string)($_SERVER['SERVER_NAME'] ?? '');

        $host = trim($host);
        if ($host === '') return '';

        /* فقط کاراکترهای مجاز نام میزبان (شامل IPv6 و پورت) */
        if (!preg_match('/^[A-Za-z0-9\.\-\_\[\]\:]+$/', $host)) return '';

        /* حذف پورت پیش‌فرض */
        $scheme = app_detect_scheme();
        if ($scheme === 'https' && substr($host, -4) === ':443') $host = substr($host, 0, -4);
        if ($scheme === 'http'  && substr($host, -3) === ':80')  $host = substr($host, 0, -3);

        return $host;
    }
}

if (!function_exists('app_detect_base_url')) {
    function app_detect_base_url(): string
    {
        if (PHP_SAPI === 'cli') return '';

        $host = app_detect_host();
        if ($host === '') return '';

        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script === '') $script = str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? ''));
        if ($script === '') return '';

        /*
         * چند پوشه از فایل در حال اجرا تا ریشهٔ برنامه فاصله داریم؟
         * با مقایسهٔ SCRIPT_FILENAME و APP_ROOT به‌دست می‌آید، بنابراین
         * به نام پوشه یا عمق نصب هیچ وابستگی‌ای ندارد.
         */
        $file = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $root = rtrim(str_replace('\\', '/', defined('APP_ROOT') ? APP_ROOT : ''), '/');
        $up   = 0;

        if ($file !== '' && $root !== '' && strpos($file, $root . '/') === 0) {
            $rel = trim(substr($file, strlen($root)), '/');
            $up  = substr_count($rel, '/');
        } else {
            /* پشتیبان: از روی نام پوشه‌های شناخته‌شده */
            $d1 = strtolower(basename(dirname($script)));
            $d2 = strtolower(basename(dirname(dirname($script))));
            if (in_array($d1, ['admin', 'install', 'miniapp', 'tools', 'cron', 'api'], true)) $up = 1;
            if (in_array($d2, ['admin', 'miniapp'], true)) $up = 2;
        }

        $dir = $script;
        for ($i = 0; $i <= $up; $i++) $dir = str_replace('\\', '/', dirname($dir));
        if ($dir === '/' || $dir === '.' || $dir === '\\') $dir = '';

        /* کدگذاری هر بخش مسیر تا نام‌های فارسی یا دارای فاصله هم معتبر بمانند */
        if ($dir !== '') {
            $segs = [];
            foreach (explode('/', trim($dir, '/')) as $s) {
                if ($s === '') continue;
                $segs[] = rawurlencode(rawurldecode($s));
            }
            $dir = $segs ? '/' . implode('/', $segs) : '';
        }

        return app_detect_scheme() . '://' . $host . $dir;
    }
}
