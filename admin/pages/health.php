<?php
if (!can('health.view')) { echo denyBox('این بخش برای شما فعال نیست.'); return; }
/** صفحهٔ سلامت سیستم — بازنویسی گسترده */

$act = (string)($_POST['act'] ?? '');

if ($act !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('err', 'نشست منقضی شده است. دوباره تلاش کنید.'); back('health'); }

    if ($act === 'hook') { need('health.view', 'health');
        $r = Tg::setWebhook(app_url('index.php'), (string)cfg('bot.secret', ''));
        if (!empty($r['ok'])) flash('ok', '🔗 وب‌هوک دوباره ثبت شد.');
        else flash('err', '⛔️ ثبت وب‌هوک ناموفق بود: ' . (string)($r['description'] ?? '-'));
        back('health');
    }

    if ($act === 'recover') { need('health.view', 'health');
        $r = Orders::recoverStuck(null, 50);
        flash(!empty($r['ok']) ? 'ok' : 'warn', (string)($r['message'] ?? 'بررسی انجام شد.'));
        back('health');
    }

    if ($act === 'logtest') { need('health.view', 'health');
        $r = Logs::testAll();
        flash(!empty($r['ok']) ? 'ok' : 'warn', (string)($r['message'] ?? 'پیام تست ارسال شد.'));
        back('health');
    }

    if ($act === 'clearerr') { need('health.view', 'health');
        DB::q('UPDATE {p}panels SET last_error = NULL');
        flash('ok', '🧹 خطاهای ثبت‌شدهٔ پنل‌ها پاک شد.');
        back('health');
    }

    if ($act === 'cfg') { need('health.view', 'health');
        DB::setSetting('order_stuck_minutes', (string)max(2, pint('order_stuck_minutes', 10)));
        DB::setSetting('health_quick_default', pchk('health_quick_default') ? '1' : '0');
        DB::loadSettings(true);
        flash('ok', '💾 تنطیمات ذخیره شد.');
        back('health');
    }

    if ($act === 'report') { need('health.view', 'health');
        $gs = Health::all(false);
        $ln = [];
        $ln[] = '# گزارش سلامت سیستم';
        $ln[] = 'تاریخ: ' . date('Y-m-d H:i:s');
        $ln[] = 'نسخه: ' . APP_VERSION;
        $ln[] = '';
        $lb = ['ok' => 'OK  ', 'warn' => 'WARN', 'err' => 'ERR '];
        foreach ($gs as $g) {
            $ln[] = '== ' . (string)($g['title'] ?? '-') . ' ==';
            foreach ((array)($g['items'] ?? []) as $i) {
                $st = (string)($i['status'] ?? 'ok');
                $ln[] = '[' . ($lb[$st] ?? 'OK  ') . '] '
                    . (string)($i['label'] ?? '-') . ': ' . (string)($i['value'] ?? '-')
                    . ((string)($i['hint'] ?? '') !== '' ? ' | ' . (string)$i['hint'] : '');
            }
            $ln[] = '';
        }
        $out = implode("\n", $ln);
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="health-' . date('Ymd-His') . '.txt"');
        header('Content-Length: ' . strlen($out));
        echo $out;
        exit;
    }
}

$quickDef = (string)DB::setting('health_quick_default', '0') === '1';
$liveReq  = $_GET['live'] ?? null;
$live     = $liveReq === null ? !$quickDef : ((string)$liveReq !== '0');

$groups = Health::all($live);
$sc     = Health::score($groups);
$stuck  = Orders::stuckCount();

$total = (int)($sc['total'] ?? 0);
$pct   = $total > 0 ? (int)round(((int)$sc['ok'] / $total) * 100) : 100;

$stMap = [
    'ok'   => ['b-green',  'سالم',   '✅', 'var(--green)'],
    'warn' => ['b-orange', 'هشدار', '⚠️', 'var(--orange)'],
    'err'  => ['b-red',    'خطا',    '⛔', 'var(--red)'],
];
$state  = (string)($sc['state'] ?? 'ok');
$stateT = $state === 'ok'
    ? 'همه‌چیز سالم است'
    : ($state === 'warn' ? 'چند هشدار وجود دارد' : 'خطای مهم پیدا شد');
$ringC  = $state === 'ok' ? 'var(--green)' : ($state === 'warn' ? 'var(--orange)' : 'var(--red)');

