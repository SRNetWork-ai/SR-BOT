# -*- coding: utf-8 -*-
# fixed157 - 0.0.2 #22: Zarinpal admin UI in gateways.php (save handler, kind card, fieldset, JS) + bot recon.
import io, os, sys, json

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed157').strip() or 'fixed157'

CACHE = {}
NEW = set()
ERRORS = []
WARN = []
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'storage', 'uploads', 'backups'}
EXT = ('.php', '.sql', '.js', '.html', '.json')

GWP = 'admin/pages/gateways.php'


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8', errors='replace') as fh:
        CACHE[path] = fh.read()
    return CACHE[path]


def rep_lit(path, old, new, marker, optional=False):
    src = load(path)
    if marker in src:
        print('skip (already applied): %s' % marker)
        return
    n = src.count(old)
    if n != 1:
        msg = '%s: anchor for %s matched %d times (want 1)' % (path, marker, n)
        if optional:
            WARN.append(msg)
            print('SKIP optional: ' + msg)
        else:
            ERRORS.append(msg)
        return
    CACHE[path] = src.replace(old, new)
    NEW.add(path)
    print('patched: %s (%s)' % (path, marker))


def dump(tag, path, start, end):
    try:
        lines = load(path).split(chr(10))
    except Exception as e:
        print('---- dump %s FAILED (%s) ----' % (tag, e))
        return
    print('---- dump %s : %s (%d lines) ----' % (tag, path, len(lines)))
    for i in range(max(1, start), min(len(lines), end) + 1):
        s = lines[i - 1]
        if len(s) > 200:
            s = s[:200] + ' ...'
        print('%5d %s' % (i, s))
    print('---- end dump %s ----' % tag)


def dump_find(tag, path, needle, before=3, after=30):
    try:
        lines = load(path).split(chr(10))
    except Exception as e:
        print('---- dump %s FAILED (%s) ----' % (tag, e))
        return
    for i, ln in enumerate(lines, 1):
        if needle in ln:
            dump(tag, path, i - before, i + after)
            return
    print('---- dump %s : needle not found (%s) ----' % (tag, needle))


ALL = []


def files_all():
    if ALL:
        return ALL
    for base, dirs, fns in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        for fn in sorted(fns):
            if fn.endswith(EXT):
                ALL.append(os.path.relpath(os.path.join(base, fn), ROOT))
    ALL.sort()
    return ALL


def grep_all(needle, limit=20, only=None):
    print('---- grep: %s ----' % needle)
    n = 0
    for p in files_all():
        if only and not p.startswith(only):
            continue
        try:
            lines = load(p).split(chr(10))
        except Exception:
            continue
        for i, ln in enumerate(lines, 1):
            if needle in ln:
                s = ln.strip()
                if len(s) > 170:
                    s = s[:170] + ' ...'
                print('%s:%d: %s' % (p, i, s))
                n += 1
                if n >= limit:
                    print('---- end grep: %s (truncated) ----' % needle)
                    return
    print('---- end grep: %s (%d) ----' % (needle, n))


# ==================================================================
# 1) save handler
# ==================================================================
HP_TAIL = (
    "            DB::setSetting('hp_enabled',  (string)pchk('enabled'));\n"
    "        }\n"
)

ZP_SAVE = r"""
        if (ptxt('kind') === 'zarinpal') { /* 0.0.2 #22 */
            DB::setSetting('zp_merchant', ptxt('zp_merchant', 60));
            DB::setSetting('zp_unit',     ptxt('zp_unit', 20));
            DB::setSetting('zp_audience', ptxt('zp_audience', 20));
            DB::setSetting('zp_min',      (string)max(0, pint('zp_min', 0)));
            DB::setSetting('zp_max',      (string)max(0, pint('zp_max', 0)));
            DB::setSetting('zp_desc',     ptxt('zp_desc', 200));
            DB::setSetting('zp_sandbox',  (string)pchk('zp_sandbox'));
            DB::setSetting('zp_label',    ptxt('label', 120));
            DB::setSetting('zp_icon',     ptxt('icon', 20));
            DB::setSetting('zp_enabled',  (string)pchk('enabled'));
        }
"""

rep_lit(GWP, HP_TAIL, HP_TAIL + ZP_SAVE, "ptxt('kind') === 'zarinpal'")

