# -*- coding: utf-8 -*-
# fixed163 - recon only: reply-keyboard text matching pipeline.
import io, os, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed163').strip() or 'fixed163'

CACHE = {}
NEW = set()
ERRORS = []
WARN = []
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json')

BOT = 'app/Bot/Bot.php'
BTN = 'app/Service/Btn.php'


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


def grep_all(needle, limit=20, only=None, ci=False):
    print('---- grep: %s ----' % needle)
    nl = needle.lower()
    n = 0
    for p in files_all():
        if only and not p.startswith(only):
            continue
        try:
            lines = load(p).split(chr(10))
        except Exception:
            continue
        for i, ln in enumerate(lines, 1):
            hit = (nl in ln.lower()) if ci else (needle in ln)
            if hit:
                s = ln.strip()
                if len(s) > 170:
                    s = s[:170] + ' ...'
                print('%s:%d: %s' % (p, i, s))
                n += 1
                if n >= limit:
                    print('---- end grep: %s (truncated) ----' % needle)
                    return
    print('---- end grep: %s (%d) ----' % (needle, n))


print('changed files: 0 (recon only)')

print('===== SEED more/rest =====')
dump('seed_rest', BTN, 150, 262)
print('===== normalize / all / save =====')
dump('btn_norm', BTN, 377, 452)
print('===== withVirtual / labels / match =====')
dump('btn_match', BTN, 741, 800)
print('===== visible + replyRows =====')
dump('btn_vis', BTN, 691, 740)
dump('btn_rows', BTN, 852, 900)
print('===== syncBuiltins =====')
dump('btn_sync', BTN, 966, 1010)
print('===== autoScan / scanHandlers =====')
dump('btn_scan', BTN, 1446, 1520)
dump('btn_auto', BTN, 1583, 1615)
print('===== bot text dispatch =====')
dump('bot_disp', BOT, 150, 230)
print('===== who calls sync/seed/autoFix/autoScan =====')
grep_all('syncBuiltins(', 12)
grep_all('seedMenus(', 12)
grep_all('autoFix(', 12)
grep_all('autoScan(', 12)

vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
print('build stays: %s' % vj.get('build'))
print('exit: 0')
