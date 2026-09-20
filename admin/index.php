<?php
declare(strict_types=1);

/**
 * پنل مدیریت تحت وب
 */

require dirname(__DIR__) . '/app/bootstrap.php';

if (!app_installed()) { header('Location: ../install/index.php'); exit; }
boot();

/* ---------- کوکی امن نشست (باید پیش از session_start تنظیم شود) ---------- */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    Session::start();
}

/* ---------- هدرهای امنیتی ---------- */
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/* توکن CSRF زودتر ساخته می‌شود تا فرم ورود و لینک خروج هم محافظت شوند */
if (empty($_SESSION['vs_csrf'])) $_SESSION['vs_csrf'] = bin2hex(random_bytes(32));
$CSRF = (string)$_SESSION['vs_csrf'];

$loginError = null;
/* 0.0.2 #4: پشت پراکسی/کلادفلر باید آی‌پی واقعی کاربر مبنای قفل ورود باشد */
$clientIp   = class_exists('Guard') ? Guard::clientIp() : (string)($_SERVER['REMOTE_ADDR'] ?? '');

/* ---------- خروج (با محافظت CSRF تا کسی نتواند شما را اجباری خارج کند) ---------- */
if (isset($_GET['logout'])) {
    if (hash_equals($CSRF, (string)($_GET['_t'] ?? ''))) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $pr = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $pr['path'], $pr['domain'], (bool)$pr['secure'], (bool)$pr['httponly']);
        }
        session_destroy();
    }
    header('Location: index.php');
    exit;
}

/* ---------- قفل ورود پس از تلاش‌های ناموفق ---------- */
$lockMin = 0;
try { $lockMin = Security::lockedFor($clientIp); } catch (Throwable $e) { $lockMin = 0; }
$lockMsg = '⛔ به دلیل تلاش‌های ناموفق، ورود از این آی‌پی تا ' . fa_num($lockMin) . ' دقیقهٔ دیگر قفل است.';

