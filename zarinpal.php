<?php
declare(strict_types=1);

/**
 * بازگشت کاربر از درگاه زرین‌پال — 0.0.2 #22
 *
 * این آدرس را به عنوان callback درگاه ثبت کنید:
 *   https://YOUR-DOMAIN/zarinpal.php
 */

require __DIR__ . '/app/bootstrap.php';

if (!app_installed()) {
    http_response_code(503);
    echo 'not installed';
    exit;
}

boot();

if (!class_exists('Zarinpal')) {
    http_response_code(500);
    echo 'zarinpal class missing';
    exit;
}

$authority = trim((string)($_GET['Authority'] ?? ($_GET['authority'] ?? '')));
$status    = strtoupper(trim((string)($_GET['Status'] ?? ($_GET['status'] ?? ''))));

/* بازدید ساده بدون پارامتر: فقط وضعیت درگاه */
if ($authority === '') {
    header('Content-Type: text/plain; charset=utf-8');
    echo Zarinpal::enabled() ? 'zarinpal callback ready' : 'zarinpal disabled';
    exit;
}

$res = Zarinpal::apply($authority, $status !== '' ? $status : 'OK');
$ok  = !empty($res['ok']);

app_log('zarinpal', 'callback', [
    'authority' => $authority,
    'status'    => $status,
    'ok'        => $ok ? 1 : 0,
    'message'   => (string)($res['message'] ?? ''),
]);

header('Content-Type: text/html; charset=utf-8');

$brand = defined('APP_BRAND') ? (string)APP_BRAND : 'فروشگاه';
$icon  = $ok ? "\u{2705}" : "\u{26D4}";
$title = $ok ? 'پرداخت با موفقیت انجام شد' : 'پرداخت انجام نشد';
$msg   = (string)($res['message'] ?? '');
$ref   = (string)($res['ref'] ?? '');
$col   = $ok ? '#2FD48F' : '#F87171';
$rgb   = $ok ? '47,212,143' : '248,113,113';

echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>بازگشت از پرداخت</title><style>'
    . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
    . 'background:#0B0E14;color:#E6EAF2;font-family:"Vazirmatn",system-ui,sans-serif;padding:20px}'
    . '.b{max-width:420px;width:100%;background:#141A26;border:1px solid #28324a;border-radius:20px;'
    . 'padding:28px 22px;text-align:center;box-shadow:0 18px 50px rgba(0,0,0,.45)}'
    . '.i{font-size:52px;line-height:1;margin-bottom:14px}'
    . 'h1{font-size:19px;margin:0 0 10px}'
    . 'p{font-size:14px;line-height:1.9;color:#9AA6BA;margin:0 0 8px}'
    . '.r{direction:ltr;font-family:ui-monospace,monospace;font-size:13px;color:#E6EAF2;'
    . 'background:#0F1420;border:1px solid #28324a;border-radius:12px;padding:8px 12px;display:inline-block;margin-top:6px}'
    . '.k{display:inline-block;margin-top:16px;background:rgba(' . $rgb . ',.13);color:' . $col . ';'
    . 'border:1px solid rgba(' . $rgb . ',.35);border-radius:999px;padding:9px 18px;font-size:13px}'
    . '</style></head><body><div class="b">'
    . '<div class="i">' . $icon . '</div>'
    . '<h1>' . h($title) . '</h1>'
    . '<p>' . h($msg) . '</p>';

if ($ok && $ref !== '') {
    echo '<p>کد رهگیری بانکی:</p><div class="r">' . h($ref) . '</div>';
}

echo '<p>می‌توانید به ربات ' . h($brand) . ' برگردید؛ نتیجه همان‌جا هم برایتان ارسال می‌شود.</p>'
    . '<div class="k">می‌توانید این صفحه را ببندید</div>'
    . '</div></body></html>';
