<?php
/**
 * تمدید خودکار از کیف پول + یادآوری مرحلهٔ دوم (fixed74)
 *
 *  - سراسری: arn_enabled (0/1) | arn_hours (پیش‌فرض ۲۴: وقتی کمتر از N ساعت به انقضا مانده)
 *             arn_traffic (پیش‌فرض ۹۵: درصد مصرف حجم که تمدید زودهنگام انجام می‌شود؛ ۰ = خاموش)
 *  - هر کاربر جدا از «حساب کاربری» روشن/خاموش می‌کند (users.auto_renew)
 *  - طرح تمدید: همان محصول سرویس اگر فعال و هم‌سرور باشد، وگرنه ارزان‌ترین طرح همان سرور
 *  - اگر موجودی کم باشد فقط یک بار اطلاع داده می‌شود (بیت 16 ستون notified)
 *  - یادآوری مرحلهٔ دوم: expire_notify_days2 (پیش‌فرض ۱ روز، ۰ = خاموش) — بیت 32
 *
 *  بیت‌های notified: 1 هشدار انقضا | 2 هشدار حجم | 4 اطلاع انقضا | 8 هشدار حذف | 16 تمدید خودکار ناموفق | 32 یادآوری مرحله ۲
 */
class AutoRenew
{
    public const BIT_FAIL  = 16;
    public const BIT_STAGE2 = 32;

    public static function enabled(): bool
    {
        return (string)DB::setting('arn_enabled', '0') === '1';
    }

    public static function hours(): int
    {
        return max(1, min(24 * 30, (int)DB::setting('arn_hours', 24)));
    }

    public static function trafficPercent(): int
    {
        return max(0, min(100, (int)DB::setting('arn_traffic', 95)));
    }

