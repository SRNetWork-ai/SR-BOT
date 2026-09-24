# -*- coding: utf-8 -*-
# fixed145 - 0.0.2 #33: performance indexes (Migrate::INDEXES) with column/dup guards.
import io, os, re, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed145').strip() or 'fixed145'

CACHE = {}
NEW = set()
ERRORS = []
MIG = 'app/Service/Migrate.php'


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8', errors='replace') as fh:
        CACHE[path] = fh.read()
    return CACHE[path]


def dump(tag, path, start, end):
    try:
        lines = load(path).split(chr(10))
    except Exception as e:
        print('---- dump %s FAILED (%s) ----' % (tag, e))
        return
    print('---- dump %s : %s ----' % (tag, path))
    for i in range(max(1, start), min(len(lines), end) + 1):
        s = lines[i - 1]
        if len(s) > 200:
            s = s[:200] + ' ...'
        print('%5d %s' % (i, s))
    print('---- end dump %s ----' % tag)


def dump_find(tag, path, needle, before=1, after=30):
    try:
        lines = load(path).split(chr(10))
    except Exception as e:
        print('---- dump %s FAILED (%s) ----' % (tag, e))
        return
    for i, ln in enumerate(lines, 1):
        if needle in ln:
            dump(tag, path, i - before, i + after)
            return
    print('---- dump %s : needle not found (%s) ----' % (tag, needle))


def rep_lit(path, old, new, marker):
    src = load(path)
    if marker in src:
        print('skip (already applied): %s' % marker)
        return
    n = src.count(old)
    if n != 1:
        ERRORS.append('%s: anchor for %s matched %d times (want 1)' % (path, marker, n))
        return
    CACHE[path] = src.replace(old, new)
    NEW.add(path)
    print('patched: %s (%s)' % (path, marker))


# ---------------- P1: expand the INDEXES map ----------------
OLD_IDX = (
    "    public const INDEXES = [\n"
    "        'transactions' => [\n"
    "            'idx_txid' => '(`txid`)',\n"
    "        ],\n"
    "    ];\n"
)

NEW_IDX = (
    "    public const INDEXES = [\n"
    "        /* 0.0.2 #33: \u0627\u06cc\u0646\u062f\u06a9\u0633\u200c\u0647\u0627\u06cc \u06a9\u0627\u0631\u0627\u06cc\u06cc \u2014 \u067e\u06cc\u0634 \u0627\u0632 \u0633\u0627\u062e\u062a\u060c \u0648\u062c\u0648\u062f \u0633\u062a\u0648\u0646 \u0648 \u0627\u06cc\u0646\u062f\u06a9\u0633 \u0645\u0634\u0627\u0628\u0647 \u0628\u0631\u0631\u0633\u06cc \u0645\u06cc\u200c\u0634\u0648\u062f */\n"
    "        'transactions' => [\n"
    "            'idx_txid'       => '(`txid`)',\n"
    "            'idx_tx_user'    => '(`user_id`)',\n"
    "            'idx_tx_status'  => '(`status`)',\n"
    "            'idx_tx_created' => '(`created_at`)',\n"
    "        ],\n"
    "        'services' => [\n"
    "            'idx_sv_user'    => '(`user_id`)',\n"
    "            'idx_sv_status'  => '(`status`)',\n"
    "            'idx_sv_expire'  => '(`expire_at`)',\n"
    "            'idx_sv_panel'   => '(`panel_id`)',\n"
    "            'idx_sv_test'    => '(`is_test`)',\n"
    "        ],\n"
    "        'orders' => [\n"
    "            'idx_or_user'    => '(`user_id`)',\n"
    "            'idx_or_status'  => '(`status`)',\n"
    "            'idx_or_created' => '(`created_at`)',\n"
    "        ],\n"
    "        'users' => [\n"
    "            'idx_us_tg'      => '(`tg_id`)',\n"
    "            'idx_us_created' => '(`created_at`)',\n"
    "        ],\n"
    "        'tickets' => [\n"
    "            'idx_tk_user'    => '(`user_id`)',\n"
    "            'idx_tk_updated' => '(`updated_at`)',\n"
    "        ],\n"
    "        'ticket_messages' => [\n"
    "            'idx_tm_created' => '(`created_at`)',\n"
    "        ],\n"
    "        'discount_uses' => [\n"
    "            'idx_du_user'    => '(`user_id`)',\n"
    "        ],\n"
    "        'stock_items' => [\n"
    "            'idx_si_order'   => '(`order_id`)',\n"
    "        ],\n"
    "    ];\n"
)

rep_lit(MIG, OLD_IDX, NEW_IDX, 'idx_sv_user')

# ---------------- P2: helper methods ----------------
OLD_HAS = (
    "    public static function hasIndex(string $table, string $idx): bool\n"
    "    {\n"
    "        return (int)DB::val(\n"
    "            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',\n"
    "            [DB::prefix() . $table, $idx], 0\n"
    "        ) > 0;\n"
    "    }\n"
)

