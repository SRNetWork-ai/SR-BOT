<?php
declare(strict_types=1);

/**
 * خواندن و نوشتن ایمن فایل config.php
 * (توکن ربات، آدرس سایت، مدیران)
 */
class Cfg
{
    public static function path(): string
    {
        return APP_ROOT . '/config.php';
    }

    /** خواندن تازهٔ فایل (بدون کش cfg) */
    public static function all(): array
    {
        $f = self::path();
        if (!is_file($f)) return [];
        if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
        try {
            $c = @include $f;
        } catch (Throwable $e) {
            return [];
        }
        return is_array($c) ? $c : [];
    }

    public static function get(string $key, $default = null)
    {
        $ref = self::all();
        foreach (explode('.', $key) as $p) {
            if (!is_array($ref) || !array_key_exists($p, $ref)) return $default;
            $ref = $ref[$p];
        }
        return $ref;
    }

    public static function writable(): bool
    {
        $f = self::path();
        return is_file($f) ? is_writable($f) : is_writable(dirname($f));
    }

    /** نمایش امن توکن */
    public static function mask(string $v, int $head = 10, int $tail = 4): string
    {
        if ($v === '') return '';
        $len = mb_strlen($v);
        if ($len <= $head + $tail) return str_repeat('•', $len);
        return mb_substr($v, 0, $head) . str_repeat('•', 8) . mb_substr($v, -$tail);
    }

    /** تغییر چند کلید با مسیر نقطه‌ای مانند bot.token */
    public static function set(array $pairs): array
    {
        $cfg = self::all();
        if ($cfg === []) {
            return ['ok' => false, 'message' => '⛔ فایل config.php خوانده نشد. وجود و دسترسی آن را بررسی کنید.'];
        }
        foreach ($pairs as $key => $val) {
            $parts = explode('.', (string)$key);
            $last  = count($parts) - 1;
            $ref   = &$cfg;
            foreach ($parts as $i => $p) {
                if ($i === $last) { $ref[$p] = $val; break; }
                if (!isset($ref[$p]) || !is_array($ref[$p])) $ref[$p] = [];
                $ref = &$ref[$p];
            }
            unset($ref);
        }
        return self::write($cfg);
    }

    public static function write(array $cfg): array
    {
        $f = self::path();
        if (!self::writable()) {
            return ['ok' => false, 'message' => '⛔ فایل config.php قابل نوشتن نیست. دسترسی فایل را روی 644 و پوشه را روی 755 بگذارید.'];
        }

        /* پشتیبان از نسخهٔ قبلی */
        $dir = APP_ROOT . '/storage/backups';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (is_file($f)) @copy($f, $dir . '/config-' . date('Ymd-His') . '.php.bak');

        $code = "<?php\n/* این فایل توسط پنل مدیریت به‌روز شد: " . date('Y-m-d H:i:s') . " */\nreturn "
            . var_export($cfg, true) . ";\n";

        $tmp = $f . '.tmp';
        if (@file_put_contents($tmp, $code) === false) {
            return ['ok' => false, 'message' => '⛔ نوشتن فایل موقت ناموفق بود.'];
        }
        if (!@rename($tmp, $f)) {
            @unlink($tmp);
            if (@file_put_contents($f, $code) === false) {
                return ['ok' => false, 'message' => '⛔ ذخیرهٔ config.php ناموفق بود.'];
            }
        }
        @chmod($f, 0644);
        if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);

        return ['ok' => true, 'message' => '✅ تنظیمات هسته ذخیره شد.'];
    }

    /** ذخیرهٔ توکن ربات همراه بررسی از تلگرام */
    public static function saveToken(string $token, bool $force = false): array
    {
        $token = (string)preg_replace('/\s+/u', '', $token);
        $token = trim(str_replace(['bot', 'Bot'], '', $token), " \t\n\r/");

        if ($token === '' || strpos($token, ':') === false) {
            return ['ok' => false, 'verified' => false,
                'message' => '⛔ ساختار توکن درست نیست. نمونهٔ درست: 123456789:AAE...'];
        }

        Tg::setToken($token);
        $me   = Tg::getMe();
        $okMe = !empty($me['ok']);
        $desc = (string)($me['description'] ?? '');

        if (!$okMe && !$force) {
            return ['ok' => false, 'verified' => false, 'telegram' => $desc,
                'message' => '⛔ تلگرام این توکن را قبول نکرد: ' . ($desc !== '' ? $desc : 'پاسخی دریافت نشد')];
        }

        $set = ['bot.token' => $token];
        $un  = (string)($me['result']['username'] ?? '');
        if ($okMe && $un !== '') $set['bot.username'] = $un;
        if ((string)self::get('bot.secret', '') === '') $set['bot.secret'] = bin2hex(random_bytes(16));

        $w = self::set($set);
        if (empty($w['ok'])) {
            return ['ok' => false, 'verified' => $okMe, 'message' => (string)$w['message']];
        }

        return ['ok' => true, 'verified' => $okMe, 'username' => $un, 'telegram' => $desc,
            'message' => $okMe
                ? '✅ توکن تایید شد و ربات @' . $un . ' متصل است.'
                : '⚠️ توکن ذخیره شد ولی تلگرام آن را تایید نکرد.'];
    }
}
