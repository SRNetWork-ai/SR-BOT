#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed93 - 0.0.2 batch #8

  * csrf shield switched to strict (every POST form in admin/pages has a token)
  * media upload uses the new Upload service (safe name + protected directory)
  * recon: idor candidates in the mini app, ssrf candidates repo wide

Safety rules: encode before writing, php -l every touched php file, never put
\\uXXXX escape text inside python string literals.
"""
import io, json, os, re, shutil, subprocess, sys, tempfile

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed93").strip() or "fixed93"

CACHE = {}
NEW = {}
ERRORS = []


def load(path):
    if path not in CACHE:
        with io.open(os.path.join(ROOT, path), encoding="utf-8") as fh:
            CACHE[path] = fh.read()
    return CACHE[path]


def rep_multi(path, pairs, marker=None):
    try:
        s = load(path)
    except Exception as e:
        ERRORS.append("%s: %s" % (path, e))
        return
    if marker and marker in s:
        print("skip (already applied): %s / %s" % (path, marker))
        return
    for i, (old, new) in enumerate(pairs):
        if s.count(old) == 1:
            CACHE[path] = s.replace(old, new)
            NEW[path] = True
            print("patched %s with anchor #%d (%s)" % (path, i + 1, marker or "-"))
            return
    ERRORS.append("%s: no unique anchor for %s (counts: %s)"
                  % (path, marker or "-", [s.count(o) for o, _ in pairs]))


def rep_rx(path, pattern, fn, marker=None, expect=1, flags=re.M):
    """regex patch - used when the indentation of the anchor is unknown"""
    try:
        s = load(path)
    except Exception as e:
        ERRORS.append("%s: %s" % (path, e))
        return
    if marker and marker in s:
        print("skip (already applied): %s / %s" % (path, marker))
        return
    rx = re.compile(pattern, flags)
    hits = rx.findall(s)
    if len(hits) != expect:
        ERRORS.append("%s: regex %s matched %d times (want %d)" % (path, marker or pattern[:40], len(hits), expect))
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
            print("  %d: %s" % (i, line.strip()[:110]))
            n += 1
            if n >= limit:
                break
    if n == 0:
        print("  (no match)")


def grep_tree(tag, pattern, limit=30):
    print("== %s (tree ~ %s) ==" % (tag, pattern))
    rx = re.compile(pattern)
    n = 0
    for base, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in (".git", "storage", "node_modules", "vendor", "assets")]
        for f in sorted(files):
            if not f.endswith(".php"):
                continue
            rel = os.path.relpath(os.path.join(base, f), ROOT)
            try:
                with io.open(os.path.join(base, f), encoding="utf-8", errors="replace") as fh:
                    for i, line in enumerate(fh, 1):
                        if rx.search(line):
                            print("  %s:%d: %s" % (rel, i, line.strip()[:96]))
                            n += 1
                            if n >= limit:
                                print("  ... (limit reached)")
                                return
            except Exception:
                continue
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


# ===================================================== 1) strict csrf (row 6)
# the detailed audit showed every POST form already carries csrf_field();
# only GET filter forms were missing, and those do not need a token.
rep_multi(
    "admin/index.php",
    [("DB::setting('sec_csrf_strict', '0')", "DB::setting('sec_csrf_strict', '1')")],
    marker="DB::setting('sec_csrf_strict', '1')",
)

# ================================================== 2) safe media upload (10)
SET = "admin/pages/settings.php"


def _safe_name(m):
    ind = m.group(1)
    return (
        ind + "/* 0.0.2 #10: \u0646\u0627\u0645 \u0627\u0645\u0646 \u0648 \u067e\u0633\u0648\u0646\u062f \u0645\u062c\u0627\u0632 \u0628\u0631\u0627\u06cc \u0641\u0627\u06cc\u0644 \u0631\u0633\u0627\u0646\u0647 */\n"
        + ind + "$safe = class_exists('Upload')\n"
        + ind + "    ? Upload::safeName((string)$_FILES['media']['name'], Upload::MEDIA)\n"
        + ind + "    : (" + m.group(2) + ");"
    )


rep_rx(
    SET,
    r"^([ \t]*)\$safe\s*=\s*(preg_replace\([^\n]*\$_FILES\['media'\]\['name'\][^\n]*?)\s*;[ \t]*$",
    _safe_name,
    marker="0.0.2 #10: ",
)


def _protect(m):
    ind = m.group(1)
    return (
        ind + "if (class_exists('Upload')) Upload::protectDir(dirname($dest));   /* 0.0.2 #10-dir */\n"
        + m.group(0)
    )


rep_rx(
    SET,
    r"^([ \t]*)if \(@move_uploaded_file\(\(string\)\$_FILES\['media'\]\['tmp_name'\], \$dest\)\) \{[ \t]*$",
    _protect,
    marker="0.0.2 #10-dir",
)

# ==================================================== 3) sanity check + write
if ERRORS:
    print("ABORTED - anchors not found:")
    for e in ERRORS:
        print("  - " + e)
    sys.exit(1)

SANITY = {
    "admin/index.php": (25000, "function csrf_ok"),
    SET: (150000, "$_FILES['media']"),
}
for path, (minlen, needle) in SANITY.items():
    if path in NEW:
        t = CACHE[path]
        if len(t) < minlen or needle not in t:
            print("ABORTED - sanity check failed for %s (%d chars)" % (path, len(t)))
            sys.exit(1)

write_all()

# ===================================================== 4) recon for batch #9
grep("MINIAPP WHERE ID", "miniapp/api.php", r"WHERE\s+id\s*=", 30)
grep("MINIAPP OWNER CHECK", "miniapp/api.php", r"user_id\s*=\s*:u|AND\s+user_id", 20)
grep_tree("SSRF CURL", r"curl_init\(\$|http_json\(\$|file_get_contents\(\$url", 22)

# ================================================================ 5) version
VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)

entry = (
    "\U0001f6e1 \u0633\u062e\u062a\u200c\u0633\u0627\u0632\u06cc \u0627\u0645\u0646\u06cc\u062a\u06cc \u06f0.\u06f0.\u06f2 (\u06af\u0627\u0645 \u06f8): "
    "\u0641\u0639\u0627\u0644 \u0634\u062f\u0646 \u0633\u0637\u062d \u0633\u062e\u062a\u06af\u06cc\u0631\u0627\u0646\u0647\u0654 \u0633\u067e\u0631 CSRF \u0628\u0631\u0627\u06cc \u0647\u0645\u0647\u0654 \u0641\u0631\u0645\u200c\u0647\u0627\u06cc \u067e\u0646\u0644\u060c "
    "\u0633\u0631\u0648\u06cc\u0633 \u062a\u0627\u0632\u0647\u0654 Upload \u0628\u0631\u0627\u06cc \u0628\u0631\u0631\u0633\u06cc \u0646\u0627\u0645\u060c \u067e\u0633\u0648\u0646\u062f \u0648 \u062d\u062c\u0645 \u0641\u0627\u06cc\u0644\u200c\u0647\u0627\u06cc \u0622\u067e\u0644\u0648\u062f\u06cc "
    "\u0648 \u0628\u0633\u062a\u0646 \u0627\u062c\u0631\u0627\u06cc \u0627\u0633\u06a9\u0631\u06cc\u067e\u062a \u062f\u0631 \u067e\u0648\u0634\u0647\u0654 \u0622\u067e\u0644\u0648\u062f."
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
