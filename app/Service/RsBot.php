<?php
declare(strict_types=1);

/**
 * ربات اختصاصی نماینده — راه‌اندازی کاملاً خودکار
 *
 * نماینده هزینه را از کیف پول نمایندگی پرداخت می‌کند، سپس توکن ربات و
 * آیدی عددی مالک را می‌دهد و همین کلاس بدون دخالت مدیر:
 *   ۱) توکن را با getMe اعتبارسنجی می‌کند
 *   ۲) وب‌هوک را روی rbot.php با کلید امن خصوصی تنظیم می‌کند
 *   ۳) دستورها، توضیح و دکمهٔ مینی‌اپ ربات را می‌سازد
 *   ۴) از داخل همان ربات به مالک پیام خوش‌آمد می‌فرستد
 */
class RsBot
{
    public const STATUS = [
        'active' => '✅ فعال',
        'paused' => '⏸ موقتاً متوقف',
        'failed' => '⛔️ خطا در راه‌اندازی',
    ];

    public static function enabled(): bool { return (string)DB::setting('rs_bot_enabled', '0') === '1'; }
    public static function auto(): bool    { return (string)DB::setting('rs_bot_auto', '1') === '1'; }
    public static function price(): int    { return max(0, (int)DB::setting('rs_bot_price', 0)); }
    public static function note(): string  { return trim((string)DB::setting('rs_bot_note', '')); }
    public static function maxPerUser(): int { return max(1, (int)DB::setting('rs_bot_max', 1)); }

