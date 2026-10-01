# -*- coding: utf-8 -*-
# fixed173 - Unknown column 'ssl_verify' on panel save + automatic schema completion
import io, os, sys, json, re

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed173').strip() or 'fixed173'
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


# ============================================================
# 1) Migrate::healData - self-healing save for any table
# ============================================================
MIG = 'app/Service/Migrate.php'
HEAL = r"""
    /**
     * 0.0.2 #heal-cols — پیش از ذخیره (INSERT/UPDATE) صدا زده می‌شود:
     * اگر ستونی از داده در جدول نباشد، یک بار «تکمیل ساختار» (run) اجرا می‌شود؛ اگر باز هم
     * نبود و تعریفش در COLUMNS هست همان ستون ساخته می‌شود؛ وگرنه از داده حذف می‌شود
     * تا ذخیره با خطای «Unknown column» متوقف نشود.
     */
    public static function healData(string $table, array $data): array
    {
        static $ran = false;
        if ($data === [] || !preg_match('/^[A-Za-z0-9_]+$/', $table)) return $data;

        $cols = static function () use ($table): ?array {
            try {
                $out = [];
                foreach (DB::all('SHOW COLUMNS FROM {p}' . $table) as $r) {
                    $out[strtolower((string)($r['Field'] ?? ''))] = true;
                }
                return $out;
            } catch (Throwable $e) {
                return null;
            }
        };

        $set = $cols();
        if ($set === null) return $data;
        $want = self::COLUMNS[$table] ?? [];

        foreach (array_keys($data) as $col) {
            $col = (string)$col;
            if (isset($set[strtolower($col)]) || !preg_match('/^[A-Za-z0-9_]+$/', $col)) continue;

            if (!$ran) {
                $ran = true;
                try {
                    self::run();
                    if (function_exists('app_log')) app_log('db', 'heal: schema completed (missing ' . $table . '.' . $col . ')');
                } catch (Throwable $e) {
                    if (function_exists('app_log')) app_log('db', 'heal: run failed: ' . $e->getMessage());
                }
                $set = $cols() ?? $set;
                if (isset($set[strtolower($col)])) continue;
            }

            if (isset($want[$col]) && is_string($want[$col])) {
                try {
                    DB::q('ALTER TABLE {p}' . $table . ' ADD COLUMN `' . $col . '` ' . $want[$col]);
                    $set[strtolower($col)] = true;
                    continue;
                } catch (Throwable $e) {
                    if (stripos($e->getMessage(), 'Duplicate column') !== false) continue;
                    if (function_exists('app_log')) app_log('db', 'heal: add ' . $table . '.' . $col . ' failed: ' . $e->getMessage());
                }
            }

            if (function_exists('app_log')) app_log('db', 'heal: dropped unknown column ' . $table . '.' . $col);
            unset($data[$col]);
        }
        return $data;
    }
"""
insert_before_func(MIG, 'hasTable', HEAL, '#heal-cols')

# ============================================================
# 2) panels.php - heal before UPDATE / INSERT
# ============================================================
PNL = 'admin/pages/panels.php'
rep_lit(PNL,
        "            $data['session'] = null;\n"
        "            DB::update('panels', $data, 'id = :id', [':id' => $id]);\n",
        "            $data['session'] = null;\n"
        "            if (class_exists('Migrate') && method_exists('Migrate', 'healData')) $data = Migrate::healData('panels', $data); /* 0.0.2 #heal-cols-up */\n"
        "            DB::update('panels', $data, 'id = :id', [':id' => $id]);\n",
        '#heal-cols-up')
rep_lit(PNL,
        "            $data['created_at'] = now();\n"
        "            $id = DB::insert('panels', $data);\n",
        "            $data['created_at'] = now();\n"
        "            if (class_exists('Migrate') && method_exists('Migrate', 'healData')) $data = Migrate::healData('panels', $data); /* 0.0.2 #heal-cols-ins */\n"
        "            $id = DB::insert('panels', $data);\n",
        '#heal-cols-ins')

# ============================================================
# 3) schema.sql - fresh installs get the panel columns directly
# ============================================================
SQL = 'database/schema.sql'
rep_lit(SQL,
        "  `username` VARCHAR(128) NOT NULL,\n"
        "  `password` VARCHAR(190) NOT NULL,\n"
        "  `sub_base` VARCHAR(255) NULL,\n",
        "  `username` VARCHAR(128) NOT NULL,\n"
        "  `password` VARCHAR(255) NOT NULL,\n"
        "  `ssl_verify` TINYINT(1) NOT NULL DEFAULT 0,\n"
        "  `api_token` VARCHAR(500) NULL,\n"
        "  `sub_base` VARCHAR(255) NULL,\n",
        '`ssl_verify` TINYINT(1)')
rep_lit(SQL,
        "  `test_volume_gb` DECIMAL(10,2) NOT NULL DEFAULT 1.00,\n"
        "  `test_days` INT NOT NULL DEFAULT 1,\n",
        "  `test_volume_gb` DECIMAL(10,2) NOT NULL DEFAULT 1.00,\n"
        "  `test_volume_mb` INT NOT NULL DEFAULT 0,\n"
        "  `test_days` INT NOT NULL DEFAULT 1,\n",
        '`test_volume_mb` INT')

