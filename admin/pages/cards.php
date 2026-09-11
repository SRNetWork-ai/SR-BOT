<?php
if (!can('cards.view')) { echo denyBox('این بخش برای شما فعال نیست.'); return; }
/**
 * احراز کارت بانکی — نسخهٔ گسترده
 * کاربر پیش از واریز کارت‌به‌کارت شماره‌کارت خودش را ثبت می‌کند و مدیر تایید می‌کند.
 * هیچ داده‌ی حساسی (CVV2 / رمز دوم / رمز پویا / تاریخ انقضا) درخواست یا ذخیره نمی‌شود.
 */

$tab = (string)($_GET['tab'] ?? 'req');
if (!in_array($tab, ['req', 'list', 'bank', 'set'], true)) $tab = 'req';

$q   = trim((string)($_GET['q'] ?? ''));
$fst = (string)($_GET['st'] ?? '');
if (!in_array($fst, CardAuth::ST, true)) $fst = '';
$fbank = trim((string)($_GET['bank'] ?? ''));

/* ---------------- نمایش تصویر کارت (فایل‌ها بیرون از دسترس وب هستند) ---------------- */
$phName = basename(trim((string)($_GET['photo'] ?? '')));
if ($phName !== '') {
    $ppath = CardAuth::photoPath($phName);
    if ($ppath === '') {
        flash('err', 'تصویر کارت یافت نشد.');
        back('cards', ['tab' => $tab]);
    }
    $pext = strtolower((string)pathinfo($ppath, PATHINFO_EXTENSION));
    $pmim = $pext === 'png' ? 'image/png' : ($pext === 'webp' ? 'image/webp' : 'image/jpeg');
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $pmim);
    header('Content-Length: ' . (string)@filesize($ppath));
    header('Cache-Control: private, max-age=300');
    header('Content-Disposition: inline; filename="' . $phName . '"');
    readfile($ppath);
    exit;
}

/* ---------------- خروجی CSV ---------------- */
if ((string)($_GET['export'] ?? '') === '1') {
    $ex = CardAuth::search(['q' => $q, 'status' => $fst, 'limit' => 500]);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cards-' . date('Ymd-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['کد', 'کاربر', 'آیدی تلگرام', 'شماره کارت', 'بانک', 'صاحب کارت', 'شبا', 'وضعیت', 'تعداد واریز', 'تاریخ ثبت']);
    foreach ($ex as $c) {
        if ($fbank !== '' && (string)($c['bank'] ?? '') !== $fbank) continue;
        fputcsv($out, [
            (int)$c['id'],
            trim((string)($c['first_name'] ?? '')),
            (string)$c['tg_id'],
            "'" . (string)$c['pan'],
            (string)($c['bank'] ?? ''),
            (string)($c['holder'] ?? ''),
            (string)($c['sheba'] ?? ''),
            CardAuth::label((string)$c['status']),
            (int)($c['uses'] ?? 0),
            to_jalali((string)$c['created_at']),
        ]);
    }
    fclose($out);
    exit;
}

