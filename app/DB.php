<?php
declare(strict_types=1);

/**
 * لایه دیتابیس (PDO) با پشتیبانی از پیشوند جدول
 * در تمام کوئری‌ها به جای نام جدول از {p}table استفاده کنید.
 */
class DB
{
    private static ?PDO $pdo = null;
    private static string $prefix = '';
    private static array $settings = [];
    private static bool $loaded = false;
    private static ?PDO $pdoEmu = null;
    private static array $cfg = [];

    public static function init(array $cfg): void
    {
        self::$prefix = (string)($cfg['prefix'] ?? '');
        self::$cfg    = $cfg;
        $host = (string)($cfg['host'] ?? 'localhost');
        $port = (int)($cfg['port'] ?? 3306);
        $name = (string)($cfg['name'] ?? '');
        $dsn  = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        self::$pdo = new PDO($dsn, (string)($cfg['user'] ?? ''), (string)($cfg['pass'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public static function connected(): bool { return self::$pdo instanceof PDO; }

    public static function pdo(): PDO
    {
        if (!self::$pdo) throw new RuntimeException('اتصال به دیتابیس برقرار نشده است.');
        return self::$pdo;
    }

    public static function prefix(): string { return self::$prefix; }

    public static function raw(string $sql): string { return str_replace('{p}', self::$prefix, $sql); }

    /**
     * اتصال کمکی با prepare شبیه‌سازی‌شده
     * برخی نسخه‌های MySQL/MariaDB هنگام فرستادن مقدارهای بزرگ (مانند JSON دکمه‌ها)
     * با prepare واقعی خطای مبهم «General error: 2000» می‌دهند؛ در این حالت یک بار روی این اتصال تکرار می‌کنیم.
     */
    private static function emu(): ?PDO
    {
        if (self::$pdoEmu instanceof PDO) return self::$pdoEmu;
        if (!self::$cfg) return null;
        try {
            $cfg = self::$cfg;
            $dsn = 'mysql:host=' . (string)($cfg['host'] ?? 'localhost')
                . ';port=' . (int)($cfg['port'] ?? 3306)
                . ';dbname=' . (string)($cfg['name'] ?? '') . ';charset=utf8mb4';
            self::$pdoEmu = new PDO($dsn, (string)($cfg['user'] ?? ''), (string)($cfg['pass'] ?? ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => true,
            ]);
        } catch (Throwable $e) {
            self::$pdoEmu = null;
        }
        return self::$pdoEmu;
    }

    /** خطاهایی که ارزش تکرار دارند (مشکل پروتکل/مقدار بزرگ، نه خطای منطقی) */
    private static function retryable(PDOException $e): bool
    {
        $code = (int)($e->errorInfo[1] ?? 0);
        if (in_array($code, [2000, 1153, 2006, 2013], true)) return true;
        $m = $e->getMessage();
        return stripos($m, 'Unknown or undefined error code') !== false
            || stripos($m, 'gone away') !== false;
    }

    /** افزودن کوئری و محل واقعی فراخوانی به متن خطا تا عیب‌یابی راحت شود */
    private static function wrap(PDOException $e, string $sql): PDOException
    {
        $short = trim((string)preg_replace('/\s+/', ' ', $sql));
        if (mb_strlen($short) > 200) $short = mb_substr($short, 0, 200) . '...';

        $at = '';
        foreach ($e->getTrace() as $fr) {
            $f = (string)($fr['file'] ?? '');
            if ($f === '' || basename($f) === 'DB.php') continue;
            $at = basename($f) . ':' . (int)($fr['line'] ?? 0);
            break;
        }

        $msg = $e->getMessage() . ' | SQL: ' . $short . ($at !== '' ? ' | فراخوانی از: ' . $at : '');
        $new = new PDOException($msg, 0, $e);
        $new->errorInfo = $e->errorInfo;
        return $new;
    }
    /**
     * اتصال تازه به دیتابیس
     * وقتی یک دستور اتصال را خراب می‌کند (خطاهای مبهم مانند 2000) یک اتصال نو ساخته می‌شود
     * تا بقیٔ کارهای همان درخواست از کار نیفتند. کش تنظیمات دست نمی‌خورد.
     */
    public static function reconnect(): bool
    {
        if (!self::$cfg) return false;
        try {
            self::$pdo    = null;
            self::$pdoEmu = null;
            self::init(self::$cfg);
            return self::$pdo instanceof PDO;
        } catch (Throwable $e) {
            if (function_exists('app_log')) app_log('db', 'reconnect failed: ' . $e->getMessage());
            return false;
        }
    }

    /** آخرین تلاش: اتصال نو و اجرای دوبارهٔ همان کوئری */
    private static function retryFresh(string $raw, array $params): ?PDOStatement
    {
        if (!self::reconnect()) return null;
        try {
            $st = self::pdo()->prepare($raw);
            $st->execute($params);
            if (function_exists('app_log')) {
                app_log('db', 'retry-reconnect | ' . mb_substr(trim((string)preg_replace('/\s+/', ' ', $raw)), 0, 160));
            }
            return $st;
        } catch (Throwable $e) {
            return null;
        }
    }


    public static function q(string $sql, array $params = []): PDOStatement
    {
        $raw = self::raw($sql);
        try {
            $st = self::pdo()->prepare($raw);
            $st->execute($params);
            return $st;
        } catch (PDOException $e) {
            $inTx = false;
            try { $inTx = self::pdo()->inTransaction(); } catch (Throwable $x) {}

            if (!$inTx && self::retryable($e)) {
                $emu = self::emu();
                if ($emu instanceof PDO) {
                    try {
                        $st = $emu->prepare($raw);
                        $st->execute($params);
                        if (function_exists('app_log')) {
                            app_log('db', 'retry-emulate | ' . mb_substr(trim((string)preg_replace('/\s+/', ' ', $raw)), 0, 160));
                        }
                        return $st;
                    } catch (PDOException $e2) {
                        $fresh = self::retryFresh($raw, $params);
                        if ($fresh instanceof PDOStatement) return $fresh;
                        throw self::wrap($e2, $raw);
                    }
                }
            }
            if (!$inTx && self::retryable($e)) {
                $fresh = self::retryFresh($raw, $params);
                if ($fresh instanceof PDOStatement) return $fresh;
            }
            throw self::wrap($e, $raw);
        }
    }

    public static function all(string $sql, array $params = []): array { return self::q($sql, $params)->fetchAll(); }

    public static function one(string $sql, array $params = []): ?array
    {
        $r = self::q($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $params = [], $default = null)
    {
        $r = self::q($sql, $params)->fetchColumn();
        return $r === false ? $default : $r;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $ph   = array_map(fn($c) => ':' . $c, $cols);
        $sql  = 'INSERT INTO {p}' . $table . ' (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $ph) . ')';
        self::q($sql, array_combine($ph, array_values($data)));
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $sets = [];
        $bind = [];
        foreach ($data as $k => $v) { $sets[] = "`$k` = :s_$k"; $bind[":s_$k"] = $v; }
        $sql = 'UPDATE {p}' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        return self::q($sql, array_merge($bind, $params))->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        return self::q('DELETE FROM {p}' . $table . ' WHERE ' . $where, $params)->rowCount();
    }

    /* ---------- تنظیمات ---------- */

    public static function loadSettings(bool $force = false): array
    {
        if (self::$loaded && !$force) return self::$settings;
        self::$settings = [];
        try {
            foreach (self::all('SELECT `k`,`v` FROM {p}settings') as $row) {
                $v = $row['v'];
                /* 0.0.2 #2: مقدارهای رمزنگاری‌شده هنگام خواندن باز می‌شوند */
                if (is_string($v) && strncmp($v, 'enc:v1:', 7) === 0 && class_exists('Crypt')) {
                    $v = Crypt::dec($v);
                }
                self::$settings[$row['k']] = $v;
            }
        } catch (Throwable $e) { /* جدول هنوز ساخته نشده */ }
        self::$loaded = true;
        return self::$settings;
    }

    public static function setting(string $key, $default = null)
    {
        self::loadSettings();
        $v = self::$settings[$key] ?? null;
        if ($v === null || $v === '') return $default;
        return $v;
    }

    public static function setSetting(string $key, $value): void
    {
        self::loadSettings();
        $value = is_array($value) ? jenc($value) : (string)$value;
        if (strlen($value) > 60000 && function_exists('app_log')) {
            app_log('db', 'big-setting ' . $key . ' = ' . strlen($value) . 'B');
        }
        /* 0.0.2 #2: اسرار (توکن، کلید درگاه، رمز پنل و …) رمزنگاری‌شده ذخیره می‌شوند */
        $store = $value;
        if ($value !== '' && self::isSecretKey($key) && class_exists('Crypt')
            && Crypt::available() && !Crypt::isEnc($value)) {
            $store = Crypt::enc($value);
        }

        self::q('INSERT INTO {p}settings (`k`,`v`) VALUES (:k,:v) ON DUPLICATE KEY UPDATE `v` = :v2', [
            ':k' => $key, ':v' => $store, ':v2' => $store,
        ]);
        self::$settings[$key] = $value;
    }

    /** آیا این کلید تنظیمات «راز» است و باید رمزنگاری شود؟ */
    public static function isSecretKey(string $k): bool
    {
        return (bool)preg_match(
            '/(^|_)(token|secret|apikey|api_key|password|passwd|pass|merchant|privkey|private_key)(_|$)/i',
            $k
        );
    }

    /** رمزنگاری یک‌بارهٔ اسرارِ قدیمی که به‌صورت متن ساده ذخیره شده‌اند */
    public static function encryptExistingSecrets(): int
    {
        if (!class_exists('Crypt') || !Crypt::available() || !Crypt::hasKey()) return 0;

        $n = 0;
        foreach (self::all('SELECT `k`,`v` FROM {p}settings') as $row) {
            $k = (string)$row['k'];
            $v = (string)$row['v'];
            if ($v === '' || Crypt::isEnc($v) || !self::isSecretKey($k)) continue;
            self::setSetting($k, $v);
            $n++;
        }

        return $n;
    }

    /** اجرای فایل SQL (نصب/به‌روزرسانی) */
    public static function runSqlFile(string $file): void
    {
        $sql = (string)file_get_contents($file);
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $sql = self::raw($sql);
        foreach (explode(";\n", $sql) as $chunk) {
            $stmt = trim($chunk);
            // خطوط توضیحی ابتدای هر دستور را جدا می‌کنیم تا خود دستور اجرا شود
            while ($stmt !== '' && (str_starts_with($stmt, '--') || str_starts_with($stmt, '#'))) {
                $nl   = strpos($stmt, "\n");
                $stmt = $nl === false ? '' : trim(substr($stmt, $nl + 1));
            }
            $stmt = trim(rtrim($stmt, ';'));
            if ($stmt === '') continue;
            self::pdo()->exec($stmt);
        }
    }
}
