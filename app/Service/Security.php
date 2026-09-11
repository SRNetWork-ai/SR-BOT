<?php
declare(strict_types=1);

/**
 * امنیت و تایید حساب کاربری
 *
 * روش‌های تایید:
 *   email – ارسال کد به ایمیل (mail() یا SMTP)
 *   phone – ارسال کد پیامکی با API دلخواه
 *   link  – لینک تایید یک‌بارمصرف
 *
 * همچنین محافظت از ورود مدیر (قفل پس از تلاش‌های ناموفق).
 */
class Security
{
    public const KINDS = [
        'email' => ['label' => 'تایید با ایمیل', 'icon' => '📧'],
        'phone' => ['label' => 'تایید با شماره موبایل', 'icon' => '📱'],
        'link'  => ['label' => 'تایید با لینک', 'icon' => '🔗'],
    ];

    public const MODES = [
        'off'      => 'غیرفعال – هیچ تاییدی لازم نیست',
        'optional' => 'اختیاری – کاربر خودش می‌تواند تایید کند',
        'required' => 'اجباری – بدون تایید اجازه ندارد',
    ];

    public const SCOPES = [
        'buy'  => 'فقط هنگام خرید',
        'test' => 'فقط هنگام دریافت اکانت تست',
        'both' => 'خرید و اکانت تست',
        'all'  => 'همهٔ بخش‌های ربات',
    ];

    /* ---------------- تنظیمات ---------------- */

    public static function mode(): string
    {
        $m = (string)DB::setting('sec_verify_mode', 'off');
        return isset(self::MODES[$m]) ? $m : 'off';
    }

    public static function scope(): string
    {
        $s = (string)DB::setting('sec_verify_required_for', 'buy');
        return isset(self::SCOPES[$s]) ? $s : 'buy';
    }

    public static function on(string $kind): bool
    {
        return (string)DB::setting('sec_verify_' . $kind, '0') === '1';
    }

    /** روش‌های فعال تایید */
    public static function activeKinds(): array
    {
        $out = [];
        foreach (self::KINDS as $k => $meta) if (self::on($k)) $out[$k] = $meta;
        return $out;
    }

    public static function enabled(): bool
    {
        return self::mode() !== 'off' && self::activeKinds() !== [];
    }

    public static function codeLen(): int    { return max(4, min(8, (int)DB::setting('sec_code_length', 5))); }
    public static function ttlMin(): int     { return max(1, (int)DB::setting('sec_code_ttl', 10)); }
    public static function maxTries(): int   { return max(1, (int)DB::setting('sec_max_tries', 5)); }
    public static function resendWait(): int { return max(10, (int)DB::setting('sec_resend_wait', 90)); }

    /* ---------------- وضعیت کاربر ---------------- */

    public static function isVerified(array $u): bool
    {
        if (!empty($u['verified_at'])) return true;
        if (self::on('email') && (int)($u['email_verified'] ?? 0) === 1) return true;
        if (self::on('phone') && (int)($u['phone_verified'] ?? 0) === 1) return true;
        return false;
    }

    /**
     * آیا برای این عمل تایید لازم است؟
     * @param string $action buy | test | any
     */
    public static function needs(array $u, string $action = 'any'): bool
    {
        if (self::mode() !== 'required' || !self::enabled()) return false;
        if (self::isVerified($u)) return false;

        $scope = self::scope();
        if ($scope === 'all') return true;
        if ($scope === 'both') return in_array($action, ['buy', 'test'], true);
        return $action === $scope;
    }

    /** پیام راهنمای تایید نشده */
    public static function blockMessage(): string
    {
        $names = implode(' یا ', array_map(fn($m) => (string)$m['label'], self::activeKinds()));
        return "🔐 <b>تایید حساب لازم است</b>\n\n"
            . "برای ادامه باید حساب خود را تایید کنید.\n"
            . "روش فعال: " . ($names !== '' ? $names : '—') . "\n\n"
            . "از منوی «👤 حساب کاربری» بخش تایید حساب را باز کنید.";
    }

    /* ---------------- شروع فرآیند تایید ---------------- */

