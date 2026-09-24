# -*- coding: utf-8 -*-
# fixed155 - 0.0.2 #22: Zarinpal auto gateway (driver class + callback page + Gateway hooks) and recon for the wiring.
import io, os, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed155').strip() or 'fixed155'

CACHE = {}
NEW = set()
ERRORS = []
WARN = []
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json')

GW = 'app/Service/Gateway.php'
ZP = 'app/Service/Zarinpal.php'
ZPR = 'zarinpal.php'


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8', errors='replace') as fh:
        CACHE[path] = fh.read()
    return CACHE[path]


def add_file(path, content):
    full = os.path.join(ROOT, path)
    if os.path.exists(full):
        print('skip (exists): %s' % path)
        CACHE[path] = load(path)
        return
    CACHE[path] = content
    NEW.add(path)
    print('new file: %s (%d bytes)' % (path, len(content.encode('utf-8'))))


def rep_lit(path, old, new, marker, optional=False):
    src = load(path)
    if marker in src:
        print('skip (already applied): %s' % marker)
        return
    n = src.count(old)
    if n != 1:
        msg = '%s: anchor for %s matched %d times (want 1)' % (path, marker, n)
        if optional:
            WARN.append(msg)
            print('SKIP optional: ' + msg)
        else:
            ERRORS.append(msg)
        return
    CACHE[path] = src.replace(old, new)
    NEW.add(path)
    print('patched: %s (%s)' % (path, marker))


def dump(tag, path, start, end):
    try:
        lines = load(path).split(chr(10))
    except Exception as e:
        print('---- dump %s FAILED (%s) ----' % (tag, e))
        return
    print('---- dump %s : %s (%d lines) ----' % (tag, path, len(lines)))
    for i in range(max(1, start), min(len(lines), end) + 1):
        s = lines[i - 1]
        if len(s) > 200:
            s = s[:200] + ' ...'
        print('%5d %s' % (i, s))
    print('---- end dump %s ----' % tag)


def dump_find(tag, path, needle, before=3, after=30):
    try:
        lines = load(path).split(chr(10))
    except Exception as e:
        print('---- dump %s FAILED (%s) ----' % (tag, e))
        return
    for i, ln in enumerate(lines, 1):
        if needle in ln:
            dump(tag, path, i - before, i + after)
            return
    print('---- dump %s : needle not found (%s) ----' % (tag, needle))


ALL = []


def files_all():
    if ALL:
        return ALL
    for base, dirs, fns in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        for fn in sorted(fns):
            if fn.endswith(EXT):
                ALL.append(os.path.relpath(os.path.join(base, fn), ROOT))
    ALL.sort()
    return ALL


def grep_all(needle, limit=20, only=None):
    print('---- grep: %s ----' % needle)
    n = 0
    for p in files_all():
        if only and not p.startswith(only):
            continue
        try:
            lines = load(p).split(chr(10))
        except Exception:
            continue
        for i, ln in enumerate(lines, 1):
            if needle in ln:
                s = ln.strip()
                if len(s) > 170:
                    s = s[:170] + ' ...'
                print('%s:%d: %s' % (p, i, s))
                n += 1
                if n >= limit:
                    print('---- end grep: %s (truncated) ----' % needle)
                    return
    print('---- end grep: %s (%d) ----' % (needle, n))


# ==================================================================
# 1) driver class
# ==================================================================
ZP_SRC = r"""<?php
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
"""

add_file(ZP, ZP_SRC)

