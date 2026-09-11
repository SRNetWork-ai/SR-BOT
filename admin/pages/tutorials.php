<?php
if (!can('tutorials.view')) { echo denyBox('این بخش برای شما فعال نیست.'); return; }
/**
 * آموزش‌ها — استودیوی محتوای آموزشی ربات
 * دسته‌بندی بر اساس دستگاه، پیش‌نمایش زندهٔ تلگرام، عملیات گروهی و چیدمان کارتی
 */

$PLAT = class_exists('Kb') ? Kb::platforms() : [
    'android' => '🤖 اندروید',
    'ios'     => '🍏 آی‌او‌اس',
    'windows' => '💻 ویندوز',
    'mac'     => '🖥 مک',
    'tv'      => '📺 تلویزیون',
    'other'   => '📚 سایر',
];

$act = (string)($_POST['act'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {

    if ($act === 'save') { need('tutorials.edit', 'tutorials');
        $id   = pint('id');
        $plat = ptxt('platform', 'android');
        if (!isset($PLAT[$plat])) $plat = 'android';
        $data = [
            'title'    => mb_substr(ptxt('title'), 0, 180),
            'platform' => $plat,
            'content'  => clean_text(ptxt('content'), 3500),
            'link'     => ptxt('link'),
            'file_id'  => ptxt('file_id'),
            'sort'     => pint('sort'),
            'active'   => pchk('active'),
        ];
        if ($data['title'] === '') {
            flash('err', 'عنوان آموزش الزامی است.');
            back('tutorials', $id ? ['edit' => $id] : []);
        }
        if ($data['content'] === '' && $data['link'] === '' && $data['file_id'] === '') {
            flash('err', 'حداقل یکی از فیلدهای «متن آموزش»، «لینک» یا «شناسه فایل» را پر کنید.');
            back('tutorials', $id ? ['edit' => $id] : []);
        }
        if ($id) {
            DB::update('tutorials', $data, 'id = :id', [':id' => $id]);
            flash('ok', '✅ آموزش به‌روز شد.');
        } else {
            $data['created_at'] = now();
            DB::insert('tutorials', $data);
            flash('ok', '✅ آموزش جدید افزوده شد.');
        }
        back('tutorials');
    }

    if ($act === 'del') { need('tutorials.edit', 'tutorials');
        DB::delete('tutorials', 'id = :id', [':id' => pint('id')]);
        flash('ok', '🗑 آموزش حذف شد.');
        back('tutorials');
    }

    if ($act === 'toggle') { need('tutorials.edit', 'tutorials');
        $t = DB::one('SELECT * FROM {p}tutorials WHERE id = :id', [':id' => pint('id')]);
        if ($t) DB::update('tutorials', ['active' => (int)$t['active'] ? 0 : 1], 'id = :id', [':id' => (int)$t['id']]);
        back('tutorials');
    }

    if ($act === 'clone') { need('tutorials.edit', 'tutorials');
        $t = DB::one('SELECT * FROM {p}tutorials WHERE id = :id', [':id' => pint('id')]);
        if ($t) {
            unset($t['id']);
            $t['title']      = (string)$t['title'] . ' (کپی)';
            $t['active']     = 0;
            $t['created_at'] = now();
            DB::insert('tutorials', $t);
            flash('ok', '⧉ کپی آموزش ساخته شد؛ بعد از ویرایش آن را فعال کنید.');
        }
        back('tutorials');
    }

    if ($act === 'move') { need('tutorials.edit', 'tutorials');
        $t   = DB::one('SELECT * FROM {p}tutorials WHERE id = :id', [':id' => pint('id')]);
        $dir = ptxt('dir') === 'up' ? -1 : 1;
        if ($t) DB::update('tutorials', ['sort' => max(0, (int)$t['sort'] + $dir)], 'id = :id', [':id' => (int)$t['id']]);
        back('tutorials');
    }

    /* ---------- عملیات گروهی ---------- */
    if ($act === 'bulk') { need('tutorials.edit', 'tutorials');
        $ids = array_values(array_unique(array_map('intval', (array)($_POST['ids'] ?? []))));
        $ids = array_filter($ids, fn($x) => $x > 0);
        $m   = ptxt('mode');

        if (!$ids) {
            flash('warn', 'هیچ آموزشی انتخاب نشده بود.');
            back('tutorials');
        }
        $n = 0;
        foreach ($ids as $tid) {
            if ($m === 'on')       { DB::update('tutorials', ['active' => 1], 'id = :id', [':id' => $tid]); $n++; }
            elseif ($m === 'off')  { DB::update('tutorials', ['active' => 0], 'id = :id', [':id' => $tid]); $n++; }
            elseif ($m === 'del')  { DB::delete('tutorials', 'id = :id', [':id' => $tid]); $n++; }
        }
        $lbl = ['on' => 'فعال', 'off' => 'مخفی', 'del' => 'حذف'][$m] ?? 'به‌روز';
        flash($n ? 'ok' : 'err', fa_num($n) . ' آموزش ' . $lbl . ' شد.');
        back('tutorials');
    }

    /* افزودن آموزش‌های پیش‌فرض برای شروع سریع */
    if ($act === 'seed') { need('tutorials.edit', 'tutorials');
        $seed = [
            ['android', 'اتصال در اندروید با v2rayNG', "۱) برنامه <b>v2rayNG</b> را نصب کنید.\n۲) از بخش «🔑 سرویس‌های من» لینک ساب را کپی کنید.\n۳) در برنامه روی + بزنید و «Import config from clipboard» را انتخاب کنید.\n۴) کانفیگ را انتخاب و دکمه اتصال را بزنید.", 'https://github.com/2dust/v2rayNG/releases'],
            ['ios', 'اتصال در آیفون با Streisand', "۱) برنامه <b>Streisand</b> را از اپ‌استور نصب کنید.\n۲) لینک ساب سرویس را کپی کنید.\n۳) در برنامه + ← «Import from clipboard».\n۴) سرور را انتخاب و وصل شوید.", 'https://apps.apple.com/app/streisand/id6450534064'],
            ['windows', 'اتصال در ویندوز با v2rayN', "۱) بسته <b>v2rayN</b> را دانلود و از حالت زیپ خارج کنید.\n۲) لینک ساب را کپی کنید.\n۳) در برنامه: Subscription ← Subscription group setting ← افزودن لینک ساب.\n۴) Update subscription سپس انتخاب سرور و اتصال.", 'https://github.com/2dust/v2rayN/releases'],
            ['mac', 'اتصال در مک با V2Box', "۱) برنامه <b>V2Box</b> را از اپ‌استور نصب کنید.\n۲) لینک ساب را کپی کنید.\n۳) در تب Configs روی + بزنید و «Add subscription» را انتخاب کنید.\n۴) سرور را انتخاب و اتصال را فعال کنید.", 'https://apps.apple.com/app/v2box-v2ray-client/id6446814690'],
            ['tv', 'اتصال در اندروید تی‌وی', "۱) از فروشگاه دستگاه برنامه <b>v2rayNG</b> یا <b>HiddifyNG</b> را نصب کنید.\n۲) لینک ساب را با ریموت وارد کنید یا با QR اسکن کنید.\n۳) سرور را انتخاب و وصل شوید.\n\nنکته: اگر کیبورد تی‌وی سخت است، از اپ Remote گوشی برای وارد کردن لینک استفاده کنید.", ''],
            ['other', 'راهنمای لینک ساب و به‌روزرسانی سرویس', "• لینک ساب یک آدرس ثابت است؛ با تمدید یا تغییر سرور، کانفیگ‌های داخل آن به‌صورت خودکار به‌روز می‌شود.\n• هر چند روز یک‌بار در برنامه دکمه Update subscription را بزنید.\n• حجم و تاریخ انقضا را از «🔑 سرویس‌های من» ببینید.\n• برای مشکل اتصال، ابتدا سرور دیگری از داخل همان ساب را امتحان کنید.", ''],
        ];
        $added = 0;
        foreach ($seed as $i => [$plat, $title, $body, $link]) {
            $exists = (int)DB::val('SELECT COUNT(*) FROM {p}tutorials WHERE title = :t AND platform = :p',
                [':t' => $title, ':p' => $plat], 0);
            if ($exists) continue;
            DB::insert('tutorials', [
                'title' => $title, 'platform' => $plat, 'content' => $body,
                'link' => $link, 'file_id' => null, 'sort' => $i, 'active' => 1, 'created_at' => now(),
            ]);
            $added++;
        }
        flash($added ? 'ok' : 'info', $added ? (fa_num((string)$added) . ' آموزش پیش‌فرض افزوده شد.') : 'آموزش‌های پیش‌فرض از قبل موجود بودند.');
        back('tutorials');
    }
}

/* ==================== داده ==================== */
$fPlat = (string)($_GET['plat'] ?? '');
if (!isset($PLAT[$fPlat])) $fPlat = '';
$fSt = (string)($_GET['st'] ?? '');
if (!in_array($fSt, ['on', 'off'], true)) $fSt = '';

$all = DB::all('SELECT * FROM {p}tutorials ORDER BY platform ASC, sort ASC, id ASC');

$items = array_values(array_filter($all, function ($x) use ($fPlat, $fSt) {
    if ($fPlat !== '' && (string)$x['platform'] !== $fPlat) return false;
    if ($fSt === 'on'  && (int)$x['active'] !== 1) return false;
    if ($fSt === 'off' && (int)$x['active'] === 1) return false;
    return true;
}));

$editId = (int)($_GET['edit'] ?? 0);
$e      = $editId ? DB::one('SELECT * FROM {p}tutorials WHERE id = :id', [':id' => $editId]) : null;
$v      = function (string $k, $d = '') use ($e) { return $e[$k] ?? $d; };

$counts = [];
foreach ($all as $it) {
    $k = (string)$it['platform'];
    $counts[$k] = ($counts[$k] ?? 0) + 1;
}
$cActive = count(array_filter($all, fn($x) => (int)$x['active'] === 1));
$cHidden = count($all) - $cActive;
$cLink   = count(array_filter($all, fn($x) => trim((string)$x['link']) !== ''));
$cFile   = count(array_filter($all, fn($x) => trim((string)$x['file_id']) !== ''));
$cPlat   = count(array_filter($PLAT, fn($k) => ($counts[$k] ?? 0) > 0, ARRAY_FILTER_USE_KEY));
$covPct  = count($PLAT) > 0 ? (int)round($cPlat * 100 / count($PLAT)) : 0;
?>

<style>
/* ===== Tutorials Studio ===== */
.tut-hero{
  position:relative; overflow:hidden; padding:var(--s5) var(--s4);
  border:1px solid var(--border); border-radius:var(--r-xl);
  background:linear-gradient(155deg, rgba(139,92,246,.15), rgba(38,211,232,.10) 45%, transparent 75%), var(--surface);
  box-shadow:var(--shadow);
}
.tut-glow{ position:absolute; border-radius:50%; filter:blur(60px); opacity:.5; pointer-events:none; }
.tut-glow.g1{ width:225px; height:225px; background:rgba(139,92,246,.34); inset-block-start:-92px; inset-inline-end:-58px; }
.tut-glow.g2{ width:185px; height:185px; background:rgba(38,211,232,.28); inset-block-end:-92px; inset-inline-start:-52px; }
.tut-htop{ position:relative; display:flex; align-items:flex-start; gap:var(--s3); flex-wrap:wrap; }
.tut-hic{
  width:52px; height:52px; flex:none; display:grid; place-items:center; font-size:26px;
  border-radius:var(--r-lg); background:var(--grad-soft, var(--accent-soft)); border:1px solid var(--border);
  box-shadow:var(--glow);
}
.tut-htt h2{ margin:0; font-size:17px; }
.tut-htt p{ margin:4px 0 0; font-size:12px; color:var(--muted); line-height:1.8; max-width:56ch; }
.tut-hact{ margin-inline-start:auto; display:flex; gap:6px; flex-wrap:wrap; }

.tut-cells{ position:relative; display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:var(--s2); margin-top:var(--s4); }
.tut-cell{
  display:flex; flex-direction:column; align-items:center; gap:2px; padding:var(--s3) var(--s2);
  background:var(--surface-2); border:1px solid var(--border); border-radius:var(--r);
  transition:transform .18s, border-color .18s;
}
.tut-cell:hover{ transform:translateY(-3px); border-color:var(--accent); }
.tut-cell .i{ font-size:17px; }
.tut-cell .v{ font-family:var(--font-num); font-size:19px; font-weight:800; }
.tut-cell .l{ font-size:10.5px; color:var(--muted); text-align:center; }
.tut-cell.g .v{ color:var(--green); } .tut-cell.o .v{ color:var(--orange); }
.tut-cell.b .v{ color:var(--accent); } .tut-cell.c .v{ color:var(--cyan); }
.tut-cell.p .v{ color:var(--accent-2); }

.tut-cov{ position:relative; margin-top:var(--s4); }
.tut-cov-t{ display:flex; justify-content:space-between; font-size:12px; color:var(--muted); margin-bottom:6px; }
.tut-cov-t b{ color:var(--cyan); font-family:var(--font-num); }
.tut-cov-bar{ height:8px; border-radius:99px; background:var(--surface-3); overflow:hidden; }
.tut-cov-bar span{ display:block; height:100%; border-radius:99px; background:linear-gradient(90deg,var(--accent-2),var(--cyan)); transition:width .6s ease; }

/* ---- کاشی‌های دستگاه ---- */
.tut-tiles{ display:grid; grid-template-columns:repeat(auto-fit,minmax(128px,1fr)); gap:var(--s2); margin-top:var(--s3); }
.tut-tile{
  display:flex; flex-direction:column; align-items:center; gap:4px; padding:var(--s3) var(--s2);
  background:var(--surface); border:1px solid var(--border); border-radius:var(--r);
  color:var(--muted); text-decoration:none; font-size:12px; font-weight:700;
  transition:transform .18s, border-color .18s, background .18s, color .18s;
}
.tut-tile:hover{ transform:translateY(-3px); border-color:var(--accent-2); color:var(--text); }
.tut-tile.on{ color:#fff; background:var(--grad, var(--accent)); border-color:transparent; box-shadow:var(--glow); }
.tut-tile .n{ font-family:var(--font-num); font-size:17px; font-weight:800; }
.tut-tile .z{ font-size:10px; opacity:.75; }

/* ---- نوار ابزار ---- */
.tut-bar{
  display:flex; align-items:center; gap:var(--s2); flex-wrap:wrap;
  margin-top:var(--s3); padding:var(--s3);
  background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
}
.tut-seg{ display:inline-flex; background:var(--surface-2); border:1px solid var(--border); border-radius:var(--r-pill); padding:3px; }
.tut-seg button{
  border:0; background:transparent; color:var(--muted); cursor:pointer;
  padding:6px 14px; border-radius:var(--r-pill); font-size:12px; font-weight:700; font-family:inherit;
  transition:background .18s, color .18s;
}
.tut-seg button.on{ background:var(--grad, var(--accent)); color:#fff; }

/* ---- کارت آموزش ---- */
.tut-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:var(--s3); margin-top:var(--s3); }
.tut-card{
  position:relative; display:flex; flex-direction:column; overflow:hidden;
  background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
  transition:transform .18s, border-color .18s, box-shadow .18s;
}
.tut-card:hover{ transform:translateY(-3px); border-color:var(--accent-2); box-shadow:var(--shadow-lg); }
.tut-card.off{ opacity:.62; }
.tut-card.sel{ border-color:var(--green); box-shadow:0 0 0 1px var(--green); }
.tut-card .strip{ height:3px; background:linear-gradient(90deg,var(--accent-2),var(--cyan)); }
.tut-card.off .strip{ background:var(--surface-3); }
.tut-ctop{ display:flex; align-items:flex-start; gap:var(--s3); padding:var(--s3) var(--s3) var(--s2); }
.tut-ic{
  width:42px; height:42px; flex:none; border-radius:13px; display:grid; place-items:center; font-size:20px;
  background:var(--grad-soft, var(--accent-soft)); border:1px solid var(--border);
}
.tut-cw{ flex:1; min-width:0; }
.tut-cw .t{ font-weight:800; font-size:13.5px; line-height:1.6; }
.tut-cw .s{ font-size:11px; color:var(--muted); margin-top:2px; }
.tut-body{ padding:0 var(--s3) var(--s3); }
.tut-ex{
  font-size:11.5px; color:var(--muted); line-height:1.9; max-height:74px; overflow:hidden;
  padding:9px 11px; background:var(--surface-2); border-radius:var(--r-sm);
}
.tut-tags{ display:flex; gap:5px; flex-wrap:wrap; margin-top:var(--s2); }
.tut-tag{ font-size:10.5px; padding:3px 9px; border-radius:99px; background:var(--surface-3); color:var(--muted); }
.tut-tag.g{ background:var(--green-soft); color:var(--green); }
.tut-tag.b{ background:var(--accent-soft); color:var(--accent-text); }
.tut-tag.c{ background:var(--cyan-soft); color:var(--cyan); }
.tut-cact{ margin-top:auto; display:flex; gap:5px; flex-wrap:wrap; padding:var(--s3); border-top:1px solid var(--border-soft); background:var(--surface-2); }
.tut-pick{ position:absolute; inset-block-start:10px; inset-inline-start:10px; z-index:3; }
.tut-pick input{ width:18px; height:18px; cursor:pointer; accent-color:var(--green); }

/* ---- عملیات گروهی ---- */
.tut-bulk{
  position:sticky; inset-block-start:8px; z-index:20;
  display:flex; align-items:center; gap:var(--s2); flex-wrap:wrap; margin-top:var(--s3); padding:var(--s3);
  background:var(--surface-2); border:1px solid var(--accent-2); border-radius:var(--r); box-shadow:var(--shadow-lg);
}
.tut-bulk[hidden]{ display:none; }
.tut-bulk .cnt{ font-family:var(--font-num); font-weight:800; color:var(--accent-2); }

/* ---- پیش‌نمایش تلگرام ---- */
.tut-prev{
  border:1px solid var(--border); border-radius:var(--r-lg); overflow:hidden;
  background:#0d1620; box-shadow:var(--shadow); position:sticky; inset-block-start:8px;
}
.tut-prev-h{
  display:flex; align-items:center; gap:8px; padding:9px 12px;
  background:linear-gradient(135deg,#2AABEE,#229ED9); color:#fff; font-size:12px; font-weight:700;
}
.tut-prev-b{ padding:14px 12px; min-height:130px; }
.tut-bub{
  max-width:94%; padding:10px 13px; border-radius:14px 14px 14px 4px;
  background:#182533; color:#e9eef5; font-size:12.5px; line-height:2;
  word-break:break-word; white-space:pre-wrap;
}
.tut-bub b{ color:#fff; }
.tut-bub .ph{
  display:grid; place-items:center; height:104px; margin-bottom:9px; border-radius:10px;
  background:linear-gradient(135deg,#22303f,#1a2532); color:#7d8ea1; font-size:11.5px;
}
.tut-kb{ display:flex; flex-direction:column; gap:5px; margin-top:8px; }
.tut-kb span{
  display:block; padding:9px; border-radius:9px; text-align:center;
  background:#22303f; color:#7fc4f2; font-size:12px; font-weight:700;
}
.tut-mgrid{ display:grid; grid-template-columns:1.25fr .95fr; gap:var(--s4); align-items:start; }

@media (max-width:1100px){
  .tut-cells{ grid-template-columns:repeat(3,minmax(0,1fr)); }
  .tut-mgrid{ grid-template-columns:1fr; }
  .tut-prev{ position:static; }
}
@media (max-width:640px){
  .tut-cells{ grid-template-columns:repeat(2,minmax(0,1fr)); }
  .tut-grid{ grid-template-columns:1fr; }
}
</style>

<div class="tut-hero">
  <span class="tut-glow g1"></span><span class="tut-glow g2"></span>

  <div class="tut-htop">
    <div class="tut-hic">🎓</div>
    <div class="tut-htt">
      <h2>مرکز آموزش</h2>
      <p>محتوای بخش «🎓 آموزش» ربات را اینجا بسازید. آموزش‌ها بر اساس دستگاه دسته‌بندی می‌شوند و می‌توانند متن، دکمهٔ دانلود و تصویر/ویدیو داشته باشند.</p>
    </div>
    <div class="tut-hact">
      <?php if (can('tutorials.edit')): ?>
        <button class="btn btn-primary btn-sm" type="button" data-modal="#mTut">➕ آموزش جدید</button>
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="act" value="seed">
          <button class="btn btn-sm" type="submit">✨ آموزش‌های پیش‌فرض</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="tut-cells">
    <div class="tut-cell b"><span class="i">📚</span><span class="v"><?= fa_num(count($all)) ?></span><span class="l">کل آموزش‌ها</span></div>
    <div class="tut-cell g"><span class="i">✅</span><span class="v"><?= fa_num($cActive) ?></span><span class="l">فعال در ربات</span></div>
    <div class="tut-cell o"><span class="i">🙈</span><span class="v"><?= fa_num($cHidden) ?></span><span class="l">مخفی</span></div>
    <div class="tut-cell c"><span class="i">🔗</span><span class="v"><?= fa_num($cLink) ?></span><span class="l">دارای لینک</span></div>
    <div class="tut-cell p"><span class="i">🖼</span><span class="v"><?= fa_num($cFile) ?></span><span class="l">دارای فایل</span></div>
  </div>

  <div class="tut-cov">
    <div class="tut-cov-t">
      <span>پوشش دستگاه‌ها</span>
      <b><?= fa_num($cPlat) ?> از <?= fa_num(count($PLAT)) ?> دستگاه · <?= fa_num($covPct) ?>٪</b>
    </div>
    <div class="tut-cov-bar"><span style="width:<?= (int)$covPct ?>%"></span></div>
  </div>
</div>

<?php if (!$all): ?>
  <div class="alert a-info mt3">هنوز آموزشی ثبت نشده و بخش «🎓 آموزش» ربات خالی است. با دکمهٔ «آموزش‌های پیش‌فرض» ۶ آموزش آماده اضافه کنید.</div>
<?php endif; ?>

<div class="tut-tiles">
  <a class="tut-tile <?= $fPlat === '' ? 'on' : '' ?>" href="index.php?p=tutorials">
    <span>🗂 همه</span><span class="n"><?= fa_num(count($all)) ?></span><span class="z">تمام دستگاه‌ها</span>
  </a>
  <?php foreach ($PLAT as $k => $label): $n = (int)($counts[$k] ?? 0); ?>
    <a class="tut-tile <?= $fPlat === $k ? 'on' : '' ?>" href="index.php?p=tutorials&amp;plat=<?= h($k) ?>">
      <span><?= h($label) ?></span><span class="n"><?= fa_num($n) ?></span>
      <span class="z"><?= $n > 0 ? 'آموزش ثبت شده' : 'خالی' ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="tut-bar">
  <div class="tut-seg">
    <button type="button" class="on" data-tut-view="grid">▦ کارتی</button>
    <button type="button" data-tut-view="table">☰ جدولی</button>
  </div>

  <div class="tut-seg">
    <a class="btn btn-sm <?= $fSt === '' ? 'btn-primary' : 'btn-ghost' ?>" href="index.php?p=tutorials<?= $fPlat ? '&amp;plat=' . h($fPlat) : '' ?>">همه</a>
    <a class="btn btn-sm <?= $fSt === 'on' ? 'btn-primary' : 'btn-ghost' ?>" href="index.php?p=tutorials&amp;st=on<?= $fPlat ? '&amp;plat=' . h($fPlat) : '' ?>">فعال</a>
    <a class="btn btn-sm <?= $fSt === 'off' ? 'btn-primary' : 'btn-ghost' ?>" href="index.php?p=tutorials&amp;st=off<?= $fPlat ? '&amp;plat=' . h($fPlat) : '' ?>">مخفی</a>
  </div>

  <input type="search" style="max-width:230px;margin-inline-start:auto" placeholder="🔎 جستجوی عنوان یا متن…" id="tutFind">
  <span class="hint" id="tutCount"><?= fa_num(count($items)) ?> مورد</span>
</div>

<?php if (!$items): ?>
  <div class="card mt3"><div class="empty"><div class="ic">🎓</div>با این فیلتر، آموزشی پیدا نشد.</div></div>
<?php else: ?>

<form method="post" id="tutBulkForm">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="bulk">
  <input type="hidden" name="mode" id="tutMode" value="">

  <?php if (can('tutorials.edit')): ?>
    <div class="tut-bulk" id="tutBulk" hidden>
      <span>✔️ <span class="cnt" id="tutCnt">۰</span> آموزش انتخاب شده</span>
      <button class="btn btn-green btn-sm" type="button" data-tut-bulk="on">✅ فعال‌سازی</button>
      <button class="btn btn-sm" type="button" data-tut-bulk="off">🙈 مخفی کردن</button>
      <button class="btn btn-red btn-sm" type="button" data-tut-bulk="del">🗑 حذف</button>
      <button class="btn btn-sm btn-ghost" type="button" id="tutNone">لغو انتخاب</button>
    </div>
  <?php endif; ?>

  <!-- ============ نمای کارتی ============ -->
  <div class="tut-grid" id="tutGrid">
    <?php foreach ($items as $t):
        $plain = trim(strip_tags((string)$t['content']));
        $hay   = mb_strtolower((string)$t['title'] . ' ' . $plain);
    ?>
      <div class="tut-card <?= (int)$t['active'] ? '' : 'off' ?>" data-tut-card data-hay="<?= h($hay) ?>">
        <span class="strip"></span>
        <?php if (can('tutorials.edit')): ?>
          <label class="tut-pick"><input type="checkbox" name="ids[]" value="<?= (int)$t['id'] ?>" data-tut-box></label>
        <?php endif; ?>

        <div class="tut-ctop">
          <div class="tut-ic"><?= h(mb_substr((string)($PLAT[(string)$t['platform']] ?? '📘'), 0, 2)) ?></div>
          <div class="tut-cw">
            <div class="t"><?= h((string)$t['title']) ?></div>
            <div class="s"><?= h((string)($PLAT[(string)$t['platform']] ?? $t['platform'])) ?> · ترتیب <?= fa_num((int)$t['sort']) ?></div>
          </div>
        </div>

        <div class="tut-body">
          <?php if ($plain !== ''): ?>
            <div class="tut-ex"><?= h(mb_substr($plain, 0, 150)) ?><?= mb_strlen($plain) > 150 ? '…' : '' ?></div>
          <?php else: ?>
            <div class="tut-ex">بدون متن — فقط لینک یا فایل</div>
          <?php endif; ?>

          <div class="tut-tags">
            <span class="tut-tag <?= (int)$t['active'] ? 'g' : '' ?>"><?= (int)$t['active'] ? '✅ فعال' : '○ مخفی' ?></span>
            <?php if (trim((string)$t['content']) !== ''): ?><span class="tut-tag b">📝 متن</span><?php endif; ?>
            <?php if (trim((string)$t['link']) !== ''): ?><span class="tut-tag c">🔗 لینک</span><?php endif; ?>
            <?php if (trim((string)$t['file_id']) !== ''): ?><span class="tut-tag c">🖼 فایل</span><?php endif; ?>
            <span class="tut-tag"><?= h(to_jalali((string)$t['created_at'])) ?></span>
          </div>
        </div>

        <?php if (can('tutorials.edit')): ?>
          <div class="tut-cact">
            <a class="btn btn-sm btn-primary" href="index.php?p=tutorials&amp;edit=<?= (int)$t['id'] ?>">✏️ ویرایش</a>
            <button class="btn btn-sm" type="button" data-tut-one="toggle" data-id="<?= (int)$t['id'] ?>" title="فعال/غیرفعال">⏻</button>
            <button class="btn btn-sm" type="button" data-tut-one="move" data-dir="up" data-id="<?= (int)$t['id'] ?>" title="بالاتر">▲</button>
            <button class="btn btn-sm" type="button" data-tut-one="move" data-dir="down" data-id="<?= (int)$t['id'] ?>" title="پایین‌تر">▼</button>
            <button class="btn btn-sm" type="button" data-tut-one="clone" data-id="<?= (int)$t['id'] ?>" title="کپی">⧉</button>
            <button class="btn btn-sm btn-red" type="button" data-tut-one="del" data-id="<?= (int)$t['id'] ?>" title="حذف">🗑</button>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ============ نمای جدولی ============ -->
  <div class="card mt3" id="tutTableWrap" hidden>
    <div class="table-wrap"><table id="tutTable" class="responsive">
      <thead><tr><th>عنوان</th><th>دستگاه</th><th>محتوا</th><th>ترتیب</th><th>وضعیت</th><th>تاریخ</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($items as $t):
          $plain = trim(strip_tags((string)$t['content']));
      ?>
        <tr data-hay="<?= h(mb_strtolower((string)$t['title'] . ' ' . $plain)) ?>">
          <td><b><?= h((string)$t['title']) ?></b>
            <?php if ($plain !== ''): ?><div class="muted" style="font-size:11.5px"><?= h(mb_substr($plain, 0, 55)) ?>…</div><?php endif; ?></td>
          <td style="font-size:12px"><?= h((string)($PLAT[(string)$t['platform']] ?? $t['platform'])) ?></td>
          <td class="muted" style="font-size:11.5px">
            <?= trim((string)$t['content']) !== '' ? '📝 ' : '' ?><?= trim((string)$t['link']) !== '' ? '🔗 ' : '' ?><?= trim((string)$t['file_id']) !== '' ? '🖼' : '' ?>
            <?= (trim((string)$t['content']) === '' && trim((string)$t['link']) === '' && trim((string)$t['file_id']) === '') ? '—' : '' ?></td>
          <td class="mono"><?= fa_num((int)$t['sort']) ?></td>
          <td><?= (int)$t['active'] ? '<span class="badge b-green">فعال</span>' : '<span class="badge b-gray">مخفی</span>' ?></td>
          <td class="muted" style="font-size:11.5px"><?= h(to_jalali((string)$t['created_at'])) ?></td>
          <td class="acts">
            <?php if (can('tutorials.edit')): ?>
              <a class="btn btn-sm" href="index.php?p=tutorials&amp;edit=<?= (int)$t['id'] ?>">✏️</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </div>
</form>

<!-- فرم تک‌عملیاتی -->
<form method="post" id="tutOneForm" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="act" id="to_act" value="">
  <input type="hidden" name="id"  id="to_id"  value="">
  <input type="hidden" name="dir" id="to_dir" value="">
</form>
<?php endif; ?>

<?php if (can('tutorials.edit')): ?>
<!-- ==================== مودال افزودن / ویرایش ==================== -->
<div class="modal wide" id="mTut" <?= $e ? 'data-modal-auto="mTut"' : '' ?>>
  <div class="m-back"></div>
  <div class="m-box">
    <form method="post" id="tutForm">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" value="<?= (int)$editId ?>">

      <div class="m-head">
        <span style="font-size:18px"><?= $e ? '✏️' : '➕' ?></span>
        <h3><?= $e ? 'ویرایش آموزش: ' . h((string)$e['title']) : 'افزودن آموزش جدید' ?></h3>
        <?php if ($e): ?>
          <a class="x" href="index.php?p=tutorials" style="display:grid;place-items:center;text-decoration:none">✕</a>
        <?php else: ?>
          <button type="button" class="x" data-modal-close>✕</button>
        <?php endif; ?>
      </div>

      <div class="m-body">
        <div class="tut-mgrid">

          <div>
            <div class="fieldset">
              <div class="lg"><span class="n">🧾</span> مشخصات آموزش</div>
              <div class="form-grid">
                <div class="field full">
                  <label>عنوان آموزش <span style="color:var(--red)">*</span></label>
                  <input type="text" name="title" id="tf_title" required maxlength="180"
                    value="<?= h((string)$v('title')) ?>" placeholder="مثلاً اتصال در اندروید با v2rayNG">
                  <div class="hint">همین عنوان روی دکمهٔ آموزش در ربات نشان داده می‌شود</div>
                </div>

                <div class="field">
                  <label>دستگاه / پلتفرم</label>
                  <select name="platform" id="tf_plat">
                    <?php foreach ($PLAT as $k => $label): ?>
                      <option value="<?= h($k) ?>" <?= (string)$v('platform', 'android') === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="hint">دکمه‌های بخش آموزش از همین لیست ساخته می‌شوند</div>
                </div>

                <div class="field">
                  <label>ترتیب نمایش</label>
                  <input class="mono" type="number" name="sort" min="0" value="<?= h((string)$v('sort', 0)) ?>">
                  <div class="hint">عدد کوچک‌تر = بالاتر</div>
                </div>

                <div class="field full">
                  <label class="check">
                    <input type="checkbox" name="active" value="1" <?= (int)$v('active', 1) ? 'checked' : '' ?>>
                    <span>در ربات نمایش داده شود</span>
                  </label>
                </div>
              </div>
            </div>

            <div class="fieldset">
              <div class="lg"><span class="n">📝</span> متن آموزش</div>
              <div class="fs-hint">مرحله به مرحله بنویسید. پیش‌نمایش کناری زنده به‌روز می‌شود.</div>
              <div class="field">
                <textarea name="content" id="tf_body" rows="9" maxlength="3500"
                  placeholder="۱) برنامه را نصب کنید&#10;۲) لینک ساب را کپی کنید&#10;۳) …"><?= h((string)$v('content')) ?></textarea>
                <div class="hint">
                  تگ‌های مجاز: <span class="mono">&lt;b&gt; &lt;i&gt; &lt;code&gt; &lt;a href&gt;</span>
                  · <span id="tf_len">۰</span> از ۳۵۰۰ کاراکتر
                </div>
              </div>
              <div class="row" style="gap:5px;flex-wrap:wrap">
                <button class="btn btn-sm" type="button" data-tut-ins="<b>متن</b>">🅱 درشت</button>
                <button class="btn btn-sm" type="button" data-tut-ins="<i>متن</i>">𝑰 مورب</button>
                <button class="btn btn-sm" type="button" data-tut-ins="<code>کد</code>">⌨ کد</button>
                <button class="btn btn-sm" type="button" data-tut-ins="&#10;• ">• بولت</button>
              </div>
            </div>

            <div class="fieldset">
              <div class="lg"><span class="n">📎</span> لینک و رسانه</div>
              <div class="form-grid">
                <div class="field">
                  <label>لینک دانلود / ویدیو</label>
                  <input class="mono" type="text" dir="ltr" name="link" id="tf_link"
                    value="<?= h((string)$v('link')) ?>" placeholder="https://…">
                  <div class="hint">به‌صورت دکمه زیر پیام نمایش داده می‌شود</div>
                </div>
                <div class="field">
                  <label>شناسه فایل تلگرام (file_id)</label>
                  <input class="mono" type="text" dir="ltr" name="file_id" id="tf_file"
                    value="<?= h((string)$v('file_id')) ?>" placeholder="AgACAgQAAxk…">
                  <div class="hint">برای ارسال عکس/ویدیوی آموزش</div>
                </div>
              </div>
              <div class="alert a-info" style="font-size:11.5px;margin-top:var(--s2)">
                💡 حداقل یکی از سه مورد «متن»، «لینک» یا «فایل» باید پر باشد.
              </div>
            </div>
          </div>

          <!-- پیش‌نمایش زنده -->
          <div>
            <div class="tut-prev">
              <div class="tut-prev-h">📱 پیش‌نمایش در تلگرام</div>
              <div class="tut-prev-b">
                <div class="tut-bub" id="tfPrev">
                  <div class="ph" id="tfPh" hidden>🖼 تصویر / ویدیوی آموزش</div>
                  <div id="tfPrevTxt"></div>
                  <div class="tut-kb" id="tfKb"></div>
                </div>
              </div>
            </div>
            <div class="hint" style="text-align:center;margin-top:8px">پیش‌نمایش تقریبی است و فقط ساختار پیام را نشان می‌دهد.</div>
          </div>

        </div>
      </div>

      <div class="m-foot">
        <?php if ($e): ?>
          <a class="btn btn-ghost" href="index.php?p=tutorials">انصراف</a>
        <?php else: ?>
          <button class="btn btn-ghost" type="button" data-modal-close>انصراف</button>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit">💾 ذخیره آموزش</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
/* ===== Tutorials Studio v1 ===== */
(function () {
  'use strict';

  function faNum(n) {
    var d = '۰۱۲۳۴۵۶۷۸۹';
    return String(n).replace(/[0-9]/g, function (x) { return d[+x]; });
  }
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  /* -------- تغییر نما -------- */
  var grid  = document.getElementById('tutGrid');
  var table = document.getElementById('tutTableWrap');
  var KEY   = 'srbotTutView';

  function setView(v) {
    if (!grid || !table) return;
    var isT = v === 'table';
    grid.hidden  = isT;
    table.hidden = !isT;
    document.querySelectorAll('[data-tut-view]').forEach(function (b) {
      b.classList.toggle('on', b.getAttribute('data-tut-view') === v);
    });
    try { localStorage.setItem(KEY, v); } catch (e) {}
  }
  document.querySelectorAll('[data-tut-view]').forEach(function (b) {
    b.addEventListener('click', function () { setView(b.getAttribute('data-tut-view')); });
  });
  try { setView(localStorage.getItem(KEY) || 'grid'); } catch (e) { setView('grid'); }

  /* -------- جستجو -------- */
  var find = document.getElementById('tutFind');
  var out  = document.getElementById('tutCount');
  if (find) {
    find.addEventListener('input', function () {
      var q = find.value.trim().toLowerCase();
      var n = 0;
      document.querySelectorAll('[data-hay]').forEach(function (el) {
        var hit = !q || (el.getAttribute('data-hay') || '').indexOf(q) !== -1;
        el.style.display = hit ? '' : 'none';
        if (hit && el.hasAttribute('data-tut-card')) n++;
      });
      if (out) out.textContent = faNum(n) + ' مورد';
    });
  }

  /* -------- انتخاب گروهی -------- */
  var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-tut-box]'));
  var bulk  = document.getElementById('tutBulk');
  var cnt   = document.getElementById('tutCnt');
  var bform = document.getElementById('tutBulkForm');
  var bmode = document.getElementById('tutMode');

  function sync() {
    var on = boxes.filter(function (b) { return b.checked; });
    boxes.forEach(function (b) {
      var c = b.closest('[data-tut-card]');
      if (c) c.classList.toggle('sel', b.checked);
    });
    if (!bulk) return;
    bulk.hidden = on.length === 0;
    if (cnt) cnt.textContent = faNum(on.length);
  }
  boxes.forEach(function (b) { b.addEventListener('change', sync); });

  var none = document.getElementById('tutNone');
  if (none) none.addEventListener('click', function () {
    boxes.forEach(function (b) { b.checked = false; });
    sync();
  });

  document.querySelectorAll('[data-tut-bulk]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var m = btn.getAttribute('data-tut-bulk');
      var n = boxes.filter(function (b) { return b.checked; }).length;
      if (!n) return;
      if (m === 'del' && !window.confirm(faNum(n) + ' آموزش حذف شود؟ این کار برگشت‌پذیر نیست.')) return;
      if (bmode) bmode.value = m;
      if (bform) bform.submit();
    });
  });

  /* -------- عملیات تکی -------- */
  var one = document.getElementById('tutOneForm');
  document.querySelectorAll('[data-tut-one]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!one) return;
      var a = btn.getAttribute('data-tut-one');
      if (a === 'del' && !window.confirm('این آموزش حذف شود؟')) return;
      document.getElementById('to_act').value = a;
      document.getElementById('to_id').value  = btn.getAttribute('data-id');
      document.getElementById('to_dir').value = btn.getAttribute('data-dir') || '';
      one.submit();
    });
  });

  /* -------- پیش‌نمایش زنده -------- */
  var fT = document.getElementById('tf_title');
  var fB = document.getElementById('tf_body');
  var fL = document.getElementById('tf_link');
  var fF = document.getElementById('tf_file');
  var pT = document.getElementById('tfPrevTxt');
  var pK = document.getElementById('tfKb');
  var pP = document.getElementById('tfPh');
  var pLen = document.getElementById('tf_len');

  function render() {
    if (!pT) return;
    var title = fT ? fT.value.trim() : '';
    var body  = fB ? fB.value : '';

    var html = '';
    if (title) html += '<b>' + esc(title) + '</b>\n\n';

    /* تگ‌های مجاز تلگرام را زنده نشان می‌دهیم، بقیه را امن می‌کنیم */
    var safe = esc(body)
      .replace(/&lt;(\/?)(b|i|u|s|code|pre)&gt;/g, '<$1$2>')
      .replace(/&lt;a href=&quot;([^&]*)&quot;&gt;/g, '<a href="$1">')
      .replace(/&lt;a href="([^"]*)"&gt;/g, '<a href="$1">')
      .replace(/&lt;\/a&gt;/g, '</a>');

    html += safe;
    pT.innerHTML = html || '<span style="opacity:.5">متن آموزش اینجا دیده می‌شود…</span>';

    if (pP) pP.hidden = !(fF && fF.value.trim());

    if (pK) {
      pK.innerHTML = '';
      if (fL && fL.value.trim()) {
        var s = document.createElement('span');
        s.textContent = '🔗 دانلود / مشاهده';
        pK.appendChild(s);
      }
      var back = document.createElement('span');
      back.textContent = '⬅️ بازگشت';
      pK.appendChild(back);
    }

    if (pLen && fB) pLen.textContent = faNum(fB.value.length);
  }

  [fT, fB, fL, fF].forEach(function (el) {
    if (el) el.addEventListener('input', render);
  });

  /* -------- دکمه‌های درج تگ -------- */
  document.querySelectorAll('[data-tut-ins]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!fB) return;
      var ins = btn.getAttribute('data-tut-ins') || '';
      var s = fB.selectionStart || 0;
      var e = fB.selectionEnd || 0;
      var sel = fB.value.substring(s, e);
      if (sel && ins.indexOf('متن') !== -1) ins = ins.replace('متن', sel);
      if (sel && ins.indexOf('کد') !== -1)  ins = ins.replace('کد', sel);
      fB.value = fB.value.substring(0, s) + ins + fB.value.substring(e);
      fB.focus();
      fB.selectionStart = fB.selectionEnd = s + ins.length;
      render();
    });
  });

  render();
  sync();
})();
</script>