    public static function genCode(): string
    {
        // هر رقم جداگانه ساخته می‌شود تا طول کد دقیقاً درست و توزیع یکنواخت باشد
        $len  = self::codeLen();
        $code = (string)random_int(1, 9);                 // رقم اول صفر نباشد
        for ($i = 1; $i < $len; $i++) $code .= (string)random_int(0, 9);
        return $code;
    }

    /**
     * ارسال کد/لینک تایید
     * @return array{ok:bool,message:string,link?:string}
     */
    public static function start(array $u, string $kind, string $target): array
    {
        if (!isset(self::KINDS[$kind])) return ['ok' => false, 'message' => 'روش تایید نامعتبر است.'];
        if (!self::on($kind))          return ['ok' => false, 'message' => 'این روش تایید غیرفعال است.'];

        $target = trim($target);
        if ($kind === 'email' && !filter_var($target, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'ایمیل واردشده معتبر نیست.'];
        }
        if ($kind === 'phone') {
            $target = preg_replace('/\D/', '', en_num($target)) ?? '';
            if (strlen($target) < 10) return ['ok' => false, 'message' => 'شماره موبایل معتبر نیست.'];
        }

        // محدودیت ارسال مجدد
        $lastAt = (string)($u['verify_at'] ?? '');
        if ($lastAt !== '') {
            $diff = time() - (int)strtotime($lastAt);
            if ($diff < self::resendWait()) {
                $wait = self::resendWait() - $diff;
                return ['ok' => false, 'message' => 'لطفاً ' . fa_num($wait) . ' ثانیه دیگر دوباره تلاش کنید.'];
            }
        }

        $code  = self::genCode();
        $token = rnd(48);

        DB::update('users', [
            'verify_kind'   => $kind,
            'verify_target' => $target,
            'verify_code'   => $code,
            'verify_token'  => $token,
            'verify_at'     => now(),
            'verify_tries'  => 0,
        ], 'id = :id', [':id' => (int)$u['id']]);

        $shop = (string)DB::setting('shop_title', 'فروشگاه کانفیگ');
        $link = self::link($token);

        if ($kind === 'link') {
            return ['ok' => true, 'link' => $link,
                'message' => 'لینک تایید ساخته شد.'];
        }

        if ($kind === 'email') {
            $subject = 'کد تایید ' . $shop;
            $html = self::mailTemplate($shop, $code, $link);
            $ok = self::sendEmail($target, $subject, $html);
            return $ok
                ? ['ok' => true, 'message' => 'کد تایید به ایمیل شما ارسال شد.', 'link' => $link]
                : ['ok' => false, 'message' => 'ارسال ایمیل ناموفق بود. تنظیمات ایمیل را بررسی کنید.'];
        }

        $text = $shop . "\nکد تایید: " . $code;
        $ok = self::sendSms($target, $text, $code);
        return $ok
            ? ['ok' => true, 'message' => 'کد تایید پیامک شد.']
            : ['ok' => false, 'message' => 'ارسال پیامک ناموفق بود. تنظیمات پیامک را بررسی کنید.'];
    }

