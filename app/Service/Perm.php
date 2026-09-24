<?php
declare(strict_types=1);

/**
 * سیستم دسترسی سفارشی مدیران پنل وب
 *
 * هر مدیر یک لیست JSON از کلیدهای دسترسی دارد که در ستون perms ذخیره می‌شود.
 * مدیر با نقش super به همه بخش‌ها دسترسی کامل دارد.
 */
class Perm
{
    /** کلید دسترسی کامل */
    public const ALL = '*';

    /**
     * نقش‌هایی که همیشه دسترسی کامل دارند.
     * نصب‌کننده مدیر اول را با نقش owner می‌سازد، پس owner/root هم باید
     * مثل super شناخته شوند وگرنه مدیر اصلی از پنل قفل می‌شود.
     */
    public const SUPER_ROLES = ['super', 'owner', 'root'];

    /** آیا این نام نقش، نقشِ مدیر کل است؟ */
    public static function roleIsSuper(?string $role): bool
    {
        return in_array(strtolower(trim((string)$role)), self::SUPER_ROLES, true);
    }

    /**
     * نقشه‌ی کامل دسترسی‌ها
     * ساختار: گروه => [عنوان، آیکون، دسترسی‌ها => [کلید => عنوان]]
     */
    public static function map(): array
    {
        return [
            'dashboard' => [
                'title' => 'داشبورد',
                'icon'  => '📊',
                'items' => [
                    'dashboard.view' => 'مشاهده داشبورد و آمار کلی',
                    'dashboard.revenue' => 'مشاهده ارقام درآمد و مالی',
                ],
            ],
            'panels' => [
                'title' => 'سرورها و پنل‌ها',
                'icon'  => '🖧',
                'items' => [
                    'panels.view'   => 'مشاهده لیست پنل‌ها',
                    'panels.create' => 'افزودن پنل جدید',
                    'panels.edit'   => 'ویرایش پنل',
                    'panels.delete' => 'حذف پنل',
                    'panels.health' => 'تست سلامت و اینباندها',
                ],
            ],
            'products' => [
                'title' => 'محصولات',
                'icon'  => '📦',
                'items' => [
                    'products.view'   => 'مشاهده محصولات',
                    'products.create' => 'افزودن محصول',
                    'products.edit'   => 'ویرایش محصول و قیمت',
                    'products.delete' => 'حذف محصول',
                ],
            ],
            'services' => [
                'title' => 'سرویس‌ها',
                'icon'  => '🔑',
                'items' => [
                    'services.view'   => 'مشاهده سرویس‌ها',
                    'services.sync'   => 'هم‌رسانی با پنل',
                    'services.edit'   => 'تمدید، غیرفعال و ویرایش سرویس',
                    'services.delete' => 'حذف سرویس',
                ],
            ],
            'users' => [
                'title' => 'کاربران',
                'icon'  => '👥',
                'items' => [
                    'users.view'    => 'مشاهده لیست و پروفایل کاربران',
                    'users.create'  => 'افزودن کاربر دستی',
                    'users.edit'    => 'ویرایش اطلاعات کاربر',
                    'users.balance' => 'تغییر موجودی کیف پول',
                    'users.ban'     => 'مسدود / رفع مسدودی',
                    'users.message' => 'ارسال پیام خصوصی',
                    'users.delete'  => 'حذف کاربر',
                    'users.export'  => 'خروجی CSV کاربران',
                ],
            ],
            'payments' => [
                'title' => 'مالی و پرداخت‌ها',
                'icon'  => '💳',
                'items' => [
                    'payments.view'    => 'مشاهده تراکنش‌ها',
                    'payments.approve' => 'تایید پرداخت',
                    'payments.reject'  => 'رد پرداخت',
                ],
            ],
            'stock' => [
                'title' => 'انبار ملی',
                'icon'  => '🏪',
                'items' => [
                    'stock.view'     => 'مشاهدهٔ انبار و موجودی',
                    'stock.create'   => 'افزودن دسته و قلم جدید',
                    'stock.edit'     => 'ویرایش دسته و وضعیت اقلام',
                    'stock.delete'   => 'حذف دسته و قلم',
                    'stock.reveal'   => 'دیدن محتوای کامل اقلام',
                    'stock.settings' => 'تنظیمات انبار',
                ],
            ],

            'cards' => [
                'title' => 'احراز کارت بانکی',
                'icon'  => '💳',
                'items' => [
                    'cards.view'     => 'مشاهده کارت‌های ثبت‌شده',
                    'cards.approve'  => 'تایید کارت کاربر',
                    'cards.reject'   => 'رد کارت کاربر',
                    'cards.delete'   => 'حذف کارت',
                    'cards.settings' => 'تنظیمات احراز کارت',
                ],
            ],
            'codes' => [
                'title' => 'کد تخفیف و هدیه',
                'icon'  => '🎟',
                'items' => [
                    'codes.view'   => 'مشاهده کدها',
                    'codes.create' => 'ساخت کد جدید',
                    'codes.delete' => 'حذف کد',
                ],
            ],
            'tickets' => [
                'title' => 'پشتیبانی',
                'icon'  => '🆘',
                'items' => [
                    'tickets.view'  => 'مشاهده تیکت‌ها',
                    'tickets.reply' => 'پاسخ به تیکت',
                    'tickets.close' => 'بستن / باز کردن تیکت',
                    'tickets.delete'=> 'حذف تیکت',
                ],
            ],
            'tutorials' => [
                'title' => 'آموزش‌ها',
                'icon'  => '🎓',
                'items' => [
                    'tutorials.view' => 'مشاهده آموزش‌ها',
                    'tutorials.edit' => 'افزودن و ویرایش آموزش',
                ],
            ],
            'settings' => [
                'title' => 'تنظیمات',
                'icon'  => '⚙️',
                'items' => [
                    'settings.view'      => 'مشاهده تنظیمات',
                    'settings.shop'      => 'تنظیمات فروشگاه و متن‌ها',
                    'settings.payment'   => 'تنظیمات پرداخت و نرخ ارز',
                    'settings.bot'       => 'تنظیمات ربات و وب‌هوک',
                    'settings.logs'      => 'تنظیمات گزارش لاگ',
                    'settings.broadcast' => 'ارسال پیام همگانی',
                ],
            ],
            'subs' => [
                'title' => 'مدیریت ساب',
                'icon'  => '🛰',
                'items' => [
                    'subs.view' => 'مشاهده بخش مدیریت ساب',
                    'subs.edit' => 'تغییر آپشن های ساب و افزودن یا حذف آپشن',
                ],
            ],
            'system' => [
                'title' => 'سیستم',
                'icon'  => '🩺',
                'items' => [
                    'backup.view'    => 'مشاهده پشتیبان‌گیری',
                    'backup.run'     => 'ایجاد / بازگردانی بکآپ',
                    'update.view'    => 'مشاهده به‌روزرسانی',
                    'update.run'     => 'اجرای به‌روزرسانی',
                    'health.view'    => 'مشاهده سلامت سیستم',
                    'audit.view'     => 'مشاهده لاگ اقدامات مدیران',
                    'export.view'    => 'خروجی CSV (کاربران، سرویس‌ها، پرداخت‌ها)', /* fixed79 */
                ],
            ],
            'resellers' => [
                'title' => 'نمایندگی',
                'icon'  => '🏷',
                'items' => [
                    'resellers.view'   => 'مشاهده نمایندگان و درخواست‌ها',
                    'resellers.level'  => 'ثبت نماینده و تعیین سطح',
                    'resellers.credit' => 'تعیین سقف بدهی و تخفیف',
                    'resellers.price'  => 'تعرفه و تنظیمات درخواست',
                ],
            ],
            'botui' => [
                'title' => 'شخصی‌سازی ربات',
                'icon'  => '🎛',
                'items' => [
                    'gateways.view'   => 'مشاهده درگاه‌های پرداخت',
                    'gateways.edit'   => 'افزودن و ویرایش درگاه پرداخت',
                    'botbuttons.view' => 'مشاهده دکمه‌های ربات',
                    'botbuttons.edit' => 'افزودن، حذف و رنگ‌بندی دکمه‌ها',
                    'bottexts.view'   => 'مشاهده متن‌های ربات',
                    'bottexts.edit'   => 'ویرایش متن‌های ربات',
                ],
            ],
            'admins' => [
                'title' => 'مدیران پنل',
                'icon'  => '🛡️',
                'items' => [
                    'admins.view'   => 'مشاهده لیست مدیران',
                    'admins.create' => 'افزودن مدیر جدید',
                    'admins.edit'   => 'ویرایش مدیر و دسترسی‌ها',
                    'admins.delete' => 'حذف مدیر',
                ],
            ],
        ];
    }

