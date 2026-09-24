<?php
declare(strict_types=1);

/**
 * مدیریت دکمه‌های ربات
 *
 * • افزودن / حذف / تغییر ترتیب دکمه‌های منوی اصلی
 * • دکمهٔ سفارشی: لینک، متن دلخواه یا مینی‌اپ
 * • دو حالت نمایش: کیبورد پایین (معمولی) یا دکمهٔ شیشه‌ای (اینلاین)
 * • رنگ‌بندی: تلگرام رنگ واقعی دکمه را پشتیبانی نمی‌کند، پس با
 *   نشانگرهای رنگی (🟦 🔵 …) و سبک‌های مختلف شبیه‌سازی می‌شود.
 */
class Btn
{
    public const KEY = 'bot_buttons';

    /** دکمه‌های درون‌ساخت: کلید => [آیکون، برچسب، مخاطب پیش‌فرض، گروه] */
    public const BUILTIN = [
        /* fixed76: دکمه‌های جدید (پیش‌فرض خاموش — از «دکمه‌های ربات» روشن کنید) */
        'prices'     => ['💰', 'تعرفه‌ها', 'all', 'منوی اصلی'],
        'campaign'   => ['🔥', 'پیشنهاد ویژه', 'all', 'منوی اصلی'],
        'renew'      => ['🔄', 'تمدید سرویس', 'all', 'منوی اصلی'],
        'tickets'    => ['🎫', 'تیکت‌های من', 'all', 'منوی اصلی'],
        'orders'     => ['🧾', 'سوابق خرید', 'all', 'منوی اصلی'], /* fixed79 */
        /* ---------- منوی اصلی ---------- */
        'products'   => ['🛒', 'محصولات', 'all', 'منوی اصلی'],
        'custom'     => ['📐', 'حجم و زمان دلخواه', 'all', 'منوی اصلی'],
        'services'   => ['📦', 'سرویس‌های من', 'all', 'منوی اصلی'],
        'wallet'     => ['💳', 'شارژ کیف پول', 'all', 'منوی اصلی'],
        'account'    => ['👤', 'حساب کاربری', 'all', 'منوی اصلی'],
        'test'       => ['🧪', 'اکانت تست', 'all', 'منوی اصلی'],
        'gift'       => ['🎁', 'کد هدیه', 'all', 'منوی اصلی'],
        'tutorial'   => ['🎓', 'آموزش', 'all', 'منوی اصلی'],
        'support'    => ['🆘', 'پشتیبانی', 'all', 'منوی اصلی'],
        'referral'   => ['🎯', 'معرفی به دوستان', 'all', 'منوی اصلی'],
        'miniapp'    => ['🚀', 'اپلیکیشن', 'all', 'منوی اصلی'],
        'stock'      => ['🏪', 'انبار ملی', 'all', 'منوی اصلی'],
        'home'       => ['🏠', 'منوی اصلی', 'all', 'منوی اصلی'],

        /* ---------- کیف پول ---------- */
        'wal_card'   => ['🧾', 'شارژ با کارت به کارت', 'all', 'کیف پول'],
        'wal_crypto' => ['🪙', 'شارژ با رمزارز', 'all', 'کیف پول'],
        'wal_np'     => ['🌐', 'شارژ خودکار (NowPayments)', 'all', 'کیف پول'],
        'wal_hp'     => ['🪙', 'شارژ آنی هوش‌پی', 'all', 'کیف پول'],
        'wal_zp'     => ["\u{1F3E6}", 'شارژ آنی زرین‌پال', 'all', 'کیف پول'], /* 0.0.2 #22 */
        'wal_hash'   => ['🔗', 'ثبت هش تراکنش', 'all', 'کیف پول'],
        'wal_hist'   => ['📜', 'تاریخچهٔ کیف پول', 'all', 'کیف پول'],

        /* ---------- حساب کاربری ---------- */
        'acc_phone'  => ['📱', 'ثبت شماره تماس', 'all', 'حساب کاربری'],
        'acc_email'  => ['📧', 'ثبت ایمیل', 'all', 'حساب کاربری'],
        'acc_name'   => ['✏️', 'ثبت نام و نام خانوادگی', 'all', 'حساب کاربری'],
        'verify'     => ['✅', 'تایید حساب', 'all', 'حساب کاربری'],
        'refs'       => ['👥', 'زیرمجموعه‌های من', 'all', 'حساب کاربری'],

        /* ---------- نمایندگی ---------- */
        'reseller'   => ['🏷', 'نمایندگی', 'reseller', 'نمایندگی'],
        'rssvcs'     => ['🧩', 'کانفیگ های نمایندگی', 'reseller', 'نمایندگی'],
        'rsapp'      => ['🖥', 'پنل نمایندگی (مینی‌اپ)', 'reseller', 'نمایندگی'],
        'rspay'      => ['💰', 'تسویه و اعتبار نمایندگی', 'reseller', 'نمایندگی'],
        'rsreq'      => ['📝', 'درخواست نمایندگی', 'user', 'نمایندگی'],

        /* ---------- مدیریت ---------- */
        'admin'      => ['🛠', 'پنل مدیریت', 'admin', 'مدیریت'],
        'admin_web'  => ['🔐', 'پنل وب مدیریت', 'admin', 'مدیریت'],
    ];

    /**
     * زیرمنوها: کلید دکمهٔ درون‌ساخت => منویی که دکمه داخل آن دیده می‌شود
     *
     * این دکمه‌ها زیرمجموعهٔ یک بخش دیگر هستند و نباید روی کیبورد منوی اصلی
     * بیایند؛ جای آن‌ها فقط داخل همان زیرمنو است.
     */
    public const SUBMENU = [
        'wal_card'   => 'wallet',
        'wal_crypto' => 'wallet',
        'wal_np'     => 'wallet',
        'wal_hp'     => 'wallet',
        'wal_zp'     => 'wallet',
        'wal_hash'   => 'wallet',
        'wal_hist'   => 'wallet',
        'acc_phone'  => 'account',
        'acc_email'  => 'account',
        'acc_name'   => 'account',
        'verify'     => 'account',
        'refs'       => 'account',
    ];

    /** منوهایی که یک دکمه می‌تواند داخلشان بنشیند */
    /**
     * دکمه‌های پیشنهادی هر منو — برای «تکمیل» زیرمنوهای خالی
     *
     * قالب هر ردیف: [کلید، آیکون، برچسب، مخاطب، عرض، روشن؟، نوع (اختیاری)]
     * این دکمه‌ها روی همان بخش‌های واقعی ربات سوار می‌شوند؛ پس بدون هیچ
     * تنظیم دیگری کار می‌کنند و فقط جای نمایششان زیرمنو است.
     */
    public const SEED = [
        /* بازکننده‌های زیرمنو روی کیبورد اصلی (پیش‌فرض خاموش) */
        'main' => [
            ['shop', '🛒', 'خرید و محصولات',   'all', 'half', 0, 'menu'],
            ['svc',  '📦', 'سرویس‌های من',     'all', 'half', 0, 'menu'],
            ['help', '🆘', 'پشتیبانی و آموزش', 'all', 'half', 0, 'menu'],
            ['more', '➕', 'امکانات بیشتر',    'all', 'half', 0, 'menu'],
        ],

        'wallet' => [
            ['gift',     '🎁', 'کد هدیه',             'all', 'half', 1],
            ['referral', '🎯', 'کسب اعتبار با معرفی', 'all', 'half', 1],
            ['verify',   '✅', 'تایید حساب',          'all', 'half', 0],
            ['home',     '🏠', 'بازگشت به منوی اصلی', 'all', 'full', 0],
        ],

        'account' => [
            ['wallet',   '👛', 'کیف پول و شارژ',      'all', 'half', 1],
            ['services', '📦', 'سرویس‌های من',        'all', 'half', 1],
            ['referral', '🎯', 'معرفی به دوستان',     'all', 'half', 1],
            ['home',     '🏠', 'بازگشت به منوی اصلی', 'all', 'full', 0],
        ],

        'shop' => [
            ['products', '🛒', 'همهٔ محصولات',        'all', 'half', 1],
            ['custom',   '📐', 'حجم و زمان دلخواه',   'all', 'half', 1],
            ['stock',    '🏪', 'انبار ملی کانفیگ',    'all', 'half', 1],
            ['test',     '🧪', 'اکانت تست رایگان',    'all', 'half', 1],
            ['gift',     '🎁', 'کد هدیه و تخفیف',     'all', 'half', 1],
            ['miniapp',  '🚀', 'خرید در اپلیکیشن',    'all', 'full', 1],
            ['prices',   '💰', 'تعرفه‌ها',            'all', 'half', 0], /* fixed79 */
            ['campaign', '🔥', 'پیشنهاد ویژه',        'all', 'half', 0],
            ['home',     '🏠', 'بازگشت به منوی اصلی', 'all', 'full', 0],
        ],

        'svc' => [
            ['services', '📦', 'سرویس‌های فعال من',     'all',      'half', 1],
            ['rssvcs',   '🧩', 'کانفیگ‌های نمایندگی',   'reseller', 'half', 1],
            ['stock',    '🏪', 'خریدهای انبار ملی',     'all',      'half', 1],
            ['tutorial', '🎓', 'آموزش اتصال',           'all',      'half', 1],
            ['support',  '🆘', 'مشکل در سرویس',         'all',      'half', 1],
            ['renew',    '🔄', 'تمدید سرویس',           'all',      'half', 0], /* fixed79 */
            ['orders',   '🧾', 'سوابق خرید و پرداخت',   'all',      'half', 0],
            ['home',     '🏠', 'بازگشت به منوی اصلی',   'all',      'full', 0],
        ],

        'help' => [
            ['support',  '🆘', 'ارسال تیکت پشتیبانی', 'all', 'half', 1],
            ['tutorial', '🎓', 'آموزش‌های اتصال',     'all', 'half', 1],
            ['verify',   '✅', 'تایید حساب',          'all', 'half', 0],
            ['tickets',  '🎫', 'تیکت‌های من',         'all', 'half', 0], /* fixed79 */
            ['miniapp',  '🚀', 'راهنما در اپلیکیشن',  'all', 'full', 0],
            ['home',     '🏠', 'بازگشت به منوی اصلی', 'all', 'full', 0],
        ],

        'more' => [
            ['referral', '🎯', 'معرفی به دوستان',     'all', 'half', 1],
            ['refs',     '👥', 'زیرمجموعه‌های من',    'all', 'half', 1],
            ['gift',     '🎁', 'کد هدیه',             'all', 'half', 1],
            ['test',     '🧪', 'اکانت تست',           'all', 'half', 1],
            ['verify',   '✅', 'تایید حساب',          'all', 'half', 1],
            ['account',  '👤', 'حساب کاربری',         'all', 'half', 1],
            ['tutorial', '🎓', 'آموزش',               'all', 'half', 0],
            ['home',     '🏠', 'بازگشت به منوی اصلی', 'all', 'full', 0],
        ],

        'reseller' => [
            ['rsapp',   '🖥', 'پنل نمایندگی (مینی‌اپ)', 'reseller', 'full', 1],
            ['rssvcs',  '🧩', 'کانفیگ‌های نمایندگی',    'reseller', 'half', 1],
            ['rspay',   '💰', 'تسویه و اعتبار',         'reseller', 'half', 1],
            ['rsreq',   '📝', 'درخواست نمایندگی',       'user',     'full', 1],
            ['support', '🆘', 'پشتیبانی نمایندگان',     'reseller', 'half', 0],
            ['home',    '🏠', 'بازگشت به منوی اصلی',    'all',      'full', 0],
        ],

        'admin' => [
            ['admin_web', '🔐', 'پنل وب مدیریت',       'admin', 'full', 1],
            ['miniapp',   '🚀', 'اپلیکیشن',            'admin', 'half', 0],
            ['home',      '🏠', 'بازگشت به منوی اصلی', 'admin', 'full', 0],
        ],
    ];

