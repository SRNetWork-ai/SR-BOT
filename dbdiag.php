<?php
/**
 * ابزار تشخیص دیتابیس و صحت به‌روزرسانی — SR-BOT
 *
 * دسترسی: فقط مدیرِ واردشده به پنل مدیریت؛ یا توکن اختصاصی خودتان در config.php:
 *   'app' => [ ... , 'diag_token' => 'یک-رشته-تصادفی-حداقل-۱۶-کاراکتر' ]
 * سپس:  https://سایت‌شما/dbdiag.php?t=<diag_token>
 * این فایل فقط گزارش می‌دهد و چیزی را تغییر نمی‌دهد (جز یک ردیف آزمایشی که خودش پاک می‌کند).
 * پس از عیب‌یابی حتماً حذفش کنید.
 */

/* ---------- کنترل دسترسی (توکن ثابتِ عمومی حذف شد) ---------- */
$__allowed = (PHP_SAPI === 'cli');

/* ۱) مدیر واردشده به پنل مدیریت */
if (!$__allowed) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        @session_start();
    }
    if (!empty($_SESSION['vs_admin'])) $__allowed = true;
}

/* ۲) توکن اختصاصی تعریف‌شده در config.php (کلید app.diag_token — حداقل ۱۶ کاراکتر) */
if (!$__allowed) {
    $__tok = '';
    $__cf  = __DIR__ . '/config.php';
    if (is_file($__cf)) {
        $__c   = (array)@include $__cf;
        $__app = (isset($__c['app']) && is_array($__c['app'])) ? $__c['app'] : array();
        $__tok = trim((string)(isset($__app['diag_token']) ? $__app['diag_token'] : ''));
    }
    $__got = (string)(isset($_GET['t']) ? $_GET['t'] : '');
    if ($__tok !== '' && strlen($__tok) >= 16 && $__got !== '' && hash_equals($__tok, $__got)) {
        $__allowed = true;
    }
}

if (!$__allowed) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><div style="font:15px system-ui;padding:40px;text-align:center;direction:rtl">'
        . '🔒 دسترسی به این صفحه فقط برای مدیرِ واردشده به پنل مدیریت مجاز است.<br>'
        . '<small style="opacity:.7">یا کلید app.diag_token را در config.php تعریف کنید و صفحه را با ?t=&lt;توکن&gt; باز کنید.</small></div>');
}

@ini_set('display_errors', '1');
@set_time_limit(120);
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');

$ROOT = __DIR__;

function dg_box($t) { echo '<h2>' . $t . '</h2><table>'; }
function dg_end() { echo '</table>'; }
function dg_row($k, $v, $cls = '') {
    echo '<tr><th>' . htmlspecialchars((string)$k) . '</th><td' . ($cls !== '' ? ' class="' . $cls . '"' : '')
        . '><span class="ltr">' . htmlspecialchars((string)$v) . '</span></td></tr>';
}
function dg_err($e) {
    $m = get_class($e) . ': ' . $e->getMessage();
    if ($e instanceof PDOException && is_array($e->errorInfo)) {
        $m .= '  |  errorInfo = ' . implode(' / ', array_map('strval', $e->errorInfo));
    }
    return $m;
}

echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>SR-BOT · تشخیص دیتابیس</title><style>'
    . 'body{background:#0F1115;color:#EAF0FA;font:14px/1.9 Tahoma,Vazirmatn,sans-serif;margin:0;padding:20px}'
    . 'h1{font-size:19px;color:#EAF0FA}h2{font-size:15px;color:#6C8CFF;margin:26px 0 6px}'
    . 'table{border-collapse:collapse;width:100%;max-width:1000px}'
    . 'td,th{border:1px solid #2A3140;padding:7px 10px;text-align:right;vertical-align:top}'
    . 'th{background:#1D222D;width:290px;font-weight:600}td{background:#171B24}'
    . '.ok{color:#34D399}.bad{color:#F87171}.warn{color:#F59E0B}'
    . '.ltr{direction:ltr;display:inline-block;font-family:monospace;font-size:12px;white-space:pre-wrap;word-break:break-all}'
    . 'p{max-width:1000px}</style></head><body><h1>🔍 تشخیص دیتابیس و صحت فایل‌ها</h1>';