/* فهرست مشکلات: خطاها اول، بعد هشدارها */
$bad = [];
foreach ($groups as $g) {
    foreach ((array)($g['items'] ?? []) as $i) {
        $s = (string)($i['status'] ?? 'ok');
        if ($s === 'ok') continue;
        $bad[] = [
            'group'  => (string)($g['title'] ?? '-'),
            'icon'   => (string)($g['icon'] ?? ''),
            'label'  => (string)($i['label'] ?? '-'),
            'value'  => (string)($i['value'] ?? '-'),
            'hint'   => (string)($i['hint'] ?? ''),
            'status' => $s,
        ];
    }
}
usort($bad, static fn($a, $b) => ($a['status'] === 'err' ? 0 : 1) <=> ($b['status'] === 'err' ? 0 : 1));
?>

<style>
/* ===== health page ===== */
.hz-hero{position:relative;overflow:hidden;border-radius:18px;padding:18px 20px;margin:0 0 15px;
  background:linear-gradient(135deg,rgba(91,140,255,.16),rgba(139,92,246,.10)),var(--surface);
  border:1px solid var(--border);box-shadow:var(--shadow)}
.hz-hero::after{content:"";position:absolute;inset-inline-end:-90px;top:-100px;width:260px;height:260px;border-radius:50%;
  background:radial-gradient(circle,rgba(91,140,255,.20),transparent 70%);pointer-events:none}
.hz-top{display:flex;gap:18px;align-items:center;flex-wrap:wrap;position:relative;z-index:1}
.hz-ring{width:104px;height:104px;border-radius:50%;flex:0 0 auto;display:grid;place-items:center;
  background:conic-gradient(var(--c) calc(var(--p) * 1%), var(--surface-3) 0)}
.hz-ring i{width:82px;height:82px;border-radius:50%;background:var(--surface);display:grid;place-items:center;
  font-style:normal;font-family:var(--font-num);font-size:21px;font-weight:800}
.hz-hh{min-width:0;flex:1 1 240px}
.hz-hh h3{margin:0 0 5px;font-size:16.5px;display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.hz-hh .s{color:var(--muted);font-size:12.5px;line-height:1.95}
.hz-cells{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:15px;position:relative;z-index:1}
.hz-cell{background:var(--surface-2);border:1px solid var(--border);border-radius:14px;padding:11px 13px;text-align:center}
.hz-cell .v{font-family:var(--font-num);font-size:19px;font-weight:800;line-height:1.3}
.hz-cell .l{color:var(--muted);font-size:11px;margin-top:3px}
.hz-cell.g .v{color:var(--green)}
.hz-cell.o .v{color:var(--orange)}
.hz-cell.r .v{color:var(--red)}
.hz-tools{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;position:relative;z-index:1}
.hz-tools .btn{font-size:12px}

.hz-acts{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:0 0 15px}
.hz-act{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:14px;display:flex;
  flex-direction:column;gap:8px;box-shadow:var(--shadow-sm)}
.hz-act .i{font-size:22px;line-height:1}
.hz-act .t{font-size:13px;font-weight:700}
.hz-act .d{font-size:11.5px;color:var(--muted);line-height:1.95;flex:1}
.hz-act form{margin:0}
.hz-act .btn{width:100%}

.hz-bad{display:grid;gap:9px;margin-top:12px}
.hz-brow{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:11px;align-items:start;
  background:var(--surface-2);border:1px solid var(--border);border-inline-start:3px solid var(--orange);
  border-radius:13px;padding:11px 13px}
.hz-brow.e{border-inline-start-color:var(--red)}
.hz-brow .ic{font-size:15px;line-height:1.6}
.hz-brow .tl{font-size:12.5px;font-weight:700;line-height:1.8}
.hz-brow .vl{font-size:11.5px;color:var(--accent-text);font-family:var(--font-num);word-break:break-all;line-height:1.9}
.hz-brow .hn{font-size:11.5px;color:var(--muted);line-height:1.95;margin-top:2px}
.hz-brow .gp{font-size:10.5px;color:var(--muted);white-space:nowrap}
.hz-okbox{background:rgba(47,212,143,.10);border:1px solid rgba(47,212,143,.35);border-radius:14px;
  padding:14px;color:var(--green);font-size:12.5px;line-height:2;margin-top:12px}

.hz-fbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 13px}
.hz-chip{background:var(--surface-2);border:1px solid var(--border);color:var(--text-dim);border-radius:999px;
  padding:8px 14px;font:inherit;font-size:12px;cursor:pointer;transition:.15s}