    /** آیا ستون users.auto_renew وجود دارد؟ (در صورت نبودن یک‌بار ساخته می‌شود) */
    public static function hasColumn(): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            $n = (int)DB::val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [DB::prefix() . 'users', 'auto_renew'], 0);
            if ($n === 0) {
                DB::q('ALTER TABLE {p}users ADD COLUMN auto_renew TINYINT(1) NOT NULL DEFAULT 0');
                $n = 1;
            }
            $ok = $n > 0;
        } catch (Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    public static function userOn(array $u): bool
    {
        return (int)($u['auto_renew'] ?? 0) === 1;
    }

    /** روشن/خاموش کردن برای یک کاربر — وضعیت جدید برمی‌گردد */
    public static function setUser(int $userId, ?bool $on = null): bool
    {
        if (!self::hasColumn()) return false;
        $cur = (int)DB::val('SELECT auto_renew FROM {p}users WHERE id = :i', [':i' => $userId], 0) === 1;
        $new = $on === null ? !$cur : $on;
        DB::update('users', ['auto_renew' => $new ? 1 : 0], 'id = :i', [':i' => $userId]);
        return $new;
    }

    /** طرح مناسب برای تمدید خودکار یک سرویس */
    public static function planFor(array $s): ?array
    {
        $pid = (int)($s['product_id'] ?? 0);
        if ($pid > 0) {
            $p = DB::one('SELECT * FROM {p}products WHERE id = :i AND active = 1 AND stock <> 0', [':i' => $pid]);
            /* fixed76: محصول «حجم و زمان دلخواه» طرح تمدید خودکار نیست → ارزان‌ترین طرح ثابت همان سرور */
            if ($p && (string)($p['type'] ?? 'fixed') !== 'custom' && (int)$p['panel_id'] === (int)$s['panel_id'] && (int)$p['price'] > 0) return $p;
        }
        $plans = Svc::renewPlans($s);
        $best = null;
        foreach ($plans as $p) {
            if ((int)$p['price'] <= 0 || (string)($p['type'] ?? 'fixed') === 'custom') continue;
            if ($best === null || (int)$p['price'] < (int)$best['price']) $best = $p;
        }
        return $best;
    }

    /**
     * اجرای دوره‌ای (از cron)
     * @return array{checked:int, renewed:int, nofunds:int, failed:int}
     */
    public static function run(int $max = 30): array
    {
        $rep = ['checked' => 0, 'renewed' => 0, 'nofunds' => 0, 'failed' => 0];
        if (!self::enabled() || !self::hasColumn()) return $rep;

        $hours = self::hours();
        $tp    = self::trafficPercent();
        $cond  = '(s.expire_at IS NOT NULL AND s.expire_at <= DATE_ADD(NOW(), INTERVAL :h HOUR))';
        $prm   = [':h' => $hours];
        if ($tp > 0) {
            $cond = '(' . $cond . ' OR (s.volume_gb > 0 AND s.used_bytes >= s.volume_gb * 1073741824 * :tp / 100))';
            $prm[':tp'] = $tp;
        }
        $rows = DB::all("SELECT s.* FROM {p}services s
                         INNER JOIN {p}users u ON u.id = s.user_id
                         WHERE s.status = 'active' AND s.is_test = 0 AND COALESCE(s.is_reseller,0) = 0
                           AND u.auto_renew = 1 AND u.is_banned = 0
                           AND (COALESCE(s.notified,0) & " . self::BIT_FAIL . ") = 0
                           AND $cond
                         ORDER BY s.expire_at ASC LIMIT " . (int)$max, $prm);

        foreach ($rows as $s) {
            $rep['checked']++;
            try {
                $r = self::renewOne($s);
                if ($r['ok']) $rep['renewed']++;
                elseif (($r['reason'] ?? '') === 'nofunds') $rep['nofunds']++;
                else $rep['failed']++;
            } catch (Throwable $e) {
                $rep['failed']++;
                app_log('autorenew', 'exception: ' . $e->getMessage(), ['svc' => (int)$s['id']]);
            }
            usleep(150000);
        }
        return $rep;
    }

    /** تمدید خودکار یک سرویس */
    public static function renewOne(array $s): array
    {
        $user = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => (int)$s['user_id']]);
        if (!$user) return ['ok' => false, 'reason' => 'nouser'];
        $p = self::planFor($s);
        if (!$p) {
            self::markFail($s);
            return ['ok' => false, 'reason' => 'noplan'];
        }
        $price = (int)$p['price'];
        $disc  = class_exists('Campaign') ? Campaign::discount($price, (int)$p['id'], 'renew') : 0;
        $final = max(0, $price - $disc);
        $tg    = (int)$user['tg_id'];

        if ((int)$user['balance'] < $final) {
            self::markFail($s);
            $need = $final - (int)$user['balance'];
            Tg::send($tg,
                "🔁 <b>تمدید خودکار انجام نشد</b>\n\n"
                . '🔑 <code>' . h((string)$s['client_email']) . "</code>\n"
                . '📦 طرح: ' . h((string)$p['name']) . "\n"
                . '💰 مبلغ لازم: <b>' . money($final) . ' ' . currency() . "</b>\n"
                . '👛 کسری موجودی: <b>' . money($need) . ' ' . currency() . "</b>\n\n"
                . 'برای جلوگیری از قطع سرویس، کیف پول را شارژ کنید؛ تمدید خودکار در اجرای بعدی دوباره تلاش می‌کند.',
                Tg::ikb([[Tg::btn('💳 شارژ کیف پول', 'menu:wallet')], [Tg::btn('♻️ تمدید دستی', 'svcrn:' . (int)$s['id'])]]));
            return ['ok' => false, 'reason' => 'nofunds'];
        }

        if (!Wallet::debit((int)$user['id'], $final, 'تمدید خودکار: ' . (string)$p['name'], 'purchase')) {
            self::markFail($s);
            return ['ok' => false, 'reason' => 'debit'];
        }
        $orderId = 0;
        try {
            $orderId = (int)DB::insert('orders', [
                'user_id' => (int)$user['id'], 'tg_id' => $tg, 'product_id' => (int)$p['id'], 'service_id' => (int)$s['id'],
                'type' => 'renew', 'amount' => $price, 'discount_code' => $disc > 0 ? 'CAMPAIGN' : null,
                'discount_amount' => $disc, 'final_amount' => $final, 'status' => 'pending', 'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            app_log('autorenew', 'order insert: ' . $e->getMessage(), ['svc' => (int)$s['id']]);
        }

        try {
            $r = Svc::renew($s, $p);
        } catch (Throwable $e) {
            $r = ['ok' => false, 'message' => $e->getMessage()];
        }
        if (empty($r['ok'])) {
            Wallet::credit((int)$user['id'], $final, 'refund', 'wallet', 'تمدید خودکار ناموفق #' . $orderId);
            if ($orderId) DB::update('orders', ['status' => 'failed'], 'id = :id', [':id' => $orderId]);
            self::markFail($s);
            Tg::send($tg, '❌ تمدید خودکار سرویس <code>' . h((string)$s['client_email']) . '</code> انجام نشد: ' . h((string)($r['message'] ?? '')) . "\n💰 مبلغ به کیف پول بازگردانده شد.");
            app_log('autorenew', 'renew failed', ['svc' => (int)$s['id'], 'msg' => (string)($r['message'] ?? '')]);
            return ['ok' => false, 'reason' => 'renew'];
        }
        if ($orderId) DB::update('orders', ['status' => 'paid'], 'id = :id', [':id' => $orderId]);
        $svc = $r['service'] ?? $s;

        Tg::send($tg,
            "🔁 <b>سرویس شما خودکار تمدید شد</b>\n\n"
            . '📦 طرح: ' . h((string)$p['name']) . "\n"
            . '💰 مبلغ: ' . money($final) . ' ' . currency() . ($disc > 0 ? ' (🔥 ' . money($disc) . ' تخفیف کمپین)' : '') . "\n\n"
            . Svc::summary($svc),
            Tg::ikb([[Tg::btn('📦 مشاهدهٔ سرویس', 'svc:' . (int)$s['id'])], [Tg::btn('🔁 خاموش کردن تمدید خودکار', 'arn:off')]]));
        try {
            Logs::send('services', Logs::fmt('🔁 تمدید خودکار', [
                'سفارش'   => '<code>#' . $orderId . '</code>',
                'کاربر'    => '<code>' . $tg . '</code>',
                'نام کاربری' => '<code>' . h((string)$svc['client_email']) . '</code>',
                'طرح'      => h((string)$p['name']),
                'مبلغ'     => money($final) . ' ' . currency(),
                'انقضای جدید' => !empty($svc['expire_at']) ? to_jalali((string)$svc['expire_at']) : 'نامحدود',
            ]));
        } catch (Throwable $e) { }
        return ['ok' => true, 'order' => $orderId, 'service' => $svc];
    }

    private static function markFail(array $s): void
    {
        try {
            DB::update('services', ['notified' => ((int)($s['notified'] ?? 0)) | self::BIT_FAIL], 'id = :id', [':id' => (int)$s['id']]);
        } catch (Throwable $e) { }
    }

    /* ------------------------------------------------------------------
     * یادآوری مرحلهٔ دوم انقضا (نزدیک‌تر به پایان)
     * ------------------------------------------------------------------ */
    public static function stage2Days(): int
    {
        return max(0, min(30, (int)DB::setting('expire_notify_days2', 1)));
    }

    public static function remindStage2(int $max = 80): int
    {
        $d = self::stage2Days();
        if ($d <= 0) return 0;
        $arnCol = self::hasColumn() ? 'COALESCE(u.auto_renew,0)' : '0';
        $rows = DB::all("SELECT s.*, u.tg_id AS utg, $arnCol AS u_arn FROM {p}services s
                         LEFT JOIN {p}users u ON u.id = s.user_id
                         WHERE s.status = 'active' AND s.is_test = 0
                           AND s.expire_at IS NOT NULL AND s.expire_at > NOW()
                           AND s.expire_at <= DATE_ADD(NOW(), INTERVAL :d DAY)
                           AND (COALESCE(s.notified,0) & " . self::BIT_STAGE2 . ") = 0
                         LIMIT " . (int)$max, [':d' => $d]);
        $n = 0;
        foreach ($rows as $s) {
            if ((int)$s['utg'] <= 0) continue;
            $txt = "🚨 <b>انقضای سرویس نزدیک است!</b>\n\n"
                 . '🔑 <code>' . h((string)$s['client_email']) . "</code>\n"
                 . '⏳ باقی‌مانده: <b>' . remaining_human((string)$s['expire_at']) . "</b>\n\n"
                 . 'اگر تمدید نکنید سرویس قطع می‌شود.';
            $kb = [[Tg::btn('♻️ تمدید سرویس', 'svcrn:' . (int)$s['id'])]];
            if (self::enabled() && (int)$s['u_arn'] !== 1) $kb[] = [Tg::btn('🔁 فعال‌سازی تمدید خودکار', 'arn:on')];
            Tg::send((int)$s['utg'], $txt, Tg::ikb($kb));
            DB::update('services', ['notified' => ((int)($s['notified'] ?? 0)) | self::BIT_STAGE2], 'id = :id', [':id' => (int)$s['id']]);
            $n++;
            usleep(120000);
        }
        return $n;
    }

    /** آمار برای پنل مدیریت */
    public static function stats(): array
    {
        $out = ['users_on' => 0, 'renewed_30d' => 0, 'sum_30d' => 0, 'nofunds' => 0];
        try {
            if (self::hasColumn()) $out['users_on'] = (int)DB::val('SELECT COUNT(*) FROM {p}users WHERE auto_renew = 1', [], 0);
            $out['renewed_30d'] = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE type = 'purchase' AND note LIKE 'تمدید خودکار:%' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", [], 0);
            $out['sum_30d']     = abs((int)DB::val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE type = 'purchase' AND note LIKE 'تمدید خودکار:%' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", [], 0));
            $out['nofunds']     = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE status = 'active' AND (COALESCE(notified,0) & " . self::BIT_FAIL . ") <> 0", [], 0);
        } catch (Throwable $e) { }
        return $out;
    }
}
