# -*- coding: utf-8 -*-
# fixed125 - row 16: register Marzneshin + Hiddify drivers in the Xui facade
import io, os, re, sys, json, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed125').strip() or 'fixed125'

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


def php_lint_file(path):
    try:
        p = subprocess.run(['php', '-l', os.path.join(ROOT, path)], capture_output=True, text=True)
    except Exception as e:
        print('php lint %s: n/a (%s)' % (path, e))
        return True
    if p.returncode != 0:
        print('PHP LINT FAILED %s:\n%s\n%s' % (path, p.stdout.strip(), p.stderr.strip()))
        return False
    print('php lint %s: ok' % path)
    return True


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


# ---------------------------------------------------------------- recon
for p in ('app/Panel/Marzneshin.php', 'app/Panel/Hiddify.php'):
    info(p)
    try:
        tail = load(p).rstrip('\n').split('\n')[-3:]
        print('tail %s: %s' % (p, ' | '.join(t.strip() for t in tail)))
    except Exception as e:
        print('tail %s: cannot read (%s)' % (p, e))
        ERRORS.append('missing %s' % p)

dump('xui_supports', 'app/Panel/Xui.php', 960, 1000)

# ---------------------------------------------------------------- patches
XUI = 'app/Panel/Xui.php'
HID = 'app/Panel/Hiddify.php'

# 1) docblock of the shared marzban-family driver property
rep_rx(
    XUI,
    r"^    /\*\* \u062f\u0631\u0627\u06cc\u0648\u0631 \u0645\u0631\u0632\u0628\u0627\u0646\u061b [^\n]*\*/\n    private \?Marzban \$mz = null;$",
    lambda m: (
        '    /** \u062f\u0631\u0627\u06cc\u0648\u0631 \u062e\u0627\u0646\u0648\u0627\u062f\u0647\u0654 \u0645\u0631\u0632\u0628\u0627\u0646 (marzban / marzneshin / hiddify)\u061b \u0627\u06af\u0631 \u0646\u0648\u0639 \u067e\u0646\u0644 \u06cc\u06a9\u06cc \u0627\u0632 \u0627\u06cc\u0646\u200c\u0647\u0627 \u0628\u0627\u0634\u062f \u0647\u0645\u0647\u0654 \u062f\u0631\u062e\u0648\u0627\u0633\u062a\u200c\u0647\u0627 \u0628\u0647 \u0622\u0646 \u0633\u067e\u0631\u062f\u0647 \u0645\u06cc\u200c\u0634\u0648\u062f */\n'
        '    private ?Marzban $mz = null;'
    ),
    'row16-doc',
)

# 2) selectable panel types
rep_rx(
    XUI,
    r"^        'pasarguard' => '\u067e\u0627\u0633\u0627\u0631\u06af\u0627\u0631\u062f \(PasarGuard\)',$",
    lambda m: (
        m.group(0) + '\n'
        + "        'marzneshin' => '\u0645\u0631\u0632\u0646\u0634\u06cc\u0646 (Marzneshin)',\n"
        + "        'hiddify'    => '\u0647\u06cc\u062f\u06cc\u0641\u0627\u06cc (Hiddify)',"
    ),
    "'marzneshin' => '",
)

# 3) aliases
rep_rx(
    XUI,
    r"^        'pg'         => 'pasarguard',$",
    lambda m: (
        m.group(0) + '\n'
        + "        'marznashin' => 'marzneshin',\n"
        + "        'marz-neshin'=> 'marzneshin',\n"
        + "        'marzneshin-panel' => 'marzneshin',\n"
        + "        'hidify'     => 'hiddify',\n"
        + "        'hiddifi'    => 'hiddify',\n"
        + "        'hiddify-manager'  => 'hiddify',"
    ),
    "'marznashin' => 'marzneshin'",
)

