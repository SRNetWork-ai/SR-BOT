#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed105 - admin tools for 3x-ui panels

  x3upd  -> GET  /server/getPanelUpdateInfo  (is a newer panel release out?)
  x3orph -> POST /clients/delOrphans         (drop clients with no inbound)
"""
import io, json, os, re, shutil, subprocess, sys, tempfile

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed105").strip() or "fixed105"

CACHE = {}
NEW = {}
ERRORS = []
WARN = []


def load(path):
    if path not in CACHE:
        with io.open(os.path.join(ROOT, path), encoding="utf-8") as fh:
            CACHE[path] = fh.read()
    return CACHE[path]


def rep_rx(path, pattern, fn, marker=None, expect=1, optional=False, flags=re.M):
    bag = WARN if optional else ERRORS
    try:
        s = load(path)
    except Exception as e:
        bag.append("%s: %s" % (path, e))
        return
    if marker and marker in s:
        print("skip (already applied): %s / %s" % (path, marker))
        return
    rx = re.compile(pattern, flags)
    hits = rx.findall(s)
    if len(hits) != expect:
        bag.append("%s: regex for %s matched %d times (want %d)"
                   % (path, marker or pattern[:40], len(hits), expect))
        return
    CACHE[path] = rx.sub(fn, s, count=expect)
    NEW[path] = True
    print("patched %s by regex (%s)" % (path, marker or "-"))


def write_all():
    php = shutil.which("php")
    blobs = {}
    for path in sorted(NEW):
        data = CACHE[path].encode("utf-8")
        if path.endswith(".php") and php:
            tmp = os.path.join(tempfile.gettempdir(), "syntax-check.php")
            with open(tmp, "wb") as fh:
                fh.write(data)
            r = subprocess.run([php, "-l", tmp], capture_output=True, text=True)
            if r.returncode != 0:
                print("php syntax error in %s:" % path)
                print("  " + (r.stdout + r.stderr).strip()[:400])
                sys.exit(1)
        blobs[path] = data
    for path, data in blobs.items():
        with open(os.path.join(ROOT, path), "wb") as fh:
            fh.write(data)
    print("php lint: " + ("on" if php else "php not installed - skipped"))


PANELS = "admin/pages/panels.php"

# --------------------------------------------------------------- 1) handlers
ACTION = """    /* 0.0.2 #x3-tools: ابزارهای پنل نسل جدید سنایی (3x-ui) */
    if ($act === 'x3upd' || $act === 'x3orph') {
        need('panels.edit', 'panels');
        $id = pint('id');
        $x  = Xui::forPanel($id);
        $x3 = ($x && method_exists($x, 'isXui3') && $x->isXui3()) ? $x->xui3() : null;
        if (!$x3) {
            flash('err', 'این ابزار فقط برای پنل نسل جدید سنایی (3x-ui) با توکن API کار می‌کند.');
        } elseif ($act === 'x3orph') {
            try {
                $n = $x3->delOrphans();
                flash('ok', $n > 0
                    ? ('\U0001f9f9 ' . fa_num((string)$n) . ' اکانت بی‌صاحب حذف شد.')
                    : 'اکانت بی‌صاحبی برای حذف پیدا نشد.');
            } catch (Throwable $e) {
                flash('err', 'پاک‌سازی انجام نشد: ' . h($e->getMessage()));
            }
        } else {
            try {
                $u = $x3->panelUpdateInfo();
                if (!$u) {
                    flash('err', 'دریافت اطلاعات نسخهٔ پنل ناموفق بود.');
                } elseif (!empty($u['available'])) {
                    flash('ok', '⬆️ نسخهٔ تازهٔ پنل موجود است: <code>' . h((string)$u['latest'])
                        . '</code> (نسخهٔ فعلی: <code>' . h((string)$u['current']) . '</code>)');
                } else {
                    flash('ok', '✅ پنل به‌روز است'
                        . ((string)$u['current'] !== '' ? ' (نسخهٔ <code>' . h((string)$u['current']) . '</code>)' : '') . '.');
                }
            } catch (Throwable $e) {
                flash('err', 'بررسی نسخه انجام نشد: ' . h($e->getMessage()));
            }
        }
        back('panels', ['inb' => $id]);
    }

