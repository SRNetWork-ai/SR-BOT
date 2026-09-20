<?php
declare(strict_types=1);

/**
 * کلاینت Bot API تلگرام
 */
class Tg
{
    public static string $token = '';

    public static function setToken(string $t): void { self::$token = $t; }

    public static function api(string $method, array $params = [], bool $multipart = false): array
    {
        if (self::$token === '') return ['ok' => false, 'description' => 'token missing'];
        $url = 'https://api.telegram.org/bot' . self::$token . '/' . $method;
        $ch  = curl_init($url);
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_SSL_VERIFYPEER => app_ssl_verify(),
            CURLOPT_SSL_VERIFYHOST => app_ssl_verify() ? 2 : 0,
            CURLOPT_POST           => true,
        ];
        if ($multipart) {
            $opt[CURLOPT_POSTFIELDS] = $params;
        } else {
            foreach ($params as $k => $v) if (is_array($v)) $params[$k] = jenc($v);
            $opt[CURLOPT_POSTFIELDS] = $params;
        }
        curl_setopt_array($ch, $opt);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        $out = jdec(is_string($res) ? $res : '', []);
        if (!$out) $out = ['ok' => false, 'description' => $err ?: 'empty response'];
        if (empty($out['ok'])) app_log('telegram', $method . ' failed', ['res' => $out['description'] ?? '', 'params' => array_slice($params, 0, 3)]);
        return $out;
    }

    public static function send($chatId, string $text, $keyboard = null, array $extra = []): array
    {
        if (class_exists('Txt')) {
            try { $text = Txt::apply($text); } catch (Throwable $e) {}
        }
        $p = array_merge([
            'chat_id'    => $chatId,
            'text'       => mb_substr($text, 0, 4000),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra);
        if ($keyboard !== null) $p['reply_markup'] = $keyboard;
        return self::api('sendMessage', $p);
    }

    public static function edit($chatId, $messageId, string $text, $keyboard = null, array $extra = []): array
    {
        if (class_exists('Txt')) {
            try { $text = Txt::apply($text); } catch (Throwable $e) {}
        }
        $p = array_merge([
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => mb_substr($text, 0, 4000),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra);
        if ($keyboard !== null) $p['reply_markup'] = $keyboard;
        $r = self::api('editMessageText', $p);
        if (empty($r['ok'])) $r = self::send($chatId, $text, $keyboard, $extra);
        return $r;
    }

    public static function photo($chatId, string $photo, string $caption = '', $keyboard = null): array
    {
        $p = ['chat_id' => $chatId, 'photo' => $photo, 'caption' => mb_substr($caption, 0, 1000), 'parse_mode' => 'HTML'];
        if ($keyboard !== null) $p['reply_markup'] = $keyboard;
        return self::api('sendPhoto', $p);
    }

    /**
     * ارسال فایل موجود در تلگرام با file_id (عکس، سند، ویدیو، ویس)
     * اگر نوع فایل مشخص نباشد، اول sendPhoto و در صورت خطا sendDocument امتحان می‌شود.
     */
    public static function media($chatId, string $fileId, ?string $type = null, string $caption = '', $keyboard = null): array
    {
        $fileId = trim($fileId);
        if ($fileId === '') return ['ok' => false];

        $map = [
            'photo'     => ['sendPhoto', 'photo'],
            'document'  => ['sendDocument', 'document'],
            'video'     => ['sendVideo', 'video'],
            'voice'     => ['sendVoice', 'voice'],
            'audio'     => ['sendAudio', 'audio'],
            'animation' => ['sendAnimation', 'animation'],
            'video_note'=> ['sendVideoNote', 'video_note'],
        ];
        $order = isset($map[(string)$type]) ? [(string)$type] : ['photo', 'document'];

        $last = ['ok' => false];
        foreach ($order as $t) {
            [$method, $field] = $map[$t];
            $p = ['chat_id' => $chatId, $field => $fileId];
            if ($caption !== '' && $t !== 'video_note') {
                $p['caption'] = mb_substr($caption, 0, 1000);
                $p['parse_mode'] = 'HTML';
            }
            if ($keyboard !== null) $p['reply_markup'] = $keyboard;
            $last = self::api($method, $p);
            if (!empty($last['ok'])) return $last;
        }
        // آخرین تلاش: ارسال به صورت سند
        if (!in_array('document', $order, true)) {
            $p = ['chat_id' => $chatId, 'document' => $fileId];
            if ($caption !== '') { $p['caption'] = mb_substr($caption, 0, 1000); $p['parse_mode'] = 'HTML'; }
            if ($keyboard !== null) $p['reply_markup'] = $keyboard;
            $last = self::api('sendDocument', $p);
        }
        return $last;
    }

    /** دریافت لینک مستقیم دانلود یک فایل تلگرام (برای نمایش در پنل وب) */
    public static function fileUrl(string $fileId): string
    {
        $fileId = trim($fileId);
        if ($fileId === '') return '';
        $r = self::api('getFile', ['file_id' => $fileId]);
        $path = (string)($r['result']['file_path'] ?? '');
        if ($path === '') return '';
        return 'https://api.telegram.org/file/bot' . self::$token . '/' . $path;
    }

    public static function document($chatId, string $path, string $caption = ''): array
    {
        if (!is_file($path)) return ['ok' => false];
        return self::api('sendDocument', [
            'chat_id'  => $chatId,
            'document' => new CURLFile($path),
            'caption'  => mb_substr($caption, 0, 1000),
            'parse_mode' => 'HTML',
        ], true);
    }

    public static function answerCb($cbId, string $text = '', bool $alert = false): array
    {
        return self::api('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => mb_substr($text, 0, 190), 'show_alert' => $alert]);
    }

    public static function deleteMsg($chatId, $messageId): array
    {
        return self::api('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    public static function copyMsg($toChat, $fromChat, $messageId, $keyboard = null): array
    {
        $p = ['chat_id' => $toChat, 'from_chat_id' => $fromChat, 'message_id' => $messageId];
        if ($keyboard !== null) $p['reply_markup'] = $keyboard;
        return self::api('copyMessage', $p);
    }

    public static function setWebhook(string $url, string $secret = ''): array
    {
        /* 0.0.2 #8: اگر توکن امنیتی خالی باشد خودکار ساخته و در config.php ذخیره می‌شود */
        if ($secret === '') $secret = self::ensureSecret();

        $p = ['url' => $url, 'max_connections' => 40, 'drop_pending_updates' => true,
              'allowed_updates' => jenc(['message', 'callback_query', 'pre_checkout_query'])];
        if ($secret !== '') $p['secret_token'] = $secret;
        return self::api('setWebhook', $p);
    }

    /**
     * 0.0.2 #8: خواندن یا ساخت توکن امنیتی وب‌هوک.
     *
     * اگر ذخیره در config.php ممکن نباشد رشتهٔ خالی برمی‌گردد؛ چون در غیر این صورت
     * تلگرام هدر امنیتی می‌فرستد ولی ربات توکن را نمی‌شناسد و همهٔ پیام‌ها رد می‌شوند.
     */
    public static function ensureSecret(): string
    {
        $s = '';
        try {
            if (class_exists('Cfg')) $s = trim((string)Cfg::get('bot.secret', ''));
            if ($s === '' && function_exists('cfg')) $s = trim((string)cfg('bot.secret', ''));
        } catch (Throwable $e) {
            $s = '';
        }
        if ($s !== '') return $s;
        if (!class_exists('Cfg')) return '';

        try {
            $new = bin2hex(random_bytes(16));
            $w   = Cfg::set(['bot.secret' => $new]);
            if (empty($w['ok'])) {
                if (function_exists('app_log')) app_log('sec', 'webhook secret not saved: ' . (string)($w['message'] ?? ''));
                return '';
            }
            if (function_exists('app_log')) app_log('sec', 'webhook secret generated');
            return $new;
        } catch (Throwable $e) {
            return '';
        }
    }

    public static function deleteWebhook(): array { return self::api('deleteWebhook', ['drop_pending_updates' => false]); }

    public static function getMe(): array { return self::api('getMe'); }

    public static function isMember(string $chatId, $userId): bool
    {
        $r = self::api('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
        if (empty($r['ok'])) return true; // اگر ربات ادمین کانال نباشد مانع کاربر نمی‌شویم
        $status = $r['result']['status'] ?? '';
        return in_array($status, ['creator', 'administrator', 'member'], true);
    }

    /* ---------- سازنده‌های کیبورد ---------- */

    public static function ikb(array $rows): array { return ['inline_keyboard' => $rows]; }

    public static function btn(string $text, string $data): array { return ['text' => $text, 'callback_data' => $data]; }

    public static function url(string $text, string $url): array { return ['text' => $text, 'url' => $url]; }

    public static function rkb(array $rows, bool $resize = true): array
    {
        return ['keyboard' => $rows, 'resize_keyboard' => $resize, 'is_persistent' => true];
    }

    public static function removeKb(): array { return ['remove_keyboard' => true]; }
    /* ==================== fixed84: مهر زدن روی پیام‌ها ==================== */

    /** ویرایش کپشن پیام عکس‌دار (کارت رسید) */
    public static function editCaption($chatId, $messageId, string $caption, $keyboard = null): array
    {
        if (class_exists('Txt')) {
            try { $caption = Txt::apply($caption); } catch (Throwable $e) {}
        }
        $p = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'caption'    => mb_substr($caption, 0, 1000),
            'parse_mode' => 'HTML',
        ];
        if ($keyboard !== null) $p['reply_markup'] = $keyboard;
        return self::api('editMessageCaption', $p);
    }

    /** تغییر یا حذف دکمه‌های شیشه‌ای یک پیام */
    public static function editMarkup($chatId, $messageId, $keyboard = null): array
    {
        return self::api('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => $keyboard ?? ['inline_keyboard' => []],
        ]);
    }

    /**
     * متن/کپشن یک پیام را جایگزین و دکمه‌هایش را حذف می‌کند (بدون ارسال پیام جدید).
     * editMessageText روی پیام عکس‌دار کار نمی‌کند؛ پس کپشن و در نهایت فقط دکمه‌ها ویرایش می‌شود.
     */
    public static function stamp($chatId, $messageId, string $text, $keyboard = null): bool
    {
        if ((int)$messageId <= 0) return false;
        $kb = $keyboard ?? ['inline_keyboard' => []];
        $r  = self::api('editMessageText', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => mb_substr($text, 0, 4000),
            'parse_mode'   => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $kb,
        ]);
        if (!empty($r['ok'])) return true;
        $c = self::editCaption($chatId, $messageId, $text, $kb);
        if (!empty($c['ok'])) return true;
        $m = self::editMarkup($chatId, $messageId, $kb);
        return !empty($m['ok']);
    }

}
