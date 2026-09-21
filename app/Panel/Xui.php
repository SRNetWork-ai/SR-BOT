<?php
declare(strict_types=1);

/**
 * کلاینت API پنل‌های سنایی (3x-ui) / VPN-UI
 *
 * ساختار آدرس: scheme://host:port[/web_path]
 *  - web_path (پچ یا مسیر وب) اختیاری است؛ اگر خالی باشد نادیده گرفته می‌شود.
 */
class Xui
{
    public array $panel;
    private ?string $cookie = null;
    /** درایور مرزبان؛ اگر نوع پنل marzban باشد همهٔ درخواست‌ها به آن سپرده می‌شود */
    private ?Marzban $mz = null;
    /** درایور نسل جدید سنایی (3x-ui با توکن API)؛ اگر توکن ثبت شده باشد درخواست‌ها به آن سپرده می‌شود */
    private ?Xui3 $x3 = null;
    /** درایور پاسارگارد (PasarGuard)؛ اگر نوع پنل pasarguard باشد همهٔ درخواست‌ها به آن سپرده می‌شود */
    private ?PasarGuard $pg = null;

    /** پنل‌هایی که هم‌اکنون پشتیبانی می‌شوند */
    public const TYPES = [
        'vpn-ui'     => 'VPN-UI (Sir-MmD)',
        'marzban'    => 'مرزبان (Marzban)',
        'sanaei'     => 'سنایی جدید – مولتی‌اینباند (3x-ui)',
        'sanaei-old' => 'سنایی قدیم – تک‌اینباند',
        'pasarguard' => 'پاسارگارد (PasarGuard)',
    ];

    /** پنل‌های در دست توسعه – فعلاً قابل انتخاب نیستند */
    public const SOON = [
        'heimdall' => 'هایمدال (Heimdall)',
        'rebecca'  => 'ربکا (Rebecca)',
    ];

    /** نام‌های قدیمی که به نوع‌های جدید نگاشت می‌شوند */
    public const ALIASES = [
        '3x-ui'      => 'sanaei',
        '3xui'       => 'sanaei',
        'x3-ui'      => 'sanaei',
        'sanaei-new' => 'sanaei',
        'sanaei_new' => 'sanaei',
        'x-ui'       => 'sanaei-old',
        'xui'        => 'sanaei-old',
        'sanaei_old' => 'sanaei-old',
        'sanaeiold'  => 'sanaei-old',
        'vpnui'      => 'vpn-ui',
        'vpn_ui'     => 'vpn-ui',
        'pasargad'   => 'pasarguard',
        'pasar-guard'=> 'pasarguard',
        'pasar_guard'=> 'pasarguard',
        'pasargard'  => 'pasarguard',
        'pg'         => 'pasarguard',
    ];

    /** نوع‌هایی که کانفیگ فقط روی یک اینباند ساخته می‌شود */
    public const SINGLE_INBOUND = ['sanaei-old'];

    /** توضیح کوتاه هر نوع پنل برای فرم افزودن */
    public const TYPE_NOTES = [
        'vpn-ui'     => 'یک اکانت روی چند اینباند سرو می‌شود و لینک اشتراک خود پنل تحویل داده می‌شود.',
        'marzban'    => 'اینباندها با تگ شناخته می‌شوند؛ خالی گذاشتن کد اینباند = همهٔ اینباندها.',
        'sanaei'     => 'نسخهٔ جدید سنایی (3x-ui) – مولتی‌اینباند: چند کد اینباند بدهید تا کانفیگ روی همهٔ آن‌ها ساخته شود. اگر پنل شما «API Token» دارد (تنظیمات ← امنیت)، آن را هم وارد کنید تا ربات از API نسل جدید استفاده کند. گیت‌هاب: github.com/MHSanaei/3x-ui',
        'sanaei-old' => 'نسخهٔ قدیم سنایی – تک‌اینباند: آدرس پنل و نام کاربری/رمز را می‌دهید و فقط کد «یک» اینباند؛ همهٔ کانفیگ‌ها روی همان اینباند ساخته می‌شوند.',
        'pasarguard' => 'پاسارگارد (PasarGuard) – بازنویسی مرزبان: ورود با یوزر/پسورد ادمین یا «کلید API» (پنل ← API Keys ← pg_key_…). کاربر باید عضو یک «گروه» باشد؛ شناسهٔ گروه‌ها را در فیلد «کد اینباندها» بنویسید یا خالی بگذارید تا همهٔ گروه‌های فعال استفاده شود. لینک اشتراک را خود پنل می‌دهد. مستندات: docs.pasarguard.org',
    ];

    /** تبدیل نام‌های قدیمی به نوع استاندارد */
    public static function normType(?string $t): string
    {
        $t = strtolower(trim((string)$t));
        if ($t === '') return 'vpn-ui';
        return self::ALIASES[$t] ?? $t;
    }

    /** آیا این نوع پنل هم‌اکنون قابل استفاده است؟ */
    public static function typeAllowed(string $t): bool
    {
        return array_key_exists(self::normType($t), self::TYPES);
    }

    /** آیا این نوع پنل فقط روی یک اینباند کانفیگ می‌سازد؟ */
    public static function isSingleType(?string $t): bool
    {
        return in_array(self::normType($t), self::SINGLE_INBOUND, true);
    }

    /** توضیح نوع پنل */
    public static function typeNote(?string $t): string
    {
        return self::TYPE_NOTES[self::normType($t)] ?? '';
    }

    /** نام نمایشی نوع پنل */
    public static function typeLabel(string $t): string
    {
        $n = self::normType($t);
        if (isset(self::TYPES[$n])) return self::TYPES[$n];
        if (isset(self::SOON[$n]))  return self::SOON[$n] . ' – به‌زودی';
        return $t !== '' ? $t : 'سنایی جدید';
    }

    public function __construct(array $panel)
    {
        $this->panel = $panel;
        $type = self::normType((string)($panel['type'] ?? ''));
        if ($type === 'marzban') {
            $this->mz = new Marzban($panel);
        } elseif ($type === 'pasarguard') {
            $this->pg = new PasarGuard($panel);
        } elseif ($type === 'sanaei' && Xui3::hasToken($panel)) {
            /* توکن API ثبت شده ← درایور نسل جدید 3x-ui خودکار فعال می‌شود؛
               بدون توکن، همان مسیر قدیمی لاگین/کوکی استفاده می‌شود (fallback امن) */
            $this->x3 = new Xui3($panel);
        }
    }

    /** آیا این پنل از نوع مرزبان است؟ */
    public function isMarzban(): bool { return $this->mz !== null; }

    /** دسترسی مستقیم به درایور مرزبان */
    public function marzban(): ?Marzban { return $this->mz; }

    /** آیا این پنل از نوع پاسارگارد است؟ */
    public function isPasarGuard(): bool { return $this->pg !== null; }

