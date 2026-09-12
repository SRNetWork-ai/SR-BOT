<?php
declare(strict_types=1);

/**
 * بخش مدیریت درون ربات (تایید پرداخت، اطلاعیه، افزودن موجودی، ورود به پنل وب)
 */
class AdminBot
{
    public static function adminIds(): array
    {
        $ids = array_map('strval', (array)cfg('bot.admins', []));
        $extra = (string)DB::setting('extra_admins', '');
        if (trim($extra) !== '') {
            foreach (explode(',', $extra) as $x) {
                $x = trim(en_num($x));
                if ($x !== '') $ids[] = $x;
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    public static function notifyAdmins(string $text, $keyboard = null): void
    {
        foreach (self::adminIds() as $id) Tg::send($id, $text, $keyboard);
    }

    /* ---------------- منوی مدیریت ---------------- */

    public static function home($chatId, $msgId = null): void
    {
        $pending = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE status = 'pending'", [], 0);
        $tickets = (int)DB::val("SELECT COUNT(*) FROM {p}tickets WHERE status = 'open'", [], 0);
        $cardsPd = class_exists('CardAuth') ? CardAuth::pendingCount() : 0;
        $activeN = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE status = 'active'", [], 0);
        $exp3    = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE status = 'active' AND expire_at IS NOT NULL AND expire_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)", [], 0);
        $sToday  = (int)DB::val("SELECT COALESCE(SUM(final_amount),0) FROM {p}orders WHERE status = 'paid' AND DATE(created_at) = CURDATE()", [], 0);
        $hub     = class_exists('AdminHub');
        $finN    = $pending + $cardsPd;

        /* کارهای فوری: فقط وقتی موردی منتظر است نمایش داده می‌شوند */
        $rows   = [];
        $urgent = [];
        if ($pending) $urgent[] = Tg::btn('💳 پرداخت در انتظار (' . fa_num($pending) . ')', 'adm:pay');
        if ($cardsPd) $urgent[] = Tg::btn('🪪 احراز کارت (' . fa_num($cardsPd) . ')', 'adm:crd');
        if ($tickets) $urgent[] = Tg::btn('🎫 تیکت باز (' . fa_num($tickets) . ')', 'adm:tk');
        foreach (array_chunk($urgent, 2) as $chunk) $rows[] = $chunk;

        /* منوی دسته‌بندی‌شده */
        $rows[] = [Tg::btn('📊 آمار و گزارش', 'adm:stats'), Tg::btn('💳 مالی' . ($finN ? ' (' . fa_num($finN) . ')' : ''), $hub ? 'adm:fin' : 'adm:pay')];
        $rows[] = [Tg::btn('👥 کاربران', 'adm:usr'), Tg::btn('📦 سرویس‌ها' . ($exp3 ? ' (⏳' . fa_num($exp3) . ')' : ''), $hub ? 'adm:svcs' : 'adm:svc')];
        $rows[] = [Tg::btn('🖥 سرورها', 'adm:pnl'), Tg::btn('🏪 انبار ملی', 'adm:stk')];
        $rows[] = [Tg::btn('📣 ارتباط و پشتیبانی' . ($tickets ? ' (' . fa_num($tickets) . ')' : ''), $hub ? 'adm:comm' : 'adm:tk'), Tg::btn('⚙️ تنظیمات سریع', 'adm:set')];
        $rows[] = [Tg::btn('🧰 ابزار و نگهداری', $hub ? 'adm:tools' : 'adm:bkp'), Tg::btn('🌐 پنل مدیریت تحت وب', 'adm:web')];
        /* fixed74: رشد و فروش (کمپین، هدیهٔ گروهی، تمدید خودکار، معرفی، CSV) */
        if ($hub) {
            $campOn = class_exists('Campaign') && Campaign::active() !== null;
            $rows[] = [Tg::btn('🚀 رشد و فروش' . ($campOn ? ' 🔥' : ''), 'adm:grow')];
        }
        /* دکمه‌های سفارشی زیرمنوی پنل مدیریت */
        if (class_exists('Btn')) {
            try {
                /* fixed76: دکمهٔ «پنل وب» و «پنل مدیریت» بالاتر به‌صورت ثابت هستند → تکرار نشوند */
                foreach (Btn::subRows('admin', true, false, ['admin_web', 'admin']) as $extra) {
                    if (is_array($extra) && $extra) $rows[] = $extra;
                }
            } catch (\Throwable $e) { }
        }

        $ver = defined('APP_VERSION') ? (string)APP_VERSION : '-';
        $txt = "🛠 <b>پنل مدیریت</b>\n"
            . "<code>─────────────────</code>\n"
            . '🟢 سرویس فعال: <b>' . fa_num($activeN) . '</b>  |  ⏳ رو به انقضا (۳ روز): <b>' . fa_num($exp3) . "</b>\n"
            . '📈 فروش امروز: <b>' . money($sToday) . '</b> ' . currency() . "\n"
            . '💳 پرداخت در انتظار: ' . fa_num($pending) . '  |  🪪 احراز کارت: ' . fa_num($cardsPd) . '  |  🎫 تیکت باز: ' . fa_num($tickets) . "\n"
            . "<code>─────────────────</code>\n"
            . "بخش‌ها دسته‌بندی شده‌اند؛ ساخت/ویرایش محصول، سرور، آموزش و تنظیمات پیشرفته همچنان از پنل تحت وب انجام می‌شود.\n"
            . '🤖 نسخهٔ ربات: <b>' . h($ver) . "</b>\n"
            . "🏷 این ربات متعلق به مجموعهٔ <b>SR-BOT</b> است.\n"
            . "© تمامی حقوق محفوظ است؛ هرگونه <b>فروش، بازفروش یا سوءاستفاده</b> از این سورس ممنوع است.";
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    public static function callback($chatId, $msgId, $cbId, string $arg, string $arg2): void
    {
        switch ($arg) {
            case 'home': Tg::answerCb($cbId); self::home($chatId, $msgId); return;

            /* ---------- مدیریت کاربران ---------- */
            case 'usr':
                Tg::answerCb($cbId);
                self::usersHub($chatId, $msgId);
                return;

            case 'ufind':
                Tg::answerCb($cbId);
                self::askUser($chatId);
                return;

            case 'useg': {
                Tg::answerCb($cbId);
                $pp = explode(':', $arg2);
                self::usersList($chatId, $msgId, (string)($pp[0] ?? 'all'), (int)($pp[1] ?? 1));
                return;
            }

            case 'u':
                Tg::answerCb($cbId);
                self::userCard($chatId, $msgId, (int)$arg2);
                return;

            case 'usvc':
                Tg::answerCb($cbId);
                self::userServices($chatId, $msgId, (int)$arg2);
                return;

            case 'uban':
            case 'uunb': {
                $uid = (int)$arg2;
                $ban = $arg === 'uban' ? 1 : 0;
                DB::update('users', ['is_banned' => $ban], 'id = :id', [':id' => $uid]);
                if (class_exists('Audit')) Audit::log($ban ? 'user.ban' : 'user.unban', ['uid' => $uid]);
                Tg::answerCb($cbId, $ban ? '⛔️ کاربر مسدود شد.' : '✅ مسدودی برداشته شد.', true);
                self::userCard($chatId, $msgId, $uid);
                return;
            }

            case 'ubal': {
                Tg::answerCb($cbId);
                $uid = (int)$arg2;
                $t2  = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => $uid]);
                if (!$t2) return;
                DB::update('users', ['state' => 'admin_bal_amount', 'state_data' => jenc(['uid' => $uid])],
                    'id = :id', [':id' => (int)(Bot::$u['id'] ?? 0)]);
                Tg::send($chatId, '💰 مبلغ را وارد کنید (منفی برای کسر):' . "\n"
                    . 'کاربر: ' . h((string)$t2['first_name']) . "\n"
                    . 'موجودی فعلی: ' . fa_num(number_format((int)$t2['balance'])) . ' ' . currency(), Kb::cancel());
                return;
            }

            case 'umsg': {
                Tg::answerCb($cbId);
                $uid = (int)$arg2;
                DB::update('users', ['state' => 'admin_dm_text', 'state_data' => jenc(['uid' => $uid])],
                    'id = :id', [':id' => (int)(Bot::$u['id'] ?? 0)]);
                Tg::send($chatId, '✉️ متن پیام را بفرستید تا مستقیم برای کاربر ارسال شود:', Kb::cancel());
                return;
            }

            /* ---------- مدیریت سرویس‌ها ---------- */
            case 'svc':
                Tg::answerCb($cbId);
                self::askService($chatId);
                return;

            case 's':
                Tg::answerCb($cbId);
                self::serviceCard($chatId, $msgId, (int)$arg2);
                return;

            case 'ssync': {
                Tg::answerCb($cbId, '⏳ در حال همگام‌سازی…');
                if (class_exists('Svc') && method_exists('Svc', 'syncNow')) {
                    try { Svc::syncNow((int)$arg2); } catch (\Throwable $e) { }
                }
                self::serviceCard($chatId, $msgId, (int)$arg2);
                return;
            }

            /* ---------- سرورها / انبار / پشتیبان / تنظیمات ---------- */
            case 'pnl':
                Tg::answerCb($cbId, '⏳ در حال بررسی سرورها…');
                self::panelsHealth($chatId, $msgId);
                return;

            case 'stk':
                Tg::answerCb($cbId);
                self::stockPanel($chatId, $msgId);
                return;

            case 'bkp':
                self::backupNow($chatId, $cbId);
                return;

            case 'set':
                Tg::answerCb($cbId);
                self::quickSettings($chatId, $msgId);
                return;

            case 'tg':
                self::toggleSetting($chatId, $msgId, $cbId, (string)$arg2);
                return;

            case 'stats':
                Tg::answerCb($cbId);
                self::stats($chatId, $msgId);
                return;

            case 'pay':
                Tg::answerCb($cbId);
                self::pendingPayments($chatId, $msgId);
                return;

            /* ---------- احراز کارت بانکی ---------- */
            case 'crd':
                Tg::answerCb($cbId);
                self::pendingCards($chatId, $msgId);
                return;

            case 'crdok': {
                $ok = class_exists('CardAuth') && CardAuth::approve((int)$arg2, (int)(Bot::$u['tg_id'] ?? 0));
                Tg::answerCb($cbId, $ok ? '✅ کارت تایید شد.' : '❌ انجام نشد.', true);
                if ($ok && $msgId) Tg::edit($chatId, $msgId, '✅ کارت #' . (int)$arg2 . ' تایید شد و به کاربر اطلاع داده شد.');
                return;
            }

            case 'crdno': {
                $ok = class_exists('CardAuth') && CardAuth::reject((int)$arg2, (int)(Bot::$u['tg_id'] ?? 0), 'رد توسط مدیر');
                Tg::answerCb($cbId, $ok ? '⛔️ کارت رد شد.' : '❌ انجام نشد.', true);
                if ($ok && $msgId) Tg::edit($chatId, $msgId, '⛔️ کارت #' . (int)$arg2 . ' رد شد و به کاربر اطلاع داده شد.');
                return;
            }

            case 'ok': {
                /* fixed84: پیام کاربر و مهر خوردن کارت رسید در Wallet انجام می‌شود */
                $r = Wallet::approve((int)$arg2, (int)Bot::$u['tg_id']);
                if (class_exists('Audit')) Audit::log('wallet.approve', ['tx' => (int)$arg2, 'ok' => !empty($r['ok']), 'amount' => (int)($r['tx']['amount'] ?? 0)]);
                Tg::answerCb($cbId, $r['message'], true);
                if (!empty($r['ok']) && empty($r['stamped']) && $msgId) {
                    Tg::stamp($chatId, $msgId, '✅ <b>تایید شده</b>' . "\n" . '🧾 تراکنش <code>#' . (int)$arg2 . '</code> تایید و کیف پول شارژ شد.');
                }
                return;
            }

            case 'no': {
                if (class_exists('Audit')) Audit::log('wallet.reject', ['tx' => (int)$arg2]);
                $r = Wallet::reject((int)$arg2, (int)Bot::$u['tg_id'], 'رد توسط مدیر');
                Tg::answerCb($cbId, $r['message'], true);
                if (!empty($r['ok']) && empty($r['stamped']) && $msgId) {
                    Tg::stamp($chatId, $msgId, '❌ <b>رد شده</b>' . "\n" . '🧾 تراکنش <code>#' . (int)$arg2 . '</code> رد شد و به کاربر اطلاع داده شد.');
                }
                return;
            }

            case 'undo': {
                $r = Wallet::undo((int)$arg2, (int)(Bot::$u['tg_id'] ?? 0));
                if (class_exists('Audit')) Audit::log('wallet.undo', ['tx' => (int)$arg2, 'ok' => !empty($r['ok'])]);
                Tg::answerCb($cbId, (string)($r['message'] ?? '-'), true);
                if (!empty($r['ok'])) {
                    $tx = (array)$r['tx'];
                    Tg::send((int)$tx['tg_id'],
                        "⚠️ <b>تایید شارژ کیف پول شما لغو شد</b>\n"
                        . '💸 مبلغ ' . money((int)$tx['amount']) . ' ' . currency() . " از موجودی شما کسر شد.\n"
                        . 'در صورت اعتراض با پشتیبانی در تماس باشید.');
                    Tg::send($chatId, '↩️ تراکنش #' . (int)$arg2 . ' لغو و مبلغ برگشت خورد. موجودی جدید: '
                        . money((int)($r['balance'] ?? 0)) . ' ' . currency());
                }
                return;
            }

            case 'ban': {
                $uid = (int)$arg2;
                $u   = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => $uid]);
                if (!$u) { Tg::answerCb($cbId, 'کاربر یافت نشد.', true); return; }

                $new = (int)($u['is_banned'] ?? 0) === 1 ? 0 : 1;
                DB::update('users', ['is_banned' => $new], 'id = :id', [':id' => $uid]);
                if (class_exists('Audit')) Audit::log($new ? 'user.ban' : 'user.unban', ['uid' => $uid, 'tg' => (int)$u['tg_id']]);

                Tg::answerCb($cbId, $new ? '🚫 کاربر مسدود شد.' : '✅ مسدودیت ب��داشته شد.', true);

                $nm = trim((string)($u['first_name'] ?? '')) !== '' ? (string)$u['first_name'] : '-';
                Tg::send($chatId,
                    ($new ? '🚫 <b>کاربر مسدود شد</b>' : '✅ <b>مسدودیت کاربر برداشته شد</b>') . "\n"
                    . '👤 ' . h($nm) . ' – <code>' . (int)$u['tg_id'] . '</code>',
                    Tg::ikb([[Tg::btn($new ? '↩️ رفع مسدودی' : '🚫 مسدود کردن دوباره', 'adm:ban:' . $uid)]]));

                if ($new) {
                    Tg::send((int)$u['tg_id'], '⛔️ دسترسی شما به ربات مسدود شد.');
                } else {
                    Tg::send((int)$u['tg_id'], '✅ دسترسی شما به ربات دوباره فعال شد. /start');
                }
                return;
            }

            case 'tk':
                Tg::answerCb($cbId);
                self::openTickets($chatId, $msgId);
                return;

            case 'tkv':
                Tg::answerCb($cbId);
                self::viewTicket($chatId, $msgId, (int)$arg2);
                return;

            case 'tkr':
                Tg::answerCb($cbId);
                DB::update('users', ['state' => 'admin_tk_reply', 'state_data' => jenc(['tid' => (int)$arg2])],
                    'id = :id', [':id' => (int)Bot::$u['id']]);
                Tg::send($chatId, '✍️ پاسخ خود به تیکت #' . (int)$arg2 . " را بنویسید:\n🖼 امکان ارسال عکس و فایل هم وجود دارد.", Kb::cancel());
                return;

            case 'bal':
                Tg::answerCb($cbId);
                DB::update('users', ['state' => 'admin_bal_user', 'state_data' => null], 'id = :id', [':id' => (int)Bot::$u['id']]);
                Tg::send($chatId, '👤 آیدی عددی کاربر را ارسال کنید:', Kb::cancel());
                return;

            case 'bc':
                Tg::answerCb($cbId);
                DB::update('users', ['state' => 'admin_broadcast', 'state_data' => null], 'id = :id', [':id' => (int)Bot::$u['id']]);
                Tg::send($chatId, "📣 متن پیام همگانی را ارسال کنید.\nامکان استفاده از تگ‌های HTML وجود دارد.", Kb::cancel());
                return;

            case 'rsok': {
                $uid = (int)$arg2;
                $r = class_exists('Reseller')
                    ? (class_exists('Audit') ? (Audit::log('reseller.approve', ['uid' => $uid]) ?? Reseller::approveRequest($uid, 1)) : Reseller::approveRequest($uid, 1))
                    : ['ok' => false, 'message' => 'ماژول نمایندگی در دسترس نیست.'];

                Tg::answerCb($cbId, (string)($r['message'] ?? '-'), true);

                if (!empty($r['ok'])) {
                    $u = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => $uid]);
                    if ($u) {
                        Tg::send(
                            (int)$u['tg_id'],
                            "🎉 <b>درخواست نمایندگی شما تایید شد</b>\n\n"
                            . "🥈 سطح شما: نمایندگی سطح ۱\n"
                            . "از منوی «🏷 نمایندگی» پنل خود را باز کنید و کانفیگ بسازید."
                        );
                    }
                    if ($msgId) Tg::edit($chatId, $msgId, '✅ درخواست نمایندگی تایید شد (سطح ۱).');
                }
                return;
            }

            case 'rsno': {
                $uid = (int)$arg2;
                if (class_exists('Reseller')) Reseller::clearRequest($uid);
                if (class_exists('Audit')) Audit::log('reseller.reject', ['uid' => $uid]);
                Tg::answerCb($cbId, 'درخواست رد شد.', true);

                $u = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => $uid]);
                if ($u) {
                    Tg::send(
                        (int)$u['tg_id'],
                        "❌ <b>درخواست نمایندگی شما پذیرفته نشد</b>\n\n"
                        . 'برای اطلاعات بیشتر با پشتیبانی در تماس باشید.'
                    );
                }
                if ($msgId) Tg::edit($chatId, $msgId, '❌ درخواست نمایندگی رد شد.');
                return;
            }

            case 'web': {
                Tg::answerCb($cbId, 'در حال ساخت لینک ورود...');
                $link = self::webLoginLink((int)Bot::$u['tg_id']);
                if ($link === '') {
                    Tg::send($chatId, '⚠️ هیچ حساب مدیریتیِ فعالی برای پنل وب وجود ندارد.');
                    return;
                }
                $rows = [[Tg::url('🌐 باز کردن در مرورگر', $link)]];

                // دکمهٔ دوم: باز شدن همان پنل داخل مینی‌اپ تلگرام (نیازمند HTTPS)
                if (self::adminMiniappOn() && str_starts_with($link, 'https://')) {
                    $rows[] = [['text' => '📱 باز کردن داخل مینی‌اپ', 'web_app' => ['url' => $link]]];
                }

                $txt = "🌐 <b>ورود یک‌بارمصرف به پنل مدیریت</b>\n"
                    . "⏱ اعتبار لینک: ۱۰ دقیقه (یک‌بار مصرف)\n\n"
                    . "🔗 <b>لینک مرورگر:</b>\n" . h($link) . "\n\n"
                    . "📱 <b>مینی‌اپ:</b> با دکمهٔ دوم، پنل بدون خروج از تلگرام باز می‌شود.";

                Tg::send($chatId, $txt, Tg::ikb($rows));
                return;
            }
        }
        /* بخش‌های دسته‌بندی‌شدهٔ جدید (مالی، سرویس‌ها، سرورها، ارتباط، ابزار، گزارش‌ها) */
        if (class_exists('AdminHub') && AdminHub::callback($chatId, $msgId, $cbId, $arg, $arg2)) return;
        Tg::answerCb($cbId);
    }

    /* ---------------- آمار ---------------- */

    private static function stats($chatId, $msgId = null): void
    {
        $users     = (int)DB::val('SELECT COUNT(*) FROM {p}users', [], 0);
        $today     = (int)DB::val('SELECT COUNT(*) FROM {p}users WHERE DATE(created_at) = CURDATE()', [], 0);
        $services  = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE status <> 'deleted'", [], 0);
        $active    = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE status = 'active'", [], 0);
        $tests     = (int)DB::val('SELECT COUNT(*) FROM {p}services WHERE is_test = 1', [], 0);
        $sales     = (int)DB::val("SELECT COALESCE(SUM(final_amount),0) FROM {p}orders WHERE status = 'paid'", [], 0);
        $salesToday= (int)DB::val("SELECT COALESCE(SUM(final_amount),0) FROM {p}orders WHERE status = 'paid' AND DATE(created_at) = CURDATE()", [], 0);
        $wallets   = (int)DB::val('SELECT COALESCE(SUM(balance),0) FROM {p}users', [], 0);
        $pending   = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE status = 'pending'", [], 0);

        $txt = "📊 <b>آمار فروشگاه</b>\n\n"
            . '👥 کاربران: ' . fa_num($users) . ' (امروز: ' . fa_num($today) . ")\n"
            . '📦 سرویس‌ها: ' . fa_num($services) . ' (فعال: ' . fa_num($active) . ")\n"
            . '🧪 اکانت تست: ' . fa_num($tests) . "\n"
            . '💵 فروش کل: ' . money($sales) . ' ' . currency() . "\n"
            . '📈 فروش امروز: ' . money($salesToday) . ' ' . currency() . "\n"
            . '👛 مجموع کیف پول‌ها: ' . money($wallets) . ' ' . currency() . "\n"
            . '⏳ پرداخت در انتظار: ' . fa_num($pending);
        $kbRows = [];
        if (class_exists('AdminHub')) {
            $kbRows[] = [Tg::btn('📈 فروش ۷ روز اخیر', 'adm:st7'), Tg::btn('🏆 پرفروش‌ها', 'adm:sttop')];
            $kbRows[] = [Tg::btn('🖥 آمار سرورها', 'adm:stpnl'), Tg::btn('💳 مالی', 'adm:fin')];
        }
        $kbRows[] = [Tg::btn('🔄 بروزرسانی', 'adm:stats'), Tg::btn('🏠 خانه', 'adm:home')];
        $kb = Tg::ikb($kbRows);
        $msgId ? Tg::edit($chatId, $msgId, $txt, $kb) : Tg::send($chatId, $txt, $kb);
    }

    /* ---------------- پرداخت‌ها ---------------- */

    private static function pendingPayments($chatId, $msgId = null): void
    {
        $list = Wallet::pending(10);
        if (!$list) {
            $kb = Tg::ikb([[Tg::btn('⬅️ بازگشت', 'adm:home')]]);
            $txt = '✅ پرداخت در انتظاری وجود ندارد.';
            $msgId ? Tg::edit($chatId, $msgId, $txt, $kb) : Tg::send($chatId, $txt, $kb);
            return;
        }
        if ($msgId) Tg::edit($chatId, $msgId, '💳 <b>پرداخت‌های در انتظار</b> (' . fa_num(count($list)) . ')');
        foreach ($list as $tx) self::sendPaymentCard($chatId, $tx);
    }

    /* ---------------- احراز کارت بانکی ---------------- */

    /** کارت‌های در انتظار تایید */
    private static function pendingCards($chatId, $msgId = null): void
    {
        if (!class_exists('CardAuth')) return;
        $list = CardAuth::pending(10);
        if (!$list) {
            $kb  = Tg::ikb([[Tg::btn('⬅️ بازگشت', 'adm:home')]]);
            $txt = '✅ کارتی در انتظار تایید وجود ندارد.';
            $msgId ? Tg::edit($chatId, $msgId, $txt, $kb) : Tg::send($chatId, $txt, $kb);
            return;
        }
        if ($msgId) Tg::edit($chatId, $msgId, '💳 <b>کارت‌های در انتظار تایید</b> (' . fa_num(count($list)) . ')');
        foreach ($list as $c) self::sendCardRequest($chatId, $c);
    }

    /** کارت درخواست احراز کارت برای مدیر */
    private static function sendCardRequest($chatId, array $c): void
    {
        $un   = trim((string)($c['username'] ?? ''));
        $link = $un !== '' ? 'https://t.me/' . ltrim($un, '@') : 'tg://user?id=' . (int)$c['tg_id'];

        $txt = "💳 <b>درخواست احراز کارت #" . (int)$c['id'] . "</b>\n"
            . 'کاربر: <code>' . (int)$c['tg_id'] . '</code> ' . ($un !== '' ? '(@' . h($un) . ')' : '') . "\n"
            . ((string)($c['first_name'] ?? '') !== '' ? 'نام در ربات: ' . h((string)$c['first_name']) . "\n" : '')
            . 'کارت: <code>' . CardAuth::pretty((string)$c['pan']) . "</code>\n"
            . ((string)($c['bank'] ?? '') !== ''   ? 'بانک: ' . h((string)$c['bank']) . "\n" : '')
            . ((string)($c['holder'] ?? '') !== '' ? 'صاحب کارت: <b>' . h((string)$c['holder']) . "</b>\n" : '')
            . ((string)($c['sheba'] ?? '') !== ''  ? 'شبا: <code>IR' . h((string)$c['sheba']) . "</code>\n" : '')
            . 'تاریخ: ' . to_jalali((string)$c['created_at'], true) . "\n\n"
            . '⛔️ هیچ داده‌ی حساسی (CVV2 / رمز دوم / انقضا) دریافت نمی‌شود.';

        Tg::send($chatId, $txt, Tg::ikb([
            [
                Tg::btn('✅ تایید کارت', 'adm:crdok:' . (int)$c['id']),
                Tg::btn('⛔️ رد کارت', 'adm:crdno:' . (int)$c['id']),
            ],
            [Tg::url('💬 ارتباط با کاربر', $link)],
        ]));
    }

    /** اطلاع‌رسانی درخواست احراز کارت به مدیران */
    public static function notifyCardRequest(int $cardId): void
    {
        if (!class_exists('CardAuth')) return;
        $c = CardAuth::find($cardId);
        if (!$c) return;

        $u = DB::one('SELECT username, first_name FROM {p}users WHERE id = :i', [':i' => (int)$c['user_id']]);
        $c['username']   = (string)($u['username'] ?? '');
        $c['first_name'] = (string)($u['first_name'] ?? '');

        foreach (self::adminIds() as $id) self::sendCardRequest($id, $c);
    }

    private static function sendPaymentCard($chatId, array $tx, string $note = ''): void
    {
        $mLbl   = ['card' => '💳 کارت به کارت', 'crypto' => '🌐 ارزی', 'wallet' => '👛 کیف پول',
                   'hooshpay' => '🪙 هوش‌پی (خودکار)', 'nowpay' => '🤖 نوپیمنتس (خودکار)'];
        $method = $mLbl[(string)$tx['method']] ?? (string)$tx['method'];

        /* کارت احرازشده‌ای که کاربر اعلام کرده با آن واریز می‌کند */
        $cardLine = '';
        if (!empty($tx['card_id']) && class_exists('CardAuth')) {
            $uc = CardAuth::find((int)$tx['card_id']);
            if ($uc) {
                $cardLine = "\n" . '💳 کارت مبدأ (احرازشده): <code>' . CardAuth::pretty((string)$uc['pan']) . '</code>'
                    . ((string)($uc['holder'] ?? '') !== '' ? "\n" . '👤 صاحب کارت: ' . h((string)$uc['holder']) : '');
            }
        }
        $txt = "🧾 <b>درخواست شارژ #" . $tx['id'] . "</b>\n"
            . 'کاربر: <code>' . (int)$tx['tg_id'] . '</code> ' . ($tx['username'] ? '(@' . h((string)$tx['username']) . ')' : '') . "\n"
            . 'مبلغ: <b>' . money((int)$tx['amount']) . ' ' . currency() . "</b>\n"
            . 'روش: ' . $method . "\n"
            . ($tx['txid'] ? 'هش تراکنش: <code>' . h((string)$tx['txid']) . "</code>\n" : '')
            . 'تاریخ: ' . to_jalali((string)$tx['created_at'], true)
            . $cardLine
            . ($note !== '' ? "\n" . 'یادداشت سیستم: ' . h($note) : '');
        $un   = trim((string)($tx['username'] ?? ''));
        $link = $un !== '' ? 'https://t.me/' . ltrim($un, '@') : 'tg://user?id=' . (int)$tx['tg_id'];
        $kb = Tg::ikb([
            [
                Tg::btn('✅ تایید', 'adm:ok:' . $tx['id']),
                Tg::btn('❌ رد', 'adm:no:' . $tx['id']),
            ],
            [
                Tg::btn('🚫 مسدود کاربر', 'adm:ban:' . (int)($tx['user_id'] ?? 0)),
                Tg::url('💬 ارتباط با کاربر', $link),
            ],
        ]);
        if (!empty($tx['receipt_file'])) {
            $res = Tg::api('sendPhoto', ['chat_id' => $chatId, 'photo' => (string)$tx['receipt_file'],
                'caption' => $txt, 'parse_mode' => 'HTML', 'reply_markup' => jenc($kb)]);
        } else {
            $res = Tg::send($chatId, $txt, $kb);
        }
        /* fixed84: شناسهٔ این پیام را نگه می‌داریم تا هنگام تایید/رد (از ربات یا پنل وب)
           همین کارت مهر بخورد و دکمه‌های تایید/رد حذف شوند */
        Wallet::rememberAdminCard((int)($tx['id'] ?? 0), $chatId, (int)($res['result']['message_id'] ?? 0));
    }

    /** اطلاع‌رسانی درخواست نمایندگی به مدیران */
    public static function notifyResellerRequest(int $userId, string $note = ''): void
    {
        $u = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => $userId]);
        if (!$u) return;

        $name = trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? ''));

        $txt = "🏷 <b>درخواست نمایندگی تازه</b>\n"
            . "<code>─────────────────</code>\n"
            . '👤 نام: ' . h($name !== '' ? $name : '-') . "\n"
            . '🆔 شناسه: <code>' . (int)$u['tg_id'] . "</code>\n";

        if (trim((string)($u['username'] ?? '')) !== '') {
            $txt .= '🔗 یوزرنیم: @' . h((string)$u['username']) . "\n";
        }

        $txt .= '💰 موجودی: ' . money((float)($u['balance'] ?? 0)) . ' ' . currency() . "\n"
            . '🧾 جمع خرید: ' . money((float)($u['total_paid'] ?? 0)) . ' ' . currency() . "\n";

        if (trim($note) !== '') {
            $txt .= "\n💬 توضیح کاربر:\n<i>" . h(mb_substr(trim($note), 0, 300)) . "</i>\n";
        }

        $txt .= "\nℹ️ برای تعیین سطح ۲، سقف بدهی و تخفیف اختصاصی، از پنل وب بخش «نمایندگی‌ها» استفاده کنید.";

        self::notifyAdmins($txt, Tg::ikb([
            [
                Tg::btn('✅ تایید سطح ۱', 'adm:rsok:' . (int)$u['id']),
                Tg::btn('❌ رد درخواست', 'adm:rsno:' . (int)$u['id']),
            ],
        ]));
    }

    public static function notifyPayment(int $txId, ?string $fileId = null, string $note = ''): void
    {
        $tx = DB::one('SELECT t.*, u.username FROM {p}transactions t JOIN {p}users u ON u.id = t.user_id WHERE t.id = :id', [':id' => $txId]);
        if (!$tx) return;
        /* fixed84: فاکتور درگاه خودکار رسید دستی نیست و به صف تایید مدیر نمی‌رود */
        if (class_exists('Wallet') && Wallet::isAuto((string)($tx['method'] ?? ''))) return;
        if ($fileId !== null && $fileId !== '' && empty($tx['receipt_file'])) $tx['receipt_file'] = $fileId;
        foreach (self::adminIds() as $id) self::sendPaymentCard($id, $tx, $note);
    }

    /* ---------------- تیکت‌ها ---------------- */

    private static function openTickets($chatId, $msgId = null): void
    {
        $list = DB::all("SELECT t.*, u.tg_id AS uid FROM {p}tickets t JOIN {p}users u ON u.id = t.user_id
            WHERE t.status = 'open' ORDER BY t.updated_at DESC LIMIT 10");
        $rows = [];
        foreach ($list as $t) {
            $rows[] = [Tg::btn('#' . $t['id'] . ' – ' . mb_substr((string)$t['subject'], 0, 30), 'adm:tkv:' . $t['id'])];
        }
        $rows[] = [Tg::btn('⬅️ بازگشت', 'adm:home')];
        $txt = $list ? '🎫 <b>تیکت‌های باز</b>' : '✅ تیکت بازی وجود ندارد.';
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    private static function viewTicket($chatId, $msgId, int $id): void
    {
        $t = DB::one('SELECT t.*, u.tg_id AS uid, u.first_name FROM {p}tickets t JOIN {p}users u ON u.id = t.user_id WHERE t.id = :id', [':id' => $id]);
        if (!$t) { Tg::send($chatId, '❌ تیکت یافت نشد.'); return; }
        $msgs = DB::all('SELECT * FROM {p}ticket_messages WHERE ticket_id = :t ORDER BY id ASC LIMIT 20', [':t' => $id]);
        $stMap = ['open' => '🕘 باز', 'answered' => '✅ پاسخ داده شده', 'closed' => '🔒 بسته'];
        $out = [
            '🎫 <b>تیکت #' . $id . '</b>',
            '👤 کاربر: <code>' . (int)$t['uid'] . '</code> ' . h((string)$t['first_name']),
            '🔰 وضعیت: ' . ($stMap[(string)$t['status']] ?? (string)$t['status']),
            '<code>─────────────────</code>',
            '',
        ];
        $attachments = [];
        foreach ($msgs as $m) {
            $out[] = ($m['sender'] === 'admin' ? '🛠 <b>پشتیبانی</b>' : '👤 <b>کاربر</b>')
                . ' – <i>' . to_jalali((string)$m['created_at'], true) . '</i>';
            if (trim((string)$m['text']) !== '') $out[] = h(mb_substr((string)$m['text'], 0, 500));
            if (!empty($m['file_id'])) {
                $out[] = '📎 <i>فایل پیوست – در پیام بعدی ارسال می‌شود</i>';
                $attachments[] = $m;
            }
            $out[] = '';
        }
        $rows = [
            [Tg::btn('✍️ پاسخ', 'adm:tkr:' . $id)],
            [Tg::btn('⬅️ تیکت‌ها', 'adm:tk')],
        ];
        $txt = implode("\n", $out);
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
        foreach (array_slice($attachments, -5) as $m) {
            Tg::media($chatId, (string)$m['file_id'], (string)($m['file_type'] ?? ''),
                '📎 پیوست تیکت #' . $id . ' – ' . ($m['sender'] === 'admin' ? 'پشتیبانی' : 'کاربر'));
        }
    }

    /**
     * اطلاع‌رسانی تیکت به مدیران – همراه با عکس/فایل پیوست
     */
    public static function notifyTicket(int $ticketId, string $text, ?string $fileId = null, ?string $fileType = null): void
    {
        $t = DB::one('SELECT t.*, u.tg_id AS uid, u.username, u.first_name FROM {p}tickets t JOIN {p}users u ON u.id = t.user_id WHERE t.id = :id', [':id' => $ticketId]);
        if (!$t) return;

        $uname = trim((string)($t['username'] ?? ''));
        $head = "💬 <b>پیام جدید پشتیبانی #" . $ticketId . "</b>\n"
            . '👤 کاربر: <code>' . (int)$t['uid'] . '</code>'
            . ($uname !== '' ? ' (@' . h($uname) . ')' : '') . "\n"
            . '📝 موضوع: ' . h(mb_substr((string)$t['subject'], 0, 60)) . "\n";

        $body = trim($text) !== '' ? "\n" . h(mb_substr($text, 0, 600)) : "\n<i>(بدون متن)</i>";
        $kb   = Tg::ikb([[Tg::btn('✍️ پاسخ', 'adm:tkr:' . $ticketId), Tg::btn('👁 مشاهده', 'adm:tkv:' . $ticketId)]]);

        foreach (self::adminIds() as $id) {
            if ($fileId !== null && $fileId !== '') {
                // اگر متن کوتاه است، همه چیز در کپشن خود عکس می‌رود
                $caption = $head . $body;
                if (mb_strlen($caption) <= 950) {
                    $r = Tg::media($id, $fileId, $fileType, $caption, $kb);
                    if (!empty($r['ok'])) continue;
                    Tg::send($id, $caption, $kb);
                    continue;
                }
                Tg::send($id, $head . $body . "\n\n📎 فایل پیوست در پیام بعدی:", $kb);
                Tg::media($id, $fileId, $fileType, '📎 پیوست تیکت #' . $ticketId);
                continue;
            }
            Tg::send($id, $head . $body, $kb);
        }
    }

    /* ---------------- حالت‌های مدیر ---------------- */

    public static function handleState($chatId, string $state, string $text, array $msg, array $user, array $data): bool
    {
        if (!is_admin_id($user['tg_id'])) return false;

        switch ($state) {
            case 'admin_usr_find': {
                DB::update('users', ['state' => null, 'state_data' => null], 'id = :id', [':id' => (int)$user['id']]);
                $q   = ltrim(trim(en_num($text)), '@');
                $num = preg_replace('/[^0-9]/', '', $q);
                $target = null;
                if ($num !== '') {
                    $target = DB::one('SELECT * FROM {p}users WHERE tg_id = :t', [':t' => (int)$num]);
                    if (!$target) {
                        $target = DB::one('SELECT * FROM {p}users WHERE phone LIKE :p ORDER BY id DESC LIMIT 1',
                            [':p' => '%' . $num . '%']);
                    }
                }
                if (!$target && $q !== '') {
                    $target = DB::one('SELECT * FROM {p}users WHERE username = :u ORDER BY id DESC LIMIT 1', [':u' => $q]);
                }
                if (!$target) {
                    Tg::send($chatId, '❌ کاربری با این مشخصات پیدا نشد.', Kb::main(true));
                    return true;
                }
                self::userCard($chatId, null, (int)$target['id']);
                return true;
            }

            case 'admin_svc_find': {
                DB::update('users', ['state' => null, 'state_data' => null], 'id = :id', [':id' => (int)$user['id']]);
                $q   = trim(en_num($text));
                $num = (int)preg_replace('/[^0-9]/', '', $q);
                $s   = null;
                if ($num > 0) $s = DB::one('SELECT * FROM {p}services WHERE id = :i', [':i' => $num]);
                if (!$s && $q !== '') {
                    $s = DB::one('SELECT * FROM {p}services WHERE client_email = :e OR sub_id = :e2 ORDER BY id DESC LIMIT 1',
                        [':e' => $q, ':e2' => $q]);
                }
                if (!$s) {
                    Tg::send($chatId, '❌ سرویسی با این مشخصات پیدا نشد.', Kb::main(true));
                    return true;
                }
                self::serviceCard($chatId, null, (int)$s['id']);
                return true;
            }

            case 'admin_dm_text': {
                DB::update('users', ['state' => null, 'state_data' => null], 'id = :id', [':id' => (int)$user['id']]);
                $uid    = (int)($data['uid'] ?? 0);
                $target = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => $uid]);
                if (!$target) { Tg::send($chatId, '❌ کاربر یافت نشد.', Kb::main(true)); return true; }
                $sent = Tg::send((int)$target['tg_id'], '📩 <b>پیام از پشتیبانی</b>' . "\n"
                    . '<code>─────────────────</code>' . "\n" . h($text));
                Tg::send($chatId, $sent ? '✅ پیام ارسال شد.' : '❌ ارسال نشد (کاربر ربات را بلاک کرده است).', Kb::main(true));
                return true;
            }

            case 'admin_bal_user': {
                $tgId = (int)preg_replace('/\D/', '', en_num($text));
                $target = DB::one('SELECT * FROM {p}users WHERE tg_id = :t', [':t' => $tgId]);
                if (!$target) { Tg::send($chatId, '❌ کاربری با این آیدی یافت نشد. دوباره تلاش کنید.'); return true; }
                DB::update('users', ['state' => 'admin_bal_amount', 'state_data' => jenc(['uid' => (int)$target['id']])],
                    'id = :id', [':id' => (int)$user['id']]);
                Tg::send($chatId, '💰 مبلغ را وارد کنید (منفی برای کسر):' . "\nکاربر: " . h((string)$target['first_name'])
                    . "\nموجودی فعلی: " . money((int)$target['balance']) . ' ' . currency(), Kb::cancel());
                return true;
            }

            case 'admin_bal_amount': {
                $amount = (int)preg_replace('/[^\d\-]/', '', en_num($text));
                $uid = (int)($data['uid'] ?? 0);
                $target = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => $uid]);
                DB::update('users', ['state' => null, 'state_data' => null], 'id = :id', [':id' => (int)$user['id']]);
                if (!$target || $amount === 0) { Tg::send($chatId, '❌ عملیات لغو شد.', Kb::main(true)); return true; }
                if ($amount > 0) {
                    if (class_exists('Audit')) Audit::log('wallet.credit', ['uid' => $uid, 'tg' => (int)$target['tg_id'], 'amount' => $amount]);
                    Wallet::credit($uid, $amount, 'admin', 'admin', 'شارژ دستی توسط مدیر', (int)$user['tg_id']);
                    Tg::send((int)$target['tg_id'], '🎁 مبلغ ' . money($amount) . ' ' . currency() . ' به کیف پول شما افزوده شد.');
                } else {
                    if (class_exists('Audit')) Audit::log('wallet.debit', ['uid' => $uid, 'tg' => (int)$target['tg_id'], 'amount' => abs($amount)]);
                    Wallet::debit($uid, abs($amount), 'کسر دستی توسط مدیر', 'admin');
                    Tg::send((int)$target['tg_id'], 'ℹ️ مبلغ ' . money(abs($amount)) . ' ' . currency() . ' از کیف پول شما کسر شد.');
                }
                Tg::send($chatId, '✅ موجودی کاربر بروز شد.', Kb::main(true));
                return true;
            }

            case 'admin_broadcast': {
                DB::update('users', ['state' => null, 'state_data' => null], 'id = :id', [':id' => (int)$user['id']]);
                $ids = DB::all('SELECT tg_id FROM {p}users WHERE is_banned = 0 ORDER BY id ASC LIMIT 5000');
                if (class_exists('Audit')) Audit::log('broadcast.send', ['targets' => count($ids), 'text' => mb_substr(strip_tags($text), 0, 200)]);
                $ok = 0; $fail = 0;
                foreach ($ids as $row) {
                    $r = Tg::send((int)$row['tg_id'], $text);
                    if (!empty($r['ok'])) $ok++; else $fail++;
                    usleep(40000);
                }
                Tg::send($chatId, "📣 ارسال پیام همگانی پایان یافت.\n✅ موفق: " . fa_num($ok) . "\n❌ ناموفق: " . fa_num($fail), Kb::main(true));
                Logs::send('broadcast', Logs::fmt('📣 پیام همگانی', [
                    'مدیر'        => '<code>' . (int)$user['tg_id'] . '</code>',
                    'ارسال موفق'  => fa_num((string)$ok),
                    'ناموفق'      => fa_num((string)$fail),
                ], h(mb_substr($text, 0, 300))));
                return true;
            }

            case 'admin_tk_reply': {
                $tid = (int)($data['tid'] ?? 0);
                $t = DB::one('SELECT t.*, u.tg_id AS uid FROM {p}tickets t JOIN {p}users u ON u.id = t.user_id WHERE t.id = :id', [':id' => $tid]);
                $att = Bot::fileMeta($msg);
                if ($text === '' && !$att['id']) { Tg::send($chatId, '❌ متن پاسخ یا یک فایل ارسال کنید.'); return true; }
                DB::update('users', ['state' => null, 'state_data' => null], 'id = :id', [':id' => (int)$user['id']]);
                if (!$t) { Tg::send($chatId, '❌ تیکت یافت نشد.', Kb::main(true)); return true; }
                DB::insert('ticket_messages', ['ticket_id' => $tid, 'sender' => 'admin', 'text' => $text,
                    'file_id' => $att['id'], 'file_type' => $att['type'], 'created_at' => now()]);
                DB::update('tickets', ['status' => 'answered', 'updated_at' => now()], 'id = :id', [':id' => $tid]);

                $userTxt = "🛠 <b>پاسخ پشتیبانی – تیکت #" . $tid . "</b>\n\n" . ($text !== '' ? h($text) : '<i>(فایل پیوست)</i>');
                $userKb  = Tg::ikb([[Tg::btn('✍️ پاسخ من', 'tkr:' . $tid)]]);
                if ($att['id']) {
                    $r = Tg::media((int)$t['uid'], (string)$att['id'], $att['type'], $userTxt, $userKb);
                    if (empty($r['ok'])) Tg::send((int)$t['uid'], $userTxt, $userKb);
                } else {
                    Tg::send((int)$t['uid'], $userTxt, $userKb);
                }
                Tg::send($chatId, '✅ پاسخ ارسال شد.' . ($att['id'] ? "\n📎 فایل پیوست هم ارسال شد." : ''), Kb::main(true));
                Logs::send('tickets', Logs::fmt('🛠 پاسخ پشتیبانی', [
                    'شماره' => '<code>#' . $tid . '</code>',
                    'مدیر'  => '<code>' . (int)$user['tg_id'] . '</code>',
                    'پیوست' => $att['id'] ? 'دارد' : 'ندارد',
                ], h(mb_substr($text, 0, 300))));
                return true;
            }
        }
        /* حالت‌های بخش‌های جدید (افزودن روز/گیگ، کد تخفیف/هدیه سریع، پیام گروهی، تنظیمات عددی) */
        if (class_exists('AdminHub')) {
            return AdminHub::handleState($chatId, $state, $text, $msg, $user, $data);
        }
        return false;
    }

    /* ---------------- ورود یک‌بارمصرف به پنل وب ---------------- */

    /** آیا باز کردن پنل مدیریت داخل مینی‌اپ فعال است؟ */
    public static function adminMiniappOn(): bool
    {
        return (string)DB::setting('admin_miniapp', '1') === '1';
    }

    /* ==================== قابلیت‌های مدیریتی داخل ربات ==================== */

    /** کلیدهای تنظیمات سریع: شناسه => [کلید دیتابیس، برچسب، مقدار پیش‌فرض وقتی تنظیم هنوز ثبت نشده] */
    public const QTOGGLES = [
        'stk' => ['stock_enabled',     '🏪 انبار ملی',                  '1'],
        'rs'  => ['rs_enabled',        '🏷 نمایندگی',                   '1'],
        'ma'  => ['miniapp_enabled',   '📱 مینی‌اپ',                    '1'],
        'ama' => ['admin_miniapp',     '📱 پنل مدیریت در مینی‌اپ',       '1'],
        'crd' => ['cardauth_enabled',  '💳 احراز کارت',                 '1'],
        'qr'  => ['qr_enabled',        '🔳 تصویر QR',                   '1'],
        'txc' => ['txc_enabled',       '🧾 بررسی خودکار رسید',         '1'],
        'tst' => ['test_enabled',      '🧪 اکانت تست',                  '1'],
        'cus' => ['cus_enabled',       '📐 حجم و زمان دلخواه',          '0'],
        'fj'  => ['fj_enabled',        '🔒 جوین اجباری کانال',          '1'],
        'urn' => ['usr_renew_enabled', '♻️ تمدید توسط کاربر',           '1'],
        'udl' => ['usr_del_enabled',   '🗑 حذف سرویس توسط کاربر',       '0'],
        'adl' => ['svc_autodel_on',    '🧹 حذف خودکار تمام‌شده‌ها',     '1'],
        'adn' => ['svc_autodel_notify','🔔 اطلاع حذف به کاربر',          '1'],
        'adw' => ['svc_autodel_warn',  '⏰ هشدار ۲۴ ساعت قبل از حذف',    '1'],
        'adr' => ['svc_autodel_rs',    '🏷 حذف خودکار سرویس نمایندگان',  '0'],
        'mnt' => ['maintenance',       '🚧 حالت تعمیر (فقط مدیران)',    '0'],
        /* fixed74 */
        'arn' => ['arn_enabled',       '🔁 تمدید خودکار از کیف پول',   '0'],
        'cmp' => ['camp_enabled',      '🔥 کمپین تخفیف زمان‌دار',       '0'],
    ];

    /** مقدار فعلی یک کلید روشن/خاموش با رعایت پیش‌فرض مخصوص همان کلید */
    private static function toggleOn(string $k): bool
    {
        $m   = self::QTOGGLES[$k];
        $def = (string)($m[2] ?? '1');
        $v   = (string)DB::setting($m[0], $def);
        if ($v === '') $v = $def; // مقدار خالی همان پیش‌فرض است (مثل جوین اجباری)
        return $v === '1';
    }

    public static function sep(): string
    {
        return '<code>─────────────────</code>' . "\n";
    }

    /**
     * گروه‌های مدیریت کاربران در ربات
     * کلید => [آیکون، عنوان، شرط SQL، ترتیب]
     */
    public const USEG = [
        'all'   => ['👥', 'همهٔ کاربران',   '1 = 1',                                       'id DESC'],
        'bal'   => ['💰', 'موجودی‌دارها',   'balance > 0',                                 'balance DESC'],
        'rs'    => ['🏷', 'نمایندگان',      'reseller_level > 0',                          'reseller_level DESC, id DESC'],
        'buy'   => ['🛒', 'خریداران',       'id IN (SELECT user_id FROM {p}services)',     'id DESC'],
        'nobuy' => ['🕳', 'بدون خرید',      'id NOT IN (SELECT user_id FROM {p}services)', 'id DESC'],
        'new'   => ['🆕', 'تازه‌وارد امروز', 'created_at >= :d0',                           'id DESC'],
        'wk'    => ['📆', 'تازه‌وارد هفته',  'created_at >= :d7',                           'id DESC'],
        'vrf'   => ['🔐', 'احرازشده',       '(phone_verified = 1 OR email_verified = 1)',  'id DESC'],
        'ref'   => ['🎯', 'معرفی‌شده',       'referrer_id > 0',                             'id DESC'],
        'debt'  => ['📉', 'بدهکاران',       'balance < 0',                                 'balance ASC'],
        'idle'  => ['💤', 'غیرفعال‌ها',      '(last_seen IS NULL OR last_seen < :d30)',     'id DESC'],
        'ban'   => ['🚫', 'مسدودها',        'is_banned = 1',                               'id DESC'],
    ];

    /** پارامترهای تاریخی مورد نیاز هر شرط */
    public static function usegParams(string $where): array
    {
        $p = [];
        if (strpos($where, ':d0')  !== false) $p[':d0']  = date('Y-m-d 00:00:00');
        if (strpos($where, ':d7')  !== false) $p[':d7']  = date('Y-m-d 00:00:00', strtotime('-7 days'));
        if (strpos($where, ':d30') !== false) $p[':d30'] = date('Y-m-d 00:00:00', strtotime('-30 days'));
        return $p;
    }

    /** مرحلهٔ ۱: انتخاب گروه کاربران */
    public static function usersHub($chatId, $msgId = null): void
    {
        $cnt = [];
        foreach (self::USEG as $k => $d) {
            $cnt[$k] = (int)DB::val('SELECT COUNT(*) FROM {p}users WHERE ' . $d[2], self::usegParams($d[2]), 0);
        }
        $sum = (int)DB::val('SELECT COALESCE(SUM(balance),0) FROM {p}users', [], 0);

        $t  = '👥 <b>مدیریت کاربران</b>' . "\n" . self::sep();
        $t .= 'اول یک <b>گروه</b> را انتخاب کنید، بعد از فهرست، کاربر مورد نظر را بزنید' . "\n";
        $t .= 'تا پرونده و ابزارهای مدیریتش باز شود.' . "\n" . self::sep();
        $t .= '👤 کل کاربران: <b>' . fa_num($cnt['all']) . '</b>' . "\n";
        $t .= '🛒 خریدار: ' . fa_num($cnt['buy']) . '  |  🏷 نماینده: ' . fa_num($cnt['rs']) . "\n";
        $t .= '💳 مجموع کیف پول‌ها: <b>' . fa_num(number_format($sum)) . '</b> ' . currency() . "\n";
        $t .= '🆕 امروز: ' . fa_num($cnt['new']) . '  |  📆 این هفته: ' . fa_num($cnt['wk']) . "\n";
        if ($cnt['ban'] > 0)  $t .= '🚫 مسدود: ' . fa_num($cnt['ban']) . "\n";
        if ($cnt['debt'] > 0) $t .= '📉 بدهکار: ' . fa_num($cnt['debt']) . "\n";

        $rows = [];
        $pair = [];
        foreach (self::USEG as $k => $d) {
            $pair[] = Tg::btn($d[0] . ' ' . $d[1] . ' (' . fa_num($cnt[$k]) . ')', 'adm:useg:' . $k . ':1');
            if (count($pair) === 2) { $rows[] = $pair; $pair = []; }
        }
        if ($pair) $rows[] = $pair;
        $rows[] = [Tg::btn('🔎 جستجوی کاربر (آیدی / یوزرنیم / شماره)', 'adm:ufind')];
        $rows[] = [Tg::btn('🏠 خانه', 'adm:home')];

        $kb = Tg::ikb($rows);
        $msgId ? Tg::edit($chatId, $msgId, $t, $kb) : Tg::send($chatId, $t, $kb);
    }

    /** مرحلهٔ ۲: فهرست کاربران یک گروه (صفحه‌بندی‌شده) */
    public static function usersList($chatId, $msgId, string $seg, int $page = 1): void
    {
        if (!isset(self::USEG[$seg])) $seg = 'all';
        $d   = self::USEG[$seg];
        $per = 8;
        $par = self::usegParams($d[2]);
        $tot = (int)DB::val('SELECT COUNT(*) FROM {p}users WHERE ' . $d[2], $par, 0);
        $pgs = max(1, (int)ceil($tot / $per));
        if ($page < 1)    $page = 1;
        if ($page > $pgs) $page = $pgs;
        $off = ($page - 1) * $per;

        $list = DB::all('SELECT * FROM {p}users WHERE ' . $d[2]
            . ' ORDER BY ' . $d[3] . ' LIMIT ' . $per . ' OFFSET ' . $off, $par);

        $t  = $d[0] . ' <b>' . $d[1] . '</b>' . "\n" . self::sep();
        $t .= '🔢 ' . fa_num($tot) . ' کاربر • صفحهٔ ' . fa_num($page) . ' از ' . fa_num($pgs) . "\n";

        $rows = [];
        if (!$list) {
            $t .= "\n" . 'در این گروه کاربری نیست.';
        } else {
            $t .= self::sep();
            foreach ($list as $u) {
                $nm = trim((string)$u['first_name'] . ' ' . (string)$u['last_name']);
                if ($nm === '') $nm = 'بدون نام';
                $lvl = (int)($u['reseller_level'] ?? 0);
                $ic  = (int)$u['is_banned'] ? '⛔️' : ($lvl > 0 ? '🏷' : '👤');
                $svc = (int)DB::val('SELECT COUNT(*) FROM {p}services WHERE user_id = :u', [':u' => (int)$u['id']], 0);

                $t .= $ic . ' <b>' . h(mb_substr($nm, 0, 24)) . '</b>';
                if (!empty($u['username'])) $t .= ' • @' . h((string)$u['username']);
                $t .= "\n";
                $t .= '   💳 ' . fa_num(number_format((int)$u['balance'])) . ' ' . currency()
                    . ' • 📦 ' . fa_num($svc)
                    . ' • 🆔 <code>' . (int)$u['tg_id'] . '</code>' . "\n";

                $rows[] = [Tg::btn($ic . ' ' . mb_substr($nm, 0, 22), 'adm:u:' . (int)$u['id'])];
            }
        }

        $nav = [];
        if ($page > 1)    $nav[] = Tg::btn('◀️ قبلی', 'adm:useg:' . $seg . ':' . ($page - 1));
        if ($page < $pgs) $nav[] = Tg::btn('بعدی ▶️', 'adm:useg:' . $seg . ':' . ($page + 1));
        if ($nav) $rows[] = $nav;
        $rows[] = [Tg::btn('👥 گروه‌ها', 'adm:usr'), Tg::btn('🏠 خانه', 'adm:home')];

        $kb = Tg::ikb($rows);
        $msgId ? Tg::edit($chatId, $msgId, $t, $kb) : Tg::send($chatId, $t, $kb);
    }

    /** درخواست جستجوی کاربر */
    public static function askUser($chatId): void
    {
        DB::update('users', ['state' => 'admin_usr_find', 'state_data' => null],
            'id = :id', [':id' => (int)(Bot::$u['id'] ?? 0)]);
        Tg::send($chatId, '👥 <b>مدیریت کاربران</b>' . "\n" . self::sep()
            . 'یکی از موارد زیر را بفرستید:' . "\n"
            . '• آیدی عددی تلگرام' . "\n"
            . '• یوزرنیم (با یا بدون @)' . "\n"
            . '• شمارهٔ تماس', Kb::cancel());
    }

    /** پروندهٔ کامل یک کاربر */
    public static function userCard($chatId, $msgId, int $uid): void
    {
        $u = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => $uid]);
        if (!$u) { Tg::send($chatId, '❌ کاربر یافت نشد.'); return; }

        $all = (int)DB::val('SELECT COUNT(*) FROM {p}services WHERE user_id = :u', [':u' => $uid], 0);
        $act = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE user_id = :u AND status = 'active'", [':u' => $uid], 0);
        $lvl = (int)($u['reseller_level'] ?? 0);
        $ban = (int)($u['is_banned'] ?? 0);
        $nm  = trim((string)$u['first_name'] . ' ' . (string)$u['last_name']);

        $t  = '👤 <b>پروندهٔ کاربر</b>' . "\n" . self::sep();
        $t .= '🆔 آیدی: <code>' . (int)$u['tg_id'] . '</code>' . "\n";
        $t .= '📛 نام: ' . h($nm !== '' ? $nm : 'بدون نام') . "\n";
        if (!empty($u['username'])) $t .= '🔗 یوزرنیم: @' . h((string)$u['username']) . "\n";
        if (!empty($u['phone']))    $t .= '📱 شماره: <code>' . h((string)$u['phone']) . '</code>' . "\n";
        $t .= self::sep();
        $t .= '💳 موجودی: <b>' . fa_num(number_format((int)$u['balance'])) . '</b> ' . currency() . "\n";
        $t .= '💰 مجموع پرداختی: ' . fa_num(number_format((int)($u['total_paid'] ?? 0))) . ' ' . currency() . "\n";
        $t .= '📦 سرویس‌ها: ' . fa_num((string)$all) . '  |  🟢 فعال: ' . fa_num((string)$act) . "\n";
        $t .= '🧪 تست دریافتی: ' . fa_num((string)(int)$u['test_count']) . "\n";
        if ($lvl > 0) $t .= '🏷 سطح نمایندگی: ' . fa_num((string)$lvl) . "\n";
        $t .= '📅 عضویت: ' . h(to_jalali((string)$u['created_at'])) . "\n";
        if (!empty($u['last_seen'])) $t .= '👁 آخرین بازدید: ' . h(to_jalali((string)$u['last_seen'])) . "\n";
        if ($ban) $t .= "\n" . '⛔️ <b>این کاربر مسدود است.</b>';

        $rows = [
            [Tg::btn('💰 تغییر موجودی', 'adm:ubal:' . $uid), Tg::btn('📦 سرویس‌ها', 'adm:usvc:' . $uid)],
            [$ban ? Tg::btn('✅ رفع مسدودی', 'adm:uunb:' . $uid) : Tg::btn('⛔️ مسدودسازی', 'adm:uban:' . $uid)],
            [Tg::btn('✉️ پیام مستقیم', 'adm:umsg:' . $uid)],
            [Tg::btn('👥 گروه‌ها', 'adm:usr'), Tg::btn('🔎 جستجو', 'adm:ufind'), Tg::btn('🏠 خانه', 'adm:home')],
        ];

        $kb = Tg::ikb($rows);
        $msgId ? Tg::edit($chatId, $msgId, $t, $kb) : Tg::send($chatId, $t, $kb);
    }

    /** فهرست سرویس‌های یک کاربر */
    public static function userServices($chatId, $msgId, int $uid): void
    {
        $rows = DB::all('SELECT * FROM {p}services WHERE user_id = :u ORDER BY id DESC LIMIT 20', [':u' => $uid]);

        $t  = '📦 <b>سرویس‌های کاربر</b>' . "\n" . self::sep();
        $kb = [];

        if (!$rows) {
            $t .= 'سرویسی برای این کاربر ثبت نشده است.';
        } else {
            foreach ($rows as $s) {
                $stt = (string)$s['status'];
                $ic  = $stt === 'active' ? '🟢' : ($stt === 'expired' ? '🔴' : '⚪️');
                $em  = (string)$s['client_email'];
                $t  .= $ic . ' <code>#' . (int)$s['id'] . '</code> ' . h($em)
                     . ' · ' . fa_num((string)(float)$s['volume_gb']) . ' گیگ';
                if (!empty($s['expire_at'])) $t .= ' · ' . h(to_jalali((string)$s['expire_at']));
                $t .= "\n";
                $kb[] = [Tg::btn($ic . ' #' . (int)$s['id'] . ' ' . mb_substr($em, 0, 22), 'adm:s:' . (int)$s['id'])];
            }
        }

        $kb[] = [Tg::btn('↩️ بازگشت به پرونده', 'adm:u:' . $uid)];
        $ikb  = Tg::ikb($kb);
        $msgId ? Tg::edit($chatId, $msgId, $t, $ikb) : Tg::send($chatId, $t, $ikb);
    }

    /** درخواست جستجوی سرویس */
    public static function askService($chatId): void
    {
        DB::update('users', ['state' => 'admin_svc_find', 'state_data' => null],
            'id = :id', [':id' => (int)(Bot::$u['id'] ?? 0)]);
        Tg::send($chatId, '📦 <b>مدیریت سرویس‌ها</b>' . "\n" . self::sep()
            . 'کد سرویس، نام کاربری (email) یا کد اشتراک را بفرستید:', Kb::cancel());
    }

    /** کارت یک سرویس */
    public static function serviceCard($chatId, $msgId, int $sid): void
    {
        $s = DB::one('SELECT * FROM {p}services WHERE id = :id', [':id' => $sid]);
        if (!$s) { Tg::send($chatId, '❌ سرویس یافت نشد.'); return; }

        $p   = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)$s['panel_id']]);
        $ow  = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)$s['user_id']]);
        $stt = (string)$s['status'];
        $ic  = $stt === 'active' ? '🟢' : ($stt === 'expired' ? '🔴' : '⚪️');
        $vol = (float)$s['volume_gb'];
        $usd = (int)$s['used_bytes'];

        $t  = $ic . ' <b>سرویس #' . $sid . '</b>' . "\n" . self::sep();
        $t .= '📛 نام کاربری: <code>' . h((string)$s['client_email']) . '</code>' . "\n";
        $t .= '🖥 سرور: ' . h((string)($p['name'] ?? '—')) . "\n";
        $t .= '📊 حجم: ' . ($vol > 0 ? fa_num((string)$vol) . ' گیگ' : 'نامحدود') . "\n";
        $t .= '📉 مصرف‌شده: ' . fa_num(human_bytes($usd)) . "\n";
        $t .= '📅 انقضا: ' . (!empty($s['expire_at']) ? h(to_jalali((string)$s['expire_at'])) : 'نامحدود') . "\n";
        $t .= '🔁 تمدیدها: ' . fa_num((string)(int)$s['renew_count']) . "\n";
        if ((int)$s['is_test'])     $t .= '🧪 این یک ��کانت تست است.' . "\n";
        if ((int)$s['is_reseller']) $t .= '🏷 ساخته‌شده توسط نماینده.' . "\n";
        $t .= self::sep();
        $t .= '👤 مالک: ' . h((string)($ow['first_name'] ?? '—'))
            . ' (<code>' . (int)($ow['tg_id'] ?? 0) . '</code>)' . "\n";
        if (!empty($s['last_sync'])) $t .= '🕒 آخرین همگام‌سازی: ' . h(to_jalali((string)$s['last_sync'])) . "\n";

        /* وضعیت آنلاین/آخرین اتصال (سنایی نسل جدید، پاسارگارد، مرزبان) */
        if ($p && $stt !== 'deleted' && class_exists('Xui')) {
            try {
                $xo = new Xui($p);
                if (method_exists($xo, 'supports') && $xo->supports('online')) {
                    $onl = $xo->isOnline((string)$s['client_email']);
                    $ls  = $xo->lastOnlineOf((string)$s['client_email']);
                    $t .= '📡 اتصال: ' . ($onl === null ? '—' : ($onl ? '🟢 آنلاین' : '⚪️ آفلاین'))
                        . ($ls > 0 ? ' · آخرین اتصال: ' . h(to_jalali(date('Y-m-d H:i:s', $ls), true)) : '') . "\n";
                }
            } catch (\Throwable $e) { }
        }

        if (class_exists('AdminHub')) {
            $rows = AdminHub::svcRows($s, $p ?: null);
        } else {
            $rows = [
                [Tg::btn('🔄 همگام‌سازی با سرور', 'adm:ssync:' . $sid)],
                [Tg::btn('👤 پروندهٔ مالک', 'adm:u:' . (int)$s['user_id'])],
                [Tg::btn('🔎 جستجوی دیگر', 'adm:svc'), Tg::btn('🏠 خانه', 'adm:home')],
            ];
        }

        $kb = Tg::ikb($rows);
        $msgId ? Tg::edit($chatId, $msgId, $t, $kb) : Tg::send($chatId, $t, $kb);
    }

    /** وضعیت زندهٔ سرورها */
    public static function panelsHealth($chatId, $msgId = null): void
    {
        $rows = DB::all('SELECT * FROM {p}panels ORDER BY sort ASC, id ASC');

        $t = '🖥 <b>وضعیت سرورها</b>' . "\n" . self::sep();

        if (!$rows) {
            $t .= 'هنوز سروری ثبت نشده است.';
        } else {
            $upC   = 0;
            $pbtns = [];
            foreach ($rows as $p) {
                $on  = (int)$p['active'];
                $cnt = (class_exists('Svc') && method_exists('Svc', 'panelUsage')) ? Svc::panelUsage((int)$p['id']) : 0;
                $ok  = false;
                $ms  = 0;

                if ($on) {
                    try {
                        if (class_exists('Xui') && method_exists('Xui', 'forPanel')) {
                            $x = Xui::forPanel($p);
                            if (is_object($x) && method_exists($x, 'healthCheck')) {
                                $t0 = microtime(true);
                                $r  = $x->healthCheck();
                                $ms = (int)round((microtime(true) - $t0) * 1000);
                                $ok = is_array($r) ? !empty($r['ok']) : (bool)$r;
                            }
                        }
                    } catch (\Throwable $e) {
                        $ok = false;
                    }
                }
                if ($ok) $upC++;

                $ic = !$on ? '⚪️' : ($ok ? '🟢' : '🔴');
                $pbtns[] = Tg::btn($ic . ' ' . mb_substr((string)$p['name'], 0, 18) . ' · ' . fa_num((string)$cnt), 'adm:p:' . (int)$p['id']);
                $t .= $ic . ' <b>' . h((string)$p['name']) . '</b>' . "\n";
                $t .= '   ⤷ ' . h((string)$p['host']) . ':' . (int)$p['port']
                    . ' · 👥 ' . fa_num((string)$cnt);
                if ($on && $ms > 0) $t .= ' · ⚡️ ' . fa_num((string)$ms) . ' ms';
                if (!$on) $t .= ' · غیرفعال';
                $t .= "\n";

                if (!$ok && $on && !empty($p['last_error'])) {
                    $t .= '   ⚠️ ' . h(mb_substr((string)$p['last_error'], 0, 90)) . "\n";
                }
            }
            $t .= self::sep();
            $t .= '📈 در دسترس: <b>' . fa_num((string)$upC) . '</b> از ' . fa_num((string)count($rows));
        }

        $kbRows = [];
        if (!empty($pbtns) && class_exists('AdminHub')) {
            $t .= "\n" . 'برای کارت هر سرور (منابع، اینباندها، فعال/غیرفعال، سرویس‌ها) روی نام آن بزنید.';
            foreach (array_chunk($pbtns, 2) as $chunk) $kbRows[] = $chunk;
        }
        $kbRows[] = [Tg::btn('🔄 بررسی مجدد', 'adm:pnl'), Tg::btn('🖥 آمار سرورها', 'adm:stpnl')];
        $kbRows[] = [Tg::btn('🏠 بازگشت', 'adm:home')];
        $kb = Tg::ikb($kbRows);
        $msgId ? Tg::edit($chatId, $msgId, $t, $kb) : Tg::send($chatId, $t, $kb);
    }

    /** خلاصهٔ انبار ملی */
    public static function stockPanel($chatId, $msgId = null): void
    {
        if (!class_exists('Stock')) {
            Tg::send($chatId, '⚠️ ماژول انبار در دسترس نیست.');
            return;
        }

        $st  = Stock::stats();
        $low = Stock::lowStock();

        $t  = '🏪 <b>' . h(Stock::title()) . '</b>' . "\n" . self::sep();
        $t .= '📦 کل اقلام: <b>' . fa_num((string)(int)$st['total']) . '</b>' . "\n";
        $t .= '🟢 آزاد: <b>' . fa_num((string)(int)$st['free']) . '</b>' . "\n";
        $t .= '🔵 فروخته‌شده: ' . fa_num((string)(int)$st['sold']) . "\n";
        $t .= '📅 فروش امروز: ' . fa_num((string)(int)$st['today']) . "\n";
        $t .= '🗂 دسته‌ها: ' . fa_num((string)(int)$st['cats']) . "\n";
        $t .= '💰 درآمد ۳۰ روز: <b>' . fa_num(number_format(Stock::revenue(30))) . '</b> ' . currency() . "\n";

        if ($low) {
            $t .= self::sep() . '⚠️ <b>کمبود موجودی</b>' . "\n";
            foreach ($low as $l) {
                $t .= '• ' . h((string)$l['name']) . ' — ' . fa_num((string)(int)$l['free']) . ' عدد' . "\n";
            }
        }

        $t .= self::sep() . 'ℹ️ افزودن موجودی و ساخت دسته از پنل تحت وب انجام می‌شود.';

        $kb = Tg::ikb([
            [Tg::btn('🔄 بررسی مجدد', 'adm:stk')],
            [Tg::btn('🌐 ورود به پنل تحت وب', 'adm:web')],
            [Tg::btn('🏠 بازگشت', 'adm:home')],
        ]);
        $msgId ? Tg::edit($chatId, $msgId, $t, $kb) : Tg::send($chatId, $t, $kb);
    }

    /** پشتیبان‌گیری فوری */
    public static function backupNow($chatId, $cbId): void
    {
        Tg::answerCb($cbId, '⏳ در حال تهیهٔ پشتیبان…');

        if (!class_exists('Backup')) {
            Tg::send($chatId, '⚠️ ماژول پشتیبان‌گیری در دسترس نیست.');
            return;
        }

        Tg::send($chatId, '💾 در حال تهیهٔ نسخهٔ پشتیبان… چند لحظه صبر کنید.');

        try {
            $r = Backup::create('db', 'دستی از داخل ربات', true);
        } catch (\Throwable $e) {
            Tg::send($chatId, '❌ خطا در پشتیبان‌گیری:' . "\n" . '<code>' . h($e->getMessage()) . '</code>');
            return;
        }

        $ok = !empty($r['ok']);
        $t  = ($ok ? '✅ <b>پشتیبان‌گیری انجام شد.</b>' : '❌ <b>پشتیبان‌گیری ناموفق بود.</b>') . "\n" . self::sep();
        if (!empty($r['name']))    $t .= '📄 فایل: <code>' . h((string)$r['name']) . '</code>' . "\n";
        if (!empty($r['size']))    $t .= '💽 حجم: ' . fa_num(human_bytes((int)$r['size'])) . "\n";
        if (!empty($r['message'])) $t .= 'ℹ️ ' . h((string)$r['message']) . "\n";

        if (method_exists('Backup', 'nextRun')) {
            $t .= '⏱ اجرای خودکار بعدی: ' . h((string)Backup::nextRun());
        }

        Tg::send($chatId, $t, Tg::ikb([[Tg::btn('🏠 بازگشت', 'adm:home')]]));
    }

    /** تنظیمات سریع — کلیدهای روشن/خاموش */
    public static function quickSettings($chatId, $msgId = null): void
    {
        $t = '⚙️ <b>تنظیمات سریع</b>' . "\n" . self::sep()
           . 'با هر ضربه، وضعیت همان بخش تغییر می‌کند.' . "\n";

        $rows = [];
        $line = [];
        foreach (self::QTOGGLES as $k => $m) {
            $on = self::toggleOn($k);
            $t .= ($on ? '🟢' : '⚪️') . ' ' . $m[1] . "\n";
            $line[] = Tg::btn(($on ? '🟢 ' : '⚪️ ') . $m[1], 'adm:tg:' . $k);
            if (count($line) === 2) { $rows[] = $line; $line = []; }
        }
        if ($line) $rows[] = $line;

        /* تنظیمات عددی (مهلت‌ها، هشدارها، محدودهٔ حجم/زمان دلخواه) */
        if (class_exists('AdminHub')) {
            $t .= self::sep() . '🔢 <b>مقادیر عددی</b> — با زدن هر مورد، مقدار جدید را بفرستید:' . "\n";
            $line = [];
            foreach (AdminHub::NUMS as $nk => $nm) {
                $cur = (string)DB::setting($nm[0], (string)$nm[4]);
                $t .= '• ' . $nm[1] . ': <b>' . fa_num($cur) . '</b>' . "\n";
                $line[] = Tg::btn($nm[1] . ' (' . fa_num($cur) . ')', 'adm:num:' . $nk);
                if (count($line) === 2) { $rows[] = $line; $line = []; }
            }
            if ($line) $rows[] = $line;
        }
        $t .= self::sep() . 'تنظیمات کامل (متن‌ها، درگاه‌ها، کانال‌ها، قیمت‌ها) در پنل وب → تنظیمات.';

        $rows[] = [Tg::btn('🌐 تنظیمات کامل (وب)', 'adm:web'), Tg::btn('🏠 بازگشت', 'adm:home')];

        $kb = Tg::ikb($rows);
        $msgId ? Tg::edit($chatId, $msgId, $t, $kb) : Tg::send($chatId, $t, $kb);
    }

    /** تغییر وضعیت یک کلید تنظیمات */
    public static function toggleSetting($chatId, $msgId, $cbId, string $k): void
    {
        if (!isset(self::QTOGGLES[$k])) { Tg::answerCb($cbId); return; }

        $key   = self::QTOGGLES[$k][0];
        $label = self::QTOGGLES[$k][1];
        $on    = self::toggleOn($k);

        DB::setSetting($key, $on ? '0' : '1');
        if (class_exists('Audit')) Audit::log('setting.toggle', ['key' => $key, 'label' => $label, 'value' => $on ? '0' : '1']);
        Tg::answerCb($cbId, $label . ($on ? ' خاموش شد' : ' روشن شد'));
        self::quickSettings($chatId, $msgId);
    }

    public static function webLoginLink(int $tgId = 0): string
    {
        // اول تلاش می‌کنیم حساب مدیریتی متصل به همین آیدی تلگرام را پیدا کنیم
        $admin = null;
        if ($tgId > 0) {
            $admin = DB::one('SELECT * FROM {p}admins WHERE tg_id = :t AND active = 1 LIMIT 1', [':t' => $tgId]);
        }
        // در غیر این صورت، اولین مدیر کل فعال
        if (!$admin) {
            $admin = DB::one("SELECT * FROM {p}admins WHERE active = 1 AND LOWER(role) IN ('super', 'owner', 'root') ORDER BY id ASC LIMIT 1");
        }
        if (!$admin) {
            $admin = DB::one('SELECT * FROM {p}admins WHERE active = 1 ORDER BY id ASC LIMIT 1');
        }
        if (!$admin) return '';
        $token = bin2hex(random_bytes(16));
        DB::update('admins', ['token' => $token, 'token_at' => now()], 'id = :id', [':id' => (int)$admin['id']]);
        return app_url('admin/index.php?token=' . $token);
    }
}
