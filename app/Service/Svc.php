<?php
declare(strict_types=1);

/**
 * مدیریت سرویس‌ها: ساخت، اکانت تست، تمدید، همگام‌سازی مصرف و حذف
 */
class Svc
{
    /* ---------------- نام کاربری ---------------- */

    /** روش‌های انتخاب نام کاربری */
    public const USERNAME_MODES = [
        'random'       => 'تصادفی (تولید خودکار)',
        'telegram_id'  => 'بر اساس آیدی عددی تلگرام',
        'tg_username'  => 'بر اساس نام کاربری تلگرام',
        'first_name'   => 'بر اساس نام کاربر + عدد',
        'product'      => 'نام محصول + شماره',
        'panel_prefix' => 'نام سرور + شمارهٔ ترتیبی',
        'sequential'   => 'شمارهٔ ترتیبی ساده',
        'date_rand'    => 'تاریخ روز + کد تصادفی',
        'phone'        => 'بر اساس شمارهٔ موبایل',
        'email'        => 'بر اساس ایمیل کاربر',
        'uuid'         => 'شناسهٔ یکتا (UUID کوتاه)',
        'word_num'     => 'کلمهٔ انگلیسی + عدد',
        'custom'       => 'انتخاب توسط کاربر (اجباری)',
        'custom_opt'   => 'انتخاب توسط کاربر (اختیاری)',
    ];

    /** روش‌های تمدید سرویس */
    public const RENEW_MODES = [
        'reset_extend' => 'ریست حجم + تمدید از امروز',
        'extend'       => 'فقط تمدید تاریخ (حجم باقی بماند)',
        'extend_today' => 'تمدید از امروز بدون دست‌زدن به حجم',
        'add_volume'   => 'افزودن حجم به حجم قبلی',
        'add_both'     => 'افزودن حجم و زمان به کانفیگ فعلی',
        'carry_over'   => 'انتقال حجم باقی‌مانده به دورهٔ جدید',
        'keep_larger'  => 'بدون کاهش (هرکدام از حجم و تاریخ بیشتر بود)',
        'reset_only'   => 'فقط ریست حجم (تاریخ انقضا دست‌نخورده)',
        'smart'        => 'هوشمند (تمام‌شده: ریست ، فعال: افزودن)',
        'recreate'     => 'ساخت کانفیگ جدید و حذف قبلی',
    ];

    /** توضیح کوتاه هر روش تمدید — زیر همان گزینه در پنل مدیریت نشان داده می‌شود */
    public const RENEW_MODE_HINTS = [
        'reset_extend' => 'مصرف صفر می‌شود، حجم برابر حجم محصول و تاریخ انقضا از همین امروز حساب می‌شود. مناسب فروش دوره‌ای.',
        'extend'       => 'فقط تاریخ تمدید می‌شود؛ روزهای جدید به انتهای تاریخ فعلی اضافه می‌شود و مصرف صفر نمی‌شود.',
        'extend_today' => 'تاریخ انقضا از امروز حساب می‌شود ولی حجم و مصرف فعلی کاربر دست‌نخورده می‌ماند.',
        'add_volume'   => 'حجم محصول به حجم فعلی اضافه می‌شود و تاریخ از انتهای دورهٔ فعلی جلو می‌رود.',
        'add_both'     => 'هم حجم و هم روزهای محصول روی کانفیگ فعلی اضافه می‌شود؛ چیزی از کاربر کم نمی‌شود.',
        'carry_over'   => 'حجم استفاده‌نشدهٔ دورهٔ قبل به حجم محصول اضافه می‌شود، مصرف صفر و تاریخ از امروز حساب می‌شود. منصفانه‌ترین حالت برای مشتری.',
        'keep_larger'  => 'بین مقدار فعلی و مقدار محصول، حجم بیشتر و تاریخ دورتر انتخاب می‌شود تا سرویس کاربر هیچ‌وقت کوچک‌تر نشود.',
        'reset_only'   => 'فقط مصرف صفر و حجم برابر حجم محصول می‌شود؛ تاریخ انقضای فعلی تغییری نمی‌کند. مناسب بسته‌های حجمی.',
        'smart'        => 'اگر سرویس منقضی یا حجمش تمام شده باشد مثل «ریست + تمدید از امروز» و در غیر این صورت مثل «افزودن حجم و زمان» عمل می‌کند.',
        'recreate'     => 'کانفیگ قبلی حذف و یک کانفیگ کاملاً تازه ساخته می‌شود؛ لینک اتصال کاربر عوض خواهد شد.',
    ];

    public static function usernameNeedsInput(array $panel): bool
    {
        return in_array((string)($panel['username_mode'] ?? 'random'), ['custom', 'custom_opt'], true);
    }

    /** آیا وارد کردن نام کاربری اختیاری است؟ */
    public static function usernameIsOptional(array $panel): bool
    {
        return (string)($panel['username_mode'] ?? 'random') === 'custom_opt';
    }

    public static function makeUsername(array $panel, array $user, ?array $product = null, ?string $custom = null): string
    {
        $prefix = trim((string)($panel['remark_prefix'] ?? ''));
        /* پیشوند خالی که به اشتباه رشتهٔ null ذخیره شده باشد نباید وارد نام کاربری شود */
        if (in_array(strtolower($prefix), ['null', 'undefined', 'nan'], true)) $prefix = '';
        $prefix = $prefix !== '' ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $prefix) . '-' : '';
        $mode   = (string)($panel['username_mode'] ?? 'random');

        // نام دلخواه کاربر (در حالت اجباری و اختیاری)
        if (($mode === 'custom' || $mode === 'custom_opt') && $custom !== null && trim($custom) !== '') {
            $clean = self::slug($custom, 24);
            if (strlen($clean) >= 3) return self::uniqueEmail($prefix . $clean);
        }

        switch ($mode) {
            case 'telegram_id':
                return self::uniqueEmail($prefix . 'u' . (int)($user['tg_id'] ?? 0));

            case 'tg_username':
                $un = self::slug((string)($user['username'] ?? ''), 20);
                if ($un !== '') return self::uniqueEmail($prefix . $un);
                break;

            case 'first_name':
                $fn = self::slug((string)($user['first_name'] ?? ''), 16);
                if ($fn !== '') return self::uniqueEmail($prefix . $fn . '-' . rnd(3, '0123456789'));
                break;

            case 'product':
                if ($product) {
                    $slug = self::slug((string)($product['name'] ?? ''), 12);
                    return self::uniqueEmail($prefix . ($slug !== '' ? $slug : 'srv'));
                }
                break;

            case 'panel_prefix':
                $pn = self::slug((string)($panel['name'] ?? ''), 10);
                $pf = $prefix !== '' ? $prefix : ($pn !== '' ? $pn . '-' : '');
                return self::uniqueEmail($pf . self::nextSeq());

            case 'sequential':
                return self::uniqueEmail($prefix . self::nextSeq());

            case 'date_rand':
                return self::uniqueEmail($prefix . date('ymd') . '-' . rnd(4));

            case 'phone':
                $ph = preg_replace('/\D/', '', en_num((string)($user['phone'] ?? ''))) ?? '';
                if (strlen($ph) >= 8) return self::uniqueEmail($prefix . 'p' . substr($ph, -9));
                break;

            case 'email':
                $parts = explode('@', (string)($user['email'] ?? ''));
                $lp    = self::slug((string)($parts[0] ?? ''), 18);
                if ($lp !== '') return self::uniqueEmail($prefix . $lp);
                break;

            case 'uuid':
                return self::uniqueEmail($prefix . substr(str_replace('-', '', uuidv4()), 0, 12));

            case 'word_num':
                $words = ['sky', 'nova', 'wave', 'swift', 'delta', 'orbit', 'pulse', 'lumen', 'vertex', 'zephyr', 'onyx', 'flux'];
                return self::uniqueEmail($prefix . $words[array_rand($words)] . rnd(3, '0123456789'));
        }

