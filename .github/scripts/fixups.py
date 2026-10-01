# -*- coding: utf-8 -*-
# fixed174 - schema.sql completion from Migrate::COLUMNS + Migrate::ensureBuild + DB retry-after-migrate
import io, os, sys, json, re

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed174').strip() or 'fixed174'
CACHE = {}
ORIG = {}
ERRORS = []
WARN = []
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json', '.sh')


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8') as fh:
        s = fh.read()
    CACHE[path] = s
    ORIG[path] = s
    return s


def rep_lit(path, old, new, marker, optional=False):
    s = load(path)
    if marker in s:
        print('skip (already applied): %s' % marker)
        return
    n = s.count(old)
    if n != 1:
        msg = '%s: anchor for %s matched %d times (want 1)' % (path, marker, n)
        (WARN if optional else ERRORS).append(msg)
        print('MISS: %s' % msg)
        return
    CACHE[path] = s.replace(old, new, 1)
    print('patched: %s (%s)' % (path, marker))


def insert_before_func(path, fname, block, marker):
    s = load(path)
    if marker in s:
        print('skip (already applied): %s' % marker)
        return
    lines = s.split(chr(10))
    pat = re.compile(r'^\s*(?:public|private|protected)?\s*(?:static\s+)?function\s+' + re.escape(fname) + r'\s*\(')
    idx = None
    for i, ln in enumerate(lines):
        if pat.match(ln):
            idx = i
            break
    if idx is None:
        msg = '%s: function %s not found (%s)' % (path, fname, marker)
        ERRORS.append(msg)
        print('MISS: %s' % msg)
        return
    j = idx
    while j > 0:
        t = lines[j - 1].strip()
        if t.startswith('/**') or t.startswith('*') or t.startswith('//'):
            j -= 1
        else:
            break
    add = block.strip(chr(10)).split(chr(10)) + ['']
    if j > 0 and lines[j - 1].strip() != '':
        add = [''] + add
    lines[j:j] = add
    CACHE[path] = chr(10).join(lines)
    print('patched: %s (%s) at line %d' % (path, marker, j + 1))


def files_all():
    out = []
    for base, dirs, names in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        for n in names:
            if n.endswith(EXT):
                out.append(os.path.relpath(os.path.join(base, n), ROOT))
    return sorted(out)


def grep(path, needle, limit=10):
    hits = 0
    for i, ln in enumerate(load(path).split(chr(10))):
        if needle in ln:
            hits += 1
            if hits <= limit:
                print('  %5d| %s' % (i + 1, ln.strip()[:140]))
    print('---- grep %s in %s (%d) ----' % (needle, path, hits))


def grep_all(needle, limit=12):
    hits = 0
    for p in files_all():
        if p.startswith('.github'):
            continue
        try:
            s = load(p)
        except Exception:
            continue
        for i, ln in enumerate(s.split(chr(10))):
            if needle in ln:
                hits += 1
                if hits <= limit:
                    print('  %-30s %5d| %s' % (p, i + 1, ln.strip()[:120]))
    print('---- grep: %s (%d) ----' % (needle, hits))


def dump(tag, path, start, end):
    lines = load(path).split(chr(10))
    print('---- dump %s (%s:%d-%d of %d) ----' % (tag, path, start, end, len(lines)))
    for i in range(max(0, start - 1), min(len(lines), end)):
        print('%5d|%s' % (i + 1, lines[i]))
    print('---- end dump %s ----' % tag)


def dump_find(tag, path, needle, before=2, after=30):
    lines = load(path).split(chr(10))
    for i, ln in enumerate(lines):
        if needle in ln:
            dump(tag, path, i + 1 - before, i + 1 + after)
            return
    print('---- dump %s : needle not found (%s) ----' % (tag, needle))


MIG = 'app/Service/Migrate.php'
SQL = 'database/schema.sql'
DBP = 'app/DB.php'

