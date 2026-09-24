# -*- coding: utf-8 -*-
# fixed156 - 0.0.2 #22: wire Zarinpal into Gateway::KINDS, Wallet auto methods, payments labels + recon for admin UI / bot.
import io, os, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed156').strip() or 'fixed156'

CACHE = {}
NEW = set()
ERRORS = []
WARN = []
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json')

GW = 'app/Service/Gateway.php'
GWP = 'admin/pages/gateways.php'
WAL = 'app/Service/Wallet.php'
PAY = 'admin/pages/payments.php'

ZPICON = '\\u{1F3E6}'


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


def listdir(rel):
    full = os.path.join(ROOT, rel)
    print('---- ls %s ----' % rel)
    try:
        for fn in sorted(os.listdir(full)):
            p = os.path.join(full, fn)
            sz = os.path.getsize(p) if os.path.isfile(p) else 0
            print('%s %s %d' % ('d' if os.path.isdir(p) else 'f', fn, sz))
    except Exception as e:
        print('failed: %s' % e)
    print('---- end ls %s ----' % rel)


# ==================================================================
# 1) Wallet: zarinpal is an automatic gateway
# ==================================================================
rep_lit(
    WAL,
    "    public const AUTO_METHODS = ['hooshpay', 'nowpay'];",
    "    public const AUTO_METHODS = ['hooshpay', 'nowpay', 'zarinpal'];",
    "'nowpay', 'zarinpal'",
)

# ==================================================================
# 2) Gateway::KINDS
# ==================================================================
rep_lit(
    GW,
    "    ];\n\n    public const AUDIENCE = [",
    "        'zarinpal' => \"" + ZPICON + " \u0632\u0631\u06cc\u0646\u200c\u067e\u0627\u0644 (\u067e\u0631\u062f\u0627\u062e\u062a \u0622\u0646\u0644\u0627\u06cc\u0646)\",\n"
    "    ];\n\n    public const AUDIENCE = [",
    "'zarinpal' =>",
)

# ==================================================================
# 3) admin payments: method label
# ==================================================================
rep_lit(
    PAY,
    "$METHODS = [\n",
    "$METHODS = [\n"
    "    /* 0.0.2 #22 */\n"
    "    'zarinpal' => ['\u0632\u0631\u06cc\u0646\u200c\u067e\u0627\u0644 (\u062e\u0648\u062f\u06a9\u0627\u0631)', \"" + ZPICON + "\"],\n",
    "'zarinpal' => [",
)

# ==================================================================
# 4) admin gateways: icon for the kind picker
# ==================================================================
rep_lit(
    GWP,
    "elseif ($kk === 'hooshpay')",
    "elseif ($kk === 'zarinpal')  $ic = \"" + ZPICON + "\";\n"
    "                  elseif ($kk === 'hooshpay')",
    "$kk === 'zarinpal'",
    optional=True,
)

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
    (WAL, "'nowpay', 'zarinpal'"),
    (GW, "'zarinpal' =>"),
    (PAY, "'zarinpal' => ["),
]
ok = 0
for p, needle in SANITY:
    good = needle in load(p)
    print('sanity %s / %s : %s' % (p, needle[:40], 'ok' if good else 'MISSING'))
    if good:
        ok += 1
print('sanity: %d/%d' % (ok, len(SANITY)))

# ---------------- recon ----------------
print('===== gateways.php save handlers =====')
dump('gw_save', GWP, 1, 70)
print('===== gateways.php kind flags =====')
dump('gw_flags', GWP, 360, 396)
print('===== gateways.php kind descriptions =====')
dump('gw_desc', GWP, 552, 576)
print('===== gateways.php end of hooshpay fieldset =====')
dump('gw_hpend', GWP, 738, 815)
print('===== gateways.php js =====')
dump('gw_js', GWP, 815, 880)
print('===== bot ask amount =====')
listdir('app/Bot')
grep_all('function askAmount', 6)
grep_all('askAmount', 30)
print('===== where hooshpay button is offered =====')
grep_all('hooshpayOn', 20)
grep_all('hooshpayLabel', 12)

# ---------------- version bump ----------------
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = '0.0.2 #22: \u0632\u0631\u06cc\u0646\u200c\u067e\u0627\u0644 \u0628\u0647 \u062f\u0631\u06af\u0627\u0647\u200c\u0647\u0627\u06cc \u062e\u0648\u062f\u06a9\u0627\u0631 \u0627\u0636\u0627\u0641\u0647 \u0634\u062f'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
