#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed103 - recon only: where to surface 3x-ui panel insights

  clientsSummary() / ipLimitStatus() / panelUpdateInfo()
"""
import io, json, os, re

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed103").strip() or "fixed103"

CACHE = {}


def load(path):
    if path not in CACHE:
        with io.open(os.path.join(ROOT, path), encoding="utf-8") as fh:
            CACHE[path] = fh.read()
    return CACHE[path]


def grep(tag, path, pattern, limit=30):
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


def dump(tag, path, start, end):
    try:
        lines = load(path).splitlines()
    except Exception as e:
        print("== %s == missing: %s" % (tag, e))
        return
    print("== %s (%s lines %d-%d of %d) ==" % (tag, path, start, end, len(lines)))
    for i in range(start, min(end, len(lines)) + 1):
        raw = lines[i - 1]
        if len(raw) > 260:
            raw = raw[:260] + " ...TRUNC"
        print("  %d|%s" % (i, raw))


# 1) health sections
grep("HEALTH FUNCS", "app/Service/Health.php", r"static function |\$out = \[\];|return \$out;", 30)

# 2) the panel-related part of health (if any)
grep("HEALTH PANELS", "app/Service/Health.php", r"panels|Xui|healthCheck", 20)

# 3) admin panels page: action hooks for a maintenance button
grep("ADMIN PANELS", "admin/pages/panels.php", r"\$act|case '|post\('|csrf", 26)

VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)
v["build"] = BUILD
with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")

print("build: " + BUILD)
print("changed files: 0 (recon run)")