"""


def _action(m):
    return ACTION + m.group(0)


rep_rx(
    PANELS,
    r"^    if \(\$act === 'health'\) \{$",
    _action,
    marker="0.0.2 #x3-tools",
)

# --------------------------------------------------------------------- 2) UI
UI = """        <?php /* 0.0.2 #x3-tools-ui */ if (can('panels.edit') && class_exists('Xui')
            && Xui::normType((string)($p['type'] ?? '')) === 'sanaei'): ?>
          <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="act" value="x3upd">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-sm">⬆️ نسخهٔ پنل</button>
          </form>
          <form method="post" data-confirm="اکانت‌های بی‌صاحب پنل «<?= h((string)$p['name']) ?>» حذف شوند؟"><?= csrf_field() ?>
            <input type="hidden" name="act" value="x3orph">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-sm">\U0001f9f9 پاک‌سازی بی‌صاحب‌ها</button>
          </form>
        <?php endif; ?>
"""


def _ui(m):
    return UI + m.group(0)


rep_rx(
    PANELS,
    r"^        <a class=\"btn btn-sm\" href=\"index\.php\?p=panels&inb=<\?= \(int\)\$p\['id'\] \?>\">[^\n]*</a>$",
    _ui,
    marker="0.0.2 #x3-tools-ui",
)

# ------------------------------------------------------------ sanity + write
if ERRORS:
    print("ABORTED - anchors not found:")
    for e in ERRORS:
        print("  - " + e)
    sys.exit(1)

if PANELS in NEW:
    t = CACHE[PANELS]
    for needle in ("$act === 'health'", "$act === 'toggle'", "$act === 'del'", "csrf_field()"):
        if needle not in t:
            print("ABORTED - sanity check failed for %s (%s)" % (PANELS, needle))
            sys.exit(1)
    if len(t) < 20000:
        print("ABORTED - %s shrank unexpectedly (%d chars)" % (PANELS, len(t)))
        sys.exit(1)

write_all()

if WARN:
    print("warnings (optional patches skipped):")
    for w in WARN:
        print("  - " + w)

# ---------------------------------------------------------------- version.json
VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)
v["build"] = BUILD
entry = ("\u0627\u0628\u0632\u0627\u0631\u0647\u0627\u06cc \u067e\u0646\u0644 3x-ui: "
         "\u0628\u0631\u0631\u0633\u06cc \u0646\u0633\u062e\u0647\u0654 \u067e\u0646\u0644\u060c "
         "\u067e\u0627\u06a9\u200c\u0633\u0627\u0632\u06cc \u0627\u06a9\u0627\u0646\u062a\u200c\u0647\u0627\u06cc \u0628\u06cc\u200c\u0635\u0627\u062d\u0628 \u0648 "
         "\u0646\u0645\u0627\u06cc\u0634 \u0622\u0645\u0627\u0631 \u0632\u0646\u062f\u0647\u0654 \u06a9\u0627\u0631\u0628\u0631\u0627\u0646 \u0648 "
         "\u0648\u0636\u0639\u06cc\u062a \u0645\u062d\u062f\u0648\u062f\u06cc\u062a IP \u062f\u0631 \u0635\u0641\u062d\u0647\u0654 \u0633\u0644\u0627\u0645\u062a.")
cl = v.get("changelog")
if isinstance(cl, list) and all(isinstance(x, str) for x in cl) and entry not in cl:
    cl.insert(0, entry)
    v["changelog"] = cl
    print("changelog: entry added")
with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")

print("build: " + BUILD)
print("changed files: %d" % len(NEW))
for p in sorted(NEW):
    print("  - " + p)