    /** لیست تخت همه کلیدهای دسترسی */
    public static function allKeys(): array
    {
        $out = [];
        foreach (self::map() as $g) {
            foreach (array_keys((array)$g['items']) as $k) $out[] = $k;
        }
        return $out;
    }

    /** عنوان فارسی یک کلید دسترسی */
    public static function label(string $key): string
    {
        foreach (self::map() as $g) {
            if (isset($g['items'][$key])) return (string)$g['items'][$key];
        }
        return $key;
    }

    /**
     * قالب‌های آماده‌ی نقش برای انتخاب سریع
     */
    public static function presets(): array
    {
        return [
            'super' => [
                'title' => '👑 مدیر کل (دسترسی کامل)',
                'desc'  => 'به همه بخش‌ها بدون محدودیت دسترسی دارد',
                'perms' => [self::ALL],
            ],
            'manager' => [
                'title' => '🧑‍💼 مدیر فروش',
                'desc'  => 'محصول، سرویس، کاربر و مالی – بدون تنظیمات سیستم',
                'perms' => [
                    'dashboard.view', 'dashboard.revenue',
                    'panels.view',
                    'products.view', 'products.create', 'products.edit',
                    'services.view', 'services.sync', 'services.edit',
                    'users.view', 'users.create', 'users.edit', 'users.balance', 'users.ban', 'users.message',
                    'payments.view', 'payments.approve', 'payments.reject',
                    'codes.view', 'codes.create', 'codes.delete',
                    'tickets.view', 'tickets.reply', 'tickets.close',
                    'tutorials.view',
                    'resellers.view', 'resellers.level', 'resellers.credit', 'resellers.price',
                    'gateways.view', 'gateways.edit',
                    'stock.view', 'stock.create', 'stock.edit', 'stock.delete',
                    'botbuttons.view', 'bottexts.view',
                ],
            ],
            'support' => [
                'title' => '🆘 کارشناس پشتیبانی',
                'desc'  => 'فقط تیکت، کاربران و سرویس‌ها',
                'perms' => [
                    'dashboard.view',
                    'tickets.view', 'tickets.reply', 'tickets.close',
                    'users.view', 'users.message',
                    'services.view', 'services.sync',
                    'tutorials.view',
                ],
            ],
            'finance' => [
                'title' => '💰 مسئول مالی',
                'desc'  => 'تایید پرداخت‌ها و مدیریت کیف پول',
                'perms' => [
                    'dashboard.view', 'dashboard.revenue',
                    'payments.view', 'payments.approve', 'payments.reject',
                    'cards.view', 'cards.approve', 'cards.reject',
                    'users.view', 'users.balance',
                    'codes.view', 'codes.create',
                ],
            ],
            'viewer' => [
                'title' => '👁 فقط مشاهده',
                'desc'  => 'هیچ تغییری نمی‌تواند انجام دهد',
                'perms' => [
                    'dashboard.view',
                    'panels.view', 'products.view', 'services.view',
                    'users.view', 'payments.view', 'codes.view',
                    'tickets.view', 'tutorials.view', 'health.view', 'audit.view',
                ],
            ],
        ];
    }

