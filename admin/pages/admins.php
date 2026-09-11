<?php
/**
 * مدیران پنل و دسترسی‌های سفارشی
 * متغیرهای در دسترس: $ADMIN, $CSRF و توابع کمکی admin/index.php
 */
declare(strict_types=1);

if (!canAny(['admins.view'])) { echo denyBox('بخش مدیران پنل فقط برای دارندگان دسترسی مدیریت مدیران قابل مشاهده است.'); return; }

$ME = (int)$ADMIN['id'];

/** تعداد مدیران کل فعال */
$superCount = static fn(): int => (int)DB::val("SELECT COUNT(*) FROM {p}admins WHERE role = 'super' AND active = 1", [], 0);

/* ==================== عملیات ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $act = pv('act');

    /* ---------- افزودن مدیر جدید ---------- */
    if ($act === 'create') {
        need('admins.create', 'admins');

        $u    = strtolower(trim(pv('username')));
        $pass = (string)($_POST['password'] ?? '');
        $name = ptxt('name', 128);
        $role = pv('role') === 'super' ? 'super' : 'admin';
        $tgId = pint('tg_id');
        $note = ptxt('note', 255);
        $perms = (array)($_POST['perms'] ?? []);

        if (!preg_match('/^[a-z0-9_.-]{3,64}$/', $u)) {
            flash('err', 'نام کاربری باید ۳ تا ۶۴ کاراکتر و فقط شامل حروف انگلیسی، عدد، نقطه، خط تیره و زیرخط باشد.');
            back('admins');
        }
        if (mb_strlen($pass) < 8) {
            flash('err', 'رمز عبور باید حداقل ۸ کاراکتر باشد.');
            back('admins');
        }
        if (DB::val('SELECT COUNT(*) FROM {p}admins WHERE username = :u', [':u' => $u], 0) > 0) {
            flash('err', 'این نام کاربری قبلاً ثبت شده است.');
            back('admins');
        }
        if ($role === 'super' && !isSuper()) {
            flash('err', 'فقط مدیر کل می‌تواند مدیر کل جدید بسازد.');
            back('admins');
        }

        $newId = DB::insert('admins', [
            'username'      => $u,
            'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
            'name'          => $name !== '' ? $name : $u,
            'role'          => $role,
            'perms'         => $role === 'super' ? jenc([Perm::ALL]) : Perm::encode($perms),
            'tg_id'         => $tgId > 0 ? $tgId : null,
            'active'        => 1,
            'note'          => $note,
            'created_at'    => now(),
        ]);

        app_log('info', 'مدیر جدید ساخته شد', ['id' => $newId, 'username' => $u, 'by' => $ME]);
        flash('ok', '✅ مدیر <b>' . h($u) . '</b> با موفقیت افزوده شد.');
        back('admins', ['a' => $newId]);
    }

    /* ---------- ذخیره‌ی دسترسی‌ها و مشخصات ---------- */
    if ($act === 'save') {
        need('admins.edit', 'admins');

        $id  = pint('id');
        $row = DB::one('SELECT * FROM {p}admins WHERE id = :id', [':id' => $id]);
        if (!$row) { flash('err', 'مدیر یافت نشد.'); back('admins'); }

        $role  = pv('role') === 'super' ? 'super' : 'admin';
        $perms = (array)($_POST['perms'] ?? []);
        $name  = ptxt('name', 128);
        $tgId  = pint('tg_id');
        $note  = ptxt('note', 255);

        /* محافظت: فقط مدیر کل می‌تواند نقش کل بدهد یا بگیرد */
        if (($role === 'super' || (string)$row['role'] === 'super') && !isSuper()) {
            flash('err', 'تغییر نقش مدیر کل فقط توسط مدیر کل امکان‌پذیر است.');
            back('admins', ['a' => $id]);
        }

        /* محافظت: آخرین مدیر کل نباید تنزل پیدا کند */
        if ((string)$row['role'] === 'super' && $role !== 'super' && $superCount() <= 1) {
            flash('err', '⚠️ حداقل یک مدیر کل فعال باید باقی بماند.');
            back('admins', ['a' => $id]);
        }

        DB::update('admins', [
            'name'  => $name !== '' ? $name : (string)$row['username'],
            'role'  => $role,
            'perms' => $role === 'super' ? jenc([Perm::ALL]) : Perm::encode($perms),
            'tg_id' => $tgId > 0 ? $tgId : null,
            'note'  => $note,
        ], 'id = :id', [':id' => $id]);

        app_log('info', 'دسترسی‌های مدیر به‌روز شد', ['id' => $id, 'by' => $ME]);
        flash('ok', '✅ دسترسی‌های <b>' . h((string)$row['username']) . '</b> ذخیره شد.');
        back('admins', ['a' => $id]);
    }

    /* ---------- تغییر رمز ---------- */
    if ($act === 'passwd') {
        $id = pint('id');
        if ($id !== $ME) need('admins.edit', 'admins');

        $row = DB::one('SELECT * FROM {p}admins WHERE id = :id', [':id' => $id]);
        if (!$row) { flash('err', 'مدیر یافت نشد.'); back('admins'); }
        if ((string)$row['role'] === 'super' && $id !== $ME && !isSuper()) {
            flash('err', 'اجازه‌ی تغییر رمز مدیر کل را ندارید.');
            back('admins');
        }

        $pass = (string)($_POST['password'] ?? '');
        if (mb_strlen($pass) < 8) {
            flash('err', 'رمز عبور باید حداقل ۸ کاراکتر باشد.');
            back('admins', ['a' => $id]);
        }

        DB::update('admins', ['password_hash' => password_hash($pass, PASSWORD_DEFAULT)], 'id = :id', [':id' => $id]);
        app_log('warn', 'رمز عبور مدیر تغییر کرد', ['id' => $id, 'by' => $ME]);
        flash('ok', '🔑 رمز عبور با موفقیت تغییر کرد.');
        back('admins', ['a' => $id]);
    }

    /* ---------- فعال / غیرفعال ---------- */
    if ($act === 'toggle') {
        need('admins.edit', 'admins');

        $id  = pint('id');
        $row = DB::one('SELECT * FROM {p}admins WHERE id = :id', [':id' => $id]);
        if (!$row) { flash('err', 'مدیر یافت نشد.'); back('admins'); }
        if ($id === $ME) { flash('err', 'نمی‌توانید حساب خودتان را غیرفعال کنید.'); back('admins'); }

        $new = (int)($row['active'] ?? 1) === 1 ? 0 : 1;
        if ($new === 0 && (string)$row['role'] === 'super' && $superCount() <= 1) {
            flash('err', '⚠️ حداقل یک مدیر کل فعال باید باقی بماند.');
            back('admins');
        }

        DB::update('admins', ['active' => $new, 'token' => null, 'token_at' => null], 'id = :id', [':id' => $id]);
        flash('ok', $new === 1 ? '✅ حساب فعال شد.' : '⏸ حساب غیرفعال شد.');
        back('admins');
    }

    /* ---------- حذف ---------- */
    if ($act === 'del') {
        need('admins.delete', 'admins');

        $id  = pint('id');
        $row = DB::one('SELECT * FROM {p}admins WHERE id = :id', [':id' => $id]);
        if (!$row) { flash('err', 'مدیر یافت نشد.'); back('admins'); }
        if ($id === $ME) { flash('err', 'نمی‌توانید حساب خودتان را حذف کنید.'); back('admins'); }
        if ((string)$row['role'] === 'super' && $superCount() <= 1) {
            flash('err', '⚠️ آخرین مدیر کل قابل حذف نیست.');
            back('admins');
        }

        DB::delete('admins', 'id = :id', [':id' => $id]);
        app_log('warn', 'مدیر حذف شد', ['id' => $id, 'username' => $row['username'], 'by' => $ME]);
        flash('ok', '🗑 مدیر <b>' . h((string)$row['username']) . '</b> حذف شد.');
        back('admins');
    }
}

