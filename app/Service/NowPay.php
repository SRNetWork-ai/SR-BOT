<?php
declare(strict_types=1);

/**
 * درگاه پرداخت ارزی NowPayments – ساخت فاکتور و تایید خودکار با IPN
 */
class NowPay
{
    public const BASE = 'https://api.nowpayments.io/v1';

    public const STATUS_FA = [
        'waiting'        => 'در انتظار پرداخت',
        'confirming'     => 'در حال تایید شبکه',
        'confirmed'      => 'تایید شده',
        'sending'        => 'در حال انتقال',
        'partially_paid' => 'پرداخت ناقص',
        'finished'       => 'تکمیل شده',
        'failed'         => 'ناموفق',
        'refunded'       => 'بازگشت داده شده',
        'expired'        => 'منقضی شده',
    ];

    /** وضعیت‌هایی که پرداخت را موفق می‌دانیم */
    public const PAID = ['finished', 'confirmed'];
    public const DEAD = ['failed', 'expired', 'refunded'];

    public static function enabled(): bool
    {
        return (string)DB::setting('nowpay_enabled', '0') === '1' && self::key() !== '';
    }

    public static function key(): string       { return trim((string)DB::setting('nowpay_api_key', '')); }
    public static function ipnSecret(): string { return trim((string)DB::setting('nowpay_ipn_secret', '')); }
    public static function minUsd(): float     { return max(1, (float)DB::setting('nowpay_min_usd', 5)); }
    public static function payCurrency(): string { return trim((string)DB::setting('nowpay_currency', '')); }

    public static function callbackUrl(): string { return app_url('/nowpay.php'); }

    public static function api(string $path, array $body = [], string $method = 'GET'): array
    {
        if (self::key() === '') return ['code' => 0, 'json' => [], 'body' => '', 'error' => 'api key missing'];
        $r = http_json(self::BASE . $path, $body, $method, ['x-api-key: ' . self::key()], 25);
        if ($r['code'] >= 400 || $r['error'] !== '') {
            app_log('nowpay', 'api error', ['path' => $path, 'code' => $r['code'], 'res' => mb_substr($r['body'], 0, 300)]);
        }
        return $r;
    }

    /** تست اتصال و کلید */
    public static function health(): array
    {
        $s = self::api('/status');
        if (($s['json']['message'] ?? '') !== 'OK') {
            return ['ok' => false, 'message' => 'سرویس NowPayments در دسترس نیست.'];
        }
        $b = self::api('/balance');
        if ($b['code'] === 401 || $b['code'] === 403) {
            return ['ok' => false, 'message' => 'کلید API معتبر نیست (خطای ' . $b['code'] . ').'];
        }
        $curs = self::api('/currencies');
        $n    = is_array($curs['json']['currencies'] ?? null) ? count($curs['json']['currencies']) : 0;
        return ['ok' => true, 'message' => 'اتصال موفق بود. ' . fa_num((string)$n) . ' ارز در دسترس است.', 'currencies' => $n];
    }

    public static function minAmount(string $cur): ?float
    {
        $r = self::api('/min-amount', ['currency_from' => $cur, 'currency_to' => 'usd']);
        $v = (float)($r['json']['min_amount'] ?? 0);
        return $v > 0 ? $v : null;
    }

