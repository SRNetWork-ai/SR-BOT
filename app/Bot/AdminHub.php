<?php
declare(strict_types=1);

/**
 * پنل مدیریت درون ربات — بخش‌های دسته‌بندی‌شده (fixed73)
 *
 * این کلاس مکمل AdminBot است و قابلیت‌های ادمینی را که نیاز به پنل وب ندارند، داخل ربات می‌آورد:
 *  - مالی (تراکنش‌ها، کدهای تخفیف/هدیه، ساخت سریع کد)
 *  - سرویس‌ها (فهرست‌های هوشمند، اقدامات روی هر سرویس: افزودن روز/گیگ، ریست، فعال/غیرفعال، حذف، لینک، اتصال و دستگاه‌ها)
 *  - سرورها (کارت هر سرور، وضعیت منابع، اینباندها/گروه‌ها، حذف تمام‌شده‌های پنل)
 *  - ارتباط (پیام به گروه‌های کاربری، تیکت‌ها)
 *  - ابزار و نگهداری (سلامت سیستم، همگام‌سازی همه، حذف تمام‌شده‌ها، سطل زباله، حالت تعمیر، لاگ‌ها)
 *  - گزارش‌های تکمیلی (فروش ۷ روز، پرفروش‌ها، آمار سرورها)
 *
 * همهٔ callbackها با پیشوند adm: از AdminBot::callback به اینجا می‌رسند؛ اگر کلید شناخته‌شده نباشد false برمی‌گردد.
 */
class AdminHub
{
    private const PER = 8;

    /** گروه‌های سرویس: کلید => [آیکون، عنوان، شرط SQL (با نام مستعار s)، ترتیب] */
    public const SSEG = [
        'new'     => ['🆕', 'آخرین سرویس‌ها',        "s.status <> 'deleted'",                                   's.id DESC'],
        'act'     => ['🟢', 'فعال',                  "s.status = 'active'",                                     's.id DESC'],
        'exp'     => ['⏳', 'رو به انقضا (۳ روز)',    "s.status = 'active' AND s.expire_at IS NOT NULL AND s.expire_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)", 's.expire_at ASC'],
        'expired' => ['🔴', 'منقضی‌شده',              "s.status = 'expired'",                                    's.expire_at DESC, s.id DESC'],
        'dis'     => ['⏸', 'غیرفعال',                "s.status = 'disabled'",                                   's.id DESC'],
        'miss'    => ['❔', 'ناموجود در پنل',          "s.status = 'missing'",                                    's.id DESC'],
        'test'    => ['🧪', 'اکانت‌های تست',           "s.is_test = 1 AND s.status <> 'deleted'",                 's.id DESC'],
        'rs'      => ['🏷', 'ساخت نمایندگان',          "s.is_reseller = 1 AND s.status <> 'deleted'",             's.id DESC'],
        'trash'   => ['🗑', 'سطل زباله',              "s.status = 'deleted'",                                    's.id DESC'],
    ];

    /** گروه‌های اضافی برای پیام گروهی (مالکان سرویس‌ها) */
    public const BSEG = [
        'expsoon' => ['⏳', 'مالکان سرویس رو به انقضا', "id IN (SELECT user_id FROM {p}services WHERE status = 'active' AND expire_at IS NOT NULL AND expire_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY))"],
        'expired' => ['🔴', 'مالکان سرویس منقضی',      "id IN (SELECT user_id FROM {p}services WHERE status = 'expired')"],
        'active'  => ['🟢', 'مالکان سرویس فعال',        "id IN (SELECT user_id FROM {p}services WHERE status = 'active')"],
    ];

    /** تنظیمات عددی سریع: کلید => [نام تنظیم، برچسب، حداقل، حداکثر، پیش‌فرض] */
    public const NUMS = [
        'adld'  => ['svc_autodel_days',       '🧹 حذف خودکار پس از (روز)',        1,  60,         3],
        'trd'   => ['trash_days',             '🗑 مهلت سطل زباله (روز)',          1,  90,         7],
        'expn'  => ['expire_notify_days',     '⏰ یادآوری انقضا (روز قبل)',       1,  30,         3],
        'trfn'  => ['traffic_notify_percent', '📉 هشدار حجم (درصد مصرف)',       50, 99,         85],
        'cmin'  => ['cus_min_gb',             '📐 دلخواه: حداقل گیگ',             1,  100000,     5],
        'cmax'  => ['cus_max_gb',             '📐 دلخواه: حداکثر گیگ',            1,  100000,     100],
        'dmin'  => ['cus_min_days',           '📐 دلخواه: حداقل روز',             1,  3650,       7],
        'dmax'  => ['cus_max_days',           '📐 دلخواه: حداکثر روز',            1,  3650,       90],
        'cpg'   => ['cus_price_gb',           '💰 دلخواه: قیمت هر گیگ (پیش‌فرض)', 0,  1000000000, 0],
        'cpd'   => ['cus_price_day',          '💰 دلخواه: قیمت هر روز (پیش‌فرض)', 0,  1000000000, 0],
        'fjc'   => ['fj_cache_min',           '🔒 کش جوین اجباری (دقیقه)',        0,  1440,       3],
        'stuck' => ['order_stuck_minutes',    '🧷 مهلت سفارش گیرکرده (دقیقه)',    2,  1440,       10],
        /* fixed74 */
        'arnh'  => ['arn_hours',              '🔁 تمدید خودکار: چند ساعت قبل از انقضا', 1, 720,     24],
        'arnt'  => ['arn_traffic',            '🔁 تمدید خودکار: درصد مصرف حجم (۰=خاموش)', 0, 100,   95],
        /* fixed75: ضدسوءاستفادهٔ اکانت تست */
        'tcd'   => ['test_cooldown_days',     '🧪 فاصلهٔ دو تست (روز، ۰=بدون محدودیت)', 0, 365, 0],
        'tage'  => ['test_min_age_hours',     '🧪 حداقل عمر حساب برای تست (ساعت، ۰=خاموش)', 0, 720, 0],
        'tmax'  => ['test_global_max',        '🧪 سقف کل تست هر کاربر (همهٔ سرورها، ۰=بی‌نهایت)', 0, 100, 0],
        'expn2' => ['expire_notify_days2',    '🚨 یادآوری مرحلهٔ ۲ انقضا (روز قبل، ۰=خاموش)', 0, 30, 1],
    ];

    /* ============================================================ مسیریابی */

    public static function callback($chatId, $msgId, $cbId, string $arg, string $arg2): bool
    {
        try {
            switch ($arg) {
                /* ---- تنظیمات عددی ---- */
                case 'num': {
                    Tg::answerCb($cbId);
                    if (!isset(self::NUMS[$arg2])) { AdminBot::quickSettings($chatId, $msgId); return true; }
                    $nm  = self::NUMS[$arg2];
                    $cur = (string)DB::setting($nm[0], (string)$nm[4]);
                    self::setState('admin_num_set', ['k' => $arg2]);
                    Tg::send($chatId, '🔢 <b>' . h($nm[1]) . '</b>' . "\n" . self::sep()
                        . 'مقدار فع��ی: <b>' . fa_num($cur) . '</b>' . "\n"
                        . 'محدودهٔ مجاز: ' . fa_num((string)$nm[2]) . ' تا ' . fa_num((string)$nm[3]) . "\n\n"
                        . 'مقدار جدید را به عدد بفرستید:', Kb::cancel());
                    return true;
                }

                /* ---- مالی ---- */
                case 'fin':     Tg::answerCb($cbId); self::finHub($chatId, $msgId); return true;
                case 'txs':     Tg::answerCb($cbId); self::txList($chatId, $msgId, max(1, (int)$arg2)); return true;
                case 'codes':   Tg::answerCb($cbId); self::codesList($chatId, $msgId, 'discount', max(1, (int)$arg2)); return true;
                case 'gifts':   Tg::answerCb($cbId); self::codesList($chatId, $msgId, 'gift', max(1, (int)$arg2)); return true;
                case 'dctg':    self::codeToggle($chatId, $msgId, $cbId, 'discount', $arg2); return true;
                case 'gftg':    self::codeToggle($chatId, $msgId, $cbId, 'gift', $arg2); return true;
                case 'dcnew':
                    Tg::answerCb($cbId);
                    self::setState('admin_dc_new');
                    Tg::send($chatId, "🎟 <b>ساخت سریع کد تخفیف</b>\n" . self::sep()
                        . "در یک خط بفرستید:  <code>کد  مقدار  تعداد  [روز اعتبار]</code>\n\n"
                        . "• مقدار درصدی با ٪ مثل <code>20%</code> و مبلغ ثابت بدون علامت مثل <code>50000</code>\n"
                        . "• تعداد <code>0</code> = نامحدود · روز خالی = بدون تاریخ انقضا\n"
                        . "• به‌جای کد، <code>-</code> بنویسید تا کد تصادفی ساخته شود\n\n"
                        . "مثال: <code>OFF20 20% 100 30</code>\nمثال: <code>- 50000 10</code>", Kb::cancel());
                    return true;
                case 'gfnew':
                    Tg::answerCb($cbId);
                    self::setState('admin_gf_new');
                    Tg::send($chatId, "🎁 <b>ساخت سریع کد هدیه (شارژ کیف پول)</b>\n" . self::sep()
                        . "در یک خط بفرستید:  <code>کد  مبلغ  تعداد  [روز اعتبار]</code>\n\n"
                        . "• به‌جای کد، <code>-</code> بنویسید تا کد تصادفی ساخته شود\n"
                        . "• تعداد خالی = ۱ بار مصرف\n\n"
                        . "مثال: <code>- 100000 1 7</code>", Kb::cancel());
                    return true;

                /* ---- سرویس‌ها ---- */
                case 'svcs':    Tg::answerCb($cbId); self::svcHub($chatId, $msgId); return true;
                case 'sl': {
                    Tg::answerCb($cbId);
                    [$seg, $page] = array_pad(explode(':', $arg2, 2), 2, '1');
                    self::svcList($chatId, $msgId, (string)$seg, max(1, (int)$page));
                    return true;
                }
                case 'syncall':   self::syncAll($chatId, $msgId, $cbId); return true;
                case 'autodel':   Tg::answerCb($cbId); self::autodelAsk($chatId, $msgId); return true;
                case 'autodelok': self::autodelRun($chatId, $msgId, $cbId); return true;
                case 'purge':     Tg::answerCb($cbId); self::purgeAsk($chatId, $msgId); return true;
                case 'purgeok':   self::purgeRun($chatId, $msgId, $cbId); return true;

                case 'sadd': case 'sreset': case 'stog': case 'sdel': case 'sdelok': case 'slink':
                case 'sonl': case 'sips': case 'srevoke': case 'smsg':
                    self::svcAction($chatId, $msgId, $cbId, $arg, (int)$arg2);
                    return true;

                /* ---- سرورها ---- */
                case 'p':      Tg::answerCb($cbId); self::panelCard($chatId, $msgId, (int)$arg2); return true;
                case 'ptog':   self::panelToggle($chatId, $msgId, $cbId, (int)$arg2); return true;
                case 'pstat':  Tg::answerCb($cbId, '⏳ دریافت وضعیت سرور...'); self::panelStatus($chatId, $msgId, (int)$arg2); return true;
                case 'pinb':   Tg::answerCb($cbId); self::panelInbounds($chatId, $msgId, (int)$arg2); return true;
                case 'pdep':   Tg::answerCb($cbId); self::panelDepletedAsk($chatId, $msgId, (int)$arg2); return true;
                case 'pdepok': self::panelDepletedRun($chatId, $msgId, $cbId, (int)$arg2); return true;

                /* ---- ارتباط ---- */
                case 'comm':   Tg::answerCb($cbId); self::commHub($chatId, $msgId); return true;
                case 'bcseg':  Tg::answerCb($cbId); self::bcSeg($chatId, $msgId, $arg2); return true;

                /* ---- ابزار ---- */
                case 'tools':  Tg::answerCb($cbId); self::toolsHub($chatId, $msgId); return true;
                case 'health': Tg::answerCb($cbId, '⏳ بررسی سلامت...'); self::health($chatId, $msgId); return true;
                case 'mnt':    self::maintenanceToggle($chatId, $msgId, $cbId); return true;
                case 'logs':   Tg::answerCb($cbId); self::logs($chatId, $msgId); return true;
                case 'stuck':  self::stuckOrders($chatId, $msgId, $cbId); return true;

                /* ---- fixed75: لاگ اقدامات مدیران + ضدسوءاستفادهٔ اکانت تست ---- */
                case 'audit':  Tg::answerCb($cbId); self::auditHub($chatId, $msgId, (int)$arg2); return true;
                case 'test':   Tg::answerCb($cbId); self::testHub($chatId, $msgId); return true;
                case 'tsttog': {
                    $allowed = ['test_enabled' => '1', 'test_panel_check' => '1', 'test_phone_unique' => '1', 'test_log_block' => '1'];
                    if (!isset($allowed[$arg2])) { Tg::answerCb($cbId); return true; }
                    $cur = (string)DB::setting($arg2, $allowed[$arg2]) === '1';
                    DB::setSetting($arg2, $cur ? '0' : '1');
                    if (class_exists('Audit')) Audit::log('setting.toggle', ['key' => $arg2, 'value' => $cur ? '0' : '1']);
                    Tg::answerCb($cbId, $cur ? 'خاموش شد' : 'روشن شد');
                    self::testHub($chatId, $msgId);
                    return true;
                }

                /* ---- رشد و فروش (fixed74): کمپین، هدیهٔ گروهی، تمدید خودکار، معرفی، CSV ---- */
                case 'grow':    Tg::answerCb($cbId); self::growHub($chatId, $msgId); return true;
                case 'camp':    Tg::answerCb($cbId); self::campHub($chatId, $msgId); return true;
                case 'campnew': {
                    Tg::answerCb($cbId);
                    self::setState('admin_camp_new');
                    Tg::send($chatId, '🔥 <b>کمپین تخفیف جدید</b>' . "\n" . self::sep()
                        . "فرمت: <code>درصد | ساعت | عنوان | سقف تومان | محصولات</code>\n"
                        . "• ساعت = مدت کمپین (۰ = بدون پایان)\n"
                        . "• سقف و محصولات اختیاری‌اند (محصولات: شناسه‌ها با کاما، خالی = همه)\n"
                        . "• اگر انتهای متن <code>+تمدید</code> بنویسید، روی تمدید هم اعمال می‌شود\n\n"
                        . "مثال‌ها:\n<code>30 | 48 | جشنوارهٔ پاییز</code>\n<code>20 | 72 | تخفیف ویژه | 50000 | 3,7 +تمدید</code>", Kb::cancel());
                    return true;
                }
                case 'campstop': {
                    Campaign::stop();
                    if (class_exists('Audit')) Audit::log('campaign.stop');
                    Tg::answerCb($cbId, 'کمپین متوقف شد.');
                    self::campHub($chatId, $msgId);
                    return true;
                }
                case 'camprn': {
                    DB::setSetting('camp_renew', (string)DB::setting('camp_renew', '0') === '1' ? '0' : '1');
                    Tg::answerCb($cbId);
                    self::campHub($chatId, $msgId);
                    return true;
                }
                case 'bulk':    Tg::answerCb($cbId); self::bulkHub($chatId, $msgId); return true;
                case 'bulkseg': {
                    Tg::answerCb($cbId);
                    $seg = $arg2 !== '' ? $arg2 : 'act';
                    $n   = Bulk::count($seg);
                    self::setState('admin_bulk_add', ['seg' => $seg]);
                    Tg::send($chatId, '🎁 <b>هدیهٔ گروهی — ' . h(Bulk::label($seg)) . '</b>' . "\n" . self::sep()
                        . 'تعداد سرویس‌های این دسته: <b>' . fa_num($n) . "</b>\n\n"
                        . "فرمت: <code>روز | گیگ | پیام اختیاری</code>\n"
                        . "مثال: <code>3 | 5</code> (۳ روز و ۵ گیگ)\n<code>7 | 0 | به مناسبت عید</code> (فقط ۷ روز)\n<code>0 | 10</code> (فقط ۱۰ گیگ)\n\n"
                        . "برای اعمال بدون اطلاع به کاربران، انتهای متن <code>-بی‌صدا</code> بنویسید.", Kb::cancel());
                    return true;
                }
                case 'bulkok': {
                    $d = jdec(Bot::$u['state_data'] ?? null, []);
                    if ((string)(Bot::$u['state'] ?? '') !== 'admin_bulk_confirm' || empty($d['seg'])) {
                        Tg::answerCb($cbId, 'درخواست منقضی شده؛ دوباره شروع کنید.', true);
                        self::bulkHub($chatId, $msgId);
                        return true;
                    }
                    self::clearState(Bot::$u);
                    Tg::answerCb($cbId, 'در حال اعمال...');
                    Tg::edit($chatId, $msgId, '⏳ در حال اعمال هدیه روی ' . fa_num((int)($d['n'] ?? 0)) . ' سرویس... هر سرویس روی پنل به‌روزرسانی می‌شود؛ لطفاً صبر کنید.');
                    $rep = Bulk::apply((string)$d['seg'], (int)($d['days'] ?? 0), (float)($d['gb'] ?? 0), (string)($d['note'] ?? ''), !empty($d['notify']));
                    if (class_exists('Audit')) Audit::log('bulk.gift', ['seg' => (string)$d['seg'], 'days' => (int)($d['days'] ?? 0), 'gb' => (float)($d['gb'] ?? 0), 'total' => (int)($rep['total'] ?? 0), 'ok' => (int)($rep['ok'] ?? 0)]);
                    Tg::send($chatId, '🎁 <b>نتیجهٔ هدیهٔ گروهی</b>' . "\n" . self::sep()
                        . '📂 دسته: ' . h(Bulk::label((string)$d['seg'])) . "\n"
                        . '✅ موفق: <b>' . fa_num((int)$rep['ok']) . '</b> از ' . fa_num((int)$rep['total']) . "\n"
                        . '❌ خطای پنل: ' . fa_num((int)$rep['panel_fail']) . "\n"
                        . '⏭ بدون تغییر (نامحدود): ' . fa_num((int)$rep['skipped']) . "\n"
                        . '🔄 فعال‌شدهٔ مجدد: ' . fa_num((int)$rep['reactivated']) . "\n"
                        . '📨 اطلاع داده‌شده: ' . fa_num((int)$rep['notified'])
                        . (!empty($rep['message']) ? "\n⚠️ " . h((string)$rep['message']) : ''),
                        Tg::ikb([[Tg::btn('🎁 هدیهٔ دیگر', 'adm:bulk')], self::nav()]));
                    return true;
                }
                case 'bulkno': {
                    self::clearState(Bot::$u);
                    Tg::answerCb($cbId, 'لغو شد.');
                    self::bulkHub($chatId, $msgId);
                    return true;
                }
                case 'arn':     Tg::answerCb($cbId); self::arnHub($chatId, $msgId); return true;
                case 'arntog': {
                    $turnOn = !AutoRenew::enabled();
                    if ($turnOn && !AutoRenew::hasColumn()) {
                        Tg::answerCb($cbId, 'ستون auto_renew ساخته نشد؛ دسترسی دیتابیس را بررسی کنید.', true);
                        return true;
                    }
                    DB::setSetting('arn_enabled', $turnOn ? '1' : '0');
                    if (class_exists('Audit')) Audit::log('autorenew.toggle', ['value' => $turnOn ? '1' : '0']);
                    Tg::answerCb($cbId, $turnOn ? 'تمدید خودکار روشن شد.' : 'تمدید خودکار خاموش شد.');
                    self::arnHub($chatId, $msgId);
                    return true;
                }
                case 'arnrun': {
                    Tg::answerCb($cbId, 'در حال اجرا...');
                    $r = AutoRenew::run(30);
                    if (class_exists('Audit')) Audit::log('autorenew.run', ['checked' => (int)$r['checked'], 'renewed' => (int)($r['renewed'] ?? 0)]);
                    Tg::send($chatId, '🔁 <b>اجرای دستی تمدید خودکار</b>' . "\n" . self::sep()
                        . '🔎 بررسی‌شده: ' . fa_num((int)$r['checked']) . "\n"
                        . '✅ تمدیدشده: ' . fa_num((int)$r['renewed']) . "\n"
                        . '👛 موجودی ناکافی: ' . fa_num((int)$r['nofunds']) . "\n"
                        . '❌ خطا: ' . fa_num((int)$r['failed']),
                        Tg::ikb([self::nav([Tg::btn('🔁 بازگشت', 'adm:arn')])]));
                    return true;
                }
                case 'ref':     Tg::answerCb($cbId); self::refHub($chatId, $msgId); return true;
                case 'reftog': {
                    DB::setSetting('referral_enabled', Referral::enabled() ? '0' : '1');
                    Tg::answerCb($cbId);
                    self::refHub($chatId, $msgId);
                    return true;
                }
                case 'csv': {
                    if ($arg2 === '') { Tg::answerCb($cbId); self::csvHub($chatId, $msgId); return true; }
                    [$kind, $days] = array_pad(explode(':', $arg2, 2), 2, '0');
                    if (!isset(Export::kinds()[$kind])) { Tg::answerCb($cbId, 'نوع خروجی نامعتبر است.', true); return true; }
                    Tg::answerCb($cbId, 'در حال ساخت فایل...');
                    $f = Export::file($kind, ['days' => (int)$days]);
                    if (class_exists('Audit')) Audit::log('export.csv', ['kind' => $kind, 'days' => (int)$days, 'rows' => (int)($f['rows'] ?? 0)]);
                    if (empty($f['ok'])) { Tg::send($chatId, '❌ ' . h((string)($f['message'] ?? 'خطا در ساخت فایل'))); return true; }
                    $cap = '📤 <b>' . h(Export::label($kind)) . '</b>' . "\n"
                         . '📄 ردیف‌ها: ' . fa_num((int)$f['rows']) . ((int)$days > 0 ? ' | بازه: ' . fa_num((int)$days) . ' روز اخیر' : ' | همهٔ رکوردها') . "\n"
                         . '💾 حجم: ' . human_bytes((int)$f['size']) . "\n"
                         . '🕒 ' . to_jalali(now(), true);
                    $r = Tg::document($chatId, (string)$f['path'], $cap);
                    @unlink((string)$f['path']);
                    if (empty($r['ok'])) Tg::send($chatId, '❌ ارسال فایل ناموفق بود: ' . h((string)($r['description'] ?? 'خطای تلگرام')));
                    return true;
                }

                /* ---- گزارش‌ها ---- */
                case 'st7':    Tg::answerCb($cbId); self::sales7($chatId, $msgId); return true;
                case 'sttop':  Tg::answerCb($cbId); self::topProducts($chatId, $msgId); return true;
                case 'stpnl':  Tg::answerCb($cbId); self::panelStats($chatId, $msgId); return true;
            }
        } catch (\Throwable $e) {
            app_log('admin', 'hub ' . $arg . ': ' . $e->getMessage());
            Tg::answerCb($cbId, '❌ خطا: ' . mb_substr($e->getMessage(), 0, 150), true);
            return true;
        }
        return false;
    }

