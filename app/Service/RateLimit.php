<?php
declare(strict_types=1);

/**
 * RateLimit — محدودیت نرخ درخواست و قفل ضد حدس رمز (نسخهٔ 0.0.2)
 *
 * بدون نیاز به جدول تازه کار می‌کند (شمارنده‌ها در storage/tmp/rl ذخیره می‌شوند)
 * تا روی هاست اشتراکی هم سبک باشد.
 *
 * نکتهٔ مهم: وب‌هوک تلگرام هرگز محدود نمی‌شود و مینی‌اپ هم براساس IP
 * محدود نمی‌شود (اپراتورهای موبایل ایران آی‌پی مشترک می‌دهند)؛ محدودیت مینی‌اپ
 * در گام بعد براساس شناسهٔ کاربر تلگرام اعمال می‌شود.
 */
final class RateLimit
{
    /** سقف درخواست پنل مدیریت در هر دقیقه برای هر آی‌پی */
    public const PANEL_PER_MIN = 300;

    /** تعداد تلاش ناموفق ورود پیش از قفل شدن */
    public const LOGIN_TRIES = 5;

    /** مدت قفل پس از تلاش‌های ناموفق (ثانیه) */
    public const LOGIN_LOCK = 900;

    /* ------------------------------------------------------- شمارندهٔ نرخ */

    /**
     * یک واحد به شمارنده اضافه می‌کند و می‌گوید اجازه هست یا نه.
     *
     * @return array{ok:bool,count:int,retry:int}
     */
    public static function hit(string $key, int $limit, int $window = 60): array
    {
        $now  = time();
        $file = self::file($key);
        $data = self::read($file);

        if ((int)($data['start'] ?? 0) + $window <= $now) {
            $data = ['start' => $now, 'count' => 0];
        }

        $data['count'] = (int)($data['count'] ?? 0) + 1;
        self::write($file, $data);

        $retry = max(1, (int)$data['start'] + $window - $now);
        return [
            'ok'    => $data['count'] <= $limit,
            'count' => (int)$data['count'],
            'retry' => $retry,
        ];
    }

    /** فقط خواندن شمارنده بدون افزایش */
    public static function count(string $key, int $window = 60): int
    {
        $data = self::read(self::file($key));
        if ((int)($data['start'] ?? 0) + $window <= time()) return 0;
        return (int)($data['count'] ?? 0);
    }

    public static function reset(string $key): void
    {
        @unlink(self::file($key));
    }

    /* --------------------------------------------------- قفل ورود به پنل */

    /** ثبت یک تلاش ناموفق ورود؛ خروجی = ثانیهٔ باقی‌ماندهٔ قفل (0 = باز) */
    public static function loginFail(string $who): int
    {
        $r = self::hit('login:' . $who, self::LOGIN_TRIES, self::LOGIN_LOCK);
        if (!empty($r['ok'])) return 0;
        return (int)$r['retry'];
    }

    /** آیا ورود برای این آی‌پی/کاربر قفل است؟ (ثانیهٔ باقی‌مانده) */
    public static function loginLocked(string $who): int
    {
        $data = self::read(self::file('login:' . $who));
        $left = (int)($data['start'] ?? 0) + self::LOGIN_LOCK - time();
        if ($left <= 0) return 0;
        return (int)($data['count'] ?? 0) > self::LOGIN_TRIES ? $left : 0;
    }

    /** پس از ورود موفق صدا زده می‌شود */
    public static function loginOk(string $who): void
    {
        self::reset('login:' . $who);
    }

    /* ------------------------------------------------------ نگهبان وب */

    /** محدودیت عمومی درخواست‌های پنل مدیریت */
    public static function guardWeb(): void
    {
        if (PHP_SAPI === 'cli') return;

        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        if (strpos($script, '/admin/') === false) return;   // وب‌هوک و مینی‌اپ محدود نمی‌شوند

        $ip = class_exists('Guard') ? Guard::clientIp() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') return;

        $r = self::hit('web:panel:' . $ip, self::PANEL_PER_MIN, 60);
        if (!empty($r['ok'])) return;

        $retry = (int)$r['retry'];
        if (function_exists('app_log')) app_log('sec', 'rate limited panel ip=' . $ip . ' retry=' . $retry);

        http_response_code(429);
        if (!headers_sent()) {
            header('Retry-After: ' . $retry);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!doctype html><meta charset="utf-8"><title>درخواست بیش از حد</title>'
            . '<div style="font:15px/2.1 Tahoma,sans-serif;direction:rtl;max-width:520px;margin:60px auto;padding:24px;'
            . 'border:1px solid #ddd;border-radius:14px;text-align:center">'
            . '<h2 style="margin:0 0 10px">⏳ درخواست بیش از حد</h2>'
            . '<p>تعداد درخواست‌های شما در یک دقیقه زیاد بود. لطفاً '
            . (int)$retry . ' ثانیه دیگر دوباره تلاش کنید.</p></div>';
        exit;
    }

    /* ----------------------------------------------------------- ابزار */

    private static function dir(): string
    {
        $root = defined('APP_ROOT') ? (string)APP_ROOT : dirname(__DIR__, 2);
        $dir  = $root . '/storage/tmp/rl';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }

    private static function file(string $key): string
    {
        return self::dir() . '/' . sha1($key) . '.json';
    }

    private static function read(string $file): array
    {
        if (!is_file($file)) return [];
        $j = json_decode((string)@file_get_contents($file), true);
        return is_array($j) ? $j : [];
    }

    private static function write(string $file, array $data): void
    {
        @file_put_contents($file, (string)json_encode($data), LOCK_EX);
        /* گاه‌به‌گاه فایل‌های کهنه پاک می‌شوند */
        if (random_int(1, 200) === 1) self::prune();
    }

    private static function prune(int $olderThan = 86400): void
    {
        $now = time();
        foreach ((array)@glob(self::dir() . '/*.json') as $f) {
            if (is_string($f) && (int)@filemtime($f) + $olderThan < $now) @unlink($f);
        }
    }
}
