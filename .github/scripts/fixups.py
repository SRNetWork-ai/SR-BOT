# -*- coding: utf-8 -*-
# fixed134 - RECON: why deliver mode leaks configs, why the config list is too long,
# and how the panel exposes a dedicated Happ subscription link.
import io, os, re, sys, json, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed134').strip() or 'fixed134'

CACHE = {}


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8') as fh:
        CACHE[path] = fh.read()
    return CACHE[path]


def info(path):
    try:
        s = load(path)
    except Exception as e:
        print('info %s: cannot read (%s)' % (path, e))
        return
    print('info %s: %d bytes, %d lines' % (path, len(s.encode('utf-8')), len(s.split('\n'))))


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


def dump_find(tag, path, pattern, before=3, after=25, limit=1):
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


# ============ A) why does "sub only" still send configs? ============
grep_lines('bot_deliver_calls', 'app/Bot/Bot.php', r'self::deliver\(|sendCfgList\(|self::cfgList\(', 30)
dump_find('bot_cfglist', 'app/Bot/Bot.php', r'function cfgList', 3, 30, 1)
dump_find('svc_delivermode', 'app/Service/Svc.php', r'public static function deliverMode\(', 6, 14, 1)
dump_find('svc_delivermodeof', 'app/Service/Svc.php', r'public static function deliverModeOf\(', 4, 16, 1)
grep_lines('svc_storedcfg', 'app/Service/Svc.php', r'function storedConfigs|function subUrl|function localSub|function subCode|function cfgLines', 20)
dump_find('svc_stored', 'app/Service/Svc.php', r'function storedConfigs', 3, 34, 1)

# ============ B) why 10 configs while the client has 4 ============
dump('svc_create_cfg', 'app/Service/Svc.php', 286, 345)
grep_lines('xui_links', 'app/Panel/Xui.php', r'function clientLinks|function buildLink|function links\(|function configLinks', 20)

# ============ C) dedicated Happ subscription link ============
dump_find('x3_happ', 'app/Panel/Xui3.php', r'function happLink', 3, 42, 1)
grep_lines('x3_happ_all', 'app/Panel/Xui3.php', r'happ|Happ|crypt|externalLinks|subLinks|subJson|/clients/links', 40)
dump_find('x3_sublinks', 'app/Panel/Xui3.php', r'function subLinks', 3, 34, 1)
dump_find('x3_extlinks', 'app/Panel/Xui3.php', r'function externalLinks', 3, 34, 1)
dump_find('svc_suburl', 'app/Service/Svc.php', r'public static function subUrl', 3, 38, 1)
grep_lines('sub_happ', 'sub.php', r'happ|crypt|Happ', 20)

info('app/Service/Svc.php')
info('app/Bot/Bot.php')
info('miniapp/api.php')

# =============================== version bump only ===============================
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
print('build: %s (recon only, nothing patched)' % BUILD)
print('changelog: skipped (recon build)')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
