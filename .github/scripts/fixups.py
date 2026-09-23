# -*- coding: utf-8 -*-
# fixed133 - enforce the "happ only" delivery mode server side (bot callbacks + mini app API)
import io, os, re, sys, json, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed133').strip() or 'fixed133'

CACHE = {}
NEW = {}
ERRORS = []
WARN = []


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8') as fh:
        CACHE[path] = fh.read()
    return CACHE[path]


def dump(tag, path, start, end):
    try:
        lines = load(path).split('\n')
    except Exception as e:
        print('dump %s: cannot read %s (%s)' % (tag, path, e))
        return
    print('---- dump %s : %s (%d lines) ----' % (tag, path, len(lines)))
    i = max(1, start)
    last = min(len(lines), end)
    while i <= last:
        print('%5d %s' % (i, lines[i - 1]))
        i += 1
    print('---- end dump %s ----' % tag)


def dump_find(tag, path, pattern, before=4, after=20, limit=1):
    try:
        lines = load(path).split('\n')
    except Exception as e:
        print('dump_find %s: cannot read %s (%s)' % (tag, path, e))
        return
    rx = re.compile(pattern)
    hits = [i + 1 for i, ln in enumerate(lines) if rx.search(ln)]
    print('---- find %s : %s -> %d hits %s ----' % (tag, pattern, len(hits), hits[:40]))
    for n in hits[:limit]:
        dump('%s@%d' % (tag, n), path, n - before, n + after)


def rep_rx(path, pattern, fn, marker, expect=1, optional=False, flags=re.M):
    src = NEW.get(path, load(path))
    if marker and marker in src:
        print('skip (already applied): %s / %s' % (path, marker))
        return
    rx = re.compile(pattern, flags)
    n = len(rx.findall(src))
    if n != expect:
        msg = '%s: regex for %s matched %d times (want %d)' % (path, marker, n, expect)
        if optional:
            WARN.append(msg)
            print('warn: ' + msg)
            return
        ERRORS.append(msg)
        print('ERROR: ' + msg)
        return
    NEW[path] = rx.sub(fn, src)
    print('patched %s / %s' % (path, marker))


def write_all():
    if ERRORS:
        print('ABORTED - anchors not found:')
        for e in ERRORS:
            print(' - ' + e)
        sys.exit(1)
    try:
        subprocess.run(['php', '-v'], capture_output=True)
        php = 'php'
    except Exception:
        php = None
    tmp = os.path.join(os.environ.get('TMPDIR', '/tmp'), 'syntax-check.php')
    for p, s in NEW.items():
        if php and p.endswith('.php'):
            with io.open(tmp, 'w', encoding='utf-8') as fh:
                fh.write(s)
            r = subprocess.run([php, '-l', tmp], capture_output=True, text=True)
            if r.returncode != 0:
                print('php lint FAILED for %s:' % p)
                print((r.stdout or '') + (r.stderr or ''))
                sys.exit(1)
        with io.open(os.path.join(ROOT, p), 'w', encoding='utf-8') as fh:
            fh.write(s)
        print('wrote %s' % p)
    print('php lint: %s' % ('on' if php else 'n/a'))
    print('changed files: %d' % len(NEW))
    if WARN:
        print('warnings (optional patches skipped):')
        for w in WARN:
            print(' - ' + w)


# =============================== recon ===============================
dump_find('bot_sendcfg', 'app/Bot/Bot.php', r'function sendConfig', 2, 14, 1)

# =============================== bot guards ===============================
GUARD = r"""        /* 0.0.2 {MARK}: محصول «فقط لینک هپ» لینک ساب یا کانفیگ مستقیم نمی\u200cدهد */
        $gS = self::myService($id);
        if ($gS && Svc::wantsHapp(Svc::deliverMode($gS)) && Svc::happLink($gS) !== '') {
            Tg::answerCb($cbId);
            self::happView($chatId, null, $id);
            return;
        }
"""


def _guard(mark):
    def _fn(m):
        return m.group(0) + GUARD.replace('{MARK}', mark)
    return _fn


