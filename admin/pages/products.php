<?php
/** محصولات (طرح‌های فروش) – لیست‌محور با فرم مودال و دسته‌بندی */
declare(strict_types=1);

if (!can('products.view')) { echo denyBox('بخش محصولات برای شما فعال نیست.'); return; }

$act = (string)($_POST['act'] ?? '');

/* ستون‌های اختیاری محدودیت دستگاه و سرعت (پس از اجرای مایگریشن فعال می‌شوند) */
$prCols = [];
try {
    foreach (DB::all('SHOW COLUMNS FROM {p}products') as $c) $prCols[(string)($c['Field'] ?? '')] = true;
} catch (Throwable $e) {
    $prCols = [];
}
$prHasLimits = isset($prCols['device_limit'], $prCols['speed_down'], $prCols['speed_up']);
/* fixed76: نوع محصول «حجم و زمان دلخواه» (پس از اجرای مایگریشن فعال می‌شود) */
$prHasCus = isset($prCols['type'], $prCols['price_gb'], $prCols['price_day'], $prCols['min_gb'], $prCols['max_gb'], $prCols['min_days'], $prCols['max_days']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {

    if ($act === 'save') {
        $id = pint('id');
        need($id ? 'products.edit' : 'products.create', 'products');

        $data = [
            'panel_id'    => pint('panel_id'),
            'inbound_id'  => pint('inbound_id'),
            'name'        => ptxt('name'),
            'category'    => ptxt('category', 'عمومی'),
            'description' => ptxt('description'),
            'volume_gb'   => pflt('volume_gb'),
            'days'        => pint('days'),
            'ip_limit'    => pint('ip_limit'),
            'price'       => pflt('price'),
            'old_price'   => pflt('old_price'),
            'stock'       => pint('stock', -1),
            'active'      => pchk('active'),
            'sort'        => pint('sort'),
        ];

        /* نحوهٔ تحویل: خالی = پیروی از پیش‌فرض کلی فروشگاه */
        if (isset($prCols['group_ids'])) $data['group_ids'] = mb_substr(trim(en_num((string)ptxt('group_ids'))), 0, 190); /* fixed79: گروه‌های پاسارگارد این محصول */
        if (isset($prCols['deliver_mode'])) {
            $dmIn = (string)ptxt('deliver_mode');
            $data['deliver_mode'] = isset(Svc::DELIVER[$dmIn]) ? $dmIn : '';
        }

        foreach (['device_limit', 'speed_up', 'speed_down', 'reset_days'] as $lk) {
            if (isset($prCols[$lk])) $data[$lk] = max(0, pint($lk));
        }

        /* fixed76: نوع محصول — «حجم و زمان دلخواه» با قیمت هر گیگ/هر روز و بازهٔ مجاز */
        if ($prHasCus) {
            $data['type']      = ptxt('type') === 'custom' ? 'custom' : 'fixed';
            $data['price_gb']  = max(0, (int)pflt('price_gb'));
            $data['price_day'] = max(0, (int)pflt('price_day'));
            $data['min_gb']    = max(1, pint('min_gb', 1));
            $data['max_gb']    = max($data['min_gb'], pint('max_gb', 100));
            $data['min_days']  = max(1, pint('min_days', 1));
            $data['max_days']  = max($data['min_days'], pint('max_days', 90));
            if ($data['type'] === 'custom') {
                if ($data['price_gb'] <= 0 && $data['price_day'] <= 0) {
                    flash('err', 'برای محصول «حجم و زمان دلخواه» حداقل یکی از «قیمت هر گیگ» یا «قیمت هر روز» باید بزرگ‌تر از صفر باشد.');
                    back('products', $id ? ['edit' => $id] : []);
                }
                /* حجم/مدت را مشتری انتخاب می‌کند؛ قیمت ثبت‌شده = کمترین مبلغ ممکن (برای مرتب‌سازی و نمایش «از …») */
                $data['volume_gb'] = 0;
                $data['days']      = 0;
                $data['price']     = $data['min_gb'] * $data['price_gb'] + $data['min_days'] * $data['price_day'];
            }
        }

        if ($data['name'] === '' || !$data['panel_id']) {
            flash('err', 'نام محصول و انتخاب پنل الزامی است.');
            back('products', $id ? ['edit' => $id] : []);
        }

        if ($id) {
            DB::update('products', $data, 'id = :id', [':id' => $id]);
            flash('ok', '✅ محصول <b>' . h($data['name']) . '</b> به‌روز شد.');
        } else {
            $data['created_at'] = now();
            DB::insert('products', $data);
            flash('ok', '✅ محصول <b>' . h($data['name']) . '</b> افزوده شد.');
        }
        back('products');
    }

    /* پیش‌فرض کلی تحویل برای همهٔ محصولات */
    if ($act === 'delmode') {
        need('products.edit', 'products');
        $dmIn = (string)ptxt('deliver_mode');
        DB::setSetting('deliver_mode', isset(Svc::DELIVER[$dmIn]) ? $dmIn : 'both');
        flash('ok', '✅ پیش‌فرض تحویل ذخیره شد.');
        back('products');
    }

    if ($act === 'del') {
        need('products.delete', 'products');
        DB::delete('products', 'id = :id', [':id' => pint('id')]);
        flash('ok', '🗑 محصول حذف شد.');
        back('products');
    }

    if ($act === 'toggle') {
        need('products.edit', 'products');
        $p = DB::one('SELECT * FROM {p}products WHERE id = :id', [':id' => pint('id')]);
        if ($p) DB::update('products', ['active' => (int)$p['active'] ? 0 : 1], 'id = :id', [':id' => (int)$p['id']]);
        back('products');
    }

    if ($act === 'clone') {
        need('products.create', 'products');
        $p = DB::one('SELECT * FROM {p}products WHERE id = :id', [':id' => pint('id')]);
        if ($p) {
            unset($p['id'], $p['sold']);
            $p['name'] = $p['name'] . ' (کپی)';
            $p['created_at'] = now();
            DB::insert('products', $p);
            flash('ok', '⧉ کپی محصول ساخته شد.');
        }
        back('products');
    }
}

$panels   = DB::all('SELECT * FROM {p}panels ORDER BY sort ASC, id ASC');
$products = DB::all('SELECT pr.*, pa.name AS panel_name FROM {p}products pr
                     LEFT JOIN {p}panels pa ON pa.id = pr.panel_id
                     ORDER BY pr.sort ASC, pr.id DESC');
$editId = (int)($_GET['edit'] ?? 0);
$e      = $editId ? DB::one('SELECT * FROM {p}products WHERE id = :id', [':id' => $editId]) : null;
$v      = static function (string $k, $d = '') use ($e) { return $e[$k] ?? $d; };

/* دسته‌بندی */
$cats = [];
foreach ($products as $p) {
    $c = (string)($p['category'] ?: 'عمومی');
    if (!isset($cats[$c])) $cats[$c] = [];
    $cats[$c][] = $p;
}
ksort($cats);
$catFilter = (string)($_GET['cat'] ?? '');

/* آمار */
$cntOn = 0; $sold = 0; $revenue = 0.0;
foreach ($products as $p) {
    if ((int)$p['active']) $cntOn++;
    $sold    += (int)$p['sold'];
    $revenue += (float)$p['price'] * (int)$p['sold'];
}

$canWrite = can('products.create') || can('products.edit');
?>

<?php if (!$panels): ?>
  <div class="alert a-warn">
    اول از بخش <b>سرورها و پنل‌ها</b> یک پنل اضافه کنید، سپس محصول بسازید.
    <div class="mt3"><a class="btn btn-primary btn-sm" href="index.php?p=panels">رفتن به پنل‌ها</a></div>
  </div>
<?php endif; ?>

<div class="page-head">
  <div>
    <h2>📦 محصولات</h2>
    <div class="sub">طرح‌های فروش در بخش «🛒 محصولات» ربات بر اساس دسته‌بندی نمایش داده می‌شوند</div>
  </div>
  <div class="acts">
    <?php if (can('products.create') && $panels): ?>
      <button class="btn btn-primary" data-modal="mProd">➕ افزودن محصول</button>
    <?php endif; ?>
  </div>
</div>

<style>
/* ===== Products Studio ===== */
.pr-hero{position:relative;overflow:hidden;border-radius:18px;padding:18px 20px;margin:14px 0 0;
  background:linear-gradient(135deg,rgba(47,212,143,.16),rgba(91,140,255,.10) 55%,transparent),var(--surface);
  border:1px solid var(--border)}
.pr-hero::after{content:"";position:absolute;inset-inline-end:-80px;top:-90px;width:250px;height:250px;
  border-radius:50%;background:radial-gradient(circle,rgba(47,212,143,.20),transparent 70%);pointer-events:none}
.pr-hero h3{margin:0 0 5px;font-size:16.5px;display:flex;align-items:center;gap:9px}
.pr-hero .s{color:var(--muted);font-size:12.5px;line-height:1.95}
.pr-cells{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-top:15px;position:relative;z-index:1}
.pr-cell{background:var(--surface-2);border:1px solid var(--border);border-radius:13px;padding:11px 9px;text-align:center}
.pr-cell .v{font-size:18px;font-weight:700;line-height:1.3;direction:ltr;unicode-bidi:isolate}
.pr-cell .l{font-size:10.5px;color:var(--muted);margin-top:4px}
.pr-cell.g .v{color:var(--green)}.pr-cell.o .v{color:var(--orange)}
.pr-cell.b .v{color:var(--accent)}.pr-cell.r .v{color:var(--red)}

.pr-seg{display:inline-flex;gap:4px;padding:4px;border-radius:12px;background:var(--surface-2);
  border:1px solid var(--border);margin-bottom:12px}
.pr-seg button{border:0;background:transparent;color:var(--text-dim);cursor:pointer;font-family:inherit;
  font-size:12.5px;font-weight:600;padding:7px 13px;border-radius:9px;transition:.15s}
.pr-seg button.on{background:var(--grad);color:#fff;box-shadow:var(--glow)}

.pr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(275px,1fr));gap:12px}
.pr-card{position:relative;border:1px solid var(--border);border-radius:17px;background:var(--surface-2);
  padding:14px 15px;display:flex;flex-direction:column;gap:10px;transition:.18s}
.pr-card:hover{border-color:var(--accent);transform:translateY(-2px);box-shadow:0 10px 26px rgba(0,0,0,.22)}
.pr-card::before{content:"";position:absolute;inset-inline:15px;top:0;height:2px;border-radius:2px;
  background:linear-gradient(90deg,var(--green),var(--accent));opacity:.85}
.pr-card.off{opacity:.56}
.pr-card.off::before{background:var(--muted)}
.pr-card .ptop{display:flex;align-items:flex-start;gap:9px;min-width:0}
.pr-card .pnm{font-weight:700;font-size:14px;line-height:1.5;overflow-wrap:anywhere;flex:1;min-width:0}
.pr-card .pdot{width:9px;height:9px;border-radius:50%;flex:0 0 auto;margin-top:6px}
.pr-card .pdot.on{background:var(--green);box-shadow:0 0 0 4px rgba(47,212,143,.16)}
.pr-card .pdot.no{background:var(--muted)}
.pr-card .pds{font-size:11.5px;color:var(--muted);line-height:1.85;overflow-wrap:anywhere}
.pr-card .ptags{display:flex;flex-wrap:wrap;gap:5px}
.pr-card .pspec{display:flex;flex-wrap:wrap;gap:6px}
.pr-card .sp{display:inline-flex;align-items:center;gap:4px;font-size:11.5px;padding:5px 9px;
  border-radius:9px;background:var(--surface-3);border:1px solid var(--border);color:var(--text-dim)}
.pr-card .pprice{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;padding-top:2px}
.pr-card .pprice .now{font-size:18px;font-weight:800;color:var(--green);direction:ltr;unicode-bidi:isolate}
.pr-card .pprice .cur{font-size:11px;color:var(--muted);font-weight:500}
.pr-card .pprice .was{font-size:12px;color:var(--muted);text-decoration:line-through;direction:ltr;unicode-bidi:isolate}
.pr-card .pprice .off{font-size:10.5px;font-weight:700;color:#fff;background:var(--red);
  padding:2px 7px;border-radius:99px}
.pr-card .pacts{display:flex;gap:5px;flex-wrap:wrap;padding-top:10px;border-top:1px solid var(--border)}
.pr-card .pacts form{display:inline;margin:0}

/* ---- مودال افزودن محصول ---- */
/* نوار گام‌ها — تنها مسیر جابه‌جایی بین بخش‌های مودال */
.pr-steps{display:flex;align-items:stretch;gap:5px;margin:0 0 14px;padding:6px;border-radius:17px;
  background:linear-gradient(180deg,var(--surface-3),var(--surface-2));border:1px solid var(--border);
  box-shadow:inset 0 1px 0 rgba(255,255,255,.05);overflow-x:auto;scrollbar-width:none}
.pr-steps::-webkit-scrollbar{display:none}
.pr-st{position:relative;flex:1 1 0;min-width:126px;display:flex;align-items:center;gap:9px;
  padding:10px 12px;border-radius:13px;background:transparent;border:1px solid transparent;
  font-family:inherit;font-size:11.5px;font-weight:600;color:var(--muted);text-align:start;
  white-space:nowrap;cursor:pointer;transition:.18s ease;user-select:none}
.pr-st::before{content:"";position:absolute;inset-inline-start:-5px;top:50%;width:5px;height:2px;
  margin-top:-1px;border-radius:99px;background:var(--border)}
.pr-st:first-child::before{display:none}
.pr-st:hover{background:var(--surface-3);color:var(--text)}
.pr-st .n{width:25px;height:25px;flex:0 0 25px;border-radius:50%;display:grid;place-items:center;
  background:var(--surface-3);border:1px solid var(--border);font-size:11px;font-weight:800;transition:.18s ease}
.pr-st .t{overflow:hidden;text-overflow:ellipsis}
.pr-st.ok{color:var(--text)}
.pr-st.ok .n{background:var(--green-soft);border-color:transparent;color:var(--green);font-size:0}
.pr-st.ok .n::after{content:"✓";font-size:13px;line-height:1;font-weight:800}
.pr-st.on{background:var(--grad);border-color:transparent;color:#fff;box-shadow:var(--glow);transform:translateY(-1px)}
.pr-st.on .n{background:rgba(255,255,255,.22);border-color:transparent;color:#fff;font-size:11px}
.pr-st.on .n::after{content:none}
.pr-st .dot{width:7px;height:7px;flex:0 0 7px;border-radius:50%;background:var(--red);
  margin-inline-start:auto;box-shadow:0 0 0 3px rgba(255,107,107,.16)}
.pr-st.on .dot{background:#fff;box-shadow:0 0 0 3px rgba(255,255,255,.22)}
@media (max-width:640px){.pr-st{flex:0 0 auto;min-width:auto;padding:9px 11px}}

.pr-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:4px}
.pchip{border:1px solid var(--border);background:var(--surface-3);color:var(--text-dim);cursor:pointer;
  font-family:inherit;font-size:11.5px;font-weight:600;padding:6px 11px;border-radius:99px;transition:.15s}
.pchip:hover{border-color:var(--accent);color:var(--text);background:var(--accent-soft)}

.pr-calc{margin-top:10px;padding:11px 13px;border-radius:12px;border:1px dashed var(--border);
  background:var(--surface-3);font-size:12.5px;display:flex;flex-wrap:wrap;gap:14px;align-items:center}
.pr-calc b{color:var(--green);direction:ltr;unicode-bidi:isolate}
.pr-calc .bad{color:var(--orange)}

.pr-sum{border:1px solid var(--border);border-radius:15px;overflow:hidden;background:var(--surface-2)}
.pr-sum .hd{padding:11px 14px;background:var(--surface-3);border-bottom:1px solid var(--border);
  font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px}
.pr-sum .rw{display:flex;align-items:center;gap:10px;padding:10px 14px;
  border-bottom:1px solid var(--border);font-size:12.5px}
.pr-sum .rw:last-child{border-bottom:0}
.pr-sum .rw .k{color:var(--muted);flex:0 0 42%}
.pr-sum .rw .v{flex:1;min-width:0;font-weight:600;overflow-wrap:anywhere}
.pr-sum .rw .v.em{color:var(--muted);font-weight:400}

.pr-chk{display:flex;flex-direction:column;gap:7px;margin-top:12px}
.pr-ck{display:flex;align-items:center;gap:9px;padding:9px 12px;border-radius:11px;
  border:1px solid var(--border);background:var(--surface-2);font-size:12.5px}
.pr-ck .i{width:20px;height:20px;flex:0 0 20px;border-radius:50%;display:grid;place-items:center;
  font-size:11px;font-weight:700}
.pr-ck.ok .i{background:var(--green-soft);color:var(--green)}
.pr-ck.no{border-color:var(--red)}
.pr-ck.no .i{background:var(--red-soft);color:var(--red)}

.pr-prev{margin-top:12px}
.pr-prev .ttl{font-size:12px;color:var(--muted);margin-bottom:7px}
.pr-prev .bubble{border:1px solid var(--border);border-radius:16px;padding:14px 15px;
  background:linear-gradient(135deg,var(--accent-soft),transparent 70%),var(--surface-2)}
.pr-prev .bn{font-weight:700;font-size:14.5px;line-height:1.6;overflow-wrap:anywhere}
.pr-prev .bd{font-size:11.5px;color:var(--muted);line-height:1.9;margin-top:4px;overflow-wrap:anywhere}
.pr-prev .bchips{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
.pr-prev .bchips span:empty{display:none}
.pr-prev .bchips span{font-size:11.5px;padding:5px 9px;border-radius:9px;
  background:var(--surface-3);border:1px solid var(--border);color:var(--text-dim)}
.pr-prev .bp{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;margin-top:11px;
  padding-top:11px;border-top:1px solid var(--border)}
.pr-prev .bp b{font-size:18px;color:var(--green);direction:ltr;unicode-bidi:isolate}
.pr-prev .bp s{font-size:12px;color:var(--muted);direction:ltr;unicode-bidi:isolate}
.pr-prev .bp s:empty{display:none}
.pr-prev .bp em{font-size:10.5px;font-weight:700;font-style:normal;color:#fff;background:var(--red);
  padding:2px 7px;border-radius:99px}
.pr-prev .bp em:empty{display:none}

@media (max-width:900px){.pr-cells{grid-template-columns:repeat(3,1fr)}}
@media (max-width:560px){
  .pr-cells{grid-template-columns:repeat(2,1fr)}
  .pr-grid{grid-template-columns:1fr}
  .pr-sum .rw{flex-direction:column;align-items:flex-start;gap:3px}
  .pr-sum .rw .k{flex:none}
}
</style>

<div class="pr-hero">
  <h3>📦 مرکز طرح‌های فروش</h3>
  <div class="s">
    ساخت، قیمت‌گذاری و دسته‌بندی محصولاتی که در ربات به فروش می‌رسند.<br>
    هر محصول به یک <b>پنل</b> وصل است و پس از خرید، سرویس روی همان پنل ساخته می‌شود
  </div>

  <div class="pr-cells">
    <div class="pr-cell b"><div class="v"><?= fa_num(count($products)) ?></div><div class="l">کل محصولات</div></div>
    <div class="pr-cell g"><div class="v"><?= fa_num($cntOn) ?></div><div class="l">فعال در ربات</div></div>
    <div class="pr-cell <?= (count($products) - $cntOn) > 0 ? 'o' : '' ?>"><div class="v"><?= fa_num(count($products) - $cntOn) ?></div><div class="l">مخفی</div></div>
    <div class="pr-cell"><div class="v"><?= fa_num(count($cats)) ?></div><div class="l">دسته‌بندی</div></div>
    <div class="pr-cell o"><div class="v"><?= fa_num($sold) ?></div><div class="l">کل فروش</div></div>
    <div class="pr-cell g"><div class="v"><?= money($revenue) ?></div><div class="l">درآمد (<?= h(currency()) ?>)</div></div>
  </div>
</div>

<?php
$dmDef  = Svc::deliverDefault();
$dmHas  = isset($prCols['deliver_mode']);
$dmStat = ['sub' => 0, 'config' => 0, 'both' => 0, 'def' => 0];
if ($dmHas) {
    foreach ($products as $pp) {
        $mm = (string)($pp['deliver_mode'] ?? '');
        if (isset($dmStat[$mm])) $dmStat[$mm]++; else $dmStat['def']++;
    }
} else {
    $dmStat['def'] = count($products);
}
?>
<section class="pr-dm">
  <div class="dm-head">
    <div class="dm-ic">📤</div>
    <div>
      <b>نحوهٔ تحویل کانفیگ به خریدار</b>
      <div class="muted xs">
        مشخص کنید پس از خرید، ربات <b>لینک ساب</b> بدهد، <b>کانفیگ مستقیم</b> بدهد یا <b>هردو</b>.
        این پیش‌فرض کلی است و هر محصول می‌تواند تنظیم خودش را داشته باشد.
      </div>
    </div>
    <div class="dm-now"><?= h(Svc::deliverLabel($dmDef)) ?></div>
  </div>

  <?php if (can('products.edit')): ?>
    <form method="post" class="dm-form">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="delmode">
      <div class="dm-opts">
        <?php foreach (Svc::DELIVER as $dk => $dv): ?>
          <label class="dm-op <?= $dmDef === $dk ? 'on' : '' ?>">
            <input type="radio" name="deliver_mode" value="<?= h($dk) ?>" <?= $dmDef === $dk ? 'checked' : '' ?>>
            <span class="i"><?= h($dv[2]) ?></span>
            <span class="t"><?= h($dv[0]) ?></span>
            <span class="d"><?= h($dv[1]) ?></span>
            <span class="c"><?= fa_num((int)($dmStat[$dk] ?? 0)) ?> محصول</span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="dm-foot">
        <span class="muted xs">🔹 <?= fa_num($dmStat['def']) ?> محصول از همین پیش‌فرض پیروی می‌کنند.</span>
        <button class="btn btn-primary btn-sm">💾 ذخیرهٔ پیش‌فرض</button>
      </div>
    </form>
  <?php endif; ?>

  <?php if (!$dmHas): ?>
    <div class="alert a-warn mt3">⚠️ برای تنظیم جداگانهٔ هر محصول، یک بار از صفحهٔ به‌روزرسانی گزینهٔ «🧩 بررسی و تکمیل ساختار دیتابیس» را اجرا کنید.</div>
  <?php endif; ?>
</section>

<?php if ($products): ?>
<div class="tabbar mt4">
  <a class="<?= $catFilter === '' ? 'on' : '' ?>" href="index.php?p=products">📂 همه <span class="n"><?= fa_num(count($products)) ?></span></a>
  <?php foreach ($cats as $cname => $items): ?>
    <a class="<?= $catFilter === (string)$cname ? 'on' : '' ?>" href="index.php?p=products&cat=<?= urlencode((string)$cname) ?>">
      <?= h((string)$cname) ?> <span class="n"><?= fa_num(count($items)) ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card mt3">
  <div class="card-head">
    <div>
      <div class="card-title">📋 لیست محصولات<?= $catFilter !== '' ? ' – ' . h($catFilter) : '' ?></div>
      <div class="card-sub">نمایش <span id="prodCount"><?= fa_num($catFilter !== '' ? count($cats[$catFilter] ?? []) : count($products)) ?></span> محصول</div>
    </div>
    <div class="row">
      <input type="search" style="max-width:190px" placeholder="🔎 جستجوی محصول" data-live-filter="#prodTable tbody tr" data-live-count="#prodCount">
    </div>
  </div>

  <?php
  $rows = $catFilter !== '' ? ($cats[$catFilter] ?? []) : $products;
  if (!$rows): ?>
    <div class="empty">
      <div class="ic">📦</div>
      هنوز محصولی ثبت نشده است.<br>
      <span class="muted">برای شروع روی دکمه‌ی «افزودن محصول» بزنید.</span>
      <?php if (can('products.create') && $panels): ?>
        <div class="mt3"><button class="btn btn-primary" data-modal="mProd">➕ افزودن محصول</button></div>
      <?php endif; ?>
    </div>
  <?php else: ?>

  <div class="pr-seg" id="prSeg">
    <button type="button" class="on" data-pview="grid">🔳 کارت‌ها</button>
    <button type="button" data-pview="table">📋 جدول</button>
  </div>

  <div class="pr-grid" id="prodGrid">
    <?php foreach ($rows as $p):
        $rVol = (float)$p['volume_gb'];
        $rDay = (int)$p['days'];
        $rNow = (float)$p['price'];
        $rWas = (float)$p['old_price'];
        $rOn  = (int)$p['active'];
        $rStk = (int)$p['stock'];
        $rIp  = (int)$p['ip_limit'];

        $rOff = 0;
        if ($rWas > $rNow && $rWas > 0) {
            $rOff = (int)round(($rWas - $rNow) / $rWas * 100);
        }

        $rVolTxt = 'حجم نامحدود';
        if ($rVol > 0) {
            $rVolTxt = fa_num((string)$rVol) . ' گیگ';
        }

        $rDayTxt = 'بدون انقضا';
        if ($rDay > 0) {
            $rDayTxt = fa_num($rDay) . ' روز';
        }

        /* fixed76: محصول «حجم و زمان دلخواه» */
        $rCus = (string)($p['type'] ?? 'fixed') === 'custom';
        if ($rCus) {
            $rVolTxt = 'هر گیگ ' . money((float)($p['price_gb'] ?? 0)) . ' · ' . fa_num((int)($p['min_gb'] ?? 1)) . '–' . fa_num((int)($p['max_gb'] ?? 100)) . ' گیگ';
            $rDayTxt = 'هر روز ' . money((float)($p['price_day'] ?? 0)) . ' · ' . fa_num((int)($p['min_days'] ?? 1)) . '–' . fa_num((int)($p['max_days'] ?? 90)) . ' روز';
        }

        $rStkTxt = 'موجودی نامحدود';
        if ($rStk === 0) {
            $rStkTxt = 'ناموجود';
        } elseif ($rStk > 0) {
            $rStkTxt = 'موجودی ' . fa_num($rStk);
        }
    ?>
      <div class="pr-card <?= $rOn ? '' : 'off' ?>">
        <div class="ptop">
          <div class="pnm"><?= h((string)$p['name']) ?></div>
          <span class="pdot <?= $rOn ? 'on' : 'no' ?>" title="<?= $rOn ? 'فعال' : 'مخفی' ?>"></span>
        </div>

        <?php if ($p['description']): ?>
          <div class="pds"><?= h(mb_substr((string)$p['description'], 0, 90)) ?></div>
        <?php endif; ?>

        <div class="ptags">
          <span class="badge b-blue">🏷 <?= h((string)$p['category']) ?></span>
          <span class="badge b-gray">🖧 <?= h((string)($p['panel_name'] ?: 'نامشخص')) ?></span>
          <?php if ((int)$p['inbound_id']): ?>
            <span class="badge b-gray">📡 #<?= (int)$p['inbound_id'] ?></span>
          <?php endif; ?>
          <?php $rDm = (string)($p['deliver_mode'] ?? ''); if ($rDm !== '' && isset(Svc::DELIVER[$rDm])): ?>
            <span class="badge b-purple"><?= h(Svc::DELIVER[$rDm][2] . ' ' . Svc::DELIVER[$rDm][0]) ?></span>
          <?php endif; ?>
          <?php if ($rCus): ?><span class="badge b-orange">📐 حجم و زمان دلخواه</span><?php endif; ?>
          <?php if (!$rOn): ?><span class="badge b-gray">مخفی</span><?php endif; ?>
        </div>

        <div class="pspec">
          <span class="sp">📊 <?= $rVolTxt ?></span>
          <span class="sp">⏱ <?= $rDayTxt ?></span>
          <?php if ($rIp > 0): ?><span class="sp">👥 <?= fa_num($rIp) ?> آیپی</span><?php endif; ?>
          <?php if ($prHasLimits && (int)($p['device_limit'] ?? 0) > 0): ?>
            <span class="sp">📱 <?= fa_num((int)$p['device_limit']) ?> دستگاه</span>
          <?php endif; ?>
          <span class="sp"><?= $rStk === 0 ? '⛔' : '📦' ?> <?= $rStkTxt ?></span>
          <span class="sp">🛒 <?= fa_num((int)$p['sold']) ?> فروش</span>
        </div>

        <div class="pprice">
          <span class="now"><?= ($rCus ? 'از ' : '') . money($rNow) ?></span>
          <span class="cur"><?= h(currency()) ?></span>
          <?php if ($rWas > 0): ?><span class="was"><?= money($rWas) ?></span><?php endif; ?>
          <?php if ($rOff > 0): ?><span class="off"><?= fa_num($rOff) ?>٪ تخفیف</span><?php endif; ?>
        </div>

        <div class="pacts">
          <?php if (can('products.edit')): ?>
            <a class="btn btn-sm" href="index.php?p=products&edit=<?= (int)$p['id'] ?>">✏️ ویرایش</a>
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="btn btn-sm" title="فعال/غیرفعال">⏻</button></form>
          <?php endif; ?>
          <?php if (can('products.create')): ?>
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="act" value="clone"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="btn btn-sm" title="کپی">⧉</button></form>
          <?php endif; ?>
          <?php if (can('products.delete')): ?>
            <form method="post" data-confirm="محصول «<?= h((string)$p['name']) ?>» حذف شود؟"><?= csrf_field() ?>
              <input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="btn btn-sm btn-red">🗑</button></form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="table-wrap" id="prodTableWrap" style="display:none">
    <table id="prodTable" class="responsive">
      <thead>
        <tr>
          <th class="grow-col">محصول</th><th class="tight">دسته</th><th>پنل</th><th class="tight">حجم / مدت</th>
          <th class="tight">آیپی / دستگاه</th><th class="tight">قیمت</th><th class="tight">فروش رفته</th><th class="tight">موجودی</th>
          <th class="tight">وضعیت</th><th data-l="">عملیات</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $p): ?>
        <tr>
          <td data-l="محصول" class="grow-col">
            <div class="idcell">
              <span class="nm"><?= h((string)$p['name']) ?></span>
              <?php if ($p['description']): ?>
                <span class="sub"><?= h(mb_substr((string)$p['description'], 0, 40)) ?></span>
              <?php endif; ?>
            </div>
          </td>
          <td data-l="دسته" class="tight"><span class="badge b-blue"><?= h((string)$p['category']) ?></span></td>
          <td data-l="پنل">
            <span class="cellbox">
              <span><?= h((string)($p['panel_name'] ?: 'نامشخص')) ?></span>
              <?php if ((int)$p['inbound_id']): ?><span class="mono">#<?= (int)$p['inbound_id'] ?></span><?php endif; ?>
            </span>
          </td>
          <td data-l="حجم / مدت" class="num tight">
            <?php if ((string)($p['type'] ?? 'fixed') === 'custom'): ?>
            <span class="numbox a">📐 دلخواه</span>
            <span class="numbox"><?= fa_num((int)($p['min_gb'] ?? 1)) ?>–<?= fa_num((int)($p['max_gb'] ?? 100)) ?>گیگ · <?= fa_num((int)($p['min_days'] ?? 1)) ?>–<?= fa_num((int)($p['max_days'] ?? 90)) ?>روز</span>
            <?php else: ?>
            <span class="numbox"><?= (float)$p['volume_gb'] > 0 ? fa_num((string)(float)$p['volume_gb']) . 'گیگ' : 'نامحدود' ?></span>
            <span class="numbox"><?= (int)$p['days'] > 0 ? fa_num((int)$p['days']) . 'روز' : 'بی‌نهایت' ?></span>
            <?php endif; ?>
          </td>
          <td data-l="آیپی / دستگاه" class="num tight">
            <span class="numbox"><?= (int)$p['ip_limit'] > 0 ? fa_num((int)$p['ip_limit']) : '—' ?></span>
            <?php if ($prHasLimits && (int)($p['device_limit'] ?? 0) > 0): ?>
              <span class="numbox a"><?= fa_num((int)$p['device_limit']) ?>د</span>
            <?php endif; ?>
          </td>
          <td data-l="قیمت" class="num tight">
            <span class="numbox big g"><?= ((string)($p['type'] ?? 'fixed') === 'custom' ? 'از ' : '') . money((float)$p['price']) ?></span>
            <?php if ((float)$p['old_price'] > 0): ?>
              <div class="muted" style="font-size:11.5px;text-decoration:line-through"><?= money((float)$p['old_price']) ?></div>
            <?php endif; ?>
          </td>
          <td data-l="فروش رفته" class="num tight"><span class="numbox <?= (int)$p['sold'] > 0 ? 'a' : '' ?>"><?= fa_num((int)$p['sold']) ?></span></td>
          <td data-l="موجودی" class="num tight"><span class="numbox <?= (int)$p['stock'] === 0 ? 'r' : '' ?>"><?= (int)$p['stock'] < 0 ? '∞' : fa_num((int)$p['stock']) ?></span></td>
          <td data-l="وضعیت" class="tight"><?= (int)$p['active'] ? '<span class="badge b-green">فعال</span>' : '<span class="badge b-gray">مخفی</span>' ?></td>
          <td class="acts" data-l="">
            <?php if (can('products.edit')): ?>
              <a class="btn btn-sm" href="index.php?p=products&edit=<?= (int)$p['id'] ?>">✏️ ویرایش</a>
              <form method="post" style="display:inline"><?= csrf_field() ?>
                <input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-sm" title="فعال/غیرفعال">⏻</button></form>
            <?php endif; ?>
            <?php if (can('products.create')): ?>
              <form method="post" style="display:inline"><?= csrf_field() ?>
                <input type="hidden" name="act" value="clone"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-sm" title="کپی">⧉</button></form>
            <?php endif; ?>
            <?php if (can('products.delete')): ?>
              <form method="post" style="display:inline" data-confirm="محصول «<?= h((string)$p['name']) ?>» حذف شود؟"><?= csrf_field() ?>
                <input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-sm btn-red">🗑</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($canWrite && $panels): ?>
<!-- ==================== مودال افزودن / ویرایش محصول ==================== -->
<div class="modal wide" id="mProd" data-cur="<?= h(currency()) ?>" <?= $e ? 'data-modal-auto="mProd"' : '' ?>>
  <div class="m-back"></div>
  <div class="m-box">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" value="<?= (int)$editId ?>">

      <div class="m-head">
        <span style="font-size:18px"><?= $e ? '✏️' : '➕' ?></span>
        <h3><?= $e ? 'ویرایش محصول: ' . h((string)$e['name']) : 'افزودن محصول جدید' ?></h3>
        <?php if ($e): ?>
          <a class="x" href="index.php?p=products" style="display:grid;place-items:center;text-decoration:none">✕</a>
        <?php else: ?>
          <button type="button" class="x" data-modal-close>✕</button>
        <?php endif; ?>
      </div>

      <div class="m-body">
        <div class="pr-steps" id="prdSteps">
          <button type="button" class="pr-st" data-tab-group="prd" data-tab="base"  data-st="base"><span class="n">۱</span><span class="t">مشخصات</span></button>
          <button type="button" class="pr-st" data-tab-group="prd" data-tab="spec"  data-st="spec"><span class="n">۲</span><span class="t">حجم و محدودیت</span></button>
          <button type="button" class="pr-st" data-tab-group="prd" data-tab="price" data-st="price"><span class="n">۳</span><span class="t">قیمت</span></button>
          <button type="button" class="pr-st" data-tab-group="prd" data-tab="disp"  data-st="disp"><span class="n">۴</span><span class="t">نمایش</span></button>
          <button type="button" class="pr-st" data-tab-group="prd" data-tab="rev"   data-st="rev"><span class="n">۵</span><span class="t">بازبینی</span></button>
        </div>

        <!-- تب ۱ — مشخصات -->
        <div class="tab-panel" data-tab-panel-group="prd" data-tab-panel="base">
        <div class="fieldset">
          <div class="lg"><span class="n">🧾</span> مشخصات پایه</div>
          <div class="fs-hint">نام و توضیحی که اینجا می‌نویسید، دقیقاً همان چیزی است که خریدار در ربات می‌بیند.</div>
          <div class="form-grid">
            <div class="field full">
              <label>نام محصول <span style="color:var(--red)">*</span></label>
              <input type="text" name="name" value="<?= h((string)$v('name')) ?>" placeholder="مثل��ً ۳۰ گیگ – ۱ ماهه" required>
            </div>
            <div class="field">
              <label>دسته‌بندی</label>
              <input type="text" name="category" list="catsList" value="<?= h((string)$v('category', 'عمومی')) ?>">
              <datalist id="catsList"><?php foreach (array_keys($cats) as $c): ?><option value="<?= h((string)$c) ?>"><?php endforeach; ?></datalist>
              <div class="hint">محصولات هم‌دسته زیر یک دکمه جمع می‌شوند</div>
            </div>
            <div class="field">
              <label>پنل ارائه‌دهنده <span style="color:var(--red)">*</span></label>
              <select name="panel_id" required>
                <option value="">— انتخاب کنید —</option>
                <?php foreach ($panels as $pn): ?>
                  <option value="<?= (int)$pn['id'] ?>" <?= (int)$v('panel_id') === (int)$pn['id'] ? 'selected' : '' ?>>
                    <?= h((string)$pn['name']) ?> (<?= h((string)$pn['type']) ?>)</option>
                <?php endforeach; ?>
              </select>
              <div class="hint">سرویس خریدار روی همین پنل ساخته می‌شود</div>
            </div>
            <div class="field full">
              <label>توضیحات</label>
              <textarea name="description" rows="3" placeholder="مناسب برای موبایل و کامپیوتر، پروتکل VLESS..."><?= h((string)$v('description')) ?></textarea>
              <div class="hint">زیر نام محصول در ربات نمایش داده می‌شود</div>
            </div>
          </div>
        </div>
        </div>

        <!-- تب ۲ — حجم و محدودیت -->
        <div class="tab-panel" data-tab-panel-group="prd" data-tab-panel="spec">
        <div class="fieldset">
          <div class="lg"><span class="n">⚡</span> الگوهای آماده</div>
          <div class="fs-hint">یکی را بزنید تا حجم و مدت خودکار پر شود.</div>
          <div class="pr-chips">
            <button type="button" class="pchip" data-preset="10:30">۱۰ گیگ / ۳۰ روز</button>
            <button type="button" class="pchip" data-preset="30:30">۳۰ گیگ / ۳۰ روز</button>
            <button type="button" class="pchip" data-preset="50:30">۵۰ گیگ / ۳۰ روز</button>
            <button type="button" class="pchip" data-preset="100:60">۱۰۰ گیگ / ۶۰ روز</button>
            <button type="button" class="pchip" data-preset="200:90">۲۰۰ گیگ / ۹۰ روز</button>
            <button type="button" class="pchip" data-preset="0:30">نامحدود / ۳۰ روز</button>
          </div>
        </div>

        <div class="fieldset accent">
          <div class="lg"><span class="n">📊</span> حجم، مدت و محدودیت</div>
          <?php if ($prHasCus): ?>
          <div class="form-grid" style="margin-bottom:10px">
            <div class="field full">
              <label>نوع محصول</label>
              <select name="type" id="prType">
                <option value="fixed" <?= (string)$v('type', 'fixed') === 'custom' ? '' : 'selected' ?>>📦 ثابت — حجم و مدت مشخص</option>
                <option value="custom" <?= (string)$v('type', 'fixed') === 'custom' ? 'selected' : '' ?>>📐 حجم و زمان دلخواه — مشتری خودش حجم و مدت را انتخاب می‌کند</option>
              </select>
              <div class="hint">در حالت «دلخواه»، قیمت = حجم × قیمت هر گیگ + مدت × قیمت هر روز. این محصول در ربات و مینی‌اپ با نشان «📐» در همان سرور/دسته نمایش داده می‌شود و دکمهٔ «📐 حجم و زمان دلخواه» منوی اصلی هم به آن متصل می‌شود.</div>
            </div>
          </div>
          <div id="prCus" class="form-grid" style="display:none;margin-bottom:10px">
            <div class="field">
              <label>قیمت هر گیگ (<?= h(currency()) ?>)</label>
              <input class="mono" type="text" name="price_gb" value="<?= h((string)$v('price_gb', '0')) ?>">
            </div>
            <div class="field">
              <label>قیمت هر روز (<?= h(currency()) ?>)</label>
              <input class="mono" type="text" name="price_day" value="<?= h((string)$v('price_day', '0')) ?>">
            </div>
            <div class="field">
              <label>حداقل حجم (گیگ)</label>
              <input class="mono" type="number" min="1" name="min_gb" value="<?= h((string)$v('min_gb', 1)) ?>">
            </div>
            <div class="field">
              <label>حداکثر حجم (گیگ)</label>
              <input class="mono" type="number" min="1" name="max_gb" value="<?= h((string)$v('max_gb', 100)) ?>">
            </div>
            <div class="field">
              <label>حداقل مدت (روز)</label>
              <input class="mono" type="number" min="1" name="min_days" value="<?= h((string)$v('min_days', 1)) ?>">
            </div>
            <div class="field">
              <label>حداکثر مدت (روز)</label>
              <input class="mono" type="number" min="1" name="max_days" value="<?= h((string)$v('max_days', 90)) ?>">
            </div>
          </div>
          <?php else: ?>
          <div class="alert a-warn">برای فعال‌شدن «نوع محصول: حجم و زمان دلخواه»، یک‌بار از صفحهٔ «بروزرسانی» گزینهٔ «بررسی و تکمیل ساختار دیتابیس» را اجرا کنید.</div>
          <?php endif; ?>
          <div class="form-grid">
            <div class="field pr-fixed">
              <label>حجم (گیگابایت)</label>
              <input class="mono" type="text" name="volume_gb" value="<?= h((string)$v('volume_gb', '30')) ?>">
              <div class="hint">۰ = حجم نامحدود</div>
            </div>
            <div class="field pr-fixed">
              <label>مدت (روز)</label>
              <input class="mono" type="number" name="days" value="<?= h((string)$v('days', 30)) ?>">
              <div class="hint">۰ = بدون محدودیت زمانی</div>
            </div>
            <div class="field">
              <label>محدودیت آیپی همزمان</label>
              <input class="mono" type="number" name="ip_limit" value="<?= h((string)$v('ip_limit', 0)) ?>">
              <div class="hint">۰ = بدون محدودیت</div>
            </div>
            <div class="field">
              <label>اینباند اختصاصی</label>
              <input class="mono" type="number" name="inbound_id" value="<?= h((string)$v('inbound_id', 0)) ?>">
              <div class="hint">۰ = انتخاب خودکار از پنل</div>
            </div>
            <?php if (isset($prCols['group_ids'])): ?>
            <div class="field">
              <label>گروه‌های پاسارگارد این محصول</label>
              <input class="mono ltr" type="text" name="group_ids" value="<?= h((string)$v('group_ids', '')) ?>" placeholder="vip,premium یا 3,5">
              <div class="hint">فقط پنل پاسارگارد؛ خالی = گروه‌های تنظیم‌شده در پنل. نام یا شناسهٔ عددی، با کاما</div>
            </div>
            <?php endif; ?>
            <?php if ($prHasLimits): ?>
              <div class="field">
                <label>محدودیت دستگاه / هاردویر (HWID)</label>
                <input class="mono" type="number" min="0" name="device_limit" value="<?= h((string)$v('device_limit', 0)) ?>">
                <div class="hint">۰ = بدون محدودیت — روی پنل نسل جدید سنایی به‌صورت محدودیت هاردویر (limitHwid) روی همان اکانت و لینک هپ اعمال می‌شود؛ روی VPN-UI هم پشتیبانی می‌شود</div>
              </div>
              <?php if (isset($prCols['reset_days'])): ?>
              <div class="field">
                <label>ریست دوره‌ای حجم (روز)</label>
                <input class="mono" type="number" min="0" max="365" name="reset_days" value="<?= h((string)$v('reset_days', 0)) ?>">
                <div class="hint">۰ = خاموش — مثلاً ۳۰ یعنی هر ۳۰ روز مصرف کاربر خودکار صفر می‌شود (فقط پنل نسل جدید سنایی)</div>
              </div>
              <?php endif; ?>
              <div class="field">
                <label>سرعت دانلود (KB/s)</label>
                <input class="mono" type="number" min="0" name="speed_down" value="<?= h((string)$v('speed_down', 0)) ?>">
                <div class="hint">۰ = بدون محدودیت – مثلاً ۱۲۸۰۰ یعنی حدود ۱۰ مگابیت</div>
              </div>
              <div class="field">
                <label>سرعت آپلود (KB/s)</label>
                <input class="mono" type="number" min="0" name="speed_up" value="<?= h((string)$v('speed_up', 0)) ?>">
                <div class="hint">۰ = بدون محدودیت</div>
              </div>
            <?php else: ?>
              <div class="field full">
                <div class="alert a-warn">⚠️ برای فعال شدن «محدودیت دستگاه» و «محدودیت سرعت»، یک بار از صفحهٔ به‌روزرسانی گزینهٔ «🧩 بررسی و تکمیل ساختار دیتابیس» را اجرا کنید.</div>
              </div>
            <?php endif; ?>
          </div>
        </div>
        </div>

        <!-- تب ۳ — قیمت -->
        <div class="tab-panel" data-tab-panel-group="prd" data-tab-panel="price">
        <div class="fieldset">
          <div class="lg"><span class="n">💰</span> قیمت و موجودی</div>
          <div class="fs-hint">اگر «قیمت قبل از تخفیف» را بزرگ‌تر از قیمت بگذارید، درصد تخفیف خودکار به خریدار نشان داده می‌شود.</div>
          <div class="form-grid">
            <div class="field pr-fixed">
              <label>قیمت (<?= h(currency()) ?>) <span style="color:var(--red)">*</span></label>
              <input class="mono" type="text" name="price" value="<?= h((string)$v('price', '0')) ?>" required>
            </div>
            <div class="field">
              <label>قیمت قبل از تخفیف</label>
              <input class="mono" type="text" name="old_price" value="<?= h((string)$v('old_price', '0')) ?>">
              <div class="hint">خالی/۰ = نمایش ندادن</div>
            </div>
            <div class="field">
              <label>موجودی</label>
              <input class="mono" type="number" name="stock" value="<?= h((string)$v('stock', -1)) ?>">
              <div class="hint">۱− = نامحدود ؛ ۰ = ناموجود</div>
            </div>
          </div>
          <div class="pr-calc">
            <span>درصد تخفیف: <b id="prOff">—</b></span>
            <span>مبلغ تخفیف: <b id="prSave">—</b></span>
            <span id="prNote"></span>
          </div>
        </div>
        </div>

        <!-- تب ۴ — نمایش -->
        <div class="tab-panel" data-tab-panel-group="prd" data-tab-panel="disp">
        <div class="fieldset">
          <div class="lg"><span class="n">📤</span> نحوهٔ تحویل به خریدار</div>
          <div class="fs-hint">بعد از پرداخت، ربات دقیقاً همین مورد را تحویل می‌دهد و دکمه‌های صفحهٔ سرویس هم همین را دنبال می‌کنند.</div>
          <?php $dmCur = (string)($e['deliver_mode'] ?? ''); ?>
          <div class="dm-opts sm">
            <label class="dm-op <?= $dmCur === '' ? 'on' : '' ?>">
              <input type="radio" name="deliver_mode" value="" <?= $dmCur === '' ? 'checked' : '' ?>>
              <span class="i">🎯</span>
              <span class="t">پیش‌فرض فروشگاه</span>
              <span class="d">هرچه بالای صفحه تنظیم کرده‌اید — اکنون: <?= h(Svc::deliverLabel(Svc::deliverDefault())) ?></span>
            </label>
            <?php foreach (Svc::DELIVER as $dk => $dv): ?>
              <label class="dm-op <?= $dmCur === $dk ? 'on' : '' ?>">
                <input type="radio" name="deliver_mode" value="<?= h($dk) ?>" <?= $dmCur === $dk ? 'checked' : '' ?>>
                <span class="i"><?= h($dv[2]) ?></span>
                <span class="t"><?= h($dv[0]) ?></span>
                <span class="d"><?= h($dv[1]) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <?php if (!isset($prCols['deliver_mode'])): ?>
            <div class="alert a-warn mt3">⚠️ ستون <code>deliver_mode</code> هنوز ساخته نشده؛ از صفحهٔ به‌روزرسانی ساختار دیتابیس را تکمیل کنید.</div>
          <?php endif; ?>
        </div>

        <div class="fieldset">
          <div class="lg"><span class="n">🎨</span> نمایش در ربات</div>
          <div class="form-grid">
            <div class="field">
              <label>ترتیب نمایش</label>
              <input class="mono" type="number" name="sort" value="<?= h((string)$v('sort', 0)) ?>">
              <div class="hint">عدد کوچک‌تر بالاتر نمایش داده می‌شود</div>
            </div>
            <div class="field">
              <label>وضعیت</label>
              <label class="check" style="margin-top:8px">
                <input type="checkbox" name="active" value="1" <?= (int)$v('active', 1) ? 'checked' : '' ?>>
                <span>نمایش در ربات</span>
              </label>
              <div class="hint">خاموش = محصول ذخیره می‌شود ولی در ربات دیده نمی‌شود</div>
            </div>
          </div>
        </div>
        </div>

        <!-- تب ۵ — بازبینی -->
        <div class="tab-panel" data-tab-panel-group="prd" data-tab-panel="rev">
          <div class="pr-sum">
            <div class="hd">🧾 خلاصهٔ این محصول</div>
            <div class="rw"><span class="k">نام محصول</span><span class="v" data-sum="name">—</span></div>
            <div class="rw"><span class="k">دسته‌بندی</span><span class="v" data-sum="category">—</span></div>
            <div class="rw"><span class="k">پنل ارائه‌دهنده</span><span class="v" data-sum="panel">—</span></div>
            <div class="rw"><span class="k">حجم</span><span class="v" data-sum="vol">—</span></div>
            <div class="rw"><span class="k">مدت</span><span class="v" data-sum="day">—</span></div>
            <div class="rw"><span class="k">محدودیت آیپی</span><span class="v" data-sum="ip">—</span></div>
            <div class="rw"><span class="k">اینباند</span><span class="v" data-sum="inb">—</span></div>
            <div class="rw"><span class="k">قیمت فروش</span><span class="v" data-sum="price">—</span></div>
            <div class="rw"><span class="k">تخفیف</span><span class="v" data-sum="off">—</span></div>
            <div class="rw"><span class="k">موجودی</span><span class="v" data-sum="stock">—</span></div>
            <div class="rw"><span class="k">وضعیت</span><span class="v" data-sum="active">—</span></div>
          </div>

          <div class="pr-chk" id="prdChk"></div>

          <div class="pr-prev">
            <div class="ttl">👀 پیش‌نمایش تقریبی در ربات</div>
            <div class="bubble">
              <div class="bn" data-pv="name">—</div>
              <div class="bd" data-pv="desc"></div>
              <div class="bchips">
                <span data-pv="vol"></span><span data-pv="day"></span><span data-pv="ip"></span>
              </div>
              <div class="bp">
                <b data-pv="price">—</b><s data-pv="was"></s><em data-pv="off"></em>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="m-foot">
        <?php if ($e): ?>
          <a class="btn btn-ghost" href="index.php?p=products">انصراف</a>
        <?php else: ?>
          <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
        <?php endif; ?>
        <button class="btn btn-primary">💾 <?= $e ? 'ذخیره‌ی تغییرات' : 'افزودن محصول' ?></button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
/* fixed76: نوع محصول — نمایش فیلدهای «حجم و زمان دلخواه» و پنهان‌کردن فیلدهای حجم/مدت/قیمت ثابت */
(function () {
  var t = document.getElementById('prType');
  if (!t) return;
  var cus = document.getElementById('prCus');
  function upd() {
    var c = t.value === 'custom';
    if (cus) cus.style.display = c ? '' : 'none';
    document.querySelectorAll('.pr-fixed').forEach(function (el) {
      el.style.display = c ? 'none' : '';
      /* fixed79: فیلد required پنهان مانع ثبت فرم نشود */
      el.querySelectorAll('input,select').forEach(function (i) { if (i.hasAttribute('required') || i.dataset.req) { i.dataset.req = '1'; i.required = !c; } });
    });
  }
  t.addEventListener('change', upd);
  upd();
})();
</script>
<script>
/* ===== Products Studio v1 ===== */
(function () {
  'use strict';

  var FA = '۰۱۲۳۴۵۶۷۸۹';
  var AR = '٠١٢٣٤٥٦٧٨٩';

  function fa(s) {
    return String(s).replace(/[0-9]/g, function (c) { return FA.charAt(Number(c)); });
  }
  function en(s) {
    return String(s)
      .replace(/[\u06F0-\u06F9]/g, function (c) { return String(FA.indexOf(c)); })
      .replace(/[\u0660-\u0669]/g, function (c) { return String(AR.indexOf(c)); });
  }

  /* ================= سویچ نمای کارت / جدول ================= */
  var seg  = document.getElementById('prSeg');
  var grid = document.getElementById('prodGrid');
  var wrap = document.getElementById('prodTableWrap');

  if (seg && grid && wrap) {
    var setView = function (v) {
      var isGrid = (v !== 'table');
      grid.style.display = isGrid ? '' : 'none';
      wrap.style.display = isGrid ? 'none' : '';
      var bs = seg.querySelectorAll('button[data-pview]');
      for (var i = 0; i < bs.length; i++) {
        var want = isGrid ? 'grid' : 'table';
        bs[i].classList.toggle('on', bs[i].getAttribute('data-pview') === want);
      }
      try { localStorage.setItem('srbotProdView', isGrid ? 'grid' : 'table'); } catch (err) {}
    };

    seg.addEventListener('click', function (ev) {
      if (!ev.target || !ev.target.closest) return;
      var b = ev.target.closest('button[data-pview]');
      if (!b) return;
      ev.preventDefault();
      setView(b.getAttribute('data-pview'));
    });

    var saved = 'grid';
    try { saved = localStorage.getItem('srbotProdView') || 'grid'; } catch (err) {}
    setView(saved);
  }

  /* ============ جست‌وجوی زنده روی کارت‌ها (جدول را app.js مدیریت می‌کند) ============ */
  var srch = document.querySelector('input[data-live-filter="#prodTable tbody tr"]');
  if (srch && grid) {
    srch.addEventListener('input', function () {
      var q = en(String(srch.value || '')).trim().toLowerCase();
      var cards = grid.querySelectorAll('.pr-card');
      for (var i = 0; i < cards.length; i++) {
        var txt = en(cards[i].textContent || '').toLowerCase();
        var hit = (q === '' || txt.indexOf(q) > -1);
        cards[i].style.display = hit ? '' : 'none';
      }
    });
  }

  /* ================= مودال افزودن / ویرایش محصول ================= */
  var box = document.getElementById('mProd');
  if (!box) return;

  var cur  = box.getAttribute('data-cur') || '';
  var TABS = ['base', 'spec', 'price', 'disp', 'rev'];

  function f(n) { return box.querySelector('[name="' + n + '"]'); }

  function val(n) {
    var el = f(n);
    if (!el) return '';
    return String(el.value == null ? '' : el.value).trim();
  }

  function num(n) {
    var raw = en(val(n)).replace(/[^0-9.\-]/g, '');
    var x = parseFloat(raw);
    if (isNaN(x)) return 0;
    return x;
  }

  function chk(n) {
    var el = f(n);
    return !!(el && el.checked);
  }

  function selText(n) {
    var el = f(n);
    if (!el || el.selectedIndex < 0) return '';
    var o = el.options[el.selectedIndex];
    if (!o || !o.value) return '';
    return String(o.text || '').trim();
  }

  function money(v) { return fa(Math.round(v).toLocaleString('en-US')); }

  function setSum(k, txt, dim) {
    var el = box.querySelector('[data-sum="' + k + '"]');
    if (!el) return;
    el.textContent = txt;
    el.classList.toggle('em', !!dim);
  }

  function setPv(k, txt) {
    var el = box.querySelector('[data-pv="' + k + '"]');
    if (el) el.textContent = txt;
  }

  function offPct() {
    var p = num('price');
    var o = num('old_price');
    if (o > p && o > 0) return Math.round((o - p) / o * 100);
    return 0;
  }

  /* ---------- محاسبهٔ زندهٔ تخفیف ---------- */
  function paintCalc() {
    var p = num('price');
    var o = num('old_price');
    var pct = offPct();

    var offEl  = document.getElementById('prOff');
    var saveEl = document.getElementById('prSave');
    var noteEl = document.getElementById('prNote');

    if (offEl)  offEl.textContent  = pct > 0 ? fa(String(pct)) + '٪' : '—';
    if (saveEl) saveEl.textContent = pct > 0 ? money(o - p) + ' ' + cur : '—';

    if (noteEl) {
      if (p <= 0) {
        noteEl.textContent = '⚠️ قیمت صفر است — محصول رایگان می‌شود';
        noteEl.className = 'bad';
      } else if (o > 0 && o <= p) {
        noteEl.textContent = '⚠️ قیمت قبل از تخفیف باید بیشتر از قیمت فروش باشد';
        noteEl.className = 'bad';
      } else {
        noteEl.textContent = '';
        noteEl.className = '';
      }
    }
  }

  /* ---------- خلاصهٔ بازبینی ---------- */
  function paintSum() {
    var name = val('name');
    setSum('name', name || 'وارد نشده', !name);

    var cat = val('category');
    setSum('category', cat || 'عمومی', !cat);

    var pnl = selText('panel_id');
    setSum('panel', pnl || 'انتخاب نشده', !pnl);

    var v = num('volume_gb');
    setSum('vol', v > 0 ? fa(String(v)) + ' گیگابایت' : 'نامحدود', v <= 0);

    var d = num('days');
    setSum('day', d > 0 ? fa(String(d)) + ' روز' : 'بدون انقضا', d <= 0);

    var ip = num('ip_limit');
    setSum('ip', ip > 0 ? fa(String(ip)) + ' کاربر همزمان' : 'بدون محدودیت', ip <= 0);

    var inb = num('inbound_id');
    setSum('inb', inb > 0 ? '#' + fa(String(inb)) : 'انتخاب خودکار از پنل', inb <= 0);

    var p = num('price');
    setSum('price', money(p) + ' ' + cur, p <= 0);

    var pct = offPct();
    if (pct > 0) {
      setSum('off', fa(String(pct)) + '٪ معادل ' + money(num('old_price') - p) + ' ' + cur, false);
    } else {
      setSum('off', 'ندارد', true);
    }

    var st = num('stock');
    var stTxt = 'نامحدود';
    if (st === 0) stTxt = 'ناموجود';
    else if (st > 0) stTxt = fa(String(st)) + ' عدد';
    setSum('stock', stTxt, st === 0);

    var on = chk('active');
    setSum('active', on ? 'فعال — در ربات دیده می‌شود' : 'مخفی — در ربات نمایش داده نمی‌شود', !on);
  }

  /* ---------- پیش‌نمایش ربات ---------- */
  function paintPrev() {
    setPv('name', val('name') || 'نام محصول');
    setPv('desc', val('description'));

    var v = num('volume_gb');
    setPv('vol', v > 0 ? '📊 ' + fa(String(v)) + ' گیگ' : '📊 حجم نامحدود');

    var d = num('days');
    setPv('day', d > 0 ? '⏱ ' + fa(String(d)) + ' روز' : '⏱ بدون انقضا');

    var ip = num('ip_limit');
    setPv('ip', ip > 0 ? '👥 ' + fa(String(ip)) + ' کاربر همزمان' : '');

    setPv('price', money(num('price')) + ' ' + cur);

    var o = num('old_price');
    setPv('was', o > 0 ? money(o) + ' ' + cur : '');

    var pct = offPct();
    setPv('off', pct > 0 ? fa(String(pct)) + '٪ تخفیف' : '');
  }

  /* ---------- چک‌لیست ---------- */
  var CHECKS = [
    { tab: 'base',  t: 'نام محصول وارد شده است',            ok: function () { return val('name') !== ''; } },
    { tab: 'base',  t: 'پنل ارائه‌دهنده انتخاب شده است',   ok: function () { return val('panel_id') !== ''; } },
    { tab: 'price', t: 'قیمت فروش بیشتر از صفر است',      ok: function () { return num('price') > 0; } },
    { tab: 'price', t: 'قیمت قبل از تخفیف منطقی است',      ok: function () { var o = num('old_price'); return o === 0 || o > num('price'); } }
  ];

  function paintChk() {
    var host = document.getElementById('prdChk');
    if (!host) return;
    var html = '';
    for (var i = 0; i < CHECKS.length; i++) {
      var good = CHECKS[i].ok();
      var cls  = good ? 'ok' : 'no';
      var icon = good ? '✓' : '!';
      html += '<div class="pr-ck ' + cls + '"><span class="i">' + icon + '</span><span>' + CHECKS[i].t + '</span></div>';
    }
    host.innerHTML = html;
  }

  /* ---------- نوار گام‌ها و نشانگر بخش ناقص ---------- */
  function tabBtn(key) {
    return box.querySelector('.pr-st[data-st="' + key + '"]');
  }

  function tabHasChecks(key) {
    for (var i = 0; i < CHECKS.length; i++) {
      if (CHECKS[i].tab === key) return true;
    }
    return false;
  }

  function tabIncomplete(key) {
    for (var i = 0; i < CHECKS.length; i++) {
      if (CHECKS[i].tab === key && !CHECKS[i].ok()) return true;
    }
    return false;
  }

  function paintSteps() {
    for (var j = 0; j < TABS.length; j++) {
      var key = TABS[j];
      var st  = tabBtn(key);
      if (!st) continue;

      var bad = tabIncomplete(key);

      /* تیک سبز فقط برای گامی که ورودی اجباری دارد و کامل شده است */
      st.classList.toggle('ok', tabHasChecks(key) && !bad && !st.classList.contains('on'));

      var dot = st.querySelector('.dot');
      if (bad && !dot) {
        dot = document.createElement('span');
        dot.className = 'dot';
        st.appendChild(dot);
      } else if (!bad && dot && dot.parentNode) {
        dot.parentNode.removeChild(dot);
      }
    }
  }

  function paint() {
    paintCalc();
    paintSum();
    paintPrev();
    paintChk();
    paintSteps();
  }

  box.addEventListener('input', paint);
  box.addEventListener('change', paint);

  box.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t || !t.closest) return;

    var chip = t.closest('[data-preset]');
    if (chip) {
      ev.preventDefault();
      var parts = String(chip.getAttribute('data-preset')).split(':');
      var vg = f('volume_gb');
      var dd = f('days');
      if (vg) vg.value = parts[0];
      if (dd) dd.value = parts[1];
      paint();
      return;
    }

    if (t.closest('.pr-st')) {
      setTimeout(paintSteps, 20);
      return;
    }
  });

  paint();
  setTimeout(paint, 60);
})();
</script>
