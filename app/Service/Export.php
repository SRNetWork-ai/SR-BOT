<?php
/**
 * خروجی CSV (سازگار با Excel — UTF-8 با BOM) — fixed74
 *
 *  Export::kinds()            فهرست خروجی‌ها
 *  Export::rows($kind, $opt)  سرستون‌ها + سطرها
 *  Export::file($kind, $opt)  ساخت فایل موقت در storage/tmp و برگرداندن مسیر
 *  Export::stream($kind, $opt) ارسال مستقیم به مرورگر (پنل وب)
 *
 *  $opt: ['days' => 30]   فقط رکوردهای N روز اخیر (۰ = همه)
 *        ['limit' => 5000] سقف سطر
 */
class Export
{
    public const MAX_ROWS = 20000;

    public static function kinds(): array
    {
        return [
            'users'        => ['👥', 'کاربران'],
            'services'     => ['🔑', 'سرویس‌ها'],
            'orders'       => ['🧾', 'سفارش‌ها'],
            'transactions' => ['💳', 'تراکنش‌ها'],
            'resellers'    => ['🏷', 'نمایندگان'],
            'expiring'     => ['⏰', 'رو به انقضا (۷ روز)'],
        ];
    }

    public static function label(string $kind): string
    {
        $k = self::kinds()[$kind] ?? null;
        return $k ? $k[0] . ' ' . $k[1] : $kind;
    }

