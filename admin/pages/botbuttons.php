<?php
if (!can('botbuttons.view')) { echo denyBox('این بخش برای شما فعال نیست.'); return; }
if (!class_exists('Btn')) { echo denyBox('ماژول دکمه‌ها (Btn) پیدا نشد؛ فایل‌های به‌روزرسانی را کامل آپلود کنید.'); return; }
/**
 * دکمه‌های ربات — fixed80 (بازنویسی کامل)
 * تب‌ها: چیدمان (درگ‌اند‌دراپ + پیش‌نمایش) · فهرست (فیلتر و عملیات گروهی) · اسکن خودکار · تنظیمات
 * همهٔ عملیات سمت سرور و بدون وابستگی به جاوااسکریپت انجام می‌شود؛ JS فقط برای درگ‌اند‌دراپ، فیلتر و فرم ویرایش است.
 */

$canEd = can('botbuttons.edit');
$tab   = (string)($_GET['tab'] ?? 'layout');
if (!in_array($tab, ['layout', 'list', 'scan', 'settings'], true)) $tab = 'layout';
$editId = trim((string)($_GET['e'] ?? ''));
$isNew  = isset($_GET['new']);
$rtTab  = static fn(string $t = ''): array => ['tab' => $t !== '' ? $t : (string)($_POST['rt'] ?? 'layout')];

/* ==================== عملیات ==================== */
$act = (string)($_POST['act'] ?? '');
if ($act !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('err', 'نشست منقضی شده است. دوباره تلاش کنید.'); back('botbuttons', $rtTab()); }
    need('botbuttons.edit', 'botbuttons');
    $rt = $rtTab();

    if ($act === 'mode') {
        $m = ptxt('btn_mode');
        DB::setSetting('btn_mode', in_array($m, ['reply', 'inline', 'both'], true) ? $m : 'reply');
        DB::setSetting('btn_per_row', (string)max(1, min(4, pint('btn_per_row', 2))));
        DB::setSetting('btn_max_per_row', (string)max(1, min(4, pint('btn_max_per_row', 3))));
        $mu = trim(ptxt('miniapp_url', 500));
        if ($mu !== '' && !preg_match('~^https://~i', $mu)) { flash('err', '⛔️ آدرس مینی‌اپ باید با https:// شروع شود.'); back('botbuttons', $rt); }
        DB::setSetting('miniapp_url', $mu);
        DB::loadSettings(true);
        flash('ok', '✅ تنظیمات نمایش دکمه‌ها ذخیره شد.');
        back('botbuttons', $rt);
    }
    if ($act === 'layout') {
        $r = Btn::saveLayout(ptxt('layout', 40000));
        if (!empty($r['ok'])) flash('ok', '✅ چیدمان ذخیره شد (' . fa_num((string)(int)($r['rows'] ?? 0)) . ' ردیف).');
        else flash('err', '⛔️ ' . (string)($r['message'] ?? 'ذخیره نشد.'));
        back('botbuttons', $rt);
    }
    if ($act === 'save') {
        $kind = ptxt('kind');
        $r = Btn::upsert([
            'id'       => ptxt('id'),
            'kind'     => $kind,
            'key'      => $kind === 'menu' ? ptxt('mkey') : ptxt('key'),
            'label'    => ptxt('label', 80),
            'icon'     => ptxt('icon', 12),
            'color'    => ptxt('color'),
            'style'    => ptxt('style'),
            'audience' => ptxt('audience'),
            'enabled'  => pchk('enabled'),
            'width'    => ptxt('width'),
            'url'      => ptxt('url', 600),
            'text'     => ptxt('text', 3500),
            'menu'     => ptxt('menu'),
            'desc'     => ptxt('desc', 120),
            'copy'     => ptxt('copy', 300),
            'hits'     => pint('hits'),
        ]);
        if (!empty($r['ok'])) { flash('ok', '💾 ' . (string)($r['message'] ?? 'ذخیره شد.')); back('botbuttons', $rt); }
        flash('err', '⛔️ ' . (string)($r['message'] ?? 'ذخیره نشد.'));
        back('botbuttons', $rt + ['e' => ptxt('id')]);
    }
    if ($act === 'del')    { Btn::remove(ptxt('id')) ? flash('ok', '🗑 دکمه حذف شد.') : flash('err', '⛔️ دکمه پیدا نشد.'); back('botbuttons', $rt); }
    if ($act === 'toggle') { Btn::toggle(ptxt('id')); flash('ok', '🔁 وضعیت دکمه تغییر کرد.'); back('botbuttons', $rt); }
    if ($act === 'move')   { Btn::move(ptxt('id'), pint('dir') < 0 ? -1 : 1); back('botbuttons', $rt); }
    if ($act === 'edge')   { Btn::moveEdge(ptxt('id'), ptxt('to') === 'top'); back('botbuttons', $rt); }
    if ($act === 'dup') {
        $nid = Btn::duplicate(ptxt('id'));
        $nid ? flash('ok', '📄 یک کپی خاموش ساخته شد؛ آن را ویرایش کنید.') : flash('err', '⛔️ کپی انجام نشد.');
        back('botbuttons', $nid ? $rt + ['e' => $nid] : $rt);
    }
    if ($act === 'bulk') {
        $ids = array_values(array_filter(array_map('strval', (array)($_POST['ids'] ?? []))));
        $do  = ptxt('do');
        if ($ids === []) { flash('err', '⛔️ هیچ دکمه‌ای انتخاب نشده.'); back('botbuttons', $rt); }
        $n = 0;
        if ($do === 'on' || $do === 'off') $n = (int)Btn::toggleMany($ids, $do === 'on');
        elseif ($do === 'del') { foreach ($ids as $id) { if (Btn::remove($id)) $n++; } }
        elseif (strncmp($do, 'w:', 2) === 0) { foreach ($ids as $id) { if (Btn::setWidth($id, substr($do, 2))) $n++; } }
        elseif (strncmp($do, 'c:', 2) === 0) { foreach ($ids as $id) { if (Btn::setColor($id, substr($do, 2))) $n++; } }
        elseif (strncmp($do, 'm:', 2) === 0) { foreach ($ids as $id) { if (Btn::setMenu($id, substr($do, 2))) $n++; } }
        elseif (strncmp($do, 'a:', 2) === 0) {
            $aud = substr($do, 2);
            if (isset(Btn::AUDIENCES[$aud])) {
                $rows = Btn::all();
                foreach ($rows as $i => $b) { if (in_array((string)$b['id'], $ids, true)) { $rows[$i]['audience'] = $aud; $n++; } }
                Btn::save($rows);
            }
        }
        flash('ok', '✅ ' . fa_num((string)$n) . ' دکمه به‌روز شد.');
        back('botbuttons', $rt);
    }
    if ($act === 'sync') {
        $n = (int)Btn::syncBuiltins();
        flash('ok', $n > 0 ? '➕ ' . fa_num((string)$n) . ' دکمهٔ تازه (خاموش) به فهرست اضافه شد.' : '✅ همهٔ بخش‌های ربات در فهرست هستند.');
        back('botbuttons', $rt);
    }
    if ($act === 'seed') {
        $mn = ptxt('menu');
        $n  = (int)Btn::seedMenus($mn !== '' ? $mn : null);
        flash('ok', $n > 0 ? '✨ ' . fa_num((string)$n) . ' دکمهٔ پیشنهادی ��اخته شد.' : 'همهٔ دکمه‌های پیشنهادی از قبل وجود دارند.');
        back('botbuttons', $rt);
    }
    if ($act === 'fix') {
        $rs = Btn::autoFix();
        $n  = (int)($rs['fixed'] ?? 0);
        $lg = array_slice((array)($rs['log'] ?? []), 0, 6);
        flash('ok', $n > 0 ? '🔧 ' . fa_num((string)$n) . ' مورد اصلاح شد' . ($lg !== [] ? ': ' . implode(' · ', $lg) : '.') : '✅ چیزی برای اصلاح پیدا نشد.');
        back('botbuttons', $rt);
    }
    if ($act === 'scan') {
        $rs = Btn::autoScan(true);
        flash(!empty($rs['ok']) ? 'ok' : 'err', (string)($rs['message'] ?? 'اسکن انجام شد.'));
        back('botbuttons', $rtTab('scan'));
    }
    if ($act === 'autoscan') {
        DB::setSetting('btn_autoscan', pchk('on') ? '1' : '0');
        DB::loadSettings(true);
        flash('ok', pchk('on') ? '🟢 اسکن خودکار روشن شد (روزانه با کران و بعد از هر به‌روزرسانی).' : '⚪️ اسکن خودکار خاموش شد.');
        back('botbuttons', $rtTab('scan'));
    }
    if ($act === 'discmeta') {
        $ok = Btn::setDiscovered(ptxt('key'), [ptxt('icon', 8), ptxt('label', 60), ptxt('audience'), ptxt('group', 40)]);
        $ok ? flash('ok', '💾 مشخصات بخش کشف‌شده ذخیره شد.') : flash('err', '⛔️ ذخیره نشد.');
        back('botbuttons', $rtTab('scan'));
    }
    if ($act === 'discdel') {
        Btn::forgetDiscovered(ptxt('key')) ? flash('ok', '🗑 بخش کشف‌شده و دکمه‌های آن حذف شد.') : flash('err', '⛔️ پیدا نشد.');
        back('botbuttons', $rtTab('scan'));
    }
    if ($act === 'import') {
        $json = '';
        $tmp  = (string)($_FILES['jfile']['tmp_name'] ?? '');
        $siz  = (int)($_FILES['jfile']['size'] ?? 0);
        if ($tmp !== '' && $siz > 0 && $siz <= 2097152 && is_uploaded_file($tmp)) $json = (string)@file_get_contents($tmp);
        if (trim($json) === '') $json = (string)($_POST['json'] ?? '');
        if (trim($json) === '') { flash('err', '⛔️ فایل یا متن JSON را وارد کنید.'); back('botbuttons', $rt); }
        $rs = Btn::importJson($json);
        if (!empty($rs['ok'])) flash('ok', '📥 ' . (string)($rs['message'] ?? 'بازگردانی شد.') . ((int)($rs['count'] ?? 0) > 0 ? ' (' . fa_num((string)(int)$rs['count']) . ' دکمه)' : ''));
        else flash('err', '⛔️ ' . (string)($rs['message'] ?? 'بازگردانی انجام نشد.'));
        back('botbuttons', $rt);
    }
    if ($act === 'export') {
        $out = Btn::exportJson();
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="bot-buttons-' . date('Ymd-His') . '.json"');
        header('Content-Length: ' . strlen($out));
        echo $out;
        exit;
    }
    if ($act === 'reset') { Btn::reset(); flash('ok', '♻️ دکمه‌ها به حالت پیش‌فرض برگشت.'); back('botbuttons', $rt); }
    flash('err', 'عملیات ناشناخته.');
    back('botbuttons', $rt);
}

