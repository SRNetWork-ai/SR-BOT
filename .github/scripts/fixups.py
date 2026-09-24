# -*- coding: utf-8 -*-
# fixed146 - 0.0.2 #21: ticket priority/category/admin/closed_at (DB layer) + UI recon.
import io, os, re, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed146').strip() or 'fixed146'

CACHE = {}
NEW = set()
ERRORS = []
SCH = 'database/schema.sql'
MIG = 'app/Service/Migrate.php'
TKP = 'admin/pages/tickets.php'

SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json')


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
    print('---- dump %s : %s (%d lines) ----' % (tag, path, len(lines)))
    for i in range(max(1, start), min(len(lines), end) + 1):
        s = lines[i - 1]
        if len(s) > 200:
            s = s[:200] + ' ...'
        print('%5d %s' % (i, s))
    print('---- end dump %s ----' % tag)


def dump_find(tag, path, needle, before=2, after=30):
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


def grep_all(needle, limit=40, only=None):
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
                if len(s) > 150:
                    s = s[:150] + ' ...'
                print('%s:%d: %s' % (p, i, s))
                n += 1
                if n >= limit:
                    print('---- end grep: %s (truncated) ----' % needle)
                    return
    print('---- end grep: %s (%d) ----' % (needle, n))


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


# ---------------- P1: schema.sql - new ticket columns ----------------
OLD_SCH = (
    "  `subject` VARCHAR(190) NULL,\n"
    "  `status` VARCHAR(16) NOT NULL DEFAULT 'open',\n"
    "  `created_at` DATETIME NOT NULL,\n"
    "  `updated_at` DATETIME NOT NULL,\n"
    "  KEY `idx_status` (`status`)\n"
)
NEW_SCH = (
    "  `subject` VARCHAR(190) NULL,\n"
    "  `status` VARCHAR(16) NOT NULL DEFAULT 'open',\n"
    "  `priority` VARCHAR(8) NOT NULL DEFAULT 'normal',\n"
    "  `category` VARCHAR(32) NULL,\n"
    "  `admin_id` INT NULL,\n"
    "  `closed_at` DATETIME NULL,\n"
    "  `created_at` DATETIME NOT NULL,\n"
    "  `updated_at` DATETIME NOT NULL,\n"
    "  KEY `idx_status` (`status`),\n"
    "  KEY `idx_tk_priority` (`priority`)\n"
)
rep_lit(SCH, OLD_SCH, NEW_SCH, 'idx_tk_priority')

# ---------------- P2: Migrate::COLUMNS - tickets ----------------
OLD_MIG = (
    "        'ticket_messages' => [\n"
    "            'file_type' => 'VARCHAR(16) NULL',\n"
    "        ],\n"
)
NEW_MIG = (
    "        'tickets' => [\n"
    "            /* 0.0.2 #21: \u067e\u0634\u062a\u06cc\u0628\u0627\u0646\u06cc \u062d\u0631\u0641\u0647\u200c\u0627\u06cc \u2014 \u0627\u0648\u0644\u0648\u06cc\u062a\u060c \u062f\u0633\u062a\u0647\u200c\u0628\u0646\u062f\u06cc \u0648 \u0645\u062f\u06cc\u0631 \u0645\u0633\u0626\u0648\u0644 */\n"
    "            'priority'  => \"VARCHAR(8) NOT NULL DEFAULT 'normal'\",\n"
    "            'category'  => 'VARCHAR(32) NULL',\n"
    "            'admin_id'  => 'INT NULL',\n"
    "            'closed_at' => 'DATETIME NULL',\n"
    "        ],\n"
    "        'ticket_messages' => [\n"
    "            'file_type' => 'VARCHAR(16) NULL',\n"
    "        ],\n"
)
rep_lit(MIG, OLD_MIG, NEW_MIG, "'closed_at' => 'DATETIME NULL',")

# ---------------- P3: index for the new column ----------------
OLD_IX = (
    "        'tickets' => [\n"
    "            'idx_tk_user'    => '(`user_id`)',\n"
    "            'idx_tk_updated' => '(`updated_at`)',\n"
    "        ],\n"
)
NEW_IX = (
    "        'tickets' => [\n"
    "            'idx_tk_user'     => '(`user_id`)',\n"
    "            'idx_tk_updated'  => '(`updated_at`)',\n"
    "            'idx_tk_priority' => '(`priority`)',\n"
    "        ],\n"
)
rep_lit(MIG, OLD_IX, NEW_IX, "'idx_tk_priority' => '(`priority`)'")

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

SANITY = [
    (SCH, "`priority` VARCHAR(8) NOT NULL DEFAULT 'normal'"),
    (SCH, 'KEY `idx_tk_priority` (`priority`)'),
    (MIG, "'closed_at' => 'DATETIME NULL',"),
    (MIG, "'idx_tk_priority' => '(`priority`)',"),
]
ok = 0
for p, needle in SANITY:
    good = needle in load(p)
    print('sanity %s / %s : %s' % (p, needle[:46], 'ok' if good else 'MISSING'))
    if good:
        ok += 1
print('sanity: %d/%d' % (ok, len(SANITY)))

# ---------------- recon: ticket UI + bot flow ----------------
print('===== admin tickets page =====')
dump('tk_head', TKP, 1, 120)
grep_all("status", 40, only=TKP)
grep_all("tk_", 40, only=TKP)

print('===== bot ticket flow =====')
grep_all('ticket', 45, only='app/Bot/')
grep_all('Tickets::', 25)

# ---------------- version bump ----------------
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = '0.0.2 #21: \u0633\u062a\u0648\u0646\u200c\u0647\u0627\u06cc \u0627\u0648\u0644\u0648\u06cc\u062a\u060c \u062f\u0633\u062a\u0647 \u0648 \u0645\u062f\u06cc\u0631 \u0645\u0633\u0626\u0648\u0644 \u0628\u0631\u0627\u06cc \u062a\u06cc\u06a9\u062a\u200c\u0647\u0627 (\u0644\u0627\u06cc\u0647\u0654 \u062f\u06cc\u062a\u0627\u0628\u06cc\u0633)'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
