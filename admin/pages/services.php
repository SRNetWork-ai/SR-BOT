<?php
if (!can('services.view')) { echo denyBox('این بخش برای شما فعال نیست.'); return; }

/**
 * مدیریت سرویس‌ها (اکانت‌های ساخته‌شده روی پنل) — نسخهٔ گسترش‌یافته
 * فیلترهای پیشرفته، کاشی‌های آماری، نمایش مصرف/زمان به صورت گیج،
 * صفحهٔ جزئیات تب‌دار (مشخصات / لینک‌ها / سوابق / مدیریت) و اقدامات گروهی.
 */

$act = (string)($_POST['act'] ?? '');

/* بازگشت به همان فیلتری که کاربر داشت */
$sv_ret = static function (array $extra = []): array {
    $keep = [];
    foreach (['status', 'panel', 'type', 'exp', 'usage', 'sort', 'q'] as $k) {
        $v = trim((string)($_POST['f_' . $k] ?? ''));
        if ($v !== '' && $v !== '0') $keep[$k] = $v;
    }
    return $extra + $keep;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {

    /* ---------------------- ساخت دستی سرویس ---------------------- */
    if ($act === 'create') { need('services.edit', 'services');
        $tg   = pint('tg_id');
        $user = DB::one('SELECT * FROM {p}users WHERE tg_id = :t', [':t' => $tg])
             ?: DB::one('SELECT * FROM {p}users WHERE id = :t', [':t' => $tg]);
        $panel = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => pint('panel_id')]);
        $prod  = pint('product_id') ? DB::one('SELECT * FROM {p}products WHERE id = :id', [':id' => pint('product_id')]) : null;

        if (!$user)  { flash('err', 'کاربری با این آیدی پیدا نشد. اول کاربر را بسازید.'); back('services'); }
        if (!$panel) { flash('err', 'پنل را انتخاب کنید.'); back('services'); }

        $opts = [
            'volume_gb'  => $prod ? (float)$prod['volume_gb'] : pflt('volume_gb'),
            'days'       => $prod ? (int)$prod['days'] : pint('days'),
            'hours'      => pint('hours'),
            'ip_limit'   => $prod ? (int)$prod['ip_limit'] : pint('ip_limit'),
            'inbound_id' => pint('inbound_id'),
            'is_test'    => pchk('is_test') === 1,
            'product'    => $prod,
        ];
        if (ptxt('username') !== '') $opts['username'] = ptxt('username');

        $r = Svc::create($user, $panel, $opts);
        if (!empty($r['ok'])) {
            $s = $r['service'] ?? null;
            flash('ok', '✅ سرویس ساخته شد: <span class="mono">' . h((string)($s['client_email'] ?? '')) . '</span>');
            if ($s && pchk('notify')) {
                Tg::send((int)$user['tg_id'], "🎁 <b>سرویس جدید برای شما ساخته شد</b>\n\n" . Svc::summary($s));
            }
            if ($s && !empty($s['id'])) back('services', ['view' => (int)$s['id']]);
        } else {
            flash('err', h((string)($r['message'] ?? 'خطا در ساخت سرویس.')));
        }
        back('services');
    }

    /* ---------------------- همگام‌سازی گروهی ---------------------- */
    if ($act === 'syncmany') { need('services.sync', 'services');
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))))));
        $ids = array_slice($ids, 0, 40);
        if (!$ids) { flash('err', 'سرویسی برای همگام‌سازی پیدا نشد.'); back('services', $sv_ret()); }
        $rows = DB::all('SELECT * FROM {p}services WHERE id IN (' . implode(',', $ids) . ')');
        $ok = 0;
        try { $ok = count((array)Svc::syncStale($rows, 120, 20, false)); } catch (Throwable $e) { $ok = 0; }
        flash('ok', 'همگام‌سازی انجام شد — ' . fa_num(count($rows)) . ' سرویس بررسی شد.');
        back('services', $sv_ret());
    }

    $sid = pint('id');
    $S   = $sid ? Svc::find($sid) : null;

    if ($S) {
        if ($act === 'sync') { need('services.sync', 'services');
            $r = Svc::sync($S);
            flash(!empty($r['ok']) ? 'ok' : 'err', !empty($r['ok']) ? 'مصرف و وضعیت سرویس به‌روز شد.' : h((string)($r['message'] ?? 'همگام‌سازی ناموفق بود.')));
            back('services', ['view' => $sid]);
        }
        if ($act === 'toggle') { need('services.edit', 'services');
            $enable = (string)$S['status'] !== 'active';
            $r = Svc::toggle($S, $enable);
            flash(!empty($r['ok']) ? 'ok' : 'err', !empty($r['ok'])
                ? ($enable ? 'سرویس فعال شد.' : 'سرویس غیرفعال شد.')
                : h((string)($r['message'] ?? 'عملیات ناموفق بود.')));
            back('services', ['view' => $sid]);
        }
        if ($act === 'renew') { need('services.edit', 'services');
            $prod = DB::one('SELECT * FROM {p}products WHERE id = :id', [':id' => pint('product_id')]);
            if (!$prod) { flash('err', 'محصول تمدید را انتخاب کنید.'); back('services', ['view' => $sid]); }
            $r = Svc::renew($S, $prod);
            if (!empty($r['ok'])) {
                flash('ok', 'سرویس تمدید شد.');
                if (pchk('notify')) {
                    $fresh = Svc::find($sid);
                    Tg::send((int)$S['tg_id'], "♻️ <b>سرویس شما تمدید شد</b>\n\n" . Svc::summary($fresh ?: $S));
                }
            } else {
                flash('err', h((string)($r['message'] ?? 'تمدید ناموفق بود.')));
            }
            back('services', ['view' => $sid]);
        }
        if ($act === 'notify') { need('services.edit', 'services');
            $txt = "🔔 <b>اطلاعات سرویس شما</b>\n\n" . Svc::summary($S);
            $sub = '';
            try { $sub = Svc::subUrl($S); } catch (Throwable $e) { $sub = ''; }
            if ($sub !== '') $txt .= "\n\n🔗 <b>لینک اشتراک:</b>\n<code>" . h($sub) . "</code>";
            if (pchk('with_cfg')) {
                $cfg = [];
                try { $cfg = Svc::liveConfigs($S); } catch (Throwable $e) { $cfg = []; }
                if ($cfg) {
                    $txt .= "\n\n📦 <b>کانفیگ‌ها:</b>";
                    foreach (array_slice($cfg, 0, 6) as $c) $txt .= "\n<code>" . h((string)$c) . "</code>";
                }
            }
            $r = Tg::send((int)$S['tg_id'], $txt);
            flash(!empty($r['ok']) ? 'ok' : 'err', !empty($r['ok']) ? 'اطلاعات سرویس برای کاربر ارسال شد.' : 'ارسال پیام به کاربر انجام نشد.');
            back('services', ['view' => $sid]);
        }
        if ($act === 'del') { need('services.delete', 'services');
            Svc::remove($S, true);
            flash('ok', 'سرویس از پنل حذف شد.');
            back('services', $sv_ret());
        }
    }
}