/** ثبت موفقیت ورود و ساخت نشست تازه */
$startAdminSession = static function (int $adminId) use ($clientIp): void {
    // خوددرمانیِ نقش مدیر اصلی:
    // نصب‌کننده مدیر اول را با نقش «owner» می‌سازد ولی سامانهٔ دسترسی «super» می‌شناسد.
    // بدون این مرحله، مدیر اصلی با دسترسی سخت‌گیرانه پشت پیام «دسترسی محدود است» قفل می‌شود
    // و چون صفحهٔ مایگریشن هم داخل همین پنل است، راهی برای باز کردن قفل نمی‌ماند.
    try {
        $me = DB::one('SELECT `id`, `role`, `perms` FROM {p}admins WHERE id = :id', [':id' => $adminId]);
        if ($me) {
            $role  = strtolower(trim((string)($me['role'] ?? '')));
            $first = (int)DB::val('SELECT MIN(`id`) FROM {p}admins', [], 0);
            $heal  = in_array($role, ['owner', 'root'], true)
                || ($first === $adminId && $role !== 'super' && trim((string)($me['perms'] ?? '')) === '');
            if ($heal) {
                DB::update('admins', ['role' => 'super', 'perms' => jenc([Perm::ALL])],
                    'id = :id', [':id' => $adminId]);
            }
        }
    } catch (Throwable $e) { /* ورود نباید به خاطر این مرحله شکست بخورد */ }

    session_regenerate_id(true);                                  // جلوگیری از Session Fixation
    $_SESSION['vs_admin']    = $adminId;
    $_SESSION['vs_admin_ua'] = md5((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $_SESSION['vs_admin_ip'] = $clientIp;
    $_SESSION['vs_seen_at']  = time();
    $_SESSION['vs_csrf']     = bin2hex(random_bytes(32));         // توکن CSRF تازه پس از ورود
    try { Security::noteSuccess($clientIp); } catch (Throwable $e) { }

    /* 0.0.2 #11: اطلاع ورود موفق به تاپیک امنیت */
    try {
        if (class_exists('Logs')) {
            Logs::send('security', Logs::fmt('🔐 ورود به پنل مدیریت', [
                'مدیر'   => '#' . $adminId,
                'آی‌پی'  => $clientIp !== '' ? $clientIp : '-',
                'مرورگر' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 60),
            ]));
        }
    } catch (Throwable $e) { }
};

/* ---------- ورود یک‌بارمصرف از ربات ---------- */
$tok = trim((string)($_GET['token'] ?? ''));
if ($tok !== '' && $lockMin > 0) {
    $loginError = $lockMsg;
} elseif ($tok !== '') {
    // مقایسه در زمان ثابت تا توکن با حملهٔ زمان‌سنجی حدس زده نشود
    $row = null;
    foreach (DB::all("SELECT * FROM {p}admins WHERE token IS NOT NULL AND token <> ''") as $cand) {
        if (hash_equals((string)$cand['token'], $tok)) { $row = $cand; break; }
    }
    if ($row && (int)($row['active'] ?? 1) === 0) {
        $loginError = 'حساب مدیریتی شما غیرفعال شده است.';
    } elseif ($row && !empty($row['token_at']) && strtotime((string)$row['token_at']) > time() - 600) {
        DB::update('admins', ['token' => null, 'token_at' => null, 'last_login' => now(), 'last_ip' => $clientIp], 'id = :id', [':id' => (int)$row['id']]);
        $startAdminSession((int)$row['id']);
        header('Location: index.php');
        exit;
    } else {
        try { Security::noteFail($clientIp, 'token-link'); } catch (Throwable $e) { }
        $loginError = 'لینک ورود منقضی شده است. دوباره از ربات لینک بگیرید یا با رمز وارد شوید.';
    }
}

/* ---------- ورود با نام کاربری ---------- */
/* fixed79: لغو مرحلهٔ دوم ورود */
if (isset($_GET['cancel2fa'])) { unset($_SESSION['vs_2fa']); header('Location: index.php'); exit; }

/* fixed79: تایید کد دومرحله‌ای */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_2fa'])) {
    $tf = $_SESSION['vs_2fa'] ?? null;
    if (!hash_equals($CSRF, (string)($_POST['_t'] ?? ''))) {
        $loginError = 'نشست فرم منقضی شده است. صفحه را تازه کنید و دوباره تلاش کنید.';
    } elseif (!is_array($tf) || time() - (int)($tf['at'] ?? 0) > 300 || (int)($tf['tries'] ?? 0) >= 5) {
        unset($_SESSION['vs_2fa']);
        $loginError = 'کد منقضی شد یا تلاش‌ها بیش از حد بود؛ دوباره وارد شوید.';
    } else {
        $code = preg_replace('/\D+/', '', en_num((string)($_POST['code'] ?? ''))) ?? '';
        $row  = DB::one('SELECT * FROM {p}admins WHERE id = :id', [':id' => (int)($tf['id'] ?? 0)]);
        if ($row && (int)($row['active'] ?? 1) === 1 && $code !== '' && password_verify($code, (string)($tf['hash'] ?? ''))) {
            unset($_SESSION['vs_2fa']);
            DB::update('admins', ['last_login' => now(), 'last_ip' => $clientIp], 'id = :id', [':id' => (int)$row['id']]);
            if (class_exists('Audit')) Audit::log('login.ok', ['ip' => $clientIp, '2fa' => 1], 'web:' . (string)$row['username']);
            $startAdminSession((int)$row['id']);
            header('Location: index.php');
            exit;
        }
        $_SESSION['vs_2fa']['tries'] = (int)($tf['tries'] ?? 0) + 1;
        try { Security::noteFail($clientIp, (string)($row['username'] ?? '2fa')); } catch (Throwable $e) { }
        if (class_exists('Audit')) Audit::log('login.fail', ['ip' => $clientIp, '2fa' => 1], 'system');
        $loginError = 'کد وارد شده اشتباه است.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_login'])) {
    if (!hash_equals($CSRF, (string)($_POST['_t'] ?? ''))) {
        $loginError = 'نشست فرم منقضی شده است. صفحه را تازه کنید و دوباره تلاش کنید.';
    } elseif ($lockMin > 0) {
        $loginError = $lockMsg;
    } else {
        $u = trim((string)($_POST['username'] ?? ''));
        $p = (string)($_POST['password'] ?? '');
        $row = DB::one('SELECT * FROM {p}admins WHERE username = :u', [':u' => $u]);
        if ($row && (int)($row['active'] ?? 1) === 0) {
            $loginError = 'حساب مدیریتی شما غیرفعال شده است. با مدیر کل تماس بگیرید.';
        } elseif ($row && password_verify($p, (string)$row['password_hash'])) {
            // ارتقای خودکار الگوریتم هش در صورت نیاز
            if (password_needs_rehash((string)$row['password_hash'], PASSWORD_DEFAULT)) {
                DB::update('admins', ['password_hash' => password_hash($p, PASSWORD_DEFAULT)], 'id = :id', [':id' => (int)$row['id']]);
            }
            /* fixed79: تایید دومرحله‌ای — کد یک‌بارمصرف به تلگرام مدیر (تنظیمات ← تنظیمات پیشرفته) */
            if ((string)DB::setting('admin_2fa', '0') === '1' && (int)($row['tg_id'] ?? 0) > 0 && class_exists('Tg')) {
                $otp = (string)random_int(100000, 999999);
                $_SESSION['vs_2fa'] = ['id' => (int)$row['id'], 'hash' => password_hash($otp, PASSWORD_DEFAULT), 'at' => time(), 'tries' => 0];
                try {
                    Tg::send((int)$row['tg_id'], "🔐 <b>کد ورود به پنل مدیریت</b>\n\nکد: <code>" . $otp . "</code>\nاعتبار: ۵ دقیقه • IP: <code>" . h($clientIp) . "</code>\n\nاگر شما درخواست ورود نداده‌اید، فوراً رمز پنل را تغییر دهید.");
                } catch (Throwable $e) { }
                header('Location: index.php');
                exit;
            }
            DB::update('admins', ['last_login' => now(), 'last_ip' => $clientIp], 'id = :id', [':id' => (int)$row['id']]);
            if (class_exists('Audit')) Audit::log('login.ok', ['ip' => $clientIp], 'web:' . (string)$row['username']);
            $startAdminSession((int)$row['id']);
            header('Location: index.php');
            exit;
        } else {
            // زمان پاسخ برای «کاربر ناموجود» و «رمز غلط» یکسان شود (User Enumeration)
            if (!$row) password_verify($p, '$2y$12$C6UzMDM.H6dfI/f/IKcEe.rXelJDtj9pGSCbC.MCEyMbCTLAaTfSm');
            try { Security::noteFail($clientIp, $u); } catch (Throwable $e) { }
            if (class_exists('Audit')) Audit::log('login.fail', ['ip' => $clientIp, 'user' => mb_substr($u, 0, 40)], 'system');
            $loginError = 'نام کاربری یا رمز عبور اشتباه است.';
        }
    }
}

