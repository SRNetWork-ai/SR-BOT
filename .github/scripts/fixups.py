#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed87 - 0.0.2 security batch #2: encrypt secrets at rest."""
import io, json, os, sys

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed87").strip() or "fixed87"

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


# =========================================== 1) DB: باز کردن رمز هنگام خواندن
rep(
    "app/DB.php",
    """            foreach (self::all('SELECT `k`,`v` FROM {p}settings') as $row) {
                self::$settings[$row['k']] = $row['v'];
            }""",
    """            foreach (self::all('SELECT `k`,`v` FROM {p}settings') as $row) {
                $v = $row['v'];
                /* 0.0.2 #2: مقدارهای رمزنگاری‌شده هنگام خواندن باز می‌شوند */
                if (is_string($v) && strncmp($v, 'enc:v1:', 7) === 0 && class_exists('Crypt')) {
                    $v = Crypt::dec($v);
                }
                self::$settings[$row['k']] = $v;
            }""",
    marker="Crypt::dec(",
)

# ============================================ 2) DB: رمزنگاری هنگام ذخیره‌سازی
rep(
    "app/DB.php",
    """        self::q('INSERT INTO {p}settings (`k`,`v`) VALUES (:k,:v) ON DUPLICATE KEY UPDATE `v` = :v2', [
            ':k' => $key, ':v' => $value, ':v2' => $value,
        ]);
        self::$settings[$key] = $value;""",
    """        /* 0.0.2 #2: اسرار (توکن، کلید درگاه، رمز پنل و …) رمزنگاری‌شده ذخیره می‌شوند */
        $store = $value;
        if ($value !== '' && self::isSecretKey($key) && class_exists('Crypt')
            && Crypt::available() && !Crypt::isEnc($value)) {
            $store = Crypt::enc($value);
        }

        self::q('INSERT INTO {p}settings (`k`,`v`) VALUES (:k,:v) ON DUPLICATE KEY UPDATE `v` = :v2', [
            ':k' => $key, ':v' => $store, ':v2' => $store,
        ]);
        self::$settings[$key] = $value;""",
    marker="self::isSecretKey($key)",
)

# ================================================= 3) DB: توابع کمکی رمزنگاری
rep(
    "app/DB.php",
    "    /** اجرای فایل SQL (نصب/به‌روزرسانی) */",
    """    /** آیا این کلید تنظیمات «راز» است و باید رمزنگاری شود؟ */
    public static function isSecretKey(string $k): bool
    {
        return (bool)preg_match(
            '/(^|_)(token|secret|apikey|api_key|password|passwd|pass|merchant|privkey|private_key)(_|$)/i',
            $k
        );
    }

    /** رمزنگاری یک‌بارهٔ اسرارِ قدیمی که به‌صورت متن ساده ذخیره شده‌اند */
    public static function encryptExistingSecrets(): int
    {
        if (!class_exists('Crypt') || !Crypt::available() || !Crypt::hasKey()) return 0;

        $n = 0;
        foreach (self::all('SELECT `k`,`v` FROM {p}settings') as $row) {
            $k = (string)$row['k'];
            $v = (string)$row['v'];
            if ($v === '' || Crypt::isEnc($v) || !self::isSecretKey($k)) continue;
            self::setSetting($k, $v);
            $n++;
        }

        return $n;
    }

    /** اجرای فایل SQL (نصب/به‌روزرسانی) */""",
    marker="encryptExistingSecrets",
)

# ==================================== 4) صفحهٔ سلامت: وضعیت رمزنگاری + مهاجرت
rep(
    "app/Service/Health.php",
    "        /* 0.0.2 #1: آیا فایل‌های حساس از روی وب خوانده می‌شوند؟ */",
    """        /* 0.0.2 #2: رمزنگاری اسرار در دیتابیس */
        if (class_exists('Crypt')) {
            if (!Crypt::available()) {
                $out[] = self::it('رمزنگاری اسرار', 'غیرفعال', 'warn',
                    'افزونهٔ openssl روی سرور فعال نیست؛ توکن‌ها و کلیدها به‌صورت متن ساده ذخیره می‌شوند.');
            } else {
                try {
                    if ((string)DB::setting('secrets_encrypted', '') !== '1') {
                        $enc = DB::encryptExistingSecrets();
                        DB::setSetting('secrets_encrypted', '1');
                        if (function_exists('app_log')) app_log('sec', 'secrets encrypted: ' . $enc);
                    }
                    $out[] = self::it('رمزنگاری اسرار', 'فعال', 'ok',
                        'توکن ربات‌ها، کلید درگاه‌ها و رمزهای ذخیره‌شده در تنظیمات، رمزنگاری‌شده نگهداری می‌شوند.');
                } catch (Throwable $e) {
                    $out[] = self::it('رمزنگاری اسرار', 'خطا', 'warn', $e->getMessage());
                }
            }
        }

        /* 0.0.2 #1: آیا فایل‌های حساس از روی وب خوانده می‌شوند؟ */""",
    marker="0.0.2 #2: رمزنگاری اسرار",
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
    "🔐 سخت‌سازی امنیتی ۰.۰.۲ (گام ۲): توکن ربات‌ها، کلید درگاه‌های پرداخت و رمزهای ذخیره‌شده در تنظیمات "
    "با AES-256-GCM رمزنگاری می‌شوند؛ کلید رمزنگاری خودکار ساخته و در config.php (یا storage/.appkey) نگهداری می‌شود، "
    "اسرار قدیمی یک‌بار به‌صورت خودکار رمزنگاری می‌شوند و وضعیت آن در صفحهٔ سلامت سیستم نمایش داده می‌شود."
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
