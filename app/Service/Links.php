<?php

/**
 * لینک‌های تکمیلی سرویس روی پنل‌های نسل جدید 3x-ui (docs.sanaei.dev)
 *
 *  - happ():     دیپ‌لینک اختصاصی Happ برای ایمپورت یک‌ضربه‌ای اشتراک
 *  - external(): لینک‌هایی که ادمین روی خود پنل برای آن اکانت تعریف کرده است
 *
 * روی پنل‌هایی که 3x-ui نیستند یا توکن API ندارند، بی‌صدا خاموش می‌ماند تا
 * هیچ بخشی از ربات/مینی‌اپ به خطا نخورد.
 *
 * 0.0.2 #happ-links
 */
final class Links
{
    /** کلید تنظیمات برای خاموش‌کردن کل قابلیت */
    public const S_ENABLED = 'happ_link';

    /** کش درایور به ازای هر پنل تا در یک درخواست چندبار ساخته نشود */
    private static $drv = [];

    public static function enabled(): bool
    {
        if (!class_exists('DB')) return false;
        return (string)DB::setting(self::S_ENABLED, '1') === '1';
    }

    /** درایور نسل جدیدِ پنلِ همین سرویس (بدون هیچ تماس شبکه‌ای) */
    private static function driver(array $svc)
    {
        $pid = (int)($svc['panel_id'] ?? 0);
        if ($pid <= 0 || !class_exists('Xui')) return null;
        if (array_key_exists($pid, self::$drv)) return self::$drv[$pid];

        $out = null;
        try {
            $x = Xui::forPanel($pid);
            if ($x && method_exists($x, 'isXui3') && $x->isXui3()) {
                $d = $x->xui3();
                if (is_object($d)) $out = $d;
            }
        } catch (Throwable $e) {
            $out = null;
        }
        self::$drv[$pid] = $out;
        return $out;
    }

    /** آیا برای این سرویس اصلاً لینک تکمیلی در دسترس است؟ */
    public static function supported(array $svc): bool
    {
        if (!self::enabled()) return false;
        if (trim((string)($svc['client_email'] ?? '')) === '') return false;
        return self::driver($svc) !== null;
    }

    /** دیپ‌لینک Happ سرویس؛ رشتهٔ خالی یعنی در دسترس نیست */
    public static function happ(array $svc): string
    {
        if (!self::supported($svc)) return '';
        $d = self::driver($svc);
        if (!$d || !method_exists($d, 'happLink')) return '';
        try {
            return trim((string)$d->happLink((string)$svc['client_email']));
        } catch (Throwable $e) {
            if (function_exists('app_log')) {
                app_log('panel', 'happ link failed', ['svc' => (int)($svc['id'] ?? 0), 'err' => $e->getMessage()]);
            }
            return '';
        }
    }

    /** لینک‌های خارجی اکانت به شکل [['title' => ..., 'link' => ...], ...] */
    public static function external(array $svc): array
    {
        if (!self::supported($svc)) return [];
        $d = self::driver($svc);
        if (!$d || !method_exists($d, 'externalLinks')) return [];
        try {
            $rows = $d->externalLinks((string)$svc['client_email']);
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
