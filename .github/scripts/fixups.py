#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed90 - 0.0.2 batch #5: global CSRF shield + real client IP + login alerts + recon."""
import io, json, os, re, sys

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed90").strip() or "fixed90"

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


# ==================================================== 1) CSRF coverage audit
problems = []
pages_dir = os.path.join(ROOT, "admin", "pages")
for f in sorted(os.listdir(pages_dir)):
    if not f.endswith(".php"):
        continue
    s = load("admin/pages/" + f)
    forms = s.lower().count("<form")
    toks = s.count("csrf_field") + s.count("_t")
    if forms and toks < forms:
        problems.append("%s forms=%d tokens=%d" % (f, forms, toks))

print("== CSRF FORM AUDIT ==")
for p in problems:
    print("  " + p)
print("  problem files: %d" % len(problems))
CSRF_DEFAULT = "1" if not problems else "0"
print("  sec_csrf_strict default -> " + CSRF_DEFAULT)

# ============================================ 2) real client ip for the lock
rep(
    "admin/index.php",
    """$loginError = null;
$clientIp   = (string)($_SERVER['REMOTE_ADDR'] ?? '');""",
    """$loginError = null;
/* 0.0.2 #4: پشت پراکسی/کلادفلر باید آی‌پی واقعی کاربر مبنای قفل ورود باشد */
$clientIp   = class_exists('Guard') ? Guard::clientIp() : (string)($_SERVER['REMOTE_ADDR'] ?? '');""",
    marker="Guard::clientIp()",
)

# ====================================== 3) telegram alert on successful login
rep(
    "admin/index.php",
    """    try { Security::noteSuccess($clientIp); } catch (Throwable $e) { }
};""",
    """    try { Security::noteSuccess($clientIp); } catch (Throwable $e) { }

    /* 0.0.2 #11: اطلاع ورود موفق به تاپیک امنیت */
    try {
        if (class_exists('Logs')) {
            Logs::send('security', Logs::fmt('\ud83d\udd10 ورود به پنل مدیریت', [
                'مدیر'  => '#' . $adminId,
                'آی‌پی' => $clientIp !== '' ? $clientIp : '-',
                'مرورگر' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 60),
            ]));
        }
    } catch (Throwable $e) { }
};""",
    marker="0.0.2 #11:",
)

# ================================================= 4) global CSRF post shield
SHIELD = """/* 0.0.2 #6: سپر سراسری CSRF روی همهٔ درخواست‌های POST پنل */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_ok()) {
    $csrfStrict = (string)DB::setting('sec_csrf_strict', '__DEF__') !== '0';
    $csrfAct    = (string)preg_replace('/[^a-z0-9_\\-]/i', '', (string)($_POST['act'] ?? ($_POST['action'] ?? '')));
    app_log('sec', 'csrf ' . ($csrfStrict ? 'blocked' : 'report') . ': p=' . $page . ' act=' . $csrfAct . ' ip=' . $clientIp);
    try {
        if (class_exists('Logs')) {
            Logs::send('security', Logs::fmt('\ud83d\udeab درخواست بدون توکن امنیتی', [
                'صفحه'  => $page,
                'اقدام' => $csrfAct !== '' ? $csrfAct : '-',
                'مدیر'  => (string)($ADMIN['username'] ?? '-'),
                'آی‌پی' => $clientIp !== '' ? $clientIp : '-',
                'نتیجه' => $csrfStrict ? 'مسدود شد' : 'فقط گزارش',
            ]));
        }
    } catch (Throwable $e) { }
    if ($csrfStrict) {
        http_response_code(419);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html dir="rtl" lang="fa"><meta charset="utf-8"><title>توکن امنیتی نامعتبر</title>'
            . '<div style="font:15px/2.1 Tahoma,sans-serif;max-width:520px;margin:60px auto;padding:24px;'
            . 'border:1px solid #ddd;border-radius:14px;text-align:center">'
            . '<h2 style="margin:0 0 10px">\ud83d\udeab توکن امنیتی نامعتبر است</h2>'
            . '<p>این درخواست بدون توکن معتبر فرستاده شد و برای جلوگیری از حملهٔ CSRF مسدود شد.<br>'
            . 'صفحه را تازه کنید و دوباره تلاش کنید.</p>'
            . '<p><a href="index.php?p=' . h($page) . '">بازگشت به صفحه</a></p></div>';
        exit;
    }
}

""".replace("__DEF__", CSRF_DEFAULT)

ANCHOR75 = "/* fixed75: لاگ اقدامات مدیران — هر POST پنل وب پس از اجرا (حتی با redirect) ثبت می‌شود */"
rep("admin/index.php", ANCHOR75, SHIELD + ANCHOR75, marker="0.0.2 #6:")

# ================================================================= 5) write
if ERRORS:
    print("ABORTED - anchors not found:")
    for e in ERRORS:
        print("  - " + e)
    sys.exit(1)

for path in list(NEW):
    with io.open(os.path.join(ROOT, path), "w", encoding="utf-8") as fh:
        fh.write(CACHE[path])

# ============================================= 6) recon for the next batches
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

# =============================================================== 7) version
VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)

entry = (
    "🛡 سخت‌سازی امنیتی ۰.۰.۲ (گام ۵): سپر سراسری CSRF روی همهٔ درخواست‌های POST پنل، "
    "مبنا قرار گرفتن آی‌پی واقعی کاربر در قفل ضد حدس رمز (پشت پراکسی و کلادفلر) "
    "و ارسال پیام امنیتی به تاپیک تلگرام هنگام ورود به پنل یا درخواست بدون توکن."
)
if NEW:
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
