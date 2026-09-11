<?php
declare(strict_types=1);

/**
 * پیام همگانی پیشرفته
 *  - ارسال متن، عکس، ویدیو، فایل، گیف، ویس و استیکر
 *  - دکمه‌های شیشه‌ای دلخواه
 *  - دسته‌بندی مخاطبان (خریدار، بدون خرید، سرویس فعال، منقضی، …)
 *  - ارسال دسته‌ای با مکث برای جلوگیری از محدودیت تلگرام
 */
class Broadcast
{
    /** دسته‌بندی مخاطبان: [برچسب، شرط SQL] */
    public const SEGMENTS = [
        'all'        => ['label' => '👥 همهٔ کاربران',        'where' => 'is_banned = 0'],
        'buyers'     => ['label' => '💰 فقط خریداران',        'where' => 'is_banned = 0 AND total_paid > 0'],
        'nobuy'      => ['label' => '🛒 بدون خرید',            'where' => 'is_banned = 0 AND (total_paid IS NULL OR total_paid = 0)'],
        'active'     => ['label' => '✅ دارای سرویس فعال',   'where' => "is_banned = 0 AND id IN (SELECT user_id FROM {p}services WHERE status = 'active')"],
        'expired'    => ['label' => '⏰ سرویس منقضی‌شده',   'where' => "is_banned = 0 AND id IN (SELECT user_id FROM {p}services WHERE status = 'expired') AND id NOT IN (SELECT user_id FROM {p}services WHERE status = 'active')"],
        'balance'    => ['label' => '👛 دارای موجودی',       'where' => 'is_banned = 0 AND balance > 0'],
        'testonly'   => ['label' => '🧪 فقط اکانت تست',      'where' => 'is_banned = 0 AND test_count > 0 AND (total_paid IS NULL OR total_paid = 0)'],
        'recent'     => ['label' => '🆕 عضو ۷ روز اخیر',      'where' => 'is_banned = 0 AND created_at >= (NOW() - INTERVAL 7 DAY)'],
        'active30'   => ['label' => '🔥 فعال ۳۰ روز اخیر',     'where' => 'is_banned = 0 AND last_seen >= (NOW() - INTERVAL 30 DAY)'],
        'sleeping'   => ['label' => '💤 غیرفعال بیش از ۳۰ روز', 'where' => 'is_banned = 0 AND (last_seen IS NULL OR last_seen < (NOW() - INTERVAL 30 DAY))'],
        'banned'     => ['label' => '🚫 کاربران مسدود',      'where' => 'is_banned = 1'],
    ];

    /** نوع محتوای قابل ارسال */
    public const KINDS = [
        'text'      => ['label' => '📝 فقط متن',   'method' => 'sendMessage'],
        'photo'     => ['label' => '🖼 عکس',        'method' => 'sendPhoto'],
        'video'     => ['label' => '🎥 ویدیو',      'method' => 'sendVideo'],
        'document'  => ['label' => '📎 فایل',        'method' => 'sendDocument'],
        'animation' => ['label' => '🎭 گیف',         'method' => 'sendAnimation'],
        'voice'     => ['label' => '🎤 ویس',         'method' => 'sendVoice'],
        'audio'     => ['label' => '🎵 موزیک',      'method' => 'sendAudio'],
        'sticker'   => ['label' => '😀 استیکر',     'method' => 'sendSticker'],
    ];

    public static function batch(): int { return max(1, min(100, (int)DB::setting('bc_batch', 25))); }
    public static function sleepMs(): int { return max(0, (int)DB::setting('bc_sleep', 1)); }

    /** شرط SQL یک دسته */
    public static function whereFor(string $seg): string
    {
        $w = self::SEGMENTS[$seg]['where'] ?? self::SEGMENTS['all']['where'];
        return str_replace('{p}', DB::prefix(), (string)$w);
    }

