<?php
declare(strict_types=1);

/**
 * درگاه هوش‌پی (HooshPay) — کارت به کارت با تایید آنی
 *
 * مستندات: https://hooshpay.xyz/api/v1
 * جریان کار:
 *   ۱) ساخت فاکتور  → POST /invoices
 *   ۲) کاربر روی لینک payment_url پرداخت می‌کند
 *   ۳) هوش‌پی به /hooshpay.php کال‌بک می‌زند
 *   ۴) ما همیشه با POST /invoices/{uid}/verify پرداخت را دوباره استعلام می‌کنیم
 *      و تنها در صورت paid=true کیف پول را شارژ می‌کنیم.
 *
 * نکته: مبالغ هوش‌پی همیشه «تومان» است. اگر واحد فروشگاه ریال باشد،
 * تنظیم hp_unit را روی rial بگذارید تا تبدیل خودکار انجام شود.
 */
class HooshPay
{
    public const BASE = 'https://hooshpay.xyz/api/v1';

    /** روش تقسیم کارمزد درگاه */
    public const FEE_MODES = [
        'seller' => '🏪 کارمزد از فروشنده — خریدار دقیقاً مبلغ فاکتور را می‌پردازد',
        'buyer'  => '🙋 کارمزد از خریدار — شما کل مبلغ را دریافت می‌کنید',
        'split'  => '🤝 نصف‌نصف — کارمزد بین شما و خریدار تقسیم می‌شود',
    ];

    public const FEE_SHORT = [
        'seller' => 'از فروشنده',
        'buyer'  => 'از خریدار',
        'split'  => 'نصف‌نصف',
    ];

    public const UNITS = [
        'toman' => 'تومان — مبالغ فروشگاه تومان است',
        'rial'  => 'ریال — مبالغ فروشگاه ریال است (تقسیم بر ۱۰ می‌شود)',
    ];

    public const STATUS_FA = [
        'pending'   => 'در انتظار پرداخت',
        'paid'      => 'پرداخت‌شده و تاییدشده',
        'expired'   => 'مهلت پرداخت تمام شد',
        'cancelled' => 'لغو شد',
        'canceled'  => 'لغو شد',
        'failed'    => 'ناموفق',
    ];

    public const PAID = ['paid'];
    public const DEAD = ['expired', 'cancelled', 'canceled', 'failed'];

    public const ERRORS = [
        400 => 'ورودی نامعتبر — مبلغ خارج از محدودهٔ مجاز است',
        401 => 'کلید API نامعتبر است یا ارسال نشده',
        403 => 'حساب شما در هوش‌پی مسدود است',
        404 => 'فاکتور یافت نشد',
        503 => 'درگاه غیرفعال است یا کارتی برای دریافت موجود نیست',
    ];

    /* ==================== تنظیمات ==================== */

    public static function enabled(): bool
    {
        return (string)DB::setting('hp_enabled', '0') === '1' && self::key() !== '';
    }

    public static function key(): string    { return trim((string)DB::setting('hp_api_key', '')); }
    public static function secret(): string { return trim((string)DB::setting('hp_secret', '')); }
    public static function desc(): string   { return trim((string)DB::setting('hp_desc', '')); }

    public static function label(): string
    {
        $l = trim((string)DB::setting('hp_label', ''));
        return $l !== '' ? $l : 'پرداخت آنی کارت به کارت';
    }

    public static function icon(): string
    {
        $i = trim((string)DB::setting('hp_icon', ''));
        return $i !== '' ? $i : '🪙';
    }

    public static function feeMode(): string
    {
        $m = trim((string)DB::setting('hp_fee_mode', 'seller'));
        return isset(self::FEE_MODES[$m]) ? $m : 'seller';
    }

    /** واحد مبلغ فروشگاه: toman یا rial */
    public static function unit(): string
    {
        $u = trim((string)DB::setting('hp_unit', 'toman'));
        return $u === 'rial' ? 'rial' : 'toman';
    }

    public static function minAmount(): int { return max(0, (int)DB::setting('hp_min', '0')); }
    public static function maxAmount(): int { return max(0, (int)DB::setting('hp_max', '0')); }

    /** فقط برای نمایش در پنل — درصد کارمزد پیش‌فرض هوش‌پی */
    public static function feePercent(): float { return max(0, (float)DB::setting('hp_fee_percent', '20')); }

    public static function audience(): string
    {
        $a = trim((string)DB::setting('hp_audience', 'all'));
        return in_array($a, ['all', 'user', 'reseller'], true) ? $a : 'all';
    }

