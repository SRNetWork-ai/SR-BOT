<?php
declare(strict_types=1);

/**
 * درگاه زرین‌پال (پرداخت خودکار) — 0.0.2 #22
 *
 * جریان کار:
 *   ۱) ساخت تراکنش «در انتظار» و گرفتن Authority از زرین‌پال
 *   ۲) هدایت کاربر به صفحهٔ StartPay
 *   ۳) بازگشت کاربر به /zarinpal.php
 *   ۴) استعلام دوبارهٔ پرداخت با verify و شارژ کیف پول فقط در صورت کد ۱۰۰ یا ۱۰۱
 *
 * واحد مبلغ فروشگاه با تنظیم zp_unit مشخص می‌شود (تومان = IRT، ریال = IRR).
 */
class Zarinpal
{
    public const BASE      = 'https://payment.zarinpal.com/pg/v4/payment';
    public const START     = 'https://payment.zarinpal.com/pg/StartPay/';
    public const BASE_BOX  = 'https://sandbox.zarinpal.com/pg/v4/payment';
    public const START_BOX = 'https://sandbox.zarinpal.com/pg/StartPay/';

    public const DEF_ICON = "\u{1F3E6}";

    public const UNITS = [
        'toman' => 'تومان — مبالغ فروشگاه تومان است',
        'rial'  => 'ریال — مبالغ فروشگاه ریال است',
    ];

    public const AUDIENCES = ['all', 'user', 'reseller'];

    public const ERRORS = [
        -9  => 'اطلاعات ارسالی نامعتبر است (مبلغ یا مرچنت کد)',
        -10 => 'مرچنت کد یا آی‌پی سرور درست نیست',
        -11 => 'درخواست یافت نشد',
        -12 => 'تعداد تلاش بیش از حد مجاز',
        -15 => 'درگاه پرداخت شما معلق است',
        -16 => 'سطح تایید پذیرنده پایین‌تر از حد مجاز است',
        -17 => 'محدودیت پذیرنده در سطح آبی',
        -30 => 'سرویس اشتراکی برای این پذیرنده فعال نیست',
        -31 => 'حساب بانکی متصل نیست یا هنوز تایید نشده است',
        -33 => 'مبلغ پرداخت با مبلغ درخواست یکسان نیست',
        -34 => 'سقف تقسیم پرداخت رد شده است',
        -40 => 'دسترسی به این متد وجود ندارد',
        -50 => 'مبلغ پرداخت‌شده با مبلغ ارسالی برابر نیست',
        -51 => 'پرداخت ناموفق بود',
        -52 => 'خطای غیرمنتظره از سمت زرین‌پال',
        -53 => 'این پرداخت متعلق به پذیرندهٔ دیگری است',
        -54 => 'کد رهگیری (Authority) نامعتبر است',
        101 => 'این پرداخت قبلاً تایید شده است',
    ];

    /* ==================== تنظیمات ==================== */

    public static function enabled(): bool
    {
        return (string)DB::setting('zp_enabled', '0') === '1' && self::merchant() !== '';
    }

    public static function merchant(): string { return trim((string)DB::setting('zp_merchant', '')); }
    public static function sandbox(): bool    { return (string)DB::setting('zp_sandbox', '0') === '1'; }
    public static function desc(): string     { return trim((string)DB::setting('zp_desc', '')); }

    /** واحد مبلغ فروشگاه: toman یا rial */
    public static function unit(): string
    {
        $u = trim((string)DB::setting('zp_unit', 'toman'));
        return isset(self::UNITS[$u]) ? $u : 'toman';
    }

    /** کد ارز برای API زرین‌پال */
    public static function currency(): string
    {
        return self::unit() === 'rial' ? 'IRR' : 'IRT';
    }

    public static function label(): string
    {
        $l = trim((string)DB::setting('zp_label', ''));
        return $l !== '' ? $l : 'پرداخت آنلاین با زرین‌پال';
    }

    public static function icon(): string
    {
        $i = trim((string)DB::setting('zp_icon', ''));
        return $i !== '' ? $i : self::DEF_ICON;
    }

    public static function minAmount(): int { return max(0, (int)DB::setting('zp_min', '0')); }
    public static function maxAmount(): int { return max(0, (int)DB::setting('zp_max', '0')); }

    public static function audience(): string
    {
        $a = trim((string)DB::setting('zp_audience', 'all'));
        return in_array($a, self::AUDIENCES, true) ? $a : 'all';
    }

    public static function forUser(bool $isReseller = false): bool
    {
        $a = self::audience();
        if ($a === 'user')     return !$isReseller;
        if ($a === 'reseller') return $isReseller;
        return true;
    }

    public static function btnLabel(): string
    {
        return trim(self::icon() . ' ' . self::label());
    }

    public static function callbackUrl(): string
    {
        return (string)app_url('/zarinpal.php');
    }

    public static function maskKey(): string
    {
        $m = self::merchant();
        if ($m === '') return '-';
        if (strlen($m) <= 12) return $m;
        return substr($m, 0, 8) . '...' . substr($m, -4);
    }

