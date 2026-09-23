# -*- coding: utf-8 -*-
# fixed135 - RECON #2: Bot::deliver body, Svc::liveConfigs, Xui3 link/sub endpoints,
# local vs panel sub, and Xui isXui3/xui3 helpers.
import io, os, re, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed135').strip() or 'fixed135'

CACHE = {}


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8') as fh:
        CACHE[path] = fh.read()
    return CACHE[path]


def info(path):
    try:
        s = load(path)
    except Exception as e:
        print('info %s: cannot read (%s)' % (path, e))
        return
    print('info %s: %d bytes, %d lines' % (path, len(s.encode('utf-8')), len(s.split('\n'))))


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


def dump_find(tag, path, pattern, before=3, after=25, limit=1):
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


def grep_lines(tag, path, pattern, limit=60):
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
                print('%5d %s' % (i + 1, ln.strip()[:190]))
    print('---- grep %s : %d hits ----' % (tag, n))


# ===== A) full deliver() body (why sub-only still ships configs) =====
dump('bot_deliver', 'app/Bot/Bot.php', 2863, 2960)

# ===== B) where do 10 configs come from =====
dump_find('svc_live', 'app/Service/Svc.php', r'function liveConfigs', 3, 46, 1)
dump('x3_links', 'app/Panel/Xui3.php', 700, 806)

# ===== C) local vs panel subscription =====
dump_find('svc_localsub', 'app/Service/Svc.php', r'function localSub', 3, 42, 1)
dump_find('svc_panelsub', 'app/Service/Svc.php', r'function panelSub', 3, 34, 1)
dump_find('svc_submode', 'app/Service/Svc.php', r'function subMode', 3, 12, 1)

# ===== D) helper availability =====
grep_lines('x3_methods', 'app/Panel/Xui3.php', r'public function |public static function ', 70)
grep_lines('xui_x3', 'app/Panel/Xui.php', r'function isXui3|function xui3|function subLink|function buildConfigLink|function isVpnUi|function forPanel', 25)
grep_lines('pr_save', 'admin/pages/products.php', r'deliver_mode|device_limit|dmHas|prCols|prHasLimits', 40)

info('app/Panel/Xui3.php')
info('app/Panel/Xui.php')

# ===== version bump only =====
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
print('changelog: skipped (recon build)')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
