<?php
/**
 * لینک اشتراک اختصاصی ربات (ساب مشترک چندپنلی)
 *
 * آدرس: /sub.php?id=<group_key|sub_id>
 *
 * همهٔ کانفیگ‌های یک «سرویس» را – حتی اگر روی چند پنل مختلف ساخته شده باشند –
 * داخل یک لینک ساب واحد تحویل می‌دهد. حجم و انقضا برای کل گروه مشترک است.
 *
 * پارامتر fmt:
 *   (خالی)  → تشخیص خودکار: برای کلاینت‌ها base64 و برای مرورگر صفحهٔ اطلاعات
 *   base64  → خروجی استاندارد اشتراک
 *   raw     → کانفیگ‌ها به صورت متن ساده
 *   json    → اطلاعات مصرف و فهرست کانفیگ‌ها
 *   html    → صفحهٔ اطلاعات مصرف
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

if (!app_installed()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('');
}

boot();

header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Expose-Headers: Subscription-Userinfo, Profile-Update-Interval, Profile-Title, Profile-Web-Page-Url');

/*
 * کد اشتراک از هر دو شکل آدرس خوانده می شود:
 *   لینک زیبا  →  BASE/sub/CODE          (توسط .htaccess بازنویسی می شود)
 *   لینک قدیم  →  BASE/sub.php?id=CODE
 */
$key = trim((string)($_GET['id'] ?? ($_GET['code'] ?? ($_GET['c'] ?? ''))));
if ($key === '') {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $uri = (string)parse_url($uri, PHP_URL_PATH);
    if (preg_match('~/sub/([A-Za-z0-9_\-]{6,64})~', rawurldecode($uri), $m)) $key = $m[1];
}
$fmt = strtolower(trim((string)($_GET['fmt'] ?? '')));

/* پسوند مستقیم روی لینک زیبا:  BASE/sub/CODE/json */
if ($fmt === '' && preg_match('~/sub/[A-Za-z0-9_\-]{6,64}/(json|raw|html|base64)~i',
        rawurldecode((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)), $m2)) {
    $fmt = strtolower($m2[1]);
}

if ($key === '' || !preg_match('/^[A-Za-z0-9_\-]{6,64}$/', $key)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('');
}

/* اول گروه چندپنلی، بعد تک‌سرویس با همان شناسه */
$rows = [];
foreach (['group_key', 'sub_id'] as $col) {
    try {
        $rows = DB::all('SELECT * FROM {p}services WHERE `' . $col . '` = :k AND status <> :d ORDER BY id ASC',
            [':k' => $key, ':d' => 'deleted']);
    } catch (Throwable $e) {
        /* اگر مایگریشن ۰۰۱۱ اجرا نشده باشد ستون group_key وجود ندارد */
        $rows = [];
    }
    if ($rows) break;
}

if (!$rows) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('');
}

$grouped = trim((string)($rows[0]['group_key'] ?? '')) !== '';

/*
 * مصرف زنده از خود پنل
 *
 * پیش از این، فقط مقدار ذخیره شده در پایگاه داده خوانده می شد و اگر کران
 * روی هاست اجرا نمی شد، مصرف همیشه صفر می ماند. الان هر بار که کلاینت
 * لینک را به روز می کند، مصرف واقعی از پنل خوانده می شود (با کنترل نرخ).
 */
if (class_exists('Svc') && method_exists('Svc', 'syncStale')) {
    try {
        $rows = Svc::syncStale($rows, (int)DB::setting('sub_sync_ttl', '120'), 8);
    } catch (Throwable $e) {
        app_log('sub', 'live sync: ' . $e->getMessage(), ['key' => $key]);
    }
}

/* حجم مشترک، مصرف کل و نزدیک‌ترین انقضا */
$quotaGb = 0.0;
$used    = 0;
$expire  = '';
$active  = false;
$upBytes = 0;

foreach ($rows as $r) {
    $quotaGb = max($quotaGb, (float)($r['group_quota_gb'] ?? 0));
    $used   += (int)($r['used_bytes'] ?? 0);

    $ex = trim((string)($r['expire_at'] ?? ''));
    if ($ex !== '' && ($expire === '' || $ex < $expire)) $expire = $ex;

    if ((string)($r['status'] ?? '') === 'active') $active = true;
}

/*
 * اگر سهمیهٔ گروهی ثبت نشده باشد (سرویس‌های قبل از نسخهٔ ۱.۴) از حجم خود
 * سرویس‌ها حساب می‌کنیم. در حالت گروهی حجم مشترک است، پس بزرگ‌ترین مقدار
 * ملاک است نه جمع آن‌ها.
 */
if ($quotaGb <= 0) {
    foreach ($rows as $r) {
        $v = (float)($r['volume_gb'] ?? 0);
        $quotaGb = $grouped ? max($quotaGb, $v) : $quotaGb + $v;
    }
}

$totalBytes = $quotaGb > 0 ? (int)gb2bytes($quotaGb) : 0;
$expireTs   = $expire !== '' ? (int)strtotime($expire) : 0;

$overQuota = $totalBytes > 0 && $used >= $totalBytes;
$expired   = $expireTs > 0 && $expireTs < time();
$ok        = $active && !$overQuota && !$expired;

$shopTitle = trim((string)DB::setting('shop_title', APP_BRAND));
if ($shopTitle === '') $shopTitle = APP_BRAND;

/* لینک زیبای خود این اشتراک */
$subUrl  = class_exists('Svc') && method_exists('Svc', 'subLinkFor')
    ? Svc::subLinkFor($key)
    : app_url('/sub.php?id=' . rawurlencode($key));
if ($subUrl === '') $subUrl = app_url('/sub.php?id=' . rawurlencode($key));
$htmlUrl = $subUrl . (strpos($subUrl, '?') === false ? '?fmt=html' : '&fmt=html');
$dlUrl   = app_url('/sub.php?id=' . rawurlencode($key) . '&fmt=dl');
$rawUrl  = app_url('/sub.php?id=' . rawurlencode($key) . '&fmt=raw');