    /* ==================== ارتباط با زرین‌پال ==================== */

    public static function api(string $path, array $body): array
    {
        $base = self::sandbox() ? self::BASE_BOX : self::BASE;
        $ch   = curl_init($base . $path);
        if ($ch === false) {
            return ['ok' => false, 'code' => 0, 'data' => [], 'message' => 'امکان ارتباط با زرین‌پال نبود.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => (string)json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 25,
        ]);
        $raw  = curl_exec($ch);
        $cerr = (string)curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            return ['ok' => false, 'code' => 0, 'data' => [], 'http' => $http,
                'message' => 'ارتباط با زرین‌پال برقرار نشد' . ($cerr !== '' ? ' (' . $cerr . ')' : '')];
        }

        $j = json_decode($raw, true);
        if (!is_array($j)) {
            return ['ok' => false, 'code' => 0, 'data' => [], 'http' => $http, 'message' => 'پاسخ نامعتبر از زرین‌پال'];
        }

        $data = is_array($j['data'] ?? null) ? $j['data'] : [];
        $errs = $j['errors'] ?? [];
        if ($data === [] && is_array($errs) && $errs !== []) {
            $ec = (int)($errs['code'] ?? 0);
            return ['ok' => false, 'code' => $ec, 'data' => [], 'http' => $http,
                'message' => self::errFa($ec, (string)($errs['message'] ?? ''))];
        }

