#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed115 - slim inbound list + fast panel ping

  1. Xui3.php : $slimCache property, inboundsSlim() via /inbounds/list/slim, ping()
  2. Xui.php  : facade inboundsSlim() + pingFast()
  3. Svc.php  : panel aliveness check uses the cheap ping instead of the full list
"""
import io
import json
import os
import re
import subprocess
import sys
import tempfile

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed115").strip() or "fixed115"

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


# ---- 1) Xui3: slim cache property --------------------------------------
PROP_TPL = r'''{I}/** کش فهرست سبک اینباندها (list/slim) */
{I}private ?array $slimCache = null;
'''


def _prop(m):
    return m.group(0) + PROP_TPL.replace("{I}", m.group(1))


rep_rx("app/Panel/Xui3.php",
       r"^([ \t]*)private \?array \$inboundCache = null;\n",
       _prop, marker="private ?array $slimCache")


# ---- 2) Xui3: inboundsSlim() + ping() ----------------------------------
X3_TPL = r'''{I}/* 0.0.2 #x3-slim: فهرست سبک اینباندها — مناسب انتخاب و تست سریع */
{I}public function inboundsSlim(): array
{I}{
{I}    if ($this->slimCache !== null) return $this->slimCache;
{I}    $r    = $this->api('/inbounds/list/slim');
{I}    $rows = ($r['success'] ?? false) === true ? (array)($r['obj'] ?? []) : $this->inboundOptions();
{I}    $out  = [];
{I}    foreach ($rows as $row) {
{I}        if (is_numeric($row)) {
{I}            $out[] = ['id' => (int)$row, 'remark' => '', 'protocol' => '', 'port' => 0, 'enable' => true, 'clients' => 0];
{I}            continue;
{I}        }
{I}        $row = (array)$row;
{I}        $id  = (int)($row['id'] ?? 0);
{I}        if ($id <= 0) continue;
{I}        $out[] = [
{I}            'id'       => $id,
{I}            'remark'   => (string)($row['remark'] ?? $row['name'] ?? ''),
{I}            'protocol' => (string)($row['protocol'] ?? ''),
{I}            'port'     => (int)($row['port'] ?? 0),
{I}            'enable'   => !isset($row['enable']) || !empty($row['enable']),
{I}            'clients'  => (int)($row['clientCount'] ?? $row['clients'] ?? 0),
{I}        ];
{I}    }
{I}    return $this->slimCache = $out;
{I}}

{I}/** تست سریع زنده‌بودن پنل با یک درخواست سبک */
{I}public function ping(): bool
{I}{
{I}    if ($this->inboundsSlim() !== []) return true;
{I}    return $this->inbounds() !== [];
{I}}

'''


def _x3(m):
    return X3_TPL.replace("{I}", m.group(1)) + m.group(0)


rep_rx("app/Panel/Xui3.php",
       r"^([ \t]*)public function allowedInboundIds\(\): array\n",
       _x3, marker="function inboundsSlim")


# ---- 3) Xui facade -----------------------------------------------------
FACADE_TPL = r'''{I}/* 0.0.2 #x3-slim: فهرست سبک اینباندها و تست سریع اتصال */
{I}public function inboundsSlim(): array
{I}{
{I}    if ($this->x3) return $this->x3->inboundsSlim();
{I}    $out = [];
{I}    foreach ($this->inbounds() as $in) {
{I}        $out[] = [
{I}            'id'       => (int)($in['id'] ?? 0),
{I}            'remark'   => (string)($in['remark'] ?? ''),
{I}            'protocol' => (string)($in['protocol'] ?? ''),
{I}            'port'     => (int)($in['port'] ?? 0),
{I}            'enable'   => !isset($in['enable']) || !empty($in['enable']),
{I}            'clients'  => 0,
{I}        ];
{I}    }
{I}    return $out;
{I}}

{I}/** تست سریع زنده‌بودن پنل بدون خواندن کل اینباندها */
{I}public function pingFast(): bool
{I}{
{I}    if ($this->x3) return $this->x3->ping();
{I}    return $this->inbounds() !== [];
{I}}

'''


def _facade(m):
    return FACADE_TPL.replace("{I}", m.group(1)) + m.group(0)


rep_rx("app/Panel/Xui.php",
       r"^([ \t]*)public function inbound\(int \$id\): \?array\n",
       _facade, marker="public function pingFast")


# ---- 4) Svc.php: cheap aliveness check ---------------------------------
rep_rx("app/Service/Svc.php",
       r"try \{ \$alive = \$xui->inbounds\(\) !== \[\]; \} catch \(Throwable \$e\) \{ \$alive = false; \}",
       lambda m: "try { $alive = method_exists($xui, 'pingFast') ? $xui->pingFast() : ($xui->inbounds() !== []); } catch (Throwable $e) { $alive = false; }",
       marker="pingFast")

write_all()

for _p, _needle in [
    ("app/Panel/Xui3.php", "private ?array $slimCache"),
    ("app/Panel/Xui3.php", "function inboundsSlim"),
    ("app/Panel/Xui3.php", "public function ping(): bool"),
    ("app/Panel/Xui.php", "public function pingFast"),
    ("app/Service/Svc.php", "pingFast()"),
]:
    print("sanity %s / %s : %s" % (_p, _needle, "ok" if _needle in load(_p) else "MISSING"))

CH = "بهینه‌سازی سرعت: فهرست سبک اینباندها (list/slim) و تست سریع زنده‌بودن پنل"
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
