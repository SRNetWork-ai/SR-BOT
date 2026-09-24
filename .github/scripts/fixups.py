# -*- coding: utf-8 -*-
# fixed152 - 0.0.2 #19: trial abuse guard by IP/device fingerprint (schema + Svc) + recon for settings/miniapp.
import io, os, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed152').strip() or 'fixed152'

CACHE = {}
NEW = set()
ERRORS = []
WARN = []
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json')

MIG = 'app/Service/Migrate.php'
SVC = 'app/Service/Svc.php'


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8', errors='replace') as fh:
        CACHE[path] = fh.read()
    return CACHE[path]


def rep_lit(path, old, new, marker, optional=False):
    src = load(path)
    if marker in src:
        print('skip (already applied): %s' % marker)
        return
    n = src.count(old)
    if n != 1:
        msg = '%s: anchor for %s matched %d times (want 1)' % (path, marker, n)
        if optional:
            WARN.append(msg)
            print('SKIP optional: ' + msg)
        else:
            ERRORS.append(msg)
        return
    CACHE[path] = src.replace(old, new)
    NEW.add(path)
    print('patched: %s (%s)' % (path, marker))


def dump(tag, path, start, end):
    try:
        lines = load(path).split(chr(10))
    except Exception as e:
        print('---- dump %s FAILED (%s) ----' % (tag, e))
        return
    print('---- dump %s : %s (%d lines) ----' % (tag, path, len(lines)))
    for i in range(max(1, start), min(len(lines), end) + 1):
        s = lines[i - 1]
        if len(s) > 220:
            s = s[:220] + ' ...'
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


def grep_all(needle, limit=25, only=None):
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
                if len(s) > 170:
                    s = s[:170] + ' ...'
                print('%s:%d: %s' % (p, i, s))
                n += 1
                if n >= limit:
                    print('---- end grep: %s (truncated) ----' % needle)
                    return
    print('---- end grep: %s (%d) ----' % (needle, n))


# ---------------- 1) schema: users fingerprint columns ----------------
rep_lit(
    MIG,
    "            'miniapp_at'        => 'DATETIME NULL',\n",
    "            'miniapp_at'        => 'DATETIME NULL',\n"
    "            'test_ip'           => 'VARCHAR(64) NULL',   /* 0.0.2 #19 */\n"
    "            'test_dev'          => 'VARCHAR(64) NULL',   /* 0.0.2 #19 */\n"
    "            'test_at'           => 'DATETIME NULL',      /* 0.0.2 #19 */\n",
    "'test_ip'           =>",
)

# ---------------- 2) Svc: fingerprint helpers ----------------
MSG_IP = '\u0627\u0632 \u0627\u06cc\u0646 \u0634\u0628\u06a9\u0647 \u0628\u06cc\u0634 \u0627\u0632 \u062d\u062f \u0645\u062c\u0627\u0632 \u0627\u06a9\u0627\u0646\u062a \u062a\u0633\u062a \u062f\u0631\u06cc\u0627\u0641\u062a \u0634\u062f\u0647 \u0627\u0633\u062a.'
MSG_DEV = '\u0628\u0627 \u0627\u06cc\u0646 \u062f\u0633\u062a\u06af\u0627\u0647 \u0642\u0628\u0644\u0627\u064b \u0627\u06a9\u0627\u0646\u062a \u062a\u0633\u062a \u062f\u0631\u06cc\u0627\u0641\u062a \u0634\u062f\u0647 \u0627\u0633\u062a.'

HELPERS = (
    "    /* 0.0.2 #19 - request fingerprint (web/mini-app only; empty on Telegram webhook) */\n"
    "    public static array $testFp = ['', ''];\n"
    "\n"
    "    /** ip = real client ip, dev = raw device string (hashed here) */\n"
    "    public static function setTestFp(string $ip, string $dev = ''): void\n"
    "    {\n"
    "        $ip  = trim($ip);\n"
    "        $dev = trim($dev);\n"
    "        self::$testFp = [\n"
    "            $ip === '' ? '' : substr($ip, 0, 64),\n"
    "            $dev === '' ? '' : substr(hash('sha256', $dev), 0, 48),\n"
    "        ];\n"
    "    }\n"
    "\n"
    "    private static function testFp(): array\n"
    "    {\n"
    "        return [(string)(self::$testFp[0] ?? ''), (string)(self::$testFp[1] ?? '')];\n"
    "    }\n"
    "\n"
    "    private static function testFpReady(): bool\n"
    "    {\n"
    "        try { return class_exists('Migrate') && Migrate::hasColumn('users', 'test_ip'); }\n"
    "        catch (\\Throwable $e) { return false; }\n"
    "    }\n"
    "\n"
    "    /** store the fingerprint of the request that took a trial */\n"
    "    private static function testStampFp(int $uid): void\n"
    "    {\n"
    "        if (!self::testFpReady()) return;\n"
    "        [$ip, $dev] = self::testFp();\n"
    "        $set = ['test_at' => now()];\n"
    "        if ($ip !== '')  $set['test_ip']  = $ip;\n"
    "        if ($dev !== '') $set['test_dev'] = $dev;\n"
    "        try { DB::update('users', $set, 'id = :id', [':id' => $uid]); }\n"
    "        catch (\\Throwable $e) { app_log('test', 'fp stamp failed: ' . $e->getMessage()); }\n"
    "    }\n"
    "\n"
)