    /** تا مهاجرت اجرا نشده، همهٔ این بخش بی‌صدا خاموش می‌ماند */
    public static function ready(): bool
    {
        static $ok = null;
        if ($ok !== null) return (bool)$ok;
        try {
            DB::val('SELECT COUNT(*) FROM {p}rs_bots', [], 0);
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
        return (bool)$ok;
    }

    /* ------------------------------------------------------------ خواندن */

    public static function byId(int $id): ?array
    {
        if ($id <= 0 || !self::ready()) return null;
        try { return DB::one('SELECT * FROM {p}rs_bots WHERE id = :i', [':i' => $id]); }
        catch (Throwable $e) { return null; }
    }

    public static function bySecret(string $secret): ?array
    {
        $secret = trim($secret);
        if ($secret === '' || !preg_match('~^[a-f0-9]{16,64}$~i', $secret) || !self::ready()) return null;
        try { return DB::one('SELECT * FROM {p}rs_bots WHERE secret = :s', [':s' => $secret]); }
        catch (Throwable $e) { return null; }
    }

    public static function forUser(int $userId): ?array
    {
        if ($userId <= 0 || !self::ready()) return null;
        try {
            return DB::one('SELECT * FROM {p}rs_bots WHERE user_id = :u ORDER BY id DESC LIMIT 1',
                [':u' => $userId]);
        } catch (Throwable $e) { return null; }
    }

    public static function all(int $limit = 200): array
    {
        if (!self::ready()) return [];
        $limit = max(1, min(500, $limit));
        try {
            return DB::all('SELECT * FROM {p}rs_bots ORDER BY id DESC LIMIT ' . $limit);
        } catch (Throwable $e) { return []; }
    }

    /** آیا هزینهٔ راه‌اندازی پرداخت شده و اجازهٔ ساخت دارد؟ */
    public static function paid(int $userId): bool
    {
        if ($userId <= 0) return false;
        if (self::price() <= 0) return true;
        try {
            $n = (int)DB::val("SELECT COUNT(*) FROM {p}transactions
                WHERE user_id = :u AND method = 'rsbot'", [':u' => $userId], 0);
            return $n > 0;
        } catch (Throwable $e) { return false; }
    }

    /* ------------------------------------------------------------ ابزار */

    public static function hookUrl(string $secret): string
    {
        return app_url('rbot.php?k=' . $secret);
    }

    /** اجرای یک کار با توکن دیگر و بازگرداندن توکن ربات اصلی */
    public static function withToken(string $token, callable $fn)
    {
        $old = Tg::$token;
        Tg::setToken($token);
        try {
            return $fn();
        } finally {
            Tg::setToken($old);
        }
    }

    public static function checkToken(string $token): array
    {
        return (array)self::withToken($token, static fn() => Tg::api('getMe'));
    }

    public static function validToken(string $token): bool
    {
        return (bool)preg_match('~^\d{6,14}:[A-Za-z0-9_\-]{20,80}$~', trim($token));
    }

    /* ------------------------------------------------------------ ساخت */

    /**
     * ساخت کاملاً خودکار ربات نماینده
     *
     * @param array $ru ردیف کاربر نماینده
     */
    public static function provision(array $ru, string $token, int $ownerId, array $opt = []): array
    {
        $uid = (int)($ru['id'] ?? 0);
        if ($uid <= 0)      return ['ok' => false, 'message' => 'کاربر شناسایی نشد.'];
        if (!self::ready()) return ['ok' => false, 'message' => 'جدول ربات‌های نمایندگی ساخته نشده است؛ به‌روزرسانی پنل را کامل کنید.'];

        $token = trim($token);
        if (!self::validToken($token)) {
            return ['ok' => false, 'message' => 'قالب توکن درست نیست. توکن را کامل از @BotFather کپی کنید (مانند 1234567890:AA...).'];
        }
        if ($ownerId < 1000) {
            return ['ok' => false, 'message' => 'آیدی عددی مالک درست نیست. از @userinfobot عدد خود را بگیرید.'];
        }

        $base = trim((string)cfg('app.url', ''));
        if ($base === '' || !preg_match('~^https://~i', $base)) {
            return ['ok' => false, 'message' => 'آدرس پنل (app.url) باید https باشد تا تلگرام وب‌هوک را بپذیرد.'];
        }

        /* تکراری نبودن توکن */
        try {
            $dup = DB::one('SELECT id, user_id FROM {p}rs_bots WHERE token = :t LIMIT 1', [':t' => $token]);
        } catch (Throwable $e) { $dup = null; }
        if ($dup && (int)$dup['user_id'] !== $uid) {
            return ['ok' => false, 'message' => 'این توکن قبلاً برای ربات دیگری ثبت شده است.'];
        }

        /* محدودیت تعداد ربات برای هر نماینده */
        $mine = null;
        try {
            $mine = DB::one('SELECT * FROM {p}rs_bots WHERE user_id = :u ORDER BY id DESC LIMIT 1', [':u' => $uid]);
            $cnt  = (int)DB::val('SELECT COUNT(*) FROM {p}rs_bots WHERE user_id = :u', [':u' => $uid], 0);
        } catch (Throwable $e) { $cnt = 0; }
        $replacing = ($mine && (int)($mine['id'] ?? 0) > 0);
        if (!$replacing && $cnt >= self::maxPerUser()) {
            return ['ok' => false, 'message' => 'سقف تعداد ربات اختصاصی شما پر شده است.'];
        }

        /* اعتبارسنجی توکن نزد تلگرام */
        $me = self::checkToken($token);
        if (empty($me['ok']) || empty($me['result']['id'])) {
            $why = trim((string)($me['description'] ?? ''));
            return ['ok' => false, 'message' => 'تلگرام این توکن را نپذیرفت'
                . ($why !== '' ? ': ' . $why : '. توکن را دوباره بررسی کنید.')];
        }
        $bot   = (array)$me['result'];
        $botId = (int)$bot['id'];
        $uname = trim((string)($bot['username'] ?? ''));
        $title = trim((string)($bot['first_name'] ?? $uname));

        try {
            $dupBot = DB::one('SELECT id, user_id FROM {p}rs_bots WHERE bot_id = :b LIMIT 1', [':b' => $botId]);
        } catch (Throwable $e) { $dupBot = null; }
        if ($dupBot && (int)$dupBot['user_id'] !== $uid) {
            return ['ok' => false, 'message' => 'این ربات قبلاً توسط نمایندهٔ دیگری راه‌اندازی شده است.'];
        }

        $secret = bin2hex(random_bytes(16));
        $now    = date('Y-m-d H:i:s');
        $row    = [
            'user_id'    => $uid,
            'tg_id'      => (int)($ru['tg_id'] ?? 0),
            'owner_id'   => $ownerId,
            'bot_id'     => $botId,
            'username'   => mb_substr($uname, 0, 64),
            'title'      => mb_substr($title, 0, 120),
            'token'      => $token,
            'secret'     => $secret,
            'status'     => 'active',
            'paid'       => max(0, (int)($opt['paid'] ?? 0)),
            'tx_id'      => max(0, (int)($opt['tx_id'] ?? 0)),
            'hook_at'    => $now,
            'err'        => null,
            'updated_at' => $now,
        ];

        try {
            if ($replacing) {
                DB::update('rs_bots', $row, 'id = :i', [':i' => (int)$mine['id']]);
                $id = (int)$mine['id'];
            } else {
                $row['created_at'] = $now;
                $id = (int)DB::insert('rs_bots', $row);
            }
        } catch (Throwable $e) {
            app_log('rsbot', 'insert failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'ثبت ربات در بانک اطلاعات انجام نشد.'];
        }
        if ($id <= 0) return ['ok' => false, 'message' => 'ثبت ربات انجام نشد.'];

        /* تنظیم وب‌هوک */
        $hook = self::hookUrl($secret);
        $set  = (array)self::withToken($token, static function () use ($hook, $secret) {
            return Tg::api('setWebhook', [
                'url'                  => $hook,
                'secret_token'         => $secret,
                'max_connections'      => 20,
                'drop_pending_updates' => true,
                'allowed_updates'      => ['message', 'edited_message', 'callback_query',
                                           'my_chat_member', 'pre_checkout_query'],
            ]);
        });
        if (empty($set['ok'])) {
            $why = mb_substr(trim((string)($set['description'] ?? '')), 0, 300);
            try {
                DB::update('rs_bots', ['status' => 'failed', 'err' => $why, 'updated_at' => date('Y-m-d H:i:s')],
                    'id = :i', [':i' => $id]);
            } catch (Throwable $e) {}
            return ['ok' => false, 'message' => 'تنظیم وب‌هوک انجام نشد' . ($why !== '' ? ': ' . $why : '.')];
        }

        /* زیباسازی ربات — خطاهای این بخش مهم نیستند */
        $shop = trim((string)DB::setting('shop_title', 'فروشگاه کانفیگ'));
        self::withToken($token, static function () use ($shop) {
            try {
                Tg::api('setMyCommands', ['commands' => [
                    ['command' => 'start', 'description' => 'شروع و منوی اصلی'],
                    ['command' => 'services', 'description' => 'سرویس‌های من'],
                    ['command' => 'support', 'description' => 'پشتیبانی'],
                ]]);
            } catch (Throwable $e) {}
            try {
                Tg::api('setMyShortDescription',
                    ['short_description' => mb_substr('خرید و تمدید سرویس — ' . $shop, 0, 120)]);
            } catch (Throwable $e) {}
            $ma = trim((string)DB::setting('miniapp_url', ''));
            if ($ma !== '' && preg_match('~^https://~i', $ma)) {
                try {
                    Tg::api('setChatMenuButton', ['menu_button' => [
                        'type' => 'web_app',
                        'text' => '🚀 اپلیکیشن',
                        'web_app' => ['url' => $ma],
                    ]]);
                } catch (Throwable $e) {}
            }
        });

        /* سلام اول از داخل ربات خود نماینده */
        $hi = "🤖 <b>ربات اختصاصی شما فعال شد</b>\n\n"
            . 'ربات: @' . $uname . "\n"
            . "شما مالک و مدیر این ربات هستید؛ کاربرانی که اینجا عضو می‌شوند مشتری شما محسوب می‌شوند.\n"
            . "برای شروع دستور /start را بزنید.";
        self::withToken($token, static function () use ($ownerId, $hi) {
            try { Tg::send($ownerId, $hi); } catch (Throwable $e) {}
        });

        $who = trim((string)($ru['first_name'] ?? '')) ?: 'نماینده';
        $adm = "🤖 <b>ربات اختصاصی نماینده ساخته شد</b>\n"
            . 'نماینده: ' . h($who) . ' (<code>' . (int)($ru['tg_id'] ?? 0) . '</code>)' . "\n"
            . 'ربات: @' . h($uname) . "\n"
            . 'مالک ربات: <code>' . $ownerId . '</code>';
        try { AdminBot::notifyAdmins($adm); } catch (Throwable $e) {}
        try { Logs::send('financial', $adm); } catch (Throwable $e) {}

        app_log('rsbot', 'provisioned', ['id' => $id, 'bot' => $uname, 'user' => $uid]);

        return [
            'ok'      => true,
            'id'      => $id,
            'bot'     => self::byId($id),
            'message' => '✅ ربات @' . $uname . ' ساخته و فعال شد. در تلگرام /start را بزنید.',
        ];
    }

    /* ------------------------------------------------------------ مدیریت */

    public static function reHook(array $row): array
    {
        $token  = (string)($row['token'] ?? '');
        $secret = (string)($row['secret'] ?? '');
        if ($token === '' || $secret === '') return ['ok' => false, 'message' => 'اطلاعات ربات کامل نیست.'];

        $hook = self::hookUrl($secret);
        $set  = (array)self::withToken($token, static function () use ($hook, $secret) {
            return Tg::api('setWebhook', [
                'url'             => $hook,
                'secret_token'    => $secret,
                'max_connections' => 20,
                'allowed_updates' => ['message', 'edited_message', 'callback_query',
                                      'my_chat_member', 'pre_checkout_query'],
            ]);
        });
        $ok = !empty($set['ok']);
        try {
            DB::update('rs_bots', [
                'status'     => $ok ? 'active' : 'failed',
                'err'        => $ok ? null : mb_substr((string)($set['description'] ?? ''), 0, 300),
                'hook_at'    => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :i', [':i' => (int)$row['id']]);
        } catch (Throwable $e) {}

        return ['ok' => $ok, 'message' => $ok
            ? '✅ وب‌هوک دوباره تنظیم شد.'
            : 'تنظیم وب‌هوک انجام نشد: ' . (string)($set['description'] ?? '')];
    }

    public static function pause(array $row): array
    {
        $token = (string)($row['token'] ?? '');
        self::withToken($token, static function () {
            try { Tg::api('deleteWebhook', ['drop_pending_updates' => true]); } catch (Throwable $e) {}
        });
        try {
            DB::update('rs_bots', ['status' => 'paused', 'updated_at' => date('Y-m-d H:i:s')],
                'id = :i', [':i' => (int)$row['id']]);
        } catch (Throwable $e) {}
        return ['ok' => true, 'message' => '⏸ ربات موقتاً متوقف شد.'];
    }

    public static function resume(array $row): array
    {
        return self::reHook($row);
    }

    public static function rotate(array $row): array
    {
        $secret = bin2hex(random_bytes(16));
        try {
            DB::update('rs_bots', ['secret' => $secret, 'updated_at' => date('Y-m-d H:i:s')],
                'id = :i', [':i' => (int)$row['id']]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'تغییر کلید امن انجام نشد.'];
        }
        $row['secret'] = $secret;
        $r = self::reHook($row);
        $r['message'] = !empty($r['ok']) ? '🔑 کلید امن و وب‌هوک تازه شد.' : (string)$r['message'];
        return $r;
    }

    public static function remove(array $row): array
    {
        $token = (string)($row['token'] ?? '');
        self::withToken($token, static function () {
            try { Tg::api('deleteWebhook', ['drop_pending_updates' => true]); } catch (Throwable $e) {}
        });
        try {
            DB::delete('rs_bot_users', 'bot_id = :b', [':b' => (int)$row['id']]);
        } catch (Throwable $e) {}
        try {
            DB::delete('rs_bots', 'id = :i', [':i' => (int)$row['id']]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'حذف انجام نشد.'];
        }
        return ['ok' => true, 'message' => '🗑 ربات حذف شد. می‌توانید توکن تازه‌ای ثبت کنید.'];
    }

    public static function health(array $row): array
    {
        $token = (string)($row['token'] ?? '');
        $info  = (array)self::withToken($token, static fn() => Tg::api('getWebhookInfo'));
        $r     = (array)($info['result'] ?? []);
        return [
            'ok'         => !empty($info['ok']),
            'url'        => (string)($r['url'] ?? ''),
            'pending'    => (int)($r['pending_update_count'] ?? 0),
            'last_error' => (string)($r['last_error_message'] ?? ''),
            'ip'         => (string)($r['ip_address'] ?? ''),
        ];
    }

    /** شمارش پیام‌های دریافتی ربات */
    public static function touch(int $id): void
    {
        if ($id <= 0) return;
        try {
            DB::q('UPDATE {p}rs_bots SET updates = updates + 1, last_at = :n WHERE id = :i',
                [':n' => date('Y-m-d H:i:s'), ':i' => $id]);
        } catch (Throwable $e) {}
    }

    /** مشتریان ربات نماینده را به همان ربات متصل می‌کند */
    public static function tagUser(array $row, int $tgId): void
    {
        $bid = (int)($row['id'] ?? 0);
        if ($bid <= 0 || $tgId <= 0 || !self::ready()) return;
        try {
            $uid = (int)DB::val('SELECT id FROM {p}users WHERE tg_id = :t', [':t' => $tgId], 0);
            $has = (int)DB::val('SELECT COUNT(*) FROM {p}rs_bot_users WHERE bot_id = :b AND tg_id = :t',
                [':b' => $bid, ':t' => $tgId], 0);
            if ($has > 0) {
                if ($uid > 0) {
                    DB::update('rs_bot_users', ['user_id' => $uid], 'bot_id = :b AND tg_id = :t',
                        [':b' => $bid, ':t' => $tgId]);
                }
                return;
            }
            DB::insert('rs_bot_users', [
                'bot_id'     => $bid,
                'tg_id'      => $tgId,
                'user_id'    => $uid,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {}
    }

    public static function stats(array $row): array
    {
        $bid = (int)($row['id'] ?? 0);
        $out = ['users' => 0, 'today' => 0, 'updates' => (int)($row['updates'] ?? 0)];
        if ($bid <= 0 || !self::ready()) return $out;
        try {
            $out['users'] = (int)DB::val('SELECT COUNT(*) FROM {p}rs_bot_users WHERE bot_id = :b',
                [':b' => $bid], 0);
            $out['today'] = (int)DB::val('SELECT COUNT(*) FROM {p}rs_bot_users
                WHERE bot_id = :b AND DATE(created_at) = CURDATE()', [':b' => $bid], 0);
        } catch (Throwable $e) {}
        return $out;
    }

    /** بستهٔ اطلاعات برای مینی‌اپ نمایندگی */
    public static function info(array $ru): array
    {
        $uid   = (int)($ru['id'] ?? 0);
        $price = self::price();
        $row   = self::forUser($uid);

        $out = [
            'enabled'   => self::enabled(),
            'auto'      => self::auto(),
            'price'     => $price,
            'price_txt' => function_exists('ma_money') ? ma_money($price) : money($price),
            'note'      => self::note(),
            'paid'      => self::paid($uid),
            'ready'     => self::ready(),
            'bot'       => null,
        ];
        $out['pending'] = $out['paid'] && !$row;

        if ($row) {
            $st  = (string)($row['status'] ?? 'active');
            $stt = self::stats($row);
            $out['bot'] = [
                'id'        => (int)$row['id'],
                'username'  => (string)($row['username'] ?? ''),
                'title'     => (string)($row['title'] ?? ''),
                'owner_id'  => (int)($row['owner_id'] ?? 0),
                'status'    => $st,
                'status_fa' => (string)(self::STATUS[$st] ?? $st),
                'link'      => ((string)($row['username'] ?? '') !== '')
                    ? 'https://t.me/' . (string)$row['username'] : '',
                'created'   => (string)($row['created_at'] ?? ''),
                'hook_at'   => (string)($row['hook_at'] ?? ''),
                'last_at'   => (string)($row['last_at'] ?? ''),
                'err'       => (string)($row['err'] ?? ''),
                'users'     => (int)$stt['users'],
                'today'     => (int)$stt['today'],
                'updates'   => (int)$stt['updates'],
            ];
        }
        return $out;
    }
}
