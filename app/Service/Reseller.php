<?php

declare(strict_types=1);

/**
 * سامانه نمایندگی
 *
 * نماینده در مینی‌اپ حجم، مدت و نام کانفیگ را وارد می‌کند، قیمت لحظه‌ای محاسبه
 * می‌شود و با زدن دکمه ساخت، کانفیگ ساخته و موجودی کم می‌شود.
 *
 * دو سطح:
 *   سطح ۱ – حتماً باید موجودی کافی داشته باشد (سقف بدهی صفر)
 *   سطح ۲ – می‌تواند تا سقف مشخصی بدهکار شود (موجودی منفی مجاز است)
 */
class Reseller
{
    /** رنگ و توضیح هر سطح برای نمایش در پنل مدیریت */
    public const LEVEL_META = [
        0 => ['color' => '#94a3b8', 'desc' => 'کاربر عادی فروشگاه'],
        1 => ['color' => '#22c55e', 'desc' => 'خرید فقط با موجودی کیف پول'],
        2 => ['color' => '#f59e0b', 'desc' => 'مجاز به بدهکاری تا سقف اعتبار'],
    ];

    public const LEVELS = [
        0 => ['label' => 'کاربر عادی',      'icon' => '👤'],
        1 => ['label' => 'نماینده سطح ۱', 'icon' => '🥈'],
        2 => ['label' => 'نماینده سطح ۲', 'icon' => '🥇'],
    ];

    /* ==================== تنظیمات ==================== */

    public static function enabled(): bool { return (string)DB::setting('rs_enabled', '1') === '1'; }

    public static function priceGb(): int  { return max(0, (int)DB::setting('rs_price_gb', 2000)); }
    public static function priceDay(): int { return max(0, (int)DB::setting('rs_price_day', 500)); }
    public static function baseFee(): int  { return max(0, (int)DB::setting('rs_base_fee', 0)); }

    public static function minGb(): int    { return max(1, (int)DB::setting('rs_min_gb', 1)); }
    public static function maxGb(): int    { return max(self::minGb(), (int)DB::setting('rs_max_gb', 500)); }
    public static function minDays(): int  { return max(1, (int)DB::setting('rs_min_days', 1)); }
    public static function maxDays(): int  { return max(self::minDays(), (int)DB::setting('rs_max_days', 365)); }

    public static function ipLimit(): int  { return max(0, (int)DB::setting('rs_ip_limit', 2)); }
    public static function round(): int    { return max(1, (int)DB::setting('rs_round', 1000)); }

    /* ---------- امکانات گسترده‌ی پنل نمایندگان ---------- */

    /** فقط پرداخت از کیف پول (بدون درگاه مستقیم) */
    public static function walletOnly(): bool { return (string)DB::setting('rs_wallet_only', '0') === '1'; }

    /** سقف تعداد سرویس ساخته‌شده در روز (۰ = بی‌نهایت) */
    public static function dailyLimit(): int   { return max(0, (int)DB::setting('rs_daily_limit', 0)); }

    /** سقف تعداد سرویس در ماه (۰ = بی‌نهایت) */
    public static function monthLimit(): int   { return max(0, (int)DB::setting('rs_month_limit', 0)); }

    /** سقف کل سرویس‌های فعال هر نماینده (۰ = بی‌نهایت) */
    public static function serviceCap(): int   { return max(0, (int)DB::setting('rs_service_cap', 0)); }

    /** حداقل مبلغ شارژ حساب نماینده */
    public static function minCharge(): int    { return max(0, (int)DB::setting('rs_min_charge', 0)); }

    /** تعلیق خودکار نماینده‌ی بدهکار */
    public static function autoSuspend(): bool { return (string)DB::setting('rs_auto_suspend', '0') === '1'; }

    /** دامنه‌ی اختصاصی لینک ساب نمایندگان */
    public static function subDomain(): string { return trim((string)DB::setting('rs_sub_domain', '')); }

    /** پیام خوش‌آمد پنل نمایندگی */
    public static function welcome(): string   { return trim((string)DB::setting('rs_welcome', '')); }

    /** شناسه‌ی پشتیبانی ویژه‌ی نمایندگان */
    public static function supportId(): string { return ltrim(trim((string)DB::setting('rs_support_id', '')), '@'); }

    /** قوانین و مقررات نمایندگی */
    public static function tos(): string       { return trim((string)DB::setting('rs_tos', '')); }

    /** دسترسی‌های نماینده */
    public static function allowTest(): bool   { return (string)DB::setting('rs_allow_test', '0')   === '1'; }
    public static function allowRename(): bool { return (string)DB::setting('rs_allow_rename', '1') === '1'; }
    public static function allowDelete(): bool { return (string)DB::setting('rs_allow_delete', '1') === '1'; }
    public static function allowRenew(): bool  { return (string)DB::setting('rs_allow_renew', '1')  === '1'; }
    public static function allowEdit(): bool   { return (string)DB::setting('rs_allow_edit', '1')   === '1'; }

    /** نمایش قیمت خرید به نماینده */
    public static function showPrice(): bool   { return (string)DB::setting('rs_show_price', '1') === '1'; }

    /** پنهان‌سازی نام سرور/پنل از دید نماینده */
    public static function hidePanel(): bool   { return (string)DB::setting('rs_hide_panel', '1') === '1'; }

    /** اجبار به احراز هویت (موبایل/ایمیل) پیش از کار با پنل */
    public static function forceVerify(): bool { return (string)DB::setting('rs_force_verify', '0') === '1'; }

    /** تعداد سرویس ساخته‌شده‌ی نماینده در بازه‌ی زمانی */
    /* ============ دامنه و برند اختصاصی نماینده ============ */

    /**
     * پاکسازی دامنهٔ ورودی نماینده
     * پروتکل، مسیر، فاصله و پیشوند www حذف می‌شود.
     * خروجی: فقط هاست معتبر (مانند sub.example.com) یا رشتهٔ خالی
     */
    public static function cleanDomain(string $d): string
    {
        $d = trim(mb_strtolower($d));
        if ($d === '') return '';

        $d = preg_replace('~^[a-z]+://~', '', $d);
        $d = (string)preg_replace('~[/?#].*$~', '', (string)$d);
        $d = trim((string)$d, " \t\n\r.");
        $d = preg_replace('~^www\.~', '', (string)$d);
        $d = (string)$d;

        /* حذف پورت احتمالی */
        $port = '';
        if (preg_match('~^(.+?):(\d{2,5})$~', $d, $mm)) {
            $d    = $mm[1];
            $port = ':' . $mm[2];
        }

        if (!preg_match('~^(?=.{4,120}$)([a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,18}$~', $d)) return '';

        return $d . $port;
    }

    /** دامنهٔ فعال برای این نماینده: اول دامنهٔ خودش، بعد دامنهٔ عمومی مدیر */
    public static function domainFor(array $u): string
    {
        $own = self::cleanDomain((string)($u['reseller_domain'] ?? ''));
        if ($own !== '') return $own;
        return self::cleanDomain(self::subDomain());
    }

    /** نام برند نماینده */
    public static function brandOf(array $u): string
    {
        $b = trim((string)($u['reseller_brand'] ?? ''));
        return $b !== '' ? $b : (defined('APP_BRAND') ? (string)APP_BRAND : '');
    }

    /** توضیح عمومی نماینده (زیر لینک ساب نمایش داده می‌شود) */
    public static function publicNote(array $u): string
    {
        return trim((string)($u['reseller_note_pub'] ?? ''));
    }

    /**
     * جایگزینی هاست یک نشانی با دامنهٔ اختصاصی نماینده
     * مسیر، کوئری و پروتکل دست‌نخورده باقی می‌ماند.
     */
    public static function applyDomain(string $url, array $u): string
    {
        $url = trim($url);
        if ($url === '') return '';

        $dom = self::domainFor($u);
        if ($dom === '') return $url;

        $parts = @parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) return $url;

        $scheme = (string)($parts['scheme'] ?? 'https');
        $path   = (string)($parts['path'] ?? '');
        $query  = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $frag   = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

