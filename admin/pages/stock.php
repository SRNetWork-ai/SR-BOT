<?php
/** انبار ملی — فروش دستی کانفیگ، وایرگارد، اوپن‌وی‌پی‌ان و اکانت */
declare(strict_types=1);

if (!can('stock.view')) { echo denyBox('بخش انبار ملی برای شما فعال نیست.'); return; }

$act = (string)($_POST['act'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {

    /* ---------- دسته ---------- */
    if ($act === 'catsave') {
        $id = pint('id');
        need($id ? 'stock.edit' : 'stock.create', 'stock');

        $nid = Stock::saveCat([
            'name'        => ptxt('name', 120),
            'icon'        => ptxt('icon', 16),
            'kind'        => ptxt('kind'),
            'description' => ptxt('description', 1500),
            'guide'       => ptxt('guide', 2000),
            'price'       => pint('price'),
            'rs_price'    => pint('rs_price'),
            'old_price'   => pint('old_price'),
            'days'        => pint('days'),
            'volume_gb'   => pflt('volume_gb'),
            'active'      => pchk('active'),
            'sort'        => pint('sort'),
        ], $id);

        flash('ok', $id ? '✅ دسته به‌روز شد.' : '✅ دستهٔ جدید ساخته شد. حالا از تب «افزودن انبوه» موجودی بریزید.');
        back('stock', ['cat' => $nid]);
    }

    if ($act === 'catdel') {
        need('stock.delete', 'stock');
        $r = Stock::delCat(pint('id'));
        flash('ok', '🗑 دسته حذف شد.' . (!empty($r['kept']) ? ' (' . fa_num((string)(int)$r['kept']) . ' قلم فروخته‌شده برای سوابق نگه داشته شد)' : ''));
        back('stock');
    }

    if ($act === 'cattoggle') {
        need('stock.edit', 'stock');
        $c = Stock::cat(pint('id'));
        if ($c) DB::update('stock_cats', ['active' => (int)$c['active'] ? 0 : 1], 'id = :id', [':id' => (int)$c['id']]);
        back('stock');
    }

    /* ---------- افزودن انبوه ---------- */
    if ($act === 'bulk') {
        need('stock.create', 'stock');
        $cid = pint('cat_id');
        if (!Stock::cat($cid)) { flash('err', 'ابتدا یک دسته بسازید.'); back('stock'); }

        $r = Stock::addBulk($cid, (string)($_POST['blob'] ?? ''), [
            'kind'     => ptxt('kind'),
            'note'     => ptxt('note', 250),
            'admin_id' => (int)($ADMIN['id'] ?? 0),
        ]);

        flash($r['ok'] ? 'ok' : 'err', (string)$r['message']);
        back('stock', ['cat' => $cid]);
    }

    /* ---------- اقلام ---------- */
    if ($act === 'itemdel') {
        need('stock.delete', 'stock');
        Stock::delItem(pint('id'));
        flash('ok', '🗑 قلم حذف شد.');
        back('stock', ['cat' => pint('cat')]);
    }

    if ($act === 'itemst') {
        need('stock.edit', 'stock');
        Stock::setStatus(pint('id'), ptxt('st'));
        flash('ok', '✅ وضعیت قلم تغییر کرد.');
        back('stock', ['cat' => pint('cat')]);
    }

    if ($act === 'purge') {
        need('stock.delete', 'stock');
        $n = Stock::purgeSold(pint('days', 90));
        flash('ok', '🧹 ' . fa_num((string)$n) . ' قلم فروخته‌شدهٔ قدیمی پاک شد.');
        back('stock');
    }

    /* ---------- آپلود فایل (TXT / ZIP) ---------- */
    if ($act === 'upload') {
        need('stock.create', 'stock');
        $cid = pint('cat_id');
        if (!Stock::cat($cid)) { flash('err', 'ابتدا یک دسته بسازید.'); back('stock'); }

        $f   = (array)($_FILES['file'] ?? []);
        /* 0.0.2 #10-stock-file */
        if ($f && class_exists('Upload') && !Upload::check($f, 16 * 1024 * 1024, ['csv', 'txt', 'json'])['ok']) $f = [];
        $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($err !== UPLOAD_ERR_OK || empty($f['tmp_name']) || !is_uploaded_file((string)$f['tmp_name'])) {
            $emap = [
                UPLOAD_ERR_INI_SIZE   => 'حجم فایل از سقف مجاز سرور بیشتر است.',
                UPLOAD_ERR_FORM_SIZE  => 'حجم فایل بیش از حد مجاز فرم است.',
                UPLOAD_ERR_PARTIAL    => 'آپلود ناقص ماند؛ دوباره تلاش کنید.',
                UPLOAD_ERR_NO_FILE    => 'فایلی انتخاب نشده است.',
                UPLOAD_ERR_NO_TMP_DIR => 'پوشهٔ موقت سرور در دسترس نیست.',
                UPLOAD_ERR_CANT_WRITE => 'نوشتن فایل موقت روی سرور ممکن نشد.',
            ];
            flash('err', '⚠️ ' . ($emap[$err] ?? 'آپلود فایل ناموفق بود.'));
            back('stock', ['cat' => $cid, 'tab' => 'up']);
        }

        $fname = (string)($f['name'] ?? 'file');
        $ext   = strtolower((string)pathinfo($fname, PATHINFO_EXTENSION));
        $mode  = ptxt('mode');
        $opt   = [
            'kind'     => ptxt('kind'),
            'note'     => ptxt('note', 250),
            'admin_id' => (int)($ADMIN['id'] ?? 0),
        ];

        if ($ext === 'zip') {
            $opt['mode'] = $mode === 'lines' ? 'lines' : 'file';
            $r = Stock::importZip($cid, (string)$f['tmp_name'], $opt);

        } elseif (in_array($ext, ['txt', 'text', 'conf', 'ovpn', 'cfg', 'ini', 'json', 'yaml', 'yml', 'md', 'log'], true)) {
            $auto = in_array($ext, ['conf', 'ovpn'], true) ? 'file' : 'lines';
            $opt['mode']      = in_array($mode, ['lines', 'file'], true) ? $mode : $auto;
            $opt['file_name'] = $fname;
            $body = (string)@file_get_contents((string)$f['tmp_name']);
            $r = Stock::importTxt($cid, $body, $opt);

        } else {
            flash('err', '⚠️ فقط فایل TXT / CONF / OVPN (کانفیگ متنی) یا ZIP (کانفیگ فایلی) پذیرفته می‌شود.');
            back('stock', ['cat' => $cid, 'tab' => 'up']);
        }

        app_log('stock', 'upload ' . $fname . ' cat=' . $cid . ' added=' . (int)($r['added'] ?? 0));
        flash(!empty($r['ok']) ? 'ok' : 'err', (string)$r['message']);
        back('stock', ['cat' => $cid, 'tab' => 'up']);
    }

    /* ---------- عملیات گروهی روی اقلام ---------- */
    if ($act === 'bulkact') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
        $do  = ptxt('do');
        $cid = pint('cat');

        if (!$ids) {
            flash('err', 'هیچ قلمی انتخاب نشده است.');
            back('stock', ['cat' => $cid]);
        }
        $in = implode(',', $ids);

        if ($do === 'export') {
            need('stock.reveal', 'stock');
            $rows = DB::all("SELECT * FROM {p}stock_items WHERE id IN ($in) ORDER BY id ASC");
            $out  = [];
            foreach ($rows as $r0) {
                $b = trim((string)($r0['payload'] ?? ''));
                if ($b !== '') $out[] = $b;
            }
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="stock-' . date('Ymd-His') . '.txt"');
            echo implode("\n\n", $out);
            exit;
        }

        if ($do === 'delete') {
            need('stock.delete', 'stock');
            DB::q("DELETE FROM {p}stock_items WHERE id IN ($in)");
            flash('ok', '🗑 ' . fa_num((string)count($ids)) . ' قلم حذف شد.');

        } elseif ($do === 'free' || $do === 'disable') {
            need('stock.edit', 'stock');
            $to = $do === 'free' ? 'free' : 'disabled';
            foreach ($ids as $id0) Stock::setStatus((int)$id0, $to);
            flash('ok', '✅ وضعیت ' . fa_num((string)count($ids)) . ' قلم به «' . Stock::stLabel($to) . '» تغییر کرد.');
        }

        back('stock', ['cat' => $cid]);
    }

    /* ---------- تنظیمات ---------- */
    if ($act === 'settings') {
        need('stock.settings', 'stock');
        DB::setSetting('stock_enabled',    pchk('stock_enabled') ? '1' : '0');
        DB::setSetting('stock_title',      ptxt('stock_title', 40));
        DB::setSetting('stock_icon',       ptxt('stock_icon', 16));
        DB::setSetting('stock_note',       ptxt('stock_note', 500));
        DB::setSetting('stock_low_alert',  (string)pint('stock_low_alert'));
        DB::setSetting('stock_per_user',   (string)pint('stock_per_user'));
        DB::setSetting('stock_show_empty', pchk('stock_show_empty') ? '1' : '0');
        DB::setSetting('stock_rs_enabled',  pchk('stock_rs_enabled') ? '1' : '0');
        DB::setSetting('stock_rs_per_user', (string)pint('stock_rs_per_user'));
        flash('ok', '✅ تنظیمات انبار ذخیره شد.');
        back('stock');
    }
}

/* ================= داده‌ها ================= */

$st       = Stock::stats();
$cats     = Stock::cats();
$catId    = (int)($_GET['cat'] ?? 0);
$editCat  = (int)($_GET['ecat'] ?? 0);
$ec       = $editCat ? Stock::cat($editCat) : null;
$fStatus  = (string)($_GET['st'] ?? '');
$fQ       = trim((string)($_GET['q'] ?? ''));
$page     = max(1, (int)($_GET['pg'] ?? 1));
$per      = 60;

$filters = ['cat' => $catId, 'status' => $fStatus, 'q' => $fQ, 'limit' => $per, 'offset' => ($page - 1) * $per];
$items   = Stock::items($filters);
$total   = Stock::itemsCount($filters);
$pages   = max(1, (int)ceil($total / $per));
$low     = Stock::lowStock();
$byKind  = DB::all(
    "SELECT kind, COUNT(*) AS n,
            SUM(CASE WHEN status = 'free' THEN 1 ELSE 0 END) AS f
       FROM {p}stock_items GROUP BY kind ORDER BY n DESC"
);
$rev7    = Stock::revenue(7);
$maxUp   = Stock::maxUpload();
$tabQ    = preg_replace('~[^a-z0-9_]~i', '', (string)($_GET['tab'] ?? ''));
$reveal  = can('stock.reveal');
$canAdd  = can('stock.create');
$v       = static function (string $k, $d = '') use ($ec) { return $ec[$k] ?? $d; };
?>

<section class="stk-hero fade-up">
  <span class="sh-glow" aria-hidden="true"></span>

  <div class="sh-top">
    <div class="sh-ic"><?= h(Stock::icon()) ?></div>
    <div class="sh-ttl">
      <h2><?= h(Stock::title()) ?>
        <span class="ver <?= Stock::enabled() ? 'ok' : 'off' ?>"><?= Stock::enabled() ? 'فعال در ربات' : 'خاموش' ?></span>
      </h2>
      <div class="sub">
        اقلام را از پیش درون انبار بریزید؛ ربات هنگام خرید خودکار یک قلم آزاد را قفل و تحویل می‌دهد.<br>
        پشتیبانی: <b>کانفیگ V2Ray</b> · <b>لینک ساب</b> · <b>WireGuard</b> · <b>OpenVPN</b> · <b>اکانت</b> · <b>فایل و متن</b>
      </div>
    </div>
    <div class="sh-act">
      <?php if ($canAdd): ?>
        <button type="button" class="btn primary sm" data-tab-group="stk" data-tab="up">⬆️ آپلود TXT / ZIP</button>
        <button type="button" class="btn ghost sm" data-tab-group="stk" data-tab="add">➕ افزودن انبوه</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="stk-cells">
    <div class="stk-cell b"><div class="v"><?= fa_num((string)$st['total']) ?></div><div class="l">کل اقلام</div></div>
    <div class="stk-cell g"><div class="v"><?= fa_num((string)$st['free']) ?></div><div class="l">آزاد</div></div>
    <div class="stk-cell"><div class="v"><?= fa_num((string)$st['sold']) ?></div><div class="l">فروخته‌شده</div></div>
    <div class="stk-cell o"><div class="v"><?= fa_num((string)$st['today']) ?></div><div class="l">فروش امروز</div></div>
    <div class="stk-cell p"><div class="v"><?= fa_num((string)$st['cats']) ?></div><div class="l">دسته</div></div>
    <div class="stk-cell"><div class="v"><?= fa_num((string)(int)$st['hold']) ?></div><div class="l">رزرو</div></div>
    <div class="stk-cell g"><div class="v"><?= fa_num(number_format($rev7)) ?></div><div class="l">درآمد ۷ روز</div></div>
    <div class="stk-cell g"><div class="v"><?= fa_num(number_format(Stock::revenue(30))) ?></div><div class="l">درآمد ۳۰ روز</div></div>
  </div>

  <?php if ($byKind): ?>
    <div class="stk-chips">
      <?php foreach ($byKind as $bk): $kk = (string)$bk['kind']; ?>
        <span class="chip" title="<?= h(Stock::kindLabel($kk)) ?>">
          <?= h(Stock::kindIcon($kk)) ?> <?= h(Stock::kindLabel($kk)) ?>
          <b><?= fa_num((string)(int)$bk['f']) ?></b><span class="sl">/<?= fa_num((string)(int)$bk['n']) ?></span>
        </span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($low): ?>
    <div class="stk-low">
      <?php foreach ($low as $l): ?>
        <a class="badge <?= (int)$l['free'] < 1 ? 'b-red' : 'b-orange' ?>" href="?p=stock&cat=<?= (int)$l['id'] ?>">
          ⚠️ <?= h((string)$l['name']) ?>: <?= fa_num((string)(int)$l['free']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<nav class="stk-nav">
  <button type="button" class="sn on" data-tab-group="stk" data-tab="items">
    <span class="i">📦</span><span class="t">اقلام انبار</span>
    <span class="d"><?= fa_num((string)$st['total']) ?> قلم ثبت‌شده</span>
  </button>
  <button type="button" class="sn" data-tab-group="stk" data-tab="cats">
    <span class="i">🗂</span><span class="t">دسته‌های فروش</span>
    <span class="d"><?= fa_num((string)$st['cats']) ?> محصول در ربات</span>
  </button>
  <?php if ($canAdd): ?>
    <button type="button" class="sn" data-tab-group="stk" data-tab="add">
      <span class="i">➕</span><span class="t">افزودن انبوه</span>
      <span class="d">پیست متنی چندخطی</span>
    </button>
    <button type="button" class="sn" data-tab-group="stk" data-tab="up">
      <span class="i">⬆️</span><span class="t">آپلود فایل</span>
      <span class="d">TXT برای متنی · ZIP برای فایلی</span>
    </button>
  <?php endif; ?>
  <?php if (can('stock.settings')): ?>
    <button type="button" class="sn" data-tab-group="stk" data-tab="set">
      <span class="i">⚙️</span><span class="t">تنظیمات</span>
      <span class="d">عنوان، سقف خرید، هشدارها</span>
    </button>
  <?php endif; ?>
</nav>

<!-- ==================== اقلام ==================== -->
<div class="tab-panel on" data-tab-panel="items" data-tab-panel-group="stk">
  <div class="card">
    <div class="card-head tight">
      <div>
        <div class="card-title">📦 اقلام انبار</div>
        <div class="card-sub"><?= fa_num((string)$total) ?> قلم مطابق صافی فعلی</div>
      </div>
    </div>

    <form method="get" class="form-grid g2" style="margin-bottom:12px">
      <input type="hidden" name="p" value="stock">
      <div class="field">
        <label>دسته</label>
        <select name="cat">
          <option value="0">— همهٔ دسته‌ها —</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $catId === (int)$c['id'] ? 'selected' : '' ?>>
              <?= h((string)($c['icon'] ?: '📦')) ?> <?= h((string)$c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>وضعیت</label>
        <select name="st">
          <option value="">— همه —</option>
          <?php foreach (Stock::ST as $k => $lb): ?>
            <option value="<?= h($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= h($lb[1] . ' ' . $lb[0]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>جستجو</label>
        <input type="text" name="q" value="<?= h($fQ) ?>" placeholder="عنوان، یادداشت یا محتوا…">
      </div>
      <div class="field" style="align-self:end">
        <div class="btn-row">
          <button class="btn primary sm">🔍 اعمال صافی</button>
          <a class="btn ghost sm" href="?p=stock">♻️ پاک‌کردن</a>
        </div>
      </div>
    </form>

    <?php if ($items): ?>
      <form method="post" id="stkBulk" class="stk-bulk" data-confirm="عملیات روی اقلام انتخاب‌شده انجام شود؟">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="bulkact">
        <input type="hidden" name="cat" value="<?= $catId ?>">
        <span class="n">☑️ <b id="stkCnt">۰</b> قلم انتخاب شد</span>
        <?php if (can('stock.edit')): ?>
          <button class="btn ghost sm" name="do" value="free">♻️ آزادسازی</button>
          <button class="btn ghost sm" name="do" value="disable">⏸ غیرفعال</button>
        <?php endif; ?>
        <?php if ($reveal): ?>
          <button class="btn ghost sm" name="do" value="export">⬇️ خروجی TXT</button>
        <?php endif; ?>
        <?php if (can('stock.delete')): ?>
          <button class="btn red sm" name="do" value="delete">🗑 حذف گروهی</button>
        <?php endif; ?>
      </form>
    <?php endif; ?>

    <?php if (!$items): ?>
      <div class="empty"><div class="ic">📦</div>
        <b>هیچ قلمی پیدا نشد</b>
        <div class="muted xs mt2">از تب «افزودن انبوه» موجودی بریزید.</div>
      </div>
    <?php else: ?>
      <div class="tbl-wrap">
        <table class="stk-tbl">
          <thead>
            <tr>
              <th class="ck"><input type="checkbox" id="stkAll" title="انتخاب همه"></th>
              <th>#</th><th>دسته</th><th>نوع</th><th>عنوان</th>
              <th>محتوا</th><th>وضعیت</th><th>خریدار</th><th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($items as $it):
              $k  = (string)$it['kind'];
              $s  = (string)$it['status'];
              $pv = trim((string)$it['payload']);
              $pv = $pv !== '' ? mb_substr(preg_replace('~\s+~u', ' ', $pv), 0, 90) : ('📎 ' . (string)($it['file_name'] ?: 'file'));
          ?>
            <tr>
              <td class="ck"><input type="checkbox" class="stk-ck" form="stkBulk" name="ids[]" value="<?= (int)$it['id'] ?>"></td>
              <td data-l="کد" class="mono">#<?= (int)$it['id'] ?></td>
              <td data-l="دسته"><?= h((string)($it['cat_icon'] ?: '')) ?> <?= h((string)($it['cat_name'] ?: '—')) ?></td>
              <td data-l="نوع"><span class="chip"><?= h(Stock::kindIcon($k) . ' ' . Stock::kindLabel($k)) ?></span></td>
              <td data-l="عنوان"><?= h((string)$it['title']) ?></td>
              <td data-l="محتوا">
                <div class="stk-pay <?= $reveal ? '' : 'stk-blur' ?>"><?= h($pv) ?></div>
              </td>
              <td data-l="وضعیت">
                <span class="badge <?= $s === 'free' ? 'b-green' : ($s === 'sold' ? 'b-blue' : ($s === 'hold' ? 'b-orange' : 'b-gray')) ?>">
                  <?= h(Stock::stIcon($s) . ' ' . Stock::stLabel($s)) ?>
                </span>
              </td>
              <td data-l="خریدار" class="mono xs">
                <?= $it['tg_id'] ? h((string)$it['tg_id']) : '<span class="muted">—</span>' ?>
                <?php if ($it['sold_at']): ?><div class="muted xs"><?= h(to_jalali((string)$it['sold_at'], true)) ?></div><?php endif; ?>
              </td>
              <td>
                <div class="stk-acts">
                  <?php if (can('stock.edit')): ?>
                    <form method="post" class="inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="act" value="itemst">
                      <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                      <input type="hidden" name="cat" value="<?= $catId ?>">
                      <input type="hidden" name="st" value="<?= $s === 'free' ? 'disabled' : 'free' ?>">
                      <button title="<?= $s === 'free' ? 'غیرفعال کردن' : 'آزادسازی مج��د' ?>"><?= $s === 'free' ? '⏸' : '♻️' ?></button>
                    </form>
                  <?php endif; ?>
                  <?php if ($reveal && trim((string)$it['payload']) !== ''): ?>
                    <button type="button" data-copy="<?= h((string)$it['payload']) ?>" title="کپی محتوا">📋</button>
                  <?php endif; ?>
                  <?php if (can('stock.delete')): ?>
                    <form method="post" class="inline" data-confirm="این قلم حذف شود؟">
                      <?= csrf_field() ?>
                      <input type="hidden" name="act" value="itemdel">
                      <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                      <input type="hidden" name="cat" value="<?= $catId ?>">
                      <button title="حذف">🗑</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($pages > 1): ?>
        <div class="tbl-pager mt3">
          <?php for ($i = 1; $i <= min($pages, 20); $i++): ?>
            <a class="btn sm <?= $i === $page ? 'primary' : 'ghost' ?>"
               href="?p=stock&cat=<?= $catId ?>&st=<?= h($fStatus) ?>&q=<?= h(urlencode($fQ)) ?>&pg=<?= $i ?>"><?= fa_num((string)$i) ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (can('stock.delete')): ?>
      <div class="card-foot">
        <form method="post" class="inline" data-confirm="اقلام فروخته‌شدهٔ قدیمی پاک شوند؟">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="purge">
          <input type="number" name="days" value="90" min="7" style="width:82px" class="numbox">
          <button class="btn ghost sm">🧹 پاک‌سازی سوابق قدیمی‌تر از این روز</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ==================== دسته‌ها ==================== -->
<div class="tab-panel" data-tab-panel="cats" data-tab-panel-group="stk">
  <div class="card">
    <div class="card-head tight">
      <div>
        <div class="card-title">🗂 دسته‌های فروش</div>
        <div class="card-sub">هر دسته یک محصول در ربات است. قیمت و راهنما روی دسته تنظیم می‌شود.</div>
      </div>
    </div>

    <?php if (!$cats): ?>
      <div class="empty"><div class="ic">🗂</div><b>هنوز دسته‌ای نساخته‌اید</b>
        <div class="muted xs mt2">فرم پایین را پر کنید.</div></div>
    <?php else: ?>
      <div class="stk-cats">
        <?php foreach ($cats as $c):
            $free = Stock::freeCount((int)$c['id']);
            $sold = (int)$c['sold'];
            $tot  = max(1, $free + $sold);
            $pct  = (int)round($free / $tot * 100);
        ?>
          <div class="stk-cat <?= (int)$c['active'] ? '' : 'off' ?>">
            <div class="ch">
              <div class="ci"><?= h((string)($c['icon'] ?: Stock::kindIcon((string)$c['kind']))) ?></div>
              <div style="min-width:0;flex:1">
                <div class="cn"><?= h((string)$c['name']) ?></div>
                <div class="ck"><?= h(Stock::kindLabel((string)$c['kind'])) ?></div>
              </div>
            </div>

            <div class="cb">
              <span class="badge <?= $free < 1 ? 'b-red' : ($free <= Stock::lowAlert() ? 'b-orange' : 'b-green') ?>">موجودی: <?= fa_num((string)$free) ?></span>
              <span class="badge b-gray">فروش: <?= fa_num((string)$sold) ?></span>
              <?php if ((int)$c['days'] > 0): ?><span class="badge b-blue"><?= fa_num((string)(int)$c['days']) ?> روز</span><?php endif; ?>
              <?php if ((float)$c['volume_gb'] > 0): ?><span class="badge b-blue"><?= fa_num((string)(float)$c['volume_gb']) ?> گیگ</span><?php endif; ?>
              <?php if (!(int)$c['active']): ?><span class="badge b-gray">غیرفعال</span><?php endif; ?>
            </div>

            <div class="stk-bar <?= $free <= Stock::lowAlert() ? 'warn' : '' ?>"><i style="width:<?= max(3, $pct) ?>%"></i></div>

            <div class="cp">
              <?= fa_num(number_format((int)$c['price'])) ?> <?= h(currency()) ?>
              <?php if ((int)$c['old_price'] > 0): ?><s><?= fa_num(number_format((int)$c['old_price'])) ?></s><?php endif; ?>
            </div>
            <?php if (Stock::rsEnabled()): ?>
              <?php $rsP = Stock::rsPrice($c); $rsG = Stock::rsProfit($c); ?>
              <div class="muted xs">
                🏷 نماینده: <b><?= fa_num(number_format($rsP)) ?></b>
                <?php if ($rsG > 0): ?>
                  · سود <span class="badge b-green"><?= fa_num(number_format($rsG)) ?></span>
                <?php else: ?>
                  · <span class="muted">بدون سود</span>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <div class="btn-row">
              <a class="btn ghost sm" href="?p=stock&cat=<?= (int)$c['id'] ?>">📦 اقلام</a>
              <?php if (can('stock.edit')): ?>
                <a class="btn ghost sm" href="?p=stock&ecat=<?= (int)$c['id'] ?>#catform">✏️ ویرایش</a>
                <form method="post" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="cattoggle">
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <button class="btn ghost sm"><?= (int)$c['active'] ? '⏸' : '▶️' ?></button>
                </form>
              <?php endif; ?>
              <?php if (can('stock.delete')): ?>
                <form method="post" class="inline" data-confirm="دسته حذف شود؟ اقلام آزاد آن هم پاک می‌شوند.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="catdel">
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <button class="btn red sm">🗑</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($canAdd || $ec): ?>
  <div class="card" id="catform">
    <div class="card-head tight">
      <div class="card-title"><?= $ec ? '✏️ ویرایش دسته' : '➕ دستهٔ جدید' ?></div>
      <?php if ($ec): ?><a class="btn ghost sm" href="?p=stock">✕ انصراف</a><?php endif; ?>
    </div>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="catsave">
      <input type="hidden" name="id" value="<?= (int)$editCat ?>">

      <div class="form-grid g2">
        <div class="field">
          <label>نام محصول <span class="muted">*</span></label>
          <input type="text" name="name" maxlength="120" required value="<?= h((string)$v('name')) ?>" placeholder="مثلاً: وایرگارد آلمان – ۳۰ روزه">
        </div>
        <div class="field">
          <label>آیکون</label>
          <input type="text" name="icon" maxlength="16" value="<?= h((string)$v('icon')) ?>" placeholder="🔐">
        </div>

        <div class="field">
          <label>نوع کالا</label>
          <select name="kind">
            <?php foreach (Stock::KINDS as $k => $meta): ?>
              <option value="<?= h($k) ?>" <?= (string)$v('kind', 'config') === $k ? 'selected' : '' ?>>
                <?= h($meta[1] . ' ' . $meta[0]) ?><?= $meta[2] !== '' ? ' (.' . h($meta[2]) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="hint">وایرگارد و اوپن‌وی‌پی‌ان به صورت <b>فایل آماده</b> تحویل می‌شوند.</div>
        </div>
        <div class="field">
          <label>ترتیب نمایش</label>
          <input type="number" name="sort" class="numbox" value="<?= (int)$v('sort', 0) ?>">
        </div>

        <div class="field">
          <label>قیمت (<?= h(currency()) ?>)</label>
          <input type="number" name="price" class="numbox" min="0" value="<?= (int)$v('price', 0) ?>">
          <div class="hint">قیمتی که کاربر عادی می‌پردازد</div>
        </div>
        <div class="field">
          <label>🏷 قیمت نماینده (<?= h(currency()) ?>)</label>
          <input type="number" name="rs_price" class="numbox" min="0" value="<?= (int)$v('rs_price', 0) ?>">
          <div class="hint">
            قیمت خرید نماینده از انبار. اختلاف آن با قیمت بالا سود نماینده است.
            <b>۰</b> یعنی همان قیمت عمومی (بدون سود)
          </div>
        </div>

        <div class="field">
          <label>قیمت قبل از تخفیف</label>
          <input type="number" name="old_price" class="numbox" min="0" value="<?= (int)$v('old_price', 0) ?>">
          <div class="hint">خالی یا ۰ یعنی بدون خط قرمز</div>
        </div>

        <div class="field">
          <label>مدت اعتبار (روز)</label>
          <input type="number" name="days" class="numbox" min="0" value="<?= (int)$v('days', 0) ?>">
          <div class="hint">فقط نمایشی است</div>
        </div>
        <div class="field">
          <label>حجم (گیگ)</label>
          <input type="number" step="0.1" name="volume_gb" class="numbox" min="0" value="<?= h((string)$v('volume_gb', 0)) ?>">
        </div>
      </div>

      <div class="field mt3">
        <label>توضیح کوتاه (زیر نام محصول در ربات)</label>
        <textarea name="description" rows="3" maxlength="1500"><?= h((string)$v('description')) ?></textarea>
      </div>

      <div class="field">
        <label>راهنمای اتصال (پس از خرید ارسال می‌شود)</label>
        <textarea name="guide" rows="4" maxlength="2000" placeholder="مثلاً: فایل را در اپلیکیشن WireGuard وارد کنید…"><?= h((string)$v('guide')) ?></textarea>
      </div>

      <div class="check">
        <label><input type="checkbox" name="active" value="1" <?= (int)$v('active', 1) ? 'checked' : '' ?>> فعال و قابل فروش در ربات</label>
      </div>

      <div class="card-foot">
        <button class="btn primary">💾 ذخیرهٔ دسته</button>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>

<!-- ==================== افزودن انبوه ==================== -->
<?php if ($canAdd): ?>
<div class="tab-panel" data-tab-panel="add" data-tab-panel-group="stk">
  <div class="card">
    <div class="card-head tight">
      <div>
        <div class="card-title">➕ افزودن انبوه به انبار</div>
        <div class="card-sub">محتوا را پیست کنید — سیستم خودکار قلم‌ها را جدا می‌کند و تکراری‌ها را رد می‌کند.</div>
      </div>
    </div>

    <?php if (!$cats): ?>
      <div class="alert w">ابتدا از تب «دسته‌های فروش» یک دسته بسازید.</div>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="bulk">

        <div class="form-grid g2">
          <div class="field">
            <label>دستهٔ مقصد <span class="muted">*</span></label>
            <select name="cat_id" required>
              <?php foreach ($cats as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $catId === (int)$c['id'] ? 'selected' : '' ?>>
                  <?= h((string)($c['icon'] ?: '📦')) ?> <?= h((string)$c['name']) ?>
                  — <?= h(Stock::kindLabel((string)$c['kind'])) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>یادداشت مشترک (اختیاری)</label>
            <input type="text" name="note" maxlength="250" placeholder="مثلاً: لوکیشن فرانکفورت">
          </div>
        </div>

        <div class="field mt3">
          <label>نوع کالا (خالی = همان نوع دسته)</label>
          <div class="stk-kb">
            <label><input type="radio" name="kind" value="" checked> ⚙️ همان نوع دسته</label>
            <?php foreach (Stock::KINDS as $k => $meta): ?>
              <label><input type="radio" name="kind" value="<?= h($k) ?>"> <?= h($meta[1] . ' ' . $meta[0]) ?></label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="field mt3">
          <label>محتوا <span class="muted">*</span></label>
          <textarea name="blob" class="stk-blob" required placeholder="vless://...&#10;vless://...&#10;&#10;--- یا برای وایرگارد/اوپن‌وی‌پی‌ان هر کانفیگ را با --- جدا کنید ---&#10;[Interface]&#10;PrivateKey = ..."></textarea>
        </div>

        <div class="fieldset accent mt3">
          <div class="section-title">📐 قانون جداسازی</div>
          <ul class="muted xs" style="line-height:2;padding-inline-start:18px;margin:0">
            <li><b>کانفیگ / لینک / اکانت:</b> هر خط یک قلم جداگانه</li>
            <li><b>وایرگارد / اوپن‌وی‌پی‌ان / متن:</b> با یک خط خالی جدا می‌شوند</li>
            <li>خط <span class="mono">---</span> همیشه قطعی‌ترین جداکننده است</li>
            <li>اقلام تکراری در همان دسته خودکار رد می‌شوند</li>
          </ul>
        </div>

        <div class="card-foot">
          <button class="btn primary">⬆️ افزودن به انبار</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ==================== آپلود فایل ==================== -->
<?php if ($canAdd): ?>
<div class="tab-panel" data-tab-panel="up" data-tab-panel-group="stk">
  <div class="card">
    <div class="card-head tight">
      <div>
        <div class="card-title">⬆️ آپلود فایل کانفیگ</div>
        <div class="card-sub">فایل را بدهید؛ سیستم خودکار می‌خواند، کانفیگ‌ها را جدا می‌کند و داخل انبار می‌ریزد.</div>
      </div>
    </div>

    <div class="stk-up2">
      <div class="stk-upc">
        <span class="i">📄</span>
        <b>کانفیگ متنی ← فایل TXT</b>
        <span>هر خط یک کانفیگ: <span class="mono ltr">vless:// vmess:// trojan:// ss://</span> یا لینک ساب و اکانت.
        خطوط خالی و توضیحی (<span class="mono ltr">#</span> و <span class="mono ltr">//</span>) خودکار نادیده گرفته می‌شوند.</span>
      </div>
      <div class="stk-upc">
        <span class="i">🗜</span>
        <b>کانفیگ فایلی ← فایل ZIP</b>
        <span>هر فایل داخل زیپ یک قلم انبار می‌شود؛ مناسب <span class="mono ltr">.conf</span> وایرگارد و <span class="mono ltr">.ovpn</span> اوپن‌وی‌پی‌ان.
        نام هر فایل همان عنوان قلم می‌شود.</span>
      </div>
    </div>

    <?php if (!$cats): ?>
      <div class="alert w mt3">ابتدا از تب «دسته‌های فروش» یک دسته بسازید، بعد فایل را آپلود کنید.</div>
    <?php else: ?>
      <form method="post" enctype="multipart/form-data" class="mt3" id="stkUpForm">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="upload">

        <label class="stk-drop" id="stkDrop">
          <input type="file" name="file" id="stkFile" required hidden
                 accept=".txt,.text,.conf,.ovpn,.cfg,.ini,.json,.yaml,.yml,.md,.log,.zip">
          <span class="ic">📥</span>
          <b>فایل را اینجا رها کنید یا کلیک کنید</b>
          <span class="muted xs">TXT · CONF · OVPN · ZIP<?= $maxUp > 0 ? ' — حداکثر ' . h(human_bytes($maxUp)) : '' ?></span>
          <span class="pick" id="stkPick" hidden></span>
        </label>

        <div class="form-grid g2 mt3">
          <div class="field">
            <label>دستهٔ مقصد <span class="muted">*</span></label>
            <select name="cat_id" required>
              <?php foreach ($cats as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $catId === (int)$c['id'] ? 'selected' : '' ?>>
                  <?= h((string)($c['icon'] ?: '📦')) ?> <?= h((string)$c['name']) ?>
                  — <?= h(Stock::kindLabel((string)$c['kind'])) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>یادداشت مشترک (اختیاری)</label>
            <input type="text" name="note" maxlength="250" placeholder="مثلاً: لوکیشن فرانکفورت — خرید مرداد">
          </div>
        </div>

        <div class="field mt3">
          <label>روش جداسازی</label>
          <div class="stk-kb">
            <label><input type="radio" name="mode" value="auto" checked> 🧠 هوشمند (پیشنهادی)</label>
            <label><input type="radio" name="mode" value="lines"> 📄 هر خط یک کانفیگ</label>
            <label><input type="radio" name="mode" value="file"> 📁 هر فایل یک قلم</label>
          </div>
          <div class="hint">
            حالت هوشمند: <b>TXT</b> خط‌به‌خط خوانده می‌شود، <b>CONF/OVPN</b> و هر فایل داخل <b>ZIP</b> یک قلم کامل حساب می‌شود.
          </div>
        </div>

        <div class="field mt3">
          <label>نوع کالا (خالی = تشخیص خودکار / همان نوع دسته)</label>
          <div class="stk-kb">
            <label><input type="radio" name="kind" value="" checked> ⚙️ خودکار</label>
            <?php foreach (Stock::KINDS as $k => $meta): ?>
              <label><input type="radio" name="kind" value="<?= h($k) ?>"> <?= h($meta[1] . ' ' . $meta[0]) ?></label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="fieldset accent mt3">
          <div class="section-title">🛡 قوانین پردازش فایل</div>
          <ul class="muted xs" style="line-height:2;padding-inline-start:18px;margin:0">
            <li>اقلام تکراری در همان دسته خودکار رد می‌شوند</li>
            <li>داخل زیپ فقط فایل‌های متنی (txt / conf / ovpn / cfg / ini / json / yaml) خوانده می‌شوند</li>
            <li>پوشه‌ها و فایل‌های مخفی و <span class="mono ltr">__MACOSX</span> نادیده گرفته می‌شوند</li>
            <li>سقف هر فایل داخل زیپ: <b><?= h(human_bytes(Stock::MAX_ENTRY)) ?></b></li>
            <li>نوع هر قلم از روی پسوند و محتوا تشخیص داده می‌شود (<span class="mono ltr">.conf ← WireGuard</span>، <span class="mono ltr">.ovpn ← OpenVPN</span>)</li>
          </ul>
        </div>

        <div class="card-foot">
          <button class="btn primary">⬆️ خواندن فایل و افزودن به انبار</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ==================== تنظیمات ==================== -->
<?php if (can('stock.settings')): ?>
<div class="tab-panel" data-tab-panel="set" data-tab-panel-group="stk">
  <div class="card">
    <div class="card-head tight">
      <div class="card-title">⚙️ تنظیمات انبار ملی</div>
    </div>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="settings">

      <div class="check">
        <label><input type="checkbox" name="stock_enabled" value="1" <?= Stock::enabled() ? 'checked' : '' ?>>
          فعال بودن بخش انبار در ربات و مینی‌اپ</label>
      </div>
      <div class="check">
        <label><input type="checkbox" name="stock_show_empty" value="1" <?= Stock::showEmpty() ? 'checked' : '' ?>>
          نمایش دسته‌های تمام‌شده (با برچسب ناموجود)</label>
      </div>
      <div class="check">
        <label><input type="checkbox" name="stock_rs_enabled" value="1" <?= Stock::rsEnabled() ? 'checked' : '' ?>>
          🏷 نمایش انبار در پنل نمایندگان (خرید با قیمت نماینده)</label>
      </div>

      <div class="form-grid g2 mt3">
        <div class="field">
          <label>عنوان بخش</label>
          <input type="text" name="stock_title" maxlength="40" value="<?= h(Stock::title()) ?>">
        </div>
        <div class="field">
          <label>آیکون بخش</label>
          <input type="text" name="stock_icon" maxlength="16" value="<?= h(Stock::icon()) ?>">
        </div>
        <div class="field">
          <label>آستانهٔ هشدار کمبود موجودی</label>
          <input type="number" name="stock_low_alert" class="numbox" min="0" value="<?= (int)Stock::lowAlert() ?>">
          <div class="hint">وقتی موجودی دسته به این عدد برسد، به مدیران در ربات پیام می‌رود. ۰ = خاموش</div>
        </div>
        <div class="field">
          <label>سقف خرید هر کاربر از هر دسته</label>
          <input type="number" name="stock_per_user" class="numbox" min="0" value="<?= (int)Stock::perUser() ?>">
          <div class="hint">۰ = بدون محدودیت</div>
        </div>
        <div class="field">
          <label>🏷 سقف خرید هر نماینده از هر دسته</label>
          <input type="number" name="stock_rs_per_user" class="numbox" min="0" value="<?= (int)Stock::rsPerUser() ?>">
          <div class="hint">۰ = بدون محدودیت. معمولاً باید بازتر از سقف کاربر عادی باشد</div>
        </div>
      </div>

      <div class="field mt3">
        <label>متن بالای فهرست در ربات</label>
        <textarea name="stock_note" rows="3" maxlength="500"><?= h(Stock::note()) ?></textarea>
      </div>

      <div class="card-foot">
        <button class="btn primary">💾 ذخیرهٔ تنظیمات</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($ec): ?>
<script>document.addEventListener('DOMContentLoaded',function(){
  var a = document.querySelector('[data-tab="cats"]');
  if (a) a.click();
  var f = document.getElementById('catform');
  if (f) setTimeout(function(){ f.scrollIntoView({behavior:'smooth',block:'center'}); }, 150);
});</script>
<?php endif; ?>

<script>
/* ------------------ انبار ملی — تعامل صفحه ------------------ */
(function () {
  /* باز کردن تب خواسته‌شده در آدرس */
  var m = /[?&]tab=([a-z0-9_-]+)/i.exec(location.search);
  if (m && window.vsTab) { setTimeout(function () { try { window.vsTab('stk', m[1]); } catch (e) {} }, 40); }

  /* شمارشگر انتخاب گروهی */
  var all  = document.getElementById('stkAll');
  var cnt  = document.getElementById('stkCnt');
  var bulk = document.getElementById('stkBulk');
  var cks  = [].slice.call(document.querySelectorAll('.stk-ck'));

  function fa(n) {
    return String(n).replace(/[0-9]/g, function (d) { return String.fromCharCode(1776 + (+d)); });
  }
  function sync() {
    var n = cks.filter(function (c) { return c.checked; }).length;
    if (cnt) cnt.textContent = fa(n);
    if (bulk) bulk.classList.toggle('on', n > 0);
    if (all) all.checked = n > 0 && n === cks.length;
    cks.forEach(function (c) {
      var tr = c.closest ? c.closest('tr') : null;
      if (tr) tr.classList.toggle('sel', c.checked);
    });
  }
  cks.forEach(function (c) { c.addEventListener('change', sync); });
  if (all) {
    all.addEventListener('change', function () {
      cks.forEach(function (c) { c.checked = all.checked; });
      sync();
    });
  }
  sync();

  /* درگ و دراپ آپلود */
  var drop = document.getElementById('stkDrop');
  var file = document.getElementById('stkFile');
  var pick = document.getElementById('stkPick');
  if (!drop || !file) return;

  function human(b) {
    if (!b) return '';
    var u = ['B', 'KB', 'MB', 'GB'], i = 0;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return fa(b.toFixed(b < 10 && i > 0 ? 1 : 0)) + ' ' + u[i];
  }
  function show() {
    var f = file.files && file.files[0];
    if (!f) { if (pick) pick.hidden = true; drop.classList.remove('has'); return; }
    var zip = /\.zip$/i.test(f.name);
    if (pick) {
      pick.hidden = false;
      pick.textContent = (zip ? '🗜 ' : '📄 ') + f.name + ' · ' + human(f.size);
    }
    drop.classList.add('has');
  }
  file.addEventListener('change', show);

  ['dragenter', 'dragover'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); });
  });
  drop.addEventListener('drop', function (e) {
    var dt = e.dataTransfer;
    if (!dt || !dt.files || !dt.files.length) return;
    file.files = dt.files;
    show();
  });
})();
</script>
