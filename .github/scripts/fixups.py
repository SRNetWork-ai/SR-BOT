#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed117 - multi-node onlines (onlinesByGuid/activeInbounds) + installer sanity"""
import io, json, os, re, subprocess, sys, tempfile

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed117").strip() or "fixed117"

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


def rep_rx(path, pattern, fn, marker=None, expect=1, optional=False, flags=re.M):
    try:
        src = load(path)
    except Exception as e:
        (WARN if optional else ERRORS).append("%s: %s" % (path, e))
        return
    if marker and marker in src:
        print("skip (already applied): %s / %s" % (path, marker))
        return
    n = len(re.findall(pattern, src, flags))
    if n != expect:
        msg = "%s: regex for %s matched %d times (want %d)" % (path, marker or pattern[:44], n, expect)
        (WARN if optional else ERRORS).append(msg)
        return
    NEW[path] = re.sub(pattern, fn, src, count=expect, flags=flags)
    print("patched %s (%s)" % (path, marker or "rx"))


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
    lint = False
    tmpd = os.environ.get("TMPDIR") or tempfile.gettempdir()
    for path, text in NEW.items():
        data = text.encode("utf-8")
        if path.endswith(".php"):
            chk = os.path.join(tmpd, "syntax-check.php")
            with io.open(chk, "wb") as fh:
                fh.write(data)
            r = subprocess.run(["php", "-l", chk], capture_output=True, text=True)
            lint = True
            if r.returncode != 0:
                print("php lint FAILED for %s" % path)
                print((r.stdout or "") + (r.stderr or ""))
                sys.exit(1)
        with io.open(os.path.join(ROOT, path), "wb") as fh:
            fh.write(data)
        print("wrote " + path)
    print("php lint: " + ("on" if lint else "off"))
    print("changed files: %d" % len(NEW))


# ------------------------------------------------------------------
# 0) installer scripts: bash syntax + exec bit
# ------------------------------------------------------------------
for rel in ("install.sh", "tools/sr-ui"):
    p = os.path.join(ROOT, rel)
    if not os.path.exists(p):
        print("installer MISSING: " + rel)
        sys.exit(1)
    r = subprocess.run(["bash", "-n", p], capture_output=True, text=True)
    print("bash -n %s : %s" % (rel, "ok" if r.returncode == 0 else "FAILED"))
    if r.returncode != 0:
        print((r.stdout or "") + (r.stderr or ""))
        sys.exit(1)
    os.chmod(p, 0o755)
    print("chmod 0755 %s (mode %s)" % (rel, oct(os.stat(p).st_mode & 0o777)))


# ------------------------------------------------------------------
# 1) Xui3: onlinesByGuid / activeInbounds / onlinesAll
# ------------------------------------------------------------------
X3 = r'''{I}/* 0.0.2 #x3-nodes: آنلاین‌های چندنودی */

{I}/** آنلاین‌ها به تفکیک نود (پنل‌های چندنودی) */
{I}public function onlinesByGuid(): array
{I}{
{I}    $r = $this->api('/clients/onlinesByGuid', [], 'POST');
{I}    if (($r['success'] ?? false) !== true) $r = $this->api('/clients/onlinesByGuid');
{I}    if (($r['success'] ?? false) !== true) return [];
{I}    $out = [];
{I}    foreach ((array)($r['obj'] ?? []) as $k => $row) {
{I}        if (is_string($row)) {
{I}            $em = trim($row);
{I}            if ($em !== '') $out[$em] = ['email' => $em, 'nodes' => []];
{I}            continue;
{I}        }
{I}        $row = (array)$row;
{I}        $em  = trim((string)($row['email'] ?? $row['name'] ?? (is_string($k) ? $k : '')));
{I}        if ($em === '') continue;
{I}        $nodes = [];
{I}        foreach ((array)($row['nodes'] ?? $row['node'] ?? $row['inbounds'] ?? []) as $nd) {
{I}            $nd = is_array($nd) ? (string)($nd['name'] ?? $nd['remark'] ?? $nd['id'] ?? '') : (string)$nd;
{I}            $nd = trim($nd);
{I}            if ($nd !== '') $nodes[] = $nd;
{I}        }
{I}        $out[$em] = ['email' => $em, 'nodes' => array_values(array_unique($nodes))];
{I}    }
{I}    return $out;
{I}}

{I}/** اینباندهای فعال روی همهٔ نودها */
{I}public function activeInbounds(): array
{I}{
{I}    $r = $this->api('/clients/activeInbounds');
{I}    if (($r['success'] ?? false) !== true) $r = $this->api('/clients/activeInbounds', [], 'POST');
{I}    if (($r['success'] ?? false) !== true) return [];
{I}    $out = [];
{I}    foreach ((array)($r['obj'] ?? []) as $row) {
{I}        if (is_scalar($row)) {
{I}            $out[] = ['id' => (int)$row, 'remark' => (string)$row, 'node' => ''];
{I}            continue;
{I}        }
{I}        $row   = (array)$row;
{I}        $out[] = [
{I}            'id'     => (int)($row['id'] ?? 0),
{I}            'remark' => (string)($row['remark'] ?? $row['name'] ?? ''),
{I}            'node'   => (string)($row['node'] ?? $row['nodeName'] ?? $row['server'] ?? ''),
{I}        ];
{I}    }
{I}    return $out;
{I}}

{I}/** فهرست یکتای ایمیل آنلاین‌ها؛ روی پنل چندنودی همهٔ نودها را پوشش می‌دهد */
{I}public function onlinesAll(): array
{I}{
{I}    $g = $this->onlinesByGuid();
{I}    if ($g) return array_values(array_unique(array_map('strval', array_keys($g))));
{I}    return $this->onlines();
{I}}

'''