    /** دسترسی‌های یک مدیر به صورت آرایه */
    public static function of(?array $admin): array
    {
        if (!$admin) return [];
        if (self::roleIsSuper((string)($admin['role'] ?? ''))) return [self::ALL];
        $raw = (string)($admin['perms'] ?? '');
        if (trim($raw) === '') {
            // امن‌سازی (fail-closed): نبودِ perms یعنی «هیچ دسترسی»، نه «دسترسی کامل».
            // مدیر کل با role = super بالاتر بررسی شده است.
            return [];
        }
        $arr = jdec($raw, []);
        return is_array($arr) ? array_values(array_filter(array_map('strval', $arr))) : [];
    }

    /** آیا مدیر داده‌شده دسترسی خاصی دارد؟ */
    public static function has(?array $admin, string $key): bool
    {
        if (!$admin) return false;
        if ((int)($admin['active'] ?? 1) === 0) return false;
        $perms = self::of($admin);
        if (in_array(self::ALL, $perms, true)) return true;
        if (in_array($key, $perms, true)) return true;
        // پشتیبانی از وایلدکارت گروهی مانند users.*
        $grp = explode('.', $key)[0] . '.*';
        return in_array($grp, $perms, true);
    }

    /** آیا حداقل یکی از دسترسی‌ها را دارد؟ */
    public static function any(?array $admin, array $keys): bool
    {
        foreach ($keys as $k) if (self::has($admin, (string)$k)) return true;
        return false;
    }

