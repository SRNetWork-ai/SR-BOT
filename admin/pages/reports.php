<?php
/** گزارش مالی و فروش — 0.0.2 #24 */
declare(strict_types=1);

if (!can('payments.view')) { echo denyBox('بخش گزارش‌ها برای شما فعال نیست.'); return; }

$days = (int)($_GET['d'] ?? 30);
if (!in_array($days, [7, 30, 90, 365], true)) $days = 30;
$since = 'DATE_SUB(CURDATE(), INTERVAL ' . $days . ' DAY)';

$rv = static function (string $sql, array $p = []) {
    try { return DB::val($sql, $p, 0); } catch (Throwable $e) { return 0; }
};
$ra = static function (string $sql, array $p = []): array {
    try { $r = DB::all($sql, $p); return is_array($r) ? $r : []; } catch (Throwable $e) { return []; }
};

/* سری روزانه */
$series = $ra("SELECT DATE(created_at) AS d,
                      COALESCE(SUM(CASE WHEN type = 'deposit'  THEN amount  ELSE 0 END), 0) AS dep,
                      COALESCE(SUM(CASE WHEN type = 'purchase' THEN -amount ELSE 0 END), 0) AS pur
               FROM {p}transactions
               WHERE status = 'approved' AND created_at >= $since
               GROUP BY DATE(created_at) ORDER BY d ASC");

/* خروجی CSV */
if (isset($_GET['csv'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sr-report-' . $days . 'd.csv"');
    echo chr(0xEF) . chr(0xBB) . chr(0xBF) . "date,deposit,purchase\n";
    foreach ($series as $row) {
        echo (string)$row['d'] . ',' . (float)$row['dep'] . ',' . (float)$row['pur'] . "\n";
    }
    exit;
}

/* شاخص‌های کلیدی */
$sumDep  = (float)$rv("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE status = 'approved' AND type = 'deposit' AND created_at >= $since");
$sumPur  = (float)$rv("SELECT COALESCE(SUM(-amount),0) FROM {p}transactions WHERE status = 'approved' AND type = 'purchase' AND created_at >= $since");
$pendSum = (float)$rv("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE status = 'pending'");
$newUsers = (int)$rv("SELECT COUNT(*) FROM {p}users WHERE created_at >= $since");
$newSvc   = (int)$rv("SELECT COUNT(*) FROM {p}services WHERE created_at >= $since AND (is_test IS NULL OR is_test = 0)");
$newTest  = (int)$rv("SELECT COUNT(*) FROM {p}services WHERE created_at >= $since AND is_test = 1");
$payers   = (int)$rv("SELECT COUNT(DISTINCT user_id) FROM {p}transactions WHERE status = 'approved' AND type = 'deposit' AND created_at >= $since");
$arpu     = $payers > 0 ? $sumDep / $payers : 0.0;

$byType = $ra("SELECT type, COUNT(*) AS c, COALESCE(SUM(amount),0) AS s
               FROM {p}transactions WHERE status = 'approved' AND created_at >= $since
               GROUP BY type ORDER BY c DESC");

$byMethod = $ra("SELECT method, COUNT(*) AS c, COALESCE(SUM(amount),0) AS s
                 FROM {p}transactions WHERE status = 'approved' AND type = 'deposit' AND created_at >= $since
                 GROUP BY method ORDER BY s DESC");

$byOrder = $ra("SELECT status, COUNT(*) AS c, COALESCE(SUM(final_amount),0) AS s
                FROM {p}orders WHERE created_at >= $since GROUP BY status ORDER BY c DESC");

$topProd = $ra("SELECT product_id, COUNT(*) AS c, COALESCE(SUM(final_amount),0) AS s
                FROM {p}orders WHERE created_at >= $since AND product_id IS NOT NULL
                GROUP BY product_id ORDER BY s DESC LIMIT 10");

$pname = [];
foreach ($ra("SELECT * FROM {p}products") as $prow) {
    $pid = (int)($prow['id'] ?? 0);
    $pname[$pid] = (string)($prow['title'] ?? ($prow['name'] ?? ('#' . $pid)));
}

$topUser = $ra("SELECT u.id AS uid, u.first_name, u.username, u.tg_id, COUNT(*) AS c, COALESCE(SUM(t.amount),0) AS s
                FROM {p}transactions t JOIN {p}users u ON u.id = t.user_id
                WHERE t.status = 'approved' AND t.type = 'deposit' AND t.created_at >= $since
                GROUP BY u.id, u.first_name, u.username, u.tg_id ORDER BY s DESC LIMIT 10");

$maxBar = 1.0;
foreach ($series as $row) { $maxBar = max($maxBar, (float)$row['dep'], (float)$row['pur']); }

$tabs = [7 => '۷ روز', 30 => '۳۰ روز', 90 => '۹۰ روز', 365 => '۱ سال'];
?>
<div class="row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
  <div>
    <h2 style="margin:0">گزارش مالی و فروش</h2>
    <div class="muted">بازهٔ انتخابی: <?= h((string)$tabs[$days]) ?> گذشته</div>
  </div>
  <div class="row" style="gap:6px;flex-wrap:wrap">
    <?php foreach ($tabs as $dk => $dl): ?>
      <a class="btn btn-sm <?= $days === $dk ? 'btn-primary' : '' ?>" href="index.php?p=reports&amp;d=<?= (int)$dk ?>"><?= h((string)$dl) ?></a>
    <?php endforeach; ?>
    <a class="btn btn-sm btn-ghost" href="index.php?p=reports&amp;d=<?= (int)$days ?>&amp;csv=1">دریافت CSV</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-top:14px">
  <div class="card"><div class="muted">واریز تاییدشده</div><div style="font-size:19px;font-weight:700"><?= h((string)money($sumDep)) ?></div></div>
  <div class="card"><div class="muted">خرید کاربران</div><div style="font-size:19px;font-weight:700"><?= h((string)money($sumPur)) ?></div></div>
  <div class="card"><div class="muted">در انتظار تایید</div><div style="font-size:19px;font-weight:700"><?= h((string)money($pendSum)) ?></div></div>
  <div class="card"><div class="muted">میانگین خرید هر مشتری</div><div style="font-size:19px;font-weight:700"><?= h((string)money($arpu)) ?></div></div>
  <div class="card"><div class="muted">کاربر جدید</div><div style="font-size:19px;font-weight:700"><?= fa_num($newUsers) ?></div></div>
  <div class="card"><div class="muted">سرویس فروخته‌شده</div><div style="font-size:19px;font-weight:700"><?= fa_num($newSvc) ?></div></div>
  <div class="card"><div class="muted">اکانت تست</div><div style="font-size:19px;font-weight:700"><?= fa_num($newTest) ?></div></div>
  <div class="card"><div class="muted">مشتری پرداخت‌کننده</div><div style="font-size:19px;font-weight:700"><?= fa_num($payers) ?></div></div>
</div>

<div class="card mt3">
  <div class="row" style="justify-content:space-between;align-items:center">
    <b>روند درآمد روزانه</b>
    <span class="hint"><?= fa_num(count($series)) ?> روز دارای تراکنش</span>
  </div>
  <?php if (!$series): ?>
    <div class="empty" style="padding:18px">در این بازه تراکنش تاییدشده‌ای ثبت نشده است.</div>
  <?php else: ?>
    <div style="display:flex;align-items:flex-end;gap:3px;height:150px;margin-top:12px">
      <?php foreach ($series as $row): $hh = (int)round(((float)$row['dep'] / $maxBar) * 138); ?>
        <div title="<?= h((string)$row['d']) ?> - <?= h((string)money((float)$row['dep'])) ?>"
             style="flex:1;min-width:4px;height:<?= max(2, $hh) ?>px;background:linear-gradient(180deg,#38bdf8,#0284c7);border-radius:5px 5px 0 0"></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;margin-top:14px">

  <div class="card">
    <b>تراکنش‌ها بر اساس نوع</b>
    <div class="table-wrap mt3">
      <table class="responsive">
        <thead><tr><th>نوع</th><th>تعداد</th><th>مبلغ</th></tr></thead>
        <tbody>
        <?php foreach ($byType as $row): ?>
          <tr><td><?= h((string)$row['type']) ?></td><td><?= fa_num((int)$row['c']) ?></td><td><?= h((string)money((float)$row['s'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$byType): ?><tr><td colspan="3" class="muted">داده‌ای ثبت نشده است.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <b>واریز به تفکیک روش پرداخت</b>
    <div class="table-wrap mt3">
      <table class="responsive">
        <thead><tr><th>روش</th><th>تعداد</th><th>مبلغ</th></tr></thead>
        <tbody>
        <?php foreach ($byMethod as $row): ?>
          <tr><td><?= h((string)$row['method']) ?></td><td><?= fa_num((int)$row['c']) ?></td><td><?= h((string)money((float)$row['s'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$byMethod): ?><tr><td colspan="3" class="muted">داده‌ای ثبت نشده است.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <b>سفارش‌ها بر اساس وضعیت</b>
    <div class="table-wrap mt3">
      <table class="responsive">
        <thead><tr><th>وضعیت</th><th>تعداد</th><th>مبلغ نهایی</th></tr></thead>
        <tbody>
        <?php foreach ($byOrder as $row): ?>
          <tr><td><?= h((string)$row['status']) ?></td><td><?= fa_num((int)$row['c']) ?></td><td><?= h((string)money((float)$row['s'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$byOrder): ?><tr><td colspan="3" class="muted">داده‌ای ثبت نشده است.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <b>پرفروش‌ترین محصولات</b>
    <div class="table-wrap mt3">
      <table class="responsive">
        <thead><tr><th>محصول</th><th>سفارش</th><th>فروش</th></tr></thead>
        <tbody>
        <?php foreach ($topProd as $row): $pid = (int)$row['product_id']; ?>
          <tr><td><?= h((string)($pname[$pid] ?? ('#' . $pid))) ?></td><td><?= fa_num((int)$row['c']) ?></td><td><?= h((string)money((float)$row['s'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$topProd): ?><tr><td colspan="3" class="muted">داده‌ای ثبت نشده است.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <b>برترین مشتریان</b>
    <div class="table-wrap mt3">
      <table class="responsive">
        <thead><tr><th>کاربر</th><th>تعداد واریز</th><th>مجموع</th></tr></thead>
        <tbody>
        <?php foreach ($topUser as $row): ?>
          <tr>
            <td><a href="index.php?p=users&amp;u=<?= (int)$row['uid'] ?>"><?= h((string)($row['first_name'] ?: 'کاربر')) ?></a>
              <div class="muted mono" style="font-size:11.5px"><?= fa_num((string)$row['tg_id']) ?></div></td>
            <td><?= fa_num((int)$row['c']) ?></td>
            <td><?= h((string)money((float)$row['s'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$topUser): ?><tr><td colspan="3" class="muted">داده‌ای ثبت نشده است.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
