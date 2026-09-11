<?php
declare(strict_types=1);

/**
 * درگاه‌های پرداخت دستی (کارت به کارت و ارزی)
 *
 * برای هر ارز می‌توان چند درگاه با شبکه‌های مختلف ساخت؛
 * مثلاً تتر روی ترون، تتر روی بی‌ان‌بی چین و تتر روی اتریوم.
 * هر درگاه می‌تواند برای همه، فقط کاربران عادی یا فقط نمایندگان باشد.
 */
class Gateway
{
    public const KEY = 'pay_gateways';

    /** انواع درگاه */
    public const KINDS = [
        'crypto' => '🌐 ارز دیجیتال (دستی)',
        'card'   => '💳 کارت بانکی',
        'nowpay' => '⚡️ نوپیمنتس (خودکار)',
        'hooshpay' => '🪙 هوش‌پی (کارت به کارت آنی)',
    ];

    public const AUDIENCE = [
        'all'      => '👥 همه',
        'user'     => '🙋 فقط کاربران عادی',
        'reseller' => '🏷 فقط نمایندگان',
    ];

    /** شبکه‌های پیشنهادی هر ارز */
    public const NETWORKS = [
        'USDT' => [
            'TRC20'    => 'ترون • TRC20',
            'BEP20'    => 'بی‌ان‌بی چین • BEP20',
            'ERC20'    => 'اتریوم • ERC20',
            'TON'      => 'تون • Jetton',
            'POLYGON'  => 'پالیگان • Polygon',
            'ARBITRUM' => 'آربیتروم • Arbitrum',
            'OPTIMISM' => 'اپتیمیزم • Optimism',
            'BASE'     => 'بیس • Base',
            'SOL'      => 'سولانا • SPL',
            'AVAX'     => 'آوالانچ • C-Chain',
            'APTOS'    => 'اپتاس • Aptos',
            'NEAR'     => 'نیر • NEAR',
        ],
        'USDC' => [
            'ERC20'    => 'اتریوم • ERC20',
            'TRC20'    => 'ترون • TRC20',
            'BEP20'    => 'بی‌ان‌بی چین • BEP20',
            'POLYGON'  => 'پالیگان • Polygon',
            'ARBITRUM' => 'آربیتروم • Arbitrum',
            'BASE'     => 'بیس • Base',
            'SOL'      => 'سولانا • SPL',
            'AVAX'     => 'آوالانچ • C-Chain',
        ],
        'DAI'  => ['ERC20' => 'اتریوم • ERC20', 'POLYGON' => 'پالیگان • Polygon', 'ARBITRUM' => 'آربیتروم • Arbitrum', 'BEP20' => 'بی‌ان‌بی چین • BEP20'],
        'TON'  => ['TON' => 'تون • TON'],
        'NOT'  => ['TON' => 'تون • Jetton'],
        'TRX'  => ['TRON' => 'ترون • TRX'],
        'BTC'  => ['BTC' => 'بیت‌کوین • BTC', 'LN' => 'لایتنینگ • LN', 'BEP20' => 'بی‌ان‌بی چین • BTCB'],
        'ETH'  => ['ERC20' => 'اتریوم • ERC20', 'ARBITRUM' => 'آربیتروم • Arbitrum', 'BASE' => 'بیس • Base', 'OPTIMISM' => 'اپتیمیزم • Optimism', 'ZKSYNC' => 'زدکی‌سینک • zkSync', 'BEP20' => 'بی‌ان‌بی چین • BEP20'],
        'BNB'  => ['BEP20' => 'بی‌ان‌بی چین • BEP20', 'OPBNB' => 'اپ‌بی‌ان‌بی • opBNB'],
        'SOL'  => ['SOL' => 'سولانا • SPL'],
        'LTC'  => ['LTC' => 'لایت‌کوین • LTC'],
        'DOGE' => ['DOGE' => 'دوج‌کوین • DOGE', 'BEP20' => 'بی‌ان‌بی چین • BEP20'],
        'XRP'  => ['XRP' => 'ریپل • XRP Ledger'],
        'ADA'  => ['ADA' => 'کاردانو • Cardano'],
        'AVAX' => ['AVAX' => 'آوالانچ • C-Chain'],
        'POL'  => ['POLYGON' => 'پالیگان • Polygon'],
        'SHIB' => ['ERC20' => 'اتریوم • ERC20', 'BEP20' => 'بی‌ان‌بی چین • BEP20'],
        'XMR'  => ['XMR' => 'مونرو • Monero'],
    ];

