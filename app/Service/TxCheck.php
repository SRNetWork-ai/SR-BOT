<?php

declare(strict_types=1);

/**
 * هش‌چکر – بررسی خودکار هش تراکنش ارزی
 *
 * کاربر فقط هش (TXID) را می‌فرستد؛ این کلاس خودش تشخیص می‌دهد متعلق به کدام
 * شبکه است، از اکسپلورر عمومی استعلام می‌گیرد، آدرس مقصد و مبلغ را مقایسه
 * می‌کند و در صورت تطابق، واریز را تایید خودکار می‌کند.
 *
 * اگر هر دلیلی برای عدم قطعیت وجود داشته باشد (شبکهٔ ناشناس، عدم دسترسی به
 * اکسپلورر، نرخ نامعلوم، مبلغ کمتر و …) نتیجه به فاز تایید دستی منتقل می‌شود.
 */
class TxCheck
{
    /** شبکه‌های پشتیبانی‌شده */
    public const CHAINS = [
        'TRON' => ['label' => 'ترون (TRC20)',   'icon' => '⚡️', 'key' => false],
        'TON'  => ['label' => 'تون (TON)',        'icon' => '💎', 'key' => false],
        'BSC'  => ['label' => 'بایننس (BEP20)', 'icon' => '🟡', 'key' => true],
        'ETH'  => ['label' => 'اتریوم (ERC20)', 'icon' => '🔷', 'key' => true],
    ];

    /** پیام وضعیت‌ها */
    public const STATUS_FA = [
        'confirmed'     => '✅ تراکنش تایید شد',
        'pending'       => '⏳ تراکنش هنوز در شبکه تایید نشده است',
        'notfound'      => '❓ تراکنشی با این هش پیدا نشد',
        'wrong_address' => '⚠️ آدرس مقصد با آدرس فروشگاه مطابقت ندارد',
        'low_amount'    => '⚠️ مبلغ واریزی کمتر از مبلغ سفارش است',
        'duplicate'     => '🔁 این هش پیش‌تر استفاده شده است',
        'unsupported'   => '🛠 بررسی خودکار امکان‌پذیر نبود',
        'error'         => '⚠️ خطا در بررسی خودکار',
    ];

    private const ERC20_TRANSFER = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    /* ==================== تنظیمات ==================== */

    public static function enabled(): bool     { return (string)DB::setting('txc_enabled', '1') === '1'; }
    public static function autoApprove(): bool { return (string)DB::setting('txc_auto_approve', '1') === '1'; }
    public static function tolerance(): float  { return max(0.0, (float)DB::setting('txc_tolerance', 3)); }
    public static function timeout(): int      { return max(5, (int)DB::setting('txc_timeout', 15)); }
    public static function bscKey(): string    { return trim((string)DB::setting('txc_bscscan_key', '')); }
    public static function ethKey(): string    { return trim((string)DB::setting('txc_etherscan_key', '')); }
    public static function tonKey(): string    { return trim((string)DB::setting('txc_tonapi_key', '')); }

    public static function autoReject(): bool     { return (string)DB::setting('txc_auto_reject', '1') === '1'; }
    public static function rejectLow(): bool      { return (string)DB::setting('txc_reject_low', '1') === '1'; }
    public static function rejectNotFound(): bool { return (string)DB::setting('txc_reject_notfound', '0') === '1'; }

    /** وضعیت‌هایی که قطعاً اشتباه‌اند و جای بررسی دستی ندارند */
    public const HARD_FAIL = ['duplicate', 'wrong_address'];

    /* ==================== داوری خودکار ==================== */

    /**
     * تصمیم نهایی روی نتیجهٔ بررسی هش
     * خروجی: approve (تایید و شارژ) | reject (رد تراکنش) | manual (بررسی دستی)
     */
    public static function decide(array $res): string
    {
        $st = (string)($res['status'] ?? '');

        if (!empty($res['ok']) && $st === 'confirmed') {
            return self::autoApprove() ? 'approve' : 'manual';
        }
        if (!self::autoReject()) return 'manual';

        if (in_array($st, self::HARD_FAIL, true))          return 'reject';
        if ($st === 'low_amount' && self::rejectLow())      return 'reject';
        if ($st === 'notfound'   && self::rejectNotFound()) return 'reject';

        return 'manual';
    }

