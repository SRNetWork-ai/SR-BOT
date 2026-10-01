# -*- coding: utf-8 -*-
# fixed175 - automatic schema completion (boot + installer) + tools/schema-check.php
import io, os, sys, json, re, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed175').strip() or 'fixed175'
CACHE = {}
ORIG = {}
ERRORS = []
WARN = []


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


def add_file(path, content):
    full = os.path.join(ROOT, path)
    if os.path.exists(full):
        if load(path) == content:
            print('unchanged: %s' % path)
            return
        CACHE[path] = content
        print('updated: %s' % path)
        return
    CACHE[path] = content
    ORIG[path] = None
    print('NEW %s' % path)


def dump(tag, path, start, end):
    lines = load(path).split(chr(10))
    print('---- dump %s (%s:%d-%d of %d) ----' % (tag, path, start, end, len(lines)))
    for i in range(max(0, start - 1), min(len(lines), end)):
        print('%5d|%s' % (i + 1, lines[i]))
    print('---- end dump %s ----' % tag)


BOOT = 'app/bootstrap.php'
INST = 'install/index.php'

# ============================================================
# 1) boot(): complete the schema once per version/build
# ============================================================
bs = load(BOOT)
if '#auto-schema-boot' in bs:
    print('skip (already applied): #auto-schema-boot')
else:
    bl = bs.split(chr(10))
    idxs = [i for i, l in enumerate(bl) if l.strip() == 'DB::loadSettings();']
    if len(idxs) != 1:
        ERRORS.append('%s: DB::loadSettings(); found %d times (want 1)' % (BOOT, len(idxs)))
    else:
        i = idxs[0]
        ind = bl[i][:len(bl[i]) - len(bl[i].lstrip())]
        bl.insert(i + 1, ind + "if (class_exists('Migrate') && method_exists('Migrate', 'ensureBuild')) Migrate::ensureBuild(); /* 0.0.2 #auto-schema-boot */")
        CACHE[BOOT] = chr(10).join(bl)
        print('patched: %s (#auto-schema-boot) after line %d' % (BOOT, i + 1))

# ============================================================
# 2) installer: complete the schema right after the tables are created
# ============================================================
rep_lit(INST,
        "                DB::loadSettings(true);\n"
        "                if (!empty($D['shop_title'])) DB::setSetting('shop_title', $D['shop_title']);\n",
        "                DB::loadSettings(true);\n"
        "                if (!empty($D['shop_title'])) DB::setSetting('shop_title', $D['shop_title']);\n"
        "                /* 0.0.2 #install-schema: ستون‌ها، جدول‌ها و ایندکس‌هایی که فقط Migrate می‌شناسد همین حالا ساخته می‌شوند */\n"
        "                if (class_exists('Migrate') && method_exists('Migrate', 'ensureBuild')) Migrate::ensureBuild();\n",
        '#install-schema')

# ============================================================
# 3) tools/schema-check.php
# ============================================================
CHECK = r"""<?php
declare(strict_types=1);
/**
 * 0.0.2 #36 — هم‌خوانی database/schema.sql با Migrate::COLUMNS
 * هر ستونی که Migrate می‌شناسد باید در جدول متناظر schema.sql هم باشد تا نصب تازه از همان
 * ابتدا کامل باشد (نمونه: خطای Unknown column 'ssl_verify' هنگام افزودن پنل).
 * اجرا: php tools/schema-check.php     خروجی ۰ = سالم، ۱ = ناهمخوانی
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/Service/Migrate.php';

$sql = (string)@file_get_contents($root . '/database/schema.sql');
if ($sql === '') {
    fwrite(STDERR, "database/schema.sql not found\n");
    exit(1);
}

$tables = [];
if (preg_match_all('/CREATE TABLE IF NOT EXISTS \{p\}(\w+) \((.*?)\n\)/s', $sql, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $m) {
        preg_match_all('/^\s*`(\w+)`/m', $m[2], $cm);
        $tables[strtolower($m[1])] = array_map('strtolower', $cm[1]);
    }
}

$viaMigrate = defined('Migrate::TABLES') ? array_map('strtolower', array_keys(Migrate::TABLES)) : [];
$problems   = [];
foreach (Migrate::COLUMNS as $t => $cols) {
    $t = strtolower((string)$t);
    if (!isset($tables[$t])) {
        if (!in_array($t, $viaMigrate, true)) $problems[] = $t . ': table is neither in schema.sql nor in Migrate::TABLES';
        continue;
    }
    foreach (array_keys($cols) as $c) {
        if (!in_array(strtolower((string)$c), $tables[$t], true)) $problems[] = $t . '.' . $c . ': missing in schema.sql';
    }
}

echo 'schema tables: ' . count($tables) . ', Migrate::COLUMNS tables: ' . count(Migrate::COLUMNS)
    . ', problems: ' . count($problems) . PHP_EOL;
foreach ($problems as $p) echo '  - ' . $p . PHP_EOL;
exit($problems ? 1 : 0);
"""
add_file('tools/schema-check.php', CHECK)

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
    full = os.path.join(ROOT, p)
    d = os.path.dirname(full)
    if d and not os.path.isdir(d):
        os.makedirs(d)
    with io.open(full, 'w', encoding='utf-8') as fh:
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
for tag, path, mk, before, after in (('boot', BOOT, '#auto-schema-boot', 16, 4), ('inst', INST, '#install-schema', 4, 4)):
    ls = load(path).split(chr(10))
    hit = [i for i, l in enumerate(ls) if mk in l]
    if hit:
        dump(tag, path, hit[0] + 1 - before, hit[0] + 1 + after)
    else:
        print('---- %s: marker %s not found ----' % (tag, mk))

for cmd in (['php', 'tools/schema-check.php'], ['php', 'tools/integrity.php']):
    try:
        r = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, timeout=120)
        print('$ %s -> exit %d' % (' '.join(cmd), r.returncode))
        out = ((r.stdout or '') + (r.stderr or '')).strip()
        for ln in out.split(chr(10))[:40]:
            print('  ' + ln)
    except Exception as ex:
        print('$ %s -> error %s' % (' '.join(cmd), ex))

# ============================================================
# version bump
# ============================================================
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = 'تکمیل خودکار ساختار دیتابیس در پایان نصب و یک بار برای هر نسخهٔ جدید (حتی با git pull یا آپلود دستی) + ابزار tools/schema-check.php'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write(chr(10))
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
