<?php

/**
 * مینی‌اپ تلگرام – رابط کاربری
 * تمام داده‌ها از api.php و پس از اعتبارسنجی initData خوانده می‌شوند.
 */

require __DIR__ . '/../app/bootstrap.php';

/* اجازه باز شدن داخل تلگرام (رفع خطای refused to connect) */
if (!headers_sent()) {
    header_remove('X-Frame-Options');
    header("Content-Security-Policy: frame-ancestors https://web.telegram.org https://*.telegram.org https://telegram.org https://*.t.me tg: 'self';");
}


$ready  = false;
$accent = '#6C8CFF';
$appName = 'اپلیکیشن';
$note   = '';
$bins   = [];   /* شش رقم اول کارت ← نام بانک (برای طرح هوشمند کارت) */

if (!app_installed()) {
    $note = 'ربات هنوز نصب نشده است.';
} else {
    try {
        boot();
        if ((string)DB::setting('miniapp_enabled', '1') !== '1') {
            $note = 'مینی‌اپ در حال حاضر توسط مدیر غیرفعال شده است.';
        } else {
            $ready   = true;
            $accent  = (string)DB::setting('miniapp_accent', '#6C8CFF');
            $shop    = (string)DB::setting('shop_title', 'فروشگاه کانفیگ');
            $custom  = trim((string)DB::setting('miniapp_title', ''));
            $appName = $custom !== '' ? $custom : $shop;
            $bins    = class_exists('CardAuth') ? CardAuth::BINS : [];
        }
    } catch (Throwable $e) {
        $note = 'خطای اتصال به پایگاه داده.';
    }
}

if (!preg_match('/^#[0-9A-Fa-f]{3,8}$/', $accent)) $accent = '#6C8CFF';

