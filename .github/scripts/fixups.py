# -*- coding: utf-8 -*-
# fixed140 - the panel builds the Happ link locally (happ://crypt5/...) and only
# answers on POST /panel/api/clients/happLink/{id}, returning {encryptedLink:...}.
# Our driver used GET and never looked at the encryptedLink key, so it always
# fell through to the generic happ://add/ fallback.
import io, os, re, sys, json, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed140').strip() or 'fixed140'

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


def dump_find(tag, path, needle, before=2, after=60):
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


X3 = 'app/Panel/Xui3.php'
LNK = 'app/Service/Links.php'

HAPP = r"""    /**
     * دیپ‌لینک رمزنگاری‌شدهٔ Happ برای یک اکانت.
     * پنل نسل جدید این لینک را خودش محلی می‌سازد (happ://crypt5/...) و
     * فقط روی POST /panel/api/clients/happLink/{id} پاسخ می‌دهد؛ کلید پاسخ encryptedLink است.
     * 0.0.2 #happ-post
     */
    public function happLink(string $email): string
    {
        $email = trim($email);
        if ($email === '') return '';
        $row = $this->clientRow($email);
        if (!$row) return '';

        $id = 0;
        foreach (['id', 'clientId', 'recordId'] as $k) {
            if (isset($row[$k]) && is_numeric($row[$k]) && (int)$row[$k] > 0) {
                $id = (int)$row[$k];
                break;
            }
        }
        if ($id <= 0) return '';

        $path = '/clients/happLink/' . $id;
        $last = '';
        foreach ([[null, 'POST'], [[], 'POST'], [null, 'GET']] as $try) {
            $r = $this->api($path, $try[0], $try[1]);
            if (($r['success'] ?? false) === true) {
                $l = self::pickHappLink($r['obj'] ?? '');
                if ($l !== '') return $l;
                continue;
            }
            $last = trim((string)($r['msg'] ?? ''));
            if (stripos($last, 'happ_source_too_long') !== false) break;
        }
        if ($last !== '' && function_exists('app_log')) {
            app_log('panel', 'happ link unavailable', [
                'panel' => (int)($this->panel['id'] ?? 0),
                'email' => $email,
                'msg'   => mb_substr($last, 0, 160),
            ]);
        }
        return '';
    }

    /** استخراج دیپ‌لینک از پاسخ پنل — کلید رسمی encryptedLink است */
    private static function pickHappLink($o): string
    {
        if (is_string($o)) return trim($o);
        if (is_array($o)) {
            foreach (['encryptedLink', 'happLink', 'link', 'url', 'happ'] as $k) {
                if (isset($o[$k]) && is_string($o[$k]) && trim($o[$k]) !== '') return trim($o[$k]);
            }
        }
        return '';
    }
"""

rep_rx(
    X3,
    (r"^    /\*\*[^\n]*GET /clients/happLink[^\n]*\*/\n"
     r"    public function happLink\(string \$email\): string\n"
     r"    \{.*?^    \}\n"),
    lambda m: HAPP,
    '#happ-post',
    flags=re.M | re.S,
)

write_all()

# ================================ SANITY ================================
SANITY = [
    (X3, '#happ-post'),
    (X3, "$r = $this->api($path, $try[0], $try[1]);"),
    (X3, 'private static function pickHappLink($o): string'),
    (X3, "'encryptedLink', 'happLink', 'link', 'url', 'happ'"),
    (X3, 'happ_source_too_long'),
    (LNK, 'public static function normHapp(string $l): string'),
]
for p, needle in SANITY:
    try:
        ok = needle in load(p)
    except Exception:
        ok = False
    print('sanity %s / %s : %s' % (p, needle, 'ok' if ok else 'MISSING'))

dump_find('happ_after', X3, '#happ-post', 3, 62)

# ============================== version bump ==============================
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
notes = [
    'لینک رمزنگاری‌شدهٔ Happ (happ://crypt5) از خودِ پنل گرفته می‌شود: درخواست POST و خواندن کلید encryptedLink',
    'پیام خطای پنل (مثلاً طولانی‌بودن آدرس اشتراک) در لاگ ثبت می‌شود',
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
