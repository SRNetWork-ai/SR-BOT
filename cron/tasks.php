<?php
/**
 * وظایف دوره‌ای فروشگاه
 * اجرا: php /path/to/cron/tasks.php   (هر ۵ تا ۱۵ دقیقه یکبار)
 *
 * کارها:
 *  1) همگام‌سازی مصرف سرویس‌ها از پنل
 *  2) هشدار نزدیک شدن انقضا و پر شدن حجم
 *  3) منقضی کردن سرویس‌های تمام شده
 *  4) پاکسازی لاگ‌ها و رسیدهای قدیمی
 */

@set_time_limit(0);
@ini_set('memory_limit', '256M');

require __DIR__ . '/../app/bootstrap.php';

if (!app_installed()) {
    fwrite(STDERR, "VPN Shop is not installed yet.\n");
    exit(1);
}

boot();

/* ضربان اجرا برای صفحه سلامت سیستم */
if (!is_dir(APP_ROOT . '/storage')) @mkdir(APP_ROOT . '/storage', 0755, true);
@file_put_contents(APP_ROOT . '/storage/last-cron.txt', date('Y-m-d H:i:s'));

$isCli   = PHP_SAPI === 'cli';
$started = microtime(true);
$report  = ['synced' => 0, 'expired' => 0, 'warned_expire' => 0, 'warned_traffic' => 0, 'disabled' => 0,
            'sync_fail' => 0, 'nowpay' => 0, 'recovered' => 0, 'hooshpay' => 0, 'gw_expired' => 0, 'log_queue' => 0,
            'warned_stage2' => 0, 'auto_renewed' => 0, 'auto_nofunds' => 0, 'auto_failed' => 0];

function cron_say(string $msg): void
{
    if (PHP_SAPI === 'cli') echo $msg . PHP_EOL;
    app_log('cron', $msg);
}

/* قفل ساده برای جلوگیری از اجرای هم‌زمان */
$lockFile = APP_ROOT . '/storage/cron.lock';
$lock = @fopen($lockFile, 'c');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    cron_say('cron already running, skipping this round');
    exit(0);
}

/* ---------------------------------------------------------------
 * حذف خودکار پوشه نصب install پس از تکمیل نصب
 * --------------------------------------------------------------- */
if (is_dir(APP_ROOT . '/install') && is_file(APP_ROOT . '/storage/installed.lock')) {
    try {
        $rrmInstall = function (string $dir) use (&$rrmInstall): void {
            $ls = @scandir($dir);
            if (!is_array($ls)) return;
            foreach ($ls as $f) {
                if ($f === '.' || $f === '..') continue;
                $p = $dir . '/' . $f;
                if (is_dir($p) && !is_link($p)) { $rrmInstall($p); } else { @unlink($p); }
            }
            @rmdir($dir);
        };
        $rrmInstall(APP_ROOT . '/install');
        if (!is_dir(APP_ROOT . '/install')) cron_say('پوشه install به صورت خودکار حذف شد');
    } catch (Throwable $e) {
        app_log('cron', 'حذف پوشه install ناموفق: ' . $e->getMessage());
    }
}

$notifyDays    = (int)DB::setting('expire_notify_days', 3);
$notifyPercent = (int)DB::setting('traffic_notify_percent', 85);

/* ---------------------------------------------------------------
 * 0) نرخ خودکار ارز و پیگیری پرداخت‌های ارزی
 * --------------------------------------------------------------- */
if (Rates::mode() === 'auto') {
    $rr = Rates::refresh(false);
    if (!empty($rr['ok']) && empty($rr['cached'])) {
        cron_say('rate updated => ' . (string)($rr['rate'] ?? 0));
    } elseif (empty($rr['ok'])) {
        cron_say('rate error: ' . (string)($rr['message'] ?? ''));
    }
}

