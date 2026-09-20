#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed92 - 0.0.2 batch #7

  * Tg::setWebhook generates and stores bot.secret when it is empty (row 8)
  * Backup::zipPass auto creates a strong password so backups are never plain (row 3)
  * two new health items: backup encryption + webhook secret
  * recon: csrf form detail for the 3 broken pages, uploads, idor

Safety rules: encode before writing, php -l every touched php file, never put
\\uXXXX escape text inside python string literals.
"""
import io, json, os, re, shutil, subprocess, sys, tempfile

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed92").strip() or "fixed92"

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


def csrf_detail(path, window=16):
    print("== CSRF DETAIL (%s) ==" % path)
    try:
        lines = load(path).splitlines()
    except Exception as e:
        print("  missing: " + str(e))
        return
    for i, line in enumerate(lines):
        if re.search(r"<form", line, re.I):
            chunk = "\n".join(lines[i:i + window])
            ok = ("csrf_field" in chunk) or ('name="_t"' in chunk)
            print("  %d %s | %s" % (i + 1, "ok     " if ok else "MISSING", line.strip()[:90]))


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


# ============================================ 1) webhook secret (row 8)
OLD_WH = """    public static function setWebhook(string $url, string $secret = ''): array
    {
        $p = ['url' => $url, 'max_connections' => 40, 'drop_pending_updates' => true,
              'allowed_updates' => jenc(['message', 'callback_query', 'pre_checkout_query'])];
        if ($secret !== '') $p['secret_token'] = $secret;
        return self::api('setWebhook', $p);
    }"""

NEW_WH = """    public static function setWebhook(string $url, string $secret = ''): array
    {
        /* 0.0.2 #8: اگر توکن امنیتی خالی باشد خودکار ساخته و در config.php ذخیره می‌شود */
        if ($secret === '') $secret = self::ensureSecret();

        $p = ['url' => $url, 'max_connections' => 40, 'drop_pending_updates' => true,
              'allowed_updates' => jenc(['message', 'callback_query', 'pre_checkout_query'])];
        if ($secret !== '') $p['secret_token'] = $secret;
        return self::api('setWebhook', $p);
    }

    /**
     * 0.0.2 #8: خواندن یا ساخت توکن امنیتی وب‌هوک.
     *
     * اگر ذخیره در config.php ممکن نباشد رشتهٔ خالی برمی‌گردد؛ چون در غیر این صورت
     * تلگرام هدر امنیتی می‌فرستد ولی ربات توکن را نمی‌شناسد و همهٔ پیام‌ها رد می‌شوند.
     */
    public static function ensureSecret(): string
    {
        $s = '';
        try {
            if (class_exists('Cfg')) $s = trim((string)Cfg::get('bot.secret', ''));
            if ($s === '' && function_exists('cfg')) $s = trim((string)cfg('bot.secret', ''));
        } catch (Throwable $e) {
            $s = '';
        }
        if ($s !== '') return $s;
        if (!class_exists('Cfg')) return '';

        try {
            $new = bin2hex(random_bytes(16));
            $w   = Cfg::set(['bot.secret' => $new]);
            if (empty($w['ok'])) {
                if (function_exists('app_log')) app_log('sec', 'webhook secret not saved: ' . (string)($w['message'] ?? ''));
                return '';
            }
            if (function_exists('app_log')) app_log('sec', 'webhook secret generated');
            return $new;
        } catch (Throwable $e) {
            return '';
        }
    }"""

rep_multi("app/Tg.php", [(OLD_WH, NEW_WH)], marker="0.0.2 #8:")

# ======================================== 2) always encrypted backups (row 3)
OLD_BP = """    public static function zipPass(): string
    {
        return trim((string)DB::setting('backup_pass', ''));
    }"""

NEW_BP = """    public static function zipPass(): string
    {
        $p = trim((string)DB::setting('backup_pass', ''));

        /* 0.0.2 #3-autopass: اگر رمزی تنظیم نشده باشد یک رمز قوی ساخته می‌شود تا بکاپ‌ها بدون رمز نمانند */
        if ($p === '' && (string)DB::setting('backup_autopass', '1') === '1' && self::aesReady()) {
            try {
                $p = self::makePass();
                DB::setSetting('backup_pass', $p);
                DB::setSetting('backup_pass_auto', '1');
                if (function_exists('app_log')) app_log('backup', 'auto backup password generated');
                if (class_exists('Logs')) {
                    Logs::send('backup', Logs::fmt('🔑 رمز خودکار بکاپ ساخته شد', [
                        'رمز'    => $p,
                        'کاربرد' => 'برای باز کردن فایل‌های ZIP بکاپ لازم است؛ جایی امن نگه دارید.',
                        'تغییر'   => 'پنل مدیریت ← بکاپ ← رمز فایل',
                    ]));
                }
            } catch (Throwable $e) {
                $p = trim((string)DB::setting('backup_pass', ''));
            }
        }

        return $p;
    }

    /** 0.0.2 #3: آیا سرور از رمزگذاری AES-256 داخل ZIP پشتیبانی می‌کند؟ */
    public static function aesReady(): bool
    {
        return class_exists('ZipArchive')
            && method_exists('ZipArchive', 'setEncryptionIndex')
            && defined('ZipArchive::EM_AES_256');
    }

    /** 0.0.2 #3: ساخت رمز قوی بدون کاراکترهای گیج‌کننده */
    private static function makePass(int $len = 20): string
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $max = strlen($abc) - 1;
        $out = '';
        for ($i = 0; $i < $len; $i++) $out .= $abc[random_int(0, $max)];
        return $out;
    }"""

rep_multi("app/Service/Backup.php", [(OLD_BP, NEW_BP)], marker="0.0.2 #3-autopass")

# ================================================ 3) health items (rows 3+8)
OLD_H = """        if (class_exists('Guard')) {
            $gx   = Guard::exposure();"""

NEW_H = """        /* 0.0.2 #3: رمزدار بودن فایل‌های بکاپ */
        if (class_exists('Backup') && method_exists('Backup', 'aesReady')) {
            $bkPw = trim((string)DB::setting('backup_pass', ''));
            if (!Backup::aesReady()) {
                $out[] = self::it('رمزگذاری بکاپ', 'پشتیبانی نمی‌شود', 'warn',
                    'نسخهٔ ZipArchive این سرور از AES-256 پشتیبانی نمی‌کند؛ فایل بکاپ بدون رمز ساخته می‌شود.');
            } elseif ($bkPw === '') {
                $out[] = self::it('رمزگذاری بکاپ', 'هنوز بدون رمز', 'warn',
                    'در صفحهٔ بکاپ رمز دلخواه بگذارید؛ وگرنه نخستین بکاپ بعدی خودکار یک رمز قوی می‌سازد.');
            } else {
                $out[] = self::it('رمزگذاری بکاپ', 'فعال (AES-256)', 'ok',
                    (string)DB::setting('backup_pass_auto', '') === '1'
                        ? 'رمز به‌صورت خودکار ساخته شده و در صفحهٔ بکاپ قابل مشاهده است.'
                        : 'فایل‌های بکاپ با رمز تعیین‌شدهٔ شما رمزگذاری می‌شوند.');
            }
        }

        /* 0.0.2 #8: توکن امنیتی وب‌هوک تلگرام */
        $whSec = '';
        try {
            if (class_exists('Cfg')) $whSec = trim((string)Cfg::get('bot.secret', ''));
            if ($whSec === '' && function_exists('cfg')) $whSec = trim((string)cfg('bot.secret', ''));
        } catch (Throwable $e) {
            $whSec = '';
        }
        $out[] = $whSec === ''
            ? self::it('توکن امنیتی وب‌هوک', 'تنظیم نشده', 'warn',
                'بدون آن هر کسی می‌تواند به آدرس وب‌هوک درخواست بفرستد؛ دکمهٔ تنظیم وب‌هوک را بزنید تا خودکار ساخته شود.')
            : self::it('توکن امنیتی وب‌هوک', 'فعال', 'ok',
                'هر درخواست ورودی با هدر X-Telegram-Bot-Api-Secret-Token بررسی می‌شود.');

        /* 0.0.2 #1: بررسی دسترسی وب به فایل‌های حساس */
        if (class_exists('Guard')) {
            $gx   = Guard::exposure();"""

rep_multi("app/Service/Health.php", [(OLD_H, NEW_H)], marker="0.0.2 #8: ")

# ==================================================== 4) sanity check + write
if ERRORS:
    print("ABORTED - anchors not found:")
    for e in ERRORS:
        print("  - " + e)
    sys.exit(1)

SANITY = {
    "app/Tg.php": (5000, "function setWebhook"),
    "app/Service/Backup.php": (18000, "function zipPass"),
    "app/Service/Health.php": (15000, "function it("),
}
for path, (minlen, needle) in SANITY.items():
    if path in NEW:
        t = CACHE[path]
        if len(t) < minlen or needle not in t:
            print("ABORTED - sanity check failed for %s (%d chars)" % (path, len(t)))
            sys.exit(1)

write_all()

# ===================================================== 5) recon for batch #8
csrf_detail("admin/pages/audit.php")
csrf_detail("admin/pages/cards.php")
csrf_detail("admin/pages/payments.php")
grep_tree("UPLOADS", r"move_uploaded_file|\$_FILES", 24)

# ================================================================ 6) version
VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)

entry = (
    "🛡 سخت‌سازی امنیتی ۰.۰.۲ (گام ۷): ساخت خودکار توکن امنیتی وب‌هوک تلگرام، "
    "رمزدار شدن همیشگی فایل‌های بکاپ با AES-256 (رمز خودکار و قابل تغییر در صفحهٔ بکاپ) "
    "و افزودن دو بررسی تازه به صفحهٔ سلامت سیستم."
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