    /** دسترسی مستقیم به درایور پاسارگارد */
    public function pasarguard(): ?PasarGuard { return $this->pg; }

    /** آیا این پنل از خانوادهٔ مرزبان است (مرزبان یا پاسارگارد)؟ نام کاربری محدود و اینباند خودکار */
    public function isMarzbanLike(): bool { return $this->mz !== null || $this->pg !== null; }

    /** آیا این پنل از درایور نسل جدید (توکن API) استفاده می‌کند؟ */
    public function isXui3(): bool { return $this->x3 !== null; }

    /** دسترسی مستقیم به درایور نسل جدید */
    public function xui3(): ?Xui3 { return $this->x3; }

    public static function forPanel($panelId): ?self
    {
        $p = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)$panelId]);
        return $p ? new self($p) : null;
    }

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

    /** آدرسی که در کانفیگ کاربر قرار می‌گیرد */
    public function nodeHost(): string
    {
        $n = trim((string)($this->panel['node_host'] ?? ''));
        return $n !== '' ? $n : trim((string)$this->panel['host']);
    }

    /* ------------------------------------------------------------------ */

    private function curl(string $url, $body = null, string $method = 'GET', bool $formEncoded = true): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($this->cookie) $headers[] = 'Cookie: ' . $this->cookie;
        // بررسی گواهی SSL برای هر پنل جداگانه قابل تنظیم است
        // (پنل‌های دارای دامنه و گواهی معتبر را حتماً روشن کنید)
        $verify = (int)($this->panel['ssl_verify'] ?? 0) === 1;
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : ($formEncoded ? http_build_query($body) : jenc($body));
                $headers[] = $formEncoded ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json';
            } else {
                $opts[CURLOPT_POSTFIELDS] = '';
            }
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err  = curl_error($ch);
        curl_close($ch);
        $raw     = is_string($raw) ? $raw : '';
        $headStr = substr($raw, 0, $hlen);
        $bodyStr = substr($raw, $hlen);
        return ['code' => $code, 'headers' => $headStr, 'body' => $bodyStr, 'json' => jdec($bodyStr, []), 'error' => $err];
    }

    public function login(bool $force = false): bool
    {
        if ($this->mz) return $this->mz->login($force);
        if ($this->pg) return $this->pg->login($force);
        if ($this->x3) return $this->x3->login($force);
        if (!$force) {
            $sess = (string)($this->panel['session'] ?? '');
            $at   = (string)($this->panel['session_at'] ?? '');
            if ($sess !== '' && $at !== '' && (time() - strtotime($at)) < 3000) {
                $this->cookie = $sess;
                return true;
            }
        }
        $this->cookie = null;
        $res = $this->curl($this->base() . '/login', [
            'username' => (string)$this->panel['username'],
            'password' => app_decrypt((string)$this->panel['password']),
        ], 'POST');

        $ok = ($res['json']['success'] ?? false) === true;
        if (!$ok && $res['code'] === 200 && stripos($res['body'], 'success') !== false) $ok = true;

        if (preg_match_all('/set-cookie:\\s*([^;]+);/i', $res['headers'], $m)) {
            $this->cookie = implode('; ', array_map('trim', $m[1]));
        }
        if ($ok && $this->cookie) {
            DB::update('panels', ['session' => $this->cookie, 'session_at' => now(), 'last_error' => null], 'id = :id', [':id' => (int)$this->panel['id']]);
            $this->panel['session'] = $this->cookie;
            return true;
        }
        $msg = $res['error'] ?: ($res['json']['msg'] ?? ('HTTP ' . $res['code']));
        DB::update('panels', ['last_error' => mb_substr('ورود ناموفق: ' . $msg, 0, 240)], 'id = :id', [':id' => (int)$this->panel['id']]);
        app_log('panel', 'login failed', ['panel' => $this->panel['name'], 'msg' => $msg]);
        return false;
    }

    /** مسیرهای API متفاوت بین 3x-ui و x-ui قدیمی */
    private function apiPaths(string $key, array $args = []): array
    {
        $id    = $args['id'] ?? 0;
        $uuid  = $args['uuid'] ?? '';
        $email = rawurlencode((string)($args['email'] ?? ''));
        $map = [
            'list'    => ['/panel/api/inbounds/list', '/xui/API/inbounds/list', '/xui/inbound/list'],
            'get'     => ["/panel/api/inbounds/get/$id", "/xui/API/inbounds/get/$id"],
            'add'     => ['/panel/api/inbounds/addClient', '/xui/API/inbounds/addClient'],
            'update'  => ["/panel/api/inbounds/updateClient/$uuid", "/xui/API/inbounds/updateClient/$uuid"],
            'del'     => ["/panel/api/inbounds/$id/delClient/$uuid", "/xui/API/inbounds/$id/delClient/$uuid"],
            'reset'   => ["/panel/api/inbounds/$id/resetClientTraffic/$email", "/xui/API/inbounds/$id/resetClientTraffic/$email"],
            'traffic' => ["/panel/api/inbounds/getClientTraffics/$email", "/xui/API/inbounds/getClientTraffics/$email"],
        ];
        return $map[$key] ?? [];
    }

    private function call(string $key, array $args = [], $body = null, string $method = 'GET'): array
    {
        if (!$this->login()) return ['success' => false, 'msg' => 'اتصال به پنل ناموفق (ورود)'];
        $last = ['success' => false, 'msg' => 'پاسخ نامعتبر از پنل'];
        foreach ($this->apiPaths($key, $args) as $i => $path) {
            $res = $this->curl($this->base() . $path, $body, $method);
            if ($res['code'] === 401 || $res['code'] === 302) {
                if ($this->login(true)) $res = $this->curl($this->base() . $path, $body, $method);
            }
            if (is_array($res['json']) && array_key_exists('success', $res['json'])) return $res['json'];
            $last = ['success' => false, 'msg' => $res['error'] ?: ('HTTP ' . $res['code'])];
        }
        return $last;
    }

    /* ---------------------- اینباوندها ---------------------- */

    public function inbounds(): array
    {
        if ($this->mz) return $this->mz->inbounds();
        if ($this->pg) return $this->pg->inbounds();
        if ($this->x3) return $this->x3->inbounds();
        $r = $this->call('list');
        return ($r['success'] ?? false) ? (array)($r['obj'] ?? []) : [];
    }

    /* 0.0.2 #x3-slim: فهرست سبک اینباندها و تست سریع اتصال */
    public function inboundsSlim(): array
    {
        if ($this->x3) return $this->x3->inboundsSlim();
        $out = [];
        foreach ($this->inbounds() as $in) {
            $out[] = [
                'id'       => (int)($in['id'] ?? 0),
                'remark'   => (string)($in['remark'] ?? ''),
                'protocol' => (string)($in['protocol'] ?? ''),
                'port'     => (int)($in['port'] ?? 0),
                'enable'   => !isset($in['enable']) || !empty($in['enable']),
                'clients'  => 0,
            ];
        }
        return $out;
    }

    /** تست سریع زنده‌بودن پنل بدون خواندن کل اینباندها */
    public function pingFast(): bool
    {
        if ($this->x3) return $this->x3->ping();
        return $this->inbounds() !== [];
    }

    public function inbound(int $id): ?array
    {
        if ($this->mz) return $this->mz->inbound($id);
        if ($this->pg) return $this->pg->inbound($id);
        if ($this->x3) return $this->x3->inbound($id);
        $r = $this->call('get', ['id' => $id]);
        if (($r['success'] ?? false) && !empty($r['obj'])) return (array)$r['obj'];
        foreach ($this->inbounds() as $in) if ((int)$in['id'] === $id) return $in;
        return null;
    }

    /** اینباوندهای مجاز این پنل (کدهای واردشده در تنظیمات) */
    public function allowedInboundIds(): array
    {
        if ($this->mz) return $this->mz->allowedInboundIds();
        if ($this->pg) return $this->pg->allowedInboundIds();
        if ($this->x3) return $this->x3->allowedInboundIds();
        $raw = trim((string)($this->panel['inbound_ids'] ?? ''));
        if ($raw === '') return $this->isVpnUi() ? $this->assignableInboundIds() : [];
        if ($this->singleInbound()) {
            $one = self::idList(preg_split('/[^0-9]+/', en_num($raw)) ?: []);
            return $one ? [$one[0]] : [];
        }
        $ids = array_filter(array_map('intval', preg_split('/[\\s,;]+/', en_num($raw)) ?: []));
        return array_values(array_unique($ids));
    }

    /* ---------------------- کلاینت‌ها ---------------------- */

    /**
     * کلیدهای محدودیت سرعت
     * پنل‌های مختلف (x-ui / 3x-ui / vpn-ui و فورک‌ها) نام‌های متفاوتی برای
     * محدودیت سرعت دارند. همهٔ نام‌های شناخته‌شده را می‌فرستیم؛
     * کلیدهای ناشناخته توسط پنل نادیده گرفته می‌شوند.
     * واحد ورودی: کیلوبیت بر ثانیه (Kbps)
     */
    public static function speedKeys(int $upKbps, int $downKbps): array
    {
        $out = [];
        if ($upKbps > 0) {
            $mb = (int)max(1, (int)round($upKbps / 1000));
            $out['uploadLimit']   = $upKbps;
            $out['upLimit']       = $upKbps;
            $out['limitUp']       = $upKbps;
            $out['speedLimitUp']  = $upKbps;
            $out['upSpeed']       = $upKbps;
            $out['maxUpload']     = $upKbps;
            $out['upMbps']        = $mb;
        }
        if ($downKbps > 0) {
            $mb = (int)max(1, (int)round($downKbps / 1000));
            $out['downloadLimit']  = $downKbps;
            $out['downLimit']      = $downKbps;
            $out['limitDown']      = $downKbps;
            $out['speedLimitDown'] = $downKbps;
            $out['downSpeed']      = $downKbps;
            $out['maxDownload']    = $downKbps;
            $out['downMbps']       = $mb;
        }
        return $out;
    }
    /* ---------- هم‌سان‌سازی کلاینت پیش از ارسال به پنل ---------- */

    /** این مقدار شناسهٔ واقعی کلاینت (UUID/پسورد) است یا شمارهٔ ردیف پنل؟ */
    public static function isClientKey($v): bool
    {
        if (is_array($v) || is_bool($v) || $v === null) return false;
        $v = trim((string)$v);
        if ($v === '') return false;
        return !ctype_digit($v);
    }

    /** کلید کلاینت برای مسیر API پنل — اول uuid، بعد id، بعد password */
    public static function clientKey(array $client, string $fallback = ''): string
    {
        foreach (['uuid', 'id', 'password'] as $k) {
            if (self::isClientKey($client[$k] ?? null)) return trim((string)$client[$k]);
        }
        return trim($fallback);
    }

    /**
     * هم‌سان‌سازی نوع فیلدهای کلاینت.
     * پنل‌های Go برای id رشته می‌خواهند؛ اگر شمارهٔ ردیف پنل
     * به‌جای UUID ارسال شود خطای
     * «json: cannot unmarshal number into Go struct field .id of type string» می‌دهند.
     * حجم/انقضا هم باید عدد صحیح باشند، نه اعشاری یا رشته.
     */
    public static function normClient(array $c, string $key = ''): array
    {
        if (isset($c['id']) && !self::isClientKey($c['id'])) unset($c['id']);
        if (!isset($c['id']) && !isset($c['password']) && self::isClientKey($key)) $c['id'] = trim($key);
        foreach (['id', 'email', 'password', 'method', 'flow', 'security', 'subId', 'comment'] as $k) {
            if (isset($c[$k]) && !is_array($c[$k]) && !is_bool($c[$k])) $c[$k] = (string)$c[$k];
        }
        foreach (['totalGB', 'expiryTime', 'limitIp', 'reset', 'deviceLimit'] as $k) {
            if (isset($c[$k]) && !is_array($c[$k])) $c[$k] = (int)$c[$k];
        }
        if (isset($c['enable'])) $c['enable'] = (bool)$c['enable'];
        return $c;
    }

    /* 0.0.2 #x3-reset: تعیین دورهٔ ریست خودکار حجم پیش از ساخت اکانت — فقط پنل نسل جدید سنایی */
    public function setAutoReset(int $days): void
    {
        if ($this->x3) $this->x3->autoReset = max(0, $days);
    }

    /**
     * افزودن کلاینت جدید
     * @param float $volumeGb ۰ = نامحدود
     * @param int   $expiryMs زمان انقضا بر حسب میلی‌سانیه (۰ = نامحدود)
     */
    public function addClient(int $inboundId, string $email, string $uuid, float $volumeGb, int $expiryMs, int $ipLimit = 0, string $subId = '', array $inboundIds = [], int $deviceLimit = 0, int $upKbps = 0, int $downKbps = 0): array
    {
        if ($this->mz) return $this->mz->addClient($inboundId, $email, $uuid, $volumeGb, $expiryMs, $ipLimit, $subId, $inboundIds, $deviceLimit, $upKbps, $downKbps);
        if ($this->pg) return $this->pg->addClient($inboundId, $email, $uuid, $volumeGb, $expiryMs, $ipLimit, $subId, $inboundIds, $deviceLimit, $upKbps, $downKbps);
        if ($this->x3) return $this->x3->addClient($inboundId, $email, $uuid, $volumeGb, $expiryMs, $ipLimit, $subId, $inboundIds, $deviceLimit, $upKbps, $downKbps);
        $inbound = $this->inbound($inboundId);
        $proto   = strtolower((string)($inbound['protocol'] ?? 'vless'));
        $client  = [
            'email'      => $email,
            'enable'     => true,
            'totalGB'    => $volumeGb > 0 ? gb2bytes($volumeGb) : 0,
            'expiryTime' => $expiryMs,
            'limitIp'    => $ipLimit,
            'tgId'       => '',
            'subId'      => $subId !== '' ? $subId : rnd(16),
            'reset'      => 0,
        ];
        if ($proto === 'trojan') {
            $client['password'] = $uuid;
        } elseif ($proto === 'shadowsocks') {
            $client['password'] = $uuid;
            $client['method']   = 'chacha20-ietf-poly1305';
        } else {
            $client['id']   = $uuid;
            $client['flow'] = '';
        }
        if ($deviceLimit > 0) $client['deviceLimit'] = $deviceLimit;
        /* محدودیت سرعت بر حسب کیلوبایت بر ثانیه؛ کلیدهای ناشناخته توسط پنل نادیده گرفته می‌شوند */
        foreach (self::speedKeys($upKbps, $downKbps) as $sk => $sv) $client[$sk] = $sv;
        $client = self::normClient($client, $uuid);
        $body = ['id' => $inboundId, 'settings' => jenc(['clients' => [$client]])];

        if ($this->isVpnUi()) {
            /* VPN-UI: یک اکانت روی چند اینباند با کلید تکراری inboundIds */
            $ids = self::idList($inboundIds);
            if (!$ids) $ids = [$inboundId];
            $res = $this->call('add', [], self::formBody($body, $ids), 'POST');
        } else {
            $res = $this->call('add', [], $body, 'POST');
        }

        if (($res['success'] ?? false) !== true) {
            return ['success' => false, 'msg' => (string)($res['msg'] ?? 'خطا در ساخت کاربر در پنل')];
        }
        return ['success' => true, 'client' => $client, 'inbound' => $inbound];
    }

    public function updateClient(int $inboundId, string $uuid, array $client, array $inboundIds = []): array
    {
        if ($this->mz) return $this->mz->updateClient($inboundId, $uuid, $client, $inboundIds);
        if ($this->pg) return $this->pg->updateClient($inboundId, $uuid, $client, $inboundIds);
        if ($this->x3) return $this->x3->updateClient($inboundId, $uuid, $client, $inboundIds);
        $client = self::normClient($client, $uuid);
        $body = ['id' => $inboundId, 'settings' => jenc(['clients' => [$client]])];
        $ids  = self::idList($inboundIds);
        if ($this->isVpnUi() && $ids) {
            return $this->call('update', ['uuid' => self::clientKey($client, $uuid)], self::formBody($body, $ids), 'POST');
        }
        return $this->call('update', ['uuid' => self::clientKey($client, $uuid)], $body, 'POST');
    }

    public function deleteClient(int $inboundId, string $uuid): array
    {
        if ($this->mz) return $this->mz->deleteClient($inboundId, $uuid);
        if ($this->pg) return $this->pg->deleteClient($inboundId, $uuid);
        if ($this->x3) return $this->x3->deleteClient($inboundId, $uuid);
        return $this->call('del', ['id' => $inboundId, 'uuid' => $uuid], null, 'POST');
    }

    public function resetTraffic(int $inboundId, string $email): array
    {
        if ($this->mz) return $this->mz->resetTraffic($inboundId, $email);
        if ($this->pg) return $this->pg->resetTraffic($inboundId, $email);
        if ($this->x3) return $this->x3->resetTraffic($inboundId, $email);
        return $this->call('reset', ['id' => $inboundId, 'email' => $email], null, 'POST');
    }

    public function clientTraffic(string $email): ?array
    {
        if ($this->mz) return $this->mz->clientTraffic($email);
        if ($this->pg) return $this->pg->clientTraffic($email);
        if ($this->x3) return $this->x3->clientTraffic($email);
        $r = $this->call('traffic', ['email' => $email]);
        if (($r['success'] ?? false) && !empty($r['obj'])) return (array)$r['obj'];
        if ($this->isVpnUi()) {
            $row = $this->accountRow($email);
            if ($row) {
                return [
                    'email'      => (string)($row['email'] ?? $email),
                    'up'         => (int)($row['up'] ?? 0),
                    'down'       => (int)($row['down'] ?? 0),
                    'total'      => (int)($row['total'] ?? 0),
                    'expiryTime' => (int)($row['expiryTime'] ?? ($row['expiry_time'] ?? 0)),
                    'enable'     => (bool)($row['enable'] ?? true),
                ];
            }
        }
        return null;
    }

    /* ---------------- مصرف زنده و اسکن ساب اصلی پنل ---------------- */

    /**
     * مصرف زنده اکانت — مقاوم در برابر تفاوت پاسخ پنل ها
     *
     * برخی پنل ها برای هر اینباند یک ردیف جدا برمی گردانند؛ در آن حالت مجموع
     * up/down همه ردیف ها حساب می شود تا مصرف واقعی روی صفر نماند. اگر API
     * ترافیک جواب نداد، ردیف صفحه Clients خود پنل خوانده می شود.
     */
    public function liveTraffic(string $email): ?array
    {
        if ($this->mz) return $this->mz->liveTraffic($email);
        if ($this->pg) return $this->pg->liveTraffic($email);
        if ($this->x3) return $this->x3->liveTraffic($email);
        $email = trim($email);
        if ($email === '') return null;

        $up = 0; $down = 0; $total = 0; $expiry = 0; $enable = null; $found = false;

        $absorb = static function ($row) use (&$up, &$down, &$total, &$expiry, &$enable, &$found) {
            if (!is_array($row)) return;
            $hasT = isset($row['up']) || isset($row['down']) || isset($row['total'])
                || isset($row['upload']) || isset($row['download']);
            if (!$hasT && !isset($row['expiryTime']) && !isset($row['expiry_time'])) return;
            $found = true;
            $up   += (int)($row['up']   ?? ($row['upload']   ?? 0));
            $down += (int)($row['down'] ?? ($row['download'] ?? 0));
            $t = (int)($row['total'] ?? 0);
            if ($t > $total) $total = $t;
            $e = (int)($row['expiryTime'] ?? ($row['expiry_time'] ?? 0));
            if ($e > 0 && ($expiry === 0 || $e < $expiry)) $expiry = $e;
            if (array_key_exists('enable', $row)) {
                $en     = !empty($row['enable']);
                $enable = $enable === null ? $en : ($enable || $en);
            }
        };

        try {
            $r = $this->call('traffic', ['email' => $email]);
            if (($r['success'] ?? false) && !empty($r['obj'])) {
                $obj = $r['obj'];
                if (is_array($obj) && $obj !== [] && array_keys($obj) === range(0, count($obj) - 1)) {
                    foreach ($obj as $one) $absorb($one);
                } else {
                    $absorb((array)$obj);
                }
            }
        } catch (Throwable $e) { }

        /* پنل VPN-UI: ردیف صفحه Clients دقیق ترین مقدار تجمیعی است */
        if (!$found || ($up + $down) === 0) {
            try {
                $row = $this->accountRow($email);
                if ($row) $absorb($row);
            } catch (Throwable $e) { }
        }

        if (!$found) return null;

        return [
            'email'      => $email,
            'up'         => $up,
            'down'       => $down,
            'total'      => $total,
            'expiryTime' => $expiry,
            'enable'     => $enable === null ? true : $enable,
        ];
    }

    /** دریافت یک آدرس عمومی (ساب پنل) بدون نیاز به نشست مدیریتی */
    public static function httpGet(string $url, int $timeout = 12): string
    {
        if (!function_exists('curl_init')) return '';
        if (!preg_match('~^https?://~i', $url)) return '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(4, $timeout),
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            /* بدنهٔ ساب مستقیم به کاربر تحویل می‌شود؛ بررسی گواهی طبق تنظیم سراسری app.ssl_verify
               (در صورت استفاده از پنل با گواهی self-signed، ssl_verify را در config.php خاموش کنید) */
            CURLOPT_SSL_VERIFYPEER => app_ssl_verify(),
            CURLOPT_SSL_VERIFYHOST => app_ssl_verify() ? 2 : 0,
            CURLOPT_USERAGENT      => 'v2rayNG/1.8.6',
            CURLOPT_HTTPHEADER     => ['Accept: */*'],
        ]);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 400) ? $body : '';
    }

    /** الگوی کانفیگ های معتبر */
    public const LINK_RE = '~^(vless|vmess|trojan|ss|ssr|hy2|hysteria2?|tuic|wireguard|socks)://\S+~i';

    /**
     * استخراج کانفیگ های سالم از بدنه یک ساب
     * هم خروجی base64 و هم متن ساده پشتیبانی می شود.
     */
    public static function parseSubBody(string $body): array
    {
        $body = trim($body);
        if ($body === '') return [];

        /* اگر متن خام کانفیگ ندارد، احتمالا base64 است */
        if (!preg_match('~(vless|vmess|trojan|ss|hy2|hysteria2?|tuic)://~i', $body)) {
            $clean = (string)preg_replace('/\s+/', '', $body);
            $pad   = strlen($clean) % 4;
            if ($pad > 0) $clean .= str_repeat('=', 4 - $pad);
            $dec = base64_decode(strtr($clean, '-_', '+/'), false);
            if (is_string($dec) && preg_match('~(vless|vmess|trojan|ss|hy2|hysteria2?|tuic)://~i', $dec)) {
                $body = $dec;
            }
        }

        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (!preg_match(self::LINK_RE, $line)) continue;
            if (mb_strlen($line) < 20) continue;
            $out[] = $line;
        }
        return array_values(array_unique($out));
    }

    /**
     * اسکن ساب اصلی خود پنل و برگرداندن کانفیگ های سالم
     * $subUrl خالی باشد از sub_base پنل ساخته می شود.
     */
    public function subFetch(string $subId, string $subUrl = ''): array
    {
        if ($this->mz) return $this->mz->subFetch($subId, $subUrl);
        if ($this->pg) return $this->pg->subFetch($subId, $subUrl);
        if ($this->x3) return $this->x3->subFetch($subId, $subUrl);
        $url = trim($subUrl) !== '' ? trim($subUrl) : $this->subLink(trim($subId));
        if ($url === '') return [];
        $links = self::parseSubBody(self::httpGet($url));
        if ($links) return $links;
        /* برخی پنل ها فقط با پسوند صریح base64 جواب می دهند */
        $alt = rtrim($url, '/') . '?b64=1';
        return self::parseSubBody(self::httpGet($alt));
    }

    /** بازسازی کانفیگ ها از روی اینباندهای واقعی همین اکانت */
    public function rebuildConfigs(string $email, string $uuid, array $extraInbounds = []): array
    {
        if ($this->mz) return $this->mz->rebuildConfigs($email, $uuid, $extraInbounds);
        if ($this->pg) return $this->pg->rebuildConfigs($email, $uuid, $extraInbounds);
        if ($this->x3) return $this->x3->rebuildConfigs($email, $uuid, $extraInbounds);
        $ids = [];
        try { $ids = $this->accountInboundIds($email); } catch (Throwable $e) { }
        $ids = self::idList(array_merge($ids, $extraInbounds));
        if (!$ids) {
            try { $ids = $this->allowedInboundIds(); } catch (Throwable $e) { $ids = []; }
        }

        $out = [];
        foreach ($ids as $ib) {
            $ib = (int)$ib;
            if ($ib <= 0) continue;
            try {
                $inb = $this->inbound($ib);
                if (!$inb) continue;
                $key = $uuid;
                $cl  = $this->findClient($ib, $email);
                if ($cl) $key = self::clientKey($cl, $uuid);
                $l = trim((string)$this->buildConfigLink($inb, (string)$key, $email));
                if ($l !== '' && preg_match(self::LINK_RE, $l)) $out[] = $l;
            } catch (Throwable $e) {
                app_log('panel', 'rebuildConfigs: ' . $e->getMessage(), ['inbound' => $ib]);
            }
        }
        return array_values(array_unique($out));
    }

    /** خواندن کلاینت از درون تنظیمات اینباوند */
    public function findClient(int $inboundId, string $email): ?array
    {
        if ($this->mz) return $this->mz->findClient($inboundId, $email);
        if ($this->pg) return $this->pg->findClient($inboundId, $email);
        if ($this->x3) return $this->x3->findClient($inboundId, $email);
        $inbound = $this->inbound($inboundId);
        if (!$inbound) return null;
        $settings = jdec((string)($inbound['settings'] ?? ''), []);
        foreach ((array)($settings['clients'] ?? []) as $c) {
            if (strcasecmp((string)($c['email'] ?? ''), $email) === 0) return $c;
        }
        return null;
    }

    /* ---------------------- لینک‌ها ---------------------- */

    /* ------------------- VPN-UI (Sir-MmD/vpn-ui) ------------------- */

    /** پنل از نوع VPN-UI است؟ در این پنل یک اکانت روی چند اینباند سرو می‌شود */
    public function isVpnUi(): bool
    {
        $t = strtolower(trim((string)($this->panel['type'] ?? '')));
        return in_array($t, ['vpn-ui', 'vpnui', 'vpn_ui'], true);
    }

    /** پاک‌سازی لیست کد اینباندها */
    /** نوع استانداردشدهٔ این پنل */
    public function type(): string { return self::normType((string)($this->panel['type'] ?? '')); }

    /** سنایی (هر دو نسخه)؟ */
    public function isSanaei(): bool { return in_array($this->type(), ['sanaei', 'sanaei-old'], true); }

    /** سنایی نسخهٔ قدیم (تک‌اینباند)؟ */
    public function isSanaeiOld(): bool { return $this->type() === 'sanaei-old'; }

    /** این پنل فقط یک اینباند می‌پذیرد؟ */
    public function singleInbound(): bool { return self::isSingleType($this->type()); }

    /** محدودسازی لیست اینباند بر اساس نوع پنل */
    public function limitIds(array $ids): array
    {
        $ids = self::idList($ids);
        /* مرزبان/پاسارگارد: کاربر یک‌بار ساخته می‌شود و خود پنل روی همهٔ اینباندها سرو می‌کند؛ چند هدف = ساخت تکراری */
        if ($this->mz !== null || $this->pg !== null) return array_slice($ids, 0, 1);
        return $this->singleInbound() ? array_slice($ids, 0, 1) : $ids;
    }

    public static function idList($raw): array
    {
        $out = [];
        foreach ((array)$raw as $v) { $v = (int)$v; if ($v > 0) $out[] = $v; }
        return array_values(array_unique($out));
    }

    /**
     * بدنه فرم با کلید تکراری inboundIds
     * پنل آرایه را به شکل inboundIds=1&inboundIds=2 می‌خواند، نه inboundIds[]=1
     */
    public static function formBody(array $pairs, array $inboundIds = []): string
    {
        $parts = [];
        foreach ($pairs as $k => $v) {
            if (is_bool($v)) $v = $v ? 'true' : 'false';
            $parts[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
        }
        foreach (self::idList($inboundIds) as $id) $parts[] = 'inboundIds=' . $id;
        return implode('&', $parts);
    }

    /** درخواست خام روی API پنل (برای مسیرهای دارای query string) */
    private function apiRaw(string $path, ?string $post = null): array
    {
        if (!$this->login()) return [];
        $method = $post === null ? 'GET' : 'POST';
        $res = $this->curl($this->base() . $path, $post, $method);
        if ((int)$res['code'] === 401 || (int)$res['code'] === 302) {
            if ($this->login(true)) $res = $this->curl($this->base() . $path, $post, $method);
        }
        return is_array($res['json'] ?? null) ? (array)$res['json'] : [];
    }

    /** اینباندهایی که این کاربر پنل اجازه اختصاص‌شان را دارد */
    public function assignableInboundIds(): array
    {
        if ($this->mz) return $this->mz->assignableInboundIds();
        if ($this->pg) return $this->pg->assignableInboundIds();
        if ($this->x3) return $this->x3->assignableInboundIds();
        $r    = $this->apiRaw('/panel/api/clients/assignable');
        $rows = (array)($r['obj'] ?? []);
        $ids  = [];
        foreach ($rows as $row) {
            if (is_array($row))        $ids[] = (int)($row['id'] ?? ($row['inboundId'] ?? 0));
            elseif (is_numeric($row))  $ids[] = (int)$row;
        }
        return self::idList($ids);
    }

    /** ردیف اکانت از صفحه Clients پنل (مصرف + اینباندها) */
    public function accountRow(string $email): ?array
    {
        if ($this->mz) return $this->mz->accountRow($email);
        if ($this->pg) return $this->pg->accountRow($email);
        if ($this->x3) return $this->x3->accountRow($email);
        $r    = $this->apiRaw('/panel/api/clients/list?page=1&size=50&search=' . rawurlencode($email));
        $obj  = (array)($r['obj'] ?? []);
        $rows = (array)($obj['rows'] ?? ($obj['data'] ?? $obj));
        foreach ($rows as $row) {
            if (is_array($row) && strcasecmp((string)($row['email'] ?? ''), $email) === 0) return $row;
        }
        return null;
    }

    /** اینباندهای فعلی یک اکانت */
    public function accountInboundIds(string $email): array
    {
        if ($this->mz) return $this->mz->accountInboundIds($email);
        if ($this->pg) return $this->pg->accountInboundIds($email);
        if ($this->x3) return $this->x3->accountInboundIds($email);
        $row = $this->accountRow($email);
        if (!$row) return [];
        $ids = [];
        foreach ((array)($row['memberships'] ?? ($row['inboundIds'] ?? [])) as $m) {
            if (is_array($m))       $ids[] = (int)($m['inboundId'] ?? ($m['id'] ?? 0));
            elseif (is_numeric($m)) $ids[] = (int)$m;
        }
        return self::idList($ids);
    }

    /** حذف کامل اکانت با ایمیل؛ اگر روی هیچ اینباندی نبود از مسیر اکانت */
    public function deleteByEmail(int $inboundId, string $email): array
    {
        if ($this->mz) return $this->mz->deleteByEmail($inboundId, $email);
        if ($this->pg) return $this->pg->deleteByEmail($inboundId, $email);
        if ($this->x3) return $this->x3->deleteByEmail($inboundId, $email);
        $r = $this->apiRaw('/panel/api/inbounds/delClientByEmail', self::formBody(['id' => $inboundId, 'email' => $email]));
        if (($r['success'] ?? false) === true) return $r;
        $r2 = $this->apiRaw('/panel/api/inbounds/delAccount/' . rawurlencode($email), '');
        return ($r2 && isset($r2['success'])) ? $r2 : ['success' => false, 'msg' => 'حذف اکانت در پنل ناموفق بود'];
    }

    /** لینک اشتراک واقعی پنل برای یک نا�� کاربری (فعلاً مرزبان) */
    public function subUrlFor(string $email): string
    {
        if ($this->mz) return $this->mz->subUrlFor($email);
        if ($this->pg) return $this->pg->subUrlFor($email);
        if ($this->x3) return $this->x3->subUrlFor($email);
        return '';
    }

    public function subLink(string $subId): string
    {
        if ($this->mz) return $this->mz->subLink($subId);
        if ($this->pg) return $this->pg->subLink($subId);
        if ($this->x3) return $this->x3->subLink($subId);
        $base = trim((string)($this->panel['sub_base'] ?? ''));
        if ($base === '' || $subId === '') return '';
        return rtrim($base, '/') . '/' . $subId;
    }

    /** ساخت لینک کانفیگ از روی اینباوند */
    public function buildConfigLink(array $inbound, string $uuid, string $email): string
    {
        if ($this->mz) return $this->mz->buildConfigLink($inbound, $uuid, $email);
        if ($this->pg) return $this->pg->buildConfigLink($inbound, $uuid, $email);
        if ($this->x3) return $this->x3->buildConfigLink($inbound, $uuid, $email);
        $proto  = strtolower((string)($inbound['protocol'] ?? 'vless'));
        $port   = (int)($inbound['port'] ?? 443);
        $host   = $this->nodeHost();
        $remark = (string)($inbound['remark'] ?? 'config');

        /* نام نمایشی کانفیگ: عنوان فروشگاه (+ نام پنل) نه ریمارک خام اینباند */
        $tag = trim((string)DB::setting('sub_tag', ''));
        if ($tag === '') $tag = trim((string)DB::setting('shop_title', ''));
        if ($tag !== '' && (string)DB::setting('sub_name_panel', '1') === '1') {
            $pn = trim((string)($this->panel['name'] ?? ''));
            if ($pn !== '') $tag .= ' | ' . $pn;
        }
        if ($tag === '') $tag = $remark . '-' . $email;
        /* نام کانفیگ هم به نام اضافه شود تا کاربر بداند کدام سرویس است */
        if ((string)DB::setting('sub_name_client', '1') === '1' && trim($email) !== '') {
            $tag = trim($tag) !== '' ? $tag . ' | ' . trim($email) : trim($email);
        }
        $tag = mb_substr(trim($tag), 0, 60);
        $ss     = jdec((string)($inbound['streamSettings'] ?? ''), []);
        $net    = (string)($ss['network'] ?? 'tcp');
        $sec    = (string)($ss['security'] ?? 'none');

        /* اگر اینباند آدرس بیرونی جداگانه دارد (External Proxy) همان را بگیر */
        $ep = $ss['externalProxy'] ?? null;
        if (is_array($ep) && isset($ep[0]) && is_array($ep[0])) {
            $epHost = trim((string)($ep[0]['dest'] ?? ''));
            $epPort = (int)($ep[0]['port'] ?? 0);
            if ($epHost !== '') $host = $epHost;
            if ($epPort > 0)    $port = $epPort;
        }

        $q = ['type' => $net, 'security' => $sec];
        if ($net === 'ws') {
            $q['path'] = (string)($ss['wsSettings']['path'] ?? '/');
            $hh = (string)($ss['wsSettings']['headers']['Host'] ?? ($ss['wsSettings']['host'] ?? ''));
            if ($hh !== '') $q['host'] = $hh;
        } elseif ($net === 'grpc') {
            $q['serviceName'] = (string)($ss['grpcSettings']['serviceName'] ?? '');
        } elseif ($net === 'httpupgrade') {
            $q['path'] = (string)($ss['httpupgradeSettings']['path'] ?? '/');
            $hh = (string)($ss['httpupgradeSettings']['host'] ?? '');
            if ($hh !== '') $q['host'] = $hh;
        } elseif ($net === 'tcp') {
            $type = (string)($ss['tcpSettings']['header']['type'] ?? 'none');
            if ($type === 'http') {
                $q['headerType'] = 'http';
                $q['path'] = (string)($ss['tcpSettings']['header']['request']['path'][0] ?? '/');
                $q['host'] = (string)($ss['tcpSettings']['header']['request']['headers']['Host'][0] ?? '');
            }
        }
        if ($sec === 'tls') {
            $sni = (string)($ss['tlsSettings']['serverName'] ?? '');
            if ($sni !== '') $q['sni'] = $sni;
            $fp = (string)($ss['tlsSettings']['settings']['fingerprint'] ?? 'chrome');
            $q['fp'] = $fp ?: 'chrome';
            $alpn = $ss['tlsSettings']['alpn'] ?? null;
            if (is_array($alpn) && $alpn) $q['alpn'] = implode(',', $alpn);
        } elseif ($sec === 'reality') {
            $r = (array)($ss['realitySettings'] ?? []);
            $sni = (string)($r['serverNames'][0] ?? ($r['dest'] ?? ''));
            if ($sni !== '') $q['sni'] = explode(':', $sni)[0];
            $q['pbk'] = (string)($r['settings']['publicKey'] ?? ($r['publicKey'] ?? ''));
            $sid = (string)($r['shortIds'][0] ?? '');
            if ($sid !== '') $q['sid'] = $sid;
            $q['fp'] = (string)($r['settings']['fingerprint'] ?? 'chrome');
            $spx = (string)($r['settings']['spiderX'] ?? '');
            if ($spx !== '') $q['spx'] = $spx;
        }

        $client = $this->findClient((int)($inbound['id'] ?? 0), $email);
        if ($proto === 'vless') {
            /* بدون encryption=none بعضی کلاینت ها لینک را رد می کنند (خطای EOF) */
            $q = array_merge(['encryption' => 'none'], $q);
            $flow = (string)($client['flow'] ?? '');
            if ($flow !== '') $q['flow'] = $flow;
            return 'vless://' . $uuid . '@' . $host . ':' . $port . '?' . http_build_query($q) . '#' . rawurlencode($tag);
        }
        if ($proto === 'trojan') {
            $pass = (string)($client['password'] ?? $uuid);
            return 'trojan://' . rawurlencode($pass) . '@' . $host . ':' . $port . '?' . http_build_query($q) . '#' . rawurlencode($tag);
        }
        if ($proto === 'vmess') {
            $conf = [
                'v' => '2', 'ps' => $tag, 'add' => $host, 'port' => (string)$port, 'id' => $uuid,
                'aid' => '0', 'scy' => 'auto', 'net' => $net, 'type' => (string)($q['headerType'] ?? 'none'),
                'host' => (string)($q['host'] ?? ''), 'path' => (string)($q['path'] ?? ''),
                'tls' => $sec === 'none' ? '' : $sec, 'sni' => (string)($q['sni'] ?? ''), 'fp' => (string)($q['fp'] ?? ''),
            ];
            return 'vmess://' . base64_encode(jenc($conf));
        }
        if ($proto === 'shadowsocks') {
            $method = (string)($client['method'] ?? 'chacha20-ietf-poly1305');
            $pass   = (string)($client['password'] ?? $uuid);
            return 'ss://' . base64_encode($method . ':' . $pass) . '@' . $host . ':' . $port . '#' . rawurlencode($tag);
        }
        return '';
    }

    /* ---------------------- قابلیت‌های پیشرفته (فاز ۳–۴ سنایی نسل جدید + پاسارگارد) ---------------------- */

    /** کدام قابلیت‌های پیشرفته روی این پنل پشتیبانی می‌شود */
    public function supports(string $feature): bool
    {
        switch ($feature) {
            case 'status':   return $this->x3 !== null || $this->pg !== null;
            case 'online':   return $this->x3 !== null || $this->pg !== null || $this->mz !== null;
            case 'ips':      return $this->x3 !== null;
            case 'depleted': return $this->x3 !== null;
            case 'revoke':   return $this->pg !== null;
            case 'toggle':   return true;
        }
        return false;
    }

    /**
     * وضعیت منابع سرور به شکل یکسان
     * خروجی: cpu (درصد)، mem_used/mem_total (بایت)، uptime (ثانیه)، xray_state، xray_ver، net_up/net_down (بایتبرثانیه)، sent/recv (بایت)، users_online
     */
    public function serverStatus(): ?array
    {
        try {
            if ($this->x3 !== null) return $this->x3->serverStatus();
            if ($this->pg !== null) {
                $s = $this->pg->systemStats(true);
                if ($s === []) return null;
                $memTotal = (int)($s['mem_total'] ?? 0);
                $memUsed  = (int)($s['mem_used'] ?? 0);
                return [
                    'cpu'          => (float)($s['cpu_usage'] ?? 0),
                    'mem_used'     => $memUsed,
                    'mem_total'    => $memTotal,
                    'uptime'       => 0,
                    'xray_state'   => '',
                    'xray_ver'     => (string)($s['version'] ?? ''),
                    'net_up'       => (int)($s['outgoing_bandwidth_speed'] ?? 0),
                    'net_down'     => (int)($s['incoming_bandwidth_speed'] ?? 0),
                    'sent'         => (int)($s['outgoing_bandwidth'] ?? 0),
                    'recv'         => (int)($s['incoming_bandwidth'] ?? 0),
                    'users_total'  => (int)($s['total_user'] ?? 0),
                    'users_active' => (int)($s['active_users'] ?? ($s['users_active'] ?? 0)),
                    'users_online' => (int)($s['online_users'] ?? 0),
                ];
            }
        } catch (Throwable $e) {
            app_log('panel', 'serverStatus: ' . $e->getMessage(), ['panel' => (int)($this->panel['id'] ?? 0)]);
        }
        return null;
    }

    /**
     * fixed75 — کلاینت‌های متصل به یک آیدی تلگرام (فقط 3x-ui از فیلد tgId پشتیبانی می‌کند؛ بقیه خالی برمی‌گردانند).
     * برای ضدسوءاستفادهٔ اکانت تست: اگر کاربر قبلاً روی پنل کلاینت داشته (حتی اگر از ربات پاک شده) تشخیص می‌دهد.
     */
    public function clientsByTgId($tgId): array
    {
        if ($this->x3 === null || (int)$tgId <= 0) return [];
        try {
            $rows = $this->x3->byTgId((int)$tgId);
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** آخرین اتصال کاربر (تایم‌استمپ ثانیه؛ ۰ = نامشخص یا هرگز) */
    public function lastOnlineOf(string $email): int
    {
        try {
            if ($this->x3 !== null) return $this->x3->lastOnlineOf($email);
            if ($this->pg !== null) return $this->pg->lastOnline($email);
            if ($this->mz !== null) {
                $u = $this->mz->accountRow($email);
                $v = $u ? ($u['online_at'] ?? null) : null;
                if ($v === null || $v === '') return 0;
                if (is_numeric($v)) return (int)$v;
                return strtotime((string)$v) ?: 0;
            }
        } catch (Throwable $e) { }
        return 0;
    }

    /** آیا همین الآن آنلاین است؟ (null = پنل از این قابلیت پشتیبانی نمی‌کند) */
    public function isOnline(string $email): ?bool
    {
        try {
            if ($this->x3 !== null) return in_array($email, $this->x3->onlines(), true);
        } catch (Throwable $e) { }
        $ts = $this->lastOnlineOf($email);
        if ($ts <= 0) return ($this->pg !== null || $this->mz !== null) ? false : null;
        return (time() - $ts) < 180;
    }

    /** آی‌پی‌های ثبت‌شدهٔ کلاینت (فقط سنایی نسل جدید) */
    public function clientIps(string $email): array
    {
        if ($this->x3 === null) return [];
        try { return $this->x3->clientIps($email); } catch (Throwable $e) { return []; }
    }

    /** پاک کردن آی‌پی‌های ثبت‌شده (فقط سنایی نسل جدید) */
    public function clearClientIps(string $email): array
    {
        if ($this->x3 === null) return ['success' => false, 'msg' => 'این پنل از مدیریت آی‌پی پشتیبانی نمی‌کند'];
        try { return $this->x3->clearClientIps($email); } catch (Throwable $e) { return ['success' => false, 'msg' => $e->getMessage()]; }
    }

    /** حذف کلاینت‌های تمام‌شدهٔ پنل (فقط سنایی نسل جدید؛ -1 = همهٔ اینباندها) */
    public function delDepleted(int $inboundId = -1): array
    {
        if ($this->x3 === null) return ['success' => false, 'msg' => 'این پنل از حذف گروهی تمام‌شده‌ها پشتیبانی نمی‌کند'];
        try { return $this->x3->delDepleted($inboundId); } catch (Throwable $e) { return ['success' => false, 'msg' => $e->getMessage()]; }
    }

    /** لغو و بازسازی لینک اشتراک (فقط پاسارگارد) */
    public function revokeSub(string $email): array
    {
        if ($this->mz !== null && method_exists($this->mz, 'revokeSub')) { /* fixed79: مرزبان */
            try { return $this->mz->revokeSub($email); } catch (Throwable $e) { return ['success' => false, 'msg' => $e->getMessage()]; }
        }
        if ($this->pg === null) return ['success' => false, 'msg' => 'فقط پنل پاسارگارد از لغو لینک اشتراک پشتیبانی می‌کند'];
        try { return $this->pg->revokeSub($email); } catch (Throwable $e) { return ['success' => false, 'msg' => $e->getMessage()]; }
    }

    /** fixed79: وضعیت نودها (مرزبان/پاسارگارد)؛ null = پشتیبانی یا دسترسی ندارد */
    public function nodes(): ?array
    {
        try {
            if ($this->mz && method_exists($this->mz, 'nodes')) return $this->mz->nodes();
            if ($this->pg && method_exists($this->pg, 'nodes')) return $this->pg->nodes();
        } catch (Throwable $e) { }
        return null;
    }

    /** fixed79: خلاصهٔ وضعیت نودها برای پیام «تست اتصال» */
    public static function nodesNote(?array $nodes): string
    {
        if (!is_array($nodes) || !$nodes) return '';
        $tot = 0; $ok = 0; $down = [];
        foreach ($nodes as $n) {
            if (!empty($n['disabled'])) continue;
            $tot++;
            if (!empty($n['ok'])) $ok++;
            else $down[] = (string)($n['name'] ?? '?') . ' (' . ((string)($n['status'] ?? '') !== '' ? (string)$n['status'] : 'unknown') . ')';
        }
        if ($tot === 0) return '';
        return ' • نودها: ' . fa_num((string)$ok) . '/' . fa_num((string)$tot) . ' متصل'
             . ($down ? ' — قطع: ' . implode('، ', array_slice($down, 0, 5)) : '');
    }

    /** تست سلامت پنل برای پنل مدیریت */
    public function healthCheck(): array
    {
        if ($this->mz) return $this->mz->healthCheck();
        if ($this->pg) return $this->pg->healthCheck();
        if ($this->x3) return $this->x3->healthCheck();
        if (!$this->login(true)) {
            return ['ok' => false, 'message' => 'ورود به پنل ناموفق بود. آدرس/پورت/پچ یا یوزر و پسورد را بررسی کنید.'];
        }
        $inbounds = $this->inbounds();
        $ids = array_map(fn($i) => (int)$i['id'], $inbounds);
        return [
            'ok' => true,
            'message' => 'اتصال موفق بود.',
            'inbounds' => $inbounds,
            'inbound_ids' => $ids,
        ];
    }
}
