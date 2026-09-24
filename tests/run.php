<?php
declare(strict_types=1);

/**
 * اجرای تست‌ها بدون هیچ وابستگی:   php tests/run.php
 * (نسخهٔ کامل با PHPUnit:            composer test)
 */

require_once __DIR__ . '/bootstrap.php';

$pass = 0;
$fail = 0;

$check = function (string $name, $got, $want) use (&$pass, &$fail): void {
    if ($got === $want) {
        $pass++;
        echo 'ok   - ' . $name . PHP_EOL;
        return;
    }
    $fail++;
    echo 'FAIL - ' . $name . PHP_EOL;
    echo '       got:  ' . var_export($got, true) . PHP_EOL;
    echo '       want: ' . var_export($want, true) . PHP_EOL;
};

$truthy = function (string $name, bool $cond) use (&$pass, &$fail): void {
    if ($cond) {
        $pass++;
        echo 'ok   - ' . $name . PHP_EOL;
        return;
    }
    $fail++;
    echo 'FAIL - ' . $name . PHP_EOL;
};

$check('h()', h('<b>'), '&lt;b&gt;');
$check('jenc()', jenc(['a' => 1]), '{"a":1}');
$check('jdec()', jdec('{"a":1}'), ['a' => 1]);
$check('jdec() fallback', jdec('x', ['d']), ['d']);
$check('en_num()', en_num('۱۲۳۴'), '1234');
$check('money()', money(1234567, false), '1,234,567');
$check('gb2bytes()', gb2bytes(1), 1073741824);
$check('bytes2gb()', bytes2gb(536870912), 0.5);
$check('human_bytes()', human_bytes(1073741824), '1 GB');
$check('clean_text()', clean_text("  salam\x00  "), 'salam');

$truthy('rnd() length', strlen(rnd(16)) === 16);
$truthy('rnd() charset', (bool)preg_match('/^[a-z0-9]{16}$/', rnd(16)));
$truthy('uuidv4()', (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', uuidv4()));
$truthy('to_jalali()', (bool)preg_match('/^\d{4}\/\d{2}\/\d{2}$/', en_num(to_jalali('2026-03-21'))));
$truthy('app_encrypt/app_decrypt', app_decrypt(app_encrypt('plain')) === 'plain');
$truthy('str_split_unicode_safe()', count(str_split_unicode_safe(str_repeat('a', 100), 30)) > 1);

echo PHP_EOL . $pass . ' passed, ' . $fail . ' failed' . PHP_EOL;
exit($fail > 0 ? 1 : 0);