    /**
     * ساخت فاکتور پرداخت برای یک تراکنش در انتظار
     * @param array $user ردیف کاربر
     * @param int   $amount مبلغ بر حسب واحد فروشگاه
     * @param int   $txId شناسه تراکنش
     */
    public static function createInvoice(array $user, int $amount, int $txId): array
    {
        if (!self::enabled()) return ['ok' => false, 'message' => 'درگاه ارزی فعال نیست.'];

        $usd = Rates::amountToUsd($amount);
        if ($usd < self::minUsd()) {
            return ['ok' => false, 'message' => 'حداقل مبلغ پرداخت خودکار معادل ' . self::minUsd() . ' دلار است.'];
        }

        $order = 'VS' . $txId . 'X' . strtoupper(rnd(4));
        $body  = [
            'price_amount'      => $usd,
            'price_currency'    => 'usd',
            'order_id'          => $order,
            'order_description' => 'Wallet top-up for ' . (int)$user['tg_id'],
            'ipn_callback_url'  => self::callbackUrl(),
            'is_fixed_rate'     => true,
            'is_fee_paid_by_user' => true,
        ];
        if (self::payCurrency() !== '') $body['pay_currency'] = self::payCurrency();

        $r = self::api('/invoice', $body, 'POST');
        $j = is_array($r['json']) ? $r['json'] : [];
        $url = (string)($j['invoice_url'] ?? '');
        if ($url === '') {
            $msg = (string)($j['message'] ?? 'پاسخ نامعتبر از درگاه');
            return ['ok' => false, 'message' => 'ساخت فاکتور ناموفق بود: ' . $msg];
        }

        DB::update('transactions', [
            'ref'  => $order,
            'txid' => 'INV-' . (string)($j['id'] ?? ''),
            'note' => 'NowPayments – ' . $usd . '$ – در انتظار پرداخت',
        ], 'id = :id', [':id' => $txId]);

        return ['ok' => true, 'url' => $url, 'usd' => $usd, 'order' => $order, 'invoice_id' => (string)($j['id'] ?? '')];
    }

    /** وضعیت یک پرداخت */
    public static function payment(string $paymentId): array
    {
        $r = self::api('/payment/' . rawurlencode($paymentId));
        return is_array($r['json']) ? $r['json'] : [];
    }

    /** پرداخت‌های مربوط به یک فاکتور (برای کران) */
    public static function paymentsOfInvoice(string $invoiceId): array
    {
        $r = self::api('/payment', ['limit' => 20, 'page' => 0, 'sortBy' => 'created_at', 'orderBy' => 'desc']);
        $list = $r['json']['data'] ?? [];
        $out  = [];
        foreach ((array)$list as $p) {
            if ((string)($p['invoice_id'] ?? '') === $invoiceId) $out[] = $p;
        }
        return $out;
    }

    /** بررسی وضعیت یک تراکنش در انتظار (دکمهٔ بررسی یا کران) */
    public static function poll(array $tx): array
    {
        $ref = (string)($tx['txid'] ?? '');

        if (strpos($ref, 'INV-') === 0) {
            $pays = self::paymentsOfInvoice(substr($ref, 4));
            if (!$pays) return ['ok' => false, 'pending' => true, 'message' => 'هنوز پرداختی برای این فاکتور ثبت نشده است.'];
            $p = $pays[0];
            if (empty($p['order_id'])) $p['order_id'] = (string)$tx['ref'];
            return self::apply($p);
        }

        if ($ref !== '') {
            $p = self::payment($ref);
            if (!empty($p['payment_status'])) {
                if (empty($p['order_id'])) $p['order_id'] = (string)$tx['ref'];
                return self::apply($p);
            }
        }

        return ['ok' => false, 'pending' => true, 'message' => 'اطلاعات پرداخت در دسترس نیست.'];
    }

    /** امضای HMAC-SHA512 بر اساس JSON مرتب‌شده */
    public static function sortedJson(array $data): string
    {
        $sort = function (array $arr) use (&$sort) {
            ksort($arr);
            foreach ($arr as $k => $v) if (is_array($v)) $arr[$k] = $sort($v);
            return $arr;
        };
        return (string)json_encode($sort($data), JSON_UNESCAPED_SLASHES);
    }

    public static function verify(array $data, string $signature): bool
    {
        $secret = self::ipnSecret();
        if ($secret === '' || $signature === '') return false;
        $calc = hash_hmac('sha512', self::sortedJson($data), $secret);
        return hash_equals(strtolower($calc), strtolower(trim($signature)));
    }

    public static function statusFa(string $s): string
    {
        return self::STATUS_FA[$s] ?? $s;
    }

