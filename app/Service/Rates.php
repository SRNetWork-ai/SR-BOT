<?php
declare(strict_types=1);

/**
 * محاسبه خودکار نرخ ارز (USDT / TON / TRX → تومان یا ریال)
 *
 * ویژگی‌ها:
 *  - زنجیره چندمنبعی: هر API پاسخ نداد، خودکار می‌رود سراغ بعدی
 *  - امکان تعریف API دلخواه توسط مدیر (آدرس + مسیر JSON)
 *  - حالت «نرخ لحظه‌ای»: هر بار کاربر مبلغ وارد کند، نرخ تازه گرفته می‌شود
 *  - پشتیبانی از TON و TRX علاوه بر USDT
 */
class Rates
{
    /** منابع نرخ تتر → تومان */
    public const SOURCES = [
        'nobitex'       => 'نوبیتکس (اوردربوک)',
        'nobitex_stats' => 'نوبیتکس (آمار بازار)',
        'wallex'        => 'والکس',
        'tetherland'    => 'تترلند',
        'bitpin'        => 'بیت‌پین',
        'custom'        => 'API دلخواه من',
        'manual'        => 'دستی (بدون به‌روزرسانی خودکار)',
    ];

    /** ترتیب پیش‌فرض تلاش وقتی منبع اصلی جواب نداد */
    public const FALLBACK_ORDER = ['nobitex', 'wallex', 'tetherland', 'bitpin', 'nobitex_stats'];

    /** منابع قیمت دلاری ارزهای جهانی */
    public const GLOBAL_SOURCES = ['binance', 'okx', 'coinbase', 'coingecko'];

    /** ارزهای پشتیبانی‌شده برای پرداخت */
    public const ASSETS = [
        'USDT' => ['label' => 'تتر – USDT', 'icon' => '💵', 'cg' => 'tether',          'networks' => 'TRC20 / BEP20 / ERC20'],
        'TON'  => ['label' => 'تون‌کوین – TON', 'icon' => '💎', 'cg' => 'the-open-network', 'networks' => 'TON'],
        'TRX'  => ['label' => 'ترون – TRX', 'icon' => '⚡️', 'cg' => 'tron',           'networks' => 'TRON / TRC20'],
    ];

    public const UNITS = ['toman' => 'تومان', 'rial' => 'ریال'];

    /** بازهٔ منطقی نرخ تتر بر حسب تومان – برای فیلتر کردن پاسخ های خراب */
    private const SANE_MIN = 5000;
    private const SANE_MAX = 2000000;

    /* ---------------- تنظیمات ---------------- */

    public static function mode(): string
    {
        return (string)DB::setting('rate_mode', 'manual') === 'auto' ? 'auto' : 'manual';
    }

    public static function source(): string
    {
        $s = (string)DB::setting('rate_source', 'nobitex');
        return isset(self::SOURCES[$s]) ? $s : 'nobitex';
    }

    public static function unit(): string
    {
        return (string)DB::setting('rate_unit', 'toman') === 'rial' ? 'rial' : 'toman';
    }

    public static function markup(): float { return (float)DB::setting('rate_markup', 0); }
    public static function step(): int     { return max(1, (int)DB::setting('rate_round', 500)); }
    public static function ttl(): int      { return max(1, (int)DB::setting('rate_ttl', 60)); }

    /** failover خودکار بین منابع */
    public static function failover(): bool
    {
        return (string)DB::setting('rate_failover', '1') === '1';
    }

    /** نرخ لحظه‌ای در هر بار ورود مبلغ */
    public static function live(): bool
    {
        return (string)DB::setting('rate_live', '1') === '1';
    }

    /** ارزهایی که مدیر برای پرداخت فعال کرده */
    public static function enabledAssets(): array
    {
        $raw = trim((string)DB::setting('rate_assets', 'USDT,TON,TRX'));
        $out = [];
        foreach (explode(',', $raw) as $a) {
            $a = strtoupper(trim($a));
            if ($a !== '' && isset(self::ASSETS[$a])) $out[$a] = self::ASSETS[$a];
        }
        return $out ?: ['USDT' => self::ASSETS['USDT']];
    }

    /** زنجیرهٔ منابعی که به ترتیب امتحان می‌شوند */
    public static function chain(): array
    {
        $primary = self::source();
        if ($primary === 'manual') return [];

        $chain = [$primary];
        if (self::failover()) {
            // API دلخواه اگر تنظیم شده باشد، همیشه در زنجیره می‌ماند
            if (self::customUrl() !== '' && !in_array('custom', $chain, true)) $chain[] = 'custom';
            foreach (self::FALLBACK_ORDER as $s) {
                if (!in_array($s, $chain, true)) $chain[] = $s;
            }
        }
        return $chain;
    }

