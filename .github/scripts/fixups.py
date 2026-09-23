# -*- coding: utf-8 -*-
# fixed127 - recon only: products schema, admin form, service creation, happ links
import io, os, re, sys, json, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed127').strip() or 'fixed127'

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


def dump_find(tag, path, pattern, before=6, after=20, limit=2):
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
                print('%5d %s' % (i + 1, ln.strip()[:200]))
    print('---- grep %s : %d hits ----' % (tag, n))


# ------------------------------------------------------------------ recon
for p in ('database/schema.sql', 'app/Service/Migrate.php', 'admin/pages/products.php',
          'app/Service/Svc.php', 'app/Service/Links.php', 'app/Service/Devices.php'):
    info(p)

try:
    mig = sorted(os.listdir(os.path.join(ROOT, 'database/migrations')))
    print('migrations (%d): %s' % (len(mig), ', '.join(mig)))
except Exception as e:
    print('migrations: cannot list (%s)' % e)

# 1) products table definition
dump_find('schema_products', 'database/schema.sql', r'CREATE TABLE[^\n]*products', 0, 48, 1)

# 2) how columns get added by the migrator
dump('migrate_head', 'app/Service/Migrate.php', 1, 60)
dump_find('migrate_reset_days', 'app/Service/Migrate.php', r'reset_days', 10, 10, 2)
grep_lines('migrate_helpers', 'app/Service/Migrate.php', r'function |addColumn|hasColumn|ALTER TABLE', 60)

# 3) admin product form: existing numeric limits
dump_find('prod_reset_days', 'admin/pages/products.php', r'reset_days', 12, 12, 3)
dump_find('prod_ip_limit', 'admin/pages/products.php', r'ip_limit', 10, 10, 3)
grep_lines('prod_cols', 'admin/pages/products.php', r"'(ip_limit|device_limit|reset_days|volume|days|inbound_id|panel_id)'", 60)

# 4) service creation path
dump_find('svc_addclient', 'app/Service/Svc.php', r'->addClient\(', 34, 18, 2)
grep_lines('svc_limits', 'app/Service/Svc.php', r'device_limit|deviceLimit|ip_limit|limitHwid|reset_days', 60)

# 5) happ links service
dump('links_all', 'app/Service/Links.php', 1, 90)

print('build: %s (recon only, nothing patched)' % BUILD)

# ------------------------------------------------------------------ version bump only
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
