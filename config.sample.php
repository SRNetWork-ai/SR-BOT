<?php
// نمونه فایل تنظیمات - نصاب وب یا install.sh این فایل را به صورت config.php می‌سازد
return [
    'installed' => true,
    'db' => [
        'host'   => 'localhost',
        'port'   => 3306,
        'name'   => 'vpnshop',
        'user'   => 'vpnshop',
        'pass'   => '',
        'prefix' => 'vs_',
    ],
    'bot' => [
        'token'    => '123456:ABC-TOKEN',
        'username' => 'MyShopBot',
        'secret'   => 'random-webhook-secret',
        'admins'   => [123456789],
    ],
    'app' => [
        'url'      => 'https://example.com',
        'timezone' => 'Asia/Tehran',
        'currency' => 'تومان',

        // کلید رمزنگاری داده‌های حساس (مانند رمز پنل‌ها).
        // ۶۴ کاراکتر تصادفی با دستور زیر بسازید و پس از اولین ذخیره هرگز تغییرش ندهید:
        //   php -r "echo bin2hex(random_bytes(32));"
        'key'      => '',

        // بررسی گواهی SSL در تماس با تلگرام و سرویس‌های بیرونی.
        // فقط اگر سرور شما CA به‌روز ندارد موقتاً false کنید.
        'ssl_verify' => true,
    ],
];
