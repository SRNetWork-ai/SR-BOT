<?php
if (!can('backup.view')) { echo denyBox('این بخش برای شما فعال نیست.'); return; }
/**
 * پشتیبان‌گیری و بازگردانی — نسخهٔ گسترده
 * در نسخهٔ اشتراکی (SR_BACKUP_DB_ONLY) فقط دیتابیس پشتیبان‌گیری می‌شود و سورس خروجی نمی‌گیرد.
 */

$dbOnly = defined('SR_BACKUP_DB_ONLY') && SR_BACKUP_DB_ONLY;
$hasZip = class_exists('ZipArchive');
$canRun = can('backup.run');

$tab = (string)($_GET['tab'] ?? 'new');
if (!in_array($tab, ['new', 'files', 'restore', 'guide'], true)) $tab = 'new';
$fType = (string)($_GET['ft'] ?? '');
if (!in_array($fType, ['db', 'full', 'pre', 'upload'], true)) $fType = '';

/* ---------- دانلود فایل پشتیبان ---------- */
$dl = trim((string)($_GET['dl'] ?? ''));
if ($dl !== '') {
    $p = Backup::path($dl);
    if ($p === null) { flash('err', 'فایل پشتیبان پیدا نشد.'); back('backup', ['tab' => $tab]); }
    while (ob_get_level() > 0) ob_end_clean();
    $ext = strtolower((string)pathinfo($p, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($ext === 'zip' ? 'application/zip' : 'application/sql'));
    header('Content-Disposition: attachment; filename="' . basename($p) . '"');
    header('Content-Length: ' . (string)@filesize($p));
    header('X-Content-Type-Options: nosniff');
    readfile($p);
    exit;
}

/* ---------- عملیات ---------- */
$act = (string)($_POST['act'] ?? '');
if ($act !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('err', 'نشست منقضی شده است. دوباره تلاش کنید.'); back('backup'); }
    $rt = ['tab' => (string)($_POST['rt'] ?? $tab)];

    if ($act === 'new') { need('backup.run', 'backup');
        $type = (!$dbOnly && ptxt('type', 'db') === 'full') ? 'full' : 'db';
        $r = Backup::create($type, ptxt('note'), pchk('send') === 1);
        flash(!empty($r['ok']) ? 'ok' : 'err', h((string)($r['message'] ?? '')));
        back('backup', $rt);
    }

    if ($act === 'cfg') { need('backup.run', 'backup');
        DB::setSetting('backup_auto', (string)pchk('backup_auto'));
        DB::setSetting('backup_hours', (string)max(1, pint('backup_hours', 24)));
        DB::setSetting('backup_keep', (string)max(1, pint('backup_keep', 7)));
        DB::setSetting('backup_type', (!$dbOnly && ptxt('backup_type', 'db') === 'full') ? 'full' : 'db');
        DB::setSetting('backup_send_tg', (string)pchk('backup_send_tg'));
        DB::setSetting('backup_pass', mb_substr(trim((string)($_POST['backup_pass'] ?? '')), 0, 64));
        /* 0.0.2 #3-autopass-ui */
        DB::setSetting('backup_autopass', isset($_POST['backup_autopass']) ? '1' : '0');
        DB::loadSettings(true);
        flash('ok', '✅ تنظیمات پشتیبان‌گیری ذخیره شد.');
        back('backup', $rt);
    }

    if ($act === 'del') { need('backup.run', 'backup');
        $ok = Backup::delete(ptxt('name'));
        flash($ok ? 'ok' : 'err', $ok ? '🗑 فایل پشتیبان حذف شد.' : 'حذف فایل ناموفق بود.');
        back('backup', $rt);
    }

    if ($act === 'bulk') { need('backup.run', 'backup');
        $names = (array)($_POST['names'] ?? []);
        $op    = (string)($_POST['op'] ?? ptxt('op'));
        $done  = 0; $bad = 0;
        foreach ($names as $nm) {
            $nm = basename(trim((string)$nm));
            if ($nm === '') continue;
            if ($op === 'del')  { Backup::delete($nm) ? $done++ : $bad++; }
            elseif ($op === 'send') {
                $cap = "\u{1F4E6} <b>ارسال گروهی پشتیبان</b>\n" . $nm;
                Backup::sendTg($nm, $cap) ? $done++ : $bad++;
            }
        }
        if ($done > 0) flash('ok', '✅ ' . fa_num($done) . ' فایل پردازش شد.'
            . ($bad > 0 ? ' — ' . fa_num($bad) . ' مورد ناموفق.' : ''));
        else flash('err', 'هیچ فایلی انتخاب نشد یا عملیات ناموفق بود.');
        back('backup', $rt);
    }

    if ($act === 'send') { need('backup.run', 'backup');
        $name = ptxt('name');
        $ok = Backup::sendTg($name, "📦 <b>ارسال دستی پشتیبان</b>\n" . $name . "\nتاریخ: " . to_jalali(now(), true));
        flash($ok ? 'ok' : 'err', $ok ? '📤 فایل در تاپیک بکاپ ارسال شد.' : 'ارسال ناموفق بود؛ گروه گزارشات و تاپیک بکاپ را بررسی کنید.');
        back('backup', $rt);
    }

    if ($act === 'restore') { need('backup.run', 'backup');
        $withFiles = !$dbOnly && pchk('with_files') === 1;
        $r = Backup::restore(ptxt('name'), $withFiles);
        flash(!empty($r['ok']) ? 'ok' : 'err', h((string)($r['message'] ?? '')));
        back('backup', $rt);
    }

    if ($act === 'upload') { need('backup.run', 'backup');
        $u = Backup::saveUpload((array)($_FILES['file'] ?? []));
        if (empty($u['ok'])) { flash('err', h((string)($u['message'] ?? ''))); back('backup', $rt); }
        if (pchk('restore_now') === 1) {
            $r = Backup::restore((string)$u['name'], !$dbOnly && pchk('with_files') === 1);
            flash(!empty($r['ok']) ? 'ok' : 'err', h((string)$u['message']) . '<br>' . h((string)($r['message'] ?? '')));
        } else {
            flash('ok', h((string)$u['message']));
        }
        back('backup', $rt);
    }

    if ($act === 'prune') { need('backup.run', 'backup');
        $n = Backup::prune(max(1, pint('backup_keep', (int)DB::setting('backup_keep', 7))));
        flash('ok', '🧹 ' . fa_num($n) . ' فایل قدیمی پاک شد.');
        back('backup', $rt);
    }

    /* بررسی سلامت یک فایل پشتیبان */
    if ($act === 'peek') {
        $nm = ptxt('name');
        $p  = Backup::path($nm);
        if ($p === null) { flash('err', 'فایل پیدا نشد.'); back('backup', $rt); }
        $ext = strtolower((string)pathinfo($p, PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            if (!$hasZip) { flash('err', 'افزونهٔ ZipArchive روی سرور فعال نیست.'); back('backup', $rt); }
            $z = new ZipArchive();
            if ($z->open($p) !== true) { flash('err', 'فایل ZIP سالم نیست یا باز نمی‌شود.'); back('backup', $rt); }
            $cnt = $z->numFiles;
            $hasSql = false;
            for ($i = 0; $i < $cnt; $i++) {
                $en = (string)$z->getNameIndex($i);
                if (substr($en, -13) === 'database.sql' || substr($en, -4) === '.sql') { $hasSql = true; break; }
            }
            $z->close();
            flash('ok', '🔎 <b>' . h($nm) . '</b><br>فایل ZIP سالم است — ' . fa_num($cnt) . ' فایل داخل آن است'
                . ($hasSql ? ' و شامل خروجی دیتابیس می‌شود.' : ' ولی خروجی SQL در آن پیدا نشد!'));
            back('backup', $rt);
        }
        $tbl = 0; $ins = 0; $read = 0;
        $fh = @fopen($p, 'rb');
        if (!$fh) { flash('err', 'فایل باز نشد.'); back('backup', $rt); }
        while (!feof($fh) && $read < 8388608) {
            $chunk = (string)fread($fh, 262144);
            $read += strlen($chunk);
            $tbl  += substr_count($chunk, 'CREATE TABLE');
            $ins  += substr_count($chunk, 'INSERT INTO');
        }
        fclose($fh);
        $more = @filesize($p) > $read ? '≈' : '';
        flash('ok', '🔎 <b>' . h($nm) . '</b><br>جدول: ' . $more . fa_num($tbl)
            . ' — دستور درج: ' . $more . fa_num($ins)
            . ' — حجم: ' . h(human_bytes((int)@filesize($p)))
            . ($tbl > 0 ? '<br>✅ فایل سالم به نظر می‌رسد.' : '<br>⚠️ هیچ دستور CREATE TABLE پیدا نشد!'));
        back('backup', $rt);
    }
}

/* ==================== داده‌ها ==================== */
$S     = fn(string $k, $d = '') => DB::setting($k, $d);
$all   = Backup::all();
$st    = Backup::stats();
$dir   = Backup::dir();
$writable = is_dir($dir) && is_writable($dir);
$autoOn   = (int)$S('backup_auto', 1) === 1;
$keep     = max(1, (int)$S('backup_keep', 7));
$hours    = max(1, (int)$S('backup_hours', 24));
$autoType = $dbOnly ? 'db' : ((string)$S('backup_type', 'db') === 'full' ? 'full' : 'db');
$lastAt   = (string)$S('backup_last_at', '');

/* شمارش به تفکیک نوع + بزرگترین فایل */
$byType = ['db' => 0, 'full' => 0, 'pre' => 0, 'upload' => 0];
$big    = 0;
foreach ($all as $b) {
    $t = (string)$b['type'];
    if (isset($byType[$t])) $byType[$t]++;
    if ((int)$b['size'] > $big) $big = (int)$b['size'];
}

$list = $fType === '' ? $all : array_values(array_filter($all, fn($b) => (string)$b['type'] === $fType));

$freeB = 0; $totB = 0;
try { $freeB = (int)@disk_free_space($dir); $totB = (int)@disk_total_space($dir); } catch (Throwable $e) { $freeB = 0; $totB = 0; }
$usePc = $totB > 0 ? min(100, (int)round(($totB - $freeB) / $totB * 100)) : 0;

/* سقف آپلود فایل */
$toB = function (string $v): int {
    $v = trim($v); if ($v === '') return 0;
    $u = strtolower(substr($v, -1)); $n = (float)$v;
    if ($u === 'g') $n *= 1073741824; elseif ($u === 'm') $n *= 1048576; elseif ($u === 'k') $n *= 1024;
    return (int)$n;
};
$maxUp = min(array_filter([$toB((string)ini_get('upload_max_filesize')), $toB((string)ini_get('post_max_size'))]) ?: [0]);

$tabUrl = function (string $t) use ($fType): string {
    $u = 'index.php?p=backup&tab=' . $t;
    if ($fType !== '') $u .= '&ft=' . urlencode($fType);
    return $u;
};

$typeIcon = ['db' => '🗄', 'full' => '📦', 'pre' => '🛡', 'upload' => '⬆️'];
$typeCls  = ['db' => 'b-blue', 'full' => 'b-green', 'pre' => 'b-orange', 'upload' => 'b-purple'];

$ago = function (int $ts): string {
    if ($ts <= 0) return '—';
    $s = time() - $ts;
    if ($s < 60) return 'همین حالا';
    if ($s < 3600) return fa_num((int)($s / 60)) . ' دقیقه پیش';
    if ($s < 86400) return fa_num((int)($s / 3600)) . ' ساعت پیش';
    return fa_num((int)($s / 86400)) . ' روز پیش';
};
?>

<section class="bk-hero">
  <span class="bk-glow g1"></span>
  <span class="bk-glow g2"></span>
  <div class="bk-htop">
    <div class="bk-hic">💾</div>
    <div class="bk-htt">
      <h2>پشتیبان‌گیری و بازگردانی</h2>
      <p><?= $dbOnly
        ? 'در این نسخه فقط از دیتابیس پشتیبان گرفته می‌شود — سورس و فایل‌های پروژه خروجی نمی‌شوند.'
        : 'خروجی دیتابیس، بکاپ کامل پروژه، زمان‌بندی خودکار و بازگردانی امن در یک جا' ?></p>
    </div>
    <div class="bk-hact">
      <span class="bk-pill <?= $autoOn ? 'on' : 'off' ?>"><?= $autoOn ? '● بکاپ خودکار فعال' : '○ بکاپ خودکار خاموش' ?></span>
      <?php if ($canRun): ?>
        <form method="post" class="inline">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="new">
          <input type="hidden" name="type" value="db">
          <input type="hidden" name="send" value="1">
          <input type="hidden" name="rt" value="<?= h($tab) ?>">
          <button class="btn sm btn-primary">⚡️ بکاپ فوری دیتابیس</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="bk-cells">
    <div class="bk-cell b"><span class="i">🗂</span><span class="v"><?= fa_num((int)$st['count']) ?></span><span class="l">فایل پشتیبان</span></div>
    <div class="bk-cell c"><span class="i">💽</span><span class="v"><?= h(human_bytes((int)$st['size'])) ?></span><span class="l">حجم کل</span></div>
    <div class="bk-cell g"><span class="i">⏱</span><span class="v"><?= h($ago((int)$st['last'])) ?></span><span class="l">آخرین بکاپ</span></div>
    <div class="bk-cell o"><span class="i">🔁</span><span class="v"><?= h(Backup::nextRun()) ?></span><span class="l">نوبت بعدی</span></div>
    <div class="bk-cell"><span class="i">📦</span><span class="v"><?= h(human_bytes($big)) ?></span><span class="l">بزرگترین فایل</span></div>
    <div class="bk-cell <?= $writable ? 'g' : 'r' ?>"><span class="i"><?= $writable ? '✅' : '⛔️' ?></span><span class="v"><?= $writable ? 'آماده' : 'بدون دسترسی' ?></span><span class="l">پوشهٔ ذخیره</span></div>
  </div>

  <?php if ($totB > 0): ?>
    <div class="bk-disk">
      <div class="d-t"><span>فضای دیسک سرور</span><b><?= h(human_bytes($freeB)) ?> آزاد از <?= h(human_bytes($totB)) ?></b></div>
      <div class="d-bar"><span class="<?= $usePc > 90 ? 'hot' : ($usePc > 70 ? 'warm' : '') ?>" style="width:<?= $usePc ?>%"></span></div>
    </div>
  <?php endif; ?>
</section>

<nav class="bk-nav">
  <a class="bk-nv <?= $tab === 'new' ? 'on' : '' ?>" href="<?= h($tabUrl('new')) ?>"><span class="i">⚡️</span><span class="t">ساخت و زمان‌بندی</span></a>
  <a class="bk-nv <?= $tab === 'files' ? 'on' : '' ?>" href="<?= h($tabUrl('files')) ?>"><span class="i">🗂</span><span class="t">فایل‌ها</span><span class="n"><?= fa_num((int)$st['count']) ?></span></a>
  <a class="bk-nv <?= $tab === 'restore' ? 'on' : '' ?>" href="<?= h($tabUrl('restore')) ?>"><span class="i">♻️</span><span class="t">بازگردانی</span></a>
  <a class="bk-nv <?= $tab === 'guide' ? 'on' : '' ?>" href="<?= h($tabUrl('guide')) ?>"><span class="i">🛡</span><span class="t">راهنما و امنیت</span></a>
</nav>

<?php if (!$writable): ?>
  <div class="alert a-err mt3">⛔️ پوشهٔ <span class="mono ltr">storage/backups</span> قابل نوشتن نیست؛ در مدیر فایل هاست به پوشهٔ <span class="mono ltr">storage</span> دسترسی ۷۵۵ بدهید.</div>
<?php endif; ?>
<?php if ($dbOnly): ?>
  <div class="alert a-info mt3">🔒 نسخهٔ اشتراکی: پشتیبان فقط شامل <b>دیتابیس</b> است؛ فایل‌های سورس نه در خروجی قرار می‌گیرند و نه قابل بازگردانی هستند.</div>
<?php endif; ?>

<?php /* ==================== تب ۱: ساخت و زمان‌بندی ==================== */ ?>
<?php if ($tab === 'new'): ?>
<div class="bk-two mt3">

  <section class="card">
    <div class="card-head"><div><div class="card-title">📦 ساخت پشتیبان فوری</div>
      <div class="card-sub"><?= $dbOnly ? 'خروجی کامل دیتابیس در قالب فایل SQL' : 'خروجی دیتابیس یا بکاپ کامل پروژه' ?></div></div></div>

    <?php if (!$canRun): ?>
      <div class="alert a-warn">⚠️ شما فقط دسترسی مشاهده دارید؛ اجازهٔ ساخت یا بازگردانی بکاپ به شما داده نشده است.</div>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="new">
        <input type="hidden" name="rt" value="new">

        <?php if ($dbOnly): ?>
          <input type="hidden" name="type" value="db">
          <div class="bk-pick one">
            <label class="bk-op on">
              <span class="i">🗄</span>
              <span class="t">فقط دیتابیس (SQL)</span>
              <span class="d">همهٔ جدول‌ها، کاربران، سرویس‌ها و تراکنش‌ها</span>
            </label>
          </div>
        <?php else: ?>
          <div class="bk-pick">
            <label class="bk-op">
              <input type="radio" name="type" value="db" checked>
              <span class="i">🗄</span>
              <span class="t">فقط دیتابیس</span>
              <span class="d">فایل SQL — سریع و کم‌حجم؛ مناسب بکاپ روزانه</span>
            </label>
            <label class="bk-op<?= $hasZip ? '' : ' dis' ?>">
              <input type="radio" name="type" value="full" <?= $hasZip ? '' : 'disabled' ?>>
              <span class="i">📦</span>
              <span class="t">کامل (فایل‌ها + دیتابیس)</span>
              <span class="d">فایل ZIP — مناسب مهاجرت به هاست جدید<?= $hasZip ? '' : ' (ZipArchive غیرفعال است)' ?></span>
            </label>
          </div>
        <?php endif; ?>

        <div class="field mt3"><label>توضیح (اختیاری)</label>
          <input type="text" name="note" maxlength="120" placeholder="مانند: پیش از تغییر پنل"></div>

        <label class="check"><input type="checkbox" name="send" value="1" checked><span>پس از ساخت، در تاپیک بکاپ تلگرام ارسال شود</span></label>

        <div class="bk-row mt3">
          <button class="btn btn-primary" type="submit">💾 ساخت پشتیبان</button>
        </div>
      </form>

      <form method="post" class="mt3">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="prune">
        <input type="hidden" name="rt" value="new">
        <button class="btn btn-ghost sm" type="submit" data-confirm="فایل‌های قدیمی حذف شوند؟ آخرین <?= fa_num($keep) ?> فایل از هر نوع باقی می‌ماند.">🧹 پاکسازی فایل‌های قدیمی</button>
      </form>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><div><div class="card-title">🔁 بکاپ خودکار</div>
      <div class="card-sub">توسط کرانجاب هر ۱۰ دقیقه بررسی می‌شود</div></div>
      <span class="badge <?= $autoOn ? 'b-green' : 'b-gray' ?>"><?= $autoOn ? 'فعال' : 'خاموش' ?></span>
    </div>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="cfg">
      <input type="hidden" name="rt" value="new">

      <label class="bk-sw">
        <input type="checkbox" name="backup_auto" value="1" <?= $autoOn ? 'checked' : '' ?>>
        <span class="bx"></span>
        <span class="tx"><b>بکاپ خودکار فعال باشد</b><i>در فاصله‌های مشخص خودکار بکاپ ساخته می‌شود</i></span>
      </label>

      <div class="bk-grid3 mt3">
        <div class="field"><label>فاصلهٔ زمانی (ساعت)</label>
          <input class="mono" type="number" name="backup_hours" min="1" max="720" value="<?= (int)$hours ?>"></div>
        <div class="field"><label>تعداد نگه‌داری</label>
          <input class="mono" type="number" name="backup_keep" min="1" max="200" value="<?= (int)$keep ?>">
          <div class="hint">از هر نوع بکاپ</div></div>
        <div class="field"><label>نوع بکاپ خودکار</label>
          <?php if ($dbOnly): ?>
            <input type="hidden" name="backup_type" value="db">
            <input class="mono" type="text" value="دیتابیس" disabled>
          <?php else: ?>
            <select name="backup_type">
              <option value="db"<?= $autoType === 'db' ? ' selected' : '' ?>>دیتابیس</option>
              <option value="full"<?= $autoType === 'full' ? ' selected' : '' ?>>کامل</option>
            </select>
          <?php endif; ?>
        </div>
      </div>

      <label class="bk-sw mt3">
        <input type="checkbox" name="backup_send_tg" value="1" <?= (int)$S('backup_send_tg', 1) === 1 ? 'checked' : '' ?>>
        <span class="bx"></span>
        <span class="tx"><b>ارسال خودکار در تاپیک بکاپ</b><i>فایل پس از ساخت، در گروه گزارشات تلگرام فرستاده می‌شود</i></span>
      </label>

      <div class="field mt3"><label>🔒 رمز فایل‌های ZIP بکاپ (بسیار پیشنهاد می‌شود)</label>
        <input class="mono" type="text" name="backup_pass" value="<?= h((string)$S('backup_pass', '')) ?>" placeholder="خالی = بدون رمز" autocomplete="off">
        <!-- 0.0.2 #3-autopass-box -->
        <label style="display:block;margin-top:6px">
          <input type="checkbox" name="backup_autopass" value="1" <?= ((string)$S('backup_autopass', '1') === '1' ? 'checked' : '') ?>>
          ساخت خودکار رمز قوی وقتی این فیلد خالی است (رمز در تاپیک بکاپ ارسال می‌شود)
        </label>
        <div class="hint">با تنظیم رمز، فایل‌های ZIP با AES-256 قفل می‌شوند و بکاپ SQL هم پیش از ارسال به تلگرام داخل ZIP رمزدار قرار می‌گیرد. همین رمز هنگام بازگردانی استفاده می‌شود؛ آن را در جای امن نگه دارید. نیازمند PHP 7.2 به بالا است.</div></div>

      <div class="bk-info mt3">
        <div><span>آخرین اجرا</span><b><?= $lastAt !== '' ? h(to_jalali($lastAt, true)) : '—' ?></b></div>
        <div><span>نوبت بعدی</span><b><?= h(Backup::nextRun()) ?></b></div>
        <div><span>آخرین فایل</span><b class="mono ltr xs"><?= h((string)$st['last_name'] ?: '—') ?></b></div>
      </div>

      <?php if ($canRun): ?>
        <div class="bk-row mt3"><button class="btn btn-primary" type="submit">💾 ذخیرهٔ تنظیمات</button></div>
      <?php endif; ?>
    </form>
  </section>

</div>

<div class="bk-mini mt3">
  <div class="m"><span class="i b">🗄</span><b><?= fa_num($byType['db']) ?></b><span class="l">دیتابیس</span></div>
  <div class="m"><span class="i g">📦</span><b><?= fa_num($byType['full']) ?></b><span class="l">کامل</span></div>
  <div class="m"><span class="i o">🛡</span><b><?= fa_num($byType['pre']) ?></b><span class="l">پیش از به‌روزرسانی</span></div>
  <div class="m"><span class="i p">⬆️</span><b><?= fa_num($byType['upload']) ?></b><span class="l">آپلودشده</span></div>
</div>
<?php endif; ?>

<?php /* ==================== تب ۲: فایل‌ها ==================== */ ?>
<?php if ($tab === 'files'): ?>
<section class="card mt3">
  <div class="card-head">
    <div><div class="card-title">🗂 فایل‌های پشتیبان</div>
      <div class="card-sub"><span class="mono ltr"><?= h(str_replace(APP_ROOT, '', $dir)) ?></span></div></div>
    <div class="bk-find">
      <input type="search" id="bkq" placeholder="🔎 جستجوی نام فایل…" data-live-filter="#bkTable" data-live-count="#bkFound">
      <span class="cnt" id="bkFound"></span>
    </div>
  </div>

  <div class="bk-chips">
    <a class="chip <?= $fType === '' ? 'on' : '' ?>" href="index.php?p=backup&tab=files">همه <b><?= fa_num(count($all)) ?></b></a>
    <a class="chip <?= $fType === 'db' ? 'on' : '' ?>" href="index.php?p=backup&tab=files&ft=db">🗄 دیتابیس <b><?= fa_num($byType['db']) ?></b></a>
    <?php if (!$dbOnly): ?>
      <a class="chip <?= $fType === 'full' ? 'on' : '' ?>" href="index.php?p=backup&tab=files&ft=full">📦 کامل <b><?= fa_num($byType['full']) ?></b></a>
      <a class="chip <?= $fType === 'pre' ? 'on' : '' ?>" href="index.php?p=backup&tab=files&ft=pre">🛡 پیش‌بروزرسانی <b><?= fa_num($byType['pre']) ?></b></a>
    <?php endif; ?>
    <a class="chip <?= $fType === 'upload' ? 'on' : '' ?>" href="index.php?p=backup&tab=files&ft=upload">⬆️ آپلودی <b><?= fa_num($byType['upload']) ?></b></a>
  </div>

  <?php if ($canRun): ?>
    <form method="post" id="bkBulk" class="hidden-form">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="bulk">
      <input type="hidden" name="rt" value="files">
    </form>
    <div class="bk-bulk" id="bkBar" hidden>
      <span class="c"><b id="bkCnt">۰</b> فایل انتخاب شده</span>
      <div class="sp"></div>
      <button class="btn sm btn-ghost" type="submit" form="bkBulk" name="op" value="send">📤 ارسال به تلگرام</button>
      <button class="btn sm btn-red" type="submit" form="bkBulk" name="op" value="del" data-confirm="فایل‌های انتخاب‌شده حذف شوند؟">🗑 حذف</button>
      <button class="btn sm btn-ghost" type="button" id="bkClr">✖ انصراف</button>
    </div>
  <?php endif; ?>

  <?php if (!$list): ?>
    <div class="empty">📦 هنوز فایل پشتیبانی در این دسته نیست.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl bk-tbl" id="bkTable" data-enhance data-page-size="25">
        <thead><tr>
          <?php if ($canRun): ?><th class="w1" data-nosort><input type="checkbox" id="bkAll"></th><?php endif; ?>
          <th>نام فایل</th>
          <th>نوع</th>
          <th data-l="num">حجم</th>
          <th>تاریخ ساخت</th>
          <th class="w1" data-nosort>عملیات</th>
        </tr></thead>
        <tbody>
        <?php foreach ($list as $b):
            $t   = (string)$b['type'];
            $nm  = (string)$b['name'];
            $ext = strtolower((string)pathinfo($nm, PATHINFO_EXTENSION));
        ?>
          <tr>
            <?php if ($canRun): ?>
              <td><input type="checkbox" class="bkck" form="bkBulk" name="names[]" value="<?= h($nm) ?>"></td>
            <?php endif; ?>
            <td>
              <div class="bk-fn">
                <span class="fic <?= h($typeCls[$t] ?? 'b-gray') ?>"><?= $typeIcon[$t] ?? '📄' ?></span>
                <span class="nm">
                  <b class="mono ltr xs" data-copy="<?= h($nm) ?>"><?= h($nm) ?></b>
                  <i class="ext"><?= h(strtoupper($ext)) ?></i>
                </span>
              </div>
            </td>
            <td><span class="badge <?= h($typeCls[$t] ?? 'b-gray') ?>"><?= h(Backup::typeLabel($t)) ?></span></td>
            <td data-l="num" class="mono"><?= h(human_bytes((int)$b['size'])) ?></td>
            <td><span class="bk-dt"><b><?= h(to_jalali(date('Y-m-d H:i:s', (int)$b['time']), true)) ?></b><i><?= h($ago((int)$b['time'])) ?></i></span></td>
            <td>
              <div class="bk-acts">
                <a class="icon-btn" title="دانلود" href="index.php?p=backup&tab=files&dl=<?= urlencode($nm) ?>">⬇️</a>
                <?php if ($canRun): ?>
                  <form method="post" class="inline"><?= csrf_field() ?>
                    <input type="hidden" name="act" value="peek"><input type="hidden" name="rt" value="files">
                    <input type="hidden" name="name" value="<?= h($nm) ?>">
                    <button class="icon-btn" title="بررسی سلامت فایل">🔎</button></form>
                  <form method="post" class="inline"><?= csrf_field() ?>
                    <input type="hidden" name="act" value="send"><input type="hidden" name="rt" value="files">
                    <input type="hidden" name="name" value="<?= h($nm) ?>">
                    <button class="icon-btn" title="ارسال در تلگرام">📤</button></form>
                  <form method="post" class="inline"><?= csrf_field() ?>
                    <input type="hidden" name="act" value="restore"><input type="hidden" name="rt" value="files">
                    <input type="hidden" name="name" value="<?= h($nm) ?>">
                    <button class="icon-btn warn" title="بازگردانی" data-confirm="بازگردانی از این فایل انجام شود؟ دیتابیس فعلی بازنویسی می‌شود.">♻️</button></form>
                  <form method="post" class="inline"><?= csrf_field() ?>
                    <input type="hidden" name="act" value="del"><input type="hidden" name="rt" value="files">
                    <input type="hidden" name="name" value="<?= h($nm) ?>">
                    <button class="icon-btn danger" title="حذف" data-confirm="این فایل حذف شود؟">🗑</button></form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php /* ==================== تب ۳: بازگردانی ==================== */ ?>
<?php if ($tab === 'restore'): ?>
<div class="alert a-warn mt3">⚠️ بازگردانی، دیتابیس فعلی را <b>بازنویسی</b> می‌کند. پیش از اجرا، یک بکاپ تازه بگیرید و ربات را در ساعت کم‌ترافیک بازگردانی کنید.</div>

<div class="bk-two mt3">
  <section class="card">
    <div class="card-head"><div><div class="card-title">⬆️ آپلود فایل پشتیبان</div>
      <div class="card-sub">فرمت مجاز: <span class="mono ltr"><?= $dbOnly ? '.sql' : '.sql ، .zip' ?></span> — حداکثر <?= h($maxUp > 0 ? human_bytes($maxUp) : '—') ?></div></div></div>

    <?php if (!$canRun): ?>
      <div class="alert a-warn">⚠️ دسترسی بازگردانی برای شما فعال نیست.</div>
    <?php else: ?>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="upload">
        <input type="hidden" name="rt" value="restore">

        <label class="bk-drop" id="bkDrop">
          <input type="file" name="file" id="bkFile" accept="<?= $dbOnly ? '.sql' : '.sql,.zip' ?>" required>
          <span class="ic">📁</span>
          <span class="t">فایل را اینجا رها کنید یا کلیک کنید</span>
          <span class="n" id="bkFn">هیچ فایلی انتخاب نشده</span>
        </label>

        <label class="check mt3"><input type="checkbox" name="restore_now" value="1"><span>بلافاصله پس از آپلود، بازگردانی انجام شود</span></label>
        <?php if (!$dbOnly): ?>
          <label class="check"><input type="checkbox" name="with_files" value="1"><span>فایل‌های سورس هم بازگردانده شوند (فقط بکاپ کامل)</span></label>
        <?php endif; ?>

        <div class="bk-row mt3"><button class="btn btn-primary" type="submit">⬆️ آپلود فایل</button></div>
      </form>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><div><div class="card-title">♻️ بازگردانی از فایل موجود</div>
      <div class="card-sub">پیش از اجرا، یک بکاپ ایمنی خودکار ساخته می‌شود</div></div></div>

    <?php if (!$all): ?>
      <div class="empty">هنوز فایل پشتیبانی وجود ندارد.</div>
    <?php elseif (!$canRun): ?>
      <div class="empty">دسترسی لازم را ندارید.</div>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="restore">
        <input type="hidden" name="rt" value="restore">
        <div class="field"><label>انتخاب فایل</label>
          <select name="name" class="mono ltr">
            <?php foreach ($all as $b): ?>
              <option value="<?= h((string)$b['name']) ?>"><?= h((string)$b['name']) ?> — <?= h(human_bytes((int)$b['size'])) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (!$dbOnly): ?>
          <label class="check"><input type="checkbox" name="with_files" value="1"><span>بازگردانی فایل‌های سورس همراه دیتابیس</span></label>
        <?php endif; ?>
        <div class="bk-steps mt3">
          <div class="s"><b>۱</b> بکاپ ایمنی از وضعیت فعلی</div>
          <div class="s"><b>۲</b> اجرای دستورات SQL فایل</div>
          <div class="s"><b>۳</b> بررسی سلامت و گزارش نتیجه</div>
        </div>
        <div class="bk-row mt3">
          <button class="btn btn-red" type="submit" data-confirm="مطمئنید؟ دیتابیس فعلی با محتوای این فایل جایگزین می‌شود.">♻️ اجرای بازگردانی</button>
        </div>
      </form>
    <?php endif; ?>
  </section>
</div>
<?php endif; ?>

<?php /* ==================== تب ۴: راهنما ==================== */ ?>
<?php if ($tab === 'guide'): ?>
<div class="bk-two mt3">
  <section class="card">
    <div class="card-head"><div><div class="card-title">🛡 توصیه‌های امنیتی</div></div></div>
    <ul class="bk-tips">
      <li><b>خروج از سرور:</b> فایل بکاپ را فقط روی همین سرور نگه ندارید؛ ارسال خودکار تلگرام را روشن بگذارید.</li>
      <li><b>دسترسی وب:</b> پوشهٔ <span class="mono ltr">storage/</span> باید از طریق مرورگر قابل دسترسی نباشد.</li>
      <li><b>رمزها:</b> خروجی دیتابیس شامل توکن ربات و اطلاعات پنل‌هاست؛ فایل را جایی منتشر نکنید.</li>
      <li><b>تمرین بازگردانی:</b> هر چند وقت یک‌بار روی یک دیتابیس آزمایشی بازگردانی را تست کنید.</li>
      <li><b>پاکسازی:</b> مقدار «تعداد نگه‌داری» را متناسب با فضای دیسک تنظیم کنید.</li>
    </ul>
  </section>

  <section class="card">
    <div class="card-head"><div><div class="card-title">⚙️ اجرا از خط فرمان و کران</div></div></div>
    <div class="bk-code">
      <div class="ln"><span class="c"># کرانجاب پیشنهادی (هر ۵ دقیقه)</span></div>
      <div class="ln"><code>*/5 * * * * php <?= h(APP_ROOT) ?>/cron/tasks.php &gt;/dev/null 2&gt;&amp;1</code></div>
      <div class="ln"><span class="c"># بکاپ دستی دیتابیس</span></div>
      <div class="ln"><code>php <?= h(APP_ROOT) ?>/cli.php backup db</code></div>
      <?php if (!$dbOnly): ?>
      <div class="ln"><span class="c"># بکاپ کامل پروژه</span></div>
      <div class="ln"><code>php <?= h(APP_ROOT) ?>/cli.php backup full</code></div>
      <?php endif; ?>
      <div class="ln"><span class="c"># فهرست فایل‌ها و پاکسازی</span></div>
      <div class="ln"><code>php <?= h(APP_ROOT) ?>/cli.php backups</code></div>
      <div class="ln"><code>php <?= h(APP_ROOT) ?>/cli.php prune <?= (int)$keep ?></code></div>
    </div>

    <div class="bk-info mt3">
      <div><span>ZipArchive</span><b class="<?= $hasZip ? 'ok' : 'no' ?>"><?= $hasZip ? 'فعال' : 'غیرفعال' ?></b></div>
      <div><span>سقف آپلود</span><b><?= h($maxUp > 0 ? human_bytes($maxUp) : '—') ?></b></div>
      <div><span>حالت بکاپ</span><b><?= $dbOnly ? 'فقط دیتابیس' : 'دیتابیس + سورس' ?></b></div>
    </div>
  </section>
</div>

<section class="card mt3">
  <div class="card-head"><div><div class="card-title">📁 چه چیزی در بکاپ هست؟</div></div></div>
  <div class="bk-two">
    <div class="bk-box ok">
      <div class="hd">✅ ذخیره می‌شود</div>
      <ul>
        <li>تمام جدول‌های دیتابیس (کاربران، سرویس‌ها، پرداخت‌ها، تنظیمات)</li>
        <?php if (!$dbOnly): ?>
          <li>فایل‌های سورس و پوسته (در بکاپ کامل)</li>
          <li>فایل <span class="mono ltr">config.php</span> در بکاپ کامل</li>
        <?php endif; ?>
      </ul>
    </div>
    <div class="bk-box no">
      <div class="hd">⛔️ ذخیره نمی‌شود</div>
      <ul>
        <?php if ($dbOnly): ?><li>فایل‌های سورس و پیکربندی پروژه</li><?php endif; ?>
        <li>پوشهٔ بکاپ‌ها و فایل‌های به‌روزرسانی</li>
        <li>لاگ‌ها و پوشهٔ <span class="mono ltr">.git</span> / <span class="mono ltr">node_modules</span></li>
      </ul>
    </div>
  </div>
</section>
<?php endif; ?>

<script>
(function () {
  var bar = document.getElementById('bkBar');
  if (bar) {
    var all = document.getElementById('bkAll'), cnt = document.getElementById('bkCnt'),
        clr = document.getElementById('bkClr');
    var boxes = function () { return Array.prototype.slice.call(document.querySelectorAll('.bkck')); };
    var sync = function () {
      var on = boxes().filter(function (b) { return b.checked; });
      cnt.textContent = window.faDigits ? window.faDigits(String(on.length)) : String(on.length);
      bar.hidden = on.length === 0;
      boxes().forEach(function (b) { var tr = b.closest('tr'); if (tr) tr.classList.toggle('sel', b.checked); });
      if (all) all.checked = on.length > 0 && on.length === boxes().length;
    };
    document.addEventListener('change', function (e) { if (e.target && e.target.classList.contains('bkck')) sync(); });
    if (all) all.addEventListener('change', function () {
      boxes().forEach(function (b) { if (b.closest('tr').style.display !== 'none') b.checked = all.checked; });
      sync();
    });
    if (clr) clr.addEventListener('click', function () { boxes().forEach(function (b) { b.checked = false; }); sync(); });
    sync();
  }

  var drop = document.getElementById('bkDrop'), fi = document.getElementById('bkFile'), fn = document.getElementById('bkFn');
  if (drop && fi) {
    var show = function () { fn.textContent = fi.files && fi.files[0] ? fi.files[0].name : 'هیچ فایلی انتخاب نشده'; drop.classList.toggle('has', !!(fi.files && fi.files[0])); };
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
  }
})();
</script>