$act = (string)($_POST['act'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $id = pint('id');
    $rt = ['tab' => (string)($_POST['rt'] ?? $tab)];

    if ($act === 'approve' && $id) {
        need('cards.approve', 'cards');
        $ok = CardAuth::approve($id, (int)$ADMIN['id']);
        flash($ok ? 'ok' : 'err', $ok ? '✅ کارت تایید شد و به کاربر اطلاع داده شد.' : 'تایید ناموفق بود.');
        back('cards', $rt);
    }

    if ($act === 'reject' && $id) {
        need('cards.reject', 'cards');
        $ok = CardAuth::reject($id, (int)$ADMIN['id'], ptxt('reason'));
        flash($ok ? 'ok' : 'err', $ok ? '⛔️ کارت رد شد و به کاربر اطلاع داده شد.' : 'عملیات ناموفق بود.');
        back('cards', $rt);
    }

    if ($act === 'del' && $id) {
        need('cards.delete', 'cards');
        $ok = CardAuth::remove($id);
        flash($ok ? 'ok' : 'err', $ok ? 'کارت حذف شد.' : 'حذف ناموفق بود.');
        back('cards', $rt);
    }

    if ($act === 'approve_all') {
        need('cards.approve', 'cards');
        $n = 0;
        foreach (CardAuth::pending(300) as $c) {
            if (CardAuth::approve((int)$c['id'], (int)$ADMIN['id'])) $n++;
        }
        flash($n ? 'ok' : 'err', $n ? '✅ ' . fa_num($n) . ' کارت تایید شد.' : 'کارتی در انتظار نبود.');
        back('cards', $rt);
    }

    /* عملیات گروهی روی کارت‌های انتخاب‌شده */
    if ($act === 'bulk') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
        $do  = (string)($_POST['do'] ?? '');
        if (!$ids) {
            flash('err', 'هیچ کارتی انتخاب نشده بود.');
            back('cards', $rt);
        }
        $n = 0;
        if ($do === 'approve') {
            need('cards.approve', 'cards');
            foreach ($ids as $i) { if (CardAuth::approve($i, (int)$ADMIN['id'])) $n++; }
            flash($n ? 'ok' : 'err', $n ? '✅ ' . fa_num($n) . ' کارت تایید شد.' : 'عملیات ناموفق بود.');
        } elseif ($do === 'reject') {
            need('cards.reject', 'cards');
            $rs = ptxt('reason');
            foreach ($ids as $i) { if (CardAuth::reject($i, (int)$ADMIN['id'], $rs)) $n++; }
            flash($n ? 'ok' : 'err', $n ? '⛔️ ' . fa_num($n) . ' کارت رد شد.' : 'عملیات ناموفق بود.');
        } elseif ($do === 'delete') {
            need('cards.delete', 'cards');
            foreach ($ids as $i) { if (CardAuth::remove($i)) $n++; }
            flash($n ? 'ok' : 'err', $n ? '🗑 ' . fa_num($n) . ' کارت حذف شد.' : 'عملیات ناموفق بود.');
        } else {
            flash('err', 'عملیات نامعتبر است.');
        }
        back('cards', $rt);
    }

    if ($act === 'set') {
        need('cards.settings', 'cards');
        DB::setSetting('cardauth_enabled',  pchk('cardauth_enabled'));
        DB::setSetting('cardauth_required', pchk('cardauth_required'));
        DB::setSetting('cardauth_auto',     pchk('cardauth_auto'));
        DB::setSetting('cardauth_holder',   pchk('cardauth_holder'));
        DB::setSetting('cardauth_sheba',    pchk('cardauth_sheba'));
        DB::setSetting('cardauth_luhn',     pchk('cardauth_luhn'));
        /* قفل تک‌کارت دیگر گزینهٔ جداگانه نیست: فقط وقتی سقف = ۱ باشد روشن می‌شود */
        $maxCards = max(1, min(20, pint('cardauth_max') ?: 3));
        DB::setSetting('cardauth_single',    $maxCards <= 1 ? '1' : '0');
        DB::setSetting('cardauth_photo',     pchk('cardauth_photo'));
        DB::setSetting('cardauth_photo_req', pchk('cardauth_photo_req'));
        DB::setSetting('cardauth_max',      $maxCards);
        DB::setSetting('cardauth_note',     mb_substr(ptxt('cardauth_note'), 0, 600));
        flash('ok', '✅ تنظیمات احراز کارت ذخیره شد.');
        back('cards', ['tab' => 'set']);
    }

    /* روشن/خاموش کردن سریع از روی هدر */
    if ($act === 'quick') {
        need('cards.settings', 'cards');
        $newOn = !CardAuth::enabled();
        DB::setSetting('cardauth_enabled', $newOn ? '1' : '0');
        flash('ok', $newOn ? '✅ احراز کارت روشن شد.' : '⛔️ احراز کارت خاموش شد.');
        back('cards', $rt);
    }
}

/* ==================== داده‌ها ==================== */
$S     = CardAuth::stats();
$onOff = CardAuth::enabled();
$rate  = $S['total'] > 0 ? (int)round($S['approved'] / max(1, (int)$S['total']) * 100) : 0;
$canOk = can('cards.approve');
$canNo = can('cards.reject');
$canDl = can('cards.delete');
$canBulk = $canOk || $canNo || $canDl;

/* توزیع بانکی */
$banks = [];
try {
    $banks = DB::all(
        "SELECT COALESCE(NULLIF(bank, ''), 'نامشخص') AS bnk, COUNT(*) AS n,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS ok,
                SUM(CASE WHEN status = 'pending'  THEN 1 ELSE 0 END) AS pend,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS bad,
                SUM(uses) AS uses
           FROM {p}user_cards
          GROUP BY COALESCE(NULLIF(bank, ''), 'نامشخص')
          ORDER BY n DESC LIMIT 40"
    );
} catch (Throwable $e) { $banks = []; }

/* روند ۱۴ روز اخیر */
$trend = [];
try {
    foreach (DB::all(
        "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM {p}user_cards
          WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
          GROUP BY DATE(created_at)"
    ) as $t) { $trend[(string)$t['d']] = (int)$t['n']; }
} catch (Throwable $e) { $trend = []; }