rep_rx(
    'app/Bot/Bot.php',
    r"^(\s*)private static function sendSub\(\$chatId, \$cbId, int \$id\): void\n\1\{\n",
    _guard('#happ-only-guard'),
    '#happ-only-guard',
)

rep_rx(
    'app/Bot/Bot.php',
    r"^(\s*)private static function sendConfig\(\$chatId, \$cbId, int \$id\): void\n\1\{\n",
    _guard('#happ-only-guard2'),
    '#happ-only-guard2',
    optional=True,
)

# =============================== mini app API ===============================
HAPP_API_VAR = r"""    /* 0.0.2 #happ-only-api: محصول «فقط لینک هپ» — ساب و کانفیگ خالی برگردانده می\u200cشود */
    $__happOnly = class_exists('Svc') && method_exists('Svc', 'wantsHapp')
        && Svc::wantsHapp(Svc::deliverMode($s))
        && class_exists('Links') && Links::supported($s);

"""

rep_rx(
    'miniapp/api.php',
    r"    return \[\n        'id'         => \(int\)\$s\['id'\],",
    lambda m: HAPP_API_VAR + m.group(0),
    '#happ-only-api',
)

rep_rx(
    'miniapp/api.php',
    r"(        'sub'        => )([\s\S]*?),\n        'sub_own'",
    lambda m: m.group(1) + "$__happOnly ? '' : (" + m.group(2) + "),\n        'sub_own'",
    "'sub'        => $__happOnly",
)

rep_rx(
    'miniapp/api.php',
    r"        'sub_own'    => class_exists\('Svc'\) \? Svc::localSub\(\$s\) : '',",
    lambda m: "        'sub_own'    => $__happOnly ? '' : (class_exists('Svc') ? Svc::localSub($s) : ''),",
    "'sub_own'    => $__happOnly",
)

rep_rx(
    'miniapp/api.php',
    r"        'sub_main'   => \(string\)\(\$s\['sub_link'\] \?\? ''\),",
    lambda m: "        'sub_main'   => $__happOnly ? '' : (string)($s['sub_link'] ?? ''),",
    "'sub_main'   => $__happOnly",
)

rep_rx(
    'miniapp/api.php',
    r"        'sub_code'   => class_exists\('Svc'\) && method_exists\('Svc', 'subCode'\) \? Svc::subCode\(\$s\) : '',",
    lambda m: "        'sub_code'   => $__happOnly ? '' : (class_exists('Svc') && method_exists('Svc', 'subCode') ? Svc::subCode($s) : ''),",
    "'sub_code'   => $__happOnly",
)

rep_rx(
    'miniapp/api.php',
    r"(        'configs'    => )([\s\S]*?),\n        'days'",
    lambda m: m.group(1) + "$__happOnly ? [] : (" + m.group(2) + "),\n        'days'",
    "'configs'    => $__happOnly",
)

write_all()

# =============================== sanity ===============================
SANITY = [
    ('app/Bot/Bot.php', '#happ-only-guard'),
    ('app/Bot/Bot.php', 'Svc::wantsHapp(Svc::deliverMode($gS))'),
    ('miniapp/api.php', '#happ-only-api'),
    ('miniapp/api.php', '$__happOnly = class_exists('),
    ('miniapp/api.php', "'sub'        => $__happOnly"),
    ('miniapp/api.php', "'sub_own'    => $__happOnly"),
    ('miniapp/api.php', "'sub_main'   => $__happOnly"),
    ('miniapp/api.php', "'sub_code'   => $__happOnly"),
    ('miniapp/api.php', "'configs'    => $__happOnly"),
]
for path, needle in SANITY:
    try:
        with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8') as fh:
            body = fh.read()
    except Exception as e:
        print('sanity %s: cannot read (%s)' % (path, e))
        continue
    print('sanity %s / %s : %s' % (path, needle, 'ok' if needle in body else 'MISSING'))

# =============================== version bump ===============================
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
entry = 'تضمین حالت «فقط لینک هپ» در ربات و API مینی\u200cاپ'
cl = vj.get('changelog')
if isinstance(cl, list):
    if entry not in cl:
        cl.insert(0, entry)
    vj['changelog'] = cl
    print('changelog: entry added')
else:
    print('changelog: skipped (unexpected type)')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
