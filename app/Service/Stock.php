<?php
declare(strict_types=1);

/**
 * انبار ملی — فروش دستی کانفیگ، وایرگارد، اوپن‌وی‌پی‌ان و اکانت
 *
 * مدل کار: مدیر اقلام را از پیش درون انبار می‌ریزد؛ ربات هنگام خرید
 * یک قلم آزاد را برمی‌دارد، قفل می‌کند و تحویل می‌دهد. هیچ تماسی با پنل نیست.
 */
class Stock
{
    /* ================= ثابت‌ها ================= */

    /** نوع کالا => [برچسب، آیکون، پسوند فایل یا خالی] */
    public const KINDS = [
        'config'    => ['کانفیگ V2Ray / Xray', '⚡', ''],
        'sub'       => ['لینک اشتراک (Sub)', '🔗', ''],
        'wireguard' => ['WireGuard', '🔐', 'conf'],
        'openvpn'   => ['OpenVPN', '🛡', 'ovpn'],
        'account'   => ['اکانت / نام کاربری', '👤', ''],
        'file'      => ['فایل پیوست', '📎', ''],
        'text'      => ['متن / کد', '📝', ''],
    ];

    /** وضعیت قلم */
    public const ST = [
        'free'     => ['آزاد', '🟢'],
        'sold'     => ['فروخته‌شده', '🔵'],
        'hold'     => ['رزرو', '🟡'],
        'disabled' => ['غیرفعال', '⚪️'],
    ];

    /* ================= تنظیمات ================= */

    public static function enabled(): bool
    {
        return (string)DB::setting('stock_enabled', '1') === '1';
    }

    public static function title(): string
    {
        $t = trim((string)DB::setting('stock_title', ''));
        return $t !== '' ? $t : 'انبار ملی';
    }

    public static function icon(): string
    {
        $i = trim((string)DB::setting('stock_icon', ''));
        return $i !== '' ? $i : '🏪';
    }

    /** متن توضیح بالای فهرست */
    public static function note(): string
    {
        return trim((string)DB::setting('stock_note', ''));
    }

    /** آستانهٔ هشدار کمبود موجودی */
    public static function lowAlert(): int
    {
        return max(0, (int)DB::setting('stock_low_alert', 3));
    }

    /** آیا دسته‌های خالی هم نمایش داده شوند؟ */
    public static function showEmpty(): bool
    {
        return (string)DB::setting('stock_show_empty', '0') === '1';
    }

    /** حداکثر خرید همزمان از یک دسته (۰ = بی‌نهایت) */
    public static function perUser(): int
    {
        return max(0, (int)DB::setting('stock_per_user', 0));
    }

    /* ================= نمایندگی ================= */

    /** خرید از انبار برای نمایندگان فعال است؟ */
    public static function rsEnabled(): bool
    {
        return (string)DB::setting('stock_rs_enabled', '1') === '1';
    }

    /** سقف خرید نماینده از هر دسته (۰ = بدون محدودیت) */
    public static function rsPerUser(): int
    {
        return max(0, (int)DB::setting('stock_rs_per_user', 0));
    }

    /**
     * قیمت خرید نماینده از یک دسته.
     * اگر مدیر قیمت ویژه نگذاشته باشد، همان قیمت عمومی برمی‌گردد.
     */
    public static function rsPrice(array $c): int
    {
        $p = (int)($c['rs_price'] ?? 0);
        return $p > 0 ? $p : (int)($c['price'] ?? 0);
    }

    /** سود نماینده اگر به قیمت عمومی بفروشد */
    public static function rsProfit(array $c): int
    {
        return max(0, (int)($c['price'] ?? 0) - self::rsPrice($c));
    }

    /* ================= کمکی ================= */

    public static function kindLabel(string $k): string
    {
        return self::KINDS[$k][0] ?? $k;
    }

    public static function kindIcon(string $k): string
    {
        return self::KINDS[$k][1] ?? '📦';
    }

    /** پسوند فایل تحویلی — خالی یعنی تحویل متنی */
    public static function kindExt(string $k): string
    {
        return self::KINDS[$k][2] ?? '';
    }

    /** آیا این نوع به صورت فایل تحویل می‌شود؟ */
    public static function isFileKind(string $k): bool
    {
        return self::kindExt($k) !== '';
    }

    public static function stLabel(string $s): string
    {
        return self::ST[$s][0] ?? $s;
    }

    public static function stIcon(string $s): string
    {
        return self::ST[$s][1] ?? '•';
    }

