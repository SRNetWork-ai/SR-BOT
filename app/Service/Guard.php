<?php
declare(strict_types=1);

/**
 * Guard — لایهٔ سخت‌سازی امنیتی (نسخهٔ 0.0.2)
 *
 *  ۱) هدرهای امنیتی روی همهٔ ورودی‌های وب
 *  ۲) بررسی خودکار اینکه فایل‌های حساس از روی وب قابل خواندن هستند یا نه
 *  ۳) وضعیت پوشهٔ نصب (قفل نصاب)
 *  ۴) تشخیص آی‌پی واقعی کاربر (پایهٔ محدودیت نرخ در گام بعد)
 */
final class Guard
{
    /** مدت اعتبار نتیجهٔ بررسی افشای فایل‌ها (۶ ساعت) */
    public const EXPOSURE_TTL = 21600;

    private static bool $sent = false;

    /* ------------------------------------------------------- هدرهای امنیتی */

    /**
     * هدرهای امنیتی پایه.
     *
     * @param string $mode web = عمومی، panel = پنل مدیریت (بستن فریم)،
     *                     app = مینی‌اپ تلگرام (فریم باید باز بماند)
     */
    public static function headers(string $mode = 'web'): void
    {
        if (PHP_SAPI === 'cli' || self::$sent || headers_sent()) return;
        self::$sent = true;

        @header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
        header('X-Robots-Tag: noindex, nofollow');

        if ($mode === 'panel') {
            /* پنل مدیریت هرگز نباید داخل فریم سایت دیگری باز شود */
            header('X-Frame-Options: DENY');
            header("Content-Security-Policy: frame-ancestors 'none'");
            header('Cross-Origin-Opener-Policy: same-origin');
        }
        /* mode=app عمداً فریم را نمی‌بندد چون مینی‌اپ باید داخل تلگرام باز شود */
    }

    /* ------------------------------------------- افشای فایل‌های حساس روی وب */

    /** مسیرهایی که نباید از اینترنت قابل خواندن باشند */
    public static function sensitive(): array
    {
        return [
            'config.php'          => 'فایل پیکربندی (رمز دیتابیس و توکن ربات)',
            'database/schema.sql' => 'ساختار دیتابیس',
            'storage/logs/'       => 'پوشهٔ لاگ‌ها',
            'storage/backups/'    => 'پوشهٔ بکاپ‌ها',
        ];
    }

    /**
     * بررسی می‌کند کدام مسیر حساس از روی وب قابل خواندن است.
     * نتیجه ۶ ساعت کش می‌شود تا صفحهٔ سلامت کند نشود.
     *
     * @return array{at:int,base:string,open:array<string,string>,checked:int,error:string}
     */
    public static function exposure(bool $fresh = false): array
    {
        $file = self::tmp('sec-exposure.json');
        if (!$fresh && is_file($file)) {
            $j = json_decode((string)@file_get_contents($file), true);
            if (is_array($j) && (time() - (int)($j['at'] ?? 0)) < self::EXPOSURE_TTL) {
                $j['open'] = (array)($j['open'] ?? []);
                return $j;
            }
        }

        $base = '';
        if (function_exists('app_url')) $base = rtrim((string)app_url(''), '/');
        if ($base === '' && !empty($_SERVER['HTTP_HOST'])) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
            $base = ($https ? 'https://' : 'http://') . (string)$_SERVER['HTTP_HOST'];
        }

        $res = ['at' => time(), 'base' => $base, 'open' => [], 'checked' => 0, 'error' => ''];

        if ($base === '' || !function_exists('curl_init')) {
            $res['error'] = $base === '' ? 'no_base_url' : 'no_curl';
            self::writeJson($file, $res);
            return $res;
        }

        foreach (self::sensitive() as $path => $label) {
            $p = self::probe($base . '/' . ltrim($path, '/'));
            $res['checked']++;
            if ($p['open']) $res['open'][$path] = $label;
        }

        self::writeJson($file, $res);
        return $res;
    }

    /** یک درخواست کوتاه به آدرس می‌زند و می‌گوید محتوا برگشت یا نه */
    private static function probe(string $url): array
    {
        $ch = @curl_init($url);
        if (!$ch) return ['code' => 0, 'open' => false];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RANGE          => '0-2048',
            CURLOPT_USERAGENT      => 'SR-BOT-SecurityCheck',
        ]);

        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $open = false;
        if ($code === 200 || $code === 206) {
            $t = ltrim($body);
            /* اگر PHP فایل را اجرا کرده باشد بدنه خالی است؛ افشای واقعی یعنی محتوا برگردد */
            $open = $t !== '' && stripos($t, '<!doctype') !== 0 && stripos($t, '<html') !== 0;
            if (stripos($t, 'Index of /') !== false) $open = true;
        }

        return ['code' => $code, 'open' => $open];
    }

    /* ----------------------------------------------------------- نصاب */

    /** وضعیت پوشهٔ نصب روی سرور */
    public static function installer(): array
    {
        $root = self::root();
        return [
            'present'  => is_dir($root . '/install'),
            'unlocked' => is_file($root . '/storage/tmp/install.unlock'),
        ];
    }

    /* ------------------------------------------------------------ آی‌پی */

    /** آی‌پی واقعی کاربر (هدر پراکسی فقط از پراکسی مورد اعتماد پذیرفته می‌شود) */
    public static function clientIp(): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $trusted = function_exists('cfg') ? (array)cfg('app.trusted_proxies', []) : [];

        if ($ip !== '' && $trusted && in_array($ip, array_map('strval', $trusted), true)) {
            $fwd = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($fwd !== '') {
                $first = trim((string)explode(',', $fwd)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
            }
        }

        return $ip;
    }

    /* ----------------------------------------------------------- ابزار */

    private static function root(): string
    {
        return defined('APP_ROOT') ? (string)APP_ROOT : dirname(__DIR__, 2);
    }

    private static function tmp(string $name): string
    {
        $dir = self::root() . '/storage/tmp';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/' . $name;
    }

    private static function writeJson(string $file, array $data): void
    {
        @file_put_contents(
            $file,
            (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