$days = [];
$maxD = 1;
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime('-' . $i . ' day'));
    $v = (int)($trend[$d] ?? 0);
    if ($v > $maxD) $maxD = $v;
    $days[] = ['d' => $d, 'n' => $v];
}

/** فاصلهٔ زمانی خوانا */
$ago = function (?string $dt): string {
    $dt = trim((string)$dt);
    if ($dt === '') return '—';
    $t = strtotime($dt);
    if (!$t) return '—';
    $s = time() - $t;
    if ($s < 60)    return 'همین حالا';
    if ($s < 3600)  return fa_num((string)(int)($s / 60)) . ' دقیقه پیش';
    if ($s < 86400) return fa_num((string)(int)($s / 3600)) . ' ساعت پیش';
    if ($s < 2592000) return fa_num((string)(int)($s / 86400)) . ' روز پیش';
    return fa_num(to_jalali($dt));
};

/* کارت‌های در انتظار و لیست کل */
$reqs = CardAuth::pending(300);
$list = CardAuth::search(['q' => $q, 'status' => $fst, 'limit' => 400]);
if ($fbank !== '') {
    $list = array_values(array_filter($list, function ($c) use ($fbank) {
        return (string)($c['bank'] ?? '') === $fbank;
    }));
}

$tabUrl = function (string $t) use ($q, $fst, $fbank): string {
    $u = 'index.php?p=cards&tab=' . $t;
    if ($q !== '')     $u .= '&q=' . urlencode($q);
    if ($fst !== '')   $u .= '&st=' . urlencode($fst);
    if ($fbank !== '') $u .= '&bank=' . urlencode($fbank);
    return $u;
};

/** کارت کاربر در حالت بررسی */
$nameOf = function (array $c): string {
    $n = trim((string)($c['first_name'] ?? ''));
    if ($n === '') $n = trim((string)($c['username'] ?? ''));
    if ($n === '') $n = 'کاربر ' . (string)($c['tg_id'] ?? '');
    return $n;
};
?>

<section class="ca-hero">
  <span class="ca-glow g1"></span>
  <span class="ca-glow g2"></span>

  <div class="ca-htop">
    <div class="ca-hic">💳</div>
    <div class="ca-htt">
      <h2>احراز کارت بانکی</h2>
      <p>مدیریت کارت‌های ثبت‌شدهٔ کاربران برای واریز کارت‌به‌کارت — فقط شمارهٔ کارت و نام صاحب کارت</p>
    </div>
    <div class="ca-hact">
      <span class="ca-pill <?= $onOff ? 'on' : 'off' ?>"><?= $onOff ? '● فعال' : '○ خاموش' ?></span>
      <?php if (can('cards.settings')): ?>
        <form method="post" class="inline">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="quick">
          <input type="hidden" name="rt" value="<?= h($tab) ?>">
          <button class="btn sm <?= $onOff ? 'ghost' : 'green' ?>" data-confirm="<?= $onOff ? 'احراز کارت خاموش شود؟' : 'احراز کارت روشن شود؟' ?>">
            <?= $onOff ? '⏻ خاموش کردن' : '⏻ روشن کردن' ?>
          </button>
        </form>
      <?php endif; ?>
      <a class="btn sm ghost" href="<?= h($tabUrl($tab)) ?>&export=1">⬇️ خروجی CSV</a>
    </div>
  </div>

  <div class="ca-cells">
    <div class="ca-cell"><span class="i">💳</span><span class="v"><?= fa_num((int)$S['total']) ?></span><span class="l">کل کارت‌ها</span></div>
    <div class="ca-cell o"><span class="i">⏳</span><span class="v"><?= fa_num((int)$S['pending']) ?></span><span class="l">در انتظار</span></div>
    <div class="ca-cell g"><span class="i">✅</span><span class="v"><?= fa_num((int)$S['approved']) ?></span><span class="l">تایید‌شده</span></div>
    <div class="ca-cell r"><span class="i">⛔️</span><span class="v"><?= fa_num((int)$S['rejected']) ?></span><span class="l">ردشده</span></div>
    <div class="ca-cell b"><span class="i">👥</span><span class="v"><?= fa_num((int)$S['users']) ?></span><span class="l">کاربر دارای کارت</span></div>
    <div class="ca-cell c"><span class="i">🆕</span><span class="v"><?= fa_num((int)$S['today']) ?></span><span class="l">ثبت امروز</span></div>
  </div>

  <div class="ca-rate">
    <div class="ca-rate-t">
      <span>نرخ تایید</span>
      <b><?= fa_num($rate) ?>٪</b>
    </div>
    <div class="ca-rate-bar"><span style="width:<?= (int)$rate ?>%"></span></div>
  </div>
