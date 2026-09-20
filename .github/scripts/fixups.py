#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed95 - 0.0.2 batch #10

  * every admin upload form now goes through the Upload guard (row 10)
  * backup page gets the auto password switch (row 3 leftover)
  * recon: how sub.php resolves its ?id= key (row 9 / IDOR)

All patches in this batch are optional: a missing anchor prints a warning
instead of aborting the whole run.
"""
import io, json, os, re, shutil, subprocess, sys, tempfile

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed95").strip() or "fixed95"

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
    """regex patch - used when the indentation of the anchor is unknown"""
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
                break
    if n == 0:
        print("  (no match)")


def around(tag, path, needle, before=3, after=30):
    print("== %s (%s ~ %s) ==" % (tag, path, needle))
    try:
        lines = load(path).splitlines()
    except Exception as e:
        print("  missing: " + str(e))
        return
    idx = -1
    for i, line in enumerate(lines):
        if needle in line:
            idx = i
            break
    if idx < 0:
        print("  (needle not found)")
        return
    lo = max(0, idx - before)
    hi = min(len(lines), idx + after)
    for i in range(lo, hi):
        print("  %d: %s" % (i + 1, lines[i][:118]))


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


# =========================================== 1) ticket attachments (row 10)
TIC = "admin/pages/tickets.php"


def _tic_cond(m):
    i = m.group(1)
    return (
        i + "/* 0.0.2 #10-ticket-att: \u0641\u0627\u06cc\u0644 \u067e\u06cc\u0648\u0633\u062a \u0627\u0632 \u0641\u06cc\u0644\u062a\u0631 \u0627\u0645\u0646\u06cc\u062a\u06cc \u0631\u062f \u0634\u0648\u062f */\n"
        + i + "if (!empty($_FILES['att']['tmp_name']) && is_uploaded_file($_FILES['att']['tmp_name'])\n"
        + i + "    && (!class_exists('Upload') || Upload::check((array)$_FILES['att'], 20 * 1024 * 1024)['ok'])) {"
    )


rep_rx(
    TIC,
    r"^([ \t]*)if \(!empty\(\$_FILES\['att'\]\['tmp_name'\]\) && is_uploaded_file\(\$_FILES\['att'\]\['tmp_name'\]\)\) \{[ \t]*$",
    _tic_cond,
    marker="0.0.2 #10-ticket-att",
    optional=True,
)


def _tic_name(m):
    i = m.group(1)
    return (
        i + "/* 0.0.2 #10-ticket-name */\n"
        + i + "$safe = class_exists('Upload')\n"
        + i + "    ? Upload::safeName((string)($_FILES['att']['name'] ?? 'file'))\n"
        + i + "    : (" + m.group(2) + ");"
    )


rep_rx(
    TIC,
    r"^([ \t]*)\$safe\s*=\s*(preg_replace\([^\n]*\$_FILES\['att'\]\['name'\][^\n]*?)\s*;[ \t]*$",
    _tic_name,
    marker="0.0.2 #10-ticket-name",
    optional=True,
)

# ======================================== 2) json imports of bot texts/buttons


def _txt_cond(m):
    i = m.group(1)
    return (
        i + "/* 0.0.2 #10-json-text */\n"
        + i + "if (!empty($_FILES['jfile']['tmp_name']) && is_uploaded_file($_FILES['jfile']['tmp_name'])\n"
        + i + "    && (!class_exists('Upload') || Upload::check((array)$_FILES['jfile'], 4 * 1024 * 1024, ['json', 'txt'])['ok'])) {"
    )


rep_rx(
    "admin/pages/bottexts.php",
    r"^([ \t]*)if \(!empty\(\$_FILES\['jfile'\]\['tmp_name'\]\) && is_uploaded_file\(\$_FILES\['jfile'\]\['tmp_name'\]\)\) \{[ \t]*$",
    _txt_cond,
    marker="0.0.2 #10-json-text",
    optional=True,
)


def _btn_tmp(m):
    i = m.group(1)
    return (
        m.group(0) + "\n"
        + i + "/* 0.0.2 #10-json-btn */\n"
        + i + "if ($tmp !== '' && class_exists('Upload')\n"
        + i + "    && !Upload::check((array)($_FILES['jfile'] ?? []), 4 * 1024 * 1024, ['json', 'txt'])['ok']) $tmp = '';"
    )


rep_rx(
    "admin/pages/botbuttons.php",
    r"^([ \t]*)\$tmp\s*=\s*\(string\)\(\$_FILES\['jfile'\]\['tmp_name'\] \?\? ''\);[ \t]*$",
    _btn_tmp,
    marker="0.0.2 #10-json-btn",
    optional=True,
)

# ================================== 3) update zip and stock csv (row 10)


def _zip_guard(m):
    i = m.group(1)
    return (
        m.group(0) + "\n"
        + i + "/* 0.0.2 #10-update-zip */\n"
        + i + "if ($f && class_exists('Upload') && !Upload::check($f, 200 * 1024 * 1024, ['zip'])['ok']) $f = [];"
    )


rep_rx(
    "admin/pages/update.php",
    r"^([ \t]*)\$f\s*=\s*\(array\)\(\$_FILES\['zip'\] \?\? \[\]\);[ \t]*$",
    _zip_guard,
    marker="0.0.2 #10-update-zip",
    optional=True,
)


def _stock_guard(m):
    i = m.group(1)
    return (
        m.group(0) + "\n"
        + i + "/* 0.0.2 #10-stock-file */\n"
        + i + "if ($f && class_exists('Upload') && !Upload::check($f, 16 * 1024 * 1024, ['csv', 'txt', 'json'])['ok']) $f = [];"
    )


rep_rx(
    "admin/pages/stock.php",
    r"^([ \t]*)\$f\s*=\s*\(array\)\(\$_FILES\['file'\] \?\? \[\]\);[ \t]*$",
    _stock_guard,
    marker="0.0.2 #10-stock-file",
    optional=True,
)

# ============================= 4) backup auto password switch (row 3 leftover)
BK = "admin/pages/backup.php"


def _bk_save(m):
    i = m.group(1)
    return (
        m.group(0) + "\n"
        + i + "/* 0.0.2 #3-autopass-ui */\n"
        + i + "DB::setSetting('backup_autopass', isset($_POST['backup_autopass']) ? '1' : '0');"
    )


rep_rx(
    BK,
    r"^([ \t]*)DB::setSetting\('backup_pass',[^\n]*\);[ \t]*$",
    _bk_save,
    marker="0.0.2 #3-autopass-ui",
    optional=True,
)


def _bk_ui(m):
    i = m.group(1)
    return (
        m.group(0) + "\n"
        + i + "<label style=\"display:block;margin-top:6px\">\n"
        + i + "  <input type=\"checkbox\" name=\"backup_autopass\" value=\"1\" <?= ((string)$S('backup_autopass', '1') === '1' ? 'checked' : '') ?>>\n"
        + i + "  \u0633\u0627\u062e\u062a \u062e\u0648\u062f\u06a9\u0627\u0631 \u0631\u0645\u0632 \u0642\u0648\u06cc \u0648\u0642\u062a\u06cc \u0627\u06cc\u0646 \u0641\u06cc\u0644\u062f \u062e\u0627\u0644\u06cc \u0628\u0627\u0634\u062f (\u0631\u0645\u0632 \u062f\u0631 \u062a\u0627\u067e\u06cc\u06a9 \u0628\u06a9\u0627\u067e \u0627\u0631\u0633\u0627\u0644 \u0645\u06cc\u200c\u0634\u0648\u062f)\n"
        + i + "</label>"
    )


rep_rx(
    BK,
    r"^([ \t]*)<input class=\"mono\" type=\"text\" name=\"backup_pass\"[^\n]*$",
    _bk_ui,
    marker="backup_autopass",
    optional=True,
)

# ==================================================== 5) sanity check + write
if ERRORS:
    print("ABORTED - anchors not found:")
    for e in ERRORS:
        print("  - " + e)
    sys.exit(1)

SANITY = {
    TIC: (4000, "$_FILES['att']"),
    "admin/pages/bottexts.php": (2500, "$_FILES['jfile']"),
    "admin/pages/botbuttons.php": (4000, "$_FILES['jfile']"),
    "admin/pages/update.php": (2500, "$_FILES['zip']"),
    "admin/pages/stock.php": (4000, "$_FILES['file']"),
    BK: (8000, "backup_pass"),
}
for path, (minlen, needle) in SANITY.items():
    if path in NEW:
        t = CACHE[path]
        if len(t) < minlen or needle not in t:
            print("ABORTED - sanity check failed for %s (%d chars)" % (path, len(t)))
            sys.exit(1)

write_all()

if WARN:
    print("warnings (optional patches skipped):")
    for w in WARN:
        print("  - " + w)

# ===================================================== 6) recon for batch #11
around("SUB KEY", "sub.php", "$_GET['id']", 4, 42)
grep("SUB LOOKUP", "sub.php", r"FROM \{p\}services|sub_token|remark|WHERE\s+uuid", 18)

# ================================================================ 7) version
VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)

entry = (
    "\U0001f6e1 \u0633\u062e\u062a\u200c\u0633\u0627\u0632\u06cc \u0627\u0645\u0646\u06cc\u062a\u06cc \u06f0.\u06f0.\u06f2 (\u06af\u0627\u0645 \u06f1\u06f0): "
    "\u0639\u0628\u0648\u0631 \u0647\u0645\u0647\u0654 \u0641\u0631\u0645\u200c\u0647\u0627\u06cc \u0622\u067e\u0644\u0648\u062f \u067e\u0646\u0644 \u0627\u0632 \u0641\u06cc\u0644\u062a\u0631 \u0627\u0645\u0646 Upload "
    "(\u0646\u0627\u0645\u060c \u067e\u0633\u0648\u0646\u062f \u0648 \u062d\u062c\u0645) \u0648 \u0627\u0641\u0632\u0648\u062f\u0646 \u06af\u0632\u06cc\u0646\u0647\u0654 \u0633\u0627\u062e\u062a \u062e\u0648\u062f\u06a9\u0627\u0631 \u0631\u0645\u0632 \u0628\u06a9\u0627\u067e."
)
log = v.get("changelog") or []
if entry not in log:
    log.insert(0, entry)
    v["changelog"] = log
v["build"] = BUILD

with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")

print("build: " + BUILD)
print("changed files: %d" % len(NEW))
for p in sorted(NEW):
    print("  - " + p)
