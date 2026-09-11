<?php
/**
 * هدیه / تمدید گروهی سرویس‌ها (fixed74)
 *
 *  افزودن روز و/یا گیگ به یک دسته از سرویس‌ها و اعمال روی پنل (همهٔ انواع از طریق Svc::pushLimits)
 *  سرویس‌های منقضی با دریافت هدیه دوباره فعال می‌شوند.
 */
class Bulk
{
    public const MAX = 400;

    /** دسته‌ها: کلید => [برچسب, شرط SQL روی s] */
    public static function segments(): array
    {
        return [
            'act'     => ['🟢 همهٔ فعال‌ها',            "s.status = 'active' AND s.is_test = 0"],
            'expsoon' => ['⏳ رو به انقضا (۳ روز)',      "s.status = 'active' AND s.is_test = 0 AND s.expire_at IS NOT NULL AND s.expire_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)"],
            'expired' => ['🔴 منقضی‌شده‌ها',              "s.status = 'expired' AND s.is_test = 0"],
            'rs'      => ['🏷 سرویس نمایندگان (فعال)',  "s.status = 'active' AND COALESCE(s.is_reseller,0) = 1"],
            'all'     => ['📦 فعال + منقضی',            "s.status IN ('active','expired') AND s.is_test = 0"],
        ];
    }

    public static function label(string $seg): string
    {
        return self::segments()[$seg][0] ?? $seg;
    }

    /** شرط SQL برای یک دسته (پشتیبانی از panel:<id> و user:<tg_id>) */
    public static function where(string $seg, array &$params): string
    {
        $params = [];
        if (preg_match('/^panel:(\d+)$/', $seg, $m)) {
            $params[':pid'] = (int)$m[1];
            return "s.status IN ('active','expired') AND s.panel_id = :pid";
        }
        if (preg_match('/^user:(\d+)$/', $seg, $m)) {
            $params[':tg'] = (int)$m[1];
            return "s.status IN ('active','expired') AND s.user_id IN (SELECT id FROM {p}users WHERE tg_id = :tg)";
        }
        return self::segments()[$seg][1] ?? "s.status = 'active' AND s.is_test = 0";
    }

    public static function count(string $seg): int
    {
        $p = [];
        $w = self::where($seg, $p);
        return (int)DB::val("SELECT COUNT(*) FROM {p}services s WHERE $w", $p, 0);
    }

