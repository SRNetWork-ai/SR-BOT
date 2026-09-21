#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed113 - group traffic reset + panel server logs (3x-ui)

  1. Xui3.php    : groupEmails(), groupResetTraffic(), serverLogs()
  2. panels.php  : x3grp / x3log actions + buttons in the panel card tools
"""
import io
import json
import os
import re
import subprocess
import sys
import tempfile

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed113").strip() or "fixed113"

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
        if len(raw) > 250:
            raw = raw[:250] + " ...TRUNC"
        print("  %d|%s" % (i, raw))


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


# ---- 1) Xui3: group + logs endpoints ------------------------------------
X3_TPL = r'''{I}/* 0.0.2 #x3-groups: ریست ترافیک گروهی و لاگ سرور — پنل نسل جدید سنایی */

{I}/** emails of one client group (subId group) */
{I}public function groupEmails(string $name): array
{I}{
{I}    $name = trim($name);
{I}    if ($name === '') return [];
{I}    $r = $this->api('/clients/groups/' . rawurlencode($name) . '/emails');
{I}    if (($r['success'] ?? false) !== true) return [];
{I}    $out = [];
{I}    foreach ((array)($r['obj'] ?? []) as $row) {
{I}        if (is_string($row)) {
{I}            $e = trim($row);
{I}        } else {
{I}            $row = (array)$row;
{I}            $e = trim((string)($row['email'] ?? $row['name'] ?? ''));
{I}        }
{I}        if ($e !== '') $out[] = $e;
{I}    }
{I}    return array_values(array_unique($out));
{I}}

{I}/** zero the traffic counters of one or more client groups */
{I}public function groupResetTraffic(array $names): array
{I}{
{I}    $names = array_values(array_filter(array_map('trim', array_map('strval', $names)), static fn($n) => $n !== ''));
{I}    if (!$names) return ['ok' => false, 'affected' => 0, 'msg' => 'نام گروه خالی است'];
{I}    $r = $this->api('/clients/groups/resetTraffic', ['groups' => $names], 'POST');
{I}    if (($r['success'] ?? false) !== true) {
{I}        $r2 = $this->api('/clients/groups/resetTraffic', ['names' => $names], 'POST');
{I}        if (($r2['success'] ?? false) !== true) {
{I}            return ['ok' => false, 'affected' => 0, 'msg' => (string)($r['msg'] ?? 'خطا در ریست گروهی')];
{I}        }
{I}        $r = $r2;
{I}    }
{I}    $o = (array)($r['obj'] ?? []);
{I}    return ['ok' => true, 'affected' => (int)($o['affected'] ?? $o['count'] ?? 0)];
{I}}

{I}/** last lines of the panel server log */
{I}public function serverLogs(int $count = 50): array
{I}{
{I}    $count = max(1, min(500, $count));
{I}    $r = $this->api('/server/logs/' . $count);
{I}    if (($r['success'] ?? false) !== true) $r = $this->api('/server/logs/' . $count, [], 'POST');
{I}    if (($r['success'] ?? false) !== true) return [];
{I}    $out = [];
{I}    foreach ((array)($r['obj'] ?? []) as $row) {
{I}        $line = is_string($row) ? $row : (string)(((array)$row)['line'] ?? ((array)$row)['msg'] ?? '');
{I}        $line = trim($line);
{I}        if ($line !== '') $out[] = $line;
{I}    }
{I}    return $out;
{I}}

'''


def _x3(m):
    return X3_TPL.replace("{I}", m.group(1)) + m.group(0)


rep_rx("app/Panel/Xui3.php",
       r"^([ \t]*)/\*\* can per-client IP limits be enforced on this host\? \(needs Fail2ban\) \*/\n",
       _x3, marker="0.0.2 #x3-groups")


# ---- 2) panels.php: accept the two new actions --------------------------
rep_rx("admin/pages/panels.php",
       r"if \(\$act === 'x3upd' \|\| \$act === 'x3orph'\) \{",
       lambda m: "if ($act === 'x3upd' || $act === 'x3orph' || $act === 'x3grp' || $act === 'x3log') {",
       marker="'x3orph' || $act === 'x3grp'")

HANDLER_TPL = r'''{I}} elseif ($act === 'x3grp') {
{I}    $raw   = trim((string)($_POST['group'] ?? ''));
{I}    $names = $raw === '' ? [] : (array)preg_split('/[,\s]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
{I}    if (!$names) {
{I}        flash('err', 'نام گروه (subId) را وارد کن؛ چند گروه را با کاما جدا کن.');
{I}    } else {
{I}        try {
{I}            $g = $x3->groupResetTraffic($names);
{I}            if (!empty($g['ok'])) {
{I}                $aff = (int)($g['affected'] ?? 0);
{I}                flash('ok', '♻️ ریست ترافیک گروه انجام شد' . ($aff > 0 ? ' — ' . fa_num((string)$aff) . ' اکانت' : '') . '.');
{I}            } else {
{I}                flash('err', 'ریست گروهی انجام نشد: ' . h((string)($g['msg'] ?? 'نامشخص')));
{I}            }
{I}        } catch (Throwable $e) {
{I}            flash('err', 'ریست گروهی انجام نشد: ' . h($e->getMessage()));
{I}        }
{I}    }
{I}} elseif ($act === 'x3log') {
{I}    try {
{I}        $lines = $x3->serverLogs(30);
{I}        if (!$lines) {
{I}            flash('err', 'لاگی از پنل دریافت نشد؛ این نسخهٔ پنل ممکن است لاگ سرور را ارائه نکند.');
{I}        } else {
{I}            $logOut = [];
{I}            foreach (array_slice($lines, 0, 12) as $ln) $logOut[] = '<code>' . h(mb_substr((string)$ln, 0, 160)) . '</code>';
{I}            flash('ok', 'آخرین لاگ‌های پنل:<br>' . implode('<br>', $logOut));
{I}        }
{I}    } catch (Throwable $e) {
{I}        flash('err', 'دریافت لاگ انجام نشد: ' . h($e->getMessage()));
{I}    }
'''


def _handler(m):
    return HANDLER_TPL.replace("{I}", m.group(1)) + m.group(0)


rep_rx("admin/pages/panels.php",
       r"^([ \t]*)\} elseif \(\$act === 'x3orph'\) \{\n",
       _handler, marker="elseif ($act === 'x3grp')")


# ---- 3) panels.php: two more buttons in the tools row -------------------
UI_TPL = r'''{F}<form method="post" data-confirm="ترافیک گروه واردشده صفر شود؟"><?= csrf_field() ?>
{I}<input type="hidden" name="act" value="x3grp">
{I}<input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
{I}<input class="mono ltr" type="text" name="group" placeholder="subId" style="max-width:120px">
{I}<button class="btn btn-sm">♻️ ریست گروه</button>
{F}</form>
{F}<form method="post"><?= csrf_field() ?>
{I}<input type="hidden" name="act" value="x3log">
{I}<input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
{I}<button class="btn btn-sm">☰ لاگ پنل</button>
{F}</form>
'''


def _ui(m):
    return m.group(0) + UI_TPL.replace("{F}", m.group(4)).replace("{I}", m.group(1))


rep_rx("admin/pages/panels.php",
       r'^([ \t]*)<input type="hidden" name="act" value="x3orph">\n([ \t]*)<input type="hidden" name="id"[^\n]*\n([ \t]*)<button class="btn btn-sm">[^\n]*\n([ \t]*)</form>\n',
       _ui, marker='value="x3grp"')

write_all()

for _p, _needle in [
    ("app/Panel/Xui3.php", "function groupResetTraffic"),
    ("app/Panel/Xui3.php", "function serverLogs"),
    ("admin/pages/panels.php", "elseif ($act === 'x3grp')"),
    ("admin/pages/panels.php", 'value="x3log"'),
    ("admin/pages/panels.php", "x3orph"),
]:
    print("sanity %s / %s : %s" % (_p, _needle, "ok" if _needle in load(_p) else "MISSING"))

dump("PANELS tools row", "admin/pages/panels.php", 457, 482)

CH = "ابزارهای تازهٔ پنل نسل جدید: ریست ترافیک گروهی و نمایش لاگ سرور"
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
