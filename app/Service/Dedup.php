<?php
declare(strict_types=1);

/**
 * Dedup — جلوگیری از پردازش دوبارهٔ یک آپدیت تلگرام (نسخهٔ ۰.۰.۲ / ردیف ۳۲)
 *
 * تلگرام اگر تا ۶۰ ثانیه پاسخ ۲۰۰ نگیرد، همان آپدیت را دوباره می‌فرستد.
 * بدون این نگهبان ممکن است یک خرید، تمدید یا شارژ کیف‌پول دوبار انجام شود.
 *
 * برای سبک ماندن روی هاست اشتراکی هیچ جدول تازه‌ای نمی‌سازد؛ هر شناسه یک
 * فایل کوچک قفل در storage/tmp/dedup است و ساخت فایل با حالت «x» اتمیک
 * انجام می‌شود، پس حتی اگر دو درخواست همزمان برسند فقط یکی رد می‌شود.
 */
final class Dedup
{
    /** چند ثانیه یک شناسه در حافظه بماند */
    public const TTL = 7200;

    /**
     * اولین بار که این شناسه دیده می‌شود true برمی‌گرداند و دفعات بعد false.
     *
     * @param int    $id    شناسهٔ آپدیت (update_id)
     * @param string $scope فضای نام؛ برای کاربردهای دیگر قابل تغییر است
     */
    public static function first(int $id, string $scope = 'tg'): bool
    {
        if ($id <= 0) return true;

        $scope = preg_replace('/[^a-z0-9_-]/i', '', $scope) ?: 'tg';
        $file  = self::dir() . '/' . $scope . '-' . $id . '.lock';

        /* حالت x فقط وقتی فایل وجود نداشته باشد موفق می‌شود (اتمیک) */
        $fh = @fopen($file, 'x');
        if ($fh === false) {
            /* اگر قفل خیلی قدیمی است اجازهٔ پردازش دوباره می‌دهیم */
            $age = time() - (int)@filemtime($file);
            if ($age > self::TTL) {
                @touch($file);
                return true;
            }
            if (function_exists('app_log')) {
                app_log('bot', 'duplicate update skipped', ['update_id' => $id, 'age' => $age]);
            }
            return false;
        }

        @fwrite($fh, (string)time());
        @fclose($fh);

        /* گاه‌به‌گاه قفل‌های کهنه پاک می‌شوند */
        if (random_int(1, 120) === 1) self::prune();

        return true;
    }

    /** حذف دستی یک شناسه (برای تست یا پردازش مجدد عمدی) */
    public static function forget(int $id, string $scope = 'tg'): void
    {
        $scope = preg_replace('/[^a-z0-9_-]/i', '', $scope) ?: 'tg';
        @unlink(self::dir() . '/' . $scope . '-' . $id . '.lock');
    }

    /** تعداد قفل‌های نگهداری‌شده (برای صفحهٔ سلامت) */
    public static function size(): int
    {
        return count((array)@glob(self::dir() . '/*.lock'));
    }

    /* ----------------------------------------------------------- ابزار */

    private static function dir(): string
    {
        $root = defined('APP_ROOT') ? (string)APP_ROOT : dirname(__DIR__, 2);
        $dir  = $root . '/storage/tmp/dedup';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }

    private static function prune(?int $olderThan = null): void
    {
        $ttl = $olderThan ?? self::TTL;
        $now = time();
        foreach ((array)@glob(self::dir() . '/*.lock') as $f) {
            if (is_string($f) && (int)@filemtime($f) + $ttl < $now) @unlink($f);
        }
    }
}
