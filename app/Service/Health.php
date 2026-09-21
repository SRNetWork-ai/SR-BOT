<?php

/**
 * بررسی سلامت سیستم
 *
 * وضعیت هر مورد: ok (سالم) | warn (هشدار) | err (خطا)
 * خروجی all(): [ ['title','icon','items' => [ ['label','value','status','hint'] ]] ]
 */
class Health
{
    /** جدول‌های ضروری (بدون پیشوند) */
    public const TABLES = [
        'settings', 'admins', 'users', 'panels', 'products', 'services',
        'orders', 'transactions', 'tickets', 'ticket_messages', 'tutorials', 'logs',
    ];

    /** افزونه‌های لازم PHP */
    public const EXTS = ['pdo_mysql', 'curl', 'mbstring', 'json', 'openssl', 'zip'];

    private static function it(string $label, string $value, string $status = 'ok', string $hint = ''): array
    {
        return ['label' => $label, 'value' => $value, 'status' => $status, 'hint' => $hint];
    }

    /** همه گروه‌های بررسی */
    public static function all(bool $live = true): array
    {
        $groups = [
            ['برنامه', '🧩', 'app'],
            ['PHP و سرور', '🖥', 'php'],
            ['دیتابیس', '🗄', 'db'],
            ['فایل‌ها و دسترسی‌ها', '📁', 'files'],
            ['ربات و وب‌هوک', '🤖', 'bot'],
            ['کران‌جاب و زمان‌بندی', '⏱', 'cron'],
            ['پنل‌های VPN', '🖧', 'panels'],
            ['فروش و سفارش‌ها', '🛒', 'sales'],
        ];

        $out = [];
        foreach ($groups as $g) {
            try {
                $items = (array)call_user_func([self::class, $g[2]], $live);
            } catch (Throwable $e) {
                $items = [self::it('خطا در بررسی', $e->getMessage(), 'err')];
            }
            $out[] = ['title' => $g[0], 'icon' => $g[1], 'items' => $items];
        }
        return $out;
    }

    /** خلاصه وضعیت کل */
    public static function score(array $groups): array
    {
        $c = ['ok' => 0, 'warn' => 0, 'err' => 0];
        foreach ($groups as $g) {
            foreach ((array)($g['items'] ?? []) as $i) {
                $s = (string)($i['status'] ?? 'ok');
                if (!isset($c[$s])) $s = 'ok';
                $c[$s]++;
            }
        }
        $c['total'] = $c['ok'] + $c['warn'] + $c['err'];
        $c['state'] = $c['err'] > 0 ? 'err' : ($c['warn'] > 0 ? 'warn' : 'ok');
        return $c;
    }

    /* ------------------------------------------------------------- برنامه */

    public static function app(bool $live = true): array
    {
        $out   = [];
        $out[] = self::it('نسخه نصب‌شده', APP_VERSION);

        $rem = (string)DB::setting('update_remote_version', '');
        if ($rem !== '') {
            $new   = version_compare($rem, APP_VERSION, '>');
            $out[] = self::it('نسخه روی مخزن', $rem, $new ? 'warn' : 'ok',
                $new ? 'در صفحه به‌روزرسانی می‌توانید نسخه جدید را نصب کنید.' : '');
        }

        $url   = (string)cfg('app.url', '');
        $out[] = self::it('آدرس برنامه', $url !== '' ? $url : 'تنظیم نشده',
            $url !== '' ? 'ok' : 'err',
            $url === '' ? 'در config.php مقدار app.url را درست کنید.' : '');

        $ins   = is_dir(APP_ROOT . '/install');
        $out[] = self::it('پوشه نصب', $ins ? 'باز است' : 'حذف شده', $ins ? 'warn' : 'ok',
            $ins ? 'بعد از نصب، پوشه install را حذف کنید.' : '');

        $mnt   = (string)DB::setting('maintenance', '0') === '1';
        $out[] = self::it('حالت تعمیرات', $mnt ? 'روشن' : 'خاموش', $mnt ? 'warn' : 'ok',
            $mnt ? 'در این حالت ربات به کاربران سرویس نمی‌دهد.' : '');

        return $out;
    }

