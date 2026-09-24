# -*- coding: utf-8 -*-
# fixed167 - verification of the Bot.php restore + repo wide truncation audit (recon only)
import io, os, sys, json, re, urllib.request

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
GOOD = '2a2603cebf49ca4381ea00ebc8831f94c0bffa86'  # last commit before the loss
RAW = 'https://raw.githubusercontent.com/SRNetWork-ai/SR-BOT/' + GOOD + '/%s'
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json')


def load(path):
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8') as fh:
        return fh.read()


def fetch(path):
    req = urllib.request.Request(RAW % path, headers={'User-Agent': 'sr-bot-fixups'})
    with urllib.request.urlopen(req, timeout=40) as r:
        return r.read().decode('utf-8', 'replace')


def files_all():
    out = []
    for base, dirs, names in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        for n in names:
            if n.endswith(EXT):
                p = os.path.relpath(os.path.join(base, n), ROOT)
                out.append(p)
    return sorted(out)


bot = load('app/Bot/Bot.php')
print('app/Bot/Bot.php: %d lines, %d bytes' % (len(bot.split(chr(10))), len(bot.encode('utf-8'))))

vj = json.loads(load('version.json'))
print('version.json build: %s' % vj.get('build'))

WANT = ['sectionWallet', 'sectionAccount', 'sectionTest', 'sectionTutorials', 'sectionSupport',
        'sectionReseller', 'sectionReferral', 'sectionStock', 'sectionResellerServices',
        'walletCardMenu', 'walletHistory', 'askAmount', 'verifyMenu', 'referralList',
        'resellerRequest', 'miniappBtn', 'sectionProducts', 'sectionServices', 'sectionOrders',
        'sectionCampaign', 'cusMenu', 'runButton', 'handleState', 'isAdmin', 'onMessage',
        'checkHooshPay', 'checkNowPay', 'sendSub', 'doRenew', 'doDelete', 'setMenuButton']
print('===== method existence in app/Bot/Bot.php =====')
low = bot.lower()
miss = []
for m in WANT:
    hit = ('function ' + m.lower() + '(') in low
    print('  %-26s %s' % (m, 'OK' if hit else 'MISSING'))
    if not hit:
        miss.append(m)
print('missing count: %d' % len(miss))

# ---- name level diff against the last good commit ----
NAME_RE = re.compile(r'function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(')
try:
    ref_bot = fetch('app/Bot/Bot.php')
    a = set(x.lower() for x in NAME_RE.findall(ref_bot))
    b = set(x.lower() for x in NAME_RE.findall(bot))
    print('functions: good-commit=%d current=%d' % (len(a), len(b)))
    lost = sorted(a - b)
    gained = sorted(b - a)
    print('still missing vs good commit: %s' % (', '.join(lost) if lost else 'none'))
    print('added since good commit: %s' % (', '.join(gained) if gained else 'none'))
except Exception as e:
    print('ref fetch failed: %s' % e)

# ---- repo wide truncation audit ----
print('===== truncation audit vs %s =====' % GOOD[:8])
rows = []
for p in files_all():
    if p.startswith('.github'):
        continue
    try:
        cur = load(p)
    except Exception:
        continue
    if len(cur.encode('utf-8')) < 12000:
        continue
    try:
        old = fetch(p.replace(os.sep, '/'))
    except Exception as e:
        print('  %-44s current=%5d lines   (ref fetch: %s)' % (p, len(cur.split(chr(10))), e))
        continue
    cl = len(cur.split(chr(10)))
    ol = len(old.split(chr(10)))
    flag = 'SHRUNK' if cl < ol * 0.92 else 'ok'
    rows.append((flag, p, ol, cl))
for flag, p, ol, cl in rows:
    if flag == 'SHRUNK':
        print('  !! %-42s good=%5d  now=%5d  (-%d)' % (p, ol, cl, ol - cl))
print('checked %d large files, shrunk=%d' % (len(rows), len([r for r in rows if r[0] == 'SHRUNK'])))
for flag, p, ol, cl in rows:
    if flag != 'SHRUNK':
        print('  ok %-42s good=%5d  now=%5d' % (p, ol, cl))

# ---- anchors for the next guard patch ----
lines = bot.split(chr(10))
print('===== dump Bot.php 165-210 =====')
for i in range(164, min(210, len(lines))):
    print('%4d|%s' % (i + 1, lines[i]))

print('changed files: 0')
print('build stays: %s' % vj.get('build'))
print('exit: 0')
