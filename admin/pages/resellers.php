<?php
/** نمایندگی‌ها – سطح، تعرفه، سقف بدهی و کانفیگ‌های ساخته‌شده */

if (!can('resellers.view')) { echo denyBox('بخش نمایندگی برای شما فعال نیست.'); return; }

if (!class_exists('Reseller')) {
    echo '<div class="card"><div class="alert a-err">ماژول نمایندگی پیدا نشد. فایل <code>app/Service/Reseller.php</code> را بررسی کنید.</div></div>';
    return;
}

$act = (string)($_POST['act'] ?? '');

/* ---------------- عملیات ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {

    /* تعیین یا تغییر سطح نمایندگی */
    if ($act === 'setlevel') {
        need('resellers.level', 'resellers');

        $uid   = pint('user_id');
        $level = pint('level');
        if ($level < 0 || $level > 2) $level = 0;

        /* اگر شناسه نداشتیم، با شناسهٔ تلگرام یا نام کاربری جستجو کن */
        if (!$uid) {
            $qs  = ptxt('user');
            $dig = preg_replace('/\D/', '', en_num($qs)) ?? '';
            $row = null;
            if ($dig !== '') {
                $row = DB::one('SELECT * FROM {p}users WHERE tg_id = :t', [':t' => $dig])
                    ?: DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => (int)$dig]);
            }
            if (!$row && $qs !== '') {
                $row = DB::one('SELECT * FROM {p}users WHERE username = :u', [':u' => ltrim($qs, '@')]);
            }
            if (!$row) { flash('err', 'کاربری با این شناسه یا نام کاربری پیدا نشد.'); back('resellers'); }
            $uid = (int)$row['id'];
        }

        $opts = [];
        if (can('resellers.credit')) {
            if (isset($_POST['credit']))   $opts['credit']   = max(0, pint('credit'));
            if (isset($_POST['discount'])) $opts['discount'] = max(0, min(90, pint('discount')));
        }

        Reseller::setLevel($uid, $level, $opts);

        flash('ok', $level > 0
            ? '✅ سطح نمایندگی کاربر روی «' . h(Reseller::levelLabel($level)) . '» تنظیم شد.'
            : '✅ نمایندگی این کاربر لغو شد.');
        back('resellers', $level > 0 ? ['u' => $uid] : []);
    }

    /* تعرفهٔ عمومی نمایندگی */
    if ($act === 'rsreq_ok') {
        need('resellers.level', 'resellers');
        $ruid = pint('user_id');
        $lv   = max(1, min(2, pint('level')));
        $ru   = $ruid ? DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => $ruid]) : null;
        if (!$ru) { flash('err', 'کاربر پیدا نشد.'); back('resellers', ['tab' => 'req']); }

        $r = Reseller::approveRequest($ruid, $lv);
        if (!empty($r['ok'])) {
            Tg::send((int)$ru['tg_id'], "🎉 <b>درخواست نمایندگی شما تایید شد</b>\n\n🏷 سطح شما: "
                . h(Reseller::levelLabel($lv)) . "\nاز منوی «🏷 نمایندگی» پنل خود را باز کنید و کانفیگ بسازید.");
        }
        flash(!empty($r['ok']) ? 'ok' : 'err', (string)($r['message'] ?? '-'));
        back('resellers', ['tab' => 'req']);
    }

    if ($act === 'rsreq_no') {
        need('resellers.level', 'resellers');
        $ruid = pint('user_id');
        $ru   = $ruid ? DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => $ruid]) : null;
        Reseller::clearRequest($ruid);
        if ($ru) {
            Tg::send((int)$ru['tg_id'], "❌ <b>درخواست نمایندگی شما پذیرفته نشد</b>\n\nبرای اطلاعات بیشتر با پشتیبانی در تماس باشید.");
        }
        flash('ok', 'درخواست رد شد.');
        back('resellers', ['tab' => 'req']);
    }

    /* تنظیمات درخواست و هزینهٔ نمایندگی */
    /* مدیریت درخواست‌های ربات اختصاصی نماینده */
    if ($act === 'rsbot_ok' || $act === 'rsbot_no') {
        need('resellers.price', 'resellers');

        $txId = pint('tx');
        $tx = DB::one("SELECT * FROM {p}transactions WHERE id = :i AND method = 'rsbot' LIMIT 1", [':i' => $txId]);
        if (!$tx) { flash('err', '⚠️ درخواست پیدا نشد.'); back('resellers', ['tab' => 'price']); }
        if ((string)$tx['status'] !== 'pending') {
            flash('err', 'این درخواست قبلاً بررسی شده است.');
            back('resellers', ['tab' => 'price']);
        }

        $uid  = (int)$tx['user_id'];
        $amt  = (int)$tx['amount'];
        $tgId = (int)DB::val('SELECT tg_id FROM {p}users WHERE id = :i', [':i' => $uid], 0);
        $note = ptxt('msg', 500);

        if ($act === 'rsbot_ok') {
            DB::update('transactions', ['status' => 'approved', 'decided_at' => now()], 'id = :i', [':i' => $txId]);
            if ($tgId > 0 && class_exists('Tg')) {
                Tg::send($tgId, '🤖 <b>ربات اختصاصی شما آماده شد</b>' . chr(10)
                    . 'درخواست شما بررسی و تحویل شد.'
                    . ($note !== '' ? chr(10) . chr(10) . $note : ''));
            }
            if (class_exists('Logs')) {
                Logs::send('financial', Logs::fmt('🤖 تحویل ربات اختصاصی', [
                    'کاربر' => (string)$tgId,
                    'مبلغ'  => money($amt) . ' ' . currency(),
                ]));
            }
            flash('ok', '✅ درخواست به‌عنوان تحویل‌شده ثبت شد.');
        } else {
            if ($amt > 0 && class_exists('Wallet')) {
                Wallet::credit($uid, $amt, 'refund', 'rsbot', 'عودت هزینه ربات اختصاصی');
            }
            DB::update('transactions', ['status' => 'rejected', 'decided_at' => now()], 'id = :i', [':i' => $txId]);
            if ($tgId > 0 && class_exists('Tg')) {
                Tg::send($tgId, '↩️ <b>درخواست ربات اختصاصی رد شد</b>' . chr(10)
                    . 'مبلغ ' . money($amt) . ' ' . currency() . ' به کیف پول شما برگشت داده شد.'
                    . ($note !== '' ? chr(10) . chr(10) . $note : ''));
            }
            flash('ok', '↩️ درخواست رد و مبلغ عودت داده شد.');
        }
        back('resellers', ['tab' => 'price']);
    }

    if ($act === 'rsfee') {
        need('resellers.price', 'resellers');

        DB::setSetting('rs_requests_open',  (string)pchk('rs_requests_open'));
        DB::setSetting('rs_req_fee',        (string)max(0, pint('rs_req_fee')));
        DB::setSetting('rs_req_fee_credit', (string)pchk('rs_req_fee_credit'));
        DB::setSetting('rs_req_fee_target', ptxt('rs_req_fee_target') === 'credit' ? 'credit' : 'balance');
        DB::setSetting('rs_req_auto',       (string)pchk('rs_req_auto'));
        DB::setSetting('rs_req_level',      (string)max(1, min(2, pint('rs_req_level', 1))));
        DB::setSetting('rs_bot_enabled',    (string)pchk('rs_bot_enabled'));
        DB::setSetting('rs_bot_price',      (string)max(0, pint('rs_bot_price')));
        DB::setSetting('rs_bot_note',       ptxt('rs_bot_note', 600));
        DB::setSetting('rs_bot_auto',       (string)pchk('rs_bot_auto'));
        DB::setSetting('rs_bot_max',        (string)max(1, min(5, pint('rs_bot_max', 1))));
        DB::setSetting('rs_del_refund',     (string)pchk('rs_del_refund'));
        DB::setSetting('rs_del_fee_pct',    (string)max(0, min(100, pint('rs_del_fee_pct'))));
        DB::setSetting('rs_shrink_refund',  (string)pchk('rs_shrink_refund'));
        $dmRaw = ptxt('rs_del_mode');
        DB::setSetting('rs_del_mode', in_array($dmRaw, ['fair', 'split', 'gb'], true) ? $dmRaw : 'fair');
        DB::setSetting('trash_days',  (string)max(1, min(90, pint('trash_days', 7))));

        flash('ok', '💾 تنظیمات درخواست نمایندگی ذخیره شد.');
        back('resellers', ['tab' => 'price']);
    }
    if ($act === 'trash_purge') {
        need('resellers.price', 'resellers');
        $one = pint('id');
        $k   = $one > 0 ? Svc::purgeNow([$one]) : Svc::purgeTrash(0);
        flash('ok', '🧹 ' . en_num((string)$k) . ' مورد از سطل زباله کامل پاک شد.');
        back('resellers', ['tab' => 'price']);
    }


    /* امکانات، دسترسی‌ها و سقف‌های پنل نمایندگان */
    if ($act === 'rsopt') {
        need('resellers.price', 'resellers');

        foreach (['rs_wallet_only', 'rs_auto_suspend', 'rs_force_verify', 'rs_allow_test',
                  'rs_allow_rename', 'rs_allow_delete', 'rs_allow_renew', 'rs_allow_edit',
                  'rs_show_price', 'rs_hide_panel'] as $k) {
            DB::setSetting($k, (string)pchk($k));
        }
        foreach (['rs_service_cap', 'rs_daily_limit', 'rs_month_limit', 'rs_min_charge'] as $k) {
            DB::setSetting($k, (string)max(0, pint($k)));
        }

        DB::setSetting('rs_sub_domain', rtrim(ptxt('rs_sub_domain', 160), '/'));
        DB::setSetting('rs_support_id', ltrim(ptxt('rs_support_id', 60), '@'));
        DB::setSetting('rs_welcome',    ptxt('rs_welcome', 800));
        DB::setSetting('rs_tos',        ptxt('rs_tos', 3000));

        flash('ok', '💾 امکانات پنل نمایندگان ذخیره شد.');
        back('resellers', ['tab' => 'opt']);
    }

    /* تعرفهٔ اختصاصی هر سرور (قیمت جدا برای ترکیه، آلمان، مولتی‌لوکیشن و گیره) */
    if ($act === 'rspanel') {
        need('resellers.price', 'resellers');

        $raw  = (array)($_POST['pt'] ?? []);
        $rows = [];

        foreach ($raw as $rid => $r) {
            $rid = (int)$rid;
            if ($rid <= 0 || !is_array($r)) continue;

            $lbl = trim(strip_tags((string)($r['label'] ?? '')));
            $nte = trim(strip_tags((string)($r['note'] ?? '')));

            $rows[$rid] = [
                'panel_id' => $rid,
                'on'       => !empty($r['on']),
                'gb'       => max(0, (int)($r['gb'] ?? 0)),
                'day'      => max(0, (int)($r['day'] ?? 0)),
                'fee'      => max(0, (int)($r['fee'] ?? 0)),
                'mul'      => max(10, min(500, (int)($r['mul'] ?? 100))),
                'off'      => max(0, min(90,  (int)($r['off'] ?? 0))),
                'min_gb'   => max(0, (int)($r['min_gb'] ?? 0)),
                'max_gb'   => max(0, (int)($r['max_gb'] ?? 0)),
                'min_days' => max(0, (int)($r['min_days'] ?? 0)),
                'max_days' => max(0, (int)($r['max_days'] ?? 0)),
                'lv'       => max(0, min(2, (int)($r['lv'] ?? 0))),
                'label'    => mb_substr($lbl, 0, 40),
                'note'     => mb_substr($nte, 0, 160),
            ];
        }

        $nSaved = 0;
        if (class_exists('Reseller') && method_exists('Reseller', 'savePanelTariffs')) {
            $nSaved = (int)Reseller::savePanelTariffs($rows);
        }

        flash('ok', $nSaved > 0
            ? '🌍 قیمت‌گذاری ' . fa_num($nSaved) . ' سرور ذخیره شد.'
            : '🌍 همهٔ سرورها روی تعرفهٔ عمومی تنظیم شدند.');
        back('resellers', ['tab' => 'price']);
    }

    if ($act === 'tariff') {
        need('resellers.price', 'resellers');

        DB::setSetting('rs_enabled', pchk('rs_enabled'));
        foreach (['rs_price_gb', 'rs_price_day', 'rs_base_fee', 'rs_round',
                  'rs_min_gb', 'rs_max_gb', 'rs_min_days', 'rs_max_days',
                  'rs_ip_limit', 'rs_l1_discount', 'rs_l2_discount', 'rs_l2_credit'] as $k) {
            DB::setSetting($k, max(0, pint($k)));
        }
        DB::setSetting('rs_note', ptxt('rs_note'));

        $ids = array_values(array_filter(array_map('intval', (array)($_POST['panels'] ?? []))));
        DB::setSetting('rs_panels', implode(',', $ids));

        flash('ok', '✅ تعرفه و تنظیمات نمایندگی ذخیره شد.');
        back('resellers', ['tab' => 'price']);
    }

    /* تعرفهٔ اختصاصی یک نماینده */
    if ($act === 'utariff') {
        need('resellers.level', 'resellers');
        $uid2 = pint('uid');
        $u2   = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => $uid2]);
        if (!$u2) {
            flash('err', '⛔️ کاربر پیدا نشد.');
            back('resellers');
        }
        $rt = ['ok' => false, 'message' => '⛔️ این قابلیت در دسترس نیست.'];
        if (method_exists('Reseller', 'setTariff')) {
            $rt = Reseller::setTariff($uid2, [
                'price_gb'  => pint('price_gb'),
                'price_day' => pint('price_day'),
                'max_gb'    => pint('max_gb'),
                'max_days'  => pint('max_days'),
                'panels'    => ptxt('panels'),
                'note'      => ptxt('note'),
            ]);
        }
        $okT  = is_array($rt) ? !empty($rt['ok']) : (bool)$rt;
        $msgT = is_array($rt) ? (string)($rt['message'] ?? '') : '';
        if ($msgT === '') $msgT = $okT ? '✅ تعرفهٔ اختصاصی ذخیره شد.' : '⛔️ ذخیره نشد.';
        flash($okT ? 'ok' : 'err', h($msgT));
        back('resellers', ['u' => $uid2]);
    }

    /* مارک اختصاصی نماینده (پیشوند نام کانفیگ) */
    if ($act === 'umark') {
        need('resellers.level', 'resellers');
        $uidM = pint('uid');
        $mk   = (string)($_POST['mark'] ?? '');
        $mk   = strtolower((string)preg_replace('/[^a-zA-Z0-9]/', '', en_num(trim($mk))));
        $mk   = substr($mk, 0, 10);
        try {
            DB::update('users', ['reseller_mark' => ($mk !== '' ? $mk : null)], 'id = :id', [':id' => $uidM]);
            flash('ok', $mk !== ''
                ? '✅ مارک نماینده روی «' . h($mk) . '» تنظیم شد.'
                : '✅ مارک نماینده حذف شد.');
        } catch (Throwable $e) {
            flash('err', '⛔️ ستون مارک وجود ندارد. ابتدا از صفحهٔ به‌روزرسانی «🧩 بررسی و تکمیل ساختار دیتابیس» را اجرا کنید.');
        }
        back('resellers', ['u' => $uidM]);
    }

    /* ---------- طرح‌های آمادهٔ نمایندگی ---------- */
    if ($act === 'planadd') {
        need('resellers.level', 'resellers');
        $rows = Reseller::plans();
        $pid  = trim((string)($_POST['plan_id'] ?? ''));
        $ttl  = trim((string)($_POST['title'] ?? ''));

        if ($ttl === '') {
            flash('err', '⛔️ عنوان طرح الزامی است.');
            back('resellers', ['tab' => 'plans']);
        }

        $new = [
            'id'         => $pid,
            'title'      => $ttl,
            'gb'         => pint('gb'),
            'days'       => pint('days'),
            'price'      => pint('price'),
            'device'     => pint('device'),
            'speed_up'   => pint('speed_up'),
            'speed_down' => pint('speed_down'),
            'ip_limit'   => pint('ip_limit'),
            'panel_id'   => pint('panel_id'),
            'level'      => pint('level'),
            'sort'       => pint('sort'),
            'active'     => pchk('active') ? 1 : 0,
            'note'       => trim((string)($_POST['note'] ?? '')),
        ];

        $found = false;
        if ($pid !== '') {
            foreach ($rows as $i => $r) {
                if ((string)($r['id'] ?? '') === $pid) { $rows[$i] = $new; $found = true; break; }
            }
        }
        if (!$found) $rows[] = $new;

        Reseller::savePlans($rows);
        flash('ok', $found ? '✅ طرح به‌روزرسانی شد.' : '✅ طرح آماده افزوده شد.');
        back('resellers', ['tab' => 'plans']);
    }

    if ($act === 'plandel') {
        need('resellers.level', 'resellers');
        $pid  = trim((string)($_POST['plan_id'] ?? ''));
        $rows = [];
        foreach (Reseller::plans() as $r) {
            if ((string)($r['id'] ?? '') !== $pid) $rows[] = $r;
        }
        Reseller::savePlans($rows);
        flash('ok', '🗑 طرح حذف شد.');
        back('resellers', ['tab' => 'plans']);
    }

    if ($act === 'plantoggle') {
        need('resellers.level', 'resellers');
        $pid  = trim((string)($_POST['plan_id'] ?? ''));
        $rows = Reseller::plans();
        foreach ($rows as $i => $r) {
            if ((string)($r['id'] ?? '') === $pid) $rows[$i]['active'] = empty($r['active']) ? 1 : 0;
        }
        Reseller::savePlans($rows);
        flash('ok', '✅ وضعیت طرح تغییر کرد.');
        back('resellers', ['tab' => 'plans']);
    }

    /* ---------- سرویس‌های نمایندگی (چند پنل / چند اینباند) ---------- */
    if ($act === 'svcadd') {
        need('resellers.level', 'resellers');

        $rows = Reseller::bundles();
        $sid  = trim((string)($_POST['bundle_id'] ?? ''));
        $ttl  = trim((string)($_POST['title'] ?? ''));

        if ($ttl === '') {
            flash('err', '⛔️ نام سرویس الزامی است.');
            back('resellers', ['tab' => 'svc']);
        }

        $use   = (array)($_POST['use'] ?? []);
        $ibArr = (array)($_POST['ib'] ?? []);
        $ibTxt = (array)($_POST['ibtxt'] ?? []);

        $items = [];
        foreach ($use as $rawPid => $on) {
            $pid = (int)$rawPid;
            if ($pid <= 0 || !$on) continue;

            $picked = [];
            foreach ((array)($ibArr[$pid] ?? []) as $x) $picked[] = (int)$x;

            $csv = Reseller::idsCsv(implode(',', $picked) . ',' . (string)($ibTxt[$pid] ?? ''));
            if ($csv === '') continue;

            $items[] = ['panel_id' => $pid, 'inbounds' => $csv];
        }

        if (!$items) {
            flash('err', '⛔️ حداقل یک اینباند از یک پنل انتخاب کنید.');
            back('resellers', ['tab' => 'svc']);
        }

        $new = [
            'id'         => $sid,
            'title'      => $ttl,
            'gb'         => pint('gb'),
            'days'       => pint('days'),
            'price'      => pint('price'),
            'device'     => pint('device'),
            'speed_up'   => pint('speed_up'),
            'speed_down' => pint('speed_down'),
            'ip_limit'   => pint('ip_limit'),
            'level'      => pint('level'),
            'sort'       => pint('sort'),
            'custom'     => pchk('custom') ? 1 : 0,
            'active'     => pchk('active') ? 1 : 0,
            'note'       => trim((string)($_POST['note'] ?? '')),
            'items'      => $items,
        ];

        $found = false;
        if ($sid !== '') {
            foreach ($rows as $i => $r) {
                if ((string)($r['id'] ?? '') === $sid) { $rows[$i] = $new; $found = true; break; }
            }
        }
        if (!$found) $rows[] = $new;

        Reseller::saveBundles($rows);
        flash('ok', $found ? '✅ سرویس به‌روزرسانی شد.' : '✅ سرویس تازه افزوده شد.');
        back('resellers', ['tab' => 'svc']);
    }

    if ($act === 'svcdel') {
        need('resellers.level', 'resellers');
        $sid  = trim((string)($_POST['bundle_id'] ?? ''));
        $rows = [];
        foreach (Reseller::bundles() as $r) {
            if ((string)($r['id'] ?? '') !== $sid) $rows[] = $r;
        }
        Reseller::saveBundles($rows);
        flash('ok', '🗑 سرویس حذف شد.');
        back('resellers', ['tab' => 'svc']);
    }

    if ($act === 'svctoggle') {
        need('resellers.level', 'resellers');
        $sid  = trim((string)($_POST['bundle_id'] ?? ''));
        $rows = Reseller::bundles();
        foreach ($rows as $i => $r) {
            if ((string)($r['id'] ?? '') === $sid) $rows[$i]['active'] = empty($r['active']) ? 1 : 0;
        }
        Reseller::saveBundles($rows);
        flash('ok', '✅ وضعیت سرویس تغییر کرد.');
        back('resellers', ['tab' => 'svc']);
    }
}