.hz-chip:hover{border-color:var(--accent);color:var(--text)}
.hz-chip.on{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:700}
.hz-fbar .sp{flex:1 1 160px}
.hz-fbar input[type=search]{width:100%;min-width:150px}

.hz-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}
.hz-g{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:13px 15px;box-shadow:var(--shadow-sm)}
.hz-gh{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin:0 0 11px}
.hz-gh .tt{font-size:13.5px;font-weight:700;display:flex;align-items:center;gap:7px}
.hz-gh .sp{flex:1}
.hz-mini{display:flex;gap:6px;font-size:11px;font-family:var(--font-num)}
.hz-mini span{background:var(--surface-3);border-radius:8px;padding:2px 7px}
.hz-mini .g{color:var(--green)}
.hz-mini .o{color:var(--orange)}
.hz-mini .r{color:var(--red)}
.hz-items{display:grid;gap:7px}
.hz-i{display:grid;grid-template-columns:auto minmax(0,1fr);gap:10px;align-items:start;
  background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:9px 11px}
.hz-i .dot{width:9px;height:9px;border-radius:50%;margin-top:6px;background:var(--green)}
.hz-i.warn .dot{background:var(--orange)}
.hz-i.err .dot{background:var(--red)}
.hz-i.warn{border-color:rgba(255,169,46,.35)}
.hz-i.err{border-color:rgba(255,107,107,.40)}
.hz-i .k{font-size:12px;font-weight:600;line-height:1.85}
.hz-i .v{font-size:11.5px;color:var(--text-dim);font-family:var(--font-num);word-break:break-all;line-height:1.9}
.hz-i .h{font-size:11px;color:var(--muted);line-height:1.9;margin-top:2px}
.hz-none{color:var(--muted);font-size:12px;text-align:center;padding:12px}
@media (max-width:1000px){
  .hz-grid{grid-template-columns:1fr}
  .hz-acts{grid-template-columns:repeat(2,1fr)}
  .hz-cells{grid-template-columns:repeat(2,1fr)}
}
</style>

<div class="hz-hero">
  <div class="hz-top">
    <div class="hz-ring" style="--p:<?= (int)$pct ?>;--c:<?= $ringC ?>"><i><?= fa_num((string)$pct) ?>٪</i></div>
    <div class="hz-hh">
      <h3>🩺 سلامت سیستم <span class="badge <?= $stMap[$state][0] ?>"><?= h($stateT) ?></span></h3>
      <div class="s">
        بررسی خودکار نصب، دیتابیس، فایل‌ها، ربات، کران‌جاب، پنل‌ها و فروش.<br>
        آخرین بررسی: <b><?= fa_num(date('H:i')) ?></b> — حالت:
        <b><?= $live ? 'بررسی کامل (شامل تماس با تلگرام)' : 'بررسی سریع (بدون اینترنت)' ?></b>
      </div>
    </div>
  </div>

  <div class="hz-cells">
    <div class="hz-cell g"><div class="v"><?= fa_num((string)(int)$sc['ok']) ?></div><div class="l">سالم</div></div>
    <div class="hz-cell o"><div class="v"><?= fa_num((string)(int)$sc['warn']) ?></div><div class="l">هشدار</div></div>
    <div class="hz-cell r"><div class="v"><?= fa_num((string)(int)$sc['err']) ?></div><div class="l">خطا</div></div>
    <div class="hz-cell"><div class="v" style="font-size:14px;padding-top:4px"><?= h(APP_VERSION) ?></div><div class="l"><?= fa_num((string)$total) ?> مورد بررسی شد</div></div>
  </div>

  <div class="hz-tools">
    <a class="btn btn-sm btn-primary" href="?p=health&amp;live=1">🔎 بررسی کامل دوباره</a>
    <a class="btn btn-sm btn-ghost" href="?p=health&amp;live=0">⚡ بررسی سریع</a>
    <form method="post" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="act" value="report">
      <button class="btn btn-sm btn-ghost">📄 دانلود گزارش متنی</button>
    </form>
    <label class="hz-chip" style="display:inline-flex;align-items:center;gap:7px">
      <input type="checkbox" id="hzAuto"> بروزرسانی خودکار هر ۶۰ ثانیه
    </label>
  </div>
</div>