    public static function customUrl(): string { return trim((string)DB::setting('rate_custom_url', '')); }

    /* ---------------- خواندن نرخ ---------------- */

    /**
     * نرخ فعلی هر ۱ دلار/تتر بر حسب واحد فروشگاه
     *
     * @param bool $fresh اگر true باشد، کش نادیده گرفته و نرخ لحظه‌ای گرفته می‌شود
     */
    public static function current(bool $fresh = false): int
    {
        if (self::mode() === 'auto' && ($fresh || self::isStale())) {
            try { self::refresh($fresh); } catch (Throwable $e) { }
        }
        return max(1, (int)DB::setting('usd_rate', 100000));
    }

    /**
     * نرخ لحظه‌ای – در لحظهٔ ورود مبلغ توسط کاربر صدا زده می‌شود.
     * اگر حالت لحظه‌ای خاموش باشد، همان رفتار کش‌دار قبلی اعمال می‌شود.
     */
    public static function liveRate(): int
    {
        return self::current(self::mode() === 'auto' && self::live());
    }

    public static function isStale(): bool
    {
        $at = (string)DB::setting('rate_updated_at', '');
        if ($at === '') return true;
        $ts = strtotime($at);
        if (!$ts) return true;
        return (time() - $ts) > self::ttl() * 60;
    }

    /** قیمت یک واحد از ارز مورد نظر بر حسب واحد فروشگاه */
    public static function assetPrice(string $asset, bool $fresh = false): int
    {
        $asset = strtoupper($asset);
        $base  = self::current($fresh);
        if ($asset === 'USDT' || !isset(self::ASSETS[$asset])) return $base;

        $cacheKey = 'rate_usd_' . $asset;
        $usd = (float)DB::setting($cacheKey, 0);
        $at  = (string)DB::setting('rate_usd_at_' . $asset, '');
        $old = $at === '' || (time() - (int)strtotime($at)) > self::ttl() * 60;

        if ($fresh || $old || $usd <= 0) {
            $r = self::fetchUsdPrice($asset);
            if ($r !== null && $r > 0) {
                $usd = $r;
                DB::setSetting($cacheKey, (string)$usd);
                DB::setSetting('rate_usd_at_' . $asset, now());
            }
        }
        if ($usd <= 0) return $base;
        return max(1, (int)round($base * $usd));
    }

    /** معادل مبلغ فروشگاه به مقدار ارز (مثلاً ۲۰۰۰۰۰ تومان → ۳.۱۲ TON) */
    public static function amountToAsset(int $amount, string $asset, bool $fresh = false): float
    {
        $p = self::assetPrice($asset, $fresh);
        return round($amount / max(1, $p), 6);
    }

    /* ---------------- به‌روزرسانی ---------------- */

    /** دریافت و ذخیره نرخ جدید (با failover) */
    public static function refresh(bool $force = false): array
    {
        if (self::mode() !== 'auto') {
            return ['ok' => false, 'message' => 'حالت نرخ روی «دستی» تنظیم شده است.'];
        }
        if (!$force && !self::isStale()) {
            return ['ok' => true, 'cached' => true, 'rate' => (int)DB::setting('usd_rate', 0),
                'message' => 'نرخ به‌روز است؛ نیازی به دریافت مجدد نبود.'];
        }

        $tried = [];
        $raw   = null;
        $used  = '';

        foreach (self::chain() as $src) {
            $t0 = microtime(true);
            $v  = self::fetch($src);
            $ms = (int)((microtime(true) - $t0) * 1000);
            $tried[] = ['source' => $src, 'label' => self::SOURCES[$src] ?? $src, 'value' => $v, 'ms' => $ms];
            if ($v !== null && $v > 0) { $raw = $v; $used = $src; break; }
        }

        if ($raw === null) {
            $names = implode('، ', array_map(fn($t) => (string)$t['label'], $tried));
            DB::setSetting('rate_last_error', 'هیچ‌کدام از منابع پاسخ ندادند (' . $names . ') – ' . to_jalali(now(), true));
            app_log('rates', 'all sources failed', ['tried' => $tried]);
            return ['ok' => false, 'tried' => $tried,
                'message' => 'دریافت نرخ از همهٔ منابع ناموفق بود؛ نرخ قبلی حفظ شد.'];
        }

        $final = $raw * (1 + self::markup() / 100);
        if (self::unit() === 'rial') $final *= 10;
        $step  = self::step();
        $final = (int)(round($final / $step) * $step);
        if ($final < 1) $final = 1;

        DB::setSetting('rate_raw', (string)((int)round($raw)));
        DB::setSetting('usd_rate', (string)$final);
        DB::setSetting('rate_updated_at', now());
        DB::setSetting('rate_src_used', $used);
        DB::setSetting('rate_last_error', '');

        $fellBack = ($used !== self::source());
        return ['ok' => true, 'rate' => $final, 'raw' => (int)round($raw), 'source' => $used, 'tried' => $tried,
            'message' => 'نرخ به‌روز شد: ' . money($final) . ' ' . currency()
                . ' (منبع: ' . (self::SOURCES[$used] ?? $used) . ')'
                . ($fellBack ? ' – منبع اصلی پاسخ نداد و خودکار جایگزین شد.' : '')];
    }