# 4) form notes for the new types
rep_rx(
    XUI,
    r"^        'pasarguard' => '[^\n]*docs\.pasarguard\.org',$",
    lambda m: (
        m.group(0) + '\n'
        + "        'marzneshin' => '\u0645\u0631\u0632\u0646\u0634\u06cc\u0646 (Marzneshin) \u2013 \u0646\u0633\u0644 \u062c\u062f\u06cc\u062f \u0645\u0631\u0632\u0628\u0627\u0646: \u0648\u0631\u0648\u062f \u0628\u0627 \u06cc\u0648\u0632\u0631/\u067e\u0633\u0648\u0631\u062f \u0627\u062f\u0645\u06cc\u0646. \u06a9\u0627\u0631\u0628\u0631 \u0628\u0627\u06cc\u062f \u0639\u0636\u0648 \u06cc\u06a9 \u00ab\u0633\u0631\u0648\u06cc\u0633\u00bb (Service) \u0628\u0627\u0634\u062f\u061b \u0634\u0646\u0627\u0633\u0647\u0654 \u0633\u0631\u0648\u06cc\u0633\u200c\u0647\u0627 \u0631\u0627 \u062f\u0631 \u0641\u06cc\u0644\u062f \u00ab\u06a9\u062f \u0627\u06cc\u0646\u0628\u0627\u0646\u062f\u0647\u0627\u00bb \u0628\u0646\u0648\u06cc\u0633\u06cc\u062f \u06cc\u0627 \u062e\u0627\u0644\u06cc \u0628\u06af\u0630\u0627\u0631\u06cc\u062f \u062a\u0627 \u0647\u0645\u0647\u0654 \u0633\u0631\u0648\u06cc\u0633\u200c\u0647\u0627 \u0627\u0633\u062a\u0641\u0627\u062f\u0647 \u0634\u0648\u062f. \u0644\u06cc\u0646\u06a9 \u0627\u0634\u062a\u0631\u0627\u06a9 \u0631\u0627 \u062e\u0648\u062f \u067e\u0646\u0644 \u0645\u06cc\u200c\u062f\u0647\u062f. \u06af\u06cc\u062a\u200c\u0647\u0627\u0628: github.com/marzneshin/marzneshin',\n"
        + "        'hiddify'    => '\u0647\u06cc\u062f\u06cc\u0641\u0627\u06cc (Hiddify Manager) \u2013 \u0644\u0627\u06af\u06cc\u0646 \u06cc\u0648\u0632\u0631/\u067e\u0633\u0648\u0631\u062f \u0646\u062f\u0627\u0631\u062f: \u062f\u0631 \u0641\u06cc\u0644\u062f \u00ab\u0645\u0633\u06cc\u0631 \u0648\u0628\u00bb \u0645\u0633\u06cc\u0631 \u067e\u0631\u0648\u06a9\u0633\u06cc (proxy path) \u067e\u0646\u0644 \u0631\u0627 \u0628\u0646\u0648\u06cc\u0633\u06cc\u062f \u0648 \u062f\u0631 \u0641\u06cc\u0644\u062f \u0631\u0645\u0632/\u062a\u0648\u06a9\u0646\u060c \u06a9\u0644\u06cc\u062f API \u0627\u062f\u0645\u06cc\u0646 (Hiddify-API-Key) \u0631\u0627 \u0628\u06af\u0630\u0627\u0631\u06cc\u062f. \u06a9\u0627\u0631\u0628\u0631\u0627\u0646 \u0628\u0627 UUID \u0633\u0627\u062e\u062a\u0647 \u0645\u06cc\u200c\u0634\u0648\u0646\u062f \u0648 \u0644\u06cc\u0646\u06a9 \u0627\u0634\u062a\u0631\u0627\u06a9 \u0627\u0632 \u0647\u0645\u06cc\u0646 UUID \u0633\u0627\u062e\u062a\u0647 \u0645\u06cc\u200c\u0634\u0648\u062f\u061b \u06a9\u062f \u0627\u06cc\u0646\u0628\u0627\u0646\u062f \u0644\u0627\u0632\u0645 \u0646\u06cc\u0633\u062a. \u0645\u0633\u062a\u0646\u062f\u0627\u062a: hiddify.com',"
    ),
    "'marzneshin' => '\u0645\u0631\u0632\u0646\u0634\u06cc\u0646 (Marzneshin) \u2013",
)

# 5) constructor: pick the right driver
rep_rx(
    XUI,
    r"^        if \(\$type === 'marzban'\) \{\n            \$this->mz = new Marzban\(\$panel\);\n        \} elseif \(\$type === 'pasarguard'\) \{$",
    lambda m: (
        "        if ($type === 'marzban') {\n"
        '            $this->mz = new Marzban($panel);\n'
        "        } elseif ($type === 'marzneshin') {\n"
        '            /* 0.0.2 #row16 \u2014 \u0645\u0631\u0632\u0646\u0634\u06cc\u0646 \u0646\u0633\u0644 \u062c\u062f\u06cc\u062f \u0645\u0631\u0632\u0628\u0627\u0646 \u0627\u0633\u062a \u0648 \u0647\u0645\u0627\u0646 \u0642\u0631\u0627\u0631\u062f\u0627\u062f \u0631\u0627 \u067e\u06cc\u0627\u062f\u0647 \u0645\u06cc\u200c\u06a9\u0646\u062f */\n'
        '            $this->mz = new Marzneshin($panel);\n'
        "        } elseif ($type === 'hiddify') {\n"
        '            /* 0.0.2 #row16 \u2014 \u0647\u06cc\u062f\u06cc\u0641\u0627\u06cc \u0628\u0627 \u06a9\u0644\u06cc\u062f API \u06a9\u0627\u0631 \u0645\u06cc\u200c\u06a9\u0646\u062f \u0648 \u0644\u06cc\u0646\u06a9 \u0627\u0634\u062a\u0631\u0627\u06a9 \u0631\u0627 \u062e\u0648\u062f \u067e\u0646\u0644 \u0645\u06cc\u200c\u062f\u0647\u062f */\n'
        '            $this->mz = new Hiddify($panel);\n'
        "        } elseif ($type === 'pasarguard') {"
    ),
    'new Marzneshin($panel)',
)

