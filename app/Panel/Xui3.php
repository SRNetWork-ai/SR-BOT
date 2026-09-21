<?php
declare(strict_types=1);

/**
 * درایور نسل جدید پنل سنایی (3X-UI) — احراز هویت با توکن API (Bearer)
 *
 * فعال‌سازی: در فرم پنل (نوع «سنایی جدید») فیلد «توکن API» را پر کنید.
 * توکن از «تنظیمات → امنیت → API Token» خودِ پنل ساخته می‌شود (سطح admin)
 * و مانند رمز پنل به صورت رمزنگاری‌شده در دیتابیس نگهداری می‌شود.
 *
 * تفاوت با درایور قدیمی (Xui):
 *  - بدون لاگین و کوکی؛ هر درخواست فقط با هدر Authorization: Bearer.
 *  - کلاینت‌ها موجودیت مستقل‌اند: /panel/api/clients/* (ساخت روی چند اینباند در یک درخواست،
 *    ویرایش/حذف سراسری با ایمیل، اتصال به اینباند جدید با attach).
 *  - لینک کانفیگ‌ها را خودِ پنل می‌سازد: /panel/api/clients/links/{email}
 *    (الگوی remark تنظیم‌شده در پنل به‌صورت یکسان اعمال می‌شود).
 *  - اگر فیلد توکن خالی باشد این درایور فعال نمی‌شود و ربات مثل قبل از
 *    درایور کوکی/لاگین استفاده می‌کند (fallback امن و بدون شکستن پنل‌های فعلی).
 */
class Xui3
{
    public array $panel;

    /** نگاشت uuid/password → email برای متدهایی که فقط uuid دارند */
    private array $userMap = [];
    /** ایمیل اکانت‌هایی که در همین درخواست ساخته شده‌اند (برای پرهیز از بازنویسی تکراری) */
    private array $created = [];
    /** کش اینباندها در طول عمر همین درخواست */
    private ?array $inboundCache = null;
    /** کش لینک‌های هر اکانت */
    private array $linksCache = [];
    /** لینک‌هایی که در حلقه ساخت سرویس قبلاً تحویل شده‌اند (جلوگیری از تکرار) */
    private array $linksServed = [];
    private ?string $tokenPlain = null;

    /* 0.0.2 #x3-reset: دورهٔ ریست خودکار حجم (روز) برای اکانت تازه — ۰ = خاموش */
    public $autoReset = 0;

    public function __construct(array $panel) { $this->panel = $panel; }

