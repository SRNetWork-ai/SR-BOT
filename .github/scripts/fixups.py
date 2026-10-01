# -*- coding: utf-8 -*-
# fixed169 - recon for row 22 (zarinpal) mini-app render branches + status endpoint
import io, os, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
CACHE = {}


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8') as fh:
        s = fh.read()
    CACHE[path] = s
    return s


def dump_all(tag, path, needle, before=4, after=20, limit=3):
    lines = load(path).split(chr(10))
    hits = 0
    for i, ln in enumerate(lines):
        if needle in ln:
            hits += 1
            if hits > limit:
                break
            a = max(0, i - before)
            b = min(len(lines), i + after + 1)
            print('---- dump %s #%d : %s (%s:%d) ----' % (tag, hits, needle, path, i + 1))
            for k in range(a, b):
                print('%5d|%s' % (k + 1, lines[k]))
    if hits == 0:
        print('---- dump %s : needle not found (%s) ----' % (tag, needle))
    else:
        print('---- end dump %s : %d hit(s) ----' % (tag, hits))


def grep(path, needle, limit=20):
    lines = load(path).split(chr(10))
    hits = 0
    for i, ln in enumerate(lines):
        if needle in ln:
            hits += 1
            if hits <= limit:
                print('  %5d| %s' % (i + 1, ln.strip()[:170]))
    print('---- grep %s in %s (%d) ----' % (needle, path, hits))


for p in ['miniapp/index.php', 'miniapp/api.php', 'app/Service/Zarinpal.php']:
    s = load(p)
    print('%s: %d lines, %d bytes' % (p, len(s.split(chr(10))), len(s.encode('utf-8'))))

print('===== what already mentions zarinpal =====')
grep('miniapp/index.php', 'zarinpal', 30)
grep('miniapp/api.php', 'zarinpal', 30)

print('===== hooshpay render branches (mini-app) =====')
dump_all('render', 'miniapp/index.php', "r.method === 'hooshpay'", 6, 22, 3)

print('===== hpPayBox helper =====')
dump_all('hppaybox', 'miniapp/index.php', 'function hpPayBox', 2, 34, 1)

print('===== hpCheck handler + binding =====')
dump_all('hpcheck', 'miniapp/index.php', 'function hpCheck', 2, 28, 1)
dump_all('hpchkbind', 'miniapp/index.php', 'data-hpchk', 6, 8, 3)

print('===== topup_hp_check endpoint =====')
dump_all('hpchk_api', 'miniapp/api.php', 'topup_hp_check', 6, 48, 2)

print('===== zarinpal deposit block in api.php =====')
dump_all('zp_api', 'miniapp/api.php', "'zarinpal'", 4, 26, 2)

vj = json.loads(load('version.json'))
print('changed files: 0')
print('build stays: %s' % vj.get('build'))
print('exit: 0')
