#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed106 - recon only: per-plan device (HWID) limit + periodic traffic reset

What we need to know:
  1. products table columns
  2. how a client is created on the panel (Xui3::addClient)
  3. every call site of addClient
  4. the admin products form/save hooks
  5. the migrations folder (next file number + format)
"""
import io, json, os, re

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed106").strip() or "fixed106"
SKIP_DIRS = {".git", "storage", "node_modules", "vendor", "assets"}

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
            print("  %d: %s" % (i, line.strip()[:118]))
            n += 1
            if n >= limit:
                print("  ... (limit)")
                break
    if n == 0:
        print("  (no match)")


def grep_tree(tag, pattern, limit=24, exts=(".php",)):
    print("== %s (tree ~ %s) ==" % (tag, pattern))
    rx = re.compile(pattern)
    n = 0
    for base, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS and not d.startswith(".")]
        for f in sorted(files):
            if not f.endswith(exts):
                continue
            rel = os.path.relpath(os.path.join(base, f), ROOT)
            try:
                lines = load(rel).splitlines()
            except Exception:
                continue
            for i, line in enumerate(lines, 1):
                if rx.search(line):
                    print("  %s:%d: %s" % (rel, i, line.strip()[:104]))
                    n += 1
                    if n >= limit:
                        print("  ... (limit)")
                        return
    if n == 0:
        print("  (no match)")


def dump(tag, path, start, end):
    try:
        lines = load(path).splitlines()
    except Exception as e:
        print("== %s == missing: %s" % (tag, e))
        return
    print("== %s (%s lines %d-%d of %d) ==" % (tag, path, start, end, len(lines)))
    for i in range(max(1, start), min(end, len(lines)) + 1):
        raw = lines[i - 1]
        if len(raw) > 260:
            raw = raw[:260] + " ...TRUNC"
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


def ls(tag, d, tail=14):
    print("== %s (%s) ==" % (tag, d))
    try:
        names = sorted(os.listdir(os.path.join(ROOT, d)))
    except Exception as e:
        print("  " + str(e))
        return
    print("  total: %d" % len(names))
    for f in names[-tail:]:
        print("  " + f)


# 1) products table
dump_find("SCHEMA PRODUCTS", "database/schema.sql", r"CREATE TABLE IF NOT EXISTS \{p\}products", 0, 34)

# 2) how clients are created on a 3x-ui panel
dump("XUI3 addClient", "app/Panel/Xui3.php", 237, 335)

# 3) call sites
grep_tree("ADDCLIENT CALLS", r"addClient\(", 24)

# 4) admin products page
grep("PRODUCTS SAVE", "admin/pages/products.php", r"\$act ===|pint\('|pnum\('|pstr\('", 34)
grep("PRODUCTS FORM", "admin/pages/products.php", r"name=\"(volume_gb|days|ip_limit|limit_ip|iplimit|device|hwid|inbound)", 20)

# 5) migrations
ls("MIGRATIONS", "database/migrations", 14)

VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)
v["build"] = BUILD
with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")

print("build: " + BUILD)
print("changed files: 0 (recon run)")
