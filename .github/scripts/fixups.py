# -*- coding: utf-8 -*-
# fixed149 - recon only: admin router/menu/permissions + stats helpers for the reports page (0.0.2 #24).
import io, os, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed149').strip() or 'fixed149'

CACHE = {}
NEW = set()
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json')


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8', errors='replace') as fh:
        CACHE[path] = fh.read()
    return CACHE[path]


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


def dump_find(tag, path, needle, before=2, after=25):
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
                if len(s) > 160:
                    s = s[:160] + ' ...'
                print('%s:%d: %s' % (p, i, s))
                n += 1
                if n >= limit:
                    print('---- end grep: %s (truncated) ----' % needle)
                    return
    print('---- end grep: %s (%d) ----' % (needle, n))


def funcs(path, limit=80):
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
            if len(s) > 140:
                s = s[:140] + ' ...'
            print('%5d %s' % (i, s))
            n += 1
            if n >= limit:
                break
    print('---- end funcs %s (%d lines) ----' % (path, len(lines)))


print('===== admin router: page map + groups + visibility =====')
dump('router_map', 'admin/index.php', 358, 418)

print('===== permissions =====')
grep_all('function can(', 6)
grep_all('function need(', 6)
grep_all("'payments.view'", 10)
grep_all('PERM_GROUPS', 10)
dump_find('perm_list', 'admin/pages/admins.php', 'payments.view', 6, 34)

print('===== money / stats helpers for reports =====')
funcs('app/Service/Stats.php', 60)
funcs('app/Service/Orders.php', 40)
grep_all('dailySales', 12)
grep_all('SUM(amount)', 14)

print('===== dashboard chart markup (reuse) =====')
dump_find('dash_chart', 'admin/pages/dashboard.php', 'canvas', 4, 26)

# ---------------- version bump ----------------
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('changed files: 0 (recon)')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
