# -*- coding: utf-8 -*-
# fixed144 - mirror the 0.0.2 build notes into CHANGELOG.md (housekeeping)
# and dump what the next batch needs (migration runner, trial flow, helpers).
import io, os, re, sys, json, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed144').strip() or 'fixed144'

CACHE = {}
NEW = set()
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json', '.yml', '.sh', '.md')


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


def grep_all(needle, limit=30, only=None):
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


def funcs(path, limit=200):
    print('---- functions: %s ----' % path)
    n = 0
    try:
        for i, ln in enumerate(load(path).split(chr(10)), 1):
            if 'function ' in ln:
                print('%5d %s' % (i, ln.strip()[:140]))
                n += 1
                if n >= limit:
                    break
    except Exception as e:
        print('FAILED (%s)' % e)
    print('---- end functions ----')


# ---------------------- 1) CHANGELOG sync ----------------------
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)

CH = 'CHANGELOG.md'
MARK = '<!-- #changelog-sync -->'
src = load(CH)
if MARK in src:
    print('changelog: already synced (marker present)')
else:
    entries = [e.strip() for e in (vj.get('changelog') or []) if isinstance(e, str) and e.strip()]
    seen = set()
    uniq = []
    for e in entries:
        if e not in seen:
            seen.add(e)
            uniq.append(e)
    fresh = [e for e in uniq if e not in src]
    lines = src.split(chr(10))
    idx = None
    for i, ln in enumerate(lines):
        if ln.startswith('## '):
            idx = i
            break
    if idx is None:
        print('changelog: no "## " heading found - skipped')
    elif not fresh:
        print('changelog: nothing new to add')
    else:
        block = [MARK, '## fixed81 \u2192 ' + BUILD + ' \u2014 \u0686\u0631\u062e\u0647\u0654 \u06f0.\u06f0.\u06f2 (\u062f\u0631 \u062d\u0627\u0644 \u062a\u0648\u0633\u0639\u0647)', '']
        block += ['- ' + e for e in fresh]
        block += ['']
        lines[idx:idx] = block
        CACHE[CH] = chr(10).join(lines)
        NEW.add(CH)
        print('changelog: inserted %d entries before line %d' % (len(fresh), idx + 1))

for p in sorted(NEW):
    with io.open(os.path.join(ROOT, p), 'w', encoding='utf-8') as fh:
        fh.write(CACHE[p])
    print('wrote ' + p)
print('changed files: %d' % len(NEW))

# ---------------------- 2) recon for next batch ----------------------
funcs('app/Service/Migrate.php')
dump_find('migrate_sql', 'app/Service/Migrate.php', '.sql', 8, 45)

print('===== trial / test service flow =====')
grep_all('test_volume', 25)
grep_all('testInbound', 15)
grep_all("'test", 30)
grep_all('free_test', 20)

print('===== helpers =====')
grep_all('function rnd(', 5)
grep_all('function money(', 5)
grep_all('function en_num(', 5)
grep_all('function app_log(', 5)

print('===== gateways / backup =====')
funcs('app/Service/Gateway.php', 60)
funcs('app/Service/Backup.php', 60)

print('===== tickets schema =====')
dump_find('tickets_tbl', 'database/schema.sql', 'tickets (', 1, 22)

# ---------------------- 3) version bump ----------------------
old_build = vj.get('build')
vj['build'] = BUILD
note = '\u0645\u0633\u062a\u0646\u062f CHANGELOG \u0628\u0627 \u06cc\u0627\u062f\u062f\u0627\u0634\u062a\u200c\u0647\u0627\u06cc \u0628\u06cc\u0644\u062f\u0647\u0627\u06cc \u06f0.\u06f0.\u06f2 \u0647\u0645\u200c\u06af\u0627\u0645 \u0634\u062f'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog json: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