rep_lit(
    SVC,
    '    private static function testDeny(array $user, array $panel, string $why, string $msg): array',
    HELPERS + '    private static function testDeny(array $user, array $panel, string $why, string $msg): array',
    'function setTestFp(',
)

# ---------------- 3) Svc: the guard itself ----------------
ANCHOR = "        if ((string)DB::setting('test_panel_check', '1') === '1' && (int)($user['test_count'] ?? 0) === 0) {"

GUARD = (
    "        /* 0.0.2 #19 - IP / device caps (0 or empty fingerprint = off) */\n"
    "        if (self::testFpReady()) {\n"
    "            [$fpIp, $fpDev] = self::testFp();\n"
    "            $ipMax = (int)DB::setting('test_ip_max', '0');\n"
    "            if ($ipMax > 0 && $fpIp !== '') {\n"
    "                $sameIp = (int)DB::val(\n"
    "                    'SELECT COUNT(*) FROM {p}users WHERE test_ip = :ip AND test_count > 0 AND id <> :u',\n"
    "                    [':ip' => $fpIp, ':u' => $uid]\n"
    "                );\n"
    "                if ($sameIp >= $ipMax) {\n"
    "                    return self::testDeny($user, $panel, 'ip_max', '" + MSG_IP + "');\n"
    "                }\n"
    "            }\n"
    "            if ((string)DB::setting('test_device_unique', '0') === '1' && $fpDev !== '') {\n"
    "                $sameDev = (int)DB::val(\n"
    "                    'SELECT COUNT(*) FROM {p}users WHERE test_dev = :d AND test_count > 0 AND id <> :u',\n"
    "                    [':d' => $fpDev, ':u' => $uid]\n"
    "                );\n"
    "                if ($sameDev > 0) {\n"
    "                    return self::testDeny($user, $panel, 'device_dup', '" + MSG_DEV + "');\n"
    "                }\n"
    "            }\n"
    "        }\n"
    "\n"
)

rep_lit(SVC, ANCHOR, GUARD + ANCHOR, "'test_ip_max'")

# ---------------- 4) Svc: stamp after a successful trial ----------------
rep_lit(
    SVC,
    "            DB::q('UPDATE {p}users SET test_count = test_count + 1 WHERE id = :id', [':id' => (int)$user['id']]);\n",
    "            DB::q('UPDATE {p}users SET test_count = test_count + 1 WHERE id = :id', [':id' => (int)$user['id']]);\n"
    "            self::testStampFp((int)$user['id']); /* 0.0.2 #19 */\n",
    'self::testStampFp(',
)

# ---------------- write ----------------
if ERRORS:
    print('ABORTED - anchors not found:')
    for e in ERRORS:
        print(' - ' + e)
    print('exit: 1')
    sys.exit(1)

if WARN:
    print('warnings (optional patches skipped):')
    for w in WARN:
        print(' - ' + w)

for p in sorted(NEW):
    with io.open(os.path.join(ROOT, p), 'w', encoding='utf-8') as fh:
        fh.write(CACHE[p])
    print('wrote ' + p)
print('changed files: %d' % len(NEW))

SANITY = [
    (MIG, "'test_ip'           => 'VARCHAR(64) NULL'"),
    (SVC, 'public static function setTestFp('),
    (SVC, "DB::setting('test_ip_max', '0')"),
    (SVC, "DB::setting('test_device_unique', '0')"),
    (SVC, 'self::testStampFp((int)$user[\'id\']);'),
]
ok = 0
for p, needle in SANITY:
    good = needle in load(p)
    print('sanity %s / %s : %s' % (p, needle[:46], 'ok' if good else 'MISSING'))
    if good:
        ok += 1
print('sanity: %d/%d' % (ok, len(SANITY)))

# ---------------- recon for the next build ----------------
print('===== settings.php: trial rules save-handlers =====')
dump('set_save', 'admin/pages/settings.php', 26, 48)
print('===== settings.php: trial rules form =====')
dump('set_form', 'admin/pages/settings.php', 2818, 2848)
print('===== mini-app: client ip context =====')
dump('ma_ip', 'miniapp/api.php', 218, 248)
print('===== trial entry points =====')
grep_all('Svc::test', 14)
grep_all('clientIp()', 14)
print('===== users INDEXES block =====')
grep_all("'users' => [", 8)

# ---------------- version bump ----------------
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = '0.0.2 #19: \u0645\u062d\u062f\u0648\u062f\u06cc\u062a \u0627\u06a9\u0627\u0646\u062a \u062a\u0633\u062a \u0628\u0631 \u067e\u0627\u06cc\u0647\u0654 IP \u0648 \u062f\u0633\u062a\u06af\u0627\u0647'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
