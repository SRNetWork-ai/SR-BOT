<?php
declare(strict_types=1);

/**
 * وب‌هوک ربات‌های اختصاصی نمایندگان
 *
 * هر نماینده با یک کلید امن اختصاصی اینجا می‌رسد:
 *   https://<panel>/rbot.php?k=<secret>
 * موتور ربات همان موتور اصلی است، فقط توکن و مالک عوض می‌شود.
 */

require __DIR__ . '/app/bootstrap.php';

if (!app_installed()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'not installed';
    exit;
}

boot();

$key = (string)($_GET['k'] ?? '');
$bot = class_exists('RsBot') ? RsBot::bySecret($key) : null;
$raw = file_get_contents('php://input');

if (!$bot) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>404</title>'
        . '<div style="font:15px system-ui;padding:40px;text-align:center;direction:rtl">'
        . 'رباتی با این کلید پیدا نشد.</div>';
    exit;
}

/* بازدید مرورگر */
if ($raw === '' || $raw === false) {
    header('Content-Type: text/html; charset=utf-8');
    $u  = (string)($bot['username'] ?? '');
    $st = (string)($bot['status'] ?? '');
    echo '<!doctype html><meta charset="utf-8"><title>bot</title>'
        . '<div style="font:15px system-ui;padding:40px;text-align:center;direction:rtl">'
        . '🤖 ربات ' . ($u !== '' ? '@' . h($u) . ' ' : '') . 'فعال است.<br>'
        . '<small style="opacity:.6">' . h((string)(RsBot::STATUS[$st] ?? $st)) . '</small></div>';
    exit;
}

/* اعتبارسنجی هدر امنیتی تلگرام */
$hdr = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (!hash_equals((string)($bot['secret'] ?? ''), $hdr)) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'unauthorized';
    exit;
}

/* ربات متوقف‌شده — بی‌صدا بپذیر و رد کن */
if ((string)($bot['status'] ?? '') !== 'active') {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":true}';
    exit;
}

$update = json_decode($raw, true);
if (!is_array($update)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'bad request';
    exit;
}

/* از اینجا به بعد، همهٔ کد در بستر ربات نماینده اجرا می‌شود */
Tg::setToken((string)$bot['token']);
$GLOBALS['RSBOT'] = $bot;

/* پاسخ فوری به تلگرام تا تایم‌اوت نخورد */
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo '{"ok":true}';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
}
ignore_user_abort(true);
@set_time_limit(120);

RsBot::touch((int)$bot['id']);

$fromId = (int)($update['message']['from']['id']
    ?? $update['callback_query']['from']['id']
    ?? $update['edited_message']['from']['id']
    ?? $update['my_chat_member']['from']['id']
    ?? 0);

try {
    Bot::handle($update);
} catch (Throwable $e) {
    app_log('rsbot', 'handle failed: ' . $e->getMessage(), [
        'bot'  => (int)$bot['id'],
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);
}

if ($fromId > 0) {
    RsBot::tagUser($bot, $fromId);
}