    public const MENUS = [
        'main'     => '🏠 منوی اصلی (کیبورد پایین)',
        'wallet'   => '👛 زیرمنوی شارژ کیف پول',
        'account'  => '👤 زیرمنوی حساب کاربری',
        'admin'    => '🛠 زیرمنوی پنل مدیریت',
        'reseller' => '🏷 زیرمنوی نمایندگی',
        'shop'     => '🛒 زیرمنوی خرید و محصولات',
        'svc'      => '📦 زیرمنوی سرویس‌های من',
        'help'     => '🆘 زیرمنوی پشتیبانی و آموزش',
        'more'     => '➕ زیرمنوی امکانات بیشتر',
    ];

    /** دکمه‌هایی که در نصب تازه روشن هستند (بقیه خاموش‌اند و از پنل روشن می‌شوند) */
    public const DEFAULT_ON = [
        'products', 'custom', 'services', 'wallet', 'account', 'test', 'gift',
        'tutorial', 'support', 'referral', 'stock', 'reseller', 'rssvcs', 'admin',
    ];

    /** دکمه‌هایی که پیش‌فرض تمام‌عرض هستند */
    public const DEFAULT_WIDE = [
        'reseller', 'admin', 'admin_web', 'rsreq', 'rsapp', 'miniapp',
    ];

    public const KINDS = [
        'builtin' => '⚙️ بخش درونی ربات',
        'url'     => '🔗 لینک خارجی',
        'text'    => '💬 متن دلخواه',
        'miniapp' => '🚀 مینی‌اپ',
        'stock'   => '🏪 انبار ملی',
        'menu'    => '🗂 باز کردن یک زیرمنو',
        'copy'    => '📋 کپی متن با یک لمس',
        'share'   => '📤 اشتراک‌گذاری ربات',
        'contact' => '📱 گرفتن شمارهٔ تماس',
        'location'=> '📍 گرفتن موقعیت مکانی',
    ];

    public const AUDIENCES = [
        'all'      => '👥 همه',
        'user'     => '🙋 کاربران عادی',
        'reseller' => '🏷 نمایندگان',
        'admin'    => '🛠 مدیران',
    ];

    /** رنگ => [نام، دایره، مربع، کد CSS مینی‌اپ] */
    /** عرض هر دکمه در ردیف */
    public const WIDTHS = [
        'auto'    => 'خودکار (طبق تنظیم عمومی)',
        'full'    => 'تمام عرض (تنها در ردیف)',
        'half'    => 'نصف عرض (۲ تایی کنار هم)',
        'third'   => 'یک‌سوم (۳ تایی کنار هم)',
        'quarter' => 'یک‌چهارم (۴ تایی کنار هم)',
    ];

    public const COLORS = [
        'none'   => ['بدون رنگ', '', '', ''],
        'blue'   => ['آبی', '🔵', '🟦', '#3b82f6'],
        'green'  => ['سبز', '🟢', '🟩', '#22c55e'],
        'red'    => ['قرمز', '🔴', '🟥', '#ef4444'],
        'yellow' => ['زرد', '🟡', '🟨', '#eab308'],
        'purple' => ['بنفش', '🟣', '🟪', '#a855f7'],
        'orange' => ['نارنجی', '🟠', '🟧', '#f97316'],
        'white'  => ['سفید', '⚪️', '⬜️', '#e5e7eb'],
        'black'  => ['مشکی', '⚫️', '⬛️', '#111827'],
        'brown'  => ['قهوه‌ای', '🟤', '🟫', '#a16207'],
    ];

    public const STYLES = [
        'plain'   => 'ساده',
        'circle'  => 'نشانگر دایره‌ای',
        'square'  => 'نشانگر مربعی',
        'wrap'    => 'رنگ در دو طرف',
        'bracket' => 'داخل کروشه',
        'line'    => 'با خط جداکننده',
        'dot'     => 'نقطه‌ی ابتدای متن',
        'arrow'   => 'پیکان انتهای متن',
        'dash'    => 'خط تیرهٔ دو طرف',
        'star'    => 'ستارهٔ ویژه',
    ];

    /* ==================== حالت نمایش ==================== */

    /** reply = کیبورد پایین ، inline = دکمهٔ شیشه‌ای ، both = هر دو */
    public static function mode(): string
    {
        $m = (string)DB::setting('btn_mode', 'reply');
        return in_array($m, ['reply', 'inline', 'both'], true) ? $m : 'reply';
    }

    public static function perRow(): int
    {
        $n = (int)DB::setting('btn_per_row', 2);
        return $n >= 1 && $n <= 4 ? $n : 2;
    }

    /* ==================== داده ==================== */

    public static function defaults(): array
    {
        $out  = [];
        $sort = 0;
        foreach (self::BUILTIN as $key => $meta) {
            $out[] = [
                'id'       => 'b_' . $key,
                'kind'     => 'builtin',
                'key'      => $key,
                'label'    => $meta[1],
                'icon'     => $meta[0],
                'color'    => 'none',
                'style'    => 'plain',
                'audience' => $meta[2],
                'enabled'  => in_array($key, self::DEFAULT_ON, true) ? 1 : 0,
                'wide'     => in_array($key, self::DEFAULT_WIDE, true) ? 1 : 0,
                'url'      => '',
                'text'     => '',
                'sort'     => $sort++,
            ];
        }
        foreach (self::seedRows() as $sr) {
            $sr['sort'] = $sort++;
            $out[]      = $sr;
        }
        return $out;
    }

    /**
     * ردیف‌های خام دکمه‌های پیشنهادی (SEED)
     *
     * @param string|null $only فقط همین منو ساخته شود
     */
    public static function seedRows(?string $only = null): array
    {
        $out = [];
        foreach (self::SEED as $menu => $list) {
            if (!isset(self::MENUS[$menu])) continue;
            if ($only !== null && $only !== '' && $only !== $menu) continue;
            foreach ($list as $s) {
                if (!is_array($s) || count($s) < 6) continue;
                $kind = (string)($s[6] ?? 'builtin');
                if (!isset(self::KINDS[$kind])) $kind = 'builtin';
                $key = (string)$s[0];
                if ($kind === 'builtin' && self::meta($key) === null) continue;
                if ($kind === 'menu'    && !isset(self::MENUS[$key]))   continue;
                $out[] = [
                    'id'       => 's_' . $menu . '_' . ($kind === 'menu' ? 'm' : '') . $key,
                    'kind'     => $kind,
                    'key'      => $key,
                    'icon'     => (string)$s[1],
                    'label'    => (string)$s[2],
                    'audience' => (string)$s[3],
                    'width'    => (string)$s[4],
                    'enabled'  => (int)$s[5] === 1 ? 1 : 0,
                    'menu'     => $menu,
                ];
            }
        }
        return $out;
    }