    /**
     * تشخیص خودکار نوع از روی محتوا
     * — برای وقتی مدیر فقط متن را پیست می‌کند.
     */
    public static function detectKind(string $payload): string
    {
        $p = trim($payload);
        if ($p === '') return 'text';

        /* WireGuard: بلاک [Interface] و کلید خصوصی */
        if (preg_match('~\[\s*Interface\s*\]~i', $p) && preg_match('~PrivateKey\s*=~i', $p)) {
            return 'wireguard';
        }

        /* OpenVPN: بلاک‌های گواهی یا دستورهای معروف */
        if (preg_match('~<\s*(ca|cert|key|tls-auth|tls-crypt)\s*>~i', $p)
            || (preg_match('~^\s*client\s*$~mi', $p) && preg_match('~^\s*remote\s+~mi', $p))
            || preg_match('~^\s*dev\s+(tun|tap)~mi', $p)) {
            return 'openvpn';
        }

        /* لینک اشتراک */
        if (preg_match('~^https?://\S+$~i', $p) && !preg_match('~\s~', $p)) {
            return 'sub';
        }

        /* کانفیگ مستقیم */
        if (preg_match('~^(vless|vmess|trojan|ss|ssr|hy2|hysteria2?|tuic|socks)://~i', $p)) {
            return 'config';
        }

        /* اکانت: user:pass یا دو خطی کوتاه */
        if (preg_match('~^[^\s:]{2,40}:[^\s:]{2,60}$~', $p)) {
            return 'account';
        }

        return 'text';
    }

    /** نام فایل تحویلی امن */
    public static function fileName(array $it): string
    {
        $ext = self::kindExt((string)($it['kind'] ?? 'text'));
        if ($ext === '') $ext = 'txt';

        $base = trim((string)($it['title'] ?? ''));
        if ($base === '') $base = 'SR-' . (int)($it['id'] ?? 0);

        $base = preg_replace('~[^A-Za-z0-9._\-]+~', '-', $base);
        $base = trim((string)$base, '-.');
        if ($base === '') $base = 'SR-' . (int)($it['id'] ?? 0);

        return mb_substr($base, 0, 48) . '.' . $ext;
    }

    /* ================= دسته‌ها ================= */

    /** @return array<int,array> */
    public static function cats(bool $onlyActive = false): array
    {
        $w = $onlyActive ? 'WHERE active = 1' : '';
        return DB::all("SELECT * FROM {p}stock_cats $w ORDER BY sort ASC, id ASC");
    }

    public static function cat($id): ?array
    {
        $r = DB::one('SELECT * FROM {p}stock_cats WHERE id = :id', [':id' => (int)$id]);
        return $r ?: null;
    }

    /** دسته‌های قابل فروش همراه شمارش موجودی */
    public static function shopCats(): array
    {
        $out = [];
        foreach (self::cats(true) as $c) {
            $c['free'] = self::freeCount((int)$c['id']);
            if ($c['free'] < 1 && !self::showEmpty()) continue;
            $out[] = $c;
        }
        return $out;
    }

    public static function saveCat(array $d, int $id = 0): int
    {
        $row = [
            'name'        => mb_substr(trim((string)($d['name'] ?? '')), 0, 120),
            'icon'        => mb_substr(trim((string)($d['icon'] ?? '')), 0, 16),
            'kind'        => isset(self::KINDS[(string)($d['kind'] ?? '')]) ? (string)$d['kind'] : 'config',
            'description' => mb_substr(trim((string)($d['description'] ?? '')), 0, 1500),
            'price'       => max(0, (int)($d['price'] ?? 0)),
            'rs_price'    => max(0, (int)($d['rs_price'] ?? 0)),
            'old_price'   => max(0, (int)($d['old_price'] ?? 0)) ?: null,
            'days'        => max(0, (int)($d['days'] ?? 0)),
            'volume_gb'   => max(0, (float)($d['volume_gb'] ?? 0)),
            'guide'       => mb_substr(trim((string)($d['guide'] ?? '')), 0, 2000),
            'active'      => !empty($d['active']) ? 1 : 0,
            'sort'        => (int)($d['sort'] ?? 0),
        ];

        if ($row['name'] === '') $row['name'] = 'دستهٔ بدون نام';

        if ($id > 0) {
            DB::update('stock_cats', $row, 'id = :id', [':id' => $id]);
            return $id;
        }

        $row['created_at'] = now();
        return (int)DB::insert('stock_cats', $row);
    }

    /** حذف دسته همراه اقلام فروخته‌نشده */
    public static function delCat(int $id): array
    {
        $sold = (int)DB::val('SELECT COUNT(*) FROM {p}stock_items WHERE cat_id = :c AND status = \'sold\'',
            [':c' => $id], 0);

        DB::delete('stock_items', 'cat_id = :c AND status <> \'sold\'', [':c' => $id]);

        if ($sold > 0) {
            /* اقلام فروخته‌شده برای سوابق می‌مانند */
            DB::update('stock_items', ['cat_id' => 0], 'cat_id = :c', [':c' => $id]);
        }

        DB::delete('stock_cats', 'id = :id', [':id' => $id]);

        return ['ok' => true, 'kept' => $sold];
    }

    /* ================= اقلام ================= */

    public static function item($id): ?array
    {
        $r = DB::one('SELECT * FROM {p}stock_items WHERE id = :id', [':id' => (int)$id]);
        return $r ?: null;
    }

    /** شمارش اقلام آزاد یک دسته */
    public static function freeCount(int $catId): int
    {
        return (int)DB::val(
            "SELECT COUNT(*) FROM {p}stock_items WHERE cat_id = :c AND status = 'free'",
            [':c' => $catId], 0
        );
    }

