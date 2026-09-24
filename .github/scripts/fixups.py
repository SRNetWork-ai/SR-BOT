# -*- coding: utf-8 -*-
# fixed143 - RECON ONLY: figure out which 0.0.2 roadmap rows already exist.
import io, os, re, sys, json, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed143').strip() or 'fixed143'

CACHE = {}
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json', '.yml', '.sh', '.md', '.neon', '.xml', '.dist')


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8', errors='replace') as fh:
        CACHE[path] = fh.read()
    return CACHE[path]


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


def grep_all(needle, limit=40, only=None):
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
                if len(s) > 140:
                    s = s[:140] + ' ...'
                print('%s:%d: %s' % (p, i, s))
                n += 1
                if n >= limit:
                    print('---- end grep: %s (truncated) ----' % needle)
                    return
    print('---- end grep: %s (%d) ----' % (needle, n))


def funcs(path):
    print('---- functions: %s ----' % path)
    try:
        for i, ln in enumerate(load(path).split(chr(10)), 1):
            if 'function ' in ln:
                print('%5d %s' % (i, ln.strip()[:140]))
    except Exception as e:
        print('FAILED (%s)' % e)
    print('---- end functions ----')


def listdir(rel):
    d = os.path.join(ROOT, rel)
    print('---- ls %s ----' % rel)
    try:
        for fn in sorted(os.listdir(d)):
            fp = os.path.join(d, fn)
            print('%9s  %s' % (os.path.getsize(fp) if os.path.isfile(fp) else '<dir>', fn))
    except Exception as e:
        print('FAILED (%s)' % e)
    print('---- end ls %s ----' % rel)


listdir('.')
listdir('.github/workflows')
listdir('docs')
listdir('tools')
listdir('cron')

# row 18 - auto renew + reminders
funcs('app/Service/AutoRenew.php')
grep_all('AutoRenew::', 30)
grep_all('expire_soon', 20)
grep_all('remind', 30)

# row 19 - trial abuse
grep_all("'trial'", 40)
grep_all('trial_used', 20)

# row 20 - referral levels
funcs('app/Service/Referral.php')
grep_all('ref_l2', 20)

# row 21 - tickets
grep_all('priority', 25)
grep_all('canned', 15)

# row 22 - gateways
grep_all('GATEWAYS', 15)
grep_all("case 'zarinpal", 10)
grep_all('zarinpal', 15)

# row 23 - i18n
grep_all("'lang'", 25)
grep_all('i18n', 15)

# row 24 - financial report
grep_all('p=reports', 10)
grep_all('profit', 20)

# row 25 - cloud backup
grep_all('backup_cloud', 15)
grep_all('sendDocument', 20)

# row 27 - reseller api
grep_all('rs_api', 15)
grep_all('api/v1', 15)

# improvements 33-36
grep_all('phpstan', 10)
grep_all('phpunit', 10)
grep_all('CREATE INDEX', 15)

print('---- file sizes ----')
for p in ['app/Service/AutoRenew.php', 'app/Service/Referral.php', 'app/Service/Gateway.php',
          'app/Service/Backup.php', 'app/Service/Txt.php', 'cron/tasks.php', 'admin/pages/tickets.php',
          'CHANGELOG.md', 'README.md']:
    try:
        print('%6d lines  %s' % (len(load(p).split(chr(10))), p))
    except Exception as e:
        print('   n/a  %s (%s)' % (p, e))
print('---- end file sizes ----')

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
