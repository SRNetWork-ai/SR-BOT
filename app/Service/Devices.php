<?php

/**
 * مدیریت دستگاه‌های ثبت‌شدهٔ هر سرویس (HWID)
 *
 * فقط روی پنل‌های نسل جدید سنایی (3x-ui) که توکن API دارند کار می‌کند؛
 * روی بقیهٔ پنل‌ها بی‌صدا غیرفعال است تا چیزی در ربات/مینی‌اپ خراب نشود.
 *
 * مرجع: https://docs.sanaei.dev/docs/reference/api/clients
 *   GET    /panel/api/clients/hwids/{email}
 *   DELETE /panel/api/clients/hwids/{email}/{id}
 *   POST   /panel/api/clients/bulkAdjust   (limitHwid)
 */
final class Devices
{
    /** کش پنل‌ها در طول یک درخواست */
    private static array $panels = [];

    /** کلید تنظیمات برای خاموش کردن کل قابلیت */
    public const S_ENABLED = 'dev_manage';

    public static function enabled(): bool
    {
        try {
            return (string)DB::setting(self::S_ENABLED, '1') === '1';
        } catch (Throwable $e) {
            return true;
        }
    }

    private static function panel(int $panelId): ?array
    {
        if ($panelId <= 0) return null;
        if (!array_key_exists($panelId, self::$panels)) {
            try {
                self::$panels[$panelId] = DB::one('SELECT * FROM {p}panels WHERE id = :i', [':i' => $panelId]) ?: null;
            } catch (Throwable $e) {
                self::$panels[$panelId] = null;
            }
        }
        return self::$panels[$panelId];
    }

    /** درایور نسل جدید برای این سرویس؛ در غیر این صورت null */
    public static function driver(array $service): ?Xui3
    {
        if (!self::enabled()) return null;
        if (!class_exists('Xui3')) return null;
        $p = self::panel((int)($service['panel_id'] ?? 0));
        if (!$p) return null;
        $type = class_exists('Xui') ? Xui::normType((string)($p['type'] ?? '')) : (string)($p['type'] ?? '');
        if ($type !== 'sanaei' || !Xui3::hasToken($p)) return null;
        return new Xui3($p);
    }

    /** آیا برای این سرویس می‌توان دستگاه‌ها را مدیریت کرد؟ */
    public static function supported(array $service): bool
    {
        return trim((string)($service['client_email'] ?? '')) !== '' && self::driver($service) !== null;
    }

    /** فهرست دستگاه‌های ثبت‌شده */
    public static function listFor(array $service): array
    {
        $x     = self::driver($service);
        $email = trim((string)($service['client_email'] ?? ''));
        if (!$x || $email === '') return [];
        try {
            $rows = $x->devices($email);
        } catch (Throwable $e) {
            app_log('panel', 'devices list: ' . $e->getMessage(), ['svc' => (int)($service['id'] ?? 0)]);
            return [];
        }
        $out = [];
        foreach ($rows as $d) {
            $id = (int)($d['id'] ?? 0);
            if ($id <= 0) continue;
            $seen  = (int)($d['seen'] ?? 0);
            $out[] = [
                'id'       => $id,
                'title'    => self::title((array)$d),
                'seen'     => $seen,
                'seen_txt' => self::when($seen),
            ];
        }
        return $out;
    }

    /** سقف دستگاه‌های مجاز (۰ یعنی بدون محدودیت) */
    public static function limitOf(array $service): int
    {
        $x     = self::driver($service);
        $email = trim((string)($service['client_email'] ?? ''));
        if (!$x || $email === '') return 0;
        try {
            $row = $x->accountRow($email);
        } catch (Throwable $e) {
            return 0;
        }
        return max(0, (int)(($row['limitHwid'] ?? 0)));
    }

    /** حذف یک دستگاه (آزاد شدن یک ظرفیت) */
    public static function remove(array $service, int $deviceId): bool
    {
        $x     = self::driver($service);
        $email = trim((string)($service['client_email'] ?? ''));
        if (!$x || $email === '' || $deviceId <= 0) return false;
        try {
            $r = $x->deviceDelete($email, $deviceId);
        } catch (Throwable $e) {
            app_log('panel', 'device del: ' . $e->getMessage(), ['svc' => (int)($service['id'] ?? 0), 'dev' => $deviceId]);
            return false;
        }
        $ok = ($r['success'] ?? false) === true;
        if ($ok && class_exists('Logs')) {
            try {
                Logs::send('services', Logs::fmt('حذف دستگاه', [
                    'سرویس'  => (string)($service['client_email'] ?? ''),
                    'شناسه'  => (string)$deviceId,
                ]));
            } catch (Throwable $e) { }
        }
        return $ok;
    }

    /** حذف همهٔ دستگاه‌ها؛ تعداد حذف‌شده برمی‌گردد */
    public static function clear(array $service): int
    {
        $x     = self::driver($service);
        $email = trim((string)($service['client_email'] ?? ''));
        if (!$x || $email === '') return 0;
        try {
            $n = $x->devicesClear($email);
        } catch (Throwable $e) {
            app_log('panel', 'device clear: ' . $e->getMessage(), ['svc' => (int)($service['id'] ?? 0)]);
            return 0;
        }
        if ($n > 0 && class_exists('Logs')) {
            try {
                Logs::send('services', Logs::fmt('پاک‌سازی دستگاه‌ها', [
                    'سرویس' => (string)($service['client_email'] ?? ''),
                    'تعداد' => (string)$n,
                ]));
            } catch (Throwable $e) { }
        }
        return $n;
    }

    /** متن خلاصه برای نمایش در ربات */
    public static function summary(array $service): string
    {
        $items = self::listFor($service);
        $limit = self::limitOf($service);
        $cap   = $limit > 0 ? (fa_num((string)count($items)) . ' از ' . fa_num((string)$limit)) : fa_num((string)count($items));
        return $cap;
    }

    private static function title(array $d): string
    {
        $bits = [];
        foreach (['name', 'os', 'app'] as $k) {
            $v = trim((string)($d[$k] ?? ''));
            if ($v !== '') $bits[] = $v;
        }
        if ($bits) return implode(' • ', array_slice($bits, 0, 2));
        $hw = trim((string)($d['hwid'] ?? ''));
        return $hw !== '' ? ('دستگاه ' . mb_substr($hw, 0, 8)) : 'دستگاه ناشناس';
    }

    /** پنل زمان را میلی‌ثانیه‌ای می‌دهد */
    private static function when(int $ms): string
    {
        if ($ms <= 0) return '—';
        $ts = $ms > 9999999999 ? (int)round($ms / 1000) : $ms;
        try {
            return to_jalali(date('Y-m-d H:i:s', $ts));
        } catch (Throwable $e) {
            return date('Y-m-d H:i', $ts);
        }
    }
}
