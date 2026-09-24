<?php
/** پشتیبانی – مرکز تیکت (گفتگوی زنده، پیوست، پاسخ آماده و پایش زمان پاسخ) */
declare(strict_types=1);

if (!can('tickets.view')) { echo denyBox('بخش پشتیبانی برای شما فعال نیست.'); return; }

/* ---------- نمایش/دانلود فایل پیوست کاربر ----------
 * فایل از سرور تلگرام خوانده و از طریق همین صفحه پخش می‌شود
 * تا توکن ربات در مرورگر لو نرود. */
if (isset($_GET['att'])) {
    $mid = (int)$_GET['att'];
    $row = DB::one('SELECT * FROM {p}ticket_messages WHERE id = :id', [':id' => $mid]);
    while (ob_get_level() > 0) ob_end_clean();

    if (!$row || empty($row['file_id'])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'فایل یافت نشد.';
        exit;
    }

    $src = Tg::fileUrl((string)$row['file_id']);
    if ($src === '') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'دریافت فایل از تلگرام ممکن نشد. (فایل‌های قدیمی پاک می‌شوند)';
        exit;
    }

    $ext   = strtolower((string)pathinfo((string)parse_url($src, PHP_URL_PATH), PATHINFO_EXTENSION));
    $mimes = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'mp4' => 'video/mp4',
        'pdf' => 'application/pdf', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg',
        'mp3' => 'audio/mpeg', 'zip' => 'application/zip', 'txt' => 'text/plain',
    ];
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: private, max-age=900');
    header('X-Content-Type-Options: nosniff');
    if (!empty($_GET['dl'])) {
        header('Content-Disposition: attachment; filename="ticket-' . $mid . '.' . ($ext ?: 'bin') . '"');
    }
    $fh = @fopen($src, 'rb');
    if ($fh) { fpassthru($fh); fclose($fh); }
    exit;
}

$act = (string)($_POST['act'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $id = pint('id');
    $tk = $id ? DB::one('SELECT t.*, u.tg_id AS utg, u.first_name FROM {p}tickets t LEFT JOIN {p}users u ON u.id = t.user_id WHERE t.id = :id', [':id' => $id]) : null;

    /* 0.0.2 #21: اولویت و دستهٔ تیکت */
    if ($tk && $act === 'tkmeta') {
        need('tickets.reply', 'tickets');
        $pr  = (string)($_POST['priority'] ?? 'normal');
        $cat = trim((string)($_POST['category'] ?? ''));
        if (!in_array($pr, ['low', 'normal', 'high', 'urgent'], true)) $pr = 'normal';
        if (function_exists('mb_substr') && mb_strlen($cat, 'UTF-8') > 32) $cat = mb_substr($cat, 0, 32, 'UTF-8');
        $hasPr  = !class_exists('Migrate') || Migrate::hasColumn('tickets', 'priority');
        $hasCat = !class_exists('Migrate') || Migrate::hasColumn('tickets', 'category');
        $up = ['updated_at' => now()];
        if ($hasPr)  $up['priority'] = $pr;
        if ($hasCat) $up['category'] = ($cat !== '' ? $cat : null);
        if (count($up) > 1) {
            DB::update('tickets', $up, 'id = :id', [':id' => $id]);
            flash('ok', '✅ اولویت/دستهٔ تیکت به‌روز شد.');
        } else {
            flash('warn', '⚠️ ابتدا به‌روزرسانی دیتابیس را اجرا کنید.');
        }
        back('tickets', ['t' => $id]);
    }

    if ($tk && $act === 'reply') {
        need('tickets.reply', 'tickets');

        $text = ptxt('text');

        /* فایل پیوست اختیاری از سمت مدیر */
        $sentFile = false;
        $tmpPath  = '';
        /* 0.0.2 #10-ticket-att: فایل پیوست از فیلتر امنیتی رد شود */
        if (!empty($_FILES['att']['tmp_name']) && is_uploaded_file($_FILES['att']['tmp_name'])
            && (!class_exists('Upload') || Upload::check((array)$_FILES['att'], 20 * 1024 * 1024)['ok'])) {
            $size = (int)($_FILES['att']['size'] ?? 0);
            if ($size > 0 && $size <= 20 * 1024 * 1024) {
                /* 0.0.2 #10-ticket-name */
                $safe = class_exists('Upload')
                    ? Upload::safeName((string)($_FILES['att']['name'] ?? 'file'))
                    : (preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($_FILES['att']['name'] ?? 'file')));
                $dir     = APP_ROOT . '/storage/tmp';
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                $tmpPath = $dir . '/' . time() . '-' . $safe;
                if (@move_uploaded_file($_FILES['att']['tmp_name'], $tmpPath)) $sentFile = true;
            } else {
                flash('warn', '⚠️ حجم فایل باید کمتر از ۲۰ مگابایت باشد.');
            }
        }

        if ($text === '' && !$sentFile) {
            flash('err', 'متن پاسخ یا فایل پیوست را وارد کنید.');
            back('tickets', ['t' => $id]);
        }

        DB::insert('ticket_messages', [
            'ticket_id'  => $id,
            'sender'     => 'admin',
            'text'       => $text !== '' ? $text : '📎 فایل پیوست',
            'created_at' => now(),
        ]);
        DB::update('tickets', ['status' => 'answered', 'updated_at' => now()], 'id = :id', [':id' => $id]);

        $head = "💬 <b>پاسخ پشتیبانی</b>\n"
              . "🎫 تیکت شماره <code>" . $id . "</code> – " . h((string)$tk['subject']) . "\n"
              . "<code>─────────────────</code>\n";
        $kb = Tg::ikb([[Tg::btn('✍️ ارسال پاسخ', 'tkr:' . $id)]]);

        if ($text !== '') Tg::send((int)$tk['utg'], $head . h($text), $kb);

        if ($sentFile && $tmpPath !== '') {
            Tg::document((int)$tk['utg'], $tmpPath, '📎 فایل پیوست پشتیبانی – تیکت #' . $id);
            @unlink($tmpPath);
        }

        flash('ok', '✅ پاسخ' . ($sentFile ? ' و فایل پیوست' : '') . ' برای کاربر ارسال شد.');
        back('tickets', ['t' => $id]);
    }

    if ($tk && $act === 'close') {
        need('tickets.close', 'tickets');
        DB::update('tickets', ['status' => 'closed', 'updated_at' => now()], 'id = :id', [':id' => $id]);
        Tg::send((int)$tk['utg'], "✅ تیکت شماره <code>" . $id . "</code> بسته شد.\nدر صورت نیاز تیکت جدیدی باز کنید.");
        flash('ok', '✅ تیکت بسته شد.');
        back('tickets');
    }

    if ($tk && $act === 'open') {
        need('tickets.close', 'tickets');
        DB::update('tickets', ['status' => 'open', 'updated_at' => now()], 'id = :id', [':id' => $id]);
        back('tickets', ['t' => $id]);
    }

    if ($tk && $act === 'del') {
        need('tickets.delete', 'tickets');
        DB::delete('ticket_messages', 'ticket_id = :id', [':id' => $id]);
        DB::delete('tickets', 'id = :id', [':id' => $id]);
        flash('ok', '🗑 تیکت حذف شد.');
        back('tickets');
    }

    /* ---------- عملیات گروهی روی تیکت‌ها ---------- */
    if ($act === 'bulk') {
        $ids = array_values(array_unique(array_map('intval', (array)($_POST['ids'] ?? []))));
        $ids = array_values(array_filter($ids, static fn(int $x): bool => $x > 0));
        $m   = ptxt('mode');

        if (!$ids) {
            flash('warn', 'هیچ تیکتی انتخاب نشده بود.');
            back('tickets');
        }

        $n = 0;
        if ($m === 'close') { need('tickets.close', 'tickets');
            foreach ($ids as $tid) {
                DB::update('tickets', ['status' => 'closed', 'updated_at' => now()], 'id = :id', [':id' => $tid]);
                $n++;
            }
            flash('ok', fa_num($n) . ' تیکت بسته شد.');
        } elseif ($m === 'open') { need('tickets.close', 'tickets');
            foreach ($ids as $tid) {
                DB::update('tickets', ['status' => 'open', 'updated_at' => now()], 'id = :id', [':id' => $tid]);
                $n++;
            }
            flash('ok', fa_num($n) . ' تیکت باز شد.');
        } elseif ($m === 'del') { need('tickets.delete', 'tickets');
            foreach ($ids as $tid) {
                DB::delete('ticket_messages', 'ticket_id = :id', [':id' => $tid]);
                DB::delete('tickets', 'id = :id', [':id' => $tid]);
                $n++;
            }
            flash('ok', fa_num($n) . ' تیکت حذف شد.');
        } else {
            flash('err', 'عملیات نامعتبر است.');
        }
        back('tickets');
    }
}

