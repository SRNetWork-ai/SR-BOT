#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed107 - recon only: Happ link / external links wiring

  1. Xui3 curl+api signatures (so new calls match exactly)
  2. clientRow (panel row id used by /clients/happLink/{id})
  3. the fixed99 device methods (call style to copy)
  4. linksFor / subLink (how links reach the bot)
  5. device_limit flow (buy + renew)
  6. bot + mini-app delivery hooks
"""
import io, json, os, re

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed107").strip() or "fixed107"
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
        if len(raw) > 240:
            raw = raw[:240] + " ...TRUNC"
        print("  %d|%s" % (i, raw))


dump("XUI3 curl+api", "app/Panel/Xui3.php", 95, 158)
dump("XUI3 clientRow", "app/Panel/Xui3.php", 224, 258)
dump("XUI3 devices", "app/Panel/Xui3.php", 705, 742)
dump("XUI3 links", "app/Panel/Xui3.php", 655, 700)

grep_tree("DEVICE LIMIT", r"device_limit|devLim", 24)

grep("BOT LINK DELIVERY", "app/Bot/Bot.php", r"function sendSub|function sendConfig|case 'svcsub'|case 'svccfg'|linksFor\(|subUrl\(", 22)
grep("MINIAPP LINKS", "miniapp/api.php", r"'svc_sub'|'svc_cfg'|'svc_links'|sub_link|config_link", 24)

VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)
v["build"] = BUILD
with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")

print("build: " + BUILD)
print("changed files: 0 (recon run)")