/* ==================== داده‌ها ==================== */
$admins = DB::all('SELECT * FROM {p}admins ORDER BY (role = \'super\') DESC, id ASC');
$sel    = (int)($_GET['a'] ?? 0);
$editing = null;
foreach ($admins as $a) { if ((int)$a['id'] === $sel) $editing = $a; }

$PMAP    = Perm::map();
$PRESETS = Perm::presets();
$ALLKEYS = Perm::allKeys();

$cntAll    = count($admins);
$cntActive = 0;
$cntSuper  = 0;
foreach ($admins as $a) {
    if ((int)($a['active'] ?? 1) === 1) $cntActive++;
    if ((string)$a['role'] === 'super') $cntSuper++;
}
$cntPerm = count($ALLKEYS);

/** رسم ماتریس دسترسی با شمارنده و جستجو */
$pmRender = static function (array $PMAP, array $current, bool $disabled = false, string $ns = 'n'): void {
    $isFull = in_array(Perm::ALL, $current, true);
    ?>
    <div class="pm-bar">
      <input type="search" class="pm-q" data-ns="<?= h($ns) ?>" placeholder="🔎 جستجو در دسترسی‌ها…">
      <?php if (!$disabled): ?>
        <button type="button" class="pm-mini" data-pm-all="<?= h($ns) ?>">انتخاب همه</button>
        <button type="button" class="pm-mini" data-pm-none="<?= h($ns) ?>">پاک کردن همه</button>
      <?php endif; ?>
      <span class="pm-tot" data-pm-tot="<?= h($ns) ?>"></span>
    </div>

    <div class="pm-grid" data-pm-grid="<?= h($ns) ?>">
      <?php foreach ($PMAP as $gk => $g): $items = (array)($g['items'] ?? []); ?>
        <div class="pm-card" data-pm-sec="<?= h((string)$gk) ?>">
          <div class="pm-h">
            <span class="i"><?= h((string)($g['icon'] ?? '🔑')) ?></span>
            <span class="t"><?= h((string)($g['title'] ?? $gk)) ?></span>
            <span class="c" data-pm-cnt>0/<?= fa_num((string)count($items)) ?></span>
            <?php if (!$disabled): ?>
              <button type="button" class="pm-mini" data-pm-sec-all>همه</button>
            <?php endif; ?>
          </div>
          <div class="pm-items">
            <?php foreach ($items as $key => $label): ?>
              <label class="pm-i" data-q="<?= h(mb_strtolower((string)$key . ' ' . (string)$label)) ?>">
                <input type="checkbox" name="perms[]" value="<?= h((string)$key) ?>"
                       <?= ($isFull || in_array((string)$key, $current, true)) ? 'checked' : '' ?>
                       <?= $disabled ? 'disabled' : '' ?>>
                <span class="tx">
                  <b><?= h((string)$label) ?></b>
                  <i><?= h((string)$key) ?></i>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
};

/** کارت پیش‌تنظیم‌ها */
$presetRow = static function (array $PRESETS, string $ns): void {
    ?>
    <div class="ad-presets">
      <?php foreach ($PRESETS as $pk => $p): ?>
        <button type="button" class="ad-preset" data-preset="<?= h((string)$pk) ?>" data-ns="<?= h($ns) ?>">
          <span class="t"><?= h((string)($p['title'] ?? $pk)) ?></span>
          <span class="d"><?= h((string)($p['desc'] ?? '')) ?></span>
          <span class="n"><?= in_array(Perm::ALL, (array)($p['perms'] ?? []), true) ? 'همهٔ دسترسی‌ها' : fa_num((string)count((array)($p['perms'] ?? []))) . ' دسترسی' ?></span>
        </button>
      <?php endforeach; ?>
    </div>
    <?php
};
?>

