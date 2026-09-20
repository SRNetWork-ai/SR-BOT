<?php
declare(strict_types=1);

/**
 * Session — سخت‌سازی نشست‌های پنل مدیریت (نسخهٔ 0.0.2)
 *
 * به‌جای session_start() خام، همه‌جا Session::start() صدا زده می‌شود تا کوکی نشست
 * امن ساخته شود: HttpOnly، SameSite=Lax، روی HTTPS با پرچم Secure و حالت سخت‌گیرانه.
 */
final class Session
{
    /** حداکثر بی‌کاری مجاز نشست (ثانیه) — ۱۲ ساعت */
    public const IDLE = 43200;

    /** جایگزین امن session_start() */
    public static function start(): void
    {
        if (PHP_SAPI === 'cli') return;
        if (session_status() === PHP_SESSION_ACTIVE) return;
        self::harden();
        @session_start();
    }

    /** تنظیمات امن کوکی و شناسهٔ نشست (باید پیش از شروع نشست اجرا شود) */
    public static function harden(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) return;

        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.gc_maxlifetime', (string)self::IDLE);

        $secure = self::https();

        if (PHP_VERSION_ID >= 70300) {
            @session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            @session_set_cookie_params(0, '/', '', $secure, true);
        }
    }

    /** پس از ورود موفق: شناسهٔ نشست عوض و اثرانگشت مرورگر ثبت می‌شود */
    public static function afterLogin(): void
    {
        self::start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        $_SESSION['_fp']   = self::fp();
        $_SESSION['_seen'] = time();
    }

    /**
     * اعتبار نشست: اثرانگشت مرورگر باید یکی باشد و نشست نباید بیش از حد بی‌کار مانده باشد.
     * عمداً به IP گره نمی‌خورد چون اینترنت موبایل ایران IP را مدام عوض می‌کند.
     */
    public static function valid(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return false;

        $fp = (string)($_SESSION['_fp'] ?? '');
        if ($fp !== '' && !hash_equals($fp, self::fp())) return false;

        $seen = (int)($_SESSION['_seen'] ?? 0);
        if ($seen > 0 && $seen + self::IDLE < time()) return false;

        $_SESSION['_seen'] = time();
        return true;
    }

    /** خروج کامل: خالی کردن نشست، حذف کوکی و نابودی نشست */
    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return;

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            @setcookie(
                session_name(),
                '',
                time() - 42000,
                (string)($p['path'] ?? '/'),
                (string)($p['domain'] ?? ''),
                (bool)($p['secure'] ?? false),
                (bool)($p['httponly'] ?? true)
            );
        }
        @session_destroy();
    }

    /* ----------------------------------------------------------- ابزار */

    private static function fp(): string
    {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $salt = defined('APP_ROOT') ? (string)APP_ROOT : 'sr-bot';
        return substr(hash('sha256', $ua . '|' . $salt), 0, 32);
    }

    private static function https(): bool
    {
        $h = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        if ($h !== '' && $h !== 'off') return true;
        if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
        return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
