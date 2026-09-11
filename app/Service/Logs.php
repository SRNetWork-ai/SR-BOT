<?php
declare(strict_types=1);

/**
 * گزارش‌گیری در گروه لاگ با تاپیک‌های جداگانه (Forum Topics)
 * هر رویداد در تاپیک مربوط به خودش ارسال می‌شود.
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

    /** قالب پیام کلید-مقدار */
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

    /** متن بلند: در صورت عبور از سقف تلگرام، تکه‌تکه ارسال می‌شود */
    public static function big(string $key, string $text): bool
    {
        $parts = function_exists('str_split_unicode_safe')
            ? str_split_unicode_safe($text, 3600)
            : [mb_substr($text, 0, 3600)];

        $ok = true;
        $n  = count($parts);
        foreach ($parts as $i => $part) {
            $suffix = $n > 1 ? "\n\n— بخش " . fa_num($i + 1) . ' از ' . fa_num($n) : '';
            $ok = self::send($key, $part . $suffix) && $ok;
        }
        return $ok;
    }

    /** ارسال گزارش متنی در تاپیک */
    public static function send(string $key, string $text, array $extra = []): bool
    {
        if (!self::enabled() || !self::on($key)) return false;

        $params = array_merge([
            'chat_id'    => self::chat(),
            'text'       => mb_substr($text, 0, 3900),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra);

        $tid = self::thread($key);
        if ($tid) $params['message_thread_id'] = $tid;

        $res = Tg::api('sendMessage', $params);
        if (empty($res['ok']) && $tid) {
            // تاپیک حذف یا گم شده – یک‌بار بدون تاپیک تلاش می‌کنیم
            $desc = strtolower((string)($res['description'] ?? ''));
            if (strpos($desc, 'thread') !== false || strpos($desc, 'topic') !== false) {
                self::setThread($key, 0);
                unset($params['message_thread_id']);
                $res = Tg::api('sendMessage', $params);
            }
        }
        return !empty($res['ok']);
    }

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

        $params = [
            'chat_id'    => self::chat(),
            'document'   => new CURLFile($path, $mime, basename($path)),
            'caption'    => mb_substr($caption, 0, 1000),
            'parse_mode' => 'HTML',
        ];

        $tid = self::thread($key);
        if ($tid) $params['message_thread_id'] = $tid;

        $res = Tg::api('sendDocument', $params, true);

        /* تاپیک گم شده – یک‌بار بدون تاپیک تلاش می‌کنیم */
        if (empty($res['ok']) && $tid) {
            $desc = strtolower((string)($res['description'] ?? ''));
            if (strpos($desc, 'thread') !== false || strpos($desc, 'topic') !== false) {
                self::setThread($key, 0);
                unset($params['message_thread_id']);
                $params['document'] = new CURLFile($path, $mime, basename($path));
                $res = Tg::api('sendDocument', $params, true);
            }
        }

        if (empty($res['ok'])) {
            app_log('logs', 'sendDocument failed', [
                'file' => basename($path),
                'size' => $size,
                'desc' => (string)($res['description'] ?? ''),
            ]);
        }

        return !empty($res['ok']);
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
            $r = Tg::api('createForumTopic', [
                'chat_id'    => self::chat(),
                'name'       => mb_substr($title, 0, 120),
                'icon_color' => $color,
            ]);
            if (!empty($r['ok']) && !empty($r['result']['message_thread_id'])) {
                self::setThread($key, (int)$r['result']['message_thread_id']);
                $created++;
                usleep(300000);
            } else {
                $failed[$key] = (string)($r['description'] ?? 'unknown');
            }
        }

        $msg = 'تاپیک‌ها: ' . fa_num((string)$created) . ' ساخته شد، ' . fa_num((string)$kept) . ' موجود بود.';
        if ($failed) $msg .= ' خطا در ' . fa_num((string)count($failed)) . ' مورد: ' . implode(' | ', array_slice($failed, 0, 3));

        if ($created > 0) {
            DB::setSetting('log_enabled', '1');
            self::send('users', self::fmt('✅ گروه گزارشات فعال شد', [
                'فروشگاه' => (string)DB::setting('shop_title', '-'),
                'تاپیک‌ها' => count(self::threads()),
            ]));
        }
        return ['ok' => empty($failed), 'message' => $msg, 'created' => $created, 'failed' => $failed];
    }

    /** تست ارسال در همه تاپیک‌ها */
    public static function testAll(): array
    {
        $ok = 0; $bad = 0;
        foreach (array_keys(self::TOPICS) as $key) {
            if (self::send($key, '🧪 پیام تست – ' . (self::TOPICS[$key][0] ?? $key) . "\n" . to_jalali(now(), true))) $ok++; else $bad++;
        }
        return ['ok' => $bad === 0, 'message' => 'ارسال موفق: ' . fa_num((string)$ok) . ' – ناموفق: ' . fa_num((string)$bad)];
    }
}
