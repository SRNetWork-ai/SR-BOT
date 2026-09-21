#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed112 - recon only: admin panel tools (x3-tools) + Xui3 bulk endpoints

Goal: add group traffic reset + bulk reset + server logs tools next.
"""
import io, json, os, re

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed112").strip() or "fixed112"

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
        if len(raw) > 250:
            raw = raw[:250] + " ...TRUNC"
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


def grep(tag, path, pattern, limit=40):
    try:
        lines = load(path).splitlines()
    except Exception as e:
        print("== %s == missing: %s" % (tag, e))
        return
    print("== grep %s (%s) %s ==" % (tag, path, pattern))
    n = 0
    for i, line in enumerate(lines, 1):
        if re.search(pattern, line):
            s = line.strip()
            if len(s) > 118:
                s = s[:118] + " ..."
            print("  %d: %s" % (i, s))
            n += 1
            if n >= limit:
                break


dump_find("PANELS x3-tools handler", "admin/pages/panels.php", r"0\.0\.2 #x3-tools\b", before=2, after=58)
dump_find("PANELS x3-tools UI", "admin/pages/panels.php", r"0\.0\.2 #x3-tools-ui", before=4, after=34)
dump_find("XUI3 delOrphans", "app/Panel/Xui3.php", r"function delOrphans", before=6, after=34)
grep("PANELS act cases", "admin/pages/panels.php", r"\$act === '", 30)
grep("PANELS flash/back", "admin/pages/panels.php", r"flash\('(ok|err)'", 12)

VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)
v["build"] = BUILD
with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")
print("build: " + BUILD)
print("changed files: 0 (recon run)")
