<?php
declare(strict_types=1);

/* 0.0.2 #13: قفل نصاب پس از نصب — نصب دوباره فقط با باز کردن دستی قفل */
(static function (): void {
    $root   = dirname(__DIR__);
    $cfg    = $root . '/config.php';
    $unlock = $root . '/storage/tmp/install.unlock';
    if (!is_file($cfg) || is_file($unlock)) return;
    /* در جریان یک نصب تازه، config.php همین چند دقیقه پیش ساخته شده است */
    if (time() - (int)@filemtime($cfg) < 1800) return;
    http_response_code(403);
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>نصب قفل است</title>'
        . '<div style="font:15px/2.1 Tahoma,sans-serif;direction:rtl;max-width:640px;margin:60px auto;padding:24px;border:1px solid #ddd;border-radius:14px">'
        . '<h2 style="margin:0 0 10px">🔒 نصاب قفل شده است</h2>'
        . '<p>این برنامه قبلاً نصب شده است. برای جلوگیری از نصب دوبارهٔ مخرب، پوشهٔ نصب بسته شد.</p>'
        . '<p>اگر واقعاً می‌خواهید نصاب را دوباره اجرا کنید:</p>'
        . '<ol><li>پوشهٔ <code>install/</code> را از سرور حذف کنید (امن‌ترین کار)</li>'
        . '<li>یا فایل خالی <code>storage/tmp/install.unlock</code> را بسازید و بعد از کار حذفش کنید</li></ol>'
        . '</div>';
    exit;
})();

/**
 * نصاب وب فروشگاه کانفیگ (مخصوص هاست)
 */

require dirname(__DIR__) . '/app/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) Session::start();

$lock = APP_ROOT . '/storage/installed.lock';
$alreadyInstalled = app_installed() && is_file($lock);

/* حذف خودکار نصاب: وقتی نصب کامل شده باشد، این پوشه پس از نمایش همین صفحه خودش را پاک می‌کند */
if ($alreadyInstalled) {
    register_shutdown_function(static function (): void {
        $rrm = function (string $dir) use (&$rrm): void {
            $ls = @scandir($dir);
            if (!is_array($ls)) return;
            foreach ($ls as $f) {
                if ($f === '.' || $f === '..') continue;
                $p = $dir . '/' . $f;
                if (is_dir($p) && !is_link($p)) { $rrm($p); } else { @unlink($p); }
            }
            @rmdir($dir);
        };
        $rrm(__DIR__);
    });
}

$step   = (int)($_GET['step'] ?? 1);
if ($step < 1 || $step > 5) $step = 1;
$errors = [];
$notice = null;
$D      = $_SESSION['vs_install'] ?? [];

