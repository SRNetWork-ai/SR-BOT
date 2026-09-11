<?php
declare(strict_types=1);

/**
 * مدیریت تم‌های صفحهٔ اشتراک (sub.php)
 *
 * هر تم یک فایل CSS مستقل در assets/sub/ دارد که دقیقاً همان
 * کلاس‌های مارک‌آپ را استایل می‌دهد؛ پس عوض کردن تم هیچ تغییری
 * در ساختار HTML لازم ندارد.
 *
 * تم "aurora" تم پیش‌فرض است و CSS آن درون خود sub.php قرار دارد.
 */
class SubTheme
{
    /** کلید تم پیش‌فرض */
    public const DEFAULT_KEY = 'aurora';

    /** نام تنظیم ذخیره‌سازی */
    public const SETTING = 'sub_theme';

    /**
     * فهرست تم‌ها
     *
     * file   : نام فایل CSS در assets/sub/ ، خالی = CSS داخلی sub.php
     * scheme : dark یا light — برای meta color-scheme
     * sw     : سه رنگ برای پیش‌نمایش در پنل مدیریت
     */
    public const THEMES = [
        'aurora' => [
            'name'   => 'آرورا',
            'icon'   => '🌌',
            'desc'   => 'تیره، شیشه‌ای و نئونی با هاله‌های آبی–قرمز',
            'tags'   => ['تیره', 'شیشه‌ای', 'نئون'],
            'file'   => '',
            'scheme' => 'dark',
            'sw'     => ['#0a0d16', '#ff2d55', '#22d3ff'],
        ],
        'mist' => [
            'name'   => 'میست',
            'icon'   => '☁️',
            'desc'   => 'روشن، مینیمال و آرام — مناسب روز و چاپ',
            'tags'   => ['روشن', 'مینیمال', 'تجاری'],
            'file'   => 'mist.css',
            'scheme' => 'light',
            'sw'     => ['#eef1f7', '#3b6cf6', '#0f9d58'],
        ],
        'terminal' => [
            'name'   => 'ترمینال',
            'icon'   => '🖥',
            'desc'   => 'سیاه مطلق با فسفر سبز، خط مونواسپیس و اسکن‌لاین CRT',
            'tags'   => ['هکری', 'مونواسپیس', 'رترو'],
            'file'   => 'terminal.css',
            'scheme' => 'dark',
            'sw'     => ['#000000', '#33ff77', '#00e5ff'],
        ],
        'candy' => [
            'name'   => 'کندی',
            'icon'   => '🍭',
            'desc'   => 'پاستل و شاد با گوشه‌های خیلی گرد و انیمیشن فنری',
            'tags'   => ['روشن', 'پاستل', 'شاد'],
            'file'   => 'candy.css',
            'scheme' => 'light',
            'sw'     => ['#fdf3f8', '#ff8fc3', '#a78bfa'],
        ],
        'carbon' => [
            'name'   => 'کربن',
            'icon'   => '⬛',
            'desc'   => 'صنعتی و گوشه‌تیز با سایه‌های سخت و تأکید نارنجی',
            'tags'   => ['تیره', 'صنعتی', 'بروتالیست'],
            'file'   => 'carbon.css',
            'scheme' => 'dark',
            'sw'     => ['#0d0d0f', '#ff6a00', '#3ddc84'],
        ],
    ];

    /** همهٔ تم‌ها */
    public static function all(): array
    {
        return self::THEMES;
    }

    /** کلیدهای معتبر */
    public static function keys(): array
    {
        return array_keys(self::THEMES);
    }

    /** آیا این کلید معتبر است؟ */
    public static function has(string $key): bool
    {
        return $key !== '' && isset(self::THEMES[$key]);
    }

    /** نرمال‌سازی کلید */
    public static function normalize(?string $key): string
    {
        $k = strtolower(trim((string)$key));
        return self::has($k) ? $k : self::DEFAULT_KEY;
    }

    /** متادیتای یک تم */
    public static function meta(string $key): array
    {
        $k = self::normalize($key);
        $m = self::THEMES[$k];
        $m['key'] = $k;
        return $m;
    }

    /** نام فارسی تم */
    public static function label(string $key): string
    {
        $m = self::meta($key);
        return (string)$m['icon'] . ' ' . (string)$m['name'];
    }

    /** dark یا light */
    public static function scheme(string $key): string
    {
        $m = self::meta($key);
        return (string)$m['scheme'] === 'light' ? 'light' : 'dark';
    }

    /** تم فعلی از تنظیمات (با امکان پیش‌نمایش با ?theme=) */
    public static function current(bool $allowPreview = true): string
    {
        if ($allowPreview) {
            $q = isset($_GET['theme']) ? (string)$_GET['theme'] : '';
            if ($q !== '' && self::has(strtolower(trim($q)))) {
                return strtolower(trim($q));
            }
        }

        $saved = '';
        if (class_exists('DB')) {
            try {
                $saved = (string)DB::setting(self::SETTING, self::DEFAULT_KEY);
            } catch (\Throwable $e) {
                $saved = self::DEFAULT_KEY;
            }
        }

        return self::normalize($saved);
    }

    /** ذخیرهٔ تم انتخابی */
    public static function save(string $key): bool
    {
        if (!self::has($key)) {
            return false;
        }
        if (!class_exists('DB')) {
            return false;
        }
        try {
            DB::setSetting(self::SETTING, $key);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** مسیر فایل CSS یک تم (خالی اگر داخلی باشد) */
    public static function path(string $key): string
    {
        $m    = self::meta($key);
        $file = (string)($m['file'] ?? '');
        if ($file === '') {
            return '';
        }
        if (!defined('APP_ROOT')) {
            return '';
        }
        return APP_ROOT . '/assets/sub/' . $file;
    }

    /** آیا CSS این تم داخل sub.php است؟ */
    public static function isInline(string $key): bool
    {
        $m = self::meta($key);
        return (string)($m['file'] ?? '') === '';
    }

    /** آیا فایل CSS روی دیسک موجود است؟ */
    public static function ready(string $key): bool
    {
        if (self::isInline($key)) {
            return true;
        }
        $p = self::path($key);
        return $p !== '' && is_file($p) && is_readable($p);
    }

    /**
     * متن CSS تم
     * اگر تم داخلی باشد یا فایل پیدا نشود، رشتهٔ خالی برمی‌گرداند.
     */
    public static function css(string $key): string
    {
        $p = self::path($key);
        if ($p === '' || !is_file($p) || !is_readable($p)) {
            return '';
        }
        $raw = @file_get_contents($p);
        if ($raw === false) {
            return '';
        }
        // جلوگیری از بسته شدن زودهنگام تگ استایل
        return str_ireplace('</style', '<\\/style', $raw);
    }

    /** آیا همهٔ فایل‌های تم سر جای خودشان هستند؟ */
    public static function missing(): array
    {
        $out = [];
        foreach (self::THEMES as $k => $_m) {
            if (!self::ready((string)$k)) {
                $out[] = (string)$k;
            }
        }
        return $out;
    }

    /** لینک پیش‌نمایش یک تم روی یک اشتراک واقعی */
    public static function previewUrl(string $subKey, string $theme): string
    {
        $t = self::normalize($theme);
        $s = trim($subKey);
        if ($s === '') {
            return '';
        }
        if (!function_exists('app_url')) {
            return '';
        }
        return app_url('/sub.php?id=' . rawurlencode($s) . '&theme=' . rawurlencode($t));
    }
}