# 6) small helpers for the admin UI and reports
rep_rx(
    XUI,
    r"^    public function marzban\(\): \?Marzban \{ return \$this->mz; \}$",
    lambda m: (
        m.group(0) + '\n\n'
        + '    /** 0.0.2 #row16 \u2014 \u0622\u06cc\u0627 \u0627\u06cc\u0646 \u067e\u0646\u0644 \u0645\u0631\u0632\u0646\u0634\u06cc\u0646 \u0627\u0633\u062a\u061f */\n'
        + '    public function isMarzneshin(): bool { return $this->mz instanceof Marzneshin; }\n\n'
        + '    /** 0.0.2 #row16 \u2014 \u0622\u06cc\u0627 \u0627\u06cc\u0646 \u067e\u0646\u0644 \u0647\u06cc\u062f\u06cc\u0641\u0627\u06cc \u0627\u0633\u062a\u061f */\n'
        + '    public function isHiddify(): bool { return $this->mz instanceof Hiddify; }'
    ),
    'public function isMarzneshin()',
)

# 7) hiddify: avoid touching a missing enable key
rep_rx(
    HID,
    r"^        \$on   = array_key_exists\('enable', \$u\) \? \(bool\)\$u\['enable'\] : true;\n        if \(\$on && array_key_exists\('is_active', \$u\)\) \$on = \(bool\)\$u\['is_active'\] \|\| \(bool\)\$u\['enable'\];$",
    lambda m: (
        "        $on   = array_key_exists('enable', $u)\n"
        '            ? (bool)$u[\'enable\']\n'
        "            : (array_key_exists('is_active', $u) ? (bool)$u['is_active'] : true);"
    ),
    'row16-hd-enable',
    optional=True,
)

write_all()

# ---------------------------------------------------------------- class load check
try:
    php_code = (
        'require "app/Panel/Xui.php"; require "app/Panel/Xui3.php"; require "app/Panel/Marzban.php"; '
        'require "app/Panel/Marzneshin.php"; require "app/Panel/PasarGuard.php"; require "app/Panel/Hiddify.php"; '
        'echo "classes ok ", (new ReflectionClass("Marzneshin"))->getParentClass()->getName(), " ", '
        '(new ReflectionClass("Hiddify"))->getParentClass()->getName(), PHP_EOL;'
    )
    p = subprocess.run(['php', '-r', php_code], cwd=ROOT, capture_output=True, text=True)
    print('class check rc=%d out=%s' % (p.returncode, p.stdout.strip()))
    if p.returncode != 0:
        print('class check stderr: %s' % p.stderr.strip()[:800])
except FileNotFoundError:
    print('class check: n/a (no php)')

php_lint_file('app/Panel/Marzneshin.php')
php_lint_file('app/Panel/Hiddify.php')

# ---------------------------------------------------------------- sanity
SANITY = [
    ('app/Panel/Marzneshin.php', 'class Marzneshin extends Marzban'),
    ('app/Panel/Marzneshin.php', "'/api/admins/token'"),
    ('app/Panel/Hiddify.php', 'class Hiddify extends Marzban'),
    ('app/Panel/Hiddify.php', 'Hiddify-API-Key: '),
    (XUI, "'marzneshin' => '\u0645\u0631\u0632\u0646\u0634\u06cc\u0646 (Marzneshin)',"),
    (XUI, "'hiddify'    => '\u0647\u06cc\u062f\u06cc\u0641\u0627\u06cc (Hiddify)',"),
    (XUI, "'marznashin' => 'marzneshin',"),
    (XUI, 'new Marzneshin($panel)'),
    (XUI, 'new Hiddify($panel)'),
    (XUI, 'public function isMarzneshin(): bool'),
    (XUI, 'public function isHiddify(): bool'),
    (XUI, '0.0.2 #row16'),
]
for path, needle in SANITY:
    try:
        ok = needle in load(path)
    except Exception:
        ok = False
    print('sanity %s / %s : %s' % (path, needle, 'ok' if ok else 'MISSING'))

# ---------------------------------------------------------------- version + changelog
TXT = '\u067e\u0634\u062a\u06cc\u0628\u0627\u0646\u06cc \u0627\u0632 \u067e\u0646\u0644\u200c\u0647\u0627\u06cc \u0645\u0631\u0632\u0646\u0634\u06cc\u0646 (Marzneshin) \u0648 \u0647\u06cc\u062f\u06cc\u0641\u0627\u06cc (Hiddify)'
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