    /* ---------------------------------------------------------- PHP و سرور */

    public static function php(bool $live = true): array
    {
        $out   = [];
        $ok    = version_compare(PHP_VERSION, '8.0', '>=');
        $out[] = self::it('نسخه PHP', PHP_VERSION, $ok ? 'ok' : 'err',
            $ok ? '' : 'حداقل نسخه ۸.۰ لازم است.');

        foreach (self::EXTS as $e) {
            $has   = extension_loaded($e);
            $st    = $has ? 'ok' : ($e === 'zip' ? 'warn' : 'err');
            $out[] = self::it('افزونه ' . $e, $has ? 'فعال' : 'غیرفعال', $st,
                $has ? '' : 'این افزونه را از پنل هاست فعال کنید.');
        }

        $out[] = self::it('حافظه مجاز', (string)ini_get('memory_limit'));

        $t     = (int)ini_get('max_execution_time');
        $out[] = self::it('زمان اجرای مجاز', $t === 0 ? 'بی‌نهایت' : ($t . ' ثانیه'),
            ($t === 0 || $t >= 60) ? 'ok' : 'warn',
            ($t !== 0 && $t < 60) ? 'برای بکاپ و به‌روزرسانی مقدار بیشتری لازم است.' : '');

        $out[] = self::it('حجم مجاز آپلود', (string)ini_get('upload_max_filesize'));
        $out[] = self::it('نوع اجرا', PHP_SAPI);
        $out[] = self::it('زمان سرور', now());

        return $out;
    }

    /* ------------------------------------------------------------- دیتابیس */

    public static function db(bool $live = true): array
    {
        $out   = [];
        $conn  = DB::connected();
        $out[] = self::it('اتصال دیتابیس', $conn ? 'برقرار' : 'قطع', $conn ? 'ok' : 'err');
        if (!$conn) return $out;

        $pre  = DB::prefix();
        $rows = DB::all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()');
        $have = [];
        foreach ($rows as $r) $have[] = strtolower((string)($r['t'] ?? ''));

        $missing = [];
        foreach (self::TABLES as $t) {
            if (!in_array(strtolower($pre . $t), $have, true)) $missing[] = $pre . $t;
        }
        $out[] = self::it('جدول‌ها',
            $missing ? ('ناقص: ' . implode('، ', $missing)) : (fa_num((string)count(self::TABLES)) . ' جدول سالم'),
            $missing ? 'err' : 'ok',
            $missing ? 'در صفحه به‌روزرسانی گزینه اجرای مایگریشن را بزنید.' : '');

        $size  = (int)DB::val('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()', [], 0);
        $out[] = self::it('حجم دیتابیس', human_bytes($size));
        $out[] = self::it('تعداد کاربران', fa_num((string)(int)DB::val('SELECT COUNT(*) FROM {p}users', [], 0)));
        $out[] = self::it('سرویس‌های فعال',
            fa_num((string)(int)DB::val("SELECT COUNT(*) FROM {p}services WHERE status = 'active'", [], 0)));

        $mig   = (string)DB::setting('db_migrations', '');
        $out[] = self::it('مایگریشن‌های اجراشده', $mig !== '' ? $mig : 'هیچ');

        return $out;
    }

    /* ------------------------------------------------- فایل‌ها و دسترسی‌ها */