NEW_HAS = OLD_HAS + (
    "\n"
    "    /** 0.0.2 #33: \u0622\u06cc\u0627 \u0627\u06cc\u0646\u062f\u06a9\u0633\u06cc \u0648\u062c\u0648\u062f \u062f\u0627\u0631\u062f \u06a9\u0647 \u0628\u0627 \u0627\u06cc\u0646 \u0633\u062a\u0648\u0646 \u0634\u0631\u0648\u0639 \u0634\u0648\u062f\u061f */\n"
    "    public static function hasIndexOn(string $table, string $col): bool\n"
    "    {\n"
    "        try {\n"
    "            return (int)DB::val(\n"
    "                'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1',\n"
    "                [DB::prefix() . $table, $col], 0\n"
    "            ) > 0;\n"
    "        } catch (Throwable $e) { return false; }\n"
    "    }\n"
    "\n"
    "    /** \u0646\u0627\u0645 \u0633\u062a\u0648\u0646\u200c\u0647\u0627\u06cc \u062f\u0627\u062e\u0644 \u062a\u0639\u0631\u06cc\u0641 \u0627\u06cc\u0646\u062f\u06a9\u0633 */\n"
    "    public static function indexCols(string $def): array\n"
    "    {\n"
    "        if (!preg_match_all('/`([A-Za-z0-9_]+)`/', $def, $m)) return [];\n"
    "        return $m[1];\n"
    "    }\n"
    "\n"
    "    /** \u0627\u06cc\u0646\u062f\u06a9\u0633 \u0641\u0642\u0637 \u0648\u0642\u062a\u06cc \u0633\u0627\u062e\u062a\u0647 \u0645\u06cc\u200c\u0634\u0648\u062f \u06a9\u0647 \u0633\u062a\u0648\u0646\u200c\u0647\u0627\u06cc\u0634 \u0645\u0648\u062c\u0648\u062f \u0628\u0627\u0634\u0646\u062f \u0648 \u0627\u06cc\u0646\u062f\u06a9\u0633 \u0645\u0634\u0627\u0628\u0647 \u0646\u0628\u0627\u0634\u062f */\n"
    "    public static function indexReady(string $table, string $def): bool\n"
    "    {\n"
    "        $cols = self::indexCols($def);\n"
    "        if (!$cols) return false;\n"
    "        foreach ($cols as $c) {\n"
    "            if (!self::hasColumn($table, $c)) return false;\n"
    "        }\n"
    "        return !self::hasIndexOn($table, (string)$cols[0]);\n"
    "    }\n"
)

rep_lit(MIG, OLD_HAS, NEW_HAS, 'indexReady(string $table')

# ---------------- P3: plan() guard ----------------
OLD_PLAN = (
    "                foreach ($ixs as $i => $cols) {\n"
    "                    if (!self::hasIndex($t, $i)) $miss['indexes'][] = $t . '.' . $i;\n"
    "                }\n"
)
NEW_PLAN = (
    "                foreach ($ixs as $i => $cols) {\n"
    "                    if (self::hasIndex($t, $i)) continue;\n"
    "                    if (!self::indexReady($t, (string)$cols)) continue; /* 0.0.2 #33 */\n"
    "                    $miss['indexes'][] = $t . '.' . $i;\n"
    "                }\n"
)
rep_lit(MIG, OLD_PLAN, NEW_PLAN, 'indexReady($t, (string)$cols)) continue; /* 0.0.2 #33 */\n                    $miss')

# ---------------- P4: run() guard ----------------
OLD_RUN = (
    "            foreach ($ixs as $i => $cols) {\n"
    "                if (self::hasIndex($t, $i)) continue;\n"
)
NEW_RUN = (
    "            foreach ($ixs as $i => $cols) {\n"
    "                if (self::hasIndex($t, $i)) continue;\n"
    "                if (!self::indexReady($t, (string)$cols)) continue; /* 0.0.2 #33 */\n"
)
rep_lit(MIG, OLD_RUN, NEW_RUN, 'indexReady($t, (string)$cols)) continue; /* 0.0.2 #33 */\n                $exec')

# ---------------- write ----------------
if ERRORS:
    print('ABORTED - anchors not found:')
    for e in ERRORS:
        print(' - ' + e)
    print('exit: 1')
    sys.exit(1)

for p in sorted(NEW):
    with io.open(os.path.join(ROOT, p), 'w', encoding='utf-8') as fh:
        fh.write(CACHE[p])
    print('wrote ' + p)
print('changed files: %d' % len(NEW))

# ---------------- sanity ----------------
SANITY = [
    (MIG, 'public static function indexReady(string $table, string $def): bool'),
    (MIG, 'public static function hasIndexOn(string $table, string $col): bool'),
    (MIG, "'idx_sv_user'"),
    (MIG, "'idx_or_created'"),
]
ok = 0
for p, needle in SANITY:
    good = needle in load(p)
    print('sanity %s / %s : %s' % (p, needle[:48], 'ok' if good else 'MISSING'))
    if good:
        ok += 1
print('sanity: %d/%d' % (ok, len(SANITY)))

# ---------------- recon for next batch ----------------
print('===== schema columns (index validation + next batch) =====')
for t in ['{p}services (', '{p}orders (', '{p}transactions (', '{p}users (', '{p}logs (', '{p}discount_uses (']:
    dump_find('tbl' + t, 'database/schema.sql', t, 0, 26)

# ---------------- version bump ----------------
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = '0.0.2 #33: \u0627\u06cc\u0646\u062f\u06a9\u0633\u200c\u0647\u0627\u06cc \u06a9\u0627\u0631\u0627\u06cc\u06cc \u062f\u06cc\u062a\u0627\u0628\u06cc\u0633 (\u062a\u0631\u0627\u06a9\u0646\u0634\u060c \u0633\u0631\u0648\u06cc\u0633\u060c \u0633\u0641\u0627\u0631\u0634\u060c \u06a9\u0627\u0631\u0628\u0631\u060c \u062a\u06cc\u06a9\u062a) \u0628\u0627 \u0645\u062d\u0627\u0641\u0638 \u0636\u062f \u062e\u0637\u0627 + \u062a\u0633\u062a \u062e\u0648\u062f\u06a9\u0627\u0631 tests/'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
