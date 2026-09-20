<?php
if (!can('update.view')) { echo denyBox('این بخش برای شما فعال نیست.'); return; }
/**
 * به‌روزرسانی ربات — نسخهٔ گسترده
 * دریافت از گیت‌هاب، نصب دستی ZIP، تکمیل ساختار دیتابیس و بازگشت به نسخهٔ قبل
 */

$canRun = can('update.run');
$hasZip = class_exists('ZipArchive');
$hasCurl = function_exists('curl_init');
$rootWritable = is_writable(APP_ROOT);

$tab = (string)($_GET['tab'] ?? 'home');
if (!in_array($tab, ['home', 'db', 'source', 'history'], true)) $tab = 'home';

$act = (string)($_POST['act'] ?? '');
if ($act !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('err', 'نشست منقضی شده است. دوباره تلاش کنید.'); back('update'); }
    $rt = ['tab' => (string)($_POST['rt'] ?? $tab)];

    if ($act === 'cfg') { need('update.run', 'update');
        DB::setSetting('update_repo', Updater::normalizeRepo(ptxt('update_repo', Updater::DEFAULT_REPO)));
        DB::setSetting('update_subdir', trim(str_replace('\\', '/', ptxt('update_subdir')), " /")); /* fixed80 */
        DB::setSetting('update_branch', ptxt('update_branch', 'main') ?: 'main');
        $tok = ptxt('update_token');
        if ($tok !== '********') DB::setSetting('update_token', $tok);
        DB::setSetting('update_auto_check', (string)pchk('update_auto_check'));
        DB::loadSettings(true);
        flash('ok', '✅ تنظیمات منبع به‌روزرسانی ذخیره شد.');
        back('update', $rt);
    }

    if ($act === 'check') { need('update.run', 'update');
        $r = Updater::check();
        flash(!empty($r['ok']) ? 'ok' : 'err', h((string)($r['message'] ?? '')));
        back('update', $rt);
    }

    if ($act === 'run') { need('update.run', 'update');
        @set_time_limit(300);
        $d = Updater::download();
        if (empty($d['ok'])) { flash('err', h((string)$d['message'])); back('update', $rt); }
        $r = Updater::apply((string)$d['path'], pchk('backup_first') === 1, pchk('run_migrate') === 1);
        @unlink((string)$d['path']);
        $lines = array_slice((array)($r['log'] ?? []), 0, 14);
        flash(!empty($r['ok']) ? 'ok' : 'err',
            h((string)($r['message'] ?? '')) . ($lines ? '<br><span class="mono xs ltr">' . h(implode(' | ', $lines)) . '</span>' : ''));
        back('update', $rt);
    }

    if ($act === 'upload') { need('update.run', 'update');
        @set_time_limit(300);
        $f = (array)($_FILES['zip'] ?? []);
        /* 0.0.2 #10-update-zip */
        if ($f && class_exists('Upload') && !Upload::check($f, 200 * 1024 * 1024, ['zip'])['ok']) $f = [];
        if ((int)($f['error'] ?? 1) !== 0 || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) {
            flash('err', 'آپلود فایل ناموفق بود.'); back('update', $rt);
        }
        if (strtolower((string)pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'zip') {
            flash('err', 'فقط فایل ZIP مجاز است.'); back('update', $rt);
        }
        $dest = Updater::updDir() . '/manual-' . date('Ymd-His') . '.zip';
        if (!@move_uploaded_file((string)$f['tmp_name'], $dest)) {
            flash('err', 'ذخیرهٔ فایل روی سرور ناموفق بود.'); back('update', $rt);
        }
        $r = Updater::apply($dest, pchk('backup_first') === 1, pchk('run_migrate') === 1);
        $lines = array_slice((array)($r['log'] ?? []), 0, 14);
        flash(!empty($r['ok']) ? 'ok' : 'err',
            h((string)($r['message'] ?? '')) . ($lines ? '<br><span class="mono xs ltr">' . h(implode(' | ', $lines)) . '</span>' : ''));
        back('update', $rt);
    }

    if ($act === 'migrate') { need('update.run', 'update');
        $r = Updater::migrate();
        flash(!empty($r['ok']) ? 'ok' : 'err', h((string)($r['message'] ?? '')));
        back('update', $rt);
    }

    if ($act === 'schema') { need('update.run', 'update');
        if (!class_exists('Migrate')) { flash('err', 'ماژول Migrate در دسترس نیست.'); back('update', $rt); }
        $r  = Migrate::run();
        $ls = array_slice((array)($r['log'] ?? []), 0, 60);
        flash(!empty($r['ok']) ? 'ok' : 'err',
            h((string)($r['message'] ?? '')) . ($ls ? '<br><span class="mono xs ltr">' . h(implode(' | ', $ls)) . '</span>' : ''));
        back('update', $rt);
    }

    if ($act === 'announce') { need('update.run', 'update'); /* fixed79: اعلام دستی بستهٔ فعلی در تاپیک آپدیت */
        $r = (class_exists('Release') && method_exists('Release', 'announceBuild')) ? Release::announceBuild(true) : ['ok' => false, 'message' => 'ماژول Release در دسترس نیست.'];
        flash(!empty($r['ok']) ? 'ok' : 'err', h((string)($r['message'] ?? '')));
        back('update', $rt);
    }

    if ($act === 'rollback') { need('update.run', 'update');
        $r = Updater::rollback(ptxt('name'));
        flash(!empty($r['ok']) ? 'ok' : 'err', h((string)($r['message'] ?? '')));
        back('update', $rt);
    }
}

