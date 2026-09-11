<?php

/**
 * بازیابی سفارش‌های نیمه‌کاره
 *
 * اگر ساخت کانفیگ در میانه کار قطع شود (تایم‌اوت پنل، قطعی شبکه، خطای PHP)
 * سفارش در وضعیت pending می‌ماند در حالی که پول کاربر کسر شده است.
 * این کلاس این سفارش‌ها را پیدا کرده و یکی از دو کار را انجام می‌دهد:
 *   ۱) اگر سرویس در واقع ساخته شده بود => سفارش به سرویس وصل و paid می‌شود
 *   ۲) در غیر این صورت => مبلغ به کیف پول برمی‌گردد و سفارش failed می‌شود
 */
class Orders
{
    /** آستانه پیش‌فرض گیرکردن سفارش (دقیقه) */
    public const STUCK_MINUTES = 10;

    public static function stuckMinutes(): int
    {
        $m = (int)DB::setting('order_stuck_minutes', (string)self::STUCK_MINUTES);
        return $m >= 2 ? $m : self::STUCK_MINUTES;
    }

    /** لیست سفارش‌های گیرکرده */
    public static function stuck(?int $minutes = null, int $limit = 25): array
    {
        $m   = (int)($minutes ?? self::stuckMinutes());
        $lim = max(1, min(200, $limit));

        return DB::all(
            "SELECT o.*, u.tg_id AS utg, p.name AS pname
               FROM {p}orders o
               LEFT JOIN {p}users u ON u.id = o.user_id
               LEFT JOIN {p}products p ON p.id = o.product_id
              WHERE o.status = 'pending'
                AND o.created_at < DATE_SUB(NOW(), INTERVAL {$m} MINUTE)
              ORDER BY o.id ASC
              LIMIT {$lim}"
        );
    }

