# -*- coding: utf-8 -*-
# fixed171 - zarinpal status check: clear message while the payment is still pending
import io, os, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed171').strip() or 'fixed171'
CACHE = {}
ORIG = {}
ERRORS = []
WARN = []


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8') as fh:
        s = fh.read()
    CACHE[path] = s
    ORIG[path] = s
    return s


def rep_lit(path, old, new, marker, optional=False):
    s = load(path)
    if marker in s:
        print('skip (already applied): %s' % marker)
        return
    n = s.count(old)
    if n != 1:
        msg = '%s: anchor for %s matched %d times (want 1)' % (path, marker, n)
        (WARN if optional else ERRORS).append(msg)
        print('MISS: %s' % msg)
        return
    CACHE[path] = s.replace(old, new, 1)
    print('patched: %s (%s)' % (path, marker))


API = 'miniapp/api.php'

old1 = "        $zpRes  = Zarinpal::poll((array)$zpRow);\n"
new1 = (
    "        /* 0.0.2 #22 zp-chk-pending */\n"
    "        if (trim((string)($zpRow['txid'] ?? '')) === '') {\n"
    "            ma_out(['ok' => true, 'paid' => false, 'dead' => false,\n"
    "                'balance_txt' => ma_money($zpBal()),\n"
    "                'message' => '\u0647\u0646\u0648\u0632 \u067e\u0631\u062f\u0627\u062e\u062a\u06cc \u0628\u0631\u0627\u06cc \u0627\u06cc\u0646 \u0641\u0627\u06a9\u062a\u0648\u0631 \u062b\u0628\u062a \u0646\u0634\u062f\u0647 \u0627\u0633\u062a\u061b \u0627\u06af\u0631 \u067e\u0631\u062f\u0627\u062e\u062a \u0631\u0627 \u0627\u0646\u062c\u0627\u0645 \u062f\u0627\u062f\u0647\u200c\u0627\u06cc\u062f\u060c \u0686\u0646\u062f \u0644\u062d\u0638\u0647 \u0628\u0639\u062f \u062f\u0648\u0628\u0627\u0631\u0647 \u0628\u0631\u0631\u0633\u06cc \u06a9\u0646\u06cc\u062f.']);\n"
    "        }\n"
    "\n"
    "        $zpRes  = Zarinpal::poll((array)$zpRow);\n"
)
rep_lit(API, old1, new1, 'zp-chk-pending')

if ERRORS:
    print('ABORTED - anchors not found:')
    for e in ERRORS:
        print('  - %s' % e)
    print('exit: 1')
    sys.exit(1)

changed = 0
for p, s in CACHE.items():
    if ORIG.get(p) == s:
        continue
    with io.open(os.path.join(ROOT, p), 'w', encoding='utf-8') as fh:
        fh.write(s)
    changed += 1
print('changed files: %d' % changed)

for p in ['miniapp/api.php', 'miniapp/index.php', 'app/Bot/Bot.php']:
    s = load(p)
    print('%s: %d lines, %d bytes' % (p, len(s.split(chr(10))), len(s.encode('utf-8'))))

vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write(chr(10))
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
