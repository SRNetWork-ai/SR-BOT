<?php

/**
 * پنل اختصاصی نمایندگان – مینی‌اپ مستقل
 * داده‌ها از api.php (اکشن‌های rs_*) و پس از اعتبارسنجی initData خوانده می‌شوند.
 */

require __DIR__ . '/../app/bootstrap.php';

/* اجازه باز شدن داخل تلگرام (رفع خطای refused to connect) */
if (!headers_sent()) {
    header_remove('X-Frame-Options');
    header("Content-Security-Policy: frame-ancestors https://web.telegram.org https://*.telegram.org https://telegram.org https://*.t.me tg: 'self';");
}

$ready   = false;
$accent  = '#6C8CFF';
$appName = 'پنل نمایندگی';
$note    = '';

if (!app_installed()) {
    $note = 'ربات هنوز نصب نشده است.';
} else {
    try {
        boot();
        if ((string)DB::setting('miniapp_enabled', '1') !== '1') {
            $note = 'مینی‌اپ در حال حاضر توسط مدیر غیرفعال شده است.';
        } elseif (class_exists('Reseller') && !Reseller::enabled()) {
            $note = 'بخش نمایندگی در حال حاضر غیرفعال است.';
        } else {
            $ready  = true;
            $accent = (string)DB::setting('miniapp_accent', '#6C8CFF');
            $t      = trim((string)DB::setting('rs_app_title', ''));
            if ($t !== '') $appName = $t;
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
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<style>
:root{
  --acc: <?= h($accent) ?>;
  --bg:#0e1015; --card:#161a23; --card2:#1c2130; --line:#2a3140;
  --tx:#eef2fa; --dim:#b7c1d4; --mut:#7e8aa1;
  --ok:#34d399; --warn:#f59e0b; --err:#f87171;
  --r:16px; --r2:22px;
  --safe: env(safe-area-inset-bottom, 0px);
  --num:"Vazirmatn","Segoe UI",system-ui,-apple-system,sans-serif;
  --d3:0 1px 0 rgba(255,255,255,.14) inset,0 -3px 0 rgba(0,0,0,.34) inset,0 8px 18px rgba(0,0,0,.40),0 3px 0 rgba(0,0,0,.45);
  --d3a:0 1px 0 rgba(255,255,255,.08) inset,0 2px 6px rgba(0,0,0,.35);
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;padding:0;min-height:100%;max-width:100%;overflow-x:hidden}
body{
  background:var(--bg);color:var(--tx);
  font-family:"Vazirmatn",system-ui,-apple-system,sans-serif;
  font-size:14.5px;line-height:1.9;
  font-feature-settings:"ss01" 1,"tnum" 1,"lnum" 1;
  background-image:radial-gradient(720px 420px at 100% -8%, color-mix(in srgb, var(--acc) 22%, transparent) 0%, transparent 62%);
  padding-bottom:calc(88px + var(--safe));
}
button,input,select,textarea{font-family:inherit;font-size:inherit;color:inherit}
.wrap{padding:14px 14px 0;max-width:640px;margin:0 auto}

/* ---------- عدد در جعبهٔ مخصوص ---------- */
.nb{
  display:inline-flex;align-items:baseline;gap:5px;direction:ltr;
  padding:5px 11px;border-radius:12px;background:rgba(255,255,255,.05);
  border:1px solid rgba(255,255,255,.09);font-family:var(--num);
  font-variant-numeric:tabular-nums lining-nums;font-weight:800;
  letter-spacing:.4px;white-space:nowrap;line-height:1.7;
}
.nb .u{font-size:10.5px;font-weight:500;color:var(--mut);letter-spacing:0}
.nb.big{font-size:20px;padding:8px 14px}
.nb.g{color:#8ff0cd;border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.10)}
.nb.r{color:#ffb4b4;border-color:rgba(248,113,113,.35);background:rgba(248,113,113,.10)}
.nb.a{color:#c9d6ff;border-color:color-mix(in srgb,var(--acc) 45%,transparent);background:color-mix(in srgb,var(--acc) 14%,transparent)}

/* ---------- سربرگ ---------- */
.hero{
  background:linear-gradient(160deg, color-mix(in srgb,var(--acc) 26%,#161a23) 0%, var(--card) 62%);
  border:1px solid var(--line);border-radius:var(--r2);padding:16px;
  box-shadow:0 14px 34px rgba(0,0,0,.34);
}
.hero .top{display:flex;align-items:center;gap:11px}
.ava{width:46px;height:46px;border-radius:15px;background:rgba(255,255,255,.10);
  display:grid;place-items:center;font-size:21px;flex:0 0 46px}
.hero .nm{font-weight:700;font-size:15.5px;line-height:1.5}
.hero .lv{font-size:11.5px;color:var(--dim)}
.tag{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;
  font-size:11px;font-weight:600;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.12)}
.tag.g{color:#8ff0cd;background:rgba(52,211,153,.12);border-color:rgba(52,211,153,.30)}
.tag.w{color:#ffd79a;background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.30)}
.money3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:13px}
.mcell{background:rgba(0,0,0,.24);border:1px solid var(--line);border-radius:14px;padding:9px;text-align:center}
.mcell .t{font-size:10.5px;color:var(--mut);margin-bottom:4px}
.mcell .nb{width:100%;justify-content:center;font-size:14px;padding:4px 6px}

/* ---------- کارت ---------- */
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r2);
  padding:15px;margin-top:13px;box-shadow:0 10px 26px rgba(0,0,0,.26)}
.card h3{margin:0 0 11px;font-size:14.5px;font-weight:700;display:flex;align-items:center;gap:7px}
.sub{color:var(--dim);font-size:12.5px}
.hr{height:1px;background:var(--line);margin:12px 0;border:0}

/* ---------- فیلدها ---------- */
.fld{margin-bottom:12px}
.fld label{display:block;font-size:12px;color:var(--dim);margin-bottom:6px;font-weight:600}
.fld input,.fld select{
  width:100%;background:var(--card2);border:1px solid var(--line);border-radius:13px;
  padding:12px 13px;min-height:48px;outline:none;transition:border-color .15s, box-shadow .15s;
}
.fld input:focus,.fld select:focus{border-color:var(--acc);box-shadow:0 0 0 3px color-mix(in srgb,var(--acc) 22%,transparent)}
.fld input.ltr{direction:ltr;text-align:left;font-family:var(--num);font-variant-numeric:tabular-nums;letter-spacing:.4px;font-weight:700}
input[type=number]{-moz-appearance:textfield}
input[type=number]::-webkit-outer-spin-button,input[type=number]::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
.chips{display:flex;gap:7px;flex-wrap:wrap;margin-top:8px}
.chip{padding:6px 12px;border-radius:11px;background:var(--card2);border:1px solid var(--line);
  font-size:12px;font-weight:700;direction:ltr;font-variant-numeric:tabular-nums;cursor:pointer;
  box-shadow:var(--d3a);transition:transform .12s, border-color .15s}
.chip:active{transform:translateY(2px)}
.chip.on{border-color:var(--acc);background:color-mix(in srgb,var(--acc) 18%,transparent);color:#dbe4ff}

/* ---------- دکمه‌های سه‌بعدی ---------- */
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:7px;
  padding:13px 18px;min-height:50px;border:0;border-radius:15px;cursor:pointer;
  font-weight:700;font-size:14px;color:#fff;
  background:linear-gradient(180deg, color-mix(in srgb,var(--acc) 88%,#fff) 0%, var(--acc) 55%, color-mix(in srgb,var(--acc) 78%,#000) 100%);
  box-shadow:var(--d3);transition:transform .12s ease, box-shadow .16s ease, filter .16s ease;
}
.btn:active{transform:translateY(3px);box-shadow:var(--d3a)}
.btn.w{width:100%}
.btn.sm{padding:9px 13px;min-height:40px;font-size:12.5px;border-radius:12px}
.btn.gh{background:linear-gradient(180deg,#232937,#1a1f2b);color:var(--tx);border:1px solid var(--line)}
.btn.ok{background:linear-gradient(180deg,#5ff0c0,#22c58c 60%,#12996b);color:#04281c}
.btn.dn{background:linear-gradient(180deg,#ff9f9f,#ef5f5f 60%,#c53d3d);color:#31090b}
.btn[disabled]{opacity:.5;pointer-events:none;transform:none}
.brow{display:flex;gap:9px;flex-wrap:wrap}
.brow .btn{flex:1 1 auto}

/* ---------- فاکتور ---------- */
.inv{background:var(--card2);border:1px dashed var(--line);border-radius:15px;padding:12px;margin-top:4px}
.irow{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:5px 0;font-size:13px}
.irow .k{color:var(--dim)}
.irow.tot{border-top:1px solid var(--line);margin-top:6px;padding-top:9px;font-weight:800}

/* ---------- فهرست سرویس ---------- */
.svc{background:var(--card2);border:1px solid var(--line);border-radius:15px;padding:12px;margin-bottom:9px}
.svc .h{display:flex;align-items:center;justify-content:space-between;gap:9px;margin-bottom:8px}
.svc .nm{font-weight:700;font-size:13.5px;direction:ltr;text-align:left;word-break:break-all}
.bar{height:7px;border-radius:99px;background:rgba(255,255,255,.08);overflow:hidden;margin:8px 0 7px}
.bar i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,var(--acc),#8b5cf6)}
.meta{display:flex;gap:7px;flex-wrap:wrap}
.copy{display:flex;gap:7px;align-items:center;background:#11151d;border:1px solid var(--line);
  border-radius:12px;padding:9px 11px;margin-top:9px}
.copy code{flex:1;min-width:0;direction:ltr;text-align:left;font-size:11.5px;color:var(--dim);
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:var(--num)}

/* ---------- نوار تب پایین ---------- */
.tabs{
  position:fixed;inset-inline:0;bottom:0;z-index:40;display:grid;grid-template-columns:repeat(4,1fr);
  gap:6px;padding:8px 10px calc(8px + var(--safe));
  background:rgba(14,16,21,.92);backdrop-filter:blur(14px);border-top:1px solid var(--line);
}
.tab{display:flex;flex-direction:column;align-items:center;gap:2px;padding:7px 4px;border:0;
  background:transparent;color:var(--mut);font-size:10.5px;font-weight:600;border-radius:13px;cursor:pointer}
.tab .i{font-size:18px;line-height:1}
.tab.on{color:var(--tx);background:color-mix(in srgb,var(--acc) 18%,transparent);
  box-shadow:0 1px 0 rgba(255,255,255,.10) inset}

/* ---------- متفرقه ---------- */
.empty{text-align:center;padding:28px 12px;color:var(--mut)}
.empty .ic{font-size:36px;display:block;margin-bottom:8px;opacity:.7}
.al{border-radius:14px;padding:11px 13px;font-size:12.5px;margin-top:10px}
.al.err{background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.32);color:#ffc9c9}
.al.ok{background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.32);color:#a8f0d6}
.al.warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.32);color:#ffdca8}
.toast{position:fixed;inset-inline:0;bottom:calc(96px + var(--safe));display:flex;justify-content:center;
  z-index:70;pointer-events:none;opacity:0;transition:opacity .2s}
.toast.on{opacity:1}
.toast span{background:#222836;border:1px solid var(--line);border-radius:13px;padding:9px 15px;
  font-size:12.5px;box-shadow:0 10px 26px rgba(0,0,0,.5)}
.sk{height:76px;border-radius:var(--r2);background:linear-gradient(90deg,#161a23,#1d2231,#161a23);
  background-size:200% 100%;animation:sh 1.2s infinite;margin-top:13px}
@keyframes sh{0%{background-position:200% 0}100%{background-position:-200% 0}}
/* ===== نوار بالا و منوی کشویی همبرگری ===== */
.tb{position:sticky;top:0;z-index:60;display:flex;align-items:center;gap:9px;
  padding:calc(9px + env(safe-area-inset-top)) 13px 9px;
  background:rgba(14,16,21,.88);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);
  border-bottom:1px solid var(--line)}
.hb{width:42px;height:42px;flex:0 0 42px;border:1px solid var(--line);border-radius:13px;
  background:var(--card);display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:4px;cursor:pointer;box-shadow:var(--d3);padding:0}
.hb span{display:block;width:17px;height:2px;border-radius:2px;background:var(--tx);
  transition:transform .22s,opacity .22s}
.hb.on span:nth-child(1){transform:translateY(6px) rotate(45deg)}
.hb.on span:nth-child(2){opacity:0}
.hb.on span:nth-child(3){transform:translateY(-6px) rotate(-45deg)}
.tbt{flex:1;min-width:0;font-weight:700;font-size:14px;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap}
.tbb{border:1px solid var(--line);border-radius:13px;background:var(--card);color:var(--tx);
  padding:9px 11px;font:inherit;font-size:12px;font-weight:700;white-space:nowrap;
  cursor:pointer;box-shadow:var(--d3)}
.drawer{position:fixed;top:0;bottom:0;inset-inline-start:0;width:min(84vw,300px);z-index:90;
  background:linear-gradient(180deg,#181d28,#12151d);border-inline-end:1px solid var(--line);
  padding:calc(15px + env(safe-area-inset-top)) 12px calc(20px + var(--safe));
  overflow-y:auto;-webkit-overflow-scrolling:touch;
  transform:translateX(102%);transition:transform .26s cubic-bezier(.2,.9,.25,1);
  box-shadow:22px 0 48px -18px rgba(0,0,0,.85)}
.drawer.on{transform:translateX(0)}
.dh{display:flex;align-items:center;margin-bottom:10px}
.dt{font-weight:700;font-size:15px}
.dx{margin-inline-start:auto;width:33px;height:33px;border:1px solid var(--line);border-radius:11px;
  background:var(--card2);color:var(--dim);font:inherit;font-size:13px;cursor:pointer;padding:0}
.dg{font-size:10.5px;color:var(--mut);letter-spacing:.3px;margin:14px 5px 6px}
.nvi{display:flex;align-items:center;gap:10px;width:100%;min-height:46px;
  border:1px solid transparent;border-radius:13px;padding:11px 12px;margin-bottom:4px;
  background:transparent;color:var(--dim);font:inherit;font-size:13px;text-align:start;cursor:pointer}
.nvi .i{font-size:17px;line-height:1;width:22px;text-align:center;flex:0 0 22px}
.nvi.on{color:var(--tx);font-weight:700;background:rgba(108,140,255,.16);
  border-color:rgba(108,140,255,.4)}
.nvi:active{transform:scale(.985)}
.scrim{position:fixed;top:0;right:0;bottom:0;left:0;z-index:85;background:rgba(4,6,10,.64);
  opacity:0;pointer-events:none;transition:opacity .22s}
.scrim.on{opacity:1;pointer-events:auto}

/* ===== کیف پول و ربات نمایندگی ===== */
.wh{font-weight:700;font-size:15.5px;margin-bottom:5px}
.wrow{display:flex;align-items:center;justify-content:space-between;gap:10px;
  padding:9px 0;border-bottom:1px dashed rgba(255,255,255,.07);font-size:13px}
.wrow:last-child{border-bottom:0}
.wrow.tot{border-top:1px solid var(--line);border-bottom:0;margin-top:5px;padding-top:11px}
.wk{color:var(--mut);font-size:12px}
.wv{font-weight:700;overflow-wrap:anywhere;text-align:end}
.wv.g{color:var(--ok)}.wv.r{color:var(--err)}.wv.b{color:var(--acc)}
.wlbl{font-size:11.5px;color:var(--mut);margin:15px 2px 7px}
.wq{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px}
.wqb{border:1px solid var(--line);border-radius:12px;background:var(--card2);color:var(--dim);
  padding:11px 5px;font:inherit;font-size:12px;font-weight:700;cursor:pointer;min-height:44px}
.wqb.on{color:#fff;background:linear-gradient(180deg,var(--acc),#3f63e0);border-color:transparent;
  box-shadow:var(--d3a)}
.pmc{display:flex;align-items:center;gap:11px;width:100%;text-align:start;margin-bottom:8px;
  border:1px solid var(--line);border-radius:15px;background:var(--card2);color:var(--tx);
  padding:12px;font:inherit;cursor:pointer;min-height:60px}
.pmc.on{border-color:rgba(108,140,255,.55);background:rgba(108,140,255,.13);box-shadow:var(--d3a)}
.pmc .pi{font-size:21px;flex:0 0 34px;text-align:center}
.pmc .pt{display:flex;flex-direction:column;min-width:0}
.pmc .pt b{font-size:13.5px}
.pmc .pt i{font-style:normal;font-size:11px;color:var(--mut);margin-top:2px;overflow-wrap:anywhere}
.wbox{background:#0d1017;border:1px dashed #2f374a;border-radius:13px;padding:11px;
  margin:6px 0 8px;font-size:11.5px;direction:ltr;text-align:left;overflow-wrap:anywhere;word-break:break-all}
.wbtns{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.wbtns .btn{flex:1 1 auto;min-width:140px}
.wimg{width:100%;border-radius:14px;margin-top:10px;display:block}
.whr{height:1px;background:var(--line);margin:15px 0}
.wfeat{margin:12px 0 4px}
.wf{display:flex;align-items:flex-start;gap:10px;padding:9px 0;
  border-bottom:1px dashed rgba(255,255,255,.07)}
.wf:last-child{border-bottom:0}
.wfi{font-size:18px;flex:0 0 26px;text-align:center;line-height:1.5}
.wft{display:flex;flex-direction:column;min-width:0}
.wft b{font-size:13px}
.wft i{font-style:normal;font-size:11.5px;color:var(--mut);margin-top:2px;overflow-wrap:anywhere}

/* ===== اصلاح‌های اندروید و نمایشگرهای کوچک ===== */
.wrap{padding-bottom:calc(26px + var(--safe))}
input,select,textarea{font-family:inherit;font-size:16px;max-width:100%}
.brow{flex-wrap:wrap}
.copy code{overflow-wrap:anywhere;word-break:break-all}
.svc,.card{overflow-wrap:anywhere}
@media (max-width:360px){.wq{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (prefers-reduced-motion:reduce){.drawer,.scrim,.hb span{transition:none}}
.hide{display:none !important}
/* ===== 3D depth + KPI layer (0.0.1 beta) ===== */
:root{
	--dp1:0 1px 2px rgba(3,7,18,.20), 0 2px 6px rgba(3,7,18,.14);
	--dp2:0 8px 20px rgba(3,7,18,.26), 0 2px 6px rgba(3,7,18,.16);
	--dp3:0 20px 44px rgba(3,7,18,.34), 0 8px 16px rgba(3,7,18,.20);
	--gloss:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,0) 58%);
	--ez:cubic-bezier(.2,.8,.2,1);
}
*{-webkit-tap-highlight-color:transparent}
html{-webkit-text-size-adjust:100%;text-size-adjust:100%}
body{overscroll-behavior-y:contain}
.card{position:relative;box-shadow:var(--dp2);transform:translateZ(0);transition:transform .28s var(--ez), box-shadow .28s var(--ez)}
.card::before{content:'';position:absolute;inset:0;border-radius:inherit;background:var(--gloss);pointer-events:none}
.card:hover{transform:translateY(-2px);box-shadow:var(--dp3)}
.btn{box-shadow:var(--dp1);transition:transform .16s var(--ez), box-shadow .16s var(--ez)}
.btn:hover{box-shadow:var(--dp2);transform:translateY(-1px)}
.btn:active{transform:translateY(1px) scale(.995);box-shadow:var(--dp1)}
.sec-h{margin:12px 0 8px;font-size:13px;font-weight:800;opacity:.9}
.kpi-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:10px 0 4px}
.kpi{position:relative;overflow:hidden;padding:12px 12px 13px;border-radius:16px;background:linear-gradient(160deg, rgba(255,255,255,.10), rgba(255,255,255,.03));box-shadow:var(--dp2), inset 0 1px 0 rgba(255,255,255,.16)}
.kpi::after{content:'';position:absolute;inset:0;background:var(--gloss);pointer-events:none}
.kpi.wide{grid-column:1 / -1}
.kpi .kv{font-size:20px;font-weight:800;letter-spacing:.2px;direction:ltr;text-align:right}
.kpi .kl{font-size:11.5px;opacity:.78;margin-top:3px}
.kpi .kl.mt6{margin-top:7px}
.kpi.k1{border:1px solid rgba(108,140,255,.30)}
.kpi.k2{border:1px solid rgba(34,197,94,.30)}
.kpi.k3{border:1px solid rgba(248,113,113,.28)}
.kpi.k4{border:1px solid rgba(250,204,21,.28)}
.kpi.k5{border:1px solid rgba(34,211,238,.28)}
.pb{height:10px;border-radius:99px;background:rgba(0,0,0,.28);overflow:hidden;box-shadow:inset 0 1px 3px rgba(0,0,0,.45)}
.pb > i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#6C8CFF,#22d3ee);box-shadow:0 0 10px rgba(108,140,255,.55)}
@media (max-width:380px){ .kpi .kv{font-size:18px} }

/* =================================================================
   UI 3D v2 — طراحی سه بعدی پنل نمایندگی + اسکرول روان موبایل
   ================================================================= */
:root{
  --gl:linear-gradient(180deg,rgba(255,255,255,.18),rgba(255,255,255,0) 56%);
  --ez3:cubic-bezier(.2,.9,.25,1);
  --face:0 1px 0 rgba(255,255,255,.20) inset,0 -3px 0 rgba(0,0,0,.34) inset;
}

/* ---------- اسکرول درست در گوشی ---------- */
html{height:auto;-webkit-text-size-adjust:100%}
html,body{overflow-x:hidden}
body{
  min-height:var(--vh,100dvh);
  overscroll-behavior-y:contain;
  -webkit-overflow-scrolling:touch;
  padding-bottom:calc(124px + var(--safe));
}
.wrap{
  padding-bottom:calc(34px + var(--safe));
  perspective:1500px;perspective-origin:50% 0;
}
body:has(.drawer.on),body.nav-lock{overflow:hidden}
.drawer{overscroll-behavior:contain}
.tb{transform:translateZ(0)}

/*
 * گوشی و تبلت: پرسپکتیو و بلور از مسیر اسکرول برداشته می شود
 * تا صفحه روان بالا و پایین برود. حالت سه بعدی روی هدر و کارت
 * اصلی باقی می ماند.
 */
@media (hover:none) and (pointer:coarse){
  .wrap{perspective:none;perspective-origin:50% 50%}
  .hero{perspective:1200px}
  .tb{backdrop-filter:none;-webkit-backdrop-filter:none;background:#0e1015}
  .tabs{backdrop-filter:none;-webkit-backdrop-filter:none;background:#12151d}
  .card,.svc,.kpi,.pmc,.wbox,.money3{will-change:auto}
  .svc,.kpi{transition:none}
  .card:active,.svc:active,.kpi:active{transform:none}
}
@media (prefers-reduced-motion:reduce){
  .wrap{perspective:none}
}

/* ---------- کارت های سه بعدی ---------- */
.card{
  border-radius:var(--r2);
  background:linear-gradient(168deg,rgba(255,255,255,.075),rgba(255,255,255,.015) 48%),var(--card);
  border:1px solid rgba(255,255,255,.07);
  box-shadow:0 26px 44px -26px rgba(0,0,0,.92),0 10px 20px -12px rgba(0,0,0,.6),
             inset 0 1px 0 rgba(255,255,255,.15),inset 0 -2px 0 rgba(0,0,0,.34);
  transition:transform .3s var(--ez3),box-shadow .3s var(--ez3);
}
.svc,.inv,.wbox,.pmc{
  box-shadow:0 16px 28px -22px rgba(0,0,0,.85),inset 0 1px 0 rgba(255,255,255,.09),
             inset 0 -2px 0 rgba(0,0,0,.30);
  transition:transform .22s var(--ez3),box-shadow .22s var(--ez3);
}
@media (hover:hover) and (pointer:fine){
  .card:hover{transform:translateY(-3px) rotateX(1.2deg)}
  .svc:hover{transform:translateY(-2px) scale(1.004)}
}

/* ---------- دکمه های سه بعدی واقعی ---------- */
.btn{
  position:relative;isolation:isolate;border-radius:16px;
  box-shadow:var(--face),0 12px 22px -12px rgba(0,0,0,.75),0 5px 0 rgba(0,0,0,.36);
  transition:transform .13s var(--ez3),box-shadow .18s var(--ez3),filter .18s
}
.btn::after{content:'';position:absolute;inset:0;border-radius:inherit;
  background:var(--gl);pointer-events:none;z-index:-1}
.btn:active{transform:translateY(5px);
  box-shadow:0 1px 0 rgba(255,255,255,.12) inset,0 2px 7px rgba(0,0,0,.55)}
@media (hover:hover) and (pointer:fine){
  .btn:hover{filter:brightness(1.07);transform:translateY(-2px);
    box-shadow:var(--face),0 18px 28px -14px rgba(0,0,0,.8),0 7px 0 rgba(0,0,0,.36)}
}
.hb,.tbb,.dx{box-shadow:var(--face),0 8px 16px -10px rgba(0,0,0,.7),0 3px 0 rgba(0,0,0,.34);
  transition:transform .14s var(--ez3),box-shadow .18s var(--ez3)}
.hb:active,.tbb:active,.dx:active{transform:translateY(3px);
  box-shadow:0 1px 0 rgba(255,255,255,.10) inset,0 2px 6px rgba(0,0,0,.5)}

/* ---------- نوار تب پایین: پیل شناور سه بعدی ---------- */
.tabs{
  inset-inline:10px;bottom:calc(10px + var(--safe));
  gap:6px;padding:8px;border-radius:26px;border:1px solid rgba(255,255,255,.11);
  background:linear-gradient(180deg,rgba(34,40,55,.96),rgba(15,18,25,.96));
  box-shadow:0 26px 46px -22px rgba(0,0,0,.95),inset 0 1px 0 rgba(255,255,255,.14),
             inset 0 -3px 0 rgba(0,0,0,.42);
}
.tab{border-radius:18px;transition:transform .16s var(--ez3),background .22s,color .22s,box-shadow .22s}
.tab .i{filter:drop-shadow(0 2px 3px rgba(0,0,0,.55));transition:transform .18s var(--ez3)}
.tab:active{transform:translateY(3px)}
.tab.on{
  color:#fff;
  background:linear-gradient(180deg,color-mix(in srgb,var(--acc) 62%,#fff 10%),
             color-mix(in srgb,var(--acc) 82%,#000 10%));
  box-shadow:0 1px 0 rgba(255,255,255,.30) inset,0 -3px 0 rgba(0,0,0,.34) inset,
             0 10px 18px -10px rgba(0,0,0,.85);
}
.tab.on .i{transform:translateY(-2px) scale(1.08)}

/* ---------- منوی کشویی سه بعدی ---------- */
.drawer{
  width:min(86vw,322px);border-radius:0 28px 28px 0;
  background:linear-gradient(170deg,#1f2533,#111419 62%);
  transform:translateX(104%) rotateY(15deg) scale(.97);
  transform-origin:100% 50%;
  transition:transform .36s var(--ez3);
  box-shadow:30px 0 64px -22px rgba(0,0,0,.95),inset 0 1px 0 rgba(255,255,255,.10);
}
.drawer.on{transform:translateX(0) rotateY(0) scale(1)}
.nvi{
  background:linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.015));
  border:1px solid rgba(255,255,255,.07);
  box-shadow:0 8px 14px -12px rgba(0,0,0,.8),inset 0 1px 0 rgba(255,255,255,.08);
  transition:transform .16s var(--ez3),box-shadow .18s var(--ez3),background .2s
}
.nvi:active{transform:translateY(2px) scale(.99)}
.nvi.on{
  background:linear-gradient(180deg,color-mix(in srgb,var(--acc) 34%,transparent),
             color-mix(in srgb,var(--acc) 16%,transparent));
  box-shadow:0 1px 0 rgba(255,255,255,.22) inset,0 -2px 0 rgba(0,0,0,.28) inset,
             0 10px 18px -12px rgba(0,0,0,.8);
}
@media (hover:hover) and (pointer:fine){.nvi:hover{transform:translateX(-3px)}}

/* ---------- چیپ، ترازو و فیلد ---------- */
.chip,.wqb{box-shadow:inset 0 1px 0 rgba(255,255,255,.10),0 6px 12px -10px rgba(0,0,0,.8);
  transition:transform .14s var(--ez3),box-shadow .18s}
.chip:active,.wqb:active{transform:translateY(2px)}
.chip.on,.wqb.on{box-shadow:0 1px 0 rgba(255,255,255,.24) inset,0 -2px 0 rgba(0,0,0,.30) inset}
.fld input,.fld select,.fld textarea{box-shadow:inset 0 2px 5px rgba(0,0,0,.35)}
.bar,.pb{box-shadow:inset 0 2px 4px rgba(0,0,0,.5)}
.kpi{transition:transform .26s var(--ez3),box-shadow .26s var(--ez3)}
@media (hover:hover) and (pointer:fine){.kpi:hover{transform:translateY(-3px) rotateX(1.4deg)}}

/* ---------- مناسب پیسی ---------- */
@media (min-width:900px){
  .wrap{max-width:1060px;padding:20px 24px 0}
  .tb{padding-inline:24px}
  .kpi-grid{grid-template-columns:repeat(4,minmax(0,1fr))}
  .brow .btn{flex:0 1 auto;min-width:186px}
  .tabs{inset-inline:auto;left:50%;transform:translateX(-50%);
    width:min(760px,94vw);bottom:calc(16px + var(--safe))}
  .drawer{width:330px}
  .svc{padding:14px}
}
@media (min-width:1280px){
  .wrap{max-width:1180px}
  .card{border-radius:26px}
}

/* ---------- گوشی کوچک ---------- */
@media (max-width:400px){
  .wrap{padding:12px 11px 0}
  .btn{padding:12px 14px;min-height:48px}
  .tabs{inset-inline:7px;padding:7px;border-radius:22px}
  .tab{font-size:10px}
}
@media (prefers-reduced-motion:reduce){
  .card,.svc,.btn,.tab,.nvi,.drawer,.kpi{transition:none !important;transform:none !important}
}

/* =================================================================
   اسکرول موبایل — ظرف اسکرول اختصاصی (0.0.1 beta)

   پیش از این خودِ بدنهٔ صفحه اسکرول می‌شد و وب‌ویو تلگرام درگ عمودی
   تک‌انگشتی را برای ژست بستن مینی‌اپ برمی‌داشت؛ نتیجه این بود که صفحه
   ثابت می‌ماند و فقط با دو انگشت شانسی حرکت می‌کرد. الان بدنه ثابت است
   و همهٔ محتوا داخل #scroller بالا و پایین می‌رود.
   ================================================================= */
html,body{height:100%;max-height:100%;overflow:hidden}
body{padding-bottom:0;min-height:100%}
#scroller{
  position:relative;
  height:100%;
  height:var(--vh,100%);
  overflow-y:auto;
  overflow-x:hidden;
  -webkit-overflow-scrolling:touch;
  overscroll-behavior-y:contain;
  touch-action:pan-y;
  padding-bottom:calc(34px + var(--safe));
}
#scroller>.wrap{touch-action:pan-y}
body.nav-lock #scroller{overflow:hidden}
@media (hover:none) and (pointer:coarse){
  #scroller{scroll-behavior:auto}
  .wrap{perspective:none;perspective-origin:50% 50%}
  .card,.svc,.kpi{transform:none}
}
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
</style>
<style id="rsBotCss">
/* ربات اختصاصی — شبکهٔ دکمه‌های مدیریت */
.bgrid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px}
.bgrid .btn{width:100%;justify-content:center}
@media (max-width:430px){.bgrid{grid-template-columns:1fr}}
</style>
<style>
/* =======================================================
   Reseller Panel Polish v6 — depth, gradient, 3D
   ======================================================= */

/* ---------- هدر نماینده ---------- */
.hero.v6{
  position:relative;overflow:hidden;
  border-radius:24px;padding:16px 15px 14px;
  background:radial-gradient(130% 140% at 8% 0%,#242c3d 0%,#1a2030 48%,#131824 100%);
  border:1px solid rgba(255,255,255,.075);
  box-shadow:0 24px 48px -26px rgba(0,0,0,.9),inset 0 1px 0 rgba(255,255,255,.1);
}
.hero.v6>.glo{
  position:absolute;inset:-40% -10% auto -10%;height:80%;pointer-events:none;
  background:radial-gradient(58% 100% at 50% 0%,rgba(91,140,255,.34),transparent 72%);
  filter:blur(22px);
}
.hero.v6 .top,.hero.v6 .money3,.hero.v6 .hstat,.hero.v6 .qb,.hero.v6 .hdom{position:relative;z-index:2;}
.hero.v6 .ava{
  box-shadow:0 10px 22px -12px rgba(0,0,0,.95),inset 0 1px 0 rgba(255,255,255,.22);
}

/* ---------- نوار آمار ---------- */
.hstat{display:grid;grid-template-columns:repeat(4,1fr);gap:7px;margin-top:11px;}
.hs{
  position:relative;overflow:hidden;border-radius:14px;padding:9px 6px 8px;text-align:center;
  background:linear-gradient(180deg,rgba(255,255,255,.07),rgba(255,255,255,.02));
  border:1px solid rgba(255,255,255,.075);
  box-shadow:0 9px 18px -14px rgba(0,0,0,.95),inset 0 1px 0 rgba(255,255,255,.09);
}
.hs::after{content:'';position:absolute;inset:0 0 auto 0;height:1px;
  background:linear-gradient(90deg,transparent,rgba(255,255,255,.26),transparent);}
.hs .i{display:block;font-size:13px;margin-bottom:3px;}
.hs .l{display:block;font-size:9.5px;color:#8794a9;margin-bottom:2px;}
.hs .v{display:block;font-size:13px;font-weight:900;color:#eef3fd;}
.hs.g .v{color:#5ee2ab;}
.hs.r .v{color:#ff9b9b;}

/* ---------- نوار سقف ---------- */
.qb{margin-top:11px;}
.qh{display:flex;justify-content:space-between;align-items:center;font-size:11px;color:#96a2b6;margin-bottom:5px;}
.qh b{color:#dfe7f5;font-weight:800;}
.qt{
  position:relative;height:11px;border-radius:999px;overflow:hidden;
  background:linear-gradient(180deg,rgba(0,0,0,.5),rgba(255,255,255,.03));
  border:1px solid rgba(255,255,255,.07);
  box-shadow:inset 0 2px 5px rgba(0,0,0,.6);
}
.qt i{
  position:absolute;inset:1px auto 1px 1px;border-radius:999px;
  background:linear-gradient(180deg,#7aa4ff,#3f6ee0);
  box-shadow:0 2px 7px -2px rgba(91,140,255,.9),inset 0 1px 0 rgba(255,255,255,.4);
  transition:width .8s cubic-bezier(.22,.9,.28,1);
}
.qt.wr i{background:linear-gradient(180deg,#ffc061,#e08b12);}
.qt.er i{background:linear-gradient(180deg,#ff9b9b,#e04b4b);}

/* ---------- نوار دامنه در هدر ---------- */
.hdom{
  display:flex;align-items:center;gap:8px;margin-top:11px;padding:9px 11px;border-radius:14px;cursor:pointer;
  background:linear-gradient(180deg,rgba(47,212,143,.14),rgba(47,212,143,.04));
  border:1px solid rgba(47,212,143,.26);
}
.hdom.warn{background:linear-gradient(180deg,rgba(245,158,11,.15),rgba(245,158,11,.04));border-color:rgba(245,158,11,.3);}
.hdom .i{font-size:14px;flex:0 0 auto;}
.hdom .d{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11.5px;color:#e3eaf7;}
.hdom .bg{
  flex:0 0 auto;font-size:9.5px;font-weight:800;padding:4px 8px;border-radius:999px;
  background:rgba(255,255,255,.09);color:#dbe4f5;
}
.hdom .bg.ok{background:linear-gradient(180deg,#2fd48f,#18a86e);color:#04220f;}

/* ---------- کارت‌های پنل ---------- */
.card.rsc{
  position:relative;overflow:hidden;
  border-radius:20px;
  background:linear-gradient(180deg,#1a2029,#151a23);
  border:1px solid rgba(255,255,255,.07);
  box-shadow:0 16px 34px -24px rgba(0,0,0,.9),inset 0 1px 0 rgba(255,255,255,.06);
}
.card.rsc::before{content:'';position:absolute;inset:0 0 auto 0;height:2px;
  background:linear-gradient(90deg,transparent,rgba(91,140,255,.75),rgba(139,92,246,.55),transparent);}
.card.rsc.acc::before{background:linear-gradient(90deg,transparent,rgba(47,212,143,.85),rgba(38,211,232,.6),transparent);}
.card.rsc h3{display:flex;align-items:center;gap:7px;}
.card.rsc .sub.pre{white-space:pre-wrap;line-height:1.9;}
.card.rsc code{
  font-family:ui-monospace,Menlo,Consolas,monospace;direction:ltr;display:inline-block;
  font-size:11px;padding:2px 6px;border-radius:7px;background:rgba(255,255,255,.09);color:#a9c4ff;
}

/* ---------- پیش‌نمایش دامنه ---------- */
.dompv{
  position:relative;overflow:hidden;
  margin:11px 0 14px;padding:13px 13px 12px;border-radius:17px;
  background:radial-gradient(120% 140% at 10% 0%,rgba(91,140,255,.2),rgba(255,255,255,.03) 62%);
  border:1px solid rgba(91,140,255,.28);
  box-shadow:0 14px 28px -20px rgba(0,0,0,.9),inset 0 1px 0 rgba(255,255,255,.1);
}
.dompv.ok{
  background:radial-gradient(120% 140% at 10% 0%,rgba(47,212,143,.2),rgba(255,255,255,.03) 62%);
  border-color:rgba(47,212,143,.32);
}
.dompv .l{display:block;font-size:10px;color:#8b97ac;margin-bottom:5px;}
.dompv .v{display:block;font-size:14px;font-weight:800;color:#f0f5ff;word-break:break-all;margin-bottom:6px;}
.dompv .s{display:block;font-size:10.5px;color:#a7b3c7;line-height:1.7;}

/* ---------- کادرها ---------- */
.hnt{font-size:10.5px;color:#7f8b9f;margin-top:5px;line-height:1.8;}
.dg2{font-size:11px;font-weight:800;color:#9aa6bb;margin:3px 0 9px;}

/* ---------- گام‌ها ---------- */
.stp{display:flex;gap:9px;align-items:flex-start;margin-bottom:9px;}
.stp b{
  flex:0 0 auto;width:23px;height:23px;border-radius:9px;display:flex;align-items:center;justify-content:center;
  font-size:11px;font-weight:900;color:#0b1220;
  background:linear-gradient(180deg,#9fbcff,#5b8cff);
  box-shadow:0 6px 13px -8px rgba(91,140,255,.95),inset 0 1px 0 rgba(255,255,255,.45);
}
.stp span{flex:1;font-size:11.5px;color:#b8c3d5;line-height:1.85;}

/* ---------- دسترسی‌ها ---------- */
.capg{display:grid;grid-template-columns:repeat(2,1fr);gap:7px;margin:4px 0 10px;}
.cp{
  display:flex;align-items:center;gap:6px;padding:9px 10px;border-radius:13px;
  background:linear-gradient(180deg,rgba(255,255,255,.055),rgba(255,255,255,.015));
  border:1px solid rgba(255,255,255,.07);
  box-shadow:inset 0 1px 0 rgba(255,255,255,.07);
}
.cp .i{font-size:13px;flex:0 0 auto;}
.cp .l{flex:1;min-width:0;font-size:10.5px;color:#c3cddc;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.cp .s{flex:0 0 auto;font-size:11px;font-weight:900;}
.cp.on{border-color:rgba(47,212,143,.3);}
.cp.on .s{color:#5ee2ab;}
.cp.off{opacity:.6;}
.cp.off .s{color:#ff9b9b;}

/* ---------- سطل زباله ---------- */
.card.rsc.trow{margin-top:9px;}
.card.rsc.trow .th{display:flex;align-items:center;gap:8px;margin-bottom:9px;}
.card.rsc.trow .th .nm{
  flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  font-size:11.5px;color:#dbe4f5;padding:5px 9px;border-radius:10px;
  background:rgba(255,255,255,.055);border:1px solid rgba(255,255,255,.07);
}
.card.rsc.trow .th .tg{
  flex:0 0 auto;font-size:9.5px;font-weight:800;padding:4px 8px;border-radius:999px;
  background:linear-gradient(180deg,rgba(245,158,11,.24),rgba(245,158,11,.08));
  color:#ffcc7a;border:1px solid rgba(245,158,11,.3);
}

/* ---------- منوی کشویی ---------- */
.drawer .nvi{
  transition:transform .14s ease,background .2s ease,box-shadow .2s ease;
}
.drawer .nvi:active{transform:translateX(-2px) scale(.985);}
.drawer .nvi.on{
  background:linear-gradient(90deg,rgba(91,140,255,.24),rgba(91,140,255,.05));
  box-shadow:inset 2px 0 0 #5b8cff,0 8px 18px -14px rgba(0,0,0,.95);
}

/* ---------- دکمه‌ها ---------- */
.btn{transition:transform .12s ease,box-shadow .2s ease,filter .2s ease;}
.btn:active{transform:translateY(2px) scale(.985);}
.btn.w{box-shadow:0 12px 24px -14px rgba(0,0,0,.95),inset 0 1px 0 rgba(255,255,255,.2);}

.spin{
  display:inline-block;width:12px;height:12px;border-radius:50%;vertical-align:-1px;
  border:2px solid rgba(255,255,255,.28);border-top-color:#fff;
  animation:rsSpin .7s linear infinite;
}
@keyframes rsSpin{to{transform:rotate(360deg)}}

@media (max-width:360px){
  .hstat{grid-template-columns:repeat(2,1fr);}
  .capg{grid-template-columns:1fr;}
}

@media (prefers-reduced-motion:reduce){
  .qt i,.btn,.drawer .nvi{transition:none!important;}
}

/* ---------- کشوی پنل نمایندگی — بازطراحی ---------- */
.drawer{
  padding-inline:11px;
  background:
    radial-gradient(120% 50% at 100% 0,rgba(108,140,255,.20),transparent 60%),
    linear-gradient(170deg,#1c2231,#0f1218 66%);
}
.drawer::-webkit-scrollbar{width:4px}
.drawer::-webkit-scrollbar-thumb{background:rgba(108,140,255,.35);border-radius:9px}

.dwh{
  position:sticky;top:calc(-1 * (15px + env(safe-area-inset-top)));z-index:5;
  display:flex;align-items:center;gap:10px;
  padding:10px 2px 11px;margin-bottom:11px;
  background:linear-gradient(180deg,rgba(20,25,36,.96),rgba(20,25,36,.82));
  -webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);
  border-bottom:1px solid rgba(255,255,255,.08);
}
.dwlogo{
  width:38px;height:38px;flex:0 0 38px;border-radius:13px;
  display:grid;place-items:center;font-size:18px;
  background:linear-gradient(160deg,var(--acc),#3f63e0);
  box-shadow:0 12px 22px -14px rgba(0,0,0,.95),inset 0 1px 0 rgba(255,255,255,.28);
}
.dwt{display:flex;flex-direction:column;min-width:0;flex:1}
.dwt b{font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dwt i{font-style:normal;font-size:10.5px;color:var(--mut);margin-top:2px;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dx{border-radius:11px;transition:border-color .18s,color .18s}
.dx:active{transform:scale(.94)}

/* کارت کیف پول */
.dwcard{
  border:1px solid rgba(108,140,255,.28);border-radius:17px;
  padding:12px;margin-bottom:13px;cursor:pointer;
  background:linear-gradient(160deg,rgba(108,140,255,.20),rgba(108,140,255,.05));
  box-shadow:inset 0 1px 0 rgba(255,255,255,.12),0 14px 26px -18px rgba(0,0,0,.95);
  transition:transform .16s,box-shadow .2s;
}
.dwcard:active{transform:translateY(2px) scale(.994)}
.dwc-h{display:flex;align-items:center;justify-content:space-between;gap:8px;
  font-size:10.5px;color:#c8d4ec;font-weight:700}
.dwc-h .go{color:var(--acc);font-size:10px}
.dwc-v{font-size:19px;font-weight:900;margin:7px 0 9px;letter-spacing:.2px}
.dwc-r{display:grid;grid-template-columns:1fr 1fr;gap:7px}
.dwc-p{display:flex;flex-direction:column;gap:2px;padding:7px 8px;border-radius:12px;
  background:rgba(9,12,18,.42);border:1px solid rgba(255,255,255,.07)}
.dwc-p b{font-size:12.5px;font-weight:800}
.dwc-p i{font-style:normal;font-size:9.5px;color:var(--mut)}

/* عنوان گروه */
.dg{display:flex;align-items:center;gap:6px;font-size:10px;font-weight:800;
  color:var(--mut);letter-spacing:.3px;margin:15px 4px 7px}
.dg .gi{font-size:11px;opacity:.85}
.dg::after{content:"";flex:1;height:1px;background:linear-gradient(90deg,rgba(255,255,255,.10),transparent)}

/* ردیف‌های کشو */
.drawer .nvi{
  align-items:center;gap:10px;min-height:54px;border-radius:15px;padding:9px 10px;margin-bottom:6px;
}
.drawer .nvi .i{
  flex:0 0 32px;width:32px;height:32px;border-radius:11px;
  display:grid;place-items:center;font-size:16px;
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);
  transition:background .18s,border-color .18s,transform .18s;
}
.drawer .nvi .t{display:flex;flex-direction:column;min-width:0;flex:1;text-align:start}
.drawer .nvi .t b{font-size:12.5px;font-weight:700;line-height:1.5;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.drawer .nvi .t i{font-style:normal;font-size:9.5px;color:var(--mut);line-height:1.5;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.drawer .nvi .ar{flex:0 0 auto;color:var(--mut);font-size:14px;opacity:.55;transition:opacity .18s,transform .18s}
.drawer .nvi .nb{
  flex:0 0 auto;min-width:20px;padding:2px 6px;border-radius:999px;
  background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.09);
  color:#dbe4f5;font-size:9.5px;font-weight:800;text-align:center;
}
.drawer .nvi .nb:empty{display:none}
.drawer .nvi.on .i{
  background:linear-gradient(160deg,var(--acc),#3f63e0);border-color:transparent;
  box-shadow:0 8px 16px -12px rgba(0,0,0,.95),inset 0 1px 0 rgba(255,255,255,.25);
}
.drawer .nvi.on .ar{opacity:1;color:var(--acc);transform:translateX(-2px)}
.drawer .nvi.on .t i{color:#b9c8ea}
.drawer .nvi.on .nb{background:rgba(108,140,255,.26);border-color:rgba(108,140,255,.38);color:#fff}
@media (hover:hover) and (pointer:fine){
  .drawer .nvi:hover .i{background:rgba(255,255,255,.11);transform:scale(1.05)}
  .drawer .nvi:hover .ar{opacity:1;transform:translateX(-2px)}
}

/* پانوی کشو */
.dwft{margin-top:16px;padding-top:12px;border-top:1px solid rgba(255,255,255,.08)}
.dwfb{
  width:100%;min-height:44px;border-radius:14px;cursor:pointer;
  border:1px solid var(--line);background:var(--card2);color:var(--dim);
  font:inherit;font-size:12px;font-weight:700;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.07);transition:transform .14s,color .18s;
}
.dwfb:active{transform:translateY(2px)}
.dwv{margin-top:9px;text-align:center;font-size:9.5px;color:var(--mut);letter-spacing:.4px}

@media (max-width:360px){
  .drawer .nvi{min-height:50px}
  .drawer .nvi .t i{display:none}
}
@media (prefers-reduced-motion:reduce){
  .drawer .nvi .i,.drawer .nvi .ar,.dwcard,.dwfb{transition:none!important;transform:none!important}
}


/* ===== افزودهٔ پنل نمایندگی: دسترسی سریع، فیلتر و خلاصه ===== */
.hqa{display:grid;grid-template-columns:repeat(4,1fr);gap:7px;margin:11px 0 2px;position:relative;z-index:2}
.hqa button{display:flex;flex-direction:column;align-items:center;gap:4px;padding:9px 4px;border:0;cursor:pointer;
  border-radius:14px;font:inherit;font-size:10px;line-height:1.5;color:inherit;
  background:rgba(255,255,255,.10);box-shadow:inset 0 1px 0 rgba(255,255,255,.16),0 4px 10px rgba(0,0,0,.18)}
.hqa button:active{transform:translateY(2px) scale(.97)}
.halert{margin-top:11px;padding:9px 11px;border-radius:13px;font-size:11.5px;line-height:1.8;position:relative;z-index:2;
  background:rgba(250,204,21,.15);color:#fde68a;box-shadow:inset 0 0 0 1px rgba(250,204,21,.28)}
.rss{display:flex;gap:7px;margin:2px 0 11px}
.rss .c{flex:1;text-align:center;padding:9px 3px;border-radius:14px;background:rgba(255,255,255,.05);
  box-shadow:inset 0 1px 0 rgba(255,255,255,.10)}
.rss .c b{display:block;font-size:15px;font-weight:800;direction:ltr}
.rss .c i{display:block;font-style:normal;font-size:9.5px;opacity:.62;margin-top:2px}
.rss .c.g b{color:#6ee7b7}
.rss .c.w b{color:#fde68a}
.rsf{display:flex;gap:6px;overflow-x:auto;padding:0 0 11px;scrollbar-width:none}
.rsf::-webkit-scrollbar{display:none}
.rsf button{flex:0 0 auto;border:0;cursor:pointer;padding:7px 12px;border-radius:999px;font:inherit;font-size:11px;
  white-space:nowrap;color:inherit;background:rgba(255,255,255,.07);box-shadow:inset 0 1px 0 rgba(255,255,255,.12)}
.rsf button.on{background:linear-gradient(160deg,#6C8CFF,#4b6ae0);color:#0B1020;font-weight:800}

/* ===== کارت کانفیگ نماینده — نسخهٔ جدید ===== */
.svc.v2{position:relative;overflow:hidden;padding:12px;border-radius:18px;
  background:linear-gradient(165deg,rgba(255,255,255,.065),rgba(255,255,255,.02));
  box-shadow:inset 0 1px 0 rgba(255,255,255,.10),0 8px 20px rgba(0,0,0,.26)}
.svc.v2::before{content:'';position:absolute;inset-inline:0;top:0;height:3px;
  background:linear-gradient(90deg,#34d399,#22d3ee,#6C8CFF)}
.svc.v2.off::before{background:linear-gradient(90deg,#fbbf24,#f87171)}
.svc.v2.off{opacity:.78}
.s2h{display:flex;align-items:center;gap:8px}
.s2i{width:31px;height:31px;flex:0 0 31px;display:grid;place-items:center;border-radius:11px;font-size:14px;
  background:rgba(0,0,0,.26);box-shadow:inset 0 1px 0 rgba(255,255,255,.10)}
.s2n{flex:1;min-width:0;font-size:13px;font-weight:800;direction:ltr;text-align:right;overflow-wrap:anywhere}
.s2b{position:relative;height:22px;margin-top:11px;border-radius:999px;overflow:hidden;
  background:rgba(0,0,0,.32);box-shadow:inset 0 1px 4px rgba(0,0,0,.45)}
.s2b > i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#34d399,#22d3ee)}
.s2b.w > i{background:linear-gradient(90deg,#fbbf24,#f59e0b)}
.s2b.r > i{background:linear-gradient(90deg,#f87171,#dc2626)}
.s2b > em{position:absolute;inset:0;display:grid;place-items:center;font-style:normal;font-size:10.5px;
  font-weight:800;direction:ltr;text-shadow:0 1px 3px rgba(0,0,0,.65)}
.s2g{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin-top:10px}
.s2c{text-align:center;padding:8px 3px;border-radius:12px;background:rgba(0,0,0,.20);
  box-shadow:inset 0 1px 0 rgba(255,255,255,.07)}
.s2c b{display:block;font-size:11.5px;font-weight:800}
.s2c i{display:block;font-style:normal;font-size:9px;opacity:.60;margin-top:3px}
.s2c.g b{color:#6ee7b7}
.s2l{display:flex;align-items:center;gap:8px;margin-top:9px;padding:8px 9px;border-radius:13px;
  background:rgba(0,0,0,.28);box-shadow:inset 0 1px 0 rgba(255,255,255,.06)}
.s2l .k{font-size:9.5px;opacity:.60;white-space:nowrap}
.s2l code{flex:1;min-width:0;font-size:10px;direction:ltr;word-break:break-all;max-height:32px;overflow:hidden}
.s2a{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:11px}
.s2a .btn{width:100%;justify-content:center}
.s2a .dgr{color:#fca5a5}

/* ===== انبار ملی — سربرگ و کارت بسته ===== */
.skhero{display:flex;gap:7px;margin:10px 0 2px}
.skhero .c{flex:1;text-align:center;padding:10px 4px;border-radius:15px;
  background:linear-gradient(160deg,rgba(108,140,255,.16),rgba(34,211,238,.07));
  box-shadow:inset 0 1px 0 rgba(255,255,255,.12)}
.skhero .c b{display:block;font-size:13.5px;font-weight:900;color:#bfd0ff}
.skhero .c i{display:block;font-style:normal;font-size:9.5px;opacity:.62;margin-top:3px}
.sk.v2{position:relative;overflow:hidden}
.sk.v2::before{content:'';position:absolute;inset-inline:0;top:0;height:3px;
  background:linear-gradient(90deg,#22d3ee,#6C8CFF,#34d399)}
.sk.v2.no{opacity:.66}
.sk.v2.no::before{background:linear-gradient(90deg,#9ca3af,#6b7280)}
.skm{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px}
.skm span{font-size:9.5px;padding:4px 9px;border-radius:999px;background:rgba(255,255,255,.07);
  box-shadow:inset 0 1px 0 rgba(255,255,255,.10)}
.skb{display:flex;align-items:center;gap:8px;margin-top:10px;padding:9px 11px;border-radius:14px;
  background:rgba(52,211,153,.11);box-shadow:inset 0 0 0 1px rgba(52,211,153,.24)}
.skb .t{flex:1;min-width:0;font-size:10.5px;opacity:.82}
.skb .v{font-size:13px;font-weight:900;color:#6ee7b7}
</style>
</head>
<body>

<div id="scroller">

<header class="tb" id="tb">
  <button class="hb" id="navBtn" type="button" aria-label="منو"><span></span><span></span><span></span></button>
  <div class="tbt" id="tbT">پنل نمایندگی</div>
  <button class="tbb" id="tbSync" type="button" title="خواندن مصرف از پنل">🔄</button>
  <button class="tbb" id="tbBal" type="button">👛 —</button>
</header>

<div class="wrap" id="app">
<?php if (!$ready): ?>
  <div class="card"><div class="empty"><span class="ic">⛔</span><?= h($note) ?></div></div>
<?php else: ?>
  <div class="sk"></div><div class="sk"></div>
<?php endif; ?>
</div>

</div><!-- /scroller -->

<div class="toast" id="toast"><span></span></div>

<?php if ($ready): ?>
<div class="drawer" id="drawer">

  <div class="dwh">
    <div class="dwlogo">🏷</div>
    <div class="dwt">
      <b id="dwName">پنل نمایندگی</b>
      <i id="dwSub">سرویس‌ساز اختصاصی شما</i>
    </div>
    <button class="dx" id="navX" type="button" aria-label="بستن">✕</button>
  </div>

  <div class="dwcard" data-tab="wallet">
    <div class="dwc-h"><span>👛 موجودی کیف پول</span><span class="go">شارژ ‹</span></div>
    <div class="dwc-v" id="dwBal">—</div>
    <div class="dwc-r">
      <span class="dwc-p"><b id="dwAvl">—</b><i>قابل مصرف</i></span>
      <span class="dwc-p"><b id="dwSvc">—</b><i>سرویس فعال</i></span>
    </div>
  </div>

  <div class="dg"><span class="gi">🛠</span>ساخت سرویس</div>
  <button class="nvi on" data-tab="plan" type="button">
    <span class="i">🎁</span><span class="t"><b>طرح آماده</b><i>خرید سریع از تعرفهٔ شما</i></span><span class="ar">‹</span>
  </button>
  <button class="nvi" data-tab="custom" type="button">
    <span class="i">⚡</span><span class="t"><b>حجم کاستوم</b><i>حجم و زمان دلخواه</i></span><span class="ar">‹</span>
  </button>
  <button class="nvi" data-tab="svc" type="button">
    <span class="i">🧩</span><span class="t"><b>سرویس‌های باندلی</b><i>چند سرور در یک بسته</i></span><span class="ar">‹</span>
  </button>
  <button class="nvi" data-tab="stock" type="button">
    <span class="i">🏪</span><span class="t"><b>انبار ملی</b><i>کانفیگ آمادهٔ تحویل فوری</i></span><span class="ar">‹</span>
  </button>

  <div class="dg"><span class="gi">📂</span>مدیریت سرویس‌ها</div>
  <button class="nvi" data-tab="list" type="button">
    <span class="i">📦</span><span class="t"><b>کانفیگ‌های من</b><i>تمدید، مصرف و لینک ساب</i></span><span class="nb" id="nbList"></span><span class="ar">‹</span>
  </button>
  <button class="nvi" data-tab="trash" type="button">
    <span class="i">🗑</span><span class="t"><b>سطل زباله</b><i>بازگردانی سرویس حذف‌شده</i></span><span class="ar">‹</span>
  </button>

  <div class="dg"><span class="gi">💰</span>مالی و فروش</div>
  <button class="nvi" data-tab="wallet" type="button">
    <span class="i">💳</span><span class="t"><b>شارژ کیف پول</b><i>کارت به کارت و رمزارز</i></span><span class="ar">‹</span>
  </button>
  <button class="nvi" data-tab="money" type="button">
    <span class="i">📊</span><span class="t"><b>داشبورد فروش</b><i>سود، گردش و مشتریان</i></span><span class="ar">‹</span>
  </button>
  <button class="nvi" data-tab="brand" type="button">
    <span class="i">🌐</span><span class="t"><b>برند و دامنهٔ من</b><i>لینک ساب با نام خودتان</i></span><span class="ar">‹</span>
  </button>

  <div class="dg"><span class="gi">🚀</span>توسعه و راهنما</div>
  <button class="nvi" data-tab="bot" type="button">
    <span class="i">🤖</span><span class="t"><b>ربات نمایندگی</b><i>فروش خودکار با ربات خودتان</i></span><span class="ar">‹</span>
  </button>
  <button class="nvi" data-tab="help" type="button">
    <span class="i">📘</span><span class="t"><b>راهنما</b><i>آموزش گام‌به‌گام پنل</i></span><span class="ar">‹</span>
  </button>

  <div class="dwft">
    <button class="dwfb" type="button" onclick="location.reload()">🔄 تازه‌سازی پنل</button>
    <div class="dwv">SR-BOT<?= defined('APP_VERSION') ? ' • ' . h((string)APP_VERSION) : '' ?></div>
  </div>
</div>
<div class="scrim" id="scrim"></div>

<script>
(function () {
  'use strict';

  var TG = (window.Telegram && window.Telegram.WebApp) ? window.Telegram.WebApp : null;
  if (TG) {
    try { TG.ready(); TG.expand(); } catch (e) {}
    try { TG.setHeaderColor('#0e1015'); TG.setBackgroundColor('#0e1015'); } catch (e) {}
    /* جلوگیری از بسته شدن مینی اپ هنگام اسکرول در گوشی */
    /* ژست عمودی تلگرام بسته می‌شود تا درگ تک‌انگشتی به خود صفحه برسد */
    try { if (typeof TG.disableVerticalSwipes === 'function') TG.disableVerticalSwipes(); } catch (e) {}
    try { TG.isVerticalSwipesEnabled = false; } catch (e) {}
  }

  /* ارتفاع واقعی پنجره تلگرام برای اسکرول درست */
  function syncVh() {
    var h = 0;
    try { h = (TG && (TG.viewportStableHeight || TG.viewportHeight)) || 0; } catch (e) {}
    if (!h) h = window.innerHeight || 0;
    if (h > 0) document.documentElement.style.setProperty('--vh', h + 'px');
  }
  syncVh();
  window.addEventListener('resize', syncVh);
  window.addEventListener('orientationchange', function () { setTimeout(syncVh, 220); });
  try { if (TG && TG.onEvent) TG.onEvent('viewportChanged', syncVh); } catch (e) {}

  window.rsScrollTop = function () {
    try { var sc = document.getElementById('scroller'); if (sc) sc.scrollTop = 0; } catch (e) {}
    try { window.scrollTo(0, 0); } catch (e) {}
  };

  var INIT = (TG && TG.initData) ? TG.initData : '';
  try {
    if (INIT) sessionStorage.setItem('rs_init', INIT);
    else INIT = sessionStorage.getItem('rs_init') || '';
  } catch (e) {}

  var FA = '۰۱۲۳۴۵۶۷۸۹', AR = '٠١٢٣٤٥٦٧٨٩';
  function en(v) {
    return String(v == null ? '' : v)
      .replace(/[۰-۹]/g, function (d) { return FA.indexOf(d); })
      .replace(/[٠-٩]/g, function (d) { return AR.indexOf(d); });
  }
  function fmt(n) { n = Number(n) || 0; return n.toLocaleString('en-US'); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function $(id) { return document.getElementById(id); }
  function hap(t) { try { TG.HapticFeedback.impactOccurred(t || 'light'); } catch (e) {} }

  var tTimer = null;
  function toast(msg) {
    var t = $('toast');
    t.firstElementChild.textContent = msg;
    t.classList.add('on');
    clearTimeout(tTimer);
    tTimer = setTimeout(function () { t.classList.remove('on'); }, 2200);
  }

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

  function copy(txt) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(txt).then(function () { toast('کپی شد ✅'); }, function () { fallback(txt); });
    } else { fallback(txt); }
    hap('medium');
  }
  function fallback(txt) {
    var a = document.createElement('textarea');
    a.value = txt; a.style.position = 'fixed'; a.style.opacity = '0';
    document.body.appendChild(a); a.select();
    try { document.execCommand('copy'); toast('کپی شد ✅'); } catch (e) { toast('کپی نشد'); }
    document.body.removeChild(a);
  }

  var S = { info: null, boot: null, tab: 'plan', price: null, busy: false, made: null, trash: null,
            stock: null, stockBusy: false, stockErr: '', stockMine: null, stockMineBusy: false,
            stockView: 'shop', stockQty: {} };

  function nb(v, unit, cls) {
    return '<span class="nb ' + (cls || '') + '">' + esc(v) + (unit ? '<span class="u">' + esc(unit) + '</span>' : '') + '</span>';
  }

  /* ======================= سربرگ ======================= */
  /* انتخاب اولین کلید موجود (سازگاری با نام‌های مختلف API) */
  function pick(o, keys, dflt) {
    o = o || {};
    for (var i = 0; i < keys.length; i++) {
      var v = o[keys[i]];
      if (v !== undefined && v !== null && v !== '') return v;
    }
    return dflt;
  }

  /* سلول آمار کوتاه */
  function hs(ic, lbl, val, cls) {
    return '<div class="hs ' + (cls || '') + '">' +
      '<span class="i">' + ic + '</span>' +
      '<span class="l">' + esc(lbl) + '</span>' +
      '<span class="v">' + val + '</span>' +
    '</div>';
  }

  /* نوار سقف */
  function qbar(lbl, used, total) {
    var pct = total > 0 ? Math.min(100, (used / total) * 100) : 0;
    var cls = pct >= 100 ? 'er' : (pct >= 80 ? 'wr' : '');
    return '<div class="qb">' +
      '<div class="qh"><span>' + esc(lbl) + '</span><b>' + fmt(used) + ' / ' + fmt(total) + '</b></div>' +
      '<div class="qt ' + cls + '"><i style="width:' + pct.toFixed(1) + '%"></i></div>' +
    '</div>';
  }

  function hero() {
    var i = S.info, q = i.quota || {}, u = (S.boot && S.boot.user) || {};
    var st = i.stats || {}, cp = i.caps || {}, br = i.brand || {};
    var lvlCls = i.level === 2 ? 'g' : 'w';

    var made  = Number(pick(st, ['total', 'count', 'services', 'made', 'built'], 0)) || 0;
    var alive = Number(pick(st, ['active', 'alive', 'live', 'on'], 0)) || 0;

    var today  = Number(cp.built_today || 0);
    var month  = Number(cp.built_month || 0);
    var dLimit = Number(cp.daily_limit || 0);
    var mLimit = Number(cp.month_limit || 0);
    var cap    = Number(cp.service_cap  || 0);

    return '<div class="hero v6">' +
      '<div class="glo"></div>' +
      '<div class="top">' +
        '<div class="ava">🏷</div>' +
        '<div style="flex:1;min-width:0">' +
          '<div class="nm">' + esc(u.name || 'نماینده') + '</div>' +
          '<div class="lv">' + esc(i.level_label || '') +
            (i.discount > 0 ? ' · تخفیف ' + fmt(i.discount) + '٪' : '') +
            (br.name ? ' · ' + esc(br.name) : '') + '</div>' +
        '</div>' +
        '<span class="tag ' + lvlCls + '">سط�� ' + fmt(i.level) + '</span>' +
      '</div>' +
      '<div class="money3">' +
        '<div class="mcell"><div class="t">موجودی</div>' + nb(fmt(q.balance), '', q.balance < 0 ? 'r' : 'g') + '</div>' +
        '<div class="mcell"><div class="t">اعتبار مجاز</div>' + nb(fmt(q.credit), '', 'a') + '</div>' +
        '<div class="mcell"><div class="t">قابل مصرف</div>' + nb(fmt(q.available), '', '') + '</div>' +
      '</div>' +
      '<div class="hstat">' +
        hs('📦', 'ساخته‌شده', fmt(made), '') +
        hs('🟢', 'فعال', fmt(alive), 'g') +
        hs('📅', 'امروز', fmt(today) + (dLimit > 0 ? ' / ' + fmt(dLimit) : ''),
           (dLimit > 0 && today >= dLimit) ? 'r' : '') +
        hs('🗓', 'این ماه', fmt(month) + (mLimit > 0 ? ' / ' + fmt(mLimit) : ''),
           (mLimit > 0 && month >= mLimit) ? 'r' : '') +
      '</div>' +
      '<div class="hqa">' +
        '<button type="button" onclick="rsGoTab(\'plan\')"><span>🎁</span>ساخت سرویس</button>' +
        '<button type="button" onclick="rsGoTab(\'list\')"><span>📦</span>کانفیگ‌ها</button>' +
        '<button type="button" onclick="rsGoTab(\'wallet\')"><span>💳</span>شارج کیف پول</button>' +
        '<button type="button" onclick="rsGoTab(\'money\')"><span>📊</span>داشبورد فروش</button>' +
      '</div>' +
      (Number(q.available) <= 0
        ? '<div class="halert">⚠️ موجودی قابل مصرف شما صفر است؛ برای ساخت سرویس تازه، کیف پول را شارج کنید یا از مدیر اعتبار بگیرید.</div>'
        : '') +
      (cap > 0 ? qbar('سقف سرویس فعال', alive, cap) : '') +
      (br.active
        ? '<div class="hdom" data-tab="brand">' +
            '<span class="i">🌐</span>' +
            '<span class="d ltr mono">' + esc(br.active) + '</span>' +
            (br.domain ? '<span class="bg ok">دامنهٔ شما</span>' : '<span class="bg">عمومی</span>') +
          '</div>'
        : '<div class="hdom warn" data-tab="brand"><span class="i">🌐</span>' +
          '<span class="d">دامنهٔ اختصاصی ثبت نشده — افزودن</span>' +
          '<span class="bg">تنظیم</span></div>') +
    '</div>';
  }

  /* ======================= تب ساخت ======================= */
  function tabBuild(sec) {
    var i = S.info, lm = i.limits || {}, tf = i.tariff || {}, ps = i.panels || [];
    var pls = i.plans || [];
    var bds = i.bundles || [];
    var h = '';

    /* ---------- سرویس‌های آمادهٔ مدیر (چند پنل / چند اینباند) ---------- */
    if (sec === 'svc' && bds.length) {
      h += '<div class="card"><h3>🧩 سرویس‌ها</h3>' +
           '<div class="sub">سرویس را انتخاب کنید؛ همهٔ کانفیگ‌ها ساخته و لینک اشتراک (ساب) برایتان ارسال می‌شود.</div>';

      h += '<div class="fld"><label>نام کانفیگ (انگلیسی – اختیاری)</label>' +
        '<input id="rSvcName" class="ltr" type="text" dir="ltr" lang="en" maxlength="24" autocapitalize="off" ' +
        'autocomplete="off" spellcheck="false" placeholder="my-client-01"></div>';

      for (var q = 0; q < bds.length; q++) {
        var bd = bds[q];
        h += '<div style="border:1px solid rgba(128,128,128,.28);border-radius:15px;padding:12px;margin-top:10px;' +
               (bd.afford ? '' : 'opacity:.62;') + '">' +
             '<div style="display:flex;align-items:center;gap:8px;justify-content:space-between">' +
               '<b style="font-size:14.5px">' + esc(bd.title) + '</b>' +
               nb(fmt(bd.price), 'T', bd.afford ? 'a' : 'r') +
             '</div>' +
             '<div class="sub" style="margin-top:5px">' + esc(bd.label) +
               ' · ' + fmt(bd.configs) + ' کانفیگ روی ' + fmt(bd.panels) + ' سرور' +
               (bd.device > 0 ? ' · ' + fmt(bd.device) + ' دستگاه' : '') +
               (bd.speed_down > 0 ? ' · ↓' + fmt(bd.speed_down) + ' KB/s' : '') +
             '</div>' +
             (bd.note ? '<div class="sub" style="margin-top:3px">' + esc(bd.note) + '</div>' : '');

        if (bd.custom) {
          h += '<div class="fld" style="margin-top:9px"><label>حجم (گیگابایت)</label>' +
            '<input id="bg_' + esc(bd.id) + '" class="ltr" type="number" inputmode="numeric" dir="ltr" lang="en" min="' +
            lm.min_gb + '" max="' + lm.max_gb + '" placeholder="30"></div>' +
            '<div class="fld"><label>مدت (روز)</label>' +
            '<input id="bd_' + esc(bd.id) + '" class="ltr" type="number" inputmode="numeric" dir="ltr" lang="en" min="' +
            lm.min_days + '" max="' + lm.max_days + '" placeholder="30"></div>';
        }

        h += '<button class="btn w" style="margin-top:10px" ' + (bd.afford ? '' : 'disabled ') +
               'onclick="rsBuildSvc(\'' + esc(bd.id) + '\')">' +
               (bd.afford ? '🚀 ساخت سرویس ' + esc(bd.title) : '⛔️ مو��ودی کافی نیست') +
             '</button>' +
             '<div id="bo_' + esc(bd.id) + '"></div>' +
             '</div>';
      }
      h += '</div>';
    }

    if (sec === 'plan' && pls.length) {
      h += '<div class="card"><h3>🎁 طرح‌های آماده</h3>' +
           '<div class="sub">با یک لمس، طرح آماده را بسازید.</div>' +
           '<div class="fld"><label>نام کانفیگ (انگلیسی – اختیاری)</label>' +
           '<input id="rPlanName" class="ltr" type="text" dir="ltr" lang="en" maxlength="24" autocapitalize="off" ' +
           'autocomplete="off" spellcheck="false" placeholder="my-client-01"></div>';
      for (var z = 0; z < pls.length; z++) {
        var pl = pls[z];
        h += '<div style="border:1px solid rgba(128,128,128,.28);border-radius:15px;padding:12px;margin-top:10px;' +
               (pl.afford ? '' : 'opacity:.62;') + '">' +
             '<div style="display:flex;align-items:center;gap:8px;justify-content:space-between">' +
               '<b style="font-size:14.5px">' + esc(pl.title) + '</b>' +
               nb(fmt(pl.price), 'T', pl.afford ? 'a' : 'r') +
             '</div>' +
             '<div class="sub" style="margin-top:5px">' + esc(pl.label) +
               (pl.device > 0 ? ' · ' + fmt(pl.device) + ' دستگاه' : '') +
               (pl.speed_down > 0 ? ' · ↓' + fmt(pl.speed_down) + ' KB/s' : '') +
               (pl.speed_up > 0 ? ' · ↑' + fmt(pl.speed_up) + ' KB/s' : '') +
             '</div>' +
             (pl.note ? '<div class="sub" style="margin-top:3px">' + esc(pl.note) + '</div>' : '') +
             '<button class="btn w" style="margin-top:10px" ' + (pl.afford ? '' : 'disabled ') +
               'onclick="rsBuyPlan(\'' + esc(pl.id) + '\')">' +
               (pl.afford ? '🚀 ساخت با این طرح' : '⛔️ موجودی کافی نیست') +
             '</button>' +
             '</div>';
      }
      h += '<div class="sub" style="margin-top:9px">💡 اگر نام را خالی بگذارید، خودکار ساخته می‌شود.</div>';
      h += '</div>';
    }

    if (sec === 'custom') {
    h += '<div class="card"><h3>⚡ ساخت کانفیگ سفارشی</h3>' +
      '<div class="sub">حجم و مدت دلخواه را خودتان تعیین کنید؛ مبلغ لحظه‌ای محاسبه می‌شود.</div>';

    if (ps.length > 1) {
      h += '<div class="fld"><label>سرور مقصد (قیمت هر سرور متفاوت است)</label><select id="rPanel">';
      for (var k = 0; k < ps.length; k++) {
        h += '<option value="' + ps[k].id + '">' + esc(ps[k].name) +
             (ps[k].gb ? ' · ' + fmt(ps[k].gb) + '/گیگ' : '') + '</option>';
      }
      h += '</select></div>';
    } else if (ps.length === 1) {
      h += '<input type="hidden" id="rPanel" value="' + ps[0].id + '">';
    }
    h += '<div id="rSrvInfo"></div>';

    h += '<div class="fld"><label>حجم (گیگابایت) · از ' + fmt(lm.min_gb) + ' تا ' + fmt(lm.max_gb) + '</label>' +
      '<input id="rGb" class="ltr" type="number" inputmode="numeric" dir="ltr" lang="en" min="' + lm.min_gb + '" max="' + lm.max_gb + '" placeholder="30">' +
      '<div class="chips" data-for="rGb">' +
        [10, 20, 30, 50, 100, 200].map(function (v) { return '<button class="chip" data-v="' + v + '">' + v + '</button>'; }).join('') +
      '</div></div>';

    h += '<div class="fld"><label>مدت (روز) · از ' + fmt(lm.min_days) + ' تا ' + fmt(lm.max_days) + '</label>' +
      '<input id="rDay" class="ltr" type="number" inputmode="numeric" dir="ltr" lang="en" min="' + lm.min_days + '" max="' + lm.max_days + '" placeholder="30">' +
      '<div class="chips" data-for="rDay">' +
        [7, 30, 60, 90, 180, 365].map(function (v) { return '<button class="chip" data-v="' + v + '">' + v + '</button>'; }).join('') +
      '</div></div>';

    h += '<div class="fld"><label>نام کانفیگ (انگلیسی)</label>' +
      '<input id="rName" class="ltr" type="text" dir="ltr" lang="en" maxlength="24" autocapitalize="off" ' +
      'autocomplete="off" spellcheck="false" placeholder="my-client-01"></div>';

    h += '<div class="inv" id="rInv">' +
      '<div class="irow"><span class="k">تعرفه هر گیگ</span>' + nb(fmt(tf.gb), 'T') + '</div>' +
      '<div class="irow"><span class="k">تعرفه هر روز</span>' + nb(fmt(tf.day), 'T') + '</div>' +
      '<div class="irow tot"><span class="k">مبلغ نهایی</span>' + nb('—') + '</div>' +
      '</div>';

    h += '<div class="brow" style="margin-top:13px">' +
      '<button class="btn w" id="rGo">🚀 ساخت و کسر از موجودی</button></div>';

    h += '<div id="rOut"></div></div>';
    }

    if (h === '') {
      if (sec === 'svc') {
        h = '<div class="card"><h3>🧩 سرویس‌ها</h3>' +
            '<div class="empty"><span class="ic">🧩</span>هنوز سرویس آماده‌ای توسط مدیر تعریف نشده است.</div>' +
            '<div class="sub" style="text-align:center">از تب «حجم کاستوم» می‌توانید کانفیگ دلخواه بسازید.</div></div>';
      } else if (sec === 'plan') {
        h = '<div class="card"><h3>🎁 طرح‌های آماده</h3>' +
            '<div class="empty"><span class="ic">🎁</span>طرح آماده‌ای فعال نیست.</div>' +
            '<div class="sub" style="text-align:center">از تب «حجم کاستوم» استفاده کنید.</div></div>';
      }
    }

    if (i.note) h += '<div class="card"><h3>📌 یادداشت مدیر</h3><div class="sub">' + esc(i.note) + '</div></div>';
    return h;
  }

  function invoice(p, afford, msg) {
    var h = '<div class="irow"><span class="k">حجم</span>' + nb(fmt(p.gb), 'GB') + '</div>' +
      '<div class="irow"><span class="k">مدت</span>' + nb(fmt(p.days), 'DAY') + '</div>' +
      '<div class="irow"><span class="k">مبلغ پایه</span>' + nb(fmt(p.base), 'T') + '</div>';
    if (p.discount > 0) {
      h += '<div class="irow"><span class="k">تخفیف ' + fmt(p.discount_pct) + '٪</span>' + nb('-' + fmt(p.discount), 'T', 'g') + '</div>';
    }
    h += '<div class="irow tot"><span class="k">مبلغ نهایی</span>' + nb(fmt(p.final), 'T', afford ? 'a' : 'r') + '</div>';
    if (!afford && msg) h += '<div class="al err" style="white-space:pre-line">' + esc(msg) + '</div>';
    return h;
  }

  /* شناسهٔ سرور انتخاب‌شده */
  function rsPanelId() {
    var el = $('rPanel');
    return el ? (parseInt(en(el.value || '0'), 10) || 0) : 0;
  }

  /* کارت تعرفهٔ همان سرور */
  function srvInfo() {
    var box = $('rSrvInfo');
    if (!box) return;

    var id = rsPanelId(), ps = (S.info.panels || []), p = null;
    for (var i = 0; i < ps.length; i++) { if (+ps[i].id === id) { p = ps[i]; break; } }
    if (!p || !p.gb) { box.innerHTML = ''; return; }

    var cs = 'font-size:11.5px;border:1px solid rgba(128,128,128,.3);border-radius:999px;padding:3px 9px;white-space:nowrap';
    var h = '<div style="display:flex;flex-wrap:wrap;gap:6px;margin:2px 0 4px">' +
      '<span style="' + cs + '">💽 ' + fmt(p.gb) + ' / گیگ</span>' +
      '<span style="' + cs + '">📆 ' + fmt(p.day) + ' / روز</span>' +
      (p.off > 0 ? '<span style="' + cs + ';color:#2FD48F">🎁 ' + fmt(p.off) + '٪ تخفیف</span>' : '') +
      (p.custom ? '<span style="' + cs + ';color:#A9C1FF">⭐ تعرفهٔ اختصاصی</span>' : '') +
      '</div>';

    if (p.min_gb) {
      h += '<div class="sub">حجم ' + fmt(p.min_gb) + '–' + fmt(p.max_gb) + ' گیگ · مدت ' +
           fmt(p.min_days) + '–' + fmt(p.max_days) + ' روز</div>';
    }
    if (p.note) h += '<div class="sub">' + esc(p.note) + '</div>';

    box.innerHTML = h;
  }

  var pTimer = null;
  function priceCalc() {
    var gb = parseInt(en(($('rGb') || {}).value || '0'), 10) || 0;
    var dy = parseInt(en(($('rDay') || {}).value || '0'), 10) || 0;
    if (gb <= 0 || dy <= 0) { S.price = null; return; }
    clearTimeout(pTimer);
    pTimer = setTimeout(function () {
      api('rs_price', { volume_gb: gb, days: dy, panel_id: rsPanelId() }).then(function (r) {
        if (!r.ok) { $('rInv').innerHTML = '<div class="al err">' + esc(r.message || 'خطا') + '</div>'; return; }
        S.price = r.price;
        S.afford = !!r.afford;
        $('rInv').innerHTML = invoice(r.price, r.afford, r.message);
      });
    }, 320);
  }

  /* ساخت یک سرویس آماده (چند پنل / چند اینباند) */
  window.rsBuildSvc = function (id) {
    if (S.busy) return;
    S.busy = true;
    hap('medium');

    var out = $('bo_' + id);
    if (out) out.innerHTML = '<div class="sub" style="margin-top:8px">⏳ در حال ساخت…</div>';
    toast('⏳ در حال ساخت…');

    var body = { bundle_id: id, name: (($('rSvcName') || {}).value || '').trim() };
    var gEl  = $('bg_' + id), dEl = $('bd_' + id);
    if (gEl) body.volume_gb = parseInt(en(gEl.value || '0'), 10) || 0;
    if (dEl) body.days      = parseInt(en(dEl.value || '0'), 10) || 0;

    api('rs_bundle', body).then(function (r) {
      S.busy = false;
      if (!r.ok) {
        if (out) out.innerHTML = '<div class="al err" style="white-space:pre-line">' + esc(r.message || 'ساخت ناموفق بود') + '</div>';
        toast(r.message || 'ساخت ناموفق بود');
        return;
      }
      hap('heavy');
      toast('سرویس ساخته شد ✅');

      var hh = '<div class="al ok" style="white-space:pre-line">' + esc(r.message || 'سرویس ساخته شد ✅') + '</div>';
      (r.services || []).forEach(function (s) { hh += svcCard(s, true); });
      if (out) out.innerHTML = hh;

      if (r.quota) S.info.quota = r.quota;
      if (r.stats) S.info.stats = r.stats;
      var hr = document.querySelector('.hero');
      if (hr) hr.outerHTML = hero();
      api('rs_info').then(function (x) { if (x.ok) S.info = x; });
    });
  };

  /* خرید طرح آماده */
  window.rsBuyPlan = function (id) {
    if (S.busy) return;
    S.busy = true;
    hap('medium');
    toast('⏳ در حال ساخت…');
    var nm = (($('rPlanName') || $('rName') || {}).value || '').trim();
    api('rs_create', { plan_id: id, name: nm }).then(function (r) {
      S.busy = false;
      if (!r.ok) { toast(r.message || 'ساخت ناموفق بود'); return; }
      hap('heavy');
      toast('کانفیگ ساخته شد ✅');
      api('rs_info').then(function (x) { if (x.ok) { S.info = x; render(); } });
    });
  };

  function doCreate() {
    if (S.busy) return;
    var gb = parseInt(en(($('rGb') || {}).value || '0'), 10) || 0;
    var dy = parseInt(en(($('rDay') || {}).value || '0'), 10) || 0;
    var nm = (($('rName') || {}).value || '').trim();
    var pn = parseInt((($('rPanel') || {}).value || '0'), 10) || 0;

    if (gb <= 0 || dy <= 0) { toast('حجم و مدت را وارد کنید'); return; }

    S.busy = true;
    var b = $('rGo'); b.disabled = true; b.textContent = '⏳ در حال ساخت…';
    hap('medium');

    api('rs_create', { volume_gb: gb, days: dy, name: nm, panel_id: pn }).then(function (r) {
      S.busy = false; b.disabled = false; b.textContent = '🚀 ساخت و کسر از موجودی';
      if (!r.ok) { $('rOut').innerHTML = '<div class="al err" style="white-space:pre-line">' + esc(r.message || 'ساخت ناموفق بود.') + '</div>'; return; }
      hap('heavy');
      toast('کانفیگ ساخته شد ✅');
      if (r.quota) S.info.quota = r.quota;
      if (r.stats) S.info.stats = r.stats;
      S.made = r.service;
      $('rOut').innerHTML = '<div class="al ok">' + esc(r.message || 'کانفیگ ساخته شد ✅') + '</div>' + svcCard(r.service, true);
      var hr = document.querySelector('.hero');
      if (hr) hr.outerHTML = hero();
      api('rs_info').then(function (x) { if (x.ok) S.info = x; });
    });
  }

  /* ======================= کارت سرویس ======================= */
  function svcCard(s, open) {
    var pct = Math.max(0, Math.min(100, Number(s.percent) || 0));
    var on  = s.status === 'active';
    var bc  = pct >= 90 ? ' r' : (pct >= 70 ? ' w' : '');

    var h = '<div class="svc v2' + (on ? '' : ' off') + '" id="sv' + s.id + '">' +
      '<div class="s2h">' +
        '<span class="s2i">' + (on ? '🟢' : '⌛') + '</span>' +
        '<span class="s2n">' + esc(s.name) + '</span>' +
        '<span class="tag ' + (on ? 'g' : 'w') + '">' +
          (on ? 'فعال' : esc(s.status)) + '</span>' +
      '</div>' +
      '<div class="s2b' + bc + '"><i style="width:' + pct + '%"></i>' +
        '<em>' + fmt(pct) + '% مصرف</em></div>' +
      '<div class="s2g">' +
        '<div class="s2c"><b>' + esc(s.volume_txt || '—') + '</b><i>حجم کل</i></div>' +
        '<div class="s2c"><b>' + esc(s.used_txt || '—') + '</b><i>مصرف‌شده</i></div>' +
        '<div class="s2c g"><b>' + esc(s.remain_txt || '—') + '</b><i>زمان باقی‌مانده</i></div>' +
      '</div>';

    if (s.sub) {
      h += '<div class="s2l"><span class="k">🔗 لینک اشتراک</span>' +
        '<code>' + esc(s.sub) + '</code>' +
        '<button class="btn sm gh" data-copy="' + esc(s.sub) + '">📋</button></div>';
    }

    if (open && s.configs && s.configs.length) {
      for (var k = 0; k < s.configs.length; k++) {
        h += '<div class="s2l"><span class="k">کانفیگ ' + fmt(k + 1) + '</span>' +
          '<code>' + esc(s.configs[k]) + '</code>' +
          '<button class="btn sm gh" data-copy="' + esc(s.configs[k]) + '">📋</button></div>';
      }
    }

    h += '<div class="s2a">' +
      '<button class="btn sm gh" data-edit="' + s.id + '">✏️ ویرایش حجم و زمان</button>' +
      (s.sub
        ? '<button class="btn sm gh" data-copy="' + esc(s.sub) + '">📋 کپی لینک</button>'
        : '<button class="btn sm gh" data-edit="' + s.id + '">🔄 تمدید</button>') +
      '<button class="btn sm gh dgr" data-del="' + s.id + '" style="grid-column:1/-1">🗑 حذف کانفیگ</button>' +
      '</div>';

    return h + '</div>';
  }

  function tabList() {
    var rows = S.info.services || [];
    var i, act = 0, soon = 0, exp = 0;
    for (i = 0; i < rows.length; i++) {
      var st0 = rows[i].status, pc0 = Number(rows[i].percent) || 0;
      if (st0 === 'active') act++;
      if (st0 === 'active' && pc0 >= 80) soon++;
      if (st0 === 'expired' || st0 === 'missing') exp++;
    }

    var h = '<div class="card"><h3>📦 کانفیگ‌های ساخته‌شده' +
      '<span style="margin-inline-start:auto">' + nb(fmt(rows.length)) + '</span></h3>';

    if (!rows.length) {
      return h + '<div class="empty"><span class="ic">📭</span>هنوز کانفیگی نساخته‌اید.</div>' +
        '<div class="brow" style="margin-top:12px">' +
        '<button class="btn w" onclick="rsGoTab(\'plan\')">🎁 ساخت اولین سرویس</button></div></div>';
    }

    h += '<div class="rss">' +
      '<div class="c g"><b>' + fmt(act) + '</b><i>فعال</i></div>' +
      '<div class="c w"><b>' + fmt(soon) + '</b><i>نزدیک پایان</i></div>' +
      '<div class="c"><b>' + fmt(exp) + '</b><i>منقضی</i></div>' +
      '<div class="c"><b>' + fmt(rows.length) + '</b><i>کل</i></div>' +
      '</div>';

    var F = [['all', '📋 همه'], ['active', '🟢 فعال'],
             ['soon', '⚠️ نزدیک پایان'], ['expired', '⌛ منقضی']];
    h += '<div class="rsf">';
    for (i = 0; i < F.length; i++) {
      h += '<button type="button" class="' + ((S.rsF || 'all') === F[i][0] ? 'on' : '') +
        '" onclick="rsFilter(\'' + F[i][0] + '\')">' + F[i][1] + '</button>';
    }
    h += '</div>';

    h += '<div class="fld"><input id="rSearch" type="text" placeholder="جست‌وجوی نام…" value="' +
      esc(S.rsQ || '') + '"></div><div id="rRows">';

    var q = String(S.rsQ || '').trim().toLowerCase(), shown = 0;
    for (i = 0; i < rows.length; i++) {
      if (!rsMatch(rows[i], S.rsF || 'all')) continue;
      if (q && String(rows[i].name).toLowerCase().indexOf(q) < 0) continue;
      h += svcCard(rows[i], false);
      shown++;
    }
    if (!shown) h += '<div class="empty"><span class="ic">🔍</span>موردی با این فیلتر نیست.</div>';
    h += '</div>';

    return h + '</div>';
  }

  /** تطبیق یک کانفیگ با فیلتر فعال */
  function rsMatch(s, f) {
    var pc = Number(s.percent) || 0;
    if (f === 'active') return s.status === 'active';
    if (f === 'soon') return s.status === 'active' && pc >= 80;
    if (f === 'expired') return s.status === 'expired' || s.status === 'missing';
    return true;
  }

  window.rsFilter = function (f) { S.rsF = f; hap('light'); render(); };

  /* ======================= تب مالی — داشبورد گستردهٔ فروش ======================= */

  function moneyLoad(force) {
    if (S.moneyBusy) return;
    if (S.money && !force) return;
    S.moneyBusy = true;
    S.moneyErr  = '';
    api('rs_money').then(function (r) {
      S.moneyBusy = false;
      if (r && r.ok) { S.money = r; }
      else { S.moneyErr = (r && r.message) || 'دریافت آمار ناموفق بود.'; }
      render();
    });
  }
  window.__rsMoney = {
    reload: function () { hap('medium'); S.money = null; S.moneyErr = ''; moneyLoad(true); render(); },
    chart:  function (mode) {
      S.mChart = mode;
      hap('light');
      render();
    }
  };

  function mCss() {
    return '<style>' +
      '.chart{display:flex;align-items:flex-end;gap:4px;height:118px;margin:10px 0 2px;padding:8px 4px 0;' +
        'border-radius:14px;background:rgba(0,0,0,.20);box-shadow:inset 0 1px 4px rgba(0,0,0,.40)}' +
      '.cbar{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;gap:5px;height:100%}' +
      '.cb{flex:1;width:100%;display:flex;align-items:flex-end;border-radius:7px;overflow:hidden}' +
      '.cb > i{display:block;width:100%;border-radius:7px 7px 3px 3px;' +
        'background:linear-gradient(180deg,#6C8CFF,#22d3ee);box-shadow:0 0 8px rgba(108,140,255,.45)}' +
      '.cb.g > i{background:linear-gradient(180deg,#34d399,#16a34a);box-shadow:0 0 8px rgba(52,211,153,.40)}' +
      '.cl{font-size:9px;opacity:.60;direction:ltr;white-space:nowrap}' +
      '.seg{display:flex;gap:6px;margin:10px 0 0}' +
      '.seg button{flex:1;border:0;border-radius:12px;padding:8px 6px;font:inherit;font-size:11.5px;cursor:pointer;' +
        'background:rgba(255,255,255,.07);color:inherit;box-shadow:inset 0 1px 0 rgba(255,255,255,.14)}' +
      '.seg button.on{background:linear-gradient(160deg,#6C8CFF,#4b6ae0);color:#0B1020;font-weight:800}' +
      '.tbl{width:100%;border-collapse:collapse;margin-top:6px;font-size:12px}' +
      '.tbl th{font-size:10.5px;opacity:.62;font-weight:700;padding:5px 4px;text-align:right}' +
      '.tbl td{padding:7px 4px;border-top:1px solid rgba(255,255,255,.07)}' +
      '.tbl td.n{direction:ltr;text-align:left;white-space:nowrap;font-weight:700}' +
      '.lg{display:flex;align-items:center;gap:9px;padding:8px 0;border-top:1px solid rgba(255,255,255,.07);font-size:12px}' +
      '.lg .d{width:26px;height:26px;flex:0 0 26px;display:grid;place-items:center;border-radius:9px;font-size:12px}' +
      '.lg .d.out{background:rgba(248,113,113,.16);color:#fca5a5}' +
      '.lg .d.in{background:rgba(52,211,153,.16);color:#6ee7b7}' +
      '.lg .t{flex:1;min-width:0;overflow-wrap:break-word}' +
      '.lg .t i{display:block;font-style:normal;font-size:10px;opacity:.55;direction:ltr}' +
      '.lg .a{direction:ltr;white-space:nowrap;font-weight:800}' +
      '.exp{display:flex;align-items:center;gap:9px;padding:8px 0;border-top:1px solid rgba(255,255,255,.07)}' +
      '.exp .nm{flex:1;min-width:0;font-size:12px;overflow-wrap:anywhere}' +
      '.exp .nm i{display:block;font-style:normal;font-size:10px;opacity:.55}' +
      '.exp .dd{font-size:11px;padding:3px 8px;border-radius:9px;white-space:nowrap;background:rgba(255,255,255,.08)}' +
      '.exp .dd.w{background:rgba(250,204,21,.18);color:#fde68a}' +
      '.exp .dd.r{background:rgba(248,113,113,.18);color:#fca5a5}' +
      '</style>';
  }

  function mChartBlock(series) {
    var mode = S.mChart === 's' ? 's' : 'c';
    var i, v, max = 0;
    for (i = 0; i < series.length; i++) {
      v = mode === 's' ? +series[i].s : +series[i].c;
      if (v > max) max = v;
    }
    var h = '<div class="seg">' +
      '<button type="button" class="' + (mode === 'c' ? 'on' : '') + '" onclick="__rsMoney.chart(\'c\')">📦 تعداد کانفیگ</button>' +
      '<button type="button" class="' + (mode === 's' ? 'on' : '') + '" onclick="__rsMoney.chart(\'s\')">💰 مبلغ خرید</button>' +
      '</div><div class="chart">';
    for (i = 0; i < series.length; i++) {
      v = mode === 's' ? +series[i].s : +series[i].c;
      var pc  = max > 0 && v > 0 ? Math.max(6, Math.round(v * 100 / max)) : 2;
      var ttl = series[i].label + ' — ' + (mode === 's' ? fmt(v) + ' تومان' : fmt(v) + ' کانفیگ');
      h += '<div class="cbar" title="' + esc(ttl) + '">' +
             '<div class="cb' + (mode === 's' ? ' g' : '') + '"><i style="height:' + pc + '%"></i></div>' +
             '<div class="cl">' + esc(series[i].label) + '</div>' +
           '</div>';
    }
    h += '</div><div class="kl" style="opacity:.6;font-size:10.5px;margin-top:6px">' +
         (max > 0 ? 'بیشینهٔ ۱۴ روز اخیر: ' + fmt(max) + (mode === 's' ? ' تومان' : ' کانفیگ')
                  : 'در ۱۴ روز اخیر ثبتی ندارید.') + '</div>';
    return h;
  }

  function tabMoney() {
    var m = S.money;

    if (!m) {
      if (!S.moneyErr && !S.moneyBusy) moneyLoad(false);
      return '<div class="card"><h3>📊 داشبورد فروش</h3>' +
        (S.moneyErr
          ? '<div class="empty"><span class="ic">⚠️</span>' + esc(S.moneyErr) + '</div>' +
            '<div class="brow"><button class="btn" type="button" onclick="__rsMoney.reload()">🔄 تلاش دوباره</button></div>'
          : '<div class="empty"><span class="ic">⏳</span>در حال محاسبهٔ آمار فروش…</div>') +
        '</div>';
    }

    var c  = m.cnt || {}, sp = m.spend || {}, vl = m.vol || {};
    var q  = m.quota || S.info.quota || {};
    var bot = (S.boot && S.boot.shop && S.boot.shop.bot) || '';
    var off = Math.max(0, (+c.expired || 0) + (+c.disabled || 0));

    var h = mCss() + '<div class="card"><h3>📊 داشبورد فروش' +
      '<button class="btn gh" type="button" style="margin-inline-start:auto;padding:5px 10px;font-size:11px"' +
      ' onclick="__rsMoney.reload()">🔄</button></h3>';

    /* ---- کارت‌های کلیدی ---- */
    h += '<div class="kpi-grid">' +
      '<div class="kpi k1"><div class="kv">' + fmt(c.total) + '</div><div class="kl">کل کانفیگ‌ها</div></div>' +
      '<div class="kpi k2"><div class="kv">' + fmt(c.active) + '</div><div class="kl">فعال</div></div>' +
      '<div class="kpi k3"><div class="kv">' + fmt(off) + '</div><div class="kl">منقضی / غیرفعال</div></div>' +
      '<div class="kpi k4"><div class="kv">' + fmt(c.missing) + '</div><div class="kl">حذف‌شده از پنل</div></div>' +
      '<div class="kpi k1"><div class="kv">' + fmt(c.today) + '</div><div class="kl">ساخت امروز</div></div>' +
      '<div class="kpi k2"><div class="kv">' + fmt(c.d7) + '</div><div class="kl">۷ روز اخیر</div></div>' +
      '<div class="kpi k5"><div class="kv">' + fmt(c.d30) + '</div><div class="kl">۳۰ روز اخیر</div></div>' +
      '<div class="kpi k4"><div class="kv">' + fmt(c.soon) + '</div><div class="kl">نزدیک انقضا (۷ روز)</div></div>' +
      '<div class="kpi wide"><div class="kl">سلامت فروش — درصد کانفیگ‌های فعال</div>' +
        '<div class="pb"><i style="width:' + (+c.pct_active || 0) + '%"></i></div>' +
        '<div class="kl mt6">' + (+c.pct_active || 0) + '% فعال از ' + fmt(c.total) + ' کانفیگ ساخته‌شده' +
        (+c.renews > 0 ? ' · ' + fmt(c.renews) + ' تمدید' : '') + '</div></div>' +
      '</div>';

    /* ---- خرید و گردش مالی ---- */
    h += '<hr class="hr"><div class="sec-h">💰 خرید شما از پنل</div>' +
      '<div class="irow"><span class="k">امروز</span>' + nb(fmt(sp.today), 'T') + '</div>' +
      '<div class="irow"><span class="k">۷ روز اخیر</span>' + nb(fmt(sp.d7), 'T') + '</div>' +
      '<div class="irow"><span class="k">۳۰ روز اخیر</span>' + nb(fmt(sp.d30), 'T') + '</div>' +
      '<div class="irow"><span class="k">میانگین روزانه (۳۰ روز)</span>' + nb(fmt(sp.daily_avg), 'T') + '</div>' +
      '<div class="irow"><span class="k">میانگین هزینهٔ هر کانفیگ</span>' + nb(fmt(sp.avg), 'T') + '</div>' +
      '<div class="irow"><span class="k">مجموع شارژ کیف پول</span>' + nb(fmt(sp.topup), 'T', 'g') + '</div>' +
      '<div class="irow tot"><span class="k">مجموع خرید از ابتدا</span>' + nb(fmt(sp.all), 'T') + '</div>';

    /* ---- نمودار ۱۴ روز ---- */
    h += '<hr class="hr"><div class="sec-h">📈 روند ۱۴ روز اخیر</div>' + mChartBlock(m.series || []);

    /* ---- حجم ---- */
    h += '<hr class="hr"><div class="sec-h">💽 حجم فروشته‌شده</div>' +
      '<div class="irow"><span class="k">مجموع حجم فروشته‌شده</span>' + nb(fmt(vl.gb_sold), 'GB') + '</div>' +
      '<div class="irow"><span class="k">مصرف واقعی مشتریان</span>' + nb(String(vl.used || '0 B')) + '</div>' +
      '<div class="irow"><span class="k">نسبت مصرف</span>' +
        nb((+vl.gb_sold > 0 ? Math.round((+vl.used_gb || 0) * 100 / (+vl.gb_sold)) : 0), '%') + '</div>';

    /* ---- تفکیک سرور ---- */
    var bp = m.by_panel || [];
    h += '<hr class="hr"><div class="sec-h">🗄 تفکیک بر اساس سرور</div>';
    if (!bp.length) {
      h += '<div class="sub">هنوز داده‌ای نیست.</div>';
    } else {
      h += '<table class="tbl"><tr><th>سرور</th><th>کانفیگ</th><th>حجم</th><th>مصرف</th></tr>';
      for (var p = 0; p < bp.length; p++) {
        h += '<tr><td>' + esc(bp[p].name) + '</td>' +
             '<td class="n">' + fmt(bp[p].c) + '</td>' +
             '<td class="n">' + fmt(bp[p].gb) + ' GB</td>' +
             '<td class="n">' + esc(bp[p].used) + '</td></tr>';
      }
      h += '</table>';
    }

    /* ---- نز��یک‌ترین انقضاها ---- */
    var ex = m.exp_soon || [];
    if (ex.length) {
      h += '<hr class="hr"><div class="sec-h">⏳ نزدیک‌ترین انقضاها</div>';
      for (var e = 0; e < ex.length; e++) {
        var dcls = (+ex[e].days <= 2 ? ' r' : (+ex[e].days <= 7 ? ' w' : ''));
        h += '<div class="exp"><div class="nm">' + esc(ex[e].name) +
             '<i>' + esc(ex[e].used) + ' از ' + fmt(ex[e].gb) + ' GB · ' + (+ex[e].pct || 0) + '%</i></div>' +
             '<div class="dd' + dcls + '">' + fmt(ex[e].days) + ' روز</div></div>';
      }
    }

    /* ---- کیف پول ---- */
    h += '<hr class="hr"><div class="sec-h">💳 کیف پول و اعتبار</div>' +
      '<div class="irow"><span class="k">موجودی کیف پول</span>' + nb(fmt(q.balance), 'T', (+q.balance < 0 ? 'r' : 'g')) + '</div>' +
      '<div class="irow"><span class="k">سقف اعتبار (بدهی مجاز)</span>' + nb(fmt(q.credit), 'T', 'a') + '</div>' +
      '<div class="irow"><span class="k">بدهی فعلی</span>' + nb(fmt(q.debt), 'T', (+q.debt > 0 ? 'r' : '')) + '</div>' +
      '<div class="irow tot"><span class="k">قابل مصرف</span>' + nb(fmt(q.available), 'T') + '</div>';

    /* ---- گردش حساب ---- */
    var lg = m.ledger || [];
    if (lg.length) {
      h += '<hr class="hr"><div class="sec-h">🧾 آخرین گردش حساب</div>';
      for (var g = 0; g < lg.length; g++) {
        h += '<div class="lg"><div class="d ' + (lg[g].dir === 'out' ? 'out' : 'in') + '">' +
             (lg[g].dir === 'out' ? '−' : '+') + '</div>' +
             '<div class="t">' + esc(lg[g].note) + '<i>' + esc(lg[g].at) + '</i></div>' +
             '<div class="a">' + esc(lg[g].amount_txt) + '</div></div>';
      }
    }

    h += '<div class="brow" style="margin-top:14px">' +
      '<button class="btn" data-tab="wallet">💳 شارژ کیف پول</button>' +
      '<button class="btn gh" data-tab="list">📦 کانفیگ‌ها</button>' +
      (bot ? '<a class="btn gh" href="https://t.me/' + esc(bot) + '" target="_blank">🤖 بازکردن ربات</a>' : '') +
      '</div></div>';

    return h;
  }

  /* ======================= تب راهنما ======================= */
  /* ---------- راهنما و امکانات نمایندگی ---------- */

  /** نشان فعال/غیرفعال بودن یک دسترسی */
  function cap(ic, lbl, on) {
    return '<div class="cp ' + (on ? 'on' : 'off') + '">' +
      '<span class="i">' + ic + '</span>' +
      '<span class="l">' + esc(lbl) + '</span>' +
      '<span class="s">' + (on ? '✓' : '✕') + '</span>' +
    '</div>';
  }

  function tabHelp() {
    var i = S.info, tf = i.tariff || {}, lm = i.limits || {}, cp = i.caps || {};

    var out = '';

    /* قیمت جداگانهٔ هر سرور */
    var srv51 = (i.panels || []).filter(function (p) { return p && p.gb; });
    if (srv51.length) {
      out += '<div class="card rsc"><h3>🌍 قیمت سرورها</h3>' +
        '<div class="sub">هر لوکیشن می‌تواند تعرفهٔ جداگانه داشته باشد.</div>';

      srv51.forEach(function (p) {
        out += '<div class="irow"><span class="k">' + esc(p.name) + (p.custom ? ' ⭐' : '') + '</span>' +
          nb(fmt(p.gb) + ' / ' + fmt(p.day), 'T') + '</div>';
        if (p.off > 0 || p.note) {
          out += '<div class="sub">' +
            (p.off > 0 ? '🎁 ' + fmt(p.off) + '٪ تخفیف ویژه' + (p.note ? ' · ' : '') : '') +
            (p.note ? esc(p.note) : '') + '</div>';
        }
      });

      out += '<div class="sub" style="margin-top:7px">عددها: قیمت هر گیگ / قیمت هر روز</div></div>';
    }

    /* پیام خوش‌آمد مدیر */
    if (cp.welcome) {
      out += '<div class="card rsc acc"><h3>👋 پیام مدیریت</h3>' +
        '<div class="sub pre">' + esc(cp.welcome) + '</div></div>';
    }

    /* تعرفه */
    out += '<div class="card rsc"><h3>💰 تعرفهٔ شما</h3>' +
      '<div class="irow"><span class="k">قیمت هر گیگابایت</span>' + nb(fmt(tf.gb), 'T') + '</div>' +
      '<div class="irow"><span class="k">قیمت هر روز</span>' + nb(fmt(tf.day), 'T') + '</div>' +
      '<div class="irow"><span class="k">تخفیف شما</span>' + nb(fmt(i.discount), '%', 'g') + '</div>' +
      '<div class="irow"><span class="k">محدودهٔ حجم</span>' + nb(fmt(lm.min_gb) + ' – ' + fmt(lm.max_gb), 'GB') + '</div>' +
      '<div class="irow"><span class="k">محدودهٔ مدت</span>' + nb(fmt(lm.min_days) + ' – ' + fmt(lm.max_days), 'DAY') + '</div>' +
      '<div class="irow"><span class="k">محدودیت آی‌پی</span>' + nb(fmt(lm.ip_limit)) + '</div>' +
      (Number(cp.min_charge) > 0
        ? '<div class="irow"><span class="k">حداقل شارژ حساب</span>' + nb(fmt(cp.min_charge), 'T', 'w') + '</div>'
        : '') +
    '</div>';

    /* سقف‌ها */
    var dL = Number(cp.daily_limit || 0), mL = Number(cp.month_limit || 0), sC = Number(cp.service_cap || 0);
    if (dL > 0 || mL > 0 || sC > 0) {
      out += '<div class="card rsc"><h3>🎯 سقف‌های شما</h3>' +
        (dL > 0 ? qbar('ساخت امروز', Number(cp.built_today || 0), dL) : '') +
        (mL > 0 ? qbar('ساخت این ماه', Number(cp.built_month || 0), mL) : '') +
        (sC > 0 ? '<div class="irow"><span class="k">سقف سرویس فعال</span>' + nb(fmt(sC)) + '</div>' : '') +
      '</div>';
    }

    /* دسترسی‌ها */
    out += '<div class="card rsc"><h3>🛠 دسترسی‌های پنل شما</h3>' +
      '<div class="capg">' +
        cap('🆓', 'ساخت اکانت تست', !!cp.allow_test) +
        cap('♻️', 'تمدید سرویس', cp.allow_renew !== false) +
        cap('✏️', 'ویرایش حجم/مدت', cp.allow_edit !== false) +
        cap('🔤', 'تغییر نام کانفیگ', cp.allow_rename !== false) +
        cap('🗑', 'حذف و عودت وجه', cp.allow_delete !== false) +
        cap('💵', 'نمایش قیمت خرید', cp.show_price !== false) +
      '</div>' +
      (cp.wallet_only ? '<div class="alert w">⚠️ پرداخت فقط از طریق کیف پول ممکن است.</div>' : '') +
      (cp.hide_panel ? '<div class="sub">🔒 نام سرور و پنل از دید شما مخفی است.</div>' : '') +
    '</div>';

    /* راهنمای گام‌به‌گام */
    out += '<div class="card rsc"><h3>📘 چگونه سرویس بسازم؟</h3>' +
      '<div class="stp"><b>۱</b><span>از منو، «طرح آماده» یا «حجم کاستوم» را انتخاب کنید.</span></div>' +
      '<div class="stp"><b>۲</b><span>حجم و مدت را وارد کنید؛ قیمت لحظه‌ای محاسبه می‌شود.</span></div>' +
      '<div class="stp"><b>۳</b><span>نام کانفیگ فقط با حروف انگلیسی، عدد و خط تیره.</span></div>' +
      '<div class="stp"><b>۴</b><span>سطح ۱: موجودی کافی لازم است. سطح ۲: تا سقف اعتبار مجاز می‌توانید بدهکار شوید.</span></div>' +
      '<div class="stp"><b>۵</b><span>پس از ساخت، لینک ساب در همین صفحه و در ربات برایتان ارسال می‌شود.</span></div>' +
    '</div>';

    /* پشتیبانی و قوانین */
    if (cp.support_id) {
      out += '<div class="card rsc"><h3>🆘 پشتیبانی ویژهٔ نمایندگان</h3>' +
        '<div class="sub">برای مسائل فنی و مالی مستقیم پیام بدهید.</div>' +
        '<a class="btn w" href="https://t.me/' + esc(cp.support_id) + '" target="_blank" rel="noopener">💬 گفتگو با پشتیبانی</a></div>';
    }

    if (cp.tos) {
      out += '<div class="card rsc"><h3>📋 قوانین نمایندگی</h3>' +
        '<div class="sub pre">' + esc(cp.tos) + '</div></div>';
    }

    return out;
  }

  /* ---------- برند و دامنهٔ اختصاصی (خودسرویس) ---------- */
  function tabBrand() {
    var i = S.info, br = i.brand || {};
    var own = br.domain || '';
    var glb = br.global || '';
    var act = br.active || '';

    return '<div class="card rsc acc">' +
      '<h3>🌐 برند و دامنهٔ اختصاصی شما</h3>' +
      '<div class="sub">لینک اشتراک (ساب) مشتریان شما با همین دامنه ساخته می‌شود؛ ' +
        'پس مشتری شما نام فروشگاه شما را می‌بیند، نه ما را.</div>' +

      '<div class="dompv ' + (own ? 'ok' : '') + '">' +
        '<span class="l">دامنهٔ فعال لینک ساب</span>' +
        '<span class="v ltr mono">' + (act ? esc(act) : 'دامنهٔ پیش‌فرض فروشگاه') + '</span>' +
        '<span class="s">' + (own
            ? '✅ دامنهٔ شخصی شما فعال است'
            : (glb ? 'ℹ️ در حال استفاده از دامنهٔ عمومی: ' + esc(glb)
                  : 'ℹ️ هنوز دامنه‌ای ثبت نشده است')) + '</span>' +
      '</div>' +

      '<div class="fld"><label>نام برند / فروشگاه شما</label>' +
        '<input id="rbBrand" type="text" maxlength="40" placeholder="مثلاً: نت‌سریع" value="' + esc(br.name || '') + '">' +
        '<div class="hnt">حداکثر ۴۰ کاراکتر. در هدر پنل و پیام‌های مشتریان نمایش داده می‌شود.</div></div>' +

      '<div class="fld"><label>دامنهٔ اختصاصی لینک ساب (اختیاری)</label>' +
        '<input id="rbDom" class="ltr" type="text" dir="ltr" lang="en" maxlength="120" ' +
          'autocapitalize="off" autocomplete="off" spellcheck="false" ' +
          'placeholder="sub.example.com" value="' + esc(own) + '">' +
        '<div class="hnt">فقط خود دامنه را بنویسید؛ نیازی به //:https و مسیر نیست. ' +
          'برای خالی کردن، کادر را خالی بگذارید و ذخیره کنید.</div></div>' +

      '<div class="fld"><label>توضیح کوتاه برای مشتریان (اختیاری)</label>' +
        '<input id="rbNote" type="text" maxlength="200" placeholder="پشتیبانی ۲۴ ساعته — تلگرام @yourid" ' +
          'value="' + esc(br.note || '') + '"></div>' +

      '<div class="brow"><button class="btn w" id="rbSave" type="button">💾 ذخیرهٔ تنظیمات</button></div>' +

      '<hr class="hr">' +
      '<div class="dg2">🔧 راهنمای اتصال دامنه</div>' +
      '<div class="stp"><b>۱</b><span>در پنل دامنهٔ خود، یک رکورد <code>A</code> بسازید و آن را به آی‌پی سرور فروشگاه وصل کنید.</span></div>' +
      '<div class="stp"><b>۲</b><span>یا یک رکورد <code>CNAME</code> به دامنهٔ اصلی فروشگاه بسازید.</span></div>' +
      '<div class="stp"><b>۳</b><span>تا تکمیل انتشار DNS ممکن است تا ۲۴ ساعت طول بکشد.</span></div>' +
      '<div class="stp"><b>۴</b><span>پس از ذخیره، لینک ساب سرویس‌های جدید و قبلی با دامنهٔ شما نمایش داده می‌شود.</span></div>' +
      '<div class="alert w">⚠️ دامنه باید SSL فعال داشته باشد؛ در غیر این صورت برخی اپلیکیشن‌ها لینک را باز نمی‌کنند.</div>' +
    '</div>';
  }

  /** ذخیرهٔ برند و دامنه */
  function rbSave() {
    var b = $('rbSave');
    if (!b || S.busy) return;

    var brand = ($('rbBrand') || {}).value || '';
    var dom   = ($('rbDom')   || {}).value || '';
    var note  = ($('rbNote')  || {}).value || '';

    S.busy = true;
    b.disabled = true;
    b.innerHTML = '<span class="spin"></span> در حال ذخیره…';

    api('rs_brand', { brand: brand, domain: dom, note: note }).then(function (r) {
      S.busy = false;
      b.disabled = false;
      b.innerHTML = '💾 ذخیرهٔ تنظیمات';

      if (!r || !r.ok) { toast((r && (r.message || r.error)) || 'ذخیره نشد.'); return; }

      toast(r.message || 'ذخیره شد ✅');

      if (S.info) {
        S.info.brand = {
          name: r.brand || '', domain: r.domain || '',
          note: r.note || '', global: (S.info.brand || {}).global || '',
          active: r.active || ''
        };
      }
      render();
    });
  }

  /* ---------- سطل زباله ---------- */
  function tabTrash() {
    if (S.trash === null) {
      api('rs_trash').then(function (r) {
        S.trash = (r && r.ok) ? r : { ok: false, services: [], count: 0, days: 0 };
        if (!r || !r.ok) toast((r && r.message) || 'خواندن سطل زباله ناموفق بود.');
        render();
      });
      return '<div class="card rsc"><div class="sk"></div><div class="sk"></div><div class="sk"></div></div>';
    }

    var t = S.trash, rows = t.services || [];

    var out = '<div class="card rsc"><h3>🗑 سطل زباله</h3>' +
      '<div class="sub">سرویس‌های حذف‌شده تا ' + fmt(t.days || 0) +
      ' روز قابل مشاهده‌اند و سپس برای همیشه پاک می‌شوند.</div>';

    if (!rows.length) {
      out += '<div class="empty"><span class="ic">✨</span>سطل زباله خالی است.</div></div>';
      return out;
    }

    out += '<div class="irow"><span class="k">تعداد</span>' + nb(fmt(t.count || rows.length)) + '</div>' +
      '<div class="brow"><button class="btn gh" id="rtPurgeAll" type="button">🧹 پاک‌سازی کامل</button></div></div>';

    rows.forEach(function (r) {
      out += '<div class="card rsc trow">' +
        '<div class="th"><span class="nm mono ltr">' + esc(r.name || ('#' + r.id)) + '</span>' +
          '<span class="tg">' + fmt(r.purge_in || 0) + ' روز تا حذف</span></div>' +
        '<div class="irow"><span class="k">حجم</span>' + nb(esc(r.volume_txt || '-')) + '</div>' +
        '<div class="irow"><span class="k">مصرف شده</span>' + nb(esc(r.used_txt || '-')) + '</div>' +
        '<div class="irow"><span class="k">تاریخ حذف</span>' + nb(esc(r.deleted_txt || '-')) + '</div>' +
        '<div class="brow"><button class="btn gh" data-tpurge="' + r.id + '" type="button">🗑 پاک کردن این مورد</button></div>' +
      '</div>';
    });

    return out;
  }

  /** پاک‌سازی قطعی */
  function rtPurge(id) {
    if (S.busy) return;
    S.busy = true;

    api('rs_trash_purge', id > 0 ? { id: id } : {}).then(function (r) {
      S.busy = false;
      toast((r && (r.message || r.error)) || 'انجام شد.');
      S.trash = null;
      render();
    });
  }

  /* ======================= رندر ======================= */
  function render() {
    var el = $('app');
    if (!S.info) return;

    if (!S.info.is_reseller) {
      el.innerHTML = '<div class="card"><div class="empty"><span class="ic">🏷</span>' +
        'شما هنوز نمایندهٔ ثبت‌شده نیستید.</div>' +
        '<div class="sub" style="text-align:center">برای دریافت نمایندگی، از داخل ربات گزینهٔ «درخواست نمایندگی» را بزنید.</div>' +
        ((S.boot && S.boot.shop && S.boot.shop.bot)
          ? '<div class="brow" style="margin-top:13px"><a class="btn w" href="https://t.me/' +
            esc(S.boot.shop.bot) + '" target="_blank">🤖 رفتن به ربات</a></div>' : '') +
        '</div>';
      var nb1 = $('navBtn'); if (nb1) nb1.classList.add('hide');
      var nb2 = $('tbBal'); if (nb2) nb2.classList.add('hide');
      var nb3 = $('tbSync'); if (nb3) nb3.classList.add('hide');
      return;
    }

    var body = '';
    if (S.tab === 'svc') body = tabBuild('svc');
    else if (S.tab === 'brand') body = tabBrand();
    else if (S.tab === 'trash') body = tabTrash();
    else if (S.tab === 'plan') body = tabBuild('plan');
    else if (S.tab === 'custom') body = tabBuild('custom');
    else if (S.tab === 'list') body = tabList();
    else if (S.tab === 'wallet') body = tabWallet();
    else if (S.tab === 'money') body = tabMoney();
    else if (S.tab === 'bot') body = tabBot();
    else if (S.tab === 'stock') body = tabStock();
    else body = tabHelp();

    el.innerHTML = hero() + body;
    paintTop();
    try { window.rsScrollTop(); } catch (e) {}

    var g = $('rGb'), d = $('rDay');
    if (g) g.addEventListener('input', priceCalc);
    if (d) d.addEventListener('input', priceCalc);

    var pnl = $('rPanel');
    if (pnl) {
      srvInfo();
      if (pnl.tagName === 'SELECT') {
        pnl.addEventListener('change', function () { srvInfo(); priceCalc(); });
      }
    }

    var sr = $('rSearch');
    if (sr) {
      sr.addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        S.rsQ = this.value;
        var rows = (S.info.services || []).filter(function (x) {
          if (!rsMatch(x, S.rsF || 'all')) return false;
          return !q || String(x.name).toLowerCase().indexOf(q) >= 0;
        });
        $('rRows').innerHTML = rows.length
          ? rows.map(function (x) { return svcCard(x, false); }).join('')
          : '<div class="empty"><span class="ic">🔍</span>موردی یافت نشد.</div>';
      });
    }
  }

  /* ======================= رویدادها ======================= */
  /* ================= ویرایش و حذف کانفیگ ================= */
  function rsFind(id) {
    var rows = (S.info && S.info.services) || [];
    for (var i = 0; i < rows.length; i++) if (Number(rows[i].id) === Number(id)) return rows[i];
    return null;
  }

  function rsReload() {
    api('rs_info').then(function (i) { if (i.ok) { S.info = i; render(); } });
  }

  function rsCloseEditor() {
    var x = $('rEd');
    if (x && x.parentNode) x.parentNode.removeChild(x);
  }

  function rsEditor(id) {
    rsCloseEditor();
    var s = rsFind(id);
    if (!s) { toast('کانفیگ پیدا نشد.'); return; }
    var host = $('sv' + id);
    if (!host) { toast('کارت این کانفیگ پیدا نشد.'); return; }

    var cur = (s.grouped && Number(s.quota_gb) > 0) ? Number(s.quota_gb) : Number(s.volume || 0);
    var h = '<div class="card" id="rEd" style="margin-top:10px">' +
      '<h3>✏️ ویرایش ' + esc(s.name) + '</h3>' +
      '<div class="irow"><span class="k">حجم فعلی</span>' + nb(fmt(cur), 'GB') + '</div>' +
      '<div class="irow"><span class="k">مصرف‌شده</span>' + nb(en(s.used_txt || '0'), '') + '</div>' +
      (s.grouped ? '<div style="font-size:11px;color:var(--mut);line-height:1.85;margin:8px 0">' +
        'این یک بستهٔ چندپنلی است؛ حجم و زمان روی همهٔ سرورهای همان بسته اعمال می‌شود.</div>' : '') +
      '<div class="fld"><label>حجم جدید (گیگابایت)</label>' +
      '<input id="eGb" type="text" inputmode="numeric" value="' + en(String(cur)) + '"></div>' +
      '<div class="fld"><label>افزودن روز (اختیاری)</label>' +
      '<input id="eDay" type="text" inputmode="numeric" value="0"></div>' +
      '<div id="eOut" style="font-size:11.5px;color:var(--mut);line-height:1.9;margin-top:4px">' +
      'مقدارها را تغییر دهید تا هزینه محاسبه شود.</div>' +
      '<div class="brow" style="margin-top:11px">' +
      '<button class="btn" data-apply="' + s.id + '">💾 اعمال و پرداخت</button>' +
      '<button class="btn gh" id="eNo">انصراف</button>' +
      '</div></div>';

    host.insertAdjacentHTML('afterend', h);
    rsQuote(id);
    ['eGb', 'eDay'].forEach(function (k) {
      var f = $(k);
      if (f) f.addEventListener('input', function () { rsQuoteLater(id); });
    });
  }

  var rsQT = null;
  function rsQuoteLater(id) {
    if (rsQT) clearTimeout(rsQT);
    rsQT = setTimeout(function () { rsQuote(id); }, 450);
  }

  function rsVals() {
    return {
      gb: Number(en((($('eGb') || {}).value) || '0')) || 0,
      days: Number(en((($('eDay') || {}).value) || '0')) || 0
    };
  }

  function rsQuote(id) {
    var v = rsVals();
    var o = $('eOut');
    if (o) o.textContent = 'در حال محاسبه…';
    api('rs_svc_quote', { id: id, gb: v.gb, days: v.days }).then(function (r) {
      var b = $('eOut');
      if (!b) return;
      if (!r.ok) { b.innerHTML = '<span style="color:var(--err,#f87171)">' + esc(r.message || 'خطا') + '</span>'; return; }
      var q = r.quote, t = '';
      if (Number(q.add_gb) > 0) t += 'افزایش حجم: ' + fmt(q.add_gb) + ' گیگ<br>';
      if (Number(q.add_gb) < 0) t += 'کاهش حجم: ' + fmt(-q.add_gb) + ' گیگ<br>';
      if (Number(q.add_days) > 0) t += 'افزودن زمان: ' + fmt(q.add_days) + ' روز<br>';
      t += '<b>هزینه: ' + esc(q.cost_txt) + '</b>';
      if (Number(q.refund) > 0) t += ' • عودت: ' + esc(q.refund_txt);
      if (Number(q.cost) === 0 && Number(q.refund) === 0) t += ' (بدون هزینه)';
      if (q.grouped) t += '<br>روی ' + fmt(q.parts) + ' سرور این بسته اعمال می‌شود.';
      b.innerHTML = t;
    });
  }

  /* ================= منوی کشویی و نوار بالا ================= */
  var NAV = { open: false };
  var W = { amount: 0, method: '', tx: 0, img: '', assets: [] };
  var WQ = [100000, 200000, 500000, 1000000, 2000000, 5000000];

  function navOpen() {
    NAV.open = true;
    var d = $('drawer'), c = $('scrim'), b = $('navBtn');
    if (d) d.classList.add('on');
    if (c) c.classList.add('on');
    if (b) b.classList.add('on');
    document.body.classList.add('nav-lock');
  }

  function navClose() {
    NAV.open = false;
    var d = $('drawer'), c = $('scrim'), b = $('navBtn');
    if (d) d.classList.remove('on');
    if (c) c.classList.remove('on');
    if (b) b.classList.remove('on');
    document.body.classList.remove('nav-lock');
  }

  function navToggle() { if (NAV.open) navClose(); else navOpen(); }

  var TNAMES = {
    stock: '🏪 انبار ملی',
    svc: '🧩 سرویس‌های باندلی',
    brand: '🌐 برند و دامنه',
    trash: '🗑 سطل زباله',
    plan: '🎁 طرح آماده', custom: '⚡ حجم کاستوم',
    list: '📦 کانفیگ‌های من', wallet: '💳 شارژ کیف پول',
    money: '📊 داشبورد فروش', bot: '🤖 ربات نمایندگی', help: '📘 راهنما'
  };

  function paintTop() {
    var q = (S.info && S.info.quota) || {};
    var b = $('tbBal');
    if (b) b.innerHTML = '👛 ' + fmt(q.balance || 0);
    var t = $('tbT');
    if (t) t.textContent = TNAMES[S.tab] || 'پنل نمایندگی';
    var ns = document.querySelectorAll('.nvi');
    for (var i = 0; i < ns.length; i++)
      ns[i].classList.toggle('on', ns[i].getAttribute('data-tab') === S.tab);
    paintDrawer();
  }

  /* ---- سرستون و کارت کیف پول درون کشو ---- */
  function paintDrawer() {
    var inf = S.info || {};
    var q = inf.quota || {}, br = inf.brand || {};
    var svcs = inf.services || [];
    var put = function (id, txt) { var el = $(id); if (el) el.textContent = txt; };

    put('dwBal', q.balance_txt || (fmt(q.balance || 0) + ' تومان'));
    put('dwAvl', q.avail_txt || fmt(q.available || 0));
    put('dwSvc', fmt(svcs.length || 0));

    var nm = (br.name || '').trim();
    put('dwName', nm || 'پنل نمایندگی');
    put('dwSub', nm ? (br.domain || 'برند اختصاصی شما') : 'سرویس‌ساز اختصاصی شما');
    put('nbList', svcs.length ? fmt(svcs.length) : '');
  }

  function goTab(tab) {
    if (tab === 'trash') S.trash = null;   /* هر بار تازه خوانده شود */
    if (tab === 'stock') {                 /* موجودی انبار لحظه‌ای است */
      S.stock = null; S.stockMine = null; S.stockErr = ''; S.stockView = 'shop';
    }
    S.tab = tab;
    navClose();
    try { window.scrollTo(0, 0); } catch (e) {}
    render();
  }
  window.rsGoTab = goTab;

  /* ================= خواندن مصرف واقعی از پنل ================= */
  var syncBusy = false;
  function syncUsage(id) {
    if (syncBusy) return;
    syncBusy = true;
    var b = $('tbSync');
    if (b) { b.disabled = true; b.textContent = '⏳'; }
    hap('light');
    api('rs_sync', id ? { id: id } : {}).then(function (r) {
      if (r && r.ok) {
        if (S.info) {
          if (r.services) S.info.services = r.services;
          if (r.stats) S.info.stats = r.stats;
          if (r.quota) S.info.quota = r.quota;
        }
        toast(r.message || 'مصرف به روز شد ✅');
        if (r.services) render(); else rsReload();
      } else {
        toast((r && r.message) || 'به روزرسانی انجام نشد.');
      }
    }).then(function () {
      syncBusy = false;
      if (b) { b.disabled = false; b.textContent = '🔄'; }
    });
  }
  window.rsSyncUsage = syncUsage;

  var syncBtn = $('tbSync');
  if (syncBtn) syncBtn.addEventListener('click', function () { syncUsage(''); });

  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('[data-sync]') : null;
    if (!t) return;
    e.preventDefault();
    syncUsage(t.getAttribute('data-sync'));
  });

  document.addEventListener('input', function (e) {
    if (!e.target || e.target.id !== 'wAmt') return;
    W.amount = parseInt(en(String(e.target.value)).replace(/[^0-9]/g, ''), 10) || 0;
    var q = document.querySelectorAll('#wQ .wqb');
    for (var i = 0; i < q.length; i++)
      q[i].classList.toggle('on', Number(q[i].getAttribute('data-wamt')) === W.amount);
  });

  /* ================= کیف پول اختصاصی نماینده ================= */
  /* ======================= انبار ملی ======================= */

  function stockLoad(force) {
    if (S.stockBusy) return;
    if (S.stock && !force) return;
    S.stockBusy = true;
    S.stockErr  = '';
    api('rs_stock').then(function (r) {
      S.stockBusy = false;
      if (r && r.ok) { S.stock = r; }
      else { S.stockErr = (r && r.message) || 'دریافت انبار ناموفق بود.'; }
      render();
    });
  }

  function stockMineLoad(force) {
    if (S.stockMineBusy) return;
    if (S.stockMine && !force) return;
    S.stockMineBusy = true;
    api('rs_stock_mine').then(function (r) {
      S.stockMineBusy = false;
      S.stockMine = (r && r.ok) ? r : { items: [], profit_txt: '' };
      render();
    });
  }

  /* تأیید سازگار با تلگرام */
  function askYes(msg, cb) {
    try {
      if (TG && typeof TG.showConfirm === 'function') {
        TG.showConfirm(msg, function (ok) { if (ok) cb(); });
        return;
      }
    } catch (e) {}
    if (window.confirm(msg)) cb();
  }

  function stockQty(id, d) {
    var cur = (Number(S.stockQty[id]) || 1) + d;
    if (cur < 1)  cur = 1;
    if (cur > 20) cur = 20;
    S.stockQty[id] = cur;
    hap('light');
    var el = $('sq' + id);
    if (el) el.textContent = fmt(cur);
  }

  function stockBuy(id) {
    if (S.busy) return;

    var list = (S.stock && S.stock.cats) || [];
    var c = null;
    for (var i = 0; i < list.length; i++) {
      if (Number(list[i].id) === Number(id)) { c = list[i]; break; }
    }
    if (!c) return;

    var qty = Number(S.stockQty[id]) || 1;
    var tot = (Number(c.cost) || 0) * qty;

    askYes('خرید ' + fmt(qty) + ' عدد «' + c.name + '»\nپرداخت از کیف پول: ' + fmt(tot) + '\nادامه می‌دهید؟', function () {
      S.busy = true;
      hap('medium');
      var b = $('sb' + id);
      if (b) { b.disabled = true; b.textContent = '⏳ در حال خرید…'; }

      api('rs_stock_buy', { id: id, qty: qty }).then(function (r) {
        S.busy = false;
        if (!r || !r.ok) {
          toast((r && r.message) || 'خرید انجام نشد.');
          render();
          return;
        }
        toast(r.message || 'خرید انجام شد ✅');
        if (r.partial) setTimeout(function () { toast(r.partial); }, 2400);

        S.stockQty[id] = 1;
        S.stock = null;
        S.stockMine = null;
        S.stockView = 'mine';
        stockLoad(true);
        stockMineLoad(true);
      });
    });
  }

  function stockResend(id) {
    hap('light');
    api('stock_resend', { id: id }).then(function (r) {
      toast((r && (r.msg || r.message)) || 'انجام شد.');
    });
  }

  function sCss() {
    return '<style>' +
      '.sseg{display:flex;gap:6px;margin:11px 0 2px}' +
      '.sseg button{flex:1;border:0;border-radius:12px;padding:9px 6px;font:inherit;font-size:11.5px;' +
        'cursor:pointer;background:rgba(255,255,255,.07);color:inherit;' +
        'box-shadow:inset 0 1px 0 rgba(255,255,255,.14)}' +
      '.sseg button.on{background:linear-gradient(160deg,#6C8CFF,#4b6ae0);color:#0B1020;font-weight:800}' +
      '.stk{display:flex;flex-direction:column;gap:10px;margin-top:11px}' +
      '.sk{padding:11px;border-radius:16px;background:rgba(255,255,255,.05);' +
        'box-shadow:inset 0 1px 0 rgba(255,255,255,.10)}' +
      '.sk .top{display:flex;align-items:center;gap:9px}' +
      '.sk .ic{width:38px;height:38px;flex:0 0 38px;display:grid;place-items:center;' +
        'border-radius:12px;background:rgba(108,140,255,.16);font-size:18px}' +
      '.sk .nm{flex:1;min-width:0;font-weight:700;font-size:13px;overflow-wrap:anywhere}' +
      '.sk .nm i{display:block;font-style:normal;font-size:10.5px;opacity:.6;font-weight:500}' +
      '.sk .fr{font-size:10.5px;padding:3px 9px;border-radius:20px;white-space:nowrap;font-weight:700;' +
        'background:rgba(52,211,153,.16);color:#6ee7b7}' +
      '.sk .fr.no{background:rgba(248,113,113,.16);color:#fca5a5}' +
      '.sk .pr{display:flex;gap:6px;margin-top:10px}' +
      '.sk .pc{flex:1;min-width:0;padding:7px 6px;border-radius:11px;' +
        'background:rgba(0,0,0,.20);text-align:center}' +
      '.sk .pc b{display:block;font-size:12px;direction:ltr;white-space:nowrap}' +
      '.sk .pc span{font-size:9.5px;opacity:.6}' +
      '.sk .pc.g b{color:#6ee7b7}' +
      '.sk .act{display:flex;gap:7px;align-items:center;margin-top:10px}' +
      '.qty{display:flex;align-items:center;border-radius:11px;overflow:hidden;background:rgba(0,0,0,.22)}' +
      '.qty button{border:0;background:transparent;color:inherit;font:inherit;' +
        'font-size:16px;width:33px;height:35px;cursor:pointer}' +
      '.qty span{min-width:30px;text-align:center;font-weight:800;font-size:12.5px;direction:ltr}' +
      '.mi{padding:10px;border-radius:14px;background:rgba(255,255,255,.05);margin-bottom:8px}' +
      '.mi .h{display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:700}' +
      '.mi .h em{margin-inline-start:auto;font-style:normal;font-size:10px;opacity:.55;direction:ltr}' +
      '.mi .pl{margin-top:7px;padding:8px;border-radius:10px;background:rgba(0,0,0,.28);' +
        'font-size:10.5px;direction:ltr;word-break:break-all;max-height:76px;overflow:auto}' +
      '.mi .b{display:flex;gap:6px;margin-top:8px;flex-wrap:wrap}' +
      '</style>';
  }

  function tabStock() {
    var d = S.stock;

    if (!d) {
      if (!S.stockErr && !S.stockBusy) stockLoad(false);
      return '<div class="card"><h3>🏪 انبار ملی</h3>' +
        (S.stockErr
          ? '<div class="empty"><span class="ic">⚠️</span>' + esc(S.stockErr) + '</div>' +
            '<div class="brow"><button class="btn" type="button" data-sreload="1">🔄 تلاش دوباره</button></div>'
          : '<div class="empty"><span class="ic">⏳</span>در حال دریافت موجودی انبار…</div>') +
        '</div>';
    }

    if (!d.enabled) {
      return '<div class="card"><h3>🏪 انبار ملی</h3>' +
        '<div class="empty"><span class="ic">🔒</span>' +
        'مدیر هنوز فروش انبار به نمایندگان را فعال نکرده است.</div></div>';
    }

    var cats  = d.cats || [];
    var avail = 0, i;
    for (i = 0; i < cats.length; i++) if ((Number(cats[i].free) || 0) > 0) avail++;

    var h = sCss() + '<div class="card"><h3>' + esc(d.icon || '🏪') + ' ' +
      esc(d.title || 'انبار ملی') +
      '<button class="btn gh" type="button" data-sreload="1"' +
      ' style="margin-inline-start:auto;padding:5px 10px;font-size:11px">🔄</button></h3>';

    if (d.note) h += '<div class="sub">' + esc(d.note) + '</div>';

    h += '<div class="skhero">' +
      '<div class="c"><b>' + esc(d.balance_txt || fmt(d.balance)) + '</b><i>موجودی کیف پول</i></div>' +
      '<div class="c"><b>' + fmt(avail) + '</b><i>بستهٔ موجود</i></div>' +
      '<div class="c"><b>' + fmt(cats.length) + '</b><i>کل بسته‌ها</i></div>' +
      '</div>';

    h += '<div class="sseg">' +
      '<button type="button" data-sview="shop"' + (S.stockView !== 'mine' ? ' class="on"' : '') +
        '>🛒 خرید از انبار</button>' +
      '<button type="button" data-sview="mine"' + (S.stockView === 'mine' ? ' class="on"' : '') +
        '>🎒 خریدهای من</button>' +
      '</div>';

    if (S.stockView === 'mine') return h + stockMineBody() + '</div>';

    if (!cats.length) {
      return h + '<div class="empty"><span class="ic">📦</span>' +
        'فعلاً هیچ بسته‌ای در انبار موجود نیست.</div></div>';
    }

    h += '<div class="stk">';

    for (i = 0; i < cats.length; i++) {
      var c    = cats[i];
      var free = Number(c.free) || 0;
      var qty  = Number(S.stockQty[c.id]) || 1;

      var meta = [];
      if (c.kind_label)       meta.push((c.kind_icon || '🏷') + ' ' + c.kind_label);
      if (Number(c.gb) > 0)   meta.push('📊 ' + fmt(c.gb) + ' گیگ');
      if (Number(c.days) > 0) meta.push('📅 ' + fmt(c.days) + ' روز');
      if (Number(c.mine) > 0) meta.push('🎒 ' + fmt(c.mine) + ' خرید شما');

      h += '<div class="sk v2' + (free < 1 ? ' no' : '') + '">' +
        '<div class="top">' +
          '<span class="ic">' + esc(c.icon || c.kind_icon || '📦') + '</span>' +
          '<span class="nm">' + esc(c.name) + '</span>' +
          '<span class="fr' + (free < 1 ? ' no' : '') + '">' +
            (free < 1 ? '⛔ ناموجود' : '✅ ' + fmt(free) + ' عدد') + '</span>' +
        '</div>';

      if (meta.length) {
        h += '<div class="skm"><span>' +
          meta.map(function (t) { return esc(t); }).join('</span><span>') + '</span></div>';
      }

      if (c.desc) h += '<div class="sub" style="margin-top:8px">' + esc(c.desc) + '</div>';

      h += '<div class="pr">' +
        '<div class="pc"><b>' + esc(c.cost_txt) + '</b><span>خرید شما</span></div>' +
        '<div class="pc"><b>' + esc(c.pub_txt) + '</b><span>قیمت عمومی</span></div>' +
        '<div class="pc g"><b>' + esc(c.profit_txt) + '</b><span>سود شما</span></div>' +
      '</div>';

      if (free > 0) {
        h += '<div class="skb">' +
          '<span class="t">💚 سود شما از فروش هر عدد</span>' +
          '<span class="v">' + esc(c.profit_txt || '—') + '</span></div>';

        h += '<div class="act">' +
          '<div class="qty">' +
            '<button type="button" data-sqty="' + c.id + ':-1">−</button>' +
            '<span id="sq' + c.id + '">' + fmt(qty) + '</span>' +
            '<button type="button" data-sqty="' + c.id + ':1">+</button>' +
          '</div>' +
          '<button class="btn" type="button" id="sb' + c.id + '" style="flex:1"' +
            ' data-sbuy="' + c.id + '">🛒 خرید از انبار</button>' +
        '</div>';
      } else {
        h += '<div class="sub" style="margin-top:10px">⏳ این بسته موقتاً تمام شده؛ ' +
          'پس از شارج مجدد توسط مدیر دوباره قابل خرید می‌شود.</div>';
      }

      h += '</div>';
    }

    return h + '</div></div>';
  }

  function stockMineBody() {
    var m = S.stockMine;

    if (!m) {
      if (!S.stockMineBusy) stockMineLoad(false);
      return '<div class="empty"><span class="ic">⏳</span>در حال دریافت خریدها…</div>';
    }

    var it = m.items || [];
    if (!it.length) {
      return '<div class="empty"><span class="ic">🎒</span>' +
        'هنوز از انبار خریدی نداشته‌اید.</div>';
    }

    var h = '<div class="wrow tot" style="margin-top:10px"><span class="wk">مجموع سود این اقلام</span>' +
      '<span class="wv b">' + esc(m.profit_txt || '') + '</span></div>' +
      '<div style="margin-top:10px">';

    for (var i = 0; i < it.length; i++) {
      var x = it[i];

      h += '<div class="mi">' +
        '<div class="h">' + esc(x.kind_icon || '📦') + ' ' +
          esc(x.title || x.cat || 'قلم انبار') +
          '<em>' + esc(x.date || '') + '</em></div>';

      var sub = [];
      if (x.cat)        sub.push(x.cat);
      if (x.pub_txt)    sub.push('فروش پیشنهادی: ' + x.pub_txt);
      if (x.profit_txt) sub.push('سود: ' + x.profit_txt);
      if (sub.length) h += '<div class="sub">' + esc(sub.join(' · ')) + '</div>';

      if (x.is_file) {
        h += '<div class="sub" style="margin-top:6px">📎 این قلم فایل است و در ربات برای شما ارسال شده.</div>';
      } else if (x.payload) {
        h += '<div class="pl">' + esc(x.payload) + '</div>';
      }

      h += '<div class="b">';
      if (!x.is_file && x.payload) {
        h += '<button class="btn gh" type="button" data-copy="' + esc(x.payload) + '">📋 کپی</button>';
      }
      h += '<button class="btn gh" type="button" data-sresend="' + x.id + '">📤 ارسال دوباره در ربات</button>';
      h += '</div></div>';
    }

    return h + '</div>';
  }

  function tabWallet() {
    var q = (S.info && S.info.quota) || {};
    var f = (S.boot && S.boot.flags) || {};
    var bal = Number(q.balance || 0);

    var h = '<div class="card"><div class="wh">💳 شارژ کیف پول نمایندگی</div>' +
      '<div class="sub">پرداخت کاملاً داخل همین پنل انجام می‌شود و شما را به اپلیکیشن اصلی نمی‌برد.</div>' +
      '<div class="wrow"><span class="wk">موجودی فعلی</span>' +
      '<span class="wv ' + (bal < 0 ? 'r' : 'g') + '">' + esc(q.balance_txt || fmt(bal)) + '</span></div>';

    if (Number(q.debt || 0) > 0)
      h += '<div class="wrow"><span class="wk">بدهی</span><span class="wv r">' + esc(q.debt_txt || fmt(q.debt)) + '</span></div>';
    if (Number(q.credit || 0) > 0)
      h += '<div class="wrow"><span class="wk">اعتبار سطح شما</span><span class="wv">' + esc(q.credit_txt || fmt(q.credit)) + '</span></div>';
    h += '<div class="wrow tot"><span class="wk">قابل مصرف</span><span class="wv b">' +
      esc(q.avail_txt || fmt(q.available || 0)) + '</span></div>';

    h += '<div class="wlbl">۱) مبلغ شارژ را انتخاب کنید</div><div class="wq" id="wQ">';
    for (var i = 0; i < WQ.length; i++)
      h += '<button type="button" class="wqb' + (W.amount === WQ[i] ? ' on' : '') +
        '" data-wamt="' + WQ[i] + '">' + fmt(WQ[i]) + '</button>';
    h += '</div><div class="fld" style="margin-top:9px"><label>یا مبلغ دلخواه</label>' +
      '<input id="wAmt" type="text" inputmode="numeric" dir="ltr" placeholder="0" value="' +
      (W.amount > 0 ? W.amount : '') + '"></div>';

    h += '<div class="wlbl">۲) روش پرداخت</div>';
    var ms = [];
    if (f.card !== false) ms.push(['card', '💳', 'کارت به کارت', 'واریز و آپلود رسید در همین صفحه']);
    if (f.crypto !== false) ms.push(['crypto', '🌐', 'پرداخت ارزی', 'نرخ لحظه‌ای و بررسی خودکار هش']);

    if (!ms.length) {
      h += '<div class="al warn">مدیر هنوز هیچ روش پرداختی را فعال نکرده است.</div>';
    } else {
      for (var m = 0; m < ms.length; m++)
        h += '<button type="button" class="pmc' + (W.method === ms[m][0] ? ' on' : '') +
          '" data-wpm="' + ms[m][0] + '"><span class="pi">' + ms[m][1] + '</span>' +
          '<span class="pt"><b>' + ms[m][2] + '</b><i>' + ms[m][3] + '</i></span></button>';
      h += '<button class="btn" id="wGo" style="width:100%;margin-top:11px">دریافت اطلاعات پرداخت</button>';
    }

    return h + '<div id="wOut"></div></div>';
  }

  function wStart() {
    var wi = $('wAmt');
    if (wi) {
      var v = parseInt(en(String(wi.value)).replace(/[^0-9]/g, ''), 10) || 0;
      if (v > 0) W.amount = v;
    }
    if (!W.amount || W.amount <= 0) { toast('مبلغ شارژ را وارد کنید.'); return; }
    if (!W.method) { toast('روش پرداخت را انتخاب کنید.'); return; }

    var out = $('wOut');
    if (out) out.innerHTML = '<div class="wlbl">در حال آماده‌سازی…</div>';

    api('topup', { amount: W.amount, method: W.method }).then(function (r) {
      if (!r.ok) {
        if (out) out.innerHTML = '<div class="al err">' + esc(r.message || 'انجام نشد.') + '</div>';
        return;
      }
      wPay(r);
    });
  }

  function wPay(r) {
    var h = '<div class="whr"></div>';

    if (r.method === 'card' && r.card) {
      W.tx = Number(r.tx || 0);
      W.img = '';
      h += '<div class="wlbl">۳) مبلغ زیر را به این کارت واریز کنید</div>' +
        '<div class="wbox">' + esc(r.card.number || '') + '</div>' +
        '<button class="btn gh" style="width:100%" data-copy="' + esc(r.card.number || '') + '">📋 کپی شمارهٔ کارت</button>' +
        '<div class="wrow"><span class="wk">به نام</span><span class="wv">' + esc(r.card.holder || '—') + '</span></div>' +
        '<div class="wrow"><span class="wk">بانک</span><span class="wv">' + esc(r.card.bank || '—') + '</span></div>' +
        '<div class="wrow tot"><span class="wk">مبلغ واریز</span><span class="wv b">' +
        esc(r.amount_txt || fmt(W.amount)) + '</span></div>';
      if (W.tx) h += '<div class="wrow"><span class="wk">کد پیگیری</span><span class="wv">#' + esc(String(W.tx)) + '</span></div>';
      h += '<div class="al warn">پس از واریز، عکس رسید را همین‌جا آپلود کنید. نیازی به رفتن به چت ربات نیست.</div>' +
        '<input type="file" id="wRcpF" accept="image/*" style="display:none">' +
        '<div class="wbtns"><button class="btn gh" id="wRcpPick">🖼 انتخاب عکس رسید</button>' +
        '<button class="btn" id="wRcpGo">📤 ارسال رسید</button></div><div id="wRcpP"></div>';
    } else if (r.method === 'crypto') {
      var as = r.assets || [];
      W.assets = as;
      h += '<div class="wlbl">۳) پرداخت ارزی</div>';
      if (r.rate_txt)
        h += '<div class="wrow"><span class="wk">نرخ لحظه‌ای</span><span class="wv">' + esc(r.rate_txt) + '</span></div>';
      h += '<div class="wrow tot"><span class="wk">مبلغ سفارش</span><span class="wv b">' +
        esc(r.amount_txt || fmt(W.amount)) + '</span></div>';

      for (var i = 0; i < as.length; i++) {
        var a = as[i];
        h += '<div class="wlbl">' + esc((a.icon || '') + ' ' + (a.label || a.key || '') +
          (a.network ? ' · ' + a.network : '')) + '</div>';
        if (a.qty_txt)
          h += '<div class="wrow"><span class="wk">مقدار ارسال</span><span class="wv">' + esc(a.qty_txt) + '</span></div>';
        h += '<div class="wbox">' + esc(a.address || '') + '</div>' +
          '<button class="btn gh" style="width:100%" data-copy="' + esc(a.address || '') + '">📋 کپی آدرس</button>';
        if (a.memo)
          h += '<div class="wrow"><span class="wk">Memo / Tag</span><span class="wv">' + esc(a.memo) + '</span></div>';
      }

      if (r.can_hash) {
        var op = '';
        for (var j = 0; j < as.length; j++)
          op += '<option value="' + esc(as[j].key || '') + '">' + esc(as[j].label || as[j].key || '') + '</option>';
        h += '<div class="fld" style="margin-top:12px"><label>شبکهٔ واریز</label><select id="wHxGw">' + op + '</select></div>' +
          '<div class="fld"><label>هش تراکنش (TXID)</label>' +
          '<input id="wHxTx" type="text" dir="ltr" placeholder="0x…"></div>' +
          '<button class="btn" id="wHxGo" style="width:100%">✅ ثبت و بررسی خودکار</button>';
      }
      if (r.note) h += '<div class="al warn">' + esc(r.note) + '</div>';
    }

    var out = $('wOut');
    if (out) out.innerHTML = h;
    var wf = $('wRcpF');
    if (wf) wf.addEventListener('change', wPick);
    try { if (out) out.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); } catch (e) {}
  }

  function wPick(e) {
    var f = e.target.files && e.target.files[0];
    if (!f) return;
    if (f.size > 5 * 1024 * 1024) { toast('حجم عکس باید کمتر از ۵ مگابایت باشد.'); return; }
    var fr = new FileReader();
    fr.onload = function () {
      W.img = String(fr.result || '');
      var pv = $('wRcpP');
      if (pv) pv.innerHTML = '<img class="wimg" src="' + W.img + '" alt="">';
      toast('عکس انتخاب شد ✅');
    };
    fr.readAsDataURL(f);
  }

  function wSendRcp() {
    if (!W.tx) { toast('اول اطلاعات پرداخت را دریافت کنید.'); return; }
    if (!W.img) { toast('اول عکس رسید را انتخاب کنید.'); return; }
    var b = $('wRcpGo');
    if (b) { b.disabled = true; b.textContent = 'در حال ارسال…'; }
    api('topup_receipt', { tx: W.tx, image: W.img }).then(function (r) {
      if (b) { b.disabled = false; b.textContent = '📤 ارسال رسید'; }
      toast(r.message || (r.ok ? 'رسید ارسال شد.' : 'ارسال نشد.'));
      if (!r.ok) return;
      W.img = '';
      var out = $('wOut');
      if (out) out.innerHTML = '<div class="al ok">✅ رسید شما ارسال شد. پس از تایید مدیر، کیف پول شما شارژ می‌شود.</div>';
    });
  }

  function wSendHash() {
    var g = $('wHxGw'), t = $('wHxTx');
    var hx = t ? en(String(t.value)).trim() : '';
    if (hx.length < 10) { toast('هش تراکنش را کامل وارد کنید.'); return; }
    var b = $('wHxGo');
    if (b) { b.disabled = true; b.textContent = 'در حال بررسی…'; }
    api('topup_hash', { amount: W.amount, hash: hx, gw: g ? g.value : '' }).then(function (r) {
      if (b) { b.disabled = false; b.textContent = '✅ ثبت و بررسی خودکار'; }
      toast(r.message || (r.ok ? 'ثبت شد.' : 'انجام نشد.'));
      if (!r.ok) return;
      var out = $('wOut');
      if (out) out.innerHTML = '<div class="al ok">' + esc(r.message || 'ثبت شد.') + '</div>';
    });
  }

  /* ================= ربات اختصاصی نماینده ================= */
  var BOTNEW = false;

  function wbAsk(msg, cb) {
    try {
      if (window.TG && TG.showConfirm) { TG.showConfirm(msg, function (ok) { if (ok) cb(); }); return; }
    } catch (e) {}
    if (window.confirm(msg)) cb();
  }

  function wbGuide() {
    var st = [
      ['١', 'ساخت ربات در تلگرام', 'به @BotFather پیام دهید، دستور /newbot را بزنید و نام و یوزرنیم دلخواه را بدهید.'],
      ['٢', 'کپی توکن', 'توکنی که BotFather می‌دهد مانند 1234567890:AAE… را کامل کپی کنید.'],
      ['٣', 'آیدی عددی مالک', 'به @userinfobot پیام دهید و عدد آیدی خود را بردارید؛ مدیر ربات همان عدد است.'],
      ['٤', 'ثبت و تحویل فوری', 'هر دو مورد را اینجا بگذارید؛ ربات در چند ثانیه خودکار فعال می‌شود.']
    ];
    var h = '<div class="wfeat">';
    for (var i = 0; i < st.length; i++)
      h += '<div class="wf"><span class="wfi">' + st[i][0] + '</span>' +
        '<span class="wft"><b>' + st[i][1] + '</b><i>' + st[i][2] + '</i></span></div>';
    return h + '</div>';
  }

  function wbForm(btnTxt) {
    return '<div class="fld"><label>توکن ربات (از BotFather)</label>' +
      '<input id="wbTok" class="ltr" type="text" dir="ltr" lang="en" autocapitalize="off" ' +
      'autocorrect="off" spellcheck="false" placeholder="1234567890:AAE…"></div>' +
      '<div class="fld"><label>آیدی عددی مالک ربات</label>' +
      '<input id="wbOwn" class="ltr" type="text" inputmode="numeric" dir="ltr" lang="en" placeholder="123456789"></div>' +
      '<button class="btn" id="wBotMake" style="width:100%">' + (btnTxt || '🚀 ساخت خودکار ربات') + '</button>';
  }

  function tabBot() {
    var b = (S.info && S.info.rsbot) || {};
    var q = (S.info && S.info.quota) || {};
    var price = Number(b.price || 0);
    var bot = b.bot || null;

    var h = '<div class="card"><div class="wh">🤖 ربات اختصاصی نمایندگی</div>';

    /* ---------- ربات ساخته شده: کارت وضعیت و مدیریت ---------- */
    if (bot) {
      var st = String(bot.status || '');
      var cls = st === 'active' ? 'ok' : 'warn';
      h += '<div class="al ' + cls + '">' + esc(bot.status_fa || st) +
        (bot.err ? ' — ' + esc(bot.err) : '') + '</div>';

      h += '<div class="wrow"><span class="wk">ربات</span><span class="wv b">@' +
        esc(bot.username || '-') + '</span></div>' +
        '<div class="wrow"><span class="wk">مالک (آیدی عددی)</span><span class="wv">' +
        esc(en(String(bot.owner_id || 0))) + '</span></div>' +
        '<div class="wrow"><span class="wk">کاربران ربات</span><span class="wv g">' +
        esc(en(String(bot.users || 0))) + '</span></div>' +
        '<div class="wrow"><span class="wk">کاربر جدید امروز</span><span class="wv">' +
        esc(en(String(bot.today || 0))) + '</span></div>' +
        '<div class="wrow"><span class="wk">پیام‌های پردازش‌شده</span><span class="wv">' +
        esc(en(String(bot.updates || 0))) + '</span></div>';
      if (bot.last_at)
        h += '<div class="wrow"><span class="wk">آخرین فعالیت</span><span class="wv">' +
          esc(en(String(bot.last_at))) + '</span></div>';

      if (bot.link)
        h += '<a class="btn" href="' + esc(bot.link) + '" target="_blank" rel="noopener" ' +
          'style="width:100%;display:flex;align-items:center;justify-content:center;margin-top:10px">' +
          '↗️ باز کردن ربات من</a>';

      h += '<div class="bgrid">' +
        (st === 'active'
          ? '<button class="btn gh sm" data-bctl="pause">⏸ توقف موقت</button>'
          : '<button class="btn sm" data-bctl="resume">▶️ ��عال‌سازی دوباره</button>') +
        '<button class="btn gh sm" data-bctl="rehook">♻️ تنظیم دوبارهٔ وب‌هوک</button>' +
        '<button class="btn gh sm" data-bctl="health">🩺 بررسی سلامت</button>' +
        '<button class="btn gh sm" data-bctl="rotate">🔑 کلید امن تازه</button>' +
        '<button class="btn gh sm" id="wBotNew">🔁 تغییر توکن</button>' +
        '<button class="btn gh sm" data-bctl="delete">🗑 حذف ربات</button>' +
        '</div>';

      if (BOTNEW)
        h += '<div class="al warn">توکن ربات تازه را وارد کنید؛ ربات قبلی از سرویس خارج می‌شود.</div>' +
          wbForm('🔁 ثبت توکن تازه') +
          '<button class="btn gh sm" id="wBotNewX" style="width:100%;margin-top:6px">انصراف</button>';

      return h + '</div>';
    }

    /* ---------- معرفی و خرید ---------- */
    h += '<div class="sub">با یک‌بار پرداخت، یک ربات فروش کامل به نام خودتان تحویل می‌گیرید و مشتریانتان مستقیم از ربات خودتان خرید می‌کنند.</div>';

    var fs = [
      ['🏷', 'برند خودتان', 'نام، لوگو، متن‌ها و پشتیبانی مخصوص شما'],
      ['⚡', 'تحویل کاملاً خودکار', 'پرداخت کنید، توکن و آیدی بدهید، ربات همان لحظه فعال می‌شود'],
      ['📦', 'مدیریت کامل فروش', 'محصولات، تمدید و کیف پول روی سرورهای مجاز شما'],
      ['🚀', 'مینی‌اپ و اشتراک', 'همین اپلیکیشن سه‌بعدی و لینک اشتراک برای مشتریان شما'],
      ['🛠', 'مدیریت مالکانه', 'فقط آیدی عددی شما مدیر ربات است']
    ];
    h += '<div class="wfeat">';
    for (var i = 0; i < fs.length; i++)
      h += '<div class="wf"><span class="wfi">' + fs[i][0] + '</span>' +
        '<span class="wft"><b>' + fs[i][1] + '</b><i>' + fs[i][2] + '</i></span></div>';
    h += '</div>';

    if (b.note) h += '<div class="al">' + esc(b.note) + '</div>';

    if (!b.enabled) {
      h += '<div class="al warn">فروش ربات اختصاصی در حال حاضر توسط مدیر فعال نشده است. برای هماهنگی با پشتیبانی در تماس باشید.</div>';
      return h + '</div>';
    }

    if (b.paid) {
      if (b.auto) {
        h += '<div class="al ok">✅ هزینهٔ راه‌اندازی تسویه شده است. توکن ربات و آیدی عددی خود را بدهید تا همین حالا ربات ساخته شود.</div>' +
          wbGuide() + wbForm('🚀 ساخت خودکار ربات');
      } else {
        h += '<div class="al ok">✅ درخواست شما ثبت و پرداخت شده است. مدیر به‌زودی ربات شما را راه‌اندازی می‌کند.</div>';
      }
      return h + '</div>';
    }

    h += '<div class="wrow tot"><span class="wk">هزینهٔ راه‌اندازی</span><span class="wv b">' +
      esc(b.price_txt || fmt(price)) + '</span></div>' +
      '<div class="wrow"><span class="wk">موجودی شما</span><span class="wv ' +
      (Number(q.available || 0) >= price ? 'g' : 'r') + '">' +
      esc(q.balance_txt || fmt(q.balance || 0)) + '</span></div>';

    if (price > 0 && Number(q.available || 0) < price)
      h += '<div class="al warn">موجودی کافی نیست؛ از بخش «شارژ کیف پول» اعتبار خود را افزایش دهید.</div>' +
        '<button class="btn" data-tab="wallet" style="width:100%">💳 رفتن به شارژ کیف پول</button>';
    else
      h += '<button class="btn" id="wBotGo" style="width:100%">✅ پرداخت و شروع ساخت ربات</button>';

    return h + '</div>';
  }

  function wbSync(r) {
    if (!S.info) return;
    if (r && r.rsbot) S.info.rsbot = r.rsbot;
    if (r && typeof r.balance !== 'undefined' && S.info.quota) {
      S.info.quota.balance = Number(r.balance || 0);
      S.info.quota.balance_txt = r.balance_txt || '';
      S.info.quota.available = Number(r.balance || 0) + Number(S.info.quota.credit || 0);
      S.info.quota.avail_txt = '';
    }
  }

  function wBotBuy() {
    var b = $('wBotGo');
    if (b) { b.disabled = true; b.textContent = 'در حال پرداخت…'; }
    api('rs_bot_buy', {}).then(function (r) {
      if (b) { b.disabled = false; b.textContent = '✅ پرداخت و شروع ساخت ربات'; }
      toast(r.message || (r.ok ? 'ثبت شد.' : 'انجام نشد.'));
      if (!r.ok) return;
      wbSync(r);
      render();
    });
  }

  function wBotMake() {
    var tk = String((($('wbTok') || {}).value) || '').trim();
    var ow = String((($('wbOwn') || {}).value) || '').replace(/[^0-9]/g, '');
    if (tk.indexOf(':') < 4) { toast('توکن ربات را کامل وارد کنید.'); return; }
    if (ow.length < 5) { toast('آیدی عددی مالک را وارد کنید.'); return; }

    var btn = $('wBotMake');
    var old = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = '⏳ در حال ساخت ربات…'; }
    api('rs_bot_setup', { token: tk, owner_id: ow }).then(function (r) {
      if (btn) { btn.disabled = false; btn.textContent = old; }
      toast(r.message || (r.ok ? 'انجام شد.' : 'انجام نشد.'));
      if (!r.ok) return;
      BOTNEW = false;
      wbSync(r);
      render();
    });
  }

  function wBotDo(act) {
    api('rs_bot_ctl', { act: act }).then(function (r) {
      toast(r.message || (r.ok ? 'انجام شد.' : 'انجام نشد.'));
      wbSync(r);
      if (act === 'delete') BOTNEW = false;
      render();
    });
  }

  function wBotCtl(act) {
    if (act === 'delete') {
      wbAsk('ربات اختصاصی حذف شود؟ پس از حذف می‌توانید توکن دیگری ثبت کنید.', function () { wBotDo('delete'); });
      return;
    }
    if (act === 'pause') {
      wbAsk('ربات موقتاً متوقف شود؟', function () { wBotDo('pause'); });
      return;
    }
    wBotDo(act);
  }

  document.addEventListener('click', function (e) {
    if (e.target.closest('#rbSave')) { hap(); rbSave(); return; }
    if (e.target.closest('#rtPurgeAll')) { hap(); rtPurge(0); return; }

    var tpg = e.target.closest('[data-tpurge]');
    if (tpg) { hap(); rtPurge(Number(tpg.getAttribute('data-tpurge')) || 0); return; }

    /* -------- انبار ملی -------- */
    if (e.target.closest('[data-sreload]')) {
      hap('medium');
      S.stock = null; S.stockMine = null; S.stockErr = '';
      stockLoad(true);
      render();
      return;
    }

    var svw = e.target.closest('[data-sview]');
    if (svw) {
      S.stockView = svw.getAttribute('data-sview');
      hap('light');
      if (S.stockView === 'mine') stockMineLoad(false);
      render();
      return;
    }

    var sqt = e.target.closest('[data-sqty]');
    if (sqt) {
      var pr = String(sqt.getAttribute('data-sqty')).split(':');
      stockQty(Number(pr[0]) || 0, Number(pr[1]) || 0);
      return;
    }

    var sby = e.target.closest('[data-sbuy]');
    if (sby) { stockBuy(Number(sby.getAttribute('data-sbuy')) || 0); return; }

    var srs = e.target.closest('[data-sresend]');
    if (srs) { stockResend(Number(srs.getAttribute('data-sresend')) || 0); return; }

    var t = e.target.closest('[data-tab]');
    if (t) { hap(); goTab(t.getAttribute('data-tab')); return; }

    if (e.target.closest('#navBtn')) { hap(); navToggle(); return; }
    if (e.target.closest('#navX')) { navClose(); return; }
    if (e.target.id === 'scrim') { navClose(); return; }
    if (e.target.closest('#tbBal')) { hap(); goTab('wallet'); return; }

    var wa = e.target.closest('[data-wamt]');
    if (wa) {
      W.amount = Number(wa.getAttribute('data-wamt')) || 0;
      var wi = $('wAmt'); if (wi) wi.value = W.amount;
      var qs = document.querySelectorAll('#wQ .wqb');
      for (var qi = 0; qi < qs.length; qi++)
        qs[qi].classList.toggle('on', qs[qi] === wa);
      hap(); return;
    }

    var wp = e.target.closest('[data-wpm]');
    if (wp) {
      W.method = wp.getAttribute('data-wpm');
      var ps = document.querySelectorAll('.pmc');
      for (var pi = 0; pi < ps.length; pi++)
        ps[pi].classList.toggle('on', ps[pi] === wp);
      hap(); return;
    }

    if (e.target.closest('#wGo')) { hap(); wStart(); return; }
    if (e.target.closest('#wRcpPick')) { var wf2 = $('wRcpF'); if (wf2) wf2.click(); return; }
    if (e.target.closest('#wRcpGo')) { hap(); wSendRcp(); return; }
    if (e.target.closest('#wHxGo')) { hap(); wSendHash(); return; }
    if (e.target.closest('#wBotGo')) { hap(); wBotBuy(); return; }
    if (e.target.closest('#wBotMake')) { hap(); wBotMake(); return; }
    if (e.target.closest('#wBotNew')) { hap(); BOTNEW = true; render(); return; }
    if (e.target.closest('#wBotNewX')) { hap(); BOTNEW = false; render(); return; }
    var bctl = e.target.closest('[data-bctl]');
    if (bctl) { hap(); wBotCtl(bctl.getAttribute('data-bctl')); return; }

    var c = e.target.closest('[data-copy]');
    if (c) { copy(c.getAttribute('data-copy')); return; }

    var ch = e.target.closest('.chip');
    if (ch) {
      var box = ch.parentElement, id = box.getAttribute('data-for'), f = $(id);
      if (f) { f.value = ch.getAttribute('data-v'); priceCalc(); }
      box.querySelectorAll('.chip').forEach(function (x) { x.classList.toggle('on', x === ch); });
      hap(); return;
    }

    var ed = e.target.closest('[data-edit]');
    if (ed) { hap(); rsEditor(+ed.getAttribute('data-edit')); return; }

    if (e.target.closest('#eNo')) { rsCloseEditor(); return; }

    var ap = e.target.closest('[data-apply]');
    if (ap) {
      var aid = +ap.getAttribute('data-apply'), av = rsVals();
      ap.disabled = true; ap.textContent = 'در حال اعمال…';
      api('rs_svc_edit', { id: aid, gb: av.gb, days: av.days }).then(function (r) {
        ap.disabled = false; ap.textContent = '💾 اعمال و پرداخت';
        if (!r.ok) { toast(r.message || 'ویرایش انجام نشد.'); return; }
        toast('کانفیگ به‌روزرسانی شد.');
        rsCloseEditor(); rsReload();
      });
      return;
    }

    var dl = e.target.closest('[data-del]');
    if (dl) {
      var did = +dl.getAttribute('data-del');
      if (S.delAsk !== did) {
        S.delAsk = did;
        dl.textContent = '⚠️ برای حذف دوباره بزنید';
        toast('حذف قطعی است؛ برای تایید دوباره بزنید.');
        return;
      }
      dl.disabled = true; dl.textContent = 'در حال حذف…';
      api('rs_svc_delete', { id: did, confirm: 'yes' }).then(function (r) {
        if (!r.ok) { dl.disabled = false; dl.textContent = '🗑 حذف'; toast(r.message || 'حذف انجام نشد.'); return; }
        S.delAsk = 0;
        toast('کانفیگ حذف شد.');
        rsCloseEditor(); rsReload();
      });
      return;
    }

    if (e.target.closest('#rGo')) { doCreate(); return; }

    var w = e.target.closest('[data-open="wallet"]');
    if (w) { location.href = 'index.php#wallet'; return; }
  });

  /* ورود فقط با ارقام انگلیسی */
  document.addEventListener('beforeinput', function (e) {
    if (!e.data) return;
    var conv = en(e.data);
    if (conv === e.data) return;
    var el = e.target;
    if (!el || !('value' in el)) return;
    e.preventDefault();
    try { el.setRangeText(conv, el.selectionStart, el.selectionEnd, 'end'); }
    catch (x) { el.value = en(el.value) + conv; }
    el.dispatchEvent(new Event('input', { bubbles: true }));
  }, true);

  /* ======================= بارگذاری ======================= */
  Promise.all([api('rs_info'), api('boot')]).then(function (res) {
    var i = res[0], b = res[1];
    if (!i.ok) {
      $('app').innerHTML = '<div class="card"><div class="empty"><span class="ic">⚠️</span>' +
        esc(i.message || 'دریافت اطلاعات ناموفق بود.') + '</div></div>';
      var nb1 = $('navBtn'); if (nb1) nb1.classList.add('hide');
      var nb2 = $('tbBal'); if (nb2) nb2.classList.add('hide');
      return;
    }
    S.info = i;
    S.boot = b.ok ? b : null;
    render();
  });
})();
</script>
<?php endif; ?>

</body>
</html>