    /**
     * فهرست اقلام با صافی
     * @param array $f cat, status, q, limit, offset
     */
    public static function items(array $f = []): array
    {
        $w = [];
        $p = [];

        if (!empty($f['cat']))    { $w[] = 'i.cat_id = :c';    $p[':c'] = (int)$f['cat']; }
        if (!empty($f['status'])) { $w[] = 'i.status = :s';    $p[':s'] = (string)$f['status']; }
        if (!empty($f['kind']))   { $w[] = 'i.kind = :k';      $p[':k'] = (string)$f['kind']; }
        if (!empty($f['user']))   { $w[] = 'i.user_id = :u';   $p[':u'] = (int)$f['user']; }

        if (!empty($f['q'])) {
            $w[] = '(i.title LIKE :q OR i.note LIKE :q OR i.payload LIKE :q)';
            $p[':q'] = '%' . trim((string)$f['q']) . '%';
        }

        $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
        $lim   = max(1, min(500, (int)($f['limit'] ?? 100)));
        $off   = max(0, (int)($f['offset'] ?? 0));

        return DB::all(
            "SELECT i.*, c.name AS cat_name, c.icon AS cat_icon
             FROM {p}stock_items i
             LEFT JOIN {p}stock_cats c ON c.id = i.cat_id
             $where
             ORDER BY i.id DESC
             LIMIT $lim OFFSET $off", $p
        );
    }

    public static function itemsCount(array $f = []): int
    {
        $w = [];
        $p = [];

        if (!empty($f['cat']))    { $w[] = 'cat_id = :c'; $p[':c'] = (int)$f['cat']; }
        if (!empty($f['status'])) { $w[] = 'status = :s'; $p[':s'] = (string)$f['status']; }
        if (!empty($f['kind']))   { $w[] = 'kind = :k';   $p[':k'] = (string)$f['kind']; }

        if (!empty($f['q'])) {
            $w[] = '(title LIKE :q OR note LIKE :q OR payload LIKE :q)';
            $p[':q'] = '%' . trim((string)$f['q']) . '%';
        }

        $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
        return (int)DB::val("SELECT COUNT(*) FROM {p}stock_items $where", $p, 0);
    }

    /** افزودن یک قلم */
    public static function addItem(int $catId, string $payload, array $opt = []): int
    {
        $c    = self::cat($catId);
        $kind = (string)($opt['kind'] ?? '');

        if ($kind === '' || !isset(self::KINDS[$kind])) {
            $kind = $c ? (string)$c['kind'] : self::detectKind($payload);
        }

        $row = [
            'cat_id'     => $catId,
            'kind'       => $kind,
            'title'      => mb_substr(trim((string)($opt['title'] ?? '')), 0, 120),
            'payload'    => trim($payload),
            'file_id'    => mb_substr(trim((string)($opt['file_id'] ?? '')), 0, 250) ?: null,
            'file_name'  => mb_substr(trim((string)($opt['file_name'] ?? '')), 0, 120) ?: null,
            'note'       => mb_substr(trim((string)($opt['note'] ?? '')), 0, 250),
            'price'      => max(0, (int)($opt['price'] ?? 0)),
            'status'     => 'free',
            'admin_id'   => (int)($opt['admin_id'] ?? 0) ?: null,
            'created_at' => now(),
        ];

        if ($row['title'] === '') {
            $row['title'] = self::kindIcon($kind) . ' ' . self::kindLabel($kind);
        }

        return (int)DB::insert('stock_items', $row);
    }

    /**
     * ورود گروهی — هر قلم با خط جداکننده یا خط خالی جدا می‌شود
     *
     * — کانفیگ/لینک/اکانت: هر خط یک قلم
     * — وایرگارد/اوپن‌وی‌پی‌ان/متن: با جداکنندهٔ --- یا خط خالی دوتایی
     */
    public static function addBulk(int $catId, string $blob, array $opt = []): array
    {
        $blob = str_replace(["\r\n", "\r"], "\n", trim($blob));
        if ($blob === '') return ['ok' => false, 'added' => 0, 'message' => 'متنی وارد نشده است.'];

        $c    = self::cat($catId);
        $kind = (string)($opt['kind'] ?? '');
        if ($kind === '' || !isset(self::KINDS[$kind])) {
            $kind = $c ? (string)$c['kind'] : 'config';
        }

        $parts = [];

        /* جداکنندهٔ صریح همیشه ارجحیت دارد */
        if (preg_match('~^\s*-{3,}\s*$~m', $blob)) {
            $parts = preg_split('~^\s*-{3,}\s*$~m', $blob) ?: [];
        } elseif (in_array($kind, ['wireguard', 'openvpn', 'text'], true)) {
            $parts = preg_split('~\n{2,}~', $blob) ?: [];
        } else {
            $parts = preg_split('~\n+~', $blob) ?: [];
        }

        $added = 0;
        $dup   = 0;
        $names = (array)($opt['titles'] ?? []);

        foreach ($parts as $i => $chunk) {
            $chunk = trim((string)$chunk);
            if ($chunk === '') continue;

            /* جلوگیری از تکرار در همین دسته */
            $exists = (int)DB::val(
                'SELECT COUNT(*) FROM {p}stock_items WHERE cat_id = :c AND payload = :p',
                [':c' => $catId, ':p' => $chunk], 0
            );
            if ($exists > 0) { $dup++; continue; }

            self::addItem($catId, $chunk, [
                'kind'     => $kind,
                'title'    => (string)($names[$i] ?? ''),
                'note'     => (string)($opt['note'] ?? ''),
                'price'    => (int)($opt['price'] ?? 0),
                'admin_id' => (int)($opt['admin_id'] ?? 0),
            ]);
            $added++;
        }

        $msg = '✅ ' . fa_num((string)$added) . ' قلم به انبار افزوده شد.';
        if ($dup > 0) $msg .= ' (' . fa_num((string)$dup) . ' مورد تکراری رد شد)';

        return ['ok' => $added > 0, 'added' => $added, 'dup' => $dup, 'message' => $msg];
    }