/* ---------------- داده‌ها ---------------- */
$uSel = (int)($_GET['u'] ?? 0);
$tab  = (string)($_GET['tab'] ?? 'list');
if (!in_array($tab, ['list', 'price', 'opt', 'req', 'plans', 'svc'], true)) $tab = 'list';
$reqCnt = Reseller::requestCount();

$list = Reseller::all(300);

$cnt1 = 0; $cnt2 = 0; $debt = 0;
foreach ($list as $r) {
    $lv = (int)($r['reseller_level'] ?? 0);
    if ($lv === 1) $cnt1++;
    if ($lv === 2) $cnt2++;
    $b = (int)($r['balance'] ?? 0);
    if ($b < 0) $debt += -$b;
}

$soldRs = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE is_reseller = 1", [], 0);

/* آمار گسترده و جستجو */
$ov    = method_exists('Reseller', 'overview') ? Reseller::overview() : [];
$topRs = method_exists('Reseller', 'top') ? Reseller::top(10) : [];
$ovv   = function (string $k, $d = null) use ($ov) {
    $x = $ov[$k] ?? null;
    return ($x === null || $x === '') ? $d : $x;
};

$listAll = $list;
$qs  = trim((string)($_GET['q'] ?? ''));
$flv = (string)($_GET['lv'] ?? '');
if ($qs !== '' || $flv !== '') {
    $needle = function_exists('mb_strtolower') ? mb_strtolower($qs) : strtolower($qs);
    $list = array_values(array_filter($listAll, function ($r) use ($needle, $flv) {
        if ($flv !== '' && (int)($r['reseller_level'] ?? 0) !== (int)$flv) return false;
        if ($needle === '') return true;
        $hay = trim((string)($r['first_name'] ?? '')) . ' ' . trim((string)($r['username'] ?? ''))
             . ' ' . (string)($r['tg_id'] ?? '') . ' ' . (string)($r['id'] ?? '');
        $hay = function_exists('mb_strtolower') ? mb_strtolower($hay) : strtolower($hay);
        return strpos($hay, $needle) !== false;
    }));
}

/* خروجی CSV برای کپی سریع */
$csv = "id,tg_id,name,username,level,balance,credit,discount\n";
foreach ($listAll as $r) {
    $csv .= (int)$r['id'] . ',' . (int)$r['tg_id'] . ','
        . str_replace(',', ' ', trim((string)($r['first_name'] ?? ''))) . ','
        . str_replace(',', ' ', trim((string)($r['username'] ?? ''))) . ','
        . (int)($r['reseller_level'] ?? 0) . ',' . (int)($r['balance'] ?? 0) . ','
        . (int)Reseller::userCredit($r) . ',' . (int)Reseller::userDiscount($r) . "\n";
}

/* اگر ستون‌های نمایندگی ساخته نشده باشند، نماینده‌کردن کار نمی‌کند */
$rsColOk = true;
try {
    DB::val('SELECT reseller_level FROM {p}users LIMIT 1', [], 0);
} catch (Throwable $e) {
    $rsColOk = false;
}
?>

<style>
/* ================= مرکز نمایندگی ================= */
.rs-hero{position:relative;overflow:hidden;
  background:radial-gradient(1100px 320px at 92% -60%, color-mix(in srgb,var(--accent) 20%,transparent), transparent 70%), var(--surface);}
.rs-hero::before{content:"";position:absolute;inset:0 0 auto 0;height:3px;background:var(--grad)}
.rs-quick{display:flex;gap:8px;flex-wrap:wrap}
.rs-quick .q{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border:1px solid var(--border);
  border-radius:var(--r-pill);background:var(--surface-2);color:var(--muted);font-size:12px;font-weight:700;
  text-decoration:none;transition:.18s}
.rs-quick .q:hover{color:var(--accent-text);border-color:var(--accent);background:var(--accent-soft)}
.rs-quick .q b{color:var(--text)}

.rs-kpis{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(185px,1fr));margin-top:16px}
.rs-k{position:relative;overflow:hidden;padding:14px;border:1px solid var(--border-soft);border-radius:var(--r-lg);
  background:linear-gradient(180deg,var(--surface-2),var(--surface));transition:.18s}
.rs-k:hover{transform:translateY(-2px);border-color:color-mix(in srgb,var(--c,var(--accent)) 55%,var(--border));
  box-shadow:0 10px 26px -18px var(--c,var(--accent))}
.rs-k::after{content:"";position:absolute;inset-inline-start:0;top:0;bottom:0;width:3px;background:var(--c,var(--accent));opacity:.75}
.rs-k .ic{position:absolute;inset-inline-end:10px;top:8px;font-size:24px;opacity:.18}
.rs-k .t{font-size:11.5px;color:var(--muted);font-weight:700}
.rs-k .v{font-size:20px;font-weight:800;margin-top:6px;color:var(--c,var(--text))}
.rs-k .s{font-size:11px;color:var(--muted);margin-top:5px;line-height:1.7}
.rs-k .pbar{height:6px;border-radius:99px;background:var(--surface-3);overflow:hidden;margin-top:9px}
.rs-k .pbar i{display:block;height:100%;border-radius:99px;background:var(--c,var(--accent))}

.rs-spark{margin-top:16px;padding:14px;border:1px solid var(--border-soft);border-radius:var(--r-lg);background:var(--surface-2)}
.rs-spark .h{display:flex;justify-content:space-between;align-items:center;gap:10px;font-size:12px;color:var(--muted);font-weight:700;flex-wrap:wrap}
.rs-spark .h b{color:var(--accent-text)}
.rs-spark .b{display:flex;align-items:flex-end;gap:4px;height:72px;margin-top:12px}
.rs-spark .b i{flex:1;min-width:4px;border-radius:5px 5px 2px 2px;transition:.18s;
  background:linear-gradient(180deg,var(--accent),color-mix(in srgb,var(--accent) 20%,transparent))}
.rs-spark .b i:hover{filter:brightness(1.3)}
.rs-spark .b i.z{background:var(--surface-3)}
.rs-spark .f{display:flex;justify-content:space-between;font-size:10.5px;color:var(--muted);margin-top:7px}

/* ---------- تب‌ها ---------- */
.rs-tabs{gap:6px;flex-wrap:wrap}
.rs-tabs a{border-radius:var(--r-pill)!important;transition:.18s}
.rs-tabs a:hover{color:var(--accent-text)}
.rs-tabs a.on{background:var(--accent-soft)!important;border-color:var(--accent)!important;color:var(--accent-text)!important;
  box-shadow:0 6px 18px -12px var(--accent)}

/* ---------- نوار ابزار ---------- */
.rs-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.rs-search{flex:1;min-width:230px;display:flex;align-items:center;gap:8px;padding:9px 13px;
  border:1px solid var(--border);border-radius:var(--r-pill);background:var(--surface-2);transition:.18s}
