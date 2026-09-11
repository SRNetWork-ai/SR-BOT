<?php
declare(strict_types=1);

/**
 * سیستم به‌روزرسانی
 *  - بررسی نسخه جدید از گیت‌هاب (version.json)
 *  - دریافت و نصب خودکار با بکاپ پیش از به‌روزرسانی
 *  - به‌روزرسانی دستی با فایل ZIP
 *  - اجرای مایگریشن‌های دیتابیس و تاریخچه/بازگشت به نسخه قبل
 */
class Updater
{
    public const DEFAULT_REPO = 'SRNetWork-ai/SR-BOT';
    public const API = 'https://api.github.com';

    public static function repo(): string   { return self::normalizeRepo((string)DB::setting('update_repo', self::DEFAULT_REPO)); }
    public static function branch(): string { return trim((string)DB::setting('update_branch', 'main')); }
    public static function token(): string  { return trim((string)DB::setting('update_token', '')); }
    public static function version(): string { return defined('APP_VERSION') ? APP_VERSION : '0.0.0'; }

    /** fixed80: پوشهٔ داخلی مخزن که پروژه در آن قرار دارد (خالی = ریشهٔ مخزن). خروجی با «/» پایانی */
    public static function subdir(): string
    {
        $s = trim((string)DB::setting('update_subdir', ''), " \t/\\");
        return $s === '' ? '' : str_replace('\\', '/', $s) . '/';
    }

    /**
     * fixed80: نرمال‌سازی آدرس مخزن — همهٔ این قالب‌ها به owner/repo تبدیل می‌شوند:
     * https://github.com/owner/repo(.git) · github.com/owner/repo/tree/main · git@github.com:owner/repo · owner/repo/
     */
    public static function normalizeRepo(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') return self::DEFAULT_REPO;
        $s = (string)preg_replace('~^(?:https?://)?(?:www\.)?github\.com[/:]~i', '', $s);
        $s = (string)preg_replace('~^git@github\.com:~i', '', $s);
        $s = trim($s, " \t/");
        $parts = explode('/', $s);
        if (count($parts) >= 2) $s = $parts[0] . '/' . $parts[1];
        return (string)preg_replace('~\.git$~i', '', $s);
    }