    public static function callbackUrl(): string { return (string)app_url('/hooshpay.php'); }

    public static function returnUrl(): string
    {
        $r = trim((string)DB::setting('hp_return_url', ''));
        return $r !== '' ? $r : self::callbackUrl() . '?done=1';
    }

    /** آیا این درگاه برای این کاربر قابل استفاده است؟ */
    public static function forUser(bool $isReseller = false): bool
    {
        if (!self::enabled()) return false;
        $a = self::audience();
        if ($a === 'user' && $isReseller) return false;
        if ($a === 'reseller' && !$isReseller) return false;
        return true;
    }

    /** برچسب کامل برای دکمهٔ ربات */
    public static function btnLabel(): string
    {
        return trim(self::icon() . ' ' . self::label());
    }

    public static function maskKey(): string
    {
        $k = self::key();
        if ($k === '') return '—';
        if (mb_strlen($k) <= 14) return $k;
        return mb_substr($k, 0, 10) . '…' . mb_substr($k, -4);
    }

    /* ==================== تبدیل واحد ==================== */

    /** مبلغ فروشگاه → تومان (هوش‌پی همیشه تومان می‌گیرد) */
    public static function toToman(int $amount): int
    {
        if (self::unit() === 'rial') return (int)floor($amount / 10);
        return $amount;
    }

    /** تومان → مبلغ فروشگاه */
    public static function fromToman(int $toman): int
    {
        if (self::unit() === 'rial') return $toman * 10;
        return $toman;
    }

    /* ==================== ارتباط با API ==================== */

    public static function api(string $path, array $body = [], string $method = 'GET'): array
    {
        $hdr = ['X-API-KEY: ' . self::key()];
        return http_json(self::BASE . $path, $body, $method, $hdr, 25);
    }

    /** ترجمهٔ خطای هوش‌پی به فارسی */
    public static function errFa(int $code, array $j = []): string
    {
        $m = trim((string)($j['message'] ?? ''));
        if ($m === '') $m = trim((string)($j['error'] ?? ''));
        if ($m !== '') return $m;
        if (isset(self::ERRORS[$code])) return self::ERRORS[$code];
        if ($code === 0) return 'اتصال به سرور هوش‌پی برقرار نشد';
        return 'پاسخ نامعتبر از هوش‌پی (کد ' . $code . ')';
    }

    public static function statusFa(string $s): string
    {
        $s = strtolower(trim($s));
        if (isset(self::STATUS_FA[$s])) return self::STATUS_FA[$s];
        return $s !== '' ? $s : 'نامشخص';
    }

    /** تست اتصال و صحت کلید API */
    public static function health(): array
    {
        if (self::key() === '') return ['ok' => false, 'message' => 'کلید API وارد نشده است.'];

        $r    = self::api('/account');
        $j    = is_array($r['json'] ?? null) ? $r['json'] : [];
        $code = (int)($r['code'] ?? 0);

        if ($code >= 200 && $code < 300 && !empty($j['success'])) {
            $d = is_array($j['data'] ?? null) ? $j['data'] : [];
            return ['ok' => true, 'message' => 'اتصال برقرار است و کلید API معتبر است.', 'data' => $d];
        }
        if ($code === 0) {
            return ['ok' => false, 'message' => 'اتصال برقرار نشد: ' . (string)($r['error'] ?? 'timeout')];
        }
        return ['ok' => false, 'message' => self::errFa($code, $j), 'code' => $code];
    }

    /** موجودی حساب هوش‌پی */
    public static function balance(): array
    {
        $r    = self::api('/balance');
        $j    = is_array($r['json'] ?? null) ? $r['json'] : [];
        $code = (int)($r['code'] ?? 0);

        if ($code >= 200 && $code < 300 && !empty($j['success'])) {
            $d = is_array($j['data'] ?? null) ? $j['data'] : $j;
            return ['ok' => true, 'data' => $d];
        }
        return ['ok' => false, 'message' => self::errFa($code, $j)];
    }

    /* ==================== ساخت فاکتور ==================== */