    public static function files(bool $live = true): array
    {
        $out = [];
        foreach (['storage', 'storage/backups', 'storage/logs', 'storage/updates'] as $d) {
            $p = APP_ROOT . '/' . $d;
            if (!is_dir($p)) @mkdir($p, 0755, true);
            $w     = is_dir($p) && is_writable($p);
            $out[] = self::it('نوشتن در ' . $d, $w ? 'مجاز' : 'غیرمجاز', $w ? 'ok' : 'err',
                $w ? '' : 'دسترسی این پوشه را ۷۵۵ کنید.');
        }

        $cf    = APP_ROOT . '/config.php';
        $out[] = self::it('فایل config.php', is_file($cf) ? 'موجود' : 'ناموجود', is_file($cf) ? 'ok' : 'err');

        $rw    = is_writable(APP_ROOT);
        $out[] = self::it('نوشتن در ریشه پروژه', $rw ? 'مجاز' : 'غیرمجاز', $rw ? 'ok' : 'warn',
            $rw ? '' : 'برای به‌روزرسانی یک‌کلیکی لازم است.');

        $free = @disk_free_space(APP_ROOT);
        if ($free !== false) {
            $low   = (int)$free < 300 * 1024 * 1024;
            $out[] = self::it('فضای آزاد دیسک', human_bytes((int)$free), $low ? 'warn' : 'ok',
                $low ? 'برای بکاپ فضای کافی وجود ندارد.' : '');
        }

        if (class_exists('Backup')) {
            $st    = Backup::stats();
            $cnt   = (int)($st['count'] ?? 0);
            $out[] = self::it('بکاپ‌های موجود',
                fa_num((string)$cnt) . ' فایل (' . human_bytes((int)($st['size'] ?? 0)) . ')',
                $cnt > 0 ? 'ok' : 'warn',
                $cnt > 0 ? ('آخرین: ' . (string)($st['last'] ?? '-')) : 'حداقل یک بکاپ بگیرید.');
        }

        return $out;
    }

    /* --------------------------------------------------------- ربات و وب‌هوک */