/* ======================= ۱) فیلترها ======================= */

$sv_status = (string)($_GET['status'] ?? '');
$sv_panel  = (int)($_GET['panel'] ?? 0);
$sv_user   = (int)($_GET['user'] ?? 0);
$sv_type   = (string)($_GET['type'] ?? '');
$sv_exp    = (string)($_GET['exp'] ?? '');
$sv_usage  = (string)($_GET['usage'] ?? '');
$sv_sort   = (string)($_GET['sort'] ?? 'new');
$q         = trim((string)($_GET['q'] ?? ''));
$viewId    = (int)($_GET['view'] ?? 0);
$sv_cfg    = (string)($_GET['cfg'] ?? '');
$sv_tab    = (string)($_GET['tab'] ?? 'info');

if (!in_array($sv_sort, ['new', 'old', 'exp', 'used', 'vol'], true)) $sv_sort = 'new';

$sv_safeAll = static function (string $sql, array $par = []): array {
    try { return DB::all($sql, $par); } catch (Throwable $e) { return []; }
};
$sv_safeOne = static function (string $sql, array $par = []): array {
    try { return (array)(DB::one($sql, $par) ?: []); } catch (Throwable $e) { return []; }
};
$sv_safeVal = static function (string $sql, array $par = [], $def = 0) {
    try { return DB::val($sql, $par, $def); } catch (Throwable $e) { return $def; }
};

$panels   = $sv_safeAll('SELECT * FROM {p}panels ORDER BY sort ASC, id ASC');
$products = $sv_safeAll('SELECT * FROM {p}products ORDER BY sort ASC, id ASC');

$w = [];
$pr = [];
if ($sv_status !== '') { $w[] = 's.status = :st'; $pr[':st'] = $sv_status; }
if ($sv_panel)  { $w[] = 's.panel_id = :pa'; $pr[':pa'] = $sv_panel; }
if ($sv_user)   { $w[] = 's.user_id = :us';  $pr[':us'] = $sv_user; }
if ($sv_type === 'test')     $w[] = 's.is_test = 1';
if ($sv_type === 'sale')     $w[] = 's.is_test = 0';
if ($sv_type === 'reseller') $w[] = 's.is_reseller = 1';
if ($sv_exp === 'soon3')  $w[] = 's.expire_at IS NOT NULL AND s.expire_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)';
if ($sv_exp === 'soon7')  $w[] = 's.expire_at IS NOT NULL AND s.expire_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)';
if ($sv_exp === 'over')   $w[] = 's.expire_at IS NOT NULL AND s.expire_at < NOW()';
if ($sv_exp === 'never')  $w[] = 's.expire_at IS NULL';
if ($sv_usage === 'high') $w[] = 's.volume_gb > 0 AND s.used_bytes >= (s.volume_gb * 1073741824 * 0.85)';
if ($sv_usage === 'full') $w[] = 's.volume_gb > 0 AND s.used_bytes >= (s.volume_gb * 1073741824)';
if ($sv_usage === 'idle') $w[] = 's.used_bytes = 0';
if ($q !== '') {
    $w[] = '(s.client_email LIKE :q OR s.tg_id LIKE :q OR s.client_uuid LIKE :q OR s.sub_id LIKE :q OR u.first_name LIKE :q OR u.username LIKE :q)';
    $pr[':q'] = '%' . en_num($q) . '%';
}
$where = $w ? ' WHERE ' . implode(' AND ', $w) : '';

$sv_orderMap = [
    'new'  => 's.id DESC',
    'old'  => 's.id ASC',
    'exp'  => 's.expire_at IS NULL ASC, s.expire_at ASC',
    'used' => 's.used_bytes DESC',
    'vol'  => 's.volume_gb DESC',
];
$order = $sv_orderMap[$sv_sort];