function detect_url(): string
{
    /* تشخیص یکپارچهٔ آدرس — با هر نام پوشه‌ای کار می‌کند */
    if (function_exists('app_detect_base_url')) {
        $u = rtrim((string)app_detect_base_url(), '/');
        if ($u !== '') return $u;
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host  = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir   = str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'))));
    $dir   = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');

    /* کدگذاری بخش‌های مسیر تا نام پوشهٔ فارسی یا دارای فاصله هم معتبر بماند */
    if ($dir !== '') {
        $segs = [];
        foreach (explode('/', trim($dir, '/')) as $s) {
            if ($s === '') continue;
            $segs[] = rawurlencode(rawurldecode($s));
        }
        $dir = $segs ? '/' . implode('/', $segs) : '';
    }

    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

function requirements(): array
{
    $storage = APP_ROOT . '/storage';
    if (!is_dir($storage)) @mkdir($storage, 0775, true);
    return [
        ['name' => 'نسخه PHP (حداقل ۸.۰)', 'ok' => version_compare(PHP_VERSION, '8.0.0', '>='), 'value' => PHP_VERSION],
        ['name' => 'افزونه PDO MySQL',        'ok' => extension_loaded('pdo_mysql'), 'value' => extension_loaded('pdo_mysql') ? 'فعال' : 'غیرفعال'],
        ['name' => 'افزونه cURL',             'ok' => extension_loaded('curl'), 'value' => extension_loaded('curl') ? 'فعال' : 'غیرفعال'],
        ['name' => 'افزونه JSON',             'ok' => extension_loaded('json'), 'value' => extension_loaded('json') ? 'فعال' : 'غیرفعال'],
        ['name' => 'افزونه mbstring',         'ok' => extension_loaded('mbstring'), 'value' => extension_loaded('mbstring') ? 'فعال' : 'غیرفعال'],
        ['name' => 'افزونه OpenSSL',          'ok' => extension_loaded('openssl'), 'value' => extension_loaded('openssl') ? 'فعال' : 'غیرفعال'],
        ['name' => 'قابلیت نوشتن پوشه اصلی', 'ok' => is_writable(APP_ROOT), 'value' => is_writable(APP_ROOT) ? 'قابل نوشتن' : 'بدون دسترسی'],
        ['name' => 'قابلیت نوشتن پوشه storage', 'ok' => is_dir($storage) && is_writable($storage), 'value' => (is_dir($storage) && is_writable($storage)) ? 'قابل نوشتن' : 'بدون دسترسی'],
    ];
}

/* ==================== پردازش فرم‌ها ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyInstalled) {
    $post = $_POST;
    $doing = (int)($post['step'] ?? 1);

    if ($doing === 2) {
        $db = [
            'host'   => trim((string)($post['db_host'] ?? 'localhost')),
            'port'   => (int)($post['db_port'] ?? 3306),
            'name'   => trim((string)($post['db_name'] ?? '')),
            'user'   => trim((string)($post['db_user'] ?? '')),
            'pass'   => (string)($post['db_pass'] ?? ''),
            'prefix' => trim((string)($post['db_prefix'] ?? 'vs_')),
        ];
        if ($db['name'] === '' || $db['user'] === '') $errors[] = 'نام دیتابیس و نام کاربری الزامی است.';
        if (!$errors) {
            try {
                $dsn = 'mysql:host=' . $db['host'] . ';port=' . $db['port'] . ';dbname=' . $db['name'] . ';charset=utf8mb4';
                new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
                $D['db'] = $db;
                $_SESSION['vs_install'] = $D;
                header('Location: index.php?step=3');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage();
            }
        }
        $step = 2;
    }

    if ($doing === 3) {
        $token  = trim((string)($post['bot_token'] ?? ''));
        $admins = array_values(array_filter(array_map(
            fn($x) => preg_replace('/\\D/', '', en_num(trim($x))),
            explode(',', (string)($post['admins'] ?? ''))
        )));
        $url = rtrim(trim((string)($post['app_url'] ?? '')), '/');
        if ($token === '' || !str_contains($token, ':')) $errors[] = 'توکن ربات معتبر نیست.';
        if (!$admins) $errors[] = 'حداقل یک آیدی عددی مدیر را وارد کنید.';
        if ($url === '' || !preg_match('#^https?://#', $url)) $errors[] = 'آدرس سایت معتبر نیست.';
        if (!$errors) {
            Tg::setToken($token);
            $me = Tg::getMe();
            if (empty($me['ok'])) {
                $errors[] = 'توکن توسط تلگرام تایید نشد. در صورت فیلتر بودن سرور، می‌توانید با زدن دوباره دکمه ادامه را، این مرحله را رد کنید.';
                $D['token_unverified'] = ($D['token_unverified'] ?? 0) + 1;
            }
            if (!empty($me['ok']) || (int)($D['token_unverified'] ?? 0) > 1) {
                $D['bot'] = [
                    'token'    => $token,
                    'username' => (string)($me['result']['username'] ?? ''),
                    'admins'   => $admins,
                    'secret'   => bin2hex(random_bytes(16)),
                ];
                $D['app_url'] = $url;
                $_SESSION['vs_install'] = $D;
                header('Location: index.php?step=4');
                exit;
            }
            $_SESSION['vs_install'] = $D;
        }
        $step = 3;
    }

    if ($doing === 4) {
        $user = trim((string)($post['admin_user'] ?? ''));
        $pass = (string)($post['admin_pass'] ?? '');
        $pass2 = (string)($post['admin_pass2'] ?? '');
        if (strlen($user) < 3) $errors[] = 'نام کاربری مدیر حداقل ۳ کاراکتر باشد.';
        if (strlen($pass) < 6) $errors[] = 'رمز عبور حداقل ۶ کاراکتر باشد.';
        if ($pass !== $pass2) $errors[] = 'تکرار رمز عبور مطابقت ندارد.';
        if (!$errors) {
            $D['admin'] = ['user' => $user, 'pass' => $pass];
            $D['shop_title'] = trim((string)($post['shop_title'] ?? 'فروشگاه کانفیگ'));
            $_SESSION['vs_install'] = $D;
            header('Location: index.php?step=5');
            exit;
        }
        $step = 4;
    }

    if ($doing === 5) {
        if (empty($D['db']) || empty($D['bot']) || empty($D['admin'])) {
            $errors[] = 'اطلاعات نصب کامل نیست. لطفاً مراحل را از ابتدا طی کنید.';
        } else {
            try {
                $config = [
                    'installed' => true,
                    'db'  => $D['db'],
                    'bot' => $D['bot'],
                    'app' => [
                        'url'        => $D['app_url'],
                        'timezone'   => 'Asia/Tehran',
                        'currency'   => 'تومان',
                        // کلید رمزنگاری داده‌های حساس — پس از نصب هرگز تغییرش ندهید
                        'key'        => bin2hex(random_bytes(32)),
                        'ssl_verify' => true,
                    ],
                ];
                $code = "<?php\n// این فایل توسط نصاب ساخته شده است\nreturn " . var_export($config, true) . ";\n";
                if (@file_put_contents(APP_ROOT . '/config.php', $code) === false) {
                    throw new RuntimeException('فایل config.php قابل نوشتن نیست. دسترسی پوشه را به ۷۵۵ تغییر دهید.');
                }

                DB::init($D['db']);
                DB::runSqlFile(APP_ROOT . '/database/schema.sql');
                /* اطمینان از ساخته شدن جدول‌ها */
                $needTables = ['settings', 'admins', 'users', 'panels', 'products', 'services',
                                'orders', 'transactions', 'tickets', 'ticket_messages', 'tutorials', 'logs'];
                $havePre    = DB::prefix();
                $haveRows   = DB::all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()');
                $haveNames  = [];
                foreach ($haveRows as $hr) $haveNames[] = strtolower((string)($hr['t'] ?? ''));
                $missTables = [];
                foreach ($needTables as $nt) {
                    if (!in_array(strtolower($havePre . $nt), $haveNames, true)) $missTables[] = $havePre . $nt;
                }
                if ($missTables) {
                    throw new RuntimeException('جدول‌های زیر ساخته نشدند: ' . implode('، ', $missTables)
                        . ' — فایل database/schema.sql را کامل آپلود کنید یا همان فایل را در phpMyAdmin اجرا کنید.');
                }

                $exists = DB::one('SELECT id FROM {p}admins WHERE username = :u', [':u' => $D['admin']['user']]);
                if ($exists) {
                    DB::update('admins', ['password_hash' => password_hash($D['admin']['pass'], PASSWORD_DEFAULT)],
                        'id = :id', [':id' => (int)$exists['id']]);
                } else {
                    DB::insert('admins', [
                        'username'      => $D['admin']['user'],
                        'password_hash' => password_hash($D['admin']['pass'], PASSWORD_DEFAULT),
                        'name'          => 'مدیر اصلی',
                        'role'          => 'owner',
                        'created_at'    => now(),
                    ]);
                }

                DB::loadSettings(true);
                if (!empty($D['shop_title'])) DB::setSetting('shop_title', $D['shop_title']);

                Tg::setToken($D['bot']['token']);
                $hook = Tg::setWebhook(rtrim($D['app_url'], '/') . '/index.php', $D['bot']['secret']);
                $D['webhook_ok'] = !empty($hook['ok']);
                $D['webhook_msg'] = (string)($hook['description'] ?? ($hook['error'] ?? ''));

                if (!is_dir(APP_ROOT . '/storage')) @mkdir(APP_ROOT . '/storage', 0775, true);
                @file_put_contents($lock, now());

                foreach ($D['bot']['admins'] as $aid) {
                    Tg::send($aid, "✅ <b>نصب ربات با موفقیت انجام شد</b>\nبرای شروع دستور /start را بزنید.");
                }

                $_SESSION['vs_install'] = $D;
                $_SESSION['vs_done'] = true;
                header('Location: index.php?step=5&done=1');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'خطا در نصب: ' . $e->getMessage();
            }
        }
        $step = 5;
    }
}

