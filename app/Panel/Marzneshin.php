<?php
declare(strict_types=1);

/**
 * کلاینت API پنل مرزنشین (Marzneshin)
 *
 * مرزنشین بازنویسی مرزبان است و ساختار API آن متفاوت است:
 *   ورود:      POST /api/admins/token        (فرم‌انکد، username/password)
 *   سرویس‌ها:  GET  /api/services            (جای «اینباند» در مرزبان)
 *   کاربران:   POST /api/users  و  GET|PUT|DELETE /api/users/{username}
 *
 * برای جایگزین‌پذیری در سراسر ربات از Marzban ارث می‌برد و فقط متدهایی که با
 * API کار می‌کنند بازنویسی شده‌اند؛ خروجی همهٔ متدها دقیقاً همان قرارداد قبلی است.
 *
 * 0.0.2 #row16-marzneshin
 */
class Marzneshin extends Marzban
{
    private ?string $tok = null;
    /** آخرین کانفیگ‌های دریافت‌شده از پنل */
    private array $cfgLinks = [];
    /** آخرین لینک اشتراک دریافت‌شده از پنل */
    private string $subUrlMn = '';
    /** آخرین نام کاربری خوانده‌شده */
    private string $lastUserMn = '';
    /** نگاشت uuid/key به نام کاربری */
    private array $mapMn = [];
    /** کش سرویس‌های پنل */
    private ?array $svcCache = null;