    /** تعداد مخاطبان یک دسته */
    public static function countFor(string $seg): int
    {
        try {
            return (int)DB::val('SELECT COUNT(*) FROM {p}users WHERE ' . self::whereFor($seg));
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** شمارش همهٔ دسته‌ها برای نمایش در پنل */
    public static function allCounts(): array
    {
        $out = [];
        foreach (self::SEGMENTS as $k => $meta) $out[$k] = self::countFor($k);
        return $out;
    }

    /**
     * تبدیل متن دکمه‌ها به کیبورد شیشه‌ای
     * هر خط: عنوان | آدرس     و دو دکمه کنار هم: عنوان|آدرس || عنوان|آدرس
     */
    public static function parseButtons(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        $rows = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;
            $row = [];
            foreach (explode('||', $line) as $cell) {
                $parts = explode('|', $cell);
                if (count($parts) < 2) continue;
                $label = trim($parts[0]);
                $url   = trim($parts[1]);
                if ($label === '' || $url === '') continue;
                if (!preg_match('~^(https?://|tg://)~i', $url)) $url = 'https://' . ltrim($url, '/');
                $row[] = Tg::url($label, $url);
            }
            if ($row) $rows[] = $row;
        }
        return $rows ? Tg::ikb($rows) : null;
    }

    /** پیش‌نمایش دکمه‌ها برای پنل (متن ساده) */
    public static function buttonPreview(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;
            $row = [];
            foreach (explode('||', $line) as $cell) {
                $p = explode('|', $cell);
                if (count($p) >= 2 && trim($p[0]) !== '') $row[] = trim($p[0]);
            }
            if ($row) $out[] = $row;
        }
        return $out;
    }