    /** آیا مدیر ارشد است؟ */
    public static function isSuper(?array $admin): bool
    {
        return $admin !== null && self::roleIsSuper((string)($admin['role'] ?? ''));
    }

    /**
     * کلیدهای دسترسی لازم برای دیدن هر صفحه‌ی پنل
     */
    public static function pageKeys(): array
    {
        return [
            'dashboard' => ['dashboard.view'],
            'panels'    => ['panels.view'],
            'stock'     => ['stock.view'],
            'products'  => ['products.view'],
            'services'  => ['services.view'],
            'users'     => ['users.view'],
            'payments'  => ['payments.view'],
        'reports'   => ['payments.view'], /* 0.0.2 #24 */
            'codes'     => ['codes.view'],
            'tickets'   => ['tickets.view'],
            'tutorials' => ['tutorials.view'],
            'settings'  => ['settings.view', 'settings.shop', 'settings.payment', 'settings.bot', 'settings.logs', 'settings.broadcast'],
            'backup'    => ['backup.view'],
            'update'    => ['update.view'],
            'health'    => ['health.view'],
            'audit'     => ['audit.view'],
            'export'    => ['export.view'], /* fixed79 */
            'resellers'  => ['resellers.view'],
            'gateways'   => ['gateways.view'],
            'cards'      => ['cards.view'],
            'botbuttons' => ['botbuttons.view'],
            'bottexts'   => ['bottexts.view'],
            'join'       => ['settings.view', 'settings.bot'],
            'subs'      => ['subs.view', 'subs.edit'],
            'admins'    => ['admins.view'],
        ];
    }

    /** آیا مدیر می‌تواند صفحه‌ی داده‌شده را ببیند؟ */
    public static function canPage(?array $admin, string $page): bool
    {
        $keys = self::pageKeys()[$page] ?? [];
        if (!$keys) return self::isSuper($admin);
        return self::any($admin, $keys);
    }

    /** اولین صفحه‌ای که مدیر به آن دسترسی دارد */
    public static function firstPage(?array $admin, array $order): string
    {
        foreach ($order as $p) if (self::canPage($admin, (string)$p)) return (string)$p;
        return '';
    }

    /** پاک‌سازی لیست دسترسی ورودی و تبدیل به JSON برای ذخیره */
    public static function encode(array $keys): string
    {
        if (in_array(self::ALL, $keys, true)) return jenc([self::ALL]);
        $valid = self::allKeys();
        $clean = array_values(array_unique(array_filter($keys, static fn($k) => in_array((string)$k, $valid, true))));
        return jenc($clean);
    }

    /** خلاصه‌ی متنی دسترسی‌ها برای نمایش در جدول */
    public static function summary(?array $admin): string
    {
        $p = self::of($admin);
        if (in_array(self::ALL, $p, true)) return 'دسترسی کامل';
        if (!$p) return 'بدون دسترسی';
        $groups = [];
        foreach (self::map() as $gk => $g) {
            $n = 0;
            foreach (array_keys((array)$g['items']) as $k) if (in_array($k, $p, true)) $n++;
            if ($n > 0) $groups[] = $g['title'] . ' (' . fa_num($n) . ')';
        }
        return $groups ? implode('، ', $groups) : fa_num(count($p)) . ' دسترسی';
    }
}
