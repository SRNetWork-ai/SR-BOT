# -*- coding: utf-8 -*-
# fixed166 - restore class members that disappeared from app/Bot/Bot.php
# (wallet / account / test / tutorial / support / reseller / referral / stock buttons)
import io, os, sys, json, re, urllib.request

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed166').strip() or 'fixed166'

BOT = 'app/Bot/Bot.php'
RAW = 'https://raw.githubusercontent.com/SRNetWork-ai/SR-BOT/%s/app/Bot/Bot.php'

SHAS = [
    ('2026-09-11 upload', '02d49e6b85fc426c6a2a8e79b031f67929a47452'),
    ('2026-09-12 fixed83', '1b73ed1aaf7d3f12106b76a193586c3619eada34'),
    ('2026-09-12 fixed84', 'ed9f457af429b8a14a1e13765078ca1a086f9621'),
    ('2026-09-20 a', '767d1bf07da74f46a08b8c29a2bbb0c3e2b9e6cc'),
    ('2026-09-20 b', 'd01982d471873ab8160535456b368941d9caa117'),
    ('2026-09-23 a', '97ffa74942fd5430f4382d5c99db27503d9eb674'),
    ('2026-09-23 b', 'c67bd4beee384c2aada8793db205eb8713a5235e'),
    ('2026-09-23 c', '27cd62c9a079a7d669d902472ed04563104939d3'),
    ('2026-09-23 d', '2a2603cebf49ca4381ea00ebc8831f94c0bffa86'),
    ('2026-09-24 a', 'e60553e15f9a2b1f028662ec7b00c5054439cc0d'),
    ('2026-09-24 b', 'e3e682411b38676e8d2c2ddaf695fc3e64208a43'),
    ('2026-09-24 c', '1ac3677f7208834413bd48fd7e64f6c24eec0186'),
]

PROBE = 'function sectionWallet('


def read_local(path):
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8') as fh:
        return fh.read()


def fetch(sha):
    req = urllib.request.Request(RAW % sha, headers={'User-Agent': 'sr-bot-fixups'})
    with urllib.request.urlopen(req, timeout=40) as r:
        return r.read().decode('utf-8')


cur = read_local(BOT)
print('current %s: %d lines, %d bytes' % (BOT, len(cur.split(chr(10))), len(cur.encode('utf-8'))))

print('===== history probe =====')
best = None
for label, sha in SHAS:
    try:
        src = fetch(sha)
    except Exception as e:
        print('%-20s %-12s FETCH FAILED (%s)' % (label, sha[:8], e))
        continue
    ok = PROBE.lower() in src.lower()
    print('%-20s %-10s %6d lines  %8d B  sectionWallet=%s' % (label, sha[:8], len(src.split(chr(10))), len(src.encode('utf-8')), 'YES' if ok else 'no'))
    if ok:
        best = (label, sha, src)

if best is None:
    print('NO COMPLETE REFERENCE FOUND IN HISTORY')
    print('changed files: 0')
    print('exit: 0')
    sys.exit(0)

print('reference chosen: %s (%s)' % (best[0], best[1][:8]))
ref = best[2]

FN_RE = re.compile(r'^    (?:public|private|protected)\s+(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(')
PROP_RE = re.compile(r'^    (?:public|private|protected)\s+(?:static\s+)?(?:\??[A-Za-z_][A-Za-z0-9_]*\s+)?\$([A-Za-z_][A-Za-z0-9_]*)')
CONST_RE = re.compile(r'^    (?:public\s+|private\s+|protected\s+)?const\s+([A-Za-z_][A-Za-z0-9_]*)')


def members(src):
    lines = src.split(chr(10))
    out = []
    i = 0
    n = len(lines)
    while i < n:
        m = FN_RE.match(lines[i])
        if m:
            name = m.group(1)
            s = i
            j = i - 1
            while j >= 0:
                t = lines[j].strip()
                if t.startswith('/*') or t.startswith('*') or t.startswith('//') or t.endswith('*/'):
                    s = j
                    j -= 1
                else:
                    break
            e = -1
            k = i
            while k < n:
                if lines[k] == '    }':
                    e = k
                    break
                k += 1
            if e < 0:
                e = i
            out.append((name, s, e))
            i = e + 1
        else:
            i += 1
    return lines, out