</section>

<nav class="ca-nav">
  <a class="ca-nv <?= $tab === 'req' ? 'on' : '' ?>" href="<?= h($tabUrl('req')) ?>">
    <span class="i">⏳</span>
    <span class="t">درخواست‌ها</span>
    <?php if ((int)$S['pending'] > 0): ?><span class="n"><?= fa_num((int)$S['pending']) ?></span><?php endif; ?>
  </a>
  <a class="ca-nv <?= $tab === 'list' ? 'on' : '' ?>" href="<?= h($tabUrl('list')) ?>">
    <span class="i">🗂</span><span class="t">همهٔ کارت‌ها</span><span class="n"><?= fa_num((int)$S['total']) ?></span>
  </a>
  <a class="ca-nv <?= $tab === 'bank' ? 'on' : '' ?>" href="<?= h($tabUrl('bank')) ?>">
    <span class="i">🏦</span><span class="t">بانک‌ها</span><span class="n"><?= fa_num(count($banks)) ?></span>
  </a>
  <?php if (can('cards.settings')): ?>
    <a class="ca-nv <?= $tab === 'set' ? 'on' : '' ?>" href="<?= h($tabUrl('set')) ?>">
      <span class="i">⚙️</span><span class="t">تنظیمات</span>
    </a>
  <?php endif; ?>
</nav>

<?php if (!$onOff): ?>
  <div class="alert a-warn mt3">⚠️ احراز کارت هم‌اکنون <b>خاموش</b> است؛ کاربران بدون ثبت کارت می‌توانند رسید واریز بفرستند.</div>
<?php endif; ?>

<?php if ($canBulk && ($tab === 'req' || $tab === 'list')): ?>
  <form method="post" id="caBulk" class="ca-bulk" hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="bulk">
    <input type="hidden" name="rt" value="<?= h($tab) ?>">
    <div class="b-l"><b id="caCnt">۰</b> کارت انتخاب شده</div>
    <input class="b-r" type="text" name="reason" placeholder="دلیل رد (اختیاری)" maxlength="200">
    <div class="b-a">
      <?php if ($canOk): ?><button class="btn sm green" name="do" value="approve" data-confirm="کارت‌های انتخاب‌شده تایید شوند؟">✅ تایید</button><?php endif; ?>
      <?php if ($canNo): ?><button class="btn sm orange" name="do" value="reject" data-confirm="کارت‌های انتخاب‌شده رد شوند؟">⛔️ رد</button><?php endif; ?>
      <?php if ($canDl): ?><button class="btn sm red" name="do" value="delete" data-confirm="کارت‌های انتخاب‌شده حذف شوند؟">🗑 حذف</button><?php endif; ?>
      <button type="button" class="btn sm ghost" id="caClr">✖️ لغو</button>
    </div>
  </form>
<?php endif; ?>

