# -*- coding: utf-8 -*-
# fixed160 - 0.0.2 #22: Zarinpal deposit branch in the bot state handler and the miniapp API.
import io, os, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed160').strip() or 'fixed160'

CACHE = {}
NEW = set()
ERRORS = []
WARN = []
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json')

BOT = 'app/Bot/Bot.php'
API = 'miniapp/api.php'


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8', errors='replace') as fh:
        CACHE[path] = fh.read()
    return CACHE[path]


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
# 1) bot deposit branch
# ==================================================================
BOT_ANCHOR = "} elseif ($method === 'hooshpay') {"

ZP_BOT = r"""} elseif ($method === 'zarinpal') { /* 0.0.2 #22 */
                    self::setState(null);
                    $waitId = self::waitMsg(
                        $chatId,
                        "\u{1F3E6} <b>در حال ساخت لینک پرداخت…</b>\n\n\u{23F3} اتصال به زرین‌پال، چند لحظه صبر کنید.",
                        self::kbMain()
                    );

                    if (!class_exists('Zarinpal') || !Zarinpal::enabled()) {
                        self::waitEdit($chatId, $waitId, "\u{26A0} درگاه زرین‌پال در دسترس نیست.");
                        return true;
                    }

                    $invZ = Zarinpal::createInvoice(self::$u, $amount);
                    if (empty($invZ['ok'])) {
                        self::waitEdit($chatId, $waitId, "\u{274C} " . (string)($invZ['message'] ?? 'ساخت لینک پرداخت ناموفق بود.'));
                        Logs::send('errors', Logs::fmt("\u{26A0} خطای ساخت تراکنش زرین‌پال", [
                            'کاربر' => (int)self::$u['tg_id'],
                            'مبلغ'  => money($amount) . ' ' . currency(),
                            'خطا'   => (string)($invZ['message'] ?? '-'),
                        ]));
                        return true;
                    }

                    $txZ = (int)($invZ['tx'] ?? 0);
                    $txt = "\u{1F3E6} <b>پرداخت آنلاین با کارت بانکی</b>\n\n"
                        . 'مبلغ: <b>' . money($amount) . ' ' . currency() . "</b>\n"
                        . 'شماره پیگیری: <code>#' . $txZ . "</code>\n\n"
                        . "روی دکمهٔ زیر بزنید و پرداخت را در درگاه زرین‌پال کامل کنید.\n"
                        . "پس از پرداخت موفق، کیف پول شما <b>خودکار</b> شارژ می‌شود.";

                    self::waitEdit($chatId, $waitId, $txt, Tg::ikb([
                        [Tg::url("\u{1F3E6} رفتن به درگاه پرداخت", (string)$invZ['url'])],
                    ]));
                    Logs::send('financial', Logs::fmt("\u{1F3E6} تراکنش زرین‌پال جدید", [
                        'کاربر'  => (($uZ = self::$u)['first_name'] ?? '-') . ' (' . (int)$uZ['tg_id'] . ')',
                        'مبلغ'   => money($amount) . ' ' . currency(),
                        'پیگیری' => '#' . $txZ,
                    ]));
                    return true;
                """

rep_lit(BOT, BOT_ANCHOR, ZP_BOT + BOT_ANCHOR, "$method === 'zarinpal'")

# ==================================================================
# 2) miniapp deposit endpoint
# ==================================================================
API_ANCHOR = (
    "        if ($method === 'hooshpay') {\n"
    "            if (!class_exists('HooshPay') || !HooshPay::enabled()) ma_fail('"
)

ZP_API = r"""        if ($method === 'zarinpal') { /* 0.0.2 #22 */
            if (!class_exists('Zarinpal') || !Zarinpal::enabled()) ma_fail('درگاه زرین‌پال فعال نیست.');
            if (!Zarinpal::forUser($isRs)) ma_fail('این درگاه برای حساب شما فعال نیست.');

            $zpMin = Zarinpal::minAmount();
            $zpMax = Zarinpal::maxAmount();
            if ($zpMin > 0 && $amount < $zpMin) ma_fail('حداقل مبلغ این درگاه ' . ma_money($zpMin) . ' است.');
            if ($zpMax > 0 && $amount > $zpMax) ma_fail('حداکثر مبلغ این درگاه ' . ma_money($zpMax) . ' است.');

            $invZp = Zarinpal::createInvoice($user, $amount);
            if (empty($invZp['ok'])) {
                ma_fail((string)($invZp['message'] ?? 'ساخت لینک پرداخت زرین‌پال ناموفق بود.'));
            }

            ma_out(['ok' => true, 'method' => 'zarinpal',
                'amount'     => $amount,
                'amount_txt' => ma_money($amount),
                'tx'         => (int)($invZp['tx'] ?? 0),
                'url'        => (string)($invZp['url'] ?? ''),
                'authority'  => (string)($invZp['authority'] ?? ''),
                'note'       => 'روی دکمهٔ پرداخت بزنید؛ پس از پرداخت موفق، کیف پول شما خودکار شارژ می‌شود.']);
        }

"""

rep_lit(API, API_ANCHOR, ZP_API + API_ANCHOR, "$method === 'zarinpal'")

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
    with io.open(os.path.join(ROOT, p), 'w', encoding='utf-8') as fh:
        fh.write(CACHE[p])
    print('wrote ' + p)
print('changed files: %d' % len(NEW))

SANITY = [
    (BOT, "$method === 'zarinpal'"),
    (BOT, 'Zarinpal::createInvoice(self::$u'),
    (API, "'method' => 'zarinpal'"),
    (API, 'Zarinpal::createInvoice($user'),
]
ok = 0
for p, needle in SANITY:
    good = needle in load(p)
    print('sanity %s / %s : %s' % (p, needle[:36], 'ok' if good else 'MISSING'))
    if good:
        ok += 1
print('sanity: %d/%d' % (ok, len(SANITY)))

# ---------------- recon ----------------
print('===== hooshpay status check in bot =====')
grep_all('checkHooshPay', 10)
dump_find('bot_chk', BOT, 'checkHooshPay($chatId, int', 2, 45)
print('===== askAmount hunt =====')
grep_all('Amount(', 40, only='app/Bot')
print('===== miniapp wallet UI =====')
grep_all('hooshpay', 30, only='miniapp/index.php')
print('===== miniapp hp button =====')
dump('ma_ui', 'miniapp/index.php', 2280, 2330)

# ---------------- version bump ----------------
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = '0.0.2 #22: \u0634\u0627\u0631\u0698 \u06a9\u06cc\u0641 \u067e\u0648\u0644 \u0628\u0627 \u0632\u0631\u06cc\u0646\u200c\u067e\u0627\u0644 \u062f\u0631 \u0631\u0628\u0627\u062a \u0648 \u0645\u06cc\u0646\u06cc\u200c\u0627\u067e'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
