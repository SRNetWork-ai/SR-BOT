#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed97 - 0.0.2 batch #12

  * index.php: ignore a Telegram update that was already processed (row 32)
  * recon: confirm how bootstrap.php autoloads app/Service classes
"""
import io, json, os, re, shutil, subprocess, sys, tempfile

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed97").strip() or "fixed97"

CACHE = {}
NEW = {}
ERRORS = []
WARN = []


def load(path):
    if path not in CACHE:
        with io.open(os.path.join(ROOT, path), encoding="utf-8") as fh:
            CACHE[path] = fh.read()
    return CACHE[path]


def rep_multi(path, pairs, marker=None, optional=False):
    """literal anchor patch; every anchor must appear exactly once"""
    bag = WARN if optional else ERRORS
    try:
        s = load(path)
    except Exception as e:
        bag.append("%s: %s" % (path, e))
        return
    if marker and marker in s:
        print("skip (already applied): %s / %s" % (path, marker))
        return
    out = s
    counts = []
    for old, _new in pairs:
        counts.append(out.count(old))
    if any(c != 1 for c in counts):
        bag.append("%s: no unique anchor for %s (counts: %s)"
                   % (path, marker or "-", counts))
        return
    for old, new in pairs:
        out = out.replace(old, new, 1)
    CACHE[path] = out
    NEW[path] = True
    print("patched %s with %d anchor(s) (%s)" % (path, len(pairs), marker or "-"))


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


# ================================ 1) duplicate telegram updates (row 32)
ANCHOR = (
    "$update = json_decode((string)$raw, true);\n"
    "if (!is_array($update)) {\n"
    "    http_response_code(400);\n"
    "    echo 'bad request';\n"
    "    exit;\n"
    "}\n"
)

DEDUP = ANCHOR + (
    "\n"
    "/* 0.0.2 #32: a retried delivery of the same update must not be handled twice */\n"
    "$updateId = (int)($update['update_id'] ?? 0);\n"
    "if ($updateId > 0 && class_exists('Dedup') && !Dedup::first($updateId)) {\n"
    "    http_response_code(200);\n"
    "    header('Content-Type: application/json; charset=utf-8');\n"
    "    echo '{\"ok\":true,\"duplicate\":true}';\n"
    "    exit;\n"
    "}\n"
)

rep_multi("index.php", [(ANCHOR, DEDUP)], marker="0.0.2 #32")

# ==================================================== 2) sanity check + write
if ERRORS:
    print("ABORTED - anchors not found:")
    for e in ERRORS:
        print("  - " + e)
    sys.exit(1)

if "index.php" in NEW:
    t = CACHE["index.php"]
    if len(t) < 4000 or "Bot::handle($update)" not in t:
        print("ABORTED - sanity check failed for index.php (%d chars)" % len(t))
        sys.exit(1)

write_all()

if WARN:
    print("warnings (optional patches skipped):")
    for w in WARN:
        print("  - " + w)

# ============================================= 3) recon: class autoloading
grep("AUTOLOAD", "app/bootstrap.php", r"spl_autoload_register|glob\(|require|Service", 26)
print("service files present: %d" % len([f for f in os.listdir(os.path.join(ROOT, "app", "Service")) if f.endswith(".php")]))
for f in ["Dedup.php", "Net.php", "Upload.php"]:
    p = os.path.join(ROOT, "app", "Service", f)
    print("  %s: %s" % (f, "ok" if os.path.isfile(p) else "MISSING"))

# ================================================================ 4) version
VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)

entry = (
    "\U0001f6e1 \u0633\u062e\u062a\u200c\u0633\u0627\u0632\u06cc \u06f0.\u06f0.\u06f2 (\u06af\u0627\u0645 \u06f1\u06f2): "
    "\u062c\u0644\u0648\u06af\u06cc\u0631\u06cc \u0627\u0632 \u067e\u0631\u062f\u0627\u0632\u0634 \u062a\u06a9\u0631\u0627\u0631\u06cc "
    "\u0622\u067e\u062f\u06cc\u062a\u200c\u0647\u0627\u06cc \u062a\u0644\u06af\u0631\u0627\u0645 (update_id) "
    "\u062a\u0627 \u062e\u0631\u06cc\u062f\u060c \u062a\u0645\u062f\u06cc\u062f \u06cc\u0627 \u0634\u0627\u0631\u0698 "
    "\u062f\u0648\u0628\u0627\u0631 \u0627\u0646\u062c\u0627\u0645 \u0646\u0634\u0648\u062f."
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
