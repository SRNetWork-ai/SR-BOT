<?php
declare(strict_types=1);

/**
 * سیستم پشتیبان‌گیری و بازگردانی
 *  - بکاپ دیتابیس (SQL) یا بکاپ کامل (فایل‌ها + دیتابیس در یک ZIP)
 *  - زمان‌بندی خودکار، نگه‌داری تعداد دلخواه و ارسال در تاپیک بکاپ گروه گزارشات
 *  - بازگردانی از فایل SQL یا ZIP
 */
class Backup
{
    /** این مسیرها هرگز داخل بکاپ فایل‌ها نمی‌روند */
    public const SKIP = ['storage/backups', 'storage/updates', 'storage/logs', '.git', '.github', 'node_modules'];

    /** این مسیرها در بازگردانی فایل‌ها دست نمی‌خورند */
    public const KEEP = ['config.php', 'storage'];

    public static function dir(): string
    {
        $d = APP_ROOT . '/storage/backups';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    public static function typeLabel(string $t): string
    {
        $map = ['db' => 'دیتابیس', 'full' => 'کامل', 'pre' => 'پیش از به‌روزرسانی', 'upload' => 'آپلود شده'];
        return $map[$t] ?? $t;
    }

    /* ==================== ساخت بکاپ ==================== */

    /** خروجی SQL همه جداول با پیشوند فعلی */
    public static function dumpDb(string $path): array
    {
        try {
            $fh = @fopen($path, 'w');
            if (!$fh) return ['ok' => false, 'message' => 'امکان نوشتن در پوشه storage/backups نیست؛ دسترسی ۷۵۵ بدهید.'];
            $pfx = DB::prefix();
            $tables = 0; $rows = 0;
            fwrite($fh, "-- VPN Shop backup\n-- date: " . now() . "\n-- version: "
                . (defined('APP_VERSION') ? APP_VERSION : '-') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            foreach ((array)DB::pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tb) {
                if ($pfx !== '' && strpos((string)$tb, $pfx) !== 0) continue;
                $tables++;
                $c = DB::pdo()->query('SHOW CREATE TABLE `' . $tb . '`')->fetch(PDO::FETCH_ASSOC);
                fwrite($fh, "DROP TABLE IF EXISTS `$tb`;\n" . ($c['Create Table'] ?? '') . ";\n");
                $st = DB::pdo()->query('SELECT * FROM `' . $tb . '`');
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $cols = implode(',', array_map(fn($k) => '`' . $k . '`', array_keys($row)));
                    $vals = implode(',', array_map(fn($x) => $x === null ? 'NULL' : DB::pdo()->quote((string)$x), array_values($row)));
                    fwrite($fh, "INSERT INTO `$tb` ($cols) VALUES ($vals);\n");
                    $rows++;
                }
                fwrite($fh, "\n");
            }
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($fh);
            return ['ok' => true, 'path' => $path, 'tables' => $tables, 'rows' => $rows, 'size' => (int)@filesize($path)];
        } catch (Throwable $e) {
            app_log('backup', 'dump failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'خطا در ساخت خروجی دیتابیس: ' . $e->getMessage()];
        }
    }

    /** لیست فایل‌های پروژه برای بکاپ کامل */
    private static function projectFiles(): array
    {
        $out = [];
        self::walk(APP_ROOT, APP_ROOT, $out);
        return $out;
    }

    private static function walk(string $dir, string $base, array &$out): void
    {
        foreach ((array)@scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $full = $dir . '/' . $f;
            $rel  = ltrim(str_replace('\\', '/', substr($full, strlen($base))), '/');
            if (self::skipPath($rel)) continue;
            if (is_dir($full)) self::walk($full, $base, $out);
            elseif (is_file($full)) $out[] = $rel;
        }
    }

    private static function skipPath(string $rel): bool
    {
        foreach (self::SKIP as $s) {
            if ($rel === $s || strpos($rel, $s . '/') === 0) return true;
        }
        return in_array(basename($rel), ['.DS_Store', 'Thumbs.db'], true);
    }

    /** بکاپ کامل: همه فایل‌ها + database.sql در یک ZIP */
    /** رمز فایل‌های ZIP بکاپ؛ خالی یعنی بدون رمز */
    public static function zipPass(): string
    {
        return trim((string)DB::setting('backup_pass', ''));
    }

    /** رمزگذاری AES-256 همهٔ فایل‌های داخل ZIP در صورت تنظیم رمز و پشتیبانی سرور */
    private static function encryptZip(ZipArchive $zip): void
    {
        $pass = self::zipPass();
        if ($pass === '' || !method_exists($zip, 'setEncryptionIndex') || !defined('ZipArchive::EM_AES_256')) return;
        $zip->setPassword($pass);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            @$zip->setEncryptionIndex($i, ZipArchive::EM_AES_256);
        }
    }

    private static function zipAll(string $zipPath, string $sqlPath): array
    {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'message' => 'افزونه ZipArchive روی این هاست فعال نیست؛ فقط بکاپ دیتابیس ممکن است.'];
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'message' => 'ساخت فایل ZIP ناموفق بود.'];
        }
        $zip->addFile($sqlPath, 'database.sql');
        $count = 1;
        foreach (self::projectFiles() as $rel) {
            if ($zip->addFile(APP_ROOT . '/' . $rel, $rel)) $count++;
        }
        $zip->setArchiveComment('VPN Shop full backup ' . now());
        self::encryptZip($zip);
        $zip->close();
        if (!is_file($zipPath)) return ['ok' => false, 'message' => 'فایل ZIP ساخته نشد.'];
        return ['ok' => true, 'path' => $zipPath, 'files' => $count, 'size' => (int)@filesize($zipPath)];
    }

    /**
     * ساخت بکاپ جدید
     * @param string $type db | full | pre
     * @param bool|null $send ارسال در تلگرام (null = طبق تنظیمات)
     */
    public static function create(string $type = 'db', string $note = '', ?bool $send = null): array
    {
        $type  = in_array($type, ['db', 'full', 'pre'], true) ? $type : 'db';
        $stamp = date('Ymd-His');
        $dir   = self::dir();
        $sql   = $dir . '/backup-' . $type . '-' . $stamp . '.sql';

        $d = self::dumpDb($sql);
        if (empty($d['ok'])) return $d;

        $name = basename($sql);
        $extra = '';
        if ($type === 'full' || $type === 'pre') {
            $z = self::zipAll($dir . '/backup-' . $type . '-' . $stamp . '.zip', $sql);
            if (!empty($z['ok'])) {
                @unlink($sql);
                $name  = basename((string)$z['path']);
                $extra = ' – ' . fa_num((int)$z['files']) . ' فایل';
            } else {
                $extra = ' – ' . (string)$z['message'];
            }
        }

        $path = $dir . '/' . $name;
        DB::setSetting('backup_last_at', now());
        DB::setSetting('backup_last_name', $name);
        self::prune((int)DB::setting('backup_keep', 7));

        if ($send === null) $send = (int)DB::setting('backup_send_tg', 1) === 1;
        $sent = false;
        if ($send) {
            $cap = class_exists('Logs') ? Logs::fmt('📦 پشتیبان ' . self::typeLabel($type), [
                'نام فایل'   => '`' . $name,
                'نوع'        => self::typeLabel($type),
                'حجم'        => human_bytes((int)@filesize($path)),
                'جداول'      => fa_num((int)$d['tables']),
                'رکوردها'    => fa_num((int)$d['rows']),
                'زمان‌بندی'  => 'هر ' . fa_num(max(1, (int)DB::setting('backup_hours', 6))) . ' ساعت',
                'رمز فایل'   => self::zipPass() !== '' ? 'دارد 🔒' : 'ندارد ⚠️',
                'پشتیبان بعدی' => self::nextRun(),
                'توضیح'      => $note !== '' ? $note : '—',
            ], '🔒 این فایل شامل تمام داده‌های فروشگاه است؛ در جای امن نگهداری کنید.') : '';

            $sent = self::sendTg($name, $cap);
        }

        app_log('backup', 'created ' . $name, ['note' => $note, 'sent' => $sent]);

        return [
            'ok' => true, 'name' => $name, 'path' => $path, 'type' => $type, 'sent' => $sent,
            'size' => (int)@filesize($path),
            'message' => '✅ پشتیبان ' . self::typeLabel($type) . ' ساخته شد (' . human_bytes((int)@filesize($path)) . ')'
                . $extra . ($sent ? ' و در تلگرام ارسال شد.' : '.'),
        ];
    }

    /* ==================== مدیریت فایل‌ها ==================== */

    public static function all(): array
    {
        $out = [];
        foreach ((array)glob(self::dir() . '/*.{sql,zip}', GLOB_BRACE) as $f) {
            if (!is_file($f)) continue;
            $base = basename($f);
            $type = 'db';
            if (strpos($base, 'backup-full-') === 0) $type = 'full';
            elseif (strpos($base, 'backup-pre-') === 0) $type = 'pre';
            elseif (strpos($base, 'upload-') === 0) $type = 'upload';
            $out[] = [
                'name' => $base,
                'path' => $f,
                'type' => $type,
                'ext'  => strtolower((string)pathinfo($base, PATHINFO_EXTENSION)),
                'size' => (int)@filesize($f),
                'time' => (int)@filemtime($f),
            ];
        }
        usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
        return $out;
    }

    public static function stats(): array
    {
        $all = self::all();
        $size = 0;
        foreach ($all as $b) $size += (int)$b['size'];
        return [
            'count' => count($all),
            'size'  => $size,
            'last'  => $all[0]['time'] ?? 0,
            'last_name' => $all[0]['name'] ?? '',
        ];
    }

    /** مسیر امن یک فایل بکاپ (جلوگیری از دسترسی به فایل‌های دیگر) */
    public static function path(string $name): ?string
    {
        $name = basename(trim($name));
        if (!preg_match('/^[A-Za-z0-9._\-]+\.(sql|zip)$/', $name)) return null;
        $p = self::dir() . '/' . $name;
        return is_file($p) ? $p : null;
    }

    public static function delete(string $name): bool
    {
        $p = self::path($name);
        if ($p === null) return false;
        return (bool)@unlink($p);
    }

    /** نگه‌داری تعداد مشخص از هر نوع بکاپ */
    public static function prune(int $keep = 7): int
    {
        $keep = max(1, $keep);
        $groups = [];
        foreach (self::all() as $b) $groups[$b['type']][] = $b;
        $removed = 0;
        foreach ($groups as $items) {
            foreach (array_slice($items, $keep) as $old) {
                if (@unlink((string)$old['path'])) $removed++;
            }
        }
        return $removed;
    }

    /**
     * ارسال خودِ فایل پشتیبان در تاپیک بکاپ (نه پیوند دانلود)
     * فایل SQL ابتدا فشرده می‌شود تا حجم کمتری داشته باشد.
     */
    public static function sendTg(string $name, string $caption = ''): bool
    {
        $p = self::path($name);
        if ($p === null) {
            app_log('backup', 'sendTg: file not found', ['name' => $name]);
            return false;
        }
        return self::sendTgPath($p, $caption);
    }

    /** ارسال مستقیم یک مسیر فایل در تاپیک بکاپ */
    public static function sendTgPath(string $p, string $caption = ''): bool
    {
        if (!is_file($p)) return false;

        if (!class_exists('Logs') || !Logs::enabled()) {
            app_log('backup', 'sendTg: log group is not configured');
            return false;
        }

        $tmp  = '';
        $send = $p;

        /* فایل SQL را فشرده می‌کنیم (معمولاً حدود ۸۰٪ کوچک‌تر) */
        if (strtolower((string)pathinfo($p, PATHINFO_EXTENSION)) === 'sql' && class_exists('ZipArchive')) {
            $tmp = self::dir() . '/tg-' . date('Ymd-His') . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $zip->addFile($p, basename($p));
                $zip->setArchiveComment('SR-BOT backup ' . now());
                self::encryptZip($zip);
                $zip->close();
                if (is_file($tmp) && (int)@filesize($tmp) > 0) $send = $tmp;
            }
        }

        $cap = $caption !== '' ? $caption : Logs::fmt('📦 فایل پشتیبان', [
            'نام فایل' => '`' . basename($send),
            'حجم'      => human_bytes((int)@filesize($send)),
        ]);

        $ok = Logs::doc('backup', $send, $cap);

        if ($tmp !== '' && is_file($tmp)) @unlink($tmp);

        if (!$ok) {
            Logs::event('errors', '⚠️ ارسال فایل پشتیبان ناموفق بود', [
                'نام فایل' => '`' . basename($p),
                'حجم'      => human_bytes((int)@filesize($p)),
                'راهکار'   => 'از پنل مدیریت ‹ پشتیبان‌گیری › دستی دانلود کنید',
            ]);
        }

        return $ok;
    }

    /* ==================== بازگردانی ==================== */

    /** اجرای یک فایل SQL دستور به دستور */
    public static function execSqlFile(string $file, bool $usePrefix = false): array
    {
        if (!is_file($file)) return ['ok' => false, 'message' => 'فایل SQL پیدا نشد.'];
        $sql = (string)@file_get_contents($file);
        if ($usePrefix) $sql = DB::raw($sql);
        $stmts = self::splitSql($sql);
        if (!$stmts) return ['ok' => false, 'message' => 'فایل SQL خالی یا نامعتبر است.'];
        $done = 0; $errors = [];
        try { DB::pdo()->exec('SET FOREIGN_KEY_CHECKS=0'); } catch (Throwable $e) {}
        foreach ($stmts as $s) {
            $err = self::runStmt($s);
            if ($err === '') $done++;
            else $errors[] = $err;
        }
        try { DB::pdo()->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $e) {}
        try { DB::loadSettings(true); } catch (Throwable $e) {}
        return [
            'ok' => $done > 0 && count($errors) < $done,
            'done' => $done, 'total' => count($stmts), 'errors' => $errors,
            'message' => 'اجرای ' . fa_num($done) . ' دستور از ' . fa_num(count($stmts))
                . (count($errors) ? ' – خطاها: ' . implode(' | ', array_slice($errors, 0, 3)) : ''),
        ];
    }
    /** خطاهایی که معنایش «قبلاً انجام شده» است و باید نادیده گرفته شوند */
    private static function harmlessSqlError(string $msg): bool
    {
        $list = ['1050', '1060', '1061', '1062', '1091', 'already exists', 'Duplicate column',
                 'Duplicate key', 'Duplicate entry', 'check that column/key exists'];
        foreach ($list as $n) {
            if (stripos($msg, $n) !== false) return true;
        }
        return false;
    }

    /** رفتن به نتیجهٔ بعدی بدون پرت کردن خطا */
    private static function nextRowsetSafe(PDOStatement $st): bool
    {
        try { return (bool)$st->nextRowset(); } catch (Throwable $e) { return false; }
    }

    /**
     * اجرای یک دستور SQL با مصرف کامل نتیجه
     * دستورهایی مثل EXECUTE و SELECT و SHOW نتیجه برمی‌گردانند؛ اگر با exec() اجرا شوند
     * نتیجه روی اتصال باقی می‌ماند و دستور بعدی با خطای مبهم «General error: 2000» می‌شکند.
     * (مایگریشن‌های ۰۰۰۷ به بعد از الگوی PREPARE/EXECUTE استفاده می‌کنند و دقیقاً همین‌جا می‌شکستند.)
     * خروجی: رشتهٔ خالی یعنی موفق، وگرنه متن خطا.
     */
    private static function runStmt(string $s): string
    {
        $head = strtoupper(substr(ltrim($s, "( \t\n\r"), 0, 10));
        $rows = false;
        foreach (['SELECT', 'SHOW', 'DESC', 'EXPLAIN', 'EXECUTE', 'CALL', 'ANALYZE', 'CHECK', 'OPTIMIZE', 'REPAIR', 'WITH'] as $k) {
            if (strpos($head, $k) === 0) { $rows = true; break; }
        }

        $run = function (string $sql) use ($rows): void {
            if ($rows) {
                $st = DB::pdo()->query($sql);
                if ($st instanceof PDOStatement) {
                    do { $st->fetchAll(); } while (self::nextRowsetSafe($st));
                    $st->closeCursor();
                }
                return;
            }
            DB::pdo()->exec($sql);
        };

        try {
            $run($s);
            return '';
        } catch (Throwable $e) {
            $msg = (string)$e->getMessage();
            if (self::harmlessSqlError($msg)) return '';
            if (DB::reconnect()) {
                try {
                    $run($s);
                    return '';
                } catch (Throwable $e2) {
                    $msg = (string)$e2->getMessage();
                    if (self::harmlessSqlError($msg)) return '';
                }
            }
            return mb_substr($msg, 0, 160);
        }
    }


    /** جدا کردن دستورهای SQL با در نظر گرفتن رشته‌ها و توضیحات */
    public static function splitSql(string $sql): array
    {
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $out = []; $buf = '';
        $len = strlen($sql);
        $inS = false; $inD = false; $inB = false; $inLine = false; $inBlock = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $n = $i + 1 < $len ? $sql[$i + 1] : '';
            if ($inLine) { if ($c === "\n") { $inLine = false; $buf .= $c; } continue; }
            if ($inBlock) { if ($c === '*' && $n === '/') { $inBlock = false; $i++; } continue; }
            if (!$inS && !$inD && !$inB) {
                if ($c === '-' && $n === '-') { $inLine = true; $i++; continue; }
                if ($c === '#') { $inLine = true; continue; }
                if ($c === '/' && $n === '*') { $inBlock = true; $i++; continue; }
                if ($c === ';') { $t = trim($buf); if ($t !== '') $out[] = $t; $buf = ''; continue; }
            }
            if ($c === '\\' && ($inS || $inD)) { $buf .= $c . $n; $i++; continue; }
            if ($c === "'" && !$inD && !$inB) $inS = !$inS;
            elseif ($c === '"' && !$inS && !$inB) $inD = !$inD;
            elseif ($c === '`' && !$inS && !$inD) $inB = !$inB;
            $buf .= $c;
        }
        $t = trim($buf);
        if ($t !== '') $out[] = $t;
        return $out;
    }

    /**
     * بازگردانی از یک فایل پشتیبان
     * @param bool $withFiles در فایل ZIP، فایل‌های پروژه هم برگردانده شوند
     */
    public static function restore(string $name, bool $withFiles = false): array
    {
        $p = self::path($name);
        if ($p === null) return ['ok' => false, 'message' => 'فایل پشتیبان پیدا نشد.'];
        $ext = strtolower((string)pathinfo($p, PATHINFO_EXTENSION));

        if ($ext === 'sql') {
            $r = self::execSqlFile($p);
            app_log('backup', 'restore sql ' . $name, ['ok' => $r['ok']]);
            return ['ok' => !empty($r['ok']), 'message' => (!empty($r['ok']) ? '✅ دیتابیس بازگردانی شد – ' : '⚠️ بازگردانی با خطا – ') . (string)$r['message']];
        }

        if (!class_exists('ZipArchive')) return ['ok' => false, 'message' => 'افزونه ZipArchive فعال نیست؛ بازگردانی ZIP ممکن نیست.'];
        $zip = new ZipArchive();
        if ($zip->open($p) !== true) return ['ok' => false, 'message' => 'فایل ZIP باز نشد.'];
        if (self::zipPass() !== '') @$zip->setPassword(self::zipPass());

        $tmpDir = APP_ROOT . '/storage/backups/_restore-' . date('Ymd-His');
        @mkdir($tmpDir, 0775, true);
        $sqlEntry = '';
        $files = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);
            if ($entry === '' || substr($entry, -1) === '/') continue;
            if (strpos($entry, '..') !== false) continue;
            $base = basename($entry);
            if ($base === 'database.sql' || preg_match('/^backup-.*\\.sql$/', $base)) {
                $sqlEntry = $tmpDir . '/database.sql';
                @file_put_contents($sqlEntry, (string)$zip->getFromIndex($i));
                continue;
            }
            if (!$withFiles) continue;
            $rel = ltrim(str_replace('\\\\', '/', $entry), '/');
            $skip = false;
            foreach (self::KEEP as $k) if ($rel === $k || strpos($rel, $k . '/') === 0) $skip = true;
            if ($skip || self::skipPath($rel)) continue;
            $dest = APP_ROOT . '/' . $rel;
            $dd = dirname($dest);
            if (!is_dir($dd)) @mkdir($dd, 0775, true);
            if (@file_put_contents($dest, (string)$zip->getFromIndex($i)) !== false) $files++;
        }
        $zip->close();

        $msg = [];
        if ($files > 0) $msg[] = fa_num($files) . ' فایل بازگردانی شد';
        $ok = $files > 0;
        if ($sqlEntry !== '' && is_file($sqlEntry)) {
            $r = self::execSqlFile($sqlEntry);
            $ok = $ok || !empty($r['ok']);
            $msg[] = (string)$r['message'];
        }
        foreach ((array)glob($tmpDir . '/*') as $f) @unlink((string)$f);
        @rmdir($tmpDir);
        app_log('backup', 'restore zip ' . $name, ['files' => $files, 'ok' => $ok]);
        return ['ok' => $ok, 'message' => ($ok ? '✅ ' : '⚠️ ') . implode(' – ', $msg !== [] ? $msg : ['موردی برای بازگردانی پیدا نشد'])];
    }

    /** ذخیره فایل آپلود شده در پوشه بکاپ */
    public static function saveUpload(array $file): array
    {
        if (empty($file['name']) || !empty($file['error'])) return ['ok' => false, 'message' => 'فایلی آپلود نشد.'];
        $ext = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['sql', 'zip'], true)) return ['ok' => false, 'message' => 'فقط فایل SQL یا ZIP قابل قبول است.'];
        $name = 'upload-' . date('Ymd-His') . '.' . $ext;
        $dest = self::dir() . '/' . $name;
        if (!@move_uploaded_file((string)$file['tmp_name'], $dest)) {
            if (!@copy((string)$file['tmp_name'], $dest)) return ['ok' => false, 'message' => 'انتقال فایل آپلود شده ناموفق بود.'];
        }
        return ['ok' => true, 'name' => $name, 'path' => $dest, 'message' => '✅ فایل ' . $name . ' آپلود شد.'];
    }

    /* ==================== بکاپ خودکار (کرانجاب) ==================== */

    public static function due(): bool
    {
        if ((int)DB::setting('backup_auto', 1) !== 1) return false;
        $hours = max(1, (int)DB::setting('backup_hours', 6));
        $last  = (string)DB::setting('backup_last_at', '');
        return $last === '' || strtotime($last) < time() - $hours * 3600;
    }

    public static function autoRun(): array
    {
        if (!self::due()) return ['ok' => false, 'skipped' => true,
            'message' => 'زمان بکاپ خودکار نرسیده است (بعدی: ' . self::nextRun() . ').'];
        $type = (string)DB::setting('backup_type', 'db');
        $hours = max(1, (int)DB::setting('backup_hours', 6));
        return self::create($type === 'full' ? 'full' : 'db', 'خودکار – هر ' . fa_num($hours) . ' ساعت');
    }

    public static function nextRun(): string
    {
        $hours = max(1, (int)DB::setting('backup_hours', 6));
        $last  = (string)DB::setting('backup_last_at', '');
        if ($last === '') return 'در اولین اجرای کرانجاب';
        return to_jalali(date('Y-m-d H:i:s', strtotime($last) + $hours * 3600), true);
    }
}
