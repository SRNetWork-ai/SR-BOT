# -*- coding: utf-8 -*-
# fixed129 - recon #3: where sub/config are rendered for the customer
import io, os, re, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed129').strip() or 'fixed129'

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


def grep_lines(tag, path, pattern, limit=50):
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
                print('%5d %s' % (i + 1, ln.strip()[:180]))
    print('---- grep %s : %d hits ----' % (tag, n))


def grep_repo(tag, pattern, roots, exts=('.php',), limit=60):
    rx = re.compile(pattern)
    n = 0
    print('---- grepr %s : %s ----' % (tag, pattern))
    for root in roots:
        base = os.path.join(ROOT, root)
        if not os.path.isdir(base):
            continue
        for dirpath, dirnames, filenames in os.walk(base):
            for fn in sorted(filenames):
                if not fn.endswith(exts):
                    continue
                p = os.path.join(dirpath, fn)
                rel = os.path.relpath(p, ROOT)
                try:
                    with io.open(p, 'r', encoding='utf-8') as fh:
                        txt = fh.read()
                except Exception:
                    continue
                for i, ln in enumerate(txt.split('\n')):
                    if rx.search(ln):
                        n += 1
                        if n <= limit:
                            print('%s:%d %s' % (rel, i + 1, ln.strip()[:170]))
    print('---- grepr %s : %d hits ----' % (tag, n))


# 1) bot: the two delivery renderers
dump('bot_deliver_1', 'app/Bot/Bot.php', 2845, 2935)
dump('bot_deliver_2', 'app/Bot/Bot.php', 2960, 3030)
grep_lines('bot_happ', 'app/Bot/Bot.php', r'Links::happ|happView|svchapp|#happ-btn', 30)
dump_find('bot_happview', 'app/Bot/Bot.php', r'(private|public|protected).*function happView', 2, 45, 1)

# 2) admin selects for deliver mode
grep_repo('deliver_ui', r'Svc::DELIVER|deliverLabel|deliver_mode', ['admin', 'miniapp'], ('.php',), 60)
dump_find('prod_deliver_select', 'admin/pages/products.php', r"name=\"deliver_mode\"", 8, 16, 1)

# 3) miniapp service row + links action
dump('ma_row', 'miniapp/api.php', 305, 365)
grep_lines('ma_links', 'miniapp/api.php', r"svc_links|Links::happ|can_links|sub_link|'configs'", 40)

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