    /** هر منو چند دکمهٔ پیشنهادی ساخته‌نشده دارد */
    public static function seedMissing(): array
    {
        $have = [];
        foreach (self::all() as $r) $have[(string)($r['id'] ?? '')] = true;

        $out = [];
        foreach (array_keys(self::MENUS) as $m) $out[$m] = 0;
        foreach (self::seedRows() as $r) {
            $m = (string)($r['menu'] ?? 'main');
            if (!isset($out[$m])) $out[$m] = 0;
            if (!isset($have[(string)$r['id']])) $out[$m]++;
        }
        return $out;
    }

    /**
     * ساخت دکمه‌های پیشنهادیِ نبوده
     *
     * @return int تعداد دکمه‌های تازه
     */
    public static function seedMenus(?string $only = null): int
    {
        $rows = self::all();
        $have = [];
        foreach ($rows as $r) $have[(string)($r['id'] ?? '')] = true;

        $add  = 0;
        $sort = count($rows);
        foreach (self::seedRows($only) as $sr) {
            if (isset($have[(string)$sr['id']])) continue;
            $sr['sort'] = $sort++;
            $rows[]     = self::normalize($sr);
            $have[(string)$sr['id']] = true;
            $add++;
        }
        if ($add > 0) self::save($rows);
        return $add;
    }

    public static function normalize(array $r): array
    {
        $kind = (string)($r['kind'] ?? 'builtin');
        if (!isset(self::KINDS[$kind])) $kind = 'builtin';
        $key = (string)($r['key'] ?? '');
        if ($kind === 'builtin' && self::meta($key) === null) $key = 'products';

        $color = (string)($r['color'] ?? 'none');
        $style = (string)($r['style'] ?? 'plain');
        $aud   = (string)($r['audience'] ?? 'all');

        /* عرض دکمه: خودکار / تمام / نصف / یک‌سوم / یک‌چهارم */
        $width = (string)($r['width'] ?? '');
        if (!isset(self::WIDTHS[$width])) {
            $width = (int)($r['wide'] ?? 0) === 1 ? 'full' : 'auto';
        }

        /* منوی مقصد: منوی اصلی یا یکی از زیرمنوها */
        $menu = trim((string)($r['menu'] ?? ''));
        if ($menu === '' && $kind === 'builtin') $menu = (string)(self::SUBMENU[$key] ?? 'main');
        if (!isset(self::MENUS[$menu])) $menu = 'main';

        return [
            'id'       => (string)($r['id'] ?? ('b_' . rnd(6))),
            'kind'     => $kind,
            'key'      => $key,
            'label'    => trim((string)($r['label'] ?? '')) !== '' ? trim((string)$r['label']) : (string)((self::meta($key) ?? [])[1] ?? 'دکمه'),
            'icon'     => trim((string)($r['icon'] ?? '')),
            'color'    => isset(self::COLORS[$color]) ? $color : 'none',
            'style'    => isset(self::STYLES[$style]) ? $style : 'plain',
            'audience' => isset(self::AUDIENCES[$aud]) ? $aud : 'all',
            'enabled'  => (int)($r['enabled'] ?? 1) === 1,
            'width'    => $width,
            'wide'     => $width === 'full',
            'url'      => trim((string)($r['url'] ?? '')),
            'text'     => (string)($r['text'] ?? ''),
            'row'      => max(0, min(99, (int)($r['row'] ?? 0))),
            'menu'     => $menu,
            'desc'     => mb_substr(trim((string)($r['desc'] ?? '')), 0, 120),
            'copy'     => mb_substr(trim((string)($r['copy'] ?? '')), 0, 300),
            'hits'     => max(0, (int)($r['hits'] ?? 0)),
            'sort'     => (int)($r['sort'] ?? 0),
        ];
    }

    public static function all(): array
    {
        $j = jdec((string)DB::setting(self::KEY, '[]'));
        $rows = is_array($j) && $j !== [] ? $j : self::defaults();
        usort($rows, function (array $a, array $b) {
            return ((int)($a['sort'] ?? 0)) <=> ((int)($b['sort'] ?? 0));
        });
        $out = [];
        foreach ($rows as $r) {
            if (is_array($r)) $out[] = self::normalize($r);
        }
        return $out;
    }

