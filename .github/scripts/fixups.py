# -*- coding: utf-8 -*-
# fixed132 - mini app service sheet honours the "happ only" delivery mode
import io, os, re, sys, json, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed132').strip() or 'fixed132'

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


def grep_lines(tag, path, pattern, limit=40):
    try:
        lines = load(path).split('\n')
    except Exception as e:
        print('grep %s: cannot read %s (%s)' % (tag, path, e))
        return
    rx = re.compile(pattern)
    n = 0
    print('---- grep %s : %s ----' % (tag, pattern))
    for i, ln in enumerate(lines):
        if rx.search(ln):
            n += 1
            if n <= limit:
                print('%5d %s' % (i + 1, ln.strip()[:170]))
    print('---- grep %s : %d hits ----' % (tag, n))


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


# =============================== recon: bot sub/cfg callbacks ===============================
grep_lines('bot_cb', 'app/Bot/Bot.php', r"case 'svcsub'|case 'svccfg'|function subView|function cfgView|function sendSub", 20)
dump_find('bot_subview', 'app/Bot/Bot.php', r"case 'svcsub'", 2, 6, 1)

# =============================== mini app patches ===============================
SHEET_HEAD = """  function serviceSheet(s, fresh) {
    /* 0.0.2 #happ-only-ui: محصول «فقط لینک هپ» — ساب و کانفیگ مستقیم نمایش داده نمی‌شود */
    var happOnly = !!s.happ_only;
    var cfg = '';
"""

rep_rx(
    'miniapp/index.php',
    r"""  function serviceSheet\(s, fresh\) \{\n    var cfg = '';\n""",
    lambda m: SHEET_HEAD,
    '#happ-only-ui',
)

rep_rx(
    'miniapp/index.php',
    r"""    \(s\.configs \|\| \[\]\)\.forEach\(function \(c, i\) \{""",
    lambda m: "    (happOnly ? [] : (s.configs || [])).forEach(function (c, i) {",
    'happOnly ? [] :',
)

rep_rx(
    'miniapp/index.php',
    r"""      \(s\.sub\n(        \? '<div class="sec-t">)""",
    lambda m: "      (s.sub && !happOnly\n" + m.group(1),
    's.sub && !happOnly',
)

HAPP_NOTE = """      (happOnly ? '<div class="sec-t"><span>⚡ لینک اختصاصی Happ</span></div>' +
        '<div class="hint3d">این سرویس فقط با اپلیکیشن Happ کار می‌کند؛ تنظیمات سرور و محدودیت دستگاه (HWID) روی همین لینک اعمال می‌شود.</div>' +
        '<button type="button" class="btn b3d" style="width:100%;margin-top:6px" data-links="' + s.id + '">⚡ دریافت لینک Happ</button>' : '') +
"""

rep_rx(
    'miniapp/index.php',
    r"""      \(!s\.sub && !cfg (\? '<div class="alert e">[^']*</div>' : ''\) \+)""",
    lambda m: HAPP_NOTE + "      (!s.sub && !cfg && !happOnly " + m.group(1),
    '!cfg && !happOnly',
)

rep_rx(
    'miniapp/index.php',
    r"""        \(s\.can_links \? '<button""",
    lambda m: "        (s.can_links && !happOnly ? '<button",
    's.can_links && !happOnly',
)

rep_rx(
    'miniapp/index.php',
    r"""    var sub = s\.sub \|\| s\.sub_link \|\| s\.link \|\| '';""",
    lambda m: "    var sub = s.happ_only ? '' : (s.sub || s.sub_link || s.link || '');",
    "s.happ_only ? '' :",
)

write_all()

# =============================== sanity ===============================
SANITY = [
    ('miniapp/index.php', '#happ-only-ui'),
    ('miniapp/index.php', 'var happOnly = !!s.happ_only;'),
    ('miniapp/index.php', 'happOnly ? [] : (s.configs || [])'),
    ('miniapp/index.php', '(s.sub && !happOnly'),
    ('miniapp/index.php', '!s.sub && !cfg && !happOnly'),
    ('miniapp/index.php', '(s.can_links && !happOnly ?'),
    ('miniapp/index.php', "var sub = s.happ_only ? '' :"),
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
entry = 'مینی‌اپ: برای محصولات «فقط لینک هپ» فقط لینک Happ نمایش داده می‌شود'
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