    /* ================= ورود از فایل ================= */

    /** بیشترین حجم مجاز هر فایل داخل زیپ */
    public const MAX_ENTRY = 2097152;

    /** پسوندهای متنی مجاز داخل زیپ */
    public const OK_EXT = ['txt', 'text', 'conf', 'ovpn', 'cfg', 'ini', 'json', 'yaml', 'yml', 'md', 'log', ''];

    /** یکسان‌سازی متن ورودی (BOM، سطر جدید، انکودینگ) */
    public static function cleanText(string $s): string
    {
        $s = str_replace(["\xEF\xBB\xBF", "\r\n", "\r"], ['', "\n", "\n"], $s);
        if (!mb_check_encoding($s, 'UTF-8')) {
            $c = @mb_convert_encoding($s, 'UTF-8', 'UTF-8, Windows-1256, ISO-8859-1');
            if (is_string($c)) $s = $c;
        }
        return trim($s);
    }

    /** تشخیص محتوای باینری */
    public static function isBinary(string $s): bool
    {
        if ($s === '') return false;
        if (strpos(substr($s, 0, 8000), "\0") !== false) return true;
        return !mb_check_encoding($s, 'UTF-8');
    }

    /** افزودن یک قلم با کنترل تکراری در همان دسته */
    public static function addUnique(int $catId, string $payload, array $opt = []): bool
    {
        $payload = trim($payload);
        if ($payload === '') return false;

        $exists = (int)DB::val(
            'SELECT COUNT(*) FROM {p}stock_items WHERE cat_id = :c AND payload = :p',
            [':c' => $catId, ':p' => $payload], 0
        );
        if ($exists > 0) return false;

        self::addItem($catId, $payload, $opt);
        return true;
    }

    /** نام پایهٔ امن از روی نام فایل */
    public static function safeBase(string $name): string
    {
        $base = (string)pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('~[^\p{L}\p{N}._\- ]+~u', '-', $base);
        $base = trim((string)$base, '-._ ');
        return mb_substr($base, 0, 60);
    }

    /** حدس نوع کالا از روی پسوند و محتوا */
    public static function kindByExt(string $ext, string $body = ''): string
    {
        $ext = strtolower(ltrim($ext, '.'));
        if ($ext === 'conf') return 'wireguard';
        if ($ext === 'ovpn') return 'openvpn';
        return $body !== '' ? self::detectKind($body) : 'text';
    }

    /** سقف آپلود سرور بر حسب بایت (۰ = نامشخص) */
    public static function maxUpload(): int
    {
        $to = static function (string $v): int {
            $v = trim($v);
            if ($v === '') return 0;
            $u = strtolower(substr($v, -1));
            $n = (int)$v;
            if ($u === 'g') return $n * 1073741824;
            if ($u === 'm') return $n * 1048576;
            if ($u === 'k') return $n * 1024;
            return $n;
        };
        $a = $to((string)ini_get('upload_max_filesize'));
        $b = $to((string)ini_get('post_max_size'));
        $vals = array_filter([$a, $b]);
        return $vals ? (int)min($vals) : 0;
    }