/* ==================== داده‌ها ==================== */
/* اسکن خودکار سبک: فقط وقتی کد ربات تغییر کرده باشد (امضای فایل) */
try { Btn::autoScan(); } catch (Throwable $e) { }
try { Btn::syncBuiltins(); } catch (Throwable $e) { }

$all    = Btn::all();
$stats  = Btn::stats();
$mode   = Btn::mode();
$perRow = (int)Btn::perRow();
$maxRow = (int)Btn::maxPerRow();
$appUrl = Btn::miniappUrl();
$appRaw = (string)DB::setting('miniapp_url', '');
$mCnt   = Btn::menuCounts();
$sdMiss = Btn::seedMissing();
$health = Btn::health();
$hErr   = 0; $hWarn = 0;
foreach ($health as $hr) { if ((string)($hr['level'] ?? '') === 'err') $hErr++; else $hWarn++; }
$scan   = Btn::scanReport();
$disc   = Btn::discovered();
$allB   = Btn::builtinAll();
$byId   = [];
foreach ($all as $b) $byId[(string)$b['id']] = $b;

$MENUS = Btn::MENUS;
$menuShort = static function (string $m) use ($MENUS): string {
    $t = (string)($MENUS[$m] ?? $m);
    $t = preg_replace('/^(\S+)\s+زیرمنوی\s+/u', '$1 ', $t);
    return (string)preg_replace('/\s*\(.*\)$/u', '', (string)$t);
};
$kindShort = static fn(string $k): string => (string)preg_replace('/^\S+\s+/u', '', (string)(Btn::KINDS[$k] ?? $k));
$audShort  = static fn(string $a): string => (string)(Btn::AUDIENCES[$a] ?? $a);
$capOf = static function (array $b) use ($perRow): int {
    switch ((string)($b['width'] ?? 'auto')) { case 'full': return 1; case 'half': return 2; case 'third': return 3; case 'quarter': return 4; }
    return max(1, min(4, $perRow));
};
/* بسته‌بندی ردیفی (همان منطق ربات) برای هر منو */
$packRows = static function (array $items) use ($capOf): array {
    $rows = []; $cur = []; $cap = 0; $rw = 0;
    foreach ($items as $b) {
        $c = $capOf($b); $r = max(0, (int)($b['row'] ?? 0));
        if ($r > 0) {
            if ($cur !== [] && $rw !== $r) { $rows[] = $cur; $cur = []; $cap = 0; }
            $rw = $r; $cur[] = $b;
            if (count($cur) >= 4) { $rows[] = $cur; $cur = []; $cap = 0; $rw = 0; }
            continue;
        }
        if ($cur !== [] && ($rw > 0 || $c !== $cap)) { $rows[] = $cur; $cur = []; }
        $rw = 0; $cap = $c; $cur[] = $b;
        if (count($cur) >= $cap) { $rows[] = $cur; $cur = []; $cap = 0; }
    }
    if ($cur !== []) $rows[] = $cur;
    return $rows;
};
$perMenu = [];
foreach (array_keys($MENUS) as $m) $perMenu[$m] = [];
foreach ($all as $b) { $m = Btn::menuOf($b); if (!isset($perMenu[$m])) $perMenu[$m] = []; $perMenu[$m][] = $b; }

$tabUrl = static fn(string $t): string => 'index.php?p=botbuttons&tab=' . $t;
$editing = $editId !== '' && isset($byId[$editId]) ? $byId[$editId] : null;
$showEditor = $isNew || $editing !== null;
$curMenu = (string)($_GET['m'] ?? 'main');
if (!isset($MENUS[$curMenu])) $curMenu = 'main';