    /** بررسی کد واردشده */
    public static function checkCode(array $u, string $code): array
    {
        $code = trim(en_num($code));
        $saved = (string)($u['verify_code'] ?? '');
        if ($saved === '') return ['ok' => false, 'message' => 'کد فعالی وجود ندارد. دوباره درخواست کنید.'];

        $at = (string)($u['verify_at'] ?? '');
        if ($at === '' || (time() - (int)strtotime($at)) > self::ttlMin() * 60) {
            self::clear((int)$u['id']);
            return ['ok' => false, 'message' => 'کد منقضی شده است. کد جدید بگیرید.'];
        }

        $tries = (int)($u['verify_tries'] ?? 0);
        if ($tries >= self::maxTries()) {
            self::clear((int)$u['id']);
            return ['ok' => false, 'message' => 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. کد جدید بگیرید.'];
        }

        if (!hash_equals($saved, $code)) {
            DB::q('UPDATE {p}users SET verify_tries = verify_tries + 1 WHERE id = :id', [':id' => (int)$u['id']]);
            $left = self::maxTries() - ($tries + 1);
            return ['ok' => false, 'message' => 'کد اشتباه است. ' . ($left > 0 ? fa_num($left) . ' تلاش دیگر باقی مانده.' : '')];
        }

        self::markVerified((int)$u['id'], (string)($u['verify_kind'] ?? ''), (string)($u['verify_target'] ?? ''));
        return ['ok' => true, 'message' => '✅ حساب شما با موفقیت تایید شد.'];
    }

    /** تایید با توکن لینک */
    /**
     * بررسی توکن بدون مصرف کردن آن.
     * برای صفحهٔ تایید استفاده می‌شود تا اول کپچا گرفته شود و بعد حساب تایید شود.
     */
    public static function peekToken(string $token): array
    {
        $token = trim($token);
        if (strlen($token) < 16) return ['ok' => false, 'message' => 'لینک نامعتبر است.'];

        $u = DB::one('SELECT * FROM {p}users WHERE verify_token = :t LIMIT 1', [':t' => $token]);
        if (!$u) return ['ok' => false, 'message' => 'لینک نامعتبر یا قبلاً استفاده شده است.'];

        $at = (string)($u['verify_at'] ?? '');
        if ($at === '' || (time() - (int)strtotime($at)) > self::ttlMin() * 60) {
            return ['ok' => false, 'message' => 'این لینک منقضی شده است.'];
        }

        $kind = (string)($u['verify_kind'] ?? 'link');
        $meta = self::KINDS[$kind] ?? null;
        $kt   = '';
        if (is_array($meta)) {
            $kt = trim((string)($meta['icon'] ?? '') . ' ' . (string)($meta['label'] ?? ''));
        }

        return [
            'ok'       => true,
            'message'  => '',
            'kind'     => $kind,
            'kind_txt' => $kt,
            'target'   => (string)($u['verify_target'] ?? ''),
            'name'     => trim((string)($u['first_name'] ?? '')),
        ];
    }

    public static function checkToken(string $token): array
    {
        $token = trim($token);
        if (strlen($token) < 16) return ['ok' => false, 'message' => 'لینک نامعتبر است.'];

        $u = DB::one('SELECT * FROM {p}users WHERE verify_token = :t LIMIT 1', [':t' => $token]);
        if (!$u) return ['ok' => false, 'message' => 'لینک نامعتبر یا قبلاً استفاده شده است.'];

        $at = (string)($u['verify_at'] ?? '');
        if ($at === '' || (time() - (int)strtotime($at)) > self::ttlMin() * 60) {
            self::clear((int)$u['id']);
            return ['ok' => false, 'message' => 'این لینک منقضی شده است.'];
        }

        self::markVerified((int)$u['id'], (string)($u['verify_kind'] ?? 'link'), (string)($u['verify_target'] ?? ''));
        return ['ok' => true, 'message' => 'حساب شما تایید شد.', 'user' => $u];
    }

    public static function markVerified(int $userId, string $kind, string $target): void
    {
        $data = [
            'verified_at'  => now(),
            'verify_code'  => null,
            'verify_token' => null,
            'verify_tries' => 0,
        ];
        if ($kind === 'email' && $target !== '') { $data['email'] = $target; $data['email_verified'] = 1; }
        if ($kind === 'phone' && $target !== '') { $data['phone'] = $target; $data['phone_verified'] = 1; }

        DB::update('users', $data, 'id = :id', [':id' => $userId]);
        if (class_exists('Logs')) {
            try { Logs::send('users', '✅ حساب تایید شد' . ($target !== '' ? "\nمقصد: <code>" . h($target) . '</code>' : '')); } catch (Throwable $e) { }
        }
    }

    public static function clear(int $userId): void
    {
        DB::update('users', ['verify_code' => null, 'verify_token' => null, 'verify_tries' => 0],
            'id = :id', [':id' => $userId]);
    }

    public static function link(string $token): string
    {
        return rtrim(app_url(), '/') . '/verify.php?t=' . urlencode($token);
    }

    /* ---------------- ارسال ایمیل ---------------- */

    public static function sendEmail(string $to, string $subject, string $html): bool
    {
        $driver = (string)DB::setting('sec_mail_driver', 'mail');
        try {
            return $driver === 'smtp' ? self::smtp($to, $subject, $html) : self::phpMail($to, $subject, $html);
        } catch (Throwable $e) {
            app_log('security', 'mail failed: ' . $e->getMessage(), ['to' => $to]);
            return false;
        }
    }

    private static function phpMail(string $to, string $subject, string $html): bool
    {
        if (!function_exists('mail')) return false;
        $from     = (string)DB::setting('sec_mail_from', 'no-reply@localhost');
        $fromName = (string)DB::setting('sec_mail_from_name', 'فروشگاه');
        $headers  = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>',
            'Reply-To: ' . $from,
        ]);
        $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        return @mail($to, $subj, $html, $headers);
    }

    /** کلاینت سادهٔ SMTP بدون نیاز به کتابخانه خارجی */
    private static function smtp(string $to, string $subject, string $html): bool
    {
        $host = trim((string)DB::setting('sec_smtp_host', ''));
        if ($host === '') return false;
        $port   = (int)DB::setting('sec_smtp_port', 587);
        $secure = (string)DB::setting('sec_smtp_secure', 'tls');
        $user   = (string)DB::setting('sec_smtp_user', '');
        $pass   = (string)DB::setting('sec_smtp_pass', '');
        $from   = (string)DB::setting('sec_mail_from', $user ?: 'no-reply@localhost');
        $fname  = (string)DB::setting('sec_mail_from_name', 'فروشگاه');

        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        /* بررسی گواهی TLS به‌صورت پیش‌فرض فعال است تا اطلاعات ورود SMTP قابل شنود نباشد.
           فقط در صورت مشکل گواهی سرور ایمیل: sec_smtp_insecure را 1 کنید یا app.ssl_verify را false. */
        $verifyTls = app_ssl_verify() && (string)DB::setting('sec_smtp_insecure', '0') !== '1';
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => $verifyTls,
            'verify_peer_name'  => $verifyTls,
            'allow_self_signed' => !$verifyTls,
        ]]);
        $fp  = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) { app_log('security', "smtp connect failed: $errstr"); return false; }
        stream_set_timeout($fp, 15);

        $read = function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') break;
            }
            return $data;
        };
        $cmd = function (string $c) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            return $read();
        };
        $code = fn(string $r): int => (int)substr(trim($r), 0, 3);

        $read();
        $host4helo = parse_url(app_url(), PHP_URL_HOST) ?: 'localhost';
        $r = $cmd('EHLO ' . $host4helo);

        if ($secure === 'tls') {
            $r = $cmd('STARTTLS');
            if ($code($r) !== 220) { fclose($fp); return false; }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }
            $cmd('EHLO ' . $host4helo);
        }

        if ($user !== '') {
            $r = $cmd('AUTH LOGIN');
            if ($code($r) !== 334) { fclose($fp); return false; }
            $r = $cmd(base64_encode($user));
            if ($code($r) !== 334) { fclose($fp); return false; }
            $r = $cmd(base64_encode($pass));
            if ($code($r) !== 235) { app_log('security', 'smtp auth rejected'); fclose($fp); return false; }
        }

        $r = $cmd('MAIL FROM:<' . $from . '>');
        if ($code($r) !== 250) { fclose($fp); return false; }
        $r = $cmd('RCPT TO:<' . $to . '>');
        if (!in_array($code($r), [250, 251], true)) { fclose($fp); return false; }
        $r = $cmd('DATA');
        if ($code($r) !== 354) { fclose($fp); return false; }

        $body = implode("\r\n", [
            'From: =?UTF-8?B?' . base64_encode($fname) . '?= <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($html)),
            '.',
        ]);
        $r = $cmd($body);
        $cmd('QUIT');
        fclose($fp);
        return $code($r) === 250;
    }

    private static function mailTemplate(string $shop, string $code, string $link): string
    {
        $c = h($code); $s = h($shop); $l = h($link);
        return '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;background:#f4f6fb;padding:28px">'
            . '<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:14px;padding:26px;border:1px solid #e6e9f2">'
            . '<h2 style="margin:0 0 6px;color:#1e293b;font-size:18px">' . $s . '</h2>'
            . '<p style="color:#64748b;font-size:13px;margin:0 0 18px">کد تایید حساب کاربری شما</p>'
            . '<div style="font-size:30px;font-weight:bold;letter-spacing:8px;text-align:center;'
            . 'background:#f1f5f9;border-radius:12px;padding:16px;color:#0f172a">' . $c . '</div>'
            . '<p style="color:#64748b;font-size:12px;margin:18px 0 0">یا روی لینک زیر کلیک کنید:</p>'
            . '<p style="margin:8px 0 0"><a href="' . $l . '" style="color:#3b82f6;font-size:12px">' . $l . '</a></p>'
            . '<p style="color:#94a3b8;font-size:11px;margin:18px 0 0">اگر شما این درخواست را نداده‌اید، این پیام را نادیده بگیرید.</p>'
            . '</div></div>';
    }

    /* ---------------- ارسال پیامک ---------------- */

    /** API دلخواه پیامک – جایگزین‌ها: {to} {code} {text} */
    public static function sendSms(string $to, string $text, string $code = ''): bool
    {
        if ((string)DB::setting('sec_sms_driver', 'off') === 'off') return false;
        $url = trim((string)DB::setting('sec_sms_url', ''));
        if ($url === '') return false;

        $repl = ['{to}' => $to, '{code}' => $code, '{text}' => $text];
        $url  = strtr($url, array_map('urlencode', $repl));

        $method = strtoupper((string)DB::setting('sec_sms_method', 'GET')) === 'POST' ? 'POST' : 'GET';
        $hdrs = [];
        foreach (preg_split('/\r?\n/', (string)DB::setting('sec_sms_headers', '')) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line !== '' && strpos($line, ':') !== false) $hdrs[] = $line;
        }

        $data = [];
        if ($method === 'POST') {
            $raw = strtr((string)DB::setting('sec_sms_body', ''), $repl);
            $data = jdec($raw, []);
            if (!is_array($data) || !$data) $data = ['to' => $to, 'text' => $text];
        }

        try {
            $r = http_json($url, $data, $method, $hdrs, 15);
            $ok = $r['code'] >= 200 && $r['code'] < 300;
            if (!$ok) app_log('security', 'sms http ' . $r['code'], ['body' => mb_substr((string)$r['body'], 0, 200)]);
            return $ok;
        } catch (Throwable $e) {
            app_log('security', 'sms failed: ' . $e->getMessage());
            return false;
        }
    }

    /* ---------------- محافظت ورود مدیر ---------------- */

    public static function loginMaxFail(): int { return max(0, (int)DB::setting('sec_login_max_fail', 5)); }
    public static function loginLockMin(): int { return max(1, (int)DB::setting('sec_login_lock_min', 15)); }

    private static function lockKey(string $ip): string { return 'seclock_' . md5($ip); }

    /** آیا این IP قفل است؟ خروجی: دقایق باقی‌مانده (۰ = آزاد) */
    public static function lockedFor(string $ip): int
    {
        if (self::loginMaxFail() <= 0) return 0;
        $raw = (string)DB::setting(self::lockKey($ip), '');
        if ($raw === '') return 0;
        $d = jdec($raw, []);
        $until = (int)($d['until'] ?? 0);
        if ($until <= time()) return 0;
        return (int)ceil(($until - time()) / 60);
    }

    public static function noteFail(string $ip, string $username = ''): void
    {
        if (self::loginMaxFail() <= 0) return;
        $key = self::lockKey($ip);
        $d   = jdec((string)DB::setting($key, ''), []);
        /* پنجرهٔ شمارش ۲۴ ساعته: تلاش‌های خیلی قدیمی از نو شمرده می‌شوند */
        $at  = (int)($d['at'] ?? 0);
        if ($at > 0 && $at < time() - 86400) $d['n'] = 0;
        $n   = (int)($d['n'] ?? 0) + 1;
        $out = ['n' => $n, 'until' => 0, 'at' => time()];

        if ($n >= self::loginMaxFail()) {
            $out = ['n' => 0, 'until' => time() + self::loginLockMin() * 60, 'at' => time()];
            if ((string)DB::setting('sec_login_alert', '1') === '1' && class_exists('AdminBot')) {
                try {
                    AdminBot::notifyAdmins("🚨 <b>تلاش مشکوک برای ورود به پنل</b>\n"
                        . 'آی‌پی: <code>' . h($ip) . "</code>\n"
                        . 'نام کاربری: <code>' . h($username) . "</code>\n"
                        . 'این آی‌پی به مدت ' . fa_num(self::loginLockMin()) . ' دقیقه قفل شد.');
                } catch (Throwable $e) { }
            }
        }
        DB::setSetting($key, jenc($out));
    }

    public static function noteSuccess(string $ip): void
    {
        DB::setSetting(self::lockKey($ip), '');
    }
}