$fStatus = (string)($_GET['status'] ?? '');
$viewId  = (int)($_GET['t'] ?? 0);

/* 0.0.2 #21: فیلتر اولویت و دسته */
$tkHasPrio   = !class_exists('Migrate') || Migrate::hasColumn('tickets', 'priority');
$tkPrioLabel = ['urgent' => 'فوری', 'high' => 'زیاد', 'normal' => 'عادی', 'low' => 'کم'];
$fPrio = $tkHasPrio ? (string)($_GET['prio'] ?? '') : '';
if (!isset($tkPrioLabel[$fPrio])) $fPrio = '';

$wh = [];
$pw = [];
if ($fStatus !== '') { $wh[] = 't.status = :st';   $pw[':st'] = $fStatus; }
if ($fPrio   !== '') { $wh[] = 't.priority = :pr'; $pw[':pr'] = $fPrio; }
$w = $wh ? ('WHERE ' . implode(' AND ', $wh)) : '';
$ordPrio = $tkHasPrio ? "CASE t.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'low' THEN 3 ELSE 2 END," : '';
$cUrg  = $tkHasPrio ? (int)DB::val("SELECT COUNT(*) FROM {p}tickets WHERE priority = 'urgent' AND status <> 'closed'", [], 0) : 0;
$cHigh = $tkHasPrio ? (int)DB::val("SELECT COUNT(*) FROM {p}tickets WHERE priority = 'high' AND status <> 'closed'", [], 0) : 0;
$rows = DB::all("SELECT t.*, u.first_name, u.username, u.tg_id AS utg, u.id AS uid,
                        (SELECT COUNT(*) FROM {p}ticket_messages m WHERE m.ticket_id = t.id) AS msgs,
                        (SELECT COUNT(*) FROM {p}ticket_messages m2 WHERE m2.ticket_id = t.id AND m2.file_id IS NOT NULL AND m2.file_id <> '') AS atts
                 FROM {p}tickets t LEFT JOIN {p}users u ON u.id = t.user_id
                 $w ORDER BY (t.status = 'open') DESC, $ordPrio t.updated_at DESC LIMIT 200",
                 $pw);