<?php /* ==================== تب ۱: درخواست‌های در انتظار ==================== */ ?>
<?php if ($tab === 'req'): ?>

  <div class="ca-head mt4">
    <div>
      <b>⏳ درخواست‌های در انتظار تایید</b>
      <span class="muted xs">— <?= fa_num(count($reqs)) ?> مورد</span>
    </div>
    <div class="ca-head-a">
      <?php if ($reqs && $canBulk): ?>
        <label class="ca-all"><input type="checkbox" id="caAll"> انتخاب همه</label>
      <?php endif; ?>
      <?php if ($reqs && $canOk): ?>
        <form method="post" class="inline">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="approve_all">
          <input type="hidden" name="rt" value="req">
          <button class="btn sm green" data-confirm="همهٔ کارت‌های در انتظار تایید شوند؟">✔️ تایید همه</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$reqs): ?>
    <div class="empty mt3"><div class="ic">🎉</div>هیچ درخواست بازی ندارید — همه بررسی شده‌اند.</div>
  <?php else: ?>
    <div class="ca-grid mt3">
      <?php foreach ($reqs as $c):
        $cid   = (int)$c['id'];
        $nm    = $nameOf($c);
        $un    = trim((string)($c['username'] ?? ''));
        $bank  = trim((string)($c['bank'] ?? ''));
        $hold  = trim((string)($c['holder'] ?? ''));
        $sheba = trim((string)($c['sheba'] ?? ''));
        $pho   = trim((string)($c['photo'] ?? ''));
      ?>
        <article class="ca-card">
          <header class="ca-c-top">
            <?php if ($canBulk): ?>
              <input class="ca-ck" type="checkbox" name="ids[]" value="<?= $cid ?>" form="caBulk">
            <?php endif; ?>
            <span class="ca-av"><?= h(mb_substr($nm, 0, 1)) ?></span>
            <div class="ca-who">
              <b><?= h($nm) ?></b>
              <span class="muted xs ltr"><?= $un !== '' ? '@' . h($un) : 'ID ' . fa_num((string)$c['tg_id']) ?></span>
            </div>
            <span class="ca-time"><?= h($ago((string)($c['created_at'] ?? ''))) ?></span>
          </header>

          <div class="ca-pan" data-copy="<?= h(CardAuth::digits((string)$c['pan'])) ?>" title="کپی شماره کارت">
            <span class="bk"><?= $bank !== '' ? h($bank) : 'بانک نامشخص' ?></span>
            <span class="nm mono ltr"><?= h(CardAuth::pretty((string)$c['pan'])) ?></span>
            <span class="cp">📋</span>
          </div>

          <div class="ca-meta">
            <div class="m"><span>👤 صاحب کارت</span><b><?= $hold !== '' ? h($hold) : '<i class="muted">ثبت نشده</i>' ?></b></div>
            <?php if ($sheba !== ''): ?>
              <div class="m"><span>🏦 شبا</span><b class="mono ltr xs">IR<?= h($sheba) ?></b></div>
            <?php endif; ?>
            <div class="m"><span>💸 تعداد واریز</span><b><?= fa_num((int)($c['uses'] ?? 0)) ?></b></div>
            <?php if ($pho !== ''): ?>
              <div class="m"><span>📷 تصویر کارت</span>
                <a class="ca-ph" href="index.php?p=cards&photo=<?= urlencode($pho) ?>" target="_blank" rel="noopener">مشاهده تصویر</a>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($pho !== ''): ?>
            <a class="ca-shot" href="index.php?p=cards&photo=<?= urlencode($pho) ?>" target="_blank" rel="noopener">
              <img src="index.php?p=cards&photo=<?= urlencode($pho) ?>" alt="تصویر کارت" loading="lazy">
            </a>
          <?php endif; ?>

          <footer class="ca-c-act">
            <?php if ($canOk): ?>
              <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="approve">
                <input type="hidden" name="id" value="<?= $cid ?>">
                <input type="hidden" name="rt" value="req">
                <button class="btn sm green">✅ تایید</button>
              </form>
            <?php endif; ?>
            <?php if ($canNo): ?>
              <form method="post" class="inline ca-rej">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="reject">
                <input type="hidden" name="id" value="<?= $cid ?>">
                <input type="hidden" name="rt" value="req">
                <input type="text" name="reason" placeholder="دلیل (اختیاری)" maxlength="200">
                <button class="btn sm orange">⛔️ رد</button>
              </form>
            <?php endif; ?>
            <?php if ($canDl): ?>
              <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="del">
                <input type="hidden" name="id" value="<?= $cid ?>">
                <input type="hidden" name="rt" value="req">
                <button class="btn sm red" data-confirm="این کارت حذف شود؟">🗑</button>
              </form>
            <?php endif; ?>
          </footer>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php endif; ?>

