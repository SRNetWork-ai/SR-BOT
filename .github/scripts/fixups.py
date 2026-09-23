# -*- coding: utf-8 -*-
# fixed123 - mini-app: expose can_devs / can_links on the service payload + verify fixed122 buttons
import io, os, re, sys, json, tempfile, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed123').strip() or 'fixed123'

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


def grep_lines(tag, path, pattern, limit=40):
    try:
        lines = load(path).split('\n')
    except Exception as e:
        print('grep %s: cannot read %s (%s)' % (tag, path, e))
        return
    rx = re.compile(pattern)
    out = [(i, l) for i, l in enumerate(lines, 1) if rx.search(l)]
    print('---- grep %s : %s -> %d hits ----' % (tag, pattern, len(out)))
    for i, l in out[:limit]:
        print('%5d %s' % (i, l[:220]))
    print('---- end grep %s ----' % tag)


def rep_rx(path, pattern, fn, marker=None, expect=1, optional=False, flags=re.M):
    try:
        src = load(path)
    except Exception as e:
        ERRORS.append('%s: cannot read (%s)' % (path, e))
        return
    if marker and marker in src:
        print('skip (already applied): %s / %s' % (path, marker))
        return
    rx = re.compile(pattern, flags)
    n = len(rx.findall(src))
    if n != expect:
        msg = '%s: regex for %s matched %d times (want %d)' % (path, marker or pattern[:40], n, expect)
        if optional:
            WARN.append(msg)
        else:
            ERRORS.append(msg)
        return
    CACHE[path] = rx.sub(fn, src, count=expect)
    NEW[path] = True
    print('patched %s (%s)' % (path, marker or 'ok'))


def write_all():
    if ERRORS:
        print('ABORTED - anchors not found:')
        for e in ERRORS:
            print(' - ' + e)
        sys.exit(1)
    lint = 0
    for path in sorted(NEW):
        data = CACHE[path]
        if path.endswith('.php'):
            tmp = os.path.join(tempfile.gettempdir(), 'syntax-check.php')
            with io.open(tmp, 'w', encoding='utf-8') as fh:
                fh.write(data)
            r = subprocess.run(['php', '-l', tmp], capture_output=True, text=True)
            if r.returncode != 0:
                print('php lint FAILED for ' + path)
                print(r.stdout + r.stderr)
                sys.exit(1)
            lint += 1
        with io.open(os.path.join(ROOT, path), 'w', encoding='utf-8') as fh:
            fh.write(data)
        print('wrote ' + path)
    print('php lint: %s' % ('on' if lint else 'n/a'))
    print('changed files: %d' % len(NEW))
    if WARN:
        print('warnings (optional patches skipped):')
        for w in WARN:
            print(' - ' + w)


# ------------------------------------------------ verify what fixed122 inserted
grep_lines('idx_new_attrs', 'miniapp/index.php', r"data-(devs|links|devdel|devclr)=", 20)

# ------------------------------------------------ patch: can_devs / can_links flags
INS = (
    "        /* 0.0.2 #ma-dev-flags \u2014 \u062f\u06a9\u0645\u0647\u200c\u0647\u0627\u06cc \u062f\u0633\u062a\u06af\u0627\u0647 \u0648 \u0644\u06cc\u0646\u06a9 \u0641\u0642\u0637 \u0631\u0648\u06cc \u067e\u0646\u0644\u200c\u0647\u0627\u06cc \u067e\u0634\u062a\u06cc\u0628\u0627\u0646\u06cc\u200c\u0634\u062f\u0647 */\n"
    "        'can_devs'   => class_exists('Devices') && Devices::supported($s),\n"
    "        'can_links'  => class_exists('Links') && Links::supported($s),\n"
)

rep_rx(
    'miniapp/api.php',
    r"^        'can_tut'    => \(string\)DB::setting\('ma_btn_tut', '1'\) === '1',\n",
    lambda m: m.group(0) + INS,
    marker='#ma-dev-flags',
)

write_all()

# ------------------------------------------------ sanity (correct needles this time)
SANITY = [
    ('miniapp/api.php', "'can_devs'"),
    ('miniapp/api.php', "'can_links'"),
    ('miniapp/api.php', '#ma-dev-flags'),
    ('miniapp/index.php', 'data-devs="'),
    ('miniapp/index.php', 'data-links="'),
    ('miniapp/index.php', 'data-devdel="'),
    ('miniapp/index.php', 'data-devclr="'),
    ('miniapp/index.php', '#ma-dev-ui'),
    ('miniapp/index.php', "function devicesSheet(id)"),
    ('miniapp/index.php', "function linksSheet(id)"),
]
for path, needle in SANITY:
    try:
        ok = needle in load(path)
    except Exception:
        ok = False
    print('sanity %s / %s : %s' % (path, needle, 'ok' if ok else 'MISSING'))

# ------------------------------------------------ version + changelog
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
ENTRY = '\u0645\u06cc\u0646\u06cc\u200c\u0627\u067e: \u0646\u0645\u0627\u06cc\u0634 \u062f\u06a9\u0645\u0647\u200c\u0647\u0627\u06cc \u062f\u0633\u062a\u06af\u0627\u0647\u200c\u0647\u0627 \u0648 \u0644\u06cc\u0646\u06a9\u200c\u0647\u0627 \u0641\u0642\u0637 \u0631\u0648\u06cc \u0633\u0631\u0648\u06cc\u0633\u200c\u0647\u0627\u06cc \u067e\u0634\u062a\u06cc\u0628\u0627\u0646\u06cc\u200c\u0634\u062f\u0647'
cl = vj.get('changelog')
if isinstance(cl, list) and (len(cl) == 0 or isinstance(cl[0], str)):
    if ENTRY in cl:
        print('changelog: already present')
    else:
        cl.insert(0, ENTRY)
        print('changelog: entry added')
    vj['changelog'] = cl
else:
    print('changelog: skipped (shape=%s)' % type(cl).__name__)
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