    /* ---------------- منابع نرخ تتر → تومان ---------------- */

    /** دریافت نرخ خام تتر بر حسب تومان از یک منبع */
    public static function fetch(string $source): ?float
    {
        try {
            switch ($source) {
                case 'nobitex':
                    $r = http_json('https://api.nobitex.ir/v2/orderbook/USDTIRT', [], 'GET', [], 12);
                    $p = (float)($r['json']['lastTradePrice'] ?? 0);
                    return self::sane($p > 0 ? $p / 10 : null);

                case 'nobitex_stats':
                    $r = http_json('https://api.nobitex.ir/market/stats', ['srcCurrency' => 'usdt', 'dstCurrency' => 'rls'], 'GET', [], 12);
                    $p = (float)($r['json']['stats']['usdt-rls']['latest'] ?? 0);
                    return self::sane($p > 0 ? $p / 10 : null);

                case 'wallex':
                    $r = http_json('https://api.wallex.ir/v1/markets', [], 'GET', [], 12);
                    $p = (float)($r['json']['result']['symbols']['USDTTMN']['stats']['lastPrice'] ?? 0);
                    return self::sane($p > 0 ? $p : null);

                case 'tetherland':
                    $r = http_json('https://api.tetherland.com/currencies', [], 'GET', [], 12);
                    $p = (float)($r['json']['data']['currencies']['USDT']['price'] ?? 0);
                    return self::sane($p > 0 ? $p : null);

                case 'bitpin':
                    $r = http_json('https://api.bitpin.ir/v1/mkt/markets/', [], 'GET', [], 12);
                    $rows = $r['json']['results'] ?? [];
                    if (is_array($rows)) {
                        foreach ($rows as $row) {
                            if ((string)($row['code'] ?? '') === 'USDT_IRT') {
                                return self::sane((float)($row['price'] ?? 0) ?: null);
                            }
                        }
                    }
                    return null;

                case 'custom':
                    return self::fetchCustom();
            }
        } catch (Throwable $e) {
            app_log('rates', 'source threw', ['source' => $source, 'err' => $e->getMessage()]);
        }
        return null;
    }

    /** API دلخواه مدیر */
    public static function fetchCustom(): ?float
    {
        $url = self::customUrl();
        if ($url === '') return null;

        $path = trim((string)DB::setting('rate_custom_path', ''));
        $hdrs = [];
        foreach (preg_split('/\r?\n/', (string)DB::setting('rate_custom_headers', '')) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line !== '' && strpos($line, ':') !== false) $hdrs[] = $line;
        }

        $r = http_json($url, [], 'GET', $hdrs, 12);
        $val = $path === '' ? ($r['json'] ?? null) : self::dig($r['json'] ?? null, $path);

        // اگر خروجی عدد نبود، عدد را از متن جدا می‌کنیم (مثلاً "102,500 تومان")
        if (is_array($val)) return null;
        $num = (float)preg_replace('/[^\d.]/', '', en_num((string)$val));
        if ($num <= 0) return null;

        $num *= (float)(DB::setting('rate_custom_scale', 1) ?: 1);
        if ((string)DB::setting('rate_custom_unit', 'toman') === 'rial') $num /= 10;