$T = $viewId ? DB::one('SELECT t.*, u.first_name, u.username, u.tg_id AS utg, u.id AS uid
                        FROM {p}tickets t LEFT JOIN {p}users u ON u.id = t.user_id WHERE t.id = :id', [':id' => $viewId]) : null;
$msgs = $T ? DB::all('SELECT * FROM {p}ticket_messages WHERE ticket_id = :id ORDER BY id ASC', [':id' => $viewId]) : [];

/* آمار */
$cOpen = (int)DB::val("SELECT COUNT(*) FROM {p}tickets WHERE status = 'open'", [], 0);
$cAns  = (int)DB::val("SELECT COUNT(*) FROM {p}tickets WHERE status = 'answered'", [], 0);
$cCls  = (int)DB::val("SELECT COUNT(*) FROM {p}tickets WHERE status = 'closed'", [], 0);
$cAll  = $cOpen + $cAns + $cCls;

$cToday = (int)DB::val('SELECT COUNT(*) FROM {p}tickets WHERE DATE(created_at) = CURDATE()', [], 0);
$cMsgs  = (int)DB::val('SELECT COUNT(*) FROM {p}ticket_messages', [], 0);
$cAtts  = (int)DB::val("SELECT COUNT(*) FROM {p}ticket_messages WHERE file_id IS NOT NULL AND file_id <> ''", [], 0);
$avgMsg = $cAll > 0 ? round($cMsgs / $cAll, 1) : 0;

/* قدیمی‌ترین تیکت باز — شاخص کیفیت پاسخ‌گویی */
$oldOpen = DB::one("SELECT updated_at FROM {p}tickets WHERE status = 'open' ORDER BY updated_at ASC LIMIT 1");
$waitTxt = '—';
$waitBad = false;
if ($oldOpen && !empty($oldOpen['updated_at'])) {
    $mins = (int)max(0, (time() - (int)strtotime((string)$oldOpen['updated_at'])) / 60);
    if ($mins < 60) {
        $waitTxt = fa_num($mins) . ' دقیقه';
    } elseif ($mins < 1440) {
        $waitTxt = fa_num((int)floor($mins / 60)) . ' ساعت';
    } else {
        $waitTxt = fa_num((int)floor($mins / 1440)) . ' روز';
    }
    $waitBad = $mins > 720;
}

$closedPct = $cAll > 0 ? (int)round(($cAns + $cCls) * 100 / $cAll) : 0;

/** سن تیکت برای نشانگر SLA */
$ageOf = static function (string $ts): array {
    $mins = (int)max(0, (time() - (int)strtotime($ts)) / 60);
    if ($mins < 60)   return [fa_num($mins) . ' دقیقه', 'ok'];
    if ($mins < 1440) return [fa_num((int)floor($mins / 60)) . ' ساعت', $mins > 720 ? 'warn' : 'ok'];
    return [fa_num((int)floor($mins / 1440)) . ' روز', 'bad'];
};

/* پاسخ‌های آماده */
$CANNED = [
    '🙏 سلام و تشکر'   => 'سلام وقت بخیر 🙏' . "\n" . 'از صبر و شکیبایی شما سپاسگزاریم. درخواست شما در حال بررسی است.',
    '🔍 در حال بررسی' => 'درخواست شما به تیم فنی ارجاع شد و در حال بررسی است. نتیجه را در همین تیکت اطلاع می‌دهیم.',
    '🔄 تعویض کانفیگ'  => 'کانفیگ شما بازنشانی شد. لطفاً در برنامه دکمهٔ Update Subscription را بزنید و دوباره امتحان کنید.',
    '📶 راهنمای اتصال'  => 'لطفاً این موارد را امتحان کنید:' . "\n" . '۱) سرور دیگری از داخل همان لینک ساب' . "\n" . '۲) به‌روزرسانی ساب در برنامه' . "\n" . '۳) تغییر اینترنت (وای‌فای ↔ دیتا)',
    '💳 بررسی پرداخت'  => 'پرداخت شما بررسی و ثبت شد. موجودی کیف پول خود را بررسی کنید.',
    '✅ حل شد'           => 'مشکل برطرف شد ✅' . "\n" . 'اگر مورد دیگری باقی مانده اطلاع دهید، در غیر این صورت تیکت بسته می‌شود.',
];

$imgTypes = ['photo', 'image'];
?>

<style>
/* ===== Tickets Studio ===== */
.tk-hero{
  position:relative; overflow:hidden; padding:var(--s5) var(--s4);
  border:1px solid var(--border); border-radius:var(--r-xl);
  background:linear-gradient(155deg, rgba(255,169,46,.14), rgba(91,140,255,.10) 45%, transparent 75%), var(--surface);
  box-shadow:var(--shadow);
}
.tk-glow{ position:absolute; border-radius:50%; filter:blur(60px); opacity:.5; pointer-events:none; }
.tk-glow.g1{ width:225px; height:225px; background:rgba(255,169,46,.30); inset-block-start:-92px; inset-inline-end:-58px; }
.tk-glow.g2{ width:185px; height:185px; background:rgba(91,140,255,.30); inset-block-end:-92px; inset-inline-start:-52px; }
.tk-htop{ position:relative; display:flex; align-items:flex-start; gap:var(--s3); flex-wrap:wrap; }
.tk-hic{
  width:52px; height:52px; flex:none; display:grid; place-items:center; font-size:26px;
  border-radius:var(--r-lg); background:var(--grad-soft, var(--accent-soft)); border:1px solid var(--border);
  box-shadow:var(--glow);
}
.tk-htt h2{ margin:0; font-size:17px; }
.tk-htt p{ margin:4px 0 0; font-size:12px; color:var(--muted); line-height:1.8; max-width:56ch; }

.tk-cells{ position:relative; display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:var(--s2); margin-top:var(--s4); }
.tk-cell{
  display:flex; flex-direction:column; align-items:center; gap:2px; padding:var(--s3) var(--s2);
  background:var(--surface-2); border:1px solid var(--border); border-radius:var(--r);
  transition:transform .18s, border-color .18s;
}
.tk-cell:hover{ transform:translateY(-3px); border-color:var(--accent); }
.tk-cell .i{ font-size:17px; }
.tk-cell .v{ font-family:var(--font-num); font-size:18px; font-weight:800; text-align:center; }
.tk-cell .l{ font-size:10.5px; color:var(--muted); text-align:center; }
.tk-cell.o .v{ color:var(--orange); } .tk-cell.g .v{ color:var(--green); }
.tk-cell.r .v{ color:var(--red); }    .tk-cell.b .v{ color:var(--accent); }
.tk-cell.c .v{ color:var(--cyan); }

.tk-rate{ position:relative; margin-top:var(--s4); }
.tk-rate-t{ display:flex; justify-content:space-between; font-size:12px; color:var(--muted); margin-bottom:6px; }
.tk-rate-t b{ color:var(--green); font-family:var(--font-num); }
.tk-rate-bar{ height:8px; border-radius:99px; background:var(--surface-3); overflow:hidden; }
.tk-rate-bar span{ display:block; height:100%; border-radius:99px; background:linear-gradient(90deg,var(--green),var(--cyan)); transition:width .6s ease; }

.tk-nav{ display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:var(--s2); margin-top:var(--s3); }
.tk-nv{
  display:flex; align-items:center; justify-content:center; gap:8px; padding:12px var(--s2);
  background:var(--surface); border:1px solid var(--border); border-radius:var(--r);
  color:var(--muted); font-size:12.5px; font-weight:700; text-decoration:none;
  transition:transform .18s, border-color .18s, color .18s, background .18s;
}
.tk-nv:hover{ transform:translateY(-2px); color:var(--text); border-color:var(--accent); }
.tk-nv.on{ color:#fff; background:var(--grad, var(--accent)); border-color:transparent; box-shadow:var(--glow); }
.tk-nv .n{ min-width:20px; padding:1px 6px; border-radius:99px; font-family:var(--font-num); font-size:11px; background:var(--surface-3); color:var(--text); }
.tk-nv.on .n{ background:rgba(255,255,255,.22); color:#fff; }

/* ---- گفتگو ---- */
.tk-conv{ margin-top:var(--s3); border:1px solid var(--accent); border-radius:var(--r-xl); overflow:hidden; background:var(--surface); box-shadow:var(--shadow-lg); }
.tk-chead{
  display:flex; align-items:center; gap:var(--s3); flex-wrap:wrap; padding:var(--s4);
  background:linear-gradient(135deg, rgba(91,140,255,.14), transparent), var(--surface-2);
  border-bottom:1px solid var(--border);
}
.tk-cava{
  width:44px; height:44px; flex:none; border-radius:14px; display:grid; place-items:center; font-size:20px;
  background:var(--grad-soft, var(--accent-soft)); border:1px solid var(--border);
}
.tk-cttl{ flex:1; min-width:0; }
.tk-cttl h3{ margin:0; font-size:14.5px; }
.tk-cttl .s{ font-size:11.5px; color:var(--muted); margin-top:3px; }
.tk-age{ font-size:10.5px; padding:3px 9px; border-radius:99px; background:var(--surface-3); color:var(--muted); }
.tk-age.ok{ background:var(--green-soft); color:var(--green); }
.tk-age.warn{ background:var(--orange-soft); color:var(--orange); }
.tk-age.bad{ background:var(--red-soft); color:var(--red); }

.tk-thread{ max-height:520px; overflow-y:auto; padding:var(--s4); background:var(--bg-soft); display:flex; flex-direction:column; gap:var(--s3); }
.tk-m{ display:flex; gap:9px; max-width:82%; }
.tk-m.me{ margin-inline-start:auto; flex-direction:row-reverse; }
.tk-mav{
  width:31px; height:31px; flex:none; border-radius:50%; display:grid; place-items:center; font-size:14px;
  background:var(--surface-3); border:1px solid var(--border);
}
.tk-m.me .tk-mav{ background:var(--accent-soft); border-color:var(--accent); }
.tk-mb{ min-width:0; }
.tk-mw{ display:flex; gap:8px; align-items:baseline; font-size:10.5px; color:var(--muted); margin-bottom:4px; }
.tk-m.me .tk-mw{ flex-direction:row-reverse; }
.tk-mw .n{ font-weight:700; color:var(--text); font-size:11.5px; }
.tk-mt{
  padding:10px 13px; border-radius:14px 14px 14px 4px; background:var(--surface-2);
  border:1px solid var(--border); font-size:12.5px; line-height:2; word-break:break-word;
}
.tk-m.me .tk-mt{
  border-radius:14px 14px 4px 14px;
  background:linear-gradient(135deg, rgba(91,140,255,.20), rgba(139,92,246,.14));
  border-color:var(--accent);
}
.tk-mf{ margin-top:7px; }
.tk-mf img{ display:block; max-width:100%; max-height:230px; border-radius:10px; border:1px solid var(--border); cursor:zoom-in; }
.tk-file{
  display:inline-flex; align-items:center; gap:8px; padding:9px 13px; border-radius:10px;
  background:var(--surface-3); border:1px solid var(--border); color:var(--accent-text);
  font-size:12px; text-decoration:none;
}
.tk-file:hover{ border-color:var(--accent); }

/* ---- جعبه پاسخ ---- */
.tk-reply{ padding:var(--s4); border-top:1px solid var(--border); background:var(--surface); }
.tk-canned{ display:flex; gap:5px; flex-wrap:wrap; margin-bottom:var(--s3); }
.tk-cn{
  padding:5px 11px; border-radius:99px; cursor:pointer; font-family:inherit; font-size:11.5px;
  background:var(--surface-2); border:1px solid var(--border); color:var(--muted);
  transition:border-color .18s, color .18s, transform .18s;
}
.tk-cn:hover{ border-color:var(--accent); color:var(--accent-text); transform:translateY(-2px); }

/* ---- لیست تیکت ---- */
.tk-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:var(--s3); margin-top:var(--s3); }
.tk-card{
  position:relative; display:flex; flex-direction:column; overflow:hidden;
  background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
  transition:transform .18s, border-color .18s, box-shadow .18s;
}
.tk-card:hover{ transform:translateY(-3px); border-color:var(--accent); box-shadow:var(--shadow-lg); }
.tk-card.sel{ border-color:var(--green); box-shadow:0 0 0 1px var(--green); }
.tk-card .strip{ height:3px; background:var(--surface-3); }
.tk-card.s-open .strip{ background:linear-gradient(90deg,var(--orange),var(--red)); }
.tk-card.s-answered .strip{ background:linear-gradient(90deg,var(--green),var(--cyan)); }
.tk-ctop{ display:flex; align-items:flex-start; gap:var(--s3); padding:var(--s3); }
.tk-cw{ flex:1; min-width:0; }
.tk-cw .t{ font-weight:800; font-size:13px; line-height:1.6; }
.tk-cw .s{ font-size:11px; color:var(--muted); margin-top:2px; }
.tk-tags{ display:flex; gap:5px; flex-wrap:wrap; padding:0 var(--s3) var(--s3); }
.tk-tag{ font-size:10.5px; padding:3px 9px; border-radius:99px; background:var(--surface-3); color:var(--muted); }
.tk-cact{ margin-top:auto; display:flex; gap:6px; padding:var(--s3); border-top:1px solid var(--border-soft); background:var(--surface-2); }
.tk-pick{ position:absolute; inset-block-start:10px; inset-inline-start:10px; z-index:3; }
.tk-pick input{ width:18px; height:18px; cursor:pointer; accent-color:var(--green); }

.tk-bulk{
  position:sticky; inset-block-start:8px; z-index:20;
  display:flex; align-items:center; gap:var(--s2); flex-wrap:wrap; margin-top:var(--s3); padding:var(--s3);
  background:var(--surface-2); border:1px solid var(--accent); border-radius:var(--r); box-shadow:var(--shadow-lg);
}
.tk-bulk[hidden]{ display:none; }
.tk-bulk .cnt{ font-family:var(--font-num); font-weight:800; color:var(--accent); }

.tk-bar{
  display:flex; align-items:center; gap:var(--s2); flex-wrap:wrap; margin-top:var(--s3); padding:var(--s3);
  background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
}
.tk-seg{ display:inline-flex; background:var(--surface-2); border:1px solid var(--border); border-radius:var(--r-pill); padding:3px; }
.tk-seg button{
  border:0; background:transparent; color:var(--muted); cursor:pointer; font-family:inherit;
  padding:6px 14px; border-radius:var(--r-pill); font-size:12px; font-weight:700;
  transition:background .18s, color .18s;
}
.tk-seg button.on{ background:var(--grad, var(--accent)); color:#fff; }

@media (max-width:1100px){
  .tk-cells{ grid-template-columns:repeat(3,minmax(0,1fr)); }
}
@media (max-width:640px){
  .tk-cells{ grid-template-columns:repeat(2,minmax(0,1fr)); }
  .tk-nav{ grid-template-columns:repeat(2,minmax(0,1fr)); }
  .tk-grid{ grid-template-columns:1fr; }
  .tk-m{ max-width:94%; }
}
</style>

<div class="tk-hero">
  <span class="tk-glow g1"></span><span class="tk-glow g2"></span>

  <div class="tk-htop">
    <div class="tk-hic">🆘</div>
    <div class="tk-htt">
      <h2>مرکز پشتیبانی</h2>
      <p>پاسخ‌گویی به تیکت‌ها همراه با مشاهدهٔ تصاویر و فایل‌های کاربر، پاسخ‌های آماده و پایش زمان انتظار. هر پاسخ بلافاصله به تلگرام کاربر ارسال می‌شود.</p>
    </div>
  </div>

  <div class="tk-cells">
    <div class="tk-cell <?= $cOpen > 0 ? 'r' : 'g' ?>"><span class="i">🕓</span><span class="v"><?= fa_num($cOpen) ?></span><span class="l">باز — نیاز به پاسخ</span></div>
    <div class="tk-cell g"><span class="i">✅</span><span class="v"><?= fa_num($cAns) ?></span><span class="l">پاسخ داده شده</span></div>
    <div class="tk-cell b"><span class="i">🔒</span><span class="v"><?= fa_num($cCls) ?></span><span class="l">بسته شده</span></div>
    <div class="tk-cell b"><span class="i">📊</span><span class="v"><?= fa_num($cAll) ?></span><span class="l">کل تیکت‌ها</span></div>
    <div class="tk-cell c"><span class="i">💬</span><span class="v"><?= fa_num($cMsgs) ?></span><span class="l">کل پیام‌ها</span></div>
    <div class="tk-cell c"><span class="i">📎</span><span class="v"><?= fa_num($cAtts) ?></span><span class="l">فایل پیوست</span></div>
    <div class="tk-cell o"><span class="i">📅</span><span class="v"><?= fa_num($cToday) ?></span><span class="l">تیکت امروز</span></div>
    <div class="tk-cell b"><span class="i">🧮</span><span class="v"><?= fa_num((string)$avgMsg) ?></span><span class="l">میانگین پیام هر تیکت</span></div>
    <div class="tk-cell <?= $waitBad ? 'r' : 'o' ?>"><span class="i">⏱</span><span class="v"><?= $waitTxt ?></span><span class="l">انتظار قدیمی‌ترین</span></div>
  </div>

  <div class="tk-rate">
    <div class="tk-rate-t">
      <span>نرخ رسیدگی به تیکت‌ها</span>
      <b><?= fa_num($closedPct) ?>٪ · <?= fa_num($cAns + $cCls) ?> از <?= fa_num($cAll) ?></b>
    </div>
    <div class="tk-rate-bar"><span style="width:<?= (int)$closedPct ?>%"></span></div>
  </div>
</div>

<?php if ($cOpen > 0 && $waitBad): ?>
  <div class="alert a-warn mt3">⚠️ قدیمی‌ترین تیکت باز بیش از <b><?= $waitTxt ?></b> منتظر پاسخ است. رسیدگی به آن را در اولویت قرار دهید.</div>
<?php endif; ?>

<?php if ($T):
    [$ageTxt, $ageCls] = $ageOf((string)$T['updated_at']);
    $nAtt = 0;
    foreach ($msgs as $mm) { if (trim((string)($mm['file_id'] ?? '')) !== '') $nAtt++; }
?>
  <div class="tk-conv">
    <div class="tk-chead">
      <div class="tk-cava">🎫</div>
      <div class="tk-cttl">
        <h3>تیکت #<?= fa_num((int)$T['id']) ?> — <?= h((string)$T['subject']) ?></h3>
        <div class="s">
          <?= h((string)($T['first_name'] ?: 'کاربر')) ?>
          · <a class="mono" href="index.php?p=users&u=<?= (int)$T['uid'] ?>"><?= fa_num((string)$T['utg']) ?></a>
          · <?= fa_num(count($msgs)) ?> پیام<?= $nAtt ? ' · 📎 ' . fa_num($nAtt) . ' پیوست' : '' ?>
          · آخرین فعالیت: <?= h(to_jalali((string)$T['updated_at'], true)) ?>
        </div>
      </div>
      <span class="tk-age <?= h($ageCls) ?>">⏱ <?= $ageTxt ?></span>
      <?= badge((string)$T['status']) ?>
      <a class="btn btn-sm btn-ghost" href="index.php?p=tickets">← بازگشت</a>
    </div>

    <div class="tk-thread" id="tkThread">
      <?php foreach ($msgs as $m):
          $isAdmin = (string)$m['sender'] === 'admin';
          $fid     = (string)($m['file_id'] ?? '');
          $ftype   = (string)($m['file_type'] ?? '');
      ?>
        <div class="tk-m <?= $isAdmin ? 'me' : '' ?>">
          <div class="tk-mav"><?= $isAdmin ? '🛠' : '👤' ?></div>
          <div class="tk-mb">
            <div class="tk-mw">
              <span class="n"><?= $isAdmin ? 'پشتیبانی' : h((string)($T['first_name'] ?: 'کاربر')) ?></span>
              <span><?= h(to_jalali((string)$m['created_at'], true)) ?></span>
            </div>
            <?php if (trim((string)$m['text']) !== ''): ?>
              <div class="tk-mt"><?= nl2br(h((string)$m['text'])) ?></div>
            <?php endif; ?>
            <?php if ($fid !== ''): $url = 'index.php?p=tickets&t=' . (int)$T['id'] . '&att=' . (int)$m['id']; ?>
              <div class="tk-mf">
                <?php if ($ftype === '' || in_array($ftype, $imgTypes, true)): ?>
                  <img src="<?= h($url) ?>" alt="پیوست کاربر" loading="lazy" data-zoom="<?= h($url) ?>">
                  <div class="muted" style="font-size:11px;margin-top:4px">
                    🖼 برای بزرگ‌نمایی کلیک کنید ·
                    <a href="<?= h($url) ?>&dl=1">دانلود</a>
                  </div>
                <?php else: ?>
                  <a class="tk-file" href="<?= h($url) ?>&dl=1">
                    <span><?= $ftype === 'video' ? '🎥' : (($ftype === 'voice' || $ftype === 'audio') ? '🎧' : '📎') ?></span>
                    <span>دانلود فایل پیوست (<?= h($ftype) ?>)</span>
                  </a>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$msgs): ?><div class="empty"><div class="ic">💬</div>پیامی ثبت نشده.</div><?php endif; ?>
    </div>

    <?php if ((string)$T['status'] !== 'closed' && can('tickets.reply')): ?>
      <div class="tk-reply">
        <div class="tk-canned">
          <?php foreach ($CANNED as $lbl => $body): ?>
            <button type="button" class="tk-cn" data-tk-canned="<?= h($body) ?>"><?= h($lbl) ?></button>
          <?php endforeach; ?>
        </div>

        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="reply">
          <input type="hidden" name="id" value="<?= (int)$T['id'] ?>">
          <div class="field">
            <label>پاسخ شما</label>
            <textarea name="text" id="tkText" rows="4" placeholder="پاسخ به کاربر در تلگرام ارسال می‌شود…"></textarea>
            <div class="hint">روی پاسخ‌های آمادهٔ بالا کلیک کنید تا متن درج شود · <span id="tkLen">۰</span> کاراکتر</div>
          </div>
          <div class="form-grid g2">
            <div class="field">
              <label>📎 فایل پیوست (اختیاری)</label>
              <input type="file" name="att" accept="image/*,video/*,application/pdf,.zip,.txt">
              <div class="hint">حداکثر ۲۰ مگابایت – مستقیم برای کاربر ارسال می‌شود</div>
            </div>
            <div class="field" style="align-self:end">
              <button class="btn btn-primary btn-block">📤 ارسال پاسخ</button>
            </div>
          </div>
        </form>
      </div>
    <?php elseif ((string)$T['status'] === 'closed'): ?>
      <div class="tk-reply"><div class="alert a-info" style="margin:0">این تیکت بسته شده است.</div></div>
    <?php endif; ?>

    <div class="tk-reply" style="border-top:1px solid var(--border-soft);background:var(--surface-2)">
      <div class="row" style="flex-wrap:wrap;gap:6px">
        <?php if ($tkHasPrio && can('tickets.reply')): ?>
          <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0"><?= csrf_field() ?>
            <input type="hidden" name="act" value="tkmeta"><input type="hidden" name="id" value="<?= (int)$T['id'] ?>">
            <select name="priority" style="padding:6px 8px;border-radius:8px;border:1px solid var(--border-soft);background:var(--surface-1)">
              <?php foreach ($tkPrioLabel as $pk => $plb): ?>
                <option value="<?= h($pk) ?>"<?= (string)($T['priority'] ?? 'normal') === $pk ? ' selected' : '' ?>>اولویت: <?= h($plb) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="category" maxlength="32" placeholder="دسته (اختیاری)" value="<?= h((string)($T['category'] ?? '')) ?>" style="padding:6px 8px;border-radius:8px;border:1px solid var(--border-soft);background:var(--surface-1);max-width:150px">
            <button class="btn btn-sm">ثبت اولویت</button>
          </form>
        <?php endif; ?>
        <?php if (can('tickets.close')): ?>
          <?php if ((string)$T['status'] !== 'closed'): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?>
              <input type="hidden" name="act" value="close"><input type="hidden" name="id" value="<?= (int)$T['id'] ?>">
              <button class="btn btn-sm">✅ بستن تیکت</button></form>
          <?php else: ?>
            <form method="post" style="display:inline"><?= csrf_field() ?>
              <input type="hidden" name="act" value="open"><input type="hidden" name="id" value="<?= (int)$T['id'] ?>">
              <button class="btn btn-sm">🔓 باز کردن مجدد</button></form>
          <?php endif; ?>
        <?php endif; ?>
        <a class="btn btn-sm" href="index.php?p=users&u=<?= (int)$T['uid'] ?>">👤 پروندهٔ کاربر</a>
        <?php if (can('tickets.delete')): ?>
          <form method="post" style="display:inline" data-confirm="تیکت و پیام‌هایش حذف شوند؟"><?= csrf_field() ?>
            <input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= (int)$T['id'] ?>">
            <button class="btn btn-red btn-sm">🗑 حذف تیکت</button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($tkHasPrio): $tkQs = 'index.php?p=tickets' . ($fStatus !== '' ? '&amp;status=' . urlencode($fStatus) : ''); ?>
<div class="tk-nav">
  <a class="tk-nv <?= $fPrio === '' ? 'on' : '' ?>" href="<?= $tkQs ?>">⚑ همهٔ اولویت‌ها</a>
  <a class="tk-nv <?= $fPrio === 'urgent' ? 'on' : '' ?>" href="<?= $tkQs ?>&amp;prio=urgent">⚡ فوری <span class="n"><?= fa_num($cUrg) ?></span></a>
  <a class="tk-nv <?= $fPrio === 'high' ? 'on' : '' ?>" href="<?= $tkQs ?>&amp;prio=high">▲ زیاد <span class="n"><?= fa_num($cHigh) ?></span></a>
  <a class="tk-nv <?= $fPrio === 'normal' ? 'on' : '' ?>" href="<?= $tkQs ?>&amp;prio=normal">عادی</a>
  <a class="tk-nv <?= $fPrio === 'low' ? 'on' : '' ?>" href="<?= $tkQs ?>&amp;prio=low">کم</a>
</div>
<?php endif; ?>

<div class="tk-nav">
  <a class="tk-nv <?= $fStatus === '' ? 'on' : '' ?>" href="index.php?p=tickets">📂 همه <span class="n"><?= fa_num($cAll) ?></span></a>
  <a class="tk-nv <?= $fStatus === 'open' ? 'on' : '' ?>" href="index.php?p=tickets&amp;status=open">🕓 باز <span class="n"><?= fa_num($cOpen) ?></span></a>
  <a class="tk-nv <?= $fStatus === 'answered' ? 'on' : '' ?>" href="index.php?p=tickets&amp;status=answered">✅ پاسخ داده شده <span class="n"><?= fa_num($cAns) ?></span></a>
  <a class="tk-nv <?= $fStatus === 'closed' ? 'on' : '' ?>" href="index.php?p=tickets&amp;status=closed">🔒 بسته <span class="n"><?= fa_num($cCls) ?></span></a>
</div>

<div class="tk-bar">
  <div class="tk-seg">
    <button type="button" class="on" data-tk-view="grid">▦ کارتی</button>
    <button type="button" data-tk-view="table">☰ جدولی</button>
  </div>
  <input type="search" style="max-width:230px;margin-inline-start:auto" id="tkFind" placeholder="🔎 جستجوی کاربر یا موضوع…">
  <span class="hint" id="tkCount"><?= fa_num(count($rows)) ?> تیکت</span>
</div>

<?php if (!$rows): ?>
  <div class="card mt3"><div class="empty"><div class="ic">🆘</div>تیکتی در این دسته وجود ندارد.</div></div>
<?php else: ?>

<form method="post" id="tkBulkForm">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="bulk">
  <input type="hidden" name="mode" id="tkMode" value="">

  <?php if (can('tickets.close') || can('tickets.delete')): ?>
    <div class="tk-bulk" id="tkBulk" hidden>
      <span>✔️ <span class="cnt" id="tkCnt">۰</span> تیکت انتخاب شده</span>
      <?php if (can('tickets.close')): ?>
        <button class="btn btn-green btn-sm" type="button" data-tk-bulk="close">✅ بستن گروهی</button>
        <button class="btn btn-sm" type="button" data-tk-bulk="open">🔓 باز کردن</button>
      <?php endif; ?>
      <?php if (can('tickets.delete')): ?>
        <button class="btn btn-red btn-sm" type="button" data-tk-bulk="del">🗑 حذف</button>
      <?php endif; ?>
      <button class="btn btn-sm btn-ghost" type="button" id="tkNone">لغو انتخاب</button>
    </div>
  <?php endif; ?>

  <!-- نمای کارتی -->
  <div class="tk-grid" id="tkGrid">
    <?php foreach ($rows as $t):
        [$aTxt, $aCls] = $ageOf((string)$t['updated_at']);
        $st  = (string)$t['status'];
        $hay = mb_strtolower((string)($t['first_name'] ?? '') . ' ' . (string)$t['subject'] . ' ' . (string)$t['utg']);
    ?>
      <div class="tk-card s-<?= h($st) ?>" data-tk-card data-hay="<?= h($hay) ?>">
        <span class="strip"></span>
        <?php if (can('tickets.close') || can('tickets.delete')): ?>
          <label class="tk-pick"><input type="checkbox" name="ids[]" value="<?= (int)$t['id'] ?>" data-tk-box></label>
        <?php endif; ?>

        <div class="tk-ctop">
          <div class="tk-cava" style="width:38px;height:38px;font-size:17px">🎫</div>
          <div class="tk-cw">
            <div class="t"><?= h((string)$t['subject']) ?></div>
            <div class="s">#<?= fa_num((int)$t['id']) ?> · <?= h((string)($t['first_name'] ?: 'کاربر')) ?>
              · <span class="mono"><?= fa_num((string)$t['utg']) ?></span></div>
          </div>
          <?= badge($st) ?>
        </div>

        <div class="tk-tags">
          <?php $pr = (string)($t['priority'] ?? 'normal'); if ($pr !== '' && $pr !== 'normal' && isset($tkPrioLabel[$pr])): ?>
            <span class="tk-tag" style="<?= $pr === 'urgent' ? 'background:#fee2e2;color:#b91c1c' : ($pr === 'high' ? 'background:#ffedd5;color:#c2410c' : '') ?>">⚡ <?= h($tkPrioLabel[$pr]) ?></span>
          <?php endif; ?>
          <?php $ct = trim((string)($t['category'] ?? '')); if ($ct !== ''): ?><span class="tk-tag">▦ <?= h($ct) ?></span><?php endif; ?>
          <span class="tk-tag">💬 <?= fa_num((int)$t['msgs']) ?> پیام</span>
          <?php if ((int)$t['atts'] > 0): ?><span class="tk-tag">📎 <?= fa_num((int)$t['atts']) ?> پیوست</span><?php endif; ?>
          <span class="tk-age <?= h($aCls) ?>">⏱ <?= $aTxt ?></span>
          <span class="tk-tag"><?= h(to_jalali((string)$t['updated_at'], true)) ?></span>
        </div>

        <div class="tk-cact">
          <a class="btn btn-sm <?= $st === 'open' ? 'btn-primary' : '' ?>" href="index.php?p=tickets&amp;t=<?= (int)$t['id'] ?>">👁 مشاهده و پاسخ</a>
          <a class="btn btn-sm btn-ghost" href="index.php?p=users&amp;u=<?= (int)$t['uid'] ?>">👤 پرونده</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- نمای جدولی -->
  <div class="card mt3" id="tkTableWrap" hidden>
    <div class="table-wrap">
      <table id="tkTable" class="responsive">
        <thead><tr><th>#</th><th>کاربر</th><th>موضوع</th><th>پیام</th><th>پیوست</th><th>وضعیت</th><th>سن</th><th>آخرین فعالیت</th><th data-l="">عملیات</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $t): [$aTxt, $aCls] = $ageOf((string)$t['updated_at']); ?>
          <tr data-hay="<?= h(mb_strtolower((string)($t['first_name'] ?? '') . ' ' . (string)$t['subject'] . ' ' . (string)$t['utg'])) ?>">
            <td class="mono"><?= fa_num((int)$t['id']) ?></td>
            <td>
              <b><?= h((string)($t['first_name'] ?: 'کاربر')) ?></b>
              <div class="muted mono" style="font-size:11.5px"><?= fa_num((string)$t['utg']) ?></div>
            </td>
            <td><?= h((string)$t['subject']) ?></td>
            <td class="mono"><?= fa_num((int)$t['msgs']) ?></td>
            <td><?= (int)$t['atts'] > 0 ? '📎 ' . fa_num((int)$t['atts']) : '—' ?></td>
            <td><?= badge((string)$t['status']) ?></td>
            <td><span class="tk-age <?= h($aCls) ?>"><?= $aTxt ?></span></td>
            <td class="muted" style="font-size:12px"><?= h(to_jalali((string)$t['updated_at'], true)) ?></td>
            <td class="acts">
              <a class="btn btn-sm <?= (string)$t['status'] === 'open' ? 'btn-primary' : '' ?>" href="index.php?p=tickets&amp;t=<?= (int)$t['id'] ?>">👁 مشاهده</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>
<?php endif; ?>

<script>
/* ===== Tickets Studio v1 ===== */
(function () {
  'use strict';

  function faNum(n) {
    var d = '۰۱۲۳۴۵۶۷۸۹';
    return String(n).replace(/[0-9]/g, function (x) { return d[+x]; });
  }

  /* -------- تغییر نما -------- */
  var grid  = document.getElementById('tkGrid');
  var table = document.getElementById('tkTableWrap');
  var KEY   = 'srbotTkView';

  function setView(v) {
    if (!grid || !table) return;
    var isT = v === 'table';
    grid.hidden  = isT;
    table.hidden = !isT;
    document.querySelectorAll('[data-tk-view]').forEach(function (b) {
      b.classList.toggle('on', b.getAttribute('data-tk-view') === v);
    });
    try { localStorage.setItem(KEY, v); } catch (e) {}
  }
  document.querySelectorAll('[data-tk-view]').forEach(function (b) {
    b.addEventListener('click', function () { setView(b.getAttribute('data-tk-view')); });
  });
  try { setView(localStorage.getItem(KEY) || 'grid'); } catch (e) { setView('grid'); }

  /* -------- جستجو -------- */
  var find = document.getElementById('tkFind');
  var out  = document.getElementById('tkCount');
  if (find) {
    find.addEventListener('input', function () {
      var q = find.value.trim().toLowerCase();
      var n = 0;
      document.querySelectorAll('[data-hay]').forEach(function (el) {
        var hit = !q || (el.getAttribute('data-hay') || '').indexOf(q) !== -1;
        el.style.display = hit ? '' : 'none';
        if (hit && el.hasAttribute('data-tk-card')) n++;
      });
      if (out) out.textContent = faNum(n) + ' تیکت';
    });
  }

  /* -------- انتخاب گروهی -------- */
  var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-tk-box]'));
  var bulk  = document.getElementById('tkBulk');
  var cnt   = document.getElementById('tkCnt');
  var bform = document.getElementById('tkBulkForm');
  var bmode = document.getElementById('tkMode');

  function sync() {
    var on = boxes.filter(function (b) { return b.checked; });
    boxes.forEach(function (b) {
      var c = b.closest('[data-tk-card]');
      if (c) c.classList.toggle('sel', b.checked);
    });
    if (!bulk) return;
    bulk.hidden = on.length === 0;
    if (cnt) cnt.textContent = faNum(on.length);
  }
  boxes.forEach(function (b) { b.addEventListener('change', sync); });

  var none = document.getElementById('tkNone');
  if (none) none.addEventListener('click', function () {
    boxes.forEach(function (b) { b.checked = false; });
    sync();
  });

  document.querySelectorAll('[data-tk-bulk]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var m = btn.getAttribute('data-tk-bulk');
      var n = boxes.filter(function (b) { return b.checked; }).length;
      if (!n) return;
      if (m === 'del' && !window.confirm(faNum(n) + ' تیکت و تمام پیام‌هایشان حذف شوند؟')) return;
      if (bmode) bmode.value = m;
      if (bform) bform.submit();
    });
  });

  /* -------- پاسخ‌های آماده -------- */
  var ta  = document.getElementById('tkText');
  var len = document.getElementById('tkLen');

  function upLen() { if (len && ta) len.textContent = faNum(ta.value.length); }
  if (ta) ta.addEventListener('input', upLen);

  document.querySelectorAll('[data-tk-canned]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!ta) return;
      var txt = btn.getAttribute('data-tk-canned') || '';
      ta.value = ta.value.trim() ? (ta.value.replace(/\s+$/, '') + '\n\n' + txt) : txt;
      ta.focus();
      ta.selectionStart = ta.selectionEnd = ta.value.length;
      upLen();
    });
  });

  /* -------- اسکرول به آخرین پیام -------- */
  var th = document.getElementById('tkThread');
  if (th) th.scrollTop = th.scrollHeight;

  upLen();
  sync();
})();
</script>