<?php /* ==================== تب ۲: همهٔ کارت‌ها ==================== */ ?>
<?php if ($tab === 'list'): ?>

  <form class="ca-filter mt4" method="get">
    <input type="hidden" name="p" value="cards">
    <input type="hidden" name="tab" value="list">
    <div class="f-search">
      <span>🔍</span>
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="شماره کارت، نام صاحب کارت، آیدی تلگرام یا یوزرنیم…">
      <button class="btn sm">جستجو</button>
    </div>
    <div class="f-chips">
      <a class="chip <?= $fst === '' ? 'on' : '' ?>" href="index.php?p=cards&tab=list<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">همه</a>
      <?php foreach (CardAuth::ST as $st): ?>
        <a class="chip s-<?= h($st) ?> <?= $fst === $st ? 'on' : '' ?>" href="index.php?p=cards&tab=list&st=<?= h($st) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">
          <?= h(CardAuth::ST_LABEL[$st] ?? $st) ?>
        </a>
      <?php endforeach; ?>
      <?php if ($fbank !== ''): ?>
        <a class="chip on" href="index.php?p=cards&tab=list">🏦 <?= h($fbank) ?> ✖️</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="ca-head mt3">
    <div><b>🗂 نتایج</b> <span class="muted xs">— <?= fa_num(count($list)) ?> کارت</span></div>
    <?php if ($list && $canBulk): ?>
      <label class="ca-all"><input type="checkbox" id="caAll"> انتخاب همه</label>
    <?php endif; ?>
  </div>

  <?php if (!$list): ?>
    <div class="empty mt3"><div class="ic">🔍</div>کارتی با این فیلترها پیدا نشد.</div>
  <?php else: ?>
    <div class="tbl-wrap mt3">
      <table class="table ca-tbl" data-enhance data-page-size="25">
        <thead>
          <tr>
            <?php if ($canBulk): ?><th class="nw" data-nosort>☑️</th><?php endif; ?>
            <th>کاربر</th>
            <th>شماره کارت</th>
            <th>بانک</th>
            <th>صاحب کارت</th>
            <th>وضعیت</th>
            <th>واریز</th>
            <th>ثبت</th>
            <th data-nosort>عملیات</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($list as $c):
          $cid  = (int)$c['id'];
          $nm   = $nameOf($c);
          $un   = trim((string)($c['username'] ?? ''));
          $pho  = trim((string)($c['photo'] ?? ''));
          $stt  = (string)$c['status'];
        ?>
          <tr class="st-<?= h($stt) ?>">
            <?php if ($canBulk): ?>
              <td><input class="ca-ck" type="checkbox" name="ids[]" value="<?= $cid ?>" form="caBulk"></td>
            <?php endif; ?>
            <td>
              <div class="ca-u">
                <span class="ca-av sm"><?= h(mb_substr($nm, 0, 1)) ?></span>
                <div>
                  <b><?= h($nm) ?></b>
                  <span class="muted xs ltr"><?= $un !== '' ? '@' . h($un) : fa_num((string)$c['tg_id']) ?></span>
                </div>
              </div>
            </td>
            <td class="mono ltr nw" data-copy="<?= h(CardAuth::digits((string)$c['pan'])) ?>"><?= h(CardAuth::pretty((string)$c['pan'])) ?></td>
            <td class="nw"><?= h((string)($c['bank'] ?? '') ?: '—') ?></td>
            <td><?= h((string)($c['holder'] ?? '') ?: '—') ?></td>
            <td><?= badge($stt) ?></td>
            <td><?= fa_num((int)($c['uses'] ?? 0)) ?></td>
            <td class="nw xs muted" title="<?= h((string)$c['created_at']) ?>"><?= h($ago((string)($c['created_at'] ?? ''))) ?></td>
            <td class="nw ca-ops">
              <?php if ($pho !== ''): ?>
                <a class="btn sm ghost" href="index.php?p=cards&photo=<?= urlencode($pho) ?>" target="_blank" rel="noopener" title="تصویر کارت">📷</a>
              <?php endif; ?>
              <?php if ($canOk && $stt !== 'approved'): ?>
                <form method="post" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="approve">
                  <input type="hidden" name="id" value="<?= $cid ?>">
                  <input type="hidden" name="rt" value="list">
                  <button class="btn sm green" title="تایید">✅</button>
                </form>
              <?php endif; ?>
              <?php if ($canNo && $stt !== 'rejected'): ?>
                <form method="post" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="reject">
                  <input type="hidden" name="id" value="<?= $cid ?>">
                  <input type="hidden" name="rt" value="list">
                  <button class="btn sm orange" title="رد">⛔️</button>
                </form>
              <?php endif; ?>
              <?php if ($canDl): ?>
                <form method="post" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="del">
                  <input type="hidden" name="id" value="<?= $cid ?>">
                  <input type="hidden" name="rt" value="list">
                  <button class="btn sm red" data-confirm="کارت حذف شود؟" title="حذف">🗑</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php endif; ?>

