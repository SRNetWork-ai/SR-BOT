# -*- coding: utf-8 -*-
# fixed141 - the panel now returns a real happ://crypt5 link, so the manual
# "copy this subscription URL" fallback added in fixed139 only leaks the raw
# sub address (and lets the customer bypass HWID). Remove it.
import io, os, re, sys, json, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed141').strip() or 'fixed141'

CACHE = {}
NEW = set()
ERRORS = []
WARN = []


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8') as fh:
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
        print('%5d %s' % (i, lines[i - 1]))
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


def rep_rx(path, pattern, fn, marker, expect=1, optional=False, flags=re.M):
    try:
        src = load(path)
    except Exception as e:
        msg = '%s: cannot read (%s)' % (path, e)
        (WARN if optional else ERRORS).append(msg)
        print(('warn: ' if optional else 'ERROR: ') + msg)
        return
    if marker in src:
        print('skip (already applied): %s / %s' % (path, marker))
        return
    rx = re.compile(pattern, flags)
    n = len(rx.findall(src))
    if n != expect:
        msg = '%s: regex for %s matched %d times (want %d)' % (path, marker, n, expect)
        if optional:
            WARN.append(msg)
            print('warn: ' + msg)
            return
        ERRORS.append(msg)
        print('ERROR: ' + msg)
        return
    CACHE[path] = rx.sub(fn, src, count=expect)
    NEW.add(path)
    print('patched: %s / %s (%d)' % (path, marker, expect))


def write_all():
    if ERRORS:
        print('ABORTED - anchors not found:')
        for e in ERRORS:
            print(' - ' + e)
        sys.exit(1)
    for p in sorted(NEW):
        with io.open(os.path.join(ROOT, p), 'w', encoding='utf-8') as fh:
            fh.write(CACHE[p])
        print('wrote ' + p)
    php = None
    try:
        r = subprocess.run(['php', '-v'], capture_output=True)
        if r.returncode == 0:
            php = 'php'
    except Exception:
        php = None
    print('php lint: %s' % ('on' if php else 'n/a'))
    if php:
        for p in sorted(NEW):
            if not p.endswith('.php'):
                continue
            r = subprocess.run([php, '-l', os.path.join(ROOT, p)], capture_output=True)
            if r.returncode != 0:
                print('PHP LINT FAILED: ' + p)
                print(r.stdout.decode('utf-8', 'replace'))
                print(r.stderr.decode('utf-8', 'replace'))
                sys.exit(1)
    print('changed files: %d' % len(NEW))
    if WARN:
        print('warnings (optional patches skipped):')
        for w in WARN:
            print(' - ' + w)


BOT = 'app/Bot/Bot.php'
X3 = 'app/Panel/Xui3.php'

OFF = "\u0622\u062f\u0631\u0633 \u062e\u0627\u0645 \u0627\u0634\u062a\u0631\u0627\u06a9 \u0639\u0645\u062f\u0627\u064b \u0646\u0645\u0627\u06cc\u0634 \u062f\u0627\u062f\u0647 \u0646\u0645\u06cc\u200c\u0634\u0648\u062f \u062a\u0627 \u0641\u0642\u0637 \u0644\u06cc\u0646\u06a9 \u0631\u0645\u0632\u0646\u06af\u0627\u0631\u06cc\u200c\u0634\u062f\u0647\u0654 Happ \u0628\u0647 \u0645\u0634\u062a\u0631\u06cc \u0628\u0631\u0633\u062f \u0648 \u0645\u062d\u062f\u0648\u062f\u06cc\u062a \u0647\u0627\u0631\u062f\u0648\u06cc\u0631 \u062f\u0648\u0631 \u0632\u062f\u0647 \u0646\u0634\u0648\u062f"

rep_rx(
    BOT,
    r"\n([ \t]*)/\* 0\.0\.2 #happ-plain:[^\n]*\n.*?\. '</code>' \. \"\\n\";\n[ \t]*\}\n",
    lambda m: '\n' + m.group(1) + '/* 0.0.2 #happ-plain-off: ' + OFF + ' */\n',
    '#happ-plain-off',
    flags=re.S,
)

write_all()

# ================================ SANITY ================================
SANITY = [
    (BOT, '#happ-plain-off'),
    (X3, '#happ-post'),
    (X3, "'encryptedLink', 'happLink', 'link', 'url', 'happ'"),
]
for p, needle in SANITY:
    try:
        ok = needle in load(p)
    except Exception:
        ok = False
    print('sanity %s / %s : %s' % (p, needle, 'ok' if ok else 'MISSING'))

ABSENT = [
    (BOT, 'Add subscription:'),
    (BOT, '#happ-plain:'),
    (BOT, '$psub'),
]
for p, needle in ABSENT:
    try:
        gone = needle not in load(p)
    except Exception:
        gone = False
    print('absent %s / %s : %s' % (p, needle, 'ok' if gone else 'STILL PRESENT'))

dump_find('happ_view', BOT, '#happ-plain-off', 34, 8)

# ============================== version bump ==============================
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
notes = [
    'در صفحهٔ «افزودن به Happ» دیگر آدرس خام اشتراک نمایش داده نمی‌شود؛ فقط لینک رمزنگاری‌شدهٔ پنل تحویل می‌شود',
]
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = (notes + cl)[:60]
    print('changelog: entry added')
else:
    print('changelog: skipped (no list)')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