    public static function forPanel($panelId): ?self
    {
        $p = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)$panelId]);
        return $p ? new self($p) : null;
    }

    /** آیا برای این پنل توکن API ثبت شده است؟ (شرط فعال‌شدن این درایور) */
    public static function hasToken(array $panel): bool
    {
        return trim((string)($panel['api_token'] ?? '')) !== '';
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

    public function nodeHost(): string
    {
        $n = trim((string)($this->panel['node_host'] ?? ''));
        return $n !== '' ? $n : trim((string)$this->panel['host']);
    }

    public function isVpnUi(): bool { return false; }

    public static function idList($raw): array { return Xui::idList($raw); }

    public static function speedKeys(int $upKbps, int $downKbps): array { return Xui::speedKeys($upKbps, $downKbps); }

    /* ------------------------------------------------------------------ */

    /** توکن رمزگشایی‌شده */
    private function token(): string
    {
        if ($this->tokenPlain === null) {
            $this->tokenPlain = app_decrypt(trim((string)($this->panel['api_token'] ?? '')));
        }
        return $this->tokenPlain;
    }

    /** توکن ایستاست؛ «ورود» یعنی فقط وجود توکن */
    public function login(bool $force = false): bool
    {
        if ($this->token() !== '') return true;
        try {
            DB::update('panels', ['last_error' => 'توکن API خالی یا غیرقابل رمزگشایی است'], 'id = :id', [':id' => (int)($this->panel['id'] ?? 0)]);
        } catch (Throwable $e) { }
        return false;
    }

    private function curl(string $url, $body = null, string $method = 'GET'): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->token()];
        /* بررسی گواهی SSL مانند بقیه درایورها برای هر پنل جداگانه قابل تنظیم است */
        $verify = (int)($this->panel['ssl_verify'] ?? 0) === 1;
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : jenc($body);
            $headers[] = 'Content-Type: application/json';
        } elseif ($method === 'POST') {
            $opts[CURLOPT_POSTFIELDS] = '';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $raw = is_string($raw) ? $raw : '';
        return ['code' => $code, 'body' => $raw, 'json' => jdec($raw, []), 'error' => $err];
    }

    /** فراخوانی API نسل جدید — خروجی همیشه ['success','msg','obj','code'] */
    private function api(string $path, $body = null, string $method = 'GET'): array
    {
        if ($this->token() === '') {
            return ['success' => false, 'msg' => 'توکن API برای این پنل ثبت نشده است', 'obj' => null, 'code' => 0];
        }
        $res  = $this->curl($this->base() . '/panel/api' . $path, $body, $method);
        $code = (int)$res['code'];
        $j    = is_array($res['json']) ? $res['json'] : [];
        if (array_key_exists('success', $j)) {
            $j += ['msg' => '', 'obj' => null];
            $j['code'] = $code;
            return $j;
        }
        $msg = (string)($res['error'] !== '' ? $res['error'] : ('HTTP ' . $code));
        if ($code === 401 || $code === 403) $msg = 'توکن API نامعتبر یا منقضی است (HTTP ' . $code . ')';
        if ($code === 404)                  $msg = 'این پنل از API نسل جدید پشتیبانی نمی‌کند (HTTP 404)';
        return ['success' => false, 'msg' => $msg, 'obj' => null, 'code' => $code];
    }

    /* ---------------------- اینباندها ---------------------- */

    /** تنظیمات تو در توی نسل جدید برای سازگاری با بقیهٔ ربات به رشتهٔ JSON برمی‌گردد */
    private static function normInbound($in): array
    {
        $in = (array)$in;
        foreach (['settings', 'streamSettings', 'sniffing', 'allocate'] as $k) {
            if (isset($in[$k]) && is_array($in[$k])) $in[$k] = jenc($in[$k]);
        }
        return $in;
    }

    public function inbounds(): array
    {
        if ($this->inboundCache !== null) return $this->inboundCache;
        $r   = $this->api('/inbounds/list');
        $out = [];
        if (($r['success'] ?? false) === true) {
            foreach ((array)($r['obj'] ?? []) as $in) $out[] = self::normInbound($in);
        }
        return $this->inboundCache = $out;
    }

    public function inbound(int $id): ?array
    {
        foreach ($this->inbounds() as $in) {
            if ((int)($in['id'] ?? 0) === $id) return $in;
        }
        $r = $this->api('/inbounds/get/' . $id);
        if (($r['success'] ?? false) && !empty($r['obj'])) return self::normInbound($r['obj']);
        return null;
    }

    /** خلاصهٔ اینباندها (id/remark/protocol/port) — سبک؛ برای انتخاب و تشخیص نسخه */
    public function inboundOptions(): array
    {
        $r = $this->api('/inbounds/options');
        return ($r['success'] ?? false) === true ? (array)($r['obj'] ?? []) : [];
    }

    public function allowedInboundIds(): array
    {
        $raw = trim((string)($this->panel['inbound_ids'] ?? ''));
        if ($raw !== '') {
            $ids = array_filter(array_map('intval', preg_split('/[\s,;]+/', en_num($raw)) ?: []));
            return array_values(array_unique($ids));
        }
        /* خالی = همهٔ اینباندهای پنل مجاز هستند */
        return $this->assignableInboundIds();
    }

    public function assignableInboundIds(): array
    {
        $ids = [];
        foreach ($this->inboundOptions() as $row) {
            if (is_array($row))       $ids[] = (int)($row['id'] ?? 0);
            elseif (is_numeric($row)) $ids[] = (int)$row;
        }
        return Xui::idList($ids);
    }

    /* ---------------------- کلاینت‌ها ---------------------- */

    /** ردیف کلاینت از پاسخ پنل؛ هر دو شکل {client:{...},inboundIds:[...]} و تختِ ساده پشتیبانی می‌شود */
    private static function rowOf($obj): array
    {
        $obj = (array)$obj;
        if (isset($obj['client']) && is_array($obj['client'])) {
            $row = $obj['client'];
            foreach (['inboundIds', 'externalConfigIds'] as $k) {
                if (isset($obj[$k]) && !isset($row[$k])) $row[$k] = $obj[$k];
            }
            return $row;
        }
        return $obj;
    }

    /** خواندن کامل یک کلاینت با ایمیل (null = پیدا نشد) */
    private function clientRow(string $email): ?array
    {
        $email = trim($email);
        if ($email === '') return null;
        $r = $this->api('/clients/get/' . rawurlencode($email));
        if (($r['success'] ?? false) !== true || empty($r['obj'])) return null;
        $row = self::rowOf($r['obj']);
        $key = Xui::clientKey($row, '');
        if ($key !== '') $this->userMap[$key] = (string)($row['email'] ?? $email);
        return $row;
    }

    /** کلیدهایی که جزو payload کلاینت نی��تند و در ویرایش نباید ارسال شوند */
    private static function cleanClient(array $c): array
    {
        $drop = [
            'inboundIds', 'externalConfigIds', 'externalLinks', 'memberships',
            'up', 'down', 'total', 'allTime', 'lastOnline', 'lastSubFetch', 'resetCount',
            'inboundId', 'created_at', 'updated_at', 'createdAt', 'updatedAt', 'uuid',
            /* کلیدهای محدودیت سرعت مخصوص فورک vpn-ui — در نسل جدید تعریف نشده‌اند */
            'uploadLimit', 'upLimit', 'limitUp', 'speedLimitUp', 'upSpeed', 'maxUpload', 'upMbps',
            'downloadLimit', 'downLimit', 'limitDown', 'speedLimitDown', 'downSpeed', 'maxDownload', 'downMbps',
        ];
        foreach ($drop as $k) unset($c[$k]);
        /* شمارهٔ ردیف پنل هرگز به‌جای UUID ارسال نشود */
        if (isset($c['id']) && !Xui::isClientKey($c['id'])) unset($c['id']);
        return $c;
    }

    /**
     * ساخت کلاینت — یک اکانت روی یک یا چند اینباند در یک درخواست.
     * اگر اکانت با همین ایمیل از قبل باشد (حلقهٔ چنداینباندی ربات)، به‌جای خطای
     * «ایمیل تکراری» با attach به اینباندهای جدید وصل می‌شود.
     */
    public function addClient(int $inboundId, string $email, string $uuid, float $volumeGb, int $expiryMs, int $ipLimit = 0, string $subId = '', array $inboundIds = [], int $deviceLimit = 0, int $upKbps = 0, int $downKbps = 0): array
    {
        $ids = Xui::idList($inboundIds);
        if (!$ids) $ids = [$inboundId];
        if ($inboundId > 0 && !in_array($inboundId, $ids, true)) array_unshift($ids, $inboundId);
        $ids = array_values($ids);

        $inbound = $this->inbound($inboundId) ?? ['id' => $inboundId, 'protocol' => 'vless', 'remark' => (string)($this->panel['name'] ?? ''), 'port' => 0];
        $proto   = strtolower((string)($inbound['protocol'] ?? 'vless'));

        /* اکانت موجود → اتصال به اینباندهای خواسته‌شده + بازنویسی حجم/زمان با خرید جدید */
        $existing = $this->clientRow($email);
        if ($existing) {
            $r = $this->api('/clients/' . rawurlencode($email) . '/attach', ['inboundIds' => $ids], 'POST');
            if (($r['success'] ?? false) !== true) {
                return ['success' => false, 'msg' => (string)($r['msg'] ?? 'اتصال اکانت به اینباند جدید ناموفق بود')];
            }
            /*
             * اگر اکانتی با همین نام از قبل روی پنل مانده باشد (مثلاً کانفیگ قدیمیِ تمام‌شده)،
             * سرویس تازه نباید سهمیهٔ صفر و تاریخ گذشتهٔ آن را به ارث ببرد؛ پس حجم/زمان/وضعیت
             * با مقادیر خرید جدید بازنویسی و مصرف قبلی صفر می‌شود.
             */
            $fresh = [
                'email'      => $email,
                'enable'     => true,
                'totalGB'    => $volumeGb > 0 ? gb2bytes($volumeGb) : 0,
                'expiryTime' => $expiryMs,
                'limitIp'    => $ipLimit,
            ];
            if ($deviceLimit > 0) $fresh['limitHwid'] = $deviceLimit;
            if (empty($this->created[$email])) {
                $up = $this->updateClient($inboundId, $uuid, $fresh, $ids);
                if (($up['success'] ?? false) !== true) {
                    app_log('panel', 'xui3 attach: refresh quota failed', ['panel' => (int)($this->panel['id'] ?? 0), 'email' => $email, 'msg' => (string)($up['msg'] ?? '')]);
                }
                try { $this->resetTraffic($inboundId, $email); } catch (Throwable $e) { }
                $this->created[$email] = true;
            }
            unset($this->linksCache[$email], $this->linksServed[$email]);
            $this->userMap[$uuid] = $email;
            return ['success' => true, 'client' => array_merge($existing, $fresh), 'inbound' => $inbound];
        }

        $client = [
            'email'      => $email,
            'enable'     => true,
            'totalGB'    => $volumeGb > 0 ? gb2bytes($volumeGb) : 0,
            'expiryTime' => $expiryMs,
            'limitIp'    => $ipLimit,
            'subId'      => $subId !== '' ? $subId : rnd(16),
            'reset'      => max(0, (int)$this->autoReset),
            'comment'    => '',
        ];
        if ($proto === 'trojan' || $proto === 'shadowsocks') {
            $client['password'] = $uuid;
        } else {
            $client['id']   = $uuid;
            $client['flow'] = '';
        }
        if ($deviceLimit > 0) $client['limitHwid'] = $deviceLimit;

        $r = $this->api('/clients/add', ['client' => $client, 'inboundIds' => $ids], 'POST');
        if (($r['success'] ?? false) !== true) {
            app_log('panel', 'xui3 addClient failed', ['panel' => (int)($this->panel['id'] ?? 0), 'email' => $email, 'msg' => (string)($r['msg'] ?? '')]);
            return ['success' => false, 'msg' => (string)($r['msg'] ?? 'خطا در ساخت کاربر در پنل')];
        }
        $this->userMap[$uuid] = $email;
        $this->created[$email] = true;
        unset($this->linksCache[$email], $this->linksServed[$email]);
        return ['success' => true, 'client' => $client, 'inbound' => $inbound];
    }

    /**
     * ویرایش کلاینت — نسل جدید ردیف را «کامل جایگزین» می‌کند (patch نیست)؛
     * پس اول نسخهٔ فعلی خوانده می‌شود و تغییرات ربات روی آن سوار می‌شود.
     */
    public function updateClient(int $inboundId, string $uuid, array $client, array $inboundIds = []): array
    {
        $email = trim((string)($client['email'] ?? ''));
        if ($email === '') $email = trim((string)($this->userMap[$uuid] ?? ''));
        if ($email === '') return ['success' => false, 'msg' => 'ایمیل اکانت برای ویرایش مشخص نیست'];

        $cur    = $this->clientRow($email) ?? [];
        $full   = array_merge($cur, $client);
        $merged = self::cleanClient($full);
        $merged['email'] = $email;
        /* شمارهٔ ردیف پنل حذف و UUID واقعی جای آن می‌نشیند */
        $merged = Xui::normClient($merged, Xui::clientKey($full, $uuid));

        $r = $this->api('/clients/update/' . rawurlencode($email), $merged, 'POST');
        if (($r['success'] ?? false) !== true) {
            /* بعضی بیلدها بدنه را در قالب {client:{...}} می‌پذیرند؛ یک بار هم آن شکل تلاش می‌شود */
            $r2 = $this->api('/clients/update/' . rawurlencode($email), ['client' => $merged], 'POST');
            if (($r2['success'] ?? false) !== true) {
                return ['success' => false, 'msg' => (string)($r['msg'] ?? 'خطا در ویرایش کاربر در پنل')];
            }
            $r = $r2;
        }
        unset($this->linksCache[$email], $this->linksServed[$email]);
        return ['success' => true, 'obj' => $r['obj'] ?? null];
    }

    public function deleteClient(int $inboundId, string $uuid): array
    {
        $email = trim((string)($this->userMap[$uuid] ?? ''));
        if ($email === '') return ['success' => false, 'msg' => 'اکانت برای حذف پیدا نشد'];
        return $this->deleteByEmail($inboundId, $email);
    }

    /** حذف سراسری اکانت با ایمیل (از همهٔ اینباندها) */
    public function deleteByEmail(int $inboundId, string $email): array
    {
        $r = $this->api('/clients/del/' . rawurlencode($email), null, 'POST');
        if (($r['success'] ?? false) === true || (int)($r['code'] ?? 0) === 404) return ['success' => true];
        return ['success' => false, 'msg' => (string)($r['msg'] ?? 'حذف اکانت در پنل ناموفق بود')];
    }

    /** صفر کردن مصرف؛ نسل جدید همان‌جا اکانتِ مسدودشده را هم دوباره فعال می‌کند */
    public function resetTraffic(int $inboundId, string $email): array
    {
        $r = $this->api('/clients/resetTraffic/' . rawurlencode($email), null, 'POST');
        return ($r['success'] ?? false) === true
            ? ['success' => true]
            : ['success' => false, 'msg' => (string)($r['msg'] ?? 'ریست مصرف ناموفق بود')];
    }

    /* ---------------- فاز ۲: عملیات کلاینت‌محور نسل جدید ---------------- */

    /**
     * تمدید یا شارژ گروهی: افزودن روز یا گیگ به یک یا چند کلاینت.
     * پنل خودش کلاینتِ خارج‌شده از حالت اتمام را دوباره فعال می‌کند.
     */
    public function bulkAdjust(array $emails, int $addDays = 0, float $addGb = 0): array
    {
        $emails = array_values(array_filter(array_map('strval', $emails)));
        if (!$emails || ($addDays === 0 && $addGb <= 0)) {
            return ['success' => false, 'msg' => 'ورودی تمدید گروهی نامعتبر است'];
        }
        $body = ['emails' => $emails];
        if ($addDays !== 0) $body['addDays'] = $addDays;
        if ($addGb > 0) $body['addBytes'] = (int)round($addGb * 1073741824);
        $r = $this->api('/clients/bulkAdjust', $body, 'POST');
        return ($r['success'] ?? false) === true
            ? ['success' => true, 'obj' => $r['obj'] ?? null]
            : ['success' => false, 'msg' => (string)($r['msg'] ?? 'تمدید گروهی ناموفق بود')];
    }

    /** فهرست ایمیل کلاینت‌های آنلاین */
    public function onlines(): array
    {
        $r = $this->api('/clients/onlines', [], 'POST');
        if (($r['success'] ?? false) !== true) return [];
        $o = $r['obj'] ?? [];
        return is_array($o) ? array_values($o) : [];
    }

    /** آخرین زمان اتصال کلاینت‌ها */
    public function lastOnline(): array
    {
        $r = $this->api('/clients/lastOnline', [], 'POST');
        if (($r['success'] ?? false) !== true) return [];
        $o = $r['obj'] ?? [];
        return is_array($o) ? $o : [];
    }

    /** همهٔ کلاینت‌های متعلق به یک آیدی تلگرام؛ برای بازیابی سرویس‌ها و ضدتکرار اکانت تست */
    public function byTgId($tgId): array
    {
        $r = $this->api('/clients/get/tgId/' . rawurlencode((string)$tgId));
        if (($r['success'] ?? false) !== true) return [];
        $o = $r['obj'] ?? [];
        if (!is_array($o)) return [];
        if (isset($o['email'])) return [self::cleanClient((array)$o)];
        $out = [];
        foreach ($o as $c) {
            if (is_array($c)) $out[] = self::cleanClient($c);
        }
        return $out;
    }

    /** فعال یا غیرفعال کردن گروهی کلاینت‌ها */
    public function setEnabled(array $emails, bool $on): array
    {
        $emails = array_values(array_filter(array_map('strval', $emails)));
        if (!$emails) return ['success' => false, 'msg' => 'فهرست ایمیل خالی است'];
        $r = $this->api($on ? '/clients/bulkEnable' : '/clients/bulkDisable', ['emails' => $emails], 'POST');
        return ($r['success'] ?? false) === true
            ? ['success' => true]
            : ['success' => false, 'msg' => (string)($r['msg'] ?? 'تغییر وضعیت گروهی ناموفق بود')];
    }

    /* ---------------------- فاز ۳ و ۴: نگهداری و مانیتورینگ ---------------------- */

    /** حذف کلاینت‌های تمام‌شده (حجم یا زمان) — inboundId = -1 یعنی همهٔ اینباندها */
    public function delDepleted(int $inboundId = -1): array
    {
        $r = $this->api('/inbounds/delDepletedClients/' . $inboundId, [], 'POST');
        return ($r['success'] ?? false) === true
            ? ['success' => true, 'msg' => (string)($r['msg'] ?? '')]
            : ['success' => false, 'msg' => (string)($r['msg'] ?? 'حذف کلاینت‌های تمام‌شده ناموفق بود')];
    }

    /** آی‌پی‌های ثبت‌شدهٔ یک کلاینت (بررسی اشتراک‌گذاری اکانت) */
    public function clientIps(string $email): array
    {
        $r = $this->api('/inbounds/clientIps/' . rawurlencode($email), [], 'POST');
        if (($r['success'] ?? false) !== true) return [];
        $o = $r['obj'] ?? null;
        if (is_string($o)) {
            $d = jdec($o, null);
            if (is_array($d)) {
                $o = $d;
            } elseif (trim($o) === '' || stripos($o, 'no ip') !== false) {
                return [];
            } else {
                $o = preg_split('/[\s,]+/', trim($o)) ?: [];
            }
        }
        if (!is_array($o)) return [];
        $out = [];
        foreach ($o as $ip) {
            if (is_array($ip)) $ip = (string)($ip['ip'] ?? (reset($ip) ?: ''));
            $ip = trim((string)$ip);
            if ($ip !== '') $out[] = $ip;
        }
        return array_values(array_unique($out));
    }

    /** پاک کردن آی‌پی‌های ثبت‌شدهٔ کلاینت (آزاد شدن محدودیت دستگاه) */
    public function clearClientIps(string $email): array
    {
        $r = $this->api('/inbounds/clearClientIps/' . rawurlencode($email), [], 'POST');
        return ($r['success'] ?? false) === true
            ? ['success' => true]
            : ['success' => false, 'msg' => (string)($r['msg'] ?? 'پاک کردن آی‌پی‌ها ناموفق بود')];
    }

    /** وضعیت سرور (CPU / RAM / آپ‌تایم / Xray) — اول مسیر API نسل جدید، بعد مسیر قدیمی پنل */
    public function serverStatus(): ?array
    {
        $r = $this->api('/server/status');
        if (($r['success'] ?? false) !== true || !is_array($r['obj'] ?? null)) {
            $res = $this->curl($this->base() . '/server/status', '', 'POST');
            $j   = is_array($res['json']) ? $res['json'] : [];
            if (($j['success'] ?? false) !== true || !is_array($j['obj'] ?? null)) return null;
            $r = $j;
        }
        $o   = (array)$r['obj'];
        $mem = (array)($o['mem'] ?? []);
        $xr  = (array)($o['xray'] ?? []);
        $io  = (array)($o['netIO'] ?? []);
        $nt  = (array)($o['netTraffic'] ?? []);
        return [
            'cpu'        => (float)($o['cpu'] ?? 0),
            'mem_used'   => (int)($mem['current'] ?? 0),
            'mem_total'  => (int)($mem['total'] ?? 0),
            'uptime'     => (int)($o['uptime'] ?? 0),
            'xray_state' => (string)($xr['state'] ?? ''),
            'xray_ver'   => (string)($xr['version'] ?? ''),
            'net_up'     => (int)($io['up'] ?? 0),
            'net_down'   => (int)($io['down'] ?? 0),
            'sent'       => (int)($nt['sent'] ?? 0),
            'recv'       => (int)($nt['recv'] ?? 0),
        ];
    }

    /** آخرین اتصال یک کلاینت مشخص (ثانیه؛ ۰ = نامشخص) */
    public function lastOnlineOf(string $email): int
    {
        $map = $this->lastOnline();
        $v   = $map[$email] ?? 0;
        if (is_array($v)) $v = $v['lastOnline'] ?? ($v['time'] ?? 0);
        $v = (int)$v;
        if ($v > 100000000000) $v = (int)floor($v / 1000);
        return $v > 0 ? $v : 0;
    }

    public function clientTraffic(string $email): ?array
    {
        $r = $this->api('/clients/traffic/' . rawurlencode($email));
        if (($r['success'] ?? false) === true && !empty($r['obj'])) {
            $o = (array)$r['obj'];
            /* بعضی بیلدها به ازای هر اینباند یک ردیف برمی‌گردانند؛ جمع زده می‌شود */
            if (isset($o[0]) && is_array($o[0])) {
                $up = 0; $down = 0; $total = 0; $expiry = 0; $enable = false;
                foreach ($o as $row) {
                    if (!is_array($row)) continue;
                    $up   += (int)($row['up'] ?? 0);
                    $down += (int)($row['down'] ?? 0);
                    $t = (int)($row['total'] ?? 0);
                    if ($t > $total) $total = $t;
                    $e = (int)($row['expiryTime'] ?? 0);
                    if ($e > 0 && ($expiry === 0 || $e < $expiry)) $expiry = $e;
                    if (!empty($row['enable'])) $enable = true;
                }
                return ['email' => $email, 'up' => $up, 'down' => $down, 'total' => $total, 'expiryTime' => $expiry, 'enable' => $enable];
            }
            $o += ['email' => $email];
            return $o;
        }
        /* fallback: از خود ردیف کلاینت */
        $row = $this->clientRow($email);
        if (!$row) return null;
        return [
            'email'      => (string)($row['email'] ?? $email),
            'up'         => (int)($row['up'] ?? 0),
            'down'       => (int)($row['down'] ?? 0),
            'total'      => (int)($row['totalGB'] ?? ($row['total'] ?? 0)),
            'expiryTime' => (int)($row['expiryTime'] ?? 0),
            'enable'     => (bool)($row['enable'] ?? true),
        ];
    }

    /** مصرف زنده — در نسل جدید همان شمارندهٔ تجمیعی کلاینت است */
    public function liveTraffic(string $email): ?array
    {
        $t = $this->clientTraffic($email);
        if (!$t) return null;
        return [
            'email'      => (string)($t['email'] ?? $email),
            'up'         => (int)($t['up'] ?? 0),
            'down'       => (int)($t['down'] ?? 0),
            'total'      => (int)($t['total'] ?? 0),
            'expiryTime' => (int)($t['expiryTime'] ?? 0),
            'enable'     => (bool)($t['enable'] ?? true),
        ];
    }

    public function findClient(int $inboundId, string $email): ?array
    {
        return $this->clientRow($email);
    }

    /** ردیف اکانت + مصرف (سازگار با accountRow درایور قدیمی) */
    public function accountRow(string $email): ?array
    {
        $row = $this->clientRow($email);
        if (!$row) return null;
        $t = $this->clientTraffic($email);
        if (is_array($t)) {
            foreach (['up', 'down', 'total', 'expiryTime', 'enable'] as $k) {
                if (!isset($row[$k]) && isset($t[$k])) $row[$k] = $t[$k];
            }
        }
        return $row;
    }

    public function accountInboundIds(string $email): array
    {
        $row = $this->clientRow($email);
        if (!$row) return [];
        $ids = [];
        foreach ((array)($row['inboundIds'] ?? ($row['memberships'] ?? [])) as $m) {
            if (is_array($m))       $ids[] = (int)($m['inboundId'] ?? ($m['id'] ?? 0));
            elseif (is_numeric($m)) $ids[] = (int)$m;
        }
        return Xui::idList($ids);
    }

    /* ---------------------- لینک‌ها ---------------------- */

    /** همهٔ لینک‌های یک اکانت — رندرشده توسط خودِ پنل (الگوی remark پنل اعمال می‌شود) */
    public function linksFor(string $email): array
    {
        $email = trim($email);
        if ($email === '') return [];
        if (isset($this->linksCache[$email])) return $this->linksCache[$email];
        $r   = $this->api('/clients/links/' . rawurlencode($email));
        $out = [];
        if (($r['success'] ?? false) === true) {
            foreach ((array)($r['obj'] ?? []) as $l) {
                $l = trim(is_array($l) ? (string)($l['url'] ?? ($l['link'] ?? '')) : (string)$l);
                if ($l !== '' && preg_match(Xui::LINK_RE, $l)) $out[] = $l;
            }
        }
        return $this->linksCache[$email] = array_values(array_unique($out));
    }

    /**
     * سازگاری با حلقهٔ ساخت سرویس (به ازای هر اینباند یک بار صدا زده می‌شود):
     * هر بار فقط لینک‌هایی برمی‌گردند که قبلاً تحویل نشده‌اند تا لینک تکراری ساخته نشود.
     */
    public function buildConfigLink(array $inbound, string $uuid, string $email): string
    {
        unset($this->linksCache[$email]);            /* بعد از attach، لینک اینباند جدید هم بیاید */
        $links  = $this->linksFor($email);
        $served = (array)($this->linksServed[$email] ?? []);
        $new    = array_values(array_diff($links, $served));
        if (!$new) return '';
        $this->linksServed[$email] = array_merge($served, $new);
        return implode("\n", $new);
    }

    /** بازسازی کانفیگ‌ها — مستقیم از خود پنل */
    public function rebuildConfigs(string $email, string $uuid, array $extraInbounds = []): array
    {
        unset($this->linksCache[$email]);
        $links = $this->linksFor($email);
        if ($links) return $links;
        /* fallback: از ساب پنل */
        $row   = $this->clientRow($email);
        $subId = trim((string)($row['subId'] ?? ''));
        return $subId !== '' ? $this->subFetch($subId) : [];
    }

    public function subLink(string $subId): string
    {
        $base = trim((string)($this->panel['sub_base'] ?? ''));
        if ($base === '' || $subId === '') return '';
        return rtrim($base, '/') . '/' . $subId;
    }

    public function subUrlFor(string $email): string
    {
        $row   = $this->clientRow($email);
        $subId = trim((string)($row['subId'] ?? ''));
        return $subId !== '' ? $this->subLink($subId) : '';
    }

    /** خواندن کانفیگ‌های ساب؛ نسل جدید خروجی JSON بدون base64 هم دارد */
    public function subFetch(string $subId, string $subUrl = ''): array
    {
        $subId = trim($subId);
        if ($subId !== '') {
            $r = $this->api('/clients/subLinks/' . rawurlencode($subId));
            if (($r['success'] ?? false) === true) {
                $out = [];
                foreach ((array)($r['obj'] ?? []) as $l) {
                    $l = trim((string)$l);
                    if ($l !== '' && preg_match(Xui::LINK_RE, $l)) $out[] = $l;
                }
                if ($out) return array_values(array_unique($out));
            }
        }
        /* fallback: خواندن ساب عمومی مثل درایور قدیمی */
        $url = trim($subUrl) !== '' ? trim($subUrl) : $this->subLink($subId);
        if ($url === '') return [];
        $links = Xui::parseSubBody(Xui::httpGet($url));
        if ($links) return $links;
        return Xui::parseSubBody(Xui::httpGet(rtrim($url, '/') . '?b64=1'));
    }

    /* ---------------------- سلامت و تشخیص نسخه ---------------------- */

    /**
     * تست اتصال + تشخیص نسخه:
     * موفق‌بودن /inbounds/options یعنی پنل نسل جدید است؛ 404 یعنی پنل قدیمی است
     * و باید فیلد توکن خالی شود تا ربات از درایور قدیمی (یوزر/پسورد) استفاده کند.
     */
    /* ========== 0.0.2: newer 3x-ui API endpoints (docs.sanaei.dev) ========== */

    /* ========== 0.0.2 #happ-links : Happ deep link + external links ========== */

    /** دیپ‌لینک اختصاصی Happ برای یک اکانت: GET /clients/happLink/{id} */
    public function happLink(string $email): string
    {
        $email = trim($email);
        if ($email === '') return '';
        $row = $this->clientRow($email);
        $id  = (int)($row['id'] ?? 0);
        if ($id <= 0) return '';
        $r = $this->api('/clients/happLink/' . $id);
        if (($r['success'] ?? false) !== true) return '';
        $o = $r['obj'] ?? '';
        if (is_string($o)) return trim($o);
        if (is_array($o)) {
            foreach (['happLink', 'link', 'url', 'happ'] as $k) {
                if (isset($o[$k]) && is_string($o[$k]) && trim($o[$k]) !== '') return trim($o[$k]);
            }
        }
        return '';
    }

    /** لینک‌های خارجیِ ثبت‌شده روی پنل برای اکانت: GET /clients/{email}/externalLinks */
    public function externalLinks(string $email): array
    {
        $email = trim($email);
        if ($email === '') return [];
        $r = $this->api('/clients/' . rawurlencode($email) . '/externalLinks');
        if (($r['success'] ?? false) !== true) return [];
        $out = [];
        foreach ((array)($r['obj'] ?? []) as $row) {
            if (is_string($row)) {
                $l = trim($row);
                if ($l !== '') $out[] = ['title' => '', 'link' => $l];
                continue;
            }
            if (!is_array($row)) continue;
            $l = '';
            foreach (['link', 'url', 'href'] as $k) {
                if (isset($row[$k]) && is_string($row[$k]) && trim($row[$k]) !== '') {
                    $l = trim($row[$k]);
                    break;
                }
            }
            if ($l === '') continue;
            $t = '';
            foreach (['remark', 'title', 'name'] as $k) {
                if (isset($row[$k]) && is_string($row[$k]) && trim($row[$k]) !== '') {
                    $t = trim($row[$k]);
                    break;
                }
            }
            $out[] = ['title' => $t, 'link' => $l];
        }
        return $out;
    }

    /** registered HWID devices of one account: GET /clients/hwids/{email} */
    public function devices(string $email): array
    {
        $email = trim($email);
        if ($email === '') return [];
        $r = $this->api('/clients/hwids/' . rawurlencode($email));
        if (($r['success'] ?? false) !== true) return [];
        $out = [];
        foreach ((array)($r['obj'] ?? []) as $d) {
            if (!is_array($d)) continue;
            $out[] = [
                'id'   => (int)($d['id'] ?? 0),
                'hwid' => (string)($d['hwid'] ?? ''),
                'name' => trim((string)($d['deviceName'] ?? ($d['name'] ?? ''))),
                'os'   => trim((string)($d['os'] ?? ($d['platform'] ?? ''))),
                'app'  => trim((string)($d['appName'] ?? ($d['app'] ?? ''))),
                'seen' => (int)($d['updatedAt'] ?? ($d['createdAt'] ?? 0)),
            ];
        }
        return $out;
    }

    /** free one HWID slot: DELETE /clients/hwids/{email}/{id} */
    public function deviceDelete(string $email, int $id): array
    {
        $email = trim($email);
        if ($email === '' || $id <= 0) return ['success' => false, 'msg' => 'bad request'];
        return $this->api('/clients/hwids/' . rawurlencode($email) . '/' . $id, null, 'DELETE');
    }

    /** drop every registered device of one account; returns how many were removed */
    public function devicesClear(string $email): int
    {
        $n = 0;
        foreach ($this->devices($email) as $d) {
            $id = (int)($d['id'] ?? 0);
            if ($id <= 0) continue;
            $r = $this->deviceDelete($email, $id);
            if (($r['success'] ?? false) === true) $n++;
        }
        return $n;
    }

    /** set the device (HWID) limit of one or more accounts */
    public function setDeviceLimit(array $emails, int $limit): array
    {
        $emails = array_values(array_filter(array_map('strval', $emails), static fn($e) => trim($e) !== ''));
        if (!$emails) return ['success' => false, 'msg' => 'no emails'];
        return $this->api('/clients/bulkAdjust', [
            'emails'    => $emails,
            'limitHwid' => max(0, $limit),
        ], 'POST');
    }

    /** panel-wide counters: total / online / active / deactive / depleted / expiring */
    public function clientsSummary(): array
    {
        $r = $this->api('/clients/list?page=1&pageSize=1');
        if (($r['success'] ?? false) !== true) return [];
        $s = (array)(((array)($r['obj'] ?? []))['summary'] ?? []);
        if (!$s) return [];
        return [
            'total'    => (int)($s['total'] ?? 0),
            'online'   => (int)($s['onlineCount'] ?? 0),
            'active'   => (int)($s['active'] ?? 0),
            'deactive' => (int)($s['deactiveCount'] ?? 0),
            'depleted' => (int)($s['depletedCount'] ?? 0),
            'expiring' => (int)($s['expiringCount'] ?? 0),
        ];
    }

    /** delete clients that are no longer attached to any inbound */
    public function delOrphans(): int
    {
        $r = $this->api('/clients/delOrphans', [], 'POST');
        if (($r['success'] ?? false) !== true) return 0;
        return (int)(((array)($r['obj'] ?? []))['deleted'] ?? 0);
    }

    /** zero the counters of many accounts in one call; returns affected count */
    public function bulkResetTraffic(array $emails): int
    {
        $emails = array_values(array_filter(array_map('strval', $emails), static fn($e) => trim($e) !== ''));
        if (!$emails) return 0;
        $r = $this->api('/clients/bulkResetTraffic', ['emails' => $emails], 'POST');
        if (($r['success'] ?? false) !== true) return 0;
        return (int)(((array)($r['obj'] ?? []))['affected'] ?? 0);
    }

    /* 0.0.2 #x3-groups: ریست ترافیک گروهی و لاگ سرور — پنل نسل جدید سنایی */

    /** emails of one client group (subId group) */
    public function groupEmails(string $name): array
    {
        $name = trim($name);
        if ($name === '') return [];
        $r = $this->api('/clients/groups/' . rawurlencode($name) . '/emails');
        if (($r['success'] ?? false) !== true) return [];
        $out = [];
        foreach ((array)($r['obj'] ?? []) as $row) {
            if (is_string($row)) {
                $e = trim($row);
            } else {
                $row = (array)$row;
                $e = trim((string)($row['email'] ?? $row['name'] ?? ''));
            }
            if ($e !== '') $out[] = $e;
        }
        return array_values(array_unique($out));
    }

    /** zero the traffic counters of one or more client groups */
    public function groupResetTraffic(array $names): array
    {
        $names = array_values(array_filter(array_map('trim', array_map('strval', $names)), static fn($n) => $n !== ''));
        if (!$names) return ['ok' => false, 'affected' => 0, 'msg' => 'نام گروه خالی است'];
        $r = $this->api('/clients/groups/resetTraffic', ['groups' => $names], 'POST');
        if (($r['success'] ?? false) !== true) {
            $r2 = $this->api('/clients/groups/resetTraffic', ['names' => $names], 'POST');
            if (($r2['success'] ?? false) !== true) {
                return ['ok' => false, 'affected' => 0, 'msg' => (string)($r['msg'] ?? 'خطا در ریست گروهی')];
            }
            $r = $r2;
        }
        $o = (array)($r['obj'] ?? []);
        return ['ok' => true, 'affected' => (int)($o['affected'] ?? $o['count'] ?? 0)];
    }

    /** last lines of the panel server log */
    public function serverLogs(int $count = 50): array
    {
        $count = max(1, min(500, $count));
        $r = $this->api('/server/logs/' . $count);
        if (($r['success'] ?? false) !== true) $r = $this->api('/server/logs/' . $count, [], 'POST');
        if (($r['success'] ?? false) !== true) return [];
        $out = [];
        foreach ((array)($r['obj'] ?? []) as $row) {
            $line = is_string($row) ? $row : (string)(((array)$row)['line'] ?? ((array)$row)['msg'] ?? '');
            $line = trim($line);
            if ($line !== '') $out[] = $line;
        }
        return $out;
    }

    /** can per-client IP limits be enforced on this host? (needs Fail2ban) */
    public function ipLimitStatus(): array
    {
        $r = $this->api('/server/fail2banStatus');
        $o = (array)($r['obj'] ?? []);
        return [
            'ok'        => ($r['success'] ?? false) === true,
            'usable'    => !empty($o['usable']),
            'installed' => !empty($o['installed']),
            'enabled'   => !empty($o['enabled']),
        ];
    }

    /** is a newer 3x-ui release available for this panel? */
    public function panelUpdateInfo(): array
    {
        $r = $this->api('/server/getPanelUpdateInfo');
        if (($r['success'] ?? false) !== true) return [];
        $o = (array)($r['obj'] ?? []);
        return [
            'current'   => trim((string)($o['current'] ?? ($o['currentVersion'] ?? ($o['version'] ?? '')))),
            'latest'    => trim((string)($o['latest'] ?? ($o['latestVersion'] ?? ''))),
            'available' => !empty($o['hasUpdate']) || !empty($o['updateAvailable']) || !empty($o['available']),
        ];
    }

    public function healthCheck(): array
    {
        if ($this->token() === '') {
            return ['ok' => false, 'message' => 'توکن API ثبت نشده است. از «تنظیمات → امنیت → API Token» پنل یک توکن با سطح admin بسازید.'];
        }
        $r = $this->api('/inbounds/options');
        if (($r['success'] ?? false) !== true) {
            $code = (int)($r['code'] ?? 0);
            if ($code === 404) {
                $msg = 'این پنل API نسل جدید را ندارد (404). پنل را بروزرسانی کنید یا فیلد «توکن API» را خالی بگذارید تا ربات مثل قبل با یوزر/پسورد کار کند.';
            } elseif ($code === 401 || $code === 403) {
                $msg = 'توکن API نامعتبر است یا منقضی/غیرفعال شده (HTTP ' . $code . '). یک توکن جدید با سطح admin بسازید.';
            } else {
                $msg = 'اتصال ناموفق: ' . (string)($r['msg'] ?? ('HTTP ' . $code));
            }
            try {
                DB::update('panels', ['last_error' => mb_substr($msg, 0, 240)], 'id = :id', [':id' => (int)($this->panel['id'] ?? 0)]);
            } catch (Throwable $e) { }
            return ['ok' => false, 'message' => $msg];
        }

        $inbounds = $this->inbounds();
        $ids      = [];
        foreach ($inbounds as $i) $ids[] = (int)($i['id'] ?? 0);
        $extra = '';
        $st = $this->api('/server/status');
        if (($st['success'] ?? false) === true && is_array($st['obj'])) {
            $o  = (array)$st['obj'];
            $xr = (array)($o['xray'] ?? []);
            $v  = trim((string)($xr['version'] ?? ''));
            $s  = trim((string)($xr['state'] ?? ''));
            if ($v !== '') $extra .= ' • Xray: ' . $v;
            if ($s !== '') $extra .= ' (' . $s . ')';
        }
        try {
            DB::update('panels', ['last_error' => null], 'id = :id', [':id' => (int)($this->panel['id'] ?? 0)]);
        } catch (Throwable $e) { }
        return [
            'ok'          => true,
            'message'     => 'اتصال با توکن API (نسل جدید 3x-ui) موفق بود.' . $extra,
            'inbounds'    => $inbounds,
            'inbound_ids' => Xui::idList($ids),
        ];
    }
}