# ============================================================
# 1) schema.sql: add every column that only Migrate::COLUMNS knows
# ============================================================
COL_RE = re.compile(r"^\s{12}'(\w+)'\s*=>\s*('[^'\\]*'|\x22[^\x22\\]*\x22)\s*,?\s*(?:/\*.*?\*/|//.*)?\s*$")
mig = load(MIG)
st = mig.find('public const COLUMNS = [')
en = mig.find(chr(10) + '    ];', st) if st >= 0 else -1
if st < 0 or en < 0:
    ERRORS.append('Migrate::COLUMNS block not found')
defs = {}
cur = None
for ln in (mig[st:en].split(chr(10)) if (st >= 0 and en > st) else []):
    m = re.match(r"^\s{8}'(\w+)'\s*=>\s*\[\s*$", ln)
    if m:
        cur = m.group(1)
        defs.setdefault(cur, [])
        continue
    m = COL_RE.match(ln)
    if m and cur:
        d = m.group(2)[1:-1].strip()
        d = re.sub(r'\s+(?:AFTER\s+`?\w+`?|FIRST)\s*$', '', d, flags=re.I)
        defs[cur].append((m.group(1), d))
    elif cur and re.match(r"^\s{12}'(\w+)'\s*=>", ln):
        WARN.append('COLUMNS line not parsed: ' + ln.strip()[:110])
print('Migrate::COLUMNS parsed: %d tables, %d columns' % (len(defs), sum(len(v) for v in defs.values())))

schema = load(SQL)
KEYS = ('PRIMARY KEY', 'UNIQUE', 'KEY ', 'KEY`', 'INDEX', 'CONSTRAINT', 'FOREIGN KEY', 'FULLTEXT')
added = 0
added_tables = []
for t in sorted(defs):
    head = 'CREATE TABLE IF NOT EXISTS {p}%s (' % t
    hi = schema.find(head)
    if hi < 0:
        continue
    bi = hi + len(head)
    ci = schema.find(chr(10) + ')', bi)
    if ci < 0:
        ERRORS.append('schema: end of table %s not found' % t)
        continue
    body = schema[bi:ci]
    have = set(re.findall(r'^\s*`(\w+)`', body, re.M))
    miss = [(c, d) for (c, d) in defs[t] if c not in have]
    if not miss:
        continue
    lines = body.split(chr(10))
    new = ['  `%s` %s' % (c, d) for (c, d) in miss]
    kidx = None
    for i, ln in enumerate(lines):
        if ln.strip().upper().startswith(KEYS):
            kidx = i
            break
    if kidx is not None:
        lines[kidx:kidx] = [x + ',' for x in new]
    else:
        li = None
        for i in range(len(lines) - 1, -1, -1):
            s2 = lines[i].strip()
            if s2 and not s2.startswith('--'):
                li = i
                break
        if li is None or lines[li].rstrip().endswith(','):
            ERRORS.append('schema: unexpected layout in table %s' % t)
            continue
        last = lines[li]
        if ' -- ' in last:
            a, b = last.split(' -- ', 1)
            lines[li] = a.rstrip() + ', -- ' + b
        else:
            lines[li] = last.rstrip() + ','
        lines[li + 1:li + 1] = [x + ',' for x in new[:-1]] + [new[-1]]
    schema = schema[:bi] + chr(10).join(lines) + schema[ci:]
    added += len(new)
    added_tables.append((t, len(new)))
    for x in new:
        print('  + %-14s %s' % (t, x.strip()))
if re.search(r',\s*\n\)\s*ENGINE', schema):
    ERRORS.append('schema: trailing comma before ) ENGINE')
CACHE[SQL] = schema
print('schema.sql: %d columns added in %d tables' % (added, len(added_tables)))

