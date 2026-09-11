<?php
if (!can('codes.view')) { echo denyBox('این بخش برای شما فعال نیست.'); return; }
/** کد تخفیف و کد هدیه — نسخهٔ گسترده */

$canEdit = can('codes.create');
$canDel  = can('codes.delete');

/* ستون‌های اختیاری (اگر مایگریشن اجرا نشده باشد نادیده گرفته می‌شوند) */
$hasCol = function (string $t, string $c): bool {
    try { return class_exists('Migrate') ? (bool)Migrate::hasColumn($t, $c) : false; }
    catch (Throwable $e) { return false; }
};
$hasMax   = $hasCol('discount_codes', 'max_discount');
$hasStart = $hasCol('discount_codes', 'starts_at');
$hasNote  = $hasCol('discount_codes', 'note');
$hasGNote = $hasCol('gift_codes', 'note');

$mkCode = function (string $prefix): string {
    $p = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix));
    return strtoupper(Codes::generate($p !== '' ? $p : ''));
};

$act = (string)($_POST['act'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {

    /* ==================== کد تخفیف ==================== */
    if ($act === 'dc_save') { need('codes.create', 'codes');
        $id    = pint('id');
        $count = max(1, min(200, pint('count', 1)));
        $pref  = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', en_num(ptxt('prefix'))));
        $code  = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', en_num(ptxt('code'))));
        $exp   = ptxt('expires_at');
        $srt   = ptxt('starts_at');

        $base = [
            'type'       => ptxt('type') === 'amount' ? 'amount' : 'percent',
            'value'      => pflt('value'),
            'max_uses'   => pint('max_uses', -1),
            'per_user'   => pint('per_user', 1),
            'min_amount' => pflt('min_amount'),
            'product_id' => pint('product_id') ?: null,
            'expires_at' => $exp !== '' ? date('Y-m-d H:i:s', strtotime(en_num($exp)) ?: time()) : null,
            'active'     => pchk('active'),
        ];
        if ($hasMax)   $base['max_discount'] = pflt('max_discount');
        if ($hasStart) $base['starts_at']    = $srt !== '' ? date('Y-m-d H:i:s', strtotime(en_num($srt)) ?: time()) : null;
        if ($hasNote)  $base['note']         = mb_substr(ptxt('note'), 0, 180);

        if ((float)$base['value'] <= 0) { flash('err', 'مقدار تخفیف باید بزرگتر از صفر باشد.'); back('codes'); }
        if ($base['type'] === 'percent' && (float)$base['value'] > 100) $base['value'] = 100;

        if ($id) {
            if ($code === '') { flash('err', 'کد تخفیف را وارد کنید.'); back('codes'); }
            $dup = DB::one('SELECT id FROM {p}discount_codes WHERE code = :c AND id <> :id', [':c' => $code, ':id' => $id]);
            if ($dup) { flash('err', 'کد دیگری با این نام وجود دارد.'); back('codes', ['edit' => $id]); }
            $base['code'] = $code;
            DB::update('discount_codes', $base, 'id = :id', [':id' => $id]);
            flash('ok', 'کد تخفیف <span class="mono">' . h($code) . '</span> به‌روز شد.');
            back('codes');
        }

        $made = [];
        for ($i = 0; $i < $count; $i++) {
            $c = ($count === 1 && $code !== '') ? $code : $mkCode($pref !== '' ? $pref : 'OFF');
            if (DB::one('SELECT id FROM {p}discount_codes WHERE code = :c', [':c' => $c])) continue;
            $row = $base;
            $row['code'] = $c;
            $row['created_at'] = now();
            DB::insert('discount_codes', $row);
            $made[] = $c;
        }
        if (!$made) { flash('err', 'کدی ساخته نشد — احتمالاً تکراری بوده است.'); back('codes'); }
        flash('ok', fa_num(count($made)) . ' کد تخفیف ساخته شد: <span class="mono">' . h(implode(' · ', array_slice($made, 0, 20))) . '</span>');
        back('codes');
    }

    if ($act === 'dc_del') { need('codes.delete', 'codes');
        $id = pint('id');
        DB::delete('discount_uses', 'code_id = :id', [':id' => $id]);
        DB::delete('discount_codes', 'id = :id', [':id' => $id]);
        flash('ok', 'کد حذف شد.'); back('codes');
    }

    if ($act === 'dc_toggle') { need('codes.create', 'codes');
        $c = DB::one('SELECT * FROM {p}discount_codes WHERE id = :id', [':id' => pint('id')]);
        if ($c) {
            $new = (int)$c['active'] ? 0 : 1;
            DB::update('discount_codes', ['active' => $new], 'id = :id', [':id' => (int)$c['id']]);
            flash('ok', 'کد <span class="mono">' . h((string)$c['code']) . '</span> ' . ($new ? 'فعال' : 'غیرفعال') . ' شد.');
        }
        back('codes');
    }

    if ($act === 'dc_bulk') { need('codes.create', 'codes');
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
        $do  = ptxt('bulk');
        if (!$ids) { flash('err', 'هیچ کدی انتخاب نشده است.'); back('codes'); }
        $in = implode(',', $ids);
        if ($do === 'on' || $do === 'off') {
            DB::q('UPDATE {p}discount_codes SET active = ' . ($do === 'on' ? 1 : 0) . ' WHERE id IN (' . $in . ')');
            flash('ok', fa_num(count($ids)) . ' کد ' . ($do === 'on' ? 'فعال' : 'غیرفعال') . ' شد.');
        } elseif ($do === 'del') {
            if (!$canDel) { flash('err', 'دسترسی حذف ندارید.'); back('codes'); }
            DB::q('DELETE FROM {p}discount_uses WHERE code_id IN (' . $in . ')');
            DB::q('DELETE FROM {p}discount_codes WHERE id IN (' . $in . ')');
            flash('ok', fa_num(count($ids)) . ' کد حذف شد.');
        }
        back('codes');
    }

    /* ==================== کد هدیه ==================== */
    if ($act === 'gc_save') { need('codes.create', 'codes');
        $count  = max(1, min(200, pint('count', 1)));
        $amount = (int)pflt('amount');
        if ($amount <= 0) { flash('err', 'مبلغ کد هدیه را وارد کنید.'); back('codes', ['tab' => 'gift']); }
        $exp    = ptxt('expires_at');
        $pref   = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', en_num(ptxt('prefix'))));
        $manual = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', en_num(ptxt('code'))));
        $made   = [];
        for ($i = 0; $i < $count; $i++) {
            $code = ($count === 1 && $manual !== '') ? $manual : $mkCode($pref !== '' ? $pref : 'GIFT');
            if (DB::one('SELECT id FROM {p}gift_codes WHERE code = :c', [':c' => $code])) continue;
            $row = [
                'code'       => $code,
                'amount'     => $amount,
                'max_uses'   => max(1, pint('max_uses', 1)),
                'expires_at' => $exp !== '' ? date('Y-m-d H:i:s', strtotime(en_num($exp)) ?: time()) : null,
                'active'     => 1,
                'created_at' => now(),
            ];
            if ($hasGNote) $row['note'] = mb_substr(ptxt('note'), 0, 180);
            DB::insert('gift_codes', $row);
            $made[] = $code;
        }
        if (!$made) { flash('err', 'کدی ساخته نشد — احتمالاً تکراری بوده است.'); back('codes', ['tab' => 'gift']); }
        $_SESSION['cd_made'] = $made;
        flash('ok', fa_num(count($made)) . ' کد هدیه به ارزش ' . money($amount) . ' ' . currency() . ' ساخته شد.');
        back('codes', ['tab' => 'gift']);
    }

    if ($act === 'gc_del') { need('codes.delete', 'codes');
        $id = pint('id');
        DB::delete('gift_uses', 'code_id = :id', [':id' => $id]);
        DB::delete('gift_codes', 'id = :id', [':id' => $id]);
        flash('ok', 'کد هدیه حذف شد.'); back('codes', ['tab' => 'gift']);
    }

    if ($act === 'gc_toggle') { need('codes.create', 'codes');
        $c = DB::one('SELECT * FROM {p}gift_codes WHERE id = :id', [':id' => pint('id')]);
        if ($c) DB::update('gift_codes', ['active' => (int)$c['active'] ? 0 : 1], 'id = :id', [':id' => (int)$c['id']]);
        back('codes', ['tab' => 'gift']);
    }

    if ($act === 'gc_bulk') { need('codes.create', 'codes');
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
        $do  = ptxt('bulk');
        if (!$ids) { flash('err', 'هیچ کدی انتخاب نشده است.'); back('codes', ['tab' => 'gift']); }
        $in = implode(',', $ids);
        if ($do === 'on' || $do === 'off') {
            DB::q('UPDATE {p}gift_codes SET active = ' . ($do === 'on' ? 1 : 0) . ' WHERE id IN (' . $in . ')');
            flash('ok', fa_num(count($ids)) . ' کد ' . ($do === 'on' ? 'فعال' : 'غیرفعال') . ' شد.');
        } elseif ($do === 'del') {
            if (!$canDel) { flash('err', 'دسترسی حذف ندارید.'); back('codes', ['tab' => 'gift']); }
            DB::q('DELETE FROM {p}gift_uses WHERE code_id IN (' . $in . ')');
            DB::q('DELETE FROM {p}gift_codes WHERE id IN (' . $in . ')');
            flash('ok', fa_num(count($ids)) . ' کد حذف شد.');
        }
        back('codes', ['tab' => 'gift']);
    }

    /* پاک‌سازی کدهای منقضی یا تمام‌شده */
    if ($act === 'prune') { need('codes.delete', 'codes');
        $kind = ptxt('kind') === 'gift' ? 'gift' : 'discount';
        $tbl  = $kind === 'gift' ? 'gift_codes' : 'discount_codes';
        $ut   = $kind === 'gift' ? 'gift_uses' : 'discount_uses';
        $rows = DB::all('SELECT id FROM {p}' . $tbl . ' WHERE (expires_at IS NOT NULL AND expires_at < :n) OR (max_uses > 0 AND used >= max_uses)', [':n' => now()]);
        $ids  = array_map(function ($r) { return (int)$r['id']; }, $rows);
        if ($ids) {
            $in = implode(',', $ids);
            DB::q('DELETE FROM {p}' . $ut . ' WHERE code_id IN (' . $in . ')');
            DB::q('DELETE FROM {p}' . $tbl . ' WHERE id IN (' . $in . ')');
        }
        flash('ok', fa_num(count($ids)) . ' کد منقضی/تمام‌شده پاک شد.');
        back('codes', ['tab' => $kind === 'gift' ? 'gift' : 'discount']);
    }
}

/* ==================== داده‌ها ==================== */
$tab = (string)($_GET['tab'] ?? 'discount');
if (!in_array($tab, ['discount', 'gift', 'report', 'guide'], true)) $tab = 'discount';
$q  = trim((string)($_GET['q'] ?? ''));
$st = (string)($_GET['st'] ?? 'all');
if (!in_array($st, ['all', 'on', 'off', 'exp', 'full'], true)) $st = 'all';

$products = DB::all('SELECT id, name FROM {p}products ORDER BY sort ASC, id ASC');
$prodName = [];
foreach ($products as $pd) $prodName[(int)$pd['id']] = (string)$pd['name'];

$editId = (int)($_GET['edit'] ?? 0);
$e = $editId ? DB::one('SELECT * FROM {p}discount_codes WHERE id = :id', [':id' => $editId]) : null;
if (!$e) $editId = 0;
$v = function (string $k, $d = '') use ($e) { return ($e && isset($e[$k]) && $e[$k] !== null) ? $e[$k] : $d; };

$dcs = DB::all('SELECT * FROM {p}discount_codes ORDER BY id DESC LIMIT 400');
$gcs = DB::all('SELECT * FROM {p}gift_codes ORDER BY id DESC LIMIT 400');

/* وضعیت هر کد: on | off | exp | full | soon */
$stateOf = function (array $c): string {
    if ((int)($c['active'] ?? 0) !== 1) return 'off';
    if (!empty($c['expires_at']) && strtotime((string)$c['expires_at']) < time()) return 'exp';
    if ((int)($c['max_uses'] ?? 0) > 0 && (int)($c['used'] ?? 0) >= (int)$c['max_uses']) return 'full';
    if (!empty($c['starts_at']) && strtotime((string)$c['starts_at']) > time()) return 'soon';
    return 'on';
};
$stLabel = ['on' => 'فعال', 'off' => 'غیرفعال', 'exp' => 'منقضی', 'full' => 'تمام شد', 'soon' => 'در انتظار شروع'];
$stCls   = ['on' => 'b-green', 'off' => 'b-gray', 'exp' => 'b-red', 'full' => 'b-orange', 'soon' => 'b-blue'];

$filter = function (array $rows) use ($q, $st, $stateOf): array {
    $out = [];
    foreach ($rows as $r) {
        $s = $stateOf($r);
        if ($st !== 'all') {
            if ($st === 'on'   && $s !== 'on')   continue;
            if ($st === 'off'  && $s !== 'off')  continue;
            if ($st === 'exp'  && $s !== 'exp')  continue;
            if ($st === 'full' && $s !== 'full') continue;
        }
        if ($q !== '') {
            $hay = mb_strtolower((string)$r['code'] . ' ' . (string)($r['note'] ?? ''));
            if (mb_strpos($hay, mb_strtolower($q)) === false) continue;
        }
        $out[] = $r;
    }
    return $out;
};
$dcList = $filter($dcs);
$gcList = $filter($gcs);

/* ---------------- خروجی CSV ---------------- */
$ex = (string)($_GET['export'] ?? '');
if ($ex === 'discount' || $ex === 'gift') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $ex . '-codes-' . date('Ymd-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    if ($ex === 'discount') {
        fputcsv($out, ['کد', 'نوع', 'مقدار', 'سقف تخفیف', 'استفاده', 'سقف کل', 'هر کاربر', 'حداقل خرید', 'محصول', 'انقضا', 'وضعیت', 'تاریخ ساخت']);
        foreach ($dcList as $c) {
            fputcsv($out, [
                (string)$c['code'],
                (string)$c['type'] === 'percent' ? 'درصدی' : 'مبلغ ثابت',
                (float)$c['value'],
                isset($c['max_discount']) ? (float)$c['max_discount'] : 0,
                (int)$c['used'],
                (int)$c['max_uses'] < 0 ? 'نامحدود' : (int)$c['max_uses'],
                (int)$c['per_user'],
                (float)$c['min_amount'],
                $c['product_id'] ? ($prodName[(int)$c['product_id']] ?? ('#' . (int)$c['product_id'])) : 'همه',
                $c['expires_at'] ? (string)$c['expires_at'] : '-',
                $stLabel[$stateOf($c)],
                (string)$c['created_at'],
            ]);
        }
    } else {
        fputcsv($out, ['کد', 'مبلغ', 'استفاده', 'سقف', 'انقضا', 'وضعیت', 'تاریخ ساخت']);
        foreach ($gcList as $c) {
            fputcsv($out, [
                (string)$c['code'], (float)$c['amount'], (int)$c['used'], (int)$c['max_uses'],
                $c['expires_at'] ? (string)$c['expires_at'] : '-', $stLabel[$stateOf($c)], (string)$c['created_at'],
            ]);
        }
    }
    fclose($out);
    exit;
}

/* ---------------- آمار ---------------- */
$val = function (string $sql, array $p = [], $d = 0) { try { return DB::val($sql, $p, $d); } catch (Throwable $e2) { return $d; } };
$dcOn = 0; $dcOff = 0; $dcExp = 0; $dcUsed = 0;
foreach ($dcs as $c) {
    $s = $stateOf($c);
    if ($s === 'on') $dcOn++; elseif ($s === 'exp' || $s === 'full') $dcExp++; else $dcOff++;
    $dcUsed += (int)$c['used'];
}
$gcOn = 0; $gcExp = 0; $gcUsed = 0; $gcPool = 0;
foreach ($gcs as $c) {
    $s = $stateOf($c);
    if ($s === 'on') { $gcOn++; $gcPool += (int)$c['amount'] * max(0, (int)$c['max_uses'] - (int)$c['used']); }
    elseif ($s === 'exp' || $s === 'full') $gcExp++;
    $gcUsed += (int)$c['used'];
}
$giftPaid  = (int)$val('SELECT COALESCE(SUM(g.amount),0) FROM {p}gift_uses u JOIN {p}gift_codes g ON g.id = u.code_id');
$useTotal  = (int)$val('SELECT COUNT(*) FROM {p}discount_uses');
$useMonth  = (int)$val('SELECT COUNT(*) FROM {p}discount_uses WHERE created_at >= :d', [':d' => date('Y-m-d 00:00:00', strtotime('-30 days'))]);
$made      = (array)($_SESSION['cd_made'] ?? []);
unset($_SESSION['cd_made']);

$tabUrl = function (string $t) use ($q, $st): string {
    $u = 'index.php?p=codes&tab=' . $t;
    if ($q !== '')    $u .= '&q=' . urlencode($q);
    if ($st !== 'all') $u .= '&st=' . $st;
    return $u;
};
$daysLeft = function ($d): string {
    if (empty($d)) return '';
    $s = strtotime((string)$d) - time();
    if ($s < 0) return 'منقضی شده';
    $dd = (int)ceil($s / 86400);
    return $dd <= 1 ? 'امروز/فردا' : fa_num($dd) . ' روز مانده';
};
?>

<section class="cd-hero">
  <span class="cd-glow g1"></span><span class="cd-glow g2"></span>
  <div class="cd-htop">
    <div class="cd-hic">🎟</div>
    <div class="cd-htt">
      <h2>کد تخفیف و کد هدیه</h2>
      <p>ساخت کمپین تخفیف، کد هدیهٔ شارژ کیف پول و گزارش کامل مصرف</p>
    </div>
    <div class="cd-hact">
      <?php if ($canEdit): ?>
        <a class="btn btn-primary sm" href="index.php?p=codes&tab=discount#dcForm">➕ کد تخفیف</a>
        <a class="btn sm ghost" href="index.php?p=codes&tab=gift#gcForm">🎁 کد هدیه</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="cd-cells">
    <div class="cd-cell b"><span class="i">🎟</span><span class="v"><?= fa_num(count($dcs)) ?></span><span class="l">کد تخفیف</span></div>
    <div class="cd-cell g"><span class="i">✅</span><span class="v"><?= fa_num($dcOn) ?></span><span class="l">تخفیف فعال</span></div>
    <div class="cd-cell c"><span class="i">🔁</span><span class="v"><?= fa_num($useTotal) ?></span><span class="l">دفعات استفاده</span></div>
    <div class="cd-cell p"><span class="i">🎁</span><span class="v"><?= fa_num($gcOn) ?></span><span class="l">هدیهٔ فعال</span></div>
    <div class="cd-cell o"><span class="i">💰</span><span class="v"><?= money($giftPaid) ?></span><span class="l">هدیهٔ پرداخت‌شده</span></div>
    <div class="cd-cell r"><span class="i">⏳</span><span class="v"><?= fa_num($dcExp + $gcExp) ?></span><span class="l">منقضی/تمام‌شده</span></div>
  </div>
</section>

<nav class="cd-nav">
  <a class="cd-nv <?= $tab === 'discount' ? 'on' : '' ?>" href="<?= h($tabUrl('discount')) ?>">🎟 کد تخفیف <span class="n"><?= fa_num(count($dcs)) ?></span></a>
  <a class="cd-nv <?= $tab === 'gift' ? 'on' : '' ?>" href="<?= h($tabUrl('gift')) ?>">🎁 کد هدیه <span class="n"><?= fa_num(count($gcs)) ?></span></a>
  <a class="cd-nv <?= $tab === 'report' ? 'on' : '' ?>" href="index.php?p=codes&tab=report">📈 گزارش مصرف <span class="n"><?= fa_num($useTotal + $gcUsed) ?></span></a>
  <a class="cd-nv <?= $tab === 'guide' ? 'on' : '' ?>" href="index.php?p=codes&tab=guide">📘 راهنما</a>
</nav>

<?php if ($made): ?>
  <div class="cd-out">
    <div class="hd"><b>🎁 کدهای ساخته‌شده</b>
      <button class="btn xs" type="button" data-copy="<?= h(implode("\n", $made)) ?>">📋 کپی همه</button></div>
    <div class="cd-tags">
      <?php foreach ($made as $mc): ?>
        <span class="cd-tag mono ltr" data-copy="<?= h($mc) ?>"><?= h($mc) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($tab === 'discount'): ?>

  <?php if ($canEdit): ?>
  <section class="card cd-form" id="dcForm">
    <div class="card-head">
      <div>
        <div class="card-title"><?= $e ? '✏️ ویرایش کد ' . h((string)$e['code']) : '➕ کد تخفیف جدید' ?></div>
        <div class="card-sub">کاربر موقع خرید این کد را وارد می‌کند و تخفیف روی مبلغ نهایی اعمال می‌شود</div>
      </div>
      <?php if ($e): ?><a class="btn sm ghost" href="index.php?p=codes">➕ کد جدید</a><?php endif; ?>
    </div>

    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="dc_save"><input type="hidden" name="id" value="<?= (int)$editId ?>">

      <div class="cd-pick">
        <label class="cd-op <?= (string)$v('type', 'percent') === 'percent' ? 'on' : '' ?>">
          <input type="radio" name="type" value="percent" <?= (string)$v('type', 'percent') === 'percent' ? 'checked' : '' ?>>
          <span class="ic">％</span><b>تخفیف درصدی</b><i>مثلاً ۲۰٪ از مبلغ سفارش</i>
        </label>
        <label class="cd-op <?= (string)$v('type') === 'amount' ? 'on' : '' ?>">
          <input type="radio" name="type" value="amount" <?= (string)$v('type') === 'amount' ? 'checked' : '' ?>>
          <span class="ic">💵</span><b>مبلغ ثابت</b><i>مثلاً ۲۰٬۰۰۰ <?= h(currency()) ?> تخفیف</i>
        </label>
      </div>

      <div class="form-grid g2">
        <div class="field"><label>کد *</label>
          <div class="cd-gen">
            <input class="mono ltr" id="dcCode" type="text" name="code" value="<?= h((string)$v('code', $editId ? '' : $mkCode('OFF'))) ?>" placeholder="OFF-ABCD1234">
            <button class="icon-btn" type="button" id="dcDice" title="تولید خودکار">🎲</button>
          </div>
          <div class="hint">فقط حروف انگلیسی، عدد، خط تیره و زیرخط</div></div>

        <div class="field"><label>مقدار تخفیف *</label>
          <div class="cd-unit">
            <input class="mono ltr" id="dcVal" type="text" name="value" value="<?= h((string)$v('value', '10')) ?>" required>
            <span class="u" id="dcUnit"><?= (string)$v('type', 'percent') === 'percent' ? '٪' : h(currency()) ?></span>
          </div>
          <div class="hint">درصدی: عدد ۱ تا ۱۰۰ — مبلغی: عدد به <?= h(currency()) ?></div></div>
      </div>

      <?php if (!$editId): ?>
      <div class="form-grid g2">
        <div class="field"><label>تعداد کد (ساخت گروهی)</label>
          <input class="mono ltr" type="number" name="count" value="1" min="1" max="200">
          <div class="hint">بیش از یک = کدهای تصادفی ساخته می‌شوند</div></div>
        <div class="field"><label>پیشوند کدهای گروهی</label>
          <input class="mono ltr" type="text" name="prefix" value="OFF" maxlength="10"></div>
      </div>
      <?php endif; ?>

      <div class="form-grid g2">
        <div class="field"><label>سقف کل استفاده</label>
          <input class="mono ltr" type="number" name="max_uses" value="<?= h((string)$v('max_uses', -1)) ?>">
          <div class="hint">۱− = نامحدود</div></div>
        <div class="field"><label>سقف برای هر کاربر</label>
          <input class="mono ltr" type="number" name="per_user" value="<?= h((string)$v('per_user', 1)) ?>">
          <div class="hint">۰ = بی‌نهایت برای هر کاربر</div></div>
      </div>

      <div class="form-grid g2">
        <div class="field"><label>حداقل مبلغ خرید</label>
          <input class="mono ltr" type="text" name="min_amount" value="<?= h((string)$v('min_amount', '0')) ?>">
          <div class="hint">۰ = بدون محدودیت</div></div>
        <?php if ($hasMax): ?>
          <div class="field" id="dcMaxBox"><label>سقف مبلغ تخفیف (ویژهٔ درصدی)</label>
            <input class="mono ltr" type="text" name="max_discount" value="<?= h((string)$v('max_discount', '0')) ?>">
            <div class="hint">مثلاً ۲۰٪ تا سقف ۵۰٬۰۰۰ — ۰ = بدون سقف</div></div>
        <?php else: ?>
          <div class="field"><label>سقف مبلغ تخفیف</label>
            <input class="mono ltr" type="text" value="نیازمند به‌روزرسانی ساختار" disabled>
            <div class="hint">یک‌بار «تکمیل ساختار دیتابیس» را از صفحهٔ به‌روزرسانی اجرا کنید</div></div>
        <?php endif; ?>
      </div>

      <div class="form-grid g2">
        <div class="field"><label>محدود به محصول</label>
          <select name="product_id">
            <option value="0">همهٔ محصولات</option>
            <?php foreach ($products as $pd): ?>
              <option value="<?= (int)$pd['id'] ?>" <?= (int)$v('product_id') === (int)$pd['id'] ? 'selected' : '' ?>><?= h((string)$pd['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>تاریخ انقضا (میلادی)</label>
          <input class="mono ltr" type="date" name="expires_at" value="<?= $v('expires_at') ? h(date('Y-m-d', strtotime((string)$v('expires_at')))) : '' ?>">
          <div class="hint">خالی = بدون انقضا</div></div>
      </div>

      <?php if ($hasStart || $hasNote): ?>
      <div class="form-grid g2">
        <?php if ($hasStart): ?>
          <div class="field"><label>شروع اعتبار</label>
            <input class="mono ltr" type="date" name="starts_at" value="<?= $v('starts_at') ? h(date('Y-m-d', strtotime((string)$v('starts_at')))) : '' ?>">
            <div class="hint">خالی = از همین الان</div></div>
        <?php endif; ?>
        <?php if ($hasNote): ?>
          <div class="field"><label>یادداشت داخلی</label>
            <input type="text" name="note" value="<?= h((string)$v('note', '')) ?>" placeholder="مثلاً کمپین شب یلدا"></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <label class="cd-sw"><input type="checkbox" name="active" value="1" <?= (int)$v('active', 1) ? 'checked' : '' ?>>
        <span class="bx"></span><span class="tx"><b>کد فعال باشد</b><i>با خاموش کردن، کد برای کاربران رد می‌شود</i></span></label>

      <div class="btn-row mt3">
        <button class="btn btn-primary" type="submit">💾 <?= $e ? 'ذخیرهٔ تغییرات' : 'ساخت کد' ?></button>
        <?php if ($e): ?><a class="btn ghost" href="index.php?p=codes">انصراف</a><?php endif; ?>
      </div>
    </form>
  </section>
  <?php endif; ?>

  <section class="card">
    <div class="card-head">
      <div><div class="card-title">🎟 فهرست کدهای تخفیف</div>
        <div class="card-sub"><?= fa_num(count($dcList)) ?> کد از <?= fa_num(count($dcs)) ?> کد</div></div>
      <div class="btn-row">
        <a class="btn sm ghost" href="<?= h($tabUrl('discount')) ?>&export=discount">⬇️ خروجی CSV</a>
        <?php if ($canDel): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="prune"><input type="hidden" name="kind" value="discount">
            <button class="btn sm" type="submit" data-confirm="کدهای منقضی و تمام‌شده حذف شوند؟">🧹 پاک‌سازی</button></form>
        <?php endif; ?>
      </div>
    </div>

    <form method="get" class="cd-find">
      <input type="hidden" name="p" value="codes"><input type="hidden" name="tab" value="discount">
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="🔍 جستجوی کد یا یادداشت…">
      <input type="hidden" name="st" value="<?= h($st) ?>">
      <button class="btn sm" type="submit">جستجو</button>
      <?php if ($q !== ''): ?><a class="btn sm ghost" href="index.php?p=codes&tab=discount&st=<?= h($st) ?>">✕</a><?php endif; ?>
    </form>

    <div class="cd-chips">
      <?php
      $chips = ['all' => 'همه', 'on' => 'فعال', 'off' => 'غیرفعال', 'exp' => 'منقضی', 'full' => 'تمام‌شده'];
      foreach ($chips as $k => $lb):
        $u = 'index.php?p=codes&tab=discount&st=' . $k . ($q !== '' ? '&q=' . urlencode($q) : '');
      ?>
        <a class="chip <?= $st === $k ? 'on' : '' ?>" href="<?= h($u) ?>"><?= $lb ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (!$dcList): ?>
      <div class="empty"><div class="ic">🎟</div><?= $q !== '' || $st !== 'all' ? 'با این فیلتر کدی پیدا نشد.' : 'هنوز کد تخفیفی ساخته نشده است.' ?></div>
    <?php else: ?>
      <form method="post" id="dcBulkForm">
        <?= csrf_field() ?><input type="hidden" name="act" value="dc_bulk"><input type="hidden" name="bulk" id="dcBulkAct" value="">
        <?php if ($canEdit): ?>
        <div class="cd-bulk" id="dcBulk">
          <label class="check"><input type="checkbox" id="dcAll"><span>انتخاب همه</span></label>
          <span class="sp"><b id="dcCnt">۰</b> کد انتخاب شده</span>
          <div class="btn-row">
            <button class="btn xs" type="button" data-bulk="on">✅ فعال</button>
            <button class="btn xs" type="button" data-bulk="off">⏻ غیرفعال</button>
            <?php if ($canDel): ?><button class="btn xs btn-red" type="button" data-bulk="del">🗑 حذف</button><?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="tbl-wrap cd-tbl">
          <table>
            <thead><tr>
              <?php if ($canEdit): ?><th class="w1"></th><?php endif; ?>
              <th>کد</th><th>تخفیف</th><th>مصرف</th><th>محدودیت</th><th>انقضا</th><th>وضعیت</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($dcList as $c):
              $s   = $stateOf($c);
              $mx  = (int)$c['max_uses'];
              $pc  = $mx > 0 ? min(100, (int)round((int)$c['used'] * 100 / max(1, $mx))) : 0;
              $pid = (int)($c['product_id'] ?? 0);
            ?>
              <tr>
                <?php if ($canEdit): ?><td><input class="dcck" type="checkbox" name="ids[]" value="<?= (int)$c['id'] ?>"></td><?php endif; ?>
                <td>
                  <div class="cd-code">
                    <b class="mono ltr"><?= h((string)$c['code']) ?></b>
                    <button class="icon-btn xs" type="button" data-copy="<?= h((string)$c['code']) ?>" title="کپی">📋</button>
                  </div>
                  <?php if (!empty($c['note'])): ?><div class="muted xs"><?= h((string)$c['note']) ?></div><?php endif; ?>
                  <div class="muted xs"><?= $pid ? '📦 ' . h($prodName[$pid] ?? ('#' . $pid)) : '📦 همهٔ محصولات' ?></div>
                </td>
                <td>
                  <b class="cd-val"><?= (string)$c['type'] === 'percent' ? fa_num((string)(float)$c['value']) . '٪' : money((float)$c['value']) ?></b>
                  <?php if ((string)$c['type'] === 'percent' && !empty($c['max_discount'])): ?>
                    <div class="muted xs">سقف <?= money((float)$c['max_discount']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="w-bar">
                  <div class="cd-bar"><span style="width:<?= $pc ?>%"></span></div>
                  <div class="muted xs"><?= fa_num((int)$c['used']) ?> / <?= $mx < 0 ? 'نامحدود' : fa_num($mx) ?></div>
                </td>
                <td class="xs">
                  <div>👤 هر کاربر: <b><?= (int)$c['per_user'] > 0 ? fa_num((int)$c['per_user']) : 'نامحدود' ?></b></div>
                  <div class="muted">💳 حداقل: <?= (float)$c['min_amount'] > 0 ? money((float)$c['min_amount']) : '—' ?></div>
                </td>
                <td class="xs">
                  <?php if ($c['expires_at']): ?>
                    <div><?= h(to_jalali((string)$c['expires_at'])) ?></div>
                    <div class="muted"><?= h($daysLeft($c['expires_at'])) ?></div>
                  <?php else: ?><span class="muted">بدون انقضا</span><?php endif; ?>
                </td>
                <td><span class="badge <?= $stCls[$s] ?>"><?= $stLabel[$s] ?></span></td>
                <td><div class="cd-acts">
                  <?php if ($canEdit): ?>
                    <a class="icon-btn" href="index.php?p=codes&edit=<?= (int)$c['id'] ?>#dcForm" title="ویرایش">✏️</a>
                  <?php endif; ?>
                  <?php if ($canEdit): ?>
                    <button class="icon-btn" type="submit" form="dcT<?= (int)$c['id'] ?>" title="فعال/غیرفعال">⏻</button>
                  <?php endif; ?>
                  <?php if ($canDel): ?>
                    <button class="icon-btn danger" type="submit" form="dcD<?= (int)$c['id'] ?>" data-confirm="کد <?= h((string)$c['code']) ?> حذف شود؟" title="حذف">🗑</button>
                  <?php endif; ?>
                </div></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </form>

      <?php foreach ($dcList as $c): ?>
        <?php if ($canEdit): ?>
          <form class="hidden-form" id="dcT<?= (int)$c['id'] ?>" method="post"><?= csrf_field() ?>
            <input type="hidden" name="act" value="dc_toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"></form>
        <?php endif; ?>
        <?php if ($canDel): ?>
          <form class="hidden-form" id="dcD<?= (int)$c['id'] ?>" method="post"><?= csrf_field() ?>
            <input type="hidden" name="act" value="dc_del"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"></form>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

<?php endif; ?>

<?php if ($tab === 'gift'): ?>

  <?php if ($canEdit): ?>
  <section class="card cd-form" id="gcForm">
    <div class="card-head">
      <div><div class="card-title">🎁 ساخت کد هدیه</div>
        <div class="card-sub">کاربر با وارد کردن کد، کیف پولش شارژ می‌شود</div></div>
      <span class="badge b-purple">موجودی قابل خرج: <?= money($gcPool) ?></span>
    </div>

    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="gc_save">

      <div class="form-grid g2">
        <div class="field"><label>مبلغ هدیه (<?= h(currency()) ?>) *</label>
          <input class="mono ltr" id="gcAmount" type="text" name="amount" value="50000" required>
          <div class="cd-quick">
            <button class="chip" type="button" data-amt="20000">۲۰ هزار</button>
            <button class="chip" type="button" data-amt="50000">۵۰ هزار</button>
            <button class="chip" type="button" data-amt="100000">۱۰۰ هزار</button>
            <button class="chip" type="button" data-amt="200000">۲۰۰ هزار</button>
          </div></div>
        <div class="field"><label>تعداد کد</label>
          <input class="mono ltr" type="number" name="count" value="1" min="1" max="200">
          <div class="hint">تا ۲۰۰ کد یکجا — بیش از یکی = کدهای تصادفی</div></div>
      </div>

      <div class="form-grid g2">
        <div class="field"><label>سقف استفادهٔ هر کد</label>
          <input class="mono ltr" type="number" name="max_uses" value="1" min="1">
          <div class="hint">هر کاربر فقط یک‌بار می‌تواند از یک کد استفاده کند</div></div>
        <div class="field"><label>پیشوند کدها</label>
          <input class="mono ltr" type="text" name="prefix" value="GIFT" maxlength="10"></div>
      </div>

      <div class="form-grid g2">
        <div class="field"><label>کد دلخواه (فقط وقتی تعداد = ۱)</label>
          <div class="cd-gen">
            <input class="mono ltr" id="gcCode" type="text" name="code" placeholder="خالی = تولید خودکار">
            <button class="icon-btn" type="button" id="gcDice" title="تولید خودکار">🎲</button>
          </div></div>
        <div class="field"><label>تاریخ انقضا</label>
          <input class="mono ltr" type="date" name="expires_at">
          <div class="hint">خالی = بدون انقضا</div></div>
      </div>

      <?php if ($hasGNote): ?>
        <div class="field"><label>یادداشت داخلی</label>
          <input type="text" name="note" placeholder="مثلاً جبران قطعی سرور"></div>
      <?php endif; ?>

      <div class="btn-row mt3"><button class="btn btn-primary" type="submit">🎁 ساخت کد هدیه</button></div>
    </form>
  </section>
  <?php endif; ?>

  <section class="card">
    <div class="card-head">
      <div><div class="card-title">🎁 فهرست کدهای هدیه</div>
        <div class="card-sub"><?= fa_num(count($gcList)) ?> کد از <?= fa_num(count($gcs)) ?> کد · <?= fa_num($gcUsed) ?> بار استفاده شده</div></div>
      <div class="btn-row">
        <a class="btn sm ghost" href="<?= h($tabUrl('gift')) ?>&export=gift">⬇️ خروجی CSV</a>
        <?php if ($canDel): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="prune"><input type="hidden" name="kind" value="gift">
            <button class="btn sm" type="submit" data-confirm="کدهای منقضی و مصرف‌شده حذف شوند؟">🧹 پاک‌سازی</button></form>
        <?php endif; ?>
      </div>
    </div>

    <form method="get" class="cd-find">
      <input type="hidden" name="p" value="codes"><input type="hidden" name="tab" value="gift">
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="🔍 جستجوی کد…">
      <input type="hidden" name="st" value="<?= h($st) ?>">
      <button class="btn sm" type="submit">جستجو</button>
      <?php if ($q !== ''): ?><a class="btn sm ghost" href="index.php?p=codes&tab=gift&st=<?= h($st) ?>">✕</a><?php endif; ?>
    </form>

    <div class="cd-chips">
      <?php foreach (['all' => 'همه', 'on' => 'فعال', 'off' => 'غیرفعال', 'exp' => 'منقضی', 'full' => 'مصرف‌شده'] as $k => $lb):
        $u = 'index.php?p=codes&tab=gift&st=' . $k . ($q !== '' ? '&q=' . urlencode($q) : ''); ?>
        <a class="chip <?= $st === $k ? 'on' : '' ?>" href="<?= h($u) ?>"><?= $lb ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (!$gcList): ?>
      <div class="empty"><div class="ic">🎁</div><?= $q !== '' || $st !== 'all' ? 'با این فیلتر کدی پیدا نشد.' : 'هنوز کد هدیه‌ای ساخته نشده است.' ?></div>
    <?php else: ?>
      <form method="post" id="gcBulkForm">
        <?= csrf_field() ?><input type="hidden" name="act" value="gc_bulk"><input type="hidden" name="bulk" id="gcBulkAct" value="">
        <?php if ($canEdit): ?>
        <div class="cd-bulk" id="gcBulk">
          <label class="check"><input type="checkbox" id="gcAll"><span>انتخاب همه</span></label>
          <span class="sp"><b id="gcCnt">۰</b> کد انتخاب شده</span>
          <div class="btn-row">
            <button class="btn xs" type="button" data-bulk="on">✅ فعال</button>
            <button class="btn xs" type="button" data-bulk="off">⏻ غیرفعال</button>
            <?php if ($canDel): ?><button class="btn xs btn-red" type="button" data-bulk="del">🗑 حذف</button><?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="tbl-wrap cd-tbl">
          <table>
            <thead><tr>
              <?php if ($canEdit): ?><th class="w1"></th><?php endif; ?>
              <th>کد</th><th>مبلغ</th><th>مصرف</th><th>انقضا</th><th>وضعیت</th><th>ساخت</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($gcList as $c):
              $s  = $stateOf($c);
              $mx = max(1, (int)$c['max_uses']);
              $pc = min(100, (int)round((int)$c['used'] * 100 / $mx));
            ?>
              <tr>
                <?php if ($canEdit): ?><td><input class="gcck" type="checkbox" name="ids[]" value="<?= (int)$c['id'] ?>"></td><?php endif; ?>
                <td>
                  <div class="cd-code">
                    <b class="mono ltr"><?= h((string)$c['code']) ?></b>
                    <button class="icon-btn xs" type="button" data-copy="<?= h((string)$c['code']) ?>" title="کپی">📋</button>
                  </div>
                  <?php if (!empty($c['note'])): ?><div class="muted xs"><?= h((string)$c['note']) ?></div><?php endif; ?>
                </td>
                <td><b class="cd-val g"><?= money((float)$c['amount']) ?></b></td>
                <td class="w-bar">
                  <div class="cd-bar"><span class="g" style="width:<?= $pc ?>%"></span></div>
                  <div class="muted xs"><?= fa_num((int)$c['used']) ?> / <?= fa_num((int)$c['max_uses']) ?></div>
                </td>
                <td class="xs">
                  <?php if ($c['expires_at']): ?>
                    <div><?= h(to_jalali((string)$c['expires_at'])) ?></div>
                    <div class="muted"><?= h($daysLeft($c['expires_at'])) ?></div>
                  <?php else: ?><span class="muted">بدون انقضا</span><?php endif; ?>
                </td>
                <td><span class="badge <?= $stCls[$s] ?>"><?= $stLabel[$s] ?></span></td>
                <td class="muted xs"><?= h(to_jalali((string)$c['created_at'])) ?></td>
                <td><div class="cd-acts">
                  <?php if ($canEdit): ?>
                    <button class="icon-btn" type="submit" form="gcT<?= (int)$c['id'] ?>" title="فعال/غیرفعال">⏻</button>
                  <?php endif; ?>
                  <?php if ($canDel): ?>
                    <button class="icon-btn danger" type="submit" form="gcD<?= (int)$c['id'] ?>" data-confirm="کد <?= h((string)$c['code']) ?> حذف شود؟" title="حذف">🗑</button>
                  <?php endif; ?>
                </div></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </form>

      <?php foreach ($gcList as $c): ?>
        <?php if ($canEdit): ?>
          <form class="hidden-form" id="gcT<?= (int)$c['id'] ?>" method="post"><?= csrf_field() ?>
            <input type="hidden" name="act" value="gc_toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"></form>
        <?php endif; ?>
        <?php if ($canDel): ?>
          <form class="hidden-form" id="gcD<?= (int)$c['id'] ?>" method="post"><?= csrf_field() ?>
            <input type="hidden" name="act" value="gc_del"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"></form>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

<?php endif; ?>

<?php if ($tab === 'report'):
  $from = date('Y-m-d 00:00:00', strtotime('-13 days'));
  $rowsD = DB::all('SELECT DATE(created_at) d, COUNT(*) c FROM {p}discount_uses WHERE created_at >= :f GROUP BY DATE(created_at)', [':f' => $from]);
  $rowsG = DB::all('SELECT DATE(created_at) d, COUNT(*) c FROM {p}gift_uses WHERE created_at >= :f GROUP BY DATE(created_at)', [':f' => $from]);
  $mapD = []; foreach ($rowsD as $r) $mapD[(string)$r['d']] = (int)$r['c'];
  $mapG = []; foreach ($rowsG as $r) $mapG[(string)$r['d']] = (int)$r['c'];
  $days = [];
  for ($i = 13; $i >= 0; $i--) {
      $k = date('Y-m-d', strtotime('-' . $i . ' days'));
      $days[] = ['k' => $k, 'd' => (int)($mapD[$k] ?? 0), 'g' => (int)($mapG[$k] ?? 0)];
  }
  $peak = 1;
  foreach ($days as $d) $peak = max($peak, $d['d'] + $d['g']);

  $top = DB::all('SELECT c.code, c.type, c.value, COUNT(u.id) cnt, MAX(u.created_at) last_at
                  FROM {p}discount_uses u JOIN {p}discount_codes c ON c.id = u.code_id
                  GROUP BY u.code_id, c.code, c.type, c.value ORDER BY cnt DESC LIMIT 10');
  $feedD = DB::all('SELECT u.created_at, u.order_id, c.code, us.first_name, us.tg_id
                    FROM {p}discount_uses u LEFT JOIN {p}discount_codes c ON c.id = u.code_id
                    LEFT JOIN {p}users us ON us.id = u.user_id ORDER BY u.id DESC LIMIT 25');
  $feedG = DB::all('SELECT u.created_at, g.code, g.amount, us.first_name, us.tg_id
                    FROM {p}gift_uses u LEFT JOIN {p}gift_codes g ON g.id = u.code_id
                    LEFT JOIN {p}users us ON us.id = u.user_id ORDER BY u.id DESC LIMIT 25');
?>

  <div class="cd-two">
    <section class="card">
      <div class="card-head"><div><div class="card-title">📈 مصرف ۱۴ روز گذشته</div>
        <div class="card-sub">تخفیف <?= fa_num($useMonth) ?> بار در ۳۰ روز اخیر</div></div>
        <div class="cd-lg"><span class="k b"></span>تخفیف <span class="k p"></span>هدیه</div></div>
      <div class="cd-chart">
        <?php foreach ($days as $d): $tt = $d['d'] + $d['g']; ?>
          <div class="cl" title="<?= h(to_jalali($d['k'])) ?> — تخفیف <?= fa_num($d['d']) ?> · هدیه <?= fa_num($d['g']) ?>">
            <div class="bars">
              <span class="b p" style="height:<?= (int)round($d['g'] * 100 / $peak) ?>%"></span>
              <span class="b" style="height:<?= (int)round($d['d'] * 100 / $peak) ?>%"></span>
            </div>
            <i><?= $tt > 0 ? fa_num($tt) : '' ?></i>
            <em><?= fa_num((int)date('j', strtotime($d['k']))) ?></em>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><div><div class="card-title">🏆 پراستفاده‌ترین کدها</div>
        <div class="card-sub">براساس تعداد دفعات استفاده</div></div></div>
      <?php if (!$top): ?>
        <div class="empty sm">هنوز از هیچ کد تخفیفی استفاده نشده است.</div>
      <?php else: $mx = max(1, (int)$top[0]['cnt']); ?>
        <div class="cd-top">
          <?php foreach ($top as $i => $t): ?>
            <div class="rk">
              <span class="no"><?= fa_num($i + 1) ?></span>
              <div class="bd">
                <div class="t1"><b class="mono ltr"><?= h((string)$t['code']) ?></b>
                  <span class="badge b-blue"><?= (string)$t['type'] === 'percent' ? fa_num((string)(float)$t['value']) . '٪' : money((float)$t['value']) ?></span></div>
                <div class="pb"><span style="width:<?= (int)round((int)$t['cnt'] * 100 / $mx) ?>%"></span></div>
                <div class="t2"><span><?= fa_num((int)$t['cnt']) ?> بار</span>
                  <span class="muted">آخرین: <?= h(to_jalali((string)$t['last_at'])) ?></span></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <div class="cd-two">
    <section class="card">
      <div class="card-head"><div><div class="card-title">🎟 آخرین استفاده‌های تخفیف</div>
        <div class="card-sub">۲۵ مورد آخر</div></div></div>
      <?php if (!$feedD): ?><div class="empty sm">موردی ثبت نشده است.</div><?php else: ?>
        <div class="cd-feed">
          <?php foreach ($feedD as $f): ?>
            <div class="fi">
              <span class="ic">🎟</span>
              <div class="bd">
                <div class="t1"><b class="mono ltr"><?= h((string)($f['code'] ?? '—')) ?></b>
                  <?php if (!empty($f['order_id'])): ?><span class="badge b-gray">سفارش #<?= fa_num((int)$f['order_id']) ?></span><?php endif; ?></div>
                <div class="t2"><span>👤 <?= h(trim((string)($f['first_name'] ?? '')) !== '' ? (string)$f['first_name'] : 'کاربر') ?></span>
                  <span class="mono ltr muted"><?= h((string)($f['tg_id'] ?? '')) ?></span></div>
              </div>
              <span class="dt"><?= h(to_jalali((string)$f['created_at'], true)) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><div><div class="card-title">🎁 آخرین کدهای هدیهٔ مصرف‌شده</div>
        <div class="card-sub">جمع پرداختی: <?= money($giftPaid) ?> <?= h(currency()) ?></div></div></div>
      <?php if (!$feedG): ?><div class="empty sm">موردی ثبت نشده است.</div><?php else: ?>
        <div class="cd-feed">
          <?php foreach ($feedG as $f): ?>
            <div class="fi g">
              <span class="ic">🎁</span>
              <div class="bd">
                <div class="t1"><b class="mono ltr"><?= h((string)($f['code'] ?? '—')) ?></b>
                  <span class="badge b-green">+<?= money((float)($f['amount'] ?? 0)) ?></span></div>
                <div class="t2"><span>👤 <?= h(trim((string)($f['first_name'] ?? '')) !== '' ? (string)$f['first_name'] : 'کاربر') ?></span>
                  <span class="mono ltr muted"><?= h((string)($f['tg_id'] ?? '')) ?></span></div>
              </div>
              <span class="dt"><?= h(to_jalali((string)$f['created_at'], true)) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>

<?php endif; ?>

<?php if ($tab === 'guide'): ?>
  <div class="cd-two">
    <section class="card">
      <div class="card-head"><div><div class="card-title">🎟 کد تخفیف چطور کار می‌کند؟</div></div></div>
      <div class="cd-steps">
        <div class="s"><span class="n">۱</span><div><b>ساخت کد</b><i>نوع (درصدی/مبلغی)، مقدار، سقف استفاده و تاریخ انقضا را مشخص کنید.</i></div></div>
        <div class="s"><span class="n">۲</span><div><b>ارسال به کاربر</b><i>کد را در کانال یا پیام همگانی منتشر کنید.</i></div></div>
        <div class="s"><span class="n">۳</span><div><b>ورود در ربات</b><i>کاربر در صفحهٔ خرید گزینهٔ «کد تخفیف» را می‌زند و کد را می‌فرستد.</i></div></div>
        <div class="s"><span class="n">۴</span><div><b>اعمال خودکار</b><i>تخفیف روی مبلغ نهایی اعمال و در گزارش مصرف ثبت می‌شود.</i></div></div>
      </div>
      <div class="cd-tips">
        <b>💡 نکته‌ها</b>
        <ul>
          <li>سقف «هر کاربر» جلوی استفادهٔ تکراری را می‌گیرد؛ ۱ یعنی فقط یک‌بار.</li>
          <li>برای کمپین تک‌نفره از ساخت گروهی با پیشوند اختصاصی استفاده کنید.</li>
          <li>محدود کردن کد به یک محصول، برای تخفیف روی پلن‌های کم‌فروش عالی است.</li>
          <li>خروجی CSV را برای تحویل کدها به همکاران بازاریابی بگیرید.</li>
        </ul>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><div><div class="card-title">🎁 کد هدیه چطور کار می‌کند؟</div></div></div>
      <div class="cd-steps">
        <div class="s"><span class="n">۱</span><div><b>مبلغ و تعداد</b><i>مبلغ هدیه و تعداد کد را مشخص کنید (تا ۲۰۰ کد یکجا).</i></div></div>
        <div class="s"><span class="n">۲</span><div><b>مصرف توسط کاربر</b><i>کاربر در ربات یا مینی‌اپ کد هدیه را وارد می‌کند.</i></div></div>
        <div class="s"><span class="n">۳</span><div><b>شارژ کیف پول</b><i>مبلغ بلافاصله به کیف پول اضافه و در تراکنش‌ها ثبت می‌شود.</i></div></div>
        <div class="s"><span class="n">۴</span><div><b>کنترل هزینه</b><i>«موجودی قابل خرج» مجموع مبلغی است که هنوز مصرف نشده است.</i></div></div>
      </div>
      <div class="cd-tips warn">
        <b>⚠️ مراقب باشید</b>
        <ul>
          <li>کد هدیه معادل پول نقد است؛ پیش از انتشار عمومی سقف استفاده را ۱ بگذارید.</li>
          <li>برای کمپین عمومی، تاریخ انقضای کوتاه تعیین کنید.</li>
          <li>کدهای منقضی را با دکمهٔ پاک‌سازی حذف کنید تا فهرست تمیز بماند.</li>
        </ul>
      </div>
    </section>
  </div>
<?php endif; ?>

<script>
(function () {
  var alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ', digits = '23456789';
  function gen(prefix) {
    var s = '';
    for (var i = 0; i < 4; i++) s += alpha[Math.floor(Math.random() * alpha.length)];
    for (var j = 0; j < 4; j++) s += digits[Math.floor(Math.random() * digits.length)];
    return (prefix ? prefix + '-' : '') + s;
  }
  var dice = function (btnId, inpId, pre) {
    var b = document.getElementById(btnId), i = document.getElementById(inpId);
    if (b && i) b.addEventListener('click', function () { i.value = gen(pre); i.focus(); });
  };
  dice('dcDice', 'dcCode', 'OFF');
  dice('gcDice', 'gcCode', 'GIFT');

  // نوع تخفیف: تغییر واحد و نمایش سقف
  var ops = document.querySelectorAll('.cd-op input[name="type"]');
  var unit = document.getElementById('dcUnit'), maxBox = document.getElementById('dcMaxBox');
  function syncType() {
    var pct = true;
    ops.forEach(function (o) {
      o.closest('.cd-op').classList.toggle('on', o.checked);
      if (o.checked) pct = o.value === 'percent';
    });
    if (unit) unit.textContent = pct ? '٪' : unit.getAttribute('data-cur') || unit.textContent;
    if (maxBox) maxBox.style.opacity = pct ? '1' : '.45';
  }
  if (unit) unit.setAttribute('data-cur', unit.textContent);
  ops.forEach(function (o) { o.addEventListener('change', syncType); });
  if (ops.length) syncType();

  // مبالغ سریع کد هدیه
  document.querySelectorAll('.cd-quick [data-amt]').forEach(function (b) {
    b.addEventListener('click', function () {
      var i = document.getElementById('gcAmount');
      if (i) { i.value = b.getAttribute('data-amt'); i.focus(); }
    });
  });

  // انتخاب گروهی
  function bulk(formId, ckCls, allId, cntId, barId, actId) {
    var form = document.getElementById(formId);
    if (!form) return;
    var cks = form.querySelectorAll('.' + ckCls),
        all = document.getElementById(allId),
        cnt = document.getElementById(cntId),
        bar = document.getElementById(barId),
        act = document.getElementById(actId);
    function sync() {
      var n = form.querySelectorAll('.' + ckCls + ':checked').length;
      if (cnt) cnt.textContent = window.faDigits ? window.faDigits(String(n)) : String(n);
      if (bar) bar.classList.toggle('has', n > 0);
      cks.forEach(function (c) { var tr = c.closest('tr'); if (tr) tr.classList.toggle('sel', c.checked); });
    }
    cks.forEach(function (c) { c.addEventListener('change', sync); });
    if (all) all.addEventListener('change', function () {
      cks.forEach(function (c) { c.checked = all.checked; });
      sync();
    });
    if (bar) bar.querySelectorAll('[data-bulk]').forEach(function (b) {
      b.addEventListener('click', function () {
        var n = form.querySelectorAll('.' + ckCls + ':checked').length;
        if (!n) { alert('ابتدا کدها را انتخاب کنید.'); return; }
        var kind = b.getAttribute('data-bulk');
        if (kind === 'del' && !confirm(n + ' کد حذف شود؟')) return;
        if (act) act.value = kind;
        form.submit();
      });
    });
    sync();
  }
  bulk('dcBulkForm', 'dcck', 'dcAll', 'dcCnt', 'dcBulk', 'dcBulkAct');
  bulk('gcBulkForm', 'gcck', 'gcAll', 'gcCnt', 'gcBulk', 'gcBulkAct');

  // کپی با کلیک روی تگ کدهای ساخته‌شده
  document.querySelectorAll('.cd-tag[data-copy]').forEach(function (t) {
    t.addEventListener('click', function () {
      t.classList.add('cp');
      setTimeout(function () { t.classList.remove('cp'); }, 900);
    });
  });
})();
</script>
