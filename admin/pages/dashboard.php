<?php
if (!can('dashboard.view')) { echo denyBox('این بخش برای شما فعال نیست.'); return; }

/**
 * داشبورد مدیریت — نمای کلی فروشگاه
 * نسخهٔ گسترش‌یافته: کارت‌های آماری با روند، نمودار تعاملی درآمد/سفارش،
 * تفکیک روش پرداخت، نقشهٔ حرارتی فروش، رتبه‌بندی محصولات و مشتریان،
 * سرویس‌های رو به انقضا، رسیدهای در انتظار و پایش سلامت سامانه.
 */

/* ======================= ۱) ابزارهای کمکی داشبورد ======================= */

/** اجرای امن کوئری تک‌مقداری (اگر جدول/ستون نبود، خطا نمی‌دهد) */
$d_val = static function (string $sql, array $par = [], $def = 0) {
    try { return DB::val($sql, $par, $def); } catch (Throwable $e) { return $def; }
};

/** اجرای امن کوئری چندسطری */
$d_all = static function (string $sql, array $par = []): array {
    try { return DB::all($sql, $par); } catch (Throwable $e) { return []; }
};

/** آیا مدیر به این صفحه دسترسی دارد؟ (برای پنهان کردن میان‌برهای غیرمجاز) */
$d_page = static function (string $k): bool {
    try { return Perm::canPage($GLOBALS['ADMIN'] ?? null, $k); } catch (Throwable $e) { return false; }
};

/* آیا این مدیر اجازهٔ دیدن ارقام مالی را دارد؟ */
$d_fin = can('dashboard.revenue');
$d_cur = h(currency());

/** نمایش مبلغ با توجه به سطح دسترسی */
$d_m = static function ($v, bool $unit = true) use ($d_fin, $d_cur): string {
    if (!$d_fin) return '<span class="muted">••••</span>';
    return money($v) . ($unit ? ' <span class="cur">' . $d_cur . '</span>' : '');
};

/** عدد فشرده برای کارت‌های آماری: ۱۲۵۰۰۰۰ ← ۱.۲ میلیون */
$d_short = static function ($v) use ($d_fin): string {
    if (!$d_fin) return '••••';
    $n = (float)$v; $a = abs($n);
    if ($a >= 1000000000) return fa_num(rtrim(rtrim(number_format($n / 1000000000, 1), '0'), '.')) . ' میلیارد';
    if ($a >= 1000000)    return fa_num(rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.')) . ' میلیون';
    if ($a >= 1000)       return fa_num(rtrim(rtrim(number_format($n / 1000, 1), '0'), '.')) . ' هزار';
    return fa_num(number_format($n));
};

/** درصد تغییر نسبت به دورهٔ قبل */
$d_diff = static function ($now, $prev): array {
    $now = (float)$now; $prev = (float)$prev;
    if ($prev <= 0) return $now > 0 ? [100, 'up'] : [0, 'eq'];
    $pc = (int)round((($now - $prev) / $prev) * 100);
    return [$pc, $pc > 0 ? 'up' : ($pc < 0 ? 'dn' : 'eq')];
};

/** برچسب رنگی درصد تغییر */
$d_badge = static function (array $d, string $note = 'نسبت به دورهٔ قبل'): string {
    [$pc, $dir] = $d;
    $ico = $dir === 'up' ? '▲' : ($dir === 'dn' ? '▼' : '•');
    return '<span class="delta ' . $dir . '">' . $ico . ' ' . fa_num(abs($pc)) . '٪</span>'
         . '<span class="muted">' . h($note) . '</span>';
};

/** نمودار کوچک داخل کارت‌های آماری */
$d_spark = static function (array $vals, string $color): string {
    $vals = array_values($vals);
    $n = count($vals);
    if ($n < 2) return '';
    $max = 0.0;
    foreach ($vals as $v) $max = max($max, (float)$v);
    if ($max <= 0) $max = 1.0;
    $w = 100.0; $hh = 30.0; $st = $w / ($n - 1);
    $pts = [];
    foreach ($vals as $i => $v) {
        $pts[] = round($i * $st, 2) . ',' . round($hh - 2 - ((float)$v / $max) * ($hh - 7), 2);
    }
    $line = implode(' ', $pts);
    $area = 'M 0,' . $hh . ' L ' . implode(' L ', $pts) . ' L ' . $w . ',' . $hh . ' Z';
    $uid  = 'sp' . substr(md5($line . $color), 0, 7);
    return '<svg class="spark" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true">'
        . '<defs><linearGradient id="' . $uid . '" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0%" stop-color="' . $color . '" stop-opacity=".38"></stop>'
        . '<stop offset="100%" stop-color="' . $color . '" stop-opacity="0"></stop>'
        . '</linearGradient></defs>'
        . '<path d="' . $area . '" fill="url(#' . $uid . ')"></path>'
        . '<polyline points="' . $line . '" fill="none" stroke="' . $color . '" stroke-width="2"'
        . ' stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"></polyline></svg>';
};

/** سری زمانی روزانه با پرکردن روزهای خالی */
$d_series = static function (string $sql, int $days) use ($d_all): array {
    $map = [];
    foreach ($d_all($sql) as $r) $map[(string)$r['d']] = (float)$r['c'];
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $day   = date('Y-m-d', strtotime('-' . $i . ' day'));
        $out[] = ['date' => $day, 'v' => (float)($map[$day] ?? 0)];
    }
    return $out;
};

/* ======================= ۲) دورهٔ زمانی گزارش ======================= */

$d_days = (int)en_num((string)($_GET['d'] ?? '14'));
if (!in_array($d_days, [7, 14, 30, 60], true)) $d_days = 14;
$d_prev = $d_days * 2;

/* ======================= ۳) آمار کلیدی ======================= */

/* کاربران */
$m_usersAll   = (int)$d_val('SELECT COUNT(*) FROM {p}users');
$m_usersToday = (int)$d_val('SELECT COUNT(*) FROM {p}users WHERE DATE(created_at) = CURDATE()');
$m_usersNow   = (int)$d_val("SELECT COUNT(*) FROM {p}users WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$d_days} DAY)");
$m_usersPrev  = (int)$d_val("SELECT COUNT(*) FROM {p}users WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$d_prev} DAY) AND created_at < DATE_SUB(NOW(), INTERVAL {$d_days} DAY)");
$m_usersBan   = (int)$d_val('SELECT COUNT(*) FROM {p}users WHERE is_banned = 1');
$m_online     = (int)$d_val('SELECT COUNT(*) FROM {p}users WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 24 HOUR)');

/* سرویس‌ها */
$m_svcActive  = (int)$d_val("SELECT COUNT(*) FROM {p}services WHERE status = 'active'");
$m_svcTest    = (int)$d_val('SELECT COUNT(*) FROM {p}services WHERE is_test = 1');
$m_svcSoon    = (int)$d_val("SELECT COUNT(*) FROM {p}services WHERE status = 'active' AND expire_at IS NOT NULL AND expire_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)");
$m_svcWeek    = (int)$d_val("SELECT COUNT(*) FROM {p}services WHERE status = 'active' AND expire_at IS NOT NULL AND expire_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)");
$m_svcExpired = (int)$d_val("SELECT COUNT(*) FROM {p}services WHERE status = 'expired'");
$m_svcNew     = (int)$d_val("SELECT COUNT(*) FROM {p}services WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$d_days} DAY)");
$m_svcPrev    = (int)$d_val("SELECT COUNT(*) FROM {p}services WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$d_prev} DAY) AND created_at < DATE_SUB(NOW(), INTERVAL {$d_days} DAY)");
$m_traffic    = (float)$d_val("SELECT COALESCE(SUM(used_bytes),0) FROM {p}services WHERE status = 'active'");
$m_renews     = (int)$d_val('SELECT COALESCE(SUM(renew_count),0) FROM {p}services');

