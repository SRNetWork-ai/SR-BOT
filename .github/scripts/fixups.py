# -*- coding: utf-8 -*-
# fixed170 - row 22: zarinpal pay card + status check inside the mini-app
import io, os, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed170').strip() or 'fixed170'
CACHE = {}
ORIG = {}
ERRORS = []
WARN = []
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json')


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


def files_all():
    out = []
    for base, dirs, names in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        for n in names:
            if n.endswith(EXT):
                out.append(os.path.relpath(os.path.join(base, n), ROOT))
    return sorted(out)


def grep_all(needle, limit=20):
    hits = 0
    for p in files_all():
        try:
            s = load(p)
        except Exception:
            continue
        for i, ln in enumerate(s.split(chr(10))):
            if needle in ln:
                hits += 1
                if hits <= limit:
                    print('  %-26s %5d| %s' % (p, i + 1, ln.strip()[:120]))
    print('---- grep: %s (%d) ----' % (needle, hits))


def dump(tag, path, start, end):
    lines = load(path).split(chr(10))
    print('---- dump %s (%s:%d-%d) ----' % (tag, path, start, end))
    for i in range(max(0, start - 1), min(len(lines), end)):
        print('%5d|%s' % (i + 1, lines[i]))
    print('---- end dump %s ----' % tag)


IDX = 'miniapp/index.php'
API = 'miniapp/api.php'

# ============================================================
# 1) render branch (wallet top-up view)
# ============================================================
old1 = (
    "      } else if (r.method === 'hooshpay') {\n"
    "        h += hpPayBox(r);\n"
    "      } else {\n"
)
new1 = (
    "      } else if (r.method === 'zarinpal') { /* 0.0.2 #22 zp-render1 */\n"
    "        h += zpPayBox(r);\n"
    "      } else if (r.method === 'hooshpay') {\n"
    "        h += hpPayBox(r);\n"
    "      } else {\n"
)
rep_lit(IDX, old1, new1, 'zp-render1')

# ============================================================
# 2) render branch (top-up sheet)
# ============================================================
old2 = (
    "    } else if (r.method === 'hooshpay') {\n"
    "      h += hpPayBox(r);\n"
    "    } else {\n"
)
new2 = (
    "    } else if (r.method === 'zarinpal') { /* 0.0.2 #22 zp-render2 */\n"
    "      h += zpPayBox(r);\n"
    "    } else if (r.method === 'hooshpay') {\n"
    "      h += hpPayBox(r);\n"
    "    } else {\n"
)
rep_lit(IDX, old2, new2, 'zp-render2')