    /**
     * ورود از فایل متنی (.txt / .conf / .ovpn / …)
     *
     * mode = lines → هر خط (یا هر بلاک) یک قلم
     * mode = file  → کل فایل یک قلم (مناسب وایرگارد و اوپن‌وی‌پی‌ان)
     */
    public static function importTxt(int $catId, string $content, array $opt = []): array
    {
        $content = self::cleanText($content);
        if ($content === '') {
            return ['ok' => false, 'added' => 0, 'dup' => 0, 'skipped' => 0, 'files' => 1,
                    'message' => '⚠️ فایل خالی است یا محتوای قابل خواندنی ندارد.'];
        }
        if (self::isBinary($content)) {
            return ['ok' => false, 'added' => 0, 'dup' => 0, 'skipped' => 1, 'files' => 1,
                    'message' => '⚠️ این فایل متنی نیست. برای فایل‌های آماده از حالت زیپ استفاده کنید.'];
        }

        $mode = (string)($opt['mode'] ?? 'lines');

        /* در حالت خطی، خطوط توضیحی حذف می‌شوند */
        if ($mode !== 'file') {
            $keep = [];
            foreach (explode("\n", $content) as $ln) {
                $t = ltrim($ln);
                if ($t !== '' && ($t[0] === '#' || strncmp($t, '//', 2) === 0)) continue;
                $keep[] = $ln;
            }
            $content = trim(implode("\n", $keep));
            if ($content === '') {
                return ['ok' => false, 'added' => 0, 'dup' => 0, 'skipped' => 0, 'files' => 1,
                        'message' => '⚠️ بعد از حذف خطوط توضیحی چیزی برای افزودن نماند.'];
            }
        }

        if ($mode === 'file') {
            $kind = (string)($opt['kind'] ?? '');
            if ($kind === '' || !isset(self::KINDS[$kind])) {
                $c    = self::cat($catId);
                $kind = $c ? (string)$c['kind'] : self::detectKind($content);
            }
            $base = self::safeBase((string)($opt['file_name'] ?? ''));
            $fx   = self::kindExt($kind) ?: 'txt';

            $ok = self::addUnique($catId, $content, [
                'kind'      => $kind,
                'title'     => $base,
                'note'      => (string)($opt['note'] ?? ''),
                'file_name' => ($base !== '' ? $base : 'stock') . '.' . $fx,
                'admin_id'  => (int)($opt['admin_id'] ?? 0),
            ]);

            return ['ok' => $ok, 'added' => $ok ? 1 : 0, 'dup' => $ok ? 0 : 1, 'skipped' => 0, 'files' => 1,
                    'message' => $ok ? '✅ ۱ قلم از فایل به انبار افزوده شد.'
                                     : '⚠️ محتوای این فایل قبلاً در همین دسته ثبت شده بود.'];
        }

        $r = self::addBulk($catId, $content, $opt);
        $r['skipped'] = 0;
        $r['files']   = 1;
        return $r;
    }

    /**
     * ورود از فایل ZIP — هر فایل داخل زیپ یک قلم انبار
     *
     * mode = file  → هر فایل داخل زیپ یک قلم (پیش‌فرض)
     * mode = lines → فایل‌های txt خط‌به‌خط باز می‌شوند، بقیه یک قلم
     */
    public static function importZip(int $catId, string $zipPath, array $opt = []): array
    {
        $fail = static function (string $m): array {
            return ['ok' => false, 'added' => 0, 'dup' => 0, 'skipped' => 0, 'files' => 0, 'message' => $m];
        };

        if (!class_exists('ZipArchive')) return $fail('⚠️ افزونهٔ ZipArchive روی این هاست فعال نیست.');
        if (!is_file($zipPath))          return $fail('⚠️ فایل زیپ پیدا نشد.');

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) return $fail('⚠️ فایل زیپ باز نشد یا خراب است.');

        $mode   = (string)($opt['mode'] ?? 'file');
        $note   = (string)($opt['note'] ?? '');
        $kindIn = (string)($opt['kind'] ?? '');
        $admin  = (int)($opt['admin_id'] ?? 0);

        $added = 0; $dup = 0; $skip = 0; $files = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stt = $zip->statIndex($i);
            if (!$stt) continue;

            $nm = (string)($stt['name'] ?? '');
            if ($nm === '' || substr($nm, -1) === '/') continue;
            if (stripos($nm, '__MACOSX') !== false) continue;

            $bn = basename(str_replace('\\', '/', $nm));
            if ($bn === '' || $bn[0] === '.') continue;

            $files++;

            if ((int)($stt['size'] ?? 0) > self::MAX_ENTRY) { $skip++; continue; }

            $ext = strtolower((string)pathinfo($bn, PATHINFO_EXTENSION));
            if (!in_array($ext, self::OK_EXT, true)) { $skip++; continue; }

            $body = $zip->getFromIndex($i);
            if (!is_string($body)) { $skip++; continue; }

            $body = self::cleanText($body);
            if ($body === '' || self::isBinary($body)) { $skip++; continue; }

            /* فهرست کانفیگ داخل زیپ */
            if ($mode === 'lines' && in_array($ext, ['txt', 'text', ''], true)) {
                $r = self::addBulk($catId, $body, [
                    'kind'     => $kindIn,
                    'note'     => $note,
                    'admin_id' => $admin,
                ]);
                $added += (int)($r['added'] ?? 0);
                $dup   += (int)($r['dup'] ?? 0);
                continue;
            }

            $kind = ($kindIn !== '' && isset(self::KINDS[$kindIn])) ? $kindIn : self::kindByExt($ext, $body);
            $base = self::safeBase($bn);
            $fx   = self::kindExt($kind) ?: ($ext !== '' ? $ext : 'txt');

