<?php
declare(strict_types=1);

/**
 * گزارش‌گیری در گروه لاگ با تاپیک‌های جداگانه (Forum Topics)
 * هر رویداد در تاپیک مربوط به خودش ارسال می‌شود.
 *
 * بهبودهای این نسخه:
 *  • ترمیم خودکار تاپیکِ بسته یا حذف‌شده (بازکردن / ساخت دوباره) به‌جای ریختن گزارش‌ها در General
 *  • مدیریت محدودیت نرخ تلگرام (۴۲۹ retry_after) و تلاش مجدد
 *  • تقسیم پیام‌های بلند به چند بخش به‌جای بریدن متن
 *  • صف آفلاین: اگر تلگرام در دسترس نباشد گزارش ذخیره و با کرانجاب ارسال می‌شود
 *  • جلوگیری از تکرار پیام یکسان در بازهٔ کوتاه (ضد اسپمِ تاپیک خطاها)
 *  • ارسال بی‌صدا برای تاپیک‌های پرترافیک
 *  • آمار ارسال/خطا و آخرین خطا برای صفحهٔ تنظیمات و سلامت سیستم
 */
class Logs
{
    /** کلید => [عنوان تاپیک، رنگ آیکون] */
    public const TOPICS = [
        'nightly'   => ['🌙 گزارش شبانه', 9367192],
        'purchases' => ['🛍 گزارش خرید‌ها', 9367192],
        'services'  => ['🚀 گزارش خرید خدمات', 7322096],
        'financial' => ['💰 گزارش مالی', 16766590],
        'test'      => ['🔑 گزارش اکانت تست', 13338331],
        'panels'    => ['🖥 گزارش پنل‌ها', 7322096],
        'referral'  => ['🎁 گزارش پورسانت‌ها', 13338331],
        'broadcast' => ['📣 گزارش اطلاع‌رسانی‌ها', 16766590],
        'tickets'   => ['🆘 گزارش پشتیبانی', 16749490],
        'users'     => ['👤 سایر گزارشات', 16749490],
        'errors'    => ['❌ گزارش خطاها', 16478047],
        'backup'    => ['📦 بکاپ دیتابیس', 16478047],
        'resellers' => ['🏷 گزارش نمایندگان', 7322096],
        'cards'     => ['💳 گزارش احراز کارت', 16766590],
        'wallet'    => ['👛 گزارش کیف پول', 16766590],
        'security'  => ['🛡 گزارش امنیت و ورود', 16478047],
        'cron'      => ['⏱ گزارش کرانجاب', 9367192],
    ];

    /** تاپیک‌های پرترافیک که پیش‌فرض بی‌صدا ارسال می‌شوند */
    public const SILENT_DEFAULT = ['nightly', 'cron', 'panels'];

    /** حداکثر گزارش نگه‌داشته‌شده در صف آفلاین */
    public const QUEUE_MAX = 300;

    /** سقف امن متن هر پیام */
    private const CHUNK = 3500;

    /* ============================================================ تنظیمات */

    public static function enabled(): bool
    {
        return (string)DB::setting('log_enabled', '0') === '1' && self::chat() !== '';
    }

    public static function chat(): string
    {
        return trim((string)DB::setting('log_chat_id', ''));
    }

    public static function threads(): array
    {
        $t = jdec((string)DB::setting('log_topics', ''), []);
        return is_array($t) ? $t : [];
    }

    public static function thread(string $key): ?int
    {
        $t = self::threads();
        $v = (int)($t[$key] ?? 0);
        return $v > 0 ? $v : null;
    }

    public static function setThread(string $key, int $threadId): void
    {
        $t = self::threads();
        if ($threadId > 0) $t[$key] = $threadId; else unset($t[$key]);
        DB::setSetting('log_topics', jenc($t));
    }

    /** رویدادهای غیرفعال‌شده */
    public static function off(): array
    {
        $t = jdec((string)DB::setting('log_events_off', ''), []);
        return is_array($t) ? $t : [];
    }

    public static function on(string $key): bool
    {
        return !in_array($key, self::off(), true);
    }