# ============================================================
# 3) zpPayBox + zpCheck helpers
# ============================================================
old3 = "  function hpPayBox(r) {"
new3 = (
    "  /* ================= \u0632\u0631\u06cc\u0646\u200c\u067e\u0627\u0644 \u2013 \u06a9\u0627\u0631\u062a \u067e\u0631\u062f\u0627\u062e\u062a \u0648 \u0628\u0631\u0631\u0633\u06cc \u0648\u0636\u0639\u06cc\u062a ================= */\n"
    "  /* 0.0.2 #22 zp-paybox */\n"
    "  function zpPayBox(r) {\n"
    "    var h = '<div class=\"sec-t\"><span>\U0001F3E6 \u067e\u0631\u062f\u0627\u062e\u062a \u0622\u0646\u06cc \u0632\u0631\u06cc\u0646\u200c\u067e\u0627\u0644</span>' +\n"
    "      (r.tx ? '<span class=\"bdg b\">\u067e\u06cc\u06af\u06cc\u0631\u06cc #' + esc(String(r.tx)) + '</span>' : '') + '</div>' +\n"
    "      '<div class=\"card tight\">';\n"
    "\n"
    "    if (r.amount_txt) {\n"
    "      h += '<div style=\"font-size:11.5px;color:var(--dim)\">\u0645\u0628\u0644\u063a \u0642\u0627\u0628\u0644 \u067e\u0631\u062f\u0627\u062e\u062a</div>' +\n"
    "        '<div class=\"mono\" style=\"font-weight:800;font-size:17px;margin:2px 0 4px\">' + esc(r.amount_txt) + '</div>';\n"
    "    }\n"
    "    if (r.note) h += '<div class=\"hint\">\u2139\uFE0F ' + esc(r.note) + '</div>';\n"
    "    h += '</div>';\n"
    "\n"
    "    if (r.url) {\n"
    "      h += '<a class=\"btn w\" href=\"' + esc(r.url) + '\" target=\"_blank\" rel=\"noopener\" ' +\n"
    "        'style=\"margin-top:8px;display:block;text-align:center;text-decoration:none\">\U0001F3E6 \u0631\u0641\u062a\u0646 \u0628\u0647 \u062f\u0631\u06af\u0627\u0647 \u0628\u0627\u0646\u06a9\u06cc</a>';\n"
    "    }\n"
    "\n"
    "    h += '<button type=\"button\" class=\"btn gh w\" data-zpchk=\"' + esc(String(r.tx || 0)) +\n"
    "      '\" style=\"margin-top:8px\">\U0001F504 \u0628\u0631\u0631\u0633\u06cc \u0648\u0636\u0639\u06cc\u062a \u067e\u0631\u062f\u0627\u062e\u062a</button><div id=\"zpChkOut\"></div>';\n"
    "\n"
    "    return h;\n"
    "  }\n"
    "\n"
    "  function zpCheck(el) {\n"
    "    var tx = parseInt(el.getAttribute('data-zpchk') || '0', 10) || 0;\n"
    "    if (tx <= 0) { toast('\u0634\u0646\u0627\u0633\u0647\u0654 \u067e\u0631\u062f\u0627\u062e\u062a \u067e\u06cc\u062f\u0627 \u0646\u0634\u062f.', 'err'); return; }\n"
    "\n"
    "    var old = el.innerHTML;\n"
    "    el.disabled = true;\n"
    "    el.innerHTML = '<span class=\"spin\"></span> \u062f\u0631 \u062d\u0627\u0644 \u0628\u0631\u0631\u0633\u06cc\u2026';\n"
    "\n"
    "    api('topup_zp_check', { tx: tx }).then(function (r) {\n"
    "      el.disabled = false;\n"
    "      el.innerHTML = old;\n"
    "\n"
    "      var msg = (r && r.message) ? r.message : '\u067e\u0627\u0633\u062e\u06cc \u0627\u0632 \u0633\u0631\u0648\u0631 \u062f\u0631\u06cc\u0627\u0641\u062a \u0646\u0634\u062f.';\n"
    "      var cl = 'w';\n"
    "      if (r && r.paid) cl = 'i';\n"
    "      else if (r && r.dead) cl = 'e';\n"
    "\n"
    "      var out = $('zpChkOut');\n"
    "      if (out) {\n"
    "        out.innerHTML = '<div class=\"alert ' + cl + '\" style=\"margin-top:8px\">' + esc(msg) +\n"
    "          ((r && r.paid && r.balance_txt) ? '<br>\u0645\u0648\u062c\u0648\u062f\u06cc \u062c\u062f\u06cc\u062f: ' + esc(r.balance_txt) : '') + '</div>';\n"
    "      } else {\n"
    "        toast(msg, (r && r.paid) ? 'ok' : 'i');\n"
    "      }\n"
    "\n"
    "      if (r && r.paid) toast('\u06a9\u06cc\u0641 \u067e\u0648\u0644 \u0634\u0627\u0631\u0698 \u0634\u062f.', 'ok');\n"
    "    });\n"
    "  }\n"
    "\n"
    "  function hpPayBox(r) {"
)
rep_lit(IDX, old3, new3, 'zp-paybox')

# ============================================================
# 4) click dispatcher binding
# ============================================================
old4 = "    if ((el = t.closest('[data-hpchk]'))) { haptic(); hpCheck(el); return; }"
new4 = (
    "    if ((el = t.closest('[data-zpchk]'))) { haptic(); zpCheck(el); return; } /* 0.0.2 #22 zp-bind */\n"
    "    if ((el = t.closest('[data-hpchk]'))) { haptic(); hpCheck(el); return; }"
)
rep_lit(IDX, old4, new4, 'zp-bind')

