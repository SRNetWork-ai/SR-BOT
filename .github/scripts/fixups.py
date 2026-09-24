# -*- coding: utf-8 -*-
# fixed148 - 0.0.2 #21: ticket priority/category UI (filters, sorting, badges, edit form).
import io, os, re, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed148').strip() or 'fixed148'

CACHE = {}
NEW = set()
ERRORS = []
WARN = []
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


def dump_find(tag, path, needle, before=2, after=25):
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
                if len(s) > 150:
                    s = s[:150] + ' ...'
                print('%s:%d: %s' % (p, i, s))
                n += 1
                if n >= limit:
                    print('---- end grep: %s (truncated) ----' % needle)
                    return
    print('---- end grep: %s (%d) ----' % (needle, n))


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


# ---------------- P1: filters, labels, sorting ----------------
OLD_A = (
    "$fStatus = (string)($_GET['status'] ?? '');\n"
    "$viewId  = (int)($_GET['t'] ?? 0);\n"
    "\n"
    "$w = $fStatus !== '' ? 'WHERE t.status = :st' : '';\n"
)

NEW_A = (
    "$fStatus = (string)($_GET['status'] ?? '');\n"
    "$viewId  = (int)($_GET['t'] ?? 0);\n"
    "\n"
    "/* 0.0.2 #21: \u0641\u06cc\u0644\u062a\u0631 \u0627\u0648\u0644\u0648\u06cc\u062a \u0648 \u062f\u0633\u062a\u0647 */\n"
    "$tkHasPrio   = !class_exists('Migrate') || Migrate::hasColumn('tickets', 'priority');\n"
    "$tkPrioLabel = ['urgent' => '\u0641\u0648\u0631\u06cc', 'high' => '\u0632\u06cc\u0627\u062f', 'normal' => '\u0639\u0627\u062f\u06cc', 'low' => '\u06a9\u0645'];\n"
    "$fPrio = $tkHasPrio ? (string)($_GET['prio'] ?? '') : '';\n"
    "if (!isset($tkPrioLabel[$fPrio])) $fPrio = '';\n"
    "\n"
    "$wh = [];\n"
    "$pw = [];\n"
    "if ($fStatus !== '') { $wh[] = 't.status = :st';   $pw[':st'] = $fStatus; }\n"
    "if ($fPrio   !== '') { $wh[] = 't.priority = :pr'; $pw[':pr'] = $fPrio; }\n"
    "$w = $wh ? ('WHERE ' . implode(' AND ', $wh)) : '';\n"
    "$ordPrio = $tkHasPrio ? \"CASE t.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'low' THEN 3 ELSE 2 END,\" : '';\n"
    "$cUrg  = $tkHasPrio ? (int)DB::val(\"SELECT COUNT(*) FROM {p}tickets WHERE priority = 'urgent' AND status <> 'closed'\", [], 0) : 0;\n"
    "$cHigh = $tkHasPrio ? (int)DB::val(\"SELECT COUNT(*) FROM {p}tickets WHERE priority = 'high' AND status <> 'closed'\", [], 0) : 0;\n"
)

rep_lit(TKP, OLD_A, NEW_A, '$tkPrioLabel = [')

# ---------------- P2: bind the new params ----------------
IND = ' ' * 17
rep_lit(
    TKP,
    IND + "$fStatus !== '' ? [':st' => $fStatus] : []);\n",
    IND + "$pw);\n",
    '$pw);',
)

# ---------------- P3: order by priority ----------------
rep_lit(
    TKP,
    IND + "$w ORDER BY (t.status = 'open') DESC, t.updated_at DESC LIMIT 200\",\n",
    IND + "$w ORDER BY (t.status = 'open') DESC, $ordPrio t.updated_at DESC LIMIT 200\",\n",
    "DESC, $ordPrio t.updated_at",
)

# ---------------- P4: priority filter chips ----------------
OLD_NAV = '<div class="tk-nav">\n'
NEW_NAV = (
    "<?php if ($tkHasPrio): $tkQs = 'index.php?p=tickets' . ($fStatus !== '' ? '&amp;status=' . urlencode($fStatus) : ''); ?>\n"
    '<div class="tk-nav">\n'
    '  <a class="tk-nv <?= $fPrio === \'\' ? \'on\' : \'\' ?>" href="<?= $tkQs ?>">\u2691 \u0647\u0645\u0647\u0654 \u0627\u0648\u0644\u0648\u06cc\u062a\u200c\u0647\u0627</a>\n'
    '  <a class="tk-nv <?= $fPrio === \'urgent\' ? \'on\' : \'\' ?>" href="<?= $tkQs ?>&amp;prio=urgent">\u26a1 \u0641\u0648\u0631\u06cc <span class="n"><?= fa_num($cUrg) ?></span></a>\n'
    '  <a class="tk-nv <?= $fPrio === \'high\' ? \'on\' : \'\' ?>" href="<?= $tkQs ?>&amp;prio=high">\u25b2 \u0632\u06cc\u0627\u062f <span class="n"><?= fa_num($cHigh) ?></span></a>\n'
    '  <a class="tk-nv <?= $fPrio === \'normal\' ? \'on\' : \'\' ?>" href="<?= $tkQs ?>&amp;prio=normal">\u0639\u0627\u062f\u06cc</a>\n'
    '  <a class="tk-nv <?= $fPrio === \'low\' ? \'on\' : \'\' ?>" href="<?= $tkQs ?>&amp;prio=low">\u06a9\u0645</a>\n'
    '</div>\n'
    '<?php endif; ?>\n'
    '\n'
    '<div class="tk-nav">\n'
)
rep_lit(TKP, OLD_NAV, NEW_NAV, 'prio=urgent', optional=True)

