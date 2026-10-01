<?php
/**
 * بررسی یکپارچگی ایستای سورس
 * اجرا: php tools/integrity.php
 * خروجی غیر صفر یعنی متدی صدا زده شده که تعریف نشده است.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$dirs = ['/app', '/cron', '/tools'];
$files = [];
foreach ($dirs as $d) {
    $p = $root . $d;
    if (!is_dir($p)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
            $files[] = $f->getPathname();
        }
    }
}
sort($files);

$problems = 0;
$checked = 0;
$skipped = 0;
foreach ($files as $f) {
    $src = (string)file_get_contents($f);
    $rel = str_replace($root . '/', '', $f);
    if (!preg_match('/\bclass\s+[A-Za-z_][A-Za-z0-9_]*/', $src)) {
        continue;
    }
    if (preg_match('/\bclass\s+[A-Za-z_][A-Za-z0-9_]*\s+extends\s/', $src)) {
        $skipped++;
        continue;
    }
    $checked++;
    preg_match_all('/self::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $src, $m);
    $missing = [];
    foreach (array_unique($m[1]) as $name) {
        if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\(/i', $src)) {
            $missing[] = $name;
        }
    }
    if ($missing) {
        echo '! ' . $rel . ' -> ' . implode(', ', $missing) . PHP_EOL;
        $problems += count($missing);
    }
}
echo '---' . PHP_EOL;
echo 'files checked: ' . $checked . ', skipped(extends): ' . $skipped . ', problems: ' . $problems . PHP_EOL;
exit($problems > 0 ? 1 : 0);