    public static function bot(bool $live = true): array
    {
        $out   = [];
        $tok   = (string)cfg('bot.token', '');
        $out[] = self::it('توکن ربات', $tok !== '' ? 'ثبت شده' : 'ثبت نشده', $tok !== '' ? 'ok' : 'err',
            $tok === '' ? 'در config.php مقدار bot.token را وارد کنید.' : '');

        if ($live && $tok !== '') {
            $me    = Tg::getMe();
            $okMe  = !empty($me['ok']);
            $out[] = self::it('اتصال به تلگرام',
                $okMe ? ('@' . (string)($me['result']['username'] ?? '-')) : 'ناموفق',
                $okMe ? 'ok' : 'err',
                $okMe ? '' : (string)($me['description'] ?? 'دسترسی سرور به api.telegram.org را بررسی کنید.'));

            $wh = Tg::api('getWebhookInfo');
            if (!empty($wh['ok'])) {
                $info  = (array)($wh['result'] ?? []);
                $url   = (string)($info['url'] ?? '');
                $want  = app_url('index.php');
                $same  = $url !== '' && rtrim($url, '/') === rtrim($want, '/');
                $out[] = self::it('آدرس وب‌هوک', $url !== '' ? $url : 'ثبت نشده',
                    $same ? 'ok' : ($url === '' ? 'err' : 'warn'),
                    $same ? '' : ('آدرس درست: ' . $want . ' — دکمه ثبت مجدد وب‌هوک را بزنید.'));

                $pend  = (int)($info['pending_update_count'] ?? 0);
                $out[] = self::it('پیام‌های در صف', fa_num((string)$pend), $pend > 50 ? 'warn' : 'ok',
                    $pend > 50 ? 'صف پیام‌ها زیاد است؛ احتمالاً ربات خطا می‌دهد.' : '');

                $err = trim((string)($info['last_error_message'] ?? ''));
                if ($err !== '') {
                    $out[] = self::it('آخرین خطای وب‌هوک', mb_substr($err, 0, 140), 'warn',
                        'اگر تکرار می‌شود، آدرس و گواهی SSL را بررسی کنید.');
                }
            }
        }

        if (class_exists('Logs')) {
            $chat  = (string)Logs::chat();
            $out[] = self::it('گروه لاگ', $chat !== '' ? $chat : 'تنظیم نشده', $chat !== '' ? 'ok' : 'warn',
                $chat === '' ? 'در تنظیمات، شناسه گروه تاپیک‌دار را وارد کنید.' : '');

            if ($chat !== '') {
                $th    = array_filter((array)Logs::threads());
                $all   = count(Logs::TOPICS);
                $n     = count($th);
                $out[] = self::it('تاپیک‌های ساخته‌شده', fa_num((string)$n) . ' از ' . fa_num((string)$all),
                    $n >= $all ? 'ok' : 'warn',
                    $n >= $all ? '' : 'در تنظیمات دکمه ساخت خودکار تاپیک‌ها را بزنید.');
            }
        }

        /* 0.0.2 #2: رمزنگاری اسرار در دیتابیس */
        if (class_exists('Crypt')) {
            if (!Crypt::available()) {
                $out[] = self::it('رمزنگاری اسرار', 'غیرفعال', 'warn',
                    'افزونهٔ openssl روی سرور فعال نیست؛ توکن‌ها و کلیدها به‌صورت متن ساده ذخیره می‌شوند.');
            } else {
                try {
                    if ((string)DB::setting('secrets_encrypted', '') !== '1') {
                        $enc = DB::encryptExistingSecrets();
                        DB::setSetting('secrets_encrypted', '1');
                        if (function_exists('app_log')) app_log('sec', 'secrets encrypted: ' . $enc);
                    }
                    $out[] = self::it('رمزنگاری اسرار', 'فعال', 'ok',
                        'توکن ربات‌ها، کلید درگاه‌ها و رمزهای ذخیره‌شده در تنظیمات، رمزنگاری‌شده نگهداری می‌شوند.');
                } catch (Throwable $e) {
                    $out[] = self::it('رمزنگاری اسرار', 'خطا', 'warn', $e->getMessage());
                }
            }
        }

        /* 0.0.2 #1: آیا فایل‌های حساس از روی وب خوانده می‌شوند؟ */
        /* 0.0.2 #3: رمزدار بودن فایل‌های بکاپ */
        if (class_exists('Backup') && method_exists('Backup', 'aesReady')) {
            $bkPw = trim((string)DB::setting('backup_pass', ''));
            if (!Backup::aesReady()) {
                $out[] = self::it('رمزگذاری بکاپ', 'پشتیبانی نمی‌شود', 'warn',
                    'نسخهٔ ZipArchive این سرور از AES-256 پشتیبانی نمی‌کند؛ فایل بکاپ بدون رمز ساخته می‌شود.');
            } elseif ($bkPw === '') {
                $out[] = self::it('رمزگذاری بکاپ', 'هنوز بدون رمز', 'warn',
                    'در صفحهٔ بکاپ رمز دلخواه بگذارید؛ وگرنه نخستین بکاپ بعدی خودکار یک رمز قوی می‌سازد.');
            } else {
                $out[] = self::it('رمزگذاری بکاپ', 'فعال (AES-256)', 'ok',
                    (string)DB::setting('backup_pass_auto', '') === '1'
                        ? 'رمز به‌صورت خودکار ساخته شده و در صفحهٔ بکاپ قابل مشاهده است.'
                        : 'فایل‌های بکاپ با رمز تعیین‌شدهٔ شما رمزگذاری می‌شوند.');
            }
        }

        /* 0.0.2 #8: توکن امنیتی وب‌هوک تلگرام */
        $whSec = '';
        try {
            if (class_exists('Cfg')) $whSec = trim((string)Cfg::get('bot.secret', ''));
            if ($whSec === '' && function_exists('cfg')) $whSec = trim((string)cfg('bot.secret', ''));
        } catch (Throwable $e) {
            $whSec = '';
        }
        $out[] = $whSec === ''
            ? self::it('توکن امنیتی وب‌هوک', 'تنظیم نشده', 'warn',
                'بدون آن هر کسی می‌تواند به آدرس وب‌هوک درخواست بفرستد؛ دکمهٔ تنظیم وب‌هوک را بزنید تا خودکار ساخته شود.')
            : self::it('توکن امنیتی وب‌هوک', 'فعال', 'ok',
                'هر درخواست ورودی با هدر X-Telegram-Bot-Api-Secret-Token بررسی می‌شود.');

        /* 0.0.2 #1: بررسی دسترسی وب به فایل‌های حساس */
        /* 0.0.2 #14: SSRF guard item */
        if (class_exists('Net')) {
            $nh = Net::healthItem();
            $out[] = self::it((string)$nh['title'], (string)$nh['value'], (string)$nh['status'], (string)$nh['note']);
        }

        if (class_exists('Guard')) {
            $gx   = Guard::exposure();
            $gopn = (array)($gx['open'] ?? []);
            if ($gopn) {
                $out[] = self::it('فایل‌های حساس روی وب', implode('، ', array_keys($gopn)), 'warn',
                    'این مسیرها از اینترنت قابل خواندن‌اند. قواعد فایل nginx.conf.sample را روی سرور اعمال کنید یا مطمئن شوید .htaccess فعال است.');
            } elseif ((int)($gx['checked'] ?? 0) > 0) {
                $out[] = self::it('محافظت فایل‌های حساس', 'برقرار', 'ok', '');
            }
            $gi = Guard::installer();
            if (!empty($gi['present'])) {
                $out[] = self::it('پوشهٔ نصب روی سرور', !empty($gi['unlocked']) ? 'قفل باز است!' : 'قفل است', 'warn',
                    !empty($gi['unlocked'])
                        ? 'فایل storage/tmp/install.unlock را حذف کنید تا نصاب دوباره قفل شود.'
                        : 'برای امنیت بیشتر، پوشهٔ install را از سرور حذف کنید.');
            }
        }

        /* fixed85: سلامت صف و آخرین خطای گزارش‌ها */
        if (class_exists('Logs') && Logs::chat() !== '') {
            $lst = Logs::stats();
            $lqn = (int)($lst['queue'] ?? 0);
            $out[] = self::it('صف گزارش‌های معلق', fa_num((string)$lqn), $lqn > 0 ? 'warn' : 'ok',
                $lqn > 0 ? 'با اجرای کرانجاب یا دکمهٔ «ارسال صف معلق» ارسال می‌شوند.' : '');
            if ((string)($lst['last_err'] ?? '') !== '') {
                $out[] = self::it('آخرین خطای ارسال گزارش', mb_substr((string)$lst['last_err'], 0, 120), 'warn',
                    'دسترسی ربات در گروه و فعال بودن حالت Topics را بررسی کنید.');
            }
        }

        $fc    = trim((string)DB::setting('force_channel', ''));
        $out[] = self::it('عضویت اجباری کانال', $fc !== '' ? $fc : 'غیرفعال');

        return $out;
    }

