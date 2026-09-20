#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed90 (retry) - restore admin/index.php, then apply 0.0.2 batch #5 safely.

Safety rules learned the hard way:
  * never open a target file for writing before the new content is fully encoded
  * run `php -l` on every changed php file before touching the real file
  * never put \\uXXXX escape text inside python string literals (lone surrogates)
"""
import io, json, os, re, shutil, subprocess, sys, tempfile, urllib.request

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed90").strip() or "fixed90"
GOOD_COMMIT = "1ed98f136070160e1466c4b06572409921a46fe8"
RAW = "https://raw.githubusercontent.com/SRNetWork-ai/SR-BOT/%s/%s"

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
        ERRORS.append("%s: literal anchor x%d (want %d): %r" % (path, n, expect, old[:100]))
        return
    CACHE[path] = s.replace(old, new)
    NEW[path] = True


def show(tag, path, a, b):
    print("== %s (%s:%d-%d) ==" % (tag, path, a, b))
    try:
        lines = load(path).splitlines()
    except Exception as e:
        print("  missing: " + str(e))
        return
    for i in range(a - 1, min(b, len(lines))):
        print("  %d: %s" % (i + 1, lines[i][:118]))


def write_all():
    blobs = {}
    php = shutil.which("php")
    for path in sorted(NEW):
        text = CACHE[path]
        data = text.encode("utf-8")
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


# ================================================ 1) restore admin/index.php
TARGET = "admin/index.php"
full = os.path.join(ROOT, TARGET)
size = os.path.getsize(full) if os.path.isfile(full) else 0
print("admin/index.php size before: %d bytes" % size)

if size < 5000:
    url = RAW % (GOOD_COMMIT, TARGET)
    try:
        data = urllib.request.urlopen(url, timeout=60).read().decode("utf-8")
    except Exception as e:
        print("restore download failed: " + str(e))
        sys.exit(1)
    if len(data) < 20000 or "function csrf_ok" not in data:
        print("restore sanity check failed (%d chars)" % len(data))
        sys.exit(1)
    CACHE[TARGET] = data
    NEW[TARGET] = True
    print("restored admin/index.php from %s (%d chars)" % (GOOD_COMMIT[:7], len(data)))

# ============================================ 2) real client ip for the lock
rep(
    TARGET,
    """$loginError = null;
$clientIp   = (string)($_SERVER['REMOTE_ADDR'] ?? '');""",
    """$loginError = null;
/* 0.0.2 #4: پشت پراکسی/کلادفلر باید آی‌پی واقعی کاربر مبنای قفل ورود باشد */
$clientIp   = class_exists('Guard') ? Guard::clientIp() : (string)($_SERVER['REMOTE_ADDR'] ?? '');""",
    marker="Guard::clientIp()",
)

# ===================================== 3) telegram alert on successful login
rep(
    TARGET,
    """    try { Security::noteSuccess($clientIp); } catch (Throwable $e) { }
};""",
    """    try { Security::noteSuccess($clientIp); } catch (Throwable $e) { }

    /* 0.0.2 #11: اطلاع ورود موفق به تاپیک امنیت */
    try {
        if (class_exists('Logs')) {
            Logs::send('security', Logs::fmt('🔐 ورود به پنل مدیریت', [
                'مدیر'   => '#' . $adminId,
                'آی‌پی'  => $clientIp !== '' ? $clientIp : '-',
                'مرورگر' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 60),
            ]));
        }
    } catch (Throwable $e) { }
};""",
    marker="0.0.2 #11:",
)

# ================================================ 4) global CSRF post shield
SHIELD = """/* 0.0.2 #6: سپر سراسری CSRF روی همهٔ درخواست‌های POST پنل.
   پیش‌فرض حالت گزارشی است؛ با تنظیم sec_csrf_strict = 1 درخواست بدون توکن مسدود می‌شود. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_ok()) {
    $csrfStrict = (string)DB::setting('sec_csrf_strict', '0') === '1';
    $csrfAct    = (string)preg_replace('/[^a-z0-9_\\-]/i', '', (string)($_POST['act'] ?? ($_POST['action'] ?? '')));
    app_log('sec', 'csrf ' . ($csrfStrict ? 'blocked' : 'report') . ': p=' . $page . ' act=' . $csrfAct . ' ip=' . $clientIp);
    try {
        if (class_exists('Logs')) {
            Logs::send('security', Logs::fmt('🚫 درخواست بدون توکن امنیتی', [
                'صفحه'  => $page,
                'اقدام'  => $csrfAct !== '' ? $csrfAct : '-',
                'مدیر'   => (string)($ADMIN['username'] ?? '-'),
                'آی‌پی'  => $clientIp !== '' ? $clientIp : '-',
                'نتیجه'  => $csrfStrict ? 'مسدود شد' : 'فقط گزارش',
            ]));
        }
    } catch (Throwable $e) { }
    if ($csrfStrict) {
        http_response_code(419);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html dir="rtl" lang="fa"><meta charset="utf-8"><title>توکن امنیتی نامعتبر</title>'
            . '<div style="font:15px/2.1 Tahoma,sans-serif;max-width:520px;margin:60px auto;padding:24px;'
            . 'border:1px solid #ddd;border-radius:14px;text-align:center">'
            . '<h2 style="margin:0 0 10px">🚫 توکن امنیتی نامعتبر است</h2>'
            . '<p>این درخواست بدون توکن معتبر فرستاده شد و برای جلوگیری از حملهٔ CSRF مسدود شد.<br>'
            . 'صفحه را تازه کنید و دوباره تلاش کنید.</p>'
            . '<p><a href="index.php?p=' . h($page) . '">بازگشت به صفحه</a></p></div>';
        exit;
    }
}

"""

ANCHOR75 = "/* fixed75: لاگ اقدامات مدیران — هر POST پنل وب پس از اجرا (حتی با redirect) ثبت می‌شود */"
rep(TARGET, ANCHOR75, SHIELD + ANCHOR75, marker="0.0.2 #6:")

# ============================================================ 5) sanity+write
if ERRORS:
    print("ABORTED - anchors not found:")
    for e in ERRORS:
        print("  - " + e)
    sys.exit(1)

final = CACHE.get(TARGET, "")
if len(final) < 20000 or not final.rstrip().endswith("</html>") or "function csrf_ok" not in final:
    print("ABORTED - admin/index.php sanity check failed (%d chars)" % len(final))
    sys.exit(1)

write_all()
print("admin/index.php size after: %d bytes" % os.path.getsize(full))

# ========================================= 6) recon for the coming batches
show("MINIAPP AUTH HEAD", "miniapp/api.php", 150, 168)
show("MINIAPP AUTH", "miniapp/api.php", 186, 240)
show("BACKUP ENC", "app/Service/Backup.php", 95, 130)

print("== BACKUP PASS LINES ==")
c = 0
for i, line in enumerate(load("app/Service/Backup.php").splitlines(), 1):
    if re.search(r"pass|encrypt", line, re.I):
        print("  %d: %s" % (i, line.strip()[:110]))
        c += 1
        if c >= 18:
            break

# ================================================================ 7) version
VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)

entry = (
    "🛡 سخت‌سازی امنیتی ۰.۰.۲ (گام ۵): سپر سراسری CSRF روی همهٔ درخواست‌های POST پنل "
    "(حالت گزارشی و قابل سخت‌گیرانه شدن با sec_csrf_strict)، مبنا قرار گرفتن آی‌پی واقعی کاربر در قفل ضد حدس رمز "
    "و ارسال پیام امنیتی به تاپیک تلگرام هنگام ورود به پنل یا درخواست بدون توکن."
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
