#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed96 - 0.0.2 batch #11

  * backup page: the auto password checkbox (missed last run because the
    marker string was created by the save patch in the same run)
  * recon: public api of every panel driver + how Svc.php picks a driver,
    needed for row 16 (Hiddify / Marzneshin support)
"""
import io, json, os, re, shutil, subprocess, sys, tempfile

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed96").strip() or "fixed96"

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


def grep(tag, path, pattern, limit=25):
    print("== %s (%s ~ %s) ==" % (tag, path, pattern))
    try:
        lines = load(path).splitlines()
    except Exception as e:
        print("  missing: " + str(e))
        return
    n = 0
    for i, line in enumerate(lines, 1):
        if re.search(pattern, line):
            print("  %d: %s" % (i, line.strip()[:112]))
            n += 1
            if n >= limit:
                print("  ... (limit)")
                break
    if n == 0:
        print("  (no match)")


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


# ============================== 1) backup auto password checkbox (row 3)
BK = "admin/pages/backup.php"


def _bk_ui(m):
    i = m.group(1)
    return (
        m.group(0) + "\n"
        + i + "<!-- 0.0.2 #3-autopass-box -->\n"
        + i + "<label style=\"display:block;margin-top:6px\">\n"
        + i + "  <input type=\"checkbox\" name=\"backup_autopass\" value=\"1\" <?= ((string)$S('backup_autopass', '1') === '1' ? 'checked' : '') ?>>\n"
        + i + "  \u0633\u0627\u062e\u062a \u062e\u0648\u062f\u06a9\u0627\u0631 \u0631\u0645\u0632 \u0642\u0648\u06cc \u0648\u0642\u062a\u06cc \u0627\u06cc\u0646 \u0641\u06cc\u0644\u062f \u062e\u0627\u0644\u06cc \u0627\u0633\u062a (\u0631\u0645\u0632 \u062f\u0631 \u062a\u0627\u067e\u06cc\u06a9 \u0628\u06a9\u0627\u067e \u0627\u0631\u0633\u0627\u0644 \u0645\u06cc\u200c\u0634\u0648\u062f)\n"
        + i + "</label>"
    )


rep_rx(
    BK,
    r"^([ \t]*)<input class=\"mono\" type=\"text\" name=\"backup_pass\"[^\n]*$",
    _bk_ui,
    marker="0.0.2 #3-autopass-box",
    optional=True,
)

# ==================================================== 2) sanity check + write
if ERRORS:
    print("ABORTED - anchors not found:")
    for e in ERRORS:
        print("  - " + e)
    sys.exit(1)

if BK in NEW:
    t = CACHE[BK]
    if len(t) < 8000 or "backup_pass" not in t:
        print("ABORTED - sanity check failed for %s (%d chars)" % (BK, len(t)))
        sys.exit(1)

write_all()

if WARN:
    print("warnings (optional patches skipped):")
    for w in WARN:
        print("  - " + w)

# ============================= 3) recon for row 16 (new panel types)
grep("XUI API", "app/Panel/Xui.php", r"public function |public const |class ", 34)
grep("XUI3 API", "app/Panel/Xui3.php", r"public function |class ", 26)
grep("MARZBAN API", "app/Panel/Marzban.php", r"public function |class ", 26)
grep("PASARGUARD API", "app/Panel/PasarGuard.php", r"public function |class ", 26)
grep("SVC DISPATCH", "app/Service/Svc.php", r"\$panel\['type'\]|\['type'\]\s*===|Marzban|PasarGuard|Xui3|function driver", 26)
grep("PANELS PAGE", "admin/pages/panels.php", r"option value|'type'|panel_type", 24)

# ================================================================ 4) version
VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)
v["build"] = BUILD
with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")

print("build: " + BUILD)
print("changed files: %d" % len(NEW))
for p in sorted(NEW):
    print("  - " + p)
