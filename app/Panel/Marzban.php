<?php
declare(strict_types=1);

/**
 * کلاینت API پنل مرزبان (Marzban) و فورک‌های سازگار
 *
 * روند کار:
 *   ۱) آدرس پنل + یوزرنیم و پسورد ادمین  →  دریافت توکن
 *   ۲) ساخت کاربر روی پنل  →  پنل خودش لینک اشتراک و همهٔ کانفیگ‌ها را برمی‌گرداند
 *   ۳) اینباندها و پروتکل‌ها خودکار از خود پنل خوانده می‌شوند؛ کد اینباند لازم نیست.
 *
 * این کلاس همان متدهای Xui را دارد تا در سراسر ربات جایگزین‌پذیر باشد.
 */
class Marzban
{
    public array $panel;
    private ?string $token = null;
    /** آخرین کانفیگ‌های دریافت‌شده از پنل */
    private array $links = [];
    /** آخرین لینک اشتراک دریافت‌شده از پنل */
    private string $subUrl = '';
    /** آخرین نام کاربری خوانده‌شده از پنل */
    private string $lastUser = '';
    /** نگاشت uuid به نام کاربری مرزبان */
    private array $userMap = [];
    private ?array $inboundCache = null;

    public function __construct(array $panel) { $this->panel = $panel; }

