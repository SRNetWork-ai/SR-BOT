#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed88 - 0.0.2 security batch #3: rate limiting foundation."""
import io, json, os, sys

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed88").strip() or "fixed88"

CACHE = {}
NEW = {}
ERRORS = []


def load(path):
    if path not in CACHE:
        with io.open(os.path.join(ROOT, path), encoding="utf-8") as fh:
            CACHE[path] = fh.read()
    return CACHE[path]


def rep(path, old, new, expect=1, marker=None):
    s = load(path)
    if marker and marker in s:
        return
    n = s.count(old)
    if n != expect:
        ERRORS.append("%s: literal anchor x%d (want %d): %r" % (path, n, expect, old[:120]))
        return
    CACHE[path] = s.replace(old, new)
    NEW[path] = True


# ============================================ bootstrap: نگهبان محدودیت نرخ
rep(
    "app/bootstrap.php",
    """if (PHP_SAPI !== 'cli' && class_exists('Guard')) {
    Guard::headers(strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/') !== false ? 'panel' : 'web');
}""",
    """if (PHP_SAPI !== 'cli' && class_exists('Guard')) {
    Guard::headers(strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/') !== false ? 'panel' : 'web');
}

/* 0.0.2 #4: محدودیت نرخ درخواست پنل مدیریت (وب‌هوک ربات محدود نمی‌شود) */
if (PHP_SAPI !== 'cli' && class_exists('RateLimit')) {
    RateLimit::guardWeb();
}""",
    marker="RateLimit::guardWeb()",
)

# ==================================================================== version
if ERRORS:
    print("ABORTED - anchors not found:")
    for e in ERRORS:
        print("  - " + e)
    sys.exit(1)

for path in list(CACHE):
    if NEW.get(path):
        with io.open(os.path.join(ROOT, path), "w", encoding="utf-8") as fh:
            fh.write(CACHE[path])

VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)

entry = (
    "🛡 سخت‌سازی امنیتی ۰.۰.۲ (گام ۳): زیرساخت محدودیت نرخ درخواست اضافه شد؛ "
    "پنل مدیریت در هر دقیقه حداکثر ۳۰۰ درخواست از هر آی‌پی می‌پذیرد و وب‌هوک ربات هرگز محدود نمی‌شود؛ "
    "قفل ضد حدس رمز برای ورود به پنل آماده شد (۵ تلاش ناموفق ← ۱۵ دقیقه قفل)."
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
print("changed files: " + str(len(NEW)))
for p in sorted(NEW):
    print("  - " + p)