$done  = isset($_GET['done']) && !empty($_SESSION['vs_done']);

/* ---------- تلاش دوباره برای تنظیم وب‌هوک (بدون نصب مجدد) ---------- */
if (isset($_GET['rehook'])) {
    try {
        cfg(null, null, true);

        $base = rtrim((string)cfg('app.url', ''), '/');
        $live = rtrim((string)detect_url(), '/');
        if ($live !== '') $base = $live;

        if ($base === '' || (string)cfg('bot.token', '') === '') {
            throw new RuntimeException('اطلاعات نصب کامل نیست؛ مراحل نصب را کامل کنید.');
        }

        /* اگر آدرس واقعی با آدرس ذخیره‌شده فرق دارد، اول اصلاحش کن */
        if ($base !== rtrim((string)cfg('app.url', ''), '/') && class_exists('Cfg')) {
            Cfg::set(['app.url' => $base]);
            cfg(null, null, true);
        }

        Tg::setToken((string)cfg('bot.token', ''));
        $h = Tg::setWebhook($base . '/index.php', (string)cfg('bot.secret', ''));

        $D['app_url']     = $base;
        $D['webhook_ok']  = !empty($h['ok']);
        $D['webhook_msg'] = (string)($h['description'] ?? ($h['error'] ?? ''));
        $_SESSION['vs_install'] = $D;
    } catch (Throwable $e) {
        $D['webhook_ok']  = false;
        $D['webhook_msg'] = $e->getMessage();
    }
}