    public static function decisionFa(string $d): string
    {
        $m = ['approve' => 'تایید خودکار', 'reject' => 'رد خودکار', 'manual' => 'در انتظار بررسی دستی'];
        return $m[$d] ?? $d;
    }

    /**
     * ثبت واریز ارزی + داوری خودکار در یک مرحله.
     * همیشه یک تراکنش ثبت می‌شود تا سابقه بماند، سپس تایید یا رد می‌شود.
     */
    public static function settle(array $user, int $amount, string $hash, string $extraNote = ''): array
    {
        $hash = self::normalize($hash);
        $chk  = self::verify($hash, $amount);
        $note = trim(($extraNote !== '' ? $extraNote . "\n" : '') . self::summary($chk));
        $dec  = self::decide($chk);

        $txId = (int)Wallet::createDeposit($user, $amount, 'crypto', [
            'txid' => mb_substr($hash, 0, 180),
            'note' => mb_substr($note, 0, 240),
        ]);

        if ($dec === 'approve') {
            $r = Wallet::approve($txId, null);
            if (empty($r['ok'])) $dec = 'manual';
        } elseif ($dec === 'reject') {
            $reason = trim(str_replace("\n", ' ', (string)($chk['message'] ?? '')));
            $r = Wallet::reject($txId, null, 'هش‌چکر: ' . $reason);
            if (empty($r['ok'])) $dec = 'manual';
        }

        return ['tx' => $txId, 'decision' => $dec, 'check' => $chk, 'note' => $note];
    }

    /* ==================== نرمال‌سازی و تشخیص ==================== */

    /** حذف فاصله، تبدیل اعداد فارسی و بیرون کشیدن هش از لینک اکسپلورر */
    public static function normalize(string $h): string
    {
        $h = trim(en_num($h));
        if (preg_match('~/(?:tx|transaction|transactions)/([A-Za-z0-9+/=_\-]{40,})~i', $h, $m)) {
            $h = $m[1];
        }
        $h = preg_replace('/\s+/', '', $h) ?? '';
        return trim($h, "\"'«» ");
    }

    /**
     * تشخیص خودکار شبکه از قالب هش
     * @return array<int,string> شبکه‌های محتمل به ترتیب اولویت
     */
    public static function detect(string $h): array
    {
        $h = self::normalize($h);
        if ($h === '') return [];

        // شبکه‌های EVM همیشه با 0x شروع می‌شوند
        if (preg_match('/^0x[0-9a-fA-F]{64}$/', $h)) return ['BSC', 'ETH'];

        // ۶۴ کاراکتر هگزادسیمال: هم ترون هم تون
        if (preg_match('/^[0-9a-fA-F]{64}$/', $h)) return ['TRON', 'TON'];

        // قالب base64 مخصوص تون
        if (preg_match('~^[A-Za-z0-9+/_\-]{42,48}={0,2}$~', $h)) return ['TON'];

        return [];
    }

    /** آدرس‌های دریافت فروشگاه (همه با حروف کوچک) */
    public static function myAddresses(): array
    {
        $out = [];
        foreach (['USDT', 'TON', 'TRX'] as $k) {
            $a = trim((string)DB::setting('crypto_addr_' . $k, ''));
            if ($a !== '') $out[] = strtolower($a);
        }
        $legacy = trim((string)DB::setting('crypto_address', ''));
        if ($legacy !== '') $out[] = strtolower($legacy);

        /* آدرس‌های درگاه‌های چندگانه */
        if (class_exists('Gateway')) {
            try {
                foreach (Gateway::addresses() as $a) {
                    $a = trim((string)$a);
                    if ($a !== '') $out[] = strtolower($a);
                }
            } catch (Throwable $e) {
            }
        }

        return array_values(array_unique(array_filter($out)));
    }

    /** آیا این هش قبلاً ثبت شده است؟ */
    public static function duplicate(string $hash, ?int $ignoreTxId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM {p}transactions WHERE txid = :t AND status <> 'rejected'";
        $par = [':t' => $hash];
        if ($ignoreTxId !== null && $ignoreTxId > 0) {
            $sql .= ' AND id <> :i';
            $par[':i'] = $ignoreTxId;
        }
        return (int)DB::val($sql, $par) > 0;
    }

    /* ==================== بررسی اصلی ==================== */

