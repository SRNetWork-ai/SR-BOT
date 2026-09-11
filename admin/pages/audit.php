<?php
if (!can('audit.view')) { echo denyBox('این بخش برای شما فعال نیست.'); return; }
/** لاگ اقدامات مدیران (fixed75) — کی؟ چه کاری؟ کی؟ با چه جزئیاتی؟ */

$act = (string)($_POST['act'] ?? '');
if ($act !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('err', 'نشست منقضی شده است. دوباره تلاش کنید.'); back('audit'); }

    if ($act === 'prune') {
        if (!isSuper()) { flash('err', 'فقط مدیر کل می‌تواند لاگ‌ها را پاک کند.'); back('audit'); }
        $days = max(1, min(3650, pint('days', 90)));
        $n = Audit::prune($days);
        Audit::log('audit.prune', ['days' => $days, 'deleted' => $n]);
        flash('ok', '🧽 ' . fa_num($n) . ' رکورد قدیمی‌تر از ' . fa_num($days) . ' روز پاک شد.');
        back('audit');
    }

    if ($act === 'keep') {
        if (!isSuper()) { flash('err', 'فقط مدیر کل می‌تواند مدت نگهداری را تغییر دهد.'); back('audit'); }
        $keep = max(7, min(3650, pint('keep', 90)));
        DB::setSetting('logs_keep_days', (string)$keep);
        flash('ok', '✅ مدت نگهداری لاگ‌ها روی ' . fa_num($keep) . ' روز تنظیم شد (کرون قدیمی‌ترها را خودکار پاک می‌کند).');
        back('audit');
    }
}

/* ---------- فیلترها ---------- */
$fActor = trim((string)($_GET['actor'] ?? ''));
$fQ     = trim((string)($_GET['q'] ?? ''));
$fDays  = max(0, min(3650, (int)preg_replace('/[^0-9]/', '', en_num((string)($_GET['days'] ?? '7')))));
$pg     = max(1, (int)($_GET['pg'] ?? 1));
$per    = 50;

$total = Audit::count($fActor, $fQ, $fDays);
$pages = max(1, (int)ceil($total / $per));
if ($pg > $pages) $pg = $pages;
$rows   = Audit::recent($per, $fActor, $fQ, $fDays, $pg);
$actors = Audit::actors(30);
$st     = Audit::stats();
$keep   = (int)DB::setting('logs_keep_days', '90');

$qs = function (array $over = []) use ($fActor, $fQ, $fDays, $pg): string {
    $q = array_merge(['p' => 'audit', 'actor' => $fActor, 'q' => $fQ, 'days' => $fDays, 'pg' => $pg], $over);
    return 'index.php?' . http_build_query(array_filter($q, static fn($v) => $v !== '' && $v !== null));
};
?>
<div class="grid g3">
  <div class="card">
    <div class="card-head"><div class="ic">📝</div><div><div class="card-title">امروز</div><div class="card-sub">اقدام مدیریتی ثبت‌شده</div></div></div>
    <div class="num tight"><?= fa_num($st['today']) ?></div>
  </div>
  <div class="card">
    <div class="card-head"><div class="ic">🗓</div><div><div class="card-title">۷ روز اخیر</div><div class="card-sub">مجموع کل: <?= fa_num($st['total']) ?></div></div></div>
    <div class="num tight"><?= fa_num($st['week']) ?></div>
  </div>
  <div class="card">
    <div class="card-head"><div class="ic">🏆</div><div><div class="card-title">فعال‌ترین مدیر (۷ روز)</div><div class="card-sub"><?= $st['top'] !== '' ? h(Audit::actorLabel($st['top'])) : '—' ?></div></div></div>
    <div class="num tight"><?= fa_num($st['top_n']) ?></div>
  </div>
</div>

