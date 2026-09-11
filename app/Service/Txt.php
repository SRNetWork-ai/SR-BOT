<?php
declare(strict_types=1);

/**
 * متن‌های ربات
 *
 * دو سطح ویرایش:
 *  ۱) متن‌های کلیددار (فهرست زیر) که مدیر می‌تواند تک‌تک بازنویسی کند.
 *  ۲) قاعده‌های جست‌وجو/جایگزینی که روی «همهٔ» پیام‌های خروجی ربات اعمال می‌شود؛
 *     با این روش هر عبارتی در ربات — حتی متن‌های کلیدنشده — قابل تغییر است.
 */
class Txt
{
    public const KEY   = 'bot_texts';
    public const RULES = 'bot_text_rules';

    /** گروه => کلید => [برچسب، متن پیش‌فرض] */
    public const CATALOG = [
        '🚀 شروع و حساب کاربری' => [
            'start_welcome' => ['پیام خوش‌آمد (دستور /start)', '👋 <b>خوش آمدید!</b>'],
            'start_help'    => ['راهنمای کوتاه زیر منوی اصلی', 'از منوی زیر یکی از گزینه‌ها را انتخاب کنید.'],
            'account_note'  => ['یادداشت پایین صفحهٔ حساب کاربری', ''],
            'banned'        => ['پیام کاربر مسدود', '⛔️ دسترسی شما به ربات بسته شده است.'],
            'verify_needed' => ['پیام لزوم تایید حساب', '🔐 برای استفاده از ربات، حساب خود را تایید کنید.'],
        ],
        '💳 کیف پول و پرداخت' => [
            'wallet_note'      => ['یادداشت پایین کیف پول', ''],
            'wallet_amount'    => ['درخواست مبلغ شارژ', 'مبلغ مورد نظر را فقط با عدد ارسال کنید.'],
            'wallet_card_note' => ['توضیح کارت به کارت', '⚠️ بعد از واریز، عکس رسید را همین‌جا ارسال کنید.'],
            'wallet_crypto_note' => ['توضیح پرداخت ارزی', '✅ پس از واریز، هش تراکنش (TXID) را ارسال کنید.'],
            'wallet_txid_ask'  => ['درخواست هش تراکنش', '🔗 هش تراکنش (TXID) را ارسال کنید.'],
            'wallet_pending'   => ['ثبت شد و در انتظار تایید', '🕓 تراکنش شما ثبت شد و در انتظار تایید است.'],
            'wallet_low'       => ['موجودی کافی نیست', '❌ موجودی کیف پول شما کافی نیست.'],
        ],
        '🛒 خرید و سرویس' => [
            'products_note' => ['یادداشت بالای فهرست محصولات', ''],
            'products_empty' => ['محصولی موجود نیست', '📭 در حال حاضر محصولی برای فروش موجود نیست.'],
            'buy_note'      => ['یادداشت صفحهٔ تایید خرید', ''],
            'service_note'  => ['یادداشت پیام تحویل سرویس', '🙏 از خرید شما سپاسگزاریم.'],
            'services_empty' => ['سرویسی ندارید', '📭 هنوز سرویسی خریداری نکرده‌اید.'],
            'test_note'     => ['یادداشت اکانت تست', ''],
        ],
        '🎁 کد هدیه و آموزش' => [
            'gift_ask'  => ['درخواست کد هدیه', '🎁 کد هدیه را ارسال کنید.'],
            'gift_bad'  => ['کد هدیه نامعتبر', '❌ این کد معتبر نیست یا قبلاً استفاده شده است.'],
            'tutorial_note' => ['یادداشت بخش آموزش', ''],
        ],
        '🆘 پشتیبانی' => [
            'support_note' => ['یادداشت بخش پشتیبانی', ''],
            'ticket_ask'   => ['درخواست متن تیکت', '✍️ پیام خود را بنویسید؛ می‌توانید عکس هم بفرستید.'],
            'ticket_sent'  => ['تیکت ثبت شد', '✅ پیام شما ثبت شد. پشتیبانی به‌زودی پاسخ می‌دهد.'],
        ],
        '🏷 نمایندگی' => [
            'rs_note'      => ['یادداشت پنل نمایندگی', ''],
            'rs_req_ask'   => ['درخواست توضیح برای نمایندگی', '✍️ توضیح کوتاهی دربارهٔ درخواست خود بنویسید.'],
            'rs_req_ok'    => ['درخواست ثبت شد', '✅ درخواست نمایندگی شما ثبت شد و در انتظار بررسی است.'],
            'rs_closed'    => ['ثبت درخواست بسته است', '⛔️ ثبت درخواست نمایندگی فعلاً بسته است.'],
            'rs_fee_note'  => ['توضیح هزینهٔ نمایندگی', ''],
            'rs_welcome'   => ['پیام تبریک فعال‌سازی نمایندگی', '🎉 <b>حساب شما به نمایندگی ارتقا یافت!</b>'],
        ],
        '⚙️ عمومی' => [
            'cancel'  => ['پیام انصراف', '❌ عملیات لغو شد.'],
            'err'     => ['خطای عمومی', '⚠️ خطایی رخ داد. کمی بعد دوباره تلاش کنید.'],
            'wait'    => ['پیام در حال انجام', '⏳ کمی صبر کنید…'],
            'footer'  => ['امضای پایان پیام‌ها (اختیاری)', ''],
        ],
    ];