        return self::sane($num);
    }

    /* ---------------- قیمت دلاری ارزهای جهانی (TON / TRX) ---------------- */

    /** قیمت یک واحد ارز بر حسب دلار – با failover بین صرافی‌های جهانی */
    public static function fetchUsdPrice(string $asset): ?float
    {
        $asset = strtoupper($asset);
        if ($asset === 'USDT') return 1.0;
        if (!isset(self::ASSETS[$asset])) return null;

        foreach (self::GLOBAL_SOURCES as $src) {
            try {
                $v = null;
                switch ($src) {
                    case 'binance':
                        $r = http_json('https://api.binance.com/api/v3/ticker/price', ['symbol' => $asset . 'USDT'], 'GET', [], 10);
                        $v = (float)($r['json']['price'] ?? 0);
                        break;
                    case 'okx':
                        $r = http_json('https://www.okx.com/api/v5/market/ticker', ['instId' => $asset . '-USDT'], 'GET', [], 10);
                        $v = (float)($r['json']['data'][0]['last'] ?? 0);
                        break;
                    case 'coinbase':
                        $r = http_json('https://api.coinbase.com/v2/prices/' . $asset . '-USD/spot', [], 'GET', [], 10);
                        $v = (float)($r['json']['data']['amount'] ?? 0);
                        break;
                    case 'coingecko':
                        $cg = (string)(self::ASSETS[$asset]['cg'] ?? '');
                        if ($cg === '') break;
                        $r = http_json('https://api.coingecko.com/api/v3/simple/price', ['ids' => $cg, 'vs_currencies' => 'usd'], 'GET', [], 10);
                        $v = (float)($r['json'][$cg]['usd'] ?? 0);
                        break;
                }
                if ($v !== null && $v > 0) return $v;
            } catch (Throwable $e) {
                app_log('rates', 'global source failed', ['source' => $src, 'asset' => $asset]);
            }
        }
        return null;
    }

    /* ---------------- کمکی ---------------- */

    /** فیلتر منطقی بودن نرخ – جلوگیری از عددهای خراب API */
    private static function sane(?float $v): ?float
    {
        if ($v === null || $v <= 0) return null;
        // اگر عدد به ریال بوده، خودکار به تومان تبدیل می‌شود
        if ($v > self::SANE_MAX) $v = $v / 10;
        if ($v < self::SANE_MIN || $v > self::SANE_MAX) return null;
        return $v;
    }

    /** خواندن مقدار از مسیر نقطه‌ای در JSON – مثلاً data.currencies.USDT.price */
    public static function dig($json, string $path)
    {
        $cur = $json;
        foreach (explode('.', $path) as $seg) {
            $seg = trim($seg);
            if ($seg === '') continue;
            if (is_array($cur) && array_key_exists($seg, $cur)) {
                $cur = $cur[$seg];
            } elseif (is_array($cur) && ctype_digit($seg) && array_key_exists((int)$seg, $cur)) {
                $cur = $cur[(int)$seg];
            } else {
                return null;
            }
        }
        return $cur;
    }

    public static function usdToAmount(float $usd): int
    {
        return (int)round($usd * self::current());
    }

    public static function amountToUsd(int $amount): float
    {
        return round($amount / max(1, self::current()), 2);
    }

    public static function info(): array
    {
        return [
            'mode'       => self::mode(),
            'source'     => self::source(),
            'used'       => (string)DB::setting('rate_src_used', ''),
            'unit'       => self::unit(),
            'rate'       => (int)DB::setting('usd_rate', 0),
            'raw'        => (int)DB::setting('rate_raw', 0),
            'markup'     => self::markup(),
            'step'       => self::step(),
            'ttl'        => self::ttl(),
            'failover'   => self::failover(),
            'live'       => self::live(),
            'assets'     => self::enabledAssets(),
            'custom_url' => self::customUrl(),
            'updated_at' => (string)DB::setting('rate_updated_at', ''),
            'error'      => (string)DB::setting('rate_last_error', ''),
            'stale'      => self::isStale(),
        ];
    }

    /** تست همه منابع برای پنل مدیریت */
    public static function testAll(): array
    {
        $out = [];
        foreach (self::SOURCES as $k => $label) {
            if ($k === 'manual') continue;
            if ($k === 'custom' && self::customUrl() === '') continue;
            $t0 = microtime(true);
            $v  = self::fetch($k);
            $out[$k] = ['label' => $label, 'value' => $v, 'ms' => (int)((microtime(true) - $t0) * 1000)];
        }
        return $out;
    }

    /** تست قیمت ارزهای جهانی */
    public static function testAssets(): array
    {
        $out = [];
        foreach (self::enabledAssets() as $code => $meta) {
            $t0 = microtime(true);
            $usd = self::fetchUsdPrice($code);
            $out[$code] = [
                'label' => $meta['label'],
                'icon'  => $meta['icon'],
                'usd'   => $usd,
                'price' => $usd !== null ? (int)round(self::current() * $usd) : null,
                'ms'    => (int)((microtime(true) - $t0) * 1000),
            ];
        }
        return $out;
    }
}