/* ==================== داده‌ها ==================== */
$info = Updater::info();
$hist = Updater::history();
$plan = class_exists('Migrate') ? Migrate::plan()
    : ['tables' => [], 'columns' => [], 'indexes' => [], 'settings' => [], 'modify' => [], 'error' => 'ماژول Migrate پیدا نشد.'];
$missCnt  = class_exists('Migrate') ? Migrate::missingCount($plan) : 0;
$hasUpd   = !empty($info['has_update']);
$autoChk  = (int)($info['auto_check'] ?? 1) === 1;
$chkAt    = (string)($info['checked_at'] ?? '');
$latest   = (string)($info['latest'] ?? '');
$errTxt   = (string)($info['error'] ?? '');
$tokenSet = trim((string)DB::setting('update_token', '')) !== '';
$ready    = $rootWritable && $hasZip && $hasCurl;

$okCnt = 0;
foreach ($hist as $r) { if (!empty($r['ok'])) $okCnt++; }

$tabUrl = fn(string $t): string => 'index.php?p=update&tab=' . $t;

$ago = function (string $dt): string {
    if ($dt === '') return '—';
    $ts = strtotime($dt);
    if (!$ts) return '—';
    $s = time() - $ts;
    if ($s < 60) return 'همین حالا';
    if ($s < 3600) return fa_num((int)($s / 60)) . ' دقیقه پیش';
    if ($s < 86400) return fa_num((int)($s / 3600)) . ' ساعت پیش';
    return fa_num((int)($s / 86400)) . ' روز پیش';
};
?>

<section class="up-hero<?= $hasUpd ? ' new' : '' ?>">
  <span class="up-glow g1"></span>
  <span class="up-glow g2"></span>
  <div class="up-htop">
    <div class="up-hic"><?= $hasUpd ? '🚀' : '✅' ?></div>
    <div class="up-htt">
      <h2>به‌روزرسانی ربات</h2>
      <p><?= $hasUpd
        ? 'نسخهٔ تازه‌ای منتشر شده است — پیش از نصب حتماً بکاپ بگیرید'
        : 'نسخهٔ شما به‌روز است — دریافت خودکار از گیت‌هاب، نصب دستی و بازگشت امن' ?></p>
    </div>
    <div class="up-vs">
      <div class="v now"><span>نسخهٔ فعلی</span><b class="mono ltr"><?= h((string)$info['current']) ?></b></div>
      <div class="arw"><?= $hasUpd ? '←' : '=' ?></div>
      <div class="v <?= $hasUpd ? 'nx' : '' ?>"><span>آخرین نسخه</span><b class="mono ltr"><?= h($latest !== '' ? $latest : '—') ?></b></div>
    </div>
  </div>

  <div class="up-cells">
    <div class="up-cell <?= $hasUpd ? 'o' : 'g' ?>"><span class="i"><?= $hasUpd ? '⬆️' : '💚' ?></span>
      <span class="v"><?= $hasUpd ? 'موجود' : 'به‌روز' ?></span><span class="l">وضعیت نسخه</span></div>
    <div class="up-cell b"><span class="i">⏱</span><span class="v"><?= h($ago($chkAt)) ?></span><span class="l">آخرین بررسی</span></div>
    <div class="up-cell <?= $autoChk ? 'g' : '' ?>"><span class="i">🔁</span>
      <span class="v"><?= $autoChk ? 'فعال' : 'خاموش' ?></span><span class="l">بررسی خودکار</span></div>
    <div class="up-cell <?= $missCnt > 0 ? 'o' : 'g' ?>"><span class="i">🧩</span>
      <span class="v"><?= $missCnt > 0 ? fa_num($missCnt) . ' مورد' : 'کامل' ?></span><span class="l">ساختار دیتابیس</span></div>
    <div class="up-cell c"><span class="i">📚</span><span class="v"><?= fa_num(count($hist)) ?></span><span class="l">سابقهٔ نصب</span></div>
    <div class="up-cell <?= $ready ? 'g' : 'r' ?>"><span class="i"><?= $ready ? '✅' : '⚠️' ?></span>
      <span class="v"><?= $ready ? 'آماده' : 'ناقص' ?></span><span class="l">وضعیت سرور</span></div>
  </div>
