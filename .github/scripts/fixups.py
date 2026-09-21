#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed116 - recon only: onlines() + health x3 block (multi-node onlines)"""
import io, json, os, re

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed116").strip() or "fixed116"

SKIP_DIRS = {".git", "storage", "node_modules", "vendor", "assets"}
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


def grep_tree(tag, pattern, limit=40, exts=(".php",)):
    print("== grep_tree %s -> %s ==" % (tag, pattern))
    n = 0
    for base, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS and not d.startswith(".")]
        for fn in sorted(files):
            if not fn.endswith(exts):
                continue
            rel = os.path.relpath(os.path.join(base, fn), ROOT)
            try:
                with io.open(os.path.join(base, fn), encoding="utf-8", errors="replace") as fh:
                    for i, line in enumerate(fh, 1):
                        if re.search(pattern, line):
                            s = line.strip()
                            if len(s) > 104:
                                s = s[:104] + " ..."
                            print("  %s:%d: %s" % (rel, i, s))
                            n += 1
                            if n >= limit:
                                return
            except Exception:
                pass


dump_find("XUI3 onlines", "app/Panel/Xui3.php", r"public function onlines", before=6, after=46)
dump_find("HEALTH x3 block", "app/Service/Health.php", r"0\.0\.2 #x3-health", before=3, after=48)
grep_tree("onlines callers", r"->onlines\(|activeInbounds|onlinesByGuid", 30)
grep_tree("cron entry", r"tasks\.php", 14, exts=(".php", ".md", ".sh"))

VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)
v["build"] = BUILD
with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")
print("build: " + BUILD)
print("changed files: 0 (recon run)")
