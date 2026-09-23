# -*- coding: utf-8 -*-
# fixed130 - per-product HWID (limitHwid) + new delivery mode "happ" (Happ deep link only)
import io, os, re, sys, json, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed130').strip() or 'fixed130'

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


def dump_find(tag, path, pattern, before=4, after=20, limit=1):
    try:
        lines = load(path).split('\n')
    except Exception as e:
        print('dump_find %s: cannot read %s (%s)' % (tag, path, e))
        return
    rx = re.compile(pattern)
    hits = [i + 1 for i, ln in enumerate(lines) if rx.search(ln)]
    print('---- find %s : %s -> %d hits %s ----' % (tag, pattern, len(hits), hits[:40]))
    for n in hits[:limit]:
        dump('%s@%d' % (tag, n), path, n - before, n + after)


def grep_lines(tag, path, pattern, limit=40):
    try:
        lines = load(path).split('\n')
    except Exception as e:
        print('grep %s: cannot read %s (%s)' % (tag, path, e))
        return
    rx = re.compile(pattern)
    n = 0
    print('---- grep %s : %s ----' % (tag, pattern))
    for i, ln in enumerate(lines):
        if rx.search(ln):
            n += 1
            if n <= limit:
                print('%5d %s' % (i + 1, ln.strip()[:170]))
    print('---- grep %s : %d hits ----' % (tag, n))


def rep_rx(path, pattern, fn, marker, expect=1, optional=False, flags=re.M):
    src = NEW.get(path, load(path))
    if marker and marker in src:
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
    NEW[path] = rx.sub(fn, src)
    print('patched %s / %s' % (path, marker))


def write_all():
    if ERRORS:
        print('ABORTED - anchors not found:')
        for e in ERRORS:
            print(' - ' + e)
        sys.exit(1)
    php = None
    try:
        subprocess.run(['php', '-v'], capture_output=True)
        php = 'php'
    except Exception:
        php = None
    tmp = os.path.join(os.environ.get('TMPDIR', '/tmp'), 'syntax-check.php')
    for p, s in NEW.items():
        if php and p.endswith('.php'):
            with io.open(tmp, 'w', encoding='utf-8') as fh:
                fh.write(s)
            r = subprocess.run([php, '-l', tmp], capture_output=True, text=True)
            if r.returncode != 0:
                print('php lint FAILED for %s:' % p)
                print((r.stdout or '') + (r.stderr or ''))
                sys.exit(1)
        with io.open(os.path.join(ROOT, p), 'w', encoding='utf-8') as fh:
            fh.write(s)
        print('wrote %s' % p)
    print('php lint: %s' % ('on' if php else 'n/a'))
    print('changed files: %d' % len(NEW))
    if WARN:
        print('warnings (optional patches skipped):')
        for w in WARN:
            print(' - ' + w)


# =============================== recon for the next build ===============================
dump_find('svc_renew', 'app/Service/Svc.php', r'public static function renew', 4, 70, 1)
grep_lines('svc_update', 'app/Service/Svc.php', r'updateClient|setDeviceLimit|isXui3|xui3\(\)', 30)
grep_lines('ma_ui', 'miniapp/index.php', r"can_links|can_devs|data-links|data-devs|s\.configs|s\.sub\b", 40)

# =============================== patches ===============================

# --- 1) Svc::DELIVER gains the "happ" mode -------------------------------------------
HAPP_ENTRY = (
    "        /* 0.0.2 #happ-only-mode: فقط دیپ\u200cلینک اختصاصی Happ */\n"
    "        'happ'   => ['فقط لینک هپ (Happ)', 'فقط دیپ\u200cلینک اختصاصی Happ؛ بدون لینک ساب و کانفیگ مستقیم', '⚡'],\n"
)
rep_rx(
    'app/Service/Svc.php',
    r"\n    \];\n\n(    /\*\*[^\n]*\*/\n    public static function deliverDefault\(\): string)",
    lambda m: "\n" + HAPP_ENTRY + "    ];\n\n" + m.group(1),
    '#happ-only-mode',
)

# --- 2) wantsSub / wantsCfg respect the new mode + helpers ---------------------------
rep_rx(
    'app/Service/Svc.php',
    r"        return \$m !== 'config';",
    lambda m: "        return $m !== 'config' && $m !== 'happ'; /* 0.0.2 #happ-only-sub */",
    '#happ-only-sub',
)

