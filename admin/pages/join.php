<?php
/**
 * SR-BOT — جوین اجباری «عضویت اجباری در کانال‌ها»
 * بخش مستقل: چند کانال، محدودهٔ بررسی، متن دلخواه و تست دسترسی ربات
 */

if (!canAny(['settings.view', 'settings.bot'])) { echo denyBox('این بخش برای شما فعال نیست.'); return; }
$canEdit = can('settings.bot');

$act = (string)($_POST['act'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok() && $canEdit) {

    /* ---------- ذخیره تنظیمات ---------- */
    if ($act === 'fjsave') {
        DB::setSetting('fj_enabled', pchk('fj_enabled'));

        $scope = ptxt('fj_scope');
        DB::setSetting('fj_scope', in_array($scope, ['all', 'start', 'buy'], true) ? $scope : 'all');
        DB::setSetting('fj_text', ptxt('fj_text', 1000));
        DB::setSetting('fj_cache_min', (string)max(0, min(60, pint('fj_cache_min', 3))));

        $ids    = is_array($_POST['fj_id'] ?? null) ? $_POST['fj_id'] : [];
        $titles = is_array($_POST['fj_title'] ?? null) ? $_POST['fj_title'] : [];
        $links  = is_array($_POST['fj_link'] ?? null) ? $_POST['fj_link'] : [];
        $ons    = is_array($_POST['fj_on'] ?? null) ? $_POST['fj_on'] : [];

        $list = [];
        for ($i = 0; $i < 8; $i++) {
            $id = trim(en_num((string)($ids[$i] ?? '')));
            if ($id === '') continue;
            /* نرمال‌سازی: لینک t.me یا یوزرنیم خام هم پذیرفته می‌شود */
            $id = preg_replace('~^https?://t\.me/~i', '@', $id);
            $id = str_replace('@@', '@', (string)$id);
            if (!str_starts_with($id, '@') && !str_starts_with($id, '-') && !preg_match('/^\d+$/', $id)) $id = '@' . $id;
            $title = mb_substr(trim((string)($titles[$i] ?? '')), 0, 60);
            $link  = trim((string)($links[$i] ?? ''));
            if ($link !== '' && !preg_match('~^https?://~i', $link)) $link = 'https://' . $link;
            $list[] = [
                'on'    => !empty($ons[$i]) ? 1 : 0,
                'id'    => $id,
                'title' => $title,
                'link'  => mb_substr($link, 0, 200),
            ];
        }
        DB::setSetting('fj_channels', jenc($list));

        DB::loadSettings(true);
        $nOn = count(array_filter($list, static fn($r) => (int)$r['on'] === 1));
        flash('ok', '📢 تنظیمات جوین اجباری ذخیره شد — ' . fa_num($nOn) . ' کانال فعال.');
        back('join');
    }

    /* ---------- تست دسترسی ربات به کانال‌ها ---------- */
    if ($act === 'fjtest') {
        $rows = jdec((string)DB::setting('fj_channels', ''), []);
        $me   = Tg::getMe();
        $bid  = (int)($me['result']['id'] ?? 0);
        if ($bid <= 0) {
            flash('err', 'اتصال به تلگرام برقرار نشد؛ توکن ربات را بررسی کنید.');
            back('join');
        }
        $lines = [];
        $n     = 0;
        foreach ((array)$rows as $r) {
            if (!is_array($r) || (int)($r['on'] ?? 0) !== 1) continue;
            $id = trim((string)($r['id'] ?? ''));
            if ($id === '') continue;
            $n++;
            $m = Tg::api('getChatMember', ['chat_id' => $id, 'user_id' => $bid]);
            if (empty($m['ok'])) {
                $lines[] = '⛔️ <span class="mono">' . h($id) . '</span> — ربات به این کانال دسترسی ندارد؛ ربات را ادمین کانال کنید.';
                continue;
            }
            $st = (string)($m['result']['status'] ?? '');
            $lines[] = in_array($st, ['administrator', 'creator'], true)
                ? '✅ <span class="mono">' . h($id) . '</span> — ربات ادمین است و می‌تواند عضویت را بررسی کند.'
                : '⚠️ <span class="mono">' . h($id) . '</span> — ربات عضو است ولی ادمین نیست؛ برای بررسی عضویت باید ادمین شود.';
        }
        if ($n === 0) flash('err', 'هیچ کانال فعالی برای تست وجود ندارد؛ ابتدا کانال‌ها را ذخیره کنید.');
        else flash('ok', '🧪 نتیجهٔ تست:<br>' . implode('<br>', $lines));
        back('join');
    }
}

/* ---------- داده‌های نمایش ---------- */
$SET = fn(string $k, $d = '') => DB::setting($k, $d);

$chs = jdec((string)$SET('fj_channels', ''), []);
if (!is_array($chs)) $chs = [];
$chs = array_values($chs);

/* انتقال نرم از تنظیم قدیمی «کانال اجباری» در تنظیمات ربات */
if (!$chs && trim((string)$SET('force_channel', '')) !== '') {
    $old = trim((string)$SET('force_channel', ''));
    $chs = [[
        'on'    => 1,
        'id'    => str_starts_with($old, '@') || str_starts_with($old, '-') ? $old : '@' . $old,
        'title' => '',
        'link'  => '',
    ]];
}

$scopeNow = (string)$SET('fj_scope', 'all');
$fjOnRaw  = (string)$SET('fj_enabled', '');
/* اگر بخش جدید هنوز ذخیره نشده ولی کانال قدیمی فعال است، روشن نمایش داده می‌شود */
$isOn = $fjOnRaw === '' ? ($chs !== []) : ((int)$fjOnRaw === 1);
?>

<div class="card">
  <div class="lg">📢 جوین اجباری — عضویت اجباری در کانال‌ها</div>
  <div class="fs-hint">
    تا وقتی کاربر عضو کانال‌های فعال زیر نشود، ربات بر اساس «محدودهٔ بررسی» به او سرویس نمی‌دهد.
    پیام عضویت همراه دکمهٔ هر کانال و دکمهٔ «✅ عضو شدم — بررسی کن» ارسال می‌شود. مدیران ربات همیشه معاف هستند.
    برای اینکه ربات بتواند عضویت را بررسی کند باید <b>ادمین همان کانال</b> باشد.
    کانال عمومی: <span class="mono">@username</span> — کانال خصوصی: آیدی عددی مانند <span class="mono">-1001234567890</span> به‌همراه «لینک عضویت».
  </div>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="fjsave">

    <div class="form-grid g2">
      <div class="field"><label>وضعیت</label>
        <label class="check" style="margin-top:8px"><input type="checkbox" name="fj_enabled" value="1" <?= $isOn ? 'checked' : '' ?>>
          <span>📢 جوین اجباری فعال باشد</span></label></div>
      <div class="field"><label>محدودهٔ بررسی عضویت</label>
        <select name="fj_scope">
          <option value="all" <?= $scopeNow === 'all' ? 'selected' : '' ?>>سخت‌گیرانه — همهٔ پیام‌ها و دکمه‌ها (پیشنهادی)</option>
          <option value="start" <?= $scopeNow === 'start' ? 'selected' : '' ?>>فقط هنگام /start</option>
          <option value="buy" <?= $scopeNow === 'buy' ? 'selected' : '' ?>>فقط هنگام خرید و اکانت تست</option>
        </select></div>
    </div>

    <div class="form-grid g2">
      <div class="field"><label>⚡️ کش نتیجهٔ بررسی عضویت (دقیقه)</label>
        <input class="mono" type="number" name="fj_cache_min" min="0" max="60" value="<?= (int)$SET('fj_cache_min', 3) ?>">
        <div class="hint">عضویت تاییدشدهٔ هر کاربر تا این مدت دوباره از تلگرام پرسیده نمی‌شود — در حالت «همهٔ پیام‌ها» ربات را محسوس سریع‌تر می‌کند. ۰ = بدون کش. دکمهٔ «عضو شدم» همیشه بررسی واقعی می‌کند.</div></div>
      <div class="field"><label>📊 آمار جوین اجباری</label>
        <div class="hint" style="margin-top:12px">📥 نمایش دیوار عضویت: <b><?= fa_num((string)(int)$SET('fj_stat_shown', 0)) ?></b> بار<br>✅ عضویت تاییدشده با دکمه: <b><?= fa_num((string)(int)$SET('fj_stat_joined', 0)) ?></b> بار</div></div>
    </div>

    <div class="field"><label>متن پیام عضویت (خالی = متن پیش‌فرض)</label>
      <textarea name="fj_text" rows="3" placeholder="🔒 برای استفاده از ربات ابتدا در کانال‌های زیر عضو شوید…"><?= h((string)$SET('fj_text', '')) ?></textarea></div>

    <div class="lg" style="margin-top:14px">📋 کانال‌ها (تا ۸ مورد)</div>
    <div style="overflow:auto">
      <table style="width:100%;border-collapse:collapse;margin-top:8px;min-width:680px">
        <tr style="text-align:right">
          <th style="padding:6px 8px">فعال</th>
          <th style="padding:6px 8px">آیدی کانال</th>
          <th style="padding:6px 8px">عنوان دکمه</th>
          <th style="padding:6px 8px">لینک عضویت (اختیاری)</th>
        </tr>
        <?php for ($i = 0; $i < 8; $i++): $r = is_array($chs[$i] ?? null) ? $chs[$i] : []; ?>
        <tr>
          <td style="padding:6px 8px;text-align:center"><input type="checkbox" name="fj_on[<?= $i ?>]" value="1" <?= (int)($r['on'] ?? 0) === 1 ? 'checked' : '' ?>></td>
          <td style="padding:6px 8px"><input class="mono" type="text" name="fj_id[<?= $i ?>]" value="<?= h((string)($r['id'] ?? '')) ?>" placeholder="@channel یا -100…"></td>
          <td style="padding:6px 8px"><input type="text" name="fj_title[<?= $i ?>]" value="<?= h((string)($r['title'] ?? '')) ?>" placeholder="مثلاً: کانال اطلاع‌رسانی"></td>
          <td style="padding:6px 8px"><input class="mono" type="text" name="fj_link[<?= $i ?>]" value="<?= h((string)($r['link'] ?? '')) ?>" placeholder="برای کانال خصوصی: https://t.me/+…"></td>
        </tr>
        <?php endfor; ?>
      </table>
    </div>
    <div class="fs-hint">«عنوان دکمه» روی دکمهٔ شیشه‌ای نمایش داده می‌شود؛ خالی = آیدی کانال. «لینک عضویت» برای کانال‌های عمومی به‌طور خودکار ساخته می‌شود و فقط برای کانال خصوصی لازم است.</div>

    <?php if ($canEdit): ?>
    <div style="margin-top:12px"><button class="btn" type="submit">💾 ذخیره تنظیمات</button></div>
    <?php endif; ?>
  </form>

  <?php if ($canEdit): ?>
  <form method="post" style="margin-top:10px">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="fjtest">
    <button class="btn" type="submit">🧪 تست دسترسی ربات به کانال‌ها</button>
  </form>
  <?php endif; ?>
</div>
