# -*- coding: utf-8 -*-
# fixed126 - row 16 finish: feature matrix for the new drivers
import io, os, re, sys, json, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed126').strip() or 'fixed126'

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


def rep_rx(path, pattern, fn, marker, expect=1, optional=False, flags=re.M):
    try:
        s = load(path)
    except Exception as e:
        ERRORS.append('%s: cannot read (%s)' % (path, e))
        print('ERROR: %s: cannot read (%s)' % (path, e))
        return
    if marker and marker in s:
        print('skip (already applied): %s / %s' % (path, marker))
        return
    rx = re.compile(pattern, flags)
    hits = rx.findall(s)
    if len(hits) != expect:
        msg = '%s: regex for %s matched %d times (want %d)' % (path, marker, len(hits), expect)
        if optional:
            WARN.append(msg)
            print('warn: ' + msg)
        else:
            ERRORS.append(msg)
            print('ERROR: ' + msg)
        return
    out = rx.sub(lambda m: fn(m), s, count=expect)
    CACHE[path] = out
    NEW[path] = out
    print('patched %s (%s)' % (path, marker))


def write_all():
    if ERRORS:
        print('ABORTED - anchors not found:')
        for e in ERRORS:
            print('  - ' + e)
        sys.exit(1)
    tmpdir = os.environ.get('TMPDIR') or '/tmp'
    tmp = os.path.join(tmpdir, 'syntax-check.php')
    lint = 'n/a'
    for path, text in NEW.items():
        if path.endswith('.php'):
            try:
                with io.open(tmp, 'w', encoding='utf-8') as fh:
                    fh.write(text)
                p = subprocess.run(['php', '-l', tmp], capture_output=True, text=True)
                if p.returncode != 0:
                    print('PHP LINT FAILED for %s:\n%s\n%s' % (path, p.stdout.strip(), p.stderr.strip()))
                    sys.exit(1)
                lint = 'on'
            except FileNotFoundError:
                lint = 'n/a'
        with io.open(os.path.join(ROOT, path), 'w', encoding='utf-8') as fh:
            fh.write(text)
        print('wrote %s' % path)
    print('php lint: %s' % lint)
    print('changed files: %d' % len(NEW))
    if WARN:
        print('warnings (optional patches skipped):')
        for w in WARN:
            print('  - ' + w)


XUI = 'app/Panel/Xui.php'
info(XUI)

# 1) revoke is available on marzban + marzneshin (hiddify has no revoke endpoint)
rep_rx(
    XUI,
    r"^            case 'revoke':   return \$this->pg !== null;$",
    lambda m: (
        "            /* 0.0.2 #row16 \u2014 \u0645\u0631\u0632\u0628\u0627\u0646 \u0648 \u0645\u0631\u0632\u0646\u0634\u06cc\u0646 \u0647\u0645 \u0644\u063a\u0648 \u0644\u06cc\u0646\u06a9 \u0627\u0634\u062a\u0631\u0627\u06a9 \u062f\u0627\u0631\u0646\u062f\u061b \u0647\u06cc\u062f\u06cc\u0641\u0627\u06cc \u0646\u062f\u0627\u0631\u062f */\n"
        "            case 'revoke':   return $this->pg !== null || ($this->mz !== null && !($this->mz instanceof Hiddify));"
    ),
    'instanceof Hiddify)',
)

write_all()

# ------------------------------------------------------------- checks
try:
    php_code = (
        'require "app/Panel/Xui.php"; require "app/Panel/Xui3.php"; require "app/Panel/Marzban.php"; '
        'require "app/Panel/Marzneshin.php"; require "app/Panel/PasarGuard.php"; require "app/Panel/Hiddify.php"; '
        'echo "classes ok", PHP_EOL;'
    )
    p = subprocess.run(['php', '-r', php_code], cwd=ROOT, capture_output=True, text=True)
    print('class check rc=%d out=%s' % (p.returncode, p.stdout.strip()))
    if p.returncode != 0:
        print('class check stderr: %s' % p.stderr.strip()[:800])
except FileNotFoundError:
    print('class check: n/a (no php)')

SANITY = [
    (XUI, "case 'revoke':   return $this->pg !== null || ($this->mz !== null && !($this->mz instanceof Hiddify));"),
    (XUI, 'new Marzneshin($panel)'),
    (XUI, 'new Hiddify($panel)'),
]
for path, needle in SANITY:
    try:
        ok = needle in load(path)
    except Exception:
        ok = False
    print('sanity %s / %s : %s' % (path, needle, 'ok' if ok else 'MISSING'))

# ------------------------------------------------------------- version + changelog
TXT = '\u0644\u063a\u0648 \u0644\u06cc\u0646\u06a9 \u0627\u0634\u062a\u0631\u0627\u06a9 \u0628\u0631\u0627\u06cc \u067e\u0646\u0644\u200c\u0647\u0627\u06cc \u0645\u0631\u0632\u0628\u0627\u0646 \u0648 \u0645\u0631\u0632\u0646\u0634\u06cc\u0646 \u0647\u0645 \u0641\u0639\u0627\u0644 \u0634\u062f'
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
cl = vj.get('changelog')
added = False
if isinstance(cl, list) and (not cl or isinstance(cl[0], str)):
    if TXT not in cl:
        cl.insert(0, TXT)
        added = True
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('changelog: %s' % ('entry added' if added else 'skipped'))
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
