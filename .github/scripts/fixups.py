# -*- coding: utf-8 -*-
# fixed138 - the Happ deep link was rejected by the app ("invalid deeplink").
# Per Happ dev docs the payload after happ://add/ must be the RAW subscription
# URL (the crypt* variants are the only base64 ones), so the base64url payload
# built in fixed137 was wrong. Also: normalize whatever the panel returns and
# always show the plain panel subscription URL as a manual fallback.
import io, os, re, sys, json, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed138').strip() or 'fixed138'

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


LNK = 'app/Service/Links.php'
BOT = 'app/Bot/Bot.php'
X3 = 'app/Panel/Xui3.php'

# ---------------------------------------------------------------- recon
dump('x3_happ', X3, 795, 872)

# ------------------------------------------------- 1) normalizer helper
NORM = """    /**
     * فقط دیپ‌لینک معتبر Happ پذیرفته می‌شود.
     * طبق مستندات Happ، بعد از happ://add/ باید آدرس خام اشتراک بیاید
     * (فقط نسخهٔ رمزنگاری‌شده happ://crypt*/ به صورت base64 است).
     * 0.0.2 #happ-norm-fn
     */
    public static function normHapp(string $l): string
    {
        $l = trim($l);
        if ($l === '') return '';
        if (stripos($l, 'happ://') === 0) return $l;
        if (stripos($l, 'http://') === 0 || stripos($l, 'https://') === 0) return 'happ://add/' . $l;
        return '';
    }

"""

rep_rx(
    LNK,
    r"(?m)^(\s*)public static function panelSub\(array \$svc\): string",
    lambda m: NORM + m.group(0),
    '#happ-norm-fn',
)

# ------------------------------- 2) validate what the panel hands back
rep_rx(
    LNK,
    r"(?m)^(\s*)return \$l !== '' \? \$l : self::happFallback\(\$svc\); /\* 0\.0\.2 #happ-fb2 \*/",
    lambda m: (m.group(1) + "$l = self::normHapp($l); /* 0.0.2 #happ-norm */\n"
               + m.group(1) + "return $l !== '' ? $l : self::happFallback($svc);"),
    '#happ-norm ',
)

# ------------------------------- 3) the deep link itself: raw url, no base64
rep_rx(
    LNK,
    r"(?m)^(\s*)return 'happ://add/' \. rtrim\(strtr\(base64_encode\(\$u\), '\+/', '-_'\), '='\);",
    lambda m: (m.group(1) + "/* 0.0.2 #happ-fb4: آدرس اشتراک باید خام باشد؛ base64 را Happ نامعتبر می‌داند */\n"
               + m.group(1) + "return 'happ://add/' . $u;"),
    '#happ-fb4',
)

# ------------------------------- 4) always offer the plain subscription url
HAPP_PLAIN = """
{IND}/* 0.0.2 #happ-plain: روش مطمئن دوم — افزودن دستی آدرس اشتراک خودِ پنل */
{IND}$psub = method_exists('Links', 'panelSub') ? Links::panelSub($s) : '';
{IND}if ($psub === '' && class_exists('Svc')) {
{IND}    try {
{IND}        $psub = (string)Svc::subUrl($s, 'panel');
{IND}    } catch (Throwable $e) {
{IND}        $psub = '';
{IND}    }
{IND}}
{IND}if ($psub !== '') {
{IND}    $txt .= "\nاگر لینک بالا اضافه نشد، این آدرس اشتراک را کپی کنید و در Happ بزنید «+» ← Add subscription:\n"
{IND}        . '<code>' . h($psub) . '</code>' . "\n";
{IND}}"""

rep_rx(
    BOT,
    (r"\. '<code>' \. h\(\$happ\) \. '</code>' \. \"\\n\";\n"
     r"(\s*)\} else \{\n\s*\$txt \.= \"[^\"]*\";\n\1\}"),
    lambda m: m.group(0) + HAPP_PLAIN.replace('{IND}', m.group(1)),
    '#happ-plain',
)

write_all()

# ================================ SANITY ================================
SANITY = [
    (LNK, '#happ-norm-fn'),
    (LNK, 'public static function normHapp(string $l): string'),
    (LNK, '#happ-norm '),
    (LNK, '#happ-fb4'),
    (LNK, "return 'happ://add/' . $u;"),
    (BOT, '#happ-plain'),
    (BOT, 'Add subscription:'),
]
for p, needle in SANITY:
    try:
        ok = needle in load(p)
    except Exception:
        ok = False
    print('sanity %s / %s : %s' % (p, needle, 'ok' if ok else 'MISSING'))

dump('links_after', LNK, 55, 135)

# ============================== version bump ==============================
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
notes = [
    'رفع باگ: دیپ‌لینک Happ با قالب درست (happ://add/ + آدرس خام اشتراک) ساخته می‌شود تا در برنامه Invalid نخورد',
    'لینک برگشتی از پنل اعتبارسنجی و در صورت نیاز به دیپ‌لینک معتبر تبدیل می‌شود',
    'در صفحهٔ «افزودن به Happ» آدرس خام اشتراک پنل هم برای افزودن دستی نمایش داده می‌شود',
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
