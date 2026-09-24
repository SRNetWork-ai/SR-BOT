# -*- coding: utf-8 -*-
# fixed159 - 0.0.2 #22: cron auto-poll for Zarinpal + admin bot label; recon of driver signatures.
import io, os, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed159').strip() or 'fixed159'

CACHE = {}
NEW = set()
ERRORS = []
WARN = []
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json')

CRON = 'cron/tasks.php'
AB = 'app/Bot/AdminBot.php'
ZP = 'app/Service/Zarinpal.php'
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


def funcs(path, limit=200):
    print('---- funcs: %s ----' % path)
    n = 0
    try:
        lines = load(path).split(chr(10))
    except Exception as e:
        print('failed: %s' % e)
        return
    for i, ln in enumerate(lines, 1):
        s = ln.strip()
        if s.startswith('public ') or s.startswith('private ') or s.startswith('protected ') or s.startswith('const ') or s.startswith('function '):
            if len(s) > 150:
                s = s[:150] + ' ...'
            print('%5d %s' % (i, s))
            n += 1
            if n >= limit:
                break
    print('---- end funcs: %s ----' % path)


# ==================================================================
# 1) cron poller
# ==================================================================
HP_CRON_TAIL = (
    "        } catch (Throwable $e) {\n"
    "            cron_say('hooshpay poll failed: ' . $e->getMessage());\n"
    "        }\n"
    "        usleep(250000);\n"
    "    }\n"
    "}\n"
)

ZP_CRON = """
/* 0.0.2 #22: auto-poll pending Zarinpal transactions */
if (class_exists('Zarinpal') && Zarinpal::enabled()) {
    $zpWait = DB::all("SELECT * FROM {p}transactions
                       WHERE method = 'zarinpal' AND status = 'pending'
                         AND created_at > DATE_SUB(NOW(), INTERVAL 2 DAY)
                       ORDER BY id ASC LIMIT 25");
    foreach ($zpWait as $tx) {
        try {
            $pr = Zarinpal::poll($tx);
            if (!empty($pr['ok'])) {
                $report['zarinpal'] = ($report['zarinpal'] ?? 0) + 1;
                cron_say('zarinpal tx #' . (int)$tx['id'] . ' => ' . (string)($pr['message'] ?? 'done'));
            }
        } catch (Throwable $e) {
            cron_say('zarinpal poll failed: ' . $e->getMessage());
        }
        usleep(250000);
    }
}
"""

rep_lit(CRON, HP_CRON_TAIL, HP_CRON_TAIL + ZP_CRON, "zarinpal poll failed")

# ==================================================================
# 2) admin bot payment card label
# ==================================================================
rep_lit(
    AB,
    "$mLbl   = ['card' => '",
    "$mLbl   = ['zarinpal' => \"\\u{1F3E6} \u0632\u0631\u06cc\u0646\u200c\u067e\u0627\u0644 (\u062e\u0648\u062f\u06a9\u0627\u0631)\", 'card' => '",
    "'zarinpal' => \"",
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
    (CRON, "zarinpal poll failed"),
    (CRON, "method = 'zarinpal' AND status = 'pending'"),
    (AB, "'zarinpal' => \""),
]
ok = 0
for p, needle in SANITY:
    good = needle in load(p)
    print('sanity %s / %s : %s' % (p, needle[:34], 'ok' if good else 'MISSING'))
    if good:
        ok += 1
print('sanity: %d/%d' % (ok, len(SANITY)))

# ---------------- recon: driver signatures ----------------
print('===== Zarinpal map =====')
funcs(ZP, 80)
print('===== Zarinpal consts head =====')
dump('zp_head', ZP, 1, 60)
print('===== createInvoice =====')
dump_find('zp_inv', ZP, 'function createInvoice', 2, 62)
print('===== poll =====')
dump_find('zp_poll', ZP, 'function poll', 2, 26)
print('===== status check callbacks =====')
grep_all('hpchk', 20)
grep_all("'wal:chk", 10)
print('===== wallet menu builder =====')
grep_all('walletMenu', 12)
grep_all('hooshpayOn', 12)

# ---------------- version bump ----------------
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = '0.0.2 #22: \u067e\u06cc\u06af\u06cc\u0631\u06cc \u062e\u0648\u062f\u06a9\u0627\u0631 \u062a\u0631\u0627\u06a9\u0646\u0634\u200c\u0647\u0627\u06cc \u0632\u0631\u06cc\u0646\u200c\u067e\u0627\u0644 \u062f\u0631 \u06a9\u0631\u0627\u0646'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
