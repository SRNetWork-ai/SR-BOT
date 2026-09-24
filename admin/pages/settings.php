<?php
/** تنظیمات کلی ربات و فروشگاه – دسته‌بندی‌شده در تب‌ها */
declare(strict_types=1);

if (!can('settings.view')) { echo denyBox('بخش تنظیمات برای شما فعال نیست.'); return; }

$act = (string)($_POST['act'] ?? '');
$SET = static function (string $k, $d = '') { return DB::setting($k, $d); };

/* دانلود فایل پشتیبان */
$dl = basename((string)($_GET['dl'] ?? ''));
if ($dl !== '') {
    if (!can('backup.view')) { flash('err', '⛔ دسترسی دانلود پشتیبان را ندارید.'); back('settings'); }
    $file = APP_ROOT . '/storage/backups/' . $dl;
    if (is_file($file) && preg_match('/^backup-[\w\-]+\.sql$/', $dl)) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $dl . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
    flash('err', 'فایل پشتیبان پیدا نشد.');
    back('settings');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {

    if ($act === 'adv') { /* fixed79: تنظیمات پیشرفته */
        need('settings.bot', 'settings');
        $clamp = static function (string $k, int $d, int $min, int $max): void { DB::setSetting($k, (string)max($min, min($max, pint($k, $d)))); };
        $chk   = static function (string $k): void { DB::setSetting($k, (string)pchk($k)); };
        /* اکانت تست */
        $clamp('test_global_max', 0, 0, 1000);
        $clamp('test_cooldown_days', 0, 0, 365);
        $chk('test_cooldown_per_panel');
        $clamp('test_min_age_hours', 0, 0, 24 * 365);
        $chk('test_phone_unique');
        $chk('test_panel_check');
        $clamp('test_ip_max', 0, 0, 100);   /* 0.0.2 #19 */
        $chk('test_device_unique');         /* 0.0.2 #19 */
        /* تمدید خودکار و هشدارها */
        $chk('arn_enabled');
        $clamp('arn_hours', 24, 1, 24 * 30);
        $clamp('arn_traffic', 95, 0, 100);
        $clamp('expire_notify_days2', 1, 0, 30);
        $clamp('traffic_notify_percent', 85, 1, 100);
        /* حذف خودکار تمام‌شده‌ها */
        $chk('svc_autodel_on');
        $clamp('svc_autodel_days', 3, 1, 60);
        $chk('svc_autodel_rs');
        $chk('svc_autodel_notify');
        $chk('svc_autodel_warn');
        /* نگهداری و امنیت */
        $clamp('logs_keep_days', 90, 7, 3650);
        $clamp('nodes_warn_h', 1, 0, 720);
        $chk('admin_2fa');
        if ((string)DB::setting('admin_2fa', '0') === '1' && (int)($ADMIN['tg_id'] ?? 0) <= 0) {
            flash('err', '⚠️ ورود دومرحله‌ای فعال شد، اما آیدی تلگرام حساب شما ثبت نیست؛ مدیران بدون آیدی بدون کد وارد می‌شوند. در «مدیران» آیدی را ثبت کنید.');
        }
        if (class_exists('Audit')) { try { Audit::log('settings.adv', []); } catch (Throwable $e) { } }
        flash('ok', '✅ تنظیمات پیشرفته ذخیره شد.');
        back('settings', ['tab' => 'adv']);
    }

    if ($act === 'rel_announce') { /* fixed79: اعلام دستی بستهٔ فعلی در تاپیک آپدیت */
        need('settings.bot', 'settings');
        $r = (class_exists('Release') && method_exists('Release', 'announceBuild')) ? Release::announceBuild(true) : ['ok' => false, 'message' => 'ماژول Release در دسترس نیست.'];
        flash(!empty($r['ok']) ? 'ok' : 'err', h((string)($r['message'] ?? '')));
        back('settings', ['tab' => 'rel']);
    }

    if ($act === 'cus_migrate') { /* fixed79: تبدیل تنظیمات قدیمی «حجم دلخواه» به محصول */
        need('settings.shop', 'settings');
        if (!class_exists('Migrate') || !Migrate::hasColumn('products', 'type')) {
            flash('err', 'اول از «به‌روزرسانی ← بررسی و تکمیل ساختار دیتابیس» را اجرا کنید (ستون products.type نیست).');
            back('settings', ['tab' => 'shop']);
        }
        $map = jdec((string)DB::setting('cus_panels', ''), []);
        if (!is_array($map)) $map = [];
        $dG  = (int)DB::setting('cus_price_gb', 0);
        $dD  = (int)DB::setting('cus_price_day', 0);
        $mnG = max(1, (int)DB::setting('cus_min_gb', 5));
        $mxG = max($mnG, (int)DB::setting('cus_max_gb', 100));
        $mnD = max(1, (int)DB::setting('cus_min_days', 1));
        $mxD = max($mnD, (int)DB::setting('cus_max_days', 90));
        $made = 0; $skip = 0;
        foreach (DB::all('SELECT id, name FROM {p}panels WHERE active = 1 ORDER BY sort ASC, id ASC') as $pnRow) {
            $pid = (int)$pnRow['id'];
            $row = is_array($map[$pid] ?? null) ? $map[$pid] : [];
            if ((int)($row['on'] ?? 0) !== 1) continue;
            if (DB::one("SELECT id FROM {p}products WHERE type = 'custom' AND panel_id = :p", [':p' => $pid])) { $skip++; continue; }
            $pg = (int)($row['pg'] ?? 0) > 0 ? (int)$row['pg'] : $dG;
            $pd = (int)($row['pd'] ?? 0) > 0 ? (int)$row['pd'] : $dD;
            DB::insert('products', [
                'panel_id' => $pid, 'inbound_id' => 0,
                'name' => 'حجم و زمان دلخواه — ' . (string)$pnRow['name'],
                'category' => 'دلخواه', 'description' => '',
                'volume_gb' => 0, 'days' => 0, 'ip_limit' => 0, 'price' => 0, 'old_price' => 0,
                'stock' => -1, 'sold' => 0, 'active' => 1, 'sort' => 99, 'deliver_mode' => '',
                'type' => 'custom', 'price_gb' => $pg, 'price_day' => $pd,
                'min_gb' => $mnG, 'max_gb' => $mxG, 'min_days' => $mnD, 'max_days' => $mxD,
                'created_at' => now(),
            ]);
            $made++;
        }
        if ($made > 0) DB::setSetting('cus_enabled', '0');
        if (class_exists('Audit')) { try { Audit::log('settings.cus_migrate', ['made' => $made, 'skip' => $skip]); } catch (Throwable $e) { } }
        flash($made > 0 ? 'ok' : 'err', $made > 0
            ? '✅ ' . fa_num((string)$made) . ' محصول «حجم و زمان دلخواه» ساخته شد و روش قدیمی خاموش شد.' . ($skip > 0 ? ' (' . fa_num((string)$skip) . ' سرور از قبل محصول داشت)' : '')
            : 'چیزی برای تبدیل نبود: در روش قدیمی هیچ سروری فعال نیست یا همه از قبل محصول دارند.');
        back('settings', ['tab' => 'shop']);
    }

    if ($act === 'txc') {
        need('settings.payment', 'settings');
        DB::setSetting('txc_enabled', pchk('txc_enabled'));
        DB::setSetting('txc_auto_approve', pchk('txc_auto_approve'));
        DB::setSetting('txc_tolerance', max(0, min(50, pint('txc_tolerance'))));
        DB::setSetting('txc_timeout', max(5, pint('txc_timeout')));
        foreach (['txc_bscscan_key', 'txc_etherscan_key', 'txc_tonapi_key'] as $k) {
            DB::setSetting($k, ptxt($k));
        }
        DB::setSetting('install_autodelete', pchk('install_autodelete'));
        DB::setSetting('admin_miniapp', pchk('admin_miniapp'));
        flash('ok', '✅ تنظیمات هش‌چکر ذخیره شد.');
        back('settings', ['tab' => 'txc']);
    }

    if ($act === 'txc_recheck') {
        need('settings.payment', 'settings');
        if (!class_exists('TxCheck')) {
            flash('err', 'ماژول هش‌چکر در دسترس نیست.');
            back('settings', ['tab' => 'txc']);
        }
        $tid = pint('tx_id');
        if (!$tid) {
            flash('err', 'شمارهٔ تراکنش را وارد کنید.');
            back('settings', ['tab' => 'txc']);
        }
        $rc = TxCheck::recheck($tid);
        flash(!empty($rc['ok']) ? 'ok' : 'err', h(TxCheck::summary($rc)));
        back('settings', ['tab' => 'txc']);
    }

    if ($act === 'shop') {
        need('settings.shop', 'settings');
        foreach (['shop_title', 'currency', 'support_username', 'force_channel'] as $k) DB::setSetting($k, ptxt($k));
        DB::setSetting('welcome_text', ptxt('welcome_text', 3000));
        DB::setSetting('rules_text', ptxt('rules_text', 3000));
        /* حجم و زمان دلخواه */
        DB::setSetting('cus_enabled', pchk('cus_enabled'));
        DB::setSetting('cus_product_id', (string)max(0, (int)pflt('cus_product_id')));
        DB::setSetting('cus_min_gb', (string)max(1, (int)pflt('cus_min_gb')));
        DB::setSetting('cus_max_gb', (string)max(1, (int)pflt('cus_max_gb')));
        DB::setSetting('cus_min_days', (string)max(1, (int)pflt('cus_min_days')));
        DB::setSetting('cus_max_days', (string)max(1, (int)pflt('cus_max_days')));
        DB::setSetting('cus_price_gb', (string)max(0, (int)pflt('cus_price_gb')));
        DB::setSetting('cus_price_day', (string)max(0, (int)pflt('cus_price_day')));
        /* قیمت اختصاصی هر پنل برای حجم و زمان دلخواه */
        $cusMap = [];
        $cusPid = $_POST['cus_pn_pid'] ?? [];
        if (is_array($cusPid)) {
            $cusOn = is_array($_POST['cus_pn_on'] ?? null) ? $_POST['cus_pn_on'] : [];
            $cusPg = is_array($_POST['cus_pn_pg'] ?? null) ? $_POST['cus_pn_pg'] : [];
            $cusPd = is_array($_POST['cus_pn_pd'] ?? null) ? $_POST['cus_pn_pd'] : [];
            foreach ($cusPid as $cusPnId => $cusV) {
                $cusPnId = (int)$cusPnId;
                if ($cusPnId <= 0) continue;
                $cusMap[$cusPnId] = [
                    'on'  => isset($cusOn[$cusPnId]) ? 1 : 0,
                    'pid' => max(0, (int)$cusV),
                    'pg'  => max(0, (int)($cusPg[$cusPnId] ?? 0)),
                    'pd'  => max(0, (int)($cusPd[$cusPnId] ?? 0)),
                ];
            }
        }
        DB::setSetting('cus_panels', jenc($cusMap));
        DB::setSetting('maintenance', pchk('maintenance'));
        DB::setSetting('bot_mute', pchk('bot_mute'));
        DB::setSetting('bot_mute_admins', pchk('bot_mute_admins'));
        flash('ok', '✅ تنظیمات فروشگاه ذخیره شد.');
        back('settings', ['tab' => 'shop']);
    }

    if ($act === 'pay') {
        need('settings.payment', 'settings');
        DB::setSetting('card_enabled', pchk('card_enabled'));
        foreach (['card_number', 'card_holder', 'card_bank'] as $k) DB::setSetting($k, ptxt($k));
        DB::setSetting('crypto_enabled', pchk('crypto_enabled'));
        foreach (['crypto_asset', 'crypto_address', 'crypto_network'] as $k) DB::setSetting($k, ptxt($k));
        DB::setSetting('usd_rate', pflt('usd_rate'));
        DB::setSetting('min_deposit', pflt('min_deposit'));
        DB::setSetting('max_deposit', pflt('max_deposit'));
        flash('ok', '✅ تنظیمات پرداخت ذخیره شد.');
        back('settings', ['tab' => 'pay']);
    }

    if ($act === 'bot') {
        need('settings.bot', 'settings');
        DB::setSetting('test_enabled', pchk('test_enabled'));
        DB::setSetting('referral_bonus', pflt('referral_bonus'));
        DB::setSetting('referral_enabled',    (string)pchk('referral_enabled'));
        DB::setSetting('referral_percent',    (string)max(0.0, min(50.0, (float)pflt('referral_percent'))));
        DB::setSetting('referral_l2_percent', (string)max(0.0, min(50.0, (float)pflt('referral_l2_percent'))));
        DB::setSetting('referral_first_only', (string)pchk('referral_first_only'));
        DB::setSetting('referral_min_amount', (string)max(0, pint('referral_min_amount')));
        DB::setSetting('referral_cap',        (string)max(0, pint('referral_cap')));
        DB::setSetting('expire_notify_days', pint('expire_notify_days', 3));
        DB::setSetting('traffic_notify_percent', pint('traffic_notify_percent', 85));
        DB::setSetting('extra_admins', preg_replace('/[^0-9,]/', '', en_num(ptxt('extra_admins'))));
        flash('ok', '✅ تنظیمات ربات ذخیره شد.');
        back('settings', ['tab' => 'bot']);
    }

    if ($act === 'nowpay') {
        need('settings.payment', 'settings');
        DB::setSetting('nowpay_enabled', pchk('nowpay_enabled'));
        DB::setSetting('nowpay_api_key', ptxt('nowpay_api_key'));
        DB::setSetting('nowpay_ipn_secret', ptxt('nowpay_ipn_secret'));
        DB::setSetting('nowpay_currency', strtolower((string)preg_replace('/[^a-zA-Z0-9]/', '', ptxt('nowpay_currency'))));
        DB::setSetting('nowpay_min_usd', pflt('nowpay_min_usd'));
        flash('ok', '✅ تنظیمات درگاه ارزی ذخیره شد.');
        back('settings', ['tab' => 'pay']);
    }

    if ($act === 'nowpay_test') {
        need('settings.payment', 'settings');
        $r = NowPay::health();
        flash(!empty($r['ok']) ? 'ok' : 'err', (string)$r['message']);
        back('settings', ['tab' => 'pay']);
    }

    if ($act === 'rates') {
        need('settings.payment', 'settings');
        DB::setSetting('rate_mode', ptxt('rate_mode') === 'auto' ? 'auto' : 'manual');
        DB::setSetting('rate_source', ptxt('rate_source'));
        DB::setSetting('rate_unit', ptxt('rate_unit') === 'rial' ? 'rial' : 'toman');
        DB::setSetting('rate_markup', pflt('rate_markup'));
        DB::setSetting('rate_round', pint('rate_round', 500));
        DB::setSetting('rate_ttl', pint('rate_ttl', 60));

        /* زنجیرهٔ خودکار منابع و نرخ لحظه‌ای */
        DB::setSetting('rate_failover', pchk('rate_failover'));
        DB::setSetting('rate_live', pchk('rate_live'));

        /* ارزهای فعال (USDT همیشه فعال است) */
        $as = [];
        foreach (array_keys(Rates::ASSETS) as $ak) if (!empty($_POST['asset_' . $ak])) $as[] = $ak;
        if (!in_array('USDT', $as, true)) array_unshift($as, 'USDT');
        DB::setSetting('rate_assets', implode(',', $as));

        /* API دلخواه کاربر */
        DB::setSetting('rate_custom_url', ptxt('rate_custom_url', 500));
        DB::setSetting('rate_custom_path', ptxt('rate_custom_path', 200));
        DB::setSetting('rate_custom_headers', ptxt('rate_custom_headers', 1000));
        DB::setSetting('rate_custom_unit', ptxt('rate_custom_unit') === 'rial' ? 'rial' : 'toman');
        $scale = pflt('rate_custom_scale');
        DB::setSetting('rate_custom_scale', $scale > 0 ? $scale : 1);

        /* کیف پول هر ارز */
        foreach (array_keys(Rates::ASSETS) as $ak) {
            DB::setSetting('crypto_addr_' . $ak, ptxt('addr_' . $ak, 200));
            DB::setSetting('crypto_net_' . $ak, ptxt('net_' . $ak, 40));
        }

        DB::loadSettings(true);
        flash('ok', '✅ تنظیمات نرخ ارز ذخیره شد.');
        back('settings', ['tab' => 'rate']);
    }

    if ($act === 'rate_now') {
        need('settings.payment', 'settings');
        DB::setSetting('rate_mode', 'auto');
        $r = Rates::refresh(true);
        flash(!empty($r['ok']) ? 'ok' : 'err', (string)$r['message']);
        back('settings', ['tab' => 'rate']);
    }

    /* آزمایش همهٔ منابع نرخ و همهٔ ارزها */
    if ($act === 'rate_test') {
        need('settings.payment', 'settings');
        $_SESSION['vs_rate_test'] = [
            'src' => Rates::testAll(),
            'ast' => Rates::testAssets(),
            'at'  => time(),
        ];
        flash('ok', '🧪 همهٔ منابع آزمایش شدند – نتیجه در پایین همین صفحه.');
        back('settings', ['tab' => 'rate']);
    }

    if (in_array($act, ['logs', 'logs_setup', 'logs_setup_rebuild', 'logs_test', 'logs_test_one', 'logs_repair', 'logs_flush'], true)) { /* fixed85 */
        need('settings.logs', 'settings');
        // اگر فرم گزارشات ارسال شده باشد، اول ذخیره می‌کنیم تا شناسه گروه از دست نرود
        if (isset($_POST['log_chat_id'])) {
            $cid = (string)preg_replace('/[^0-9\-]/', '', en_num(ptxt('log_chat_id')));
            if ($cid !== '' && $cid[0] !== '-' && strlen($cid) >= 10) $cid = '-100' . $cid;
            DB::setSetting('log_enabled', pchk('log_enabled'));
            DB::setSetting('log_chat_id', $cid);
            DB::setSetting('log_backup', pchk('log_backup'));
            /* بازهٔ ساعتی گزارش‌های دوره‌ای — صفر یعنی خاموش */
            foreach (['rep_panels_h' => 24, 'rep_summary_h' => 24, 'rep_sales_h' => 0, 'rep_users_h' => 0, 'rep_pays_h' => 0, 'rep_warnconn_h' => 24] as $rpk => $rpd) {
                if (isset($_POST[$rpk])) DB::setSetting($rpk, (string)max(0, min(720, pint($rpk, $rpd))));
            }
            $off = [];
            foreach (array_keys(Logs::TOPICS) as $lk) if (!isset($_POST['ev_' . $lk])) $off[] = $lk;
            Logs::setOff($off);
            /* fixed85: تاپیک‌های بی‌صدا + ضد تکرار + ساخت خودکار تاپیک */
            $sil = [];
            foreach (array_keys(Logs::TOPICS) as $lk) if (isset($_POST['sl_' . $lk])) $sil[] = $lk;
            Logs::setSilent($sil);
            DB::setSetting('log_dedup_min', (string)max(0, min(1440, pint('log_dedup_min', 3))));
            DB::setSetting('log_auto_topic', pchk('log_auto_topic'));
            DB::loadSettings(true);
        }
        if ($act === 'logs') {
            $info = Logs::chatInfo();
            flash(!empty($info['ok']) ? 'ok' : 'err', 'تنظیمات گزارشات ذخیره شد. ' . (string)($info['message'] ?? ''));
        } elseif ($act === 'logs_repair') { /* fixed85 */
            $r = Logs::repairTopics();
            flash(!empty($r['ok']) ? 'ok' : 'err', (string)$r['message']);
        } elseif ($act === 'logs_flush') {
            $r = Logs::flushQueue(50);
            flash('ok', '🚚 صف گزارش‌ها: ' . fa_num((string)(int)($r['sent'] ?? 0)) . ' ارسال شد، '
                . fa_num((string)(int)($r['left'] ?? 0)) . ' در صف ماند'
                . ((int)($r['dropped'] ?? 0) > 0 ? '، ' . fa_num((string)(int)$r['dropped']) . ' منقضی شد' : '') . '.');
        } elseif ($act === 'logs_test_one') {
            $tk = (string)preg_replace('/[^a-z_]/', '', (string)($_POST['tkey'] ?? ''));
            $r  = Logs::testOne($tk);
            flash(!empty($r['ok']) ? 'ok' : 'err', (string)$r['message']);
        } elseif ($act === 'logs_test') {
            $r = Logs::testAll();
            flash(!empty($r['ok']) ? 'ok' : 'err', (string)$r['message']);
        } else {
            $r = Logs::setupTopics($act === 'logs_setup_rebuild' || (string)($_POST['rebuild'] ?? '') === '1');
            flash(!empty($r['ok']) ? 'ok' : 'err', (string)$r['message']);
        }
        back('settings', ['tab' => 'logs']);
    }

    if ($act === 'hook_set') {
        need('settings.bot', 'settings');
        $r = Tg::setWebhook(app_url('index.php'), (string)cfg('bot.secret'));
        flash(!empty($r['ok']) ? 'ok' : 'err', !empty($r['ok'])
            ? '✅ وب‌هوک روی <span class="mono">' . h(app_url('index.php')) . '</span> تنظیم شد.'
            : 'تنظیم وب‌هوک ناموفق بود: ' . h((string)($r['description'] ?? 'خطای نامشخص')));
        back('settings');
    }

    if ($act === 'hook_del') {
        need('settings.bot', 'settings');
        Tg::deleteWebhook();
        flash('warn', 'وب‌هوک حذف شد. تا تنظیم مجدد، ربات پاسخ نمی‌دهد.');
        back('settings');
    }

    if ($act === 'bot_token') {
        need('settings.bot', 'settings');
        if (!class_exists('Cfg')) {
            flash('err', '⛔ فایل app/Service/Cfg.php آپلود نشده است.');
            back('settings', ['tab' => 'bot']);
        }
        $r = Cfg::saveToken((string)ptxt('bot_token', 200), (string)pchk('token_force') === '1');
        if (empty($r['ok'])) {
            flash('err', h((string)$r['message']));
            back('settings', ['tab' => 'bot']);
        }
        $msg = h((string)$r['message']);
        if ((string)pchk('token_hook') === '1' && !empty($r['verified'])) {
            $w = Tg::setWebhook(app_url('index.php'), (string)Cfg::get('bot.secret', ''));
            $msg .= !empty($w['ok'])
                ? ' وب‌هوک روی <span class="mono">' . h(app_url('index.php')) . '</span> تنظیم شد.'
                : ' اما تنظیم وب‌هوک ناموفق بود: ' . h((string)($w['description'] ?? '-'));
        }
        flash(!empty($r['verified']) ? 'ok' : 'warn', $msg);
        back('settings', ['tab' => 'bot']);
    }

    if ($act === 'bot_core') {
        need('settings.bot', 'settings');
        if (!class_exists('Cfg')) {
            flash('err', '⛔ فایل app/Service/Cfg.php آپلود نشده است.');
            back('settings', ['tab' => 'bot']);
        }
        $set = [];
        $url = rtrim(trim((string)ptxt('app_url', 300)), '/');
        if ($url !== '') {
            if (!preg_match('#^https?://#', $url)) {
                flash('err', '⛔ آدرس سایت باید با http:// یا https:// شروع شود.');
                back('settings', ['tab' => 'bot']);
            }
            $set['app.url'] = $url;
        }
        $adm = (string)preg_replace('/[^0-9,]/', '', en_num((string)ptxt('bot_admins', 300)));
        if ($adm !== '') {
            $ids = array_values(array_unique(array_filter(array_map('trim', explode(',', $adm)))));
            if ($ids) $set['bot.admins'] = $ids;
        }
        if (!$set) {
            flash('err', 'چیزی برای ذخیره وارد نشد.');
            back('settings', ['tab' => 'bot']);
        }
        $w = Cfg::set($set);
        flash(!empty($w['ok']) ? 'ok' : 'err', h((string)$w['message']));
        back('settings', ['tab' => 'bot']);
    }

    /* ---------- پیام همگانی پیشرفته ---------- */
    if ($act === 'broadcast' || $act === 'broadcast_test') {
        need('settings.broadcast', 'settings');

        $kind = (string)($_POST['kind'] ?? 'text');
        if (!isset(Broadcast::KINDS[$kind])) $kind = 'text';
        $text    = ptxt('text', 3500);
        $buttons = ptxt('buttons', 1500);
        $segment = (string)($_POST['segment'] ?? 'all');
        $fileId  = ptxt('file_id', 300);
        $adminTg = (int)($ADMIN['tg_id'] ?? 0);

        /* اگر فایل آپلود شده، اول در تلگرام بارگذاری می‌شود تا ��ناسه بگیریم */
        if ($kind !== 'text' && $fileId === ''
            && !empty($_FILES['media']['tmp_name']) && is_uploaded_file((string)$_FILES['media']['tmp_name'])) {
            $tmpDir = APP_ROOT . '/storage/tmp';
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
            /* 0.0.2 #10: نام امن و پسوند مجاز برای فایل رسانه */
            $safe = class_exists('Upload')
                ? Upload::safeName((string)$_FILES['media']['name'], Upload::MEDIA)
                : (preg_replace('/[^\w\.\-]/u', '_', (string)$_FILES['media']['name']) ?: 'file');
            $dest = $tmpDir . '/bc_' . rnd(8) . '_' . $safe;
            if (class_exists('Upload')) Upload::protectDir(dirname($dest));   /* 0.0.2 #10-dir */
            if (@move_uploaded_file((string)$_FILES['media']['tmp_name'], $dest)) {
                $fileId = Broadcast::uploadToTelegram($dest, $kind, $adminTg);
                @unlink($dest);
            }
            if ($fileId === '') {
                flash('err', 'آپلود فایل در تلگرام ناموفق بود. مطمئن شوید شناسهٔ تلگرام مدیر ثبت شده و پوشهٔ storage/tmp قابل نوشتن است.');
                back('settings', ['tab' => 'bc']);
            }
        }

        if ($act === 'broadcast_test' && $adminTg <= 0) {
            flash('err', 'برای ارسال آزمایشی باید شناسهٔ تلگرام شما در بخش مدیران ثبت شده باشد.');
            back('settings', ['tab' => 'bc']);
        }

        $res = Broadcast::send([
            'kind'     => $kind,
            'text'     => $text,
            'file_id'  => $fileId,
            'buttons'  => $buttons,
            'segment'  => $segment,
            'pin'      => pchk('pin'),
            'silent'   => pchk('silent'),
            'admin_id' => (int)$ADMIN['id'],
            'test_to'  => $act === 'broadcast_test' ? $adminTg : 0,
        ]);

        flash(!empty($res['ok']) ? 'ok' : 'err', ($res['ok'] ? '📣 ' : '') . (string)$res['message']);
        back('settings', ['tab' => 'bc']);
    }

    /* ---------- امنیت و تایید حساب ---------- */
    if ($act === 'security') {
        need('settings.bot', 'settings');
        $mode = ptxt('sec_verify_mode');
        DB::setSetting('sec_verify_mode', isset(Security::MODES[$mode]) ? $mode : 'off');
        $scope = ptxt('sec_verify_required_for');
        DB::setSetting('sec_verify_required_for', isset(Security::SCOPES[$scope]) ? $scope : 'buy');
        foreach (array_keys(Security::KINDS) as $sk) DB::setSetting('sec_verify_' . $sk, pchk('sec_verify_' . $sk));

        DB::setSetting('sec_code_length', max(4, min(8, pint('sec_code_length', 5))));
        DB::setSetting('sec_code_ttl', max(1, pint('sec_code_ttl', 10)));
        DB::setSetting('sec_max_tries', max(1, pint('sec_max_tries', 5)));
        DB::setSetting('sec_resend_wait', max(10, pint('sec_resend_wait', 90)));

        /* ایمیل */
        DB::setSetting('sec_mail_driver', ptxt('sec_mail_driver') === 'smtp' ? 'smtp' : 'mail');
        DB::setSetting('sec_mail_from', ptxt('sec_mail_from', 190));
        DB::setSetting('sec_mail_from_name', ptxt('sec_mail_from_name', 120));
        DB::setSetting('sec_smtp_host', ptxt('sec_smtp_host', 190));
        DB::setSetting('sec_smtp_port', pint('sec_smtp_port', 587));
        DB::setSetting('sec_smtp_user', ptxt('sec_smtp_user', 190));
        if (ptxt('sec_smtp_pass') !== '') DB::setSetting('sec_smtp_pass', ptxt('sec_smtp_pass', 190));
        $sm = ptxt('sec_smtp_secure');
        DB::setSetting('sec_smtp_secure', in_array($sm, ['tls', 'ssl', 'none'], true) ? $sm : 'tls');

        /* پیامک */
        DB::setSetting('sec_sms_driver', ptxt('sec_sms_driver') === 'http' ? 'http' : 'off');
        DB::setSetting('sec_sms_url', ptxt('sec_sms_url', 500));
        DB::setSetting('sec_sms_method', ptxt('sec_sms_method') === 'POST' ? 'POST' : 'GET');
        DB::setSetting('sec_sms_body', ptxt('sec_sms_body', 1000));
        DB::setSetting('sec_sms_headers', ptxt('sec_sms_headers', 1000));

        /* محافظت‌ها */
        DB::setSetting('sec_block_multi_account', pchk('sec_block_multi_account'));
        DB::setSetting('sec_min_account_age', pint('sec_min_account_age', 0));
        DB::setSetting('sec_force_join_before_test', pchk('sec_force_join_before_test'));
        DB::setSetting('sec_login_alert', pchk('sec_login_alert'));
        DB::setSetting('sec_login_max_fail', pint('sec_login_max_fail', 5));
        DB::setSetting('sec_login_lock_min', max(1, pint('sec_login_lock_min', 15)));

        DB::loadSettings(true);
        flash('ok', '🛡 تنظیمات امنیت ذخیره شد.');
        back('settings', ['tab' => 'sec2']);
    }

    if ($act === 'sec_test_mail') {
        need('settings.bot', 'settings');
        $to = ptxt('test_mail', 190);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('err', 'ایمیل مقصد معتبر نیست.');
        } else {
            $ok = Security::sendEmail($to, 'پیام آزمایشی',
                '<div dir="rtl" style="font-family:Tahoma">این یک پیام آزمایشی از پنل مدیریت است. ✅</div>');
            flash($ok ? 'ok' : 'err', $ok ? '✅ ایمیل آزمایشی ارسال شد.' : '❌ ارسال ایمیل ناموفق بود – تنظیمات SMTP را بررسی کنید.');
        }
        back('settings', ['tab' => 'sec2']);
    }

    if ($act === 'sec_test_sms') {
        need('settings.bot', 'settings');
        $to = preg_replace('/\D/', '', en_num(ptxt('test_sms', 30))) ?? '';
        if (strlen($to) < 10) {
            flash('err', 'شمارهٔ مقصد معتبر نیست.');
        } else {
            $ok = Security::sendSms($to, 'پیام آزمایشی پنل – کد: 12345', '12345');
            flash($ok ? 'ok' : 'err', $ok ? '✅ درخواست پیامک ارسال شد.' : '❌ ارسال پیامک ناموفق بود – آدرس API را بررسی کنید.');
        }
        back('settings', ['tab' => 'sec2']);
    }

    /* ---------- مینی‌اپ ---------- */
    /* ---------- کانال انتشار آپدیت ---------- */
    if (strpos($act, 'rel_') === 0 && !class_exists('Release')) {
        flash('err', 'سرویس انتشار در دسترس نیست — فایل app/Service/Release.php پیدا نشد.');
        back('settings', ['tab' => 'rel']);
    }

    if ($act === 'rel_save') {
        need('settings.bot', 'settings');

        DB::setSetting('rel_enabled', pchk('rel_enabled'));

        /* شناسهٔ کانال: @username یا عددی -100... */
        $rc = trim(en_num(ptxt('rel_chat_id', 120)));
        $rc = preg_replace('~[\s\x{200c}]+~u', '', $rc) ?? $rc;
        if ($rc !== '' && strpos($rc, 'https://t.me/') === 0) $rc = '@' . substr($rc, 13);
        if ($rc !== '' && strpos($rc, 't.me/') === 0)         $rc = '@' . substr($rc, 5);
        if ($rc !== '' && $rc[0] !== '@' && ctype_digit(ltrim($rc, '-'))) {
            $digits = ltrim($rc, '-');
            if (strlen($digits) >= 9 && strpos($rc, '-100') !== 0) $rc = '-100' . $digits;
        }
        DB::setSetting('rel_chat_id', $rc);

        DB::setSetting('rel_topic_name', ptxt('rel_topic_name', 120));
        $rcol = ptxt('rel_topic_color', 12);
        DB::setSetting('rel_topic_color', isset(Release::COLORS[$rcol]) ? $rcol : '9367192');

        $rst = ptxt('rel_style', 12);
        DB::setSetting('rel_style', isset(Release::STYLES[$rst]) ? $rst : 'hero');

        DB::setSetting('rel_auto',   pchk('rel_auto'));
        DB::setSetting('rel_pin',    pchk('rel_pin'));
        DB::setSetting('rel_silent', pchk('rel_silent'));
        DB::setSetting('rel_title',  ptxt('rel_title', 80));
        DB::setSetting('rel_footer', ptxt('rel_footer', 300));
        DB::setSetting('rel_btn_text', ptxt('rel_btn_text', 60));

        $bu = trim(ptxt('rel_btn_url', 300));
        DB::setSetting('rel_btn_url', preg_match('~^(https?://|tg://)~i', $bu) ? $bu : '');

        DB::setSetting('rel_topic_id', max(0, pint('rel_topic_id', 0)));

        DB::loadSettings(true);
        flash('ok', '📣 تنظیمات کانال انتشار ذخیره شد.');
        back('settings', ['tab' => 'rel']);
    }

    /* بررسی دسترسی ربات در کانال */
    if ($act === 'rel_probe') {
        need('settings.bot', 'settings');
        $i = Release::chatInfo();
        flash(!empty($i['ok']) ? 'ok' : 'err', (string)($i['message'] ?? '—'));
        back('settings', ['tab' => 'rel']);
    }

    /* ساخت تاپیک مخصوص */
    if ($act === 'rel_topic') {
        need('settings.bot', 'settings');
        $r = Release::setupTopic((string)($_POST['force'] ?? '') === '1');
        flash(!empty($r['ok']) ? 'ok' : 'err', (string)($r['message'] ?? '—'));
        back('settings', ['tab' => 'rel']);
    }

    /* پیام آزمایشی */
    if ($act === 'rel_test') {
        need('settings.bot', 'settings');
        $r = Release::test();
        flash(!empty($r['ok']) ? 'ok' : 'err', (string)($r['message'] ?? '—'));
        back('settings', ['tab' => 'rel']);
    }

    /* ذخیرهٔ پیش‌نویس تغییرات (بدون انتشار) */
    if ($act === 'rel_draft') {
        need('settings.bot', 'settings');
        DB::setSetting('rel_draft', ptxt('rel_body', 4000));
        DB::loadSettings(true);
        flash('ok', '📝 پیش‌نویس تغییرات ذخیره شد. با انتشار بعدی منتشر می‌شود.');
        back('settings', ['tab' => 'rel']);
    }

    /* انتشار دستی */
    if ($act === 'rel_publish') {
        need('settings.bot', 'settings');

        $body = ptxt('rel_body', 4000);
        if (trim($body) !== '') DB::setSetting('rel_draft', $body);
        DB::loadSettings(true);

        $relTo = ptxt('rel_to', 30);
        if ($relTo === '') $relTo = APP_VERSION;

        $r = Release::publish([
            'from'    => ptxt('rel_from', 30),
            'to'      => $relTo,
            'files'   => pint('rel_files', 0),
            'changes' => trim($body) !== '' ? $body : null,
            'style'   => ptxt('rel_style_now', 12),
        ]);
        flash(!empty($r['ok']) ? 'ok' : 'err', (string)($r['message'] ?? '—'));
        back('settings', ['tab' => 'rel']);
    }

    /* پاک‌سازی تاریخچه */
    if ($act === 'rel_hist_clear') {
        need('settings.bot', 'settings');
        Release::clearHistory();
        DB::loadSettings(true);
        flash('ok', '🧹 تاریخچهٔ انتشار پاک شد.');
        back('settings', ['tab' => 'rel']);
    }

    if ($act === 'miniapp') {
        need('settings.bot', 'settings');
        DB::setSetting('miniapp_enabled', pchk('miniapp_enabled'));
        DB::setSetting('miniapp_show_menu', pchk('miniapp_show_menu'));
        DB::setSetting('miniapp_in_sections', pchk('miniapp_in_sections'));
        DB::setSetting('sub_deliver', (($_POST['sub_deliver'] ?? 'local') === 'panel') ? 'panel' : 'local');
        DB::setSetting('sub_page_mode', (($_POST['sub_page_mode'] ?? 'own') === 'main') ? 'main' : 'own');
        DB::setSetting('sub_show_main', pchk('sub_show_main'));
        DB::setSetting('num_style', (($_POST['num_style'] ?? 'en') === 'fa') ? 'fa' : 'en');
        DB::setSetting('miniapp_title', ptxt('miniapp_title', 120));
        DB::setSetting('miniapp_button', ptxt('miniapp_button', 60));
        $acc = ptxt('miniapp_accent', 20);
        DB::setSetting('miniapp_accent', preg_match('/^#[0-9a-fA-F]{6}$/', $acc) ? $acc : '#3b82f6');
        DB::loadSettings(true);
        flash('ok', '📱 تنظیمات مینی‌اپ ذخیره شد.');
        back('settings', ['tab' => 'app']);
    }

    if ($act === 'miniapp_menu') {
        need('settings.bot', 'settings');
        $r = Bot::setMenuButton();
        flash(!empty($r['ok']) ? 'ok' : 'err', !empty($r['ok'])
            ? '✅ دکمهٔ منوی ربات روی مینی‌اپ تنظیم شد.'
            : 'تنظیم دکمهٔ منو ناموفق بود: ' . h((string)($r['description'] ?? '')));
        back('settings', ['tab' => 'app']);
    }

    if ($act === 'passwd') {
        $cur = (string)($_POST['cur'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        $row = DB::one('SELECT * FROM {p}admins WHERE id = :id', [':id' => (int)$ADMIN['id']]);
        if (!$row || !password_verify($cur, (string)$row['password_hash'])) {
            flash('err', 'رمز فعلی اشتباه است.');
        } elseif (mb_strlen($new) < 8) {
            flash('err', 'رمز جدید حداقل ۸ کاراکتر باشد.');
        } else {
            DB::update('admins', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = :id', [':id' => (int)$ADMIN['id']]);
            flash('ok', '🔑 رمز عبور به‌روز شد.');
        }
        back('settings', ['tab' => 'sec']);
    }

    if ($act === 'backup') {
        need('backup.run', 'settings');
        $dir = APP_ROOT . '/storage/backups';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $name = 'backup-' . date('Ymd-His') . '.sql';
        $fh = @fopen($dir . '/' . $name, 'w');
        if (!$fh) { flash('err', 'امکان نوشتن در پوشه storage نیست. دسترسی ۷۵۵ بدهید.'); back('settings', ['tab' => 'sec']); }
        $pfx = DB::prefix();
        $tables = DB::pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        fwrite($fh, "-- VPN Shop backup " . now() . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        foreach ($tables as $tb) {
            if ($pfx !== '' && strpos((string)$tb, $pfx) !== 0) continue;
            $create = DB::pdo()->query('SHOW CREATE TABLE `' . $tb . '`')->fetch(PDO::FETCH_ASSOC);
            fwrite($fh, "DROP TABLE IF EXISTS `$tb`;\n" . ($create['Create Table'] ?? '') . ";\n");
            $st = DB::pdo()->query('SELECT * FROM `' . $tb . '`');
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $cols = array_map(fn($c) => '`' . $c . '`', array_keys($r));
                $vals = array_map(fn($x) => $x === null ? 'NULL' : DB::pdo()->quote((string)$x), array_values($r));
                fwrite($fh, 'INSERT INTO `' . $tb . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n");
            }
            fwrite($fh, "\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
        flash('ok', '💾 فایل پشتیبان ساخته شد: <a href="index.php?p=settings&dl=' . h($name) . '">دانلود ' . h($name) . '</a>');
        back('settings', ['tab' => 'sec']);
    }
}

$me      = Tg::getMe();
$botUser = (string)($me['result']['username'] ?? '');
$meErr   = !empty($me['ok']) ? '' : (string)($me['description'] ?? '');
$tokNow  = (string)cfg('bot.token', '');
$tokMask = class_exists('Cfg') ? Cfg::mask($tokNow) : '';
$cfgW    = class_exists('Cfg') ? Cfg::writable() : false;
$backups = is_dir(APP_ROOT . '/storage/backups')
    ? array_slice(array_reverse(array_values(array_filter(scandir(APP_ROOT . '/storage/backups') ?: [], fn($f) => substr($f, -4) === '.sql'))), 0, 5)
    : [];
$ri = Rates::info();
$lt = Logs::threads();
$loff = Logs::off();
$lsil = Logs::silent();   /* fixed85 */
$lstat = Logs::stats();   /* fixed85 */

/* داده‌های پیام همگانی */
$bcCount  = can('settings.broadcast') ? Broadcast::allCounts() : [];
$bcRecent = can('settings.broadcast') ? Broadcast::recent(8) : [];

/* نتیجهٔ آخرین آزمایش منابع نرخ */
$rt = $_SESSION['vs_rate_test'] ?? null;
unset($_SESSION['vs_rate_test']);

/* ارزهای فعال */
$rateOn = Rates::enabledAssets();
?>

<?php
/* ---------- خلاصهٔ وضعیت برای کارت بالای صفحه ---------- */
$s_maint  = (int)$SET('maintenance', 0) === 1;
$s_mute   = (int)$SET('bot_mute', 0) === 1;
$s_card   = (int)$SET('card_enabled', 1) === 1;
$s_crypto = (int)$SET('crypto_enabled', 1) === 1;
$s_now    = (int)$SET('nowpay_enabled', 0) === 1;
$s_pays   = ($s_card ? 1 : 0) + ($s_crypto ? 1 : 0) + ($s_now ? 1 : 0);
$s_verify = (string)$SET('sec_verify_mode', 'off');
$s_app    = (int)$SET('miniapp_enabled', 0) === 1;
$s_txc    = (int)$SET('txc_enabled', 0) === 1;
$s_test   = (int)$SET('test_enabled', 1) === 1;
$s_ref    = (string)$SET('referral_enabled', '1') === '1';
$s_assets = count((array)$rateOn);
$s_logs   = !$loff && count((array)$lt) > 0;
$s_bk     = count((array)$backups);

/* آخرین همگام‌سازی سرویس‌ها = نشانهٔ کارکرد کرانجا�� */
$s_cron = '';
try { $s_cron = (string)DB::val('SELECT MAX(last_sync) FROM {p}services', [], ''); }
catch (Throwable $e) { $s_cron = ''; }
$s_cronOk = $s_cron !== '' && (time() - (int)strtotime($s_cron)) < 7200;

/* دسترسی به صفحات مرتبط برای میان‌برها */
$s_page = static function (string $k): bool {
    try { return Perm::canPage($GLOBALS['ADMIN'] ?? null, $k); }
    catch (Throwable $e) { return false; }
};

/* تب‌های قابل نمایش */
$s_tabs = [];
$s_tabs[] = ['bot',  '🤖', 'ربات و وب‌هوک', 'توکن، وب‌هوک، رفتار، کرانجاب', true];
$s_tabs[] = ['shop', '🏪', 'فروشگاه', 'نام، متن خوشامد، قوانین', can('settings.shop')];
$s_tabs[] = ['pay',  '💳', 'پرداخت', 'کارت به کارت، ارزی، سقف شارژ', can('settings.payment')];
$s_tabs[] = ['rate', '📈', 'نرخ ارز', 'نرخ لحظه‌ای دلار و تتر', can('settings.payment')];
$s_tabs[] = ['logs', '🗂', 'گزارشات', 'گروه تاپیک‌دار رویدادها', can('settings.logs')];
$s_tabs[] = ['rel',  '📢', 'کانال انتشار آپدیت', 'انتشار خودکار لیست تغییرات', can('settings.bot')];
$s_tabs[] = ['bc',   '📣', 'پیام همگانی', 'متن، عکس، ویدیو و دکمه', can('settings.broadcast')];
$s_tabs[] = ['sec2', '🛡', 'امنیت و تایید حساب', 'ایمیل، پیامک، محافظت‌ها', can('settings.bot')];
$s_tabs[] = ['app',  '📱', 'مینی‌اپ', 'ظاهر، دکمهٔ منو، تحویل ساب', can('settings.bot')];
$s_tabs[] = ['txc',  '🔎', 'هش‌چکر تراکنش', 'بررسی خودکار پرداخت رمزارز', can('settings.payment')];
$s_tabs[] = ['adv',  '🧩', 'تنظیمات پیشرفته', 'تست، تمدید خودکار، حذف خودکار، نودها، ورود دومرحله‌ای', can('settings.bot')]; /* fixed79 */
$s_tabs[] = ['sec',  '🔑', 'رمز و پشتیبان', 'تغییر رمز و فایل پشتیبان', true];
?>

<div class="set-wrap" id="setWrap">

<section class="set-hero fade-up">
  <span class="sh-glow" aria-hidden="true"></span>

  <div class="sh-top">
    <div class="sh-ic">⚙️</div>
    <div class="sh-ttl">
      <h2>تنظیمات <span class="ver"><?= h(APP_VERSION) ?></span></h2>
      <div class="sub">پیکربندی ربات، فروشگاه، پرداخت، امنیت و مینی‌اپ — همه در یک صفحه</div>
    </div>
    <div class="sh-act">
      <?php if ($botUser !== ''): ?>
        <a class="btn btn-sm btn-ghost" target="_blank" rel="noopener" href="https://t.me/<?= h($botUser) ?>">↗ باز کردن ربات</a>
      <?php endif; ?>
      <span class="badge <?= $botUser !== '' ? 'b-green' : 'b-red' ?>"><?= $botUser !== '' ? '🤖 متصل' : '🤖 قطع' ?></span>
    </div>
  </div>

  <div class="sh-chips">
    <span class="chip <?= $s_maint ? 'c-red' : 'c-green' ?>"><?= $s_maint ? '🚧 حالت تعمیر روشن' : '🟢 فروشگاه فعال' ?></span>
    <?php if ($s_mute): ?><span class="chip c-orange">🔇 ربات بی‌صدا</span><?php endif; ?>
    <span class="chip <?= $s_pays > 0 ? 'c-green' : 'c-red' ?>">💳 <?= fa_num((string)$s_pays) ?> روش پرداخت</span>
    <span class="chip <?= $s_verify !== 'off' ? 'c-green' : '' ?>">🛡 تایید حساب: <?= $s_verify !== 'off' ? 'روشن' : 'خاموش' ?></span>
    <span class="chip <?= $s_app ? 'c-green' : '' ?>">📱 مینی‌اپ: <?= $s_app ? 'فعال' : 'غیرفعال' ?></span>
    <span class="chip <?= $s_logs ? 'c-green' : '' ?>">🗂 گزارشات: <?= $s_logs ? 'روشن' : 'خاموش' ?></span>
    <span class="chip <?= $s_txc ? 'c-green' : '' ?>">🔎 هش‌چکر: <?= $s_txc ? 'روشن' : 'خاموش' ?></span>
    <span class="chip <?= $s_test ? 'c-green' : '' ?>">🧪 اکانت تست: <?= $s_test ? 'فعال' : 'خاموش' ?></span>
    <span class="chip <?= $s_ref ? 'c-green' : '' ?>">🤝 رفرال: <?= $s_ref ? 'فعال' : 'خاموش' ?></span>
    <span class="chip <?= $s_cronOk ? 'c-green' : 'c-orange' ?>">⏰ کرانجاب: <?= $s_cronOk ? 'سالم' : 'نامشخص' ?></span>
  </div>

  <div class="sh-stats">
    <div class="hs"><span class="i">🤖</span><span class="v mono ltr"><?= $botUser !== '' ? '@' . h($botUser) : '—' ?></span><span class="l">نام کاربری ربات</span></div>
    <div class="hs"><span class="i">🔑</span><span class="v mono ltr sm"><?= $tokMask !== '' ? h($tokMask) : '—' ?></span><span class="l">توکن فعلی</span></div>
    <div class="hs"><span class="i">💱</span><span class="v"><?= fa_num((string)$s_assets) ?></span><span class="l">ارز فعال در نرخ‌گیری</span></div>
    <div class="hs"><span class="i">💾</span><span class="v"><?= fa_num((string)$s_bk) ?></span><span class="l">پشتیبان اخیر</span></div>
    <div class="hs"><span class="i">⏱</span><span class="v sm"><?= $s_cron !== '' ? h(to_jalali($s_cron, true)) : '—' ?></span><span class="l">آخرین همگام‌سازی</span></div>
    <div class="hs"><span class="i">🧩</span><span class="v mono ltr sm"><?= h(PHP_VERSION) ?></span><span class="l">نسخهٔ PHP</span></div>
  </div>
</section>

<div class="set-tools fade-up">
  <div class="set-search">
    <span class="ic">🔍</span>
    <input id="setSearch" type="search" autocomplete="off"
      placeholder="جستجو در همهٔ تنظیمات… مثلاً کارت، توکن، رفرال، SMTP">
    <button type="button" class="clr" id="setClr" hidden aria-label="پاک کردن">✕</button>
    <kbd class="kbd">/</kbd>
  </div>
  <div class="set-cnt" id="setCnt"></div>
</div>

<nav class="set-nav" id="setNav">
  <?php foreach ($s_tabs as $T): if (!$T[4]) continue; ?>
    <button type="button" class="sn" data-tab-group="set" data-tab="<?= h($T[0]) ?>">
      <span class="i"><?= $T[1] ?></span>
      <span class="t"><?= h($T[2]) ?></span>
      <span class="d"><?= h($T[3]) ?></span>
    </button>
  <?php endforeach; ?>
</nav>

<div class="set-nores" id="setNoRes" hidden>
  🔎 نتیجه‌ای پیدا نشد — عبارت دیگری را امتحان کنید.
</div>

<!-- ==================== ربات و وب‌هوک ==================== -->
<style>
/* ================= Settings Studio v1 — زیباسازی همهٔ بخش‌ها ================= */

/* رنگ اختصاصی هر بخش */
.set-panel{--sc:var(--accent);--scs:var(--accent-soft)}
.set-panel[data-tab-panel="bot"]  {--sc:#5B8CFF;--scs:rgba(91,140,255,.14)}
.set-panel[data-tab-panel="shop"] {--sc:#2FD48F;--scs:rgba(47,212,143,.14)}
.set-panel[data-tab-panel="pay"]  {--sc:#FFA92E;--scs:rgba(255,169,46,.14)}
.set-panel[data-tab-panel="rate"] {--sc:#26D3E8;--scs:rgba(38,211,232,.14)}
.set-panel[data-tab-panel="logs"] {--sc:#8B5CF6;--scs:rgba(139,92,246,.14)}
.set-panel[data-tab-panel="rel"]  {--sc:#38BDF8;--scs:rgba(56,189,248,.14)}
.set-panel[data-tab-panel="bc"]   {--sc:#FF6B6B;--scs:rgba(255,107,107,.14)}
.set-panel[data-tab-panel="sec2"] {--sc:#34D399;--scs:rgba(52,211,153,.14)}
.set-panel[data-tab-panel="app"]  {--sc:#F472B6;--scs:rgba(244,114,182,.14)}
.set-panel[data-tab-panel="txc"]  {--sc:#FBBF24;--scs:rgba(251,191,36,.14)}
.set-panel[data-tab-panel="adv"]  {--sc:#A78BFA;--scs:rgba(167,139,250,.14)}
.set-panel[data-tab-panel="sec"]  {--sc:#F87171;--scs:rgba(248,113,113,.14)}

/* ورود نرم پنل */
.set-panel:not([hidden]){animation:setIn .34s cubic-bezier(.22,.61,.36,1) both}
@keyframes setIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}

/* ---------- کارت‌ها ---------- */
.set-panel .card{position:relative;overflow:hidden;border-radius:var(--r-lg);
  transition:transform .2s cubic-bezier(.22,.61,.36,1),border-color .2s,box-shadow .2s}
.set-panel .card::before{content:"";position:absolute;inset-block-start:0;inset-inline:0;height:3px;
  background:linear-gradient(90deg,var(--sc),transparent 78%);opacity:.85}
.set-panel .card:hover{transform:translateY(-3px);border-color:var(--sc);box-shadow:var(--shadow-lg)}
.set-panel .card+.card{margin-top:14px}

/* ---------- سربرگ کارت ---------- */
.set-panel .card-head{align-items:flex-start;gap:12px;padding-bottom:12px;margin-bottom:14px;
  border-block-end:1px solid var(--border-soft)}
.set-panel .card-title{position:relative;font-size:15px;font-weight:800;letter-spacing:-.2px;
  display:flex;align-items:center;gap:9px}
.set-panel .card-sub{font-size:12px;color:var(--muted);line-height:1.9;margin-top:4px}

/* ---------- فیلدست ---------- */
.set-panel .fieldset{position:relative;background:var(--surface-2);border:1px solid var(--border-soft);
  border-radius:var(--r);padding:16px 15px;transition:border-color .18s,background .18s}
.set-panel .fieldset:hover{border-color:var(--border);background:var(--surface-3)}
.set-panel .fieldset+.fieldset{margin-top:13px}
.set-panel .fieldset.accent{border-color:var(--sc);background:var(--scs)}
.set-panel .section-title{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:800;
  color:var(--accent-text);margin-bottom:10px;padding-inline-start:11px;position:relative}
.set-panel .section-title::before{content:"";position:absolute;inset-inline-start:0;inset-block:2px;
  width:3px;border-radius:99px;background:var(--sc)}

/* ---------- فیلدها ---------- */
.set-panel .field{display:grid;gap:6px}
.set-panel .field>label{font-size:12px;font-weight:700;color:var(--muted)}
.set-panel .field input[type="text"],
.set-panel .field input[type="number"],
.set-panel .field input[type="password"],
.set-panel .field input[type="url"],
.set-panel .field input[type="email"],
.set-panel .field select,
.set-panel .field textarea{
  border-radius:var(--r-sm);border:1px solid var(--border);background:var(--bg-soft);
  transition:border-color .16s,box-shadow .16s,background .16s}
.set-panel .field input:focus,
.set-panel .field select:focus,
.set-panel .field textarea:focus{
  outline:none;border-color:var(--sc);background:var(--surface);
  box-shadow:0 0 0 3px var(--scs)}
.set-panel .field input:disabled,
.set-panel .field select:disabled,
.set-panel .field textarea:disabled{opacity:.55;cursor:not-allowed}

/* ---------- چک‌باکس ---------- */
.set-panel .check{display:flex;align-items:center;gap:10px;padding:11px 12px;border-radius:var(--r-sm);
  background:var(--bg-soft);border:1px solid var(--border-soft);cursor:pointer;min-height:var(--tap);
  transition:border-color .16s,background .16s,transform .16s}
.set-panel .check:hover{border-color:var(--sc);background:var(--surface);transform:translateY(-1px)}
.set-panel .check input[type="checkbox"]{width:18px;height:18px;accent-color:var(--sc);flex:none;cursor:pointer}
.set-panel .check span{font-size:12.5px;font-weight:600;line-height:1.7}
.set-panel .check:has(input:checked){border-color:var(--sc);background:var(--scs)}

/* ---------- راهنما و کد ---------- */
.set-panel .hint{font-size:11px;color:var(--muted);line-height:1.9}
.set-panel .hint code,.set-panel .card-sub code,.set-panel .alert code{
  background:var(--surface-3);border:1px solid var(--border-soft);border-radius:6px;
  padding:1px 6px;font-size:11px;direction:ltr;display:inline-block}

/* ---------- هشدارها ---------- */
.set-panel .alert{border-radius:var(--r);border-inline-start-width:4px;line-height:1.95;font-size:12.5px}

/* ---------- نوار عملیات ---------- */
.set-panel .sticky-acts{position:sticky;inset-block-end:0;z-index:5;display:flex;gap:9px;flex-wrap:wrap;
  margin-top:16px;padding:12px 0 4px;
  background:linear-gradient(180deg,transparent,var(--surface) 38%);
  border-block-start:1px solid var(--border-soft)}
.set-panel .sticky-acts .btn-primary{box-shadow:0 10px 22px -14px var(--sc)}

/* ---------- جعبهٔ عددی و کلید–مقدار ---------- */
.set-panel .numbox,.set-panel .kv{border-radius:var(--r-sm);border:1px solid var(--border-soft);background:var(--bg-soft)}
.set-panel .url-preview{border-radius:var(--r-sm);border:1px dashed var(--border);background:var(--bg-soft);
  direction:ltr;text-align:left;font-size:11.5px;word-break:break-all}

/* ---------- جدول ---------- */
.set-panel .table-wrap{border-radius:var(--r);border:1px solid var(--border-soft);overflow:auto}
.set-panel table th{position:sticky;inset-block-start:0;background:var(--surface-2);z-index:1;font-size:11.5px}
.set-panel table tbody tr{transition:background .14s}
.set-panel table tbody tr:hover{background:var(--scs)}

/* ---------- چیپ ---------- */
.set-panel .chip{border-radius:var(--r-pill);font-size:11px}

/* ---------- ریزپاسخگو ---------- */
@media (max-width:760px){
  .set-panel .card{border-radius:var(--r)}
  .set-panel .fieldset{padding:13px 12px}
  .set-panel .sticky-acts .btn{flex:1;min-width:130px}
}
</style>

<div class="tab-panel set-panel" data-tab-panel-group="set" data-tab-panel="bot">
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">🤖 وضعیت ربات</div>
        <div class="card-sub"><?= $botUser !== '' ? 'اتصال به تلگرام برقرار است' : ('اتصال برقرار نشد' . ($meErr !== '' ? ' — ' . h($meErr) : '')) ?></div>
      </div>
      <?= $botUser !== '' ? '<span class="badge b-green">متصل</span>' : '<span class="badge b-red">قطع</span>' ?>
    </div>
    <div class="kv"><span class="k">نام کاربری ربات</span><span class="mono"><?= $botUser !== '' ? '@' . h($botUser) : '—' ?></span></div>
    <div class="kv"><span class="k">آدرس وب‌هوک</span><span class="mono" style="word-break:break-all"><?= h(app_url('index.php')) ?></span></div>
    <div class="kv"><span class="k">نسخه</span><span class="mono"><?= h(APP_VERSION) ?></span></div>
    <?php if (can('settings.bot')): ?>
      <div class="row mt3" style="flex-wrap:wrap">
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="act" value="hook_set">
          <button class="btn btn-primary">🔗 تنظیم وب‌هوک</button></form>
        <form method="post" style="display:inline" data-confirm="وب‌هوک حذف شود؟ ربات موقتاً خاموش می‌شود."><?= csrf_field() ?>
          <input type="hidden" name="act" value="hook_del">
          <button class="btn">⛔ حذف وب‌هوک</button></form>
        <?php if ($botUser !== ''): ?>
          <a class="btn btn-ghost" target="_blank" href="https://t.me/<?= h($botUser) ?>">باز کردن ربات</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($meErr !== ''): ?>
    <div class="alert a-err mt3">
      ⛔ <b>پاسخ تلگرام:</b> <span class="mono ltr"><?= h($meErr) ?></span><br>
      <?php if (stripos($meErr, 'unauthorized') !== false): ?>
        یعنی <b>توکن ربات باطل یا اشتباه است</b> (در BotFather باطل شده، ربات عوض شده، یا ناقص کپی شده است).
        در تلگرام به <span class="mono ltr">@BotFather</span> بروید ← <span class="mono ltr">/mybots</span> ← ربات خود ← <span class="mono ltr">API Token</span>؛
        توکن را کامل کپی کنید و در کادر پایین بچسبانید.
      <?php else: ?>
        اگر پیام مربوط به شبکه است، دسترسی سرور به <span class="mono ltr">api.telegram.org</span> بسته است و مشکل از توکن نیست.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (can('settings.bot')): ?>
    <div class="card mt3">
      <div class="card-head">
        <div>
          <div class="card-title">🔑 توکن ربات</div>
          <div class="card-sub">اگر توکن را در BotFather عوض یا باطل کردید، همین‌جا جایگزین کنید</div>
        </div>
        <?= $cfgW ? '<span class="badge b-green">قابل ذخیره</span>' : '<span class="badge b-red">config.php فقط‌خواندنی</span>' ?>
      </div>

      <?php if (!$cfgW): ?>
        <div class="alert a-warn">برای ذخیره، سطح دسترسی فایل <span class="mono ltr">config.php</span> را روی <span class="mono ltr">644</span> بگذارید.</div>
      <?php endif; ?>

      <div class="kv"><span class="k">توکن فعلی</span><span class="mono ltr"><?= $tokMask !== '' ? h($tokMask) : '—' ?></span></div>
      <div class="kv"><span class="k">وضعیت تایید</span><span><?= $botUser !== '' ? '✅ تاییدشده' : '⛔ تایید نشد' ?></span></div>

      <form method="post" class="mt3"><?= csrf_field() ?>
        <input type="hidden" name="act" value="bot_token">
        <div class="field">
          <label>توکن جدید</label>
          <input class="mono ltr" type="text" name="bot_token" placeholder="123456789:AAE..." autocomplete="off" required>
          <div class="hint">کل رشته را کپی کنید؛ فاصله و خط جدید خودکار حذف می‌شود. نسخهٔ قبلی config.php خودکار پشتیبان می‌شود.</div>
        </div>
        <label class="check"><input type="checkbox" name="token_hook" value="1" checked><span>پس از ذخیره، وب‌هوک دوباره تنظیم شود</span></label>
        <label class="check"><input type="checkbox" name="token_force" value="1"><span>حتی اگر تلگرام تایید نکرد ذخیره شود (سرور فیلتر)</span></label>
        <div class="btn-row mt3"><button class="btn btn-primary">💾 ذخیره و آزمایش توکن</button></div>
      </form>
    </div>

    <div class="card mt3">
      <div class="card-head">
        <div>
          <div class="card-title">🌐 آدرس سایت و مدیران</div>
          <div class="card-sub">اگر دامنه عوض شده یا می‌خواهید مدیر اصلی را تغییر دهید</div>
        </div>
      </div>
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="act" value="bot_core">
        <div class="form-grid g2">
          <div class="field">
            <label>آدرس سایت (برای وب‌هوک و مینی‌اپ)</label>
            <input class="mono ltr" type="text" name="app_url" value="<?= h((string)cfg('app.url', '')) ?>" placeholder="https://example.com">
            <div class="hint">بدون اسلش انتهایی؛ وب‌هوک روی <span class="mono ltr">index.php</span> همین آدرس تنظیم می‌شود.</div>
          </div>
          <div class="field">
            <label>آیدی عددی مدیران (با کاما)</label>
            <input class="mono ltr" type="text" name="bot_admins" value="<?= h(implode(',', array_map('strval', (array)cfg('bot.admins', [])))) ?>" placeholder="123456789,987654321">
            <div class="hint">این‌ها مدیران اصلی در ربات هستند.</div>
          </div>
        </div>
        <div class="btn-row mt3"><button class="btn btn-primary">💾 ذخیره</button></div>
      </form>
    </div>
  <?php endif; ?>

  <?php if (can('settings.bot')): ?>
  <div class="card mt4">
    <div class="card-head">
      <div>
        <div class="card-title">⚙️ رفتار ربات</div>
        <div class="card-sub">اکانت تست، زیرمجموعه، هشدارها و مدیران ربات</div>
      </div>
    </div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="bot">
      <label class="check"><input type="checkbox" name="test_enabled" value="1" <?= (int)$SET('test_enabled', 1) ? 'checked' : '' ?>><span>دکمه اکانت تست فعال باشد</span></label>
      <div class="form-grid mt3">
        <div class="field full">
          <label class="check"><input type="checkbox" name="referral_enabled" value="1"
            <?= (string)$SET('referral_enabled', '1') === '1' ? 'checked' : '' ?>><span>سیستم معرفی (رفرال) فعال باشد</span></label>
          <div class="hint">هر کاربر یک لینک اختصاصی در مینی‌اپ و ربات دریافت می‌کند.</div>
        </div>

        <div class="field"><label>پاداش اولین شارژ زیرمجموعه (درصد)</label>
          <input class="mono" type="text" name="referral_bonus" value="<?= h((string)$SET('referral_bonus', 0)) ?>">
          <div class="hint">۰ = غیرفعال. یک‌بار و فقط روی اولین شارژ تاییدشده پرداخت می‌شود.</div></div>

        <div class="field"><label>پورسانت دائمی سطح ۱ (درصد)</label>
          <input class="mono" type="text" name="referral_percent" value="<?= h((string)$SET('referral_percent', 0)) ?>">
          <div class="hint">روی هر شارژ زیرمجموعهٔ مستقیم. حداکثر ۵۰٪</div></div>

        <div class="field"><label>پورسانت سطح ۲ (درصد)</label>
          <input class="mono" type="text" name="referral_l2_percent" value="<?= h((string)$SET('referral_l2_percent', 0)) ?>">
          <div class="hint">زیرمجموعهٔ زیرمجموعهٔ شما. ۰ = غیرفعال</div></div>

        <div class="field"><label>حداقل مبلغ شارژ برای پورسانت (<?= h(currency()) ?>)</label>
          <input class="mono" type="number" name="referral_min_amount" min="0" value="<?= h((string)$SET('referral_min_amount', 0)) ?>">
          <div class="hint">۰ = بدون محدودیت</div></div>

        <div class="field"><label>سقف پورسانت هر تراکنش (<?= h(currency()) ?>)</label>
          <input class="mono" type="number" name="referral_cap" min="0" value="<?= h((string)$SET('referral_cap', 0)) ?>">
          <div class="hint">۰ = بی‌نهایت</div></div>

        <div class="field full">
          <label class="check"><input type="checkbox" name="referral_first_only" value="1"
            <?= (string)$SET('referral_first_only', '0') === '1' ? 'checked' : '' ?>><span>پورسانت درصدی فقط روی اولین شارژ پرداخت شود</span></label>
          <div class="hint">خاموش = روی همهٔ شارژهای بعدی هم پورسانت داده می‌شود (درآمد دائمی).</div>
        </div>
        <div class="field"><label>هشدار انقضا (روز باقی‌مانده)</label>
          <input class="mono" type="number" name="expire_notify_days" value="<?= h((string)$SET('expire_notify_days', 3)) ?>"></div>
        <div class="field"><label>هشدار مصرف حجم (درصد)</label>
          <input class="mono" type="number" name="traffic_notify_percent" value="<?= h((string)$SET('traffic_notify_percent', 85)) ?>"></div>
        <div class="field full"><label>مدیران ربات (آیدی عددی تلگرام)</label>
          <input class="mono" type="text" name="extra_admins" value="<?= h((string)$SET('extra_admins')) ?>" placeholder="123456789,987654321">
          <div class="hint">با کاما جدا کنید. این افراد در ربات دکمه پنل مدیریت را می‌بینند. برای دسترسی تفکیکی پنل وب، به بخش «🛡️ مدیران پنل» بروید.</div></div>
      </div>
      <div class="sticky-acts"><button class="btn btn-primary">💾 ذخیره‌ی رفتار ربات</button></div>
    </form>
  </div>
  <?php endif; ?>

  <div class="card mt4">
    <div class="card-head">
      <div>
        <div class="card-title">⏰ کرانجاب (مهم)</div>
        <div class="card-sub">برای همگام‌سازی مصرف، انقضای سرویس‌ها و هشدارها لازم است</div>
      </div>
    </div>
    <div class="copy-line"><code>*/10 * * * * php <?= h(APP_ROOT) ?>/cron/tasks.php >/dev/null 2>&amp;1</code>
      <button class="btn btn-sm" data-copy="*/10 * * * * php <?= h(APP_ROOT) ?>/cron/tasks.php >/dev/null 2>&1">کپی</button></div>
    <div class="hint">روی هاست اشتراکی، این مسیر را در بخش Cron Jobs سی پنل با فاصله ۵ تا ۱۵ دقیقه تنظیم کنید.</div>
  </div>
</div>

<!-- ==================== فروشگاه ==================== -->
<?php if (can('settings.shop')): ?>
<div class="tab-panel set-panel" data-tab-panel-group="set" data-tab-panel="shop">
  <div class="card">
    <div class="card-head">
      <div><div class="card-title">🏪 فروشگاه</div>
      <div class="card-sub">متن‌ها و اطلاعات عمومی ربات</div></div>
    </div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="shop">

      <div class="fieldset">
        <div class="lg">🏷 اطلاعات پایه</div>
        <div class="form-grid">
          <div class="field"><label>نام فروشگاه</label>
            <input type="text" name="shop_title" value="<?= h((string)$SET('shop_title', 'فروشگاه کانفیگ')) ?>"></div>
          <div class="field"><label>واحد پول</label>
            <input type="text" name="currency" value="<?= h((string)$SET('currency', 'تومان')) ?>"></div>
          <div class="field"><label>آیدی پشتیبانی</label>
            <input class="mono" type="text" name="support_username" value="<?= h((string)$SET('support_username')) ?>" placeholder="@support"></div>
        </div>
      </div>

      <div class="fieldset">
        <div class="lg">✍️ متن‌های ربات</div>
        <div class="field"><label>پیام خوش‌آمدگویی</label>
          <textarea name="welcome_text" rows="3"><?= h((string)$SET('welcome_text')) ?></textarea></div>
        <div class="field"><label>قوانین و شرایط</label>
          <textarea name="rules_text" rows="3"><?= h((string)$SET('rules_text')) ?></textarea></div>
      </div>

      <div class="fieldset">
        <div class="lg">📐 حجم و زمان دلخواه</div>
        <div class="fs-hint">مشتری خودش حجم و مدت را انتخاب می‌کند و قیمت = حجم×قیمت هر گیگ + مدت×قیمت هر روز. این بخش به‌صورت دکمهٔ مستقل «📐 حجم و زمان دلخواه» در منوی اصلی ربات و همچنین داخل هر سرور فروشگاه نمایش داده می‌شود. «محصول پایه» اختیاری است: اگر انتخاب نشود، ربات به‌طور خودکار از اولین محصول فعال همان سرور استفاده می‌کند؛ فقط کافی است این بخش فعال باشد و قیمت گیگ/روز بزرگ‌تر از صفر داشته باشد.</div>
        <div class="alert a-warn" style="margin:8px 0 12px">✨ <b>روش جدید (fixed76):</b> «حجم و زمان دلخواه» را به‌عنوان یک <b>محصول</b> بسازید: منوی «محصولات» ← افزودن محصول ← نوع محصول: «📐 حجم و زمان دلخواه» (قیمت هر گیگ/هر روز و بازهٔ مجاز برای هر سرور جدا). به‌محض وجود حداقل یک محصول فعال از این نوع، تنظیمات زیر نادیده گرفته می‌شود و دکمهٔ «📐» ربات و مینی‌اپ از همان محصول(ها) استفاده می‌کند. تنظیمات زیر فقط برای سازگاری با نسخه‌های قبلی نگه داشته شده است.</div>
        <?php $cusLegacyN = 0; foreach ((array)(jdec((string)DB::setting('cus_panels', ''), []) ?: []) as $cusLg) { if (is_array($cusLg) && (int)($cusLg['on'] ?? 0) === 1) $cusLegacyN++; } ?>
        <?php if ($cusLegacyN > 0 || (int)$SET('cus_enabled', 0) === 1): ?>
        <div class="alert a-ok" style="margin:0 0 12px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
          <span>⬆️ <b>تبدیل یک‌کلیکی (fixed79):</b> برای <?= fa_num((string)$cusLegacyN) ?> سرور فعال روش قدیمی، محصول «حجم و زمان دلخواه» ساخته و روش قدیمی خاموش می‌شود.</span>
          <button class="btn btn-sm btn-primary" type="submit" form="cusMigForm" data-confirm="برای هر سرور فعالِ روش قدیمی یک محصول «دلخواه» ساخته شود؟">⬆️ تبدیل به محصول</button>
        </div>
        <?php endif; ?>
        <div class="form-grid g2">
          <div class="field"><label>وضعیت</label>
            <label class="check" style="margin-top:8px"><input type="checkbox" name="cus_enabled" value="1" <?= (int)$SET('cus_enabled', 0) ? 'checked' : '' ?>>
              <span>📐 خرید با حجم و زمان دلخواه فعال باشد</span></label></div>
          <div class="field"><label>محصول پایه (سرور و اینباند از آن برداشته می‌شود)</label>
            <select name="cus_product_id">
              <option value="0">— انتخاب کنید —</option>
              <?php foreach (DB::all("SELECT p.id, p.name, pn.name AS pname FROM {p}products p LEFT JOIN {p}panels pn ON pn.id = p.panel_id WHERE p.active = 1 ORDER BY p.id DESC") as $cusP): ?>
              <option value="<?= (int)$cusP['id'] ?>" <?= (int)$SET('cus_product_id', 0) === (int)$cusP['id'] ? 'selected' : '' ?>>#<?= (int)$cusP['id'] ?> — <?= h((string)$cusP['name']) ?> (<?= h((string)($cusP['pname'] ?? '-')) ?>)</option>
              <?php endforeach; ?>
            </select>
            <div class="hint">بهتر است موجودی محصول پایه «نامحدود» باشد تا خرید دلخواه محدود نشود</div></div>
        </div>
        <div class="form-grid g2">
          <div class="field"><label>حداقل حجم (گیگ)</label><input type="number" name="cus_min_gb" min="1" value="<?= (int)$SET('cus_min_gb', 5) ?>"></div>
          <div class="field"><label>حداکثر حجم (گیگ)</label><input type="number" name="cus_max_gb" min="1" value="<?= (int)$SET('cus_max_gb', 100) ?>"></div>
          <div class="field"><label>حداقل مدت (روز)</label><input type="number" name="cus_min_days" min="1" value="<?= (int)$SET('cus_min_days', 7) ?>"></div>
          <div class="field"><label>حداکثر مدت (روز)</label><input type="number" name="cus_max_days" min="1" value="<?= (int)$SET('cus_max_days', 90) ?>"></div>
          <div class="field"><label>قیمت هر گیگ (پیش‌فرض)</label><input type="number" name="cus_price_gb" min="0" value="<?= (int)$SET('cus_price_gb', 0) ?>"></div>
          <div class="field"><label>قیمت هر روز (پیش‌فرض)</label><input type="number" name="cus_price_day" min="0" value="<?= (int)$SET('cus_price_day', 0) ?>"></div>
        </div>
        <div class="lg" style="margin-top:14px">🌍 قیمت اختصاصی هر پنل</div>
        <div class="fs-hint">هر پنلی که «فعال» شود در ربات به مشتری نمایش داده می‌شود و با قیمت خودش حساب می‌شود. «محصول پایه» اختیاری است: اگر انتخاب نشود، به‌طور خودکار اولین محصول فعال همان پنل استفاده می‌شود. اگر قیمت صفر بماند از قیمت پیش‌فرض بالا استفاده می‌شود. اگر هیچ ردیفی فعال نباشد: با انتخاب «محصول پایه» بالا فقط همان سرور نمایش داده می‌شود و بدون آن، همهٔ سرورهای دارای محصول با قیمت پیش‌فرض در دسترس مشتری قرار می‌گیرند.</div>
        <div style="overflow:auto">
          <table style="width:100%;border-collapse:collapse;margin-top:8px">
            <tr style="text-align:right">
              <th style="padding:6px 8px">فعال</th>
              <th style="padding:6px 8px">پنل</th>
              <th style="padding:6px 8px">محصول پایه این پنل</th>
              <th style="padding:6px 8px">قیمت هر گیگ</th>
              <th style="padding:6px 8px">قیمت هر روز</th>
            </tr>
            <?php
              $cusMap = jdec((string)DB::setting('cus_panels', ''), []);
              if (!is_array($cusMap)) $cusMap = [];
              $cusProds = DB::all('SELECT id, name, panel_id FROM {p}products WHERE active = 1 ORDER BY id DESC');
              foreach (DB::all('SELECT id, name FROM {p}panels WHERE active = 1 ORDER BY sort ASC, id ASC') as $cusPn):
                $cusRow = is_array($cusMap[(int)$cusPn['id']] ?? null) ? $cusMap[(int)$cusPn['id']] : [];
            ?>
            <tr>
              <td style="padding:6px 8px"><input type="checkbox" name="cus_pn_on[<?= (int)$cusPn['id'] ?>]" value="1" <?= (int)($cusRow['on'] ?? 0) === 1 ? 'checked' : '' ?>></td>
              <td style="padding:6px 8px"><b><?= h((string)$cusPn['name']) ?></b></td>
              <td style="padding:6px 8px">
                <select name="cus_pn_pid[<?= (int)$cusPn['id'] ?>]">
                  <option value="0">— انتخاب کنید —</option>
                  <?php foreach ($cusProds as $cusPr): if ((int)$cusPr['panel_id'] !== (int)$cusPn['id']) continue; ?>
                  <option value="<?= (int)$cusPr['id'] ?>" <?= (int)($cusRow['pid'] ?? 0) === (int)$cusPr['id'] ? 'selected' : '' ?>>#<?= (int)$cusPr['id'] ?> — <?= h((string)$cusPr['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td style="padding:6px 8px"><input type="number" name="cus_pn_pg[<?= (int)$cusPn['id'] ?>]" min="0" value="<?= (int)($cusRow['pg'] ?? 0) ?>"></td>
              <td style="padding:6px 8px"><input type="number" name="cus_pn_pd[<?= (int)$cusPn['id'] ?>]" min="0" value="<?= (int)($cusRow['pd'] ?? 0) ?>"></td>
            </tr>
            <?php endforeach; ?>
          </table>
        </div>
      </div>

      <div class="fieldset">
        <div class="lg">🔒 دسترسی و حالت تعمیر</div>
        <div class="form-grid g2">
          <div class="field"><label>کانال اجباری (عضویت اجباری)</label>
            <input class="mono" type="text" name="force_channel" value="<?= h((string)$SET('force_channel')) ?>" placeholder="@channel یا خالی">
            <div class="hint">نسخهٔ کامل و چندکاناله به بخش «📢 جوین اجباری» در منوی کناری منتقل شده است؛ پس از ذخیره در آن بخش، این فیلد دیگر ملاک نیست</div></div>
          <div class="field"><label>حالت تعمیر</label>
            <label class="check" style="margin-top:8px"><input type="checkbox" name="maintenance" value="1" <?= (int)$SET('maintenance', 0) ? 'checked' : '' ?>>
              <span>ربات در حالت تعمیر باشد (فقط مدیران)</span></label></div>
        </div>
      </div>

      <div class="fieldset">
        <div class="lg">📱 فقط مینی‌اپ (سکوت ربات)</div>
        <div class="fs-hint">اگر می‌خواهید کاربران فقط از مینی‌اپ استفاده کنند، این گزینه را روشن کنید تا ربات به هیچ پیامی در تلگرام پاسخ ندهد.</div>
        <div class="form-grid g2">
          <div class="field">
            <label>وضعیت پاسخ‌گویی ربات</label>
            <label class="check" style="margin-top:8px">
              <input type="checkbox" name="bot_mute" value="1" <?= (int)$SET('bot_mute', 0) ? 'checked' : '' ?>>
              <span>🔇 ربات پاسخ ندهد (فقط مینی‌اپ فعال باشد)</span>
            </label>
            <div class="hint">خاموش = ربات مثل همیشه جواب می‌دهد.</div>
          </div>
          <div class="field">
            <label>استثنای مدیران</label>
            <label class="check" style="margin-top:8px">
              <input type="checkbox" name="bot_mute_admins" value="1" <?= (int)$SET('bot_mute_admins', 1) ? 'checked' : '' ?>>
              <span>مدیران همچنان بتوانند از ربات استفاده کنند</span>
            </label>
            <div class="hint">پیشنهاد می‌شود روشن بماند تا دسترسی خودتان به ربات قطع نشود.</div>
          </div>
        </div>
        <?php if ((int)$SET('bot_mute', 0)): ?>
          <div class="alert a-warn mt3">🔇 هم‌اکنون ربات در حالت سکوت است و فقط مینی‌اپ پاسخ می‌دهد<?= (int)$SET('bot_mute_admins', 1) ? ' (به‌جز مدیران)' : '' ?>.</div>
        <?php endif; ?>
      </div>

      <div class="sticky-acts"><button class="btn btn-primary">💾 ذخیره‌ی تنظیمات فروشگاه</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ==================== پرداخت ==================== -->
<?php if (can('settings.payment')): ?>
<div class="tab-panel set-panel" data-tab-panel-group="set" data-tab-panel="pay">
  <div class="card">
    <div class="card-head">
      <div><div class="card-title">💳 روش‌های پرداخت</div>
      <div class="card-sub">کارت به کارت، پرداخت ارزی و محدودیت شارژ</div></div>
    </div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="pay">

      <div class="fieldset">
        <div class="lg">💳 کارت به کارت</div>
        <label class="check"><input type="checkbox" name="card_enabled" value="1" <?= (int)$SET('card_enabled', 1) ? 'checked' : '' ?>><span>فعال باشد</span></label>
        <div class="form-grid mt3">
          <div class="field"><label>شماره کارت</label>
            <input class="mono" type="text" name="card_number" value="<?= h((string)$SET('card_number')) ?>" placeholder="6037-xxxx-xxxx-xxxx"></div>
          <div class="field"><label>به نام</label>
            <input type="text" name="card_holder" value="<?= h((string)$SET('card_holder')) ?>"></div>
          <div class="field"><label>بانک</label>
            <input type="text" name="card_bank" value="<?= h((string)$SET('card_bank')) ?>"></div>
        </div>
      </div>

      <div class="fieldset">
        <div class="lg">🌐 پرداخت ارزی (دستی)</div>
        <label class="check"><input type="checkbox" name="crypto_enabled" value="1" <?= (int)$SET('crypto_enabled', 1) ? 'checked' : '' ?>><span>فعال باشد</span></label>
        <div class="form-grid mt3">
          <div class="field"><label>ارز / توکن</label>
            <input type="text" name="crypto_asset" value="<?= h((string)$SET('crypto_asset', 'USDT (TRC20)')) ?>"></div>
          <div class="field"><label>شبکه</label>
            <input type="text" name="crypto_network" value="<?= h((string)$SET('crypto_network', 'TRON / TRC20')) ?>"></div>
          <div class="field"><label>نرخ هر دلار (<?= h(currency()) ?>)</label>
            <input class="mono" type="text" name="usd_rate" value="<?= h((string)$SET('usd_rate', 100000)) ?>"></div>
          <div class="field full"><label>آدرس کیف پول ارزی</label>
            <input class="mono" type="text" name="crypto_address" value="<?= h((string)$SET('crypto_address')) ?>" placeholder="T..."></div>
        </div>
      </div>

      <div class="fieldset">
        <div class="lg">💰 محدودیت شارژ کیف پول</div>
        <div class="form-grid g2">
          <div class="field"><label>حداقل مبلغ شارژ</label>
            <input class="mono" type="text" name="min_deposit" value="<?= h((string)$SET('min_deposit', 50000)) ?>"></div>
          <div class="field"><label>حداکثر مبلغ شارژ</label>
            <input class="mono" type="text" name="max_deposit" value="<?= h((string)$SET('max_deposit', 50000000)) ?>"></div>
        </div>
      </div>

      <div class="sticky-acts"><button class="btn btn-primary">💾 ذخیره‌ی تنظیمات پرداخت</button></div>
    </form>
  </div>

  <div class="card mt4">
    <div class="card-head">
      <div><div class="card-title">⚡️ درگاه خودکار ارزی – NowPayments</div>
      <div class="card-sub">تایید پرداخت و شارژ کیف پول بدون دخالت مدیر</div></div>
      <?= NowPay::enabled() ? '<span class="badge b-green">فع��ل</span>' : '<span class="badge b-gray">غیرفعال</span>' ?>
    </div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="nowpay">
      <label class="check"><input type="checkbox" name="nowpay_enabled" value="1" <?= (int)$SET('nowpay_enabled', 0) ? 'checked' : '' ?>><span>درگاه در ربات نمایش داده شود</span></label>
      <div class="form-grid g2 mt3">
        <div class="field"><label>کلید API</label>
          <input class="mono" type="text" name="nowpay_api_key" value="<?= h((string)$SET('nowpay_api_key')) ?>" placeholder="Settings → API keys">
          <div class="hint">پنل NowPayments ← Store settings ← API keys</div></div>
        <div class="field"><label>IPN Secret</label>
          <input class="mono" type="text" name="nowpay_ipn_secret" value="<?= h((string)$SET('nowpay_ipn_secret')) ?>" placeholder="IPN secret key">
          <div class="hint">برای بررسی امضای پیام‌های درگاه الزامی است</div></div>
        <div class="field"><label>ارز پیش‌فرض (اختیاری)</label>
          <input class="mono" type="text" name="nowpay_currency" value="<?= h((string)$SET('nowpay_currency')) ?>" placeholder="usdttrc20">
          <div class="hint">خالی = کاربر خودش ارز را انتخاب می‌کند</div></div>
        <div class="field"><label>حداقل مبلغ (دلار)</label>
          <input class="mono" type="text" name="nowpay_min_usd" value="<?= h((string)$SET('nowpay_min_usd', 5)) ?>"></div>
      </div>
      <div class="field mt3"><label>آدرس Callback (IPN)</label>
        <div class="copy-line"><code><?= h(app_url('/nowpay.php')) ?></code>
          <button class="btn btn-sm" type="button" data-copy="<?= h(app_url('/nowpay.php')) ?>">کپی</button></div>
        <div class="hint">این آدرس را در Store settings ← IPN callback URL درگاه ثبت کنید</div></div>
      <div class="row mt3">
        <button class="btn btn-primary">💾 ذخیره‌ی درگاه</button>
      </div>
    </form>
    <form method="post" class="mt3"><?= csrf_field() ?>
      <input type="hidden" name="act" value="nowpay_test">
      <button class="btn">🔌 تست اتصال و کلید</button></form>
  </div>
</div>
<?php endif; ?>

<!-- ==================== نرخ ارز ==================== -->
<?php if (can('settings.payment')): ?>
<div class="tab-panel set-panel" data-tab-panel-group="set" data-tab-panel="rate">
  <div class="card">
    <div class="card-head">
      <div><div class="card-title">📈 نرخ خودکار ارز</div>
      <div class="card-sub">محاسبهٔ لحظه‌ای نرخ دلار/تتر به <?= h(currency()) ?></div></div>
      <?= $ri['mode'] === 'auto' ? '<span class="badge b-green">خودکار</span>' : '<span class="badge b-gray">دستی</span>' ?>
    </div>

    <div class="mini-stats">
      <div class="mini ok"><div class="ic">💵</div><div><div class="t">نرخ فعلی هر دلار</div><div class="v"><?= h(money((int)$ri['rate'])) ?></div></div></div>
      <div class="mini"><div class="ic">📊</div><div><div class="t">نرخ خام بازار</div><div class="v"><?= (int)$ri['raw'] > 0 ? h(money((int)$ri['raw'])) : '—' ?></div></div></div>
      <div class="mini"><div class="ic">📡</div><div><div class="t">منبع مورد استفاده</div><div class="v" style="font-size:13px"><?= h((string)(Rates::SOURCES[(string)$ri['used']] ?? ($ri['used'] ?: '—'))) ?></div></div></div>
      <div class="mini <?= !empty($ri['stale']) ? 'warn' : '' ?>"><div class="ic">🕓</div><div><div class="t">آخرین به‌روزرسانی</div><div class="v" style="font-size:13px"><?= (string)$ri['updated_at'] !== '' ? h(to_jalali((string)$ri['updated_at'], true)) : '—' ?></div></div></div>
    </div>

    <?php if ((string)$ri['error'] !== ''): ?><div class="alert a-warn mt3">⚠ <?= h((string)$ri['error']) ?></div><?php endif; ?>

    <form method="post" class="mt3">
      <?= csrf_field() ?><input type="hidden" name="act" value="rates">

      <div class="section-title">۱) حالت محاسبه</div>
      <div class="form-grid">
        <div class="field"><label>حالت نرخ</label>
          <select name="rate_mode">
            <option value="manual" <?= $ri['mode'] === 'manual' ? 'selected' : '' ?>>دستی (عدد ثابت)</option>
            <option value="auto" <?= $ri['mode'] === 'auto' ? 'selected' : '' ?>>خودکار از بازار</option>
          </select></div>
        <div class="field"><label>منبع اصلی نرخ</label>
          <select name="rate_source">
            <?php foreach (Rates::SOURCES as $rk => $rlbl): if ($rk === 'manual') continue; ?>
              <option value="<?= h($rk) ?>" <?= $ri['source'] === $rk ? 'selected' : '' ?>><?= h($rlbl) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="hint">اگر این منبع پاسخ ندهد، به‌طور خودکار سراغ منبع بعدی می‌رود</div></div>
        <div class="field"><label>واحد محاسبه</label>
          <select name="rate_unit">
            <option value="toman" <?= $ri['unit'] === 'toman' ? 'selected' : '' ?>>تومان</option>
            <option value="rial" <?= $ri['unit'] === 'rial' ? 'selected' : '' ?>>ریال (×۱۰)</option>
          </select></div>
        <div class="field"><label>کارمزد / سود (٪)</label>
          <input class="mono" type="text" name="rate_markup" value="<?= h((string)$ri['markup']) ?>">
          <div class="hint">مثلاً ۳ یعنی ۳٪ بالاتر از نرخ بازار</div></div>
        <div class="field"><label>رند کردن به</label>
          <input class="mono" type="text" name="rate_round" value="<?= h((string)$ri['step']) ?>"></div>
        <div class="field"><label>عمر کش نرخ (دقیقه)</label>
          <input class="mono" type="text" name="rate_ttl" value="<?= h((string)$ri['ttl']) ?>">
          <div class="hint">فقط وقتی «نرخ لحظه‌ای» خاموش باشد استفاده می‌شود</div></div>
      </div>

      <div class="section-title">۲) دریافت خودکار و لحظه‌ای</div>
      <div class="grid g2">
        <label class="check"><input type="checkbox" name="rate_failover" value="1" <?= !empty($ri['failover']) ? 'checked' : '' ?>>
          <span>زنجیرهٔ خودکار منابع – هر کدام جواب نداد، بعدی امتحان شود</span></label>
        <label class="check"><input type="checkbox" name="rate_live" value="1" <?= !empty($ri['live']) ? 'checked' : '' ?>>
          <span>نرخ لحظه‌ای – هر بار که کاربر مبلغ وارد می‌کند، نرخ تازه گرفته شود</span></label>
      </div>
      <div class="alert a-info mt3">ترتیب پیش‌فرض زنجیره: <span class="mono"><?= h(implode(' ← ', array_map(fn($s) => (string)(Rates::SOURCES[$s] ?? $s), Rates::chain()))) ?></span></div>

      <div class="section-title">۳) ارزهای قابل پرداخت</div>
      <div class="table-wrap"><table class="responsive">
        <thead><tr><th>ارز</th><th>فعال</th><th>آدرس کیف پول</th><th>شبکه</th></tr></thead>
        <tbody>
        <?php foreach (Rates::ASSETS as $ak => $am): ?>
          <tr>
            <td data-l="ارز"><b><?= h((string)$am['icon']) ?> <?= h($ak) ?></b></td>
            <td data-l="فعال"><label class="check"><input type="checkbox" name="asset_<?= h($ak) ?>" value="1"
              <?= in_array($ak, $rateOn, true) ? 'checked' : '' ?> <?= $ak === 'USDT' ? 'disabled checked' : '' ?>><span><?= $ak === 'USDT' ? 'همیشه فعال' : 'فعال باشد' ?></span></label></td>
            <td data-l="آدرس"><input class="mono" type="text" name="addr_<?= h($ak) ?>" value="<?= h((string)$SET('crypto_addr_' . $ak)) ?>" placeholder="آدرس کیف پول"></td>
            <td data-l="شبکه"><input class="mono" type="text" name="net_<?= h($ak) ?>" value="<?= h((string)$SET('crypto_net_' . $ak, trim((string)explode('/', (string)($am['networks'] ?? ''))[0]))) ?>" placeholder="<?= h((string)($am['networks'] ?? '')) ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>

      <div class="section-title">۴) API دلخواه شما (اختیاری)</div>
      <div class="alert a-info">اگر سرویس نرخ خودتان را دارید، آدرس آن را ��ینجا بدهید. در «منبع اصلی» گزینهٔ <b>API دلخواه من</b> را انتخاب کنید یا بگذارید زنجیره خودش سراغش بیاید.</div>
      <div class="form-grid">
        <div class="field" style="grid-column:1/-1"><label>آدرس API</label>
          <input class="mono" type="text" name="rate_custom_url" dir="ltr" value="<?= h((string)$SET('rate_custom_url')) ?>" placeholder="https://example.com/api/usdt"></div>
        <div class="field"><label>مسیر مقدار در خروجی JSON</label>
          <input class="mono" type="text" name="rate_custom_path" dir="ltr" value="<?= h((string)$SET('rate_custom_path')) ?>" placeholder="data.usdt.price">
          <div class="hint">مسیر را با نقطه جدا کنید. خالی = خود خروجی عدد است</div></div>
        <div class="field"><label>واحد خروجی API</label>
          <select name="rate_custom_unit">
            <option value="toman" <?= (string)$SET('rate_custom_unit', 'toman') === 'toman' ? 'selected' : '' ?>>تومان</option>
            <option value="rial" <?= (string)$SET('rate_custom_unit') === 'rial' ? 'selected' : '' ?>>ریال</option>
          </select></div>
        <div class="field"><label>ضریب تبدیل</label>
          <input class="mono" type="text" name="rate_custom_scale" value="<?= h((string)$SET('rate_custom_scale', '1')) ?>">
          <div class="hint">عدد خروجی در این عدد ضرب می‌شود (پیش‌فرض ۱)</div></div>
        <div class="field" style="grid-column:1/-1"><label>هدرهای اختصاصی (هر خط یکی)</label>
          <textarea class="mono" name="rate_custom_headers" rows="3" dir="ltr" placeholder="Authorization: Bearer xxxxx"><?= h((string)$SET('rate_custom_headers')) ?></textarea></div>
      </div>

      <div class="sticky-acts"><button class="btn btn-primary">💾 ذخیرهٔ تنظیمات نرخ</button></div>
    </form>

    <div class="btn-row mt3">
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="act" value="rate_now">
        <button class="btn btn-green">🔄 به‌روزرسانی نرخ همین حالا</button></form>
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="act" value="rate_test">
        <button class="btn">🧪 آزمایش همهٔ منابع</button></form>
    </div>

    <?php if (is_array($rt)): ?>
      <div class="section-title">نتیجهٔ آزمایش منابع</div>
      <div class="table-wrap"><table class="responsive">
        <thead><tr><th>منبع</th><th>وضعیت</th><th>نرخ دریافتی</th><th>پیام</th></tr></thead>
        <tbody>
        <?php foreach ((array)($rt['src'] ?? []) as $sk => $sv): ?>
          <tr>
            <td data-l="منبع"><?= h((string)(Rates::SOURCES[$sk] ?? $sk)) ?></td>
            <td data-l="وضعیت"><?= !empty($sv['ok']) ? '<span class="badge b-green">سالم</span>' : '<span class="badge b-red">خطا</span>' ?></td>
            <td data-l="نرخ" class="mono"><?= (float)($sv['rate'] ?? 0) > 0 ? h(money((int)$sv['rate'])) : '—' ?></td>
            <td data-l="پیام" class="muted"><?= h(mb_substr((string)($sv['error'] ?? '—'), 0, 90)) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ((array)($rt['ast'] ?? []) as $ak2 => $av): ?>
          <tr>
            <td data-l="منبع"><b><?= h((string)$ak2) ?></b> <span class="muted">(قیمت جهانی)</span></td>
            <td data-l="وضعیت"><?= !empty($av['ok']) ? '<span class="badge b-green">سالم</span>' : '<span class="badge b-red">خطا</span>' ?></td>
            <td data-l="نرخ" class="mono"><?= (float)($av['price'] ?? 0) > 0 ? h(money((int)$av['price'])) : '—' ?></td>
            <td data-l="پیام" class="muted"><?= h(mb_substr((string)($av['error'] ?? '—'), 0, 90)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ==================== گزارشات ==================== -->
<?php if (can('settings.logs')): ?>
<div class="tab-panel set-panel" data-tab-panel-group="set" data-tab-panel="logs">
  <div class="card">
    <div class="card-head">
      <div><div class="card-title">🗂 گروه گزارشات (تاپیک‌دار)</div>
      <div class="card-sub">هر رویداد در تاپیک مخصوص خودش ارسال می‌شود</div></div>
      <?= Logs::enabled() ? '<span class="badge b-green">فعال</span>' : '<span class="badge b-gray">غیرفعال</span>' ?>
    </div>

    <details class="collapse">
      <summary>📘 راهنمای راه‌اندازی گروه گزارشات</summary>
      <div class="body">
        <div class="alert a-info">۱) گروه بسازید و <b>Topics</b> را روشن کنید. ۲) ربات را ادمین گروه کنید (با دسترسی «مدیریت تاپیک‌ها»). ۳) در گروه دستور <span class="mono">/id</span> را بفرستید تا شناسه گروه را ببینید. ۴) شناسه را اینجا وارد کنید و روی <b>ذخیره + ساخت خودکار تاپیک‌ها</b> ��زنید.</div>
      </div>
    </details>

    <form method="post" class="mt3">
      <?= csrf_field() ?>
      <label class="check"><input type="checkbox" name="log_enabled" value="1" <?= (int)$SET('log_enabled', 0) ? 'checked' : '' ?>><span>ارسال گزارش‌ها فعال باشد</span></label>
      <div class="form-grid g2 mt3">
        <div class="field"><label>شناسه گروه گزارشات</label>
          <input class="mono" type="text" name="log_chat_id" value="<?= h((string)$SET('log_chat_id')) ?>" placeholder="-1001234567890">
          <div class="hint">با فرستادن دستور /id در گروه، شناسه را ببینید</div></div>
        <div class="field"><label>پشتیبان‌گیری روزانه</label>
          <label class="check" style="margin-top:8px"><input type="checkbox" name="log_backup" value="1" <?= (int)$SET('log_backup', 1) ? 'checked' : '' ?>>
            <span>فایل پشتیبان دیتابیس در تاپیک بکاپ ارسال شود</span></label></div>
      </div>

      <div class="section-title">تاپیک‌ها و رویدادها</div>
      <div class="table-wrap"><table class="responsive">
        <thead><tr><th>گزارش</th><th>شناسه تاپیک</th><th>وضعیت</th><th>بدون اعلان</th></tr></thead>
        <tbody>
        <?php foreach (Logs::TOPICS as $lk => $lv): ?>
          <tr>
            <td><?= h((string)$lv[0]) ?></td>
            <td class="mono"><?= isset($lt[$lk]) ? h(fa_num((string)$lt[$lk])) : '<span class="muted">ساخته نشده</span>' ?></td>
            <td><label class="check"><input type="checkbox" name="ev_<?= h((string)$lk) ?>" value="1" <?= in_array($lk, $loff, true) ? '' : 'checked' ?>><span>ارسال شود</span></label></td>
            <td><label class="check"><input type="checkbox" name="sl_<?= h((string)$lk) ?>" value="1" <?= in_array($lk, $lsil, true) ? 'checked' : '' ?>><span>بی‌صدا</span></label></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>

      <div class="section-title">🛡 پایداری ارسال گزارش‌ها</div>
      <div class="form-grid g2">
        <div class="field"><label>فاصلهٔ ضدتکرار (دقیقه)</label>
          <input class="mono" type="number" name="log_dedup_min" min="0" max="1440" value="<?= (int)$SET('log_dedup_min', 3) ?>">
          <div class="hint">پیام کاملاً یکسان در این بازه دوباره ارسال نمی‌شود — <b>۰ = خاموش</b></div></div>
        <div class="field"><label>ساخت خودکار تاپیک</label>
          <label class="check" style="margin-top:8px"><input type="checkbox" name="log_auto_topic" value="1" <?= (int)$SET('log_auto_topic', 1) ? 'checked' : '' ?>>
            <span>اگر تاپیکی نبود یا حذف شد، خودکار ساخته شود</span></label></div>
        <div class="field"><label>تست یک تاپیک مشخص</label>
          <select name="tkey">
            <?php foreach (Logs::TOPICS as $tk1 => $tv1): ?>
              <option value="<?= h((string)$tk1) ?>"><?= h((string)$tv1[0]) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="hint">با دکمهٔ «تست تاپیک انتخابی» یک پیام آزمایشی فقط در همین تاپیک ارسال می‌شود</div></div>
      </div>
      <div class="alert a-info mt3">
        📊 امروز: <b><?= fa_num((string)(int)$lstat['ok']) ?></b> ارسال موفق ـ <b><?= fa_num((string)(int)$lstat['err']) ?></b> ناموفق ـ صف معلق: <b><?= fa_num((string)(int)$lstat['queue']) ?></b>
        <?php if ((string)$lstat['last_err'] !== ''): ?><br>آخرین خطا: <span class="mono"><?= h(mb_substr((string)$lstat['last_err'], 0, 160)) ?></span><?php endif; ?>
      </div>

      <div class="section-title">⏱ بازهٔ گزارش‌های دوره‌ای (ساعت)</div>
      <div class="hint" style="margin-bottom:8px">هر گزارش هر چند ساعت یک‌بار ارسال شود؛ <b>۰ = خاموش</b>. اجرای دقیق به فعال بودن کرانجاب وابسته است. زمان‌بندی بکاپ خودکار جداگانه در بخش «پشتیبان‌گیری» تنظیم می‌شود.</div>
      <div class="form-grid g2">
        <div class="field"><label>🖥 گزارش وضعیت پنل‌ها</label>
          <input class="mono" type="number" name="rep_panels_h" min="0" max="720" value="<?= (int)$SET('rep_panels_h', 24) ?>">
          <div class="hint">سلامت، تعداد کاربر و آنلاین هر پنل — پیش‌فرض ۲۴</div></div>
        <div class="field"><label>🌙 گزارش آماری دوره‌ای</label>
          <input class="mono" type="number" name="rep_summary_h" min="0" max="720" value="<?= (int)$SET('rep_summary_h', 24) ?>">
          <div class="hint">کاربر جدید، فروش، شارژ، تیکت — جایگزین گزارش شبانه</div></div>
        <div class="field"><label>💰 گزارش فروش</label>
          <input class="mono" type="number" name="rep_sales_h" min="0" max="720" value="<?= (int)$SET('rep_sales_h', 0) ?>">
          <div class="hint">تعداد و جمع خریدهای موفق بازهٔ اخیر</div></div>
        <div class="field"><label>👥 گزارش کاربران جدید</label>
          <input class="mono" type="number" name="rep_users_h" min="0" max="720" value="<?= (int)$SET('rep_users_h', 0) ?>">
          <div class="hint">کاربران جدید بازهٔ اخیر + کل کاربران</div></div>
        <div class="field"><label>⏳ یادآور پرداخت‌های در انتظار</label>
          <input class="mono" type="number" name="rep_pays_h" min="0" max="720" value="<?= (int)$SET('rep_pays_h', 0) ?>">
          <div class="hint">فقط وقتی رسیدِ منتظر تایید وجود دارد ارسال می‌شود</div></div>
        <div class="field"><label>⚠️ هشدار قطعی اتصال پنل‌ها</label>
          <input class="mono" type="number" name="rep_warnconn_h" min="1" max="720" value="<?= (int)$SET('rep_warnconn_h', 24) ?>">
          <div class="hint">حداکثر یک هشدار در این بازه — پیش‌فرض ۲۴</div></div>
      </div>

      <div class="sticky-acts" style="flex-wrap:wrap">
        <button class="btn btn-primary" name="act" value="logs">💾 ذخیره</button>
        <button class="btn btn-green" name="act" value="logs_setup">🧩 ذخیره + ساخت خودکار تاپیک‌ها</button>
        <button class="btn" name="act" value="logs_setup_rebuild" data-confirm="تاپیک‌ها دوباره ساخته شوند؟">♻️ ساخت مجدد همه</button>
        <button class="btn btn-ghost" name="act" value="logs_test">🧪 ارسال پیام تست</button>
        <button class="btn btn-ghost" name="act" value="logs_test_one">🎯 تست تاپیک انتخابی</button>
        <button class="btn" name="act" value="logs_repair">🩺 بررسی و ترمیم تاپیک‌ها</button>
        <button class="btn" name="act" value="logs_flush">🚚 ارسال صف معلق (<?= fa_num((string)(int)$lstat['queue']) ?>)</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ==================== پیام همگانی ==================== -->
<?php if (can('settings.broadcast')): ?>
<!-- ==================== کانال انتشار آپدیت ==================== -->
<style>
/* ================= Release Channel Studio v1 ================= */
.rel-hero{position:relative;overflow:hidden;border:1px solid var(--border);border-radius:var(--r-lg);
  background:linear-gradient(135deg,rgba(56,189,248,.13),rgba(139,92,246,.08) 55%,transparent);
  padding:18px;margin-bottom:14px}
.rel-hero .g{position:absolute;width:270px;height:270px;border-radius:50%;filter:blur(62px);opacity:.42;pointer-events:none}
.rel-hero .g1{background:#38BDF8;top:-150px;inset-inline-end:-90px}
.rel-hero .g2{background:#8B5CF6;bottom:-165px;inset-inline-start:-70px}
.rel-top{display:flex;align-items:center;gap:12px;position:relative;flex-wrap:wrap}
.rel-ic{width:52px;height:52px;border-radius:16px;display:grid;place-items:center;font-size:25px;
  background:linear-gradient(135deg,#38BDF8,#8B5CF6);box-shadow:0 8px 22px rgba(56,189,248,.3)}
.rel-tt{min-width:0;flex:1}
.rel-tt h3{margin:0;font-size:17px;font-weight:800}
.rel-tt .s{color:var(--muted);font-size:12.5px;margin-top:3px;line-height:1.8}
.rel-cells{display:grid;grid-template-columns:repeat(auto-fit,minmax(138px,1fr));gap:9px;margin-top:15px;position:relative}
.rel-cell{background:var(--surface-2);border:1px solid var(--border-soft);border-radius:var(--r);padding:10px 11px}
.rel-cell .k{font-size:11px;color:var(--muted);display:flex;gap:5px;align-items:center}
.rel-cell .v{font-weight:800;font-size:14px;margin-top:5px;word-break:break-all}
.rel-cell .v.sm{font-size:12px}
.rel-cell.g{border-color:rgba(47,212,143,.42)}.rel-cell.g .v{color:var(--green)}
.rel-cell.r{border-color:rgba(255,107,107,.42)}.rel-cell.r .v{color:var(--red)}
.rel-cell.o{border-color:rgba(255,169,46,.42)}.rel-cell.o .v{color:var(--orange)}
.rel-cell.b{border-color:rgba(56,189,248,.42)}.rel-cell.b .v{color:#7DD3FC}
.rel-cell.p{border-color:rgba(139,92,246,.42)}.rel-cell.p .v{color:#C4B5FD}

.rel-steps{display:grid;gap:9px}
.rel-step{display:flex;gap:11px;align-items:flex-start;background:var(--surface-2);
  border:1px solid var(--border-soft);border-radius:var(--r);padding:11px 12px}
.rel-step .n{flex:0 0 auto;width:26px;height:26px;border-radius:9px;display:grid;place-items:center;
  font-weight:800;font-size:12.5px;background:rgba(56,189,248,.15);color:#7DD3FC;border:1px solid rgba(56,189,248,.32)}
.rel-step .b{min-width:0}
.rel-step .t{font-weight:700;font-size:13.5px;margin-bottom:3px}
.rel-step .d{color:var(--muted);font-size:12.5px;line-height:1.95}
.rel-step .d b{color:#dbe3f0}
.rel-step code{background:rgba(255,255,255,.07);padding:2px 6px;border-radius:6px;direction:ltr;
  display:inline-block;font-size:11.5px;unicode-bidi:embed}

.rel-styles{display:grid;grid-template-columns:repeat(auto-fit,minmax(205px,1fr));gap:11px}
.rel-st{position:relative;cursor:pointer;display:block;background:var(--surface-2);
  border:1.5px solid var(--border);border-radius:var(--r-lg);padding:12px;transition:.18s}
.rel-st:hover{transform:translateY(-2px);border-color:#38BDF8}
.rel-st input{position:absolute;opacity:0;pointer-events:none}
.rel-st .nm{font-weight:800;font-size:13.5px}
.rel-st .ds{color:var(--muted);font-size:11.5px;margin-top:3px;line-height:1.75;min-height:34px}
.rel-st .mk{margin-top:9px;background:var(--bg);border:1px solid var(--border-soft);border-radius:10px;
  padding:9px 10px;font-size:10.5px;line-height:1.85;color:#b9c4d6;white-space:pre-wrap;min-height:100px}
.rel-st:has(input:checked){border-color:#38BDF8;box-shadow:0 0 0 3px rgba(56,189,248,.16);background:var(--surface-3)}
.rel-st:has(input:checked) .nm::after{content:" ✓";color:var(--green)}

.rel-tg{background:#0e1621;border:1px solid #1d2836;border-radius:16px;padding:13px}
.rel-tgh{display:flex;align-items:center;gap:8px;padding-bottom:9px;margin-bottom:10px;border-bottom:1px solid #1d2836}
.rel-tgh .av{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#38BDF8,#8B5CF6);
  display:grid;place-items:center;font-size:15px}
.rel-tgh .nm{font-weight:700;font-size:12.5px;color:#e9edf2}
.rel-tgh .sb{font-size:10.5px;color:#7d8b9c}
.rel-prev{background:#17212b;border:1px solid #23303c;border-radius:13px;padding:13px 14px;
  font-size:13px;line-height:2.05;color:#e9edf2;word-break:break-word}
.rel-prev code{background:rgba(255,255,255,.09);padding:1px 5px;border-radius:5px;direction:ltr;
  display:inline-block;font-size:12px;unicode-bidi:embed}
.rel-prev pre{background:rgba(0,0,0,.34);padding:9px;border-radius:9px;overflow-x:auto;direction:ltr;
  text-align:left;font-size:11.5px;margin:7px 0;line-height:1.7}
.rel-empty{color:var(--muted);font-size:12.5px;text-align:center;padding:20px}
.rel-acts{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.rel-hist td.ltr{direction:ltr;text-align:start}
@media (max-width:760px){.rel-ic{width:44px;height:44px;font-size:21px}.rel-tt h3{font-size:15.5px}}
</style>

<div class="tab-panel set-panel" data-tab-panel-group="set" data-tab-panel="rel">
<?php
/* ---------- داده‌های نمایشی کانال انتشار ---------- */
$relOk    = class_exists('Release');
$relEn    = (string)$SET('rel_enabled', '0') === '1';
$relCh    = trim((string)$SET('rel_chat_id', ''));
$relTid   = (int)$SET('rel_topic_id', 0);
$relSty   = (string)$SET('rel_style', 'hero');
$relLastV = (string)$SET('rel_last_ver', '');
$relLastA = (string)$SET('rel_last_at', '');
$relHist  = [];
$relPendN = 0;
$relPrev  = '';
if ($relOk) {
    if (!isset(Release::STYLES[$relSty])) $relSty = 'hero';
    try { $relHist = Release::history(); } catch (Throwable $e) { $relHist = []; }
    try { $relPendN = Release::countItems(Release::pending()); } catch (Throwable $e) { $relPendN = 0; }
    try { $relPrev = Release::render(['from' => $relLastV, 'to' => APP_VERSION]); } catch (Throwable $e) { $relPrev = ''; }
}
/* پیش‌نمایش امن: فقط تگ‌های قالب‌بندی مجازند */
$relPrevSafe = $relPrev !== '' ? nl2br(strip_tags($relPrev, '<b><i><u><s><code><pre><a>')) : '';
$relStName   = ($relOk && isset(Release::STYLES[$relSty])) ? (string)Release::STYLES[$relSty][0] : '—';
$relAuto     = (string)$SET('rel_auto', '1') === '1';
?>

  <section class="rel-hero">
    <span class="g g1" aria-hidden="true"></span><span class="g g2" aria-hidden="true"></span>
    <div class="rel-top">
      <div class="rel-ic">📣</div>
      <div class="rel-tt">
        <h3>کانال انتشار آپدیت‌ها</h3>
        <div class="s">ربات را در کانال خود ادمین کنید؛ هر بار که به‌روزرسانی نصب شود، لیست تغییرات به‌صورت خودکار و با طراحی زیبا منتشر می‌شود.</div>
      </div>
      <div><span class="badge <?= $relEn ? 'b-green' : 'b-gray' ?>"><?= $relEn ? '🟢 فعال' : '⚪ خاموش' ?></span></div>
    </div>

    <div class="rel-cells">
      <div class="rel-cell <?= $relEn ? 'g' : 'r' ?>"><div class="k">⚡ وضعیت</div><div class="v"><?= $relEn ? 'روشن' : 'خاموش' ?></div></div>
      <div class="rel-cell <?= $relCh !== '' ? 'b' : 'r' ?>"><div class="k">📡 کانال</div><div class="v sm mono ltr"><?= $relCh !== '' ? h($relCh) : 'تنطیم نشده' ?></div></div>
      <div class="rel-cell <?= $relTid > 0 ? 'g' : '' ?>"><div class="k">🧵 تاپیک</div><div class="v"><?= $relTid > 0 ? fa_num((string)$relTid) : 'بدون تاپیک' ?></div></div>
      <div class="rel-cell p"><div class="k">🎨 قالب پیام</div><div class="v sm"><?= h($relStName) ?></div></div>
      <div class="rel-cell <?= $relAuto ? 'g' : 'o' ?>"><div class="k">🤖 انتشار خودکار</div><div class="v"><?= $relAuto ? 'روشن' : 'خاموش' ?></div></div>
      <div class="rel-cell <?= $relPendN > 0 ? 'o' : '' ?>"><div class="k">📝 تغییرات آماده</div><div class="v"><?= fa_num((string)$relPendN) ?></div></div>
      <div class="rel-cell b"><div class="k">🏷 نسخهٔ فعلی</div><div class="v sm mono ltr"><?= h(APP_VERSION) ?></div></div>
      <div class="rel-cell"><div class="k">📤 آخرین انتشار</div><div class="v sm"><?= $relLastA !== '' ? h(to_jalali($relLastA, false)) : '—' ?></div></div>
      <div class="rel-cell"><div class="k">🗂 تعداد انتشار</div><div class="v"><?= fa_num((string)count($relHist)) ?></div></div>
    </div>
  </section>

<?php if (!$relOk): ?>
  <div class="alert a-err">❗ فایل <span class="mono">app/Service/Release.php</span> پیدا نشد. فایل‌های نسخهٔ جدید را کامل روی هاست آپلود کنید.</div>
<?php endif; ?>

  <!-- ---------- راهنمای راه‌اندازی ---------- -->
  <div class="card">
    <div class="card-head"><div>
      <div class="card-title">🧭 راهنمای راه‌اندازی — ۴ گام</div>
      <div class="card-sub">دقیقاً همین ترتیب را انجام دهید</div></div>
    </div>

    <div class="rel-steps">
      <div class="rel-step"><div class="n">۱</div><div class="b">
        <div class="t">ربات را در کانال ادمین کنید</div>
        <div class="d">در تلگرام وارد کانال شوید و از مسیر <b>Administrators ‹ Add Admin</b> ربات خود را اضافه کنید.
          دسترسی <b>Post Messages</b> الزامی است. اگر می‌خواهید پیام سنجاق هم بشود، <b>Pin Messages</b> را نیز روشن کنید.</div>
      </div></div>

      <div class="rel-step"><div class="n">۲</div><div class="b">
        <div class="t">شناسهٔ کانال را پیدا کنید</div>
        <div class="d">اگر کانال <b>عمومی</b> است، کافیست نام کاربری را با @ وارد کنید مانند <code>@mychannel</code>.
          اگر <b>خصوصی</b> است، یک پیام از کانال را به ربات <code>@userinfobot</code> فوروارد کنید تا شناسهٔ عددی را بدهد —
          شبیه <code>-1001234567890</code>. عدد را با همان علامت منفی در فیلد زیر بگذارید.</div>
      </div></div>

      <div class="rel-step"><div class="n">۳</div><div class="b">
        <div class="t">تاپیک مخصوص بسازید (اختیاری)</div>
        <div class="d">تاپیک در تلگرام فقط در <b>گروه‌های تا��یک‌دار</b> ممکن است و کانال‌های معمولی تاپیک ندارند.
          اگر می‌خواهید آپدیت‌ها در یک تاپیک جداگانه منتشر شوند، از یک گروه با حالت <b>Topics</b> استفاده کنید، ربات را ادمین کنید
          و دسترسی <b>Manage Topics</b> را بدهید؛ سپس دکمهٔ «ساخت تاپیک» را بزنید. در کانال معمولی این گام را رد کنید — پیام مستقیم منتشر می‌شود.</div>
      </div></div>

      <div class="rel-step"><div class="n">۴</div><div class="b">
        <div class="t">قالب را انتخاب کنید و تست بگیرید</div>
        <div class="d">ی��ی از ۴ قالب زیر را انتخاب کنید، ذخیره بزنید، سپس <b>پیام آزمایشی</b> را بزنید.
          اگر پیام در کانال دیده شد، کار تمام است — از این پس هر به‌روزرسانی خودبه‌خود اعلام می‌شود.</div>
      </div></div>
    </div>
  </div>

  <!-- ---------- تنطیمات اصلی ---------- -->
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="act" value="rel_save">

    <div class="card">
      <div class="card-head"><div>
        <div class="card-title">⚙️ پیکربندی کانال</div>
        <div class="card-sub">مقصد انتشار و رفتار پیام</div></div>
      </div>

      <label class="check"><input type="checkbox" name="rel_enabled" value="1" <?= $relEn ? 'checked' : '' ?>>
        <span>📣 انتشار لیست تغییرات در کانال فعال باشد</span></label>

      <div class="form-grid mt3">
        <div class="field" style="grid-column:1/-1"><label>شناسهٔ کانال یا گروه</label>
          <input class="mono" type="text" name="rel_chat_id" dir="ltr" value="<?= h($relCh) ?>" placeholder="@mychannel یا -1001234567890">
          <div class="hint">نام کاربری با @ یا شناسهٔ عددی. لینک <span class="mono">t.me/...</span> هم پذیرفته می‌شود و خودبه‌خود تبدیل می‌گردد.</div></div>

        <div class="field"><label>نام تاپیک</label>
          <input type="text" name="rel_topic_name" value="<?= h((string)$SET('rel_topic_name', '')) ?>" placeholder="🚀 آپدیت‌ها و تغییرات">
          <div class="hint">فقط در گروه‌های تاپیک‌دار کاربرد دارد</div></div>

        <div class="field"><label>رنگ آیکون تاپیک</label>
          <select name="rel_topic_color">
            <?php if ($relOk): foreach (Release::COLORS as $ck => $cv): ?>
              <option value="<?= h($ck) ?>" <?= (string)$SET('rel_topic_color', '9367192') === $ck ? 'selected' : '' ?>><?= h($cv) ?></option>
            <?php endforeach; endif; ?>
          </select></div>

        <div class="field"><label>شناسهٔ تاپیک</label>
          <input class="mono" type="text" name="rel_topic_id" dir="ltr" value="<?= h((string)$relTid) ?>">
          <div class="hint">با دکمهٔ «ساخت تاپیک» خودکار پر می‌شود. ۰ یعنی انتشار مستقیم در کانال.</div></div>

        <div class="field"><label>عنوان نمایشی در پیام</label>
          <input type="text" name="rel_title" value="<?= h((string)$SET('rel_title', '')) ?>" placeholder="<?= h(APP_BRAND) ?>">
          <div class="hint">خالی = نام برند پیش‌فرض</div></div>
      </div>

      <div class="section-title">رفتار انتشار</div>
      <div class="grid g3">
        <label class="check"><input type="checkbox" name="rel_auto" value="1" <?= $relAuto ? 'checked' : '' ?>>
          <span>🤖 انتشار خودکار پس از هر به‌روزرسانی</span></label>
        <label class="check"><input type="checkbox" name="rel_pin" value="1" <?= (string)$SET('rel_pin', '0') === '1' ? 'checked' : '' ?>>
          <span>📌 سنجاق کردن پیام در کانال</span></label>
        <label class="check"><input type="checkbox" name="rel_silent" value="1" <?= (string)$SET('rel_silent', '0') === '1' ? 'checked' : '' ?>>
          <span>🔕 ارسال بی‌صدا (بدون نوتیفیکیشن)</span></label>
      </div>

      <div class="form-grid mt3">
        <div class="field"><label>متن دکمهٔ زیر پیام</label>
          <input type="text" name="rel_btn_text" value="<?= h((string)$SET('rel_btn_text', '')) ?>" placeholder="🛒 ورود به ربات"></div>
        <div class="field"><label>لینک دکمه</label>
          <input class="mono" type="text" name="rel_btn_url" dir="ltr" value="<?= h((string)$SET('rel_btn_url', '')) ?>" placeholder="https://t.me/YourBot">
          <div class="hint">فقط https:// یا tg:// پذیرفته می‌شود. هر دو فیلد باید پر باشند تا دکمه نمایش داده شود.</div></div>
        <div class="field" style="grid-column:1/-1"><label>پانویس پیام</label>
          <input type="text" name="rel_footer" value="<?= h((string)$SET('rel_footer', '')) ?>" placeholder="💬 پشتیبانی: @support">
          <div class="hint">می‌توانید از تگ‌های تلگرام مانند <span class="mono">&lt;b&gt;</span> و <span class="mono">&lt;i&gt;</span> استفاده کنید.</div></div>
      </div>

      <div class="section-title">قالب پیام — ۴ طرح کاملاً متفاوت</div>
      <div class="rel-styles">
        <label class="rel-st">
          <input type="radio" name="rel_style" value="hero" <?= $relSty === 'hero' ? 'checked' : '' ?>>
          <div class="nm">🚀 بنر حرفه‌ای</div>
          <div class="ds">کادر تزئینی، نسخهٔ برجسته و تغییرات دسته‌بندی‌شده</div>
          <div class="mk">╭────────────╮
   🚀 SR-BOT
   نسخهٔ ۱.۲.۳
╰────────────╯
🎉 به‌روزرسانی جدید!
✨ قابلیت‌های جدید (۲)
  ┗ کانال انتشار آپدیت</div>
        </label>

        <label class="rel-st">
          <input type="radio" name="rel_style" value="card" <?= $relSty === 'card' ? 'checked' : '' ?>>
          <div class="nm">🗂 کارت اطلاعات</div>
          <div class="ds">قالب کلید-مقدار مرتب، شبیه گزارش‌های رسمی</div>
          <div class="mk">⬆️ به‌روزرسانی SR-BOT
――――――――――――
• نسخهٔ جدید: ۱.۲.۳
• تعداد تغییرات: ۷
――――――――――――
◾️ ✨ قابلیت‌های جدید
     • کانال انتشار</div>
        </label>

        <label class="rel-st">
          <input type="radio" name="rel_style" value="minimal" <?= $relSty === 'minimal' ? 'checked' : '' ?>>
          <div class="nm">✨ ساده و تمیز</div>
          <div class="ds">کوتاه و بی‌حاشیه، مناسب کانال‌های پرترافیک</div>
          <div class="mk">SR-BOT ۱.۲.۳ · ۷ تغییر

✨ کانال انتشار آپدیت
🐞 رفع اشکال قیمت
🎨 زیباسازی تنطیمات</div>
        </label>

        <label class="rel-st">
          <input type="radio" name="rel_style" value="dev" <?= $relSty === 'dev' ? 'checked' : '' ?>>
          <div class="nm">🛠 فنی / دولوپر</div>
          <div class="ds">تک‌عرض با برچسب نوع تغییر، شبیه CHANGELOG</div>
          <div class="mk">🛠 release · v1.2.3
CHANGELOG
=============
from : 1.2.2
to   : 1.2.3
items: 7
-------------
[feat ] add channel
[fix  ] price bug</div>
        </label>
      </div>

      <div class="sticky-acts">
        <button class="btn btn-primary">💾 ذخیرهٔ تنطیمات</button>
      </div>
    </div>
  </form>

  <!-- ---------- عملیات و بررسی ---------- -->
  <div class="card">
    <div class="card-head"><div>
      <div class="card-title">🧰 بررسی و راه‌اندازی</div>
      <div class="card-sub">اول تنطیمات را ذخیره کنید، بعد این دکمه‌ها را بزنید</div></div>
    </div>

    <div class="rel-acts">
      <form method="post" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="act" value="rel_probe">
        <button class="btn btn-sm">🔎 بررسی دسترسی ربات</button>
      </form>
      <form method="post" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="act" value="rel_topic">
        <button class="btn btn-sm">🧵 ساخت تاپیک</button>
      </form>
      <form method="post" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="act" value="rel_topic"><input type="hidden" name="force" value="1">
        <button class="btn btn-sm btn-ghost" data-confirm="تاپیک جدیدی ساخته و جایگزین می‌شود. مطمئنید؟">♻️ ساخت دوبارهٔ تاپیک</button>
      </form>
      <form method="post" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="act" value="rel_test">
        <button class="btn btn-sm btn-primary">🧪 ارسال پیام آزمایشی</button>
      </form>
    </div>

    <div class="alert a-info mt3">دکمهٔ «بررسی دسترسی» واقعاً از تلگرام می‌پرسد که ربات ادمین هست یا نه، نوع چت چیست، تاپیک پشتیبانی می‌شود یا خیر و اجازهٔ ارسال دارد یا نه — برای عیب‌یابی اول این را بزنید.</div>
  </div>

  <!-- ---------- نوشتن و انتشار دستی ---------- -->
  <form method="post">
    <?= csrf_field() ?>
    <div class="card">
      <div class="card-head"><div>
        <div class="card-title">✍️ نوشتن لیست تغییرات</div>
        <div class="card-sub">هر خط یک تغییر — نوع هر خط خودکار تشخیص داده می‌شود</div></div>
      </div>

      <div class="alert a-info">لازم نیست چیز خاصی بلد باشید — فقط فارسی بنویسید. مثلاً خطی که با «رفع» یا «باگ» شروع شود در دستهٔ 🐞 رفع اشکال قرار می‌گیرد، «افزودن» در ✨ قابلیت جدید، «زیباسازی» در 🎨 ظاهر و «امنیت» در 🛡 امنیت. می‌توانید با برچسب صریح هم شروع کنید: <span class="mono">fix:</span> یا <span class="mono">feat:</span></div>

      <div class="form-grid">
        <div class="field"><label>نسخهٔ قبل</label>
          <input class="mono" type="text" name="rel_from" dir="ltr" value="<?= h($relLastV) ?>" placeholder="1.2.2"></div>
        <div class="field"><label>نسخهٔ جدید</label>
          <input class="mono" type="text" name="rel_to" dir="ltr" value="<?= h(APP_VERSION) ?>"></div>
        <div class="field"><label>تعداد فایل (اختیاری)</label>
          <input class="mono" type="text" name="rel_files" dir="ltr" value="0"></div>
        <div class="field"><label>قالب این انتشار</label>
          <select name="rel_style_now">
            <?php if ($relOk): foreach (Release::STYLES as $sk => $sv): ?>
              <option value="<?= h($sk) ?>" <?= $relSty === $sk ? 'selected' : '' ?>><?= h((string)$sv[0]) ?></option>
            <?php endforeach; endif; ?>
          </select></div>
        <div class="field" style="grid-column:1/-1"><label>فهرست تغییرات (هر خط یک مورد)</label>
          <textarea name="rel_body" rows="8" placeholder="افزودن سربرگ کانال انتشار آپدیت&#10;رفع اشکال نمایش قیمت در پنل نماینده&#10;زیباسازی بخش تنطیمات&#10;امنیت: جلوگیری از نشت توکن"><?= h((string)$SET('rel_draft', '')) ?></textarea>
          <div class="hint">اگر خالی بماند، از changelog مخزن یا فایل <span class="mono">version.json</span> استفاده می‌شود.</div></div>
      </div>

      <div class="sticky-acts">
        <button class="btn btn-primary" name="act" value="rel_publish">📤 انتشار در کانال</button>
<button class="btn" name="act" value="rel_announce" data-confirm="بستهٔ فعلی CHANGELOG.md در تاپیک اعلام شود؟" title="بستهٔ فعلی CHANGELOG.md را دستی اعلام می‌کند (fixed79)">📣 اعلام بستهٔ فعلی<?= (class_exists('Release') && method_exists('Release', 'localBuild') && ($lbB = Release::localBuild())) ? ' (' . h((string)$lbB['id']) . ')' : '' ?></button>
        <button class="btn btn-ghost" name="act" value="rel_draft">📝 ذخیره به‌عنوان پیش‌نویس</button>
      </div>
    </div>
  </form>

  <!-- ---------- پیش‌نمایش ---------- -->
  <div class="card">
    <div class="card-head"><div>
      <div class="card-title">👁 پیش‌نمایش پیام</div>
      <div class="card-sub">بر اساس قالب و پیش‌نویس ذخیره‌شده</div></div>
    </div>

    <?php if ($relPrevSafe !== ''): ?>
      <div class="rel-tg">
        <div class="rel-tgh">
          <div class="av">📣</div>
          <div><div class="nm"><?= h($relOk ? Release::title() : APP_BRAND) ?></div>
            <div class="sb"><?= $relTid > 0 ? 'در تاپیک شمارهٔ ' . fa_num((string)$relTid) : 'انتشار مستقیم در کانال' ?></div></div>
        </div>
        <div class="rel-prev"><?= $relPrevSafe ?></div>
      </div>
    <?php else: ?>
      <div class="rel-empty">پیش‌نمایش در دسترس نیست.</div>
    <?php endif; ?>
  </div>

  <!-- ---------- تاریخچه ---------- -->
  <div class="card">
    <div class="card-head">
      <div><div class="card-title">🗂 تاریخچهٔ انتشار</div>
        <div class="card-sub"><?= fa_num((string)count($relHist)) ?> مورد ثبت شده</div></div>
      <?php if ($relHist): ?>
        <form method="post" style="display:inline">
          <?= csrf_field() ?><input type="hidden" name="act" value="rel_hist_clear">
          <button class="btn btn-sm btn-ghost" data-confirm="تاریخچه پاک شود؟">🧹 پاک‌سازی</button>
        </form>
      <?php endif; ?>
    </div>

    <?php if (!$relHist): ?>
      <div class="rel-empty">هنوز چیزی منتشر نشده است.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="rel-hist">
          <thead><tr>
            <th>تاریخ</th><th>از</th><th>به</th><th>تغییرات</th><th>فایل</th><th>قالب</th><th>نوع</th>
          </tr></thead>
          <tbody>
          <?php foreach (array_slice($relHist, 0, 20) as $hr): ?>
            <tr>
              <td><?= h(to_jalali((string)($hr['at'] ?? ''), true)) ?></td>
              <td class="ltr mono"><?= h((string)($hr['from'] ?? '—')) ?></td>
              <td class="ltr mono"><b><?= h((string)($hr['to'] ?? '—')) ?></b></td>
              <td><?= fa_num((string)(int)($hr['items'] ?? 0)) ?></td>
              <td><?= fa_num((string)(int)($hr['files'] ?? 0)) ?></td>
              <td><?php $hs = (string)($hr['style'] ?? ''); echo h(($relOk && isset(Release::STYLES[$hs])) ? (string)Release::STYLES[$hs][0] : $hs); ?></td>
              <td><?= !empty($hr['auto']) ? '<span class="badge b-green">خودکار</span>' : '<span class="badge b-gray">دستی</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>

<div class="tab-panel set-panel" data-tab-panel-group="set" data-tab-panel="bc">
  <div class="card">
    <div class="card-head">
      <div><div class="card-title">📣 پیام همگانی پیشرفته</div>
      <div class="card-sub">متن، عکس، ویدیو، فایل، گیف و ویس – همراه با دکمه و دسته‌بندی مخاطب</div></div>
      <span class="badge b-blue"><?= h(fa_num((int)($bcCount['all'] ?? 0))) ?> کاربر</span>
    </div>

    <form method="post" enctype="multipart/form-data" data-no-lock>
      <?= csrf_field() ?>

      <div class="section-title">۱) نوع محتوا</div>
      <div class="pick-row">
        <?php foreach (Broadcast::KINDS as $bk => $bv): ?>
          <label class="pick">
            <input type="radio" name="kind" value="<?= h($bk) ?>" <?= $bk === 'text' ? 'checked' : '' ?> data-bc-kind>
            <span><?= h((string)$bv['label']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="form-grid mt3" id="bcMediaBox" style="display:none">
        <div class="field"><label>آپلود فایل از کامپیوتر</label>
          <input type="file" name="media" accept="image/*,video/*,audio/*,.pdf,.zip,.rar,.txt">
          <div class="hint">فایل یک‌بار برای خودتان ارسال می‌شود تا شناسه بگیرد، بعد برای همه فرستاده می‌شود</div></div>
        <div class="field"><label>یا شناسهٔ فایل تلگرام (file_id)</label>
          <input class="mono" type="text" name="file_id" dir="ltr" placeholder="AgACAgQAAxkBA…">
          <div class="hint">اگر قبلاً شناسهٔ فایل دارید، آپلود لازم نیست</div></div>
      </div>

      <div class="section-title">۲) متن پیام</div>
      <div class="field">
        <textarea name="text" rows="6" placeholder="متن اطلاعیه…"></textarea>
        <div class="hint">پشتیبانی از HTML تلگرام: <span class="mono">&lt;b&gt; &lt;i&gt; &lt;u&gt; &lt;code&gt; &lt;a href&gt;</span> – برای محتوای رسانه‌ای این متن به عنوان کپشن استفاده می‌شود</div>
      </div>

      <div class="field"><label>دکمه‌های شیشه‌ای (اختیاری)</label>
        <div id="gbWrap"></div>
        <div class="gb-acts">
          <button type="button" class="btn btn-ghost" id="gbAdd">➕ دکمهٔ جدید (ردیف تازه)</button>
          <button type="button" class="btn btn-ghost" id="gbSide">↔️ دکمه کنار دکمهٔ قبلی</button>
          <button type="button" class="btn btn-ghost" id="gbClear">🗑 پاک کردن همه</button>
        </div>
        <div class="hint">عنوان و لینک هر دکمه را در کادر خودش بنویسید – نیازی به نوشتن <span class="mono">|</span> یا <span class="mono">||</span> نیست.</div>
        <div class="gb-prevbox"><div class="gb-prevt">👁 پیش‌نمایش دقیقاً مانند تلگرام</div><div id="gbPrev"></div></div>
        <textarea name="buttons" id="gbOut" rows="3" dir="ltr" style="display:none"></textarea>
        <details class="gb-adv">
          <summary>✍️ ویرایش دستی (حالت پیشرفته)</summary>
          <textarea class="mono" id="gbManual" rows="3" dir="ltr" placeholder="کانال ما|https://t.me/example"></textarea>
          <div class="hint">اگر این کادر پر شود، همین مقدار ارسال می‌شود و سازندهٔ بالا نادیده گرفته می‌شود.</div>
        </details>
      </div>

      <style>
      .gb-acts{display:flex;gap:7px;flex-wrap:wrap;margin:8px 0 4px}
      .gb-item{border:1px solid #e3e0da;border-radius:12px;padding:10px;margin-bottom:8px;background:#faf9f7}
      .gb-h{display:flex;align-items:center;gap:8px;margin-bottom:7px;font-size:12.5px}
      .gb-h .gb-x{margin-inline-start:auto;border:0;background:#fee2e2;color:#b91c1c;border-radius:8px;width:27px;height:27px;cursor:pointer}
      .gb-item input{width:100%;margin-bottom:6px}
      .gb-tag{font-size:10.5px;padding:2px 7px;border-radius:20px;background:#e0e7ff;color:#3730a3}
      .gb-tag.n{background:#ecfdf5;color:#065f46}
      .gb-prevbox{margin-top:9px;padding:10px;border:1px dashed #e3e0da;border-radius:12px;background:#1c2130}
      .gb-prevt{font-size:11px;color:#b7c1d4;margin-bottom:7px}
      .gb-prow{display:flex;gap:6px;margin-bottom:6px}
      .gb-btn{flex:1;text-align:center;padding:8px 6px;border-radius:9px;background:#2a3f6b;color:#dbe6ff;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .gb-btn.bad{background:#4c1d24;color:#fecaca}
      .gb-adv{margin-top:9px}
      .gb-adv summary{cursor:pointer;font-size:12px;color:#7d7a75}
      .gb-adv textarea{width:100%;margin-top:7px}
      </style>

      <script>
      (function () {
        var wrap = document.getElementById('gbWrap');
        if (!wrap) return;
        var out = document.getElementById('gbOut');
        var prev = document.getElementById('gbPrev');
        var manual = document.getElementById('gbManual');
        var items = [];

        function esc(v) {
          return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
          });
        }

        function okUrl(u) {
          return /^(https?:\/\/|tg:\/\/)/i.test(String(u || '').trim());
        }

        function draw() {
          wrap.innerHTML = items.map(function (it, idx) {
            return '<div class="gb-item">' +
              '<div class="gb-h"><b>دکمهٔ ' + (idx + 1) + '</b>' +
              (it.side && idx > 0
                ? '<span class="gb-tag">کنار دکمهٔ قبلی</span>'
                : '<span class="gb-tag n">ردیف جدید</span>') +
              '<button type="button" class="gb-x" data-del="' + idx + '">✕</button></div>' +
              '<input type="text" placeholder="عنوان دکمه – مثلاً: کانال ما" data-f="t" data-i="' + idx + '" value="' + esc(it.t) + '">' +
              '<input type="text" class="mono" dir="ltr" placeholder="https://t.me/example" data-f="u" data-i="' + idx + '" value="' + esc(it.u) + '">' +
              '</div>';
          }).join('');
          sync();
        }

        function sync() {
          var lines = [], cur = [], bad = 0;
          items.forEach(function (it) {
            var t = String(it.t).trim(), u = String(it.u).trim();
            if (!t && !u) return;
            if (!okUrl(u)) bad++;
            var cell = (t || 'دکمه') + '|' + u;
            if (it.side && cur.length) cur.push(cell);
            else { if (cur.length) lines.push(cur.join(' || ')); cur = [cell]; }
          });
          if (cur.length) lines.push(cur.join(' || '));

          var man = manual ? manual.value.trim() : '';
          out.value = man !== '' ? man : lines.join('\n');

          prev.innerHTML = lines.length
            ? lines.map(function (ln) {
                return '<div class="gb-prow">' + ln.split(' || ').map(function (c) {
                  var pp = c.split('|');
                  var t = (pp[0] || '').trim();
                  var u = pp.slice(1).join('|').trim();
                  return '<span class="gb-btn' + (okUrl(u) ? '' : ' bad') + '">' + esc(t || 'دکمه') + '</span>';
                }).join('') + '</div>';
              }).join('')
            : '<div style="font-size:11.5px;color:#7e8aa1">هنوز دکمه‌ای اضافه نشده است.</div>';

          if (bad > 0) {
            prev.innerHTML += '<div style="font-size:11.5px;color:#fca5a5;margin-top:5px">⚠️ ' + bad +
              ' دکمه لینک معتبر ندارد. لینک باید با https:// یا tg:// شروع شود.</div>';
          }
        }

        wrap.addEventListener('input', function (e) {
          var f = e.target.getAttribute && e.target.getAttribute('data-f');
          if (!f) return;
          var idx = parseInt(e.target.getAttribute('data-i'), 10);
          if (isNaN(idx) || !items[idx]) return;
          items[idx][f] = e.target.value;
          sync();
        });

        wrap.addEventListener('click', function (e) {
          var d = e.target.closest ? e.target.closest('[data-del]') : null;
          if (!d) return;
          items.splice(parseInt(d.getAttribute('data-del'), 10), 1);
          draw();
        });

        document.getElementById('gbAdd').addEventListener('click', function () {
          items.push({ t: '', u: '', side: false }); draw();
        });
        document.getElementById('gbSide').addEventListener('click', function () {
          items.push({ t: '', u: '', side: items.length > 0 }); draw();
        });
        document.getElementById('gbClear').addEventListener('click', function () {
          items = []; if (manual) manual.value = ''; draw();
        });
        if (manual) manual.addEventListener('input', sync);

        draw();
      })();
      </script>

      <div class="section-title">۳) مخاطبان</div>
      <div class="form-grid">
        <div class="field" style="grid-column:1/-1"><label>دستهٔ مخاطب</label>
          <select name="segment">
            <?php foreach (Broadcast::SEGMENTS as $sk2 => $sv2): ?>
              <option value="<?= h($sk2) ?>"><?= h((string)$sv2['label']) ?> – <?= h(fa_num((int)($bcCount[$sk2] ?? 0))) ?> نفر</option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="grid g2 mt3">
        <label class="check"><input type="checkbox" name="silent" value="1"><span>🔕 ارسال بی‌صدا</span></label>
        <label class="check"><input type="checkbox" name="pin" value="1"><span>📌 پیام در چت کاربر سنجاق شود</span></label>
      </div>

      <div class="alert a-warn mt3">ارسال به تعداد زیاد ممکن است چند دقیقه طول بکشد؛ صفحه را نبندید. ارسال دسته‌ای <span class="mono"><?= h(fa_num(Broadcast::batch())) ?></span> تایی با <span class="mono"><?= h(fa_num(Broadcast::sleepMs())) ?></span> ثانیه مکث انجام می‌شود.</div>

      <div class="sticky-acts" style="flex-wrap:wrap">
        <button class="btn btn-ghost" name="act" value="broadcast_test">🧪 ارسال آزمایشی برای خودم</button>
        <button class="btn btn-primary" name="act" value="broadcast" data-confirm="پیام برای همهٔ مخاطبان انتخاب‌شده ارسال شود؟">📤 ارسال همگانی</button>
      </div>
    </form>
  </div>

  <?php if ($bcRecent): ?>
  <div class="card">
    <div class="card-head"><div><div class="card-title">🕒 آخرین ارسال‌ها</div></div></div>
    <div class="table-wrap"><table class="responsive">
      <thead><tr><th>عنوان</th><th>نوع</th><th>دسته</th><th>موفق</th><th>ناموفق</th><th>تاریخ</th></tr></thead>
      <tbody>
      <?php foreach ($bcRecent as $b): ?>
        <tr>
          <td data-l="عنوان"><?= h(mb_substr((string)($b['title'] ?? ''), 0, 60)) ?></td>
          <td data-l="نوع"><?= h((string)(Broadcast::KINDS[(string)$b['kind']]['label'] ?? $b['kind'])) ?></td>
          <td data-l="دسته"><?= h((string)(Broadcast::SEGMENTS[(string)$b['segment']]['label'] ?? $b['segment'])) ?></td>
          <td data-l="موفق"><span class="badge b-green"><?= h(fa_num((int)$b['sent'])) ?></span></td>
          <td data-l="ناموفق"><?= (int)$b['failed'] > 0 ? '<span class="badge b-red">' . h(fa_num((int)$b['failed'])) . '</span>' : '<span class="muted">۰</span>' ?></td>
          <td data-l="تاریخ" class="muted"><?= h(to_jalali((string)$b['created_at'], true)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ==================== امنیت و تایید حساب ==================== -->
<?php if (can('settings.bot')): ?>
<div class="tab-panel set-panel" data-tab-panel-group="set" data-tab-panel="sec2">
  <!-- ============ آموزش کامل احراز با ایمیل و شماره تلفن ============ -->
  <style>
  /* ================= Security Education v1 ================= */
  .sedu{border-color:rgba(52,211,153,.34)!important}
  .sedu-hd{display:flex;align-items:center;gap:11px;margin-bottom:4px}
  .sedu-hd .ic{width:44px;height:44px;border-radius:14px;display:grid;place-items:center;font-size:22px;
    background:linear-gradient(135deg,#34D399,#0EA5E9);box-shadow:0 7px 20px rgba(52,211,153,.28)}
  .sedu-hd h3{margin:0;font-size:16px;font-weight:800}
  .sedu-hd .s{color:var(--muted);font-size:12.5px;margin-top:2px}
  .sedu-quick{display:grid;grid-template-columns:repeat(auto-fit,minmax(172px,1fr));gap:9px;margin:12px 0 4px}
  .sedu-q{background:var(--surface-2);border:1px solid var(--border-soft);border-radius:var(--r);padding:11px 12px}
  .sedu-q .qi{font-size:19px}
  .sedu-q .qt{font-weight:700;font-size:12.5px;margin-top:5px}
  .sedu-q .qd{color:var(--muted);font-size:11.5px;margin-top:3px;line-height:1.75}
  .sedu-d{background:var(--surface-2);border:1px solid var(--border-soft);border-radius:var(--r);margin-top:9px;overflow:hidden}
  .sedu-d>summary{cursor:pointer;padding:12px 13px;font-weight:700;font-size:13.5px;list-style:none;
    display:flex;align-items:center;gap:8px;user-select:none}
  .sedu-d>summary::-webkit-details-marker{display:none}
  .sedu-d>summary::after{content:"▾";margin-inline-start:auto;color:var(--muted);transition:.2s;font-size:15px}
  .sedu-d[open]>summary::after{transform:rotate(180deg)}
  .sedu-d[open]>summary{border-bottom:1px solid var(--border-soft);background:rgba(52,211,153,.07)}
  .sedu-b{padding:13px;font-size:13px;line-height:2.05;color:#c4cddd}
  .sedu-b b{color:#e7ecf5}
  .sedu-step{display:flex;gap:11px;margin-bottom:12px}
  .sedu-step:last-child{margin-bottom:0}
  .sedu-step .n{flex:0 0 auto;width:25px;height:25px;border-radius:8px;display:grid;place-items:center;
    font-weight:800;font-size:12.5px;background:rgba(52,211,153,.16);color:#34D399;border:1px solid rgba(52,211,153,.3)}
  .sedu-step .b{min-width:0}
  .sedu-step .t{font-weight:700;color:#e7ecf5;margin-bottom:2px}
  .sedu-tbl{width:100%;border-collapse:collapse;font-size:12.5px;margin:9px 0;display:table}
  .sedu-tbl th,.sedu-tbl td{border:1px solid var(--border-soft);padding:7px 9px;text-align:start;vertical-align:top}
  .sedu-tbl th{background:var(--surface-3);font-weight:700;font-size:12px;white-space:nowrap}
  .sedu-b code{background:rgba(255,255,255,.08);padding:2px 6px;border-radius:6px;font-size:11.5px;
    direction:ltr;display:inline-block;unicode-bidi:embed}
  .sedu-pre{background:var(--bg);border:1px solid var(--border-soft);border-radius:9px;padding:10px 11px;
    font-size:11.5px;direction:ltr;text-align:left;overflow-x:auto;white-space:pre;line-height:1.75;margin:8px 0}
  .sedu-note{border-inline-start:3px solid #34D399;background:rgba(52,211,153,.08);padding:9px 11px;border-radius:9px;margin:10px 0}
  .sedu-warn{border-inline-start:3px solid #FFA92E;background:rgba(255,169,46,.08);padding:9px 11px;border-radius:9px;margin:10px 0}
  .sedu-bad{border-inline-start:3px solid #FF6B6B;background:rgba(255,107,107,.08);padding:9px 11px;border-radius:9px;margin:10px 0}
  .sedu-map{display:grid;gap:7px;margin:9px 0}
  .sedu-map .r{display:grid;grid-template-columns:minmax(120px,32%) 1fr;gap:9px;background:var(--bg);
    border:1px solid var(--border-soft);border-radius:9px;padding:8px 10px;font-size:12.5px}
  .sedu-map .r .f{font-weight:700;color:#e7ecf5}
  @media (max-width:640px){.sedu-map .r{grid-template-columns:1fr;gap:2px}}
  </style>

  <div class="card sedu">
    <div class="sedu-hd">
      <div class="ic">📚</div>
      <div><h3>آموزش کامل — احراز هویت با ایمیل و شمارهٔ موبایل</h3>
        <div class="s">اگر تا حالا این کار را نکرده‌اید، دقیقاً از گام ۱ شروع کنید — همه‌چیز با مثال واقعی توضیح داده شده</div></div>
    </div>

    <div class="sedu-quick">
      <div class="sedu-q"><div class="qi">🔍</div><div class="qt">این بخش چه می‌کند؟</div>
        <div class="qd">قبل از خرید یا تست رایگان، یک کد برای کاربر می‌فرستد تا ثابت شود ایمیل/شماره مال خودش است.</div></div>
      <div class="sedu-q"><div class="qi">🎯</div><div class="qt">چرا مهم است؟</div>
        <div class="qd">جلوی مالتی‌اکانت، سوءاستفاده از تست رایگان و سفارش‌های جعلی را می‌گیرد.</div></div>
      <div class="sedu-q"><div class="qi">⚡</div><div class="qt">سریع‌ترین راه</div>
        <div class="qd">ایمیل با SMTP — رایگان است و در ۵ دقیقه راه می‌افتد. پیامک هزینه و پنل لازم دارد.</div></div>
      <div class="sedu-q"><div class="qi">🧪</div><div class="qt">قانون طلایی</div>
        <div class="qd">هر تغییری دادید ← ذخیره ← دکمهٔ تست. تا تست سبز نشده، احراز را اجباری نکنید.</div></div>
    </div>

    <!-- ========== گام ۰ ========== -->
    <details class="sedu-d" open>
      <summary>🧭 گام ۰ — اول این را بخوانید: ایمیل یا پیامک؟</summary>
      <div class="sedu-b">
        <table class="sedu-tbl">
          <thead><tr><th>&nbsp;</th><th>📧 ایمیل</th><th>📱 پیامک</th></tr></thead>
          <tbody>
            <tr><td><b>هزینه</b></td><td>رایگان</td><td>هر پیامک هزینه دارد + شارژ پنل</td></tr>
            <tr><td><b>سختی راه‌اندازی</b></td><td>کم — فقط ۵ فیلد SMTP</td><td>متوسط — نیاز به API کلید و الگو</td></tr>
            <tr><td><b>سرعت رسیدن</b></td><td>چند ثانیه (احتمال اسپم)</td><td>تقریباً فوری و مطمئن</td></tr>
            <tr><td><b>اعتماد کاربر</b></td><td>متوسط</td><td>بالا — مالکیت شماره قابل جعل نیست</td></tr>
            <tr><td><b>پیشنهاد ما</b></td><td>برای شروع و فروشگاه کوچک</td><td>وقتی مالتی‌اکانت جدی شد</td></tr>
          </tbody>
        </table>
        <div class="sedu-note">💡 <b>پیشنهاد عملی:</b> اول فقط <b>ایمیل</b> را راه بیندازید و حالت را روی «اختیاری» بگذارید. وقتی مطمئن شدید کدها می‌رسند�� به «اجباری» تغییر دهید. وگرنه اگر ارسال کار نکند، هیچ کاربری نمی‌تواند ثبت‌نام را تمام کند.</div>
        <div class="sedu-warn">⚠️ ترتیب صحیح کار: ۱) روش ارسال (ایمیل/پیامک) را تنطیم و تست کنید ← ۲) بعد در کارت بالایی روش‌های مجاز را تیک بزنید ← ۳) در آخر حالت را اجباری کنید.</div>
      </div>
    </details>

    <!-- ========== ایمیل ========== -->
    <details class="sedu-d">
      <summary>📧 راه‌اندازی ایمیل — گ��م‌به‌گام و کامل</summary>
      <div class="sedu-b">

        <div class="sedu-step"><div class="n">۱</div><div class="b">
          <div class="t">روش ارسال را انتخاب کنید</div>
          در کارت «📧 ارسال ایمیل» دو گزینه دارید:<br>
          • <b>تابع داخلی PHP (mail)</b> — هیچ تنطیمی لازم ندارد، اما در بیشتر هاست‌های ایرانی مسدود است یا به پوشهٔ Spam می‌رود. <b>فقط برای تست.</b><br>
          • <b>SMTP</b> — روش درست و پیشنهادی. ربات کلاینت SMTP داخلی دارد، پس <b>هیچ کتابخانهٔ جانبی لازم نیست</b>.
        </div></div>

        <div class="sedu-step"><div class="n">۲</div><div class="b">
          <div class="t">یک ایمیل فرستنده تهیه کنید</div>
          ساده‌ترین حالت: در cPanel هاست خودتان یک ایمیل مانند <code>no-reply@yourdomain.com</code> بسازید (بخش Email Accounts).
          گزینه‌های دیگر: Gmail، Zoho Mail (رایگان)، Brevo، SendGrid.
        </div></div>

        <div class="sedu-step"><div class="n">۳</div><div class="b">
          <div class="t">اطلاعات SMTP را پیدا کنید</div>
          از جدول زیر مقدادیر سرویس خود را بردارید:
          <table class="sedu-tbl">
            <thead><tr><th>سرویس</th><th>Host</th><th>Port</th><th>رمزنگاری</th><th>نام کاربری / رمز</th></tr></thead>
            <tbody>
              <tr><td><b>cPanel هاست خودتان</b></td><td><code>mail.yourdomain.com</code></td><td><code>587</code></td><td>STARTTLS</td><td>خود ایمیل و رمزش</td></tr>
              <tr><td><b>Gmail</b></td><td><code>smtp.gmail.com</code></td><td><code>587</code></td><td>STARTTLS</td><td>ایمیل جیمیل + <b>App Password</b></td></tr>
              <tr><td><b>Zoho Mail</b></td><td><code>smtp.zoho.com</code></td><td><code>465</code></td><td>SSL</td><td>ایمیل زوهو + App Password</td></tr>
              <tr><td><b>Brevo</b></td><td><code>smtp-relay.brevo.com</code></td><td><code>587</code></td><td>STARTTLS</td><td>لاگین SMTP پنل</td></tr>
              <tr><td><b>SendGrid</b></td><td><code>smtp.sendgrid.net</code></td><td><code>587</code></td><td>STARTTLS</td><td>کاربری ثابت <code>apikey</code> ، رمز = API Key</td></tr>
            </tbody>
          </table>
          <div class="sedu-warn">⚠️ قانون پورت: پورت <code>587</code> حتماً با <b>STARTTLS</b> و پورت <code>465</code> حتماً با <b>SSL</b>. اگر این‌دو را جابجا بگذارید، ارسال قطعاً شکست می‌خورد.</div>
        </div></div>

        <div class="sedu-step"><div class="n">۴</div><div class="b">
          <div class="t">اگر Gmail انتخاب کردید: رمز عبور برنامه بسازید</div>
          جیمیل رمز اصلی حساب را برای SMTP قبول نمی‌کند:<br>
          ۱) در حساب گوگل وارد <b>Security</b> شوید ← ۲) <b>2-Step Verification</b> را فعال کنید ← ۳) سپس <b>App passwords</b> ←
          ۴) یک رمز جدید بسازید ← ۵) ۱۶ حرف تحویلی را <b>بدون فاصله</b> در فیلد رمز SMTP بگذارید.
          <div class="sedu-note">💡 در جیمیل و زوهو، «ایمیل فرستنده» باید دقیقاً همان ایمیل نام کاربری باشد، وگرنه سرور با خطای «sender rejected» رد می‌کند.</div>
        </div></div>

        <div class="sedu-step"><div class="n">۵</div><div class="b">
          <div class="t">فیلدها را در همین صفحه پر کنید</div>
          <div class="sedu-map">
            <div class="r"><div class="f">روش ارسال</div><div>SMTP</div></div>
            <div class="r"><div class="f">ایمیل فرستنده</div><div>مثل <code>no-reply@yourdomain.com</code> — اگر خالی بماند، همان نام کاربری SMTP استفاده می‌شود</div></div>
            <div class="r"><div class="f">نام فرستنده</div><div>نامی که در اینباکس کاربر دیده می‌شود — مثل نام فروشگاه شما</div></div>
            <div class="r"><div class="f">سرور SMTP</div><div>فقط نام میزبان، بدون <code>https://</code> و بدون پورت</div></div>
            <div class="r"><div class="f">پورت</div><div><code>587</code> یا <code>465</code></div></div>
            <div class="r"><div class="f">رمزنگاری</div><div>STARTTLS برای ۵۸۷ · SSL برای ۴۶۵ · «بدون رمزنگاری» فقط برای سرور داخلی روی پورت ۲۵</div></div>
            <div class="r"><div class="f">نام کاربری</div><div>معمولاً خود ایمیل کامل</div></div>
            <div class="r"><div class="f">رمز عبور</div><div>اگر خالی رها کنید، <b>رمز قبلی حفط می‌شود</b> و پاک نمی‌گردد</div></div>
          </div>
        </div></div>

        <div class="sedu-step"><div class="n">۶</div><div class="b">
          <div class="t">ذخیره کنید و تست بگیرید</div>
          دکمهٔ ذخیره را بزنید، سپس در کارت همین بخش یک ایمیل وارد کنید و <b>تست ایمیل</b> را بزنید.
          اگر پیام سبز گرفتید ولی ایمیل نرسید، پوشهٔ <b>Spam</b> را چک کنید.
        </div></div>

      </div>
    </details>

    <!-- ========== پیامک ========== -->
    <details class="sedu-d">
      <summary>📱 راه‌اندازی پیامک — گام‌به‌گام و با مثال آماده</summary>
      <div class="sedu-b">

        <div class="sedu-note">این بخش به هیچ سرویس خاصی وابسته نیست — یک <b>فراخوان HTTP عمومی</b> است، پس با هر پنل پیامکی ایرانی یا خارجی کار می‌کند.</div>

        <div class="sedu-step"><div class="n">۱</div><div class="b">
          <div class="t">در یک پنل پیامکی ثبت‌نام کنید و شارژ کنید</div>
          مثلاً کاوه‌نگار، SMS.ir، ملی‌پیامک یا قاصدک. بعد از تأیید هویت، در بخش توسعه‌دهندگان پنل، <b>API Key</b> خود را بردارید.
        </div></div>

        <div class="sedu-step"><div class="n">۲</div><div class="b">
          <div class="t">اگر خط اشتراکی دارید، الگو (Pattern) ثبت کنید</div>
          در ایران ارسال متن آزاد فقط با <b>خط اختصاصی</b> ممکن است. با خط اشتراکی باید الگو ثبت کنید؛ متن الگوی پیشنهادی:
          <div class="sedu-pre">code %code% is your verification code</div>
          و در پنل، نام متغیر را معمولاً <code>CODE</code> یا <code>code</code> می‌گذارند. کد الگو یا templateId را یادداشت کنید.
        </div></div>

        <div class="sedu-step"><div class="n">۳</div><div class="b">
          <div class="t">جایگزین‌ها را بشناسید</div>
          هر جا در «آدرس API» یا «بدنهٔ درخواست» این عبارات را بنویسید، خودبه‌خود جایگزین می‌شوند:
          <table class="sedu-tbl">
            <thead><tr><th>عبارت</th><th>به چه تبدیل می‌شود</th><th>مورد استفاده</th></tr></thead>
            <tbody>
              <tr><td><code>{to}</code></td><td>شمارهٔ مقصد به شکل رقمی مانند <code>09123456789</code></td><td>همیشه لازم است</td></tr>
              <tr><td><code>{code}</code></td><td>فقط خود کد مانند <code>48210</code></td><td>برای ارسال الگویی / verify</td></tr>
              <tr><td><code>{text}</code></td><td>متن کامل فارسی پیامک</td><td>برای ارسال متن آزاد با خط اختصاصی</td></tr>
            </tbody>
          </table>
          <div class="sedu-note">💡 در آدرس URL مقادیر خودبه‌خود <b>urlencode</b> می‌شوند، پس متن فارسی در URL هم بدون مشکل کار می‌کند.</div>
        </div></div>

        <div class="sedu-step"><div class="n">۴</div><div class="b">
          <div class="t">نمونهٔ آماده — کافیست کلید خود را جایگزین کنید</div>

          <b>کاوه‌نگار — ارسال الگویی (پیشنهادی)</b><br>
          روش: <code>GET</code>
          <div class="sedu-pre">https://api.kavenegar.com/v1/YOUR_API_KEY/verify/lookup.json?receptor={to}&amp;token={code}&amp;template=YOUR_TEMPLATE</div>

          <b>کاوه‌نگار — متن آزاد (خط اختصاصی)</b><br>
          روش: <code>GET</code>
          <div class="sedu-pre">https://api.kavenegar.com/v1/YOUR_API_KEY/sms/send.json?receptor={to}&amp;sender=YOUR_LINE&amp;message={text}</div>

          <b>SMS.ir — ارسال الگویی</b><br>
          روش: <code>POST</code> · آدرس:
          <div class="sedu-pre">https://api.sms.ir/v1/send/verify</div>
          هدرها (هر خط یک مورد):
          <div class="sedu-pre">X-API-KEY: YOUR_API_KEY</div>
          بدنهٔ درخواست:
          <div class="sedu-pre">{"mobile":"{to}","templateId":123456,"parameters":[{"name":"CODE","value":"{code}"}]}</div>

          <b>ملی‌پیامک — ارسال ساده</b><br>
          روش: <code>GET</code>
          <div class="sedu-pre">https://console.melipayamak.com/api/send/simple/YOUR_API_KEY?from=YOUR_LINE&amp;to={to}&amp;text={text}</div>

          <b>قاصدک — ارسال متنی</b><br>
          روش: <code>GET</code>
          <div class="sedu-pre">https://api.ghasedak.me/v2/sms/send/simple?receptor={to}&amp;linenumber=YOUR_LINE&amp;message={text}&amp;apikey=YOUR_API_KEY</div>
        </div></div>

        <div class="sedu-step"><div class="n">۵</div><div class="b">
          <div class="t">فیلدها را پر کنید</div>
          <div class="sedu-map">
            <div class="r"><div class="f">سرویس پیامک</div><div>روی «فراخوانی HTTP» بگذارید (خاموش = پیامک ارسال نمی‌شود)</div></div>
            <div class="r"><div class="f">روش درخواست</div><div>اگر همهٔ مقادیر در لینک هستند ← GET · اگر سرویس بدنهٔ JSON می‌خواهد ← POST</div></div>
            <div class="r"><div class="f">آدرس API</div><div>یکی از نمونه‌های بالا را کامل کپی کنید و فقط کلید/خط خود را عوض کنید</div></div>
            <div class="r"><div class="f">هدرها</div><div>هر خط یک هدر، دقیقاً شکل <code>Name: Value</code> — خط بدون دونقطه نادیده گرفته می‌شود</div></div>
            <div class="r"><div class="f">بدنهٔ درخواست</div><div>فقط در حالت POST استفاده می‌شود و باید <b>JSON معتبر</b> باشد</div></div>
          </div>
          <div class="sedu-warn">⚠️ در حالت POST، درخواست همیشه به شکل <b>JSON</b> ارسال می‌شود. اگر سرویس شما فقط <span class="mono">form-urlencoded</span> قبول می‌کند، از حالت <b>GET</b> با پارامترهای داخل لینک استفاده کنید.</div>
          <div class="sedu-bad">❌ در بدنهٔ JSON از متنی که خودش دارای گی��مهٔ دوبل است استفاده نکنید. اگر JSON خراب شود، سیستم به یک بدنهٔ پیش‌فرض ساده برمی‌گردد و سرویس پیامک خطا می‌دهد.</div>
        </div></div>

        <div class="sedu-step"><div class="n">۶</div><div class="b">
          <div class="t">تست کنید — و مهم‌ترین نکته</div>
          شمارهٔ خودتان را در کارت پیامک وارد کنید و دکمهٔ تست را بزنید.
          <div class="sedu-warn">⚠️ پیام موفقیت فقط به معنای این است که سرویس پیامک کد موفق HTTP برگردانده — بعضی پنل‌ها در عین خطا (الگوی نامعتبر یا موجودی صفر) باز هم کد ۲۰۰ می‌دهند. <b>حتماً رسیدن پیامک روی گوشی را چک کنید</b>، نه فقط پیام سبز پنل.</div>
          اگر پیامک نرسید، در سربرگ <b>🗂 گزارش‌ها</b> یا فایل لاگ هاست، خط مربوط به <code>sms http</code> را ببینید — کد و جواب ��رویس همانجا ثبت می‌شود.
        </div></div>

      </div>
    </details>

    <!-- ========== تنطیم نهایی ========== -->
    <details class="sedu-d">
      <summary>🎛 تنطیم نهایی — چه زمانی احراز از کاربر خواسته شود؟</summary>
      <div class="sedu-b">
        بعد از اینکه تست موفق شد، به کارت «🛡 تایید حساب کاربری» برگردید و این‌ها را تنطیم کنید:
        <div class="sedu-map">
          <div class="r"><div class="f">حالت احراز</div><div>خاموش = هیچ‌وقت · اختیاری = کاربر می‌تواند رد کند · اجباری = بدون احراز ادامه نمی‌دهد</div></div>
          <div class="r"><div class="f">دامنهٔ اجبار</div><div>دقیقاً کدام مرحله قفل باشد — مثلاً فقط تست رایگان یا فقط خرید</div></div>
          <div class="r"><div class="f">روش‌های مجاز</div><div>فقط روش‌هایی را تیک بزنید که واقعاً تستشان سبز شده است</div></div>
          <div class="r"><div class="f">طول کد</div><div>۵ رقم تعادل خوبی است</div></div>
          <div class="r"><div class="f">اعتبار کد</div><div>۵ تا ۱۰ دقیقه — کمتر از ۲ دقیقه نگذارید، پیامک ممکن است دیر برسد</div></div>
          <div class="r"><div class="f">حد خطا و فاصلهٔ ارسال مجدد</div><div>۵ خطا و ۶۰ ثانیه مقدادیر مناسبی هستند و جلوی هدر رفتن شارژ پیامک را می‌گیرند</div></div>
        </div>
        <div class="sedu-note">💡 در کارت «🔒 محافطت‌های تکمیلی» گزینهٔ <b>جلوگیری از چندحسابی</b> وقتی معنا دارد که احراز فعال باشد — این دو را با هم روشن کنید.</div>
      </div>
    </details>

    <!-- ========== خطاها ========== -->
    <details class="sedu-d">
      <summary>🚨 خطاهای رایج و راه‌حل فوری</summary>
      <div class="sedu-b">
        <table class="sedu-tbl">
          <thead><tr><th>نشانه</th><th>علت محتمل</th><th>راه‌حل</th></tr></thead>
          <tbody>
            <tr><td>تست ایمیل ناموفق می‌شود</td><td>پورت یا رمزنگاری اشتباه</td><td>۵۸۷ را با STARTTLS و ۴۶۵ را با SSL امتحان کنید</td></tr>
            <tr><td>خطای مربوط به لاگین یا auth</td><td>رمز اشتباه یا رمز اصلی جیمیل</td><td>برای Gmail/Zoho حتماً App Password بسازید</td></tr>
            <tr><td>ایمیل می‌رود ولی به Spam</td><td>رکورد SPF/DKIM دامنه تنطیم نشده</td><td>در DNS دامنه SPF و DKIM را اضافه کنید؛ یا از ایمیل همان دامنه استفاده کنید</td></tr>
            <tr><td>هیچ ایمیلی ارسال نمی‌شود و خطایی هم نیست</td><td>روش روی تابع داخلی PHP مانده</td><td>روش را به SMTP تغییر دهید</td></tr>
            <tr><td>تست پیامک سبز است ولی پیامک نمی‌رسد</td><td>موجودی صفر، الگوی تأییدنشده یا خط غیرمجاز</td><td>جواب دقیق سرویس را در لاگ <code>sms http</code> ببینید</td></tr>
            <tr><td>پیامک فارسی نامفهوم می‌رسد</td><td>مشکل کدگذاری پنل</td><td>از ارسال الگویی با <code>{code}</code> استفاده کنید نه متن آزاد</td></tr>
            <tr><td>کاربر می‌گوید کد منقضی شده</td><td>اعتبار کد خیلی کوتاه است</td><td>اعتبار کد را روی ۵ تا ۱۰ دقیقه بگذارید</td></tr>
            <tr><td>هیچ کاربری نمی‌تواند ثبت‌نام کند</td><td>حالت اجباری شده ولی ارسال کار نمی‌کند</td><td>فوراً حالت را به «اختیاری» برگردانید تا مشکل حل شود</td></tr>
          </tbody>
        </table>
      </div>
    </details>

  </div>


  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="act" value="security">

    <div class="card">
      <div class="card-head">
        <div><div class="card-title">🛡 تایید حساب کاربری</div>
        <div class="card-sub">تایید هویت کاربر با ایمیل، شمارهٔ موبایل یا لینک یک‌بارمصرف</div></div>
        <?= Security::enabled() ? '<span class="badge b-green">فعال</span>' : '<span class="badge b-gray">غیرفعال</span>' ?>
      </div>

      <div class="form-grid">
        <div class="field"><label>حالت تایید</label>
          <select name="sec_verify_mode">
            <?php foreach (Security::MODES as $mk => $mv): ?>
              <option value="<?= h($mk) ?>" <?= Security::mode() === $mk ? 'selected' : '' ?>><?= h($mv) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>تایید برای چه بخشی اجباری باشد؟</label>
          <select name="sec_verify_required_for">
            <?php foreach (Security::SCOPES as $ck => $cv): ?>
              <option value="<?= h($ck) ?>" <?= Security::scope() === $ck ? 'selected' : '' ?>><?= h($cv) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>

      <div class="section-title">روش‌های تایید</div>
      <div class="grid g3">
        <?php foreach (Security::KINDS as $kk => $kv): ?>
          <label class="check"><input type="checkbox" name="sec_verify_<?= h($kk) ?>" value="1" <?= Security::on($kk) ? 'checked' : '' ?>>
            <span><?= h((string)$kv['icon']) ?> <?= h((string)$kv['label']) ?></span></label>
        <?php endforeach; ?>
      </div>

      <div class="section-title">پارامترهای کد</div>
      <div class="form-grid">
        <div class="field"><label>طول کد (۴ تا ۸)</label>
          <input class="mono" type="text" name="sec_code_length" value="<?= h((string)Security::codeLen()) ?>"></div>
        <div class="field"><label>اعتبار کد (دقیقه)</label>
          <input class="mono" type="text" name="sec_code_ttl" value="<?= h((string)Security::ttlMin()) ?>"></div>
        <div class="field"><label>حداکثر تلاش ناموفق</label>
          <input class="mono" type="text" name="sec_max_tries" value="<?= h((string)Security::maxTries()) ?>"></div>
        <div class="field"><label>فاصله ارسال مجدد (ثانیه)</label>
          <input class="mono" type="text" name="sec_resend_wait" value="<?= h((string)Security::resendWait()) ?>"></div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><div><div class="card-title">📧 ارسال ایمیل</div>
        <div class="card-sub">برای کد تایید و لینک فعال‌سازی</div></div></div>
      <div class="form-grid">
        <div class="field"><label>روش ارسال</label>
          <select name="sec_mail_driver">
            <option value="mail" <?= (string)$SET('sec_mail_driver', 'mail') === 'mail' ? 'selected' : '' ?>>تابع داخلی PHP</option>
            <option value="smtp" <?= (string)$SET('sec_mail_driver') === 'smtp' ? 'selected' : '' ?>>سرور SMTP</option>
          </select></div>
        <div class="field"><label>ایمیل فرستنده</label>
          <input class="mono" type="text" name="sec_mail_from" dir="ltr" value="<?= h((string)$SET('sec_mail_from')) ?>"></div>
        <div class="field"><label>نام فرستنده</label>
          <input type="text" name="sec_mail_from_name" value="<?= h((string)$SET('sec_mail_from_name')) ?>"></div>
        <div class="field"><label>آدرس سرور SMTP</label>
          <input class="mono" type="text" name="sec_smtp_host" dir="ltr" value="<?= h((string)$SET('sec_smtp_host')) ?>" placeholder="smtp.gmail.com"></div>
        <div class="field"><label>پورت</label>
          <input class="mono" type="text" name="sec_smtp_port" value="<?= h((string)$SET('sec_smtp_port', '587')) ?>"></div>
        <div class="field"><label>امنیت اتصال</label>
          <select name="sec_smtp_secure">
            <option value="tls" <?= (string)$SET('sec_smtp_secure', 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
            <option value="ssl" <?= (string)$SET('sec_smtp_secure') === 'ssl' ? 'selected' : '' ?>>SSL</option>
            <option value="none" <?= (string)$SET('sec_smtp_secure') === 'none' ? 'selected' : '' ?>>بدون رمزنگاری</option>
          </select></div>
        <div class="field"><label>نام کاربری SMTP</label>
          <input class="mono" type="text" name="sec_smtp_user" dir="ltr" value="<?= h((string)$SET('sec_smtp_user')) ?>"></div>
        <div class="field"><label>رمز SMTP</label>
          <input class="mono" type="password" name="sec_smtp_pass" dir="ltr" placeholder="<?= (string)$SET('sec_smtp_pass') !== '' ? '•••••• (برای تغییر وارد کنید)' : '' ?>">
          <div class="hint">خالی بگذارید تا رمز قبلی حفظ شود</div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><div><div class="card-title">📱 ارسال پیامک</div>
        <div class="card-sub">هر سرویس پیامکی که API وب دارد</div></div></div>
      <div class="alert a-info">در آدرس یا بدنهٔ درخواست می‌توانید از <span class="mono">{to}</span> ، <span class="mono">{code}</span> و <span class="mono">{text}</span> استفاده کنید.</div>
      <div class="form-grid">
        <div class="field"><label>وضعیت</label>
          <select name="sec_sms_driver">
            <option value="off" <?= (string)$SET('sec_sms_driver', 'off') === 'off' ? 'selected' : '' ?>>غیرفعال</option>
            <option value="http" <?= (string)$SET('sec_sms_driver') === 'http' ? 'selected' : '' ?>>API تحت وب</option>
          </select></div>
        <div class="field"><label>روش درخواست</label>
          <select name="sec_sms_method">
            <option value="GET" <?= (string)$SET('sec_sms_method', 'GET') === 'GET' ? 'selected' : '' ?>>GET</option>
            <option value="POST" <?= (string)$SET('sec_sms_method') === 'POST' ? 'selected' : '' ?>>POST</option>
          </select></div>
        <div class="field" style="grid-column:1/-1"><label>آدرس API پیامک</label>
          <input class="mono" type="text" name="sec_sms_url" dir="ltr" value="<?= h((string)$SET('sec_sms_url')) ?>" placeholder="https://sms.example.com/send?to={to}&text={text}"></div>
        <div class="field" style="grid-column:1/-1"><label>بدنهٔ JSON برای POST</label>
          <textarea class="mono" name="sec_sms_body" rows="3" dir="ltr" placeholder='{"receptor":"{to}","message":"{text}"}'><?= h((string)$SET('sec_sms_body')) ?></textarea></div>
        <div class="field" style="grid-column:1/-1"><label>هدرها (هر خط یکی)</label>
          <textarea class="mono" name="sec_sms_headers" rows="2" dir="ltr" placeholder="Authorization: Bearer xxxxx"><?= h((string)$SET('sec_sms_headers')) ?></textarea></div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><div><div class="card-title">🔒 محافظت‌های تکمیلی</div></div></div>
      <div class="grid g2">
        <label class="check"><input type="checkbox" name="sec_block_multi_account" value="1" <?= (int)$SET('sec_block_multi_account', 0) ? 'checked' : '' ?>>
          <span>جلوگیری از چندحسابی (یک ایمیل/شماره برای یک کاربر)</span></label>
        <label class="check"><input type="checkbox" name="sec_force_join_before_test" value="1" <?= (int)$SET('sec_force_join_before_test', 1) ? 'checked' : '' ?>>
          <span>اجبار عضویت در کانال پیش از اکانت تست</span></label>
        <label class="check"><input type="checkbox" name="sec_login_alert" value="1" <?= (int)$SET('sec_login_alert', 1) ? 'checked' : '' ?>>
          <span>هشدار تلگرامی هنگام تلاش مشکوک ورود به پنل</span></label>
      </div>
      <div class="form-grid mt3">
        <div class="field"><label>حداقل عمر حساب برای خرید (ساعت)</label>
          <input class="mono" type="text" name="sec_min_account_age" value="<?= h((string)$SET('sec_min_account_age', '0')) ?>">
          <div class="hint">۰ یعنی بدون محدودیت</div></div>
        <div class="field"><label>قفل پس از چند ورود ناموفق؟</label>
          <input class="mono" type="text" name="sec_login_max_fail" value="<?= h((string)$SET('sec_login_max_fail', '5')) ?>">
          <div class="hint">۰ = غیرفعال</div></div>
        <div class="field"><label>مدت قفل (دقیقه)</label>
          <input class="mono" type="text" name="sec_login_lock_min" value="<?= h((string)$SET('sec_login_lock_min', '15')) ?>"></div>
      </div>
      <div class="sticky-acts"><button class="btn btn-primary">💾 ذخیرهٔ تنظیمات امنیت</button></div>
    </div>
  </form>

  <div class="card">
    <div class="card-head"><div><div class="card-title">🧪 آزمایش ارسال</div></div></div>
    <div class="grid g2">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="sec_test_mail">
        <div class="field"><label>ایمیل مقصد</label>
          <input class="mono" type="text" name="test_mail" dir="ltr" placeholder="you@example.com"></div>
        <button class="btn">📧 ارسال ایمیل آزمایشی</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="sec_test_sms">
        <div class="field"><label>شمارهٔ مقصد</label>
          <input class="mono" type="text" name="test_sms" dir="ltr" placeholder="09120000000"></div>
        <button class="btn">📱 ارسال پیامک آزمایشی</button></form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ==================== مینی‌اپ ==================== -->
<?php if (can('settings.bot')): ?>
<div class="tab-panel set-panel" data-tab-panel-group="set" data-tab-panel="app">
  <div class="card">
    <div class="card-head">
      <div><div class="card-title">📱 مینی‌اپ تلگرام</div>
      <div class="card-sub">فروشگاه کامل درون تلگرام – خرید، سرویس‌ها، کیف پول و پشتیبانی</div></div>
      <?= (int)$SET('miniapp_enabled', 1) ? '<span class="badge b-green">فعال</span>' : '<span class="badge b-gray">غیرفعال</span>' ?>
    </div>

    <div class="alert a-info">آدرس مینی‌اپ: <span class="mono" dir="ltr"><?= h(app_url('miniapp/')) ?></span><br>
      این آدرس را در BotFather ، بخش <span class="mono">/newapp</span> ثبت کنید یا از دکمهٔ پایین برای تنظیم خودکار دکمهٔ منو استفاده کنید.</div>

    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="miniapp">
      <div class="grid g2">
        <label class="check"><input type="checkbox" name="miniapp_enabled" value="1" <?= (int)$SET('miniapp_enabled', 1) ? 'checked' : '' ?>><span>مینی‌اپ فعال باشد</span></label>
        <label class="check"><input type="checkbox" name="miniapp_show_menu" value="1" <?= (int)$SET('miniapp_show_menu', 1) ? 'checked' : '' ?>><span>دکمهٔ مینی‌اپ در منوی اصلی ربات نمایش داده شود</span></label>
        <label class="check"><input type="checkbox" name="miniapp_in_sections" value="1" <?= (string)$SET('miniapp_in_sections', '0') === '1' ? 'checked' : '' ?>><span>دکمهٔ اپل��کیشن داخل کیف پول و حساب کاربری هم باشد</span></label>
<div class="fieldset accent" style="margin-top:12px">
	<div class="section-title">🔗 لینک ساب اختصاصی و قالب اعداد</div>
	<div class="field">
		<label class="pick" for="sub_deliver">لینک اشتراکی که روی <b>همه سرویس ها</b> به مشتری تحویل داده می شود</label>
		<select name="sub_deliver" id="sub_deliver">
			<option value="local"<?= DB::setting('sub_deliver', 'local') !== 'panel' ? ' selected' : '' ?>>لینک ساب اختصاصی خود ربات (پیشنهادی)</option>
			<option value="panel"<?= DB::setting('sub_deliver', 'local') === 'panel' ? ' selected' : '' ?>>لینک ساب مستقیم پنل</option>
		</select>
		<div class="hint">این تنظیم روی <b>همه</b> سرویس ها اعمال می شود (ربات، مینی اپ و پنل مدیریت) و دیگر محدود به سرویس های گروهی نمایندگی نیست. اگر سرویسی شناسه ساب نداشته باشد، خودکار به گزینه دیگر برمی گردد.</div>
	</div>
	<div class="field">
		<label class="pick" for="sub_page_mode">وقتی کاربر لینک ساب اختصاصی را باز می‌کند</label>
		<select name="sub_page_mode" id="sub_page_mode">
			<option value="own"<?= DB::setting('sub_page_mode', 'own') !== 'main' ? ' selected' : '' ?>>صفحهٔ اختصاصی و سه‌بعدی خودمان نمایش داده شود (پیش‌فرض)</option>
			<option value="main"<?= DB::setting('sub_page_mode', 'own') === 'main' ? ' selected' : '' ?>>مستقیم به لینک ساب اصلی سرور منتقل شود</option>
		</select>
		<div class="hint">حالت انتقال فقط برای سرویس‌هایی کار می‌کند که فقط روی یک پنل ساخته شده‌اند؛ سرویس‌های چندپنلی همیشه صفحهٔ اختصاصی را می‌بینند.</div>
	</div>
	<label class="check"><input type="checkbox" name="sub_show_main" value="1"<?= DB::setting('sub_show_main', '1') === '1' ? ' checked' : '' ?>><span>نمایش کارت لینک ساب اصلی سرور داخل صفحهٔ اختصاصی (کنار کانفیگ‌ها)</span></label>
	<div class="field" style="margin-top:10px">
		<label class="pick" for="num_style">قالب اعداد در پیام‌های ربات</label>
		<select name="num_style" id="num_style">
			<option value="en"<?= DB::setting('num_style', 'en') !== 'fa' ? ' selected' : '' ?>>انگلیسی — 1,234,000</option>
			<option value="fa"<?= DB::setting('num_style', 'en') === 'fa' ? ' selected' : '' ?>>فارسی — ۱٬۲۳۴٬۰۰۰</option>
		</select>
		<div class="hint">پیش‌فرض روی انگلیسی است تا اعداد در همهٔ دستگاه‌ها درست دیده شوند.</div>
	</div>
</div>
      </div>
      <div class="hint">اگر گزینهٔ سوم خاموش باشد، منوی «شارژ کیف پول» و «حساب کاربری» تمیز می‌مانند و دکمهٔ اپلیکیشن فقط در منوی اصلی دیده می‌شود.</div>
      <div class="form-grid mt3">
        <div class="field"><label>عنوان مینی���اپ</label>
          <input type="text" name="miniapp_title" value="<?= h((string)$SET('miniapp_title', (string)$SET('shop_title', 'فروشگاه کانفیگ'))) ?>"></div>
        <div class="field"><label>متن دکمهٔ مینی‌اپ</label>
          <input type="text" name="miniapp_button" value="<?= h((string)$SET('miniapp_button', '🚀 اپلیکیشن')) ?>"></div>
        <div class="field"><label>رنگ اصلی</label>
          <input type="color" name="miniapp_accent" value="<?= h((string)$SET('miniapp_accent', '#3b82f6')) ?>" style="height:44px;padding:4px"></div>
      </div>
      <div class="sticky-acts"><button class="btn btn-primary">💾 ذخیرهٔ تنظیمات مینی‌اپ</button></div>
    </form>

    <div class="btn-row mt3">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="miniapp_menu">
        <button class="btn btn-green">🧩 تنظیم خودکار دکمهٔ منوی ربات</button></form>
      <a class="btn btn-ghost" href="<?= h(app_url('miniapp/')) ?>" target="_blank">👁 پیش‌نمایش مینی‌اپ</a>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ==================== امنیت و پشتیبان ==================== -->
<div class="tab-panel set-panel" data-tab-panel-group="set" data-tab-panel="txc">
  <?php if (!can('settings.payment')): ?>
    <?= denyBox('این بخش برای شما فعال نیست.') ?>
  <?php else: ?>

  <form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="txc">

    <div class="card-head">
      <div><div class="card-title">🔎 بررسی خودکار هش تراکنش</div>
        <div class="card-sub">وقتی کاربر هش (TXID) می‌فرستد، ربات خودش روی بلاک‌چین بررسی می‌کند؛ در صورت عدم تشخیص، به تایید دستی می‌رود.</div></div>
      <span class="badge <?= (string)$SET('txc_enabled', '1') === '1' ? 'b-green' : 'b-gray' ?>">
        <?= (string)$SET('txc_enabled', '1') === '1' ? 'فعال' : 'خاموش' ?></span>
    </div>

    <label class="check"><input type="checkbox" name="txc_enabled" value="1"
      <?= (string)$SET('txc_enabled', '1') === '1' ? 'checked' : '' ?>> بررسی خودکار هش فعال باشد</label>

    <label class="check"><input type="checkbox" name="txc_auto_approve" value="1"
      <?= (string)$SET('txc_auto_approve', '1') === '1' ? 'checked' : '' ?>>
      در صورت تایید موفق، کیف پول را خودکار شارژ کن</label>
    <div class="hint">اگر خاموش باشد، نتیجهٔ بررسی فقط به عنوان یادداشت برای مدیر ثبت می‌شود.</div>

    <div class="section-title">دقت و زمان‌بندی</div>
    <div class="form-grid g2">
      <div class="field"><label>خطای مجاز مبلغ (٪)</label>
        <input type="number" name="txc_tolerance" min="0" max="50" value="<?= (int)$SET('txc_tolerance', 3) ?>">
        <div class="hint">برای جبران نوسان نرخ ارز و کارمزد شبکه.</div></div>
      <div class="field"><label>مهلت پاسخ API (ثانیه)</label>
        <input type="number" name="txc_timeout" min="5" value="<?= (int)$SET('txc_timeout', 15) ?>"></div>
    </div>

    <div class="section-title">کلید API کاوشگرها</div>
    <div class="alert a-info">ترون (TRC20) و تون بدون کلید کار می‌کنند. برای BEP20 و ERC20 کلید لازم است.</div>
    <div class="form-grid g2">
      <div class="field"><label>BscScan API Key</label>
        <input type="text" class="mono" name="txc_bscscan_key" value="<?= h((string)$SET('txc_bscscan_key', '')) ?>" placeholder="bscscan.com/myapikey"></div>
      <div class="field"><label>Etherscan API Key</label>
        <input type="text" class="mono" name="txc_etherscan_key" value="<?= h((string)$SET('txc_etherscan_key', '')) ?>" placeholder="etherscan.io/myapikey"></div>
      <div class="field"><label>TonAPI Key (اختیاری)</label>
        <input type="text" class="mono" name="txc_tonapi_key" value="<?= h((string)$SET('txc_tonapi_key', '')) ?>" placeholder="tonapi.io"></div>
    </div>

    <div class="section-title">امنیت نصب و پنل مدیریت</div>
    <label class="check"><input type="checkbox" name="install_autodelete" value="1"
      <?= (string)$SET('install_autodelete', '1') === '1' ? 'checked' : '' ?>>
      پوشهٔ <code>install</code> بعد از پایان نصب خودکار حذف شود</label>
    <label class="check"><input type="checkbox" name="admin_miniapp" value="1"
      <?= (string)$SET('admin_miniapp', '1') === '1' ? 'checked' : '' ?>>
      دکمهٔ «باز کردن پنل داخل مینی‌اپ» در ربات نمایش داده شود</label>
    <div class="hint">دکمهٔ مینی‌اپ فقط وقتی کار می‌کند که آدرس سایت شما HTTPS باشد.</div>

    <div class="sticky-acts"><button class="btn">💾 ذخیره تنظیمات</button></div>
  </form>

  <form method="post" class="card mt3">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="txc_recheck">
    <div class="card-head"><div><div class="card-title">🔁 بررسی مجدد یک تراکنش</div>
      <div class="card-sub">شمارهٔ تراکنش (همان شمارهٔ پیگیری) را بدهید تا هشش دوباره روی شبکه بررسی شود.</div></div></div>
    <div class="form-grid g2">
      <div class="field"><label>شمارهٔ تراکنش</label>
        <input type="number" name="tx_id" placeholder="مثلاً 128"></div>
    </div>
    <div class="btn-row mt3"><button class="btn btn-ghost" data-no-lock>🔁 بررسی مجدد</button></div>
  </form>

  <div class="card mt3">
    <div class="card-head"><div><div class="card-title">🌐 شبکه‌های پشتیبانی‌شده</div></div></div>
    <?php if (class_exists('TxCheck')): ?>
      <div class="table-wrap"><table class="responsive">
        <thead><tr><th>شبکه</th><th>نیاز به کلید API</th><th>وضعیت</th></tr></thead>
        <tbody>
        <?php foreach (TxCheck::CHAINS as $ck => $cv):
            $needKey = !empty($cv['key']);
            $hasKey  = !$needKey
                || ($ck === 'BSC' && (string)$SET('txc_bscscan_key', '') !== '')
                || ($ck === 'ETH' && (string)$SET('txc_etherscan_key', '') !== '');
        ?>
          <tr>
            <td data-l="شبکه"><?= $cv['icon'] ?> <?= h((string)$cv['label']) ?></td>
            <td data-l="کلید API"><?= $needKey ? 'بله' : 'خیر' ?></td>
            <td data-l="وضعیت"><span class="badge <?= $hasKey ? 'b-green' : 'b-red' ?>">
              <?= $hasKey ? 'آماده' : 'کلید ثبت نشده' ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php else: ?>
      <div class="alert a-err">ماژول <code>TxCheck</code> پیدا نشد.</div>
    <?php endif; ?>
  </div>

  <?php endif; ?>
</div>

<div class="tab-panel set-panel" data-tab-panel-group="set" data-tab-panel="adv"><?php /* fixed79 */ ?>
  <?php if (!can('settings.bot')): ?>
    <?= denyBox('این بخش برای شما فعال نیست.') ?>
  <?php else: ?>
  <?php $on = static function (string $k, $d = '0') use ($SET): string { return (string)$SET($k, $d) === '1' ? 'checked' : ''; }; ?>

  <form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="adv">

    <div class="card-head">
      <div><div class="card-title">🧩 تنظیمات پیشرفته</div>
        <div class="card-sub">گزینه‌هایی که تا قبل فقط از داخل ربات (پنل مدیریت) قابل تغییر بودند؛ هر دو جا روی یک تنظیم کار می‌کنند.</div></div>
    </div>

    <div class="fieldset">
      <div class="lg">🧪 اکانت تست</div>
      <div class="fs-hint">حجم/مدت/سقف هر سرور در «پنل‌ها» تنظیم می‌شود (بر حسب گیگ یا مگابایت)؛ این‌جا قوانین عمومی ضد سوءاستفاده است. فعال/غیرفعال بودن کل تست در تب «ربات» است.</div>
      <div class="form-grid g2 mt3">
        <div class="field"><label>سقف کل تست هر کاربر (همهٔ سرورها)</label>
          <input class="mono" type="number" min="0" name="test_global_max" value="<?= (int)$SET('test_global_max', 0) ?>"><div class="hint">۰ = فقط سقف هر سرور اعمال شود</div></div>
        <div class="field"><label>فاصلهٔ بین دو تست (روز)</label>
          <input class="mono" type="number" min="0" name="test_cooldown_days" value="<?= (int)$SET('test_cooldown_days', 0) ?>"><div class="hint">۰ = بدون محدودیت</div></div>
        <div class="field"><label>حداقل عمر حساب کاربر در ربات (ساعت)</label>
          <input class="mono" type="number" min="0" name="test_min_age_hours" value="<?= (int)$SET('test_min_age_hours', 0) ?>"><div class="hint">جلوگیری از اکانت‌های تازه‌ساز</div></div>
      </div>
      <label class="check"><input type="checkbox" name="test_cooldown_per_panel" value="1" <?= $on('test_cooldown_per_panel') ?>><span>فاصلهٔ بین دو تست برای هر سرور جدا حساب شود (تست سرور A مانع تست سرور B نباشد)</span></label>
      <label class="check"><input type="checkbox" name="test_phone_unique" value="1" <?= $on('test_phone_unique', '1') ?>><span>هر شمارهٔ موبایل تاییدشده فقط یک تست</span></label>
      <div class="form-grid g2 mt3">
        <div class="field"><label>سقف اکانت تست برای هر IP</label>
          <input class="mono" type="number" min="0" name="test_ip_max" value="<?= (int)$SET('test_ip_max', 0) ?>"><div class="hint">۰ = خاموش · فقط برای مینی‌اپ و وب</div></div>
      </div>
      <label class="check"><input type="checkbox" name="test_device_unique" value="1" <?= $on('test_device_unique') ?>><span>هر دستگاه فقط یک اکانت تست</span></label>
      <label class="check"><input type="checkbox" name="test_panel_check" value="1" <?= $on('test_panel_check', '1') ?>><span>بررسی مستقیم پنل با آیدی تلگرام (کلاینت قدیمی حتی اگر از ربات پاک شده باشد)</span></label>
    </div>

    <div class="fieldset">
      <div class="lg">🔁 تمدید خودکار و هشدارها</div>
      <label class="check"><input type="checkbox" name="arn_enabled" value="1" <?= $on('arn_enabled') ?>><span>تمدید خودکار از کیف پول (کاربر باید روی سرویس فعال کند)</span></label>
      <div class="form-grid g2 mt3">
        <div class="field"><label>چند ساعت قبل از انقضا تمدید شود</label>
          <input class="mono" type="number" min="1" name="arn_hours" value="<?= (int)$SET('arn_hours', 24) ?>"></div>
        <div class="field"><label>تمدید در چند درصد مصرف حجم</label>
          <input class="mono" type="number" min="0" max="100" name="arn_traffic" value="<?= (int)$SET('arn_traffic', 95) ?>"><div class="hint">۰ = فقط بر اساس زمان</div></div>
        <div class="field"><label>هشدار دوم انقضا (روز قبل)</label>
          <input class="mono" type="number" min="0" name="expire_notify_days2" value="<?= (int)$SET('expire_notify_days2', 1) ?>"><div class="hint">هشدار اول در تب «ربات» تنظیم می‌شود؛ ۰ = خاموش</div></div>
        <div class="field"><label>هشدار مصرف حجم (درصد)</label>
          <input class="mono" type="number" min="1" max="100" name="traffic_notify_percent" value="<?= (int)$SET('traffic_notify_percent', 85) ?>"></div>
      </div>
    </div>

    <div class="fieldset">
      <div class="lg">🧹 حذف خودکار سرویس‌های تمام‌شده</div>
      <div class="fs-hint">سرویسی که منقضی/تمام شده و تمدید نشود، بعد از مهلت زیر از پنل و ربات پاک می‌شود.</div>
      <label class="check"><input type="checkbox" name="svc_autodel_on" value="1" <?= $on('svc_autodel_on', '1') ?>><span>فعال باشد</span></label>
      <div class="form-grid g2 mt3">
        <div class="field"><label>مهلت پس از اتمام (روز)</label>
          <input class="mono" type="number" min="1" max="60" name="svc_autodel_days" value="<?= (int)$SET('svc_autodel_days', 3) ?>"></div>
      </div>
      <label class="check"><input type="checkbox" name="svc_autodel_rs" value="1" <?= $on('svc_autodel_rs') ?>><span>سرویس‌های نمایندگان هم شامل شود</span></label>
      <label class="check"><input type="checkbox" name="svc_autodel_warn" value="1" <?= $on('svc_autodel_warn', '1') ?>><span>یک روز قبل از حذف به کاربر هشدار بده</span></label>
      <label class="check"><input type="checkbox" name="svc_autodel_notify" value="1" <?= $on('svc_autodel_notify', '1') ?>><span>بعد از حذف به کاربر اطلاع بده</span></label>
    </div>

    <div class="fieldset">
      <div class="lg">🛠 نگهداری و امنیت</div>
      <div class="form-grid g2 mt3">
        <div class="field"><label>نگهداری لاگ‌ها (روز)</label>
          <input class="mono" type="number" min="7" name="logs_keep_days" value="<?= (int)$SET('logs_keep_days', 90) ?>"><div class="hint">لاگ اقدامات و رویدادهای قدیمی‌تر پاک می‌شود (حداقل ۷)</div></div>
        <div class="field"><label>پایش نودها هر چند ساعت</label>
          <input class="mono" type="number" min="0" name="nodes_warn_h" value="<?= (int)$SET('nodes_warn_h', 1) ?>"><div class="hint">پاسارگارد/مرزبان با ادمین sudo؛ قطع نود در گروه گزارشات (تاپیک پنل‌ها) اعلام می‌شود؛ ۰ = خاموش</div></div>
      </div>
      <label class="check"><input type="checkbox" name="admin_2fa" value="1" <?= $on('admin_2fa') ?>><span>🔐 ورود دومرحله‌ای پنل وب — کد یک‌بارمصرف به تلگرام مدیر</span></label>
      <div class="hint">فقط برای مدیرانی که در «مدیران» آیدی تلگرام دارند اعمال می‌شود؛ بقیه بدون کد وارد می‌شوند. مدیر باید قبلاً ربات را استارت کرده باشد. <?= (int)($ADMIN['tg_id'] ?? 0) > 0 ? 'آیدی شما: <code class="ltr">' . (int)$ADMIN['tg_id'] . '</code>' : '<b>آیدی تلگرام حساب شما ثبت نیست.</b>' ?></div>
      <div class="hint mt2">🗄 بکاپ خودکار و ارسال به تلگرام: <a href="index.php?p=backup">صفحهٔ پشتیبان‌گیری</a> • 📣 اعلان آپدیت در تاپیک: <a href="index.php?p=settings&tab=rel">کانال انتشار آپدیت</a> • 📤 <a href="index.php?p=export">خروجی CSV</a></div>
    </div>

    <div class="mt3"><button class="btn btn-primary" type="submit">💾 ذخیرهٔ تنظیمات پیشرفته</button></div>
  </form>
  <?php endif; ?>
</div>

<form method="post" id="cusMigForm" style="display:none"><?= csrf_field() ?><input type="hidden" name="act" value="cus_migrate"></form><?php /* fixed79 */ ?>
<div class="tab-panel set-panel" data-tab-panel-group="set" data-tab-panel="sec">
  <div class="grid g2">
    <div class="card">
      <div class="card-head">
        <div><div class="card-title">🔐 رمز ع��ور شما</div>
        <div class="card-sub">تغییر رمز حساب <?= h((string)($ADMIN['name'] ?: $ADMIN['username'])) ?></div></div>
      </div>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="act" value="passwd">
        <div class="field"><label>رمز فعلی</label>
          <input type="password" name="cur" id="curPw" data-eye="#curPw" required autocomplete="current-password"></div>
        <div class="field"><label>رمز جدید (حداقل ۸ کاراکتر)</label>
          <input type="password" name="new" id="newPw" data-eye="#newPw" minlength="8" required autocomplete="new-password"></div>
        <button class="btn">🔑 تغییر رمز</button>
      </form>
      <?php if (can('admins.view')): ?>
        <div class="sep"></div>
        <div class="alert a-info">برای ساخت مدیر جدید و تعیین دسترسی تفکیکی، به بخش <a href="index.php?p=admins"><b>🛡️ مدیران پنل</b></a> بروید.</div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head">
        <div><div class="card-title">💾 پشتیبان‌گیری دیتابیس</div>
        <div class="card-sub">ساخت و دانلود فایل SQL</div></div>
      </div>
      <?php if (can('backup.run')): ?>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="act" value="backup">
          <button class="btn btn-green">📦 ساخت فایل پشتیبان SQL</button>
        </form>
      <?php else: ?>
        <div class="alert a-warn">دسترسی ساخت پشتیبان را ندارید.</div>
      <?php endif; ?>
      <?php if ($backups && can('backup.view')): ?>
        <div class="section-title">آخرین فایل‌های پشتیبان</div>
        <?php foreach ($backups as $b): ?>
          <div class="kv"><span class="k mono" style="font-size:12px"><?= h((string)$b) ?></span>
            <a class="btn btn-sm" href="index.php?p=settings&dl=<?= h(urlencode((string)$b)) ?>">دانلود</a></div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="set-links fade-up">
  <div class="sl-head">🧭 <b>تنظیمات مرتبط در صفحات دیگر</b></div>
  <div class="sl-grid">
    <?php foreach ([
      ['subs',       '🔗', 'اشتراک و تحویل',   'لینک ساب، حذف و تمدید توسط کاربر'],
      ['gateways',   '⚡️', 'درگاه‌های پرداخت', 'درگاه ایرانی و ارزی'],
      ['botbuttons', '🎛', 'دکمه‌های ربات',   'چیدمان منوها و دکمه‌ها'],
      ['bottexts',   '✍️', 'متن‌های ربات',    'ویرایش پیام‌های آماده'],
      ['admins',     '🛡️', 'مدیران پنل',       'سطح دسترسی تفکیکی'],
      ['tutorials',  '🎓', 'آموزش‌ها',         'راهنمای اتصال کاربران'],
      ['backup',     '💾', 'پش��یبان‌گیری',      'خروجی کامل دیتابیس'],
      ['update',     '⬆️', 'به‌روزرسانی',       'نصب نسخهٔ جدید'],
      ['health',     '🩺', 'سلامت سیستم',      'بررسی پنل‌ها و سرویس‌ها'],
    ] as $L): if (!$s_page($L[0])) continue; ?>
      <a class="sl" href="index.php?p=<?= h($L[0]) ?>">
        <span class="i"><?= $L[1] ?></span>
        <span class="t"><?= h($L[2]) ?></span>
        <span class="d"><?= h($L[3]) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

</div><!-- /.set-wrap -->

<script>
/* ------------------ جستجو و تعامل صفحهٔ تنظیمات ------------------ */
(function () {
  var wrap = document.getElementById('setWrap');
  if (!wrap) return;

  var inp   = document.getElementById('setSearch');
  var clr   = document.getElementById('setClr');
  var cnt   = document.getElementById('setCnt');
  var nav   = document.getElementById('setNav');
  var nores = document.getElementById('setNoRes');

  var panels = [].slice.call(wrap.querySelectorAll('.set-panel'));
  if (!panels.length) return;

  /* برچسب هر دسته روی پنل برای نمایش در حالت جستجو */
  if (nav) {
    [].slice.call(nav.querySelectorAll('[data-tab]')).forEach(function (b) {
      var k = b.getAttribute('data-tab');
      var t = b.querySelector('.t');
      panels.forEach(function (p) {
        if (p.getAttribute('data-tab-panel') === k) {
          p.setAttribute('data-label', (t ? t.textContent : k).trim());
        }
      });
    });
  }

  /* یکسان‌سازی متن فارسی/عربی و ارقام */
  function norm(v) {
    return String(v || '').toLowerCase()
      .replace(/\u064a/g, '\u06cc')
      .replace(/\u0643/g, '\u06a9')
      .replace(/[\u200c\u200e\u200f]/g, ' ')
      .replace(/[\u06f0-\u06f9]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 1728); })
      .replace(/[\u0660-\u0669]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 1584); })
      .replace(/\s+/g, ' ');
  }

  /* فهرست کارت‌ها */
  var cards = [];
  panels.forEach(function (p) {
    [].slice.call(p.children).forEach(function (c) {
      if (!c.classList) return;
      cards.push({ el: c, panel: p, txt: norm(c.textContent) });
    });
  });

  function reset() {
    wrap.classList.remove('searching');
    cards.forEach(function (c) { c.el.classList.remove('hit-off'); });
    panels.forEach(function (p) { p.classList.remove('hit-off'); });
    if (nores) nores.hidden = true;
    if (cnt) cnt.textContent = '';
    if (clr) clr.hidden = true;
  }

  function run() {
    var q = norm(inp.value).trim();
    if (clr) clr.hidden = q === '';

    if (q.length < 2) { reset(); return; }

    wrap.classList.add('searching');
    var hits = 0;
    cards.forEach(function (c) {
      var ok = c.txt.indexOf(q) > -1;
      c.el.classList.toggle('hit-off', !ok);
      if (ok) hits++;
    });
    panels.forEach(function (p) {
      var any = [].slice.call(p.children).some(function (c) {
        return c.classList && !c.classList.contains('hit-off');
      });
      p.classList.toggle('hit-off', !any);
    });

    if (cnt) cnt.textContent = hits ? (hits + ' مورد پیدا شد') : '';
    if (nores) nores.hidden = hits > 0;
  }

  if (inp) {
    var tmr = null;
    inp.addEventListener('input', function () {
      clearTimeout(tmr);
      tmr = setTimeout(run, 120);
    });
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { inp.value = ''; reset(); inp.blur(); }
    });
  }
  if (clr) {
    clr.addEventListener('click', function () { inp.value = ''; reset(); inp.focus(); });
  }

  /* کلید میانبر / برای جستجو */
  document.addEventListener('keydown', function (e) {
    if (e.key !== '/' || !inp) return;
    var t = e.target || {};
    var tag = (t.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || t.isContentEditable) return;
    e.preventDefault();
    inp.focus();
    inp.select();
  });

  /* نشانهٔ تغییر ذخیره‌نشده */
  [].slice.call(wrap.querySelectorAll('form')).forEach(function (f) {
    var mark = function () { f.classList.add('dirty'); };
    f.addEventListener('input', mark);
    f.addEventListener('change', mark);
    f.addEventListener('submit', function () { f.classList.remove('dirty'); });
  });

  /* بازکردن دستهٔ خواسته‌شده در آدرس و پرش به بالای پنل */
  function jump() {
    var m = /[?&]tab=([a-z0-9_-]+)/i.exec(location.search);
    if (m && window.vsTab) { try { window.vsTab('set', m[1]); } catch (e) {} }
  }
  setTimeout(jump, 40);

  if (nav) {
    nav.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-tab]') : null;
      if (!b) return;
      var p = wrap.querySelector('.set-panel[data-tab-panel="' + b.getAttribute('data-tab') + '"]');
      if (!p) return;
      setTimeout(function () {
        var y = p.getBoundingClientRect().top + window.pageYOffset - 74;
        window.scrollTo({ top: y > 0 ? y : 0, behavior: 'smooth' });
      }, 30);
    });
  }
})();
</script>