    /* ------------------------------------------------- کران‌جاب و زمان‌بندی */

    public static function cron(bool $live = true): array
    {
        $out  = [];
        $beat = APP_ROOT . '/storage/last-cron.txt';
        $done = APP_ROOT . '/storage/last-cron-done.txt';
        $cmd  = 'php ' . APP_ROOT . '/cron/tasks.php';

        if (is_file($beat)) {
            $ts    = (int)@filemtime($beat);
            $age   = time() - $ts;
            $st    = $age > 1800 ? 'err' : ($age > 900 ? 'warn' : 'ok');
            $out[] = self::it('آخرین اجرای کران‌جاب',
                date('Y-m-d H:i', $ts) . ' (' . fa_num((string)(int)round($age / 60)) . ' دقیقه پیش)',
                $st, $st === 'ok' ? '' : 'کران‌جاب اجرا نمی‌شود: ' . $cmd);
        } else {
            $out[] = self::it('آخرین اجرای کران‌جاب', 'تا کنون اجرا نشده', 'err',
                'در هاست این دستور را هر ۱۰ دقیقه تنظیم کنید: ' . $cmd);
        }

        if (is_file($done)) {
            $ts    = (int)@filemtime($done);
            $age   = time() - $ts;
            $out[] = self::it('آخرین اجرای موفق', date('Y-m-d H:i', $ts),
                $age > 3600 ? 'warn' : 'ok',
                $age > 3600 ? 'اجرای آخر ناتمام مانده است؛ لاگ خطاها را ببینید.' : '');
        }

        $lockFile = APP_ROOT . '/storage/cron.lock';
        if (is_file($lockFile) && (time() - (int)@filemtime($lockFile)) > 3600) {
            $out[] = self::it('قفل کران‌جاب', 'قدیمی', 'warn',
                'فایل storage/cron.lock را حذف کنید.');
        }

        $bk    = (string)DB::setting('backup_last_at', '');
        $out[] = self::it('آخرین بکاپ خودکار', $bk !== '' ? $bk : 'انجام نشده', $bk !== '' ? 'ok' : 'warn');

        $uc = (int)DB::setting('update_last_check', 0);
        $out[] = self::it('آخرین بررسی به‌روزرسانی',
            $uc > 0 ? date('Y-m-d H:i', $uc) : 'انجام نشده');

        return $out;
    }

