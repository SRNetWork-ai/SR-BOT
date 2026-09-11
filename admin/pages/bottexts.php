<?php
/** متن‌های ربات — ویرایش پیام‌ها، جایگزینی سراسری و پشتیبان */

if (!can('bottexts.view')) { echo denyBox('بخش متن‌های ربات برای شما فعال نیست.'); return; }

if (!class_exists('Txt')) {
    echo '<div class="card"><div class="alert a-warn">ماجول متن‌ها پیدا نشد. فایل <code>app/Service/Txt.php</code> را بررسی کنید.</div></div>';
    return;
}

$act   = (string)($_POST['act'] ?? '');
$canEd = can('bottexts.edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {

    if ($act === 'save') { need('bottexts.edit', 'bottexts');
        $in = $_POST['t'] ?? [];
        $n  = 0;
        if (is_array($in)) {
            foreach ($in as $k => $val) {
                $k = (string)$k;
                if (Txt::meta($k) === null) continue;
                $val = trim(str_replace("\r\n", "\n", (string)$val));
                if ($val === '') { Txt::reset($k); continue; }
                Txt::set($k, $val);
                $n++;
            }
        }
        flash('ok', '💾 متن‌ها ذخیره شد (' . fa_num($n) . ' متن سفارشی).');
        back('bottexts', ['tab' => ptxt('tab')]);
    }

    if ($act === 'reset') { need('bottexts.edit', 'bottexts');
        Txt::reset(ptxt('key'));
        flash('ok', '↩️ متن به حالت پیش‌فرض برگشت.');
        back('bottexts', ['tab' => ptxt('tab')]);
    }

    if ($act === 'resetall') { need('bottexts.edit', 'bottexts');
        Txt::resetAll();
        flash('ok', '↩️ همهٔ متن‌ها به حالت پیش‌فرض برگشتند.');
        back('bottexts');
    }

    if ($act === 'rule_add') { need('bottexts.edit', 'bottexts');
        $r = Txt::addRule(ptxt('find'), ptxt('to'), pchk('re') === 1);
        flash(!empty($r['ok']) ? 'ok' : 'err', h((string)($r['message'] ?? '')));
        back('bottexts', ['tab' => 'rules']);
    }

    if ($act === 'rule_del') { need('bottexts.edit', 'bottexts');
        Txt::delRule(ptxt('id'));
        flash('ok', '🗑 قاعده حذف شد.');
        back('bottexts', ['tab' => 'rules']);
    }

    if ($act === 'rule_toggle') { need('bottexts.edit', 'bottexts');
        Txt::toggleRule(ptxt('id'));
        back('bottexts', ['tab' => 'rules']);
    }

    if ($act === 'import') { need('bottexts.edit', 'bottexts');
        $json = '';
        if (!empty($_FILES['jfile']['tmp_name']) && is_uploaded_file($_FILES['jfile']['tmp_name'])) {
            if ((int)($_FILES['jfile']['size'] ?? 0) > 2 * 1024 * 1024) {
                flash('err', '⛔️ حجم فایل بیش از ۲ مگابایت است.');
                back('bottexts', ['tab' => 'backup']);
            }
            $json = (string)@file_get_contents($_FILES['jfile']['tmp_name']);
        } else {
            $json = (string)($_POST['json'] ?? '');
        }
        if (trim($json) === '') {
            flash('err', '⛔️ نه فایلی انتخاب شد و نه متن JSON وارد شد.');
            back('bottexts', ['tab' => 'backup']);
        }
        if (!method_exists('Txt', 'importJson')) {
            flash('err', '⛔️ این نسخه از ماجول متن‌ها بازگردانی را پشتیبانی نمی‌کند.');
            back('bottexts', ['tab' => 'backup']);
        }
        $r = Txt::importJson($json, pchk('withrules') === 1);
        flash(!empty($r['ok']) ? 'ok' : 'err', (string)($r['message'] ?? '-'));
        back('bottexts', ['tab' => 'backup']);
    }

    if ($act === 'export') { need('bottexts.view', 'bottexts');
        $out = method_exists('Txt', 'exportJson')
            ? Txt::exportJson()
            : (string)json_encode(['texts' => Txt::overrides(), 'rules' => Txt::rules()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="bot-texts-' . date('Ymd-His') . '.json"');
        header('Content-Length: ' . strlen($out));
        echo $out;
        exit;
    }
}

$ov      = Txt::overrides();
$rules   = Txt::rules();
$groups  = array_keys(Txt::CATALOG);
$gs      = method_exists('Txt', 'groupStats') ? Txt::groupStats() : [];
$totKeys = method_exists('Txt', 'totalKeys') ? Txt::totalKeys() : 0;
if ($totKeys === 0) { foreach (Txt::CATALOG as $itms) { $totKeys += count($itms); } }
$custom  = Txt::customCount();
$pct     = $totKeys > 0 ? (int)round(($custom / $totKeys) * 100) : 0;

$rOn = 0;
foreach ($rules as $r) { if (!empty($r['enabled'])) $rOn++; }

/* فهرست کلیدهای سفارشی‌شده برای تب «سفارشی‌ها» */
$customRows = [];
foreach (Txt::CATALOG as $gName => $items) {
    foreach ($items as $k => $meta) {
        if (isset($ov[$k]) && trim((string)$ov[$k]) !== '') {
            $customRows[] = ['group' => (string)$gName, 'key' => (string)$k, 'meta' => $meta];
        }
    }
}

$tab   = (string)($_GET['tab'] ?? '');
$extra = ['rules', 'custom', 'backup'];
if (!in_array($tab, $groups, true) && !in_array($tab, $extra, true)) {
    $tab = (string)($groups[0] ?? 'rules');
}

/** یک کارت ویرایش متن */
$txtCard = static function (string $k, array $meta, string $gName, array $ov, bool $canEd): void {
    $cur  = (string)($ov[$k] ?? '');
    $def  = (string)($meta[1] ?? '');
    $lab  = (string)($meta[0] ?? $k);
    $isC  = trim($cur) !== '';
    $vars = method_exists('Txt', 'varsOf') ? Txt::varsOf($k) : [];
    $len  = mb_strlen($isC ? $cur : $def);
    ?>
    <div class="tx-item<?= $isC ? ' c' : '' ?>" data-q="<?= h(mb_strtolower($lab . ' ' . $k . ' ' . $cur . ' ' . $def)) ?>" data-c="<?= $isC ? '1' : '0' ?>">
      <div class="tx-ih">
        <div class="tx-lab"><?= h($lab) ?></div>
        <span class="tx-key" data-copy="<?= h($k) ?>" title="کپی کلید"><?= h($k) ?></span>
        <span class="badge <?= $isC ? 'b-blue' : 'b-gray' ?>"><?= $isC ? 'سفارشی' : 'پیش‌فرض' ?></span>
        <span class="sp"></span>
        <span class="tx-cnt" data-cnt-for="<?= h($k) ?>"><?= fa_num((string)$len) ?>/۳۰۰۰</span>
      </div>

      <textarea name="t[<?= h($k) ?>]" id="ta_<?= h($k) ?>" rows="3" dir="auto"
                data-def="<?= h($def) ?>" data-key="<?= h($k) ?>"
                placeholder="<?= h($def !== '' ? $def : 'متن دلخواه…') ?>"
                <?= $canEd ? '' : 'disabled' ?>><?= h($cur) ?></textarea>

      <?php if ($vars !== []): ?>
        <div class="tx-vars">
          <span class="l">متغیرها:</span>
          <?php foreach ($vars as $v): ?>
            <button type="button" class="tx-var" data-ins="<?= h('{' . $v . '}') ?>" data-for="<?= h($k) ?>">{<?= h($v) ?>}</button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="tx-tools">
        <button type="button" class="tx-tbtn" data-prev="<?= h($k) ?>">👁 پیش‌نمایش</button>
        <?php if ($canEd && $def !== ''): ?>
          <button type="button" class="tx-tbtn" data-fill="<?= h($k) ?>">📋 قرار دادن متن پیش‌فرض</button>
        <?php endif; ?>
        <?php if ($canEd && $isC): ?>
          <button type="button" class="tx-tbtn r" data-reset="<?= h($k) ?>">↩️ بازگرداندن به پیش‌فرض</button>
        <?php endif; ?>
        <span class="sp"></span>
        <span class="tx-grp"><?= h($gName) ?></span>
      </div>

      <div class="tx-prev" id="pv_<?= h($k) ?>" hidden>
        <div class="tx-bub"></div>
      </div>

      <?php if ($def !== ''): ?>
        <details class="tx-def">
          <summary>متن پیش‌فرض کارخانه</summary>
          <div class="d"><?= nl2br(h($def)) ?></div>
        </details>
      <?php endif; ?>
    </div>
    <?php
};
?>

<style>
/* ===== bot texts page ===== */
.tx-hero{position:relative;overflow:hidden;border-radius:18px;padding:18px 20px;margin:0 0 14px;
  background:linear-gradient(135deg,rgba(139,92,246,.16),rgba(38,211,232,.09)),var(--surface);
  border:1px solid var(--border);box-shadow:var(--shadow)}
.tx-hero::after{content:"";position:absolute;inset-inline-end:-80px;top:-90px;width:240px;height:240px;border-radius:50%;
  background:radial-gradient(circle,rgba(139,92,246,.20),transparent 70%);pointer-events:none}
.tx-top{display:flex;gap:14px;align-items:flex-start;position:relative;z-index:1}
.tx-ic{width:52px;height:52px;border-radius:15px;flex:0 0 auto;display:grid;place-items:center;font-size:25px;
  background:var(--grad-soft);border:1px solid var(--border)}
.tx-hh{min-width:0;flex:1}
.tx-hh h3{margin:0 0 5px;font-size:16.5px;display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.tx-hh .s{color:var(--muted);font-size:12.5px;line-height:1.95}
.tx-bar{height:7px;border-radius:99px;background:var(--surface-3);overflow:hidden;margin-top:10px;max-width:420px}
.tx-bar i{display:block;height:100%;border-radius:99px;background:var(--grad)}
.tx-cells{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:15px;position:relative;z-index:1}
.tx-cell{background:var(--surface-2);border:1px solid var(--border);border-radius:14px;padding:11px 13px;text-align:center}
.tx-cell .v{font-family:var(--font-num);font-size:19px;font-weight:800}
.tx-cell .l{color:var(--muted);font-size:11px;margin-top:3px}
.tx-cell.b .v{color:var(--accent-text)}
.tx-cell.c .v{color:var(--cyan)}
.tx-htools{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;position:relative;z-index:1}
.tx-htools form{margin:0}

.tx-tabs{display:flex;flex-wrap:wrap;gap:7px;margin:0 0 14px}
.tx-tabs a{display:inline-flex;align-items:center;gap:7px;background:var(--surface-2);border:1px solid var(--border);
  color:var(--text-dim);border-radius:999px;padding:8px 14px;font-size:12.2px;text-decoration:none;transition:.15s}
.tx-tabs a:hover{border-color:var(--accent);color:var(--text)}
.tx-tabs a.on{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:700}
.tx-tabs a .n{background:rgba(255,255,255,.13);border-radius:8px;padding:1px 7px;font-family:var(--font-num);font-size:10.5px}
.tx-tabs a.on .n{background:rgba(0,0,0,.22)}
.tx-tabs a .n.b{background:rgba(91,140,255,.20);color:var(--accent-text)}

.tx-srch{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 13px}
.tx-srch input[type=search]{flex:1 1 220px;min-width:170px}
.tx-chip{background:var(--surface-2);border:1px solid var(--border);color:var(--text-dim);border-radius:999px;
  padding:8px 13px;font:inherit;font-size:12px;cursor:pointer;transition:.15s}
.tx-chip:hover{border-color:var(--accent);color:var(--text)}
.tx-chip.on{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:700}

.tx-list{display:grid;gap:11px}
.tx-item{background:var(--surface-2);border:1px solid var(--border);border-radius:15px;padding:13px 14px;
  border-inline-start:3px solid var(--border)}
.tx-item.c{border-inline-start-color:var(--accent)}
.tx-ih{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 9px}
.tx-ih .sp{flex:1}
.tx-lab{font-size:13px;font-weight:700}
.tx-key{background:var(--surface-3);border:1px solid var(--border);border-radius:8px;padding:2px 8px;
  font-family:var(--font-num);font-size:10.5px;color:var(--muted);cursor:pointer;direction:ltr}
.tx-key:hover{color:var(--accent-text);border-color:var(--accent)}
.tx-cnt{font-family:var(--font-num);font-size:10.5px;color:var(--muted)}
.tx-item textarea{width:100%;min-height:78px;resize:vertical;line-height:2}
.tx-vars{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:9px}
.tx-vars .l{font-size:11px;color:var(--muted)}
.tx-var{background:rgba(91,140,255,.13);border:1px solid rgba(91,140,255,.30);color:var(--accent-text);
  border-radius:8px;padding:3px 8px;font:inherit;font-size:11px;font-family:var(--font-num);cursor:pointer;direction:ltr}
.tx-var:hover{background:rgba(91,140,255,.22)}
.tx-tools{display:flex;flex-wrap:wrap;gap:7px;align-items:center;margin-top:10px}
.tx-tools .sp{flex:1}
.tx-tbtn{background:var(--surface-3);border:1px solid var(--border);color:var(--text-dim);border-radius:9px;
  padding:5px 10px;font:inherit;font-size:11.5px;cursor:pointer;transition:.15s}
.tx-tbtn:hover{border-color:var(--accent);color:var(--text)}
.tx-tbtn.r:hover{border-color:var(--red);color:var(--red)}
.tx-grp{font-size:10.5px;color:var(--muted)}
.tx-prev{margin-top:11px;background:var(--bg);border:1px dashed var(--border);border-radius:13px;padding:12px}
.tx-bub{background:#1f6feb1a;border:1px solid rgba(91,140,255,.30);border-radius:14px 14px 14px 4px;
  padding:10px 12px;font-size:12.5px;line-height:2.1;white-space:pre-wrap;word-break:break-word}
.tx-bub code{background:var(--surface-3);border-radius:6px;padding:1px 5px;font-family:var(--font-num)}
.tx-def{margin-top:10px}
.tx-def summary{cursor:pointer;font-size:11.5px;color:var(--muted)}
.tx-def .d{margin-top:7px;background:var(--surface-3);border-radius:11px;padding:10px 12px;
  font-size:11.5px;color:var(--text-dim);line-height:2}
.tx-empty{text-align:center;color:var(--muted);font-size:12.5px;padding:26px 10px;line-height:2}

.rl-row{display:grid;grid-template-columns:auto minmax(0,1fr) auto auto;gap:11px;align-items:center;
  background:var(--surface-2);border:1px solid var(--border);border-radius:13px;padding:10px 12px}
.rl-row.off{opacity:.55}
.rl-n{width:26px;height:26px;border-radius:9px;background:var(--surface-3);display:grid;place-items:center;
  font-family:var(--font-num);font-size:11px;color:var(--muted)}
.rl-txt{font-size:12px;line-height:1.95;word-break:break-word}
.rl-txt .f{color:var(--orange);font-family:var(--font-num)}
.rl-txt .t{color:var(--green);font-family:var(--font-num)}
.rl-row form{margin:0;display:inline}
@media (max-width:760px){
  .tx-cells{grid-template-columns:repeat(2,1fr)}
  .rl-row{grid-template-columns:auto minmax(0,1fr);row-gap:8px}
}
</style>

<div class="tx-hero">
  <div class="tx-top">
    <div class="tx-ic">💬</div>
    <div class="tx-hh">
      <h3>متن‌های ربات
        <span class="badge <?= $custom > 0 ? 'b-blue' : 'b-gray' ?>"><?= fa_num((string)$custom) ?> متن سفارشی</span>
        <?php if ($rOn > 0): ?><span class="badge b-yellow"><?= fa_num((string)$rOn) ?> قاعدهٔ فعال</span><?php endif; ?>
      </h3>
      <div class="s">
        همهٔ پیام‌های ربات را اینجا با لحن خودتان بنویسید. هر متنی را خالی بگذارید، پیش‌فرض کارخانه استفاده می‌شود.<br>
        میزان شخصی‌سازی: <b><?= fa_num((string)$pct) ?>٪</b> از <?= fa_num((string)$totKeys) ?> پیام
      </div>
      <div class="tx-bar"><i style="width:<?= (int)$pct ?>%"></i></div>
    </div>
  </div>

  <div class="tx-cells">
    <div class="tx-cell"><div class="v"><?= fa_num((string)$totKeys) ?></div><div class="l">کل پیام‌ها</div></div>
    <div class="tx-cell b"><div class="v"><?= fa_num((string)$custom) ?></div><div class="l">سفارشی‌شده</div></div>
    <div class="tx-cell c"><div class="v"><?= fa_num((string)count($rules)) ?></div><div class="l">قاعدهٔ جایگزینی</div></div>
    <div class="tx-cell"><div class="v"><?= fa_num((string)count($groups)) ?></div><div class="l">دسته‌بندی</div></div>
  </div>

  <div class="tx-htools">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="export">
      <button class="btn btn-sm btn-ghost">⬇️ دانلود پشتیبان JSON</button>
    </form>
    <a class="btn btn-sm btn-ghost" href="index.php?p=bottexts&amp;tab=backup">💾 پشتیبان و بازگردانی</a>
    <a class="btn btn-sm btn-ghost" href="index.php?p=bottexts&amp;tab=custom">⭐ فقط سفارشی‌ها (<?= fa_num((string)$custom) ?>)</a>
    <?php if ($canEd && $custom > 0): ?>
      <form method="post" data-confirm="همهٔ متن‌های سفارشی پاک و به پیش‌فرض برگردانده شود؟">
        <?= csrf_field() ?><input type="hidden" name="act" value="resetall">
        <button class="btn btn-sm btn-danger">↩️ بازگرداندن همه به پیش‌فرض</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="alert a-info">
  در متن‌ها می‌توانید از تگ‌های تلگرام استفاده کنید:
  <code>&lt;b&gt;</code> پررنگ، <code>&lt;i&gt;</code> مورب، <code>&lt;u&gt;</code> خط‌دار،
  <code>&lt;code&gt;</code> تک‌خطی قابل کپی، <code>&lt;a href="…"&gt;</code> لینک.
  متغیرهایی مانند <code>{name}</code> دقیقاً همین‌طور باید نوشته شوند تا مقدارشان جایگزین شود.
</div>

<div class="tx-tabs">
  <?php foreach ($groups as $g):
      $st = $gs[$g] ?? ['total' => count(Txt::CATALOG[$g] ?? []), 'custom' => 0];
  ?>
    <a class="<?= $tab === $g ? 'on' : '' ?>" href="index.php?p=bottexts&amp;tab=<?= urlencode((string)$g) ?>">
      <?= h((string)$g) ?>
      <span class="n<?= (int)$st['custom'] > 0 && $tab !== $g ? ' b' : '' ?>"><?= fa_num((string)(int)$st['custom']) ?>/<?= fa_num((string)(int)$st['total']) ?></span>
    </a>
  <?php endforeach; ?>
  <a class="<?= $tab === 'custom' ? 'on' : '' ?>" href="index.php?p=bottexts&amp;tab=custom">⭐ سفارشی‌ها <span class="n"><?= fa_num((string)$custom) ?></span></a>
  <a class="<?= $tab === 'rules' ? 'on' : '' ?>" href="index.php?p=bottexts&amp;tab=rules">🔁 جایگزینی سراسری <span class="n"><?= fa_num((string)count($rules)) ?></span></a>
  <a class="<?= $tab === 'backup' ? 'on' : '' ?>" href="index.php?p=bottexts&amp;tab=backup">💾 پشتیبان</a>
</div>

<?php if ($tab === 'rules'): ?>

  <div class="card compact">
    <div class="card-head tight">
      <div>
        <div class="card-title">🔁 جایگزینی سراسری</div>
        <div class="card-sub">این قاعده‌ها روی <b>همهٔ پیام‌های ارسالی ربات</b> اعمال می‌شوند — مناسب برای تغییر نام برند یا پاک کردن یک عبارت.</div>
      </div>
      <span class="badge b-gray"><?= fa_num((string)count($rules)) ?> / ۸۰</span>
    </div>

    <?php if ($rules === []): ?>
      <div class="tx-empty">هنوز قاعده‌ای نساخته‌اید.<br>مثال: هر جا کلمهٔ «VPN» بود، به «سرویس» تغییر کند.</div>
    <?php else: ?>
      <div class="tx-list">
        <?php $rn = 0; foreach ($rules as $r): $rn++; ?>
          <div class="rl-row<?= empty($r['enabled']) ? ' off' : '' ?>">
            <div class="rl-n"><?= fa_num((string)$rn) ?></div>
            <div class="rl-txt">
              <span class="f"><?= h((string)$r['find']) ?></span>
              &nbsp;→&nbsp;
              <span class="t"><?= (string)$r['to'] === '' ? '<i style="color:var(--muted)">(حذف می‌شود)</i>' : h((string)$r['to']) ?></span>
              <?php if (!empty($r['re'])): ?><span class="badge b-yellow" style="margin-inline-start:7px">regex</span><?php endif; ?>
            </div>
            <span class="badge <?= !empty($r['enabled']) ? 'b-green' : 'b-gray' ?>"><?= !empty($r['enabled']) ? 'فعال' : 'خاموش' ?></span>
            <?php if ($canEd): ?>
              <div style="display:flex;gap:6px">
                <form method="post">
                  <?= csrf_field() ?><input type="hidden" name="act" value="rule_toggle">
                  <input type="hidden" name="id" value="<?= h((string)$r['id']) ?>">
                  <button class="btn btn-sm btn-ghost"><?= !empty($r['enabled']) ? 'خاموش' : 'روشن' ?></button>
                </form>
                <form method="post" data-confirm="این قاعده حذف شود؟">
                  <?= csrf_field() ?><input type="hidden" name="act" value="rule_del">
                  <input type="hidden" name="id" value="<?= h((string)$r['id']) ?>">
                  <button class="btn btn-sm btn-danger">🗑</button>
                </form>
              </div>
            <?php else: ?>
              <span></span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($canEd): ?>
    <div class="card compact mt4">
      <div class="card-head tight">
        <div>
          <div class="card-title">➕ قاعدهٔ جدید</div>
          <div class="card-sub">اگر گزینهٔ regex را روشن کنید، عبارت جستجو به عنوان الگوی منطقی خوانده می‌شود.</div>
        </div>
      </div>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="act" value="rule_add">
        <div class="form-grid g2">
          <div class="field">
            <label>به جای این عبارت…</label>
            <input name="find" id="rlFind" maxlength="400" placeholder="مثلاً VPN" required>
          </div>
          <div class="field">
            <label>این قرار بگیرد</label>
            <input name="to" id="rlTo" maxlength="400" placeholder="مثلاً سرویس (خالی = حذف شود)">
          </div>
        </div>
        <label class="check"><input type="checkbox" name="re" value="1" id="rlRe"><span>عبارت جستجو یک الگوی regex است</span></label>
        <div class="field mt3">
          <label>آزمایش زنده (ذخیره نمی‌شود)</label>
          <textarea id="rlSample" rows="2" dir="auto" placeholder="یک متن نمونه بنویسید تا نتیجهٔ قاعده‌ها را ببینید…"></textarea>
          <div class="tx-prev" style="margin-top:9px"><div class="tx-bub" id="rlOut">—</div></div>
        </div>
        <div class="sticky-acts"><button class="btn btn-primary">➕ افزودن قاعده</button></div>
      </form>
    </div>
  <?php endif; ?>

<?php elseif ($tab === 'backup'): ?>

  <div class="card compact">
    <div class="card-head tight">
      <div>
        <div class="card-title">💾 پشتیبان و بازگردانی متن‌ها</div>
        <div class="card-sub">یک فایل JSON شامل متن‌های سفارشی و قاعده‌های جایگزینی — مناسب برای انتقال بین دو ربات.</div>
      </div>
      <span class="badge b-blue"><?= fa_num((string)$custom) ?> متن و <?= fa_num((string)count($rules)) ?> قاعده</span>
    </div>

    <div class="form-grid g2">
      <div class="fieldset accent">
        <div class="lg">⬇️ خروجی گرفتن</div>
        <div class="fs-hint">فایل JSON فقط شامل متن‌های دستکاری‌شده است؛ متن‌های پیش‌فرض در خروجی نمی‌آیند.</div>
        <form method="post" class="mt3">
          <?= csrf_field() ?><input type="hidden" name="act" value="export">
          <button class="btn btn-primary btn-sm">دانلود فایل JSON</button>
        </form>
      </div>

      <div class="fieldset warn">
        <div class="lg">⬆️ بازگردانی</div>
        <div class="fs-hint">توجه: متن‌های سفارشی فعلی با محتوای فایل <b>جایگزین</b> می‌شوند. کلیدهای ناشناس نادیده گرفته می‌شوند.</div>
        <?php if ($canEd): ?>
          <form method="post" enctype="multipart/form-data" class="mt3" data-confirm="متن‌های فعلی با فایل جایگزین شوند؟">
            <?= csrf_field() ?><input type="hidden" name="act" value="import">
            <div class="field">
              <label>فایل JSON</label>
              <input type="file" name="jfile" accept="application/json,.json">
            </div>
            <div class="field">
              <label>یا محتوای JSON را بچسبانید</label>
              <textarea name="json" rows="4" class="mono ltr" placeholder='{"texts":{"start_welcome":"…"}}'></textarea>
            </div>
            <label class="check"><input type="checkbox" name="withrules" value="1" checked><span>قاعده‌های جایگزینی هم بازگردانده شوند</span></label>
            <div class="sticky-acts"><button class="btn btn-primary">⬆️ بازگردانی</button></div>
          </form>
        <?php else: ?>
          <div class="hint mt3">برای بازگردانی به دسترسی ویرایش نیاز دارید.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php else: ?>

  <?php
  $rows = [];
  if ($tab === 'custom') {
      foreach ($customRows as $cr) { $rows[] = $cr; }
  } else {
      foreach ((array)(Txt::CATALOG[$tab] ?? []) as $k => $meta) {
          $rows[] = ['group' => $tab, 'key' => (string)$k, 'meta' => $meta];
      }
  }
  ?>

  <div class="tx-srch">
    <input type="search" id="txQ" placeholder="🔎 جستجو در عنوان، کلید یا محتوای متن…">
    <button type="button" class="tx-chip on" data-tf="all">همه</button>
    <button type="button" class="tx-chip" data-tf="1">فقط سفارشی</button>
    <button type="button" class="tx-chip" data-tf="0">فقط پیش‌فرض</button>
    <span class="tx-cnt" id="txCnt"></span>
  </div>

  <form method="post" id="txForm">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="save">
    <input type="hidden" name="key" value="">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">

    <div class="tx-list" id="txList">
      <?php if ($rows === []): ?>
        <div class="tx-empty">
          <?= $tab === 'custom' ? 'هنوز هیچ متنی را شخصی‌سازی نکرده‌اید.<br>از دسته‌های بالا یک پیام را ویرایش کنید.' : 'متنی در این دسته نیست.' ?>
        </div>
      <?php endif; ?>

      <?php foreach ($rows as $row): ?>
        <?php $txtCard((string)$row['key'], (array)$row['meta'], (string)$row['group'], $ov, $canEd); ?>
      <?php endforeach; ?>
    </div>

    <?php if ($canEd && $rows !== []): ?>
      <div class="sticky-acts">
        <button class="btn btn-primary">💾 ذخیرهٔ متن‌های این دسته</button>
        <span class="hint">متن خالی = بازگشت به پیش‌فرض کارخانه</span>
      </div>
    <?php endif; ?>
  </form>

<?php endif; ?>

<script>
window.TXRULES = <?= jenc(array_values(array_map(static fn($r) => [
    'find' => (string)$r['find'], 'to' => (string)$r['to'],
    're' => !empty($r['re']) ? 1 : 0, 'on' => !empty($r['enabled']) ? 1 : 0,
], $rules))) ?>;
(function () {
  /* ---------- پیش‌نمایش سادهٔ HTML تلگرام ---------- */
  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function tgHtml(s) {
    var out = esc(s);
    out = out.replace(/&lt;(\/?)(b|strong|i|em|u|s|code|pre)&gt;/g, '<$1$2>');
    out = out.replace(/&lt;a href=(?:"|&quot;)([^"&]+)(?:"|&quot;)&gt;/g, '<a href="$1" target="_blank" rel="noopener">');
    out = out.replace(/&lt;\/a&gt;/g, '</a>');
    out = out.replace(/\{([a-zA-Z0-9_]{1,24})\}/g, '<code>{$1}</code>');
    return out;
  }

  /* ---------- شمارندهٔ حروف ---------- */
  function count1(ta) {
    var k = ta.getAttribute('data-key');
    var el = document.querySelector('[data-cnt-for="' + k + '"]');
    if (!el) return;
    var n = (ta.value || '').length;
    el.textContent = (window.faDigits ? window.faDigits(String(n)) : n) + '/' + (window.faDigits ? window.faDigits('3000') : '3000');
    el.style.color = n > 2800 ? 'var(--orange)' : '';
  }
  document.querySelectorAll('.tx-item textarea').forEach(function (ta) {
    count1(ta);
    ta.addEventListener('input', function () { count1(ta); });
  });

  /* ---------- دکمه‌های هر کارت ---------- */
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-prev],[data-fill],[data-reset],[data-ins]') : null;
    if (!b) return;

    var k = b.getAttribute('data-prev') || b.getAttribute('data-fill') || b.getAttribute('data-reset') || b.getAttribute('data-for');
    var ta = k ? document.getElementById('ta_' + k) : null;

    if (b.hasAttribute('data-prev')) {
      var box = document.getElementById('pv_' + k);
      if (!box || !ta) return;
      var src = (ta.value || '').trim() || (ta.getAttribute('data-def') || '');
      box.querySelector('.tx-bub').innerHTML = tgHtml(src) || '<i style="color:var(--muted)">خالی</i>';
      box.hidden = !box.hidden;
      return;
    }
    if (b.hasAttribute('data-fill') && ta) {
      ta.value = ta.getAttribute('data-def') || '';
      count1(ta);
      ta.focus();
      return;
    }
    if (b.hasAttribute('data-ins') && ta) {
      var ins = b.getAttribute('data-ins') || '';
      var p = ta.selectionStart != null ? ta.selectionStart : ta.value.length;
      ta.value = ta.value.slice(0, p) + ins + ta.value.slice(p);
      ta.focus();
      ta.selectionStart = ta.selectionEnd = p + ins.length;
      count1(ta);
      return;
    }
    if (b.hasAttribute('data-reset')) {
      var f = document.getElementById('txForm');
      if (!f) return;
      if (!confirm('این متن به پیش‌فرض کارخانه برگردد؟')) return;
      f.act.value = 'reset';
      f.key.value = b.getAttribute('data-reset') || '';
      f.submit();
    }
  });

  /* ---------- جستجو و فیلتر ---------- */
  var q = document.getElementById('txQ');
  var cnt = document.getElementById('txCnt');
  var chips = document.querySelectorAll('.tx-chip[data-tf]');
  var mode = 'all';

  function apply() {
    var term = (q && q.value || '').trim().toLowerCase();
    var seen = 0, all = 0;
    document.querySelectorAll('.tx-item').forEach(function (it) {
      all++;
      var okM = (mode === 'all') || (it.getAttribute('data-c') === mode);
      var okT = !term || (it.getAttribute('data-q') || '').indexOf(term) !== -1;
      var show = okM && okT;
      it.style.display = show ? '' : 'none';
      if (show) seen++;
    });
    if (cnt) {
      var t = seen + ' از ' + all + ' متن';
      cnt.textContent = window.faDigits ? window.faDigits(t) : t;
    }
  }
  chips.forEach(function (c) {
    c.addEventListener('click', function () {
      chips.forEach(function (x) { x.classList.remove('on'); });
      c.classList.add('on');
      mode = c.getAttribute('data-tf') || 'all';
      apply();
    });
  });
  if (q) q.addEventListener('input', apply);
  apply();

  /* ---------- آزمایش زندهٔ قاعده‌ها ---------- */
  var sm = document.getElementById('rlSample');
  var out = document.getElementById('rlOut');
  if (sm && out) {
    var fi = document.getElementById('rlFind');
    var to = document.getElementById('rlTo');
    var re = document.getElementById('rlRe');

    var run = function () {
      var s = sm.value || '';
      if (!s.trim()) { out.textContent = '—'; return; }
      var list = (window.TXRULES || []).filter(function (r) { return r.on; }).slice();
      if (fi && (fi.value || '').trim()) {
        list.push({ find: fi.value, to: (to && to.value) || '', re: (re && re.checked) ? 1 : 0 });
      }
      list.forEach(function (r) {
        try {
          if (r.re) s = s.replace(new RegExp(r.find, 'gu'), r.to);
          else s = s.split(r.find).join(r.to);
        } catch (e) { }
      });
      out.innerHTML = tgHtml(s);
    };
    [sm, fi, to, re].forEach(function (el) {
      if (el) el.addEventListener(el.type === 'checkbox' ? 'change' : 'input', run);
    });
  }
})();
</script>