    public const ICONS = [
        'USDT' => '💵', 'USDC' => '🔵', 'DAI'  => '🟨', 'TON'  => '💎',
        'NOT'  => '🎯', 'TRX'  => '⚡️', 'BTC'  => '🟠', 'ETH'  => '🔷',
        'BNB'  => '🟡', 'SOL'  => '🟣', 'LTC'  => '⚪️', 'DOGE' => '🐕',
        'XRP'  => '💠', 'ADA'  => '🔹', 'AVAX' => '🔺', 'POL'  => '🟪',
        'SHIB' => '🐶', 'XMR'  => '🔶',
    ];

    /** ارزهایی که قیمتشان با نرخ تتر حساب می‌شود */
    public const STABLES = ['USDC', 'DAI', 'FDUSD', 'TUSD', 'BUSD'];

    /** نام فارسی ارزهایی که در ماژول نرخ تعریف نشده‌اند */
    public const LABELS = [
        'USDC' => 'یواس‌دی‌سی – USDC',
        'DAI'  => 'دای – DAI',
        'BTC'  => 'بیت‌کوین – BTC',
        'ETH'  => 'اتریوم – ETH',
        'BNB'  => 'بی‌ان‌بی – BNB',
        'SOL'  => 'سولانا – SOL',
        'LTC'  => 'لایت‌کوین – LTC',
        'DOGE' => 'دوج‌کوین – DOGE',
        'XRP'  => 'ریپل – XRP',
        'ADA'  => 'کاردانو – ADA',
        'AVAX' => 'آوالانچ – AVAX',
        'POL'  => 'پالیگان – POL',
        'SHIB' => 'شیبا – SHIB',
        'XMR'  => 'مونرو – XMR',
        'NOT'  => 'نات‌کوین – NOT',
    ];

    /** رنگ اختصاصی هر ارز برای کارت‌های پنل مدیریت */
    public const COLORS = [
        'USDT' => '#26a17b', 'USDC' => '#2775ca', 'DAI'  => '#f5ac37', 'TON'  => '#0098ea',
        'NOT'  => '#000000', 'TRX'  => '#e50915', 'BTC'  => '#f7931a', 'ETH'  => '#627eea',
        'BNB'  => '#f0b90b', 'SOL'  => '#9945ff', 'LTC'  => '#a6a9aa', 'DOGE' => '#c2a633',
        'XRP'  => '#23292f', 'ADA'  => '#0033ad', 'AVAX' => '#e84142', 'POL'  => '#8247e5',
        'SHIB' => '#ffa409', 'XMR'  => '#ff6600',
    ];

    /* ==================== ذخیره‌سازی ==================== */

    public static function raw(): array
    {
        $j = jdec((string)DB::setting(self::KEY, '[]'));
        return is_array($j) ? $j : [];
    }

