#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed111 - per-plan periodic auto traffic reset (3x-ui client reset field)

  1. Migrate.php  : products.reset_days column (auto-created on upgrade)
  2. schema.sql   : same column for fresh installs (optional)
  3. products.php : admin save + form field
  4. Xui3.php     : $autoReset property used in the addClient payload
  5. Xui.php      : facade setter setAutoReset()
  6. Svc.php      : apply opts/product reset_days before creating the client
"""
import io
import json
import os
import re
import subprocess
import sys
import tempfile

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed111").strip() or "fixed111"

CACHE = {}
NEW = {}
ERRORS = []
WARN = []


def load(path):
    if path in NEW:
        return NEW[path]
    if path not in CACHE:
        with io.open(os.path.join(ROOT, path), encoding="utf-8") as fh:
            CACHE[path] = fh.read()
    return CACHE[path]


def dump(tag, path, start, end):
    try:
        lines = load(path).splitlines()
    except Exception as e:
        print("== %s == missing: %s" % (tag, e))
        return
    print("== %s (%s lines %d-%d of %d) ==" % (tag, path, start, end, len(lines)))
    for i in range(max(1, start), min(end, len(lines)) + 1):
        raw = lines[i - 1]
        if len(raw) > 240:
            raw = raw[:240] + " ...TRUNC"
        print("  %d|%s" % (i, raw))


def dump_find(tag, path, pattern, before=0, after=20):
    try:
        lines = load(path).splitlines()
    except Exception as e:
        print("== %s == missing: %s" % (tag, e))
        return
    for i, line in enumerate(lines, 1):
        if re.search(pattern, line):
            dump(tag, path, i - before, i + after)
            return
    print("== %s == pattern not found: %s" % (tag, pattern))


def rep_rx(path, pattern, fn, marker=None, expect=1, optional=False, flags=re.M):
    try:
        s = load(path)
    except Exception as e:
        (WARN if optional else ERRORS).append("%s: %s" % (path, e))
        return
    if marker and marker in s:
        print("skip (already applied): %s / %s" % (path, marker))
        return
    n = len(re.findall(pattern, s, flags=flags))
    if n != expect:
        (WARN if optional else ERRORS).append(
            "%s: regex for %s matched %d times (want %d)" % (path, marker or pattern, n, expect)
        )
        return
    NEW[path] = re.sub(pattern, fn, s, count=0, flags=flags)
    print("patched %s by regex (%s)" % (path, marker or "rx"))


def write_all():
    if ERRORS:
        print("ABORTED - anchors not found:")
        for e in ERRORS:
            print("  - " + e)
        sys.exit(1)
    if WARN:
        print("warnings (optional patches skipped):")
        for w in WARN:
            print("  - " + w)
    for path in list(NEW.keys()):
        data = NEW[path].encode("utf-8")
        if path.endswith(".php"):
            tmp = os.path.join(tempfile.gettempdir(), "syntax-check.php")
            with open(tmp, "wb") as fh:
                fh.write(data)
            r = subprocess.run(["php", "-l", tmp], capture_output=True, text=True)
            if r.returncode != 0:
                print("php lint FAILED: %s" % path)
                print((r.stdout or "") + (r.stderr or ""))
                sys.exit(1)
        with open(os.path.join(ROOT, path), "wb") as fh:
            fh.write(data)
        print("wrote " + path)
    print("php lint: on")
    print("changed files: %d" % len(NEW))


# ---- recon dumps (printed even when a patch aborts the run) ---------------
dump_find("SCHEMA products device_limit", "database/schema.sql", r"device_limit", before=3, after=5)
dump_find("XUI3 reset field", "app/Panel/Xui3.php", r"'reset'", before=8, after=6)
dump_find("XUI3 constructor", "app/Panel/Xui3.php", r"function __construct", before=8, after=2)


# ---- 1) Migrate.php -------------------------------------------------------
MIG_TPL = '''{I}/* 0.0.2: ریست خودکار دوره‌ای حجم (روز) — ۰ = خاموش */
{I}'reset_days'   => 'INT NOT NULL DEFAULT 0',
'''


def _mig(m):
    return m.group(0) + MIG_TPL.replace("{I}", m.group(1))


rep_rx("app/Service/Migrate.php",
       r"^([ \t]*)'device_limit' => 'INT NOT NULL DEFAULT 0',[ \t]*\n",
       _mig, marker="'reset_days'")


# ---- 2) schema.sql (fresh installs) --------------------------------------
def _schema(m):
    return m.group(0) + m.group(1) + "reset_days INT NOT NULL DEFAULT 0,\n"


rep_rx("database/schema.sql",
       r"^([ \t]*)`?device_limit`?[ \t]+INT[^\n]*\n",
       _schema, marker="reset_days", optional=True)


# ---- 3) admin products: save + form -------------------------------------
rep_rx("admin/pages/products.php",
       r"foreach \(\['device_limit', 'speed_up', 'speed_down'\] as \$lk\)",
       lambda m: "foreach (['device_limit', 'speed_up', 'speed_down', 'reset_days'] as $lk)",
       marker="'reset_days'] as $lk")

FIELD_TPL = '''{O}<?php if (isset($prCols['reset_days'])): ?>
{O}<div class="field">
{I}<label>ریست دوره‌ای حجم (روز)</label>
{I}<input class="mono" type="number" min="0" max="365" name="reset_days" value="<?= h((string)$v('reset_days', 0)) ?>">
{I}<div class="hint">۰ = خاموش — مثلاً ۳۰ یعنی هر ۳۰ روز مصرف کاربر خودکار صفر می‌شود (فقط پنل نسل جدید سنایی)</div>
{O}</div>
{O}<?php endif; ?>
'''


def _field(m):
    return m.group(0) + FIELD_TPL.replace("{O}", m.group(4)).replace("{I}", m.group(2))


rep_rx("admin/pages/products.php",
       r'^([ \t]*)<label>محدودیت دستگاه \(Device limit\)</label>\n([ \t]*)<input[^\n]*name="device_limit"[^\n]*\n([ \t]*)<div class="hint">[^\n]*\n([ \t]*)</div>\n',
       _field, marker='name="reset_days"')


# ---- 4) Xui3.php ---------------------------------------------------------
PROP_TPL = '''{I}/* 0.0.2 #x3-reset: دورهٔ ریست خودکار حجم (روز) برای اکانت تازه — ۰ = خاموش */
{I}public $autoReset = 0;

'''


def _prop(m):
    return PROP_TPL.replace("{I}", m.group(1)) + m.group(0)


rep_rx("app/Panel/Xui3.php",
       r"^([ \t]*)(?:public )?function __construct\b",
       _prop, marker="public $autoReset")

rep_rx("app/Panel/Xui3.php",
       r"^([ \t]*)'reset'[ \t]*=>[ \t]*0,[ \t]*$",
       lambda m: m.group(0).replace("0,", "max(0, (int)$this->autoReset),", 1),
       marker="$this->autoReset")


# ---- 5) Xui.php facade --------------------------------------------------
FACADE_TPL = '''{I}/* 0.0.2 #x3-reset: تعیین دورهٔ ریست خودکار حجم پیش از ساخت اکانت — فقط پنل نسل جدید سنایی */
{I}public function setAutoReset(int $days): void
{I}{
{I}    if ($this->x3) $this->x3->autoReset = max(0, $days);
{I}}

'''


def _facade(m):
    return FACADE_TPL.replace("{I}", m.group(1)) + m.group(0)


rep_rx("app/Panel/Xui.php",
       r"^([ \t]*)/\*\*\n[ \t]*\* افزودن کلاینت جدید\n",
       _facade, marker="public function setAutoReset")


# ---- 6) Svc.php --------------------------------------------------------
SVC_TPL = '''{I}/* 0.0.2 #svc-reset: ریست دوره‌ای حجم — فقط پنل نسل جدید سنایی */
{I}$rstDays = (int)($opts['reset_days'] ?? 0);
{I}if ($rstDays <= 0 && is_array($product)) $rstDays = (int)($product['reset_days'] ?? 0);
{I}if ($rstDays > 0 && method_exists($xui, 'setAutoReset')) {
{I}    try { $xui->setAutoReset($rstDays); } catch (Throwable $e) { app_log('svc', 'setAutoReset: ' . $e->getMessage(), []); }
{I}}
'''


def _svc(m):
    return m.group(0) + SVC_TPL.replace("{I}", m.group(1))


rep_rx("app/Service/Svc.php",
       r"^([ \t]*)\$links = \[\]; \$okIds = \[\]; \$lastErr = '';\n[ \t]*\$devLim = \$devOpt;[ \t]*\n",
       _svc, marker="#svc-reset")

write_all()

for _p, _needle in [
    ("app/Service/Migrate.php", "'reset_days'"),
    ("admin/pages/products.php", 'name="reset_days"'),
    ("app/Panel/Xui3.php", "$this->autoReset"),
    ("app/Panel/Xui.php", "public function setAutoReset"),
    ("app/Service/Svc.php", "#svc-reset"),
]:
    print("sanity %s / %s : %s" % (_p, _needle, "ok" if _needle in load(_p) else "MISSING"))

CH = "ریست خودکار دوره‌ای حجم برای هر پلن (فیلد reset پنل نسل جدید سنایی) — قابل تنظیم در فرم محصول"
VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)
v["build"] = BUILD
cl = v.get("changelog")
if isinstance(cl, list) and (not cl or cl[0] != CH):
    cl.insert(0, CH)
    v["changelog"] = cl
    print("changelog: entry added")
with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")
print("build: " + BUILD)
