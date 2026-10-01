<?php
declare(strict_types=1);
/**
 * 0.0.2 #36 — هم‌خوانی database/schema.sql با Migrate::COLUMNS
 * هر ستونی که Migrate می‌شناسد باید در جدول متناظر schema.sql هم باشد تا نصب تازه از همان
 * ابتدا کامل باشد (نمونه: خطای Unknown column 'ssl_verify' هنگام افزودن پنل).
 * اجرا: php tools/schema-check.php     خروجی ۰ = سالم، ۱ = ناهمخوانی
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/Service/Migrate.php';

$sql = (string)@file_get_contents($root . '/database/schema.sql');
if ($sql === '') {
    fwrite(STDERR, "database/schema.sql not found\n");
    exit(1);
}

$tables = [];
if (preg_match_all('/CREATE TABLE IF NOT EXISTS \{p\}(\w+) \((.*?)\n\)/s', $sql, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $m) {
        preg_match_all('/^\s*`(\w+)`/m', $m[2], $cm);
        $tables[strtolower($m[1])] = array_map('strtolower', $cm[1]);
    }
}

$viaMigrate = defined('Migrate::TABLES') ? array_map('strtolower', array_keys(Migrate::TABLES)) : [];
$problems   = [];
foreach (Migrate::COLUMNS as $t => $cols) {
    $t = strtolower((string)$t);
    if (!isset($tables[$t])) {
        if (!in_array($t, $viaMigrate, true)) $problems[] = $t . ': table is neither in schema.sql nor in Migrate::TABLES';
        continue;
    }
    foreach (array_keys($cols) as $c) {
        if (!in_array(strtolower((string)$c), $tables[$t], true)) $problems[] = $t . '.' . $c . ': missing in schema.sql';
    }
}

echo 'schema tables: ' . count($tables) . ', Migrate::COLUMNS tables: ' . count(Migrate::COLUMNS)
    . ', problems: ' . count($problems) . PHP_EOL;
foreach ($problems as $p) echo '  - ' . $p . PHP_EOL;
exit($problems ? 1 : 0);