    /**
     * اعمال هدیه
     * @param int    $days   روز افزوده (۰ = بدون تغییر)
     * @param float  $gb     گیگ افزوده (۰ = بدون تغییر)
     * @param string $note   پیام اختیاری برای کاربران (خالی = متن پیش‌فرض)
     * @param bool   $notify اطلاع به کاربر
     */
    public static function apply(string $seg, int $days, float $gb, string $note = '', bool $notify = true, int $max = self::MAX): array
    {
        $rep = ['total' => 0, 'ok' => 0, 'panel_fail' => 0, 'skipped' => 0, 'reactivated' => 0, 'notified' => 0];
        if ($days <= 0 && $gb <= 0) return $rep + ['message' => 'مقدار روز یا گیگ باید بزرگ‌تر از صفر باشد.'];
        $days = min($days, 3650);
        $gb   = min($gb, 100000);

        $p = [];
        $w = self::where($seg, $p);
        $rows = DB::all("SELECT s.*, u.tg_id AS utg FROM {p}services s LEFT JOIN {p}users u ON u.id = s.user_id WHERE $w ORDER BY s.id ASC LIMIT " . (int)max(1, min(self::MAX, $max)), $p);
        $rep['total'] = count($rows);
        $hasExpAt = false;
        try { $hasExpAt = Svc::hasExpiredAt(); } catch (Throwable $e) { }

        $xuis = [];
        foreach ($rows as $s) {
            $upd = [];
            $wasExpired = (string)$s['status'] === 'expired';

            /* زمان */
            if ($days > 0 && !empty($s['expire_at'])) {
                $base = max(time(), (int)strtotime((string)$s['expire_at']));
                $upd['expire_at'] = date('Y-m-d H:i:s', $base + $days * 86400);
                $upd['days']      = (int)$s['days'] + $days;
            }
            /* حجم (سرویس نامحدود = بدون تغییر) */
            if ($gb > 0 && (float)$s['volume_gb'] > 0) {
                $upd['volume_gb'] = round((float)$s['volume_gb'] + $gb, 3);
            }
            if (!$upd) { $rep['skipped']++; continue; }

            $new = array_merge($s, $upd);
            /* اگر بعد از هدیه هنوز تمام‌شده است (مثلاً حجم پر و فقط روز افزوده شد) فعال نمی‌شود */
            $stillDead = (!empty($new['expire_at']) && strtotime((string)$new['expire_at']) <= time())
                || ((float)$new['volume_gb'] > 0 && (int)$new['used_bytes'] >= (float)$new['volume_gb'] * 1073741824);
            if ($wasExpired && !$stillDead) {
                $upd['status']   = 'active';
                $upd['notified'] = null;
                if ($hasExpAt) $upd['expired_at'] = null;
                $new['status'] = 'active';
            }

            /* اعمال روی پنل */
            $pid = (int)$s['panel_id'];
            if (!array_key_exists($pid, $xuis)) {
                $panel = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => $pid]);
                try { $xuis[$pid] = $panel ? new Xui($panel) : null; } catch (Throwable $e) { $xuis[$pid] = null; }
            }
            $pushed = false;
            if ($xuis[$pid]) {
                try { $pushed = Svc::pushLimits($new, $xuis[$pid]); } catch (Throwable $e) { $pushed = false; }
            }
            if (!$pushed) {
                $rep['panel_fail']++;
                app_log('bulk', 'push failed', ['svc' => (int)$s['id'], 'seg' => $seg]);
                /* برای سرویس‌های حذف‌شده در پنل تغییری در دیتابیس اعمال نمی‌شود تا نا��ماهنگی ایجاد نشود */
                continue;
            }
            DB::update('services', $upd, 'id = :id', [':id' => (int)$s['id']]);
            $rep['ok']++;
            if ($wasExpired && ($upd['status'] ?? '') === 'active') $rep['reactivated']++;

            if ($notify && (int)$s['utg'] > 0) {
                $what = [];
                if ($days > 0 && isset($upd['expire_at'])) $what[] = fa_num($days) . ' روز';
                if ($gb > 0 && isset($upd['volume_gb']))   $what[] = fa_num((string)$gb) . ' گیگ';
                $txt = "🎁 <b>هدیه به سرویس شما افزوده شد</b>\n\n"
                     . '🔑 <code>' . h((string)$s['client_email']) . "</code>\n"
                     . '➕ ' . implode(' و ', $what) . "\n"
                     . (isset($upd['expire_at']) ? '📅 انقضای جدید: ' . to_jalali((string)$upd['expire_at']) . "\n" : '')
                     . (isset($upd['volume_gb']) ? '📊 حجم کل: ' . fa_num((string)$upd['volume_gb']) . " گیگ\n" : '')
                     . (($upd['status'] ?? '') === 'active' ? "✅ سرویس دوباره فعال شد.\n" : '')
                     . ($note !== '' ? "\n💬 " . h($note) : '');
                try {
                    Tg::send((int)$s['utg'], $txt, Tg::ikb([[Tg::btn('📦 مشاهدهٔ سرویس', 'svc:' . (int)$s['id'])]]));
                    $rep['notified']++;
                } catch (Throwable $e) { }
                usleep(80000);
            }
        }

        try {
            Logs::send('admin', Logs::fmt('🎁 هدیهٔ گروهی', [
                'دسته'   => h(self::label($seg)),
                'افزوده' => ($days > 0 ? fa_num($days) . ' روز ' : '') . ($gb > 0 ? fa_num((string)$gb) . ' گیگ' : ''),
                'موفق'   => fa_num($rep['ok']) . ' از ' . fa_num($rep['total']),
                'خطای پنل' => fa_num($rep['panel_fail']),
                'فعال‌شده' => fa_num($rep['reactivated']),
            ]));
        } catch (Throwable $e) { }
        return $rep;
    }
}