</section>

<nav class="up-nav">
  <a class="up-nv <?= $tab === 'home' ? 'on' : '' ?>" href="<?= h($tabUrl('home')) ?>"><span class="i">🚀</span><span class="t">نصب نسخه</span><?php if ($hasUpd): ?><span class="dot"></span><?php endif; ?></a>
  <a class="up-nv <?= $tab === 'db' ? 'on' : '' ?>" href="<?= h($tabUrl('db')) ?>"><span class="i">🧩</span><span class="t">ساختار دیتابیس</span><?php if ($missCnt > 0): ?><span class="n"><?= fa_num($missCnt) ?></span><?php endif; ?></a>
  <a class="up-nv <?= $tab === 'source' ? 'on' : '' ?>" href="<?= h($tabUrl('source')) ?>"><span class="i">⚙️</span><span class="t">منبع و نصب دستی</span></a>
  <a class="up-nv <?= $tab === 'history' ? 'on' : '' ?>" href="<?= h($tabUrl('history')) ?>"><span class="i">🕒</span><span class="t">تاریخچه و بازگشت</span></a>
</nav>

<?php if ($errTxt !== ''): ?>
  <div class="alert a-warn mt3">⚠️ آخرین خطای بررسی: <span><?= h($errTxt) ?></span></div>
<?php endif; ?>
<?php $upNotes = (array)($info['notes'] ?? []); if ($upNotes !== []): ?>
  <div class="alert a-info mt3">ℹ️ <?= h(implode(' ', array_map('strval', $upNotes))) ?></div>
