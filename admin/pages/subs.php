<?php
/**
 * SR-BOT — مدیریت ساب، تحویل و عملیات سرویس
 * نسخهٔ گسترده با گالری تم، پیش‌نمایش زنده و آپشن‌های دلخواه
 */

need('subs.view', 'subs');
$canEdit = can('subs.edit');

/* ==================== آپشن‌های داخلی ==================== */

$GROUPS = [
    'links' => [
        'icon'  => '🔗',
        'name'  => 'لینک اشتراک',
        'desc'  => 'شکل و نوع لینکی که به کاربر تحویل می‌شود.',
        'items' => [
            ['k' => 'sub_deliver', 'l' => 'چه لینکی تحویل داده شود؟', 't' => 'select', 'd' => 'local',
                'o' => ['local' => 'لینک ساب خودمان (پیشنهادی)', 'panel' => 'لینک ساب پنل', 'both' => 'هر دو لینک'],
                'n' => 'حالت «خودمان» کانفیگ‌ها را از پنل می‌خواند، نام‌گذاری می‌کند و سالم تحویل می‌دهد.'],
            ['k' => 'sub_local', 'l' => 'سرویس ساب داخلی روشن باشد', 't' => 'bool', 'd' => '1',
                'n' => 'اگر خاموش شود آدرس <code>/sub/</code> کار نمی‌کند.'],
            ['k' => 'sub_pretty', 'l' => 'لینک زیبا به شکل /sub/code', 't' => 'bool', 'd' => '1',
                'n' => 'اگر هاست شما بازنویسی آدرس (rewrite) ندارد، خاموش کنید.'],
            ['k' => 'sub_show_main', 'l' => 'نمایش لینک ساب پنل در کنار لینک ما', 't' => 'bool', 'd' => '1'],
            ['k' => 'sub_page_mode', 'l' => 'محتوای صفحهٔ ساب', 't' => 'select', 'd' => 'own',
                'o' => ['own' => 'فقط کانفیگ‌های همین سرویس', 'main' => 'همهٔ کانفیگ‌های ساب پنل'],
                'n' => 'حالت «فقط همین سرویس» جلوی دیده شدن کانفیگ دیگران را می‌گیرد.'],
            ['k' => 'sub_own_filter', 'l' => 'فیلتر سخت‌گیرانهٔ کانفیگ‌های سرویس', 't' => 'bool', 'd' => '1',
                'n' => 'اگر لینک ساب خالی تحویل شد، این گزینه را خاموش کنید.'],
            ['k' => 'sub_sync_ttl', 'l' => 'فاصلهٔ به‌روزرسانی مصرف (ثانیه)', 't' => 'int', 'd' => '120', 'min' => 0, 'max' => 3600],
            ['k' => 'rs_sync_ttl', 'l' => 'فاصلهٔ به‌روزرسانی مصرف نمایندگی (ثانیه)', 't' => 'int', 'd' => '150', 'min' => 0, 'max' => 3600],
        ],
    ],
    'naming' => [
        'icon'  => '🏷',
        'name'  => 'نام کانفیگ‌ها',
        'desc'  => 'اسمی که کاربر داخل v2rayN / v2rayNG می‌بیند.',
        'items' => [
            ['k' => 'sub_rename', 'l' => 'بازنویسی نام کانفیگ‌ها', 't' => 'bool', 'd' => '1',
                'n' => 'اگر خاموش شود، نام پنل دست‌نخورده تحویل می‌شود.'],
            ['k' => 'sub_tag', 'l' => 'برند/پیشوند دلخواه', 't' => 'text', 'd' => '',
                'n' => 'خالی = نام فروشگاه استفاده می‌شود.'],
            ['k' => 'sub_name_panel', 'l' => 'اضافه شدن نام سرور به انتهای نام', 't' => 'bool', 'd' => '1'],
            ['k' => 'sub_name_client', 'l' => 'اضافه شدن نام کانفیگ کاربر (مثل 1-708)', 't' => 'bool', 'd' => '1',
                'n' => 'با روشن بودن این گزینه، اسم واقعی کانفیگ در نام نمایشی می‌آید و کاربر گم نمی‌شود.'],
        ],
    ],
    'deliver' => [
        'icon'  => '📩',
        'name'  => 'پیام تحویل کانفیگ',
        'desc'  => 'ارسال پیام «سرویس شما آماده است» در تلگرام.',
        'items' => [
            ['k' => 'deliver_msg_miniapp', 'l' => 'ارسال پیام تحویل برای خرید از مینی‌اپ', 't' => 'bool', 'd' => '0',
                'n' => 'خاموش = کاربر فقط داخل مینی‌اپ کانفیگ را می‌بیند و پیام تلگرامی نمی‌آید.'],
            ['k' => 'deliver_msg_reseller', 'l' => 'ارسال پیام تحویل برای ساخت از پنل نمایندگی', 't' => 'bool', 'd' => '0',
                'n' => 'خاموش = نماینده کانفیگ را در پنل خودش می‌گیرد، بدون پیام اضافه.'],
        ],
    ],
    'ops' => [
        'icon'  => '♻️',
        'name'  => 'تمدید و حذف سرویس توسط کاربر',
        'desc'  => 'اجازهٔ تمدید و حذف/عودت وجه برای کاربر عادی.',
        'items' => [
            ['k' => 'usr_renew_enabled', 'l' => 'کاربر بتواند سرویس را تمدید کند', 't' => 'bool', 'd' => '1',
                'n' => 'مبلغ طرح از کیف پول کسر می‌شود و طرح‌های همان سرور نمایش داده می‌شوند.'],
            ['k' => 'usr_del_enabled', 'l' => 'کاربر بتواند سرویس خریداری‌شده را حذف کند', 't' => 'bool', 'd' => '0',
                'n' => 'تا وقتی خاموش است، دکمهٔ حذف در مینی‌اپ دیده نمی‌شود.'],
            ['k' => 'usr_dead_del', 'l' => 'کاربر بتواند کانفیگ‌های قطع را حذف کند', 't' => 'bool', 'd' => '1',
                'n' => 'کانفیگ منقضی/غیرفعال/حجم‌تمام‌شده؛ مستقل از گزینهٔ بالا کار می‌کند و عودت طبق همان سیاست حساب می‌شود.'],
            ['k' => 'usr_del_refund', 'l' => 'عودت وجه هنگام حذف', 't' => 'bool', 'd' => '1',
                'n' => 'خاموش = سرویس حذف می‌شود ولی پولی برنمی‌گردد.'],
            ['k' => 'usr_del_mode', 'l' => 'روش محاسبهٔ مبلغ عودتی', 't' => 'select', 'd' => 'fair',
                'o' => ['fair' => 'منصفانه (کمترینِ حجم و زمان مصرف‌نشده)', 'split' => 'میانگین حجم و زمان', 'gb' => 'فقط بر اساس حجم مصرف‌نشده'],
                'n' => 'مبنای محاسبه، مبلغ پرداختی همان سرویس است.'],
            ['k' => 'usr_del_fee_pct', 'l' => 'کارمزد حذف (درصد)', 't' => 'int', 'd' => '0', 'min' => 0, 'max' => 100,
                'n' => 'از مبلغ عودتی کم می‌شود. مثال: ۱۰ یعنی ۱۰٪ کارمزد.'],
            ['k' => 'trash_days' , 'l' => 'ماندن در سطل زباله (روز)', 't' => 'int', 'd' => '7', 'min' => 0, 'max' => 90,
                'n' => 'سرویس حذف‌شده تا این تعداد روز قابل بازگردانی است.'],
        ],
    ],
    'autodel' => [
        'icon'  => '🧹',
        'name'  => 'حذف خودکار کانفیگ‌های تمام‌شده',
        'desc'  => 'کانفیگی که تمام شده (زمان یا حجم) و تا N روز تمدید نشده، خودکار از پنل پاک و به سطل زباله منتقل می‌شود (اجرا توسط کران).',
        'items' => [
            ['k' => 'svc_autodel_on', 'l' => 'حذف خودکار فعال باشد', 't' => 'bool', 'd' => '1',
                'n' => 'اکانت‌های تست و سرویس‌های فعال هیچ‌وقت لمس نمی‌شوند؛ فقط وضعیت «منقضی».'],
            ['k' => 'svc_autodel_days', 'l' => 'مهلت تمدید پس از پایان (روز)', 't' => 'int', 'd' => '3', 'min' => 1, 'max' => 60,
                'n' => 'پیش‌فرض ۳ روز. کانفیگ‌هایی که قبلاً منقضی شده‌اند هم با همین قاعده پاک می‌شوند.'],
            ['k' => 'svc_autodel_warn', 'l' => 'هشدار ۲۴ ساعت قبل از حذف به کاربر', 't' => 'bool', 'd' => '1',
                'n' => 'پیام «فردا حذف می‌شود» با دکمهٔ تمدید ارسال می‌شود.'],
            ['k' => 'svc_autodel_notify', 'l' => 'اطلاع به کاربر پس از حذف', 't' => 'bool', 'd' => '1'],
            ['k' => 'svc_autodel_rs', 'l' => 'کانفیگ‌های نمایندگی هم شامل شوند', 't' => 'bool', 'd' => '0',
                'n' => 'خاموش = کانفیگ‌های ساخته‌شده از پنل نمایندگی دست نمی‌خورند.'],
        ],
    ],
    'buttons' => [
        'icon'  => '🎛',
        'name'  => 'دکمه‌های کارت سرویس در مینی‌اپ',
        'desc'  => 'هر دکمه را جدا می‌توانید روشن یا خاموش کنید.',
        'items' => [
            ['k' => 'ma_btn_sync', 'l' => '🔄 دکمهٔ به‌روزرسانی مصرف', 't' => 'bool', 'd' => '1'],
            ['k' => 'ma_btn_tut', 'l' => '🎓 دکمهٔ راهنمای اتصال', 't' => 'bool', 'd' => '1'],
            ['k' => 'ma_btn_renew', 'l' => '♻️ دکمهٔ تمدید سرویس', 't' => 'bool', 'd' => '1',
                'n' => 'برای نمایش، گزینهٔ «کاربر بتواند تمدید کند» هم باید روشن باشد.'],
            ['k' => 'ma_btn_del', 'l' => '🗑 دکمهٔ حذف و عودت وجه', 't' => 'bool', 'd' => '1',
                'n' => 'برای نمایش، گزینهٔ «کاربر بتواند حذف کند» هم باید روشن باشد.'],
        ],
    ],
];