/* فروش و سفارش */
$m_saleToday  = (float)$d_val("SELECT COALESCE(SUM(final_amount),0) FROM {p}orders WHERE status = 'paid' AND DATE(created_at) = CURDATE()");
$m_saleYest   = (float)$d_val("SELECT COALESCE(SUM(final_amount),0) FROM {p}orders WHERE status = 'paid' AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
$m_saleNow    = (float)$d_val("SELECT COALESCE(SUM(final_amount),0) FROM {p}orders WHERE status = 'paid' AND created_at >= DATE_SUB(NOW(), INTERVAL {$d_days} DAY)");
$m_salePrev   = (float)$d_val("SELECT COALESCE(SUM(final_amount),0) FROM {p}orders WHERE status = 'paid' AND created_at >= DATE_SUB(NOW(), INTERVAL {$d_prev} DAY) AND created_at < DATE_SUB(NOW(), INTERVAL {$d_days} DAY)");
$m_saleMonth  = (float)$d_val("SELECT COALESCE(SUM(final_amount),0) FROM {p}orders WHERE status = 'paid' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$m_ordNow     = (int)$d_val("SELECT COUNT(*) FROM {p}orders WHERE status = 'paid' AND created_at >= DATE_SUB(NOW(), INTERVAL {$d_days} DAY)");
$m_ordPrev    = (int)$d_val("SELECT COUNT(*) FROM {p}orders WHERE status = 'paid' AND created_at >= DATE_SUB(NOW(), INTERVAL {$d_prev} DAY) AND created_at < DATE_SUB(NOW(), INTERVAL {$d_days} DAY)");
$m_ordToday   = (int)$d_val("SELECT COUNT(*) FROM {p}orders WHERE status = 'paid' AND DATE(created_at) = CURDATE()");
$m_ordFail    = (int)$d_val("SELECT COUNT(*) FROM {p}orders WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL {$d_days} DAY)");
$m_ordPend    = (int)$d_val("SELECT COUNT(*) FROM {p}orders WHERE status = 'pending'");
$m_discount   = (float)$d_val("SELECT COALESCE(SUM(discount_amount),0) FROM {p}orders WHERE status = 'paid' AND created_at >= DATE_SUB(NOW(), INTERVAL {$d_days} DAY)");
$m_avg        = $m_ordNow > 0 ? $m_saleNow / $m_ordNow : 0.0;

/* کیف پول و تراکنش */
$m_wallet     = (float)$d_val('SELECT COALESCE(SUM(balance),0) FROM {p}users');
$m_payPending = (int)$d_val("SELECT COUNT(*) FROM {p}transactions WHERE status = 'pending'");
$m_paySum     = (float)$d_val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE status = 'pending'");
$m_depNow     = (float)$d_val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE status = 'approved' AND type = 'deposit' AND created_at >= DATE_SUB(NOW(), INTERVAL {$d_days} DAY)");
$m_depToday   = (float)$d_val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE status = 'approved' AND type = 'deposit' AND DATE(created_at) = CURDATE()");

/* پشتیبانی و محصول */
$m_tkOpen     = (int)$d_val("SELECT COUNT(*) FROM {p}tickets WHERE status = 'open'");
$m_tkToday    = (int)$d_val('SELECT COUNT(*) FROM {p}tickets WHERE DATE(created_at) = CURDATE()');
$m_prodActive = (int)$d_val('SELECT COUNT(*) FROM {p}products WHERE active = 1');
$m_prodOut    = (int)$d_val('SELECT COUNT(*) FROM {p}products WHERE active = 1 AND stock = 0');
$m_codes      = (int)$d_val('SELECT COUNT(*) FROM {p}discount_codes WHERE active = 1');
$m_stockFree  = (int)$d_val("SELECT COUNT(*) FROM {p}stock_items WHERE status = 'free'");
$m_stockSold  = (int)$d_val("SELECT COUNT(*) FROM {p}stock_items WHERE status = 'sold'");
$m_resellers  = (int)$d_val('SELECT COUNT(*) FROM {p}users WHERE reseller_level > 0');
$m_resReq     = (int)$d_val('SELECT COUNT(*) FROM {p}users WHERE reseller_req = 1');
$m_cardsWait  = (int)$d_val("SELECT COUNT(*) FROM {p}user_cards WHERE status = 'pending'");

/* سفارش‌های گیرکرده */
try { $m_stuck = (int)Orders::stuckCount(); } catch (Throwable $e) { $m_stuck = 0; }

/* درصد تغییرها */
$dl_sale  = $d_diff($m_saleNow, $m_salePrev);
$dl_ord   = $d_diff($m_ordNow, $m_ordPrev);
$dl_user  = $d_diff($m_usersNow, $m_usersPrev);
$dl_svc   = $d_diff($m_svcNew, $m_svcPrev);
$dl_today = $d_diff($m_saleToday, $m_saleYest);

/* ======================= ۴) سری‌های زمانی نمودار ======================= */

try { $d_sales = Orders::dailySales($d_days); } catch (Throwable $e) { $d_sales = []; }
if (!$d_sales) {
    $d_sales = [];
    for ($i = $d_days - 1; $i >= 0; $i--) {
        $d_sales[] = ['date' => date('Y-m-d', strtotime('-' . $i . ' day')), 'count' => 0, 'sum' => 0];
    }
}

$d_newUsers = $d_series(
    "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM {p}users
      WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL {$d_days} DAY)
      GROUP BY DATE(created_at)", $d_days);

/* مقیاس نمودار اصلی */
$c_max = 1.0; $c_cmax = 1.0; $c_sum = 0.0; $c_cnt = 0;
foreach ($d_sales as $r) {
    $c_max  = max($c_max, (float)$r['sum']);
    $c_cmax = max($c_cmax, (float)$r['count']);
    $c_sum += (float)$r['sum'];
    $c_cnt += (int)$r['count'];
}
$c_avg = count($d_sales) > 0 ? $c_sum / count($d_sales) : 0.0;

$C_W = 720.0; $C_H = 250.0; $C_PT = 18.0; $C_PB = 22.0;
$c_n  = max(1, count($d_sales));
$c_bw = $C_W / $c_n;
$c_ph = $C_H - $C_PT - $C_PB;
$c_pts = [];
foreach (array_values($d_sales) as $i => $r) {
    $v  = (float)$r['sum'];
    $cc = (int)$r['count'];
    $bh = $c_cmax > 0 ? ($cc / $c_cmax) * ($c_ph * .55) : 0.0;
    $c_pts[] = [
        'x'  => round($i * $c_bw + $c_bw / 2, 1),
        'y'  => round($C_PT + (1 - ($v / $c_max)) * $c_ph, 1),
        'bx' => round($i * $c_bw + $c_bw * .28, 1),
        'bw' => round($c_bw * .44, 1),
        'by' => round($C_PT + $c_ph - $bh, 1),
        'bh' => round($bh, 1),
        'v'  => $v,
        'c'  => $cc,
        'd'  => (string)$r['date'],
        'u'  => (float)($d_newUsers[$i]['v'] ?? 0),
    ];
}
$c_line = '';
foreach ($c_pts as $p) $c_line .= $p['x'] . ',' . $p['y'] . ' ';
$c_base = round($C_PT + $c_ph, 1);
$c_area = 'M ' . $c_pts[0]['x'] . ' ' . $c_base;
foreach ($c_pts as $p) $c_area .= ' L ' . $p['x'] . ' ' . $p['y'];
$c_area .= ' L ' . $c_pts[count($c_pts) - 1]['x'] . ' ' . $c_base . ' Z';
$c_avgY = round($C_PT + (1 - ($c_avg / $c_max)) * $c_ph, 1);

/* ======================= ۵) تفکیک روش پرداخت ======================= */

$d_methods = $d_all(
    "SELECT method, COUNT(*) AS c, COALESCE(SUM(amount),0) AS s
       FROM {p}transactions
      WHERE status = 'approved' AND created_at >= DATE_SUB(NOW(), INTERVAL {$d_days} DAY)
      GROUP BY method ORDER BY s DESC");

$MET_LBL = [
    'card'    => 'کارت به کارت',
    'crypto'  => 'ارز دیجیتال',
    'nowpay'  => 'ناوپیمنت',
    'wallet'  => 'کیف پول',
    'gateway' => 'درگاه آنلاین',
    'admin'   => 'شارژ دستی مدیر',
    'gift'    => 'کد هدیه',
    'refund'  => 'بازگشت وجه',
];
$MET_CLR = ['#5B8CFF', '#2FD48F', '#FFA92E', '#26D3E8', '#8B5CF6', '#FF6B6B', '#F472B6', '#94A3B8'];

$pie_total = 0.0;
foreach ($d_methods as $mm) $pie_total += $d_fin ? (float)$mm['s'] : (float)$mm['c'];
$pie_segs = [];
$pie_off  = 0.0;
$PIE_C    = 2 * M_PI * 54;
foreach (array_values($d_methods) as $i => $mm) {
    $val = $d_fin ? (float)$mm['s'] : (float)$mm['c'];
    if ($pie_total <= 0 || $val <= 0) continue;
    $frac = $val / $pie_total;
    $pie_segs[] = [
        'lbl'  => $MET_LBL[(string)$mm['method']] ?? (string)$mm['method'],
        'clr'  => $MET_CLR[$i % count($MET_CLR)],
        'len'  => round($PIE_C * $frac, 2),
        'gap'  => round($PIE_C * (1 - $frac), 2),
        'off'  => round(-$PIE_C * $pie_off, 2),
        'pc'   => (int)round($frac * 100),
        'val'  => $val,
        'cnt'  => (int)$mm['c'],
    ];
    $pie_off += $frac;
}

/* ======================= ۶) نقشهٔ حرارتی ۲۸ روز ======================= */

$d_heat = $d_series(
    "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM {p}orders
      WHERE status = 'paid' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
      GROUP BY DATE(created_at)", 28);
$heat_max = 0.0;
foreach ($d_heat as $r) $heat_max = max($heat_max, (float)$r['v']);

/* ======================= ۷) جدول‌ها و فهرست‌ها ======================= */

try { $d_orders = Orders::recent(10); } catch (Throwable $e) { $d_orders = []; }

$d_tickets = $d_all(
    "SELECT t.id, t.subject, t.status, t.updated_at, u.first_name, u.tg_id
       FROM {p}tickets t LEFT JOIN {p}users u ON u.id = t.user_id
      WHERE t.status = 'open' ORDER BY t.updated_at DESC LIMIT 5");

$d_top = $d_all(
    "SELECT p.name AS name, COUNT(o.id) AS c, COALESCE(SUM(o.final_amount),0) AS s
       FROM {p}orders o JOIN {p}products p ON p.id = o.product_id
      WHERE o.status = 'paid' AND o.created_at >= DATE_SUB(NOW(), INTERVAL {$d_days} DAY)
      GROUP BY p.id, p.name ORDER BY c DESC, s DESC LIMIT 5");

$d_buyers = $d_all(
    "SELECT u.tg_id, u.first_name, u.username, COUNT(o.id) AS c, COALESCE(SUM(o.final_amount),0) AS s
       FROM {p}orders o JOIN {p}users u ON u.id = o.user_id
      WHERE o.status = 'paid' AND o.created_at >= DATE_SUB(NOW(), INTERVAL {$d_days} DAY)
      GROUP BY u.id ORDER BY s DESC LIMIT 5");

$d_expiring = $d_all(
    "SELECT s.id, s.client_email, s.expire_at, s.used_bytes, s.volume_gb, s.tg_id, u.first_name
       FROM {p}services s LEFT JOIN {p}users u ON u.id = s.user_id
      WHERE s.status = 'active' AND s.expire_at IS NOT NULL
        AND s.expire_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
      ORDER BY s.expire_at ASC LIMIT 6");

$d_receipts = $d_all(
    "SELECT t.id, t.amount, t.method, t.created_at, t.tg_id, u.first_name
       FROM {p}transactions t LEFT JOIN {p}users u ON u.id = t.user_id
      WHERE t.status = 'pending' ORDER BY t.id DESC LIMIT 5");

$d_panels = $d_all('SELECT * FROM {p}panels ORDER BY sort ASC, id ASC');

/* ======================= ۸) سلامت سامانه ======================= */

$beatFile = APP_ROOT . '/storage/last-cron.txt';
$beatAge  = is_file($beatFile) ? (time() - (int)@filemtime($beatFile)) : -1;
$beatOk   = $beatAge >= 0 && $beatAge < 1800;
$panelBad = 0;
foreach ($d_panels as $pn) {
    if ((int)$pn['active'] === 0 || !empty($pn['last_error'])) $panelBad++;
}

/* هدف فروش ماهانه (اختیاری – کلید تنظیمات: dash_month_goal) */
$d_goal = 0.0;
try { $d_goal = (float)en_num((string)DB::setting('dash_month_goal', '0')); } catch (Throwable $e) { $d_goal = 0.0; }
$d_goalPc = $d_goal > 0 ? min(100, (int)round(($m_saleMonth / $d_goal) * 100)) : 0;

/* سلام و تاریخ */
$hh_now  = (int)date('H');
$d_hello = $hh_now < 5 ? 'شب بخیر' : ($hh_now < 12 ? 'صبح بخیر' : ($hh_now < 17 ? 'ظهر بخیر' : ($hh_now < 20 ? 'عصر بخیر' : 'شب بخیر')));
$WD      = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه', 'شنبه'];
$d_wd    = $WD[(int)date('w')];
$d_admin = (string)(($GLOBALS['ADMIN']['name'] ?? '') ?: ($GLOBALS['ADMIN']['username'] ?? 'مدیر'));
$d_qs    = static fn(int $n): string => 'index.php?p=dashboard&amp;d=' . $n;
?>

<!-- ============================ سربرگ ============================ -->
<section class="dash-hero fade-up">
  <div class="hero-top">
    <div class="hero-ava"><?= isSuper() ? '👑' : '🛡️' ?></div>
    <div class="grow">
      <div class="hi"><?= h($d_hello) ?><?= h($d_admin) !== '' ? '، ' . h($d_admin) : '' ?> 👋</div>
      <div class="sub"><?= h($d_wd) ?> <?= h(to_jalali(null)) ?> • ساعت <?= fa_num(date('H:i')) ?> • گزارش <?= fa_num($d_days) ?> روز اخیر</div>
    </div>
    <div class="segment hero-seg" role="tablist" aria-label="بازهٔ زمانی">
      <a class="<?= $d_days === 7 ? 'on' : '' ?>" href="<?= $d_qs(7) ?>">۷ روز</a>
      <a class="<?= $d_days === 14 ? 'on' : '' ?>" href="<?= $d_qs(14) ?>">۱۴ روز</a>
      <a class="<?= $d_days === 30 ? 'on' : '' ?>" href="<?= $d_qs(30) ?>">۳۰ روز</a>
      <a class="<?= $d_days === 60 ? 'on' : '' ?>" href="<?= $d_qs(60) ?>">۶۰ روز</a>
    </div>
  </div>

  <div class="hero-chips">
    <span class="chip">💰 فروش امروز <b><?= $d_m($m_saleToday) ?></b></span>
    <span class="chip">🧾 سفارش امروز <b><?= fa_num($m_ordToday) ?></b></span>
    <span class="chip">👥 کاربر تازه <b><?= fa_num($m_usersToday) ?></b></span>
    <span class="chip">🟢 فعال در ۲۴ ساعت <b><?= fa_num($m_online) ?></b></span>
    <span class="chip <?= $beatOk ? '' : 'c-warn' ?>">⏱ کران‌جاب <b><?= $beatAge < 0 ? 'اجرا نشده' : fa_num((int)round($beatAge / 60)) . ' دقیقه پیش' ?></b></span>
  </div>
</section>

<!-- ============================ هشدارها ============================ -->
<?php if ($m_stuck > 0 || !$beatOk || $m_payPending > 0 || $panelBad > 0 || $m_cardsWait > 0 || $m_resReq > 0): ?>
<div class="alert-row mt4">
  <?php if ($m_stuck > 0): ?>
    <a class="alertx a-red" href="index.php?p=health"><span class="ic">🧯</span>
      <span><b><?= fa_num($m_stuck) ?></b> سفارش نیمه‌کاره منتظر بازیابی<i>بررسی و بازگرداندن وجه ←</i></span></a>
  <?php endif; ?>
  <?php if (!$beatOk): ?>
    <a class="alertx a-orange" href="index.php?p=health"><span class="ic">⏰</span>
      <span>کران‌جاب <b><?= $beatAge < 0 ? 'تا کنون اجرا نشده' : fa_num((int)round($beatAge / 60)) . ' دقیقه است خاموش است' ?></b><i>تمدید، هشدار و هم‌رسانی متوقف می‌ماند ←</i></span></a>
  <?php endif; ?>
  <?php if ($m_payPending > 0 && $d_page('payments')): ?>
    <a class="alertx a-blue" href="index.php?p=payments"><span class="ic">💳</span>
      <span><b><?= fa_num($m_payPending) ?></b> رسید در انتظار تایید<i>جمعاً <?= $d_m($m_paySum) ?> ←</i></span></a>
  <?php endif; ?>
  <?php if ($m_cardsWait > 0 && $d_page('cards')): ?>
    <a class="alertx a-purple" href="index.php?p=cards"><span class="ic">🪪</span>
      <span><b><?= fa_num($m_cardsWait) ?></b> کارت بانکی منتظر احراز<i>بررسی کنید ←</i></span></a>
  <?php endif; ?>
  <?php if ($m_resReq > 0 && $d_page('resellers')): ?>
    <a class="alertx a-green" href="index.php?p=resellers"><span class="ic">🏷</span>
      <span><b><?= fa_num($m_resReq) ?></b> درخواست نمایندگی تازه<i>مشاهده درخواست‌ها ←</i></span></a>
  <?php endif; ?>
  <?php if ($panelBad > 0 && $d_page('panels')): ?>
    <a class="alertx a-orange" href="index.php?p=panels"><span class="ic">🖧</span>
      <span><b><?= fa_num($panelBad) ?></b> پنل غیرفعال یا دارای خطا<i>بررسی اتصال ←</i></span></a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ============================ کارت‌های کلیدی ============================ -->
<div class="kpi-grid mt4">
  <div class="kpi k-blue fade-up" style="--i:1">
    <div class="kh"><span class="ic">💰</span><span class="lbl">فروش <?= fa_num($d_days) ?> روز اخیر</span></div>
    <div class="val"><?= $d_m($m_saleNow) ?></div>
    <div class="foot"><?= $d_badge($dl_sale) ?></div>
    <?= $d_spark(array_map(static fn($r) => (float)$r['sum'], $d_sales), '#5B8CFF') ?>
  </div>

  <div class="kpi k-green fade-up" style="--i:2">
    <div class="kh"><span class="ic">🧾</span><span class="lbl">سفارش‌های پرداخت‌شده</span></div>
    <div class="val"><?= fa_num($m_ordNow) ?><span class="cur">سفارش</span></div>
    <div class="foot"><?= $d_badge($dl_ord) ?></div>
    <?= $d_spark(array_map(static fn($r) => (float)$r['count'], $d_sales), '#2FD48F') ?>
  </div>

  <div class="kpi k-purple fade-up" style="--i:3">
    <div class="kh"><span class="ic">👥</span><span class="lbl">کاربران تازه</span></div>
    <div class="val"><?= fa_num($m_usersNow) ?><span class="cur">از <?= fa_num($m_usersAll) ?> کل</span></div>
    <div class="foot"><?= $d_badge($dl_user) ?></div>
    <?= $d_spark(array_map(static fn($r) => (float)$r['v'], $d_newUsers), '#8B5CF6') ?>
  </div>

  <div class="kpi k-orange fade-up" style="--i:4">
    <div class="kh"><span class="ic">🔑</span><span class="lbl">سرویس‌های ساخته‌شده</span></div>
    <div class="val"><?= fa_num($m_svcNew) ?><span class="cur"><?= fa_num($m_svcActive) ?> فعال</span></div>
    <div class="foot"><?= $d_badge($dl_svc) ?></div>
    <?= $d_spark(array_map(static fn($r) => (float)$r['count'], $d_sales), '#FFA92E') ?>
  </div>
</div>

<!-- ============================ نوار آمار سریع ============================ -->
<div class="mini-stats mt3">
  <div class="mini"><div class="ic">📈</div><div class="grow"><div class="t">میانگین هر سفارش</div><div class="v"><?= $d_short($m_avg) ?></div></div></div>
  <div class="mini ok"><div class="ic">👛</div><div class="grow"><div class="t">موجودی کیف پول کاربران</div><div class="v"><?= $d_short($m_wallet) ?></div></div></div>
  <div class="mini ok"><div class="ic">🏦</div><div class="grow"><div class="t">شارژ تاییدشده (دوره)</div><div class="v"><?= $d_short($m_depNow) ?></div></div></div>
  <div class="mini warn"><div class="ic">🎟</div><div class="grow"><div class="t">تخفیف اعمال‌شده</div><div class="v"><?= $d_short($m_discount) ?></div></div></div>
  <div class="mini"><div class="ic">📡</div><div class="grow"><div class="t">مصرف کل سرویس‌ها</div><div class="v"><?= fa_num(human_bytes($m_traffic)) ?></div></div></div>
  <div class="mini warn"><div class="ic">⏳</div><div class="grow"><div class="t">انقضای ۷ روز آینده</div><div class="v"><?= fa_num($m_svcWeek) ?></div></div></div>
  <div class="mini bad"><div class="ic">❌</div><div class="grow"><div class="t">سفارش ناموفق (دوره)</div><div class="v"><?= fa_num($m_ordFail) ?></div></div></div>
  <div class="mini"><div class="ic">🔁</div><div class="grow"><div class="t">تمدیدهای انجام‌شده</div><div class="v"><?= fa_num($m_renews) ?></div></div></div>
</div>

<!-- ============================ نمودار فروش ============================ -->
<div class="card mt4 fade-up">
  <div class="card-head">
    <div>
      <div class="card-title">📈 روند فروش <?= fa_num($d_days) ?> روز اخیر</div>
      <div class="card-sub">جمع <?= $d_m($c_sum) ?> از <?= fa_num($c_cnt) ?> سفارش پرداخت‌شده • میانگین روزانه <?= $d_short($c_avg) ?></div>
    </div>
    <div class="row" style="gap:6px">
      <?php if ($d_page('payments')): ?><a class="btn btn-sm" href="index.php?p=payments">گزارش کامل</a><?php endif; ?>
    </div>
  </div>

  <div class="chart-legend">
    <span><i style="background:#5B8CFF"></i> درآمد روزانه</span>
    <span><i style="background:rgba(47,212,143,.55)"></i> تعداد سفارش</span>
    <span><i class="dash"></i> میانگین دوره</span>
  </div>

  <div class="d-chart" id="dChart" dir="ltr">
    <div class="d-tip" id="dTip" aria-hidden="true"></div>
    <svg viewBox="0 0 720 250" preserveAspectRatio="none" role="img" aria-label="نمودار فروش">
      <defs>
        <linearGradient id="dArea" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#5B8CFF" stop-opacity=".42"></stop>
          <stop offset="100%" stop-color="#5B8CFF" stop-opacity=".02"></stop>
        </linearGradient>
        <linearGradient id="dBar" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#2FD48F" stop-opacity=".55"></stop>
          <stop offset="100%" stop-color="#2FD48F" stop-opacity=".10"></stop>
        </linearGradient>
      </defs>

      <?php for ($g = 0; $g <= 4; $g++): $gy = round($C_PT + $c_ph * $g / 4, 1); ?>
        <line x1="0" y1="<?= $gy ?>" x2="720" y2="<?= $gy ?>" stroke="currentColor" stroke-opacity=".10" stroke-width="1" stroke-dasharray="4 8"></line>
      <?php endfor; ?>

      <?php foreach ($c_pts as $p): if ($p['bh'] <= 0) continue; ?>
        <rect x="<?= $p['bx'] ?>" y="<?= $p['by'] ?>" width="<?= $p['bw'] ?>" height="<?= $p['bh'] ?>" rx="3" fill="url(#dBar)"></rect>
      <?php endforeach; ?>

      <path d="<?= h($c_area) ?>" fill="url(#dArea)"></path>
      <line x1="0" y1="<?= $c_avgY ?>" x2="720" y2="<?= $c_avgY ?>" stroke="#FFA92E" stroke-opacity=".65" stroke-width="1.4" stroke-dasharray="6 6"></line>
      <polyline points="<?= h(trim($c_line)) ?>" fill="none" stroke="#5B8CFF" stroke-width="2.6" stroke-linejoin="round" stroke-linecap="round"></polyline>

      <?php foreach ($c_pts as $i => $p): ?>
        <circle cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>" r="3.4" fill="#5B8CFF" stroke="var(--surface)" stroke-width="1.6"></circle>
        <rect class="hv" x="<?= round($i * $c_bw, 1) ?>" y="0" width="<?= round($c_bw, 1) ?>" height="250" fill="transparent"
              data-x="<?= round($p['x'] / 720, 4) ?>" data-y="<?= round($p['y'] / 250, 4) ?>"
              data-t="<?= h(to_jalali($p['d'])) ?>"
              data-v="<?= h($d_fin ? money($p['v']) . ' ' . currency() : '••••') ?>"
              data-c="<?= h(fa_num($p['c'])) ?>" data-u="<?= h(fa_num((int)$p['u'])) ?>"></rect>
      <?php endforeach; ?>
    </svg>

    <div class="x-axis">
      <?php $step = max(1, (int)ceil($c_n / 10)); foreach ($c_pts as $i => $p): ?>
        <span><?= ($i % $step === 0) ? h(mb_substr(to_jalali($p['d']), 5)) : '' ?></span>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($d_goal > 0 && $d_fin): ?>
  <div class="goal mt4">
    <div class="row-between" style="font-size:12.5px">
      <span class="muted">🎯 پیشرفت هدف فروش ۳۰ روزه</span>
      <span><b><?= money($m_saleMonth) ?></b> <span class="muted">/ <?= money($d_goal) ?> <?= $d_cur ?></span></span>
    </div>
    <div class="bar big"><span style="width:<?= $d_goalPc ?>%"></span></div>
    <div class="muted" style="font-size:11.5px;margin-top:4px"><?= fa_num($d_goalPc) ?>٪ از هدف ماهانه محقق شده است.</div>
  </div>
  <?php endif; ?>
</div>

<!-- ============================ تفکیک درآمد + دسترسی سریع ============================ -->
<div class="grid g2 mt4">
  <div class="card fade-up">
    <div class="card-head">
      <div><div class="card-title">🧩 تفکیک روش پرداخت</div>
      <div class="card-sub">تراکنش‌های تاییدشدهٔ <?= fa_num($d_days) ?> روز اخیر</div></div>
      <?php if ($d_page('gateways')): ?><a class="btn btn-sm" href="index.php?p=gateways">درگاه‌ها</a><?php endif; ?>
    </div>

    <?php if (!$pie_segs): ?>
      <div class="empty"><div class="ic">📊</div>در این بازه تراکنش تاییدشده‌ای ثبت نشده است.</div>
    <?php else: ?>
      <div class="donut-wrap">
        <div class="donut">
          <svg viewBox="0 0 140 140" aria-hidden="true">
            <circle cx="70" cy="70" r="54" fill="none" stroke="var(--surface-3)" stroke-width="16"></circle>
            <?php foreach ($pie_segs as $sg): ?>
              <circle cx="70" cy="70" r="54" fill="none" stroke="<?= h($sg['clr']) ?>" stroke-width="16"
                      stroke-dasharray="<?= $sg['len'] ?> <?= $sg['gap'] ?>" stroke-dashoffset="<?= $sg['off'] ?>"
                      stroke-linecap="butt" transform="rotate(-90 70 70)"></circle>
            <?php endforeach; ?>
          </svg>
          <div class="mid">
            <div>
              <div class="muted" style="font-size:11px">جمع دوره</div>
              <div style="font-weight:800;font-size:15px"><?= $d_fin ? $d_short($pie_total) : fa_num((int)$pie_total) ?></div>
            </div>
          </div>
        </div>
        <div class="legend-list">
          <?php foreach ($pie_segs as $sg): ?>
            <div class="lg">
              <i style="background:<?= h($sg['clr']) ?>"></i>
              <span class="grow"><?= h($sg['lbl']) ?></span>
              <span class="muted"><?= fa_num($sg['cnt']) ?>×</span>
              <b><?= fa_num($sg['pc']) ?>٪</b>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="card fade-up">
    <div class="card-head">
      <div><div class="card-title">⚡ دسترسی سریع</div>
      <div class="card-sub">کارهای پرکاربرد مدیر</div></div>
    </div>
    <div class="qa-grid">
      <?php if ($d_page('panels')): ?><a class="qa" href="index.php?p=panels"><span class="ic">🖧</span><span class="t">افزودن پنل</span><span class="n"><?= fa_num(count($d_panels)) ?> پنل فعلی</span></a><?php endif; ?>
      <?php if ($d_page('products')): ?><a class="qa" href="index.php?p=products"><span class="ic">📦</span><span class="t">مدیریت محصول</span><span class="n"><?= fa_num($m_prodActive) ?> محصول فعال</span></a><?php endif; ?>
      <?php if ($d_page('payments')): ?><a class="qa<?= $m_payPending ? ' hot' : '' ?>" href="index.php?p=payments"><span class="ic">💳</span><span class="t">بررسی رسیدها</span><span class="n"><?= fa_num($m_payPending) ?> در انتظار</span><?php if ($m_payPending): ?><span class="qn"><?= fa_num($m_payPending) ?></span><?php endif; ?></a><?php endif; ?>
      <?php if ($d_page('tickets')): ?><a class="qa<?= $m_tkOpen ? ' hot' : '' ?>" href="index.php?p=tickets"><span class="ic">🆘</span><span class="t">پاسخ تیکت</span><span class="n"><?= fa_num($m_tkOpen) ?> تیکت باز</span><?php if ($m_tkOpen): ?><span class="qn"><?= fa_num($m_tkOpen) ?></span><?php endif; ?></a><?php endif; ?>
      <?php if ($d_page('codes')): ?><a class="qa" href="index.php?p=codes"><span class="ic">🎟</span><span class="t">کد تخفیف</span><span class="n"><?= fa_num($m_codes) ?> کد فعال</span></a><?php endif; ?>
      <?php if ($d_page('stock')): ?><a class="qa" href="index.php?p=stock"><span class="ic">🏪</span><span class="t">انبار ملی</span><span class="n"><?= fa_num($m_stockFree) ?> قلم آزاد</span></a><?php endif; ?>
      <?php if ($d_page('users')): ?><a class="qa" href="index.php?p=users"><span class="ic">👥</span><span class="t">کاربران</span><span class="n"><?= fa_num($m_online) ?> فعال امروز</span></a><?php endif; ?>
      <?php if ($d_page('backup')): ?><a class="qa" href="index.php?p=backup"><span class="ic">💾</span><span class="t">پشتیبان‌گیری</span><span class="n">تهیهٔ بکاپ فوری</span></a><?php endif; ?>
      <?php if ($d_page('health')): ?><a class="qa" href="index.php?p=health"><span class="ic">🩺</span><span class="t">سلامت سیستم</span><span class="n"><?= $beatOk ? 'وضعیت طبیعی' : 'نیازمند بررسی' ?></span></a><?php endif; ?>
      <?php if ($d_page('settings')): ?><a class="qa" href="index.php?p=settings"><span class="ic">⚙️</span><span class="t">تنظیمات</span><span class="n">ربات و پرداخت</span></a><?php endif; ?>
    </div>

    <div class="kv mt4"><span class="k">فروش ۳۰ روز گذشته</span><span><?= $d_m($m_saleMonth) ?></span></div>
    <div class="kv"><span class="k">اکانت‌های تست ساخته‌شده</span><span><?= fa_num($m_svcTest) ?></span></div>
    <div class="kv"><span class="k">نمایندگان فعال</span><span><?= fa_num($m_resellers) ?></span></div>
    <div class="kv"><span class="k">کاربران مسدود</span><span><?= fa_num($m_usersBan) ?></span></div>
  </div>
</div>

<!-- ============================ پرفروش‌ها و مشتریان برتر ============================ -->
<div class="grid g2 mt4">
  <div class="card fade-up">
    <div class="card-head">
      <div><div class="card-title">🏆 محصولات پرفروش</div>
      <div class="card-sub">بر اساس تعداد فروش در <?= fa_num($d_days) ?> روز اخیر</div></div>
      <?php if ($d_page('products')): ?><a class="btn btn-sm" href="index.php?p=products">محصولات</a><?php endif; ?>
    </div>
    <?php if (!$d_top): ?>
      <div class="empty"><div class="ic">📦</div>هنوز فروشی در این بازه ثبت نشده است.</div>
    <?php else: $tmax = max(1, (int)$d_top[0]['c']); ?>
      <div class="rank">
        <?php foreach ($d_top as $i => $r): ?>
          <div class="rank-row">
            <span class="rank-n n<?= $i + 1 ?>"><?= fa_num($i + 1) ?></span>
            <div class="grow">
              <div class="row-between">
                <span class="nm"><?= h((string)$r['name']) ?></span>
                <span class="muted xs"><?= fa_num((int)$r['c']) ?> فروش • <?= $d_short((float)$r['s']) ?></span>
              </div>
              <div class="bar"><span style="width:<?= max(6, (int)round(((int)$r['c'] / $tmax) * 100)) ?>%"></span></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($m_prodOut > 0): ?>
      <div class="alert a-warn mt3">⚠️ <b><?= fa_num($m_prodOut) ?></b> محصول فعال موجودی صفر دارد و قابل خرید نیست.</div>
    <?php endif; ?>
  </div>

  <div class="card fade-up">
    <div class="card-head">
      <div><div class="card-title">💎 مشتریان برتر</div>
      <div class="card-sub">بیشترین مبلغ خرید در دوره</div></div>
      <?php if ($d_page('users')): ?><a class="btn btn-sm" href="index.php?p=users">کاربران</a><?php endif; ?>
    </div>
    <?php if (!$d_buyers): ?>
      <div class="empty"><div class="ic">👤</div>خریدی در این بازه ثبت نشده است.</div>
    <?php else: $bmax = max(1.0, (float)$d_buyers[0]['s']); ?>
      <div class="rank">
        <?php foreach ($d_buyers as $i => $r):
              $nm = trim((string)($r['first_name'] ?? '')) ?: ('@' . (string)($r['username'] ?? '') );
              $nm = trim($nm, '@') === '' ? ('کاربر ' . fa_num((string)$r['tg_id'])) : $nm; ?>
          <div class="rank-row">
            <span class="rank-n n<?= $i + 1 ?>"><?= fa_num($i + 1) ?></span>
            <div class="grow">
              <div class="row-between">
                <span class="nm"><?= h($nm) ?> <span class="muted mono xs ltr"><?= h((string)$r['tg_id']) ?></span></span>
                <span class="muted xs"><?= fa_num((int)$r['c']) ?> خرید • <?= $d_short((float)$r['s']) ?></span>
              </div>
              <div class="bar g"><span style="width:<?= max(6, (int)round(((float)$r['s'] / $bmax) * 100)) ?>%"></span></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============================ انقضای نزدیک + رسیدها ============================ -->
<div class="grid g2 mt4">
  <div class="card fade-up">
    <div class="card-head">
      <div><div class="card-title">⏳ سرویس‌های رو به انقضا</div>
      <div class="card-sub">کمتر از ۷ روز تا پایان اعتبار — فرصت تمدید</div></div>
      <span class="badge <?= $m_svcSoon > 0 ? 'b-orange' : 'b-gray' ?>"><?= fa_num($m_svcWeek) ?> مورد</span>
    </div>
    <?php if (!$d_expiring): ?>
      <div class="empty"><div class="ic">✅</div>سرویسی در آستانهٔ انقضا نیست.</div>
    <?php else: ?>
      <?php foreach ($d_expiring as $s):
            $left = max(0, (int)floor((strtotime((string)$s['expire_at']) - time()) / 86400));
            $vol  = (float)($s['volume_gb'] ?? 0);
            $usd  = (float)($s['used_bytes'] ?? 0) / 1073741824;
            $pc   = $vol > 0 ? min(100, (int)round(($usd / $vol) * 100)) : 0; ?>
        <div class="exp-row">
          <div class="lft">
            <div class="nm mono ltr"><?= h((string)($s['client_email'] ?: ('#' . $s['id']))) ?></div>
            <div class="muted xs"><?= h(trim((string)($s['first_name'] ?? '')) ?: 'کاربر') ?> • <span class="mono ltr"><?= h((string)$s['tg_id']) ?></span></div>
            <?php if ($vol > 0): ?><div class="bar sm <?= $pc > 85 ? 'r' : ($pc > 60 ? 'a' : '') ?>"><span style="width:<?= $pc ?>%"></span></div><?php endif; ?>
          </div>
          <div class="rgt">
            <span class="badge <?= $left <= 1 ? 'b-red' : ($left <= 3 ? 'b-orange' : 'b-blue') ?>"><?= $left <= 0 ? 'امروز' : fa_num($left) . ' روز' ?></span>
            <div class="muted xs"><?= h(to_jalali((string)$s['expire_at'])) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if ($d_page('services')): ?>
        <div class="card-foot"><a class="btn btn-sm btn-block" href="index.php?p=services">مشاهدهٔ همهٔ سرویس‌ها</a></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="card fade-up">
    <div class="card-head">
      <div><div class="card-title">🧾 رسیدهای در انتظار تایید</div>
      <div class="card-sub">جمع مبلغ معلق: <?= $d_m($m_paySum) ?></div></div>
      <span class="badge <?= $m_payPending > 0 ? 'b-orange' : 'b-gray' ?>"><?= fa_num($m_payPending) ?></span>
    </div>
    <?php if (!$d_receipts): ?>
      <div class="empty"><div class="ic">🎉</div>همهٔ رسیدها بررسی شده‌اند.</div>
    <?php else: ?>
      <?php foreach ($d_receipts as $t): ?>
        <div class="exp-row">
          <div class="lft">
            <div class="nm"><?= h(trim((string)($t['first_name'] ?? '')) ?: 'کاربر') ?>
              <span class="muted mono xs ltr"><?= h((string)$t['tg_id']) ?></span></div>
            <div class="muted xs"><?= h($MET_LBL[(string)$t['method']] ?? (string)$t['method']) ?> • <?= h(to_jalali((string)$t['created_at'])) ?></div>
          </div>
          <div class="rgt">
            <div class="amt"><?= $d_m((float)$t['amount']) ?></div>
            <?php if ($d_page('payments')): ?><a class="btn btn-sm" href="index.php?p=payments">بررسی</a><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="kv mt3"><span class="k">شارژ تاییدشدهٔ امروز</span><span><?= $d_m($m_depToday) ?></span></div>
    <div class="kv"><span class="k">انبار فروخته‌شده</span><span><?= fa_num($m_stockSold) ?> قلم</span></div>
  </div>
</div>

<!-- ============================ آخرین سفارش‌ها ============================ -->
<div class="card mt4 fade-up">
  <div class="card-head">
    <div><div class="card-title">🧾 آخرین سفارش‌ها</div>
    <div class="card-sub">۱۰ تراکنش آخر فروشگاه</div></div>
    <div class="row" style="gap:6px">
      <?php if ($m_ordPend > 0): ?><span class="badge b-orange"><?= fa_num($m_ordPend) ?> در جریان</span><?php endif; ?>
      <?php if ($d_page('health')): ?><a class="btn btn-sm" href="index.php?p=health">سفارش‌های گیرکرده</a><?php endif; ?>
    </div>
  </div>

  <?php if (!$d_orders): ?>
    <div class="empty"><div class="ic">🛒</div>هنوز سفارشی ثبت نشده است.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table data-enhance="1" data-page-size="10">
        <thead>
          <tr>
            <th>#</th><th>محصول</th><th>کاربر</th><th>مبلغ</th><th>وضعیت</th><th>زمان</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($d_orders as $o):
              $st = (string)$o['status'];
              $stMap = [
                'paid'    => ['b-green',  'پرداخت شده'],
                'pending' => ['b-orange', 'در انتظار'],
                'failed'  => ['b-red',    'ناموفق'],
                'canceled'=> ['b-gray',   'لغو شده'],
              ];
              [$stCls, $stTxt] = $stMap[$st] ?? ['b-gray', $st]; ?>
          <tr>
            <td class="mono ltr"><?= fa_num((int)$o['id']) ?></td>
            <td><?= h((string)($o['pname'] ?? '—')) ?></td>
            <td class="mono ltr xs"><?= h((string)($o['tg_id'] ?? '—')) ?></td>
            <td data-l="<?= (float)$o['final_amount'] ?>"><?= $d_m((float)$o['final_amount']) ?></td>
            <td><span class="badge <?= $stCls ?>"><?= h($stTxt) ?></span></td>
            <td class="xs muted" data-l="<?= h((string)$o['created_at']) ?>"><?= h(to_jalali((string)$o['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- ============================ تیکت‌ها + نقشهٔ حرارتی ============================ -->
<div class="grid g2 mt4">
  <div class="card fade-up">
    <div class="card-head">
      <div><div class="card-title">🆘 تیکت‌های باز</div>
      <div class="card-sub">امروز <?= fa_num($m_tkToday) ?> تیکت تازه ثبت شده</div></div>
      <span class="badge <?= $m_tkOpen > 0 ? 'b-red' : 'b-gray' ?>"><?= fa_num($m_tkOpen) ?></span>
    </div>
    <?php if (!$d_tickets): ?>
      <div class="empty"><div class="ic">💌</div>تیکت بازی وجود ندارد.</div>
    <?php else: ?>
      <div class="tl">
        <?php foreach ($d_tickets as $t): ?>
          <div class="it">
            <span class="dot"></span>
            <div class="grow">
              <div class="nm"><?= h((string)($t['subject'] ?: 'بدون موضوع')) ?></div>
              <div class="muted xs"><?= h(trim((string)($t['first_name'] ?? '')) ?: 'کاربر') ?>
                • <span class="mono ltr"><?= h((string)$t['tg_id']) ?></span>
                • <?= h(to_jalali((string)$t['updated_at'])) ?></div>
            </div>
            <?php if ($d_page('tickets')): ?><a class="btn btn-sm" href="index.php?p=tickets">پاسخ</a><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card fade-up">
    <div class="card-head">
      <div><div class="card-title">🔥 نقشهٔ فروش ۲۸ روز اخیر</div>
      <div class="card-sub">هر خانه یک روز — رنگ پررنگ‌تر یعنی سفارش بیشتر</div></div>
    </div>
    <div class="heat">
      <?php foreach ($d_heat as $hcell):
            $v  = (float)$hcell['v'];
            $lv = $v <= 0 ? 0 : ($heat_max <= 0 ? 1 : (int)ceil(($v / $heat_max) * 4)); ?>
        <i class="l<?= $lv ?>" title="<?= h(to_jalali((string)$hcell['date'])) ?> — <?= h(fa_num((int)$v)) ?> سفارش"></i>
      <?php endforeach; ?>
    </div>
    <div class="heat-legend">
      <span class="muted xs">کمتر</span>
      <i class="l0"></i><i class="l1"></i><i class="l2"></i><i class="l3"></i><i class="l4"></i>
      <span class="muted xs">بیشتر</span>
      <span class="grow"></span>
      <span class="muted xs">بیشینهٔ روزانه: <?= fa_num((int)$heat_max) ?> سفارش</span>
    </div>

    <div class="kv mt4"><span class="k">سرویس‌های منقضی‌شده</span><span><?= fa_num($m_svcExpired) ?></span></div>
    <div class="kv"><span class="k">سفارش ناموفق دوره</span><span><?= fa_num($m_ordFail) ?></span></div>
  </div>
</div>

<!-- ============================ ظرفیت پنل‌ها ============================ -->
<?php if ($d_panels && $d_page('panels')): ?>
<div class="card mt4 fade-up">
  <div class="card-head">
    <div><div class="card-title">🖧 وضعیت و ظرفیت پنل‌ها</div>
    <div class="card-sub">میزان اشغال سهمیهٔ کاربر در هر پنل</div></div>
    <a class="btn btn-sm" href="index.php?p=panels">مدیریت پنل‌ها</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>پنل</th><th>نوع</th><th>ظرفیت</th><th>وضعیت</th></tr></thead>
      <tbody>
      <?php foreach ($d_panels as $pn):
            $lim = (int)($pn['user_limit'] ?? 0);
            $usd = (int)($pn['users_created'] ?? 0);
            $pc  = $lim > 0 ? min(100, (int)round(($usd / $lim) * 100)) : 0;
            $err = trim((string)($pn['last_error'] ?? '')); ?>
        <tr>
          <td>
            <div class="nm"><?= h((string)$pn['name']) ?></div>
            <div class="muted xs mono ltr"><?= h((string)$pn['host']) ?></div>
          </td>
          <td><span class="badge b-blue"><?= h((string)$pn['type']) ?></span></td>
          <td style="min-width:170px">
            <div class="row-between xs"><span class="muted"><?= fa_num($usd) ?><?= $lim > 0 ? ' / ' . fa_num($lim) : '' ?></span><span class="muted"><?= $lim > 0 ? fa_num($pc) . '٪' : 'نامحدود' ?></span></div>
            <div class="pw-meter"><span style="width:<?= $lim > 0 ? $pc : 100 ?>%;background:<?= $pc > 85 ? 'var(--red)' : ($pc > 60 ? 'var(--orange)' : 'var(--green)') ?>"></span></div>
          </td>
          <td>
            <?php if ((int)$pn['active'] !== 1): ?>
              <span class="badge b-gray">غیرفعال</span>
            <?php elseif ($err !== ''): ?>
              <span class="badge b-red" title="<?= h(mb_substr($err, 0, 160)) ?>">خطای اتصال</span>
            <?php else: ?>
              <span class="badge b-green">سالم</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ============================ نوار وضعیت سامانه ============================ -->
<div class="sys-strip mt4 fade-up">
  <div class="si <?= $beatOk ? 'ok' : 'bad' ?>"><span class="d"></span>کران‌جاب: <b><?= $beatAge < 0 ? 'اجرا نشده' : fa_num((int)round($beatAge / 60)) . ' دقیقه پیش' ?></b></div>
  <div class="si <?= $m_stuck > 0 ? 'bad' : 'ok' ?>"><span class="d"></span>سفارش گیرکرده: <b><?= fa_num($m_stuck) ?></b></div>
  <div class="si <?= $panelBad > 0 ? 'warn' : 'ok' ?>"><span class="d"></span>پنل مشکل‌دار: <b><?= fa_num($panelBad) ?></b></div>
  <div class="si"><span class="d"></span>نسخهٔ PHP: <b class="mono ltr"><?= h(PHP_VERSION) ?></b></div>
  <div class="si"><span class="d"></span>آخرین بروزرسانی صفحه: <b><?= fa_num(date('H:i')) ?></b></div>
</div>

<script>
/* راهنمای شناور نمودار فروش (بدون وابستگی بیرونی) */
(function () {
  var box = document.getElementById('dChart');
  if (!box) return;
  var tip = document.getElementById('dTip');
  var svg = box.querySelector('svg');
  if (!tip || !svg) return;

  function show(cell) {
    var d = cell.dataset;
    tip.innerHTML =
      '<div class="t">' + (d.t || '') + '</div>' +
      '<div class="r"><i style="background:#5B8CFF"></i><span>درآمد</span><b>' + (d.v || '0') + '</b></div>' +
      '<div class="r"><i style="background:#2FD48F"></i><span>سفارش</span><b>' + (d.c || '0') + '</b></div>' +
      '<div class="r"><i style="background:#8B5CF6"></i><span>کاربر تازه</span><b>' + (d.u || '0') + '</b></div>';
    var w = box.clientWidth, hgt = box.clientHeight;
    var x = parseFloat(d.x || '0') * w;
    var y = parseFloat(d.y || '0') * hgt;
    tip.classList.add('on');
    var tw = tip.offsetWidth, th = tip.offsetHeight;
    var lx = Math.min(Math.max(x - tw / 2, 6), Math.max(6, w - tw - 6));
    var ly = y - th - 14;
    if (ly < 4) ly = y + 16;
    tip.style.transform = 'translate(' + Math.round(lx) + 'px,' + Math.round(ly) + 'px)';
  }

  function hide() { tip.classList.remove('on'); }

  svg.addEventListener('pointermove', function (e) {
    var cell = e.target.closest ? e.target.closest('rect.hv') : null;
    if (cell) show(cell); else hide();
  }, { passive: true });

  svg.addEventListener('touchstart', function (e) {
    var t = e.touches && e.touches[0];
    if (!t) return;
    var el = document.elementFromPoint(t.clientX, t.clientY);
    var cell = el && el.closest ? el.closest('rect.hv') : null;
    if (cell) show(cell);
  }, { passive: true });

  box.addEventListener('pointerleave', hide);
  window.addEventListener('scroll', hide, { passive: true });
})();
</script>
