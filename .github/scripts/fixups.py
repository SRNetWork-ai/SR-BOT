#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed108 - Happ deep link + external links (docs.sanaei.dev)

Patch : app/Panel/Xui3.php -> happLink() + externalLinks()
New   : app/Service/Links.php (pushed alongside this script)
Recon : bot devicesView / #dev-btn block / mini-app svc_devices (for the UI round)
"""
import io, json, os, re, subprocess, sys

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed108").strip() or "fixed108"

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
        s = load(path)
    except Exception as e:
        (WARN if optional else ERRORS).append("%s: missing (%s)" % (path, e))
        return
    if marker and marker in s:
        print("skip (already applied): %s / %s" % (path, marker))
        return
    n = len(re.findall(pattern, s, flags))
    if n != expect:
        (WARN if optional else ERRORS).append(
            "%s: regex for %s matched %d times (want %d)" % (path, marker or pattern[:44], n, expect)
        )
        return
    NEW[path] = re.sub(pattern, fn, s, count=0, flags=flags)
    print("patched %s by regex (%s)" % (path, marker or pattern[:44]))


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


def dump_find(tag, path, pattern, before=0, after=40):
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
    tmp = os.environ.get("TMPDIR", "/tmp")
    linted = False
    for path, content in NEW.items():
        data = content.encode("utf-8")
        if path.endswith(".php"):
            probe = os.path.join(tmp, "syntax-check.php")
            with open(probe, "wb") as fh:
                fh.write(data)
            r = subprocess.run(["php", "-l", probe], capture_output=True, text=True)
            linted = True
            if r.returncode != 0:
                print("php lint FAILED for %s:" % path)
                print(r.stdout + r.stderr)
                sys.exit(1)
        with open(os.path.join(ROOT, path), "wb") as fh:
            fh.write(data)
        print("wrote " + path)
    print("php lint: " + ("on" if linted else "off"))
    print("changed files: %d" % len(NEW))


X3_METHODS = '''    /* ========== 0.0.2 #happ-links : Happ deep link + external links ========== */

    /** دیپ‌لینک اختصاصی Happ برای یک اکانت: GET /clients/happLink/{id} */
    public function happLink(string $email): string
    {
        $email = trim($email);
        if ($email === '') return '';
        $row = $this->clientRow($email);
        $id  = (int)($row['id'] ?? 0);
        if ($id <= 0) return '';
        $r = $this->api('/clients/happLink/' . $id);
        if (($r['success'] ?? false) !== true) return '';
        $o = $r['obj'] ?? '';
        if (is_string($o)) return trim($o);
        if (is_array($o)) {
            foreach (['happLink', 'link', 'url', 'happ'] as $k) {
                if (isset($o[$k]) && is_string($o[$k]) && trim($o[$k]) !== '') return trim($o[$k]);
            }
        }
        return '';
    }

    /** لینک‌های خارجیِ ثبت‌شده روی پنل برای اکانت: GET /clients/{email}/externalLinks */
    public function externalLinks(string $email): array
    {
        $email = trim($email);
        if ($email === '') return [];
        $r = $this->api('/clients/' . rawurlencode($email) . '/externalLinks');
        if (($r['success'] ?? false) !== true) return [];
        $out = [];
        foreach ((array)($r['obj'] ?? []) as $row) {
            if (is_string($row)) {
                $l = trim($row);
                if ($l !== '') $out[] = ['title' => '', 'link' => $l];
                continue;
            }
            if (!is_array($row)) continue;
            $l = '';
            foreach (['link', 'url', 'href'] as $k) {
                if (isset($row[$k]) && is_string($row[$k]) && trim($row[$k]) !== '') {
                    $l = trim($row[$k]);
                    break;
                }
            }
            if ($l === '') continue;
            $t = '';
            foreach (['remark', 'title', 'name'] as $k) {
                if (isset($row[$k]) && is_string($row[$k]) && trim($row[$k]) !== '') {
                    $t = trim($row[$k]);
                    break;
                }
            }
            $out[] = ['title' => $t, 'link' => $l];
        }
        return $out;
    }

'''

ANCHOR = r"^    /\*\* registered HWID devices of one account: GET /clients/hwids/\{email\} \*/$"

rep_rx(
    "app/Panel/Xui3.php",
    ANCHOR,
    lambda m: X3_METHODS + m.group(0),
    marker="0.0.2 #happ-links",
)

# sanity
if "app/Panel/Xui3.php" in NEW:
    s = NEW["app/Panel/Xui3.php"]
    for needle in ("public function happLink(", "public function externalLinks(", "public function devices(", "public function addClient(", "public function healthCheck("):
        if needle not in s:
            ERRORS.append("Xui3.php lost: " + needle)
    if len(s) < 25000:
        ERRORS.append("Xui3.php too short: %d" % len(s))

write_all()

# ---------- recon for the UI round ----------
dump_find("BOT dev-btn block", "app/Bot/Bot.php", r"0\.0\.2 #dev-btn", 10, 12)
dump_find("BOT devicesView", "app/Bot/Bot.php", r"function devicesView", 2, 56)
dump_find("MINIAPP svc_devices", "miniapp/api.php", r"'svc_devices'", 2, 34)

VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)
v["build"] = BUILD
with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")
print("build: " + BUILD)