# ============================================================
# 2) Migrate::ensureBuild - complete the schema once per version/build
# ============================================================
ENSURE = r"""
    /**
     * 0.0.2 #auto-schema — یک بار برای هر نسخه/بیلد: اگر نسخهٔ نصب‌شده عوض شده باشد (نصب تازه،
     * آپلود دستی، git pull) «تکمیل ساختار» خودکار اجرا می‌شود. در درخواست‌های عادی فقط یک تنظیم
     * خوانده می‌شود.
     */
    public static function ensureBuild(): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        try {
            $root = defined('APP_ROOT') ? (string)APP_ROOT : dirname(__DIR__, 2);
            $vj   = json_decode((string)@file_get_contents($root . '/version.json'), true);
            if (!is_array($vj)) return;
            $want = trim((string)($vj['version'] ?? '')) . '|' . trim((string)($vj['build'] ?? ''));
            if ($want === '|') return;
            if ((string)DB::setting('schema_build', '') === $want) return;
            if (!self::hasTable('settings')) return; /* هنوز نصب نشده است */

            $locked = true;
            try {
                $locked = (int)DB::val("SELECT GET_LOCK(CONCAT(DATABASE(), ':srbot_schema'), 0)", [], 0) === 1;
            } catch (Throwable $e) {
                $locked = true;
            }
            if (!$locked) return;
            try {
                $r = self::run();
                DB::setSetting('schema_build', $want);
                if (function_exists('app_log')) {
                    app_log('db', 'auto-schema ' . $want . ' | done=' . (int)($r['done'] ?? 0) . ' fail=' . (int)($r['fail'] ?? 0));
                }
            } finally {
                try { DB::val("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':srbot_schema'))", [], 0); } catch (Throwable $e) {}
            }
        } catch (Throwable $e) {
            if (function_exists('app_log')) app_log('db', 'auto-schema failed: ' . $e->getMessage());
        }
    }
"""
insert_before_func(MIG, 'hasTable', ENSURE, '#auto-schema')

# ============================================================
# 3) DB::q - unknown column/table: migrate once, retry on the same connection
# ============================================================
OLD_Q = (
    "        } catch (PDOException $e) {\n"
    "            $inTx = false;\n"
    "            try { $inTx = self::pdo()->inTransaction(); } catch (Throwable $x) {}\n"
    "\n"
    "            if (!$inTx && self::retryable($e)) {\n"
    "                $emu = self::emu();\n"
)
NEW_Q = r"""        } catch (PDOException $e) {
            $inTx = false;
            try { $inTx = self::pdo()->inTransaction(); } catch (Throwable $x) {}

            /* 0.0.2 #auto-migrate-q: ستون/جدول ناموجود → یک بار تکمیل ساختار و اجرای دوبارهٔ همان کوئری روی همین اتصال */
            $ecode = (int)($e->errorInfo[1] ?? 0);
            if (!$inTx && ($ecode === 1054 || $ecode === 1146) && self::autoMigrate()) {
                try {
                    $st = self::pdo()->prepare($raw);
                    $st->execute($params);
                    if (function_exists('app_log')) {
                        app_log('db', 'retry-after-migrate | ' . mb_substr(trim((string)preg_replace('/\s+/', ' ', $raw)), 0, 160));
                    }
                    return $st;
                } catch (PDOException $e3) {
                    throw self::wrap($e3, $raw);
                }
            }

            if (!$inTx && self::retryable($e)) {
                $emu = self::emu();
"""
OLD_R = "        if (($code === 1054 || $code === 1146) && self::autoMigrate()) return true; /* 0.0.2 #auto-migrate */\n"
rep_lit(DBP, OLD_Q, NEW_Q, '#auto-migrate-q', optional=True)
if '#auto-migrate-q' in CACHE[DBP] and CACHE[DBP].count(OLD_R) == 1:
    CACHE[DBP] = CACHE[DBP].replace(OLD_R, '', 1)
    print('patched: app/DB.php (retryable no longer handles 1054/1146)')