    public static function save(array $rows): void
    {
        $out = [];
        $i = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $r['sort'] = $i++;
            $out[] = $r;
        }
        DB::setSetting(self::KEY, jenc(array_values($out)));
    }

    /** یک درگاه را به شکل استاندارد برمی‌گرداند */
    public static function normalize(array $r): array
    {
        $kind  = (string)($r['kind'] ?? 'crypto');
        if (!isset(self::KINDS[$kind])) $kind = 'crypto';
        $asset = strtoupper(trim((string)($r['asset'] ?? 'USDT')));
        $aud   = (string)($r['audience'] ?? 'all');
        $icon  = trim((string)($r['icon'] ?? ''));
        if ($icon === '') {
            if ($kind === 'card') {
                $icon = '💳';
            } elseif ($kind === 'nowpay') {
                $icon = '⚡️';
            } elseif ($kind === 'hooshpay') {
                $icon = '🪙';
            } else {
                $icon = self::ICONS[$asset] ?? '🌐';
            }
        }

        return [
            'id'       => (string)($r['id'] ?? rnd(8)),
            'kind'     => $kind,
            'enabled'  => (int)($r['enabled'] ?? 1) === 1,
            'audience' => isset(self::AUDIENCE[$aud]) ? $aud : 'all',
            'label'    => trim((string)($r['label'] ?? '')),
            'icon'     => $icon,
            'asset'    => $asset,
            'network'  => strtoupper(trim((string)($r['network'] ?? ''))),
            'address'  => trim((string)($r['address'] ?? '')),
            'memo'     => trim((string)($r['memo'] ?? '')),
            'number'   => trim((string)($r['number'] ?? '')),
            'holder'   => trim((string)($r['holder'] ?? '')),
            'bank'     => trim((string)($r['bank'] ?? '')),
            'note'     => trim((string)($r['note'] ?? '')),
            'sheba'    => (string)preg_replace('/[^0-9A-Z]/', '', strtoupper(trim((string)($r['sheba'] ?? '')))),
            'min'      => max(0, (int)($r['min'] ?? 0)),
            'max'      => max(0, (int)($r['max'] ?? 0)),
            'fee'      => round((float)($r['fee'] ?? 0), 2),
            'sort'     => (int)($r['sort'] ?? 0),
        ];
    }

    /** همهٔ درگاه‌ها (با انتقال خودکار تنظیمات قدیمی در اولین اجرا) */
    public static function all(): array
    {
        $rows = self::raw();
        if ($rows === [] && (string)DB::setting('pay_gw_migrated', '0') !== '1') {
            $rows = self::importLegacy();
        }
        usort($rows, function (array $a, array $b) {
            return ((int)($a['sort'] ?? 0)) <=> ((int)($b['sort'] ?? 0));
        });
        $out = [];
        foreach ($rows as $r) {
            if (is_array($r)) $out[] = self::normalize($r);
        }
        return $out;
    }

    /** انتقال تنظیمات تک‌آدرسی قدیمی به ساختار چند‌درگاهی */
    public static function importLegacy(): array
    {
        $rows = [];

        $card = trim((string)DB::setting('card_number', ''));
        if ($card !== '') {
            $rows[] = [
                'id' => rnd(8), 'kind' => 'card', 'enabled' => 1, 'audience' => 'all',
                'label' => 'کارت اصلی', 'icon' => '💳', 'number' => $card,
                'holder' => (string)DB::setting('card_holder', ''),
                'bank'   => (string)DB::setting('card_bank', ''),
            ];
        }

        $assets = class_exists('Rates') ? array_keys(Rates::enabledAssets()) : ['USDT', 'TON', 'TRX'];
        foreach ($assets as $ak) {
            $ak   = strtoupper((string)$ak);
            $addr = trim((string)DB::setting('crypto_addr_' . $ak, ''));
            if ($addr === '' && $ak === 'USDT') $addr = trim((string)DB::setting('crypto_address', ''));
            if ($addr === '') continue;

            $net = strtoupper(trim((string)DB::setting('crypto_net_' . $ak, '')));
            if ($net === '') {
                $nets = self::NETWORKS[$ak] ?? [];
                $net  = $nets !== [] ? (string)array_key_first($nets) : '';
            }
            $rows[] = [
                'id' => rnd(8), 'kind' => 'crypto', 'enabled' => 1, 'audience' => 'all',
                'label' => '', 'icon' => self::ICONS[$ak] ?? '🌐',
                'asset' => $ak, 'network' => $net, 'address' => $addr,
            ];
        }

        if ($rows !== []) self::save($rows);
        DB::setSetting('pay_gw_migrated', '1');
        return $rows;
    }

    /* ==================== خواندن ==================== */

    public static function byId(string $id): ?array
    {
        foreach (self::all() as $g) {
            if ($g['id'] === $id) return $g;
        }
        return null;
    }

    /** درگاه‌های فعالِ متناسب با نوع کاربر */
    public static function forUser(string $kind, bool $isReseller = false): array
    {
        $out = [];
        foreach (self::all() as $g) {
            if ($g['kind'] !== $kind || !$g['enabled']) continue;
            if ($g['audience'] === 'reseller' && !$isReseller) continue;
            if ($g['audience'] === 'user' && $isReseller) continue;
            if ($kind === 'crypto' && $g['address'] === '') continue;
            if ($kind === 'card' && $g['number'] === '') continue;
            if ($kind === 'nowpay' && (!class_exists('NowPay') || !NowPay::enabled())) continue;
            $out[] = $g;
        }
        return $out;
    }

    public static function crypto(bool $isReseller = false): array
    {
        return self::forUser('crypto', $isReseller);
    }

    public static function cards(bool $isReseller = false): array
    {
        $rows = self::forUser('card', $isReseller);
        if ($rows === [] && $isReseller) $rows = self::forUser('card', false);
        return $rows;
    }

    /** اولین کارت مناسب این کاربر */
    public static function card(bool $isReseller = false): ?array
    {
        $rows = self::cards($isReseller);
        return $rows === [] ? null : $rows[0];
    }

    /** آیا پرداخت کارت به کارت برای این کاربر واقعا فعال است؟ (تنظیم سراسری + وضعیت درگاه‌ها) */
    public static function cardOn(bool $isReseller = false): bool
    {
        if ((string)DB::setting('card_enabled', '1') !== '1') return false;

        $has = false;
        foreach (self::all() as $g) {
            if ($g['kind'] === 'card') { $has = true; break; }
        }
        /* اگر هیچ کارتی در «درگاه‌های پرداخت» ثبت نشده، رفتار قدیمی (کارت تکی تنظیمات) حاکم است */
        if (!$has) return trim((string)DB::setting('card_number', '')) !== '';

        return self::cards($isReseller) !== [];
    }

    /** آیا پرداخت ارزی دستی برای این کاربر واقعا فعال است؟ (تنظیم سراسری + وضعیت درگاه‌ها) */
    public static function cryptoOn(bool $isReseller = false): bool
    {
        if ((string)DB::setting('crypto_enabled', '1') !== '1') return false;

        $has = false;
        foreach (self::all() as $g) {
            if ($g['kind'] === 'crypto') { $has = true; break; }
        }
        /* اگر هیچ آدرسی در «درگاه‌های پرداخت» ثبت نشده، رفتار قدیمی حفظ می‌شود */
        if (!$has) return true;

        return self::crypto($isReseller) !== [];
    }

    /** درگاه‌های خودکار نوپیمنتس */
    public static function nowpay(bool $isReseller = false): array
    {
        return self::forUser('nowpay', $isReseller);
    }

    /** آیا پرداخت خودکار برای این کاربر فعال است؟ */
    public static function nowpayOn(bool $isReseller = false): bool
    {
        if (!class_exists('NowPay') || !NowPay::enabled()) return false;

        $has = false;
        foreach (self::all() as $g) {
            if ($g['kind'] === 'nowpay') { $has = true; break; }
        }
        /* اگر هیچ کارت نوپیمنتسی ثبت نشده، رفتار قدیمی حفظ می‌شود */
        if (!$has) return true;

        return self::nowpay($isReseller) !== [];
    }

    /** برچسب درگاه خودکار برای منوی ربات */
    public static function nowpayLabel(bool $isReseller = false): string
    {
        $rows = self::nowpay($isReseller);
        if ($rows === []) return '⚡️ پرداخت ارزی خودکار';
        $g = $rows[0];
        return trim(((string)$g['icon'] !== '' ? (string)$g['icon'] : '⚡️') . ' '
            . ((string)$g['label'] !== '' ? (string)$g['label'] : 'پرداخت ارزی خودکار'));
    }

    /** درگاه هوش‌پی (کارت به کارت آنی) */
    public static function hooshpay(bool $isReseller = false): array
    {
        return self::forUser('hooshpay', $isReseller);
    }

    /** آیا درگاه هوش‌پی برای این کاربر فعال است؟ */
    public static function hooshpayOn(bool $isReseller = false): bool
    {
        if (!class_exists('HooshPay') || !HooshPay::enabled()) return false;
        if (!HooshPay::forUser($isReseller)) return false;

        $has = false;
        foreach (self::all() as $g) {
            if ($g['kind'] === 'hooshpay') { $has = true; break; }
        }
        /* اگر هیچ ردیف هوش‌پی ثبت نشده باشد، فقط تنطیمات کلی حاکم است */
        if (!$has) return true;

        return self::hooshpay($isReseller) !== [];
    }

    /** برچسب دکمهٔ هوش‌پی در منوی ربات */
    public static function hooshpayLabel(bool $isReseller = false): string
    {
        $rows = self::hooshpay($isReseller);
        if ($rows === []) {
            return class_exists('HooshPay') ? HooshPay::btnLabel() : '🪙 پرداخت آنی کارت به کارت';
        }
        $g  = $rows[0];
        $ic = (string)$g['icon'] !== '' ? (string)$g['icon'] : '🪙';
        $lb = (string)$g['label'] !== '' ? (string)$g['label'] : 'پرداخت آنی کارت به کارت';
        return trim($ic . ' ' . $lb);
    }

    /** درگاه‌های ارزی گروه‌بندی‌شده بر اساس ارز */
    public static function grouped(bool $isReseller = false): array
    {
        $out = [];
        foreach (self::crypto($isReseller) as $g) {
            $a = $g['asset'];
            if (!isset($out[$a])) {
                $out[$a] = [
                    'asset' => $a,
                    'icon'  => $g['icon'],
                    'label' => self::assetLabel($a),
                    'items' => [],
                ];
            }
            $out[$a]['items'][] = $g;
        }
        return $out;
    }

    /* ==================== ویرایش ==================== */

    /** افزودن یا ویرایش یک درگاه */
    public static function upsert(array $in): array
    {
        $kind = (string)($in['kind'] ?? 'crypto');
        if (!isset(self::KINDS[$kind])) $kind = 'crypto';

        if ($kind === 'crypto' && trim((string)($in['address'] ?? '')) === '') {
            return ['ok' => false, 'message' => 'آدرس کیف پول را وارد کنید.'];
        }
        if ($kind === 'card' && trim((string)($in['number'] ?? '')) === '') {
            return ['ok' => false, 'message' => 'شماره کارت را وارد کنید.'];
        }

        $rows = self::all();
        $id   = trim((string)($in['id'] ?? ''));
        $new  = self::normalize($in);

        if ($id !== '') {
            $found = false;
            foreach ($rows as $i => $g) {
                if ($g['id'] === $id) {
                    $new['id']   = $id;
                    $new['sort'] = $g['sort'];
                    $rows[$i]    = $new;
                    $found       = true;
                    break;
                }
            }
            if (!$found) $rows[] = $new;
        } else {
            $new['id'] = rnd(8);
            $rows[]    = $new;
        }

        self::save($rows);
        return ['ok' => true, 'id' => $new['id'], 'message' => 'درگاه ذخیره شد.'];
    }

    public static function remove(string $id): bool
    {
        $rows = self::all();
        $out  = [];
        $hit  = false;
        foreach ($rows as $g) {
            if ($g['id'] === $id) { $hit = true; continue; }
            $out[] = $g;
        }
        if ($hit) self::save($out);
        return $hit;
    }

    public static function toggle(string $id): bool
    {
        $rows = self::all();
        $hit  = false;
        foreach ($rows as $i => $g) {
            if ($g['id'] === $id) {
                $rows[$i]['enabled'] = $g['enabled'] ? 0 : 1;
                $hit = true;
                break;
            }
        }
        if ($hit) self::save($rows);
        return $hit;
    }

    /** جابه‌جایی ترتیب نمایش ($dir = -1 بالا، +1 پایین) */
    public static function move(string $id, int $dir): bool
    {
        $rows = self::all();
        $idx  = -1;
        foreach ($rows as $i => $g) {
            if ($g['id'] === $id) { $idx = $i; break; }
        }
        if ($idx < 0) return false;
        $to = $idx + ($dir < 0 ? -1 : 1);
        if ($to < 0 || $to >= count($rows)) return false;

        $tmp        = $rows[$to];
        $rows[$to]  = $rows[$idx];
        $rows[$idx] = $tmp;
        self::save($rows);
        return true;
    }

    /* ==================== کمکی ==================== */

    public static function assetLabel(string $asset): string
    {
        if (class_exists('Rates') && isset(Rates::ASSETS[$asset])) {
            return (string)Rates::ASSETS[$asset]['label'];
        }
        return (string)(self::LABELS[$asset] ?? $asset);
    }

    /** برچسب فارسی نوع درگاه (بدون ایموجی) */
    public static function kindLabel(string $kind): string
    {
        $map = ['crypto' => 'ارز دیجیتال', 'card' => 'کارت بانکی', 'nowpay' => 'پرداخت خودکار'];
        return (string)($map[$kind] ?? $kind);
    }

    /** رنگ اختصاصی درگاه برای نمایش در پنل */
    public static function color(array $g): string
    {
        $kind = (string)($g['kind'] ?? 'crypto');
        if ($kind === 'card')   return '#3b82f6';
        if ($kind === 'nowpay') return '#8b5cf6';
        return (string)(self::COLORS[(string)($g['asset'] ?? '')] ?? '#22c55e');
    }

    /** نمایش کوتاه‌شدهٔ آدرس یا شماره کارت */
    public static function shortAddr(string $s, int $head = 10, int $tail = 8): string
    {
        $s = trim($s);
        $len = function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
        if ($len <= $head + $tail + 3) return $s;
        $a = function_exists('mb_substr') ? mb_substr($s, 0, $head, 'UTF-8') : substr($s, 0, $head);
        $b = function_exists('mb_substr') ? mb_substr($s, -$tail, null, 'UTF-8') : substr($s, -$tail);
        return $a . '…' . $b;
    }

    public static function netLabel(string $asset, string $net): string
    {
        $net = strtoupper(trim($net));
        if ($net === '') return '-';
        return (string)(self::NETWORKS[$asset][$net] ?? $net);
    }

    /** عنوان نمایشی درگاه */
    public static function title(array $g): string
    {
        if (($g['label'] ?? '') !== '') return (string)$g['label'];
        if (($g['kind'] ?? '') === 'nowpay') {
            return trim(((string)($g['icon'] ?? '⚡️')) . ' '
                . (trim((string)($g['label'] ?? '')) !== '' ? (string)$g['label'] : 'پرداخت ارزی خودکار'));
        }
        if (($g['kind'] ?? '') === 'card') {
            $b = trim((string)($g['bank'] ?? ''));
            return $b !== '' ? 'کارت ' . $b : 'کارت به کارت';
        }
        return $g['asset'] . ' • ' . self::netLabel((string)$g['asset'], (string)$g['network']);
    }

    /** ارز پایه برای قیمت‌گذاری؛ استیبل‌کوین‌ها با نرخ تتر حساب می‌شوند */
    public static function priceBase(string $asset): string
    {
        if (class_exists('Rates') && isset(Rates::ASSETS[$asset])) return $asset;
        return in_array($asset, self::STABLES, true) ? 'USDT' : '';
    }

    /** آیا برای این درگاه نرخ لحظه‌ای در دسترس است؟ */
    public static function hasRate(array $g): bool
    {
        if (($g['kind'] ?? '') !== 'crypto') return true;
        return self::priceBase((string)($g['asset'] ?? '')) !== '';
    }

    /** مقدار ارز معادل یک مبلغ ریالی/تومانی روی این درگاه (با احتساب کارمزد درگاه) */
    public static function qty(array $g, int $amount): float
    {
        if (!class_exists('Rates')) return 0.0;
        $base = self::priceBase((string)($g['asset'] ?? ''));
        if ($base === '') return 0.0;

        $q = (float)Rates::amountToAsset($amount, $base, true);
        if ($q <= 0) return 0.0;

        $fee = (float)($g['fee'] ?? 0);
        if ($fee != 0.0) {
            $q = $q * (1 + ($fee / 100));
            if ($q <= 0) return 0.0;
        }
        return $q;
    }

    public static function decimals(string $asset): int
    {
        if (in_array($asset, ['USDT', 'USDC', 'DAI', 'FDUSD', 'TUSD', 'BUSD'], true)) return 2;
        if (in_array($asset, ['BTC', 'XMR'], true)) return 6;
        if (in_array($asset, ['DOGE', 'SHIB', 'TRX', 'NOT', 'XRP', 'ADA'], true)) return 2;
        return 4;
    }

    /** حداقل و حداکثر مبلغ مؤثر این درگاه (ترکیب تنظیمات کلی و درگاه) */
    public static function limits(array $g): array
    {
        $min = (int)DB::setting('min_deposit', 0);
        $max = (int)DB::setting('max_deposit', 0);

        $gmin = (int)($g['min'] ?? 0);
        $gmax = (int)($g['max'] ?? 0);

        if ($gmin > $min) $min = $gmin;
        if ($gmax > 0 && ($max <= 0 || $gmax < $max)) $max = $gmax;

        return ['min' => max(0, $min), 'max' => max(0, $max)];
    }

    /** همهٔ آدرس‌های ارزی (برای بررسی هش تراکنش) */
    public static function addresses(): array
    {
        $out = [];
        foreach (self::all() as $g) {
            if ($g['kind'] === 'crypto' && $g['address'] !== '') {
                $out[] = $g['address'];
            }
        }
        return array_values(array_unique($out));
    }

    /** کپی گرفتن از یک درگاه؛ برای ساخت سریع شبکهٔ تازه */
    public static function duplicate(string $id): ?array
    {
        $rows = self::all();
        foreach ($rows as $i => $g) {
            if ($g['id'] !== $id) continue;

            $new = $g;
            $new['id']      = rnd(8);
            $new['enabled'] = false;
            $new['label']   = trim(self::title($g)) . ' (کپی)';

            array_splice($rows, $i + 1, 0, [$new]);
            self::save($rows);
            return $new;
        }
        return null;
    }

    /** روشن یا خاموش کردن همهٔ درگاه‌ها */
    public static function setAll(bool $on): int
    {
        $rows = self::all();
        $n = 0;
        foreach ($rows as $i => $g) {
            if ((bool)$g['enabled'] === $on) continue;
            $rows[$i]['enabled'] = $on ? 1 : 0;
            $n++;
        }
        if ($n > 0) self::save($rows);
        return $n;
    }

    /** بردن درگاه به ابتدا یا انتهای فهرست */
    public static function moveEdge(string $id, bool $top): bool
    {
        $rows = self::all();
        $idx  = -1;
        foreach ($rows as $i => $g) {
            if ($g['id'] === $id) { $idx = $i; break; }
        }
        if ($idx < 0) return false;

        $row = $rows[$idx];
        array_splice($rows, $idx, 1);
        if ($top) array_unshift($rows, $row);
        else      $rows[] = $row;

        self::save($rows);
        return true;
    }

    public static function stats(): array
    {
        $all = self::all();
        $c = [
            'total' => count($all), 'crypto' => 0, 'card' => 0, 'nowpay' => 0,
            'on' => 0, 'off' => 0, 'reseller' => 0, 'user' => 0, 'assets' => 0, 'broken' => 0,
        ];
        $assets = [];
        foreach ($all as $g) {
            $c[$g['kind']] = ($c[$g['kind']] ?? 0) + 1;
            if ($g['enabled']) $c['on']++; else $c['off']++;
            if ($g['audience'] === 'reseller') $c['reseller']++;
            if ($g['audience'] === 'user') $c['user']++;
            if ($g['kind'] === 'crypto') {
                $assets[$g['asset']] = true;
                if ($g['address'] === '' || !self::hasRate($g)) $c['broken']++;
            }
            if ($g['kind'] === 'card' && $g['number'] === '') $c['broken']++;
        }
        $c['assets'] = count($assets);
        return $c;
    }
}