    public static function forPanel($panelId): ?self
    {
        $p = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)$panelId]);
        return $p ? new self($p) : null;
    }

    /** آدرس پایه: scheme://host:port[/web_path] */
    public function base(): string
    {
        $scheme = $this->panel['scheme'] ?: 'https';
        $host   = trim((string)$this->panel['host']);
        $port   = (int)$this->panel['port'];
        $url    = $scheme . '://' . $host;
        if ($port && !in_array($port, [80, 443], true)) $url .= ':' . $port;
        $path = trim((string)($this->panel['web_path'] ?? ''), '/');
        if ($path !== '') $url .= '/' . $path;
        return $url;
    }

    public function nodeHost(): string
    {
        $n = trim((string)($this->panel['node_host'] ?? ''));
        return $n !== '' ? $n : trim((string)$this->panel['host']);
    }

    public function isVpnUi(): bool { return false; }

    /** نام کاربری مجاز مرزبان: فقط a-z و 0-9 و آندرلاین (فقط در میانه)، طول ۳ تا ۳۲ (نتیجه همیشه یکسان است) */
    public static function safeName(string $email): string
    {
        $raw  = trim($email);
        $hash = substr(md5($raw !== '' ? $raw : 'marzban'), 0, 8);

        /* فقط حروف کوچک، رقم و آندرلاین؛ هر چیز دیگر به آندرلاین تبدیل می‌شود */
        $u = function_exists('en_num') ? en_num($raw) : $raw;
        $u = strtolower($u);
        $u = (string)preg_replace('~[^a-z0-9]+~', '_', $u);
        $u = (string)preg_replace('~_{2,}~', '_', $u);
        $u = trim($u, '_');

        /* آندرلاین فقط در میانه مجاز است و طول باید بین 3 تا 32 کاراکتر باشد */
        if ($u === '')       $u = 'u' . $hash;
        if (strlen($u) > 32) $u = rtrim(substr($u, 0, 23), '_') . '_' . $hash;
        if (strlen($u) < 3)  $u = $u . '_' . substr($hash, 0, 3);

        $u = trim($u, '_');
        if (strlen($u) < 3)  $u = 'usr' . substr($hash, 0, 5);
        return $u;
    }

    /* ------------------------------------------------------------------ */

    private function curl(string $url, $body = null, string $method = 'GET', bool $form = false): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($this->token) $headers[] = 'Authorization: Bearer ' . $this->token;
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
            $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : ($form ? http_build_query($body) : jenc($body));
            $headers[] = $form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw  = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return ['code' => $code, 'body' => $raw, 'json' => jdec($raw, []), 'error' => $err];
    }

    /** پیام خطای خوانا از پاسخ پنل */
    private static function errOf(array $res): string
    {
        $detail = $res['json']['detail'] ?? '';
        if (is_array($detail)) $detail = jenc($detail);
        $detail = (string)$detail;
        if (($res['error'] ?? '') !== '') return (string)$res['error'];
        return $detail !== '' ? mb_substr($detail, 0, 200) : ('HTTP ' . (int)($res['code'] ?? 0));
    }

    /** ورود ادمین و گرفتن توکن (توکن در ستون session پنل نگهداری می‌شود) */
    public function login(bool $force = false): bool
    {
        if (!$force) {
            $sess = (string)($this->panel['session'] ?? '');
            $at   = (string)($this->panel['session_at'] ?? '');
            if ($sess !== '' && $at !== '' && (time() - strtotime($at)) < 3000) {
                $this->token = $sess;
                return true;
            }
        }
        $this->token = null;
        $res = $this->curl($this->base() . '/api/admin/token', [
            'username'   => (string)$this->panel['username'],
            'password'   => app_decrypt((string)$this->panel['password']),
            'grant_type' => 'password',
        ], 'POST', true);

        $tok = (string)($res['json']['access_token'] ?? '');
        if ($tok !== '') {
            $this->token = $tok;
            DB::update('panels', ['session' => $tok, 'session_at' => now(), 'last_error' => null], 'id = :id', [':id' => (int)$this->panel['id']]);
            $this->panel['session']    = $tok;
            $this->panel['session_at'] = now();
            return true;
        }
        $msg = self::errOf($res);
        try {
            DB::update('panels', ['last_error' => mb_substr('ورود ناموفق: ' . $msg, 0, 240)], 'id = :id', [':id' => (int)$this->panel['id']]);
        } catch (Throwable $e) { }
        app_log('panel', 'marzban login failed', ['panel' => (string)($this->panel['name'] ?? ''), 'msg' => $msg]);
        return false;
    }

    /** فراخوانی API با توکن؛ در صورت انقضای توکن یک بار دوباره وارد می‌شود */
    private function api(string $path, $body = null, string $method = 'GET'): array
    {
        if (!$this->login()) {
            return ['ok' => false, 'code' => 0, 'json' => [], 'msg' => 'اتصال به پنل ناموفق (ورود)'];
        }
        $res = $this->curl($this->base() . $path, $body, $method);
        if ((int)$res['code'] === 401 || (int)$res['code'] === 403) {
            if (!$this->login(true)) {
                return ['ok' => false, 'code' => 401, 'json' => [], 'msg' => 'توکن پنل نامعتبر است'];
            }
            $res = $this->curl($this->base() . $path, $body, $method);
        }
        $code = (int)$res['code'];
        $ok   = $code >= 200 && $code < 300;
        return [
            'ok'   => $ok,
            'code' => $code,
            'json' => is_array($res['json'] ?? null) ? (array)$res['json'] : [],
            'msg'  => $ok ? '' : self::errOf($res),
        ];
    }

    /* ---------------------- اینباندها ---------------------- */

    /**
     * اینباندهای پنل به تفکیک پروتکل: ['vless' => ['tag1','tag2'], ...]
     * اگر مدیر در فیلد «کد اینباندها» نام تگ‌ها را نوشته باشد، فقط همان‌ها استفاده می‌شوند.
     */
    public function inboundTags(): array
    {
        if ($this->inboundCache !== null) return $this->inboundCache;
        $r   = $this->api('/api/inbounds');
        $out = [];
        foreach ((array)$r['json'] as $proto => $rows) {
            $proto = strtolower((string)$proto);
            if (!in_array($proto, ['vless', 'vmess', 'trojan', 'shadowsocks'], true)) continue;
            foreach ((array)$rows as $row) {
                $tag = is_array($row) ? (string)($row['tag'] ?? '') : (string)$row;
                $tag = trim($tag);
                if ($tag !== '') $out[$proto][] = $tag;
            }
        }
        $only = array_values(array_filter(array_map('trim', preg_split('~[\s,;]+~', (string)($this->panel['inbound_ids'] ?? '')) ?: [])));
        $only = array_values(array_filter($only, static fn($v) => $v !== '' && !is_numeric($v)));
        if ($only) {
            foreach ($out as $proto => $tags) {
                $keep = array_values(array_intersect($tags, $only));
                if ($keep) $out[$proto] = $keep;
                else unset($out[$proto]);
            }
        }
        $this->inboundCache = $out;
        return $out;
    }

    /** فهرست نمایشی اینباندها برای پنل مدیریت (شناسه فقط ترتیبی و نمایشی است) */
    public function inbounds(): array
    {
        $list = []; $i = 0;
        foreach ($this->inboundTags() as $proto => $tags) {
            foreach ($tags as $tag) {
                $i++;
                $list[] = [
                    'id'       => $i,
                    'protocol' => $proto,
                    'remark'   => $tag,
                    'tag'      => $tag,
                    'port'     => 0,
                    'enable'   => true,
                ];
            }
        }
        return $list;
    }

    public function inbound(int $id): ?array
    {
        foreach ($this->inbounds() as $in) {
            if ((int)$in['id'] === $id) return $in;
        }
        return [
            'id'       => $id > 0 ? $id : 1,
            'protocol' => 'vless',
            'remark'   => (string)($this->panel['name'] ?? 'Marzban'),
            'tag'      => '',
            'port'     => 0,
            'enable'   => true,
        ];
    }

    /** در مرزبان اینباندها خودکارند؛ یک شناسهٔ مجازی کافی است */
    public function allowedInboundIds(): array { return [1]; }

    public function assignableInboundIds(): array { return [1]; }

    public function accountInboundIds(string $email): array { return [1]; }

    public static function idList($raw): array { return Xui::idList($raw); }

    public static function speedKeys(int $upKbps, int $downKbps): array { return []; }

    /* ---------------------- ساخت کاربر ---------------------- */

    /**
     * ساخت کاربر روی مرزبان
     * پروتکل‌ها و اینباندها خودکار از خود پنل خوانده می‌شوند و لینک اشتراک و
     * کانفیگ‌ها از پاسخ همان درخواست برداشته می‌شود.
     */
    public function addClient(int $inboundId, string $email, string $uuid, float $volumeGb, int $expiryMs, int $ipLimit = 0, string $subId = '', array $inboundIds = [], int $deviceLimit = 0, int $upKbps = 0, int $downKbps = 0): array
    {
        $user = self::safeName($email);
        $tags = $this->inboundTags();
        if (!$tags) {
            return ['success' => false, 'msg' => 'هیچ اینباند فعالی در مرزبان پیدا نشد. ابتدا در خود پنل اینباند بسازید.'];
        }

        $proxies = []; $inbounds = [];
        foreach ($tags as $proto => $list) {
            $inbounds[$proto] = array_values($list);
            if ($proto === 'trojan' || $proto === 'shadowsocks') $proxies[$proto] = ['password' => $uuid];
            else                                                 $proxies[$proto] = ['id' => $uuid];
        }
        if (isset($proxies['shadowsocks'])) $proxies['shadowsocks']['method'] = 'chacha20-ietf-poly1305';
        if (isset($proxies['vless']))       $proxies['vless']['flow']         = '';

        $pfx  = trim((string)($this->panel['remark_prefix'] ?? ''));
        if (in_array(strtolower($pfx), ['null', 'undefined', 'nan'], true)) $pfx = '';
        $note = trim($pfx . ' ' . $email);
        $body = [
            'username'                  => $user,
            'proxies'                   => $proxies,
            'inbounds'                  => $inbounds,
            'expire'                    => $expiryMs > 0 ? (int)round($expiryMs / 1000) : 0,
            'data_limit'                => $volumeGb > 0 ? (int)gb2bytes($volumeGb) : 0,
            'data_limit_reset_strategy' => 'no_reset',
            'status'                    => 'active',
            'note'                      => mb_substr($note, 0, 190),
        ];

        $r = $this->api('/api/user', $body, 'POST');
        if (!$r['ok']) {
            /* اگر همین نام از قبل روی پنل باشد، همان کاربر برگردانده می‌شود */
            if ((int)$r['code'] === 409) {
                /* کاربر قدیمی با همین نام: حجم/زمان/وضعیت با خرید جدید بازنویسی و مصرف قبلی صفر می‌شود */
                $upd = $this->api('/api/user/' . rawurlencode($user), [
                    'data_limit' => $volumeGb > 0 ? gb2bytes($volumeGb) : 0,
                    'expire'     => $expiryMs > 0 ? (int)floor($expiryMs / 1000) : 0,
                    'status'     => 'active',
                ], 'PUT');
                if (!$upd['ok']) app_log('panel', 'marzban 409 refresh failed', ['username' => $user, 'msg' => (string)$upd['msg']]);
                try { $this->api('/api/user/' . rawurlencode($user) . '/reset', null, 'POST'); } catch (Throwable $e) { }
                $exist = $this->api('/api/user/' . rawurlencode($user));
                if ($exist['ok'] && $exist['json']) {
                    $this->absorb($exist['json'], $uuid);
                    return ['success' => true, 'client' => $this->clientOf($exist['json'], $uuid), 'inbound' => $this->inbound(1)];
                }
            }
            app_log('panel', 'marzban addClient failed', [
                'panel'    => (int)($this->panel['id'] ?? 0),
                'username' => $user,
                'code'     => (int)$r['code'],
                'msg'      => (string)$r['msg'],
            ]);
            return ['success' => false, 'msg' => $r['msg'] !== '' ? $r['msg'] : 'خطا در ساخت کاربر در مرزبان'];
        }

        $this->absorb($r['json'], $uuid);
        /* بعضی نسخه‌ها لینک اشتراک را در پاسخ ساخت نمی‌فرستند؛ یک بار مستقیم می‌خوانیم */
        if ($this->subUrl === '' || !$this->links) {
            $again = $this->api('/api/user/' . rawurlencode($user));
            if ($again['ok'] && $again['json']) $this->absorb((array)$again['json'], $uuid);
        }
        return ['success' => true, 'client' => $this->clientOf($r['json'], $uuid), 'inbound' => $this->inbound(1)];
    }

    /** برداشتن لینک اشتراک و کانفیگ‌ها از پاسخ پنل */
    private function absorb(array $u, string $uuid = ''): void
    {
        $links = [];
        foreach ((array)($u['links'] ?? []) as $l) {
            $l = trim((string)$l);
            if ($l !== '') $links[] = $l;
        }
        if ($links) $this->links = array_values(array_unique($links));

        $sub = trim((string)($u['subscription_url'] ?? ''));
        if ($sub !== '') $this->subUrl = $this->absUrl($sub);

        $name = trim((string)($u['username'] ?? ''));
        if ($name !== '') {
            $this->lastUser = $name;
            if ($uuid !== '') $this->userMap[$uuid] = $name;
            foreach ((array)($u['proxies'] ?? []) as $px) {
                if (!is_array($px)) continue;
                $key = (string)($px['id'] ?? ($px['password'] ?? ''));
                if ($key !== '') $this->userMap[$key] = $name;
            }
        }
    }

    /** تبدیل مسیر نسبی ساب به آدرس کامل */
    private function absUrl(string $u): string
    {
        if (preg_match('~^https?://~i', $u)) return $u;
        $base = trim((string)($this->panel['sub_base'] ?? ''));
        $root = $base !== '' ? rtrim($base, '/') : $this->base();
        return $root . '/' . ltrim($u, '/');
    }

    /** تبدیل پاسخ مرزبان به ساختار کلاینتی که ربات می‌شناسد */
    private function clientOf(array $u, string $uuid = ''): array
    {
        $id = $uuid;
        foreach ((array)($u['proxies'] ?? []) as $px) {
            if (!is_array($px)) continue;
            $cand = (string)($px['id'] ?? ($px['password'] ?? ''));
            if ($cand !== '') { $id = $cand; break; }
        }
        $exp = (int)($u['expire'] ?? 0);
        return [
            'email'      => (string)($u['username'] ?? ''),
            'id'         => $id,
            'password'   => $id,
            'totalGB'    => (int)($u['data_limit'] ?? 0),
            'expiryTime' => $exp > 0 ? $exp * 1000 : 0,
            'enable'     => in_array((string)($u['status'] ?? 'active'), ['active', 'on_hold'], true),
            'limitIp'    => 0,
            'subId'      => (string)($u['subscription_url'] ?? ''),
        ];
    }

    /* ---------------------- ساب و کانفیگ‌ها ---------------------- */

    /** کانفیگ‌های همین اکانت (چند خط) — از پاسخ ساخت یا مستقیم از پنل */
    public function buildConfigLink(array $inbound, string $uuid, string $email): string
    {
        if (!$this->links && $email !== '') $this->rebuildConfigs($email, $uuid);
        return implode("\n", $this->links);
    }

    /**
     * لینک اشتراک مرزبان
     *
     * در مرزبان آدرس ساب یک توکن (JWT) است که خود پنل تولید می‌کند؛
     * چسباندن کد ساب داخلی ربات به sub_base یک آدرس بی‌اعتبار می‌سازد.
     * پس فقط مقدار واقعی پنل برگردانده می‌شود (یا رشته خالی).
     */
    public function subLink(string $subId): string
    {
        if ($this->subUrl !== '') return $this->subUrl;
        if ($this->lastUser !== '') {
            $r = $this->api('/api/user/' . rawurlencode($this->lastUser));
            if ($r['ok'] && $r['json']) $this->absorb((array)$r['json']);
        }
        return $this->subUrl;
    }

    /** لینک اشتراک واقعی پنل برای یک نام کاربری */
    public function subUrlFor(string $email): string
    {
        $user = self::safeName($email);
        if ($user === '') return '';
        if ($this->subUrl !== '' && $this->lastUser === $user) return $this->subUrl;
        $r = $this->api('/api/user/' . rawurlencode($user));
        if ($r['ok'] && $r['json']) {
            $this->absorb((array)$r['json']);
            if ($this->lastUser === $user) return $this->subUrl;
        }
        return '';
    }

    /** خواندن کانفیگ‌ها از روی لینک اشتراک مرزبان */
    public function subFetch(string $subId, string $subUrl = ''): array
    {
        $url = trim($subUrl) !== '' ? trim($subUrl) : $this->subLink(trim($subId));
        if ($url === '') return [];
        foreach (['', '/v2ray', '/links'] as $suffix) {
            $links = Xui::parseSubBody(Xui::httpGet(rtrim($url, '/') . $suffix));
            if ($links) return $links;
        }
        return [];
    }

    /** بازخوانی کانفیگ‌های اکانت از خود پنل */
    public function rebuildConfigs(string $email, string $uuid, array $extraInbounds = []): array
    {
        $r = $this->api('/api/user/' . rawurlencode(self::safeName($email)));
        if ($r['ok'] && $r['json']) $this->absorb($r['json'], $uuid);
        if ($this->links) return $this->links;
        return $this->subFetch('', $this->subUrl);
    }

    /* ---------------------- خواندن، ویرایش و حذف ---------------------- */

    public function findClient(int $inboundId, string $email): ?array
    {
        $user = self::safeName($email);
        $r    = $this->api('/api/user/' . rawurlencode($user));
        if (!$r['ok'] || !$r['json']) return null;
        $this->absorb($r['json']);
        $c = $this->clientOf($r['json']);
        if ((string)($c['id'] ?? '') !== '') $this->userMap[(string)$c['id']] = $user;
        return $c;
    }

    public function accountRow(string $email): ?array
    {
        $r = $this->api('/api/user/' . rawurlencode(self::safeName($email)));
        if (!$r['ok'] || !$r['json']) return null;
        /* همین‌جا لینک ساب و کانفیگ‌ها هم در حافظه می‌نشیند */
        $this->absorb((array)$r['json']);
        return (array)$r['json'];
    }

    /** ویرایش حجم، تاریخ انقضا و وضعیت کاربر */
    public function updateClient(int $inboundId, string $uuid, array $client, array $inboundIds = []): array
    {
        $user = trim((string)($client['email'] ?? ''));
        if ($user === '') $user = (string)($this->userMap[$uuid] ?? '');
        if ($user === '') return ['success' => false, 'msg' => 'نام کاربری برای ویرایش مشخص نیست'];
        $user = self::safeName($user);

        $exp  = (int)($client['expiryTime'] ?? 0);
        $body = [
            'data_limit' => max(0, (int)($client['totalGB'] ?? 0)),
            'expire'     => $exp > 0 ? (int)round($exp / 1000) : 0,
            'status'     => !empty($client['enable']) ? 'active' : 'disabled',
        ];

        $r = $this->api('/api/user/' . rawurlencode($user), $body, 'PUT');
        if ($r['ok']) {
            $this->absorb($r['json']);
            return ['success' => true, 'obj' => $r['json']];
        }
        return ['success' => false, 'msg' => $r['msg'] !== '' ? $r['msg'] : 'خطا در ویرایش کاربر مرزبان'];
    }

    public function resetTraffic(int $inboundId, string $email): array
    {
        $r = $this->api('/api/user/' . rawurlencode(self::safeName($email)) . '/reset', null, 'POST');
        return $r['ok'] ? ['success' => true] : ['success' => false, 'msg' => $r['msg']];
    }

    public function deleteClient(int $inboundId, string $uuid): array
    {
        $user = (string)($this->userMap[$uuid] ?? '');
        if ($user === '') return ['success' => false, 'msg' => 'کاربر برای حذف پیدا نشد'];
        return $this->deleteByEmail($inboundId, $user);
    }

    public function deleteByEmail(int $inboundId, string $email): array
    {
        $r = $this->api('/api/user/' . rawurlencode(self::safeName($email)), null, 'DELETE');
        if ($r['ok'] || (int)$r['code'] === 404) return ['success' => true];
        return ['success' => false, 'msg' => $r['msg'] !== '' ? $r['msg'] : 'حذف کاربر در مرزبان ناموفق بود'];
    }

    /* ---------------------- مصرف ---------------------- */

    public function clientTraffic(string $email): ?array
    {
        $u = $this->accountRow($email);
        if (!$u) return null;
        $used = (int)($u['used_traffic'] ?? 0);
        $exp  = (int)($u['expire'] ?? 0);
        return [
            'email'      => (string)($u['username'] ?? $email),
            'up'         => 0,
            'down'       => $used,
            'total'      => (int)($u['data_limit'] ?? 0),
            'expiryTime' => $exp > 0 ? $exp * 1000 : 0,
            'enable'     => in_array((string)($u['status'] ?? 'active'), ['active', 'on_hold'], true),
        ];
    }

    public function liveTraffic(string $email): ?array
    {
        return $this->clientTraffic($email);
    }

    /* ---------------------- تست سلامت ---------------------- */

    /** fixed79: لغو لینک اشتراک فعلی و ساخت لینک جدید (POST /api/user/{u}/revoke_sub) */
    public function revokeSub(string $email): array
    {
        $r = $this->api('/api/user/' . rawurlencode(self::safeName($email)) . '/revoke_sub', null, 'POST');
        if ($r['ok']) { if (is_array($r['json'])) $this->absorb((array)$r['json']); return ['success' => true, 'obj' => $r['json']]; }
        return ['success' => false, 'msg' => $r['msg'] !== '' ? $r['msg'] : 'خطا در لغو لینک اشتراک مرزبان'];
    }

    /** fixed79: قطع/وصل کاربر (status active/disabled) */
    public function setEnabled(string $email, bool $on): array
    {
        $r = $this->api('/api/user/' . rawurlencode(self::safeName($email)), ['status' => $on ? 'active' : 'disabled'], 'PUT');
        if ($r['ok']) { if (is_array($r['json'])) $this->absorb((array)$r['json']); return ['success' => true, 'obj' => $r['json']]; }
        return ['success' => false, 'msg' => $r['msg'] !== '' ? $r['msg'] : 'خطا در تغییر وضعیت کاربر مرزبان'];
    }

    /** fixed79: آخرین اتصال (online_at) به ثانیهٔ یونیکس؛ ۰ = نامشخص */
    public function lastOnline(string $email): int
    {
        $u = $this->accountRow($email);
        $v = $u ? ($u['online_at'] ?? null) : null;
        if ($v === null || $v === '') return 0;
        return is_numeric($v) ? (int)$v : (strtotime((string)$v) ?: 0);
    }

    /** fixed79: وضعیت نودها (GET /api/nodes — فقط ادمین sudo). null = در دسترس نیست */
    public function nodes(): ?array
    {
        $r = $this->api('/api/nodes');
        if (!$r['ok']) return null;
        $src = $r['json'];
        if (isset($src['nodes']) && is_array($src['nodes'])) $src = $src['nodes'];
        $out = [];
        foreach ((array)$src as $n) {
            if (!is_array($n)) continue;
            $st = strtolower(trim((string)($n['status'] ?? '')));
            $out[] = [
                'id'       => (int)($n['id'] ?? 0),
                'name'     => (string)($n['name'] ?? ('node-' . (int)($n['id'] ?? 0))),
                'status'   => $st,
                'ok'       => $st === 'connected',
                'disabled' => $st === 'disabled',
                'message'  => (string)($n['message'] ?? ''),
            ];
        }
        return $out;
    }

    public function healthCheck(): array
    {
        if (!$this->login(true)) {
            return ['ok' => false, 'message' => 'ورود به مرزبان ناموفق بود. آدرس، پورت و یوزر/پسورد ادمین را بررسی کنید.'];
        }
        $inb   = $this->inbounds();
        $extra = '';
        $sys   = $this->api('/api/system');
        if ($sys['ok']) {
            $j = $sys['json'];
            $extra = ' • کاربران پنل: ' . fa_num((int)($j['total_user'] ?? 0))
                   . ' • فعال: ' . fa_num((int)($j['users_active'] ?? 0));
        }
        $extra .= Xui::nodesNote($this->nodes()); /* fixed79: وضعیت نودها */
        if (!$inb) {
            return ['ok' => true, 'message' => 'اتصال موفق بود اما اینباندی در پنل پیدا نشد.' . $extra, 'inbounds' => [], 'inbound_ids' => []];
        }
        return [
            'ok'          => true,
            'message'     => 'اتصال به مرزبان موفق بود.' . $extra,
            'inbounds'    => $inb,
            'inbound_ids' => array_map(static fn($i) => (int)$i['id'], $inb),
        ];
    }
}
