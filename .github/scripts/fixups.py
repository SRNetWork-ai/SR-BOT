# -*- coding: utf-8 -*-
# fixed142 - RECON ONLY (no patches): map the discount/gift code plumbing
# so row 17 (discount codes) can be wired into admin, bot and mini-app.
import io, os, re, sys, json, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed142').strip() or 'fixed142'

CACHE = {}
NEW = set()
ERRORS = []
WARN = []
SKIP_DIRS = {'.git', '.github', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
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
        print('---- dump %s : %s FAILED (%s) ----' % (tag, path, e))
        return
    print('---- dump %s : %s (%d lines) ----' % (tag, path, len(lines)))
    for i in range(max(1, start), min(len(lines), end) + 1):
        s = lines[i - 1]
        if len(s) > 200:
            s = s[:200] + ' ...'
        print('%5d %s' % (i, s))
    print('---- end dump %s ----' % tag)


def dump_find(tag, path, needle, before=4, after=40):
    try:
        lines = load(path).split(chr(10))
    except Exception as e:
        print('---- dump %s FAILED (%s) ----' % (tag, e))
        return
    for i, ln in enumerate(lines, 1):
        if needle in ln:
            dump(tag, path, i - before, i + after)
            return
    print('---- dump %s : needle not found ----' % tag)


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


def grep_all(needle, limit=60, only=None):
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
                if len(s) > 150:
                    s = s[:150] + ' ...'
                print('%s:%d: %s' % (p, i, s))
                n += 1
                if n >= limit:
                    print('---- end grep: %s (truncated at %d) ----' % (needle, n))
                    return
    print('---- end grep: %s (%d) ----' % (needle, n))


def listdir(tag, rel):
    d = os.path.join(ROOT, rel)
    print('---- ls %s : %s ----' % (tag, rel))
    try:
        for fn in sorted(os.listdir(d)):
            fp = os.path.join(d, fn)
            print('%9d  %s' % (os.path.getsize(fp) if os.path.isfile(fp) else 0, fn))
    except Exception as e:
        print('FAILED (%s)' % e)
    print('---- end ls %s ----' % tag)


# ------------------------------- RECON -------------------------------
listdir('admin_pages', 'admin/pages')
listdir('services', 'app/Service')

grep_all('discount_codes', 40)
grep_all('gift_codes', 30)
grep_all('discount_uses', 20)
grep_all('Codes::', 40)
grep_all('discount', 90)

# where a purchase price is computed / charged
grep_all('Wallet::debit', 40)
grep_all('function buy', 30)

# migration runner + schema tail
dump_find('migrate_run', 'app/Service/Migrate.php', 'migrations', 6, 50)

print('---- functions: app/Service/Orders.php ----')
try:
    for i, ln in enumerate(load('app/Service/Orders.php').split(chr(10)), 1):
        if 'function ' in ln:
            print('%5d %s' % (i, ln.strip()[:150]))
except Exception as e:
    print('FAILED (%s)' % e)
print('---- end functions ----')

print('---- functions: app/Service/Wallet.php ----')
try:
    for i, ln in enumerate(load('app/Service/Wallet.php').split(chr(10)), 1):
        if 'function ' in ln:
            print('%5d %s' % (i, ln.strip()[:150]))
except Exception as e:
    print('FAILED (%s)' % e)
print('---- end functions ----')

print('---- file sizes ----')
for p in ['app/Bot/Bot.php', 'miniapp/api.php', 'miniapp/index.php', 'admin/pages/settings.php',
          'database/schema.sql', 'app/Service/Migrate.php', 'app/Service/Orders.php',
          'app/Service/Wallet.php', 'app/Service/Svc.php', 'app/Service/Codes.php']:
    try:
        print('%6d lines  %s' % (len(load(p).split(chr(10))), p))
    except Exception as e:
        print('   n/a  %s (%s)' % (p, e))
print('---- end file sizes ----')

# ============================== version bump ==============================
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('changed files: 0 (recon)')
print('exit: 0')