        return self::uniqueEmail($prefix . rnd(8));
    }

    /** پاک‌سازی رشته برای استفاده در نام کاربری */
    public static function slug(string $s, int $max = 20): string
    {
        $s = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', en_num(trim($s))) ?? '');
        return substr($s, 0, max(1, $max));
    }

    /** شمارهٔ ترتیبی بعدی بر اساس تعداد سرویس‌های ساخته‌شده */
    private static function nextSeq(): string
    {
        $n = (int)DB::val('SELECT COUNT(*) FROM {p}services', [], 0) + 1;
        return str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }

    /** تضمین یکتا بودن نام کاربری (email در پنل) */
    public static function uniqueEmail(string $base): string
    {
        $base = trim($base, '-_') ?: rnd(8);
        $try  = $base;
        for ($i = 1; $i <= 30; $i++) {
            $exists = DB::val('SELECT COUNT(*) FROM {p}services WHERE client_email = :e', [':e' => $try]);
            if ((int)$exists === 0) return $try;
            $try = $base . '-' . rnd(3, '0123456789');
        }
        return $base . '-' . rnd(6);
    }

    public static function usernameIsFree(string $name): bool
    {
        return (int)DB::val('SELECT COUNT(*) FROM {p}services WHERE client_email = :e', [':e' => $name]) === 0;
    }

    /* ---------------- محدودیت‌ها ---------------- */

    /** محدودیت تعداد کاربر ساخته‌شده در پنل (۱- = نامحدود) */
    public static function panelHasCapacity(array $panel): bool
    {
        $limit = (int)($panel['user_limit'] ?? -1);
        if ($limit < 0) return true;
        $active = (int)DB::val('SELECT COUNT(*) FROM {p}services WHERE panel_id = :p AND status <> :s',
            [':p' => (int)$panel['id'], ':s' => 'deleted']);
        return $active < $limit;
    }

    public static function panelUsage(int $panelId): int
    {
        return (int)DB::val('SELECT COUNT(*) FROM {p}services WHERE panel_id = :p AND status <> :s',
            [':p' => $panelId, ':s' => 'deleted']);
    }

    public static function pickInbound(array $panel, ?int $preferred = null): int
    {
        $allowed = (new Xui($panel))->allowedInboundIds();
        if ($preferred && (!$allowed || in_array($preferred, $allowed, true))) return $preferred;
        if ($allowed) return $allowed[array_rand($allowed)];
        return 0;
    }

    /** تبدیل رشته «۱,۲» به لیست عددی اینباندها */
    public static function parseIds($raw): array
    {
        $raw = trim((string)$raw);
        if ($raw === '') return [];
        $ids = array_filter(array_map('intval', preg_split('/[\\s,;]+/', en_num($raw)) ?: []));
        return array_values(array_unique($ids));
    }

    /**
     * اینباندهای هدف برای ساخت سرویس
     * اگر محصول اینباند مشخصی داشته باشد فقط همان، وگرنه همه اینباندهای مجاز پنل
     */
    public static function inboundList(array $panel, array $opts = [], ?array $product = null): array
    {
        $xui      = new Xui($panel);
        $allowed  = $xui->allowedInboundIds();
        $explicit = self::parseIds($opts['inbound_id'] ?? '');
        if (!$explicit && $product) $explicit = self::parseIds($product['inbound_id'] ?? '');
        if ($explicit) {
            $both = $allowed ? array_values(array_intersect($explicit, $allowed)) : $explicit;
            /* پنل تک‌اینباند (سنایی قدیم): فقط اولین اینباند */
            return $xui->limitIds($both ?: $explicit);
        }
        return $xui->limitIds($allowed);
    }

    /* ---------------- ساخت سرویس ---------------- */

    /**
     * ساخت سرویس جدید روی پنل
     * @return array{ok:bool,message:string,service?:array}
     */
    public static function create(array $user, array $panel, array $opts): array
    {
        $volume = (float)($opts['volume_gb'] ?? 0);
        $days   = (int)($opts['days'] ?? 0);
        $hours  = (int)($opts['hours'] ?? 0);
        $ipLim  = (int)($opts['ip_limit'] ?? 0);
        $isTest = !empty($opts['is_test']);
        $product = $opts['product'] ?? null;

        /* محدودیت دستگاه و سرعت – از گزینه‌ها یا از خود محصول */
        $devOpt = (int)($opts['device_limit'] ?? 0);
        if ($devOpt <= 0 && is_array($product)) $devOpt = (int)($product['device_limit'] ?? 0);
        $upKb = (int)($opts['speed_up'] ?? 0);
        if ($upKb <= 0 && is_array($product)) $upKb = (int)($product['speed_up'] ?? 0);
        $downKb = (int)($opts['speed_down'] ?? 0);
        if ($downKb <= 0 && is_array($product)) $downKb = (int)($product['speed_down'] ?? 0);

        if ((int)($panel['active'] ?? 1) !== 1) return ['ok' => false, 'message' => 'این سرور موقتاً غیرفعال است.'];
        if (!self::panelHasCapacity($panel)) return ['ok' => false, 'message' => 'ظرفیت ساخت کاربر در این سرور تکمیل شده است.'];

        $targets = self::inboundList($panel, $opts, $product);
        // اکانت تست هم مانند محصول می‌تواند روی چند اینباند ساخته شود.
        // فقط اگر مدیر صراحتاً «تک اینباند» را انتخاب کرده باشد محدود می‌شود.
        if ($isTest && $targets && !empty($opts['single_inbound'])) $targets = [(int)$targets[0]];
        if (!$targets) return ['ok' => false, 'message' => 'کد اینباوند برای این سرور تنظیم نشده است.'];

        $xui   = new Xui($panel);
        /* fixed79: گروه‌های اختصاصی محصول برای پاسارگارد (ستون products.group_ids) */
        if (is_array($product) && trim((string)($product['group_ids'] ?? '')) !== '' && method_exists($xui, 'isPasarGuard') && $xui->isPasarGuard()) {
            try { $pgd = $xui->pasarguard(); if ($pgd && method_exists($pgd, 'setGroupOverride')) $pgd->setGroupOverride((string)$product['group_ids']); } catch (Throwable $e) { }
        }
        $email = self::makeUsername($panel, $user, $product, $opts['username'] ?? null);
        /* مرزبان و پاسارگارد فقط a-z و 0-9 و آندرلاین (3 تا 32 کاراکتر) را می‌پذیرند */
        if ((method_exists($xui, 'isMarzban') && $xui->isMarzban()) || (method_exists($xui, 'isPasarGuard') && $xui->isPasarGuard())) {
            $email = Marzban::safeName($email);
            if (!self::usernameIsFree($email)) $email = Marzban::safeName(self::uniqueEmail($email));
        }
        $uuid  = uuidv4();
        /* ساب مشترک: اگر فراخوان یک subId مشترک داده باشد همان استفاده می‌شود */
        $subId = trim((string)($opts['sub_id'] ?? ''));
        if ($subId === '') $subId = rnd(16);

        $totalSeconds = ($days * 86400) + ($hours * 3600);
        $expireTs = $totalSeconds > 0 ? time() + $totalSeconds : 0;
        $expiryMs = $expireTs > 0 ? $expireTs * 1000 : 0;

        /* ردپای دقیق مقادیر ارسالی به پنل (برای ردیابی مشکل حجم/زمان دلخواه) */
        app_log('svc', 'create', [
            'panel' => (int)($panel['id'] ?? 0), 'type' => (string)($panel['type'] ?? ''),
            'email' => $email, 'gb' => $volume, 'days' => $days, 'hours' => $hours,
            'exp' => $expireTs > 0 ? date('Y-m-d H:i:s', $expireTs) : 'never', 'ip' => $ipLim, 'test' => $isTest ? 1 : 0,
        ]);

        // ساخت کلاینت روی همه اینباندهای هدف: چند کانفیگ با یک لینک اشتراک
        $links = []; $okIds = []; $lastErr = '';
        $devLim = $devOpt;

        if ($xui->isVpnUi()) {
            /* VPN-UI: یک اکانت مشترک روی همه اینباندها؛ یک ساب با چند کانفیگ */
            $ids     = Xui::idList($targets);
            $primary = (int)($ids[0] ?? 0);
            $res     = $xui->addClient($primary, $email, $uuid, $volume, $expiryMs, $ipLim, $subId, $ids, $devLim, $upKb, $downKb);
            if (empty($res['success'])) {
                $lastErr = (string)($res['msg'] ?? 'نامشخص');
            } else {
                foreach ($ids as $ib) {
                    $ib = (int)$ib;
                    $okIds[] = $ib;
                    try {
                        $inbound = ($ib === $primary && !empty($res['inbound'])) ? (array)$res['inbound'] : ($xui->inbound($ib) ?? []);
                        $link = $inbound ? (string)$xui->buildConfigLink($inbound, $uuid, $email) : '';
                        if (trim($link) !== '') $links[] = trim($link);
                    } catch (Throwable $e) {
                        app_log('svc', 'buildConfigLink: ' . $e->getMessage(), ['inbound' => $ib]);
                    }
                }
            }
        } else {
            foreach ($targets as $ib) {
                $ib = (int)$ib;
                if ($ib <= 0) continue;
                $res = $xui->addClient($ib, $email, $uuid, $volume, $expiryMs, $ipLim, $subId, [], $devLim, $upKb, $downKb);
                if (empty($res['success'])) { $lastErr = (string)($res['msg'] ?? 'نامشخص'); continue; }
                $okIds[] = $ib;
                try {
                    $inbound = $res['inbound'] ?? $xui->inbound($ib) ?? [];
                    $link = $inbound ? (string)$xui->buildConfigLink($inbound, $uuid, $email) : '';
                    if (trim($link) !== '') $links[] = trim($link);
                } catch (Throwable $e) {
                    app_log('svc', 'buildConfigLink: ' . $e->getMessage(), ['inbound' => $ib]);
                }
            }
        }
        if (!$okIds) {
            return ['ok' => false, 'message' => 'خطا در ساخت کانفیگ: ' . ($lastErr !== '' ? $lastErr : 'نامشخص')];
        }

        $inboundId = (int)$okIds[0];
        $config    = implode("\n", array_values(array_unique($links)));
        /* لینک اشتراک: در سرویس‌های چندپنلی، ساب اختصاصی خود ربات جایگزین می‌شود */
        $sub = trim((string)($opts['sub_link'] ?? ''));
        if ($sub === '') $sub = $xui->subLink($subId);
        /* مرزبان: آدرس ساب توکن‌دار را فقط خود پنل می‌دهد */
        if ($sub === '' && method_exists($xui, 'subUrlFor')) {
            try { $sub = trim((string)$xui->subUrlFor($email)); }
            catch (Throwable $e) { $sub = ''; }
        }

        $row = [
            'user_id'     => (int)$user['id'],
            'tg_id'       => (int)$user['tg_id'],
            'panel_id'    => (int)$panel['id'],
            'product_id'  => $product['id'] ?? null,
            'inbound_id'  => $inboundId,
            'client_uuid' => $uuid,
            'client_email'=> $email,
            'sub_id'      => $subId,
            'sub_link'    => $sub,
            'config_link' => $config,
            'volume_gb'   => $volume,
            'days'        => $days,
            'expire_at'   => $expireTs > 0 ? date('Y-m-d H:i:s', $expireTs) : null,
            'is_test'     => $isTest ? 1 : 0,
            'status'      => 'active',
            'created_at'  => now(),
        ];

        /* گروه سرویس (حجم مشترک بین چند پنل) – فقط اگر مایگریشن ۰۰۱۱ اجرا شده باشد */
        $groupKey = trim((string)($opts['group_key'] ?? ''));
        if ($groupKey !== '') {
            $row['group_key']      = mb_substr($groupKey, 0, 32);
            $row['group_quota_gb'] = (float)($opts['group_quota_gb'] ?? 0);
        }

        try {
            $serviceId = DB::insert('services', $row);
        } catch (Throwable $e) {
            unset($row['group_key'], $row['group_quota_gb']);
            $serviceId = DB::insert('services', $row);
        }
        DB::q('UPDATE {p}panels SET users_created = users_created + 1 WHERE id = :id', [':id' => (int)$panel['id']]);
        if ($product) DB::q('UPDATE {p}products SET sold = sold + 1 WHERE id = :id', [':id' => (int)$product['id']]);

        return ['ok' => true, 'message' => 'سرویس ساخته شد.', 'service' => self::find($serviceId)];
    }

    /**
     * اینباندهای اکانت تست
     * از ستون چندمقداری جدید می‌خواند و اگر خالی بود، با ستون تک‌مقداری قدیمی سازگار می‌ماند.
     */
    /** fixed79: حجم اکانت تست به گیگ — test_volume_mb (مگابایت) اگر > ۰ باشد بر test_volume_gb اولویت دارد */
    public static function testVolumeGb(array $panel): float
    {
        $mb = (int)($panel['test_volume_mb'] ?? 0);
        if ($mb > 0) return $mb / 1024;
        return max(0.0, (float)($panel['test_volume_gb'] ?? 0));
    }

    /** fixed79: برچسب حجم تست («۵۰۰ مگابایت» / «۱ گیگ»)؛ خالی = نامحدود */
    public static function testVolumeLabel(array $panel): string
    {
        $mb = (int)($panel['test_volume_mb'] ?? 0);
        if ($mb > 0) return fa_num((string)$mb) . ' مگابایت';
        $gb = (float)($panel['test_volume_gb'] ?? 0);
        return $gb > 0 ? fa_num((string)(float)$gb) . ' گیگ' : '';
    }

    /** fixed79: نمایش حجم — زیر ۱ گیگ به مگابایت */
    public static function volLabel(float $gb): string
    {
        if ($gb <= 0) return 'نامحدود';
        if ($gb < 1) return fa_num((string)(int)round($gb * 1024)) . ' مگابایت';
        return fa_num((string)round($gb, 2)) . ' گیگ';
    }

    /** fixed79: نوار مصرف متنی (۱۰ خانه) — خالی برای حجم نامحدود */
    public static function bar(float $gb, int $usedBytes): string
    {
        if ($gb <= 0) return '';
        $pct = (int)min(100, max(0, round($usedBytes / ($gb * 1073741824) * 100)));
        $n   = (int)min(10, max(0, round($pct / 10)));
        return "\n" . '<code>' . str_repeat('▰', $n) . str_repeat('▱', 10 - $n) . '</code> ' . fa_num((string)$pct) . '٪ مصرف';
    }

    public static function testInbounds(array $panel): string
    {
        $multi = trim((string)($panel['test_inbound_ids'] ?? ''));
        if ($multi !== '') return $multi;
        $legacy = (int)($panel['test_inbound_id'] ?? 0);
        return $legacy > 0 ? (string)$legacy : '';
    }

    /** ساخت اکانت تست با رعایت محدودیت‌ها */
    public static function createTest(array $user, ?array $panel = null): array
    {
        if ((string)DB::setting('test_enabled', '1') !== '1') {
            return ['ok' => false, 'message' => 'امکان دریافت اکانت تست فعلاً غیرفعال است.'];
        }
        if (!$panel) {
            $panel = DB::one('SELECT * FROM {p}panels WHERE active = 1 AND test_enabled = 1 ORDER BY sort ASC, id ASC LIMIT 1');
        }
        if (!$panel) return ['ok' => false, 'message' => 'سروری برای اکانت تست فعال نیست.'];

        $limit = (int)($panel['test_limit'] ?? 1);
        $used  = (int)DB::val('SELECT COUNT(*) FROM {p}services WHERE user_id = :u AND is_test = 1 AND panel_id = :p',
            [':u' => (int)$user['id'], ':p' => (int)$panel['id']]);
        if ($limit >= 0 && $used >= $limit) {
            return self::testDeny($user, $panel, 'panel_limit', 'شما سهمیه اکانت تست خود را استفاده کرده‌اید (سقف: ' . fa_num($limit) . ').');
        }

        /* fixed75: قواعد ضدسوءاستفاده (همه از تنظیمات؛ ۰ = خاموش) */
        $deny = self::testAbuseCheck($user, $panel);
        if ($deny !== null) return $deny;

        $r = self::create($user, $panel, [
            'volume_gb'  => self::testVolumeGb($panel), /* fixed79: مگابایت یا گیگ */
            'days'       => (int)$panel['test_days'],
            'hours'      => (int)$panel['test_hours'],
            'inbound_id'     => self::testInbounds($panel),
            'single_inbound' => (int)($panel['test_single_inbound'] ?? 0) === 1,
            'is_test'        => true,
            'ip_limit'       => 1,
        ]);
        if ($r['ok']) {
            DB::q('UPDATE {p}users SET test_count = test_count + 1 WHERE id = :id', [':id' => (int)$user['id']]);
        }
        return $r;
    }

    /**
     * fixed75 — قواعد ضدسوءاستفادهٔ اکانت تست. null = مجاز؛ در غیر این صورت پاسخ خطا.
     *  test_global_max      سقف کل تست هر کاربر روی همهٔ سرورها (users.test_count)
     *  test_cooldown_days   فاصلهٔ حداقل بین دو تست
     *  test_min_age_hours   حداقل عمر حساب کاربر در ربات
     *  test_phone_unique    هر شمارهٔ موبایل تاییدشده فقط یک تست (جلوگیری از اکانت تلگرام تکراری)
     *  test_panel_check     بررسی مستقیم پنل 3x-ui با آیدی تلگرام (کلاینت قدیمی حتی اگر از ربات پاک شده باشد)
     */
    public static function testAbuseCheck(array $user, array $panel): ?array
    {
        $uid = (int)$user['id'];

        $gmax = (int)DB::setting('test_global_max', '0');
        if ($gmax > 0 && (int)($user['test_count'] ?? 0) >= $gmax) {
            return self::testDeny($user, $panel, 'global_max', 'سقف کل اکانت تست برای شما پر شده است (' . fa_num($gmax) . ' بار).');
        }

        $cd = (int)DB::setting('test_cooldown_days', '0');
        if ($cd > 0) {
            $perPanel = (string)DB::setting('test_cooldown_per_panel', '0') === '1'; /* fixed79: فاصلهٔ تست جدا برای هر سرور */
            $last = $perPanel
                ? (string)DB::val('SELECT MAX(created_at) FROM {p}services WHERE user_id = :u AND is_test = 1 AND panel_id = :p', [':u' => $uid, ':p' => (int)$panel['id']])
                : (string)DB::val('SELECT MAX(created_at) FROM {p}services WHERE user_id = :u AND is_test = 1', [':u' => $uid]);
            if ($last !== '') {
                $until = strtotime($last) + $cd * 86400;
                if ($until > time()) {
                    return self::testDeny($user, $panel, 'cooldown', 'بین دو اکانت تست باید ' . fa_num($cd) . ' روز فاصله باشد. زمان باقی‌مانده: ' . remaining_human(date('Y-m-d H:i:s', $until)));
                }
            }
        }

        $age = (int)DB::setting('test_min_age_hours', '0');
        if ($age > 0 && !empty($user['created_at'])) {
            $born = strtotime((string)$user['created_at']);
            if ($born && time() - $born < $age * 3600) {
                return self::testDeny($user, $panel, 'min_age', 'اکانت تست برای حساب‌های تازه فعال نیست. ' . remaining_human(date('Y-m-d H:i:s', $born + $age * 3600)) . ' دیگر دوباره تلاش کنید.');
            }
        }

        if ((string)DB::setting('test_phone_unique', '1') === '1') {
            $phone = preg_replace('/\D+/', '', (string)($user['phone'] ?? ''));
            if ($phone !== '' && strlen($phone) >= 8) {
                $tail = substr($phone, -10);
                $other = (int)DB::val(
                    'SELECT COUNT(*) FROM {p}services s JOIN {p}users u ON u.id = s.user_id'
                    . ' WHERE s.is_test = 1 AND u.id <> :u AND u.phone IS NOT NULL AND u.phone <> \'\''
                    . ' AND REPLACE(REPLACE(REPLACE(u.phone, \'+\', \'\'), \' \', \'\'), \'-\', \'\') LIKE :t',
                    [':u' => $uid, ':t' => '%' . $tail]
                );
                if ($other > 0) {
                    return self::testDeny($user, $panel, 'phone_dup', 'با این شمارهٔ موبایل قبلاً اکانت تست دریافت شده است.');
                }
            }
        }

        if ((string)DB::setting('test_panel_check', '1') === '1' && (int)($user['test_count'] ?? 0) === 0) {
            try {
                $x = new Xui($panel);
                if ($x->supports('ips')) {
                    $found = $x->clientsByTgId((int)($user['tg_id'] ?? 0));
                    if ($found) {
                        return self::testDeny($user, $panel, 'panel_tgid', 'برای حساب تلگرام شما قبلاً روی این سرور کانفیگ ساخته شده است؛ اکانت تست فقط برای کاربران جدید است.');
                    }
                }
            } catch (\Throwable $e) {
                app_log('test', 'panel check skipped: ' . $e->getMessage());
            }
        }
        return null;
    }

    /** پاسخ رد + ثبت در لاگ اقدامات (قابل خاموش‌کردن با test_log_block) */
    private static function testDeny(array $user, array $panel, string $why, string $msg): array
    {
        if ((string)DB::setting('test_log_block', '1') === '1' && class_exists('Audit')) {
            Audit::log('test.blocked', ['why' => $why, 'uid' => (int)$user['id'], 'tg' => (int)($user['tg_id'] ?? 0), 'panel' => (string)($panel['name'] ?? '')], 'system');
        }
        return ['ok' => false, 'message' => $msg];
    }

    /* ---------------- تمدید ---------------- */

    /**
     * تمدید سرویس مطابق روش تمدید پنل
     */
    public static function renew(array $service, array $product): array
    {
        $panel = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)$service['panel_id']]);
        if (!$panel) return ['ok' => false, 'message' => 'پنل مربوطه یافت نشد.'];
        $mode = (string)($panel['renew_mode'] ?? 'reset_extend');
        if (!array_key_exists($mode, self::RENEW_MODES)) $mode = 'reset_extend';
        $xui  = new Xui($panel);
        $days = (int)$product['days'];
        $vol  = (float)$product['volume_gb'];

        if ($mode === 'recreate') {
            $user = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)$service['user_id']]);
            $new  = self::create($user, $panel, [
                'volume_gb' => $vol, 'days' => $days, 'product' => $product,
                'ip_limit'  => (int)$product['ip_limit'], 'inbound_id' => (int)$service['inbound_id'],
            ]);
            if (!$new['ok']) return $new;
            self::remove($service, false);
            return ['ok' => true, 'message' => 'کانفیگ جدید ساخته شد.', 'service' => $new['service'], 'recreated' => true];
        }

        $client = $xui->findClient((int)$service['inbound_id'], (string)$service['client_email']);
        if (!$client) return ['ok' => false, 'message' => 'کاربر در پن�� یافت نشد. لطفاً با پشتیبانی تماس بگیرید.'];

        $nowTs    = time();
        $curExpTs = strtotime((string)($service['expire_at'] ?? '')) ?: 0;
        $curTotal = (float)bytes2gb((int)($client['totalGB'] ?? 0), 4);
        $usedGb   = self::usedGb($xui, $service);
        $remainGb = $curTotal > 0 ? max(0.0, $curTotal - $usedGb) : 0.0;

        /* حالت هوشمند: سرویس تمام شده مثل ریست و سرویس زنده مثل افزودن رفتار می کند */
        if ($mode === 'smart') {
            $expired = ($curExpTs > 0 && $curExpTs <= $nowTs);
            $drained = ($curTotal > 0 && $usedGb >= $curTotal);
            $mode    = ($expired || $drained) ? 'reset_extend' : 'add_both';
        }

        $doReset = in_array($mode, ['reset_extend', 'reset_only', 'carry_over'], true);
        $newDays = $days;

        /* ---------- تاریخ انقضای تازه ---------- */
        switch ($mode) {
            case 'reset_only':
                /* فقط حجم ریست می شود و تاریخ دست نخورده می ماند */
                $newExpire = $curExpTs;
                $newDays   = (int)($service['days'] ?? $days);
                break;

            case 'reset_extend':
            case 'extend_today':
            case 'carry_over':
                $newExpire = $days > 0 ? $nowTs + ($days * 86400) : 0;
                break;

            case 'keep_larger':
                /* هرگز کمتر از وضعیت فعلی کاربر نمی شود */
                if ($days <= 0 || $curExpTs <= 0) {
                    $newExpire = 0;
                } else {
                    $fromNow   = $nowTs + ($days * 86400);
                    $newExpire = $curExpTs > $fromNow ? $curExpTs : $fromNow;
                }
                break;

            default:
                /* extend ، add_volume ، add_both : ادامه از انتهای دوره فعلی */
                $baseTs    = $curExpTs > $nowTs ? $curExpTs : $nowTs;
                $newExpire = $days > 0 ? $baseTs + ($days * 86400) : 0;
        }

        /* ---------- حجم تازه ---------- */
        switch ($mode) {
            case 'add_volume':
            case 'add_both':
                $newVolume = $curTotal > 0 ? $curTotal + $vol : $vol;
                break;

            case 'carry_over':
                /* حجم مصرف نشده دوره قبل به حجم محصول اضافه می شود */
                $newVolume = $vol > 0 ? round($vol + $remainGb, 4) : 0.0;
                break;

            case 'keep_larger':
                $newVolume = ($vol <= 0 || $curTotal <= 0) ? 0.0 : max($curTotal, $vol);
                break;

            case 'extend_today':
                /* حجم و مصرف دست نخورده می ماند */
                $newVolume = $curTotal;
                break;

            default:
                /* reset_extend ، reset_only ، extend */
                $newVolume = $vol;
        }

        $client['totalGB']    = $newVolume > 0 ? gb2bytes($newVolume) : 0;
        $client['expiryTime'] = $newExpire > 0 ? $newExpire * 1000 : 0;
        $client['enable']     = true;
        $uuidKey = (string)($client['id'] ?? ($client['password'] ?? $service['client_uuid']));

        /* محدودیت سرعت محصول در تمدید هم دوباره اعمال می شود */
        $rUp   = max(0, (int)($product['speed_up'] ?? 0));
        $rDown = max(0, (int)($product['speed_down'] ?? 0));
        foreach (Xui::speedKeys($rUp, $rDown) as $sk => $sv) $client[$sk] = $sv;

        $upd = $xui->updateClient((int)$service['inbound_id'], $uuidKey, $client);
        if (($upd['success'] ?? false) !== true) {
            return ['ok' => false, 'message' => 'خطا در تمدید: ' . (string)($upd['msg'] ?? 'نامشخص')];
        }
        if ($doReset) {
            $xui->resetTraffic((int)$service['inbound_id'], (string)$service['client_email']);
        }

        DB::update('services', [
            'volume_gb'   => $newVolume,
            'days'        => $newDays,
            'expire_at'   => $newExpire > 0 ? date('Y-m-d H:i:s', $newExpire) : null,
            'status'      => 'active',
            'is_test'     => 0,
            'product_id'  => (int)$product['id'],
            'notified'    => null,
            'used_bytes'  => $doReset ? 0 : (int)$service['used_bytes'],
            'renew_count' => (int)$service['renew_count'] + 1,
        ], 'id = :id', [':id' => (int)$service['id']]);

        return ['ok' => true, 'message' => 'سرویس تمدید شد.', 'service' => self::find((int)$service['id'])];
    }

    /** مصرف فعلی سرویس بر حسب گیگابایت — اول از پنل، در نبود پاسخ از دیتابیس */
    private static function usedGb(Xui $xui, array $service): float
    {
        $bytes = 0;
        try {
            if (method_exists($xui, 'liveTraffic')) {
                $t = $xui->liveTraffic((string)($service['client_email'] ?? ''));
                if (is_array($t)) $bytes = (int)($t['up'] ?? 0) + (int)($t['down'] ?? 0);
            }
        } catch (Throwable $e) {
            $bytes = 0;
        }
        if ($bytes <= 0) $bytes = (int)($service['used_bytes'] ?? 0);
        return (float)bytes2gb($bytes, 4);
    }

    /* ---------------- همگام‌سازی و حذف ---------------- */

    public static function sync(array $service): array
    {
        $sid = (int)($service['id'] ?? 0);
        if ($sid <= 0) return $service;

        $stamp = static function (int $id) {
            try { DB::update('services', ['last_sync' => now()], 'id = :id', [':id' => $id]); }
            catch (Throwable $e) { }
        };

        $panel = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)($service['panel_id'] ?? 0)]);
        if (!$panel) { $stamp($sid); return $service; }

        $xui = new Xui($panel);

        /*
         * liveTraffic مجموع ترافیک همه اینباندهای همین اکانت را برمی گرداند.
         * در پنل های VPN-UI یک اکانت روی چند اینباند مشترک است و خواندن تنها
         * یک اینباند باعث می شد مصرف همیشه صفر بماند.
         */
        try {
            $t = method_exists($xui, 'liveTraffic')
                ? $xui->liveTraffic((string)($service['client_email'] ?? ''))
                : $xui->clientTraffic((string)($service['client_email'] ?? ''));
        } catch (Throwable $e) {
            app_log('svc', 'sync: ' . $e->getMessage(), ['svc' => $sid]);
            $t = null;
        }

        /* زمان تلاش همیشه ثبت می شود تا حلقه همگام سازی روی یک ردیف قفل نشود */
        /*
         * اگر پنل جواب داد ولی این اکانت داخلش نبود، یعنی کانفیگ دستی از پنل
         * حذف شده است. در این حالت پس از دو بار تایید، وضعیت داخل ربات هم به
         * «حذف‌شده از پنل» تغییر می‌کند تا مینی‌اپ آن را زنده نشان ندهد و کاربر
         * پیام «کانفیگ پیدا نشد» نگیرد. اگر خود پنل در دسترس نباشد هیچ وضعیتی
         * دستکاری نمی‌شود.
         */
        if (!$t) {
            $alive = false;
            try { $alive = $xui->inbounds() !== []; } catch (Throwable $e) { $alive = false; }
            if (!$alive) { $stamp($sid); return array_merge($service, ['last_sync' => now()]); }

            $miss = (int)($service['panel_miss'] ?? 0) + 1;
            $cur  = (string)($service['status'] ?? '');
            $data = ['panel_miss' => $miss, 'last_sync' => now()];

            if ($miss >= 2 && $cur !== 'deleted' && $cur !== 'missing') {
                $data['status'] = 'missing';
                app_log('svc', 'panel client missing -> marked', [
                    'svc'   => $sid,
                    'email' => (string)($service['client_email'] ?? ''),
                ]);
                try {
                    if (class_exists('Logs')) {
                        Logs::send('service', "🚫 کانفیگ در پنل پیدا نشد و «حذف‌شده از پنل» علامت خورد\n"
                            . 'کد: ' . $sid . "\n" . 'نام کاربری: ' . (string)($service['client_email'] ?? ''));
                    }
                } catch (Throwable $e) { }
            }

            try { DB::update('services', $data, 'id = :id', [':id' => $sid]); }
            catch (Throwable $e) {
                unset($data['panel_miss']);
                try { DB::update('services', $data, 'id = :id', [':id' => $sid]); } catch (Throwable $e2) { }
            }
            return array_merge($service, $data);
        }

        $used   = (int)($t['up'] ?? 0) + (int)($t['down'] ?? 0);
        $total  = (int)($t['total'] ?? 0);
        $expiry = (int)($t['expiryTime'] ?? 0);
        /* بعضی بیلدها زمان انقضا را به ثانیه (نه میلی‌ثانیه) می‌دهند؛ یکدست می‌شود */
        if ($expiry > 0 && $expiry < 100000000000) $expiry *= 1000;

        $data = [
            'used_bytes' => max(0, $used),
            'last_sync'  => now(),
        ];

        /*
         * پنجرهٔ امن پس از ساخت: تا ۳ دقیقه بعد از ایجاد سرویس، مقادیر پنل روی حجم/زمان
         * ردیف سوار نمی‌شود و اگر پنل اکانت تازه را «تمام‌شده» نشان دهد (اکانت قدیمی هم‌نام،
         * تأخیر ذخیرهٔ پنل، واحد اشتباه)، مقادیر خرید دوباره روی پنل نوشته می‌شود.
         * این مورد باعث می‌شد پلن دلخواه بلافاصله «منقضی شده» دیده شود.
         */
        $createdTs = strtotime((string)($service['created_at'] ?? '')) ?: 0;
        $fresh     = $createdTs > 0 && (time() - $createdTs) < 180;

        /* روی کانفیگ های سهمیه اشتراکی حجم ردیف را دست نمی زنیم */
        $grouped = trim((string)($service['group_key'] ?? '')) !== '';
        if ($total > 0 && !$grouped && !$fresh) $data['volume_gb'] = bytes2gb($total, 2);
        if ($expiry > 0 && !$fresh) $data['expire_at'] = date('Y-m-d H:i:s', (int)($expiry / 1000));

        $quotaBytes = $grouped
            ? (int)gb2bytes((float)($service['group_quota_gb'] ?? 0))
            : $total;
        $overQuota = $quotaBytes > 0 && $used >= $quotaBytes;
        $expired   = ($expiry > 0 && (int)($expiry / 1000) < time()) || $overQuota;

        if ($fresh && $expired) {
            $ownExp = strtotime((string)($service['expire_at'] ?? '')) ?: 0;
            if ($ownExp === 0 || $ownExp > time()) {
                $expired = false;
                app_log('svc', 'sync: fresh service looked expired on panel -> limits re-pushed', [
                    'svc' => $sid, 'panel_total' => $total, 'panel_expiry' => $expiry, 'used' => $used,
                    'own_volume' => (float)($service['volume_gb'] ?? 0), 'own_expire' => (string)($service['expire_at'] ?? ''),
                ]);
                try { self::pushLimits($service, $xui); } catch (Throwable $e) { app_log('svc', 'pushLimits: ' . $e->getMessage(), ['svc' => $sid]); }
            }
        }

        $data['status'] = $expired ? 'expired' : (empty($t['enable']) ? 'disabled' : 'active');
        $data['panel_miss'] = 0;
        /* لحظهٔ تمام‌شدن ثبت می‌شود تا حذف خودکار پس از N روز مبنا داشته باشد */
        if ($expired && (string)($service['status'] ?? '') !== 'expired' && self::hasExpiredAt()) $data['expired_at'] = now();
        if (!$expired && (string)($service['status'] ?? '') === 'expired' && self::hasExpiredAt()) $data['expired_at'] = null;

        /* لینک ساب مرزبان توکن‌دار است؛ اگر اشتباه یا قدیمی ذخیره شده باشد ترمیم می‌شود */
        try {
            if (method_exists($xui, 'subUrlFor')) {
                $realSub = trim((string)$xui->subUrlFor((string)($service['client_email'] ?? '')));
                if ($realSub !== '' && $realSub !== trim((string)($service['sub_link'] ?? ''))) {
                    $data['sub_link'] = $realSub;
                }
            }
        } catch (Throwable $e) { }

        /* کانفیگ حذف شده هرگز نباید با همگام سازی به حالت فعال برگردد */
        if ((string)($service['status'] ?? '') === 'deleted') unset($data['status']);

        try { DB::update('services', $data, 'id = :id', [':id' => $sid]); }
        catch (Throwable $e) {
            unset($data['panel_miss']);
            try { DB::update('services', $data, 'id = :id', [':id' => $sid]); } catch (Throwable $e2) { }
        }
        return array_merge($service, $data);
    }

    /**
     * همگام سازی درجا با کنترل نرخ
     *
     * پنل نمایندگی و لینک ساب هر بار که باز می شوند مصرف را زنده می خوانند،
     * ولی برای اینکه پنل زیر بار نرود هر ردیف حداکثر هر $ttl ثانیه یک بار
     * به روز می شود. با این کار مصرف کانفیگ ها روی صفر نمی ماند حتی اگر
     * کران روی هاست فعال نباشد.
     */
    public static function syncStale(array $rows, int $ttl = 150, int $max = 12, bool $force = false): array
    {
        $ttl = max(15, $ttl);
        $out = [];
        $n   = 0;
        foreach ($rows as $s) {
            if (!is_array($s)) { $out[] = $s; continue; }
            if ((string)($s['status'] ?? '') === 'deleted') { $out[] = $s; continue; }

            $last = strtotime((string)($s['last_sync'] ?? '')) ?: 0;
            $due  = $force || $last === 0 || (time() - $last) >= $ttl;

            if ($due && $n < max(1, $max)) {
                try { $s = self::sync($s); $n++; }
                catch (Throwable $e) { app_log('svc', 'syncStale: ' . $e->getMessage(), ['svc' => (int)($s['id'] ?? 0)]); }
            }
            $out[] = $s;
        }
        return $out;
    }

    /** همگام سازی فوری یک کانفیگ (دکمه به روزرسانی مصرف) */
    public static function syncNow(int $id): ?array
    {
        $s = self::find($id);
        if (!$s) return null;
        try { return self::sync($s); }
        catch (Throwable $e) { app_log('svc', 'syncNow: ' . $e->getMessage(), ['svc' => $id]); return $s; }
    }

    /**
     * کانفیگ های سالم و به روز یک کانفیگ
     *
     * ترتیب اولویت:
     *  ۱) اسکن ساب اصلی خود پنل (دقیق ترین و همیشه به روز)
     *  ۲) بازسازی از روی اینباندهای واقعی همان اکانت
     *  ۳) مقدار ذخیره شده در پایگاه داده (آخرین چاره)
     *
     * خروجی همیشه فقط شامل خطوط کانفیگ معتبر است.
     */
    public static function liveConfigs(array $s, bool $allowStored = true): array
    {
        $links = [];
        $panel = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)($s['panel_id'] ?? 0)]);

        if ($panel && class_exists('Xui')) {
            $xui   = new Xui($panel);
            $subId = trim((string)($s['sub_id'] ?? ''));
            $own   = trim((string)($s['sub_link'] ?? ''));

            /* ۱) ساب اصلی پنل — مقدار ذخیره شده اگر خودش ساب همین ربات باشد کنار می رود */
            if (method_exists($xui, 'subFetch')) {
                try {
                    if ($own !== '' && !self::isOwnSub($own)) $links = $xui->subFetch($subId, $own);
                    if (!$links && $subId !== '')            $links = $xui->subFetch($subId);
                } catch (Throwable $e) {
                    app_log('svc', 'liveConfigs sub: ' . $e->getMessage(), ['svc' => (int)($s['id'] ?? 0)]);
                }
            }

            /* فقط کانفیگ های خود همین سرویس بمانند — پنل های اشتراکی بعضی وقت ها ساب کاربر دیگر را می دهند */
            if ($links) $links = self::ownLinks($s, $links);

            /* ۲) بازسازی از اینباندها */
            if (!$links && method_exists($xui, 'rebuildConfigs')) {
                try {
                    $links = $xui->rebuildConfigs(
                        (string)($s['client_email'] ?? ''),
                        (string)($s['client_uuid'] ?? ''),
                        [(int)($s['inbound_id'] ?? 0)]
                    );
                } catch (Throwable $e) {
                    app_log('svc', 'liveConfigs rebuild: ' . $e->getMessage(), ['svc' => (int)($s['id'] ?? 0)]);
                }
            }
        }

        /* ۳) ذخیره شده */
        if (!$links && $allowStored) {
            $links = self::storedConfigs($s);
        }

        $links = array_values(array_unique(array_filter($links)));

        /* نام گذاری یکدست روی همه کانفیگ ها */
        return self::renameLinks($links, self::configLabel($s, (string)($panel['name'] ?? '')));
    }

    /** نام تمیز برای کانفیگ ها: عنوان فروشگاه (+ نام پنل) */
    public static function configLabel(array $s, string $panelName = ''): string
    {
        $base = trim((string)DB::setting('sub_tag', ''));
        if ($base === '') $base = trim((string)DB::setting('shop_title', ''));
        $base = trim((string)preg_replace('/\s+/u', ' ', $base));
        if ($base === '') $base = 'VPN';

        if ((string)DB::setting('sub_name_panel', '1') === '1') {
            $pn = trim((string)preg_replace('/\s+/u', ' ', $panelName));
            if ($pn !== '') $base .= ' | ' . $pn;
        }
        /* نام کانفیگ (همان نام کاربری پنل) تا کاربر سرویس‌ها را اشتباه نگیرد */
        if ((string)DB::setting('sub_name_client', '1') === '1') {
            $cn = trim((string)preg_replace('/\s+/u', ' ', (string)($s['client_email'] ?? '')));
            if ($cn !== '') $base .= ' | ' . $cn;
        }
        return mb_substr($base, 0, 60);
    }

    /** شناسه یکتای درون یک لینک کانفیگ (uuid یا رمز) */
    public static function linkCred(string $link): string
    {
        $link = trim($link);
        if ($link === '') return '';

        if (stripos($link, 'vmess://') === 0) {
            $raw = substr($link, 8);
            $p   = strpos($raw, '#');
            if ($p !== false) $raw = substr($raw, 0, $p);
            $j = json_decode((string)base64_decode(strtr(trim($raw), '-_', '+/')), true);
            return is_array($j) ? trim((string)($j['id'] ?? '')) : '';
        }

        if (preg_match('~^[A-Za-z0-9]+://([^@/?#]+)@~', $link, $m)) {
            $cred = rawurldecode((string)$m[1]);
            if (strpos($cred, ':') === false && preg_match('~^[A-Za-z0-9+/=_\-]{8,}$~', $cred)) {
                $dec = base64_decode(strtr($cred, '-_', '+/'));
                if (is_string($dec) && strpos($dec, ':') !== false) $cred = $dec;
            }
            if (strpos($cred, ':') !== false) {
                $parts = explode(':', $cred);
                $cred  = (string)end($parts);
            }
            return trim($cred);
        }
        return '';
    }

    /**
     * فقط کانفیگ هایی که واقعا به همین سرویس تعلق دارند
     * (جلوگیری از تحویل کانفیگ کاربر دیگر وقتی sub_id تکراری باشد)
     */
    public static function ownLinks(array $s, array $links): array
    {
        $links = array_values(array_filter(array_map('trim', $links)));
        if ($links === []) return [];

        /* کلید خاموش کردن فیلتر در تنظیمات: sub_own_filter=0 */
        if ((string)DB::setting('sub_own_filter', '1') !== '1') return $links;

        $uuid  = strtolower(trim((string)($s['client_uuid'] ?? '')));
        $email = strtolower(trim((string)($s['client_email'] ?? '')));
        $mine  = strtolower(self::linkCred((string)($s['config_link'] ?? '')));
        if ($uuid === '' && $email === '' && $mine === '') return $links;

        $out = [];
        foreach ($links as $l) {
            $cred = strtolower(self::linkCred((string)$l));
            $hay  = strtolower(rawurldecode((string)$l));
            $ok   = false;
            if ($uuid !== '' && $cred !== '' && $cred === $uuid)         $ok = true;
            if (!$ok && $mine !== '' && $cred !== '' && $cred === $mine) $ok = true;
            if (!$ok && $uuid !== '' && strpos($hay, $uuid) !== false)   $ok = true;
            if (!$ok && $email !== '' && strpos($hay, $email) !== false) $ok = true;
            if ($ok) $out[] = $l;
        }

        /*
         * اگر هیچ لینکی شناسایی نشد، الگوی پنل ناشناخته است (مانند
         * trojan یا shadowsocks که رمز مستقل دارند). در این حالت لینک ها
         * دست نخورده برمی گردند تا اشتراک هرگز خالی تحویل نشود
         * (بدنه خالی در کلاینت ها خطای EOF می دهد).
         */
        if ($out === []) {
            app_log('svc', 'ownLinks: no link matched, passing through', [
                'svc' => (int)($s['id'] ?? 0), 'got' => count($links),
            ]);
            return $links;
        }
        return array_values(array_unique($out));
    }

    /** نام گذاری تمیز روی همه لینک ها */
    public static function renameLinks(array $links, string $label): array
    {
        if ($links === [] || $label === '') return $links;
        if ((string)DB::setting('sub_rename', '1') !== '1') return $links;

        $n   = count($links);
        $i   = 0;
        $out = [];
        foreach ($links as $l) {
            $i++;
            $out[] = self::retagLink((string)$l, $n > 1 ? $label . ' ' . $i : $label);
        }
        return array_values(array_filter($out));
    }

    /** جایگذاری نام نمایشی یک لینک */
    public static function retagLink(string $link, string $tag): string
    {
        $link = trim($link);
        if ($link === '' || $tag === '') return $link;

        if (stripos($link, 'vmess://') === 0) {
            $raw = substr($link, 8);
            $p   = strpos($raw, '#');
            if ($p !== false) $raw = substr($raw, 0, $p);
            $j = json_decode((string)base64_decode(strtr(trim($raw), '-_', '+/')), true);
            if (!is_array($j)) return $link;
            /* اگر کلیدهای حیاتی نبود به لینک دست نمی زنیم */
            if ((string)($j['add'] ?? '') === '' || (string)($j['port'] ?? '') === '' || (string)($j['id'] ?? '') === '') return $link;
            $j['ps'] = $tag;
            $enc = json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($enc) ? 'vmess://' . base64_encode($enc) : $link;
        }

        /*
         * فقط لینک هایی که ساختار درست scheme://... دارند نام گذاری
         * می شوند؛ در غیر این صورت لینک اصلی دست نخورده برمی گردد
         * تا کانفیگ سالم خراب نشود.
         */
        if (!preg_match('~^[A-Za-z][A-Za-z0-9+.\-]*://\S+~', $link)) return $link;

        $p    = strpos($link, '#');
        $base = rtrim($p !== false ? substr($link, 0, $p) : $link);
        if ($base === '' || substr($base, -3) === '://') return $link;

        return $base . '#' . rawurlencode($tag);
    }

    /** کانفیگ های ذخیره شده در پایگاه داده (فقط خطوط معتبر) */
    public static function storedConfigs(array $s): array
    {
        $re  = class_exists('Xui') && defined('Xui::LINK_RE')
            ? Xui::LINK_RE
            : '~^(vless|vmess|trojan|ss|hy2|hysteria2?|tuic)://\S+~i';
        $out = [];
        /* ردیف‌های ��دیمی که جداکنندهٔ متنی دارند هم درست خوانده می‌شون�� */
        $rawCfg = str_replace(['\r\n', '\n'], "\n", (string)($s['config_link'] ?? ''));
        foreach (preg_split('/\r\n|\r|\n/', $rawCfg) ?: [] as $l) {
            $l = trim($l);
            if ($l === '' || $l[0] === '#') continue;
            if (!preg_match($re, $l)) continue;
            $out[] = $l;
        }
        return array_values(array_unique($out));
    }

    /** آیا این آدرس، ساب داخلی خود ربات است؟ (جلو����یری از حلقه) */
    public static function isOwnSub(string $url): bool
    {
        $url = trim($url);
        if ($url === '') return false;
        if (stripos($url, '/sub.php') !== false) return true;

        $base = rtrim((string)app_url(), '/');
        if ($base !== '' && stripos($url, $base) === 0) return true;
        $sdb = self::subBase();
        if ($sdb !== '' && stripos($url, $sdb) === 0) return true;

        /*
         * فقط آدرس‌هایی که روی دامنهٔ خود ربات هستند ساب داخلی حساب می‌شوند.
         * پیشتر هر آدرسی با مسیر /sub/CODE داخلی حساب می‌شد و لینک ساب
         * خود پنل (مرزبان/اکس‌یو‌آی) هم اشتباهی نادیده گرفته می‌شد.
         */
        if ($base === '') return false;
        $h1 = strtolower((string)parse_url($url, PHP_URL_HOST));
        $h2 = strtolower((string)parse_url($base, PHP_URL_HOST));
        return $h1 !== '' && $h2 !== '' && $h1 === $h2 && (bool)preg_match('~/sub(\.php)?/~', $url);
    }

    /* ==================== لینک اشتراک ==================== */

    /** حالت تحویل لینک اشتراک: local (ساب اختصاصی ربات) یا panel (ساب مستقیم پنل) */
    public static function subMode(): string
    {
        $m = (string)DB::setting('sub_deliver', 'local');
        return in_array($m, ['local', 'panel', 'both'], true) ? $m : 'local';
    }

    /** لینک ساب اختصاصی ربات برای هر سرویس (گروهی یا تک پ��لی) */
    public static function localSub(array $s): string
    {
        $key = trim((string)($s['group_key'] ?? ''));
        if ($key === '') $key = trim((string)($s['sub_id'] ?? ''));
        return self::subLinkFor($key);
    }

    /**
     * ساخت لینک زیبای ساب:  BASE/sub/CODE
     *
     * اگر mod_rewrite روی هاست نبود، تنظیم sub_pretty را صفر کنید تا به حالت
     * قدیمی BASE/sub.php?id=CODE برگردد. هر د�� آدرس همیشه کار می کنند.
     */
    /* ============ دامنهٔ اختصاصی لینک ساب ============ */

    /**
     * پاکسازی هاست ورودی: پروتکل، مسیر، فاصله و پیشوند www حذف می‌شود.
     * خروجی: فقط هاست معتبر (مانند sub.example.com) یا رشتهٔ خالی.
     */
    public static function cleanHost(string $d): string
    {
        $d = trim(mb_strtolower($d));
        if ($d === '') return '';

        $d = (string)preg_replace('~^[a-z]+://~', '', $d);
        $d = (string)preg_replace('~[/?#].*$~', '', $d);
        $d = trim($d, " \t\n\r.");
        $d = (string)preg_replace('~^www\.~', '', $d);

        /* پورت احتمالی را جدا نگه می‌داریم */
        $port = '';
        if (preg_match('~^(.+?):(\d{2,5})$~', $d, $mm)) {
            $d    = (string)$mm[1];
            $port = ':' . (string)$mm[2];
        }

        if (!preg_match('~^(?=.{4,120}$)([a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,18}$~', $d)) return '';

        return $d . $port;
    }

    /** هاست دامنهٔ اختصاصی ساب */
    public static function subDomain(): string
    {
        return self::cleanHost((string)DB::setting('sub_domain', ''));
    }

    /** آیا دامنهٔ اختصاصی ساب فعال و معتبر است؟ */
    public static function subDomainOn(): bool
    {
        return (string)DB::setting('sub_domain_on', '0') === '1' && self::subDomain() !== '';
    }

    /** پروتکل دامنهٔ اختصاصی */
    public static function subDomainScheme(): string
    {
        return (string)DB::setting('sub_domain_https', '1') === '1' ? 'https' : 'http';
    }

    /** زیرپوشهٔ دامنهٔ اختصاصی (خالی = ریشهٔ دامنه) */
    public static function subDomainPath(): string
    {
        $p = trim((string)DB::setting('sub_domain_path', ''));
        $p = (string)preg_replace('~[^A-Za-z0-9_\-/]~', '', $p);
        $p = trim($p, '/');
        return $p === '' ? '' : '/' . $p;
    }

    /** آیا دامنهٔ اختصاصی روی لینک نمایندگان هم اعمال شود؟ */
    public static function subDomainForReseller(): bool
    {
        return (string)DB::setting('sub_domain_rs', '1') === '1';
    }

    /**
     * پایهٔ آدرس لینک ساب.
     * اگر دامنهٔ اختصاصی روشن باشد، دامنهٔ اصلی پنل کاملاً پنهان می‌ماند.
     */
    public static function subBase(): string
    {
        if (self::subDomainOn()) {
            return self::subDomainScheme() . '://' . self::subDomain() . self::subDomainPath();
        }
        return rtrim((string)app_url(), '/');
    }

    /** نمونهٔ لینک ساب برای پیش‌نمایش پنل مدیریت */
    public static function subSample(string $code = 'ABC123XY'): string
    {
        $base = self::subBase();
        if ((string)DB::setting('sub_pretty', '1') === '1') return $base . '/sub/' . $code;
        return $base . '/sub.php?id=' . $code;
    }

    public static function subLinkFor(string $key): string
    {
        $key = trim($key);
        if ($key === '' || !preg_match('/^[A-Za-z0-9_\-]{6,64}$/', $key)) return '';
        $base = self::subBase();
        if ((string)DB::setting('sub_pretty', '1') === '1') {
            return $base . '/sub/' . rawurlencode($key);
        }
        return $base . '/sub.php?id=' . rawurlencode($key);
    }

    /** کد ساب یک کانفیگ (کلید گروه در اولویت است) */
    public static function subCode(array $s): string
    {
        $key = trim((string)($s['group_key'] ?? ''));
        if ($key === '') $key = trim((string)($s['sub_id'] ?? ''));
        return preg_match('/^[A-Za-z0-9_\-]{6,64}$/', $key) ? $key : '';
    }

    /** لینک ساب مستقیم پنل (همان مقدار ذخیره شده) */
    public static function panelSub(array $s): string
    {
        return trim((string)($s['sub_link'] ?? ''));
    }

    /**
     * لینک اشتراک قابل ارائه به مشتری؛ روی همه سرویس ها کار می کند
     * (نه فقط سرویس های گروهی نمایندگی)
     */
    public static function subUrl(array $s, ?string $mode = null): string
    {
        $mode  = $mode !== null ? (string)$mode : self::subMode();
        $local = self::localSub($s);
        $panel = self::panelSub($s);
        if ($mode === 'panel') return $panel !== '' ? $panel : $local;
        return $local !== '' ? $local : $panel;
    }

    /* ==================== سطل زباله ==================== */

    /** تعداد روز نگهداری کانفیگ های حذف شده */
    public static function trashDays(): int
    {
        return (int)max(1, min(90, (int)DB::setting('trash_days', '7')));
    }

    /** فهرست کانفیگ های سطل زباله (0 = همه کاربران) */
    public static function trash(int $userId = 0, int $limit = 60, bool $resellerOnly = false): array
    {
        $w = "status = 'deleted'";
        $prm = [];
        if ($userId > 0) { $w .= ' AND user_id = :u'; $prm[':u'] = $userId; }
        if ($resellerOnly) $w .= ' AND is_reseller = 1';
        $lim = (int)max(1, min(300, $limit));
        try {
            return DB::all('SELECT * FROM {p}services WHERE ' . $w
                . ' ORDER BY COALESCE(deleted_at, created_at) DESC LIMIT ' . $lim, $prm);
        } catch (Throwable $e) {
            return DB::all('SELECT * FROM {p}services WHERE ' . $w
                . ' ORDER BY created_at DESC LIMIT ' . $lim, $prm);
        }
    }

    /** شمارش سطل زباله */
    public static function trashCount(int $userId = 0): int
    {
        $w = "status = 'deleted'";
        $prm = [];
        if ($userId > 0) { $w .= ' AND user_id = :u'; $prm[':u'] = $userId; }
        return (int)DB::val('SELECT COUNT(*) FROM {p}services WHERE ' . $w, $prm);
    }

    /** روزهای باقی مانده تا پاک سازی کامل یک ردیف حذف شده */
    public static function trashLeftDays(array $s): int
    {
        $ts = strtotime((string)($s['deleted_at'] ?? '')) ?: 0;
        if ($ts <= 0) return self::trashDays();
        $left = (int)ceil((($ts + self::trashDays() * 86400) - time()) / 86400);
        return (int)max(0, $left);
    }

    /** پاک سازی نهایی کانفیگ های حذف شده قدیمی تر از N روز */
    public static function purgeTrash(?int $days = null): int
    {
        $d = $days === null ? self::trashDays() : (int)max(0, $days);
        try {
            $rows = DB::all('SELECT id FROM {p}services WHERE status = :s'
                . ' AND deleted_at IS NOT NULL'
                . ' AND deleted_at < DATE_SUB(NOW(), INTERVAL ' . (int)$d . ' DAY)'
                . ' LIMIT 500', [':s' => 'deleted']);
        } catch (Throwable $e) {
            return 0;
        }
        $k = 0;
        foreach ($rows as $r) {
            try { DB::delete('services', 'id = :i', [':i' => (int)$r['id']]); $k++; }
            catch (Throwable $e) { app_log('svc', 'purgeTrash: ' . $e->getMessage()); }
        }
        return $k;
    }

    /* ================= بازنویسی حجم/زمان روی پنل ================= */

    /**
     * مقادیر حجم و زمان ذخیره‌شده در ربات را دوباره روی پنل می‌نویسد (و اکانت را فعال می‌کند).
     * کاربرد: اکانتِ تازه‌ساخته‌شده‌ای که پنل آن را با سهمیهٔ قدیمی/صفر نشان می‌دهد.
     */
    public static function pushLimits(array $service, ?Xui $xui = null): bool
    {
        if ($xui === null) {
            $panel = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)($service['panel_id'] ?? 0)]);
            if (!$panel) return false;
            $xui = new Xui($panel);
        }
        $email = (string)($service['client_email'] ?? '');
        if ($email === '') return false;
        $vol   = (float)($service['volume_gb'] ?? 0);
        $expTs = strtotime((string)($service['expire_at'] ?? '')) ?: 0;

        $client = $xui->findClient((int)($service['inbound_id'] ?? 0), $email);
        if (!$client) $client = ['email' => $email];
        $client['email']      = $email;
        $client['enable']     = true;
        $client['totalGB']    = $vol > 0 ? gb2bytes($vol) : 0;
        $client['expiryTime'] = $expTs > 0 ? $expTs * 1000 : 0;
        $key = (string)($client['id'] ?? ($client['password'] ?? ($service['client_uuid'] ?? '')));

        $upd = $xui->updateClient((int)($service['inbound_id'] ?? 0), $key, $client);
        if (($upd['success'] ?? false) !== true) {
            app_log('svc', 'pushLimits failed', ['svc' => (int)($service['id'] ?? 0), 'msg' => (string)($upd['msg'] ?? '')]);
            return false;
        }
        if ((int)($service['used_bytes'] ?? 0) <= 0) {
            try { $xui->resetTraffic((int)($service['inbound_id'] ?? 0), $email); } catch (Throwable $e) { }
        }
        return true;
    }

    /** آیا ستون expired_at (لحظهٔ تمام‌شدن) وجود دارد؟ اگر نبود یک‌بار ساخته می‌شود */
    public static function hasExpiredAt(): bool
    {
        static $has = null;
        if ($has !== null) return $has;
        $has = false;
        try {
            if (class_exists('Migrate')) {
                $has = Migrate::hasColumn('services', 'expired_at');
                if (!$has) {
                    DB::q('ALTER TABLE {p}services ADD COLUMN `expired_at` DATETIME NULL');
                    $has = Migrate::hasColumn('services', 'expired_at');
                }
            }
        } catch (Throwable $e) {
            $has = false;
        }
        return $has;
    }

    /* ================= ����ذف خودکار کانفیگ‌های تمام‌ش��ه ================= */

    /** تنظیمات حذف خودکار: [on, days, rs, notify, warn] */
    public static function autoDelCfg(): array
    {
        return [
            'on'     => (int)DB::setting('svc_autodel_on', 1) === 1,
            'days'   => (int)max(1, min(60, (int)DB::setting('svc_autodel_days', 3))),
            'rs'     => (int)DB::setting('svc_autodel_rs', 0) === 1,
            'notify' => (int)DB::setting('svc_autodel_notify', 1) === 1,
            'warn'   => (int)DB::setting('svc_autodel_warn', 1) === 1,
        ];
    }

    /**
     * فهرست سرویس‌های تمام‌شده‌ای که بیش از $days روز از پایانشان گذشته و تمدید نشده‌اند.
     * مبنا: تاریخ انقضا (پایان زمان) یا expired_at (اتمام حجم) — هرکدام زودتر بوده.
     */
    public static function autoDelCandidates(int $days, bool $includeReseller, int $limit = 40, int $warnedMask = 0): array
    {
        $days = max(1, $days);
        $w = "s.status = 'expired' AND s.is_test = 0";
        if (!$includeReseller) $w .= ' AND s.is_reseller = 0';
        $cut = 'DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY)';
        $cond = '(s.expire_at IS NOT NULL AND s.expire_at < ' . $cut . ')';
        if (self::hasExpiredAt()) $cond .= ' OR (s.expired_at IS NOT NULL AND s.expired_at < ' . $cut . ')';
        $w .= ' AND (' . $cond . ')';
        if ($warnedMask > 0) $w .= ' AND (COALESCE(s.notified, 0) & ' . (int)$warnedMask . ') = 0';
        try {
            return DB::all('SELECT s.*, u.tg_id AS utg FROM {p}services s LEFT JOIN {p}users u ON u.id = s.user_id'
                . ' WHERE ' . $w . ' ORDER BY s.expire_at ASC LIMIT ' . (int)max(1, min(200, $limit)));
        } catch (Throwable $e) {
            app_log('svc', 'autoDelCandidates: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * اجرای حذف خودکار (از کران): کانفیگ از پنل پاک و ردیف به سطل زباله می‌رود.
     * یک روز قبل از حذف (اگر فعال باشد) به کاربر هشدار داده می‌شود (بیت 8 ستون notified).
     * خروجی: ['deleted' => n, 'warned' => n]
     */
    public static function autoDeleteExpired(): array
    {
        $out = ['deleted' => 0, 'warned' => 0];
        $c = self::autoDelCfg();
        if (!$c['on']) return $out;

        /* ۱) هشدار ۲۴ ساعت قبل از حذف */
        if ($c['warn'] && $c['days'] >= 2) {
            foreach (self::autoDelCandidates($c['days'] - 1, $c['rs'], 40, 8) as $s) {
                try { DB::update('services', ['notified' => ((int)($s['notified'] ?? 0)) | 8], 'id = :id', [':id' => (int)$s['id']]); }
                catch (Throwable $e) { }
                $tg = (int)($s['utg'] ?? 0);
                if ($tg > 0 && class_exists('Tg')) {
                    try {
                        Tg::send($tg,
                            "⚠️ <b>سرویس تمام‌شدهٔ شما فردا حذف می‌شود</b>\n\n"
                            . "🔑 <code>" . h((string)$s['client_email']) . "</code>\n\n"
                            . 'اگر تا ۲۴ ساعت آینده تمدید نشود، کانفیگ به طور خودکار از سرور پاک می��شود.',
                            Tg::ikb([
                                [Tg::btn('♻️ تمدید سرویس', 'svcrn:' . (int)$s['id'])],
                                [Tg::btn('🛒 خرید سرویس جدید', 'menu:products')],
                            ]));
                        usleep(120000);
                    } catch (Throwable $e) { }
                }
                $out['warned']++;
            }
        }

        /* ۲) حذف پس از N روز */
        foreach (self::autoDelCandidates($c['days'], $c['rs'], 40) as $s) {
            try {
                self::remove($s, true);
            } catch (Throwable $e) {
                app_log('svc', 'autoDelete: ' . $e->getMessage(), ['svc' => (int)$s['id']]);
                continue;
            }
            $out['deleted']++;
            app_log('svc', 'auto-deleted expired service', ['svc' => (int)$s['id'], 'email' => (string)$s['client_email'], 'days' => $c['days']]);
            $tg = (int)($s['utg'] ?? 0);
            if ($c['notify'] && $tg > 0 && class_exists('Tg')) {
                try {
                    Tg::send($tg,
                        "🗑 <b>سرویس تمام‌شدهٔ شما حذف شد</b>\n\n"
                        . "🔑 <code>" . h((string)$s['client_email']) . "</code>\n\n"
                        . 'این سرویس ' . fa_num($c['days']) . ' روز پس از پایان تمدید نشد و به طور خودکار از سرور پاک شد. برای ادامه می‌توانید سرویس جدید بخرید.',
                        Tg::ikb([[Tg::btn('🛒 خرید سرویس جدید', 'menu:products')]]));
                    usleep(120000);
                } catch (Throwable $e) { }
            }
            if (class_exists('Logs')) {
                try {
                    Logs::send('services', Logs::fmt('🗑 حذف خودکار کانفیگ تمام‌شده', [
                        'سرویس'   => '<code>#' . (int)$s['id'] . '</code>',
                        'نام کاربری' => '<code>' . h((string)$s['client_email']) . '</code>',
                        'کاربر'    => '<code>' . $tg . '</code>',
                        'مهلت'     => fa_num($c['days']) . ' روز پس از پایان',
                    ]));
                } catch (Throwable $e) { }
            }
        }
        return $out;
    }

    /** پاک سازی کامل و فوری چند ردیف از سطل زباله */
    public static function purgeNow(array $ids): int
    {
        $k = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0) continue;
            try {
                DB::delete('services', "id = :i AND status = 'deleted'", [':i' => $id]);
                $k++;
            } catch (Throwable $e) { app_log('svc', 'purgeNow: ' . $e->getMessage()); }
        }
        return $k;
    }

    public static function remove(array $service, bool $markDeleted = true): bool
    {
        $panel = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)$service['panel_id']]);
        if ($panel) {
            $xui = new Xui($panel);
            $ids = $xui->limitIds(array_merge([(int)$service['inbound_id']], $xui->allowedInboundIds()));
            foreach ($ids as $ib) {
                if ((int)$ib <= 0) continue;
                try {
                    $client = $xui->findClient((int)$ib, (string)$service['client_email']);
                    if (!$client) continue;
                    $key = $client['id'] ?? ($client['password'] ?? $service['client_uuid']);
                    $xui->deleteClient((int)$ib, (string)$key);
                } catch (Throwable $e) {
                    app_log('svc', 'remove: ' . $e->getMessage(), ['inbound' => (int)$ib]);
                }
            }
        }
        if ($markDeleted) {
            /* سطل زباله: تاریخ حذف ثبت می شود تا پس از N روز کامل پاک شود */
            try {
                DB::update('services', ['status' => 'deleted', 'deleted_at' => now()],
                    'id = :id', [':id' => (int)$service['id']]);
            } catch (Throwable $e) {
                DB::update('services', ['status' => 'deleted'], 'id = :id', [':id' => (int)$service['id']]);
            }
        } else {
            DB::delete('services', 'id = :id', [':id' => (int)$service['id']]);
        }
        return true;
    }

    /** فعال/غیرفعال کردن سرویس */
    public static function toggle(array $service, bool $enable): array
    {
        $panel = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)$service['panel_id']]);
        if (!$panel) return ['ok' => false, 'message' => 'پنل یافت نشد.'];
        $xui = new Xui($panel);
        $ids = $xui->limitIds(array_merge([(int)$service['inbound_id']], $xui->allowedInboundIds()));
        $done = 0; $err = '';
        foreach ($ids as $ib) {
            if ((int)$ib <= 0) continue;
            $client = $xui->findClient((int)$ib, (string)$service['client_email']);
            if (!$client) continue;
            $client['enable'] = $enable;
            $key = (string)($client['id'] ?? ($client['password'] ?? $service['client_uuid']));
            $r = $xui->updateClient((int)$ib, $key, $client);
            if (($r['success'] ?? false) === true) $done++; else $err = (string)($r['msg'] ?? 'خطا');
        }
        if ($done === 0) return ['ok' => false, 'message' => $err !== '' ? $err : 'کاربر در پنل یافت نشد.'];
                DB::update('services', ['status' => $enable ? 'active' : 'disabled'], 'id = :id', [':id' => (int)$service['id']]);
        return ['ok' => true, 'message' => $enable ? 'سرویس فعال شد.' : 'سرویس غیرفعال شد.'];
    }

    /* ---------------- کمکی ---------------- */

    /* ---------------- نحوهٔ تحویل به خریدار ---------------- */

    /**
     * حالت‌های تحویل سرویس
     * کلید => [عنوان، توضیح، آیکون]
     */
    public const DELIVER = [
        'both'   => ['لینک ساب + کانفیگ', 'هم لینک اشتراک و هم کانفیگ‌های مستقیم تحویل داده می‌شود', '🎁'],
        'sub'    => ['فقط لینک ساب', 'فقط لینک اشتراک با به‌روزرسانی خودکار', '🔗'],
        'config' => ['فقط کانفیگ', 'فقط کانفیگ‌های مستقیم بدون لینک ساب', '⚙️'],
    ];

    /** پیش‌فرض کلی فروشگاه */
    public static function deliverDefault(): string
    {
        $m = strtolower(trim((string)DB::setting('deliver_mode', 'both')));
        return isset(self::DELIVER[$m]) ? $m : 'both';
    }

    /** حالت تحویل یک محصول؛ خالی = پیروی از پیش‌فرض کلی */
    public static function deliverModeOf($productId): string
    {
        $pid = (int)$productId;
        if ($pid <= 0) return self::deliverDefault();
        $m = '';
        try {
            $m = (string)DB::val('SELECT deliver_mode FROM {p}products WHERE id = :id', [':id' => $pid], '');
        } catch (Throwable $e) { $m = ''; }
        $m = strtolower(trim($m));
        return isset(self::DELIVER[$m]) ? $m : self::deliverDefault();
    }

    /** حالت تحویل یک سرویس */
    public static function deliverMode(array $s): string
    {
        return self::deliverModeOf((int)($s['product_id'] ?? 0));
    }

    public static function deliverLabel(string $m): string
    {
        $d = self::DELIVER[$m] ?? self::DELIVER['both'];
        return $d[2] . ' ' . $d[0];
    }

    /** آیا لینک اشتراک باید تحویل شود؟ */
    public static function wantsSub(string $m): bool
    {
        return $m !== 'config';
    }

    /** آیا کانفیگ مستقیم باید تحویل شود؟ */
    public static function wantsCfg(string $m): bool
    {
        return $m !== 'sub';
    }

    public static function find($id): ?array
    {
        return DB::one('SELECT * FROM {p}services WHERE id = :id', [':id' => (int)$id]);
    }

    /**
     * سرویس‌های یک کاربر
     *
     * کانفیگ‌هایی که نماینده از «پنل نمایندگی» ساخته است سرویس شخصی او نیستند و
     * نباید در «سرویس‌های من» داخل ربات دیده شوند؛ آن‌ها فقط در پنل نمایندگی
     * مدیریت می‌شوند. برای پنل مدیریت که باید همه چیز را ببیند
     * $includeReseller را true بگذارید.
     */
    public static function forUser(int $userId, bool $onlyActive = false, bool $includeReseller = false): array
    {
        $sql = 'SELECT s.*, p.name AS panel_name FROM {p}services s LEFT JOIN {p}panels p ON p.id = s.panel_id WHERE s.user_id = :u AND s.status <> :d';
        if ($onlyActive) $sql .= " AND s.status = 'active'";
        if (!$includeReseller) $sql .= ' AND COALESCE(s.is_reseller, 0) = 0';
        $sql .= ' ORDER BY s.id DESC';
        return DB::all($sql, [':u' => $userId, ':d' => 'deleted']);
    }

    /** فقط کانفیگ‌های نمایندگی یک کاربر */
    public static function resellerForUser(int $userId): array
    {
        return DB::all('SELECT s.*, p.name AS panel_name FROM {p}services s
            LEFT JOIN {p}panels p ON p.id = s.panel_id
            WHERE s.user_id = :u AND s.status <> :d AND COALESCE(s.is_reseller, 0) = 1
            ORDER BY s.id DESC', [':u' => $userId, ':d' => 'deleted']);
    }

    /** خلاصه وضعیت سرویس برای نمایش */
    public static function summary(array $s): string
    {
        $vol   = self::volLabel((float)$s['volume_gb']) . self::bar((float)$s['volume_gb'], (int)$s['used_bytes']); /* fixed79 */
        $used  = human_bytes((int)$s['used_bytes']);
        $leftGb = max(0.0, (float)$s['volume_gb'] - ((int)$s['used_bytes']) / 1073741824); /* fixed79 */
        $left  = (float)$s['volume_gb'] > 0 ? ($leftGb > 0 ? self::volLabel($leftGb) : fa_num('0') . ' مگابایت') : 'نامحدود';
        $statusMap = ['active' => '✅ فعال', 'expired' => '⏳ منقضی', 'disabled' => '⛔️ غیرفعال', 'deleted' => '🗑 حذف شده', 'missing' => '🚫 حذف‌شده از پنل'];
        $lines = [
            '👤 نام کاربری: <code>' . h($s['client_email']) . '</code>',
            '📊 حجم کل: ' . $vol,
            '📉 مصرف‌شده: ' . fa_num($used),
            '📈 باقی‌مانده: ' . $left,
            '⏰ انقضا: ' . ($s['expire_at'] ? to_jalali((string)$s['expire_at'], true) . ' (' . remaining_human((string)$s['expire_at']) . ')' : 'نامحدود'),
            '⚙️ وضعیت: ' . ($statusMap[$s['status']] ?? $s['status']),
        ];
        if (!empty($s['is_test'])) $lines[] = '🧪 اکانت تست';
        return implode("\n", $lines);
    }

    /* ==================== حذف/عودت و تمدید سرویس کاربر ==================== */

    public static function userDelEnabled(): bool { return (string)DB::setting('usr_del_enabled', '0') === '1'; }
    public static function userDelRefund(): bool  { return (string)DB::setting('usr_del_refund', '1') === '1'; }
    public static function userRenewEnabled(): bool { return (string)DB::setting('usr_renew_enabled', '1') === '1'; }

    /** روش محاسبه عودت: fair (کمترین سهم) | split (میانگین) | gb (فقط حجم) */
    public static function userDelMode(): string
    {
        $m = (string)DB::setting('usr_del_mode', 'fair');
        return in_array($m, ['fair', 'split', 'gb'], true) ? $m : 'fair';
    }

    /** درصد کارمزد حذف */
    public static function userDelFeePct(): float
    {
        return (float)max(0, min(100, (float)DB::setting('usr_del_fee_pct', '0')));
    }

    /** مبلغ پرداخت شده برای یک سرویس (آخرین سفارش پرداخت شده) */
    public static function paidFor(array $s): int
    {
        $sid = (int)($s['id'] ?? 0);
        if ($sid <= 0) return 0;
        try {
            $v = (int)DB::val("SELECT final_amount FROM {p}orders WHERE service_id = :s AND status = 'paid' ORDER BY id DESC LIMIT 1",
                [':s' => $sid], 0);
        } catch (Throwable $e) {
            $v = 0;
        }
        return max(0, $v);
    }

    /** برآورد مبلغ عودتی حذف سرویس کاربر (پیش از حذف) */
    public static function deleteQuote(array $s): array
    {
        $totalGb = (float)($s['volume_gb'] ?? 0);
        $usedGb  = (float)bytes2gb((int)($s['used_bytes'] ?? 0), 4);
        $leftGb  = (float)round(max(0.0, $totalGb - $usedGb), 2);

        $expTs   = strtotime((string)($s['expire_at'] ?? '')) ?: 0;
        $expired = $expTs > 0 && $expTs <= time();
        $totDays = (int)max(1, (int)($s['days'] ?? 0));
        $leftDays = $expTs > 0 ? (int)min($totDays, max(0, (int)ceil(($expTs - time()) / 86400))) : $totDays;

        $fracGb  = $totalGb > 0.0 ? min(1.0, max(0.0, $leftGb / $totalGb)) : 1.0;
        $fracDay = $expTs > 0 ? min(1.0, max(0.0, $leftDays / $totDays)) : 1.0;

        $mode = self::userDelMode();
        if ($mode === 'gb') {
            $frac = $fracGb;
        } elseif ($mode === 'split') {
            $frac = ($fracGb + $fracDay) / 2;
        } else {
            $frac = min($fracGb, $fracDay);
        }
        $frac = min(1.0, max(0.0, $frac));

        $pool  = self::paidFor($s);
        $gross = (int)floor($pool * $frac);
        $fee   = 0;
        $note  = '';

        if (!self::userDelRefund()) {
            $gross = 0;
            $note  = 'عودت وجه توسط مدیر غیرفعال است.';
        } elseif ((int)($s['is_test'] ?? 0) === 1) {
            $gross = 0;
            $note  = 'اکانت تست عودت وجه ندارد.';
        } elseif ($expired) {
            $gross = 0;
            $note  = 'این سرویس منقضی شده و عودتی ندارد.';
        } elseif ($pool <= 0) {
            $gross = 0;
            $note  = 'برای این سرویس پرداختی ثبت نشده است.';
        } elseif ($gross <= 0) {
            $note  = 'مبلغ قابل عودت صفر است.';
        }

        if ($gross > 0 && self::userDelFeePct() > 0) {
            $fee   = (int)floor($gross * self::userDelFeePct() / 100);
            $gross = max(0, $gross - $fee);
        }

        return [
            'pool'      => $pool,
            'refund'    => $gross,
            'fee'       => $fee,
            'frac_pct'  => (int)round($frac * 100),
            'left_gb'   => $leftGb,
            'left_days' => $leftDays,
            'mode'      => $mode,
            'note'      => $note,
        ];
    }

    /** حذف سرویس خریداری شده توسط خود کاربر با عودت وجه */
    public static function userDelete(array $user, int $svcId): array
    {
        if (!self::userDelEnabled()) return ['ok' => false, 'message' => 'حذف سرویس توسط مدیر غیرفعال شده است.'];

        $s = self::find($svcId);
        if (!$s || (int)($s['user_id'] ?? 0) !== (int)($user['id'] ?? 0)) {
            return ['ok' => false, 'message' => 'این سرویس پیدا نشد یا متعلق به شما نیست.'];
        }
        if ((string)($s['status'] ?? '') === 'deleted') return ['ok' => false, 'message' => 'این سرویس قبلاً حذف شده است.'];
        if ((int)($s['is_reseller'] ?? 0) === 1) return ['ok' => false, 'message' => 'کانفیگ نمایندگی از پنل نمایندگی حذف می‌شود.'];

        $q = self::deleteQuote($s);

        try {
            self::remove($s, true);
        } catch (Throwable $e) {
            app_log('svc', 'userDelete: ' . $e->getMessage(), ['svc' => $svcId]);
            return ['ok' => false, 'message' => 'حذف انج��م نشد؛ دوباره تلاش کنید.'];
        }

        $paid = (int)($q['refund'] ?? 0);
        if ($paid > 0) {
            try {
                Wallet::credit((int)$user['id'], $paid, 'refund', 'wallet', 'عودت حذف سرویس #' . $svcId);
            } catch (Throwable $e) {
                $paid = 0;
                app_log('svc', 'userDelete refund: ' . $e->getMessage(), ['svc' => $svcId]);
            }
        }

        $msg = '✅ سرویس حذف شد.';
        if ($paid > 0) {
            $msg .= chr(10) . '💰 مبلغ ' . money($paid) . ' ' . currency() . ' به کیف پول شما برگشت داده شد.';
            $msg .= chr(10) . '📐 مبنا: ' . en_num((string)(int)($q['frac_pct'] ?? 0)) . '٪ مصرف نشده از ' . money((int)($q['pool'] ?? 0)) . ' ' . currency();
            if ((int)($q['fee'] ?? 0) > 0) $msg .= chr(10) . '➖ کارمزد حذف: ' . money((int)$q['fee']) . ' ' . currency();
        } elseif (trim((string)($q['note'] ?? '')) !== '') {
            $msg .= chr(10) . 'ℹ️ ' . (string)$q['note'];
        }
        $msg .= chr(10) . '🗑 این سرویس به سطل زباله رفت و پس از ' . en_num((string)self::trashDays()) . ' روز کامل پاک می‌شود.';

        if (class_exists('Logs')) {
            try {
                Logs::send('services', Logs::fmt('🗑 حذف سرویس توسط کاربر', [
                    'کاربر'  => (string)($user['tg_id'] ?? ''),
                    'سرویس' => '#' . $svcId,
                    'عودت'  => money($paid) . ' ' . currency(),
                ]));
            } catch (Throwable $e) {
            }
        }

        return ['ok' => true, 'refund' => $paid, 'refund_txt' => money($paid), 'message' => $msg];
    }

    /** طرح های قابل استفاده برای تمدید یک سرویس */
    public static function renewPlans(array $s): array
    {
        try {
            $rows = DB::all('SELECT * FROM {p}products WHERE active = 1 AND stock <> 0 AND panel_id = :p ORDER BY sort ASC, price ASC',
                [':p' => (int)($s['panel_id'] ?? 0)]) ?: [];
            /* fixed76: محصول «حجم و زمان دلخواه» طرح تمدید نیست */
            return array_values(array_filter($rows, static function ($p) {
                return (string)($p['type'] ?? 'fixed') !== 'custom';
            }));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** تمدید سرویس با کسر از کیف پول (برای مینی اپ) */
    public static function userRenew(array $user, int $svcId, int $productId): array
    {
        if (!self::userRenewEnabled()) return ['ok' => false, 'message' => 'تمدید سرویس فعلاً غیرفعال است.'];

        $s = self::find($svcId);
        if (!$s || (int)($s['user_id'] ?? 0) !== (int)($user['id'] ?? 0)) {
            return ['ok' => false, 'message' => 'این سرویس پیدا نشد یا متعلق به شما نیست.'];
        }
        if ((string)($s['status'] ?? '') === 'deleted') return ['ok' => false, 'message' => 'این سرویس حذف شده است.'];

        $p = DB::one('SELECT * FROM {p}products WHERE id = :i AND active = 1', [':i' => $productId]);
        if (!$p) return ['ok' => false, 'message' => 'طرح تمدید پیدا نشد.'];
        if ((string)($p['type'] ?? 'fixed') === 'custom') return ['ok' => false, 'message' => 'این طرح برای تمدید قابل استفاده نیست.'];
        if ((int)$p['panel_id'] !== (int)$s['panel_id']) return ['ok' => false, 'message' => 'این طرح برای سرور این سرویس نیست.'];

        $price = (int)$p['price'];
        $fresh = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => (int)$user['id']]) ?: $user;
        /* fixed75: کمپین تخفیف روی تمدید کاربر (ربات + مینی‌اپ) */
        $rb = class_exists('Campaign') ? Campaign::best($price, (int)$p['id'], 0, 'renew') : ['final' => $price, 'discount' => 0, 'source' => ''];
        $pay = max(0, (int)($rb['final'] ?? $price));
        if ((int)($fresh['balance'] ?? 0) < $pay) {
            return ['ok' => false, 'message' => 'موجودی کیف پول کافی نیست.', 'need' => $pay - (int)($fresh['balance'] ?? 0)];
        }

        if ($pay > 0 && !Wallet::debit((int)$fresh['id'], $pay, 'تمدید: ' . (string)$p['name'])) {
            return ['ok' => false, 'message' => 'کسر از کیف پول انجام نشد.'];
        }

        $orderId = 0;
        try {
            $orderId = (int)DB::insert('orders', [
                'user_id' => (int)$fresh['id'], 'tg_id' => (int)$fresh['tg_id'], 'product_id' => (int)$p['id'],
                'service_id' => $svcId, 'type' => 'renew', 'amount' => $price, 'discount_amount' => (int)($rb['discount'] ?? 0),
                'final_amount' => $pay, 'discount_code' => (($rb['source'] ?? '') === 'camp') ? 'CAMPAIGN' : null,
                'status' => 'pending', 'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            app_log('svc', 'userRenew order: ' . $e->getMessage(), ['svc' => $svcId]);
        }

        try {
            $r = self::renew($s, $p);
        } catch (Throwable $e) {
            app_log('svc', 'userRenew: ' . $e->getMessage(), ['svc' => $svcId]);
            $r = ['ok' => false, 'message' => 'خطا در تمدید سرویس.'];
        }

        if (empty($r['ok'])) {
            try { Wallet::credit((int)$fresh['id'], $price, 'refund', 'wallet', 'تمدید ناموفق #' . $orderId); } catch (Throwable $e) {}
            if ($orderId > 0) {
                try { DB::update('orders', ['status' => 'failed'], 'id = :i', [':i' => $orderId]); } catch (Throwable $e) {}
            }
            return ['ok' => false, 'message' => (string)($r['message'] ?? 'تمدید ناموفق بود.') . ' مبلغ به کیف پول برگشت.'];
        }

        if ($orderId > 0) {
            try { DB::update('orders', ['status' => 'paid'], 'id = :i', [':i' => $orderId]); } catch (Throwable $e) {}
        }

        return [
            'ok'      => true,
            'message' => '✅ سرویس تمدید شد.',
            'service' => $r['service'] ?? self::find($svcId),
            'price'   => $price,
            'recreated' => !empty($r['recreated']),
        ];
    }
}