/* ---------------------------------------------------------------- محیط */
dg_box('⚙️ محیط اجرا');
dg_row('PHP', PHP_VERSION);
dg_row('درایورهای PDO', implode(', ', PDO::getAvailableDrivers()));
dg_row('mysqlnd', extension_loaded('mysqlnd') ? 'فعال' : 'غیرفعال');
dg_row('memory_limit', (string)ini_get('memory_limit'));
dg_row('max_execution_time', (string)ini_get('max_execution_time'));
dg_row('ZipArchive', class_exists('ZipArchive') ? 'فعال' : 'غیرفعال', class_exists('ZipArchive') ? 'ok' : 'bad');
dg_row('cURL', function_exists('curl_init') ? 'فعال' : 'غیرفعال', function_exists('curl_init') ? 'ok' : 'bad');
dg_end();

/* ---------------------------------------------------------------- اتصال */
$cfgFile = $ROOT . '/config.php';
if (!is_file($cfgFile)) {
    echo '<p class="bad">فایل config.php پیدا نشد. این فایل را در همان پوشهٔ پروژه بگذارید.</p></body></html>';
    exit;
}
$cfg    = (array)require $cfgFile;
$db     = isset($cfg['db']) ? (array)$cfg['db'] : array();
$prefix = isset($db['prefix']) ? (string)$db['prefix'] : '';
$host   = isset($db['host']) ? (string)$db['host'] : 'localhost';
$port   = isset($db['port']) ? (int)$db['port'] : 3306;
$name   = isset($db['name']) ? (string)$db['name'] : '';
$user   = isset($db['user']) ? (string)$db['user'] : '';
$pass   = isset($db['pass']) ? (string)$db['pass'] : '';
$dsn    = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';

dg_box('🔌 اتصال دیتابیس');
dg_row('DSN', $dsn);
dg_row('پیشوند جدول‌ها', $prefix !== '' ? $prefix : '(خالی)');

$native = null;
$emu    = null;
try {
    $native = new PDO($dsn, $user, $pass, array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ));
    dg_row('اتصال با prepare بومی', 'موفق', 'ok');
} catch (Throwable $e) {
    dg_row('اتصال با prepare بومی', dg_err($e), 'bad');
}
try {
    $emu = new PDO($dsn, $user, $pass, array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
    ));
    dg_row('اتصال با prepare شبیه‌سازی‌شده', 'موفق', 'ok');
} catch (Throwable $e) {
    dg_row('اتصال با prepare شبیه‌سازی‌شده', dg_err($e), 'bad');
}
dg_end();

if (!$native && !$emu) {
    echo '<p class="bad">هیچ اتصالی برقرار نشد؛ مقدارهای config.php را بررسی کنید.</p></body></html>';
    exit;
}
$pdo = $native ? $native : $emu;
$tbl = $prefix . 'settings';

/* ------------------------------------------------- متغیرهای سرور */
dg_box('🗄 وضعیت سرور دیتابیس');
$vars = array('version', 'version_comment', 'max_allowed_packet', 'wait_timeout', 'net_read_timeout', 'net_write_timeout', 'sql_mode', 'character_set_server');
foreach ($vars as $v) {
    try {
        $r = $pdo->query("SHOW VARIABLES LIKE '" . $v . "'")->fetch();
        $val = $r ? (string)$r['Value'] : '-';
        if ($v === 'max_allowed_packet') $val .= '  (' . round(((float)$val) / 1048576, 2) . ' MB)';
        dg_row($v, $val);
    } catch (Throwable $e) {
        dg_row($v, dg_err($e), 'bad');
    }
}
dg_end();

/* ------------------------------------------------- جدول تنظیمات */
dg_box('📋 جدول تنظیمات (' . $tbl . ')');
try {
    $cols = $pdo->query('SHOW COLUMNS FROM `' . $tbl . '`')->fetchAll();
    foreach ($cols as $c) {
        $field = (string)$c['Field'];
        $type  = (string)$c['Type'];
        if (strtolower($field) === 'v') {
            $isLong = stripos($type, 'longtext') !== false || stripos($type, 'mediumtext') !== false;
            dg_row('ستون ' . $field, $type . ($isLong ? '' : '   ← باید LONGTEXT شود'), $isLong ? 'ok' : 'bad');
        } else {
            dg_row('ستون ' . $field, $type);
        }
    }
} catch (Throwable $e) {
    dg_row('ساختار جدول', dg_err($e), 'bad');
}
try {
    $rows = $pdo->query('SELECT `k`, LENGTH(`v`) AS n FROM `' . $tbl . '` ORDER BY n DESC LIMIT 8')->fetchAll();
    foreach ($rows as $r) {
        $n = (int)$r['n'];
        dg_row('حجم مقدار «' . (string)$r['k'] . '»', number_format($n) . ' بایت', $n > 60000 ? 'warn' : '');
    }
} catch (Throwable $e) {
    dg_row('بزرگ‌ترین ردیف‌ها', dg_err($e), 'bad');
}
try {
    $mig = $pdo->query('SELECT `v` FROM `' . $tbl . '` WHERE `k` = ' . $pdo->quote('db_migrations'))->fetchColumn();
    dg_row('مهاجرت‌های ثبت‌شده', (string)$mig !== '' ? (string)$mig : '(خالی)');
} catch (Throwable $e) {
    dg_row('مهاجرت‌ها', dg_err($e), 'bad');
}
dg_end();

