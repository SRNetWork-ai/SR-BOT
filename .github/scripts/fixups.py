# -*- coding: utf-8 -*-
# fixed153 - 0.0.2 #19: admin settings for IP/device trial caps + mini-app fingerprint. Recon for #22 gateways.
import io, os, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed153').strip() or 'fixed153'

CACHE = {}
NEW = set()
ERRORS = []
WARN = []
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json')

SET = 'admin/pages/settings.php'
MA = 'miniapp/api.php'


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
        if len(s) > 220:
            s = s[:220] + ' ...'
        print('%5d %s' % (i, s))
    print('---- end dump %s ----' % tag)


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


def grep_all(needle, limit=25, only=None):
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


def funcs(path, limit=90):
    print('---- funcs %s ----' % path)
    try:
        lines = load(path).split(chr(10))
    except Exception as e:
        print('(failed %s)' % e)
        return
    n = 0
    for i, ln in enumerate(lines, 1):
        s = ln.strip()
        if s.startswith('function ') or ' function ' in s:
            if len(s) > 150:
                s = s[:150] + ' ...'
            print('%5d %s' % (i, s))
            n += 1
            if n >= limit:
                break
    print('---- end funcs %s (%d lines) ----' % (path, len(lines)))


# ---------------- 1) settings: save handlers ----------------
rep_lit(
    SET,
    "        $chk('test_panel_check');\n",
    "        $chk('test_panel_check');\n"
    "        $clamp('test_ip_max', 0, 0, 100);   /* 0.0.2 #19 */\n"
    "        $chk('test_device_unique');         /* 0.0.2 #19 */\n",
    "$clamp('test_ip_max'",
)

# ---------------- 2) settings: form fields ----------------
LBL_IP = '\u0633\u0642\u0641 \u0627\u06a9\u0627\u0646\u062a \u062a\u0633\u062a \u0628\u0631\u0627\u06cc \u0647\u0631 IP'
HINT_IP = '\u06f0 = \u062e\u0627\u0645\u0648\u0634 \u00b7 \u0641\u0642\u0637 \u0628\u0631\u0627\u06cc \u0645\u06cc\u0646\u06cc\u200c\u0627\u067e \u0648 \u0648\u0628'
LBL_DEV = '\u0647\u0631 \u062f\u0633\u062a\u06af\u0627\u0647 \u0641\u0642\u0637 \u06cc\u06a9 \u0627\u06a9\u0627\u0646\u062a \u062a\u0633\u062a'

FORM_ANCHOR = '      <label class="check"><input type="checkbox" name="test_panel_check" value="1"'

FORM_NEW = (
    '      <div class="form-grid g2 mt3">\n'
    '        <div class="field"><label>' + LBL_IP + '</label>\n'
    '          <input class="mono" type="number" min="0" name="test_ip_max" value="<?= (int)$SET(\'test_ip_max\', 0) ?>">'
    '<div class="hint">' + HINT_IP + '</div></div>\n'
    '      </div>\n'
    '      <label class="check"><input type="checkbox" name="test_device_unique" value="1" <?= $on(\'test_device_unique\') ?>>'
    '<span>' + LBL_DEV + '</span></label>\n'
)

rep_lit(SET, FORM_ANCHOR, FORM_NEW + FORM_ANCHOR, 'name="test_ip_max"')

# ---------------- 3) mini-app: request fingerprint ----------------
MA_ANCHOR = "$tg   = (int)$auth['user']['id'];\n"
MA_NEW = (
    MA_ANCHOR
    + '\n'
    + '/* 0.0.2 #19: request fingerprint for trial-abuse rules (real client ip, only here - not on the bot webhook) */\n'
    + "if (class_exists('Svc')) {\n"
    + "    $maFpIp = class_exists('Guard') ? Guard::clientIp() : (string)($_SERVER['REMOTE_ADDR'] ?? '');\n"
    + "    Svc::setTestFp($maFpIp, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));\n"
    + '}\n'
)

rep_lit(MA, MA_ANCHOR, MA_NEW, 'Svc::setTestFp(')

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
    (SET, "$clamp('test_ip_max', 0, 0, 100);"),
    (SET, 'name="test_ip_max"'),
    (SET, 'name="test_device_unique"'),
    (MA, 'Svc::setTestFp($maFpIp'),
]
ok = 0
for p, needle in SANITY:
    good = needle in load(p)
    print('sanity %s / %s : %s' % (p, needle[:46], 'ok' if good else 'MISSING'))
    if good:
        ok += 1
print('sanity: %d/%d' % (ok, len(SANITY)))

# ---------------- recon: payment gateways (0.0.2 #22) ----------------
print('===== Gateway.php surface =====')
funcs('app/Service/Gateway.php', 70)
dump('gw_head', 'app/Service/Gateway.php', 1, 70)
print('===== gateway drivers / keys =====')
grep_all('pay_gateways', 12)
grep_all("'nowpay'", 16)
grep_all("'hooshpay'", 16)
grep_all('zarinpal', 10)
print('===== admin gateways page head =====')
dump('gw_page', 'admin/pages/gateways.php', 1, 60)

# ---------------- version bump ----------------
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = '0.0.2 #19: \u062a\u0646\u0638\u06cc\u0645\u0627\u062a \u0645\u062d\u062f\u0648\u062f\u06cc\u062a IP \u0648 \u062f\u0633\u062a\u06af\u0627\u0647 \u0627\u06a9\u0627\u0646\u062a \u062a\u0633\u062a'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