<?php /* ==================== تب ۳: توزیع بانکی ==================== */ ?>
<?php if ($tab === 'bank'): ?>

  <div class="ca-two mt4">
    <section class="card">
      <div class="card-head"><b>🏦 توزیع کارت‌ها به تفکیک بانک</b><span class="muted xs"><?= fa_num(count($banks)) ?> بانک</span></div>
      <?php if (!$banks): ?>
        <div class="empty"><div class="ic">🏦</div>هنوز کارتی ثبت نشده است.</div>
      <?php else: ?>
        <?php $bMax = max(1, (int)($banks[0]['n'] ?? 1)); ?>
        <div class="ca-banks">
          <?php foreach ($banks as $b):
            $bn  = (string)$b['bnk'];
            $bnn = (int)$b['n'];
            $pc  = (int)round($bnn / $bMax * 100);
          ?>
            <a class="ca-bank" href="index.php?p=cards&tab=list&bank=<?= urlencode($bn) ?>">
              <div class="bb-t">
                <b><?= h($bn) ?></b>
                <span class="n"><?= fa_num($bnn) ?></span>
              </div>
              <div class="bb-bar"><span style="width:<?= $pc ?>%"></span></div>
              <div class="bb-s">
                <span class="g">✅ <?= fa_num((int)$b['ok']) ?></span>
                <span class="o">⏳ <?= fa_num((int)$b['pend']) ?></span>
                <span class="r">⛔️ <?= fa_num((int)$b['bad']) ?></span>
                <span class="c">💸 <?= fa_num((int)$b['uses']) ?> واریز</span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><b>📈 ثبت کارت در ۱۴ روز اخیر</b></div>
      <div class="ca-chart">
        <?php foreach ($days as $d): $hh = (int)round(((int)$d['n']) / $maxD * 100); ?>
          <div class="cb" title="<?= h(to_jalali($d['d'])) ?> — <?= fa_num((int)$d['n']) ?> کارت">
            <span class="bar" style="height:<?= max(4, $hh) ?>%"></span>
            <span class="lb"><?= fa_num((int)date('j', strtotime($d['d']))) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="ca-legend">
        <span>بیشترین در روز: <b><?= fa_num($maxD) ?></b></span>
        <span>مجموع دوهفته: <b><?= fa_num(array_sum(array_column($days, 'n'))) ?></b></span>
      </div>

      <div class="card-head mt4"><b>🧠 نکته‌های امنیتی</b></div>
      <ul class="ca-tips">
        <li>فقط <b>شمارهٔ کارت</b> و <b>نام صاحب کارت</b> ذخیره می‌شود؛ رمز دوم، CVV2 و تاریخ انقضا هرگز درخواست نمی‌شود.</li>
        <li>تصاویر کارت در پوشهٔ محافظت‌شده ذخیره می‌شوند و فقط از همین پنل قابل دیدن هستند.</li>
        <li>اگر نام صاحب کارت با نام کاربر هم‌خوانی ندارد، قبل از تایید از کاربر توضیح بخواهید.</li>
        <li>با فعال بودن <b>تایید خودکار</b>، کارت‌ها بدون بررسی مدیر فعال می‌شوند — فقط در فروشگاه‌های کم‌ریسک روشن کنید.</li>
      </ul>
    </section>
  </div>

<?php endif; ?>

