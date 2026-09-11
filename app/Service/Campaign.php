<?php
/**
 * کمپین تخفیف زمان‌دار (fixed74)
 *
 *  - یک کمپین فعال در هر زمان؛ درصد تخفیف + سقف تومانی + بازهٔ شروع/پایان
 *  - روی خرید جدید (و در صورت فعال بودن، تمدید) به‌صورت خودکار اعمال می‌شود
 *  - اگر کاربر کد تخفیف هم داشته باشد، «بیشترین تخفیف» اعمال می‌شود (جمع نمی‌شود)
 *  - بنر کمپین در فروشگاه ربات و مینی‌اپ نمایش داده می‌شود
 *
 * تنظیمات (جدول settings):
 *  camp_enabled  (0/1) | camp_title | camp_percent (1..90) | camp_max (سقف، ۰ = بدون سقف)
 *  camp_start / camp_end (DATETIME) | camp_renew (0/1 اعمال روی تمدید) | camp_products ("1,4,9" یا خالی = همه)
 *  camp_min (حداقل قیمت محصول برای اعمال، ۰ = همه)
 */
class Campaign
{
    /** کمپین فعال (در بازهٔ زمانی) یا null */
    public static function active(): ?array
    {
        try {
            if ((string)DB::setting('camp_enabled', '0') !== '1') return null;
            $pct = (float)DB::setting('camp_percent', 0);
            if ($pct <= 0) return null;
            $start = (string)DB::setting('camp_start', '');
            $end   = (string)DB::setting('camp_end', '');
            $now   = time();
            if ($start !== '' && strtotime($start) > $now) return null;
            if ($end !== '' && strtotime($end) <= $now) {
                /* کمپین تمام شده: خودکار خاموش می‌شود تا هر بار محاسبه نشود */
                try { DB::setSetting('camp_enabled', '0'); } catch (Throwable $e) { }
                return null;
            }
            return [
                'title'    => trim((string)DB::setting('camp_title', '')) ?: 'تخفیف ویژه',
                'percent'  => min(90.0, $pct),
                'max'      => max(0, (int)DB::setting('camp_max', 0)),
                'min'      => max(0, (int)DB::setting('camp_min', 0)),
                'start'    => $start,
                'end'      => $end,
                'renew'    => (string)DB::setting('camp_renew', '0') === '1',
                'products' => self::idList((string)DB::setting('camp_products', '')),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /** آیا کمپین روی این محصول/نوع سفارش اعمال می‌شود؟ */
    public static function applies(?array $c, int $productId, string $type = 'new', int $price = 0): bool
    {
        if (!$c) return false;
        if ($type === 'renew' && empty($c['renew'])) return false;
        if ($c['products'] && !in_array($productId, $c['products'], true)) return false;
        if ($c['min'] > 0 && $price > 0 && $price < $c['min']) return false;
        return true;
    }

    /**
     * مبلغ تخفیف کمپین برای یک قیمت
     * @return int تومان (۰ = بدون تخفیف)
     */
    public static function discount(int $price, int $productId, string $type = 'new'): int
    {
        $c = self::active();
        if (!self::applies($c, $productId, $type, $price) || $price <= 0) return 0;
        $d = (int)floor($price * (float)$c['percent'] / 100);
        if ($c['max'] > 0) $d = min($d, $c['max']);
        return max(0, min($price, $d));
    }

    /**
     * محاسبهٔ نهایی: بیشترین تخفیف بین کمپین و کد تخفیف کاربر
     * @return array{final:int, discount:int, source:string} source = 'camp' | 'code' | ''
     */
    public static function best(int $price, int $productId, int $codeDiscount = 0, string $type = 'new'): array
    {
        $camp = self::discount($price, $productId, $type);
        if ($camp > $codeDiscount) {
            return ['final' => max(0, $price - $camp), 'discount' => $camp, 'source' => 'camp'];
        }
        if ($codeDiscount > 0) {
            return ['final' => max(0, $price - $codeDiscount), 'discount' => $codeDiscount, 'source' => 'code'];
        }
        return ['final' => $price, 'discount' => 0, 'source' => ''];
    }

    /** متن باقی‌مانده تا پایان کمپین */
    public static function remaining(?array $c = null): string
    {
        $c = $c ?? self::active();
        if (!$c || $c['end'] === '') return 'بدون محدودیت زمانی';
        $sec = strtotime($c['end']) - time();
        if ($sec <= 0) return 'به پایان رسیده';
        $d = intdiv($sec, 86400); $h = intdiv($sec % 86400, 3600); $m = intdiv($sec % 3600, 60);
        if ($d > 0) return fa_num($d) . ' روز و ' . fa_num($h) . ' ساعت';
        if ($h > 0) return fa_num($h) . ' ساعت و ' . fa_num($m) . ' دقیقه';
        return fa_num(max(1, $m)) . ' دقیقه';
    }

    /** بنر HTML برای فروشگاه ربات (خالی = کمپینی فعال نیست) */
    public static function banner(): string
    {
        $c = self::active();
        if (!$c) return '';
        $pct = rtrim(rtrim(number_format((float)$c['percent'], 1, '.', ''), '0'), '.');
        $t  = '🔥 <b>' . h($c['title']) . '</b> — ' . fa_num($pct) . '٪ تخفیف';
        if ($c['max'] > 0) $t .= ' (تا ' . money($c['max']) . ' ' . currency() . ')';
        $t .= "\n⏳ زمان باقی‌مانده: " . self::remaining($c);
        if ($c['products']) $t .= "\n🎯 روی " . fa_num(count($c['products'])) . ' محصول منتخب';
        if (!empty($c['renew'])) $t .= "\n♻️ شامل تمدید هم می‌شود";
        return $t;
    }

    /** خلاصهٔ کمپین برای مینی‌اپ (JSON) */
    public static function toApi(): ?array
    {
        $c = self::active();
        if (!$c) return null;
        return [
            'title'     => $c['title'],
            'percent'   => (float)$c['percent'],
            'max'       => (int)$c['max'],
            'end'       => $c['end'],
            'remaining' => self::remaining($c),
            'renew'     => (bool)$c['renew'],
            'products'  => $c['products'],
        ];
    }

    /**
     * ساخت/جایگزینی کمپین (از ربات یا پنل وب)
     * @param float  $percent  درصد تخفیف
     * @param int    $hours    مدت به ساعت (۰ = بدون پایان)
     * @param string $title    عنوان
     * @param int    $max      سقف تومانی (۰ = بدون سقف)
     * @param bool   $renew    اعمال روی تمدید
     * @param string $products شناسهٔ محصولات با کاما (خالی = همه)
     */
    public static function start(float $percent, int $hours, string $title = '', int $max = 0, bool $renew = false, string $products = ''): array
    {
        $percent = max(1.0, min(90.0, $percent));
        $hours   = max(0, min(24 * 365, $hours));
        $title   = mb_substr(trim($title) !== '' ? trim($title) : 'تخفیف ویژه', 0, 60);
        $end     = $hours > 0 ? date('Y-m-d H:i:s', time() + $hours * 3600) : '';
        DB::setSetting('camp_title',    $title);
        DB::setSetting('camp_percent',  (string)$percent);
        DB::setSetting('camp_max',      (string)max(0, $max));
        DB::setSetting('camp_start',    now());
        DB::setSetting('camp_end',      $end);
        DB::setSetting('camp_renew',    $renew ? '1' : '0');
        DB::setSetting('camp_products', implode(',', self::idList($products)));
        DB::setSetting('camp_enabled',  '1');
        try {
            Logs::send('admin', Logs::fmt('🔥 کمپین تخفیف فعال شد', [
                'عنوان' => h($title),
                'درصد'  => fa_num((string)$percent) . '٪',
                'سقف'   => $max > 0 ? money($max) . ' ' . currency() : 'بدون سقف',
                'پایان' => $end !== '' ? to_jalali($end, true) : 'نامحدود',
            ]));
        } catch (Throwable $e) { }
        return ['ok' => true, 'title' => $title, 'percent' => $percent, 'end' => $end];
    }

    public static function stop(): void
    {
        DB::setSetting('camp_enabled', '0');
    }

    /** متن خلاصهٔ وضعیت برای پنل مدیریت ربات */
    public static function adminSummary(): string
    {
        $c = self::active();
        if (!$c) {
            $last = trim((string)DB::setting('camp_title', ''));
            return '⚪️ کمپین فعالی وجود ندارد.' . ($last !== '' ? "\nآخرین کمپین: " . h($last) : '');
        }
        $pct = rtrim(rtrim(number_format((float)$c['percent'], 1, '.', ''), '0'), '.');
        $l = [
            '🔥 <b>' . h($c['title']) . '</b>',
            '🏷 تخفیف: <b>' . fa_num($pct) . '٪</b>' . ($c['max'] > 0 ? ' (سقف ' . money($c['max']) . ')' : ''),
            '⏳ باقی‌مانده: ' . self::remaining($c),
            '📅 پایان: ' . ($c['end'] !== '' ? to_jalali($c['end'], true) : 'نامحدود'),
            '🎯 محصولات: ' . ($c['products'] ? fa_num(count($c['products'])) . ' محصول (' . implode(',', $c['products']) . ')' : 'همه'),
            '♻️ تمدید: ' . (!empty($c['renew']) ? 'شامل می‌شود' : 'فقط خرید جدید'),
        ];
        try {
            $n = (int)DB::val("SELECT COUNT(*) FROM {p}orders WHERE status = 'paid' AND discount_code = 'CAMPAIGN' AND created_at >= :s",
                [':s' => $c['start'] !== '' ? $c['start'] : date('Y-m-d 00:00:00')], 0);
            $sum = (int)DB::val("SELECT COALESCE(SUM(discount_amount),0) FROM {p}orders WHERE status = 'paid' AND discount_code = 'CAMPAIGN' AND created_at >= :s",
                [':s' => $c['start'] !== '' ? $c['start'] : date('Y-m-d 00:00:00')], 0);
            $l[] = '🧾 خریدهای این کمپین: ' . fa_num($n) . ' | جمع تخفیف: ' . money($sum) . ' ' . currency();
        } catch (Throwable $e) { }
        return implode("\n", $l);
    }

    private static function idList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[^0-9]+/', en_num($raw)) ?: [] as $v) {
            $v = (int)$v;
            if ($v > 0 && !in_array($v, $out, true)) $out[] = $v;
        }
        return $out;
    }
}