    public static function setOff(array $keys): void
    {
        DB::setSetting('log_events_off', jenc(array_values(array_unique($keys))));
    }

    /** تاپیک‌های بی‌صدا */
    public static function silent(): array
    {
        $raw = trim((string)DB::setting('log_silent', ''));
        if ($raw === '') return self::SILENT_DEFAULT;
        if ($raw === '-') return [];
        $t = jdec($raw, []);
        return is_array($t) ? array_values(array_filter(array_map('strval', $t))) : [];
    }

    public static function setSilent(array $keys): void
    {
        $keys = array_values(array_unique(array_filter($keys)));
        DB::setSetting('log_silent', $keys ? jenc($keys) : '-');
    }

    public static function isSilent(string $key): bool
    {
        return in_array($key, self::silent(), true);
    }

    public static function label(string $key): string
    {
        return (string)(self::TOPICS[$key][0] ?? $key);
    }

    /* ============================================================ قالب پیام */

    /**
     * قالب گزارش‌های تلگرام
     * کلیدهای ویژه در $kv:
     *   '---'        → خط جداکننده
     *   '## عنوان'   → عنوان بخش
     *   مقدار آرایه‌ای → فهرست چندخطی
     *   مقدار با پیشوند ` → متن تک‌عرضی (code)
     */
    public static function fmt(string $title, array $kv = [], string $footer = ''): string
    {
        $line = "――――――――――――――\n";
        $out  = '<b>' . h($title) . "</b>\n" . $line;

        foreach ($kv as $k => $v) {
            $ks = (string)$k;

            if ($ks === '---' || $v === '---') { $out .= $line; continue; }

            if (strpos($ks, '##') === 0) {
                $out .= "\n<b>" . h(trim(substr($ks, 2))) . "</b>\n";
                continue;
            }

            if (is_array($v)) {
                $out .= '◾️ <b>' . h($ks) . "</b>\n";
                foreach ($v as $row) $out .= '     • ' . h((string)$row) . "\n";
                continue;
            }

            if (is_bool($v)) $v = $v ? '✅ بله' : '✖️ خیر';
            if ($v === null || $v === '') $v = '—';

            $vs = (string)$v;
            if (strpos($vs, '`') === 0) {
                $out .= '• ' . h($ks) . ': <code>' . h(ltrim($vs, '`')) . "</code>\n";
            } else {
                $out .= '• ' . h($ks) . ': <b>' . h($vs) . "</b>\n";
            }
        }

        $out .= $line . '🕒 ' . h(to_jalali(now(), true));
        if (defined('APP_BRAND') && (string)APP_BRAND !== '') $out .= '  •  ' . h((string)APP_BRAND);
        if ($footer !== '') $out .= "\n" . $footer;
        return $out;
    }

    /** میانبر: قالب‌بندی + ارسال در یک فراخوانی */
    public static function event(string $key, string $title, array $kv = [], string $footer = ''): bool
    {
        return self::send($key, self::fmt($title, $kv, $footer));
    }

    /** گزارش خطا همراه جزئیات فنی */
    public static function err(string $where, string $message, array $kv = []): bool
    {
        return self::event('errors', '❌ خطا در ' . $where, array_merge([
            'پیام' => '`' . $message,
        ], $kv));
    }

    /** متن بلند (سازگاری با نسخه‌های قبل) – تقسیم در خودِ send انجام می‌شود */
    public static function big(string $key, string $text): bool
    {
        return self::send($key, $text);
    }

    /* ============================================================ ارسال */

    /** ارسال گزارش متنی در تاپیک مربوطه */
    public static function send(string $key, string $text, array $extra = []): bool
    {
        if (!self::enabled() || !self::on($key)) return false;
        if (trim($text) === '') return false;
        if (self::isDuplicate($key, $text)) return true;

        $parts = function_exists('str_split_unicode_safe')
            ? str_split_unicode_safe($text, self::CHUNK)
            : [mb_substr($text, 0, self::CHUNK)];

        $n  = count($parts);
        $ok = true;
        foreach ($parts as $i => $part) {
            if ($n > 1) $part .= "\n\n— بخش " . fa_num((string)($i + 1)) . ' از ' . fa_num((string)$n);
            $ok = self::deliver($key, $part, $extra) && $ok;
            if ($n > 1 && $i < $n - 1) usleep(350000);
        }
        return $ok;
    }