    /**
     * بررسی یک هش
     *
     * @param string   $hash        هش ارسالی کاربر
     * @param int      $expectToman مبلغ مورد انتظار (تومان) – ۰ یعنی بدون مقایسه
     * @param int|null $ignoreTxId  شناسهٔ تراکنشی که در بررسی تکراری نادیده بگیریم
     */
    public static function verify(string $hash, int $expectToman = 0, ?int $ignoreTxId = null): array
    {
        $hash = self::normalize($hash);

        $base = [
            'ok' => false, 'status' => 'error', 'message' => '', 'chain' => '', 'asset' => '',
            'to' => '', 'amount' => 0.0, 'toman' => 0, 'hash' => $hash, 'manual' => true, 'raw' => [],
        ];

        $fail = static function (string $st, string $extra = '') use ($base): array {
            $base['status']  = $st;
            $base['message'] = (self::STATUS_FA[$st] ?? $st) . ($extra !== '' ? "\n" . $extra : '');
            return $base;
        };

        if ($hash === '')          return $fail('notfound', 'هش خالی است.');
        if (strlen($hash) < 20)    return $fail('notfound', 'طول هش کمتر از حد مجاز است.');
        if (!self::enabled())      return $fail('unsupported', 'بررسی خودکار توسط مدیر خاموش شده است.');

        if (self::duplicate($hash, $ignoreTxId)) {
            $r = $fail('duplicate');
            $r['manual'] = false; // تکراری قطعی است؛ نیازی به بررسی دستی ندارد
            return $r;
        }

        $chains = self::detect($hash);
        if (!$chains) return $fail('unsupported', 'قالب این هش قابل شناسایی نبود.');

        $last = null;
        foreach ($chains as $chain) {
            try {
                $r = self::lookup($chain, $hash);
            } catch (Throwable $e) {
                $r = ['status' => 'error', 'note' => 'خطای ارتباط با اکسپلورر.'];
            }

            $st = (string)($r['status'] ?? 'error');

            // اگر در این شبکه نبود، برو سراغ شبکهٔ بعدی
            if (in_array($st, ['notfound', 'error', 'unsupported'], true)) {
                $last = $r + ['chain' => $chain];
                continue;
            }
            return self::judge($r, $chain, $expectToman, $hash);
        }

        $r = $fail((string)($last['status'] ?? 'notfound'), (string)($last['note'] ?? ''));
        $r['chain'] = (string)($last['chain'] ?? '');
        return $r;
    }

    /** داوری نهایی: آدرس مقصد و مبلغ */
    private static function judge(array $r, string $chain, int $expectToman, string $hash): array
    {
        $asset = strtoupper((string)($r['asset'] ?? ''));
        $to    = (string)($r['to'] ?? '');
        $amt   = (float)($r['amount'] ?? 0);

        $out = [
            'ok' => false, 'status' => 'error', 'message' => '', 'chain' => $chain, 'asset' => $asset,
            'to' => $to, 'amount' => $amt, 'toman' => 0, 'hash' => $hash, 'manual' => true,
            'raw' => $r['raw'] ?? [],
        ];

        // ۱) آدرس مقصد باید متعلق به فروشگاه باشد
        $mine = self::myAddresses();
        if ($mine && $to !== '' && !in_array(strtolower($to), $mine, true)) {
            $out['status']  = 'wrong_address';
            $out['message'] = self::STATUS_FA['wrong_address'];
            $out['manual']  = false; // قطعاً به ما واریز نشده
            return $out;
        }

        // ۲) هنوز در شبکه تایید نشده
        if ((string)($r['status'] ?? '') === 'pending') {
            $out['status']  = 'pending';
            $out['message'] = self::STATUS_FA['pending'] . "\nچند دقیقه بعد دوباره تلاش کنید.";
            return $out;
        }

        // ۳) محاسبهٔ ارزش ریالی با نرخ لحظه‌ای
        $unit = 0;
        if ($asset !== '' && class_exists('Rates')) {
            try { $unit = Rates::assetPrice($asset, true); } catch (Throwable $e) { $unit = 0; }
        }
        $toman = $unit > 0 ? (int)round($amt * $unit) : 0;
        $out['toman'] = $toman;

        // اگر نتوانیم قیمت‌گذاری کنیم، تایید دستی لازم است
        if ($expectToman > 0 && $toman <= 0) {
            $out['status']  = 'unsupported';
            $out['message'] = self::STATUS_FA['unsupported']
                . "\nنرخ لحظه‌ای این ارز در دسترس نیست؛ بررسی دستی لازم است.";
            return $out;
        }

        // ۴) مقایسهٔ مبلغ با تلورانس
        if ($expectToman > 0) {
            $min = (int)floor($expectToman * (1 - self::tolerance() / 100));
            if ($toman < $min) {
                $out['status']  = 'low_amount';
                $out['message'] = self::STATUS_FA['low_amount']
                    . "\nمبلغ واریزی: " . money($toman)
                    . "\nمبلغ سفارش: " . money($expectToman);
                return $out;
            }
        }

        $out['ok']      = true;
        $out['status']  = 'confirmed';
        $out['manual']  = false;
        $out['message'] = self::STATUS_FA['confirmed'];
        return $out;
    }

