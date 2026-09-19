<?php
declare(strict_types=1);

/**
 * Crypt — رمزنگاری متقارن اسرار ذخیره‌شده (نسخهٔ 0.0.2)
 *
 * الگوریتم: AES-256-GCM (با تگ اصالت) و خروجی «enc:v1:base64»
 * کلید: ابتدا از config.php (app.key)، سپس از فایل storage/.appkey؛
 * اگر هیچ‌کدام نبود، یک کلید تازه ساخته و ذخیره می‌شود.
 *
 * نکته: اگر کلید قابل ذخیره نباشد، عمداً رمزنگاری انجام نمی‌شود تا
 * هیچ داده‌ای غیرقابل بازیابی نشود.
 */
final class Crypt
{
    public const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';

    private static ?string $key = null;

    /** آیا سرور امکان رمزنگاری دارد؟ */
    public static function available(): bool
    {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_get_cipher_methods')) return false;
        $list = array_map('strtolower', (array)openssl_get_cipher_methods());
        return in_array(self::CIPHER, $list, true);
    }

    /** آیا این مقدار رمزنگاری‌شده است؟ */
    public static function isEnc($v): bool
    {
        return is_string($v) && strncmp($v, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    /** آیا کلید معتبر در دسترس است؟ */
    public static function hasKey(): bool
    {
        return self::key() !== '';
    }

    /** رمزنگاری؛ در صورت نبود امکان، همان متن اصلی برگردانده می‌شود */
    public static function enc(string $plain): string
    {
        if ($plain === '' || self::isEnc($plain) || !self::available()) return $plain;

        $key = self::key();
        if ($key === '') return $plain;

        try {
            $iv = random_bytes(12);
        } catch (Throwable $e) {
            return $plain;
        }

        $tag = '';
        $ct  = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false || $tag === '') return $plain;

        return self::PREFIX . base64_encode($iv . $tag . $ct);
    }

    /** بازکردن رمز؛ مقدار غیررمز همان‌طور برمی‌گردد */
    public static function dec(string $value): string
    {
        if (!self::isEnc($value)) return $value;
        if (!self::available()) return '';

        $key = self::key();
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($key === '' || $raw === false || strlen($raw) < 29) return '';

        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);

        $out = openssl_decrypt($ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($out === false) {
            if (function_exists('app_log')) app_log('sec', 'decrypt failed (کلید عوض شده یا داده خراب است)');
            return '';
        }

        return (string)$out;
    }

    /* ------------------------------------------------------------- کلید */

    private static function key(): string
    {
        if (self::$key !== null) return self::$key;

        $k = '';

        if (function_exists('cfg')) {
            $raw = trim((string)cfg('app.key', ''));
            if ($raw !== '') {
                $dec = base64_decode($raw, true);
                if (is_string($dec)) $k = $dec;
            }
        }

        if (strlen($k) !== 32) {
            $file = self::keyFile();
            if (is_file($file)) {
                $dec = base64_decode(trim((string)@file_get_contents($file)), true);
                if (is_string($dec)) $k = $dec;
            }
        }

        if (strlen($k) !== 32) $k = self::generate();

        self::$key = strlen($k) === 32 ? $k : '';
        return self::$key;
    }

    /** ساخت و ذخیرهٔ کلید تازه (اول در config.php، وگرنه در storage/.appkey) */
    private static function generate(): string
    {
        try {
            $k = random_bytes(32);
        } catch (Throwable $e) {
            return '';
        }

        $b64   = base64_encode($k);
        $saved = false;

        if (class_exists('Cfg') && method_exists('Cfg', 'set')) {
            try {
                $r     = Cfg::set(['app.key' => $b64]);
                $saved = !empty($r['ok']);
            } catch (Throwable $e) {
                $saved = false;
            }
            if ($saved && function_exists('cfg')) cfg(null, null, true);
        }

        if (!$saved) {
            $file = self::keyFile();
            @file_put_contents($file, $b64 . "\n", LOCK_EX);
            @chmod($file, 0600);
            $saved = is_file($file);
        }

        if (function_exists('app_log')) {
            app_log('sec', 'app key generated (' . ($saved ? 'saved' : 'not saved - encryption disabled') . ')');
        }

        return $saved ? $k : '';
    }

    private static function keyFile(): string
    {
        $root = defined('APP_ROOT') ? (string)APP_ROOT : dirname(__DIR__, 2);
        $dir  = $root . '/storage';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/.appkey';
    }
}