    public static function forPanel($panelId): ?self
    {
        $p = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)$panelId]);
        return $p ? new self($p) : null;
    }

    /* ------------------------------------------------------------------ */
    /*  لایهٔ شبکه                                                         */
    /* ------------------------------------------------------------------ */

    private function mnCurl(string $url, $body = null, string $method = 'GET', bool $form = false): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($this->tok) $headers[] = 'Authorization: Bearer ' . $this->tok;
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
    private static function mnErr(array $res): string
    {
        $detail = $res['json']['detail'] ?? ($res['json']['message'] ?? '');
        if (is_array($detail)) $detail = jenc($detail);
        $detail = (string)$detail;
        if (($res['error'] ?? '') !== '') return (string)$res['error'];
        return $detail !== '' ? mb_substr($detail, 0, 200) : ('HTTP ' . (int)($res['code'] ?? 0));
    }

    /** ورود ادمین و گرفتن توکن (در ستون session پنل نگهداری می‌شود) */
    public function login(bool $force = false): bool
    {
        if (!$force) {
            $sess = (string)($this->panel['session'] ?? '');
            $at   = (string)($this->panel['session_at'] ?? '');
            if ($sess !== '' && $at !== '' && (time() - strtotime($at)) < 3000) {
                $this->tok = $sess;
                return true;
            }
        }
        $this->tok = null;
        $creds = [
            'username'   => (string)$this->panel['username'],
            'password'   => app_decrypt((string)$this->panel['password']),
            'grant_type' => 'password',
        ];

        $msg = '';
        /* مسیر رسمی مرزنشین و مسیر قدیمی مرزبان (برای فورک‌های میانی) */
        foreach (['/api/admins/token', '/api/admin/token'] as $path) {
            $res = $this->mnCurl($this->base() . $path, $creds, 'POST', true);
            $tok = (string)($res['json']['access_token'] ?? '');
            if ($tok !== '') {
                $this->tok = $tok;
                try {
                    DB::update('panels', ['session' => $tok, 'session_at' => now(), 'last_error' => null], 'id = :id', [':id' => (int)$this->panel['id']]);
                } catch (Throwable $e) { }
                $this->panel['session']    = $tok;
                $this->panel['session_at'] = now();
                return true;
            }
            $msg = self::mnErr($res);
            if ((int)($res['code'] ?? 0) === 401) break; /* یوزر/پسورد غلط است؛ مسیر دوم را امتحان نکن */
        }

        try {
            DB::update('panels', ['last_error' => mb_substr('ورود ناموفق: ' . $msg, 0, 240)], 'id = :id', [':id' => (int)$this->panel['id']]);
        } catch (Throwable $e) { }
        app_log('panel', 'marzneshin login failed', ['panel' => (string)($this->panel['name'] ?? ''), 'msg' => $msg]);
        return false;
    }

    /** فراخوانی API با توکن؛ در صورت انقضا یک بار دوباره وارد می‌شود */
    private function mnApi(string $path, $body = null, string $method = 'GET'): array
    {
        if (!$this->login()) {
            return ['ok' => false, 'code' => 0, 'json' => [], 'msg' => 'اتصال به پنل ناموفق (ورود)'];
        }
        $res = $this->mnCurl($this->base() . $path, $body, $method);
        if ((int)$res['code'] === 401 || (int)$res['code'] === 403) {
            if (!$this->login(true)) {
                return ['ok' => false, 'code' => 401, 'json' => [], 'msg' => 'توکن پنل نامعتبر است'];
            }
            $res = $this->mnCurl($this->base() . $path, $body, $method);
        }
        $code = (int)$res['code'];
        $ok   = $code >= 200 && $code < 300;
        return [
            'ok'   => $ok,
            'code' => $code,
            'json' => is_array($res['json'] ?? null) ? (array)$res['json'] : [],
            'msg'  => $ok ? '' : self::mnErr($res),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  سرویس‌ها (معادل اینباند)                                           */
    /* ------------------------------------------------------------------ */

    /** سرویس‌های پنل به شکل [id => name] */
    public function services(bool $force = false): array
    {
        if (!$force && $this->svcCache !== null) return $this->svcCache;
        $out = [];
        $r   = $this->mnApi('/api/services?page=1&size=100');
        $src = $r['json']['items'] ?? $r['json'];
        foreach ((array)$src as $s) {
            if (!is_array($s)) continue;
            $id = (int)($s['id'] ?? 0);
            if ($id <= 0) continue;
            $out[$id] = (string)($s['name'] ?? ('service-' . $id));
        }
        $this->svcCache = $out;
        return $out;
    }

    /** شناسهٔ سرویس‌هایی که کاربر باید عضو آن‌ها شود (فیلد «کد اینباندها») */
    public function serviceIds(): array
    {
        $raw  = (string)($this->panel['inbound_ids'] ?? '');
        $only = array_values(array_filter(array_map('intval', preg_split('~[\s,;]+~', $raw) ?: []), static fn($v) => $v > 0));
        $all  = array_keys($this->services());
        if ($only) {
            $keep = array_values(array_intersect($only, $all));
            return $keep ?: $only;
        }
        return $all;
    }

    /** فهرست نمایشی سرویس‌ها برای پنل مدیریت */
    public function inbounds(): array
    {
        $list = [];
        foreach ($this->services() as $id => $name) {
            $list[] = [
                'id'       => $id,
                'protocol' => 'service',
                'remark'   => $name,
                'tag'      => $name,
                'port'     => 0,
                'enable'   => true,
            ];
        }
        return $list;
    }

    public function inboundTags(): array
    {
        $out = [];
        foreach ($this->services() as $name) {
            $out['service'][] = (string)$name;
        }
        return $out;
    }

    public function inbound(int $id): ?array
    {
        foreach ($this->inbounds() as $in) {
            if ((int)$in['id'] === $id) return $in;
        }
        return [
            'id'       => $id > 0 ? $id : 1,
            'protocol' => 'service',
            'remark'   => (string)($this->panel['name'] ?? 'Marzneshin'),
            'tag'      => '',
            'port'     => 0,
            'enable'   => true,
        ];
    }

    /** شناسه‌های قابل استفاده = شناسهٔ سرویس‌ها */
    public function allowedInboundIds(): array
    {
        $ids = $this->serviceIds();
        return $ids ?: [1];
    }

    public function assignableInboundIds(): array { return $this->allowedInboundIds(); }

    public function accountInboundIds(string $email): array
    {
        $u = $this->accountRow($email);
        $ids = [];
        foreach ((array)($u['service_ids'] ?? []) as $v) {
            $v = (int)$v;
            if ($v > 0) $ids[] = $v;
        }
        if (!$ids) {
            foreach ((array)($u['services'] ?? []) as $s) {
                $v = is_array($s) ? (int)($s['id'] ?? 0) : (int)$s;
                if ($v > 0) $ids[] = $v;
            }
        }
        return $ids ?: $this->allowedInboundIds();
    }

    /* ------------------------------------------------------------------ */
    /*  ساخت و ویرایش کاربر                                                */
    /* ------------------------------------------------------------------ */

    /** تبدیل میلی‌ثانیه به تاریخ ISO مورد انتظار مرزنشین */
    private static function isoOf(int $expiryMs): string
    {
        if ($expiryMs <= 0) return '';
        return gmdate('Y-m-d\TH:i:s', (int)round($expiryMs / 1000));
    }

    /** تبدیل تاریخ پنل به ثانیهٔ یونیکس */
    private static function tsOf($v): int
    {
        if ($v === null || $v === '') return 0;
        if (is_numeric($v)) return (int)$v;
        $t = strtotime((string)$v);
        return $t ? (int)$t : 0;
    }

    public function addClient(int $inboundId, string $email, string $uuid, float $volumeGb, int $expiryMs, int $ipLimit = 0, string $subId = '', array $inboundIds = [], int $deviceLimit = 0, int $upKbps = 0, int $downKbps = 0): array
    {
        $user = self::safeName($email);
        $svc  = $this->serviceIds();
        if (!$svc) {
            return ['success' => false, 'msg' => 'هیچ سرویسی در مرزنشین پیدا نشد. ابتدا در خود پنل یک Service بسازید.'];
        }

        $pfx = trim((string)($this->panel['remark_prefix'] ?? ''));
        if (in_array(strtolower($pfx), ['null', 'undefined', 'nan'], true)) $pfx = '';
        $note = trim($pfx . ' ' . $email);

        $body = [
            'username'                  => $user,
            'service_ids'               => array_values($svc),
            'data_limit'                => $volumeGb > 0 ? (int)gb2bytes($volumeGb) : 0,
            'data_limit_reset_strategy' => 'no_reset',
            'note'                      => mb_substr($note, 0, 190),
        ];
        if ($expiryMs > 0) {
            $body['expire_strategy'] = 'fixed_date';
            $body['expire_date']     = self::isoOf($expiryMs);
        } else {
            $body['expire_strategy'] = 'never';
        }

        $r = $this->mnApi('/api/users', $body, 'POST');
        if (!$r['ok']) {
            /* همین نام از قبل روی پنل هست: حجم/زمان بازنویسی و مصرف صفر می‌شود */
            if ((int)$r['code'] === 409) {
                $this->updateClient(0, $uuid, [
                    'email'      => $user,
                    'totalGB'    => $volumeGb > 0 ? (int)gb2bytes($volumeGb) : 0,
                    'expiryTime' => $expiryMs,
                    'enable'     => true,
                ]);
                try { $this->mnApi('/api/users/' . rawurlencode($user) . '/reset', null, 'POST'); } catch (Throwable $e) { }
                $exist = $this->mnApi('/api/users/' . rawurlencode($user));
                if ($exist['ok'] && $exist['json']) {
                    $this->mnAbsorb((array)$exist['json'], $uuid);
                    return ['success' => true, 'client' => $this->mnClientOf((array)$exist['json'], $uuid), 'inbound' => $this->inbound((int)($svc[0] ?? 1))];
                }
            }
            app_log('panel', 'marzneshin addClient failed', [
                'panel'    => (int)($this->panel['id'] ?? 0),
                'username' => $user,
                'code'     => (int)$r['code'],
                'msg'      => (string)$r['msg'],
            ]);
            return ['success' => false, 'msg' => $r['msg'] !== '' ? $r['msg'] : 'خطا در ساخت کاربر در مرزنشین'];
        }

        $this->mnAbsorb((array)$r['json'], $uuid);
        if ($this->subUrlMn === '' || !$this->cfgLinks) {
            $again = $this->mnApi('/api/users/' . rawurlencode($user));
            if ($again['ok'] && $again['json']) $this->mnAbsorb((array)$again['json'], $uuid);
        }
        return ['success' => true, 'client' => $this->mnClientOf((array)$r['json'], $uuid), 'inbound' => $this->inbound((int)($svc[0] ?? 1))];
    }

    /** برداشتن لینک اشتراک و کانفیگ‌ها از پاسخ پنل */
    private function mnAbsorb(array $u, string $uuid = ''): void
    {
        $links = [];
        foreach ((array)($u['links'] ?? []) as $l) {
            $l = trim((string)$l);
            if ($l !== '') $links[] = $l;
        }
        if ($links) $this->cfgLinks = array_values(array_unique($links));

        $sub = trim((string)($u['subscription_url'] ?? ''));
        if ($sub !== '') $this->subUrlMn = $this->mnAbsUrl($sub);

        $name = trim((string)($u['username'] ?? ''));
        if ($name !== '') {
            $this->lastUserMn = $name;
            if ($uuid !== '') $this->mapMn[$uuid] = $name;
            $key = trim((string)($u['key'] ?? ''));
            if ($key !== '') $this->mapMn[$key] = $name;
        }
    }

    private function mnAbsUrl(string $u): string
    {
        if (preg_match('~^https?://~i', $u)) return $u;
        $base = trim((string)($this->panel['sub_base'] ?? ''));
        $root = $base !== '' ? rtrim($base, '/') : $this->base();
        return $root . '/' . ltrim($u, '/');
    }

    /** تبدیل پاسخ مرزنشین به ساختار کلاینت شناخته‌شدهٔ ربات */
    private function mnClientOf(array $u, string $uuid = ''): array
    {
        $key = trim((string)($u['key'] ?? ''));
        $id  = $key !== '' ? $key : $uuid;
        $exp = self::tsOf($u['expire_date'] ?? ($u['expire'] ?? 0));
        $on  = array_key_exists('enabled', $u)
            ? (bool)$u['enabled']
            : (array_key_exists('is_active', $u) ? (bool)$u['is_active'] : true);
        return [
            'email'      => (string)($u['username'] ?? ''),
            'id'         => $id,
            'password'   => $id,
            'totalGB'    => (int)($u['data_limit'] ?? 0),
            'expiryTime' => $exp > 0 ? $exp * 1000 : 0,
            'enable'     => $on,
            'limitIp'    => 0,
            'subId'      => (string)($u['subscription_url'] ?? ''),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  ساب و کانفیگ‌ها                                                    */
    /* ------------------------------------------------------------------ */

    public function buildConfigLink(array $inbound, string $uuid, string $email): string
    {
        if (!$this->cfgLinks && $email !== '') $this->rebuildConfigs($email, $uuid);
        return implode("\n", $this->cfgLinks);
    }

    public function subLink(string $subId): string
    {
        if ($this->subUrlMn !== '') return $this->subUrlMn;
        if ($this->lastUserMn !== '') {
            $r = $this->mnApi('/api/users/' . rawurlencode($this->lastUserMn));
            if ($r['ok'] && $r['json']) $this->mnAbsorb((array)$r['json']);
        }
        return $this->subUrlMn;
    }

    public function subUrlFor(string $email): string
    {
        $user = self::safeName($email);
        if ($user === '') return '';
        if ($this->subUrlMn !== '' && $this->lastUserMn === $user) return $this->subUrlMn;
        $r = $this->mnApi('/api/users/' . rawurlencode($user));
        if ($r['ok'] && $r['json']) {
            $this->mnAbsorb((array)$r['json']);
            if ($this->lastUserMn === $user) return $this->subUrlMn;
        }
        return '';
    }

    public function rebuildConfigs(string $email, string $uuid, array $extraInbounds = []): array
    {
        $r = $this->mnApi('/api/users/' . rawurlencode(self::safeName($email)));
        if ($r['ok'] && $r['json']) $this->mnAbsorb((array)$r['json'], $uuid);
        if ($this->cfgLinks) return $this->cfgLinks;
        return $this->subFetch('', $this->subUrlMn);
    }

    /* ------------------------------------------------------------------ */
    /*  خواندن، ویرایش و حذف                                               */
    /* ------------------------------------------------------------------ */

    public function findClient(int $inboundId, string $email): ?array
    {
        $user = self::safeName($email);
        $r    = $this->mnApi('/api/users/' . rawurlencode($user));
        if (!$r['ok'] || !$r['json']) return null;
        $this->mnAbsorb((array)$r['json']);
        $c = $this->mnClientOf((array)$r['json']);
        if ((string)($c['id'] ?? '') !== '') $this->mapMn[(string)$c['id']] = $user;
        return $c;
    }

    public function accountRow(string $email): ?array
    {
        $r = $this->mnApi('/api/users/' . rawurlencode(self::safeName($email)));
        if (!$r['ok'] || !$r['json']) return null;
        $this->mnAbsorb((array)$r['json']);
        return (array)$r['json'];
    }

    public function updateClient(int $inboundId, string $uuid, array $client, array $inboundIds = []): array
    {
        $user = trim((string)($client['email'] ?? ''));
        if ($user === '') $user = (string)($this->mapMn[$uuid] ?? '');
        if ($user === '') return ['success' => false, 'msg' => 'نام کاربری برای ویرایش مشخص نیست'];
        $user = self::safeName($user);

        /* مرزنشین در PUT به service_ids نیاز دارد؛ از خود کاربر می‌خوانیم */
        $cur = $this->accountRow($user) ?: [];
        $svc = [];
        foreach ((array)($cur['service_ids'] ?? []) as $v) {
            $v = (int)$v;
            if ($v > 0) $svc[] = $v;
        }
        if (!$svc) {
            foreach ((array)($cur['services'] ?? []) as $s) {
                $v = is_array($s) ? (int)($s['id'] ?? 0) : (int)$s;
                if ($v > 0) $svc[] = $v;
            }
        }
        if (!$svc) $svc = $this->serviceIds();

        $exp  = (int)($client['expiryTime'] ?? 0);
        $body = [
            'username'    => $user,
            'service_ids' => array_values($svc),
            'data_limit'  => max(0, (int)($client['totalGB'] ?? 0)),
        ];
        if ($exp > 0) {
            $body['expire_strategy'] = 'fixed_date';
            $body['expire_date']     = self::isoOf($exp);
        } else {
            $body['expire_strategy'] = 'never';
        }

        $r = $this->mnApi('/api/users/' . rawurlencode($user), $body, 'PUT');
        if ($r['ok']) {
            $this->mnAbsorb((array)$r['json']);
            /* فعال/غیرفعال در مرزنشین مسیر جداگانه دارد */
            if (array_key_exists('enable', $client)) {
                $this->setEnabled($user, !empty($client['enable']));
            }
            return ['success' => true, 'obj' => $r['json']];
        }
        return ['success' => false, 'msg' => $r['msg'] !== '' ? $r['msg'] : 'خطا در ویرایش کاربر مرزنشین'];
    }

    public function resetTraffic(int $inboundId, string $email): array
    {
        $r = $this->mnApi('/api/users/' . rawurlencode(self::safeName($email)) . '/reset', null, 'POST');
        return $r['ok'] ? ['success' => true] : ['success' => false, 'msg' => $r['msg']];
    }

    public function deleteClient(int $inboundId, string $uuid): array
    {
        $user = (string)($this->mapMn[$uuid] ?? '');
        if ($user === '') return ['success' => false, 'msg' => 'کاربر برای حذف پیدا نشد'];
        return $this->deleteByEmail($inboundId, $user);
    }

    public function deleteByEmail(int $inboundId, string $email): array
    {
        $r = $this->mnApi('/api/users/' . rawurlencode(self::safeName($email)), null, 'DELETE');
        if ($r['ok'] || (int)$r['code'] === 404) return ['success' => true];
        return ['success' => false, 'msg' => $r['msg'] !== '' ? $r['msg'] : 'حذف کاربر در مرزنشین ناموفق بود'];
    }

    public function setEnabled(string $email, bool $on): array
    {
        $user = self::safeName($email);
        $r    = $this->mnApi('/api/users/' . rawurlencode($user) . ($on ? '/enable' : '/disable'), null, 'POST');
        if ($r['ok']) {
            if (is_array($r['json'])) $this->mnAbsorb((array)$r['json']);
            return ['success' => true, 'obj' => $r['json']];
        }
        return ['success' => false, 'msg' => $r['msg'] !== '' ? $r['msg'] : 'خطا در تغییر وضعیت کاربر مرزنشین'];
    }

    public function revokeSub(string $email): array
    {
        $r = $this->mnApi('/api/users/' . rawurlencode(self::safeName($email)) . '/revoke_sub', null, 'POST');
        if ($r['ok']) {
            if (is_array($r['json'])) $this->mnAbsorb((array)$r['json']);
            return ['success' => true, 'obj' => $r['json']];
        }
        return ['success' => false, 'msg' => $r['msg'] !== '' ? $r['msg'] : 'خطا در لغو لینک اشتراک مرزنشین'];
    }

    /* ------------------------------------------------------------------ */
    /*  مصرف و سلامت                                                       */
    /* ------------------------------------------------------------------ */

    public function clientTraffic(string $email): ?array
    {
        $u = $this->accountRow($email);
        if (!$u) return null;
        $used = (int)($u['used_traffic'] ?? 0);
        $exp  = self::tsOf($u['expire_date'] ?? ($u['expire'] ?? 0));
        $on   = array_key_exists('enabled', $u)
            ? (bool)$u['enabled']
            : (array_key_exists('is_active', $u) ? (bool)$u['is_active'] : true);
        return [
            'email'      => (string)($u['username'] ?? $email),
            'up'         => 0,
            'down'       => $used,
            'total'      => (int)($u['data_limit'] ?? 0),
            'expiryTime' => $exp > 0 ? $exp * 1000 : 0,
            'enable'     => $on,
        ];
    }

    public function liveTraffic(string $email): ?array
    {
        return $this->clientTraffic($email);
    }

    public function lastOnline(string $email): int
    {
        $u = $this->accountRow($email);
        return $u ? self::tsOf($u['online_at'] ?? null) : 0;
    }

    public function nodes(): ?array
    {
        $r = $this->mnApi('/api/nodes?page=1&size=100');
        if (!$r['ok']) return null;
        $src = $r['json']['items'] ?? $r['json'];
        $out = [];
        foreach ((array)$src as $n) {
            if (!is_array($n)) continue;
            $st = strtolower(trim((string)($n['status'] ?? '')));
            $out[] = [
                'id'       => (int)($n['id'] ?? 0),
                'name'     => (string)($n['name'] ?? ('node-' . (int)($n['id'] ?? 0))),
                'status'   => $st,
                'ok'       => in_array($st, ['healthy', 'connected', 'active'], true),
                'disabled' => in_array($st, ['disabled', 'none'], true),
                'message'  => (string)($n['message'] ?? ''),
            ];
        }
        return $out;
    }

    public function healthCheck(): array
    {
        if (!$this->login(true)) {
            return ['ok' => false, 'message' => 'ورود به مرزنشین ناموفق بود. آدرس، پورت و یوزر/پسورد ادمین را بررسی کنید.'];
        }
        $inb   = $this->inbounds();
        $extra = '';
        foreach (['/api/system/stats/admins', '/api/system/stats', '/api/system'] as $p) {
            $sys = $this->mnApi($p);
            if (!$sys['ok']) continue;
            $j = (array)$sys['json'];
            $tot = (int)($j['total_user'] ?? ($j['total_users'] ?? ($j['users'] ?? 0)));
            $act = (int)($j['users_active'] ?? ($j['active_users'] ?? 0));
            if ($tot > 0 || $act > 0) {
                $extra = ' • کاربران پنل: ' . fa_num($tot) . ' • فعال: ' . fa_num($act);
            }
            break;
        }
        $extra .= Xui::nodesNote($this->nodes());
        if (!$inb) {
            return ['ok' => true, 'message' => 'اتصال موفق بود اما هیچ Service‌ای در پنل پیدا نشد.' . $extra, 'inbounds' => [], 'inbound_ids' => []];
        }
        return [
            'ok'          => true,
            'message'     => 'اتصال به مرزنشین موفق بود.' . $extra,
            'inbounds'    => $inb,
            'inbound_ids' => array_map(static fn($i) => (int)$i['id'], $inb),
        ];
    }
}
