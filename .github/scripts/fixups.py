# -*- coding: utf-8 -*-
# fixed124 - recon: panel driver layer (Xui facade, Marzban, PasarGuard) for row 16
import io, os, re, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed124').strip() or 'fixed124'

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


def grep_lines(tag, path, pattern, limit=60):
    try:
        lines = load(path).split('\n')
    except Exception as e:
        print('grep %s: cannot read %s (%s)' % (tag, path, e))
        return
    rx = re.compile(pattern)
    out = [(i, l) for i, l in enumerate(lines, 1) if rx.search(l)]
    print('---- grep %s : %s -> %d hits ----' % (tag, pattern, len(out)))
    for i, l in out[:limit]:
        print('%5d %s' % (i, l[:200]))
    print('---- end grep %s ----' % tag)


# ---------------------------------------------- panel folder
try:
    files = sorted(os.listdir(os.path.join(ROOT, 'app', 'Panel')))
    print('app/Panel: ' + ', '.join(files))
except Exception as e:
    print('app/Panel: cannot list (%s)' % e)

# ---------------------------------------------- facade head (TYPES / ALIASES / normType / ctor)
dump('xui_head', 'app/Panel/Xui.php', 1, 150)
grep_lines('xui_publics', 'app/Panel/Xui.php', r"^\s*(public|private|protected)\s+(static\s+)?function ", 90)
grep_lines('xui_dispatch', 'app/Panel/Xui.php', r"new (Marzban|PasarGuard|Xui3)\(|\$this->(mz|pg|x3|drv)\b", 40)

# ---------------------------------------------- existing non-3x drivers
info('app/Panel/Marzban.php')
dump('mz_head', 'app/Panel/Marzban.php', 1, 80)
grep_lines('mz_publics', 'app/Panel/Marzban.php', r"^\s*(public|private|protected)\s+(static\s+)?function ", 70)
info('app/Panel/PasarGuard.php')
grep_lines('pg_publics', 'app/Panel/PasarGuard.php', r"^\s*(public|private|protected)\s+(static\s+)?function ", 70)

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