<?php endif; ?>
<?php $upDiag = (array)($info['diag'] ?? []); if ($upDiag !== [] && ($errTxt !== '' || $tab === 'source')): ?>
  <details class="up-diag mt2">
    <summary>🧪 جزئیات بررسی (<?= fa_num((string)count($upDiag)) ?> تلاش)</summary>
    <table class="up-diag-t">
      <thead><tr><th>مرحله</th><th>آدرس</th><th>کد</th><th>نتیجه</th></tr></thead>
      <tbody>
      <?php foreach ($upDiag as $d): $d = (array)$d; ?>
        <tr class="<?= !empty($d['ok']) ? 'ok' : 'no' ?>">
          <td class="mono ltr"><?= h((string)($d['step'] ?? '')) ?></td>
          <td class="mono ltr u"><?= h((string)($d['url'] ?? '')) ?></td>
          <td class="mono"><?= fa_num((string)(int)($d['code'] ?? 0)) ?></td>
          <td><?= !empty($d['ok']) ? '✅ خوانده شد' : '✖ ' . h((string)($d['note'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="hint" style="margin-top:8px;line-height:2">
      <b>راهنمای رفع سریع:</b><br>
      • کد <b>۴۰۴</b>: نام مخزن یا شاخه اشتباه است، یا مخزن خصوصی است و توکن ثبت نشده، یا فایل version.json در ریشهٔ مخزن نیست (پروژه داخل یک پوشه است → «پوشهٔ داخلی» را پر کنید؛ سیستم خودش هم تلاش می‌کند پیدا کند).<br>
      • کد <b>۴۰۱/۴۰۳</b>: توکن نامعتبر است یا محدودیت درخواست گیت‌هاب؛ یک Fine-grained token با دسترسی <span class="mono ltr">Contents: Read</span> بسازید.<br>
      • کد <b>۰</b>: سرور به گیت‌هاب دسترسی ندارد (فایروال/DNS) — از «نصب دستی از فایل ZIP» استفاده کنید.
    </div>
  </details>
  <style>
    .up-diag{background:var(--surface-2);border:1px solid var(--border);border-radius:14px;padding:10px 14px}
    .up-diag summary{cursor:pointer;font-size:12.5px;font-weight:700}
    .up-diag-t{width:100%;border-collapse:collapse;margin-top:10px;font-size:11.5px}
    .up-diag-t th,.up-diag-t td{padding:6px 8px;border-bottom:1px solid var(--border);text-align:start;vertical-align:top}
    .up-diag-t td.u{max-width:420px;word-break:break-all;direction:ltr;text-align:left}
    .up-diag-t tr.ok td{color:var(--green)}
    .up-diag-t tr.no td:last-child{color:var(--orange)}
    @media (max-width:760px){.up-diag-t thead{display:none}.up-diag-t tr{display:grid;grid-template-columns:1fr;padding:6px 0}.up-diag-t td{border:0;padding:2px 0}}
  </style>
<?php endif; ?>

<?php /* ==================== تب ۱: نصب نسخه ==================== */ ?>
<?php if ($tab === 'home'): ?>
<div class="up-two mt3">

  <section class="card up-main">
    <div class="card-head"><div>
      <div class="card-title"><?= $hasUpd ? '🚀 نسخهٔ جدید آمادهٔ نصب است' : '🔍 بررسی نسخهٔ جدید' ?></div>
      <div class="card-sub">مخزن: <span class="mono ltr"><?= h(Updater::repo()) ?></span> · شاخه: <span class="mono ltr"><?= h(Updater::branch()) ?></span></div>
    </div>
      <?php if ($hasUpd): ?><span class="badge b-orange">نسخه <?= h($latest) ?></span><?php else: ?><span class="badge b-green">به‌روز</span><?php endif; ?>
    </div>

    <?php if ((string)($info['released'] ?? '') !== ''): ?>
      <div class="up-rel">🗓 تاریخ انتشار: <b><?= h((string)$info['released']) ?></b></div>
    <?php endif; ?>

    <div class="up-rel">🏷 نسخهٔ نصب‌شده: <b class="mono ltr"><?= h(Updater::tag((string)$info['current'], (string)($info['local_build'] ?? ''))) ?></b>
      <?php if ($latest !== ''): ?> · آخرین نسخهٔ مخزن: <b class="mono ltr"><?= h(Updater::tag($latest, (string)($info['remote_build'] ?? ''))) ?></b><?php endif; ?>
      <?php if ((string)($info['subdir'] ?? '') !== ''): ?> · پوشهٔ داخلی: <b class="mono ltr"><?= h((string)$info['subdir']) ?></b><?php endif; ?>
    </div>

    <?php if (!empty($info['changelog'])): ?>
      <div class="up-log">
        <div class="hd">📝 تغییرات این نسخه</div>
        <ul>
          <?php foreach (array_slice((array)$info['changelog'], 0, 14) as $c): ?>
            <li><?= h((string)$c) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php else: ?>
      <div class="empty sm">فهرست تغییرات هنوز دریافت نشده — یک‌بار «بررسی نسخهٔ جدید» را بزنید.</div>
    <?php endif; ?>

    <?php if ($canRun): ?>
      <form method="post" class="up-run">
        <?= csrf_field() ?>
        <input type="hidden" name="rt" value="home">
        <div class="up-opts">
          <label class="check"><input type="checkbox" name="backup_first" value="1" checked><span>پیش از نصب، بکاپ کامل گرفته شود (پیشنهادی)</span></label>
          <label class="check"><input type="checkbox" name="run_migrate" value="1" checked><span>پس از نصب، ساختار دیتابیس به‌روز شود</span></label>
        </div>
        <div class="up-btns">
          <button class="btn btn-ghost" type="submit" name="act" value="check">🔍 بررسی نسخهٔ جدید</button>
          <button class="btn <?= $hasUpd ? 'btn-primary' : 'btn-ghost' ?>" type="submit" name="act" value="run"
            <?= $ready ? '' : 'disabled' ?>
            data-confirm="نسخهٔ جدید دریافت و نصب شود؟ این کار ممکن است چند دقیقه طول بکشد.">⬇️ دریافت و نصب آخرین نسخه</button>
        </div>
      </form>
    <?php else: ?>
      <div class="alert a-warn mt3">⚠️ شما فقط دسترسی مشاهده دارید؛ اجرای به‌روزرسانی برای شما فعال نیست.</div>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><div><div class="card-title">🩺 آمادگی سرور</div>
      <div class="card-sub">پیش‌نیازهای نصب خودکار</div></div></div>
    <ul class="up-req">
      <li class="<?= $rootWritable ? 'ok' : 'no' ?>"><span class="d"></span><b>دسترسی نوشتن روی پوشهٔ پروژه</b><i><?= $rootWritable ? 'مجاز' : 'غیرمجاز — دسترسی ۷۵۵ بدهید' ?></i></li>
      <li class="<?= $hasZip ? 'ok' : 'no' ?>"><span class="d"></span><b>افزونهٔ ZipArchive</b><i><?= $hasZip ? 'فعال' : 'غیرفعال — نصب خودکار ممکن نیست' ?></i></li>
      <li class="<?= $hasCurl ? 'ok' : 'no' ?>"><span class="d"></span><b>افزونهٔ cURL</b><i><?= $hasCurl ? 'فعال' : 'غیرفعال — اتصال به گیت‌هاب ممکن نیست' ?></i></li>
      <li class="<?= $tokenSet ? 'ok' : 'nu' ?>"><span class="d"></span><b>توکن گیت‌هاب</b><i><?= $tokenSet ? 'ثبت شده' : 'ثبت نشده — فقط برای مخزن خصوصی لازم است' ?></i></li>
    </ul>

    <div class="up-safe">
      <div class="hd">🛡 در زمان نصب حفظ می‌شوند</div>
      <div class="tags"><span class="mono ltr">config.php</span><span class="mono ltr">storage/</span><span class="mono ltr">دیتابیس</span></div>
    </div>
  </section>
</div>
<?php if ($canRun && class_exists('Release') && method_exists('Release', 'localBuild')): /* fixed79: اعلان آپدیت در تاپیک */
  $lb = null; try { $lb = Release::localBuild(); } catch (Throwable $e) { $lb = null; }
  $lbLast  = (string)DB::setting('rel_last_build', '');
  $lbReady = false; try { $lbReady = Release::ready(); } catch (Throwable $e) { $lbReady = false; }
  $lbDone  = $lb && $lbLast === (string)$lb['id'];
?>
<section class="card mt3">
  <div class="card-head"><div><div class="card-title">📣 اعلان آپدیت در تاپیک</div>
    <div class="card-sub">بعد از هر نصب، بستهٔ بالای فایل CHANGELOG.md به‌صورت خودکار (کران‌جاب) در «کانال انتشار آپدیت» اعلام می‌شود؛ هر بسته فقط یک‌بار. این‌جا می‌توانید دستی هم بفرستید.</div></div>
    <span class="badge <?= $lb ? ($lbDone ? 'b-green' : 'b-orange') : 'b-gray' ?>"><?= $lb ? ($lbDone ? 'اعلام شده' : 'در انتظار اعلام') : 'CHANGELOG پیدا نشد' ?></span></div>
  <div class="mt3">📦 بستهٔ فعلی: <b class="mono ltr"><?= h($lb ? (string)$lb['id'] : '—') ?></b><?= $lb && (string)$lb['title'] !== '' ? ' — ' . h((string)$lb['title']) : '' ?></div>
  <div class="mt2">🕒 آخرین بستهٔ اعلام‌شده: <b class="mono ltr"><?= h($lbLast !== '' ? $lbLast : '—') ?></b></div>
  <div class="mt2">📡 کانال انتشار: <?= $lbReady ? '<span class="badge b-green">فعال</span>' : '<span class="badge b-gray">خاموش</span> <a href="index.php?p=settings&tab=rel">تنظیم کانال و تاپیک</a>' ?></div>
  <form method="post" class="inline mt3"><?= csrf_field() ?>
    <input type="hidden" name="act" value="announce"><input type="hidden" name="rt" value="home">
    <button class="btn btn-primary" type="submit" <?= ($lb && $lbReady) ? '' : 'disabled' ?>>📣 اعلام این نسخه در تاپیک آپدیت</button>
  </form>
</section>
<?php endif; ?>
<?php endif; ?>

<?php /* ==================== تب ۲: ساختار دیتابیس ==================== */ ?>
<?php if ($tab === 'db'): ?>
<section class="card mt3">
  <div class="card-head"><div><div class="card-title">🧩 وضعیت ساختار دیتابیس</div>
    <div class="card-sub">جدول‌ها، ستون‌ها، ایندکس‌ها و کلیدهای تنظیمات مورد نیاز نسخهٔ فعلی</div></div>
    <span class="badge <?= $missCnt > 0 ? 'b-orange' : 'b-green' ?>"><?= $missCnt > 0 ? fa_num($missCnt) . ' مورد ناقص' : 'بدون نقص' ?></span>
  </div>

  <?php if (($plan['error'] ?? '') !== ''): ?>
    <div class="alert a-warn">⚠️ بررسی ساختار ممکن نشد: <span class="mono ltr"><?= h((string)$plan['error']) ?></span></div>
  <?php else: ?>
    <div class="up-mig">
      <div class="mg <?= empty($plan['tables']) ? '' : 'bad' ?>"><span class="i">🗃</span><b><?= fa_num(count((array)$plan['tables'])) ?></b><span class="l">جدول ناقص</span></div>
      <div class="mg <?= empty($plan['columns']) ? '' : 'bad' ?>"><span class="i">📐</span><b><?= fa_num(count((array)$plan['columns'])) ?></b><span class="l">ستون ناقص</span></div>
      <div class="mg <?= empty($plan['indexes']) ? '' : 'bad' ?>"><span class="i">⚡️</span><b><?= fa_num(count((array)$plan['indexes'])) ?></b><span class="l">ایندکس ناقص</span></div>
      <div class="mg <?= empty($plan['modify']) ? '' : 'bad' ?>"><span class="i">🔧</span><b><?= fa_num(count((array)($plan['modify'] ?? []))) ?></b><span class="l">نیازمند اصلاح</span></div>
      <div class="mg <?= empty($plan['settings']) ? '' : 'bad' ?>"><span class="i">⚙️</span><b><?= fa_num(count((array)$plan['settings'])) ?></b><span class="l">کلید تنظیمات</span></div>
    </div>

    <?php if ($missCnt === 0): ?>
      <div class="alert a-ok mt3">✅ ساختار دیتابیس کامل است و هیچ مورد ناقصی پیدا نشد.</div>
    <?php else: ?>
      <div class="up-miss mt3">
        <?php foreach ([['tables', '🗃 جدول‌های ساخته‌نشده'], ['columns', '📐 ستون‌های ساخته‌نشده'], ['indexes', '⚡️ ایندکس‌های ساخته‌نشده'], ['modify', '🔧 ستون‌های نیازمند اصلاح']] as $sec):
            $rows = (array)($plan[$sec[0]] ?? []);
            if (!$rows) continue; ?>
          <div class="ms">
            <div class="h"><?= $sec[1] ?> <span class="badge b-orange"><?= fa_num(count($rows)) ?></span></div>
            <div class="tg">
              <?php foreach (array_slice($rows, 0, 40) as $it): ?><span class="mono ltr"><?= h((string)$it) ?></span><?php endforeach; ?>
              <?php if (count($rows) > 40): ?><span class="more">+<?= fa_num(count($rows) - 40) ?></span><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!empty($plan['settings'])): ?>
          <div class="ms">
            <div class="h">⚙️ کلیدهای تنظیمات جدید <span class="badge b-orange"><?= fa_num(count((array)$plan['settings'])) ?></span></div>
            <div class="tg">
              <?php foreach (array_slice((array)$plan['settings'], 0, 40) as $k): ?><span class="mono ltr"><?= h((string)$k) ?></span><?php endforeach; ?>
              <?php if (count((array)$plan['settings']) > 40): ?><span class="more">+<?= fa_num(count((array)$plan['settings']) - 40) ?></span><?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($canRun): ?>
    <div class="up-btns mt3">
      <form method="post" class="inline"><?= csrf_field() ?>
        <input type="hidden" name="act" value="schema"><input type="hidden" name="rt" value="db">
        <button class="btn <?= $missCnt > 0 ? 'btn-primary' : 'btn-ghost' ?>" type="submit">🧩 بررسی و تکمیل ساختار<?= $missCnt > 0 ? ' (' . fa_num($missCnt) . ')' : '' ?></button></form>
      <form method="post" class="inline"><?= csrf_field() ?>
        <input type="hidden" name="act" value="migrate"><input type="hidden" name="rt" value="db">
        <button class="btn btn-ghost" type="submit">🗄 اجرای فایل‌های مایگریشن</button></form>
    </div>
    <div class="hint mt2">«تکمیل ساختار» فقط موارد ناقص را می‌سازد و داده‌های موجود را حذف نمی‌کند.</div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php /* ==================== تب ۳: منبع و نصب دستی ==================== */ ?>
<?php if ($tab === 'source'): ?>
<div class="up-two mt3">

  <section class="card">
    <div class="card-head"><div><div class="card-title">⚙️ منبع به‌روزرسانی</div>
      <div class="card-sub">مخزن گیت‌هابی که نسخه‌ها از آن دریافت می‌شوند</div></div></div>

    <?php if (!$canRun): ?>
      <div class="alert a-warn">⚠️ ویرایش تنظیمات برای شما فعال نیست.</div>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="cfg">
        <input type="hidden" name="rt" value="source">

        <div class="form-grid g2">
          <div class="field"><label>مخزن (owner/repo)</label>
            <input class="mono ltr" type="text" name="update_repo" value="<?= h(Updater::repo()) ?>" placeholder="<?= h(Updater::DEFAULT_REPO) ?>"></div>
          <div class="field"><label>شاخه (branch)</label>
            <input class="mono ltr" type="text" name="update_branch" value="<?= h(Updater::branch()) ?>" placeholder="main">
            <?php if ((string)($info['default_branch'] ?? '') !== ''): ?><div class="hint">شاخهٔ پیش‌فرض مخزن: <span class="mono ltr"><?= h((string)$info['default_branch']) ?></span></div><?php endif; ?></div>
          <div class="field"><label>پوشهٔ داخلی (اختیاری)</label>
            <input class="mono ltr" type="text" name="update_subdir" value="<?= h((string)($info['subdir'] ?? '')) ?>" placeholder="مثلاً SR-BOT-main">
            <div class="hint">فقط اگر فایل‌های پروژه داخل یک پوشه در مخزن هستند. خالی = ریشهٔ مخزن (پیشنهادی). با «بررسی نسخهٔ جدید» به‌صورت خودکار هم پیدا می‌شود.</div></div>
          <div class="field"><label>قالب‌های قابل قبول برای مخزن</label>
            <div class="hint" style="line-height:2"><span class="mono ltr">owner/repo</span> · <span class="mono ltr">https://github.com/owner/repo</span> · <span class="mono ltr">github.com/owner/repo/tree/main</span> — همه به‌صورت خودکار به <span class="mono ltr">owner/repo</span> تبدیل می‌شوند.</div></div>
        </div>

        <div class="field"><label>توکن گیت‌هاب (فقط برای مخزن خصوصی)</label>
          <div class="up-eye">
            <input class="mono ltr" id="utok" type="password" name="update_token" value="<?= $tokenSet ? '********' : '' ?>" placeholder="ghp_…" autocomplete="new-password">
            <button class="icon-btn" type="button" data-eye="#utok" title="نمایش">👁</button>
          </div>
          <div class="hint">اگر تغییری لازم ندارید، مقدار <span class="mono ltr">********</span> را دست نزنید.</div>
        </div>

        <label class="up-sw">
          <input type="checkbox" name="update_auto_check" value="1" <?= $autoChk ? 'checked' : '' ?>>
          <span class="bx"></span>
          <span class="tx"><b>بررسی خودکار نسخه</b><i>هر چند ساعت یک‌بار بررسی و در صورت وجود نسخهٔ جدید، اطلاع به مدیران</i></span>
        </label>

        <div class="up-btns mt3"><button class="btn btn-primary" type="submit">💾 ذخیرهٔ تنظیمات</button></div>
      </form>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><div><div class="card-title">📦 نصب دستی از فایل ZIP</div>
      <div class="card-sub">وقتی سرور به گیت‌هاب دسترسی ندارد</div></div></div>

    <?php if (!$canRun): ?>
      <div class="alert a-warn">⚠️ دسترسی نصب برای شما فعال نیست.</div>
    <?php elseif (!$hasZip): ?>
      <div class="alert a-err">⛔️ افزونهٔ ZipArchive روی سرور فعال نیست؛ نصب از فایل ZIP ممکن نیست.</div>
    <?php else: ?>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="upload">
        <input type="hidden" name="rt" value="source">

        <label class="up-drop" id="upDrop">
          <input type="file" name="zip" id="upFile" accept=".zip" required>
          <span class="ic">🗃</span>
          <span class="t">فایل ZIP نسخهٔ جدید را رها کنید یا کلیک کنید</span>
          <span class="n" id="upFn">هیچ فایلی انتخاب نشده</span>
        </label>

        <div class="up-opts mt3">
          <label class="check"><input type="checkbox" name="backup_first" value="1" checked><span>پیش از نصب، بکاپ گرفته شود</span></label>
          <label class="check"><input type="checkbox" name="run_migrate" value="1" checked><span>ساختار دیتابیس پس از نصب به‌روز شود</span></label>
        </div>

        <div class="up-btns mt3">
          <button class="btn btn-primary" type="submit" data-confirm="فایل آپلودشده روی پروژه نصب شود؟">⬆️ آپلود و نصب</button>
        </div>
      </form>
      <div class="hint mt2">فایل باید همان ساختار مخزن را داشته باشد (همان ZIP گیت‌هاب یا پوشهٔ اصلی پروژه).</div>
    <?php endif; ?>
  </section>
</div>
<?php endif; ?>

<?php /* ==================== تب ۴: تاریخچه ==================== */ ?>
<?php if ($tab === 'history'): ?>
<section class="card mt3">
  <div class="card-head"><div><div class="card-title">🕒 تاریخچهٔ به‌روزرسانی</div>
    <div class="card-sub"><?= fa_num(count($hist)) ?> رکورد — <?= fa_num($okCnt) ?> موفق</div></div>
  </div>

  <?php if (!$hist): ?>
    <div class="empty">🕒 هنوز به‌روزرسانی‌ای انجام نشده است.</div>
  <?php else: ?>
    <div class="up-tl">
      <?php foreach ($hist as $r):
          $ok = !empty($r['ok']);
          $bk = (string)($r['backup'] ?? '');
          $to = (string)($r['to'] ?? '');
      ?>
        <div class="tl <?= $ok ? 'ok' : 'no' ?>">
          <span class="dot"></span>
          <div class="bd">
            <div class="t1">
              <b><?= $to === 'rollback' ? '↩️ بازگشت به نسخهٔ قبل' : '⬆️ نصب نسخه ' . h($to) ?></b>
              <span class="badge <?= $ok ? 'b-green' : 'b-red' ?>"><?= $ok ? 'موفق' : 'ناموفق' ?></span>
            </div>
            <div class="t2">
              <span>🕒 <?= h(to_jalali((string)($r['at'] ?? ''), true)) ?></span>
              <span>📦 از <b class="mono ltr"><?= h((string)($r['from'] ?? '—')) ?></b></span>
              <?php if ((int)($r['files'] ?? 0) > 0): ?><span>📄 <?= fa_num((int)$r['files']) ?> فایل</span><?php endif; ?>
            </div>
            <?php if ($bk !== ''): ?>
              <div class="t3">
                <span class="mono ltr xs"><?= h($bk) ?></span>
                <a class="btn xs btn-ghost" href="index.php?p=backup&tab=files&dl=<?= urlencode($bk) ?>">⬇️ دانلود بکاپ</a>
                <?php if ($canRun): ?>
                  <form method="post" class="inline"><?= csrf_field() ?>
                    <input type="hidden" name="act" value="rollback"><input type="hidden" name="rt" value="history">
                    <input type="hidden" name="name" value="<?= h($bk) ?>">
                    <button class="btn xs btn-red" type="submit" data-confirm="پروژه به این نقطه بازگردانده شود؟ فایل‌ها و دیتابیس بازنویسی می‌شوند.">↩️ بازگشت به این نقطه</button></form>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<script>
(function () {
  var drop = document.getElementById('upDrop'), fi = document.getElementById('upFile'), fn = document.getElementById('upFn');
  if (!drop || !fi) return;
  var show = function () {
    fn.textContent = fi.files && fi.files[0] ? fi.files[0].name : 'هیچ فایلی انتخاب نشده';
    drop.classList.toggle('has', !!(fi.files && fi.files[0]));
  };
  fi.addEventListener('change', show);
  ['dragenter', 'dragover'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); });
  });
  drop.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) { fi.files = e.dataTransfer.files; show(); }
  });
})();
</script>
