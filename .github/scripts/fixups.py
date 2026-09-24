# -*- coding: utf-8 -*-
# fixed158 - 0.0.2 #22: wire Zarinpal into bot buttons, deep links, pollers and miniapp flags.
import io, os, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed158').strip() or 'fixed158'

CACHE = {}
NEW = set()
ERRORS = []
WARN = []
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json')

BTN = 'app/Service/Btn.php'
BOT = 'app/Bot/Bot.php'
PAY = 'admin/pages/payments.php'
API = 'miniapp/api.php'
CRON = 'cron/tasks.php'


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


def rep_all(path, old, new, marker, optional=True):
    src = load(path)
    if marker in src:
        print('skip (already applied): %s' % marker)
        return
    n = src.count(old)
    if n < 1:
        msg = '%s: anchor for %s matched 0 times' % (path, marker)
        if optional:
            WARN.append(msg)
            print('SKIP optional: ' + msg)
        else:
            ERRORS.append(msg)
        return
    CACHE[path] = src.replace(old, new)
    NEW.add(path)
    print('patched: %s (%s x%d)' % (path, marker, n))


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


ZPICON = '\\u{1F3E6}'
ZPLBL = '\u0634\u0627\u0631\u0698 \u0622\u0646\u06cc \u0632\u0631\u06cc\u0646\u200c\u067e\u0627\u0644'
WALGRP = '\u06a9\u06cc\u0641 \u067e\u0648\u0644'

# ==================================================================
# 1) bot button registry
# ==================================================================
rep_lit(
    BTN,
    "        'wal_hash'   => ['",
    "        'wal_zp'     => [\"" + ZPICON + "\", '" + ZPLBL + "', 'all', '" + WALGRP + "'], /* 0.0.2 #22 */\n"
    "        'wal_hash'   => ['",
    "'wal_zp'     => [",
)

rep_lit(
    BTN,
    "        'wal_hash'   => 'wallet',",
    "        'wal_zp'     => 'wallet',\n"
    "        'wal_hash'   => 'wallet',",
    "'wal_zp'     => 'wallet'",
)

# ==================================================================
# 2) bot callbacks / deep links
# ==================================================================
rep_lit(
    BOT,
    "case 'wal_hash':   self::askAmount($chatId, 'crypto_hash'); return true;",
    "case 'wal_zp':     self::askAmount($chatId, 'zarinpal'); return true; /* 0.0.2 #22 */\n"
    "            case 'wal_hash':   self::askAmount($chatId, 'crypto_hash'); return true;",
    "case 'wal_zp':",
)

rep_lit(
    BOT,
    "elseif ($arg === 'hp') self::askAmount($chatId, 'hooshpay');",
    "elseif ($arg === 'hp') self::askAmount($chatId, 'hooshpay');\n"
    "            elseif ($arg === 'zp') self::askAmount($chatId, 'zarinpal');",
    "$arg === 'zp')",
)

# ==================================================================
# 3) admin payments poller
# ==================================================================
rep_lit(
    PAY,
    "if ($mth === 'hooshpay' && class_exists('HooshPay'))   $pr = HooshPay::poll($tx);",
    "if ($mth === 'hooshpay' && class_exists('HooshPay'))   $pr = HooshPay::poll($tx);\n"
    "        if ($mth === 'zarinpal' && class_exists('Zarinpal'))   $pr = Zarinpal::poll($tx); /* 0.0.2 #22 */",
    'Zarinpal::poll($tx)',
)

# ==================================================================
# 4) miniapp gateway availability flag
# ==================================================================
rep_lit(
    API,
    "'hooshpay' => class_exists('HooshPay') ? HooshPay::enabled() : false,",
    "'hooshpay' => class_exists('HooshPay') ? HooshPay::enabled() : false,\n"
    "        'zarinpal' => class_exists('Zarinpal') ? Zarinpal::enabled() : false,",
    "'zarinpal' => class_exists('Zarinpal')",
)

# ==================================================================
# 5) auto-method fallback lists
# ==================================================================
rep_all(PAY, '"\'hooshpay\',\'nowpay\'"', '"\'hooshpay\',\'nowpay\',\'zarinpal\'"', "nowpay','zarinpal'")
rep_all(CRON, '"\'hooshpay\',\'nowpay\'"', '"\'hooshpay\',\'nowpay\',\'zarinpal\'"', "nowpay','zarinpal'")

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
    (BTN, "'wal_zp'     => ["),
    (BTN, "'wal_zp'     => 'wallet'"),
    (BOT, "case 'wal_zp':"),
    (BOT, "$arg === 'zp')"),
    (PAY, 'Zarinpal::poll($tx)'),
    (API, "'zarinpal' => class_exists('Zarinpal')"),
]
ok = 0
for p, needle in SANITY:
    good = needle in load(p)
    print('sanity %s / %s : %s' % (p, needle[:34], 'ok' if good else 'MISSING'))
    if good:
        ok += 1
print('sanity: %d/%d' % (ok, len(SANITY)))

# ---------------- recon for the deposit flow ----------------
print('===== bot deposit branch =====')
dump(BOT, BOT, 1400, 1476)
print('===== bot askAmount definition hunt =====')
grep_all('function ask', 20, only='app/Bot')
grep_all('wal_hp', 20)
print('===== cron hooshpay poll =====')
dump('cron_hp', CRON, 92, 122)
print('===== miniapp hooshpay deposit =====')
dump('ma_hp', API, 1475, 1525)
print('===== adminbot method labels =====')
dump('ab_lbl', 'app/Bot/AdminBot.php', 483, 496)

# ---------------- version bump ----------------
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = '0.0.2 #22: \u062f\u06a9\u0645\u0647\u0654 \u0632\u0631\u06cc\u0646\u200c\u067e\u0627\u0644 \u062f\u0631 \u0631\u0628\u0627\u062a \u0648 \u067e\u06cc\u06af\u06cc\u0631\u06cc \u062e\u0648\u062f\u06a9\u0627\u0631 \u062a\u0631\u0627\u06a9\u0646\u0634'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