    /** دیسپچر شبکه‌ها */
    private static function lookup(string $chain, string $hash): array
    {
        if ($chain === 'TRON') return self::tron($hash);
        if ($chain === 'TON')  return self::ton($hash);
        if ($chain === 'BSC' || $chain === 'ETH') return self::evm($hash, $chain);
        return ['status' => 'unsupported'];
    }

    /* ==================== شبکهٔ ترون ==================== */

    private static function tron(string $hash): array
    {
        $hosts = ['https://apilist.tronscanapi.com', 'https://apilist.tronscan.org'];
        $j = null;

        foreach ($hosts as $host) {
            $r = http_json($host . '/api/transaction-info?hash=' . urlencode($hash), [], 'GET', [], self::timeout());
            $cand = $r['json'] ?? null;
            if (is_array($cand) && (!empty($cand['hash']) || !empty($cand['contractRet']))) { $j = $cand; break; }
        }

        if (!is_array($j)) return ['status' => 'notfound'];

        $ret = strtoupper((string)($j['contractRet'] ?? ''));
        if ($ret !== '' && $ret !== 'SUCCESS') {
            return ['status' => 'error', 'note' => 'این تراکنش در شبکه ناموفق بوده است.'];
        }

        $confirmed = !empty($j['confirmed']);
        $status    = $confirmed ? 'ok' : 'pending';

        // انتقال توکن TRC20 (مانند USDT)
        $tti = $j['tokenTransferInfo'] ?? null;
        if (is_array($tti) && !empty($tti['to_address'])) {
            $dec = (int)($tti['decimals'] ?? 6);
            $raw = (string)($tti['amount_str'] ?? ($tti['amount'] ?? '0'));
            $amt = $dec > 0 ? ((float)$raw) / pow(10, $dec) : (float)$raw;
            $sym = strtoupper((string)($tti['symbol'] ?? 'USDT'));

            return [
                'status' => $status, 'asset' => $sym, 'to' => (string)$tti['to_address'],
                'amount' => $amt, 'raw' => $j,
            ];
        }

        // انتقال سادهٔ TRX
        $to  = (string)($j['toAddress'] ?? '');
        $sun = (float)($j['contractData']['amount'] ?? 0);
        if ($to !== '' && $sun > 0) {
            return [
                'status' => $status, 'asset' => 'TRX', 'to' => $to,
                'amount' => $sun / 1000000, 'raw' => $j,
            ];
        }

        return ['status' => 'notfound'];
    }

    /* ==================== شبکهٔ تون ==================== */

    private static function ton(string $hash): array
    {
        $hdr = ['Accept: application/json'];
        if (self::tonKey() !== '') $hdr[] = 'Authorization: Bearer ' . self::tonKey();

        $r = http_json('https://tonapi.io/v2/blockchain/transactions/' . urlencode($hash), [], 'GET', $hdr, self::timeout());
        $j = $r['json'] ?? null;

        if (!is_array($j) || empty($j['hash'])) return ['status' => 'notfound'];

        $in  = is_array($j['in_msg'] ?? null) ? $j['in_msg'] : [];
        $to  = (string)($in['destination']['address'] ?? '');
        $val = (float)($in['value'] ?? 0) / 1000000000;

        if ($to === '' || $val <= 0) return ['status' => 'notfound'];

        return [
            'status' => !empty($j['success']) ? 'ok' : 'pending',
            'asset'  => 'TON', 'to' => $to, 'amount' => $val, 'raw' => $j,
        ];
    }

    /* ==================== شبکه‌های EVM ==================== */