# ============================================================
# 4) DB.php - unknown column/table => complete the schema once and retry
# ============================================================
DBP = 'app/DB.php'
OLD_DB = (
    "    private static function retryable(PDOException $e): bool\n"
    "    {\n"
    "        $code = (int)($e->errorInfo[1] ?? 0);\n"
    "        if (in_array($code, [2000, 1153, 2006, 2013], true)) return true;\n"
)
NEW_DB = r"""    /** 0.0.2 #auto-migrate: تکمیل خودکار ساختار در همین درخواست امتحان شده است؟ */
    private static bool $migTried = false;

    /**
     * خطای ستون/جدول ناموجود (1054/1146): یک بار در هر درخواست «تکمیل ساختار» (Migrate::run)
     * اجرا می‌شود و همان کوئری دوباره امتحان می‌شود. داخل تراکنش باز اجرا نمی‌شود، چون
     * ALTER TABLE در MySQL تراکنش را خودکار commit می‌کند. حداکثر هر ۵ دقیقه یک بار.
     */
    private static function autoMigrate(): bool
    {
        if (self::$migTried) return false;
        self::$migTried = true;
        if (!class_exists('Migrate') || !method_exists('Migrate', 'run')) return false;
        try {
            if (self::$pdo instanceof PDO && self::$pdo->inTransaction()) return false;
            $flag = rtrim(sys_get_temp_dir(), '/') . '/srbot-automig-' . substr(md5(__FILE__), 0, 10);
            if (is_file($flag) && (time() - (int)@filemtime($flag)) < 300) return false;
            @touch($flag);
            Migrate::run();
            if (function_exists('app_log')) app_log('db', 'auto-migrate: schema completed after unknown column/table');
            return true;
        } catch (Throwable $e) {
            if (function_exists('app_log')) app_log('db', 'auto-migrate failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function retryable(PDOException $e): bool
    {
        $code = (int)($e->errorInfo[1] ?? 0);
        if (in_array($code, [2000, 1153, 2006, 2013], true)) return true;
        if (($code === 1054 || $code === 1146) && self::autoMigrate()) return true; /* 0.0.2 #auto-migrate */
"""
if load(DBP).count('self::retryable(') >= 1:
    rep_lit(DBP, OLD_DB, NEW_DB, '#auto-migrate')
else:
    WARN.append('app/DB.php: retryable() is never called - auto-migrate skipped')

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
# recon
# ============================================================
print('===== DB.php retry path =====')
for i, ln in enumerate(load(DBP).split(chr(10))):
    if 'retryable(' in ln or 'retryFresh(' in ln or 'autoMigrate(' in ln:
        print('  %5d| %s' % (i + 1, ln.strip()[:140]))
dump_find('db_q', DBP, 'public static function q(', 1, 38)
dump_find('mig_has', MIG, 'function hasColumn(', 1, 9)
dump_find('mig_run', MIG, 'function run(', 1, 22)

print('===== installer =====')
for nd in ['schema.sql', 'runSqlFile', 'Migrate', 'migrations']:
    grep('install/index.php', nd, 6)
bl = load('app/bootstrap.php').split(chr(10))
print('app/bootstrap.php: %d lines' % len(bl))
dump('boot_tail', 'app/bootstrap.php', max(1, len(bl) - 20), len(bl))
grep_all('vpnui', 8)

print('===== schema.sql vs Migrate::COLUMNS =====')
mig = load(MIG)
st = mig.find('public const COLUMNS = [')
en = mig.find(chr(10) + '    ];', st)
cols = {}
cur = None
for ln in mig[st:en].split(chr(10)):
    m = re.match(r"^\s{8}'(\w+)'\s*=>\s*\[\s*$", ln)
    if m:
        cur = m.group(1)
        cols.setdefault(cur, [])
        continue
    m = re.match(r"^\s{12}'(\w+)'\s*=>", ln)
    if m and cur:
        cols[cur].append(m.group(1))
schema = load(SQL)
tables = {}
for m in re.finditer(r'CREATE TABLE IF NOT EXISTS \{p\}(\w+) \((.*?)\)\s*ENGINE', schema, re.S):
    tables[m.group(1)] = re.findall(r'^\s*`(\w+)`', m.group(2), re.M)
total = 0
for t in sorted(cols):
    if t not in tables:
        print('  %-16s (table not in schema.sql)' % t)
        continue
    miss = [c for c in cols[t] if c not in tables[t]]
    total += len(miss)
    print('  %-16s %d missing: %s' % (t, len(miss), ', '.join(miss)[:220]))
print('schema tables: %d, Migrate tables: %d, missing columns total: %d' % (len(tables), len(cols), total))

# ============================================================
# version bump
# ============================================================
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = 'رفع خطای «Unknown column ssl_verify» هنگام افزودن پنل + تکمیل خودکار ساختار دیتابیس'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write(chr(10))
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
