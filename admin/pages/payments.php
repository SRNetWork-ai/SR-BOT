<?php
if (!can('payments.view')) { echo denyBox('این بخش برای شما فعال نیست.'); return; }
/**
 * پرداخت‌ها — استودیو مالی
 * رسیدهای کارت‌به‌کارت، پرداخت ارزی، سفارش‌ها، تحلیل درآمد و خروجی CSV
 */

$TYPES = [
    'deposit'  => ['شارژ کیف پول', '💰'],
    'purchase' => ['خرید سرویس', '🛒'],
    'refund'   => ['بازگشت وجه', '↩️'],
    'gift'     => ['کد هدیه', '🎁'],
    'admin'    => ['دستی مدیر', '🛠'],
    'referral' => ['پاداش زیرمجموعه', '🤝'],
];
$METHODS = [
    /* 0.0.2 #22 */
    'zarinpal' => ['زرین‌پال (خودکار)', "\u{1F3E6}"],
    /* fixed84: درگاه‌های خودکار هم برچسب درست داشته باشند */
    'hooshpay' => ['هوش‌پی (خودکار)', '🪙'],
    'nowpay'   => ['نوپیمنتس (خودکار)', '🤖'],
    'card'   => ['کارت به کارت', '💳'],
    'crypto' => ['پرداخت ارزی', '🌐'],
    'wallet' => ['کیف پول', '👛'],
    'gift'   => ['کد هدیه', '🎁'],
    'admin'  => ['مدیر', '🛠'],
];
$ST_LBL = ['pending' => 'در انتظار', 'approved' => 'تایید شده', 'rejected' => 'رد شده', 'canceled' => 'لغو شده'];

$typeLabel = function (string $k) use ($TYPES): string {
    return isset($TYPES[$k]) ? ($TYPES[$k][1] . ' ' . $TYPES[$k][0]) : $k;
};
$methodLabel = function (string $k) use ($METHODS): string {
    return isset($METHODS[$k]) ? ($METHODS[$k][1] . ' ' . $METHODS[$k][0]) : $k;
};

$act = (string)($_POST['act'] ?? '');

/* ==================== نمایش امن رسید ====================
 * فایل از تلگرام خوانده و از طریق همین صفحه پخش می‌شود تا
 * توکن ربات در آدرس مرورگر لو نرود. */
$fileTx = (int)($_GET['file'] ?? 0);
if ($fileTx) {
    $t = DB::one('SELECT * FROM {p}transactions WHERE id = :id', [':id' => $fileTx]);
    while (ob_get_level() > 0) ob_end_clean();

    $src = '';
    if ($t && !empty($t['receipt_file'])) {
        if (method_exists('Tg', 'fileUrl')) {
            $src = (string)Tg::fileUrl((string)$t['receipt_file']);
        } else {
            $r    = Tg::api('getFile', ['file_id' => (string)$t['receipt_file']]);
            $path = (string)($r['result']['file_path'] ?? '');
            if ($path !== '') $src = 'https://api.telegram.org/file/bot' . cfg('bot.token') . '/' . $path;
        }
    }

    if ($src === '') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'رسید در دسترس نیست. (فایل‌های قدیمی تلگرام پاک می‌شوند یا سرور به تلگرام دسترسی ندارد)';
        exit;
    }

    $ext   = strtolower((string)pathinfo((string)parse_url($src, PHP_URL_PATH), PATHINFO_EXTENSION));
    $mimes = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
        'mp4' => 'video/mp4',
    ];
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: private, max-age=900');
    header('X-Content-Type-Options: nosniff');
    if (!empty($_GET['dl'])) {
        header('Content-Disposition: attachment; filename="receipt-' . $fileTx . '.' . ($ext ?: 'bin') . '"');
    }
    $fh = @fopen($src, 'rb');
    if ($fh) { fpassthru($fh); fclose($fh); }
    exit;
}

/* ==================== فیلترها ==================== */
$tab = (string)($_GET['tab'] ?? 'pending');
if (!in_array($tab, ['pending', 'gateway', 'all', 'orders', 'stats'], true)) $tab = 'pending';

$fSt   = (string)($_GET['st'] ?? '');
$fMe   = (string)($_GET['me'] ?? '');
$fTy   = (string)($_GET['ty'] ?? '');
$fFrom = trim((string)($_GET['from'] ?? ''));
$fTo   = trim((string)($_GET['to'] ?? ''));
$fMin  = trim((string)($_GET['min'] ?? ''));
$fQ    = trim((string)($_GET['q'] ?? ''));

if (!isset($ST_LBL[$fSt]))   $fSt = '';
if (!isset($METHODS[$fMe]))  $fMe = '';
if (!isset($TYPES[$fTy]))    $fTy = '';
if ($fFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) $fFrom = '';
if ($fTo   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo))   $fTo   = '';

$hasFilter = ($fSt !== '' || $fMe !== '' || $fTy !== '' || $fFrom !== '' || $fTo !== '' || $fMin !== '' || $fQ !== '');

/** ساخت شرط‌های SQL بر اساس فیلترها */
$buildWhere = function (bool $onlyPending, string $scope = '') use ($fSt, $fMe, $fTy, $fFrom, $fTo, $fMin, $fQ): array {
    $w = [];
    $p = [];
    if ($onlyPending) {
        $w[] = "t.status = 'pending'";
    } elseif ($fSt !== '') {
        $w[] = 't.status = :st';
        $p[':st'] = $fSt;
    }
    /* fixed84: صف رسیدهای دستی از فاکتورهای درگاه خودکار جدا شد */
    $autoList = class_exists('Wallet') ? Wallet::autoSqlList() : "'hooshpay','nowpay','zarinpal'";
    if ($scope === 'manual') $w[] = 't.method NOT IN (' . $autoList . ')';
    if ($scope === 'auto')   $w[] = 't.method IN (' . $autoList . ')';
    if ($fMe !== '') { $w[] = 't.method = :me'; $p[':me'] = $fMe; }
    if ($fTy !== '') { $w[] = 't.type = :ty';   $p[':ty'] = $fTy; }
    if ($fFrom !== '') { $w[] = 'DATE(t.created_at) >= :df'; $p[':df'] = $fFrom; }
    if ($fTo !== '')   { $w[] = 'DATE(t.created_at) <= :dt'; $p[':dt'] = $fTo; }
    if ($fMin !== '' && is_numeric($fMin)) { $w[] = 't.amount >= :mn'; $p[':mn'] = (float)$fMin; }
    if ($fQ !== '') {
        $w[] = '(t.txid LIKE :q OR t.ref LIKE :q OR t.note LIKE :q OR u.first_name LIKE :q OR u.username LIKE :q OR CAST(u.tg_id AS CHAR) LIKE :q OR CAST(t.id AS CHAR) = :qe)';
        $p[':q']  = '%' . $fQ . '%';
        $p[':qe'] = $fQ;
    }
    return [$w ? ('WHERE ' . implode(' AND ', $w)) : '', $p];
};