<div class="card compact">
  <div class="card-head tight">
    <div>
      <div class="card-title"><?= $bad === [] ? '✅ هیچ مشکلی پیدا نشد' : '🚨 مواردی که باید ببینید' ?></div>
      <div class="card-sub">خطاها و هشدارهای همهٔ بخش‌ها یکجا — برای رفع سریع.</div>
    </div>
    <?php if ($bad !== []): ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <span class="badge b-red">خطا: <?= fa_num((string)(int)$sc['err']) ?></span>
        <span class="badge b-orange">هشدار: <?= fa_num((string)(int)$sc['warn']) ?></span>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($bad === []): ?>
    <div class="hz-okbox">همهٔ <?= fa_num((string)$total) ?> مورد بررسی‌شده سالم هستند. چیزی برای نگرانی وجود ندارد.</div>
  <?php else: ?>
    <div class="hz-bad">
      <?php foreach ($bad as $bx): ?>
        <div class="hz-brow<?= $bx['status'] === 'err' ? ' e' : '' ?>">
          <div class="ic"><?= $bx['status'] === 'err' ? '⛔' : '⚠️' ?></div>
          <div>
            <div class="tl"><?= h($bx['label']) ?></div>
            <div class="vl"><?= h($bx['value']) ?></div>
            <?php if ($bx['hint'] !== ''): ?><div class="hn"><?= h($bx['hint']) ?></div><?php endif; ?>
          </div>
          <div class="gp"><?= h($bx['icon']) ?> <?= h($bx['group']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="hz-acts">
  <div class="hz-act">
    <div class="i">♻️</div>
    <div class="t">بازیابی سفارش‌های گیرکرده</div>
    <div class="d">سفارش‌های نیمه‌کاره یا به سرویس متصل می‌شوند یا پولشان برمی‌گردد.</div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="recover">
      <button class="btn btn-primary btn-sm">اجرا<?= $stuck > 0 ? ' (' . fa_num((string)$stuck) . ')' : '' ?></button>
    </form>
  </div>

  <div class="hz-act">
    <div class="i">🔗</div>
    <div class="t">ثبت مجدد وب‌هوک</div>
    <div class="d">اگر ربات جواب نمی‌دهد، اول این را بزنید.</div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="hook">
      <button class="btn btn-ghost btn-sm">ثبت کن</button>
    </form>
  </div>

  <div class="hz-act">
    <div class="i">🧪</div>
    <div class="t">تست تاپیک‌های لاگ</div>
    <div class="d">یک پیام آزمایشی به همهٔ تاپیک‌های گزارش می‌فرستد.</div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="logtest">
      <button class="btn btn-ghost btn-sm">ارسال تست</button>
    </form>
  </div>

  <div class="hz-act">
    <div class="i">🧹</div>
    <div class="t">پاک کردن خطای پنل‌ها</div>
    <div class="d">پیام خطای قدیمی پنل‌ها پاک می‌شود تا وضعیت تازه دیده شود.</div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="clearerr">
      <button class="btn btn-ghost btn-sm">پاک کن</button>
    </form>
  </div>
</div>

<div class="hz-fbar">
  <button type="button" class="hz-chip on" data-hf="all">همهٔ موارد</button>
  <button type="button" class="hz-chip" data-hf="err">فقط خطاها</button>
  <button type="button" class="hz-chip" data-hf="warn">فقط هشدارها</button>
  <span class="sp"><input type="search" id="hzQ" placeholder="🔎 جستجو در موارد بررسی…"></span>
</div>

<div class="hz-grid" id="hzGrid">
  <?php foreach ($groups as $g):
      $items = (array)($g['items'] ?? []);
      $c = ['ok' => 0, 'warn' => 0, 'err' => 0];
      foreach ($items as $i) { $s = (string)($i['status'] ?? 'ok'); if (!isset($c[$s])) $s = 'ok'; $c[$s]++; }
  ?>
    <div class="hz-g" data-grp="1">
      <div class="hz-gh">
        <div class="tt"><span><?= h((string)($g['icon'] ?? '')) ?></span> <?= h((string)($g['title'] ?? '-')) ?></div>
        <div class="sp"></div>
        <div class="hz-mini">
          <span class="g">✅ <?= fa_num((string)$c['ok']) ?></span>
          <?php if ($c['warn'] > 0): ?><span class="o">⚠️ <?= fa_num((string)$c['warn']) ?></span><?php endif; ?>
          <?php if ($c['err'] > 0): ?><span class="r">⛔ <?= fa_num((string)$c['err']) ?></span><?php endif; ?>
        </div>
      </div>

      <div class="hz-items">
        <?php foreach ($items as $i):
            $s = (string)($i['status'] ?? 'ok');
            if (!isset($stMap[$s])) $s = 'ok';
        ?>
          <div class="hz-i <?= h($s) ?>" data-st="<?= h($s) ?>"
               data-q="<?= h(mb_strtolower((string)($i['label'] ?? '') . ' ' . (string)($i['value'] ?? '') . ' ' . (string)($i['hint'] ?? ''))) ?>">
            <div class="dot"></div>
            <div>
              <div class="k"><?= h((string)($i['label'] ?? '-')) ?></div>
              <div class="v"><?= h((string)($i['value'] ?? '-')) ?></div>
              <?php if ((string)($i['hint'] ?? '') !== ''): ?>
                <div class="h"><?= h((string)$i['hint']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if ($items === []): ?><div class="hz-none">موردی بررسی نشد.</div><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card compact mt4">
  <div class="card-head tight">
    <div>
      <div class="card-title">⚙️ تنطیمات بازیابی و بررسی</div>
      <div class="card-sub">سفارشی که بیشتر از این مدت در حالت ساخت بماند، گیرکرده حساب می‌شود.</div>
    </div>
  </div>

  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="act" value="cfg">
    <div class="form-grid g2">
      <div class="field">
        <label>مهلت ساخت سفارش (دقیقه)</label>
        <input class="mono" type="number" name="order_stuck_minutes" min="2" max="120"
               value="<?= h((string)DB::setting('order_stuck_minutes', 10)) ?>">
        <div class="hint">پیشنهاد: ۱۰ دقیقه. در هر اجرای کران‌جاب بررسی می‌شوند.</div>
      </div>
      <div class="field">
        <label>حالت پیش‌فرض باز شدن این صفحه</label>
        <label class="check">
          <input type="checkbox" name="health_quick_default" value="1" <?= $quickDef ? 'checked' : '' ?>>
          <span>بررسی سریع (بدون تماس با تلگرام)</span>
        </label>
        <div class="hint">اگر صفحه کند بالا می‌آید این را روشن کنید.</div>
      </div>
    </div>
    <div class="sticky-acts"><button class="btn btn-primary">💾 ذخیره تنطیمات</button></div>
  </form>

  <div class="hint mt4">دستور کران‌جاب مورد نیاز (هر ۱۰ دقیقه یک‌بار):</div>
  <div class="copy-line" data-copy="*/10 * * * * php <?= h(APP_ROOT) ?>/cron/tasks.php >/dev/null 2>&1">*/10 * * * * php <?= h(APP_ROOT) ?>/cron/tasks.php &gt;/dev/null 2&gt;&amp;1</div>
</div>

<script>
(function () {
  var chips = document.querySelectorAll('.hz-chip[data-hf]');
  var q = document.getElementById('hzQ');
  var mode = 'all';

  function apply() {
    var term = (q && q.value || '').trim().toLowerCase();
    document.querySelectorAll('.hz-g').forEach(function (g) {
      var seen = 0;
      g.querySelectorAll('.hz-i').forEach(function (it) {
        var st = it.getAttribute('data-st') || 'ok';
        var hay = it.getAttribute('data-q') || '';
        var okMode = (mode === 'all') || (mode === st);
        var okTerm = !term || hay.indexOf(term) !== -1;
        var show = okMode && okTerm;
        it.style.display = show ? '' : 'none';
        if (show) seen++;
      });
      g.style.display = seen ? '' : 'none';
    });
  }

  chips.forEach(function (c) {
    c.addEventListener('click', function () {
      chips.forEach(function (x) { x.classList.remove('on'); });
      c.classList.add('on');
      mode = c.getAttribute('data-hf') || 'all';
      apply();
    });
  });
  if (q) q.addEventListener('input', apply);

  var auto = document.getElementById('hzAuto');
  var timer = null;
  if (auto) {
    try { auto.checked = localStorage.getItem('hzAuto') === '1'; } catch (e) { }
    var arm = function () {
      if (timer) { clearTimeout(timer); timer = null; }
      if (auto.checked) timer = setTimeout(function () { location.reload(); }, 60000);
    };
    auto.addEventListener('change', function () {
      try { localStorage.setItem('hzAuto', auto.checked ? '1' : '0'); } catch (e) { }
      arm();
    });
    arm();
  }
})();
</script>