# ---------------- P5: card badges ----------------
OLD_TAGS = '        <div class="tk-tags">\n'
NEW_TAGS = (
    '        <div class="tk-tags">\n'
    "          <?php $pr = (string)($t['priority'] ?? 'normal'); if ($pr !== '' && $pr !== 'normal' && isset($tkPrioLabel[$pr])): ?>\n"
    '            <span class="tk-tag" style="<?= $pr === \'urgent\' ? \'background:#fee2e2;color:#b91c1c\' : ($pr === \'high\' ? \'background:#ffedd5;color:#c2410c\' : \'\') ?>">\u26a1 <?= h($tkPrioLabel[$pr]) ?></span>\n'
    '          <?php endif; ?>\n'
    "          <?php $ct = trim((string)($t['category'] ?? '')); if ($ct !== ''): ?><span class=\"tk-tag\">\u25a6 <?= h($ct) ?></span><?php endif; ?>\n"
)
rep_lit(TKP, OLD_TAGS, NEW_TAGS, "$t['priority'] ?? 'normal'", optional=True)

# ---------------- P6: edit form in the ticket view ----------------
OLD_F = (
    '    <div class="tk-reply" style="border-top:1px solid var(--border-soft);background:var(--surface-2)">\n'
    '      <div class="row" style="flex-wrap:wrap;gap:6px">\n'
)
STY = 'padding:6px 8px;border-radius:8px;border:1px solid var(--border-soft);background:var(--surface-1)'
NEW_F = OLD_F + (
    "        <?php if ($tkHasPrio && can('tickets.reply')): ?>\n"
    '          <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0"><?= csrf_field() ?>\n'
    '            <input type="hidden" name="act" value="tkmeta"><input type="hidden" name="id" value="<?= (int)$T[\'id\'] ?>">\n'
    '            <select name="priority" style="' + STY + '">\n'
    '              <?php foreach ($tkPrioLabel as $pk => $plb): ?>\n'
    "                <option value=\"<?= h($pk) ?>\"<?= (string)($T['priority'] ?? 'normal') === $pk ? ' selected' : '' ?>>\u0627\u0648\u0644\u0648\u06cc\u062a: <?= h($plb) ?></option>\n"
    '              <?php endforeach; ?>\n'
    '            </select>\n'
    '            <input type="text" name="category" maxlength="32" placeholder="\u062f\u0633\u062a\u0647 (\u0627\u062e\u062a\u06cc\u0627\u0631\u06cc)" value="<?= h((string)($T[\'category\'] ?? \'\')) ?>" style="' + STY + ';max-width:150px">\n'
    '            <button class="btn btn-sm">\u062b\u0628\u062a \u0627\u0648\u0644\u0648\u06cc\u062a</button>\n'
    '          </form>\n'
    '        <?php endif; ?>\n'
)
rep_lit(TKP, OLD_F, NEW_F, 'name="act" value="tkmeta"><input')

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
    (TKP, '$tkPrioLabel = ['),
    (TKP, '$pw);'),
    (TKP, "DESC, $ordPrio t.updated_at"),
    (TKP, 'name="act" value="tkmeta"><input'),
    (TKP, 'prio=urgent'),
    (TKP, "$t['priority'] ?? 'normal'"),
]
ok = 0
for p, needle in SANITY:
    good = needle in load(p)
    print('sanity %s / %s : %s' % (p, needle[:44], 'ok' if good else 'MISSING'))
    if good:
        ok += 1
print('sanity: %d/%d' % (ok, len(SANITY)))

# ---------------- recon: how admin pages register (row 24 reports) ----------------
print('===== admin router / menu =====')
dump_find('router', 'admin/index.php', "pages/", 6, 22)
grep_all("'tickets'", 25, only='admin/index.php')
grep_all("'dashboard' =>", 12, only='admin/')

# ---------------- version bump ----------------
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = '0.0.2 #21: \u0641\u06cc\u0644\u062a\u0631 \u0648 \u0646\u0645\u0627\u06cc\u0634 \u0627\u0648\u0644\u0648\u06cc\u062a/\u062f\u0633\u062a\u0647\u0654 \u062a\u06cc\u06a9\u062a \u062f\u0631 \u067e\u0646\u0644'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
