<?php
declare(strict_types=1);

/**
 * کلاینت API پنل هیدیفای (Hiddify Manager – API v2)
 *
 * هیدیفای لاگین یوزر/پسورد ندارد و با «کلید API» ادمین کار می‌کند:
 *   هدر:                Hiddify-API-Key: <admin uuid / api key>
 *   مسیر ادمین:         https://domain[:port]/<proxy_path>/api/v2/admin/user/
 *   لینک اشتراک کاربر:  https://domain[:port]/<proxy_path>/<uuid>/sub/
 *
 * تنظیم پنل در ربات:
 *   • «مسیر وب» = مسیر پروکسی (proxy path) هیدیفای
 *   • «توکن API» یا رمز = کلید API ادمین
 * کاربران با UUID شناخته می‌شوند؛ نام کاربر روی پنل همان نام سرویس ربات است تا
 * جست‌وجو و بازیابی ممکن باشد.
 *
 * برای جایگزین‌پذیری در سراسر ربات از Marzban ارث می‌برد و متدهای API را بازنویسی می‌کند.
 *
 * 0.0.2 #row16-hiddify
 */
class Hiddify extends Marzban
{
    /** آخرین کانفیگ‌های خوانده‌شده از لینک اشتراک */
    private array $cfgLinks = [];
    /** آخرین لینک اشتراک ساخته‌شده */
    private string $subUrlHd = '';
    /** نگاشت نام سرویس به uuid */
    private array $mapHd = [];
    /** کش فهرست کاربران پنل */
    private ?array $usersCache = null;