$ADMIN = null;
if (!empty($_SESSION['vs_admin'])) {
    $idleMax = max(5, (int)DB::setting('sec_session_idle_min', 120)) * 60;
    $uaHash  = md5((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if ((time() - (int)($_SESSION['vs_seen_at'] ?? 0)) > $idleMax) {
        // انقضای نشست بی‌استفاده
        unset($_SESSION['vs_admin']);
        $loginError = 'به دلیل بی‌فعالیتی طولانی از حساب خارج شدید. دوباره وارد شوید.';
    } elseif (!empty($_SESSION['vs_admin_ua']) && !hash_equals((string)$_SESSION['vs_admin_ua'], $uaHash)) {
        // کوکی نشست روی دستگاه دیگری استفاده شده است
        unset($_SESSION['vs_admin']);
        $loginError = 'نشست شما معتبر نیست. دوباره وارد شوید.';
    } else {
        $_SESSION['vs_seen_at'] = time();
        $ADMIN = DB::one('SELECT * FROM {p}admins WHERE id = :id', [':id' => (int)$_SESSION['vs_admin']]);
        if ($ADMIN && (int)($ADMIN['active'] ?? 1) === 0) {
            unset($_SESSION['vs_admin']);
            $ADMIN = null;
            $loginError = 'حساب مدیریتی شما غیرفعال شده است.';
        }
    }
}

/* ==================== صفحه ورود ==================== */
if (!$ADMIN) {
    $shopTitle = (string)DB::setting('shop_title', 'فروشگاه کانفیگ');
    ?>
    <!doctype html>
    <html lang="fa" dir="rtl"><head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
      <meta name="theme-color" content="#0B0E14">
      <title>ورود مدیر – <?= h($shopTitle) ?></title>
      <!-- فونت سیستم به‌جای CDN گوگل: سرعت بیشتر در ایران و حفظ حریم خصوصی -->
      <script>document.documentElement.className += ' js';</script>
      <link rel="stylesheet" href="../assets/app.css?v=<?= @filemtime(dirname(__DIR__) . '/assets/app.css') ?: '5' ?>">
</head><body>
    <div class="center-wrap"><div class="login-box">
      <div class="brand"><div class="logo">🛡️</div>
        <div><h1><?= h($shopTitle) ?></h1><div class="muted">پنل مدیریت</div></div></div>
      <div class="card">
        <?php if ($loginError): ?><div class="alert a-err">⚠️ <?= h($loginError) ?></div><?php endif; ?>
        <?php $tf2 = $_SESSION['vs_2fa'] ?? null; if (is_array($tf2) && time() - (int)($tf2['at'] ?? 0) <= 300): /* fixed79: مرحلهٔ دوم */ ?>
        <form method="post">
          <input type="hidden" name="do_2fa" value="1">
          <input type="hidden" name="_t" value="<?= h($CSRF) ?>">
          <div class="alert a-ok">🔐 کد ۶ رقمی به تلگرام شما ارسال شد. (اعتبار ۵ دقیقه)</div>
          <div class="field"><label>کد تایید</label><input class="mono" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus required></div>
          <button class="btn btn-primary btn-block" type="submit" id="tfGo">تایید و ورود</button>
          <div class="hint mt3" style="text-align:center"><a href="index.php?cancel2fa=1">↩️ بازگشت به ورود</a></div>
        </form>
        <?php else: ?>
        <form method="post">
          <input type="hidden" name="do_login" value="1">
          <input type="hidden" name="_t" value="<?= h($CSRF) ?>">
          <div class="field"><label>نام کاربری</label><input type="text" name="username" autofocus required></div>
          <div class="field"><label>رمز عبور</label>
            <div class="row" style="gap:6px">
              <input class="grow" type="password" name="password" id="pw" required>
              <button type="button" class="icon-btn" data-eye="#pw">👁</button>
            </div></div>
          <button class="btn btn-primary btn-block" type="submit">ورود به پنل</button>
        </form>
        <?php endif; ?>
        <div class="hint mt3">رمز را فراموش کرده‌اید؟ در ربات تلگرام ← پنل مدیریت ← «ورود به پنل تحت وب» لینک یک‌بارمصرف بگیرید.</div>
      </div>
    </div></div>
    <script src="../assets/app.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/app.js') ?: '5' ?>"></script>
    </body></html>
    <?php
    exit;
}

/* ==================== ابزارهای مشترک صفحات ==================== */
$CSRF = (string)$_SESSION['vs_csrf'];

function csrf_field(): string { return '<input type="hidden" name="_t" value="' . h($GLOBALS['CSRF']) . '">'; }
function csrf_ok(): bool { return hash_equals((string)$GLOBALS['CSRF'], (string)($_POST['_t'] ?? '')); }
function pv(string $k, $d = '') { return $_POST[$k] ?? $d; }
function pint(string $k, int $d = 0): int { return (int)preg_replace('/[^\\d\\-]/', '', en_num((string)($_POST[$k] ?? $d))); }
function pflt(string $k, float $d = 0): float { return (float)str_replace(',', '', en_num((string)($_POST[$k] ?? $d))); }
/**
 * خواندن یک فیلد متنی از فرم
 *  - اگر آرگومان دوم عدد باشد => حداکثر طول مجاز
 *  - اگر رشته باشد => مقدار پیش‌فرض
 */
function ptxt(string $k, $d = ''): string
{
    $max = is_int($d) || is_float($d) ? (int)$d : 0;
    $def = $max > 0 ? '' : (string)$d;
    $v   = trim((string)($_POST[$k] ?? $def));
    if ($max > 0 && mb_strlen($v) > $max) $v = mb_substr($v, 0, $max);
    return $v;
}
function pchk(string $k): int { return !empty($_POST[$k]) ? 1 : 0; }
function flash(string $type, string $msg): void { $_SESSION['vs_flash'][] = ['type' => $type, 'msg' => $msg]; }
function flashes(): array { $f = $_SESSION['vs_flash'] ?? []; unset($_SESSION['vs_flash']); return $f; }
function back(string $page, array $extra = []): void
{
    $q = array_merge(['p' => $page], $extra);
    header('Location: index.php?' . http_build_query($q));
    exit;
}
/* ---------- دسترسی سفارشی ---------- */

/** آیا مدیر فعلی دسترسی داده‌شده را دارد؟ */
function can(string $key): bool { return Perm::has($GLOBALS['ADMIN'] ?? null, $key); }

/** حداقل یکی از دسترسی‌ها */
function canAny(array $keys): bool { return Perm::any($GLOBALS['ADMIN'] ?? null, $keys); }

/** مدیر کل؟ */
function isSuper(): bool { return Perm::isSuper($GLOBALS['ADMIN'] ?? null); }

/**
 * توقف عملیات وقتی دسترسی نیست – پیام خطا + بازگشت
 */
function need(string $key, string $page = ''): void
{
    if (can($key)) return;
    flash('err', '⛔ شما به این عملیات دسترسی ندارید: <b>' . h(Perm::label($key)) . '</b>');
    back($page !== '' ? $page : (string)($_GET['p'] ?? 'dashboard'));
}

/** کادر «دسترسی ندارید» برای نمایش در صفحه */
function denyBox(string $what = ''): string
{
    return '<div class="card"><div class="empty"><div class="ic">🔒</div>'
        . '<b>دسترسی محدود است</b><br>'
        . '<span class="muted">' . ($what !== '' ? h($what) : 'شما مجوز دیدن این بخش را ندارید.') . '</span><br>'
        . '<span class="muted" style="font-size:12px">برای دریافت دسترسی با مدیر کل هماهنگ کنید.</span>'
        . '</div></div>';
}

function badge(string $status): string
{
    $map = [
        'active'   => ['b-green', 'فعال'],
        'expired'  => ['b-orange', 'منقضی'],
        'disabled' => ['b-gray', 'غیرفعال'],
        'deleted'  => ['b-red', 'حذف شده'],
        'pending'  => ['b-orange', 'در انتظار'],
        'approved' => ['b-green', 'تایید شده'],
        'rejected' => ['b-red', 'رد شده'],
        'paid'     => ['b-green', 'پرداخت شده'],
        'failed'   => ['b-red', 'ناموفق'],
        'open'     => ['b-orange', 'باز'],
        'answered' => ['b-green', 'پاسخ داده شده'],
        'closed'   => ['b-gray', 'بسته'],
    ];
    [$cls, $label] = $map[$status] ?? ['b-gray', $status];
    return '<span class="badge ' . $cls . '">' . h($label) . '</span>';
}

/* شمارنده‌های منو */
$pendingPay = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE status = 'pending'", [], 0);
$openTk     = (int)DB::val("SELECT COUNT(*) FROM {p}tickets WHERE status = 'open'", [], 0);

/* وضعیت لحظه‌ای سیستم برای نوار بالا */
$hbFile = APP_ROOT . '/storage/last-cron.txt';
$hbAge  = is_file($hbFile) ? (time() - (int)@filemtime($hbFile)) : -1;
$sysOk  = $hbAge >= 0 && $hbAge <= 1800;
$sysTip = $hbAge < 0
    ? 'کران‌جاب هنوز اجرا نشده است — برای تمدید و هشدارها لازم است'
    : ($sysOk
        ? 'سیستم سالم است — آخرین اجرای کران‌جاب ' . fa_num((int)round($hbAge / 60)) . ' دقیقه پیش'
        : 'کران‌جاب ' . fa_num((int)round($hbAge / 60)) . ' دقیقه است اجرا نشده است');

$PAGES = [
    'dashboard' => ['داشبورد', '📊', 'نمای کلی'],
    'panels'    => ['سرورها و پنل‌ها', '🖧', 'مدیریت پنل‌ها و ظرفیت سرورها'],
    'products'  => ['محصولات', '📦', 'طرح‌های فروش'],
    'services'  => ['سرویس‌ها', '🔑', 'اکانت‌های ساخته شده'],
    'stock'     => ['انبار ملی', '🏪', 'فروش دستی کانفیگ و اکانت'],
    'users'     => ['کاربران', '👥', 'مدیریت کاربران'],
    'payments'  => ['پرداخ��‌ها', '💳', 'تراکنش و سفارش'],
    'codes'     => ['کد تخفیف و هدیه', '🎟', 'کدها'],
    'tickets'   => ['پشتیبانی', '🆘', 'تیکت‌ها'],
    'tutorials' => ['آموزش‌ها', '🎓', 'مطالب آموزشی'],
    'resellers'  => ['نمایندگی', '🏷', 'نمایندگان و درخواست‌ها'],
    'gateways'   => ['درگاه‌های پرداخت', '🏦', 'کارت و ارز دیجیتال'],
    'cards'      => ['احراز کارت', '💳', 'تایید کارت بانکی کاربران'],
    'botbuttons' => ['دکمه‌های ربات', '🎛', 'افزودن، حذف و رنگ'],
    'join'       => ['جوین اجباری', '📢', 'عضویت اجباری در کانال‌ها'],
    'bottexts'   => ['متن‌های ربات', '💬', 'ویرایش همهٔ پیام‌ها'],
    'subs'      => ['مدیریت ساب', '🛰', 'آپشن‌های اشتراک'],
    'settings'  => ['تنظیمات', '⚙️', 'پرداخت و ربات'],
    'admins'    => ['مدیران پنل', '🛡️', 'دسترسی‌های سفارشی'],
    'backup'    => ['بکاپ و بازگردانی', '💾', 'پشتیبان و بازیابی'],
    'update'    => ['به‌روزرسانی', '⬆️', 'نسخه جدید'],
    'audit'     => ['لاگ اقدامات', '📝', 'کی چه کاری کرد'],
    'export'    => ['خروجی CSV', '📤', 'کاربران، سرویس‌ها، پرداخت‌ها'], /* fixed79 */
    'health'    => ['سلامت سیستم', '🩺', 'وضعیت و عیب‌یابی'],
];

$NAV_GROUPS = [
    '📈 فروش و عملیات'   => ['dashboard', 'panels', 'products', 'services', 'stock'],
    '👥 کاربران و مالی'  => ['users', 'payments', 'cards', 'codes', 'gateways'],
    '🏷 نمایندگی'         => ['resellers'],
    '🆘 پشتیبانی و محتوا' => ['tickets', 'tutorials'],
    '⚙️ پیکربندی'         => ['settings', 'join', 'subs', 'botbuttons', 'bottexts', 'admins'],
    '🛠 نگهداری سیستم'  => ['backup', 'update', 'health', 'audit', 'export'],
];

/* فیلتر منو بر اساس دسترسی مدیر */
$VISIBLE = [];
foreach (array_keys($PAGES) as $k) if (Perm::canPage($ADMIN, $k)) $VISIBLE[] = $k;

$NAV_GROUPS_VISIBLE = [];
foreach ($NAV_GROUPS as $gname => $items) {
    $keep = array_values(array_filter($items, static fn($k) => in_array($k, $VISIBLE, true)));
    if ($keep) $NAV_GROUPS_VISIBLE[$gname] = $keep;
}

$page = (string)($_GET['p'] ?? '');
// فقط نام‌های موجود در فهرست سفید پذیرفته می‌شوند
if ($page === '' || !preg_match('/^[a-z0-9_]+$/', $page) || !isset($PAGES[$page])) $page = 'dashboard';

/* اگر دسترسی ندارد، به اولین صفحه‌ی مجاز می‌رود */
$denied = false;
if (!Perm::canPage($ADMIN, $page)) {
    $fallback = Perm::firstPage($ADMIN, array_keys($PAGES));
    if ($fallback !== '' && $fallback !== $page) {
        $page = $fallback;
    } else {
        $denied = true;
    }
}
// basename لایهٔ دوم محافظت در برابر Path Traversal است
$pageFile = __DIR__ . '/pages/' . basename($page) . '.php';

/* 0.0.2 #6: سپر سراسری CSRF روی همهٔ درخواست‌های POST پنل.
   پیش‌فرض حالت گزارشی است؛ با تنظیم sec_csrf_strict = 1 درخواست بدون توکن مسدود می‌شود. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_ok()) {
    $csrfStrict = (string)DB::setting('sec_csrf_strict', '0') === '1';
    $csrfAct    = (string)preg_replace('/[^a-z0-9_\-]/i', '', (string)($_POST['act'] ?? ($_POST['action'] ?? '')));
    app_log('sec', 'csrf ' . ($csrfStrict ? 'blocked' : 'report') . ': p=' . $page . ' act=' . $csrfAct . ' ip=' . $clientIp);
    try {
        if (class_exists('Logs')) {
            Logs::send('security', Logs::fmt('🚫 درخواست بدون توکن امنیتی', [
                'صفحه'  => $page,
                'اقدام'  => $csrfAct !== '' ? $csrfAct : '-',
                'مدیر'   => (string)($ADMIN['username'] ?? '-'),
                'آی‌پی'  => $clientIp !== '' ? $clientIp : '-',
                'نتیجه'  => $csrfStrict ? 'مسدود شد' : 'فقط گزارش',
            ]));
        }
    } catch (Throwable $e) { }
    if ($csrfStrict) {
        http_response_code(419);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html dir="rtl" lang="fa"><meta charset="utf-8"><title>توکن امنیتی نامعتبر</title>'
            . '<div style="font:15px/2.1 Tahoma,sans-serif;max-width:520px;margin:60px auto;padding:24px;'
            . 'border:1px solid #ddd;border-radius:14px;text-align:center">'
            . '<h2 style="margin:0 0 10px">🚫 توکن امنیتی نامعتبر است</h2>'
            . '<p>این درخواست بدون توکن معتبر فرستاده شد و برای جلوگیری از حملهٔ CSRF مسدود شد.<br>'
            . 'صفحه را تازه کنید و دوباره تلاش کنید.</p>'
            . '<p><a href="index.php?p=' . h($page) . '">بازگشت به صفحه</a></p></div>';
        exit;
    }
}

/* fixed75: لاگ اقدامات مدیران — هر POST پنل وب پس از اجرا (حتی با redirect) ثبت می‌شود */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$denied && $page !== 'audit' && class_exists('Audit')) {
    $auditAct = (string)($_POST['act'] ?? ($_POST['action'] ?? 'save'));
    if ($auditAct !== '' && preg_match('/^[a-z0-9_\-]{1,40}$/i', $auditAct)) {
        register_shutdown_function(static function () use ($page, $auditAct): void {
            try { Audit::webHook($page, $auditAct); } catch (Throwable $e) { }
        });
    }
}

/* اجرای منطق صفحه پیش از خروجی HTML (برای امکان redirect) */
$RENDER = null;
if ($denied) {
    $RENDER = denyBox('حساب شما فعلاً هیچ دسترسی فعالی ندارد.');
} elseif (is_file($pageFile)) {
    /* خطای یک صفحه نباید لایه را از بین ببرد (وگرنه صفحه بدون CSS و نیمه رندر می شود) */
    ob_start();
    try {
        include $pageFile;
        $RENDER = ob_get_clean();
    } catch (Throwable $e) {
        $partial = ob_get_clean();
        if (function_exists('app_log')) {
            app_log('admin', 'page render failed: ' . basename($page), ['err' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine(), 'cls' => get_class($e)]);
        }
        $dbg = get_class($e) . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        $RENDER = (is_string($partial) ? $partial : '')
            . '<div class="alert a-err mt3">⛔️ خطا در نمایش بخش «' . h((string)$page) . '»: ' . h($e->getMessage())
            . '<div class="hint mono ltr mt3">' . h($dbg) . '</div></div>';
    }
} else {
    $RENDER = '<div class="card"><div class="empty"><div class="ic">🚧</div>این بخش در دسترس نیست.</div></div>';
}
$FLASH = flashes();
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0B0E14">
<title><?= h($PAGES[$page][0]) ?> – پنل مدیریت</title>
<!-- فونت سیستم به‌جای CDN گوگل: سرعت بیشتر در ایران و حفظ حریم خصوصی -->
<script>document.documentElement.className += ' js';</script>
<link rel="stylesheet" href="../assets/app.css?v=<?= @filemtime(dirname(__DIR__) . '/assets/app.css') ?: '5' ?>">
</head>
<body>
<?php
/* منوی پایین موبایل: چهار مقصد پرکاربردد که مدیر به آن‌ها دسترسی دارد */
$BOTNAV = [];
foreach (['dashboard', 'users', 'payments', 'services', 'resellers', 'panels', 'tickets', 'settings'] as $bk) {
    if (count($BOTNAV) >= 4) break;
    if (in_array($bk, $VISIBLE, true)) $BOTNAV[] = $bk;
}
$BADGE_OF = ['payments' => $pendingPay, 'tickets' => $openTk];
?>
<div class="shell">

  <aside class="side" id="vsSide">

    <div class="sd-head">
      <div class="sd-brand">
        <div class="logo">🛡️</div>
        <div class="grow">
          <div class="name"><?= h((string)DB::setting('shop_title', 'فروشگاه کانفیگ')) ?></div>
          <div class="sub"><?= h(defined('APP_BRAND') ? APP_BRAND : 'SR-BOT') ?> • <?= h(APP_VERSION) ?></div>
        </div>
        <button type="button" class="sd-x" data-burger aria-label="بستن منو">✕</button>
      </div>

      <div class="sd-srch">
        <span class="i">🔎</span>
        <input type="search" id="sdQ" autocomplete="off" placeholder="جستجوی بخش‌ها…">
        <button type="button" class="c" id="sdQx" aria-label="پاک کردن">✕</button>
      </div>

      <div class="sd-chips">
        <a class="sd-chip <?= $sysOk ? 'g' : 'o' ?>" href="index.php?p=health" title="<?= h($sysTip) ?>">
          <i></i><?= $sysOk ? 'سالم' : 'نیاز به بررسی' ?>
        </a>
        <?php if ($pendingPay > 0 && in_array('payments', $VISIBLE, true)): ?>
          <a class="sd-chip r" href="index.php?p=payments">💳 <?= fa_num($pendingPay) ?> پرداخت</a>
        <?php endif; ?>
        <?php if ($openTk > 0 && in_array('tickets', $VISIBLE, true)): ?>
          <a class="sd-chip b" href="index.php?p=tickets">🎫 <?= fa_num($openTk) ?> تیکت</a>
        <?php endif; ?>
      </div>
    </div>

    <nav class="nav sd-nav" id="sdNav">
      <?php foreach ($NAV_GROUPS_VISIBLE as $gname => $items): ?>
        <div class="sd-sec" data-sec="<?= h($gname) ?>">
          <button type="button" class="sd-g" data-sd-g>
            <span class="ch">▾</span>
            <span class="t"><?= h($gname) ?></span>
            <span class="n"><?= fa_num(count($items)) ?></span>
          </button>
          <div class="sd-items">
            <?php foreach ($items as $key): $info = $PAGES[$key]; $bn = (int)($BADGE_OF[$key] ?? 0); ?>
              <a class="sd-i<?= $page === $key ? ' on' : '' ?>" href="index.php?p=<?= h($key) ?>"
                 data-q="<?= h(mb_strtolower($info[0] . ' ' . $info[2] . ' ' . $key)) ?>">
                <span class="ic"><?= $info[1] ?></span>
                <span class="tx">
                  <b><?= h($info[0]) ?></b>
                  <i><?= h($info[2]) ?></i>
                </span>
                <?php if ($bn > 0): ?><span class="count"><?= fa_num($bn > 99 ? 99 : $bn) ?></span><?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="sd-none" id="sdNone" style="display:none">بخشی با این نام پیدا نشد.</div>
    </nav>

    <div class="side-foot sd-foot">
      <a class="sd-me" href="index.php?p=admins">
        <span class="av"><?= isSuper() ? '👑' : '👤' ?></span>
        <span class="tx">
          <b><?= h((string)($ADMIN['name'] ?: $ADMIN['username'])) ?></b>
          <i><?= isSuper() ? 'مدیر کل • دسترسی کامل' : h(mb_substr(Perm::summary($ADMIN), 0, 40)) ?></i>
        </span>
        <span class="go">‹</span>
      </a>
      <div class="sd-fb">
        <a class="btn grow" href="index.php?logout=1&amp;_t=<?= h($CSRF) ?>">🚪 خروج</a>
        <button type="button" class="icon-btn" data-theme-toggle title="تغییر پوسته"><span data-theme-icon>🌓</span></button>
        <a class="icon-btn" target="_blank" rel="noopener" href="<?= h(app_url('index.php')) ?>" title="باز کردن ربات">🤖</a>
      </div>
      <div class="sd-cp"><?= h(defined('APP_COPYRIGHT') ? APP_COPYRIGHT : '') ?></div>
    </div>
  </aside>

  <main class="main">
    <header class="topbar">
      <button type="button" class="icon-btn burger" data-burger aria-label="منو">☰</button>
      <div class="title">
        <span><?= $PAGES[$page][1] ?> <?= h($PAGES[$page][0]) ?></span>
        <span class="muted"><?= h($PAGES[$page][2]) ?></span>
      </div>
      <div class="row" style="flex-wrap:nowrap">
        <a class="sys-dot <?= $sysOk ? '' : 'warn' ?>" href="index.php?p=health" title="<?= h($sysTip) ?>"><i></i><span class="lb"><?= $sysOk ? 'سالم' : 'بررسی' ?></span></a>
        <a class="icon-btn" target="_blank" rel="noopener" href="<?= h(app_url('index.php')) ?>" title="باز کردن ربات">🤖</a>
        <button type="button" class="icon-btn" data-theme-toggle title="تغییر پوسته"><span data-theme-icon>🌓</span></button>
      </div>
      <div class="pg-search">
        <input type="search" id="pgSearch" autocomplete="off" placeholder="🔎 جستجوی سریع صفحه‌ها">
        <div class="pg-list" id="pgList"></div>
      </div>
    </header>

    <div class="content">
      <?php foreach ($FLASH as $f): ?>
        <div class="alert a-<?= h($f['type']) ?>"><?= $f['msg'] ?></div>
      <?php endforeach; ?>
      <?= $RENDER ?>
    </div>
  </main>

  <nav class="botnav">
    <?php foreach ($BOTNAV as $bk): $bi = $PAGES[$bk]; $bn = (int)($BADGE_OF[$bk] ?? 0); ?>
      <a class="<?= $page === $bk ? 'on' : '' ?>" href="index.php?p=<?= h($bk) ?>">
        <span class="ic"><?= $bi[1] ?></span><span class="t"><?= h($bi[0]) ?></span>
        <?php if ($bn > 0): ?><span class="dot"><?= fa_num($bn > 99 ? 99 : $bn) ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
    <button type="button" data-burger>
      <span class="ic">☰</span><span class="t">همه بخش‌ها</span>
    </button>
  </nav>
</div>
<script>
window.VS_PAGES = <?= jenc(array_values(array_map(function ($k) use ($PAGES) {
    return ['key' => $k, 'label' => $PAGES[$k][0], 'emoji' => $PAGES[$k][1], 'sub' => $PAGES[$k][2]];
}, $VISIBLE))) ?>;
</script>
<script src="../assets/app.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/app.js') ?: '5' ?>"></script>
</body>
</html>