    public static function save(array $rows): void
    {
        $out = [];
        $i   = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $r['sort'] = $i++;
            $out[]     = $r;
        }
        DB::setSetting(self::KEY, jenc(array_values($out)));
    }

    public static function reset(): void
    {
        DB::setSetting(self::KEY, jenc(self::defaults()));
    }

    public static function byId(string $id): ?array
    {
        foreach (self::withVirtual(self::all()) as $b) {
            if ($b['id'] === $id) return $b;
        }
        return null;
    }

    /* ==================== ویرایش ==================== */

    public static function upsert(array $in): array
    {
        $kind = (string)($in['kind'] ?? 'builtin');
        if ($kind === 'url' && trim((string)($in['url'] ?? '')) === '') {
            return ['ok' => false, 'message' => 'برای دکمهٔ لینک، آدرس لازم است.'];
        }
        if ($kind === 'text' && trim((string)($in['text'] ?? '')) === '') {
            return ['ok' => false, 'message' => 'متن پاسخ را وارد کنید.'];
        }
        if (trim((string)($in['label'] ?? '')) === '') {
            return ['ok' => false, 'message' => 'برچسب دکمه را وارد کنید.'];
        }

        $rows = self::all();
        $id   = trim((string)($in['id'] ?? ''));
        $new  = self::normalize($in);

        if ($id !== '') {
            $hit = false;
            foreach ($rows as $i => $b) {
                if ($b['id'] === $id) {
                    $new['id']   = $id;
                    $new['sort'] = $b['sort'];
                    if (!isset($in['row'])) $new['row'] = (int)($b['row'] ?? 0);
                    $rows[$i]    = $new;
                    $hit         = true;
                    break;
                }
            }
            if (!$hit) $rows[] = $new;
        } else {
            $new['id'] = 'b_' . rnd(6);
            $rows[]    = $new;
        }

        self::save($rows);
        return ['ok' => true, 'id' => $new['id'], 'message' => 'دکمه ذخیره شد.'];
    }

    public static function remove(string $id): bool
    {
        $out = [];
        $hit = false;
        foreach (self::all() as $b) {
            if ($b['id'] === $id) { $hit = true; continue; }
            $out[] = $b;
        }
        if ($hit) self::save($out);
        return $hit;
    }

    public static function toggle(string $id): bool
    {
        $rows = self::all();
        $hit  = false;
        foreach ($rows as $i => $b) {
            if ($b['id'] === $id) {
                $rows[$i]['enabled'] = $b['enabled'] ? 0 : 1;
                $hit = true;
                break;
            }
        }
        if ($hit) self::save($rows);
        return $hit;
    }

    public static function move(string $id, int $dir): bool
    {
        $rows = self::all();
        $idx  = -1;
        foreach ($rows as $i => $b) {
            if ($b['id'] === $id) { $idx = $i; break; }
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

    /** بردن دکمه به ابتدا یا انتهای فهرست */
    public static function moveEdge(string $id, bool $toTop): bool
    {
        $pick = null;
        $out  = [];
        foreach (self::all() as $b) {
            if ($b['id'] === $id) { $pick = $b; continue; }
            $out[] = $b;
        }
        if ($pick === null) return false;
        if ($toTop) array_unshift($out, $pick);
        else        $out[] = $pick;
        self::save($out);
        return true;
    }

    /** انتقال دکمه به شمارهٔ دلخواه در فهرست (۱ = اولین دکمه) */
    public static function moveTo(string $id, int $pos): bool
    {
        $pick = null;
        $out  = [];
        foreach (self::all() as $b) {
            if ($b['id'] === $id) { $pick = $b; continue; }
            $out[] = $b;
        }
        if ($pick === null) return false;

        $idx = $pos - 1;
        if ($idx < 0) $idx = 0;
        if ($idx > count($out)) $idx = count($out);
        array_splice($out, $idx, 0, [$pick]);
        self::save($out);
        return true;
    }

    /** تغییر عرض یک دکمه */
    public static function setWidth(string $id, string $width): bool
    {
        if (!isset(self::WIDTHS[$width])) return false;
        $rows = self::all();
        $hit  = false;
        foreach ($rows as $i => $b) {
            if ($b['id'] === $id) {
                $rows[$i]['width'] = $width;
                $rows[$i]['wide']  = $width === 'full' ? 1 : 0;
                $rows[$i]['row']   = 0;
                $hit = true;
                break;
            }
        }
        if ($hit) self::save($rows);
        return $hit;
    }

    /** تغییر رنگ (و در صورت نیاز سبک) یک دکمه */
    public static function setColor(string $id, string $color, string $style = ''): bool
    {
        $rows = self::all();
        $hit  = false;
        foreach ($rows as $i => $b) {
            if ($b['id'] === $id) {
                if (isset(self::COLORS[$color]))               $rows[$i]['color'] = $color;
                if ($style !== '' && isset(self::STYLES[$style])) $rows[$i]['style'] = $style;
                $hit = true;
                break;
            }
        }
        if ($hit) self::save($rows);
        return $hit;
    }

    /** روشن/خاموش گروهی؛ $on = null یعنی وضعیت هر دکمه برعکس شود */
    public static function toggleMany(array $ids, ?bool $on = null): int
    {
        $list = [];
        foreach ($ids as $x) {
            $x = trim((string)$x);
            if ($x !== '' && !in_array($x, $list, true)) $list[] = $x;
        }
        if ($list === []) return 0;

        $rows = self::all();
        $n    = 0;
        foreach ($rows as $i => $b) {
            if (!in_array((string)$b['id'], $list, true)) continue;
            $rows[$i]['enabled'] = $on === null ? ($b['enabled'] ? 0 : 1) : ($on ? 1 : 0);
            $n++;
        }
        if ($n > 0) self::save($rows);
        return $n;
    }

    /* ==================== گروه‌بندی دکمه‌ها ==================== */

    /** گروه یک دکمهٔ درون‌ساخت (برای فیلتر در پنل مدیریت) */
    public static function group(string $key): string
    {
        return (string)((self::meta($key) ?? [])[3] ?? 'دکمه سفارشی');
    }

    /** فهرست همهٔ گروه‌ها */
    public static function groups(): array
    {
        $out = [];
        foreach (self::builtinAll() as $meta) {
            $g = (string)($meta[3] ?? '');
            if ($g !== '' && !in_array($g, $out, true)) $out[] = $g;
        }
        $out[] = 'دکمه سفارشی';
        return $out;
    }

    /* ==================== برچسب و رنگ ==================== */

    /** برچسب نهایی دکمه با اعمال سبک و رنگ */
    public static function label(array $b): string
    {
        $icon = trim((string)($b['icon'] ?? ''));
        $base = trim($icon . ' ' . (string)($b['label'] ?? ''));
        $c    = self::COLORS[(string)($b['color'] ?? 'none')] ?? self::COLORS['none'];
        $st   = (string)($b['style'] ?? 'plain');

        if ($st === 'bracket') return '【 ' . $base . ' 】';
        if ($st === 'line')    return '┃ ' . $base;
        if ($st === 'dot')     return '• ' . $base;
        if ($st === 'arrow')   return $base . ' ‹';
        if ($st === 'dash')    return '— ' . $base . ' —';
        if ($st === 'star')    return '✨ ' . $base;
        if ($c[1] === '')      return $base;

        $mark = $st === 'square' ? $c[2] : $c[1];
        if ($st === 'wrap')   return $mark . ' ' . $base . ' ' . $mark;
        if ($st === 'circle' || $st === 'square') return $mark . ' ' . $base;
        return $base;
    }

    /** رنگ CSS برای نمایش در مینی‌اپ و پنل */
    public static function css(array $b): string
    {
        $c = self::COLORS[(string)($b['color'] ?? 'none')] ?? self::COLORS['none'];
        return (string)$c[3];
    }

    /** فقط دکمه‌های مناسب این کاربر */
    public static function visible(bool $isAdmin = false, bool $isReseller = false, string $menu = 'main'): array
    {
        $menu  = isset(self::MENUS[$menu]) ? $menu : 'main';
        $out   = [];
        $seen  = [];
        $cusOn = self::customPlanOn();
        foreach (self::withVirtual(self::all()) as $b) {
            if (!$b['enabled']) continue;
            if (self::menuOf($b) !== $menu) continue;
            /* fixed76: یک دکمهٔ داخلی فقط یک‌بار در هر منو (جلوگیری از دکمهٔ تکراری) */
            if ($b['kind'] === 'builtin') {
                $sig = (string)($b['key'] ?? '');
                if ($sig !== '' && isset($seen[$sig])) continue;
                $seen[$sig] = true;
            }
            /* دکمهٔ پلن دلخواه فقط وقتی قابلیت در تنظیمات روشن باشد */
            if ($b['kind'] === 'builtin' && (string)($b['key'] ?? '') === 'custom' && !$cusOn) continue;
            if ($b['audience'] === 'admin' && !$isAdmin) continue;
            if ($b['audience'] === 'reseller' && !$isReseller) continue;
            if ($b['audience'] === 'user' && $isReseller) continue;
            if ($b['kind'] === 'miniapp' && self::miniappUrl() === '') continue;
            if ($b['kind'] === 'url'  && trim((string)($b['url'] ?? '')) === '') continue;
            if ($b['kind'] === 'text' && trim((string)($b['text'] ?? '')) === '') continue;
            if ($b['kind'] === 'copy' && trim((string)($b['copy'] ?? '')) === '') continue;
            if ($b['kind'] === 'menu') {
                $tm = (string)($b['key'] ?? '');
                if (!isset(self::MENUS[$tm]) || $tm === $menu) continue;
            }
            $out[] = $b;
        }
        return $out;
    }

    /** آیا قابلیت «حجم و زمان دلخواه» در تنظیمات روشن است؟ */
    public static function customPlanOn(): bool
    {
        try {
            if ((int)DB::setting('cus_enabled', 0) === 1) return true;
            /* fixed76: وجود محصول نوع «حجم و زمان دلخواه» هم کافی است */
            return class_exists('Bot') && method_exists('Bot', 'cusProducts') && count(Bot::cusProducts()) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * دکمهٔ «حجم و زمان دلخواه»: اگر پلن دلخواه روشن است اما چیدمان ذخیره‌شدهٔ پنل وب
     * (قدیمی) اصلاً این دکمه را ندارد، یک ردیف مجازی بعد از «محصولات» تزریق می‌شود.
     * اگر مدیر آن را در چیدمان خاموش کرده باشد (ردیف وجود دارد)، همان تصمیم محترم است.
     */
    private static function withVirtual(array $rows): array
    {
        if (!self::customPlanOn()) return $rows;
        foreach ($rows as $r) {
            if ((string)($r['kind'] ?? '') === 'builtin' && (string)($r['key'] ?? '') === 'custom') return $rows;
        }
        $ins = self::normalize([
            'id' => 'b_custom', 'kind' => 'builtin', 'key' => 'custom',
            'label' => self::BUILTIN['custom'][1], 'icon' => self::BUILTIN['custom'][0],
            'audience' => 'all', 'enabled' => 1, 'width' => 'half', 'sort' => 0,
        ]);
        $out  = [];
        $done = false;
        foreach ($rows as $r) {
            $out[] = $r;
            if (!$done && (string)($r['kind'] ?? '') === 'builtin' && (string)($r['key'] ?? '') === 'products' && self::menuOf($r) === 'main') {
                $out[] = $ins;
                $done  = true;
            }
        }
        if (!$done) $out[] = $ins;
        return $out;
    }

    public static function labels(): array
    {
        $out = [];
        foreach (self::all() as $b) {
            $out[] = self::label($b);
        }
        return $out;
    }

    /** پیدا کردن دکمه از روی متنی که کاربر زده است */
    public static function match(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') return null;
        foreach (self::withVirtual(self::all()) as $b) {
            if (self::label($b) === $text) return $b;
            $plain = trim(trim((string)$b['icon']) . ' ' . (string)$b['label']);
            if ($plain === $text || (string)$b['label'] === $text) return $b;
        }
        return null;
    }

    /* ==================== ساخت کیبورد ==================== */

    public static function miniappUrl(): string
    {
        $u = trim((string)DB::setting('miniapp_url', ''));
        if ($u === '' && (string)DB::setting('miniapp_enabled', '1') === '1' && function_exists('app_url')) {
            $base = trim((string)app_url());
            if ($base !== '') $u = rtrim($base, '/') . '/miniapp/';
        }
        return strpos($u, 'https://') === 0 ? $u : '';
    }

    /** چند دکمه در این ردیف جا می‌شود؟ */
    private static function capacity(array $b, int $per): int
    {
        switch ((string)($b['width'] ?? 'auto')) {
            case 'full':    return 1;
            case 'half':    return 2;
            case 'third':   return 3;
            case 'quarter': return 4;
        }
        return max(1, min(4, $per));
    }

    /**
     * چیدن دکمه‌ها در ردیف‌ها
     *
     * اگر دکمه شمارهٔ ردیف صریح داشته باشد (خروجی «چیدمان کشیدنی»)،
     * همان ردیف‌بندی مو به مو در ربات بازتولید می‌شود و دو ردیف هرگز
     * با هم ادغام نمی‌شوند — حتی اگر یکی از دکمه‌های ردیف برای این
     * کاربر مخفی باشد. دکمه‌های بدون شمارهٔ ردیف (نسخه‌های قبل)
     * مانند گذشته بر اساس عرض چیده می‌شوند.
     */
    private static function pack(array $items): array
    {
        $rows = [];
        $cur  = [];
        $cap  = 0;
        $rw   = 0;

        foreach ($items as $it) {
            $c = max(1, (int)($it['cap'] ?? 1));
            $r = max(0, (int)($it['row'] ?? 0));

            /* ردیف صریح */
            if ($r > 0) {
                if ($cur !== [] && $rw !== $r) { $rows[] = $cur; $cur = []; $cap = 0; }
                $rw    = $r;
                $cur[] = $it['btn'];
                if (count($cur) >= 4) { $rows[] = $cur; $cur = []; $cap = 0; $rw = 0; }
                continue;
            }

            /* بدون ردیف صریح: بر اساس عرض */
            if ($cur !== [] && ($rw > 0 || $c !== $cap)) { $rows[] = $cur; $cur = []; }
            $rw    = 0;
            $cap   = $c;
            $cur[] = $it['btn'];
            if (count($cur) >= $cap) { $rows[] = $cur; $cur = []; $cap = 0; }
        }
        if ($cur !== []) $rows[] = $cur;
        return $rows;
    }

    /** ردیف‌های کیبورد پایین */
    public static function replyRows(bool $isAdmin = false, bool $isReseller = false): array
    {
        $per   = self::perRow();
        $items = [];

        foreach (self::visible($isAdmin, $isReseller) as $b) {
            $btn = ['text' => self::label($b)];
            if ($b['kind'] === 'miniapp') {
                $mu = self::miniappUrl();
                if ($mu !== '') $btn['web_app'] = ['url' => $mu];
            } elseif ($b['kind'] === 'contact') {
                $btn['request_contact'] = true;
            } elseif ($b['kind'] === 'location') {
                $btn['request_location'] = true;
            }
            $items[] = ['btn' => $btn, 'cap' => self::capacity($b, $per), 'row' => (int)($b['row'] ?? 0)];
        }
        return self::pack($items);
    }

    /** ردیف‌های دکمهٔ شیشه‌ای (اینلاین) */
    public static function inlineRows(bool $isAdmin = false, bool $isReseller = false): array
    {
        $per   = self::perRow();
        $items = [];

        foreach (self::visible($isAdmin, $isReseller) as $b) {
            $btn = self::inlineBtn($b);
            if ($btn === null) continue;
            $items[] = ['btn' => $btn, 'cap' => self::capacity($b, $per), 'row' => (int)($b['row'] ?? 0)];
        }
        return self::pack($items);
    }

    /* ==================== چیدمان ردیفی ==================== */

    /* ==================== منو و زیرمنو ==================== */

    /**
     * این دکمه داخل کدام منو دیده می‌شود؟
     *
     * دکمه‌های درون‌ساختی که زیرمجموعهٔ یک بخش دیگر هستند (مانند روش‌های
     * شارژ کیف پول) فقط داخل همان زیرمنو نمایش داده می‌شوند و روی کیبورد
     * منوی اصلی نمی‌آیند.
     */
    public static function menuOf(array $b): string
    {
        $m = trim((string)($b['menu'] ?? ''));
        if ($m === '' && (string)($b['kind'] ?? '') === 'builtin') {
            $m = (string)(self::SUBMENU[(string)($b['key'] ?? '')] ?? 'main');
        }
        return isset(self::MENUS[$m]) ? $m : 'main';
    }

    public static function menuTitle(string $menu): string
    {
        return (string)(self::MENUS[$menu] ?? self::MENUS['main']);
    }

    /** این منو دکمهٔ روشنی دارد؟ */
    public static function menuHas(string $menu, bool $isAdmin = false, bool $isReseller = false): bool
    {
        return self::visible($isAdmin, $isReseller, $menu) !== [];
    }

    /**
     * آیا این دکمهٔ درون‌ساخت در چیدمان فعلی روشن و قابل نمایش است؟
     *
     * درگاه‌های پرداخت با صفحهٔ «درگاه‌های پرداخت» روشن/خاموش می‌شوند و صفحهٔ
     * «مدیریت دکمه‌ها» فقط جای نمایش دکمه را تعیین می‌کند. ربات با این تابع
     * می‌فهمد که برای یک درگاه، دکمه‌ای در چیدمان روشن هست یا نه.
     */
    public static function isOn(string $key, bool $isAdmin = false, bool $isReseller = false, string $menu = 'main'): bool
    {
        if ($key === '') return false;
        foreach (self::visible($isAdmin, $isReseller, $menu) as $b) {
            if ((string)($b['key'] ?? '') === $key) return true;
        }
        return false;
    }

    /** آیا این کلید در چیدمان ذخیره‌شده وجود دارد؟ (روشن یا خاموش) */
    public static function hasKey(string $key): bool
    {
        if ($key === '') return false;
        foreach (self::all() as $b) {
            if ((string)($b['key'] ?? '') === $key) return true;
        }
        return false;
    }

    /**
     * ردیف‌های اینلاین یک زیرمنو (کیف پول، حساب کاربری، …)
     *
     * @param array $skipKeys کلیدهایی که فعلا نباید نمایش داده شوند (مانند درگاه خاموش)
     */
    public static function subRows(string $menu, bool $isAdmin = false, bool $isReseller = false, array $skipKeys = []): array
    {
        $per  = self::perRow();
        $skip = [];
        foreach ($skipKeys as $k) $skip[(string)$k] = true;

        $items = [];
        foreach (self::visible($isAdmin, $isReseller, $menu) as $b) {
            $key = (string)($b['key'] ?? '');
            if ($key !== '' && isset($skip[$key])) continue;

            $btn = self::inlineBtn($b);
            if ($btn === null) continue;
            $items[] = ['btn' => $btn, 'cap' => self::capacity($b, $per), 'row' => (int)($b['row'] ?? 0)];
        }
        return self::pack($items);
    }
    /** حداکثر تعداد دکمه در یک ردیف (تنظیم صفحه مدیریت) */
    public static function maxPerRow(): int
    {
        $n = (int)DB::setting('btn_max_per_row', 3);
        return $n >= 1 && $n <= 4 ? $n : 3;
    }

    /** افزودن دکمه های درون ساخت تازه به چیدمان فعلی (غیرفعال) */
    public static function syncBuiltins(): int
    {
        $rows = self::all();
        $have = [];
        foreach ($rows as $r) {
            if ((string)($r['kind'] ?? '') === 'builtin') $have[(string)$r['key']] = true;
        }

        $add  = 0;
        $sort = count($rows);
        foreach (self::builtinAll() as $key => $meta) {
            if (isset($have[$key])) continue;
            $rows[] = self::normalize([
                'id'       => 'b_' . $key,
                'kind'     => 'builtin',
                'key'      => $key,
                'label'    => $meta[1],
                'icon'     => $meta[0],
                'audience' => $meta[2],
                'enabled'  => 0,
                'width'    => 'half',
                'sort'     => $sort++,
            ]);
            $add++;
        }
        if ($add > 0) self::save($rows);
        return $add;
    }

    /**
     * چیدمان فعلی به صورت ردیف های ویرایشگر (بدون فیلتر مخاطب)
     * خروجی: مارای از ردیف ها که هر ردیف مارایی از دکمه هاست
     */
    public static function editorRows(): array
    {
        $per   = self::perRow();
        $items = [];
        foreach (self::all() as $b) {
            $items[] = ['btn' => $b, 'cap' => self::capacity($b, $per), 'row' => (int)($b['row'] ?? 0)];
        }
        return self::pack($items);
    }

    /**
     * ذخیره چیدمان درگ اند دراپ
     *
     * قالب ورودی: "id,id;id;id,id,id"
     * هر سمی‌کولون یک ردیف تازه و هر کاما یک دکمه در همان ردیف است.
     * شمارهٔ ردیف روی خود دکمه ذخیره می‌شود تا ربات دقیقاً همین
     * ردیف‌بندی را بسازد؛ عرض هم برای نمایش پنل به‌روز می‌شود.
     */
    public static function saveLayout(string $layout): array
    {
        $byId = [];
        foreach (self::all() as $b) $byId[(string)$b['id']] = $b;
        if ($byId === []) return ['ok' => false, 'message' => 'دکمه ای برای مرتب سازی وجود ندارد.'];

        $wmap = [1 => 'full', 2 => 'half', 3 => 'third', 4 => 'quarter'];
        $out  = [];
        $seen = [];
        $rows = 0;

        foreach (explode(';', $layout) as $row) {
            $ids = [];
            foreach (explode(',', $row) as $id) {
                $id = trim($id);
                if ($id === '' || isset($seen[$id]) || !isset($byId[$id])) continue;
                $ids[]     = $id;
                $seen[$id] = true;
            }
            if ($ids === []) continue;

            /* بیش از ۴ دکمه در یک ردیف جا نمی‌شود؛ خودکار ردیف تازه می‌سازیم */
            foreach (array_chunk($ids, 4) as $chunk) {
                $rows++;
                $cnt = count($chunk);
                foreach ($chunk as $id) {
                    $b          = $byId[$id];
                    $b['width'] = $wmap[$cnt] ?? 'half';
                    $b['wide']  = $cnt === 1;
                    $b['row']   = $rows;
                    $out[]      = $b;
                }
            }
        }

        /* دکمه های جا مانده به انتهای فهرست می روند */
        foreach ($byId as $id => $b) {
            if (!isset($seen[$id])) { $b['row'] = 0; $out[] = $b; }
        }
        if ($out === []) return ['ok' => false, 'message' => 'چیدمان نامعتبر است.'];

        self::save($out);
        return ['ok' => true, 'rows' => $rows, 'count' => count($out)];
    }

    /* ==================== ساخت دکمهٔ شیشه‌ای ==================== */

    /** یک دکمهٔ اینلاین بر اساس نوع دکمه می‌سازد (null یعنی قابل نمایش نیست) */
    private static function inlineBtn(array $b): ?array
    {
        $txt  = self::label($b);
        $kind = (string)($b['kind'] ?? 'builtin');

        if ($kind === 'url') {
            $u = trim((string)($b['url'] ?? ''));
            return $u === '' ? null : ['text' => $txt, 'url' => $u];
        }

        if ($kind === 'miniapp') {
            $mu = self::miniappUrl();
            return $mu === '' ? null : ['text' => $txt, 'web_app' => ['url' => $mu]];
        }

        if ($kind === 'copy') {
            $c = trim((string)($b['copy'] ?? ''));
            return $c === '' ? null : ['text' => $txt, 'copy_text' => ['text' => $c]];
        }

        if ($kind === 'share') {
            return ['text' => $txt, 'switch_inline_query' => (string)($b['text'] ?? '')];
        }

        if ($kind === 'menu') {
            $m = (string)($b['key'] ?? '');
            return isset(self::MENUS[$m]) ? ['text' => $txt, 'callback_data' => 'bmenu:' . $m] : null;
        }

        return ['text' => $txt, 'callback_data' => 'btn:' . (string)($b['id'] ?? '')];
    }

    /* ==================== آمار منوها ==================== */

    /** برای هر منو: تعداد کل، روشن و تعداد دکمه‌هایی که آن منو را باز می‌کنند */
    public static function menuCounts(): array
    {
        $out = [];
        foreach (array_keys(self::MENUS) as $m) {
            $out[$m] = ['all' => 0, 'on' => 0, 'opener' => 0, 'opener_off' => 0];
        }

        foreach (self::all() as $b) {
            $m = self::menuOf($b);
            if (!isset($out[$m])) $out[$m] = ['all' => 0, 'on' => 0, 'opener' => 0, 'opener_off' => 0];

            $out[$m]['all']++;
            if (!empty($b['enabled'])) $out[$m]['on']++;

            if ((string)($b['kind'] ?? '') === 'menu') {
                $t = (string)($b['key'] ?? '');
                if (isset($out[$t])) {
                    if (!empty($b['enabled'])) $out[$t]['opener']++;
                    else                       $out[$t]['opener_off']++;
                }
            }
        }
        return $out;
    }

    /* ==================== سلامت چیدمان ==================== */

    /**
     * مشکلات چیدمان فعلی را پیدا می‌کند.
     * خروجی: [['id','label','level' => 'err|warn','msg','code']]
     */
    public static function health(): array
    {
        $handlers = self::scanHandlers(); /* fixed80 */
        $all  = self::all();
        $out  = [];
        $seen = [];
        $lbl  = [];

        foreach ($all as $b) {
            $id   = (string)($b['id'] ?? '');
            $name = trim((string)($b['label'] ?? ''));
            $kind = (string)($b['kind'] ?? '');
            $menu = self::menuOf($b);

            if (isset($seen[$id])) {
                $out[] = ['id' => $id, 'label' => $name, 'level' => 'err', 'code' => 'dup_id',
                    'msg' => 'شناسهٔ تکراری است و یکی از دو دکمه هرگز کار نمی‌کند.'];
            }
            $seen[$id] = true;

            if ($name === '') {
                $out[] = ['id' => $id, 'label' => '—', 'level' => 'err', 'code' => 'no_label',
                    'msg' => 'برچسب دکمه خالی است.'];
            }

            if ($kind === 'url' && !preg_match('~^https?://~i', trim((string)($b['url'] ?? '')))) {
                $out[] = ['id' => $id, 'label' => $name, 'level' => 'err', 'code' => 'bad_url',
                    'msg' => 'آدرس لینک نامعتبر است (باید با https:// شروع شود).'];
            }

            if ($kind === 'text' && trim((string)($b['text'] ?? '')) === '') {
                $out[] = ['id' => $id, 'label' => $name, 'level' => 'err', 'code' => 'no_text',
                    'msg' => 'متن پاسخ خالی است؛ دکمه بی‌واکنش می‌ماند.'];
            }

            if ($kind === 'copy' && trim((string)($b['copy'] ?? '')) === '') {
                $out[] = ['id' => $id, 'label' => $name, 'level' => 'err', 'code' => 'no_copy',
                    'msg' => 'متنی برای کپی تعیین نشده است.'];
            }

            if ($kind === 'menu') {
                $t = (string)($b['key'] ?? '');
                if (!isset(self::MENUS[$t])) {
                    $out[] = ['id' => $id, 'label' => $name, 'level' => 'err', 'code' => 'bad_menu',
                        'msg' => 'این دکمه به منویی اشاره می‌کند که وجود ندارد.'];
                } elseif ($t === $menu) {
                    $out[] = ['id' => $id, 'label' => $name, 'level' => 'err', 'code' => 'self_menu',
                        'msg' => 'دکمه همان منویی را باز می‌کند که خودش داخل آن است (حلقه).'];
                }
            }

            if ($kind === 'builtin' && self::meta((string)($b['key'] ?? '')) === null) {
                $out[] = ['id' => $id, 'label' => $name, 'level' => 'err', 'code' => 'bad_key',
                    'msg' => 'این دکمه به بخشی از ربات وصل است که دیگر وجود ندارد.'];
            } elseif ($kind === 'builtin' && $handlers !== [] && !in_array((string)($b['key'] ?? ''), $handlers, true) && !empty($b['enabled'])) {
                /* fixed80: کلید در فهرست هست اما در کد ربات هندلر ندارد */
                $out[] = ['id' => $id, 'label' => $name, 'level' => 'warn', 'code' => 'no_handler',
                    'msg' => 'در اسکن کد ربات هیچ هندلری برای کلید «' . (string)($b['key'] ?? '') . '» پیدا نشد؛ زدن این دکمه در ربات کاری نمی‌کند.'];
            }

            if ($kind === 'miniapp' && self::miniappUrl() === '') {
                $out[] = ['id' => $id, 'label' => $name, 'level' => 'warn', 'code' => 'no_app',
                    'msg' => 'آدرس مینی‌اپ تنظیم نشده؛ این دکمه در ربات دیده نمی‌شود.'];
            }

            if (!empty($b['enabled'])) {
                $k = $menu . '|' . self::label($b);
                if (isset($lbl[$k])) {
                    $out[] = ['id' => $id, 'label' => $name, 'level' => 'err', 'code' => 'dup_label',
                        'msg' => 'برچسب تکراری در یک منو؛ ربات نمی‌داند کدام را اجرا کند.'];
                }
                $lbl[$k] = true;
            }
        }

        /* زیرمنوهایی که هیچ راه ورودی ندارند */
        foreach (self::menuCounts() as $m => $c) {
            if ($m === 'main') continue;
            if ((int)$c['on'] > 0 && (int)$c['opener'] === 0 && !isset(self::SUBMENU_PARENT[$m])) {
                if ((int)($c['opener_off'] ?? 0) > 0) {
                    $out[] = ['id' => '', 'label' => self::menuTitle($m), 'level' => 'warn', 'code' => 'opener_off',
                        'msg' => 'دکمهٔ بازکنندهٔ این زیرمنو ساخته شده ولی خاموش است؛ آن را روشن کنید.'];
                    continue;
                }
                $out[] = ['id' => '', 'label' => self::menuTitle($m), 'level' => 'warn', 'code' => 'orphan_menu',
                    'msg' => 'این زیرمنو دکمهٔ روشن دارد ولی هیچ دکمه‌ای آن را باز ��می‌کند.'];
            }
        }

        /* منوی اصلی خالی */
        $mc = self::menuCounts();
        if ((int)($mc['main']['on'] ?? 0) === 0) {
            $out[] = ['id' => '', 'label' => 'منوی اصلی', 'level' => 'err', 'code' => 'empty_main',
                'msg' => 'هیچ دکمه‌ای در منوی اصلی روشن نیست؛ کاربر منوی خالی می‌بیند.'];
        }

        return $out;
    }

    /** زیرمنوهایی که خود ربات آنها را باز می‌کند و نیازی به دکمهٔ بازکننده ندارند */
    public const SUBMENU_PARENT = [
        'wallet'   => 'wallet',
        'account'  => 'account',
        'admin'    => 'admin',
        'reseller' => 'reseller',
    ];

    /**
     * رفع خودکار مشکلات چیدمان
     * خروجی: ['fixed' => تعداد، 'log' => [متن کارهای انجام‌شده]]
     */
    public static function autoFix(): array
    {
        $rows = self::all();
        $log  = [];
        $out  = [];
        $ids  = [];
        $lbl  = [];

        foreach ($rows as $b) {
            $kind = (string)($b['kind'] ?? 'builtin');
            $name = trim((string)($b['label'] ?? ''));

            /* شناسهٔ تکراری */
            $id = (string)($b['id'] ?? '');
            if ($id === '' || isset($ids[$id])) {
                $b['id'] = 'b_' . rnd(6);
                $log[]   = 'شناسهٔ تکراری «' . $name . '» اصلاح شد.';
            }
            $ids[(string)$b['id']] = true;

            /* برچسب خالی */
            if ($name === '') {
                $b['label'] = (string)((self::meta((string)($b['key'] ?? '')) ?? [])[1] ?? 'دکمه');
                $log[]      = 'برچسب خالی پر شد: ' . $b['label'];
            }

            /* لینک بدون پروتکل */
            if ($kind === 'url') {
                $u = trim((string)($b['url'] ?? ''));
                if ($u !== '' && !preg_match('~^https?://~i', $u)) {
                    $b['url'] = 'https://' . ltrim($u, '/');
                    $log[]    = 'آدرس «' . $b['label'] . '» به https تبدیل شد.';
                }
                if (trim((string)$b['url']) === '' && !empty($b['enabled'])) {
                    $b['enabled'] = false;
                    $log[]        = 'دکمهٔ بدون آدرس خاموش شد: ' . $b['label'];
                }
            }

            /* متن یا کپی خالی */
            if (($kind === 'text' && trim((string)($b['text'] ?? '')) === '')
                || ($kind === 'copy' && trim((string)($b['copy'] ?? '')) === '')) {
                if (!empty($b['enabled'])) {
                    $b['enabled'] = false;
                    $log[]        = 'دکمهٔ ناقص خاموش شد: ' . $b['label'];
                }
            }

            /* منوی نامعتبر */
            if ($kind === 'menu') {
                $t = (string)($b['key'] ?? '');
                if (!isset(self::MENUS[$t]) || $t === self::menuOf($b)) {
                    $b['enabled'] = false;
                    $log[]        = 'دکمهٔ منوی نامعتبر خاموش شد: ' . $b['label'];
                }
            }

            /* کلید درون‌ساخت ناشناخته */
            if ($kind === 'builtin' && self::meta((string)($b['key'] ?? '')) === null) {
                $b['enabled'] = false;
                $log[]        = 'دکمهٔ متصل به بخش حذف‌شده خاموش شد: ' . $b['label'];
            }

            /* مینی‌اپ بدون آدرس */
            if ($kind === 'miniapp' && self::miniappUrl() === '' && !empty($b['enabled'])) {
                $b['enabled'] = false;
                $log[]        = 'دکمهٔ مینی‌اپ تا تنظیم آدرس خاموش شد.';
            }

            /* برچسب تکراری در یک منو */
            if (!empty($b['enabled'])) {
                $k = self::menuOf($b) . '|' . self::label($b);
                if (isset($lbl[$k])) {
                    $b['enabled'] = false;
                    $log[]        = 'دکمهٔ تکراری خاموش شد: ' . $b['label'];
                } else {
                    $lbl[$k] = true;
                }
            }

            $out[] = $b;
        }

        /* زیرمنوی بدون راه ورودی ← دکمهٔ بازکننده می‌سازیم */
        $cnt = [];
        foreach (array_keys(self::MENUS) as $m) $cnt[$m] = ['on' => 0, 'opener' => 0];
        foreach ($out as $b) {
            $m = self::menuOf($b);
            if (isset($cnt[$m]) && !empty($b['enabled'])) $cnt[$m]['on']++;
            if ((string)($b['kind'] ?? '') === 'menu' && !empty($b['enabled'])) {
                $t = (string)($b['key'] ?? '');
                if (isset($cnt[$t])) $cnt[$t]['opener']++;
            }
        }

        foreach ($cnt as $m => $c) {
            if ($m === 'main' || isset(self::SUBMENU_PARENT[$m])) continue;
            if ((int)$c['on'] > 0 && (int)$c['opener'] === 0) {
                $out[] = self::normalize([
                    'id'       => 'b_' . rnd(6),
                    'kind'     => 'menu',
                    'key'      => $m,
                    'label'    => trim(preg_replace('~^\S+\s~u', '', self::menuTitle($m))),
                    'icon'     => '🗂',
                    'audience' => 'all',
                    'enabled'  => 1,
                    'width'    => 'full',
                    'menu'     => 'main',
                ]);
                $log[] = 'دکمهٔ ورود به «' . self::menuTitle($m) . '» ساخته شد.';
            }
        }

        if ($log !== []) self::save($out);
        return ['fixed' => count($log), 'log' => $log];
    }

    /* ==================== پشتیبان‌گیری چیدمان ==================== */

    public static function exportJson(): string
    {
        return (string)json_encode([
            'v'        => 2,
            'mode'     => self::mode(),
            'per_row'  => self::perRow(),
            'buttons'  => self::all(),
            'saved_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public static function importJson(string $json): array
    {
        $d = json_decode(trim($json), true);
        if (!is_array($d)) return ['ok' => false, 'message' => 'فایل یا متن واردشده معتبر نیست.'];

        $rows = is_array($d['buttons'] ?? null) ? $d['buttons'] : $d;
        if (!is_array($rows) || $rows === []) return ['ok' => false, 'message' => 'دکمه‌ای در ورودی پیدا نشد.'];

        $out = [];
        foreach ($rows as $r) {
            if (is_array($r)) $out[] = self::normalize($r);
        }
        if ($out === []) return ['ok' => false, 'message' => 'ساختار دکمه‌ها قابل خواندن نبود.'];

        self::save($out);

        if (isset($d['mode']) && in_array((string)$d['mode'], ['reply', 'inline', 'both'], true)) {
            DB::setSetting('btn_mode', (string)$d['mode']);
        }
        if (isset($d['per_row'])) {
            DB::setSetting('btn_per_row', (string)max(1, min(4, (int)$d['per_row'])));
        }

        return ['ok' => true, 'count' => count($out), 'message' => 'چیدمان بازگردانده شد.'];
    }

    /* ==================== ابزارهای کوتاه ==================== */

    /** جابه‌جایی یک دکمه بین منوها */
    public static function setMenu(string $id, string $menu): bool
    {
        if (!isset(self::MENUS[$menu])) return false;
        $rows = self::all();
        $hit  = false;
        foreach ($rows as $i => $b) {
            if ((string)$b['id'] === $id) {
                $rows[$i]['menu'] = $menu;
                $rows[$i]['row']  = 0;
                $hit = true;
                break;
            }
        }
        if ($hit) self::save($rows);
        return $hit;
    }

    /** کپی یک دکمه */
    public static function duplicate(string $id): ?string
    {
        $src = self::byId($id);
        if ($src === null) return null;

        $new           = $src;
        $new['id']     = 'b_' . rnd(6);
        $new['label']  = mb_substr((string)$src['label'] . ' ۲', 0, 60);
        $new['row']    = 0;
        $new['enabled'] = false;

        $rows   = self::all();
        $rows[] = self::normalize($new);
        self::save($rows);
        return (string)$new['id'];
    }

    /* ==================== fixed80: اسکن خودکار هندلرهای ربات ==================== */

    /** مسیر فایل‌هایی که هندلر دکمه‌ها در آن‌ها تعریف می‌شود */
    public static function scanFiles(): array
    {
        return [APP_ROOT . '/app/Bot/Bot.php'];
    }

    /** امضای فایل‌ها (زمان تغییر + اندازه) برای تشخیص آپدیت کد */
    public static function scanSig(): string
    {
        $sig = [];
        foreach (self::scanFiles() as $f) $sig[] = basename($f) . ':' . (int)@filemtime($f) . ':' . (int)@filesize($f);
        return implode('|', $sig);
    }

    /**
     * کلیدهایی که Bot::runButton() واقعاً هندل می‌کند (خواندن مستقیم سورس ربات).
     * نتیجه در تنظیم btn_scan_cache با امضای فایل کش می‌شود تا هر بار فایل بزرگ خوانده نشود.
     */
    public static function scanHandlers(bool $fresh = false): array
    {
        static $mem = null;
        if ($mem !== null && !$fresh) return $mem;
        $sig   = self::scanSig();
        $cache = jdec((string)DB::setting('btn_scan_cache', ''), []);
        if (!$fresh && is_array($cache) && (string)($cache['sig'] ?? '') === $sig && is_array($cache['keys'] ?? null)) {
            $mem = array_values(array_map('strval', $cache['keys']));
            return $mem;
        }
        $keys = [];
        foreach (self::scanFiles() as $f) {
            if (!is_file($f)) continue;
            $src = (string)@file_get_contents($f);
            $pos = strpos($src, 'function runButton(');
            if ($pos === false) continue;
            $body = substr($src, $pos);
            $sw   = strpos($body, 'switch ((string)($b[\'key\']');
            if ($sw !== false) $body = substr($body, $sw);
            $ends = [];
            foreach (['public static function', 'private static function', 'protected static function', 'public function', 'private function'] as $kw) {
                $e = strpos($body, $kw, 20);
                if ($e !== false) $ends[] = $e;
            }
            if ($ends !== []) $body = substr($body, 0, min($ends));
            if (preg_match_all('/case\s+\'([a-z0-9_]+)\'\s*:/', $body, $mm)) {
                foreach ($mm[1] as $k) $keys[$k] = true;
            }
        }
        $mem = array_keys($keys);
        DB::setSetting('btn_scan_cache', jenc(['sig' => $sig, 'keys' => $mem, 'at' => now()]));
        return $mem;
    }

    /** کلیدهای کشف‌شده (خارج از BUILTIN) با متادیتای قابل ویرایش: key => [icon, label, audience, group] */
    public static function discovered(): array
    {
        $d = jdec((string)DB::setting('btn_discovered', ''), []);
        if (!is_array($d)) return [];
        $out = [];
        foreach ($d as $k => $m) {
            $k = (string)$k;
            if ($k === '' || !is_array($m) || isset(self::BUILTIN[$k])) continue;
            $out[$k] = [(string)($m[0] ?? '🔘'), (string)($m[1] ?? $k), (string)($m[2] ?? 'all'), (string)($m[3] ?? 'کشف‌شده')];
        }
        return $out;
    }

    /** همهٔ دکمه‌های درون‌ساخت: ثابت‌ها + کشف‌شده‌ها */
    private static $registryDirty = false;
    private static $registryMem = null;

    public static function builtinAll(): array
    {
        if (self::$registryMem !== null && !self::$registryDirty) return self::$registryMem;
        self::$registryMem   = self::BUILTIN + self::discovered();
        self::$registryDirty = false;
        return self::$registryMem;
    }

    /** متادیتای یک کلید درون‌ساخت (null = ناشناخته) */
    public static function meta(string $key): ?array
    {
        if ($key === '') return null;
        if (isset(self::BUILTIN[$key])) return self::BUILTIN[$key];
        $all = self::builtinAll();
        return isset($all[$key]) ? (array)$all[$key] : null;
    }

    /** برچسب پیشنهادی برای کلید تازه (products_new → Products new) */
    private static function guessMeta(string $key): array
    {
        $aud = 'all';
        if (strncmp($key, 'admin', 5) === 0) $aud = 'admin';
        elseif (strncmp($key, 'rs', 2) === 0 || strncmp($key, 'reseller', 8) === 0) $aud = 'reseller';
        $label = ucwords(str_replace('_', ' ', $key));
        return ['🔘', $label, $aud, 'کشف‌شده'];
    }

    /**
     * گزارش اسکن: هندلرهای موجود در کد، کلیدهای تازه (بدون دکمه)، دکمه‌های بی‌هندلر، هندلرهای خارج از فهرست
     */
    public static function scanReport(bool $fresh = false): array
    {
        $handlers = self::scanHandlers($fresh);
        $all      = self::builtinAll();
        $rows     = self::all();
        $inList   = [];
        foreach ($rows as $r) {
            if ((string)($r['kind'] ?? '') === 'builtin') $inList[(string)($r['key'] ?? '')] = $r;
        }
        $new = []; $dead = []; $notInList = []; $matched = 0;
        foreach ($handlers as $k) {
            if (!isset($all[$k])) { $new[] = $k; continue; }
            $matched++;
            if (!isset($inList[$k])) $notInList[] = $k;
        }
        foreach ($all as $k => $m) {
            if ($handlers !== [] && !in_array((string)$k, $handlers, true)) $dead[] = (string)$k;
        }
        $cache = jdec((string)DB::setting('btn_scan_cache', ''), []);
        return [
            'handlers'    => $handlers,
            'matched'     => $matched,
            'new'         => $new,
            'dead'        => $dead,
            'not_in_list' => $notInList,
            'discovered'  => self::discovered(),
            'scanned_at'  => (string)(is_array($cache) ? ($cache['at'] ?? '') : ''),
            'sig'         => self::scanSig(),
            'auto'        => (int)DB::setting('btn_autoscan', 1) === 1,
            'last_auto'   => (string)DB::setting('btn_autoscan_last', ''),
            'files'       => array_map('basename', self::scanFiles()),
        ];
    }

    /**
     * اسکن خودکار: کلیدهای تازه را به فهرست کشف‌شده‌ها و سپس (خاموش) به فهرست دکمه‌ها اضافه می‌کند.
     * $force = نادیده گرفتن خاموش بودن اسکن خودکار و امضای کش.
     */
    public static function autoScan(bool $force = false): array
    {
        if (!$force && (int)DB::setting('btn_autoscan', 1) !== 1) return ['ok' => false, 'skipped' => true, 'message' => 'اسکن خودکار خاموش است.'];
        $sig = self::scanSig();
        if (!$force && (string)DB::setting('btn_autoscan_sig', '') === $sig) return ['ok' => true, 'skipped' => true, 'message' => 'کد ربات تغییری نکرده است.'];
        $rep  = self::scanReport(true);
        $disc = self::discovered();
        $added = [];
        foreach ((array)$rep['new'] as $k) {
            $k = (string)$k;
            if ($k === '' || isset($disc[$k])) continue;
            $disc[$k] = self::guessMeta($k);
            $added[]  = $k;
        }
        if ($added !== []) {
            DB::setSetting('btn_discovered', jenc($disc));
            self::resetRegistry();
        }
        $rows = self::syncBuiltins();
        DB::setSetting('btn_autoscan_sig', $sig);
        DB::setSetting('btn_autoscan_last', now());
        $msg = $added !== []
            ? '🔎 ' . count($added) . ' بخش تازه در کد ربات پیدا شد و به فهرست دکمه‌ها (خاموش) اضافه شد: ' . implode(', ', $added)
            : ($rows > 0 ? '➕ ' . $rows . ' دکمه به فهرست اضافه شد (خاموش).' : '✅ اسکن انجام شد؛ همهٔ ' . count((array)$rep['handlers']) . ' بخش ربات دکمه دارند.');
        if ((array)$rep['dead'] !== []) $msg .= ' — بدون هندلر: ' . implode(', ', (array)$rep['dead']);
        return ['ok' => true, 'skipped' => false, 'added' => $added, 'rows' => $rows, 'dead' => (array)$rep['dead'], 'handlers' => count((array)$rep['handlers']), 'message' => $msg];
    }

    /** ویرایش متادیتای یک کلید کشف‌شده (آیکون/برچسب/مخاطب/گروه) */
    public static function setDiscovered(string $key, array $meta): bool
    {
        $key = trim($key);
        if ($key === '' || isset(self::BUILTIN[$key]) || !preg_match('/^[a-z0-9_]+$/', $key)) return false;
        $disc = self::discovered();
        $cur  = $disc[$key] ?? self::guessMeta($key);
        $aud  = (string)($meta[2] ?? $cur[2]);
        $disc[$key] = [
            mb_substr(trim((string)($meta[0] ?? $cur[0])), 0, 8),
            mb_substr(trim((string)($meta[1] ?? $cur[1])), 0, 60) ?: $key,
            isset(self::AUDIENCES[$aud]) ? $aud : 'all',
            mb_substr(trim((string)($meta[3] ?? $cur[3])), 0, 40) ?: 'کشف‌شده',
        ];
        DB::setSetting('btn_discovered', jenc($disc));
        self::resetRegistry();
        return true;
    }

    /** حذف یک کلید کشف‌شده (دکمه‌های متصل هم حذف می‌شوند) */
    public static function forgetDiscovered(string $key): bool
    {
        $disc = self::discovered();
        if (!isset($disc[$key])) return false;
        unset($disc[$key]);
        DB::setSetting('btn_discovered', jenc($disc));
        self::resetRegistry();
        $rows = [];
        foreach (self::all() as $r) {
            if ((string)($r['kind'] ?? '') === 'builtin' && (string)($r['key'] ?? '') === $key) continue;
            $rows[] = $r;
        }
        self::save($rows);
        return true;
    }

    private static function resetRegistry(): void
    {
        self::$registryDirty = true;
    }

    public static function stats(): array
    {
        $all = self::all();
        $on  = 0;
        $cus = 0;
        foreach ($all as $b) {
            if ($b['enabled']) $on++;
            if ($b['kind'] !== 'builtin') $cus++;
        }
        $menus = 0;
        foreach (self::menuCounts() as $mk => $mc) {
            if ($mk !== 'main' && (int)$mc['on'] > 0) $menus++;
        }
        $bad = count(self::health());

        return [
            'total'  => count($all),
            'on'     => $on,
            'off'    => count($all) - $on,
            'custom' => $cus,
            'menus'  => $menus,
            'issues' => $bad,
            'rows'   => count(self::editorRows()),
            'mode'   => self::mode(),
        ];
    }
}