<style>
/* ===== admins page ===== */
.ad-hero{position:relative;overflow:hidden;border-radius:18px;padding:18px 20px;margin:0 0 14px;
  background:linear-gradient(135deg,rgba(38,211,232,.14),rgba(91,140,255,.12)),var(--surface);
  border:1px solid var(--border);box-shadow:var(--shadow)}
.ad-hero::after{content:"";position:absolute;inset-inline-end:-80px;top:-90px;width:240px;height:240px;border-radius:50%;
  background:radial-gradient(circle,rgba(38,211,232,.18),transparent 70%);pointer-events:none}
.ad-top{display:flex;gap:14px;align-items:flex-start;position:relative;z-index:1}
.ad-ic{width:52px;height:52px;border-radius:15px;flex:0 0 auto;display:grid;place-items:center;font-size:25px;
  background:var(--grad-soft);border:1px solid var(--border)}
.ad-hh{min-width:0;flex:1}
.ad-hh h3{margin:0 0 5px;font-size:16.5px;display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.ad-hh .s{color:var(--muted);font-size:12.5px;line-height:1.95}
.ad-cells{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:15px;position:relative;z-index:1}
.ad-cell{background:var(--surface-2);border:1px solid var(--border);border-radius:14px;padding:11px 13px;text-align:center}
.ad-cell .v{font-family:var(--font-num);font-size:19px;font-weight:800}
.ad-cell .l{color:var(--muted);font-size:11px;margin-top:3px}
.ad-cell.g .v{color:var(--green)}
.ad-cell.y .v{color:var(--orange)}
.ad-cell.b .v{color:var(--accent-text)}

.ad-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.ad-row{display:grid;grid-template-columns:auto minmax(0,1fr);gap:12px;align-items:start;
  background:var(--surface-2);border:1px solid var(--border);border-radius:15px;padding:13px 14px;
  border-inline-start:3px solid var(--border);transition:.15s}
.ad-row:hover{border-color:var(--accent)}
.ad-row.sup{border-inline-start-color:var(--orange)}
.ad-row.me{border-inline-start-color:var(--accent)}
.ad-row.off{opacity:.6}
.ad-av{width:44px;height:44px;border-radius:13px;display:grid;place-items:center;font-weight:800;font-size:17px;
  background:var(--grad-soft);border:1px solid var(--border);color:var(--accent-text);font-family:var(--font-num)}
.ad-row.sup .ad-av{background:linear-gradient(135deg,rgba(255,169,46,.22),rgba(255,169,46,.08));color:var(--orange)}
.ad-nm{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-bottom:4px}
.ad-nm b{font-size:13.5px}
.ad-nm .u{font-size:11px;color:var(--muted);font-family:var(--font-num);direction:ltr}
.ad-meta{display:flex;flex-wrap:wrap;gap:5px 13px;font-size:11px;color:var(--muted);line-height:1.9;margin:5px 0 9px}
.ad-meta .m{display:inline-flex;gap:5px;align-items:center}
.ad-meta .m b{color:var(--text-dim);font-weight:600;font-family:var(--font-num)}
.ad-acts{display:flex;flex-wrap:wrap;gap:6px}
.ad-acts form{margin:0}
.ad-pbar{height:5px;border-radius:99px;background:var(--surface-3);overflow:hidden;margin:0 0 8px;max-width:230px}
.ad-pbar i{display:block;height:100%;border-radius:99px;background:var(--grad)}

.ad-presets{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;margin:0 0 14px}
.ad-preset{text-align:start;background:var(--surface-2);border:1px solid var(--border);border-radius:13px;
  padding:11px 12px;cursor:pointer;font:inherit;color:var(--text);display:grid;gap:4px;transition:.15s}
.ad-preset:hover{border-color:var(--accent);transform:translateY(-1px)}
.ad-preset .t{font-size:12.5px;font-weight:700}
.ad-preset .d{font-size:11px;color:var(--muted);line-height:1.85}
.ad-preset .n{font-size:10.5px;color:var(--accent-text);font-family:var(--font-num)}

.pm-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 12px}
.pm-bar input[type=search]{flex:1 1 200px;min-width:160px}
.pm-mini{background:var(--surface-3);border:1px solid var(--border);color:var(--text-dim);border-radius:9px;
  padding:5px 10px;font:inherit;font-size:11px;cursor:pointer;transition:.15s}
.pm-mini:hover{border-color:var(--accent);color:var(--text)}
.pm-tot{font-size:11.5px;color:var(--muted);font-family:var(--font-num)}
.pm-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:11px}
.pm-card{background:var(--surface-2);border:1px solid var(--border);border-radius:14px;padding:11px 12px}
.pm-h{display:flex;align-items:center;gap:7px;margin:0 0 9px;padding-bottom:8px;border-bottom:1px solid var(--border)}
.pm-h .i{font-size:15px}
.pm-h .t{font-size:12.5px;font-weight:700;flex:1;min-width:0}
.pm-h .c{font-size:10.5px;color:var(--muted);font-family:var(--font-num)}
.pm-items{display:grid;gap:5px}
.pm-i{display:flex;gap:8px;align-items:flex-start;background:var(--surface);border:1px solid transparent;
  border-radius:10px;padding:7px 9px;cursor:pointer;transition:.12s}