?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="<?= h($accent) ?>">
<title><?= h($appName) ?></title>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<style>
:root{
  --acc: <?= h($accent) ?>;
  --bg: #0f1115;
  --card: #171b24;
  --card2: #1d222d;
  --line: #2a3140;
  --tx: #eaf0fa;
  --dim: #b9c3d4;
  --mut: #7f8aa0;
  --ok: #34d399;
  --warn: #f59e0b;
  --err: #f87171;
  --r: 14px;
  --r2: 20px;
  --safe: env(safe-area-inset-bottom, 0px);
  --tap: 46px;
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;padding:0;height:100%}
body{
  background:var(--bg);color:var(--tx);
  font-family:"Vazirmatn","IRANSans",system-ui,-apple-system,"Segoe UI",sans-serif;
  font-size:14.5px;line-height:1.75;overflow-x:hidden;
  -webkit-font-smoothing:antialiased;
}
body.light{--bg:#f4f6fb;--card:#ffffff;--card2:#f0f3f9;--line:#e2e8f2;--tx:#141a24;--dim:#4c5666;--mut:#7b8598}
button,input,textarea,select{font-family:inherit;font-size:inherit;color:inherit}
button{cursor:pointer;border:0;background:none}
a{color:var(--acc);text-decoration:none}

/* ---------- اسکلت ---------- */
.app{display:flex;flex-direction:column;min-height:100%;max-width:620px;margin:0 auto}
.hdr{
  position:sticky;top:0;z-index:30;display:flex;align-items:center;gap:10px;
  padding:12px 14px;background:color-mix(in srgb, var(--bg) 88%, transparent);
  backdrop-filter:blur(14px);border-bottom:1px solid var(--line);
}
.ava{
  width:40px;height:40px;flex:0 0 40px;border-radius:50%;
  background:linear-gradient(135deg,var(--acc),#8b5cf6);
  display:grid;place-items:center;font-weight:700;font-size:15px;color:#fff;
}
.hdr-txt{min-width:0;flex:1}
.hdr-t{font-weight:700;font-size:14.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hdr-s{font-size:11.5px;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bal{
  display:flex;align-items:center;gap:6px;padding:8px 12px;border-radius:999px;
  background:var(--card2);border:1px solid var(--line);font-weight:700;font-size:12.5px;
  white-space:nowrap;min-height:38px;
}
.view{flex:1;padding:14px 14px calc(84px + var(--safe))}

/* ---------- نوار تب ---------- */
.tabs{
  position:fixed;bottom:0;left:0;right:0;z-index:40;display:flex;
  max-width:620px;margin:0 auto;
  padding:6px 6px calc(6px + var(--safe));
  background:color-mix(in srgb, var(--bg) 92%, transparent);
  backdrop-filter:blur(16px);border-top:1px solid var(--line);
}
.tabs button{
  flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;
  padding:7px 2px;border-radius:12px;color:var(--mut);font-size:10.5px;font-weight:600;
  min-height:var(--tap);transition:.18s;
}
.tabs button .ic{font-size:19px;line-height:1.1}
.tabs button.on{color:var(--acc);background:color-mix(in srgb, var(--acc) 14%, transparent)}

/* ---------- کارت‌ها ---------- */
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r2);padding:14px;margin-bottom:12px}
.card.tight{padding:12px}
.hero{
  position:relative;overflow:hidden;
  background:linear-gradient(135deg, color-mix(in srgb, var(--acc) 88%, #000) 0%, #8b5cf6 100%);
  color:#fff;border:0;
}
.hero:after{
  content:"";position:absolute;inset:auto -30px -60px auto;width:180px;height:180px;
  background:rgba(255,255,255,.14);border-radius:50%;
}
.hero .lbl{font-size:11.5px;opacity:.85}
.hero .big{font-size:25px;font-weight:700;margin:2px 0 10px;letter-spacing:-.4px}
.qa{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;position:relative;z-index:2}
.qa button{
  background:rgba(255,255,255,.17);border-radius:14px;padding:10px 4px;color:#fff;
  display:flex;flex-direction:column;align-items:center;gap:3px;font-size:10.5px;font-weight:600;
  min-height:var(--tap);transition:.15s;
}
.qa button:active{transform:scale(.95)}
.qa .ic{font-size:18px}

.grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.stat{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:11px;text-align:center}
.stat .v{font-size:17px;font-weight:700}
.stat .t{font-size:10.5px;color:var(--mut);margin-top:1px}

.sec-t{display:flex;align-items:center;justify-content:space-between;margin:16px 2px 9px;font-weight:700;font-size:13.5px}
.sec-t .more{font-size:11.5px;color:var(--acc);font-weight:600}

/* ---------- محصول ---------- */
.chips{display:flex;gap:7px;overflow-x:auto;padding:2px 0 10px;scrollbar-width:none}
.chips::-webkit-scrollbar{display:none}
.chip{
  flex:0 0 auto;padding:7px 14px;border-radius:999px;background:var(--card2);
  border:1px solid var(--line);font-size:12px;font-weight:600;color:var(--dim);white-space:nowrap;
}
.chip.on{background:var(--acc);border-color:var(--acc);color:#fff}
.prod{display:flex;gap:11px;align-items:flex-start}
.prod .pi{
  width:44px;height:44px;flex:0 0 44px;border-radius:13px;display:grid;place-items:center;font-size:20px;
  background:color-mix(in srgb, var(--acc) 16%, transparent);
}
.prod .pn{font-weight:700;font-size:14px}
.meta{display:flex;flex-wrap:wrap;gap:5px;margin-top:7px}
.tag{
  padding:3px 9px;border-radius:999px;background:var(--card2);border:1px solid var(--line);
  font-size:10.5px;color:var(--dim);font-weight:600;
}
.swz{display:flex;align-items:center;gap:6px;margin:2px 0 10px}
.swz-s{display:flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;
  border:1px solid var(--line);background:var(--card2);opacity:.5;transition:opacity .2s ease}
.swz-s b{width:18px;height:18px;border-radius:50%;display:grid;place-items:center;
  font-size:10.5px;background:var(--line)}
.swz-s span{font-size:11px;white-space:nowrap}
.swz-s.on{opacity:1;border-color:rgba(91,140,255,.42)}
.swz-s.on b{background:linear-gradient(135deg,#5B8CFF,#8B5CF6);color:#0B0E14}
.swz-s.dn b{background:rgba(47,212,143,.92);color:#06281a}
.swz>i{flex:1;height:1px;background:var(--line)}
.bcs{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 10px}
.bcx{font:inherit;font-size:11px;cursor:pointer;padding:4px 9px;border-radius:999px;
  border:1px solid var(--line);background:var(--card2);color:var(--dim)}
.bcx:active{transform:translateY(1px)}
.shead{display:flex;flex-direction:column;gap:3px;margin:0 0 9px}
.shead b{font-size:13.5px}
.shead i{font-style:normal;font-size:11.5px;color:var(--mut)}
.pnl{width:100%;display:flex;align-items:center;gap:10px;text-align:start;font:inherit;
  cursor:pointer;margin:0 0 8px;padding:11px;border-radius:16px;
  border:1px solid var(--line);background:var(--card);color:var(--tx)}
.pnl:active{transform:translateY(1px)}
.pnl .fg{width:40px;height:40px;flex:0 0 40px;border-radius:13px;display:grid;place-items:center;
  font-size:19px;background:var(--card2);border:1px solid var(--line)}
.pnl .tx{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.pnl .tx b{font-size:13.5px}
.pnl .tx i{font-style:normal;font-size:11px;color:var(--mut)}
.pnl .pr{display:flex;flex-direction:column;align-items:flex-end;gap:1px;font-size:9.5px;color:var(--mut)}
.pnl .pr b{font-size:12.5px;color:var(--acc)}
.pnl .ar{color:var(--mut);font-size:17px}
.sall{margin-top:4px}
.price-row{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:11px;padding-top:11px;border-top:1px dashed var(--line)}
.price{font-weight:700;font-size:15px}
.price .old{font-size:11px;color:var(--mut);text-decoration:line-through;margin-inline-start:6px;font-weight:400}

/* ---------- دکمه ---------- */
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:6px;
  padding:11px 18px;border-radius:13px;font-weight:700;font-size:13px;
  background:var(--acc);color:#fff;min-height:var(--tap);transition:.15s;
}
.btn:active{transform:scale(.97)}
.btn.sm{padding:8px 14px;font-size:12px;min-height:38px;border-radius:11px}
.btn.gh{background:var(--card2);color:var(--tx);border:1px solid var(--line)}
.btn.ok{background:var(--ok);color:#06281c}
.btn.dn{background:var(--err);color:#3a0d0f}
.btn.w{width:100%}
.btn[disabled]{opacity:.55;pointer-events:none}
.btn-row{display:flex;gap:8px;flex-wrap:wrap}
.btn-row .btn{flex:1}

/* ---------- فیلد ---------- */
.fld{margin-bottom:11px}
.fld label{display:block;font-size:11.5px;color:var(--dim);margin-bottom:5px;font-weight:600}
.fld input,.fld textarea,.fld select{
  width:100%;padding:12px 13px;border-radius:13px;background:var(--card2);
  border:1px solid var(--line);outline:none;min-height:var(--tap);
}
.fld input:focus,.fld textarea:focus{border-color:var(--acc)}
.fld textarea{min-height:96px;resize:vertical;line-height:1.8}
.hint{font-size:10.5px;color:var(--mut);margin-top:4px}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;direction:ltr;text-align:left}

/* ---------- کپی ---------- */
.copy{
  display:flex;align-items:center;gap:8px;padding:11px 12px;border-radius:13px;
  background:var(--card2);border:1px solid var(--line);margin-top:7px;
}
.copy code{
  flex:1;min-width:0;font-size:11px;direction:ltr;text-align:left;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
}
.copy button{flex:0 0 auto;padding:6px 11px;border-radius:9px;background:var(--acc);color:#fff;font-size:11px;font-weight:700}

/* ---------- نوار مصرف ---------- */
.bar{height:7px;border-radius:99px;background:var(--card2);overflow:hidden;margin-top:9px}
.bar i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,var(--ok),var(--acc));transition:width .5s}
.bar.hi i{background:linear-gradient(90deg,var(--warn),var(--err))}

/* ---------- بج و هشدار ---------- */
.bdg{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:10.5px;font-weight:700}
.bdg.g{background:color-mix(in srgb, var(--ok) 20%, transparent);color:var(--ok)}
.bdg.o{background:color-mix(in srgb, var(--warn) 20%, transparent);color:var(--warn)}
.bdg.r{background:color-mix(in srgb, var(--err) 20%, transparent);color:var(--err)}
.bdg.b{background:color-mix(in srgb, var(--acc) 20%, transparent);color:var(--acc)}
.alert{padding:12px 13px;border-radius:var(--r);font-size:12.5px;margin-bottom:12px;border:1px solid}
.alert.w{background:color-mix(in srgb, var(--warn) 12%, transparent);border-color:color-mix(in srgb, var(--warn) 34%, transparent)}
.alert.i{background:color-mix(in srgb, var(--acc) 12%, transparent);border-color:color-mix(in srgb, var(--acc) 34%, transparent)}
.alert.e{background:color-mix(in srgb, var(--err) 12%, transparent);border-color:color-mix(in srgb, var(--err) 34%, transparent)}

/* ---------- لیست ---------- */
.row{display:flex;align-items:center;gap:11px;padding:11px 0;border-bottom:1px solid var(--line)}
.row:last-child{border-bottom:0}
.row .ri{width:36px;height:36px;flex:0 0 36px;border-radius:11px;display:grid;place-items:center;font-size:16px;background:var(--card2)}
.row .rt{flex:1;min-width:0}
.row .rt b{display:block;font-size:13px;font-weight:600}
.row .rt span{font-size:10.5px;color:var(--mut)}
.row .rv{font-size:12.5px;font-weight:700;white-space:nowrap}
.rv.p{color:var(--ok)}
.rv.n{color:var(--err)}

/* ---------- گفتگو ---------- */
.msg{max-width:86%;padding:10px 13px;border-radius:16px;margin-bottom:9px;font-size:12.5px;line-height:1.8;white-space:pre-wrap;word-break:break-word}
.msg.me{margin-inline-start:auto;background:var(--acc);color:#fff;border-bottom-left-radius:5px}
.msg.ad{margin-inline-end:auto;background:var(--card2);border:1px solid var(--line);border-bottom-right-radius:5px}
.msg .tm{display:block;font-size:9.5px;opacity:.7;margin-top:3px}

/* ---------- شیت ---------- */
.sw{position:fixed;inset:0;z-index:60;display:none;background:rgba(0,0,0,.6);backdrop-filter:blur(3px)}
.sw.on{display:block}
.sh{
  position:absolute;left:0;right:0;bottom:0;max-width:620px;margin:0 auto;
  background:var(--bg);border-radius:24px 24px 0 0;border-top:1px solid var(--line);
  max-height:92vh;overflow-y:auto;padding:8px 15px calc(20px + var(--safe));
  animation:up .28s cubic-bezier(.2,.9,.25,1);
}
@keyframes up{from{transform:translateY(100%)}to{transform:translateY(0)}}
.sh-h{position:sticky;top:0;background:var(--bg);padding:9px 0 12px;z-index:2}
.sh-bar{width:40px;height:4px;border-radius:99px;background:var(--line);margin:0 auto 11px}
.sh-t{font-weight:700;font-size:15px;display:flex;align-items:center;justify-content:space-between;gap:10px}
.sh-x{width:32px;height:32px;border-radius:50%;background:var(--card2);display:grid;place-items:center;font-size:15px;flex:0 0 32px}

/* ---------- توست ---------- */
.toast{
  position:fixed;left:50%;bottom:calc(96px + var(--safe));transform:translateX(-50%) translateY(20px);
  z-index:90;padding:11px 18px;border-radius:14px;background:#111827;color:#fff;
  font-size:12.5px;font-weight:600;box-shadow:0 10px 34px rgba(0,0,0,.45);
  opacity:0;pointer-events:none;transition:.28s;max-width:88vw;text-align:center;
}
.toast.on{opacity:1;transform:translateX(-50%) translateY(0)}
.toast.g{background:#065f46}
.toast.r{background:#7f1d1d}

/* ---------- اسکلتون ---------- */
.sk{background:linear-gradient(90deg,var(--card) 25%,var(--card2) 37%,var(--card) 63%);
  background-size:400% 100%;animation:sh 1.3s infinite;border-radius:var(--r)}
@keyframes sh{0%{background-position:100% 0}100%{background-position:-100% 0}}
.sk-c{height:96px;margin-bottom:12px}
.empty{text-align:center;padding:44px 18px;color:var(--mut)}
.empty .e{font-size:44px;margin-bottom:10px;opacity:.65}
.empty b{display:block;color:var(--tx);font-size:14px;margin-bottom:5px}
.spin{width:17px;height:17px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:sp .7s linear infinite;display:inline-block}
@keyframes sp{to{transform:rotate(360deg)}}
.center{display:grid;place-items:center;min-height:70vh;text-align:center;padding:24px}

/* v11 ui */
:root{
  --num:"Vazirmatn","Segoe UI",system-ui,-apple-system,sans-serif;
  --d3:0 1px 0 rgba(255,255,255,.14) inset,0 -3px 0 rgba(0,0,0,.34) inset,0 8px 18px rgba(0,0,0,.40),0 3px 0 rgba(0,0,0,.45);
  --d3a:0 1px 0 rgba(255,255,255,.08) inset,0 2px 6px rgba(0,0,0,.35);
}
body{font-feature-settings:"ss01" 1,"tnum" 1,"lnum" 1}
.nb{display:inline-flex;align-items:baseline;gap:5px;direction:ltr;padding:5px 11px;border-radius:12px;
  background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.09);font-family:var(--num);
  font-variant-numeric:tabular-nums lining-nums;font-weight:800;letter-spacing:.4px;white-space:nowrap}
.nb .u{font-size:10.5px;font-weight:500;color:var(--mut)}
.nb.big{font-size:20px;padding:8px 14px}
.nb.g{color:#8ff0cd;border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.10)}
.nb.r{color:#ffb4b4;border-color:rgba(248,113,113,.35);background:rgba(248,113,113,.10)}
.nb.a{color:#c9d6ff;border-color:rgba(108,140,255,.45);background:rgba(108,140,255,.14)}
.btn{border-radius:15px;font-weight:700;box-shadow:var(--d3);
  background-image:linear-gradient(180deg,rgba(255,255,255,.13),rgba(0,0,0,.10));
  transition:transform .12s ease, box-shadow .16s ease, filter .16s ease}
.btn:active{transform:translateY(3px);box-shadow:var(--d3a)}
.btn.gh{box-shadow:var(--d3a);background-image:none}
.fld input,.fld select,.fld textarea{border-radius:13px;min-height:48px}
.fld input:focus,.fld select:focus,.fld textarea:focus{box-shadow:0 0 0 3px rgba(108,140,255,.20)}
.mono,.ltr,.fld input.mono,.fld input[type=number],
.fld input[inputmode=numeric],.fld input[inputmode=decimal],.fld input[inputmode=email]{
  direction:ltr !important;text-align:left;font-family:var(--num);
  font-variant-numeric:tabular-nums lining-nums;letter-spacing:.3px}
input[type=number]{-moz-appearance:textfield}
input[type=number]::-webkit-outer-spin-button,input[type=number]::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
.stat .v,.bal,.price,.amount,.money{font-variant-numeric:tabular-nums lining-nums;direction:ltr;unicode-bidi:isolate;display:inline-block}
img,svg,video,iframe{max-width:100%;height:auto}
html,body{max-width:100%;overflow-x:hidden}

/* ================= رابط سه‌بعدی ================= */
:root{
  --dp1:0 1px 0 rgba(255,255,255,.06) inset,0 8px 20px rgba(0,0,0,.32);
  --dp2:0 1px 0 rgba(255,255,255,.10) inset,0 -2px 0 rgba(0,0,0,.20) inset,0 14px 30px rgba(0,0,0,.40);
  --dp3:0 1px 0 rgba(255,255,255,.18) inset,0 -3px 0 rgba(0,0,0,.28) inset,0 22px 46px rgba(0,0,0,.48);
  --gloss:linear-gradient(180deg,rgba(255,255,255,.08),rgba(255,255,255,0) 44%,rgba(0,0,0,.10));
  --ez:cubic-bezier(.2,.9,.25,1);
}
body{
  background-image:
    radial-gradient(760px 420px at 100% -6%, color-mix(in srgb, var(--acc) 20%, transparent) 0%, transparent 62%),
    radial-gradient(560px 340px at -10% 8%, rgba(139,92,246,.16) 0%, transparent 60%);
  background-attachment:fixed;
}
body.light{
  --dp1:0 1px 0 rgba(255,255,255,.9) inset,0 6px 16px rgba(20,30,60,.10);
  --dp2:0 1px 0 rgba(255,255,255,.9) inset,0 10px 24px rgba(20,30,60,.13);
  --dp3:0 1px 0 rgba(255,255,255,.95) inset,0 18px 38px rgba(20,30,60,.16);
  --gloss:linear-gradient(180deg,rgba(255,255,255,.7),rgba(255,255,255,0) 50%,rgba(20,30,60,.04));
}

.card{box-shadow:var(--dp1);background-image:var(--gloss);transition:transform .18s var(--ez),box-shadow .18s var(--ez)}
.card.hero{box-shadow:var(--dp3)}
.stat{box-shadow:var(--dp1);background-image:var(--gloss)}
.hdr{box-shadow:0 6px 22px rgba(0,0,0,.22)}
.bal{box-shadow:var(--d3a);background-image:var(--gloss)}
.ava{box-shadow:0 6px 16px color-mix(in srgb, var(--acc) 45%, transparent),0 1px 0 rgba(255,255,255,.30) inset}
.tabs{box-shadow:0 -10px 30px rgba(0,0,0,.34)}
.tabs button{transition:transform .16s var(--ez),background .16s ease,color .16s ease}
.tabs button.on{transform:translateY(-3px);
  box-shadow:0 8px 18px color-mix(in srgb, var(--acc) 26%, transparent),0 1px 0 rgba(255,255,255,.14) inset}
.chip{box-shadow:var(--d3a);transition:transform .14s var(--ez),box-shadow .16s ease}
.chip:active{transform:translateY(2px)}
.chip.on{box-shadow:0 7px 16px color-mix(in srgb, var(--acc) 34%, transparent),0 1px 0 rgba(255,255,255,.22) inset}
.row .ri{box-shadow:var(--d3a);background-image:var(--gloss)}
.prod .pi{box-shadow:var(--d3a)}
.bar{box-shadow:0 1px 2px rgba(0,0,0,.35) inset}
.bar i{box-shadow:0 1px 0 rgba(255,255,255,.28) inset}
.copy{box-shadow:var(--d3a)}
.sh{box-shadow:0 -26px 60px rgba(0,0,0,.55)}
.qa button{box-shadow:0 6px 14px rgba(0,0,0,.22),0 1px 0 rgba(255,255,255,.20) inset;
  transition:transform .14s var(--ez)}
.qa button:active{transform:translateY(3px) scale(.97)}
.fld input,.fld select,.fld textarea{box-shadow:0 2px 6px rgba(0,0,0,.22) inset;
  transition:box-shadow .18s ease,border-color .18s ease}

/* ---------- گام‌شمار ---------- */
.stp{display:flex;align-items:center;gap:5px;margin:0 0 14px}
.stp .s{flex:1;display:flex;flex-direction:column;align-items:center;gap:5px;
  font-size:10px;color:var(--mut);font-weight:700;text-align:center}
.stp .n{width:29px;height:29px;border-radius:50%;display:grid;place-items:center;font-size:12px;
  background:var(--card2);border:1px solid var(--line);box-shadow:var(--d3a);transition:.2s var(--ez)}
.stp .s.on{color:var(--acc)}
.stp .s.on .n{background:var(--acc);color:#fff;border-color:transparent;transform:translateY(-2px);
  box-shadow:0 7px 16px color-mix(in srgb,var(--acc) 40%,transparent),0 1px 0 rgba(255,255,255,.3) inset}
.stp .s.dn{color:var(--ok)}
.stp .s.dn .n{background:color-mix(in srgb,var(--ok) 20%,transparent);color:var(--ok);
  border-color:color-mix(in srgb,var(--ok) 42%,transparent)}
.stp .ln{width:16px;height:2px;border-radius:9px;background:var(--line);margin-bottom:14px}

/* ---------- مبلغ بزرگ ---------- */
.amt3{position:relative;overflow:hidden;text-align:center;padding:20px 14px;
  border-radius:var(--r2);border:1px solid var(--line);
  background:linear-gradient(160deg, color-mix(in srgb,var(--acc) 20%,var(--card)) 0%, var(--card) 68%);
  box-shadow:var(--dp2);margin-bottom:12px}
.amt3:after{content:"";position:absolute;inset:auto -40px -70px auto;width:170px;height:170px;
  background:rgba(255,255,255,.07);border-radius:50%}
.amt3 .l{font-size:11px;color:var(--dim);position:relative;z-index:2}
.amt3 .v{font-size:30px;font-weight:800;letter-spacing:-.6px;margin-top:3px;position:relative;z-index:2;
  font-variant-numeric:tabular-nums lining-nums;direction:ltr;unicode-bidi:isolate;display:inline-block}
.amt3 .u{font-size:12px;color:var(--dim);font-weight:600;margin-inline-start:5px}

/* ---------- چیپ مبلغ ---------- */
.qg{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px}
.qg button{padding:11px 4px;border-radius:14px;background:var(--card2);border:1px solid var(--line);
  font-weight:700;font-size:12.5px;color:var(--dim);box-shadow:var(--d3a);
  transition:transform .14s var(--ez),box-shadow .16s ease;
  font-variant-numeric:tabular-nums lining-nums;min-height:44px}
.qg button:active{transform:translateY(3px);box-shadow:none}
.qg button.on{background:var(--acc);color:#fff;border-color:transparent;
  box-shadow:0 8px 18px color-mix(in srgb,var(--acc) 34%,transparent),0 1px 0 rgba(255,255,255,.24) inset}

/* ---------- کارت روش پرداخت ---------- */
.pmc{display:flex;align-items:center;gap:11px;width:100%;text-align:right;
  padding:13px;border-radius:var(--r2);background:var(--card);border:1px solid var(--line);
  margin-bottom:9px;box-shadow:var(--dp1);background-image:var(--gloss);
  transition:transform .16s var(--ez),box-shadow .18s ease,border-color .18s ease}
.pmc:active{transform:translateY(2px)}
.pmc .pi{width:44px;height:44px;flex:0 0 44px;border-radius:14px;display:grid;place-items:center;
  font-size:21px;background:color-mix(in srgb,var(--acc) 16%,transparent);box-shadow:var(--d3a)}
.pmc .pt{flex:1;min-width:0}
.pmc .pt b{display:block;font-size:13.5px;font-weight:700}
.pmc .pt span{display:block;font-size:10.5px;color:var(--mut);line-height:1.7}
.pmc .pk{width:22px;height:22px;flex:0 0 22px;border-radius:50%;border:2px solid var(--line);
  display:grid;place-items:center;font-size:11px;color:transparent;transition:.18s}
.pmc.on{border-color:color-mix(in srgb,var(--acc) 60%,transparent);
  box-shadow:0 10px 24px color-mix(in srgb,var(--acc) 22%,transparent),var(--dp1)}
.pmc.on .pk{background:var(--acc);border-color:var(--acc);color:#fff}

/* ---------- سربرگ داخلی ---------- */
.pgh{display:flex;align-items:center;gap:10px;margin:0 0 13px}
.pgh .bk{width:38px;height:38px;flex:0 0 38px;border-radius:13px;display:grid;place-items:center;
  font-size:16px;background:var(--card2);border:1px solid var(--line);box-shadow:var(--d3a);
  transition:transform .14s var(--ez)}
.pgh .bk:active{transform:translateY(2px)}
.pgh .tt{flex:1;min-width:0;font-weight:700;font-size:15.5px}
.pgh .tt span{display:block;font-size:10.5px;color:var(--mut);font-weight:500}

@media (prefers-reduced-motion: reduce){
  *{animation-duration:.001s !important;transition-duration:.001s !important}
}

/* =======================================================
   SR-BOT 3D Service Details + Product Card Polish (v6)
   ======================================================= */

.svc3d{
  position:relative;margin:2px 0 14px;padding:2px;border-radius:26px;
  background:linear-gradient(140deg,rgba(91,140,255,.55),rgba(139,92,246,.34) 42%,rgba(38,211,232,.30) 74%,rgba(255,255,255,.05));
  box-shadow:0 22px 46px -22px rgba(0,0,0,.85),0 6px 14px -8px rgba(0,0,0,.6),inset 0 1px 0 rgba(255,255,255,.13);
  transform-style:preserve-3d;transform:perspective(900px);
  transition:transform .28s cubic-bezier(.22,.9,.28,1),box-shadow .28s ease;
  will-change:transform;
}
.svc3d.wr{background:linear-gradient(140deg,rgba(245,158,11,.55),rgba(139,92,246,.26) 55%,rgba(255,255,255,.05));}
.svc3d.er{background:linear-gradient(140deg,rgba(248,113,113,.6),rgba(139,92,246,.24) 55%,rgba(255,255,255,.05));}
.svc3d>.in{
  position:relative;z-index:2;border-radius:24px;padding:16px 15px 15px;
  background:radial-gradient(120% 130% at 12% 0%,#232a39 0%,#181d28 46%,#12161f 100%);
  transform:translateZ(24px);
}
.svc3d>.glow{
  position:absolute;inset:-32% -12% auto -12%;height:78%;z-index:0;pointer-events:none;
  background:radial-gradient(60% 100% at 50% 0%,rgba(91,140,255,.42),transparent 72%);filter:blur(20px);
}
.svc3d.wr>.glow{background:radial-gradient(60% 100% at 50% 0%,rgba(245,158,11,.4),transparent 72%);}
.svc3d.er>.glow{background:radial-gradient(60% 100% at 50% 0%,rgba(248,113,113,.44),transparent 72%);}
.svc3d>.sheen{
  position:absolute;inset:2px;z-index:3;border-radius:24px;pointer-events:none;opacity:.75;
  background:linear-gradient(115deg,transparent 32%,rgba(255,255,255,.11) 47%,transparent 60%);
}
.svc3d .hd{display:flex;align-items:center;gap:9px;margin-bottom:13px;transform:translateZ(16px);}
.svc3d .nmw{flex:1;min-width:0;text-align:left;direction:ltr;}
.svc3d .nm{
  display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  font-size:12px;color:#dbe4f5;padding:5px 10px;border-radius:11px;
  background:rgba(255,255,255,.055);border:1px solid rgba(255,255,255,.08);
  box-shadow:inset 0 1px 0 rgba(255,255,255,.07);
}
.chip3d{
  flex:0 0 auto;font-size:11px;font-weight:800;padding:6px 11px;border-radius:999px;
  box-shadow:0 6px 14px -8px rgba(0,0,0,.9),inset 0 1px 0 rgba(255,255,255,.18);
}
.chip3d.on{background:linear-gradient(180deg,#2fd48f,#18a86e);color:#04220f;}
.chip3d.off{background:linear-gradient(180deg,#4a5468,#333c4d);color:#e7ecf6;}

.ring3d{position:relative;width:132px;height:132px;margin:2px auto 14px;transform:translateZ(34px);}
.ring3d svg{width:100%;height:100%;transform:rotate(-90deg);filter:drop-shadow(0 10px 18px rgba(0,0,0,.6));}
.ring3d circle{fill:none;stroke-width:11;stroke-linecap:round;}
.ring3d .trk{stroke:rgba(255,255,255,.075);}
.ring3d .val{stroke:#5b8cff;transition:stroke-dashoffset .9s cubic-bezier(.22,.9,.28,1);}
.svc3d.wr .ring3d .val{stroke:#f59e0b;}
.svc3d.er .ring3d .val{stroke:#f87171;}
.ring3d .ctr{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;}
.ring3d .ctr b{font-size:25px;font-weight:900;color:#f2f6ff;text-shadow:0 3px 10px rgba(0,0,0,.6);}
.ring3d .ctr span{font-size:10px;color:var(--mut);}
.ring3d.inf .ctr b{font-size:31px;color:#2fd48f;}

.g3d{display:grid;grid-template-columns:repeat(2,1fr);gap:9px;transform:translateZ(18px);}
.t3d{
  position:relative;border-radius:16px;padding:11px 11px 10px;overflow:hidden;
  background:linear-gradient(180deg,rgba(255,255,255,.062),rgba(255,255,255,.017));
  border:1px solid rgba(255,255,255,.075);
  box-shadow:0 10px 20px -14px rgba(0,0,0,.9),inset 0 1px 0 rgba(255,255,255,.09);
}
.t3d::after{content:'';position:absolute;inset:0 0 auto 0;height:1px;background:linear-gradient(90deg,transparent,rgba(255,255,255,.25),transparent);}
.t3d .i{font-size:15px;display:block;margin-bottom:4px;filter:drop-shadow(0 3px 6px rgba(0,0,0,.5));}
.t3d .l{display:block;font-size:10px;color:var(--mut);margin-bottom:3px;}
.t3d .v{display:block;font-size:13.5px;font-weight:800;color:#eef3fd;}
.t3d.ok .v{color:#5ee2ab;}
.t3d.wr .v{color:#ffc061;}

.bar3d{
  position:relative;height:24px;border-radius:999px;overflow:hidden;margin:2px 0 4px;
  background:linear-gradient(180deg,rgba(0,0,0,.45),rgba(255,255,255,.03));
  border:1px solid rgba(255,255,255,.07);box-shadow:inset 0 3px 7px rgba(0,0,0,.65);
}
.bar3d i{
  position:absolute;inset:2px auto 2px 2px;border-radius:999px;
  background:linear-gradient(180deg,#7aa4ff,#3f6ee0);
  box-shadow:0 3px 9px -3px rgba(91,140,255,.85),inset 0 1px 0 rgba(255,255,255,.4);
  transition:width .9s cubic-bezier(.22,.9,.28,1);
}
.bar3d.wr i{background:linear-gradient(180deg,#ffc061,#e08b12);}
.bar3d.er i{background:linear-gradient(180deg,#ff9b9b,#e04b4b);}
.bar3d b{
  position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.85);
}

.hint3d{font-size:11px;color:var(--mut);margin:0 3px 7px;line-height:1.75;}
.cfg-h{font-size:11px;color:var(--mut);margin-top:10px;margin-bottom:3px;}
.card.tight.g3{box-shadow:0 14px 28px -20px rgba(0,0,0,.85),inset 0 1px 0 rgba(255,255,255,.055);}

.btn.b3d{
  box-shadow:0 10px 20px -12px rgba(0,0,0,.95),inset 0 1px 0 rgba(255,255,255,.18);
  transition:transform .12s ease,box-shadow .2s ease,filter .2s ease;
}
.btn.b3d:active{transform:translateY(2px) scale(.985);box-shadow:0 4px 10px -8px rgba(0,0,0,.95),inset 0 1px 0 rgba(255,255,255,.12);}

.card .chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px;}
.card .chips .chip{
  font-size:10.5px;padding:5px 9px;border-radius:10px;white-space:nowrap;color:#cdd8ea;
  background:linear-gradient(180deg,rgba(255,255,255,.075),rgba(255,255,255,.022));
  border:1px solid rgba(255,255,255,.08);box-shadow:inset 0 1px 0 rgba(255,255,255,.09);
}
.card .chips .chip.a{color:#a9c4ff;border-color:rgba(91,140,255,.34);background:linear-gradient(180deg,rgba(91,140,255,.2),rgba(91,140,255,.06));}
.card .chips .chip.g{color:#7ce8b6;border-color:rgba(47,212,143,.32);background:linear-gradient(180deg,rgba(47,212,143,.18),rgba(47,212,143,.05));}
.card .chips .chip.w{color:#ffcc7a;border-color:rgba(245,158,11,.32);background:linear-gradient(180deg,rgba(245,158,11,.18),rgba(245,158,11,.05));}

@media (max-width:340px){
  .g3d{grid-template-columns:1fr;}
  .ring3d{width:116px;height:116px;}
}
@media (prefers-reduced-motion:reduce){
  .svc3d{transition:none!important;transform:none!important;}
  .ring3d .val,.bar3d i{transition:none!important;}
}

body.light .svc3d>.in{background:radial-gradient(120% 130% at 12% 0%,#ffffff 0%,#f4f6fb 52%,#eef1f7 100%);}
body.light .svc3d .nm{color:#243044;background:rgba(0,0,0,.04);border-color:rgba(0,0,0,.08);}
body.light .ring3d .trk{stroke:rgba(0,0,0,.08);}
body.light .ring3d .ctr b{color:#1b2434;text-shadow:none;}
body.light .t3d{background:linear-gradient(180deg,#ffffff,#f5f7fb);border-color:rgba(0,0,0,.07);}
body.light .t3d .v{color:#1e2736;}
body.light .bar3d{background:linear-gradient(180deg,rgba(0,0,0,.07),rgba(0,0,0,.02));box-shadow:inset 0 3px 7px rgba(0,0,0,.12);}

</style>
<style>
/* ===== 3D depth layer (0.0.1 beta) ===== */
:root{
	--dp1:0 1px 2px rgba(3,7,18,.20), 0 2px 6px rgba(3,7,18,.14);
	--dp2:0 8px 20px rgba(3,7,18,.26), 0 2px 6px rgba(3,7,18,.16);
	--dp3:0 20px 44px rgba(3,7,18,.34), 0 8px 16px rgba(3,7,18,.20);
	--gloss:linear-gradient(180deg, rgba(255,255,255,.13), rgba(255,255,255,0) 58%);
	--ez:cubic-bezier(.2,.8,.2,1);
}
*{-webkit-tap-highlight-color:transparent}
html{-webkit-text-size-adjust:100%;text-size-adjust:100%}
body{overscroll-behavior-y:contain;-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility}
.card,.box,.tile,.prod,.pcard,.item,.svc{position:relative;box-shadow:var(--dp2);transform:translateZ(0);transition:transform .26s var(--ez), box-shadow .26s var(--ez)}
.card::before,.prod::before,.pcard::before,.tile::before{content:'';position:absolute;inset:0;border-radius:inherit;background:var(--gloss);pointer-events:none}
.card:active,.prod:active,.pcard:active,.tile:active{transform:translateY(1px)}
.btn,button.btn,.chip,.pill{transition:transform .15s var(--ez), box-shadow .15s var(--ez)}
.btn,button.btn{box-shadow:var(--dp1)}
.btn:active,button.btn:active{transform:translateY(1px) scale(.995)}
.chip.on,.chip.active,.pill.on{box-shadow:var(--dp2)}
.nav,.tabbar,.bnav{box-shadow:0 -8px 24px rgba(3,7,18,.28)}
@media (max-width:380px){ body{font-size:13.5px} }
</style>
<style>
/* ترمیم نمایش — نسخهٔ ۳ (موبایل و دسکتاپ) */
*,*::before,*::after{box-sizing:border-box}
html,body{max-width:100%;overflow-x:hidden}
img,svg,canvas,video{max-width:100%;height:auto}
.card,.card>*,.kpi,.kpi>*,.irow,.irow>*,.row,.row>*,.stat,.stat>*,.sec,.sec>*{min-width:0}

/* ردیف‌های «برچسب / مقدار» هرگز از کادر بیرون نزنند */
.row{flex-wrap:wrap;row-gap:4px}
.row>.rt{flex:1 1 42%;min-width:96px;overflow-wrap:anywhere;word-break:break-word}
.row>.rt b,.row>.rt span,.row>.rt i{overflow-wrap:anywhere;white-space:normal}
.row>.rv{white-space:normal;overflow-wrap:anywhere;word-break:break-word;
         text-align:start;margin-inline-start:auto;max-width:100%}
.irow{flex-wrap:wrap;row-gap:4px}
.irow>*{overflow-wrap:anywhere;min-width:0}

/* متن لاتین، لینک، کد و شناسه‌ها */
.mono,code,pre,.ltr,.lnk,.uid{direction:ltr;unicode-bidi:isolate;overflow-wrap:anywhere;word-break:break-all}
pre,.pre{white-space:pre-wrap}

/* جدول‌ها (بدون display:block تا RTL خراب نشود) */
table{width:100%;max-width:100%;table-layout:auto;border-collapse:collapse}
th,td{overflow-wrap:anywhere;word-break:break-word}

/* گروه دکمه‌ها همیشه افقی و شکسته، هرگز ستون بلند */
.acts,.tools,.brow,.btns,.bwrap,.ops{display:flex;flex-wrap:wrap;gap:8px;align-items:center;min-width:0}
.acts>*,.tools>*,.brow>*,.btns>*,.bwrap>*,.ops>*{flex:0 1 auto;max-width:100%}
.btn{max-width:100%;overflow-wrap:anywhere}

/* چیپ‌های افقی */
.chips{-webkit-overflow-scrolling:touch;overscroll-behavior-x:contain;max-width:100%}
.chip{flex:0 0 auto;white-space:nowrap}

@media (max-width:560px){
  .row{align-items:flex-start}
  .row>.rt{flex:1 1 100%}
  .row>.rv{flex:1 1 100%;margin-inline-start:0;text-align:start;font-size:12px;opacity:.96}
  .row>.ri{margin-top:2px}
  .kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .stat{padding-inline:8px}
  .btn{white-space:normal}
}
@media (max-width:380px){
  .kpi-grid{grid-template-columns:1fr}
}

/* ================================================================
   SR-BOT • Mini App Design Polish v5
   بازطراحی طاهری بدون دست‌زدن به مارکاپ و کلاس‌ها
   ================================================================ */
:root{
  --acc2:#8b5cf6;
  --grad:linear-gradient(135deg,var(--acc),var(--acc2));
  --sh-1:0 1px 2px rgba(0,0,0,.28);
  --sh-2:0 6px 20px -8px rgba(0,0,0,.55);
  --sh-3:0 18px 44px -16px rgba(0,0,0,.7);
  --ring:0 0 0 3px color-mix(in srgb, var(--acc) 26%, transparent);
}
body.light{--sh-1:0 1px 2px rgba(16,24,40,.06);--sh-2:0 6px 18px -8px rgba(16,24,40,.18);--sh-3:0 18px 40px -16px rgba(16,24,40,.26)}

body{background:
  radial-gradient(1100px 520px at 88% -8%, color-mix(in srgb, var(--acc) 12%, transparent), transparent 70%),
  radial-gradient(900px 460px at 6% 4%, color-mix(in srgb, var(--acc2) 10%, transparent), transparent 72%),
  var(--bg);
  background-attachment:fixed;
}

/* ---------- هدر ---------- */
.hdr{padding:11px 14px;box-shadow:var(--sh-1)}
.ava{box-shadow:0 4px 14px -4px color-mix(in srgb, var(--acc) 65%, transparent);border:1px solid color-mix(in srgb,#fff 16%,transparent)}
.bal{transition:transform .16s ease, box-shadow .16s ease;box-shadow:var(--sh-1)}
.bal:active{transform:scale(.96)}

/* ---------- کارت‌ها ---------- */
.card{
  border-radius:var(--r2);
  box-shadow:var(--sh-2);
  border:1px solid var(--line);
  background:linear-gradient(180deg, color-mix(in srgb, var(--card) 96%, #fff 4%), var(--card));
}
body.light .card{background:var(--card)}
.card.hero{
  position:relative;overflow:hidden;
  background:
    radial-gradient(120% 140% at 100% 0%, color-mix(in srgb, var(--acc) 24%, transparent), transparent 62%),
    linear-gradient(180deg, var(--card2), var(--card));
  box-shadow:var(--sh-3);
}
.card.hero::after{
  content:"";position:absolute;inset:0;pointer-events:none;
  background:linear-gradient(120deg, transparent 40%, color-mix(in srgb,#fff 8%, transparent) 50%, transparent 60%);
}
.card.hero .big{background:var(--grad);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:-.4px}
body.light .card.hero .big{-webkit-text-fill-color:initial;background:none;color:var(--tx)}

/* ---------- دکمه‌ها ---------- */
.btn{
  min-height:var(--tap);border-radius:14px;font-weight:700;
  background:var(--grad);color:#fff;box-shadow:0 8px 22px -12px color-mix(in srgb, var(--acc) 90%, transparent);
  transition:transform .14s ease, filter .14s ease, box-shadow .14s ease;
}
.btn:active{transform:translateY(1px) scale(.985);filter:saturate(1.1)}
.btn:disabled{opacity:.62;filter:grayscale(.35)}
.btn.gh{background:var(--card2);color:var(--tx);border:1px solid var(--line);box-shadow:var(--sh-1)}
.btn.ok{background:linear-gradient(135deg,var(--ok),#10b981)}
.btn.sm{min-height:34px;border-radius:11px}
.btn:focus-visible,.chip:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{outline:none;box-shadow:var(--ring)}

/* ---------- فیلدها ---------- */
.fld input,.fld select,.fld textarea{
  min-height:var(--tap);border-radius:13px;background:var(--card2);border:1px solid var(--line);
  transition:border-color .15s ease, box-shadow .15s ease;
}
.fld input:focus,.fld select:focus,.fld textarea:focus{border-color:var(--acc);box-shadow:var(--ring)}
.fld label{font-weight:700}

/* ---------- ردیف‌ها ---------- */
.row{transition:background .15s ease;border-radius:12px}
.row:active{background:color-mix(in srgb, var(--acc) 8%, transparent)}
.ri{border-radius:12px}
.rv.p{color:var(--ok)}
.rv.n{color:var(--err)}

/* ---------- اقدام ��ریع ---------- */
.qa button{
  min-height:64px;border-radius:16px;background:color-mix(in srgb, var(--card2) 88%, transparent);
  border:1px solid var(--line);font-weight:700;box-shadow:var(--sh-1);
  transition:transform .14s ease, border-color .14s ease, background .14s ease;
}
.qa button:active{transform:scale(.96);border-color:var(--acc)}
.qa .ic{font-size:19px}

/* ---------- نوار تب پایین ---------- */
.tabs{box-shadow:0 -10px 30px -18px rgba(0,0,0,.85)}
.tabs button{min-height:52px;transition:color .16s ease, background .16s ease, transform .16s ease}
.tabs button .ic{font-size:19px;transition:transform .2s ease}
.tabs button.on{color:var(--acc);background:color-mix(in srgb, var(--acc) 13%, transparent)}
.tabs button.on .ic{transform:translateY(-2px) scale(1.08)}
.tabs button:active{transform:scale(.94)}

/* ---------- شیت پایین ---------- */
.sh{border-radius:24px 24px 0 0;box-shadow:var(--sh-3);border-top:1px solid var(--line)}
.sh-bar{opacity:.9}
.sh-h{padding-top:6px}
.sh-x{min-width:38px;min-height:38px;border-radius:50%;background:var(--card2);border:1px solid var(--line)}

/* ---------- هشدارها ---------- */
.alert{border-radius:14px;border-width:1px;border-style:solid;box-shadow:var(--sh-1)}
.alert.w{background:color-mix(in srgb, var(--warn) 14%, transparent);border-color:color-mix(in srgb, var(--warn) 42%, transparent)}
.alert.e{background:color-mix(in srgb, var(--err) 14%, transparent);border-color:color-mix(in srgb, var(--err) 42%, transparent)}
.alert.i{background:color-mix(in srgb, var(--acc) 13%, transparent);border-color:color-mix(in srgb, var(--acc) 40%, transparent)}

/* ---------- مبلغ و مبلغ سریع ---------- */
.amt3{border-radius:var(--r2);box-shadow:var(--sh-2);border:1px solid var(--line)}
.amt3 .v{background:var(--grad);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
body.light .amt3 .v{-webkit-text-fill-color:initial;background:none}
.qg button{min-height:44px;border-radius:13px;font-weight:700;transition:transform .14s ease,border-color .14s ease}
.qg button:active{transform:scale(.95)}
.qg button.on{border-color:var(--acc);background:color-mix(in srgb, var(--acc) 15%, transparent);color:var(--tx)}

/* ---------- کارت روش پرداخت ---------- */
.pmc{min-height:62px;border-radius:16px;box-shadow:var(--sh-1);transition:transform .14s ease,border-color .14s ease,background .14s ease}
.pmc:active{transform:scale(.985)}
.pmc.on{border-color:var(--acc);background:color-mix(in srgb, var(--acc) 11%, transparent)}
.pmc.on .pk{background:var(--grad);color:#fff}

/* ---------- چیپ ---------- */
.chip{min-height:38px;border-radius:999px;transition:transform .14s ease,border-color .14s ease}
.chip:active{transform:scale(.95)}
.chip.on{border-color:var(--acc);background:color-mix(in srgb, var(--acc) 15%, transparent)}

/* ---------- اسکلت و توست ---------- */
.sk{position:relative;overflow:hidden}
.sk::after{
  content:"";position:absolute;inset:0;transform:translateX(-100%);
  background:linear-gradient(90deg, transparent, color-mix(in srgb,#fff 10%, transparent), transparent);
  animation:shim 1.5s infinite;
}
@keyframes shim{100%{transform:translateX(100%)}}
.toast{border-radius:14px;box-shadow:var(--sh-3);font-weight:700}

/* ---------- عناوین بخش ---------- */
.sec-t{font-weight:800;letter-spacing:-.2px}
.sec-t>span:first-child{position:relative;padding-inline-start:11px}
.sec-t>span:first-child::before{
  content:"";position:absolute;inset-inline-start:0;top:50%;transform:translateY(-50%);
  width:4px;height:15px;border-radius:99px;background:var(--grad);
}

/* ---------- موبایل کوچک ---------- */
@media (max-width:360px){
  body{font-size:13.8px}
  .qa button{min-height:58px}
  .tabs button{font-size:9.8px}
}
/* ---------- دسکتاپ / تبلت ---------- */
@media (min-width:700px){
  .app{max-width:680px;box-shadow:var(--sh-3);border-inline:1px solid var(--line);min-height:100vh}
  .view{padding:18px 18px calc(96px + var(--safe))}
  .qa button{min-height:70px}
}
@media (prefers-reduced-motion:reduce){
  *{animation-duration:.001ms !important;transition-duration:.001ms !important}
}

/* ===== ۳۲) خانهٔ مینی‌اپ ، فهرست سرویس‌ها و کیف پول ===== */
.hm-hero .hm-top{display:flex;align-items:center;gap:10px;margin-bottom:12px;position:relative;z-index:2}
.hm-ava{width:42px;height:42px;border-radius:14px;display:grid;place-items:center;font-weight:800;font-size:17px;
  background:linear-gradient(150deg,rgba(255,255,255,.30),rgba(255,255,255,.08));
  border:1px solid rgba(255,255,255,.28);box-shadow:0 6px 16px rgba(0,0,0,.22)}
.hm-who{flex:1;min-width:0}
.hm-who b{display:block;font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hm-who span{display:block;font-size:10.5px;opacity:.86;margin-top:2px}
.hm-gear{width:34px;height:34px;border-radius:11px;cursor:pointer;font-size:15px;color:inherit;
  border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.12)}
.hm-gear:active{transform:scale(.94)}
.hm-kpi .stat{padding-top:13px}
.hm-kpi .ki{font-size:15px;line-height:1;margin-bottom:3px}
.qt{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin-bottom:6px}
.qti{display:flex;flex-direction:column;align-items:center;gap:5px;padding:12px 5px;border-radius:16px;cursor:pointer;
  background:var(--card);border:1px solid var(--line);color:var(--tx);font:inherit;box-shadow:var(--dp1);
  transition:transform .16s var(--ez),border-color .16s ease}
.qti:active{transform:translateY(2px) scale(.96)}
.qti .i{font-size:19px;line-height:1}
.qti .l{font-size:9.8px;color:var(--dim);text-align:center;line-height:1.5}
.hm-sv{padding:12px}
.hm-svh{display:flex;align-items:center;gap:8px;margin-bottom:8px}
.hm-svh .n{flex:1;min-width:0;font-size:12.5px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hm-svf{display:flex;justify-content:space-between;font-size:10.5px;color:var(--mut);margin-top:6px}
.hm-all{margin-bottom:12px}
.hm-ref .hm-rt{font-size:12px;color:var(--dim);line-height:1.9;margin-bottom:9px}

/* —— فیلتر و کارت سرویس‌ها —— */
.svf{display:flex;gap:7px;overflow-x:auto;padding:2px 0 10px;-webkit-overflow-scrolling:touch;scrollbar-width:none}
.svf::-webkit-scrollbar{display:none}
.svf button{flex:0 0 auto;padding:7px 13px;border-radius:999px;cursor:pointer;white-space:nowrap;font:inherit;font-size:11.5px;
  border:1px solid var(--line);background:var(--card);color:var(--dim)}
.svf button.on{background:var(--acc);border-color:transparent;color:#fff;font-weight:700;box-shadow:var(--dp1)}
.sv-s{display:flex;gap:8px;margin-bottom:10px}
.sv-s .c{flex:1;text-align:center;padding:10px 4px;border-radius:15px;background:var(--card);border:1px solid var(--line);
  box-shadow:var(--dp1)}
.sv-s .c .v{font-size:15.5px;font-weight:800;line-height:1.3}
.sv-s .c .t{font-size:9.5px;color:var(--mut);margin-top:2px}
.sv-s .c.g .v{color:var(--ok)}
.sv-s .c.o .v{color:var(--warn,#FFA92E)}
.sv-q input{width:100%;padding:11px 13px;border-radius:14px;font:inherit;font-size:12px;margin-bottom:10px;
  border:1px solid var(--line);background:var(--card);color:var(--tx)}
.sv-card{padding:13px}
.sv-h{display:flex;align-items:center;gap:9px}
.sv-h .n{flex:1;min-width:0;font-size:13px;font-weight:700;direction:ltr;text-align:left;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sv-m{font-size:10.5px;color:var(--mut);margin-top:3px}
.sv-f{display:flex;justify-content:space-between;font-size:10.5px;color:var(--mut);margin-top:6px}
.sv-act{display:flex;gap:7px;margin-top:11px}
.sv-act button{flex:1;padding:9px 6px;border-radius:12px;cursor:pointer;font:inherit;font-size:11px;
  border:1px solid var(--line);background:var(--card2,rgba(255,255,255,.04));color:var(--tx)}
.sv-act button:active{transform:translateY(1px)}

/* —— فیلتر تراکنش‌های کیف پول —— */
.wf{display:flex;gap:7px;overflow-x:auto;padding:2px 0 10px;scrollbar-width:none}
.wf::-webkit-scrollbar{display:none}
.wf button{flex:0 0 auto;padding:7px 13px;border-radius:999px;cursor:pointer;white-space:nowrap;font:inherit;font-size:11.5px;
  border:1px solid var(--line);background:var(--card);color:var(--dim)}
.wf button.on{background:var(--acc);border-color:transparent;color:#fff;font-weight:700;box-shadow:var(--dp1)}

  /* ===== ۳۳) کارت محصول مینی‌اپ — نسخهٔ جدید ===== */
  .pcard{padding:0;border-radius:20px;overflow:hidden;
    background-image:linear-gradient(90deg,#6C8CFF,#22d3ee,#34d399),
      linear-gradient(165deg,rgba(255,255,255,.05),rgba(255,255,255,.015));
    background-size:100% 3px,auto;background-repeat:no-repeat,no-repeat;background-position:top,center}
  .pcard .pc-h{display:flex;gap:11px;align-items:flex-start;padding:16px 13px 0;position:relative;z-index:2}
  .pcard .pc-ic{width:44px;height:44px;flex:0 0 44px;display:grid;place-items:center;border-radius:15px;font-size:20px;
    background:linear-gradient(160deg,rgba(108,140,255,.34),rgba(34,211,238,.18));
    box-shadow:inset 0 1px 0 rgba(255,255,255,.22),0 6px 14px rgba(0,0,0,.26)}
  .pcard .pc-n{font-size:14px;font-weight:800;line-height:1.6;overflow-wrap:anywhere}
  .pcard .pc-s{font-size:11px;color:var(--mut);margin-top:3px;line-height:1.75;overflow-wrap:anywhere}
  .pcard .pc-off{flex:0 0 auto;font-size:10px;font-weight:800;padding:5px 9px;border-radius:999px;white-space:nowrap;
    background:linear-gradient(160deg,#f87171,#dc2626);color:#fff;box-shadow:0 5px 12px rgba(220,38,38,.34)}
  .pcard .pc-g{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;padding:13px 13px 0;position:relative;z-index:2}
  .pcard .pc-i{text-align:center;padding:9px 4px;border-radius:13px;background:rgba(0,0,0,.20);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.07)}
  .pcard .pc-i b{display:block;font-size:12px;font-weight:800}
  .pcard .pc-i i{display:block;font-style:normal;font-size:9.5px;opacity:.60;margin-top:3px}
  .pcard .pc-tags{display:flex;flex-wrap:wrap;gap:6px;padding:11px 13px 0;position:relative;z-index:2}
  .pcard .pc-tags span{font-size:10px;padding:4px 9px;border-radius:999px;background:rgba(255,255,255,.07);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.10)}
  .pcard .pc-f{display:flex;align-items:center;gap:10px;margin-top:13px;padding:12px 13px;position:relative;z-index:2;
    background:rgba(0,0,0,.24);box-shadow:inset 0 1px 0 rgba(255,255,255,.07)}
  .pcard .pc-p{flex:1;min-width:0;font-size:15.5px;font-weight:900;line-height:1.5}
  .pcard .pc-p s{display:block;font-size:10.5px;opacity:.48;font-weight:600}
  .pcard .pc-b{border:0;cursor:pointer;font:inherit;font-size:12.5px;font-weight:800;padding:12px 20px;border-radius:15px;
    color:#0B1020;background:linear-gradient(160deg,#9db2ff,#5b7cf6);box-shadow:0 8px 18px rgba(91,124,246,.34)}
  .pcard .pc-b:active{transform:translateY(2px) scale(.98)}
  .pcard.sold{opacity:.64}
</style>
</head>
<body>

<?php if (!$ready): ?>
<div class="center">
  <div>
    <div style="font-size:52px;margin-bottom:12px">🚫</div>
    <div style="font-weight:700;font-size:16px;margin-bottom:6px">در دسترس نیست</div>
    <div style="color:var(--mut);font-size:13px"><?= h($note !== '' ? $note : 'خطای ناشناخته') ?></div>
  </div>
</div>
<?php else: ?>

<div class="app">
  <header class="hdr">
    <div class="ava" id="ava">…</div>
    <div class="hdr-txt">
      <div class="hdr-t" id="hName"><?= h($appName) ?></div>
      <div class="hdr-s" id="hSub">در حال بارگزاری…</div>
    </div>
    <button class="bal" id="balBtn" type="button">👛 <span id="balTxt">—</span></button>
  </header>

  <main class="view" id="view">
    <div class="sk sk-c"></div>
    <div class="sk sk-c"></div>
    <div class="sk sk-c"></div>
  </main>

  <nav class="tabs" id="tabs">
    <button type="button" data-tab="home" class="on"><span class="ic">🏠</span>خانه</button>
    <button type="button" data-tab="shop"><span class="ic">🛒</span>محصولات</button>
    <button type="button" data-tab="svc"><span class="ic">📦</span>سرویس‌ها</button>
    <button type="button" data-tab="wallet"><span class="ic">💳</span>کیف پول</button>
    <button type="button" data-tab="help"><span class="ic">👤</span>من</button>
<?php /* تب نمایندگی حذف شد: پنل نمایندگی کاملاً جداست (reseller.php) */ ?>
  </nav>
</div>

<div class="sw" id="sw"><div class="sh" id="sh"></div></div>
<div class="toast" id="toast"></div>

<script>
(function () {
  'use strict';

  var TG = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;
  var INIT = TG ? (TG.initData || '') : '';

  /* وقتی کاربر از صفحهٔ ثبت کارت برمی‌گردد، تلگرام initData تازه نمی‌سازد؛
     در این حالت همان مقدار از آدرس صفحه خوانده می‌شود تا احراز هویت قطع نشود. */
  if (!INIT) {
    var mInit = /[#&?](?:tgWebAppData|init)=([^&]+)/.exec(String(location.hash || '') + '&' + String(location.search || ''));
    if (mInit) {
      try { INIT = decodeURIComponent(mInit[1]); } catch (eInit) { INIT = mInit[1]; }
    }
  }

  /* هش راه‌اندازی تلگرام — برای جابه‌جایی بین صفحه‌های مینی‌اپ لازم است */
  var LAUNCH_HASH = String(location.hash || '');

  /* جدول BIN بانک‌های ایران برای تشخیص خودکار بانک از روی شمارهٔ کارت */
  var BINS = <?= json_encode($bins, JSON_UNESCAPED_UNICODE) ?> || {};

  var S = { boot: null, products: null, cat: '', tab: 'home', rs: null, rsPrice: null,
            shopStep: 1, shopPanel: 0, shopHome: false };

  /* ================= راه‌اندازی تلگرام ================= */
  if (TG) {
    try { TG.ready(); } catch (e) {}
    try { TG.expand(); } catch (e) {}
    try { TG.enableClosingConfirmation(); } catch (e) {}
    try { if (TG.setHeaderColor) TG.setHeaderColor('secondary_bg_color'); } catch (e) {}
    try { if (TG.colorScheme === 'light') document.body.classList.add('light'); } catch (e) {}
  }

  function haptic(kind) {
    if (!TG || !TG.HapticFeedback) return;
    try {
      if (kind === 'ok') TG.HapticFeedback.notificationOccurred('success');
      else if (kind === 'no') TG.HapticFeedback.notificationOccurred('error');
      else TG.HapticFeedback.impactOccurred('light');
    } catch (e) {}
  }

  /* ================= کمکی ================= */
  var $ = function (id) { return document.getElementById(id); };

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function fa(s) {
    var d = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    return String(s == null ? '' : s).replace(/[0-9]/g, function (n) { return d[+n]; });
  }

  var tmr = null;
  function toast(msg, kind) {
    var t = $('toast');
    t.textContent = msg;
    t.className = 'toast on' + (kind === 'ok' ? ' g' : (kind === 'err' ? ' r' : ''));
    haptic(kind === 'ok' ? 'ok' : (kind === 'err' ? 'no' : ''));
    clearTimeout(tmr);
    tmr = setTimeout(function () { t.className = 'toast'; }, 2800);
  }

  function copy(text, btn) {
    var done = function () {
      toast('کپی شد ✅', 'ok');
      if (btn) { var o = btn.textContent; btn.textContent = '✓'; setTimeout(function () { btn.textContent = o; }, 1400); }
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () { fallback(text, done); });
    } else fallback(text, done);
  }

  function fallback(text, done) {
    var a = document.createElement('textarea');
    a.value = text; a.style.position = 'fixed'; a.style.opacity = '0';
    document.body.appendChild(a); a.select();
    try { document.execCommand('copy'); done(); } catch (e) { toast('کپی نشد', 'err'); }
    document.body.removeChild(a);
  }

  /* ================= ارتباط با سرور ================= */
  function api(action, data) {
    var body = Object.assign({ action: action, initData: INIT }, data || {});
    return fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-TG-Init-Data': INIT },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.text().then(function (t) {
        try { return JSON.parse(t); }
        catch (e) {
          var snip = String(t || '').replace(/<[^>]*>/g, ' ').replace(/  +/g, ' ').trim().slice(0, 200);
          return { ok: false, message: 'پاسخ سرور معتبر نبود (کد ' + r.status + ').' + (snip ? '  —  ' + snip : '') };
        }
      });
    }).catch(function () {
      return { ok: false, message: 'اتصال به سرور برقرار نشد.' };
    });
  }

  /* ================= شیت ================= */
  function sheet(title, html) {
    $('sh').innerHTML =
      '<div class="sh-h"><div class="sh-bar"></div>' +
      '<div class="sh-t"><span>' + title + '</span>' +
      '<button type="button" class="sh-x" data-x>✕</button></div></div>' + html;
    $('sw').classList.add('on');
    document.body.style.overflow = 'hidden';
  }

  function closeSheet() {
    $('sw').classList.remove('on');
    document.body.style.overflow = '';
  }

  $('sw').addEventListener('click', function (e) {
    if (e.target === $('sw') || (e.target.closest && e.target.closest('[data-x]'))) closeSheet();
  });

  function skeleton(n) {
    var h = '';
    for (var i = 0; i < (n || 3); i++) h += '<div class="sk sk-c"></div>';
    $('view').innerHTML = h;
  }

  function empty(icon, title, sub, btn) {
    return '<div class="empty"><div class="e">' + icon + '</div><b>' + title + '</b>' +
      '<div style="font-size:12px">' + (sub || '') + '</div>' + (btn || '') + '</div>';
  }

  /* ================= هدر ================= */
  function paintHeader() {
    var b = S.boot;
    if (!b || !b.ok) return;
    var nm = b.user.name || (b.user.username ? '@' + b.user.username : 'کاربر');
    $('hName').textContent = nm;
    $('hSub').textContent = b.shop.title;
    $('ava').textContent = (nm.trim()[0] || '⭐').toUpperCase();
    $('balTxt').textContent = b.user.balance_txt;
  }

  /* ================= خانه ================= */
  function viewHome() {
    var b = S.boot;
    var h = '';

    if (b.shop.maint) {
      h += '<div class="alert w">🛠 فروشگاه در حالت تعمیر است؛ برخی بخش‌ها ممکن است کار نکند.</div>';
    }
    if (b.security.enabled && !b.user.verified) {
      h += '<div class="alert w">🔐 حساب شما تایید نشده است.' +
        (b.security.mode === 'required' ? ' برای خرید و دریافت تست، تایید اجباری است.' : '') +
        '<div style="margin-top:9px"><button type="button" class="btn sm" data-go="verify">تایید حساب</button></div></div>';
    }

    var nm = b.user.name || (b.user.username ? '@' + b.user.username : 'کاربر');
    var hr = new Date().getHours();
    var gr = hr < 5 ? 'شب بخیر' : (hr < 12 ? 'صبح بخیر' : (hr < 17 ? 'وقت بخیر' : (hr < 21 ? 'عصر بخیر' : 'شب بخیر')));

    h += '<div class="card hero hm-hero">' +
      '<div class="hm-top">' +
        '<div class="hm-ava">' + esc((String(nm).trim()[0] || '⭐').toUpperCase()) + '</div>' +
        '<div class="hm-who"><b>' + esc(gr) + '، ' + esc(nm) + '</b><span>' +
          (b.user.verified ? '🔐 حساب تاییدشده' : '⚠️ حساب تاییدنشده') +
          ' · ' + esc(b.shop.title || '') + '</span></div>' +
        '<button type="button" class="hm-gear" data-go="profile">⚙️</button>' +
      '</div>' +
      '<div class="lbl">موجودی کیف پول</div>' +
      '<div class="big">' + esc(b.user.balance_txt) + '</div>' +
      '<div class="qa">' +
      '<button type="button" data-go="shop"><span class="ic">🛒</span>خرید</button>' +
      (b.flags.stock ? '<button type="button" data-go="stock"><span class="ic">🏪</span>انبار</button>' : '') +
      '<button type="button" data-go="charge"><span class="ic">➕</span>شارج</button>' +
      (b.flags.test ? '<button type="button" data-go="test"><span class="ic">🧪</span>تست</button>' : '') +
      '<button type="button" data-go="help"><span class="ic">🆘</span>پشتیبانی</button>' +
      '</div></div>';

    h += '<div class="grid3 hm-kpi">' +
      '<div class="stat"><div class="ki">🟢</div><div class="v">' + fa(b.stats.active) + '</div><div class="t">سرویس فعال</div></div>' +
      '<div class="stat"><div class="ki">📦</div><div class="v">' + fa(b.stats.services) + '</div><div class="t">کل سرویس‌ها</div></div>' +
      '<div class="stat"><div class="ki">🧾</div><div class="v">' + fa(b.stats.orders) + '</div><div class="t">خرید موفق</div></div>' +
      '</div>';

    h += '<div class="sec-t"><span>📦 سرویس‌های من</span>' +
      '<button type="button" class="more" data-go="svc">همه</button></div>' +
      '<div id="hmSvc"><div class="sk sk-c"></div></div>';

    var tiles = [['shop', '🛒', 'محصولات'], ['svc', '📦', 'سرویس‌ها'],
                 ['wallet', '👛', 'کیف پول'], ['charge', '➕', 'شارج'],
                 ['cards', '💳', 'کارت‌های من'], ['tut', '🎓', 'آموزش اتصال'],
                 ['newtk', '✍️', 'تیکت جدید'], ['help', '🆘', 'پشتیبانی']];
    if (b.flags.stock) tiles.push(['stock', '🏪', 'انبار ملی']);
    if (b.flags.test) tiles.push(['test', '🧪', 'اکانت تست']);
    if (!b.user.verified) tiles.push(['verify', '🔐', 'تایید حساب']);

    h += '<div class="sec-t"><span>⚡ دسترسی سریع</span></div><div class="qt">';
    tiles.forEach(function (t) {
      h += '<button type="button" class="qti" data-go="' + t[0] + '">' +
        '<span class="i">' + t[1] + '</span><span class="l">' + t[2] + '</span></button>';
    });
    h += '</div>';

    h += '<div class="sec-t"><span>👤 حساب من</span>' +
      '<button type="button" class="more" data-go="profile">ویرایش</button></div>';
    h += '<div class="card tight">' +
      row('🆔', 'شناسهٔ عددی', '', fa(b.user.tg_id)) +
      row('📱', 'شماره تماس', '', b.user.phone ? fa(b.user.phone) : 'ثبت نشده') +
      row('📧', 'ایمیل', '', b.user.email || 'ثبت نشده') +
      row('🔐', 'وضعیت حساب', '',
        b.user.verified ? '<span class="bdg g">تایید شده</span>' : '<span class="bdg o">تایید نشده</span>') +
      row('📅', 'عضویت', '', b.user.joined) +
      '</div>';

    if (b.user.ref_link) {
      h += '<div class="sec-t"><span>👥 معرفی دوستان</span></div>' +
        '<div class="card tight hm-ref">' +
        '<div class="hm-rt">لینک اختصاصی شماست؛ هر دوستی که با این لینک عضو شود و خرید کند، برای شما پاداش می‌سازد.</div>' +
        copyBox(b.user.ref_link) +
        '<a class="btn gh w" style="margin-top:9px" target="_blank" href="https://t.me/share/url?url=' +
        encodeURIComponent(b.user.ref_link) + '">📤 ارسال برای دوستان</a>' +
        '</div>';
    }

    $('view').innerHTML = h;
    homeSvc();
  }

  /* پیش‌نمایش سرویس‌ها در خانه */
  function homeSvc() {
    if (!$('hmSvc')) return;
    api('services').then(function (r) {
      var box = $('hmSvc');
      if (!box) return;
      if (!r || !r.ok) { box.innerHTML = ''; return; }
      var list = r.services || [];
      S.svcs = list;
      if (!list.length) {
        box.innerHTML = '<div class="card">' +
          empty('📦', 'هنوز سرویسی ندارید', 'اولین طرح خود را از بخش محصولات بگیرید.',
            '<div style="margin-top:12px"><button type="button" class="btn sm" data-go="shop">مشاهدهٔ محصولات</button></div>') +
          '</div>';
        return;
      }
      var soon = 0, out = '';
      list.forEach(function (s) {
        if (s.status === 'active' && Number(s.percent) >= 80) soon++;
      });
      if (soon > 0) {
        out += '<div class="alert w">⚠️ ' + fa(soon) +
          ' سرویس به پایان حجم نزدیک است؛ برای جلوگیری از قطعی، تمدید کنید.</div>';
      }
      list.slice(0, 2).forEach(function (s) { out += hmSvcRow(s); });
      if (list.length > 2) {
        out += '<button type="button" class="btn gh w hm-all" data-go="svc">مشاهدهٔ همهٔ ' +
          fa(list.length) + ' سرویس</button>';
      }
      box.innerHTML = out;
    });
  }

  function hmSvcRow(s) {
    var bd = s.status === 'active' ? 'g' : (s.status === 'expired' ? 'o' : 'r');
    var st = s.status === 'active' ? 'فعال' : (s.status === 'expired' ? 'منقضی' : 'غیرفعال');
    return '<div class="card hm-sv" data-svc="' + s.id + '">' +
      '<div class="hm-svh"><div class="n ltr mono">' + esc(s.name) + '</div>' +
      '<span class="bdg ' + bd + '">' + st + '</span></div>' +
      '<div class="bar' + (Number(s.percent) > 80 ? ' hi' : '') + '"><i style="width:' +
      Math.max(0, Math.min(100, Number(s.percent) || 0)) + '%"></i></div>' +
      '<div class="hm-svf"><span>⏰ ' + esc(s.remain_txt || '') + '</span>' +
      '<span>مانده: ' + esc(s.left_txt || '') + '</span></div></div>';
  }


  function row(ic, title, sub, val) {
    return '<div class="row"><div class="ri">' + ic + '</div><div class="rt"><b>' + title + '</b>' +
      (sub ? '<span>' + esc(sub) + '</span>' : '') + '</div><div class="rv">' + val + '</div></div>';
  }

  function copyBox(text) {
    return '<div class="copy"><code>' + esc(text) + '</code>' +
      '<button type="button" data-copy="' + esc(text) + '">کپی</button></div>';
  }

  /* ================= محصولات ================= */
  function viewShop() {
    skeleton(3);
    var pr = api('products');
    var cu = (S.cus === undefined) ? api('cus_info') : null;
    var done = function (r) {
      if (!r.ok) { $('view').innerHTML = '<div class="alert e">' + esc(r.message) + '</div>'; return; }
      S.products = r;
      paintShop();
    };
    if (cu) {
      cu.then(function (c) { S.cus = (c && c.ok) ? c : null; return pr; }).then(done);
    } else {
      pr.then(done);
    }
  }

  function shopPanels() {
    return (S.products && S.products.panels) ? S.products.panels : [];
  }

  function shopPanel(id) {
    var o = null;
    shopPanels().forEach(function (p) { if (+p.id === +id) o = p; });
    return o;
  }

  /* نوار مراحل + مسیر انتخاب‌شده */
  function shopCrumb(step) {
    var ps = shopPanels(), pn = shopPanel(S.shopPanel), h = '';
    var names = ['سرور', 'دسته', 'محصول'], nums = ['۱', '۲', '۳'];
    h += '<div class="swz">';
    for (var i = 1; i <= 3; i++) {
      h += '<div class="swz-s' + (step >= i ? ' on' : '') + (step > i ? ' dn' : '') + '">' +
        '<b>' + (step > i ? '✓' : nums[i - 1]) + '</b><span>' + names[i - 1] + '</span></div>';
      if (i < 3) h += '<i></i>';
    }
    h += '</div>';

    var bc = [];
    /* fixed81: در حالت تک‌سروری هم اگر «حجم و زمان دلخواه» فعال باشد، راه بازگشت به مرحلهٔ سرور باز بماند */
    if (pn && (ps.length > 1 || cusServers().length)) {
      bc.push('<button type="button" class="bcx" data-sback="1">' + (pn.flag || '🖥') + ' ' +
        esc(pn.name) + ' ✕</button>');
    }
    if (S.cat) bc.push('<button type="button" class="bcx" data-sback="2">📂 ' + esc(S.cat) + ' ✕</button>');
    if (bc.length) h += '<div class="bcs">' + bc.join('') + '</div>';
    return h;
  }

  function prodCard(p) {
    var pn  = Number(p.price) || 0;
    var po  = Number(p.old_price || p.old) || 0;
    var off = (po > pn && pn > 0) ? Math.round((po - pn) * 100 / po) : 0;
    var out = Number(p.stock) === 0;

    var subs = [];
    if (p.panel)    subs.push((p.flag || '🖥') + ' ' + p.panel);
    if (p.category) subs.push('📂 ' + p.category);

    var tags = [];
    if (Number(p.stock) > 0) tags.push('📦 موجودی ' + fa(p.stock) + ' عدد');
    if (Number(p.sold) > 0)  tags.push('🔥 ' + fa(p.sold) + ' فروش موفق');
    if (off > 0)             tags.push('🏷 قیمت ویژهٔ امروز');

    var h = '<div class="card pcard' + (out ? ' sold' : '') + '">' +
      '<div class="pc-h">' +
        '<div class="pc-ic">⚡️</div>' +
        '<div style="flex:1;min-width:0">' +
          '<div class="pc-n">' + esc(p.name) + '</div>' +
          (subs.length ? '<div class="pc-s">' + esc(subs.join(' · ')) + '</div>' : '') +
          (p.desc ? '<div class="pc-s">' + esc(p.desc) + '</div>' : '') +
        '</div>' +
        (off > 0 ? '<span class="pc-off">' + fa(off) + '٪ تخفیف</span>' : '') +
      '</div>' +
      '<div class="pc-g">' +
        '<div class="pc-i"><b>' + esc(p.vol_txt) + '</b><i>حجم</i></div>' +
        '<div class="pc-i"><b>' + esc(p.days_txt) + '</b><i>مدت اعتبار</i></div>' +
        '<div class="pc-i"><b>' + esc(p.ip_txt) + '</b><i>کاربر هم‌زمان</i></div>' +
      '</div>';

    if (tags.length) {
      h += '<div class="pc-tags"><span>' +
        tags.map(function (t) { return esc(t); }).join('</span><span>') + '</span></div>';
    }

    h += '<div class="pc-f">' +
      '<div class="pc-p">' + esc(p.price_txt) +
      (p.old_txt ? '<s>' + esc(p.old_txt) + '</s>' : '') + '</div>' +
      '<button type="button" class="pc-b" data-buy="' + p.id + '">🛒 خرید</button>' +
      '</div>';

    return h + '</div>';
  }

  function paintShop() {
    var r = S.products;
    if (!r || !r.products || !r.products.length) {
      /* fixed81: اگر فقط محصول «حجم و زمان دلخواه» ساخته شده باشد، فروشگاه خالی نشود */
      if (cusServers().length) { S.shopStep = 1; S.shopPanel = 0; paintShopPanels(); return; }
      $('view').innerHTML = empty('📦', 'محصولی موجود نیست', 'به زودی طرح‌های جدید اضافه می‌شود.');
      return;
    }
    var ps = shopPanels();

    /* تک‌سروری: مرحلهٔ اول خودکار رد می‌شود — fixed81: مگر خود کاربر به مرحلهٔ سرور برگشته باشد */
    if (ps.length === 1 && !S.shopPanel && !S.shopHome) {
      S.shopPanel = +ps[0].id;
      if (S.shopStep < 2) S.shopStep = 2;
    }
    if (!ps.length) { S.shopStep = 3; S.shopPanel = 0; }
    else if (!S.shopPanel) S.shopStep = 1;

    if (S.shopStep <= 1) { paintShopPanels(); return; }
    if (S.shopStep === 2) { paintShopCats(); return; }
    paintShopList();
  }

  /* مرحلهٔ ۱ — سرور */
  function paintShopPanels() {
    var r = S.products, ps = shopPanels(), h = shopCrumb(1);
    var pcount = (r && r.products) ? r.products.length : 0; /* fixed81 */
    h += '<div class="shead"><b>🌍 اول سرور یا لوکیشن را انتخاب کنید</b>' +
      '<i>' + fa(ps.length) + ' سرور فعال • ' + fa(pcount) + ' طرح آماده</i></div>';
    if (!ps.length && cusServers().length) {
      h += '<div class="alert i">فعلاً طرح آماده‌ای موجود نیست؛ از دکمهٔ زیر می‌توانید پلن دلخواه خودتان را بسازید.</div>';
    }
    ps.forEach(function (p) {
      h += '<button type="button" class="pnl" data-pnl="' + p.id + '">' +
        '<span class="fg">' + (p.flag || '🖥') + '</span>' +
        '<span class="tx"><b>' + esc(p.name) + '</b><i>' + fa(p.count) + ' طرح • ' +
        fa(p.cat_n || (p.cats ? p.cats.length : 0)) + ' دسته</i></span>' +
        '<span class="ar">›</span></button>';
    });
    if (cusServers().length) {
      h += '<button type="button" class="btn w sall" id="cusEntry0">📐 حجم و زمان دلخواه — پلن خودت را بساز</button>';
    }
    $('view').innerHTML = h;
    if ($('cusEntry0')) $('cusEntry0').addEventListener('click', cusSheet);
  }

  /* مرحلهٔ ۲ — دسته‌بندی */
  function paintShopCats() {
    var r = S.products, pn = shopPanel(S.shopPanel);
    if (!pn) { S.shopStep = 1; S.shopPanel = 0; paintShopPanels(); return; }

    var agg = {}, cats = pn.cats || [];
    r.products.forEach(function (p) {
      if (+p.panel_id !== +S.shopPanel) return;
      var k = p.category || 'عمومی';
      if (!agg[k]) agg[k] = { n: 0, min: p.price, mtx: p.price_txt };
      agg[k].n++;
      if (p.price < agg[k].min) { agg[k].min = p.price; agg[k].mtx = p.price_txt; }
    });

    var h = shopCrumb(2);
    h += '<div class="shead"><b>' + (pn.flag || '🖥') + ' ' + esc(pn.name) + '</b>' +
      '<i>یک دسته‌بندی را انتخاب کنید — ' + fa(cats.length) + ' دسته • ' +
      fa(pn.count) + ' طرح</i></div>';
    cats.forEach(function (c) {
      var a = agg[c] || { n: 0, mtx: '' };
      h += '<button type="button" class="pnl cat" data-scat="' + esc(c) + '">' +
        '<span class="fg">📂</span>' +
        '<span class="tx"><b>' + esc(c) + '</b><i>' + fa(a.n) + ' طرح</i></span>' +
        '<span class="ar">›</span></button>';
    });
    h += '<button type="button" class="btn w sall" data-scat="">👀 دیدن همهٔ طرح‌های این سرور</button>';
    /* fixed81: ورودی «حجم و زمان دلخواه» در مرحلهٔ دسته‌بندی هم در دسترس باشد */
    if (cusServers().length) {
      h += '<button type="button" class="btn w sall" id="cusEntry2">📐 حجم و زمان دلخواه — پلن خودت را بساز</button>';
    }
    $('view').innerHTML = h;
    if ($('cusEntry2')) $('cusEntry2').addEventListener('click', cusSheet);
  }

  /* مرحلهٔ ۳ — محصولات */
  function paintShopList() {
    var r = S.products, pn = shopPanel(S.shopPanel), h = shopCrumb(3);
    var list = r.products.filter(function (p) {
      return (!S.shopPanel || +p.panel_id === +S.shopPanel) && (S.cat === '' || p.category === S.cat);
    });

    h += '<div class="shead"><b>🛍 ' + fa(list.length) + ' طرح' +
      (S.cat ? ' در دستهٔ «' + esc(S.cat) + '»' : '') + '</b>' +
      '<i>' + (pn ? (pn.flag || '') + ' ' + esc(pn.name) : 'همهٔ سرورها') + '</i></div>';

    if (!list.length) h += empty('🔍', 'در این دسته طرحی نیست', 'دستهٔ دیگری را امتحان کنید.');
    list.forEach(function (p) { h += prodCard(p); });
    if (cusServers().length) {
      h += '<button type="button" class="btn w sall" id="cusEntry1">📐 حجم و زمان دلخواه — پلن خودت را بساز</button>';
    }
    $('view').innerHTML = h;
    if ($('cusEntry1')) $('cusEntry1').addEventListener('click', cusSheet);
  }

  /* ---------- پلن دلخواه: حجم و زمان دلخواه ---------- */
  function cusServers() {
    return (S.cus && S.cus.servers) ? S.cus.servers : [];
  }

  function cusMoney(n) {
    n = String(Math.max(0, Math.round(n)));
    return fa(n.replace(/\B(?=(\d{3})+(?!\d))/g, ','));
  }

  /* fixed79: محدودهٔ حجم/زمان هر سرور (محصول دلخواه) — اگر نباشد محدودهٔ عمومی */
  function cusLim(c, sv) {
    var n = function (v, d) { v = +v; return (isFinite(v) && v > 0) ? v : d; };
    return {
      min_gb:   n(sv && sv.min_gb,   n(c.min_gb, 1)),
      max_gb:   n(sv && sv.max_gb,   n(c.max_gb, 100)),
      min_days: n(sv && sv.min_days, n(c.min_days, 1)),
      max_days: n(sv && sv.max_days, n(c.max_days, 90))
    };
  }

  function cusApplyLim() {
    var c = S.cus; if (!c) return;
    var sid = +($('cusPn') ? $('cusPn').value : 0), sv = null;
    cusServers().forEach(function (s) { if (+s.panel_id === sid) sv = s; });
    var L = cusLim(c, sv);
    var g = $('cusGb'), d = $('cusDy');
    if (g) { g.min = L.min_gb; g.max = L.max_gb; if (+g.value < L.min_gb || +g.value > L.max_gb) g.value = L.min_gb; }
    if (d) { d.min = L.min_days; d.max = L.max_days; if (+d.value < L.min_days || +d.value > L.max_days) d.value = L.min_days; }
    if ($('cusGbL')) $('cusGbL').textContent = 'حجم (گیگ) — از ' + fa(L.min_gb) + ' تا ' + fa(L.max_gb);
    if ($('cusDyL')) $('cusDyL').textContent = 'مدت (روز) — از ' + fa(L.min_days) + ' تا ' + fa(L.max_days);
  }

  function cusQuote() {
    var c = S.cus;
    if (!c || !$('cusPrice')) return;
    var sid = +($('cusPn') ? $('cusPn').value : 0), sv = null;
    cusServers().forEach(function (s) { if (+s.panel_id === sid) sv = s; });
    if (!sv) return;
    var L = cusLim(c, sv); /* fixed79: محدودهٔ هر سرور/محصول */
    var gb = Math.max(L.min_gb, Math.min(L.max_gb, +($('cusGb') ? $('cusGb').value : 0) || 0));
    var dy = Math.max(L.min_days, Math.min(L.max_days, +($('cusDy') ? $('cusDy').value : 0) || 0));
    var price = gb * (+sv.price_gb || 0) + dy * (+sv.price_day || 0);
    $('cusPrice').textContent = cusMoney(price) + ' ' + (c.currency || 'تومان');
  }

  function cusSheet() {
    var paint = function (c) {
      if (!c || !c.servers || !c.servers.length) { toast('بخش «حجم و زمان دلخواه» فعال نیست.', 'err'); return; }
      var opts = '';
      c.servers.forEach(function (s) {
        opts += '<option value="' + (+s.panel_id) + '">' + (s.flag ? s.flag + ' ' : '') + esc(s.name) + '</option>';
      });
      sheet('📐 حجم و زمان دلخواه',
        '<div class="fld"><label>سرور</label><select id="cusPn" class="mono">' + opts + '</select></div>' +
        '<div class="fld"><label id="cusGbL">حجم (گیگ) — از ' + fa(c.min_gb) + ' تا ' + fa(c.max_gb) + '</label>' +
        '<input id="cusGb" class="mono" type="number" min="' + c.min_gb + '" max="' + c.max_gb + '" value="' + c.min_gb + '"></div>' +
        '<div class="fld"><label id="cusDyL">مدت (روز) — از ' + fa(c.min_days) + ' تا ' + fa(c.max_days) + '</label>' +
        '<input id="cusDy" class="mono" type="number" min="' + c.min_days + '" max="' + c.max_days + '" value="' + c.min_days + '"></div>' +
        '<div class="fld" id="cusUserWrap" style="display:none"><label>نام کاربری دلخواه (لاتین)</label>' +
        '<input id="cusUser" class="mono" placeholder="myusername" maxlength="20"></div>' +
        '<div class="alert i">💵 مبلغ قابل پرداخت: <b id="cusPrice">—</b><br>مبلغ از کیف پول کسر می‌شود و سرویس همین‌جا تحویل داده می‌شود.</div>' +
        '<button type="button" class="btn w" id="cusGo">✅ تایید و پرداخت</button>');
      ['cusPn', 'cusGb', 'cusDy'].forEach(function (i) {
        if ($(i)) { $(i).addEventListener('input', cusQuote); $(i).addEventListener('change', cusQuote); }
      });
      if ($('cusPn')) $('cusPn').addEventListener('change', function () { cusApplyLim(); cusQuote(); }); /* fixed79 */
      cusApplyLim();
      cusQuote();
      if ($('cusGo')) $('cusGo').addEventListener('click', cusDoBuy);
    };
    if (S.cus) { paint(S.cus); return; }
    api('cus_info').then(function (c) { S.cus = (c && c.ok) ? c : null; paint(S.cus); });
  }

  function cusDoBuy() {
    var btn = $('cusGo');
    if (!btn || btn.disabled) return;
    var pn = +($('cusPn') ? $('cusPn').value : 0);
    var gb = +($('cusGb') ? $('cusGb').value : 0);
    var dy = +($('cusDy') ? $('cusDy').value : 0);
    var un = (($('cusUser') && $('cusUser').value) || '').trim();
    btn.disabled = true;
    btn.textContent = 'در حال ساخت سرویس…';
    var svp = null; cusServers().forEach(function (s) { if (+s.panel_id === pn) svp = s; });
    api('cus_buy', { panel_id: pn, product_id: (svp && svp.product_id) ? +svp.product_id : 0, gb: gb, days: dy, username: un }).then(function (r) {
      btn.disabled = false;
      btn.textContent = '✅ تایید و پرداخت';
      if (r.need === 'username') {
        var w = $('cusUserWrap');
        if (w) w.style.display = '';
        if ($('cusUser')) $('cusUser').focus();
        toast(r.message, 'err');
        return;
      }
      if (r.need === 'verify') { closeSheet(); toast(r.message, 'err'); openVerify(); return; }
      if (r.need === 'charge') { closeSheet(); toast(r.message, 'err'); go('wallet'); return; }
      if (!r.ok) { toast(r.message || 'خرید انجام نشد.', 'err'); return; }
      toast(r.message, 'ok');
      S.boot.user.balance_txt = r.balance;
      paintHeader();
      serviceSheet(r.service, true);
    });
  }

  function buySheet(id) {
    var p = null;
    (S.products ? S.products.products : []).forEach(function (x) { if (x.id === id) p = x; });
    if (!p) return;

    sheet('🛒 تایید خرید',
      '<div class="card tight">' +
      '<div class="pn" style="font-weight:700;font-size:15px">' + esc(p.name) + '</div>' +
      '<div class="meta">' +
      '<span class="tag">📊 ' + esc(p.vol_txt) + '</span>' +
      '<span class="tag">📅 ' + esc(p.days_txt) + '</span>' +
      '<span class="tag">👥 ' + esc(p.ip_txt) + '</span></div>' +
      '<div class="price-row"><span style="font-size:12px;color:var(--dim)">مبلغ قابل پرداخت</span>' +
      '<span class="price">' + esc(p.price_txt) + '</span></div></div>' +
      '<div class="fld"><label>کد تخفیف (اختیاری)</label>' +
      '<input id="bCode" class="mono" placeholder="مثال: OFF20"></div>' +
      '<div class="fld" id="bUserWrap" style="display:none"><label>نام کاربری دلخواه (لاتین)</label>' +
      '<input id="bUser" class="mono" placeholder="myname" maxlength="20">' +
      '<div class="hint">۳ تا ۲۰ کاراکتر؛ حروف و اعداد لاتین و خط زیر.</div></div>' +
      '<div class="alert i">مبلغ از کیف پول کسر می‌شود و کانفیگ هم اینجا و هم در چت ربات برای شما ارسال می‌شود.</div>' +
      '<button type="button" class="btn w" id="bGo" data-do-buy="' + p.id + '">تایید و پرداخت</button>');
  }

  function doBuy(id, btn) {
    var code = ($('bCode') && $('bCode').value || '').trim();
    var un = ($('bUser') && $('bUser').value || '').trim();
    btn.disabled = true;
    btn.innerHTML = '<span class="spin"></span> در حال ساخت کانفیگ…';

    api('buy', { id: id, code: code, username: un }).then(function (r) {
      btn.disabled = false;
      btn.textContent = 'تایید و پرداخت';

      if (r.need === 'username') {
        var w = $('bUserWrap');
        if (w) { w.style.display = 'block'; if ($('bUser')) $('bUser').focus(); }
        toast(r.message, 'err');
        return;
      }
      if (r.need === 'verify') { closeSheet(); toast(r.message, 'err'); openVerify(); return; }
      if (r.need === 'charge') { closeSheet(); toast(r.message, 'err'); go('wallet'); return; }
      if (!r.ok) { toast(r.message || 'خرید انجام نشد.', 'err'); return; }

      toast(r.message, 'ok');
      S.boot.user.balance_txt = r.balance;
      paintHeader();
      serviceSheet(r.service, true);
    });
  }

  /* ================= سرویس‌ها ================= */
  function viewSvc() {
    skeleton(3);
    api('services').then(function (r) {
      if (!r.ok) { $('view').innerHTML = '<div class="alert e">' + esc(r.message) + '</div>'; return; }
      S.svcs = r.services || [];
      if (!S.svcF) S.svcF = 'all';
      S.svcQ = '';
      paintSvc();
    });
  }

  /* فیلترهای فهرست سرویس */
  var SVF = [['all', '📋 همه'], ['active', '🟢 فعال'], ['soon', '⚠️ نزدیک پایان'],
             ['expired', '⌛ منقضی'], ['test', '🧪 تست']];

  function svcMatch(s, f) {
    var pct = Number(s.percent) || 0;
    if (f === 'active') return s.status === 'active';
    if (f === 'soon') return s.status === 'active' && pct >= 80;
    if (f === 'expired') return s.status === 'expired' || s.status === 'missing';
    if (f === 'test') return !!s.is_test;
    return true;
  }

  function paintSvc() {
    var list = S.svcs || [];
    if (!list.length) {
      $('view').innerHTML = empty('📦', 'هنوز سرویسی ندارید', 'از بخش محصولات یک طرح تهیه کنید.',
        '<div style="margin-top:14px"><button type="button" class="btn" data-go="shop">مشاهده محصولات</button></div>');
      return;
    }

    var act = 0, soon = 0, exp = 0;
    list.forEach(function (s) {
      if (s.status === 'active') act++;
      if (s.status === 'active' && (Number(s.percent) || 0) >= 80) soon++;
      if (s.status === 'expired' || s.status === 'missing') exp++;
    });

    var h = '<div class="sv-s">' +
      '<div class="c g"><div class="v">' + fa(act) + '</div><div class="t">فعال</div></div>' +
      '<div class="c o"><div class="v">' + fa(soon) + '</div><div class="t">نزدیک پایان</div></div>' +
      '<div class="c"><div class="v">' + fa(exp) + '</div><div class="t">منقضی</div></div>' +
      '<div class="c"><div class="v">' + fa(list.length) + '</div><div class="t">کل سرویس‌ها</div></div>' +
      '</div>';

    if (soon > 0) {
      h += '<div class="alert w">⚠️ ' + fa(soon) + ' سرویس بیش از ۸۰٪ حجم خود را مصرف کرده است؛ ' +
        'با دکمهٔ تمدید حجم تازه بگیرید.</div>';
    }

    h += '<div class="sv-q"><input id="svQ" type="search" placeholder="🔍 جستجو در نام سرویس…" value="' +
      esc(S.svcQ || '') + '"></div>';
    h += '<div class="svf" id="svF"></div><div id="svList"></div>';
    /* fixed83: پاک‌سازی گروهی کانفیگ‌های قطع (منقضی/غیرفعال/حجم تمام‌شده) */
    var deadN = list.filter(function (s) { return s.is_dead && s.can_del !== false; }).length;
    if (deadN > 0) {
      h += '<button type="button" class="btn gh w" data-dead="1" style="margin-top:4px">🧹 حذف کانفیگ‌های قطع (' + fa(deadN) + ')</button>';
    }
    h += '<button type="button" class="btn gh w" data-go="shop" style="margin-top:4px">🛒 خرید سرویس تازه</button>';

    $('view').innerHTML = h;
    paintSvcChips();
    paintSvcList();

    var q = $('svQ');
    if (q) q.addEventListener('input', function () { S.svcQ = this.value; paintSvcList(); });
  }

  function paintSvcChips() {
    var box = $('svF');
    if (!box) return;
    var h = '';
    SVF.forEach(function (f) {
      h += '<button type="button" data-svf="' + f[0] + '"' +
        ((S.svcF || 'all') === f[0] ? ' class="on"' : '') + '>' + f[1] + '</button>';
    });
    box.innerHTML = h;
    Array.prototype.forEach.call(box.querySelectorAll('[data-svf]'), function (b) {
      b.addEventListener('click', function () {
        S.svcF = b.getAttribute('data-svf');
        haptic();
        paintSvcChips();
        paintSvcList();
      });
    });
  }

  function paintSvcList() {
    var box = $('svList');
    if (!box) return;
    var q = String(S.svcQ || '').trim().toLowerCase();
    var rows = (S.svcs || []).filter(function (s) {
      if (!svcMatch(s, S.svcF || 'all')) return false;
      return !q || String(s.name || '').toLowerCase().indexOf(q) >= 0;
    });
    if (!rows.length) {
      box.innerHTML = '<div class="card">' +
        empty('🔍', 'موردی پیدا نشد', 'فیلتر یا عبارت جستجو را تغییر دهید.') + '</div>';
      return;
    }
    var h = '';
    rows.forEach(function (s) { h += svcCard(s); });
    box.innerHTML = h;
  }

  function svcCard(s) {
    var bd = s.status === 'active' ? 'g' : (s.status === 'expired' ? 'o' : 'r');
    var st = s.status === 'active' ? 'فعال'
      : (s.status === 'expired' ? 'منقضی'
      : (s.status === 'missing' ? '🚫 حذف‌شده از پنل' : 'غیرفعال'));
    var pct = Math.max(0, Math.min(100, Number(s.percent) || 0));
    var sub = s.sub || s.sub_link || s.link || '';
    return '<div class="card sv-card">' +
      '<div data-svc="' + s.id + '">' +
        '<div class="sv-h"><div class="n">' + esc(s.name) + '</div>' +
        '<span class="bdg ' + bd + '">' + st + '</span></div>' +
        '<div class="sv-m">' + (s.is_test ? '🧪 اکانت تست · ' : '') + '⏰ ' + esc(s.remain_txt || '') + '</div>' +
        '<div class="bar' + (pct > 80 ? ' hi' : '') + '" style="margin-top:9px"><i style="width:' + pct + '%"></i></div>' +
        '<div class="sv-f"><span>مصرف: ' + esc(s.used_txt || '') + '</span>' +
        '<span>مانده: ' + esc(s.left_txt || '') + '</span></div>' +
      '</div>' +
      '<div class="sv-act">' +
        '<button type="button" data-svc="' + s.id + '">📄 جزئیات</button>' +
        (sub ? '<button type="button" data-copy="' + esc(sub) + '">📋 کپی لینک</button>' : '') +
        '<button type="button" data-renew="' + s.id + '">🔄 تمدید</button>' +
      '</div>' +
    '</div>';
  }

  /* ============ جزئیات سرویس – کارت سه‌بعدی ============ */

  /** نوار پیشرفت سه‌بعدی */

  function bar3d(pct, kind) {
    pct = Math.max(0, Math.min(100, Number(pct) || 0));
    return '<div class="bar3d ' + (kind || '') + '"><i style="width:' + pct + '%"></i>' +
           '<b>' + fa(Math.round(pct)) + '٪</b></div>';
  }

  /** پلاک مقدار سه‌بعدی */
  function tile3d(ic, lbl, val, kind) {
    return '<div class="t3d ' + (kind || '') + '">' +
      '<span class="i">' + ic + '</span>' +
      '<span class="l">' + esc(lbl) + '</span>' +
      '<span class="v">' + val + '</span>' +
    '</div>';
  }

  /** درصد مصرف حجم (۱- یعنی بی‌نهایت) */
  function svcPct(s) {
    var tot = Number(s.volume_gb || s.volume || 0);
    var usd = Number(s.used_gb || s.used || 0);
    if (!tot || tot <= 0) return -1;
    return Math.min(100, (usd / tot) * 100);
  }

  function serviceSheet(s, fresh) {
    var cfg = '';
    (s.configs || []).forEach(function (c, i) {
      cfg += '<div class="cfg-h">⚙️ کانفیگ ' + fa(i + 1) + '</div>' + copyBox(c);
    });

    var pct   = svcPct(s);
    var kind  = pct < 0 ? 'ok' : (pct >= 90 ? 'er' : (pct >= 70 ? 'wr' : 'ok'));
    var state = (s.active === false || s.enabled === false) ? 'off' : 'on';

    var head = '<div class="svc3d ' + kind + '" id="svc3d">' +
      '<div class="glow"></div>' +
      '<div class="sheen"></div>' +
      '<div class="in">' +
        '<div class="hd">' +
          '<div class="chip3d ' + state + '">' + (state === 'on' ? '● فعال' : '○ غیرفعال') + '</div>' +
          '<div class="nmw"><span class="nm mono">' + esc(s.name) + '</span></div>' +
        '</div>' +
        (pct >= 0
          ? '<div class="ring3d"><svg viewBox="0 0 120 120">' +
              '<circle class="trk" cx="60" cy="60" r="50"></circle>' +
              '<circle class="val" cx="60" cy="60" r="50" ' +
                'stroke-dasharray="' + (2 * Math.PI * 50).toFixed(1) + '" ' +
                'stroke-dashoffset="' + (2 * Math.PI * 50 * (1 - pct / 100)).toFixed(1) + '"></circle>' +
            '</svg><div class="ctr"><b>' + fa(Math.round(pct)) + '٪</b><span>مصرف شده</span></div></div>'
          : '<div class="ring3d inf"><div class="ctr"><b>∞</b><span>بدون محدودیت</span></div></div>') +
        '<div class="g3d">' +
          tile3d('📊', 'حجم کل', esc(s.volume_txt), '') +
          tile3d('📉', 'مصرف شده', esc(s.used_txt), 'wr') +
          tile3d('📈', 'باقی‌مانده', esc(s.left_txt), 'ok') +
          tile3d('⏱', 'زمان مانده', esc(s.remain_txt), '') +
        '</div>' +
      '</div>' +
    '</div>';

    var body = head +
      '<div class="card tight g3">' +
        row('⏰', 'تاریخ انقضا', '', esc(s.expire_txt)) +
        (s.plan_txt    ? row('🏷', 'طرح', '', esc(s.plan_txt))            : '') +
        (s.panel_txt   ? row('🖥', 'سرور', '', esc(s.panel_txt))           : '') +
        (s.device      ? row('📱', 'دستگاه مجاز', '', fa(s.device))     : '') +
        (s.created_txt ? row('📅', 'تاریخ ساخت', '', esc(s.created_txt)) : '') +
      '</div>' +
      (pct >= 0 ? '<div class="sec-t"><span>📉 مصرف حجم</span></div>' + bar3d(pct, kind) : '') +
      (s.sub
        ? '<div class="sec-t"><span>🔗 لینک اشتراک (پیشنهادی)</span></div>' +
          '<div class="hint3d">یک لینک برای همهٔ کانفیگ‌ها با به‌روزرسانی خودکار</div>' +
          copyBox(s.sub)
        : '') +
      (cfg ? '<div class="sec-t"><span>⚙️ کانفیگ‌های مستقیم</span></div>' + cfg : '') +
      (!s.sub && !cfg ? '<div class="alert e">لینک اتصال ثبت نشده است؛ با پشتیبانی تماس بگیرید.</div>' : '') +
      '<div class="btn-row" style="margin-top:14px">' +
        (s.can_sync !== false ? '<button type="button" class="btn gh b3d" data-sync="' + s.id + '">🔄 به‌روزرسانی مصرف</button>' : '') +
        (s.can_tut  !== false ? '<button type="button" class="btn gh b3d" data-go="tut">🎓 راهنمای اتصال</button>' : '') +
        (s.can_devs ? '<button type="button" class="btn gh b3d" data-devs="' + s.id + '">📱 دستگاه‌های من</button>' : '') +
        (s.can_links ? '<button type="button" class="btn gh b3d" data-links="' + s.id + '">🔗 لینک‌ها و Happ</button>' : '') +
      '</div>' +
      (s.can_renew ? '<button type="button" class="btn b3d" style="width:100%;margin-top:4px" data-renew="' + s.id + '">♻️ تمدید سرویس</button>' : '') +
      (s.can_del ? '<button type="button" class="btn gh b3d" style="width:100%;margin-top:8px" data-del="' + s.id + '">🗑 حذف سرویس و عودت وجه</button>' : '');

    sheet((fresh ? '🎉 سرویس آماده است' : '📦 جزئیات سرویس'), body);
    tilt3d('svc3d');
  }

  /** چرخش سه‌بعدی کارت بر اساس موقعیت اشاره‌گر */
  function tilt3d(id) {
    var el = $(id);
    if (!el) return;

    var raf = 0, tx = 0, ty = 0;

    function apply() {
      raf = 0;
      el.style.transform = 'perspective(900px) rotateX(' + ty.toFixed(2) + 'deg) rotateY(' + tx.toFixed(2) + 'deg)';
    }

    function move(cx, cy) {
      var r = el.getBoundingClientRect();
      if (!r.width || !r.height) return;
      tx = ((cx - r.left) / r.width - 0.5) * 11;
      ty = -((cy - r.top) / r.height - 0.5) * 8;
      if (!raf) raf = requestAnimationFrame(apply);
    }

    function reset() {
      tx = 0; ty = 0;
      if (!raf) raf = requestAnimationFrame(apply);
    }

    el.addEventListener('mousemove', function (e) { move(e.clientX, e.clientY); });
    el.addEventListener('mouseleave', reset);
    el.addEventListener('touchmove', function (e) {
      if (e.touches && e.touches[0]) move(e.touches[0].clientX, e.touches[0].clientY);
    }, { passive: true });
    el.addEventListener('touchend', reset);
  }

  /* ============ دستگاه‌ها و لینک‌های تکمیلی (0.0.2 #ma-dev-ui) ============ */
  function maLoading(title) {
    sheet(title, '<div class="card tight g3"><div class="row"><div class="ri">⏳</div>' +
      '<div class="rt"><b>در حال دریافت اطلاعات…</b><span>چند لحظه صبر کنید</span></div></div></div>');
  }

  function devicesSheet(id) {
    maLoading('📱 دستگاه‌های من');
    api('svc_devices', { id: id }).then(function (r) {
      if (!r.ok) { toast(r.message || 'دریافت فهرست دستگاه‌ها ناموفق بود.', 'err'); closeSheet(); return; }
      paintDevices(id, r);
    });
  }

  function paintDevices(id, r) {
    if (!r.supported) {
      sheet('📱 دستگاه‌های من',
        '<div class="alert e">سرور این سرویس از مدیریت دستگاه‌ها پشتیبانی نمی‌کند.</div>');
      return;
    }
    var items = r.items || [];
    var lim   = +(r.limit || 0);
    var h = '<div class="card tight g3">' +
      row('📱', 'دستگاه‌های ثبت‌شده', '', fa(items.length) + (lim > 0 ? ' / ' + fa(lim) : '')) +
      (lim > 0 ? row('⚖', 'ظرفیت آزاد', '', fa(Math.max(0, lim - items.length))) : '') +
      '</div>';
    if (!items.length) {
      h += '<div class="hint3d">هنوز دستگاهی روی این سرویس ثبت نشده است؛ با اولین اتصال ثبت می‌شود.</div>';
    } else {
      h += '<div class="sec-t"><span>📱 فهرست دستگاه‌ها</span></div><div class="card tight g3">';
      items.forEach(function (d) {
        h += '<div class="row"><div class="ri">📱</div><div class="rt"><b>' + esc(d.title || ('#' + d.id)) + '</b>' +
          (d.seen_txt ? '<span>' + esc(d.seen_txt) + '</span>' : '') + '</div>' +
          '<div class="rv"><button type="button" class="btn gh b3d" data-devdel="' + id + ':' + d.id + '">🗑</button></div></div>';
      });
      h += '</div><button type="button" class="btn gh b3d" style="width:100%;margin-top:10px" data-devclr="' + id + '">🧹 حذف همهٔ دستگاه‌ها</button>';
    }
    h += '<div style="font-size:11px;color:var(--mut);margin-top:10px">با حذف هر دستگاه، یک ظرفیت آزاد می‌شود و می‌توانید روی دستگاه تازه وصل شوید.</div>';
    sheet('📱 دستگاه‌های من', h);
  }

  function doDevDel(id, dev, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spin"></span>'; }
    api('svc_device_del', { id: id, device: dev }).then(function (r) {
      if (!r.ok) {
        toast(r.message || 'حذف دستگاه انجام نشد.', 'err');
        if (btn) { btn.disabled = false; btn.textContent = '🗑'; }
        return;
      }
      toast(r.message || 'دستگاه حذف شد.', 'ok');
      paintDevices(id, { supported: true, items: r.items || [], limit: r.limit || 0 });
    });
  }

  function doDevClear(id, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spin"></span>'; }
    api('svc_devices_clear', { id: id }).then(function (r) {
      if (!r.ok) {
        toast(r.message || 'پاک‌سازی دستگاه‌ها انجام نشد.', 'err');
        if (btn) { btn.disabled = false; btn.textContent = '🧹 حذف همهٔ دستگاه‌ها'; }
        return;
      }
      toast(r.message || 'دستگاه‌ها پاک شدند.', 'ok');
      paintDevices(id, { supported: true, items: r.items || [], limit: r.limit || 0 });
    });
  }

  function linksSheet(id) {
    maLoading('🔗 لینک‌ها و Happ');
    api('svc_links', { id: id }).then(function (r) {
      if (!r.ok) { toast(r.message || 'دریافت لینک‌ها ناموفق بود.', 'err'); closeSheet(); return; }
      if (!r.supported) {
        sheet('🔗 لینک‌ها و Happ',
          '<div class="alert e">برای این سرویس لینک تکمیلی در دسترس نیست.</div>');
        return;
      }
      var h = '';
      if (r.happ) {
        h += '<div class="sec-t"><span>🚀 ایمپورت یک‌ضربه‌ای در Happ</span></div>' +
          '<div class="hint3d">این لینک را کپی کنید و در اپلیکیشن Happ باز کنید تا اشتراک خودکار اضافه شود.</div>' +
          copyBox(String(r.happ));
      }
      var items = r.items || [];
      if (items.length) {
        h += '<div class="sec-t"><span>🔗 لینک‌های دیگر</span></div>';
        items.forEach(function (it) {
          var u = String(it.link || it.url || '');
          if (!u) return;
          h += '<div class="cfg-h">' + esc(it.title || it.name || 'لینک') + '</div>' + copyBox(u);
        });
      }
      if (!h) h = '<div class="alert e">لینکی برای این سرویس ثبت نشده است.</div>';
      sheet('🔗 لینک‌ها و Happ', h);
    });
  }

  /* ================= تمدید و حذف سرویس ================= */
  function renewSheet(id) {
    api('svc_renew_plans', { id: id }).then(function (r) {
      if (!r.ok) { toast(r.message, 'err'); return; }
      if (!r.plans || !r.plans.length) { toast('طرحی برای تمدید این سرویس موجود نیست.', 'err'); return; }
      var h = '<div class="card tight" style="margin-bottom:10px">' +
        row('👛', 'موجودی کیف پول', '', esc(r.balance)) + '</div>';
      r.plans.forEach(function (p) {
        h += '<button type="button" class="btn ' + (p.afford ? '' : 'gh') + '" style="width:100%;margin-bottom:6px" data-rnw="' + id + ':' + p.id + '">' +
          '♻️ ' + esc(p.name) + ' — ' + esc(p.price_txt) + '</button>' +
          '<div style="font-size:10.5px;color:var(--mut);margin:0 4px 11px">📊 ' + esc(p.volume_txt) + ' • ⏰ ' + esc(p.days_txt) +
          (p.afford ? '' : ' • ⚠️ موجودی کافی نیست') + '</div>';
      });
      h += '<div style="font-size:11px;color:var(--mut)">مبلغ طرح از کیف پول کسر می‌شود و حجم و زمان طبق روش تمدید همان سرور اعمال می‌شود.</div>';
      sheet('♻️ تمدید سرویس', h);
    });
  }

  function doRenew(id, planId, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spin"></span>'; }
    api('svc_renew', { id: id, plan_id: planId }).then(function (r) {
      if (!r.ok) {
        toast(r.message, 'err');
        if (btn) { btn.disabled = false; btn.textContent = '♻️ تلاش دوباره'; }
        return;
      }
      toast(r.message, 'ok');
      if (r.service) serviceSheet(r.service, false); else closeSheet();
      try { go('services'); } catch (e) {}
    });
  }

  function delSheet(id) {
    api('svc_del_quote', { id: id }).then(function (r) {
      if (!r.ok) { toast(r.message, 'err'); return; }
      var h = '<div class="card tight">' +
        row('📈', 'حجم مصرف‌نشده', '', esc(r.left_txt)) +
        row('⏱', 'زمان باقی‌مانده', '', esc(r.days_txt)) +
        row('💳', 'پرداختی این سرویس', '', esc(r.pool_txt)) +
        (r.fee > 0 ? row('➖', 'کارمزد حذف', '', esc(r.fee_txt)) : '') +
        row('💰', 'مبلغ عودتی به کیف پول', '', esc(r.refund_txt)) +
        '</div>' +
        (r.note ? '<div class="alert w">ℹ️ ' + esc(r.note) + '</div>' : '') +
        '<div class="alert e">با حذف، اتصال این سرویس قطع می‌شود و بازگشتی ��دارد.</div>' +
        '<button type="button" class="btn" style="width:100%" data-delok="' + id + '">🗑 تایید حذف' + (r.refund > 0 ? ' و دریافت ' + esc(r.refund_txt) : '') + '</button>';
      sheet('🗑 حذف سرویس', h);
    });
  }

  function doDel(id, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spin"></span>'; }
    api('svc_delete', { id: id, confirm: 'yes' }).then(function (r) {
      if (!r.ok) {
        toast(r.message, 'err');
        if (btn) { btn.disabled = false; btn.textContent = '🗑 تلاش دوباره'; }
        return;
      }
      toast(r.message, 'ok');
      closeSheet();
      try { go('services'); } catch (e) {}
    });
  }

  /* ================= حذف کانفیگ‌های قطع (fixed83) ================= */
  function deadSheet() {
    api('svc_dead').then(function (r) {
      if (!r.ok) { toast(r.message, 'err'); return; }
      if (!r.enabled) { toast('حذف کانفیگ‌های قطع توسط مدیر غیرفعال است.', 'err'); return; }
      if (!r.count) { toast('کانفیگ قطع‌شده‌ای ندارید.', 'ok'); return; }

      var h = '<div class="card tight">';
      r.items.forEach(function (it) {
        h += row('⛔️', it.name, it.status_txt || '', it.refund > 0 ? esc(it.refund_txt) : 'بدون عودت');
      });
      h += '</div>' +
        '<div class="card tight" style="margin-top:8px">' +
          row('🔢', 'تعداد کانفیگ قطع', '', fa(r.count)) +
          row('💰', 'جمع مبلغ عودتی به کیف پول', '', esc(r.refund_txt)) +
        '</div>' +
        '<div class="alert e">این کانفیگ‌ها از سرور پاک می‌شوند و بازگشتی ندارد.</div>' +
        '<button type="button" class="btn" style="width:100%" data-deadok="1">🧹 تایید حذف ' + fa(r.count) +
        ' کانفیگ' + (r.refund > 0 ? ' و دریافت ' + esc(r.refund_txt) : '') + '</button>';
      sheet('🧹 حذف کانفیگ‌های قطع', h);
    });
  }

  function doPurgeDead(btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spin"></span>'; }
    api('svc_purge_dead', { confirm: 'yes' }).then(function (r) {
      if (!r.ok) {
        toast(r.message, 'err');
        if (btn) { btn.disabled = false; btn.textContent = '🧹 تلاش دوباره'; }
        return;
      }
      toast(r.message, 'ok');
      closeSheet();
      try { go('services'); } catch (e) {}
    });
  }

  /* ================= کیف پول ================= */
  function viewWallet() {
    skeleton(2);
    api('wallet').then(function (r) {
      if (!r.ok) { $('view').innerHTML = '<div class="alert e">' + esc(r.message) + '</div>'; return; }
      S.wallet = r;
      if (!S.wf) S.wf = 'all';
      paintWallet();
    });
  }

  /* فیلترهای تاریخچهٔ کیف پول */
  var WFS = [['all', '📜 همه'], ['in', '🟢 شارج'], ['out', '🔴 خرید'], ['pending', '⏳ در انتطار']];

  function paintWallet() {
    var r = S.wallet;
    if (!r) return;
    var tx = r.tx || [];
    var pend = 0, cIn = 0, cOut = 0;
    tx.forEach(function (t) {
      if (t.state === 'pending') pend++;
      else if (Number(t.amount) >= 0) cIn++;
      else cOut++;
    });

    var h = '<div class="card hero"><div class="lbl">موجودی قابل استفاده</div>' +
      '<div class="big">' + esc(r.balance_txt) + '</div>' +
      '<div class="qa" style="grid-template-columns:repeat(3,1fr)">' +
      '<button type="button" data-go="charge"><span class="ic">➕</span>شارج کیف پول</button>' +
      '<button type="button" data-go="cards"><span class="ic">💳</span>کارت‌های من</button>' +
      '<button type="button" data-go="rate"><span class="ic">📈</span>نرخ لحظه‌ای</button>' +
      '</div></div>';

    if (pend > 0) {
      h += '<div class="alert w">⏳ ' + fa(pend) +
        ' تراکنش در انتطار تایید است؛ پس از بررسی مدیر، موجودی شما افزوده می‌شود.</div>';
    }

    h += '<div class="sv-s">' +
      '<div class="c g"><div class="v" style="font-size:13px">' + esc(r.in_txt) + '</div><div class="t">مجموع شارج</div></div>' +
      '<div class="c o"><div class="v" style="font-size:13px">' + esc(r.out_txt) + '</div><div class="t">مجموع خرید</div></div>' +
      '<div class="c"><div class="v">' + fa(tx.length) + '</div><div class="t">تراکنش</div></div>' +
      '</div>';

    h += '<div class="sec-t"><span>📜 تاریخچهٔ تراکنش‌ها</span>' +
      '<span class="more" style="color:var(--mut)">' + fa(cIn) + ' شارج · ' + fa(cOut) + ' خرید</span></div>';
    h += '<div class="wf" id="wF"></div><div id="wList"></div>';

    $('view').innerHTML = h;
    paintWfChips();
    paintWtx();
  }

  function paintWfChips() {
    var box = $('wF');
    if (!box) return;
    var h = '';
    WFS.forEach(function (f) {
      h += '<button type="button" data-wf="' + f[0] + '"' +
        ((S.wf || 'all') === f[0] ? ' class="on"' : '') + '>' + f[1] + '</button>';
    });
    box.innerHTML = h;
    Array.prototype.forEach.call(box.querySelectorAll('[data-wf]'), function (b) {
      b.addEventListener('click', function () {
        S.wf = b.getAttribute('data-wf');
        haptic();
        paintWfChips();
        paintWtx();
      });
    });
  }

  function paintWtx() {
    var box = $('wList');
    if (!box) return;
    var f = S.wf || 'all';
    var rows = ((S.wallet && S.wallet.tx) || []).filter(function (t) {
      if (f === 'in') return Number(t.amount) >= 0 && t.state !== 'pending';
      if (f === 'out') return Number(t.amount) < 0;
      if (f === 'pending') return t.state === 'pending';
      return true;
    });
    if (!rows.length) {
      box.innerHTML = '<div class="card">' + empty('🧾', 'تراکنشی در این دسته نیست', '') + '</div>';
      return;
    }
    var h = '<div class="card tight">';
    rows.forEach(function (t) {
      var cls = Number(t.amount) >= 0 ? 'p' : 'n';
      var ic = t.state === 'pending' ? '⏳'
        : (t.state === 'rejected' ? '❌' : (Number(t.amount) >= 0 ? '🟢' : '🔴'));
      h += '<div class="row"><div class="ri">' + ic + '</div><div class="rt"><b>' + esc(t.type) + '</b>' +
        '<span>' + esc(t.status) + ' · ' + esc(t.date) + '</span></div>' +
        '<div class="rv ' + cls + '">' + esc(t.amt_txt) + '</div></div>';
    });
    h += '</div>';
    box.innerHTML = h;
  }


  function chargeSheet() {
    var b = S.boot;
    var ms = [];
    if (b.flags.card) ms.push(['card', '💳 کارت به کارت']);
    if (b.flags.hooshpay) ms.push(['hooshpay', '🪙 هوش‌پی (آنی)']);
    if (b.flags.crypto) ms.push(['crypto', '🌐 پرداخت ارزی']);

    if (!ms.length) { sheet('شارژ کیف پول', '<div class="alert e">هیچ روش پرداختی فعال نیست.</div>'); return; }

    var opt = '';
    ms.forEach(function (m, i) {
      opt += '<button type="button" class="chip' + (i === 0 ? ' on' : '') + '" data-pm="' + m[0] + '">' + m[1] + '</button>';
    });

    sheet('➕ شارژ کیف پول',
      '<div class="fld"><label>مبلغ مورد نظر (' + esc(b.shop.currency) + ')</label>' +
      '<input id="cAmt" class="mono" type="number" inputmode="numeric" placeholder="100000">' +
      '<div class="hint" id="cHint">مبلغ را وارد کنید تا نرخ لحظه‌ای محاسبه شود.</div></div>' +
      '<div style="font-size:11.5px;color:var(--dim);margin:4px 2px 7px;font-weight:600">روش پرداخت</div>' +
      '<div class="chips" id="pmRow">' + opt + '</div>' +
      '<div id="rateBox"></div>' +
      '<button type="button" class="btn w" id="cGo" style="margin-top:8px">ادامه</button>');

    S.pm = ms[0][0];
    var t = null;
    $('cAmt').addEventListener('input', function () {
      clearTimeout(t);
      var v = parseInt(this.value || '0', 10) || 0;
      if (S.pm !== 'crypto' || v <= 0) { $('rateBox').innerHTML = ''; return; }
      $('rateBox').innerHTML = '<div class="alert i"><span class="spin"></span> دریافت نرخ لحظه‌ای…</div>';
      t = setTimeout(function () { liveRate(v); }, 650);
    });
  }

  function liveRate(amount) {
    api('rate', { amount: amount }).then(function (r) {
      if (!r.ok) { $('rateBox').innerHTML = '<div class="alert e">' + esc(r.message) + '</div>'; return; }
      var h = '<div class="card tight"><div style="font-size:11.5px;color:var(--dim)">نرخ لحظه‌ای دلار</div>' +
        '<div style="font-weight:700;font-size:15px;margin:2px 0 6px">' + esc(r.rate_txt) + '</div>';
      r.assets.forEach(function (a) {
        h += '<div class="row"><div class="ri">' + a.icon + '</div><div class="rt"><b>' + esc(a.key) + '</b>' +
          '<span>شبکه: ' + esc(a.network) + '</span></div>' +
          '<div class="rv">' + esc(a.qty_txt) + '</div></div>';
      });
      h += '<div class="hint">⏱ همین لحظه از بازار گرفته شد • ' + esc(r.at) + '</div></div>';
      $('rateBox').innerHTML = h;
    });
  }

  function doTopup() {
    var v = parseInt(($('cAmt') && $('cAmt').value) || '0', 10) || 0;
    if (v <= 0) { toast('مبلغ را وارد کنید.', 'err'); return; }

    api('topup', { amount: v, method: S.pm }).then(function (r) {
      if (!r.ok) { toast(r.message, 'err'); return; }

      var h = '<div class="card tight"><div style="font-size:11.5px;color:var(--dim)">مبلغ سفارش</div>' +
        '<div style="font-weight:700;font-size:17px">' + esc(r.amount_txt) + '</div></div>';

      if (r.method === 'card') {
        h += '<div class="sec-t"><span>💳 واریز کارت به کارت</span></div>' +
          c3dShopCard(r.card) + copyBox(r.card.number);
        if (r.card.holder) h += '<div class="card tight">' + row('👤', 'به نام', '', esc(r.card.holder)) +
          (r.card.bank ? row('🏦', 'بانک', '', esc(r.card.bank)) : '') + '</div>';

        /* آپلود رسید در همین صفحه – دیگر لازم نیست کاربر به چت ربات برود */
        if (r.tx) {
          S.rcp = { tx: r.tx, img: '' };
          h += '<div class="sec-t"><span>🧾 مرحلهٔ آخر: ارسال رسید</span>' +
            '<span class="bdg b">پیگیری #' + esc(String(r.tx)) + '</span></div>' +
            '<div class="card tight">' +
            '<div style="font-size:12px;color:var(--dim);line-height:2">' +
              '<div>۱ – مبلغ بالا را به شمارهٔ کارت واریز کنید.</div>' +
              '<div>۲ – از رسید، عکس یا اسکرین‌شات بگیرید.</div>' +
              '<div>۳ – عکس را همین‌جا آپلود کنید.</div>' +
            '</div>' +
            '<input id="rcpFile" type="file" accept="image/*" style="display:none">' +
            '<button type="button" class="btn gh w" id="rcpPick" style="margin-top:9px">📷 انتخاب عکس رسید</button>' +
            '<div id="rcpPrev"></div>' +
            '<button type="button" class="btn w" id="rcpGo" style="margin-top:8px;display:none">✅ ارسال رسید برای بررسی</button>' +
            '<div class="hint">اگر ترجیح می‌دهید، می‌توانید عکس رسید را در چت ربات هم بفرستید.</div>' +
            '</div>';
        }
      } else if (r.method === 'hooshpay') {
        h += hpPayBox(r);
      } else {
        if (r.rate_txt) {
          h += '<div class="card tight"><div style="font-size:11.5px;color:var(--dim)">نرخ لحظه‌ای دلار</div>' +
            '<div style="font-weight:700">' + esc(r.rate_txt) + '</div></div>';
        }
        var opts = '';
        (r.assets || []).forEach(function (a) {
          h += '<div class="sec-t"><span>' + a.icon + ' ' + esc(a.label) + '</span>' +
            '<span class="bdg b">' + esc(a.network) + '</span></div>' +
            '<div class="card tight">' +
            (a.qty_txt ? '<div style="font-size:11.5px;color:var(--dim)">مقدار واریزی</div>' +
              '<div style="font-weight:700;font-size:15px" class="mono">' + esc(a.qty_txt) + ' ' + esc(a.key) + '</div>' : '') +
            copyBox(a.address) +
            (a.memo ? '<div style="font-size:11.5px;color:var(--dim);margin-top:7px">🏷 ممو / تگ (الزامی)</div>' + copyBox(a.memo) : '') +
            (a.note ? '<div class="hint">ℹ️ ' + esc(a.note) + '</div>' : '') +
            '</div>';
          opts += '<option value="' + esc(a.id) + '">' + esc(a.label) + ' • ' + esc(a.network) + '</option>';
        });

        S.tu = { amount: r.amount || v, gw: '' };

        if (r.can_hash) {
          h += '<div class="sec-t"><span>🔗 مرحلهٔ آخر: ثبت هش تراکنش</span></div>' +
            '<div class="card tight">' +
            '<div class="fld"><label>واریز از کدام شبکه انجام شد؟</label>' +
            '<select id="hxGw">' + opts + '</select></div>' +
            '<div class="fld"><label>هش تراکنش (TXID)</label>' +
            '<input id="hxTx" class="mono" type="text" autocomplete="off" placeholder="0x…"></div>' +
            '<div class="hint">هش را همین‌جا بفرستید؛ اگر خودکار تایید شود، موجودی فوراً شارژ می‌شود.</div>' +
            '<button type="button" class="btn w" id="hxGo" style="margin-top:8px">✅ ارسال هش و ثبت واریز</button>' +
            '</div>';
        }
      }

      h += '<div class="alert w">' + esc(r.note) + '</div>' +
        '<button type="button" class="btn gh w" data-bot="1">💬 ادامه در چت ربات</button>';

      sheet('🧾 دستور پرداخت', h);
    });
  }

  /* ================= رسید کارت به کارت – داخل اپ ================= */
  /* ================= هوش‌پی – کارت پرداخت و بررسی وضعیت ================= */
  function hpPayBox(r) {
    var h = '<div class="sec-t"><span>🪙 پرداخت آنی هوش‌پی</span>' +
      (r.tx ? '<span class="bdg b">پیگیری #' + esc(String(r.tx)) + '</span>' : '') + '</div>' +
      '<div class="card tight">';

    if (r.payable_txt) {
      h += '<div style="font-size:11.5px;color:var(--dim)">مبلغ قابل پرداخت — باید دقیقاً همین مبلغ واریز شود</div>' +
        '<div class="mono" style="font-weight:800;font-size:17px;margin:2px 0 4px">' + esc(r.payable_txt) + '</div>';
    }

    if (r.card && r.card.number) {
      h += '<div style="font-size:11.5px;color:var(--dim);margin-top:8px">کارت مقصد</div>' + copyBox(r.card.number);
      if (r.card.holder) h += row('👤', 'به نام', '', esc(r.card.holder));
      if (r.card.bank) h += row('🏦', 'بانک', '', esc(r.card.bank));
    }

    if (r.fee_txt) h += '<div class="hint">کارمزد درگاه: ' + esc(r.fee_txt) + '</div>';
    if (r.expires) h += '<div class="hint">⏱ مهلت پرداخت: ' + esc(r.expires) + '</div>';
    h += '</div>';

    if (r.url) {
      h += '<a class="btn w" href="' + esc(r.url) + '" target="_blank" rel="noopener" ' +
        'style="margin-top:8px;display:block;text-align:center;text-decoration:none">🪙 رفتن به صفحهٔ پرداخت</a>';
    }

    h += '<button type="button" class="btn gh w" data-hpchk="' + esc(String(r.tx || 0)) +
      '" style="margin-top:8px">🔄 بررسی وضعیت پرداخت</button><div id="hpChkOut"></div>';

    return h;
  }

  function hpCheck(el) {
    var tx = parseInt(el.getAttribute('data-hpchk') || '0', 10) || 0;
    if (tx <= 0) { toast('شناسهٔ پرداخت پیدا نشد.', 'err'); return; }

    var old = el.innerHTML;
    el.disabled = true;
    el.innerHTML = '<span class="spin"></span> در حال بررسی…';

    api('topup_hp_check', { tx: tx }).then(function (r) {
      el.disabled = false;
      el.innerHTML = old;

      var msg = (r && r.message) ? r.message : 'پاسخی از سرور دریافت نشد.';
      var cl = 'w';
      if (r && r.paid) cl = 'i';
      else if (r && r.dead) cl = 'e';

      var out = $('hpChkOut');
      if (out) {
        out.innerHTML = '<div class="alert ' + cl + '" style="margin-top:8px">' + esc(msg) +
          ((r && r.paid && r.balance_txt) ? '<br>موجودی جدید: ' + esc(r.balance_txt) : '') + '</div>';
      } else {
        toast(msg, (r && r.paid) ? 'ok' : 'i');
      }

      if (r && r.paid) toast('کیف پول شارژ شد.', 'ok');
    });
  }

  function pickReceipt() {
    var fi = $('rcpFile');
    if (!fi) return;
    fi.onchange = function () {
      var f = fi.files && fi.files[0];
      if (!f) return;
      if (f.size > 5 * 1024 * 1024) { toast('حجم عکس باید کمتر از ۵ مگابایت باشد.', 'err'); return; }
      var rd = new FileReader();
      rd.onload = function () {
        S.rcp = S.rcp || {};
        S.rcp.img = String(rd.result || '');
        var pv = $('rcpPrev');
        if (pv) {
          pv.innerHTML = '<div style="margin-top:9px"><img src="' + S.rcp.img +
            '" style="width:100%;border-radius:12px;max-height:230px;object-fit:contain;background:#000"></div>';
        }
        var gb = $('rcpGo');
        if (gb) gb.style.display = '';
        toast('عکس انتخاب شد؛ دکمهٔ ارسال را بزنید.', 'i');
      };
      rd.readAsDataURL(f);
    };
    fi.click();
  }

  function sendReceipt() {
    var rc = S.rcp || {};
    if (!rc.tx)  { toast('درخواست شارژ پیدا نشد؛ دوباره از کیف پول شروع کنید.', 'err'); return; }
    if (!rc.img) { toast('اول عکس رسید را انتخاب کنید.', 'err'); return; }

    var btn = $('rcpGo');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spin"></span> در حال ارسال…'; }

    api('topup_receipt', { tx: rc.tx, image: rc.img }).then(function (r) {
      if (btn) { btn.disabled = false; btn.innerHTML = '✅ ارسال رسید برای بررسی'; }
      if (!r.ok) { toast(r.message, 'err'); return; }
      toast(r.message, 'ok');
      S.rcp = null;
      closeSheet();
      go('wallet');
    });
  }

  function submitHash() {
    var tu = S.tu || {};
    var hx = $('hxTx');
    var gw = $('hxGw');
    var hash = (hx && hx.value ? hx.value : '').trim();
    if (hash.length < 8) { toast('هش تراکنش را کامل وارد ک��ید.', 'err'); return; }
    if (!tu.amount) { toast('مبلغ مشخص نیست؛ دوباره از کیف پول شروع کنید.', 'err'); return; }

    var btn = $('hxGo');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spin"></span> در حال بررسی هش…'; }

    api('topup_hash', { amount: tu.amount, hash: hash, gw: gw ? gw.value : '' }).then(function (r) {
      if (btn) { btn.disabled = false; btn.innerHTML = '✅ ارسال هش و ثبت واریز'; }
      if (!r.ok) { toast(r.message, 'err'); return; }
      toast(r.message, r.auto ? 'ok' : 'i');
      closeSheet();
      go('wallet');
    });
  }

  /* ================= پشتیبانی ================= */
  function viewHelp() {
    skeleton(2);
    api('tickets').then(function (r) {
      if (!r.ok) { $('view').innerHTML = '<div class="alert e">' + esc(r.message) + '</div>'; return; }

      var h = '<div class="card hero"><div class="lbl">پشتیبانی و راهنما</div>' +
        '<div class="big" style="font-size:19px">ما کنارتون هستیم 💙</div>' +
        '<div class="qa" style="grid-template-columns:repeat(2,1fr)">' +
        '<button type="button" data-go="newtk"><span class="ic">✍️</span>تیکت جدید</button>' +
        (r.can_tut !== false ? '<button type="button" data-go="tut"><span class="ic">🎓</span>آموزش اتصال</button>' : '') +
        '</div></div>';

      if (r.support) {
        h += '<a class="btn gh w" style="margin-bottom:12px" href="https://t.me/' +
          esc(r.support.replace(/^@/, '')) + '" target="_blank">💬 گفتگوی مستقیم با پشتیبان</a>';
      }

      h += '<div class="sec-t"><span>🚀 شروع سریع در سه قدم</span></div>' +
        '<div class="card tight">' +
        hstep('۱', 'کیف پول را شارژ کنید', 'در بخش کیف پول مبلغ دلخواه را با کارت به کارت یا پرداخت ارزی واریز کنید.') +
        hstep('۲', 'یک طرح بخرید', 'در بخش محصولات، حجم و مدت مناسب را انتخاب و خرید کنید.') +
        hstep('۳', 'وصل شوید', 'لینک اشتراک را کپی کنید و در برنامهٔ پیشنهادی بخش آموزش وارد کنید.') +
        '</div>';

      var faq = [
        ['رسید کارت به کارت را کجا بفرستم؟', 'در بخش کیف پول گزینهٔ کارت به کارت را بزنید؛ بعد از واریز، همان‌جا دکمهٔ ارسال رسید را دارید. عکس رسید را در چت ربات هم می‌توانید بفرستید.'],
        ['شارژ من چه زمانی تایید می‌شود؟', 'پرداخت ارزی معمولاً خودکار و در چند دقیق�� تایید می‌شود. کارت به کارت پس از بررسی مدیر تایید می‌شود.'],
        ['لینک اشتراک بهتر است یا کانفیگ تکی؟', 'لینک اشتراک؛ چون همهٔ سرورها را یک‌جا دارد و با تغییر سرورها خودش به‌روز می‌شود.'],
        ['حجم من تمام شد، چه کنم؟', 'از بخش سرویس‌ها همان سرویس را باز کنید و تمدید بزنید؛ حجم و زمان از نو محاسبه می‌شود.'],
        ['چرا سرعتم کم شده؟', 'ابتدا به‌روزرسانی مصرف را بزنید تا مصرف واقعی را ببینید. اگر حجم باقی است، سرور دیگری را از لینک اشتراک امتحان کنید.'],
        ['تایید حساب برای چیست؟', 'یک بار ایمیل یا شماره را تایید می‌کنید تا خرید بدون محدودیت و پیگیری پشتیبانی سریع‌تر شود.']
      ];
      h += '<div class="sec-t"><span>❓ پرسش‌های پرتکرار</span></div><div class="card tight">';
      faq.forEach(function (q) {
        h += '<details style="border-bottom:1px solid var(--line);padding:10px 0">' +
          '<summary style="font-weight:700;font-size:12.5px;cursor:pointer">' + q[0] + '</summary>' +
          '<div style="font-size:12px;color:var(--dim);line-height:1.95;margin-top:8px">' + q[1] + '</div>' +
          '</details>';
      });
      h += '</div>';

      h += '<div class="sec-t"><span>🎫 تیکت‌های من</span></div>';
      if (!r.tickets.length) h += '<div class="card">' + empty('📬', 'تیکتی ندارید', 'سوال دارید؟ تیکت جدید بسازید.') + '</div>';
      else {
        h += '<div class="card tight">';
        r.tickets.forEach(function (t) {
          var ic = t.state === 'answered' ? '✅' : (t.state === 'closed' ? '🔒' : '🕓');
          h += '<div class="row" data-tk="' + t.id + '"><div class="ri">' + ic + '</div>' +
            '<div class="rt"><b>' + esc(t.subject || ('تیکت #' + t.id)) + '</b>' +
            '<span>' + esc(t.status) + ' • ' + esc(t.date) + '</span></div>' +
            '<div class="rv" style="color:var(--mut)">›</div></div>';
        });
        h += '</div>';
      }

      $('view').innerHTML = h;
    });
  }

  function hstep(n, title, body) {
    return '<div style="display:flex;gap:10px;align-items:flex-start;padding:9px 0">' +
      '<span style="flex:0 0 24px;height:24px;border-radius:50%;background:var(--acc);color:#fff;font-size:11.5px;' +
      'display:flex;align-items:center;justify-content:center;font-weight:700">' + n + '</span>' +
      '<div><div style="font-weight:700;font-size:12.5px">' + title + '</div>' +
      '<div style="font-size:11.5px;color:var(--mut);line-height:1.85;margin-top:3px">' + body + '</div></div></div>';
  }

  function ticketSheet(id) {
    sheet('🎫 تیکت', '<div class="sk sk-c"></div>');
    api('ticket', { id: id }).then(function (r) {
      if (!r.ok) { $('sh').innerHTML = '<div class="alert e">' + esc(r.message) + '</div>'; return; }

      var h = '';
      r.messages.forEach(function (m) {
        h += '<div class="msg ' + (m.admin ? 'ad' : 'me') + '">' + esc(m.text) +
          (m.has_file ? '\n📎 (فایل در چت ربات)' : '') +
          '<span class="tm">' + esc(m.date) + '</span></div>';
      });

      var closed = r.state === 'closed';
      sheet('🎫 ' + esc(r.subject || ('تیکت #' + r.id)),
        '<div style="margin-bottom:12px">' + h + '</div>' +
        (closed
          ? '<div class="alert w">این تیکت بسته شده است.</div>'
          : '<div class="fld"><label>پاسخ شما</label>' +
            '<textarea id="tkMsg" placeholder="پیام خود را بنویسید…"></textarea></div>' +
            '<button type="button" class="btn w" data-reply="' + r.id + '">ارسال پاسخ</button>') +
        '<div class="hint" style="margin-top:9px">برای ارسال عکس و ف��یل، از چ�� ربات استفاده کنید.</div>');
    });
  }

  function newTicketSheet() {
    sheet('✍️ تیکت جدید',
      '<div class="fld"><label>موضوع (اختیاری)</label><input id="tkSub" maxlength="60" placeholder="مشکل در اتصال"></div>' +
      '<div class="fld"><label>متن پیام</label><textarea id="tkTxt" placeholder="مشکل خود را با جزئیات بنویسید…"></textarea></div>' +
      '<div class="alert i">پاسخ پشتیبانی هم اینجا و هم در چت ربات برای شما ارسال می‌شود.</div>' +
      '<button type="button" class="btn w" id="tkGo">ارسال تیکت</button>');
  }

  /* ================= آموزش ================= */
  function tutSheet() {
    sheet('🎓 آموزش اتصال', '<div class="sk sk-c"></div>');
    api('tutorials').then(function (r) {
      if (!r.ok) { $('sh').innerHTML = '<div class="alert e">' + esc(r.message) + '</div>'; return; }
      if (!r.tutorials.length) { sheet('🎓 آموزش اتصال', empty('🎓', 'مطلبی ثبت نشده', '')); return; }

      var h = '';
      r.tutorials.forEach(function (t) {
        h += '<details class="card tight" style="margin-bottom:9px">' +
          '<summary style="font-weight:700;font-size:13px;cursor:pointer">' + esc(t.title) +
          ' <span class="bdg b">' + esc(t.platform_txt) + '</span></summary>' +
          '<div style="font-size:12.5px;color:var(--dim);white-space:pre-wrap;margin-top:9px;line-height:1.9">' + esc(t.content) + '</div>' +
          (t.link ? '<a class="btn sm gh" style="margin-top:9px" href="' + esc(t.link) + '" target="_blank">🔗 دریافت برنامه</a>' : '') +
          '</details>';
      });
      sheet('🎓 آموزش اتصال', h);
    });
  }

  /* ================= تایید حساب ================= */
  var VG = {
    email: {
      title: '📧 تایید با ایمیل',
      label: 'آدرس ایمیل',
      ph: 'you@example.com',
      mode: 'email',
      steps: ['ایمیلی را بنویسید که همین حالا به آن دسترسی دارید.',
        'یک کد تایید برایتان ایمیل می‌شود؛ معمولاً کمتر از یک دقیقه.',
        'اگر نرسید، پوشهٔ Spam و Promotions را هم ببینید.'],
      hint: 'نمونهٔ درست: name@gmail.com'
    },
    phone: {
      title: '📱 تایید با شماره موبایل',
      label: 'شمارهٔ موبایل',
      ph: '09120000000',
      mode: 'numeric',
      steps: ['شماره را با صفر اول و بدون فاصله وارد کنید.',
        'کد تایید با پیامک برایتان می‌آید.',
        'اگر پیامک نرسید، یک دقیقه صبر کنید و ارسال مجدد را بزنید.'],
      hint: 'نمونهٔ درست: 09121234567'
    }
  };

  function vEn(v) {
    v = String(v || '');
    var f = '۰۱۲۳۴۵۶۷۸۹', a = '٠١٢٣٤٥٦٧٨٩', o = '';
    for (var i = 0; i < v.length; i++) {
      var c = v.charAt(i), k = f.indexOf(c);
      if (k < 0) k = a.indexOf(c);
      o += k >= 0 ? String(k) : c;
    }
    return o;
  }

  function vSteps(list) {
    var h = '<div style="background:var(--card2);border:1px solid var(--line);border-radius:12px;padding:11px 12px;margin-bottom:12px">';
    for (var i = 0; i < list.length; i++) {
      h += '<div style="display:flex;gap:8px;align-items:flex-start;font-size:12px;color:var(--dim);line-height:1.85' +
        (i ? ';margin-top:8px' : '') + '">' +
        '<span style="flex:0 0 19px;height:19px;border-radius:50%;background:var(--acc);color:#fff;font-size:10.5px;' +
        'display:flex;align-items:center;justify-content:center;font-weight:700;margin-top:2px">' + fa(i + 1) + '</span>' +
        '<span>' + list[i] + '</span></div>';
    }
    return h + '</div>';
  }

  function vErr(msg) {
    var b = $('vErr');
    if (!b) { if (msg) toast(msg, 'err'); return; }
    b.style.display = msg ? 'block' : 'none';
    b.textContent = msg || '';
  }

  function vNorm(v, kind) {
    v = vEn(v).trim();
    if (kind === 'email') return v;
    v = v.replace(/[^0-9+]/g, '');
    if (kind === 'code') return v;
    if (v.indexOf('+98') === 0) v = '0' + v.slice(3);
    else if (v.indexOf('0098') === 0) v = '0' + v.slice(4);
    else if (v.indexOf('98') === 0 && v.length === 12) v = '0' + v.slice(2);
    else if (v.length === 10 && v.indexOf('9') === 0) v = '0' + v;
    return v;
  }

  function vCheck(v, kind) {
    if (!v) return kind === 'email' ? 'ایمیل خود را وارد کنید.' : 'شمارهٔ موبایل خود را وارد کنید.';
    if (kind === 'email') {
      if (!/^[^@ ]+@[^@ ]+[.][a-zA-Z]{2,}$/.test(v)) return 'ایمیل معتبر نیست. نمونه: name@gmail.com';
      return '';
    }
    if (!/^09[0-9]{9}$/.test(v)) return 'شماره باید ۱۱ رقم و با ۰۹ شروع شود. نمونه: 09121234567';
    return '';
  }

  function openVerify() {
    var s = S.boot.security;
    if (!s.kinds.length) {
      sheet('🔐 تایید حساب', '<div class="alert i">روش تاییدی توسط مدیر فعال نشده است.</div>');
      return;
    }
    var opt = '';
    s.kinds.forEach(function (k) {
      var g = VG[k.key];
      opt += '<button type="button" class="btn gh w" style="margin-bottom:8px;text-align:right;line-height:1.6" data-vk="' +
        k.key + '">' + esc(k.label) +
        (g ? '<span style="display:block;font-size:10.5px;color:var(--mut);font-weight:400;margin-top:2px">' + g.steps[0] + '</span>' : '') +
        '</button>';
    });
    sheet('🔐 تایید حساب',
      '<div style="font-size:12.5px;color:var(--dim);line-height:1.95;margin-bottom:12px">' +
      'تایید حساب فقط یک بار انجام می‌شود و بعد از آن خرید بدون محدودیت، پیگیری سریع‌تر پشتیبانی و بازیابی سرویس‌ها را خواهید داشت.' +
      '</div><div style="font-size:11.5px;color:var(--mut);margin-bottom:10px">یکی از روش‌های زیر را انتخاب کنید:</div>' + opt);
  }

  function verifyStart(kind) {
    if (kind === 'link') {
      api('verify_start', { kind: 'link', target: '' }).then(function (r) {
        if (!r.ok) {
          if (r.done || r.verified) {
            try { if (S.boot && S.boot.user) S.boot.user.verified = 1; } catch (e) {}
            closeSheet();
            toast(r.message || 'حساب شما قبلاً تایید شده است.', 'ok');
            return;
          }
          toast(r.message, 'err');
          return;
        }
        sheet('🔗 لینک تایید',
          vSteps(['روی دکمهٔ زیر بزنید.',
            'صفحه باز می‌شود و حساب شما همان لحظه تایید می‌شود.',
            'اگر باز نشد، لینک را کپی کنید و در مرورگر باز کنید.']) +
          '<a class="btn w" id="vLink" href="' + esc(r.link) + '" target="_blank">✅ تایید حساب من</a>' + copyBox(r.link));
        var _vl = $('vLink');
        if (_vl) _vl.addEventListener('click', function () {
          if (_vl.getAttribute('data-used') === '1') return;
          _vl.setAttribute('data-used', '1');
          _vl.style.opacity = '.55';
          _vl.style.pointerEvents = 'none';
          _vl.textContent = '✅ لینک تایید باز شد — این دکمه یک‌بارمصرف است';
          var _nt = document.createElement('div');
          _nt.className = 'alert i';
          _nt.style.marginTop = '10px';
          _nt.textContent = 'اگر پیام تایید حساب را دیدید، این پنجره را ببندید و مینی‌اپ را یک بار باز �� بسته کنید.';
          if (_vl.parentNode) _vl.parentNode.appendChild(_nt);
          try { if (TG && TG.HapticFeedback) TG.HapticFeedback.impactOccurred('light'); } catch (e) {}
        });
      });
      return;
    }
    var g = VG[kind] || VG.email;
    var pre = kind === 'email' ? ((S.boot.user && S.boot.user.email) || '') : ((S.boot.user && S.boot.user.phone) || '');
    S.vk = kind;
    sheet(g.title,
      vSteps(g.steps) +
      '<div class="fld"><label>' + g.label + '</label>' +
      '<input id="vTarget" class="mono" inputmode="' + g.mode + '" placeholder="' + g.ph +
      '" value="' + esc(pre) + '"></div>' +
      '<div style="font-size:10.5px;color:var(--mut);margin:-4px 0 10px">' + g.hint + '</div>' +
      '<div id="vErr" class="alert e" style="display:none;margin-bottom:10px"></div>' +
      '<button type="button" class="btn w" data-vsend="' + kind + '">ارسال کد تایید</button>');
  }

  function verifyCodeSheet(msg) {
    var len = (S.boot.security && S.boot.security.code_len) || 6;
    sheet('🔢 ورود کد تایید',
      '<div class="alert i">' + esc(msg) + '</div>' +
      vSteps(['کد ' + fa(len) + ' رقمی ارسال‌شده را در کادر زیر بگذارید.',
        'کد چند دقیقه اعتبار دارد؛ پس از آن دوباره درخواست بدهید.',
        'اشتباه وارد کردید؟ ایرادی ندارد، دوباره بزنید.']) +
      '<div class="fld"><label>کد ' + fa(len) + ' رقمی</label>' +
      '<input id="vCode" class="mono" inputmode="numeric" maxlength="8" placeholder="------" ' +
      'style="text-align:center;letter-spacing:7px;font-size:19px"></div>' +
      '<div id="vErr" class="alert e" style="display:none;margin-bottom:10px"></div>' +
      '<button type="button" class="btn w" id="vGo">تایید کد</button>' +
      '<button type="button" class="btn gh w" id="vResend" style="margin-top:8px" disabled></button>');
    vTick(60);
    var f = $('vCode');
    if (f) { try { f.focus(); } catch (e) {} }
  }

  function vTick(sec) {
    if (S.vTimer) { clearInterval(S.vTimer); S.vTimer = null; }
    var left = sec;
    function paint() {
      var el = $('vResend');
      if (!el) { if (S.vTimer) clearInterval(S.vTimer); S.vTimer = null; return; }
      if (left <= 0) {
        if (S.vTimer) clearInterval(S.vTimer);
        S.vTimer = null;
        el.disabled = false;
        el.textContent = '🔄 ارسال مجدد کد';
        return;
      }
      el.disabled = true;
      el.textContent = 'ارسال مجدد تا ' + fa(left) + ' ثانیه';
      left--;
    }
    paint();
    S.vTimer = setInterval(paint, 1000);
  }

  /* ================= پروفایل ================= */
  function profileSheet() {
    var u = S.boot.user;
    sheet('✏️ ویرایش حساب',
      '<div class="fld"><label>نام نمایشی</label><input id="pName" maxlength="64" value="' + esc(u.name) + '"></div>' +
      '<div class="fld"><label>ایمیل</label><input id="pMail" class="mono" inputmode="email" value="' + esc(u.email) + '" placeholder="you@example.com"></div>' +
      '<div class="fld"><label>شماره تماس</label><input id="pTel" class="mono" inputmode="numeric" value="' + esc(u.phone) + '" placeholder="09120000000"></div>' +
      '<button type="button" class="btn w" id="pGo">ذخیره تغییرات</button>' +
      (S.boot.security.enabled && !u.verified
        ? '<button type="button" class="btn gh w" style="margin-top:8px" data-go="verify">🔐 تایید حساب</button>' : ''));
  }

  /* ================= من: پروفایل + پشتیبانی + کد هدیه + معرفی ================= */
  function meRow(k, v, cls) {
    return '<div class="row"><div class="rt">' + esc(k) + '</div><div class="rv ' + (cls || '') + '">' + v + '</div></div>';
  }

  function viewMe() {
    skeleton(2);
    Promise.all([api('me_info'), api('tickets')]).then(function (rr) {
      var r = rr[0] || {}, tks = rr[1] || {};
      if (!r.ok) { $('view').innerHTML = '<div class="alert e">' + esc(r.message || 'خطا در دریافت اطلاعات') + '</div>'; return; }

      var u = r.user || {}, st = r.stats || {}, rf = r.referral || {}, inv = r.invitees || [];
      S.ref = rf;

      var nm = (u.name || '').trim() || 'کاربر';
      var ini = nm.charAt(0) || '?';
      var h = '';

      h += '<div class="card hero">'
        +   '<div class="pgh"><div class="ava" style="width:54px;height:54px;font-size:21px">' + esc(ini) + '</div>'
        +     '<div><div class="hdr-t">' + esc(nm) + '</div>'
        +       '<div class="hdr-s mono">' + esc(u.tg_id ? ('ID ' + u.tg_id) : '—') + '</div></div>'
        +     '<div style="margin-inline-start:auto">'
        +       (u.verified ? '<span class="bdg g">تایید شده</span>' : '<span class="bdg o">تایید نشده</span>')
        +     '</div></div>'
        +   '<div class="grid3" style="margin-top:13px">'
        +     '<div class="stat"><div class="v">' + fa(String(st.services || 0)) + '</div><div class="t">سرویس فعال</div></div>'
        +     '<div class="stat"><div class="v">' + esc(u.balance_txt || '0') + '</div><div class="t">کیف پول</div></div>'
        +     '<div class="stat"><div class="v">' + esc(st.paid_txt || '0') + '</div><div class="t">مجموع شارژ</div></div>'
        +   '</div>'
        + '</div>';

      h += '<div class="card"><div class="sec-t">⚙️ حساب کاربری</div>'
        + meRow('ایمیل', '<span class="mono">' + esc(u.email || '—') + '</span>')
        + meRow('شماره تماس', '<span class="mono">' + esc(u.phone || '—') + '</span>')
        + '<div class="btn-row" style="margin-top:10px">'
        +   '<button type="button" class="btn gh" id="meEdit">✏️ ویرایش حساب</button>'
        +   ((S.boot && S.boot.security && S.boot.security.enabled && !u.verified)
              ? '<button type="button" class="btn ok" data-go="verify">🔐 تایید حساب</button>' : '')
        + '</div></div>';

      h += '<div class="card"><div class="sec-t">🎁 کد هدیه</div>'
        + '<div class="fld"><input id="gCode" class="mono" maxlength="64" placeholder="کد هدیه را وارد کنید"></div>'
        + '<button type="button" class="btn w" id="gGo">اعمال کد هدیه</button>'
        + '<div class="hint">اعتبار کد مستقیم به کیف پول شما افزوده می‌شود.</div>'
        + '<div id="gOut"></div></div>';

      if (rf.enabled) {
        h += '<div class="card"><div class="sec-t">👥 دعوت از دوستان</div>';
        if (rf.link) {
          h += '<div class="copy"><code>' + esc(rf.link) + '</code>'
            +  '<button class="btn sm gh" data-copy="' + esc(rf.link) + '">کپی</button></div>'
            +  '<div class="btn-row" style="margin-top:10px">'
            +    '<button type="button" class="btn ok" id="refShare">📤 ارسال به دوستان</button>'
            +    '<button type="button" class="btn gh" data-copy="' + esc(rf.code || '') + '">کد: ' + esc(rf.code || '—') + '</button>'
            +  '</div>';
        } else {
          h += '<div class="alert w">لینک معرفی فعلا در دسترس نیست. مدیر باید نام کاربری ربات را تنظیم کند.</div>';
        }

        h += '<div class="grid3" style="margin-top:13px">'
          +   '<div class="stat"><div class="v">' + fa(String(rf.count || 0)) + '</div><div class="t">زیرمجموعه</div></div>'
          +   '<div class="stat"><div class="v">' + fa(String(rf.active || 0)) + '</div><div class="t">فعال شده</div></div>'
          +   '<div class="stat"><div class="v">' + esc(rf.earned_txt || '0') + '</div><div class="t">درآمد معرفی</div></div>'
          + '</div>';

        var tips = [];
        if (+rf.bonus > 0)  tips.push('پاداش اولین شارژ: ' + fa(String(rf.bonus)) + '٪');
        if (+rf.pct > 0)    tips.push('پورسانت دائمی سطح ۱: ' + fa(String(rf.pct)) + '٪');
        if (+rf.pct_l2 > 0) tips.push('پورسانت سطح ۲: ' + fa(String(rf.pct_l2)) + '٪');
        if (+rf.l2 > 0)     tips.push('زیرمجموعه غیرمستقیم: ' + fa(String(rf.l2)) + ' نفر');
        if (+rf.min > 0)    tips.push('حداقل شارژ برای پورسانت: ' + esc(rf.min_txt || ''));
        if (tips.length) h += '<div class="alert i" style="margin-top:10px">' + tips.join(' • ') + '</div>';
        if (rf.first_only) h += '<div class="hint">پورسانت درصدی فقط روی اولین شارژ هر زیرمجموعه پرداخت می‌شود.</div>';

        if (inv.length) {
          h += '<div class="sec-t more">آخرین دعوت‌ها</div>';
          for (var i = 0; i < inv.length; i++) {
            h += meRow(inv[i].name + ' — ' + (inv[i].joined || ''),
                       esc(inv[i].paid_txt || '0'), inv[i].active ? 'p' : 'n');
          }
        }
        h += '</div>';
      }

      h += '<div class="card"><div class="sec-t">🆘 پشتیبانی و راهنما</div>'
        + '<div class="qa">'
        +   '<button type="button" data-go="newtk"><span class="ic">✍️</span>تیکت جدید</button>'
        +   '<button type="button" data-go="tut"><span class="ic">📚</span>آموزش‌ها</button>'
        + '</div>';

      var list = tks.tickets || tks.items || tks.rows || [];
      if (list.length) {
        h += '<div class="sec-t more">تیکت‌های من</div>';
        for (var j = 0; j < list.length; j++) {
          var tk = list[j];
          h += '<div class="row" data-tk="' + (+tk.id || 0) + '">'
            +    '<div class="rt">' + esc(tk.subject || tk.title || ('تیکت #' + (tk.id || ''))) + '</div>'
            +    '<div class="rv">' + esc(tk.status_txt || tk.status || '') + '</div></div>';
        }
      } else {
        h += '<div class="hint" style="margin-top:8px">هنوز تیکتی ثبت نکرده‌اید.</div>';
      }

      if (r.support) {
        h += '<div class="btn-row" style="margin-top:10px"><a class="btn gh" target="_blank" href="https://t.me/'
          +  esc(String(r.support).replace('@', '')) + '">💬 ارتباط مستقیم</a></div>';
      }
      h += '</div>';

      $('view').innerHTML = h;
    });
  }

  /* تب پشتیبانی حالا همان تب من است (ادغام پروفایل و پشتیبانی) */
  viewHelp = viewMe;

  function redeemGift(btn) {
    var i = $('gCode');
    if (!i) return;
    var code = (i.value || '').trim();
    if (code.length < 3) { toast('کد هدیه را وارد کنید', 'r'); return; }

    btn.disabled = true;
    var old = btn.textContent;
    btn.textContent = 'در حال بررسی...';

    api('gift_redeem', { code: code }).then(function (r) {
      btn.disabled = false;
      btn.textContent = old;
      var box = $('gOut');
      if (box) {
        box.innerHTML = '<div class="alert ' + (r.ok ? 'i' : 'e') + '" style="margin-top:9px">'
          + esc(r.message || '') + '</div>';
      }
      if (r.ok) {
        i.value = '';
        toast(r.message || 'کد هدیه اعمال شد', 'g');
        setTimeout(viewMe, 900);
      } else {
        toast(r.message || 'کد نامعتبر است', 'r');
      }
    });
  }

  function shareRef() {
    var url = (S.ref && S.ref.share) || '';
    if (!url) { toast('لینک معرفی موجود نیست', 'r'); return; }
    try {
      if (window.Telegram && Telegram.WebApp && Telegram.WebApp.openTelegramLink) {
        Telegram.WebApp.openTelegramLink(url);
        return;
      }
    } catch (e) {}
    window.open(url, '_blank');
  }

  /* ================= بخش اختصاصی شارژ کیف پول ================= */
  var TP = { amount: 0, method: '', order: null, step: 1, card: 0 };

  function tpSep(v) {
    var x = String(Math.max(0, Math.floor(Number(v) || 0))), o = '', c = 0;
    for (var i = x.length - 1; i >= 0; i--) {
      o = x.charAt(i) + o; c++;
      if (c % 3 === 0 && i > 0) o = ',' + o;
    }
    return o;
  }

  function tpMethods() {
    var b = S.boot, m = [];
    if (b.flags.card) m.push(['card', '💳', 'کارت به کارت', 'واریز به شماره کارت و آپلود رسید در همین صفحه']);
    if (b.flags.hooshpay) m.push(['hooshpay', '🪙', 'هوش‌پی — کارت به کارت آنی', 'لینک پرداخت با تایید خودکار و شارژ فوری کیف پول']);
    if (b.flags.crypto) m.push(['crypto', '🌐', 'پرداخت ارزی', 'تتر و ارزهای دیگر با نرخ لحظه‌ای و تایید خودکار']);
    return m;
  }

  function tpHead(step, title, sub, back) {
    var nm = ['مبلغ', 'پرداخت', 'تایید'];
    var h = '<div class="pgh"><button type="button" class="bk" data-tpback="' + back + '">→</button>' +
      '<div class="tt">' + title + '<span>' + sub + '</span></div></div><div class="stp">';
    for (var i = 1; i <= 3; i++) {
      var cls = i === step ? ' on' : (i < step ? ' dn' : '');
      h += '<div class="s' + cls + '"><div class="n">' + (i < step ? '✓' : fa(i)) + '</div>' + nm[i - 1] + '</div>';
      if (i < 3) h += '<div class="ln"></div>';
    }
    return h + '</div>';
  }

  function viewTopup() {
    var b = S.boot, ms = tpMethods();
    TP.step = 1;
    if (!ms.length) {
      $('view').innerHTML = tpHead(1, 'شارژ کیف پول', 'روش پرداخت فعال نیست', 'wallet') +
        '<div class="alert e">در حال حاضر هیچ روش پرداختی فعال نیست. با پشتیبانی تماس بگیرید.</div>';
      return;
    }
    var ok = false;
    ms.forEach(function (m) { if (m[0] === TP.method) ok = true; });
    if (!ok) TP.method = ms[0][0];

    var h = tpHead(1, 'شارژ کیف پول', 'مبلغ و روش پرداخت را انتخاب کنید', 'wallet');

    h += '<div class="amt3"><div class="l">مبلغ شارژ</div>' +
      '<div><span class="v" id="tpBig">' + fa(tpSep(TP.amount)) + '</span>' +
      '<span class="u">' + esc(b.shop.currency) + '</span></div></div>';

    h += '<div class="qg" id="tpQg">';
    [50000, 100000, 200000, 500000, 1000000, 2000000].forEach(function (v) {
      h += '<button type="button" data-qamt="' + v + '"' + (TP.amount === v ? ' class="on"' : '') + '>' +
        fa(tpSep(v)) + '</button>';
    });
    h += '</div>';

    h += '<div class="fld"><label>یا مبلغ دلخواه را بنویسید</label>' +
      '<input id="tpAmt" class="mono" type="text" inputmode="numeric" placeholder="100000" value="' +
      (TP.amount > 0 ? TP.amount : '') + '">' +
      '<div class="hint">اگر مبلغ خارج از محدودهٔ مجاز باشد، در مرحلهٔ بعد پیام می‌گیرید.</div></div>';

    h += '<div class="sec-t"><span>روش پرداخت</span></div>';
    ms.forEach(function (m) {
      h += '<button type="button" class="pmc' + (TP.method === m[0] ? ' on' : '') + '" data-pmc="' + m[0] + '">' +
        '<span class="pi">' + m[1] + '</span>' +
        '<span class="pt"><b>' + m[2] + '</b><span>' + m[3] + '</span></span>' +
        '<span class="pk">✓</span></button>';
    });

    h += '<div id="tpRate"></div>' +
      '<button type="button" class="btn w" id="tpGo" style="margin-top:10px">ادامه و دریافت اطلاعات پرداخت</button>';

    $('view').innerHTML = h;

    var tm = null, f = $('tpAmt');
    if (f) {
      f.addEventListener('input', function () {
        clearTimeout(tm);
        TP.amount = parseInt(vEn(this.value).replace(/[^0-9]/g, '') || '0', 10) || 0;
        tpPaint();
        tm = setTimeout(tpRate, 620);
      });
    }
    tpRate();
  }

  function tpPaint() {
    var b = $('tpBig');
    if (b) b.textContent = fa(tpSep(TP.amount));
    var g = $('tpQg');
    if (g) {
      Array.prototype.forEach.call(g.children, function (c) {
        c.classList.toggle('on', +c.getAttribute('data-qamt') === TP.amount);
      });
    }
  }

  function tpRate() {
    var box = $('tpRate');
    if (!box) return;
    if (TP.method !== 'crypto' || TP.amount <= 0) { box.innerHTML = ''; return; }
    box.innerHTML = '<div class="alert i"><span class="spin"></span> دریافت نرخ لحظه‌ای…</div>';
    api('rate', { amount: TP.amount }).then(function (r) {
      var b2 = $('tpRate');
      if (!b2) return;
      if (!r.ok) { b2.innerHTML = '<div class="alert e">' + esc(r.message) + '</div>'; return; }
      var h = '<div class="card tight"><div style="font-size:11.5px;color:var(--dim)">نرخ لحظه‌ای دلار</div>' +
        '<div style="font-weight:700;font-size:15px;margin:2px 0 6px">' + esc(r.rate_txt) + '</div>';
      (r.assets || []).forEach(function (a) {
        h += '<div class="row"><div class="ri">' + a.icon + '</div><div class="rt"><b>' + esc(a.key) + '</b>' +
          '<span>شبکه: ' + esc(a.network) + '</span></div><div class="rv">' + esc(a.qty_txt) + '</div></div>';
      });
      h += '<div class="hint">⏱ همین لحظه از بازار گرفته شد • ' + esc(r.at) + '</div></div>';
      b2.innerHTML = h;
    });
  }

  /* ================= احراز کارت بانکی =================
     پیش از واریز کارت به کارت، کاربر باید کارت خودش را ثبت کند.
     هیچ اطلاع حساسی (CVV2 / رمز دوم / رمز پویا / انقضا) دریافت نمی‌شود. */
  /* ---------- طرح هوشمند کارت ----------
     رنگ، بافت و نشان هر کارت از روی بانک همان کارت ساخته می‌شود؛
     بانک از فیلد bank یا از شش رقم اول شمارهٔ کارت (BIN) شناسایی می‌شود. */
  var C3D_STC = { approved: '#34d399', pending: '#fbbf24', rejected: '#f87171' };

  var C3D_PAT = {
    rings: ['radial-gradient(circle at 86% 20%,rgba(255,255,255,.20) 0 21%,transparent 22%),radial-gradient(circle at 101% 34%,rgba(255,255,255,.13) 0 27%,transparent 28%)', 'auto'],
    waves: ['radial-gradient(130% 72% at -12% 112%,rgba(255,255,255,.20),transparent 62%),radial-gradient(95% 70% at 112% -12%,rgba(255,255,255,.15),transparent 58%)', 'auto'],
    grid:  ['repeating-linear-gradient(0deg,rgba(255,255,255,.07) 0 1px,transparent 1px 15px),repeating-linear-gradient(90deg,rgba(255,255,255,.07) 0 1px,transparent 1px 15px)', 'auto'],
    diag:  ['repeating-linear-gradient(135deg,rgba(255,255,255,.11) 0 9px,transparent 9px 22px)', 'auto'],
    dots:  ['radial-gradient(rgba(255,255,255,.18) 1.3px,transparent 1.4px)', '13px 13px'],
    arcs:  ['conic-gradient(from 195deg at 92% 10%,rgba(255,255,255,.22),transparent 30%),radial-gradient(circle at 6% 96%,rgba(255,255,255,.14) 0 24%,transparent 25%)', 'auto']
  };

  var C3D_BANKS = [
    ['ملی',         '#17a06d', '#064e33', 'rings'],
    ['ملت',         '#e05a4c', '#7d1c16', 'arcs'],
    ['صادرات',      '#3f86d8', '#10375f', 'waves'],
    ['تجارت',       '#35a6e2', '#0d4a76', 'diag'],
    ['سپه',         '#2cb59c', '#0e5a49', 'grid'],
    ['رفاه',        '#4d81d6', '#16376f', 'dots'],
    ['کشاورزی',     '#25a771', '#08532f', 'waves'],
    ['مسکن',        '#4096df', '#0f4877', 'rings'],
    ['پست',         '#31b168', '#125b31', 'grid'],
    ['توسعه تعاون', '#3f9d8f', '#12554c', 'dots'],
    ['توسعه',       '#4d95c8', '#1b4870', 'diag'],
    ['صنعت',        '#4d78b1', '#152f52', 'grid'],
    ['پارسیان',     '#dc4d62', '#75182a', 'arcs'],
    ['پاسارگاد',    '#e2bb55', '#6f5210', 'rings'],
    ['سامان',       '#3ba0d4', '#0f4a6e', 'waves'],
    ['سینا',        '#31a37c', '#0f4c3b', 'dots'],
    ['سرمایه',      '#7385bd', '#2e3b61', 'grid'],
    ['مهر',         '#34a377', '#124c38', 'rings'],
    ['اقتصاد',      '#4d98ce', '#1a4468', 'diag'],
    ['دی',          '#43b48e', '#1a5d4b', 'rings'],
    ['انصار',       '#43a3a0', '#194d4b', 'waves'],
    ['شهر',         '#d4577c', '#73203c', 'arcs'],
    ['آینده',       '#a05ac9', '#4b1f6b', 'dots'],
    ['گردشگری',     '#22a7c4', '#0a5a6e', 'waves'],
    ['ایران زمین',  '#4a86c4', '#1e456e', 'grid'],
    ['خاورمیانه',   '#2f92ad', '#134857', 'diag'],
    ['قوامین',      '#33936c', '#134330', 'grid'],
    ['رسالت',       '#3b83cb', '#173f6d', 'dots'],
    ['کارافرین',    '#5f7fca', '#2b3f72', 'diag'],
    ['کارآفرین',    '#5f7fca', '#2b3f72', 'diag'],
    ['حکمت',        '#4a8dab', '#1c4557', 'waves'],
    ['نور',          '#4489c3', '#194466', 'rings'],
    ['کوثر',        '#3fa08a', '#134a40', 'grid'],
    ['ملل',          '#43a0b8', '#134a58', 'dots'],
    ['مرکزی',       '#6b7ba0', '#2b3350', 'grid']
  ];

  function c3dRgba(hex, a) {
    var m = /^#([0-9a-fA-F]{6})$/.exec(String(hex || ''));
    if (!m) return 'rgba(0,0,0,' + a + ')';
    var n = parseInt(m[1], 16);
    return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
  }

  function c3dShade(hex, k) {
    var m = /^#([0-9a-fA-F]{6})$/.exec(String(hex || ''));
    if (!m) return '#12161f';
    var n = parseInt(m[1], 16);
    return 'rgb(' + Math.round(((n >> 16) & 255) * k) + ',' +
      Math.round(((n >> 8) & 255) * k) + ',' + Math.round((n & 255) * k) + ')';
  }

  /* نام بانک: اول از خود کارت، در نبود آن از شش رقم اول شمارهٔ کارت */
  function c3dBankName(bank, pan) {
    var b = String(bank == null ? '' : bank).trim();
    if (b) return b;
    var d = String(pan == null ? '' : pan)
      .replace(/[۰-۹]/g, function (x) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(x); })
      .replace(/[^0-9]/g, '');
    if (d.length >= 6 && BINS) return String(BINS[d.slice(0, 6)] || '');
    return '';
  }

  function c3dLogo(name) {
    var n = String(name || '').replace(/^بانک\s+/, '').trim();
    if (!n) return '💳';
    var w = n.split(/\s+/);
    return w.length > 1 ? (w[0].charAt(0) + w[1].charAt(0)) : w[0].slice(0, 2);
  }

  function c3dArt(bank, pan) {
    var name = c3dBankName(bank, pan);
    var c1 = '#4b5c85', c2 = '#232b3f', pk = 'grid', i;
    for (i = 0; i < C3D_BANKS.length; i++) {
      if (name && name.indexOf(C3D_BANKS[i][0]) !== -1) {
        c1 = C3D_BANKS[i][1];
        c2 = C3D_BANKS[i][2];
        pk = C3D_BANKS[i][3];
        break;
      }
    }
    var p = C3D_PAT[pk] || C3D_PAT.grid;
    return {
      name: name,
      logo: c3dLogo(name),
      sub:  name ? 'IRAN · DEBIT CARD' : 'بانک شناسایی نشد',
      pat:  p[0],
      size: p[1],
      glow: c3dRgba(c1, .45),
      bg:   'linear-gradient(135deg,' + c1 + ' 0%,' + c2 + ' 58%,' + c3dShade(c2, .6) + ' 100%)'
    };
  }

  function c3dCss() {
    if (document.getElementById('c3dCss')) return;
    var st = document.createElement('style');
    st.id = 'c3dCss';
    st.textContent = [
      '.c3d-wrap{perspective:1100px;margin:2px 0 14px}',
      '.c3d{position:relative;width:100%;max-width:332px;margin:0 auto;aspect-ratio:1.586/1;',
      'transform-style:preserve-3d;transition:transform .65s cubic-bezier(.2,.8,.2,1);cursor:pointer}',
      '.c3d.flip{transform:rotateY(180deg)}',
      '.c3d-f,.c3d-b{position:absolute;inset:0;border-radius:17px;-webkit-backface-visibility:hidden;',
      'backface-visibility:hidden;overflow:hidden;padding:15px 17px;display:flex;flex-direction:column;',
      'justify-content:space-between;color:#fff;',
      'box-shadow:0 20px 44px -14px rgba(0,0,0,.7),0 0 0 1px rgba(255,255,255,.1) inset}',
      '.c3d-b{transform:rotateY(180deg)}',
      '.c3d-sh{position:absolute;inset:0;pointer-events:none;',
      'background:radial-gradient(120% 80% at 14% 6%,rgba(255,255,255,.26),transparent 56%)}',
      '.c3d-chip{width:42px;height:31px;border-radius:6px;position:relative;',
      'background:linear-gradient(135deg,#f8e488,#caa42a 55%,#8a6d13);',
      'box-shadow:0 1px 2px rgba(0,0,0,.42) inset,0 1px 3px rgba(0,0,0,.35)}',
      '.c3d-chip::after{content:"";position:absolute;inset:6px 5px;border-radius:3px;',
      'background:repeating-linear-gradient(90deg,rgba(0,0,0,.24) 0 1px,transparent 1px 7px)}',
      '.c3d-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;position:relative}',
      '.c3d-bank{font-size:12.5px;font-weight:800;text-shadow:0 1px 3px rgba(0,0,0,.5)}',
      '.c3d-st{font-size:10.5px;font-weight:800;padding:4px 9px;border-radius:999px;white-space:nowrap;',
      'background:rgba(0,0,0,.34);border:1px solid rgba(255,255,255,.22)}',
      '.c3d-pan{font-size:18px;font-weight:800;letter-spacing:2.3px;direction:ltr;text-align:left;',
      'position:relative;text-shadow:0 2px 6px rgba(0,0,0,.55)}',
      '.c3d-bot{display:flex;align-items:flex-end;justify-content:space-between;gap:10px;position:relative}',
      '.c3d-hn{font-size:8.5px;opacity:.72;letter-spacing:.6px;margin-bottom:2px}',
      '.c3d-hv{font-size:12.5px;font-weight:700;text-shadow:0 1px 3px rgba(0,0,0,.5)}',
      '.c3d-mag{height:40px;margin:-15px -17px 0;background:#0a0a0c;position:relative}',
      '.c3d-sig{background:#ececf1;color:#101014;border-radius:5px;padding:7px 10px;font-size:11.5px;',
      'direction:ltr;text-align:left;font-weight:700;position:relative}',
      '.c3d-note{font-size:10.5px;opacity:.88;line-height:1.8;position:relative}',
      '.c3d-acts{display:flex;gap:7px;justify-content:center;margin-top:11px;flex-wrap:wrap}',
      '.c3d-ph{border:1.5px dashed var(--line);border-radius:14px;padding:15px;text-align:center;cursor:pointer}',
      '.c3d-ph img{max-width:100%;border-radius:10px;display:block;margin:0 auto}',
      '.c3d-lock{display:flex;gap:9px;align-items:flex-start;border-radius:13px;padding:11px 13px;',
      'background:rgba(96,165,250,.08);border:1px solid rgba(96,165,250,.28);font-size:12px;line-height:1.85}',
      '.c3d-pat{position:absolute;inset:0;pointer-events:none}',
      '.c3d-bhead{display:flex;align-items:center;gap:9px;position:relative;min-width:0}',
      '.c3d-logo{min-width:34px;height:34px;padding:0 7px;border-radius:11px;display:flex;align-items:center;',
      'justify-content:center;font-size:13px;font-weight:800;background:rgba(255,255,255,.19);',
      'border:1px solid rgba(255,255,255,.3);text-shadow:0 1px 2px rgba(0,0,0,.4)}',
      '.c3d-bsub{font-size:9px;opacity:.75;letter-spacing:.4px}',
      '.c3d-dot{width:7px;height:7px;border-radius:50%;display:inline-block;',
      'margin-inline-end:5px;vertical-align:middle}',
      '.c3dm{position:relative;width:100%;max-width:332px;margin:2px auto 12px;aspect-ratio:1.586/1;',
      'border-radius:17px;overflow:hidden;padding:15px 17px;display:flex;flex-direction:column;',
      'justify-content:space-between;color:#fff;',
      'box-shadow:0 18px 40px -16px rgba(0,0,0,.66),0 0 0 1px rgba(255,255,255,.1) inset}'
    ].join('');
    document.head.appendChild(st);
  }

  function c3dCard(c) {
    var art = c3dArt(c.bank, c.pan);
    var bg  = art.bg;
    var stc = C3D_STC[c.status] || C3D_STC.pending;
    var lb = c.status === 'approved' ? '✅ تایید‌شده'
           : (c.status === 'rejected' ? '⛔️ ردشده' : '⏳ در انتظار تایید');
    return '<div class="c3d-wrap"><div class="c3d">' +
      '<div class="c3d-f" style="background:' + bg + '">' +
        '<div class="c3d-pat" style="background-image:' + art.pat + ';background-size:' + art.size + '"></div>' +
        '<div class="c3d-sh"></div>' +
        '<div class="c3d-top"><div class="c3d-bhead">' +
          '<div class="c3d-logo" style="box-shadow:0 5px 14px ' + art.glow + '">' + esc(art.logo) + '</div>' +
          '<div><div class="c3d-bank">' + esc(art.name || 'کارت بانکی') + '</div>' +
          '<div class="c3d-bsub">' + esc(art.sub) + '</div></div></div>' +
        '<div class="c3d-st" style="border-color:' + stc + '">' +
          '<span class="c3d-dot" style="background:' + stc + '"></span>' + lb + '</div></div>' +
        '<div class="c3d-chip"></div>' +
        '<div class="c3d-pan">' + esc(c.pan) + '</div>' +
        '<div class="c3d-bot"><div><div class="c3d-hn">CARD HOLDER</div>' +
        '<div class="c3d-hv">' + esc(c.holder || '—') + '</div></div>' +
        '<div style="text-align:left"><div class="c3d-hn">تاریخ ثبت</div>' +
        '<div class="c3d-hv" style="font-size:11px">' + esc(c.date || '—') + '</div></div></div>' +
      '</div>' +
      '<div class="c3d-b" style="background:' + bg + '">' +
        '<div class="c3d-pat" style="background-image:' + art.pat + ';background-size:' + art.size + '"></div>' +
        '<div class="c3d-sh"></div>' +
        '<div class="c3d-mag"></div>' +
        '<div class="c3d-sig">' + esc(c.mask || c.pan) + '</div>' +
        '<div class="c3d-note">' +
          (c.sheba ? '🏛 شبا: <span dir="ltr">IR' + esc(c.sheba) + '</span><br>' : '') +
          (c.note ? '📝 ' + esc(c.note) + '<br>' : '') +
          '🔁 دفعات استفاده: ' + fa(c.uses || 0) +
          (c.photo ? '<br>🖼 تصویر کارت ثبت شده است' : '') +
        '</div>' +
      '</div></div>' +
      '<div class="c3d-acts">' +
        '<button type="button" class="btn sm gh" data-flipb="1">🔄 پشت کارت</button>' +
        '<button type="button" class="btn sm gh" data-crddel="' + c.id + '">🗑 حذف کارت</button>' +
      '</div></div>';
  }

  function c3dBind() {
    Array.prototype.forEach.call(document.querySelectorAll('.c3d'), function (el) {
      el.addEventListener('click', function () { el.classList.toggle('flip'); });
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-flipb]'), function (b) {
      b.addEventListener('click', function (e) {
        e.stopPropagation();
        var w = b.parentNode && b.parentNode.parentNode;
        var c = w && w.querySelector ? w.querySelector('.c3d') : null;
        if (c) c.classList.toggle('flip');
      });
    });
    var box = $('crdPhBox'), inp = $('crdPhoto');
    if (box && inp) {
      box.addEventListener('click', function () { inp.click(); });
      inp.addEventListener('change', function () {
        var fl = inp.files && inp.files[0];
        if (!fl) return;
        if (fl.size > 4194304) { toast('حجم تصویر بیش از ۴ مگابایت است.', 'err'); inp.value = ''; return; }
        var fr = new FileReader();
        fr.onload = function () {
          S.cardPhoto = String(fr.result || '');
          var t = $('crdPhTxt');
          if (t) t.innerHTML = '<img src="' + S.cardPhoto + '" alt="">' +
            '<div class="hint" style="margin-top:7px">✅ تصویر انتخاب شد — برای تغییر دوباره بزنید</div>';
        };
        fr.readAsDataURL(fl);
      });
    }
  }

  function stkCss() {
    if (document.getElementById('stkCss')) return;
    var st = document.createElement('style');
    st.id = 'stkCss';
    st.textContent = [
      '.stk-grid{display:grid;grid-template-columns:1fr;gap:11px;margin-top:4px}',
      '@media(min-width:520px){.stk-grid{grid-template-columns:1fr 1fr}}',
      '.stk-c{position:relative;border-radius:16px;padding:14px 15px;overflow:hidden;cursor:pointer;',
      'background:linear-gradient(150deg,rgba(255,255,255,.07),rgba(255,255,255,.02));',
      'border:1px solid var(--line);transition:transform .22s cubic-bezier(.2,.8,.2,1),box-shadow .22s;',
      'transform-style:preserve-3d}',
      '.stk-c:active{transform:translateY(2px) scale(.985)}',
      '.stk-c:hover{transform:translateY(-3px);box-shadow:0 16px 34px -16px rgba(0,0,0,.75)}',
      '.stk-c::before{content:"";position:absolute;inset:0;pointer-events:none;',
      'background:radial-gradient(90% 60% at 12% 0%,rgba(255,255,255,.13),transparent 60%)}',
      '.stk-c.off{opacity:.55}',
      '.stk-h{display:flex;align-items:flex-start;gap:9px;position:relative}',
      '.stk-ic{width:40px;height:40px;flex:0 0 40px;border-radius:12px;display:grid;place-items:center;font-size:19px;',
      'background:linear-gradient(140deg,rgba(91,140,255,.28),rgba(139,92,246,.18));',
      'box-shadow:0 5px 14px -6px rgba(0,0,0,.7),0 0 0 1px rgba(255,255,255,.08) inset}',
      '.stk-n{font-weight:800;font-size:13.5px;line-height:1.6}',
      '.stk-k{font-size:10.5px;color:var(--dim);margin-top:1px}',
      '.stk-tags{display:flex;flex-wrap:wrap;gap:5px;margin-top:9px;position:relative}',
      '.stk-t{font-size:10.5px;padding:3px 8px;border-radius:999px;background:rgba(255,255,255,.07);',
      'border:1px solid var(--line);white-space:nowrap}',
      '.stk-t.g{background:rgba(47,212,143,.13);border-color:rgba(47,212,143,.34)}',
      '.stk-t.r{background:rgba(255,107,107,.13);border-color:rgba(255,107,107,.34)}',
      '.stk-p{display:flex;align-items:baseline;gap:7px;margin-top:11px;position:relative}',
      '.stk-pv{font-size:16px;font-weight:900}',
      '.stk-po{font-size:11.5px;color:var(--dim);text-decoration:line-through}',
      '.stk-bal{display:flex;align-items:center;justify-content:space-between;gap:10px;border-radius:14px;',
      'padding:12px 14px;margin-bottom:12px;background:linear-gradient(135deg,rgba(91,140,255,.16),rgba(139,92,246,.09));',
      'border:1px solid rgba(91,140,255,.28)}',
      '.stk-pl{white-space:pre-wrap;word-break:break-all;direction:ltr;text-align:left;font-size:11.5px;',
      'line-height:1.85;background:rgba(0,0,0,.3);border:1px solid var(--line);border-radius:12px;padding:11px 12px}'
    ].join('');
    document.head.appendChild(st);
  }

  function stkLoad(t) {
    return '<div class="card tight" style="text-align:center;padding:24px">' +
      '<span class="spin" style="border-color:var(--line);border-top-color:var(--acc)"></span>' +
      '<div style="margin-top:10px;font-size:12.5px;color:var(--dim)">' + t + '</div></div>';
  }

  function stockSheet() {
    stkCss();
    sheet('🏪 انبار ملی', stkLoad('در حال دریافت بسته‌ها…'));

    api('stock').then(function (r) {
      if (!r.ok) { sheet('🏪 انبار ملی', '<div class="alert e">' + esc(r.message) + '</div>'); return; }
      if (!r.enabled) {
        sheet('🏪 انبار ملی', '<div class="alert w">این بخش در حال حاضر غیرفعال است.</div>');
        return;
      }

      S.stk = r;
      var h = '<div class="stk-bal"><div><div style="font-size:10.5px;color:var(--dim)">👛 موجودی شما</div>' +
        '<div style="font-size:15px;font-weight:900;margin-top:2px">' + esc(r.balance_txt) + '</div></div>' +
        '<button type="button" class="btn sm gh" data-stkmy="1">🎒 خریدهای من</button></div>';

      if (r.note) h += '<div class="alert i">ℹ️ ' + esc(r.note) + '</div>';

      var cats = r.cats || [];
      if (!cats.length) {
        h += '<div class="card tight">' + empty('🏪', 'فعلاً بسته‌ای موجود نیست', 'به‌زودی موجودی جدید اضافه می‌شود.', '') + '</div>';
      } else {
        h += '<div class="stk-grid">';
        cats.forEach(function (c) {
          var out = (c.free || 0) < 1;
          h += '<div class="stk-c' + (out ? ' off' : '') + '" data-stkc="' + c.id + '">' +
            '<div class="stk-h"><div class="stk-ic">' + esc(c.icon || c.kind_icon || '📦') + '</div>' +
            '<div style="flex:1;min-width:0"><div class="stk-n">' + esc(c.name) + '</div>' +
            '<div class="stk-k">' + esc(c.kind_label || '') + '</div></div></div>' +
            '<div class="stk-tags">' +
              (c.gb > 0 ? '<span class="stk-t">📊 ' + fa(c.gb) + ' گیگ</span>' : '') +
              (c.days > 0 ? '<span class="stk-t">📅 ' + fa(c.days) + ' روز</span>' : '') +
              (out ? '<span class="stk-t r">⛔️ ناموجود</span>'
                   : '<span class="stk-t g">✅ ' + fa(c.free) + ' عدد موجود</span>') +
            '</div>' +
            '<div class="stk-p"><span class="stk-pv">' + esc(c.price_txt) + '</span>' +
              (c.old_txt ? '<span class="stk-po">' + esc(c.old_txt) + '</span>' : '') + '</div>' +
            '</div>';
        });
        h += '</div>';
      }

      sheet('🏪 انبار ملی', h);
      stkBind();
    });
  }

  function stkBind() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-stkc]'), function (el) {
      el.addEventListener('click', function () { stockCat(parseInt(el.getAttribute('data-stkc'), 10)); });
    });
    var my = document.querySelector('[data-stkmy]');
    if (my) my.addEventListener('click', function () { stockMine(); });
  }

  function stockCat(id) {
    var r = S.stk || {}, c = null;
    (r.cats || []).forEach(function (x) { if (x.id === id) c = x; });
    if (!c) { stockSheet(); return; }

    var out = (c.free || 0) < 1;
    var h = '<div class="stk-c" style="cursor:default">' +
      '<div class="stk-h"><div class="stk-ic">' + esc(c.icon || c.kind_icon || '📦') + '</div>' +
      '<div style="flex:1;min-width:0"><div class="stk-n" style="font-size:15px">' + esc(c.name) + '</div>' +
      '<div class="stk-k">' + esc(c.kind_label || '') + '</div></div></div>' +
      '<div class="stk-tags">' +
        (c.gb > 0 ? '<span class="stk-t">📊 ' + fa(c.gb) + ' گیگ</span>' : '') +
        (c.days > 0 ? '<span class="stk-t">📅 ' + fa(c.days) + ' روز</span>' : '') +
        (out ? '<span class="stk-t r">⛔️ ناموجود</span>'
             : '<span class="stk-t g">✅ ' + fa(c.free) + ' عدد موجود</span>') +
        '<span class="stk-t">🧾 ' + fa(c.sold || 0) + ' فروش</span>' +
      '</div>' +
      '<div class="stk-p"><span class="stk-pv">' + esc(c.price_txt) + '</span>' +
        (c.old_txt ? '<span class="stk-po">' + esc(c.old_txt) + '</span>' : '') + '</div>' +
      '</div>';

    if (c.desc) h += '<div class="card tight" style="margin-top:11px;font-size:12.5px;line-height:2">' + esc(c.desc) + '</div>';

    h += '<div class="stk-bal" style="margin-top:11px"><div><div style="font-size:10.5px;color:var(--dim)">👛 موجودی شما</div>' +
      '<div style="font-size:14px;font-weight:900;margin-top:2px">' + esc(r.balance_txt || '') + '</div></div></div>';

    h += out
      ? '<div class="alert w">موجودی این بسته تمام شده است.</div>'
      : '<button type="button" class="btn w" id="stkBuy" data-stkb="' + c.id + '">🛒 خرید و دریافت فوری</button>';

    h += '<button type="button" class="btn gh w" style="margin-top:8px" data-stkhome="1">⬅️ بازگشت به انبار</button>';

    sheet('🏪 ' + c.name, h);

    var b = document.querySelector('[data-stkb]');
    if (b) b.addEventListener('click', function () { stockBuy(b); });
    var hm = document.querySelector('[data-stkhome]');
    if (hm) hm.addEventListener('click', function () { stockSheet(); });
  }

  function stockBuy(btn) {
    var id = parseInt(btn.getAttribute('data-stkb'), 10);
    var old = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spin"></span> در حال خرید…';
    api('stock_buy', { id: id }).then(function (r) {
      btn.disabled = false;
      btn.innerHTML = old;
      if (!r.ok) {
        toast(r.message, 'err');
        if (r.need === 'charge') go('charge');
        return;
      }
      toast('✅ خرید انجام شد', 'ok');
      var h = '<div class="alert s">✅ ' + esc(r.message) + '</div>';
      if (r.title) h += '<div class="sec-t"><span>' + esc(r.title) + '</span></div>';
      if (r.payload) {
        h += '<div class="stk-pl">' + esc(r.payload) + '</div>' +
          '<button type="button" class="btn gh w" style="margin-top:9px" data-copy="' + esc(r.payload) + '">📋 کپی</button>';
      }
      if (r.is_file) h += '<div class="alert i">📎 فایل در ربات برای شما ارسال شد.</div>';
      h += '<div class="hint" style="margin-top:9px">👛 موجودی جدید: ' + esc(r.balance_txt) + '</div>' +
        '<button type="button" class="btn gh w" style="margin-top:9px" data-stkhome="1">🏪 بازگشت به انبار</button>';
      sheet('🎉 خرید موفق', h);
      var hm = document.querySelector('[data-stkhome]');
      if (hm) hm.addEventListener('click', function () { stockSheet(); });
    });
  }

  function stockMine() {
    stkCss();
    sheet('🎒 خریدهای من', stkLoad('در حال دریافت…'));
    api('stock_mine').then(function (r) {
      if (!r.ok) { sheet('🎒 خریدهای من', '<div class="alert e">' + esc(r.message) + '</div>'); return; }
      var it = r.items || [], h = '';
      if (!it.length) {
        h = '<div class="card tight">' + empty('🎒', 'هنوز خریدی نداشته‌اید', 'از انبار ملی بستهٔ دلخواهتان را تهیه کنید.', '') + '</div>';
      } else {
        h = '<div class="card tight">';
        it.forEach(function (x) {
          h += '<div class="row"><div class="ri">' + esc(x.kind_icon || '📦') + '</div>' +
            '<div class="rt"><b>' + esc(x.title || x.cat || '—') + '</b>' +
            '<span>' + esc(x.cat || '') + (x.date ? ' • ' + esc(x.date) : '') + '</span></div>' +
            '<div class="rv"><button type="button" class="btn sm gh" data-stkr="' + x.id + '">📤 دریافت</button></div></div>';
        });
        h += '</div>';
      }
      h += '<button type="button" class="btn gh w" style="margin-top:10px" data-stkhome="1">⬅️ بازگشت به انبار</button>';
      sheet('🎒 خریدهای من', h);
      Array.prototype.forEach.call(document.querySelectorAll('[data-stkr]'), function (b) {
        b.addEventListener('click', function () {
          api('stock_resend', { id: parseInt(b.getAttribute('data-stkr'), 10) }).then(function (q) {
            toast(q.ok ? q.msg : q.message, q.ok ? 'ok' : 'err');
          });
        });
      });
      var hm = document.querySelector('[data-stkhome]');
      if (hm) hm.addEventListener('click', function () { stockSheet(); });
    });
  }

  /* کارت مقصد فروشگاه با همان طرح هوشمند بانکی */
  function c3dShopCard(card) {
    if (!card || !card.number) return '';
    c3dCss();
    var art = c3dArt(card.bank, card.number);
    return '<div class="c3dm" style="background:' + art.bg + '">' +
      '<div class="c3d-pat" style="background-image:' + art.pat + ';background-size:' + art.size + '"></div>' +
      '<div class="c3d-sh"></div>' +
      '<div class="c3d-top"><div class="c3d-bhead">' +
        '<div class="c3d-logo" style="box-shadow:0 5px 14px ' + art.glow + '">' + esc(art.logo) + '</div>' +
        '<div><div class="c3d-bank">' + esc(art.name || 'کارت مقصد') + '</div>' +
        '<div class="c3d-bsub">کارت مقصد واریز</div></div></div></div>' +
      '<div class="c3d-chip"></div>' +
      '<div class="c3d-pan">' + esc(card.number) + '</div>' +
      '<div class="c3d-bot"><div><div class="c3d-hn">CARD HOLDER</div>' +
      '<div class="c3d-hv">' + esc(card.holder || '—') + '</div></div>' +
      '<div style="text-align:left"><div class="c3d-hn">روش پرداخت</div>' +
      '<div class="c3d-hv" style="font-size:11px">کارت به کارت</div></div></div>' +
      '</div>';
  }

  /* ---------- رفتن به صفحهٔ هوشمند ثبت کارت ----------
     هنگام جابه‌جایی بین صفحه‌های مینی‌اپ، تلگرام initData را دوباره نمی‌سازد؛
     پس همان مقدار احراز هویت همراه آدرس فرستاده می‌شود. */
  function appLink(page, hash) {
    var u = page + (page.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
    if (INIT) u += '&init=' + encodeURIComponent(INIT);
    var h = String(hash || '');
    if (!h && LAUNCH_HASH.indexOf('tgWebApp') !== -1) h = LAUNCH_HASH;
    else if (!h && INIT) h = '#tgWebAppData=' + encodeURIComponent(INIT);
    return u + h;
  }

  function openCardPage() {
    haptic();
    var url = appLink('card.php?from=app');
    try { window.location.assign(url); }
    catch (eNav) { window.location.href = url; }
  }

  function cardsSheet() {
    c3dCss();
    S.cardPhoto = '';
    sheet('💳 کارت‌های من', '<div class="card tight" style="text-align:center;padding:24px">' +
      '<span class="spin" style="border-color:var(--line);border-top-color:var(--acc)"></span>' +
      '<div style="margin-top:10px;font-size:12.5px;color:var(--dim)">در حال دریافت کارت‌ها…</div></div>');

    api('cards').then(function (r) {
      if (!r.ok) { sheet('💳 کارت‌های من', '<div class="alert e">' + esc(r.message) + '</div>'); return; }
      if (!r.enabled) {
        sheet('💳 کارت‌های من', '<div class="alert w">احراز کارت در حال حاضر غیرفعال است.</div>');
        return;
      }

      S.cardCfg = r;
      var cards = r.cards || [];
      var h = '';

      if (r.guide) h += '<div class="alert i">ℹ️ ' + esc(r.guide) + '</div>';

      /* فقط کارت‌های تاییدشده و در‌انتظار ظرفیت اشغال می‌کنند؛ کارت ردشده نه */
      var active = 0;
      cards.forEach(function (c) {
        if (c.status === 'approved' || c.status === 'pending') active++;
      });
      var maxCards = +(r.max || 1);
      if (!(maxCards >= 1)) maxCards = 1;
      var left    = Math.max(0, maxCards - active);
      var blocked = (r.single === true && maxCards <= 1 && active > 0);

      if (!cards.length) {
        h += '<div class="card tight">' +
          empty('💳', 'هنوز کارتی ثبت نکرده‌اید', 'برای واریز کارت به کارت، اول کارت خودتان را ثبت کنید.', '') +
          '</div>';
      } else {
        cards.forEach(function (c) { h += c3dCard(c); });
        h += '<div class="hint" style="text-align:center;margin:-4px 0 12px">👆 روی کارت بزنید تا بچرخد</div>';
      }

      if (blocked) {
        h += '<div class="c3d-lock"><span>🔒</span><span>مدیر سقف کارت را روی <b>یک کارت</b> تنظیم کرده است. ' +
          'برای ثبت کارت جدید، ابتدا کارت فعلی را حذف کنید.</span></div>';
      } else if (left <= 0) {
        h += '<div class="alert w">سقف تعداد کارت‌های مجاز (' + fa(String(maxCards)) +
          ' کارت) پر شده است. برای ثبت کارت تازه، یکی را حذف کنید.</div>';
      } else {
        h += '<div class="sec-t"><span>➕ ثبت کارت جدید</span></div>' +
          '<div class="card tight">' +
          '<div style="font-size:13.5px;font-weight:800;margin-bottom:5px">صفحهٔ هوشمند ثبت کارت</div>' +
          '<div class="hint" style="margin:0 0 12px">🏦 تشخیص خودکار بانک &nbsp;·&nbsp; ✅ بررسی لحظه‌ای شماره‌کارت و شبا &nbsp;·&nbsp; 📷 پیوست تصویر کارت</div>' +
          '<button type="button" class="btn w" id="crdGo">💳 باز کردن صفحهٔ ثبت کارت</button>' +
          '<div class="hint" style="margin-top:10px">ظرفیت باقی‌مانده: <b>' + fa(String(left)) + '</b> از ' + fa(String(maxCards)) + ' کارت</div>' +
          '<div class="hint">🔒 فقط شمارهٔ کارت لازم است. هرگز CVV2، رمز دوم، رمز پویا یا تاریخ انقضا از شما خواسته نمی‌شود.</div>' +
          '</div>';
      }

      sheet('💳 کارت‌های من', h);
      c3dBind();

      var crdGo = $('crdGo');
      if (crdGo) {
        crdGo.addEventListener('click', function (ev) {
          if (ev && ev.preventDefault) ev.preventDefault();
          openCardPage();
        });
      }
    });
  }

  function doCardAdd(btn) {
    var pan = ($('crdPan') && $('crdPan').value) || '';
    if (!pan.replace(/[^0-9۰-۹٠-٩]/g, '')) { toast('شمارهٔ کارت را وارد کنید.', 'err'); return; }

    var d = { pan: pan };
    if ($('crdHolder')) d.holder = $('crdHolder').value || '';
    if ($('crdSheba'))  d.sheba  = $('crdSheba').value || '';
    if (S.cardPhoto) d.photo = S.cardPhoto;

    var old = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spin"></span> در حال ثبت…';
    api('card_add', d).then(function (r) {
      btn.disabled = false;
      btn.innerHTML = old;
      if (!r.ok) { toast(r.message, 'err'); return; }
      toast(r.msg, 'ok');
      cardsSheet();
    });
  }

  function doCardDel(id) {
    api('card_del', { id: id }).then(function (r) {
      if (!r.ok) { toast(r.message, 'err'); return; }
      toast(r.msg, 'ok');
      if (TP.card === id) TP.card = 0;
      cardsSheet();
    });
  }

  function pickCardSheet(cards) {
    var h = '<div class="alert i">کارت مبدأ واریز را انتخاب کنید. واریز باید از همین کارت انجام شود.</div>' +
      '<div class="card tight">';
    (cards || []).forEach(function (c) {
      var art = c3dArt(c.bank, c.pan);
      h += '<div class="row"><div class="ri" style="background:' + art.bg + ';color:#fff">💳</div>' +
        '<div class="rt"><b class="mono" dir="ltr">' + esc(c.pan) + '</b>' +
        '<span>' + esc(c.bank || 'کارت تایید‌شده') + '</span></div>' +
        '<div class="rv"><button type="button" class="btn sm" data-crdpick="' + c.id + '">انتخاب</button></div></div>';
    });
    h += '</div>';
    sheet('💳 انتخاب کارت مبدأ', h);
  }

  function tpNext() {
    if (TP.amount <= 0) { toast('اول مبلغ شارژ را انتخاب یا وارد کنید.', 'err'); haptic(); return; }
    var btn = $('tpGo');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spin"></span> در حال ساخت درخواست…'; }

    var pay = { amount: TP.amount, method: TP.method };
    if (TP.method === 'card' && TP.card > 0) pay.card_id = TP.card;

    api('topup', pay).then(function (r) {
      var b3 = $('tpGo');
      if (b3) { b3.disabled = false; b3.textContent = 'ادامه و دریافت اطلاعات پرداخت'; }
      if (!r.ok) {
        if (r.need_card) { toast(r.message, 'err'); cardsSheet(); return; }
        if (r.pick_card) { pickCardSheet(r.cards); return; }
        toast(r.message, 'err');
        return;
      }
      TP.order = r;
      TP.step = 2;
      window.scrollTo(0, 0);
      tpPay();
    });
  }

  function tpPay() {
    var r = TP.order;
    if (!r) { viewTopup(); return; }

    var h = tpHead(2, 'پرداخت',
      r.method === 'card' ? 'واریز کارت به کارت' : 'واریز ارز دیجیتال', 'topup');

    h += '<div class="amt3"><div class="l">مبلغ قابل پرداخت</div>' +
      '<div><span class="v">' + esc(r.amount_txt) + '</span></div></div>';

    if (r.method === 'card') {
      h += '<div class="sec-t"><span>💳 شماره کارت</span></div>' +
        c3dShopCard(r.card) + copyBox(r.card.number);
      if (r.card.holder) {
        h += '<div class="card tight">' + row('👤', 'به نام', '', esc(r.card.holder)) +
          (r.card.bank ? row('🏦', 'بانک', '', esc(r.card.bank)) : '') + '</div>';
      }
      if (r.tx) {
        S.rcp = { tx: r.tx, img: '' };
        h += '<div class="sec-t"><span>🧾 ارسال رسید</span>' +
          '<span class="bdg b">پیگیری #' + esc(String(r.tx)) + '</span></div>' +
          '<div class="card tight">' +
          '<div style="font-size:12px;color:var(--dim);line-height:2">' +
          '<div>۱ – مبلغ بالا را به شمارهٔ کارت واریز کنید.</div>' +
          '<div>۲ – از رسید عکس یا اسکرین‌شات بگیرید.</div>' +
          '<div>۳ – عکس را همین‌جا آپلود کنید؛ لازم نیست به چت ربات بروید.</div>' +
          '</div>' +
          '<input id="rcpFile" type="file" accept="image/*" style="display:none">' +
          '<button type="button" class="btn gh w" id="rcpPick" style="margin-top:9px">📷 انتخاب عکس رسید</button>' +
          '<div id="rcpPrev"></div>' +
          '<button type="button" class="btn w" id="rcpGo" style="margin-top:8px;display:none">✅ ارسال رسید برای بررسی</button>' +
          '</div>';
      }
    } else if (r.method === 'hooshpay') {
      h += hpPayBox(r);
    } else {
      if (r.rate_txt) {
        h += '<div class="card tight"><div style="font-size:11.5px;color:var(--dim)">نرخ لحظه‌ای دلار</div>' +
          '<div style="font-weight:700">' + esc(r.rate_txt) + '</div></div>';
      }
      var opts = '';
      (r.assets || []).forEach(function (a) {
        h += '<div class="sec-t"><span>' + a.icon + ' ' + esc(a.label) + '</span>' +
          '<span class="bdg b">' + esc(a.network) + '</span></div><div class="card tight">' +
          (a.qty_txt ? '<div style="font-size:11.5px;color:var(--dim)">مقدار واریزی</div>' +
            '<div style="font-weight:700;font-size:15px" class="mono">' + esc(a.qty_txt) + ' ' + esc(a.key) + '</div>' : '') +
          copyBox(a.address) +
          (a.memo ? '<div style="font-size:11.5px;color:var(--dim);margin-top:7px">🏷 ممو / تگ (الزامی)</div>' + copyBox(a.memo) : '') +
          (a.note ? '<div class="hint">ℹ️ ' + esc(a.note) + '</div>' : '') + '</div>';
        opts += '<option value="' + esc(a.id) + '">' + esc(a.label) + ' • ' + esc(a.network) + '</option>';
      });
      S.tu = { amount: r.amount || TP.amount, gw: '' };
      if (r.can_hash) {
        h += '<div class="sec-t"><span>🔗 ثبت هش تراکنش</span></div><div class="card tight">' +
          '<div class="fld"><label>واریز از کدام ش����که انجام شد؟</label>' +
          '<select id="hxGw">' + opts + '</select></div>' +
          '<div class="fld"><label>هش تراکنش (TXID)</label>' +
          '<input id="hxTx" class="mono" type="text" autocomplete="off" placeholder="0x…"></div>' +
          '<div class="hint">هش را همین‌جا بفرستید؛ اگر خودکار تایید شود، موجودی فوراً شارژ می‌شود.</div>' +
          '<button type="button" class="btn w" id="hxGo" style="margin-top:8px">✅ ارسال هش و ثبت واریز</button></div>';
      }
    }

    if (r.note) h += '<div class="alert w">' + esc(r.note) + '</div>';
    h += '<div class="btn-row"><button type="button" class="btn gh" data-tpback="topup">← تغییر مبلغ</button>' +
      '<button type="button" class="btn gh" data-go="wallet">👛 کیف پول</button></div>';

    $('view').innerHTML = h;
  }

  /* ================= مسیریابی ================= */
  function go(tab) {
    if (tab === 'charge' || tab === 'rate') { TP.step = 1; go('topup'); return; }
    if (tab === 'verify') { openVerify(); return; }
    if (tab === 'cards') { cardsSheet(); return; }
    if (tab === 'stock') { stockSheet(); return; }
    if (tab === 'profile') { profileSheet(); return; }
    if (tab === 'tut') { tutSheet(); return; }
    if (tab === 'newtk') { newTicketSheet(); return; }
    if (tab === 'test') { doTest(); return; }

    S.tab = tab;
    var hiTab = tab === 'topup' ? 'wallet' : tab;
    Array.prototype.forEach.call($('tabs').children, function (b) {
      b.classList.toggle('on', b.getAttribute('data-tab') === hiTab);
    });
    closeSheet();
    window.scrollTo(0, 0);

    if (tab === 'home') viewHome();
    else if (tab === 'shop') viewShop();
    else if (tab === 'svc') viewSvc();
    else if (tab === 'wallet') viewWallet();
    else if (tab === 'help') viewHelp();
    else if (tab === 'topup') viewTopup();
    else if (tab === 'rs') viewReseller();
  }

  /* fixed79: انتخاب سرور تست در مینی‌اپ — اگر بیش از یک سرور تست فعال باشد، لیست نمایش می‌دهد */
  function doTest() {
    sheet('🧪 اکانت تست', '<div class="card tight" style="text-align:center;padding:26px">' +
      '<span class="spin" style="border-color:var(--line);border-top-color:var(--acc)"></span>' +
      '<div style="margin-top:11px;font-size:12.5px;color:var(--dim)">دریافت سرورهای تست…</div></div>');
    api('test_panels').then(function (r) {
      var list = (r && r.ok && r.panels) ? r.panels : [];
      if (list.length <= 1) { doTestRun(list.length ? +list[0].id : 0); return; }
      var html = '<div class="alert i">سروری که می‌خواهید اکانت تست آن را بگیرید انتخاب کنید:</div>';
      list.forEach(function (p) {
        var spec = [];
        if (p.spec) spec.push(p.spec); else if (+p.volume_gb > 0) spec.push(fa(p.volume_gb) + ' گیگ');
        if (+p.days > 0) spec.push(fa(p.days) + ' روز');
        if (+p.hours > 0) spec.push(fa(p.hours) + ' ساعت');
        var lim = Math.max(1, +p.limit || 1), used = +p.used || 0, ex = !!p.exhausted;
        html += '<button type="button" class="btn ' + (ex ? 'gh ' : '') + 'w tstPick" data-pid="' + (+p.id) + '"' + (ex ? ' disabled' : '') + ' style="margin-top:8px;text-align:right">' +
          (ex ? '⛔️ ' : '🧪 ') + esc(p.name) +
          '<span style="display:block;font-size:11.5px;opacity:.75;margin-top:3px">' + esc(spec.join(' • ') || '—') +
          ' • استفاده: ' + fa(used) + '/' + fa(lim) + '</span></button>';
      });
      sheet('🧪 اکانت تست', html);
    });
  }

  function doTestRun(pid) {
    sheet('🧪 اکانت تست', '<div class="card tight" style="text-align:center;padding:26px">' +
      '<span class="spin" style="border-color:var(--line);border-top-color:var(--acc)"></span>' +
      '<div style="margin-top:11px;font-size:12.5px;color:var(--dim)">در حال ساخت اکانت تست…</div></div>');

    api('test', pid > 0 ? { panel_id: pid } : {}).then(function (r) {
      if (r.need === 'verify') { toast(r.message, 'err'); openVerify(); return; }
      if (!r.ok) { sheet('🧪 اکانت تست', '<div class="alert e">' + esc(r.message) + '</div>'); return; }
      toast(r.message, 'ok');
      serviceSheet(r.service, true);
    });
  }

  /* ================= رویدادها ================= */
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;

    var el;

    if ((el = t.closest('[data-tab]'))) { haptic(); go(el.getAttribute('data-tab')); return; }
    if ((el = t.closest('.tstPick'))) { haptic(); doTestRun(+el.getAttribute('data-pid') || 0); return; } /* fixed79 */
    if ((el = t.closest('#meEdit'))) { haptic(); profileSheet(); return; }
    if ((el = t.closest('#gGo'))) { haptic(); redeemGift(el); return; }
    if ((el = t.closest('#refShare'))) { haptic(); shareRef(); return; }
    if ((el = t.closest('[data-go]'))) { haptic(); go(el.getAttribute('data-go')); return; }
    if ((el = t.closest('[data-copy]'))) { copy(el.getAttribute('data-copy'), el); return; }
    if ((el = t.closest('[data-hpchk]'))) { haptic(); hpCheck(el); return; }
    if ((el = t.closest('#crdSave'))) { haptic(); doCardAdd(el); return; }
    if ((el = t.closest('[data-crddel]'))) { haptic(); doCardDel(+el.getAttribute('data-crddel')); return; }
    if ((el = t.closest('[data-crdpick]'))) {
      haptic();
      TP.card = +el.getAttribute('data-crdpick');
      closeSheet();
      tpNext();
      return;
    }
    if ((el = t.closest('#rsGo'))) { haptic(); rsCalcNow(); return; }
    if ((el = t.closest('#rsMake'))) { haptic(); doRsCreate(el); return; }

    if ((el = t.closest('[data-pnl]'))) {
      haptic(); S.shopPanel = +el.getAttribute('data-pnl'); S.cat = ''; S.shopStep = 2; S.shopHome = false; paintShop(); return;
    }
    if ((el = t.closest('[data-scat]'))) {
      haptic(); S.cat = el.getAttribute('data-scat') || ''; S.shopStep = 3; paintShop(); return;
    }
    if ((el = t.closest('[data-sback]'))) {
      haptic();
      /* fixed81: بازگشت عمدی به مرحلهٔ سرور نباید دوباره خودکار رد شود */
      if (+el.getAttribute('data-sback') === 1) { S.shopPanel = 0; S.cat = ''; S.shopStep = 1; S.shopHome = true; }
      else { S.cat = ''; S.shopStep = 2; S.shopHome = false; }
      paintShop(); return;
    }
    if ((el = t.closest('[data-cat]'))) { S.cat = el.getAttribute('data-cat'); S.shopStep = 3; paintShop(); return; }
    if ((el = t.closest('[data-buy]'))) { haptic(); buySheet(+el.getAttribute('data-buy')); return; }
    if ((el = t.closest('[data-do-buy]'))) { doBuy(+el.getAttribute('data-do-buy'), el); return; }

    if ((el = t.closest('[data-svc]'))) {
      var sid = +el.getAttribute('data-svc');
      haptic();
      api('service', { id: sid }).then(function (r) {
        if (!r.ok) { toast(r.message, 'err'); return; }
        serviceSheet(r.service, false);
      });
      return;
    }
    if ((el = t.closest('[data-renew]'))) { haptic(); renewSheet(+el.getAttribute('data-renew')); return; }
    if ((el = t.closest('[data-devs]'))) { haptic(); devicesSheet(+el.getAttribute('data-devs')); return; }
    if ((el = t.closest('[data-links]'))) { haptic(); linksSheet(+el.getAttribute('data-links')); return; }
    if ((el = t.closest('[data-devdel]'))) { haptic(); var dv = (el.getAttribute('data-devdel') || '').split(':'); doDevDel(+dv[0], +dv[1], el); return; }
    if ((el = t.closest('[data-devclr]'))) { haptic(); doDevClear(+el.getAttribute('data-devclr'), el); return; }
    if ((el = t.closest('[data-rnw]'))) { haptic(); var pr = (el.getAttribute('data-rnw') || '').split(':'); doRenew(+pr[0], +pr[1], el); return; }
    if ((el = t.closest('[data-del]'))) { haptic(); delSheet(+el.getAttribute('data-del')); return; }
    if ((el = t.closest('[data-delok]'))) { haptic(); doDel(+el.getAttribute('data-delok'), el); return; }
    if ((el = t.closest('[data-sync]'))) {
      var b = el; b.disabled = true; b.innerHTML = '<span class="spin"></span>';
      api('sync', { id: +el.getAttribute('data-sync') }).then(function (r) {
        if (!r.ok) { toast(r.message, 'err'); b.disabled = false; b.textContent = '🔄 به‌روزرسانی مصرف'; return; }
        toast(r.message, 'ok');
        serviceSheet(r.service, false);
      });
      return;
    }

    if ((el = t.closest('[data-pm]'))) {
      S.pm = el.getAttribute('data-pm');
      Array.prototype.forEach.call($('pmRow').children, function (c) {
        c.classList.toggle('on', c === el);
      });
      var v = parseInt(($('cAmt') && $('cAmt').value) || '0', 10) || 0;
      if (S.pm === 'crypto' && v > 0) liveRate(v); else $('rateBox').innerHTML = '';
      return;
    }
    if ((el = t.closest('[data-tpback]'))) {
      haptic();
      if (el.getAttribute('data-tpback') === 'topup') { TP.step = 1; viewTopup(); }
      else go('wallet');
      return;
    }
    if ((el = t.closest('[data-qamt]'))) {
      haptic();
      TP.amount = +el.getAttribute('data-qamt');
      var fq = $('tpAmt');
      if (fq) fq.value = TP.amount;
      tpPaint();
      tpRate();
      return;
    }
    if ((el = t.closest('[data-pmc]'))) {
      haptic();
      TP.method = el.getAttribute('data-pmc');
      Array.prototype.forEach.call(document.querySelectorAll('.pmc'), function (c) {
        c.classList.toggle('on', c.getAttribute('data-pmc') === TP.method);
      });
      tpRate();
      return;
    }
    if (t.closest('#tpGo')) { haptic(); tpNext(); return; }
    if (t.closest('#cGo')) { doTopup(); return; }
    if (t.closest('#rcpPick')) { haptic(); pickReceipt(); return; }
    if (t.closest('#rcpGo')) { haptic(); sendReceipt(); return; }
    if (t.closest('#hxGo')) { submitHash(); return; }

    if (t.closest('[data-bot]')) {
      closeSheet();
      if (TG && TG.close) TG.close();
      return;
    }

    if ((el = t.closest('[data-tk]'))) { haptic(); ticketSheet(+el.getAttribute('data-tk')); return; }
    if (t.closest('#tkGo')) {
      var sub = ($('tkSub') && $('tkSub').value) || '';
      var txt = ($('tkTxt') && $('tkTxt').value) || '';
      if (!txt.trim()) { toast('متن پیام را بنویسید.', 'err'); return; }
      api('ticket_new', { subject: sub, text: txt }).then(function (r) {
        if (!r.ok) { toast(r.message, 'err'); return; }
        toast(r.message, 'ok'); closeSheet(); viewHelp();
      });
      return;
    }
    if ((el = t.closest('[data-reply]'))) {
      var id = +el.getAttribute('data-reply');
      var m = ($('tkMsg') && $('tkMsg').value) || '';
      if (!m.trim()) { toast('متن پیام را بنویسید.', 'err'); return; }
      api('ticket_reply', { id: id, text: m }).then(function (r) {
        if (!r.ok) { toast(r.message, 'err'); return; }
        toast(r.message, 'ok'); ticketSheet(id);
      });
      return;
    }

    if ((el = t.closest('[data-vk]'))) { verifyStart(el.getAttribute('data-vk')); return; }
    if ((el = t.closest('[data-vsend]'))) {
      var kind = el.getAttribute('data-vsend');
      var tv = vNorm(($('vTarget') && $('vTarget').value) || '', kind);
      var bad = vCheck(tv, kind);
      if (bad) { vErr(bad); haptic(); return; }
      vErr('');
      if ($('vTarget')) $('vTarget').value = tv;
      S.vk = kind; S.vt = tv;
      el.disabled = true; el.innerHTML = '<span class="spin"></span>';
      api('verify_start', { kind: kind, target: tv }).then(function (r) {
        if (!r.ok) {
          if (r.done || r.verified) { closeSheet(); toast(r.message || 'حساب شما قبلاً تایید شده است.', 'ok'); return; }
          el.disabled = false; el.textContent = 'ارسال کد تایید'; vErr(r.message); return;
        }
        verifyCodeSheet(r.message);
      });
      return;
    }
    if (t.closest('#vResend')) {
      var rb = $('vResend');
      if (rb) { rb.disabled = true; rb.textContent = 'در حال ارسال…'; }
      api('verify_start', { kind: S.vk || 'email', target: S.vt || '' }).then(function (r) {
        if (!r.ok) { vErr(r.message); vTick(20); return; }
        vErr(''); toast('کد تایید دوباره ارسال شد.', 'ok'); vTick(60);
      });
      return;
    }
    if (t.closest('#vGo')) {
      var code = vNorm(($('vCode') && $('vCode').value) || '', 'code');
      if (code.length < 4) { vErr('کد ارسال‌شده را کامل وارد کنید.'); haptic(); return; }
      vErr('');
      var gb = $('vGo');
      if (gb) { gb.disabled = true; gb.innerHTML = '<span class="spin"></span>'; }
      api('verify_code', { code: code }).then(function (r) {
        var g2 = $('vGo');
        if (!r.ok) {
          if (g2) { g2.disabled = false; g2.textContent = 'تایید کد'; }
          vErr(r.message); return;
        }
        if (S.vTimer) { clearInterval(S.vTimer); S.vTimer = null; }
        toast(r.message, 'ok');
        if (S.boot && S.boot.user) S.boot.user.verified = true;
        closeSheet(); go('home');
      });
      return;
    }

    if (t.closest('#pGo')) {
      api('save_profile', {
        name: ($('pName') && $('pName').value) || '',
        email: ($('pMail') && $('pMail').value) || '',
        phone: ($('pTel') && $('pTel').value) || ''
      }).then(function (r) {
        if (!r.ok) { toast(r.message, 'err'); return; }
        toast(r.message, 'ok');
        closeSheet();
        start();
      });
      return;
    }

    if (t.closest('#balBtn')) { go('wallet'); return; }
  });

  /* ================= نمایندگی ================= */
  function viewReseller() {
    skeleton(3);
    api('rs_info').then(function (r) {
      if (!r.ok) { $('view').innerHTML = '<div class="alert e">' + esc(r.message) + '</div>'; return; }

      if (!r.is_reseller) {
        $('view').innerHTML = '<div class="card">' +
          empty('🏷', 'شما نماینده نیستید', 'برای دریافت نمایندگی با پشتیبانی هماهنگ کنید.') + '</div>';
        return;
      }

      S.rs = r;
      S.rsPrice = null;

      var q = r.quota, lm = r.limits, tf = r.tariff, cur = S.boot.shop.currency;

      var h = '<div class="card hero"><div class="lbl">' + esc(r.level_label) + '</div>' +
        '<div class="big">' + esc(q.balance_txt) + ' <small>' + esc(cur) + '</small></div>';

      if (r.level >= 2) {
        h += '<div style="font-size:11.5px;color:var(--dim);margin-top:5px">' +
          'سقف بدهی: ' + esc(q.credit_txt) + ' • قابل استفاده: <b>' + esc(q.avail_txt) + '</b></div>';
        if (q.debt > 0) {
          h += '<div class="alert w" style="margin-top:9px">⚠️ بدهی فعلی: ' + esc(q.debt_txt) + ' ' + esc(cur) + '</div>';
        }
      } else {
        h += '<div style="font-size:11.5px;color:var(--dim);margin-top:5px">' +
          'در سطح ۱ ساخت کانفیگ فقط با موجودی کافی امکان‌پذیر است.</div>';
      }
      h += '</div>';

      h += '<div class="grid3">' +
        '<div class="stat"><b>' + fa(r.stats.services) + '</b><span>کانفیگ</span></div>' +
        '<div class="stat"><b>' + fa(r.stats.active) + '</b><span>فعال</span></div>' +
        '<div class="stat"><b>' + esc(r.stats.spent_txt) + '</b><span>جمع خرید</span></div></div>';

      h += '<div class="sec-t"><span>🛠 ساخت کانفیگ دلخواه</span></div><div class="card">';

      if (r.panels.length > 1) {
        h += '<div class="fld"><label>سرور</label><select id="rsPanel" style="width:100%">';
        r.panels.forEach(function (p) {
          var pl = esc(p.name);
          if (p.custom && p.gb_txt) pl += ' | گیگ: ' + esc(p.gb_txt) + ' • روز: ' + esc(p.day_txt);
          h += '<option value="' + p.id + '">' + pl + '</option>';
        });
        h += '</select></div>';
      } else if (r.panels.length === 1) {
        h += '<input type="hidden" id="rsPanel" value="' + r.panels[0].id + '">';
      } else {
        h += '<div class="alert w">هیچ سروری برای نمایندگی فعال نیست.</div>';
      }

      h += '<div class="grid2">' +
        '<div class="fld"><label>حجم (گیگابایت)</label>' +
        '<input id="rsGb" class="mono" type="number" inputmode="numeric" min="' + lm.min_gb + '" max="' + lm.max_gb +
        '" placeholder="' + lm.min_gb + ' – ' + lm.max_gb + '"></div>' +
        '<div class="fld"><label>مدت (روز)</label>' +
        '<input id="rsDays" class="mono" type="number" inputmode="numeric" min="' + lm.min_days + '" max="' + lm.max_days +
        '" placeholder="' + lm.min_days + ' – ' + lm.max_days + '"></div></div>';

      h += '<div class="fld"><label>نام کانفیگ (اختیاری)</label>' +
        '<input id="rsName" class="mono" type="text" maxlength="24" placeholder="my-client-01">' +
        '<div class="hint">فقط حروف انگلیسی، عدد، خط تیره و اندرلاین • حداقل ۳ کاراکتر</div></div>';

      h += '<div class="hint" id="rsTf">تعرفهٔ شما: هر گیگ ' + esc(tf.gb_txt) + ' • هر روز ' + esc(tf.day_txt) +
        (r.discount > 0 ? ' • تخفیف ' + fa(r.discount) + '٪' : '') + '</div>';

      h += '<div id="rsBox"></div>' +
        '<button type="button" class="btn w" id="rsGo" style="margin-top:9px">🧾 محاسبهٔ قیمت</button></div>';

      if (r.note) h += '<div class="alert i" style="margin-top:11px">' + esc(r.note) + '</div>';

      h += '<div class="sec-t"><span>📦 کانفیگ‌های ساختهٔ من</span></div>';
      if (!r.services.length) {
        h += '<div class="card">' + empty('📫', 'هنوز کانفیگی نساختید', 'اولین کانفیگ نمایندگی خود را بسازید.') + '</div>';
      } else {
        h += '<div class="card tight">';
        r.services.forEach(function (sv) {
          h += '<div class="row" data-svc="' + sv.id + '"><div class="ri">🏷</div>' +
            '<div class="rt"><b>' + esc(sv.name) + '</b><span>' + esc(sv.volume_txt) + ' • ' + esc(sv.remain_txt) + '</span></div>' +
            '<div class="rv" style="color:var(--mut)">›</div></div>';
        });
        h += '</div>';
      }

      $('view').innerHTML = h;
      bindReseller();
    });
  }

  function bindReseller() {
    var t = null;
    function onInput() {
      clearTimeout(t);
      var gb = parseInt(($('rsGb') && $('rsGb').value) || '0', 10) || 0;
      var dy = parseInt(($('rsDays') && $('rsDays').value) || '0', 10) || 0;
      S.rsPrice = null;
      if (gb <= 0 || dy <= 0) { $('rsBox').innerHTML = ''; return; }
      $('rsBox').innerHTML = '<div class="alert i" style="margin-top:9px"><span class="spin"></span> محاسبهٔ قیمت…</div>';
      t = setTimeout(function () { rsPrice(gb, dy); }, 500);
    }
    if ($('rsGb')) $('rsGb').addEventListener('input', onInput);
    if ($('rsDays')) $('rsDays').addEventListener('input', onInput);
    if ($('rsPanel')) $('rsPanel').addEventListener('change', function () { rsTariffLine(); onInput(); });
    rsTariffLine();
  }

  /* تعرفه و محدودهٔ سرورِ انتخاب‌شده را زیر فرم ساخت کانفیگ نشان می‌دهد */
  function rsTariffLine() {
    var r = S.rs;
    if (!r || !$('rsTf')) return;
    var pid = parseInt(($('rsPanel') && $('rsPanel').value) || '0', 10) || 0;
    var p = null;
    (r.panels || []).forEach(function (x) { if (+x.id === pid) p = x; });
    var tf = r.tariff || {};
    var gbT = p && p.gb_txt ? p.gb_txt : tf.gb_txt;
    var dyT = p && p.day_txt ? p.day_txt : tf.day_txt;
    var h = (p && p.custom ? 'تعرفهٔ این سرور: هر گیگ ' : 'تعرفهٔ شما: هر گیگ ') + esc(gbT) + ' • هر روز ' + esc(dyT);
    if (p && +p.off > 0) h += ' • ' + fa(p.off) + '٪ تخفیف ویژهٔ این سرور';
    if (r.discount > 0) h += ' • تخفیف سطح ' + fa(r.discount) + '٪';
    if (p && p.note) h += '<br>📝 ' + esc(p.note);
    $('rsTf').innerHTML = h;
    if (p && $('rsGb') && +p.min_gb > 0 && +p.max_gb > 0) {
      $('rsGb').min = p.min_gb; $('rsGb').max = p.max_gb;
      $('rsGb').placeholder = p.min_gb + ' – ' + p.max_gb;
    }
    if (p && $('rsDays') && +p.min_days > 0 && +p.max_days > 0) {
      $('rsDays').min = p.min_days; $('rsDays').max = p.max_days;
      $('rsDays').placeholder = p.min_days + ' – ' + p.max_days;
    }
  }

  function rsCalcNow() {
    var gb = parseInt(($('rsGb') && $('rsGb').value) || '0', 10) || 0;
    var dy = parseInt(($('rsDays') && $('rsDays').value) || '0', 10) || 0;
    if (gb <= 0 || dy <= 0) { toast('حجم و مدت را وارد کنید.', 'err'); return; }
    $('rsBox').innerHTML = '<div class="alert i" style="margin-top:9px"><span class="spin"></span> محاسبهٔ قیمت…</div>';
    rsPrice(gb, dy);
  }

  function rsPrice(gb, days) {
    var pid = parseInt(($('rsPanel') && $('rsPanel').value) || '0', 10) || 0;
    api('rs_price', { volume_gb: gb, days: days, panel_id: pid }).then(function (r) {
      if (!r.ok) {
        $('rsBox').innerHTML = '<div class="alert e" style="margin-top:9px">' + esc(r.message) + '</div>';
        S.rsPrice = null;
        return;
      }
      S.rsPrice = r;
      var p = r.price, cur = S.boot.shop.currency;

      var h = '<div class="card tight" style="margin-top:9px">' +
        '<div class="row"><div class="ri">📊</div><div class="rt"><b>مشخصات</b>' +
        '<span>' + fa(p.gb) + ' گیگابایت • ' + fa(p.days) + ' روز</span></div>' +
        '<div class="rv">' + esc(p.base_txt) + '</div></div>';

      if (p.discount > 0) {
        h += '<div class="row"><div class="ri">🎁</div><div class="rt"><b>تخفیف نمایندگی</b>' +
          '<span>' + fa(p.discount_pct) + '٪</span></div>' +
          '<div class="rv n">− ' + fa(p.discount) + '</div></div>';
      }

      h += '<div class="row"><div class="ri">💰</div><div class="rt"><b>قابل پرداخت</b>' +
        '<span>از موجودی کیف پول کسر می‌شود</span></div>' +
        '<div class="rv p">' + esc(p.final_txt) + ' ' + esc(cur) + '</div></div></div>';

      if (!r.afford) h += '<div class="alert w" style="margin-top:8px">' + esc(r.message) + '</div>';

      h += '<button type="button" class="btn w ok" id="rsMake" style="margin-top:9px"' +
        (r.afford ? '' : ' disabled') + '>⚡️ ساخت کانفیگ</button>';

      $('rsBox').innerHTML = h;
    });
  }

  function doRsCreate(btn) {
    var gb  = parseInt(($('rsGb') && $('rsGb').value) || '0', 10) || 0;
    var dy  = parseInt(($('rsDays') && $('rsDays').value) || '0', 10) || 0;
    var nm  = ($('rsName') && $('rsName').value) || '';
    var pid = parseInt(($('rsPanel') && $('rsPanel').value) || '0', 10) || 0;

    if (gb <= 0 || dy <= 0) { toast('حجم و مدت را وارد کنید.', 'err'); return; }

    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spin"></span> در حال ساخت…'; }

    api('rs_create', { volume_gb: gb, days: dy, name: nm, panel_id: pid }).then(function (r) {
      if (!r.ok) {
        toast(r.message, 'err');
        if (btn) { btn.disabled = false; btn.innerHTML = '⚡️ ساخت کانفیگ'; }
        if (r.need === 'verify') openVerify();
        return;
      }
      haptic('ok');
      toast(r.message, 'ok');
      serviceSheet(r.service, true);
      viewReseller();
    });
  }

  /* ================= شروع ================= */
  function start() {
    api('boot').then(function (r) {
      if (!r.ok) {
        $('view').innerHTML = '<div class="center"><div>' +
          '<div style="font-size:46px;margin-bottom:11px">🔒</div>' +
          '<div style="font-weight:700;margin-bottom:6px">اجازهٔ دسترسی نیست</div>' +
          '<div style="color:var(--mut);font-size:12.5px">' + esc(r.message) + '</div></div></div>';
        return;
      }
      S.boot = r;
      if (r.shop.accent) document.documentElement.style.setProperty('--acc', r.shop.accent);
      paintHeader();

      var hs = String(LAUNCH_HASH || location.hash || '').replace('#', '');

      /* پنل نمایندگی کاملاً جداست؛ تب داخلی حذف شده است */
      if (hs === 'reseller' && r.flags && r.flags.reseller) {
        location.replace('reseller.php');
        return;
      }

      go(S.tab);

      /* بازگشت از صفحهٔ ثبت کارت: همان بخش خودکار باز می‌شود */
      if (hs === 'cards') setTimeout(cardsSheet, 80);
    });
  }

  if (!TG || !INIT) {
    $('view').innerHTML = '<div class="center"><div>' +
      '<div style="font-size:46px;margin-bottom:11px">📲</div>' +
      '<div style="font-weight:700;margin-bottom:6px">این صفحه باید از داخل تلگرام باز شود</div>' +
      '<div style="color:var(--mut);font-size:12.5px">وارد ربات شوید و دکمهٔ اپلیکیشن را بزنید.</div></div></div>';
  } else {
    start();
  }
})();
</script>

<?php endif; ?>

<script>
/* v11 – نمایش اعداد به انگلیسی و ورود فقط با ارقام انگلیسی */
(function () {
  var FA = '۰۱۲۳۴۵۶۷۸۹';
  var AR = '٠١٢٣٤٥٦٧٨٩';
  function conv(v) {
    return String(v).replace(/[۰-۹]/g, function (d) { return FA.indexOf(d); })
                    .replace(/[٠-٩]/g, function (d) { return AR.indexOf(d); });
  }
  window.enDigits = conv;
  var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, INPUT: 1, SELECT: 1 };
  var HAS = /[۰-۹٠-٩]/;
  function walk(n) {
    if (!n) return;
    if (n.nodeType === 3) { if (HAS.test(n.nodeValue)) n.nodeValue = conv(n.nodeValue); return; }
    if (n.nodeType !== 1 || SKIP[n.tagName]) return;
    for (var i = 0; i < n.childNodes.length; i++) walk(n.childNodes[i]);
  }
  var t = null, obs = null;
  function sweep() {
    if (obs) obs.disconnect();
    try { walk(document.body); } catch (e) {}
    if (obs) obs.observe(document.body, { childList: true, subtree: true, characterData: true });
  }
  function boot() {
    try {
      obs = new MutationObserver(function () { clearTimeout(t); t = setTimeout(sweep, 60); });
    } catch (e) {}
    sweep();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

  document.addEventListener('beforeinput', function (e) {
    if (!e.data) return;
    var c = conv(e.data);
    if (c === e.data) return;
    var el = e.target;
    if (!el || !('value' in el)) return;
    e.preventDefault();
    try { el.setRangeText(c, el.selectionStart, el.selectionEnd, 'end'); }
    catch (x) { el.value = conv(el.value) + c; }
    el.dispatchEvent(new Event('input', { bubbles: true }));
  }, true);
})();
</script>
</body>
</html>