/* ------------------------------------------------- آزمون نوشتن مقدار بزرگ */
dg_box('🧪 آزمون نوشتن مقدارهای بزرگ در تنظیمات');
$probeKey = '__diag_probe';
$sizes    = array(1000, 60000, 200000, 1000000);
$handles  = array(array('prepare بومی', $native), array('prepare شبیه‌سازی', $emu));
foreach ($handles as $pair) {
    $label = $pair[0];
    $h     = $pair[1];
    if (!$h) { dg_row($label, 'اتصال ندارد', 'bad'); continue; }
    foreach ($sizes as $sz) {
        $val = str_repeat('x', $sz);
        try {
            $st = $h->prepare('INSERT INTO `' . $tbl . '` (`k`, `v`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `v` = :v2');
            $st->execute(array(':k' => $probeKey, ':v' => $val, ':v2' => $val));
            $back = (int)$h->query('SELECT LENGTH(`v`) FROM `' . $tbl . '` WHERE `k` = ' . $h->quote($probeKey))->fetchColumn();
            dg_row($label . ' · ' . number_format($sz) . ' بایت',
                $back === $sz ? 'موفق' : 'نوشته شد ولی طول برگشتی ' . number_format($back) . ' بایت است (بریده شد)',
                $back === $sz ? 'ok' : 'bad');
        } catch (Throwable $e) {
            dg_row($label . ' · ' . number_format($sz) . ' بایت', dg_err($e), 'bad');
        }
    }
}
try {
    $pdo->exec('DELETE FROM `' . $tbl . '` WHERE `k` = ' . $pdo->quote($probeKey));
    dg_row('پاک‌سازی ردیف آزمایشی', 'انجام شد', 'ok');
} catch (Throwable $e) {
    dg_row('پاک‌سازی ردیف آزمایشی', dg_err($e), 'bad');
}
dg_end();

/* ------------------------------------------------- آزمون کوئری‌های خاص */
dg_box('🧪 آزمون دستورهای ویژه (مربوط به بکاپ و مهاجرت)');
$probes = array(
    'SHOW TABLES'             => 'SHOW TABLES',
    'SHOW CREATE TABLE'       => 'SHOW CREATE TABLE `' . $tbl . '`',
    'SET FOREIGN_KEY_CHECKS'  => 'SET FOREIGN_KEY_CHECKS=0',
    'LOCK TABLES'             => 'LOCK TABLES `' . $tbl . '` READ',
    'UNLOCK TABLES'           => 'UNLOCK TABLES',
    'SELECT با پارامت��'      => 'SELECT `k` FROM `' . $tbl . '` WHERE `k` = ? LIMIT 1',
);
foreach ($probes as $pname => $sql) {
    foreach ($handles as $pair) {
        $label = $pair[0];
        $h     = $pair[1];
        if (!$h) continue;
        try {
            $st = $h->prepare($sql);
            if (strpos($sql, '?') !== false) $st->execute(array('x'));
            else $st->execute();
            $st->closeCursor();
            dg_row($pname . '  (' . $label . ')', 'موفق', 'ok');
        } catch (Throwable $e) {
            dg_row($pname . '  (' . $label . ')', dg_err($e), 'bad');
        }
    }
}
dg_end();

/* ------------------------------------------------- ستون‌های حساس */
dg_box('🧩 ستون‌های مورد نیاز');
$need = array(
    $prefix . 'users'    => array('tg_id', 'first_name', 'username', 'balance'),
    $prefix . 'services' => array('used_bytes', 'group_key', 'deleted_at', 'renew_count'),
    $prefix . 'panels'   => array('sub_base', 'node_host', 'renew_mode'),
);
foreach ($need as $t => $fields) {
    try {
        $have = array();
        foreach ((array)$pdo->query('SHOW COLUMNS FROM `' . $t . '`')->fetchAll() as $c) $have[] = (string)$c['Field'];
        $missing = array();
        foreach ($fields as $f) if (!in_array($f, $have, true)) $missing[] = $f;
        dg_row($t, $missing ? 'ستون کم: ' . implode(', ', $missing) : 'کامل است (' . count($have) . ' ستون)', $missing ? 'bad' : 'ok');
    } catch (Throwable $e) {
        dg_row($t, dg_err($e), 'bad');
    }
}
dg_end();

