#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed86 - 0.0.2 security batch #1 (headers, sensitive paths, installer lock)."""
import io, json, os, re, sys

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed86").strip() or "fixed86"

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


def insert_after_open(path, block, marker=None):
    s = load(path)
    if marker and marker in s:
        return
    m = re.match(r"\A<\?php[^\n]*\n(?:\s*declare\(\s*strict_types\s*=\s*1\s*\);[^\n]*\n)?", s)
    if not m:
        ERRORS.append("%s: php open tag not found" % path)
        return
    CACHE[path] = s[:m.end()] + block + s[m.end():]
    NEW[path] = True


# ==================================================== 1) bootstrap: هدرهای امنیتی
rep(
    "app/bootstrap.php",
    "    foreach ($paths as $p) {\n        if (is_file($p)) { require_once $p; return; }\n    }\n});\n",
    "    foreach ($paths as $p) {\n        if (is_file($p)) { require_once $p; return; }\n    }\n});\n"
    "\n"
    "/* 0.0.2 #12: هدرهای امنیتی پایه روی همهٔ ورودی‌های وب */\n"
    "if (PHP_SAPI !== 'cli' && class_exists('Guard')) {\n"
    "    Guard::headers(strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/') !== false ? 'panel' : 'web');\n"
    "}\n",
    marker="Guard::headers(",
)

# ======================================================= 2) قفل نصاب پس از نصب
INSTALL_GUARD = (
    "\n"
    "/* 0.0.2 #13: قفل نصاب پس از نصب — نصب دوباره فقط با باز کردن دستی قفل */\n"
    "(static function (): void {\n"
    "    $root   = dirname(__DIR__);\n"
    "    $cfg    = $root . '/config.php';\n"
    "    $unlock = $root . '/storage/tmp/install.unlock';\n"
    "    if (!is_file($cfg) || is_file($unlock)) return;\n"
    "    /* در جریان یک نصب تازه، config.php همین چند دقیقه پیش ساخته شده است */\n"
    "    if (time() - (int)@filemtime($cfg) < 1800) return;\n"
    "    http_response_code(403);\n"
    "    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');\n"
    "    echo '<!doctype html><meta charset=\"utf-8\"><title>نصب قفل است</title>'\n"
    "        . '<div style=\"font:15px/2.1 Tahoma,sans-serif;direction:rtl;max-width:640px;margin:60px auto;padding:24px;border:1px solid #ddd;border-radius:14px\">'\n"
    "        . '<h2 style=\"margin:0 0 10px\">🔒 نصاب قفل شده است</h2>'\n"
    "        . '<p>این برنامه قبلاً نصب شده است. برای جلوگیری از نصب دوبارهٔ مخرب، پوشهٔ نصب بسته شد.</p>'\n"
    "        . '<p>اگر واقعاً می‌خواهید نصاب را دوباره اجرا کنید:</p>'\n"
    "        . '<ol><li>پوشهٔ <code>install/</code> را از سرور حذف کنید (امن‌ترین کار)</li>'\n"
    "        . '<li>یا فایل خالی <code>storage/tmp/install.unlock</code> را بسازید و بعد از کار حذفش کنید</li></ol>'\n"
    "        . '</div>';\n"
    "    exit;\n"
    "})();\n"
)
insert_after_open("install/index.php", INSTALL_GUARD, marker="install.unlock")

# ============================================ 3) صفحهٔ سلامت: افشای فایل حساس
rep(
    "app/Service/Health.php",
    "        /* fixed85: سلامت صف و آخرین خطای گزارش‌ها */",
    "        /* 0.0.2 #1: آیا فایل‌های حساس از روی وب خوانده می‌شوند؟ */\n"
    "        if (class_exists('Guard')) {\n"
    "            $gx   = Guard::exposure();\n"
    "            $gopn = (array)($gx['open'] ?? []);\n"
    "            if ($gopn) {\n"
    "                $out[] = self::it('فایل‌های حساس روی وب', implode('، ', array_keys($gopn)), 'warn',\n"
    "                    'این مسیرها از اینترنت قابل خواندن‌اند. قواعد فایل nginx.conf.sample را روی سرور اعمال کنید یا مطمئن شوید .htaccess فعال است.');\n"
    "            } elseif ((int)($gx['checked'] ?? 0) > 0) {\n"
    "                $out[] = self::it('محافظت فایل‌های حساس', 'برقرار', 'ok', '');\n"
    "            }\n"
    "            $gi = Guard::installer();\n"
    "            if (!empty($gi['present'])) {\n"
    "                $out[] = self::it('پوشهٔ نصب روی سرور', !empty($gi['unlocked']) ? 'قفل باز است!' : 'قفل است', 'warn',\n"
    "                    !empty($gi['unlocked'])\n"
    "                        ? 'فایل storage/tmp/install.unlock را حذف کنید تا نصاب دوباره قفل شود.'\n"
    "                        : 'برای امنیت بیشتر، پوشهٔ install را از سرور حذف کنید.');\n"
    "            }\n"
    "        }\n"
    "\n"
    "        /* fixed85: سلامت صف و آخرین خطای گزارش‌ها */",
    marker="0.0.2 #1:",
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
    "🔐 سخت‌سازی امنیتی ۰.۰.۲ (گام ۱): هدرهای امنیتی روی همهٔ صفحه‌ها و بستن فریم برای پنل مدیریت، "
    "بستن دسترسی وب به پوشه‌های app/cron/database/storage/tools/docs، نمونهٔ nginx کامل‌تر با لینک زیبای ساب، "
    "قفل خودکار نصاب پس از نصب و بررسی خودکار «فایل‌های حساس روی وب» در صفحهٔ سلامت سیستم."
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
