#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed98 - recon only

Maps the internals of the 3x-ui driver so the next batch can add the new
endpoints documented at docs.sanaei.dev (hwid devices, client links,
slim inbound lists, client summary, periodic traffic reset).
"""
import io, json, os, re, sys

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed98").strip() or "fixed98"

CACHE = {}


def load(path):
    if path not in CACHE:
        with io.open(os.path.join(ROOT, path), encoding="utf-8") as fh:
            CACHE[path] = fh.read()
    return CACHE[path]


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


def dump(tag, path, start, end=None, tail=0):
    """print raw lines (untruncated up to 300 chars) so literal anchors are safe"""
    try:
        lines = load(path).splitlines()
    except Exception as e:
        print("== %s == missing: %s" % (tag, e))
        return
    if tail:
        start = max(1, len(lines) - tail + 1)
        end = len(lines)
    end = end or start
    print("== %s (%s lines %d-%d of %d) ==" % (tag, path, start, end, len(lines)))
    for i in range(start, min(end, len(lines)) + 1):
        raw = lines[i - 1]
        if len(raw) > 300:
            raw = raw[:300] + " ...TRUNC"
        print("  %d|%s" % (i, raw))


# ------------------------------------------------------------------ recon
grep("X3 FUNCS", "app/Panel/Xui3.php", r"function ", 60)
grep("X3 PATHS", "app/Panel/Xui3.php", r"panel/api|/login|apiToken|Bearer|token", 40)
dump("X3 HEAD", "app/Panel/Xui3.php", 20, 60)
dump("X3 TAIL", "app/Panel/Xui3.php", 0, tail=120)
grep("XUI DELEGATE", "app/Panel/Xui.php", r"x3->", 30)
grep("XUI TYPES", "app/Panel/Xui.php", r"'sanaei'|'vpn-ui'|'hiddify'|'marzneshin'|SOON|=> \[", 24)

# ---------------------------------------------------------------- version
VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)
v["build"] = BUILD
with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")

print("build: " + BUILD)
print("changed files: 0 (recon run)")