.pm-i:hover{border-color:var(--border)}
.pm-i input{margin-top:3px;flex:0 0 auto}
.pm-i .tx{min-width:0}
.pm-i .tx b{display:block;font-size:11.8px;font-weight:600;line-height:1.75}
.pm-i .tx i{display:block;font-size:10px;color:var(--muted);font-style:normal;font-family:var(--font-num);direction:ltr}
.pm-i:has(input:checked){border-color:rgba(91,140,255,.40);background:rgba(91,140,255,.07)}
.pm-off{opacity:.5;pointer-events:none}

.ad-pw{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.ad-pw input{flex:1 1 180px}
.ad-str{height:5px;border-radius:99px;background:var(--surface-3);overflow:hidden;margin-top:7px;max-width:260px}
.ad-str i{display:block;height:100%;width:0;border-radius:99px;background:var(--red);transition:.2s}
.ad-danger{background:rgba(255,107,107,.07);border:1px solid rgba(255,107,107,.30);border-radius:14px;padding:13px}
.ad-danger .lg{color:var(--red);font-size:12.5px;font-weight:700;margin-bottom:5px}
.ad-empty{text-align:center;color:var(--muted);font-size:12.5px;padding:24px 10px;line-height:2}
.ad-row>*{min-width:0}
.ad-meta .m b{overflow-wrap:anywhere}
.ad-tg{display:inline-flex;align-items:center;gap:5px}
.ad-tg.warn{color:var(--orange)}
/* fixed80: واکنش‌گرا برای تبلت و موبایل */
@media (max-width:1000px){
  .ad-list,.ad-presets{grid-template-columns:1fr}
  .pm-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .ad-cells{grid-template-columns:repeat(2,1fr)}
}
@media (max-width:640px){
  .ad-hero{padding:14px;border-radius:15px;margin-bottom:12px}
  .ad-hero::after{display:none}
  .ad-top{gap:10px}
  .ad-ic{width:42px;height:42px;font-size:20px;border-radius:12px}
  .ad-hh h3{font-size:15px;gap:6px}
  .ad-hh .s{font-size:12px;line-height:1.8}
  .ad-cells{gap:7px;margin-top:12px}
  .ad-cell{padding:9px 8px;border-radius:12px}
  .ad-cell .v{font-size:16px}
  .pm-grid{grid-template-columns:1fr}
  .ad-row{grid-template-columns:auto minmax(0,1fr);gap:10px;padding:11px 12px;border-radius:13px}
  .ad-av{width:36px;height:36px;font-size:14px;border-radius:11px}
  .ad-nm{gap:5px}
  .ad-nm b{font-size:13px}
  .ad-nm .badge{font-size:10px;padding:2px 7px}
  .ad-meta{gap:4px 10px;font-size:10.5px}
  .ad-pbar{max-width:none}
  .ad-acts{gap:5px}
  .ad-acts>.btn,.ad-acts>form{flex:1 1 calc(50% - 3px);min-width:0}
  .ad-acts>form{display:flex}
  .ad-acts .btn{width:100%;justify-content:center;white-space:nowrap}
  .card-head.tight{flex-wrap:wrap;gap:8px}
  #adQ{max-width:none!important;width:100%}
  .pm-bar input[type=search]{flex-basis:100%}
  .pm-mini{padding:7px 10px}
  .ad-pw input{flex-basis:100%}
  .ad-pw .pm-mini{width:100%;text-align:center;padding:9px}
  .ad-str{max-width:none}
  .ad-preset{padding:10px 11px}
  .ad-danger{padding:11px}
  details.card>summary{font-size:13px!important}
}
</style>

<div class="ad-hero">
  <div class="ad-top">
    <div class="ad-ic">🛡️</div>
    <div class="ad-hh">
      <h3>مدیران پنل
        <span class="badge b-blue"><?= fa_num((string)$cntActive) ?> فعال</span>
        <?php if ($cntSuper > 0): ?><span class="badge b-orange"><?= fa_num((string)$cntSuper) ?> مدیر کل</span><?php endif; ?>
        <?php $twoFa = (string)DB::setting('admin_2fa', '0') === '1'; ?>
        <a class="badge <?= $twoFa ? 'b-green' : 'b-gray' ?>" href="index.php?p=settings#tab-adv" style="text-decoration:none" title="تنظیم ورود دومرحله‌ای در تنظیمات"><?= $twoFa ? '🔐 ورود دومرحله‌ای فعال' : '🔓 ورود دومرحله‌ای خاموش' ?></a>
      </h3>
      <div class="s">
        برای هر مدیر دقیقاً مشخص کنید به کدام بخش و کدام عملیات دسترسی داشته باشد.<br>
        مدیر کل همیشه به همهٔ بخش‌ها دسترسی دارد و حداقل یک مدیر کل فعال باید باقی بماند.
      </div>
    </div>
  </div>

  <div class="ad-cells">
    <div class="ad-cell"><div class="v"><?= fa_num((string)$cntAll) ?></div><div class="l">کل مدیران</div></div>
    <div class="ad-cell g"><div class="v"><?= fa_num((string)$cntActive) ?></div><div class="l">حساب فعال</div></div>
    <div class="ad-cell y"><div class="v"><?= fa_num((string)$cntSuper) ?></div><div class="l">مدیر کل</div></div>
    <div class="ad-cell b"><div class="v"><?= fa_num((string)$cntPerm) ?></div><div class="l">دسترسی قابل تنظیم</div></div>
  </div>
</div>

<?php if (can('admins.create')): ?>
  <details class="card compact" <?= $cntAll <= 1 ? 'open' : '' ?>>
    <summary style="cursor:pointer;font-size:13.5px;font-weight:700">➕ افزودن مدیر جدید</summary>

    <form method="post" class="mt4" id="adNewForm">
      <?= csrf_field() ?><input type="hidden" name="act" value="create">

      <div class="form-grid g2">
        <div class="field">
          <label>نام کاربری ورود *</label>
          <input name="username" class="mono ltr" required minlength="3" maxlength="64" placeholder="support1">
          <div class="hint">۳ تا ۶۴ کاراکتر — حروف انگلیسی، عدد، نقطه، خط تیره و زیرخط</div>
        </div>
        <div class="field">
          <label>رمز عبور *</label>
          <div class="ad-pw">
            <input name="password" id="adPw" class="mono ltr" type="text" required minlength="8" placeholder="حداقل ۸ کاراکتر">
            <button type="button" class="pm-mini" id="adGen">🎲 ساخت خودکار</button>
          </div>
          <div class="ad-str"><i id="adStr"></i></div>
        </div>
        <div class="field">
          <label>نام نمایشی</label>
          <input name="name" maxlength="128" placeholder="مثلاً کارشناس پشتیبانی">
        </div>
        <div class="field">
          <label>نقش</label>
          <select name="role" id="adRoleNew">
            <option value="admin">مدیر محدود (با دسترسی دلخواه)</option>
            <?php if (isSuper()): ?><option value="super">👑 مدیر کل (دسترسی کامل)</option><?php endif; ?>
          </select>
        </div>
        <div class="field">
          <label>شناسهٔ تلگرام (اختیاری)</label>
          <input name="tg_id" class="mono ltr" inputmode="numeric" placeholder="123456789" pattern="[0-9۰-۹]*">
          <div class="hint">برای گزارش‌های مدیریتی و <b>ورود دومرحله‌ای (2FA)</b> لازم است — شناسهٔ عددی را از <span class="mono ltr">@userinfobot</span> بگیرید. مدیر باید یک‌بار به ربات /start بدهد.</div>
        </div>
        <div class="field">
          <label>یادداشت</label>
          <input name="note" maxlength="255" placeholder="مثلاً شیفت شب">
        </div>
      </div>

      <div class="card-title mt4" style="font-size:13px">⚡ پیش‌تنظیم‌های آماده</div>
      <div class="card-sub" style="margin-bottom:10px">یکی را بزنید تا تیک‌های پایین خودکار انتخاب شوند؛ بعد می‌توانید دستی تغییر دهید.</div>
      <?php $presetRow($PRESETS, 'new'); ?>

      <div class="card-title" style="font-size:13px">🔐 دسترسی‌ها</div>
      <div class="card-sub" style="margin-bottom:10px">اگر نقش «مدیر کل» باشد، این فهرست نادیده گرفته می‌شود.</div>
      <div id="adPmNew"><?php $pmRender($PMAP, [], false, 'new'); ?></div>

      <div class="sticky-acts"><button class="btn btn-primary">➕ ساخت مدیر</button></div>
    </form>
  </details>
<?php endif; ?>

<div class="card compact mt4">
  <div class="card-head tight">
    <div>
      <div class="card-title">📋 فهرست مدیران</div>
      <div class="card-sub">برای تنظیم دقیق دسترسی، روی دکمهٔ «دسترسی‌ها» بزنید.</div>
    </div>
    <input type="search" id="adQ" placeholder="🔎 جستجوی مدیر…" style="max-width:220px">
  </div>

  <?php if ($admins === []): ?>
    <div class="ad-empty">هنوز مدیری ثبت نشده است.</div>
  <?php else: ?>
    <div class="ad-list" id="adList">
      <?php foreach ($admins as $a):
          $id    = (int)$a['id'];
          $isSup = (string)$a['role'] === 'super';
          $isOn  = (int)($a['active'] ?? 1) === 1;
          $isMe  = $id === $ME;
          $nm    = trim((string)($a['name'] ?? '')) !== '' ? (string)$a['name'] : (string)$a['username'];
          $ini   = mb_strtoupper(mb_substr($nm, 0, 1));
          $pl    = Perm::of($a);
          $pc    = in_array(Perm::ALL, $pl, true) ? $cntPerm : count($pl);
          $pp    = $cntPerm > 0 ? (int)round(($pc / $cntPerm) * 100) : 0;
          $ll    = (string)($a['last_login'] ?? '');
      ?>
        <div class="ad-row<?= $isSup ? ' sup' : '' ?><?= $isMe ? ' me' : '' ?><?= $isOn ? '' : ' off' ?><?= $sel === $id ? ' on' : '' ?>"
             data-q="<?= h(mb_strtolower($nm . ' ' . (string)$a['username'] . ' ' . (string)($a['note'] ?? ''))) ?>">
          <div class="ad-av"><?= h($ini) ?></div>
          <div>
            <div class="ad-nm">
              <b><?= h($nm) ?></b>
              <span class="u">@<?= h((string)$a['username']) ?></span>
              <span class="badge <?= $isSup ? 'b-orange' : 'b-gray' ?>"><?= $isSup ? '👑 مدیر کل' : 'مدیر محدود' ?></span>
              <span class="badge <?= $isOn ? 'b-green' : 'b-red' ?>"><?= $isOn ? 'فعال' : 'غیرفعال' ?></span>
              <?php if ($isMe): ?><span class="badge b-blue">حساب شما</span><?php endif; ?>
            </div>

            <div class="ad-pbar"><i style="width:<?= (int)$pp ?>%"></i></div>
            <div class="ad-meta">
              <span class="m">🔐 دسترسی: <b><?= $isSup ? 'کامل' : fa_num((string)$pc) . '/' . fa_num((string)$cntPerm) ?></b></span>
              <span class="m">🕒 ورود آخر: <b><?= $ll !== '' ? fa_num(substr($ll, 0, 16)) : '—' ?></b></span>
              <?php if ((string)($a['last_ip'] ?? '') !== ''): ?>
                <span class="m">🌐 <b class="ltr"><?= h((string)$a['last_ip']) ?></b></span>
              <?php endif; ?>
              <?php if ((int)($a['tg_id'] ?? 0) > 0): ?>
                <span class="m ad-tg">✈️ تلگرام: <b class="ltr"><?= fa_num((string)(int)$a['tg_id']) ?></b></span>
              <?php elseif ((string)DB::setting('admin_2fa', '0') === '1' && $isOn): ?>
                <span class="m ad-tg warn" title="ورود دومرحله‌ای فعال است اما این مدیر شناسهٔ تلگرام ندارد">⚠️ بدون شناسهٔ تلگرام (2FA)</span>
              <?php endif; ?>
              <span class="m">📅 ساخت: <b><?= fa_num(substr((string)($a['created_at'] ?? ''), 0, 10)) ?></b></span>
            </div>

            <?php if ((string)($a['note'] ?? '') !== ''): ?>
              <div class="hint" style="margin-bottom:8px">📝 <?= h((string)$a['note']) ?></div>
            <?php endif; ?>

            <div class="ad-acts">
              <a class="btn btn-sm <?= $sel === $id ? 'btn-primary' : 'btn-ghost' ?>" href="index.php?p=admins&amp;a=<?= $id ?>#adEdit">🔐 دسترسی‌ها</a>

              <?php if (can('admins.edit') && !$isMe): ?>
                <form method="post" data-confirm="<?= $isOn ? 'این حساب غیرفعال شود؟' : 'این حساب فعال شود؟' ?>">
                  <?= csrf_field() ?><input type="hidden" name="act" value="toggle">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <button class="btn btn-sm btn-ghost"><?= $isOn ? '⏸ غیرفعال' : '▶️ فعال' ?></button>
                </form>
              <?php endif; ?>

              <?php if (can('admins.delete') && !$isMe): ?>
                <form method="post" data-confirm="حساب این مدیر کاملاً حذف شود؟ این کار بازگشت‌پذیر نیست.">
                  <?= csrf_field() ?><input type="hidden" name="act" value="del">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <button class="btn btn-sm btn-danger">🗑 حذف</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($editing !== null):
    $eId    = (int)$editing['id'];
    $eSup   = (string)$editing['role'] === 'super';
    $eNm    = trim((string)($editing['name'] ?? '')) !== '' ? (string)$editing['name'] : (string)$editing['username'];
    $eCur   = Perm::of($editing);
    $canEd  = can('admins.edit');
    $lockRole = $eSup && !isSuper();
?>

  <div class="card compact mt4" id="adEdit">
    <div class="card-head tight">
      <div>
        <div class="card-title">🔐 دسترسی‌های <?= h($eNm) ?></div>
        <div class="card-sub">
          شناسه: <b class="ltr mono"><?= fa_num((string)$eId) ?></b> •
          نام کاربری: <b class="ltr mono">@<?= h((string)$editing['username']) ?></b> •
          <?= $eSup ? 'نقش فعلی: مدیر کل' : 'دسترسی فعلی: ' . fa_num((string)count($eCur)) . ' مورد' ?>
        </div>
      </div>
      <a class="btn btn-sm btn-ghost" href="index.php?p=admins">✖️ بستن</a>
    </div>

    <?php if (!$canEd): ?>
      <div class="alert a-warn">شما اجازهٔ ویرایش ندارید و فقط در حالت مشاهده هستید.</div>
    <?php endif; ?>
    <?php if ($lockRole): ?>
      <div class="alert a-warn">این حساب مدیر کل است؛ تغییر نقش و دسترسی‌های او فقط توسط مدیر کل امکان‌پذیر است.</div>
    <?php endif; ?>

    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="save">
      <input type="hidden" name="id" value="<?= $eId ?>">

      <div class="form-grid g2">
        <div class="field">
          <label>نام نمایشی</label>
          <input name="name" maxlength="128" value="<?= h((string)($editing['name'] ?? '')) ?>" <?= $canEd ? '' : 'disabled' ?>>
        </div>
        <div class="field">
          <label>نقش</label>
          <select name="role" id="adRoleEd" <?= ($canEd && !$lockRole) ? '' : 'disabled' ?>>
            <option value="admin" <?= $eSup ? '' : 'selected' ?>>مدیر محدود (با دسترسی دلخواه)</option>
            <?php if (isSuper() || $eSup): ?>
              <option value="super" <?= $eSup ? 'selected' : '' ?>>👑 مدیر کل (دسترسی کامل)</option>
            <?php endif; ?>
          </select>
          <?php if ($lockRole): ?><input type="hidden" name="role" value="super"><?php endif; ?>
        </div>
        <div class="field">
          <label>شناسهٔ تلگرام <?= (string)DB::setting('admin_2fa', '0') === '1' && (int)($editing['tg_id'] ?? 0) <= 0 ? '<span class="badge b-orange">برای 2FA لازم است</span>' : '' ?></label>
          <input name="tg_id" class="mono ltr" inputmode="numeric" placeholder="123456789" pattern="[0-9۰-۹]*"
                 value="<?= (int)($editing['tg_id'] ?? 0) > 0 ? (int)$editing['tg_id'] : '' ?>" <?= $canEd ? '' : 'disabled' ?>>
          <div class="hint">برای گزارش‌های مدیریتی و ورود دومرحله‌ای (2FA). مدیر باید یک‌بار به ربات /start بدهد.</div>
        </div>
        <div class="field">
          <label>یادداشت</label>
          <input name="note" maxlength="255" value="<?= h((string)($editing['note'] ?? '')) ?>" <?= $canEd ? '' : 'disabled' ?>>
        </div>
      </div>

      <?php if ($canEd): ?>
        <div class="card-title mt4" style="font-size:13px">⚡ پیش‌تنظیم‌های آماده</div>
        <div class="card-sub" style="margin-bottom:10px">با یک کلیک، مجموعهٔ دسترسی استاندارد اعمال می‌شود.</div>
        <?php $presetRow($PRESETS, 'ed'); ?>
      <?php endif; ?>

      <div class="card-title" style="font-size:13px">🔑 فهرست دسترسی‌ها</div>
      <div class="card-sub" style="margin-bottom:10px">هر تیک معنای یک عملیات مشخص است؛ برای مشاهدهٔ یک بخش حداقل دسترسی <code>view</code> همان بخش لازم است.</div>
      <div id="adPmEd" class="<?= $eSup ? 'pm-off' : '' ?>"><?php $pmRender($PMAP, $eCur, !$canEd, 'ed'); ?></div>

      <?php if ($canEd): ?>
        <div class="sticky-acts">
          <button class="btn btn-primary">💾 ذخیرهٔ دسترسی‌ها</button>
          <span class="hint">نقش «مدیر کل» همهٔ تیک‌ها را بی‌اهمیت می‌کند.</span>
        </div>
      <?php endif; ?>
    </form>
  </div>

  <div class="form-grid g2 mt4">
    <?php if ($canEd || $eId === $ME): ?>
      <div class="card compact">
        <div class="card-head tight">
          <div>
            <div class="card-title">🔑 تغییر رمز عبور</div>
            <div class="card-sub">پس از تغییر رمز، ورود با رمز قبلی ممکن نخواهد بود.</div>
          </div>
        </div>
        <form method="post" data-confirm="رمز عبور این مدیر تغییر کند؟">
          <?= csrf_field() ?><input type="hidden" name="act" value="passwd">
          <input type="hidden" name="id" value="<?= $eId ?>">
          <div class="field">
            <label>رمز جدید</label>
            <div class="ad-pw">
              <input name="password" id="adPw2" class="mono ltr" type="text" required minlength="8" placeholder="حداقل ۸ کاراکتر">
              <button type="button" class="pm-mini" id="adGen2">🎲 ساخت خودکار</button>
            </div>
            <div class="ad-str"><i id="adStr2"></i></div>
          </div>
          <div class="sticky-acts"><button class="btn btn-primary">🔑 ثبت رمز جدید</button></div>
        </form>
      </div>
    <?php endif; ?>

    <div class="card compact">
      <div class="card-head tight">
        <div>
          <div class="card-title">⚠️ عملیات حساس</div>
          <div class="card-sub">این دو کار روی دسترسی ورود این مدیر اثر فوری دارند.</div>
        </div>
      </div>
      <div class="ad-danger">
        <div class="lg">محدودیت‌های ایمنی</div>
        <div class="hint">• حساب خودتان قابل غیرفعال یا حذف نیست.<br>• آخرین مدیر کل فعال نمی‌تواند حذف یا تنزل داده شود.</div>
        <div class="ad-acts mt3">
          <?php if (can('admins.edit') && $eId !== $ME): ?>
            <form method="post" data-confirm="وضعیت این حساب تغییر کند؟">
              <?= csrf_field() ?><input type="hidden" name="act" value="toggle">
              <input type="hidden" name="id" value="<?= $eId ?>">
              <button class="btn btn-sm btn-ghost"><?= (int)($editing['active'] ?? 1) === 1 ? '⏸ غیرفعال کردن' : '▶️ فعال کردن' ?></button>
            </form>
          <?php endif; ?>
          <?php if (can('admins.delete') && $eId !== $ME): ?>
            <form method="post" data-confirm="این مدیر کاملاً حذف شود؟">
              <?= csrf_field() ?><input type="hidden" name="act" value="del">
              <input type="hidden" name="id" value="<?= $eId ?>">
              <button class="btn btn-sm btn-danger">🗑 حذف مدیر</button>
            </form>
          <?php endif; ?>
          <?php if ($eId === $ME): ?><span class="hint">این حساب خود شماست.</span><?php endif; ?>
        </div>
      </div>
    </div>
  </div>

<?php else: ?>
  <div class="card compact mt4">
    <div class="ad-empty">برای دیدن و ویرایش دسترسی‌ها، از فهرست بالا یک مدیر را انتخاب کنید.</div>
  </div>
<?php endif; ?>

<div class="card compact mt4">
  <div class="card-head tight">
    <div>
      <div class="card-title">ℹ️ راهنمای دسترسی‌ها</div>
      <div class="card-sub">چند نکتهٔ مهم پیش از دادن دسترسی به همکاران.</div>
    </div>
  </div>
  <div class="form-grid g2">
    <div class="fieldset">
      <div class="lg">🔍 منطق دسترسی‌ها</div>
      <div class="fs-hint">
        هر دسترسی به شکل <code>بخش.عملیات</code> است؛ مانند <code>users.view</code> یا <code>payments.approve</code>.<br>
        برای دیدن منوی هر بخش در سایدبار، دادن دسترسی <code>view</code> همان بخش کافی است.<br>
        دادن دسترسی ویرایش بدون <code>view</code> عملاً بی‌اثر است.
      </div>
    </div>
    <div class="fieldset warn">
      <div class="lg">🛡️ توصیه‌های امنیتی</div>
      <div class="fs-hint">
        • به همکار پشتیبانی دسترسی تنظیمات، بکاپ و مدیران ندهید.<br>
        • برای هر نفر حساب جداگانه بسازید تا لاگ‌ها قابل پیگیری باشد.<br>
        • رمزها حداقل ۸ کاراکتر و ترکیبی باشند؛ از دکمهٔ ساخت خودکار استفاده کنید.<br>
        • حساب کارمندی که دیگر همکاری نمی‌کند را غیرفعال کنید تا تاریخچه حفظ شود.
      </div>
    </div>
  </div>
</div>

<script>
window.ADPRESETS = <?= jenc(array_map(static fn($p) => array_values((array)($p['perms'] ?? [])), $PRESETS)) ?>;
(function () {
  /* ---------- شمارندهٔ هر بخش ---------- */
  function recount(ns) {
    var grid = document.querySelector('[data-pm-grid="' + ns + '"]');
    if (!grid) return;
    var on = 0, all = 0;
    grid.querySelectorAll('.pm-card').forEach(function (card) {
      var b = card.querySelectorAll('input[type=checkbox]');
      var n = 0;
      b.forEach(function (c) { if (c.checked) n++; });
      on += n; all += b.length;
      var el = card.querySelector('[data-pm-cnt]');
      if (el) {
        var t = n + '/' + b.length;
        el.textContent = window.faDigits ? window.faDigits(t) : t;
        el.style.color = n > 0 ? 'var(--accent-text)' : '';
      }
    });
    var tot = document.querySelector('[data-pm-tot="' + ns + '"]');
    if (tot) {
      var s = on + ' از ' + all + ' دسترسی انتخاب شده';
      tot.textContent = window.faDigits ? window.faDigits(s) : s;
    }
  }
  ['new', 'ed'].forEach(recount);

  document.addEventListener('change', function (ev) {
    var t = ev.target;
    if (t && t.name === 'perms[]') {
      var g = t.closest('[data-pm-grid]');
      if (g) recount(g.getAttribute('data-pm-grid'));
    }
    if (t && (t.id === 'adRoleNew' || t.id === 'adRoleEd')) {
      var box = document.getElementById(t.id === 'adRoleNew' ? 'adPmNew' : 'adPmEd');
      if (box) box.classList.toggle('pm-off', t.value === 'super');
    }
  });

  /* ---------- دکمه‌های انتخاب ---------- */
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-pm-all],[data-pm-none],[data-pm-sec-all],[data-preset]') : null;
    if (!b) return;

    if (b.hasAttribute('data-pm-sec-all')) {
      var card = b.closest('.pm-card');
      if (!card) return;
      var boxes = card.querySelectorAll('input[type=checkbox]:not([disabled])');
      var allOn = true;
      boxes.forEach(function (c) { if (!c.checked) allOn = false; });
      boxes.forEach(function (c) { c.checked = !allOn; });
      var g = card.closest('[data-pm-grid]');
      if (g) recount(g.getAttribute('data-pm-grid'));
      return;
    }

    var ns = b.getAttribute('data-pm-all') || b.getAttribute('data-pm-none') || b.getAttribute('data-ns');
    var grid = ns ? document.querySelector('[data-pm-grid="' + ns + '"]') : null;
    if (!grid) return;

    if (b.hasAttribute('data-pm-all') || b.hasAttribute('data-pm-none')) {
      var val = b.hasAttribute('data-pm-all');
      grid.querySelectorAll('input[type=checkbox]:not([disabled])').forEach(function (c) { c.checked = val; });
      recount(ns);
      return;
    }

    if (b.hasAttribute('data-preset')) {
      var key = b.getAttribute('data-preset');
      var list = (window.ADPRESETS || {})[key] || [];
      var full = list.indexOf('*') !== -1;
      grid.querySelectorAll('input[type=checkbox]:not([disabled])').forEach(function (c) {
        c.checked = full || list.indexOf(c.value) !== -1;
      });
      recount(ns);
      grid.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  });

  /* ---------- جستجو در دسترسی‌ها ---------- */
  document.querySelectorAll('.pm-q').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var ns = inp.getAttribute('data-ns');
      var grid = document.querySelector('[data-pm-grid="' + ns + '"]');
      if (!grid) return;
      var term = (inp.value || '').trim().toLowerCase();
      grid.querySelectorAll('.pm-card').forEach(function (card) {
        var seen = 0;
        card.querySelectorAll('.pm-i').forEach(function (it) {
          var ok = !term || (it.getAttribute('data-q') || '').indexOf(term) !== -1;
          it.style.display = ok ? '' : 'none';
          if (ok) seen++;
        });
        card.style.display = seen ? '' : 'none';
      });
    });
  });

  /* ---------- جستجوی مدیر ---------- */
  var aq = document.getElementById('adQ');
  if (aq) {
    aq.addEventListener('input', function () {
      var term = (aq.value || '').trim().toLowerCase();
      document.querySelectorAll('#adList .ad-row').forEach(function (r) {
        var ok = !term || (r.getAttribute('data-q') || '').indexOf(term) !== -1;
        r.style.display = ok ? '' : 'none';
      });
    });
  }

  /* ---------- رمز عبور ---------- */
  function strength(v) {
    var s = 0;
    if (v.length >= 8) s++;
    if (v.length >= 12) s++;
    if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++;
    if (/[0-9]/.test(v)) s++;
    if (/[^A-Za-z0-9]/.test(v)) s++;
    return s;
  }
  function gen() {
    var cs = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%*?';
    var out = '';
    for (var i = 0; i < 14; i++) out += cs.charAt(Math.floor(Math.random() * cs.length));
    return out;
  }
  function wire(inpId, barId, btnId) {
    var inp = document.getElementById(inpId);
    var bar = document.getElementById(barId);
    var btn = document.getElementById(btnId);
    if (!inp) return;
    var draw = function () {
      if (!bar) return;
      var s = strength(inp.value || '');
      bar.style.width = (s * 20) + '%';
      bar.style.background = s <= 2 ? 'var(--red)' : (s === 3 ? 'var(--orange)' : 'var(--green)');
    };
    inp.addEventListener('input', draw);
    if (btn) btn.addEventListener('click', function () { inp.value = gen(); draw(); inp.focus(); });
    draw();
  }
  wire('adPw', 'adStr', 'adGen');
  wire('adPw2', 'adStr2', 'adGen2');
})();
</script>