WANTS_CFG = r"""    public static function wantsCfg(string $m): bool
    {
        return $m !== 'sub' && $m !== 'happ'; /* 0.0.2 #happ-only-cfg */
    }

    /** آیا فقط دیپ‌لینک اختصاصی Happ تحویل شود؟ */
    public static function wantsHapp(string $m): bool
    {
        return $m === 'happ';
    }

    /** دیپ‌لینک Happ سرویس؛ رشتهٔ خالی یعنی در دسترس نیست */
    public static function happLink(array $s): string
    {
        if (!class_exists('Links')) return '';
        try {
            return trim((string)Links::happ($s));
        } catch (Throwable $e) {
            return '';
        }
    }"""

rep_rx(
    'app/Service/Svc.php',
    r"    public static function wantsCfg\(string \$m\): bool\n    \{\n        return \$m !== 'sub';\n    \}",
    lambda m: WANTS_CFG,
    '#happ-only-cfg',
)

# --- 3) bot delivery message ----------------------------------------------------------
DELIVER_FLAGS = r"""        /* 0.0.2 #happ-only-bot: محصول «فقط لینک هپ» */
        $happ = Svc::wantsHapp($mode) ? Svc::happLink($service) : '';
        $wantSub = Svc::wantsSub($mode) && $sub !== '';
        $wantCfg = Svc::wantsCfg($mode) && $n > 0;
        if ($happ !== '') { $wantSub = false; $wantCfg = false; }
        if (!$wantSub && !$wantCfg && $happ === '') {
            $wantSub = ($sub !== '');
            $wantCfg = ($n > 0);
        }"""

rep_rx(
    'app/Bot/Bot.php',
    r"        \$wantSub = Svc::wantsSub\(\$mode\) && \$sub !== '';\n"
    r"        \$wantCfg = Svc::wantsCfg\(\$mode\) && \$n > 0;\n"
    r"        if \(!\$wantSub && !\$wantCfg\) \{\n"
    r"            \$wantSub = \(\$sub !== ''\);\n"
    r"            \$wantCfg = \(\$n > 0\);\n"
    r"        \}",
    lambda m: DELIVER_FLAGS,
    '#happ-only-bot',
)

HAPP_BLOCK = r"""        if ($happ !== '') { /* 0.0.2 #happ-only-txt */
            $txt .= "\n<code>────────────────</code>\n";
            $txt .= "⚡ <b>لینک اختصاصی Happ</b>\n";
            $txt .= "<i>تنظیمات سرور (محدودیت دستگاه، مسیریابی و…) روی همین لینک اعمال می‌شود.</i>\n";
            $txt .= '<code>' . h($happ) . "</code>\n";
        }

        if ($wantCfg && !$wantSub) {"""

rep_rx(
    'app/Bot/Bot.php',
    r"        if \(\$wantCfg && !\$wantSub\) \{",
    lambda m: HAPP_BLOCK,
    '#happ-only-txt',
)

TIP = r"""        $txt .= ($wantSub || $happ !== '') /* 0.0.2 #happ-only-tip */
            ? "۱) روی لینک بالا بزنید تا کپی شود.\n"
            : "۱) روی کانفیگ بزنید تا کپی شود.\n";"""

rep_rx(
    'app/Bot/Bot.php',
    r"        \$txt \.= \$wantSub\n            \? \"[^\"]*\"\n            : \"[^\"]*\";",
    lambda m: TIP,
    '#happ-only-tip',
)

rep_rx(
    'app/Bot/Bot.php',
    r"            if \(\$wantCfg\) \$br\[\] = Tg::btn\('[^']*', 'svccfg:' \. \$sid\);",
    lambda m: m.group(0) + "\n            if ($happ !== '') $br[] = Tg::btn('⚡ افزودن به Happ', 'svchapp:' . $sid); /* 0.0.2 #happ-only-btn */",
    '#happ-only-btn',
)

# --- 4) service card buttons ----------------------------------------------------------
VIEW_TOP = r"""        $mode = Svc::deliverMode($s);
        /* 0.0.2 #happ-only-view: محصول «فقط لینک هپ» */
        $hOnly = Svc::wantsHapp($mode) && class_exists('Links') && Links::supported($s);
        $top  = [];
        if ($hOnly) $top[] = Tg::btn('⚡ افزودن به Happ', 'svchapp:' . $id);"""