    /* ==================== متن‌های کلیددار ==================== */

    public static function overrides(): array
    {
        $j = jdec((string)DB::setting(self::KEY, '{}'));
        return is_array($j) ? $j : [];
    }

    public static function meta(string $key): ?array
    {
        foreach (self::CATALOG as $group => $items) {
            if (isset($items[$key])) {
                return ['group' => $group, 'label' => $items[$key][0], 'default' => $items[$key][1]];
            }
        }
        return null;
    }

    /** متن نهایی یک کلید: بازنویسی مدیر، وگرنه پیش‌فرض، وگرنه مقدار جایگزین */
    public static function t(string $key, string $fallback = '', array $vars = []): string
    {
        $ov  = self::overrides();
        $val = isset($ov[$key]) ? (string)$ov[$key] : '';

        if (trim($val) === '') {
            $m   = self::meta($key);
            $val = $m !== null && (string)$m['default'] !== '' ? (string)$m['default'] : $fallback;
        }
        if ($vars !== []) {
            foreach ($vars as $k => $v) {
                $val = str_replace('{' . $k . '}', (string)$v, $val);
            }
        }
        return $val;
    }

    /** آیا مدیر برای این کلید متن سفارشی گذاشته است؟ */
    public static function has(string $key): bool
    {
        $ov = self::overrides();
        return isset($ov[$key]) && trim((string)$ov[$key]) !== '';
    }

    public static function set(string $key, string $val): void
    {
        $ov = self::overrides();
        if (trim($val) === '') {
            unset($ov[$key]);
        } else {
            $ov[$key] = mb_substr($val, 0, 3000);
        }
        DB::setSetting(self::KEY, jenc($ov));
    }

    public static function reset(string $key): void
    {
        self::set($key, '');
    }

    public static function resetAll(): void
    {
        DB::setSetting(self::KEY, '{}');
    }

    public static function customCount(): int
    {
        $n = 0;
        foreach (self::overrides() as $v) {
            if (trim((string)$v) !== '') $n++;
        }
        return $n;
    }

    /* ==================== قاعده‌های جایگزینی ==================== */

    public static function rules(): array
    {
        $j = jdec((string)DB::setting(self::RULES, '[]'));
        if (!is_array($j)) return [];

        $out = [];
        foreach ($j as $r) {
            if (!is_array($r)) continue;
            $find = (string)($r['find'] ?? '');
            if ($find === '') continue;
            $out[] = [
                'id'      => (string)($r['id'] ?? rnd(6)),
                'find'    => $find,
                'to'      => (string)($r['to'] ?? ''),
                're'      => (int)($r['re'] ?? 0) === 1,
                'enabled' => (int)($r['enabled'] ?? 1) === 1,
            ];
        }
        return $out;
    }

    public static function saveRules(array $rows): void
    {
        DB::setSetting(self::RULES, jenc(array_values($rows)));
    }

    public static function addRule(string $find, string $to, bool $re = false): array
    {
        $find = trim($find);
        if ($find === '') return ['ok' => false, 'message' => 'عبارت جست‌وجو خالی است.'];

        if ($re) {
            $test = @preg_match('/' . str_replace('/', '\\/', $find) . '/u', 'x');
            if ($test === false) return ['ok' => false, 'message' => 'الگوی regex معتبر نیست.'];
        }

        $rows   = self::rules();
        if (count($rows) >= 80) return ['ok' => false, 'message' => 'حداکثر ۸۰ قاعده مجاز است.'];
        $rows[] = [
            'id' => rnd(6), 'find' => mb_substr($find, 0, 400),
            'to' => mb_substr($to, 0, 400), 're' => $re ? 1 : 0, 'enabled' => 1,
        ];
        self::saveRules($rows);
        return ['ok' => true, 'message' => 'قاعده افزوده شد.'];
    }

    public static function delRule(string $id): bool
    {
        $rows = self::rules();
        $out  = [];
        $hit  = false;
        foreach ($rows as $r) {
            if ($r['id'] === $id) { $hit = true; continue; }
            $out[] = $r;
        }
        if ($hit) self::saveRules($out);
        return $hit;
    }

    public static function toggleRule(string $id): bool
    {
        $rows = self::rules();
        $hit  = false;
        foreach ($rows as $i => $r) {
            if ($r['id'] === $id) {
                $rows[$i]['enabled'] = $r['enabled'] ? 0 : 1;
                $hit = true;
                break;
            }
        }
        if ($hit) self::saveRules($rows);
        return $hit;
    }