# ==================================================================
# 2) kind description
# ==================================================================
rep_lit(
    GWP,
    "                  'hooshpay' => '",
    "                  'zarinpal' => '\u067e\u0631\u062f\u0627\u062e\u062a \u0622\u0646\u0644\u0627\u06cc\u0646 \u0628\u0627 \u06a9\u0627\u0631\u062a \u0634\u062a\u0627\u0628 (\u0632\u0631\u06cc\u0646\u200c\u067e\u0627\u0644)',\n"
    "                  'hooshpay' => '",
    "'zarinpal' => '",
)

# ==================================================================
# 3) list card flags
# ==================================================================
rep_lit(
    GWP,
    "        $isHp   = $g['kind'] === 'hooshpay';\n",
    "        $isHp   = $g['kind'] === 'hooshpay';\n"
    "        $isZp   = $g['kind'] === 'zarinpal'; /* 0.0.2 #22 */\n",
    "$isZp   = $g['kind']",
)

rep_lit(
    GWP,
    "        elseif ($isHp) { $val = class_exists('HooshPay') ? HooshPay::callbackUrl() : 'HooshPay'; }\n",
    "        elseif ($isHp) { $val = class_exists('HooshPay') ? HooshPay::callbackUrl() : 'HooshPay'; }\n"
    "        elseif ($isZp) { $val = class_exists('Zarinpal') ? Zarinpal::callbackUrl() : 'ZarinPal'; }\n",
    "$isZp) { $val",
)

# ==================================================================
# 4) settings fieldset in the modal
# ==================================================================
ZP_FIELDSET = r"""          <?php if (class_exists('Zarinpal')): ?>
          <div class="fieldset accent" id="gwZp">
            <div class="lg"><span class="n"><?= "\u{1F3E6}" ?></span> تنظیمات زرین‌پال (ZarinPal)</div>
            <div class="fs-hint">مرچنت کد ۳۶ کاراکتری را از پنل زرین‌پال بخش «درگاه‌های پرداخت» بگیرید. با ذخیره، درگاه فعال می‌شود.</div>
            <div class="form-grid g2">
              <div class="field" style="grid-column:1/-1"><label>مرچنت کد <span style="color:var(--red)">*</span></label>
                <input class="mono ltr" type="text" name="zp_merchant" autocomplete="off" maxlength="60"
                  value="<?= h((string)DB::setting('zp_merchant', '')) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                <div class="hint">بدون مرچنت کد، درگاه روشن نمی‌شود.</div></div>

              <div class="field"><label>واحد مبلغ فروشگاه</label>
                <select name="zp_unit">
                  <?php $zpU = (string)DB::setting('zp_unit', 'toman'); ?>
                  <?php foreach (Zarinpal::UNITS as $uk => $uv): ?>
                    <option value="<?= h((string)$uk) ?>" <?= $zpU === (string)$uk ? 'selected' : '' ?>><?= h((string)$uv) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="hint">همین واحد به زرین‌پال اعلام می‌شود (تومان = IRT، ریال = IRR).</div></div>

              <div class="field"><label>مخاطب درگاه</label>
                <select name="zp_audience">
                  <?php $zpA = (string)DB::setting('zp_audience', 'all'); ?>
                  <?php foreach (Gateway::AUDIENCE as $ak => $av): ?>
                    <option value="<?= h((string)$ak) ?>" <?= $zpA === (string)$ak ? 'selected' : '' ?>><?= h((string)$av) ?></option>
                  <?php endforeach; ?>
                </select></div>

              <div class="field"><label>حداقل مبلغ</label>
                <input type="number" name="zp_min" min="0" value="<?= (int)DB::setting('zp_min', 0) ?>" placeholder="0 = بدون محدودیت"></div>

              <div class="field"><label>حداکثر مبلغ</label>
                <input type="number" name="zp_max" min="0" value="<?= (int)DB::setting('zp_max', 0) ?>" placeholder="0 = بدون محدودیت"></div>

              <div class="field" style="grid-column:1/-1"><label>توضیح تراکنش</label>
                <input type="text" name="zp_desc" maxlength="120"
                  value="<?= h((string)DB::setting('zp_desc', '')) ?>" placeholder="شارژ کیف پول"></div>

              <div class="field" style="grid-column:1/-1">
                <label class="pick"><input type="checkbox" name="zp_sandbox" value="1" <?= (string)DB::setting('zp_sandbox', '0') === '1' ? 'checked' : '' ?>> حالت تست (Sandbox)</label>
                <div class="hint">فقط برای آزمایش؛ در حالت عادی خاموش باشد.</div>
              </div>

              <div class="field" style="grid-column:1/-1">
                <div class="hint">آدرس بازگشت: <b class="mono ltr"><?= h(Zarinpal::callbackUrl()) ?></b></div>
                <div class="hint">همین آدرس در هر تراکنش ارسال می‌شود؛ در پنل زرین‌پال نیازی به ثبت دستی نیست.</div>
              </div>
            </div>
          </div>
          <?php endif; ?>

"""

