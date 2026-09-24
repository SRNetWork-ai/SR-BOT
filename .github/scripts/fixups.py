# -*- coding: utf-8 -*-
# fixed154 - recon only: existing auto-gateway plumbing (HooshPay/NOWPayments) to model a Zarinpal driver on (0.0.2 #22).
import io, os, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed154').strip() or 'fixed154'

CACHE = {}
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
        if len(s) > 200:
            s = s[:200] + ' ...'
        print('%5d %s' % (i, s))
    print('---- end dump %s ----' % tag)


def funcs(path, limit=90):
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
            if len(s) > 150:
                s = s[:150] + ' ...'
            print('%5d %s' % (i, s))
            n += 1
            if n >= limit:
                break
    print('---- end funcs %s (%d lines) ----' % (path, len(lines)))


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


def grep_all(needle, limit=22, only=None):
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
                if len(s) > 170:
                    s = s[:170] + ' ...'
                print('%s:%d: %s' % (p, i, s))
                n += 1
                if n >= limit:
                    print('---- end grep: %s (truncated) ----' % needle)
                    return
    print('---- end grep: %s (%d) ----' % (needle, n))


print('===== HooshPay driver =====')
funcs('app/Service/HooshPay.php', 40)
dump('hp_head', 'app/Service/HooshPay.php', 1, 90)

print('===== root callback file =====')
dump('hp_root', 'hooshpay.php', 1, 140)

print('===== Gateway: normalize + kind filters =====')
dump('gw_norm', 'app/Service/Gateway.php', 133, 200)
dump('gw_hp', 'app/Service/Gateway.php', 338, 400)

print('===== Wallet deposit API =====')
funcs('app/Service/Wallet.php', 40)
dump('wal_dep', 'app/Service/Wallet.php', 89, 140)
grep_all('autoSqlList', 10)

print('===== bot deposit flow =====')
dump('bot_wal', 'app/Bot/Bot.php', 556, 596)
grep_all('askAmount', 14)

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
