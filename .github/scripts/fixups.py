#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed99 - new 3x-ui API features (docs.sanaei.dev)

Adds to the Xui3 driver:
  * HWID device management  /clients/hwids/{email}  (+ DELETE /{id})
  * device limit via        /clients/bulkAdjust  (limitHwid)
  * panel client summary    /clients/list?page=1&pageSize=1
  * bulk traffic reset      /clients/bulkResetTraffic
  * orphan cleanup          /clients/delOrphans
  * ip-limit feasibility    /server/fail2banStatus
  * panel update check      /server/getPanelUpdateInfo

Also recon for the next batch (service row columns, bot service menu,
mini-app actions, and the driver's curl() method support).
"""
import io, json, os, re, shutil, subprocess, sys, tempfile

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed99").strip() or "fixed99"

CACHE = {}
NEW = {}
ERRORS = []
WARN = []
SKIP_DIRS = {".git", "storage", "node_modules", "vendor", "assets"}


def load(path):
    if path not in CACHE:
        with io.open(os.path.join(ROOT, path), encoding="utf-8") as fh:
            CACHE[path] = fh.read()
    return CACHE[path]


def rep_rx(path, pattern, fn, marker=None, expect=1, optional=False, flags=re.M):
    bag = WARN if optional else ERRORS
    try:
        s = load(path)
    except Exception as e:
        bag.append("%s: %s" % (path, e))
        return
    if marker and marker in s:
        print("skip (already applied): %s / %s" % (path, marker))
        return
    rx = re.compile(pattern, flags)
    hits = rx.findall(s)
    if len(hits) != expect:
        bag.append("%s: regex for %s matched %d times (want %d)"
                   % (path, marker or pattern[:40], len(hits), expect))
        return
    CACHE[path] = rx.sub(fn, s, count=expect)
    NEW[path] = True
    print("patched %s by regex (%s)" % (path, marker or "-"))


def grep(tag, path, pattern, limit=25):
    print("== %s (%s ~ %s) ==" % (tag, path, pattern))
    try:
        lines = load(path).splitlines()
    except Exception as e:
        print("  missing: " + str(e))
        return
    n = 0
    for i, line in enumerate(lines, 1):
        if re.search(pattern, line):
            print("  %d: %s" % (i, line.strip()[:112]))
            n += 1
            if n >= limit:
                print("  ... (limit)")
                break
    if n == 0:
        print("  (no match)")


def grep_tree(tag, pattern, limit=20, exts=(".php", ".sql")):
    print("== TREE %s (~ %s) ==" % (tag, pattern))
    rx = re.compile(pattern)
    n = 0
    for base, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS and not d.startswith(".")]
        for f in sorted(files):
            if not f.endswith(exts):
                continue
            p = os.path.join(base, f)
            rel = os.path.relpath(p, ROOT)
            try:
                with io.open(p, encoding="utf-8", errors="ignore") as fh:
                    for i, line in enumerate(fh, 1):
                        if rx.search(line):
                            print("  %s:%d: %s" % (rel, i, line.strip()[:104]))
                            n += 1
                            if n >= limit:
                                print("  ... (limit)")
                                return
            except Exception:
                pass
    if n == 0:
        print("  (no match)")


def dump(tag, path, start, end):
    try:
        lines = load(path).splitlines()
    except Exception as e:
        print("== %s == missing: %s" % (tag, e))
        return
    print("== %s (%s lines %d-%d of %d) ==" % (tag, path, start, end, len(lines)))
    for i in range(start, min(end, len(lines)) + 1):
        raw = lines[i - 1]
        if len(raw) > 300:
            raw = raw[:300] + " ...TRUNC"
        print("  %d|%s" % (i, raw))


def write_all():
    php = shutil.which("php")
    blobs = {}
    for path in sorted(NEW):
        data = CACHE[path].encode("utf-8")
        if path.endswith(".php") and php:
            tmp = os.path.join(tempfile.gettempdir(), "syntax-check.php")
            with open(tmp, "wb") as fh:
                fh.write(data)
            r = subprocess.run([php, "-l", tmp], capture_output=True, text=True)
            if r.returncode != 0:
                print("php syntax error in %s:" % path)
                print("  " + (r.stdout + r.stderr).strip()[:400])
                sys.exit(1)
        blobs[path] = data
    for path, data in blobs.items():
        with open(os.path.join(ROOT, path), "wb") as fh:
            fh.write(data)
    print("php lint: " + ("on" if php else "php not installed - skipped"))


# ==================================== 1) new driver methods (3x-ui API)
X3 = "app/Panel/Xui3.php"

NEW_METHODS = '''    /* ========== 0.0.2: newer 3x-ui API endpoints (docs.sanaei.dev) ========== */

    /** registered HWID devices of one account: GET /clients/hwids/{email} */
    public function devices(string $email): array
    {
        $email = trim($email);
        if ($email === '') return [];
        $r = $this->api('/clients/hwids/' . rawurlencode($email));
        if (($r['success'] ?? false) !== true) return [];
        $out = [];
        foreach ((array)($r['obj'] ?? []) as $d) {
            if (!is_array($d)) continue;
            $out[] = [
                'id'   => (int)($d['id'] ?? 0),
                'hwid' => (string)($d['hwid'] ?? ''),
                'name' => trim((string)($d['deviceName'] ?? ($d['name'] ?? ''))),
                'os'   => trim((string)($d['os'] ?? ($d['platform'] ?? ''))),
                'app'  => trim((string)($d['appName'] ?? ($d['app'] ?? ''))),
                'seen' => (int)($d['updatedAt'] ?? ($d['createdAt'] ?? 0)),
            ];
        }
        return $out;
    }

    /** free one HWID slot: DELETE /clients/hwids/{email}/{id} */
    public function deviceDelete(string $email, int $id): array
    {
        $email = trim($email);
        if ($email === '' || $id <= 0) return ['success' => false, 'msg' => 'bad request'];
        return $this->api('/clients/hwids/' . rawurlencode($email) . '/' . $id, null, 'DELETE');
    }

    /** drop every registered device of one account; returns how many were removed */
    public function devicesClear(string $email): int
    {
        $n = 0;
        foreach ($this->devices($email) as $d) {
            $id = (int)($d['id'] ?? 0);
            if ($id <= 0) continue;
            $r = $this->deviceDelete($email, $id);
            if (($r['success'] ?? false) === true) $n++;
        }
        return $n;
    }

    /** set the device (HWID) limit of one or more accounts */
    public function setDeviceLimit(array $emails, int $limit): array
    {
        $emails = array_values(array_filter(array_map('strval', $emails), static fn($e) => trim($e) !== ''));
        if (!$emails) return ['success' => false, 'msg' => 'no emails'];
        return $this->api('/clients/bulkAdjust', [
            'emails'    => $emails,
            'limitHwid' => max(0, $limit),
        ], 'POST');
    }

    /** panel-wide counters: total / online / active / deactive / depleted / expiring */
    public function clientsSummary(): array
    {
        $r = $this->api('/clients/list?page=1&pageSize=1');
        if (($r['success'] ?? false) !== true) return [];
        $s = (array)(((array)($r['obj'] ?? []))['summary'] ?? []);
        if (!$s) return [];
        return [
            'total'    => (int)($s['total'] ?? 0),
            'online'   => (int)($s['onlineCount'] ?? 0),
            'active'   => (int)($s['active'] ?? 0),
            'deactive' => (int)($s['deactiveCount'] ?? 0),
            'depleted' => (int)($s['depletedCount'] ?? 0),
            'expiring' => (int)($s['expiringCount'] ?? 0),
        ];
    }

    /** delete clients that are no longer attached to any inbound */
    public function delOrphans(): int
    {
        $r = $this->api('/clients/delOrphans', [], 'POST');
        if (($r['success'] ?? false) !== true) return 0;
        return (int)(((array)($r['obj'] ?? []))['deleted'] ?? 0);
    }

    /** zero the counters of many accounts in one call; returns affected count */
    public function bulkResetTraffic(array $emails): int
    {
        $emails = array_values(array_filter(array_map('strval', $emails), static fn($e) => trim($e) !== ''));
        if (!$emails) return 0;
        $r = $this->api('/clients/bulkResetTraffic', ['emails' => $emails], 'POST');
        if (($r['success'] ?? false) !== true) return 0;
        return (int)(((array)($r['obj'] ?? []))['affected'] ?? 0);
    }

    /** can per-client IP limits be enforced on this host? (needs Fail2ban) */
    public function ipLimitStatus(): array
    {
        $r = $this->api('/server/fail2banStatus');
        $o = (array)($r['obj'] ?? []);
        return [
            'ok'        => ($r['success'] ?? false) === true,
            'usable'    => !empty($o['usable']),
            'installed' => !empty($o['installed']),
            'enabled'   => !empty($o['enabled']),
        ];
    }

    /** is a newer 3x-ui release available for this panel? */
    public function panelUpdateInfo(): array
    {
        $r = $this->api('/server/getPanelUpdateInfo');
        if (($r['success'] ?? false) !== true) return [];
        $o = (array)($r['obj'] ?? []);
        return [
            'current'   => trim((string)($o['current'] ?? ($o['currentVersion'] ?? ($o['version'] ?? '')))),
            'latest'    => trim((string)($o['latest'] ?? ($o['latestVersion'] ?? ''))),
            'available' => !empty($o['hasUpdate']) || !empty($o['updateAvailable']) || !empty($o['available']),
        ];
    }

'''


def _x3(m):
    return NEW_METHODS + m.group(0)


rep_rx(
    X3,
    r"^    public function healthCheck\(\): array$",
    _x3,
    marker="0.0.2: newer 3x-ui API endpoints",
)

# ==================================================== 2) sanity + write
if ERRORS:
    print("ABORTED - anchors not found:")
    for e in ERRORS:
        print("  - " + e)
    sys.exit(1)

if X3 in NEW:
    t = CACHE[X3]
    if len(t) < 30000 or "public function healthCheck" not in t:
        print("ABORTED - sanity check failed for %s (%d chars)" % (X3, len(t)))
        sys.exit(1)

write_all()

if WARN:
    print("warnings (optional patches skipped):")
    for w in WARN:
        print("  - " + w)

# ============================================ 3) recon for the next batch
dump("X3 CURL", X3, 95, 150)
grep_tree("SERVICES TABLE", r"CREATE TABLE[^\n]*services", 6)
grep_tree("SVC MENU", r"'svcdel:|svcdel:'|svc:'|'svc:", 14)
grep("MINIAPP ACTIONS", "miniapp/api.php", r"case 'svc", 20)

# ================================================================ version
VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)

entry = ("\u0642\u0627\u0628\u0644\u06cc\u062a\u200c\u0647\u0627\u06cc \u062a\u0627\u0632\u0647\u0654 3x-ui: "
         "\u0645\u062f\u06cc\u0631\u06cc\u062a \u062f\u0633\u062a\u06af\u0627\u0647\u200c\u0647\u0627 (HWID)\u060c "
         "\u0622\u0645\u0627\u0631 \u06a9\u0627\u0631\u0628\u0631\u0627\u0646 \u067e\u0646\u0644\u060c "
         "\u0635\u0641\u0631 \u06a9\u0631\u062f\u0646 \u06af\u0631\u0648\u0647\u06cc \u062a\u0631\u0627\u0641\u06cc\u06a9\u060c "
         "\u062d\u0630\u0641 \u0627\u06a9\u0627\u0646\u062a\u200c\u0647\u0627\u06cc \u0628\u06cc\u200c\u0635\u0627\u062d\u0628 "
         "\u0648 \u0628\u0631\u0631\u0633\u06cc \u0627\u0645\u06a9\u0627\u0646 \u0645\u062d\u062f\u0648\u062f\u06cc\u062a IP.")
log = v.get("changelog") or []
if entry not in log:
    log.insert(0, entry)
    v["changelog"] = log
v["build"] = BUILD

with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")

print("build: " + BUILD)
print("changed files: %d" % len(NEW))
for p in sorted(NEW):
    print("  - " + p)
