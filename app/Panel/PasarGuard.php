<?php
declare(strict_types=1);

/**
 * کلاینت API پنل پاسارگارد (PasarGuard) — https://docs.pasarguard.org
 *
 * پاسارگارد بازنویسی مرزبان است؛ تفاوت‌های مهم که این درایور پوشش می‌دهد:
 *   ۱) ورود با «کلید API» (pg_key_…) یا با یوزر/پسورد ادمین (/api/admin/token)
 *   ۲) ساخت کاربر «بدون گروه» ممکن نیست → group_ids الزامی است.
 *      شناسهٔ گروه‌ها در همان فیلد «کد اینباندها» پنل نوشته می‌شود؛ خالی = همهٔ گروه‌های فعال.
 *   ۳) پروتکل‌ها در proxy_settings تعریف می‌شوند و تاریخ انقضا ممکن است رشتهٔ ISO یا timestamp باشد.
 *   ۴) وضعیت‌ها: active / disabled / expired / limited / on_hold
 *
 * این کلاس همان متدهای Xui را دارد تا در سراسر ربات جایگزین‌پذیر باشد.
 */
class PasarGuard
{
    public array $panel;
    private ?string $token = null;
    /** کلید API (اگر ثبت شده باشد به‌جای یوزر/پسورد استفاده می‌شود) */
    private string $apiKey = '';
    /** آخرین کانفیگ‌های دریافت‌شده از پنل */
    private array $links = [];
    /** آخرین لینک اشتراک دریافت‌شده از پنل */
    private string $subUrl = '';
    /** آخرین نام کاربری خوانده‌شده از پنل */
    private string $lastUser = '';
    /** نگاشت uuid به نام کاربری */
    private array $userMap = [];
    private ?array $groupCache = null;
    /** fixed77: علت خالی‌بودن لیست گروه‌ها (خطای API یا نام اشتباه) برای پیام خطای شفاف */
    private string $groupErr = '';
    /** fixed77: اگر هدر apikey پذیرفته نشد، کلید با Bearer ارسال می‌شود (نسخه‌های متفاوت پاسارگارد) */
    private bool $keyBearer = false;
    private string $groupApiErr = '';
    /** fixed78: گروه‌ها از کاربران موجود استنتاج شده‌اند (ادمین مجوز groups.read ندارد) */
    private bool $groupsInferred = false;
    /** fixed79: گروه‌های اختصاصی محصول (بر گروه‌های سطح پنل اولویت دارد) */
    private string $groupOverride = '';
    private ?array $sysCache = null;

    public function __construct(array $panel)
    {
        $this->panel = $panel;
        $tok = trim((string)($panel['api_token'] ?? ''));
        if ($tok !== '') {
            try { $this->apiKey = trim((string)app_decrypt($tok)); } catch (Throwable $e) { $this->apiKey = ''; }
            /* اگر رمزگشایی ناموفق بود ولی مقدار شبیه کلید خام است، همان را استفاده می‌کنیم */
            if ($this->apiKey === '' && preg_match('~^pg_key_~i', $tok)) $this->apiKey = $tok;
        }
    }

