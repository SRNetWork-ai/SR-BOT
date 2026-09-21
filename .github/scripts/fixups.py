#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed110 - recon only: periodic auto traffic reset (reset/resetDay)

  1. Svc.php option -> addClient flow
  2. Migrate.php products column map (auto column creation on upgrade)
  3. admin/pages/products.php save + form (where device_limit lives)
  4. Xui facade addClient signature
  5. Xui3 updateClient payload handling
"""
import io, json, os, re

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed110").strip() or "fixed110"

CACHE = {}


def load(path):
    if path not in CACHE:
        with io.open(os.path.join(ROOT, path), encoding="utf-8") as fh:
            CACHE[path] = fh.read()
    return CACHE[path]


def dump(tag, path, start, end):
    try:
        lines = load(path).splitlines()
    except Exception as e:
        print("== %s == missing: %s" % (tag, e))
        return
    print("== %s (%s lines %d-%d of %d) ==" % (tag, path, start, end, len(lines)))
    for i in range(max(1, start), min(end, len(lines)) + 1):
        raw = lines[i - 1]
        if len(raw) > 240:
            raw = raw[:240] + " ...TRUNC"
        print("  %d|%s" % (i, raw))


def dump_find(tag, path, pattern, before=0, after=40):
    try:
        lines = load(path).splitlines()
    except Exception as e:
        print("== %s == missing: %s" % (tag, e))
        return
    for i, line in enumerate(lines, 1):
        if re.search(pattern, line):
            dump(tag, path, i - before, i + after)
            return
    print("== %s == pattern not found: %s" % (tag, pattern))


dump("SVC options", "app/Service/Svc.php", 225, 250)
dump("SVC create", "app/Service/Svc.php", 276, 320)
dump("MIGRATE product cols", "app/Service/Migrate.php", 50, 92)
dump("PRODUCTS save", "admin/pages/products.php", 22, 70)
dump("PRODUCTS form", "admin/pages/products.php", 742, 762)
dump("XUI facade addClient", "app/Panel/Xui.php", 385, 402)
dump("XUI3 updateClient", "app/Panel/Xui3.php", 334, 376)

VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)
v["build"] = BUILD
with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")
print("build: " + BUILD)
print("changed files: 0 (recon run)")
