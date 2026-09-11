<?php
declare(strict_types=1);

/**
 * عیب‌یابی اتصال ربات به تلگرام
 * آدرس: https://دامنه‌شما/tools/botcheck.php
 * فقط برای مدیری که در پنل وارد شده باشد.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

if (!app_installed()) { exit('برنامه نصب نشده است.'); }
boot();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$ADMIN = null;
try {
    if (!empty($_SESSION['vs_admin'])) {
        $ADMIN = DB::one('SELECT * FROM {p}admins WHERE id = :id', [':id' => (int)$_SESSION['vs_admin']]);
    }
} catch (Throwable $e) { $ADMIN = null; }

header('Content-Type: text/html; charset=utf-8');

if (!$ADMIN) {
    echo '<!doctype html><html dir="rtl" lang="fa"><meta charset="utf-8"><title>عیب‌یابی ربات</title>'
        . '<body style="font-family:system-ui;background:#0f1115;color:#e7e9ee;display:flex;align-items:center;justify-content:center;height:100vh;margin:0">'
        . '<div style="text-align:center;max-width:420px"><div style="font-size:38px">🔒</div>'
        . '<h1 style="font-size:17px">ابتدا در پنل مدیریت وارد شوید</h1>'
        . '<p style="color:#9aa3b2;font-size:13px">برای امنیت، این صفحه فقط بعد از ورود به پنل باز می‌شود.</p>'
        . '<a style="display:inline-block;margin-top:14px;padding:10px 18px;border-radius:10px;background:#3b82f6;color:#fff;text-decoration:none" href="../admin/index.php">ورود به پنل</a>'
        . '</div></body></html>';
    exit;
}

if (empty($_SESSION['vs_csrf'])) $_SESSION['vs_csrf'] = bin2hex(random_bytes(16));
$CSRF = (string)$_SESSION['vs_csrf'];
$msgs = [];

/** درخواست خام به تلگرام با جزئیات کامل */
function tgRaw(string $method, string $token, array $params = []): array
{
    if ($token === '') return ['http' => 0, 'body' => '', 'err' => 'token empty', 'ip' => ''];
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => app_ssl_verify(),
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $params,
    ]);
    $body = curl_exec($ch);
    $out  = [
        'http' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'body' => is_string($body) ? $body : '',
        'err'  => (string)curl_error($ch),
        'ip'   => (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP),
    ];
    curl_close($ch);
    return $out;
}