/* ---------- حذف خودکار پوشهٔ نصب پس از پایان نصب ---------- */
$autoDel = false;
if ($done) {
    $wantDel = true;
    try {
        $wantDel = (string)DB::setting('install_autodelete', '1') === '1';
    } catch (Throwable $e) {
        $wantDel = true;
    }

    if ($wantDel || isset($_GET['wipe'])) {
        $autoDel = true;
        // پس از ارسال کامل صفحه، پوشهٔ install حذف می‌شود
        register_shutdown_function(static function (): void {
            $dir = __DIR__;
            if (basename($dir) !== 'install') return;

            foreach ((array)@scandir($dir) as $it) {
                if ($it === '.' || $it === '..' || $it === '') continue;
                $p = $dir . '/' . $it;
                if (is_dir($p)) {
                    foreach ((array)@scandir($p) as $sub) {
                        if ($sub === '.' || $sub === '..' || $sub === '') continue;
                        @unlink($p . '/' . $sub);
                    }
                    @rmdir($p);
                } else {
                    @unlink($p);
                }
            }
            @rmdir($dir);
        });
    }
}
$reqs  = requirements();
$reqOk = !in_array(false, array_column($reqs, 'ok'), true);
$titles = [1 => 'پیش‌نیازها', 2 => 'دیتابیس', 3 => 'ربات تلگرام', 4 => 'مدیر پنل', 5 => 'پایان نصب'];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>نصب فروشگاه کانفیگ</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/app.css?v=<?= @filemtime(dirname(__DIR__) . '/assets/app.css') ?: '2' ?>">
</head>
<body>
<div class="center-wrap">
<div class="installer">

  <div class="brand">
    <div class="logo">🛡️</div>
    <div>
      <h1>نصب فروشگاه کانفیگ</h1>
      <div class="muted">ربات فروش و مدیریت سرویس – نسخه <?= h(APP_VERSION) ?></div>
    </div>
  </div>

