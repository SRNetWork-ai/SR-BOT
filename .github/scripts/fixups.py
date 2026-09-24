# -*- coding: utf-8 -*-
# fixed147 - 0.0.2 #21: ticket priority/category POST handler + UI recon (filters, list, bot).
import io, os, re, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed147').strip() or 'fixed147'

CACHE = {}
NEW = set()
ERRORS = []
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


def grep_all(needle, limit=30, only=None):
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


def listdir(rel):
    d = os.path.join(ROOT, rel)
    print('---- listdir %s ----' % rel)
    if not os.path.isdir(d):
        print('(missing)')
        return
    for fn in sorted(os.listdir(d)):
        p = os.path.join(d, fn)
        print('%-34s %s' % (fn, os.path.getsize(p) if os.path.isfile(p) else '<dir>'))
    print('---- end listdir %s ----' % rel)


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


# ---------------- P1: priority / category POST handler ----------------
OLD = (
    "if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {\n"
    "    $id = pint('id');\n"
    "    $tk = $id ? DB::one('SELECT t.*, u.tg_id AS utg, u.first_name FROM {p}tickets t LEFT JOIN {p}users u ON u.id = t.user_id WHERE t.id = :id', [':id' => $id]) : null;\n"
)

NEW_H = OLD + (
    "\n"
    "    /* 0.0.2 #21: \u0627\u0648\u0644\u0648\u06cc\u062a \u0648 \u062f\u0633\u062a\u0647\u0654 \u062a\u06cc\u06a9\u062a */\n"
    "    if ($tk && $act === 'tkmeta') {\n"
    "        need('tickets.reply', 'tickets');\n"
    "        $pr  = (string)($_POST['priority'] ?? 'normal');\n"
    "        $cat = trim((string)($_POST['category'] ?? ''));\n"
    "        if (!in_array($pr, ['low', 'normal', 'high', 'urgent'], true)) $pr = 'normal';\n"
    "        if (function_exists('mb_substr') && mb_strlen($cat, 'UTF-8') > 32) $cat = mb_substr($cat, 0, 32, 'UTF-8');\n"
    "        $hasPr  = !class_exists('Migrate') || Migrate::hasColumn('tickets', 'priority');\n"
    "        $hasCat = !class_exists('Migrate') || Migrate::hasColumn('tickets', 'category');\n"
    "        $up = ['updated_at' => now()];\n"
    "        if ($hasPr)  $up['priority'] = $pr;\n"
    "        if ($hasCat) $up['category'] = ($cat !== '' ? $cat : null);\n"
    "        if (count($up) > 1) {\n"
    "            DB::update('tickets', $up, 'id = :id', [':id' => $id]);\n"
    "            flash('ok', '\u2705 \u0627\u0648\u0644\u0648\u06cc\u062a/\u062f\u0633\u062a\u0647\u0654 \u062a\u06cc\u06a9\u062a \u0628\u0647\u200c\u0631\u0648\u0632 \u0634\u062f.');\n"
    "        } else {\n"
    "            flash('warn', '\u26a0\ufe0f \u0627\u0628\u062a\u062f\u0627 \u0628\u0647\u200c\u0631\u0648\u0632\u0631\u0633\u0627\u0646\u06cc \u062f\u06cc\u062a\u0627\u0628\u06cc\u0633 \u0631\u0627 \u0627\u062c\u0631\u0627 \u06a9\u0646\u06cc\u062f.');\n"
    "        }\n"
    "        back('tickets', ['t' => $id]);\n"
    "    }\n"
)

rep_lit(TKP, OLD, NEW_H, "$act === 'tkmeta'")

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

SANITY = [(TKP, "$act === 'tkmeta'"), (TKP, "'low', 'normal', 'high', 'urgent'")]
ok = 0
for p, needle in SANITY:
    good = needle in load(p)
    print('sanity %s / %s : %s' % (p, needle[:44], 'ok' if good else 'MISSING'))
    if good:
        ok += 1
print('sanity: %d/%d' % (ok, len(SANITY)))

# ---------------- recon: tickets UI ----------------
dump('tk_filters', TKP, 160, 215)
dump('tk_head_view', TKP, 440, 470)
dump('tk_nav', TKP, 545, 575)
dump('tk_list', TKP, 585, 660)

print('===== admin pages + nav =====')
listdir('admin/pages')
grep_all('p=tickets', 12, only='admin/')

print('===== row 19 (trial abuse) anchors =====')
grep_all('test_count', 20)
grep_all("'is_test'", 20)

# ---------------- version bump ----------------
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = '0.0.2 #21: \u062b\u0628\u062a \u0627\u0648\u0644\u0648\u06cc\u062a \u0648 \u062f\u0633\u062a\u0647\u0654 \u062a\u06cc\u06a9\u062a \u0627\u0632 \u067e\u0646\u0644 \u0645\u062f\u06cc\u0631\u06cc\u062a'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