    /** ارسال واقعی یک تکه پیام همراه ترمیم خودکار تاپیک */
    private static function deliver(string $key, string $text, array $extra = [], bool $allowQueue = true): bool
    {
        $params = array_merge([
            'chat_id'                  => self::chat(),
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra);

        if (self::isSilent($key) && !isset($params['disable_notification'])) {
            $params['disable_notification'] = true;
        }

        $tid = self::ensureTopic($key);
        if ($tid > 0) {
            $params['message_thread_id'] = $tid;
        } else {
            /* تاپیکی در کار نیست → دست‌کم دسته‌بندی گزارش مشخص باشد */
            $params['text'] = '<b>' . h(self::label($key)) . "</b>\n" . $params['text'];
        }

        $res = self::call('sendMessage', $params);

        /* تاپیک بسته یا حذف شده → بازکردن یا ساخت دوباره و ارسال مجدد */
        if (empty($res['ok']) && $tid > 0 && self::isTopicError($res)) {
            $new = self::recoverTopic($key, $tid);
            if ($new > 0) {
                $params['message_thread_id'] = $new;
            } else {
                unset($params['message_thread_id']);
                $params['text'] = '<b>' . h(self::label($key)) . "</b>\n" . $text;
            }
            $res = self::call('sendMessage', $params);
        }

        /* HTML خراب → ارسال به‌صورت متن ساده تا گزارش از دست نرود */
        if (empty($res['ok']) && self::isParseError($res)) {
            unset($params['parse_mode']);
            $params['text'] = self::plain((string)$params['text']);
            $res = self::call('sendMessage', $params);
        }

        if (!empty($res['ok'])) { self::bump('ok'); return true; }

        self::bump('err', (string)($res['description'] ?? ''));
        if ($allowQueue && self::isTransient($res)) self::queuePush($key, $text, $extra);
        return false;
    }

    /** فراخوانی تلگرام با مدیریت محدودیت نرخ (۴۲۹) */
    private static function call(string $method, array $params, bool $multipart = false): array
    {
        $res  = Tg::api($method, $params, $multipart);
        if (!empty($res['ok'])) return $res;

        $wait = (int)($res['parameters']['retry_after'] ?? 0);
        $max  = PHP_SAPI === 'cli' ? 25 : 3;
        if ($wait > 0 && $wait <= $max) {
            sleep($wait);
            $res = Tg::api($method, $params, $multipart);
        }
        return $res;
    }

    /* ============================================================ تاپیک‌ها */

    /** شناسه تاپیک؛ در صورت نبود، ساخت خودکار */
    public static function ensureTopic(string $key): int
    {
        $tid = (int)(self::thread($key) ?? 0);
        if ($tid > 0) return $tid;
        if (!isset(self::TOPICS[$key])) return 0;
        if ((string)DB::setting('log_auto_topic', '1') !== '1') return 0;

        /* اگر گروه فوروم نیست، تا یک ساعت دوباره تلاش نکن */
        $retryAt = (int)DB::setting('log_topic_retry', '0');
        if ($retryAt > time()) return 0;

        return self::createTopic($key);
    }

    private static function createTopic(string $key): int
    {
        $title = (string)(self::TOPICS[$key][0] ?? $key);
        $color = (int)(self::TOPICS[$key][1] ?? 9367192);

        $r = Tg::api('createForumTopic', [
            'chat_id'    => self::chat(),
            'name'       => mb_substr($title, 0, 120),
            'icon_color' => $color,
        ]);

        $tid = (int)($r['result']['message_thread_id'] ?? 0);
        if ($tid > 0) {
            self::setThread($key, $tid);
            DB::setSetting('log_topic_retry', '0');
            return $tid;
        }

        DB::setSetting('log_topic_retry', (string)(time() + 3600));
        app_log('logs', 'createForumTopic failed', [
            'key'  => $key,
            'desc' => (string)($r['description'] ?? ''),
        ]);
        return 0;
    }

    /** تلاش برای بازکردن تاپیک بسته، در غیر این صورت ساخت دوباره */
    private static function recoverTopic(string $key, int $tid): int
    {
        $r = Tg::api('reopenForumTopic', [
            'chat_id'           => self::chat(),
            'message_thread_id' => $tid,
        ]);
        $d = strtolower((string)($r['description'] ?? ''));
        if (!empty($r['ok']) || strpos($d, 'not modified') !== false) return $tid;

        self::setThread($key, 0);
        return self::createTopic($key);
    }

    /** وضعیت گروه لاگ */
    public static function chatInfo(): array
    {
        if (self::chat() === '') return ['ok' => false, 'message' => 'شناسه گروه تنظیم نشده است.'];
        $r = Tg::api('getChat', ['chat_id' => self::chat()]);
        if (empty($r['ok'])) {
            return ['ok' => false, 'message' => 'دسترسی به گروه ممکن نیست: ' . (string)($r['description'] ?? '')];
        }
        $c = $r['result'] ?? [];
        return [
            'ok' => true,
            'title' => (string)($c['title'] ?? '-'),
            'type' => (string)($c['type'] ?? '-'),
            'is_forum' => !empty($c['is_forum']),
            'message' => 'گروه: ' . (string)($c['title'] ?? '-') . (!empty($c['is_forum']) ? ' (تاپیک فعال ✅)' : ' (حالت تاپیک غیرفعال ⚠)'),
        ];
    }

    /**
     * ساخت خودکار تاپیک‌های گزارش در گروه
     * @param bool $rebuild ساخت مجدد همه تاپیک‌ها حتی اگر قبلاً ساخته شده‌اند
     */
    public static function setupTopics(bool $rebuild = false): array
    {
        $info = self::chatInfo();
        if (empty($info['ok'])) return ['ok' => false, 'message' => $info['message']];
        if (empty($info['is_forum'])) {
            return ['ok' => false, 'message' => 'این گروه حالت Topics فعال ندارد. از تنظیمات گروه گزینه Topics را روشن کنید.'];
        }

        $created = 0; $kept = 0; $failed = [];
        foreach (self::TOPICS as $key => [$title, $color]) {
            if (!$rebuild && self::thread($key)) { $kept++; continue; }
            if ($rebuild) self::setThread($key, 0);
            if (self::createTopic($key) > 0) {
                $created++;
                usleep(300000);
            } else {
                $failed[$key] = 'ساخت تاپیک ناموفق';
            }
        }

        $msg = 'تاپیک‌ها: ' . fa_num((string)$created) . ' ساخته شد، ' . fa_num((string)$kept) . ' موجود بود.';
        if ($failed) $msg .= ' خطا در ' . fa_num((string)count($failed)) . ' مورد: ' . implode(' | ', array_slice(array_keys($failed), 0, 3));

        if ($created > 0) {
            DB::setSetting('log_enabled', '1');
            self::send('users', self::fmt('✅ گروه گزارشات فعال شد', [
                'فروشگاه' => (string)DB::setting('shop_title', '-'),
                'تاپیک‌ها' => count(self::threads()),
            ]));
        }
        return ['ok' => empty($failed), 'message' => $msg, 'created' => $created, 'failed' => $failed];
    }

    /**
     * بررسی و ترمیم تاپیک‌ها:
     *  • تاپیک سالم → عنوان و رنگ هم‌گام می‌شود
     *  • تاپیک حذف/بسته → باز یا دوباره ساخته می‌شود
     *  • تاپیک نداشته → ساخته می‌شود
     */
    public static function repairTopics(): array
    {
        $info = self::chatInfo();
        if (empty($info['ok'])) return ['ok' => false, 'message' => $info['message']];
        if (empty($info['is_forum'])) {
            return ['ok' => false, 'message' => 'این گروه حالت Topics فعال ندارد؛ همهٔ گزارش‌ها با برچسب دسته در خودِ گروه ارسال می‌شوند.'];
        }

        $healthy = 0; $repaired = 0; $created = 0; $failed = [];

        foreach (self::TOPICS as $key => [$title, $color]) {
            $tid = (int)(self::thread($key) ?? 0);

            if ($tid <= 0) {
                if (self::createTopic($key) > 0) $created++; else $failed[] = $key;
                usleep(250000);
                continue;
            }

            $r = Tg::api('editForumTopic', [
                'chat_id'           => self::chat(),
                'message_thread_id' => $tid,
                'name'              => mb_substr($title, 0, 120),
            ]);
            $d = strtolower((string)($r['description'] ?? ''));

            if (!empty($r['ok']) || strpos($d, 'not modified') !== false) { $healthy++; }
            elseif (self::isTopicError(['description' => $d])) {
                if (self::recoverTopic($key, $tid) > 0) $repaired++; else $failed[] = $key;
            } else {
                $failed[] = $key;
            }
            usleep(250000);
        }

        $msg = '🩺 بررسی تاپیک‌ها: ' . fa_num((string)$healthy) . ' سالم، '
            . fa_num((string)$repaired) . ' ترمیم شد، ' . fa_num((string)$created) . ' ساخته شد';
        if ($failed) $msg .= '، ' . fa_num((string)count($failed)) . ' ناموفق (' . implode(', ', array_slice($failed, 0, 3)) . ')';

        return ['ok' => empty($failed), 'message' => $msg,
            'healthy' => $healthy, 'repaired' => $repaired, 'created' => $created, 'failed' => $failed];
    }

    /* ============================================================ فایل */

    /**
     * ارسال خودِ فایل (نه پیوند) در تاپیک
     * مایم‌تایپ و نام فایل صریح اعلام می‌شود تا تلگرام آن را به‌صورت سند پیوست کند.
     */
    public static function doc(string $key, string $path, string $caption = ''): bool
    {
        if (!self::enabled() || !self::on($key)) return false;

        if (!is_file($path) || !is_readable($path)) {
            app_log('logs', 'doc: file missing or unreadable', ['path' => $path]);
            return false;
        }

        $size = (int)@filesize($path);
        if ($size <= 0) {
            app_log('logs', 'doc: empty file', ['path' => $path]);
            return false;
        }

        /* سقف ارسال فایل ربات تلگرام = ۵۰ مگابایت */
        if ($size > 49 * 1024 * 1024) {
            self::send($key, self::fmt('⚠️ فایل بزرگ‌تر از سقف تلگرام است', [
                'نام فایل'  => '`' . basename($path),
                'حجم'       => human_bytes($size),
                'سقف مجاز'  => '۵۰ مگابایت',
                'راهکار'    => 'از پنل مدیریت ‹ پشتیبان‌گیری › فایل را دانلود کنید',
            ]));
            return false;
        }

        $ext  = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        $mime = $ext === 'zip'  ? 'application/zip'
            : ($ext === 'sql'  ? 'application/sql'
            : ($ext === 'json' ? 'application/json'
            : (($ext === 'log' || $ext === 'txt') ? 'text/plain' : 'application/octet-stream')));

        $build = function (?int $tid) use ($path, $mime, $caption, $key): array {
            $p = [
                'chat_id'    => self::chat(),
                'document'   => new CURLFile($path, $mime, basename($path)),
                'caption'    => mb_substr($caption, 0, 1000),
                'parse_mode' => 'HTML',
            ];
            if ($tid) $p['message_thread_id'] = $tid;
            if (self::isSilent($key)) $p['disable_notification'] = true;
            return $p;
        };

        $tid = self::ensureTopic($key);
        $res = self::call('sendDocument', $build($tid > 0 ? $tid : null), true);

        /* تاپیک گم شده → ترمیم و تلاش دوباره */
        if (empty($res['ok']) && $tid > 0 && self::isTopicError($res)) {
            $new = self::recoverTopic($key, $tid);
            $res = self::call('sendDocument', $build($new > 0 ? $new : null), true);
        }

        if (empty($res['ok'])) {
            self::bump('err', (string)($res['description'] ?? ''));
            app_log('logs', 'sendDocument failed', [
                'file' => basename($path),
                'size' => $size,
                'desc' => (string)($res['description'] ?? ''),
            ]);
            return false;
        }

        self::bump('ok');
        return true;
    }

    /* ============================================================ صف آفلاین */

    private static function tmpFile(string $name): string
    {
        $dir = APP_ROOT . '/storage/tmp';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/' . $name;
    }

    private static function readJson(string $file): array
    {
        $raw = @file_get_contents($file);
        $v   = jdec(is_string($raw) ? $raw : '', []);
        return is_array($v) ? $v : [];
    }

    private static function writeJson(string $file, array $data): void
    {
        @file_put_contents($file, jenc($data), LOCK_EX);
    }

    private static function queuePush(string $key, string $text, array $extra = []): void
    {
        $file = self::tmpFile('log-queue.json');
        $q    = self::readJson($file);
        $q[]  = ['k' => $key, 't' => $text, 'e' => $extra, 'at' => time()];
        if (count($q) > self::QUEUE_MAX) $q = array_slice($q, -self::QUEUE_MAX);
        self::writeJson($file, $q);
    }

    public static function queueSize(): int
    {
        return count(self::readJson(self::tmpFile('log-queue.json')));
    }

    public static function queueClear(): void
    {
        @unlink(self::tmpFile('log-queue.json'));
    }

    /** ارسال گزارش‌های معلق (در کرانجاب یا از پنل) */
    public static function flushQueue(int $limit = 25): array
    {
        $file = self::tmpFile('log-queue.json');
        $q    = self::readJson($file);
        if (!$q) return ['sent' => 0, 'left' => 0, 'dropped' => 0];
        if (!self::enabled()) return ['sent' => 0, 'left' => count($q), 'dropped' => 0];

        $sent = 0; $dropped = 0; $keep = []; $tries = 0;
        $oldest = time() - 86400;

        foreach ($q as $item) {
            $key  = (string)($item['k'] ?? 'users');
            $text = (string)($item['t'] ?? '');
            $at   = (int)($item['at'] ?? 0);

            if ($text === '' || $at < $oldest) { $dropped++; continue; }
            if ($tries >= $limit) { $keep[] = $item; continue; }

            $tries++;
            if (self::deliver($key, $text, (array)($item['e'] ?? []), false)) {
                $sent++;
                usleep(350000);
            } else {
                $keep[] = $item;
            }
        }

        if ($keep) self::writeJson($file, $keep); else @unlink($file);
        return ['sent' => $sent, 'left' => count($keep), 'dropped' => $dropped];
    }

    /* ============================================================ ضد تکرار و آمار */

    private static function isDuplicate(string $key, string $text): bool
    {
        $min = (int)DB::setting('log_dedup_min', '3');
        if ($min <= 0) return false;

        /* مهر زمانی انتهای پیام در امضا حساب نمی‌شود */
        $norm = (string)preg_replace('/🕒.*$/us', '', $text);
        $norm = trim((string)preg_replace('/\s+/u', ' ', $norm));
        if ($norm === '') return false;

        $sig  = $key . ':' . md5($norm);
        $file = self::tmpFile('log-dedup.json');
        $map  = self::readJson($file);
        $now  = time();

        foreach ($map as $k => $ts) if ((int)$ts < $now - 86400) unset($map[$k]);

        if (isset($map[$sig]) && (int)$map[$sig] > $now - ($min * 60)) return true;

        $map[$sig] = $now;
        if (count($map) > 400) $map = array_slice($map, -400, null, true);
        self::writeJson($file, $map);
        return false;
    }

    private static function bump(string $kind, string $desc = ''): void
    {
        $file = self::tmpFile('log-stats.json');
        $s    = self::readJson($file);
        $day  = date('Y-m-d');

        if ((string)($s['day'] ?? '') !== $day) {
            $s = ['day' => $day, 'ok' => 0, 'err' => 0,
                'last_err' => (string)($s['last_err'] ?? ''), 'last_err_at' => (string)($s['last_err_at'] ?? '')];
        }

        $s[$kind] = (int)($s[$kind] ?? 0) + 1;
        if ($kind === 'err' && $desc !== '') {
            $s['last_err']    = mb_substr($desc, 0, 200);
            $s['last_err_at'] = now();
        }
        if ($kind === 'ok') $s['last_ok_at'] = now();

        self::writeJson($file, $s);
    }

    /** آمار امروز + آخرین خطا + اندازه صف */
    public static function stats(): array
    {
        $s = self::readJson(self::tmpFile('log-stats.json'));
        return [
            'day'         => (string)($s['day'] ?? date('Y-m-d')),
            'ok'          => (int)($s['ok'] ?? 0),
            'err'         => (int)($s['err'] ?? 0),
            'last_ok_at'  => (string)($s['last_ok_at'] ?? ''),
            'last_err'    => (string)($s['last_err'] ?? ''),
            'last_err_at' => (string)($s['last_err_at'] ?? ''),
            'queue'       => self::queueSize(),
        ];
    }

    /* ============================================================ تست‌ها */

    /** تست ارسال در یک تاپیک */
    public static function testOne(string $key): array
    {
        if (!isset(self::TOPICS[$key])) return ['ok' => false, 'message' => 'تاپیک ناشناخته است.'];
        if (!self::enabled())           return ['ok' => false, 'message' => 'ارسال گزارش‌ها خاموش است یا شناسه گروه خالی است.'];
        if (!self::on($key))            return ['ok' => false, 'message' => 'این رویداد غیرفعال است؛ اول تیک «ارسال شود» را بزنید.'];

        $ok = self::deliver($key, self::fmt('🧪 پیام تست – ' . self::label($key), [
            'تاپیک'  => '`' . (string)(self::thread($key) ?? 0),
            'حالت'   => self::isSilent($key) ? 'بی‌صدا' : 'با اعلان',
        ]), [], false);

        $st = self::stats();
        return ['ok' => $ok, 'message' => $ok
            ? '✅ پیام تست در «' . self::label($key) . '» ارسال شد.'
            : '❌ ارسال تست ناموفق بود: ' . ((string)$st['last_err'] !== '' ? $st['last_err'] : 'خطای نامشخص')];
    }

    /** تست ارسال در همه تاپیک‌ها */
    public static function testAll(): array
    {
        $ok = 0; $bad = 0;
        foreach (array_keys(self::TOPICS) as $key) {
            if (!self::on($key)) continue;
            if (self::deliver($key, '🧪 پیام تست – ' . self::label($key) . "\n" . h(to_jalali(now(), true)), [], false)) $ok++; else $bad++;
            usleep(250000);
        }
        $st  = self::stats();
        $msg = 'ارسال موفق: ' . fa_num((string)$ok) . ' – ناموفق: ' . fa_num((string)$bad);
        if ($bad > 0 && (string)$st['last_err'] !== '') $msg .= ' | آخرین خطا: ' . $st['last_err'];
        return ['ok' => $bad === 0, 'message' => $msg];
    }

    /* ============================================================ تشخیص خطا */

    private static function isTopicError(array $res): bool
    {
        $d = strtolower((string)($res['description'] ?? ''));
        if ($d === '') return false;
        return strpos($d, 'thread') !== false
            || strpos($d, 'topic') !== false;
    }

    private static function isParseError(array $res): bool
    {
        $d = strtolower((string)($res['description'] ?? ''));
        return strpos($d, 'parse entities') !== false
            || strpos($d, 'unsupported start tag') !== false
            || strpos($d, 'unclosed start tag') !== false
            || strpos($d, 'tag is not closed') !== false;
    }

    /** خطاهای گذرا: شبکه، محدودیت نرخ، خطای سرور تلگرام */
    private static function isTransient(array $res): bool
    {
        $code = (int)($res['error_code'] ?? 0);
        if ($code === 429 || $code >= 500) return true;

        $d = strtolower((string)($res['description'] ?? ''));
        if ($d === '') return true;

        foreach (['timed out', 'timeout', 'could not resolve', 'resolve host', 'connection',
            'empty response', 'too many requests', 'bad gateway', 'ssl', 'temporarily'] as $needle) {
            if (strpos($d, $needle) !== false) return true;
        }
        return false;
    }

    private static function plain(string $text): string
    {
        $t = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $t = strip_tags((string)$t);
        $t = html_entity_decode((string)$t, ENT_QUOTES, 'UTF-8');
        return mb_substr(trim($t), 0, 3900);
    }
}