# ==================================================================
# 2) public callback / return page
# ==================================================================
ZPR_SRC = r"""<?php
declare(strict_types=1);

/**
 * بازگشت کاربر از درگاه زرین‌پال — 0.0.2 #22
 *
 * این آدرس را به عنوان callback درگاه ثبت کنید:
 *   https://YOUR-DOMAIN/zarinpal.php
 */

require __DIR__ . '/app/bootstrap.php';

if (!app_installed()) {
    http_response_code(503);
    echo 'not installed';
    exit;
}

boot();

if (!class_exists('Zarinpal')) {
    http_response_code(500);
    echo 'zarinpal class missing';
    exit;
}

$authority = trim((string)($_GET['Authority'] ?? ($_GET['authority'] ?? '')));
$status    = strtoupper(trim((string)($_GET['Status'] ?? ($_GET['status'] ?? ''))));

/* بازدید ساده بدون پارامتر: فقط وضعیت درگاه */
if ($authority === '') {
    header('Content-Type: text/plain; charset=utf-8');
    echo Zarinpal::enabled() ? 'zarinpal callback ready' : 'zarinpal disabled';
    exit;
}

$res = Zarinpal::apply($authority, $status !== '' ? $status : 'OK');
$ok  = !empty($res['ok']);

app_log('zarinpal', 'callback', [
    'authority' => $authority,
    'status'    => $status,
    'ok'        => $ok ? 1 : 0,
    'message'   => (string)($res['message'] ?? ''),
]);

header('Content-Type: text/html; charset=utf-8');

$brand = defined('APP_BRAND') ? (string)APP_BRAND : 'فروشگاه';
$icon  = $ok ? "\u{2705}" : "\u{26D4}";
$title = $ok ? 'پرداخت با موفقیت انجام شد' : 'پرداخت انجام نشد';
$msg   = (string)($res['message'] ?? '');
$ref   = (string)($res['ref'] ?? '');
$col   = $ok ? '#2FD48F' : '#F87171';
$rgb   = $ok ? '47,212,143' : '248,113,113';

echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>بازگشت از پرداخت</title><style>'
    . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
    . 'background:#0B0E14;color:#E6EAF2;font-family:"Vazirmatn",system-ui,sans-serif;padding:20px}'
    . '.b{max-width:420px;width:100%;background:#141A26;border:1px solid #28324a;border-radius:20px;'
    . 'padding:28px 22px;text-align:center;box-shadow:0 18px 50px rgba(0,0,0,.45)}'
    . '.i{font-size:52px;line-height:1;margin-bottom:14px}'
    . 'h1{font-size:19px;margin:0 0 10px}'
    . 'p{font-size:14px;line-height:1.9;color:#9AA6BA;margin:0 0 8px}'
    . '.r{direction:ltr;font-family:ui-monospace,monospace;font-size:13px;color:#E6EAF2;'
    . 'background:#0F1420;border:1px solid #28324a;border-radius:12px;padding:8px 12px;display:inline-block;margin-top:6px}'
    . '.k{display:inline-block;margin-top:16px;background:rgba(' . $rgb . ',.13);color:' . $col . ';'
    . 'border:1px solid rgba(' . $rgb . ',.35);border-radius:999px;padding:9px 18px;font-size:13px}'
    . '</style></head><body><div class="b">'
    . '<div class="i">' . $icon . '</div>'
    . '<h1>' . h($title) . '</h1>'
    . '<p>' . h($msg) . '</p>';

if ($ok && $ref !== '') {
    echo '<p>کد رهگیری بانکی:</p><div class="r">' . h($ref) . '</div>';
}

echo '<p>می‌توانید به ربات ' . h($brand) . ' برگردید؛ نتیجه همان‌جا هم برایتان ارسال می‌شود.</p>'
    . '<div class="k">می‌توانید این صفحه را ببندید</div>'
    . '</div></body></html>';
"""

add_file(ZPR, ZPR_SRC)

# ==================================================================
# 3) Gateway hooks
# ==================================================================
rep_lit(
    GW,
    "            } elseif ($kind === 'hooshpay') {\n",
    "            } elseif ($kind === 'zarinpal') {\n"
    "                $icon = \"\\u{1F3E6}\";\n"
    "            } elseif ($kind === 'hooshpay') {\n",
    "$kind === 'zarinpal'",
)