    /**
     * اعمال قاعده‌ها روی متن خروجی ربات.
     * روی همهٔ پیام‌های ارسالی و ویرایشی صدا زده می‌شود.
     */
    public static function apply(string $text): string
    {
        if ($text === '') return $text;

        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                foreach (self::rules() as $r) {
                    if ($r['enabled']) $cache[] = $r;
                }
            } catch (Throwable $e) {
                $cache = [];
            }
        }
        if ($cache === []) return $text;

        foreach ($cache as $r) {
            if ($r['re']) {
                $out = @preg_replace('/' . str_replace('/', '\\/', $r['find']) . '/u', $r['to'], $text);
                if (is_string($out)) $text = $out;
            } else {
                $text = str_replace($r['find'], $r['to'], $text);
            }
        }

        $foot = self::t('footer');
        if (trim($foot) !== '' && mb_strpos($text, $foot) === false) {
            $text .= "\n\n" . $foot;
        }
        return $text;
    }

    /** خروجی JSON از متن‌های سفارشی و قاعده‌های جایگزینی */
    public static function exportJson(): string
    {
        $data = [
            'v'        => 1,
            'texts'    => self::overrides(),
            'rules'    => self::rules(),
            'saved_at' => date('Y-m-d H:i:s'),
        ];
        return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** بازگردانی از JSON — فقط کلیدهای موجود در کاتالوگ پذیرفته می‌شوند */
    public static function importJson(string $json, bool $withRules = true): array
    {
        $j = jdec(trim($json));
        if (!is_array($j)) {
            return ['ok' => false, 'count' => 0, 'rules' => 0, 'skipped' => 0, 'message' => 'فایل JSON معتبر نیست.'];
        }

        $texts = isset($j['texts']) && is_array($j['texts']) ? $j['texts'] : $j;

        $ov = [];
        $n  = 0;
        $sk = 0;
        foreach ((array)$texts as $k => $v) {
            if (is_array($v)) continue;
            $k = (string)$k;
            if (self::meta($k) === null) { $sk++; continue; }
            $v = trim(str_replace("\r\n", "\n", (string)$v));
            if ($v === '') continue;
            $ov[$k] = mb_substr($v, 0, 3000);
            $n++;
        }
        DB::setSetting(self::KEY, jenc($ov));

        $rn = 0;
        if ($withRules && isset($j['rules']) && is_array($j['rules'])) {
            $rows = [];
            foreach ($j['rules'] as $r) {
                if (!is_array($r)) continue;
                $find = trim((string)($r['find'] ?? ''));
                if ($find === '') continue;
                $rows[] = [
                    'id'      => (string)($r['id'] ?? rnd(6)),
                    'find'    => mb_substr($find, 0, 400),
                    'to'      => mb_substr((string)($r['to'] ?? ''), 0, 400),
                    're'      => !empty($r['re']) ? 1 : 0,
                    'enabled' => (isset($r['enabled']) && !$r['enabled']) ? 0 : 1,
                ];
                if (count($rows) >= 80) break;
            }
            self::saveRules($rows);
            $rn = count($rows);
        }

        return [
            'ok' => true, 'count' => $n, 'rules' => $rn, 'skipped' => $sk,
            'message' => '✅ ' . fa_num($n) . ' متن و ' . fa_num($rn) . ' قاعده بازگردانده شد.'
                . ($sk > 0 ? ' (' . fa_num($sk) . ' کلید ناشناس رد شد)' : ''),
        ];
    }

    /** آمار هر گروه: total و custom */
    public static function groupStats(): array
    {
        $ov  = self::overrides();
        $out = [];
        foreach (self::CATALOG as $g => $items) {
            $c = 0;
            foreach ($items as $k => $meta) {
                if (isset($ov[$k]) && trim((string)$ov[$k]) !== '') $c++;
            }
            $out[(string)$g] = ['total' => count($items), 'custom' => $c];
        }
        return $out;
    }

    /** تعداد کل کلیدهای کاتالوگ */
    public static function totalKeys(): int
    {
        $n = 0;
        foreach (self::CATALOG as $items) $n += count($items);
        return $n;
    }

    /** متغیرهای قابل استفاده در یک کلید (از دل پیش‌فرض و مقدار فعلی) */
    public static function varsOf(string $key): array
    {
        $m  = self::meta($key);
        $ov = self::overrides();
        $s  = (string)($m['default'] ?? '') . ' ' . (string)($ov[$key] ?? '');
        $out = [];
        if (preg_match_all('/\{([a-zA-Z0-9_]{1,24})\}/', $s, $mm)) {
            foreach ($mm[1] as $v) { $out[$v] = true; }
        }
        return array_keys($out);
    }
}