def x3_ins(m):
    return X3.replace("{I}", m.group(1)) + m.group(0)


rep_rx(
    "app/Panel/Xui3.php",
    r"^([ \t]*)/\*\* [^\n]*\*/\n([ \t]*)public function lastOnline\(\): array\n",
    x3_ins,
    marker="function onlinesByGuid",
)


# ------------------------------------------------------------------
# 2) Xui facade: online check across nodes
# ------------------------------------------------------------------
rep_rx(
    "app/Panel/Xui.php",
    r"return in_array\(\$email, \$this->x3->onlines\(\), true\);",
    lambda m: "return in_array($email, $this->x3->onlinesAll(), true);",
    marker="x3->onlinesAll(",
)


# ------------------------------------------------------------------
# 3) cron: online sync uses every node
# ------------------------------------------------------------------
rep_rx(
    "cron/tasks.php",
    r"\$ox = \(new Xui3\(\$pn\)\)->onlines\(\);",
    lambda m: "$x3d = new Xui3($pn); $ox = method_exists($x3d, 'onlinesAll') ? $x3d->onlinesAll() : $x3d->onlines();",
    marker="onlinesAll()",
)


# ------------------------------------------------------------------
# 4) Health: per-node online counter
# ------------------------------------------------------------------
HL = r'''{I}/* 0.0.2 #x3-nodes-ui: آنلاین‌ها روی چند نود */
{I}$x3g = $x3drv->onlinesByGuid();
{I}if ($x3g) {
{I}    $x3nd = [];
{I}    foreach ($x3g as $x3row) {
{I}        foreach ((array)($x3row['nodes'] ?? []) as $x3n) {
{I}            $x3n = trim((string)$x3n);
{I}            if ($x3n !== '') $x3nd[$x3n] = true;
{I}        }
{I}    }
{I}    $out[] = self::it(
{I}        'آنلاین‌های ' . $x3name,
{I}        fa_num((string)count($x3g)) . ' کاربر روی ' . fa_num((string)max(1, count($x3nd))) . ' نود',
{I}        'ok',
{I}        $x3nd ? ('نودها: ' . implode('، ', array_slice(array_keys($x3nd), 0, 6))) : ''
{I}    );
{I}}
'''


def hl_ins(m):
    return HL.replace("{I}", m.group(1)) + m.group(0)


rep_rx(
    "app/Service/Health.php",
    r"^([ \t]*)\$x3ip = \$x3drv->ipLimitStatus\(\);\n",
    hl_ins,
    marker="0.0.2 #x3-nodes-ui",
)


write_all()


# ------------------------------------------------------------------
# sanity
# ------------------------------------------------------------------
for path, needle in (
    ("app/Panel/Xui3.php", "function onlinesByGuid"),
    ("app/Panel/Xui3.php", "function activeInbounds"),
    ("app/Panel/Xui3.php", "function onlinesAll"),
    ("app/Panel/Xui.php", "x3->onlinesAll("),
    ("cron/tasks.php", "onlinesAll()"),
    ("app/Service/Health.php", "0.0.2 #x3-nodes-ui"),
    ("install.sh", "install_srui"),
    ("tools/sr-ui", "c_uninstall"),
):
    try:
        body = load(path)
    except Exception as e:
        print("sanity %s : ERROR %s" % (path, e))
        continue
    print("sanity %s / %s : %s" % (path, needle, "ok" if needle in body else "MISSING"))


# ------------------------------------------------------------------
# version.json
# ------------------------------------------------------------------
VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)
v["build"] = BUILD
entry = "آنلاین‌های چندنودی پنل نسل جدید + نصاب هوشمند install.sh و مدیر خط فرمان sr-ui"
cl = v.get("changelog")
if isinstance(cl, list) and entry not in cl:
    cl.insert(0, entry)
    v["changelog"] = cl
    print("changelog: entry added")
with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")
print("build: " + BUILD)
