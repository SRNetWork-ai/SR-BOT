# -*- coding: utf-8 -*-
# fixed164 - recon only: runButton switch + Kb constants + state/verify gates.
import io, os, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()

CACHE = {}
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json')

BOT = 'app/Bot/Bot.php'
BTN = 'app/Service/Btn.php'
KB = 'app/Bot/Kb.php'


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


def dump_find(tag, path, needle, before=3, after=30):
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

print('===== runButton switch =====')
dump_find('run', BOT, 'function runButton(', 2, 140)
print('===== submenu opener =====')
dump('bot_menu', BOT, 415, 470)
print('===== Kb constants =====')
dump('kb_head', KB, 1, 120)
print('===== Kb::isMenuButton =====')
dump_find('kb_ismenu', KB, 'function isMenuButton', 2, 40)
print('===== handleState head =====')
dump_find('bot_state', BOT, 'function handleState', 2, 55)
print('===== verifyGate =====')
dump_find('bot_vg', BOT, 'function verifyGate', 2, 40)
print('===== section handlers =====')
grep_all('function section', 30, only=BOT)
print('===== Btn::meta / guessMeta / scanReport / autoFix =====')
dump_find('btn_meta', BTN, 'public static function meta(', 2, 26)
dump_find('btn_report', BTN, 'function scanReport', 2, 42)
dump('btn_fix', BTN, 1249, 1300)
print('exit: 0')