$BYKEY = [];
foreach ($GROUPS as $g) {
    foreach ($g['items'] as $it) $BYKEY[$it['k']] = $it;
}

$TABS = [
    'simple'  => ['i' => '⚡', 'l' => 'ساده',        'n' => 'پرکاربردترین گزینه‌ها'],
    'theme'   => ['i' => '🎨', 'l' => 'تم صفحهٔ ساب', 'n' => 'ظاهر صفحه‌ای که کاربر می‌بیند'],
    'links'   => ['i' => '🔗', 'l' => 'لینک ساب',    'n' => 'نوع و شکل لینک تحویلی'],
    'domain'  => ['i' => '🌐', 'l' => 'دامنهٔ اختصاصی', 'n' => 'مخفی‌کردن دامنهٔ اصلی روی لینک ساب'],
    'naming'  => ['i' => '🏷', 'l' => 'نام کانفیگ',  'n' => 'برندینگ نام کانفیگ‌ها'],
    'actions' => ['i' => '♻️', 'l' => 'تمدید و حذف', 'n' => 'عملیات کاربر روی سرویس'],
    'buttons' => ['i' => '🎛', 'l' => 'دکمه‌ها',      'n' => 'دکمه‌های کارت مینی‌اپ'],
    'custom'  => ['i' => '➕', 'l' => 'آپشن دلخواه', 'n' => 'تنظیمات ساختهٔ خودتان'],
];
$TAB_GROUPS = [
    'links'   => ['links'],
    'naming'  => ['naming'],
    'actions' => ['deliver', 'ops', 'autodel'],
    'buttons' => ['buttons'],
];
$QUICK = ['sub_deliver', 'sub_pretty', 'sub_name_client', 'deliver_msg_miniapp', 'deliver_msg_reseller', 'usr_renew_enabled', 'usr_del_enabled', 'usr_dead_del', 'usr_del_refund'];

$tab = (string)($_GET['tab'] ?? $_POST['tab'] ?? 'simple');
if (!isset($TABS[$tab])) $tab = 'simple';

/* ==================== آپشن‌های دلخواه ==================== */

$OPT_TYPES = ['bool' => 'روشن/خاموش', 'int' => 'عدد', 'text' => 'متن', 'select' => 'انتخابی'];

$loadOpts = function (): array {
    $j = jdec((string)DB::setting('sub_options', '[]'));
    return is_array($j) ? $j : [];
};
$saveOpts = function (array $o): void {
    DB::setSetting('sub_options', jenc(array_values($o)));
};

/* ==================== ذخیره ==================== */

