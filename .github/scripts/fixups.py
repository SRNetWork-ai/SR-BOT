# -*- coding: utf-8 -*-
# fixed162 - recon: why reply-keyboard buttons stopped resolving; plus miniapp zarinpal pickers.
import io, os, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed162').strip() or 'fixed162'

CACHE = {}
NEW = set()
ERRORS = []
WARN = []
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json')

MA = 'miniapp/index.php'
BOT = 'app/Bot/Bot.php'
BTN = 'app/Service/Btn.php'


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


def grep_all(needle, limit=20, only=None, ci=False):
    print('---- grep: %s ----' % needle)
    nl = needle.lower()
    n = 0
    for p in files_all():
        if only and not p.startswith(only):
            continue
        try:
            lines = load(p).split(chr(10))
        except Exception:
            continue
        for i, ln in enumerate(lines, 1):
            hit = (nl in ln.lower()) if ci else (needle in ln)
            if hit:
                s = ln.strip()
                if len(s) > 170:
                    s = s[:170] + ' ...'
                print('%s:%d: %s' % (p, i, s))
                n += 1
                if n >= limit:
                    print('---- end grep: %s (truncated) ----' % needle)
                    return
    print('---- end grep: %s (%d) ----' % (needle, n))


def funcs(path, limit=200):
    print('---- funcs: %s ----' % path)
    n = 0
    try:
        lines = load(path).split(chr(10))
    except Exception as e:
        print('failed (%s)' % e)
        return
    for i, ln in enumerate(lines, 1):
        s = ln.strip()
        if s.startswith('function ') or ' function ' in s or s.startswith('const ') or ' const ' in s:
            if len(s) > 150:
                s = s[:150] + ' ...'
            print('%5d %s' % (i, s))
            n += 1
            if n >= limit:
                break
    print('---- end funcs: %s ----' % path)


# ==================================================================
# miniapp pickers (optional - recon must always run)
# ==================================================================
MS_OLD = "if (b.flags.hooshpay) ms.push(["
MS_NEW = r"""if (b.flags.zarinpal) ms.push(['zarinpal', '\u{1F3E6} زرین‌پال (آنلاین)']);
    if (b.flags.hooshpay) ms.push(["""
rep_lit(MA, MS_OLD, MS_NEW, 'b.flags.zarinpal) ms.push', optional=True)

M_OLD = "if (b.flags.hooshpay) m.push(["
M_NEW = r"""if (b.flags.zarinpal) m.push(['zarinpal', '\u{1F3E6}', 'زرین‌پال — پرداخت آنلاین', 'اتصال به درگاه بانکی؛ پس از پرداخت موفق، کیف پول خودکار شارژ می‌شود']);
    if (b.flags.hooshpay) m.push(["""
rep_lit(MA, M_OLD, M_NEW, 'b.flags.zarinpal) m.push', optional=True)

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

# ---------------- RECON: button map ----------------
print('===== Btn.php head (BUILTIN / SUBMENU / SEED) =====')
dump('btn_head', BTN, 1, 150)
print('===== Btn.php api =====\n')
funcs(BTN, 90)
print('===== how Bot.php resolves reply-keyboard text =====')
grep_all('Btn::', 60, only=BOT)
print('===== main text dispatcher =====')
grep_all('function kbMain', 4, ci=True)
dump_find('bot_text', BOT, 'private static function route', 2, 60)
print('===== zarinpal branch placement check =====')
dump('bot_zp', BOT, 1418, 1470)
print('===== miniapp render branch 1 =====')
dump('ma_r1', MA, 2200, 2250)
print('===== miniapp render branch 2 =====')
dump('ma_r2', MA, 3612, 3662)

# ---------------- version bump ----------------
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = '0.0.2 #22: \u0646\u0645\u0627\u06cc\u0634 \u0632\u0631\u06cc\u0646\u200c\u067e\u0627\u0644 \u062f\u0631 \u0631\u0648\u0634\u200c\u0647\u0627\u06cc \u067e\u0631\u062f\u0627\u062e\u062a \u0645\u06cc\u0646\u06cc\u200c\u0627\u067e'
cl = vj.get('changelog')
if isinstance(cl, list) and NEW:
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