/* ------------------------------------------------- صحت فایل‌ها */
dg_box('📦 صحت فایل‌های به‌روزرسانی');
$marks = array(
    'app/DB.php'                                  => 'retry-emulate',
    'app/Service/Updater.php'                     => 'pre-backup failed',
    'admin/index.php'                             => 'get_class($e)',
    'admin/pages/botbuttons.php'                  => 'btTabs',
    'admin/pages/subs.php'                        => 'opt_add',
    'sub.php'                                     => 'appsCard',
    'miniapp/reseller.php'                        => 'id="scroller"',
    'miniapp/api.php'                             => 'svc_renew',
    'database/migrations/0016_settings_longtext.sql' => 'LONGTEXT',
);
foreach ($marks as $rel => $mark) {
    $p = $ROOT . '/' . $rel;
    if (!is_file($p)) { dg_row($rel, 'فایل وجود ندارد ← آپدیت نشده', 'bad'); continue; }
    $body = (string)@file_get_contents($p);
    $ok   = strpos($body, $mark) !== false;
    dg_row($rel, ($ok ? 'به‌روز است' : 'قدیمی است (نشانهٔ «' . $mark . '» نیست)')
        . '  ·  ' . number_format((float)@filesize($p)) . ' بایت  ·  ' . date('Y-m-d H:i', (int)@filemtime($p)), $ok ? 'ok' : 'bad');
}
$vj = @file_get_contents($ROOT . '/version.json');
if ($vj !== false) {
    $vd = (array)json_decode((string)$vj, true);
    dg_row('version.json', (string)(isset($vd['version']) ? $vd['version'] : '?')
        . '  ·  ' . count((array)(isset($vd['changelog']) ? $vd['changelog'] : array())) . ' مورد تغییرات');
}
dg_end();

/* ------------------------------------------------- دسترسی نوشتن */
dg_box('✍️ دسترسی نوشتن (علت رایج آپدیت ناقص)');
$paths = array('.', 'app', 'app/Service', 'admin', 'admin/pages', 'miniapp', 'database/migrations', 'storage');
foreach ($paths as $d) {
    $p = $ROOT . '/' . $d;
    $ok = is_dir($p) && is_writable($p);
    dg_row('پوشه ' . $d, is_dir($p) ? ($ok ? 'قابل نوشتن' : 'فقط خواندنی ← آپدیت نمی‌شود') : 'موجود نیست', $ok ? 'ok' : 'bad');
}
$files = array('admin/pages/botbuttons.php', 'app/DB.php', 'app/Service/Updater.php', 'sub.php', 'miniapp/reseller.php', 'version.json');
foreach ($files as $f) {
    $p = $ROOT . '/' . $f;
    $ok = is_file($p) && is_writable($p);
    dg_row('فایل ' . $f, is_file($p) ? ($ok ? 'قابل نوشتن' : 'فقط خواندنی ← آپدیت نمی‌شود') : 'موجود نیست', $ok ? 'ok' : 'bad');
}
$free = @disk_free_space($ROOT);
if ($free !== false) dg_row('فضای آزاد دیسک', round(((float)$free) / 1048576, 1) . ' MB', ((float)$free < 20971520) ? 'bad' : 'ok');
dg_end();

/* ------------------------------------------------- لاگ‌ها */
dg_box('📝 آخرین خطاهای ثبت‌شده');
$logDir = $ROOT . '/storage/logs';
$found  = false;
if (is_dir($logDir)) {
    $list = (array)@scandir($logDir);
    rsort($list);
    foreach ($list as $lf) {
        if (substr($lf, -4) !== '.log') continue;
        $lp   = $logDir . '/' . $lf;
        $body = (string)@file_get_contents($lp);
        $ln   = array_slice(array_filter(explode("\n", $body)), -6);
        if ($ln) { dg_row($lf, implode("\n", $ln)); $found = true; }
        if ($found && count($ln) > 0) break;
    }
}
if (!$found) dg_row('storage/logs', 'لاگی پیدا نشد');
dg_end();

echo '<p class="warn">✅ گزارش تمام شد. این صفحه را کامل اسکرین‌شات بگیرید و بفرستید؛ سپس فایل dbdiag.php را حذف کنید.</p></body></html>';
