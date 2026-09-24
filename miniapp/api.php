<?php

/**
 * API مینی‌اپ تلگرام
 * ---------------------------------------------------------------
 * تمام درخواست‌ها POST و با هدر یا بدنهٔ حاوی initData تلگرام هستند.
 * صحت initData با HMAC-SHA256 و کلید HMAC("WebAppData", botToken) بررسی می‌شود،
 * پس هیچ‌کس نمی‌تواند خود را جای کاربر دیگری جا بزند.
 */

/* ============ output guard: always clean JSON ============ */
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
if (!headers_sent()) { ob_start(); }

function ma_flush_junk(): void
{
    while (ob_get_level() > 0) { @ob_end_clean(); }
}

function ma_is_admin_hint(): bool
{
    $raw = (string)($_SERVER['HTTP_X_TG_INIT_DATA'] ?? '');
    if ($raw === '') { return false; }
    $q = [];
    parse_str($raw, $q);
    $u  = json_decode((string)($q['user'] ?? ''), true);
    $id = is_array($u) ? (int)($u['id'] ?? 0) : 0;
    if ($id <= 0 || !function_exists('is_admin_id')) { return false; }
    try { return (bool)is_admin_id($id); } catch (Throwable $e) { return false; }
}

function ma_debug_on(): bool
{
    if (ma_is_admin_hint()) { return true; }
    try { return class_exists('DB') && (string)DB::setting('miniapp_debug', '0') === '1'; }
    catch (Throwable $e) { return false; }
}