$act    = (string)($_POST['act'] ?? '');
$delKey = (string)($_POST['del'] ?? '');
$upKey  = (string)($_POST['up'] ?? '');
$dnKey  = (string)($_POST['down'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    if (!$canEdit) {
        flash('err', 'اجازهٔ ویرایش این بخش را ندارید.');
        back('subs', ['tab' => $tab]);
    }

    /* --- ذخیرهٔ تم صفحهٔ ساب --- */
    if ($act === 'theme_save') {
        $tk = (string)ptxt('theme', 32);
        if (!class_exists('SubTheme')) {
            flash('err', 'موتور تم در دسترس نیست. فایل <code>app/Service/SubTheme.php</code> را آپلود کنید.');
            back('subs', ['tab' => 'theme']);
        }
        if (!SubTheme::has($tk)) {
            flash('err', 'این تم معتبر نیست.');
            back('subs', ['tab' => 'theme']);
        }
        if (!SubTheme::ready($tk)) {
            flash('err', 'فایل CSS این تم روی هاست پیدا نشد. پوشهٔ <code>assets/sub/</code> را آپلود کنید.');
            back('subs', ['tab' => 'theme']);
        }
        SubTheme::save($tk);
        flash('ok', '🎨 تم صفحهٔ اشتراک به «' . h(SubTheme::label($tk)) . '» تغییر کرد.');
        back('subs', ['tab' => 'theme']);
    }

    /* --- تنظیمات داخلی --- */
    if ($act === 'save') {
        $keys = array_filter(array_map('trim', explode(',', (string)($_POST['keys'] ?? ''))));
        $n = 0;
        foreach ($keys as $k) {
            if (!isset($BYKEY[$k])) continue;
            $it = $BYKEY[$k];
            $t  = (string)($it['t'] ?? 'text');
            if ($t === 'bool') {
                $v = pchk('v_' . $k) ? '1' : '0';
            } elseif ($t === 'int') {
                $v = (string)max((int)($it['min'] ?? 0), min((int)($it['max'] ?? 100000), pint('v_' . $k, (int)($it['d'] ?? 0))));
            } elseif ($t === 'select') {
                $v = (string)ptxt('v_' . $k, 40);
                if (!isset($it['o'][$v])) $v = (string)($it['d'] ?? '');
            } else {
                $v = (string)ptxt('v_' . $k, 200);
            }
            DB::setSetting($k, $v);
            $n++;
        }
        flash('ok', '✅ ' . fa_num((string)$n) . ' تنظیم ذخیره شد.');
        back('subs', ['tab' => $tab]);
    }

    /* --- حذف آپشن دلخواه --- */
    if ($delKey !== '') {
        $opts = $loadOpts();
        $out  = [];
        $hit  = false;
        foreach ($opts as $o) {
            if ((string)($o['key'] ?? '') === $delKey) { $hit = true; continue; }
            $out[] = $o;
        }
        if (!$hit) {
            flash('err', 'این آپشن پیدا نشد.');
            back('subs', ['tab' => 'custom']);
        }
        $saveOpts($out);
        DB::setSetting($delKey, '');
        flash('ok', '🗑 آپشن <b>' . h($delKey) . '</b> حذف شد.');
        back('subs', ['tab' => 'custom']);
    }

    /* --- جابه‌جایی آپشن دلخواه --- */
    if ($upKey !== '' || $dnKey !== '') {
        $opts = $loadOpts();
        $key  = $upKey !== '' ? $upKey : $dnKey;
        $dir  = $upKey !== '' ? -1 : 1;
        foreach ($opts as $i => $o) {
            if ((string)($o['key'] ?? '') !== $key) continue;
            $j = $i + $dir;
            if ($j >= 0 && $j < count($opts)) {
                $tmp = $opts[$j];
                $opts[$j] = $opts[$i];
                $opts[$i] = $tmp;
                $saveOpts($opts);
            }
            break;
        }
        back('subs', ['tab' => 'custom']);
    }

    /* --- افزودن آپشن دلخواه --- */
    /* --- ذخیرهٔ دامنهٔ اختصاصی ساب --- */
    if ($act === 'dom_save') {
        $rawDom = (string)ptxt('sub_domain', 160);
        $clean  = class_exists('Svc') ? Svc::cleanHost($rawDom) : trim(mb_strtolower($rawDom));

        if (trim($rawDom) !== '' && $clean === '') {
            flash('err', 'دامنه معتبر نیست. نمونهٔ درست: <b class="mono ltr">sub.example.com</b>');
            back('subs', ['tab' => 'domain']);
        }

        $domOn = pchk('sub_domain_on') ? '1' : '0';
        if ($domOn === '1' && $clean === '') {
            flash('err', 'برای روشن‌کردن دامنهٔ اختصاصی، اول یک دامنهٔ معتبر وارد کنید.');
            back('subs', ['tab' => 'domain']);
        }

        /* جلوگیری از حلقه: دامنهٔ ساب نباید دقیقاً همان دامنهٔ پنل باشد */
        $mainHost = '';
        try {
            $mainHost = (string)parse_url((string)app_url(), PHP_URL_HOST);
            $mainHost = class_exists('Svc') ? Svc::cleanHost($mainHost) : mb_strtolower($mainHost);
        } catch (Throwable $e) {
            $mainHost = '';
        }

        $domPath = (string)ptxt('sub_domain_path', 60);
        $domPath = (string)preg_replace('~[^A-Za-z0-9_\-/]~', '', $domPath);
        $domPath = trim($domPath, '/');

        DB::setSetting('sub_domain', $clean);
        DB::setSetting('sub_domain_on', $domOn);
        DB::setSetting('sub_domain_https', pchk('sub_domain_https') ? '1' : '0');
        DB::setSetting('sub_domain_path', $domPath);
        DB::setSetting('sub_domain_rs', pchk('sub_domain_rs') ? '1' : '0');

        if ($domOn === '1' && $clean !== '' && $clean === $mainHost && $domPath === '') {
            flash('warn', '⚠️ دامنهٔ واردشده دقیقاً همان دامنهٔ پنل است؛ عملاً چیزی مخفی نمی‌شود.');
            back('subs', ['tab' => 'domain']);
        }

        if ($domOn === '1') {
            flash('ok', '✅ دامنهٔ اختصاصی ساب فعال شد: <b class="mono ltr">' . h($clean) . '</b>');
        } else {
            flash('ok', '✅ تنطیمات دامنه ذخیره شد (فعلاً خاموش است).');
        }
        back('subs', ['tab' => 'domain']);
    }

    if ($act === 'opt_add') {
        $key = strtolower(trim((string)ptxt('o_key', 40)));
        $key = (string)preg_replace('/[^a-z0-9_]/', '', $key);
        if ($key === '' || strpos($key, 'sub_') !== 0) {
            flash('err', 'کلید باید با <code>sub_</code> شروع شود. مثال درست: <code>sub_note</code>');
            back('subs', ['tab' => 'custom']);
        }
        if (isset($BYKEY[$key]) || $key === 'sub_options' || $key === 'sub_theme') {
            flash('err', 'کلید <b>' . h($key) . '</b> متعلق به آپشن‌های داخلی است؛ نام دیگری بگذارید.');
            back('subs', ['tab' => 'custom']);
        }
        $opts = $loadOpts();
        foreach ($opts as $o) {
            if ((string)($o['key'] ?? '') === $key) {
                flash('err', 'این آپشن قبلاً اضافه شده است.');
                back('subs', ['tab' => 'custom']);
            }
        }
        $type = (string)ptxt('o_type', 10);
        if (!isset($OPT_TYPES[$type])) $type = 'text';
        $label = trim((string)ptxt('o_label', 80));
        if ($label === '') $label = $key;
        $note    = trim((string)ptxt('o_note', 160));
        $choices = [];
        if ($type === 'select') {
            foreach (explode(',', (string)ptxt('o_opts', 200)) as $c) {
                $c = trim($c);
                if ($c !== '') $choices[] = $c;
            }
            if (count($choices) < 2) {
                flash('err', 'برای نوع «انتخابی» حداقل دو مقدار را با کاما وارد کنید.');
                back('subs', ['tab' => 'custom']);
            }
        }
        $opts[] = ['key' => $key, 'label' => $label, 'type' => $type, 'note' => $note, 'opts' => $choices];
        $saveOpts($opts);

        if ($type === 'bool')        $init = pchk('o_bool') ? '1' : '0';
        elseif ($type === 'select')  $init = (string)($choices[0] ?? '');
        elseif ($type === 'int')     $init = (string)pint('o_val', 0);
        else                         $init = (string)ptxt('o_val', 200);
        DB::setSetting($key, $init);

        flash('ok', '✅ آپشن «' . h($label) . '» اضافه شد.');
        back('subs', ['tab' => 'custom']);
    }

    /* --- ذخیرهٔ مقادیر آپشن‌های دلخواه --- */
    if ($act === 'opt_vals') {
        $n = 0;
        foreach ($loadOpts() as $o) {
            $k = (string)($o['key'] ?? '');
            if ($k === '') continue;
            $t = (string)($o['type'] ?? 'text');
            if ($t === 'bool') {
                $v = pchk('v_' . $k) ? '1' : '0';
            } elseif ($t === 'int') {
                $v = (string)pint('v_' . $k, 0);
            } elseif ($t === 'select') {
                $v = (string)ptxt('v_' . $k, 120);
                if (!in_array($v, (array)($o['opts'] ?? []), true)) $v = (string)(((array)($o['opts'] ?? []))[0] ?? '');
            } else {
                $v = (string)ptxt('v_' . $k, 400);
            }
            DB::setSetting($k, $v);
            $n++;
        }
        flash('ok', '✅ ' . fa_num((string)$n) . ' آپشن دلخواه ذخیره شد.');
        back('subs', ['tab' => 'custom']);
    }
}

/* ==================== وضعیت فعلی ==================== */

$subCount = 0;
$grpCount = 0;
$actCount = 0;
$allCount = 0;
$sampleId = '';
try {
    $subCount = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE sub_id <> '' AND (deleted_at IS NULL OR deleted_at = '')", [], 0);
    $grpCount = (int)DB::val("SELECT COUNT(DISTINCT group_key) FROM {p}services WHERE group_key <> ''", [], 0);
} catch (Throwable $e) {
}
try {
    $allCount = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE status <> 'deleted'", [], 0);
    $actCount = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE status = 'active'", [], 0);
} catch (Throwable $e) {
}
try {
    $sampleId = (string)DB::val("SELECT sub_id FROM {p}services WHERE sub_id <> '' AND status <> 'deleted' ORDER BY id DESC LIMIT 1", [], '');
} catch (Throwable $e) {
    $sampleId = '';
}

$pretty  = (string)DB::setting('sub_pretty', '1') === '1';
$baseUrl = rtrim((string)app_url(), '/');
$sample  = $pretty ? $baseUrl . '/sub/CODE' : $baseUrl . '/sub.php?c=CODE';
$mode    = (string)DB::setting('sub_deliver', 'local');
$modeTxt = $mode === 'panel' ? 'لینک ساب پنل' : ($mode === 'both' ? 'هر دو لینک' : 'لینک ساب خودمان');
$localOn = (string)DB::setting('sub_local', '1') === '1';
$optCnt  = count($loadOpts());

/* تم فعلی */
$hasTheme = class_exists('SubTheme');
$THEMES   = $hasTheme ? SubTheme::all() : [];
$curTheme = $hasTheme ? SubTheme::current(false) : 'aurora';
$curMeta  = $hasTheme ? SubTheme::meta($curTheme) : ['name' => 'آرورا', 'icon' => '🌌', 'desc' => '', 'tags' => [], 'sw' => ['#0a0d16', '#ff2d55', '#22d3ff']];
$missing  = $hasTheme ? SubTheme::missing() : [];
$thCount  = $hasTheme ? count($THEMES) : 1;

/* سلامت تنظیمات — چند درصد از کلیدهای مهم مقداردهی شده‌اند */
$hlTotal = 0;
$hlOk    = 0;
foreach ($BYKEY as $hk => $hit) {
    $hlTotal++;
    $hv = (string)DB::setting($hk, '@@none@@');
    if ($hv !== '@@none@@') $hlOk++;
}
$hlPct = $hlTotal > 0 ? (int)round($hlOk * 100 / $hlTotal) : 0;

$badge = function (string $key, string $label, string $def = '0'): string {
    $on = (string)DB::setting($key, $def) === '1';
    return '<span class="badge ' . ($on ? 'b-green' : 'b-gray') . '">' . h($label) . ': ' . ($on ? 'روشن' : 'خاموش') . '</span> ';
};

/* ==================== نمایش ==================== */

$fld = function (array $it) use ($canEdit): string {
    $k   = (string)$it['k'];
    $t   = (string)($it['t'] ?? 'text');
    $cur = (string)DB::setting($k, (string)($it['d'] ?? ''));
    $dis = $canEdit ? '' : ' disabled';
    $o   = '<div class="field' . ($t === 'text' ? ' full' : '') . '">';

    if ($t === 'bool') {
        $o .= '<label class="check"><input type="checkbox" name="v_' . h($k) . '" value="1"'
            . ($cur === '1' ? ' checked' : '') . $dis . '><span>' . h((string)$it['l']) . '</span></label>';
    } else {
        $o .= '<label>' . h((string)$it['l']) . '</label>';
        if ($t === 'select') {
            $o .= '<select name="v_' . h($k) . '"' . $dis . '>';
            foreach ((array)($it['o'] ?? []) as $ov => $ol) {
                $o .= '<option value="' . h((string)$ov) . '"' . ($cur === (string)$ov ? ' selected' : '') . '>' . h((string)$ol) . '</option>';
            }
            $o .= '</select>';
        } elseif ($t === 'int') {
            $o .= '<input type="number" class="ltr" name="v_' . h($k) . '" value="' . h($cur) . '" min="'
                . (int)($it['min'] ?? 0) . '" max="' . (int)($it['max'] ?? 100000) . '"' . $dis . '>';
        } else {
            $o .= '<input type="text" name="v_' . h($k) . '" value="' . h($cur) . '" placeholder="خالی = پیش‌فرض"' . $dis . '>';
        }
    }
    if (trim((string)($it['n'] ?? '')) !== '') $o .= '<div class="hint">' . (string)$it['n'] . '</div>';
    $o .= '<div class="hint mono ltr sb-key">' . h($k) . '</div></div>';
    return $o;
};

$renderGroup = function (array $g) use ($fld): string {
    $o = '<div class="fieldset sb-fs"><div class="section-title">' . h((string)$g['icon'] . ' ' . (string)$g['name']) . '</div>';
    if (trim((string)($g['desc'] ?? '')) !== '') $o .= '<div class="card-sub">' . h((string)$g['desc']) . '</div>';
    $o .= '<div class="form-grid g2">';
    foreach ($g['items'] as $it) $o .= $fld($it);
    return $o . '</div></div>';
};
?>

<style>
/* ============ Subs Studio v1 ============ */
.sb-hero{position:relative;overflow:hidden;border-radius:var(--r-xl);padding:22px 22px 20px;margin-bottom:16px;
  background:linear-gradient(135deg,rgba(91,140,255,.16),rgba(139,92,246,.10) 52%,rgba(38,211,232,.12));
  border:1px solid var(--border)}
.sb-glow{position:absolute;border-radius:50%;filter:blur(64px);pointer-events:none;opacity:.55}
.sb-glow.g1{width:280px;height:280px;background:var(--accent);inset-block-start:-130px;inset-inline-end:-70px}
.sb-glow.g2{width:240px;height:240px;background:var(--accent-2);inset-block-end:-140px;inset-inline-start:-60px}
.sb-htop{position:relative;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.sb-hic{width:54px;height:54px;flex:none;border-radius:var(--r-lg);display:grid;place-items:center;font-size:26px;
  background:var(--grad);box-shadow:var(--glow)}
.sb-htt{flex:1;min-width:190px}
.sb-htt h2{margin:0 0 3px;font-size:19px;font-weight:800}
.sb-htt p{margin:0;font-size:12.5px;color:var(--muted);line-height:1.85}
.sb-hact{display:flex;gap:8px;flex-wrap:wrap}
.sb-cells{position:relative;display:grid;grid-template-columns:repeat(auto-fit,minmax(112px,1fr));gap:10px;margin-top:18px}
.sb-cell{background:rgba(10,14,22,.44);border:1px solid var(--border-soft);border-radius:var(--r);padding:11px 10px;text-align:center;
  transition:transform .18s,border-color .18s}
.sb-cell:hover{transform:translateY(-2px);border-color:var(--accent)}
.sb-cell .i{font-size:16px;line-height:1}
.sb-cell .v{display:block;font-size:16px;font-weight:800;margin-top:5px;font-family:var(--font-num)}
.sb-cell .l{display:block;font-size:10.5px;color:var(--muted);margin-top:2px}
.sb-cell.o .v{color:var(--orange)} .sb-cell.g .v{color:var(--green)}
.sb-cell.r .v{color:var(--red)}    .sb-cell.b .v{color:var(--accent)}
.sb-cell.c .v{color:var(--cyan)}   .sb-cell.p .v{color:var(--accent-2)}
.sb-rate{position:relative;margin-top:14px}
.sb-rate-t{display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-bottom:6px}
.sb-rate-bar{height:8px;border-radius:var(--r-pill);background:rgba(10,14,22,.6);overflow:hidden}
.sb-rate-bar i{display:block;height:100%;border-radius:var(--r-pill);background:var(--grad);transition:width .8s cubic-bezier(.22,.61,.36,1)}

/* ---- نوار تب‌ها ---- */
.sb-nav{display:flex;gap:8px;overflow-x:auto;padding-bottom:6px;margin-bottom:14px;scrollbar-width:none}
.sb-nav::-webkit-scrollbar{display:none}
.sb-nv{display:flex;align-items:center;gap:9px;padding:11px 14px;border-radius:var(--r);white-space:nowrap;
  background:var(--surface-2);border:1px solid var(--border-soft);color:var(--muted);text-decoration:none;
  transition:transform .16s,border-color .16s,background .16s,color .16s;min-height:var(--tap)}
.sb-nv:hover{transform:translateY(-2px);border-color:var(--accent);color:var(--accent-text)}
.sb-nv.on{background:var(--accent-soft);border-color:var(--accent);color:var(--accent-text);box-shadow:var(--glow)}
.sb-nv .i{font-size:16px;line-height:1}
.sb-nv .t{display:grid;gap:1px;text-align:start}
.sb-nv .t b{font-size:12.5px;font-weight:700}
.sb-nv .t i{font-style:normal;font-size:10px;opacity:.72}

/* ---- کارت و فیلدست ---- */
.sb-fs{position:relative}
.sb-key{opacity:.5;font-size:10px}
.sb-quick{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin-top:4px}
.sb-qc{display:flex;align-items:center;gap:10px;padding:12px 13px;border-radius:var(--r);
  background:var(--surface-2);border:1px solid var(--border-soft)}
.sb-qc .qi{width:34px;height:34px;flex:none;border-radius:10px;display:grid;place-items:center;font-size:16px;
  background:var(--accent-soft);border:1px solid var(--border)}
.sb-qc .qt{min-width:0;display:grid;gap:2px}
.sb-qc .qt b{font-size:12px;font-weight:700}
.sb-qc .qt i{font-style:normal;font-size:11px;color:var(--muted);word-break:break-all;direction:ltr;text-align:start}

/* ---- گالری تم ---- */
.sb-themes{display:grid;grid-template-columns:repeat(auto-fill,minmax(252px,1fr));gap:14px;margin-top:6px}
.sb-th{position:relative;border-radius:var(--r-lg);overflow:hidden;border:2px solid var(--border-soft);
  background:var(--surface-2);transition:transform .2s,border-color .2s,box-shadow .2s;display:flex;flex-direction:column}
.sb-th:hover{transform:translateY(-4px);border-color:var(--accent);box-shadow:var(--shadow-lg)}
.sb-th.on{border-color:var(--green);box-shadow:0 0 0 1px var(--green),0 10px 26px -14px rgba(47,212,143,.7)}
.sb-th.miss{opacity:.55}
.sb-thp{position:relative;height:132px;display:grid;place-items:center;overflow:hidden}
.sb-thp .mock{width:78%;border-radius:10px;padding:10px;display:grid;gap:7px;
  box-shadow:0 10px 22px -12px rgba(0,0,0,.85)}
.sb-thp .mrow{display:flex;align-items:center;gap:6px}
.sb-thp .mdot{width:16px;height:16px;border-radius:5px;flex:none}
.sb-thp .mln{height:6px;border-radius:99px;flex:1}
.sb-thp .mbar{height:9px;border-radius:99px;overflow:hidden}
.sb-thp .mbar i{display:block;height:100%;width:62%;border-radius:99px}
.sb-thp .mgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:5px}
.sb-thp .mcell{height:20px;border-radius:6px}
.sb-thb{padding:13px 14px 14px;display:grid;gap:7px;flex:1}
.sb-thb .nm{display:flex;align-items:center;gap:7px;font-size:14px;font-weight:800}
.sb-thb .ds{font-size:11.5px;color:var(--muted);line-height:1.8;min-height:34px}
.sb-thtags{display:flex;gap:5px;flex-wrap:wrap}
.sb-thtag{font-size:10px;padding:3px 8px;border-radius:var(--r-pill);background:var(--surface-3);color:var(--muted);border:1px solid var(--border-soft)}
.sb-thsw{display:flex;gap:5px;margin-top:2px}
.sb-thsw span{width:22px;height:22px;border-radius:7px;border:1px solid rgba(255,255,255,.14)}
.sb-thact{display:flex;gap:7px;margin-top:8px;flex-wrap:wrap}
.sb-thact .btn{flex:1;min-width:96px}
.sb-thflag{position:absolute;inset-block-start:9px;inset-inline-end:9px;z-index:2;font-size:10px;font-weight:800;
  padding:4px 10px;border-radius:var(--r-pill);background:var(--green);color:#06210f}
.sb-thflag.w{background:var(--orange);color:#231400}

/* ---- تست لینک ---- */
.sb-test{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:end}
.sb-out{margin-top:12px;padding:12px 14px;border-radius:var(--r);background:var(--surface-2);
  border:1px solid var(--border-soft);font-size:12px;word-break:break-all;direction:ltr;text-align:left}

@media (max-width:760px){
  .sb-hero{padding:18px 16px}
  .sb-htt h2{font-size:17px}
  .sb-test{grid-template-columns:1fr}
  .sb-themes{grid-template-columns:1fr}
}
</style>

<div class="sb-hero">
  <span class="sb-glow g1"></span><span class="sb-glow g2"></span>
  <div class="sb-htop">
    <div class="sb-hic">🛰</div>
    <div class="sb-htt">
      <h2>مدیریت ساب و تحویل سرویس</h2>
      <p>لینک اشتراک، نام کانفیگ، پیام تحویل، عملیات کاربر و تم ظاهری صفحهٔ ساب — همه در یک جا.</p>
    </div>
    <div class="sb-hact">
      <a class="btn btn-sm btn-ghost" href="?p=subs&amp;tab=theme">🎨 تم صفحهٔ ساب</a>
      <a class="btn btn-sm btn-ghost" href="?p=services">📦 سرویس‌ها</a>
    </div>
  </div>

  <div class="sb-cells">
    <div class="sb-cell b"><div class="i">🔗</div><span class="v"><?= h(fa_num((string)$subCount)) ?></span><span class="l">سرویس دارای ساب</span></div>
    <div class="sb-cell g"><div class="i">✅</div><span class="v"><?= h(fa_num((string)$actCount)) ?></span><span class="l">سرویس فعال</span></div>
    <div class="sb-cell c"><div class="i">📦</div><span class="v"><?= h(fa_num((string)$allCount)) ?></span><span class="l">کل سرویس‌ها</span></div>
    <div class="sb-cell p"><div class="i">👥</div><span class="v"><?= h(fa_num((string)$grpCount)) ?></span><span class="l">گروه نمایندگی</span></div>
    <div class="sb-cell <?= $localOn ? 'g' : 'r' ?>"><div class="i"><?= $localOn ? '🟢' : '🔴' ?></div><span class="v"><?= $localOn ? 'روشن' : 'خاموش' ?></span><span class="l">ساب داخلی</span></div>
    <div class="sb-cell o"><div class="i">🧩</div><span class="v"><?= h(fa_num((string)$optCnt)) ?></span><span class="l">آپشن دلخواه</span></div>
    <div class="sb-cell b"><div class="i">🎨</div><span class="v" style="font-size:13px"><?= h((string)$curMeta['icon'] . ' ' . (string)$curMeta['name']) ?></span><span class="l">تم فعلی</span></div>
    <div class="sb-cell c"><div class="i">🖼</div><span class="v"><?= h(fa_num((string)$thCount)) ?></span><span class="l">تم آماده</span></div>
  </div>

  <div class="sb-rate">
    <div class="sb-rate-t">
      <span>پیکربندی تنظیمات ساب</span>
      <span><?= h(fa_num((string)$hlOk)) ?> از <?= h(fa_num((string)$hlTotal)) ?> کلید مقداردهی شده — <?= h(fa_num((string)$hlPct)) ?>٪</span>
    </div>
    <div class="sb-rate-bar"><i style="width:<?= (int)$hlPct ?>%"></i></div>
  </div>
</div>

<div class="sb-nav">
  <?php foreach ($TABS as $tk => $tv): ?>
    <a class="sb-nv<?= $tab === $tk ? ' on' : '' ?>" href="?p=subs&amp;tab=<?= h($tk) ?>">
      <span class="i"><?= h((string)$tv['i']) ?></span>
      <span class="t"><b><?= h((string)$tv['l']) ?></b><i><?= h((string)$tv['n']) ?></i></span>
    </a>
  <?php endforeach; ?>
</div>

<?php if (!$canEdit): ?>
  <div class="alert a-warn">شما فقط اجازهٔ مشاهده دارید؛ تغییرات ذخیره نمی‌شود.</div>
<?php endif; ?>

<?php if ($tab === 'domain'): ?>
<?php
/* ---------- مقادیر دامنهٔ اختصاصی ساب ---------- */
$sdOn     = false;
$sdHost   = '';
$sdScheme = 'https';
$sdPath   = '';
$sdRs     = true;
$sdSample = '';
try {
    if (class_exists('Svc')) {
        $sdOn     = Svc::subDomainOn();
        $sdHost   = Svc::subDomain();
        $sdScheme = Svc::subDomainScheme();
        $sdPath   = Svc::subDomainPath();
        $sdRs     = Svc::subDomainForReseller();
        $sdSample = Svc::subSample();
    }
} catch (Throwable $e) {
    /* مقادیر پیش‌فرض حفظ می‌شود */
}
$sdRaw    = (string)DB::setting('sub_domain', '');
$sdPathR  = trim((string)DB::setting('sub_domain_path', ''), '/');
$sdMain   = '';
try { $sdMain = (string)parse_url((string)app_url(), PHP_URL_HOST); } catch (Throwable $e) { $sdMain = ''; }
$sdPretty = (string)DB::setting('sub_pretty', '1') === '1';
$sdTail   = $sdPretty ? '/sub/ABC123XY' : '/sub.php?id=ABC123XY';
$sdOld    = rtrim((string)app_url(), '/') . $sdTail;
if ($sdSample === '') $sdSample = $sdOld;
$sdRsDom  = '';
try { if (class_exists('Reseller')) $sdRsDom = Reseller::subDomain(); } catch (Throwable $e) { $sdRsDom = ''; }
?>

<style>
/* ============ Sub Domain Studio v1 ============ */
.sd-hero{position:relative;overflow:hidden;border:1px solid var(--border);border-radius:var(--r-xl);
  background:linear-gradient(135deg,var(--surface) 0%,var(--surface-2) 100%);padding:20px;margin-bottom:var(--s4)}
.sd-hero .g{position:absolute;width:280px;height:280px;border-radius:50%;filter:blur(80px);opacity:.20;pointer-events:none}
.sd-hero .g1{background:#26D3E8;top:-140px;inset-inline-end:-90px}
.sd-hero .g2{background:#8B5CF6;bottom:-150px;inset-inline-start:-70px}
.sd-top{display:flex;align-items:center;gap:12px;position:relative;z-index:1;margin-bottom:16px}
.sd-ic{width:52px;height:52px;flex:0 0 52px;border-radius:16px;display:grid;place-items:center;font-size:25px;
  background:rgba(38,211,232,.14);border:1px solid rgba(38,211,232,.34)}
.sd-tt b{display:block;font-size:17px;font-weight:900}
.sd-tt i{display:block;font-style:normal;color:var(--muted);font-size:12.5px;margin-top:3px;line-height:1.8}
.sd-cells{position:relative;z-index:1;display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(158px,1fr))}
.sd-cell{background:var(--surface-3);border:1px solid var(--border-soft);border-radius:var(--r);padding:11px 12px}
.sd-cell .k{display:flex;align-items:center;gap:6px;color:var(--muted);font-size:11px;font-weight:700}
.sd-cell .v{display:block;font-weight:900;font-size:14px;margin-top:5px;word-break:break-all}
.sd-cell .v.sm{font-size:11.5px;font-weight:700;line-height:1.75}
.sd-cell.g{border-color:rgba(47,212,143,.4);background:rgba(47,212,143,.09)}
.sd-cell.g .v{color:var(--green)}
.sd-cell.o{border-color:rgba(255,169,46,.4);background:rgba(255,169,46,.09)}
.sd-cell.o .v{color:var(--orange)}
.sd-cell.c{border-color:rgba(38,211,232,.4);background:rgba(38,211,232,.09)}
.sd-cell.c .v{color:var(--cyan)}

.sd-steps{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(215px,1fr))}
.sd-step{background:var(--surface-2);border:1px solid var(--border-soft);border-radius:var(--r);padding:13px}
.sd-step .n{width:26px;height:26px;border-radius:9px;display:grid;place-items:center;font-weight:900;font-size:12px;
  background:rgba(38,211,232,.16);color:var(--cyan);border:1px solid rgba(38,211,232,.34);margin-bottom:8px}
.sd-step b{display:block;font-size:13.5px;margin-bottom:5px}
.sd-step i{font-style:normal;color:var(--muted);font-size:12px;line-height:1.95;display:block}
.sd-step code{background:var(--bg);border:1px solid var(--border-soft);border-radius:6px;padding:1px 5px;
  font-size:11.5px;direction:ltr;display:inline-block}

.sd-cmp{display:grid;gap:10px;grid-template-columns:1fr 1fr;margin-top:4px}
@media(max-width:640px){.sd-cmp{grid-template-columns:1fr}}
.sd-cmpb{border:1px solid var(--border-soft);border-radius:var(--r);padding:12px;background:var(--surface-2)}
.sd-cmpb .t{font-size:11px;font-weight:800;color:var(--muted);margin-bottom:7px}
.sd-cmpb .u{direction:ltr;text-align:left;font-family:var(--font-num),monospace;font-size:12px;
  word-break:break-all;line-height:1.8}
.sd-cmpb.bad{border-color:rgba(255,107,107,.36);background:rgba(255,107,107,.07)}
.sd-cmpb.bad .u{color:#FF9A9A}
.sd-cmpb.good{border-color:rgba(47,212,143,.4);background:rgba(47,212,143,.08)}
.sd-cmpb.good .u{color:#7EE7B6}

.sd-prev{border:1px dashed var(--border);border-radius:var(--r);padding:13px;background:var(--bg-soft);margin-top:10px}
.sd-prev .t{font-size:11px;color:var(--muted);font-weight:800;margin-bottom:7px}
.sd-prev .u{direction:ltr;text-align:left;font-family:var(--font-num),monospace;font-size:13px;
  font-weight:800;color:var(--accent-text);word-break:break-all}

.sd-d{border:1px solid var(--border-soft);border-radius:var(--r);background:var(--surface-2);margin-bottom:9px;overflow:hidden}
.sd-d>summary{cursor:pointer;padding:12px 14px;font-weight:800;font-size:13.5px;list-style:none;
  display:flex;align-items:center;gap:8px}
.sd-d>summary::-webkit-details-marker{display:none}
.sd-d>summary::after{content:'▾';margin-inline-start:auto;color:var(--muted);transition:transform .18s}
.sd-d[open]>summary{background:rgba(38,211,232,.07);border-bottom:1px solid var(--border-soft)}
.sd-d[open]>summary::after{transform:rotate(180deg)}
.sd-b{padding:13px 15px;font-size:12.5px;line-height:2.05;color:#C8D2E4}
.sd-b code{background:var(--bg);border:1px solid var(--border-soft);border-radius:6px;padding:1px 6px;
  font-size:11.5px;direction:ltr;display:inline-block}
.sd-tbl{width:100%;border-collapse:collapse;margin:9px 0;font-size:12px}
.sd-tbl th,.sd-tbl td{border:1px solid var(--border-soft);padding:7px 9px;text-align:start}
.sd-tbl th{background:var(--surface-3);font-weight:800;font-size:11.5px}
.sd-tbl td.ltr{direction:ltr;text-align:left;font-family:var(--font-num),monospace;font-size:11.5px}
.sd-note{border-inline-start:3px solid var(--cyan);background:rgba(38,211,232,.08);border-radius:9px;
  padding:9px 12px;margin:9px 0;font-size:12px;line-height:1.95}
.sd-warn{border-inline-start:3px solid var(--orange);background:rgba(255,169,46,.08);border-radius:9px;
  padding:9px 12px;margin:9px 0;font-size:12px;line-height:1.95}
</style>

<div class="sd-hero">
  <span class="g g1"></span><span class="g g2"></span>
  <div class="sd-top">
    <div class="sd-ic">🌐</div>
    <div class="sd-tt">
      <b>دامنهٔ اختصاصی لینک ساب</b>
      <i>لینک اشتراک مشتریان روی یک دامنهٔ جداگانه ساخته می‌شود و دامنهٔ اصلی پنل کاملاً پنهان می‌ماند</i>
    </div>
  </div>
  <div class="sd-cells">
    <div class="sd-cell <?= $sdOn ? 'g' : 'o' ?>">
      <span class="k">⚡ وضعیت</span>
      <span class="v"><?= $sdOn ? 'فعال' : 'خاموش' ?></span>
    </div>
    <div class="sd-cell c">
      <span class="k">🌐 دامنهٔ ساب</span>
      <span class="v sm"><?= $sdHost !== '' ? h($sdHost) : 'ثبت نشده' ?></span>
    </div>
    <div class="sd-cell">
      <span class="k">🔒 پروتکل</span>
      <span class="v sm"><?= h($sdScheme) ?></span>
    </div>
    <div class="sd-cell">
      <span class="k">📁 زیرپوشه</span>
      <span class="v sm"><?= $sdPath !== '' ? h($sdPath) : 'ریشهٔ دامنه' ?></span>
    </div>
    <div class="sd-cell">
      <span class="k">🏷 نمایندگان</span>
      <span class="v sm"><?= $sdRs ? 'اعمال می‌شود' : 'فقط کاربران عادی' ?></span>
    </div>
    <div class="sd-cell">
      <span class="k">🏠 دامنهٔ پنل</span>
      <span class="v sm"><?= $sdMain !== '' ? h($sdMain) : '—' ?></span>
    </div>
  </div>
</div>

<?php if (!$sdOn && $sdHost !== ''): ?>
  <div class="alert a-warn">دامنه ذخیره شده ولی هنوز <b>روشن نشده</b> — تیک «فعال‌سازی» را بزنید و ذخیره کنید.</div>
<?php endif; ?>

<?php if ($sdRsDom !== '' && $sdRsDom !== $sdHost): ?>
  <div class="alert a-info">در بخش نمایندگی هم یک دامنهٔ عمومی ثبت شده است:
    <b class="mono ltr"><?= h($sdRsDom) ?></b> — دامنهٔ اختصاصی خود هر نماینده همیشه اولویت دارد.</div>
<?php endif; ?>

<div class="card">
  <div class="card-head tight">
    <div>
      <div class="card-title">🧭 راهنمای راه‌اندازی — ۳ گام</div>
      <div class="card-sub">ترتیب را رعایت کنید؛ اول DNS، بعد SSL، آخر فعال‌سازی.</div>
    </div>
  </div>
  <div class="sd-steps">
    <div class="sd-step">
      <div class="n">۱</div>
      <b>دامنه را به همین هاست وصل کنید</b>
      <i>در DNS یک رکورد <code>A</code> به آیپی همین سرور بسازید، یا یک <code>CNAME</code> به دامنهٔ اصلی.</i>
    </div>
    <div class="sd-step">
      <div class="n">۲</div>
      <b>دامنه را به هاست معرفی کنید + SSL</b>
      <i>در cPanel از <code>Addon Domain</code> یا <code>Alias</code> استفاده کنید و گواهی SSL رایگان را صادر کنید.</i>
    </div>
    <div class="sd-step">
      <div class="n">۳</div>
      <b>همین‌جا روشن کنید</b>
      <i>دامنه را در فرم زیر بنویسید، تیک فعال‌سازی را بزنید و ذخیره کنید.</i>
    </div>
  </div>
</div>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="dom_save">
  <input type="hidden" name="tab" value="domain">

  <div class="card">
    <div class="card-head tight">
      <div>
        <div class="card-title">⚙️ تنطیمات دامنه</div>
        <div class="card-sub">تا وقتی تیک فعال‌سازی نخورده باشد، هیچ تغییری در لینک‌های کاربران اعمال نمی‌شود.</div>
      </div>
    </div>

    <label class="check">
      <input type="checkbox" name="sub_domain_on" value="1" <?= $sdOn ? 'checked' : '' ?>>
      <span><b>فعال‌سازی دامنهٔ اختصاصی</b> — لینک ساب روی این دامنه ساخته شود</span>
    </label>

    <div class="form-grid g2" style="margin-top:12px">
      <div class="field" style="grid-column:1/-1">
        <label>دامنهٔ اختصاصی ساب</label>
        <input class="mono ltr" type="text" name="sub_domain" id="sdHost" dir="ltr" maxlength="160"
          value="<?= h($sdRaw) ?>" placeholder="sub.example.com">
        <div class="hint">فقط دامنه — بدون <code>https://</code> و بدون اسلش انتها. خودمان تمیزش می‌کنیم.</div>
      </div>

      <div class="field">
        <label>زیرپوشه (اختیاری)</label>
        <input class="mono ltr" type="text" name="sub_domain_path" id="sdPath" dir="ltr" maxlength="60"
          value="<?= h($sdPathR) ?>" placeholder="خالی = ریشهٔ دامنه">
        <div class="hint">اگر ربات در پوشه‌ای مانند <code>SR-BOT</code> نصب است و دامنه به ریشه اشاره نمی‌کند.</div>
      </div>

      <div class="field">
        <label>وضعیت گواهی SSL</label>
        <label class="check" style="margin:0">
          <input type="checkbox" name="sub_domain_https" id="sdHttps" value="1" <?= $sdScheme === 'https' ? 'checked' : '' ?>>
          <span>از <b>https</b> استفاده شود</span>
        </label>
        <div class="hint">اگر گواهی SSL ندارید خاموش کنید، ولی بسیاری از کلاینت‌ها لینک http را قبول نمی‌کنند.</div>
      </div>

      <div class="field" style="grid-column:1/-1">
        <label class="check" style="margin:0">
          <input type="checkbox" name="sub_domain_rs" value="1" <?= $sdRs ? 'checked' : '' ?>>
          <span>روی لینک <b>نمایندگان</b> هم اعمال شود</span>
        </label>
        <div class="hint">اگر نماینده‌ای دامنهٔ خودش را ثبت کرده باشد، همیشه دامنهٔ خودش اولویت دارد.</div>
      </div>
    </div>

    <div class="sd-prev">
      <div class="t">🔗 پیش‌نمایش لینک ساب (زنده)</div>
      <div class="u" id="sdPrev"><?= h($sdSample) ?></div>
    </div>

    <div class="section-title" style="margin-top:16px">مقایسهٔ قبل و بعد</div>
    <div class="sd-cmp">
      <div class="sd-cmpb bad">
        <div class="t">❌ بدون دامنهٔ اختصاصی — دامنهٔ پنل لو می‌رود</div>
        <div class="u"><?= h($sdOld) ?></div>
      </div>
      <div class="sd-cmpb good">
        <div class="t">✅ با دامنهٔ اختصاصی — دامنهٔ پنل پنهان</div>
        <div class="u" id="sdPrev2"><?= h($sdSample) ?></div>
      </div>
    </div>

    <div class="sd-warn">
      ⚠️ <b>مهم:</b> این تنطیم فقط لینک‌های جدید را می‌سازد. لینک‌هایی که قبلاً در دست مشتریان است
      روی دامنهٔ قبلی می‌مانند؛ پس <b>دامنهٔ قبلی را حتماً فعال نگه دارید</b> تا سرویس مشتریان قطع نشود.
    </div>

    <div class="sticky-acts">
      <button class="btn btn-primary">💾 ذخیرهٔ تنطیمات دامنه</button>
    </div>
  </div>
</form>

<div class="card">
  <div class="card-head tight">
    <div>
      <div class="card-title">📚 راهنمای کامل و رفع اشکال</div>
      <div class="card-sub">هر چیزی که برای راه‌اندازی لازم دارید — قدم به قدم.</div>
    </div>
  </div>

  <details class="sd-d">
    <summary>🔌 گام ۱ — تنطیم DNS دامنه</summary>
    <div class="sd-b">
      دو راه دارید؛ هر دو درست کار می‌کنند:
      <table class="sd-tbl">
        <tr><th>روش</th><th>نوع رکورد</th><th>مقدار</th><th>کجا مناسب است</th></tr>
        <tr><td>مستقیم به آیپی</td><td class="ltr">A</td><td class="ltr">1.2.3.4</td><td>وقتی آیپی سرور ثابت است</td></tr>
        <tr><td>اشاره به دامنهٔ اصلی</td><td class="ltr">CNAME</td><td class="ltr"><?= h($sdMain !== '' ? $sdMain : 'example.com') ?></td><td>وقتی آیپی عوض می‌شود</td></tr>
      </table>
      <div class="sd-note">آیپی سرور را در cPanel از بخش <b>Server Information</b> یا در بخش «سلامت» همین پنل می‌بینید.</div>
      اگر دامنه روی <b>کلادفلر</b> است، در اولین مرحله ابر را <b>خاموش (DNS only)</b> کنید تا گواهی SSL صادر شود؛
      بعد از صدور گواهی می‌توانید دوباره روشنش کنید.
    </div>
  </details>

  <details class="sd-d">
    <summary>🏠 گام ۲ — معرفی دامنه به هاست و صدور SSL</summary>
    <div class="sd-b">
      در cPanel یکی از این دو را انجام دهید:
      <table class="sd-tbl">
        <tr><th>گزینه</th><th>مسیر cPanel</th><th>توضیح</th></tr>
        <tr><td>Alias / Parked</td><td class="ltr">Domains → Create A New Domain</td><td>دامنهٔ دوم دقیقاً همان محتوای دامنهٔ اصلی را نشان می‌دهد — <b>ساده‌ترین راه</b></td></tr>
        <tr><td>Addon Domain</td><td class="ltr">Domains → Addon Domains</td><td>پوشهٔ جداگانه می‌سازد؛ باید Document Root را روی پوشهٔ ربات بگذارید</td></tr>
      </table>
      <div class="sd-note">برای گواهی رایگان: <b>SSL/TLS Status</b> ← دامنه را انتخاب کنید ← <b>Run AutoSSL</b>.
      اگر خطا داد، مطمئن شوید DNS دامنه واقعاً به همین سرور می‌رسد (تا ۲۴ ساعت طول می‌کشد).</div>
      اگر از <b>Addon Domain</b> استفاده کردید و دامنه به ریشهٔ جداگانه‌ای اشاره می‌کند، یا Document Root را روی پوشهٔ ربات بگذارید
      یا در فرم بالا فیلد <b>زیرپوشه</b> را پر کنید.
    </div>
  </details>

  <details class="sd-d">
    <summary>🧪 گام ۳ — تست درستی پیش از فعال‌سازی</summary>
    <div class="sd-b">
      پیش از زدن تیک فعال‌سازی، این دو مورد را در مرورگر باز کنید و مطمئن شوید درست بالا می‌آیند:
      <ol style="margin:8px 0;padding-inline-start:20px">
        <li>صفحهٔ اصلی روی دامنهٔ جدید باز می‌شود و قفل SSL سبز است</li>
        <li>یک لینک ساب واقعی را با دامنهٔ جدید باز کنید و ببینید کانفیگ تحویل می‌شود</li>
      </ol>
      لینک تست را می‌توانید از سربرگ <b>لینک ساب</b> بردارید و فقط دامنه‌اش را دستی عوض کنید.
      <div class="sd-warn">اگر صفحهٔ دامنهٔ جدید خطای <b>404</b> داد، یعنی Document Root اشتباه است یا باید فیلد «زیرپوشه» را پر کنید.</div>
    </div>
  </details>

  <details class="sd-d">
    <summary>🏷 دامنهٔ نمایندگان چطور کار می‌کند؟</summary>
    <div class="sd-b">
      ترتیب اولویت برای ساخت لینک هر مشتری دقیقاً این است:
      <table class="sd-tbl">
        <tr><th>اولویت</th><th>منبع دامنه</th><th>توضیح</th></tr>
        <tr><td>۱</td><td>دامنهٔ شخصی نماینده</td><td>هر نماینده در پنل خودش ثبت می‌کند — همیشه برنده است</td></tr>
        <tr><td>۲</td><td>دامنهٔ عمومی نمایندگان</td><td>در مدیریت نمایندگان تنطیم می‌شود</td></tr>
        <tr><td>۳</td><td>دامنهٔ اختصاصی همین صفحه</td><td>برای همهٔ کاربران عادی و نمایندگان بی‌دامنه</td></tr>
        <tr><td>۴</td><td>دامنهٔ اصلی پنل</td><td>وقتی هیچ‌کدام از بالا تنطیم نشده باشد</td></tr>
      </table>
      اگر تیک «روی لینک نمایندگان هم اعمال شود» را بردارید، دامنهٔ این صفحه فقط برای کاربران عادی می‌ماند.
    </div>
  </details>

  <details class="sd-d">
    <summary>🚨 خطاهای رایج و راه‌حل فوری</summary>
    <div class="sd-b">
      <table class="sd-tbl">
        <tr><th>نشانه</th><th>علت</th><th>راه‌حل</th></tr>
        <tr><td>لینک باز نمی‌شود / تایم‌اوت</td><td>DNS هنوز پخش نشده</td><td>تا ۲۴ ساعت صبر کنید؛ فعلاً تیک فعال‌سازی را بردارید</td></tr>
        <tr><td>خطای گواهی SSL در کلاینت</td><td>گواهی برای این دامنه صادر نشده</td><td>AutoSSL را روی دامنهٔ جدید اجرا کنید</td></tr>
        <tr><td>خطای 404</td><td>Document Root یا زیرپوشه اشتباه است</td><td>فیلد زیرپوشه را پر کنید یا Root را اصلاح کنید</td></tr>
        <tr><td>لینک باز می‌شود ولی خالی است</td><td>کد ساب اشتباه است</td><td>لینک را از کارت همان سرویس کپی کنید</td></tr>
        <tr><td>لینک زیبا کار نمی‌کند</td><td>mod_rewrite روی دامنهٔ جدید فعال نیست</td><td>در سربرگ «لینک ساب» گزینهٔ لینک زیبا را خاموش کنید</td></tr>
        <tr><td>مشتری قدیمی قطع شد</td><td>دامنهٔ قبلی غیرفعال شده</td><td>دامنهٔ قدیمی را هم فعال نگه دارید</td></tr>
      </table>
    </div>
  </details>
</div>

<script>
(function () {
  var host = document.getElementById('sdHost'),
      path = document.getElementById('sdPath'),
      https = document.getElementById('sdHttps'),
      out = document.getElementById('sdPrev'),
      out2 = document.getElementById('sdPrev2');
  if (!host || !out) return;

  var TAIL = <?= json_encode($sdTail, JSON_UNESCAPED_SLASHES) ?>;
  var FALL = <?= json_encode($sdOld, JSON_UNESCAPED_SLASHES) ?>;

  function clean(v) {
    v = (v || '').trim().toLowerCase();
    v = v.replace(/^[a-z]+:\/\//, '');
    v = v.replace(/[\/?#].*$/, '');
    v = v.replace(/^www\./, '');
    v = v.replace(/^\.+|\.+$/g, '');
    return v;
  }

  function paint() {
    var hh = clean(host.value);
    if (!hh || hh.indexOf('.') < 0) {
      out.textContent = FALL;
      if (out2) out2.textContent = FALL;
      return;
    }
    var sc = (https && https.checked) ? 'https' : 'http';
    var pp = (path ? path.value : '').trim().replace(/[^A-Za-z0-9_\-\/]/g, '').replace(/^\/+|\/+$/g, '');
    var url = sc + '://' + hh + (pp ? '/' + pp : '') + TAIL;
    out.textContent = url;
    if (out2) out2.textContent = url;
  }

  host.addEventListener('input', paint);
  if (path) path.addEventListener('input', paint);
  if (https) https.addEventListener('change', paint);
  paint();
})();
</script>

<?php endif; ?>

<?php if ($tab === 'simple'): ?>

  <div class="card compact">
    <div class="card-head tight">
      <div>
        <div class="card-title">📊 خلاصهٔ وضعیت</div>
        <div class="card-sub">نگاه سریع به حالت تحویل و سوئیچ‌های مهم.</div>
      </div>
    </div>
    <div class="sb-quick">
      <div class="sb-qc"><div class="qi">📤</div><div class="qt"><b>حالت تحویل</b><i><?= h($modeTxt) ?></i></div></div>
      <div class="sb-qc"><div class="qi">🔗</div><div class="qt"><b>نمونهٔ لینک</b><i><?= h($sample) ?></i></div></div>
      <div class="sb-qc"><div class="qi">🎨</div><div class="qt"><b>تم فعلی</b><i><?= h((string)$curMeta['icon'] . ' ' . (string)$curMeta['name']) ?></i></div></div>
    </div>
    <div style="line-height:2.2;margin-top:12px">
      <?= $badge('sub_name_client', 'نام کانفیگ کاربر', '1') ?>
      <?= $badge('sub_pretty', 'لینک زیبا', '1') ?>
      <?= $badge('deliver_msg_miniapp', 'پیام تحویل مینی‌اپ', '0') ?>
      <?= $badge('deliver_msg_reseller', 'پیام تحویل نمایندگی', '0') ?>
      <?= $badge('usr_renew_enabled', 'تمدید کاربر', '1') ?>
      <?= $badge('usr_del_enabled', 'حذف کاربر', '0') ?>
    </div>
  </div>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="save">
    <input type="hidden" name="tab" value="simple">
    <input type="hidden" name="keys" value="<?= h(implode(',', $QUICK)) ?>">

    <div class="card">
      <div class="card-head tight">
        <div>
          <div class="card-title">⚡ تنظیمات پرکاربرد</div>
          <div class="card-sub">۹۰٪ کارها با همین چند گزینه انجام می‌شود؛ بقیه در تب‌های دیگر.</div>
        </div>
      </div>
      <div class="form-grid g2">
        <?php foreach ($QUICK as $k): if (isset($BYKEY[$k])) echo $fld($BYKEY[$k]); endforeach; ?>
      </div>
      <div class="sticky-acts">
        <button class="btn btn-primary" type="submit"<?= $canEdit ? '' : ' disabled' ?>>💾 ذخیره</button>
      </div>
    </div>
  </form>

  <div class="card compact">
    <div class="card-head tight">
      <div>
        <div class="card-title">🧪 تست لینک ساب</div>
        <div class="card-sub">کد ساب یک سرویس را وارد کنید تا صفحهٔ اشتراک باز شود.</div>
      </div>
    </div>
    <div class="sb-test">
      <div class="field">
        <label>کد ساب</label>
        <input type="text" id="subTestCode" class="ltr mono" value="<?= h($sampleId) ?>" placeholder="40uk4pon5ac6bzca">
        <div class="hint"><?= $sampleId !== '' ? 'آخرین کد ساب موجود خودکار پر شد.' : 'هنوز سرویسی با کد ساب وجود ندارد.' ?></div>
      </div>
      <div class="field">
        <label>&nbsp;</label>
        <div class="btn-row">
          <button type="button" class="btn btn-primary" id="subTestGo">🚀 باز کردن</button>
          <button type="button" class="btn btn-ghost" id="subTestCopy">📋 کپی</button>
        </div>
      </div>
    </div>
    <div class="sb-out mono" id="subTestOut">—</div>
  </div>

  <script>
    (function () {
      var tpl  = <?= json_encode($sample, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
      var inp  = document.getElementById('subTestCode');
      var out  = document.getElementById('subTestOut');
      var go   = document.getElementById('subTestGo');
      var cp   = document.getElementById('subTestCopy');
      if (!inp || !out) return;

      function url() {
        var c = (inp.value || '').trim();
        return c ? tpl.replace('CODE', encodeURIComponent(c)) : '';
      }
      function paint() {
        var u = url();
        out.textContent = u || '—';
      }
      inp.addEventListener('input', paint);
      paint();

      if (go) go.addEventListener('click', function () {
        var u = url();
        if (u) window.open(u, '_blank');
      });
      if (cp) cp.addEventListener('click', function () {
        var u = url();
        if (!u) return;
        var old = cp.textContent;
        function done(ok) {
          cp.textContent = ok ? '✅ کپی شد' : '❌ نشد';
          setTimeout(function () { cp.textContent = old; }, 1500);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(u).then(function () { done(true); }, function () { done(false); });
        } else { done(false); }
      });
    })();
  </script>

<?php elseif ($tab === 'theme'): ?>

  <?php if (!$hasTheme): ?>
    <div class="alert a-err">
      موتور تم پیدا نشد. فایل <code>app/Service/SubTheme.php</code> و پوشهٔ <code>assets/sub/</code> را روی هاست آپلود کنید.
    </div>
  <?php else: ?>

    <?php if ($missing): ?>
      <div class="alert a-warn">
        فایل CSS این تم‌ها روی هاست نیست: <b><?= h(implode(' ، ', $missing)) ?></b>
        — پوشهٔ <code>assets/sub/</code> را کامل آپلود کنید.
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head tight">
        <div>
          <div class="card-title">🎨 تم صفحهٔ اشتراک</div>
          <div class="card-sub">
            ظاهر صفحه‌ای که کاربر با باز کردن لینک اشتراک می‌بیند.
            هر تم سبک کاملاً متفاوتی دارد و هیچ تأثیری روی کانفیگ‌ها و لینک‌ها ندارد.
          </div>
        </div>
      </div>

      <?php if ($sampleId === ''): ?>
        <div class="alert a-info">برای پیش‌نمایش زنده باید حداقل یک سرویس با کد ساب وجود داشته باشد.</div>
      <?php endif; ?>

      <form method="post" id="thForm">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="theme_save">
        <input type="hidden" name="tab" value="theme">
        <input type="hidden" name="theme" id="thPick" value="<?= h($curTheme) ?>">

        <div class="sb-themes">
          <?php foreach ($THEMES as $tkey => $tm):
              $sw   = (array)($tm['sw'] ?? ['#111', '#555', '#999']);
              $c0   = (string)($sw[0] ?? '#111');
              $c1   = (string)($sw[1] ?? '#555');
              $c2   = (string)($sw[2] ?? '#999');
              $isOn = ((string)$tkey === $curTheme);
              $rdy  = SubTheme::ready((string)$tkey);
              $prev = ($sampleId !== '')
                  ? $baseUrl . '/sub.php?id=' . rawurlencode($sampleId) . '&theme=' . rawurlencode((string)$tkey)
                  : '';
          ?>
            <div class="sb-th<?= $isOn ? ' on' : '' ?><?= $rdy ? '' : ' miss' ?>" data-th="<?= h((string)$tkey) ?>">
              <?php if ($isOn): ?><span class="sb-thflag">✓ فعال</span><?php endif; ?>
              <?php if (!$rdy): ?><span class="sb-thflag w">فایل نیست</span><?php endif; ?>

              <div class="sb-thp" style="background:<?= h($c0) ?>">
                <div class="mock" style="background:<?= h($c1) ?>1a;border:1px solid <?= h($c1) ?>44">
                  <div class="mrow">
                    <span class="mdot" style="background:<?= h($c1) ?>"></span>
                    <span class="mln" style="background:<?= h($c2) ?>66"></span>
                  </div>
                  <div class="mbar" style="background:<?= h($c2) ?>22"><i style="background:<?= h($c1) ?>"></i></div>
                  <div class="mgrid">
                    <span class="mcell" style="background:<?= h($c2) ?>28"></span>
                    <span class="mcell" style="background:<?= h($c2) ?>28"></span>
                    <span class="mcell" style="background:<?= h($c2) ?>28"></span>
                  </div>
                </div>
              </div>

              <div class="sb-thb">
                <div class="nm"><?= h((string)$tm['icon']) ?> <?= h((string)$tm['name']) ?></div>
                <div class="ds"><?= h((string)$tm['desc']) ?></div>
                <div class="sb-thtags">
                  <?php foreach ((array)($tm['tags'] ?? []) as $tg): ?>
                    <span class="sb-thtag"><?= h((string)$tg) ?></span>
                  <?php endforeach; ?>
                </div>
                <div class="sb-thsw">
                  <span style="background:<?= h($c0) ?>"></span>
                  <span style="background:<?= h($c1) ?>"></span>
                  <span style="background:<?= h($c2) ?>"></span>
                </div>
                <div class="sb-thact">
                  <?php if ($prev !== ''): ?>
                    <a class="btn btn-sm btn-ghost" href="<?= h($prev) ?>" target="_blank" rel="noopener">👁 پیش‌نمایش</a>
                  <?php endif; ?>
                  <?php if ($canEdit && $rdy && !$isOn): ?>
                    <button type="button" class="btn btn-sm btn-primary" data-th-pick="<?= h((string)$tkey) ?>">✓ انتخاب</button>
                  <?php elseif ($isOn): ?>
                    <span class="btn btn-sm" style="pointer-events:none;opacity:.65">تم فعلی</span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </form>
    </div>

    <div class="card compact">
      <div class="card-head tight"><div><div class="card-title">ℹ️ نکته‌ها</div></div></div>
      <ul style="margin:0;padding-inline-start:20px;line-height:2.1;font-size:12.5px;color:var(--muted)">
        <li>تم فقط ظاهر صفحهٔ وب را عوض می‌کند؛ خروجی کلاینت‌ها (base64/raw) دست‌نخورده می‌ماند.</li>
        <li>برای تست بدون تغییر تم اصلی، به انتهای لینک ساب عبارت <code>&amp;theme=کلید</code> را اضافه کنید.</li>
        <li>تم‌های روشن (میست و کندی) برای کاربرانی که صفحه را چاپ یا اسکرین‌شات می‌گیرند مناسب‌ترند.</li>
        <li>فایل هر تم در <code>assets/sub/</code> است و مستقیم قابل ویرایش است.</li>
      </ul>
    </div>

    <script>
      (function () {
        var form = document.getElementById('thForm');
        var pick = document.getElementById('thPick');
        if (!form || !pick) return;
        document.addEventListener('click', function (e) {
          var b = e.target.closest('[data-th-pick]');
          if (!b) return;
          pick.value = b.getAttribute('data-th-pick');
          form.submit();
        });
      })();
    </script>

  <?php endif; ?>

<?php elseif (isset($TAB_GROUPS[$tab])): ?>

  <?php
  $keys = [];
  foreach ($TAB_GROUPS[$tab] as $gk) {
      foreach ($GROUPS[$gk]['items'] as $it) $keys[] = $it['k'];
  }
  ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="save">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <input type="hidden" name="keys" value="<?= h(implode(',', $keys)) ?>">

    <div class="card">
      <?php foreach ($TAB_GROUPS[$tab] as $gk) echo $renderGroup($GROUPS[$gk]); ?>

      <?php if ($tab === 'links'): ?>
        <div class="alert a-info">
          نمونهٔ لینک فعلی: <code class="ltr"><?= h($sample) ?></code>
          — ظاهر این صفحه را در تب <a href="?p=subs&amp;tab=theme">🎨 تم صفحهٔ ساب</a> عوض کنید.
        </div>
      <?php endif; ?>

      <?php if ($tab === 'buttons'): ?>
        <div class="alert a-info">
          دکمه‌های منوی ربات تلگرام (جای‌گذاری، ترتیب و کشیدن‌ورهاکردن) در بخش
          <a href="?p=botbuttons">🎛 دکمه‌های ربات</a> مدیریت می‌شود.
        </div>
      <?php endif; ?>

      <?php if ($tab === 'actions'): ?>
        <div class="alert a-warn">
          مبلغ عودتی از «آخرین پرداخت همان سرویس» محاسبه می‌شود؛ اکانت تست و سرویس منقضی عودت نمی‌گیرند.
        </div>
      <?php endif; ?>

      <div class="sticky-acts">
        <button class="btn btn-primary" type="submit"<?= $canEdit ? '' : ' disabled' ?>>💾 ذخیره</button>
        <a class="btn btn-ghost" href="?p=subs&amp;tab=simple">↩️ بازگشت به تب ساده</a>
      </div>
    </div>
  </form>

<?php else: /* custom */ ?>

  <?php $OPTS = $loadOpts(); ?>

  <div class="card">
    <div class="card-head tight">
      <div>
        <div class="card-title">🧩 آپشن‌های دلخواه شما</div>
        <div class="card-sub">هر تنظیمی که لازم دارید بسازید؛ در کد با <code>DB::setting('کلید')</code> در دسترس است.</div>
      </div>
    </div>

    <?php if (!$OPTS): ?>
      <div class="empty ic">هنوز آپشن دلخواهی نساخته‌اید.</div>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="opt_vals">
        <input type="hidden" name="tab" value="custom">
        <div class="form-grid g2">
          <?php foreach ($OPTS as $o):
              $k   = (string)($o['key'] ?? '');
              $t   = (string)($o['type'] ?? 'text');
              $cur = (string)DB::setting($k, '');
          ?>
            <div class="field<?= $t === 'text' ? ' full' : '' ?>">
              <?php if ($t === 'bool'): ?>
                <label class="check">
                  <input type="checkbox" name="v_<?= h($k) ?>" value="1"<?= $cur === '1' ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>>
                  <span><?= h((string)($o['label'] ?? $k)) ?></span>
                </label>
              <?php else: ?>
                <label><?= h((string)($o['label'] ?? $k)) ?></label>
                <?php if ($t === 'select'): ?>
                  <select name="v_<?= h($k) ?>"<?= $canEdit ? '' : ' disabled' ?>>
                    <?php foreach ((array)($o['opts'] ?? []) as $c): ?>
                      <option value="<?= h((string)$c) ?>"<?= $cur === (string)$c ? ' selected' : '' ?>><?= h((string)$c) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php elseif ($t === 'int'): ?>
                  <input type="number" class="ltr" name="v_<?= h($k) ?>" value="<?= h($cur) ?>"<?= $canEdit ? '' : ' disabled' ?>>
                <?php else: ?>
                  <input type="text" name="v_<?= h($k) ?>" value="<?= h($cur) ?>"<?= $canEdit ? '' : ' disabled' ?>>
                <?php endif; ?>
              <?php endif; ?>

              <?php if (trim((string)($o['note'] ?? '')) !== ''): ?>
                <div class="hint"><?= h((string)$o['note']) ?></div>
              <?php endif; ?>
              <div class="hint mono ltr sb-key"><?= h($k) ?> • <?= h((string)($OPT_TYPES[$t] ?? $t)) ?></div>

              <?php if ($canEdit): ?>
                <div class="btn-row">
                  <button class="btn btn-sm btn-ghost" type="submit" name="up" value="<?= h($k) ?>">⬆️</button>
                  <button class="btn btn-sm btn-ghost" type="submit" name="down" value="<?= h($k) ?>">⬇️</button>
                  <button class="btn btn-sm btn-ghost" type="submit" name="del" value="<?= h($k) ?>" onclick="return confirm('این آپشن حذف شود؟')">🗑</button>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="sticky-acts">
          <button class="btn btn-primary" type="submit"<?= $canEdit ? '' : ' disabled' ?>>💾 ذخیرهٔ مقادیر</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($canEdit): ?>
    <div class="card">
      <div class="card-head tight">
        <div>
          <div class="card-title">➕ افزودن آپشن</div>
          <div class="card-sub">کلید باید با <code>sub_</code> شروع شود.</div>
        </div>
      </div>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="opt_add">
        <input type="hidden" name="tab" value="custom">
        <div class="form-grid g2">
          <div class="field">
            <label>کلید</label>
            <input type="text" name="o_key" class="ltr mono" placeholder="sub_note" required>
          </div>
          <div class="field">
            <label>عنوان نمایشی</label>
            <input type="text" name="o_label" placeholder="یادداشت صفحهٔ ساب">
          </div>
          <div class="field">
            <label>نوع</label>
            <select name="o_type" id="soType">
              <?php foreach ($OPT_TYPES as $tv => $tl): ?>
                <option value="<?= h($tv) ?>"><?= h($tl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" id="soValWrap">
            <label>مقدار اولیه</label>
            <input type="text" name="o_val">
          </div>
          <div class="field" id="soBoolWrap" style="display:none">
            <label class="check"><input type="checkbox" name="o_bool" value="1"><span>از ابتدا روشن باشد</span></label>
          </div>
          <div class="field full" id="soOptsWrap" style="display:none">
            <label>مقادیر انتخابی (با کاما)</label>
            <input type="text" name="o_opts" placeholder="کم, متوسط, زیاد">
          </div>
          <div class="field full">
            <label>توضیح (اختیاری)</label>
            <input type="text" name="o_note">
          </div>
        </div>
        <div class="sticky-acts">
          <button class="btn btn-primary" type="submit">➕ افزودن</button>
        </div>
      </form>
    </div>

    <script>
      (function () {
        var t = document.getElementById('soType');
        if (!t) return;
        function paint() {
          var v = t.value;
          document.getElementById('soValWrap').style.display = (v === 'text' || v === 'int') ? '' : 'none';
          document.getElementById('soBoolWrap').style.display = (v === 'bool') ? '' : 'none';
          document.getElementById('soOptsWrap').style.display = (v === 'select') ? '' : 'none';
        }
        t.addEventListener('change', paint);
        paint();
      })();
    </script>
  <?php endif; ?>

<?php endif; ?>