    public static function forPanel($panelId): ?self
    {
        $p = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)$panelId]);
        return $p ? new self($p) : null;
    }

    /** آیا برای این پنل کلید API ثبت شده است؟ */
    public static function hasApiKey(array $panel): bool
    {
        return trim((string)($panel['api_token'] ?? '')) !== '';
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

    /** قواعد نام کاربری پاسارگارد همان مرزبان است: a-z و 0-9 و آندرلاین، طول ۳ تا ۳۲ */
    public static function safeName(string $email): string { return Marzban::safeName($email); }

    /** تبدیل تاریخ انقضای پنل (timestamp یا رشتهٔ ISO) به ثانیهٔ یونیکس؛ ۰ = بدون انقضا */
    public static function expTs($v): int
    {
        if ($v === null || $v === '' || $v === false) return 0;
        if (is_int($v) || is_float($v)) {
            $n = (int)$v;
            return $n > 9999999999 ? (int)floor($n / 1000) : max(0, $n);
        }
        $s = trim((string)$v);
        if ($s === '' || $s === '0') return 0;
        if (ctype_digit($s)) {
            $n = (int)$s;
            return $n > 9999999999 ? (int)floor($n / 1000) : $n;
        }
        $t = strtotime($s);
        return $t !== false && $t > 0 ? $t : 0;
    }

    /* ------------------------------------------------------------------ */

    private function curl(string $url, $body = null, string $method = 'GET', bool $form = false): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($this->apiKey !== '') {
            /* پاسارگارد هر دو شکل را می‌پذیرد؛ هر دو ارسال می‌شود تا با همهٔ نسخه‌ها سازگار باشد */
            $headers[] = $this->keyBearer ? 'Authorization: Bearer ' . $this->apiKey : 'Authorization: apikey ' . $this->apiKey;
            $headers[] = 'X-Api-Key: ' . $this->apiKey;
        } elseif ($this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
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

    /** پیام خطای خوانا از پاسخ پنل (detail ممکن است رشته یا آرایهٔ خطاهای اعتبارسنجی باشد) */
    private static function errOf(array $res): string
    {
        if (($res['error'] ?? '') !== '') return (string)$res['error'];
        $detail = $res['json']['detail'] ?? '';
        if (is_array($detail)) {
            $parts = [];
            foreach ($detail as $d) {
                if (is_array($d)) {
                    $loc = isset($d['loc']) && is_array($d['loc']) ? implode('.', array_map('strval', $d['loc'])) : '';
                    $parts[] = trim($loc . ': ' . (string)($d['msg'] ?? jenc($d)), ': ');
                } else {
                    $parts[] = (string)$d;
                }
            }
            $detail = implode(' | ', $parts);
        }
        $detail = (string)$detail;
        return $detail !== '' ? mb_substr($detail, 0, 200) : ('HTTP ' . (int)($res['code'] ?? 0));
    }

    /** ورود ادمین و گرفتن توکن؛ با کلید API نیازی به ورود نیست */
    public function login(bool $force = false): bool
    {
        if ($this->apiKey !== '') return true;

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
        app_log('panel', 'pasarguard login failed', ['panel' => (string)($this->panel['name'] ?? ''), 'msg' => $msg]);
        return false;
    }

    /** فراخوانی API؛ در صورت انقضای توکن یک بار دوباره وارد می‌شود */
    private function api(string $path, $body = null, string $method = 'GET'): array
    {
        if (!$this->login()) {
            return ['ok' => false, 'code' => 0, 'json' => [], 'msg' => 'اتصال به پنل ناموفق (ورود)'];
        }
        $res = $this->curl($this->base() . $path, $body, $method);
        if ((int)$res['code'] === 401 || (int)$res['code'] === 403) {
            /* fixed77: یک‌بار با هدر Bearer تلاش می‌شود */
            if ($this->apiKey !== '' && !$this->keyBearer) {
                $this->keyBearer = true;
                $res = $this->curl($this->base() . $path, $body, $method);
            }
        }
        if ((int)$res['code'] === 401 || (int)$res['code'] === 403) {
            if ($this->apiKey !== '') {
                /* fixed78: «Permission denied» یعنی کلید معتبر است اما مجوز این عمل را ندارد؛ کلید را نامعتبر ثبت نمی‌کنیم */
                $det = self::errOf($res);
                if (stripos($det, 'permission') !== false) {
                    return ['ok' => false, 'code' => (int)$res['code'], 'json' => is_array($res['json'] ?? null) ? (array)$res['json'] : [], 'msg' => $det];
                }
                try {
                    DB::update('panels', ['last_error' => 'کلید API پاسارگارد نامعتبر یا بدون دسترسی است'], 'id = :id', [':id' => (int)$this->panel['id']]);
                } catch (Throwable $e) { }
                return ['ok' => false, 'code' => (int)$res['code'], 'json' => [], 'msg' => 'کلید API پاسارگارد نامعتبر یا بدون دسترسی است'];
            }
            if (stripos(self::errOf($res), 'permission') === false) { /* fixed78: «Permission denied» ربطی به توکن ندارد؛ ورود مجدد لازم نیست */
                if (!$this->login(true)) {
                    return ['ok' => false, 'code' => 401, 'json' => [], 'msg' => 'توکن پنل نامعتبر است'];
                }
                $res = $this->curl($this->base() . $path, $body, $method);
            }
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

    /* ---------------------- گروه‌ها (به‌جای اینباند) ---------------------- */

    /** همهٔ گروه‌های پنل: [['id'=>1,'name'=>'…','inbound_tags'=>[…],'is_disabled'=>false], …] */
    public function groups(bool $force = false): array
    {
        if ($this->groupCache !== null && !$force) return $this->groupCache;
        $r    = $this->api('/api/groups');
        $this->groupsInferred = false;
        $this->groupApiErr    = '';
        if (!$r['ok']) {
            $m = (string)$r['msg'];
            if ((int)$r['code'] === 403 || stripos($m, 'permission') !== false) {
                /* fixed78: ادمین غیر sudo بدون مجوز groups.read */
                $this->groupApiErr = 'ادمین این پنل مجوز «groups.read» ندارد (کد 403). در پاسارگارد → Admins → ویرایش همین ادمین → مجوز groups.read (یا sudo) را فعال کنید؛ یا شناسهٔ عددی گروه‌ها را در فیلد «گروه‌ها» بنویسید.';
            } else {
                $this->groupApiErr = 'دریافت لیست گروه‌ها از پاسارگارد ناموفق بود: ' . ($m !== '' ? $m : 'خطای نامشخص') . ' (کد ' . (int)$r['code'] . ')';
            }
        }
        $rows = [];
        $src  = $r['json'];
        if (isset($src['groups']) && is_array($src['groups'])) $src = $src['groups'];
        elseif (isset($src['items']) && is_array($src['items'])) $src = $src['items'];
        elseif (isset($src['data']) && is_array($src['data'])) $src = $src['data'];
        foreach ((array)$src as $g) {
            if (!is_array($g) || !isset($g['id'])) continue;
            $rows[] = [
                'id'           => (int)$g['id'],
                'name'         => (string)($g['name'] ?? ('group-' . (int)$g['id'])),
                'inbound_tags' => array_values(array_map('strval', (array)($g['inbound_tags'] ?? []))),
                'is_disabled'  => !empty($g['is_disabled']),
                'total_users'  => (int)($g['total_users'] ?? 0),
            ];
        }
        if (!$rows && !$r['ok']) {
            /* fixed78: بدون مجوز groups.read، شناسهٔ گروه‌ها از کاربران موجودِ همین ادمین خوانده می‌شود */
            $rows = $this->groupsFromUsers();
            if ($rows) $this->groupsInferred = true;
        }
        $this->groupCache = $rows;
        return $rows;
    }

    /**
     * fixed78: وقتی ادمین مجوز groups.read ندارد، شناسهٔ گروه‌ها از کاربران موجودِ همان ادمین استنتاج می‌شود
     * (GET /api/users فقط users.read می‌خواهد). نام گروه در این حالت معلوم نیست → «group-ID».
     */
    private function groupsFromUsers(): array
    {
        $r = $this->api('/api/users?offset=0&limit=500');
        if (!$r['ok']) return [];
        $src = $r['json'];
        if (isset($src['users']) && is_array($src['users'])) $src = $src['users'];
        elseif (isset($src['items']) && is_array($src['items'])) $src = $src['items'];
        elseif (isset($src['data']) && is_array($src['data'])) $src = $src['data'];
        $cnt = [];
        foreach ((array)$src as $u) {
            if (!is_array($u)) continue;
            $gids = $u['group_ids'] ?? null;
            if (!is_array($gids)) {
                $gids = [];
                foreach ((array)($u['groups'] ?? []) as $g) $gids[] = is_array($g) ? ($g['id'] ?? 0) : $g;
            }
            foreach ($gids as $gid) {
                $gid = (int)$gid;
                if ($gid > 0) $cnt[$gid] = ($cnt[$gid] ?? 0) + 1;
            }
        }
        ksort($cnt);
        $rows = [];
        foreach ($cnt as $gid => $n) {
            $rows[] = [
                'id'           => (int)$gid,
                'name'         => 'group-' . (int)$gid,
                'inbound_tags' => [],
                'is_disabled'  => false,
                'total_users'  => (int)$n,
                'inferred'     => true,
            ];
        }
        return $rows;
    }

    /**
     * شناسهٔ گروه‌هایی که کاربر جدید در آن‌ها ساخته می‌شود.
     * اگر مدیر در فیلد «کد اینباندها» عدد نوشته باشد همان‌ها؛ وگرنه همهٔ گروه‌های فعال پنل.
     */
    public function groupIds(): array
    {
        $this->groupErr = '';
        $raw = $this->groupOverride !== '' ? $this->groupOverride : trim((string)($this->panel['inbound_ids'] ?? '')); /* fixed79 */
        if (function_exists('en_num')) $raw = en_num($raw);
        $tokens = [];
        foreach (preg_split('/[\s,،;]+/u', $raw) ?: [] as $t) {
            $t = strtolower(trim((string)$t));
            if ($t !== '') $tokens[] = $t;
        }
        $all = $this->groups();
        if (!$all && $this->groupApiErr !== '') $this->groupErr = $this->groupApiErr;
        if ($tokens) {
            /* fixed77: هر توکن می‌تواند شناسهٔ عددی یا «نام» گروه باشد */
            $ok = []; $missing = [];
            foreach ($tokens as $t) {
                $isNum = preg_match('/^\d+$/', $t) === 1;
                $hit   = null;
                foreach ($all as $g) {
                    if (($isNum && (int)$g['id'] === (int)$t) || strtolower((string)$g['name']) === $t) { $hit = (int)$g['id']; break; }
                }
                if ($hit !== null) { $ok[] = $hit; continue; }
                /* لیست گروه‌ها در دسترس نیست: شناسهٔ عددی مدیر عیناً ارسال می‌شود تا خطای پنل شفاف باشد */
                if (!$all && $isNum) { $ok[] = (int)$t; continue; }
                $missing[] = $t;
            }
            $ok = array_values(array_unique($ok));
            if ($missing) {
                if ($this->groupsInferred) {
                    /* fixed78: بدون مجوز groups.read نام گروه قابل تبدیل به شناسه نیست */
                    $seen = array_map(static fn(array $g): string => (string)$g['id'], $all);
                    $msg  = 'ادمین پنل مجوز groups.read ندارد و نام گروه («' . implode('»، «', $missing) . '») قابل تبدیل به شناسه نیست. شناسهٔ عددی گروه را بنویسید' . ($seen ? ' (شناسه‌های دیده‌شده روی کاربران: ' . implode('، ', $seen) . ')' : '') . '.';
                } else {
                    $names = array_map(static fn(array $g): string => $g['name'] . ' (' . $g['id'] . ')', $all);
                    $msg   = 'گروه «' . implode('»، «', $missing) . '» در پنل پیدا نشد.' . ($names ? ' گروه‌های موجود: ' . implode('، ', $names) : '');
                }
                if (!$ok) $this->groupErr = ($this->groupErr !== '' ? $this->groupErr . ' — ' : '') . $msg;
                else app_log('panel', 'pasarguard: some groups not found', ['panel' => (int)($this->panel['id'] ?? 0), 'missing' => $missing]);
            }
            return $ok;
        }
        $ids = [];
        foreach ($all as $g) if (empty($g['is_disabled'])) $ids[] = (int)$g['id'];
        if (!$ids && $this->groupErr === '') {
            $this->groupErr = $all
                ? 'همهٔ گروه‌های پنل غیرفعال (disabled) هستند.'
                : 'هیچ گروهی در پنل پیدا نشد؛ در پاسارگارد از بخش Groups یک گروه با اینباندهای دلخواه بسازید.';
        }
        return $ids;
    }

    /** fixed77: علت خالی‌بودن گروه‌ها (خطای API/ورود، نام اشتباه، بدون گروه) */
    public function groupError(): string
    {
        return $this->groupErr !== '' ? $this->groupErr : $this->groupApiErr;
    }

    /** fixed79: گروه‌های اختصاصی یک محصول (نام یا شناسه، با کاما) — جای گروه‌های تنظیم‌شدهٔ پنل */
    public function setGroupOverride(string $raw): void
    {
        $raw = trim($raw);
        if ($raw === $this->groupOverride) return;
        $this->groupOverride = $raw;
        $this->groupErr      = '';
    }

    /** fixed79: وضعیت نودها (GET /api/nodes — نیازمند sudo). null = در دسترس نیست */
    public function nodes(): ?array
    {
        $r = $this->api('/api/nodes');
        if (!$r['ok']) return null;
        $src = $r['json'];
        if (isset($src['nodes']) && is_array($src['nodes'])) $src = $src['nodes'];
        elseif (isset($src['items']) && is_array($src['items'])) $src = $src['items'];
        $out = [];
        foreach ((array)$src as $n) {
            if (!is_array($n)) continue;
            $st = strtolower(trim((string)($n['status'] ?? '')));
            $out[] = [
                'id'       => (int)($n['id'] ?? 0),
                'name'     => (string)($n['name'] ?? ('node-' . (int)($n['id'] ?? 0))),
                'status'   => $st,
                'ok'       => in_array($st, ['connected', 'healthy'], true),
                'disabled' => $st === 'disabled',
                'message'  => (string)($n['message'] ?? ''),
            ];
        }
        return $out;
    }

    /** فهرست نمایشی برای پنل مدیریت: هر گروه یک «اینباند» با شناسهٔ واقعی گروه */
    public function inbounds(): array
    {
        $list = [];
        foreach ($this->groups() as $g) {
            $tags = $g['inbound_tags'] ?: [];
            $list[] = [
                'id'       => (int)$g['id'],
                'protocol' => 'group',
                'remark'   => !empty($g['inferred']) ? ('گروه #' . (int)$g['id'] . ' — استنتاج از ' . fa_num((int)$g['total_users']) . ' کاربر موجود (نام نامعلوم؛ مجوز groups.read نیست)') : $g['name'] . ($tags ? ' [' . implode(', ', array_slice($tags, 0, 4)) . (count($tags) > 4 ? ', …' : '') . ']' : ''),
                'tag'      => implode(',', $tags),
                'port'     => 0,
                'enable'   => empty($g['is_disabled']),
            ];
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
            'protocol' => 'group',
            'remark'   => (string)($this->panel['name'] ?? 'PasarGuard'),
            'tag'      => '',
            'port'     => 0,
            'enable'   => true,
        ];
    }

    /** کاربر یک‌بار ساخته می‌شود و پنل خودش روی همهٔ اینباندهای گروه سرو می‌کند؛ یک شناسهٔ مجازی کافی است */
    public function allowedInboundIds(): array { return [1]; }

    public function assignableInboundIds(): array { return [1]; }

    public function accountInboundIds(string $email): array { return [1]; }

    public static function idList($raw): array { return Xui::idList($raw); }

    public static function speedKeys(int $upKbps, int $downKbps): array { return []; }

    /* ---------------------- ساخت کاربر ---------------------- */

    /** تنظیمات پروتکل با همان UUID ربات تا client_uuid ذخیره‌شده با پنل یکی باشد */
    private static function proxySettings(string $uuid): array
    {
        return [
            'vless'       => ['id' => $uuid, 'flow' => ''],
            'vmess'       => ['id' => $uuid],
            'trojan'      => ['password' => $uuid],
            'shadowsocks' => ['password' => $uuid, 'method' => 'chacha20-ietf-poly1305'],
        ];
    }

    public function addClient(int $inboundId, string $email, string $uuid, float $volumeGb, int $expiryMs, int $ipLimit = 0, string $subId = '', array $inboundIds = [], int $deviceLimit = 0, int $upKbps = 0, int $downKbps = 0): array
    {
        $user   = self::safeName($email);
        $groups = $this->groupIds();
        if (!$groups) {
            $why = $this->groupError();
            return ['success' => false, 'msg' => 'در پاسارگارد ساخت کاربر بدون «گروه» ممکن نیست. ' . ($why !== '' ? $why : 'در فیلد «گروه‌ها» پنل، نام یا شناسهٔ گروه را بنویسید یا آن را خالی بگذارید تا همهٔ گروه‌های فعال استفاده شوند.')];
        }

        $pfx  = trim((string)($this->panel['remark_prefix'] ?? ''));
        if (in_array(strtolower($pfx), ['null', 'undefined', 'nan'], true)) $pfx = '';
        $note = trim($pfx . ' ' . $email);
        $exp  = $expiryMs > 0 ? (int)round($expiryMs / 1000) : null;
        $body = [
            'username'                  => $user,
            'group_ids'                 => array_values($groups),
            'proxy_settings'            => self::proxySettings($uuid),
            'expire'                    => $exp,
            'data_limit'                => $volumeGb > 0 ? (int)gb2bytes($volumeGb) : 0,
            'data_limit_reset_strategy' => 'no_reset',
            'status'                    => 'active',
            'note'                      => mb_substr($note, 0, 190),
        ];

        $r = $this->api('/api/user', $body, 'POST');
        /* بعضی نسخه‌ها proxy_settings دستی را نمی‌پذیرند؛ یک بار بدون آن تلاش می‌شود و شناسه از پاسخ برداشته می‌شود */
        if (!$r['ok'] && (int)$r['code'] === 422) {
            unset($body['proxy_settings']);
            $r = $this->api('/api/user', $body, 'POST');
        }
        if (!$r['ok']) {
            if ((int)$r['code'] === 409) {
                /* کاربر قدیمی با همین نام: حجم/زمان/وضعیت/گروه با خرید جدید بازنویسی و مصرف قبلی صفر می‌شود */
                $upd = $this->api('/api/user/' . rawurlencode($user), [
                    'group_ids'  => array_values($groups),
                    'data_limit' => $volumeGb > 0 ? (int)gb2bytes($volumeGb) : 0,
                    'expire'     => $exp,
                    'status'     => 'active',
                ], 'PUT');
                if (!$upd['ok']) app_log('panel', 'pasarguard 409 refresh failed', ['username' => $user, 'msg' => (string)$upd['msg']]);
                try { $this->api('/api/user/' . rawurlencode($user) . '/reset', null, 'POST'); } catch (Throwable $e) { }
                $exist = $this->api('/api/user/' . rawurlencode($user));
                if ($exist['ok'] && $exist['json']) {
                    $this->absorb($exist['json'], $uuid);
                    return ['success' => true, 'client' => $this->clientOf($exist['json'], $uuid), 'inbound' => $this->inbound(1)];
                }
            }
            app_log('panel', 'pasarguard addClient failed', [
                'panel'    => (int)($this->panel['id'] ?? 0),
                'username' => $user,
                'groups'   => implode(',', $groups),
                'code'     => (int)$r['code'],
                'msg'      => (string)$r['msg'],
            ]);
            return ['success' => false, 'msg' => $r['msg'] !== '' ? $r['msg'] : 'خطا در ساخت کاربر در پاسارگارد'];
        }

        $this->absorb($r['json'], $uuid);
        /* لینک اشتراک یا کانفیگ‌ها در پاسخ ساخت نبود؛ یک بار مستقیم می‌خوانیم */
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
            foreach (self::proxyIds($u) as $key) $this->userMap[$key] = $name;
        }
    }

    /** همهٔ شناسه‌ها/پسوردهای پروتکل‌ها از پاسخ پنل */
    private static function proxyIds(array $u): array
    {
        $out = [];
        $ps  = $u['proxy_settings'] ?? ($u['proxies'] ?? []);
        foreach ((array)$ps as $px) {
            if (!is_array($px)) continue;
            $key = (string)($px['id'] ?? ($px['password'] ?? ''));
            if ($key !== '') $out[] = $key;
        }
        return array_values(array_unique($out));
    }

    /** تبدیل مسیر نسبی ساب به آدرس کامل */
    private function absUrl(string $u): string
    {
        if (preg_match('~^https?://~i', $u)) return $u;
        $base = trim((string)($this->panel['sub_base'] ?? ''));
        $root = $base !== '' ? rtrim($base, '/') : $this->base();
        return $root . '/' . ltrim($u, '/');
    }

    /** تبدیل پاسخ پاسارگارد به ساختار کلاینتی که ربات می‌شناسد */
    private function clientOf(array $u, string $uuid = ''): array
    {
        $ids = self::proxyIds($u);
        $id  = $ids ? (string)$ids[0] : $uuid;
        /* اگر UUID ربات بین شناسه‌های پنل هست، همان را ترجیح می‌دهیم */
        if ($uuid !== '' && in_array($uuid, $ids, true)) $id = $uuid;
        $exp = self::expTs($u['expire'] ?? null);
        return [
            'email'      => (string)($u['username'] ?? ''),
            'id'         => $id,
            'password'   => $id,
            'totalGB'    => (int)($u['data_limit'] ?? 0),
            'expiryTime' => $exp > 0 ? $exp * 1000 : 0,
            'enable'     => in_array((string)($u['status'] ?? 'active'), ['active', 'on_hold'], true),
            'limitIp'    => 0,
            'subId'      => (string)($u['subscription_url'] ?? ''),
            'status'     => (string)($u['status'] ?? ''),
            'onlineAt'   => self::expTs($u['online_at'] ?? null),
            'groupIds'   => array_values(array_map('intval', (array)($u['group_ids'] ?? []))),
        ];
    }

    /* ---------------------- ساب و کانفیگ‌ها ---------------------- */

    /** کانفیگ‌های همین اکانت (چند خط) — از پاسخ ساخت یا لینک اشتراک پنل */
    public function buildConfigLink(array $inbound, string $uuid, string $email): string
    {
        if (!$this->links && $email !== '') $this->rebuildConfigs($email, $uuid);
        return implode("\n", $this->links);
    }

    /** لینک اشتراک واقعی پنل (توکن ساب را خود پنل تولید می‌کند) */
    public function subLink(string $subId): string
    {
        if ($this->subUrl !== '') return $this->subUrl;
        if ($this->lastUser !== '') {
            $r = $this->api('/api/user/' . rawurlencode($this->lastUser));
            if ($r['ok'] && $r['json']) $this->absorb((array)$r['json']);
        }
        return $this->subUrl;
    }

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

    /** خواندن کانفیگ‌ها از روی لینک اشتراک (پاسارگارد: /sub/{token}/v2ray یا /links) */
    public function subFetch(string $subId, string $subUrl = ''): array
    {
        $url = trim($subUrl) !== '' ? trim($subUrl) : $this->subLink(trim($subId));
        if ($url === '') return [];
        foreach (['/v2ray', '', '/links'] as $suffix) {
            $links = Xui::parseSubBody(Xui::httpGet(rtrim($url, '/') . $suffix));
            if ($links) return $links;
        }
        return [];
    }

    public function rebuildConfigs(string $email, string $uuid, array $extraInbounds = []): array
    {
        $r = $this->api('/api/user/' . rawurlencode(self::safeName($email)));
        if ($r['ok'] && $r['json']) $this->absorb($r['json'], $uuid);
        if ($this->links) return $this->links;
        $links = $this->subFetch('', $this->subUrl);
        if ($links) $this->links = $links;
        return $links;
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
            'expire'     => $exp > 0 ? (int)round($exp / 1000) : null,
            'status'     => !empty($client['enable']) ? 'active' : 'disabled',
        ];

        $r = $this->api('/api/user/' . rawurlencode($user), $body, 'PUT');
        if ($r['ok']) {
            $this->absorb($r['json']);
            return ['success' => true, 'obj' => $r['json']];
        }
        return ['success' => false, 'msg' => $r['msg'] !== '' ? $r['msg'] : 'خطا در ویرایش کاربر پاسارگارد'];
    }

    /** فعال/غیرفعال کردن کاربر */
    public function setEnabled(string $email, bool $on): array
    {
        $r = $this->api('/api/user/' . rawurlencode(self::safeName($email)), ['status' => $on ? 'active' : 'disabled'], 'PUT');
        return $r['ok'] ? ['success' => true] : ['success' => false, 'msg' => $r['msg']];
    }

    public function resetTraffic(int $inboundId, string $email): array
    {
        $r = $this->api('/api/user/' . rawurlencode(self::safeName($email)) . '/reset', null, 'POST');
        return $r['ok'] ? ['success' => true] : ['success' => false, 'msg' => $r['msg']];
    }

    /** لغو (revoke) لینک اشتراک — لینک قبلی بی‌اعتبار و لینک جدید ساخته می‌شود */
    public function revokeSub(string $email): array
    {
        $r = $this->api('/api/user/' . rawurlencode(self::safeName($email)) . '/revoke_sub', null, 'POST');
        if ($r['ok'] && $r['json']) $this->absorb((array)$r['json']);
        return $r['ok'] ? ['success' => true, 'sub' => $this->subUrl] : ['success' => false, 'msg' => $r['msg']];
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
        return ['success' => false, 'msg' => $r['msg'] !== '' ? $r['msg'] : 'حذف کاربر در پاسارگارد ناموفق بود'];
    }

    /* ---------------------- مصرف ---------------------- */

    public function clientTraffic(string $email): ?array
    {
        $u = $this->accountRow($email);
        if (!$u) return null;
        $used = (int)($u['used_traffic'] ?? 0);
        $exp  = self::expTs($u['expire'] ?? null);
        return [
            'email'      => (string)($u['username'] ?? $email),
            'up'         => 0,
            'down'       => $used,
            'total'      => (int)($u['data_limit'] ?? 0),
            'expiryTime' => $exp > 0 ? $exp * 1000 : 0,
            'enable'     => in_array((string)($u['status'] ?? 'active'), ['active', 'on_hold'], true),
            'status'     => (string)($u['status'] ?? ''),
            'onlineAt'   => self::expTs($u['online_at'] ?? null),
            'lifetime'   => (int)($u['lifetime_used_traffic'] ?? 0),
        ];
    }

    public function liveTraffic(string $email): ?array
    {
        return $this->clientTraffic($email);
    }

    /** آخرین زمان آنلاین بودن کاربر (ثانیهٔ یونیکس؛ ۰ = هرگز) */
    public function lastOnline(string $email): int
    {
        $u = $this->accountRow($email);
        return $u ? self::expTs($u['online_at'] ?? null) : 0;
    }

    /* ---------------------- آمار سیستم ---------------------- */

    /** آمار /api/system (نسخه، حافظه، CPU، تعداد کاربران، پهنای باند) */
    public function systemStats(bool $force = false): array
    {
        if ($this->sysCache !== null && !$force) return $this->sysCache;
        $r = $this->api('/api/system');
        $this->sysCache = $r['ok'] ? (array)$r['json'] : [];
        return $this->sysCache;
    }

    /* ---------------------- تست سلامت ---------------------- */

    public function healthCheck(): array
    {
        if ($this->apiKey === '' && !$this->login(true)) {
            return ['ok' => false, 'message' => 'ورود به پاسارگارد ناموفق بود. آدرس، پورت و یوزر/پسورد ادمین (یا کلید API) را بررسی کنید.'];
        }
        $sys = $this->api('/api/system');
        $sysNote = '';
        if (!$sys['ok'] && (int)($sys['code'] ?? 0) === 403) {
            /* fixed78: ادمین غیر sudo به /api/system دسترسی ندارد؛ اتصال برقرار است، فقط آمار نداریم */
            $sysNote = ' • آمار سیستم در دسترس نیست (ادمین sudo نیست)';
            $sys     = ['ok' => true, 'code' => 200, 'json' => [], 'msg' => ''];
        }
        if (!$sys['ok']) {
            $why = $this->apiKey !== '' ? 'کلید API پذیرفته نشد یا دسترسی sudo ندارد.' : 'پاسخ نامعتبر از پنل.';
            return ['ok' => false, 'message' => 'اتصال به پاسارگارد ناموفق: ' . $why . ' (' . $sys['msg'] . ')'];
        }
        $j     = $sys['json'];
        $extra = $sysNote . Xui::nodesNote($this->nodes()); /* fixed79: وضعیت نودها */
        if ($j) {
            $ver = trim((string)($j['version'] ?? ''));
            $extra .= $ver !== '' ? ' • نسخه: ' . $ver : '';
            $extra .= ' • کاربران پنل: ' . fa_num((int)($j['total_user'] ?? 0))
                    . ' • فعال: ' . fa_num((int)($j['active_users'] ?? ($j['users_active'] ?? 0)))
                    . ' • آنلاین: ' . fa_num((int)($j['online_users'] ?? 0));
        }
        $inb  = $this->inbounds();
        $infNote = $this->groupsInferred
            ? ' ⚠️ ادمین مجوز groups.read ندارد؛ شناسهٔ گروه‌ها از کاربران موجود استنتاج شد (' . fa_num(count($inb)) . ' گروه). برای اطمینان، شناسهٔ عددی گروه را در فیلد «گروه‌ها» بنویسید یا به ادمین مجوز groups.read بدهید.'
            : '';
        $mode = $this->apiKey !== '' ? ' (کلید API)' : ' (یوزر/پسورد)';
        if (!$inb) {
            return [
                'ok'          => true,
                'message'     => 'اتصال به پاسارگارد موفق بود' . $mode . ' اما هیچ گروهی در پنل پیدا نشد؛ بدون گروه ساخت کاربر ممکن نیست. در پنل → Groups یک گروه بسازید.' . ($this->groupError() !== '' ? ' (' . $this->groupError() . ')' : '') . $extra,
                'inbounds'    => [],
                'inbound_ids' => [],
            ];
        }
        $sel = $this->groupIds();
        if (!$sel) {
            return [
                'ok'          => true,
                'message'     => '⚠️ اتصال به پاسارگارد موفق بود' . $mode . ' اما گروهی برای ساخت کاربر انتخاب نشد: ' . ($this->groupError() !== '' ? $this->groupError() : 'نام/شناسهٔ گروه‌ها را در فیلد «گروه‌ها» بررسی کنید.'),
                'inbounds'    => $inb,
                'inbound_ids' => array_map(static fn($i) => (int)$i['id'], $inb),
            ];
        }
        return [
            'ok'          => true,
            'message'     => 'اتصال به پاسارگارد موفق بود' . $mode . '. گروه‌های استفاده‌شده برای ساخت کاربر: ' . fa_num(implode(', ', $sel)) . $infNote . $extra,
            'inbounds'    => $inb,
            'inbound_ids' => array_map(static fn($i) => (int)$i['id'], $inb),
        ];
    }
}