.rs-search:focus-within{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.rs-search span{opacity:.65}
.rs-search input{flex:1;border:0;background:transparent;color:var(--text);font-family:var(--font);font-size:13px;outline:none;padding:0}
.rs-filt{display:flex;gap:6px;flex-wrap:wrap}
.rs-filt button{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border:1px solid var(--border);
  border-radius:var(--r-pill);background:var(--surface-2);color:var(--muted);font-family:var(--font);
  font-size:12px;font-weight:700;cursor:pointer;transition:.18s}
.rs-filt button:hover{color:var(--text);border-color:var(--accent)}
.rs-filt button.on{background:var(--accent-soft);border-color:var(--accent);color:var(--accent-text)}
.rs-filt button i{font-style:normal;font-size:10.5px;padding:1px 7px;border-radius:99px;background:var(--surface-3);color:var(--muted)}
.rs-filt button.on i{background:var(--accent);color:#fff}
.rs-tools{display:flex;gap:6px;flex-wrap:wrap;margin-inline-start:auto}
.rs-add{display:none;margin-top:14px;padding-top:14px;border-top:1px dashed var(--border)}
.rs-add.open{display:block;animation:rsIn .2s ease}
@keyframes rsIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}

/* ---------- کارت نماینده ---------- */
.rs-grid{display:grid;gap:12px;grid-template-columns:minmax(0,1fr)}
@media(min-width:760px){.rs-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(min-width:1240px){.rs-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
.rs-card{position:relative;overflow:hidden;padding:14px;border:1px solid var(--border);border-radius:var(--r-lg);
  background:var(--surface);transition:.18s}
.rs-card::before{content:"";position:absolute;inset:0 0 auto 0;height:3px;
  background:linear-gradient(90deg,var(--c),transparent)}
.rs-card:hover{transform:translateY(-3px);border-color:color-mix(in srgb,var(--c) 60%,var(--border));
  box-shadow:0 16px 34px -24px var(--c)}
.rs-card .top{display:flex;align-items:center;gap:10px}
.rs-card .ava{width:42px;height:42px;flex:0 0 42px;border-radius:14px;display:grid;place-items:center;
  font-size:17px;font-weight:800;color:#fff;background:linear-gradient(140deg,var(--c),color-mix(in srgb,var(--c) 45%,#000));
  box-shadow:0 8px 20px -12px var(--c)}
.rs-card .tt{min-width:0;flex:1}
.rs-card .nm{display:block;font-size:14px;font-weight:800;color:var(--text);text-decoration:none;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rs-card .nm:hover{color:var(--accent-text)}
.rs-card .sub{font-size:11px;color:var(--muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rs-card .lv{flex:0 0 auto;font-size:10.5px;font-weight:800;padding:4px 9px;border-radius:99px;
  color:var(--c);background:color-mix(in srgb,var(--c) 14%,transparent);border:1px solid color-mix(in srgb,var(--c) 35%,transparent)}
.rs-card .nums{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:12px}
.rs-card .nums .n{padding:8px 10px;border-radius:var(--r);background:var(--surface-2);border:1px solid var(--border-soft);text-align:center}
.rs-card .nums .n span{display:block;font-size:10.5px;color:var(--muted)}
.rs-card .nums .n b{display:block;font-size:13px;font-weight:800;margin-top:3px;font-family:var(--font-mono)}
.rs-card .nums .n b.g{color:var(--green)}
.rs-card .nums .n b.r{color:var(--red)}
.rs-card .nums .n i{display:block;font-style:normal;font-size:10px;color:var(--muted);margin-top:2px}
.rs-card .bar{margin-top:11px;padding:9px 10px;border-radius:var(--r);background:var(--surface-2);border:1px solid var(--border-soft)}
.rs-card .bar .t{display:flex;justify-content:space-between;gap:8px;font-size:11px;color:var(--muted);font-weight:700}
.rs-card .bar .t b{font-family:var(--font-mono);color:var(--text);font-size:11px}
.rs-card .bar .p{height:6px;border-radius:99px;background:var(--surface-3);overflow:hidden;margin-top:7px}
.rs-card .bar .p i{display:block;height:100%;border-radius:99px;background:var(--green)}
.rs-card .bar.mid .p i{background:var(--accent)}
.rs-card .bar.warn .p i{background:var(--orange)}
.rs-card .bar.bad .p i{background:var(--red)}
.rs-card .bar.warn .t span{color:var(--orange)}
.rs-card .bar.bad .t span{color:var(--red)}
.rs-card .acts{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px;padding-top:11px;border-top:1px dashed var(--border-soft)}
.rs-empty{padding:26px;text-align:center;color:var(--muted);font-size:13px;border:1px dashed var(--border);
  border-radius:var(--r-lg);background:var(--surface);margin-top:12px}

/* ---------- سکوی برترین‌ها ---------- */
.rs-podium{display:grid;gap:10px;grid-template-columns:minmax(0,1fr)}
@media(min-width:700px){.rs-podium{grid-template-columns:repeat(3,minmax(0,1fr))}}
.rs-podium a{position:relative;overflow:hidden;display:block;padding:14px;text-decoration:none;text-align:center;
  border:1px solid var(--border);border-radius:var(--r-lg);background:var(--surface-2);transition:.18s}
.rs-podium a:hover{transform:translateY(-3px)}
.rs-podium a.p1{background:linear-gradient(180deg,color-mix(in srgb,#f5c451 16%,var(--surface-2)),var(--surface-2));border-color:#f5c45166}
.rs-podium a.p2{background:linear-gradient(180deg,color-mix(in srgb,#cbd5e1 13%,var(--surface-2)),var(--surface-2));border-color:#cbd5e155}
.rs-podium a.p3{background:linear-gradient(180deg,color-mix(in srgb,#d08c50 13%,var(--surface-2)),var(--surface-2));border-color:#d08c5055}
.rs-podium .m{font-size:26px;display:block}
.rs-podium .n{display:block;font-weight:800;color:var(--text);font-size:13.5px;margin-top:6px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rs-podium .c{display:block;font-size:11.5px;color:var(--accent-text);margin-top:4px;font-weight:700}
.rs-podium .b{display:block;font-size:11px;color:var(--muted);margin-top:3px;font-family:var(--font-mono)}
.rs-rank{display:grid;gap:6px}
.rs-rank a{display:flex;align-items:center;gap:10px;padding:9px 12px;text-decoration:none;
  border:1px solid var(--border-soft);border-radius:var(--r);background:var(--surface-2);transition:.18s}
.rs-rank a:hover{border-color:var(--accent);background:var(--accent-soft)}
.rs-rank .i{width:24px;height:24px;flex:0 0 24px;display:grid;place-items:center;border-radius:8px;
  background:var(--surface-3);color:var(--muted);font-size:11px;font-weight:800}
.rs-rank .n{flex:1;min-width:0;color:var(--text);font-size:12.5px;font-weight:700;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rs-rank .c{font-size:11.5px;color:var(--accent-text);font-weight:800;font-family:var(--font-mono)}
.rs-prof{position:relative;overflow:hidden}
.rs-prof::before{content:"";position:absolute;inset:0 0 auto 0;height:3px;background:linear-gradient(90deg,var(--c),transparent)}
.rs-phead{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.rs-phead .ava{width:54px;height:54px;flex:0 0 54px;border-radius:18px;display:grid;place-items:center;
  font-size:21px;font-weight:800;color:#fff;box-shadow:0 10px 24px -14px var(--c);
  background:linear-gradient(140deg,var(--c),color-mix(in srgb,var(--c) 45%,#000))}
.rs-phead .tt{flex:1;min-width:180px}
.rs-phead .nm{font-size:16px;font-weight:800;color:var(--text);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.rs-phead .nm .lv{font-size:10.5px;font-weight:800;padding:4px 9px;border-radius:99px;color:var(--c);
  background:color-mix(in srgb,var(--c) 14%,transparent);border:1px solid color-mix(in srgb,var(--c) 35%,transparent)}
.rs-phead .sub{font-size:11.5px;color:var(--muted);margin-top:4px}
.rs-phead .ds{font-size:11.5px;color:var(--muted);margin-top:3px}
.rs-phead .acts{display:flex;gap:6px;flex-wrap:wrap;margin-inline-start:auto}
.rs-hbar{margin-top:14px;padding:11px 12px;border-radius:var(--r-lg);background:var(--surface-2);border:1px solid var(--border-soft)}
.rs-hbar .t{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--muted);font-weight:700}
.rs-hbar .t b{font-family:var(--font-mono);color:var(--text);font-size:11.5px}
.rs-hbar .p{height:8px;border-radius:99px;background:var(--surface-3);overflow:hidden;margin-top:9px}
.rs-hbar .p i{display:block;height:100%;border-radius:99px;background:var(--green);transition:.25s}
.rs-hbar.mid .p i{background:var(--accent)}
.rs-hbar.warn .p i{background:var(--orange)}
.rs-hbar.bad .p i{background:var(--red)}
.rs-hbar.warn .t span{color:var(--orange)}
.rs-hbar.bad .t span{color:var(--red)}
/* ===== Reseller profile (dossier) ===== */
.rsp-hero{position:relative;overflow:hidden;margin:calc(var(--s4) * -1) calc(var(--s4) * -1) 0;
  padding:var(--s5) var(--s4);border-block-end:1px solid var(--border);
  background:linear-gradient(155deg,color-mix(in srgb,var(--c,#5B8CFF) 22%,transparent),rgba(139,92,246,.08) 48%,transparent 78%)}
.rsp-glow{position:absolute;border-radius:50%;filter:blur(62px);opacity:.5;pointer-events:none}
.rsp-glow.g1{width:230px;height:230px;background:var(--c,#5B8CFF);opacity:.28;inset-block-start:-96px;inset-inline-end:-60px}
.rsp-glow.g2{width:180px;height:180px;background:rgba(139,92,246,.30);inset-block-end:-92px;inset-inline-start:-54px}
.rsp-top{position:relative;display:flex;align-items:flex-start;gap:var(--s3);flex-wrap:wrap}
.rsp-ava{width:60px;height:60px;flex:none;border-radius:20px;display:grid;place-items:center;
  font-size:26px;font-weight:800;color:#fff;
  background:linear-gradient(140deg,var(--c,#5B8CFF),var(--accent-2));
  border:1px solid var(--border);box-shadow:var(--glow)}
.rsp-tt{flex:1;min-width:0}
.rsp-tt .nm{display:flex;align-items:center;gap:7px;flex-wrap:wrap;font-size:17px;font-weight:800}
.rsp-tt .lv{font-size:11px;padding:3px 10px;border-radius:99px;color:#fff;
  background:linear-gradient(135deg,var(--c,#5B8CFF),var(--accent-2))}
.rsp-tt .sub{font-size:12px;color:var(--muted);margin-top:5px}
.rsp-tt .ds{font-size:11.5px;color:var(--muted);margin-top:4px;line-height:1.9;max-width:62ch}
.rsp-flag{font-size:10.5px;padding:3px 9px;border-radius:99px}
.rsp-flag.g{background:var(--green-soft);color:var(--green)}
.rsp-flag.r{background:var(--red-soft);color:var(--red)}
.rsp-acts{display:flex;gap:6px;flex-wrap:wrap;margin-inline-start:auto}
.rsp-cells{position:relative;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:var(--s2);margin-top:var(--s4)}
.rsp-cell{display:flex;flex-direction:column;align-items:center;gap:2px;padding:var(--s3) var(--s2);
  background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r);
  transition:transform .18s,border-color .18s}
.rsp-cell:hover{transform:translateY(-3px);border-color:var(--c,var(--accent))}
.rsp-cell .i{font-size:17px}
.rsp-cell .v{font-family:var(--font-num);font-size:15.5px;font-weight:800;text-align:center;line-height:1.5}
.rsp-cell .l{font-size:10.5px;color:var(--muted);text-align:center}
.rsp-cell.g .v{color:var(--green)}.rsp-cell.o .v{color:var(--orange)}
.rsp-cell.r .v{color:var(--red)}.rsp-cell.b .v{color:var(--accent)}
.rsp-cell.c .v{color:var(--cyan)}.rsp-cell.p .v{color:var(--accent-2)}
@media (max-width:1100px){.rsp-cells{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media (max-width:640px){.rsp-cells{grid-template-columns:repeat(2,minmax(0,1fr))}
  .rsp-acts{margin-inline-start:0;width:100%}}
</style>

<div class="card rs-hero">
  <div class="card-head">
    <div>
      <div class="card-title">🏷 مرکز نمایندگی</div>
      <div class="card-sub">وضعیت مالی، فروش و فعالیت نمایندگان در یک نگاه</div>
    </div>
    <div class="rs-quick">
      <a class="q" href="index.php?p=resellers&tab=req">📝 درخواست‌ها <b><?= fa_num((int)$ovv('requests', $reqCnt)) ?></b></a>
      <a class="q" href="index.php?p=resellers&tab=plans">🎁 طرح‌ها <b><?= fa_num((int)$ovv('plans', count(Reseller::plans()))) ?></b></a>
      <a class="q" href="index.php?p=resellers&tab=svc">🧩 سرویس‌ها <b><?= fa_num((int)$ovv('bundles', count(Reseller::bundles()))) ?></b></a>
      <a class="q" href="index.php?p=resellers&tab=opt">🎛 سقف‌ها و امکانات</a>
    </div>
  </div>

  <div class="rs-kpis">
    <div class="rs-k" style="--c:var(--accent)">
      <div class="ic">🏷</div>
      <div class="t">کل نمایندگان</div>
      <div class="v"><?= fa_num((int)$ovv('total', count($listAll))) ?></div>
      <div class="s">🥈 سطح ۱: <?= fa_num((int)$ovv('l1', $cnt1)) ?> • 🥇 سطح ۲: <?= fa_num((int)$ovv('l2', $cnt2)) ?><?php if ((int)$ovv('new30', 0) > 0): ?><br>🆕 <?= fa_num((int)$ovv('new30', 0)) ?> نمایندهٔ تازه در ۳۰ روز<?php endif; ?></div>
    </div>

    <div class="rs-k" style="--c:var(--green)">
      <div class="ic">💰</div>
      <div class="t">جمع موجودی نمایندگان</div>
      <div class="v"><?= h((string)$ovv('balance_txt', '—')) ?></div>
      <div class="s">میانگین تخفیف: <?= fa_num((string)$ovv('avg_discount', 0)) ?>٪</div>
    </div>

    <div class="rs-k" style="--c:var(--red)">
      <div class="ic">🧨</div>
      <div class="t">جمع بدهی نمایندگان</div>
      <div class="v"><?= h((string)$ovv('debt_txt', money($debt))) ?></div>
      <div class="s"><?= fa_num((int)$ovv('debtors', 0)) ?> نمایندهٔ بدهکار</div>
    </div>

    <div class="rs-k" style="--c:var(--orange)">
      <div class="ic">🎫</div>
      <div class="t">سقف اعتبار تخصیص‌یافته</div>
      <div class="v"><?= h((string)$ovv('credit_txt', '—')) ?></div>
      <div class="s">آزاد: <?= h((string)$ovv('credit_free_txt', '—')) ?> • مصرف <?= fa_num((int)$ovv('credit_pct', 0)) ?>٪</div>
      <div class="pbar"><i style="width:<?= max(2, min(100, (int)$ovv('credit_pct', 0))) ?>%"></i></div>
    </div>

    <div class="rs-k" style="--c:var(--cyan)">
      <div class="ic">📦</div>
      <div class="t">کانفیگ ساختهٔ نمایندگان</div>
      <div class="v"><?= fa_num((int)$ovv('services', $soldRs)) ?></div>
      <div class="s">✅ <?= fa_num((int)$ovv('active', 0)) ?> فعال • ⌛️ <?= fa_num((int)$ovv('expired', 0)) ?> منقضی<br>میانگین هر نماینده: <?= fa_num((int)$ovv('avg_cfg', 0)) ?></div>
    </div>

    <div class="rs-k" style="--c:var(--accent-2)">
      <div class="ic">📈</div>
      <div class="t">فروش ۳۰ روز اخیر</div>
      <div class="v"><?= h((string)$ovv('month_txt', $ovv('sold_txt', '—'))) ?></div>
      <div class="s">۷ روز: <?= h((string)$ovv('week_txt', '—')) ?> • ۲۴ ساعت: <?= h((string)$ovv('today_txt', '—')) ?><br>کل فروش: <?= h((string)$ovv('sold_txt', '—')) ?></div>
    </div>
  </div>

  <?php
  $rsDaily = method_exists('Reseller', 'daily') ? Reseller::daily(14) : [];
  $rsMax = 0;
  foreach ($rsDaily as $dd) { if ((int)$dd['sum'] > $rsMax) $rsMax = (int)$dd['sum']; }
  ?>
  <?php if ($rsDaily): ?>
    <div class="rs-spark">
      <div class="h">
        <span>📊 روند فروش ۱۴ روز اخیر نمایندگان</span>
        <b>بیشترین روز: <?= money($rsMax) ?> <?= h(currency()) ?></b>
      </div>
      <div class="b">
        <?php foreach ($rsDaily as $dd):
          $sv = (int)$dd['sum'];
          $pc = $rsMax > 0 ? max(4, (int)round($sv / $rsMax * 100)) : 4; ?>
          <i class="<?= $sv > 0 ? '' : 'z' ?>" style="height:<?= $pc ?>%"
             title="<?= h((string)$dd['date']) ?> — <?= money($sv) ?> <?= h(currency()) ?> (<?= fa_num((int)$dd['count']) ?> فروش)"></i>
        <?php endforeach; ?>
      </div>
      <div class="f"><span>۱۴ روز پیش</span><span>روی هر ستون نگه دارید</span><span>امروز</span></div>
    </div>
  <?php endif; ?>
</div>

<?php if (!$rsColOk): ?>
  <div class="card mt4">
    <div class="alert a-err">
      ⛔️ <b>ستون‌های نمایندگی در دیتابیس ساخته نشده‌اند.</b><br>
      تا وقتی این ستون‌ها ساخته نشوند، ثبت نماینده ذخیره نمی‌شود. یک بار دکمهٔ زیر را بزنید.
      <div class="btn-row mt3"><a class="btn btn-primary btn-sm" href="index.php?p=update">🧩 بررسی و تکمیل ساختار دیتابیس</a></div>
    </div>
  </div>
<?php endif; ?>

<div class="tabs rs-tabs mt4">
  <a class="<?= $tab === 'list' ? 'on' : '' ?>" href="index.php?p=resellers">🏷 نمایندگان (<?= fa_num(count($list)) ?>)</a>
  <a class="<?= $tab === 'req' ? 'on' : '' ?>" href="index.php?p=resellers&tab=req">📝 درخواست‌ها<?= $reqCnt ? ' (' . fa_num($reqCnt) . ')' : '' ?></a>
  <a class="<?= $tab === 'price' ? 'on' : '' ?>" href="index.php?p=resellers&tab=price">💵 تعرفه و تنظیمات</a>
  <a class="<?= $tab === 'opt' ? 'on' : '' ?>" href="index.php?p=resellers&tab=opt">🎛 امکانات و سقف‌ها</a>
  <a class="<?= $tab === 'plans' ? 'on' : '' ?>" href="index.php?p=resellers&tab=plans">🎁 طرح‌های آماده (<?= fa_num(count(Reseller::plans())) ?>)</a>
  <a class="<?= $tab === 'svc' ? 'on' : '' ?>" href="index.php?p=resellers&tab=svc">🧩 سرویس‌ها (<?= fa_num(count(Reseller::bundles())) ?>)</a>
</div>

<?php if ($tab === 'req'): ?>
  <?php $reqs = Reseller::requests(200); ?>
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">📝 درخواست‌های نمایندگی</div>
        <div class="card-sub">کاربرانی که از داخل ربات درخواست نمایندگی داده‌اند</div>
      </div>
      <span class="badge <?= $reqs ? 'b-blue' : 'b-gray' ?>"><?= fa_num(count($reqs)) ?> در انتظار</span>
    </div>

    <?php if (!$reqs): ?>
      <div class="empty"><div class="ic">🗂</div>در حال حاضر درخواستی در انتظار بررسی نیست.</div>
    <?php else: ?>
      <div class="table-wrap"><table class="responsive">
        <thead><tr>
          <th>کاربر</th><th>موجودی</th><th>جمع خرید</th>
          <th>توضیح کاربر</th><th>تاریخ</th><th>اقدام</th>
        </tr></thead>
        <tbody>
        <?php foreach ($reqs as $r): ?>
          <tr>
            <td data-l="کاربر">
              <a href="index.php?p=users&u=<?= (int)$r['id'] ?>"><?= h((string)($r['first_name'] ?: 'کاربر')) ?></a>
              <div class="mono muted" style="font-size:12px">
                <?= (int)$r['tg_id'] ?><?= trim((string)($r['username'] ?? '')) !== '' ? ' · @' . h((string)$r['username']) : '' ?>
              </div>
            </td>
            <td data-l="موجودی" class="mono"><?= h(money((float)($r['balance'] ?? 0))) ?></td>
            <td data-l="جمع خرید" class="mono"><?= h(money((float)($r['total_paid'] ?? 0))) ?></td>
            <td data-l="توضیح"><?= trim((string)($r['reseller_req_note'] ?? '')) !== '' ? h((string)$r['reseller_req_note']) : '—' ?></td>
            <td data-l="تاریخ"><?= !empty($r['reseller_req_at']) ? h(to_jalali((string)$r['reseller_req_at'], true)) : '—' ?></td>
            <td data-l="اقدام">
              <?php if (can('resellers.level')): ?>
                <form method="post" class="row" style="gap:6px;flex-wrap:wrap;align-items:center">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="rsreq_ok">
                  <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                  <select name="level" style="max-width:130px">
                    <option value="1">🥈 سطح ۱</option>
                    <option value="2">🥇 سطح ۲</option>
                  </select>
                  <button class="btn btn-sm btn-primary">✅ تایید</button>
                </form>
                <form method="post" class="mt3">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="rsreq_no">
                  <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-sm btn-ghost" data-confirm="درخواست این کاربر رد شود؟">❌ رد</button>
                </form>
              <?php else: ?>—<?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>

    <div class="alert a-info mt3">
      تایید این درخواست‌ها از داخل ربات هم ممکن است؛ مدیران برای هر درخواست تازه پیام با دکمهٔ تایید/رد دریافت می‌کنند.
      برای باز یا بسته کردن فرم درخواست، به تب «تعرفه و تنظیمات» بروید.
    </div>
  </div>

<?php elseif ($tab === 'plans'): ?>

  <?php
    $plans    = Reseller::plans();
    $planEdit = trim((string)($_GET['plan'] ?? ''));
    $pe       = $planEdit !== '' ? Reseller::plan($planEdit) : null;
    $pvv      = static function (string $k, $d = '') use ($pe) {
        if (!$pe) return $d;
        $x = $pe[$k] ?? null;
        return ($x === null || $x === '') ? $d : $x;
    };
    $pnls = DB::all('SELECT id, name FROM {p}panels WHERE active = 1 ORDER BY sort ASC, id ASC');
  ?>

  <div class="card mt3">
    <div class="card-head">
      <div>
        <div class="card-title">🎁 طرح‌های آمادهٔ نمایندگی</div>
        <div class="card-sub">نماینده به جای وارد کردن حجم و مدت، یک طرح آماده را انتخاب و بلافاصله کانفیگ می‌سازد.</div>
      </div>
      <span class="badge <?= $plans ? 'b-green' : 'b-gray' ?>"><?= fa_num(count($plans)) ?> طرح</span>
    </div>
    <div class="alert a-info mt3">
      💡 مثال: طرح «نامحدود ۳۰ روزه» ← حجم = ۰ (نامحدود) و مدت = ۳۰ روز.
      اگر قیمت را ۰ بگذارید، قیمت از تعرفهٔ نمایندگی محاسبه می‌شود.
    </div>
  </div>

  <?php if (can('resellers.level')): ?>
  <form method="post" class="card mt3">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="planadd">
    <input type="hidden" name="plan_id" value="<?= h((string)$pvv('id')) ?>">

    <div class="card-head">
      <div>
        <div class="card-title"><?= $pe ? '✏️ ویرایش طرح: ' . h((string)$pvv('title')) : '➕ افزودن طرح آماده' ?></div>
        <div class="card-sub">محدودیت دستگاه و سرعت فقط روی پنل VPN-UI اعمال می‌شود.</div>
      </div>
      <?php if ($pe): ?><a class="btn btn-sm btn-ghost" href="index.php?p=resellers&tab=plans">➕ طرح تازه</a><?php endif; ?>
    </div>

    <div class="fieldset">
      <div class="lg">🧾 مشخصات طرح</div>
      <div class="form-grid g2">
        <div class="field full">
          <label>عنوان طرح <span style="color:var(--red)">*</span></label>
          <input type="text" name="title" value="<?= h((string)$pvv('title')) ?>" placeholder="مثلاً نامحدود ۳۰ روزه" required>
        </div>

        <div class="field">
          <label>حجم (گیگابایت)</label>
          <input class="mono" type="number" min="0" name="gb" value="<?= (int)$pvv('gb', 0) ?>">
          <div class="hint">۰ = نامحدود</div>
        </div>

        <div class="field">
          <label>مدت (روز)</label>
          <input class="mono" type="number" min="0" name="days" value="<?= (int)$pvv('days', 30) ?>">
          <div class="hint">۰ = بدون انقضا</div>
        </div>

        <div class="field">
          <label>قیمت برای نماینده (<?= h(currency()) ?>)</label>
          <input class="mono" type="number" min="0" name="price" value="<?= (int)$pvv('price', 0) ?>">
          <div class="hint">۰ = محاسبه از تعرفهٔ نمایندگی</div>
        </div>

        <div class="field">
          <label>ترتیب نمایش</label>
          <input class="mono" type="number" name="sort" value="<?= (int)$pvv('sort', 0) ?>">
        </div>
      </div>
    </div>

    <div class="fieldset">
      <div class="lg">⚡️ محدودیت‌ها</div>
      <div class="form-grid g2">
        <div class="field">
          <label>محدودیت دستگاه (Device limit)</label>
          <input class="mono" type="number" min="0" name="device" value="<?= (int)$pvv('device', 0) ?>">
          <div class="hint">۰ = بدون محدودیت</div>
        </div>

        <div class="field">
          <label>محدودیت آیپی همزمان (IP limit)</label>
          <input class="mono" type="number" min="0" name="ip_limit" value="<?= (int)$pvv('ip_limit', 0) ?>">
          <div class="hint">۰ = استفاده از مقدار پیش‌فرض نمایندگی</div>
        </div>

        <div class="field">
          <label>سرعت دانلود (KB/s)</label>
          <input class="mono" type="number" min="0" name="speed_down" value="<?= (int)$pvv('speed_down', 0) ?>">
          <div class="hint">۰ = بدون محدودیت</div>
        </div>

        <div class="field">
          <label>سرعت آپلود (KB/s)</label>
          <input class="mono" type="number" min="0" name="speed_up" value="<?= (int)$pvv('speed_up', 0) ?>">
          <div class="hint">۰ = بدون محدودیت</div>
        </div>
      </div>
    </div>

    <div class="fieldset">
      <div class="lg">🎛 دسترسی و سرور</div>
      <div class="form-grid g2">
        <div class="field">
          <label>سرور (پنل)</label>
          <select name="panel_id">
            <option value="0">— انتخاب خودکار —</option>
            <?php foreach ($pnls as $pn): ?>
              <option value="<?= (int)$pn['id'] ?>" <?= (int)$pvv('panel_id', 0) === (int)$pn['id'] ? 'selected' : '' ?>><?= h((string)$pn['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label>حداقل سطح نماینده</label>
          <select name="level">
            <option value="0" <?= (int)$pvv('level', 0) === 0 ? 'selected' : '' ?>>همهٔ نمایندگان</option>
            <option value="1" <?= (int)$pvv('level', 0) === 1 ? 'selected' : '' ?>>🥈 سطح ۱ به بالا</option>
            <option value="2" <?= (int)$pvv('level', 0) === 2 ? 'selected' : '' ?>>🥇 فقط سطح ۲</option>
          </select>
        </div>

        <div class="field full">
          <label>توضیح کوتاه</label>
          <input type="text" name="note" value="<?= h((string)$pvv('note')) ?>" placeholder="مثلاً مناسب موبایل و پرسرعت">
        </div>

        <div class="field">
          <label>وضعیت</label>
          <label class="check" style="margin-top:8px">
            <input type="checkbox" name="active" value="1" <?= (!$pe || !empty($pe['active'])) ? 'checked' : '' ?>>
            <span>نمایش به نمایندگان</span>
          </label>
        </div>
      </div>
    </div>

    <div class="sticky-acts"><button class="btn btn-primary">💾 <?= $pe ? 'ذخیرهٔ تغییرات' : 'افزودن طرح' ?></button></div>
  </form>
  <?php endif; ?>

  <div class="card mt3">
    <div class="card-head"><div><div class="card-title">📋 طرح‌های ثبت‌شده</div></div></div>

    <?php if (!$plans): ?>
      <div class="empty"><div class="ic">🎁</div>هنوز طرح آماده‌ای ثبت نشده است.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="responsive">
          <thead><tr>
            <th class="grow-col">طرح</th><th class="tight">حجم و مدت</th><th class="tight">قیمت</th>
            <th class="tight">دستگاه</th><th class="tight">سرعت (KB/s)</th><th class="tight">سطح</th>
            <th class="tight">وضعیت</th><th data-l="">عملیات</th>
          </tr></thead>
          <tbody>
          <?php foreach ($plans as $pl): ?>
            <tr>
              <td data-l="طرح">
                <div class="idcell">
                  <span class="nm"><?= h((string)$pl['title']) ?></span>
                  <?php if ($pl['note'] !== ''): ?><span class="sub"><?= h((string)$pl['note']) ?></span><?php endif; ?>
                </div>
              </td>
              <td data-l="حجم و مدت" class="num"><span class="numbox"><?= h(Reseller::planLabel($pl)) ?></span></td>
              <td data-l="قیمت" class="num">
                <?php if ((int)$pl['price'] > 0): ?>
                  <span class="numbox g"><?= money((int)$pl['price']) ?></span>
                <?php else: ?>
                  <span class="badge b-gray">تعرفهٔ پویا</span>
                <?php endif; ?>
              </td>
              <td data-l="دستگاه" class="num"><span class="numbox"><?= (int)$pl['device'] > 0 ? fa_num((int)$pl['device']) : '—' ?></span></td>
              <td data-l="سرعت" class="num">
                <span class="numbox"><?= (int)$pl['speed_down'] > 0 ? fa_num((int)$pl['speed_down']) : '—' ?> ↓</span>
                <span class="numbox"><?= (int)$pl['speed_up'] > 0 ? fa_num((int)$pl['speed_up']) : '—' ?> ↑</span>
              </td>
              <td data-l="سطح" class="num"><span class="badge b-gray"><?= (int)$pl['level'] > 0 ? fa_num((int)$pl['level']) . '+' : 'همه' ?></span></td>
              <td data-l="وضعیت"><span class="badge <?= !empty($pl['active']) ? 'b-green' : 'b-gray' ?>"><?= !empty($pl['active']) ? 'فعال' : 'غیرفعال' ?></span></td>
              <td class="acts" data-l="">
                <?php if (can('resellers.level')): ?>
                  <a class="btn btn-sm btn-ghost" href="index.php?p=resellers&tab=plans&plan=<?= h(urlencode((string)$pl['id'])) ?>">✏️ ویرایش</a>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="plantoggle">
                    <input type="hidden" name="plan_id" value="<?= h((string)$pl['id']) ?>">
                    <button class="btn btn-sm"><?= !empty($pl['active']) ? '⏸ غیرفعال' : '▶️ فعال' ?></button>
                  </form>
                  <form method="post" style="display:inline" data-confirm="این طرح حذف شود؟">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="plandel">
                    <input type="hidden" name="plan_id" value="<?= h((string)$pl['id']) ?>">
                    <button class="btn btn-sm btn-danger">🗑</button>
                  </form>
                <?php else: ?>—<?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'svc'): ?>

  <?php
    $bundles = Reseller::bundles();
    $svcEdit = trim((string)($_GET['svc'] ?? ''));
    $be      = $svcEdit !== '' ? Reseller::bundle($svcEdit) : null;
    $bv      = static function (string $k, $d = '') use ($be) {
        if (!$be) return $d;
        $x = $be[$k] ?? null;
        return ($x === null || $x === '') ? $d : $x;
    };

    /* اینباندهای انتخاب‌شدهٔ فعلی برای هر پنل */
    $bSel = [];
    if ($be) {
        foreach ((array)$be['items'] as $bIt) {
            $bSel[(int)$bIt['panel_id']] = array_map('intval', array_filter(explode(',', (string)$bIt['inbounds'])));
        }
    }

    $pnlAll  = DB::all('SELECT * FROM {p}panels WHERE active = 1 ORDER BY sort ASC, id ASC');
    $pnlName = [];
    foreach ($pnlAll as $pn) $pnlName[(int)$pn['id']] = (string)$pn['name'];

    /* بارگذاری زندهٔ اینباندها (ممکن است چند ثانیه طول بکشد) */
    $loadInb = ((string)($_GET['load'] ?? '') === '1') || $be !== null;
    $inbMap  = [];
    if ($loadInb) {
        foreach ($pnlAll as $pn) {
            $pid = (int)$pn['id'];
            $inbMap[$pid] = null;
            try {
                $lst = (new Xui($pn))->inbounds();
                if (is_array($lst) && $lst) $inbMap[$pid] = $lst;
            } catch (Throwable $e) {
                $inbMap[$pid] = null;
            }
        }
    }
  ?>

  <div class="card mt3">
    <div class="card-head">
      <div>
        <div class="card-title">🧩 سرویس‌های نمایندگی</div>
        <div class="card-sub">از پنل‌هایی که اضافه کرده‌اید اینباندها را انتخاب کنید و یک نام بگذارید (مثلاً g1)</div>
      </div>
      <span class="badge <?= $bundles ? 'b-green' : 'b-gray' ?>"><?= fa_num(count($bundles)) ?> سرویس</span>
    </div>
    <div class="alert a-info mt3">
      💡 نماینده در مینی‌اپ فقط نام سرویس (مثلاً <b>g1</b> یا <b>g2</b>) را انتخاب می‌کند؛
      همهٔ کانفیگ‌های همان سرویس روی همهٔ پنل‌ها ساخته می‌شود و لینک اشتراک (ساب) برایش فرستاده می‌شود.
      برای هر پنل یک ساب جداگانه ساخته می‌شود که همهٔ اینباندهای انتخابی همان پنل درونش قرار دارد.
    </div>
  </div>

  <?php if (!$pnlAll): ?>
    <div class="card mt3"><div class="alert a-err">⛔️ هیچ پنل فعالی ثبت نشده است. اول از صفحهٔ «پنل‌ها» یک پنل اضافه کنید.
      <div class="btn-row mt3"><a class="btn btn-primary btn-sm" href="index.php?p=panels">➕ افزودن پنل</a></div></div></div>

  <?php elseif (can('resellers.level')): ?>

  <form method="post" class="card mt3">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="svcadd">
    <input type="hidden" name="bundle_id" value="<?= h((string)$bv('id')) ?>">

    <div class="card-head">
      <div>
        <div class="card-title"><?= $be ? '✏️ ویرایش سرویس: ' . h((string)$bv('title')) : '➕ ساخت سرویس تازه' ?></div>
        <div class="card-sub">نام سرویس همان چیزی است که نماینده در مینی‌اپ می‌بیند</div>
      </div>
      <?php if ($be): ?><a class="btn btn-sm btn-ghost" href="index.php?p=resellers&tab=svc">➕ سرویس تازه</a><?php endif; ?>
    </div>

    <div class="fieldset">
      <div class="lg"><span class="n">🏷</span> مشخصات سرویس</div>
      <div class="form-grid g2">
        <div class="field">
          <label>نام سرویس <span style="color:var(--red)">*</span></label>
          <input type="text" name="title" value="<?= h((string)$bv('title')) ?>" placeholder="مثلاً g1" required>
          <div class="hint">همین نام به نماینده نشان داده می‌شود.</div>
        </div>
        <div class="field">
          <label>ترتیب نمایش</label>
          <input class="mono" type="number" name="sort" value="<?= (int)$bv('sort', 0) ?>">
        </div>

        <div class="field">
          <label>حجم (گیگابایت)</label>
          <input class="mono" type="number" min="0" name="gb" value="<?= (int)$bv('gb', 0) ?>">
          <div class="hint">۰ = نامحدود</div>
        </div>
        <div class="field">
          <label>مدت (روز)</label>
          <input class="mono" type="number" min="0" name="days" value="<?= (int)$bv('days', 30) ?>">
          <div class="hint">۰ = بدون انقضا</div>
        </div>

        <div class="field">
          <label>قیمت برای نماینده (<?= h(currency()) ?>)</label>
          <input class="mono" type="number" min="0" name="price" value="<?= (int)$bv('price', 0) ?>">
          <div class="hint">۰ = محاسبه از تعرفهٔ نمایندگی</div>
        </div>
        <div class="field">
          <label>حداقل سطح نماینده</label>
          <select name="level">
            <option value="0" <?= (int)$bv('level', 0) === 0 ? 'selected' : '' ?>>همهٔ نمایندگان</option>
            <option value="1" <?= (int)$bv('level', 0) === 1 ? 'selected' : '' ?>>🥈 سطح ۱ به بالا</option>
            <option value="2" <?= (int)$bv('level', 0) === 2 ? 'selected' : '' ?>>🥇 فقط سطح ۲</option>
          </select>
        </div>

        <div class="field full">
          <label>توضیح کوتاه</label>
          <input type="text" name="note" value="<?= h((string)$bv('note')) ?>" placeholder="مثلاً میکس آلمان + فنلاند">
        </div>

        <div class="field">
          <label>حجم و مدت دلخواه</label>
          <label class="check" style="margin-top:8px">
            <input type="checkbox" name="custom" value="1" <?= !empty($bv('custom', 0)) ? 'checked' : '' ?>>
            <span>نماینده خودش حجم و مدت را وارد کند</span>
          </label>
          <div class="hint">در این حالت حجم/مدت بالا نادیده و قیمت از تعرفه حساب می‌شود.</div>
        </div>
        <div class="field">
          <label>وضعیت</label>
          <label class="check" style="margin-top:8px">
            <input type="checkbox" name="active" value="1" <?= (!$be || !empty($be['active'])) ? 'checked' : '' ?>>
            <span>نمایش به نمایندگان</span>
          </label>
        </div>
      </div>
    </div>

    <div class="fieldset accent">
      <div class="lg"><span class="n">📡</span> پنل‌ها و اینباندها</div>
      <div class="fs-hint">هر پنلی که تیک بزنید وارد این سرویس می‌شود و اینباندهای انتخابیاش ساخته خواهند شد.</div>

      <?php if (!$loadInb): ?>
        <div class="alert a-info mt3">
          📡 برای دیدن و تیک زدن اینباندها، ابتدا آن‌ها را از پنل‌ها بخوانید (ممکن است چند ثانیه طول بکشد).
          <div class="btn-row mt3"><a class="btn btn-primary btn-sm" href="index.php?p=resellers&tab=svc&load=1">🔄 خواندن اینباندها از پنل‌ها</a></div>
        </div>
      <?php endif; ?>

      <?php foreach ($pnlAll as $pn):
          $pid  = (int)$pn['id'];
          $on   = isset($bSel[$pid]);
          $sel  = $bSel[$pid] ?? [];
          $list = $inbMap[$pid] ?? null;
      ?>
        <div style="border:1px solid var(--line,rgba(128,128,128,.28));border-radius:14px;padding:12px;margin-top:10px">
          <label class="check">
            <input type="checkbox" name="use[<?= $pid ?>]" value="1" <?= $on ? 'checked' : '' ?>>
            <span><b><?= h((string)$pn['name']) ?></b> <span class="sub">(<?= h(Xui::typeLabel((string)$pn['type'])) ?>)</span></span>
          </label>

          <?php if (is_array($list) && $list): ?>
            <div class="form-grid g3" style="margin-top:9px">
              <?php foreach ($list as $ib):
                  $ibId = (int)($ib['id'] ?? 0);
                  if ($ibId <= 0) continue;
              ?>
                <label class="check">
                  <input type="checkbox" name="ib[<?= $pid ?>][]" value="<?= $ibId ?>" <?= in_array($ibId, $sel, true) ? 'checked' : '' ?>>
                  <span class="mono">#<?= $ibId ?></span>
                  <span><?= h((string)($ib['remark'] ?? '-')) ?></span>
                  <span class="sub"><?= h((string)($ib['protocol'] ?? '?')) ?>:<?= (int)($ib['port'] ?? 0) ?><?= empty($ib['enable']) ? ' ⛔' : '' ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          <?php elseif ($loadInb): ?>
            <div class="alert a-warn mt3">⚠️ اینباندهای این پنل خوانده نشد. کدها را دستی وارد کنید.</div>
          <?php endif; ?>

          <div class="field" style="margin-top:9px">
            <label>کد اینباندهای اضافی (دستی)</label>
            <input class="mono ltr" type="text" name="ibtxt[<?= $pid ?>]"
                   value="<?= h(!is_array($list) && $sel ? implode(',', $sel) : '') ?>" placeholder="مثلاً 1,3,5">
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="fieldset">
      <div class="lg"><span class="n">⚡️</span> محدودیت‌ها</div>
      <div class="fs-hint">محدودیت دستگاه و سرعت فقط روی پنل VPN-UI اعمال می‌شود.</div>
      <div class="form-grid g2">
        <div class="field">
          <label>محدودیت آیپی همزمان</label>
          <input class="mono" type="number" min="0" name="ip_limit" value="<?= (int)$bv('ip_limit', 0) ?>">
          <div class="hint">۰ = مقدار پیش‌فرض نمایندگی</div>
        </div>
        <div class="field">
          <label>محدودیت دستگاه</label>
          <input class="mono" type="number" min="0" name="device" value="<?= (int)$bv('device', 0) ?>">
          <div class="hint">۰ = بدون محدودیت</div>
        </div>
        <div class="field">
          <label>سرعت دانلود (KB/s)</label>
          <input class="mono" type="number" min="0" name="speed_down" value="<?= (int)$bv('speed_down', 0) ?>">
        </div>
        <div class="field">
          <label>سرعت آپلود (KB/s)</label>
          <input class="mono" type="number" min="0" name="speed_up" value="<?= (int)$bv('speed_up', 0) ?>">
        </div>
      </div>
    </div>

    <div class="sticky-acts"><button class="btn btn-primary">💾 <?= $be ? 'ذخیرهٔ تغییرات' : 'افزودن سرویس' ?></button></div>
  </form>
  <?php endif; ?>

  <div class="card mt3">
    <div class="card-head"><div><div class="card-title">📋 سرویس‌های ثبت‌شده</div></div></div>

    <?php if (!$bundles): ?>
      <div class="empty"><div class="ic">🧩</div>هنوز سرویسی ثبت نشده است.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="responsive">
          <thead><tr>
            <th class="grow-col">سرویس</th><th>پنل‌ها و اینباندها</th><th class="tight">حجم و مدت</th>
            <th class="tight">قیمت</th><th class="tight">سطح</th><th class="tight">وضعیت</th><th data-l="">عملیات</th>
          </tr></thead>
          <tbody>
          <?php foreach ($bundles as $bd): ?>
            <tr>
              <td data-l="سرویس">
                <div class="idcell">
                  <span class="nm"><?= h((string)$bd['title']) ?></span>
                  <span class="sub"><?= fa_num(Reseller::bundleConfigCount($bd)) ?> کانفیگ روی <?= fa_num(count($bd['items'])) ?> پنل<?= $bd['note'] !== '' ? ' · ' . h((string)$bd['note']) : '' ?></span>
                </div>
              </td>
              <td data-l="پنل‌ها">
                <?php foreach ($bd['items'] as $bIt): ?>
                  <div><span class="badge b-gray"><?= h((string)($pnlName[(int)$bIt['panel_id']] ?? ('#' . (int)$bIt['panel_id']))) ?></span>
                    <span class="mono">← <?= h((string)$bIt['inbounds']) ?></span></div>
                <?php endforeach; ?>
              </td>
              <td data-l="حجم و مدت" class="num"><span class="numbox"><?= h(Reseller::bundleLabel($bd)) ?></span></td>
              <td data-l="قیمت" class="num">
                <?php if ((int)$bd['price'] > 0): ?>
                  <span class="numbox g"><?= money((int)$bd['price']) ?></span>
                <?php else: ?>
                  <span class="badge b-gray">تعرفهٔ پویا</span>
                <?php endif; ?>
              </td>
              <td data-l="سطح" class="num"><span class="badge b-gray"><?= (int)$bd['level'] > 0 ? fa_num((int)$bd['level']) . '+' : 'همه' ?></span></td>
              <td data-l="وضعیت"><span class="badge <?= !empty($bd['active']) ? 'b-green' : 'b-gray' ?>"><?= !empty($bd['active']) ? 'فعال' : 'غیرفعال' ?></span></td>
              <td class="acts" data-l="">
                <?php if (can('resellers.level')): ?>
                  <a class="btn btn-sm btn-ghost" href="index.php?p=resellers&tab=svc&svc=<?= h(urlencode((string)$bd['id'])) ?>">✏️ ویرایش</a>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="svctoggle">
                    <input type="hidden" name="bundle_id" value="<?= h((string)$bd['id']) ?>">
                    <button class="btn btn-sm"><?= !empty($bd['active']) ? '⏸ غیرفعال' : '▶️ فعال' ?></button>
                  </form>
                  <form method="post" style="display:inline" data-confirm="این سرویس حذف شود؟">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="svcdel">
                    <input type="hidden" name="bundle_id" value="<?= h((string)$bd['id']) ?>">
                    <button class="btn btn-sm btn-danger">🗑</button>
                  </form>
                <?php else: ?>—<?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'opt'): ?>

  <?php if (!can('resellers.price')): ?>
    <?= denyBox('تنظیم امکانات نمایندگی برای شما فعال نیست.') ?>
  <?php else:
    $tgAccess = [
        ['rs_allow_renew',  '🔄', 'تمدید سرویس',          'دکمهٔ تمدید در پنل نماینده نمایش داده می‌شود.',   Reseller::allowRenew()],
        ['rs_allow_edit',   '✏️', 'ویرایش حجم و مدت',     'افزایش حجم یا روزهای سرویس توسط خود نماینده.', Reseller::allowEdit()],
        ['rs_allow_delete', '🗑', 'حذف سرویس و عودت',    'حذف کانفیگ و بازگشت سهم مصرف‌نشده به کیف پول.', Reseller::allowDelete()],
        ['rs_allow_rename', '🏷', 'تغییر نام کانفیگ',     'اجازهٔ تغییر نام کاربری کانفیگ‌های ساخته‌شده.', Reseller::allowRename()],
        ['rs_allow_test',   '🧪', 'سرویس تست رایگان',     'ساخت کانفیگ تست بدون کسر هزینه.',            Reseller::allowTest()],
        ['rs_show_price',   '💵', 'نمایش قیمت خرید',      'نماینده بهای تمام‌شدهٔ هر کانفیگ را می‌بیند.',   Reseller::showPrice()],
        ['rs_hide_panel',   '🕶', 'پنهان‌کردن نام سرور',  'نام و لوکیشن پنل از دید نماینده مخفی می‌ماند.',  Reseller::hidePanel()],
    ];
    $tgSafe = [
        ['rs_wallet_only',  '👛', 'پرداخت فقط از کیف پول', 'خرید مستقیم بسته می‌شود؛ اول باید کیف پول را شارژ کند.', Reseller::walletOnly()],
        ['rs_auto_suspend', '⛔️', 'تعلیق خودکار بدهکار',   'با عبور از سقف بدهی، ساخت سرویس بسته می‌شود.',       Reseller::autoSuspend()],
        ['rs_force_verify', '🪪', 'اجبار احراز هویت',       'تا کاربر احراز نشود، اجازهٔ ساخت سرویس ندارد.',      Reseller::forceVerify()],
    ];
    $onCnt = 0;
    foreach (array_merge($tgAccess, $tgSafe) as $tRow) { if ($tRow[4]) $onCnt++; }
    $allCnt = count($tgAccess) + count($tgSafe);
    $capTxt = function (int $v, string $suffix = '') { return $v > 0 ? fa_num($v) . $suffix : '∞'; };
  ?>

  <section class="rsx-hero">
    <span class="rsx-glow g1"></span><span class="rsx-glow g2"></span>
    <div class="rsx-htop">
      <div class="rsx-hic">🎛</div>
      <div class="rsx-htt">
        <h2>امکانات و سقف‌های پنل نمایندگان</h2>
        <p>دقیقاً مشخص کنید نماینده در ربات و مینی‌اپ چه کارهایی می‌تواند انجام دهد</p>
      </div>
      <div class="rsx-hact">
        <span class="badge b-blue"><?= fa_num($onCnt) ?> از <?= fa_num($allCnt) ?> گزینه فعال</span>
        <a class="btn btn-sm btn-ghost" href="index.php?p=resellers&tab=price">💵 تعرفه</a>
      </div>
    </div>
    <div class="rsx-cells">
      <div class="rsx-cell b"><span class="i">📦</span><span class="v"><?= $capTxt(Reseller::serviceCap()) ?></span><span class="l">سقف سرویس فعال</span></div>
      <div class="rsx-cell c"><span class="i">📅</span><span class="v"><?= $capTxt(Reseller::dailyLimit()) ?></span><span class="l">سقف ساخت روزانه</span></div>
      <div class="rsx-cell p"><span class="i">🗓</span><span class="v"><?= $capTxt(Reseller::monthLimit()) ?></span><span class="l">سقف ساخت ماهانه</span></div>
      <div class="rsx-cell g"><span class="i">💳</span><span class="v"><?= Reseller::minCharge() > 0 ? money(Reseller::minCharge()) : '—' ?></span><span class="l">حداقل شارژ</span></div>
      <div class="rsx-cell o"><span class="i">🌐</span><span class="v sm"><?= Reseller::subDomain() !== '' ? h(preg_replace('~^https?://~', '', Reseller::subDomain())) : 'پیش‌فرض' ?></span><span class="l">دامنهٔ ساب</span></div>
      <div class="rsx-cell r"><span class="i">💬</span><span class="v sm"><?= Reseller::supportId() !== '' ? '@' . h(Reseller::supportId()) : '—' ?></span><span class="l">پشتیبانی</span></div>
    </div>
  </section>

  <div class="rsx-nav" id="rsxNav" data-key="rsx_opt">
    <button type="button" class="rsx-pill on" data-sec="acc">🔓 دسترسی‌ها <span class="n"><?= fa_num(count($tgAccess)) ?></span></button>
    <button type="button" class="rsx-pill" data-sec="cap">📏 سقف‌ها</button>
    <button type="button" class="rsx-pill" data-sec="sec">🛡 مالی و امنیت</button>
    <button type="button" class="rsx-pill" data-sec="brand">🏷 برند و پیام‌ها</button>
  </div>

  <form method="post" class="card rsx-card" data-own="acc cap sec brand">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="rsopt">

    <div class="rsx-sec" data-sec="acc">
      <div class="card-head"><div>
        <div class="card-title">🔓 دسترسی‌های نماینده</div>
        <div class="card-sub">هر گزینه که خاموش شود، دکمهٔ مربوطه از پنل نماینده حذف می‌شود</div>
      </div></div>
      <div class="rsx-tgs">
        <?php foreach ($tgAccess as $tRow): ?>
          <label class="rsx-tg">
            <input type="checkbox" name="<?= h($tRow[0]) ?>" value="1" <?= $tRow[4] ? 'checked' : '' ?>>
            <span class="bx"></span>
            <span class="ic"><?= $tRow[1] ?></span>
            <span class="tx"><b><?= h($tRow[2]) ?></b><i><?= h($tRow[3]) ?></i></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="rsx-sec" data-sec="cap" hidden>
      <div class="card-head"><div>
        <div class="card-title">📏 سقف ساخت سرویس</div>
        <div class="card-sub">عدد ۰ یعنی بدون محدودیت</div>
      </div></div>
      <div class="form-grid g2">
        <div class="field"><label>سقف سرویس فعال هر نماینده</label>
          <div class="rsx-num"><input class="mono ltr" type="number" name="rs_service_cap" min="0" value="<?= (int)Reseller::serviceCap() ?>" data-zero="بی‌نهایت"><span class="z">بی‌نهایت</span></div>
          <div class="hint">مجموع کانفیگ‌های فعالی که همزمان می‌تواند داشته باشد.</div></div>
        <div class="field"><label>سقف ساخت در روز</label>
          <div class="rsx-num"><input class="mono ltr" type="number" name="rs_daily_limit" min="0" value="<?= (int)Reseller::dailyLimit() ?>" data-zero="بی‌نهایت"><span class="z">بی‌نهایت</span></div>
          <div class="hint">جلوی سوءاستفاده و ساخت انبوه را می‌گیرد.</div></div>
        <div class="field"><label>سقف ساخت در ماه</label>
          <div class="rsx-num"><input class="mono ltr" type="number" name="rs_month_limit" min="0" value="<?= (int)Reseller::monthLimit() ?>" data-zero="بی‌نهایت"><span class="z">بی‌نهایت</span></div></div>
        <div class="field"><label>حداقل مبلغ شارژ حساب (<?= h(currency()) ?>)</label>
          <div class="rsx-num"><input class="mono ltr" type="number" name="rs_min_charge" min="0" step="1000" value="<?= (int)Reseller::minCharge() ?>" data-zero="بدون محدودیت"><span class="z">بدون محدودیت</span></div></div>
      </div>
      <div class="rsx-note">ℹ️ سقف‌ها فقط روی ساخت سرویس توسط خود نماینده اعمال می‌شوند؛ ساخت دستی توسط مدیر محدود نمی‌شود.</div>
    </div>

    <div class="rsx-sec" data-sec="sec" hidden>
      <div class="card-head"><div>
        <div class="card-title">🛡 مالی و امنیت</div>
        <div class="card-sub">قوانین مالی و شرط لازم برای فعالیت نماینده</div>
      </div></div>
      <div class="rsx-tgs">
        <?php foreach ($tgSafe as $tRow): ?>
          <label class="rsx-tg">
            <input type="checkbox" name="<?= h($tRow[0]) ?>" value="1" <?= $tRow[4] ? 'checked' : '' ?>>
            <span class="bx"></span>
            <span class="ic"><?= $tRow[1] ?></span>
            <span class="tx"><b><?= h($tRow[2]) ?></b><i><?= h($tRow[3]) ?></i></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="rsx-note warn">⚠️ با فعال بودن «تعلیق خودکار»، نمایندهٔ بدهکار تا تسویهٔ حساب نمی‌تواند سرویس تازه بسازد.</div>
    </div>

    <div class="rsx-sec" data-sec="brand" hidden>
      <div class="card-head"><div>
        <div class="card-title">🏷 برند، پشتیبانی و متن‌ها</div>
        <div class="card-sub">دامنهٔ لینک ساب، آیدی پشتیبانی و متن‌های پنل نماینده</div>
      </div></div>
      <div class="form-grid g2">
        <div class="field"><label>دامنهٔ اختصاصی لینک ساب</label>
          <input class="mono ltr" type="text" name="rs_sub_domain" dir="ltr" placeholder="https://sub.example.com" value="<?= h(Reseller::subDomain()) ?>">
          <div class="hint">خالی = همان دامنهٔ پنل اصلی</div></div>
        <div class="field"><label>شناسهٔ پشتیبانی نمایندگان</label>
          <input class="mono ltr" type="text" name="rs_support_id" dir="ltr" placeholder="@support" value="<?= h(Reseller::supportId()) ?>">
          <div class="hint">بدون @ هم قبول است</div></div>
      </div>
      <div class="field"><label>پیام خوش‌آمد پنل نمایندگی <span class="rsx-cnt" data-for="rsWel">۰</span></label>
        <textarea id="rsWel" name="rs_welcome" rows="3" maxlength="800" placeholder="متنی که بالای پنل نماینده دیده می‌شود"><?= h(Reseller::welcome()) ?></textarea></div>
      <div class="field"><label>قوانین و مقررات نمایندگی <span class="rsx-cnt" data-for="rsTos">۰</span></label>
        <textarea id="rsTos" name="rs_tos" rows="5" maxlength="3000" placeholder="پیش از ثبت درخواست نمایندگی به کاربر نشان داده می‌شود"><?= h(Reseller::tos()) ?></textarea>
        <div class="hint">خالی = مرحلهٔ پذیرش قوانین نمایش داده نمی‌شود.</div></div>
    </div>

    <div class="sticky-acts"><button class="btn btn-primary">💾 ذخیره امکانات پنل نمایندگان</button></div>
  </form>

  <script>
  (function () {
    var q = function (s, r) { return [].slice.call((r || document).querySelectorAll(s)); };
    var fmt = function (n) { try { return (n || 0).toLocaleString('fa-IR'); } catch (e) { return String(n); } };
    var num = function (id) { var el = document.getElementById(id); return el ? Math.max(0, parseInt(el.value || '0', 10) || 0) : 0; };

    /* ---- جابجایی بخش‌ها ---- */
    var nav = document.getElementById('rsxNav');
    if (nav) {
      var key = nav.getAttribute('data-key') || 'rsx';
      var pills = q('.rsx-pill', nav);
      var show = function (sec) {
        pills.forEach(function (p) { p.classList.toggle('on', p.getAttribute('data-sec') === sec); });
        q('.rsx-sec').forEach(function (s) { s.hidden = s.getAttribute('data-sec') !== sec; });
        q('[data-own]').forEach(function (c) {
          c.hidden = ((c.getAttribute('data-own') || '').split(/\s+/)).indexOf(sec) < 0;
        });
        try { localStorage.setItem(key, sec); } catch (e) {}
      };
      pills.forEach(function (p) {
        p.addEventListener('click', function () { show(p.getAttribute('data-sec')); });
      });
      var want = '';
      try { want = localStorage.getItem(key) || ''; } catch (e) {}
      if (location.hash.length > 1) want = location.hash.slice(1);
      if (!/^[a-z]+$/i.test(want) || !nav.querySelector('.rsx-pill[data-sec="' + want + '"]')) {
        want = pills.length ? pills[0].getAttribute('data-sec') : '';
      }
      if (want) show(want);
    }

    /* ---- ماشین‌حساب زنده ---- */
    var calc = function () {
      if (!document.getElementById('cp0')) return;
      var gb = Math.max(1, num('cGb')), dy = Math.max(1, num('cDays'));
      var pg = num('fPgb'), pd = num('fPday'), fe = num('fPfee'), rd = Math.max(1, num('fPrnd'));
      var d1 = Math.min(90, num('fD1')), d2 = Math.min(90, num('fD2'));
      var base = (gb * pg) + (dy * pd) + fe;
      var one = function (d) {
        var f = Math.max(0, base - Math.round(base * d / 100));
        return rd > 1 ? Math.ceil(f / rd) * rd : f;
      };
      var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = v; };
      set('cp0', fmt(one(0))); set('cp1', fmt(one(d1))); set('cp2', fmt(one(d2)));
      set('cd1', fmt(d1)); set('cd2', fmt(d2));
      var br = document.getElementById('cBreak');
      if (br) {
        br.innerHTML = 'قیمت پایه <b>' + fmt(base) + '</b> = (' + fmt(gb) + ' × ' + fmt(pg) + ')'
          + ' + (' + fmt(dy) + ' × ' + fmt(pd) + ')' + (fe > 0 ? ' + ' + fmt(fe) : '')
          + ' • رند به <b>' + fmt(rd) + '</b>';
      }
    };
    q('.rsx-calcin').forEach(function (el) { el.addEventListener('input', calc); });
    ['cGb', 'cDays'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('input', calc);
    });
    q('[data-cq]').forEach(function (b) {
      b.addEventListener('click', function () {
        var p = (b.getAttribute('data-cq') || '').split(',');
        var g = document.getElementById('cGb'), d = document.getElementById('cDays');
        if (g) g.value = p[0]; if (d) d.value = p[1];
        q('[data-cq]').forEach(function (x) { x.classList.remove('on'); });
        b.classList.add('on');
        calc();
      });
    });
    calc();

    /* ---- نمایش «بی‌نهایت / رایگان» برای عدد صفر ---- */
    var zsync = function (el) {
      var box = el.closest('.rsx-num');
      if (!box) return;
      var v = parseInt(el.value || '0', 10) || 0;
      box.classList.toggle('zero', v === 0);
    };
    q('.rsx-num input[data-zero]').forEach(function (el) {
      zsync(el);
      el.addEventListener('input', function () { zsync(el); });
    });

    /* ---- شمارندهٔ کاراکتر متن‌ها ---- */
    q('.rsx-cnt[data-for]').forEach(function (c) {
      var ta = document.getElementById(c.getAttribute('data-for'));
      if (!ta) return;
      var upd = function () { c.textContent = fmt((ta.value || '').length); };
      ta.addEventListener('input', upd);
      upd();
    });
  })();
  </script>

  <?php endif; ?>

<?php elseif ($tab === 'price'): ?>

  <?php if (!can('resellers.price')): ?>
    <?= denyBox('تعیین تعرفه نمایندگی برای شما فعال نیست.') ?>
  <?php else:
    $panels  = DB::all('SELECT id, name, active FROM {p}panels ORDER BY sort ASC, id ASC');
    $onIds   = array_values(array_filter(array_map('intval', preg_split('/[^0-9]+/', (string)DB::setting('rs_panels', '')) ?: [])));
    $delMode = (string)DB::setting('rs_del_mode', 'fair');

    /* توجه: جدول users ستون name ندارد — نام از first_name/last_name ساخته می شود */
    $rsBotRows = [];
    try {
        $rsBotRows = DB::all("SELECT t.*, u.tg_id AS u_tg, u.username AS u_uname,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS u_name
            FROM {p}transactions t LEFT JOIN {p}users u ON u.id = t.user_id
            WHERE t.method = 'rsbot' ORDER BY t.id DESC LIMIT 40");
    } catch (Throwable $e) {
        if (function_exists('app_log')) app_log('admin', 'resellers/price: rsbot list failed', ['err' => $e->getMessage()]);
    }
    $rsBotPend = 0;
    foreach ($rsBotRows as $rb) { if ((string)$rb['status'] === 'pending') $rsBotPend++; }

    $rsOn   = (string)DB::setting('rs_enabled', '1') === '1';
    $pGbV   = (int)DB::setting('rs_price_gb', 2000);
    $pDayV  = (int)DB::setting('rs_price_day', 500);
    $pFeeV  = (int)DB::setting('rs_base_fee', 0);
    $pRndV  = max(1, (int)DB::setting('rs_round', 1000));
    $d1V    = (int)DB::setting('rs_l1_discount', 10);
    $d2V    = (int)DB::setting('rs_l2_discount', 20);
    $reqFee = (int)DB::setting('rs_req_fee', 0);
    $botOn  = (string)DB::setting('rs_bot_enabled', '0') === '1';
    $trashN = (class_exists('Svc') && method_exists('Svc', 'trashCount')) ? (int)Svc::trashCount(0) : 0;

    /* تعرفهٔ اختصاصی هر سرور */
    $pTar   = (class_exists('Reseller') && method_exists('Reseller', 'panelTariffs')) ? Reseller::panelTariffs() : [];
    $srvCnt = 0;
    foreach ($pTar as $tRow0) { if (!empty($tRow0['on'])) $srvCnt++; }

    $calcOne = function (int $gb, int $days, int $disc) use ($pGbV, $pDayV, $pFeeV, $pRndV): int {
        $base = ($gb * $pGbV) + ($days * $pDayV) + $pFeeV;
        $fin  = max(0, $base - (int)round($base * $disc / 100));
        return $pRndV > 1 ? (int)(ceil($fin / $pRndV) * $pRndV) : $fin;
    };
  ?>

  <section class="rsx-hero">
    <span class="rsx-glow g1"></span><span class="rsx-glow g2"></span>
    <div class="rsx-htop">
      <div class="rsx-hic">💵</div>
      <div class="rsx-htt">
        <h2>تعرفه و تنظیمات نمایندگی</h2>
        <p>قیمت پایه از حجم و مدت ساخته می‌شود، سپس تخفیف سطح اعمال و در نهایت رند می‌گردد</p>
      </div>
      <div class="rsx-hact">
        <span class="badge <?= $rsOn ? 'b-green' : 'b-gray' ?>"><?= $rsOn ? '● بخش نمایندگی فعال' : '○ غیرفعال' ?></span>
        <a class="btn btn-sm btn-ghost" href="index.php?p=resellers&tab=opt">🎛 امکانات</a>
      </div>
    </div>
    <div class="rsx-cells">
      <div class="rsx-cell b"><span class="i">💽</span><span class="v"><?= money($pGbV) ?></span><span class="l">هر گیگابایت</span></div>
      <div class="rsx-cell c"><span class="i">📆</span><span class="v"><?= money($pDayV) ?></span><span class="l">هر روز</span></div>
      <div class="rsx-cell g"><span class="i">🥈</span><span class="v"><?= fa_num($d1V) ?>٪</span><span class="l">تخفیف سطح ۱</span></div>
      <div class="rsx-cell o"><span class="i">🥇</span><span class="v"><?= fa_num($d2V) ?>٪</span><span class="l">تخفیف سطح ۲</span></div>
      <div class="rsx-cell p"><span class="i">🏦</span><span class="v"><?= money((int)DB::setting('rs_l2_credit', 500000)) ?></span><span class="l">سقف بدهی سطح ۲</span></div>
      <div class="rsx-cell r"><span class="i">📝</span><span class="v"><?= $reqFee > 0 ? money($reqFee) : 'رایگان' ?></span><span class="l">هزینهٔ فعال‌سازی</span></div>
      <div class="rsx-cell g"><span class="i">🌍</span><span class="v"><?= fa_num($srvCnt) ?></span><span class="l">سرور با قیمت اختصاصی</span></div>
    </div>
  </section>

  <div class="rsx-nav" id="rsxNav" data-key="rsx_price">
    <button type="button" class="rsx-pill on" data-sec="price">💰 قیمت‌گذاری</button>
    <button type="button" class="rsx-pill" data-sec="level">🎖 سطح‌ها و اعتبار</button>
    <button type="button" class="rsx-pill" data-sec="range">📏 محدوده و سرورها</button>
    <button type="button" class="rsx-pill" data-sec="srv">🌍 قیمت سرورها<?= $srvCnt ? ' <span class="n">' . fa_num($srvCnt) . '</span>' : '' ?></button>
    <button type="button" class="rsx-pill" data-sec="req">📝 درخواست نمایندگی</button>
    <button type="button" class="rsx-pill" data-sec="bot">🤖 ربات اختصاصی<?= $rsBotPend ? ' <span class="n">' . fa_num($rsBotPend) . '</span>' : '' ?></button>
    <button type="button" class="rsx-pill" data-sec="refund">↩️ عودت و سطل زباله</button>
    <button type="button" class="rsx-pill" data-sec="pay">🏦 درگاه و پرداخت</button>
  </div>

  <form method="post" class="card rsx-card" data-own="price level range">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="tariff">

    <div class="rsx-sec" data-sec="price">
      <div class="card-head"><div>
        <div class="card-title">💰 قیمت‌گذاری پایه</div>
        <div class="card-sub">فرمول: (حجم × قیمت گیگ) + (روز × قیمت روز) + هزینهٔ ثابت ← تخفیف سطح ← رند</div>
      </div></div>

      <label class="rsx-master<?= $rsOn ? ' on' : '' ?>">
        <input type="checkbox" name="rs_enabled" value="1" <?= $rsOn ? 'checked' : '' ?>>
        <span class="bx"></span>
        <span class="tx"><b>بخش نمایندگی فعال باشد</b><i>با خاموش بودن، نه ربات و نه مینی‌اپ بخش نمایندگی را نشان نمی‌دهند.</i></span>
      </label>

      <div class="form-grid g2 mt3">
        <div class="field"><label>قیمت هر گیگابایت (<?= h(currency()) ?>)</label>
          <input id="fPgb" class="mono ltr rsx-calcin" type="number" name="rs_price_gb" min="0" step="500" value="<?= $pGbV ?>"></div>
        <div class="field"><label>قیمت هر روز (<?= h(currency()) ?>)</label>
          <input id="fPday" class="mono ltr rsx-calcin" type="number" name="rs_price_day" min="0" step="100" value="<?= $pDayV ?>"></div>
        <div class="field"><label>هزینهٔ ثابت هر کانفیگ (<?= h(currency()) ?>)</label>
          <input id="fPfee" class="mono ltr rsx-calcin" type="number" name="rs_base_fee" min="0" step="500" value="<?= $pFeeV ?>">
          <div class="hint">به قیمت نهایی اضافه می‌شود و در عودت وجه برنمی‌گردد.</div></div>
        <div class="field"><label>رند کردن قیمت به</label>
          <input id="fPrnd" class="mono ltr rsx-calcin" type="number" name="rs_round" min="1" step="500" value="<?= $pRndV ?>">
          <div class="hint">مثلاً ۱۰۰۰ یعنی قیمت به بالا رند شود.</div></div>
      </div>

      <div class="rsx-calc">
        <div class="hd"><span>🧮 ماشین‌حساب زندهٔ تعرفه</span><em>با تغییر هر عدد، نتیجه همینجا به‌روز می‌شود</em></div>
        <div class="in">
          <div class="f"><label>حجم (گیگ)</label><input id="cGb" class="mono ltr" type="number" min="1" value="30"></div>
          <div class="f"><label>مدت (روز)</label><input id="cDays" class="mono ltr" type="number" min="1" value="30"></div>
          <div class="qs">
            <button type="button" class="chip" data-cq="10,30">۱۰گ ۳۰روز</button>
            <button type="button" class="chip" data-cq="30,30">۳۰گ ۳۰روز</button>
            <button type="button" class="chip" data-cq="60,60">۶۰گ ۶۰روز</button>
            <button type="button" class="chip" data-cq="100,90">۱۰۰گ ۹۰روز</button>
          </div>
        </div>
        <div class="res">
          <div class="r"><span class="t">👤 کاربر عادی</span><b id="cp0"><?= money($calcOne(30, 30, 0)) ?></b><i>بدون تخفیف</i></div>
          <div class="r s1"><span class="t">🥈 سطح ۱</span><b id="cp1"><?= money($calcOne(30, 30, $d1V)) ?></b><i><span id="cd1"><?= fa_num($d1V) ?></span>٪ تخفیف</i></div>
          <div class="r s2"><span class="t">🥇 سطح ۲</span><b id="cp2"><?= money($calcOne(30, 30, $d2V)) ?></b><i><span id="cd2"><?= fa_num($d2V) ?></span>٪ تخفیف</i></div>
        </div>
        <div class="br" id="cBreak"></div>
      </div>
    </div>

    <div class="rsx-sec" data-sec="level" hidden>
      <div class="card-head"><div>
        <div class="card-title">🎖 سطح‌های نمایندگی</div>
        <div class="card-sub">تخفیف هر سطح و سقف بدهی مجاز سطح ۲</div>
      </div></div>

      <div class="rsx-lvs">
        <div class="rsx-lv s1">
          <div class="hd"><span class="ic">🥈</span><div><b>سطح ۱ — نقره‌ای</b><i>فقط با موجودی مثبت کار می‌کند</i></div></div>
          <div class="field"><label>تخفیف سطح ۱ (٪)</label>
            <input id="fD1" class="mono ltr rsx-calcin" type="number" name="rs_l1_discount" min="0" max="90" value="<?= $d1V ?>"></div>
          <div class="ft">سقف بدهی: <b>ندارد</b> — هرگز بدهکار نمی‌شود</div>
        </div>
        <div class="rsx-lv s2">
          <div class="hd"><span class="ic">🥇</span><div><b>سطح ۲ — طلایی</b><i>مجاز به بدهکاری تا سقف تعیین‌شده</i></div></div>
          <div class="field"><label>تخفیف سطح ۲ (٪)</label>
            <input id="fD2" class="mono ltr rsx-calcin" type="number" name="rs_l2_discount" min="0" max="90" value="<?= $d2V ?>"></div>
          <div class="field"><label>سقف بدهی مجاز (<?= h(currency()) ?>)</label>
            <input class="mono ltr" type="number" name="rs_l2_credit" min="0" step="50000" value="<?= (int)DB::setting('rs_l2_credit', 500000) ?>"></div>
          <div class="ft">برای هر نماینده می‌توانید سقف اختصاصی هم تعریف کنید.</div>
        </div>
      </div>

      <div class="form-grid g2 mt3">
        <div class="field"><label>محدودیت IP هر کانفیگ</label>
          <div class="rsx-num"><input class="mono ltr" type="number" name="rs_ip_limit" min="0" value="<?= (int)DB::setting('rs_ip_limit', 2) ?>" data-zero="بی‌نهایت"><span class="z">بی‌نهایت</span></div>
          <div class="hint">روی کانفیگ‌های ساخته‌شده توسط نمایندگان اعمال می‌شود.</div></div>
      </div>
      <div class="rsx-note">ℹ️ تخفیف اختصاصی هر نماینده (در صفحهٔ پروفایل او) بر تخفیف سطح اولویت دارد.</div>
    </div>

    <div class="rsx-sec" data-sec="range" hidden>
      <div class="card-head"><div>
        <div class="card-title">📏 محدودهٔ مجاز سفارش</div>
        <div class="card-sub">کمترین و بیشترین حجم و مدتی که نماینده می‌تواند انتخاب کند</div>
      </div></div>
      <div class="form-grid g2">
        <div class="field"><label>حداقل حجم (گیگابایت)</label>
          <input class="mono ltr" type="number" name="rs_min_gb" min="0" value="<?= (int)DB::setting('rs_min_gb', 1) ?>"></div>
        <div class="field"><label>حداکثر حجم (گیگابایت)</label>
          <input class="mono ltr" type="number" name="rs_max_gb" min="0" value="<?= (int)DB::setting('rs_max_gb', 500) ?>"></div>
        <div class="field"><label>حداقل مدت (روز)</label>
          <input class="mono ltr" type="number" name="rs_min_days" min="0" value="<?= (int)DB::setting('rs_min_days', 1) ?>"></div>
        <div class="field"><label>حداکثر مدت (روز)</label>
          <input class="mono ltr" type="number" name="rs_max_days" min="0" value="<?= (int)DB::setting('rs_max_days', 365) ?>"></div>
      </div>

      <div class="section-title mt3">🖥 سرورهای مجاز نمایندگی</div>
      <?php if (!$panels): ?>
        <div class="empty sm"><div class="ic">🖥</div>هنوز سروری ثبت نشده است.</div>
      <?php else: ?>
        <div class="pick-row">
          <?php foreach ($panels as $pRow): ?>
            <label class="pick"><input type="checkbox" name="panels[]" value="<?= (int)$pRow['id'] ?>"
              <?= in_array((int)$pRow['id'], $onIds, true) ? 'checked' : '' ?>>
              <span><?= h((string)$pRow['name']) ?><?= (int)$pRow['active'] === 1 ? '' : ' (غیرفعال)' ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="hint mt2">اگر هیچ سروری انتخاب نشود، همهٔ سرورهای فعال در دسترس نمایندگان خواهند بود.</div>
      <?php endif; ?>

      <div class="field mt3"><label>📝 یادداشت برای نمایندگان <span class="rsx-cnt" data-for="rsNote">۰</span></label>
        <textarea id="rsNote" name="rs_note" rows="3" maxlength="1000" placeholder="این متن در پنل نمایندگی نمایش داده می‌شود."><?= h((string)DB::setting('rs_note', '')) ?></textarea></div>
    </div>

    <div class="sticky-acts"><button class="btn btn-primary">💾 ذخیره تعرفه عمومی</button></div>
  </form>

  <?php
    /* ---------- تعرفهٔ اختصاصی هر سرور ---------- */
    $numF = [
        ['gb',       '💽 قیمت هر گیگ',      'عمومی'],
        ['day',      '📆 قیمت هر روز',      'عمومی'],
        ['fee',      '➕ هزینهٔ ثابت',       'عمومی'],
        ['mul',      '✖️ ضریب قیمت (٪)',    ''],
        ['off',      '🎁 تخفیف ویژه (٪)',    'بدون'],
        ['min_gb',   '⬇️ حداقل حجم (گیگ)',  'عمومی'],
        ['max_gb',   '⬆️ حداکثر حجم (گیگ)', 'عمومی'],
        ['min_days', '⬇️ حداقل مدت (روز)',  'عمومی'],
        ['max_days', '⬆️ حداکثر مدت (روز)', 'عمومی'],
    ];
  ?>
  <form method="post" class="card rsx-card" data-own="srv" hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="rspanel">

    <div class="rsx-sec" data-sec="srv" hidden>
      <div class="card-head"><div>
        <div class="card-title">🌍 قیمت اختصاصی هر سرور</div>
        <div class="card-sub">برای هر لوکیشن (ترکیه، آلمان، مولتی‌لوکیشن و …) قیمت، ضریب، تخفیف و محدودهٔ جداگانه تعیین کنید</div>
      </div></div>

      <div class="rsx-steps">
        <span class="st"><b>۱</b> سرور را روشن کنید</span>
        <span class="st"><b>۲</b> قیمت گیگ و روز را بنویسید</span>
        <span class="st"><b>۳</b> ضریب یا تخفیف ویژه بدهید</span>
        <span class="st"><b>۴</b> ذخیره کنید</span>
      </div>

      <div class="rsx-note">
        اولویت قیمت: <b>تعرفهٔ اختصاصی خودِ نماینده</b> ← <b>تعرفهٔ همین سرور</b> ← <b>تعرفهٔ عمومی</b>.
        مقدار «۰» یعنی همان مقدارِ تعرفهٔ عمومی استفاده شود.
        فرمول: (حجم × قیمت گیگ) + (روز × قیمت روز) + هزینهٔ ثابت ← × ضریب ← تخفیف سطح + تخفیف ویژه ← رند.
      </div>

      <?php if (!$panels): ?>
        <div class="empty sm"><div class="ic">🖥</div>هنوز سروری ثبت نشده است.
          <div class="hint mt2">ابتدا از صفحهٔ <a href="index.php?p=panels">🖥 پنل‌ها</a> سرور اضافه کنید.</div>
        </div>
      <?php else: ?>
        <div class="rsx-srvs">
          <?php foreach ($panels as $pRow):
              $pid  = (int)$pRow['id'];
              $pnm  = (string)$pRow['name'];
              $t    = $pTar[$pid] ?? [];
              $on   = !empty($t['on']);
              $lbl  = (string)($t['label'] ?? '');
              $note = (string)($t['note'] ?? '');
              $lv   = (int)($t['lv'] ?? 0);
          ?>
            <div class="rsx-srv<?= $on ? ' on' : '' ?>" data-srv>
              <div class="rsx-srv-h">
                <label class="rsx-tg">
                  <input type="checkbox" name="pt[<?= $pid ?>][on]" value="1" <?= $on ? 'checked' : '' ?> data-f="on">
                  <span class="bx"></span>
                  <span class="ic">🖥</span>
                  <span class="tx"><b><?= h($pnm) ?></b><i>شناسه <?= fa_num($pid) ?><?= (int)$pRow['active'] === 1 ? '' : ' · غیرفعال' ?></i></span>
                </label>
                <span class="rsx-srv-p">🧮 ۳۰ گیگ / ۳۰ روز: <b data-out>—</b> <?= currency() ?></span>
              </div>

              <div class="rsx-srv-g">
                <div class="field rsx-srv-w"><label>🏷 برچسب نمایشی برای نماینده</label>
                  <input type="text" name="pt[<?= $pid ?>][label]" maxlength="40"
                    value="<?= h($lbl) ?>" placeholder="<?= h($pnm) ?>">
                  <div class="hint">مثلاً «🇹🇷 ترکیه – پرسرعت» یا «🌐 مولتی لوکیشن»</div>
                </div>

                <?php foreach ($numF as $nf):
                    $k   = $nf[0];
                    $val = (int)($t[$k] ?? ($k === 'mul' ? 100 : 0));
                ?>
                  <div class="field"><label><?= $nf[1] ?></label>
                    <div class="rsx-num">
                      <input class="mono ltr" type="number" min="0" name="pt[<?= $pid ?>][<?= $k ?>]"
                        value="<?= $val ?>" data-f="<?= $k ?>"<?= $nf[2] !== '' ? ' data-zero="' . h($nf[2]) . '"' : '' ?>>
                      <?php if ($nf[2] !== ''): ?><span class="z"><?= h($nf[2]) ?></span><?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>

                <div class="field"><label>🎖 حداقل سطح نماینده</label>
                  <select name="pt[<?= $pid ?>][lv]">
                    <option value="0"<?= $lv === 0 ? ' selected' : '' ?>>همهٔ نمایندگان</option>
                    <option value="1"<?= $lv === 1 ? ' selected' : '' ?>>سطح ۱ و بالاتر</option>
                    <option value="2"<?= $lv === 2 ? ' selected' : '' ?>>فقط سطح ۲</option>
                  </select>
                  <div class="hint">این سرور فقط برای همین سطح‌ها دیده می‌شود.</div>
                </div>

                <div class="field rsx-srv-w"><label>📝 توضیح کوتاه (در مینی‌اپ دیده می‌شود)</label>
                  <input type="text" name="pt[<?= $pid ?>][note]" maxlength="160"
                    value="<?= h($note) ?>" placeholder="مثلاً مناسب گیمینگ و پینگ پایین">
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="hint mt2">💡 اگر سروری خاموش باشد، دقیقاً مطابق تعرفهٔ عمومی فروخته می‌شود.</div>
      <?php endif; ?>
    </div>

    <div class="sticky-acts"><button class="btn btn-primary">💾 ذخیره قیمت سرورها</button></div>
  </form>

  <script>
  (function () {
    var boxes = [].slice.call(document.querySelectorAll('[data-srv]'));
    if (!boxes.length) return;

    var G = <?= (int)$pGbV ?>, D = <?= (int)$pDayV ?>, F = <?= (int)$pFeeV ?>, R = <?= (int)$pRndV ?>;
    var fa = function (n) { try { return (n || 0).toLocaleString('fa-IR'); } catch (e) { return String(n); } };
    var vv = function (b, k) {
      var el = b.querySelector('[data-f="' + k + '"]');
      return el ? Math.max(0, parseInt(el.value || '0', 10) || 0) : 0;
    };

    var up = function (b) {
      var on = b.querySelector('[data-f="on"]');
      b.classList.toggle('on', !!(on && on.checked));

      var g = vv(b, 'gb') || G, d = vv(b, 'day') || D, f = vv(b, 'fee') || F;
      var m = vv(b, 'mul') || 100, o = Math.min(90, vv(b, 'off'));

      var base = Math.round(((30 * g) + (30 * d) + f) * m / 100);
      var fin  = Math.max(0, base - Math.round(base * o / 100));
      if (R > 1) fin = Math.ceil(fin / R) * R;

      var out = b.querySelector('[data-out]');
      if (out) out.textContent = fa(fin);
    };

    boxes.forEach(function (b) {
      up(b);
      [].slice.call(b.querySelectorAll('input,select')).forEach(function (el) {
        el.addEventListener('input',  function () { up(b); });
        el.addEventListener('change', function () { up(b); });
      });
    });
  })();
  </script>

  <form method="post" class="card rsx-card" data-own="req bot refund" hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="rsfee">

    <div class="rsx-sec" data-sec="req" hidden>
      <div class="card-head"><div>
        <div class="card-title">📝 درخواست نمایندگی از داخل ربات</div>
        <div class="card-sub">شرایط ثبت درخواست، هزینهٔ فعال‌سازی و سطح پیش‌فرض</div>
      </div></div>

      <div class="rsx-tgs">
        <label class="rsx-tg">
          <input type="checkbox" name="rs_requests_open" value="1" <?= (string)DB::setting('rs_requests_open', '1') === '1' ? 'checked' : '' ?>>
          <span class="bx"></span><span class="ic">📬</span>
          <span class="tx"><b>ثبت درخواست باز باشد</b><i>اگر بسته باشد، دکمهٔ درخواست پیام «فعلاً بسته است» می‌دهد.</i></span>
        </label>
        <label class="rsx-tg">
          <input type="checkbox" name="rs_req_auto" value="1" <?= (string)DB::setting('rs_req_auto', '0') === '1' ? 'checked' : '' ?>>
          <span class="bx"></span><span class="ic">⚡️</span>
          <span class="tx"><b>فعال‌سازی خودکار پس از پرداخت</b><i>غیرفعال = درخواست به تب درخواست‌ها می‌رود.</i></span>
        </label>
        <label class="rsx-tg">
          <input type="checkbox" name="rs_req_fee_credit" value="1" <?= (string)DB::setting('rs_req_fee_credit', '0') === '1' ? 'checked' : '' ?>>
          <span class="bx"></span><span class="ic">↩️</span>
          <span class="tx"><b>هزینه به نماینده برگردد</b><i>فعال = مبلغ فقط ودیعه است و پس از تایید بازمی‌گردد.</i></span>
        </label>
      </div>

      <div class="form-grid g2 mt3">
        <div class="field"><label>هزینهٔ فعال‌سازی (<?= h(currency()) ?>)</label>
          <div class="rsx-num"><input class="mono ltr" type="number" name="rs_req_fee" min="0" step="10000" value="<?= (int)DB::setting('rs_req_fee', 0) ?>" data-zero="رایگان"><span class="z">رایگان</span></div>
          <div class="hint">صفر = درخواست رایگان است.</div></div>
        <div class="field"><label>محل بازگشت مبلغ</label>
          <select name="rs_req_fee_target">
            <option value="balance" <?= (string)DB::setting('rs_req_fee_target', 'balance') === 'balance' ? 'selected' : '' ?>>👛 موجودی کیف پول</option>
            <option value="credit"  <?= (string)DB::setting('rs_req_fee_target', 'balance') === 'credit' ? 'selected' : '' ?>>🏷 اعتبار نمایندگی</option>
          </select></div>
        <div class="field"><label>سطح پیش‌فرض پس از تایید</label>
          <select name="rs_req_level">
            <option value="1" <?= (int)DB::setting('rs_req_level', 1) === 1 ? 'selected' : '' ?>>🥈 سطح ۱ — فقط با موجودی</option>
            <option value="2" <?= (int)DB::setting('rs_req_level', 1) === 2 ? 'selected' : '' ?>>🥇 سطح ۲ — مجاز به بدهکاری</option>
          </select></div>
        <div class="field"><label>درخواست‌های در صف بررسی</label>
          <div class="cellbox wide"><span><?= $reqCnt ? '⏳ ' . fa_num($reqCnt) . ' درخواست در انتظار' : '✅ صف خالی است' ?></span></div>
          <div class="hint"><a href="index.php?p=resellers&tab=req">رفتن به تب درخواست‌ها ←</a></div></div>
      </div>
    </div>

    <div class="rsx-sec" data-sec="bot" hidden>
      <div class="card-head"><div>
        <div class="card-title">🤖 ربات اختصاصی نمایندگان</div>
        <div class="card-sub">فروش ربات جداگانه به نماینده با توکن خودش</div>
      </div></div>

      <label class="rsx-master<?= $botOn ? ' on' : '' ?>">
        <input type="checkbox" name="rs_bot_enabled" value="1" <?= $botOn ? 'checked' : '' ?>>
        <span class="bx"></span>
        <span class="tx"><b>فروش ربات اختصاصی فعال باشد</b><i>نماینده از تب ربات داخل پنل خودش درخواست ثبت می‌کند.</i></span>
      </label>

      <div class="form-grid g2 mt3">
        <div class="field"><label>هزینهٔ راه‌اندازی ربات (<?= h(currency()) ?>)</label>
          <div class="rsx-num"><input class="mono ltr" type="number" name="rs_bot_price" min="0" step="10000" value="<?= (int)DB::setting('rs_bot_price', 0) ?>" data-zero="غیرفعال"><span class="z">غیرفعال</span></div>
          <div class="hint">مبلغ از کیف پول نماینده کسر می‌شود.</div></div>
        <div class="field"><label>حداکثر ربات برای هر نماینده</label>
          <input class="mono ltr" type="number" name="rs_bot_max" min="1" max="5" value="<?= (int)DB::setting('rs_bot_max', 1) ?>">
          <div class="hint">وب‌هوک هر ربات روی <code>rbot.php</code> با کلید امن اختصاصی تنظیم می‌شود.</div></div>
      </div>

      <label class="rsx-tg mt3">
        <input type="checkbox" name="rs_bot_auto" value="1" <?= (string)DB::setting('rs_bot_auto', '1') === '1' ? 'checked' : '' ?>>
        <span class="bx"></span><span class="ic">🤖</span>
        <span class="tx"><b>ساخت کاملاً خودکار</b><i>نماینده پس از پرداخت، توکن ربات و آیدی مالک را می‌دهد و ربات همان لحظه فعال می‌شود.</i></span>
      </label>

      <div class="field mt3"><label>توضیح نمایش‌داده‌شده به نماینده <span class="rsx-cnt" data-for="rsBotNote">۰</span></label>
        <textarea id="rsBotNote" name="rs_bot_note" rows="3" maxlength="600" placeholder="مثلاً: تحویل تا ۲۴ ساعت، شامل نصب روی دامنهٔ خودتان."><?= h((string)DB::setting('rs_bot_note', '')) ?></textarea></div>

      <?php $rsBots = class_exists('RsBot') ? RsBot::all(50) : []; ?>
      <?php if ($rsBots): ?>
        <div class="section-title mt3">🧩 ربات‌های ساخته‌شده (<?= fa_num(count($rsBots)) ?>)</div>
        <div class="tbl-wrap">
          <table class="responsive">
            <thead><tr><th>#</th><th>ربات</th><th>نماینده</th><th>مالک</th><th>وضعیت</th><th>کاربران</th><th>پیام‌ها</th><th>آخرین فعالیت</th></tr></thead>
            <tbody>
            <?php foreach ($rsBots as $rb): $rbSt = RsBot::stats($rb); $rbS = (string)($rb['status'] ?? ''); ?>
              <tr>
                <td data-l="#" class="mono"><?= fa_num((int)($rb['id'] ?? 0)) ?></td>
                <td data-l="ربات"><?= ((string)($rb['username'] ?? '') !== '')
                  ? '<a class="mono ltr" href="https://t.me/' . h((string)$rb['username']) . '" target="_blank" rel="noopener">@' . h((string)$rb['username']) . '</a>'
                  : '&mdash;' ?></td>
                <td data-l="نماینده" class="mono ltr"><?= (int)($rb['tg_id'] ?? 0) ?></td>
                <td data-l="مالک" class="mono ltr"><?= (int)($rb['owner_id'] ?? 0) ?></td>
                <td data-l="وضعیت"><span class="badge <?= $rbS === 'active' ? 'b-green' : ($rbS === 'pending' ? 'b-orange' : 'b-gray') ?>"><?= h((string)(RsBot::STATUS[$rbS] ?? $rbS)) ?></span>
                  <?php if ((string)($rb['err'] ?? '') !== ''): ?><div class="hint"><?= h(mb_substr((string)$rb['err'], 0, 110)) ?></div><?php endif; ?></td>
                <td data-l="کاربران" class="mono"><?= fa_num((int)($rbSt['users'] ?? 0)) ?></td>
                <td data-l="پیام‌ها" class="mono"><?= fa_num((int)($rb['updates'] ?? 0)) ?></td>
                <td data-l="آخرین فعالیت" class="muted" style="font-size:12px"><?= (string)($rb['last_at'] ?? '') !== '' ? h(to_jalali((string)$rb['last_at'], true)) : '&mdash;' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="rsx-sec" data-sec="refund" hidden>
      <div class="card-head"><div>
        <div class="card-title">↩️ عودت وجه و حذف کانفیگ</div>
        <div class="card-sub">قانون بازگشت سهم مصرف‌نشده و مدت نگهداری در سطل زباله</div>
      </div></div>

      <div class="rsx-tgs">
        <label class="rsx-tg">
          <input type="checkbox" name="rs_del_refund" value="1" <?= (string)DB::setting('rs_del_refund', '1') === '1' ? 'checked' : '' ?>>
          <span class="bx"></span><span class="ic">💰</span>
          <span class="tx"><b>عودت وجه هنگام حذف کانفیگ</b><i>سهم مصرف‌نشده به کیف پول نماینده برمی‌گردد.</i></span>
        </label>
        <label class="rsx-tg">
          <input type="checkbox" name="rs_shrink_refund" value="1" <?= (string)DB::setting('rs_shrink_refund', '0') === '1' ? 'checked' : '' ?>>
          <span class="bx"></span><span class="ic">📉</span>
          <span class="tx"><b>کاهش حجم هم عودت داشته باشد</b><i>اگر در ویرایش، حجم یا مدت کاهش یافت، مابه‌التفاوت برگردد.</i></span>
        </label>
      </div>

      <div class="form-grid g2 mt3">
        <div class="field"><label>روش محاسبهٔ مبلغ عودتی</label>
          <select name="rs_del_mode">
            <option value="fair"  <?= $delMode === 'fair'  ? 'selected' : '' ?>>منصفانه — کمترین سهم بین حجم و زمان (پیشنهادی)</option>
            <option value="split" <?= $delMode === 'split' ? 'selected' : '' ?>>میانگین — نیمی حجم، نیمی زمان</option>
            <option value="gb"    <?= $delMode === 'gb'    ? 'selected' : '' ?>>فقط حجم — بدون در نظر گرفتن زمان</option>
          </select>
          <div class="hint">مبلغ عودتی = بهای کانفیگ × سهم مصرف‌نشده؛ هرگز بیشتر از بهای کانفیگ نمی‌شود.</div></div>
        <div class="field"><label>کارمزد حذف (درصد از مبلغ عودتی)</label>
          <div class="rsx-num"><input class="mono ltr" type="number" name="rs_del_fee_pct" min="0" max="100" step="1" value="<?= (int)DB::setting('rs_del_fee_pct', 0) ?>" data-zero="عودت کامل"><span class="z">عودت کامل</span></div></div>
        <div class="field"><label>نگهداری کانفیگ‌های حذف‌شده (روز)</label>
          <input class="mono ltr" type="number" name="trash_days" min="1" max="90" value="<?= (int)DB::setting('trash_days', 7) ?>">
          <div class="hint">تا این تعداد روز قابل بازگردانی است، سپس خودکار کامل پاک می‌شود.</div></div>
        <div class="field"><label>وضعیت فعلی سطل زباله</label>
          <div class="cellbox wide"><span><?= $trashN > 0 ? '🗑 ' . fa_num($trashN) . ' کانفیگ در سطل زباله' : '✅ سطل زباله خالی است' ?></span></div></div>
      </div>
    </div>

    <div class="sticky-acts"><button class="btn btn-primary">💾 ذخیره تنظیمات</button></div>
  </form>

  <div class="card rsx-card" data-own="bot" hidden>
    <div class="card-head"><div>
      <div class="card-title">🧾 درخواست‌های ربات اختصاصی</div>
      <div class="card-sub"><?= fa_num(count($rsBotRows)) ?> درخواست • <?= fa_num($rsBotPend) ?> در انتظار تحویل</div>
    </div></div>

    <?php if (!$rsBotRows): ?>
      <div class="empty sm"><div class="ic">🤖</div>هنوز درخواستی برای ربات اختصاصی ثبت نشده است.</div>
    <?php else: ?>
      <div class="rsx-feed">
      <?php foreach ($rsBotRows as $rb): $rbs = (string)$rb['status'];
        $rbNm = trim((string)($rb['u_name'] ?? ''));
        if ($rbNm === '') { $rbU = trim((string)($rb['u_uname'] ?? '')); $rbNm = $rbU !== '' ? '@' . $rbU : 'کاربر ' . (int)($rb['user_id'] ?? 0); }
      ?>
        <div class="fi<?= $rbs === 'pending' ? ' hot' : '' ?>">
          <div class="ic"><?= $rbs === 'pending' ? '⏳' : ($rbs === 'approved' ? '✅' : '↩️') ?></div>
          <div class="bd">
            <div class="t1"><b><?= h($rbNm) ?></b> <span class="mono ltr muted"><?= h((string)($rb['u_tg'] ?? '')) ?></span>
              <span class="badge b-blue"><?= money((int)$rb['amount']) ?> <?= h(currency()) ?></span>
              <?php if ($rbs === 'pending'): ?><span class="badge b-orange">در انتظار تحویل</span>
              <?php elseif ($rbs === 'approved'): ?><span class="badge b-green">تحویل شد</span>
              <?php else: ?><span class="badge b-gray">رد شد</span><?php endif; ?>
            </div>
            <div class="t2 mono">#<?= fa_num((int)$rb['id']) ?> • <?= h(to_jalali((string)$rb['created_at'], true)) ?></div>
            <?php if ($rbs === 'pending'): ?>
              <form method="post" class="mt2">
                <?= csrf_field() ?>
                <input type="hidden" name="tx" value="<?= (int)$rb['id'] ?>">
                <input type="text" name="msg" maxlength="500" placeholder="پیام به نماینده (اختیاری) — مانند آدرس ربات و توکن">
                <div class="btn-row mt2">
                  <button class="btn btn-sm btn-primary" name="act" value="rsbot_ok">✅ تحویل شد</button>
                  <button class="btn btn-sm btn-ghost" name="act" value="rsbot_no" data-confirm="درخواست رد و مبلغ عودت داده شود؟">↩️ رد و عودت وجه</button>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card rsx-card" data-own="refund" hidden>
    <div class="card-head"><div>
      <div class="card-title">🧹 سطل زبالهٔ کانفیگ‌ها</div>
      <div class="card-sub">کانفیگ‌های حذف‌شده تا <?= fa_num((int)DB::setting('trash_days', 7)) ?> روز قابل بازگردانی هستند</div>
    </div></div>
    <div class="rsx-trash">
      <div class="nm"><span class="v"><?= fa_num($trashN) ?></span><span class="l">کانفیگ در سطل زباله</span></div>
      <div class="ac">
        <a class="btn btn-sm btn-ghost" href="index.php?p=services&trash=1">👁 مشاهده و بازگردانی</a>
        <?php if ($trashN > 0): ?>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="trash_purge">
            <button class="btn btn-sm btn-ghost danger" data-confirm="همهٔ کانفیگ‌های منقضی‌شدهٔ سطل زباله کامل پاک شوند؟ این کار برگشت‌پذیر نیست.">🧹 پاک‌سازی کامل</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card rsx-card" data-own="pay" hidden>
    <div class="card-head"><div>
      <div class="card-title">🏦 کارت و درگاه اختصاصی نمایندگان</div>
      <div class="card-sub">می‌توانید شماره کارت یا آدرس ارزی جداگانه فقط برای نمایندگان ثبت کنید</div>
    </div></div>
    <div class="rsx-steps">
      <div class="s"><span class="n">۱</span><div><b>ساخت درگاه</b><i>در صفحهٔ درگاه‌های پرداخت، یک درگاه تازه بسازید.</i></div></div>
      <div class="s"><span class="n">۲</span><div><b>محدود کردن به نمایندگان</b><i>گزینهٔ «نمایش برای» را روی <b>🏷 فقط نمایندگان</b> بگذارید.</i></div></div>
      <div class="s"><span class="n">۳</span><div><b>شارژ کیف پول</b><i>نماینده فقط همین درگاه را می‌بیند و واریزها جدا ثبت می‌شود.</i></div></div>
    </div>
    <div class="btn-row mt3">
      <a class="btn btn-sm btn-primary" href="index.php?p=gateways">🏦 درگاه‌های پرداخت</a>
      <a class="btn btn-sm btn-ghost" href="index.php?p=cards">💳 احراز کارت</a>
      <a class="btn btn-sm btn-ghost" href="index.php?p=payments">🧾 تراکنش‌ها</a>
    </div>
  </div>

  <script>
  (function () {
    var q = function (s, r) { return [].slice.call((r || document).querySelectorAll(s)); };
    var fmt = function (n) { try { return (n || 0).toLocaleString('fa-IR'); } catch (e) { return String(n); } };
    var num = function (id) { var el = document.getElementById(id); return el ? Math.max(0, parseInt(el.value || '0', 10) || 0) : 0; };

    /* ---- جابجایی بخش‌ها ---- */
    var nav = document.getElementById('rsxNav');
    if (nav) {
      var key = nav.getAttribute('data-key') || 'rsx';
      var pills = q('.rsx-pill', nav);
      var show = function (sec) {
        pills.forEach(function (p) { p.classList.toggle('on', p.getAttribute('data-sec') === sec); });
        q('.rsx-sec').forEach(function (s) { s.hidden = s.getAttribute('data-sec') !== sec; });
        q('[data-own]').forEach(function (c) {
          c.hidden = ((c.getAttribute('data-own') || '').split(/\s+/)).indexOf(sec) < 0;
        });
        try { localStorage.setItem(key, sec); } catch (e) {}
      };
      pills.forEach(function (p) {
        p.addEventListener('click', function () { show(p.getAttribute('data-sec')); });
      });
      var want = '';
      try { want = localStorage.getItem(key) || ''; } catch (e) {}
      if (location.hash.length > 1) want = location.hash.slice(1);
      if (!/^[a-z]+$/i.test(want) || !nav.querySelector('.rsx-pill[data-sec="' + want + '"]')) {
        want = pills.length ? pills[0].getAttribute('data-sec') : '';
      }
      if (want) show(want);
    }

    /* ---- ماشین‌حساب زنده ---- */
    var calc = function () {
      if (!document.getElementById('cp0')) return;
      var gb = Math.max(1, num('cGb')), dy = Math.max(1, num('cDays'));
      var pg = num('fPgb'), pd = num('fPday'), fe = num('fPfee'), rd = Math.max(1, num('fPrnd'));
      var d1 = Math.min(90, num('fD1')), d2 = Math.min(90, num('fD2'));
      var base = (gb * pg) + (dy * pd) + fe;
      var one = function (d) {
        var f = Math.max(0, base - Math.round(base * d / 100));
        return rd > 1 ? Math.ceil(f / rd) * rd : f;
      };
      var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = v; };
      set('cp0', fmt(one(0))); set('cp1', fmt(one(d1))); set('cp2', fmt(one(d2)));
      set('cd1', fmt(d1)); set('cd2', fmt(d2));
      var br = document.getElementById('cBreak');
      if (br) {
        br.innerHTML = 'قیمت پایه <b>' + fmt(base) + '</b> = (' + fmt(gb) + ' × ' + fmt(pg) + ')'
          + ' + (' + fmt(dy) + ' × ' + fmt(pd) + ')' + (fe > 0 ? ' + ' + fmt(fe) : '')
          + ' • رند به <b>' + fmt(rd) + '</b>';
      }
    };
    q('.rsx-calcin').forEach(function (el) { el.addEventListener('input', calc); });
    ['cGb', 'cDays'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('input', calc);
    });
    q('[data-cq]').forEach(function (b) {
      b.addEventListener('click', function () {
        var p = (b.getAttribute('data-cq') || '').split(',');
        var g = document.getElementById('cGb'), d = document.getElementById('cDays');
        if (g) g.value = p[0]; if (d) d.value = p[1];
        q('[data-cq]').forEach(function (x) { x.classList.remove('on'); });
        b.classList.add('on');
        calc();
      });
    });
    calc();

    /* ---- نمایش «بی‌نهایت / رایگان» برای عدد صفر ---- */
    var zsync = function (el) {
      var box = el.closest('.rsx-num');
      if (!box) return;
      var v = parseInt(el.value || '0', 10) || 0;
      box.classList.toggle('zero', v === 0);
    };
    q('.rsx-num input[data-zero]').forEach(function (el) {
      zsync(el);
      el.addEventListener('input', function () { zsync(el); });
    });

    /* ---- شمارندهٔ کاراکتر متن‌ها ---- */
    q('.rsx-cnt[data-for]').forEach(function (c) {
      var ta = document.getElementById(c.getAttribute('data-for'));
      if (!ta) return;
      var upd = function () { c.textContent = fmt((ta.value || '').length); };
      ta.addEventListener('input', upd);
      upd();
    });
  })();
  </script>

  <?php endif; ?>

<?php elseif ($uSel):
  $u = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $uSel]);
  if (!$u) { echo '<div class="card"><div class="alert a-err">کاربر پیدا نشد.</div></div>'; return; }

  $lv = Reseller::level($u);
  $q  = Reseller::quota($u);
  $st = Reseller::stats($u);
  $svc = Reseller::services($u, 60);
?>

  <?php
  $rsHl  = method_exists('Reseller', 'health')
         ? Reseller::health($u)
         : ['debt' => max(0, -(int)$u['balance']), 'credit' => (int)Reseller::userCredit($u),
            'free' => 0, 'pct' => 0, 'key' => 'ok', 'label' => '', 'icon' => ''];
  $rsClr = method_exists('Reseller', 'levelColor') ? Reseller::levelColor($lv) : '#5B8CFF';
  $rsNm  = trim((string)($u['first_name'] ?? '')) !== '' ? trim((string)$u['first_name']) : 'کاربر';
  $rsIni = function_exists('mb_substr') ? mb_substr($rsNm, 0, 1) : substr($rsNm, 0, 1);
  $rsAt  = trim((string)($u['reseller_at'] ?? ''));
  ?>

  <div class="card mt3 rs-prof" style="--c:<?= h($rsClr) ?>">
    <div class="rsp-hero">
      <span class="rsp-glow g1"></span><span class="rsp-glow g2"></span>

      <div class="rsp-top">
        <div class="rsp-ava"><?= h($rsIni) ?></div>
        <div class="rsp-tt">
          <div class="nm"><?= h($rsNm) ?>
            <span class="lv"><?= h(Reseller::levelLabel($lv)) ?></span>
<?php if (Reseller::hasCustomTariff($u)): ?><span class="badge b-blue">تعرفهٔ اختصاصی</span><?php endif; ?>
<?php if ((int)$u['balance'] < 0): ?><span class="rsp-flag r">🔴 بدهکار</span><?php else: ?><span class="rsp-flag g">🟢 تسویه</span><?php endif; ?>
          </div>
          <div class="sub mono ltr"><?= !empty($u['username']) ? '@' . h((string)$u['username']) . ' · ' : '' ?><?= fa_num((string)$u['tg_id']) ?></div>
          <div class="ds"><?= h(method_exists('Reseller', 'levelDesc') ? Reseller::levelDesc($lv) : '') ?><?= $rsAt !== '' ? ' • نماینده از ' . fa_num(substr($rsAt, 0, 10)) : '' ?></div>
        </div>
        <div class="rsp-acts">
          <a class="btn btn-sm btn-ghost" href="index.php?p=users&amp;u=<?= (int)$u['id'] ?>">👤 پروفایل کامل</a>
          <a class="btn btn-sm btn-ghost" href="index.php?p=services&amp;user=<?= (int)$u['id'] ?>">📦 کانفیگ‌ها</a>
          <a class="btn btn-sm btn-ghost" href="index.php?p=tickets">🆘 تیکت‌ها</a>
          <a class="btn btn-sm btn-ghost" href="index.php?p=resellers">↩️ بازگشت</a>
        </div>
      </div>

      <div class="rsp-cells">
        <div class="rsp-cell <?= (int)$u['balance'] < 0 ? 'r' : 'g' ?>"><span class="i">💰</span>
          <span class="v"><?= money((int)$u['balance']) ?></span><span class="l">موجودی کیف پول</span></div>
        <div class="rsp-cell b"><span class="i">🧾</span>
          <span class="v"><?= h($q['avail_txt']) ?></span><span class="l">قابل استفاده</span></div>
        <div class="rsp-cell p"><span class="i">📦</span>
          <span class="v"><?= fa_num((int)$st['services']) ?></span><span class="l">کانفیگ ساخته</span></div>
        <div class="rsp-cell g"><span class="i">✅</span>
          <span class="v"><?= fa_num((int)($st['active'] ?? 0)) ?></span><span class="l">کانفیگ فعال</span></div>
        <div class="rsp-cell o"><span class="i">🛒</span>
          <span class="v"><?= h($st['spent_txt']) ?></span><span class="l">جمع خرید</span></div>
        <div class="rsp-cell c"><span class="i">📊</span>
          <span class="v"><?= h((string)($st['used_txt'] ?? '—')) ?></span><span class="l">ترافیک مصرفی</span></div>
      </div>
    </div>

    <?php if ((int)$rsHl['credit'] > 0 || (int)$rsHl['debt'] > 0): ?>
      <div class="rs-hbar <?= h((string)$rsHl['key']) ?>">
        <div class="t">
          <span><?= h(trim((string)$rsHl['icon'] . ' ' . (string)$rsHl['label'])) ?> — مصرف <?= fa_num((int)$rsHl['pct']) ?>٪ از سقف اعتبار</span>
          <b><?= money((int)$rsHl['debt']) ?> / <?= money((int)$rsHl['credit']) ?> • آزاد: <?= money((int)($rsHl['free'] ?? 0)) ?></b>
        </div>
        <div class="p"><i style="width:<?= max(2, min(100, (int)$rsHl['pct'])) ?>%"></i></div>
      </div>
    <?php endif; ?>

    <?php if ((int)$u['balance'] < 0): ?>
      <div class="alert a-warn mt3">⚠️ این نماینده در حال حاضر <b><?= money(-1 * (int)$u['balance']) ?></b> بدهکار است.</div>
    <?php endif; ?>
    <?php if ((int)($st['trash'] ?? 0) > 0): ?>
      <div class="alert a-info mt3">🗑 <?= fa_num((int)$st['trash']) ?> کانفیگ این نماینده در سطل زباله است.</div>
    <?php endif; ?>
  </div>

  <?php if (can('resellers.level')): ?>
  <form method="post" class="card mt3">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="setlevel">
    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">

    <div class="card-head"><div><div class="card-title">🎖 سطح و اعتبار</div>
      <div class="card-sub">سطح ۱ فقط با موجودی مثبت کار می‌کند؛ سطح ۲ می‌تواند تا سقف تعیین‌شده بدهکار شود.</div></div></div>

    <div class="form-grid g2">
      <div class="field"><label>سطح نمایندگی</label>
        <select name="level">
          <option value="0" <?= $lv === 0 ? 'selected' : '' ?>>👤 کاربر عادی (لغو نمایندگی)</option>
          <option value="1" <?= $lv === 1 ? 'selected' : '' ?>>🥈 نمایندگی سطح ۱ – فقط با موجودی</option>
          <option value="2" <?= $lv === 2 ? 'selected' : '' ?>>🥇 نمایندگی سطح ۲ – مجاز به بدهکاری</option>
        </select></div>

      <?php if (can('resellers.credit')): ?>
      <div class="field"><label>سقف بدهی اختصاصی (<?= h(currency()) ?>)</label>
        <input type="number" name="credit" value="<?= (int)($u['reseller_credit'] ?? 0) ?>">
        <div class="hint">صفر = استفاده از سقف پیش‌فرض سطح ۲.</div></div>
      <div class="field"><label>تخفیف اختصاصی (٪)</label>
        <input type="number" name="discount" min="0" max="90" value="<?= (int)($u['reseller_discount'] ?? 0) ?>">
        <div class="hint">صفر = استفاده از تخفیف پیش‌فرض سطح.</div></div>
      <?php endif; ?>
    </div>

    <div class="btn-row mt3"><button class="btn">💾 ذخیره سطح</button>
      <a class="btn btn-ghost" href="index.php?p=resellers">بازگشت به لیست</a></div>
  </form>
  <?php endif; ?>

  <?php if (can('resellers.level')): ?>
  <form method="post" class="card mt3">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="utariff">
    <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">

    <div class="card-head">
      <div><div class="card-title">💵 تعرفهٔ اختصاصی این نماینده</div>
        <div class="card-sub">عدد صفر یعنی از تعرفهٔ عمومی استفاده شود.</div></div>
      <span class="badge <?= Reseller::hasCustomTariff($u) ? 'b-blue' : 'b-gray' ?>">
        <?= Reseller::hasCustomTariff($u) ? 'تعرفهٔ اختصاصی' : 'تعرفهٔ عمومی' ?></span>
    </div>

    <div class="form-grid g2">
      <div class="field"><label>قیمت هر گیگابایت (<?= h(currency()) ?>)</label>
        <input type="number" name="price_gb" value="<?= (int)($u['reseller_price_gb'] ?? 0) ?>">
        <div class="hint">تعرفهٔ عمومی فعلی: <?= money(Reseller::priceGb()) ?></div></div>

      <div class="field"><label>قیمت هر روز (<?= h(currency()) ?>)</label>
        <input type="number" name="price_day" value="<?= (int)($u['reseller_price_day'] ?? 0) ?>">
        <div class="hint">تعرفهٔ عمومی فعلی: <?= money(Reseller::priceDay()) ?></div></div>

      <div class="field"><label>سقف حجم (گیگابایت)</label>
        <input type="number" name="max_gb" value="<?= (int)($u['reseller_max_gb'] ?? 0) ?>">
        <div class="hint">سقف عمومی: <?= fa_num(Reseller::maxGb()) ?> گیگ</div></div>

      <div class="field"><label>سقف مدت (روز)</label>
        <input type="number" name="max_days" value="<?= (int)($u['reseller_max_days'] ?? 0) ?>">
        <div class="hint">سقف عمومی: <?= fa_num(Reseller::maxDays()) ?> روز</div></div>

      <div class="field"><label>سرورهای مجاز (شناسه‌ها با کاما)</label>
        <input class="mono ltr" type="text" name="panels" value="<?= h((string)($u['reseller_panels'] ?? '')) ?>" placeholder="1,3">
        <div class="hint">خالی = همان فهرست عمومی نمایندگی.</div></div>

      <div class="field"><label>یادداشت داخلی</label>
        <input type="text" name="note" value="<?= h((string)($u['reseller_note'] ?? '')) ?>" placeholder="مثلاً قرارداد ویژه"></div>
    </div>

    <div class="btn-row mt3"><button class="btn btn-primary">💾 ذخیره تعرفهٔ اختصاصی</button></div>
  </form>
  <?php endif; ?>

  <?php if (can('resellers.level')): ?>
  <?php $mark = class_exists('Reseller') && method_exists('Reseller', 'markFor') ? Reseller::markFor($u) : ''; ?>
  <form method="post" class="card mt3">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="umark">
    <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">

    <div class="card-head">
      <div><div class="card-title">🏷 مارک نام کانفیگ</div>
        <div class="card-sub">این پیشوند به صورت خودکار ابتدای نام همهٔ کانفیگ‌های این نماینده قرار می‌گیرد.</div></div>
      <span class="badge <?= $mark !== '' ? 'b-blue' : 'b-gray' ?>"><?= $mark !== '' ? h($mark) : 'بدون مارک' ?></span>
    </div>

    <div class="form-grid g2">
      <div class="field">
        <label>مارک (فقط حروف و اعداد لاتین)</label>
        <input class="mono ltr" type="text" name="mark" maxlength="10" value="<?= h($mark) ?>" placeholder="sr">
        <div class="hint">خالی = بدون پیشوند – حداکثر ۱۰ کاراکتر</div>
      </div>
      <div class="field">
        <label>نمونهٔ نام خروجی</label>
        <div class="cellbox wide"><span class="mono"><?= h(($mark !== '' ? $mark . '_' : '') . 'myconfig') ?></span></div>
        <div class="hint">اگر نام واردشده از قبل با مارک شروع شود، دوباره اضافه نمی‌شود (رفع دابل‌نیم).</div>
      </div>
    </div>

    <div class="btn-row mt3"><button class="btn btn-primary">💾 ذخیره مارک</button></div>
  </form>
  <?php endif; ?>

  <?php $led = method_exists('Reseller', 'ledger') ? Reseller::ledger($u, 40) : []; ?>
  <div class="card mt3">
    <div class="card-head"><div><div class="card-title">📒 گردش مالی نماینده</div>
      <div class="card-sub">۴۰ تراکنش آخر</div></div></div>
    <?php if (!$led): ?>
      <div class="empty"><div class="ic">📒</div>تراکنشی ثبت نشده است.</div>
    <?php else: ?>
      <div class="table-wrap"><table class="responsive">
        <thead><tr><th>#</th><th>مبلغ</th><th>روش</th><th>وضعیت</th><th>توضیح</th><th>تاریخ</th></tr></thead>
        <tbody>
        <?php foreach ($led as $t): $am = (int)($t['amount'] ?? 0); ?>
          <tr>
            <td class="mono" data-l="#"><?= fa_num((int)($t['id'] ?? 0)) ?></td>
            <td class="mono" data-l="مبلغ">
              <span style="color:var(--<?= $am < 0 ? 'red' : 'green' ?>)"><?= money($am) ?></span></td>
            <td data-l="روش"><?= h((string)($t['method'] ?? '')) ?></td>
            <td data-l="وضعیت"><?= badge((string)($t['status'] ?? '')) ?></td>
            <td data-l="توضیح" style="max-width:280px"><?= h(mb_substr((string)($t['note'] ?? ''), 0, 90)) ?></td>
            <td class="muted" style="font-size:12px" data-l="تاریخ"><?= h(to_jalali((string)($t['created_at'] ?? ''), true)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
  </div>

  <div class="card mt3">
    <div class="card-head"><div><div class="card-title">📦 کانفیگ‌های ساختهٔ این نماینده</div></div></div>
    <?php if (!$svc): ?>
      <div class="empty"><div class="ic">📭</div>هنوز کانفیگی نساخته است.</div>
    <?php else: ?>
      <div class="table-wrap"><table class="responsive">
        <thead><tr><th>#</th><th>نام کاربری</th><th>حجم</th><th>انقضا</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
        <tbody>
        <?php foreach ($svc as $s): ?>
          <tr>
            <td class="mono" data-l="#"><?= fa_num((int)$s['id']) ?></td>
            <td class="mono" data-l="نام کاربری"><?= h((string)$s['client_email']) ?></td>
            <td data-l="حجم"><?= fa_num((string)$s['volume_gb']) ?> GB</td>
            <td data-l="انقضا"><?= h(remaining_human((string)$s['expire_at'])) ?></td>
            <td data-l="وضعیت"><?= badge((string)$s['status']) ?></td>
            <td class="muted" style="font-size:12px" data-l="تاریخ"><?= h(to_jalali((string)$s['created_at'], true)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
  </div>

<?php else: ?>

  <?php
  $rsCounts = method_exists('Reseller', 'configCounts') ? Reseller::configCounts() : [];
  $l1c = 0; $l2c = 0; $debtC = 0; $idleC = 0;
  foreach ($list as $r) {
      $lvX = (int)($r['reseller_level'] ?? 0);
      if ($lvX === 1) { $l1c++; } elseif ($lvX >= 2) { $l2c++; }
      if ((int)($r['balance'] ?? 0) < 0) $debtC++;
      if ((int)($rsCounts[(int)$r['id']]['total'] ?? 0) === 0) $idleC++;
  }
  ?>

  <div class="card mt3">
    <div class="rs-bar">
      <form method="get" class="rs-search">
        <input type="hidden" name="p" value="resellers">
        <?php if ($flv !== ''): ?><input type="hidden" name="lv" value="<?= h($flv) ?>"><?php endif; ?>
        <span>🔍</span>
        <input type="search" id="rsQ" name="q" value="<?= h($qs) ?>" autocomplete="off"
               placeholder="جستجوی نام، یوزرنیم یا آیدی عددی…">
      </form>

      <div class="rs-filt" id="rsFilt">
        <button type="button" class="on" data-f="all">همه <i><?= fa_num(count($list)) ?></i></button>
        <button type="button" data-f="1">🥈 سطح ۱ <i><?= fa_num($l1c) ?></i></button>
        <button type="button" data-f="2">🥇 سطح ۲ <i><?= fa_num($l2c) ?></i></button>
        <button type="button" data-f="debt">🧨 بدهکار <i><?= fa_num($debtC) ?></i></button>
        <button type="button" data-f="idle">💤 بی‌فعالیت <i><?= fa_num($idleC) ?></i></button>
      </div>

      <div class="rs-tools">
        <?php if ($qs !== '' || $flv !== ''): ?>
          <a class="btn btn-sm btn-ghost" href="index.php?p=resellers">♻️ پاک‌کردن فیلتر</a>
        <?php endif; ?>
        <?php if (can('resellers.level')): ?>
          <button type="button" class="btn btn-sm btn-primary" data-rs-add>➕ نمایندهٔ جدید</button>
        <?php endif; ?>
      </div>
    </div>

    <?php if (can('resellers.level')): ?>
      <form method="post" class="rs-add" id="rsAdd">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="setlevel">
        <div class="form-grid g2">
          <div class="field"><label>کاربر</label>
            <input type="text" name="user" placeholder="123456789 یا @username" required>
            <div class="hint">شناسهٔ عددی تلگرام، شناسهٔ داخلی کاربر یا نام کاربری.</div></div>
          <div class="field"><label>سطح نمایندگی</label>
            <select name="level">
              <option value="1">🥈 سطح ۱ – فقط با موجودی</option>
              <option value="2">🥇 سطح ۲ – مجاز به بدهکاری</option>
            </select>
            <div class="hint">سطح ۲ تا سقف تعیین‌شده می‌تواند بدهکار شود.</div></div>
        </div>
        <div class="btn-row mt3">
          <button class="btn btn-primary">➕ ثبت نماینده</button>
          <button type="button" class="btn btn-ghost" data-rs-add>انصراف</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <?php if (!$list): ?>
    <div class="card mt3"><div class="empty"><div class="ic">🏷</div>
      <?= $qs !== '' || $flv !== '' ? 'نتیجه‌ای برای این جستجو پیدا نشد.' : 'هنوز نماینده‌ای ثبت نشده است.' ?></div></div>
  <?php else: ?>
    <div class="rs-grid mt3" id="rsGrid">
      <?php foreach ($list as $r):
        $rid  = (int)$r['id'];
        $lvR  = (int)($r['reseller_level'] ?? 0);
        $balR = (int)($r['balance'] ?? 0);
        $hl   = method_exists('Reseller', 'health')
              ? Reseller::health($r)
              : ['debt' => max(0, -$balR), 'credit' => (int)Reseller::userCredit($r),
                 'pct' => 0, 'key' => 'ok', 'label' => '', 'icon' => ''];
        $cc   = $rsCounts[$rid] ?? ['total' => 0, 'active' => 0];
        $dsc  = (int)Reseller::userDiscount($r);
        $nm   = trim((string)($r['first_name'] ?? '')) !== '' ? trim((string)$r['first_name']) : 'کاربر';
        $un   = ltrim(trim((string)($r['username'] ?? '')), '@');
        $ini  = function_exists('mb_substr') ? mb_substr($nm, 0, 1) : substr($nm, 0, 1);
        $clr  = method_exists('Reseller', 'levelColor') ? Reseller::levelColor($lvR) : '#5B8CFF';
        $hay  = $nm . ' ' . $un . ' ' . (string)$r['tg_id'] . ' ' . $rid;
        $hay  = function_exists('mb_strtolower') ? mb_strtolower($hay) : strtolower($hay);
      ?>
        <article class="rs-card" style="--c:<?= h($clr) ?>"
                 data-lv="<?= $lvR ?>" data-debt="<?= $balR < 0 ? 1 : 0 ?>"
                 data-cfg="<?= (int)$cc['total'] ?>" data-q="<?= h($hay) ?>">
          <div class="top">
            <div class="ava"><?= h($ini) ?></div>
            <div class="tt">
              <a class="nm" href="index.php?p=resellers&u=<?= $rid ?>"><?= h($nm) ?></a>
              <div class="sub mono ltr"><?= $un !== '' ? '@' . h($un) . ' · ' : '' ?><?= fa_num((string)$r['tg_id']) ?></div>
            </div>
            <span class="lv"><?= h(Reseller::levelLabel($lvR)) ?></span>
          </div>

          <div class="nums">
            <div class="n"><span>موجودی</span>
              <b class="<?= $balR < 0 ? 'r' : ($balR > 0 ? 'g' : '') ?>"><?= money($balR) ?></b></div>
            <div class="n"><span>تخفیف</span>
              <b class="<?= $dsc > 0 ? 'g' : '' ?>"><?= fa_num($dsc) ?>٪</b></div>
            <div class="n"><span>کانفیگ</span>
              <b><?= fa_num((int)$cc['total']) ?></b><i><?= fa_num((int)$cc['active']) ?> فعال</i></div>
          </div>

          <?php if ((int)$hl['credit'] > 0 || (int)$hl['debt'] > 0): ?>
            <div class="bar <?= h((string)$hl['key']) ?>">
              <div class="t"><span><?= h(trim((string)$hl['icon'] . ' ' . (string)$hl['label'])) ?></span>
                <b><?= money((int)$hl['debt']) ?> / <?= money((int)$hl['credit']) ?></b></div>
              <div class="p"><i style="width:<?= max(2, min(100, (int)$hl['pct'])) ?>%"></i></div>
            </div>
          <?php else: ?>
            <div class="bar ok">
              <div class="t"><span>✅ بدون بدهی</span><b><?= h(Reseller::levelDesc($lvR)) ?></b></div>
            </div>
          <?php endif; ?>

          <div class="acts">
            <a class="btn btn-sm btn-primary" href="index.php?p=resellers&u=<?= $rid ?>">🎛 مدیریت</a>
            <a class="btn btn-sm btn-ghost" href="index.php?p=users&u=<?= $rid ?>">👤 پرونده</a>
            <a class="btn btn-sm btn-ghost" href="index.php?p=services&user=<?= $rid ?>">📦 کانفیگ‌ها</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <div class="rs-empty" id="rsEmpty" style="display:none">هیچ نماینده‌ای با این فیلتر پیدا نشد.</div>
  <?php endif; ?>

  <?php if ($topRs): ?>
    <div class="card mt3">
      <div class="card-head tight"><div>
        <div class="card-title">🏆 پرکارترین نمایندگان</div>
        <div class="card-sub">بر اساس تعداد کانفیگ ساخته‌شده</div>
      </div></div>

      <div class="rs-podium">
        <?php $rk = 0; foreach (array_slice($topRs, 0, 3) as $t): $rk++;
          $medal = $rk === 1 ? '🥇' : ($rk === 2 ? '🥈' : '🥉'); ?>
          <a class="p<?= $rk ?>" href="index.php?p=resellers&u=<?= (int)$t['id'] ?>">
            <span class="m"><?= $medal ?></span>
            <span class="n"><?= h((string)($t['first_name'] ?: 'کاربر')) ?></span>
            <span class="c"><?= fa_num((int)($t['cfg'] ?? 0)) ?> کانفیگ</span>
            <span class="b"><?= money((int)($t['balance'] ?? 0)) ?> <?= h(currency()) ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <?php if (count($topRs) > 3): ?>
        <div class="rs-rank mt3">
          <?php $rk = 3; foreach (array_slice($topRs, 3) as $t): $rk++; ?>
            <a href="index.php?p=resellers&u=<?= (int)$t['id'] ?>">
              <span class="i"><?= fa_num($rk) ?></span>
              <span class="n"><?= h((string)($t['first_name'] ?: 'کاربر')) ?></span>
              <span class="c"><?= fa_num((int)($t['cfg'] ?? 0)) ?> کانفیگ</span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <details class="card mt3">
    <summary style="cursor:pointer;font-weight:700">📤 خروجی CSV فهرست نمایندگان</summary>
    <div class="hint mt3">متن زیر را کپی کنید و در یک فایل با پسوند csv ذخیره کنید.</div>
    <textarea class="mono ltr mt3" rows="6" readonly onclick="this.select()"><?= h($csv) ?></textarea>
  </details>

  <script>
  (function () {
    var addBox = document.getElementById('rsAdd');
    Array.prototype.forEach.call(document.querySelectorAll('[data-rs-add]'), function (b) {
      b.addEventListener('click', function () {
        if (!addBox) return;
        addBox.classList.toggle('open');
        if (addBox.classList.contains('open')) {
          var f = addBox.querySelector('input[name="user"]');
          if (f) f.focus();
        }
      });
    });

    var grid = document.getElementById('rsGrid');
    if (!grid) return;
    var q = document.getElementById('rsQ'),
        filt = document.getElementById('rsFilt'),
        empt = document.getElementById('rsEmpty'),
        cards = [].slice.call(grid.querySelectorAll('.rs-card')),
        mode = 'all';

    function paint() {
      var s = (q && q.value ? q.value : '').trim().toLowerCase(), n = 0;
      cards.forEach(function (c) {
        var ok = true;
        if (mode === 'debt') ok = c.getAttribute('data-debt') === '1';
        else if (mode === 'idle') ok = c.getAttribute('data-cfg') === '0';
        else if (mode !== 'all') ok = c.getAttribute('data-lv') === mode;
        if (ok && s) ok = (c.getAttribute('data-q') || '').indexOf(s) > -1;
        c.style.display = ok ? '' : 'none';
        if (ok) n++;
      });
      if (empt) empt.style.display = n ? 'none' : 'block';
    }

    if (q) q.addEventListener('input', paint);
    if (filt) filt.addEventListener('click', function (e) {
      var b = e.target && e.target.closest ? e.target.closest('button[data-f]') : null;
      if (!b) return;
      mode = b.getAttribute('data-f');
      Array.prototype.forEach.call(filt.querySelectorAll('button'), function (x) {
        if (x === b) x.classList.add('on'); else x.classList.remove('on');
      });
      paint();
    });
    paint();
  })();
  </script>

<?php endif; ?>
