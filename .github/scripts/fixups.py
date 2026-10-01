# -*- coding: utf-8 -*-
# fixed172 - recon: Unknown column 'ssl_verify' in vs_panels
import io, os, sys, json, re

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
CACHE = {}
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json', '.sh', '.md')


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8', errors='replace') as fh:
        s = fh.read()
    CACHE[path] = s
    return s


def files_all():
    out = []
    for base, dirs, names in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        for n in names:
            if n.endswith(EXT):
                out.append(os.path.relpath(os.path.join(base, n), ROOT))
    return sorted(out)


def grep_all(needle, limit=30):
    hits = 0
    for p in files_all():
        if p.startswith('.github/'):
            continue
        try:
            s = load(p)
        except Exception:
            continue
        for i, ln in enumerate(s.split(chr(10))):
            if needle in ln:
                hits += 1
                if hits <= limit:
                    print('  %-34s %5d| %s' % (p, i + 1, ln.strip()[:130]))
    print('---- grep: %s (%d) ----' % (needle, hits))


def dump(tag, path, start, end):
    lines = load(path).split(chr(10))
    print('---- dump %s (%s:%d-%d of %d) ----' % (tag, path, start, end, len(lines)))
    for i in range(max(0, start - 1), min(len(lines), end)):
        print('%5d|%s' % (i + 1, lines[i]))
    print('---- end dump %s ----' % tag)


def dump_find(tag, path, needle, before=3, after=30, limit=1):
    lines = load(path).split(chr(10))
    hits = 0
    for i, ln in enumerate(lines):
        if needle in ln:
            hits += 1
            if hits > limit:
                break
            dump('%s#%d' % (tag, hits), path, i + 1 - before, i + 1 + after)
    if hits == 0:
        print('---- dump %s : needle not found (%s) ----' % (tag, needle))


def funcs(path):
    for i, ln in enumerate(load(path).split(chr(10))):
        m = re.match(r'\s*(?:public|private|protected)?\s*(?:static\s+)?function\s+(\w+)\s*\(', ln)
        if m:
            print('  %5d| %s' % (i + 1, m.group(1)))
    print('---- end funcs %s ----' % path)


print('===== where ssl_verify is used =====')
grep_all('ssl_verify', 40)

print('===== Migrate.php structure =====')
funcs('app/Service/Migrate.php')
dump('mig_head', 'app/Service/Migrate.php', 1, 34)
dump_find('mig_panels', 'app/Service/Migrate.php', "'panels'", 2, 40, 2)

print('===== who runs the migrator =====')
grep_all('Migrate::', 30)

print('===== DB.php around the failing execute =====')
funcs('app/DB.php')
dump('db_exec', 'app/DB.php', 70, 125)

print('===== panels.php save path =====')
dump('panels_save', 'admin/pages/panels.php', 40, 130)

print('===== schema.sql panels table =====')
dump_find('schema_panels', 'database/schema.sql', '{p}panels', 1, 42, 1)

print('===== migrations dir =====')
md = os.path.join(ROOT, 'database', 'migrations')
if os.path.isdir(md):
    names = sorted(os.listdir(md))
    print('count: %d' % len(names))
    for n in names[-8:]:
        print('  ' + n)
else:
    print('no database/migrations dir')

vj = json.loads(load('version.json'))
print('changed files: 0')
print('build stays: %s' % vj.get('build'))
print('exit: 0')