<?php if ($alreadyInstalled && !$done): ?>
  <div class="card">
    <div class="alert a-ok">✅ این نسخه قبلاً نصب شده است.</div>
    <p class="muted">برای نصب مجدد، فایل <code class="mono">storage/installed.lock</code> و <code class="mono">config.php</code> را حذف کنید.</p>
    <div class="row mt4">
      <a class="btn btn-primary" href="../admin/index.php">🌐 ورود به پنل مدیریت</a>
      <a class="btn" href="../index.php">صفحه ربات</a>
    </div>
  </div>
<?php else: ?>

  <div class="ins-top">
    <div class="ins-progress" role="progressbar"><div class="ins-bar" style="width:<?= (int)round(($step - 1) / 4 * 100) ?>%"></div></div>
    <div class="ins-meta">
      <span class="muted"><?= fa_num((int)round(($step - 1) / 4 * 100)) ?>٪ تکمیل شده · م��حله <?= fa_num($step) ?> از ۵</span>
      <button class="icon-btn" type="button" id="insTheme" title="تغییر تم روشن/تیره">🌗</button>
    </div>
  </div>

  <div class="steps">
    <?php foreach ($titles as $n => $t): ?>
      <div class="step <?= $n === $step ? 'active' : ($n < $step ? 'done' : '') ?>">
        <span class="n"><?= $n < $step ? '✓' : fa_num($n) ?></span><?= h($t) ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
  <?php foreach ($errors as $e): ?>
    <div class="alert a-err">⚠️ <?= h($e) ?></div>
  <?php endforeach; ?>

  <?php if ($step === 1): ?>
    <div class="card-head">
      <div><div class="card-title">🧾 بررسی پیش‌نیازها</div>
      <div class="card-sub">موارد زیر باید روی هاست شما فعال باشند.</div></div>
    </div>
    <div class="row" style="flex-wrap:wrap;gap:8px;margin-bottom:12px">
      <span class="badge <?= $reqOk ? 'b-green' : 'b-orange' ?>"><?= fa_num(count(array_filter(array_column($reqs, 'ok')))) ?> از <?= fa_num(count($reqs)) ?> پیش‌نیاز آماده است</span>
      <span class="badge b-blue">PHP <?= h(PHP_VERSION) ?></span>
      <span class="badge b-purple">نسخه <?= h(APP_VERSION) ?></span>
    </div>
    <div class="req-list">
      <?php foreach ($reqs as $r): ?>
        <div class="req">
          <span class="name"><?= $r['ok'] ? '✅' : '❌' ?> <?= h($r['name']) ?></span>
          <span class="badge <?= $r['ok'] ? 'b-green' : 'b-red' ?>"><?= h($r['value']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (!$reqOk): ?>
      <div class="alert a-warn mt4">برخی پیش‌نیازها فراهم نیست. پس از رفع مشکل، صفحه را دوباره بارگزاری کنید.</div>
    <?php endif; ?>
    <div class="row-between mt5">
      <a class="btn btn-ghost" href="index.php?step=1">🔄 بررسی مجدد</a>
      <a class="btn btn-primary" href="index.php?step=2" <?= $reqOk ? '' : 'style="opacity:.6"' ?>>ادامه ←</a>
    </div>

  <?php elseif ($step === 2): ?>
    <div class="card-head">
      <div><div class="card-title">🗄️ اطلاعات دیتابیس</div>
      <div class="card-sub">دیتابیس MySQL یا MariaDB را از پنل هاست خود بسازید و اطلاعات را اینجا وارد کنید.</div></div>
    </div>
    <form method="post">
      <input type="hidden" name="step" value="2">
      <div class="split">
        <div class="field"><label>میزبان (Host)</label>
          <input type="text" name="db_host" value="<?= h($D['db']['host'] ?? 'localhost') ?>" placeholder="localhost"></div>
        <div class="field"><label>پورت</label>
          <input type="number" name="db_port" value="<?= h((string)($D['db']['port'] ?? 3306)) ?>"></div>
      </div>
      <div class="field"><label>نام دیتابیس</label>
        <input type="text" name="db_name" value="<?= h($D['db']['name'] ?? '') ?>" required></div>
      <div class="split">
        <div class="field"><label>نام کاربری دیتابیس</label>
          <input type="text" name="db_user" value="<?= h($D['db']['user'] ?? '') ?>" required></div>
        <div class="field"><label>رمز دیتابیس</label>
          <input type="password" name="db_pass" id="dbp" value="<?= h($D['db']['pass'] ?? '') ?>"></div>
      </div>
      <div class="field"><label>پیشوند جداول</label>
        <input type="text" name="db_prefix" value="<?= h($D['db']['prefix'] ?? 'vs_') ?>">
        <div class="hint">اگر در یک دیتابیس چند برنامه دارید، پیشوند را یکتا بگذارید.</div></div>
      <div class="row-between mt4">
        <a class="btn btn-ghost" href="index.php?step=1">→ قبلی</a>
        <button class="btn btn-primary" type="submit">بررسی اتصال و ادامه ←</button>
      </div>
    </form>

  <?php elseif ($step === 3): ?>
    <div class="card-head">
      <div><div class="card-title">🤖 اتصال ربات تلگرام</div>
      <div class="card-sub">توکن را از <span class="mono">@BotFather</span> بگیرید و آیدی عددی خود را از <span class="mono">@userinfobot</span>.</div></div>
    </div>
    <form method="post">
      <input type="hidden" name="step" value="3">
      <div class="field"><label>توکن ربات</label>
        <input class="mono" type="text" name="bot_token" value="<?= h($D['bot']['token'] ?? '') ?>" placeholder="123456:ABC-DEF..." required></div>
      <div class="field"><label>آیدی عددی مدیران</label>
        <input class="mono" type="text" name="admins" value="<?= h(implode(',', $D['bot']['admins'] ?? [])) ?>" placeholder="123456789,987654321" required>
        <div class="hint">برای چند مدیر، با کاما جدا کنید.</div></div>
      <div class="field"><label>آدرس سایت (محل نصب)</label>
        <input class="mono" type="text" name="app_url" value="<?= h($D['app_url'] ?? detect_url()) ?>" required>
        <div class="hint">وب‌هوک روی <span class="mono">این آدرس/index.php</span> تنظیم می‌شود و برای کار کردن ربات باید SSL (https) فعال باشد.</div></div>
      <div class="row-between mt4">
        <a class="btn btn-ghost" href="index.php?step=2">→ قبلی</a>
        <button class="btn btn-primary" type="submit">بررسی توکن و ادامه ←</button>
      </div>
    </form>

  <?php elseif ($step === 4): ?>
    <div class="card-head">
      <div><div class="card-title">🔐 ساخت حساب مدیر پنل وب</div>
      <div class="card-sub">با این مشخصات به پنل مدیریت تحت وب وارد می‌شوید.</div></div>
    </div>
    <form method="post">
      <input type="hidden" name="step" value="4">
      <div class="field"><label>نام فروشگاه</label>
        <input type="text" name="shop_title" value="<?= h($D['shop_title'] ?? 'فروشگاه کانفیگ') ?>"></div>
      <div class="field"><label>نام کاربری مدیر</label>
        <input class="mono" type="text" name="admin_user" value="<?= h($D['admin']['user'] ?? 'admin') ?>" required></div>
      <div class="split">
        <div class="field"><label>رمز عبور</label>
          <div class="row"><input class="grow" type="password" name="admin_pass" id="apw" required>
            <button type="button" class="icon-btn" data-eye="#apw">👁</button></div>
          <div class="pw-meter"><span id="apwBar"></span></div>
          <div class="hint" id="apwTxt">حداقل ۸ کاراکتر، ترکیب حرف بزرگ و کوچک و رقم.</div></div>
        <div class="field"><label>تکرار رمز عبور</label>
          <input type="password" name="admin_pass2" required></div>
      </div>
      <div class="row-between mt4">
        <a class="btn btn-ghost" href="index.php?step=3">→ قبلی</a>
        <button class="btn btn-primary" type="submit">ادامه ←</button>
      </div>
    </form>

  <?php else: ?>
    <?php if (!$done): ?>
      <div class="card-head">
        <div><div class="card-title">🚀 شروع نصب</div>
        <div class="card-sub">با تایید نهایی، جداول ساخته و وب‌هوک تنظیم می‌شود.</div></div>
      </div>
      <div class="finish-box">
        <div class="kv"><span class="k">دیتابیس</span><span class="mono"><?= h(($D['db']['name'] ?? '-') . '@' . ($D['db']['host'] ?? '-')) ?></span></div>
        <div class="kv"><span class="k">پیشوند جداول</span><span class="mono"><?= h($D['db']['prefix'] ?? 'vs_') ?></span></div>
        <div class="kv"><span class="k">ربات</span><span class="mono">@<?= h($D['bot']['username'] ?? '-') ?></span></div>
        <div class="kv"><span class="k">مدیران ربات</span><span class="mono"><?= h(implode(', ', $D['bot']['admins'] ?? [])) ?></span></div>
        <div class="kv"><span class="k">آدرس وب‌هوک</span><span class="mono"><?= h(rtrim((string)($D['app_url'] ?? ''), '/') . '/index.php') ?></span></div>
      </div>
      <form method="post" class="mt4">
        <input type="hidden" name="step" value="5">
        <div class="row-between">
          <a class="btn btn-ghost" href="index.php?step=4">→ قبلی</a>
          <button class="btn btn-green" type="submit">✅ نصب نهایی</button>
        </div>
      </form>
    <?php else: ?>
      <?php if (empty($D['webhook_ok'])): ?>
        <div class="alert a-err">
          ⛔ <b>نصب انجام شد ولی وب‌هوک تنظیم نشد</b> — تا وقتی این مرحله درست نشود، ربات به هیچ پیامی پاسخ نمی‌دهد.
          <div class="mt3 mono">پاسخ تلگرام: <?= h((string)($D['webhook_msg'] ?? '-')) ?></div>
          <div class="mono">آدرس وب‌هوک: <?= h(rtrim((string)($D['app_url'] ?? ''), '/') . '/index.php') ?></div>
          <div class="mt3">معمولاً یکی از این‌هاست: آدرس سایت <b>https</b> نیست، گواهی SSL معتبر نیست، یا سرور به تلگرام دسترسی ندارد.</div>
          <div class="mt3"><a class="btn btn-sm" href="index.php?done=1&amp;rehook=1">🔁 تلاش دوباره برای تنظیم وب‌هوک</a></div>
        </div>
      <?php else: ?>
        <div class="alert a-ok">🎉 نصب با موفقیت انجام شد!</div>
        <div class="alert a-info">وب‌هوک ربات فعال شد. در تلگرام دستور <span class="mono">/start</span> را بزنید.</div>
      <?php endif; ?>
      <div class="finish-box">
        <b>۱) پنل مدیریت تحت وب</b>
        <div class="copy-line"><code><?= h(rtrim((string)($D['app_url'] ?? ''), '/') . '/admin/index.php') ?></code>
          <button class="btn btn-sm" data-copy="<?= h(rtrim((string)($D['app_url'] ?? ''), '/') . '/admin/index.php') ?>">کپی</button></div>
        <div class="mt3"><b>۲) قدم بعدی:</b> در پنل مدیریت ابتدا یک پنل VPN-UI / سنایی (جدید و قدیم) / مرزبان اضافه کنید، سپس محصول بسازید و روش پرداخت (کارت به کارت / ارزی) را تکمیل کنید.</div>
        <div class="mt3"><b>۳) کرونجاب (اختیاری ولی توصیه‌شده):</b> هر ۵ دقیقه
          <div class="copy-line"><code>php <?= h(APP_ROOT) ?>/cron/tasks.php</code>
            <button class="btn btn-sm" data-copy="php <?= h(APP_ROOT) ?>/cron/tasks.php">کپی</button></div>
        </div>
        <?php if ($autoDel): ?>
          <div class="mt3"><b>۴) امنیت:</b> ✅ پوشهٔ <code class="mono">install</code> به‌صورت خودکار حذف شد.
            <br><span class="mono" style="opacity:.75">اگر دسترسی نوشتن وجود نداشته باشد، حتماً دستی پاکش کنید.</span></div>
        <?php else: ?>
          <div class="mt3"><b>۴) امنیت:</b> پوشه <code class="mono">install</code> را پاک کنید.
            <a class="btn btn-sm" href="index.php?done=1&amp;wipe=1">🗑 حذف همین حالا</a></div>
        <?php endif; ?>
      </div>
      <div class="row mt5">
        <a class="btn btn-primary" href="../admin/index.php">🌐 ورود به پنل مدیریت</a>
        <?php if (!empty($D['bot']['username'])): ?>
          <a class="btn" target="_blank" href="https://t.me/<?= h((string)$D['bot']['username']) ?>">🤖 باز کردن ربات</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
  </div>

  <div class="muted mt4" style="text-align:center;font-size:12.5px">نصب روی سرور اختصاصی؟ اسکریپت <span class="mono">install.sh</span> همه مراحل را خودکار انجام می‌دهد.</div>