    /* ============================================================ کمکی */

    private static function sep(): string
    {
        return '<code>─────────────────</code>' . "\n";
    }

    private static function out($chatId, $msgId, string $t, array $rows): void
    {
        $kb = Tg::ikb($rows);
        $msgId ? Tg::edit($chatId, $msgId, $t, $kb) : Tg::send($chatId, $t, $kb);
    }

    private static function setState(?string $state, array $data = []): void
    {
        DB::update('users', ['state' => $state, 'state_data' => $data ? jenc($data) : null],
            'id = :id', [':id' => (int)Bot::$u['id']]);
    }

    private static function clearState(array $user): void
    {
        DB::update('users', ['state' => null, 'state_data' => null], 'id = :id', [':id' => (int)$user['id']]);
    }

    private static function nav(array $extra = []): array
    {
        return array_merge($extra, [Tg::btn('🏠 خانه', 'adm:home')]);
    }

    private static function statusIcon(array $s): string
    {
        $st = (string)($s['status'] ?? '');
        $ic = $st === 'active' ? '🟢' : ($st === 'expired' ? '��' : ($st === 'disabled' ? '⏸' : ($st === 'deleted' ? '🗑' : ($st === 'missing' ? '❔' : '⚪️'))));
        if ((int)($s['is_test'] ?? 0) === 1) $ic .= '🧪';
        return $ic;
    }

    private static function statusLabel(string $st): string
    {
        $m = ['active' => 'فعال', 'expired' => 'منقضی', 'disabled' => 'غیرفعال', 'deleted' => 'حذف‌شده', 'missing' => 'ناموجود در پنل'];
        return $m[$st] ?? $st;
    }

    private static function uptime(int $sec): string
    {
        if ($sec <= 0) return '—';
        $d = intdiv($sec, 86400); $h = intdiv($sec % 86400, 3600); $m = intdiv($sec % 3600, 60);
        $o = [];
        if ($d) $o[] = fa_num($d) . ' روز';
        if ($h) $o[] = fa_num($h) . ' ساعت';
        if (!$d && $m) $o[] = fa_num($m) . ' دقیقه';
        return $o ? implode(' و ', $o) : 'کمتر از یک دقیقه';
    }

    private static function pct($a, $b): string
    {
        $a = (float)$a; $b = (float)$b;
        if ($b <= 0) return '—';
        return fa_num((string)round($a / $b * 100)) . '٪';
    }

    private static function jd(?string $dt, bool $time = true): string
    {
        if (!$dt) return '—';
        return fa_num(to_jalali($dt, $time));
    }