rep_lit(
    GWP,
    "          <?php if (class_exists('HooshPay')): ?>\n",
    ZP_FIELDSET + "          <?php if (class_exists('HooshPay')): ?>\n",
    'id="gwZp"',
)

# ==================================================================
# 5) modal JS
# ==================================================================
rep_lit(
    GWP,
    "bn = $('gwNp'), bh = $('gwHp'),",
    "bn = $('gwNp'), bh = $('gwHp'), bz = $('gwZp'),",
    "bz = $('gwZp')",
)

rep_lit(
    GWP,
    "    if (bh) bh.style.display = k === 'hooshpay' ? '' : 'none';\n",
    "    if (bh) bh.style.display = k === 'hooshpay' ? '' : 'none';\n"
    "    if (bz) bz.style.display = k === 'zarinpal' ? '' : 'none';\n",
    'bz.style.display',
)

rep_lit(
    GWP,
    "var KIND_T = { crypto: ",
    "var KIND_T = { zarinpal: '\u0632\u0631\u06cc\u0646\u200c\u067e\u0627\u0644', crypto: ",
    'KIND_T = { zarinpal',
)

rep_lit(
    GWP,
    "(k === 'hooshpay' ? '#f59e0b' : (COLORS[ak] || '#22c55e'))",
    "(k === 'zarinpal' ? '#ffd400' : (k === 'hooshpay' ? '#f59e0b' : (COLORS[ak] || '#22c55e')))",
    "k === 'zarinpal' ? '#ffd400'",
)

rep_lit(
    GWP,
    "    if (k === 'hooshpay') val = 'HooshPay';\n",
    "    if (k === 'hooshpay') val = 'HooshPay';\n"
    "    if (k === 'zarinpal') val = 'ZarinPal';\n",
    "val = 'ZarinPal'",
)

# ---------------- write ----------------
if ERRORS:
    print('ABORTED - anchors not found:')
    for e in ERRORS:
        print(' - ' + e)
    print('exit: 1')
    sys.exit(1)

if WARN:
    print('warnings (optional patches skipped):')
    for w in WARN:
        print(' - ' + w)

for p in sorted(NEW):
    with io.open(os.path.join(ROOT, p), 'w', encoding='utf-8') as fh:
        fh.write(CACHE[p])
    print('wrote ' + p)
print('changed files: %d' % len(NEW))

SANITY = [
    (GWP, "ptxt('kind') === 'zarinpal'"),
    (GWP, 'id="gwZp"'),
    (GWP, "name=\"zp_merchant\""),
    (GWP, "bz = $('gwZp')"),
    (GWP, "val = 'ZarinPal'"),
    (GWP, "$isZp   = $g['kind']"),
]
ok = 0
for p, needle in SANITY:
    good = needle in load(p)
    print('sanity %s : %s' % (needle[:44], 'ok' if good else 'MISSING'))
    if good:
        ok += 1
print('sanity: %d/%d' % (ok, len(SANITY)))

# ---------------- recon: bot wallet flow ----------------
print('===== Btn.php =====')
dump('btn', 'app/Service/Btn.php', 1, 110)
print('===== magic dispatch? =====')
grep_all('__callStatic', 8)
grep_all('Amount(int', 20, only='app/Bot')
print('===== hooshpay inside bot =====')
grep_all('hooshpay', 30, only='app/Bot')
print('===== HooshPay:: usages =====')
grep_all('HooshPay::', 30)

# ---------------- version bump ----------------
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = '0.0.2 #22: \u0641\u0631\u0645 \u062a\u0646\u0638\u06cc\u0645\u0627\u062a \u0632\u0631\u06cc\u0646\u200c\u067e\u0627\u0644 \u062f\u0631 \u067e\u0646\u0644 \u0645\u062f\u06cc\u0631\u06cc\u062a'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
