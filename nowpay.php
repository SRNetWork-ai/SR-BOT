<?php
declare(strict_types=1);

/**
 * دریافت اعلان پرداخت (IPN) از NowPayments
 * این ادرس را در تنظیمات درگاه وارد کنید:
 *   https://دامنه-شما/nowpay.php
 */

require __DIR__ . '/app/bootstrap.php';

if (!app_installed()) {
    http_response_code(503);
    echo 'not installed';
    exit;
}

boot();
header('Content-Type: text/plain; charset=utf-8');

$raw = (string)file_get_contents('php://input');
$sig = (string)($_SERVER['HTTP_X_NOWPAYMENTS_SIG'] ?? '');

// درخواست خالی (مانند بازدید مرورگر) – فقط وضعیت را نشان می‌دهیم
if (trim($raw) === '') {
    echo NowPay::enabled() ? 'nowpayments callback ready' : 'nowpayments disabled';
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo 'bad json';
    app_log('nowpay', 'bad json ipn', ['len' => strlen($raw)]);
    exit;
}

// بررسی امضای HMAC-SHA512
if (!NowPay::verify($data, $sig)) {
    http_response_code(403);
    echo 'invalid signature';
    app_log('nowpay', 'invalid signature', [
        'order'  => (string)($data['order_id'] ?? ''),
        'status' => (string)($data['payment_status'] ?? ''),
    ]);
    Logs::send('errors', Logs::fmt('⛔ امضای IPN نامعتبر بود', [
        'فاکتور' => (string)($data['order_id'] ?? '-'),
        'وضعیت' => (string)($data['payment_status'] ?? '-'),
        'راهنما' => 'کلید IPN Secret را در پنل مدیریت با درگاه یکسان کنید',
    ]));
    exit;
}

$res = NowPay::apply($data);

app_log('nowpay', 'ipn processed', [
    'order'  => (string)($data['order_id'] ?? ''),
    'status' => (string)($data['payment_status'] ?? ''),
    'result' => (string)($res['message'] ?? ''),
]);

http_response_code(200);
echo !empty($res['ok']) ? 'ok' : 'error';
