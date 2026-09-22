# -*- coding: utf-8 -*-
# fixed120 - recon only: mini-app front-end anchors for devices/links screens
import io, os, re, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed120').strip() or 'fixed120'

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


def dump_find(tag, path, pattern, before=2, after=25, limit=1):
    try:
        lines = load(path).split('\n')
    except Exception as e:
        print('find %s: cannot read %s (%s)' % (tag, path, e))
        return
    rx = re.compile(pattern)
    hits = [i for i, l in enumerate(lines, 1) if rx.search(l)]
    print('---- find %s : %s -> %d hits %s ----' % (tag, pattern, len(hits), hits[:30]))
    for h in hits[:limit]:
        s = max(1, h - before)
        e = min(len(lines), h + after)
        print('  -- around line %d --' % h)
        i = s
        while i <= e:
            print('%5d %s' % (i, lines[i - 1]))
            i += 1
    print('---- end find %s ----' % tag)


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


# ------------------------------------------------------------ API side (already built)
info('miniapp/api.php')
dump_find('api_devices', 'miniapp/api.php', r"'svc_devices'", 2, 34, 1)
dump_find('api_devdel', 'miniapp/api.php', r"'svc_device_del'", 2, 26, 1)
dump_find('api_devclear', 'miniapp/api.php', r"'svc_devices_clear'", 2, 22, 1)
dump_find('api_links', 'miniapp/api.php', r"'svc_links'", 2, 34, 1)

# ------------------------------------------------------------ front-end side (to build)
info('miniapp/index.php')
grep_lines('idx_svc_actions', 'miniapp/index.php', r"svc_(renew|dead|purge_dead|links|devices|device_del|detail|list|extend|traffic)", 50)
grep_lines('idx_functions', 'miniapp/index.php', r"function [A-Za-z0-9_]+\s*\(", 60)
grep_lines('idx_screens', 'miniapp/index.php', r"(id=\"(scr|screen|page|view|tab)[A-Za-z0-9_-]*\"|data-(screen|page|tab)=)", 40)
dump_find('idx_api_helper', 'miniapp/index.php', r"function api\s*\(", 3, 34, 1)
grep_lines('idx_srv_buttons', 'miniapp/index.php', r"(\u062a\u0645\u062f\u06cc\u062f|\u062c\u0632\u0626\u06cc\u0627\u062a \u0633\u0631\u0648\u06cc\u0633|\u062d\u0630\u0641 \u0633\u0631\u0648\u06cc\u0633|\u06a9\u067e\u06cc \u0644\u06cc\u0646\u06a9|QR|\u062f\u0633\u062a\u06af\u0627\u0647)", 50)

# ------------------------------------------------------------ version bump (recon build)
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('changelog: skipped (recon build)')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