<?php endif; ?>

</div>
</div>
<script>
(function () {
  /* تم روشن/تیره برای صفحه نصب */
  var K = 'vs-theme', r = document.documentElement, b = document.getElementById('insTheme');
  try { if (localStorage.getItem(K) === 'light') r.setAttribute('data-theme', 'light'); } catch (e) {}
  if (b) b.addEventListener('click', function () {
    var light = r.getAttribute('data-theme') === 'light';
    if (light) r.removeAttribute('data-theme'); else r.setAttribute('data-theme', 'light');
    try { localStorage.setItem(K, light ? 'dark' : 'light'); } catch (e) {}
  });

  /* سنجه قدرت رمز */
  var p = document.getElementById('apw'), bar = document.getElementById('apwBar'), txt = document.getElementById('apwTxt');
  if (p && bar) {
    var L = ['خیلی ضعیف', 'ضعیف', 'متوسط', 'خوب', 'قوی'];
    var C = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#16a34a'];
    p.addEventListener('input', function () {
      var v = p.value, s = 0;
      if (v.length >= 8) s++;
      if (v.length >= 12) s++;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
      if (/[0-9]/.test(v)) s++;
      if (/[^A-Za-z0-9]/.test(v)) s++;
      if (s > 4) s = 4;
      if (!v.length) { bar.style.width = '0'; if (txt) txt.textContent = 'حداقل ۸ کاراکتر، ترکیب حرف بزرگ و کوچک و رقم.'; return; }
      bar.style.width = ((s + 1) * 20) + '%';
      bar.style.background = C[s];
      if (txt) txt.textContent = 'قدرت رمز: ' + L[s];
    });
  }
})();
</script>
<script src="../assets/app.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/app.js') ?: '2' ?>"></script>
</body>
</html>