    /* ---------------------------------------------------------- پنل‌های VPN */

    public static function panels(bool $live = true): array
    {
        $out   = [];
        $tot   = (int)DB::val('SELECT COUNT(*) FROM {p}panels', [], 0);
        $act   = (int)DB::val('SELECT COUNT(*) FROM {p}panels WHERE active = 1', [], 0);
        $out[] = self::it('پنل‌های ثبت‌شده',
            fa_num((string)$tot) . ' پنل (فعال: ' . fa_num((string)$act) . ')',
            $act > 0 ? 'ok' : 'err', $act > 0 ? '' : 'حداقل یک پنل فعال لازم است.');
        /* 0.0.2 #x3-health: آمار زندهٔ پنل‌های نسل جدید سنایی (3x-ui) */
        if ($live && class_exists('Xui3')) {
            $x3seen = 0;
            foreach ((DB::all('SELECT * FROM {p}panels WHERE active = 1 ORDER BY id') ?: []) as $x3p) {
                if ($x3seen >= 3) break;
                $x3type = class_exists('Xui') ? Xui::normType((string)($x3p['type'] ?? '')) : '';
                if ($x3type !== 'sanaei' || !Xui3::hasToken($x3p)) continue;
                $x3seen++;
                $x3name = trim((string)($x3p['name'] ?? '')) !== ''
                    ? (string)$x3p['name']
                    : ('#' . (int)($x3p['id'] ?? 0));
                try {
                    $x3drv = new Xui3($x3p);
                    $x3sum = $x3drv->clientsSummary();
                    if ($x3sum) {
                        $out[] = self::it(
                            'کاربران ' . $x3name,
                            'کل ' . fa_num((string)(int)($x3sum['total'] ?? 0))
                                . ' • آنلاین ' . fa_num((string)(int)($x3sum['online'] ?? 0)),
                            'ok',
                            'رو به انقضا: ' . fa_num((string)(int)($x3sum['expiring'] ?? 0))
                                . ' | اتمام حجم: ' . fa_num((string)(int)($x3sum['depleted'] ?? 0))
                                . ' | غیرفعال: ' . fa_num((string)(int)($x3sum['deactive'] ?? 0))
                        );
                    }
                    /* 0.0.2 #x3-nodes-ui: آنلاین‌ها روی چند نود */
                    $x3g = $x3drv->onlinesByGuid();
                    if ($x3g) {
                        $x3nd = [];
                        foreach ($x3g as $x3row) {
                            foreach ((array)($x3row['nodes'] ?? []) as $x3n) {
                                $x3n = trim((string)$x3n);
                                if ($x3n !== '') $x3nd[$x3n] = true;
                            }
                        }
                        $out[] = self::it(
                            'آنلاین‌های ' . $x3name,
                            fa_num((string)count($x3g)) . ' کاربر روی ' . fa_num((string)max(1, count($x3nd))) . ' نود',
                            'ok',
                            $x3nd ? ('نودها: ' . implode('، ', array_slice(array_keys($x3nd), 0, 6))) : ''
                        );
                    }
                    $x3ip = $x3drv->ipLimitStatus();
                    if ($x3ip) {
                        $x3on = !empty($x3ip['usable']) && !empty($x3ip['enabled']);
                        $out[] = self::it(
                            'محدودیت IP ' . $x3name,
                            $x3on ? 'فعال' : (!empty($x3ip['installed']) ? 'نصب است ولی خاموش' : 'نصب نیست'),
                            $x3on ? 'ok' : 'warn',
                            'برای جلوگیری از اشتراک‌گذاری کانفیگ، Fail2ban را در پنل روشن کنید.'
                        );
                    }
                } catch (Throwable $x3e) {
                    $out[] = self::it('پنل ' . $x3name, 'بررسی نشد', 'warn', $x3e->getMessage());
                }
            }
        }


        $rows  = DB::all('SELECT name, last_error, user_limit, users_created FROM {p}panels WHERE active = 1 ORDER BY sort ASC, id ASC');
        $clean = true;
        foreach ($rows as $p) {
            $name = (string)($p['name'] ?? '-');
            $err  = trim((string)($p['last_error'] ?? ''));
            if ($err !== '') {
                $clean = false;
                $out[] = self::it('خطای پنل ' . $name, mb_substr($err, 0, 140), 'warn',
                    'بعد از رفع مشکل، دکمه پاک کردن خطاها را بزنید.');
            }
            $lim = (int)($p['user_limit'] ?? -1);
            $cr  = (int)($p['users_created'] ?? 0);
            if ($lim > 0) {
                $pc    = (int)floor($cr * 100 / max(1, $lim));
                $out[] = self::it('ظرفیت پنل ' . $name,
                    fa_num((string)$cr) . ' از ' . fa_num((string)$lim) . ' (' . fa_num((string)$pc) . '٪)',
                    $pc >= 90 ? 'warn' : 'ok', $pc >= 90 ? 'ظرفیت رو به اتمام است.' : '');
            }
        }
        if ($rows && $clean) $out[] = self::it('وضعیت پنل‌ها', 'بدون خطای ثبت‌شده');

        return $out;
    }