    public static function stuckCount(?int $minutes = null): int
    {
        $m = (int)($minutes ?? self::stuckMinutes());
        return (int)DB::val(
            "SELECT COUNT(*) FROM {p}orders
              WHERE status = 'pending'
                AND created_at < DATE_SUB(NOW(), INTERVAL {$m} MINUTE)", [], 0);
    }

    /** جلوگیری از بازگشت وجه تکراری */
    public static function refunded(int $orderId): bool
    {
        $row = DB::one(
            "SELECT id FROM {p}transactions
              WHERE type = 'refund' AND note LIKE :n
              LIMIT 1",
            [':n' => '%#' . $orderId]);

        return !empty($row);
    }

    /**
     * پیدا کردن سرویسی که احتمالا برای همین سفارش ساخته شده ولی وصل نشده است.
     * فقط سرویسی که به هیچ سفارش دیگری وصل نیست و بعد از ثبت سفارش ساخته شده.
     */
    public static function findService(array $o): ?array
    {
        $pid = (int)($o['product_id'] ?? 0);
        $sql = "SELECT s.* FROM {p}services s
                LEFT JOIN {p}orders o2 ON o2.service_id = s.id
                WHERE s.user_id = :u
                  AND s.is_test = 0
                  AND o2.id IS NULL
                  AND s.created_at >= DATE_SUB(:t, INTERVAL 3 MINUTE)";
        $par = [':u' => (int)$o['user_id'], ':t' => (string)$o['created_at']];

        if ($pid > 0) {
            $sql .= " AND s.product_id = :p";
            $par[':p'] = $pid;
        }
        $sql .= " ORDER BY s.id DESC LIMIT 1";

        $row = DB::one($sql, $par);
        return $row ?: null;
    }

    /** بازیابی یک سفارش */
    public static function recoverOne(array $o): array
    {
        $oid   = (int)$o['id'];
        $tg    = (int)($o['utg'] ?? ($o['tg_id'] ?? 0));
        $money = (int)($o['final_amount'] ?? 0);
        $pname = (string)($o['pname'] ?? '-');

        /* ۱) سرویس ساخته شده بود؟ */
        $svc = self::findService($o);
        if ($svc) {
            DB::update('orders', ['status' => 'paid', 'service_id' => (int)$svc['id']],
                'id = :id', [':id' => $oid]);

            if ($tg > 0) {
                Tg::send($tg,
                    "✅ <b>سرویس شما سالم است</b>\n\n"
                    . "سفارش #" . $oid . " به دلیل کندی سرور نیمه‌کاره مانده بود؛ بررسی شد و کانفیگ شما ساخته شده است.\n"
                    . "🔑 <code>" . h((string)$svc['client_email']) . "</code>",
                    Tg::ikb([[Tg::btn('🔑 مشاهده سرویس', 'svc:' . (int)$svc['id'])]]));
            }

            Logs::send('services', Logs::fmt('🛠 سفارش نیمه‌کاره بازیابی شد', [
                'سفارش' => '#' . $oid,
                'سرویس' => (string)$svc['client_email'],
                'محصول' => $pname,
                'کاربر' => (string)$tg,
            ]));
            app_log('orders', 'recovered order #' . $oid . ' => service #' . (int)$svc['id']);

            return ['ok' => true, 'action' => 'linked', 'order' => $oid, 'service' => (int)$svc['id']];
        }

        /* ۲) سرویسی ساخته نشده => بازگشت وجه */
        $back = false;
        if ($money > 0 && !self::refunded($oid)) {
            Wallet::credit((int)$o['user_id'], $money, 'refund', 'wallet',
                'بازگشت وجه سفارش ناتمام #' . $oid);
            $back = true;
        }
        DB::update('orders', ['status' => 'failed'], 'id = :id', [':id' => $oid]);

        if ($tg > 0) {
            Tg::send($tg,
                "⚠️ <b>سفارش شما تکمیل نشد</b>\n\n"
                . "محصول: <b>" . h($pname) . "</b>\n"
                . ($back ? ('💰 مبلغ <b>' . money($money) . ' ' . currency() . "</b> به کیف پول شما بازگشت.\n") : '')
                . "می‌توانید دوباره خرید کنید یا به پشتیبانی پیام دهید.",
                Tg::ikb([
                    [Tg::btn('🛒 خرید مجدد', 'menu:products')],
                    [Tg::btn('🆘 پشتیبانی', 'tk:new')],
                ]));
        }

        Logs::send('errors', Logs::fmt('⏳ سفارش گیرکرده لغو شد', [
            'سفارش'      => '#' . $oid,
            'محصول'      => $pname,
            'کاربر'      => (string)$tg,
            'مبلغ'       => money($money) . ' ' . currency(),
            'بازگشت وجه' => $back ? 'انجام شد' : 'نیازی نبود',
        ]));
        app_log('orders', 'failed stuck order #' . $oid, ['refund' => $back, 'amount' => $money]);

        return ['ok' => true, 'action' => 'refunded', 'order' => $oid, 'refunded' => $back];
    }

    /** بازیابی گروهی؛ در کران‌جاب و پنل مدیریت استفاده می‌شود */
    public static function recoverStuck(?int $minutes = null, int $limit = 25): array
    {
        $rows = self::stuck($minutes, $limit);
        $out  = ['ok' => true, 'total' => count($rows), 'linked' => 0, 'refunded' => 0, 'items' => []];

        foreach ($rows as $o) {
            try {
                $r = self::recoverOne($o);
                if (($r['action'] ?? '') === 'linked') $out['linked']++;
                else $out['refunded']++;
                $out['items'][] = $r;
            } catch (Throwable $e) {
                app_log('orders', 'recover failed #' . (int)$o['id'] . ': ' . $e->getMessage());
                $out['items'][] = ['ok' => false, 'order' => (int)$o['id'], 'message' => $e->getMessage()];
            }
            usleep(150000);
        }

        $out['message'] = $out['total'] === 0
            ? 'سفارش گیرکرده‌ای یافت نشد.'
            : ('بررسی ' . $out['total'] . ' سفارش: ' . $out['linked'] . ' سرویس وصل شد، '
               . $out['refunded'] . ' مورد بازگشت وجه.');

        return $out;
    }

    /** آخرین سفارش‌ها برای داشبورد */
    public static function recent(int $limit = 8): array
    {
        $lim = max(1, min(50, $limit));
        return DB::all(
            "SELECT o.id, o.status, o.final_amount, o.created_at, o.tg_id,
                    p.name AS pname
               FROM {p}orders o
               LEFT JOIN {p}products p ON p.id = o.product_id
              ORDER BY o.id DESC
              LIMIT {$lim}");
    }

    /** فروش روزانه برای نمودار داشبورد */
    public static function dailySales(int $days = 14): array
    {
        $d    = max(2, min(60, $days));
        $rows = DB::all(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c, COALESCE(SUM(final_amount),0) AS s
               FROM {p}orders
              WHERE status = 'paid' AND created_at >= DATE_SUB(CURDATE(), INTERVAL {$d} DAY)
              GROUP BY DATE(created_at)
              ORDER BY d ASC");

        $map = [];
        foreach ($rows as $r) $map[(string)$r['d']] = ['count' => (int)$r['c'], 'sum' => (int)$r['s']];

        $out = [];
        for ($i = $d - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime('-' . $i . ' day'));
            $out[] = [
                'date'  => $day,
                'count' => (int)($map[$day]['count'] ?? 0),
                'sum'   => (int)($map[$day]['sum'] ?? 0),
            ];
        }
        return $out;
    }
}