GW_HELPERS = r"""
    /* ==================== زرین‌پال (0.0.2 #22) ==================== */

    /** ردیف‌های درگاه زرین‌پال برای این کاربر */
    public static function zarinpal(bool $isReseller = false): array
    {
        return self::forUser('zarinpal', $isReseller);
    }

    /** آیا درگاه زرین‌پال برای این کاربر فعال است؟ */
    public static function zarinpalOn(bool $isReseller = false): bool
    {
        if (!class_exists('Zarinpal') || !Zarinpal::enabled()) return false;
        if (!Zarinpal::forUser($isReseller)) return false;

        $has = false;
        foreach (self::all() as $g) {
            if ($g['kind'] === 'zarinpal') { $has = true; break; }
        }
        /* اگر هیچ ردیفی ثبت نشده باشد، فقط تنظیمات کلی حاکم است */
        if (!$has) return true;

        return self::zarinpal($isReseller) !== [];
    }

    /** برچسب دکمهٔ زرین‌پال در منوی ربات */
    public static function zarinpalLabel(bool $isReseller = false): string
    {
        $rows = self::zarinpal($isReseller);
        if ($rows === []) {
            return class_exists('Zarinpal') ? Zarinpal::btnLabel() : "\u{1F3E6} پرداخت آنلاین";
        }
        $g  = $rows[0];
        $ic = (string)$g['icon'] !== '' ? (string)$g['icon'] : "\u{1F3E6}";
        $lb = (string)$g['label'] !== '' ? (string)$g['label'] : 'پرداخت آنلاین با زرین‌پال';
        return trim($ic . ' ' . $lb);
    }
"""

GROUPED_TAIL = (
    "            $out[$a]['items'][] = $g;\n"
    "        }\n"
    "        return $out;\n"
    "    }\n"
)

rep_lit(GW, GROUPED_TAIL, GROUPED_TAIL + GW_HELPERS, 'function zarinpalOn(')

# ---------------- write ----------------
if ERRORS:
    print('ABORTED - anchors not found:')
    for e in ERRORS:
        print(' - ' + e)
    print('exit: 1')
    sys.exit(1)

if WARN:
    print('warnings (optional patches skipped):')
    for w in WARN:
        print(' - ' + w)

for p in sorted(NEW):
    full = os.path.join(ROOT, p)
    d = os.path.dirname(full)
    if d and not os.path.isdir(d):
        os.makedirs(d, exist_ok=True)
    with io.open(full, 'w', encoding='utf-8') as fh:
        fh.write(CACHE[p])
    print('wrote ' + p)
print('changed files: %d' % len(NEW))

SANITY = [
    (ZP, 'class Zarinpal'),
    (ZP, "public const START     = 'https://payment.zarinpal.com/pg/StartPay/'"),
    (ZP, 'public static function createInvoice('),
    (ZPR, 'Zarinpal::apply($authority'),
    (GW, 'public static function zarinpalOn('),
    (GW, "$kind === 'zarinpal'"),
]
ok = 0
for p, needle in SANITY:
    good = needle in load(p)
    print('sanity %s / %s : %s' % (p, needle[:46], 'ok' if good else 'MISSING'))
    if good:
        ok += 1
print('sanity: %d/%d' % (ok, len(SANITY)))

# ---------------- recon for the wiring build ----------------
print('===== autoloader =====')
grep_all('spl_autoload_register', 6)
print('===== Wallet auto-method list =====')
dump('wal_auto', 'app/Service/Wallet.php', 10, 26)
print('===== bot ask amount flow =====')
dump_find('bot_ask', 'app/Bot/Bot.php', 'function askAmount', 3, 55)
grep_all('wal_hp', 10)
print('===== admin gateways: kind select + hooshpay fields =====')
dump_find('gw_kind', 'admin/pages/gateways.php', 'name="kind"', 6, 26)
dump_find('gw_hpui', 'admin/pages/gateways.php', 'name="hp_key"', 14, 34)
print('===== payments labels =====')
dump('pay_lbl', 'admin/pages/payments.php', 10, 30)
print('===== cron auto list =====')
dump('cron_auto', 'cron/tasks.php', 116, 134)

# ---------------- version bump ----------------
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = '0.0.2 #22: \u062f\u0631\u06af\u0627\u0647 \u0632\u0631\u06cc\u0646\u200c\u067e\u0627\u0644 (\u062f\u0631\u0627\u06cc\u0648\u0631 \u0648 \u0635\u0641\u062d\u0647\u0654 \u0628\u0627\u0632\u06af\u0634\u062a)'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