    /** پنل و شیء اتصال یک سرویس */
    private static function xuiFor(array $s): array
    {
        $p = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)($s['panel_id'] ?? 0)]);
        if (!$p) return [null, null];
        try { return [new Xui($p), $p]; } catch (\Throwable $e) { return [null, $p]; }
    }

    /** ردیف‌های اقدام روی کارت سرویس (از AdminBot::serviceCard صدا زده می‌شود) */
    public static function svcRows(array $s, ?array $p = null): array
    {
        $sid = (int)$s['id'];
        $st  = (string)$s['status'];
        $x   = null;
        if ($p) { try { $x = new Xui($p); } catch (\Throwable $e) { $x = null; } }

        $rows = [];
        if ($st !== 'deleted') {
            $rows[] = [Tg::btn('♻️ افزودن روز / گیگ', 'adm:sadd:' . $sid), Tg::btn('🔁 ریست ترافیک', 'adm:sreset:' . $sid)];
            $rows[] = [
                Tg::btn($st === 'disabled' ? '▶️ فعال کردن' : '⏸ غیرفعال کردن', 'adm:stog:' . $sid),
                Tg::btn('🗑 حذف سرویس', 'adm:sdel:' . $sid),
            ];
            $rows[] = [Tg::btn('🔗 لینک و کانفیگ‌ها', 'adm:slink:' . $sid), Tg::btn('🕒 اتصال و دستگاه‌ها', 'adm:sonl:' . $sid)];
            $extra = [];
            if ($x && $x->supports('ips'))    $extra[] = Tg::btn('📵 پاک‌کردن IPها', 'adm:sips:' . $sid);
            if ($x && $x->supports('revoke')) $extra[] = Tg::btn('🚫 لغو لینک اشتراک', 'adm:srevoke:' . $sid);
            if ($extra) $rows[] = $extra;
        }
        $rows[] = [Tg::btn('🔄 همگام‌سازی با سرور', 'adm:ssync:' . $sid), Tg::btn('✉️ پیام به مالک', 'adm:smsg:' . $sid)];
        $rows[] = [Tg::btn('👤 پروندهٔ مالک', 'adm:u:' . (int)$s['user_id']), Tg::btn('🖥 کارت سرور', 'adm:p:' . (int)$s['panel_id'])];
        $rows[] = [Tg::btn('🔎 جستجو', 'adm:svc'), Tg::btn('📦 سرویس‌ها', 'adm:svcs'), Tg::btn('🏠 خانه', 'adm:home')];
        return $rows;
    }

    /* ============================================================ مالی */

    private static function finHub($chatId, $msgId): void
    {
        $pendN = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE status = 'pending'", [], 0);
        $pendS = (int)DB::val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE status = 'pending'", [], 0);
        $cards = class_exists('CardAuth') ? CardAuth::pendingCount() : 0;
        $today = (int)DB::val("SELECT COALESCE(SUM(final_amount),0) FROM {p}orders WHERE status = 'paid' AND DATE(created_at) = CURDATE()", [], 0);
        $d7    = (int)DB::val("SELECT COALESCE(SUM(final_amount),0) FROM {p}orders WHERE status = 'paid' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", [], 0);
        $d30   = (int)DB::val("SELECT COALESCE(SUM(final_amount),0) FROM {p}orders WHERE status = 'paid' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", [], 0);
        $all   = (int)DB::val("SELECT COALESCE(SUM(final_amount),0) FROM {p}orders WHERE status = 'paid'", [], 0);
        $wPos  = (int)DB::val('SELECT COALESCE(SUM(balance),0) FROM {p}users WHERE balance > 0', [], 0);
        $wNeg  = (int)DB::val('SELECT COALESCE(SUM(-balance),0) FROM {p}users WHERE balance < 0', [], 0);
        $dcA   = (int)DB::val('SELECT COUNT(*) FROM {p}discount_codes WHERE active = 1', [], 0);
        $gfA   = (int)DB::val('SELECT COUNT(*) FROM {p}gift_codes WHERE active = 1', [], 0);
        $cur   = currency();

        $t  = '💳 <b>مالی</b>' . "\n" . self::sep();
        $t .= '⏳ پرداخت در انتظار: <b>' . fa_num($pendN) . '</b> (' . money($pendS) . ' ' . $cur . ")\n";
        $t .= '🪪 احراز کارت در انتظار: <b>' . fa_num($cards) . '</b>' . "\n" . self::sep();
        $t .= '📈 فروش امروز: <b>' . money($today) . '</b> ' . $cur . "\n";
        $t .= '📆 فروش ۷ روز: ' . money($d7) . ' ' . $cur . "\n";
        $t .= '🗓 فروش ۳۰ روز: ' . money($d30) . ' ' . $cur . "\n";
        $t .= '💵 فروش کل: ' . money($all) . ' ' . $cur . "\n" . self::sep();
        $t .= '👛 مجموع کیف پول‌ها: ' . money($wPos) . ' ' . $cur . "\n";
        if ($wNeg > 0) $t .= '🏷 اعتبار مصرف‌شدهٔ نمایندگان: ' . money($wNeg) . ' ' . $cur . "\n";
        $t .= '🎟 کد تخفیف فعال: ' . fa_num($dcA) . '  |  🎁 کد هدیهٔ فعال: ' . fa_num($gfA);

        $rows = [
            [Tg::btn('💳 پرداخت‌های در انتظار (' . fa_num($pendN) . ')', 'adm:pay')],
            [Tg::btn('🪪 احراز کارت (' . fa_num($cards) . ')', 'adm:crd'), Tg::btn('💰 افزودن موجودی', 'adm:bal')],
            [Tg::btn('🧾 تراکنش‌های اخیر', 'adm:txs:1'), Tg::btn('🏆 پرفروش‌ها', 'adm:sttop')],
            [Tg::btn('🎟 کدهای تخفیف', 'adm:codes:1'), Tg::btn('🎁 کدهای هدیه', 'adm:gifts:1')],
            [Tg::btn('➕ کد تخفیف سریع', 'adm:dcnew'), Tg::btn('➕ کد هدیه سریع', 'adm:gfnew')],
            [Tg::btn('📈 فروش ۷ روز اخیر', 'adm:st7')],
            self::nav(),
        ];
        self::out($chatId, $msgId, $t, $rows);
    }

    private static function txList($chatId, $msgId, int $page): void
    {
        $per   = 10;
        $total = (int)DB::val('SELECT COUNT(*) FROM {p}transactions', [], 0);
        $pages = max(1, (int)ceil($total / $per));
        $page  = min($page, $pages);
        $off   = ($page - 1) * $per;
        $rows  = DB::all('SELECT t.*, u.first_name AS fn FROM {p}transactions t LEFT JOIN {p}users u ON u.id = t.user_id'
            . ' ORDER BY t.id DESC LIMIT ' . $per . ' OFFSET ' . $off);

        $t = '🧾 <b>تراکنش‌های اخیر</b> — صفحهٔ ' . fa_num($page) . ' از ' . fa_num($pages) . "\n" . self::sep();
        if (!$rows) $t .= 'تراکنشی ثبت نشده است.';
        foreach ($rows as $r) {
            $st = (string)($r['status'] ?? '');
            $ic = $st === 'pending' ? '⏳' : (in_array($st, ['approved', 'paid', 'done', 'success', 'ok'], true) ? '✅'
                : (in_array($st, ['rejected', 'canceled', 'cancelled', 'failed'], true) ? '❌' : '⚪️'));
            $t .= $ic . ' <code>#' . (int)$r['id'] . '</code> · <b>' . money((int)$r['amount']) . '</b> ' . currency()
                . ' · ' . h(mb_substr((string)($r['fn'] ?? '—'), 0, 16)) . ' (<code>' . (int)$r['tg_id'] . '</code>)'
                . ' · ' . h((string)($r['method'] ?? '')) . "\n"
                . '   ⤷ ' . self::jd((string)($r['created_at'] ?? '')) . "\n";
        }

        $pg = [];
        if ($page > 1)      $pg[] = Tg::btn('⬅️ قبلی', 'adm:txs:' . ($page - 1));
        if ($page < $pages) $pg[] = Tg::btn('بعدی ➡️', 'adm:txs:' . ($page + 1));
        $kb = [];
        if ($pg) $kb[] = $pg;
        $kb[] = [Tg::btn('💳 پرداخت‌های در انتظار', 'adm:pay')];
        $kb[] = self::nav([Tg::btn('⬅️ مالی', 'adm:fin')]);
        self::out($chatId, $msgId, $t, $kb);
    }

    private static function codesList($chatId, $msgId, string $kind, int $page): void
    {
        $tbl   = $kind === 'gift' ? 'gift_codes' : 'discount_codes';
        $per   = self::PER;
        $total = (int)DB::val('SELECT COUNT(*) FROM {p}' . $tbl, [], 0);
        $pages = max(1, (int)ceil($total / $per));
        $page  = min($page, $pages);
        $rows  = DB::all('SELECT * FROM {p}' . $tbl . ' ORDER BY active DESC, id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));

        $t = ($kind === 'gift' ? '🎁 <b>کدهای هدیه</b>' : '🎟 <b>کدهای تخفیف</b>') . ' — صفحهٔ ' . fa_num($page) . ' از ' . fa_num($pages) . "\n" . self::sep();
        $t .= 'با زدن هر کد، فعال/غیرفعال می‌شود. ساخت و ویرایش کامل در پنل وب.' . "\n" . self::sep();
        if (!$rows) $t .= 'کدی ثبت نشده است.';
        $kb = [];
        foreach ($rows as $c) {
            $on  = (int)$c['active'] === 1;
            $max = (int)$c['max_uses'];
            $use = fa_num((int)$c['used']) . '/' . ($max > 0 ? fa_num($max) : '∞');
            if ($kind === 'gift') {
                $val = money((int)$c['amount']) . ' ' . currency();
            } else {
                $val = (string)$c['type'] === 'percent' ? fa_num((int)$c['value']) . '٪' : money((int)$c['value']) . ' ' . currency();
            }
            $exp = !empty($c['expires_at']) ? ' · تا ' . self::jd((string)$c['expires_at'], false) : '';
            $t .= ($on ? '🟢' : '⚪️') . ' <code>' . h((string)$c['code']) . '</code> — ' . $val . ' · ' . $use . $exp . "\n";
            $kb[] = [Tg::btn(($on ? '🟢 ' : '⚪️ ') . (string)$c['code'] . ' — ' . $val,
                'adm:' . ($kind === 'gift' ? 'gftg' : 'dctg') . ':' . (int)$c['id'] . ':' . $page)];
        }
        $pg = [];
        $key = $kind === 'gift' ? 'gifts' : 'codes';
        if ($page > 1)      $pg[] = Tg::btn('⬅️ قبلی', 'adm:' . $key . ':' . ($page - 1));
        if ($page < $pages) $pg[] = Tg::btn('بعدی ➡️', 'adm:' . $key . ':' . ($page + 1));
        if ($pg) $kb[] = $pg;
        $kb[] = [Tg::btn($kind === 'gift' ? '➕ کد هدیه سریع' : '➕ کد تخفیف سریع', $kind === 'gift' ? 'adm:gfnew' : 'adm:dcnew')];
        $kb[] = self::nav([Tg::btn('⬅️ مالی', 'adm:fin')]);
        self::out($chatId, $msgId, $t, $kb);
    }

    private static function codeToggle($chatId, $msgId, $cbId, string $kind, string $arg2): void
    {
        [$id, $page] = array_pad(explode(':', $arg2, 2), 2, '1');
        $tbl = $kind === 'gift' ? 'gift_codes' : 'discount_codes';
        $c   = DB::one('SELECT * FROM {p}' . $tbl . ' WHERE id = :id', [':id' => (int)$id]);
        if (!$c) { Tg::answerCb($cbId, 'کد یافت نشد.', true); return; }
        $on = (int)$c['active'] === 1;
        DB::update($tbl, ['active' => $on ? 0 : 1], 'id = :id', [':id' => (int)$id]);
        Tg::answerCb($cbId, (string)$c['code'] . ($on ? ' غیرفعال شد' : ' فعال شد'));
        self::codesList($chatId, $msgId, $kind, max(1, (int)$page));
    }

    /* ============================================================ سرویس‌ها */

    private static function svcCounts(): array
    {
        $r = DB::one("SELECT
            SUM(status = 'active') a, SUM(status = 'expired') e, SUM(status = 'disabled') d, SUM(status = 'missing') m,
            SUM(status = 'deleted') tr, SUM(is_test = 1 AND status <> 'deleted') t, SUM(is_reseller = 1 AND status <> 'deleted') rs,
            SUM(status = 'active' AND expire_at IS NOT NULL AND expire_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)) ex3,
            SUM(status <> 'deleted') n
            FROM {p}services");
        $o = [];
        foreach (['a', 'e', 'd', 'm', 'tr', 't', 'rs', 'ex3', 'n'] as $k) $o[$k] = (int)($r[$k] ?? 0);
        return $o;
    }

    private static function svcHub($chatId, $msgId): void
    {
        $c   = self::svcCounts();
        $cfg = Svc::autoDelCfg();
        $cand = 0;
        try { $cand = count(Svc::autoDelCandidates($cfg['days'], $cfg['rs'], 500)); } catch (\Throwable $e) { }

        $t  = '📦 <b>مدیریت سرویس‌ها</b>' . "\n" . self::sep();
        $t .= '🟢 فعال: <b>' . fa_num($c['a']) . '</b>  |  🔴 منقضی: ' . fa_num($c['e']) . '  |  ⏸ غیرفعال: ' . fa_num($c['d']) . "\n";
        $t .= '⏳ رو به انقضا (۳ روز): <b>' . fa_num($c['ex3']) . '</b>  |  ❔ ناموجود: ' . fa_num($c['m']) . "\n";
        $t .= '🧪 تست: ' . fa_num($c['t']) . '  |  🏷 نمایندگی: ' . fa_num($c['rs']) . '  |  🗑 سطل زباله: ' . fa_num($c['tr']) . "\n" . self::sep();
        $t .= '🧹 حذف خودکار تمام‌شده‌ها: ' . ($cfg['on'] ? '🟢 روشن' : '⚪️ خاموش') . ' (پس از ' . fa_num($cfg['days']) . ' روز'
            . ($cfg['rs'] ? '، شامل نمایندگی' : '') . ')' . "\n";
        $t .= '🗂 آمادهٔ حذف هم‌اکنون: <b>' . fa_num($cand) . '</b>' . "\n" . self::sep();
        $t .= 'یک فهرست را انتخاب کنید یا با شناسه/نام کاربری جستجو کنید.';

        $rows = [
            [Tg::btn('🔎 جستجوی سرویس', 'adm:svc'), Tg::btn('🆕 آخرین‌ها', 'adm:sl:new:1')],
            [Tg::btn('⏳ رو به انقضا (' . fa_num($c['ex3']) . ')', 'adm:sl:exp:1'), Tg::btn('🔴 منقضی (' . fa_num($c['e']) . ')', 'adm:sl:expired:1')],
            [Tg::btn('🟢 فعال (' . fa_num($c['a']) . ')', 'adm:sl:act:1'), Tg::btn('⏸ غیرفعال (' . fa_num($c['d']) . ')', 'adm:sl:dis:1')],
            [Tg::btn('🧪 تست (' . fa_num($c['t']) . ')', 'adm:sl:test:1'), Tg::btn('🏷 نمایندگی (' . fa_num($c['rs']) . ')', 'adm:sl:rs:1')],
            [Tg::btn('❔ ناموجود (' . fa_num($c['m']) . ')', 'adm:sl:miss:1'), Tg::btn('🗑 سطل زباله (' . fa_num($c['tr']) . ')', 'adm:sl:trash:1')],
            [Tg::btn('🔄 همگام‌سازی همه', 'adm:syncall'), Tg::btn('🧹 حذف تمام‌شده‌ها (' . fa_num($cand) . ')', 'adm:autodel')],
            [Tg::btn('♻️ پاکسازی سطل زباله', 'adm:purge'), Tg::btn('🖥 آمار سرورها', 'adm:stpnl')],
            self::nav(),
        ];
        self::out($chatId, $msgId, $t, $rows);
    }

    private static function svcList($chatId, $msgId, string $seg, int $page): void
    {
        $title = ''; $icon = ''; $w = ''; $o = 's.id DESC';
        if (isset(self::SSEG[$seg])) {
            [$icon, $title, $w, $o] = self::SSEG[$seg];
        } elseif (preg_match('/^pn(\d+)$/', $seg, $m)) {
            $pid = (int)$m[1];
            $pn  = (string)DB::val('SELECT name FROM {p}panels WHERE id = :id', [':id' => $pid], '');
            $icon = '🖥'; $title = 'سرویس‌های سرور ' . $pn; $w = "s.panel_id = {$pid} AND s.status <> 'deleted'";
        } else {
            self::svcHub($chatId, $msgId);
            return;
        }

        $per   = self::PER;
        $total = (int)DB::val('SELECT COUNT(*) FROM {p}services s WHERE ' . $w, [], 0);
        $pages = max(1, (int)ceil($total / $per));
        $page  = min($page, $pages);
        $rows  = DB::all('SELECT s.*, p.name AS pname FROM {p}services s LEFT JOIN {p}panels p ON p.id = s.panel_id WHERE ' . $w
            . ' ORDER BY ' . $o . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));

        $t = $icon . ' <b>' . h($title) . '</b> — ' . fa_num($total) . ' مورد · صفحهٔ ' . fa_num($page) . ' از ' . fa_num($pages) . "\n" . self::sep();
        if (!$rows) $t .= 'موردی یافت نشد.';
        $kb = [];
        foreach ($rows as $s) {
            $vol = (float)$s['volume_gb'];
            $rem = $s['status'] === 'active' ? remaining_human($s['expire_at'] ?? null) : self::statusLabel((string)$s['status']);
            $t .= self::statusIcon($s) . ' <code>' . h((string)$s['client_email']) . '</code> · ' . h((string)($s['pname'] ?? '—'))
                . ' · ' . ($vol > 0 ? fa_num((string)$vol) . ' گیگ' : 'نامحدود') . ' · ' . $rem . "\n";
            $kb[] = [Tg::btn(self::statusIcon($s) . ' ' . mb_substr((string)$s['client_email'], 0, 24) . ' · ' . $rem, 'adm:s:' . (int)$s['id'])];
        }
        $pg = [];
        if ($page > 1)      $pg[] = Tg::btn('⬅️ قبلی', 'adm:sl:' . $seg . ':' . ($page - 1));
        if ($page < $pages) $pg[] = Tg::btn('بعدی ➡️', 'adm:sl:' . $seg . ':' . ($page + 1));
        if ($pg) $kb[] = $pg;
        $kb[] = self::nav([Tg::btn('⬅️ سرویس‌ها', 'adm:svcs')]);
        self::out($chatId, $msgId, $t, $kb);
    }

    private static function syncAll($chatId, $msgId, $cbId): void
    {
        Tg::answerCb($cbId, '⏳ همگام‌سازی شروع شد...');
        $rows = DB::all("SELECT * FROM {p}services WHERE status IN ('active','disabled','expired','missing') ORDER BY last_sync IS NULL DESC, last_sync ASC LIMIT 25");
        $n = 0;
        try {
            $res = Svc::syncStale($rows, 150, 25, true);
            $n   = is_array($res) ? count($res) : 0;
        } catch (\Throwable $e) {
            app_log('admin', 'syncAll: ' . $e->getMessage());
        }
        $c = self::svcCounts();
        $t = '🔄 <b>همگام‌سازی همه</b>' . "\n" . self::sep()
           . '✅ ' . fa_num($n) . ' سرویس (قدیمی‌ترین همگام‌سازی‌ها) با پنل‌ها همگام شد.' . "\n"
           . 'برای سرویس‌های بیشتر دوباره بزنید؛ کران‌جاب هم به‌طور خودکار ادامه می‌دهد.' . "\n" . self::sep()
           . '🟢 فعال: ' . fa_num($c['a']) . '  |  🔴 منقضی: ' . fa_num($c['e']) . '  |  ❔ ناموجود: ' . fa_num($c['m']);
        self::out($chatId, $msgId, $t, [[Tg::btn('🔄 ادامهٔ همگام‌سازی', 'adm:syncall')], self::nav([Tg::btn('⬅️ سرویس‌ها', 'adm:svcs')])]);
    }

    private static function autodelAsk($chatId, $msgId): void
    {
        $cfg  = Svc::autoDelCfg();
        $list = [];
        try { $list = Svc::autoDelCandidates($cfg['days'], $cfg['rs'], 500); } catch (\Throwable $e) { }
        $t = '🧹 <b>حذف سرویس‌های تمام‌شده</b>' . "\n" . self::sep()
           . 'سرویس‌هایی که بیش از <b>' . fa_num($cfg['days']) . ' روز</b> از پایانشان گذشته و تمدید نشده‌اند'
           . ($cfg['rs'] ? ' (شامل نمایندگی)' : ' (بدون نمایندگی)') . ' از پنل حذف و به سطل زباله می‌روند.' . "\n" . self::sep()
           . '🗂 تعداد آماده: <b>' . fa_num(count($list)) . '</b>' . "\n";
        foreach (array_slice($list, 0, 8) as $s) $t .= '• <code>' . h((string)$s['client_email']) . '</code>' . "\n";
        if (count($list) > 8) $t .= '… و ' . fa_num(count($list) - 8) . ' مورد دیگر' . "\n";
        $t .= self::sep() . 'ادامه می‌دهید؟';
        $rows = [];
        if ($list) $rows[] = [Tg::btn('✅ بله، حذف کن', 'adm:autodelok'), Tg::btn('↩️ انصراف', 'adm:svcs')];
        else       $rows[] = [Tg::btn('↩️ بازگشت', 'adm:svcs')];
        self::out($chatId, $msgId, $t, $rows);
    }

    private static function autodelRun($chatId, $msgId, $cbId): void
    {
        Tg::answerCb($cbId, '⏳ در حال حذف...');
        $cfg = Svc::autoDelCfg();
        $deleted = 0; $warned = 0;
        if ($cfg['on']) {
            $r = Svc::autoDeleteExpired();
            if (class_exists('Audit')) Audit::log('svc.autodel', ['deleted' => (int)($r['deleted'] ?? 0), 'warned' => (int)($r['warned'] ?? 0)]);
            $deleted = (int)($r['deleted'] ?? 0); $warned = (int)($r['warned'] ?? 0);
        } else {
            foreach (Svc::autoDelCandidates($cfg['days'], $cfg['rs'], 100) as $s) {
                try { if (Svc::remove($s, true)) $deleted++; } catch (\Throwable $e) { }
            }
        }
        if (class_exists('Logs')) {
            try { Logs::send('autodel', Logs::fmt('🧹 حذف دستی تمام‌شده‌ها', ['مدیر' => '<code>' . (int)Bot::$u['tg_id'] . '</code>', 'حذف‌شده' => fa_num($deleted)])); } catch (\Throwable $e) { }
        }
        $t = '🧹 <b>حذف تمام‌شده‌ها انجام شد</b>' . "\n" . self::sep()
           . '🗑 حذف‌شده: <b>' . fa_num($deleted) . '</b>' . ($warned ? "\n" . '⚠️ هشدار ۲۴ ساعته ارسال‌شده: ' . fa_num($warned) : '') . "\n"
           . 'سرویس‌های حذف‌شده تا پایان مهلت سطل زباله قابل بازیابی از پنل وب هستند.';
        self::out($chatId, $msgId, $t, [self::nav([Tg::btn('⬅️ سرویس‌ها', 'adm:svcs')])]);
    }

    private static function purgeAsk($chatId, $msgId): void
    {
        $n = Svc::trashCount();
        $days = (int)DB::setting('trash_days', 7);
        $t = '♻️ <b>پاکسازی سطل زباله</b>' . "\n" . self::sep()
           . 'در سطل زباله: <b>' . fa_num($n) . '</b> سرویس' . "\n"
           . 'با تأیید، سرویس‌هایی که بیش از <b>' . fa_num($days) . ' روز</b> در سطل زباله بوده‌اند برای همیشه پاک می‌شوند.' . "\n"
           . '(مهلت از تنظیمات وب قابل تغییر است)';
        self::out($chatId, $msgId, $t, [[Tg::btn('✅ پاکسازی کن', 'adm:purgeok'), Tg::btn('↩️ انصراف', 'adm:svcs')]]);
    }

    private static function purgeRun($chatId, $msgId, $cbId): void
    {
        $n = 0;
        try { $n = (int)Svc::purgeTrash(); } catch (\Throwable $e) { }
        if (class_exists('Audit')) Audit::log('svc.purge', ['deleted' => $n]);
        Tg::answerCb($cbId, fa_num($n) . ' سرویس برای همیشه پاک شد');
        self::svcHub($chatId, $msgId);
    }

    /* ---------------- اقدامات روی یک سرویس ---------------- */

    private static function svcAction($chatId, $msgId, $cbId, string $act, int $sid): void
    {
        $s = DB::one('SELECT * FROM {p}services WHERE id = :id', [':id' => $sid]);
        if (!$s) { Tg::answerCb($cbId, 'سرویس یافت نشد.', true); return; }
        $email = (string)$s['client_email'];

        switch ($act) {
            case 'sadd':
                Tg::answerCb($cbId);
                self::setState('admin_svc_add', ['sid' => $sid]);
                Tg::send($chatId, '♻️ <b>افزودن روز / گیگ</b> به <code>' . h($email) . '</code>' . "\n" . self::sep()
                    . "دو عدد بفرستید:  <code>روز  گیگ</code>\n"
                    . "مثال: <code>30 10</code> → ۳۰ روز و ۱۰ گیگ اضافه می‌شود\n"
                    . "مثال: <code>0 5</code> → فقط ۵ گیگ\n"
                    . "عدد منفی برای کم‌کردن هم مجاز است. اگر سرویس منقضی باشد، از همین لحظه محاسبه و دوباره فعال می‌شود.", Kb::cancel());
                return;

            case 'smsg':
                Tg::answerCb($cbId);
                DB::update('users', ['state' => 'admin_dm_text', 'state_data' => jenc(['uid' => (int)$s['user_id']])],
                    'id = :id', [':id' => (int)Bot::$u['id']]);
                Tg::send($chatId, '✉��� متن پیام برای مالک سرویس <code>' . h($email) . '</code> را بفرستید:', Kb::cancel());
                return;

            case 'sreset': {
                [$x] = self::xuiFor($s);
                if (!$x) { Tg::answerCb($cbId, 'پنل یافت نشد.', true); return; }
                $r = $x->resetTraffic((int)$s['inbound_id'], $email);
                if (($r['success'] ?? false) === true) {
                    DB::update('services', ['used_bytes' => 0], 'id = :id', [':id' => $sid]);
                    Tg::answerCb($cbId, '✅ ترافیک صفر شد');
                } else {
                    Tg::answerCb($cbId, '❌ ' . mb_substr((string)($r['msg'] ?? 'ناموفق'), 0, 150), true);
                }
                AdminBot::serviceCard($chatId, $msgId, $sid);
                return;
            }

            case 'stog': {
                $enable = (string)$s['status'] === 'disabled';
                $r = Svc::toggle($s, $enable);
                Tg::answerCb($cbId, ($r['ok'] ? '✅ ' : '❌ ') . (string)$r['message'], !$r['ok']);
                if ($r['ok']) {
                    try {
                        Tg::send((int)$s['tg_id'], ($enable ? '▶️ سرویس <code>' : '⏸ سرویس <code>') . h($email) . '</code> شما توسط مدیریت '
                            . ($enable ? 'فعال شد.' : 'غیرفعال شد. برای پیگیری با پشتیبانی در تماس باشید.'));
                    } catch (\Throwable $e) { }
                }
                AdminBot::serviceCard($chatId, $msgId, $sid);
                return;
            }

            case 'sdel':
                Tg::answerCb($cbId);
                self::out($chatId, $msgId, '🗑 <b>حذف سرویس</b>' . "\n" . self::sep()
                    . 'سرویس <code>' . h($email) . '</code> از پنل حذف و به سطل زباله منتقل می‌شود.' . "\n"
                    . 'مالک: <code>' . (int)$s['tg_id'] . '</code>' . "\n" . self::sep() . 'مطمئن هستید؟',
                    [[Tg::btn('✅ بله، حذف کن', 'adm:sdelok:' . $sid), Tg::btn('↩️ انصراف', 'adm:s:' . $sid)]]);
                return;

            case 'sdelok': {
                Svc::remove($s, true);
                Tg::answerCb($cbId, '🗑 سرویس حذف شد');
                try {
                    Tg::send((int)$s['tg_id'], '🗑 سرویس <code>' . h($email) . '</code> شما توسط مدیریت حذف شد.');
                } catch (\Throwable $e) { }
                if (class_exists('Logs')) {
                    try { Logs::send('svc', Logs::fmt('🗑 حذف سرویس توسط مدیر', ['مدیر' => '<code>' . (int)Bot::$u['tg_id'] . '</code>', 'سرویس' => '<code>' . h($email) . '</code>'])); } catch (\Throwable $e) { }
                }
                self::out($chatId, $msgId, '✅ سرویس <code>' . h($email) . '</code> حذف و به سطل زباله منتقل شد.',
                    [[Tg::btn('🗑 سطل زباله', 'adm:sl:trash:1'), Tg::btn('👤 پروندهٔ مالک', 'adm:u:' . (int)$s['user_id'])], self::nav([Tg::btn('⬅️ سرویس‌ها', 'adm:svcs')])]);
                return;
            }

            case 'slink': {
                Tg::answerCb($cbId, '⏳ دریافت لینک‌ها...');
                $sub = '';
                try { $sub = Svc::subUrl($s); } catch (\Throwable $e) { }
                $cfgs = [];
                try { $cfgs = Svc::liveConfigs($s); } catch (\Throwable $e) { }
                $t = '🔗 <b>لینک و کانفیگ‌های</b> <code>' . h($email) . '</code>' . "\n" . self::sep();
                $t .= $sub !== '' ? '📎 لینک اشتراک:' . "\n" . '<code>' . h($sub) . '</code>' . "\n" : '📎 لینک اشتراک: ندارد' . "\n";
                if ($cfgs) {
                    $t .= self::sep() . '⚙️ کانفیگ‌ها (' . fa_num(count($cfgs)) . '):' . "\n";
                    $i = 0;
                    foreach ($cfgs as $c) {
                        if (mb_strlen($t) > 3300) { $t .= '… (بقیه در پنل وب)'; break; }
                        $t .= '<code>' . h((string)$c) . '</code>' . "\n";
                        if (++$i >= 6) { if (count($cfgs) > 6) $t .= '… و ' . fa_num(count($cfgs) - 6) . ' کانفیگ دیگر'; break; }
                    }
                } else {
                    $t .= self::sep() . '⚙️ کانفیگی دریافت نشد.';
                }
                Tg::send($chatId, $t, Tg::ikb([[Tg::btn('⬅️ کارت سرویس', 'adm:s:' . $sid)]]));
                return;
            }

            case 'sonl': {
                Tg::answerCb($cbId, '⏳ بررسی اتصال...');
                self::svcOnline($chatId, $msgId, $s);
                return;
            }

            case 'sips': {
                [$x] = self::xuiFor($s);
                if (!$x) { Tg::answerCb($cbId, 'پنل یافت نشد.', true); return; }
                $r = $x->clearClientIps($email);
                if (class_exists('Audit')) Audit::log('svc.clearips', ['email' => $email]);
                Tg::answerCb($cbId, ($r['success'] ?? false) ? '✅ آی‌پی‌ها پاک شد' : '❌ ' . mb_substr((string)($r['msg'] ?? 'ناموفق'), 0, 150), !($r['success'] ?? false));
                self::svcOnline($chatId, $msgId, $s);
                return;
            }

            case 'srevoke': {
                [$x] = self::xuiFor($s);
                if (!$x) { Tg::answerCb($cbId, 'پنل یافت نشد.', true); return; }
                $r = $x->revokeSub($email);
                if (($r['success'] ?? false) === true) {
                    $upd = ['last_sync' => now()];
                    if (!empty($r['sub'])) $upd['sub_link'] = (string)$r['sub'];
                    DB::update('services', $upd, 'id = :id', [':id' => $sid]);
                    try { Svc::syncNow($sid); } catch (\Throwable $e) { }
                    Tg::answerCb($cbId, '✅ لینک اشتراک قبلی باطل و لینک جدید ساخته شد');
                    try {
                        Tg::send((int)$s['tg_id'], '🔗 لینک اشتراک سرویس <code>' . h($email) . '</code> شما به‌روزرسانی شد. لطفاً از بخش «سرویس‌های من» لینک جدید را دریافت کنید.');
                    } catch (\Throwable $e) { }
                } else {
                    Tg::answerCb($cbId, '❌ ' . mb_substr((string)($r['msg'] ?? 'ناموفق'), 0, 150), true);
                }
                AdminBot::serviceCard($chatId, $msgId, $sid);
                return;
            }
        }
        Tg::answerCb($cbId);
    }

    /** وضعیت اتصال، آخرین اتصال و دستگاه‌های یک سرویس */
    private static function svcOnline($chatId, $msgId, array $s): void
    {
        $sid   = (int)$s['id'];
        $email = (string)$s['client_email'];
        [$x, $p] = self::xuiFor($s);
        $t = '🕒 <b>اتصال و دستگاه‌ها</b> — <code>' . h($email) . '</code>' . "\n" . self::sep();
        $rows = [];
        if (!$x) {
            $t .= 'پنل این سرویس یافت نشد.';
        } else {
            $on = $x->supports('online') ? $x->isOnline($email) : null;
            $t .= '🖥 سرور: ' . h((string)($p['name'] ?? '—')) . ' (' . h(Xui::typeLabel((string)($p['type'] ?? ''))) . ')' . "\n";
            $t .= '📡 وضعیت: ' . ($on === null ? '❔ این پنل وضعیت آنلاین را نمی‌دهد' : ($on ? '🟢 هم‌اکنون آنلاین' : '⚪️ آفلاین')) . "\n";
            if ($x->supports('online')) {
                $ts = $x->lastOnlineOf($email);
                $t .= '🕒 آخرین اتصال: ' . ($ts > 0 ? self::jd(date('Y-m-d H:i:s', $ts)) : 'هرگز / نامشخص') . "\n";
            }
            try {
                $lt = $x->liveTraffic($email);
                if (is_array($lt)) {
                    $used = (int)($lt['up'] ?? 0) + (int)($lt['down'] ?? 0);
                    $tot  = (int)($lt['total'] ?? 0);
                    $t .= '📉 مصرف زنده: ' . fa_num(human_bytes($used)) . ($tot > 0 ? ' از ' . fa_num(human_bytes($tot)) . ' (' . self::pct($used, $tot) . ')' : '') . "\n";
                    if (array_key_exists('enable', $lt)) $t .= '⚙️ در پنل: ' . (!empty($lt['enable']) ? 'فعال' : 'غیرفعال') . "\n";
                }
            } catch (\Throwable $e) { }
            if ($x->supports('ips')) {
                $ips = $x->clientIps($email);
                $t .= self::sep() . '📱 آی‌پی‌های ثبت‌شده: <b>' . fa_num(count($ips)) . '</b>' . "\n";
                foreach (array_slice($ips, 0, 10) as $ip) $t .= '• <code>' . h((string)$ip) . '</code>' . "\n";
                if (count($ips) > 10) $t .= '… و ' . fa_num(count($ips) - 10) . ' مورد دیگر' . "\n";
                $rows[] = [Tg::btn('📵 پاک‌کردن IPها', 'adm:sips:' . $sid)];
            }
        }
        $rows[] = [Tg::btn('🔄 بروزرسانی', 'adm:sonl:' . $sid), Tg::btn('⬅️ کارت سرویس', 'adm:s:' . $sid)];
        self::out($chatId, $msgId, $t, $rows);
    }

    /* ============================================================ سرورها */

    private static function panelCard($chatId, $msgId, int $pid): void
    {
        $p = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => $pid]);
        if (!$p) { self::out($chatId, $msgId, '❌ سرور یافت نشد.', [self::nav([Tg::btn('⬅️ سرورها', 'adm:pnl')])]); return; }
        $on  = (int)$p['active'] === 1;
        $x   = null; $ok = false; $msg = ''; $ms = 0;
        try {
            $x = new Xui($p);
            if ($on) {
                $t0 = microtime(true);
                $r  = $x->healthCheck();
                $ms = (int)round((microtime(true) - $t0) * 1000);
                $ok = is_array($r) ? !empty($r['ok']) : (bool)$r;
                $msg = is_array($r) ? (string)($r['message'] ?? '') : '';
            }
        } catch (\Throwable $e) { $msg = $e->getMessage(); }

        $use = Svc::panelUsage($pid);
        $cap = (int)($p['user_limit'] ?? 0);
        $cnt = DB::one("SELECT SUM(status = 'active') a, SUM(status = 'expired') e, SUM(is_test = 1) t FROM {p}services WHERE panel_id = :p AND status <> 'deleted'", [':p' => $pid]) ?: [];

        $t  = (!$on ? '⚪️' : ($ok ? '🟢' : '🔴')) . ' <b>' . h((string)$p['name']) . '</b>' . "\n" . self::sep();
        $t .= '🧩 نوع: ' . h(Xui::typeLabel((string)$p['type'])) . "\n";
        $t .= '🌐 آدرس: <code>' . h((string)$p['host']) . ':' . (int)$p['port'] . '</code>' . "\n";
        $t .= '🔌 وضعیت: ' . (!$on ? 'غیرفعال (ف��وش متوقف)' : ($ok ? 'در دسترس' . ($ms ? ' · ⚡️ ' . fa_num($ms) . ' ms' : '') : 'خطا در اتصال')) . "\n";
        if ($on && !$ok && ($msg !== '' || !empty($p['last_error']))) $t .= '⚠️ ' . h(mb_substr($msg !== '' ? $msg : (string)$p['last_error'], 0, 160)) . "\n";
        $t .= self::sep();
        $t .= '👥 ظرفیت: <b>' . fa_num($use) . '</b>' . ($cap > 0 ? ' از ' . fa_num($cap) . ' (' . self::pct($use, $cap) . ')' : ' (نامحدود)') . "\n";
        $t .= '🟢 فعال: ' . fa_num((int)($cnt['a'] ?? 0)) . '  |  🔴 منقضی: ' . fa_num((int)($cnt['e'] ?? 0)) . '  |  🧪 تست: ' . fa_num((int)($cnt['t'] ?? 0)) . "\n";
        $ib = trim((string)($p['inbound_ids'] ?? ''));
        $t .= ($x && $x->isMarzbanLike() ? '🗂 گروه‌ها/اینباندهای مجاز: ' : '📡 اینباندهای مجاز: ') . ($ib !== '' ? '<code>' . h($ib) . '</code>' : 'همه') . "\n";
        if (!empty($p['sub_base'])) $t .= '🔗 دامنهٔ ساب: <code>' . h((string)$p['sub_base']) . '</code>' . "\n";
        $feat = [];
        if ($x) {
            if ($x->supports('status'))   $feat[] = 'وضعیت سرور';
            if ($x->supports('online'))   $feat[] = 'آنلاین/آخرین اتصال';
            if ($x->supports('ips'))      $feat[] = 'مدیریت IP';
            if ($x->supports('depleted')) $feat[] = 'حذف تمام‌شده‌ها';
            if ($x->supports('revoke'))   $feat[] = 'لغو ساب';
        }
        if ($feat) $t .= '✨ قابلیت‌های پیشرفته: ' . implode('، ', $feat) . "\n";
        $t .= self::sep() . 'ویرایش آدرس/رمز/اینباندها در پنل وب انجام می‌شود.';

        $rows = [[Tg::btn($on ? '⏸ غیرفعال کردن سرور' : '▶️ فعال کردن سرور', 'adm:ptog:' . $pid), Tg::btn('📦 سرویس‌های این سرور', 'adm:sl:pn' . $pid . ':1')]];
        $r2 = [];
        if ($x && $x->supports('status')) $r2[] = Tg::btn('📈 وضعیت منابع سرور', 'adm:pstat:' . $pid);
        $r2[] = Tg::btn($x && $x->isMarzbanLike() ? '🗂 گروه‌ها' : '📡 اینباندها', 'adm:pinb:' . $pid);
        $rows[] = $r2;
        if ($x && $x->supports('depleted')) $rows[] = [Tg::btn('🧹 حذف کلاینت‌های تمام‌شدهٔ پنل', 'adm:pdep:' . $pid)];
        $rows[] = [Tg::btn('🔄 بررسی مجدد', 'adm:p:' . $pid), Tg::btn('⬅️ سرورها', 'adm:pnl'), Tg::btn('🏠 خانه', 'adm:home')];
        self::out($chatId, $msgId, $t, $rows);
    }

    private static function panelToggle($chatId, $msgId, $cbId, int $pid): void
    {
        $p = DB::one('SELECT id, active, name FROM {p}panels WHERE id = :id', [':id' => $pid]);
        if (!$p) { Tg::answerCb($cbId, 'سرور یافت نشد.', true); return; }
        $on = (int)$p['active'] === 1;
        DB::update('panels', ['active' => $on ? 0 : 1], 'id = :id', [':id' => $pid]);
        Tg::answerCb($cbId, (string)$p['name'] . ($on ? ' غیرفعال شد' : ' فعال شد'));
        self::panelCard($chatId, $msgId, $pid);
    }

    private static function panelStatus($chatId, $msgId, int $pid): void
    {
        $p = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => $pid]);
        if (!$p) { self::panelCard($chatId, $msgId, $pid); return; }
        $st = null;
        try { $st = (new Xui($p))->serverStatus(); } catch (\Throwable $e) { }
        $t = '📈 <b>وضعیت منابع</b> — ' . h((string)$p['name']) . "\n" . self::sep();
        if (!$st) {
            $t .= 'اطلاعاتی دریافت نشد. (این قابلیت روی سنایی نسل جدید با توکن API و پاسارگارد در دسترس است)';
        } else {
            $t .= '🧠 CPU: <b>' . fa_num((string)round((float)$st['cpu'], 1)) . '٪</b>' . "\n";
            if ((int)$st['mem_total'] > 0) {
                $t .= '💾 RAM: ' . fa_num(human_bytes((int)$st['mem_used'])) . ' از ' . fa_num(human_bytes((int)$st['mem_total'])) . ' (' . self::pct($st['mem_used'], $st['mem_total']) . ')' . "\n";
            }
            if ((int)$st['uptime'] > 0) $t .= '⏱ آپ‌تایم: ' . self::uptime((int)$st['uptime']) . "\n";
            if ((string)$st['xray_state'] !== '' || (string)$st['xray_ver'] !== '') {
                $xs = (string)$st['xray_state'];
                $t .= '⚙️ هسته: ' . ($xs === 'running' ? '🟢 در حال اجرا' : ($xs !== '' ? '🔴 ' . h($xs) : '')) . ((string)$st['xray_ver'] !== '' ? ' · v' . h((string)$st['xray_ver']) : '') . "\n";
            }
            $t .= '🔼 آپلود: ' . fa_num(human_bytes((int)$st['net_up'])) . '/s  |  🔽 دانلود: ' . fa_num(human_bytes((int)$st['net_down'])) . '/s' . "\n";
            $t .= '📤 ارسال‌شده: ' . fa_num(human_bytes((int)$st['sent'])) . '  |  📥 دریافت‌شده: ' . fa_num(human_bytes((int)$st['recv'])) . "\n";
            if (isset($st['users_total'])) {
                $t .= '👥 کاربران پنل: ' . fa_num((int)$st['users_total']) . ' · فعال: ' . fa_num((int)$st['users_active']) . ' · آنلاین: ' . fa_num((int)$st['users_online']) . "\n";
            }
            $t .= self::sep() . '🕒 ' . self::jd(now());
        }
        self::out($chatId, $msgId, $t, [[Tg::btn('🔄 بروزرسانی', 'adm:pstat:' . $pid), Tg::btn('⬅️ کارت سرور', 'adm:p:' . $pid)]]);
    }

    private static function panelInbounds($chatId, $msgId, int $pid): void
    {
        $p = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => $pid]);
        if (!$p) { self::panelCard($chatId, $msgId, $pid); return; }
        $list = []; $err = ''; $x = null;
        try { $x = new Xui($p); $list = (array)$x->inbounds(); } catch (\Throwable $e) { $err = $e->getMessage(); }
        $allowed = array_filter(array_map('intval', preg_split('/[^0-9]+/', (string)($p['inbound_ids'] ?? '')) ?: []));
        $isG = $x && $x->isMarzbanLike();
        $t = ($isG ? '🗂 <b>گروه‌ها</b>' : '📡 <b>اینباندها</b>') . ' — ' . h((string)$p['name']) . "\n" . self::sep();
        if (!$list) {
            $t .= $err !== '' ? '⚠️ ' . h(mb_substr($err, 0, 160)) : 'موردی دریافت نشد.';
        } else {
            $i = 0;
            foreach ($list as $ib) {
                if (!is_array($ib)) continue;
                $id   = (int)($ib['id'] ?? 0);
                $name = (string)($ib['remark'] ?? ($ib['name'] ?? ($ib['tag'] ?? ('#' . $id))));
                $prot = (string)($ib['protocol'] ?? '');
                $port = (int)($ib['port'] ?? 0);
                $en   = array_key_exists('enable', $ib) ? (bool)$ib['enable'] : true;
                $cs   = isset($ib['clientStats']) && is_array($ib['clientStats']) ? count($ib['clientStats']) : null;
                if ($cs === null && isset($ib['settings']) && is_string($ib['settings'])) {
                    $sj = jdec($ib['settings'], []);
                    if (isset($sj['clients']) && is_array($sj['clients'])) $cs = count($sj['clients']);
                }
                $mark = ($allowed && in_array($id, $allowed, true)) ? '✅' : ($allowed ? '▫️' : '✅');
                $t .= $mark . ' <b>' . h(mb_substr($name, 0, 28)) . '</b> <code>#' . $id . '</code>';
                if ($prot !== '') $t .= ' · ' . h($prot);
                if ($port > 0)    $t .= ':' . $port;
                if (!$en)         $t .= ' · ⛔️ خاموش';
                if ($cs !== null) $t .= ' · 👥 ' . fa_num($cs);
                $t .= "\n";
                if (++$i >= 30) { $t .= '… (' . fa_num(count($list) - 30) . ' مورد دیگر)'; break; }
            }
            $t .= self::sep() . '✅ = برای فروش استفاده می‌شود' . ($allowed ? '' : ' (همه)') . "\n" . 'تغییر لیست مجاز از پنل وب → سرورها.';
        }
        self::out($chatId, $msgId, $t, [[Tg::btn('🔄 بروزرسانی', 'adm:pinb:' . $pid), Tg::btn('⬅️ کارت سرور', 'adm:p:' . $pid)]]);
    }

    private static function panelDepletedAsk($chatId, $msgId, int $pid): void
    {
        $p = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => $pid]);
        if (!$p) { self::panelCard($chatId, $msgId, $pid); return; }
        $t = '🧹 <b>حذف کلاینت‌های تمام‌شدهٔ پنل</b> — ' . h((string)$p['name']) . "\n" . self::sep()
           . 'همهٔ کلاینت‌هایی که در خود پنل «تمام‌شده» هستند (حجم یا زمان به پایان رسیده) مستقیماً از پنل پاک می‌شوند؛ '
           . 'شامل کلاینت‌هایی که خارج از ربات ساخته شده‌اند.' . "\n\n"
           . '⚠️ این عمل بازگشت‌ناپذیر است. سرویس‌های ربات که با این کار از پنل حذف شوند، در همگام‌سازی بعدی «ناموجود» علامت می‌خورند.' . "\n" . self::sep()
           . 'برای حذف تدریجیِ فقط سرویس‌های ربات (با مهلت و اطلاع‌رسانی) از «سرویس‌ها → حذف تمام‌شده‌ها» استفاده کنید.';
        self::out($chatId, $msgId, $t, [[Tg::btn('✅ بله، پاک کن', 'adm:pdepok:' . $pid), Tg::btn('↩️ انصراف', 'adm:p:' . $pid)]]);
    }

    private static function panelDepletedRun($chatId, $msgId, $cbId, int $pid): void
    {
        $p = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => $pid]);
        if (!$p) { Tg::answerCb($cbId, 'سرور یافت نشد.', true); return; }
        $x = new Xui($p);
        $allowed = array_values(array_filter(array_map('intval', preg_split('/[^0-9]+/', (string)($p['inbound_ids'] ?? '')) ?: [])));
        $targets = $allowed ?: [-1];
        $okN = 0; $err = '';
        foreach ($targets as $ib) {
            $r = $x->delDepleted((int)$ib);
            if (($r['success'] ?? false) === true) $okN++; else $err = (string)($r['msg'] ?? 'خطا');
        }
        if ($okN > 0) {
            Tg::answerCb($cbId, '✅ کلاینت‌های تمام‌شده پاک شدند');
            if (class_exists('Logs')) {
                try { Logs::send('svc', Logs::fmt('🧹 حذف تمام‌شده‌های پنل', ['مدیر' => '<code>' . (int)Bot::$u['tg_id'] . '</code>', 'سرور' => h((string)$p['name'])])); } catch (\Throwable $e) { }
            }
        } else {
            Tg::answerCb($cbId, '❌ ' . mb_substr($err, 0, 150), true);
        }
        self::panelCard($chatId, $msgId, $pid);
    }

    /* ============================================================ ارتباط */

    private static function commHub($chatId, $msgId): void
    {
        $open = (int)DB::val("SELECT COUNT(*) FROM {p}tickets WHERE status = 'open'", [], 0);
        $ans  = (int)DB::val("SELECT COUNT(*) FROM {p}tickets WHERE status = 'answered'", [], 0);
        $all  = (int)DB::val('SELECT COUNT(*) FROM {p}users WHERE is_banned = 0', [], 0);
        $t  = '📣 <b>ارتباط و پشتیبانی</b>' . "\n" . self::sep();
        $t .= '🎫 تیکت باز: <b>' . fa_num($open) . '</b>  |  پاسخ‌داده‌شده: ' . fa_num($ans) . "\n";
        $t .= '👥 کاربران قابل‌دریافت پیام: ' . fa_num($all) . "\n" . self::sep();
        $t .= 'پیام همگانی به همه یا فقط به یک گروه (خریداران، نمایندگان، مالکان سرویس رو به انقضا…) ارسال می‌شود.';
        $rows = [
            [Tg::btn('🎫 تیکت‌های باز (' . fa_num($open) . ')', 'adm:tk')],
            [Tg::btn('📣 پیام همگانی (همه)', 'adm:bc'), Tg::btn('🎯 پیام به یک گروه', 'adm:bcseg')],
            [Tg::btn('⏳ پیام به رو به انقضاها', 'adm:bcseg:expsoon'), Tg::btn('🔴 پیام به منقضی‌ها', 'adm:bcseg:expired')],
            [Tg::btn('✉️ پیام به یک کاربر', 'adm:usr')],
            self::nav(),
        ];
        self::out($chatId, $msgId, $t, $rows);
    }

    /** انتخاب گروه برای پیام گروهی یا شروع دریافت متن */
    private static function bcSeg($chatId, $msgId, string $seg): void
    {
        if ($seg === '') {
            $t = '🎯 <b>پیام به یک گروه</b>' . "\n" . self::sep() . 'گروه گیرنده را انتخاب کنید:';
            $rows = []; $line = [];
            $all = [];
            foreach (self::BSEG as $k => $d) $all[$k] = [$d[0], $d[1], $d[2]];
            foreach (AdminBot::USEG as $k => $d) { if ($k === 'ban') continue; $all[$k] = [$d[0], $d[1], $d[2]]; }
            foreach ($all as $k => $d) {
                $n = (int)DB::val('SELECT COUNT(*) FROM {p}users WHERE is_banned = 0 AND (' . $d[2] . ')', AdminBot::usegParams($d[2]), 0);
                $line[] = Tg::btn($d[0] . ' ' . $d[1] . ' (' . fa_num($n) . ')', 'adm:bcseg:' . $k);
                if (count($line) === 2) { $rows[] = $line; $line = []; }
            }
            if ($line) $rows[] = $line;
            $rows[] = self::nav([Tg::btn('⬅️ ارتباط', 'adm:comm')]);
            self::out($chatId, $msgId, $t, $rows);
            return;
        }
        $d = self::BSEG[$seg] ?? (AdminBot::USEG[$seg] ?? null);
        if (!$d) { self::bcSeg($chatId, $msgId, ''); return; }
        $n = (int)DB::val('SELECT COUNT(*) FROM {p}users WHERE is_banned = 0 AND (' . $d[2] . ')', AdminBot::usegParams($d[2]), 0);
        self::setState('admin_bc_seg', ['seg' => $seg]);
        Tg::send($chatId, '🎯 <b>پیام به گروه «' . $d[0] . ' ' . h($d[1]) . '»</b> — ' . fa_num($n) . ' نفر' . "\n" . self::sep()
            . 'متن پیام را بفرستید (HTML تلگرام مجاز است). برای لغو، «' . Kb::CANCEL . '» را بزنید.', Kb::cancel());
    }

    /* ============================================================ ابزار */

    private static function toolsHub($chatId, $msgId): void
    {
        $mnt  = (string)DB::setting('maintenance', '0') === '1';
        $bk   = (string)DB::setting('backup_last_at', '');
        $cron = (string)DB::setting('cron_last_run', '');
        $t  = '🧰 <b>ابزار و نگهداری</b>' . "\n" . self::sep();
        $t .= '🚧 حالت تعمیر: ' . ($mnt ? '🟠 روشن (فقط مدیران به ربات دسترسی دارند)' : '⚪️ خاموش') . "\n";
        $t .= '💾 آخرین بکاپ: ' . ($bk !== '' ? self::jd($bk) : 'ثبت نشده') . "\n";
        if ($cron !== '') $t .= '⏱ آخرین کران‌جاب: ' . self::jd($cron) . "\n";
        $t .= '🗑 سطل زباله: ' . fa_num(Svc::trashCount()) . ' سرویس' . "\n" . self::sep();
        $t .= 'کارهای نگهداری که معمولاً از پنل وب انجام می‌شدند، اینجا با یک ضربه در دسترس‌اند.';
        $rows = [
            [Tg::btn('🩺 سلامت سیستم', 'adm:health'), Tg::btn('💾 پشتیبان‌گیری فوری', 'adm:bkp')],
            [Tg::btn('🔄 همگام‌سازی همه', 'adm:syncall'), Tg::btn('🧹 حذف تمام‌شده‌ها', 'adm:autodel')],
            [Tg::btn('♻️ پاکسازی سطل زباله', 'adm:purge'), Tg::btn('🧷 سفارش‌های گیرکرده', 'adm:stuck')],
            [Tg::btn($mnt ? '🟠 خاموش‌کردن حالت تعمیر' : '🚧 روشن‌کردن حالت تعمیر', 'adm:mnt')],
            [Tg::btn('📜 لاگ امروز', 'adm:logs'), Tg::btn('📝 اقدامات مدیران', 'adm:audit')],
            [Tg::btn('⚙️ تنظیمات سریع', 'adm:set'), Tg::btn('🧪 اکانت تست و ضدسوءاستفاده', 'adm:test')],
            [Tg::btn('🌐 پنل مدیریت وب', 'adm:web'), Tg::btn('🏠 خانه', 'adm:home')],
        ];
        self::out($chatId, $msgId, $t, $rows);
    }

    private static function health($chatId, $msgId): void
    {
        $t = '🩺 <b>سلامت سیستم</b>' . "\n" . self::sep();
        if (!class_exists('Health')) {
            $t .= 'ماژو�� سلامت در دسترس نیست.';
        } else {
            $groups = Health::all(true);
            $sc = Health::score($groups);
            $t .= 'وضعیت کلی: ' . ($sc['state'] === 'ok' ? '🟢 سالم' : ($sc['state'] === 'warn' ? '🟠 با هشدار' : '🔴 دارای خطا'))
                . '  (✅ ' . fa_num($sc['ok']) . ' · ⚠️ ' . fa_num($sc['warn']) . ' · ❌ ' . fa_num($sc['err']) . ')' . "\n" . self::sep();
            foreach ($groups as $g) {
                $items = (array)($g['items'] ?? []);
                $bad = array_filter($items, static fn($i) => (string)($i['status'] ?? 'ok') !== 'ok');
                $t .= ($bad ? (count(array_filter($bad, static fn($i) => $i['status'] === 'err')) ? '🔴' : '🟠') : '🟢') . ' <b>' . h((string)$g['title']) . '</b> (' . fa_num(count($items)) . ')' . "\n";
                foreach (array_slice($bad, 0, 4) as $i) {
                    $t .= '   ' . ((string)$i['status'] === 'err' ? '❌' : '⚠️') . ' ' . h((string)$i['label']) . ': ' . h(mb_substr((string)$i['value'], 0, 60)) . "\n";
                }
                if (mb_strlen($t) > 3500) { $t .= '…'; break; }
            }
        }
        self::out($chatId, $msgId, $t, [[Tg::btn('🔄 بررسی مجدد', 'adm:health'), Tg::btn('🖥 سرورها', 'adm:pnl')], self::nav([Tg::btn('⬅️ ابزار', 'adm:tools')])]);
    }

    private static function maintenanceToggle($chatId, $msgId, $cbId): void
    {
        $on = (string)DB::setting('maintenance', '0') === '1';
        DB::setSetting('maintenance', $on ? '0' : '1');
        if (class_exists('Audit')) Audit::log('maintenance', ['value' => $on ? '0' : '1']);
        Tg::answerCb($cbId, $on ? '🟢 حالت تعمیر خاموش شد؛ ربات برای همه فعال است' : '🚧 حالت تعمیر روشن شد؛ فقط مدیران دسترسی دارند', true);
        self::toolsHub($chatId, $msgId);
    }

    private static function logs($chatId, $msgId): void
    {
        $f = APP_ROOT . '/storage/logs/app-' . date('Y-m-d') . '.log';
        $t = '📜 <b>لاگ امروز</b> <code>' . h(basename($f)) . '</code>' . "\n" . self::sep();
        if (!is_file($f)) {
            $t .= 'امروز خطایی ثبت نشده است. ✅';
        } else {
            $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $tail  = array_slice($lines, -15);
            $t .= fa_num(count($lines)) . ' خط · ' . fa_num(human_bytes((int)filesize($f))) . ' — ۱۵ خط آخر:' . "\n";
            $body = '';
            foreach (array_reverse($tail) as $ln) {
                $ln = mb_substr((string)$ln, 0, 220);
                if (mb_strlen($body) + mb_strlen($ln) > 3000) break;
                $body .= h($ln) . "\n";
            }
            $t .= '<pre>' . rtrim($body) . '</pre>';
        }
        self::out($chatId, $msgId, $t, [[Tg::btn('🔄 بروزرسانی', 'adm:logs')], self::nav([Tg::btn('⬅️ ابزار', 'adm:tools')])]);
    }

    private static function stuckOrders($chatId, $msgId, $cbId): void
    {
        if (!class_exists('Orders') || !method_exists('Orders', 'recoverStuck')) { Tg::answerCb($cbId, 'ماژول سفارش‌ها در دسترس نیست.', true); return; }
        $r = Orders::recoverStuck();
        Tg::answerCb($cbId, '✅ بررسی شد');
        $t = '🧷 <b>سفارش‌های گیرکرده</b>' . "\n" . self::sep()
           . 'سفارش‌هایی که پرداخت شده اما سرویسشان ساخته نشده، بررسی و در صورت امکان اصلاح می‌شوند.' . "\n" . self::sep()
           . '🔍 بررسی‌شده: ' . fa_num((int)($r['total'] ?? 0)) . "\n"
           . '🔗 وصل‌شده به سرویس: ' . fa_num((int)($r['linked'] ?? 0)) . "\n"
           . '↩️ بازگشت وجه: ' . fa_num((int)($r['refunded'] ?? 0));
        self::out($chatId, $msgId, $t, [[Tg::btn('🔄 بررسی مجدد', 'adm:stuck')], self::nav([Tg::btn('⬅️ ابزار', 'adm:tools')])]);
    }

    /* ============================================================ گزارش‌ها */

    private static function sales7($chatId, $msgId): void
    {
        $rows = DB::all("SELECT DATE(created_at) d, COUNT(*) c, COALESCE(SUM(final_amount),0) s, SUM(type = 'renew') r FROM {p}orders
            WHERE status = 'paid' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(created_at) ORDER BY d ASC");
        $map = [];
        foreach ($rows as $r) $map[(string)$r['d']] = $r;
        $t = '📈 <b>فروش ۷ روز اخیر</b>' . "\n" . self::sep();
        $sumS = 0; $sumC = 0; $max = 0;
        for ($i = 6; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-{$i} days")); $max = max($max, (int)($map[$d]['s'] ?? 0)); }
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $r = $map[$d] ?? ['c' => 0, 's' => 0, 'r' => 0];
            $s = (int)$r['s']; $c = (int)$r['c'];
            $sumS += $s; $sumC += $c;
            $bar = $max > 0 ? str_repeat('▰', (int)round($s / $max * 8)) : '';
            $bar = $bar . str_repeat('▱', 8 - mb_strlen($bar));
            $t .= ($i === 0 ? '🔸' : '▪️') . ' ' . self::jd($d . ' 00:00:00', false) . ' <code>' . $bar . '</code> ' . money($s) . ' (' . fa_num($c) . ' سفارش'
                . ((int)$r['r'] > 0 ? '، ' . fa_num((int)$r['r']) . ' تمدید' : '') . ')' . "\n";
        }
        $t .= self::sep() . '💵 جمع: <b>' . money($sumS) . '</b> ' . currency() . ' در ' . fa_num($sumC) . ' سفارش'
            . ($sumC > 0 ? ' · میانگین هر سفارش: ' . money((int)round($sumS / $sumC)) : '') . "\n";
        $nu = (int)DB::val('SELECT COUNT(*) FROM {p}users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)', [], 0);
        $ns = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND is_test = 0", [], 0);
        $nt = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND is_test = 1", [], 0);
        $t .= '👥 کاربر جدید: ' . fa_num($nu) . '  |  📦 سرویس جدید: ' . fa_num($ns) . '  |  🧪 تست: ' . fa_num($nt);
        self::out($chatId, $msgId, $t, [[Tg::btn('🏆 پرفروش‌ها', 'adm:sttop'), Tg::btn('🖥 آمار سرورها', 'adm:stpnl')], self::nav([Tg::btn('⬅️ آمار', 'adm:stats')])]);
    }

    private static function topProducts($chatId, $msgId): void
    {
        $rows = DB::all("SELECT o.product_id, p.name, COUNT(*) c, COALESCE(SUM(o.final_amount),0) s FROM {p}orders o
            LEFT JOIN {p}products p ON p.id = o.product_id WHERE o.status = 'paid' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY o.product_id, p.name ORDER BY c DESC, s DESC LIMIT 8");
        $t = '🏆 <b>پرفروش‌های ۳۰ روز اخیر</b>' . "\n" . self::sep();
        if (!$rows) $t .= 'در ۳۰ روز اخیر فروشی ثبت نشده است.';
        $i = 0;
        $medal = ['🥇', '🥈', '🥉'];
        foreach ($rows as $r) {
            $t .= ($medal[$i] ?? '▪️') . ' <b>' . h((string)($r['name'] ?? ('محصول #' . (int)$r['product_id']))) . '</b> — ' . fa_num((int)$r['c']) . ' فروش · ' . money((int)$r['s']) . ' ' . currency() . "\n";
            $i++;
        }
        $top = DB::all("SELECT u.first_name, u.tg_id, COUNT(*) c, COALESCE(SUM(o.final_amount),0) s FROM {p}orders o JOIN {p}users u ON u.id = o.user_id
            WHERE o.status = 'paid' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY o.user_id, u.first_name, u.tg_id ORDER BY s DESC LIMIT 5");
        if ($top) {
            $t .= self::sep() . '👑 <b>بهترین خریداران (۳۰ روز)</b>' . "\n";
            foreach ($top as $u) {
                $t .= '• ' . h(mb_substr((string)($u['first_name'] ?? '—'), 0, 18)) . ' (<code>' . (int)$u['tg_id'] . '</code>) — ' . fa_num((int)$u['c']) . ' خرید · ' . money((int)$u['s']) . "\n";
            }
        }
        $renew = (int)DB::val("SELECT COUNT(*) FROM {p}orders WHERE status = 'paid' AND type = 'renew' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", [], 0);
        $newO  = (int)DB::val("SELECT COUNT(*) FROM {p}orders WHERE status = 'paid' AND type = 'new' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", [], 0);
        $t .= self::sep() . '🆕 خرید جدید: ' . fa_num($newO) . '  |  🔁 تمدید: ' . fa_num($renew);
        self::out($chatId, $msgId, $t, [[Tg::btn('📈 فروش ۷ روز', 'adm:st7'), Tg::btn('💳 مالی', 'adm:fin')], self::nav([Tg::btn('⬅️ آمار', 'adm:stats')])]);
    }

    private static function panelStats($chatId, $msgId): void
    {
        $panels = DB::all('SELECT * FROM {p}panels ORDER BY sort ASC, id ASC');
        $stat = DB::all("SELECT panel_id, SUM(status = 'active') a, SUM(status = 'expired') e, SUM(status = 'disabled') d, SUM(is_test = 1) t,
            COALESCE(SUM(CASE WHEN status = 'active' THEN volume_gb ELSE 0 END),0) gb, COALESCE(SUM(CASE WHEN status = 'active' THEN used_bytes ELSE 0 END),0) ub, COUNT(*) n
            FROM {p}services WHERE status <> 'deleted' GROUP BY panel_id");
        $m = [];
        foreach ($stat as $r) $m[(int)$r['panel_id']] = $r;
        $t = '🖥 <b>آمار سرورها</b>' . "\n" . self::sep();
        if (!$panels) $t .= 'سروری ثبت نشده است.';
        $kb = []; $line = [];
        foreach ($panels as $p) {
            $r   = $m[(int)$p['id']] ?? [];
            $cap = (int)($p['user_limit'] ?? 0);
            $a   = (int)($r['a'] ?? 0);
            $t .= ((int)$p['active'] ? '🟢' : '⚪️') . ' <b>' . h((string)$p['name']) . '</b> · ' . h(Xui::typeLabel((string)$p['type'])) . "\n";
            $t .= '   👥 فعال: ' . fa_num($a) . ($cap > 0 ? ' از ' . fa_num($cap) . ' (' . self::pct($a, $cap) . ')' : '')
                . ' · 🔴 ' . fa_num((int)($r['e'] ?? 0)) . ' · ⏸ ' . fa_num((int)($r['d'] ?? 0)) . ' · 🧪 ' . fa_num((int)($r['t'] ?? 0)) . "\n";
            $t .= '   📊 حجم فروخته‌شدهٔ فعال: ' . fa_num((string)round((float)($r['gb'] ?? 0))) . ' گیگ · مصرف: ' . fa_num(human_bytes((int)($r['ub'] ?? 0))) . "\n";
            $line[] = Tg::btn('🖥 ' . mb_substr((string)$p['name'], 0, 18), 'adm:p:' . (int)$p['id']);
            if (count($line) === 2) { $kb[] = $line; $line = []; }
        }
        if ($line) $kb[] = $line;
        $kb[] = [Tg::btn('🖥 وضعیت زندهٔ سرورها', 'adm:pnl'), Tg::btn('📦 سرویس‌ها', 'adm:svcs')];
        $kb[] = self::nav([Tg::btn('⬅️ آمار', 'adm:stats')]);
        self::out($chatId, $msgId, $t, $kb);
    }

    /* ============================================================ حالت‌های متنی */

    public static function handleState($chatId, string $state, string $text, array $msg, array $user, array $data): bool
    {
        switch ($state) {
            /* ---- fixed74: کمپین تخفیف ---- */
            case 'admin_camp_new': {
                $raw   = trim(en_num($text));
                $renew = false;
                if (preg_match('/\+\s*تمدید/u', $raw)) { $renew = true; $raw = (string)preg_replace('/\+\s*تمدید/u', '', $raw); }
                $parts = array_map('trim', explode('|', $raw));
                $pct   = (float)str_replace(['٪', '%', ','], '', (string)($parts[0] ?? ''));
                if ($pct < 1 || $pct > 90) {
                    Tg::send($chatId, '❌ درصد تخفیف باید بین ۱ تا ۹۰ باشد. مثال: <code>30 | 48 | جشنواره</code>', Kb::cancel());
                    return true;
                }
                self::clearState($user);
                $hours = max(0, (int)($parts[1] ?? 0));
                $title = (string)($parts[2] ?? '');
                $max   = max(0, (int)str_replace(',', '', (string)($parts[3] ?? '0')));
                $prods = (string)($parts[4] ?? '');
                Campaign::start($pct, $hours, $title, $max, $renew, $prods);
                if (class_exists('Audit')) Audit::log('campaign.start', ['percent' => $pct, 'hours' => $hours, 'title' => $title, 'max' => $max, 'renew' => $renew, 'products' => $prods]);
                Tg::send($chatId, '✅ <b>کمپین فعال شد</b>' . "\n" . self::sep() . Campaign::adminSummary()
                    . "\n" . self::sep() . '🛒 بنر فروشگاه:' . "\n" . Campaign::banner(), Kb::main(true));
                self::campHub($chatId, null);
                return true;
            }
            /* ---- fixed74: هدیهٔ گروهی ---- */
            case 'admin_bulk_add': {
                $seg    = (string)($data['seg'] ?? 'act');
                $raw    = trim(en_num($text));
                $notify = true;
                if (preg_match('/-\s*بی[\s‌]?صدا/u', $raw)) { $notify = false; $raw = (string)preg_replace('/-\s*بی[\s‌]?صدا/u', '', $raw); }
                $parts = array_map('trim', explode('|', $raw));
                $days  = (int)($parts[0] ?? 0);
                $gb    = (float)str_replace(',', '.', (string)($parts[1] ?? '0'));
                $note  = trim((string)($parts[2] ?? ''));
                if ($days <= 0 && $gb <= 0) {
                    Tg::send($chatId, '❌ حداقل یکی از «روز» یا «گیگ» باید بزرگ‌تر از صفر باشد. مثال: <code>3 | 5</code>', Kb::cancel());
                    return true;
                }
                if ($days > 3650 || $gb > 100000) { Tg::send($chatId, '❌ مقدار خیلی بزرگ است.', Kb::cancel()); return true; }
                $n = Bulk::count($seg);
                if ($n <= 0) { self::clearState($user); Tg::send($chatId, '⚠️ در این دسته سرویسی وجود ندارد.', Kb::main(true)); return true; }
                self::setState('admin_bulk_confirm', ['seg' => $seg, 'days' => $days, 'gb' => $gb, 'note' => mb_substr($note, 0, 300), 'notify' => $notify ? 1 : 0, 'n' => $n]);
                Tg::send($chatId, '🎁 <b>تأیید هدیهٔ گروهی</b>' . "\n" . self::sep()
                    . '📂 دسته: ' . h(Bulk::label($seg)) . "\n"
                    . '👥 تعداد سرویس: <b>' . fa_num($n) . '</b>' . ($n > Bulk::MAX ? ' (حداکثر ' . fa_num(Bulk::MAX) . ' در هر اجرا)' : '') . "\n"
                    . '➕ افزوده: ' . ($days > 0 ? fa_num($days) . ' روز ' : '') . ($gb > 0 ? fa_num((string)$gb) . ' گیگ' : '') . "\n"
                    . '📨 اطلاع به کاربران: ' . ($notify ? 'بله' : 'خیر') . "\n"
                    . ($note !== '' ? '💬 پیام: ' . h($note) . "\n" : '')
                    . "\n⚠️ این عملیات روی پنل اعمال می‌شود و برگشت‌پذیر نیست.",
                    Tg::ikb([[Tg::btn('✅ اعمال کن', 'adm:bulkok'), Tg::btn('❌ لغو', 'adm:bulkno')]]));
                return true;
            }
            case 'admin_bulk_confirm': {
                Tg::send($chatId, 'برای اعمال، دکمهٔ «✅ اعمال کن» را بزنید یا «❌ لغو».', Kb::cancel());
                return true;
            }
            case 'admin_num_set': {
                $k = (string)($data['k'] ?? '');
                if (!isset(self::NUMS[$k])) { self::clearState($user); Tg::send($chatId, '❌ کلید تنظیم نامعتبر است.', Kb::main(true)); return true; }
                $nm = self::NUMS[$k];
                $v  = str_replace([',', '٬', ' '], '', trim(en_num($text)));
                if ($v === '' || !is_numeric($v)) { Tg::send($chatId, '❌ فقط عدد بفرستید. مثال: <code>' . $nm[4] . '</code>', Kb::cancel()); return true; }
                $n = (int)round((float)$v);
                if ($n < $nm[2] || $n > $nm[3]) { Tg::send($chatId, '❌ مقدار باید بین ' . fa_num((string)$nm[2]) . ' و ' . fa_num((string)$nm[3]) . ' باشد.', Kb::cancel()); return true; }
                $pairs = ['cmin' => ['cmax', 'max'], 'cmax' => ['cmin', 'min'], 'dmin' => ['dmax', 'max'], 'dmax' => ['dmin', 'min']];
                if (isset($pairs[$k])) {
                    $o  = self::NUMS[$pairs[$k][0]];
                    $ov = (int)DB::setting($o[0], (string)$o[4]);
                    if (($pairs[$k][1] === 'max' && $n > $ov) || ($pairs[$k][1] === 'min' && $n < $ov)) {
                        Tg::send($chatId, '❌ با مقدار «' . h($o[1]) . '» (' . fa_num($ov) . ') ناسازگار است؛ اول آن را تغییر دهید یا عدد دیگری بفرستید.', Kb::cancel());
                        return true;
                    }
                }
                self::clearState($user);
                DB::setSetting($nm[0], (string)$n);
                if (class_exists('Audit')) Audit::log('setting.number', ['key' => $nm[0], 'label' => $nm[1], 'value' => $n]);
                Tg::send($chatId, '✅ ' . $nm[1] . ' روی <b>' . fa_num($n) . '</b> تنظیم شد.', Kb::main(true));
                AdminBot::quickSettings($chatId, null);
                return true;
            }

            case 'admin_svc_add': {
                $sid = (int)($data['sid'] ?? 0);
                $s   = DB::one('SELECT * FROM {p}services WHERE id = :id', [':id' => $sid]);
                if (!$s) { self::clearState($user); Tg::send($chatId, '❌ سرویس یافت نشد.', Kb::main(true)); return true; }
                $tok = preg_split('/[\s,،]+/', trim(en_num($text))) ?: [];
                if (count($tok) < 1 || $tok[0] === '' || !is_numeric($tok[0]) || (isset($tok[1]) && !is_numeric($tok[1]))) {
                    Tg::send($chatId, '❌ فرمت درست نیست. مثال: <code>30 10</code> (روز و گیگ)', Kb::cancel());
                    return true;
                }
                $days = (int)$tok[0];
                $gb   = isset($tok[1]) ? (float)$tok[1] : 0.0;
                if ($days === 0 && abs($gb) < 0.0001) { Tg::send($chatId, '❌ هر دو عدد صفر است؛ حداقل یکی را وارد کنید.', Kb::cancel()); return true; }
                self::clearState($user);

                $upd  = [];
                $note = [];
                $nowTs  = time();
                $curExp = !empty($s['expire_at']) ? (strtotime((string)$s['expire_at']) ?: 0) : 0;
                if ($days !== 0) {
                    if ($curExp <= 0) {
                        if ($days > 0) { $newExp = $nowTs + $days * 86400; $note[] = 'انقضا از نامحدود به ' . fa_num($days) . ' روز تغییر کرد'; }
                        else { $newExp = 0; }
                    } else {
                        $base   = max($curExp, $nowTs);
                        $newExp = $base + $days * 86400;
                        if ($newExp < $nowTs + 600) $newExp = $nowTs + 600;
                        $note[] = ($days > 0 ? fa_num($days) . ' روز اضافه شد' : fa_num(abs($days)) . ' روز کم شد');
                    }
                    if ($newExp > 0) $upd['expire_at'] = date('Y-m-d H:i:s', $newExp);
                }
                $curVol = (float)$s['volume_gb'];
                if (abs($gb) >= 0.0001) {
                    if ($curVol <= 0 && $gb > 0) {
                        $note[] = 'حجم نامحدود بود؛ بدون تغییر ماند';
                    } else {
                        $newVol = max(0.0, round($curVol + $gb, 2));
                        if ($newVol <= 0 && $gb < 0) $newVol = 0.01;
                        $upd['volume_gb'] = $newVol;
                        $note[] = ($gb > 0 ? fa_num((string)$gb) . ' گیگ اضافه شد' : fa_num((string)abs($gb)) . ' گیگ کم شد') . ' (اکنون ' . fa_num((string)$newVol) . ' گیگ)';
                    }
                }
                $expTs = isset($upd['expire_at']) ? strtotime($upd['expire_at']) : $curExp;
                $volN  = isset($upd['volume_gb']) ? (float)$upd['volume_gb'] : $curVol;
                $usedGb = (float)bytes2gb((int)$s['used_bytes'], 4);
                $revive = in_array((string)$s['status'], ['expired', 'disabled', 'missing'], true)
                    && ($expTs <= 0 || $expTs > $nowTs) && ($volN <= 0 || $usedGb < $volN);
                if ($revive) { $upd['status'] = 'active'; $note[] = 'سرویس دوباره فعال شد'; }
                if ($upd) {
                    $upd['notified'] = 0;
                    if (Svc::hasExpiredAt()) $upd['expired_at'] = null;
                    DB::update('services', $upd, 'id = :id', [':id' => $sid]);
                }
                $s2 = Svc::find($sid) ?: array_merge($s, $upd);
                $pushed = false;
                try { $pushed = Svc::pushLimits($s2); } catch (\Throwable $e) { app_log('admin', 'svc_add push: ' . $e->getMessage(), ['svc' => $sid]); }

                $rep = '♻️ <b>سرویس <code>' . h((string)$s['client_email']) . '</code> به‌روز شد</b>' . "\n" . self::sep();
                foreach ($note as $n) $rep .= '• ' . $n . "\n";
                $rep .= '📅 انقضای جدید: ' . (!empty($s2['expire_at']) ? self::jd((string)$s2['expire_at']) : 'نامحدود') . "\n";
                $rep .= '📊 حجم: ' . ((float)$s2['volume_gb'] > 0 ? fa_num((string)(float)$s2['volume_gb']) . ' گیگ' : 'نامحدود') . "\n";
                $rep .= $pushed ? '✅ روی پنل اعمال شد.' : '⚠️ ثبت شد اما اعمال روی پنل ناموفق بود؛ «همگام‌سازی با سرور» را بزنید.';
                Tg::send($chatId, $rep, Kb::main(true));
                Tg::send($chatId, '👇 کارت سرویس', Tg::ikb([[Tg::btn('📦 کارت سرویس', 'adm:s:' . $sid)]]));
                try {
                    Tg::send((int)$s['tg_id'], '🎁 مدیریت سرویس <code>' . h((string)$s['client_email']) . '</code> شما را به‌روز کرد:' . "\n"
                        . implode("\n", array_map(static fn($n) => '• ' . $n, $note)) . "\n"
                        . '📅 انقضا: ' . (!empty($s2['expire_at']) ? self::jd((string)$s2['expire_at']) : 'نامحدود'));
                } catch (\Throwable $e) { }
                if (class_exists('Logs')) {
                    try { Logs::send('svc', Logs::fmt('♻️ افزودن روز/گیگ توسط مدیر', ['مدیر' => '<code>' . (int)$user['tg_id'] . '</code>', 'سرویس' => '<code>' . h((string)$s['client_email']) . '</code>', 'تغییر' => h(implode('، ', $note))])); } catch (\Throwable $e) { }
                }
                return true;
            }

            case 'admin_dc_new':
            case 'admin_gf_new': {
                $gift = $state === 'admin_gf_new';
                $tok  = preg_split('/\s+/', trim(en_num(str_replace('٪', '%', $text)))) ?: [];
                if (count($tok) < 2) { Tg::send($chatId, '❌ حداقل کد و مقدار لازم است. مثال: <code>- ' . ($gift ? '100000 1' : '20% 100') . '</code>', Kb::cancel()); return true; }
                $code = strtoupper(trim($tok[0]));
                if ($code === '-' || $code === '*' || $code === '') {
                    $code = class_exists('Codes') ? Codes::generate($gift ? 'GIFT' : 'OFF') : strtoupper(($gift ? 'GIFT-' : 'OFF-') . substr(bin2hex(random_bytes(4)), 0, 8));
                }
                if (!preg_match('/^[A-Z0-9_\-]{3,40}$/', $code)) { Tg::send($chatId, '❌ کد فقط می‌تواند حروف انگلیسی، عدد، خط تیره و زیرخط باشد.', Kb::cancel()); return true; }
                $raw  = (string)$tok[1];
                $isPct = !$gift && str_ends_with($raw, '%');
                $val  = (int)round((float)rtrim($raw, '%'));
                if ($val <= 0 || ($isPct && $val > 100)) { Tg::send($chatId, '❌ مقدار نامعتبر است.', Kb::cancel()); return true; }
                $max  = isset($tok[2]) ? max(0, (int)$tok[2]) : ($gift ? 1 : 0);
                $days = isset($tok[3]) ? max(0, (int)$tok[3]) : 0;
                $tbl  = $gift ? 'gift_codes' : 'discount_codes';
                if (DB::one('SELECT id FROM {p}' . $tbl . ' WHERE code = :c', [':c' => $code])) { Tg::send($chatId, '❌ این کد قبلاً وجود دارد؛ کد دیگری بفرستید.', Kb::cancel()); return true; }
                self::clearState($user);
                $exp = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
                if ($gift) {
                    DB::insert('gift_codes', ['code' => $code, 'amount' => $val, 'max_uses' => max(1, $max), 'used' => 0, 'expires_at' => $exp, 'active' => 1, 'created_at' => now()]);
                    $desc = money($val) . ' ' . currency() . ' شارژ کیف پول';
                } else {
                    DB::insert('discount_codes', ['code' => $code, 'type' => $isPct ? 'percent' : 'amount', 'value' => $val, 'max_uses' => $max, 'used' => 0,
                        'per_user' => 1, 'min_amount' => 0, 'product_id' => null, 'expires_at' => $exp, 'active' => 1, 'created_at' => now()]);
                    $desc = $isPct ? fa_num($val) . '٪ تخفیف' : money($val) . ' ' . currency() . ' تخفیف';
                }
                $rep = ($gift ? '🎁 <b>کد هدیه ساخته شد</b>' : '🎟 <b>کد تخفیف ساخته شد</b>') . "\n" . self::sep()
                    . '🔑 کد: <code>' . h($code) . '</code>' . "\n"
                    . '💠 ' . $desc . "\n"
                    . '🔢 سقف استفاده: ' . ($max > 0 ? fa_num($max) : ($gift ? fa_num(1) : 'نامحدود')) . "\n"
                    . '📅 اعتبار: ' . ($exp ? 'تا ' . self::jd($exp) : 'بدون محدودیت');
                Tg::send($chatId, $rep, Kb::main(true));
                Tg::send($chatId, '👇', Tg::ikb([[Tg::btn($gift ? '🎁 کدهای هدیه' : '🎟 کدهای تخفیف', $gift ? 'adm:gifts:1' : 'adm:codes:1'), Tg::btn('💳 مالی', 'adm:fin')]]));
                return true;
            }

            case 'admin_bc_seg': {
                $seg = (string)($data['seg'] ?? '');
                $d   = self::BSEG[$seg] ?? (AdminBot::USEG[$seg] ?? null);
                self::clearState($user);
                if (!$d) { Tg::send($chatId, '❌ گروه نامعتبر است.', Kb::main(true)); return true; }
                if (trim($text) === '') { Tg::send($chatId, '❌ متن پیام خالی است.', Kb::main(true)); return true; }
                $ids = DB::all('SELECT tg_id FROM {p}users WHERE is_banned = 0 AND (' . $d[2] . ') ORDER BY id ASC LIMIT 5000', AdminBot::usegParams($d[2]));
                $ok = 0; $fail = 0;
                foreach ($ids as $row) {
                    $r = Tg::send((int)$row['tg_id'], $text);
                    if (!empty($r['ok'])) $ok++; else $fail++;
                    usleep(40000);
                }
                Tg::send($chatId, '🎯 ارسال به گروه «' . $d[0] . ' ' . h($d[1]) . "» پایان یافت.\n✅ موفق: " . fa_num($ok) . "\n❌ ناموفق: " . fa_num($fail), Kb::main(true));
                if (class_exists('Logs')) {
                    try {
                        Logs::send('broadcast', Logs::fmt('🎯 پیام گروهی', [
                            'مدیر'       => '<code>' . (int)$user['tg_id'] . '</code>',
                            'گروه'       => h($d[1]),
                            'ارسال موفق' => fa_num((string)$ok),
                            'ناموفق'     => fa_num((string)$fail),
                        ], h(mb_substr($text, 0, 300))));
                    } catch (\Throwable $e) { }
                }
                return true;
            }
        }
        return false;
    }

    /* ============================================================ رشد و فروش (fixed74) */

    private static function growHub($chatId, $msgId): void
    {
        $camp  = class_exists('Campaign') ? Campaign::active() : null;
        $arnOn = class_exists('AutoRenew') && AutoRenew::enabled();
        $refOn = class_exists('Referral') && Referral::enabled();
        $t = '🚀 <b>رشد و فروش</b>' . "\n" . self::sep()
           . '🔥 کمپین تخفیف: ' . ($camp ? '<b>فعال</b> — ' . h((string)$camp['title']) . ' (' . fa_num((string)$camp['percent']) . '٪)' : '⚪️ غیرفعال') . "\n"
           . '🔁 تمدید خودکار از کیف پول: ' . ($arnOn ? '🟢 روشن' : '⚪️ خاموش') . "\n"
           . '🎯 سیستم معرفی: ' . ($refOn ? '🟢 روشن' : '⚪️ خاموش') . "\n"
           . self::sep()
           . 'ابزارهای افزایش فروش و نگه‌داشت کاربران — همه از همین‌جا مدیریت می‌شوند.';
        $rows = [
            [Tg::btn('🔥 کمپین تخفیف', 'adm:camp'), Tg::btn('🎁 هدیهٔ گروهی', 'adm:bulk')],
            [Tg::btn('🔁 تمدید خودکار', 'adm:arn'), Tg::btn('🎯 سیستم معرفی', 'adm:ref')],
            [Tg::btn('📤 خروجی CSV / اکسل', 'adm:csv'), Tg::btn('🎟 کدهای تخفیف', 'adm:codes:1')],
            [Tg::btn('📈 فروش ۷ روز اخیر', 'adm:st7'), Tg::btn('🏆 پرفروش‌ها', 'adm:sttop')],
            self::nav(),
        ];
        self::out($chatId, $msgId, $t, $rows);
    }

    private static function campHub($chatId, $msgId): void
    {
        $c = Campaign::active();
        $t = '🔥 <b>کمپین تخفیف زمان‌دار</b>' . "\n" . self::sep() . Campaign::adminSummary() . "\n" . self::sep()
           . 'کمپین روی همهٔ خریدها (ربات و مینی‌اپ) خودکار اعمال می‌شود و بنر آن در فروشگاه نمایش داده می‌شود. اگر کاربر کد تخفیف هم داشته باشد، «بیشترین تخفیف» اعمال می‌شود (جمع نمی‌شود).';
        $rows = [];
        if ($c) {
            $rows[] = [Tg::btn('⏹ توقف کمپین', 'adm:campstop'), Tg::btn('🔄 کمپین جدید (جایگزین)', 'adm:campnew')];
            $rows[] = [Tg::btn((!empty($c['renew']) ? '🟢' : '⚪️') . ' اعمال روی تمدید', 'adm:camprn')];
        } else {
            $rows[] = [Tg::btn('▶️ شروع کمپین جدید', 'adm:campnew')];
        }
        $rows[] = [Tg::btn('🎟 کدهای تخفیف', 'adm:codes:1'), Tg::btn('🔄 بروزرسانی', 'adm:camp')];
        $rows[] = self::nav([Tg::btn('⬅️ رشد و فروش', 'adm:grow')]);
        self::out($chatId, $msgId, $t, $rows);
    }

    private static function bulkHub($chatId, $msgId): void
    {
        $t = '🎁 <b>هدیه / تمدید گروهی</b>' . "\n" . self::sep()
           . "به یک دسته از سرویس‌ها روز و/یا گیگ اضافه کنید؛ تغییرات روی پنل اعمال و به کاربران اطلاع داده می‌شود.\n"
           . "سرویس‌های منقضی با دریافت هدیه دوباره فعال می‌شوند. سرویس‌های نامحدود فقط زمان می‌گیرند.\n\n"
           . 'دسته را انتخاب کنید:';
        $rows = [];
        $line = [];
        foreach (Bulk::segments() as $k => $m) {
            $line[] = Tg::btn($m[0] . ' (' . fa_num(Bulk::count($k)) . ')', 'adm:bulkseg:' . $k);
            if (count($line) === 2) { $rows[] = $line; $line = []; }
        }
        if ($line) $rows[] = $line;
        /* به تفکیک سرور */
        $panels = DB::all('SELECT id, name FROM {p}panels WHERE active = 1 ORDER BY sort ASC, id ASC LIMIT 12');
        $line = [];
        foreach ($panels as $p) {
            $line[] = Tg::btn('🖥 ' . mb_substr((string)$p['name'], 0, 16) . ' (' . fa_num(Bulk::count('panel:' . (int)$p['id'])) . ')', 'adm:bulkseg:panel:' . (int)$p['id']);
            if (count($line) === 2) { $rows[] = $line; $line = []; }
        }
        if ($line) $rows[] = $line;
        $rows[] = self::nav([Tg::btn('⬅️ رشد و فروش', 'adm:grow')]);
        self::out($chatId, $msgId, $t, $rows);
    }

    private static function arnHub($chatId, $msgId): void
    {
        $on = AutoRenew::enabled();
        $st = AutoRenew::stats();
        $tp = AutoRenew::trafficPercent();
        $t = '🔁 <b>تمدید خودکار از کیف پول</b>' . "\n" . self::sep()
           . 'وضعیت: ' . ($on ? '🟢 روشن' : '⚪️ خاموش') . "\n"
           . '⏱ زمان اقدام: ' . fa_num(AutoRenew::hours()) . ' ساعت قبل از انقضا' . ($tp > 0 ? ' یا ' . fa_num($tp) . '٪ مصرف حجم' : '') . "\n"
           . '👥 کاربرانی که فعال کرده‌اند: <b>' . fa_num((int)$st['users_on']) . '</b>' . "\n"
           . '✅ تمدیدهای خودکار ۳۰ روز اخیر: ' . fa_num((int)$st['renewed_30d']) . ' (' . money((int)$st['sum_30d']) . ' ' . currency() . ')' . "\n"
           . '👛 سرویس‌های منتظر شارژ کاربر: ' . fa_num((int)$st['nofunds']) . "\n"
           . '⏰ یادآوری مرحلهٔ دوم: ' . (AutoRenew::stage2Days() > 0 ? fa_num(AutoRenew::stage2Days()) . ' روز قبل از انقضا' : 'خاموش') . "\n"
           . self::sep()
           . 'کاربر از «👤 حساب کاربری» تمدید خودکار را روشن می‌کند؛ ربات پیش از انقضا با همان طرح (یا ارزان‌ترین طرح همان سرور) از کیف پول تمدید می‌کند. اگر موجودی کم باشد یک بار اطلاع می‌دهد و در اجرای بعدی دوباره تلاش نمی‌کند تا شارژ شود.';
        $rows = [
            [Tg::btn($on ? '⚪️ خاموش کردن' : '🟢 روشن کردن', 'adm:arntog'), Tg::btn('▶️ اجرای دستی اکنون', 'adm:arnrun')],
            [Tg::btn('⏱ ساعت اقدام', 'adm:num:arnh'), Tg::btn('📉 درصد حجم', 'adm:num:arnt')],
            [Tg::btn('⏰ یادآوری مرحلهٔ ۱ (روز)', 'adm:num:expn'), Tg::btn('🚨 یادآوری مرحلهٔ ۲ (روز)', 'adm:num:expn2')],
            self::nav([Tg::btn('⬅️ رشد و فروش', 'adm:grow')]),
        ];
        self::out($chatId, $msgId, $t, $rows);
    }

    private static function refHub($chatId, $msgId): void
    {
        $on  = Referral::enabled();
        $ov  = Referral::overview();
        $top = Referral::top(5);
        $t = '🎯 <b>سیستم معرفی (رفرال)</b>' . "\n" . self::sep()
           . 'وضعیت: ' . ($on ? '🟢 روشن' : '⚪️ خاموش') . "\n"
           . '🎁 پاداش اولین شارژ: ' . fa_num((string)Referral::bonus()) . '٪ | پورسانت سطح ۱: ' . fa_num((string)Referral::percent()) . '٪ | سطح ۲: ' . fa_num((string)Referral::percentL2()) . '٪' . "\n"
           . '👥 کاربران معرفی‌شده: <b>' . fa_num((int)$ov['linked']) . '</b> توسط <b>' . fa_num((int)$ov['referrers']) . '</b> معرف' . "\n"
           . '💸 پورسانت پرداخت‌شده: ' . h((string)$ov['paid_txt']) . ' ' . currency() . "\n";
        if ($top) {
            $t .= self::sep() . '🏆 <b>برترین معرف‌ها</b>' . "\n";
            $i = 0;
            foreach ($top as $r) {
                $i++;
                $nm = trim((string)($r['name'] ?? '')) ?: ('#' . (int)($r['tg_id'] ?? 0));
                $t .= fa_num($i) . '. ' . h(mb_substr($nm, 0, 24)) . ' — ' . fa_num((int)($r['invites'] ?? 0)) . ' دعوت | ' . money((int)($r['earned'] ?? 0)) . "\n";
            }
        }
        $t .= self::sep() . 'درصدها و سقف‌ها از «🌐 تنظیمات کامل (وب) ← ربات» تغییر می‌کنند. لینک دعوت هر کاربر در «👤 حساب کاربری» و دکمهٔ «🎯 معرفی به دوستان» است.';
        $rows = [
            [Tg::btn($on ? '⚪️ خاموش کردن' : '🟢 روشن کردن', 'adm:reftog'), Tg::btn('🌐 تنظیمات وب', 'adm:web')],
            self::nav([Tg::btn('⬅️ رشد و فروش', 'adm:grow')]),
        ];
        self::out($chatId, $msgId, $t, $rows);
    }

    private static function csvHub($chatId, $msgId): void
    {
        $t = '📤 <b>خروجی CSV / اکسل</b>' . "\n" . self::sep()
           . "فایل با کدگذاری UTF-8 (سازگار با Excel) ساخته و همین‌جا ارسال می‌شود.\n"
           . 'حداکثر ' . fa_num(Export::MAX_ROWS) . " ردیف در هر فایل.\n\n"
           . 'گزینهٔ «۳۰ روز» فقط رکوردهای یک ماه اخیر را شامل می‌شود.';
        $rows = [];
        foreach (Export::kinds() as $k => $m) {
            if (in_array($k, ['users', 'services', 'orders', 'transactions'], true)) {
                $rows[] = [Tg::btn($m[0] . ' ' . $m[1] . ' — همه', 'adm:csv:' . $k . ':0'), Tg::btn('🗓 ۳۰ روز اخیر', 'adm:csv:' . $k . ':30')];
            } else {
                $rows[] = [Tg::btn($m[0] . ' ' . $m[1], 'adm:csv:' . $k . ':0')];
            }
        }
        $rows[] = self::nav([Tg::btn('⬅️ رشد و فروش', 'adm:grow')]);
        self::out($chatId, $msgId, $t, $rows);
    }

    /* ============================================================ fixed75: لاگ اقدامات مدیران */

    private static function auditHub($chatId, $msgId, int $page = 1): void
    {
        $page  = max(1, $page);
        $per   = 10;
        $st    = Audit::stats();
        $total = Audit::count();
        $pages = max(1, (int)ceil($total / $per));
        if ($page > $pages) $page = $pages;
        $rows  = Audit::recent($per, '', '', 0, $page);

        $t = '📝 <b>لاگ اقدامات مدیران</b>' . "\n" . self::sep()
           . '📅 امروز: <b>' . fa_num($st['today']) . '</b> | ۷ روز: <b>' . fa_num($st['week']) . '</b> | کل: ' . fa_num($st['total']) . "\n"
           . ($st['top'] !== '' ? '🏆 فعال‌ترین (۷ روز): ' . h(Audit::actorLabel($st['top'])) . ' (' . fa_num($st['top_n']) . ')' . "\n" : '')
           . self::sep();
        if (!$rows) {
            $t .= 'هنوز اقدامی ثبت نشده است. از این نسخه هر کار مدیران (پنل وب، ربات، کرون) اینجا ثبت می‌شود.';
        } else {
            foreach ($rows as $r) {
                $meta = Audit::metaLine((string)($r['meta'] ?? ''), 90);
                $t .= '🕒 <code>' . h(to_jalali((string)$r['created_at'], true)) . '</code> — ' . h(Audit::actorLabel((string)$r['actor'])) . "\n"
                    . ' ' . h(Audit::label((string)$r['action']))
                    . ($meta !== '' ? "\n <i>" . h($meta) . '</i>' : '') . "\n\n";
            }
            $t .= self::sep() . 'صفحهٔ ' . fa_num($page) . ' از ' . fa_num($pages) . ' • نسخهٔ کامل با فیلتر و جستجو: پنل وب › نگهداری سیستم › لاگ اقدامات';
        }
        if (mb_strlen($t) > 3900) $t = mb_substr($t, 0, 3900) . '…';

        $pg = [];
        if ($page > 1)      $pg[] = Tg::btn('◀️ جدیدتر', 'adm:audit:' . ($page - 1));
        if ($page < $pages) $pg[] = Tg::btn('قدیمی‌تر ▶️', 'adm:audit:' . ($page + 1));
        $btns = [];
        if ($pg) $btns[] = $pg;
        $btns[] = [Tg::btn('🔄 تازه‌سازی', 'adm:audit:' . $page), Tg::btn('🧪 مسدودی‌های تست', 'adm:test')];
        $btns[] = self::nav([Tg::btn('⬅️ ابزارها', 'adm:tools')]);
        self::out($chatId, $msgId, $t, $btns);
    }

    /* ============================================================ fixed75: اکانت تست و ضدسوءاستفاده */

    private static function testHub($chatId, $msgId): void
    {
        $on  = (string)DB::setting('test_enabled', '1') === '1';
        $pc  = (string)DB::setting('test_panel_check', '1') === '1';
        $pu  = (string)DB::setting('test_phone_unique', '1') === '1';
        $lb  = (string)DB::setting('test_log_block', '1') === '1';
        $cd  = (int)DB::setting('test_cooldown_days', '0');
        $age = (int)DB::setting('test_min_age_hours', '0');
        $max = (int)DB::setting('test_global_max', '0');

        $today = (int)DB::val('SELECT COUNT(*) FROM {p}services WHERE is_test = 1 AND created_at >= CURDATE()');
        $week  = (int)DB::val('SELECT COUNT(*) FROM {p}services WHERE is_test = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)');
        $blocked = 0; $why = [];
        try {
            $blocked = (int)DB::val("SELECT COUNT(*) FROM {p}logs WHERE action = 'test.blocked' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
            foreach (DB::all("SELECT meta FROM {p}logs WHERE action = 'test.blocked' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY id DESC LIMIT 300") as $l) {
                $m = jdec((string)$l['meta'], []);
                $k = (string)($m['why'] ?? '?');
                $why[$k] = ($why[$k] ?? 0) + 1;
            }
        } catch (\Throwable $e) { }
        $whyLbl = ['panel_limit' => 'سقف سرور', 'global_max' => 'سقف کل', 'cooldown' => 'فاصلهٔ زمانی', 'min_age' => 'حساب تازه', 'phone_dup' => 'شمارهٔ تکراری', 'panel_tgid' => 'سابقه در پنل'];
        $whyTxt = '';
        foreach ($why as $k => $n) $whyTxt .= ' • ' . ($whyLbl[$k] ?? $k) . ': ' . fa_num($n);

        $panels = DB::all('SELECT name, test_limit, test_volume_gb, test_days, test_hours FROM {p}panels WHERE active = 1 AND test_enabled = 1 ORDER BY sort ASC, id ASC');

        $t = '🧪 <b>اکانت تست و ضدسوءاستفاده</b>' . "\n" . self::sep()
           . '📊 تست امروز: <b>' . fa_num($today) . '</b> | ۷ روز: <b>' . fa_num($week) . '</b>' . "\n"
           . '🛡 جلوگیری‌شده (۷ روز): <b>' . fa_num($blocked) . '</b>' . ($whyTxt !== '' ? "\n<i>" . $whyTxt . '</i>' : '') . "\n"
           . self::sep()
           . '🔒 <b>قواعد</b>' . "\n"
           . '• اکانت تست: ' . ($on ? 'روشن ✅' : 'خاموش ❌') . "\n"
           . '• سقف هر سرور: از تنظیمات همان سرور (پنل وب › سرورها)' . "\n"
           . '• سقف کل هر کاربر: ' . ($max > 0 ? fa_num($max) . ' بار' : 'بی‌نهایت') . "\n"
           . '• فاصلهٔ دو تست: ' . ($cd > 0 ? fa_num($cd) . ' روز' : 'بدون محدودیت') . "\n"
           . '• حداقل عمر حساب: ' . ($age > 0 ? fa_num($age) . ' ساعت' : 'خاموش') . "\n"
           . '• بررسی سابقه در پنل با آیدی تلگرام (3x-ui): ' . ($pc ? '✅' : '❌') . "\n"
           . '• هر شمارهٔ موبایل فقط یک تست: ' . ($pu ? '✅' : '❌') . "\n"
           . '• ثبت تلاش‌های مسدودشده در لاگ: ' . ($lb ? '✅' : '❌') . "\n"
           . self::sep() . '🖥 <b>سرورهای تست‌دار</b>' . "\n";
        if (!$panels) $t .= 'هیچ سروری برای تست فعال نیست (پنل وب › سرورها › اکانت تست).' . "\n";
        foreach ($panels as $p) {
            $dur = (int)$p['test_hours'] > 0 ? fa_num((int)$p['test_hours']) . ' ساعت' : fa_num((int)$p['test_days']) . ' روز';
            $t .= '• ' . h((string)$p['name']) . ' — ' . fa_num((string)(float)$p['test_volume_gb']) . ' گیگ / ' . $dur . ' / سقف ' . fa_num((int)$p['test_limit']) . "\n";
        }
        $t .= self::sep() . '<i>۰ = خاموش. برای جلوگیری از اکانت‌های تلگرام تکراری، «تایید شماره» را در امنیت فعال کنید تا قاعدهٔ «هر شماره یک تست» اثر کند.</i>';

        $rows = [
            [Tg::btn(($on ? '🟢' : '🔴') . ' اکانت تست', 'adm:tsttog:test_enabled'), Tg::btn(($pc ? '🟢' : '🔴') . ' بررسی سابقه در پنل', 'adm:tsttog:test_panel_check')],
            [Tg::btn(($pu ? '🟢' : '🔴') . ' هر شماره یک تست', 'adm:tsttog:test_phone_unique'), Tg::btn(($lb ? '🟢' : '🔴') . ' لاگ مسدودی‌ها', 'adm:tsttog:test_log_block')],
            [Tg::btn('⏳ فاصلهٔ دو تست', 'adm:num:tcd'), Tg::btn('👶 حداقل عمر حساب', 'adm:num:tage')],
            [Tg::btn('🔢 سقف کل هر کاربر', 'adm:num:tmax'), Tg::btn('📝 لاگ اقدامات', 'adm:audit')],
            self::nav([Tg::btn('⬅️ ابزارها', 'adm:tools')]),
        ];
        self::out($chatId, $msgId, $t, $rows);
    }
}
