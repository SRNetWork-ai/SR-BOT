<?php
declare(strict_types=1);

/**
 * Net — محافظ درخواست‌های خروجی (SSRF guard) — نسخهٔ 0.0.2
 *
 * هر درخواستی که با http_json() فرستاده می‌شود پیش از ارسال بررسی می‌شود تا به
 * آدرس‌های داخلی شبکه (127.0.0.1، 10.x، 192.168.x، 169.254.169.254 و …) نرود.
 *
 * پنل‌های VPN معمولاً روی همان سرور یا شبکهٔ داخلی نصب‌اند؛ درایورهای پنل
 * مستقیماً با curl کار می‌کنند و از این محدودیت مستثنا هستند (زمینهٔ panel).
 *
 * تنظیمات:
 *   net_guard          (پیش‌فرض 1) روشن بودن محافظ
 *   net_allow_private  (پیش‌فرض 0) اجازهٔ درخواست به شبکهٔ داخلی برای APIها
 */
final class Net
{
    public const CTX_API   = 'api';
    public const CTX_PANEL = 'panel';

    /** نام‌هایی که همیشه مسدودند */
    public const BLOCK_HOSTS = [
        'localhost',
        'localhost.localdomain',
        'metadata.google.internal',
        'metadata.goog',
        'instance-data',
    ];

    /** @var array<string,array<int,string>> */
    private static array $dns = [];

    public static function enabled(): bool
    {
        if (function_exists('cfg') && (bool)cfg('app.net_guard_off', false)) return false;
        return self::setting('net_guard', '1') !== '0';
    }

    public static function allowPrivate(string $ctx = self::CTX_API): bool
    {
        if ($ctx === self::CTX_PANEL) return true;
        if (function_exists('cfg') && (bool)cfg('app.net_allow_private', false)) return true;
        return self::setting('net_allow_private', '0') === '1';
    }

    /** آیا این IP داخل شبکهٔ محلی/رزروشده است؟ */
    public static function isPrivateIp(string $ip): bool
    {
        $ip = trim($ip, "[] \t\n\r");
        if ($ip === '') return true;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        if (strpos($ip, '100.64.') === 0) return true; /* CGNAT */

        $low = strtolower($ip);
        if ($low === '::1') return true;
        if (strncmp($low, 'fe80', 4) === 0) return true;
        if (strncmp($low, 'fc', 2) === 0 || strncmp($low, 'fd', 2) === 0) return true;

        return false;
    }

    /** @return array<int,string> */
    public static function resolve(string $host): array
    {
        $host = strtolower(trim($host, "[] \t"));
        if ($host === '') return [];
        if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
        if (isset(self::$dns[$host])) return self::$dns[$host];

        $ips = [];
        $v4  = @gethostbynamel($host);
        if (is_array($v4)) $ips = $v4;

        if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
            $rec = @dns_get_record($host, DNS_AAAA);
            if (is_array($rec)) {
                foreach ($rec as $r) {
                    if (!empty($r['ipv6'])) $ips[] = (string)$r['ipv6'];
                }
            }
        }

        self::$dns[$host] = array_values(array_unique($ips));
        return self::$dns[$host];
    }

    /**
     * بررسی یک آدرس پیش از ارسال درخواست
     *
     * @return array{ok:bool,message:string,host:string,ips:array<int,string>}
     */
    public static function check(string $url, string $ctx = self::CTX_API): array
    {
        $url = trim($url);
        if ($url === '') return self::no('آدرس خالی است.', '');
        if (!self::enabled()) return self::yes('');

        $p      = @parse_url($url);
        $scheme = strtolower((string)($p['scheme'] ?? ''));
        $host   = strtolower((string)($p['host'] ?? ''));

        if ($scheme !== 'http' && $scheme !== 'https') return self::no('فقط آدرس http/https مجاز است.', $host);
        if ($host === '') return self::no('آدرس معتبر نیست.', '');
        if (self::allowPrivate($ctx)) return self::yes($host);

        if (in_array($host, self::BLOCK_HOSTS, true)) return self::no('آدرس محلی مجاز نیست.', $host);
        foreach (['.local', '.internal', '.localdomain'] as $suf) {
            if (substr($host, -strlen($suf)) === $suf) return self::no('دامنهٔ داخلی مجاز نیست.', $host);
        }

        $ips = self::resolve($host);
        if ($ips === []) {
            /* نام میزبان حل نشد؛ برای جلوگیری از اختلال سرویس مسدود نمی‌کنیم */
            return self::yes($host);
        }
        foreach ($ips as $ip) {
            if (self::isPrivateIp($ip)) {
                return self::no('آدرس مقصد در شبکهٔ داخلی است (' . $ip . ').', $host, $ips);
            }
        }

        return self::yes($host, $ips);
    }

    /** آیتم صفحهٔ «سلامت سیستم» */
    public static function healthItem(): array
    {
        $on   = self::enabled();
        $priv = self::allowPrivate(self::CTX_API);

        return [
            'title'  => 'محافظ درخواست‌های خروجی',
            'value'  => $on ? ($priv ? 'فعال (شبکهٔ داخلی آزاد)' : 'فعال') : 'خاموش',
            'status' => ($on && !$priv) ? 'ok' : 'warn',
            'note'   => $on
                ? ($priv
                    ? 'با تنظیم net_allow_private = 0 مسیرهای داخلی شبکه هم بسته می‌شوند.'
                    : 'درخواست سرویس‌های بیرونی به آدرس‌های داخلی شبکه مسدود می‌شود.')
                : 'با تنظیم net_guard = 1 محافظ روشن می‌شود.',
        ];
    }

    private static function yes(string $host, array $ips = []): array
    {
        return ['ok' => true, 'message' => '', 'host' => $host, 'ips' => $ips];
    }

    private static function no(string $msg, string $host, array $ips = []): array
    {
        return ['ok' => false, 'message' => $msg, 'host' => $host, 'ips' => $ips];
    }

    private static function setting(string $k, string $def): string
    {
        try {
            if (class_exists('DB') && method_exists('DB', 'setting')) {
                $v = DB::setting($k, $def);
                return $v === null ? $def : (string)$v;
            }
        } catch (Throwable $e) {
        }
        return $def;
    }
}