    public static function createInvoice(array $user, int $amount, int $txId): array
    {
        if (!self::enabled()) return ['ok' => false, 'message' => 'درگاه هوش‌پی فعال نیست.'];

        $min = self::minAmount();
        $max = self::maxAmount();
        if ($min > 0 && $amount < $min) {
            return ['ok' => false, 'message' => 'حداقل مبلغ این درگاه ' . money($min) . ' ' . currency() . ' است.'];
        }
        if ($max > 0 && $amount > $max) {
            return ['ok' => false, 'message' => 'حداکثر مبلغ این درگاه ' . money($max) . ' ' . currency() . ' است.'];
        }

        $toman = self::toToman($amount);
        if ($toman < 1000) {
            return ['ok' => false, 'message' => 'حداقل مبلغ قابل پذیرش هوش‌پی ۱۰۰۰ تومان است.'];
        }

        $order = 'HP' . $txId . 'X' . strtoupper(rnd(4));
        $desc  = self::desc();
        if ($desc === '') $desc = 'شارژ کیف پول';

        $body = [
            'amount'       => $toman,
            'fee_mode'     => self::feeMode(),
            'order_id'     => $order,
            'description'  => mb_substr($desc, 0, 180),
            'callback_url' => self::callbackUrl(),
            'return_url'   => self::returnUrl(),
        ];

        $r    = self::api('/invoices', $body, 'POST');
        $j    = is_array($r['json'] ?? null) ? $r['json'] : [];
        $code = (int)($r['code'] ?? 0);

        if ($code < 200 || $code >= 300 || empty($j['success'])) {
            return ['ok' => false, 'message' => 'ساخت فاکتور ناموفق بود: ' . self::errFa($code, $j)];
        }

        $d   = is_array($j['data'] ?? null) ? $j['data'] : [];
        $uid = trim((string)($d['uid'] ?? ''));
        $url = trim((string)($d['payment_url'] ?? ''));
        if ($uid === '' || $url === '') {
            return ['ok' => false, 'message' => 'پاسخ هوش‌پی ناقص بود (شناسه یا لینک پرداخت خالی است).'];
        }

        $payable = (int)($d['payable_amount'] ?? $toman);
        $credit  = (int)($d['merchant_credit'] ?? $toman);
        $feeAmt  = (int)($d['fee_amount'] ?? 0);
        $card    = is_array($d['card'] ?? null) ? $d['card'] : [];

        DB::update('transactions', [
            'ref'  => $order,
            'txid' => mb_substr($uid, 0, 180),
            'note' => mb_substr('HooshPay – ' . fa_num((string)$payable) . ' تومان – در انتظار پرداخت', 0, 250),
        ], 'id = :id', [':id' => $txId]);

        return [
            'ok'      => true,
            'url'     => $url,
            'uid'     => $uid,
            'order'   => $order,
            'toman'   => $toman,
            'payable' => $payable,
            'credit'  => $credit,
            'fee'     => $feeAmt,
            'expires' => (string)($d['expires_at'] ?? ''),
            'card'    => $card,
        ];
    }

    /* ==================== استعلام و تایید ==================== */