    /** @return array{0: string[], 1: array<int, array>} */
    public static function rows(string $kind, array $opt = []): array
    {
        $days  = max(0, (int)($opt['days'] ?? 0));
        $limit = max(1, min(self::MAX_ROWS, (int)($opt['limit'] ?? self::MAX_ROWS)));
        $since = $days > 0 ? date('Y-m-d 00:00:00', time() - $days * 86400) : '';

        switch ($kind) {
            case 'users':
                $w = $since !== '' ? 'WHERE u.created_at >= :s' : '';
                $rs = DB::all("SELECT u.*, 
                        (SELECT COUNT(*) FROM {p}services s WHERE s.user_id = u.id AND s.status <> 'deleted') AS svc_n,
                        (SELECT COUNT(*) FROM {p}services s WHERE s.user_id = u.id AND s.status = 'active') AS svc_act
                    FROM {p}users u $w ORDER BY u.id DESC LIMIT $limit", $since !== '' ? [':s' => $since] : []);
                $head = ['id', 'tg_id', 'username', 'first_name', 'last_name', 'phone', 'balance', 'total_paid', 'services', 'active_services', 'reseller_level', 'is_banned', 'referrer_id', 'created_at', 'last_seen'];
                $out = [];
                foreach ($rs as $r) $out[] = [
                    $r['id'], $r['tg_id'], $r['username'], $r['first_name'], $r['last_name'], $r['phone'],
                    $r['balance'], $r['total_paid'], $r['svc_n'], $r['svc_act'], $r['reseller_level'] ?? 0,
                    $r['is_banned'], $r['referrer_id'], $r['created_at'], $r['last_seen'],
                ];
                return [$head, $out];

            case 'services':
            case 'expiring':
                $w = "WHERE s.status <> 'deleted'";
                $p = [];
                if ($kind === 'expiring') {
                    $w .= " AND s.status = 'active' AND s.expire_at IS NOT NULL AND s.expire_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)";
                } elseif ($since !== '') {
                    $w .= ' AND s.created_at >= :s'; $p[':s'] = $since;
                }
                $rs = DB::all("SELECT s.*, u.username AS u_username, u.first_name AS u_name, p.name AS panel_name, pr.name AS product_name
                    FROM {p}services s
                    LEFT JOIN {p}users u ON u.id = s.user_id
                    LEFT JOIN {p}panels p ON p.id = s.panel_id
                    LEFT JOIN {p}products pr ON pr.id = s.product_id
                    $w ORDER BY " . ($kind === 'expiring' ? 's.expire_at ASC' : 's.id DESC') . " LIMIT $limit", $p);
                $head = ['id', 'client_email', 'status', 'tg_id', 'username', 'name', 'panel', 'product', 'volume_gb', 'used_gb', 'days', 'expire_at', 'renew_count', 'is_test', 'is_reseller', 'sub_link', 'created_at', 'last_sync'];
                $out = [];
                foreach ($rs as $r) $out[] = [
                    $r['id'], $r['client_email'], $r['status'], $r['tg_id'], $r['u_username'], $r['u_name'],
                    $r['panel_name'], $r['product_name'], $r['volume_gb'], round(((int)$r['used_bytes']) / 1073741824, 3),
                    $r['days'], $r['expire_at'], $r['renew_count'], $r['is_test'], $r['is_reseller'] ?? 0,
                    $r['sub_link'], $r['created_at'], $r['last_sync'],
                ];
                return [$head, $out];

            case 'orders':
                $w = $since !== '' ? 'WHERE o.created_at >= :s' : '';
                $rs = DB::all("SELECT o.*, u.username AS u_username, pr.name AS product_name
                    FROM {p}orders o LEFT JOIN {p}users u ON u.id = o.user_id LEFT JOIN {p}products pr ON pr.id = o.product_id
                    $w ORDER BY o.id DESC LIMIT $limit", $since !== '' ? [':s' => $since] : []);
                $head = ['id', 'tg_id', 'username', 'type', 'product', 'service_id', 'amount', 'discount_code', 'discount_amount', 'final_amount', 'status', 'created_at'];
                $out = [];
                foreach ($rs as $r) $out[] = [
                    $r['id'], $r['tg_id'], $r['u_username'], $r['type'], $r['product_name'], $r['service_id'],
                    $r['amount'], $r['discount_code'], $r['discount_amount'], $r['final_amount'], $r['status'], $r['created_at'],
                ];
                return [$head, $out];

            case 'transactions':
                $w = $since !== '' ? 'WHERE t.created_at >= :s' : '';
                $rs = DB::all("SELECT t.*, u.username AS u_username FROM {p}transactions t LEFT JOIN {p}users u ON u.id = t.user_id
                    $w ORDER BY t.id DESC LIMIT $limit", $since !== '' ? [':s' => $since] : []);
                $head = ['id', 'tg_id', 'username', 'type', 'method', 'amount', 'status', 'ref', 'txid', 'note', 'admin_id', 'created_at', 'decided_at'];
                $out = [];
                foreach ($rs as $r) $out[] = [
                    $r['id'], $r['tg_id'], $r['u_username'], $r['type'], $r['method'], $r['amount'], $r['status'],
                    $r['ref'], $r['txid'], $r['note'], $r['admin_id'], $r['created_at'], $r['decided_at'],
                ];
                return [$head, $out];

            case 'resellers':
                $rs = DB::all("SELECT u.*, 
                        (SELECT COUNT(*) FROM {p}services s WHERE s.user_id = u.id AND s.status = 'active') AS svc_act,
                        (SELECT COUNT(*) FROM {p}users r WHERE r.referrer_id = u.tg_id) AS subs
                    FROM {p}users u WHERE COALESCE(u.reseller_level,0) > 0 ORDER BY u.balance ASC LIMIT $limit");
                $head = ['id', 'tg_id', 'username', 'first_name', 'phone', 'reseller_level', 'balance', 'credit_used', 'total_paid', 'active_services', 'sub_users', 'created_at'];
                $out = [];
                foreach ($rs as $r) $out[] = [
                    $r['id'], $r['tg_id'], $r['username'], $r['first_name'], $r['phone'], $r['reseller_level'],
                    $r['balance'], (int)$r['balance'] < 0 ? abs((int)$r['balance']) : 0, $r['total_paid'], $r['svc_act'], $r['subs'], $r['created_at'],
                ];
                return [$head, $out];
        }
        throw new InvalidArgumentException('نوع خروجی نامعتبر است.');
    }

    /** متن CSV کامل */
    public static function csv(string $kind, array $opt = []): string
    {
        [$head, $rows] = self::rows($kind, $opt);
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF"); /* BOM برای نمایش درست فارسی در Excel */
        fputcsv($fh, $head);
        foreach ($rows as $r) {
            $line = [];
            foreach ($r as $v) {
                $v = $v === null ? '' : (string)$v;
                /* جلوگیری از CSV injection */
                if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric($v)) $v = "'" . $v;
                $line[] = $v;
            }
            fputcsv($fh, $line);
        }
        rewind($fh);
        $s = stream_get_contents($fh);
        fclose($fh);
        return (string)$s;
    }

    /** ساخت فایل موقت و برگرداندن مسیر (پس از ارسال unlink کنید) */
    public static function file(string $kind, array $opt = []): array
    {
        $dir = APP_ROOT . '/storage/tmp';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $name = 'export-' . preg_replace('/[^a-z_]/', '', $kind) . '-' . date('Ymd-His') . '.csv';
        $path = $dir . '/' . $name;
        [$head, $rows] = self::rows($kind, $opt);
        $csv = self::csv($kind, $opt);
        if (@file_put_contents($path, $csv) === false) {
            return ['ok' => false, 'message' => 'نوشتن فایل در storage/tmp ممکن نیست.'];
        }
        return ['ok' => true, 'path' => $path, 'name' => $name, 'rows' => count($rows), 'size' => strlen($csv)];
    }

    /** دانلود مستقیم در پنل وب */
    public static function stream(string $kind, array $opt = []): void
    {
        $csv  = self::csv($kind, $opt);
        $name = 'export-' . preg_replace('/[^a-z_]/', '', $kind) . '-' . date('Ymd-His') . '.csv';
        if (ob_get_level()) { while (ob_get_level()) ob_end_clean(); }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($csv));
        header('Cache-Control: no-store');
        echo $csv;
        exit;
    }
}