# ============================================================
# write
# ============================================================
if ERRORS:
    print('ABORTED - anchors not found:')
    for e in ERRORS:
        print('  - %s' % e)
    print('exit: 1')
    sys.exit(1)

changed = 0
for p, s in CACHE.items():
    if ORIG.get(p) == s:
        continue
    with io.open(os.path.join(ROOT, p), 'w', encoding='utf-8') as fh:
        fh.write(s)
    changed += 1
print('changed files: %d' % changed)
if WARN:
    print('warnings (optional patches skipped):')
    for w in WARN:
        print('  - %s' % w)

# ============================================================
# verify
# ============================================================
print('===== parity after patch =====')
schema2 = CACHE[SQL]
left = 0
for t in sorted(defs):
    head = 'CREATE TABLE IF NOT EXISTS {p}%s (' % t
    hi = schema2.find(head)
    if hi < 0:
        print('  %-16s (not in schema.sql)' % t)
        continue
    ci = schema2.find(chr(10) + ')', hi)
    have = set(re.findall(r'^\s*`(\w+)`', schema2[hi:ci], re.M))
    miss = [c for (c, _) in defs[t] if c not in have]
    left += len(miss)
    if miss:
        print('  %-16s still missing: %s' % (t, ', '.join(miss)))
print('missing columns left: %d' % left)
all_lines = schema2.split(chr(10))
for (t, n) in added_tables:
    head = 'CREATE TABLE IF NOT EXISTS {p}%s (' % t
    hl = next((i for i, l in enumerate(all_lines) if l.startswith(head)), None)
    if hl is None:
        continue
    cl = next((i for i in range(hl + 1, len(all_lines)) if all_lines[i].startswith(')')), None)
    if cl is None:
        continue
    s0 = max(hl, cl - n - 4)
    dump('schema_' + t, SQL, s0 + 1, cl + 1)

tb = mig.find('public const TABLES')
if tb >= 0:
    te = mig.find(chr(10) + '    ];', tb)
    tnames = re.findall(r"^\s{8}'(\w+)'\s*=>", mig[tb:te], re.M)
    sch_tables = set(re.findall(r'CREATE TABLE IF NOT EXISTS \{p\}(\w+) \(', schema2))
    print('Migrate::TABLES (%d): %s' % (len(tnames), ', '.join(tnames)))
    print('  not in schema.sql: %s' % ', '.join([x for x in tnames if x not in sch_tables]))
else:
    print('Migrate::TABLES const not found')

# ============================================================
# recon for the ensureBuild call sites
# ============================================================
print('===== recon =====')
dump_find('mig_hastable', MIG, 'function hasTable(', 1, 8)
dump('db_head', DBP, 1, 69)
dump_find('db_setting', DBP, 'public static function setting(', 0, 30)
grep_all('bootstrap.php', 25)
grep('app/bootstrap.php', 'DB::', 15)
dump('adm_head', 'admin/index.php', 1, 30)
dump('cron_head', 'cron/tasks.php', 1, 28)
dump('inst', 'install/index.php', 196, 245)
grep_all('version.json', 15)
if os.path.isdir(os.path.join(ROOT, 'tests')):
    print('tests/: %s' % ', '.join(sorted(os.listdir(os.path.join(ROOT, 'tests')))))
if os.path.exists(os.path.join(ROOT, 'phpunit.xml.dist')):
    dump('phpunit_xml', 'phpunit.xml.dist', 1, 40)
CI = '.github/workflows/ci.yml'
if os.path.exists(os.path.join(ROOT, CI)):
    dump('ci', CI, 1, 90)
else:
    print('no ci.yml')

# ============================================================
# version bump
# ============================================================
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = 'تکمیل database/schema.sql با ستون‌های جامانده (نصب تازه بدون خطای Unknown column) + اجرای دوبارهٔ کوئری پس از تکمیل خودکار ساختار'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write(chr(10))
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
