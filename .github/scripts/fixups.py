# -*- coding: utf-8 -*-
# fixed128 - recon #2: deliver modes (bot / miniapp) + hwid mapping in xui3
import io, os, re, sys, json, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed128').strip() or 'fixed128'

CACHE = {}


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8') as fh:
        CACHE[path] = fh.read()
    return CACHE[path]


def dump(tag, path, start, end):
    try:
        lines = load(path).split('\n')
    except Exception as e:
        print('dump %s: cannot read %s (%s)' % (tag, path, e))
        return
    print('---- dump %s : %s (%d lines) ----' % (tag, path, len(lines)))
    i = max(1, start)
    last = min(len(lines), end)
    while i <= last:
        print('%5d %s' % (i, lines[i - 1]))
        i += 1
    print('---- end dump %s ----' % tag)


def dump_find(tag, path, pattern, before=4, after=20, limit=1):
    try:
        lines = load(path).split('\n')
    except Exception as e:
        print('dump_find %s: cannot read %s (%s)' % (tag, path, e))
        return
    rx = re.compile(pattern)
    hits = [i + 1 for i, ln in enumerate(lines) if rx.search(ln)]
    print('---- find %s : %s -> %d hits %s ----' % (tag, pattern, len(hits), hits[:40]))
    for n in hits[:limit]:
        dump('%s@%d' % (tag, n), path, n - before, n + after)


def grep_lines(tag, path, pattern, limit=40):
    try:
        lines = load(path).split('\n')
    except Exception as e:
        print('grep %s: cannot read %s (%s)' % (tag, path, e))
        return
    rx = re.compile(pattern)
    n = 0
    print('---- grep %s : %s ----' % (tag, pattern))
    for i, ln in enumerate(lines):
        if rx.search(ln):
            n += 1
            if n <= limit:
                print('%5d %s' % (i + 1, ln.strip()[:190]))
    print('---- grep %s : %d hits ----' % (tag, n))


# ---------------------------------------------------------- 1) deliver modes
dump_find('svc_deliver_const', 'app/Service/Svc.php', r'const DELIVER', 2, 14, 1)
grep_lines('svc_deliver', 'app/Service/Svc.php', r'DELIVER|deliver_mode|deliverMode|function deliver', 50)
dump_find('svc_deliver_fn', 'app/Service/Svc.php', r'function deliverMode|function deliver\(', 6, 45, 2)

grep_lines('bot_deliver', 'app/Bot/Bot.php', r'DELIVER|deliver_mode|deliverMode|Svc::deliver', 40)
grep_lines('ma_deliver', 'miniapp/api.php', r'DELIVER|deliver_mode|deliverMode|Svc::deliver', 40)
grep_lines('set_deliver', 'admin/pages/settings.php', r'deliver', 30)

# ---------------------------------------------------------- 2) hwid mapping
grep_lines('x3_hwid', 'app/Panel/Xui3.php', r'limitHwid|deviceLimit|setDeviceLimit|hwid', 40)
dump_find('x3_addclient', 'app/Panel/Xui3.php', r'public function addClient', 2, 55, 1)
dump_find('x3_setdev', 'app/Panel/Xui3.php', r'function setDeviceLimit', 3, 28, 1)
grep_lines('xui_dev', 'app/Panel/Xui.php', r'deviceLimit|limitHwid', 30)
dump_find('xui_addclient', 'app/Panel/Xui.php', r'public function addClient', 3, 45, 1)

print('build: %s (recon only, nothing patched)' % BUILD)

vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('changelog: skipped (recon build)')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