        return ['ok' => true, 'code' => (int)($data['code'] ?? 0), 'data' => $data, 'http' => $http, 'message' => ''];
    }

    public static function errFa(int $code, string $fallback = ''): string
    {
        if (isset(self::ERRORS[$code])) return self::ERRORS[$code];
        if ($fallback !== '') return $fallback;
        return 'خطای زرین‌پال (کد ' . $code . ')';
    }

    /* ==================== پرداخت ==================== */

    /**
     * ساخت تراکنش در انتظار و گرفتن لینک پرداخت
     * خروجی: ok, url, authority, tx, message
     */
    public static function createInvoice(array $user, int $amount, int $txId = 0): array
    {
        $fail = function (string $m) use ($txId): array {
            return ['ok' => false, 'url' => '', 'authority' => '', 'tx' => $txId, 'message' => $m];
        };

        if (!self::enabled())  return $fail('درگاه زرین‌پال فعال نیست.');
        if ($amount <= 0)      return $fail('مبلغ نامعتبر است.');

        $min = self::minAmount();
        $max = self::maxAmount();
        if ($min > 0 && $amount < $min) return $fail('حداقل مبلغ پرداخت ' . money($min) . ' است.');
        if ($max > 0 && $amount > $max) return $fail('حداکثر مبلغ پرداخت ' . money($max) . ' است.');

        if ($txId <= 0) {
            if (!class_exists('Wallet')) return $fail('ماژول کیف پول در دسترس نیست.');
            $txId = (int)Wallet::createDeposit($user, $amount, 'zarinpal');
        }
        if ($txId <= 0) return $fail('ثبت تراکنش انجام نشد.');

        $desc = self::desc();
        if ($desc === '') $desc = 'شارژ کیف پول';

        $meta = [];
        $mob  = preg_replace('/\D+/', '', (string)($user['phone'] ?? ''));
        if (is_string($mob) && strlen($mob) >= 10) $meta['mobile'] = substr($mob, -11);
        $mail = trim((string)($user['email'] ?? ''));
        if ($mail !== '') $meta['email'] = $mail;

        $body = [
            'merchant_id'  => self::merchant(),
            'amount'       => $amount,
            'currency'     => self::currency(),
            'callback_url' => self::callbackUrl(),
            'description'  => $desc . ' #' . $txId,
        ];
        if ($meta !== []) $body['metadata'] = $meta;

        $res = self::api('/request.json', $body);
        if (empty($res['ok']) || (int)($res['code'] ?? 0) !== 100) {
            app_log('zarinpal', 'request failed: ' . (string)($res['message'] ?? ''), ['tx' => $txId]);
            return ['ok' => false, 'url' => '', 'authority' => '', 'tx' => $txId,
                'message' => (string)($res['message'] ?? 'ساخت لینک پرداخت ناموفق بود.')];
        }

        $authority = trim((string)($res['data']['authority'] ?? ''));
        if ($authority === '') return $fail('کد رهگیری از زرین‌پال دریافت نشد.');

        try {
            DB::update('transactions', ['txid' => $authority], 'id = :id', [':id' => $txId]);
        } catch (Throwable $e) {
            app_log('zarinpal', 'store authority failed: ' . $e->getMessage(), ['tx' => $txId]);
        }

        $start = self::sandbox() ? self::START_BOX : self::START;
        return ['ok' => true, 'url' => $start . $authority, 'authority' => $authority, 'tx' => $txId, 'message' => ''];
    }

    public static function verify(string $authority, int $amount): array
    {
        return self::api('/verify.json', [
            'merchant_id' => self::merchant(),
            'amount'      => $amount,
            'authority'   => $authority,
        ]);
    }

    /** پردازش بازگشت کاربر از درگاه: استعلام و شارژ کیف پول */
    public static function apply(string $authority, string $status = 'OK'): array
    {
        $authority = trim($authority);
        if ($authority === '') return ['ok' => false, 'message' => 'کد رهگیری ارسال نشده است.'];

        $tx = DB::one('SELECT * FROM {p}transactions WHERE method = :m AND txid = :a ORDER BY id DESC LIMIT 1',
            [':m' => 'zarinpal', ':a' => $authority]);
        if (!$tx) return ['ok' => false, 'message' => 'تراکنشی برای این پرداخت پیدا نشد.'];

        $txId   = (int)$tx['id'];
        $amount = (int)$tx['amount'];

        if ((string)$tx['status'] === 'approved') {
            return ['ok' => true, 'already' => true, 'tx' => $txId, 'amount' => $amount,
                'message' => 'این پرداخت قبلاً تایید و کیف پول شارژ شده است.'];
        }

        if (strtoupper($status) !== 'OK') {
            try {
                DB::update('transactions', ['status' => 'rejected', 'decided_at' => now()],
                    'id = :id AND status = :s', [':id' => $txId, ':s' => 'pending']);
            } catch (Throwable $e) {
                app_log('zarinpal', 'cancel update failed: ' . $e->getMessage(), ['tx' => $txId]);
            }
            return ['ok' => false, 'tx' => $txId, 'message' => 'پرداخت لغو شد یا ناموفق بود.'];
        }

        $res  = self::verify($authority, $amount);
        $code = (int)($res['code'] ?? 0);

        if (empty($res['ok']) && $code !== 101) {
            app_log('zarinpal', 'verify failed: ' . (string)($res['message'] ?? ''), ['tx' => $txId]);
            return ['ok' => false, 'tx' => $txId, 'message' => (string)($res['message'] ?? 'تایید پرداخت ناموفق بود.')];
        }
        if ($code !== 100 && $code !== 101) {
            return ['ok' => false, 'tx' => $txId, 'message' => self::errFa($code)];
        }

        $ref = (string)($res['data']['ref_id'] ?? '');
        if ($ref !== '') {
            try {
                DB::update('transactions', ['note' => 'zarinpal ref ' . $ref], 'id = :id', [':id' => $txId]);
            } catch (Throwable $e) {
                app_log('zarinpal', 'store ref failed: ' . $e->getMessage(), ['tx' => $txId]);
            }
        }

        if (!class_exists('Wallet')) {
            return ['ok' => false, 'tx' => $txId, 'message' => 'ماژول کیف پول در دسترس نیست.'];
        }

        $ap = Wallet::approve($txId, null, true);
        if (empty($ap['ok'])) {
            return ['ok' => false, 'tx' => $txId, 'message' => (string)($ap['message'] ?? 'شارژ کیف پول انجام نشد.')];
        }

        try {
            if (class_exists('Logs')) {
                Logs::send('payments', Logs::fmt("\u{2705} پرداخت زرین‌پال تایید شد", [
                    'مبلغ'    => money($amount),
                    'تراکنش'  => '#' . $txId,
                    'رهگیری'  => $ref !== '' ? $ref : '-',
                ]));
            }
        } catch (Throwable $e) {
            /* لاگ نباید مانع تایید پرداخت شود */
        }

        return ['ok' => true, 'tx' => $txId, 'amount' => $amount, 'ref' => $ref,
            'message' => 'پرداخت تایید و کیف پول شارژ شد.'];
    }

    /** استعلام دستی یک تراکنش از پنل مدیریت */
    public static function poll(array $tx): array
    {
        $a = trim((string)($tx['txid'] ?? ''));
        if ($a === '') return ['ok' => false, 'message' => 'کد رهگیری برای این تراکنش ثبت نشده است.'];
        return self::apply($a, 'OK');
    }

    /* ==================== وضعیت ==================== */

    public static function health(): array
    {
        if (self::merchant() === '') return ['ok' => false, 'message' => 'مرچنت کد ثبت نشده است.'];
        if (strlen(self::merchant()) !== 36) return ['ok' => false, 'message' => 'مرچنت کد باید ۳۶ کاراکتر باشد.'];
        if (!self::enabled()) return ['ok' => false, 'message' => 'درگاه خاموش است.'];
        return ['ok' => true, 'message' => 'آمادهٔ دریافت پرداخت'];
    }

    public static function summary(): array
    {
        $out = ['enabled' => self::enabled(), 'pending' => 0, 'approved' => 0, 'sum' => 0];
        try {
            $out['pending']  = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE method = 'zarinpal' AND status = 'pending'");
            $out['approved'] = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE method = 'zarinpal' AND status = 'approved'");
            $out['sum']      = (int)DB::val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE method = 'zarinpal' AND status = 'approved'");
        } catch (Throwable $e) {
            /* جدول ممکن است هنوز ساخته نشده باشد */
        }
        return $out;
    }
}
