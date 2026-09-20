#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed91 - 0.0.2 batch #6

  * strict mini-app initData validation (auth_date is mandatory, TTL is a setting)
  * per telegram-user rate limit for the mini app (ip based limits break on CGNAT)
  * security-topic alert on repeated mini-app auth failures
  * recon output for the next batch (backup password, bot.secret, health items)

Safety rules: encode before writing, php -l every touched php file, never put
\\uXXXX escape text inside python string literals.
"""
import io, json, os, re, shutil, subprocess, sys, tempfile

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed91").strip() or "fixed91"

CACHE = {}
NEW = {}
ERRORS = []


def load(path):
    if path not in CACHE:
        with io.open(os.path.join(ROOT, path), encoding="utf-8") as fh:
            CACHE[path] = fh.read()
    return CACHE[path]


def rep_multi(path, pairs, marker=None):
    """try several candidate anchors, use the first that matches exactly once"""
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
            print("  %d: %s" % (i, line.strip()[:118]))
            n += 1
            if n >= limit:
                break
    if n == 0:
        print("  (no match)")


def around(tag, path, needle, before=6, after=26):
    print("== %s (%s @ %s) ==" % (tag, path, needle))
    try:
        lines = load(path).splitlines()
    except Exception as e:
        print("  missing: " + str(e))
        return
    idx = -1
    for i, line in enumerate(lines):
        if needle in line:
            idx = i
            break
    if idx < 0:
        print("  (needle not found)")
        return
    for i in range(max(0, idx - before), min(len(lines), idx + after)):
        print("  %d: %s" % (i + 1, lines[i][:118]))


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


MA = "miniapp/api.php"

# ============================================ 1) auth_date is now mandatory
NEW_TTL = """    /* 0.0.2 #7: auth_date اجباری شد و پنجرهٔ اعتبار از تنظیمات خوانده می‌شود (ma_init_ttl_min دقیقه) */
    $maTtlMin = (int)DB::setting('ma_init_ttl_min', '1440');
    if ($maTtlMin < 5)     $maTtlMin = 5;
    if ($maTtlMin > 10080) $maTtlMin = 10080;
    if ($authDate <= 0 || (time() - $authDate) > ($maTtlMin * 60)) {"""

rep_multi(
    MA,
    [
        ("""    $authDate = (int)($data['auth_date'] ?? 0);
    if ($authDate > 0 && (time() - $authDate) > 86400) {""",
         "    $authDate = (int)($data['auth_date'] ?? 0);\n" + NEW_TTL),
        ("    if ($authDate > 0 && (time() - $authDate) > 86400) {", NEW_TTL),
    ],
    marker="0.0.2 #7: auth_date",
)

# ================================ 2) alert on repeated mini-app auth failures
FAIL_BLOCK = """if (!$auth['ok']) {
    /* 0.0.2 #7-log: گزارش تلاش‌های پی‌درپی برای دور زدن اعتبارسنجی تلگرام */
    try {
        if (class_exists('RateLimit') && class_exists('Logs')) {
            $maIp  = class_exists('Guard') ? Guard::clientIp() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $maBad = RateLimit::hit('ma:bad:' . ($maIp !== '' ? $maIp : 'unknown'), 20, 600);
            if ((int)($maBad['count'] ?? 0) === 21) {
                Logs::send('security', Logs::fmt('🚫 تلاش‌های ناموفق ورود به مینی‌اپ', [
                    'آی‌پی' => $maIp !== '' ? $maIp : '-',
                    'تعداد' => 'بیش از ۲۰ بار در ۱۰ دقیقه',
                    'پیام'  => mb_substr((string)$auth['message'], 0, 60),
                ]));
            }
        }
    } catch (Throwable $e) { }
    ma_fail($auth['message'], 401);
}"""

rep_multi(
    MA,
    [
        ("""$auth = ma_auth($initData);
if (!$auth['ok']) ma_fail($auth['message'], 401);""",
         "$auth = ma_auth($initData);\n" + FAIL_BLOCK),
        ("if (!$auth['ok']) ma_fail($auth['message'], 401);", FAIL_BLOCK),
    ],
    marker="0.0.2 #7-log",
)

# =========================================== 3) per telegram-user rate limit
RATE_BLOCK = """$tg   = (int)$auth['user']['id'];

/* 0.0.2 #7-rate: محدودیت نرخ درخواست بر پایهٔ شناسهٔ تلگرام (نه آی‌پی؛ اپراتورهای ایران آی‌پی مشترک می‌دهند) */
if (class_exists('RateLimit')) {
    $maMax = (int)DB::setting('ma_rate_per_min', '240');
    if ($maMax > 0) {
        $maHit = RateLimit::hit('ma:' . $tg, $maMax, 60);
        if (empty($maHit['ok'])) {
            ma_fail('درخواست‌های شما بیش از حد مجاز است؛ ' . (int)($maHit['retry'] ?? 30) . ' ثانیه دیگر دوباره تلاش کنید.', 429);
        }
    }
}"""

rep_multi(
    MA,
    [
        ("""$tg   = (int)$auth['user']['id'];
$user = DB::one('SELECT * FROM {p}users WHERE tg_id = :t', [':t' => $tg]);""",
         RATE_BLOCK + "\n$user = DB::one('SELECT * FROM {p}users WHERE tg_id = :t', [':t' => $tg]);"),
        ("$tg   = (int)$auth['user']['id'];", RATE_BLOCK),
    ],
    marker="0.0.2 #7-rate",
)

# ==================================================== 4) sanity check + write
if ERRORS:
    print("ABORTED - anchors not found:")
    for e in ERRORS:
        print("  - " + e)
    sys.exit(1)

if MA in NEW:
    txt = CACHE[MA]
    if len(txt) < 100000 or "function ma_auth" not in txt:
        print("ABORTED - miniapp/api.php sanity check failed (%d chars)" % len(txt))
        sys.exit(1)

write_all()

# ================================================= 5) recon for batch #7
grep("DB SETTERS", "app/DB.php", r"function\s+\w*[Ss]et\w*\s*\(", 25)
grep("DB SETTING FNS", "app/DB.php", r"function\s+\w*etting\w*\s*\(", 10)
around("HEALTH ITEM SAMPLE", "app/Service/Health.php", "0.0.2 #2:", 10, 26)
grep("HEALTH BACKUP", "app/Service/Health.php", r"backup", 14)
grep("BACKUP PAGE PASS", "admin/pages/backup.php", r"backup_pass|zipPass", 16)
grep("BOOTSTRAP SECRET", "app/bootstrap.php", r"secret", 14)
grep("SETTINGS SECRET", "admin/pages/settings.php", r"bot\.secret|bot_secret", 12)

# ================================================================ 6) version
VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)

entry = (
    "🛡 سخت‌سازی امنیتی ۰.۰.۲ (گام ۶): اعتبارسنجی سخت‌گیرانهٔ initData مینی‌اپ "
    "(اجباری شدن auth_date و پنجرهٔ اعتبار قابل تنظیم با ma_init_ttl_min)، "
    "محدودیت نرخ درخواست بر پایهٔ شناسهٔ تلگرام (ma_rate_per_min) و هشدار امنیتی هنگام تلاش‌های ناموفق پی‌درپی برای ورود به مینی‌اپ."
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