<?php /* ==================== تب ۴: تنظیمات ==================== */ ?>
<?php if ($tab === 'set' && can('cards.settings')): ?>

  <form method="post" class="ca-set mt4">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="set">

    <section class="card">
      <div class="card-head"><b>🔑 فعال‌سازی و سیاست</b></div>
      <div class="ca-sw">
        <label class="sw">
          <input type="checkbox" name="cardauth_enabled" value="1" <?= CardAuth::enabled() ? 'checked' : '' ?>>
          <span class="bx"></span>
          <span class="tx"><b>احراز کارت فعال باشد</b><i>دکمهٔ «کارت‌های من» در ربات نمایش داده می‌شود</i></span>
        </label>
        <label class="sw">
          <input type="checkbox" name="cardauth_required" value="1" <?= CardAuth::required() ? 'checked' : '' ?>>
          <span class="bx"></span>
          <span class="tx"><b>برای واریز کارت‌به‌کارت اجباری باشد</b><i>کاربر تا تایید نشدن کارت، رسید واریز نمی‌تواند بفرستد</i></span>
        </label>
        <label class="sw">
          <input type="checkbox" name="cardauth_auto" value="1" <?= CardAuth::autoApprove() ? 'checked' : '' ?>>
          <span class="bx"></span>
          <span class="tx"><b>تایید خودکار کارت‌ها</b><i>بدون بررسی مدیر بلافاصله تایید می‌شوند (پرریسک)</i></span>
        </label>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><b>📝 اطلاعات درخواستی از کاربر</b></div>
      <div class="ca-sw">
        <label class="sw">
          <input type="checkbox" name="cardauth_holder" value="1" <?= CardAuth::needHolder() ? 'checked' : '' ?>>
          <span class="bx"></span>
          <span class="tx"><b>نام صاحب کارت اجباری</b><i>برای تطبیق با نام واریزکننده توصیه می‌شود</i></span>
        </label>
        <label class="sw">
          <input type="checkbox" name="cardauth_sheba" value="1" <?= CardAuth::needSheba() ? 'checked' : '' ?>>
          <span class="bx"></span>
          <span class="tx"><b>شمارهٔ شبا هم گرفته شود</b><i>برای تسویه یا بازگرداندن وجه مفید است</i></span>
        </label>
        <label class="sw">
          <input type="checkbox" name="cardauth_luhn" value="1" <?= CardAuth::checkLuhn() ? 'checked' : '' ?>>
          <span class="bx"></span>
          <span class="tx"><b>اعتبارسنجی شماره کارت (لون)</b><i>شماره‌های جعلی و اشتباهی رد می‌شوند</i></span>
        </label>
        <label class="sw">
          <input type="checkbox" name="cardauth_photo" value="1" <?= CardAuth::wantPhoto() ? 'checked' : '' ?>>
          <span class="bx"></span>
          <span class="tx"><b>درخواست تصویر کارت</b><i>کاربر می‌تواند عکس کارت را هم بفرستد</i></span>
        </label>
        <label class="sw">
          <input type="checkbox" name="cardauth_photo_req" value="1" <?= CardAuth::photoRequired() ? 'checked' : '' ?>>
          <span class="bx"></span>
          <span class="tx"><b>ارسال تصویر اجباری باشد</b><i>فقط وقتی گزینهٔ بالا روشن باشد اثر دارد</i></span>
        </label>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><b>🔢 سقف و متن راهنما</b></div>
      <div class="form-grid g2">
        <div class="fld">
          <label>حداکثر کارت فعال برای هر کاربر</label>
          <input type="number" name="cardauth_max" min="1" max="20" value="<?= (int)CardAuth::maxCards() ?>">
          <div class="hint">اگر ۱ بگذارید، هر کاربر فقط یک کارت ثبت‌شده می‌تواند داشته باشد.</div>
        </div>
        <div class="fld">
          <label>وضعیت فعلی</label>
          <div class="ca-now">
            <span class="badge <?= CardAuth::enabled() ? 'b-green' : 'b-gray' ?>"><?= CardAuth::enabled() ? 'فعال' : 'خاموش' ?></span>
            <span class="badge <?= CardAuth::required() ? 'b-orange' : 'b-gray' ?>"><?= CardAuth::required() ? 'اجباری' : 'اختیاری' ?></span>
            <span class="badge <?= CardAuth::autoApprove() ? 'b-blue' : 'b-gray' ?>"><?= CardAuth::autoApprove() ? 'تایید خودکار' : 'تایید دستی' ?></span>
            <span class="badge b-gray">سقف <?= fa_num((int)CardAuth::maxCards()) ?> کارت</span>
          </div>
        </div>
      </div>
      <div class="fld mt3">
        <label>یادداشت اضافی در راهنمای کاربر</label>
        <textarea name="cardauth_note" rows="4" maxlength="600" placeholder="مثلاً: فقط کارتی را ثبت کنید که به نام خودتان است."><?= h((string)DB::setting('cardauth_note', '')) ?></textarea>
        <div class="hint">این متن زیر راهنمای ثبت کارت به کاربر نشان داده می‌شود.</div>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><b>👁 پیش‌نمایش پیام کاربر</b></div>
      <div class="ca-prev"><?= nl2br(CardAuth::guide()) ?></div>
      <div class="alert w mt3">⛔️ هرگز CVV2، رمز دوم، رمز پویا یا تاریخ انقضا را از کاربر نخواهید؛ ربات هم این داده‌ها را ذخیره نمی‌کند.</div>
    </section>

    <div class="ca-save">
      <button class="btn btn-primary">💾 ذخیرهٔ تنظیمات</button>
      <a class="btn ghost" href="index.php?p=cards&tab=req">انصراف</a>
    </div>
  </form>

<?php endif; ?>

<script>
(function () {
  var bulk = document.getElementById('caBulk');
  if (!bulk) return;
  var cks  = Array.prototype.slice.call(document.querySelectorAll('.ca-ck'));
  var all  = document.getElementById('caAll');
  var cnt  = document.getElementById('caCnt');
  var clr  = document.getElementById('caClr');

  function fa(n) {
    return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; });
  }
  function sync() {
    var n = cks.filter(function (c) { return c.checked; }).length;
    if (cnt) cnt.textContent = fa(n);
    bulk.hidden = n === 0;
    if (all) all.checked = n > 0 && n === cks.length;
  }
  cks.forEach(function (c) { c.addEventListener('change', sync); });
  if (all) all.addEventListener('change', function () {
    cks.forEach(function (c) { c.checked = all.checked; });
    sync();
  });
  if (clr) clr.addEventListener('click', function () {
    cks.forEach(function (c) { c.checked = false; });
    if (all) all.checked = false;
    sync();
  });
  sync();
})();
</script>