/* ==================== خروجی CSV ==================== */
if (isset($_GET['export'])) {
    [$where, $params] = $buildWhere(false);
    $rows = DB::all("SELECT t.*, u.first_name, u.username, u.tg_id AS utg
                     FROM {p}transactions t LEFT JOIN {p}users u ON u.id = t.user_id
                     $where ORDER BY t.id DESC LIMIT 5000", $params);

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transactions-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['شناسه', 'کاربر', 'یوزرنیم', 'آیدی تلگرام', 'نوع', 'روش', 'مبلغ', 'وضعیت', 'کد رهگیری', 'توضیح', 'تاریخ ثبت', 'تاریخ تصمیم']);
    foreach ($rows as $r) {
        fputcsv($out, [
            (int)$r['id'],
            (string)($r['first_name'] ?? ''),
            (string)($r['username'] ?? ''),
            (string)($r['utg'] ?? ''),
            (string)($TYPES[(string)$r['type']][0] ?? $r['type']),
            (string)($METHODS[(string)$r['method']][0] ?? $r['method']),
            (float)$r['amount'],
            (string)($ST_LBL[(string)$r['status']] ?? $r['status']),
            (string)($r['txid'] ?? ''),
            (string)($r['note'] ?? ''),
            (string)$r['created_at'],
            (string)($r['decided_at'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

/* ==================== عملیات ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $id  = pint('id');
    $ret = ['tab' => $tab];

    if ($act === 'approve' && $id) { need('payments.approve', 'payments');
        $r = Wallet::approve($id, (int)$ADMIN['id']);
        flash(!empty($r['ok']) ? 'ok' : 'err', !empty($r['ok'])
            ? '✅ پرداخت تایید شد و کیف پول کاربر شارژ شد.'
            : h((string)($r['message'] ?? 'تایید ناموفق بود.')));
        back('payments', $ret);
    }

    if ($act === 'reject' && $id) { need('payments.reject', 'payments');
        $r = Wallet::reject($id, (int)$ADMIN['id'], ptxt('reason'));
        flash(!empty($r['ok']) ? 'ok' : 'err', !empty($r['ok'])
            ? 'پرداخت رد شد و به کاربر اطلاع داده شد.'
            : h((string)($r['message'] ?? 'عملیات ناموفق بود.')));
        back('payments', $ret);
    }

    /* ---------- عملیات گروهی ---------- */
    /* fixed84: استعلام وضعیت فاکتور از درگاه خودکار */
    if ($act === 'poll' && $id) { need('payments.approve', 'payments');
        $tx = DB::one('SELECT * FROM {p}transactions WHERE id = :id', [':id' => $id]);
        if (!$tx) { flash('err', 'تراکنش پیدا نشد.'); back('payments', $ret); }
        $mth = strtolower((string)$tx['method']);
        $pr  = ['ok' => false, 'message' => 'استعلام برای این روش پرداخت ممکن نیست.'];
        if ($mth === 'hooshpay' && class_exists('HooshPay'))   $pr = HooshPay::poll($tx);
        if ($mth === 'zarinpal' && class_exists('Zarinpal'))   $pr = Zarinpal::poll($tx); /* 0.0.2 #22 */
        elseif ($mth === 'nowpay' && class_exists('NowPay'))   $pr = NowPay::poll($tx);
        flash(!empty($pr['ok']) ? 'ok' : 'err', h((string)($pr['message'] ?? '-')));
        back('payments', $ret);
    }

    if ($act === 'bulk') {
        $ids  = array_values(array_unique(array_map('intval', (array)($_POST['ids'] ?? []))));
        $ids  = array_filter($ids, fn($x) => $x > 0);
        $mode = ptxt('mode');

        if (!$ids) {
            flash('warn', 'هیچ تراکنشی انتخاب نشده بود.');
            back('payments', $ret);
        }
        if (count($ids) > 100) $ids = array_slice($ids, 0, 100);

        $ok = 0; $fail = 0;

        if ($mode === 'approve') { need('payments.approve', 'payments');
            foreach ($ids as $tid) {
                $r = Wallet::approve($tid, (int)$ADMIN['id']);
                if (!empty($r['ok'])) { $ok++; } else { $fail++; }
            }
            flash($ok ? 'ok' : 'err', '✅ ' . fa_num($ok) . ' پرداخت تایید و شارژ شد'
                . ($fail ? ' · ❌ ' . fa_num($fail) . ' مورد ناموفق' : '') . '.');
        } elseif ($mode === 'reject') { need('payments.reject', 'payments');
            $reason = ptxt('reason');
            foreach ($ids as $tid) {
                $r = Wallet::reject($tid, (int)$ADMIN['id'], $reason);
                if (!empty($r['ok'])) { $ok++; } else { $fail++; }
            }
            flash($ok ? 'ok' : 'err', '❌ ' . fa_num($ok) . ' پرداخت رد شد'
                . ($fail ? ' · ' . fa_num($fail) . ' مورد ناموفق' : '') . '.');
        } else {
            flash('err', 'عملیات نامعتبر است.');
        }
        back('payments', $ret);
    }

    if ($act === 'recheck' && $id) { need('payments.approve', 'payments');
        if (!class_exists('TxCheck')) {
            flash('err', 'ماژول بررسی خودکار هش در دسترس نیست.');
            back('payments', $ret);
        }
        $r   = TxCheck::recheck($id);
        $txt = nl2br(h(TxCheck::summary($r)));
        if ($txt === '') $txt = 'نتیجه‌ای برگردانده نشد.';

        $dec = method_exists('TxCheck', 'decide')
            ? TxCheck::decide($r)
            : (!empty($r['ok']) ? 'approve' : 'manual');

        $apply = pchk('apply') === 1;
        $tx    = DB::one('SELECT * FROM {p}transactions WHERE id = :id', [':id' => $id]);
        $lvl   = 'err';

        if ($apply && $dec === 'approve') {
            $w = Wallet::approve($id, (int)$ADMIN['id'], false);
            if (!empty($w['ok'])) {
                $lvl  = 'ok';
                $txt .= '<br>✅ هش درست بود — تراکنش تایید و کیف پول شارژ شد.';
                if ($tx) {
                    Tg::send((int)$tx['tg_id'], "✅ <b>پرداخت شما تایید شد</b>\n\n"
                        . '💰 مبلغ ' . money((int)$tx['amount']) . " به کیف پول شما افزوده شد.\n"
                        . '🧾 شماره پیگیری: <code>#' . (int)$id . '</code>');
                }
            } else {
                $txt .= '<br>' . h((string)($w['message'] ?? ''));
            }
        } elseif ($apply && $dec === 'reject') {
            $reason = trim(str_replace("\n", ' ', (string)($r['message'] ?? '')));
            $w = Wallet::reject($id, (int)$ADMIN['id'], 'هش‌چکر: ' . $reason, false);
            if (!empty($w['ok'])) {
                $lvl  = 'ok';
                $txt .= '<br>❌ هش معتبر نبود — تراکنش رد شد.';
                if ($tx) {
                    Tg::send((int)$tx['tg_id'], "❌ <b>پرداخت شما تایید نشد</b>\n\n"
                        . '🔎 علت: ' . h($reason) . "\n"
                        . '🧾 شماره پیگیری: <code>#' . (int)$id . "</code>\n\n"
                        . 'اگر فکر می‌کنید اشتباهی رخ داده، با پشتیبانی تماس بگیرید.');
                }
            } else {
                $txt .= '<br>' . h((string)($w['message'] ?? ''));
            }
        } else {
            if (!empty($r['ok'])) {
                $lvl  = 'ok';
                $txt .= '<br>✅ هش تایید شد. با دکمهٔ «تایید و شارژ» پرداخت را نهایی کنید.';
            } elseif ($dec === 'reject') {
                $txt .= '<br>⚠️ این هش قطعاً اشتباه است. با دکمهٔ «بررسی و اعمال خودکار» خودکار رد می‌شود.';
            } else {
                $txt .= '<br>🕓 نتیجه قطعی نیست؛ تصمیم با شماست.';
            }
        }

        flash($lvl, $txt);
        back('payments', $ret);
    }
}

/* ==================== آمار ==================== */
/* fixed84: رسیدهای دستی و فاکتورهای درگاه جدا شمرده می‌شوند */
$autoIn   = class_exists('Wallet') ? Wallet::autoSqlList() : "'hooshpay','nowpay','zarinpal'";
$cntPend  = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE status = 'pending' AND method NOT IN ($autoIn)", [], 0);
$sumPend  = (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE status = 'pending' AND method NOT IN ($autoIn)", [], 0);
$cntAuto  = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE status = 'pending' AND method IN ($autoIn)", [], 0);
$sumToday = (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE status = 'approved' AND type = 'deposit' AND DATE(decided_at) = CURDATE()", [], 0);
$sumWeek  = (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE status = 'approved' AND type = 'deposit' AND decided_at >= (CURDATE() - INTERVAL 6 DAY)", [], 0);
$sumMonth = (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE status = 'approved' AND type = 'deposit' AND decided_at >= (CURDATE() - INTERVAL 29 DAY)", [], 0);
$sumAll   = (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE status = 'approved' AND type = 'deposit'", [], 0);
$cntAppr  = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE status = 'approved' AND type = 'deposit'", [], 0);
$cntRej   = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE status = 'rejected'", [], 0);
$cntOrd   = (int)DB::val('SELECT COUNT(*) FROM {p}orders', [], 0);
$sumOrd   = (float)DB::val("SELECT COALESCE(SUM(final_amount),0) FROM {p}orders WHERE status IN ('paid','done','active','completed')", [], 0);
$avgDep   = $cntAppr > 0 ? ($sumAll / $cntAppr) : 0.0;
$usdRate  = (float)DB::setting('usd_rate', 100000);

/* نرخ تایید */
$decided  = $cntAppr + $cntRej;
$apprPct  = $decided > 0 ? (int)round($cntAppr * 100 / $decided) : 0;

/* قدیمی‌ترین تراکنش در انتظار */
$oldPend = DB::one("SELECT created_at FROM {p}transactions WHERE status = 'pending' AND method NOT IN ($autoIn) ORDER BY id ASC LIMIT 1");
$waitTxt = '—';
if ($oldPend && !empty($oldPend['created_at'])) {
    $mins = (int)max(0, (time() - strtotime((string)$oldPend['created_at'])) / 60);
    if ($mins < 60) {
        $waitTxt = fa_num($mins) . ' دقیقه';
    } elseif ($mins < 1440) {
        $waitTxt = fa_num((int)floor($mins / 60)) . ' ساعت';
    } else {
        $waitTxt = fa_num((int)floor($mins / 1440)) . ' روز';
    }
}
?>

<style>
/* ===== Payments Studio ===== */
.pay-hero{
  position:relative; overflow:hidden; padding:var(--s5) var(--s4);
  border:1px solid var(--border); border-radius:var(--r-xl);
  background:linear-gradient(155deg, rgba(47,212,143,.14), rgba(91,140,255,.10) 45%, transparent 75%), var(--surface);
  box-shadow:var(--shadow);
}
.pay-glow{ position:absolute; border-radius:50%; filter:blur(60px); opacity:.5; pointer-events:none; }
.pay-glow.g1{ width:230px; height:230px; background:rgba(47,212,143,.32); inset-block-start:-95px; inset-inline-end:-60px; }
.pay-glow.g2{ width:190px; height:190px; background:rgba(91,140,255,.30); inset-block-end:-95px; inset-inline-start:-55px; }
.pay-htop{ position:relative; display:flex; align-items:flex-start; gap:var(--s3); flex-wrap:wrap; }
.pay-hic{
  width:52px; height:52px; flex:none; display:grid; place-items:center; font-size:26px;
  border-radius:var(--r-lg); background:var(--grad-soft, var(--accent-soft)); border:1px solid var(--border);
  box-shadow:var(--glow);
}
.pay-htt h2{ margin:0; font-size:17px; }
.pay-htt p{ margin:4px 0 0; font-size:12px; color:var(--muted); line-height:1.8; max-width:56ch; }
.pay-hact{ margin-inline-start:auto; display:flex; gap:6px; flex-wrap:wrap; }

.pay-cells{ position:relative; display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:var(--s2); margin-top:var(--s4); }
.pay-cell{
  display:flex; flex-direction:column; align-items:center; gap:2px; padding:var(--s3) var(--s2);
  background:var(--surface-2); border:1px solid var(--border); border-radius:var(--r);
  transition:transform .18s, border-color .18s;
}
.pay-cell:hover{ transform:translateY(-3px); border-color:var(--accent); }
.pay-cell .i{ font-size:17px; }
.pay-cell .v{ font-family:var(--font-num); font-size:16px; font-weight:800; line-height:1.5; text-align:center; }
.pay-cell .l{ font-size:10.5px; color:var(--muted); text-align:center; }
.pay-cell.o .v{ color:var(--orange); } .pay-cell.g .v{ color:var(--green); }
.pay-cell.r .v{ color:var(--red); }    .pay-cell.b .v{ color:var(--accent); }
.pay-cell.c .v{ color:var(--cyan); }   .pay-cell.p .v{ color:var(--accent-2); }

.pay-rate{ position:relative; margin-top:var(--s4); }
.pay-rate-t{ display:flex; justify-content:space-between; font-size:12px; color:var(--muted); margin-bottom:6px; }
.pay-rate-t b{ color:var(--green); font-family:var(--font-num); }
.pay-rate-bar{ height:8px; border-radius:99px; background:var(--surface-3); overflow:hidden; }
.pay-rate-bar span{ display:block; height:100%; border-radius:99px; background:linear-gradient(90deg, var(--green), var(--cyan)); transition:width .6s ease; }

.pay-nav{ display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:var(--s2); margin-top:var(--s3); }
.pay-nv{
  position:relative; display:flex; align-items:center; justify-content:center; gap:8px; padding:12px var(--s2);
  background:var(--surface); border:1px solid var(--border); border-radius:var(--r);
  color:var(--muted); font-size:12.5px; font-weight:700; text-decoration:none;
  transition:transform .18s, border-color .18s, color .18s, background .18s;
}
.pay-nv:hover{ transform:translateY(-2px); color:var(--text); border-color:var(--accent); }
.pay-nv.on{ color:#fff; background:var(--grad, var(--accent)); border-color:transparent; box-shadow:var(--glow); }
.pay-nv .i{ font-size:15px; }
.pay-nv .n{ min-width:20px; padding:1px 6px; border-radius:99px; font-family:var(--font-num); font-size:11px; background:var(--surface-3); color:var(--text); }
.pay-nv.on .n{ background:rgba(255,255,255,.22); color:#fff; }

/* ---- نوار فیلتر ---- */
.pay-filter{
  margin-top:var(--s3); padding:var(--s3);
  background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
}
.pay-fgrid{ display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:var(--s2); }
.pay-fgrid .field{ margin:0; }
.pay-fgrid label{ font-size:11px; color:var(--muted); }
.pay-fgrid input, .pay-fgrid select{ height:38px; font-size:12.5px; }
.pay-facts{ display:flex; gap:6px; flex-wrap:wrap; margin-top:var(--s3); align-items:center; }
.pay-chips{ display:flex; gap:6px; flex-wrap:wrap; margin-top:var(--s3); }
.pay-chip{
  display:inline-flex; align-items:center; gap:6px; padding:5px 11px; border-radius:99px;
  background:var(--accent-soft); border:1px solid var(--accent); color:var(--accent-text);
  font-size:11.5px; text-decoration:none;
}
.pay-chip b{ color:#fff; }
.pay-chip.clear{ background:var(--red-soft); border-color:var(--red); color:var(--red); }

/* ---- عملیات گروهی ---- */
.pay-bulk{
  position:sticky; inset-block-start:8px; z-index:20;
  display:flex; align-items:center; gap:var(--s2); flex-wrap:wrap; margin-top:var(--s3); padding:var(--s3);
  background:var(--surface-2); border:1px solid var(--accent); border-radius:var(--r); box-shadow:var(--shadow-lg);
}
.pay-bulk[hidden]{ display:none; }
.pay-bulk .cnt{ font-family:var(--font-num); font-weight:800; color:var(--accent); }

/* ---- کارت رسید ---- */
.pay-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:var(--s3); margin-top:var(--s3); }
.pay-card{
  position:relative; display:flex; flex-direction:column; overflow:hidden;
  background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
  transition:transform .18s, border-color .18s, box-shadow .18s;
}
.pay-card:hover{ transform:translateY(-3px); border-color:var(--accent); box-shadow:var(--shadow-lg); }
.pay-card.sel{ border-color:var(--green); box-shadow:0 0 0 1px var(--green); }
.pay-card .strip{ height:3px; background:linear-gradient(90deg,var(--orange),var(--accent)); }
.pay-card.m-crypto .strip{ background:linear-gradient(90deg,var(--cyan),var(--accent-2)); }
.pay-ctop{ display:flex; align-items:flex-start; gap:var(--s3); padding:var(--s3); }
.pay-ava{
  width:44px; height:44px; flex:none; border-radius:14px; display:grid; place-items:center;
  font-size:20px; background:var(--grad-soft, var(--accent-soft)); border:1px solid var(--border);
}
.pay-cwho{ min-width:0; flex:1; }
.pay-cwho .n{ font-weight:800; font-size:13.5px; }
.pay-cwho .s{ font-size:11px; color:var(--muted); font-family:var(--font-num); }
.pay-camt{ text-align:end; }
.pay-camt .a{ font-family:var(--font-num); font-size:16px; font-weight:800; color:var(--green); }
.pay-camt .t{ font-size:10.5px; color:var(--muted); }
.pay-cbody{ padding:0 var(--s3) var(--s3); display:flex; flex-direction:column; gap:6px; }
.pay-line{ display:flex; justify-content:space-between; gap:10px; font-size:12px; padding:6px 10px; background:var(--surface-2); border-radius:var(--r-sm); }
.pay-line .k{ color:var(--muted); flex:none; }
.pay-line .v{ text-align:end; word-break:break-all; }
.pay-shot{
  display:block; width:100%; max-height:190px; object-fit:cover; cursor:zoom-in;
  border-radius:var(--r-sm); border:1px solid var(--border); background:var(--surface-2);
}
.pay-cact{ display:flex; gap:6px; flex-wrap:wrap; padding:var(--s3); border-top:1px solid var(--border-soft); background:var(--surface-2); }
.pay-pick{ position:absolute; inset-block-start:10px; inset-inline-start:10px; z-index:3; }
.pay-pick input{ width:19px; height:19px; cursor:pointer; accent-color:var(--green); }

/* ---- نمودار ---- */
.pay-chart{ display:flex; align-items:flex-end; gap:5px; height:170px; padding:var(--s3) 0 0; }
.pay-bar{ flex:1; display:flex; flex-direction:column; justify-content:flex-end; align-items:center; gap:5px; min-width:0; }
.pay-bar .b{
  width:100%; border-radius:6px 6px 3px 3px; min-height:3px;
  background:linear-gradient(180deg,var(--green),rgba(47,212,143,.28));
  transition:filter .18s;
}
.pay-bar:hover .b{ filter:brightness(1.35); }
.pay-bar .d{ font-size:9.5px; color:var(--muted); font-family:var(--font-num); white-space:nowrap; }
.pay-bar .t{ font-size:9.5px; color:var(--green); font-family:var(--font-num); font-weight:700; }

.pay-split{ display:grid; grid-template-columns:1.3fr 1fr; gap:var(--s3); margin-top:var(--s3); }
.pay-mrow{ display:flex; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid var(--border-soft); }
.pay-mrow:last-child{ border-bottom:0; }
.pay-mrow .ic{ width:30px; height:30px; flex:none; display:grid; place-items:center; border-radius:9px; background:var(--surface-2); font-size:15px; }
.pay-mrow .nm{ flex:1; font-size:12.5px; min-width:0; }
.pay-mrow .mb{ height:6px; border-radius:99px; background:var(--surface-3); overflow:hidden; margin-top:5px; }
.pay-mrow .mb i{ display:block; height:100%; border-radius:99px; background:var(--grad, var(--accent)); }
.pay-mrow .vl{ font-family:var(--font-num); font-size:12.5px; font-weight:800; flex:none; }

.pay-rank{ display:flex; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid var(--border-soft); }
.pay-rank:last-child{ border-bottom:0; }
.pay-rank .no{
  width:26px; height:26px; flex:none; display:grid; place-items:center; border-radius:50%;
  background:var(--surface-3); font-family:var(--font-num); font-size:11.5px; font-weight:800;
}
.pay-rank .no.n1{ background:linear-gradient(135deg,#FFD76E,#FFA92E); color:#111; }
.pay-rank .no.n2{ background:linear-gradient(135deg,#D9E2F2,#9AA8BE); color:#111; }
.pay-rank .no.n3{ background:linear-gradient(135deg,#E0A579,#B9713C); color:#111; }

/* ---- لایت‌باکس ---- */
.pay-lb{ position:fixed; inset:0; z-index:9999; display:none; place-items:center; padding:24px; background:rgba(4,7,13,.9); backdrop-filter:blur(4px); }
.pay-lb.on{ display:grid; }
.pay-lb img{ max-width:min(92vw,900px); max-height:88vh; border-radius:var(--r-lg); box-shadow:var(--shadow-lg); }
.pay-lb .x{ position:absolute; inset-block-start:18px; inset-inline-end:18px; width:40px; height:40px; border-radius:50%; border:1px solid var(--border); background:var(--surface); color:var(--text); font-size:19px; cursor:pointer; }

@media (max-width:1100px){
  .pay-cells{ grid-template-columns:repeat(3,minmax(0,1fr)); }
  .pay-fgrid{ grid-template-columns:repeat(3,minmax(0,1fr)); }
  .pay-split{ grid-template-columns:1fr; }
}
@media (max-width:640px){
  .pay-cells{ grid-template-columns:repeat(2,minmax(0,1fr)); }
  .pay-fgrid{ grid-template-columns:repeat(2,minmax(0,1fr)); }
  .pay-nav{ grid-template-columns:repeat(2,minmax(0,1fr)); }
  .pay-grid{ grid-template-columns:1fr; }
  .pay-chart{ height:135px; }
  .pay-bar .d{ display:none; }
}
</style>

<div class="pay-hero">
  <span class="pay-glow g1"></span><span class="pay-glow g2"></span>

  <div class="pay-htop">
    <div class="pay-hic">💳</div>
    <div class="pay-htt">
      <h2>مرکز مالی</h2>
      <p>بررسی رسیدهای کارت‌به‌کارت و پرداخت‌های ارزی، مدیریت سفارش‌ها و تحلیل درآمد. پس از تایید، کیف پول کاربر خودکار شارژ و پیام اطلاع‌رسانی ارسال می‌شود.</p>
    </div>
    <div class="pay-hact">
      <a class="btn btn-sm" href="index.php?p=payments&amp;export=1<?= $hasFilter ? '&amp;' . h(http_build_query(array_filter(['st' => $fSt, 'me' => $fMe, 'ty' => $fTy, 'from' => $fFrom, 'to' => $fTo, 'min' => $fMin, 'q' => $fQ]))) : '' ?>">⬇️ خروجی CSV</a>
      <a class="btn btn-sm" href="index.php?p=gateways">🏦 درگاه‌ها</a>
    </div>
  </div>

  <div class="pay-cells">
    <div class="pay-cell o"><span class="i">⏳</span><span class="v"><?= fa_num($cntPend) ?></span><span class="l">در انتظار بررسی</span></div>
    <div class="pay-cell o"><span class="i">🧾</span><span class="v"><?= money($sumPend) ?></span><span class="l">مبلغ معلق</span></div>
    <div class="pay-cell g"><span class="i">📅</span><span class="v"><?= money($sumToday) ?></span><span class="l">شارژ امروز</span></div>
    <div class="pay-cell g"><span class="i">🗓</span><span class="v"><?= money($sumWeek) ?></span><span class="l">۷ روز اخیر</span></div>
    <div class="pay-cell c"><span class="i">📈</span><span class="v"><?= money($sumMonth) ?></span><span class="l">۳۰ روز اخیر</span></div>
    <div class="pay-cell b"><span class="i">💎</span><span class="v"><?= money($sumAll) ?></span><span class="l">کل شارژ تایید شده</span></div>
    <div class="pay-cell p"><span class="i">🧮</span><span class="v"><?= money($avgDep) ?></span><span class="l">میانگین هر شارژ</span></div>
    <div class="pay-cell b"><span class="i">🛒</span><span class="v"><?= fa_num($cntOrd) ?></span><span class="l">تعداد سفارش</span></div>
    <div class="pay-cell g"><span class="i">💵</span><span class="v"><?= money($sumOrd) ?></span><span class="l">درآمد سفارش‌ها</span></div>
    <div class="pay-cell r"><span class="i">⛔</span><span class="v"><?= fa_num($cntRej) ?></span><span class="l">رد شده</span></div>
    <div class="pay-cell o"><span class="i">⏱</span><span class="v"><?= $waitTxt ?></span><span class="l">انتظار قدیمی‌ترین</span></div>
    <div class="pay-cell c"><span class="i">💱</span><span class="v"><?= money($usdRate) ?></span><span class="l">نرخ دلار</span></div>
  </div>

  <div class="pay-rate">
    <div class="pay-rate-t">
      <span>نرخ تایید پرداخت‌ها</span>
      <b><?= fa_num($apprPct) ?>٪ · <?= fa_num($cntAppr) ?> تایید از <?= fa_num($decided) ?> بررسی</b>
    </div>
    <div class="pay-rate-bar"><span style="width:<?= (int)$apprPct ?>%"></span></div>
  </div>
</div>

<div class="pay-nav">
  <a class="pay-nv <?= $tab === 'pending' ? 'on' : '' ?>" href="index.php?p=payments&amp;tab=pending">
    <span class="i">⏳</span> در انتظار <span class="n"><?= fa_num($cntPend) ?></span></a>
  <a class="pay-nv <?= $tab === 'gateway' ? 'on' : '' ?>" href="index.php?p=payments&amp;tab=gateway">
    <span class="i">🪙</span> درگاه خودکار <span class="n"><?= fa_num($cntAuto) ?></span></a>
  <a class="pay-nv <?= $tab === 'all' ? 'on' : '' ?>" href="index.php?p=payments&amp;tab=all">
    <span class="i">🧾</span> تراکنش‌ها</a>
  <a class="pay-nv <?= $tab === 'orders' ? 'on' : '' ?>" href="index.php?p=payments&amp;tab=orders">
    <span class="i">🛒</span> سفارش‌ها <span class="n"><?= fa_num($cntOrd) ?></span></a>
  <a class="pay-nv <?= $tab === 'stats' ? 'on' : '' ?>" href="index.php?p=payments&amp;tab=stats">
    <span class="i">📊</span> تحلیل</a>
</div>

<?php if ($tab === 'pending' || $tab === 'all'): ?>
  <form class="pay-filter" method="get">
    <input type="hidden" name="p" value="payments">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <div class="pay-fgrid">
      <div class="field"><label>🔎 جستجو</label>
        <input type="search" name="q" value="<?= h($fQ) ?>" placeholder="نام، آیدی، کد رهگیری…"></div>
      <?php if ($tab === 'all'): ?>
        <div class="field"><label>وضعیت</label>
          <select name="st"><option value="">همه</option>
            <?php foreach ($ST_LBL as $k => $lb): ?>
              <option value="<?= h($k) ?>" <?= $fSt === $k ? 'selected' : '' ?>><?= h($lb) ?></option>
            <?php endforeach; ?>
          </select></div>
      <?php endif; ?>
      <div class="field"><label>روش پرداخت</label>
        <select name="me"><option value="">همه</option>
          <?php foreach ($METHODS as $k => $m): ?>
            <option value="<?= h($k) ?>" <?= $fMe === $k ? 'selected' : '' ?>><?= h($m[1] . ' ' . $m[0]) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>نوع تراکنش</label>
        <select name="ty"><option value="">همه</option>
          <?php foreach ($TYPES as $k => $m): ?>
            <option value="<?= h($k) ?>" <?= $fTy === $k ? 'selected' : '' ?>><?= h($m[1] . ' ' . $m[0]) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>از تاریخ (میلادی)</label>
        <input class="mono" type="date" name="from" value="<?= h($fFrom) ?>"></div>
      <div class="field"><label>تا تاریخ (میلادی)</label>
        <input class="mono" type="date" name="to" value="<?= h($fTo) ?>"></div>
      <div class="field"><label>حداقل مبلغ</label>
        <input class="mono" type="number" name="min" value="<?= h($fMin) ?>" placeholder="۰"></div>
    </div>
    <div class="pay-facts">
      <button class="btn btn-primary btn-sm" type="submit">🔍 اعمال فیلتر</button>
      <?php if ($hasFilter): ?>
        <a class="btn btn-sm btn-ghost" href="index.php?p=payments&amp;tab=<?= h($tab) ?>">✖️ پاک کردن</a>
      <?php endif; ?>
      <span class="hint" style="margin-inline-start:auto">فیلترها روی خروجی CSV هم اعمال می‌شوند</span>
    </div>
  </form>
<?php endif; ?>

<?php
/* ==================== تب: در انتظار ==================== */
if ($tab === 'pending'):
    [$where, $params] = $buildWhere(true, 'manual');
    $rows = DB::all("SELECT t.*, u.first_name, u.username, u.tg_id AS utg, u.balance
                     FROM {p}transactions t LEFT JOIN {p}users u ON u.id = t.user_id
                     $where ORDER BY t.id ASC LIMIT 200", $params);
?>
  <?php if (!$rows): ?>
    <div class="card mt3"><div class="empty"><div class="ic">🎉</div>
      <?= $hasFilter ? 'با این فیلترها موردی پیدا نشد.' : 'هیچ پرداخت معلقی نیست — همه چیز بررسی شده!' ?></div></div>
  <?php else: ?>

    <form method="post" id="payBulkForm">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="bulk">
      <input type="hidden" name="mode" id="payMode" value="">

      <div class="pay-bulk" id="payBulk" hidden>
        <span>✔️ <span class="cnt" id="payCnt">۰</span> مورد انتخاب شده</span>
        <input type="text" name="reason" placeholder="دلیل رد (اختیاری)" style="max-width:200px">
        <?php if (can('payments.approve')): ?>
          <button class="btn btn-green btn-sm" type="button" data-pay-bulk="approve">✅ تایید و شارژ گروهی</button>
        <?php endif; ?>
        <?php if (can('payments.reject')): ?>
          <button class="btn btn-red btn-sm" type="button" data-pay-bulk="reject">❌ رد گروهی</button>
        <?php endif; ?>
        <button class="btn btn-sm btn-ghost" type="button" id="payNone">لغو انتخاب</button>
      </div>

      <div class="pay-facts" style="margin-top:var(--s3)">
        <button class="btn btn-sm" type="button" id="payAll">☑️ انتخاب همه</button>
        <span class="hint">برای بررسی سریع، چند رسید را با هم انتخاب و یک‌جا تایید کنید.</span>
      </div>

      <div class="pay-grid">
        <?php foreach ($rows as $t):
            $isCrypto = (string)$t['method'] === 'crypto';
            $uname    = (string)($t['first_name'] ?: 'کاربر');
        ?>
          <div class="pay-card <?= $isCrypto ? 'm-crypto' : '' ?>" data-pay-card>
            <span class="strip"></span>
            <label class="pay-pick"><input type="checkbox" name="ids[]" value="<?= (int)$t['id'] ?>" data-pay-box></label>

            <div class="pay-ctop">
              <div class="pay-ava"><?= $isCrypto ? '🌐' : '💳' ?></div>
              <div class="pay-cwho">
                <div class="n"><?= h($uname) ?></div>
                <div class="s">
                  <a href="index.php?p=users&amp;u=<?= (int)$t['user_id'] ?>"><?= fa_num((string)$t['utg']) ?></a>
                  <?php if (!empty($t['username'])): ?> · @<?= h((string)$t['username']) ?><?php endif; ?>
                </div>
              </div>
              <div class="pay-camt">
                <div class="a"><?= money((float)$t['amount']) ?></div>
                <div class="t">#<?= fa_num((int)$t['id']) ?></div>
              </div>
            </div>

            <div class="pay-cbody">
              <div class="pay-line"><span class="k">روش</span><span class="v"><?= h($methodLabel((string)$t['method'])) ?></span></div>
              <div class="pay-line"><span class="k">زمان ثبت</span><span class="v"><?= h(to_jalali((string)$t['created_at'], true)) ?></span></div>
              <div class="pay-line"><span class="k">موجودی فعلی کاربر</span><span class="v"><?= money((float)$t['balance']) ?></span></div>
              <div class="pay-line"><span class="k">موجودی پس از تایید</span><span class="v" style="color:var(--green)"><?= money((float)$t['balance'] + (float)$t['amount']) ?></span></div>
              <?php if (!empty($t['txid'])): ?>
                <div class="pay-line"><span class="k">کد رهگیری</span><span class="v mono"><?= h((string)$t['txid']) ?></span></div>
              <?php endif; ?>
              <?php if (!empty($t['ref'])): ?>
                <div class="pay-line"><span class="k">مرجع</span><span class="v mono"><?= h((string)$t['ref']) ?></span></div>
              <?php endif; ?>
              <?php if (!empty($t['note'])): ?>
                <div class="pay-line"><span class="k">توضیح</span><span class="v"><?= h((string)$t['note']) ?></span></div>
              <?php endif; ?>
              <?php if (!empty($t['receipt_file'])): $ru = 'index.php?p=payments&file=' . (int)$t['id']; ?>
                <img class="pay-shot" loading="lazy" src="<?= h($ru) ?>" alt="رسید پرداخت" data-pay-zoom="<?= h($ru) ?>">
                <div class="hint" style="text-align:center">🖼 برای بزرگ‌نمایی کلیک کنید · <a href="<?= h($ru) ?>&amp;dl=1">دانلود</a></div>
              <?php endif; ?>
            </div>

            <div class="pay-cact">
              <?php if (can('payments.approve')): ?>
                <button class="btn btn-green btn-sm" type="button"
                  data-pay-one="approve" data-id="<?= (int)$t['id'] ?>">✅ تایید و شارژ</button>
              <?php endif; ?>
              <?php if (can('payments.reject')): ?>
                <button class="btn btn-red btn-sm" type="button"
                  data-pay-one="reject" data-id="<?= (int)$t['id'] ?>">❌ رد</button>
              <?php endif; ?>
              <?php if (!empty($t['txid']) && class_exists('TxCheck') && TxCheck::enabled() && can('payments.approve')): ?>
                <button class="btn btn-sm" type="button"
                  data-pay-one="recheck" data-id="<?= (int)$t['id'] ?>">🔁 بررسی هش</button>
                <button class="btn btn-primary btn-sm" type="button"
                  data-pay-one="recheck" data-apply="1" data-id="<?= (int)$t['id'] ?>">🤖 بررسی و اعمال</button>
              <?php endif; ?>
              <a class="btn btn-sm btn-ghost" href="index.php?p=users&amp;u=<?= (int)$t['user_id'] ?>">👤 پرونده</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </form>

    <!-- فرم تک‌عملیاتی -->
    <form method="post" id="payOneForm" class="hidden-form" style="display:none">
      <?= csrf_field() ?>
      <input type="hidden" name="act" id="po_act" value="">
      <input type="hidden" name="id"  id="po_id"  value="">
      <input type="hidden" name="apply" id="po_apply" value="">
      <input type="hidden" name="reason" id="po_reason" value="">
    </form>
  <?php endif; ?>

<?php
/* fixed84 ==================== tab: gateway invoices ==================== */
elseif ($tab === 'gateway'):
    [$where, $params] = $buildWhere(true, 'auto');
    $rows = DB::all("SELECT t.*, u.first_name, u.username, u.tg_id AS utg
                     FROM {p}transactions t LEFT JOIN {p}users u ON u.id = t.user_id
                     $where ORDER BY t.id DESC LIMIT 200", $params);
    $gwHours = max(1, (int)DB::setting('gw_stale_hours', '6'));
?>
  <div class="card mt3">
    <div class="card-head">
      <div><div class="card-title">🪙 فاکتورهای درگاه خودکار</div>
        <div class="card-sub">این‌ها رسید دستی نیستند و تایید مدیر لازم ندارند؛ با پرداخت کاربر، کیف پول خودکار شارژ می‌شود.</div></div>
    </div>
    <div class="hint">فاکتورهای پرداخت‌نشده پس از <?= fa_num($gwHours) ?> ساعت خودکار لغو می‌شوند. با «استعلام» وضعیت همین لحظه از درگاه گرفته می‌شود.</div>
    <?php if (!$rows): ?>
      <div class="empty"><div class="ic">🎉</div>فاکتور بازمانده‌ای از درگاه‌ها نیست.</div>
    <?php else: ?>
      <div class="table-wrap"><table class="responsive">
        <thead><tr><th>#</th><th>کاربر</th><th>درگاه</th><th>مبلغ</th><th>شناسه فاکتور</th><th>ثبت</th><th>توضیح</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $t): ?>
          <tr>
            <td class="mono"><?= fa_num((int)$t['id']) ?></td>
            <td>
              <a href="index.php?p=users&amp;u=<?= (int)$t['user_id'] ?>"><b><?= h((string)($t['first_name'] ?: 'کاربر')) ?></b></a>
              <div class="muted mono" style="font-size:11.5px"><?= fa_num((string)$t['utg']) ?></div>
            </td>
            <td style="font-size:12px"><?= h($methodLabel((string)$t['method'])) ?></td>
            <td><b><?= money((float)$t['amount']) ?></b></td>
            <td class="mono" style="font-size:11px;max-width:150px;word-break:break-all"><?= h(mb_substr((string)($t['txid'] ?? ''), 0, 28)) ?></td>
            <td class="muted" style="font-size:11.5px"><?= h(to_jalali((string)$t['created_at'], true)) ?></td>
            <td class="muted" style="font-size:11.5px"><?= h(mb_substr((string)($t['note'] ?? '—'), 0, 40)) ?></td>
            <td class="acts">
              <?php if (can('payments.approve')): ?>
                <button class="btn btn-sm btn-primary" type="button" data-pay-one="poll" data-id="<?= (int)$t['id'] ?>">🔁 استعلام</button>
              <?php endif; ?>
              <?php if (can('payments.reject')): ?>
                <button class="btn btn-sm btn-red" type="button" data-pay-one="reject" data-id="<?= (int)$t['id'] ?>">❌ لغو فاکتور</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>

      <form method="post" id="payOneForm" class="hidden-form" style="display:none">
        <?= csrf_field() ?>
        <input type="hidden" name="act" id="po_act" value="">
        <input type="hidden" name="id"  id="po_id"  value="">
        <input type="hidden" name="apply" id="po_apply" value="">
        <input type="hidden" name="reason" id="po_reason" value="">
      </form>
    <?php endif; ?>
  </div>

<?php
/* ==================== تب: همهٔ تراکنش‌ها ==================== */
elseif ($tab === 'all'):
    [$where, $params] = $buildWhere(false);
    $rows = DB::all("SELECT t.*, u.first_name, u.username, u.tg_id AS utg
                     FROM {p}transactions t LEFT JOIN {p}users u ON u.id = t.user_id
                     $where ORDER BY t.id DESC LIMIT 300", $params);
    $sumShown = 0.0;
    foreach ($rows as $r) { $sumShown += (float)$r['amount']; }
?>
  <div class="card mt3">
    <div class="card-head">
      <div><div class="card-title">🧾 تراکنش‌ها</div>
        <div class="card-sub"><?= fa_num(count($rows)) ?> ردیف · جمع نمایش‌داده‌شده: <b><?= money($sumShown) ?></b></div></div>
      <input class="mono" style="max-width:190px" type="search" placeholder="🔍 فیلتر سریع جدول" data-live-filter="#txTable tbody tr">
    </div>

    <?php if (!$rows): ?>
      <div class="empty"><div class="ic">💳</div>موردی با این فیلترها پیدا نشد.</div>
    <?php else: ?>
      <div class="table-wrap"><table id="txTable" class="responsive">
        <thead><tr><th>#</th><th>کاربر</th><th>نوع</th><th>روش</th><th>مبلغ</th><th>وضعیت</th><th>کد رهگیری</th><th>ثبت</th><th>تصمیم</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $t): ?>
          <tr>
            <td class="mono"><?= fa_num((int)$t['id']) ?></td>
            <td>
              <a href="index.php?p=users&amp;u=<?= (int)$t['user_id'] ?>"><b><?= h((string)($t['first_name'] ?: 'کاربر')) ?></b></a>
              <div class="muted mono" style="font-size:11.5px"><?= fa_num((string)$t['utg']) ?></div>
            </td>
            <td style="font-size:12px"><?= h($typeLabel((string)$t['type'])) ?></td>
            <td style="font-size:12px"><?= h($methodLabel((string)$t['method'])) ?></td>
            <td><b><?= money((float)$t['amount']) ?></b></td>
            <td><?= badge((string)$t['status']) ?></td>
            <td class="mono" style="font-size:11px;max-width:150px;word-break:break-all"><?= h(mb_substr((string)($t['txid'] ?? ''), 0, 28)) ?></td>
            <td class="muted" style="font-size:11.5px"><?= h(to_jalali((string)$t['created_at'], true)) ?></td>
            <td class="muted" style="font-size:11.5px"><?= !empty($t['decided_at']) ? h(to_jalali((string)$t['decided_at'], true)) : '—' ?></td>
            <td class="acts">
              <?php if (!empty($t['receipt_file'])): ?>
                <a class="btn btn-sm" target="_blank" href="index.php?p=payments&amp;file=<?= (int)$t['id'] ?>">🖼</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
  </div>

<?php
/* ==================== تب: سفارش‌ها ==================== */
elseif ($tab === 'orders'):
    $oq = trim((string)($_GET['q'] ?? ''));
    $ow = '';
    $op = [];
    if ($oq !== '') {
        $ow = 'WHERE (u.first_name LIKE :q OR CAST(u.tg_id AS CHAR) LIKE :q OR p.name LIKE :q OR o.discount_code LIKE :q OR CAST(o.id AS CHAR) = :qe)';
        $op = [':q' => '%' . $oq . '%', ':qe' => $oq];
    }
    $orders = DB::all("SELECT o.*, u.first_name, u.tg_id AS utg, p.name AS pname
                       FROM {p}orders o
                       LEFT JOIN {p}users u ON u.id = o.user_id
                       LEFT JOIN {p}products p ON p.id = o.product_id
                       $ow ORDER BY o.id DESC LIMIT 300", $op);

    $oNew = 0; $oRen = 0; $oSum = 0.0; $oDisc = 0.0;
    foreach ($orders as $o) {
        if ((string)$o['type'] === 'renew') { $oRen++; } else { $oNew++; }
        $oSum  += (float)$o['final_amount'];
        $oDisc += (float)$o['discount_amount'];
    }
?>
  <div class="pay-cells" style="grid-template-columns:repeat(4,minmax(0,1fr));margin-top:var(--s3)">
    <div class="pay-cell b"><span class="i">🆕</span><span class="v"><?= fa_num($oNew) ?></span><span class="l">خرید جدید</span></div>
    <div class="pay-cell c"><span class="i">♻️</span><span class="v"><?= fa_num($oRen) ?></span><span class="l">تمدید</span></div>
    <div class="pay-cell g"><span class="i">💵</span><span class="v"><?= money($oSum) ?></span><span class="l">جمع پرداختی</span></div>
    <div class="pay-cell o"><span class="i">🎟</span><span class="v"><?= money($oDisc) ?></span><span class="l">مجموع تخفیف</span></div>
  </div>

  <div class="card mt3">
    <div class="card-head">
      <div><div class="card-title">🛒 سفارش‌ها</div>
        <div class="card-sub">خرید و تمدیدهای ثبت‌شده — <?= fa_num(count($orders)) ?> ردیف اخیر</div></div>
      <form method="get" class="row" style="gap:6px">
        <input type="hidden" name="p" value="payments"><input type="hidden" name="tab" value="orders">
        <input class="mono" style="max-width:190px" type="search" name="q" value="<?= h($oq) ?>" placeholder="🔍 جستجوی سفارش">
        <button class="btn btn-sm" type="submit">جستجو</button>
      </form>
    </div>

    <?php if (!$orders): ?>
      <div class="empty"><div class="ic">🛒</div>سفارشی ثبت نشده است.</div>
    <?php else: ?>
      <div class="table-wrap"><table id="orderTable" class="responsive">
        <thead><tr><th>#</th><th>کاربر</th><th>محصول</th><th>نوع</th><th>مبلغ</th><th>تخفیف</th><th>پرداختی</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td class="mono"><?= fa_num((int)$o['id']) ?></td>
            <td>
              <a href="index.php?p=users&amp;u=<?= (int)$o['user_id'] ?>"><b><?= h((string)($o['first_name'] ?: 'کاربر')) ?></b></a>
              <div class="muted mono" style="font-size:11.5px"><?= fa_num((string)$o['utg']) ?></div>
            </td>
            <td><?= h((string)($o['pname'] ?: '—')) ?></td>
            <td><?= (string)$o['type'] === 'renew' ? '♻️ تمدید' : '🆕 خرید' ?></td>
            <td class="muted"><?= money((float)$o['amount']) ?></td>
            <td>
              <?php if ((float)$o['discount_amount'] > 0): ?>
                <span style="color:var(--orange)"><?= money((float)$o['discount_amount']) ?></span>
                <div class="mono muted" style="font-size:11px"><?= h((string)$o['discount_code']) ?></div>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><b style="color:var(--green)"><?= money((float)$o['final_amount']) ?></b></td>
            <td><?= badge((string)$o['status']) ?></td>
            <td class="muted" style="font-size:11.5px"><?= h(to_jalali((string)$o['created_at'], true)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
  </div>

<?php
/* ==================== تب: تحلیل ==================== */
else:
    $chartRaw = DB::all("SELECT DATE(decided_at) d, COALESCE(SUM(amount),0) s, COUNT(*) c
                         FROM {p}transactions
                         WHERE status = 'approved' AND type = 'deposit'
                           AND decided_at >= (CURDATE() - INTERVAL 13 DAY)
                         GROUP BY DATE(decided_at) ORDER BY d ASC");
    $byDay = [];
    foreach ($chartRaw as $r) { $byDay[(string)$r['d']] = ['s' => (float)$r['s'], 'c' => (int)$r['c']]; }

    $days = [];
    $maxS = 1.0;
    for ($i = 13; $i >= 0; $i--) {
        $key = date('Y-m-d', strtotime('-' . $i . ' day'));
        $val = $byDay[$key] ?? ['s' => 0.0, 'c' => 0];
        if ($val['s'] > $maxS) $maxS = $val['s'];
        $days[] = ['k' => $key, 's' => $val['s'], 'c' => $val['c']];
    }

    $byMethod = DB::all("SELECT method, COUNT(*) c, COALESCE(SUM(amount),0) s
                         FROM {p}transactions WHERE status = 'approved'
                         GROUP BY method ORDER BY s DESC");
    $mTotal = 0.0;
    foreach ($byMethod as $m) { $mTotal += (float)$m['s']; }
    if ($mTotal <= 0) $mTotal = 1.0;

    $top = DB::all("SELECT u.id, u.first_name, u.tg_id, COALESCE(SUM(t.amount),0) s, COUNT(*) c
                    FROM {p}transactions t JOIN {p}users u ON u.id = t.user_id
                    WHERE t.status = 'approved' AND t.type = 'deposit'
                    GROUP BY u.id, u.first_name, u.tg_id ORDER BY s DESC LIMIT 8");
?>
  <div class="card mt3">
    <div class="card-head">
      <div><div class="card-title">📈 روند شارژ ۱۴ روز اخیر</div>
        <div class="card-sub">فقط شارژهای تایید‌شدهٔ کیف پول · بیشترین روز: <b><?= money($maxS) ?></b></div></div>
    </div>
    <div class="pay-chart">
      <?php foreach ($days as $d):
          $pct = (int)round(($d['s'] / $maxS) * 100);
          if ($pct < 2 && $d['s'] > 0) $pct = 2;
      ?>
        <div class="pay-bar" title="<?= h(to_jalali($d['k'] . ' 00:00:00')) ?> — <?= h(money($d['s'])) ?> (<?= fa_num($d['c']) ?> تراکنش)">
          <span class="t"><?= $d['s'] > 0 ? fa_num((int)round($d['s'] / 1000)) . 'ه' : '' ?></span>
          <span class="b" style="height:<?= $pct ?>%"></span>
          <span class="d"><?= fa_num((int)date('j', strtotime($d['k']))) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="pay-split">
    <div class="card">
      <div class="card-head tight"><div><div class="card-title">🏆 بیشترین شارژکنندگان</div>
        <div class="card-sub">بر اساس مجموع شارژ تایید‌شده</div></div></div>
      <?php if (!$top): ?>
        <div class="empty sm"><div class="ic">🏆</div>هنوز شارژ تایید‌شده‌ای نیست.</div>
      <?php else: $i = 0; foreach ($top as $u): $i++; ?>
        <div class="pay-rank">
          <span class="no <?= $i <= 3 ? 'n' . $i : '' ?>"><?= fa_num($i) ?></span>
          <div style="flex:1;min-width:0">
            <a href="index.php?p=users&amp;u=<?= (int)$u['id'] ?>"><b><?= h((string)($u['first_name'] ?: 'کاربر')) ?></b></a>
            <div class="muted mono" style="font-size:11px"><?= fa_num((string)$u['tg_id']) ?> · <?= fa_num((int)$u['c']) ?> تراکنش</div>
          </div>
          <b style="color:var(--green);font-family:var(--font-num)"><?= money((float)$u['s']) ?></b>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="card">
      <div class="card-head tight"><div><div class="card-title">🧭 سهم روش‌های پرداخت</div>
        <div class="card-sub">از مجموع تراکنش‌های تایید‌شده</div></div></div>
      <?php if (!$byMethod): ?>
        <div class="empty sm"><div class="ic">🧭</div>داده‌ای موجود نیست.</div>
      <?php else: foreach ($byMethod as $m):
          $mk  = (string)$m['method'];
          $pct = (int)round(((float)$m['s'] / $mTotal) * 100);
      ?>
        <div class="pay-mrow">
          <span class="ic"><?= h((string)($METHODS[$mk][1] ?? '💠')) ?></span>
          <div class="nm">
            <div><?= h((string)($METHODS[$mk][0] ?? $mk)) ?> <span class="muted" style="font-size:11px">· <?= fa_num((int)$m['c']) ?> مورد</span></div>
            <div class="mb"><i style="width:<?= $pct ?>%"></i></div>
          </div>
          <span class="vl"><?= fa_num($pct) ?>٪</span>
        </div>
      <?php endforeach; endif; ?>

      <div class="alert a-info mt3" style="font-size:11.5px">
        💡 نرخ دلار فعلی <b><?= money($usdRate) ?></b> است. برای تغییر آن به «تنظیمات ← مالی» بروید.
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="pay-lb" id="payLb"><button class="x" type="button" id="payLbX">✕</button><img id="payLbImg" src="" alt="رسید"></div>

<script>
/* ===== Payments Studio v1 ===== */
(function () {
  'use strict';

  var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-pay-box]'));
  var bulk  = document.getElementById('payBulk');
  var cnt   = document.getElementById('payCnt');
  var form  = document.getElementById('payBulkForm');
  var mode  = document.getElementById('payMode');

  function faNum(n) {
    var d = '۰۱۲۳۴۵۶۷۸۹';
    return String(n).replace(/[0-9]/g, function (x) { return d[+x]; });
  }

  function sync() {
    var on = boxes.filter(function (b) { return b.checked; });
    boxes.forEach(function (b) {
      var card = b.closest('[data-pay-card]');
      if (card) card.classList.toggle('sel', b.checked);
    });
    if (!bulk) return;
    if (on.length) {
      bulk.hidden = false;
      if (cnt) cnt.textContent = faNum(on.length);
    } else {
      bulk.hidden = true;
    }
  }

  boxes.forEach(function (b) { b.addEventListener('change', sync); });

  var all = document.getElementById('payAll');
  if (all) all.addEventListener('click', function () {
    var any = boxes.some(function (b) { return !b.checked; });
    boxes.forEach(function (b) { b.checked = any; });
    sync();
  });

  var none = document.getElementById('payNone');
  if (none) none.addEventListener('click', function () {
    boxes.forEach(function (b) { b.checked = false; });
    sync();
  });

  document.querySelectorAll('[data-pay-bulk]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var m = btn.getAttribute('data-pay-bulk');
      var n = boxes.filter(function (b) { return b.checked; }).length;
      if (!n) return;
      var msg = m === 'approve'
        ? ('آیا ' + faNum(n) + ' پرداخت انتخاب‌شده تایید و کیف پول کاربران شارژ شود؟')
        : ('آیا ' + faNum(n) + ' پرداخت انتخاب‌شده رد شود؟');
      if (!window.confirm(msg)) return;
      if (mode) mode.value = m;
      if (form) form.submit();
    });
  });

  /* عملیات تکی */
  var one = document.getElementById('payOneForm');
  document.querySelectorAll('[data-pay-one]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!one) return;
      var a  = btn.getAttribute('data-pay-one');
      var id = btn.getAttribute('data-id');
      var ap = btn.getAttribute('data-apply') || '';

      if (a === 'approve' && !window.confirm('این پرداخت تایید و کیف پول کاربر شارژ شود؟')) return;

      var reason = '';
      if (a === 'reject') {
        reason = window.prompt('دلیل رد (اختیاری):', '');
        if (reason === null) return;
      }

      document.getElementById('po_act').value    = a;
      document.getElementById('po_id').value     = id;
      document.getElementById('po_apply').value  = ap;
      document.getElementById('po_reason').value = reason;
      one.submit();
    });
  });

  /* لایت‌باکس رسید */
  var lb  = document.getElementById('payLb');
  var img = document.getElementById('payLbImg');
  function close() { if (lb) lb.classList.remove('on'); }

  document.querySelectorAll('[data-pay-zoom]').forEach(function (el) {
    el.addEventListener('click', function () {
      if (!lb || !img) return;
      img.src = el.getAttribute('data-pay-zoom');
      lb.classList.add('on');
    });
  });

  var x = document.getElementById('payLbX');
  if (x) x.addEventListener('click', close);
  if (lb) lb.addEventListener('click', function (e) { if (e.target === lb) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

  /* اگر رسید بارگذاری نشد، جای خالی زشت نماند */
  document.querySelectorAll('.pay-shot').forEach(function (im) {
    im.addEventListener('error', function () {
      im.style.display = 'none';
      var hint = im.nextElementSibling;
      if (hint && hint.classList.contains('hint')) {
        hint.innerHTML = '⚠️ رسید از تلگرام قابل دریافت نیست.';
      }
    });
  });

  sync();
})();
</script>
