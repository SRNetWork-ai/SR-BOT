# -*- coding: utf-8 -*-
# fixed136 - RECON #3: Links service, Svc happ helpers, showService buttons,
# sendSub/sendConfig bodies, miniapp service row + svc_links.
import io, os, re, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed136').strip() or 'fixed136'

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


def grep_lines(tag, path, pattern, limit=60):
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


dump('links_all', 'app/Service/Links.php', 1, 92)
dump('svc_wants', 'app/Service/Svc.php', 1609, 1700)
grep_lines('svc_cfglink', 'app/Service/Svc.php', r"config_link", 40)
grep_lines('bot_cfglink', 'app/Bot/Bot.php', r"config_link|Links::|deliverMode", 40)
dump('bot_showsvc', 'app/Bot/Bot.php', 2968, 3046)
dump('bot_sendsub', 'app/Bot/Bot.php', 3126, 3205)
dump('ma_row', 'miniapp/api.php', 300, 372)
dump('ma_links', 'miniapp/api.php', 1014, 1046)
grep_lines('ma_deliver', 'miniapp/api.php', r'Svc::wantsSub|Svc::wantsCfg|Svc::wantsHapp|Svc::deliverMode|Links::', 30)

vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
print('changelog: skipped (recon build)')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