if (NowPay::enabled()) {
    $waiting = DB::all("SELECT * FROM {p}transactions
                        WHERE method = 'nowpay' AND status = 'pending'
                          AND created_at > DATE_SUB(NOW(), INTERVAL 3 DAY)
                        ORDER BY id ASC LIMIT 25");
    foreach ($waiting as $tx) {
        $pr = NowPay::poll($tx);
        if (!empty($pr['ok'])) {
            $report['nowpay']++;
            cron_say('nowpay tx #' . (int)$tx['id'] . ' => ' . (string)($pr['status'] ?? 'done'));
        }
        usleep(250000);
    }
}

/* fixed84: استعلام خودکار فاکتورهای هوش‌پی تا صف رسیدهای پنل وب پر نشود */
if (class_exists('HooshPay') && HooshPay::enabled()) {
    $hpWait = DB::all("SELECT * FROM {p}transactions
                       WHERE method = 'hooshpay' AND status = 'pending'
                         AND created_at > DATE_SUB(NOW(), INTERVAL 2 DAY)
                       ORDER BY id ASC LIMIT 25");
    foreach ($hpWait as $tx) {
        try {
            $pr = HooshPay::poll($tx);
            if (!empty($pr['ok'])) {
                $report['hooshpay']++;
                cron_say('hooshpay tx #' . (int)$tx['id'] . ' => ' . (string)($pr['message'] ?? 'done'));
            }
        } catch (Throwable $e) {
            cron_say('hooshpay poll failed: ' . $e->getMessage());
        }
        usleep(250000);
    }
}

/* fixed84: فاکتورهای درگاه که کاربر هرگز پرداخت نکرده، پس از چند ساعت لغو می‌شوند */
try {
    $gwHours = max(1, (int)DB::setting('gw_stale_hours', '6'));
    $gwAuto  = class_exists('Wallet') ? Wallet::autoSqlList() : "'hooshpay','nowpay','zarinpal'";
    $gwStale = DB::all("SELECT id FROM {p}transactions
                        WHERE status = 'pending' AND type = 'deposit' AND method IN ($gwAuto)
                          AND created_at < DATE_SUB(NOW(), INTERVAL $gwHours HOUR)
                        ORDER BY id ASC LIMIT 200");
    foreach ($gwStale as $g) {
        $rj = Wallet::reject((int)$g['id'], null, 'فاکتور درگاه بدون پرداخت منقضی شد', false);
        if (!empty($rj['ok'])) $report['gw_expired']++;
    }
    if ($report['gw_expired'] > 0) cron_say('gateway invoices canceled: ' . (int)$report['gw_expired']);
} catch (Throwable $e) {
    cron_say('gateway cleanup failed: ' . $e->getMessage());
}

/* fixed85: ارسال گزارش‌های معلق تلگرام (صف آفلاین لاگ) */
try {
    if (class_exists('Logs') && Logs::enabled() && Logs::queueSize() > 0) {
        $lq = Logs::flushQueue(40);
        $report['log_queue'] = (int)($lq['sent'] ?? 0);
        cron_say('log queue: ' . (int)($lq['sent'] ?? 0) . ' sent, ' . (int)($lq['left'] ?? 0) . ' left');
    }
} catch (Throwable $e) {
    cron_say('log queue flush failed: ' . $e->getMessage());
}

/* ---------------------------------------------------------------
 * 1) همگام‌سازی مصرف (قدیمی‌ترین‌ها اول)
 * --------------------------------------------------------------- */
$batch = DB::all("SELECT * FROM {p}services
                  WHERE status IN ('active','disabled')
                  ORDER BY (last_sync IS NULL) DESC, last_sync ASC
                  LIMIT 60");

foreach ($batch as $s) {
    $r = Svc::sync($s);
    if (!empty($r['ok'])) $report['synced']++;
    else $report['sync_fail']++;
    usleep(120000);
}

/* اگر هیچ همگام‌سازی موفق نبود، احتمالاً اتصال پنل قطع است (حداکثر یک هشدار در ۲۴ ساعت) */
if ($report['synced'] === 0 && $report['sync_fail'] >= 3) {
    $lastWarn = (int)DB::setting('panels_warn_last', 0);
    $warnH    = max(1, (int)DB::setting('rep_warnconn_h', 24));
    if (time() - $lastWarn >= $warnH * 3600) {
        DB::setSetting('panels_warn_last', (string)time());
        Logs::send('panels', Logs::fmt('⚠ هشدار اتصال پنل', [
            'سرویس بررسی‌شده' => fa_num((string)count($batch)),
            'همگام‌سازی موفق' => fa_num('0'),
            'زمان'              => to_jalali(now(), true),
        ], 'وضعیت پنل‌ها را از پنل مدیریت بررسی کنید.'));
    }
}

/* ---------------------------------------------------------------
 * 1.5) گزارش روزانهٔ وضعیت پنل‌ها — هر ۲۴ ساعت فقط یک‌بار
 * --------------------------------------------------------------- */
try {
    $lastRep = (int)DB::setting('panels_report_last', 0);
    $repH    = (int)DB::setting('rep_panels_h', 24);
    if ($repH > 0 && time() - $lastRep >= $repH * 3600) {
        DB::setSetting('panels_report_last', (string)time());
        $pnAll = DB::all('SELECT * FROM {p}panels ORDER BY sort ASC, id ASC');
        if ($pnAll) {
            $pTxt = '';
            $pUp  = 0;
            $pAct = 0;
            foreach ($pnAll as $pn) {
                $isOn = (int)($pn['active'] ?? 1) === 1;
                $ok   = false;
                $ms   = 0;
                if ($isOn) {
                    $pAct++;
                    try {
                        $t0 = microtime(true);
                        $hc = Xui::forPanel($pn)->healthCheck();
                        $ms = (int)round((microtime(true) - $t0) * 1000);
                        $ok = is_array($hc) ? !empty($hc['ok']) : (bool)$hc;
                    } catch (Throwable $e) {
                        $ok = false;
                    }
                    if ($ok) $pUp++;
                }
                $cnt = 0;
                try { $cnt = (int)Svc::panelUsage((int)$pn['id']); } catch (Throwable $e) {}
                /* پنل نسل جدید: تعداد کاربران آنلاین هم نمایش داده می‌شود */
                $onl = -1;
                if ($isOn && $ok && class_exists('Xui3') && Xui3::hasToken($pn)) {
                    try {
                        $x3d = new Xui3($pn); $ox = method_exists($x3d, 'onlinesAll') ? $x3d->onlinesAll() : $x3d->onlines();
                        if (is_array($ox)) $onl = count($ox);
                    } catch (Throwable $e) { $onl = -1; }
                }
                $pTxt .= (!$isOn ? '⚪️' : ($ok ? '🟢' : '🔴')) . ' <b>' . h((string)($pn['name'] ?? ('#' . $pn['id']))) . '</b>'
                       . ' · 👥 ' . fa_num((string)$cnt)
                       . ($onl >= 0 ? ' · 📡 آنلاین ' . fa_num((string)$onl) : '')
                       . ($isOn && $ms > 0 ? ' · ⚡️ ' . fa_num((string)$ms) . 'ms' : '')
                       . (!$isOn ? ' · غیرفعال' : '')
                       . "\n";
            }
            Logs::send('panels',
                "🖥 <b>گزارش دوره‌ای پنل‌ها</b>\n<code>───────────────</code>\n"
                . $pTxt
                . "<code>───────────────</code>\n"
                . '📈 در دسترس: <b>' . fa_num((string)$pUp) . '</b> از ' . fa_num((string)$pAct) . ' پنل فعال'
                . "\n🕒 " . to_jalali(now(), true));
            $report['panels_report'] = 1;
        }
    }
} catch (Throwable $e) {
    app_log('cron_panels_report: ' . $e->getMessage());
}

/* ---------------------------------------------------------------
 * 1.6) گزارش‌های دوره‌ای قابل تنظیم — فروش، کاربران جدید، پرداخت‌های در انتظار
 * بازهٔ هر گزارش از «تنظیمات ← گزارشات» قابل تغییر است. صفر = خاموش.
 * --------------------------------------------------------------- */
try {
    if (Logs::enabled()) {
        foreach (['sales', 'users', 'pays'] as $rk) {
            $rh = (int)DB::setting('rep_' . $rk . '_h', 0);
            if ($rh <= 0) continue;
            $rl = (int)DB::setting('rep_' . $rk . '_last', 0);
            if (time() - $rl < $rh * 3600) continue;
            DB::setSetting('rep_' . $rk . '_last', (string)time());
            $rFrom = date('Y-m-d H:i:s', $rl > 0 ? $rl : time() - $rh * 3600);

            if ($rk === 'sales') {
                $rC = (int)DB::val("SELECT COUNT(*) FROM {p}orders WHERE status = 'paid' AND created_at >= :a", [':a' => $rFrom], 0);
                $rS = (int)DB::val("SELECT COALESCE(SUM(final_amount),0) FROM {p}orders WHERE status = 'paid' AND created_at >= :a", [':a' => $rFrom], 0);
                Logs::send('financial', Logs::fmt('💰 گزارش فروش دوره‌ای', [
                    'از زمان'    => to_jalali($rFrom, true),
                    'خرید موفق'  => fa_num((string)$rC),
                    'جمع فروش'   => money($rS) . ' ' . currency(),
                ], 'بازهٔ این گزارش: هر ' . fa_num((string)$rh) . ' ساعت'));
            } elseif ($rk === 'users') {
                $rN = (int)DB::val('SELECT COUNT(*) FROM {p}users WHERE created_at >= :a', [':a' => $rFrom], 0);
                $rT = (int)DB::val('SELECT COUNT(*) FROM {p}users', [], 0);
                Logs::send('users', Logs::fmt('👥 گزارش کاربران', [
                    'از زمان'     => to_jalali($rFrom, true),
                    'کاربر جدید'  => fa_num((string)$rN),
                    'کل کاربران'  => fa_num((string)$rT),
                ], 'بازهٔ این گزارش: هر ' . fa_num((string)$rh) . ' ساعت'));
            } else {
                $rP = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE status = 'pending'", [], 0);
                if ($rP > 0) {
                    $rA = (int)DB::val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE status = 'pending' AND amount > 0", [], 0);
                    Logs::send('financial', Logs::fmt('⏳ پرداخت‌های در انتظار تایید', [
                        'تعداد'     => fa_num((string)$rP),
                        'جمع مبلغ'  => money($rA) . ' ' . currency(),
                    ], 'از پنل مدیریت بخش پرداخت‌ها بررسی و تایید کنید.'));
                }
            }
        }
    }
} catch (Throwable $e) {
    cron_say('periodic reports error: ' . $e->getMessage());
}

/* ---------------------------------------------------------------
 * 2) هشدارها
 *    notified: bit1 = هشدار انقضا ، bit2 = هشدار حجم ، bit4 = اطلاع انقضا
 * --------------------------------------------------------------- */
if ($notifyDays > 0) {
    $soon = DB::all("SELECT s.*, u.tg_id AS utg FROM {p}services s
                     LEFT JOIN {p}users u ON u.id = s.user_id
                     WHERE s.status = 'active' AND s.is_test = 0
                       AND s.expire_at IS NOT NULL
                       AND s.expire_at > NOW()
                       AND s.expire_at <= DATE_ADD(NOW(), INTERVAL :d DAY)
                       AND (s.notified & 1) = 0
                     LIMIT 80", [':d' => $notifyDays]);

    foreach ($soon as $s) {
        $txt = "⏰ <b>یادآوری انقضای سرویس</b>\n\n"
             . "🔑 نام کاربری: <code>" . h((string)$s['client_email']) . "</code>\n"
             . "📅 باقی‌مانده: " . remaining_human((string)$s['expire_at']) . "\n\n"
             . "برای جلوگیری از قطع سرویس، از بخش «📦 سرویس‌های من» تمدید کنید.";
        $kbw = [[Tg::btn('♻️ تمدید سرویس', 'svcrn:' . (int)$s['id'])]];
        /* fixed74: پیشنهاد فعال‌سازی تمدید خودکار */
        if (class_exists('AutoRenew') && AutoRenew::enabled()) $kbw[] = [Tg::btn('🔁 فعال‌سازی تمدید خودکار', 'arn:on')];
        Tg::send((int)$s['utg'], $txt, Tg::ikb($kbw));
        DB::update('services', ['notified' => (int)$s['notified'] | 1], 'id = :id', [':id' => (int)$s['id']]);
        $report['warned_expire']++;
        usleep(120000);
    }
}

/* fixed74: یادآوری مرحلهٔ دوم (نزدیک‌تر به انقضا، بیت 32) */
if (class_exists('AutoRenew')) {
    try {
        $n2 = AutoRenew::remindStage2(80);
        if ($n2 > 0) { $report['warned_stage2'] = $n2; cron_say('stage2 reminders: ' . $n2); }
    } catch (Throwable $e) {
        app_log('cron', 'stage2 reminder failed: ' . $e->getMessage());
    }
}

/* ---------------------------------------------------------------
 * 2.5) تمدید خودکار از کیف پول (fixed74) — قبل از منقضی شدن
 * --------------------------------------------------------------- */
if (class_exists('AutoRenew') && AutoRenew::enabled()) {
    try {
        $ar = AutoRenew::run(30);
        $report['auto_renewed'] = (int)($ar['renewed'] ?? 0);
        $report['auto_nofunds'] = (int)($ar['nofunds'] ?? 0);
        $report['auto_failed']  = (int)($ar['failed'] ?? 0);
        if ((int)($ar['checked'] ?? 0) > 0) {
            cron_say('auto-renew: checked=' . (int)$ar['checked'] . ' renewed=' . (int)$ar['renewed']
                . ' nofunds=' . (int)$ar['nofunds'] . ' failed=' . (int)$ar['failed']);
        }
    } catch (Throwable $e) {
        app_log('cron', 'auto-renew failed: ' . $e->getMessage());
    }
}

if ($notifyPercent > 0 && $notifyPercent < 100) {
    $heavy = DB::all("SELECT s.*, u.tg_id AS utg FROM {p}services s
                      LEFT JOIN {p}users u ON u.id = s.user_id
                      WHERE s.status = 'active' AND s.is_test = 0 AND s.volume_gb > 0
                        AND (s.notified & 2) = 0
                        AND s.used_bytes >= (s.volume_gb * 1073741824 * :p / 100)
                      LIMIT 80", [':p' => $notifyPercent]);

    foreach ($heavy as $s) {
        $used = human_bytes((int)$s['used_bytes']);
        $txt = "📊 <b>حجم سرویس شما رو به پایان است</b>\n\n"
             . "🔑 <code>" . h((string)$s['client_email']) . "</code>\n"
             . "📈 مصرف شده: " . $used . " از " . fa_num((string)(float)$s['volume_gb']) . " گیگ\n\n"
             . "برای جلوگیری از قطع اتصال، سرویس را تمدید کنید.";
        Tg::send((int)$s['utg'], $txt, Tg::ikb([[Tg::btn('♻️ تمدید سرویس', 'svcrn:' . (int)$s['id'])]]));
        DB::update('services', ['notified' => (int)$s['notified'] | 2], 'id = :id', [':id' => (int)$s['id']]);
        $report['warned_traffic']++;
        usleep(120000);
    }
}

/* ---------------------------------------------------------------
 * 3) منقضی کردن سرویس‌های تمام شده (زمان یا حجم)
 * --------------------------------------------------------------- */
$dead = DB::all("SELECT s.*, u.tg_id AS utg FROM {p}services s
                 LEFT JOIN {p}users u ON u.id = s.user_id
                 WHERE s.status = 'active'
                   AND (
                        (s.expire_at IS NOT NULL AND s.expire_at <= NOW())
                     OR (s.volume_gb > 0 AND s.used_bytes >= s.volume_gb * 1073741824)
                   )
                 LIMIT 60");

$hasExpAt = false;
try { $hasExpAt = Svc::hasExpiredAt(); } catch (Throwable $e) { $hasExpAt = false; }
foreach ($dead as $s) {
    $res = Svc::toggle($s, false);
    if (!empty($res['ok'])) $report['disabled']++;
    $expData = ['status' => 'expired', 'notified' => (int)$s['notified'] | 4];
    if ($hasExpAt) $expData['expired_at'] = now();
    DB::update('services', $expData, 'id = :id', [':id' => (int)$s['id']]);
    $report['expired']++;

    if (((int)$s['notified'] & 4) === 0 && !(int)$s['is_test']) {
        Tg::send((int)$s['utg'],
            "❌ <b>سرویس شما به پایان رسید</b>\n\n"
            . "🔑 <code>" . h((string)$s['client_email']) . "</code>\n\n"
            . "برای ادامه استفاده می‌توانید همین سرویس را تمدید کنید یا سرویس جدید بخرید.",
            Tg::ikb([
                [Tg::btn('♻️ تمدید سرویس', 'svcrn:' . (int)$s['id'])],
                [Tg::btn('🛒 خرید سرویس جدید', 'menu:products')],
            ]));
        usleep(120000);
    }
}

/* ---------------------------------------------------------------
 * 3.4) سرویس‌های چندپنلی: حجم و زمان مشترک گروه
 * چند کانفیگ روی چند پنل که یک لینک ساب مشترک دارند با هم حساب
 * می‌شوند. وقتی مجموع مصرف گروه به سقف برسد یا زمان تمام شود،
 * همهٔ کانفیگ‌های آن گروه روی همهٔ پنل‌ها قطع می‌شوند.
 * --------------------------------------------------------------- */
try {
    $groups = DB::all("SELECT `group_key`,
                              MAX(group_quota_gb) AS quota_gb,
                              SUM(used_bytes)     AS used_total,
                              MIN(expire_at)      AS first_expire
                       FROM {p}services
                       WHERE `group_key` IS NOT NULL AND `group_key` <> ''
                         AND status = 'active'
                       GROUP BY `group_key`
                       LIMIT 200");

    foreach ($groups as $g) {
        $gk    = (string)($g['group_key'] ?? '');
        if ($gk === '') continue;

        $quota = (float)($g['quota_gb'] ?? 0);
        $used  = (int)($g['used_total'] ?? 0);
        $exp   = trim((string)($g['first_expire'] ?? ''));

        $overQuota = $quota > 0 && $used >= $quota * 1073741824;
        $expired   = $exp !== '' && strtotime($exp) > 0 && strtotime($exp) <= time();
        if (!$overQuota && !$expired) continue;

        $members = DB::all("SELECT s.*, u.tg_id AS utg FROM {p}services s
                            LEFT JOIN {p}users u ON u.id = s.user_id
                            WHERE s.`group_key` = :g AND s.status = 'active'", [':g' => $gk]);

        $notifyTo = 0;
        $wasQuiet = true;
        foreach ($members as $m) {
            $r = Svc::toggle($m, false);
            if (!empty($r['ok'])) $report['disabled']++;
            DB::update('services', ['status' => 'expired', 'notified' => (int)$m['notified'] | 4],
                'id = :id', [':id' => (int)$m['id']]);
            $report['expired']++;

            if ($notifyTo === 0) $notifyTo = (int)$m['utg'];
            if (((int)$m['notified'] & 4) !== 0) $wasQuiet = false;
            usleep(80000);
        }

        if ($notifyTo > 0 && $wasQuiet) {
            Tg::send($notifyTo,
                "❌ <b>سرویس شما به پایان رسید</b>\n\n"
                . ($overQuota
                    ? "📊 حجم مشترک این سرویس تمام شد.\n"
                    : "⏳ زمان این سرویس تمام شد.\n")
                . "\nکانفیگ‌های همهٔ سرورهای این سرویس قطع شدند.");
            usleep(120000);
        }

        cron_say('group ' . $gk . ' closed (' . ($overQuota ? 'quota' : 'time') . ')');
    }
} catch (Throwable $e) {
    cron_say('group enforce error: ' . $e->getMessage());
}

/* --------------------------------- 3.4) حذف خودکار کانفیگ‌های تمام‌شده پس از N روز بدون تمدید */
try {
    $ad = Svc::autoDeleteExpired();
    if ((int)($ad['deleted'] ?? 0) > 0 || (int)($ad['warned'] ?? 0) > 0) {
        cron_say('auto-delete expired: deleted=' . (int)$ad['deleted'] . ' warned=' . (int)$ad['warned']);
    }
} catch (Throwable $e) {
    cron_say('auto-delete failed: ' . $e->getMessage());
}

/* --------------------------------- 3.5) بازیابی سفارش‌های نیمه‌کاره (گیرکرده) */
try {
    $rec = Orders::recoverStuck(null, 25);
    $report['recovered'] = (int)($rec['linked'] ?? 0) + (int)($rec['refunded'] ?? 0);
    if ($report['recovered'] > 0) {
        cron_say('recovered stuck orders: linked=' . (int)($rec['linked'] ?? 0)
            . ' refunded=' . (int)($rec['refunded'] ?? 0));
    }
} catch (Throwable $e) {
    cron_say('order recovery failed: ' . $e->getMessage());
}

/* ---------------------------------------------------------------
 * 4) پاکسازی
 * --------------------------------------------------------------- */
/* fixed75: مدت نگهداری لاگ اقدامات مدیران از تنظیمات (پیش‌فرض ۹۰ روز) */
$logsKeep = max(7, (int)DB::setting('logs_keep_days', '90'));
DB::q('DELETE FROM {p}logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $logsKeep . ' DAY)');
DB::q("UPDATE {p}transactions SET status = 'rejected', note = CONCAT(COALESCE(note,''), ' | منقضی شده خودکار')
       WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
/* سطل زباله: پاک سازی کامل کانفیگ های حذف شده پس از N روز */
try {
    if (class_exists('Svc')) {
        $trashed = Svc::purgeTrash();
        if ($trashed > 0) cron_say('trash purge: ' . $trashed . ' rows (older than ' . Svc::trashDays() . ' days)');
    }
} catch (Throwable $e) {
    cron_say('trash purge failed: ' . $e->getMessage());
}


/* پاک کردن لاگ‌های فایلی قدیمی‌تر از ۱۴ روز */
$logDir = APP_ROOT . '/storage/logs';
if (is_dir($logDir)) {
    foreach ((array)glob($logDir . '/app-*.log') as $f) {
        if (is_file($f) && filemtime($f) < time() - 14 * 86400) @unlink($f);
    }
}

/* پاکسازی قفل‌های ورود منقضی seclock تا جدول تنظیمات سنگین نشود */
try {
    $slDel = 0;
    foreach (DB::all("SELECT `k`, `v` FROM {p}settings WHERE `k` LIKE 'seclock%'") as $sl) {
        $sv  = (string)($sl['v'] ?? '');
        $sd  = $sv === '' ? [] : jdec($sv, []);
        $slU = (int)($sd['until'] ?? 0);
        $slN = (int)($sd['n'] ?? 0);
        $slA = (int)($sd['at'] ?? 0);
        $stale = $sv === ''
            || ($slN === 0 && $slU < time())
            || ($slU > 0 && $slU < time() - 7 * 86400)
            || ($slA > 0 && $slA < time() - 7 * 86400 && $slU < time());
        if ($stale) {
            DB::q('DELETE FROM {p}settings WHERE `k` = :k', [':k' => (string)$sl['k']]);
            $slDel++;
        }
    }
    if ($slDel > 0) cron_say('seclock cleanup: ' . $slDel . ' rows');
} catch (Throwable $e) {
    cron_say('seclock cleanup failed: ' . $e->getMessage());
}

/* پاکسازی فایل‌های ضداسپم و کش عضویت جوین اجباری */
try { if (class_exists('Flood')) Flood::cleanup(); } catch (Throwable $e) { }
try {
    $fjDir = APP_ROOT . '/storage/fjcache';
    if (is_dir($fjDir)) {
        foreach ((array)glob($fjDir . '/u*') as $f) {
            if (is_file((string)$f) && (int)@filemtime((string)$f) < time() - 86400) @unlink((string)$f);
        }
    }
} catch (Throwable $e) { }

/* پشتیبان‌گیری خودکار طبق زمان‌بندی پنل مدیریت */
try {
    $bk = Backup::autoRun();
        if (class_exists('\\App\\Service\\Logs')) {
            \App\Service\Logs::event('cron', '⏱ چرخهٔ کرانجاب اجرا شد', [
                'زمان' => now(),
            ]);
        }
    if (empty($bk['skipped'])) cron_say('backup: ' . (string)($bk['message'] ?? ''));
} catch (Throwable $e) {
    cron_say('backup failed: ' . $e->getMessage());
    app_log('backup', 'cron failed: ' . $e->getMessage());
}

/* بررسی خودکار نسخه جدید */
try {
    $up = Updater::autoCheck();
    if (empty($up['skipped'])) cron_say('update: ' . (string)($up['message'] ?? ''));
} catch (Throwable $e) {
    cron_say('update check failed: ' . $e->getMessage());
}

/* fixed79: اعلام خودکار بستهٔ جدید (CHANGELOG.md) در تاپیک «کانال انتشار آپدیت» — هر fixedNN فقط یک‌بار */
try {
    if (class_exists('Release') && method_exists('Release', 'announceBuild')) {
        $rb = Release::announceBuild();
        if (empty($rb['skipped'])) cron_say('release: ' . (string)($rb['message'] ?? ''));
    }
} catch (Throwable $e) {
    cron_say('release announce failed: ' . $e->getMessage());
}

/* fixed80: اسکن خودکار دکمه‌های ربات — فقط وقتی کد ربات تغییر کرده باشد (btn_autoscan) */
try {
    if (class_exists('Btn') && method_exists('Btn', 'autoScan')) {
        $bs = Btn::autoScan();
        if (empty($bs['skipped'])) cron_say('buttons: ' . (string)($bs['message'] ?? ''));
    }
} catch (Throwable $e) {
    cron_say('buttons scan failed: ' . $e->getMessage());
}

/* fixed79: پایش نودهای پاسارگارد/مرزبان — هشدار قطع نود در گروه گزارشات (nodes_warn_h ساعت؛ ۰ = خاموش) */
try {
    $nwH = (int)DB::setting('nodes_warn_h', 1);
    if ($nwH > 0 && time() - (int)DB::setting('nodes_warn_last', 0) >= $nwH * 3600) {
        DB::setSetting('nodes_warn_last', (string)time());
        $down = [];
        foreach (DB::all("SELECT * FROM {p}panels WHERE active = 1 AND LOWER(type) IN ('pasarguard', 'marzban')") as $npn) {
            try {
                $nx = new Xui($npn);
                $nl = method_exists($nx, 'nodes') ? $nx->nodes() : null;
                if (!is_array($nl)) continue;
                foreach ($nl as $nd) {
                    if (!empty($nd['ok']) || !empty($nd['disabled'])) continue;
                    $down[] = (string)$npn['name'] . ' → ' . (string)$nd['name'] . ' (' . ((string)$nd['status'] !== '' ? (string)$nd['status'] : 'unknown')
                            . ((string)$nd['message'] !== '' ? ': ' . mb_substr((string)$nd['message'], 0, 60) : '') . ')';
                }
            } catch (Throwable $e) { }
        }
        $prevSig = (string)DB::setting('nodes_down_sig', '');
        $sig     = $down ? md5(implode('|', $down)) : '';
        if ($down && $sig !== $prevSig) {
            Logs::send('panels', Logs::fmt('🔴 هشدار قطع نود', [
                'تعداد'      => fa_num((string)count($down)),
                'نودهای قطع' => $down,
                'زمان'       => to_jalali(now(), true),
            ]));
            cron_say('nodes down: ' . count($down));
        } elseif (!$down && $prevSig !== '') {
            Logs::send('panels', Logs::fmt('🟢 همهٔ نودها دوباره متصل شدند', ['زمان' => to_jalali(now(), true)]));
        }
        DB::setSetting('nodes_down_sig', $sig);
    }
} catch (Throwable $e) {
    cron_say('nodes check failed: ' . $e->getMessage());
}

/* گزارش آماری دوره‌ای — بازه از «تنظیمات ← گزارشات» قابل تغییر است (پیش‌فرض ۲۴ ساعت) */
$sumH    = (int)DB::setting('rep_summary_h', 24);
$sumLast = (int)DB::setting('rep_summary_last', 0);
if ($sumH > 0 && time() - $sumLast >= $sumH * 3600) {
    DB::setSetting('rep_summary_last', (string)time());

    if (Logs::enabled()) {
        $dFrom = date('Y-m-d H:i:s', $sumLast > 0 ? $sumLast : time() - $sumH * 3600);
        $dTo   = date('Y-m-d H:i:s');
        $prm   = [':a' => $dFrom, ':b' => $dTo];

        $nUsers  = (int)DB::val("SELECT COUNT(*) FROM {p}users WHERE created_at >= :a AND created_at < :b", $prm, 0);
        $nSvc    = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE is_test = 0 AND created_at >= :a AND created_at < :b", $prm, 0);
        $nTest   = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE is_test = 1 AND created_at >= :a AND created_at < :b", $prm, 0);
        $nIn     = (int)DB::val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE status = 'approved' AND amount > 0 AND created_at >= :a AND created_at < :b", $prm, 0);
        $nOut    = (int)DB::val("SELECT COALESCE(SUM(-amount),0) FROM {p}transactions WHERE status = 'approved' AND amount < 0 AND created_at >= :a AND created_at < :b", $prm, 0);
        $nActive = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE status = 'active'", [], 0);
        $nPend   = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE status = 'pending'", [], 0);
        $nTk     = (int)DB::val("SELECT COUNT(*) FROM {p}tickets WHERE status = 'open'", [], 0);

        Logs::send('nightly', Logs::fmt('🌙 گزارش آماری دوره‌ای', [
            'از زمان'          => to_jalali($dFrom, true),
            'کاربر جدید'       => fa_num((string)$nUsers),
            'سرویس فروخته‌شده'  => fa_num((string)$nSvc),
            'اکانت تست'         => fa_num((string)$nTest),
            'شارژ تاییدشده'     => money($nIn) . ' ' . currency(),
            'خرید از کیف پول'   => money($nOut) . ' ' . currency(),
            'سرویس فعال'         => fa_num((string)$nActive),
            'پرداخت در انتظار'  => fa_num((string)$nPend),
            'تیکت باز'           => fa_num((string)$nTk),
        ], 'نرخ فعلی دلار: ' . money((int)DB::setting('usd_rate', 0)) . ' ' . currency()));
    }
}

$took = round(microtime(true) - $started, 2);
cron_say(sprintf(
    'done in %ss | synced=%d expired=%d disabled=%d warn_expire=%d warn_traffic=%d nowpay=%d recovered=%d',
    $took, $report['synced'], $report['expired'], $report['disabled'],
    $report['warned_expire'], $report['warned_traffic'], $report['nowpay'], $report['recovered']
));

@file_put_contents(APP_ROOT . '/storage/last-cron-done.txt', date('Y-m-d H:i:s'));

if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