        return $scheme . '://' . $dom . $path . $query . $frag;
    }

    public static function builtSince(int $userId, string $since): int
    {
        try {
            return (int)DB::val("SELECT COUNT(*) FROM {p}services
                WHERE user_id = :u AND is_reseller = 1 AND created_at >= :d",
                [':u' => $userId, ':d' => $since], 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * بررسی سقف‌های ساخت سرویس برای نماینده
     * بازگشت: ['ok' => bool, 'message' => string]
     */
    public static function quotaGate(array $u): array
    {
        $uid = (int)($u['id'] ?? 0);
        if ($uid <= 0) return ['ok' => false, 'message' => 'کاربر معتبر نیست.'];

        if (self::forceVerify()) {
            $ok = ((int)($u['phone_verified'] ?? 0) === 1) || ((int)($u['email_verified'] ?? 0) === 1);
            if (!$ok) return ['ok' => false, 'message' => 'پیش از ساخت سرویس، احراز هویت (موبایل یا ایمیل) را کامل کنید.'];
        }

        $cap = self::serviceCap();
        if ($cap > 0) {
            $now = (int)DB::val("SELECT COUNT(*) FROM {p}services
                WHERE user_id = :u AND is_reseller = 1 AND status != 'deleted'", [':u' => $uid], 0);
            if ($now >= $cap) {
                return ['ok' => false, 'message' => 'سقف سرویس‌های فعال شما (' . $cap . ') پر شده است.'];
            }
        }

        $day = self::dailyLimit();
        if ($day > 0 && self::builtSince($uid, date('Y-m-d 00:00:00')) >= $day) {
            return ['ok' => false, 'message' => 'سقف ساخت سرویس امروز شما (' . $day . ') پر شده است.'];
        }

        $mon = self::monthLimit();
        if ($mon > 0 && self::builtSince($uid, date('Y-m-01 00:00:00')) >= $mon) {
            return ['ok' => false, 'message' => 'سقف ساخت سرویس این ماه شما (' . $mon . ') پر شده است.'];
        }

        return ['ok' => true, 'message' => ''];
    }

    /** درصد تخفیف هر سطح */
    public static function discount(int $level): float
    {
        if ($level === 1) return max(0.0, min(90.0, (float)DB::setting('rs_l1_discount', 10)));
        if ($level === 2) return max(0.0, min(90.0, (float)DB::setting('rs_l2_discount', 20)));
        return 0.0;
    }

    /** سقف بدهی مجاز هر سطح (سطح ۱ همیشه صفر) */
    public static function creditLimit(int $level): int
    {
        if ($level === 2) return max(0, (int)DB::setting('rs_l2_credit', 500000));
        return 0;
    }

    /** فهرست سرورهای مجاز برای نمایندگی */
    public static function panels(): array
    {
        $raw = trim((string)DB::setting('rs_panels', ''));
        $ids = array_values(array_filter(array_map('intval', preg_split('/[^0-9]+/', $raw) ?: [])));

        if ($ids) {
            $in   = implode(',', $ids);
            $rows = DB::all('SELECT * FROM {p}panels WHERE active = 1 AND id IN (' . $in . ') ORDER BY sort ASC, id ASC');
            if ($rows) return $rows;
        }
        return DB::all('SELECT * FROM {p}panels WHERE active = 1 ORDER BY sort ASC, id ASC');
    }

    public static function panelById(int $id): ?array
    {
        foreach (self::panels() as $p) {
            if ((int)$p['id'] === $id) return $p;
        }
        return null;
    }

    /* ==================== تعرفهٔ اختصاصی هر سرور ==================== */

    /** استانداردسازی یک ردیف تعرفهٔ سرور */
    public static function normalizePanelTariff(array $r): array
    {
        return [
            'panel_id' => max(0, (int)($r['panel_id'] ?? 0)),
            'on'       => !empty($r['on']),
            'gb'       => max(0, (int)($r['gb'] ?? 0)),
            'day'      => max(0, (int)($r['day'] ?? 0)),
            'fee'      => max(0, (int)($r['fee'] ?? 0)),
            'mul'      => max(10, min(500, (int)($r['mul'] ?? 100))),
            'off'      => max(0, min(90,  (int)($r['off'] ?? 0))),
            'min_gb'   => max(0, (int)($r['min_gb'] ?? 0)),
            'max_gb'   => max(0, (int)($r['max_gb'] ?? 0)),
            'min_days' => max(0, (int)($r['min_days'] ?? 0)),
            'max_days' => max(0, (int)($r['max_days'] ?? 0)),
            'lv'       => max(0, min(2, (int)($r['lv'] ?? 0))),
            'label'    => mb_substr(trim((string)($r['label'] ?? '')), 0, 40),
            'note'     => mb_substr(trim((string)($r['note'] ?? '')), 0, 160),
        ];
    }

    /** همهٔ تعرفه‌های سرور: [panel_id => row] */
    public static function panelTariffs(): array
    {
        $raw = jdec((string)DB::setting('rs_panel_tariff', ''), []);
        if (!is_array($raw)) $raw = [];

        $out = [];
        foreach ($raw as $k => $r) {
            if (!is_array($r)) continue;
            if (!isset($r['panel_id'])) $r['panel_id'] = (int)$k;
            $row = self::normalizePanelTariff($r);
            if ($row['panel_id'] <= 0) continue;
            $out[$row['panel_id']] = $row;
        }
        return $out;
    }

    /** تعرفهٔ فعالِ یک سرور (آرایهٔ خالی = تعرفهٔ عمومی) */
    public static function panelTariff(int $panelId): array
    {
        if ($panelId <= 0) return [];
        $row = self::panelTariffs()[$panelId] ?? null;
        if (!$row || empty($row['on'])) return [];
        return $row;
    }

    public static function panelHasTariff(int $panelId): bool
    {
        return self::panelTariff($panelId) !== [];
    }

    /** ذخیرهٔ همهٔ تعرفه‌های سرور */
    public static function savePanelTariffs(array $rows): int
    {
        $out = [];
        foreach ($rows as $k => $r) {
            if (!is_array($r)) continue;
            if (!isset($r['panel_id'])) $r['panel_id'] = (int)$k;
            $row = self::normalizePanelTariff($r);
            if ($row['panel_id'] <= 0) continue;

            /* ردیف خاموش و کاملاً خالی ذخیره نمی‌شود */
            if (empty($row['on']) && $row['gb'] === 0 && $row['day'] === 0 && $row['fee'] === 0
                && $row['mul'] === 100 && $row['off'] === 0 && $row['lv'] === 0
                && $row['min_gb'] === 0 && $row['max_gb'] === 0
                && $row['min_days'] === 0 && $row['max_days'] === 0
                && $row['label'] === '' && $row['note'] === '') continue;

            $out[(string)$row['panel_id']] = $row;
        }

        DB::setSetting('rs_panel_tariff', jenc($out));
        return count($out);
    }

    /** نام نمایشی سرور (برچسب تعرفه بر نام پنل ارجح است) */
    public static function panelLabel($panel): string
    {
        $pid  = is_array($panel) ? (int)($panel['id'] ?? 0) : (int)$panel;
        $name = is_array($panel) ? trim((string)($panel['name'] ?? '')) : '';

        if ($name === '' && $pid > 0) {
            $p    = self::panelById($pid);
            $name = $p ? trim((string)($p['name'] ?? '')) : '';
        }

        $t  = self::panelTariff($pid);
        $lb = trim((string)($t['label'] ?? ''));
        if ($lb !== '') return $lb;

        return $name !== '' ? $name : ('سرور ' . fa_num($pid));
    }

    /** آیا سطح این نماینده اجازهٔ استفاده از این سرور را دارد؟ */
    public static function panelAllowed(array $u, int $panelId): bool
    {
        $need = (int)(self::panelTariff($panelId)['lv'] ?? 0);
        return $need <= 0 || self::level($u) >= $need;
    }

    /** ضریب قیمت این سرور (درصد) */
    public static function panelMul(int $panelId): int
    {
        $m = (int)(self::panelTariff($panelId)['mul'] ?? 100);
        return $m > 0 ? $m : 100;
    }

    /**
     * ترتیب اولویت قیمت‌ها:
     * ۱) تعرفهٔ اختصاصی خودِ نماینده
     * ۲) تعرفهٔ همان سرور
     * ۳) تعرفهٔ عمومی نمایندگی
     */
    public static function priceGbForPanel(array $u, int $panelId): int
    {
        $own = (int)($u['reseller_price_gb'] ?? 0);
        if ($own > 0) return $own;

        $v = (int)(self::panelTariff($panelId)['gb'] ?? 0);
        return $v > 0 ? $v : self::priceGb();
    }

    public static function priceDayForPanel(array $u, int $panelId): int
    {
        $own = (int)($u['reseller_price_day'] ?? 0);
        if ($own > 0) return $own;

        $v = (int)(self::panelTariff($panelId)['day'] ?? 0);
        return $v > 0 ? $v : self::priceDay();
    }

    public static function baseFeeForPanel(int $panelId): int
    {
        $v = (int)(self::panelTariff($panelId)['fee'] ?? 0);
        return $v > 0 ? $v : self::baseFee();
    }

    public static function minGbForPanel(int $panelId): int
    {
        $v = (int)(self::panelTariff($panelId)['min_gb'] ?? 0);
        return $v > 0 ? $v : self::minGb();
    }

    public static function maxGbForPanel(array $u, int $panelId): int
    {
        $own = (int)($u['reseller_max_gb'] ?? 0);
        if ($own > 0) return max(self::minGbForPanel($panelId), $own);

        $v = (int)(self::panelTariff($panelId)['max_gb'] ?? 0);
        return $v > 0 ? max(self::minGbForPanel($panelId), $v) : self::maxGb();
    }

    public static function minDaysForPanel(int $panelId): int
    {
        $v = (int)(self::panelTariff($panelId)['min_days'] ?? 0);
        return $v > 0 ? $v : self::minDays();
    }

    public static function maxDaysForPanel(array $u, int $panelId): int
    {
        $own = (int)($u['reseller_max_days'] ?? 0);
        if ($own > 0) return max(self::minDaysForPanel($panelId), $own);

        $v = (int)(self::panelTariff($panelId)['max_days'] ?? 0);
        return $v > 0 ? max(self::minDaysForPanel($panelId), $v) : self::maxDays();
    }

    /** درصد تخفیف مؤثر روی این سرور (تخفیف سطح + تخفیف ویژهٔ سرور) */
    public static function panelDiscount(array $u, int $panelId): float
    {
        $pct = self::userDiscount($u) + (float)(self::panelTariff($panelId)['off'] ?? 0);
        return max(0.0, min(90.0, $pct));
    }

    /**
     * جدول قیمت سرورها برای یک نماینده
     * برای نمایش در مینی‌اپ، ربات و پنل مدیریت استفاده می‌شود.
     */
    public static function panelCards(array $u, int $gb = 0, int $days = 0): array
    {
        $out = [];
        foreach (self::panelsFor($u) as $p) {
            $pid = (int)$p['id'];
            $t   = self::panelTariff($pid);

            $row = [
                'id'       => $pid,
                'name'     => self::panelLabel($p),
                'raw_name' => (string)($p['name'] ?? ''),
                'gb'       => self::priceGbForPanel($u, $pid),
                'day'      => self::priceDayForPanel($u, $pid),
                'fee'      => self::baseFeeForPanel($pid),
                'mul'      => self::panelMul($pid),
                'off'      => (int)($t['off'] ?? 0),
                'min_gb'   => self::minGbForPanel($pid),
                'max_gb'   => self::maxGbForPanel($u, $pid),
                'min_days' => self::minDaysForPanel($pid),
                'max_days' => self::maxDaysForPanel($u, $pid),
                'level'    => (int)($t['lv'] ?? 0),
                'note'     => (string)($t['note'] ?? ''),
                'custom'   => $t !== [],
            ];

            $row['gb_txt']  = money($row['gb']);
            $row['day_txt'] = money($row['day']);

            if ($gb > 0 && $days > 0) {
                $pr = self::price($gb, $days, $u, $pid);
                $row['sample']     = (int)$pr['final'];
                $row['sample_txt'] = money((int)$pr['final']);
            }

            $out[] = $row;
        }
        return $out;
    }

    /* ==================== سطح کاربر ==================== */

    public static function level(array $u): int
    {
        $l = (int)($u['reseller_level'] ?? 0);
        return ($l === 1 || $l === 2) ? $l : 0;
    }

    public static function isReseller(array $u): bool
    {
        return self::enabled() && self::level($u) > 0;
    }

    public static function levelLabel(int $level): string
    {
        $m = self::LEVELS[$level] ?? self::LEVELS[0];
        return $m['icon'] . ' ' . $m['label'];
    }

    /** رنگ نشانگر سطح */
    public static function levelColor(int $level): string
    {
        $m = self::LEVEL_META[$level] ?? self::LEVEL_META[0];
        return (string)$m['color'];
    }

    /** توضیح کوتاه سطح */
    public static function levelDesc(int $level): string
    {
        $m = self::LEVEL_META[$level] ?? self::LEVEL_META[0];
        return (string)$m['desc'];
    }

    /** فقط نام سطح بدون ایموجی */
    public static function levelName(int $level): string
    {
        $m = self::LEVELS[$level] ?? self::LEVELS[0];
        return (string)$m['label'];
    }

    /** سقف بدهی این کاربر (مقدار اختصاصی کاربر بر تنظیم کلی ارجح است) */
    public static function userCredit(array $u): int
    {
        if (self::level($u) < 2) return 0;

        $own = (int)($u['reseller_credit'] ?? 0);
        return $own > 0 ? $own : self::creditLimit(2);
    }

    /** درصد تخفیف این کاربر */
    public static function userDiscount(array $u): float
    {
        $own = (float)($u['reseller_discount'] ?? 0);
        return $own > 0 ? max(0.0, min(90.0, $own)) : self::discount(self::level($u));
    }

    /* ==================== تعرفهٔ اختصاصی هر نماینده ==================== */

    /** قیمت هر گیگ برای این نماینده (۰ = تعرفهٔ عمومی) */
    public static function priceGbFor(array $u): int
    {
        $v = (int)($u['reseller_price_gb'] ?? 0);
        return $v > 0 ? $v : self::priceGb();
    }

    /** قیمت هر روز برای این نماینده */
    public static function priceDayFor(array $u): int
    {
        $v = (int)($u['reseller_price_day'] ?? 0);
        return $v > 0 ? $v : self::priceDay();
    }

    /** سقف حجم اختصاصی */
    public static function maxGbFor(array $u): int
    {
        $v = (int)($u['reseller_max_gb'] ?? 0);
        return $v > 0 ? max(self::minGb(), $v) : self::maxGb();
    }

    /** سقف مدت اختصاصی */
    public static function maxDaysFor(array $u): int
    {
        $v = (int)($u['reseller_max_days'] ?? 0);
        return $v > 0 ? max(self::minDays(), $v) : self::maxDays();
    }

    /** سرورهای مجاز این نماینده (خالی = فهرست عمومی) */
    public static function panelsFor(array $u): array
    {
        $all = [];
        foreach (self::panels() as $pn0) {
            if (!self::panelAllowed($u, (int)$pn0['id'])) continue;
            $all[] = $pn0;
        }
        if (!$all) $all = self::panels();

        $raw = trim((string)($u['reseller_panels'] ?? ''));
        if ($raw === '') return $all;

        $ids = array_values(array_filter(array_map('intval', preg_split('/[^0-9]+/', $raw) ?: [])));
        if (!$ids) return $all;

        $out = [];
        foreach ($all as $pn) {
            if (in_array((int)$pn['id'], $ids, true)) $out[] = $pn;
        }
        return $out !== [] ? $out : $all;
    }

    /** آیا این نماینده تعرفهٔ اختصاصی دارد؟ */
    public static function hasCustomTariff(array $u): bool
    {
        return (int)($u['reseller_price_gb'] ?? 0) > 0
            || (int)($u['reseller_price_day'] ?? 0) > 0
            || (int)($u['reseller_max_gb'] ?? 0) > 0
            || (int)($u['reseller_max_days'] ?? 0) > 0
            || trim((string)($u['reseller_panels'] ?? '')) !== '';
    }

    /** ذخیرهٔ تعرفهٔ اختصاصی */
    public static function setTariff(int $userId, array $in): array
    {
        $up = [
            'reseller_price_gb'  => max(0, (int)($in['price_gb'] ?? 0)),
            'reseller_price_day' => max(0, (int)($in['price_day'] ?? 0)),
            'reseller_max_gb'    => max(0, (int)($in['max_gb'] ?? 0)),
            'reseller_max_days'  => max(0, (int)($in['max_days'] ?? 0)),
            'reseller_panels'    => trim((string)($in['panels'] ?? '')),
            'reseller_note'      => mb_substr(trim((string)($in['note'] ?? '')), 0, 400),
        ];

        try {
            DB::update('users', $up, 'id = :id', [':id' => $userId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'ستون‌های تعرفهٔ اختصاصی در دیتابیس نیست. در صفحهٔ «به‌روزرسانی» دکمهٔ «بررسی و تکمیل ساختار دیتابیس» را بزنید.'];
        }
        return ['ok' => true, 'message' => 'تعرفهٔ اختصاصی این نماینده ذخیره شد.'];
    }

    /* ==================== قیمت‌گذاری ==================== */

    public static function price(int $gb, int $days, array $u, int $panelId = 0): array
    {
        $gb   = max(self::minGbForPanel($panelId),   min(self::maxGbForPanel($u, $panelId),   $gb));
        $days = max(self::minDaysForPanel($panelId), min(self::maxDaysForPanel($u, $panelId), $days));

        $pGb  = self::priceGbForPanel($u, $panelId);
        $pDay = self::priceDayForPanel($u, $panelId);
        $fee  = self::baseFeeForPanel($panelId);

        $base = ($gb * $pGb) + ($days * $pDay) + $fee;

        /* ضریب قیمت این سرور */
        $mul = self::panelMul($panelId);
        if ($mul !== 100) $base = (int)round($base * $mul / 100);

        $pct  = self::panelDiscount($u, $panelId);
        $off  = (int)round($base * $pct / 100);
        $fin  = max(0, $base - $off);

        $step = self::round();
        if ($step > 1) $fin = (int)(ceil($fin / $step) * $step);

        $t = self::panelTariff($panelId);

        return [
            'gb' => $gb, 'days' => $days,
            'base' => $base, 'discount_pct' => $pct, 'discount' => $off, 'final' => $fin,
            'price_gb' => $pGb, 'price_day' => $pDay, 'base_fee' => $fee,
            'base_txt' => money($base), 'final_txt' => money($fin),
            'custom' => self::hasCustomTariff($u),
            'panel_id'     => $panelId,
            'panel'        => $panelId > 0 ? self::panelLabel($panelId) : '',
            'panel_custom' => $t !== [],
            'panel_mul'    => $mul,
            'panel_off'    => (int)($t['off'] ?? 0),
        ];
    }

    /** وضعیت مالی نماینده */
    public static function quota(array $u): array
    {
        $bal    = (int)($u['balance'] ?? 0);
        $credit = self::userCredit($u);

        return [
            'balance'     => $bal,
            'balance_txt' => money($bal),
            'credit'      => $credit,
            'credit_txt'  => money($credit),
            'available'   => $bal + $credit,
            'avail_txt'   => money($bal + $credit),
            'debt'        => $bal < 0 ? -$bal : 0,
            'debt_txt'    => money($bal < 0 ? -$bal : 0),
        ];
    }

    /** آیا توان پرداخت دارد؟ */
    public static function canAfford(array $u, int $amount): array
    {
        $level  = self::level($u);
        $bal    = (int)($u['balance'] ?? 0);
        $credit = self::userCredit($u);

        if ($level === 1 && $bal < $amount) {
            return [
                'ok' => false,
                'message' => '⚠️ موجودی کافی نیست.' . "\n" . 'موجودی شما: ' . money($bal)
                    . "\n" . 'مبلغ لازم: ' . money($amount)
                    . "\n\n" . 'نمایندگی سطح ۱ امکان بدهکار شدن ندارد؛ اول کیف پول را شارژ کنید.',
            ];
        }

        if ($level === 2 && ($bal - $amount) < -$credit) {
            return [
                'ok' => false,
                'message' => '⚠️ از سقف بدهی مجاز عبور می‌کنید.' . "\n" . 'موجودی فعلی: ' . money($bal)
                    . "\n" . 'سقف بدهی: ' . money($credit)
                    . "\n" . 'مبلغ لازم: ' . money($amount),
            ];
        }

        return ['ok' => true, 'message' => ''];
    }

    /* ==================== کسر موجودی (با اجازه بدهی برای سطح ۲) ==================== */

    public static function charge(array $u, int $amount, string $note = ''): array
    {
        if ($amount <= 0) return ['ok' => true, 'tx' => 0];

        $fresh = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)$u['id']]);
        if (!$fresh) return ['ok' => false, 'message' => 'کاربر پیدا نشد.'];

        $can = self::canAfford($fresh, $amount);
        if (!$can['ok']) return ['ok' => false, 'message' => $can['message']];

        DB::q('UPDATE {p}users SET balance = balance - :a WHERE id = :id',
            [':a' => $amount, ':id' => (int)$fresh['id']]);

        $tx = DB::insert('transactions', [
            'user_id' => (int)$fresh['id'], 'tg_id' => (int)$fresh['tg_id'],
            'type' => 'purchase', 'method' => 'reseller',
            'amount' => -1 * $amount, 'status' => 'approved',
            'note' => mb_substr($note !== '' ? $note : 'خرید نمایندگی', 0, 250),
            'ref' => 'RS-' . strtoupper(rnd(8)),
            'created_at' => now(), 'decided_at' => now(),
        ]);

        return ['ok' => true, 'tx' => (int)$tx];
    }

    /** بازگرداندن مبلغ در صورت شکست ساخت */
    public static function refund(array $u, int $amount, string $note = ''): void
    {
        if ($amount <= 0) return;

        DB::q('UPDATE {p}users SET balance = balance + :a WHERE id = :id',
            [':a' => $amount, ':id' => (int)$u['id']]);

        DB::insert('transactions', [
            'user_id' => (int)$u['id'], 'tg_id' => (int)$u['tg_id'],
            'type' => 'refund', 'method' => 'reseller',
            'amount' => $amount, 'status' => 'approved',
            'note' => mb_substr($note !== '' ? $note : 'عودت وجه نمایندگی', 0, 250),
            'ref' => 'RR-' . strtoupper(rnd(8)),
            'created_at' => now(), 'decided_at' => now(),
        ]);
    }

    /* ==================== طرح‌های آمادهٔ نمایندگی ==================== */

    public static function normalizePlan(array $r): array
    {
        return [
            'id'         => trim((string)($r['id'] ?? '')),
            'title'      => mb_substr(trim((string)($r['title'] ?? '')), 0, 60),
            'gb'         => max(0, (int)($r['gb'] ?? 0)),
            'days'       => max(0, (int)($r['days'] ?? 0)),
            'price'      => max(0, (int)($r['price'] ?? 0)),
            'device'     => max(0, (int)($r['device'] ?? 0)),
            'speed_up'   => max(0, (int)($r['speed_up'] ?? 0)),
            'speed_down' => max(0, (int)($r['speed_down'] ?? 0)),
            'ip_limit'   => max(0, (int)($r['ip_limit'] ?? 0)),
            'panel_id'   => max(0, (int)($r['panel_id'] ?? 0)),
            'level'      => max(0, (int)($r['level'] ?? 0)),
            'active'     => !empty($r['active']),
            'sort'       => (int)($r['sort'] ?? 0),
            'note'       => mb_substr(trim((string)($r['note'] ?? '')), 0, 200),
        ];
    }

    /** فهرست همهٔ طرح‌های آماده */
    public static function plans(bool $onlyActive = false): array
    {
        $raw = jdec((string)DB::setting('rs_plans', ''), []);
        if (!is_array($raw)) $raw = [];

        $out = [];
        foreach ($raw as $r) {
            if (!is_array($r)) continue;
            $pl = self::normalizePlan($r);
            if ($pl['id'] === '' || $pl['title'] === '') continue;
            if ($onlyActive && !$pl['active']) continue;
            $out[] = $pl;
        }
        usort($out, static function (array $a, array $b) {
            if ($a['sort'] === $b['sort']) return strcmp($a['title'], $b['title']);
            return $a['sort'] <=> $b['sort'];
        });
        return $out;
    }

    public static function plan(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') return null;
        foreach (self::plans() as $pl) if ($pl['id'] === $id) return $pl;
        return null;
    }

    public static function savePlans(array $rows): int
    {
        $out = [];
        foreach ($rows as $r) {
            $pl = self::normalizePlan((array)$r);
            if ($pl['title'] === '') continue;
            if ($pl['id'] === '') $pl['id'] = 'pl' . substr(md5($pl['title'] . microtime(true) . rnd(4)), 0, 8);
            $out[] = $pl;
        }
        DB::setSetting('rs_plans', jenc($out));
        return count($out);
    }

    /** طرح‌های قابل استفاده برای این نماینده */
    public static function plansFor(array $u): array
    {
        $lv  = self::level($u);
        $out = [];
        foreach (self::plans(true) as $pl) {
            if ((int)$pl['level'] > 0 && $lv < (int)$pl['level']) continue;
            $out[] = $pl;
        }
        return $out;
    }

    /** قیمت یک طرح آماده برای این نماینده */
    public static function planPrice(array $plan, array $u, int $panelId = 0): array
    {
        $pid = $panelId > 0 ? $panelId : max(0, (int)($plan['panel_id'] ?? 0));

        if ((int)($plan['price'] ?? 0) > 0) {
            $base = (int)$plan['price'];

            $mul = self::panelMul($pid);
            if ($mul !== 100) $base = (int)round($base * $mul / 100);

            $pct   = self::panelDiscount($u, $pid);
            $disc  = (int)round($base * $pct / 100);
            $final = max(0, $base - $disc);

            return [
                'gb' => (int)($plan['gb'] ?? 0), 'days' => (int)($plan['days'] ?? 0),
                'base' => $base, 'discount_pct' => $pct, 'discount' => $disc, 'final' => $final,
                'base_txt' => money($base), 'final_txt' => money($final),
                'panel_id' => $pid, 'panel' => $pid > 0 ? self::panelLabel($pid) : '',
                'plan' => true,
            ];
        }

        return self::price((int)($plan['gb'] ?? 0), (int)($plan['days'] ?? 0), $u, $pid);
    }

    public static function planLabel(array $pl): string
    {
        $vol = (int)($pl['gb'] ?? 0) > 0 ? fa_num((string)(int)$pl['gb']) . ' گی��ابایت' : 'نامحدود';
        $day = (int)($pl['days'] ?? 0) > 0 ? fa_num((string)(int)$pl['days']) . ' روز' : 'بدون انقضا';
        return $vol . ' – ' . $day;
    }

    /* ==================== مارک (پیشوند) نام کانفیگ ==================== */

    public static function markFor(array $u): string
    {
        $m = strtolower((string)preg_replace('/[^a-zA-Z0-9]/', '', en_num(trim((string)($u['reseller_mark'] ?? '')))));
        return substr($m, 0, 10);
    }

    /** افزودن مارک به ابتدای نام کانفیگ بدون تکرار (جلوگیری از دابل‌نیم) */
    public static function applyMark(array $u, string $name): string
    {
        $mark = self::markFor($u);
        $n    = self::cleanName($name);
        if ($mark === '') return $n;
        if ($n === '')    return substr($mark . '_' . strtolower(rnd(5)), 0, 24);
        if (str_starts_with(strtolower($n), $mark)) return substr($n, 0, 24);
        return substr($mark . '_' . $n, 0, 24);
    }

    /* ==================== نام کانفیگ ==================== */

    public static function cleanName(string $name): string
    {
        $n = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', en_num(trim($name))) ?? '');
        return substr($n, 0, 24);
    }

    /* ==================== ساخت کانفیگ ==================== */

    public static function create(array $u, array $in): array
    {
        if (!self::enabled())      return ['ok' => false, 'message' => 'بخش نمایندگی فعلاً غیرفعال است.'];
        if (!self::isReseller($u)) return ['ok' => false, 'message' => 'شما دسترسی نمایندگی ندارید.'];

        /* سقف‌های روزانه/ماهانه، سقف سرویس فعال و احراز هویت */
        $qg = self::quotaGate($u);
        if (empty($qg['ok'])) return ['ok' => false, 'message' => (string)$qg['message']];

        $gb   = (int)($in['volume_gb'] ?? 0);
        $days = (int)($in['days'] ?? 0);

        /* طرح آماده: حجم، مدت و محدودیت‌ها از خود طرح خوانده می‌شود */
        $plan = null;
        if (trim((string)($in['plan_id'] ?? '')) !== '') {
            $plan = self::plan((string)$in['plan_id']);
            if (!$plan)                 return ['ok' => false, 'message' => 'این طرح آماده دیگر در دسترس نیست.'];
            if (empty($plan['active'])) return ['ok' => false, 'message' => 'این طرح غیرفعال شده است.'];
            if ((int)$plan['level'] > 0 && self::level($u) < (int)$plan['level']) {
                return ['ok' => false, 'message' => 'این طرح مخصوص نمایندگان سطح بالاتر است.'];
            }
            $gb   = (int)$plan['gb'];
            $days = (int)$plan['days'];
        }

        if (!$plan && ($gb <= 0 || $days <= 0)) {
            return ['ok' => false, 'message' => 'حجم و مدت را وارد کنید.'];
        }

        $panelId = (int)($in['panel_id'] ?? 0);
        if ($panelId <= 0 && $plan && (int)$plan['panel_id'] > 0) $panelId = (int)$plan['panel_id'];
        $panel   = $panelId > 0 ? self::panelById($panelId) : null;
        if (!$panel) {
            $list  = self::panels();
            $panel = $list[0] ?? null;
        }
        if (!$panel) return ['ok' => false, 'message' => 'هیچ سرور فعالی برای نمایندگی تنظیم نشده است.'];

        $pid = (int)($panel['id'] ?? 0);

        if (!self::panelAllowed($u, $pid)) {
            return ['ok' => false, 'message' => 'سرور «' . self::panelLabel($panel) . '» برای سطح شما فعال نیست.'];
        }

        /* محدودهٔ حجم و مدتِ همین سرور */
        if (!$plan) {
            $mnG = self::minGbForPanel($pid);
            $mxG = self::maxGbForPanel($u, $pid);
            if ($gb < $mnG || $gb > $mxG) {
                return ['ok' => false, 'message' => 'روی سرور «' . self::panelLabel($panel) . '» حجم باید بین '
                    . fa_num($mnG) . ' تا ' . fa_num($mxG) . ' گیگابایت باشد.'];
            }

            $mnD = self::minDaysForPanel($pid);
            $mxD = self::maxDaysForPanel($u, $pid);
            if ($days < $mnD || $days > $mxD) {
                return ['ok' => false, 'message' => 'روی سرور «' . self::panelLabel($panel) . '» مدت باید بین '
                    . fa_num($mnD) . ' تا ' . fa_num($mxD) . ' روز باشد.'];
            }
        }

        $pr    = $plan ? self::planPrice($plan, $u, $pid) : self::price($gb, $days, $u, $pid);
        $final = (int)$pr['final'];

        $can = self::canAfford($u, $final);
        if (!$can['ok']) return ['ok' => false, 'message' => $can['message'], 'price' => $pr];

        $name = self::cleanName((string)($in['name'] ?? ''));
        if ($name !== '' && strlen($name) < 3) {
            return ['ok' => false, 'message' => 'نام کانفیگ باید حداقل ۳ کاراکتر لاتین باشد.'];
        }

        /* مارک اختصاصی نماینده به ابتدای نام اضافه می‌شود (بدون تکرار) */
        $name = self::applyMark($u, $name);

        // اگر نام داده شده، روش نام‌گذاری را موقتاً روی دلخواه می‌گذاریم
        if ($name !== '') $panel['username_mode'] = 'custom';

        // ۱) کسر موجودی
        $ch = self::charge($u, $final, 'نمایندگی: ' . fa_num($gb) . ' گیگ و ' . fa_num($days) . ' روز');
        if (empty($ch['ok'])) return ['ok' => false, 'message' => (string)($ch['message'] ?? 'کسر موجودی ناموفق بود.')];

        // ۲) ثبت سفارش
        $orderId = DB::insert('orders', [
            'user_id' => (int)$u['id'], 'tg_id' => (int)$u['tg_id'],
            'product_id' => 0, 'type' => 'new',
            'amount' => (int)$pr['base'], 'discount_amount' => (int)$pr['discount'],
            'final_amount' => $final, 'status' => 'pending', 'created_at' => now(),
        ]);

        // ۳) ساخت کانفیگ
        try {
            $res = Svc::create($u, $panel, [
                'volume_gb' => (float)$gb,
                'days'      => $days,
                'ip_limit'  => $plan && (int)$plan['ip_limit'] > 0 ? (int)$plan['ip_limit'] : self::ipLimit(),
                'username'  => $name !== '' ? $name : null,
                'device_limit' => $plan ? (int)$plan['device'] : 0,
                'speed_up'     => $plan ? (int)$plan['speed_up'] : 0,
                'speed_down'   => $plan ? (int)$plan['speed_down'] : 0,
            ]);
        } catch (Throwable $e) {
            $res = ['ok' => false, 'message' => 'خطای ارتباط با سرور: ' . $e->getMessage()];
        }

        if (empty($res['ok']) || empty($res['service'])) {
            self::refund($u, $final, 'عودت وجه نمایندگی (ساخت ناموفق)');
            DB::update('orders', ['status' => 'failed'], 'id = :id', [':id' => (int)$orderId]);

            return [
                'ok' => false,
                'message' => '⚠️ ساخت کانفیگ ناموفق بود و مبلغ به موجودی شما بازگشت.' . "\n"
                    . (string)($res['message'] ?? ''),
            ];
        }

        $service = (array)$res['service'];

        DB::update('orders', ['status' => 'paid', 'service_id' => (int)$service['id']], 'id = :id', [':id' => (int)$orderId]);

        try {
            DB::update('services', ['is_reseller' => 1], 'id = :id', [':id' => (int)$service['id']]);
        } catch (Throwable $e) {
            // اگر مایگریشن اجرا نشده باشد مهم نیست
        }

        $after = (int)DB::val('SELECT balance FROM {p}users WHERE id = :id', [':id' => (int)$u['id']], 0);

        if (class_exists('Logs')) {
            Logs::send('purchases', Logs::fmt('🏷 ساخت کانفیگ توسط نماینده', [
                'نماینده' => '<code>' . (int)$u['tg_id'] . '</code>',
                'سطح'     => self::levelLabel(self::level($u)),
                'سرور'    => (string)($panel['name'] ?? '-'),
                'حجم'      => fa_num($gb) . ' گیگابایت',
                'مدت'      => fa_num($days) . ' روز',
                'مبلغ'     => money($final),
                'موجودی'   => money($after),
            ]));
        }

        return [
            'ok' => true,
            'service' => $service,
            'order_id' => (int)$orderId,
            'price' => $pr,
            'balance' => $after,
            'balance_txt' => money($after),
            'message' => '✅ کانفیگ ساخته شد.',
        ];
    }

    /* ==================== گزارش‌ها ==================== */

    /**
     * کانفیگ های زنده یک نماینده
     *
     * ۱) ردیف های حذف شده (سطل زباله) هرگز در فهرست نمی آیند.
     * ۲) پیش از برگرداندن، مصرف از خود پنل زنده خوانده می شود تا شمارنده
     *    روی صفر نماند؛ حتی اگر کران روی هاست اجرا نشده باشد.
     */
    public static function services(array $u, int $limit = 50, bool $live = true): array
    {
        $limit = max(1, min(200, $limit));

        try {
            $rows = DB::all("SELECT * FROM {p}services
                    WHERE user_id = :u AND status <> 'deleted'
                      AND COALESCE(is_reseller, 0) = 1
                    ORDER BY id DESC LIMIT " . $limit, [':u' => (int)$u['id']]);
        } catch (Throwable $e) {
            $rows = DB::all("SELECT * FROM {p}services WHERE user_id = :u AND status <> 'deleted'
                    ORDER BY id DESC LIMIT " . $limit, [':u' => (int)$u['id']]);
        }

        if ($live && $rows && class_exists('Svc') && method_exists('Svc', 'syncStale')) {
            try {
                $rows = Svc::syncStale($rows, (int)DB::setting('rs_sync_ttl', '150'), 14);
            } catch (Throwable $e) {
                app_log('reseller', 'live sync: ' . $e->getMessage());
            }
        }
        return $rows;
    }

    public static function stats(array $u): array
    {
        $uid = (int)$u['id'];

        try {
            $base = " FROM {p}services WHERE user_id = :u AND status <> 'deleted'"
                . ' AND COALESCE(is_reseller, 0) = 1';
            $total = (int)DB::val('SELECT COUNT(*)' . $base, [':u' => $uid], 0);
            $act   = (int)DB::val('SELECT COUNT(*)' . $base . " AND status = 'active'", [':u' => $uid], 0);
            $usedB = (int)DB::val('SELECT COALESCE(SUM(used_bytes),0)' . $base, [':u' => $uid], 0);
        } catch (Throwable $e) {
            $total = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE user_id = :u AND status <> 'deleted'", [':u' => $uid], 0);
            $act   = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE user_id = :u AND status = 'active'", [':u' => $uid], 0);
            $usedB = 0;
        }
        $spent = (int)DB::val("SELECT COALESCE(SUM(final_amount),0) FROM {p}orders WHERE user_id = :u AND status = 'paid'", [':u' => $uid], 0);
        $trash = class_exists('Svc') ? (int)Svc::trashCount($uid) : 0;

        return [
            'services'  => $total,
            'active'    => $act,
            'spent'     => $spent,
            'spent_txt' => money($spent),
            'used'      => $usedB,
            'used_txt'  => human_bytes($usedB),
            'trash'     => $trash,
        ];
    }

    /** آمار کلی همهٔ نمایندگان */
    public static function overview(): array
    {
        $rows = self::all(1000);

        $l1 = 0; $l2 = 0; $debt = 0; $bal = 0; $credit = 0;
        foreach ($rows as $r) {
            $lv = (int)($r['reseller_level'] ?? 0);
            if ($lv === 1) $l1++;
            if ($lv === 2) $l2++;
            $b = (int)($r['balance'] ?? 0);
            if ($b > 0) $bal += $b;
            if ($b < 0) $debt += -$b;
            $credit += self::userCredit($r);
        }

        $svc  = (int)DB::val('SELECT COUNT(*) FROM {p}services WHERE is_reseller = 1', [], 0);
        $act  = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE is_reseller = 1 AND status = 'active'", [], 0);
        $sold = (int)DB::val("SELECT COALESCE(SUM(-amount),0) FROM {p}transactions
            WHERE method = 'reseller' AND amount < 0 AND status = 'approved'", [], 0);
        $mon  = (int)DB::val("SELECT COALESCE(SUM(-amount),0) FROM {p}transactions
            WHERE method = 'reseller' AND amount < 0 AND status = 'approved'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", [], 0);

        /* آمار تکمیلی */
        $debtors = 0; $discSum = 0.0;
        foreach ($rows as $r) {
            if ((int)($r['balance'] ?? 0) < 0) $debtors++;
            $discSum += self::userDiscount($r);
        }
        $avgDisc = count($rows) > 0 ? round($discSum / count($rows), 1) : 0.0;

        $today = 0; $week = 0; $exp = 0; $new30 = 0;
        try {
            $today = (int)DB::val("SELECT COALESCE(SUM(-amount),0) FROM {p}transactions
                WHERE method = 'reseller' AND amount < 0 AND status = 'approved'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)", [], 0);
            $week  = (int)DB::val("SELECT COALESCE(SUM(-amount),0) FROM {p}transactions
                WHERE method = 'reseller' AND amount < 0 AND status = 'approved'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", [], 0);
        } catch (Throwable $e) {
        }
        try {
            $exp = (int)DB::val("SELECT COUNT(*) FROM {p}services
                WHERE is_reseller = 1 AND status = 'expired'", [], 0);
        } catch (Throwable $e) {
        }
        try {
            $new30 = (int)DB::val('SELECT COUNT(*) FROM {p}users
                WHERE reseller_level > 0 AND reseller_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)', [], 0);
        } catch (Throwable $e) {
        }

        $plans   = 0; $bundles = 0; $reqs = 0;
        try { $plans   = count(self::plans()); }   catch (Throwable $e) { }
        try { $bundles = count(self::bundles()); } catch (Throwable $e) { }
        try { $reqs    = self::requestCount(); }   catch (Throwable $e) { }

        $free = max(0, $credit - $debt);

        return [
            'total' => count($rows), 'l1' => $l1, 'l2' => $l2,
            'debt' => $debt, 'debt_txt' => money($debt),
            'balance' => $bal, 'balance_txt' => money($bal),
            'credit' => $credit, 'credit_txt' => money($credit),
            'credit_free' => $free, 'credit_free_txt' => money($free),
            'credit_pct' => $credit > 0 ? (int)round(min(100, $debt / $credit * 100)) : 0,
            'services' => $svc, 'active' => $act, 'expired' => $exp,
            'sold' => $sold, 'sold_txt' => money($sold),
            'month' => $mon, 'month_txt' => money($mon),
            'week' => $week, 'week_txt' => money($week),
            'today' => $today, 'today_txt' => money($today),
            'debtors' => $debtors,
            'avg_discount' => $avgDisc,
            'avg_cfg' => count($rows) > 0 ? (int)round($svc / count($rows)) : 0,
            'new30' => $new30,
            'plans' => $plans, 'bundles' => $bundles, 'requests' => $reqs,
        ];
    }

    /** فروش روزانهٔ نمایندگان برای نمودار کوچک پنل */
    public static function daily(int $days = 14): array
    {
        $days = max(3, min(60, $days));
        $map  = [];
        try {
            $rows = DB::all("SELECT DATE(created_at) AS d,
                       COALESCE(SUM(-amount),0) AS s, COUNT(*) AS c
                FROM {p}transactions
                WHERE method = 'reseller' AND amount < 0 AND status = 'approved'
                  AND created_at >= DATE_SUB(CURDATE(), INTERVAL " . ($days - 1) . " DAY)
                GROUP BY DATE(created_at)");
            foreach ($rows as $r) {
                $map[(string)$r['d']] = ['s' => (int)$r['s'], 'c' => (int)$r['c']];
            }
        } catch (Throwable $e) {
            $map = [];
        }

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime('-' . $i . ' day'));
            $out[] = [
                'date'  => $d,
                'day'   => (int)date('j', strtotime($d)),
                'sum'   => (int)($map[$d]['s'] ?? 0),
                'count' => (int)($map[$d]['c'] ?? 0),
            ];
        }
        return $out;
    }

    /** وضعیت مالی یک نماینده: بدهی، سقف و درصد مصرف اعتبار */
    public static function health(array $u): array
    {
        $bal    = (int)($u['balance'] ?? 0);
        $credit = self::userCredit($u);
        $debt   = $bal < 0 ? -$bal : 0;
        $pct    = $credit > 0 ? (int)round(min(100, $debt / $credit * 100)) : ($debt > 0 ? 100 : 0);

        if ($debt <= 0) {
            $key = 'ok';   $label = 'بدون بدهی'; $icon = '✅';
        } elseif ($pct >= 100) {
            $key = 'bad';  $label = 'سقف اعتبار پر شده'; $icon = '⛔️';
        } elseif ($pct >= 60) {
            $key = 'warn'; $label = 'نزدیک سقف اعتبار'; $icon = '⚠️';
        } else {
            $key = 'mid';  $label = 'بدهی در حد مجاز'; $icon = '🔹';
        }

        return [
            'balance' => $bal,
            'debt'    => $debt,
            'credit'  => $credit,
            'free'    => max(0, $credit - $debt),
            'pct'     => $pct,
            'key'     => $key,
            'label'   => $label,
            'icon'    => $icon,
        ];
    }

    /** شمار کانفیگ همهٔ نمایندگان با یک کوئری */
    public static function configCounts(): array
    {
        $out = [];
        try {
            $rows = DB::all("SELECT user_id,
                       COUNT(*) AS c,
                       SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS a
                FROM {p}services
                WHERE is_reseller = 1 AND status <> 'deleted'
                GROUP BY user_id");
            foreach ($rows as $r) {
                $out[(int)$r['user_id']] = [
                    'total'  => (int)$r['c'],
                    'active' => (int)$r['a'],
                ];
            }
        } catch (Throwable $e) {
            $out = [];
        }
        return $out;
    }

    /** گردش مالی یک نماینده */
    public static function ledger(array $u, int $limit = 40): array
    {
        $limit = max(1, min(200, $limit));
        return DB::all('SELECT * FROM {p}transactions WHERE user_id = :u ORDER BY id DESC LIMIT ' . $limit,
            [':u' => (int)$u['id']]);
    }

    /** پرکارترین نمایندگان */
    public static function top(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return DB::all("SELECT u.id, u.tg_id, u.first_name, u.username, u.reseller_level, u.balance,
                   COUNT(s.id) AS cfg
            FROM {p}users u
            LEFT JOIN {p}services s ON s.user_id = u.id AND s.is_reseller = 1
            WHERE u.reseller_level > 0
            GROUP BY u.id, u.tg_id, u.first_name, u.username, u.reseller_level, u.balance
            ORDER BY cfg DESC, u.id ASC LIMIT " . $limit);
    }

    /** فهرست نمایندگان برای پنل مدیریت */
    public static function all(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        return DB::all('SELECT * FROM {p}users WHERE reseller_level > 0 ORDER BY reseller_level DESC, balance ASC LIMIT ' . $limit);
    }

    /** تغییر سطح نمایندگی */
    public static function setLevel(int $userId, int $level, array $opts = []): array
    {
        $level = in_array($level, [0, 1, 2], true) ? $level : 0;

        $u = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => $userId]);
        if (!$u) return ['ok' => false, 'message' => 'کاربر پیدا نشد.'];

        $up = ['reseller_level' => $level];

        if (array_key_exists('credit', $opts))   $up['reseller_credit']   = max(0, (int)$opts['credit']);
        if (array_key_exists('discount', $opts)) $up['reseller_discount'] = max(0, min(90, (int)$opts['discount']));

        if ($level === 0) {
            $up['reseller_credit']   = 0;
            $up['reseller_discount'] = 0;
        } else {
            $up['reseller_at'] = now();
        }

        try {
            DB::update('users', $up, 'id = :id', [':id' => $userId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'ستون‌های نمایندگی در دیتابیس نیست. در صفحهٔ «به‌روزرسانی» دکمهٔ «بررسی و تکمیل ساختار دیتابیس» را بزنید.'];
        }

        if (class_exists('Logs')) {
            Logs::send('users', Logs::fmt('🏷 تغییر سطح نمایندگی', [
                'کاربر'     => '<code>' . (int)$u['tg_id'] . '</code>',
                'سطح جدید' => self::levelLabel($level),
            ]));
        }

        return ['ok' => true, 'message' => 'سطح نمایندگی به‌روز شد.', 'level' => $level];
    }

    /* ==================== درخواست نمایندگی ==================== */

    /** آیا کاربران می‌توانند درخواست نمایندگی بدهند؟ */
    /* ==================== هزینهٔ درخواست نمایندگی ==================== */

    public static function reqFee(): int        { return max(0, (int)DB::setting('rs_req_fee', 0)); }
    public static function reqFeeCredit(): bool { return (string)DB::setting('rs_req_fee_credit', '0') === '1'; }
    public static function reqAuto(): bool      { return (string)DB::setting('rs_req_auto', '0') === '1'; }

    public static function reqFeeTarget(): string
    {
        return (string)DB::setting('rs_req_fee_target', 'credit') === 'wallet' ? 'wallet' : 'credit';
    }

    public static function reqLevel(): int
    {
        $l = (int)DB::setting('rs_req_level', 1);
        return in_array($l, [1, 2], true) ? $l : 1;
    }

    /**
     * پرداخت هزینهٔ نمایندگی از کیف پول و سپس ثبت یا تایید درخواست
     */
    public static function payRequest(array $u, string $note = ''): array
    {
        $uid = (int)($u['id'] ?? 0);
        if ($uid <= 0)             return ['ok' => false, 'message' => 'کاربر نامعتبر است.'];
        if (!self::enabled())      return ['ok' => false, 'message' => 'بخش نمایندگی غیرفعال است.'];
        if (!self::requestsOpen()) return ['ok' => false, 'message' => 'ثبت درخواست نمایندگی فعلاً بسته است.'];
        if (self::isReseller($u))  return ['ok' => false, 'message' => 'شما از قبل نماینده هستید.'];

        $row = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => $uid]) ?: $u;
        $fee = self::reqFee();

        if ($fee > 0) {
            $bal = (int)($row['balance'] ?? 0);
            if ($bal < $fee) {
                return ['ok' => false, 'need' => $fee - $bal,
                    'message' => 'موجودی کیف پول شما برای پرداخت هزینهٔ نمایندگی کافی نیست.'];
            }
            DB::q('UPDATE {p}users SET balance = balance - :f WHERE id = :id', [':f' => $fee, ':id' => $uid]);
            try {
                DB::insert('transactions', [
                    'user_id' => $uid, 'tg_id' => (int)($row['tg_id'] ?? 0),
                    'amount' => -$fee, 'status' => 'approved', 'method' => 'wallet',
                    'note' => 'هزینهٔ فعال‌سازی نمایندگی', 'created_at' => now(), 'decided_at' => now(),
                ]);
            } catch (Throwable $e) {
                /* ثبت تراکنش اختیاری است */
            }
        }

        $back = 0;
        if ($fee > 0 && self::reqFeeCredit()) {
            $back = $fee;
            if (self::reqFeeTarget() === 'wallet') {
                DB::q('UPDATE {p}users SET balance = balance + :f WHERE id = :id', [':f' => $fee, ':id' => $uid]);
            } else {
                try {
                    DB::q('UPDATE {p}users SET reseller_credit = reseller_credit + :f WHERE id = :id', [':f' => $fee, ':id' => $uid]);
                } catch (Throwable $e) {
                    DB::q('UPDATE {p}users SET balance = balance + :f WHERE id = :id', [':f' => $fee, ':id' => $uid]);
                }
            }
        }

        if (class_exists('Logs')) {
            Logs::send('financial', Logs::fmt('🏷 پرداخت هزینهٔ نمایندگی', [
                'کاربر' => '<code>' . (int)($row['tg_id'] ?? 0) . '</code>',
                'مبلغ'  => money($fee) . ' ' . currency(),
                'برگشت' => $back > 0 ? money($back) . ' ' . currency() : '-',
            ]));
        }

        if (self::reqAuto()) {
            $lvl = self::reqLevel();
            $r   = self::setLevel($uid, $lvl, []);
            if (empty($r['ok'])) return $r;
            self::clearRequest($uid);
            return ['ok' => true, 'approved' => true, 'fee' => $fee, 'credit' => $back,
                'level' => $lvl, 'message' => 'نمایندگی شما فعال شد.'];
        }

        $rq = self::request($row, $note !== '' ? $note : 'پرداخت هزینه انجام شد');
        if (empty($rq['ok'])) return $rq;

        return ['ok' => true, 'approved' => false, 'fee' => $fee, 'credit' => $back,
            'message' => 'درخواست شما ثبت شد.'];
    }

    public static function requestsOpen(): bool
    {
        return (string)DB::setting('rs_requests_open', '1') === '1';
    }

    /** آیا این کاربر درخواست باز دارد؟ */
    public static function hasRequest(array $u): bool
    {
        return (int)($u['reseller_req'] ?? 0) === 1;
    }

    /** ثبت درخواست نمایندگی */
    public static function request(array $u, string $note = ''): array
    {
        $uid = (int)($u['id'] ?? 0);
        if ($uid <= 0)             return ['ok' => false, 'message' => 'کاربر نامعتبر است.'];
        if (!self::enabled())      return ['ok' => false, 'message' => 'بخش نمایندگی غیرفعال است.'];
        if (!self::requestsOpen()) return ['ok' => false, 'message' => 'ثبت درخواست نمایندگی فعلاً بسته است.'];
        if (self::isReseller($u))  return ['ok' => false, 'message' => 'شما از قبل نماینده هستید.'];

        try {
            $row = DB::one('SELECT reseller_req FROM {p}users WHERE id = :id', [':id' => $uid]);
            if ($row && (int)($row['reseller_req'] ?? 0) === 1) {
                return ['ok' => false, 'message' => 'درخواست شما قبلاً ثبت شده و در انتظار بررسی است.'];
            }

            DB::update('users', [
                'reseller_req'      => 1,
                'reseller_req_at'   => now(),
                'reseller_req_note' => mb_substr(trim($note), 0, 380),
            ], 'id = :id', [':id' => $uid]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'ثبت درخواست ممکن نشد. مدیر باید مایگریشن تازه را اجرا کند.'];
        }

        if (class_exists('Logs')) {
            Logs::send('users', Logs::fmt('🏷 درخواست نمایندگی تازه', [
                'کاربر'    => '<code>' . (int)($u['tg_id'] ?? 0) . '</code>',
                'نام'      => trim((string)($u['first_name'] ?? '-')),
                'توضیح' => $note !== '' ? mb_substr($note, 0, 120) : '-',
            ]));
        }

        return ['ok' => true, 'message' => 'درخواست نمایندگی شما ثبت شد.'];
    }

    /** پاک کردن پرچم درخواست */
    public static function clearRequest(int $userId): void
    {
        try {
            DB::update('users', ['reseller_req' => 0], 'id = :id', [':id' => $userId]);
        } catch (Throwable $e) {
        }
    }

    /** لیست درخواست‌های در انتظار */
    public static function requests(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        try {
            return DB::all('SELECT * FROM {p}users WHERE reseller_req = 1 ORDER BY reseller_req_at DESC LIMIT ' . $limit);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** تعداد درخواست‌های باز */
    public static function requestCount(): int
    {
        try {
            return (int)DB::val('SELECT COUNT(*) FROM {p}users WHERE reseller_req = 1');
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** تایید درخواست و ارتقا به نماینده */
    public static function approveRequest(int $userId, int $level = 1, array $opts = []): array
    {
        $r = self::setLevel($userId, $level > 0 ? $level : 1, $opts);
        if (!empty($r['ok'])) self::clearRequest($userId);
        return $r;
    }

    /* ==================================================================
       سرویس‌های آماده (چند پنل / چند اینباند)

       مدیر یک «سرویس» می‌سازد (مثلاً g1)، از پنل‌هایی که اضافه کرده
       اینباندهای دلخواه را انتخاب می‌کند و به آن نام می‌دهد.
       نماینده در مینی‌اپ فقط همان سرویس را انتخاب می‌کند و همهٔ
       کانفیگ‌های آن ساخته و لینک اشتراک (ساب) برایش فرستاده می‌شود.

       برای هر پنل یک سرویس مستقل با ساب اختصاصی ساخته می‌شود و همهٔ
       اینباندهای انتخاب‌شدهٔ همان پنل داخل همان ساب قرار می‌گیرند.
       ================================================================== */

    /** «۱, ۳ ,5» ← «1,3,5» */
    public static function idsCsv($raw): string
    {
        $out = [];
        foreach (preg_split('/[^0-9]+/', en_num((string)$raw)) ?: [] as $x) {
            $n = (int)$x;
            if ($n > 0 && !in_array($n, $out, true)) $out[] = $n;
        }
        return implode(',', $out);
    }

    public static function normalizeBundle(array $r): array
    {
        $items = [];
        foreach ((array)($r['items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $pid = max(0, (int)($it['panel_id'] ?? 0));
            $ibs = self::idsCsv($it['inbounds'] ?? '');
            if ($pid <= 0 || $ibs === '') continue;
            $items[(string)$pid] = ['panel_id' => $pid, 'inbounds' => $ibs];
        }

        return [
            'id'         => trim((string)($r['id'] ?? '')),
            'title'      => mb_substr(trim((string)($r['title'] ?? '')), 0, 60),
            'gb'         => max(0, (int)($r['gb'] ?? 0)),
            'days'       => max(0, (int)($r['days'] ?? 0)),
            'price'      => max(0, (int)($r['price'] ?? 0)),
            'device'     => max(0, (int)($r['device'] ?? 0)),
            'speed_up'   => max(0, (int)($r['speed_up'] ?? 0)),
            'speed_down' => max(0, (int)($r['speed_down'] ?? 0)),
            'ip_limit'   => max(0, (int)($r['ip_limit'] ?? 0)),
            'level'      => max(0, (int)($r['level'] ?? 0)),
            'custom'     => !empty($r['custom']),
            'active'     => !empty($r['active']),
            'sort'       => (int)($r['sort'] ?? 0),
            'note'       => mb_substr(trim((string)($r['note'] ?? '')), 0, 200),
            'items'      => array_values($items),
        ];
    }

    /** فهرست همهٔ سرویس‌های تعریف‌شده */
    public static function bundles(bool $onlyActive = false): array
    {
        $raw = jdec((string)DB::setting('rs_bundles', ''), []);
        if (!is_array($raw)) $raw = [];

        $out = [];
        foreach ($raw as $r) {
            if (!is_array($r)) continue;
            $b = self::normalizeBundle($r);
            if ($b['id'] === '' || $b['title'] === '') continue;
            if ($onlyActive && (empty($b['active']) || !$b['items'])) continue;
            $out[] = $b;
        }

        usort($out, static function (array $a, array $b) {
            if ($a['sort'] === $b['sort']) return strcmp($a['title'], $b['title']);
            return $a['sort'] <=> $b['sort'];
        });
        return $out;
    }

    public static function bundle(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') return null;
        foreach (self::bundles() as $b) if ($b['id'] === $id) return $b;
        return null;
    }

    public static function saveBundles(array $rows): int
    {
        $out = [];
        foreach ($rows as $r) {
            $b = self::normalizeBundle((array)$r);
            if ($b['title'] === '') continue;
            if ($b['id'] === '') $b['id'] = 'sv' . substr(md5($b['title'] . microtime(true) . rnd(4)), 0, 8);
            $out[] = $b;
        }
        DB::setSetting('rs_bundles', jenc($out));
        return count($out);
    }

    /** تعداد کانفیگ‌هایی که این سرویس می‌سازد */
    public static function bundleConfigCount(array $b): int
    {
        $n = 0;
        foreach ((array)($b['items'] ?? []) as $it) {
            $n += count(array_filter(explode(',', (string)($it['inbounds'] ?? ''))));
        }
        return $n;
    }

    public static function bundleLabel(array $b): string
    {
        $vol = (int)($b['gb'] ?? 0)   > 0 ? fa_num((string)(int)$b['gb'])   . ' گیگ' : 'نامحدود';
        $day = (int)($b['days'] ?? 0) > 0 ? fa_num((string)(int)$b['days']) . ' روز' : 'بدون انقضا';
        if (!empty($b['custom'])) return 'حجم و مدت دلخواه';
        return $vol . ' – ' . $day;
    }

    /** سرویس‌های قابل استفاده برای این نماینده */
    public static function bundlesFor(array $u): array
    {
        $lv    = self::level($u);
        $allow = [];
        foreach (self::panelsFor($u) as $pn) $allow[] = (int)$pn['id'];

        $out = [];
        foreach (self::bundles(true) as $b) {
            if ((int)$b['level'] > 0 && $lv < (int)$b['level']) continue;

            $ok = true;
            foreach ($b['items'] as $it) {
                if (!in_array((int)$it['panel_id'], $allow, true)) { $ok = false; break; }
            }
            if (!$ok) continue;

            $out[] = $b;
        }
        return $out;
    }

    /** قیمت یک سرویس برای این نماینده */
    public static function bundlePrice(array $b, array $u, int $gb = 0, int $days = 0): array
    {
        if (empty($b['custom'])) {
            $gb   = (int)($b['gb'] ?? 0);
            $days = (int)($b['days'] ?? 0);
        }

        /* قیمت روی نخستین سرورِ همین سرویس حساب می‌شود */
        $pid = 0;
        foreach ((array)($b['items'] ?? []) as $it0) {
            $pid = max(0, (int)($it0['panel_id'] ?? 0));
            if ($pid > 0) break;
        }

        if ((int)($b['price'] ?? 0) > 0) {
            $base = (int)$b['price'];

            $mul = self::panelMul($pid);
            if ($mul !== 100) $base = (int)round($base * $mul / 100);

            $pct  = self::panelDiscount($u, $pid);
            $off  = (int)round($base * $pct / 100);
            $fin  = max(0, $base - $off);

            return [
                'gb' => $gb, 'days' => $days,
                'base' => $base, 'discount_pct' => $pct, 'discount' => $off, 'final' => $fin,
                'base_txt' => money($base), 'final_txt' => money($fin),
                'panel_id' => $pid,
                'bundle' => true,
            ];
        }

        $pr = self::price($gb, $days, $u, $pid);
        $pr['bundle'] = true;
        return $pr;
    }

    /**
     * ساخت کانفیگ‌های یک سرویس آماده
     */
    public static function buildBundle(array $u, array $in): array
    {
        if (!self::enabled())      return ['ok' => false, 'message' => 'بخش نمایندگی فعلاً غیرفعال است.'];
        if (!self::isReseller($u)) return ['ok' => false, 'message' => 'شما دسترسی نمایندگی ندارید.'];

        $b = self::bundle((string)($in['bundle_id'] ?? ''));
        if (!$b)                 return ['ok' => false, 'message' => 'این سرویس دیگر در دسترس نیست.'];
        if (empty($b['active'])) return ['ok' => false, 'message' => 'این سرویس غیرفعال شده است.'];
        if (!$b['items'])        return ['ok' => false, 'message' => 'برای این سرویس هیچ اینباندی تنظیم نشده است.'];
        if ((int)$b['level'] > 0 && self::level($u) < (int)$b['level']) {
            return ['ok' => false, 'message' => 'این سرویس مخصوص نمایندگان سطح بالاتر است.'];
        }

        /* حجم و مدت: از خود سرویس، مگر اینکه سرویس دلخواه باشد */
        if (empty($b['custom'])) {
            $gb   = (int)$b['gb'];
            $days = (int)$b['days'];
        } else {
            $gb   = (int)($in['volume_gb'] ?? 0);
            $days = (int)($in['days'] ?? 0);
            if ($gb < self::minGb() || $gb > self::maxGbFor($u)) {
                return ['ok' => false, 'message' => 'حجم باید بین ' . fa_num(self::minGb()) . ' تا ' . fa_num(self::maxGbFor($u)) . ' گیگابایت باشد.'];
            }
            if ($days < self::minDays() || $days > self::maxDaysFor($u)) {
                return ['ok' => false, 'message' => 'مدت باید بین ' . fa_num(self::minDays()) . ' تا ' . fa_num(self::maxDaysFor($u)) . ' روز باشد.'];
            }
        }

        /* همهٔ پنل‌های این سرویس باید برای این نماینده مجاز باشد */
        $allow = [];
        foreach (self::panelsFor($u) as $pn) $allow[] = (int)$pn['id'];
        foreach ($b['items'] as $it) {
            if (!in_array((int)$it['panel_id'], $allow, true)) {
                return ['ok' => false, 'message' => 'یکی از سرورهای این سرویس برای شما مجاز نیست.'];
            }
        }

        $pr    = self::bundlePrice($b, $u, $gb, $days);
        $final = (int)$pr['final'];

        $can = self::canAfford($u, $final);
        if (!$can['ok']) return ['ok' => false, 'message' => $can['message'], 'price' => $pr];

        $name = self::cleanName((string)($in['name'] ?? ''));
        if ($name !== '' && strlen($name) < 3) {
            return ['ok' => false, 'message' => 'نام کانفیگ باید حداقل ۳ کاراکتر لاتین باشد.'];
        }
        $name = self::applyMark($u, $name);

        /* ۱) کسر موجودی */
        $ch = self::charge($u, $final, 'سرویس نمایندگی: ' . $b['title']);
        if (empty($ch['ok'])) return ['ok' => false, 'message' => (string)($ch['message'] ?? 'کسر موجودی ناموفق بود.')];

        /* ۲) ثبت سفارش */
        $orderId = DB::insert('orders', [
            'user_id' => (int)$u['id'], 'tg_id' => (int)$u['tg_id'],
            'product_id' => 0, 'type' => 'new',
            'amount' => (int)$pr['base'], 'discount_amount' => (int)$pr['discount'],
            'final_amount' => $final, 'status' => 'pending', 'created_at' => now(),
        ]);

        /* ۳) ساخت روی همهٔ پنل‌های سرویس */
        $made = []; $subs = []; $fails = [];

        /*
         * تمپلیت هوشمند ساب مشترک:
         * همهٔ کانفیگ‌های این سرویس – روی هر تعداد پنل که باشند – یک group_key
         * مشترک می‌گیرند و زیر یک لینک ساب واحد تحویل می‌شوند؛ پس ۱۰ گیگ
         * روی دو پنل می‌شود ۱۰ گیگ مشترک، نه ۲۰ گیگ جداگانه.
         */
        $groupKey = rnd(20);
        $groupSub = '';
        if ((string)DB::setting('sub_local', '1') === '1') {
            $groupSub = method_exists('Svc', 'subLinkFor')
                ? Svc::subLinkFor($groupKey)
                : rtrim(app_url(), '/') . '/sub.php?id=' . $groupKey;
        }

        foreach ($b['items'] as $it) {
            $panel = self::panelById((int)$it['panel_id']);
            if (!$panel) { $fails[] = 'سرور #' . (int)$it['panel_id'] . ' در دسترس نیست'; continue; }
            if ($name !== '') $panel['username_mode'] = 'custom';

            try {
                $res = Svc::create($u, $panel, [
                    /* حجم و لینک مشترک بین همهٔ پنل‌های این سرویس */
                    'group_key'      => $groupKey,
                    'group_quota_gb' => (float)$gb,
                    'sub_id'         => $groupKey,
                    'sub_link'       => $groupSub,
                    'volume_gb'    => (float)$gb,
                    'days'         => $days,
                    'ip_limit'     => (int)$b['ip_limit'] > 0 ? (int)$b['ip_limit'] : self::ipLimit(),
                    'username'     => $name !== '' ? $name : null,
                    'inbound_id'   => (string)$it['inbounds'],
                    'device_limit' => (int)$b['device'],
                    'speed_up'     => (int)$b['speed_up'],
                    'speed_down'   => (int)$b['speed_down'],
                ]);
            } catch (Throwable $e) {
                $res = ['ok' => false, 'message' => 'خطای ارتباط با سرور: ' . $e->getMessage()];
            }

            if (empty($res['ok']) || empty($res['service'])) {
                $fails[] = (string)($panel['name'] ?? '-') . ': ' . (string)($res['message'] ?? 'نامشخص');
                continue;
            }

            $svc = (array)$res['service'];
            try {
                DB::update('services', ['is_reseller' => 1], 'id = :id', [':id' => (int)$svc['id']]);
                $svc['is_reseller'] = 1;
            } catch (Throwable $e) {
                /* اگر مایگریشن اجرا نشده باشد مهم نیست */
            }

            $made[] = $svc;
        }

        /* یک لینک اشتراک واحد برای کل سرویس */
        if ($groupSub !== '') {
            $subs = [$groupSub];
        } else {
            foreach ($made as $one) {
                $sl = trim((string)(((array)$one)['sub_link'] ?? ''));
                if ($sl !== '') $subs[] = $sl;
            }
            $subs = array_values(array_unique($subs));
        }

        /* هیچ‌کدام ساخته نشد ← عودت کامل */
        if (!$made) {
            self::refund($u, $final, 'عودت وجه سرویس نمایندگی (ساخت ناموفق)');
            DB::update('orders', ['status' => 'failed'], 'id = :id', [':id' => (int)$orderId]);

            return [
                'ok' => false,
                'message' => '⚠️ ساخت سرویس ناموفق بود و مبلغ به موجودی شما بازگشت.'
                    . ($fails ? "\n" . implode("\n", array_slice($fails, 0, 4)) : ''),
            ];
        }

        DB::update('orders', ['status' => 'paid', 'service_id' => (int)$made[0]['id']], 'id = :id', [':id' => (int)$orderId]);

        $after = (int)DB::val('SELECT balance FROM {p}users WHERE id = :id', [':id' => (int)$u['id']], 0);

        if (class_exists('Logs')) {
            Logs::send('purchases', Logs::fmt('🧩 ساخت سرویس توسط نماینده', [
                'نماینده' => '<code>' . (int)$u['tg_id'] . '</code>',
                'سرویس'   => $b['title'],
                'کانفیگ'   => fa_num((string)count($made)) . ' سرور',
                'حجم'      => $gb   > 0 ? fa_num((string)$gb) . ' گیگابایت' : 'نامحدود',
                'مدت'      => $days > 0 ? fa_num((string)$days) . ' روز' : 'بدون انقضا',
                'مبلغ'     => money($final),
                'موجودی'   => money($after),
            ]));
        }

        $msg = '✅ سرویس «' . $b['title'] . '» ساخته شد.';
        if ($fails) {
            $msg .= "\n⚠️ " . fa_num((string)count($fails)) . ' سرور ناموفق بود: ' . implode(' | ', array_slice($fails, 0, 3));
        }

        return [
            'ok'          => true,
            'bundle'      => ['id' => $b['id'], 'title' => $b['title']],
            'services'    => $made,
            'service'     => $made[0],
            'subs'        => array_values(array_unique($subs)),
            'failed'      => $fails,
            'order_id'    => (int)$orderId,
            'price'       => $pr,
            'balance'     => $after,
            'balance_txt' => money($after),
            'message'     => $msg,
        ];
    }

    /* ==================== ویرایش و حذف کانفیگ نماینده ==================== */

    /** سرویسی که واقعاً مال همین نماینده است (وگرنه null) */
    public static function ownService(array $u, int $svcId): ?array
    {
        $s = DB::one('SELECT * FROM {p}services WHERE id = :i AND user_id = :u',
            [':i' => $svcId, ':u' => (int)$u['id']]);
        if (!$s) return null;
        if ((int)($s['is_reseller'] ?? 0) !== 1) return null;
        if ((string)($s['status'] ?? '') === 'deleted') return null;
        return $s;
    }

    /** همهٔ ردیف‌های همان بستهٔ اشتراکی (یا فقط همین ردیف) */
    public static function groupRows(array $s): array
    {
        $gk = trim((string)($s['group_key'] ?? ''));
        if ($gk === '') return [$s];
        $rows = DB::all("SELECT * FROM {p}services WHERE group_key = :g AND status <> 'deleted' ORDER BY id ASC",
            [':g' => $gk]);
        return $rows ?: [$s];
    }

    /** حجم مؤثر: در بسته‌های چندپنلی سهمیهٔ گروهی ملاک است */
    public static function effectiveGb(array $s): float
    {
        $g = (float)($s['group_quota_gb'] ?? 0);
        if (trim((string)($s['group_key'] ?? '')) !== '' && $g > 0) return $g;
        return (float)($s['volume_gb'] ?? 0);
    }

    /** مصرف مؤثر (در بستهٔ گروهی، مجموع مصرف همهٔ ردیف‌ها) */
    public static function effectiveUsedGb(array $s): float
    {
        $sum = 0;
        foreach (self::groupRows($s) as $r) $sum += (int)($r['used_bytes'] ?? 0);
        return (float)round(bytes2gb($sum), 3);
    }

    /** هزینهٔ افزایش حجم/زمان با تعرفهٔ همین نماینده (بدون هزینهٔ پایه) */
    public static function deltaCost(array $u, float $addGb, int $addDays, int $panelId = 0): int
    {
        $addGb   = max(0.0, $addGb);
        $addDays = max(0, $addDays);
        if ($addGb <= 0 && $addDays <= 0) return 0;

        $base = ($addGb * self::priceGbForPanel($u, $panelId)) + ($addDays * self::priceDayForPanel($u, $panelId));

        $mul = self::panelMul($panelId);
        if ($mul !== 100) $base = $base * $mul / 100;

        $pct  = self::panelDiscount($u, $panelId);
        $fin  = max(0.0, $base - ($base * $pct / 100));

        $step = self::round();
        if ($step > 1) $fin = ceil($fin / $step) * $step;
        return (int)round($fin);
    }

    /** آیا کوچک‌کردن حجم عودت وجه دارد؟ (پیش‌فرض: خیر) */
    public static function shrinkRefund(): bool
    {
        return (string)DB::setting('rs_shrink_refund', '0') === '1';
    }

    /** پیش‌نمایش هزینهٔ ویرایش بدون اعمال تغییر */
    public static function editQuote(array $u, array $s, float $newGb, int $addDays = 0): array
    {
        $pid     = (int)($s['panel_id'] ?? 0);
        $curGb   = self::effectiveGb($s);
        $used    = self::effectiveUsedGb($s);
        $newGb   = (float)round(max(0.0, $newGb), 2);
        $addDays = max(0, min(self::maxDaysForPanel($u, $pid), $addDays));

        $addGb = (float)round($newGb - $curGb, 2);
        $cost  = ($addGb > 0 || $addDays > 0) ? self::deltaCost($u, max(0.0, $addGb), $addDays, $pid) : 0;
        $back  = ($addGb < 0 && self::shrinkRefund()) ? self::deltaCost($u, -$addGb, 0, $pid) : 0;

        return [
            'current_gb' => $curGb,
            'new_gb'     => $newGb,
            'used_gb'    => $used,
            'add_gb'     => $addGb,
            'add_days'   => $addDays,
            'cost'       => $cost,
            'cost_txt'   => money($cost),
            'refund'     => $back,
            'refund_txt' => money($back),
            'grouped'    => trim((string)($s['group_key'] ?? '')) !== '',
            'parts'      => count(self::groupRows($s)),
        ];
    }

    /** ویرایش حجم/مدت یک کانفیگ نماینده و تسویهٔ خودکار کیف پول */
    public static function editService(array $u, int $svcId, float $newGb, int $addDays = 0): array
    {
        $s = self::ownService($u, $svcId);
        if (!$s) return ['ok' => false, 'message' => 'این کانفیگ پیدا نشد یا متعلق به شما نیست.'];

        $fresh = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => (int)$u['id']]) ?: $u;
        $q     = self::editQuote($fresh, $s, $newGb, $addDays);

        $newGb   = (float)$q['new_gb'];
        $addDays = (int)$q['add_days'];

        if (abs((float)$q['add_gb']) < 0.001 && $addDays === 0) {
            return ['ok' => false, 'message' => 'تغییری برای اعمال وجود ندارد.'];
        }
        if ($newGb > 0 && $newGb < (float)$q['used_gb']) {
            return ['ok' => false, 'message' => 'حجم جدید نمی‌تواند کمتر از مصرف فعلی ('
                . fa_num((string)$q['used_gb']) . ' گیگ) باشد.'];
        }
        if ($newGb > 0 && $newGb > (float)self::maxGbForPanel($fresh, (int)($s['panel_id'] ?? 0))) {
            return ['ok' => false, 'message' => 'سقف حجم مجاز شما '
                . fa_num((string)self::maxGbForPanel($fresh, (int)($s['panel_id'] ?? 0))) . ' گیگ است.'];
        }

        $cost = (int)$q['cost'];
        if ($cost > 0) {
            $ch = self::charge($fresh, $cost, 'ویرایش کانفیگ #' . $svcId);
            if (empty($ch['ok'])) return ['ok' => false, 'message' => (string)($ch['message'] ?? 'کسر از کیف پول انجام نشد.')];
        }

        $okAny = false;
        $err   = '';

        foreach (self::groupRows($s) as $r) {
            $panel = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)$r['panel_id']]);
            if (!$panel) { $err = 'پنل این کانفیگ یافت نشد.'; continue; }

            $newExpire = 0;
            if ($addDays > 0) {
                $baseTs = strtotime((string)($r['expire_at'] ?? '')) ?: 0;
                if ($baseTs < time()) $baseTs = time();
                $newExpire = $baseTs + ($addDays * 86400);
            } elseif (!empty($r['expire_at'])) {
                $newExpire = (int)strtotime((string)$r['expire_at']);
            }

            try {
                $xui  = new Xui($panel);
                $ids  = $xui->limitIds(array_merge([(int)$r['inbound_id']], $xui->allowedInboundIds()));
                $done = 0;
                foreach ($ids as $ib) {
                    if ((int)$ib <= 0) continue;
                    $client = $xui->findClient((int)$ib, (string)$r['client_email']);
                    if (!$client) continue;
                    $client['totalGB']    = $newGb > 0 ? gb2bytes($newGb) : 0;
                    $client['expiryTime'] = $newExpire > 0 ? $newExpire * 1000 : 0;
                    $client['enable']     = true;
                    $key = Xui::clientKey($client, (string)($r['client_uuid']));
                    $res = $xui->updateClient((int)$ib, $key, $client);
                    if (($res['success'] ?? false) === true) $done++;
                    else $err = (string)($res['msg'] ?? 'خطای پنل');
                }
                if ($done === 0) { $err = $err !== '' ? $err : 'کاربر در پنل یافت نشد.'; continue; }
            } catch (Throwable $e) {
                $err = $e->getMessage();
                continue;
            }

            $upd = ['volume_gb' => $newGb, 'status' => 'active', 'notified' => null];
            if (trim((string)($r['group_key'] ?? '')) !== '') $upd['group_quota_gb'] = $newGb;
            if ($newExpire > 0) $upd['expire_at'] = date('Y-m-d H:i:s', $newExpire);
            if ($addDays > 0)   $upd['days'] = (int)($r['days'] ?? 0) + $addDays;
            DB::update('services', $upd, 'id = :id', [':id' => (int)$r['id']]);
            $okAny = true;
        }

        if (!$okAny) {
            if ($cost > 0) self::refund($fresh, $cost, 'ویرایش ناموفق کانفیگ #' . $svcId);
            return ['ok' => false, 'message' => 'اعمال تغییر روی پنل انجام نشد: '
                . ($err !== '' ? $err : 'خطای نامشخص')];
        }

        $back = (int)$q['refund'];
        if ($back > 0) self::refund($fresh, $back, 'کاهش حجم کانفیگ #' . $svcId);

        $after = (int)DB::val('SELECT balance FROM {p}users WHERE id = :i', [':i' => (int)$fresh['id']], 0);

        $msg = '✅ کانفیگ به‌روزرسانی شد.';
        if ($cost > 0) $msg .= "\n💸 کسر از کیف پول: " . money($cost) . ' ' . currency();
        if ($back > 0) $msg .= "\n💰 عودت: " . money($back) . ' ' . currency();
        $msg .= "\n👛 موجودی: " . money($after) . ' ' . currency();

        if (class_exists('Logs')) {
            Logs::send('services', Logs::fmt('✏️ ویرایش کانفیگ نمایندگی', [
                'نماینده'    => (string)($fresh['name'] ?? $fresh['tg_id']),
                'کانفیگ'     => '#' . $svcId,
                'حجم جدید'  => $newGb > 0 ? ($newGb . ' GB') : 'نامحدود',
                'افزایش روز' => (string)$addDays,
                'هزینه'      => money($cost),
            ]));
        }

        return ['ok' => true, 'message' => $msg, 'cost' => $cost, 'refund' => $back,
            'balance' => $after, 'balance_txt' => money($after), 'service' => Svc::find($svcId)];
    }

    /** حذف کامل یک کانفیگ نماینده (و کل بستهٔ اشتراکی آن) */
    public static function deleteService(array $u, int $svcId): array
    {
        $s = self::ownService($u, $svcId);
        if (!$s) return ['ok' => false, 'message' => 'این کانفیگ پیدا نشد یا متعلق به شما نیست.'];

        /* محاسبهٔ عودت وجه پیش از حذف (پس از حذف دیگر داده‌ای در دسترس نیست) */
        $rq = self::deleteQuote($u, $s);

        $n = 0;
        foreach (self::groupRows($s) as $r) {
            try { Svc::remove($r, true); $n++; }
            catch (Throwable $e) { app_log('reseller', 'delete: ' . $e->getMessage(), ['svc' => (int)$r['id']]); }
        }
        if ($n === 0) return ['ok' => false, 'message' => 'حذف انجام نشد؛ دوباره تلاش کنید.'];

        /* عودت بهای حجم مصرف‌نشده به کیف پول نماینده */
        $paid = (int)($rq['refund'] ?? 0);
        if ($paid > 0) {
            try {
                self::refund($u, $paid, 'عودت حجم مصرف‌نشده کانفیگ #' . $svcId
                    . ' — ' . $rq['left_gb'] . ' گیگابایت');
            } catch (Throwable $e) {
                $paid = 0;
                app_log('reseller', 'delete refund failed: ' . $e->getMessage(), ['svc' => $svcId]);
            }
        }

        if (class_exists('Logs')) {
            Logs::send('services', Logs::fmt('🗑 حذف کانفیگ نمایندگی', [
                'نماینده'    => (string)($u['name'] ?? $u['tg_id']),
                'کانفیگ'     => '#' . $svcId,
                'تعداد ردیف' => (string)$n,
            ]));
        }

        $msg = '✅ کانفیگ حذف شد.' . ($n > 1 ? ' (' . $n . ' ردیف)' : '');
        if ($paid > 0) {
            $msg .= chr(10) . '💰 مبلغ ' . money($paid) . ' ' . currency() . ' به کیف پول شما برگشت داده شد.';
            $msg .= chr(10) . '📐 مبنا: ' . en_num((string)(int)($rq['frac_pct'] ?? 0)) . '٪ مصرف نشده از '
                . money((int)($rq['pool'] ?? 0)) . ' ' . currency();
            if ((int)($rq['fee'] ?? 0) > 0) {
                $msg .= chr(10) . '➖ کارمزد حذف: ' . money((int)$rq['fee']) . ' ' . currency();
            }
        } elseif (!self::delRefund()) {
            $msg .= chr(10) . 'ℹ️ عودت وجه توسط مدیر غیرفعال شده است.';
        } elseif (trim((string)($rq['note'] ?? '')) !== '') {
            $msg .= chr(10) . 'ℹ️ ' . (string)$rq['note'];
        }
        $msg .= chr(10) . '🗑 این کانفیگ به سطل زباله منتقل شد و پس از '
            . en_num((string)Svc::trashDays()) . ' روز کامل پاک می شود.';

        return ['ok' => true, 'removed' => $n, 'refund' => $paid,
            'refund_txt' => money($paid), 'message' => $msg];
    }

    /* ==================== عودت وجه حذف کانفیگ ==================== */

    /** عودت بهای حجم مصرف‌نشده هنگام حذف کانفیگ (پیش‌فرض روشن) */
    public static function delRefund(): bool
    {
        return (string)DB::setting('rs_del_refund', '1') === '1';
    }

    /** درصد کسر از مبلغ عودتی (کارمزد حذف) */
    public static function delFeePct(): float
    {
        return (float)max(0, min(100, (float)DB::setting('rs_del_fee_pct', '0')));
    }

    /** روش محاسبه عودت: fair (کمترین سهم) | split (میانگین) | gb (فقط حجم) */
    public static function delMode(): string
    {
        $m = (string)DB::setting('rs_del_mode', 'fair');
        return in_array($m, ['fair', 'split', 'gb'], true) ? $m : 'fair';
    }

    /**
     * ارزش قابل عودت یک کانفیگ (مبنای محاسبه)
     *
     * بر پایه تعرفه کامل همین کانفیگ (حجم + مدت) محاسبه می شود
     * تا مبلغ عودتی هرگز از بهای خود کانفیگ بیشتر نشود.
     * هزینه پایه (base fee) هزینه ثابت ساخت است و عودت داده نمی شود.
     */
    public static function servicePool(array $u, array $s): int
    {
        $pid  = (int)($s['panel_id'] ?? 0);
        $gb   = (int)max(1, (int)ceil((float)self::effectiveGb($s)));
        $days = (int)max(1, (int)($s['days'] ?? 0));

        $pr   = self::price($gb, $days, $u, $pid);
        $pool = (int)($pr['final'] ?? 0);

        $fee = (int)self::baseFeeForPanel($pid);
        if ($fee > 0 && $pool > $fee) $pool -= $fee;

        return (int)max(0, $pool);
    }

    /**
     * پیش نمایش مبلغ عودتی در صورت حذف یک کانفیگ
     *
     * مبلغ عودتی = ارزش کانفیگ × سهم مصرف نشده
     * سهم مصرف نشده در حالت پیش فرض (fair) کمترین مقدار بین
     * سهم حجم باقی مانده و سهم زمان باقی مانده است؛ چون یک سرویس
     * فقط وقتی قابل استفاده است که هم حجم داشته باشد و هم زمان.
     * سپس کارمزد حذف کسر می شود و نتیجه به پایین رند می شود.
     */
    public static function deleteQuote(array $u, array $s): array
    {
        $totalGb = (float)self::effectiveGb($s);
        $usedGb  = (float)self::effectiveUsedGb($s);
        $leftGb  = (float)round(max(0.0, $totalGb - $usedGb), 2);

        $expTs    = strtotime((string)($s['expire_at'] ?? '')) ?: 0;
        $expired  = $expTs > 0 && $expTs <= time();
        $leftDays = $expTs > 0 ? (int)max(0, (int)ceil(($expTs - time()) / 86400)) : 0;

        $totDays = (int)max(1, (int)($s['days'] ?? 0));
        if ($leftDays > $totDays) $leftDays = $totDays;

        /* سهم مصرف نشده در دو محور: حجم و زمان */
        $fracGb  = $totalGb > 0.0 ? min(1.0, max(0.0, $leftGb / $totalGb)) : 0.0;
        $fracDay = $expTs > 0 ? min(1.0, max(0.0, $leftDays / $totDays)) : 1.0;

        $mode = self::delMode();
        if ($mode === 'gb') {
            $frac = $fracGb;
        } elseif ($mode === 'split') {
            $frac = ($fracGb + $fracDay) / 2;
        } else {
            $frac = min($fracGb, $fracDay);
        }
        $frac = min(1.0, max(0.0, $frac));

        $pool   = self::servicePool($u, $s);
        $feePct = self::delFeePct();

        $gross  = (int)floor($pool * $frac);
        $feeAmt = 0;
        $amount = 0;
        $why    = '';

        if (!self::delRefund()) {
            $gross = 0;
            $why   = 'عودت وجه توسط مدیر غیرفعال است.';
        } elseif ($expired) {
            $gross = 0;
            $why   = 'این کانفیگ منقضی شده و عودتی ندارد.';
        } elseif ($leftGb < 0.01) {
            $gross = 0;
            $why   = 'حجم مصرف نشده ای باقی نمانده است.';
        } elseif ($gross <= 0) {
            $why = 'مبلغ قابل عودت صفر است.';
        } else {
            $amount = $gross;
            if ($feePct > 0) {
                $feeAmt = (int)ceil($gross * $feePct / 100);
                $amount = (int)max(0, $gross - $feeAmt);
            }
            /* رند به پایین تا هرگز بیش از سهم واقعی عودت ندهیم */
            $step = self::round();
            if ($step > 1) $amount = (int)(floor($amount / $step) * $step);
            $amount = (int)max(0, min($amount, $pool));
        }

        return [
            'total_gb'   => $totalGb,
            'used_gb'    => $usedGb,
            'left_gb'    => $leftGb,
            'left_days'  => $leftDays,
            'total_days' => $totDays,
            'expired'    => $expired,
            'mode'       => $mode,
            'frac_gb'    => (int)round($fracGb * 100),
            'frac_days'  => (int)round($fracDay * 100),
            'frac_pct'   => (int)round($frac * 100),
            'pool'       => $pool,
            'pool_txt'   => money($pool),
            'gross'      => $gross,
            'gross_txt'  => money($gross),
            'fee'        => $feeAmt,
            'fee_txt'    => money($feeAmt),
            'refund'     => $amount,
            'refund_txt' => money($amount),
            'fee_pct'    => $feePct,
            'enabled'    => self::delRefund(),
            'note'       => $why,
        ];
    }
}