    public static function repoValid(string $repo): bool
    {
        return (bool)preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $repo);
    }

    /** fixed80: شمارهٔ بسته از رشتهٔ build (مثلاً fixed80 → 80) */
    public static function buildNum(string $build): int
    {
        return preg_match('/(\d+)/', $build, $m) ? (int)$m[1] : 0;
    }

    /** fixed80: build محلی از version.json پروژه */
    public static function localBuild(): string
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $f = APP_ROOT . '/version.json';
        $cache = '';
        if (is_file($f)) {
            $d = jdec((string)@file_get_contents($f), []);
            if (is_array($d)) $cache = trim((string)($d['build'] ?? ''));
        }
        return $cache;
    }

    /**
     * fixed80: آیا نسخهٔ راه‌دور جدیدتر است؟
     * نسخهٔ نمایشی قفل است (0.0.1-beta)؛ بنابراین وقتی version برابر باشد، شمارهٔ build (fixedNN) مقایسه می‌شود.
     */
    public static function isNewer(string $remoteVersion, string $remoteBuild): bool
    {
        $cmp = version_compare($remoteVersion, self::version());
        if ($cmp > 0) return true;
        if ($cmp < 0) return false;
        $rb = self::buildNum($remoteBuild);
        return $rb > 0 && $rb > self::buildNum(self::localBuild());
    }

    /** fixed80: برچسب نسخه + build برای نمایش */
    public static function tag(string $ver, string $build): string
    {
        return $ver . ($build !== '' ? ' / ' . $build : '');
    }

    public static function updDir(): string
    {
        $d = APP_ROOT . '/storage/updates';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    /* ==================== ارتباط با گیت‌هاب ==================== */

    private static function curl(string $url, string $saveTo = '', array $accept = []): array
    {
        $headers = ['User-Agent: VPNShop-Updater', 'X-GitHub-Api-Version: 2022-11-28'];
        foreach ($accept as $a) $headers[] = $a;
        if (self::token() !== '') $headers[] = 'Authorization: Bearer ' . self::token();

        $ch = curl_init($url);
        $fh = null;
        $opts = [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => app_ssl_verify(),
            CURLOPT_SSL_VERIFYHOST => app_ssl_verify() ? 2 : 0,
            CURLOPT_MAXREDIRS      => 5,
        ];
        if ($saveTo !== '') {
            $fh = @fopen($saveTo, 'w');
            if (!$fh) return ['ok' => false, 'code' => 0, 'body' => '', 'error' => 'امکان نوشتن فایل در storage/updates نیست.'];
            $opts[CURLOPT_FILE] = $fh;
        } else {
            $opts[CURLOPT_RETURNTRANSFER] = true;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string)curl_error($ch);
        curl_close($ch);
        if ($fh) fclose($fh);
        return [
            'ok'    => $err === '' && $code >= 200 && $code < 300,
            'code'  => $code,
            'body'  => is_string($body) ? $body : '',
            'error' => $err !== '' ? $err : ($code >= 400 ? 'پاسخ سرور: ' . $code : ''),
        ];
    }

    /** fixed80: خواندن version.json از یک آدرس؛ نتیجهٔ تلاش در $diag ثبت می‌شود */
    private static function fetchVersion(string $step, string $url, array $accept, array &$diag): ?array
    {
        $r   = self::curl($url, '', $accept);
        $row = ['step' => $step, 'url' => $url, 'code' => (int)($r['code'] ?? 0), 'ok' => false, 'note' => ''];
        if (empty($r['ok'])) {
            $row['note'] = (string)($r['error'] ?? '');
            $diag[] = $row;
            return null;
        }
        $data = jdec((string)$r['body'], []);
        if (!is_array($data) || trim((string)($data['version'] ?? '')) === '') {
            $row['note'] = 'ساختار version.json نامعتبر است';
            $diag[] = $row;
            return null;
        }
        $row['ok'] = true;
        $diag[] = $row;
        return $data;
    }

    /**
     * بررسی نسخه جدید
     * fixed80: تشخیص خودکار شاخه (default_branch / main / master)، پیدا کردن version.json در پوشه‌های داخلی،
     * مسیر جایگزین وقتی api.github.com در دسترس نیست، پیام‌های دقیق 404/401/403 و گزارش عیب‌یابی (update_last_diag).
     */
    public static function check(): array
    {
        $repo = self::repo();
        $diag = [];
        $fail = static function (string $hint) use (&$diag): array {
            DB::setSetting('update_last_error', $hint);
            DB::setSetting('update_last_check', now());
            DB::setSetting('update_last_diag', jenc($diag));
            return ['ok' => false, 'message' => 'بررسی نسخه ناموفق بود – ' . $hint, 'diag' => $diag];
        };
        if (!self::repoValid($repo)) {
            return $fail('آدرس مخزن نامعتبر است. قالب درست: owner/repo (مثال: ' . self::DEFAULT_REPO . ')');
        }
        $branch = self::branch() !== '' ? self::branch() : 'main';
        $sub    = self::subdir();
        $notes  = [];
        $api    = static function (string $br, string $path) use ($repo): string {
            return self::API . '/repos/' . $repo . '/contents/' . str_replace('%2F', '/', rawurlencode($path)) . '?ref=' . rawurlencode($br);
        };
        $rawAccept = ['Accept: application/vnd.github.raw'];

        /* ۱) شاخه و پوشهٔ تنظیم‌شده */
        $data  = self::fetchVersion('api:' . $branch, $api($branch, $sub . 'version.json'), $rawAccept, $diag);
        $first = $diag[count($diag) - 1] ?? ['code' => 0, 'note' => ''];
        $code  = (int)($first['code'] ?? 0);

        /* ۲) اطلاعات مخزن → شاخهٔ پیش‌فرض / خصوصی بودن / قطع ارتباط */
        $meta = null;
        if ($data === null) {
            $mu = self::API . '/repos/' . $repo;
            $m  = self::curl($mu, '', ['Accept: application/vnd.github+json']);
            $diag[] = ['step' => 'repo', 'url' => $mu, 'code' => (int)$m['code'], 'ok' => !empty($m['ok']), 'note' => (string)$m['error']];
            $mc = (int)$m['code'];
            if (!empty($m['ok'])) {
                $meta = jdec((string)$m['body'], []);
                $def  = trim((string)($meta['default_branch'] ?? ''));
                if ($def !== '') DB::setSetting('update_default_branch', $def);
            } elseif ($mc === 404) {
                return $fail('مخزن «' . $repo . '» پیدا نشد؛ یا نام owner/repo اشتباه است یا مخزن خصوصی است و توکن گیت‌هاب ثبت نشده.');
            } elseif ($mc === 401) {
                return $fail('توکن گیت‌هاب نامعتبر یا منقضی است (401). توکن تازه با دسترسی Contents: Read بسازید.');
            } elseif ($mc === 403) {
                return $fail('گیت‌هاب دسترسی را رد کرد (403) — محدودیت درخواست (rate limit) یا توکن بدون مجوز؛ چند دقیقه بعد دوباره تلاش کنید یا توکن معتبر ثبت کنید.');
            } elseif ($mc === 0) {
                /* اتصال به api.github.com برقرار نشد → مسیرهای جایگزین عمومی */
                foreach ([
                    ['raw',      'https://raw.githubusercontent.com/' . $repo . '/' . rawurlencode($branch) . '/' . $sub . 'version.json'],
                    ['jsdelivr', 'https://cdn.jsdelivr.net/gh/' . $repo . '@' . rawurlencode($branch) . '/' . $sub . 'version.json'],
                ] as $alt) {
                    $data = self::fetchVersion((string)$alt[0], (string)$alt[1], [], $diag);
                    if ($data !== null) {
                        $notes[] = 'سرور به api.github.com دسترسی ندارد؛ از مسیر جایگزین (' . (string)$alt[0] . ') خوانده شد. نصب خودکار ممکن است کار نکند — از «نصب دستی ZIP» استفاده کنید.';
                        break;
                    }
                }
                if ($data === null) return $fail('اتصال به گیت‌هاب برقرار نشد: ' . (string)$m['error']);
            }
        }

        /* ۳) شاخه‌های دیگر: پیش‌فرض مخزن، main، master */
        if ($data === null) {
            $def   = trim((string)($meta['default_branch'] ?? ''));
            $cands = [];
            foreach ([$def, 'main', 'master'] as $c) {
                if ($c !== '' && $c !== $branch && !in_array($c, $cands, true)) $cands[] = $c;
            }
            foreach ($cands as $c) {
                $data = self::fetchVersion('api:' . $c, $api($c, $sub . 'version.json'), $rawAccept, $diag);
                if ($data !== null) {
                    DB::setSetting('update_branch', $c);
                    $notes[] = 'شاخهٔ «' . $branch . '» پیدا نشد؛ شاخه به‌صورت خودکار به «' . $c . '» تغییر کرد.';
                    $branch = $c;
                    break;
                }
            }
        }

        /* ۴) جست‌وجوی version.json در پوشه‌های داخلی مخزن */
        if ($data === null && $meta !== null) {
            $def = trim((string)($meta['default_branch'] ?? ''));
            $tb  = $def !== '' ? $def : $branch;
            $tu  = self::API . '/repos/' . $repo . '/git/trees/' . rawurlencode($tb) . '?recursive=1';
            $t   = self::curl($tu, '', ['Accept: application/vnd.github+json']);
            $diag[] = ['step' => 'tree', 'url' => $tu, 'code' => (int)$t['code'], 'ok' => !empty($t['ok']), 'note' => (string)$t['error']];
            if (!empty($t['ok'])) {
                $tree  = jdec((string)$t['body'], []);
                $found = '';
                foreach ((array)($tree['tree'] ?? []) as $it) {
                    $pth = (string)($it['path'] ?? '');
                    if ($pth === 'version.json' || substr($pth, -13) === '/version.json') {
                        if ($found === '' || strlen($pth) < strlen($found)) $found = $pth;
                    }
                }
                if ($found === '') {
                    return $fail('در شاخهٔ «' . $tb . '» مخزن ' . $repo . ' هیچ فایل version.json وجود ندارد؛ فایل‌های پروژه (index.php، app/، version.json…) باید در ریشهٔ مخزن باشند، یا نام پوشهٔ داخلی را در تنظیمات منبع بنویسید.');
                }
                $dir  = $found === 'version.json' ? '' : substr($found, 0, -12);
                $data = self::fetchVersion('api:' . $tb . ':' . $found, $api($tb, $found), $rawAccept, $diag);
                if ($data !== null) {
                    DB::setSetting('update_subdir', rtrim($dir, '/'));
                    if ($tb !== $branch) { DB::setSetting('update_branch', $tb); $notes[] = 'شاخه به‌صورت خودکار به «' . $tb . '» تغییر کرد.'; $branch = $tb; }
                    if ($dir !== '') $notes[] = 'پروژه داخل پوشهٔ «' . rtrim($dir, '/') . '» مخزن است؛ به‌صورت خودکار ثبت شد.';
                    $sub = $dir;
                }
            }
        }

        if ($data === null) {
            if ($code === 404) {
                $hint = 'فایل version.json در مسیر «' . ($sub !== '' ? $sub : '/') . '» شاخهٔ «' . $branch . '» مخزن ' . $repo . ' پیدا نشد.';
            } elseif ($code === 401 || $code === 403) {
                $hint = 'دسترسی رد شد (' . $code . ')؛ برای مخزن خصوصی توکن گیت‌هاب با دسترسی خواندن لازم است.';
            } else {
                $hint = (string)($first['note'] ?? '') !== '' ? (string)$first['note'] : 'خطای ناشناخته در ارتباط با گیت‌هاب.';
            }
            if ($notes !== []) $hint .= ' ' . implode(' ', $notes);
            return $fail($hint);
        }

        $latest = trim((string)($data['version'] ?? ''));
        $rbuild = trim((string)($data['build'] ?? ''));
        $log    = $data['changelog'] ?? [];
        DB::setSetting('update_remote_version', $latest);
        DB::setSetting('update_remote_build', $rbuild);
        DB::setSetting('update_remote_date', (string)($data['released'] ?? ''));
        DB::setSetting('update_changelog', jenc(is_array($log) ? $log : [(string)$log]));
        DB::setSetting('update_last_check', now());
        DB::setSetting('update_last_error', '');
        DB::setSetting('update_last_diag', jenc($diag));
        DB::setSetting('update_last_notes', jenc($notes));
        $has = self::isNewer($latest, $rbuild);
        $cur = self::tag(self::version(), self::localBuild());
        $rem = self::tag($latest, $rbuild);
        $msg = $has
            ? '🚀 نسخهٔ جدید موجود است: ' . $rem . ' (نسخهٔ فعلی ' . $cur . ')'
            : '✅ نسخهٔ شما جدیدترین است (' . $cur . ')';
        if ($notes !== []) $msg .= ' — ' . implode(' ', $notes);
        return [
            'ok' => true, 'current' => self::version(), 'latest' => $latest, 'has_update' => $has,
            'build' => $rbuild, 'local_build' => self::localBuild(),
            'changelog' => is_array($log) ? $log : [], 'diag' => $diag, 'notes' => $notes,
            'message' => $msg,
        ];
    }

    public static function info(): array
    {
        $latest = (string)DB::setting('update_remote_version', '');
        $rbuild = (string)DB::setting('update_remote_build', '');
        return [
            'current'    => self::version(),
            'local_build'   => self::localBuild(),
            'remote_build'  => $rbuild,
            'default_branch'=> (string)DB::setting('update_default_branch', ''),
            'subdir'     => (string)DB::setting('update_subdir', ''),
            'diag'       => (array)jdec((string)DB::setting('update_last_diag', '[]'), []),
            'notes'      => (array)jdec((string)DB::setting('update_last_notes', '[]'), []),
            'latest'     => $latest,
            'has_update' => $latest !== '' && self::isNewer($latest, $rbuild),
            'checked_at' => (string)DB::setting('update_last_check', ''),
            'released'   => (string)DB::setting('update_remote_date', ''),
            'error'      => (string)DB::setting('update_last_error', ''),
            'changelog'  => (array)jdec((string)DB::setting('update_changelog', '[]'), []),
            'auto_check' => (int)DB::setting('update_auto_check', 1),
        ];
    }

    /** دریافت فایل ZIP آخرین نسخه */
    public static function download(): array
    {
        $zip = self::updDir() . '/update-' . date('Ymd-His') . '.zip';
        $url = self::API . '/repos/' . self::repo() . '/zipball/' . rawurlencode(self::branch());
        $r = self::curl($url, $zip, ['Accept: application/vnd.github+json']);
        if (empty($r['ok']) || !is_file($zip) || (int)@filesize($zip) < 1024) {
            @unlink($zip);
            return ['ok' => false, 'message' => 'دریافت فایل به‌روزرسانی ناموفق بود – ' . (string)$r['error']];
        }
        return ['ok' => true, 'path' => $zip, 'size' => (int)@filesize($zip), 'message' => 'فایل به‌روزرسانی دریافت شد (' . human_bytes((int)@filesize($zip)) . ')'];
    }

    /**
     * نصب فایل ZIP به‌روزرسانی
     * فایل‌های config.php و پوشه storage هرگز جایگزین نمی‌شوند.
     */
    public static function apply(string $zipPath, bool $backupFirst = true, bool $migrate = true): array
    {
        $log = [];
        if (!is_file($zipPath)) return ['ok' => false, 'log' => ['فایل به‌روزرسانی پیدا نشد.'], 'message' => 'فایل به‌روزرسانی پیدا نشد.'];
        if (!class_exists('ZipArchive')) return ['ok' => false, 'log' => ['ZipArchive فعال نیست.'], 'message' => 'افزونه ZipArchive روی هاست فعال نیست.'];

        $from = self::version();
        $preBackup = '';
        if ($backupFirst) {
            /* SRB-FIX: failure of the pre-update backup must not stop the update */
            try {
                $b = Backup::create('pre', 'پیش از به‌روزرسانی از نسخه ' . $from, false);
                if (!empty($b['ok'])) { $preBackup = (string)$b['name']; $log[] = '📦 بکاپ پیش از به‌روزرسانی: ' . $preBackup; }
                else $log[] = '⚠️ بکاپ پیش از به‌روزرسانی ناموفق بود: ' . (string)($b['message'] ?? '');
            } catch (Throwable $e) {
                $log[] = '⚠️ بکاپ پیش از به‌روزرسانی خطا داد و رد شد: ' . $e->getMessage();
                if (function_exists('app_log')) app_log('update', 'pre-backup failed: ' . $e->getMessage());
            }
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) return ['ok' => false, 'log' => array_merge($log, ['فایل ZIP باز نشد.']), 'message' => 'فایل ZIP باز نشد.'];

        /* تشخیص پوشه ریشه داخل ZIP (گیت‌هاب یک پوشه اضافه می‌سازد) */
        $root = '';
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) $names[] = (string)$zip->getNameIndex($i);
        /* fixed80: ریشه = کوتاه‌ترین پوشه‌ای که app/bootstrap.php دارد (زیپ گیت‌هاب + پوشهٔ داخلی، با هر عمقی) */
        $best = null;
        foreach ($names as $n) {
            if (preg_match('#^(.*?)app/bootstrap\.php$#', $n, $m) && ($best === null || strlen($m[1]) < strlen($best))) $best = $m[1];
        }
        if ($best === null) {
            foreach ($names as $n) {
                if (preg_match('#^(.*?)index\.php$#', $n, $m) && ($best === null || strlen($m[1]) < strlen($best))) $best = $m[1];
            }
        }
        $root = (string)($best ?? '');
        if ($best === null) $log[] = '⚠️ ساختار فایل ZIP ناشناخته است (app/bootstrap.php پیدا نشد).';
        if ($root !== '') $log[] = 'پوشه ریشه: ' . rtrim($root, '/');

        $written = 0; $skipped = 0;
        foreach ($names as $entry) {
            if ($entry === '' || substr($entry, -1) === '/') continue;
            if (strpos($entry, '..') !== false) continue;
            $rel = $entry;
            if ($root !== '') {
                if (strpos($entry, $root) !== 0) continue;
                $rel = substr($entry, strlen($root));
            }
            $rel = ltrim(str_replace('\\', '/', $rel), '/');
            if ($rel === '') continue;
            if (self::protectedPath($rel)) { $skipped++; continue; }
            $dest = APP_ROOT . '/' . $rel;
            $dd = dirname($dest);
            if (!is_dir($dd)) @mkdir($dd, 0775, true);
            $data = $zip->getFromName($entry);
            if ($data === false) { $skipped++; continue; }
            if (@file_put_contents($dest, $data) !== false) $written++;
            else $skipped++;
        }
        $zip->close();
        $log[] = '📁 ' . fa_num($written) . ' فایل نوشته شد، ' . fa_num($skipped) . ' فایل رد شد.';

        if ($migrate) {
            /* SRB-FIX: a failing migration must not stop the update */
            try {
                $m = self::migrate();
                $log[] = '🗄 ' . (string)$m['message'];
            } catch (Throwable $e) {
                $log[] = '⚠️ اجرای مهاجرت‌های دیتابیس ناموفق بود: ' . $e->getMessage();
                if (function_exists('app_log')) app_log('update', 'migrate failed: ' . $e->getMessage());
            }
        }

        /* نسخه جدید از فایل version.json محلی */
        $to = self::localVersionFile();
        $log[] = '🏷 نسخه نصب شده: ' . ($to !== '' ? $to : 'نامشخص');

        /* SRB-FIX: writing history must not break a successful update */
        try {
            self::addHistory([
                'at' => now(), 'from' => $from, 'to' => $to !== '' ? $to : $from,
                'files' => $written, 'backup' => $preBackup, 'ok' => $written > 0,
            ]);
            DB::setSetting('update_last_run', now());
        } catch (Throwable $e) {
            if (function_exists('app_log')) app_log('update', 'history failed: ' . $e->getMessage());
        }
        app_log('update', 'applied', ['from' => $from, 'to' => $to, 'files' => $written]);

        try { if (class_exists('Logs') && Logs::enabled()) {
            $cap = "⬆️ <b>به‌روزرسانی انجام شد</b>\nاز نسخه " . $from . ' به ' . ($to !== '' ? $to : '?')
                . "\nفایل‌ها: " . fa_num($written);
            $sentFile = false;
            if ($preBackup !== '' && class_exists('Backup')) {
                try {
                    $sentFile = Backup::sendTg($preBackup, $cap . "\n\n📦 <b>بکاپ پیش از به‌روزرسانی</b>");
                } catch (Throwable $eb) { $sentFile = false; }
            }
            if (!$sentFile) {
                Logs::send('backup', $cap . ($preBackup !== '' ? "\nبکاپ: " . $preBackup : ''));
            }
        } } catch (Throwable $e) { /* SRB-FIX-LOGS */ }

        /* SRB-REL: publish the changelog to the release channel/topic */
        try {
            if (class_exists('Release')) {
                Release::onUpdate($from, $to !== '' ? $to : $from, $written, $log);
                /* fixed80: بعد از هر آپدیت، دکمه‌های تازهٔ ربات خودکار اسکن و به فهرست اضافه شوند */
                try {
                    if (class_exists('Btn') && method_exists('Btn', 'autoScan')) {
                        $sc = Btn::autoScan(true);
                        if (!empty($sc['message'])) $log[] = '🔘 دکمه‌ها: ' . (string)$sc['message'];
                    }
                } catch (Throwable $e) { $log[] = '⚠️ اسکن دکمه‌ها خطا داد: ' . $e->getMessage(); }
            }
        } catch (Throwable $e) {
            if (function_exists('app_log')) app_log('update', 'release publish failed: ' . $e->getMessage());
        }

        return [
            'ok' => $written > 0, 'log' => $log, 'from' => $from, 'to' => $to, 'backup' => $preBackup,
            'message' => $written > 0
                ? '✅ به‌روزرسانی نصب شد – ' . fa_num($written) . ' فایل به‌روزرسانی شد.'
                : '⚠️ هیچ فایلی نوشته نشد؛ دسترسی نوشتن پوشه‌ها را بررسی کنید.',
        ];
    }

    /** فایل‌هایی که در به‌روزرسانی جایگزین نمی‌شوند */
    private static function protectedPath(string $rel): bool
    {
        $keep = ['config.php', 'storage', '.git', '.github', 'storage/installed.lock'];
        foreach ($keep as $k) if ($rel === $k || strpos($rel, $k . '/') === 0) return true;
        return false;
    }

    /** نسخه نوشته‌شده در version.json محلی */
    public static function localVersionFile(): string
    {
        $f = APP_ROOT . '/version.json';
        if (!is_file($f)) return '';
        $d = jdec((string)@file_get_contents($f), []);
        return trim((string)($d['version'] ?? ''));
    }

    /* ==================== مایگریشن دیتابیس ==================== */

    public static function migrate(): array
    {
        $dir = APP_ROOT . '/database/migrations';
        $done = (array)jdec((string)DB::setting('db_migrations', '[]'), []);
        $files = is_dir($dir) ? (array)glob($dir . '/*.sql') : [];
        sort($files);
        $applied = []; $errors = [];
        foreach ($files as $f) {
            $key = basename((string)$f);
            if (in_array($key, $done, true)) continue;
            try {
                $r = Backup::execSqlFile((string)$f, true);
                if (!empty($r['ok'])) { $done[] = $key; $applied[] = $key; }
                else {
                    $errors[] = $key;
                    if (function_exists('app_log')) app_log('update', 'migration errors: ' . $key, ['msg' => (string)($r['message'] ?? '')]);
                }
            } catch (Throwable $e) {
                $errors[] = $key;
                if (function_exists('app_log')) app_log('update', 'migration crashed: ' . $key, ['err' => $e->getMessage()]);
                DB::reconnect();
            }
            /* پیشرفت قدم‌به‌قدم ذخیره می‌شود تا یک فایل خراب همٔ مایگریشن‌ها را از دست ندهد */
            try { DB::setSetting('db_migrations', jenc(array_values(array_unique($done)))); } catch (Throwable $e) {}
        }

        /* تکمیل هوشمند ساختار: هر ستون/ایندکس/تنظیمی که فایل‌ها نساخته‌اند اینجا ساخته می‌شود */
        $smart = '';
        if (class_exists('Migrate')) {
            try {
                $sr = Migrate::run();
                $n  = (int)($sr['applied'] ?? 0);
                if ($n > 0) $smart = ' | تکمیل ساختار: ' . $n . ' مورد';
                if ($errors !== [] && Migrate::missingCount() === 0) {
                    $smart .= ' | خطای فایل‌های تکراری نادیده گرفته شد چون ساختار کامل است';
                    $errors = [];
                }
            } catch (Throwable $e) {
                $smart = ' | تکمیل ساختار ناموفق بود: ' . mb_substr($e->getMessage(), 0, 120);
            }
        }

        $msg = $applied === [] && $errors === [] && $smart === ''
            ? 'مایگریشن جدیدی نبود؛ ساختار دیتابیس کامل است.'
            : 'اجرا شد: ' . (count($applied) ? implode(', ', $applied) : '-')
                . (count($errors) ? ' | خطا: ' . implode(', ', $errors) : '') . $smart;
        return ['ok' => $errors === [], 'applied' => $applied, 'errors' => $errors, 'message' => $msg];
    }

    /* ==================== تاریخچه و بازگشت ==================== */

    private static function historyFile(): string { return APP_ROOT . '/storage/update-history.json'; }

    public static function history(): array
    {
        $f = self::historyFile();
        $rows = is_file($f) ? (array)jdec((string)@file_get_contents($f), []) : [];
        usort($rows, fn($a, $b) => strcmp((string)($b['at'] ?? ''), (string)($a['at'] ?? '')));
        return $rows;
    }

    public static function addHistory(array $row): void
    {
        $rows = self::history();
        array_unshift($rows, $row);
        $rows = array_slice($rows, 0, 20);
        @file_put_contents(self::historyFile(), jenc($rows));
    }

    /** بازگشت به نسخه قبل با بکاپ پیش از به‌روزرسانی */
    public static function rollback(string $backupName): array
    {
        $r = Backup::restore($backupName, true);
        app_log('update', 'rollback ' . $backupName, ['ok' => $r['ok']]);
        if (!empty($r['ok'])) self::addHistory(['at' => now(), 'from' => self::version(), 'to' => 'rollback', 'files' => 0, 'backup' => $backupName, 'ok' => true]);
        return $r;
    }

    /** اجرا در کرانجاب: بررسی روزانه نسخه و اطلاع به مدیران */
    public static function autoCheck(): array
    {
        if ((int)DB::setting('update_auto_check', 1) !== 1) return ['ok' => false, 'skipped' => true];
        $last = (string)DB::setting('update_last_check', '');
        if ($last !== '' && strtotime($last) > time() - 21600) return ['ok' => false, 'skipped' => true];
        $r = self::check();
        if (!empty($r['ok']) && !empty($r['has_update'])) {
            $tag  = self::tag((string)$r['latest'], (string)($r['build'] ?? ''));
            $seen = (string)DB::setting('update_notified_version', '');
            if ($seen !== $tag) {
                DB::setSetting('update_notified_version', $tag);
                $txt = "⬆️ <b>نسخه جدید ربات موجود است</b>\nنسخه فعلی: " . self::tag(self::version(), self::localBuild()) . "\nنسخه جدید: " . $tag
                    . "\n\nاز پنل مدیریت → به‌روزرسانی، دکمه «دریافت و نصب» را بزنید.";
                if (class_exists('AdminBot') && method_exists('AdminBot', 'notifyAdmins')) AdminBot::notifyAdmins($txt);
                if (class_exists('Logs') && Logs::enabled()) Logs::send('backup', $txt);
            }
        }
        return $r;
    }
}