    /**
     * ارسال پیام همگانی
     *
     * @param array $o kind, text, file_id, file_type, buttons, segment, pin, silent, title, admin_id, test_to
     * @return array{ok:bool,sent:int,failed:int,total:int,id:int,message:string}
     */
    public static function send(array $o): array
    {
        $kind    = (string)($o['kind'] ?? 'text');
        if (!isset(self::KINDS[$kind])) $kind = 'text';
        $text    = trim((string)($o['text'] ?? ''));
        $fileId  = trim((string)($o['file_id'] ?? ''));
        $segment = (string)($o['segment'] ?? 'all');
        if (!isset(self::SEGMENTS[$segment])) $segment = 'all';
        $pin     = !empty($o['pin']);
        $silent  = !empty($o['silent']);
        $btnRaw  = (string)($o['buttons'] ?? '');
        $kb      = self::parseButtons($btnRaw);

        if ($kind === 'text' && $text === '') {
            return ['ok' => false, 'sent' => 0, 'failed' => 0, 'total' => 0, 'id' => 0,
                'message' => 'متن پیام خالی است.'];
        }
        if ($kind !== 'text' && $fileId === '') {
            return ['ok' => false, 'sent' => 0, 'failed' => 0, 'total' => 0, 'id' => 0,
                'message' => 'برای این نوع پیام باید فایل را آپلود یا شناسهٔ آن را وارد کنید.'];
        }

        /* ارسال آزمایشی فقط به یک نفر */
        if (!empty($o['test_to'])) {
            $ok = self::deliver((int)$o['test_to'], $kind, $text, $fileId, $kb, $silent, false);
            return ['ok' => $ok, 'sent' => $ok ? 1 : 0, 'failed' => $ok ? 0 : 1, 'total' => 1, 'id' => 0,
                'message' => $ok ? 'پیام آزمایشی ارسال شد.' : 'ارسال آزمایشی ناموفق بود.'];
        }

        $rows  = DB::all('SELECT tg_id FROM {p}users WHERE ' . self::whereFor($segment));
        $total = count($rows);

        $bcId = 0;
        try {
            $bcId = DB::insert('broadcasts', [
                'title'      => mb_substr($text !== '' ? $text : self::KINDS[$kind]['label'], 0, 150),
                'kind'       => $kind,
                'text'       => $text,
                'file_id'    => $fileId ?: null,
                'file_type'  => $kind !== 'text' ? $kind : null,
                'buttons'    => $btnRaw ?: null,
                'segment'    => $segment,
                'pin'        => $pin ? 1 : 0,
                'silent'     => $silent ? 1 : 0,
                'total'      => $total,
                'sent'       => 0,
                'failed'     => 0,
                'status'     => 'running',
                'admin_id'   => (int)($o['admin_id'] ?? 0) ?: null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            app_log('broadcast', 'insert failed: ' . $e->getMessage());
        }

        @set_time_limit(0);
        $sent = 0; $failed = 0; $i = 0;
        $batch = self::batch();
        $nap   = self::sleepMs();

        foreach ($rows as $r) {
            $chatId = (int)$r['tg_id'];
            if ($chatId <= 0) { $failed++; continue; }
            if (self::deliver($chatId, $kind, $text, $fileId, $kb, $silent, $pin)) $sent++;
            else $failed++;

            if (++$i % $batch === 0) {
                if ($nap > 0) sleep($nap);
                if ($bcId) {
                    try { DB::update('broadcasts', ['sent' => $sent, 'failed' => $failed], 'id = :id', [':id' => $bcId]); }
                    catch (Throwable $e) { }
                }
            }
        }

        if ($bcId) {
            try {
                DB::update('broadcasts', ['sent' => $sent, 'failed' => $failed, 'status' => 'done'],
                    'id = :id', [':id' => $bcId]);
            } catch (Throwable $e) { }
        }

        if (class_exists('Logs')) {
            try {
                Logs::send('broadcast', "📣 <b>پیام همگانی ارسال شد</b>\n"
                    . 'دسته: ' . (self::SEGMENTS[$segment]['label'] ?? $segment) . "\n"
                    . 'موفق: ' . fa_num($sent) . ' – ناموفق: ' . fa_num($failed));
            } catch (Throwable $e) { }
        }

        return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'total' => $total, 'id' => $bcId,
            'message' => 'پیام به ' . fa_num($sent) . ' کاربر ارسال شد'
                . ($failed > 0 ? ' ، ' . fa_num($failed) . ' ناموفق.' : '.')];
    }

    /** ارسال به یک مخاطب */
    private static function deliver(int $chatId, string $kind, string $text, string $fileId, $kb, bool $silent, bool $pin): bool
    {
        $method = self::KINDS[$kind]['method'] ?? 'sendMessage';
        $params = ['chat_id' => $chatId, 'disable_notification' => $silent];
        if ($kb) $params['reply_markup'] = $kb;

        if ($kind === 'text') {
            $params['text'] = $text;
            $params['parse_mode'] = 'HTML';
            $params['disable_web_page_preview'] = false;
        } elseif ($kind === 'sticker') {
            $params['sticker'] = $fileId;
        } else {
            $field = $kind === 'document' ? 'document' : $kind;
            $params[$field] = $fileId;
            if ($text !== '') {
                $params['caption'] = mb_substr($text, 0, 1024);
                $params['parse_mode'] = 'HTML';
            }
        }

        try {
            $res = Tg::api($method, $params);
            if (empty($res['ok'])) return false;
            if ($pin && !empty($res['result']['message_id'])) {
                try {
                    Tg::api('pinChatMessage', [
                        'chat_id' => $chatId,
                        'message_id' => (int)$res['result']['message_id'],
                        'disable_notification' => true,
                    ]);
                } catch (Throwable $e) { }
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * آپلود فایل در تلگرام و گرفتن شناسه‌ی فایل (file_id)
     * فایل یک‌بار برای مدیر ارسال می‌شود و بعد با همان شناسه برای همه فرستاده می‌شود.
     */
    public static function uploadToTelegram(string $localPath, string $kind, int $toChatId): string
    {
        if (!is_file($localPath)) return '';
        if ($toChatId <= 0) {
            $ids = class_exists('AdminBot') ? AdminBot::adminIds() : [];
            $toChatId = (int)($ids[0] ?? 0);
        }
        if ($toChatId <= 0) return '';

        $method = self::KINDS[$kind]['method'] ?? 'sendDocument';
        $field  = $kind === 'text' ? 'document' : ($kind === 'document' ? 'document' : $kind);

        try {
            $r = Tg::api($method, [
                'chat_id'              => $toChatId,
                $field                 => new CURLFile($localPath),
                'caption'              => '📎 فایل پیام همگانی آپلود شد.',
                'disable_notification' => true,
            ], true);
        } catch (Throwable $e) {
            app_log('broadcast', 'upload failed: ' . $e->getMessage());
            return '';
        }

        if (empty($r['ok']) || empty($r['result'])) return '';
        return self::extractFileId((array)$r['result']);
    }

    /** بیرون کشیدن file_id از پاسخ تلگرام */
    public static function extractFileId(array $msg): string
    {
        if (!empty($msg['photo']) && is_array($msg['photo'])) {
            $last = end($msg['photo']);
            return (string)($last['file_id'] ?? '');
        }
        foreach (['video', 'document', 'animation', 'voice', 'audio', 'sticker'] as $t) {
            if (!empty($msg[$t]['file_id'])) return (string)$msg[$t]['file_id'];
        }
        return '';
    }

    /** تشخیص نوع محتوا از روی پیام تلگرام */
    public static function detectKind(array $msg): string
    {
        if (!empty($msg['photo']))     return 'photo';
        if (!empty($msg['video']))     return 'video';
        if (!empty($msg['animation'])) return 'animation';
        if (!empty($msg['voice']))     return 'voice';
        if (!empty($msg['audio']))     return 'audio';
        if (!empty($msg['sticker']))   return 'sticker';
        if (!empty($msg['document']))  return 'document';
        return 'text';
    }

    /** آخرین پیام‌های همگانی */
    public static function recent(int $limit = 10): array
    {
        try {
            return DB::all('SELECT * FROM {p}broadcasts ORDER BY id DESC LIMIT ' . max(1, $limit));
        } catch (Throwable $e) {
            return [];
        }
    }
}
