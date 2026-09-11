<?php
declare(strict_types=1);

/**
 * متون و کیبوردهای ربات
 */
class Kb
{
    /* دکمه‌های منوی اصلی */
    public const PRODUCTS = '🛒 محصولات';
    public const SERVICES = '📦 سرویس‌های من';
    public const WALLET   = '💳 شارژ کیف پول';
    public const ACCOUNT  = '👤 حساب کاربری';
    public const TEST     = '🧪 اکانت تست';
    public const GIFT     = '🎁 کد هدیه';
    public const TUTORIAL = '🎓 آموزش';
    public const SUPPORT  = '🆘 پشتیبانی';
    public const RESELLER = '🏷 نمایندگی';
    public const ADMIN    = '🛠 پنل مدیریت';
    public const CANCEL   = '❌ انصراف';
    public const CUSTOM   = '📐 حجم و زمان دلخواه';

    public static function main(bool $isAdmin = false, bool $isReseller = false, bool $hasCustom = false): array
    {
        /* چیدمان دکمه‌ها از پنل وب */
        if (class_exists('Btn')) {
            try {
                if (Btn::mode() === 'inline') return Tg::removeKb();
                $custom = Btn::replyRows($isAdmin, $isReseller);
                if ($custom !== []) return Tg::rkb($custom);
            } catch (Throwable $e) {
                /* در صورت خطا به کیبورد پیش‌فرض برمی‌گردیم */
            }
        }

        $rows = [
            [['text' => self::PRODUCTS], ['text' => self::SERVICES]],
            [['text' => self::WALLET], ['text' => self::ACCOUNT]],
            [['text' => self::TEST], ['text' => self::GIFT]],
            [['text' => self::TUTORIAL], ['text' => self::SUPPORT]],
        ];
        /* دکمهٔ مستقل «حجم و زمان دلخواه» زیر ردیف اول */
        if ($hasCustom) array_splice($rows, 1, 0, [[['text' => self::CUSTOM]]]);
        if ($isReseller) $rows[] = [['text' => self::RESELLER]];
        if ($isAdmin)    $rows[] = [['text' => self::ADMIN]];
        return Tg::rkb($rows);
    }

    public static function cancel(): array
    {
        return Tg::rkb([[['text' => self::CANCEL]]]);
    }

    public static function isMenuButton(string $text): bool
    {
        if (class_exists('Btn')) {
            try {
                if (Btn::match($text) !== null) return true;
            } catch (Throwable $e) {
            }
        }
        return in_array($text, [
            self::PRODUCTS, self::SERVICES, self::WALLET, self::ACCOUNT,
            self::TEST, self::GIFT, self::TUTORIAL, self::SUPPORT,
            self::RESELLER, self::ADMIN, self::CANCEL, self::CUSTOM,
        ], true);
    }

    /** دکمه بازگشت اینلاین */
    public static function backRow(string $data = 'menu:main'): array
    {
        return [Tg::btn('⬅️ بازگشت', $data)];
    }

    /**
     * ردیف ناوبری استاندارد برای همهٔ بخش‌های شیشه‌ای.
     * همیشه یک «بازگشت» و در صورت نیاز یک «منوی اصلی» می‌دهد تا کاربر هیچ‌وقت گیر نکند.
     */
    public static function navRow(string $back = 'menu:main', bool $home = true): array
    {
        $row = [Tg::btn('⬅️ بازگشت', $back)];
        if ($home && $back !== 'menu:main') $row[] = Tg::btn('🏠 منوی اصلی', 'menu:main');
        return $row;
    }

    public static function platforms(): array
    {
        return [
            'android' => '🤖 اندروید',
            'ios'     => '🍏 آی‌او‌اس',
            'windows' => '💻 ویندوز',
            'mac'     => '🖥 مک',
            'tv'      => '📺 تلویزیون',
            'other'   => '📚 سایر',
        ];
    }

    public static function platformLabel(string $key): string
    {
        return self::platforms()[$key] ?? $key;
    }
}
