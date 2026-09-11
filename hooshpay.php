<?php
declare(strict_types=1);

/**
 * دریافت کال‌بک پرداخت موفق از هوش‌پی (HooshPay)
 *
 * این آدرس را در تنظیمات درگاه هوش‌پی به عنوان callback_url بگذارید:
 *   https://دامنه-شما/hooshpay.php
 *
 * همین فایل با ?done=1 صفحهٔ بازگشت کاربر پس از پرداخت را هم نشان می‌دهد.
 */

require __DIR__ . '/app/bootstrap.php';

if (!app_installed()) {
    http_response_code(503);
    echo 'not installed';
    exit;
}

boot();

/* ---------------- صفحهٔ بازگشت کاربر ---------------- */
if (isset($_GET['done'])) {
    header('Content-Type: text/html; charset=utf-8');
    $brand = defined('APP_BRAND') ? (string)APP_BRAND : 'فروشگاه';
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
        . '.k{display:inline-block;margin-top:16px;background:rgba(47,212,143,.13);color:#2FD48F;'
        . 'border:1px solid rgba(47,212,143,.35);border-radius:999px;padding:9px 18px;font-size:13px}'
        . '</style></head><body><div class="b">'
        . '<div class="i">🪙</div>'
        . '<h1>پرداخت شما ثبت شد</h1>'
        . '<p>نتیجهٔ نهایی به‌صورت خودکار بررسی می‌شود و به‌محض تایید، کیف پول شما شارژ خواهد شد.</p>'
        . '<p>می‌توانید به ربات ' . h($brand) . ' برگردید؛ پیام تایید همان‌جا برایتان ارسال می‌شود.</p>'
        . '<div class="k">می‌توانید این صفحه را ببندید</div>'
        . '</div></body></html>';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

if (!class_exists('HooshPay')) {
    http_response_code(500);
    echo 'hooshpay class missing';
    exit;
}

$raw = (string)file_get_contents('php://input');
$sig = (string)($_SERVER['HTTP_X_HOOSHPAY_SIGNATURE'] ?? '');

/* درخواست خالی (بازدید مرورگر) — فقط وضعیت را نشان می‌دهیم */
if (trim($raw) === '') {
    echo HooshPay::enabled() ? 'hooshpay callback ready' : 'hooshpay disabled';
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo 'bad json';
    app_log('hooshpay', 'bad json callback', ['len' => strlen($raw)]);
    exit;
}

/* بررسی امضای HMAC-SHA256 (اگر Secret ثبت شده باشد) */
$trusted = HooshPay::checkSig($data, $sig);

if (!$trusted && HooshPay::secret() !== '') {
    app_log('hooshpay', 'signature mismatch', [
        'order'   => (string)($data['order_id'] ?? ''),
        'invoice' => (string)($data['invoice'] ?? ''),
    ]);
    try {
        Logs::send('errors', Logs::fmt('⛔ امضای کال‌بک هوش‌پی نامعتبر بود', [
            'فاکتور' => (string)($data['invoice'] ?? '-'),
            'سفارش'  => (string)($data['order_id'] ?? '-'),
            'راهنما' => 'کلید Secret را در پنل مدیریت با هوش‌پی یکسان کنید',
        ]));
    } catch (Throwable $e) {
        /* لاگ نباید مانع پردازش شود */
    }
}

/*
 * حتی وقتی امضا معتبر نباشد، پرداخت را دور نمی‌ریزیم؛
 * چون apply() خودش مستقیماً از هوش‌پی استعلام می‌گیرد و تنها
 * در صورت paid=true کیف پول را شارژ می‌کند.
 */
$res = HooshPay::apply($data, $trusted);

app_log('hooshpay', 'callback processed', [
    'order'   => (string)($data['order_id'] ?? ''),
    'invoice' => (string)($data['invoice'] ?? ''),
    'status'  => (string)($data['status'] ?? ''),
    'trusted' => $trusted ? 1 : 0,
    'result'  => (string)($res['message'] ?? ''),
]);

if (!empty($res['ok'])) {
    echo 'OK';
    exit;
}

/* خطا → هوش‌پی چند بار دیگر تلاش می‌کند */
http_response_code(500);
echo 'ERR ' . (string)($res['message'] ?? 'unknown');
