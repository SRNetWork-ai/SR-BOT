<?php

/**
 * صفحهٔ هوشمند ثبت کارت بانکی — مینی‌اپ مستقل
 * از ربات (دکمهٔ web_app) و از مینی‌اپ اصلی (card.php?from=app) باز می‌شود.
 * داده‌ها از api.php (اکشن‌های cards / card_add / card_del) خوانده می‌شوند.
 */

require __DIR__ . '/../app/bootstrap.php';

/* اجازه باز شدن داخل تلگرام (رفع خطای refused to connect) */
if (!headers_sent()) {
    header_remove('X-Frame-Options');
    header("Content-Security-Policy: frame-ancestors https://web.telegram.org https://*.telegram.org https://telegram.org https://*.t.me tg: 'self';");
}

$ready   = false;
$accent  = '#6C8CFF';
$appName = 'ثبت کارت بانکی';
$note    = '';
$bins    = [];

if (!app_installed()) {
    $note = 'ربات هنوز نصب نشده است.';
} else {
    try {
        boot();
        if ((string)DB::setting('miniapp_enabled', '1') !== '1') {
            $note = 'مینی‌اپ در حال حاضر توسط مدیر غیرفعال شده است.';
        } elseif (!class_exists('CardAuth') || !CardAuth::enabled()) {
            $note = 'بخش احراز کارت در حال حاضر غیرفعال است.';
        } else {
            $ready  = true;
            $accent = (string)DB::setting('miniapp_accent', '#6C8CFF');
            $bins   = CardAuth::BINS;
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
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<style>
:root{
  --acc: <?= h($accent) ?>;
  --bg:#0e1015; --card:#161a23; --card2:#1c2130; --line:#2a3140;
  --tx:#e9edf6; --dim:#9aa6bd; --mut:#6d7891;
  --green:#28c76f; --orange:#ff9f43; --red:#ea5455;
  --r:16px; --r2:22px; --ez:cubic-bezier(.22,.9,.3,1);
  --safe-b: env(safe-area-inset-bottom, 0px);
}
html.light{
  --bg:#f3f5fa; --card:#ffffff; --card2:#f6f8fd; --line:#dfe5f0;
  --tx:#141a26; --dim:#5d6a80; --mut:#8996ab;
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;padding:0}
body{
  background:var(--bg); color:var(--tx);
  font-family:Vazirmatn,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  font-size:14px; line-height:1.85;
  padding:0 14px calc(112px + var(--safe-b));
  -webkit-font-smoothing:antialiased;
}
b,strong{font-weight:800}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.6px}

/* ---------- سربرگ ---------- */
.hd{padding:18px 2px 12px;text-align:center}
.hd h1{margin:0;font-size:17px;font-weight:800;letter-spacing:-.2px}
.hd p{margin:5px 0 0;font-size:12px;color:var(--dim)}

/* ---------- کارت سه‌بعدی ---------- */
.cart{
  position:relative; border-radius:20px; padding:16px 18px 14px;
  min-height:172px; color:#fff; overflow:hidden;
  background:linear-gradient(135deg,#3b4b6a,#232a3a);
  box-shadow:0 18px 40px -18px rgba(0,0,0,.75), inset 0 1px 0 rgba(255,255,255,.14);
  transition:background .5s var(--ez), transform .35s var(--ez);
  display:flex; flex-direction:column; justify-content:space-between;
}
.cart::after{
  content:''; position:absolute; inset:0;
  background:radial-gradient(120% 90% at 88% 8%, rgba(255,255,255,.20), transparent 58%);
  pointer-events:none;
}
.cart-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;position:relative;z-index:1}
.cart-bank{font-size:14.5px;font-weight:800;text-shadow:0 1px 3px rgba(0,0,0,.35)}
.cart-sub{font-size:10.5px;opacity:.82;margin-top:1px}
.cart-ok{
  font-size:11px; font-weight:700; padding:3px 9px; border-radius:999px;
  background:rgba(255,255,255,.18); backdrop-filter:blur(6px); white-space:nowrap;
}
.cart-chip{
  width:42px;height:31px;border-radius:7px;margin:6px 0 2px;position:relative;z-index:1;
  background:linear-gradient(135deg,#f6d98a,#c9a24a 55%,#f3e2a8);
  box-shadow:inset 0 0 0 1px rgba(0,0,0,.16);
}
.cart-pan{
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
  direction:ltr; text-align:left; font-size:19.5px; font-weight:700;
  letter-spacing:2.4px; text-shadow:0 2px 6px rgba(0,0,0,.42);
  position:relative; z-index:1; white-space:nowrap; overflow:hidden;
}
.cart-bot{display:flex;align-items:flex-end;justify-content:space-between;gap:10px;position:relative;z-index:1}
.cart-lb{font-size:9px;opacity:.68;letter-spacing:.4px}
.cart-hd{font-size:12.5px;font-weight:700;max-width:190px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* ---------- گام‌ها ---------- */
.steps{display:flex;align-items:center;gap:5px;margin:16px 2px 14px}
.stp{
  flex:1;height:5px;border-radius:999px;background:var(--line);
  transition:background .35s var(--ez);
}
.stp.on{background:var(--acc)}
.stp.dn{background:var(--green)}

/* ---------- کارت‌های محتوا ---------- */
.card{
  background:var(--card); border:1px solid var(--line); border-radius:var(--r);
  padding:16px; margin-bottom:12px;
}
.card.tight{padding:14px}
.sec-t{display:flex;align-items:center;gap:8px;margin:16px 2px 9px;font-size:12.5px;font-weight:800;color:var(--dim)}
.sec-t::after{content:'';flex:1;height:1px;background:var(--line)}

/* ---------- فیلدها ---------- */
.fld{margin-bottom:12px}
.fld:last-child{margin-bottom:0}
.fld label{display:block;font-size:12px;font-weight:700;color:var(--dim);margin-bottom:7px}
.fld input,.fld textarea{
  width:100%; background:var(--card2); border:1.5px solid var(--line);
  border-radius:12px; padding:13px 14px; color:var(--tx);
  font-family:inherit; font-size:15px; outline:none;
  transition:border-color .2s, box-shadow .2s;
}
.fld input:focus{border-color:var(--acc);box-shadow:0 0 0 3.5px color-mix(in srgb,var(--acc) 20%,transparent)}
.fld input.ok{border-color:var(--green)}
.fld input.bad{border-color:var(--red)}
.fld .msg{font-size:11.5px;margin-top:6px;min-height:16px;color:var(--mut)}
.fld .msg.g{color:var(--green)}
.fld .msg.r{color:var(--red)}
.hint{font-size:11.5px;color:var(--mut);line-height:1.8;margin-top:7px}

/* ---------- هشدارها ---------- */
.alert{border-radius:13px;padding:11px 13px;font-size:12.5px;line-height:1.85;margin-bottom:12px;border:1px solid transparent}
.alert.i{background:color-mix(in srgb,var(--acc) 12%,transparent);border-color:color-mix(in srgb,var(--acc) 32%,transparent)}
.alert.w{background:rgba(255,159,67,.13);border-color:rgba(255,159,67,.34);color:var(--orange)}
.alert.e{background:rgba(234,84,85,.13);border-color:rgba(234,84,85,.34);color:var(--red)}
.alert.g{background:rgba(40,199,111,.13);border-color:rgba(40,199,111,.34);color:var(--green)}

/* ---------- دکمه‌ها ---------- */
.btn{
  display:flex;align-items:center;justify-content:center;gap:8px;
  width:100%; border:0; border-radius:13px; padding:14px 16px;
  background:var(--acc); color:#fff; font-family:inherit;
  font-size:14.5px; font-weight:800; cursor:pointer;
  transition:transform .14s var(--ez), opacity .2s;
}
.btn:active{transform:scale(.975)}
.btn[disabled]{opacity:.5;cursor:not-allowed}
.btn.gh{background:var(--card2);color:var(--tx);border:1.5px solid var(--line)}
.btn.dgr{background:rgba(234,84,85,.14);color:var(--red);border:1.5px solid rgba(234,84,85,.3)}
.btn.sm{padding:9px 13px;font-size:12.5px;border-radius:10px;width:auto}

/* ---------- ناوبری پایین ---------- */
.nav{
  position:fixed; inset:auto 0 0 0; z-index:40;
  display:flex; gap:9px; padding:11px 14px calc(11px + var(--safe-b));
  background:color-mix(in srgb,var(--bg) 88%,transparent);
  backdrop-filter:blur(14px); border-top:1px solid var(--line);
}
.nav .btn{flex:1}
.nav .btn.gh{flex:0 0 40%}

/* ---------- کادر عکس ---------- */
.ph{
  border:1.6px dashed var(--line); border-radius:14px; padding:22px 14px;
  text-align:center; font-size:12.5px; color:var(--dim); cursor:pointer;
  background:var(--card2); transition:border-color .2s;
}
.ph:active{border-color:var(--acc)}
.ph img{max-width:100%;max-height:190px;border-radius:11px;display:block;margin:0 auto}

/* ---------- ریزچیپ ---------- */
.chip{
  display:inline-flex;align-items:center;gap:5px;
  padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;
  background:var(--card2);border:1px solid var(--line);color:var(--dim);
}
.chip.g{background:rgba(40,199,111,.14);border-color:rgba(40,199,111,.3);color:var(--green)}
.chip.w{background:rgba(255,159,67,.14);border-color:rgba(255,159,67,.3);color:var(--orange)}
.chip.r{background:rgba(234,84,85,.14);border-color:rgba(234,84,85,.3);color:var(--red)}

/* ---------- جمع‌بندی ---------- */
.sum{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px dashed var(--line);font-size:12.5px}
.sum:last-child{border-bottom:0}
.sum .k{color:var(--dim);flex:0 0 auto}
.sum .v{font-weight:700;text-align:left;direction:ltr;word-break:break-all}

/* ---------- ردیف کارت ذخیره‌شده ---------- */
.crow{display:flex;align-items:center;gap:11px;padding:11px 0;border-bottom:1px solid var(--line)}
.crow:last-child{border-bottom:0}
.crow .ic{
  width:40px;height:40px;flex:0 0 40px;border-radius:11px;
  display:flex;align-items:center;justify-content:center;font-size:17px;
  background:var(--card2);border:1px solid var(--line);
}
.crow .bd{flex:1;min-width:0}
.crow .t{font-size:13px;font-weight:700;direction:ltr;text-align:right;font-family:ui-monospace,Menlo,monospace}
.crow .s{font-size:11px;color:var(--mut);margin-top:2px}
.crow .x{
  border:0;background:rgba(234,84,85,.13);color:var(--red);
  width:32px;height:32px;border-radius:9px;font-size:14px;cursor:pointer;flex:0 0 32px;
}

/* ---------- متفرقه ---------- */
.spin{
  display:inline-block;width:15px;height:15px;border-radius:50%;
  border:2px solid rgba(255,255,255,.3);border-top-color:#fff;
  animation:sp .7s linear infinite;vertical-align:-2px;
}
@keyframes sp{to{transform:rotate(360deg)}}
.toast{
  position:fixed;left:14px;right:14px;bottom:calc(84px + var(--safe-b));z-index:60;
  background:#20263a;color:#fff;border-radius:13px;padding:12px 15px;
  font-size:12.5px;font-weight:700;text-align:center;
  opacity:0;transform:translateY(16px);pointer-events:none;
  transition:all .3s var(--ez);box-shadow:0 12px 30px -10px rgba(0,0,0,.6);
}
.toast.on{opacity:1;transform:translateY(0)}
.toast.ok{background:#17603a}
.toast.err{background:#7d2b2c}
.done-ic{font-size:52px;text-align:center;margin:8px 0 4px;animation:pop .5s var(--ez)}
@keyframes pop{0%{transform:scale(.4);opacity:0}100%{transform:scale(1);opacity:1}}
.center{text-align:center}
</style>
</head>
<body>

<div id="app"></div>
<div id="toast" class="toast"></div>
<input type="file" id="photoInput" accept="image/png,image/jpeg,image/webp" style="display:none">

<script>
window.__CARD_CFG__ = {
  ready: <?= $ready ? 'true' : 'false' ?>,
  note:  <?= json_encode($note, JSON_UNESCAPED_UNICODE) ?>,
  bins:  <?= json_encode($bins, JSON_UNESCAPED_UNICODE) ?>
};
</script>

<script>
/* ===== Card Studio v1 ===== */
(function () {
  'use strict';

  var BOOT = window.__CARD_CFG__ || {};
  var TG   = (window.Telegram && window.Telegram.WebApp) ? window.Telegram.WebApp : null;
  var INIT = TG ? (TG.initData || '') : '';
  var BINS = BOOT.bins || {};
  var FROM_APP = /[?&]from=app/.test(location.search);

  /* وقتی این صفحه از داخل مینی‌اپ اصلی باز می‌شود، تلگرام initData تازه نمی‌سازد؛
     همان مقداری که مینی‌اپ در آدرس گذاشته است خوانده می‌شود تا کاربر شناخته شود. */
  if (!INIT) {
    var mInit = /[#&?](?:tgWebAppData|init)=([^&]+)/.exec(String(location.hash || '') + '&' + String(location.search || ''));
    if (mInit) {
      try { INIT = decodeURIComponent(mInit[1]); } catch (eInit) { INIT = mInit[1]; }
    }
  }

  /* ================= کمکی ================= */
  function $(id) { return document.getElementById(id); }

  function en(s) {
    var fa1 = '۰۱۲۳۴۵۶۷۸۹', ar = '٠١٢٣٤٥٦٧٨٩';
    return String(s == null ? '' : s).replace(/[۰-۹٠-٩]/g, function (d) {
      var i = fa1.indexOf(d);
      if (i < 0) i = ar.indexOf(d);
      return i < 0 ? d : String(i);
    });
  }

  function fa(s) {
    var d = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    return String(s == null ? '' : s).replace(/[0-9]/g, function (n) { return d[+n]; });
  }

  function digits(s) { return en(s).replace(/[^0-9]/g, ''); }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function hap(kind) {
    try {
      if (!TG || !TG.HapticFeedback) return;
      if (kind === 'ok') TG.HapticFeedback.notificationOccurred('success');
      else if (kind === 'err') TG.HapticFeedback.notificationOccurred('error');
      else TG.HapticFeedback.impactOccurred('light');
    } catch (e) {}
  }

  var toastT = null;
  function toast(msg, kind) {
    var t = $('toast');
    if (!t) return;
    t.textContent = String(msg == null ? '' : msg);
    t.className = 'toast on' + (kind === 'ok' ? ' ok' : (kind === 'err' ? ' err' : ''));
    hap(kind === 'ok' ? 'ok' : (kind === 'err' ? 'err' : ''));
    clearTimeout(toastT);
    toastT = setTimeout(function () { t.className = 'toast'; }, 3000);
  }

  function api(action, data) {
    var body = { action: action };
    if (data) {
      for (var k in data) {
        if (Object.prototype.hasOwnProperty.call(data, k)) body[k] = data[k];
      }
    }
    return fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-TG-Init-Data': INIT },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.text().then(function (tx) {
        try { return JSON.parse(tx); }
        catch (e) { return { ok: false, message: 'پاسخ سرور معتبر نبود.' }; }
      });
    }).catch(function () {
      return { ok: false, message: 'ارتباط با سرور برقرار نشد.' };
    });
  }

  /* ================= اعتبارسنجی ================= */
  function luhn(pan) {
    var d = digits(pan);
    if (d.length !== 16) return false;
    var sum = 0;
    for (var i = 0; i < 16; i++) {
      var n = +d.charAt(i);
      if (i % 2 === 0) { n *= 2; if (n > 9) n -= 9; }
      sum += n;
    }
    return sum % 10 === 0;
  }

  function shebaOk(sheba) {
    var d = digits(sheba);
    if (d.length !== 24) return false;
    var re = d.slice(2) + '1827' + d.slice(0, 2);
    var rem = 0;
    for (var i = 0; i < re.length; i++) {
      rem = (rem * 10 + (+re.charAt(i))) % 97;
    }
    return rem === 1;
  }

  function bankOf(pan) {
    var d = digits(pan);
    if (d.length < 6) return '';
    return BINS[d.slice(0, 6)] || '';
  }

  var BRAND = {
    'ملی':      ['#0f7a4d', '#0a5c39'],
    'ملت':      ['#c0392b', '#8e2620'],
    'صادرات':   ['#1e5fa8', '#123f73'],
    'تجارت':    ['#1f7ab8', '#155a88'],
    'سپه':      ['#1f8a70', '#12604d'],
    'رفاه':     ['#2452a3', '#183874'],
    'کشاورزی':  ['#0e7d55', '#0a5a3c'],
    'مسکن':     ['#1a6fb5', '#114d80'],
    'پست':      ['#1d8348', '#146134'],
    'توسعه':    ['#2c6fa8', '#1d4d76'],
    'صنعت':     ['#25507e', '#18365a'],
    'پارسیان':  ['#b32a3d', '#7d1c2b'],
    'پاسارگاد': ['#c99a2e', '#8f6c17'],
    'سامان':    ['#1c6fa0', '#124e73'],
    'سینا':     ['#1f7a5c', '#135240'],
    'سرمایه':   ['#4a5b8c', '#323f66'],
    'اقتصاد':   ['#2f6f9f', '#1e4b70'],
    'دی':       ['#2f8f6f', '#1e6350'],
    'انصار':    ['#2b7a78', '#1c5250'],
    'شهر':      ['#b03a5b', '#7c2740'],
    'آینده':    ['#8e44ad', '#63307a'],
    'گردشگری':  ['#0f8ea8', '#0a6376'],
    'ایران زمین': ['#3b6ea5', '#274b73'],
    'خاو��میانه': ['#25708a', '#194d5f'],
    'قوامین':   ['#2d6a4f', '#1b4634'],
    'مهر':      ['#2e7d5b', '#1d5540'],
    'رسالت':    ['#2b6cb0', '#1d4a7a'],
    'کارآفرین': ['#4b6cb7', '#324a80'],
    'حکمت':     ['#3a6f8f', '#264a61'],
    'نور':      ['#356fa3', '#234b70'],
    'ایران ونزوئلا': ['#4a5568', '#2d3648']
  };

  function brandOf(bank) {
    if (!bank) return ['#3b4b6a', '#232a3a'];
    for (var key in BRAND) {
      if (Object.prototype.hasOwnProperty.call(BRAND, key) && bank.indexOf(key) !== -1) {
        return BRAND[key];
      }
    }
    return ['#3b4b6a', '#232a3a'];
  }

  /* ================= وضعیت ================= */
  var ST = {
    cfg: null, cards: [], loading: true, busy: false,
    step: 0, keys: [], done: null,
    pan: '', holder: '', sheba: '', photo: ''
  };

  var TITLES = {
    pan:    ['شمارهٔ کارت', 'شمارهٔ ۱۶ رقمی کارت بانکی خود را وارد کنید'],
    holder: ['صاحب کارت', 'نام و نام خانوادگی دقیقاً مطابق اسناد بانکی'],
    sheba:  ['شمارهٔ شبا', 'شبای ۲۴ رقمی متعلق به همین کارت'],
    photo:  ['تصویر کارت', 'یک عکس واضح از روی کارت بانکی'],
    review: ['بازبینی و ثبت', 'اطلاعات را بررسی کنید و ثبت را بزنید']
  };

  /* ================= نمای کارت ================= */
  function paintArt() {
    var art = $('cardArt');
    if (!art) return;

    var d = digits(ST.pan);
    var bank = bankOf(d);
    var g = brandOf(bank);
    art.style.background = 'linear-gradient(135deg,' + g[0] + ',' + g[1] + ')';

    var b = $('artBank');
    if (b) b.textContent = bank || 'بانک شناسایی نشده';
    var sb = $('artSub');
    if (sb) sb.textContent = bank ? 'کارت بانکی ایران' : 'شش رقم اول را وارد کنید';

    var okEl = $('artOk');
    if (okEl) {
      if (d.length < 16) { okEl.style.display = 'none'; }
      else {
        okEl.style.display = '';
        okEl.textContent = luhn(d) ? '✓ معتبر' : '✕ نامعتبر';
      }
    }

    var parts = [], i;
    for (i = 0; i < 4; i++) {
      var seg = d.substr(i * 4, 4);
      while (seg.length < 4) seg += '•';
      parts.push(seg);
    }
    var pe = $('artPan');
    if (pe) pe.textContent = parts.join('  ');

    var he = $('artHolder');
    if (he) he.textContent = ST.holder ? ST.holder : 'نام صاحب کارت';
  }

  /* ================= گام‌ها ================= */
  function buildSteps() {
    var c = ST.cfg || {};
    var k = ['pan'];
    if (c.ask_holder) k.push('holder');
    if (c.ask_sheba) k.push('sheba');
    if (c.want_photo) k.push('photo');
    k.push('review');
    ST.keys = k;
    if (ST.step >= k.length) ST.step = k.length - 1;
  }

  function stepKey() { return ST.keys[ST.step] || 'pan'; }

  function stepsHtml() {
    var h = '';
    for (var i = 0; i < ST.keys.length; i++) {
      var cl = i < ST.step ? ' dn' : (i === ST.step ? ' on' : '');
      h += '<div class="stp' + cl + '"></div>';
    }
    return '<div class="steps">' + h + '</div>';
  }

  /* ================= مشکلات هر گام ================= */
  function stepProblem(key) {
    var c = ST.cfg || {};
    if (key === 'pan') {
      var d = digits(ST.pan);
      if (d.length !== 16) return 'شمارهٔ کارت باید دقیقاً ۱۶ رقم باشد.';
      if (c.luhn !== false && !luhn(d)) return 'این شمارهٔ کارت از نظر ریاضی معتبر نیست.';
      return '';
    }
    if (key === 'holder') {
      if (ST.holder.trim().length < 5) return 'نام و نام خانوادگی را کامل بنویسید.';
      return '';
    }
    if (key === 'sheba') {
      var s = digits(ST.sheba);
      if (c.ask_sheba && s.length === 0) return 'شمارهٔ شبا الزامی است.';
      if (s.length > 0 && s.length !== 24) return 'شبا باید ۲۴ رقم باشد (بدون IR).';
      if (s.length === 24 && !shebaOk(s)) return 'شمارهٔ شبا معتبر نیست.';
      return '';
    }
    if (key === 'photo') {
      if (c.card_photo && !ST.photo) return 'بارگذاری تصویر کارت الزامی است.';
      return '';
    }
    return '';
  }

  function allProblems() {
    var out = [];
    for (var i = 0; i < ST.keys.length; i++) {
      var k = ST.keys[i];
      if (k === 'review') continue;
      var p = stepProblem(k);
      if (p) out.push(p);
    }
    return out;
  }

  /* ================= نماهای گام ================= */
  function viewPan() {
    var d = digits(ST.pan);
    var bank = bankOf(d);
    var msg = '', cls = '';
    if (d.length === 0) { msg = 'مثال: ۶۰۳۷ ۹۹۱۱ ۲۲۳۳ ۴۴۵۵'; }
    else if (d.length < 16) { msg = fa(String(d.length)) + ' رقم از ۱۶ رقم'; }
    else if (!luhn(d)) { msg = '✕ شمارهٔ کارت معتبر نیست — دوباره بررسی کنید'; cls = 'r'; }
    else { msg = '✓ شمارهٔ کارت معتبر است' + (bank ? ' — بانک ' + bank : ''); cls = 'g'; }

    var inpCls = d.length === 16 ? (luhn(d) ? 'ok' : 'bad') : '';
    var pretty = d.replace(/(.{4})/g, '$1 ').trim();

    return '<div class="card">' +
      '<div class="fld">' +
      '<label>شمارهٔ ۱۶ رقمی کارت</label>' +
      '<input id="fPan" class="mono ' + inpCls + '" type="text" inputmode="numeric" dir="ltr" ' +
      'maxlength="19" placeholder="6037 9911 2233 4455" value="' + esc(pretty) + '">' +
      '<div class="msg ' + cls + '" id="mPan">' + esc(msg) + '</div>' +
      '</div>' +
      '<div class="hint">🏦 بانک به‌صورت خودکار از روی شش رقم اول تشخیص داده می‌شود.<br>' +
      '⛔️ فقط شمارهٔ کارت لازم است؛ CVV2، رمز دوم، رمز پویا و تاریخ انقضا هرگز پرسیده نمی‌شود.</div>' +
      '</div>';
  }

  function viewHolder() {
    var ok = ST.holder.trim().length >= 5;
    return '<div class="card">' +
      '<div class="fld">' +
      '<label>نام و نام خانوادگی صاحب کارت</label>' +
      '<input id="fHolder" class="' + (ST.holder ? (ok ? 'ok' : 'bad') : '') + '" type="text" ' +
      'placeholder="مطابق اسناد بانکی" value="' + esc(ST.holder) + '">' +
      '<div class="msg ' + (ST.holder ? (ok ? 'g' : 'r') : '') + '" id="mHolder">' +
      (ST.holder ? (ok ? '✓ ثبت شد' : 'نام را کامل‌تر بنویسید') : 'مثال: علی رضایی') +
      '</div></div>' +
      '<div class="hint">اگر نام با صاحب حساب بانکی یکی نباشد، کارت رد می‌شود.</div>' +
      '</div>';
  }

  function viewSheba() {
    var s = digits(ST.sheba);
    var msg = '', cls = '', inpCls = '';
    if (s.length === 0) { msg = '۲۴ رقم، بدون IR'; }
    else if (s.length < 24) { msg = fa(String(s.length)) + ' رقم از ۲۴ رقم'; }
    else if (!shebaOk(s)) { msg = '✕ شبا معتبر نیست'; cls = 'r'; inpCls = 'bad'; }
    else { msg = '✓ شبا معتبر است'; cls = 'g'; inpCls = 'ok'; }

    return '<div class="card">' +
      '<div class="fld">' +
      '<label>شمارهٔ شبا (بدون IR)</label>' +
      '<input id="fSheba" class="mono ' + inpCls + '" type="text" inputmode="numeric" dir="ltr" ' +
      'maxlength="24" placeholder="123456789012345678901234" value="' + esc(s) + '">' +
      '<div class="msg ' + cls + '" id="mSheba">' + esc(msg) + '</div>' +
      '</div>' +
      '<div class="hint">شبا باید متعلق به همین کارت باشد. حرف‌های «IR» را وارد نکنید.</div>' +
      '</div>';
  }

  function viewPhoto() {
    var c = ST.cfg || {};
    var inner = ST.photo
      ? '<img src="' + ST.photo + '" alt=""><div class="hint" style="margin-top:8px">✅ تصویر انتخاب شد — برای تغییر بزنید</div>'
      : '📷 برای انتخاب تصویر بزنید<div class="hint" style="margin-top:6px">JPG یا PNG — حداکثر ۴ مگابایت</div>';

    return '<div class="card">' +
      '<div class="fld">' +
      '<label>تصویر کارت' + (c.card_photo ? ' — الزامی' : ' — اختیاری') + '</label>' +
      '<div class="ph" id="phBox">' + inner + '</div>' +
      '<div class="msg" id="mPhoto"></div>' +
      '</div>' +
      (ST.photo ? '<button type="button" class="btn gh sm" id="phClear">🗑 حذف تصویر</button>' : '') +
      '<div class="hint">فقط روی کارت لازم است. پشت کارت و CVV2 را ارسال نکنید.</div>' +
      '</div>';
  }

  function viewReview() {
    var c = ST.cfg || {};
    var d = digits(ST.pan);
    var bank = bankOf(d) || '—';
    var probs = allProblems();

    var h = '';
    if (probs.length) {
      h += '<div class="alert e"><b>پیش از ثبت این موارد را اصلاح کنید:</b><br>• ' +
        probs.map(esc).join('<br>• ') + '</div>';
    } else {
      h += '<div class="alert g">✅ همه‌چیز آمادهٔ ثبت است.</div>';
    }

    h += '<div class="card">' +
      '<div class="sum"><span class="k">شمارهٔ کارت</span><span class="v mono">' +
        esc(d.replace(/(.{4})/g, '$1 ').trim() || '—') + '</span></div>' +
      '<div class="sum"><span class="k">بانک</span><span class="v">' + esc(bank) + '</span></div>';

    if (c.ask_holder) {
      h += '<div class="sum"><span class="k">صاحب کارت</span><span class="v">' +
        esc(ST.holder || '—') + '</span></div>';
    }
    if (c.ask_sheba) {
      h += '<div class="sum"><span class="k">شبا</span><span class="v mono">IR' +
        esc(digits(ST.sheba) || '—') + '</span></div>';
    }
    if (c.want_photo) {
      h += '<div class="sum"><span class="k">تصویر کارت</span><span class="v">' +
        (ST.photo ? 'پیوست شد ✅' : 'ندارد') + '</span></div>';
    }

    h += '<div class="sum"><span class="k">وضعیت پس از ثبت</span><span class="v">' +
      (c.auto ? 'بلافاصله فعال می‌شود' : 'بررسی توسط مدیر') + '</span></div>' +
      '</div>';

    h += '<div class="alert i">⛔️ به یاد داشته باشید: هیچ‌گاه CVV2، رمز دوم، رمز پویا یا ' +
      'تاریخ انقضای کارت خود را در اختیار کسی — حتی پشتیبانی — قرار ندهید.</div>';

    return h;
  }

  function viewDone() {
    var r = ST.done || {};
    var pend = String(r.status || '') !== 'approved';
    return '<div class="card">' +
      '<div class="done-ic">' + (pend ? '⏳' : '🎉') + '</div>' +
      '<div class="center" style="font-size:15px;font-weight:800;margin-bottom:6px">' +
        (pend ? 'کارت شما ثبت شد' : 'کارت شما تایید شد') + '</div>' +
      '<div class="center" style="font-size:12.5px;color:var(--dim)">' +
        esc(r.msg || (pend ? 'پس از بررسی مدیر فعال می‌شود.' : 'هم‌اکنون قابل استفاده است.')) +
      '</div></div>';
  }

  /* ================= کارت‌های ثبت‌شده ================= */
  function cardsBlock() {
    if (!ST.cards.length) return '';
    var h = '<div class="sec-t"><span>💳 کارت‌های شما</span></div><div class="card tight">';
    for (var i = 0; i < ST.cards.length; i++) {
      var c = ST.cards[i];
      var st = String(c.status || '');
      var ic = st === 'approved' ? '✅' : (st === 'pending' ? '⏳' : '❌');
      h += '<div class="crow">' +
        '<div class="ic">' + ic + '</div>' +
        '<div class="bd"><div class="t">' + esc(c.mask || c.pan || '') + '</div>' +
        '<div class="s">' + esc(c.bank || '—') + ' · ' + esc(c.label || st) + '</div></div>' +
        '<button type="button" class="x" data-del="' + esc(String(c.id)) + '">🗑</button>' +
        '</div>';
    }
    return h + '</div>';
  }

  /* ================= رندر ================= */
  function render() {
    var app = $('app');
    if (!app) return;

    if (!BOOT.ready) {
      app.innerHTML = '<div class="hd"><h1>ثبت کارت بانکی</h1></div>' +
        '<div class="alert w">' + esc(BOOT.note || 'این صفحه در حال حاضر در دسترس نیست.') + '</div>';
      return;
    }

    if (ST.loading) {
      app.innerHTML = '<div class="hd"><h1>ثبت کارت بانکی</h1></div>' +
        '<div class="card center" style="padding:30px">' +
        '<span class="spin" style="border-color:var(--line);border-top-color:var(--acc)"></span>' +
        '<div style="margin-top:10px;font-size:12.5px;color:var(--dim)">در حال آماده‌سازی…</div></div>';
      return;
    }

    var c = ST.cfg || {};

    /* پایان کار */
    if (ST.done) {
      app.innerHTML = '<div class="hd"><h1>ثبت کارت بانکی</h1></div>' +
        viewDone() + cardsBlock() +
        '<div class="nav">' +
        '<button type="button" class="btn gh" id="goBack">↩️ بازگشت</button>' +
        '<button type="button" class="btn" id="goMore">➕ کارت دیگر</button>' +
        '</div>';
      bindDone();
      return;
    }

    /* ظرفیت پر است */
    var maxC = +(c.max || 1);
    if (!(maxC >= 1)) maxC = 1;
    var act  = +(c.active || 0);
    var left = Math.max(0, maxC - act);

    if (left <= 0) {
      app.innerHTML = '<div class="hd"><h1>ثبت کارت بانکی</h1>' +
        '<p>ظرفیت شما: ' + fa(String(act)) + ' از ' + fa(String(maxC)) + ' کارت فعال</p></div>' +
        '<div class="alert w">🔒 سقف تعداد کارت‌های مجاز (' + fa(String(maxC)) +
        ' کارت) پر شده است. برای ثبت کارت تازه، یکی از کارت‌های زیر را حذف کنید.</div>' +
        cardsBlock() +
        '<div class="nav"><button type="button" class="btn gh" id="goBack">↩️ بازگشت</button></div>';
      bindDone();
      return;
    }

    buildSteps();
    var key = stepKey();
    var ttl = TITLES[key] || ['ثبت کارت', ''];

    var body = '';
    if (key === 'pan') body = viewPan();
    else if (key === 'holder') body = viewHolder();
    else if (key === 'sheba') body = viewSheba();
    else if (key === 'photo') body = viewPhoto();
    else body = viewReview();

    var last = ST.step >= ST.keys.length - 1;
    var nextTxt = last ? '✅ ثبت نهایی کارت' : 'ادامه ↩';

    app.innerHTML =
      '<div class="hd"><h1>' + esc(ttl[0]) + '</h1><p>' + esc(ttl[1]) + '</p></div>' +
      artHtml() +
      stepsHtml() +
      (c.guide ? '<div class="alert i">ℹ️ ' + esc(c.guide) + '</div>' : '') +
      body +
      '<div class="center"><span class="chip">📦 ظرفیت باقی‌مانده: ' +
        fa(String(left)) + ' از ' + fa(String(maxC)) + '</span></div>' +
      cardsBlock() +
      '<div class="nav">' +
      '<button type="button" class="btn gh" id="btnPrev">' +
        (ST.step === 0 ? '↩️ بازگشت' : '‹ قبلی') + '</button>' +
      '<button type="button" class="btn" id="btnNext">' + nextTxt + '</button>' +
      '</div>';

    paintArt();
    bindStage(key);
  }

  function artHtml() {
    return '<div class="cart" id="cardArt">' +
      '<div class="cart-top">' +
      '<div><div class="cart-bank" id="artBank">بانک شناسایی نشده</div>' +
      '<div class="cart-sub" id="artSub">شش رقم اول را وارد کنید</div></div>' +
      '<div class="cart-ok" id="artOk" style="display:none"></div>' +
      '</div>' +
      '<div class="cart-chip"></div>' +
      '<div class="cart-pan" id="artPan">••••  ••••  ••••  ••••</div>' +
      '<div class="cart-bot">' +
      '<div><div class="cart-lb">CARD HOLDER</div>' +
      '<div class="cart-hd" id="artHolder">نام صاحب کارت</div></div>' +
      '</div></div>';
  }

  /* ================= اتصال رویدادها ================= */
  function bindDone() {
    var b = $('goBack');
    if (b) b.addEventListener('click', leave);
    var m = $('goMore');
    if (m) {
      m.addEventListener('click', function () {
        hap();
        ST.done = null; ST.step = 0;
        ST.pan = ''; ST.holder = ''; ST.sheba = ''; ST.photo = '';
        reload();
      });
    }
    bindDelete();
  }

  function bindDelete() {
    var list = document.querySelectorAll('[data-del]');
    Array.prototype.forEach.call(list, function (b) {
      b.addEventListener('click', function () {
        var id = b.getAttribute('data-del');
        if (!confirm('این کارت حذف شود؟')) return;
        hap();
        b.disabled = true;
        api('card_del', { id: +id }).then(function (r) {
          if (!r.ok) { toast(r.message || r.msg || 'حذف نشد.', 'err'); b.disabled = false; return; }
          toast(r.msg || 'کارت حذف شد.', 'ok');
          reload();
        });
      });
    });
  }

  function bindStage(key) {
    var p = $('btnPrev');
    if (p) p.addEventListener('click', function () { ST.step === 0 ? leave() : prev(); });
    var n = $('btnNext');
    if (n) n.addEventListener('click', next);

    bindDelete();

    if (key === 'pan') {
      var fp = $('fPan');
      if (fp) {
        fp.addEventListener('input', function () {
          var d = digits(fp.value).slice(0, 16);
          ST.pan = d;
          var pos = fp.selectionStart;
          var atEnd = pos >= fp.value.length;
          fp.value = d.replace(/(.{4})/g, '$1 ').trim();
          if (!atEnd) { try { fp.setSelectionRange(pos, pos); } catch (e) {} }
          liveCheckPan();
          paintArt();
        });
        setTimeout(function () { try { fp.focus(); } catch (e) {} }, 120);
      }
    } else if (key === 'holder') {
      var fh = $('fHolder');
      if (fh) {
        fh.addEventListener('input', function () {
          ST.holder = fh.value;
          var ok = ST.holder.trim().length >= 5;
          fh.className = ST.holder ? (ok ? 'ok' : 'bad') : '';
          var m = $('mHolder');
          if (m) {
            m.className = 'msg ' + (ST.holder ? (ok ? 'g' : 'r') : '');
            m.textContent = ST.holder ? (ok ? '✓ ثبت شد' : 'نام را کامل‌تر بنویسید') : 'مثال: علی رضایی';
          }
          paintArt();
        });
      }
    } else if (key === 'sheba') {
      var fs = $('fSheba');
      if (fs) {
        fs.addEventListener('input', function () {
          var d = digits(fs.value).slice(0, 24);
          ST.sheba = d;
          fs.value = d;
          var m = $('mSheba');
          var ok = d.length === 24 && shebaOk(d);
          fs.className = 'mono ' + (d.length === 24 ? (ok ? 'ok' : 'bad') : '');
          if (m) {
            if (d.length === 0) { m.className = 'msg'; m.textContent = '۲۴ رقم، بدون IR'; }
            else if (d.length < 24) { m.className = 'msg'; m.textContent = fa(String(d.length)) + ' رقم از ۲۴ رقم'; }
            else if (!ok) { m.className = 'msg r'; m.textContent = '✕ شبا معتبر نیست'; }
            else { m.className = 'msg g'; m.textContent = '✓ شبا معتبر است'; }
          }
        });
      }
    } else if (key === 'photo') {
      var box = $('phBox'), inp = $('photoInput');
      if (box && inp) {
        box.addEventListener('click', function () { inp.click(); });
        inp.onchange = function () {
          var fl = inp.files && inp.files[0];
          if (!fl) return;
          if (fl.size > 8388608) { toast('حجم تصویر خیلی زیاد است.', 'err'); inp.value = ''; return; }
          var m = $('mPhoto');
          if (m) m.textContent = 'در حال پردازش تصویر…';
          shrink(fl, function (durl) {
            inp.value = '';
            if (!durl) { toast('تصویر خوانده نشد.', 'err'); return; }
            ST.photo = durl;
            toast('تصویر پیوست شد.', 'ok');
            render();
          });
        };
      }
      var cl = $('phClear');
      if (cl) {
        cl.addEventListener('click', function () {
          ST.photo = ''; hap(); render();
        });
      }
    }
  }

  function liveCheckPan() {
    var d = digits(ST.pan);
    var m = $('mPan'), fp = $('fPan');
    var bank = bankOf(d);
    if (!m) return;
    if (d.length === 0) { m.className = 'msg'; m.textContent = 'مثال: ۶۰۳۷ ۹۹۱۱ ۲۲۳۳ ۴۴۵۵'; }
    else if (d.length < 16) { m.className = 'msg'; m.textContent = fa(String(d.length)) + ' رقم از ۱۶ رقم' + (bank ? ' — بانک ' + bank : ''); }
    else if (!luhn(d)) { m.className = 'msg r'; m.textContent = '✕ شمارهٔ کارت معتبر نیست — دوباره بررسی کنید'; }
    else { m.className = 'msg g'; m.textContent = '✓ شمارهٔ کارت معتبر است' + (bank ? ' — بانک ' + bank : ''); }
    if (fp) fp.className = 'mono ' + (d.length === 16 ? (luhn(d) ? 'ok' : 'bad') : '');
  }

  /* ================= فشرده‌سازی تصویر ================= */
  function shrink(file, cb) {
    var fr = new FileReader();
    fr.onerror = function () { cb(''); };
    fr.onload = function () {
      var src = String(fr.result || '');
      var img = new Image();
      img.onerror = function () { cb(src); };
      img.onload = function () {
        try {
          var mx = 1400;
          var w = img.width, hgt = img.height;
          if (w > mx || hgt > mx) {
            var sc = Math.min(mx / w, mx / hgt);
            w = Math.round(w * sc); hgt = Math.round(hgt * sc);
          }
          var cv = document.createElement('canvas');
          cv.width = w; cv.height = hgt;
          cv.getContext('2d').drawImage(img, 0, 0, w, hgt);
          cb(cv.toDataURL('image/jpeg', 0.82));
        } catch (e) { cb(src); }
      };
      img.src = src;
    };
    fr.readAsDataURL(file);
  }

  /* ================= حرکت بین گام‌ها ================= */
  function goStep(i) {
    if (i < 0) i = 0;
    if (i > ST.keys.length - 1) i = ST.keys.length - 1;
    ST.step = i;
    render();
    try { window.scrollTo(0, 0); } catch (e) {}
  }

  function prev() { hap(); goStep(ST.step - 1); }

  function next() {
    var key = stepKey();
    if (key !== 'review') {
      var p = stepProblem(key);
      if (p) { toast(p, 'err'); return; }
      hap();
      goStep(ST.step + 1);
      return;
    }
    submit();
  }

  function leave() {
    hap();
    /* بازگشت به مینی‌اپ اصلی همراه initData تا صفحهٔ مقصد هم کاربر را بشناسد */
    var back = 'index.php' + (INIT ? '?init=' + encodeURIComponent(INIT) : '') + '#cards';
    if (FROM_APP) { location.href = back; return; }
    try { if (TG && TG.close) { TG.close(); return; } } catch (e) {}
    location.href = back;
  }

  /* ================= ثبت ================= */
  function submit() {
    if (ST.busy) return;
    var probs = allProblems();
    if (probs.length) { toast(probs[0], 'err'); return; }

    var btn = $('btnNext');
    ST.busy = true;
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spin"></span> در حال ثبت…'; }

    var d = { pan: digits(ST.pan) };
    if (ST.holder) d.holder = ST.holder.trim();
    if (digits(ST.sheba)) d.sheba = digits(ST.sheba);
    if (ST.photo) d.photo = ST.photo;

    api('card_add', d).then(function (r) {
      ST.busy = false;
      if (!r.ok) {
        toast(r.msg || r.message || 'ثبت نشد.', 'err');
        if (btn) { btn.disabled = false; btn.innerHTML = '✅ ثبت نهایی کارت'; }
        return;
      }
      hap('ok');
      ST.done = { msg: r.msg || '', status: r.status || 'pending' };
      reload();
    });
  }

  /* ================= بارگذاری ================= */
  function reload() {
    ST.loading = !ST.cfg;
    render();
    return api('cards').then(function (r) {
      ST.loading = false;
      if (!r.ok) {
        var app = $('app');
        if (app) {
          app.innerHTML = '<div class="hd"><h1>ثبت کارت بانکی</h1></div>' +
            '<div class="alert e">' + esc(r.message || 'دریافت اطلاعات ناموفق بود.') + '</div>';
        }
        return;
      }
      if (!r.enabled) {
        var a2 = $('app');
        if (a2) {
          a2.innerHTML = '<div class="hd"><h1>ثبت کارت بانکی</h1></div>' +
            '<div class="alert w">احراز کارت در حال حاضر غیرفعال است.</div>';
        }
        return;
      }
      ST.cfg = r;
      ST.cards = r.cards || [];
      buildSteps();
      render();
    });
  }

  /* ================= شروع ================= */
  function start() {
    try {
      if (TG) {
        TG.ready();
        TG.expand();
        if (TG.colorScheme === 'light') document.documentElement.classList.add('light');
        if (TG.BackButton) {
          TG.BackButton.show();
          TG.BackButton.onClick(function () {
            if (ST.done || ST.step === 0) leave();
            else prev();
          });
        }
      }
    } catch (e) {}

    if (!BOOT.ready) { render(); return; }
    reload();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
</script>
</body>
</html>
