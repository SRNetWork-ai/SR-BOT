<?php
declare(strict_types=1);

/**
 * نقطه ورود وب‌هوک تلگرام
 * آدرس وب‌هوک: https://دامنه-شما/index.php
 */

require __DIR__ . '/app/bootstrap.php';

if (!app_installed()) {
    header('Location: install/index.php');
    exit;
}

boot();

$raw = file_get_contents('php://input');
if ($raw === false || trim((string)$raw) === '') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html dir="rtl" lang="fa"><meta charset="utf-8">'
        . '<title>' . h((string)DB::setting('shop_title', 'فروشگاه کانفیگ')) . '</title>'
        . '<body style="font-family:system-ui;background:#F9F8F7;color:#2C2C2B;display:flex;align-items:center;'
        . 'justify-content:center;height:100vh;margin:0"><div style="text-align:center">'
        . '<div style="font-size:40px">🤖</div><h1 style="font-size:18px">ربات فعال است</h1>'
        . '<p style="color:#7D7A75;font-size:14px">نسخه ' . APP_VERSION . '</p></div></body></html>';
    exit;
}

$secret = (string)cfg('bot.secret', '');
$header = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if ($secret !== '' && !hash_equals($secret, $header)) {
    http_response_code(401);
    echo 'unauthorized';
    exit;
}

$update = json_decode((string)$raw, true);
if (!is_array($update)) {
    http_response_code(400);
    echo 'bad request';
    exit;
}

/* 0.0.2 #32: a retried delivery of the same update must not be handled twice */
$updateId = (int)($update['update_id'] ?? 0);
if ($updateId > 0 && class_exists('Dedup') && !Dedup::first($updateId)) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":true,"duplicate":true}';
    exit;
}

/* ==========================================================
   حالت «فقط مینی‌اپ» (سکوت ربات)
   وقتی روشن باشد ربات به هیچ پیامی پاسخ نمی‌دهد و کاربران فقط
   از طریق مینی‌اپ کار می‌کنند. تلگرام همچنان پاسخ 200 می‌گیرد
   تا وب‌هوک غیرفعال نشود.
   ========================================================== */
if ((string)DB::setting('bot_mute', '0') === '1') {
    $muteFrom = 0;
    foreach (['message', 'edited_message', 'callback_query', 'inline_query',
              'my_chat_member', 'chat_member', 'pre_checkout_query'] as $muteKey) {
        if (isset($update[$muteKey]['from']['id'])) {
            $muteFrom = (int)$update[$muteKey]['from']['id'];
            break;
        }
    }

    $muteAdminPass = (string)DB::setting('bot_mute_admins', '1') === '1';
    $muteBypass    = $muteAdminPass && $muteFrom > 0 && is_admin_id($muteFrom);

    if (!$muteBypass) {
        /*
         * اگر کاربر /start زده باشد، به جای سکوت کامل یک متن کوتاه
         * با دکمهٔ ورود به اپ (و برای نمایندگان دکمهٔ پنل نمایندگی) می‌فرستیم.
         */
        $muteText = trim((string)($update['message']['text'] ?? ''));
        if ($muteFrom > 0 && str_starts_with($muteText, '/start')) {
            try {
                Bot::muteStart($muteFrom);
            } catch (Throwable $e) {
                app_log('bot', 'muteStart error: ' . $e->getMessage());
            }
        }

        /*
         * دکمه‌های همین پیام حالت سکوت (مانند «درخواست نمایندگی») باید کار کنند؛
         * پس فقط همین پیشوند اجازهٔ عبور دارد و بقیهٔ ربات در سکوت می‌ماند.
         */
        $muteCb = (string)($update['callback_query']['data'] ?? '');
        if ($muteCb !== '' && str_starts_with($muteCb, 'mute:')) {
            try {
                Bot::muteRoute($muteCb, (array)($update['callback_query'] ?? []));
            } catch (Throwable $e) {
                app_log('bot', 'muteRoute error: ' . $e->getMessage());
            }
        }

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"ok":true}';
        exit;
    }
}

// پاسخ فوری به تلگرام و ادامه پردازش در پس‌زمینه
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo '{"ok":true}';
if (function_exists('fastcgi_finish_request')) {
    @fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    @litespeed_finish_request();
}

ignore_user_abort(true);
set_time_limit(120);

// گزارش خطاهای مرگبار تا هیچ درخواستی بی‌پاسخ نماند
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    $msg = (string)$e['message'] . ' @ ' . basename((string)$e['file']) . ':' . (int)$e['line'];
    app_log('fatal', $msg);
    try {
        if (class_exists('AdminBot')) AdminBot::notifyAdmins("⚠️ <b>خطای سیستمی ربات</b>\n<code>" . h($msg) . '</code>');
        if (class_exists('Logs')) Logs::send('errors', Logs::fmt('⚠️ خطای سیستمی ربات', ['خطا' => h($msg)]));
    } catch (Throwable $t) {
    }
});

try {
    Bot::handle($update);
} catch (Throwable $e) {
    app_log('fatal', 'handle: ' . $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine()]);
}