    private static function evm(string $hash, string $chain): array
    {
        $key = $chain === 'BSC' ? self::bscKey() : self::ethKey();
        if ($key === '') {
            return ['status' => 'unsupported', 'note' => 'برای بررسی خودکار این شبکه، کلید API را در تنظیمات ثبت کنید.'];
        }

        $base   = $chain === 'BSC' ? 'https://api.bscscan.com/api' : 'https://api.etherscan.io/api';
        $native = $chain === 'BSC' ? 'BNB' : 'ETH';
        $tokDec = $chain === 'BSC' ? 18 : 6; // USDT روی BEP20 ۱۸ رقم و روی ERC20 ۶ رقم اعشار دارد

        $r = http_json(
            $base . '?module=proxy&action=eth_getTransactionReceipt&txhash=' . urlencode($hash) . '&apikey=' . urlencode($key),
            [], 'GET', [], self::timeout()
        );
        $j = $r['json']['result'] ?? null;
        if (!is_array($j)) return ['status' => 'notfound'];

        if (strtolower((string)($j['status'] ?? '0x1')) !== '0x1') {
            return ['status' => 'error', 'note' => 'این تراکنش در شبکه ناموفق بوده است.'];
        }

        // جستجوی رویداد Transfer برای توکن‌ها
        foreach ((array)($j['logs'] ?? []) as $log) {
            $topics = (array)($log['topics'] ?? []);
            if (count($topics) < 3) continue;
            if (strtolower((string)$topics[0]) !== self::ERC20_TRANSFER) continue;

            return [
                'status' => 'ok', 'asset' => 'USDT',
                'to'     => '0x' . substr((string)$topics[2], -40),
                'amount' => self::hexToFloat((string)($log['data'] ?? '0x0'), $tokDec),
                'raw'    => $j,
            ];
        }

        // انتقال ارز بومی شبکه
        $r2 = http_json(
            $base . '?module=proxy&action=eth_getTransactionByHash&txhash=' . urlencode($hash) . '&apikey=' . urlencode($key),
            [], 'GET', [], self::timeout()
        );
        $t = $r2['json']['result'] ?? null;
        if (is_array($t) && !empty($t['to'])) {
            return [
                'status' => 'ok', 'asset' => $native, 'to' => (string)$t['to'],
                'amount' => self::hexToFloat((string)($t['value'] ?? '0x0'), 18), 'raw' => $t,
            ];
        }

        return ['status' => 'notfound'];
    }

    /** تبدیل عدد هگزادسیمال بزرگ به عدد اعشاری */
    private static function hexToFloat(string $hex, int $dec): float
    {
        $hex = strtolower(trim($hex));
        if (strpos($hex, '0x') === 0) $hex = substr($hex, 2);
        $hex = ltrim($hex, '0');
        if ($hex === '') return 0.0;

        $val = 0.0;
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $val = $val * 16 + (float)hexdec($hex[$i]);
        }
        return $dec > 0 ? $val / pow(10, $dec) : $val;
    }

    /* ==================== گزارش ==================== */

    /** خلاصهٔ قابل نمایش برای ربات و پنل */
    public static function summary(array $res): string
    {
        $l = [];
        $l[] = (string)($res['message'] ?? '');

        $chain = (string)($res['chain'] ?? '');
        if ($chain !== '' && isset(self::CHAINS[$chain])) {
            $l[] = 'شبکه: ' . self::CHAINS[$chain]['icon'] . ' ' . self::CHAINS[$chain]['label'];
        }
        if (!empty($res['asset'])) {
            $qty = rtrim(rtrim(number_format((float)$res['amount'], 6, '.', ''), '0'), '.');
            $l[] = 'مقدار: ' . fa_num($qty) . ' ' . (string)$res['asset'];
        }
        if (!empty($res['toman'])) {
            $l[] = 'ارزش لحظه‌ای: ' . money((int)$res['toman']);
        }

        return implode("\n", array_filter($l));
    }

    /** بررسی مجدد یک تراکنش در انتظار (برای پنل مدیریت) */
    public static function recheck(int $txId): array
    {
        $tx = DB::one('SELECT * FROM {p}transactions WHERE id = :id', [':id' => $txId]);
        if (!$tx) return ['ok' => false, 'status' => 'notfound', 'message' => 'تراکنش پیدا نشد.', 'manual' => true];

        $hash = trim((string)($tx['txid'] ?? ''));
        if ($hash === '') {
            return ['ok' => false, 'status' => 'notfound', 'message' => 'برای این تراکنش هشی ثبت نشده است.', 'manual' => true];
        }

        return self::verify($hash, (int)$tx['amount'], (int)$tx['id']);
    }
}
