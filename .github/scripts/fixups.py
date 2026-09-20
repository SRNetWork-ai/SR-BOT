#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed100 - recon only (wiring targets for the 3x-ui HWID device feature)"""
import io, json, os, re, sys

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed100").strip() or "fixed100"

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


# 1) services table columns
dump("SERVICES SCHEMA", "database/schema.sql", 115, 152)

# 2) how the bot routes svc callbacks
grep("BOT ROUTES", "app/Bot/Bot.php", r"'svc:|svcdel:|svcdelok:|svcpurge|svccfg|svcqr|svctraf", 24)

# 3) the service detail screen (buttons) - anchor source
dump("BOT SVC VIEW", "app/Bot/Bot.php", 2955, 3020)

# 4) a mini-app action as a template
dump("MINIAPP SVC_DEAD", "miniapp/api.php", 1017, 1060)

# 5) health item anchors for a later batch
grep("HEALTH ITEMS", "app/Service/Health.php", r"0\.0\.2 #|self::it\(", 18)

VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)
v["build"] = BUILD
with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")

print("build: " + BUILD)
print("changed files: 0 (recon run)")