<div class="card">
  <div class="card-head"><div class="ic">🔎</div><div><div class="card-title">فیلتر</div><div class="card-sub">همهٔ اقدامات پنل وب، منوی مدیریت ربات و کرون اینجا ثبت می‌شود</div></div></div>
  <form method="get" class="grid g4" style="align-items:end">
    <input type="hidden" name="p" value="audit">
    <label class="field"><span>عامل</span>
      <select name="actor">
        <option value="">همه</option>
        <?php foreach ($actors as $a): ?>
          <option value="<?= h((string)$a['actor']) ?>" <?= $fActor === (string)$a['actor'] ? 'selected' : '' ?>><?= h(Audit::actorLabel((string)$a['actor'])) ?> (<?= fa_num((int)$a['cnt']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span>جستجو (اقدام / جزئیات)</span><input type="text" name="q" value="<?= h($fQ) ?>" placeholder="مثلاي user.ban یا شناسهٔ کاربر"></label>
    <label class="field"><span>بازهٔ زمانی</span>
      <select name="days">
        <?php foreach ([1 => 'امروز (۲۴ ساعت)', 7 => '۷ روز', 30 => '۳۰ روز', 90 => '۹۰ روز', 0 => 'همه'] as $d => $lbl): ?>
          <option value="<?= $d ?>" <?= $fDays === $d ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="field"><button class="btn">اعمال فیلتر</button> <a class="btn btn-ghost" href="index.php?p=audit">پاک‌کردن</a></div>
  </form>
</div>

<div class="card">
  <div class="card-head"><div class="ic">📋</div><div><div class="card-title">فهرست اقدامات</div><div class="card-sub"><?= fa_num($total) ?> رکورد • صفحهٔ <?= fa_num($pg) ?> از <?= fa_num($pages) ?></div></div></div>
  <?php if (!$rows): ?>
    <p class="muted">رکوردی با این فیلتر پیدا نشد.</p>
  <?php else: ?>
  <div class="table-wrap"><table class="responsive">
    <thead><tr><th>#</th><th>زمان</th><th>عامل</th><th>اقدام</th><th>جزئیات</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $meta = jdec((string)($r['meta'] ?? ''), []);
        $res  = is_array($meta) ? (string)($meta['result'] ?? '') : '';
        $chip = $res === 'ok' ? 'chip on' : ($res === 'err' ? 'chip" style="color:#e5484d;border-color:#e5484d' : ($res === 'warn' ? 'chip" style="color:#f5a524;border-color:#f5a524' : ''));
        $ip   = is_array($meta) ? (string)($meta['ip'] ?? '') : '';
    ?>
      <tr>
        <td data-label="#" class="mono"><?= (int)$r['id'] ?></td>
        <td data-label="زمان" class="mono"><?= h(to_jalali((string)$r['created_at'], true)) ?></td>
        <td data-label="عامل"><span class="chip"><?= h(Audit::actorLabel((string)$r['actor'])) ?></span><?= $ip !== '' ? '<div class="mono muted" style="font-size:11px">' . h($ip) . '</div>' : '' ?></td>
        <td data-label="اقدام"><b><?= h(Audit::label((string)$r['action'])) ?></b><?= $chip !== '' ? ' <span class="' . $chip . '">' . ($res === 'ok' ? 'موفق' : ($res === 'err' ? 'خطا' : 'هشدار')) . '</span>' : '' ?><div class="mono muted" style="font-size:11px"><?= h((string)$r['action']) ?></div></td>
        <td data-label="جزئیات" class="mono" style="font-size:12px;direction:ltr;text-align:left;max-width:520px;white-space:normal;word-break:break-all"><?= h(Audit::metaLine((string)($r['meta'] ?? ''), 220)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?>
    <div style="display:flex;gap:8px;justify-content:center;margin-top:12px;flex-wrap:wrap">
      <?php if ($pg > 1): ?><a class="btn btn-sm btn-ghost" href="<?= h($qs(['pg' => $pg - 1])) ?>">▶ قبلی</a><?php endif; ?>
      <span class="chip"><?= fa_num($pg) ?> / <?= fa_num($pages) ?></span>
      <?php if ($pg < $pages): ?><a class="btn btn-sm btn-ghost" href="<?= h($qs(['pg' => $pg + 1])) ?>">بعدی ◀</a><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php if (isSuper()): ?>
<div class="grid g2">
  <div class="card">
    <div class="card-head"><div class="ic">⏳</div><div><div class="card-title">مدت نگهداری</div><div class="card-sub">کرون روزانه رکوردهای قدیمی‌تر را حذف می‌کند</div></div></div>
    <form method="post" class="grid g2" style="align-items:end"><?= csrf_field() ?><input type="hidden" name="act" value="keep">
      <label class="field"><span>نگهداری (روز)</span><input type="number" name="keep" min="7" max="3650" value="<?= $keep ?>"></label>
      <div class="field"><button class="btn">ذخیره</button></div>
    </form>
  </div>
  <div class="card">
    <div class="card-head"><div class="ic">🧽</div><div><div class="card-title">پاکسازی دستی</div><div class="card-sub">حذف رکوردهای قدیمی‌تر از N روز (غیرقابل بازگشت)</div></div></div>
    <form method="post" class="grid g2" style="align-items:end" onsubmit="return confirm('لاگ‌های قدیمی پاک شوند؟')"><?= csrf_field() ?><input type="hidden" name="act" value="prune">
      <label class="field"><span>قدیمی‌تر از (روز)</span><input type="number" name="days" min="1" max="3650" value="90"></label>
      <div class="field"><button class="btn btn-danger">پاک کن</button></div>
    </form>
  </div>
</div>
<?php endif; ?>
