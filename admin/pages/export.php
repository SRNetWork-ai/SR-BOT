<?php
/** خروجی CSV (fixed79) — همان خروجی‌های پنل مدیریت ربات، این‌بار از پنل وب و با فیلتر بازه */
declare(strict_types=1);

if (!can('export.view')) { echo denyBox('این بخش برای شما فعال نیست.'); return; }
if (!class_exists('Export')) { echo denyBox('ماژول Export در دسترس نیست (فایل app/Service/Export.php).'); return; }

$kinds = Export::kinds();
$act   = (string)($_POST['act'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $act === 'dl') {
    if (!csrf_ok()) { flash('err', 'نشست منقضی شده است. دوباره تلاش کنید.'); back('export'); }
    $kind = (string)($_POST['kind'] ?? '');
    if (!isset($kinds[$kind])) { flash('err', 'نوع خروجی نامعتبر است.'); back('export'); }
    $opt = [
        'days'  => max(0, min(3650, pint('days', 0))),
        'limit' => max(1, min((int)Export::MAX_ROWS, pint('limit', 5000) ?: 5000)),
    ];
    if (class_exists('Audit')) { try { Audit::log('export.csv', ['kind' => $kind, 'days' => $opt['days'], 'limit' => $opt['limit']]); } catch (Throwable $e) { } }
    try {
        Export::stream($kind, $opt);
        exit;
    } catch (Throwable $e) {
        flash('err', 'خروجی ناموفق بود: ' . h($e->getMessage()));
        back('export');
    }
}

/* شمارنده‌های کوچک برای نمایش روی کارت‌ها (خطا نباید صفحه را بشکند) */
$cnt = static function (string $sql): string {
    try { return fa_num((string)(int)DB::val($sql, [], 0)); } catch (Throwable $e) { return '—'; }
};
$counts = [
    'users'        => $cnt('SELECT COUNT(*) FROM {p}users'),
    'services'     => $cnt('SELECT COUNT(*) FROM {p}services'),
    'orders'       => $cnt('SELECT COUNT(*) FROM {p}orders'),
    'transactions' => $cnt('SELECT COUNT(*) FROM {p}transactions'),
    'resellers'    => $cnt('SELECT COUNT(*) FROM {p}users WHERE COALESCE(reseller_level, 0) > 0 OR COALESCE(is_reseller, 0) = 1'),
    'expiring'     => $cnt('SELECT COUNT(*) FROM {p}services WHERE status = \'active\' AND expire_at IS NOT NULL AND expire_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)'),
];
?>
<div class="card">
  <div class="card-head"><div><div class="card-title">📤 خروجی CSV</div>
    <div class="card-sub">فایل با BOM ساخته می‌شود تا در Excel فارسی درست نمایش داده شود؛ حداکثر <?= fa_num((string)(int)Export::MAX_ROWS) ?> ردیف در هر فایل. همین خروجی‌ها از ربات (پنل مدیریت ← گزارشات) هم قابل دریافت است.</div></div></div>
</div>

<div class="grid g3 mt3">
<?php foreach ($kinds as $k => $meta): ?>
  <form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="dl">
    <input type="hidden" name="kind" value="<?= h((string)$k) ?>">
    <div class="card-head"><div class="ic"><?= h((string)$meta[0]) ?></div>
      <div><div class="card-title"><?= h((string)$meta[1]) ?></div>
        <div class="card-sub">کل رکوردها: <b><?= $counts[$k] ?? '—' ?></b></div></div></div>
    <div class="form-grid g2">
      <div class="field"><label>بازه (روز)</label>
        <input class="mono" type="number" min="0" max="3650" name="days" value="0"><div class="hint">۰ = همهٔ زمان‌ها</div></div>
      <div class="field"><label>حداکثر ردیف</label>
        <input class="mono" type="number" min="1" max="<?= (int)Export::MAX_ROWS ?>" name="limit" value="5000"></div>
    </div>
    <div class="mt3"><button class="btn btn-primary" type="submit">⬇️ دانلود CSV</button></div>
  </form>
<?php endforeach; ?>
</div>