/* گروه‌بندی کلیدهای درون‌ساخت برای فرم ویرایش */
$keyGroups = [];
foreach ($allB as $k => $meta) { $keyGroups[(string)($meta[3] ?? 'دیگر')][(string)$k] = $meta; }
$handlerSet = array_fill_keys(array_map('strval', (array)$scan['handlers']), true);
?>
<style>
.bb-hero{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:12px}
.bb-hero .t{font-size:18px;font-weight:800;display:flex;align-items:center;gap:8px}
.bb-hero .t small{font-size:12px;color:var(--muted);font-weight:500}
.bb-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin-bottom:12px}
.bb-kpi{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:10px 12px;position:relative;overflow:hidden}
.bb-kpi::before{content:"";position:absolute;inset-inline:0;top:0;height:3px;background:var(--kc,var(--accent))}
.bb-kpi .l{font-size:11.5px;color:var(--muted);font-weight:600}
.bb-kpi .v{font-size:19px;font-weight:800;margin-top:4px;font-family:var(--font-num)}
.bb-tabs{margin-bottom:12px}
.bb-tabs .segment a{white-space:nowrap}
.bb-wrap{display:grid;grid-template-columns:minmax(0,1fr);gap:12px}
@media(min-width:1100px){.bb-wrap.with-ed{grid-template-columns:minmax(0,1fr) 400px;align-items:start}}
/* چیدمان */
.bb-menu-nav{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}
.bb-menu-nav a{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid var(--border);background:var(--surface-2);font-size:12px;font-weight:600;color:var(--text-dim);text-decoration:none}
.bb-menu-nav a.on{background:var(--accent);color:#fff;border-color:transparent}
.bb-menu-nav a b{font-family:var(--font-num);font-weight:700}
.bb-menu-nav a.on b{color:#fff}
.bb-board{background:var(--surface-2);border:1px solid var(--border);border-radius:16px;padding:12px}
.bb-board-h{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}
.bb-board-h .n{font-weight:800;font-size:13.5px}
.bb-board-h .s{font-size:11.5px;color:var(--muted)}
.bb-rows{display:flex;flex-direction:column;gap:8px;min-height:40px}
.bb-row{display:flex;gap:6px;min-height:46px;padding:5px;border:1px dashed var(--border);border-radius:12px;background:var(--surface);transition:border-color .15s,background .15s}
.bb-row.over{border-color:var(--accent);background:var(--accent-soft)}
.bb-row.new{justify-content:center;align-items:center;color:var(--muted);font-size:12px;border-style:dotted;min-height:38px}
.bb-btn{flex:1 1 0;min-width:0;display:flex;align-items:center;gap:6px;padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--surface-2);cursor:grab;user-select:none;position:relative;font-size:12.5px;font-weight:600}
.bb-btn:active{cursor:grabbing}
.bb-btn.drag{opacity:.45}
.bb-btn.off{opacity:.55;border-style:dashed}
.bb-btn .ic{font-size:14px;flex:none}
.bb-btn .lb{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bb-btn .tg{display:inline-flex;gap:3px;flex:none}
.bb-btn .tg span{font-size:9.5px;padding:1px 5px;border-radius:999px;background:var(--surface-3);color:var(--muted)}
.bb-btn .tg span.a-admin{background:var(--orange-soft);color:var(--orange)}
.bb-btn .tg span.a-reseller{background:var(--accent-soft);color:var(--accent-text)}
.bb-btn .tg span.a-user{background:var(--green-soft);color:var(--green)}
.bb-btn .ed{flex:none;text-decoration:none;font-size:12px;opacity:.7;padding:2px 4px;border-radius:6px}
.bb-btn .ed:hover{opacity:1;background:var(--surface-3)}
.bb-btn.sel{outline:2px solid var(--accent);outline-offset:1px}
.bb-board-f{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;margin-top:10px}
.bb-board-f .hint{margin:0}
/* پیش‌نمایش */
.bb-phone{background:linear-gradient(180deg,#0f172a,#111827);border:1px solid var(--border);border-radius:22px;padding:14px 12px 12px;color:#e5e7eb;max-width:380px;margin:0 auto}
.bb-phone .hd{font-size:11.5px;color:#9ca3af;display:flex;justify-content:space-between;margin-bottom:8px}
.bb-phone .msg{background:#1f2937;border-radius:12px;padding:8px 10px;font-size:12px;margin-bottom:10px;max-width:85%}
.bb-phone .kb{display:flex;flex-direction:column;gap:5px}
.bb-phone .kr{display:flex;gap:5px}
.bb-phone .kb.inline .kr span{background:#1e3a8a;color:#dbeafe;border:1px solid #1d4ed8}
.bb-phone .kr span{flex:1 1 0;min-width:0;text-align:center;padding:9px 6px;border-radius:8px;background:#2d3748;font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bb-phone .kr span.dim{opacity:.4;border:1px dashed #6b7280}
/* فهرست */
.bb-filters{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-bottom:10px}
.bb-filters input,.bb-filters select{width:100%}
.bb-table{width:100%;border-collapse:separate;border-spacing:0 6px}
.bb-table th{font-size:11.5px;color:var(--muted);font-weight:600;text-align:start;padding:0 8px}
.bb-table td{background:var(--surface-2);padding:8px;font-size:12.5px;vertical-align:middle;border-top:1px solid var(--border);border-bottom:1px solid var(--border)}
.bb-table td:first-child{border-inline-start:1px solid var(--border);border-start-start-radius:12px;border-end-start-radius:12px}
.bb-table td:last-child{border-inline-end:1px solid var(--border);border-start-end-radius:12px;border-end-end-radius:12px}
.bb-table tr.off td{opacity:.6}
.bb-table .nm{font-weight:700;display:flex;align-items:center;gap:6px}
.bb-table .nm .ic{font-size:15px}
.bb-table .k{font-size:11px;color:var(--muted);font-family:var(--font-mono);direction:ltr;display:inline-block}
.bb-acts{display:flex;flex-wrap:wrap;gap:4px;justify-content:flex-end}
.bb-acts form{display:inline}
.bb-acts .icon-btn,.bb-acts a.icon-btn{width:30px;height:30px;font-size:13px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none}
.bb-bulk{display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:8px 10px;margin-top:8px}
.bb-bulk select{min-width:180px}
.bb-bulk .cnt{font-size:12px;color:var(--muted)}
/* اسکن */
.bb-scan-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-bottom:12px}
.bb-h-list{display:flex;flex-direction:column;gap:6px}
.bb-h{display:flex;gap:8px;align-items:flex-start;padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--surface-2);font-size:12.5px}
.bb-h.err{border-color:var(--red);background:var(--red-soft)}
.bb-h.warn{border-color:var(--orange);background:var(--orange-soft)}
.bb-h .who{font-weight:700;flex:none}
.bb-h a{margin-inline-start:auto;flex:none;font-size:11.5px}
.bb-keys{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:6px}
.bb-key{display:flex;align-items:center;gap:6px;padding:7px 9px;border-radius:10px;border:1px solid var(--border);background:var(--surface-2);font-size:12px}
.bb-key code{font-family:var(--font-mono);direction:ltr;font-size:11px;color:var(--accent-text)}
.bb-key .st{margin-inline-start:auto;font-size:10.5px;padding:1px 6px;border-radius:999px;background:var(--surface-3);color:var(--muted);flex:none}
.bb-key .st.ok{background:var(--green-soft);color:var(--green)}
.bb-key .st.miss{background:var(--orange-soft);color:var(--orange)}
.bb-key .st.new{background:var(--accent-soft);color:var(--accent-text)}
.bb-key .st.dead{background:var(--red-soft);color:var(--red)}
.bb-disc{display:grid;grid-template-columns:70px minmax(0,1fr) 130px 130px auto auto;gap:6px;align-items:center;padding:6px 0;border-bottom:1px solid var(--border)}
/* ویرایشگر */
.bb-editor{position:sticky;top:calc(var(--top-h,56px) + 12px)}
.bb-editor .card-head{align-items:center}
.bb-editor .row2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.bb-editor .row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
.bb-editor .kf{display:none}
.bb-editor .kf.on{display:block}
.bb-editor .lblprev{margin-top:8px;padding:9px 10px;border-radius:10px;background:#2d3748;color:#f3f4f6;text-align:center;font-weight:600;font-size:13px}
.bb-sw{display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:12.5px}
.bb-empty{padding:14px;text-align:center;color:var(--muted);font-size:12.5px;border:1px dashed var(--border);border-radius:12px}
@media(max-width:1000px){.bb-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.bb-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.bb-scan-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.bb-disc{grid-template-columns:1fr 1fr}}
@media(max-width:640px){
  .bb-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
  .bb-filters{grid-template-columns:1fr}
  .bb-table thead{display:none}
  .bb-table tr{display:grid;grid-template-columns:auto 1fr;gap:4px 8px;padding:8px;background:var(--surface-2);border:1px solid var(--border);border-radius:12px;margin-bottom:8px}
  .bb-table td{display:block;background:transparent;border:0!important;padding:2px 0;border-radius:0!important}
  .bb-table td.c-chk{grid-row:1/4}
  .bb-table td.c-acts{grid-column:1/-1}
  .bb-acts{justify-content:flex-start}
  .bb-editor{position:static}
  .bb-editor .row3{grid-template-columns:1fr 1fr}
  .bb-btn .tg{display:none}
  .bb-disc{grid-template-columns:1fr}
}
</style>

<div class="bb-hero">
  <div class="t">🎛 دکمه‌های ربات <small>چیدمان منوها، زیرمنوها و دکمه‌های دلخواه — بدون دست زدن به کد</small></div>
  <div style="display:flex;gap:6px;flex-wrap:wrap">
    <?php if ($canEd): ?>
      <a class="btn btn-primary btn-sm" href="<?= $tabUrl($tab) ?>&new=1">➕ دکمهٔ جدید</a>
      <a class="btn btn-ghost btn-sm" href="<?= $tabUrl('scan') ?>">🔎 اسکن خودکار<?= (array)$scan['new'] !== [] || (array)$scan['not_in_list'] !== [] ? ' <span class="badge b-orange">' . fa_num((string)(count((array)$scan['new']) + count((array)$scan['not_in_list']))) . '</span>' : '' ?></a>
    <?php endif; ?>
  </div>
</div>

<div class="bb-kpis">
  <div class="bb-kpi" style="--kc:var(--accent)"><div class="l">همهٔ دکمه‌ها</div><div class="v"><?= fa_num((string)(int)$stats['total']) ?></div></div>
  <div class="bb-kpi" style="--kc:var(--green)"><div class="l">روشن</div><div class="v"><?= fa_num((string)(int)$stats['on']) ?></div></div>
  <div class="bb-kpi" style="--kc:var(--muted)"><div class="l">خاموش</div><div class="v"><?= fa_num((string)(int)$stats['off']) ?></div></div>
  <div class="bb-kpi" style="--kc:var(--cyan,var(--accent))"><div class="l">دکمهٔ سفارشی</div><div class="v"><?= fa_num((string)(int)$stats['custom']) ?></div></div>
  <div class="bb-kpi" style="--kc:<?= $hErr > 0 ? 'var(--red)' : ($hWarn > 0 ? 'var(--orange)' : 'var(--green)') ?>"><div class="l">مشکلات چیدمان</div><div class="v"><?= fa_num((string)count($health)) ?></div></div>
  <div class="bb-kpi" style="--kc:var(--orange)"><div class="l">بخش‌های ربات (اسکن)</div><div class="v"><?= fa_num((string)count((array)$scan['handlers'])) ?></div></div>
</div>

<div class="bb-tabs"><div class="segment">
  <a href="<?= $tabUrl('layout') ?>" class="<?= $tab === 'layout' ? 'on' : '' ?>">🧩 چیدمان و پیش‌نمایش</a>
  <a href="<?= $tabUrl('list') ?>" class="<?= $tab === 'list' ? 'on' : '' ?>">📋 فهرست دکمه‌ها</a>
  <a href="<?= $tabUrl('scan') ?>" class="<?= $tab === 'scan' ? 'on' : '' ?>">🔎 اسکن خودکار و سلامت<?= count($health) > 0 ? ' <span class="badge ' . ($hErr > 0 ? 'b-red' : 'b-orange') . '">' . fa_num((string)count($health)) . '</span>' : '' ?></a>
  <a href="<?= $tabUrl('settings') ?>" class="<?= $tab === 'settings' ? 'on' : '' ?>">⚙️ تنظیمات و پشتیبان</a>
</div></div>

<div class="bb-wrap <?= $showEditor ? 'with-ed' : '' ?>">
<div class="bb-main">

<?php /* ==================== تب چیدمان ==================== */ if ($tab === 'layout'): ?>
  <div class="bb-menu-nav">
    <?php foreach ($MENUS as $mk => $mt): $c = $mCnt[$mk] ?? ['all' => 0, 'on' => 0]; ?>
      <a href="<?= $tabUrl('layout') ?>&m=<?= h($mk) ?>" class="<?= $curMenu === $mk ? 'on' : '' ?>"><?= h($menuShort($mk)) ?> <b><?= fa_num((string)(int)$c['on']) ?>/<?= fa_num((string)(int)$c['all']) ?></b></a>
    <?php endforeach; ?>
  </div>

  <div class="grid g2" style="align-items:start">
    <div class="card compact">
      <div class="card-head tight"><div><div class="card-title">🧩 چیدمان <?= h($menuShort($curMenu)) ?></div>
        <div class="card-sub">دکمه‌ها را بکشید و در ردیف‌ها بگذارید؛ هر ردیف حداکثر ۴ دکمه. برای ویرایش روی ✏️ بزنید.</div></div>
        <?php if ($curMenu !== 'main'): $op = (int)(($mCnt[$curMenu] ?? [])['opener'] ?? 0); ?>
          <span class="badge <?= $op > 0 ? 'b-green' : 'b-red' ?>" title="دکمه‌ای در منوی اصلی که این زیرمنو را باز می‌کند"><?= $op > 0 ? '🔗 دکمهٔ بازکننده دارد' : '⚠️ بازکننده ندارد' ?></span>
        <?php endif; ?>
      </div>
      <form method="post" id="bbLayoutForm">
        <?= csrf_field() ?><input type="hidden" name="act" value="layout"><input type="hidden" name="rt" value="layout"><input type="hidden" name="layout" id="bbLayout">
        <?php foreach ($MENUS as $mk => $mt): $rows = $packRows($perMenu[$mk] ?? []); ?>
          <div class="bb-board" data-menu="<?= h($mk) ?>" <?= $mk === $curMenu ? '' : 'hidden' ?>>
            <div class="bb-board-h"><div class="n"><?= h($mt) ?></div><div class="s"><?= fa_num((string)count($perMenu[$mk] ?? [])) ?> دکمه · <?= fa_num((string)count($rows)) ?> ردیف</div></div>
            <div class="bb-rows">
              <?php if ($rows === []): ?><div class="bb-empty">هنوز دکمه‌ای در این منو نیست. از «➕ دکمهٔ جدید» یا تب «اسکن خودکار» اضافه کنید.</div><?php endif; ?>
              <?php foreach ($rows as $row): ?>
                <div class="bb-row">
                  <?php foreach ($row as $b): $aud = (string)$b['audience']; ?>
                    <div class="bb-btn <?= empty($b['enabled']) ? 'off' : '' ?> <?= $editId === (string)$b['id'] ? 'sel' : '' ?>" draggable="<?= $canEd ? 'true' : 'false' ?>" data-id="<?= h((string)$b['id']) ?>" title="<?= h((string)$b['label']) ?> · <?= h($kindShort((string)$b['kind'])) ?>">
                      <span class="ic"><?= h((string)($b['icon'] !== '' ? $b['icon'] : ((string)$b['kind'] === 'builtin' ? (string)(($allB[(string)$b['key']] ?? [])[0] ?? '🔘') : '🔘'))) ?></span>
                      <span class="lb"><?= h((string)$b['label']) ?></span>
                      <span class="tg"><?php if (empty($b['enabled'])): ?><span>خاموش</span><?php endif; ?><?php if ($aud !== 'all'): ?><span class="a-<?= h($aud) ?>"><?= h(preg_replace('/^\S+\s+/u', '', $audShort($aud))) ?></span><?php endif; ?><?php if ((string)$b['kind'] !== 'builtin'): ?><span><?= h($kindShort((string)$b['kind'])) ?></span><?php endif; ?></span>
                      <a class="ed" href="<?= $tabUrl('layout') ?>&m=<?= h($mk) ?>&e=<?= h((string)$b['id']) ?>" title="ویرایش">✏️</a>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endforeach; ?>
              <?php if ($canEd): ?><div class="bb-row new">＋ اینجا رها کنید تا ردیف تازه ساخته شود</div><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if ($canEd): ?>
          <div class="bb-board-f">
            <div class="hint">هر بار «ذخیرهٔ چیدمان» همهٔ منوها را با هم ذخیره می‌کند. عرض دکمه‌ها از تعداد دکمه‌های هر ردیف گرفته می‌شود.</div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <button class="btn btn-ghost btn-sm" type="button" id="bbUndo" disabled>↩️ برگردان</button>
              <button class="btn btn-primary btn-sm" type="submit" id="bbSaveLayout">💾 ذخیرهٔ چیدمان</button>
            </div>
          </div>
        <?php endif; ?>
      </form>
      <?php if ($canEd && (int)($sdMiss[$curMenu] ?? 0) > 0): ?>
        <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="act" value="seed"><input type="hidden" name="rt" value="layout"><input type="hidden" name="menu" value="<?= h($curMenu) ?>">
          <button class="btn btn-ghost btn-sm" type="submit">✨ ساخت <?= fa_num((string)(int)$sdMiss[$curMenu]) ?> دکمهٔ پیشنهادی برای این منو</button></form>
      <?php endif; ?>
    </div>

    <div class="card compact">
      <div class="card-head tight"><div><div class="card-title">📱 پیش‌نمایش در تلگرام</div><div class="card-sub">همان چیزی که کاربر می‌بیند (دکمه‌های خاموش کم‌رنگ‌اند و برای کاربر نمایش داده نمی‌شوند).</div></div>
        <span class="badge b-blue"><?= $mode === 'reply' ? 'کیبورد پایین' : ($mode === 'inline' ? 'دکمهٔ شیشه‌ای' : 'هر دو') ?></span></div>
      <div class="bb-phone">
        <div class="hd"><span><?= h((string)DB::setting('bot_username', 'SR-BOT')) ?></span><span>پیش‌نمایش</span></div>
        <div class="msg"><?= $curMenu === 'main' ? '🏠 منوی اصلی — یکی از گزینه‌ها را انتخاب کنید 👇' : h($menuShort($curMenu)) . ' 👇' ?></div>
        <div class="kb <?= $mode === 'inline' || $curMenu !== 'main' ? 'inline' : '' ?>" id="bbPreview">
          <?php foreach ($packRows($perMenu[$curMenu] ?? []) as $row): ?>
            <div class="kr"><?php foreach ($row as $b): ?><span class="<?= empty($b['enabled']) ? 'dim' : '' ?>" data-id="<?= h((string)$b['id']) ?>"><?= h(Btn::label($b)) ?></span><?php endforeach; ?></div>
          <?php endforeach; ?>
          <?php if (($perMenu[$curMenu] ?? []) === []): ?><div class="kr"><span class="dim">— خالی —</span></div><?php endif; ?>
        </div>
      </div>
      <div class="hint" style="margin-top:10px">حالت نمایش (<?= $mode === 'reply' ? 'کیبورد پایین' : ($mode === 'inline' ? 'شیشه‌ای' : 'هر دو') ?>) و تعداد دکمه در هر ردیف (<?= fa_num((string)$perRow) ?>) از تب «تنظیمات» تغییر می‌کند.</div>
    </div>
  </div>

<?php /* ==================== تب فهرست ==================== */ elseif ($tab === 'list'): ?>
  <div class="card compact">
    <div class="card-head tight"><div><div class="card-title">📋 فهرست همهٔ دکمه‌ها (<?= fa_num((string)count($all)) ?>)</div><div class="card-sub">فیلتر کنید، چند دکمه را انتخاب و با یک عمل گروهی تغییر دهید.</div></div></div>
    <div class="bb-filters">
      <input type="search" id="bbQ" placeholder="🔍 جست‌وجو در برچسب / کلید…">
      <select id="bbFMenu"><option value="">همهٔ منوها</option><?php foreach ($MENUS as $mk => $mt): ?><option value="<?= h($mk) ?>"><?= h($menuShort($mk)) ?></option><?php endforeach; ?></select>
      <select id="bbFKind"><option value="">همهٔ انواع</option><?php foreach (Btn::KINDS as $kk => $kt): ?><option value="<?= h($kk) ?>"><?= h($kt) ?></option><?php endforeach; ?></select>
      <select id="bbFAud"><option value="">همهٔ مخاطبان</option><?php foreach (Btn::AUDIENCES as $ak => $at): ?><option value="<?= h($ak) ?>"><?= h($at) ?></option><?php endforeach; ?></select>
      <select id="bbFSt"><option value="">روشن و خاموش</option><option value="1">فقط روشن</option><option value="0">فقط خاموش</option></select>
    </div>
    <form method="post" id="bbBulkForm"><?= csrf_field() ?><input type="hidden" name="act" value="bulk"><input type="hidden" name="rt" value="list">
    <div class="scrollbox">
    <table class="bb-table" id="bbTable">
      <thead><tr><th style="width:28px"><?php if ($canEd): ?><input type="checkbox" id="bbAll" title="انتخاب همه"><?php endif; ?></th><th>دکمه</th><th>نوع / مقصد</th><th>منو</th><th>مخاطب</th><th>عرض</th><th>وضعیت</th><th></th></tr></thead>
      <tbody>
      <?php $i = 0; foreach ($all as $b): $i++; $isB = (string)$b['kind'] === 'builtin'; $key = (string)$b['key']; $mn = Btn::menuOf($b); ?>
        <tr class="<?= empty($b['enabled']) ? 'off' : '' ?>" data-label="<?= h(mb_strtolower((string)$b['label'] . ' ' . $key)) ?>" data-menu="<?= h($mn) ?>" data-kind="<?= h((string)$b['kind']) ?>" data-aud="<?= h((string)$b['audience']) ?>" data-on="<?= empty($b['enabled']) ? '0' : '1' ?>">
          <td class="c-chk"><?php if ($canEd): ?><input type="checkbox" name="ids[]" value="<?= h((string)$b['id']) ?>" class="bb-ck"><?php endif; ?></td>
          <td><div class="nm"><span class="ic"><?= h((string)($b['icon'] !== '' ? $b['icon'] : ($isB ? (string)(($allB[$key] ?? [])[0] ?? '🔘') : '🔘'))) ?></span><?= h((string)$b['label']) ?></div>
            <?php if ((string)($b['desc'] ?? '') !== ''): ?><div class="hint" style="margin:0"><?= h((string)$b['desc']) ?></div><?php endif; ?></td>
          <td><?= h($kindShort((string)$b['kind'])) ?>
            <?php if ($isB): ?><br><span class="k"><?= h($key) ?></span> <?php if ($handlerSet !== [] && !isset($handlerSet[$key])): ?><span class="badge b-red" title="در کد ربات هندلری برای این کلید پیدا نشد">بی‌هندلر</span><?php endif; ?>
            <?php elseif ((string)$b['kind'] === 'menu'): ?><br><span class="k">→ <?= h($menuShort($key)) ?></span>
            <?php elseif ((string)$b['kind'] === 'url'): ?><br><span class="k" title="<?= h((string)$b['url']) ?>"><?= h(mb_substr((string)$b['url'], 0, 34)) ?><?= mb_strlen((string)$b['url']) > 34 ? '…' : '' ?></span>
            <?php elseif ((string)$b['kind'] === 'text'): ?><br><span class="hint" style="margin:0"><?= h(mb_substr(strip_tags((string)$b['text']), 0, 40)) ?>…</span><?php endif; ?></td>
          <td><?= h($menuShort($mn)) ?></td>
          <td><?= h($audShort((string)$b['audience'])) ?></td>
          <td><?= h((string)preg_replace('/\s*\(.*$/u', '', (string)(Btn::WIDTHS[(string)($b['width'] ?? 'auto')] ?? 'خودکار'))) ?><?= (int)($b['row'] ?? 0) > 0 ? ' <span class="k">r' . (int)$b['row'] . '</span>' : '' ?></td>
          <td><?= empty($b['enabled']) ? '<span class="badge b-gray">خاموش</span>' : '<span class="badge b-green">روشن</span>' ?><?= (int)($b['hits'] ?? 0) > 0 ? ' <span class="k" title="تعداد کلیک">' . fa_num((string)(int)$b['hits']) . '👆</span>' : '' ?></td>
          <td class="c-acts"><div class="bb-acts">
            <a class="icon-btn" href="<?= $tabUrl('list') ?>&e=<?= h((string)$b['id']) ?>" title="ویرایش">✏️</a>
            <?php if ($canEd): ?>
              <button class="icon-btn" type="submit" form="f_t_<?= $i ?>" title="<?= empty($b['enabled']) ? 'روشن کن' : 'خاموش کن' ?>"><?= empty($b['enabled']) ? '🟢' : '⚪️' ?></button>
              <button class="icon-btn" type="submit" form="f_u_<?= $i ?>" title="بالا">⬆️</button>
              <button class="icon-btn" type="submit" form="f_d_<?= $i ?>" title="پایین">⬇️</button>
              <button class="icon-btn" type="submit" form="f_c_<?= $i ?>" title="کپی">📄</button>
              <button class="icon-btn" type="submit" form="f_x_<?= $i ?>" title="حذف" data-confirm="دکمهٔ «<?= h((string)$b['label']) ?>» حذف شود؟">🗑</button>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if ($all === []): ?><div class="empty">هیچ دکمه‌ای وجود ندارد. از تب «تنظیمات» گزینهٔ «برگشت به پیش‌فرض» یا «همگام‌سازی» را بزنید.</div><?php endif; ?>
    <div class="bb-empty" id="bbNoMatch" hidden>هیچ دکمه‌ای با این فیلتر پیدا نشد.</div>
    <?php if ($canEd): ?>
      <div class="bb-bulk">
        <span class="cnt"><b id="bbSelCnt">۰</b> دکمه انتخاب شده</span>
        <select name="do">
          <optgroup label="وضعیت"><option value="on">🟢 روشن کن</option><option value="off">⚪️ خاموش کن</option></optgroup>
          <optgroup label="عرض"><?php foreach (Btn::WIDTHS as $wk => $wt): ?><option value="w:<?= h($wk) ?>"><?= h($wt) ?></option><?php endforeach; ?></optgroup>
          <optgroup label="رنگ"><?php foreach (Btn::COLORS as $ck => $cm): ?><option value="c:<?= h($ck) ?>"><?= h((string)$cm[1] . ' ' . (string)$cm[0]) ?></option><?php endforeach; ?></optgroup>
          <optgroup label="انتقال به منو"><?php foreach ($MENUS as $mk => $mt): ?><option value="m:<?= h($mk) ?>"><?= h($menuShort($mk)) ?></option><?php endforeach; ?></optgroup>
          <optgroup label="مخاطب"><?php foreach (Btn::AUDIENCES as $ak => $at): ?><option value="a:<?= h($ak) ?>"><?= h($at) ?></option><?php endforeach; ?></optgroup>
          <optgroup label="خطرناک"><option value="del">🗑 حذف انتخاب‌شده‌ها</option></optgroup>
        </select>
        <button class="btn btn-primary btn-sm" type="submit" id="bbBulkGo" data-confirm="عمل گروهی روی دکمه‌های انتخاب‌شده اجرا شود؟">اجرا</button>
      </div>
    <?php endif; ?>
    </form>
    <?php if ($canEd): $i = 0; foreach ($all as $b): $i++; $id = h((string)$b['id']); ?>
      <form method="post" id="f_t_<?= $i ?>" hidden><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="rt" value="list"><input type="hidden" name="id" value="<?= $id ?>"></form>
      <form method="post" id="f_u_<?= $i ?>" hidden><?= csrf_field() ?><input type="hidden" name="act" value="move"><input type="hidden" name="rt" value="list"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="dir" value="-1"></form>
      <form method="post" id="f_d_<?= $i ?>" hidden><?= csrf_field() ?><input type="hidden" name="act" value="move"><input type="hidden" name="rt" value="list"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="dir" value="1"></form>
      <form method="post" id="f_c_<?= $i ?>" hidden><?= csrf_field() ?><input type="hidden" name="act" value="dup"><input type="hidden" name="rt" value="list"><input type="hidden" name="id" value="<?= $id ?>"></form>
      <form method="post" id="f_x_<?= $i ?>" hidden><?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="rt" value="list"><input type="hidden" name="id" value="<?= $id ?>"></form>
    <?php endforeach; endif; ?>
  </div>

<?php /* ==================== تب اسکن خودکار ==================== */ elseif ($tab === 'scan'): ?>
  <div class="card compact">
    <div class="card-head tight"><div><div class="card-title">🔎 اسکن خودکار بخش‌های ربات</div>
      <div class="card-sub">کد ربات (<?= h(implode('، ', (array)$scan['files'])) ?>) خوانده می‌شود و هر بخشی که دکمه‌پذیر است پیدا می‌شود؛ بخش‌های تازه بعد از هر به‌روزرسانی خودکار (خاموش) به فهرست می‌آیند.</div></div>
      <?php if ($canEd): ?>
        <form method="post" style="display:flex;gap:6px;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="act" value="scan"><input type="hidden" name="rt" value="scan">
          <button class="btn btn-primary btn-sm" type="submit">🔎 اسکن الان</button></form>
      <?php endif; ?>
    </div>
    <div class="bb-scan-grid">
      <div class="bb-kpi" style="--kc:var(--accent)"><div class="l">بخش‌های پیداشده در کد</div><div class="v"><?= fa_num((string)count((array)$scan['handlers'])) ?></div></div>
      <div class="bb-kpi" style="--kc:var(--green)"><div class="l">دارای دکمه در فهرست</div><div class="v"><?= fa_num((string)((int)$scan['matched'] - count((array)$scan['not_in_list']))) ?></div></div>
      <div class="bb-kpi" style="--kc:var(--orange)"><div class="l">شناخته اما بدون دکمه</div><div class="v"><?= fa_num((string)count((array)$scan['not_in_list'])) ?></div></div>
      <div class="bb-kpi" style="--kc:var(--cyan,var(--accent))"><div class="l">بخش کاملاً تازه</div><div class="v"><?= fa_num((string)count((array)$scan['new'])) ?></div></div>
      <div class="bb-kpi" style="--kc:var(--red)"><div class="l">دکمهٔ بدون هندلر</div><div class="v"><?= fa_num((string)count((array)$scan['dead'])) ?></div></div>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
      <div class="hint" style="margin:0">آخرین اسکن: <b><?= (string)$scan['scanned_at'] !== '' ? h(to_jalali((string)$scan['scanned_at'], true)) : '—' ?></b>
        <?php if ((string)$scan['last_auto'] !== ''): ?> · آخرین اسکن خودکار: <b><?= h(to_jalali((string)$scan['last_auto'], true)) ?></b><?php endif; ?></div>
      <?php if ($canEd): ?>
        <form method="post" class="bb-sw"><?= csrf_field() ?><input type="hidden" name="act" value="autoscan"><input type="hidden" name="rt" value="scan">
          <label class="bb-sw"><input type="checkbox" name="on" value="1" <?= !empty($scan['auto']) ? 'checked' : '' ?> onchange="this.form.submit()"> اسکن خودکار (روزانه + بعد از هر به‌روزرسانی)</label></form>
      <?php endif; ?>
    </div>
    <?php if ($canEd && ((array)$scan['new'] !== [] || (array)$scan['not_in_list'] !== [])): ?>
      <div class="alert a-warn mt3" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between">
        <span>⚠️ <?= fa_num((string)(count((array)$scan['new']) + count((array)$scan['not_in_list']))) ?> بخش ربات هنوز دکمه ندارد.</span>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="scan"><input type="hidden" name="rt" value="scan"><button class="btn btn-sm btn-primary" type="submit">➕ افزودن همه به فهرست (خاموش)</button></form>
      </div>
    <?php endif; ?>
  </div>

  <div class="card compact">
    <div class="card-head tight"><div><div class="card-title">🧭 بخش‌های ربات و وضعیت دکمه</div><div class="card-sub">✅ دکمه دارد · ➕ شناخته اما در فهرست نیست · 🆕 تازه کشف‌شده · ⛔ دکمه‌ای که در کد هندلر ندارد</div></div></div>
    <div class="bb-keys">
      <?php
        $inList = [];
        foreach ($all as $b) { if ((string)$b['kind'] === 'builtin') $inList[(string)$b['key']] = $b; }
        $keysShow = array_values(array_unique(array_merge(array_map('strval', (array)$scan['handlers']), array_map('strval', array_keys($allB)))));
        foreach ($keysShow as $k):
          $meta = $allB[$k] ?? null;
          $st = 'ok'; $stT = 'دکمه دارد';
          if (in_array($k, (array)$scan['new'], true)) { $st = 'new'; $stT = 'تازه'; }
          elseif ($meta !== null && !isset($handlerSet[$k]) && $handlerSet !== []) { $st = 'dead'; $stT = 'بدون هندلر'; }
          elseif (!isset($inList[$k])) { $st = 'miss'; $stT = 'بدون دکمه'; }
          elseif (empty($inList[$k]['enabled'])) { $stT = 'دکمه دارد (خاموش)'; }
      ?>
        <div class="bb-key"><span><?= h((string)($meta[0] ?? '🔘')) ?></span><span><?= h((string)($meta[1] ?? ucwords(str_replace('_', ' ', $k)))) ?></span> <code><?= h($k) ?></code>
          <?php if (isset($inList[$k])): ?><a class="st <?= $st ?>" href="<?= $tabUrl('scan') ?>&e=<?= h((string)$inList[$k]['id']) ?>" style="text-decoration:none"><?= h($stT) ?> ✏️</a><?php else: ?><span class="st <?= $st ?>"><?= h($stT) ?></span><?php endif; ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($disc !== []): ?>
  <div class="card compact">
    <div class="card-head tight"><div><div class="card-title">🆕 بخش‌های کشف‌شده (<?= fa_num((string)count($disc)) ?>)</div><div class="card-sub">این بخش‌ها در به‌روزرسانی‌های بعدی به ربات اضافه شده‌اند؛ آیکون، نام و مخاطب پیش‌فرض آن‌ها را اینجا تنظیم کنید.</div></div></div>
    <?php foreach ($disc as $k => $m): ?>
      <form method="post" class="bb-disc"><?= csrf_field() ?><input type="hidden" name="rt" value="scan"><input type="hidden" name="key" value="<?= h((string)$k) ?>">
        <input type="text" name="icon" value="<?= h((string)$m[0]) ?>" placeholder="🔘" maxlength="8">
        <input type="text" name="label" value="<?= h((string)$m[1]) ?>" placeholder="نام دکمه" maxlength="60">
        <select name="audience"><?php foreach (Btn::AUDIENCES as $ak => $at): ?><option value="<?= h($ak) ?>" <?= (string)$m[2] === $ak ? 'selected' : '' ?>><?= h($at) ?></option><?php endforeach; ?></select>
        <input type="text" name="group" value="<?= h((string)$m[3]) ?>" placeholder="گروه" maxlength="40">
        <?php if ($canEd): ?>
          <button class="btn btn-sm btn-primary" type="submit" name="act" value="discmeta">💾</button>
          <button class="btn btn-sm btn-danger" type="submit" name="act" value="discdel" data-confirm="بخش «<?= h((string)$k) ?>» و دکمه‌های آن حذف شود؟">🗑</button>
        <?php endif; ?>
        <div class="hint" style="grid-column:1/-1;margin:0">کلید: <code class="mono ltr"><?= h((string)$k) ?></code></div>
      </form>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="card compact">
    <div class="card-head tight"><div><div class="card-title">🩺 سلامت چیدمان (<?= fa_num((string)count($health)) ?>)</div><div class="card-sub">مشکلاتی که باعث می‌شود دکمه‌ای کار نکند یا دیده نشود.</div></div>
      <?php if ($canEd && $health !== []): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="fix"><input type="hidden" name="rt" value="scan"><button class="btn btn-sm btn-ghost" type="submit">🔧 اصلاح خودکار</button></form><?php endif; ?></div>
    <?php if ($health === []): ?><div class="empty"><span class="ic">✅</span>همه چیز مرتب است.</div><?php else: ?>
      <div class="bb-h-list">
        <?php foreach ($health as $hr): ?>
          <div class="bb-h <?= (string)($hr['level'] ?? 'warn') === 'err' ? 'err' : 'warn' ?>"><span><?= (string)($hr['level'] ?? '') === 'err' ? '⛔' : '⚠️' ?></span><span class="who"><?= h((string)($hr['label'] ?? '')) ?></span><span><?= h((string)($hr['msg'] ?? '')) ?></span>
            <?php if ((string)($hr['id'] ?? '') !== '' && isset($byId[(string)$hr['id']])): ?><a href="<?= $tabUrl('scan') ?>&e=<?= h((string)$hr['id']) ?>">ویرایش ✏️</a><?php endif; ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

<?php /* ==================== تب تنظیمات ==================== */ else: ?>
  <div class="grid g2" style="align-items:start">
    <div class="card compact">
      <div class="card-head tight"><div><div class="card-title">🖥 نحوهٔ نمایش دکمه‌ها</div><div class="card-sub">این تنظیم روی همهٔ منوها اثر می‌گذارد.</div></div></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="mode"><input type="hidden" name="rt" value="settings">
        <div class="field"><label>حالت نمایش منوی اصلی</label>
          <select name="btn_mode"><option value="reply" <?= $mode === 'reply' ? 'selected' : '' ?>>⌨️ کیبورد پایین (پیشنهادی)</option><option value="inline" <?= $mode === 'inline' ? 'selected' : '' ?>>🔘 دکمهٔ شیشه‌ای زیر پیام</option><option value="both" <?= $mode === 'both' ? 'selected' : '' ?>>🔀 هر دو</option></select></div>
        <div class="form-grid g2">
          <div class="field"><label>تعداد دکمه در هر ردیف (پیش‌فرض)</label><select name="btn_per_row"><?php for ($n = 1; $n <= 4; $n++): ?><option value="<?= $n ?>" <?= $perRow === $n ? 'selected' : '' ?>><?= fa_num((string)$n) ?> تایی</option><?php endfor; ?></select></div>
          <div class="field"><label>حداکثر دکمه در ردیف</label><select name="btn_max_per_row"><?php for ($n = 1; $n <= 4; $n++): ?><option value="<?= $n ?>" <?= $maxRow === $n ? 'selected' : '' ?>><?= fa_num((string)$n) ?></option><?php endfor; ?></select></div>
        </div>
        <div class="field"><label>آدرس مینی‌اپ (برای دکمه‌های نوع مینی‌اپ)</label><input class="mono ltr" type="url" name="miniapp_url" value="<?= h($appRaw) ?>" placeholder="<?= h($appUrl !== '' ? $appUrl : 'https://example.com/miniapp/') ?>"><div class="hint">خالی بگذارید تا از آدرس پیش‌فرض سایت استفاده شود.</div></div>
        <?php if ($canEd): ?><div class="sticky-acts"><button class="btn btn-primary" type="submit">💾 ذخیرهٔ تنظیمات</button></div><?php endif; ?>
      </form>
    </div>

    <div class="card compact">
      <div class="card-head tight"><div><div class="card-title">✨ تکمیل خودکار و همگام‌سازی</div><div class="card-sub">دکمه‌های پیشنهادی هر زیرمنو و بخش‌های تازهٔ ربات را با یک کلیک اضافه کنید.</div></div></div>
      <?php if ($canEd): ?>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
          <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="sync"><input type="hidden" name="rt" value="settings"><button class="btn btn-ghost btn-sm" type="submit">🔄 همگام‌سازی با بخش‌های ربات</button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="seed"><input type="hidden" name="rt" value="settings"><input type="hidden" name="menu" value=""><button class="btn btn-ghost btn-sm" type="submit">✨ ساخت همهٔ دکمه‌های پیشنهادی<?php $sm = 0; foreach ($sdMiss as $v) $sm += (int)$v; echo $sm > 0 ? ' (' . fa_num((string)$sm) . ')' : ''; ?></button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="fix"><input type="hidden" name="rt" value="settings"><button class="btn btn-ghost btn-sm" type="submit">🔧 اصلاح خودکار مشکلات</button></form>
        </div>
        <div class="hint" style="margin-top:8px">دکمه‌های پیشنهادی هر منو: <?php foreach ($sdMiss as $mk => $v): if ((int)$v > 0): ?><span class="badge b-gray"><?= h($menuShort((string)$mk)) ?> <?= fa_num((string)(int)$v) ?></span> <?php endif; endforeach; ?><?= $sm === 0 ? 'همه ساخته شده‌اند.' : '' ?></div>
      <?php endif; ?>
    </div>

    <div class="card compact">
      <div class="card-head tight"><div><div class="card-title">📦 پشتیبان‌گیری از چیدمان</div><div class="card-sub">خروجی JSON بگیرید و هر وقت خواستید بازگردانید.</div></div></div>
      <?php if ($canEd): ?>
        <form method="post" style="margin-bottom:10px"><?= csrf_field() ?><input type="hidden" name="act" value="export"><button class="btn btn-ghost btn-sm" type="submit">📤 دریافت فایل پشتیبان</button></form>
        <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="act" value="import"><input type="hidden" name="rt" value="settings">
          <div class="field"><label>فایل JSON</label><input type="file" name="jfile" accept="application/json,.json"></div>
          <div class="field"><label>یا متن JSON</label><textarea name="json" rows="3" class="mono ltr" placeholder='{"buttons":[...]}'></textarea></div>
          <button class="btn btn-primary btn-sm" type="submit" data-confirm="چیدمان فعلی با فایل پشتیبان جایگزین شود؟">📥 بازگردانی</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="card compact">
      <div class="card-head tight"><div><div class="card-title">♻️ برگشت به پیش‌فرض</div><div class="card-sub">همهٔ دکمه‌ها و چیدمان سفارشی حذف و دکمه‌های پیش‌فرض ربات ساخته می‌شوند.</div></div></div>
      <?php if ($canEd): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="reset"><input type="hidden" name="rt" value="layout"><button class="btn btn-danger btn-sm" type="submit" data-confirm="همهٔ دکمه‌های سفارشی حذف و به حالت پیش‌فرض برگردد؟ (پیشنهاد: قبل از آن پشتیبان بگیرید)">♻️ برگشت به پیش‌فرض</button></form>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
</div>

<?php /* ==================== ویرایشگر ==================== */ if ($showEditor): $b = $editing ?? Btn::normalize(['kind' => 'builtin', 'key' => 'products', 'label' => '', 'enabled' => 1, 'menu' => $curMenu, 'width' => 'half']); if ($editing === null) $b['id'] = ''; $b['label'] = $editing ? $b['label'] : ''; ?>
<div class="bb-editor">
  <div class="card compact">
    <div class="card-head tight"><div><div class="card-title"><?= $editing ? '✏️ ویرایش دکمه' : '➕ دکمهٔ جدید' ?></div><?php if ($editing): ?><div class="card-sub mono ltr"><?= h((string)$b['id']) ?></div><?php endif; ?></div>
      <a class="icon-btn" href="<?= $tabUrl($tab) ?><?= $tab === 'layout' ? '&m=' . h($curMenu) : '' ?>" title="بستن">✖</a></div>
    <form method="post" id="bbEdForm"><?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="rt" value="<?= h($tab) ?>"><input type="hidden" name="id" value="<?= h((string)$b['id']) ?>"><input type="hidden" name="hits" value="<?= (int)($b['hits'] ?? 0) ?>">
      <div class="field"><label>نوع دکمه</label>
        <select name="kind" id="bbKind"><?php foreach (Btn::KINDS as $kk => $kt): ?><option value="<?= h($kk) ?>" <?= (string)$b['kind'] === $kk ? 'selected' : '' ?>><?= h($kt) ?></option><?php endforeach; ?></select></div>
      <div class="field kf kf-builtin"><label>بخش ربات</label>
        <select name="key" id="bbKey">
          <?php foreach ($keyGroups as $g => $ks): ?><optgroup label="<?= h((string)$g) ?>"><?php foreach ($ks as $kk => $km): ?><option value="<?= h((string)$kk) ?>" data-ic="<?= h((string)$km[0]) ?>" data-lb="<?= h((string)$km[1]) ?>" <?= (string)$b['kind'] === 'builtin' && (string)$b['key'] === (string)$kk ? 'selected' : '' ?>><?= h((string)$km[0] . ' ' . (string)$km[1]) ?> (<?= h((string)$kk) ?>)<?= $handlerSet !== [] && !isset($handlerSet[(string)$kk]) ? ' ⛔' : '' ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
        </select><div class="hint">⛔ = این بخش در کد فعلی ربات هندلر ندارد.</div></div>
      <div class="field kf kf-menu"><label>زیرمنویی که باز می‌شود</label>
        <select name="mkey"><?php foreach ($MENUS as $mk => $mt): if ($mk === 'main') continue; ?><option value="<?= h($mk) ?>" <?= (string)$b['kind'] === 'menu' && (string)$b['key'] === $mk ? 'selected' : '' ?>><?= h($mt) ?></option><?php endforeach; ?></select></div>
      <div class="field kf kf-url kf-miniapp"><label>آدرس (https://…)</label><input class="mono ltr" type="text" name="url" value="<?= h((string)$b['url']) ?>" placeholder="https://"><div class="hint kf kf-miniapp">برای مینی‌اپ اگر خالی باشد از آدرس عمومی مینی‌اپ (تب تنظیمات) استفاده می‌شود.</div></div>
      <div class="field kf kf-text"><label>متن پاسخ (HTML تلگرام مجاز)</label><textarea name="text" rows="4"><?= h((string)$b['text']) ?></textarea></div>
      <div class="field kf kf-copy"><label>متنی که با لمس کپی می‌شود</label><input type="text" name="copy" value="<?= h((string)($b['copy'] ?? '')) ?>" maxlength="300"></div>
      <div class="row2">
        <div class="field"><label>آیکون</label><input type="text" name="icon" id="bbIcon" value="<?= h((string)$b['icon']) ?>" placeholder="🔘" maxlength="12"></div>
        <div class="field"><label>برچسب دکمه <span class="muted">*</span></label><input type="text" name="label" id="bbLabel" value="<?= h((string)$b['label']) ?>" placeholder="مثلاً: خرید اشتراک" maxlength="80" required></div>
      </div>
      <div class="row3">
        <div class="field"><label>رنگ</label><select name="color" id="bbColor"><?php foreach (Btn::COLORS as $ck => $cm): ?><option value="<?= h($ck) ?>" data-dot="<?= h((string)$cm[1]) ?>" data-sq="<?= h((string)$cm[2]) ?>" <?= (string)($b['color'] ?? 'none') === $ck ? 'selected' : '' ?>><?= h(trim((string)$cm[1] . ' ' . (string)$cm[0])) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>سبک</label><select name="style" id="bbStyle"><?php foreach (Btn::STYLES as $sk => $stt): ?><option value="<?= h($sk) ?>" <?= (string)($b['style'] ?? 'plain') === $sk ? 'selected' : '' ?>><?= h($stt) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>عرض</label><select name="width"><?php foreach (Btn::WIDTHS as $wk => $wt): ?><option value="<?= h($wk) ?>" <?= (string)($b['width'] ?? 'auto') === $wk ? 'selected' : '' ?>><?= h((string)preg_replace('/\s*\(.*$/u', '', $wt)) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="row2">
        <div class="field"><label>در کدام منو</label><select name="menu"><?php foreach ($MENUS as $mk => $mt): ?><option value="<?= h($mk) ?>" <?= Btn::menuOf($b) === $mk ? 'selected' : '' ?>><?= h($menuShort($mk)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>برای کی نمایش داده شود</label><select name="audience"><?php foreach (Btn::AUDIENCES as $ak => $at): ?><option value="<?= h($ak) ?>" <?= (string)$b['audience'] === $ak ? 'selected' : '' ?>><?= h($at) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="field"><label>توضیح کوتاه (فقط در پنل)</label><input type="text" name="desc" value="<?= h((string)($b['desc'] ?? '')) ?>" maxlength="120"></div>
      <label class="check"><input type="checkbox" name="enabled" value="1" <?= !empty($b['enabled']) ? 'checked' : '' ?>> <span>دکمه روشن باشد (برای کاربر نمایش داده شود)</span></label>
      <div class="lblprev" id="bbLblPrev"><?= h(Btn::label($b)) ?></div>
      <?php if ($canEd): ?>
        <div class="sticky-acts" style="margin-top:10px">
          <button class="btn btn-primary" type="submit">💾 ذخیره</button>
          <?php if ($editing): ?>
            <button class="btn btn-ghost" type="submit" form="bbEdDup">📄 کپی</button>
            <button class="btn btn-ghost" type="submit" form="bbEdTop" title="انتقال به ابتدای منو">⏫</button>
            <button class="btn btn-ghost" type="submit" form="bbEdBot" title="انتقال به انتهای منو">⏬</button>
            <button class="btn btn-danger" type="submit" form="bbEdDel" data-confirm="دکمهٔ «<?= h((string)$b['label']) ?>» حذف شود؟">🗑 حذف</button>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </form>
    <?php if ($editing && $canEd): $id = h((string)$b['id']); ?>
      <form method="post" id="bbEdDup" hidden><?= csrf_field() ?><input type="hidden" name="act" value="dup"><input type="hidden" name="rt" value="<?= h($tab) ?>"><input type="hidden" name="id" value="<?= $id ?>"></form>
      <form method="post" id="bbEdTop" hidden><?= csrf_field() ?><input type="hidden" name="act" value="edge"><input type="hidden" name="rt" value="<?= h($tab) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="to" value="top"></form>
      <form method="post" id="bbEdBot" hidden><?= csrf_field() ?><input type="hidden" name="act" value="edge"><input type="hidden" name="rt" value="<?= h($tab) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="to" value="bottom"></form>
      <form method="post" id="bbEdDel" hidden><?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="rt" value="<?= h($tab) ?>"><input type="hidden" name="id" value="<?= $id ?>"></form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
</div>

<script>
(function(){
  'use strict';
  var $=function(s,c){return (c||document).querySelector(s);}, $$=function(s,c){return Array.prototype.slice.call((c||document).querySelectorAll(s));};
  var fa=function(n){return String(n).replace(/\d/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'[d];});};

  /* ---------- درگ‌اند‌دراپ چیدمان ---------- */
  var form=$('#bbLayoutForm');
  if(form){
    var dragging=null, history=[], undoBtn=$('#bbUndo');
    var snapshot=function(){ return $$('.bb-board',form).map(function(b){ return $$('.bb-row:not(.new)',b).map(function(r){ return $$('.bb-btn',r).map(function(x){return x.getAttribute('data-id');}).join(','); }).filter(Boolean).join(';'); }).join(';'); };
    var serialize=function(){
      var parts=[];
      $$('.bb-board',form).forEach(function(b){ $$('.bb-row:not(.new)',b).forEach(function(r){ var ids=$$('.bb-btn',r).map(function(x){return x.getAttribute('data-id');}); if(ids.length) parts.push(ids.join(',')); }); });
      return parts.join(';');
    };
    var cleanup=function(board){
      $$('.bb-row:not(.new)',board).forEach(function(r){ if(!$('.bb-btn',r)) r.parentNode.removeChild(r); });
      var rows=$('.bb-rows',board), nw=$('.bb-row.new',board);
      if(rows&&nw) rows.appendChild(nw);
      var e=$('.bb-empty',board); if(e && $('.bb-btn',board)) e.parentNode.removeChild(e);
      var s=$('.bb-board-h .s',board); if(s){ s.textContent=fa($$('.bb-btn',board).length)+' دکمه · '+fa($$('.bb-row:not(.new)',board).length)+' ردیف'; }
    };
    var pushHistory=function(){ history.push(snapshot()); if(history.length>30) history.shift(); if(undoBtn) undoBtn.disabled=false; };
    var restore=function(snap){
      /* بازسازی از روی رشتهٔ ذخیره‌شده */
      var byId={}; $$('.bb-btn',form).forEach(function(x){byId[x.getAttribute('data-id')]=x;});
      var board=$$('.bb-board',form).filter(function(b){return !b.hidden;})[0]; if(!board) return;
      var rows=$('.bb-rows',board), nw=$('.bb-row.new',board);
      $$('.bb-row:not(.new)',board).forEach(function(r){ r.parentNode.removeChild(r); });
      snap.split(';').forEach(function(rs){ if(!rs) return; var r=document.createElement('div'); r.className='bb-row'; rs.split(',').forEach(function(id){ if(byId[id]) r.appendChild(byId[id]); }); if(r.children.length) rows.insertBefore(r, nw); });
      cleanup(board); refreshPreview();
    };
    if(undoBtn) undoBtn.addEventListener('click',function(){ var s=history.pop(); if(s!==undefined){ restore(s); } if(!history.length) undoBtn.disabled=true; });

    /* پیش‌نمایش زنده */
    var prev=$('#bbPreview');
    var refreshPreview=function(){
      if(!prev) return;
      var board=$$('.bb-board',form).filter(function(b){return !b.hidden;})[0]; if(!board) return;
      var lbl={}; $$('#bbPreview span[data-id]').forEach(function(s){ lbl[s.getAttribute('data-id')]={t:s.textContent,d:s.classList.contains('dim')}; });
      prev.innerHTML='';
      $$('.bb-row:not(.new)',board).forEach(function(r){
        var kr=document.createElement('div'); kr.className='kr';
        $$('.bb-btn',r).forEach(function(x){ var id=x.getAttribute('data-id'); var s=document.createElement('span'); s.setAttribute('data-id',id); var L=lbl[id]; s.textContent=L?L.t:($('.lb',x)||{}).textContent||''; if((L&&L.d)||x.classList.contains('off')) s.className='dim'; kr.appendChild(s); });
        if(kr.children.length) prev.appendChild(kr);
      });
    };

    form.addEventListener('dragstart',function(e){ var t=e.target.closest('.bb-btn'); if(!t||t.getAttribute('draggable')!=='true') return; dragging=t; t.classList.add('drag'); e.dataTransfer.effectAllowed='move'; try{e.dataTransfer.setData('text/plain',t.getAttribute('data-id'));}catch(_){} });
    form.addEventListener('dragend',function(){ if(dragging) dragging.classList.remove('drag'); dragging=null; $$('.bb-row.over',form).forEach(function(r){r.classList.remove('over');}); });
    form.addEventListener('dragover',function(e){ var r=e.target.closest('.bb-row'); if(!r||!dragging) return; if(!r.classList.contains('new') && $$('.bb-btn',r).length>=4 && dragging.parentNode!==r) return; e.preventDefault(); e.dataTransfer.dropEffect='move'; r.classList.add('over'); });
    form.addEventListener('dragleave',function(e){ var r=e.target.closest('.bb-row'); if(r) r.classList.remove('over'); });
    form.addEventListener('drop',function(e){
      var r=e.target.closest('.bb-row'); if(!r||!dragging) return; e.preventDefault(); r.classList.remove('over');
      var board=r.closest('.bb-board'); pushHistory();
      if(r.classList.contains('new')){ var nr=document.createElement('div'); nr.className='bb-row'; nr.appendChild(dragging); r.parentNode.insertBefore(nr,r); }
      else {
        var after=null, x=e.clientX; $$('.bb-btn',r).forEach(function(b){ var rc=b.getBoundingClientRect(); var mid=rc.left+rc.width/2; if(document.dir==='rtl'||getComputedStyle(document.body).direction==='rtl'){ if(x>mid && after===null) after=b; } else { if(x<mid && after===null) after=b; } });
        if(after && after!==dragging) r.insertBefore(dragging,after); else if(!after) r.appendChild(dragging);
      }
      $$('.bb-board',form).forEach(cleanup); refreshPreview();
    });
    /* لمس موبایل: فشار طولانی → انتقال با کلیک روی ردیف مقصد */
    var picked=null;
    form.addEventListener('click',function(e){
      if(e.target.closest('a,button,input')) return;
      var btn=e.target.closest('.bb-btn'); var row=e.target.closest('.bb-row');
      if(btn && (!picked || picked===btn)){ picked=picked===btn?null:btn; $$('.bb-btn.sel',form).forEach(function(x){x.classList.remove('sel');}); if(picked) picked.classList.add('sel'); return; }
      if(picked && row){ pushHistory(); if(row.classList.contains('new')){ var nr=document.createElement('div'); nr.className='bb-row'; nr.appendChild(picked); row.parentNode.insertBefore(nr,row); } else if(btn){ row.insertBefore(picked, btn); } else { row.appendChild(picked); } picked.classList.remove('sel'); picked=null; $$('.bb-board',form).forEach(cleanup); refreshPreview(); }
    });
    form.addEventListener('submit',function(){ $('#bbLayout').value=serialize(); });
  }

  /* ---------- فیلتر فهرست ---------- */
  var tbl=$('#bbTable');
  if(tbl){
    var q=$('#bbQ'), fm=$('#bbFMenu'), fk=$('#bbFKind'), fa2=$('#bbFAud'), fs=$('#bbFSt'), none=$('#bbNoMatch');
    var apply=function(){
      var qq=(q.value||'').toLowerCase().trim(), n=0;
      $$('tbody tr',tbl).forEach(function(tr){
        var ok=true;
        if(qq && (tr.getAttribute('data-label')||'').indexOf(qq)<0) ok=false;
        if(fm.value && tr.getAttribute('data-menu')!==fm.value) ok=false;
        if(fk.value && tr.getAttribute('data-kind')!==fk.value) ok=false;
        if(fa2.value && tr.getAttribute('data-aud')!==fa2.value) ok=false;
        if(fs.value!=='' && tr.getAttribute('data-on')!==fs.value) ok=false;
        tr.hidden=!ok; if(ok) n++;
      });
      if(none) none.hidden=n>0;
    };
    [q,fm,fk,fa2,fs].forEach(function(el){ if(el){ el.addEventListener('input',apply); el.addEventListener('change',apply); } });
    var all=$('#bbAll'), cnt=$('#bbSelCnt');
    var recount=function(){ if(cnt) cnt.textContent=fa($$('.bb-ck:checked',tbl).length); };
    if(all) all.addEventListener('change',function(){ $$('tbody tr',tbl).forEach(function(tr){ if(!tr.hidden){ var c=$('.bb-ck',tr); if(c) c.checked=all.checked; } }); recount(); });
    tbl.addEventListener('change',function(e){ if(e.target.classList.contains('bb-ck')) recount(); });
    var bulk=$('#bbBulkForm'); if(bulk) bulk.addEventListener('submit',function(e){ if(!$$('.bb-ck:checked',tbl).length){ e.preventDefault(); alert('اول چند دکمه را انتخاب کنید.'); } });
  }

  /* ---------- فرم ویرایش ---------- */
  var kind=$('#bbKind');
  if(kind){
    var show=function(){ var k=kind.value; $$('.kf').forEach(function(el){ el.classList.toggle('on', el.classList.contains('kf-'+k)); }); };
    kind.addEventListener('change',show); show();
    var key=$('#bbKey'), ic=$('#bbIcon'), lb=$('#bbLabel'), col=$('#bbColor'), sty=$('#bbStyle'), pv=$('#bbLblPrev');
    var prevLbl=function(){
      if(!pv) return;
      var o=col.options[col.selectedIndex]||{}, dot=o.getAttribute?o.getAttribute('data-dot')||'':'', sq=o.getAttribute?o.getAttribute('data-sq')||'':'';
      var t=(ic.value?ic.value+' ':'')+(lb.value||'…'), s=sty.value;
      if(s==='circle'&&dot) t=dot+' '+t; else if(s==='square'&&sq) t=sq+' '+t; else if(s==='wrap'&&dot) t=dot+' '+t+' '+dot; else if(s==='bracket') t='[ '+t+' ]'; else if(s==='line') t='— '+t+' —'; else if(s==='dot') t='• '+t; else if(s==='arrow') t=t+' ←'; else if(s==='dash') t='- '+t+' -'; else if(s==='star') t='⭐ '+t;
      pv.textContent=t;
    };
    [ic,lb,col,sty].forEach(function(el){ if(el){ el.addEventListener('input',prevLbl); el.addEventListener('change',prevLbl); } });
    if(key) key.addEventListener('change',function(){ var o=key.options[key.selectedIndex]; if(!o) return; if(!lb.value.trim() || lb.getAttribute('data-auto')==='1'){ lb.value=o.getAttribute('data-lb')||''; lb.setAttribute('data-auto','1'); } if(!ic.value.trim() || ic.getAttribute('data-auto')==='1'){ ic.value=o.getAttribute('data-ic')||''; ic.setAttribute('data-auto','1'); } prevLbl(); });
    if(lb && !lb.value) lb.setAttribute('data-auto','1');
    if(ic && !ic.value) ic.setAttribute('data-auto','1');
    if(lb) lb.addEventListener('input',function(){ lb.removeAttribute('data-auto'); });
    if(ic) ic.addEventListener('input',function(){ ic.removeAttribute('data-auto'); });
    if(kind.value==='builtin' && key && lb && !lb.value){ key.dispatchEvent(new Event('change')); }
    var ed=$('.bb-editor'); if(ed && window.innerWidth<1100){ ed.scrollIntoView({behavior:'smooth',block:'start'}); }
  }
})();
</script>