/** باز کردن آدرس وب‌هوک از بیرون */
function fetchHead(string $url): array
{
    if ($url === '') return ['http' => 0, 'body' => '', 'err' => 'empty url'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => app_ssl_verify(),
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $b = curl_exec($ch);
    $r = ['http' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => is_string($b) ? mb_substr($b, 0, 400) : '', 'err' => (string)curl_error($ch)];
    curl_close($ch);
    return $r;
}

/* ---------------- اقدام‌ها ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($CSRF, (string)($_POST['_t'] ?? ''))) {
    $act = (string)($_POST['act'] ?? '');

    if ($act === 'token') {
        $new   = (string)($_POST['bot_token'] ?? '');
        $force = (string)($_POST['force'] ?? '') === '1';
        if (class_exists('Cfg')) {
            $r = Cfg::saveToken($new, $force);
            $msgs[] = [!empty($r['ok']) ? 'ok' : 'err', (string)$r['message']];
            if (!empty($r['ok']) && !empty($r['verified'])) {
                $w = Tg::setWebhook(app_url('index.php'), (string)Cfg::get('bot.secret', ''));
                $msgs[] = [!empty($w['ok']) ? 'ok' : 'err', !empty($w['ok'])
                    ? 'وب‌هوک روی ' . h(app_url('index.php')) . ' تنظیم شد.'
                    : 'تنظیم وب‌هوک ناموفق: ' . h((string)($w['description'] ?? '-'))];
            }
        } else {
            $msgs[] = ['err', 'فایل app/Service/Cfg.php آپلود نشده است.'];
        }
    }

    if ($act === 'hook_set') {
        $w = Tg::setWebhook(app_url('index.php'), (string)cfg('bot.secret', ''));
        $msgs[] = [!empty($w['ok']) ? 'ok' : 'err', !empty($w['ok']) ? 'وب‌هوک تنظیم شد.' : h((string)($w['description'] ?? '-'))];
    }

    if ($act === 'hook_del') {
        Tg::deleteWebhook();
        $msgs[] = ['warn', 'وب‌هوک حذف شد.'];
    }

    if ($act === 'secret_new') {
        if (class_exists('Cfg')) {
            $sec = bin2hex(random_bytes(16));
            $w   = Cfg::set(['bot.secret' => $sec]);
            $msgs[] = [!empty($w['ok']) ? 'ok' : 'err', (string)$w['message']];
            if (!empty($w['ok'])) {
                $r = Tg::setWebhook(app_url('index.php'), $sec);
                $msgs[] = [!empty($r['ok']) ? 'ok' : 'err', !empty($r['ok'])
                    ? 'سکرت تازه ساخته و وب‌هوک با همان تنظیم شد.'
                    : h((string)($r['description'] ?? '-'))];
            }
        }
    }
}

/* ---------------- تشخیص ---------------- */
$tok     = (string)cfg('bot.token', '');
$tokLen  = strlen($tok);
$tokFmt  = (bool)preg_match('/^[0-9]{6,}:[A-Za-z0-9_\-]{30,}$/', $tok);
$tokWs   = $tok !== trim($tok);
$tokBad  = (bool)preg_match('/[^\x21-\x7E]/', $tok);
$botId   = strpos($tok, ':') !== false ? substr($tok, 0, strpos($tok, ':')) : '';
$tokTail = $tokLen > 0 ? bin2hex(substr($tok, -4)) : '';
$secret  = (string)cfg('bot.secret', '');
$appUrl  = (string)cfg('app.url', '');
$hookUrl = app_url('index.php');

$me   = tgRaw('getMe', $tok);
$meJ  = json_decode($me['body'], true);
$meOk = is_array($meJ) && !empty($meJ['ok']);
$meUn = $meOk ? (string)($meJ['result']['username'] ?? '') : '';
$meDs = is_array($meJ) ? (string)($meJ['description'] ?? '') : '';

$wh   = tgRaw('getWebhookInfo', $tok);
$whJ  = json_decode($wh['body'], true);
$whR  = is_array($whJ) ? (array)($whJ['result'] ?? []) : [];

$site = fetchHead($hookUrl);
$siteOk = $site['http'] === 200 && mb_strpos($site['body'], 'ربات فعال است') !== false;

$cfgFile = class_exists('Cfg') ? Cfg::path() : (APP_ROOT . '/config.php');
$cfgW    = class_exists('Cfg') ? Cfg::writable() : is_writable($cfgFile);

function badge(bool $ok, string $yes = 'درست', string $no = 'ایراد'): string
{
    return '<span class="b ' . ($ok ? 'g' : 'r') . '">' . ($ok ? '✅ ' . $yes : '⛔ ' . $no) . '</span>';
}
?>
<!doctype html>
<html lang="fa" dir="rtl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>عیب‌یابی ربات – SR-BOT</title>
<style>
*{box-sizing:border-box}
body{font-family:system-ui,Vazirmatn,sans-serif;background:#0f1115;color:#e7e9ee;margin:0;padding:18px}
.wrap{max-width:860px;margin:0 auto}
h1{font-size:19px;margin:0 0 4px}
.sub{color:#9aa3b2;font-size:13px;margin-bottom:16px}
.card{background:#171a21;border:1px solid #242833;border-radius:14px;padding:14px;margin-bottom:14px}
.card h2{font-size:15px;margin:0 0 10px}
.kv{display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px dashed #242833;font-size:13px;flex-wrap:wrap}
.kv:last-child{border:0}
.kv .k{color:#9aa3b2}
.mono{font-family:ui-monospace,Menlo,Consolas,monospace;direction:ltr;text-align:left;word-break:break-all}
.b{padding:2px 8px;border-radius:20px;font-size:12px}
.b.g{background:#132e1e;color:#4ade80}
.b.r{background:#2e1313;color:#f87171}
.b.y{background:#2e2913;color:#fbbf24}
.al{padding:10px 12px;border-radius:10px;font-size:13px;margin-bottom:10px;line-height:1.9}
.al.ok{background:#132e1e;color:#86efac}
.al.err{background:#2e1313;color:#fca5a5}
.al.warn{background:#2e2913;color:#fcd34d}
input[type=text]{width:100%;padding:10px;border-radius:10px;border:1px solid #2b3040;background:#0f1115;color:#e7e9ee;font-family:ui-monospace,monospace;direction:ltr}
button{padding:9px 16px;border-radius:10px;border:0;background:#3b82f6;color:#fff;font-size:13px;cursor:pointer;font-family:inherit}
button.g{background:#22303f}
.row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
label.ck{display:flex;gap:7px;align-items:center;font-size:13px;color:#c9cfdb;margin-top:8px}
pre{background:#0f1115;border:1px solid #242833;border-radius:10px;padding:10px;font-size:12px;overflow:auto;direction:ltr;text-align:left}
.hint{color:#9aa3b2;font-size:12px;margin-top:6px;line-height:1.9}
</style>
</head><body><div class="wrap">

<h1>🩺 عیب‌یابی اتصال ربات</h1>
<div class="sub">نسخه <?= h(APP_VERSION) ?> · این صفحه مستقل از تنظیمات پنل کار می‌کند</div>

<?php foreach ($msgs as $m): ?>
  <div class="al <?= h($m[0] === 'ok' ? 'ok' : ($m[0] === 'warn' ? 'warn' : 'err')) ?>"><?= $m[1] ?></div>
<?php endforeach; ?>

<div class="card">
  <h2>🧪 نتیجهٔ نهایی</h2>
  <?php if ($meOk && !empty($whR['url']) && $siteOk): ?>
    <div class="al ok">✅ همه چیز سالم است. اگر باز هم ربات جواب نداد، بخش «آخرین خطای وب‌هوک» را بخوانید.</div>
  <?php elseif (!$meOk && stripos($meDs . $me['body'], 'unauthorized') !== false): ?>
    <div class="al err">⛔ <b>مشکل از توکن است.</b> تلگرام پاسخ داد <span class="mono">401 Unauthorized</span> یعنی این توکن باطل شده یا متعلق به ربات دیگری است.<br>در تلگرام به <span class="mono">@BotFather</span> ← <span class="mono">/mybots</span> ← ربات خود ← <span class="mono">API Token</span> بروید و توکن را در کادر پایین بگذارید.</div>
  <?php elseif (!$meOk && $me['http'] === 0): ?>
    <div class="al err">⛔ <b>سرور به تلگرام وصل نمی‌شود</b> (خطای شبکه: <span class="mono"><?= h($me['err']) ?></span>). مشکل از توکن نیست؛ فیلترینگ یا فایروال سرور را بررسی کنید.</div>
  <?php elseif ($meOk && empty($whR['url'])): ?>
    <div class="al warn">⚠️ توکن سالم است ولی <b>وب‌هوک تنظیم نشده</b>؛ دکمهٔ «تنظیم وب‌هوک» را بزنید.</div>
  <?php elseif ($meOk && !$siteOk): ?>
    <div class="al warn">⚠️ توکن سالم است ولی <b>آدرس سایت درست پاسخ نمی‌دهد</b>؛ مقدار <span class="mono">app.url</span> را درست کنید.</div>
  <?php else: ?>
    <div class="al err">⛔ تلگرام توکن را قبول نکرد: <span class="mono"><?= h($meDs !== '' ? $meDs : ('HTTP ' . $me['http'])) ?></span></div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>🔑 توکن در config.php</h2>
  <div class="kv"><span class="k">فایل</span><span class="mono"><?= h($cfgFile) ?></span></div>
  <div class="kv"><span class="k">قابل نوشتن</span><span><?= badge($cfgW, 'بله', 'خیر – دسترسی 644') ?></span></div>
  <div class="kv"><span class="k">آخرین تغییر فایل</span><span class="mono"><?= h(is_file($cfgFile) ? date('Y-m-d H:i:s', (int)filemtime($cfgFile)) : '-') ?></span></div>
  <div class="kv"><span class="k">طول توکن</span><span class="mono"><?= (int)$tokLen ?> کاراکتر <?= $tokLen >= 40 && $tokLen <= 60 ? '' : '(معمولاً ۴۵ تا ۵۰)' ?></span></div>
  <div class="kv"><span class="k">ساختار توکن</span><span><?= badge($tokFmt, 'الگوی درست', 'الگو خراب است') ?></span></div>
  <div class="kv"><span class="k">کاراکتر اضافه/فارسی</span><span><?= badge(!$tokBad && !$tokWs, 'تمیز', 'فاصله یا کاراکتر نامعتبر دارد') ?></span></div>
  <div class="kv"><span class="k">شمارهٔ ربات (قبل از :)</span><span class="mono"><?= h($botId !== '' ? $botId : '-') ?></span></div>
  <div class="kv"><span class="k">۴ کاراکتر آخر (hex)</span><span class="mono"><?= h($tokTail !== '' ? $tokTail : '-') ?></span></div>
</div>

<div class="card">
  <h2>📡 پاسخ خام تلگرام</h2>
  <div class="kv"><span class="k">getMe – کد HTTP</span><span class="mono"><?= (int)$me['http'] ?></span></div>
  <div class="kv"><span class="k">آیپی تلگرام</span><span class="mono"><?= h($me['ip'] !== '' ? $me['ip'] : '-') ?></span></div>
  <div class="kv"><span class="k">خطای شبکه</span><span class="mono"><?= h($me['err'] !== '' ? $me['err'] : '-') ?></span></div>
  <pre><?= h(mb_substr($me['body'] !== '' ? $me['body'] : '(بدون پاسخ)', 0, 500)) ?></pre>
</div>

<div class="card">
  <h2>🔗 وب‌هوک</h2>
  <div class="kv"><span class="k">آدرس ثبت‌شده در تلگرام</span><span class="mono"><?= h((string)($whR['url'] ?? '-')) ?></span></div>
  <div class="kv"><span class="k">آدرس مورد انتظار</span><span class="mono"><?= h($hookUrl) ?></span></div>
  <div class="kv"><span class="k">تطابق آدرس</span><span><?= badge(rtrim((string)($whR['url'] ?? ''), '/') === rtrim($hookUrl, '/'), 'یکی است', 'یکی نیست') ?></span></div>
  <div class="kv"><span class="k">آپدیت معلق</span><span class="mono"><?= (int)($whR['pending_update_count'] ?? 0) ?></span></div>
  <div class="kv"><span class="k">آخرین خطای وب‌هوک</span><span class="mono"><?= h((string)($whR['last_error_message'] ?? '-')) ?></span></div>
  <div class="kv"><span class="k">زمان آخرین خطا</span><span class="mono"><?= h(!empty($whR['last_error_date']) ? date('Y-m-d H:i:s', (int)$whR['last_error_date']) : '-') ?></span></div>
  <div class="kv"><span class="k">سکرت در config</span><span><?= $secret !== '' ? '<span class="b y">دارد</span>' : '<span class="b g">ندارد (بدون بررسی)</span>' ?></span></div>
  <?php if (!empty($whR['last_error_message']) && stripos((string)$whR['last_error_message'], '401') !== false): ?>
    <div class="al err">⛔ تلگرام می‌گوید وب‌هوک شما پاسخ <span class="mono">401</span> داده است؛ یعنی سکرت ثبت‌شده در تلگرام با config.php یکی نیست. دکمهٔ «سکرت تازه» را بزنید.</div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>🌐 آدرس سایت</h2>
  <div class="kv"><span class="k">app.url</span><span class="mono"><?= h($appUrl !== '' ? $appUrl : '-') ?></span></div>
  <div class="kv"><span class="k">باز شدن از بیرون</span><span><?= badge($siteOk, 'HTTP ' . (int)$site['http'] . ' – صفحهٔ ربات', 'HTTP ' . (int)$site['http'] . ($site['err'] !== '' ? ' – ' . h($site['err']) : '')) ?></span></div>
  <div class="hint">اگر اینجا ایراد بود، تلگرام هم نمی‌تواند به ربات شما پیام برساند.</div>
</div>

<?php
if (!function_exists('bcHeaders')) {
    function bcHeaders(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => app_ssl_verify(),
            CURLOPT_USERAGENT      => 'SR-BOT-check/1.0',
        ]);
        $raw  = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsz  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err  = (string)curl_error($ch);
        curl_close($ch);

        $head = substr($raw, 0, $hsz);
        $xfo  = '';
        $csp  = '';
        foreach (preg_split('/\r?\n/', $head) as $line) {
            if (stripos($line, 'x-frame-options:') === 0)         $xfo = trim(substr($line, 16));
            if (stripos($line, 'content-security-policy:') === 0)  $csp = trim(substr($line, 24));
        }
        return ['http' => $code, 'err' => $err, 'xfo' => $xfo, 'csp' => $csp];
    }
}
$maUrl  = function_exists('app_url') ? app_url('miniapp/') : '';
$maRs   = function_exists('app_url') ? app_url('miniapp/reseller.php') : '';
$maH    = $maUrl !== '' ? bcHeaders($maUrl) : ['http' => 0, 'err' => '', 'xfo' => '', 'csp' => ''];
$maFrame = ($maH['xfo'] === '');
?>
<div class="card">
  <h2>📱 مینی‌اپ داخل تلگرام</h2>
  <div class="kv"><span class="k">آدرس مینی‌اپ</span><span class="mono"><?= h($maUrl) ?></span></div>
  <div class="kv"><span class="k">پنل نمایندگی</span><span class="mono"><?= h($maRs) ?></span></div>
  <div class="kv"><span class="k">کد پاسخ</span><span class="mono"><?= (int)$maH['http'] ?></span></div>
  <div class="kv"><span class="k">X-Frame-Options</span><span class="mono"><?= $maH['xfo'] === '' ? '— (خوب)' : h($maH['xfo']) ?></span></div>
  <div class="kv"><span class="k">Content-Security-Policy</span><span class="mono"><?= $maH['csp'] === '' ? '—' : h($maH['csp']) ?></span></div>
  <?php if ($maFrame) { ?>
    <div class="al ok">✅ هیچ هدر ضدآی‌فریمی دیده نشد؛ مینی‌اپ باید داخل تلگرام باز شود.</div>
  <?php } else { ?>
    <div class="al err">⛔ سرور هدر <b>X-Frame-Options</b> می‌فرستد؛ تلگرام صفحه را باز نمی‌کند و پیام refused to connect می‌دهد.
      فایل <span class="mono">miniapp/.htaccess</span> را آپلود کنید؛ اگر حل نشد، خط <span class="mono">Header always set X-Frame-Options</span>
      را از <span class="mono">.htaccess</span> ریشه حذف کنید.</div>
  <?php } ?>
</div>

<div class="card">
  <h2>🛠 اقدام</h2>
  <form method="post">
    <input type="hidden" name="_t" value="<?= h($CSRF) ?>">
    <input type="hidden" name="act" value="token">
    <input type="text" name="bot_token" placeholder="123456789:AAE..." autocomplete="off" required>
    <label class="ck"><input type="checkbox" name="force" value="1"> حتی اگر تلگرام تایید نکرد ذخیره کن (سرور فیلتر)</label>
    <div class="row"><button type="submit">💾 ذخیرهٔ توکن + تنظیم وب‌هوک</button></div>
    <div class="hint">قبل از نوشتن، نسخهٔ قبلی config.php در storage/backups پشتیبان می‌شود.</div>
  </form>
  <div class="row">
    <form method="post"><input type="hidden" name="_t" value="<?= h($CSRF) ?>"><input type="hidden" name="act" value="hook_set"><button class="g" type="submit">🔗 تنظیم وب‌هوک</button></form>
    <form method="post"><input type="hidden" name="_t" value="<?= h($CSRF) ?>"><input type="hidden" name="act" value="secret_new"><button class="g" type="submit">🆕 سکرت تازه + وب‌هوک</button></form>
    <form method="post"><input type="hidden" name="_t" value="<?= h($CSRF) ?>"><input type="hidden" name="act" value="hook_del"><button class="g" type="submit">⛔ حذف وب‌هوک</button></form>
    <a href="../admin/index.php?p=settings&tab=bot" style="align-self:center;color:#60a5fa;font-size:13px">بازگشت به پنل</a>
  </div>
</div>

<div class="card">
  <h2>⚙️ روش دستی (اگر ذخیره نشد)</h2>
  <div class="hint">فایل <span class="mono">config.php</span> را با فایل‌منیجر باز کنید و فقط مقدار token را عوض کنید:</div>
  <pre>'bot' => [
  'token'    => '123456789:AAE...',
  'username' => 'MyBot',
  'admins'   => ['123456789'],
  'secret'   => '...',
],</pre>
</div>

</div></body></html>