/* هدر استاندارد اشتراک: حجم و انقضا داخل خود کلاینت نمایش داده می‌شود */
header('Subscription-Userinfo: upload=' . $upBytes . '; download=' . $used . '; total=' . $totalBytes . '; expire=' . $expireTs);
header('Profile-Update-Interval: 6');
header('Profile-Title: base64:' . base64_encode($shopTitle));
header('Profile-Web-Page-Url: ' . $htmlUrl);

/*
 * کانفیگ ها — اول ساب اصلی خود پنل اسکن می شود
 *
 * پیش از این، متن ذخیره شده زمان ساخت تحویل داده می شد؛ اگر مدیر مشخصات
 * اینباند (دامنه، پورت، SNI ، مسیر) را عوض می کرد، کانفیگ اشتباه تحویل
 * می شد. الان لیست زنده از ساب پنل گرفته می شود و فقط خطوط سالم می مانند.
 */
$links = [];
foreach ($rows as $r) {
    $part = [];
    if (class_exists('Svc') && method_exists('Svc', 'liveConfigs')) {
        try { $part = Svc::liveConfigs($r); }
        catch (Throwable $e) { app_log('sub', 'scan: ' . $e->getMessage(), ['svc' => (int)($r['id'] ?? 0)]); }
    }
    if (!$part) {
        foreach (preg_split('/\r?\n/', (string)($r['config_link'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') $part[] = $line;
        }
    }
    foreach ($part as $line) $links[] = $line;
}

/* فیلتر نهایی سلامت: فقط کانفیگ های معتبر با میزبان مشخص */
$links = array_values(array_filter(array_unique($links), static function ($l): bool {
    $l = trim((string)$l);
    if ($l === '' || mb_strlen($l) < 20) return false;
    if (!preg_match('~^(vless|vmess|trojan|ss|ssr|hy2|hysteria2?|tuic|wireguard|socks)://~i', $l)) return false;
    /* کانفیگ های ناقص بدون میزبان یا پورت کنار گذاشته می شوند */
    if (stripos($l, 'vmess://') === 0) return true;
    return (bool)preg_match('~@[^\s/:@]+:\d{1,5}~', $l);
}));

/* تشخیص مرورگر از کلاینت */
$ua     = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
$isClient = (bool)preg_match(
    '/v2ray|xray|clash|sing-box|singbox|hiddify|nekobox|nekoray|shadowrocket|streisand|foxray|v2box|sagernet|matsuri|surfboard|stash|quantumult|loon|karing|husi|throne|curl|wget|okhttp|go-http|python-requests/i',
    $ua
);
$isBrowser = !$isClient && (stripos($accept, 'text/html') !== false || stripos($ua, 'mozilla') !== false);

if ($fmt === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo jenc([
        'ok'        => $ok,
        'title'     => $shopTitle,
        'status'    => $ok ? 'active' : ($expired ? 'expired' : ($overQuota ? 'over_quota' : 'disabled')),
        'total'     => $totalBytes,
        'used'      => $used,
        'remaining' => $totalBytes > 0 ? max(0, $totalBytes - $used) : null,
        'total_gb'  => round($quotaGb, 3),
        'expire'    => $expireTs,
        'expire_at' => $expire,
        'parts'     => count($rows),
        'grouped'   => $grouped,
        'configs'   => $ok ? $links : [],
    ]);
    exit;
}

if ($fmt === 'html' || ($fmt === '' && $isBrowser)) {
    header('Content-Type: text/html; charset=utf-8');

    /*
     * خروجی base64 همین‌جا ساخته می‌شود تا دکمهٔ «دریافت خروجی اشتراک»
     * بدون درخواست تازه و بدون وابستگی به بازنویسی آدرس کار کند.
     */
    $b64      = base64_encode(implode("\n", $links) . "\n");
    $fileName = (string)preg_replace('/[^A-Za-z0-9_\-]/', '', $key);
    if ($fileName === '') $fileName = 'subscription';
    $fileName .= '.txt';

    /* برنامه‌های اتصال — از پنل ادمین قابل مدیریت است (کلید sub_apps) */
    $apps    = [];
    $rawApps = trim((string)DB::setting('sub_apps', ''));
    if ($rawApps !== '') {
        $tmpApps = json_decode($rawApps, true);
        if (is_array($tmpApps)) $apps = $tmpApps;
    }
    if (!$apps) {
        $apps = [
            ['n' => 'Happ', 'o' => 'اندروید، آیفون، ویندوز و مک', 'i' => '💜', 'u' => 'https://happ.su/', 'p' => [
                ['n' => '🤖 اندروید',       'u' => 'https://play.google.com/store/apps/details?id=com.happproxy'],
                ['n' => '🍏 آیفون و آیپد',  'u' => 'https://apps.apple.com/app/happ-proxy-utility/id6504287215'],
                ['n' => '🖥 ویندوز',         'u' => 'https://happ.su/main/downloads'],
                ['n' => '🍎 مک',              'u' => 'https://happ.su/main/downloads'],
                ['n' => '📺 اندروید تی‌وی', 'u' => 'https://happ.su/main/downloads'],
            ]],
            ['n' => 'v2rayNG', 'o' => 'اندروید', 'i' => '🤖', 'u' => 'https://github.com/2dust/v2rayNG/releases/latest', 'p' => [
                ['n' => '🤖 اندروید (APK)', 'u' => 'https://github.com/2dust/v2rayNG/releases/latest'],
                ['n' => '🤖 اندروید (گوگل پلی)', 'u' => 'https://play.google.com/store/apps/details?id=com.v2ray.ang'],
            ]],
            ['n' => 'NekoBox', 'o' => 'اندروید', 'i' => '🐱', 'u' => 'https://github.com/MatsuriDayo/NekoBoxForAndroid/releases/latest', 'p' => [
                ['n' => '🤖 اندروید (APK)', 'u' => 'https://github.com/MatsuriDayo/NekoBoxForAndroid/releases/latest'],
            ]],
            ['n' => 'Streisand', 'o' => 'آیفون، آیپد و مک', 'i' => '🍏', 'u' => 'https://apps.apple.com/app/streisand/id6450534064', 'p' => [
                ['n' => '🍏 آیفون و آیپد', 'u' => 'https://apps.apple.com/app/streisand/id6450534064'],
                ['n' => '🍎 مک',             'u' => 'https://apps.apple.com/app/streisand/id6450534064'],
            ]],
            ['n' => 'Shadowrocket', 'o' => 'آیفون و آیپد', 'i' => '🚀', 'u' => 'https://apps.apple.com/app/shadowrocket/id932747118', 'p' => [
                ['n' => '🍏 آیفون و آیپد (پولی)', 'u' => 'https://apps.apple.com/app/shadowrocket/id932747118'],
            ]],
            ['n' => 'V2Box', 'o' => 'آیفون و مک', 'i' => '📦', 'u' => 'https://apps.apple.com/app/v2box-v2ray-client/id6446814690', 'p' => [
                ['n' => '🍏 آیفون و آیپد', 'u' => 'https://apps.apple.com/app/v2box-v2ray-client/id6446814690'],
                ['n' => '🍎 مک',             'u' => 'https://apps.apple.com/app/v2box-v2ray-client/id6446814690'],
            ]],
            ['n' => 'v2rayN', 'o' => 'ویندوز و لینوکس', 'i' => '🖥', 'u' => 'https://github.com/2dust/v2rayN/releases/latest', 'p' => [
                ['n' => '🖥 ویندوز', 'u' => 'https://github.com/2dust/v2rayN/releases/latest'],
                ['n' => '🐧 لینوکس', 'u' => 'https://github.com/2dust/v2rayN/releases/latest'],
            ]],
            ['n' => 'Hiddify', 'o' => 'همهٔ سیستم‌ها', 'i' => '🌐', 'u' => 'https://github.com/hiddify/hiddify-next/releases/latest', 'p' => [
                ['n' => '🤖 اندروید', 'u' => 'https://github.com/hiddify/hiddify-next/releases/latest'],
                ['n' => '🍏 آیفون و آیپد', 'u' => 'https://hiddify.com/app/'],
                ['n' => '🖥 ویندوز', 'u' => 'https://github.com/hiddify/hiddify-next/releases/latest'],
                ['n' => '🍎 مک',      'u' => 'https://github.com/hiddify/hiddify-next/releases/latest'],
                ['n' => '🐧 لینوکس', 'u' => 'https://github.com/hiddify/hiddify-next/releases/latest'],
            ]],
            ['n' => 'Clash Verge', 'o' => 'ویندوز، مک و لینوکس', 'i' => '⚡', 'u' => 'https://github.com/clash-verge-rev/clash-verge-rev/releases/latest', 'p' => [
                ['n' => '🖥 ویندوز', 'u' => 'https://github.com/clash-verge-rev/clash-verge-rev/releases/latest'],
                ['n' => '🍎 مک',      'u' => 'https://github.com/clash-verge-rev/clash-verge-rev/releases/latest'],
                ['n' => '🐧 لینوکس', 'u' => 'https://github.com/clash-verge-rev/clash-verge-rev/releases/latest'],
            ]],
        ];
    }
    $appsOk = [];
    foreach ($apps as $ap0) {
        if (!is_array($ap0)) continue;
        $u0 = trim((string)($ap0['u'] ?? ''));
        $n0 = trim((string)($ap0['n'] ?? ''));
        if ($n0 === '') continue;
        if ($u0 !== '' && !preg_match('~^https?://~i', $u0)) $u0 = '';

        /* پلتفرم‌های پشتیبانی‌شده (کلید p) — هر پلتفرم یک لینک دانلود */
        $pf = [];
        foreach ((array)($ap0['p'] ?? []) as $p0) {
            if (!is_array($p0)) continue;
            $pn = trim((string)($p0['n'] ?? ''));
            $pu = trim((string)($p0['u'] ?? ''));
            if ($pn === '' || $pu === '' || !preg_match('~^https?://~i', $pu)) continue;
            $pf[] = ['n' => mb_substr($pn, 0, 40), 'u' => $pu];
            if (count($pf) >= 8) break;
        }
        if (!$pf) {
            if ($u0 === '') continue;
            $lbl = trim((string)($ap0['o'] ?? ''));
            $pf  = [['n' => $lbl === '' ? '⬇️ دانلود' : mb_substr($lbl, 0, 40), 'u' => $u0]];
        }
        if ($u0 === '') $u0 = (string)$pf[0]['u'];

        $i0 = trim((string)($ap0['i'] ?? ''));
        $appsOk[] = [
            'n' => mb_substr($n0, 0, 40),
            'o' => mb_substr(trim((string)($ap0['o'] ?? '')), 0, 40),
            'i' => $i0 === '' ? '📱' : mb_substr($i0, 0, 4),
            'u' => $u0,
            'p' => $pf,
        ];
    }
    $apps = $appsOk;

    $pct   = $totalBytes > 0 ? min(100, (int)round($used * 100 / $totalBytes)) : 0;
    $leftB = $totalBytes > 0 ? max(0, $totalBytes - $used) : 0;

    if ($ok)             { $stCls = 'ok';   $stTxt = '✅ فعال'; }
    elseif ($expired)    { $stCls = 'warn'; $stTxt = '⏳ منقضی شده'; }
    elseif ($overQuota)  { $stCls = 'err';  $stTxt = '📛 حجم تمام شده'; }
    else                 { $stCls = 'err';  $stTxt = '⛔️ غیرفعال'; }

    $barCls = $pct >= 90 ? 'hi' : ($pct >= 70 ? 'md' : '');
    ?>
<?php
    /* ---- انتخاب تم صفحهٔ اشتراک ---- */
    $subTheme    = class_exists('SubTheme') ? SubTheme::current() : 'aurora';
    $subScheme   = class_exists('SubTheme') ? SubTheme::scheme($subTheme) : 'dark';
    $subThemeCss = ($subTheme !== 'aurora' && class_exists('SubTheme'))
        ? SubTheme::css($subTheme)
        : '';
    if ($subTheme !== 'aurora' && trim($subThemeCss) === '') {
        $subTheme  = 'aurora';
        $subScheme = 'dark';
    }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" data-sub-theme="<?= h($subTheme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="<?= h($subScheme) ?>">
<title><?= h($shopTitle) ?> – اشتراک</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css">
<?php if ($subTheme !== 'aurora'): ?>
<style>
<?= $subThemeCss ?>
</style>
<?php else: ?>
<style>
  :root{
    --bg:#06070b; --ink:#eaf2ff; --dim:#93a2c4; --mut:#6a789e;
    --red:#ff2d55; --red2:#ff7d95; --blue:#22d3ff; --blue2:#3d7bff;
    --ok:#22c55e; --warn:#f59e0b; --err:#ff3b5c;
    --line:rgba(120,165,255,.20);
    --glass:linear-gradient(158deg,rgba(24,28,42,.94),rgba(9,11,18,.94));
    --ez:cubic-bezier(.22,.61,.36,1);
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0}
  body{
    font-family:Vazirmatn,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    color:var(--ink); background:var(--bg); min-height:100vh; line-height:1.75;
    padding:20px 14px calc(30px + env(safe-area-inset-bottom));
    -webkit-tap-highlight-color:transparent; -webkit-font-smoothing:antialiased;
    background-image:
      radial-gradient(920px 540px at 80% -10%, rgba(255,45,85,.22), transparent 62%),
      radial-gradient(780px 480px at 10% 4%, rgba(34,211,255,.18), transparent 60%),
      radial-gradient(760px 700px at 50% 116%, rgba(61,123,255,.16), transparent 62%),
      linear-gradient(#06070b,#04050a);
    background-attachment:fixed;
  }
  body::before{
    content:""; position:fixed; inset:0; z-index:0; pointer-events:none;
    background:
      linear-gradient(rgba(34,211,255,.05) 1px, transparent 1px) 0 0/100% 36px,
      linear-gradient(90deg, rgba(255,45,85,.045) 1px, transparent 1px) 0 0/36px 100%;
    -webkit-mask-image:linear-gradient(#000,transparent 76%);
    mask-image:linear-gradient(#000,transparent 76%);
  }
  body::after{
    content:""; position:fixed; left:0; right:0; top:-160px; height:160px; z-index:0; pointer-events:none;
    background:linear-gradient(180deg,transparent,rgba(34,211,255,.10),rgba(255,45,85,.06),transparent);
    animation:scan 8s linear infinite;
  }
  @keyframes scan{0%{transform:translateY(0)}100%{transform:translateY(calc(100vh + 320px))}}

  .wrap{position:relative; z-index:1; max-width:580px; margin:0 auto; perspective:1400px}

  .card{
    position:relative; margin:0 0 15px; padding:17px 16px; border-radius:22px;
    background:var(--glass); border:1px solid var(--line);
    box-shadow:
      0 30px 60px -34px rgba(0,0,0,.98),
      0 10px 22px -18px rgba(255,45,85,.45),
      inset 0 1px 0 rgba(255,255,255,.07),
      inset 0 -18px 40px -30px rgba(34,211,255,.55);
    transform:rotateX(.7deg) translateZ(0); transform-style:preserve-3d;
    transition:transform .55s var(--ez), box-shadow .55s var(--ez);
    overflow:hidden; backdrop-filter:blur(9px);
  }
  .card::before{
    content:""; position:absolute; inset:0; border-radius:22px; pointer-events:none;
    background:linear-gradient(122deg, rgba(255,255,255,.10), transparent 30%, transparent 68%, rgba(34,211,255,.12));
  }
  .card::after{
    content:""; position:absolute; left:16px; right:16px; top:0; height:2px; border-radius:3px; pointer-events:none;
    background:linear-gradient(90deg,transparent,var(--red),#fff3,var(--blue),transparent);
    box-shadow:0 0 18px rgba(255,45,85,.65), 0 0 26px rgba(34,211,255,.5);
    animation:live 4.5s ease-in-out infinite;
  }
  @keyframes live{0%,100%{opacity:.55}50%{opacity:1}}
  .card:hover{transform:rotateX(0) translateY(-3px)}
  .card.hi{border-color:rgba(34,211,255,.34)}

  .top{display:flex; align-items:center; gap:11px; margin-bottom:6px}
  .logo{
    flex:0 0 auto; width:44px; height:44px; border-radius:15px; display:flex; align-items:center; justify-content:center;
    font-size:21px; color:#fff; position:relative;
    background:linear-gradient(145deg,#ff2d55,#8b0f28 55%,#0b1020);
    box-shadow:0 12px 24px -12px rgba(255,45,85,.9), inset 0 1px 0 rgba(255,255,255,.45), inset 0 -6px 12px rgba(0,0,0,.55);
    animation:float 5.5s ease-in-out infinite;
  }
  @keyframes float{0%,100%{transform:translateY(0) rotate(-1deg)}50%{transform:translateY(-4px) rotate(1.5deg)}}
  .tt{font-size:16px; font-weight:800; letter-spacing:.2px;
    background:linear-gradient(90deg,#fff,#bfe6ff 45%,#ffb3c3);
    -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent}
  .st{
    display:inline-flex; align-items:center; gap:6px; margin-inline-start:auto;
    font-size:11.5px; font-weight:700; padding:5px 11px; border-radius:999px; white-space:nowrap;
    border:1px solid var(--line); background:rgba(10,14,24,.75);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.08), 0 8px 18px -12px #000;
  }
  .st.ok{color:#8ef5b8; border-color:rgba(34,197,94,.45); box-shadow:0 0 16px -4px rgba(34,197,94,.55), inset 0 1px 0 rgba(255,255,255,.08)}
  .st.warn{color:#ffd79a; border-color:rgba(245,158,11,.45); box-shadow:0 0 16px -4px rgba(245,158,11,.5), inset 0 1px 0 rgba(255,255,255,.08)}
  .st.err{color:#ffb3c1; border-color:rgba(255,45,85,.5); box-shadow:0 0 18px -4px rgba(255,45,85,.6), inset 0 1px 0 rgba(255,255,255,.08)}

  /* ---------- 3D neon progress bar ---------- */
  .bar{
    position:relative; height:26px; margin:15px 0 12px; border-radius:16px; overflow:hidden;
    background:linear-gradient(180deg,#080a11,#121725);
    border:1px solid var(--line);
    box-shadow:
      inset 0 6px 14px rgba(0,0,0,.95), inset 0 -2px 0 rgba(255,255,255,.05),
      0 14px 26px -18px #000;
  }
  .bar::before{
    content:""; position:absolute; inset:0; pointer-events:none; opacity:.5;
    background-image:linear-gradient(90deg, rgba(120,170,255,.16) 1px, transparent 1px);
    background-size:9px 100%;
  }
  .bar i{
    display:block; height:100%; width:0; border-radius:15px; position:relative;
    background:linear-gradient(180deg,#9ef0ff 0%,#22d3ff 42%,#1f66ff 100%);
    box-shadow:
      0 0 18px rgba(34,211,255,.85), 0 0 42px rgba(34,211,255,.4),
      inset 0 2px 0 rgba(255,255,255,.7), inset 0 -8px 12px rgba(0,0,0,.4);
    transition:width 1.2s var(--ez);
  }
  .bar i::after{
    content:""; position:absolute; inset:0; border-radius:15px; opacity:.45;
    background-image:linear-gradient(115deg, rgba(255,255,255,.55) 0 8px, transparent 8px 20px);
    background-size:20px 100%;
    animation:flow 1.05s linear infinite;
  }
  @keyframes flow{to{background-position:20px 0}}
  .bar i.md{
    background:linear-gradient(180deg,#ffe0a3,#f59e0b 45%,#a45a06);
    box-shadow:0 0 18px rgba(245,158,11,.8), 0 0 40px rgba(245,158,11,.35), inset 0 2px 0 rgba(255,255,255,.65), inset 0 -8px 12px rgba(0,0,0,.4);
  }
  .bar i.hi{
    background:linear-gradient(180deg,#ffb3c3,#ff2d55 45%,#96001d);
    box-shadow:0 0 20px rgba(255,45,85,.9), 0 0 46px rgba(255,45,85,.4), inset 0 2px 0 rgba(255,255,255,.6), inset 0 -8px 12px rgba(0,0,0,.45);
  }
  .bar b{
    position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
    font-size:11.5px; font-weight:800; letter-spacing:.4px; color:#eaf6ff;
    text-shadow:0 1px 0 rgba(0,0,0,.85), 0 0 12px rgba(34,211,255,.55);
  }

  .grid{display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:9px; margin-top:12px}
  .cell{
    position:relative; padding:11px 9px; border-radius:15px; text-align:center; overflow:hidden;
    background:linear-gradient(180deg,rgba(18,22,34,.95),rgba(9,12,20,.95));
    border:1px solid var(--line);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.06), 0 14px 24px -20px #000;
    transform:translateZ(14px);
    transition:transform .35s var(--ez), box-shadow .35s var(--ez), border-color .35s var(--ez);
  }
  .cell:hover{transform:translateZ(24px) translateY(-2px); border-color:rgba(34,211,255,.4);
    box-shadow:0 0 0 1px rgba(34,211,255,.18), 0 18px 30px -22px #000}
  .cell .k{display:block; font-size:10.5px; color:var(--mut); margin-bottom:3px}
  .cell .v{display:block; font-size:13.5px; font-weight:800; letter-spacing:.2px}
  .g{color:#7cf0a8; text-shadow:0 0 14px rgba(34,197,94,.45)}
  .r{color:#ff8ea3; text-shadow:0 0 14px rgba(255,45,85,.5)}
  .b{color:#8fe6ff; text-shadow:0 0 14px rgba(34,211,255,.5)}

  .lbl{font-size:11.5px; color:var(--dim); margin:14px 0 7px; display:flex; align-items:center; gap:6px}
  .lbl::before{content:""; width:6px; height:6px; border-radius:50%;
    background:var(--red); box-shadow:0 0 10px var(--red)}
  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; direction:ltr; text-align:left}
  .box{
    padding:11px 12px; border-radius:14px; font-size:11.5px; word-break:break-all; line-height:1.85;
    background:linear-gradient(180deg,#080a12,#0e1320); border:1px solid var(--line);
    box-shadow:inset 0 4px 12px rgba(0,0,0,.85), inset 0 -1px 0 rgba(255,255,255,.05);
    max-height:150px; overflow:auto;
  }
  .btn{
    display:flex; align-items:center; justify-content:center; gap:7px; width:100%;
    margin-top:9px; padding:13px 14px; border:0; border-radius:15px; cursor:pointer;
    font-family:inherit; font-size:13.5px; font-weight:800; color:#fff; text-decoration:none;
    background:linear-gradient(180deg,#ff4d6d,#e0113c 55%,#8d0421);
    box-shadow:0 16px 28px -16px rgba(255,45,85,.85), inset 0 1px 0 rgba(255,255,255,.45), inset 0 -6px 12px rgba(0,0,0,.45);
    transition:transform .18s var(--ez), box-shadow .25s var(--ez), filter .25s var(--ez);
  }
  .btn:hover{filter:brightness(1.06)}
  .btn:active{transform:translateY(2px) scale(.995); box-shadow:0 8px 16px -12px rgba(255,45,85,.8), inset 0 2px 8px rgba(0,0,0,.6)}
  .btn.gh{
    background:linear-gradient(180deg,rgba(20,26,40,.95),rgba(10,14,24,.95));
    color:#c8e8ff; border:1px solid rgba(34,211,255,.35);
    box-shadow:0 14px 26px -18px rgba(34,211,255,.65), inset 0 1px 0 rgba(255,255,255,.09);
  }
  .note{margin-top:11px; font-size:11px; color:var(--mut); text-align:center; line-height:1.9}
  .al{padding:12px 13px; border-radius:14px; font-size:12.5px; border:1px solid var(--line)}
  .al.err{color:#ffb9c6; border-color:rgba(255,45,85,.45);
    background:linear-gradient(180deg,rgba(60,8,20,.7),rgba(24,6,12,.7));
    box-shadow:inset 0 1px 0 rgba(255,255,255,.06), 0 0 22px -12px rgba(255,45,85,.7)}
  details summary{
    cursor:pointer; font-size:12.5px; font-weight:700; color:#bfe6ff; list-style:none;
    padding:10px 12px; border-radius:13px; border:1px solid var(--line);
    background:linear-gradient(180deg,rgba(18,24,38,.9),rgba(9,12,20,.9));
    box-shadow:inset 0 1px 0 rgba(255,255,255,.06);
  }
  details summary::-webkit-details-marker{display:none}
  details[open] summary{border-color:rgba(34,211,255,.4); color:#eaf6ff}

  @media (max-width:400px){
    .grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .tt{font-size:14.5px}
    .logo{width:40px; height:40px; font-size:19px}
    .card{padding:15px 13px; border-radius:19px}
  }
  @media (min-width:900px){
    .wrap{max-width:640px}
    .card{padding:19px 18px}
  }
  @media (prefers-reduced-motion:reduce){
    body::after,.card::after,.logo,.bar i::after{animation:none}
    .card,.cell,.btn{transition:none}
  }
</style>
<?php endif; ?>
</head>
<body>
<div class="wrap">

  <div class="card">
    <div class="top">
      <div class="logo">🛡</div>
      <div class="tt"><?= h($shopTitle) ?></div>
      <span class="st <?= $stCls ?>"><?= $stTxt ?></span>
    </div>

    <div class="lbl">مصرف حجم<?= $totalBytes > 0 ? ' — ' . fa_num((string)$pct) . '٪' : '' ?></div>
    <div class="bar"><i class="<?= $barCls ?>" style="width:<?= $totalBytes > 0 ? $pct : 0 ?>%"></i><b><?= fa_num((string)$pct) ?>٪</b></div>

    <div class="grid">
      <div class="cell"><div class="k">حجم کل</div>
        <div class="v b"><?= $totalBytes > 0 ? fa_num(human_bytes($totalBytes)) : 'نامحدود' ?></div></div>
      <div class="cell"><div class="k">مصرف‌شده</div>
        <div class="v"><?= fa_num(human_bytes($used)) ?></div></div>
      <div class="cell"><div class="k">باقی‌مانده</div>
        <div class="v <?= $leftB > 0 || $totalBytes === 0 ? 'g' : 'r' ?>"><?= $totalBytes > 0 ? fa_num(human_bytes($leftB)) : 'نامحدود' ?></div></div>
      <div class="cell"><div class="k">زمان باقی‌مانده</div>
        <div class="v <?= $expired ? 'r' : 'g' ?>"><?= $expireTs > 0 ? fa_num(remaining_human($expire)) : 'نامحدود' ?></div></div>
      <div class="cell"><div class="k">تاریخ انقضا</div>
        <div class="v"><?= $expire !== '' ? fa_num(to_jalali($expire)) : '—' ?></div></div>
      <div class="cell"><div class="k">تعداد کانفیگ</div>
        <div class="v"><?= fa_num((string)count($links)) ?><?= $grouped ? ' · ' . fa_num((string)count($rows)) . ' سرور' : '' ?></div></div>
    </div>

<?php if (!$ok): ?>
    <div class="al err">این اشتراک در حال حاضر فعال نیست، بنابراین کانفیگی تحویل داده نمی‌شود.
      برای تمدید یا افزایش حجم به ربات مراجعه کنید.</div>
<?php endif; ?>
  </div>

  <div class="card">
    <div class="lbl" style="margin-top:0">لینک اشتراک (این آدرس را در کلاینت وارد کنید)</div>
    <div class="box mono" id="sub"><?= h($subUrl) ?></div>
    <button class="btn" type="button" data-copy="sub">📋 کپی لینک اشتراک</button>
    <textarea id="b64box" readonly aria-hidden="true"><?= h($b64) ?></textarea>
    <div class="note">این صفحه هر بار به‌روز است و نیازی به ساخت لینک جدید نیست. لینک بالا را در کلاینت به عنوان Subscription اضافه کنید.</div>
  </div>

<?php if ($apps): ?>
  <details class="card acc" id="appsCard">
    <summary class="accs">
      <span class="acci">🧰</span>
      <span class="acct"><b>ابزارهای اتصال</b><i>دانلود برنامهٔ مناسب موبایل، ویندوز و مک</i></span>
      <span class="accx">▾</span>
    </summary>
    <div class="accb">
      <div class="apps">
<?php foreach ($apps as $ap): $pfs = (array)($ap['p'] ?? []); ?>
        <details class="app2">
          <summary class="app">
            <span class="ai"><?= h((string)$ap['i']) ?></span>
            <span class="at"><b><?= h((string)$ap['n']) ?></b><i><?= h((string)$ap['o']) ?></i></span>
            <span class="ad">▾</span>
          </summary>
          <div class="pfs">
            <div class="pfh">پلتفرم دستگاه خود را انتخاب کنید</div>
<?php foreach ($pfs as $pf): ?>
            <a class="pf" href="<?= h((string)$pf['u']) ?>" target="_blank" rel="noopener">
              <span class="pfn"><?= h((string)$pf['n']) ?></span>
              <span class="pfd">دانلود ⬇️</span>
            </a>
<?php endforeach; ?>
          </div>
        </details>
<?php endforeach; ?>
      </div>
      <div class="note">برنامهٔ مناسب دستگاه خود را نصب کنید، بعد «لینک اشتراک» بالا را کپی کنید
        و در برنامه به عنوان Subscription اضافه کنید.</div>
    </div>
  </details>
<?php if ($subTheme === 'aurora'): ?>
  <style>
  .acc{padding:0;overflow:hidden}
  .app2{border:1px solid rgba(255,255,255,.09);border-radius:14px;overflow:hidden;background:rgba(255,255,255,.03)}
  .app2+.app2{margin-top:8px}
  .app2>summary.app{list-style:none;cursor:pointer;-webkit-tap-highlight-color:transparent;margin:0;border:0;background:transparent}
  .app2>summary.app::-webkit-details-marker{display:none}
  .app2[open]{background:rgba(255,255,255,.055);border-color:rgba(255,255,255,.16)}
  .app2[open]>summary.app .ad{transform:rotate(180deg)}
  .app .ad{transition:transform .18s ease}
  .pfs{display:flex;flex-direction:column;gap:7px;padding:4px 11px 12px}
  .pfh{font-size:11px;opacity:.62;padding:2px 2px 4px}
  .pf{display:flex;align-items:center;gap:10px;padding:11px 12px;border-radius:12px;text-decoration:none;color:inherit;
      background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);min-width:0}
  .pf:active{transform:translateY(1px)}
  .pfn{flex:1;min-width:0;font-size:12.5px;font-weight:700;overflow-wrap:anywhere}
  .pfd{flex:0 0 auto;font-size:11px;opacity:.72;white-space:nowrap}
  .accs{list-style:none;cursor:pointer;display:flex;align-items:center;gap:11px;padding:14px 15px;user-select:none;
        -webkit-tap-highlight-color:transparent}
  .accs::-webkit-details-marker{display:none}
  .acci{width:38px;height:38px;flex:0 0 38px;display:grid;place-items:center;font-size:19px;border-radius:12px;
        background:rgba(255,255,255,.07);box-shadow:inset 0 1px 0 rgba(255,255,255,.16)}
  .acct{display:flex;flex-direction:column;gap:2px;min-width:0;flex:1}
  .acct b{font-size:13.5px;font-weight:800}
  .acct i{font-style:normal;font-size:11px;opacity:.72;overflow-wrap:break-word}
  .accx{margin-inline-start:auto;font-size:16px;opacity:.75;transition:transform .22s ease}
  .acc[open] .accx{transform:rotate(180deg)}
  .accb{padding:0 15px 15px;animation:accIn .22s ease}
  @keyframes accIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
  </style>
<?php endif; ?>
<?php endif; ?>

<?php if ($subTheme === 'aurora'): ?>
<style>
.brow2{display:grid;gap:9px;margin-top:2px}
#b64box{position:absolute;inset-inline-start:-9999px;width:1px;height:1px;opacity:0;pointer-events:none}
.apps{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:9px;margin-top:5px}
.app{display:flex;align-items:center;gap:9px;padding:10px 11px;border-radius:14px;text-decoration:none;color:inherit;
  background:linear-gradient(160deg,rgba(255,255,255,.075),rgba(255,255,255,.015));
  border:1px solid rgba(120,170,255,.22);
  box-shadow:0 12px 24px -18px rgba(0,0,0,.95),inset 0 1px 0 rgba(255,255,255,.08);
  transition:transform .16s,box-shadow .16s,border-color .16s}
.app:hover,.app:active{transform:translateY(-2px);border-color:rgba(34,211,238,.55);
  box-shadow:0 18px 32px -20px rgba(34,211,238,.6),inset 0 1px 0 rgba(255,255,255,.10)}
.app .ai{font-size:20px;line-height:1;flex:0 0 auto}
.app .at{min-width:0;flex:1 1 auto;display:grid;gap:1px}
.app .at b{font-size:12.5px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;direction:ltr;text-align:start}
.app .at i{font-style:normal;font-size:10.5px;opacity:.66}
.app .ad{flex:0 0 auto;opacity:.5;font-size:12px}
</style>
<?php endif; ?>

<script>
(function () {
  var box = document.getElementById('b64box');
  var b64 = box ? (box.value || '') : '';
  var btn = document.getElementById('dlBtn');
  var cp  = document.getElementById('dlCopy');

  function flash(el, txt) {
    if (!el) return;
    var old = el.textContent;
    el.textContent = txt;
    setTimeout(function () { el.textContent = old; }, 1700);
  }

  /* دانلود مستقیم از داخل مرورگر — بدون درخواست تازه */
  if (btn && b64 && window.Blob && window.URL && window.URL.createObjectURL) {
    btn.addEventListener('click', function (e) {
      try {
        var blob = new Blob([b64], { type: 'text/plain;charset=utf-8' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href = url;
        a.download = btn.getAttribute('download') || 'subscription.txt';
        document.body.appendChild(a);
        a.click();
        setTimeout(function () {
          try { URL.revokeObjectURL(url); } catch (x) {}
          if (a.parentNode) a.parentNode.removeChild(a);
        }, 1500);
        e.preventDefault();
        flash(btn, '✅ فایل ذخیره شد');
      } catch (x) { /* لینک مستقیم جایگزین است */ }
    });
  }

  if (cp) {
    cp.addEventListener('click', function () {
      var ok = function () { flash(cp, '✅ کپی شد'); };
      var fb = function () {
        try {
          box.removeAttribute('aria-hidden');
          box.focus();
          box.select();
          box.setSelectionRange(0, box.value.length);
          document.execCommand('copy');
          window.getSelection().removeAllRanges();
          ok();
        } catch (x) { flash(cp, '⛔️ کپی نشد'); }
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(b64).then(ok, fb);
      } else { fb(); }
    });
  }
})();
</script>

<?php
  $mainSubs = [];
  if ((string)DB::setting('sub_show_main', '1') === '1') {
      foreach ($rows as $r0) {
          $pid = (int)($r0['panel_id'] ?? 0);
          $sid = trim((string)($r0['sub_id'] ?? ''));
          if ($pid <= 0 || $sid === '') continue;
          $base = trim((string)DB::val('SELECT sub_base FROM {p}panels WHERE id = :i', [':i' => $pid], ''));
          if ($base === '') continue;
          $u0 = rtrim($base, '/') . '/' . $sid;
          if (!in_array($u0, $mainSubs, true)) $mainSubs[] = $u0;
      }
  }
  if ($mainSubs):
?>
  <div class="card">
    <div class="lbl" style="margin-top:0">لینک ساب اصلی سرور (جایگزین)</div>
<?php foreach ($mainSubs as $mi => $ms): ?>
    <div class="box mono" id="m<?= (int)$mi ?>"><?= h($ms) ?></div>
    <button class="btn gh" type="button" data-copy="m<?= (int)$mi ?>">📋 کپی ساب اصلی <?= count($mainSubs) > 1 ? fa_num((string)($mi + 1)) : '' ?></button>
<?php endforeach; ?>
    <div class="note">اگر لینک اختصاصی بالا در کلاینت شما باز نشد، این لینک را امتحان کنید.</div>
  </div>
<?php endif; ?>

<?php if ($ok && $links): ?>
  <div class="card hi">
    <details>
      <summary>⚙️ کانفیگ‌ها (<?= fa_num((string)count($links)) ?>)</summary>
      <div style="margin-top:10px">
<?php foreach ($links as $i => $lnk): ?>
        <div class="box mono" id="c<?= (int)$i ?>"><?= h($lnk) ?></div>
        <button class="btn gh" type="button" data-copy="c<?= (int)$i ?>">📋 کپی کانفیگ <?= fa_num((string)($i + 1)) ?></button>
<?php endforeach; ?>
      </div>
    </details>
  </div>
<?php endif; ?>

  <div class="note" style="text-align:center"><?= h(APP_BRAND) ?></div>
</div>

<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-copy]');
  if (!b) return;
  var el = document.getElementById(b.getAttribute('data-copy'));
  if (!el) return;
  var txt = el.textContent.trim(), old = b.textContent;
  function done(okv) {
    b.textContent = okv ? '\u2705 \u06a9\u067e\u06cc \u0634\u062f' : '\u274c \u06a9\u067e\u06cc \u0646\u0634\u062f';
    setTimeout(function () { b.textContent = old; }, 1500);
  }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(txt).then(function () { done(true); }, function () { done(false); });
    return;
  }
  var a = document.createElement('textarea');
  a.value = txt; a.style.position = 'fixed'; a.style.opacity = '0';
  document.body.appendChild(a); a.select();
  try { done(document.execCommand('copy')); } catch (x) { done(false); }
  document.body.removeChild(a);
});
</script>
</body>
</html>
<?php
    exit;
}

/* حالت تحویل: own = لینک اختصاصی ، main = هدایت به ساب اصلی پنل */
if ($fmt !== 'dl' && (string)DB::setting('sub_page_mode', 'own') === 'main' && count($rows) === 1) {
    $one = '';
    foreach ($rows as $r1) {
        $pid = (int)($r1['panel_id'] ?? 0);
        $sid = trim((string)($r1['sub_id'] ?? ''));
        if ($pid <= 0 || $sid === '') continue;
        $base = trim((string)DB::val('SELECT sub_base FROM {p}panels WHERE id = :i', [':i' => $pid], ''));
        if ($base === '') continue;
        $one = rtrim($base, '/') . '/' . $sid;
        break;
    }
    if ($one !== '') {
        header('Location: ' . $one, true, 302);
        exit;
    }
}

/*
 * مهم: اگر اشتراک سالم است ولی لینکی از پنل به دست نیامد، از کانفیگ
 * ذخیره شده در دیتابیس استفاده می شود؛ بدنه خالی در کلاینت ها
 * خطای EOF می دهد.
 */
if ($ok && !$links) {
    $fallback = [];
    foreach ($rows as $rF) {
        foreach (preg_split('/[\r\n]+/', (string)($rF['config_link'] ?? '')) as $lF) {
            $lF = trim((string)$lF);
            if ($lF !== '' && preg_match('~^[A-Za-z0-9]+://~', $lF)) $fallback[] = $lF;
        }
    }
    $links = array_values(array_unique($fallback));
    if ($links) {
        app_log('sub', 'served stored config_link (live links empty)', [
            'key' => $key, 'n' => count($links),
        ]);
    }
}

if (!$ok || !$links) {
    header('Content-Type: text/plain; charset=utf-8');
    exit('');
}

/*
 * خروجی دانلودی
 * دکمهٔ «دریافت خروجی اشتراک» پیش از این به آدرس زیبای /base64 می‌رفت؛
 * اگر بازنویسی آدرس روی هاست فعال نبود ۴۰۴ می‌گرفت و بی‌کار به نظر می‌رسید.
 * الان با fmt=dl فایل با هدر دانلود تحویل داده می‌شود.
 */
if ($fmt === 'dl') {
    $fn = (string)preg_replace('/[^A-Za-z0-9_\-]/', '', $key);
    if ($fn === '') $fn = 'subscription';
    header('Content-Type: application/octet-stream; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fn . '.txt"');
    header('Content-Transfer-Encoding: binary');
    echo base64_encode(implode("\n", $links) . "\n");
    exit;
}

if ($fmt === 'raw') {
    header('Content-Type: text/plain; charset=utf-8');
    echo implode("\n", $links) . "\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo base64_encode(implode("\n", $links) . "\n");
