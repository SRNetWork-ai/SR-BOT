<?php
declare(strict_types=1);

/**
 * Upload — بررسی امن فایل‌های آپلودی (نسخهٔ 0.0.2)
 *
 * هدف: جلوگیری از آپلود فایل اجرایی (php/phar/…)، محدود کردن حجم و پسوند،
 * و بستن اجرای اسکریپت داخل پوشه‌های آپلود.
 */
final class Upload
{
    /** پسوندهایی که هرگز نباید ذخیره شوند */
    public const DANGEROUS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phps', 'phar',
        'shtml', 'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'bat', 'cmd',
        'html', 'htm', 'svg', 'htaccess', 'ini',
    ];

    /** پسوندهای مجاز برای رسانه‌های ربات */
    public const MEDIA = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
        'mp4', 'mov', 'mkv', 'webm', 'mp3', 'ogg', 'oga', 'wav', 'm4a', 'pdf',
    ];

    /** پسوند تمیزشدهٔ فایل */
    public static function ext(string $name): string
    {
        $e = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        return (string)preg_replace('/[^a-z0-9]/', '', $e);
    }

    /**
     * نام امن برای ذخیره‌سازی؛ اگر پسوند خطرناک یا خارج از فهرست مجاز باشد
     * پسوند .txt به انتهای نام اضافه می‌شود تا فایل هرگز اجرا نشود.
     */
    public static function safeName(string $name, array $allow = []): string
    {
        $base = (string)preg_replace('/[^\w\.\-]/u', '_', trim($name));
        $base = str_replace(['..', '/', '\\'], '_', $base);
        $base = ltrim($base, '.');
        if ($base === '') $base = 'file';
        if (mb_strlen($base) > 80) $base = mb_substr($base, -80);

        $ext = self::ext($base);
        $bad = $ext === ''
            || in_array($ext, self::DANGEROUS, true)
            || ($allow !== [] && !in_array($ext, $allow, true));

        return $bad ? $base . '.txt' : $base;
    }

    /**
     * بررسی کامل یک فایل آپلودی
     *
     * @param array $file یک عضو از $_FILES
     * @return array{ok:bool,message:string,ext:string}
     */
    public static function check(array $file, int $maxBytes = 5242880, array $allow = []): array
    {
        $tmp = (string)($file['tmp_name'] ?? '');
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($tmp === '' || $err === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'message' => 'فایلی انتخاب نشده است.', 'ext' => ''];
        }
        if ($err !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'آپلود ناموفق بود (کد ' . $err . ').', 'ext' => ''];
        }
        if (!is_uploaded_file($tmp)) {
            return ['ok' => false, 'message' => 'فایل آپلودی معتبر نیست.', 'ext' => ''];
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            return ['ok' => false, 'message' => 'فایل خالی است.', 'ext' => ''];
        }
        if ($size > $maxBytes) {
            return ['ok' => false, 'message' => 'حجم فایل بیش از حد مجاز است (حداکثر ' . self::human($maxBytes) . ').', 'ext' => ''];
        }

        $ext = self::ext((string)($file['name'] ?? ''));
        if (in_array($ext, self::DANGEROUS, true)) {
            return ['ok' => false, 'message' => 'این نوع فایل به دلیل امنیتی پذیرفته نمی‌شود.', 'ext' => $ext];
        }
        if ($allow !== [] && !in_array($ext, $allow, true)) {
            return ['ok' => false, 'message' => 'پسوند مجاز نیست؛ فقط: ' . implode('، ', $allow), 'ext' => $ext];
        }
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true) && function_exists('getimagesize')) {
            if (@getimagesize($tmp) === false) {
                return ['ok' => false, 'message' => 'فایل تصویر سالم نیست.', 'ext' => $ext];
            }
        }

        return ['ok' => true, 'message' => '', 'ext' => $ext];
    }

    /** جلوگیری از اجرای اسکریپت داخل پوشهٔ آپلود (اگر .htaccess نداشته باشد) */
    public static function protectDir(string $dir): void
    {
        $dir = rtrim(trim($dir), "/\\");
        if ($dir === '' || !is_dir($dir)) return;

        $f = $dir . '/.htaccess';
        if (is_file($f)) return;

        $rules = "# 0.0.2 #10: اجرای اسکریپت در پوشهٔ آپلود ممنوع است\n"
            . "php_flag engine off\n"
            . "AddType text/plain .php .php3 .php4 .php5 .php7 .php8 .phtml .phar .cgi .pl .py .sh\n"
            . "<FilesMatch \"\\.(php|php[0-9]|phtml|phps|phar|cgi|pl|py|sh|htaccess)$\">\n"
            . "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
            . "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n"
            . "</FilesMatch>\n";

        @file_put_contents($f, $rules);
    }

    private static function human(int $b): string
    {
        if (function_exists('human_bytes')) return (string)human_bytes($b);
        return (string)round($b / 1048576, 1) . ' MB';
    }
}