    /* ------------------------------------------------------ فروش و سفارش‌ها */

    public static function sales(bool $live = true): array
    {
        $out   = [];
        $stuck = class_exists('Orders') ? Orders::stuckCount() : 0;
        $out[] = self::it('سفارش‌های گیرکرده', fa_num((string)$stuck), $stuck > 0 ? 'warn' : 'ok',
            $stuck > 0 ? 'دکمه بازیابی سفارش‌های گیرکرده را بزنید.' : '');

        $pend  = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE status = 'pending'", [], 0);
        $out[] = self::it('پرداخت‌های در انتظار', fa_num((string)$pend), $pend > 0 ? 'warn' : 'ok',
            $pend > 0 ? 'در صفحه پرداخت‌ها بررسی کنید.' : '');

        $open  = (int)DB::val("SELECT COUNT(*) FROM {p}tickets WHERE status = 'open'", [], 0);
        $out[] = self::it('تیکت‌های باز', fa_num((string)$open), $open > 0 ? 'warn' : 'ok');

        $soon  = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE status = 'active' AND expire_at IS NOT NULL AND expire_at <= DATE_ADD(NOW(), INTERVAL 3 DAY)", [], 0);
        $out[] = self::it('سرویس‌های نزدیک انقضا', fa_num((string)$soon));

        $prod  = (int)DB::val('SELECT COUNT(*) FROM {p}products WHERE active = 1', [], 0);
        $out[] = self::it('محصولات فعال', fa_num((string)$prod), $prod > 0 ? 'ok' : 'err',
            $prod > 0 ? '' : 'حداقل یک محصول فعال بسازید.');

        $today = DB::one("SELECT COUNT(*) AS c, COALESCE(SUM(final_amount), 0) AS s FROM {p}orders WHERE status = 'paid' AND DATE(created_at) = CURDATE()");
        $out[] = self::it('فروش امروز',
            fa_num((string)(int)($today['c'] ?? 0)) . ' سفارش / ' . money((int)($today['s'] ?? 0)) . ' ' . currency());

        return $out;
    }
}
