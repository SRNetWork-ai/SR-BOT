# -*- coding: utf-8 -*-
# fixed121 - recon #2: exact anchors in miniapp/index.php + Devices/Links item shapes
import io, os, re, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed121').strip() or 'fixed121'

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


# ---------------------------------------------- mini-app: service sheet + delegates
dump('idx_row_copybox', 'miniapp/index.php', 1228, 1246)
dump('idx_svc_sheet', 'miniapp/index.php', 1744, 1815)
grep_lines('idx_closest', 'miniapp/index.php', r"closest\(", 40)
dump_find('idx_click_delegate', 'miniapp/index.php', r"\[data-renew\]", 8, 24, 2)

# ---------------------------------------------- service layer shapes
info('app/Service/Devices.php')
dump('dev_all', 'app/Service/Devices.php', 1, 150)
info('app/Service/Links.php')
dump('links_all', 'app/Service/Links.php', 1, 120)

# ---------------------------------------------- version bump (recon build)
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