            $ok = self::addUnique($catId, $body, [
                'kind'      => $kind,
                'title'     => $base,
                'note'      => $note,
                'file_name' => ($base !== '' ? $base : 'stock-' . $i) . '.' . $fx,
                'admin_id'  => $admin,
            ]);

            if ($ok) { $added++; } else { $dup++; }
        }

        $zip->close();

        if ($files === 0) return $fail('⚠️ زیپ خالی بود یا فایل متنی معتبری نداشت.');

        $msg = '✅ ' . fa_num((string)$added) . ' قلم از ' . fa_num((string)$files) . ' فایل داخل زیپ افزوده شد.';
        if ($dup > 0)  $msg .= ' · ' . fa_num((string)$dup) . ' تکراری رد شد';
        if ($skip > 0) $msg .= ' · ' . fa_num((string)$skip) . ' فایل نامعتبر رد شد';

        return ['ok' => $added > 0, 'added' => $added, 'dup' => $dup, 'skipped' => $skip,
                'files' => $files, 'message' => $msg];
    }

    public static function delItem(int $id): bool
    {
        DB::delete('stock_items', 'id = :id', [':id' => $id]);
        return true;
    }

    /** حذف گروهی اقلام فروخته‌شدهٔ قدیمی */
    public static function purgeSold(int $days = 90): int
    {
        $n = (int)DB::val(
            'SELECT COUNT(*) FROM {p}stock_items WHERE status = \'sold\' AND sold_at < DATE_SUB(NOW(), INTERVAL :d DAY)',
            [':d' => $days], 0
        );
        if ($n > 0) {
            DB::q('DELETE FROM {p}stock_items WHERE status = \'sold\' AND sold_at < DATE_SUB(NOW(), INTERVAL :d DAY)',
                [':d' => $days]);
        }
        return $n;
    }

    public static function setStatus(int $id, string $st): bool
    {
        if (!isset(self::ST[$st])) return false;

        $d = ['status' => $st];
        if ($st === 'free') {
            $d['user_id'] = null;
            $d['tg_id']   = null;
            $d['order_id'] = null;
            $d['sold_at'] = null;
        }

        DB::update('stock_items', $d, 'id = :id', [':id' => $id]);
        return true;
    }

    /* ================= فروش ================= */

    /**
     * برداشت اتمیک یک قلم آزاد — ضد فروش همزمان
     */
    public static function take(int $catId, int $userId, int $tgId, int $orderId = 0): ?array
    {
        $row = DB::one(
            "SELECT * FROM {p}stock_items WHERE cat_id = :c AND status = 'free' ORDER BY id ASC LIMIT 1",
            [':c' => $catId]
        );
        if (!$row) return null;

        /* قفل شرطی: فقط اگر هنوز آزاد باشد */
        $n = DB::q(
            "UPDATE {p}stock_items
                SET status = 'sold', user_id = :u, tg_id = :t, order_id = :o, sold_at = :now
              WHERE id = :id AND status = 'free'",
            [':u' => $userId, ':t' => $tgId, ':o' => $orderId ?: null, ':now' => now(), ':id' => (int)$row['id']]
        );

        $aff = is_object($n) && method_exists($n, 'rowCount') ? (int)$n->rowCount() : 1;
        if ($aff < 1) {
            /* یکی دیگر زودتر گرفت — یک‌بار دوباره تلاش کن */
            $row2 = DB::one(
                "SELECT * FROM {p}stock_items WHERE cat_id = :c AND status = 'free' ORDER BY id ASC LIMIT 1",
                [':c' => $catId]
            );
            if (!$row2) return null;

            DB::q(
                "UPDATE {p}stock_items
                    SET status = 'sold', user_id = :u, tg_id = :t, order_id = :o, sold_at = :now
                  WHERE id = :id AND status = 'free'",
                [':u' => $userId, ':t' => $tgId, ':o' => $orderId ?: null, ':now' => now(), ':id' => (int)$row2['id']]
            );
            $row = $row2;
        }

        return self::item((int)$row['id']);
    }

    /** شمارش خریدهای کاربر از یک دسته */
    public static function boughtCount(int $userId, int $catId): int
    {
        return (int)DB::val(
            "SELECT COUNT(*) FROM {p}stock_items WHERE user_id = :u AND cat_id = :c AND status = 'sold'",
            [':u' => $userId, ':c' => $catId], 0
        );
    }

    /** خریدهای کاربر */
    public static function myItems(int $userId, int $limit = 40): array
    {
        return DB::all(
            "SELECT i.*, c.name AS cat_name, c.icon AS cat_icon, c.guide AS cat_guide
             FROM {p}stock_items i
             LEFT JOIN {p}stock_cats c ON c.id = i.cat_id
             WHERE i.user_id = :u AND i.status = 'sold'
             ORDER BY i.sold_at DESC, i.id DESC
             LIMIT " . max(1, min(200, $limit)),
            [':u' => $userId]
        );
    }

    /**
     * خرید کامل — کسر از کیف پول و تحویل
     * @return array{ok:bool,message:string,item?:array,cat?:array,price?:int}
     */
    public static function buy(array $user, int $catId, bool $asReseller = false): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'message' => 'این بخش در حال حاضر غیرفعال است.'];
        }

        if ($asReseller && !self::rsEnabled()) {
            return ['ok' => false, 'message' => 'خرید از انبار برای نمایندگان فعال نیست.'];
        }

        $c = self::cat($catId);
        if (!$c || (int)$c['active'] !== 1) {
            return ['ok' => false, 'message' => 'این محصول در دسترس نیست.'];
        }

        $free = self::freeCount($catId);
        if ($free < 1) {
            return ['ok' => false, 'message' => '⚠️ موجودی این محصول تمام شده است. لطفاً بعداً تلاش کنید.'];
        }

        $cap = $asReseller ? self::rsPerUser() : self::perUser();
        if ($cap > 0 && self::boughtCount((int)$user['id'], $catId) >= $cap) {
            return ['ok' => false, 'message' => 'سقف خرید شما از این محصول (' . fa_num((string)$cap) . ') پر شده است.'];
        }

        $price = $asReseller ? self::rsPrice($c) : (int)$c['price'];
        $bal   = (int)Wallet::balance((int)$user['id']);

        if ($price > 0 && $bal < $price) {
            return [
                'ok'      => false,
                'need'    => $price - $bal,
                'message' => '⚠️ موجودی کافی نیست.' . "\n"
                    . 'قیمت: ' . money($price) . ' ' . currency() . "\n"
                    . 'موجودی شما: ' . money($bal) . ' ' . currency() . "\n"
                    . 'کسری: ' . money($price - $bal) . ' ' . currency(),
            ];
        }

        /* سفارش */
        $orderId = (int)DB::insert('orders', [
            'user_id'      => (int)$user['id'],
            'tg_id'        => (int)$user['tg_id'],
            'type'         => 'stock',
            'amount'       => $price,
            'final_amount' => $price,
            'status'       => 'pending',
            'created_at'   => now(),
        ]);

        $it = self::take($catId, (int)$user['id'], (int)$user['tg_id'], $orderId);

        if (!$it) {
            DB::update('orders', ['status' => 'failed'], 'id = :id', [':id' => $orderId]);
            return ['ok' => false, 'message' => '⚠️ آخرین موجودی همین لحظه فروخته شد. دوباره تلاش کنید.'];
        }

        if ($price > 0) {
            Wallet::debit((int)$user['id'], $price,
                ($asReseller ? 'خرید نمایندگی از ' : 'خرید از ') . self::title() . ' – ' . (string)$c['name'], 'stock');
        }

        DB::update('orders', ['status' => 'paid'], 'id = :id', [':id' => $orderId]);
        DB::q('UPDATE {p}stock_cats SET sold = sold + 1 WHERE id = :id', [':id' => $catId]);

        /* لاگ */
        if (class_exists('Logs')) {
            Logs::event('purchases', '🏪 فروش از ' . self::title(), [
                'کاربر'     => '<code>' . (int)$user['tg_id'] . '</code>',
                'دسته'      => (string)$c['name'],
                'نوع'        => self::kindLabel((string)$it['kind']),
                'قیمت'      => money($price) . ' ' . currency(),
                'شمارهٔ قلم' => '<code>#' . (int)$it['id'] . '</code>',
                'مانده در انبار' => fa_num((string)max(0, $free - 1)),
            ]);
        }

        /* هشدار کمبود موجودی */
        $left = self::freeCount($catId);
        if (self::lowAlert() > 0 && $left <= self::lowAlert() && class_exists('AdminBot')) {
            AdminBot::notifyAdmins(
                '⚠️ <b>کمبود موجودی در ' . h(self::title()) . "</b>\n"
                . 'دسته: <b>' . h((string)$c['name']) . "</b>\n"
                . 'باقی‌مانده: <b>' . fa_num((string)$left) . '</b> قلم'
            );
        }

        return ['ok' => true, 'message' => '✅ خرید انجام شد.', 'item' => $it, 'cat' => $c, 'price' => $price,
            'profit' => $asReseller ? max(0, (int)$c['price'] - $price) : 0];
    }

    /* ================= تحویل ================= */

    /** متن تحویل یک قلم */
    public static function caption(array $it, ?array $c = null): string
    {
        $c = $c ?: self::cat((int)($it['cat_id'] ?? 0));
        $kind = (string)($it['kind'] ?? 'text');

        $t = self::kindIcon($kind) . ' <b>' . h((string)($c['name'] ?? self::kindLabel($kind))) . "</b>\n";
        $t .= "<code>\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80</code>\n";

        if (!empty($it['title'])) $t .= '🏷 عنوان: <b>' . h((string)$it['title']) . "</b>\n";

        if ($c) {
            if ((int)($c['days'] ?? 0) > 0)      $t .= '⏱ مدت: <b>' . fa_num((string)(int)$c['days']) . "</b> روز\n";
            if ((float)($c['volume_gb'] ?? 0) > 0) $t .= '📊 حجم: <b>' . fa_num((string)(float)$c['volume_gb']) . "</b> گیگ\n";
        }

        $t .= '🔢 کد پیگیری: <code>#' . (int)$it['id'] . "</code>\n";

        if (!empty($it['note'])) $t .= "\nℹ️ " . h((string)$it['note']) . "\n";

        return $t;
    }

    /**
     * تحویل قلم در تلگرام — فایل یا متن
     */
    public static function deliver($chatId, array $it, ?array $c = null): bool
    {
        $c    = $c ?: self::cat((int)($it['cat_id'] ?? 0));
        $kind = (string)($it['kind'] ?? 'text');
        $cap  = self::caption($it, $c);

        /* ۱) فایل از پیش آپلودشده در تلگرام */
        if (!empty($it['file_id'])) {
            $r = Tg::media($chatId, (string)$it['file_id'], 'document', $cap);
            if (!empty($r['ok'])) { self::afterDeliver($chatId, $c); return true; }
        }

        /* ۲) وایرگارد / اوپن‌وی‌پی‌ان → ساخت فایل و ارسال */
        if (self::isFileKind($kind) && trim((string)$it['payload']) !== '') {
            if (self::sendAsFile($chatId, $it, $cap)) { self::afterDeliver($chatId, $c); return true; }
        }

        /* ۳) تحویل متنی */
        $body = trim((string)$it['payload']);
        $txt  = $cap;

        if ($body !== '') {
            $txt .= "\n<code>" . h($body) . '</code>';
        }

        if (function_exists('str_split_unicode_safe') && mb_strlen($txt) > 3800) {
            foreach (str_split_unicode_safe($txt, 3600) as $chunk) Tg::send($chatId, $chunk);
        } else {
            Tg::send($chatId, $txt);
        }

        self::afterDeliver($chatId, $c);
        return true;
    }

    /** راهنمای دسته پس از تحویل */
    private static function afterDeliver($chatId, ?array $c): void
    {
        if (!$c) return;
        $g = trim((string)($c['guide'] ?? ''));
        if ($g === '') return;

        Tg::send($chatId, "🎓 <b>راهنمای اتصال</b>\n" . nl2br(h($g)));
    }

    /** ساخت فایل موقت و ارسال به عنوان سند */
    public static function sendAsFile($chatId, array $it, string $caption): bool
    {
        $dir = rtrim(APP_ROOT, '/') . '/storage/tmp';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (!is_dir($dir) || !is_writable($dir)) return false;

        $name = !empty($it['file_name']) ? (string)$it['file_name'] : self::fileName($it);
        $path = $dir . '/' . uniqid('stk-', true) . '-' . $name;

        if (@file_put_contents($path, (string)$it['payload']) === false) return false;

        $ok = false;
        try {
            $r = Tg::api('sendDocument', [
                'chat_id'    => $chatId,
                'caption'    => mb_substr($caption, 0, 1000),
                'parse_mode' => 'HTML',
                'document'   => new CURLFile($path, 'text/plain', $name),
            ], true);
            $ok = !empty($r['ok']);
        } catch (\Throwable $e) {
            $ok = false;
        }

        @unlink($path);
        return $ok;
    }

    /* ================= آمار ================= */

    public static function stats(): array
    {
        $row = DB::one(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'free' THEN 1 ELSE 0 END) AS free,
                SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) AS sold,
                SUM(CASE WHEN status = 'hold' THEN 1 ELSE 0 END) AS hold,
                SUM(CASE WHEN status = 'disabled' THEN 1 ELSE 0 END) AS disabled
             FROM {p}stock_items"
        ) ?: [];

        $today = (int)DB::val(
            "SELECT COUNT(*) FROM {p}stock_items WHERE status = 'sold' AND DATE(sold_at) = CURDATE()", [], 0
        );

        $cats = (int)DB::val('SELECT COUNT(*) FROM {p}stock_cats', [], 0);

        return [
            'total'    => (int)($row['total'] ?? 0),
            'free'     => (int)($row['free'] ?? 0),
            'sold'     => (int)($row['sold'] ?? 0),
            'hold'     => (int)($row['hold'] ?? 0),
            'disabled' => (int)($row['disabled'] ?? 0),
            'today'    => $today,
            'cats'     => $cats,
        ];
    }

    /** دسته‌های کم‌موجود */
    public static function lowStock(): array
    {
        $out = [];
        $al  = self::lowAlert();
        if ($al < 1) return $out;

        foreach (self::cats(true) as $c) {
            $f = self::freeCount((int)$c['id']);
            if ($f <= $al) {
                $c['free'] = $f;
                $out[] = $c;
            }
        }
        return $out;
    }

    /** درآمد کل انبار */
    public static function revenue(int $days = 30): int
    {
        return (int)DB::val(
            "SELECT COALESCE(SUM(o.final_amount), 0)
               FROM {p}orders o
              WHERE o.type = 'stock' AND o.status = 'paid'
                AND o.created_at >= DATE_SUB(NOW(), INTERVAL :d DAY)",
            [':d' => max(1, $days)], 0
        );
    }
}