# ============================================================
# 5) topup_zp_check endpoint
# ============================================================
old5 = "    case 'topup_hp_check': {"
new5 = (
    "    /* ---------------- \u0628\u0631\u0631\u0633\u06cc \u0648\u0636\u0639\u06cc\u062a \u067e\u0631\u062f\u0627\u062e\u062a \u0632\u0631\u06cc\u0646\u200c\u067e\u0627\u0644 \u0627\u0632 \u062f\u0627\u062e\u0644 \u0645\u06cc\u0646\u06cc\u200c\u0627\u067e ---------------- */\n"
    "    case 'topup_zp_check': { /* 0.0.2 #22 zp-chk-api */\n"
    "        if (!class_exists('Zarinpal')) ma_fail('\u062f\u0631\u06af\u0627\u0647 \u0632\u0631\u06cc\u0646\u200c\u067e\u0627\u0644 \u062f\u0631 \u062f\u0633\u062a\u0631\u0633 \u0646\u06cc\u0633\u062a.');\n"
    "\n"
    "        $zpTx  = (int)($in['tx'] ?? 0);\n"
    "        $zpRow = DB::one(\"SELECT * FROM {p}transactions WHERE id = :i AND user_id = :u AND method = 'zarinpal'\",\n"
    "            [':i' => $zpTx, ':u' => $UID]);\n"
    "        if (!$zpRow) ma_fail('\u0627\u06cc\u0646 \u062f\u0631\u062e\u0648\u0627\u0633\u062a \u0634\u0627\u0631\u0698 \u067e\u06cc\u062f\u0627 \u0646\u0634\u062f.');\n"
    "\n"
    "        $zpBal = static function () use ($UID) {\n"
    "            return (int)DB::val('SELECT balance FROM {p}users WHERE id = :id', [':id' => $UID], 0);\n"
    "        };\n"
    "\n"
    "        $zpSt = (string)($zpRow['status'] ?? '');\n"
    "\n"
    "        if ($zpSt === 'approved') {\n"
    "            ma_out(['ok' => true, 'paid' => true, 'dead' => false,\n"
    "                'balance_txt' => ma_money($zpBal()),\n"
    "                'message' => '\u067e\u0631\u062f\u0627\u062e\u062a \u062a\u0627\u06cc\u06cc\u062f \u0634\u062f\u0647 \u0648 \u06a9\u06cc\u0641 \u067e\u0648\u0644 \u0634\u0645\u0627 \u0634\u0627\u0631\u0698 \u0634\u062f\u0647 \u0627\u0633\u062a.']);\n"
    "        }\n"
    "\n"
    "        if ($zpSt === 'rejected') {\n"
    "            ma_out(['ok' => true, 'paid' => false, 'dead' => true,\n"
    "                'balance_txt' => ma_money($zpBal()),\n"
    "                'message' => '\u0627\u06cc\u0646 \u067e\u0631\u062f\u0627\u062e\u062a \u0646\u0627\u0645\u0648\u0641\u0642 \u06cc\u0627 \u0644\u063a\u0648 \u0634\u062f\u0647 \u0627\u0633\u062a\u061b \u0644\u0637\u0641\u0627\u064b \u06cc\u06a9 \u062f\u0631\u062e\u0648\u0627\u0633\u062a \u062a\u0627\u0632\u0647 \u0628\u0633\u0627\u0632\u06cc\u062f.']);\n"
    "        }\n"
    "\n"
    "        $zpRes  = Zarinpal::poll((array)$zpRow);\n"
    "        $zpSt2  = (string)DB::val('SELECT status FROM {p}transactions WHERE id = :i', [':i' => $zpTx], '');\n"
    "        $zpPaid = ($zpSt2 === 'approved') || !empty($zpRes['paid']);\n"
    "        $zpDead = ($zpSt2 === 'rejected') || !empty($zpRes['dead']);\n"
    "\n"
    "        ma_out(['ok' => true, 'paid' => $zpPaid, 'dead' => $zpDead,\n"
    "            'balance_txt' => ma_money($zpBal()),\n"
    "            'message' => (string)($zpRes['message'] ?? ($zpPaid\n"
    "                ? '\u067e\u0631\u062f\u0627\u062e\u062a \u062a\u0627\u06cc\u06cc\u062f \u0634\u062f \u0648 \u06a9\u06cc\u0641 \u067e\u0648\u0644 \u0634\u0627\u0631\u0698 \u0634\u062f.'\n"
    "                : '\u0647\u0646\u0648\u0632 \u067e\u0631\u062f\u0627\u062e\u062a \u0645\u0648\u0641\u0642\u06cc \u0628\u0631\u0627\u06cc \u0627\u06cc\u0646 \u0641\u0627\u06a9\u062a\u0648\u0631 \u062b\u0628\u062a \u0646\u0634\u062f\u0647 \u0627\u0633\u062a.'))]);\n"
    "    }\n"
    "\n"
    "    case 'topup_hp_check': {"
)
rep_lit(API, old5, new5, 'zp-chk-api')

# ============================================================
# write
# ============================================================
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
if WARN:
    print('warnings (optional patches skipped):')
    for w in WARN:
        print('  - %s' % w)

# ============================================================
# recon / sanity
# ============================================================
print('===== sanity =====')
CACHE.clear()
ORIG.clear()
for needle in ['zpPayBox', 'zpCheck', 'data-zpchk', 'topup_zp_check', 'topup_hp_check']:
    grep_all(needle, 14)
dump('zp_poll', 'app/Service/Zarinpal.php', 316, 332)
for p in [IDX, API]:
    s = load(p)
    print('%s: %d lines, %d bytes' % (p, len(s.split(chr(10))), len(s.encode('utf-8'))))

# ============================================================
# version bump
# ============================================================
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = '0.0.2 #22: \u06a9\u0627\u0631\u062a \u067e\u0631\u062f\u0627\u062e\u062a \u0632\u0631\u06cc\u0646\u200c\u067e\u0627\u0644 \u0648 \u062f\u06a9\u0645\u0647\u0654 \u0628\u0631\u0631\u0633\u06cc \u0648\u0636\u0639\u06cc\u062a \u062f\u0631 \u0645\u06cc\u0646\u06cc\u200c\u0627\u067e'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write(chr(10))
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