rep_rx(
    'app/Bot/Bot.php',
    r"        \$mode = Svc::deliverMode\(\$s\);\n        \$top  = \[\];",
    lambda m: VIEW_TOP,
    '#happ-only-view',
)

rep_rx(
    'app/Bot/Bot.php',
    r"(#happ-btn[^\n]*\n\s*)if \(class_exists\('Links'\) && Links::supported\(\$s\)\) \{",
    lambda m: m.group(1) + "if (!$hOnly && class_exists('Links') && Links::supported($s)) {",
    "!$hOnly && class_exists('Links')",
)

# --- 5) mini app flags ----------------------------------------------------------------
MA_FLAGS = r"""        /* 0.0.2 #happ-only-ma: محصول «فقط لینک هپ» */
        'deliver'    => class_exists('Svc') && method_exists('Svc', 'deliverMode') ? Svc::deliverMode($s) : '',
        'happ_only'  => class_exists('Svc') && method_exists('Svc', 'wantsHapp')
            && Svc::wantsHapp(Svc::deliverMode($s))
            && class_exists('Links') && Links::supported($s),"""

rep_rx(
    'miniapp/api.php',
    r"        'can_links'  => class_exists\('Links'\) && Links::supported\(\$s\),",
    lambda m: m.group(0) + "\n" + MA_FLAGS,
    '#happ-only-ma',
)

# --- 6) admin product form: HWID wording + safe counter -------------------------------
rep_rx(
    'admin/pages/products.php',
    r"<label>[^<]*\(Device limit\)</label>",
    lambda m: "<label>محدودیت دستگاه / هاردویر (HWID)</label>",
    '(HWID)</label>',
)

HINT = ("۰ = بدون محدودیت — روی پنل نسل جدید سنایی به\u200cصورت محدودیت هاردویر (limitHwid) "
        "روی همان اکانت و لینک هپ اعمال می\u200cشود؛ روی VPN-UI هم پشتیبانی می\u200cشود")

rep_rx(
    'admin/pages/products.php',
    r"(name=\"device_limit\" value=\"<\?= h\(\(string\)\$v\('device_limit', 0\)\) \?>\">\n\s*<div class=\"hint\">)[^<]*(</div>)",
    lambda m: m.group(1) + HINT + m.group(2),
    'limitHwid',
)

rep_rx(
    'admin/pages/products.php',
    r"fa_num\(\$dmStat\[\$dk\]\)",
    lambda m: "fa_num((int)($dmStat[$dk] ?? 0))",
    '$dmStat[$dk] ?? 0',
)

write_all()

# =============================== sanity ===============================
SANITY = [
    ('app/Service/Svc.php', "'happ'   => ["),
    ('app/Service/Svc.php', 'function wantsHapp'),
    ('app/Service/Svc.php', 'function happLink'),
    ('app/Service/Svc.php', "return $m !== 'config' && $m !== 'happ';"),
    ('app/Bot/Bot.php', '#happ-only-bot'),
    ('app/Bot/Bot.php', '#happ-only-txt'),
    ('app/Bot/Bot.php', '#happ-only-btn'),
    ('app/Bot/Bot.php', '#happ-only-view'),
    ('app/Bot/Bot.php', "if (!$hOnly && class_exists('Links')"),
    ('miniapp/api.php', '#happ-only-ma'),
    ('miniapp/api.php', "'happ_only'"),
    ('admin/pages/products.php', 'limitHwid'),
    ('admin/pages/products.php', '(HWID)</label>'),
    ('admin/pages/products.php', '$dmStat[$dk] ?? 0'),
]
for path, needle in SANITY:
    try:
        with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8') as fh:
            body = fh.read()
    except Exception as e:
        print('sanity %s: cannot read (%s)' % (path, e))
        continue
    print('sanity %s / %s : %s' % (path, needle, 'ok' if needle in body else 'MISSING'))

# =============================== version bump ===============================
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
entries = [
    'حالت تحویل «فقط لینک هپ» برای محصولات: مشتری فقط دیپ\u200cلینک اختصاصی Happ می\u200cگیرد',
    'محدودیت هاردویر (HWID) هر محصول روی پنل نسل جدید سنایی اعمال می\u200cشود',
]
cl = vj.get('changelog')
if isinstance(cl, list):
    for e in reversed(entries):
        if e not in cl:
            cl.insert(0, e)
    vj['changelog'] = cl
    print('changelog: entry added')
else:
    print('changelog: skipped (unexpected type)')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
