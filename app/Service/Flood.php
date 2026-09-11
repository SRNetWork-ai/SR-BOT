<?php
declare(strict_types=1);

/**
 * محدودساز پیام — Flood Control
 * جلوی اسپم پیام و کلیک کاربر را می‌گیرد تا فشار روی سرور و محدودیت API تلگرام کم شود.
 * شمارش با فایل‌های سبک در storage/flood انجام می‌شود و هیچ کوئری دیتابیسی اضافه نمی‌کند.
 *
 * تنظیمات در جدول settings:
 *  flood_on  : «1» فعال — پیش‌فرض | «0» خاموش
 *  flood_max : حداکثر پیام در هر پنجره — پیش‌فرض 20
 *  flood_win : طول پنجره به ثانیه — پیش‌فرض 10
 *  flood_ban : مدت سکوت پس از عبور از سقف به ثانیه — پیش‌فرض 60
 */
final class Flood
{
    public static function enabled(): bool
    {
        return (string)DB::setting('flood_on', '1') === '1';
    }

    private static function dir(): string
    {
        $d = APP_ROOT . '/storage/flood';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    /**
     * ثبت یک پیام یا کلیک از کاربر.
     * خروجی: ok = عادی | notice = از سقف عبور کرد و باید یک‌بار اخطار داده شود | silent = نادیده گرفتن بی‌صدا
     */
    public static function hit(int $tgId): string
    {
        if ($tgId <= 0 || !self::enabled()) return 'ok';
        if (function_exists('is_admin_id') && is_admin_id($tgId)) return 'ok';

        $max = max(5, (int)DB::setting('flood_max', 20));
        $win = max(3, (int)DB::setting('flood_win', 10));
        $ban = max(10, (int)DB::setting('flood_ban', 60));

        $f   = self::dir() . '/f' . $tgId . '.txt';
        $now = time();
        $d   = ['s' => $now, 'c' => 0, 'b' => 0, 'n' => 0];
        $raw = @file_get_contents($f);
        if (is_string($raw) && $raw !== '') {
            $tmp = @json_decode($raw, true);
            if (is_array($tmp)) $d = $tmp + $d;
        }

        /* در حال سکوت است؟ */
        if ((int)$d['b'] > $now) {
            if ((int)$d['n'] === 0) {
                $d['n'] = 1;
                @file_put_contents($f, (string)json_encode($d));
                return 'notice';
            }
            return 'silent';
        }

        /* شروع پنجره تازه */
        if ($now - (int)$d['s'] >= $win) $d = ['s' => $now, 'c' => 0, 'b' => 0, 'n' => 0];

        $d['c'] = (int)$d['c'] + 1;
        if ((int)$d['c'] > $max) {
            $d['b'] = $now + $ban;
            $d['n'] = 1;
            @file_put_contents($f, (string)json_encode($d));
            if (function_exists('app_log')) app_log('flood', 'user ' . $tgId . ' muted for ' . $ban . 's');
            return 'notice';
        }
        @file_put_contents($f, (string)json_encode($d));
        return 'ok';
    }

    /** پاکسازی فایل‌های قدیمی‌تر از یک روز — از cron صدا زده می‌شود */
    public static function cleanup(): int
    {
        $n = 0;
        $d = APP_ROOT . '/storage/flood';
        if (!is_dir($d)) return 0;
        foreach ((array)glob($d . '/f*.txt') as $f) {
            if (is_file((string)$f) && (int)@filemtime((string)$f) < time() - 86400) {
                @unlink((string)$f);
                $n++;
            }
        }
        return $n;
    }
}