    /**
     * پردازش پیام IPN یا نتیجه پالینگ و تایید خودکار کیف پول
     * ورودی: آرایه‌ی پرداخت NowPayments
     */
    public static function apply(array $d): array
    {
        $order  = (string)($d['order_id'] ?? '');
        $status = strtolower((string)($d['payment_status'] ?? ''));
        if ($order === '') return ['ok' => false, 'message' => 'order_id خالی است.'];

        $tx = DB::one('SELECT * FROM {p}transactions WHERE ref = :r LIMIT 1', [':r' => $order]);
        if (!$tx) return ['ok' => false, 'message' => 'تراکنش مربوط پیدا نشد: ' . $order];

        $user = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => (int)$tx['user_id']]);
        $paid = (float)($d['actually_paid'] ?? 0);
        $pay  = strtoupper((string)($d['pay_currency'] ?? ''));
        $note = 'NowPayments – ' . self::statusFa($status) . ($paid > 0 ? ' – ' . $paid . ' ' . $pay : '');

        // تکراری / قبلاً پردازش‌شده
        if ($tx['status'] !== 'pending') {
            return ['ok' => true, 'duplicate' => true, 'message' => 'تراکنش قبلاً بررسی شده است.', 'tx' => $tx];
        }

        if (in_array($status, self::PAID, true)) {
            $res = Wallet::approve((int)$tx['id'], null);
            DB::update('transactions', [
                'txid' => mb_substr((string)($d['payment_id'] ?? $tx['txid']), 0, 180),
                'note' => mb_substr($note, 0, 250),
            ], 'id = :id', [':id' => (int)$tx['id']]);

            if (!empty($res['ok']) && $user) {
                Tg::send((int)$user['tg_id'],
                    "✅ <b>پرداخت ارزی تایید شد</b>\n"
                    . 'مبلغ ' . money((int)$tx['amount']) . ' ' . currency() . " به کیف پول شما افزوده شد.\n"
                    . 'موجودی جدید: <b>' . money(Wallet::balance((int)$user['id'])) . ' ' . currency() . '</b>',
                    Tg::ikb([[Tg::btn('🛒 خرید سرویس', 'menu:products')]]));
            }

            Logs::send('financial', Logs::fmt('🌐 پرداخت ارزی خودکار تایید شد', [
                'کاربر'   => ($user['first_name'] ?? '-') . ' (' . (int)($user['tg_id'] ?? 0) . ')',
                'مبلغ'    => money((int)$tx['amount']) . ' ' . currency(),
                'پرداختی' => ($paid > 0 ? $paid . ' ' . $pay : '-'),
                'فاکتور'  => $order,
                'درگاه'   => 'NowPayments',
            ]));

            return ['ok' => true, 'message' => 'کیف پول شارژ شد.', 'tx' => $tx];
        }

        if (in_array($status, self::DEAD, true)) {
            Wallet::reject((int)$tx['id'], null, 'NowPayments: ' . self::statusFa($status));
            if ($user) {
                Tg::send((int)$user['tg_id'], "❌ پرداخت ارزی انجام نشد\nوضعیت: " . self::statusFa($status)
                    . "\nدر صورت واریز مبلغ، با پشتیبانی تماس بگیرید.");
            }
            Logs::send('errors', Logs::fmt('⚠ پرداخت ارزی ناموفق', [
                'کاربر'  => (int)($user['tg_id'] ?? 0),
                'مبلغ'   => money((int)$tx['amount']) . ' ' . currency(),
                'وضعیت' => self::statusFa($status),
                'فاکتور' => $order,
            ]));
            return ['ok' => true, 'message' => 'تراکنش به علت ' . self::statusFa($status) . ' رد شد.', 'tx' => $tx];
        }

        // در حال انتظار/تایید شبکه – فقط یادداشت را به‌روز می‌کنیم
        DB::update('transactions', ['note' => mb_substr($note, 0, 250)], 'id = :id', [':id' => (int)$tx['id']]);
        return ['ok' => true, 'pending' => true, 'message' => 'وضعیت: ' . self::statusFa($status), 'tx' => $tx];
    }
}
