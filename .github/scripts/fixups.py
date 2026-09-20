#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed94 - 0.0.2 batch #9

  * http_json() now passes every outgoing url through the new Net guard (SSRF)
  * redirects restricted to http/https, max redirects 5 -> 3
  * health page shows the guard state
  * recon: sub.php / verify.php / miniapp/card.php ownership checks, panel factory

Safety rules: encode before writing, php -l every touched php file, never put
surrogate escape text inside python string literals.
"""
import io, json, os, re, shutil, subprocess, sys, tempfile

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed94").strip() or "fixed94"

CACHE = {}
NEW = {}
ERRORS = []


def load(path):
    if path not in CACHE:
        with io.open(os.path.join(ROOT, path), encoding="utf-8") as fh:
            CACHE[path] = fh.read()
    return CACHE[path]


def rep_multi(path, pairs, marker=None):
    try:
        s = load(path)
    except Exception as e:
        ERRORS.append("%s: %s" % (path, e))
        return
    if marker and marker in s:
        print("skip (already applied): %s / %s" % (path, marker))
        return
    for i, (old, new) in enumerate(pairs):
        if s.count(old) == 1:
            CACHE[path] = s.replace(old, new)
            NEW[path] = True
            print("patched %s with anchor #%d (%s)" % (path, i + 1, marker or "-"))
            return
    ERRORS.append("%s: no unique anchor for %s (counts: %s)"
                  % (path, marker or "-", [s.count(o) for o, _ in pairs]))


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
            print("  %d: %s" % (i, line.strip()[:110]))
            n += 1
            if n >= limit:
                break
    if n == 0:
        print("  (no match)")


def grep_tree(tag, pattern, limit=30):
    print("== %s (tree ~ %s) ==" % (tag, pattern))
    rx = re.compile(pattern)
    n = 0
    for base, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in (".git", "storage", "node_modules", "vendor", "assets")]
        for f in sorted(files):
            if not f.endswith(".php"):
                continue
            rel = os.path.relpath(os.path.join(base, f), ROOT)
            try:
                with io.open(os.path.join(base, f), encoding="utf-8", errors="replace") as fh:
                    for i, line in enumerate(fh, 1):
                        if rx.search(line):
                            print("  %s:%d: %s" % (rel, i, line.strip()[:96]))
                            n += 1
                            if n >= limit:
                                print("  ... (limit reached)")
                                return
            except Exception:
                continue
    if n == 0:
        print("  (no match)")


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


# ============================================ 1) ssrf guard inside http_json()
HJ_OLD = (
    "function http_json(string $url, array $data = [], string $method = 'GET', array $headers = [], int $timeout = 20): array {\n"
    "    $ch = curl_init();"
)
HJ_NEW = (
    "function http_json(string $url, array $data = [], string $method = 'GET', array $headers = [], int $timeout = 20, string $netCtx = 'api'): array {\n"
    "    /* 0.0.2 #14: SSRF guard - refuse outgoing requests to internal targets */\n"
    "    if (class_exists('Net')) {\n"
    "        $netChk = Net::check($url, $netCtx);\n"
    "        if (empty($netChk['ok'])) {\n"
    "            if (function_exists('app_log')) {\n"
    "                app_log('net', 'blocked outgoing request', ['url' => $url, 'reason' => (string)($netChk['message'] ?? '')]);\n"
    "            }\n"
    "            return ['code' => 0, 'body' => '', 'json' => [], 'error' => 'ssrf-guard: ' . (string)($netChk['message'] ?? '')];\n"
    "        }\n"
    "    }\n"
    "    $ch = curl_init();"
)
rep_multi("app/Helpers.php", [(HJ_OLD, HJ_NEW)], marker="0.0.2 #14: SSRF guard")

RD_OLD = (
    "        CURLOPT_FOLLOWLOCATION => true,\n"
    "        CURLOPT_MAXREDIRS => 5,"
)
RD_NEW = (
    "        CURLOPT_FOLLOWLOCATION => true,\n"
    "        CURLOPT_MAXREDIRS => 3,\n"
    "        /* 0.0.2 #14: only http/https, even after a redirect */\n"
    "        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,\n"
    "        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,"
)
rep_multi("app/Helpers.php", [(RD_OLD, RD_NEW)], marker="CURLOPT_REDIR_PROTOCOLS")

# ================================================ 2) health item for the guard
H_ANCHOR = (
    "        if (class_exists('Guard')) {\n"
    "            $gx   = Guard::exposure();"
)
H_NEW = (
    "        /* 0.0.2 #14: SSRF guard item */\n"
    "        if (class_exists('Net')) {\n"
    "            $nh = Net::healthItem();\n"
    "            $out[] = self::it((string)$nh['title'], (string)$nh['value'], (string)$nh['status'], (string)$nh['note']);\n"
    "        }\n\n"
    + H_ANCHOR
)
rep_multi("app/Service/Health.php", [(H_ANCHOR, H_NEW)], marker="0.0.2 #14: SSRF guard item")

# ==================================================== 3) sanity check + write
if ERRORS:
    print("ABORTED - anchors not found:")
    for e in ERRORS:
        print("  - " + e)
    sys.exit(1)

SANITY = {
    "app/Helpers.php": (8000, "function http_json"),
    "app/Service/Health.php": (15000, "function it("),
}
for path, (minlen, needle) in SANITY.items():
    if path in NEW:
        t = CACHE[path]
        if len(t) < minlen or needle not in t:
            print("ABORTED - sanity check failed for %s (%d chars)" % (path, len(t)))
            sys.exit(1)

write_all()

# ==================================================== 4) recon for batch #10
grep("SUB ENTRY", "sub.php", r"\$_GET\[|WHERE\s+token|WHERE\s+id\s*=", 26)
grep("VERIFY ENTRY", "verify.php", r"\$_GET\[|\$_POST\[|WHERE\s+id\s*=", 20)
grep("CARD MINI", "miniapp/card.php", r"WHERE\s+id\s*=|user_id|ma_auth|initData", 20)
grep_tree("PANEL FACTORY", r"new (Xui3|Xui|Marzban|PasarGuard)\(|panel_type|'kind'\s*=>|case 'xui", 24)

# ================================================================ 5) version
VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)

entry = (
    "\U0001f6e1 \u0633\u062e\u062a\u200c\u0633\u0627\u0632\u06cc \u0627\u0645\u0646\u06cc\u062a\u06cc \u06f0.\u06f0.\u06f2 (\u06af\u0627\u0645 \u06f9): "
    "\u0645\u062d\u0627\u0641\u0638 SSRF \u0628\u0631\u0627\u06cc \u0647\u0645\u0647\u0654 \u062f\u0631\u062e\u0648\u0627\u0633\u062a\u200c\u0647\u0627\u06cc \u062e\u0631\u0648\u062c\u06cc\u060c "
    "\u0645\u062d\u062f\u0648\u062f \u06a9\u0631\u062f\u0646 \u0631\u06cc\u062f\u0627\u06cc\u0631\u06a9\u062a \u0628\u0647 http/https "
    "\u0648 \u0646\u0645\u0627\u06cc\u0634 \u0648\u0636\u0639\u06cc\u062a \u0622\u0646 \u062f\u0631 \u0635\u0641\u062d\u0647\u0654 \u0633\u0644\u0627\u0645\u062a."
)
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