    public static function forPanel($panelId): ?self
    {
        $p = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)$panelId]);
        return $p ? new self($p) : null;
    }

    /* ------------------------------------------------------------------ */
    /*  کلید API                                                          */
    /* ------------------------------------------------------------------ */

    /** کلید API از ستون‌های محتمل پنل (توکن، کلید یا رمز) */
    private static function keyOf(array $panel): string
    {
        foreach (['api_token', 'token', 'api_key', 'apikey', 'password'] as $col) {
            $raw = trim((string)($panel[$col] ?? ''));
            if ($raw === '') continue;
            $val = function_exists('app_decrypt') ? (string)app_decrypt($raw) : $raw;
            $val = trim($val);
            if ($val !== '') return $val;
        }
        return '';
    }

    /** آیا کلید API برای این پنل ثبت شده است؟ */
    public static function hasApiKey(array $panel): bool
    {
        return self::keyOf($panel) !== '';
    }

    private function key(): string
    {
        return self::keyOf($this->panel);
    }

    /* ------------------------------------------------------------------ */
    /*  لایهٔ شبکه                                                        */
    /* ------------------------------------------------------------------ */

    private function hdCurl(string $url, $body = null, string $method = 'GET'): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json', 'Hiddify-API-Key: ' . $this->key()];
        $verify = (int)($this->panel['ssl_verify'] ?? 0) === 1;
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : jenc($body);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw  = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return ['code' => $code, 'body' => $raw, 'json' => jdec($raw, []), 'error' => $err];
    }

    private static function hdErr(array $res): string
    {
        if (($res['error'] ?? '') !== '') return (string)$res['error'];
        $j = (array)($res['json'] ?? []);
        $msg = $j['msg'] ?? ($j['message'] ?? ($j['detail'] ?? ''));
        if (is_array($msg)) $msg = jenc($msg);
        $msg = trim((string)$msg);
        return $msg !== '' ? mb_substr($msg, 0, 200) : ('HTTP ' . (int)($res['code'] ?? 0));
    }

    /** هیدیفای ورود ندارد؛ فقط وجود کلید API بررسی می‌شود */
    public function login(bool $force = false): bool
    {
        if ($this->key() !== '') return true;
        try {
            DB::update('panels', ['last_error' => 'کلید API هیدیفای ثبت نشده است (پنل ← Hiddify-API-Key)'], 'id = :id', [':id' => (int)$this->panel['id']]);
        } catch (Throwable $e) { }
        app_log('panel', 'hiddify api key missing', ['panel' => (string)($this->panel['name'] ?? '')]);
        return false;
    }

    private function hdApi(string $path, $body = null, string $method = 'GET'): array
    {
        if (!$this->login()) {
            return ['ok' => false, 'code' => 0, 'json' => [], 'msg' => 'کلید API هیدیفای ثبت نشده است'];
        }
        $res  = $this->hdCurl($this->base() . $path, $body, $method);
        $code = (int)$res['code'];
        $ok   = $code >= 200 && $code < 300;
        if ($ok) {
            /* هیدیفای گاهی خطا را با کد 200 و status داخل بدنه برمی‌گرداند */
            $st = (int)(($res['json']['status'] ?? 200));
            if ($st >= 400) $ok = false;
        }
        return [
            'ok'   => $ok,
            'code' => $code,
            'json' => is_array($res['json'] ?? null) ? (array)$res['json'] : [],
            'msg'  => $ok ? '' : self::hdErr($res),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  کاربران                                                           */
    /* ------------------------------------------------------------------ */

    /** فهرست کاربران پنل (با کش) */
    private function hdUsers(bool $force = false): array
    {
        if (!$force && $this->usersCache !== null) return $this->usersCache;
        $r   = $this->hdApi('/api/v2/admin/user/');
        $src = $r['json']['items'] ?? ($r['json']['results'] ?? $r['json']);
        $out = [];
        foreach ((array)$src as $u) {
            if (!is_array($u)) continue;
            $uuid = trim((string)($u['uuid'] ?? ''));
            if ($uuid === '') continue;
            $out[] = $u;
            $nm = trim((string)($u['name'] ?? ''));
            if ($nm !== '') $this->mapHd[$nm] = $uuid;
        }
        $this->usersCache = $out;
        return $out;
    }

    /** یافتن uuid از نام سرویس */
    private function hdUuidFor(string $email): string
    {
        $name = self::safeName($email);
        if ($name === '') return '';
        if (($this->mapHd[$name] ?? '') !== '') return (string)$this->mapHd[$name];
        foreach ($this->hdUsers() as $u) {
            $nm = trim((string)($u['name'] ?? ''));
            $cm = trim((string)($u['comment'] ?? ''));
            if ($nm === $name || $nm === $email || ($email !== '' && $cm !== '' && mb_strpos($cm, $email) !== false)) {
                $uuid = trim((string)($u['uuid'] ?? ''));
                if ($uuid !== '') {
                    $this->mapHd[$name] = $uuid;
                    return $uuid;
                }
            }
        }
        return '';
    }

    /** خواندن یک کاربر با uuid */
    private function hdUser(string $uuid): ?array
    {
        $uuid = trim($uuid);
        if ($uuid === '') return null;
        $r = $this->hdApi('/api/v2/admin/user/' . rawurlencode($uuid) . '/');
        if (!$r['ok']) return null;
        $u = (array)$r['json'];
        if (isset($u['user']) && is_array($u['user'])) $u = $u['user'];
        return trim((string)($u['uuid'] ?? '')) !== '' ? $u : null;
    }

    /* ------------------------------------------------------------------ */
    /*  کمکی‌ها                                                            */
    /* ------------------------------------------------------------------ */

    /** تعداد روز باقی‌مانده تا انقضا (۰ یا منفی = بسته‌های بدون انقضا) */
    private static function daysOf(int $expiryMs): int
    {
        if ($expiryMs <= 0) return 3650;
        $d = (int)ceil((($expiryMs / 1000) - time()) / 86400);
        return $d > 0 ? $d : 1;
    }

    private static function gbToBytes(float $gb): int
    {
        return $gb > 0 ? (int)round($gb * 1024 * 1024 * 1024) : 0;
    }

    private static function bytesToGb(int $b): float
    {
        return $b > 0 ? round($b / (1024 * 1024 * 1024), 3) : 0.0;
    }

    private static function tsOf($v): int
    {
        if ($v === null || $v === '') return 0;
        if (is_numeric($v)) return (int)$v;
        $t = strtotime((string)$v);
        if (!$t || $t < 946684800) return 0; /* تاریخ‌های پیش‌فرض هیدیفای مثل 1-1-1 */
        return (int)$t;
    }

    /** زمان انقضا (ثانیهٔ یونیکس) از start_date + package_days */
    private static function expiryTs(array $u): int
    {
        foreach (['expiry_time', 'expire_date', 'expire'] as $k) {
            $t = self::tsOf($u[$k] ?? null);
            if ($t > 0) return $t;
        }
        $days = (int)($u['package_days'] ?? 0);
        if ($days <= 0) return 0;
        $start = self::tsOf($u['start_date'] ?? null);
        if ($start <= 0) return 0; /* هنوز شروع نشده: انقضا نامشخص */
        return $start + $days * 86400;
    }

    /** لینک اشتراک هیدیفای برای یک uuid */
    private function hdSubUrl(string $uuid): string
    {
        $uuid = trim($uuid);
        if ($uuid === '') return '';
        $base = trim((string)($this->panel['sub_base'] ?? ''));
        $root = $base !== '' ? rtrim($base, '/') : $this->base();
        return $root . '/' . rawurlencode($uuid) . '/sub/';
    }

    /** تبدیل کاربر هیدیفای به ساختار کلاینت شناخته‌شدهٔ ربات */
    private function hdClientOf(array $u): array
    {
        $uuid = trim((string)($u['uuid'] ?? ''));
        $exp  = self::expiryTs($u);
        $on   = array_key_exists('enable', $u) ? (bool)$u['enable'] : true;
        if ($on && array_key_exists('is_active', $u)) $on = (bool)$u['is_active'] || (bool)$u['enable'];
        return [
            'email'      => (string)($u['name'] ?? ''),
            'id'         => $uuid,
            'password'   => $uuid,
            'totalGB'    => self::gbToBytes((float)($u['usage_limit_GB'] ?? 0)),
            'expiryTime' => $exp > 0 ? $exp * 1000 : 0,
            'enable'     => $on,
            'limitIp'    => 0,
            'subId'      => $this->hdSubUrl($uuid),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  اینباندها (هیدیفای اینباند ندارد؛ یک ردیف نمایشی)                  */
    /* ------------------------------------------------------------------ */

    public function inbounds(): array
    {
        return [[
            'id'       => 1,
            'protocol' => 'hiddify',
            'remark'   => (string)($this->panel['name'] ?? 'Hiddify'),
            'tag'      => 'hiddify',
            'port'     => 0,
            'enable'   => true,
        ]];
    }

    public function inboundTags(): array
    {
        return ['hiddify' => [(string)($this->panel['name'] ?? 'Hiddify')]];
    }

    public function inbound(int $id): ?array
    {
        $list = $this->inbounds();
        return $list[0];
    }

    /* ------------------------------------------------------------------ */
    /*  ساخت و ویرایش کاربر                                               */
    /* ------------------------------------------------------------------ */

    public function addClient(int $inboundId, string $email, string $uuid, float $volumeGb, int $expiryMs, int $ipLimit = 0, string $subId = '', array $inboundIds = [], int $deviceLimit = 0, int $upKbps = 0, int $downKbps = 0): array
    {
        $name = self::safeName($email);
        $pfx  = trim((string)($this->panel['remark_prefix'] ?? ''));
        if (in_array(strtolower($pfx), ['null', 'undefined', 'nan'], true)) $pfx = '';
        $note = trim($pfx . ' ' . $email);

        $body = [
            'uuid'           => $uuid,
            'name'           => $name,
            'usage_limit_GB' => $volumeGb > 0 ? round($volumeGb, 3) : 0,
            'package_days'   => self::daysOf($expiryMs),
            'mode'           => 'no_reset',
            'enable'         => true,
            'comment'        => mb_substr($note, 0, 190),
        ];

        $r = $this->hdApi('/api/v2/admin/user/', $body, 'POST');
        if (!$r['ok']) {
            /* همین uuid از قبل روی پنل است: حجم/روز بازنویسی و مصرف صفر می‌شود */
            $exist = $this->hdUser($uuid);
            if ($exist) {
                $this->hdApi('/api/v2/admin/user/' . rawurlencode($uuid) . '/', [
                    'usage_limit_GB'   => $volumeGb > 0 ? round($volumeGb, 3) : 0,
                    'package_days'     => self::daysOf($expiryMs),
                    'current_usage_GB' => 0,
                    'start_date'       => null,
                    'enable'           => true,
                ], 'PATCH');
                $fresh = $this->hdUser($uuid) ?: $exist;
                $this->mapHd[$name] = $uuid;
                $this->subUrlHd     = $this->hdSubUrl($uuid);
                return ['success' => true, 'client' => $this->hdClientOf($fresh), 'inbound' => $this->inbound(1)];
            }
            app_log('panel', 'hiddify addClient failed', [
                'panel' => (int)($this->panel['id'] ?? 0),
                'name'  => $name,
                'code'  => (int)$r['code'],
                'msg'   => (string)$r['msg'],
            ]);
            return ['success' => false, 'msg' => $r['msg'] !== '' ? $r['msg'] : 'خطا در ساخت کاربر در هیدیفای'];
        }

        $this->usersCache   = null;
        $this->mapHd[$name] = $uuid;
        $this->subUrlHd     = $this->hdSubUrl($uuid);
        $u = $this->hdUser($uuid);
        if (!$u) {
            /* پنل تأیید کرد اما خواندن کاربر ناموفق بود؛ با داده‌های محلی پاسخ می‌دهیم */
            $u = [
                'uuid'           => $uuid,
                'name'           => $name,
                'usage_limit_GB' => $volumeGb,
                'package_days'   => self::daysOf($expiryMs),
                'enable'         => true,
            ];
        }
        return ['success' => true, 'client' => $this->hdClientOf($u), 'inbound' => $this->inbound(1)];
    }

    public function updateClient(int $inboundId, string $uuid, array $client, array $inboundIds = []): array
    {
        $id = trim($uuid);
        if ($id === '') $id = trim((string)($client['id'] ?? ''));
        if ($id === '') $id = $this->hdUuidFor((string)($client['email'] ?? ''));
        if ($id === '') return ['success' => false, 'msg' => 'کاربر هیدیفای برای ویرایش پیدا نشد'];

        $exp  = (int)($client['expiryTime'] ?? 0);
        $body = [
            'usage_limit_GB' => self::bytesToGb((int)($client['totalGB'] ?? 0)),
            'package_days'   => self::daysOf($exp),
            /* شمارش روزها از امروز شروع شود تا تاریخ انقضا دقیقاً همان مقدار ربات باشد */
            'start_date'     => gmdate('Y-m-d'),
        ];
        if (array_key_exists('enable', $client)) $body['enable'] = !empty($client['enable']);

        $r = $this->hdApi('/api/v2/admin/user/' . rawurlencode($id) . '/', $body, 'PATCH');
        if ($r['ok']) {
            $this->usersCache = null;
            return ['success' => true, 'obj' => $r['json']];
        }
        return ['success' => false, 'msg' => $r['msg'] !== '' ? $r['msg'] : 'خطا در ویرایش کاربر هیدیفای'];
    }

    public function resetTraffic(int $inboundId, string $email): array
    {
        $id = $this->hdUuidFor($email);
        if ($id === '') return ['success' => false, 'msg' => 'کاربر هیدیفای پیدا نشد'];
        $r = $this->hdApi('/api/v2/admin/user/' . rawurlencode($id) . '/', [
            'current_usage_GB' => 0,
            'last_reset_time'  => gmdate('Y-m-d'),
        ], 'PATCH');
        $this->usersCache = null;
        return $r['ok'] ? ['success' => true] : ['success' => false, 'msg' => $r['msg']];
    }

    public function setEnabled(string $email, bool $on): array
    {
        $id = $this->hdUuidFor($email);
        if ($id === '') return ['success' => false, 'msg' => 'کاربر هیدیفای پیدا نشد'];
        $r = $this->hdApi('/api/v2/admin/user/' . rawurlencode($id) . '/', ['enable' => $on], 'PATCH');
        $this->usersCache = null;
        if ($r['ok']) return ['success' => true, 'obj' => $r['json']];
        return ['success' => false, 'msg' => $r['msg'] !== '' ? $r['msg'] : 'خطا در تغییر وضعیت کاربر هیدیفای'];
    }

    public function revokeSub(string $email): array
    {
        /* لینک اشتراک هیدیفای به UUID کاربر وابسته است و API لغو ندارد */
        return ['success' => false, 'msg' => 'هیدیفای امکان لغو لینک اشتراک را ندارد؛ برای تغییر لینک باید سرویس دوباره ساخته شود.'];
    }

    public function deleteClient(int $inboundId, string $uuid): array
    {
        $id = trim($uuid);
        if ($id === '') return ['success' => false, 'msg' => 'کاربر برای حذف پیدا نشد'];
        $r = $this->hdApi('/api/v2/admin/user/' . rawurlencode($id) . '/', null, 'DELETE');
        $this->usersCache = null;
        if ($r['ok'] || (int)$r['code'] === 404) return ['success' => true];
        return ['success' => false, 'msg' => $r['msg'] !== '' ? $r['msg'] : 'حذف کاربر در هیدیفای ناموفق بود'];
    }

    public function deleteByEmail(int $inboundId, string $email): array
    {
        $id = $this->hdUuidFor($email);
        if ($id === '') return ['success' => true]; /* روی پنل نیست = حذف‌شده */
        return $this->deleteClient($inboundId, $id);
    }

    /* ------------------------------------------------------------------ */
    /*  خواندن و ساب                                                      */
    /* ------------------------------------------------------------------ */

    public function findClient(int $inboundId, string $email): ?array
    {
        $id = $this->hdUuidFor($email);
        if ($id === '') return null;
        $u = $this->hdUser($id);
        if (!$u) return null;
        $this->subUrlHd = $this->hdSubUrl($id);
        return $this->hdClientOf($u);
    }

    public function accountRow(string $email): ?array
    {
        $id = $this->hdUuidFor($email);
        if ($id === '') return null;
        $u = $this->hdUser($id);
        if (!$u) return null;
        $this->subUrlHd = $this->hdSubUrl($id);
        return $u;
    }

    public function subUrlFor(string $email): string
    {
        $id = $this->hdUuidFor($email);
        if ($id === '') return '';
        $this->subUrlHd = $this->hdSubUrl($id);
        return $this->subUrlHd;
    }

    public function subLink(string $subId): string
    {
        if ($this->subUrlHd !== '') return $this->subUrlHd;
        $sid = trim($subId);
        if ($sid !== '' && preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-~i', $sid)) {
            $this->subUrlHd = $this->hdSubUrl($sid);
        }
        return $this->subUrlHd;
    }

    public function subFetch(string $subId, string $subUrl = ''): array
    {
        $url = trim($subUrl) !== '' ? trim($subUrl) : $this->subLink(trim($subId));
        if ($url === '') return [];
        $base = rtrim($url, '/');
        $cands = [$base, $base . '/all.txt', preg_replace('~/sub$~', '/all.txt', $base)];
        $seen  = [];
        foreach ($cands as $u) {
            $u = (string)$u;
            if ($u === '' || isset($seen[$u])) continue;
            $seen[$u] = true;
            $links = Xui::parseSubBody(Xui::httpGet($u));
            if ($links) return $links;
        }
        return [];
    }

    public function rebuildConfigs(string $email, string $uuid, array $extraInbounds = []): array
    {
        $id = trim($uuid) !== '' ? trim($uuid) : $this->hdUuidFor($email);
        if ($id === '') return [];
        $this->subUrlHd = $this->hdSubUrl($id);
        $links = $this->subFetch('', $this->subUrlHd);
        if ($links) $this->cfgLinks = $links;
        return $this->cfgLinks;
    }

    public function buildConfigLink(array $inbound, string $uuid, string $email): string
    {
        if (!$this->cfgLinks) $this->rebuildConfigs($email, $uuid);
        return implode("\n", $this->cfgLinks);
    }

    /* ------------------------------------------------------------------ */
    /*  مصرف و سلامت                                                      */
    /* ------------------------------------------------------------------ */

    public function clientTraffic(string $email): ?array
    {
        $u = $this->accountRow($email);
        if (!$u) return null;
        $exp = self::expiryTs($u);
        return [
            'email'      => (string)($u['name'] ?? $email),
            'up'         => 0,
            'down'       => self::gbToBytes((float)($u['current_usage_GB'] ?? 0)),
            'total'      => self::gbToBytes((float)($u['usage_limit_GB'] ?? 0)),
            'expiryTime' => $exp > 0 ? $exp * 1000 : 0,
            'enable'     => array_key_exists('enable', $u) ? (bool)$u['enable'] : true,
        ];
    }

    public function liveTraffic(string $email): ?array
    {
        return $this->clientTraffic($email);
    }

    public function lastOnline(string $email): int
    {
        $u = $this->accountRow($email);
        return $u ? self::tsOf($u['last_online'] ?? null) : 0;
    }

    /** هیدیفای نود جداگانه ندارد (چند دامنه دارد) */
    public function nodes(): ?array
    {
        return null;
    }

    public function healthCheck(): array
    {
        if ($this->key() === '') {
            return ['ok' => false, 'message' => 'کلید API هیدیفای وارد نشده است. در پنل هیدیفای بخش ادمین، کلید (UUID ادمین) را بردارید و در تنظیمات پنل ثبت کنید.'];
        }
        $users = $this->hdUsers(true);
        if (!$users) {
            $info = $this->hdApi('/api/v2/panel/info/');
            if (!$info['ok']) {
                return ['ok' => false, 'message' => 'اتصال به هیدیفای ناموفق بود: ' . ($info['msg'] !== '' ? $info['msg'] : 'پاسخی دریافت نشد') . ' — آدرس، «مسیر وب» (proxy path) و کلید API را بررسی کنید.'];
            }
            return [
                'ok'          => true,
                'message'     => 'اتصال به هیدیفای موفق بود اما هنوز کاربری روی پنل نیست.',
                'inbounds'    => $this->inbounds(),
                'inbound_ids' => [1],
            ];
        }
        $active = 0;
        foreach ($users as $u) {
            if (!empty($u['enable'])) $active++;
        }
        return [
            'ok'          => true,
            'message'     => 'اتصال به هیدیفای موفق بود. • کاربران پنل: ' . fa_num(count($users)) . ' • فعال: ' . fa_num($active),
            'inbounds'    => $this->inbounds(),
            'inbound_ids' => [1],
        ];
    }
}