$rows = $sv_safeAll("SELECT s.*, pa.name AS panel_name, u.first_name, u.username
                     FROM {p}services s
                     LEFT JOIN {p}panels pa ON pa.id = s.panel_id
                     LEFT JOIN {p}users u ON u.id = s.user_id
                     $where ORDER BY $order LIMIT 300", $pr);

/* ======================= ۲) آمار کلی ======================= */

$ST = $sv_safeOne("SELECT COUNT(*) AS total,
        SUM(CASE WHEN status = 'active'   THEN 1 ELSE 0 END) AS act,
        SUM(CASE WHEN status = 'expired'  THEN 1 ELSE 0 END) AS exp,
        SUM(CASE WHEN status = 'disabled' THEN 1 ELSE 0 END) AS dis,
        SUM(CASE WHEN status = 'deleted'  THEN 1 ELSE 0 END) AS del,
        SUM(CASE WHEN is_test = 1 THEN 1 ELSE 0 END) AS tst,
        SUM(CASE WHEN is_reseller = 1 THEN 1 ELSE 0 END) AS rsl,
        COALESCE(SUM(used_bytes), 0) AS used
      FROM {p}services");
$sv_total = (int)($ST['total'] ?? 0);
$sv_act   = (int)($ST['act'] ?? 0);
$sv_expd  = (int)($ST['exp'] ?? 0);
$sv_dis   = (int)($ST['dis'] ?? 0);
$sv_tst   = (int)($ST['tst'] ?? 0);
$sv_rsl   = (int)($ST['rsl'] ?? 0);
$sv_used  = (float)($ST['used'] ?? 0);

$sv_soon3 = (int)$sv_safeVal("SELECT COUNT(*) FROM {p}services WHERE status = 'active' AND expire_at IS NOT NULL AND expire_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)");
$sv_soon7 = (int)$sv_safeVal("SELECT COUNT(*) FROM {p}services WHERE status = 'active' AND expire_at IS NOT NULL AND expire_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)");
$sv_hot   = (int)$sv_safeVal("SELECT COUNT(*) FROM {p}services WHERE status = 'active' AND volume_gb > 0 AND used_bytes >= (volume_gb * 1073741824 * 0.85)");
$sv_new7  = (int)$sv_safeVal('SELECT COUNT(*) FROM {p}services WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)');

$sv_byPanel = $sv_safeAll('SELECT pa.name AS nm, COUNT(*) AS c
                             FROM {p}services s LEFT JOIN {p}panels pa ON pa.id = s.panel_id
                            GROUP BY s.panel_id ORDER BY c DESC LIMIT 5');
$sv_pmax = 1;
foreach ($sv_byPanel as $bp) $sv_pmax = max($sv_pmax, (int)$bp['c']);

/* ======================= ۳) دادهٔ صفحهٔ جزئیات ======================= */

$V = $viewId ? Svc::find($viewId) : null;
$V_panel = []; $V_user = []; $V_orders = []; $V_sib = []; $V_group = [];
$V_cfgs = []; $V_paid = 0; $V_plans = []; $V_sub = ''; $V_local = ''; $V_pan = '';
$V_upct = 0; $V_tpct = 0; $V_left = ''; $V_usedGb = 0.0; $V_leftGb = 0.0;

if ($V) {
    $V_panel = $sv_safeOne('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)$V['panel_id']]);
    $V_user  = $sv_safeOne('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)$V['user_id']]);
    $V_orders = $sv_safeAll('SELECT o.*, p.name AS pname FROM {p}orders o
                             LEFT JOIN {p}products p ON p.id = o.product_id
                             WHERE o.service_id = :s ORDER BY o.id DESC LIMIT 10', [':s' => (int)$V['id']]);
    $V_sib = $sv_safeAll('SELECT id, client_email, status, expire_at, volume_gb, used_bytes FROM {p}services
                          WHERE user_id = :u AND id <> :s ORDER BY id DESC LIMIT 6',
                          [':u' => (int)$V['user_id'], ':s' => (int)$V['id']]);
    $gk = trim((string)($V['group_key'] ?? ''));
    if ($gk !== '') {
        $V_group = $sv_safeAll('SELECT s.id, s.client_email, s.status, pa.name AS panel_name FROM {p}services s
                                LEFT JOIN {p}panels pa ON pa.id = s.panel_id
                                WHERE s.group_key = :g AND s.id <> :s ORDER BY s.id ASC LIMIT 12',
                                [':g' => $gk, ':s' => (int)$V['id']]);
    }

    try { $V_paid = (int)Svc::paidFor($V); } catch (Throwable $e) { $V_paid = 0; }
    try { $V_plans = Svc::renewPlans($V); } catch (Throwable $e) { $V_plans = []; }
    if (!$V_plans) $V_plans = $products;

    try { $V_sub   = Svc::subUrl($V); }   catch (Throwable $e) { $V_sub = ''; }
    try { $V_local = Svc::localSub($V); } catch (Throwable $e) { $V_local = ''; }
    $V_pan = trim((string)($V['sub_link'] ?? ''));

    if ($sv_cfg === 'live') {
        try { $V_cfgs = (array)Svc::liveConfigs($V); } catch (Throwable $e) { $V_cfgs = []; }
    } else {
        try { $V_cfgs = (array)Svc::storedConfigs($V); } catch (Throwable $e) { $V_cfgs = []; }
    }

    /* محاسبهٔ گیج مصرف و زمان */
    $vol = (float)$V['volume_gb'];
    $V_usedGb = bytes2gb((int)$V['used_bytes']);
    $V_upct = $vol > 0 ? min(100, (int)round(($V_usedGb / $vol) * 100)) : 0;
    $V_leftGb = $vol > 0 ? max(0, round($vol - $V_usedGb, 2)) : 0;
    $V_left = remaining_human($V['expire_at'] ? (string)$V['expire_at'] : null);
    if (!empty($V['expire_at'])) {
        $st = strtotime((string)$V['created_at']);
        $en = strtotime((string)$V['expire_at']);
        $now = time();
        $V_tpct = ($en > $st) ? max(0, min(100, (int)round((($now - $st) / ($en - $st)) * 100))) : 100;
    }
}

/* لینک فیلتر با حفظ پارامترهای دیگر */
$sv_link = static function (array $ov = []) use ($sv_status, $sv_panel, $sv_type, $sv_exp, $sv_usage, $sv_sort, $q): string {
    $base = ['p' => 'services', 'status' => $sv_status, 'panel' => $sv_panel ?: '', 'type' => $sv_type,
             'exp' => $sv_exp, 'usage' => $sv_usage, 'sort' => $sv_sort, 'q' => $q];
    foreach ($ov as $k => $v) $base[$k] = $v;
    $out = [];
    foreach ($base as $k => $v) { if ($v !== '' && $v !== null) $out[$k] = $v; }
    return 'index.php?' . http_build_query($out);
};

$sv_ids = [];
foreach (array_slice($rows, 0, 40) as $r0) $sv_ids[] = (int)$r0['id'];
?>

<?php
/* چیپ وضعیت مخصوص سرویس (شامل missing که در badge عمومی نیست) */
$sv_chip = static function (?string $st): string {
    $map = [
        'active'   => ['b-green',  '✓ فعال'],
        'expired'  => ['b-orange', '⏳ منقضی'],
        'disabled' => ['b-gray',   '⛔ غیرفعال'],
        'deleted'  => ['b-red',    '🗑 حذف شده'],
        'missing'  => ['b-purple', '🚫 نبودن در پنل'],
    ];
    [$c, $l] = $map[(string)$st] ?? ['b-gray', (string)$st];
    return '<span class="badge ' . $c . '">' . h($l) . '</span>';
};
?>

<?php if (!$V): ?>

<!-- ======================= کاشی‌های آمار ======================= -->
<div class="svc-tiles">
  <a class="svc-tile t-blue fade-up<?= $sv_status === '' && $sv_type === '' && $sv_exp === '' && $sv_usage === '' ? ' on' : '' ?>" style="--i:0" href="<?= h($sv_link(['status' => '', 'type' => '', 'exp' => '', 'usage' => ''])) ?>">
    <span class="ic">📦</span>
    <b><?= fa_num($sv_total) ?></b>
    <i>کل سرویس‌ها</i>
  </a>
  <a class="svc-tile t-green fade-up<?= $sv_status === 'active' ? ' on' : '' ?>" style="--i:1" href="<?= h($sv_link(['status' => 'active'])) ?>">
    <span class="ic">✅</span>
    <b><?= fa_num($sv_act) ?></b>
    <i>فعال</i>
  </a>
  <a class="svc-tile t-orange fade-up<?= $sv_exp === 'soon3' ? ' on' : '' ?>" style="--i:2" href="<?= h($sv_link(['exp' => 'soon3', 'status' => 'active'])) ?>">
    <span class="ic">⏰</span>
    <b><?= fa_num($sv_soon3) ?></b>
    <i>زیر ۳ روز تا انقضا</i>
  </a>
  <a class="svc-tile t-red fade-up<?= $sv_usage === 'high' ? ' on' : '' ?>" style="--i:3" href="<?= h($sv_link(['usage' => 'high', 'status' => 'active'])) ?>">
    <span class="ic">🔥</span>
    <b><?= fa_num($sv_hot) ?></b>
    <i>مصرف بالای ۸۵٪</i>
  </a>
  <a class="svc-tile t-gray fade-up<?= $sv_status === 'expired' ? ' on' : '' ?>" style="--i:4" href="<?= h($sv_link(['status' => 'expired'])) ?>">
    <span class="ic">⌛</span>
    <b><?= fa_num($sv_expd) ?></b>
    <i>منقضی</i>
  </a>
  <a class="svc-tile t-purple fade-up<?= $sv_type === 'test' ? ' on' : '' ?>" style="--i:5" href="<?= h($sv_link(['type' => 'test'])) ?>">
    <span class="ic">🧪</span>
    <b><?= fa_num($sv_tst) ?></b>
    <i>اکانت تست</i>
  </a>
  <a class="svc-tile t-cyan fade-up" style="--i:6" href="<?= h($sv_link(['sort' => 'used'])) ?>">
    <span class="ic">📊</span>
    <b class="xs"><?= fa_num(human_bytes((int)$sv_used)) ?></b>
    <i>مصرف کل ترافیک</i>
  </a>
  <a class="svc-tile t-blue fade-up" style="--i:7" href="<?= h($sv_link(['sort' => 'new'])) ?>">
    <span class="ic">🆕</span>
    <b><?= fa_num($sv_new7) ?></b>
    <i>ساخته‌شده در ۷ روز</i>
  </a>
</div>

<div class="grid g2 mt3">
  <!-- پراکندگی روی پنل‌ها -->
  <div class="card compact">
    <div class="card-head">
      <div>
        <div class="card-title">🖥 پراکندگی روی پنل‌ها</div>
        <div class="card-sub">تعداد سرویس فعال و غیرفعال هر پنل</div>
      </div>
    </div>
    <?php if (!$sv_byPanel): ?>
      <div class="empty"><span class="ic">💭</span> هنوز سرویسی ساخته نشده.</div>
    <?php else: foreach ($sv_byPanel as $bp): $bpc = (int)$bp['c']; ?>
      <div class="pdist">
        <span class="nm"><?= h((string)($bp['nm'] ?: 'بدون پنل')) ?></span>
        <span class="bar sm g"><span style="width:<?= (int)round($bpc / $sv_pmax * 100) ?>%"></span></span>
        <span class="n mono"><?= fa_num($bpc) ?></span>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- اقدامات سریع -->
  <div class="card compact">
    <div class="card-head">
      <div>
        <div class="card-title">⚡ اقدامات سریع</div>
        <div class="card-sub">میانبرهای پرکاربرد سرویس‌ها</div>
      </div>
    </div>
    <div class="qa-grid">
      <a class="qa" href="<?= h($sv_link(['exp' => 'soon7', 'status' => 'active', 'sort' => 'exp'])) ?>">
        <span class="ic">⏳</span><span class="t">رو به انقضا</span><span class="n"><?= fa_num($sv_soon7) ?> سرویس</span>
      </a>
      <a class="qa" href="<?= h($sv_link(['usage' => 'full', 'status' => 'active'])) ?>">
        <span class="ic">📉</span><span class="t">حجم تمام‌شده</span><span class="n">نیازمند تمدید</span>
      </a>
      <a class="qa" href="<?= h($sv_link(['usage' => 'idle', 'type' => 'sale'])) ?>">
        <span class="ic">💤</span><span class="t">بدون مصرف</span><span class="n">احتمال مشکل اتصال</span>
      </a>
      <a class="qa" href="<?= h($sv_link(['type' => 'reseller'])) ?>">
        <span class="ic">👑</span><span class="t">سرویس نمایندگی</span><span class="n"><?= fa_num($sv_rsl) ?> مورد</span>
      </a>
    </div>
    <?php if (can('services.sync') && $sv_ids): ?>
      <form method="post" class="mt3">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="syncmany">
        <input type="hidden" name="ids" value="<?= h(implode(',', $sv_ids)) ?>">
        <input type="hidden" name="f_status" value="<?= h($sv_status) ?>">
        <input type="hidden" name="f_panel" value="<?= (int)$sv_panel ?>">
        <input type="hidden" name="f_q" value="<?= h($q) ?>">
        <button class="btn btn-block" type="submit">🔄 همگام‌سازی مصرف <?= fa_num(count($sv_ids)) ?> سرویس اول فهرست</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- ======================= فیلترها ======================= -->
<div class="card compact mt3 svc-filter">
  <form method="get" class="filter-grid">
    <input type="hidden" name="p" value="services">
    <div class="field">
      <label>جستجو</label>
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="نام‌کاربری، آیدی تلگرام، UUID یا کد ساب">
    </div>
    <div class="field">
      <label>وضعیت</label>
      <select name="status">
        <option value="">همه</option>
        <?php foreach (['active' => 'فعال', 'expired' => 'منقضی', 'disabled' => 'غیرفعال', 'deleted' => 'حذف شده', 'missing' => 'نبودن در پنل'] as $k => $lb): ?>
          <option value="<?= $k ?>" <?= $sv_status === $k ? 'selected' : '' ?>><?= $lb ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>پنل</label>
      <select name="panel">
        <option value="">همه پنل‌ها</option>
        <?php foreach ($panels as $pn): ?>
          <option value="<?= (int)$pn['id'] ?>" <?= $sv_panel === (int)$pn['id'] ? 'selected' : '' ?>><?= h((string)$pn['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>نوع</label>
      <select name="type">
        <option value="">همه</option>
        <option value="sale" <?= $sv_type === 'sale' ? 'selected' : '' ?>>فروشی</option>
        <option value="test" <?= $sv_type === 'test' ? 'selected' : '' ?>>تست</option>
        <option value="reseller" <?= $sv_type === 'reseller' ? 'selected' : '' ?>>نمایندگی</option>
      </select>
    </div>
    <div class="field">
      <label>انقضا</label>
      <select name="exp">
        <option value="">مهم نیست</option>
        <option value="soon3" <?= $sv_exp === 'soon3' ? 'selected' : '' ?>>کمتر از ۳ روز</option>
        <option value="soon7" <?= $sv_exp === 'soon7' ? 'selected' : '' ?>>کمتر از ۷ روز</option>
        <option value="over" <?= $sv_exp === 'over' ? 'selected' : '' ?>>گذشته</option>
        <option value="never" <?= $sv_exp === 'never' ? 'selected' : '' ?>>بدون تاریخ</option>
      </select>
    </div>
    <div class="field">
      <label>مصرف</label>
      <select name="usage">
        <option value="">مهم نیست</option>
        <option value="high" <?= $sv_usage === 'high' ? 'selected' : '' ?>>بالای ۸۵٪</option>
        <option value="full" <?= $sv_usage === 'full' ? 'selected' : '' ?>>تمام‌شده</option>
        <option value="idle" <?= $sv_usage === 'idle' ? 'selected' : '' ?>>بدون مصرف</option>
      </select>
    </div>
    <div class="field">
      <label>مرتب‌سازی</label>
      <select name="sort">
        <option value="new" <?= $sv_sort === 'new' ? 'selected' : '' ?>>جدیدترین</option>
        <option value="old" <?= $sv_sort === 'old' ? 'selected' : '' ?>>قدیمی‌ترین</option>
        <option value="exp" <?= $sv_sort === 'exp' ? 'selected' : '' ?>>نزدیک‌ترین انقضا</option>
        <option value="used" <?= $sv_sort === 'used' ? 'selected' : '' ?>>پرمصرف‌ترین</option>
        <option value="vol" <?= $sv_sort === 'vol' ? 'selected' : '' ?>>بیشترین حجم</option>
      </select>
    </div>
    <div class="field acts">
      <button class="btn btn-primary" type="submit">🔍 اعمال فیلتر</button>
      <a class="btn btn-ghost" href="index.php?p=services">پاک‌کردن</a>
    </div>
  </form>
</div>

<!-- ======================= فهرست سرویس‌ها ======================= -->
<div class="card mt3">
  <div class="card-head">
    <div>
      <div class="card-title">📃 فهرست سرویس‌ها</div>
      <div class="card-sub"><?= fa_num(count($rows)) ?> نتیجه <?= count($rows) >= 300 ? '(حداکثر ۳۰۰ مورد نمایش داده می‌شود)' : '' ?></div>
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="empty"><span class="ic">🔎</span> سرویسی با این فیلتر پیدا نشد.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table data-enhance="1" data-page-size="25">
      <thead>
        <tr>
          <th>#</th>
          <th>کاربر</th>
          <th>نام‌کاربری سرویس</th>
          <th>پنل</th>
          <th>مصرف</th>
          <th>انقضا</th>
          <th>وضعیت</th>
          <th data-nosort>عملیات</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
          $vol  = (float)$r['volume_gb'];
          $ugb  = bytes2gb((int)$r['used_bytes']);
          $pct  = $vol > 0 ? min(100, (int)round($ugb / max(0.001, $vol) * 100)) : 0;
          $ucls = $pct >= 90 ? 'r' : ($pct >= 70 ? 'a' : 'g');
          $exp  = trim((string)($r['expire_at'] ?? ''));
          $secs = $exp !== '' ? strtotime($exp) - time() : null;
          $dcls = $secs === null ? '' : ($secs <= 0 ? 'bad' : ($secs <= 259200 ? 'warn' : 'ok'));
          $nm   = trim((string)($r['first_name'] ?? '')) !== '' ? (string)$r['first_name'] : ('@' . (string)($r['username'] ?? '-'));
      ?>
        <tr>
          <td class="mono xs"><?= fa_num((int)$r['id']) ?></td>
          <td>
            <div class="u-cell">
              <span class="u-ava"><?= h(mb_substr(trim($nm) !== '' ? $nm : '?', 0, 1)) ?></span>
              <span class="u-txt">
                <b><?= h($nm) ?></b>
                <i class="mono ltr xs"><?= fa_num((string)$r['tg_id']) ?></i>
              </span>
            </div>
          </td>
          <td class="mono ltr xs"><?= h((string)$r['client_email']) ?></td>
          <td><span class="chip"><?= h((string)($r['panel_name'] ?: '—')) ?></span></td>
          <td>
            <?php if ($vol > 0): ?>
              <div class="usg">
                <span class="bar sm <?= $ucls ?>"><span style="width:<?= $pct ?>%"></span></span>
                <i class="mono xs"><?= fa_num($pct) ?>٪ · <?= fa_num(human_bytes((int)$r['used_bytes'])) ?> / <?= fa_num((string)round($vol, 1)) ?>G</i>
              </div>
            <?php else: ?>
              <span class="badge b-blue">♾ نامحدود</span>
              <i class="mono xs muted"><?= fa_num(human_bytes((int)$r['used_bytes'])) ?></i>
            <?php endif; ?>
          </td>
          <td class="nowrap">
            <?php if ($exp === ''): ?>
              <span class="muted">—</span>
            <?php else: ?>
              <span class="exp-pill <?= $dcls ?>"><?= h(remaining_human($exp)) ?></span>
              <i class="xs muted block"><?= h(to_jalali($exp)) ?></i>
            <?php endif; ?>
          </td>
          <td>
            <?= $sv_chip((string)$r['status']) ?>
            <?php if (!empty($r['is_test'])): ?><span class="badge b-purple xs">تست</span><?php endif; ?>
            <?php if (!empty($r['is_reseller'])): ?><span class="badge b-blue xs">نماینده</span><?php endif; ?>
          </td>
          <td>
            <div class="row">
              <a class="btn btn-sm btn-primary" href="<?= h($sv_link(['view' => (int)$r['id']])) ?>">جزئیات</a>
              <?php if (can('services.sync')): ?>
                <form method="post" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="sync">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="icon-btn" type="submit" title="همگام‌سازی">🔄</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if (can('services.edit')): ?>
<!-- ======================= ساخت سرویس دستی ======================= -->
<details class="acc mt3">
  <summary>➕ ساخت سرویس دستی روی پنل</summary>
  <div class="mt3">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="create">
      <div class="form-grid">
        <div class="field">
          <label>آیدی تلگرام یا آیدی کاربر *</label>
          <input type="text" name="tg_id" class="mono ltr" required placeholder="مثلاً 123456789">
          <span class="hint">کاربر باید قبلاً در ربات ثبت شده باشد.</span>
        </div>
        <div class="field">
          <label>پنل *</label>
          <select name="panel_id" required>
            <option value="">— انتخاب پنل —</option>
            <?php foreach ($panels as $pn): ?>
              <option value="<?= (int)$pn['id'] ?>"><?= h((string)$pn['name']) ?><?= empty($pn['active']) ? ' (غیرفعال)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>محصول (اختیاری)</label>
          <select name="product_id">
            <option value="">— مقدار دستی وارد می‌کنم —</option>
            <?php foreach ($products as $pd): ?>
              <option value="<?= (int)$pd['id'] ?>">
                <?= h((string)$pd['name']) ?> — <?= fa_num((string)$pd['volume_gb']) ?>G / <?= fa_num((int)$pd['days']) ?> روز
              </option>
            <?php endforeach; ?>
          </select>
          <span class="hint">با انتخاب محصول، حجم و مدت از روی آن خوانده می‌شود.</span>
        </div>
        <div class="field">
          <label>حجم (گیگابایت)</label>
          <input type="text" name="volume_gb" class="mono ltr" value="0" placeholder="0 = نامحدود">
        </div>
        <div class="field">
          <label>مدت (روز)</label>
          <input type="text" name="days" class="mono ltr" value="30">
        </div>
        <div class="field">
          <label>مدت اضافه (ساعت)</label>
          <input type="text" name="hours" class="mono ltr" value="0">
        </div>
        <div class="field">
          <label>محدودیت آی‌پی</label>
          <input type="text" name="ip_limit" class="mono ltr" value="0" placeholder="0 = بدون محدودیت">
        </div>
        <div class="field">
          <label>شناسهٔ اینباند (اختیاری)</label>
          <input type="text" name="inbound_id" class="mono ltr" value="0" placeholder="0 = انتخاب خودکار">
        </div>
        <div class="field">
          <label>نام‌کاربری دلخواه (اختیاری)</label>
          <input type="text" name="username" class="mono ltr" placeholder="خالی = طبق تنظیم پنل">
        </div>
      </div>
      <div class="row mt3">
        <label class="chk"><input type="checkbox" name="is_test" value="1"> 🧪 اکانت تست باشد</label>
        <label class="chk"><input type="checkbox" name="notify" value="1" checked> 📨 اطلاع‌رسانی به کاربر در تلگرام</label>
      </div>
      <button class="btn btn-primary mt3" type="submit">🚀 ساخت سرویس</button>
    </form>
  </div>
</details>
<?php endif; ?>

<?php endif; /* پایان حالت فهرست */ ?>

<?php if ($V):
    $V_nm = trim((string)($V_user['first_name'] ?? '')) !== '' ? (string)$V_user['first_name'] : ('@' . (string)($V_user['username'] ?? 'کاربر'));
    $dashU = round(213.63 * $V_upct / 100, 1);
    $dashT = round(213.63 * $V_tpct / 100, 1);
    $uCls  = $V_upct >= 90 ? 'r' : ($V_upct >= 70 ? 'a' : 'g');
    $tCls  = $V_tpct >= 90 ? 'r' : ($V_tpct >= 70 ? 'a' : 'g');
?>

<!-- ======================= هدر جزئیات سرویس ======================= -->
<div class="svc-hero fade-up">
  <div class="hero-top">
    <div class="hero-id">
      <span class="ava"><?= !empty($V['is_test']) ? '🧪' : '🛰' ?></span>
      <div class="grow">
        <div class="nm mono ltr"><?= h((string)$V['client_email']) ?></div>
        <div class="chips mt1">
          <?= $sv_chip((string)$V['status']) ?>
          <span class="chip">🖥 <?= h((string)($V_panel['name'] ?? 'بدون پنل')) ?></span>
          <span class="chip">🆔 <?= fa_num((int)$V['id']) ?></span>
          <?php if (!empty($V['is_test'])): ?><span class="chip">اکانت تست</span><?php endif; ?>
          <?php if (!empty($V['is_reseller'])): ?><span class="chip">نمایندگی</span><?php endif; ?>
          <?php if ((int)$V['renew_count'] > 0): ?><span class="chip">♻ <?= fa_num((int)$V['renew_count']) ?> بار تمدید</span><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="row">
      <a class="btn btn-ghost btn-sm" href="<?= h($sv_link(['view' => ''])) ?>">→ بازگشت به فهرست</a>
      <?php if (can('services.sync')): ?>
        <form method="post" class="inline">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="sync">
          <input type="hidden" name="id" value="<?= (int)$V['id'] ?>">
          <button class="btn btn-sm btn-primary" type="submit">🔄 همگام‌سازی</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="gauges">
    <div class="gauge-card">
      <div class="gauge">
        <svg viewBox="0 0 80 80" aria-hidden="true">
          <circle class="bg" cx="40" cy="40" r="34"></circle>
          <circle class="fg <?= $uCls ?>" cx="40" cy="40" r="34" stroke-dasharray="<?= $V['volume_gb'] > 0 ? $dashU : 0 ?> 999"></circle>
        </svg>
        <div class="mid">
          <b><?= (float)$V['volume_gb'] > 0 ? fa_num($V_upct) . '٪' : '♾' ?></b>
          <i>مصرف</i>
        </div>
      </div>
      <div class="gauge-info">
        <div class="t">حجم مصرف‌شده</div>
        <div class="v mono"><?= fa_num(human_bytes((int)$V['used_bytes'])) ?></div>
        <div class="s">
          <?php if ((float)$V['volume_gb'] > 0): ?>
            از <?= fa_num((string)round((float)$V['volume_gb'], 2)) ?> گیگ · باقی: <b class="mono"><?= fa_num((string)$V_leftGb) ?>G</b>
          <?php else: ?>
            حجم نامحدود
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="gauge-card">
      <div class="gauge">
        <svg viewBox="0 0 80 80" aria-hidden="true">
          <circle class="bg" cx="40" cy="40" r="34"></circle>
          <circle class="fg <?= $tCls ?>" cx="40" cy="40" r="34" stroke-dasharray="<?= !empty($V['expire_at']) ? $dashT : 0 ?> 999"></circle>
        </svg>
        <div class="mid">
          <b><?= !empty($V['expire_at']) ? fa_num($V_tpct) . '٪' : '♾' ?></b>
          <i>زمان</i>
        </div>
      </div>
      <div class="gauge-info">
        <div class="t">زمان باقی‌مانده</div>
        <div class="v"><?= h($V_left) ?></div>
        <div class="s">
          <?= !empty($V['expire_at']) ? 'انقضا: ' . h(to_jalali((string)$V['expire_at'], true)) : 'بدون تاریخ انقضا' ?>
        </div>
      </div>
    </div>

    <div class="gauge-card facts">
      <div class="f"><span>👤 کاربر</span><b><?= h($V_nm) ?></b></div>
      <div class="f"><span>💳 آخرین پرداخت</span><b><?= $V_paid > 0 ? h(money($V_paid)) : '—' ?></b></div>
      <div class="f"><span>🕒 آخرین همگام‌سازی</span><b><?= !empty($V['last_sync']) ? h(to_jalali((string)$V['last_sync'], true)) : '—' ?></b></div>
      <div class="f"><span>📅 ساخت</span><b><?= h(to_jalali((string)$V['created_at'])) ?></b></div>
    </div>
  </div>
</div>

<!-- ======================= تب‌ها ======================= -->
<div class="tabbar mt3">
  <button type="button" class="on" data-tab-group="svc" data-tab="info">📋 مشخصات</button>
  <button type="button" data-tab-group="svc" data-tab="links">🔗 لینک و کانفیگ <span class="n"><?= fa_num(count($V_cfgs)) ?></span></button>
  <button type="button" data-tab-group="svc" data-tab="hist">📚 سوابق <span class="n"><?= fa_num(count($V_orders)) ?></span></button>
  <button type="button" data-tab-group="svc" data-tab="manage">⚙ مدیریت</button>
</div>

<!-- ───── تب مشخصات ───── -->
<div class="tab-panel on" data-tab-panel-group="svc" data-tab-panel="info">
  <div class="grid g2">
    <div class="card compact">
      <div class="card-head"><div class="card-title">🧾 مشخصات سرویس</div></div>
      <div class="kv">
        <div class="k">نام‌کاربری</div>
        <div class="v mono ltr"><?= h((string)$V['client_email']) ?> <button class="icon-btn xs" type="button" data-copy="<?= h((string)$V['client_email']) ?>">📋</button></div>
        <div class="k">UUID</div>
        <div class="v mono ltr xs"><?= h((string)$V['client_uuid']) ?> <button class="icon-btn xs" type="button" data-copy="<?= h((string)$V['client_uuid']) ?>">📋</button></div>
        <div class="k">کد ساب</div>
        <div class="v mono ltr xs"><?= h((string)($V['sub_id'] ?: '—')) ?></div>
        <div class="k">پنل / اینباند</div>
        <div class="v"><?= h((string)($V_panel['name'] ?? '—')) ?> <span class="muted xs mono">#<?= fa_num((int)$V['inbound_id']) ?></span></div>
        <div class="k">حجم کل</div>
        <div class="v"><?= (float)$V['volume_gb'] > 0 ? fa_num((string)round((float)$V['volume_gb'], 2)) . ' گیگابایت' : 'نامحدود' ?></div>
        <div class="k">مدت اولیه</div>
        <div class="v"><?= (int)$V['days'] > 0 ? fa_num((int)$V['days']) . ' روز' : 'نامحدود' ?></div>
        <div class="k">تعداد تمدید</div>
        <div class="v"><?= fa_num((int)$V['renew_count']) ?></div>
        <?php if (trim((string)($V['group_key'] ?? '')) !== ''): ?>
          <div class="k">کلید گروه</div>
          <div class="v mono ltr xs"><?= h((string)$V['group_key']) ?></div>
          <div class="k">سهمیهٔ گروه</div>
          <div class="v"><?= (float)$V['group_quota_gb'] > 0 ? fa_num((string)round((float)$V['group_quota_gb'], 2)) . ' گیگ' : '—' ?></div>
        <?php endif; ?>
        <div class="k">یادآوری انقضا</div>
        <div class="v"><?= (int)$V['notified'] > 0 ? 'ارسال شده' : 'ارسال نشده' ?></div>
      </div>
    </div>

    <div class="card compact">
      <div class="card-head"><div class="card-title">👤 صاحب سرویس</div></div>
      <?php if (!$V_user): ?>
        <div class="empty"><span class="ic">❓</span> کاربر یافت نشد.</div>
      <?php else: ?>
      <div class="kv">
        <div class="k">نام</div>
        <div class="v"><?= h($V_nm) ?></div>
        <div class="k">آیدی تلگرام</div>
        <div class="v mono ltr"><?= fa_num((string)$V['tg_id']) ?> <button class="icon-btn xs" type="button" data-copy="<?= h((string)$V['tg_id']) ?>">📋</button></div>
        <div class="k">موجودی کیف پول</div>
        <div class="v"><?= h(money((float)($V_user['balance'] ?? 0))) ?></div>
        <div class="k">جمع پرداختی</div>
        <div class="v"><?= h(money((float)($V_user['total_paid'] ?? 0))) ?></div>
        <div class="k">وضعیت</div>
        <div class="v"><?= !empty($V_user['is_banned']) ? '<span class="badge b-red">مسدود</span>' : '<span class="badge b-green">عادی</span>' ?></div>
      </div>
      <div class="row mt3">
        <a class="btn btn-sm" href="index.php?p=users&amp;q=<?= h((string)$V['tg_id']) ?>">👤 پروفایل کاربر</a>
        <a class="btn btn-sm btn-ghost" href="index.php?p=services&amp;user=<?= (int)$V['user_id'] ?>">📦 همهٔ سرویس‌های این کاربر</a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ───── تب لینک‌ها ───── -->
<?php
$sv_mode = 'local';
try { $sv_mode = Svc::subMode(); } catch (Throwable $e) { $sv_mode = 'local'; }
$sv_modeLbl = ['local' => 'ساب اختصاصی ربات', 'panel' => 'ساب مستقیم پنل', 'both' => 'هر دو'][$sv_mode] ?? $sv_mode;
$sv_allCfg = implode("\n", array_map('strval', $V_cfgs));
?>
<div class="tab-panel" data-tab-panel-group="svc" data-tab-panel="links">
  <div class="card compact">
    <div class="card-head">
      <div>
        <div class="card-title">🔗 لینک‌های اشتراک</div>
        <div class="card-sub">حالت تحویل فعلی: <b><?= h($sv_modeLbl) ?></b></div>
      </div>
    </div>

    <?php if ($V_sub !== ''): ?>
      <div class="lnk">
        <span class="tag ok">تحویلی به کاربر</span>
        <code class="ltr"><?= h($V_sub) ?></code>
        <button class="icon-btn" type="button" data-copy="<?= h($V_sub) ?>" title="کپی">📋</button>
        <a class="icon-btn" href="<?= h($V_sub) ?>" target="_blank" rel="noopener" title="باز کردن">↗</a>
      </div>
    <?php endif; ?>

    <?php if ($V_local !== '' && $V_local !== $V_sub): ?>
      <div class="lnk">
        <span class="tag">ساب ربات</span>
        <code class="ltr"><?= h($V_local) ?></code>
        <button class="icon-btn" type="button" data-copy="<?= h($V_local) ?>">📋</button>
      </div>
    <?php endif; ?>

    <?php if ($V_pan !== '' && $V_pan !== $V_sub): ?>
      <div class="lnk">
        <span class="tag">ساب پنل</span>
        <code class="ltr"><?= h($V_pan) ?></code>
        <button class="icon-btn" type="button" data-copy="<?= h($V_pan) ?>">📋</button>
      </div>
    <?php endif; ?>

    <?php if ($V_sub === '' && $V_local === '' && $V_pan === ''): ?>
      <div class="empty"><span class="ic">🔗</span> برای این سرویس لینک اشتراکی ثبت نشده است.</div>
    <?php endif; ?>
  </div>

  <div class="card compact mt3">
    <div class="card-head">
      <div>
        <div class="card-title">📦 کانفیگ‌ها</div>
        <div class="card-sub">
          <?= $sv_cfg === 'live' ? 'خوانده‌شده از پنل (لحظه‌ای)' : 'ذخیره‌شده در پایگاه داده' ?>
          · <?= fa_num(count($V_cfgs)) ?> مورد
        </div>
      </div>
      <div class="row">
        <?php if ($sv_allCfg !== ''): ?>
          <button class="btn btn-sm" type="button" data-copy="<?= h($sv_allCfg) ?>">📋 کپی همه</button>
        <?php endif; ?>
        <a class="btn btn-sm btn-ghost" href="<?= h($sv_link(['view' => (int)$V['id'], 'cfg' => $sv_cfg === 'live' ? '' : 'live'])) ?>">
          <?= $sv_cfg === 'live' ? '💾 نمایش ذخیره‌شده' : '📡 دریافت زنده از پنل' ?>
        </a>
      </div>
    </div>

    <?php if (!$V_cfgs): ?>
      <div class="empty"><span class="ic">📡</span> کانفیگی یافت نشد. دکمهٔ «دریافت زنده از پنل» را بزنید.</div>
    <?php else: ?>
      <div class="cfg-list">
        <?php foreach ($V_cfgs as $i => $cfg): $cfg = (string)$cfg; ?>
          <div class="cfg">
            <span class="n"><?= fa_num($i + 1) ?></span>
            <code class="ltr"><?= h($cfg) ?></code>
            <button class="icon-btn" type="button" data-copy="<?= h($cfg) ?>" title="کپی">📋</button>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ───── تب سوابق ───── -->
<div class="tab-panel" data-tab-panel-group="svc" data-tab-panel="hist">
  <div class="card compact">
    <div class="card-head"><div class="card-title">🧾 سفارش‌های مرتبط</div></div>
    <?php if (!$V_orders): ?>
      <div class="empty"><span class="ic">🗂</span> سفارشی برای این سرویس ثبت نشده.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>#</th><th>محصول</th><th>نوع</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
          <tbody>
          <?php foreach ($V_orders as $o): ?>
            <tr>
              <td class="mono xs"><?= fa_num((int)$o['id']) ?></td>
              <td><?= h((string)($o['pname'] ?: '—')) ?></td>
              <td><span class="chip"><?= h((string)($o['type'] === 'renew' ? 'تمدید' : ($o['type'] === 'buy' ? 'خرید' : $o['type']))) ?></span></td>
              <td class="mono"><?= h(money((float)$o['final_amount'])) ?></td>
              <td><?= badge((string)$o['status']) ?></td>
              <td class="xs"><?= h(to_jalali((string)$o['created_at'], true)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="grid g2 mt3">
    <div class="card compact">
      <div class="card-head"><div class="card-title">👥 سرویس‌های دیگر همین کاربر</div></div>
      <?php if (!$V_sib): ?>
        <div class="empty"><span class="ic">👤</span> سرویس دیگری ندارد.</div>
      <?php else: foreach ($V_sib as $sb): ?>
        <a class="sib" href="index.php?p=services&amp;view=<?= (int)$sb['id'] ?>">
          <span class="mono ltr xs grow"><?= h((string)$sb['client_email']) ?></span>
          <?= $sv_chip((string)$sb['status']) ?>
          <i class="xs muted"><?= h(remaining_human($sb['expire_at'] ? (string)$sb['expire_at'] : null)) ?></i>
        </a>
      <?php endforeach; endif; ?>
    </div>

    <div class="card compact">
      <div class="card-head"><div class="card-title">🔗 سرویس‌های هم‌گروه</div></div>
      <?php if (!$V_group): ?>
        <div class="empty"><span class="ic">🧩</span> این سرویس گروهی نیست.</div>
      <?php else: foreach ($V_group as $gp): ?>
        <a class="sib" href="index.php?p=services&amp;view=<?= (int)$gp['id'] ?>">
          <span class="mono ltr xs grow"><?= h((string)$gp['client_email']) ?></span>
          <span class="chip"><?= h((string)($gp['panel_name'] ?: '—')) ?></span>
          <?= $sv_chip((string)$gp['status']) ?>
        </a>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<!-- ───── تب مدیریت ───── -->
<div class="tab-panel" data-tab-panel-group="svc" data-tab-panel="manage">
  <div class="grid g2">
    <div class="card compact">
      <div class="card-head">
        <div>
          <div class="card-title">⚡ اقدامات سریع</div>
          <div class="card-sub">تغییرات روی پنل و پایگاه داده اعمال می‌شود</div>
        </div>
      </div>
      <div class="act-list">
        <?php if (can('services.sync')): ?>
          <form method="post" class="act">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="sync">
            <input type="hidden" name="id" value="<?= (int)$V['id'] ?>">
            <span class="ic">🔄</span>
            <span class="tx"><b>همگام‌سازی با پنل</b><i>خواندن مصرف، انقضا و وضعیت واقعی</i></span>
            <button class="btn btn-sm" type="submit">اجرا</button>
          </form>
        <?php endif; ?>

        <?php if (can('services.edit')): $onNow = (string)$V['status'] === 'active'; ?>
          <form method="post" class="act">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$V['id'] ?>">
            <span class="ic"><?= $onNow ? '⛔' : '✅' ?></span>
            <span class="tx"><b><?= $onNow ? 'غیرفعال کردن سرویس' : 'فعال کردن سرویس' ?></b><i>روی تمام اینباندهای مجاز اعمال می‌شود</i></span>
            <button class="btn btn-sm <?= $onNow ? 'btn-red' : 'btn-green' ?>" type="submit"><?= $onNow ? 'غیرفعال' : 'فعال' ?></button>
          </form>

          <form method="post" class="act">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="notify">
            <input type="hidden" name="id" value="<?= (int)$V['id'] ?>">
            <span class="ic">📨</span>
            <span class="tx">
              <b>ارسال اطلاعات به کاربر</b>
              <i><label class="chk"><input type="checkbox" name="with_cfg" value="1" checked> همراه کانفیگ‌ها</label></i>
            </span>
            <button class="btn btn-sm" type="submit">ارسال</button>
          </form>
        <?php endif; ?>

        <?php if (can('services.delete')): ?>
          <form method="post" class="act danger" data-confirm="این سرویس از پنل حذف می‌شود. مطمئنید؟">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="del">
            <input type="hidden" name="id" value="<?= (int)$V['id'] ?>">
            <span class="ic">🗑</span>
            <span class="tx"><b>حذف سرویس</b><i>حذف اکانت از پنل و علامت‌گذاری حذف‌شده</i></span>
            <button class="btn btn-sm btn-red" type="submit">حذف</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if (can('services.edit')): ?>
    <div class="card compact">
      <div class="card-head">
        <div>
          <div class="card-title">♻ تمدید سرویس</div>
          <div class="card-sub">حجم و مدت از روی محصول انتخابی تمدید می‌شود</div>
        </div>
      </div>
      <?php if (!$V_plans): ?>
        <div class="empty"><span class="ic">📦</span> محصول فعالی برای این پنل تعریف نشده.</div>
      <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="renew">
        <input type="hidden" name="id" value="<?= (int)$V['id'] ?>">
        <div class="field">
          <label>محصول تمدید</label>
          <select name="product_id" required>
            <option value="">— انتخاب کنید —</option>
            <?php foreach ($V_plans as $pl): ?>
              <option value="<?= (int)$pl['id'] ?>">
                <?= h((string)$pl['name']) ?> — <?= fa_num((string)$pl['volume_gb']) ?>G / <?= fa_num((int)$pl['days']) ?> روز — <?= h(money((float)$pl['price'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <label class="chk mt2"><input type="checkbox" name="notify" value="1" checked> 📨 اطلاع‌رسانی تمدید به کاربر</label>
        <button class="btn btn-primary btn-block mt3" type="submit">♻ تمدید سرویس</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php endif; /* پایان حالت جزئیات */ ?>