ref_lines, ref_m = members(ref)
cur_lines, cur_m = members(cur)
print('members: reference=%d current=%d' % (len(ref_m), len(cur_m)))

have = set()
for nm, _s, _e in cur_m:
    have.add(nm.lower())

blocks = []
added = []
for nm, s, e in ref_m:
    if nm.lower() in have:
        continue
    blocks.append(chr(10).join(ref_lines[s:e + 1]))
    added.append(nm)
    have.add(nm.lower())

print('===== members to restore (%d) =====' % len(added))
for nm in added:
    print('  + %s' % nm)

# class constants / properties present in reference but missing here
cur_txt = cur
miss_c = []
for ln in ref_lines:
    mc = CONST_RE.match(ln)
    if mc and ('const ' + mc.group(1)) not in cur_txt:
        miss_c.append(mc.group(1))
    mp = PROP_RE.match(ln)
    if mp and ('$' + mp.group(1)) not in cur_txt:
        miss_c.append('$' + mp.group(1))
print('missing consts/props: %s' % (', '.join(miss_c) if miss_c else 'none'))

if not added:
    print('nothing to restore')
    print('changed files: 0')
    print('exit: 0')
    sys.exit(0)

end_idx = -1
for i in range(len(cur_lines) - 1, -1, -1):
    if cur_lines[i].strip() == '}' and not cur_lines[i].startswith(' '):
        end_idx = i
        break
if end_idx < 0:
    print('ABORTED - class closing brace not found')
    print('exit: 1')
    sys.exit(1)

head = cur_lines[:end_idx]
while head and head[-1].strip() == '':
    head.pop()

banner = [
    '',
    '    /* ============================================================',
    '     * 0.0.2 #restore-bot-sections',
    '     * بخش‌های زیر در یکی از آپلودهای قبلی از این فایل جا مانده بودند و',
    '     * دکمه‌های کیف پول، حساب کاربری، اکانت تست، آموزش و پشتیبانی را از',
    '     * کار انداخته بودند. عیناً از نسخهٔ سالم بازگردانده شده‌اند.',
    '     * ============================================================ */',
    '',
]

body = (chr(10) + chr(10)).join(blocks).split(chr(10))
new_lines = head + banner + body + [''] + cur_lines[end_idx:]
out = chr(10).join(new_lines)

with io.open(os.path.join(ROOT, BOT), 'w', encoding='utf-8') as fh:
    fh.write(out)
print('wrote %s -> %d lines, %d bytes' % (BOT, len(new_lines), len(out.encode('utf-8'))))
print('changed files: 1')

# ---------------- post merge validation ----------------
final_lines, final_m = members(out)
names = set()
for nm, _s, _e in final_m:
    names.add(nm.lower())
print('members after merge: %d' % len(final_m))

calls = set(re.findall(r'self::([A-Za-z_][A-Za-z0-9_]*)\s*\(', out))
bad = sorted([c for c in calls if c.lower() not in names])
print('unresolved self:: calls: %s' % (', '.join(bad) if bad else 'none'))

kb = read_local('app/Bot/Kb.php')
kbc = set(re.findall(r'Kb::([A-Z_]+)\b', out))
badkb = sorted([c for c in kbc if ('const ' + c) not in kb])
print('unresolved Kb:: constants: %s' % (', '.join(badkb) if badkb else 'none'))

dupe = {}
for nm, _s, _e in final_m:
    k = nm.lower()
    dupe[k] = dupe.get(k, 0) + 1
dups = sorted([k for k, v in dupe.items() if v > 1])
print('duplicate members: %s' % (', '.join(dups) if dups else 'none'))

# ---------------- version bump ----------------
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = 'رفع باگ: بازگرداندن بخش\u200cهای گمشدهٔ ربات (کیف پول، حساب کاربری، اکانت تست، آموزش، پشتیبانی، نمایندگی، معرفی، انبار)'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write(chr(10))
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
