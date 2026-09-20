#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed89 - 0.0.2 batch #4: session hardening wiring + repo recon."""
import io, json, os, re, sys

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed89").strip() or "fixed89"
SKIP = {".git", "vendor", "node_modules", "storage", ".github", "assets", "docs"}


def php_files():
    out = []
    for base, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in SKIP]
        for f in files:
            if f.endswith(".php"):
                out.append(os.path.relpath(os.path.join(base, f), ROOT).replace("\\", "/"))
    return sorted(out)


def read(p):
    with io.open(os.path.join(ROOT, p), encoding="utf-8", errors="replace") as fh:
        return fh.read()


FILES = php_files()
TEXT = {}
for p in FILES:
    TEXT[p] = read(p)

# ------------------------------------------------ 1) session hardening wiring
RX = re.compile(r"@?session_start\s*\(\s*\)")
wired = 0
changed = {}
for p in FILES:
    if p == "app/Service/Session.php":
        continue
    s = TEXT[p]
    if "session_start" not in s:
        continue
    new, n = RX.subn("Session::start()", s)
    if n:
        changed[p] = new
        wired += n

for p, s in changed.items():
    with io.open(os.path.join(ROOT, p), "w", encoding="utf-8") as fh:
        fh.write(s)

print("session_start wired: %d call(s) in %d file(s)" % (wired, len(changed)))

# ------------------------------------------------------------- 2) repo recon
print("== SERVICES ==")
print("  " + ", ".join(sorted(os.path.basename(p) for p in FILES if p.startswith("app/Service/"))))

print("== SIZES ==")
SZ = re.compile(r"^(admin/index\.php|app/Service/(Backup|Auth|Cfg|Health|Guard|Crypt|RateLimit)\.php|miniapp/api\.php|index\.php|rbot\.php|cron/tasks\.php)$")
for p in FILES:
    if SZ.match(p):
        s = TEXT[p]
        print("  %s  %d bytes  %d lines" % (p, len(s.encode("utf-8")), s.count("\n") + 1))

PAT = [
    ("password_verify", r"password_verify"),
    ("session_meta", r"session_regenerate_id|session_set_cookie_params|session_name\s*\("),
    ("auth_flag", r"\$_SESSION\[['\"](admin|auth|uid|user|login|owner)"),
    ("zip", r"ZipArchive"),
    ("backup_fn", r"(class|function)\s+\w*[Bb]ackup"),
    ("webhook", r"setWebhook|secret_token|Secret-Token"),
    ("initdata", r"initData|init_data|check_webapp"),
    ("csrf_def", r"function\s+csrf\w*"),
]
for name, rx in PAT:
    r = re.compile(rx)
    print("== %s ==" % name)
    c = 0
    for p in FILES:
        if c >= 14:
            break
        s = TEXT[p]
        if not r.search(s):
            continue
        for i, line in enumerate(s.splitlines(), 1):
            if r.search(line):
                print("  %s:%d: %s" % (p, i, line.strip()[:110]))
                c += 1
                if c >= 14:
                    break

# ----------------------------------------------------------------- 3) version
VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)

entry = (
    "🔒 سخت‌سازی امنیتی ۰.۰.۲ (گام ۴): نشست‌های پنل مدیریت امن‌تر شدند — کوکی نشست HttpOnly و SameSite=Lax "
    "و روی HTTPS با پرچم Secure، حالت سخت‌گیرانهٔ شناسهٔ نشست، تعویض خودکار شناسه پس از ورود و انقضای نشست بی‌کار پس از ۱۲ ساعت."
)
if wired:
    log = v.get("changelog") or []
    if entry not in log:
        log.insert(0, entry)
        v["changelog"] = log
v["build"] = BUILD

with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")

print("build: " + BUILD)
print("changed files: %d" % len(changed))
for p in sorted(changed):
    print("  - " + p)