    /** وضعیت یک فاکتور */
    public static function status(string $uid): array
    {
        $uid = trim($uid);
        if ($uid === '') return ['ok' => false, 'message' => 'شناسهٔ فاکتور خالی است.'];

        $r    = self::api('/invoices/' . rawurlencode($uid));
        $j    = is_array($r['json'] ?? null) ? $r['json'] : [];
        $code = (int)($r['code'] ?? 0);

        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'message' => self::errFa($code, $j), 'code' => $code];
        }
        $d = is_array($j['data'] ?? null) ? $j['data'] : [];
        return ['ok' => true, 'status' => strtolower((string)($d['status'] ?? '')), 'data' => $d];
    }

    /** تایید نهایی پرداخت — مرجع اصلی ما برای شارژ کیف پول */
    public static function verifyInvoice(string $uid): array
    {
        $uid = trim($uid);
        if ($uid === '') return ['ok' => false, 'paid' => false, 'message' => 'شناسهٔ فاکتور خالی است.'];

        $r    = self::api('/invoices/' . rawurlencode($uid) . '/verify', [], 'POST');
        $j    = is_array($r['json'] ?? null) ? $r['json'] : [];
        $code = (int)($r['code'] ?? 0);

        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'paid' => false, 'message' => self::errFa($code, $j), 'code' => $code];
        }

        $d  = is_array($j['data'] ?? null) ? $j['data'] : [];
        $st = trim((string)($j['status'] ?? ''));
        if ($st === '') $st = trim((string)($d['status'] ?? ''));

        return [
            'ok'     => true,
            'paid'   => !empty($j['paid']),
            'status' => strtolower($st),
            'data'   => $d,
        ];
    }

    /** لغو فاکتور در انتظار */
    public static function cancel(string $uid): array
    {
        $uid = trim($uid);
        if ($uid === '') return ['ok' => false, 'message' => 'شناسهٔ فاکتور خالی است.'];

        $r    = self::api('/invoices/' . rawurlencode($uid) . '/cancel', [], 'POST');
        $j    = is_array($r['json'] ?? null) ? $r['json'] : [];
        $code = (int)($r['code'] ?? 0);

        if ($code >= 200 && $code < 300) return ['ok' => true, 'message' => 'فاکتور لغو شد.'];
        return ['ok' => false, 'message' => self::errFa($code, $j)];
    }

    /* ==================== امضای کال‌بک ==================== */

    /**
     * JSON مرتب‌شده بر اساس کلید (مطابق مستندات هوش‌پی)
     * مستندات فقط ksort ساده را می‌گوید؛ نسخهٔ تودرتو را هم برای
     * اطمینان در برابر تغییر احتمالی سرور حساب می‌کنیم.
     */
    public static function sortedJson(array $data, bool $deep = false): string
    {
        if ($deep) {
            $sort = function (array $a) use (&$sort) {
                ksort($a);
                foreach ($a as $k => $v) {
                    if (is_array($v)) $a[$k] = $sort($v);
                }
                return $a;
            };
            $data = $sort($data);
        } else {
            ksort($data);
        }
        return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** بررسی امضای HMAC-SHA256 کال‌بک */
    public static function checkSig(array $data, string $sig): bool
    {
        $sig = trim($sig);
        $sec = self::secret();
        if ($sec === '' || $sig === '') return false;

        foreach ([false, true] as $deep) {
            $exp = hash_hmac('sha256', self::sortedJson($data, $deep), $sec);
            if (hash_equals($exp, $sig)) return true;
        }
        return false;
    }

    /* ==================== اعمال نتیجهٔ پرداخت ==================== */

    /**
     * پرداخت را روی تراکنش اعمال می‌کند.
     * همیشه اول از خود هوش‌پی استعلام می‌گیرد تا کال‌بک جعلی نتواند
     * کیف پول را شارژ کند.
     */
    public static function apply(array $d, bool $trusted = false): array
    {
        $order = trim((string)($d['order_id'] ?? ''));
        $uid   = trim((string)($d['invoice'] ?? ''));
        if ($uid === '') $uid = trim((string)($d['uid'] ?? ''));
        $st    = strtolower(trim((string)($d['status'] ?? '')));

        $tx = null;
        if ($order !== '') {
            $tx = DB::one('SELECT * FROM {p}transactions WHERE ref = :r LIMIT 1', [':r' => $order]);
        }
        if (!$tx && $uid !== '') {
            $tx = DB::one('SELECT * FROM {p}transactions WHERE txid = :t LIMIT 1', [':t' => $uid]);
        }
        if (!$tx) {
            $who = $order !== '' ? $order : $uid;
            return ['ok' => false, 'message' => 'تراکنش مربوط پیدا نشد: ' . $who];
        }

        if ($uid === '') $uid = trim((string)($tx['txid'] ?? ''));

        /* تکراری / قبلاً پردازش‌شده */
        if ((string)$tx['status'] !== 'pending') {
            return ['ok' => true, 'duplicate' => true, 'message' => 'این تراکنش قبلاً بررسی شده است.', 'tx' => $tx];
        }

        /* استعلام مستقیم از هوش‌پی */
        $paid  = false;
        $stNow = $st;
        $track = trim((string)($d['tracking_code'] ?? ''));

        if ($uid !== '') {
            $v = self::verifyInvoice($uid);
            if (!empty($v['ok'])) {
                $paid = !empty($v['paid']);
                if ((string)($v['status'] ?? '') !== '') $stNow = (string)$v['status'];
                $vd = is_array($v['data'] ?? null) ? $v['data'] : [];
                if ($track === '') $track = trim((string)($vd['tracking_code'] ?? ''));
            } elseif ($trusted && $st === 'paid') {
                /* امضا معتبر بود ولی استعلام در دسترس نبود */
                $paid = true;
            } else {
                return ['ok' => false, 'message' => 'تایید فاکتور از هوش‌پی ناموفق بود: ' . (string)($v['message'] ?? '-')];
            }
        } elseif ($trusted && $st === 'paid') {
            $paid = true;
        } else {
            return ['ok' => false, 'message' => 'شناسهٔ فاکتور برای استعلام موجود نیست.'];
        }

        $user = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => (int)$tx['user_id']]);

        /* ---------- پرداخت موفق ---------- */
        if ($paid) {
            $res = Wallet::approve((int)$tx['id'], null, false);

            $note = 'HooshPay – پرداخت‌شده';
            if ($track !== '') $note .= ' – پیگیری ' . $track;
            DB::update('transactions', ['note' => mb_substr($note, 0, 250)], 'id = :id', [':id' => (int)$tx['id']]);

            if (!empty($res['ok']) && $user) {
                $msg = "\xe2\x9c\x85 <b>پرداخت شما تایید شد</b>\n"
                    . 'مبلغ ' . money((int)$tx['amount']) . ' ' . currency() . " به کیف پول شما افزوده شد.\n";
                if ($track !== '') $msg .= 'کد پیگیری: <code>' . h($track) . "</code>\n";
                $msg .= 'موجودی جدید: <b>' . money(Wallet::balance((int)$user['id'])) . ' ' . currency() . '</b>';
                try {
                    Tg::send((int)$user['tg_id'], $msg,
                        Tg::ikb([[Tg::btn('🛒 خرید سرویس', 'menu:products')]]));
                } catch (Throwable $e) {
                    /* ارسال پیام نباید مانع شارژ شود */
                }
            }

            try {
                Logs::send('financial', Logs::fmt('🪙 پرداخت هوش‌پی تایید شد', [
                    'کاربر'    => (string)($user['first_name'] ?? '-') . ' (' . (int)($user['tg_id'] ?? 0) . ')',
                    'مبلغ'     => money((int)$tx['amount']) . ' ' . currency(),
                    'کد پیگیری' => ($track !== '' ? $track : '-'),
                    'فاکتور'   => ($uid !== '' ? $uid : '-'),
                    'درگاه'    => 'HooshPay',
                ]));
            } catch (Throwable $e) {
                /* لاگ اختیاری است */
            }

            return ['ok' => true, 'paid' => true, 'message' => 'کیف پول شارژ شد.', 'tx' => $tx];
        }

        /* ---------- مرده: منقضی / لغو / ناموفق ---------- */
        if (in_array($stNow, self::DEAD, true)) {
            Wallet::reject((int)$tx['id'], null, 'HooshPay: ' . self::statusFa($stNow), false);
            if ($user) {
                try {
                    Tg::send((int)$user['tg_id'],
                        "\xe2\x9d\x8c پرداخت انجام نشد\nوضعیت: " . self::statusFa($stNow)
                        . "\nدر صورت واریز مبلغ، با پشتیبانی تماس بگیرید.");
                } catch (Throwable $e) {
                    /* نادیده */
                }
            }
            try {
                Logs::send('errors', Logs::fmt('⚠ پرداخت هوش‌پی ناموفق', [
                    'کاربر'  => (int)($user['tg_id'] ?? 0),
                    'مبلغ'   => money((int)$tx['amount']) . ' ' . currency(),
                    'وضعیت' => self::statusFa($stNow),
                    'فاکتور' => ($uid !== '' ? $uid : '-'),
                ]));
            } catch (Throwable $e) {
                /* لاگ اختیاری */
            }
            return ['ok' => true, 'message' => 'تراکنش به علت ' . self::statusFa($stNow) . ' رد شد.', 'tx' => $tx];
        }

        /* ---------- هنوز در انتظار ---------- */
        DB::update('transactions', [
            'note' => mb_substr('HooshPay – ' . self::statusFa($stNow), 0, 250),
        ], 'id = :id', [':id' => (int)$tx['id']]);

        return ['ok' => true, 'pending' => true, 'message' => 'وضعیت: ' . self::statusFa($stNow), 'tx' => $tx];
    }

    /** بررسی دستی تراکنش (دکمهٔ «بررسی پرداخت» یا کران) */
    public static function poll(array $tx): array
    {
        $uid = trim((string)($tx['txid'] ?? ''));
        $ref = trim((string)($tx['ref'] ?? ''));
        if ($uid === '') {
            return ['ok' => false, 'message' => 'شناسهٔ فاکتور روی این تراکنش ثبت نشده است.'];
        }
        return self::apply(['order_id' => $ref, 'invoice' => $uid], false);
    }

    /** خلاصهٔ وضعیت برای پنل مدیریت */
    public static function summary(): array
    {
        $paid = 0;
        $pend = 0;
        $sum  = 0;
        try {
            $paid = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE method = 'hooshpay' AND status = 'approved'", [], 0);
            $pend = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE method = 'hooshpay' AND status = 'pending'", [], 0);
            $sum  = (int)DB::val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE method = 'hooshpay' AND status = 'approved'", [], 0);
        } catch (Throwable $e) {
            /* جدول ممکن است هنوز نباشد */
        }
        return ['paid' => $paid, 'pending' => $pend, 'total' => $sum];
    }
}