function ma_crash(string $kind, string $msg, string $file = '', int $line = 0): void
{
    /* ضدحلقه: اگر هنگام ساختِ پیام خطا دوباره خطایی رخ دهد، سادهٔ JSON پاسخ می‌دهیم */
    static $busy = false;
    if ($busy) {
        if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
        echo '{"ok":false,"code":"server_error","message":"\u062e\u0637\u0627\u06cc \u062f\u0627\u062e\u0644\u06cc \u0633\u0631\u0648\u0631."}';
        exit;
    }
    $busy = true;
    $GLOBALS['MA_SENT'] = true;

    ma_flush_junk();
    $where = ($file !== '' ? basename($file) : '?') . ':' . $line;
    if (function_exists('app_log')) { @app_log('miniapp', strtoupper($kind) . ' ' . $msg . ' @ ' . $where); }
    $show = false;
    try { $show = ma_debug_on(); } catch (Throwable $e) { $show = false; }
    if ($show) {
        $text = '⚠️ خطای سرور: ' . mb_substr($msg, 0, 300) . '  —  ' . $where;
    } else {
        $text = 'خطای داخلی سرور. لطفاً چند لحظه بعد دوباره تلاش کنید.';
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'code' => 'server_error', 'message' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* هندلرها تابعِ نام‌دار هستند تا در صورت نیاز بتوان دوباره نصبشان کرد */
function ma_error_handler($no, $str, $file = '', $line = 0)
{
    if (!(error_reporting() & $no)) { return true; }
    $soft = [E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_WARNING, E_USER_NOTICE, E_USER_DEPRECATED];
    if (in_array($no, $soft, true)) {
        if (function_exists('app_log')) { @app_log('miniapp', 'WARN ' . $str . ' @ ' . basename((string)$file) . ':' . $line); }
        return true;
    }
    throw new ErrorException($str, 0, (int)$no, (string)$file, (int)$line);
}

function ma_exception_handler($e): void
{
    ma_crash('exception', $e->getMessage(), (string)$e->getFile(), (int)$e->getLine());
}

/**
 * تور ایمنی نهایی.
 * اگر به هر دلیلی (مثلاً هندلر دیگری که استثنا را فقط لاگ می‌کند) هیچ بدنه‌ای
 * تولید نشود، مرورگر یک پاسخ 500 با بدنهٔ خالی می‌گیرد و JSON.parse شکست
 * می‌خورد. اینجا حتماً یک JSON معتبر برمی‌گردانیم.
 */
function ma_shutdown_handler(): void
{
    if (!empty($GLOBALS['MA_SENT'])) { return; }

    $er   = error_get_last();
    $hard = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if ($er && in_array($er['type'], $hard, true)) {
        ma_crash('fatal', (string)$er['message'], (string)$er['file'], (int)$er['line']);
        return;
    }

    ma_crash('silent', 'درخواست بدون خروجی پایان یافت.', __FILE__, 0);
}

set_error_handler('ma_error_handler');
set_exception_handler('ma_exception_handler');
register_shutdown_function('ma_shutdown_handler');

require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Referrer-Policy: no-referrer');
header_remove('X-Frame-Options');

/* ============================ خروجی ============================ */

function ma_out(array $d, int $code = 200): void
{
    $GLOBALS['MA_SENT'] = true;
    ma_flush_junk();
    http_response_code($code);
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ma_fail(string $msg, int $code = 400): void
{
    ma_out(['ok' => false, 'message' => $msg], $code);
}

if (!app_installed()) ma_fail('ربات هنوز نصب نشده است.', 503);

try {
    boot();
} catch (Throwable $e) {
    ma_fail('خطای اتصال به پایگاه داده.', 500);
}

if ((string)DB::setting('miniapp_enabled', '1') !== '1') {
    ma_fail('مینی‌اپ در حال حاضر غیرفعال است.', 403);
}

/* ============================ ورودی ============================ */

$rawBody = file_get_contents('php://input') ?: '';
$in      = json_decode($rawBody, true);
if (!is_array($in)) $in = [];

$action   = trim((string)($in['action'] ?? ($_GET['action'] ?? '')));
$initData = (string)($in['initData'] ?? ($_SERVER['HTTP_X_TG_INIT_DATA'] ?? ''));

/* ==================== اعتبارسنجی initData ==================== */

/**
 * رشتهٔ initData را به آرایه تبدیل می‌کند (بدون دستکاری کلیدها)
 */
function ma_parse_init(string $s): array
{
    $out = [];
    foreach (explode('&', $s) as $pair) {
        if ($pair === '') continue;
        $kv = explode('=', $pair, 2);
        $k  = urldecode($kv[0]);
        if ($k === '') continue;
        $out[$k] = urldecode($kv[1] ?? '');
    }
    return $out;
}

function ma_hash_ok(array $data, string $hash, string $token, array $skip): bool
{
    foreach ($skip as $s) unset($data[$s]);
    ksort($data);

    $lines = [];
    foreach ($data as $k => $v) $lines[] = $k . '=' . $v;

    $secret = hash_hmac('sha256', $token, 'WebAppData', true);
    $calc   = hash_hmac('sha256', implode("\n", $lines), $secret);

    return hash_equals($calc, strtolower($hash));
}

/**
 * @return array{ok:bool,user:array,message:string}
 */
function ma_auth(string $initData): array
{
    $token = (string)cfg('bot.token', '');
    if ($token === '')    return ['ok' => false, 'user' => [], 'message' => 'توکن ربات تنظیم نشده است.'];
    if ($initData === '') return ['ok' => false, 'user' => [], 'message' => 'این صفحه باید از داخل تلگرام باز شود.'];

    $data = ma_parse_init($initData);
    $hash = (string)($data['hash'] ?? '');
    if ($hash === '') return ['ok' => false, 'user' => [], 'message' => 'اطلاعات ورود تلگرام ناقص است.'];

    /* تلگرام ممکن است فیلد signature هم بفرستد؛ هر دو حالت بررسی می‌شود */
    $ok = ma_hash_ok($data, $hash, $token, ['hash'])
       || ma_hash_ok($data, $hash, $token, ['hash', 'signature']);

    if (!$ok) return ['ok' => false, 'user' => [], 'message' => 'اعتبارسنجی تلگرام ناموفق بود. اپ را ببندید و دوباره باز کنید.'];

    $authDate = (int)($data['auth_date'] ?? 0);
    /* 0.0.2 #7: auth_date اجباری شد و پنجرهٔ اعتبار از تنظیمات خوانده می‌شود (ma_init_ttl_min دقیقه) */
    $maTtlMin = (int)DB::setting('ma_init_ttl_min', '1440');
    if ($maTtlMin < 5)     $maTtlMin = 5;
    if ($maTtlMin > 10080) $maTtlMin = 10080;
    if ($authDate <= 0 || (time() - $authDate) > ($maTtlMin * 60)) {
        return ['ok' => false, 'user' => [], 'message' => 'نشست شما منقضی شده است. اپ را ببندید و دوباره باز کنید.'];
    }

    $tgUser = json_decode((string)($data['user'] ?? ''), true);
    if (!is_array($tgUser) || (int)($tgUser['id'] ?? 0) <= 0) {
        return ['ok' => false, 'user' => [], 'message' => 'شناسهٔ کاربر تلگرام یافت نشد.'];
    }

    return ['ok' => true, 'user' => $tgUser, 'message' => ''];
}

$auth = ma_auth($initData);
if (!$auth['ok']) {
    /* 0.0.2 #7-log: گزارش تلاش‌های پی‌درپی برای دور زدن اعتبارسنجی تلگرام */
    try {
        if (class_exists('RateLimit') && class_exists('Logs')) {
            $maIp  = class_exists('Guard') ? Guard::clientIp() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $maBad = RateLimit::hit('ma:bad:' . ($maIp !== '' ? $maIp : 'unknown'), 20, 600);
            if ((int)($maBad['count'] ?? 0) === 21) {
                Logs::send('security', Logs::fmt('🚫 تلاش‌های ناموفق ورود به مینی‌اپ', [
                    'آی‌پی' => $maIp !== '' ? $maIp : '-',
                    'تعداد' => 'بیش از ۲۰ بار در ۱۰ دقیقه',
                    'پیام'  => mb_substr((string)$auth['message'], 0, 60),
                ]));
            }
        }
    } catch (Throwable $e) { }
    ma_fail($auth['message'], 401);
}

$tg   = (int)$auth['user']['id'];

/* 0.0.2 #19: request fingerprint for trial-abuse rules (real client ip, only here - not on the bot webhook) */
if (class_exists('Svc')) {
    $maFpIp = class_exists('Guard') ? Guard::clientIp() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
    Svc::setTestFp($maFpIp, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

/* 0.0.2 #7-rate: محدودیت نرخ درخواست بر پایهٔ شناسهٔ تلگرام (نه آی‌پی؛ اپراتورهای ایران آی‌پی مشترک می‌دهند) */
if (class_exists('RateLimit')) {
    $maMax = (int)DB::setting('ma_rate_per_min', '240');
    if ($maMax > 0) {
        $maHit = RateLimit::hit('ma:' . $tg, $maMax, 60);
        if (empty($maHit['ok'])) {
            ma_fail('درخواست‌های شما بیش از حد مجاز است؛ ' . (int)($maHit['retry'] ?? 30) . ' ثانیه دیگر دوباره تلاش کنید.', 429);
        }
    }
}
$user = DB::one('SELECT * FROM {p}users WHERE tg_id = :t', [':t' => $tg]);

if (!$user) {
    DB::insert('users', [
        'tg_id'      => $tg,
        'username'   => (string)($auth['user']['username'] ?? ''),
        'first_name' => (string)($auth['user']['first_name'] ?? ''),
        'last_name'  => (string)($auth['user']['last_name'] ?? ''),
        'balance'    => 0,
        'created_at' => now(),
        'last_seen'  => now(),
    ]);
    $user = DB::one('SELECT * FROM {p}users WHERE tg_id = :t', [':t' => $tg]);
}
if (!$user) ma_fail('ساخت حساب کاربری ناموفق بود.', 500);

if ((int)($user['is_banned'] ?? 0) === 1) ma_fail('دسترسی شما مسدود شده است.', 403);

DB::update('users', ['last_seen' => now(), 'miniapp_at' => now()], 'id = :id', [':id' => (int)$user['id']]);

$UID = (int)$user['id'];

/* ============================ کمکی‌ها ============================ */

function ma_money(int $n): string { return money($n) . ' ' . currency(); }

/** وضعیت واقعی روش پرداخت: تنظیم سراسری + وضعیت درگاه‌ها در «درگاه‌های پرداخت» */
function ma_pay_on(string $kind, array $user): bool
{
    $isRs = false;
    try { $isRs = class_exists('Reseller') && Reseller::isReseller($user); } catch (Throwable $e) { $isRs = false; }
    try {
        if ($kind === 'card' && class_exists('Gateway') && method_exists('Gateway', 'cardOn')) {
            return Gateway::cardOn($isRs);
        }
        if ($kind === 'crypto' && class_exists('Gateway') && method_exists('Gateway', 'cryptoOn')) {
            return Gateway::cryptoOn($isRs);
        }
    } catch (Throwable $e) {
    }
    $key = $kind === 'card' ? 'card_enabled' : 'crypto_enabled';
    return (string)DB::setting($key, '1') === '1';
}

function ma_secure(string $action, array $u): void
{
    if (!class_exists('Security')) return;
    if (Security::needs($u, $action)) {
        ma_out([
            'ok'      => false,
            'need'    => 'verify',
            'message' => 'برای ادامه باید حساب خود را تایید کنید.',
        ], 200);
    }
}

function ma_service_row(array $s): array
{
    /* دامنهٔ اختصاصیِ نماینده روی لینک ساب اعمال می‌شود */
    static $__rsDomUser = null;
    if ($__rsDomUser === null && class_exists('Reseller') && method_exists('Reseller', 'applyDomain')) {
        $__rsDomUser = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => (int)($s['user_id'] ?? 0)]) ?: [];
    }

    $vol  = (float)($s['volume_gb'] ?? 0);
    $used = (int)($s['used_bytes'] ?? 0);
    $left = $vol > 0 ? max(0, round($vol - bytes2gb($used), 2)) : 0;
    $pct  = $vol > 0 ? min(100, (int)round(bytes2gb($used) / max(0.01, $vol) * 100)) : 0;

    /* 0.0.2 #happ-only-api: محصول «فقط لینک هپ» — ساب و کانفیگ خالی برگردانده می\u200cشود */
    $__happOnly = class_exists('Svc') && method_exists('Svc', 'wantsHapp')
        && Svc::wantsHapp(Svc::deliverMode($s))
        && class_exists('Links') && Links::supported($s);
    /* 0.0.2 #dm-strict-api: حالت تحویل محصول روی مینی‌اپ هم اعمال می‌شود */
    $__dmMode = class_exists('Svc') && method_exists('Svc', 'deliverMode') ? Svc::deliverMode($s) : 'both';
    $__noSub  = $__happOnly || (class_exists('Svc') && method_exists('Svc', 'wantsSub') && !Svc::wantsSub($__dmMode));
    $__noCfg  = $__happOnly || (class_exists('Svc') && method_exists('Svc', 'wantsCfg') && !Svc::wantsCfg($__dmMode));

    return [
        'id'         => (int)$s['id'],
        'name'       => (string)$s['client_email'],
        'status'     => (string)$s['status'],
        'is_test'    => (int)($s['is_test'] ?? 0) === 1,
        'volume'     => $vol,
        'volume_txt' => $vol > 0 ? fa_num((string)round($vol, 2)) . ' گیگ' : '♾ نامحدود',
        'used_txt'   => fa_num(human_bytes($used)),
        'left_txt'   => $vol > 0 ? fa_num((string)$left) . ' گیگ' : '♾ نامحدود',
        'percent'    => $pct,
        'expire'     => (string)($s['expire_at'] ?? ''),
        'expire_txt' => !empty($s['expire_at']) ? to_jalali((string)$s['expire_at'], true) : '♾ نامحدود',
        'remain_txt' => !empty($s['expire_at']) ? remaining_human((string)$s['expire_at']) : '♾',
        'sub'        => $__noSub ? '' : ((class_exists('Reseller') && method_exists('Reseller', 'applyDomain') && is_array($__rsDomUser)
                ? Reseller::applyDomain((string)(class_exists('Svc') ? Svc::subUrl($s) : (string)($s['sub_link'] ?? '')), $__rsDomUser)
                : (class_exists('Svc') ? Svc::subUrl($s) : (string)($s['sub_link'] ?? '')))),
        'sub_own'    => $__noSub ? '' : (class_exists('Svc') ? Svc::localSub($s) : ''),
        'sub_main'   => $__noSub ? '' : (string)($s['sub_link'] ?? ''),
        'sub_code'   => $__noSub ? '' : (class_exists('Svc') && method_exists('Svc', 'subCode') ? Svc::subCode($s) : ''),
        /* دکمه‌های تمدید و حذف — از پنل قابل خاموش کردن است */
        'can_renew'  => class_exists('Svc') && method_exists('Svc', 'userRenewEnabled') && Svc::userRenewEnabled()
            && (string)DB::setting('ma_btn_renew', '1') === '1'
            && (int)($s['is_test'] ?? 0) !== 1 && (int)($s['is_reseller'] ?? 0) !== 1 && (string)($s['status'] ?? '') !== 'deleted',
        'can_del'    => class_exists('Svc') && method_exists('Svc', 'userDelEnabled')
            && (Svc::userDelEnabled()
                || (method_exists('Svc', 'deadDelEnabled') && Svc::deadDelEnabled() && Svc::isDead($s)))
            && (string)DB::setting('ma_btn_del', '1') === '1'
            && (int)($s['is_reseller'] ?? 0) !== 1 && (string)($s['status'] ?? '') !== 'deleted',
        'is_dead'    => class_exists('Svc') && method_exists('Svc', 'isDead') ? Svc::isDead($s) : false,
        'dead_txt'   => class_exists('Svc') && method_exists('Svc', 'deadLabel') && Svc::isDead($s) ? Svc::deadLabel($s) : '',
        'can_sync'   => (string)DB::setting('ma_btn_sync', '1') === '1',
        'can_tut'    => (string)DB::setting('ma_btn_tut', '1') === '1',
        /* 0.0.2 #ma-dev-flags — دکمه‌های دستگاه و لینک فقط روی پنل‌های پشتیبانی‌شده */
        'can_devs'   => class_exists('Devices') && Devices::supported($s),
        'can_links'  => class_exists('Links') && Links::supported($s),
        /* 0.0.2 #happ-only-ma: محصول «فقط لینک هپ» */
        'deliver'    => class_exists('Svc') && method_exists('Svc', 'deliverMode') ? Svc::deliverMode($s) : '',
        'happ_only'  => class_exists('Svc') && method_exists('Svc', 'wantsHapp')
            && Svc::wantsHapp(Svc::deliverMode($s))
            && class_exists('Links') && Links::supported($s),
        /* 0.0.2 #dm-strict-flags */
        'cfg_off'    => $__noCfg,
        'sub_off'    => $__noSub,
        'hwid'       => class_exists('Svc') && method_exists('Svc', 'hwidOn') ? Svc::hwidOn($s) : false,
        /* فقط خطوط کانفیگ معتبر برگردانده می شود، نه متن خام */
        'configs'    => $__noCfg ? [] : (class_exists('Svc') && method_exists('Svc', 'storedConfigs')
            ? Svc::storedConfigs($s)
            : array_values(array_filter(array_map('trim', explode("\n", (string)($s['config_link'] ?? '')))))),
        'days'       => (int)($s['days'] ?? 0),
        'group'      => (string)($s['group_key'] ?? ''),
        'grouped'    => trim((string)($s['group_key'] ?? '')) !== '',
        'quota_gb'   => (float)($s['group_quota_gb'] ?? 0),
        'used'       => $used,
        'used_gb'    => round(bytes2gb($used), 3),
        'synced'     => (string)($s['last_sync'] ?? ''),
        'synced_txt' => !empty($s['last_sync']) ? fa_num(remaining_human((string)$s['last_sync'])) : '—',
    ];
}

function ma_panel_flag(string $name): string
{
    $n   = mb_strtolower($name);
    $map = [
        'ترکیه' => '🇹🇷', 'turk' => '🇹🇷',
        'آلمان' => '🇩🇪', 'german' => '🇩🇪',
        'هلند' => '🇳🇱', 'nether' => '🇳🇱',
        'فرانس' => '🇫🇷', 'france' => '🇫🇷',
        'امارات' => '🇦🇪', 'dubai' => '🇦🇪',
        'انگل' => '🇬🇧', 'london' => '🇬🇧',
        'امریکا' => '🇺🇸', 'usa' => '🇺🇸',
        'فینلاند' => '🇫🇮', 'finland' => '🇫🇮',
        'مولتی' => '🌐', 'multi' => '🌐', 'ایران' => '🇮🇷',
    ];
    foreach ($map as $k => $v) {
        if (mb_strpos($n, mb_strtolower((string)$k)) !== false) return $v;
    }
    return '🖥';
}

function ma_product_row(array $p, string $panelName = ''): array
{
    $cat = trim((string)($p['category'] ?? ''));
    if ($cat === '') $cat = 'عمومی';

    $pid = (int)($p['panel_id'] ?? 0);
    if ($panelName === '') $panelName = trim((string)($p['panel_name'] ?? ''));
    if ($panelName === '') $panelName = $pid > 0 ? ('سرور ' . fa_num($pid)) : '—';

    return [
        'id'        => (int)$p['id'],
        'name'      => (string)$p['name'],
        'category'  => $cat,
        'panel_id'  => $pid,
        'panel'     => $panelName,
        'flag'      => ma_panel_flag($panelName),
        'desc'      => (string)($p['description'] ?? ''),
        'volume'    => (float)$p['volume_gb'],
        'vol_txt'   => (float)$p['volume_gb'] > 0 ? fa_num((string)round((float)$p['volume_gb'], 2)) . ' گیگ' : '♾ نامحدود',
        'days'      => (int)$p['days'],
        'days_txt'  => (int)$p['days'] > 0 ? fa_num((string)(int)$p['days']) . ' روز' : '♾ نامحدود',
        'ip_limit'  => (int)$p['ip_limit'],
        'ip_txt'    => (int)$p['ip_limit'] > 0 ? fa_num((string)(int)$p['ip_limit']) . ' دستگاه' : '♾ بدون محدودیت',
        'price'     => (int)$p['price'],
        'price_txt' => ma_money((int)$p['price']),
        'old_price' => (int)($p['old_price'] ?? 0),
        'old_txt'   => (int)($p['old_price'] ?? 0) > 0 ? ma_money((int)$p['old_price']) : '',
        'stock'     => (int)$p['stock'],
        'sold'      => (int)$p['sold'],
    ];
}

/* ============================ اکشن‌ها ============================ */

switch ($action) {

    /* ---------------- وضعیت اولیه ---------------- */
    case 'boot': {
        $u = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: $user;

        $svcTotal = (int)DB::val('SELECT COUNT(*) FROM {p}services WHERE user_id = :u AND status <> :d',
            [':u' => $UID, ':d' => 'deleted'], 0);
        $svcActive = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE user_id = :u AND status = 'active'",
            [':u' => $UID], 0);
        $orders = (int)DB::val("SELECT COUNT(*) FROM {p}orders WHERE user_id = :u AND status = 'paid'",
            [':u' => $UID], 0);
        $openTk = (int)DB::val("SELECT COUNT(*) FROM {p}tickets WHERE user_id = :u AND status <> 'closed'",
            [':u' => $UID], 0);

        $botUser = (string)cfg('bot.username', '');
        $verified = class_exists('Security') ? Security::isVerified($u) : true;

        $kinds = [];
        if (class_exists('Security')) {
            foreach (Security::activeKinds() as $k => $m) $kinds[] = ['key' => $k, 'label' => (string)$m['label']];
        }

        ma_out([
            'ok'   => true,
            'shop' => [
                'title'    => (string)DB::setting('shop_title', 'فروشگاه کانفیگ'),
                'currency' => currency(),
                'accent'   => (string)DB::setting('miniapp_accent', '#6C8CFF'),
                'app_name' => (string)DB::setting('miniapp_title', (string)DB::setting('shop_title', 'اپلیکیشن')),
                'support'  => trim((string)DB::setting('support_username', '')),
                'bot'      => $botUser,
                'rules'    => (string)DB::setting('rules_text', ''),
                'welcome'  => (string)DB::setting('welcome_text', ''),
                'maint'    => (string)DB::setting('maintenance', '0') === '1',
            ],
            'user' => [
                'tg_id'       => (int)$u['tg_id'],
                'name'        => trim(((string)$u['first_name']) . ' ' . ((string)$u['last_name'])),
                'username'    => (string)($u['username'] ?? ''),
                'phone'       => (string)($u['phone'] ?? ''),
                'email'       => (string)($u['email'] ?? ''),
                'balance'     => (int)$u['balance'],
                'balance_txt' => ma_money((int)$u['balance']),
                'total_paid'  => (int)$u['total_paid'],
                'paid_txt'    => ma_money((int)$u['total_paid']),
                'joined'      => to_jalali((string)$u['created_at']),
                'verified'    => $verified,
                'ref_link'    => $botUser !== '' ? 'https://t.me/' . $botUser . '?start=ref' . (int)$u['tg_id'] : '',
            ],
            'stats' => [
                'services' => $svcTotal,
                'active'   => $svcActive,
                'orders'   => $orders,
                'tickets'  => $openTk,
            ],
            'security' => [
                'mode'     => class_exists('Security') ? Security::mode() : 'off',
                'enabled'  => class_exists('Security') ? Security::enabled() : false,
                'kinds'    => $kinds,
                'code_len' => class_exists('Security') ? Security::codeLen() : 5,
            ],
            'flags' => [
                'test'   => (string)DB::setting('test_enabled', '1') === '1',
                'card'   => ma_pay_on('card', $user),
                'crypto' => ma_pay_on('crypto', $user),
                'nowpay' => class_exists('NowPay') ? NowPay::enabled() : false,
                'hooshpay' => class_exists('HooshPay') ? HooshPay::enabled() : false,
        'zarinpal' => class_exists('Zarinpal') ? Zarinpal::enabled() : false,
                'reseller' => (class_exists('Reseller') && Reseller::enabled() && Reseller::isReseller($user)),
                'stock'    => (class_exists('Stock') && Stock::enabled()),
            ],
        ]);
    }

    /* ---------------- محصولات ---------------- */
    case 'products': {
        $rows = DB::all("SELECT pr.*, pa.name AS panel_name
            FROM {p}products pr
            LEFT JOIN {p}panels pa ON pa.id = pr.panel_id
            WHERE pr.active = 1 AND pr.stock <> 0
            ORDER BY pr.sort ASC, pr.price ASC");

        $cats = [];
        $list = [];
        $pmap = [];
        foreach ($rows as $p) {
            /* fixed76: محصول «حجم و زمان دلخواه» از بخش پلن دلخواه (cus_info) عرضه می‌شود */
            if ((string)($p['type'] ?? 'fixed') === 'custom') continue;
            $r      = ma_product_row($p);
            $list[] = $r;

            $c = (string)$r['category'];
            if (!in_array($c, $cats, true)) $cats[] = $c;

            $pid = (int)$r['panel_id'];
            if (!isset($pmap[$pid])) {
                $pmap[$pid] = [
                    'id'    => $pid,
                    'name'  => (string)$r['panel'],
                    'flag'  => (string)$r['flag'],
                    'count' => 0,
                    'min'   => (int)$r['price'],
                    'cats'  => [],
                ];
            }
            $pmap[$pid]['count']++;
            if ((int)$r['price'] < (int)$pmap[$pid]['min']) $pmap[$pid]['min'] = (int)$r['price'];
            if (!in_array($c, $pmap[$pid]['cats'], true)) $pmap[$pid]['cats'][] = $c;
        }

        $panels = [];
        foreach ($pmap as $pv) {
            $pv['min_txt'] = ma_money((int)$pv['min']);
            $pv['cat_n']   = count($pv['cats']);
            $panels[]      = $pv;
        }
        usort($panels, static function (array $a, array $b): int {
            return (int)$b['count'] <=> (int)$a['count'];
        });

        ma_out([
            'ok'         => true,
            'categories' => $cats,
            'panels'     => $panels,
            'products'   => $list,
        ]);
    }

    /* ---------------- حجم و زمان دلخواه ---------------- */
    case 'cus_info':
    case 'cus_buy': {
        /* پیکربندی پلن دلخواه — آینهٔ Bot::cusCfg */
        $cusCfg = (static function (): ?array {
            /* fixed76: محصولات نوع «حجم و زمان دلخواه» بر تنظیمات قدیمی اولویت دارند (کلید گزینه = شناسهٔ محصول) */
            $cp = (class_exists('Bot') && method_exists('Bot', 'cusProducts')) ? Bot::cusProducts() : [];
            if ($cp) {
                $opts = [];
                foreach ($cp as $p) {
                    $pnRow = DB::one('SELECT * FROM {p}panels WHERE id = :id AND active = 1', [':id' => (int)$p['panel_id']]);
                    if (!$pnRow) continue;
                    $opts[(int)$p['id']] = [
                        'panel' => $pnRow, 'product' => $p, 'product_id' => (int)$p['id'], 'name' => (string)($p['name'] ?? ''),
                        'price_gb' => (int)($p['price_gb'] ?? 0), 'price_day' => (int)($p['price_day'] ?? 0),
                        'min_gb' => max(1, (int)($p['min_gb'] ?? 1)), 'max_gb' => max(1, (int)($p['max_gb'] ?? 100)),
                        'min_days' => max(1, (int)($p['min_days'] ?? 1)), 'max_days' => max(1, (int)($p['max_days'] ?? 90)),
                    ];
                }
                if ($opts) {
                    return [
                        'min_gb' => min(array_column($opts, 'min_gb')), 'max_gb' => max(array_column($opts, 'max_gb')),
                        'min_days' => min(array_column($opts, 'min_days')), 'max_days' => max(array_column($opts, 'max_days')),
                        'opts' => $opts, 'by_product' => true,
                    ];
                }
            }
            if ((int)DB::setting('cus_enabled', 0) !== 1) return null;
            $minG = max(1, (int)DB::setting('cus_min_gb', 5));
            $maxG = max($minG, (int)DB::setting('cus_max_gb', 100));
            $minD = max(1, (int)DB::setting('cus_min_days', 7));
            $maxD = max($minD, (int)DB::setting('cus_max_days', 90));
            $defG = max(0, (int)DB::setting('cus_price_gb', 0));
            $defD = max(0, (int)DB::setting('cus_price_day', 0));
            $pick = static function (int $panelId, int $pid): ?array {
                if ($pid > 0) {
                    $p = DB::one('SELECT * FROM {p}products WHERE id = :id AND active = 1', [':id' => $pid]);
                    if ($p && ($panelId <= 0 || (int)$p['panel_id'] === $panelId)) return $p;
                }
                if ($panelId > 0) {
                    $p = DB::one('SELECT * FROM {p}products WHERE panel_id = :pn AND active = 1 ORDER BY sort ASC, price ASC, id ASC LIMIT 1', [':pn' => $panelId]);
                    return $p ?: null;
                }
                return null;
            };
            $opts = [];
            $map  = jdec((string)DB::setting('cus_panels', ''), []);
            if (is_array($map)) {
                foreach ($map as $k => $row) {
                    if (!is_array($row)) continue;
                    $pnId = (int)$k > 0 ? (int)$k : (int)($row['pn'] ?? 0);
                    if ($pnId <= 0 || (int)($row['on'] ?? 0) !== 1) continue;
                    $pnRow = DB::one('SELECT * FROM {p}panels WHERE id = :id AND active = 1', [':id' => $pnId]);
                    if (!$pnRow) continue;
                    $prod = $pick($pnId, (int)($row['pid'] ?? 0));
                    if (!$prod) continue;
                    $pg = (int)($row['pg'] ?? 0) > 0 ? (int)$row['pg'] : $defG;
                    $pd = (int)($row['pd'] ?? 0) > 0 ? (int)$row['pd'] : $defD;
                    if ($pg <= 0 && $pd <= 0) continue;
                    $opts[$pnId] = ['panel' => $pnRow, 'product' => $prod, 'price_gb' => $pg, 'price_day' => $pd];
                }
            }
            if (!$opts && ($defG > 0 || $defD > 0)) {
                $legacy = (int)DB::setting('cus_product_id', 0);
                foreach (DB::all('SELECT * FROM {p}panels WHERE active = 1 ORDER BY sort ASC, id ASC') as $pnRow) {
                    $prod = $pick((int)$pnRow['id'], $legacy);
                    if (!$prod) continue;
                    $opts[(int)$pnRow['id']] = ['panel' => $pnRow, 'product' => $prod, 'price_gb' => $defG, 'price_day' => $defD];
                }
            }
            if (!$opts) return null;
            return ['min_gb' => $minG, 'max_gb' => $maxG, 'min_days' => $minD, 'max_days' => $maxD, 'opts' => $opts];
        })();
        if (!$cusCfg) ma_fail('بخش «حجم و زمان دلخواه» فعال نیست.');

        if ($action === 'cus_info') {
            $rows = [];
            foreach ($cusCfg['opts'] as $cpn => $o) {
                $rows[] = [
                    'panel_id'  => (int)$cpn,
                    'product_id'=> (int)($o['product_id'] ?? 0),
                    'name'      => (string)($o['panel']['name'] ?? ('سرور ' . (int)$cpn)) . (!empty($o['name']) ? ' — ' . (string)$o['name'] : ''),
                    'min_gb'    => (int)($o['min_gb'] ?? $cusCfg['min_gb']),
                    'max_gb'    => (int)($o['max_gb'] ?? $cusCfg['max_gb']),
                    'min_days'  => (int)($o['min_days'] ?? $cusCfg['min_days']),
                    'max_days'  => (int)($o['max_days'] ?? $cusCfg['max_days']),
                    'flag'      => (string)($o['panel']['flag'] ?? ''),
                    'price_gb'  => (int)$o['price_gb'],
                    'price_day' => (int)$o['price_day'],
                ];
            }
            ma_out([
                'ok'       => true,
                'currency' => currency(),
                'min_gb'   => (int)$cusCfg['min_gb'],
                'max_gb'   => (int)$cusCfg['max_gb'],
                'min_days' => (int)$cusCfg['min_days'],
                'max_days' => (int)$cusCfg['max_days'],
                'servers'  => $rows,
            ]);
        }

        /* خرید پلن دلخواه با کیف پول */
        ma_secure('buy', $user);
        $pnId = (int)($in['panel_id'] ?? 0);
        $gb   = max((int)$cusCfg['min_gb'], min((int)$cusCfg['max_gb'], (int)($in['gb'] ?? 0)));
        $days = max((int)$cusCfg['min_days'], min((int)$cusCfg['max_days'], (int)($in['days'] ?? 0)));
        $o = $cusCfg['opts'][$pnId] ?? null;
        if (!$o && (int)($in['product_id'] ?? 0) > 0) $o = $cusCfg['opts'][(int)$in['product_id']] ?? null;
        if (!$o) ma_fail('سرور انتخاب‌شده در دسترس نیست.');
        /* fixed76: بازهٔ اختصاصی محصول دلخواه */
        if (isset($o['min_gb'])) {
            $gb   = max((int)$o['min_gb'], min((int)$o['max_gb'], (int)($in['gb'] ?? 0)));
            $days = max((int)$o['min_days'], min((int)$o['max_days'], (int)($in['days'] ?? 0)));
        }
        $final = max(0, $gb * (int)$o['price_gb'] + $days * (int)$o['price_day']);
        if ($final <= 0) ma_fail('تعرفهٔ این سرور تنظیم نشده است.');

        $p     = $o['product'];
        $panel = $o['panel'];
        $u     = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]);
        if ((int)$u['balance'] < $final) {
            ma_out(['ok' => false, 'need' => 'charge',
                'message' => 'موجودی کیف پول کافی نیست. کمبود: ' . ma_money($final - (int)$u['balance'])]);
        }
        $uname = trim((string)($in['username'] ?? ''));
        if (Svc::usernameNeedsInput($panel)) {
            if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $uname)) {
                ma_out(['ok' => false, 'need' => 'username',
                    'message' => 'برای این سرور یک نام کاربری لاتین ۳ تا ۲۰ کاراکتری انتخاب کنید.']);
            }
        } else {
            $uname = '';
        }
        $planName = (!empty($o['name']) ? (string)$o['name'] . ' ' : 'پلن دلخواه ') . fa_num((string)$gb) . ' گیگ ' . fa_num((string)$days) . ' روزه';
        if (!Wallet::debit($UID, $final, 'خرید (مینی‌اپ): ' . $planName)) {
            ma_fail('کسر مبلغ از کیف پول انجام نشد.');
        }
        $orderId = DB::insert('orders', [
            'user_id' => $UID, 'tg_id' => (int)$u['tg_id'], 'product_id' => (int)$p['id'],
            'type' => 'new', 'amount' => $final, 'discount_code' => null,
            'discount_amount' => 0, 'final_amount' => $final,
            'status' => 'pending', 'created_at' => now(),
        ]);
        try {
            $res = Svc::create($u, $panel, [
                'volume_gb'  => (float)$gb,
                'days'       => $days,
                'ip_limit'   => (int)($p['ip_limit'] ?? 0),
                'product'    => $p,
                'username'   => $uname !== '' ? $uname : null,
                'inbound_id' => (int)($p['inbound_id'] ?? 0),
                'name'       => $planName,
            ]);
        } catch (Throwable $e) {
            app_log('miniapp', 'cus create exception: ' . $e->getMessage());
            $res = ['ok' => false, 'message' => 'خطای سیستمی در ساخت سرویس.'];
        }
        if (empty($res['ok'])) {
            Wallet::credit($UID, $final, 'refund', 'wallet', 'بازگشت وجه پلن دلخواه #' . $orderId);
            DB::update('orders', ['status' => 'failed'], 'id = :i', [':i' => $orderId]);
            ma_fail((string)($res['message'] ?? 'ساخت سرویس ناموفق بود.') . ' مبلغ به کیف پول بازگشت.');
        }
        $service = $res['service'];
        try {
            DB::update('orders', ['status' => 'paid', 'service_id' => (int)$service['id']], 'id = :i', [':i' => $orderId]);
        } catch (Throwable $e) {
            app_log('miniapp', 'cus post-create: ' . $e->getMessage());
        }
        try { Bot::deliverCtx('miniapp', (int)$u['tg_id'], $service); } catch (Throwable $e) { }
        if (class_exists('Logs')) {
            Logs::send('purchases', Logs::fmt('🛒 خرید پلن دلخواه (مینی‌اپ)', [
                'سفارش'   => '<code>#' . $orderId . '</code>',
                'کاربر'    => '<code>' . (int)$u['tg_id'] . '</code>',
                'پلن'     => $planName,
                'پرداختی' => ma_money($final),
            ]));
        }
        ma_out([
            'ok'      => true,
            'message' => 'خرید با موفقیت انجام شد 🎉',
            'service' => ma_service_row($service),
            'balance' => ma_money(Wallet::balance($UID)),
        ]);
    }

    /* ---------------- خرید ---------------- */
    case 'buy': {
        ma_secure('buy', $user);

        $pid  = (int)($in['id'] ?? 0);
        $dc   = trim((string)($in['code'] ?? ''));
        $uname = trim((string)($in['username'] ?? ''));

        $p = DB::one('SELECT * FROM {p}products WHERE id = :i AND active = 1', [':i' => $pid]);
        if (!$p) ma_fail('محصول یافت نشد.');
        if ((string)($p['type'] ?? 'fixed') === 'custom') ma_fail('این محصول از بخش «حجم و زمان دلخواه» خریداری می‌شود.');
        if ((int)$p['stock'] === 0) ma_fail('موجودی این محصول تمام شده است.');

        $panel = DB::one('SELECT * FROM {p}panels WHERE id = :i', [':i' => (int)$p['panel_id']]);
        if (!$panel) ma_fail('سرور این محصول تنظیم نشده است.');

        $u = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]);
        $price = (int)$p['price'];
        $discount = 0; $codeRow = null;

        if ($dc !== '' && class_exists('Codes')) {
            $chk = Codes::checkDiscount($dc, $u, $price, $pid);
            if (!empty($chk['ok'])) { $discount = (int)$chk['discount']; $codeRow = $chk['code']; }
            else ma_fail((string)($chk['message'] ?? 'کد تخفیف معتبر نیست.'));
        }
        $orderCode = $dc !== '' ? $dc : null;
        if (class_exists('Campaign')) {
            $b = Campaign::best($price, $pid, $discount, 'new');
            if (($b['source'] ?? '') === 'camp') { $discount = (int)$b['discount']; $codeRow = null; $orderCode = 'CAMPAIGN'; }
        }
        $final = max(0, $price - $discount);

        if ((int)$u['balance'] < $final) {
            ma_out(['ok' => false, 'need' => 'charge',
                'message' => 'موجودی کیف پول کافی نیست. کمبود: ' . ma_money($final - (int)$u['balance'])]);
        }

        if (Svc::usernameNeedsInput($panel)) {
            if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $uname)) {
                ma_out(['ok' => false, 'need' => 'username',
                    'message' => 'برای این سرور باید یک نام کاربری لاتین (۳ تا ۲۰ کاراکتر) انتخاب کنید.']);
            }
        } else {
            $uname = '';
        }

        if (!Wallet::debit($UID, $final, 'خرید (مینی‌اپ): ' . $p['name'])) {
            ma_fail('کسر از کیف پول انجام نشد.');
        }

        $orderId = DB::insert('orders', [
            'user_id' => $UID, 'tg_id' => (int)$u['tg_id'], 'product_id' => $pid,
            'type' => 'new', 'amount' => $price, 'discount_code' => $orderCode,
            'discount_amount' => $discount, 'final_amount' => $final,
            'status' => 'pending', 'created_at' => now(),
        ]);

        try {
            $res = Svc::create($u, $panel, [
                'volume_gb'  => (float)$p['volume_gb'],
                'days'       => (int)$p['days'],
                'ip_limit'   => (int)$p['ip_limit'],
                'product'    => $p,
                'username'   => $uname !== '' ? $uname : null,
                'inbound_id' => (int)($p['inbound_id'] ?? 0),
            ]);
        } catch (Throwable $e) {
            app_log('miniapp', 'create exception: ' . $e->getMessage());
            $res = ['ok' => false, 'message' => 'خطای سیستمی در ساخت سرویس.'];
        }

        if (empty($res['ok'])) {
            Wallet::credit($UID, $final, 'refund', 'wallet', 'عدم موفقیت در ساخت سرویس #' . $orderId);
            DB::update('orders', ['status' => 'failed'], 'id = :i', [':i' => $orderId]);
            if (class_exists('AdminBot')) {
                AdminBot::notifyAdmins("⚠️ خطا در ساخت سرویس (مینی‌اپ)\nکاربر: <code>" . (int)$u['tg_id']
                    . "</code>\nمحصول: " . h((string)$p['name']));
            }
            ma_fail((string)($res['message'] ?? 'ساخت سرویس ناموفق بود.') . ' مبلغ به کیف پول بازگشت.');
        }

        $service = $res['service'];
        try {
            DB::update('orders', ['status' => 'paid', 'service_id' => (int)$service['id']], 'id = :i', [':i' => $orderId]);
            if ($codeRow && class_exists('Codes')) Codes::useDiscount($codeRow, $u, $orderId);
            if ((int)$p['stock'] > 0) DB::q('UPDATE {p}products SET stock = stock - 1 WHERE id = :i', [':i' => $pid]);
        } catch (Throwable $e) {
            app_log('miniapp', 'post-create: ' . $e->getMessage());
        }

        /* ارسال کانفیگ در چت ربات */
        try { Bot::deliverCtx('miniapp', (int)$u['tg_id'], $service); } catch (Throwable $e) {}

        if (class_exists('AdminBot')) {
            AdminBot::notifyAdmins("💵 <b>فروش جدید (مینی‌اپ)</b>\nکاربر: <code>" . (int)$u['tg_id']
                . "</code>\nمحصول: " . h((string)$p['name']) . "\nمبلغ: " . ma_money($final));
        }
        if (class_exists('Logs')) {
            Logs::send('purchases', Logs::fmt('🛒 خرید جدید (مینی‌اپ)', [
                'سفارش'  => '<code>#' . $orderId . '</code>',
                'کاربر'   => '<code>' . (int)$u['tg_id'] . '</code>',
                'محصول'  => h((string)$p['name']),
                'پرداختی' => ma_money($final),
            ]));
        }

        ma_out([
            'ok'      => true,
            'message' => 'خرید با موفقیت انجام شد 🎉',
            'service' => ma_service_row($service),
            'balance' => ma_money(Wallet::balance($UID)),
        ]);
    }

    /* ---------------- سرویس‌ها ---------------- */
    case 'services': {
        /* کانفیگ‌های نمایندگی از فهرست اصلی جدا هستند و فقط در پنل نمایندگی دیده می‌شوند */
        $rows = DB::all('SELECT * FROM {p}services WHERE user_id = :u AND status <> :d
                         AND COALESCE(is_reseller, 0) = 0 ORDER BY id DESC',
            [':u' => $UID, ':d' => 'deleted']);
        $out = [];
        foreach ($rows as $s) $out[] = ma_service_row($s);
        /* fixed83: شمارش کانفیگ‌های قطع برای دکمهٔ پاک‌سازی گروهی */
        $deadN = 0;
        if (method_exists('Svc', 'isDead')) foreach ($rows as $s) if (Svc::isDead($s)) $deadN++;
        ma_out(['ok' => true, 'services' => $out, 'dead' => $deadN,
            'dead_on' => method_exists('Svc', 'deadDelEnabled') ? Svc::deadDelEnabled() : false]);
    }

    case 'service': {
        $s = DB::one('SELECT * FROM {p}services WHERE id = :i AND user_id = :u AND status <> :d',
            [':i' => (int)($in['id'] ?? 0), ':u' => $UID, ':d' => 'deleted']);
        if (!$s) ma_fail('سرویس یافت نشد.');
        ma_out(['ok' => true, 'service' => ma_service_row($s)]);
    }

    case 'sync': {
        $s = DB::one('SELECT * FROM {p}services WHERE id = :i AND user_id = :u AND status <> :d',
            [':i' => (int)($in['id'] ?? 0), ':u' => $UID, ':d' => 'deleted']);
        if (!$s) ma_fail('سرویس یافت نشد.');
        try { Svc::sync($s); } catch (Throwable $e) {}
        $s = DB::one('SELECT * FROM {p}services WHERE id = :i', [':i' => (int)$s['id']]);
        ma_out(['ok' => true, 'message' => 'مصرف به‌روزرسانی شد.', 'service' => ma_service_row($s)]);
    }

    /* ---------------- اکانت تست ---------------- */
    /* fixed77: فهرست سرورهای تست برای انتخاب در مینی‌اپ */
    case 'test_panels': {
        $rows = [];
        foreach (DB::all('SELECT * FROM {p}panels WHERE active = 1 AND test_enabled = 1 ORDER BY sort ASC, id ASC') as $tpn) {
            $rows[] = [
                'id'        => (int)$tpn['id'],
                'name'      => (string)$tpn['name'],
                'volume_gb' => Svc::testVolumeGb($tpn), /* fixed79 */
                'volume_mb' => (int)($tpn['test_volume_mb'] ?? 0),
                'spec'      => Svc::testVolumeLabel($tpn),
                'days'      => (int)($tpn['test_days'] ?? 0),
                'hours'     => (int)($tpn['test_hours'] ?? 0),
                'limit'     => (int)($tpn['test_limit'] ?? 1),
                'used'      => (int)DB::val('SELECT COUNT(*) FROM {p}services WHERE user_id = :u AND is_test = 1 AND panel_id = :p', [':u' => $UID, ':p' => (int)$tpn['id']], 0),
            ];
            $rows[count($rows) - 1]['exhausted'] = $rows[count($rows) - 1]['used'] >= max(1, $rows[count($rows) - 1]['limit']);
        }
        ma_out(['ok' => true, 'panels' => $rows]);
    }

    case 'test': {
        if ((string)DB::setting('test_enabled', '1') !== '1') ma_fail('اکانت تست فعال نیست.');
        ma_secure('test', $user);

        $panels = DB::all('SELECT * FROM {p}panels WHERE active = 1 AND test_enabled = 1 ORDER BY sort ASC, id ASC');
        if (!$panels) ma_fail('اکانت تست فعلاً فعال نیست.');

        $u = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]);
        try {
            /* fixed77: انتخاب سرور تست از مینی‌اپ (panel_id اختیاری؛ پیش‌فرض اولین سرور) */
            $tp    = $panels[0];
            $wantP = (int)($in['panel_id'] ?? 0);
            if ($wantP > 0) { foreach ($panels as $pp) if ((int)$pp['id'] === $wantP) { $tp = $pp; break; } }
            $r = Svc::createTest($u, $tp);
        } catch (Throwable $e) {
            ma_fail('ساخت اکانت تست ناموفق بود.');
        }
        if (empty($r['ok'])) ma_fail((string)($r['message'] ?? 'ساخت اکانت تست ناموفق بود.'));

        try { Bot::deliverCtx('miniapp', (int)$u['tg_id'], $r['service']); } catch (Throwable $e) {}

        ma_out(['ok' => true, 'message' => 'اکانت تست ساخته شد 🧪',
            'service' => ma_service_row($r['service'])]);
    }

    /* ---------------- کیف پول ---------------- */
    /* ---------------- تمدید / حذف و عودت سرویس کاربر ---------------- */

    case 'svc_renew_plans': {
        $s = Svc::find((int)($in['id'] ?? 0));
        if (!$s || (int)($s['user_id'] ?? 0) !== $UID) ma_fail('سرویس پیدا نشد.', 404);
        if (!Svc::userRenewEnabled()) ma_fail('تمدید سرویس فعلاً غیرفعال است.');

        $me   = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        $rows = [];
        foreach (Svc::renewPlans($s) as $p) {
            $rows[] = [
                'id'         => (int)$p['id'],
                'name'       => (string)$p['name'],
                'price'      => (int)$p['price'],
                'price_txt'  => ma_money((int)$p['price']),
                'volume_txt' => (float)$p['volume_gb'] > 0 ? fa_num((string)round((float)$p['volume_gb'], 2)) . ' گیگ' : '♾ نامحدود',
                'days_txt'   => (int)$p['days'] > 0 ? fa_num((string)(int)$p['days']) . ' روز' : '♾ بدون انقضا',
                'afford'     => (int)($me['balance'] ?? 0) >= (int)$p['price'],
            ];
        }
        ma_out(['ok' => true, 'plans' => $rows, 'balance' => ma_money((int)($me['balance'] ?? 0))]);
    }

    case 'svc_renew': {
        $me = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        ma_secure('buy', $me);

        try {
            $r = Svc::userRenew($me, (int)($in['id'] ?? 0), (int)($in['plan_id'] ?? 0));
        } catch (Throwable $e) {
            app_log('miniapp', 'svc_renew: ' . $e->getMessage());
            ma_fail('تمدید انجام نشد.');
        }
        if (empty($r['ok'])) ma_fail((string)($r['message'] ?? 'تمدید انجام نشد.'));

        $fresh = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        ma_out([
            'ok'      => true,
            'message' => (string)$r['message'],
            'service' => ma_service_row((array)($r['service'] ?? [])),
            'balance' => ma_money((int)($fresh['balance'] ?? 0)),
        ]);
    }

    case 'svc_del_quote': {
        $s = Svc::find((int)($in['id'] ?? 0));
        if (!$s || (int)($s['user_id'] ?? 0) !== $UID) ma_fail('سرویس پیدا نشد.', 404);
        if (!Svc::userDelEnabled() && !(Svc::deadDelEnabled() && Svc::isDead($s))) ma_fail('حذف سرویس توسط مدیر غیرفعال شده است.');

        $q = Svc::deleteQuote($s);
        ma_out([
            'ok'         => true,
            'left_txt'   => (float)$s['volume_gb'] > 0 ? fa_num((string)(float)$q['left_gb']) . ' گیگ' : '♾ نامحدود',
            'days_txt'   => fa_num((string)(int)$q['left_days']) . ' روز',
            'pool_txt'   => ma_money((int)$q['pool']),
            'refund'     => (int)$q['refund'],
            'refund_txt' => ma_money((int)$q['refund']),
            'fee'        => (int)$q['fee'],
            'fee_txt'    => ma_money((int)$q['fee']),
            'note'       => (string)$q['note'],
        ]);
    }

    case 'svc_delete': {
        if ((string)($in['confirm'] ?? '') !== 'yes') ma_fail('برای حذف، تایید لازم است.');

        $me = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        try {
            $r = Svc::userDelete($me, (int)($in['id'] ?? 0));
        } catch (Throwable $e) {
            app_log('miniapp', 'svc_delete: ' . $e->getMessage());
            ma_fail('حذف انجام نشد.');
        }
        if (empty($r['ok'])) ma_fail((string)($r['message'] ?? 'حذف انجام نشد.'));

        $fresh = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        ma_out([
            'ok'      => true,
            'message' => (string)$r['message'],
            'refund'  => (int)($r['refund'] ?? 0),
            'balance' => ma_money((int)($fresh['balance'] ?? 0)),
        ]);
    }

    /* fixed83: کانفیگ‌های قطع — فهرست و مبلغ عودتی */
    /* 0.0.2: مدیریت دستگاه‌های ثبت‌شده (HWID) — فقط پنل نسل جدید سنایی */
    /* 0.0.2 #happ-links: لینک Happ و لینک‌های خارجی سرویس */
    case 'svc_links': {
        $sid = (int)($in['id'] ?? 0);
        $s   = DB::one('SELECT * FROM {p}services WHERE id = :i AND user_id = :u AND status <> :d',
            [':i' => $sid, ':u' => $UID, ':d' => 'deleted']);
        if (!$s) ma_fail('سرویس یافت نشد.');
        if (!class_exists('Links') || !Links::supported($s)) {
            ma_out(['ok' => true, 'supported' => false, 'happ' => '', 'items' => []]);
        }
        ma_out([
            'ok'        => true,
            'supported' => true,
            'happ'      => Links::happ($s),
            'items'     => Links::external($s),
        ]);
    }

    case 'svc_devices': {
        $sid = (int)($in['id'] ?? 0);
        $s   = DB::one('SELECT * FROM {p}services WHERE id = :i AND user_id = :u AND status <> :d',
            [':i' => $sid, ':u' => $UID, ':d' => 'deleted']);
        if (!$s) ma_fail('سرویس یافت نشد.');
        if (!class_exists('Devices') || !Devices::supported($s)) {
            ma_out(['ok' => true, 'supported' => false, 'count' => 0, 'items' => [], 'limit' => 0]);
        }
        $items = Devices::listFor($s);
        ma_out([
            'ok'        => true,
            'supported' => true,
            'count'     => count($items),
            'items'     => $items,
            'limit'     => Devices::limitOf($s),
        ]);
    }

    case 'svc_device_del': {
        $sid = (int)($in['id'] ?? 0);
        $dev = (int)($in['device'] ?? 0);
        $s   = DB::one('SELECT * FROM {p}services WHERE id = :i AND user_id = :u AND status <> :d',
            [':i' => $sid, ':u' => $UID, ':d' => 'deleted']);
        if (!$s) ma_fail('سرویس یافت نشد.');
        if (!class_exists('Devices') || !Devices::supported($s)) ma_fail('این پنل از مدیریت دستگاه پشتیبانی نمی‌کند.');
        if ($dev <= 0) ma_fail('دستگاه نامعتبر است.');
        if (!Devices::remove($s, $dev)) ma_fail('حذف دستگاه انجام نشد.');
        $items = Devices::listFor($s);
        ma_out([
            'ok'      => true,
            'message' => 'دستگاه حذف شد.',
            'count'   => count($items),
            'items'   => $items,
            'limit'   => Devices::limitOf($s),
        ]);
    }

    case 'svc_devices_clear': {
        $sid = (int)($in['id'] ?? 0);
        $s   = DB::one('SELECT * FROM {p}services WHERE id = :i AND user_id = :u AND status <> :d',
            [':i' => $sid, ':u' => $UID, ':d' => 'deleted']);
        if (!$s) ma_fail('سرویس یافت نشد.');
        if (!class_exists('Devices') || !Devices::supported($s)) ma_fail('این پنل از مدیریت دستگاه پشتیبانی نمی‌کند.');
        $n = Devices::clear($s);
        ma_out([
            'ok'      => true,
            'message' => $n > 0 ? ('همهٔ دستگاه‌ها حذف شدند (' . fa_num((string)$n) . ').') : 'دستگاهی برای حذف نبود.',
            'removed' => $n,
            'count'   => 0,
            'items'   => [],
            'limit'   => Devices::limitOf($s),
        ]);
    }

    case 'svc_dead': {
        $items = []; $sum = 0;
        foreach (Svc::deadForUser($UID) as $s) {
            $q = Svc::deleteQuote($s);
            $sum += (int)$q['refund'];
            $items[] = [
                'id'         => (int)$s['id'],
                'name'       => (string)$s['client_email'],
                'status_txt' => Svc::deadLabel($s),
                'refund'     => (int)$q['refund'],
                'refund_txt' => ma_money((int)$q['refund']),
                'note'       => (string)$q['note'],
            ];
        }
        ma_out([
            'ok'         => true,
            'enabled'    => Svc::deadDelEnabled(),
            'count'      => count($items),
            'items'      => $items,
            'refund'     => $sum,
            'refund_txt' => ma_money($sum),
        ]);
    }

    /* fixed83: حذف گروهی کانفیگ‌های قطع با عودت وجه */
    case 'svc_purge_dead': {
        if ((string)($in['confirm'] ?? '') !== 'yes') ma_fail('برای حذف، تایید لازم است.');

        $me  = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        $ids = [];
        if (isset($in['ids']) && is_array($in['ids'])) foreach ($in['ids'] as $i) $ids[] = (int)$i;

        try {
            $r = Svc::purgeDeadForUser($me, $ids);
        } catch (Throwable $e) {
            app_log('miniapp', 'svc_purge_dead: ' . $e->getMessage());
            ma_fail('حذف انجام نشد.');
        }
        if (empty($r['ok'])) ma_fail((string)($r['message'] ?? 'کانفیگ قطع‌شده‌ای حذف نشد.'));

        $fresh = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        ma_out([
            'ok'      => true,
            'message' => (string)$r['message'],
            'count'   => (int)$r['count'],
            'refund'  => (int)$r['refund'],
            'balance' => ma_money((int)($fresh['balance'] ?? 0)),
        ]);
    }

    case 'wallet': {
        $rows = DB::all('SELECT * FROM {p}transactions WHERE user_id = :u ORDER BY id DESC LIMIT 25', [':u' => $UID]);
        $typeMap = [
            'deposit' => '💳 شارژ کیف پول', 'purchase' => '🛒 خرید سرویس',
            'refund' => '↩️ بازگشت وجه', 'gift' => '🎁 کد هدیه',
            'admin' => '🛠 توسط مدیر', 'referral' => '👥 پاداش معرفی',
        ];
        $stMap = ['pending' => 'در انتظار', 'approved' => 'تایید شده', 'rejected' => 'رد شده'];

        $tx = [];
        foreach ($rows as $t) {
            $tx[] = [
                'id'      => (int)$t['id'],
                'type'    => $typeMap[(string)$t['type']] ?? (string)$t['type'],
                'amount'  => (int)$t['amount'],
                'amt_txt' => ((int)$t['amount'] >= 0 ? '+' : '−') . ma_money(abs((int)$t['amount'])),
                'status'  => $stMap[(string)$t['status']] ?? (string)$t['status'],
                'state'   => (string)$t['status'],
                'date'    => to_jalali((string)$t['created_at'], true),
            ];
        }

        $in24 = (int)DB::val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions
            WHERE user_id = :u AND status = 'approved' AND amount > 0", [':u' => $UID], 0);
        $out24 = (int)DB::val("SELECT COALESCE(SUM(ABS(amount)),0) FROM {p}transactions
            WHERE user_id = :u AND status = 'approved' AND amount < 0", [':u' => $UID], 0);

        ma_out([
            'ok' => true,
            'balance'     => (int)DB::val('SELECT balance FROM {p}users WHERE id = :i', [':i' => $UID], 0),
            'balance_txt' => ma_money((int)DB::val('SELECT balance FROM {p}users WHERE id = :i', [':i' => $UID], 0)),
            'in_txt'      => ma_money($in24),
            'out_txt'     => ma_money($out24),
            'min'         => (int)DB::setting('min_deposit', 0),
            'max'         => (int)DB::setting('max_deposit', 0),
            'tx'          => $tx,
        ]);
    }

    /* ---------------- نرخ لحظه‌ای ارز ---------------- */
    case 'rate': {
        $amount = max(0, (int)($in['amount'] ?? 0));
        if (!class_exists('Rates')) ma_fail('ماژول نرخ ارز در دسترس نیست.');

        $rate = Rates::liveRate();
        $assets = [];

        /* اولویت با درگاه‌های تعریف‌شده در پنل مدیریت – نرخ و آدرس همان ارز */
        $gwR = [];
        if (class_exists('Gateway')) {
            $isRsR = class_exists('Reseller') ? Reseller::isReseller($user) : false;
            try { $gwR = Gateway::crypto($isRsR); } catch (Throwable $e) { $gwR = []; }
        }
        foreach ($gwR as $g) {
            $ak  = (string)$g['asset'];
            $qty = $amount > 0 ? Gateway::qty($g, $amount) : 0;
            $dec = Gateway::decimals($ak);
            $assets[] = [
                'id'      => (string)$g['id'],
                'key'     => $ak,
                'icon'    => (string)($g['icon'] ?? '💰'),
                'label'   => Gateway::assetLabel($ak),
                'network' => Gateway::netLabel($ak, (string)$g['network']),
                'address' => (string)$g['address'],
                'memo'    => (string)($g['memo'] ?? ''),
                'price'   => ($amount > 0 && $qty > 0) ? (int)round($amount / $qty) : 0,
                'qty'     => $qty,
                'qty_txt' => $qty > 0 ? number_format($qty, $dec, '.', '') : '0',
            ];
        }

        foreach (($assets === [] ? Rates::enabledAssets() : []) as $ak => $am) {
            $addr = trim((string)DB::setting('crypto_addr_' . $ak, ''));
            if ($addr === '' && $ak === 'USDT') $addr = trim((string)DB::setting('crypto_address', ''));
            if ($addr === '') continue;

            $net = trim((string)DB::setting('crypto_net_' . $ak, ''));
            if ($net === '') $net = trim((string)explode('/', (string)($am['networks'] ?? ''))[0]);

            $qty = $amount > 0 ? Rates::amountToAsset($amount, $ak, true) : 0;
            $assets[] = [
                'key'     => $ak,
                'icon'    => (string)($am['icon'] ?? '💰'),
                'label'   => (string)($am['label'] ?? $ak),
                'network' => $net,
                'address' => $addr,
                'price'   => Rates::assetPrice($ak, true),
                'qty'     => $qty,
                'qty_txt' => $qty > 0 ? number_format($qty, $ak === 'USDT' ? 2 : 4, '.', '') : '0',
            ];
        }

        ma_out([
            'ok'        => true,
            'rate'      => $rate,
            'rate_txt'  => ma_money($rate),
            'usd'       => $amount > 0 ? round($amount / max(1, $rate), 2) : 0,
            'source'    => (string)DB::setting('rate_src_used', (string)DB::setting('rate_source', '')),
            'assets'    => $assets,
            'at'        => to_jalali(now(), true),
        ]);
    }

    /* ---------------- دستور شارژ ---------------- */
    /* ---------------- احراز کارت بانکی ----------------
       هیچ اطلاع حساسی دریافت نمی‌شود: نه CVV2، نه رمز دوم، نه رمز پویا، نه تاریخ انقضا */
    case 'cards': {
        if (!class_exists('CardAuth')) {
            ma_out(['ok' => true, 'enabled' => false, 'required' => false, 'max' => 0,
                'ask_holder' => false, 'ask_sheba' => false, 'guide' => '', 'cards' => []]);
        }

        $list = [];
        foreach (CardAuth::cards($UID) as $c) {
            $list[] = [
                'id'     => (int)$c['id'],
                'pan'    => CardAuth::pretty((string)$c['pan']),
                'mask'   => (string)($c['pan_mask'] ?? ''),
                'bank'   => (string)($c['bank'] ?? ''),
                'holder' => (string)($c['holder'] ?? ''),
                'sheba'  => (string)($c['sheba'] ?? ''),
                'status' => (string)$c['status'],
                'label'  => CardAuth::label((string)$c['status']),
                'note'   => (string)($c['note'] ?? ''),
                'uses'   => (int)($c['uses'] ?? 0),
                'photo'  => ((string)($c['photo'] ?? '') !== ''),
                'date'   => function_exists('to_jalali') ? to_jalali((string)$c['created_at']) : (string)$c['created_at'],
            ];
        }

        ma_out([
            'ok'         => true,
            'enabled'    => CardAuth::enabled(),
            'required'   => CardAuth::required(),
            'max'        => CardAuth::maxCards(),
            'active'     => CardAuth::activeCount($UID),
            'remaining'  => CardAuth::remaining($UID),
            'can_add'    => CardAuth::canAdd($UID),
            'auto'       => CardAuth::autoApprove(),
            'ask_holder' => CardAuth::needHolder(),
            'ask_sheba'  => CardAuth::needSheba(),
            'single'     => CardAuth::singleCard(),
            'want_photo' => CardAuth::wantPhoto(),
            'card_photo' => CardAuth::photoRequired(),
            'guide'      => CardAuth::guide(),
            'cards'      => $list,
        ]);
    }

    case 'card_add': {
        if (!class_exists('CardAuth')) ma_fail('این قابلیت در دسترس نیست.');
        if (!CardAuth::enabled())     ma_fail('احراز کارت در حال حاضر غیرفعال است.');

        $r = CardAuth::add(
            $user,
            (string)($in['pan'] ?? ''),
            (string)($in['holder'] ?? ''),
            (string)($in['sheba'] ?? ''),
            (string)($in['photo'] ?? '')
        );

        if (empty($r['ok'])) ma_fail((string)($r['msg'] ?? 'ثبت کارت انجام نشد.'));

        ma_out(['ok' => true, 'msg' => (string)($r['msg'] ?? 'کارت ثبت شد.'),
            'id' => (int)($r['id'] ?? 0), 'status' => (string)($r['status'] ?? 'pending')]);
    }

    case 'card_del': {
        if (!class_exists('CardAuth')) ma_fail('این قابلیت در دسترس نیست.');

        $cid = (int)($in['id'] ?? 0);
        if ($cid <= 0) ma_fail('کارت نامعتبر است.');
        if (!CardAuth::remove($cid, $UID)) ma_fail('این کارت پیدا نشد یا قابل حذف نیست.');

        ma_out(['ok' => true, 'msg' => 'کارت حذف شد.']);
    }

    case 'topup': {
        $amount = (int)($in['amount'] ?? 0);
        $method = (string)($in['method'] ?? 'card');
        $min = (int)DB::setting('min_deposit', 0);
        $max = (int)DB::setting('max_deposit', 0);

        if ($amount <= 0) ma_fail('مبلغ را درست وارد کنید.');
        if ($min > 0 && $amount < $min) ma_fail('حداقل مبلغ شارژ ' . ma_money($min) . ' است.');
        if ($max > 0 && $amount > $max) ma_fail('حداکثر مبلغ شارژ ' . ma_money($max) . ' است.');

        $isRs = class_exists('Reseller') ? Reseller::isReseller($user) : false;

        if ($method === 'card') {
            if (!ma_pay_on('card', $user)) ma_fail('کارت به کارت فعال نیست.');

            $card   = (string)DB::setting('card_number', '');
            $holder = (string)DB::setting('card_holder', '');
            $bank   = (string)DB::setting('card_bank', '');
            $cnote  = '';

            /* کارت اختصاصی نمایندگان */
            if (class_exists('Gateway')) {
                $cg = Gateway::card($isRs);
                if ($cg !== null) {
                    $card = (string)$cg['number'];
                    if ((string)$cg['holder'] !== '') $holder = (string)$cg['holder'];
                    if ((string)$cg['bank'] !== '')   $bank   = (string)$cg['bank'];
                    $cnote = (string)$cg['note'];
                }
            }
            if ($card === '') ma_fail('اطلاعات کارت توسط مدیر تنظیم نشده است.');

            /* قفل احراز کارت: واریز فقط از کارت ثبت و تاییدشدهٔ خود کاربر */
            $ucardId = 0;
            if (class_exists('CardAuth') && CardAuth::enabled()) {
                $cg2 = CardAuth::gate($user);
                if (empty($cg2['ok'])) {
                    ma_out(['ok' => false, 'message' => (string)($cg2['msg'] ?? 'اول کارت بانکی خودتان را ثبت کنید.'),
                        'need_card' => true, 'reason' => (string)($cg2['reason'] ?? 'none')], 400);
                }

                $okCards = CardAuth::approved($UID);
                $wantCid = (int)($in['card_id'] ?? 0);

                if ($wantCid > 0) {
                    foreach ($okCards as $oc) {
                        if ((int)$oc['id'] === $wantCid) { $ucardId = $wantCid; break; }
                    }
                    if ($ucardId === 0) ma_fail('کارت انتخابی شما تایید نشده است.');
                } elseif (count($okCards) === 1) {
                    $ucardId = (int)$okCards[0]['id'];
                } elseif (count($okCards) > 1) {
                    $pickList = [];
                    foreach ($okCards as $oc) {
                        $pickList[] = ['id' => (int)$oc['id'],
                            'pan'  => CardAuth::pretty((string)$oc['pan']),
                            'bank' => (string)($oc['bank'] ?? '')];
                    }
                    ma_out(['ok' => false, 'message' => 'کارت مبدأ واریز را انتخاب کنید.',
                        'pick_card' => true, 'cards' => $pickList], 400);
                }
            }

            /*
             * درخواست شارژ را همین‌جا «در انتظار» ثبت می‌کنیم تا رسید – چه در اپ و چه در
             * چت ربات – بتواند به آن وصل شود. قبلاً هیچ تراکنشی ساخته نمی‌شد و به همین
             * دلیل ارسال عکس رسید در ربات هیچ واکنشی نداشت.
             */
            $txId = 0;
            try {
                $txId = (int)Wallet::createDeposit($user, $amount, 'card', ['card_id' => $ucardId]);
                if ($txId > 0 && $ucardId > 0 && class_exists('CardAuth')) CardAuth::touch($ucardId);
            } catch (Throwable $e) {
                app_log('miniapp', 'card createDeposit failed: ' . $e->getMessage());
            }

            ma_out(['ok' => true, 'method' => 'card', 'amount' => $amount, 'amount_txt' => ma_money($amount),
                'tx' => $txId,
                'card' => ['number' => $card, 'holder' => $holder, 'bank' => $bank, 'note' => $cnote],
                'note' => $txId > 0
                    ? 'پس از واریز، عکس رسید را همین‌جا آپلود کنید (یا در چت ربات بفرستید) تا کیف پول شما شارژ شود.'
                    : 'پس از واریز، رسید را در چت ربات ارسال کنید تا کیف پول شما شارژ شو��.']);
        }

        if ($method === 'crypto') {
            if (!ma_pay_on('crypto', $user)) ma_fail('پرداخت ارزی فعال نیست.');

            $assets = [];

            /* درگاه‌های چندگانه: برای هر ارز چند شبکه */
            if (class_exists('Gateway')) {
                foreach (Gateway::crypto($isRs) as $g) {
                    $ak  = (string)$g['asset'];
                    $qty = Gateway::qty($g, $amount);
                    $dec = Gateway::decimals($ak);
                    $assets[] = [
                        'id'      => (string)$g['id'],
                        'key'     => $ak,
                        'icon'    => (string)$g['icon'] !== '' ? (string)$g['icon'] : '🌐',
                        'label'   => Gateway::assetLabel($ak),
                        'network' => Gateway::netLabel($ak, (string)$g['network']),
                        'address' => (string)$g['address'],
                        'memo'    => (string)$g['memo'],
                        'note'    => (string)$g['note'],
                        'qty_txt' => $qty > 0 ? number_format($qty, $dec, '.', '') : '',
                    ];
                }
            }

            /* سازگاری با تنظیمات قدیمی */
            if (!$assets && class_exists('Rates')) {
                foreach (Rates::enabledAssets() as $ak => $am) {
                    $addr = trim((string)DB::setting('crypto_addr_' . $ak, ''));
                    if ($addr === '' && $ak === 'USDT') $addr = trim((string)DB::setting('crypto_address', ''));
                    if ($addr === '') continue;
                    $net = trim((string)DB::setting('crypto_net_' . $ak, ''));
                    if ($net === '') $net = trim((string)explode('/', (string)($am['networks'] ?? ''))[0]);
                    $qty = Rates::amountToAsset($amount, $ak, true);
                    $assets[] = [
                        'id' => 'legacy_' . strtolower($ak), 'key' => $ak,
                        'icon' => (string)($am['icon'] ?? '💰'), 'label' => (string)($am['label'] ?? $ak),
                        'network' => $net, 'address' => $addr, 'memo' => '', 'note' => '',
                        'qty_txt' => number_format($qty, $ak === 'USDT' ? 2 : 4, '.', ''),
                    ];
                }
            }

            if (!$assets) ma_fail('هیچ درگاه ارزی توسط مدیر تنظیم نشده است.');

            /* وضعیت ربات هم تنظیم می‌شود تا ارسال هش در چت ربات هم کار کند */
            DB::update('users', [
                'state' => 'wallet_txid',
                'state_data' => jenc(['amount' => $amount, 'method' => 'crypto']),
            ], 'id = :id', [':id' => $UID]);

            ma_out(['ok' => true, 'method' => 'crypto', 'amount' => $amount, 'amount_txt' => ma_money($amount),
                'rate_txt' => class_exists('Rates') ? ma_money((int)Rates::liveRate()) : '',
                'assets' => $assets, 'can_hash' => true,
                'note' => 'مقادیر با نرخ همین لحظه محاسبه شد. پس از واریز، هش تراکنش (TXID) را همین‌جا ارسال کنید.']);
        }

        if ($method === 'zarinpal') { /* 0.0.2 #22 */
            if (!class_exists('Zarinpal') || !Zarinpal::enabled()) ma_fail('درگاه زرین‌پال فعال نیست.');
            if (!Zarinpal::forUser($isRs)) ma_fail('این درگاه برای حساب شما فعال نیست.');

            $zpMin = Zarinpal::minAmount();
            $zpMax = Zarinpal::maxAmount();
            if ($zpMin > 0 && $amount < $zpMin) ma_fail('حداقل مبلغ این درگاه ' . ma_money($zpMin) . ' است.');
            if ($zpMax > 0 && $amount > $zpMax) ma_fail('حداکثر مبلغ این درگاه ' . ma_money($zpMax) . ' است.');

            $invZp = Zarinpal::createInvoice($user, $amount);
            if (empty($invZp['ok'])) {
                ma_fail((string)($invZp['message'] ?? 'ساخت لینک پرداخت زرین‌پال ناموفق بود.'));
            }

            ma_out(['ok' => true, 'method' => 'zarinpal',
                'amount'     => $amount,
                'amount_txt' => ma_money($amount),
                'tx'         => (int)($invZp['tx'] ?? 0),
                'url'        => (string)($invZp['url'] ?? ''),
                'authority'  => (string)($invZp['authority'] ?? ''),
                'note'       => 'روی دکمهٔ پرداخت بزنید؛ پس از پرداخت موفق، کیف پول شما خودکار شارژ می‌شود.']);
        }

        if ($method === 'hooshpay') {
            if (!class_exists('HooshPay') || !HooshPay::enabled()) ma_fail('درگاه هوش‌پی فعال نیست.');
            if (!HooshPay::forUser($isRs)) ma_fail('این درگاه برای حساب شما فعال نیست.');

            $hpMin = HooshPay::minAmount();
            $hpMax = HooshPay::maxAmount();
            if ($hpMin > 0 && $amount < $hpMin) ma_fail('حداقل مبلغ این درگاه ' . ma_money($hpMin) . ' است.');
            if ($hpMax > 0 && $amount > $hpMax) ma_fail('حداکثر مبلغ این درگاه ' . ma_money($hpMax) . ' است.');

            $txHp  = (int)Wallet::createDeposit($user, $amount, 'hooshpay');
            $invHp = HooshPay::createInvoice($user, $amount, $txHp);

            if (empty($invHp['ok'])) {
                try {
                    Wallet::reject($txHp, null, 'HooshPay: ' . (string)($invHp['message'] ?? ''), false);
                } catch (Throwable $e) {
                }
                ma_fail((string)($invHp['message'] ?? 'ساخت فاکتور هوش‌پی ناموفق بود.'));
            }

            $hpCd  = is_array($invHp['card'] ?? null) ? (array)$invHp['card'] : [];
            $hpPay = (int)($invHp['payable'] ?? 0);
            $hpFee = (string)(HooshPay::FEE_SHORT[HooshPay::feeMode()] ?? '');

            ma_out(['ok' => true, 'method' => 'hooshpay',
                'amount'      => $amount,
                'amount_txt'  => ma_money($amount),
                'tx'          => $txHp,
                'url'         => (string)($invHp['url'] ?? ''),
                'uid'         => (string)($invHp['uid'] ?? ''),
                'payable'     => $hpPay,
                'payable_txt' => money($hpPay) . ' تومان',
                'fee_txt'     => $hpFee,
                'expires'     => (string)($invHp['expires'] ?? ''),
                'card'        => [
                    'number' => (string)($hpCd['card_number'] ?? ''),
                    'holder' => (string)($hpCd['holder_name'] ?? ''),
                    'bank'   => (string)($hpCd['bank_name'] ?? ''),
                ],
                'note' => 'روی دکمهٔ پرداخت بزنید و «دقیقاً» همان مبلغ قابل پرداخت را واریز کنید؛ بعد دکمهٔ بررسی وضعیت را بزنید.']);
        }

        ma_fail('روش پرداخت پشتیبانی نمی‌شود.');
    }

    /* ---------------- ارسال هش تراکنش از داخل مینی‌اپ ---------------- */
    /* ---------------- بررسی وضعیت پرداخت هوش‌پی از داخل مینی‌اپ ---------------- */
    case 'topup_hp_check': {
        if (!class_exists('HooshPay')) ma_fail('درگاه هوش‌پی در دسترس نیست.');

        $hpTx  = (int)($in['tx'] ?? 0);
        $hpRow = DB::one("SELECT * FROM {p}transactions WHERE id = :i AND user_id = :u AND method = 'hooshpay'",
            [':i' => $hpTx, ':u' => $UID]);
        if (!$hpRow) ma_fail('این درخواست شارژ پیدا نشد.');

        $hpBal = static function () use ($UID) {
            return (int)DB::val('SELECT balance FROM {p}users WHERE id = :id', [':id' => $UID], 0);
        };

        $hpSt = (string)($hpRow['status'] ?? '');

        if ($hpSt === 'approved') {
            ma_out(['ok' => true, 'paid' => true, 'dead' => false,
                'balance_txt' => ma_money($hpBal()),
                'message' => 'پرداخت تایید شده و کیف پول شما شارژ شده است.']);
        }

        if ($hpSt === 'rejected') {
            ma_out(['ok' => true, 'paid' => false, 'dead' => true,
                'balance_txt' => ma_money($hpBal()),
                'message' => 'این پرداخت ناموفق یا منقضی شده است؛ لطفاً یک درخواست تازه بسازید.']);
        }

        $hpRes  = HooshPay::poll((array)$hpRow);
        $hpPaid = !empty($hpRes['paid']);
        $hpDead = false;
        if (!empty($hpRes['ok']) && !$hpPaid && empty($hpRes['pending']) && empty($hpRes['duplicate'])) {
            $hpDead = true;
        }

        ma_out(['ok' => true, 'paid' => $hpPaid, 'dead' => $hpDead,
            'balance_txt' => ma_money($hpBal()),
            'message' => (string)($hpRes['message'] ?? 'هنوز پرداختی برای این فاکتور ثبت نشده است.')]);
    }

    case 'topup_hash': {
        $amount = (int)($in['amount'] ?? 0);
        $hash   = trim((string)($in['hash'] ?? ''));
        $gwId   = trim((string)($in['gw'] ?? ''));
        $min = (int)DB::setting('min_deposit', 0);
        $max = (int)DB::setting('max_deposit', 0);

        if (!ma_pay_on('crypto', $user)) ma_fail('پرداخت ارزی فعال نیست.');
        if ($amount <= 0) ma_fail('مبلغ واریزی مشخص ن��ست.');
        if ($min > 0 && $amount < $min) ma_fail('حداقل مبلغ شارژ ' . ma_money($min) . ' است.');
        if ($max > 0 && $amount > $max) ma_fail('حداکثر مبلغ شارژ ' . ma_money($max) . ' است.');

        $txid = class_exists('TxCheck') ? TxCheck::normalize($hash) : trim(en_num($hash));
        if (strlen($txid) < 8) ma_fail('هش تراکنش معتبر نیست.');

        $chk = ['ok' => false, 'manual' => true, 'message' => ''];
        if (class_exists('TxCheck')) $chk = TxCheck::verify($txid, $amount);

        $note = class_exists('TxCheck') ? TxCheck::summary($chk) : '';
        if ($gwId !== '' && class_exists('Gateway')) {
            $g = Gateway::byId($gwId);
            if ($g !== null) $note = trim('درگاه: ' . Gateway::title($g) . "\n" . $note);
        }
        $note = trim("🚀 ارسال از مینی‌اپ\n" . $note);

        $dec = (class_exists('TxCheck') && method_exists('TxCheck', 'decide'))
            ? TxCheck::decide($chk)
            : (!empty($chk['ok']) ? 'approve' : 'manual');

        $txNew = (int)Wallet::createDeposit($user, $amount, 'crypto', [
            'txid' => mb_substr($txid, 0, 180),
            'note' => mb_substr($note, 0, 240),
        ]);

        DB::update('users', ['state' => null, 'state_data' => null], 'id = :id', [':id' => $UID]);

        $auto = false;
        $bad  = false;

        if ($dec === 'approve') {
            $ap = Wallet::approve($txNew, null, false);
            $auto = !empty($ap['ok']);
        } elseif ($dec === 'reject') {
            $reason = trim(str_replace("\n", ' ', (string)($chk['message'] ?? '')));
            $rj = Wallet::reject($txNew, null, "هش‌چکر: " . $reason, false);
            $bad = !empty($rj['ok']);
        }

        try { AdminBot::notifyPayment($txNew); } catch (Throwable $e) {}

        $bal = (int)DB::val('SELECT balance FROM {p}users WHERE id = :id', [':id' => $UID], 0);

        ma_out(['ok' => true, 'auto' => $auto, 'bad' => $bad, 'tx' => $txNew, 'balance_txt' => ma_money($bal),
            'check' => (string)($chk['message'] ?? ''),
            'message' => $auto
                ? "\xe2\x9c\x85 هش روی شبکه تایید شد و کیف پول شما شارژ شد."
                : ($bad
                    ? "\xe2\x9d\x8c هش معتبر نبود و تراکنش رد شد: " . (string)($chk['message'] ?? '')
                    : "\xf0\x9f\x95\x93 تراکنش با شماره #" . $txNew . " ثبت شد و در انتظار تایید است.")]);
    }

    case 'topup_receipt': {
        /*
         * آپلود رسید کارت به کارت از داخل مینی‌اپ.
         * تصویر به‌صورت base64 می‌رسد، موقتاً ذخیره می‌شود، یک‌بار به تلگرام آپلود
         * می‌گردد تا file_id بگیرد و همان شناسه در تراکنش ثبت می‌شود.
         */
        $txId = (int)($in['tx'] ?? 0);
        $data = (string)($in['image'] ?? '');
        if ($data === '') ma_fail('تصویر رسید ارسال نشده است.');

        $tx = DB::one("SELECT * FROM {p}transactions WHERE id = :i AND user_id = :u AND status = 'pending'",
            [':i' => $txId, ':u' => $UID]);
        if (!$tx) ma_fail('درخواست شارژ یافت نشد یا قبلاً بررسی شده است.');

        $ext = 'jpg';
        if (preg_match('#^data:image/(jpe?g|png|webp);base64,#i', $data, $mm)) {
            $data = substr($data, strlen($mm[0]));
            $kk   = strtolower($mm[1]);
            $ext  = $kk === 'png' ? 'png' : ($kk === 'webp' ? 'webp' : 'jpg');
        }

        $bin = base64_decode(str_replace([' ', "\n", "\r"], '', $data), true);
        if ($bin === false || strlen($bin) < 512) ma_fail('فایل رسید نامعتبر است.');
        if (strlen($bin) > 5 * 1024 * 1024) ma_fail('حجم تصویر باید کمتر از ۵ مگابایت باشد.');

        $dir = APP_ROOT . '/storage/receipts';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $tmp = $dir . '/tx' . (int)$tx['id'] . '-' . rnd(8) . '.' . $ext;
        if (@file_put_contents($tmp, $bin) === false) ma_fail('ذخیرهٔ رسید ناموفق بود.');

        $fileId = '';
        try {
            $to = 0;
            if (class_exists('AdminBot')) {
                $ids = AdminBot::adminIds();
                $to  = (int)($ids[0] ?? 0);
            }
            if ($to <= 0) $to = (int)($user['tg_id'] ?? 0);

            if ($to > 0) {
                $rr = Tg::api('sendPhoto', [
                    'chat_id'              => $to,
                    'photo'                => new CURLFile($tmp),
                    'caption'              => 'رسید شارژ #' . (int)$tx['id'] . ' از داخل اپلیکیشن',
                    'disable_notification' => true,
                ], true);
                if (!empty($rr['ok']) && !empty($rr['result']) && class_exists('Broadcast')) {
                    $fileId = Broadcast::extractFileId((array)$rr['result']);
                }
            }
        } catch (Throwable $e) {
            app_log('miniapp', 'receipt upload failed: ' . $e->getMessage());
        }
        @unlink($tmp);

        if ($fileId === '') ma_fail('ارسال رسید ناموفق بود؛ لطفاً رسید را در چت ربات بفرستید.');

        DB::update('transactions', ['receipt_file' => $fileId], 'id = :id', [':id' => (int)$tx['id']]);

        try {
            AdminBot::notifyPayment((int)$tx['id']);
        } catch (Throwable $e) {
            app_log('miniapp', 'notifyPayment failed: ' . $e->getMessage());
        }

        ma_out(['ok' => true, 'message' => 'رسید شما ثبت شد و برای بررسی ارسال گردید.']);
    }

    case 'tickets': {
        $rows = DB::all('SELECT * FROM {p}tickets WHERE user_id = :u ORDER BY id DESC LIMIT 30', [':u' => $UID]);
        $stMap = ['open' => 'در انتظار پاسخ', 'answered' => 'پاسخ داده شده', 'closed' => 'بسته شده'];
        $out = [];
        foreach ($rows as $t) {
            $out[] = [
                'id' => (int)$t['id'], 'subject' => (string)$t['subject'],
                'status' => $stMap[(string)$t['status']] ?? (string)$t['status'],
                'state' => (string)$t['status'],
                'date' => to_jalali((string)$t['created_at'], true),
            ];
        }
        ma_out(['ok' => true, 'tickets' => $out,
            'support' => trim((string)DB::setting('support_username', ''))]);
    }

    case 'ticket': {
        $id = (int)($in['id'] ?? 0);
        $t = DB::one('SELECT * FROM {p}tickets WHERE id = :i AND user_id = :u', [':i' => $id, ':u' => $UID]);
        if (!$t) ma_fail('تیکت یافت نشد.');

        $msgs = DB::all('SELECT * FROM {p}ticket_messages WHERE ticket_id = :t ORDER BY id ASC LIMIT 50', [':t' => $id]);
        $out = [];
        foreach ($msgs as $m) {
            $out[] = [
                'id' => (int)$m['id'],
                'admin' => (string)$m['sender'] === 'admin',
                'text' => (string)($m['text'] ?? ''),
                'has_file' => trim((string)($m['file_id'] ?? '')) !== '',
                'date' => to_jalali((string)$m['created_at'], true),
            ];
        }
        ma_out(['ok' => true, 'id' => $id, 'subject' => (string)$t['subject'],
            'state' => (string)$t['status'], 'messages' => $out]);
    }

    case 'ticket_new': {
        $subject = trim((string)($in['subject'] ?? ''));
        $text    = trim((string)($in['text'] ?? ''));
        if ($text === '') ma_fail('متن پیام را بنویسید.');
        if ($subject === '') $subject = mb_substr(clean_text($text), 0, 60);

        $tid = DB::insert('tickets', [
            'user_id' => $UID, 'tg_id' => (int)$user['tg_id'],
            'subject' => $subject, 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::insert('ticket_messages', [
            'ticket_id' => $tid, 'sender' => 'user',
            'text' => $text, 'created_at' => now(),
        ]);

        if (class_exists('AdminBot')) {
            try { AdminBot::notifyTicket((int)$tid, $text, null, null); } catch (Throwable $e) {}
        }
        ma_out(['ok' => true, 'id' => (int)$tid, 'message' => 'تیکت شما ثبت شد ✅']);
    }

    case 'ticket_reply': {
        $id   = (int)($in['id'] ?? 0);
        $text = trim((string)($in['text'] ?? ''));
        if ($text === '') ma_fail('متن پیام را بنویسید.');

        $t = DB::one('SELECT * FROM {p}tickets WHERE id = :i AND user_id = :u', [':i' => $id, ':u' => $UID]);
        if (!$t) ma_fail('تیکت یافت نشد.');
        if ((string)$t['status'] === 'closed') ma_fail('این تیکت بسته شده است. تیکت جدید بسازید.');

        DB::insert('ticket_messages', [
            'ticket_id' => $id, 'sender' => 'user',
            'text' => $text, 'created_at' => now(),
        ]);
        DB::update('tickets', ['status' => 'open', 'updated_at' => now()], 'id = :i', [':i' => $id]);

        if (class_exists('AdminBot')) {
            try { AdminBot::notifyTicket($id, $text, null, null); } catch (Throwable $e) {}
        }
        ma_out(['ok' => true, 'message' => 'پاسخ شما ارسال شد ✅']);
    }

    /* ---------------- آموزش‌ها ---------------- */
    case 'tutorials': {
        $rows = DB::all('SELECT * FROM {p}tutorials WHERE active = 1 ORDER BY sort ASC, id ASC');
        $out = [];
        foreach ($rows as $t) {
            $out[] = [
                'id' => (int)$t['id'], 'title' => (string)$t['title'],
                'platform' => (string)$t['platform'],
                'platform_txt' => class_exists('Kb') ? Kb::platformLabel((string)$t['platform']) : (string)$t['platform'],
                'content' => (string)($t['content'] ?? ''),
                'link' => (string)($t['link'] ?? ''),
            ];
        }
        ma_out(['ok' => true, 'tutorials' => $out]);
    }

    /* ---------------- تایید حساب ---------------- */
    case 'verify_start': {
        if (!class_exists('Security')) ma_fail('ماژول امنیت در دسترس نیست.');
        $kind   = (string)($in['kind'] ?? '');
        $target = trim((string)($in['target'] ?? ''));
        $u = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]);

        /* verify_once: حساب تاییدشده دیگر دوباره تایید نمی‌شود */
        if ((int)($u['verified'] ?? 0) === 1) {
            ma_out(['ok' => false, 'done' => true, 'verified' => true,
                'message' => '✅ حساب شما پیش‌تر تایید شده است.']);
        }

        if ($kind === 'link') $target = (string)($u['email'] ?? '');
        $r = Security::start($u, $kind, $target);

        ma_out(['ok' => !empty($r['ok']), 'message' => (string)($r['message'] ?? ''),
            'link' => (string)($r['link'] ?? ''), 'kind' => $kind]);
    }

    case 'verify_code': {
        if (!class_exists('Security')) ma_fail('ماژول امنیت در دسترس نیست.');
        $u = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]);
        if ((int)($u['verified'] ?? 0) === 1) {
            ma_out(['ok' => true, 'done' => true, 'verified' => true,
                'message' => '✅ حساب شما پیش‌تر تایید شده است.']);
        }
        $r = Security::checkCode($u, (string)($in['code'] ?? ''));
        ma_out(['ok' => !empty($r['ok']), 'message' => (string)($r['message'] ?? '')]);
    }

    /* ---------------- پروفایل ---------------- */
    case 'save_profile': {
        $upd = [];
        $email = trim((string)($in['email'] ?? ''));
        $phone = trim((string)($in['phone'] ?? ''));
        $name  = trim((string)($in['name'] ?? ''));

        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) ma_fail('ایمیل معتبر نیست.');
            $upd['email'] = $email;
        }
        if ($phone !== '') {
            $digits = preg_replace('/\D/', '', en_num($phone)) ?? '';
            if (strlen($digits) < 10) ma_fail('شماره موبایل معتبر نیست.');
            $upd['phone'] = $digits;
        }
        if ($name !== '') $upd['first_name'] = mb_substr(clean_text($name), 0, 64);

        if (!$upd) ma_fail('چیزی برای ذخیره نیست.');
        DB::update('users', $upd, 'id = :i', [':i' => $UID]);
        ma_out(['ok' => true, 'message' => 'اطلاعات شما ذخیره شد ✅']);
    }

    /* ---------------- نمایندگی ---------------- */
    case 'rs_info': {
        if (!class_exists('Reseller') || !Reseller::enabled()) ma_fail('بخش نمایندگی فعال نیست.', 403);

        $ru  = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        $lvl = Reseller::level($ru);

        /* هر سرور با تعرفهٔ اختصاصی خودش */
        $panels = [];
        $pCards = method_exists('Reseller', 'panelCards') ? Reseller::panelCards($ru) : [];

        if ($pCards) {
            foreach ($pCards as $pc) {
                $panels[] = [
                    'id'       => (int)$pc['id'],
                    'name'     => (string)$pc['name'],
                    'gb'       => (int)$pc['gb'],
                    'day'      => (int)$pc['day'],
                    'gb_txt'   => ma_money((int)$pc['gb']),
                    'day_txt'  => ma_money((int)$pc['day']),
                    'off'      => (int)$pc['off'],
                    'min_gb'   => (int)$pc['min_gb'],
                    'max_gb'   => (int)$pc['max_gb'],
                    'min_days' => (int)$pc['min_days'],
                    'max_days' => (int)$pc['max_days'],
                    'note'     => (string)$pc['note'],
                    'custom'   => !empty($pc['custom']),
                ];
            }
        } else {
            foreach (Reseller::panels() as $rp) {
                $panels[] = ['id' => (int)$rp['id'], 'name' => (string)$rp['name']];
            }
        }

        $mine = [];
        foreach (Reseller::services($ru, 40) as $rs) {
            if ((int)($rs['is_reseller'] ?? 0) !== 1) continue;
            $mine[] = ma_service_row($rs);
        }

        ma_out([
            'ok'          => true,
            'is_reseller' => Reseller::isReseller($ru),
            'brand'       => [
                'name'   => (string)($ru['reseller_brand'] ?? ''),
                'domain' => (string)($ru['reseller_domain'] ?? ''),
                'note'   => (string)($ru['reseller_note_pub'] ?? ''),
                'global' => method_exists('Reseller', 'subDomain') ? Reseller::subDomain() : '',
                'active' => method_exists('Reseller', 'domainFor') ? Reseller::domainFor($ru) : '',
            ],
            'caps'        => [
                'allow_test'   => method_exists('Reseller', 'allowTest')   ? Reseller::allowTest()   : false,
                'allow_rename' => method_exists('Reseller', 'allowRename') ? Reseller::allowRename() : true,
                'allow_delete' => method_exists('Reseller', 'allowDelete') ? Reseller::allowDelete() : true,
                'allow_renew'  => method_exists('Reseller', 'allowRenew')  ? Reseller::allowRenew()  : true,
                'allow_edit'   => method_exists('Reseller', 'allowEdit')   ? Reseller::allowEdit()   : true,
                'show_price'   => method_exists('Reseller', 'showPrice')   ? Reseller::showPrice()   : true,
                'hide_panel'   => method_exists('Reseller', 'hidePanel')   ? Reseller::hidePanel()   : true,
                'wallet_only'  => method_exists('Reseller', 'walletOnly')  ? Reseller::walletOnly()  : false,
                'service_cap'  => method_exists('Reseller', 'serviceCap')  ? Reseller::serviceCap()  : 0,
                'daily_limit'  => method_exists('Reseller', 'dailyLimit')  ? Reseller::dailyLimit()  : 0,
                'month_limit'  => method_exists('Reseller', 'monthLimit')  ? Reseller::monthLimit()  : 0,
                'min_charge'   => method_exists('Reseller', 'minCharge')   ? Reseller::minCharge()   : 0,
                'built_today'  => method_exists('Reseller', 'builtSince')  ? Reseller::builtSince($UID, date('Y-m-d 00:00:00')) : 0,
                'built_month'  => method_exists('Reseller', 'builtSince')  ? Reseller::builtSince($UID, date('Y-m-01 00:00:00')) : 0,
                'support_id'   => method_exists('Reseller', 'supportId')   ? Reseller::supportId()   : '',
                'welcome'      => method_exists('Reseller', 'welcome')     ? Reseller::welcome()     : '',
                'tos'          => method_exists('Reseller', 'tos')         ? Reseller::tos()         : '',
            ],
            'level'       => $lvl,
            'level_label' => Reseller::levelLabel($lvl),
            'quota'       => Reseller::quota($ru),
            'stats'       => Reseller::stats($ru),
            'discount'    => Reseller::userDiscount($ru),
            'limits'      => [
                'min_gb'   => Reseller::minGb(),
                'max_gb'   => Reseller::maxGb(),
                'min_days' => Reseller::minDays(),
                'max_days' => Reseller::maxDays(),
                'ip_limit' => Reseller::ipLimit(),
            ],
            'tariff'      => [
                'gb'      => Reseller::priceGb(),
                'day'     => Reseller::priceDay(),
                'gb_txt'  => ma_money(Reseller::priceGb()),
                'day_txt' => ma_money(Reseller::priceDay()),
            ],
            'rsbot'    => (class_exists('RsBot')
                ? RsBot::info($ru)
                : [
                    'enabled'   => false,
                    'auto'      => false,
                    'price'     => 0,
                    'price_txt' => '',
                    'note'      => '',
                    'paid'      => false,
                    'pending'   => false,
                    'ready'     => false,
                    'bot'       => null,
                ]),
            'panels'   => $panels,
            'services' => $mine,
            'plans'    => (static function () use ($ru) {
                if (!method_exists('Reseller', 'plansFor')) return [];
                $out = [];
                foreach (Reseller::plansFor($ru) as $pl) {
                    $pp  = Reseller::planPrice($pl, $ru);
                    $aff = Reseller::canAfford($ru, (int)$pp['final']);
                    $out[] = [
                        'id'         => (string)$pl['id'],
                        'title'      => (string)$pl['title'],
                        'gb'         => (int)$pl['gb'],
                        'days'       => (int)$pl['days'],
                        'device'     => (int)$pl['device'],
                        'speed_up'   => (int)$pl['speed_up'],
                        'speed_down' => (int)$pl['speed_down'],
                        'ip_limit'   => (int)$pl['ip_limit'],
                        'note'       => (string)$pl['note'],
                        'label'      => Reseller::planLabel($pl),
                        'price'      => (int)$pp['final'],
                        'price_txt'  => ma_money((int)$pp['final']),
                        'afford'     => !empty($aff['ok']),
                    ];
                }
                return $out;
            })(),
            'bundles'  => (static function () use ($ru) {
                if (!method_exists('Reseller', 'bundlesFor')) return [];
                $out = [];
                foreach (Reseller::bundlesFor($ru) as $bd) {
                    $bp  = Reseller::bundlePrice($bd, $ru, (int)$bd['gb'], (int)$bd['days']);
                    $aff = Reseller::canAfford($ru, (int)$bp['final']);
                    $out[] = [
                        'id'         => (string)$bd['id'],
                        'title'      => (string)$bd['title'],
                        'gb'         => (int)$bd['gb'],
                        'days'       => (int)$bd['days'],
                        'device'     => (int)$bd['device'],
                        'speed_up'   => (int)$bd['speed_up'],
                        'speed_down' => (int)$bd['speed_down'],
                        'ip_limit'   => (int)$bd['ip_limit'],
                        'custom'     => !empty($bd['custom']),
                        'note'       => (string)$bd['note'],
                        'label'      => Reseller::bundleLabel($bd),
                        'panels'     => count($bd['items']),
                        'configs'    => Reseller::bundleConfigCount($bd),
                        'price'      => (int)$bp['final'],
                        'price_txt'  => ma_money((int)$bp['final']),
                        'afford'     => !empty($aff['ok']),
                    ];
                }
                return $out;
            })(),
            'note'     => (string)DB::setting('rs_note', ''),
        ]);
    }

    /* ---------------- برند و دامنهٔ اختصاصیِ خودِ نماینده ---------------- */
    case 'rs_brand': {
        if (!class_exists('Reseller') || !Reseller::enabled()) ma_fail('بخش نمایندگی فعال نیست.', 403);

        $ru = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        if (!Reseller::isReseller($ru)) ma_fail('شما نمایندهٔ ثبت‌شده نیستید.', 403);

        $brand  = trim((string)($in['brand'] ?? ''));
        $domain = trim((string)($in['domain'] ?? ''));
        $note   = trim((string)($in['note'] ?? ''));

        if (mb_strlen($brand) > 40)  ma_fail('نام برند حداکثر ۴۰ کاراکتر باشد.');
        if (mb_strlen($note)  > 200) ma_fail('توضیح حداکثر ۲۰۰ کاراکتر باشد.');

        if ($domain !== '') {
            $clean = Reseller::cleanDomain($domain);
            if ($clean === '') {
                ma_fail('دامنه معتبر نیست. نمونهٔ درست: sub.example.com');
            }
            $domain = $clean;

            /* دامنه نباید در اختیار نمایندهٔ دیگری باشد */
            $taken = DB::val('SELECT COUNT(*) FROM {p}users WHERE reseller_domain = :d AND id <> :i',
                [':d' => $domain, ':i' => $UID], 0);
            if ((int)$taken > 0) ma_fail('این دامنه پیش‌تر توسط نمایندهٔ دیگری ثبت شده است.');
        }

        DB::update('users', [
            'reseller_brand'    => $brand  !== '' ? $brand  : null,
            'reseller_domain'   => $domain !== '' ? $domain : null,
            'reseller_note_pub' => $note   !== '' ? $note   : null,
        ], 'id = :i', [':i' => $UID]);

        if (class_exists('Logs')) {
            try {
                Logs::event('resellers', '🌐 برند و دامنهٔ نماینده به‌روز شد', [
                    'نماینده'  => (string)($ru['first_name'] ?? '') . ' (#' . $UID . ')',
                    'یوزرنیم'  => (string)($ru['username'] ?? '') !== '' ? '@' . (string)$ru['username'] : '—',
                    'برند'      => $brand  !== '' ? $brand : '—',
                    'دامنه'     => $domain !== '' ? '`' . $domain : '— (دامنهٔ پیش‌فرض)',
                    'توضیح'     => $note   !== '' ? $note : '—',
                ]);
            } catch (Throwable $e) {}
        }

        ma_out(['ok' => true, 'message' => 'تنظیمات برند شما ذخیره شد ✅',
            'brand' => $brand, 'domain' => $domain, 'note' => $note,
            'active' => Reseller::domainFor(array_merge($ru, ['reseller_domain' => $domain]))]);
    }

    case 'rs_price': {
        if (!class_exists('Reseller') || !Reseller::enabled()) ma_fail('بخش نمایندگی فعال نیست.', 403);

        $ru = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        if (!Reseller::isReseller($ru)) ma_fail('شما دسترسی نمایندگی ندارید.', 403);

        $gb   = (int)($in['volume_gb'] ?? 0);
        $days = (int)($in['days'] ?? 0);

        /* قیمت یک سرویس آماده */
        $bundleId = trim((string)($in['bundle_id'] ?? ''));
        if ($bundleId !== '' && method_exists('Reseller', 'bundle')) {
            $bd = Reseller::bundle($bundleId);
            if ($bd) {
                if (empty($bd['custom'])) {
                    $gb   = (int)$bd['gb'];
                    $days = (int)$bd['days'];
                } elseif ($gb <= 0 || $days <= 0) {
                    ma_fail('حجم و مدت را وارد کنید.');
                }

                $bpr = Reseller::bundlePrice($bd, $ru, $gb, $days);
                if (!isset($bpr['gb']))           $bpr['gb'] = $gb;
                if (!isset($bpr['days']))         $bpr['days'] = $days;
                if (!isset($bpr['discount_pct'])) $bpr['discount_pct'] = Reseller::userDiscount($ru);
                $bcan = Reseller::canAfford($ru, (int)$bpr['final']);

                ma_out([
                    'ok'      => true,
                    'price'   => $bpr,
                    'afford'  => !empty($bcan['ok']),
                    'message' => (string)($bcan['message'] ?? ''),
                    'quota'   => Reseller::quota($ru),
                ]);
            }
        }

        $planId = trim((string)($in['plan_id'] ?? ''));
        $plan   = ($planId !== '' && method_exists('Reseller', 'plan')) ? Reseller::plan($planId) : null;
        if ($plan) {
            $gb   = (int)$plan['gb'];
            $days = (int)$plan['days'];
        } elseif ($gb <= 0 || $days <= 0) {
            ma_fail('حجم و مدت را وارد کنید.');
        }

        $qPid = max(0, (int)($in['panel_id'] ?? 0));
        /* هم‌راستا با rs_create: اگر سرور مشخص نشده بود، همان سروری که ساخت روی آن انجام می‌شود مبنای قیمت است */
        if ($qPid <= 0 && $plan && (int)($plan['panel_id'] ?? 0) > 0) $qPid = (int)$plan['panel_id'];
        if ($qPid <= 0) {
            $qpl  = Reseller::panels();
            $qPid = (int)($qpl[0]['id'] ?? 0);
        }
        $pr   = $plan
            ? Reseller::planPrice($plan, $ru, $qPid)
            : Reseller::price($gb, $days, $ru, $qPid);
        if (!isset($pr['gb']))           $pr['gb'] = $gb;
        if (!isset($pr['days']))         $pr['days'] = $days;
        if (!isset($pr['discount_pct'])) $pr['discount_pct'] = Reseller::userDiscount($ru);
        $can = Reseller::canAfford($ru, (int)$pr['final']);

        ma_out([
            'ok'      => true,
            'price'   => $pr,
            'afford'  => !empty($can['ok']),
            'message' => (string)($can['message'] ?? ''),
            'quota'   => Reseller::quota($ru),
        ]);
    }

    case 'rs_create': {
        if (!class_exists('Reseller') || !Reseller::enabled()) ma_fail('بخش نمایندگی فعال نیست.', 403);

        $ru = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        if (!Reseller::isReseller($ru)) ma_fail('شما دسترسی نمایندگی ندارید.', 403);

        ma_secure('buy', $ru);

        try {
            $r = Reseller::create($ru, [
                'volume_gb' => (int)($in['volume_gb'] ?? 0),
                'days'      => (int)($in['days'] ?? 0),
                'name'      => (string)($in['name'] ?? ''),
                'panel_id'  => (int)($in['panel_id'] ?? 0),
                'plan_id'   => (string)($in['plan_id'] ?? ''),
            ]);
        } catch (Throwable $e) {
            ma_fail('ساخت کانفیگ ناموفق بود.');
        }

        if (empty($r['ok'])) ma_fail((string)($r['message'] ?? 'ساخت کانفیگ ناموفق بود.'));

        try { Bot::deliverCtx('reseller', (int)$ru['tg_id'], $r['service']); } catch (Throwable $e) {}

        $fresh = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];

        ma_out([
            'ok'      => true,
            'message' => (string)($r['message'] ?? 'کانفیگ ساخته شد ✅'),
            'service' => ma_service_row($r['service']),
            'price'   => $r['price'] ?? null,
            'quota'   => Reseller::quota($fresh),
            'stats'   => Reseller::stats($fresh),
        ]);
    }

    case 'rs_bundle': {
        if (!class_exists('Reseller') || !Reseller::enabled()) ma_fail('بخش نمایندگی فعال نیست.', 403);
        if (!method_exists('Reseller', 'buildBundle')) ma_fail('این نسخه از سرویس‌ها پشتیبانی نمی‌کند.');

        $ru = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        if (!Reseller::isReseller($ru)) ma_fail('شما دسترسی نمایندگی ندارید.', 403);

        ma_secure('buy', $ru);

        try {
            $r = Reseller::buildBundle($ru, [
                'bundle_id' => (string)($in['bundle_id'] ?? ''),
                'name'      => (string)($in['name'] ?? ''),
                'volume_gb' => (int)($in['volume_gb'] ?? 0),
                'days'      => (int)($in['days'] ?? 0),
            ]);
        } catch (Throwable $e) {
            ma_fail('ساخت سرویس ناموفق بود.');
        }

        if (empty($r['ok'])) ma_fail((string)($r['message'] ?? 'ساخت سرویس ناموفق بود.'));

        /* ارسال کانفیگ‌ها و لینک اشتراک در تلگرام */
        $rows = [];
        foreach ((array)($r['services'] ?? []) as $one) {
            $one = (array)$one;
            try { Bot::deliverCtx('reseller', (int)$ru['tg_id'], $one); } catch (Throwable $e) {}
            $rows[] = ma_service_row($one);
        }

        $fresh = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];

        ma_out([
            'ok'       => true,
            'message'  => (string)($r['message'] ?? 'سرویس ساخته شد ✅'),
            'services' => $rows,
            'subs'     => array_values((array)($r['subs'] ?? [])),
            'price'    => $r['price'] ?? null,
            'quota'    => Reseller::quota($fresh),
            'stats'    => Reseller::stats($fresh),
        ]);
    }

    /* داشبورد مالی/فروش نماینده — هر متریک جدا و مقاوم */
    case 'rs_money': {
        if (!class_exists('Reseller') || !Reseller::enabled()) ma_fail('بخش نمایندگی فعال نیست.', 403);

        $ru = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        if (!Reseller::isReseller($ru)) ma_fail('شما نمایندهٔ ثبت‌شده نیستید.', 403);

        $num = static function (string $sql, array $p = []): int {
            try { return (int)DB::val($sql, $p, 0); } catch (Throwable $e) { return 0; }
        };
        $flt = static function (string $sql, array $p = []): float {
            try { return (float)DB::val($sql, $p, 0); } catch (Throwable $e) { return 0.0; }
        };
        $lst = static function (string $sql, array $p = []): array {
            try { return DB::all($sql, $p); } catch (Throwable $e) { return []; }
        };

        $u = [':u' => $UID];
        $S = " FROM {p}services WHERE user_id = :u AND is_reseller = 1 AND status <> 'deleted'";
        $T = " FROM {p}transactions WHERE user_id = :u";

        $total    = $num("SELECT COUNT(*)" . $S, $u);
        $active   = $num("SELECT COUNT(*)" . $S . " AND status = 'active'", $u);
        $expired  = $num("SELECT COUNT(*)" . $S . " AND status = 'expired'", $u);
        $disabled = $num("SELECT COUNT(*)" . $S . " AND status = 'disabled'", $u);
        $missing  = $num("SELECT COUNT(*)" . $S . " AND status = 'missing'", $u);

        $c1  = $num("SELECT COUNT(*)" . $S . " AND DATE(created_at) = CURDATE()", $u);
        $c7  = $num("SELECT COUNT(*)" . $S . " AND created_at >= (NOW() - INTERVAL 7 DAY)", $u);
        $c30 = $num("SELECT COUNT(*)" . $S . " AND created_at >= (NOW() - INTERVAL 30 DAY)", $u);

        $gbSold = $flt("SELECT COALESCE(SUM(volume_gb),0)" . $S, $u);
        $usedB  = $flt("SELECT COALESCE(SUM(used_bytes),0)" . $S, $u);
        $renews = $num("SELECT COALESCE(SUM(renew_count),0)" . $S, $u);
        $soon   = $num("SELECT COUNT(*)" . $S . " AND status = 'active' AND expire_at IS NOT NULL
                        AND expire_at BETWEEN NOW() AND (NOW() + INTERVAL 7 DAY)", $u);

        $spendAll = $num("SELECT COALESCE(SUM(-amount),0)" . $T . " AND amount < 0", $u);
        $spend1   = $num("SELECT COALESCE(SUM(-amount),0)" . $T . " AND amount < 0 AND DATE(created_at) = CURDATE()", $u);
        $spend7   = $num("SELECT COALESCE(SUM(-amount),0)" . $T . " AND amount < 0 AND created_at >= (NOW() - INTERVAL 7 DAY)", $u);
        $spend30  = $num("SELECT COALESCE(SUM(-amount),0)" . $T . " AND amount < 0 AND created_at >= (NOW() - INTERVAL 30 DAY)", $u);
        $topupAll = $num("SELECT COALESCE(SUM(amount),0)" . $T . " AND amount > 0", $u);

        $stats = Reseller::stats($ru);
        if ($spendAll <= 0) $spendAll = (int)($stats['spent'] ?? 0);

        /* نمودار ۱۴ روز اخیر: تعداد کانفیگ و مبلغ خرید */
        $mapC = [];
        foreach ($lst("SELECT DATE(created_at) AS d, COUNT(*) AS c" . $S .
                      " AND created_at >= (CURDATE() - INTERVAL 13 DAY) GROUP BY DATE(created_at)", $u) as $r) {
            $mapC[(string)($r['d'] ?? '')] = (int)($r['c'] ?? 0);
        }
        $mapS = [];
        foreach ($lst("SELECT DATE(created_at) AS d, COALESCE(SUM(-amount),0) AS s" . $T .
                      " AND amount < 0 AND created_at >= (CURDATE() - INTERVAL 13 DAY) GROUP BY DATE(created_at)", $u) as $r) {
            $mapS[(string)($r['d'] ?? '')] = (int)($r['s'] ?? 0);
        }
        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime('-' . $i . ' day'));
            $lbl = $day;
            if (function_exists('to_jalali')) {
                $j = (string)to_jalali($day, 'm/d');
                if ($j !== '') $lbl = $j;
            }
            if ($lbl === $day) $lbl = date('m/d', strtotime($day));
            $series[] = [
                'd'     => $day,
                'label' => $lbl,
                'c'     => (int)($mapC[$day] ?? 0),
                's'     => (int)($mapS[$day] ?? 0),
            ];
        }

        /* تفکیک بر اساس سرور */
        $pNames = [];
        foreach ($lst('SELECT id, name FROM {p}panels') as $pn) {
            $pNames[(int)($pn['id'] ?? 0)] = (string)($pn['name'] ?? '');
        }
        $byPanel = [];
        foreach ($lst("SELECT panel_id AS pid, COUNT(*) AS c, COALESCE(SUM(volume_gb),0) AS gb,
                              COALESCE(SUM(used_bytes),0) AS ub" . $S .
                      " GROUP BY panel_id ORDER BY c DESC LIMIT 8", $u) as $r) {
            $pid = (int)($r['pid'] ?? 0);
            $byPanel[] = [
                'name' => $pNames[$pid] !== null && isset($pNames[$pid]) && $pNames[$pid] !== '' ? $pNames[$pid] : ('#' . $pid),
                'c'    => (int)($r['c'] ?? 0),
                'gb'   => round((float)($r['gb'] ?? 0), 2),
                'used' => human_bytes((int)($r['ub'] ?? 0)),
            ];
        }

        /* نزدیک‌ترین انقضاها */
        $expSoon = [];
        foreach ($lst("SELECT id, client_email, expire_at, volume_gb, used_bytes" . $S .
                      " AND status = 'active' AND expire_at IS NOT NULL ORDER BY expire_at ASC LIMIT 6", $u) as $r) {
            $ts   = strtotime((string)($r['expire_at'] ?? '')) ?: 0;
            $days = $ts > 0 ? (int)floor(($ts - time()) / 86400) : 0;
            $vol  = (float)($r['volume_gb'] ?? 0);
            $ub   = (int)($r['used_bytes'] ?? 0);
            $pct  = $vol > 0 ? min(100, (int)round($ub / ($vol * 1073741824) * 100)) : 0;
            $expSoon[] = [
                'name' => (string)($r['client_email'] ?? ('#' . (int)($r['id'] ?? 0))),
                'days' => $days,
                'pct'  => $pct,
                'used' => human_bytes($ub),
                'gb'   => round($vol, 2),
            ];
        }

        /* ۸ تراکنش اخیر کیف پول */
        $ledger = [];
        foreach ($lst("SELECT *" . $T . " ORDER BY id DESC LIMIT 8", $u) as $r) {
            $amt  = (int)($r['amount'] ?? 0);
            $note = trim((string)($r['note'] ?? ($r['description'] ?? ($r['title'] ?? ($r['method'] ?? '')))));
            $ledger[] = [
                'amount'     => $amt,
                'amount_txt' => ma_money(abs($amt)),
                'dir'        => $amt < 0 ? 'out' : 'in',
                'note'       => $note !== '' ? $note : ($amt < 0 ? 'برداشت' : 'شارژ'),
                'at'         => (string)($r['created_at'] ?? ''),
            ];
        }

        $avg = $total > 0 ? (int)round($spendAll / $total) : 0;
        $pct = $total > 0 ? (int)round($active * 100 / $total) : 0;

        ma_out([
            'ok'    => true,
            'quota' => Reseller::quota($ru),
            'stats' => $stats,
            'cnt'   => [
                'total' => $total, 'active' => $active, 'expired' => $expired,
                'disabled' => $disabled, 'missing' => $missing,
                'today' => $c1, 'd7' => $c7, 'd30' => $c30,
                'soon' => $soon, 'renews' => $renews, 'pct_active' => $pct,
            ],
            'vol'   => [
                'gb_sold'  => round($gbSold, 2),
                'used'     => human_bytes((int)$usedB),
                'used_gb'  => round($usedB / 1073741824, 2),
            ],
            'spend' => [
                'all' => $spendAll, 'today' => $spend1, 'd7' => $spend7, 'd30' => $spend30,
                'avg' => $avg, 'topup' => $topupAll,
                'all_txt' => ma_money($spendAll), 'today_txt' => ma_money($spend1),
                'd7_txt' => ma_money($spend7), 'd30_txt' => ma_money($spend30),
                'avg_txt' => ma_money($avg), 'topup_txt' => ma_money($topupAll),
                'daily_avg' => (int)round($spend30 / 30),
            ],
            'series'   => $series,
            'by_panel' => $byPanel,
            'exp_soon' => $expSoon,
            'ledger'   => $ledger,
        ]);
    }

    case 'rs_list': {
        if (!class_exists('Reseller') || !Reseller::enabled()) ma_fail('بخش نمایندگی فعال نیست.', 403);

        $ru = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        if (!Reseller::isReseller($ru)) ma_fail('شما دسترسی نمایندگی ندارید.', 403);

        $rows = [];
        foreach (Reseller::services($ru, 60) as $rs) {
            /* ردیف های حذف شده هرگز نباید در فهرست بیایند */
            if ((string)($rs['status'] ?? '') === 'deleted') continue;
            if ((int)($rs['is_reseller'] ?? 0) !== 1) continue;
            $rows[] = ma_service_row($rs);
        }

        ma_out(['ok' => true, 'services' => $rows,
            'quota' => Reseller::quota($ru), 'stats' => Reseller::stats($ru)]);
    }

    /* ---------------- به روزرسانی دستی مصرف کانفیگ نماینده ---------------- */
    case 'rs_sync': {
        if (!class_exists('Reseller') || !Reseller::enabled()) ma_fail('بخش نمایندگی فعال نیست.', 403);

        $ru = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        if (!Reseller::isReseller($ru)) ma_fail('شما دسترسی نمایندگی ندارید.', 403);

        $one = (int)($in['id'] ?? 0);

        /* یک کانفیگ خاص */
        if ($one > 0) {
            $svc = Reseller::ownService($ru, $one);
            if (!$svc) ma_fail('این کانفیگ پیدا نشد یا متعلق به شما نیست.', 404);

            /* همه ردیف های همین گروه (چندپنلی) باهم به روز می شوند */
            $grp = trim((string)($svc['group_key'] ?? ''));
            $set = $grp !== '' && method_exists('Reseller', 'groupRows')
                ? Reseller::groupRows($grp)
                : [$svc];
            try { Svc::syncStale($set, 0, 12, true); } catch (Throwable $e) { }

            $fresh = Svc::find($one) ?: $svc;
            ma_out(['ok' => true, 'message' => 'مصرف از پنل خوانده شد ✅',
                'service' => ma_service_row($fresh),
                'quota'   => Reseller::quota($ru), 'stats' => Reseller::stats($ru)]);
        }

        /* همه کانفیگ ها */
        $raw = Reseller::services($ru, 60, false);
        try { $raw = Svc::syncStale($raw, 0, 25, true); } catch (Throwable $e) { }

        $rows = [];
        foreach ($raw as $rs) {
            if ((string)($rs['status'] ?? '') === 'deleted') continue;
            if ((int)($rs['is_reseller'] ?? 0) !== 1) continue;
            $rows[] = ma_service_row($rs);
        }

        ma_out(['ok' => true, 'message' => 'مصرف همه کانفیگ ها به روز شد ✅',
            'services' => $rows,
            'quota' => Reseller::quota($ru), 'stats' => Reseller::stats($ru)]);
    }

    /* ---------------- ویرایش و حذف کانفیگ نماینده ---------------- */

    case 'rs_svc_quote': {
        if (!class_exists('Reseller') || !Reseller::enabled()) ma_fail('بخش نمایندگی فعال نیست.', 403);
        $ru = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        if (!Reseller::isReseller($ru)) ma_fail('شما دسترسی نمایندگی ندارید.', 403);

        $svc = Reseller::ownService($ru, (int)($in['id'] ?? 0));
        if (!$svc) ma_fail('این کانفیگ پیدا نشد یا متعلق به شما نیست.', 404);

        $q = Reseller::editQuote($ru, $svc, (float)($in['gb'] ?? 0), (int)($in['days'] ?? 0));
        $svcPid = (int)($svc['panel_id'] ?? 0);
        ma_out(['ok' => true, 'quote' => $q,
            'min_gb' => Reseller::minGbForPanel($svcPid), 'max_gb' => Reseller::maxGbForPanel($ru, $svcPid),
            'max_days' => Reseller::maxDaysForPanel($ru, $svcPid),
            'shrink_refund' => Reseller::shrinkRefund()]);
    }

    case 'rs_svc_edit': {
        if (!class_exists('Reseller') || !Reseller::enabled()) ma_fail('بخش نمایندگی فعال نیست.', 403);
        $ru = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        if (!Reseller::isReseller($ru)) ma_fail('شما دسترسی نمایندگی ندارید.', 403);

        $sid = (int)($in['id'] ?? 0);
        $r   = Reseller::editService($ru, $sid, (float)($in['gb'] ?? 0), (int)($in['days'] ?? 0));
        if (empty($r['ok'])) ma_fail((string)($r['message'] ?? 'ویرایش انجام نشد.'));

        ma_out(['ok' => true, 'message' => (string)$r['message'],
            'cost' => (int)($r['cost'] ?? 0), 'refund' => (int)($r['refund'] ?? 0),
            'balance' => (int)($r['balance'] ?? 0), 'balance_txt' => (string)($r['balance_txt'] ?? ''),
            'service' => !empty($r['service']) ? ma_service_row((array)$r['service']) : null]);
    }

    case 'rs_svc_delete': {
        if (!class_exists('Reseller') || !Reseller::enabled()) ma_fail('بخش نمایندگی فعال نیست.', 403);
        $ru = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        if (!Reseller::isReseller($ru)) ma_fail('شما دسترسی نمایندگی ندارید.', 403);

        if ((string)($in['confirm'] ?? '') !== 'yes') ma_fail('برای حذف، تایید لازم است.');

        $r = Reseller::deleteService($ru, (int)($in['id'] ?? 0));
        if (empty($r['ok'])) ma_fail((string)($r['message'] ?? 'حذف انجام نشد.'));
        ma_out(['ok' => true, 'message' => (string)$r['message'], 'removed' => (int)($r['removed'] ?? 0)]);
    }
    /* ---------------- سطل زباله کانفیگ های نماینده ---------------- */

    case 'rs_trash': {
        if (!class_exists('Reseller') || !Reseller::enabled()) ma_fail('بخش نمایندگی فعال نیست.', 403);
        $ru = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        if (!Reseller::isReseller($ru)) ma_fail('شما دسترسی نمایندگی ندارید.', 403);

        $rows = [];
        foreach (Svc::trash($UID, 60, true) as $ts) {
            $row = ma_service_row($ts);
            $row['deleted_txt'] = !empty($ts['deleted_at']) ? to_jalali((string)$ts['deleted_at'], true) : '-';
            $row['purge_in']    = Svc::trashLeftDays($ts);
            $rows[] = $row;
        }

        ma_out(['ok' => true, 'services' => $rows, 'count' => count($rows),
            'days' => Svc::trashDays()]);
    }

    case 'rs_trash_purge': {
        if (!class_exists('Reseller') || !Reseller::enabled()) ma_fail('بخش نمایندگی فعال نیست.', 403);
        $ru = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        if (!Reseller::isReseller($ru)) ma_fail('شما دسترسی نمایندگی ندارید.', 403);

        $own = [];
        foreach (Svc::trash($UID, 300, true) as $ts) $own[(int)$ts['id']] = true;

        $one = (int)($in['id'] ?? 0);
        $ids = [];
        if ($one > 0) {
            if (isset($own[$one])) $ids[] = $one;
        } else {
            $ids = array_keys($own);
        }
        if ($ids === []) ma_fail('موردی برای پاک کردن پیدا نشد.', 404);

        $k = Svc::purgeNow($ids);
        ma_out(['ok' => true, 'removed' => $k,
            'message' => $k > 0
                ? '✅ ' . en_num((string)$k) . ' مورد کامل پاک شد.'
                : 'موردی پاک نشد.']);
    }


    /* ربات اختصاصی نمایندگی — ۱) پرداخت هزینه */
    case 'rs_bot_buy': {
        if (!class_exists('Reseller') || !Reseller::enabled()) ma_fail('بخش نمایندگی فعال نیست.', 403);

        $ru = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        if (!Reseller::isReseller($ru)) ma_fail('این بخش فقط برای نمایندگان است.', 403);
        if (!class_exists('RsBot') || !RsBot::enabled()) ma_fail('راه‌اندازی ربات اختصاصی فعلاً فعال نیست.', 400);

        $price = RsBot::price();

        /* قبلاً پرداخت شده یا رایگان است — فقط مرحلهٔ بعد را باز کن */
        if (RsBot::paid($UID)) {
            ma_out([
                'ok'      => true,
                'paid'    => true,
                'message' => 'هزینهٔ راه‌اندازی قبلاً تسویه شده است؛ توکن ربات را ثبت کنید.',
                'rsbot'   => RsBot::info($ru),
            ]);
        }

        $can = Reseller::canAfford($ru, $price);
        if (empty($can['ok'])) ma_fail((string)($can['message'] ?? 'موجودی کافی نیست.'), 400);

        $ch = Reseller::charge($ru, $price, 'راه‌اندازی ربات اختصاصی نمایندگی');
        if (empty($ch['ok'])) ma_fail((string)($ch['message'] ?? 'کسر مبلغ انجام نشد.'), 400);

        /* با method = rsbot علامت‌گذاری می‌شود تا پرداخت دوباره انجام نشود */
        try {
            if (!empty($ch['tx'])) {
                DB::update('transactions', ['method' => 'rsbot'], 'id = :i', [':i' => (int)$ch['tx']]);
            }
        } catch (Throwable $e) {
        }

        $who = trim((string)($ru['first_name'] ?? ''));
        if ($who === '') $who = 'نماینده';
        $msg = "\xf0\x9f\xa4\x96 <b>پرداخت هزینهٔ ربات اختصاصی نمایندگی</b>\n"
            . 'نماینده: ' . h($who) . ' (<code>' . (int)($ru['tg_id'] ?? 0) . '</code>)' . "\n"
            . 'مبلغ: ' . money($price) . ' ' . currency() . "\n"
            . (RsBot::auto()
                ? 'ساخت ربات خودکار است؛ نماینده توکن خود را ثبت می‌کند.'
                : 'لطفاً ربات این نماینده را راه‌اندازی کنید.');

        try { AdminBot::notifyAdmins($msg); } catch (Throwable $e) {}
        try { Logs::send('financial', $msg); } catch (Throwable $e) {}

        $fresh = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        ma_out([
            'ok'          => true,
            'paid'        => true,
            'message'     => RsBot::auto()
                ? '✅ پرداخت انجام شد. اکنون توکن ربات و آیدی عددی خود را بدهید تا همین حالا ربات ساخته شود.'
                : 'پرداخت انجام شد و درخواست شما ثبت گردید.',
            'balance'     => (int)($fresh['balance'] ?? 0),
            'balance_txt' => ma_money((int)($fresh['balance'] ?? 0)),
            'rsbot'       => RsBot::info($fresh ?: $ru),
        ]);
    }

    /* ربات اختصاصی نمایندگی — ۲) ساخت کاملاً خودکار با توکن و آیدی مالک */
    case 'rs_bot_setup': {
        if (!class_exists('Reseller') || !Reseller::enabled()) ma_fail('بخش نمایندگی فعال نیست.', 403);
        if (!class_exists('RsBot')) ma_fail('سرویس ربات اختصاصی در دسترس نیست.', 500);

        $ru = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        if (!Reseller::isReseller($ru)) ma_fail('این بخش فقط برای نمایندگان است.', 403);
        if (!RsBot::enabled()) ma_fail('راه‌اندازی ربات اختصاصی فعلاً فعال نیست.', 400);
        if (!RsBot::auto()) ma_fail('ساخت خودکار غیرفعال است؛ مدیر ربات شما را راه‌اندازی می‌کند.', 400);
        if (!RsBot::paid($UID)) ma_fail('اب��دا ه��ی����ٔ راه‌اندازی را پرداخت کنید.', 400);

        $token = trim((string)($in['token'] ?? ''));
        $owner = (int)preg_replace('~\D+~', '', (string)($in['owner_id'] ?? ''));

        $r = RsBot::provision($ru, $token, $owner, ['paid' => RsBot::price()]);
        if (empty($r['ok'])) ma_fail((string)($r['message'] ?? 'ساخت ربات انجام نشد.'), 400);

        ma_out([
            'ok'      => true,
            'message' => (string)$r['message'],
            'rsbot'   => RsBot::info($ru),
        ]);
    }

    /* ربات اختصاصی نمایندگی — ۳) مدیریت توسط خود نماینده */
    case 'rs_bot_ctl': {
        if (!class_exists('Reseller') || !Reseller::enabled()) ma_fail('بخش نمایندگی فعال نیست.', 403);
        if (!class_exists('RsBot')) ma_fail('سرویس ربات اختصاصی در دسترس نیست.', 500);

        $ru = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        if (!Reseller::isReseller($ru)) ma_fail('این بخش فقط برای نمایندگان است.', 403);

        $row = RsBot::forUser($UID);
        if (!$row) ma_fail('ربات اختصاصی فعالی برای شما ثبت نشده است.', 404);

        $act = trim((string)($in['act'] ?? ''));
        $r   = ['ok' => false, 'message' => 'در��واست نامعتبر است.'];
        $hlt = null;

        if ($act === 'pause') {
            $r = RsBot::pause($row);
        } elseif ($act === 'resume' || $act === 'rehook') {
            $r = RsBot::reHook($row);
        } elseif ($act === 'rotate') {
            $r = RsBot::rotate($row);
        } elseif ($act === 'delete') {
            $r = RsBot::remove($row);
        } elseif ($act === 'health') {
            $hlt = RsBot::health($row);
            $r   = [
                'ok'      => !empty($hlt['ok']),
                'message' => !empty($hlt['ok'])
                    ? ('✅ وب‌هوک سالم است. در انتظار: ' . en_num((string)(int)$hlt['pending'])
                        . ((string)$hlt['last_error'] !== '' ? ' | آخرین خطا: ' . (string)$hlt['last_error'] : ''))
                    : 'بررسی وب‌هوک انجام نشد.',
            ];
        } else {
            ma_fail('درخواست نامعتبر است.', 400);
        }

        $fresh = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: $ru;
        ma_out([
            'ok'      => !empty($r['ok']),
            'message' => (string)($r['message'] ?? ''),
            'health'  => $hlt,
            'rsbot'   => RsBot::info($fresh),
        ]);
    }

    /* ---------- من: پروفایل + آمار + سیستم معرفی ---------- */
    case 'me_info': {
        $me = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: $user;

        $svcN = (int)DB::val(
            'SELECT COUNT(*) FROM {p}services WHERE user_id = :u AND status <> :d AND COALESCE(is_reseller, 0) = 0',
            [':u' => $UID, ':d' => 'deleted'], 0);
        $paidT = (int)DB::val(
            'SELECT COALESCE(SUM(amount), 0) FROM {p}transactions WHERE user_id = :u AND type = :t AND status = :s',
            [':u' => $UID, ':t' => 'deposit', ':s' => 'approved'], 0);
        $balN = (int)Wallet::balance($UID);

        $rf  = ['enabled' => false];
        $inv = [];
        if (class_exists('Referral')) {
            $rf = Referral::stats($me);
            if (!empty($rf['enabled'])) $inv = Referral::invitees((int)($me['tg_id'] ?? 0), 20);
        }

        ma_out([
            'ok'   => true,
            'user' => [
                'name'        => (string)($me['name'] ?? $me['first_name'] ?? ''),
                'email'       => (string)($me['email'] ?? ''),
                'phone'       => (string)($me['phone'] ?? ''),
                'tg_id'       => (int)($me['tg_id'] ?? 0),
                'verified'    => !empty($me['verified']),
                'balance'     => $balN,
                'balance_txt' => ma_money($balN),
            ],
            'stats' => [
                'services' => $svcN,
                'paid'     => $paidT,
                'paid_txt' => ma_money($paidT),
            ],
            'referral' => $rf,
            'invitees' => $inv,
            'support'  => (string)DB::setting('support_username', ''),
        ]);
        break;
    }

    /* ---------- اعمال کد هدیه ---------- */
    case 'gift_redeem': {
        $gcode = strtoupper(trim(en_num((string)($in['code'] ?? ''))));
        if ($gcode === '') ma_fail('کد هدیه را وارد کنید.', 400);
        if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $gcode)) ma_fail('قالب کد هدیه نامعتبر است.', 400);

        $me   = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: $user;
        $gr   = Codes::redeemGift($gcode, $me);
        $bal2 = (int)Wallet::balance($UID);

        ma_out([
            'ok'          => !empty($gr['ok']),
            'message'     => (string)($gr['message'] ?? ''),
            'balance'     => $bal2,
            'balance_txt' => ma_money($bal2),
        ]);
        break;
    }

    /* ---------------- انبار ملی ---------------- */
    case 'stock': {
        if (!class_exists('Stock') || !Stock::enabled()) {
            ma_out(['ok' => true, 'enabled' => false, 'cats' => []]);
        }
        $cats = [];
        foreach (Stock::shopCats() as $c) {
            $free = Stock::freeCount((int)$c['id']);
            if ($free < 1 && !Stock::showEmpty()) continue;
            $kd = (string)($c['kind'] ?? 'config');
            $cats[] = [
                'id'         => (int)$c['id'],
                'name'       => (string)$c['name'],
                'icon'       => (string)($c['icon'] ?? ''),
                'kind'       => $kd,
                'kind_label' => Stock::kindLabel($kd),
                'kind_icon'  => Stock::kindIcon($kd),
                'desc'       => (string)($c['description'] ?? ''),
                'price'      => (int)$c['price'],
                'price_txt'  => ma_money((int)$c['price']),
                'old'        => (int)($c['old_price'] ?? 0),
                'old_txt'    => ((int)($c['old_price'] ?? 0) > 0) ? ma_money((int)$c['old_price']) : '',
                'days'       => (int)($c['days'] ?? 0),
                'gb'         => (float)($c['volume_gb'] ?? 0),
                'free'       => $free,
                'sold'       => (int)($c['sold'] ?? 0),
            ];
        }
        $bal = (int)Wallet::balance($UID);
        ma_out([
            'ok'          => true,
            'enabled'     => true,
            'title'       => Stock::title(),
            'icon'        => Stock::icon(),
            'note'        => Stock::note(),
            'balance'     => $bal,
            'balance_txt' => ma_money($bal),
            'cats'        => $cats,
        ]);
    }

    case 'stock_buy': {
        if (!class_exists('Stock') || !Stock::enabled()) ma_fail('انبار در دسترس نیست.');
        $cid = (int)($in['id'] ?? 0);
        if ($cid <= 0) ma_fail('بستهٔ انتخابی معتبر نیست.');

        $me = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: $user;
        $r  = Stock::buy((array)$me, $cid);
        if (empty($r['ok'])) {
            ma_out(['ok' => false, 'message' => (string)($r['message'] ?? 'خرید انجام نشد.'),
                'need' => (string)($r['need'] ?? '')]);
        }

        $it = (array)($r['item'] ?? []);
        try { Stock::deliver((int)($me['tg_id'] ?? 0), $it, $r['cat'] ?? null); } catch (Throwable $e) { }

        $bal = (int)Wallet::balance($UID);
        ma_out([
            'ok'          => true,
            'message'     => (string)($r['message'] ?? 'خرید انجام شد.'),
            'title'       => (string)($it['title'] ?? ''),
            'payload'     => (string)($it['payload'] ?? ''),
            'is_file'     => ((string)($it['file_id'] ?? '') !== ''),
            'balance'     => $bal,
            'balance_txt' => ma_money($bal),
        ]);
    }

    case 'stock_mine': {
        if (!class_exists('Stock')) ma_fail('این بخش در دسترس نیست.');
        $rows = [];
        foreach (Stock::myItems($UID, 40) as $it) {
            $c  = Stock::cat((int)($it['cat_id'] ?? 0));
            $kd = (string)($it['kind'] ?? 'text');
            $rows[] = [
                'id'        => (int)$it['id'],
                'title'     => (string)($it['title'] ?? ''),
                'cat'       => $c ? (string)$c['name'] : '',
                'kind_icon' => Stock::kindIcon($kd),
                'payload'   => (string)($it['payload'] ?? ''),
                'is_file'   => ((string)($it['file_id'] ?? '') !== ''),
                'date'      => function_exists('to_jalali')
                    ? to_jalali((string)($it['sold_at'] ?? ($it['created_at'] ?? '')))
                    : (string)($it['sold_at'] ?? ''),
            ];
        }
        ma_out(['ok' => true, 'items' => $rows]);
    }

    case 'stock_resend': {
        if (!class_exists('Stock')) ma_fail('این بخش در دسترس نیست.');
        $iid = (int)($in['id'] ?? 0);
        $it  = $iid > 0 ? Stock::item($iid) : null;
        if (!$it || (int)($it['user_id'] ?? 0) !== $UID) ma_fail('این مورد پیدا نشد.');
        $me = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: $user;
        try { Stock::deliver((int)($me['tg_id'] ?? 0), (array)$it, Stock::cat((int)($it['cat_id'] ?? 0))); }
        catch (Throwable $e) { ma_fail('ارسال مجدد انجام نشد.'); }
        ma_out(['ok' => true, 'msg' => '✅ دوباره در ربات برای شما ارسال شد.']);
    }

    /* ==================== انبار ملی — نمایندگی ==================== */

    case 'rs_stock': {
        if (!class_exists('Reseller') || !Reseller::enabled()) ma_fail('بخش نمایندگی فعال نیست.', 403);
        $ru = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        if (!Reseller::isReseller($ru)) ma_fail('شما نمایندهٔ ثبت‌شده نیستید.', 403);

        if (!class_exists('Stock') || !Stock::enabled() || !Stock::rsEnabled()) {
            ma_out(['ok' => true, 'enabled' => false, 'cats' => []]);
        }

        $cats = [];
        foreach (Stock::shopCats() as $c) {
            $free = Stock::freeCount((int)$c['id']);
            if ($free < 1 && !Stock::showEmpty()) continue;

            $kd   = (string)($c['kind'] ?? 'config');
            $cost = Stock::rsPrice($c);
            $pub  = (int)$c['price'];
            $gain = max(0, $pub - $cost);

            $cats[] = [
                'id'         => (int)$c['id'],
                'name'       => (string)$c['name'],
                'icon'       => (string)($c['icon'] ?? ''),
                'kind'       => $kd,
                'kind_label' => Stock::kindLabel($kd),
                'kind_icon'  => Stock::kindIcon($kd),
                'desc'       => (string)($c['description'] ?? ''),
                'cost'       => $cost,
                'cost_txt'   => ma_money($cost),
                'pub'        => $pub,
                'pub_txt'    => ma_money($pub),
                'profit'     => $gain,
                'profit_txt' => ma_money($gain),
                'days'       => (int)($c['days'] ?? 0),
                'gb'         => (float)($c['volume_gb'] ?? 0),
                'free'       => $free,
                'mine'       => Stock::boughtCount($UID, (int)$c['id']),
            ];
        }

        $bal = (int)Wallet::balance($UID);
        ma_out([
            'ok'          => true,
            'enabled'     => true,
            'title'       => Stock::title(),
            'icon'        => Stock::icon(),
            'note'        => Stock::note(),
            'cap'         => Stock::rsPerUser(),
            'balance'     => $bal,
            'balance_txt' => ma_money($bal),
            'cats'        => $cats,
        ]);
    }

    case 'rs_stock_buy': {
        if (!class_exists('Reseller') || !Reseller::enabled()) ma_fail('بخش نمایندگی فعال نیست.', 403);
        $ru = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        if (!Reseller::isReseller($ru)) ma_fail('شما نمایندهٔ ثبت‌شده نیستید.', 403);
        if (!class_exists('Stock') || !Stock::enabled() || !Stock::rsEnabled()) ma_fail('انبار در دسترس نیست.');

        $cid = (int)($in['id'] ?? 0);
        if ($cid <= 0) ma_fail('بستهٔ انتخابی معتبر نیست.');

        $qty  = max(1, min(20, (int)($in['qty'] ?? 1)));
        $done = [];
        $spent = 0;
        $gain  = 0;
        $stop  = '';

        for ($i = 0; $i < $qty; $i++) {
            $r = Stock::buy((array)$ru, $cid, true);
            if (empty($r['ok'])) { $stop = (string)($r['message'] ?? 'خرید انجام نشد.'); break; }

            $it     = (array)($r['item'] ?? []);
            $spent += (int)($r['price'] ?? 0);
            $gain  += (int)($r['profit'] ?? 0);

            $done[] = [
                'id'      => (int)($it['id'] ?? 0),
                'title'   => (string)($it['title'] ?? ''),
                'payload' => (string)($it['payload'] ?? ''),
                'is_file' => ((string)($it['file_id'] ?? '') !== ''),
            ];

            try { Stock::deliver((int)($ru['tg_id'] ?? 0), $it, $r['cat'] ?? null); } catch (Throwable $e) { }
        }

        if (!$done) ma_out(['ok' => false, 'message' => $stop ?: 'خرید انجام نشد.']);

        $bal = (int)Wallet::balance($UID);
        ma_out([
            'ok'          => true,
            'message'     => '✅ ' . fa_num((string)count($done)) . ' قلم خریداری شد.',
            'items'       => $done,
            'spent'       => $spent,
            'spent_txt'   => ma_money($spent),
            'profit'      => $gain,
            'profit_txt'  => ma_money($gain),
            'partial'     => (count($done) < $qty) ? $stop : '',
            'balance'     => $bal,
            'balance_txt' => ma_money($bal),
        ]);
    }

    case 'rs_stock_mine': {
        if (!class_exists('Stock')) ma_fail('این بخش در دسترس نیست.');

        $rows = [];
        $gain = 0;

        foreach (Stock::myItems($UID, 60) as $it) {
            $c  = Stock::cat((int)($it['cat_id'] ?? 0));
            $kd = (string)($it['kind'] ?? 'text');
            $pf = $c ? Stock::rsProfit($c) : 0;
            $gain += $pf;

            $rows[] = [
                'id'         => (int)$it['id'],
                'title'      => (string)($it['title'] ?? ''),
                'cat'        => $c ? (string)$c['name'] : '',
                'kind_icon'  => Stock::kindIcon($kd),
                'payload'    => (string)($it['payload'] ?? ''),
                'is_file'    => ((string)($it['file_id'] ?? '') !== ''),
                'pub_txt'    => $c ? ma_money((int)$c['price']) : '',
                'profit_txt' => ma_money($pf),
                'date'       => function_exists('to_jalali')
                    ? to_jalali((string)($it['sold_at'] ?? ($it['created_at'] ?? '')))
                    : (string)($it['sold_at'] ?? ''),
            ];
        }

        ma_out(['ok' => true, 'items' => $rows, 'profit' => $gain, 'profit_txt' => ma_money($gain)]);
    }

    default:
        ma_fail('درخواست نامعتبر است.', 404);
}
