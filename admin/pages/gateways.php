<?php
/** درگاه‌های پرداخت — ارز دیجیتال، کارت بانکی و پرداخت خودکار */

if (!can('gateways.view')) { echo denyBox('بخش درگاه‌های پرداخت برای شما فعال نیست.'); return; }

if (!class_exists('Gateway')) {
    echo '<div class="card"><div class="alert a-err">ماجول درگاه پیدا نشد. فایل <code>app/Service/Gateway.php</code> را بررسی کنید.</div></div>';
    return;
}

$act = (string)($_POST['act'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {

    if ($act === 'save') { need('gateways.edit', 'gateways');
        $fee = function_exists('pflt') ? (float)pflt('fee') : (float)str_replace(',', '.', ptxt('fee'));

        $r = Gateway::upsert([
            'id'       => ptxt('id'),
            'kind'     => ptxt('kind'),
            'enabled'  => pchk('enabled'),
            'audience' => ptxt('audience'),
            'label'    => ptxt('label'),
            'icon'     => ptxt('icon'),
            'asset'    => ptxt('asset'),
            'network'  => ptxt('network_custom') !== '' ? ptxt('network_custom') : ptxt('network'),
            'address'  => ptxt('address'),
            'memo'     => ptxt('memo'),
            'number'   => ptxt('number'),
            'holder'   => ptxt('holder'),
            'bank'     => ptxt('bank'),
            'sheba'    => ptxt('sheba'),
            'note'     => ptxt('note'),
            'min'      => pint('min'),
            'max'      => pint('max'),
            'fee'      => $fee,
        ]);

        if (ptxt('kind') === 'nowpay') {
            DB::setSetting('nowpay_api_key',    ptxt('np_key'));
            DB::setSetting('nowpay_ipn_secret', ptxt('np_ipn'));
            DB::setSetting('nowpay_min_usd',    (string)max(1, pint('np_min', 5)));
            DB::setSetting('nowpay_currency',   strtolower(ptxt('np_cur')));
            DB::setSetting('nowpay_enabled',    (string)pchk('enabled'));
        }

        if (ptxt('kind') === 'hooshpay') {
            DB::setSetting('hp_api_key',  ptxt('hp_key', 200));
            DB::setSetting('hp_secret',   ptxt('hp_secret', 200));
            DB::setSetting('hp_fee_mode', ptxt('hp_fee_mode', 20));
            DB::setSetting('hp_unit',     ptxt('hp_unit', 20));
            DB::setSetting('hp_min',      (string)max(0, pint('hp_min', 0)));
            DB::setSetting('hp_max',      (string)max(0, pint('hp_max', 0)));
            DB::setSetting('hp_audience', ptxt('hp_audience', 20));
            DB::setSetting('hp_desc',     ptxt('hp_desc', 200));
            DB::setSetting('hp_label',    ptxt('label', 120));
            DB::setSetting('hp_icon',     ptxt('icon', 20));
            DB::setSetting('hp_enabled',  (string)pchk('enabled'));
        }

        flash(!empty($r['ok']) ? 'ok' : 'err', h((string)($r['message'] ?? '')));
        back('gateways');
    }

    if ($act === 'del') { need('gateways.edit', 'gateways');
        Gateway::remove(ptxt('id'));
        flash('ok', '🗑 درگاه حذف شد.');
        back('gateways');
    }

    if ($act === 'toggle') { need('gateways.edit', 'gateways');
        Gateway::toggle(ptxt('id'));
        back('gateways');
    }

    if ($act === 'move') { need('gateways.edit', 'gateways');
        Gateway::move(ptxt('id'), pint('dir'));
        back('gateways');
    }

    if ($act === 'edge') { need('gateways.edit', 'gateways');
        Gateway::moveEdge(ptxt('id'), ptxt('to') === 'top');
        back('gateways');
    }

    if ($act === 'dup') { need('gateways.edit', 'gateways');
        $n = Gateway::duplicate(ptxt('id'));
        flash($n !== null ? 'ok' : 'err', $n !== null
            ? '📄 یک کپی خاموش ساخته شد؛ آدرس یا شبکه‌اش را ویرایش کنید.'
            : 'درگاه پیدا نشد.');
        back('gateways');
    }

    if ($act === 'bulk') { need('gateways.edit', 'gateways');
        $on = ptxt('to') === 'on';
        $n  = Gateway::setAll($on);
        flash('ok', ($on ? '✅ فعال شد: ' : '⏸ خاموش شد: ') . fa_num($n) . ' درگاه');
        back('gateways');
    }

    if ($act === 'import') { need('gateways.edit', 'gateways');
        $n = Gateway::importLegacy();
        flash('ok', '✅ تنظیمات قدیمی منتقل شد: ' . fa_num(count($n)) . ' درگاه');
        back('gateways');
    }
}

$rows = Gateway::all();
$st   = Gateway::stats();
$eid  = (string)($_GET['e'] ?? '');
$ed   = $eid !== '' ? Gateway::byId($eid) : null;
$e    = $ed !== null;
$v    = function (string $k, $d = '') use ($ed) { return $ed !== null ? ($ed[$k] ?? $d) : $d; };

$npOn = class_exists('NowPay') && NowPay::enabled();
$cbUrl = class_exists('NowPay') ? NowPay::callbackUrl() : app_url('/nowpay.php');
$cnt  = [
    'all'    => count($rows),
    'crypto' => (int)($st['crypto'] ?? 0),
    'card'   => (int)($st['card'] ?? 0),
    'nowpay' => (int)($st['nowpay'] ?? 0),
    'off'    => (int)($st['off'] ?? 0),
];

$fmtFee = function ($f) {
    $f = (float)$f;
    $s = rtrim(rtrim(number_format(abs($f), 2, '.', ''), '0'), '.');
    if ($s === '') $s = '0';
    return ($f < 0 ? '−' : '+') . fa_num($s) . '٪';
};
?>

<style>
.gw-hero { position:relative; overflow:hidden; }
.gw-hero::after {
  content:""; position:absolute; inset-block-start:-90px; inset-inline-end:-60px;
  width:260px; height:260px; border-radius:50%; background:var(--grad-soft); pointer-events:none;
}
.gw-hero > * { position:relative; z-index:1; }

.gw-bar { display:flex; gap:var(--s2); flex-wrap:wrap; align-items:center; }
.gw-search {
  flex:1 1 220px; min-width:0; display:flex; align-items:center; gap:var(--s2);
  background:var(--surface-2); border:1px solid var(--border);
  border-radius:var(--r-pill); padding:0 var(--s3); height:42px;
}
.gw-search input { border:0; background:transparent; height:40px; padding:0; box-shadow:none; width:100%; }
.gw-search input:focus { outline:none; box-shadow:none; }
.gw-search span { color:var(--muted); font-size:15px; }

.gw-filt { display:flex; gap:6px; flex-wrap:wrap; }
.gw-filt button {
  display:inline-flex; align-items:center; gap:6px; cursor:pointer;
  background:var(--surface-2); color:var(--muted);
  border:1px solid var(--border); border-radius:var(--r-pill);
  padding:8px 14px; font-family:inherit; font-size:12.5px; font-weight:700;
  transition:all .16s ease;
}
.gw-filt button:hover { color:var(--text); background:var(--surface-3); }
.gw-filt button.on { background:var(--grad); color:#fff; border-color:transparent; box-shadow:var(--glow); }
.gw-filt button i { font-style:normal; font-size:11px; padding:1px 7px; border-radius:var(--r-pill); background:var(--surface-3); }
.gw-filt button.on i { background:rgba(255,255,255,.22); }

.gw-presets { display:flex; gap:6px; flex-wrap:wrap; }
.gw-presets button {
  cursor:pointer; font-family:inherit; font-size:12px; font-weight:600;
  border:1px dashed var(--border); background:transparent; color:var(--muted);
  border-radius:var(--r-pill); padding:7px 12px; transition:all .16s ease;
}
.gw-presets button:hover { color:var(--accent-text); border-color:var(--accent); background:var(--accent-soft); }

.gw-grid { display:grid; gap:var(--s3); grid-template-columns:minmax(0,1fr); }
@media (min-width:760px)  { .gw-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (min-width:1320px) { .gw-grid { grid-template-columns:repeat(3,minmax(0,1fr)); } }

.gw-card {
  --c:#5B8CFF;
  position:relative; overflow:hidden; min-width:0;
  background:
    radial-gradient(120% 120% at 100% 0%, color-mix(in srgb, var(--c) 16%, transparent) 0%, transparent 60%),
    var(--surface);
  border:1px solid var(--border); border-radius:var(--r-lg);
  padding:var(--s4); box-shadow:var(--shadow-sm);
  transition:transform .16s ease, box-shadow .16s ease, border-color .16s ease;
}
.gw-card::before {
  content:""; position:absolute; inset-block-start:0; inset-inline:0; height:3px;
  background:linear-gradient(90deg, var(--c), transparent);
}
@media (hover:hover) { .gw-card:hover { transform:translateY(-2px); box-shadow:var(--shadow); border-color:color-mix(in srgb, var(--c) 55%, var(--border)); } }
.gw-card.off { opacity:.62; }
.gw-card.off::before { background:var(--border); }

.gw-top { display:flex; align-items:center; gap:var(--s3); min-width:0; }
.gw-ava {
  width:44px; height:44px; flex:0 0 44px; border-radius:14px; font-size:21px;
  display:grid; place-items:center; color:#fff;
  background:linear-gradient(135deg, color-mix(in srgb, var(--c) 85%, #000), color-mix(in srgb, var(--c) 45%, #000));
  box-shadow:0 6px 16px color-mix(in srgb, var(--c) 30%, transparent);
}
.gw-tt { min-width:0; flex:1 1 auto; }
.gw-name { font-weight:800; font-size:14.5px; line-height:1.7; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.gw-sub { color:var(--muted); font-size:11.5px; line-height:1.7; }

.gw-pill {
  display:inline-flex; align-items:center; gap:5px; flex:0 0 auto;
  font-size:11px; font-weight:700; padding:4px 10px; border-radius:var(--r-pill);
  border:1px solid var(--border); background:var(--surface-2); color:var(--muted);
}
.gw-pill .d { width:7px; height:7px; border-radius:50%; background:var(--muted); }
.gw-pill.on { color:var(--green); border-color:color-mix(in srgb, var(--green) 45%, transparent); background:var(--green-soft); }
.gw-pill.on .d { background:var(--green); box-shadow:0 0 0 3px var(--green-soft); }

.gw-val {
  margin-top:var(--s3); display:flex; align-items:center; gap:var(--s2); cursor:pointer;
  background:var(--surface-2); border:1px dashed var(--border); border-radius:var(--r);
  padding:9px var(--s3); font-size:12px; transition:all .16s ease;
}
.gw-val:hover { border-color:var(--accent); background:var(--accent-soft); }
.gw-val span { flex:1 1 auto; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.gw-val b { color:var(--muted); font-size:13px; }

.gw-chips { display:flex; gap:6px; flex-wrap:wrap; margin-top:var(--s3); }
.gw-chips .c {
  display:inline-flex; align-items:center; gap:4px;
  background:var(--surface-2); border:1px solid var(--border-soft);
  border-radius:var(--r-pill); padding:4px 10px; font-size:11px; color:var(--muted);
}
.gw-chips .c b { color:var(--text); font-weight:700; }
.gw-chips .c.w { color:var(--orange); border-color:color-mix(in srgb, var(--orange) 40%, transparent); background:var(--orange-soft); }
.gw-chips .c.g { color:var(--green); border-color:color-mix(in srgb, var(--green) 40%, transparent); background:var(--green-soft); }
.gw-chips .c.b { color:var(--accent-text); border-color:color-mix(in srgb, var(--accent) 40%, transparent); background:var(--accent-soft); }

.gw-note {
  margin-top:var(--s3); font-size:11.5px; color:var(--muted); line-height:1.9;
  border-inline-start:3px solid color-mix(in srgb, var(--c) 60%, transparent);
  padding-inline-start:var(--s3);
}

.gw-acts { display:flex; gap:6px; flex-wrap:wrap; margin-top:var(--s4); padding-top:var(--s3); border-top:1px solid var(--border-soft); }
.gw-acts form { display:inline; }
.gw-acts .btn { padding-inline:10px; }

.gw-empty { display:none; text-align:center; padding:var(--s6) var(--s4); color:var(--muted); }
.gw-empty .e { font-size:34px; display:block; margin-bottom:var(--s2); }

.gw-kinds { display:grid; gap:var(--s2); grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); }
.gw-kind {
  position:relative; cursor:pointer; display:block; text-align:center;
  background:var(--surface-2); border:1.5px solid var(--border);
  border-radius:var(--r); padding:var(--s3); transition:all .16s ease;
}
.gw-kind input { position:absolute; opacity:0; pointer-events:none; }
.gw-kind .i { font-size:22px; display:block; }
.gw-kind .t { display:block; font-weight:800; font-size:13px; margin-top:4px; }
.gw-kind .d { display:block; color:var(--muted); font-size:11px; line-height:1.7; margin-top:2px; }
.gw-kind.on { border-color:var(--accent); background:var(--accent-soft); box-shadow:var(--glow); }

.gw-prev {
  --c:#26a17b; display:flex; align-items:center; gap:var(--s3);
  background:
    radial-gradient(120% 120% at 100% 0%, color-mix(in srgb, var(--c) 22%, transparent) 0%, transparent 60%),
    var(--surface-2);
  border:1px solid var(--border); border-radius:var(--r-lg); padding:var(--s3);
}
.gw-prev .v { margin-inline-start:auto; font-size:11.5px; color:var(--muted); max-width:45%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
</style>

<div class="card gw-hero">
  <div class="card-head">
    <div>
      <div class="card-title">🏦 درگاه‌های پرداخت</div>
      <div class="card-sub">هر ارز می‌تواند چند شبکه داشته باشد؛ کارت بانکی و پرداخت خودکار هم از همین‌جا مدیریت می‌شوند.</div>
    </div>
    <?php if (can('gateways.edit')): ?>
      <div class="btn-row">
        <button class="btn btn-primary" data-modal="mGw" data-gw-new="1">➕ افزودن درگاه</button>
      </div>
    <?php endif; ?>
  </div>

  <div class="grid g4 mt3">
    <div class="stat"><div class="ic">🧮</div>
      <div class="lbl">کل درگاه‌ها</div>
      <b class="val"><?= fa_num($cnt['all']) ?></b>
      <div class="sub"><?= fa_num((int)($st['assets'] ?? 0)) ?> ارز مختلف</div></div>

    <div class="stat"><div class="ic">✅</div>
      <div class="lbl">فعال</div>
      <b class="val"><?= fa_num((int)($st['on'] ?? 0)) ?></b>
      <div class="sub"><?= fa_num($cnt['off']) ?> درگاه خاموش</div></div>

    <div class="stat"><div class="ic">🌐</div>
      <div class="lbl">ارزی / کارتی</div>
      <b class="val"><?= fa_num($cnt['crypto']) ?> / <?= fa_num($cnt['card']) ?></b>
      <div class="sub"><?= fa_num((int)($st['reseller'] ?? 0)) ?> درگاه ویژهٔ نمایندگان</div></div>

    <div class="stat"><div class="ic"><?= $npOn ? '⚡️' : '⛔️' ?></div>
      <div class="lbl">پرداخت خودکار</div>
      <b class="val"><?= $npOn ? 'روشن' : 'خاموش' ?></b>
      <div class="sub"><?= fa_num($cnt['nowpay']) ?> درگاه نوپیمنتس</div></div>
  </div>

  <?php if ((int)($st['broken'] ?? 0) > 0): ?>
    <div class="alert a-warn mt3">⚠️ <b><?= fa_num((int)$st['broken']) ?></b> درگاه ناقص است (آدرس/شمارهٔ خالی یا ارز بدون نرخ لحظه‌ای). با فیلتر زیر پیدایشان کنید.</div>
  <?php endif; ?>
</div>

<div class="card mt4">
  <div class="gw-bar">
    <label class="gw-search"><span>🔍</span>
      <input type="search" id="gwQ" placeholder="جستجو در نام، ارز، شبکه، آدرس یا شمارهٔ کارت…" autocomplete="off">
    </label>

    <div class="gw-filt" id="gwFilt">
      <button type="button" class="on" data-f="all">همه <i><?= fa_num($cnt['all']) ?></i></button>
      <button type="button" data-f="crypto">🌐 ارزی <i><?= fa_num($cnt['crypto']) ?></i></button>
      <button type="button" data-f="card">💳 کارتی <i><?= fa_num($cnt['card']) ?></i></button>
      <button type="button" data-f="nowpay">⚡️ خودکار <i><?= fa_num($cnt['nowpay']) ?></i></button>
      <button type="button" data-f="off">⏸ خاموش <i><?= fa_num($cnt['off']) ?></i></button>
    </div>
  </div>

  <?php if (can('gateways.edit')): ?>
    <div class="gw-bar mt3">
      <div class="gw-presets">
        <span class="hint" style="align-self:center">افزودن سریع:</span>
        <button type="button" data-modal="mGw" data-preset='{"kind":"crypto","asset":"USDT","network":"TRC20","icon":"💵","label":"تتر روی ترون"}'>💵 تتر TRC20</button>
        <button type="button" data-modal="mGw" data-preset='{"kind":"crypto","asset":"USDT","network":"BEP20","icon":"💵","label":"تتر روی بی‌ان‌بی"}'>💵 تتر BEP20</button>
        <button type="button" data-modal="mGw" data-preset='{"kind":"crypto","asset":"TON","network":"TON","icon":"💎","label":"تون‌کوین"}'>💎 تون</button>
        <button type="button" data-modal="mGw" data-preset='{"kind":"crypto","asset":"TRX","network":"TRON","icon":"⚡️","label":"ترون"}'>⚡️ ترون</button>
        <button type="button" data-modal="mGw" data-preset='{"kind":"card","icon":"💳","label":"کارت بانکی"}'>💳 کارت بانکی</button>
        <button type="button" data-modal="mGw" data-preset='{"kind":"nowpay","icon":"⚡️","label":"پرداخت خودکار"}'>⚡️ نوپیمنتس</button>
        <button type="button" data-modal="mGw" data-preset='{"kind":"hooshpay","icon":"🪙","label":"هوش‌پی"}'>🪙 هوش‌پی</button>
      </div>

      <div class="btn-row" style="margin-inline-start:auto">
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="act" value="bulk"><input type="hidden" name="to" value="on">
          <button class="btn btn-sm btn-ghost">✅ فعال‌سازی همه</button>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm('همهٔ درگاه‌ها خاموش شوند؟')"><?= csrf_field() ?>
          <input type="hidden" name="act" value="bulk"><input type="hidden" name="to" value="off">
          <button class="btn btn-sm btn-ghost">⏸ خاموش کردن همه</button>
        </form>
        <form method="post" onsubmit="return confirm('تنظیمات قدیمی کارت و والت به این فهرست منتقل شود؟')" style="display:inline">
          <?= csrf_field() ?><input type="hidden" name="act" value="import">
          <button class="btn btn-sm btn-ghost">⤴️ انتقال تنظیمات قدیمی</button>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php if (!$rows): ?>
  <div class="card mt4">
    <div class="gw-empty" style="display:block">
      <span class="e">🏦</span>
      هنوز هیچ درگاهی ثبت نشده است.
      <?php if (can('gateways.edit')): ?>
        <div class="btn-row mt3" style="justify-content:center">
          <button class="btn btn-primary" data-modal="mGw" data-gw-new="1">➕ ساخت اولین درگاه</button>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>

<div class="card mt4">
  <div class="card-head tight"><div>
    <div class="card-title">📋 فهرست درگاه‌ها</div>
    <div class="card-sub">ترتیب نمایش در ربات و مینی‌اپ، همین ترتیب فهرست است.</div>
  </div></div>

  <div class="gw-grid mt3" id="gwGrid">
    <?php foreach ($rows as $i => $g):
        $isCard = $g['kind'] === 'card';
        $isNp   = $g['kind'] === 'nowpay';
        $isHp   = $g['kind'] === 'hooshpay';
        $on     = !empty($g['enabled']);
        $title  = Gateway::title($g);
        $color  = Gateway::color($g);
        if ($isCard)   { $val = (string)$g['number']; }
        elseif ($isNp) { $val = $cbUrl; }
        elseif ($isHp) { $val = class_exists('HooshPay') ? HooshPay::callbackUrl() : 'HooshPay'; }
        else           { $val = (string)$g['address']; }
        $lim    = Gateway::limits($g);
        $fee    = (float)($g['fee'] ?? 0);
        $noRate = $g['kind'] === 'crypto' && !Gateway::hasRate($g);
        $empty  = ($g['kind'] === 'crypto' && (string)$g['address'] === '') || ($isCard && (string)$g['number'] === '');
        $q      = strtolower(trim(implode(' ', [
            $title, (string)$g['label'], (string)$g['asset'], (string)$g['network'],
            (string)$g['address'], (string)$g['number'], (string)$g['bank'], (string)$g['holder'],
            Gateway::kindLabel((string)$g['kind']),
        ])));
    ?>
      <article class="gw-card<?= $on ? '' : ' off' ?>" style="--c:<?= h($color) ?>"
               data-kind="<?= h((string)$g['kind']) ?>" data-on="<?= $on ? '1' : '0' ?>" data-q="<?= h($q) ?>">

        <div class="gw-top">
          <div class="gw-ava"><?= h((string)$g['icon']) ?></div>
          <div class="gw-tt">
            <div class="gw-name"><?= h($title) ?></div>
            <div class="gw-sub">
              <?= h(Gateway::kindLabel((string)$g['kind'])) ?>
              <?php if ($g['kind'] === 'crypto'): ?>
                • <?= h(Gateway::netLabel((string)$g['asset'], (string)$g['network'])) ?>
              <?php elseif ($isCard && (string)$g['bank'] !== ''): ?>
                • <?= h((string)$g['bank']) ?>
              <?php endif; ?>
              • ردیف <?= fa_num($i + 1) ?>
            </div>
          </div>
          <span class="gw-pill <?= $on ? 'on' : '' ?>"><span class="d"></span><?= $on ? 'فعال' : 'خاموش' ?></span>
        </div>

        <?php if ($val !== ''): ?>
          <div class="gw-val mono ltr" data-copy="<?= h($val) ?>" title="برای کپی کلیک کنید">
            <span><?= h(Gateway::shortAddr($val, $isCard ? 20 : 12, $isCard ? 4 : 8)) ?></span><b>⧉</b>
          </div>
        <?php elseif (!$isNp): ?>
          <div class="gw-val" style="border-color:var(--red);color:var(--red);cursor:default">
            <span>⚠️ <?= $isCard ? 'شمارهٔ کارت ثبت نشده' : 'آدرس کیف پول ثبت نشده' ?></span>
          </div>
        <?php endif; ?>

        <div class="gw-chips">
          <?php if (($g['audience'] ?? 'all') !== 'all'): ?>
            <span class="c b"><?= h(Gateway::AUDIENCE[$g['audience']] ?? '') ?></span>
          <?php else: ?>
            <span class="c">👥 همه</span>
          <?php endif; ?>

          <?php if ((int)$lim['min'] > 0): ?>
            <span class="c">⬇️ حداقل <b><?= money((int)$lim['min']) ?></b></span>
          <?php endif; ?>
          <?php if ((int)$lim['max'] > 0): ?>
            <span class="c">⬆️ حداکثر <b><?= money((int)$lim['max']) ?></b></span>
          <?php endif; ?>

          <?php if ($fee != 0.0): ?>
            <span class="c <?= $fee > 0 ? 'w' : 'g' ?>"><?= $fee > 0 ? '💠 کارمزد' : '🎁 تخفیف' ?> <b><?= $fmtFee($fee) ?></b></span>
          <?php endif; ?>

          <?php if (!$isCard && !$isNp && (string)$g['memo'] !== ''): ?>
            <span class="c w">🏷 ممو دارد</span>
          <?php endif; ?>

          <?php if ($isCard && (string)$g['holder'] !== ''): ?>
            <span class="c">👤 <b><?= h((string)$g['holder']) ?></b></span>
          <?php endif; ?>
          <?php if ($isCard && (string)($g['sheba'] ?? '') !== ''): ?>
            <span class="c">🏦 شبا دارد</span>
          <?php endif; ?>

          <?php if ($noRate): ?>
            <span class="c w">📉 نرخ لحظه‌ای ندارد</span>
          <?php endif; ?>
          <?php if ($isNp): ?>
            <span class="c <?= $npOn ? 'g' : 'w' ?>"><?= $npOn ? '✅ کلید API ثبت شده' : '⛔️ کلید API ندارد' ?></span>
          <?php endif; ?>
        </div>

        <?php if ((string)$g['note'] !== ''): ?>
          <div class="gw-note">ℹ️ <?= h((string)$g['note']) ?></div>
        <?php endif; ?>

        <?php if (can('gateways.edit')): ?>
          <div class="gw-acts">
            <a class="btn btn-sm btn-primary" href="index.php?p=gateways&e=<?= h((string)$g['id']) ?>">✏️ ویرایش</a>

            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= h((string)$g['id']) ?>">
              <button class="btn btn-sm btn-ghost"><?= $on ? '⏸ خاموش' : '▶️ فعال' ?></button>
            </form>

            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="act" value="dup"><input type="hidden" name="id" value="<?= h((string)$g['id']) ?>">
              <button class="btn btn-sm btn-ghost" title="ساخت یک کپی برای شبکهٔ دیگر">📄 کپی</button>
            </form>

            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="act" value="move"><input type="hidden" name="id" value="<?= h((string)$g['id']) ?>">
              <input type="hidden" name="dir" value="-1"><button class="btn btn-sm btn-ghost" title="یک پله بالاتر">⬆️</button>
            </form>

            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="act" value="move"><input type="hidden" name="id" value="<?= h((string)$g['id']) ?>">
              <input type="hidden" name="dir" value="1"><button class="btn btn-sm btn-ghost" title="یک پله پایین‌تر">⬇️</button>
            </form>

            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="act" value="edge"><input type="hidden" name="id" value="<?= h((string)$g['id']) ?>">
              <input type="hidden" name="to" value="top"><button class="btn btn-sm btn-ghost" title="انتقال به ابتدای فهرست">⏫</button>
            </form>

            <form method="post" onsubmit="return confirm('این درگاه حذف شود؟')" style="margin-inline-start:auto"><?= csrf_field() ?>
              <input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= h((string)$g['id']) ?>">
              <button class="btn btn-sm btn-danger">🗑</button>
            </form>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>

  <div class="gw-empty" id="gwEmpty"><span class="e">🔍</span>درگاهی با این فیلتر پیدا نشد.</div>
</div>
<?php endif; ?>

<div class="card mt4">
  <div class="card-head tight"><div>
    <div class="card-title">⚡️ پرداخت خودکار NOWPayments</div>
    <div class="card-sub">کاربر مبلغ را می‌زند، لینک پرداخت ساخته می‌شود و کیف پول خودکار شارژ می‌شود.</div>
  </div></div>

  <?php if ($npOn): ?>
    <div class="alert a-ok mt3">✅ کلید API ثبت شده و درگاه خودکار آمادهٔ کار است.</div>
  <?php else: ?>
    <div class="alert a-warn mt3">⛔️ هنوز فعال نیست. یک درگاه از نوع «نوپیمنتس» بسازید و کلید API را وارد کنید.</div>
  <?php endif; ?>

  <div class="field mt3">
    <label>آدرس کال‌بک (IPN) برای پنل NOWPayments</label>
    <div class="copy-line" data-copy="<?= h($cbUrl) ?>"><?= h($cbUrl) ?></div>
    <div class="hint">این آدرس را در بخش IPN حساب NOWPayments ثبت کنید تا شارژها خودکار تایید شوند.</div>
  </div>
</div>

<?php if (can('gateways.edit')): ?>
<div class="modal wide" id="mGw" <?= $e ? 'data-modal-auto="mGw"' : '' ?>>
  <div class="m-wrap">
    <div class="m-box">
      <form method="post" id="gwForm">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="save">
        <input type="hidden" name="id" value="<?= h((string)$v('id')) ?>">

        <div class="m-head">
          <div>
            <div class="card-title"><?= $e ? '✏️ ویرایش درگاه' : '➕ درگاه جدید' ?></div>
            <div class="card-sub">نوع درگاه را انتخاب کنید تا فیلدهای مربوط باز شود</div>
          </div>
          <button type="button" class="x" data-modal-close>✕</button>
        </div>

        <div class="m-body">

          <div class="gw-prev mb3" id="gwPrev">
            <div class="gw-ava" id="gwPvIcon">💵</div>
            <div style="min-width:0">
              <div class="gw-name" id="gwPvName">درگاه تازه</div>
              <div class="gw-sub" id="gwPvSub">ارز دیجیتال</div>
            </div>
            <div class="v mono ltr" id="gwPvVal">—</div>
          </div>

          <div class="fieldset">
            <div class="lg"><span class="n">🧭</span> نوع درگاه</div>
            <div class="gw-kinds">
              <?php
              $kindDesc = [
                  'crypto' => 'واریز دستی روی آدرس ولت',
                  'card'   => 'کارت به کارت با رسید',
                  'nowpay' => 'شارژ خودکار با NOWPayments',
                  'hooshpay' => 'کارت به کارت آنی با هوش‌پی',
              ];
              $curKind = (string)$v('kind', 'crypto');
              foreach (Gateway::KINDS as $kk => $klb):
                  $ic = '🌐';
                  if ($kk === 'card')          $ic = '💳';
                  elseif ($kk === 'nowpay')    $ic = '⚡️';
                  elseif ($kk === 'zarinpal')  $ic = "\u{1F3E6}";
                  elseif ($kk === 'hooshpay')  $ic = '🪙';
              ?>
                <label class="gw-kind<?= $curKind === $kk ? ' on' : '' ?>" data-k="<?= h($kk) ?>">
                  <input type="radio" name="kind" value="<?= h($kk) ?>" <?= $curKind === $kk ? 'checked' : '' ?>>
                  <span class="i"><?= $ic ?></span>
                  <span class="t"><?= h(Gateway::kindLabel($kk)) ?></span>
                  <span class="d"><?= h($kindDesc[$kk] ?? '') ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="fieldset">
            <div class="lg"><span class="n">🏷</span> نمایش و محدودیت‌ها</div>
            <div class="form-grid g2">
              <div class="field"><label>نمایش برای</label>
                <select name="audience">
                  <?php foreach (Gateway::AUDIENCE as $k => $lb): ?>
                    <option value="<?= h($k) ?>" <?= (string)$v('audience', 'all') === $k ? 'selected' : '' ?>><?= h($lb) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="hint">با «فقط نمایندگان» این درگاه از دید کاربران عادی پنهان می‌شود.</div>
              </div>

              <div class="field"><label>وضعیت</label>
                <label class="pick"><input type="checkbox" name="enabled" value="1" <?= !$e || !empty($v('enabled')) ? 'checked' : '' ?>> فعال باشد</label></div>

              <div class="field"><label>برچسب دلخواه (اختیاری)</label>
                <input type="text" name="label" id="gwLabel" value="<?= h((string)$v('label')) ?>" placeholder="مثلاً تتر روی ترون"></div>

              <div class="field"><label>ایموجی</label>
                <input type="text" name="icon" id="gwIcon" value="<?= h((string)$v('icon')) ?>" placeholder="💵"></div>

              <div class="field"><label>حداقل مبلغ (<?= h(currency()) ?>)</label>
                <input type="number" name="min" value="<?= (int)$v('min', 0) ?>" min="0">
                <div class="hint">صفر = همان حداقل کلی تنظیمات.</div></div>

              <div class="field"><label>حداکثر مبلغ (<?= h(currency()) ?>)</label>
                <input type="number" name="max" value="<?= (int)$v('max', 0) ?>" min="0">
                <div class="hint">صفر = بدون سقف اختصاصی.</div></div>

              <div class="field" style="grid-column:1/-1"><label>کارمزد یا تخفیف این درگاه (٪)</label>
                <input type="number" step="0.01" name="fee" id="gwFee" value="<?= h((string)$v('fee', 0)) ?>">
                <div class="hint">عدد مثبت = مقدار ارز درخواستی از کاربر بیشتر می‌شود (کارمزد شبکه) • عدد منفی = تخفیف. فقط روی درگاه‌های ارزی اثر دارد.</div></div>
            </div>
          </div>

          <div class="fieldset accent" id="gwCrypto">
            <div class="lg"><span class="n">🌐</span> مشخصات ارزی</div>
            <div class="fs-hint">برای هر شبکه یک درگاه جداگانه بسازید (تتر TRC20 ، تتر BEP20 و …). با دکمهٔ «کپی» می‌توانید سریع شبکهٔ تازه بسازید.</div>
            <div class="form-grid g2">
              <div class="field"><label>ارز</label>
                <select name="asset" id="gwAsset">
                  <?php foreach (array_keys(Gateway::NETWORKS) as $ak): ?>
                    <option value="<?= h($ak) ?>" <?= (string)$v('asset', 'USDT') === $ak ? 'selected' : '' ?>>
                      <?= h((Gateway::ICONS[$ak] ?? '') . ' ' . Gateway::assetLabel($ak)) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="hint">تتر، تون و ترون نرخ خودکار دارند؛ استیبل‌کوین‌ها با نرخ تتر حساب می‌شوند.</div></div>

              <div class="field"><label>شبکه</label>
                <select name="network" id="gwNet">
                  <?php foreach (Gateway::NETWORKS as $ak => $nets): ?>
                    <?php foreach ($nets as $nk => $nl): ?>
                      <option value="<?= h($nk) ?>" data-asset="<?= h($ak) ?>"
                        <?= (string)$v('network') === $nk && (string)$v('asset', 'USDT') === $ak ? 'selected' : '' ?>><?= h($nl) ?></option>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                </select></div>

              <div class="field" style="grid-column:1/-1"><label>آدرس کیف پول</label>
                <input class="mono ltr" type="text" name="address" id="gwAddr" value="<?= h((string)$v('address')) ?>" placeholder="T… / 0x…" autocomplete="off"></div>

              <div class="field"><label>ممو / تگ (اختیاری)</label>
                <input class="mono ltr" type="text" name="memo" value="<?= h((string)$v('memo')) ?>">
                <div class="hint">برای تون و بعضی صرافی‌ها الزامی است.</div></div>

              <div class="field"><label>شبکهٔ دلخواه (اگر در فهرست نبود)</label>
                <input class="mono ltr" type="text" name="network_custom" placeholder="مثلاً OPTIMISM">
                <div class="hint">اگر پر شود، جای شبکهٔ بالا می‌نشیند.</div></div>
            </div>
          </div>

          <div class="fieldset warn" id="gwCard">
            <div class="lg"><span class="n">💳</span> مشخصات کارت</div>
            <div class="form-grid g2">
              <div class="field"><label>شماره کارت</label>
                <input class="mono ltr" type="text" name="number" id="gwNum" value="<?= h((string)$v('number')) ?>" placeholder="6037-…"></div>
              <div class="field"><label>به نام</label>
                <input type="text" name="holder" value="<?= h((string)$v('holder')) ?>"></div>
              <div class="field"><label>بانک</label>
                <input type="text" name="bank" value="<?= h((string)$v('bank')) ?>"></div>
              <div class="field"><label>شمارهٔ شبا (اختیاری)</label>
                <input class="mono ltr" type="text" name="sheba" value="<?= h((string)$v('sheba')) ?>" placeholder="IR…">
                <div class="hint">اگر پر شود، در پیام پرداخت ربات هم نمایش داده می‌شود.</div></div>
            </div>
          </div>

          <div class="fieldset accent" id="gwNp">
            <div class="lg"><span class="n">⚡️</span> تنظیمات NOWPayments</div>
            <div class="fs-hint">کلید API را از حساب NOWPayments خود بگیرید. با ذخیره، درگاه خودکار فعال می‌شود.</div>
            <div class="form-grid g2">
              <div class="field" style="grid-column:1/-1"><label>کلید API</label>
                <input class="mono ltr" type="text" name="np_key" autocomplete="off"
                  value="<?= h((string)DB::setting('nowpay_api_key', '')) ?>" placeholder="XXXXXXX-XXXXXXX-XXXXXXX"></div>

              <div class="field"><label>کلید IPN (اختیاری)</label>
                <input class="mono ltr" type="text" name="np_ipn" autocomplete="off"
                  value="<?= h((string)DB::setting('nowpay_ipn_secret', '')) ?>">
                <div class="hint">برای اعتبارسنجی پیام‌های خودکار لازم است.</div></div>

              <div class="field"><label>حداقل مبلغ (دلار)</label>
                <input type="number" name="np_min" value="<?= (int)DB::setting('nowpay_min_usd', 5) ?>"></div>

              <div class="field"><label>ارز دریافتی (اختیاری)</label>
                <input class="mono ltr" type="text" name="np_cur"
                  value="<?= h((string)DB::setting('nowpay_currency', '')) ?>" placeholder="usdttrc20">
                <div class="hint">خالی = کاربر خودش ارز را انتخاب می‌کند.</div></div>

              <div class="field" style="grid-column:1/-1">
                <div class="hint">آدرس کال‌بک (IPN): <b class="mono ltr"><?= h($cbUrl) ?></b></div>
              </div>
            </div>
          </div>

          <?php if (class_exists('HooshPay')): ?>
          <div class="fieldset accent" id="gwHp">
            <div class="lg"><span class="n">🪙</span> تنطیمات هوش‌پی (HooshPay)</div>
            <div class="fs-hint">کلید API را از اپلیکیشن هوش‌پی ← بخش «توسعه» بگیرید. با ذخیره، درگاه فعال می‌شود.</div>
            <div class="form-grid g2">
              <div class="field" style="grid-column:1/-1"><label>کلید API <span style="color:var(--red)">*</span></label>
                <input class="mono ltr" type="text" name="hp_key" autocomplete="off"
                  value="<?= h((string)DB::setting('hp_api_key', '')) ?>" placeholder="hp_live_…"></div>

              <div class="field" style="grid-column:1/-1"><label>کلید Secret (برای اعتبارسنجی کال‌بک)</label>
                <input class="mono ltr" type="text" name="hp_secret" autocomplete="off"
                  value="<?= h((string)DB::setting('hp_secret', '')) ?>" placeholder="از همان بخش «توسعه»">
                <div class="hint">خالی هم کار می‌کند؛ چون هر پرداخت مستقیماً از هوش‌پی استعلام می‌شود.</div></div>

              <div class="field"><label>تقسیم کارمزد</label>
                <select name="hp_fee_mode">
                  <?php $hpFm = (string)DB::setting('hp_fee_mode', 'seller'); ?>
                  <?php foreach (HooshPay::FEE_MODES as $fk => $fv): ?>
                    <option value="<?= h((string)$fk) ?>" <?= $hpFm === (string)$fk ? 'selected' : '' ?>><?= h((string)$fv) ?></option>
                  <?php endforeach; ?>
                </select></div>

              <div class="field"><label>واحد مبلغ فروشگاه</label>
                <select name="hp_unit">
                  <?php $hpU = (string)DB::setting('hp_unit', 'toman'); ?>
                  <?php foreach (HooshPay::UNITS as $uk => $uv): ?>
                    <option value="<?= h((string)$uk) ?>" <?= $hpU === (string)$uk ? 'selected' : '' ?>><?= h((string)$uv) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="hint">هوش‌پی همیشه تومان می‌گیرد؛ اگر مبالغ فروشگاه ریال است، این را روی ریال بگذارید.</div></div>

              <div class="field"><label>حداقل مبلغ</label>
                <input type="number" name="hp_min" min="0" value="<?= (int)DB::setting('hp_min', 0) ?>" placeholder="0 = بدون محدودیت"></div>

              <div class="field"><label>حداکثر مبلغ</label>
                <input type="number" name="hp_max" min="0" value="<?= (int)DB::setting('hp_max', 0) ?>" placeholder="0 = بدون محدودیت"></div>

              <div class="field"><label>مخاطب درگاه</label>
                <select name="hp_audience">
                  <?php $hpA = (string)DB::setting('hp_audience', 'all'); ?>
                  <?php foreach (Gateway::AUDIENCE as $ak => $av): ?>
                    <option value="<?= h((string)$ak) ?>" <?= $hpA === (string)$ak ? 'selected' : '' ?>><?= h((string)$av) ?></option>
                  <?php endforeach; ?>
                </select></div>

              <div class="field"><label>توضیح فاکتور</label>
                <input type="text" name="hp_desc" maxlength="120"
                  value="<?= h((string)DB::setting('hp_desc', '')) ?>" placeholder="شارژ کیف پول"></div>

              <div class="field" style="grid-column:1/-1">
                <div class="hint">آدرس کال‌بک: <b class="mono ltr"><?= h(HooshPay::callbackUrl()) ?></b></div>
                <div class="hint">این آدرس خودکار در هر فاکتور ارسال می‌شود؛ نیازی به تنطیم دستی در پنل هوش‌پی نیست.</div>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <div class="fieldset">
            <div class="lg"><span class="n">📝</span> یادداشت برای کاربر</div>
            <div class="field"><textarea name="note" rows="2" placeholder="مثلاً: فقط از صرافی واریز کنید"><?= h((string)$v('note')) ?></textarea></div>
          </div>
        </div>

        <div class="m-foot">
          <button class="btn btn-primary">💾 ذخیره درگاه</button>
          <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  var COLORS = <?= json_encode(Gateway::COLORS, JSON_UNESCAPED_UNICODE) ?>;
  var KIND_T = { crypto: 'ارز دیجیتال', card: 'کارت بانکی', nowpay: 'پرداخت خودکار', hooshpay: 'هوش‌پی' };

  function $(id) { return document.getElementById(id); }
  function $$(s, r) { return [].slice.call((r || document).querySelectorAll(s)); }

  /* ---------- فیلتر و جستجوی فهرست ---------- */
  var grid = $('gwGrid');
  if (grid) {
    var q = $('gwQ'), filt = $('gwFilt'), empt = $('gwEmpty'), mode = 'all';

    function paint() {
      var s = (q && q.value ? q.value : '').trim().toLowerCase(), n = 0;
      $$('.gw-card', grid).forEach(function (c) {
        var okK = mode === 'all' ? true : (mode === 'off' ? c.getAttribute('data-on') === '0' : c.getAttribute('data-kind') === mode);
        var okQ = !s || (c.getAttribute('data-q') || '').indexOf(s) > -1;
        var show = okK && okQ;
        c.style.display = show ? '' : 'none';
        if (show) n++;
      });
      if (empt) empt.style.display = n ? 'none' : 'block';
    }

    if (q) q.addEventListener('input', paint);
    if (filt) {
      $$('button', filt).forEach(function (b) {
        b.addEventListener('click', function () {
          $$('button', filt).forEach(function (x) { x.classList.remove('on'); });
          b.classList.add('on');
          mode = b.getAttribute('data-f') || 'all';
          paint();
        });
      });
    }
    paint();
  }

  /* ---------- فرم درگاه ---------- */
  var form = $('gwForm');
  if (!form) return;

  var asset = $('gwAsset'), net = $('gwNet'),
      bc = $('gwCrypto'), bk = $('gwCard'), bn = $('gwNp'), bh = $('gwHp'),
      pv = $('gwPrev'), pvI = $('gwPvIcon'), pvN = $('gwPvName'), pvS = $('gwPvSub'), pvV = $('gwPvVal');

  function kind() {
    var r = form.querySelector('input[name="kind"]:checked');
    return r ? r.value : 'crypto';
  }

  function paintKind() {
    var k = kind();
    $$('.gw-kind').forEach(function (l) { l.classList.toggle('on', l.getAttribute('data-k') === k); });
    if (bc) bc.style.display = k === 'crypto' ? '' : 'none';
    if (bk) bk.style.display = k === 'card' ? '' : 'none';
    if (bn) bn.style.display = k === 'nowpay' ? '' : 'none';
    if (bh) bh.style.display = k === 'hooshpay' ? '' : 'none';
    paintPrev();
  }

  function paintNets() {
    if (!asset || !net) return;
    var a = asset.value, first = null;
    [].forEach.call(net.options, function (o) {
      var ok = o.getAttribute('data-asset') === a;
      o.hidden = !ok;
      o.disabled = !ok;
      if (ok && first === null) first = o;
    });
    if (net.selectedOptions.length && net.selectedOptions[0].disabled && first) first.selected = true;
    paintPrev();
  }

  function paintPrev() {
    if (!pv) return;
    var k = kind(),
        lb = ($('gwLabel') && $('gwLabel').value.trim()) || '',
        ic = ($('gwIcon') && $('gwIcon').value.trim()) || '',
        ak = asset ? asset.value : 'USDT',
        nt = (net && net.selectedOptions.length) ? net.selectedOptions[0].textContent.trim() : '',
        fee = parseFloat(($('gwFee') && $('gwFee').value) || '0') || 0,
        col = k === 'card' ? '#3b82f6' : (k === 'nowpay' ? '#8b5cf6' : (k === 'hooshpay' ? '#f59e0b' : (COLORS[ak] || '#22c55e'))),
        val = '—';

    if (k === 'crypto') val = ($('gwAddr') && $('gwAddr').value.trim()) || '—';
    if (k === 'card')   val = ($('gwNum') && $('gwNum').value.trim()) || '—';
    if (k === 'nowpay') val = 'NOWPayments';
    if (k === 'hooshpay') val = 'HooshPay';

    pv.style.setProperty('--c', col);
    if (pvI) pvI.textContent = ic || (k === 'card' ? '💳' : (k === 'nowpay' ? '⚡️' : '🌐'));
    if (pvN) pvN.textContent = lb || (k === 'crypto' ? (ak + (nt ? ' • ' + nt : '')) : KIND_T[k]);
    if (pvS) {
      var sub = KIND_T[k];
      if (k === 'crypto' && nt) sub += ' • ' + nt;
      if (fee) sub += ' • ' + (fee > 0 ? 'کارمزد ' : 'تخفیف ') + Math.abs(fee) + '٪';
      pvS.textContent = sub;
    }
    if (pvV) pvV.textContent = val.length > 26 ? (val.slice(0, 14) + '…' + val.slice(-8)) : val;
  }

  $$('input[name="kind"]', form).forEach(function (r) { r.addEventListener('change', paintKind); });
  if (asset) asset.addEventListener('change', paintNets);
  ['gwLabel', 'gwIcon', 'gwAddr', 'gwNum', 'gwFee'].forEach(function (id) {
    var el = $(id);
    if (el) el.addEventListener('input', paintPrev);
  });
  if (net) net.addEventListener('change', paintPrev);

  /* ---------- افزودن سریع با قالب آماده ---------- */
  $$('[data-preset]').forEach(function (b) {
    b.addEventListener('click', function () {
      var p = {};
      try { p = JSON.parse(b.getAttribute('data-preset') || '{}'); } catch (e) { p = {}; }

      var idf = form.querySelector('input[name="id"]');
      if (idf) idf.value = '';

      var kr = form.querySelector('input[name="kind"][value="' + (p.kind || 'crypto') + '"]');
      if (kr) kr.checked = true;
      if (p.asset && asset) asset.value = p.asset;
      paintKind(); paintNets();
      if (p.network && net) {
        [].forEach.call(net.options, function (o) {
          if (o.value === p.network && o.getAttribute('data-asset') === (p.asset || '')) o.selected = true;
        });
      }
      if (p.icon && $('gwIcon')) $('gwIcon').value = p.icon;
      if (p.label && $('gwLabel')) $('gwLabel').value = p.label;
      paintPrev();
    });
  });

  $$('[data-gw-new]').forEach(function (b) {
    b.addEventListener('click', function () {
      var idf = form.querySelector('input[name="id"]');
      if (idf) idf.value = '';
    });
  });

  paintKind();
  paintNets();
  paintPrev();
})();
</script>
