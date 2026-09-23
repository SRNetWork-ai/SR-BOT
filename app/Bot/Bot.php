<?php
declare(strict_types=1);

/**
 * مغز ربات: دریافت آپدیت‌ها و مدیریت بخش‌ها
 */
class Bot
{
    public static array $u = [];

    public static function handle(array $update): void
    {
        try {
            /* محدودساز پیام: جلوی اسپم پیام و کلیک گرفته می‌شود — مدیران معاف‌اند */
            if (class_exists('Flood')) {
                $fFrom = (int)($update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? 0);
                if ($fFrom > 0) {
                    $fRes = Flood::hit($fFrom);
                    if ($fRes !== 'ok') {
                        if ($fRes === 'notice') {
                            if (isset($update['callback_query']['id'])) {
                                Tg::answerCb((string)$update['callback_query']['id'], '⏳ تعداد درخواست‌های شما زیاد است؛ چند لحظه صبر کنید.', true);
                            } else {
                                Tg::send($fFrom, '⏳ تعداد پیام‌های شما زیاد است؛ لطفاً چند لحظه صبر کنید.');
                            }
                        }
                        return;
                    }
                }
            }
            if (isset($update['message'])) {
                self::onMessage($update['message']);
            } elseif (isset($update['callback_query'])) {
                self::onCallback($update['callback_query']);
            }
        } catch (Throwable $e) {
            app_log('bot', 'exception: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
        }
    }

    /* ================= کاربر و وضعیت ================= */

    private static function touch(array $from, ?string $startParam = null): array
    {
        $tgId = (int)$from['id'];
        $u = DB::one('SELECT * FROM {p}users WHERE tg_id = :t', [':t' => $tgId]);
        if (!$u) {
            $ref = null;
            if ($startParam && preg_match('/^ref(\\d+)$/', $startParam, $m) && (int)$m[1] !== $tgId) {
                $exists = DB::one('SELECT id FROM {p}users WHERE tg_id = :t', [':t' => (int)$m[1]]);
                if ($exists) $ref = (int)$m[1];
            }
            DB::insert('users', [
                'tg_id'      => $tgId,
                'username'   => $from['username'] ?? null,
                'first_name' => mb_substr((string)($from['first_name'] ?? ''), 0, 120),
                'last_name'  => mb_substr((string)($from['last_name'] ?? ''), 0, 120),
                'referrer_id'=> $ref,
                'last_seen'  => now(),
                'created_at' => now(),
            ]);
            $u = DB::one('SELECT * FROM {p}users WHERE tg_id = :t', [':t' => $tgId]);
            AdminBot::notifyAdmins("👤 کاربر جدید\nنام: " . h((string)$u['first_name']) . "\nآیدی: <code>$tgId</code>");
            Logs::send('users', Logs::fmt('👤 کاربر جدید', [
                'نام'      => h((string)$u['first_name']),
                'یوزرنیم'  => $u['username'] ? '@' . h((string)$u['username']) : '—',
                'آیدی'     => '<code>' . $tgId . '</code>',
                'معرف'      => $ref ? '<code>' . (int)$ref . '</code>' : '—',
            ]));
        } else {
            DB::update('users', [
                'username'   => $from['username'] ?? null,
                'first_name' => mb_substr((string)($from['first_name'] ?? ''), 0, 120),
                'last_seen'  => now(),
            ], 'id = :id', [':id' => (int)$u['id']]);
        }
        self::$u = $u;
        return $u;
    }

    private static function setState(?string $state, array $data = []): void
    {
        DB::update('users', ['state' => $state, 'state_data' => $data ? jenc($data) : null],
            'id = :id', [':id' => (int)self::$u['id']]);
        self::$u['state'] = $state;
        self::$u['state_data'] = $data ? jenc($data) : null;
    }

    private static function stateData(): array { return jdec(self::$u['state_data'] ?? null, []); }

    private static function isAdmin(): bool { return is_admin_id(self::$u['tg_id'] ?? 0); }

    /* ================= پیام‌ها ================= */

    private static function onMessage(array $msg): void
    {
        $from = $msg['from'] ?? null;
        if (!$from || !empty($from['is_bot'])) return;
        $chatId = $msg['chat']['id'] ?? $from['id'];
        if (($msg['chat']['type'] ?? 'private') !== 'private') {
            // در گروه‌ها فقط به دستور /id پاسخ می‌دهیم تا شناسه گروه گزارشات گرفته شود
            $gt = trim((string)($msg['text'] ?? ''));
            if ($gt === '/id' || str_starts_with($gt, '/id@')) {
                $t = "\\xf0\\x9f\\x86\\x94 <b>شناسه این گروه</b>\n<code>" . (int)($msg['chat']['id'] ?? 0) . "</code>\n";
                if (!empty($msg['message_thread_id'])) $t .= 'شناسه تاپیک فعلی: <code>' . (int)$msg['message_thread_id'] . "</code>\n";
                $t .= "\nاین شناسه را در پنل مدیریت ← تنظیمات ← گروه گزارشات وارد کنید.";
                $ex = !empty($msg['message_thread_id']) ? ['message_thread_id' => (int)$msg['message_thread_id']] : [];
                Tg::send($chatId, $t, null, $ex);
            }
            return;
        }

        $text = clean_text($msg['text'] ?? ($msg['caption'] ?? ''));
        $startParam = null;
        if (str_starts_with($text, '/start')) {
            $parts = explode(' ', $text, 2);
            $startParam = isset($parts[1]) ? trim($parts[1]) : null;
        }

        $u = self::touch($from, $startParam);

        if ((int)$u['is_banned'] === 1) {
            Tg::send($chatId, '⛔️ دسترسی شما به ربات مسدود شده است.');
            return;
        }
        if ((string)DB::setting('maintenance', '0') === '1' && !self::isAdmin()) {
            Tg::send($chatId, '🛠 ربات در حال به‌روزرسانی است. لطفاً بعداً تلاش کنید.');
            return;
        }
        if (!self::checkChannel($chatId)) return;

        if ($text === Kb::CANCEL) {
            self::setState(null);
            self::mainMenu($chatId, 'عملیات لغو شد.');
            return;
        }
        if (str_starts_with($text, '/start')) {
            self::setState(null);
            if (!self::fjGate($chatId, 'start')) return;
            if (self::verifyGate($chatId)) return;
            self::mainMenu($chatId);
            self::verifyNudge($chatId);
            return;
        }
        if ($text === '/id') {
            Tg::send($chatId, 'آیدی عددی شما: <code>' . (int)$u['tg_id'] . '</code>');
            return;
        }

        // حالت‌های گفتگویی
        /* شماره‌ای که با دکمهٔ «ارسال شمارهٔ من» فرستاده می‌شود */
        if ($text === '' && isset($msg['contact']['phone_number'])) {
            $text = en_num((string)$msg['contact']['phone_number']);
        }

        $state = (string)($u['state'] ?? '');
        if ($state !== '' && !Kb::isMenuButton($text)) {
            if (self::handleState($chatId, $state, $text, $msg)) return;
        }

        /*
         * رسید بدون حالت گفتگو:
         * درخواست‌های شارژ که از د��خل مینی‌اپ ثبت می‌��وند هیچ حالتی در ربات
         * نمی‌سازند، پس قبلاً ارسال عکس رسید در چت ربات بی‌واکنش می‌ماند.
         */
        if ($state === '' && (string)DB::setting('wallet_receipt_any', '1') === '1') {
            if (self::attachLooseReceipt($chatId, $msg)) return;
        }

        /* تا وقتی حساب تایید نشده، هیچ بخشی از ربات کار نمی‌کند */
        if (self::verifyGate($chatId)) return;

        /* دکمه‌های تعریف‌شده در پنل مدیریت */
        if (class_exists('Btn')) {
            try {
                $bm = Btn::match($text);
                if ($bm !== null && self::runButton($chatId, null, $bm)) return;
            } catch (Throwable $e) {
            }
        }

        switch ($text) {
            case Kb::PRODUCTS: self::sectionProducts($chatId); return;
            case Kb::CUSTOM:   self::cusMenu($chatId); return;
            case Kb::SERVICES: self::sectionServices($chatId); return;
            case Kb::WALLET:   self::sectionWallet($chatId); return;
            case Kb::ACCOUNT:  self::sectionAccount($chatId); return;
            case Kb::TEST:     self::sectionTest($chatId); return;
            case Kb::GIFT:
                self::setState('gift_code');
                Tg::send($chatId, '🎁 کد هدیه خود را ارسال کنید:', Kb::cancel());
                return;
            case Kb::TUTORIAL: self::sectionTutorials($chatId); return;
            case Kb::SUPPORT:  self::sectionSupport($chatId); return;
            case Kb::RESELLER: self::sectionReseller($chatId); return;
            case Kb::ADMIN:
                if (self::isAdmin()) AdminBot::home($chatId);
                else self::mainMenu($chatId);
                return;
        }

        self::mainMenu($chatId, 'یکی از گزینه‌های منو را انتخاب کنید 👇');
    }

    /* ================= جوین اجباری ================= */

    /** سازگاری با گذشته: بررسی عضویت روی هر پیام از سیستم جدید استفاده می‌کند */
    private static function checkChannel($chatId): bool
    {
        return self::fjGate($chatId, 'msg');
    }

    /** کانال‌های فعال جوین اجباری + پشتیبانی از تنظیم قدیمی force_channel */
    private static function fjRows(): array
    {
        if ((string)DB::setting('fj_enabled', '') === '0') return [];
        $out  = [];
        $rows = jdec((string)DB::setting('fj_channels', ''), []);
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_array($r) || (int)($r['on'] ?? 0) !== 1) continue;
                $id = trim((string)($r['id'] ?? ''));
                if ($id === '') continue;
                $out[] = [
                    'id'    => $id,
                    'title' => trim((string)($r['title'] ?? '')),
                    'link'  => trim((string)($r['link'] ?? '')),
                ];
            }
        }
        /* اگر بخش جدید هنوز ذخیره نشده باشد، کانال قدیمی force_channel معتبر می‌ماند */
        if (!$out && (string)DB::setting('fj_enabled', '') === '') {
            $ch = trim((string)DB::setting('force_channel', ''));
            if ($ch !== '') {
                $out[] = [
                    'id'    => str_starts_with($ch, '@') || str_starts_with($ch, '-') ? $ch : '@' . $ch,
                    'title' => '',
                    'link'  => '',
                ];
            }
        }
        return $out;
    }

    /** لینک عضویت یک کانال */
    private static function fjLink(array $r): string
    {
        $link = trim((string)($r['link'] ?? ''));
        if ($link !== '') return $link;
        $id = trim((string)($r['id'] ?? ''));
        return str_starts_with($id, '@') ? 'https://t.me/' . ltrim($id, '@') : 'https://t.me';
    }

    /** کانال‌هایی که کاربر فعلی هنوز عضوشان نشده است */
    private static function fjMissing(bool $fresh = false): array
    {
        static $memo = null;
        if (!$fresh && $memo !== null) return $memo;
        $rows = self::fjRows();
        if (!$rows || self::isAdmin()) return [];
        $uid = (int)(self::$u['tg_id'] ?? 0);
        if ($uid <= 0) return [];

        /* کش کوتاه‌مدت: اگر اخیراً عضویت کامل تایید شده، دوباره از تلگرام پرسیده نمی‌شود */
        $cacheMin = max(0, (int)DB::setting('fj_cache_min', 3));
        $cacheDir = APP_ROOT . '/storage/fjcache';
        $cacheF   = $cacheDir . '/u' . $uid;
        if (!$fresh && $cacheMin > 0 && is_file($cacheF) && (int)@filemtime($cacheF) > time() - $cacheMin * 60) {
            return $memo = [];
        }

        $miss = [];
        foreach ($rows as $r) {
            if (!Tg::isMember((string)$r['id'], $uid)) $miss[] = $r;
        }
        if (!$miss) {
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
            @touch($cacheF);
        } elseif (is_file($cacheF)) {
            @unlink($cacheF);
        }
        return $memo = $miss;
    }

    /** پیام عضویت اجباری با دکمهٔ کانال‌ها و بررسی مجدد */
    private static function fjPrompt($chatId, array $miss, $msgId = null): void
    {
        /* آمار: چند بار دیوار عضویت به کاربران نمایش داده شده است */
        if ($msgId === null) {
            try { DB::setSetting('fj_stat_shown', (string)((int)DB::setting('fj_stat_shown', 0) + 1)); } catch (Throwable $e) { }
        }
        $txt = trim((string)DB::setting('fj_text', ''));
        if ($txt === '') {
            $txt = "🔒 <b>عضویت در کانال</b>\n<code>───────────────</code>\n"
                 . "برای استفاده از ربات ابتدا در کانال‌های زیر عضو شوید\n"
                 . 'و سپس روی «✅ عضو شدم» بزنید 👇';
        }
        $kb = [];
        $i  = 1;
        foreach ($miss as $r) {
            $t = trim((string)($r['title'] ?? ''));
            if ($t === '') $t = ltrim((string)($r['id'] ?? ''), '@');
            if ($t === '') $t = 'کانال ' . fa_num($i);
            $kb[] = [Tg::url('📣 ' . $t, self::fjLink($r))];
            $i++;
        }
        $kb[] = [Tg::btn('✅ عضو شدم — بررسی کن', 'fj:chk')];
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($kb)) : Tg::send($chatId, $txt, Tg::ikb($kb));
    }

    /**
     * گیت جوین اجباری — true یعنی کاربر مجاز است.
     * محدودهٔ بررسی: all = همهٔ پیام‌ها و دکمه‌ها | start = فقط /start | buy = فقط خرید و اکانت تست
     */
    private static function fjGate($chatId, string $point = 'msg', $msgId = null): bool
    {
        $rows = self::fjRows();
        if (!$rows || self::isAdmin()) return true;
        $scope = (string)DB::setting('fj_scope', 'all');
        if (!in_array($scope, ['all', 'start', 'buy'], true)) $scope = 'all';
        if ($scope !== 'all' && $point !== $scope) return true;
        $miss = self::fjMissing();
        if (!$miss) return true;
        self::fjPrompt($chatId, $miss, $msgId);
        return false;
    }

    /** کیبورد منوی اصلی با در نظر گرفتن نقش کاربر */
    /** پیام موقت «کمی صبر کنید» – شناسهٔ پیام را برمی‌گرداند تا بعداً ویرایش شود */
    private static function waitMsg($chatId, string $text, $keyboard = null): int
    {
        $r = Tg::send($chatId, $text, $keyboard);
        return (int)($r['result']['message_id'] ?? 0);
    }

    /** جایگزینی پیام موقت با محتوای نهایی */
    private static function waitEdit($chatId, int $msgId, string $text, $keyboard = null): void
    {
        if ($msgId > 0) Tg::edit($chatId, $msgId, $text, $keyboard);
        else Tg::send($chatId, $text, $keyboard);
    }

    /**
     * دروازهٔ تایید حساب
     * وقتی تایید اجباری و دامنهٔ آن «همهٔ بخش‌های ربات» باشد، کاربر تاییدنشده
     * فقط کارت تایید حساب را می‌بیند و به بقی����ٔ بخش‌ها دسترسی ندارد.
     * @return bool true یعنی درخواست متوقف شود
     */
    private static function verifyGate($chatId): bool
    {
        if (!class_exists('Security')) return false;

        try {
            if (self::isAdmin()) return false;
            if (!Security::needs(self::$u, 'any')) return false;

            $shop  = (string)DB::setting('shop_title', 'فروشگاه کانفیگ');
            $kinds = Security::activeKinds();
            $names = implode(' یا ', array_map(static fn($m) => (string)$m['label'], $kinds));

            $rows = [];
            if (isset($kinds['link']))  $rows[] = [Tg::btn('🔗 دریافت لینک تایید', 'verify:link')];
            if (isset($kinds['email'])) $rows[] = [Tg::btn('📧 تایید با ایمیل', 'verify:email')];
            if (isset($kinds['phone'])) $rows[] = [Tg::btn('📱 تایید با شماره موبایل', 'verify:phone')];
            $rows[] = [Tg::btn('✅ تایید حساب کاربری', 'verify:menu')];

            Tg::send(
                $chatId,
                "🔐 <b>تایید حساب کاربری</b>\n"
                . "<code>─────────────────</code>\n"
                . 'به <b>' . h($shop) . "</b> خوش آمدید 👋\n\n"
                . "برای جلوگیری از سوءاستفاده، پیش از استفاده از ربات باید حساب خود را تایید کنید.\n"
                . '🔑 روش‌های فعال: <b>' . h($names !== '' ? $names : '—') . "</b>\n\n"
                . '👇 روی دکمهٔ زیر بزنید. پس از تایید، همهٔ ��خش‌های ربات برای شما باز می‌شود.',
                Tg::ikb($rows)
            );
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** یادآور تایید حساب – بلافاصله پس از /start وقتی تایید اجباری است */
    private static function verifyNudge($chatId): void
    {
        if (!class_exists('Security')) return;
        try {
            if (Security::mode() !== 'required' || !Security::enabled()) return;
            if (Security::isVerified(self::$u)) return;
            $kinds = Security::activeKinds();
        } catch (Throwable $e) {
            return;
        }
        if (!$kinds) return;

        $names = [];
        foreach ($kinds as $meta) {
            $names[] = trim((string)($meta['icon'] ?? '') . ' ' . (string)($meta['label'] ?? ''));
        }

        $txt = "🔐 <b>تایید حساب لازم است</b>\n"
            . "<code>─────────────────</code>\n"
            . "برای استفاده از خدمات ربات ابتدا باید حساب خود را تایید کنید.\n\n"
            . '🧩 روش‌های فعال: ' . implode('  •  ', $names) . "\n\n"
            . "کافی است دکمهٔ زیر را بزنید؛ کمتر از یک دقیقه طول می‌کشد.";

        Tg::send($chatId, $txt, Tg::ikb([
            [Tg::btn('🔐 تایید حساب من', 'ver:menu')],
        ]));
    }

    /** متن قابل ویرایش از پنل مدیریت */
    private static function tx(string $key, string $def = ''): string
    {
        if (class_exists('Txt')) {
            try { return Txt::t($key, $def); } catch (Throwable $e) {}
        }
        return $def;
    }

    /** آیا کاربر جاری نماینده است؟ */
    private static function isRs(): bool
    {
        if (!class_exists('Reseller')) return false;
        try { return Reseller::isReseller((array)(self::$u ?? [])); } catch (Throwable $e) { return false; }
    }

    /**
     * اجرای یک دکمهٔ منو (چه کیبوردی چه شیشه‌ای)
     */
    /** باز کردن یک زیرمنوی ساخته‌شده در پنل مدیریت */
    public static function openBtnMenu($chatId, $msgId, string $menu): void
    {
        if (!class_exists('Btn') || $menu === '') return;

        $rows = Btn::subRows($menu, self::isAdmin(), self::isRs());
        if ($rows === []) {
            Tg::send($chatId, '⚠️ این بخش فعلاً دکمه‌ای ندارد.', self::kbMain());
            return;
        }

        $rows[] = Kb::navRow('menu:main');
        $txt = '<b>' . h(Btn::menuTitle($menu)) . "</b>

"
             . 'یکی از گزینه‌های زیر را انتخاب کنید 👇';

        if ($msgId) Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows));
        else        Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    public static function runButton($chatId, $msgId, array $b): bool
    {
        $kind = (string)($b['kind'] ?? 'builtin');

        if ($kind === 'url') {
            $u = trim((string)($b['url'] ?? ''));
            if ($u === '') return false;
            Tg::send($chatId, '🔗 <b>' . h((string)($b['label'] ?? '')) . '</b>',
                Tg::ikb([[Tg::url('🌐 باز کردن', $u)]]));
            return true;
        }

        if ($kind === 'text') {
            $t = trim((string)($b['text'] ?? ''));
            if ($t === '') return false;
            Tg::send($chatId, $t, self::kbMain());
            return true;
        }

        if ($kind === 'miniapp') {
            $mb = self::miniappBtn();
            if ($mb === null) {
                Tg::send($chatId, '⚠️ مینی‌اپ فعال نیست.', self::kbMain());
                return true;
            }
            Tg::send($chatId, '🚀 <b>' . h((string)($b['label'] ?? 'اپلیکیشن')) . '</b>', Tg::ikb([[$mb]]));
            return true;
        }

        if ($kind === 'menu') {
            self::openBtnMenu($chatId, $msgId, (string)($b['key'] ?? ''));
            return true;
        }

        if ($kind === 'copy') {
            $c = trim((string)($b['copy'] ?? ''));
            if ($c === '') return false;
            Tg::send($chatId,
                '�� <b>' . h((string)($b['label'] ?? '')) . "</b>

<code>" . h($c) . '</code>'
                . "

<i>برای کپی، روی متن بالا بزنید.</i>",
                self::kbMain());
            return true;
        }

        if ($kind === 'share') {
            $un  = ltrim(trim((string)DB::setting('bot_username', '')), '@');
            $shr = trim((string)($b['text'] ?? ''));
            if ($shr === '') $shr = 'این ربات را امتحان کن 👌';

            $rws = [];
            if ($un !== '') {
                $rws[] = [Tg::url('📤 ارسال برای دوستان',
                    'https://t.me/share/url?url=' . rawurlencode('https://t.me/' . $un)
                    . '&text=' . rawurlencode($shr))];
            }
            $rws[] = [['text' => '💬 انتخاب گفتگو', 'switch_inline_query' => $shr]];
            $rws[] = [Tg::btn('🏠 منوی اصلی', 'menu:main')];

            Tg::send($chatId, '📣 <b>' . h((string)($b['label'] ?? 'معرفی ربات')) . "</b>

" . h($shr),
                Tg::ikb($rws));
            return true;
        }

        if ($kind === 'contact') {
            self::setState('set_phone');
            Tg::send($chatId, '📱 برای ثبت شمارهٔ تماس، دکمهٔ زیر را بزنید:', Tg::rkb([
                [['text' => '📲 ارسال شمارهٔ من', 'request_contact' => true]],
                [['text' => Kb::CANCEL]],
            ]));
            return true;
        }

        if ($kind === 'location') {
            Tg::send($chatId, '📍 موقعیت خود را با دکمهٔ زیر بفرستید:', Tg::rkb([
                [['text' => '🗺 ارسال موقعیت من', 'request_location' => true]],
                [['text' => Kb::CANCEL]],
            ]));
            return true;
        }

        switch ((string)($b['key'] ?? '')) {
            case 'products': self::sectionProducts($chatId, $msgId); return true;
            case 'custom':   self::cusMenu($chatId); return true;
            case 'services': self::sectionServices($chatId, $msgId); return true;
            case 'wallet':   self::sectionWallet($chatId, $msgId); return true;
            case 'account':  self::sectionAccount($chatId); return true;
            case 'test':     self::sectionTest($chatId); return true;
            case 'gift':
                self::setState('gift_code');
                Tg::send($chatId, self::tx('gift_ask', '🎁 کد هدیه خود را ارسال کنید:'), Kb::cancel());
                return true;
            case 'tutorial': self::sectionTutorials($chatId); return true;
            case 'support':  self::sectionSupport($chatId, $msgId); return true;
            /* fixed76: دکمه‌های جدید قابل‌افزودن از «دکمه‌های ربات» */
            case 'tickets':  self::sectionSupport($chatId, $msgId); return true;
            case 'renew':    self::sectionServices($chatId, $msgId); return true;
            case 'campaign': self::sectionCampaign($chatId); return true;
            case 'prices':   self::priceList($chatId); return true;
            case 'orders':   self::sectionOrders($chatId); return true; /* fixed79 */
            case 'reseller': self::sectionReseller($chatId); return true;
            case 'rsreq':    self::resellerRequest($chatId); return true;
            case 'rssvcs':
                if (self::isRs()) self::sectionResellerServices($chatId, $msgId);
                else self::sectionServices($chatId, $msgId);
                return true;
            case 'referral': self::sectionReferral($chatId, $msgId); return true;
            case 'stock':    self::sectionStock($chatId, $msgId); return true;

            /* ---------- دکمه‌های کیف پول ---------- */
            case 'wal_card':   self::walletCardMenu($chatId); return true;
            case 'wal_crypto': self::cryptoAssets($chatId, $msgId); return true;
            case 'wal_np':     self::askAmount($chatId, 'nowpay'); return true;
            case 'wal_hp':     self::askAmount($chatId, 'hooshpay'); return true;
            case 'wal_hash':   self::askAmount($chatId, 'crypto_hash'); return true;
            case 'wal_hist':   self::walletHistory($chatId, $msgId); return true;

            /* ---------- حساب کاربری ---------- */
            case 'acc_phone':
                self::setState('set_phone');
                Tg::send($chatId, '📱 شماره تماس خود را ارسال کنید:', Kb::cancel());
                return true;
            case 'acc_email':
                self::setState('set_email');
                Tg::send($chatId, '✉️ ایمیل خود را ارسال کنید:', Kb::cancel());
                return true;
            case 'acc_name':
                self::setState('set_name');
                Tg::send($chatId, '👤 نام و نام خانوادگی خود را ارسال کنید:', Kb::cancel());
                return true;
            case 'verify': self::verifyMenu($chatId, $msgId); return true;
            case 'refs':   self::referralList($chatId, $msgId); return true;

            /* ---------- نمایندگی ---------- */
            case 'rspay':
                if (self::isRs()) self::resellerPay($chatId);
                else self::sectionAccount($chatId);
                return true;
            case 'rsapp': {
                $u = self::resellerAppUrl();
                if ($u === '') {
                    Tg::send($chatId, '⚠️ مینی اپ فعال نیست.', self::kbMain());
                    return true;
                }
                Tg::send($chatId, '🎛 <b>مینی اپ پنل نمایندگی</b>' . "\n\n"
                    . 'ساخت کانفیگ، مدیریت مصرف و کیف پول در یک محیط سه بعدی زیبا 👇',
                    Tg::ikb([[['text' => '🎛 باز کردن پنل نمایندگی', 'web_app' => ['url' => $u]]]]));
                return true;
            }

            /* ---------- مدیریت ---------- */
            /* fixed80: دکمهٔ درون‌ساخت «اپلیکیشن» (قبلاً هندلر نداشت و بی‌اثر بود) */
            case 'miniapp': {
                $mb = self::miniappBtn();
                if ($mb === null) {
                    Tg::send($chatId, '⚠️ مینی‌اپ فعال نیست.', self::kbMain());
                    return true;
                }
                Tg::send($chatId, '🚀 <b>' . h((string)($b['label'] ?? 'اپلیکیشن')) . '</b>', Tg::ikb([[$mb]]));
                return true;
            }
            case 'home': self::mainMenu($chatId); return true;
            case 'admin_web': {
                if (!self::isAdmin()) { self::mainMenu($chatId); return true; }
                $base = function_exists('app_url') ? rtrim((string)app_url(), '/') : '';
                if ($base === '') {
                    Tg::send($chatId, '⚠️ آدرس سایت تنظیم نشده است.', self::kbMain());
                    return true;
                }
                Tg::send($chatId, '🖥 <b>پنل مدیریت وب</b>',
                    Tg::ikb([[Tg::url('🔐 ورود به پنل مدیریت', $base . '/admin/')]]));
                return true;
            }
            case 'admin':
                if (self::isAdmin()) AdminBot::home($chatId);
                else self::mainMenu($chatId);
                return true;
        }
        return false;
    }

    /**
     * فهرست درگاه‌های ارزی برای انتخاب کاربر
     * برای هر ارز می‌تواند چند شبکه وجود داشته باشد
     */
    /** گام ۱ پرداخت ارزی: انتخاب ارز و شبکه پیش از وارد کردن مبلغ */
    private static function cryptoAssets($chatId, $msgId = null): void
    {
        $gws = [];
        if (class_exists('Gateway')) {
            try { $gws = Gateway::crypto(self::isRs()); } catch (Throwable $e) { $gws = []; }
        }

        /* اگر مدیر فقط تنظیم تک‌آدرسی قدیمی دارد */
        if ($gws === []) {
            self::askAmount($chatId, 'crypto');
            return;
        }

        $l = [];
        $l[] = '🌐 <b>پرداخت ارزی (دستی)</b>';
        $l[] = '<code>���────────────────</code>';
        $l[] = 'اول مشخص کنید با <b>کدام ارز</b> و روی <b>کدام شبکه</b> می‌خواهید واریز کنید.';
        $l[] = 'پس از انتخاب، نرخ لحظه‌ای و آدرس <b>همان ار��</b> نمایش داده می‌شود.';

        $rows = [];
        $n = 0;
        foreach ($gws as $g) {
            if ($n >= 12) break;
            $rows[] = [Tg::btn(trim((string)$g['icon'] . ' ' . Gateway::title($g)), 'wgp:' . $g['id'])];
            $n++;
        }
        $rows[] = Kb::backRow('menu:wallet');

        $txt = implode("\n", $l);
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    /** گام ۲ پرداخت ارزی: دریافت مبلغ برای ارز انتخاب‌شده */
    private static function cryptoAskAmount($chatId, $msgId, string $gwId): void
    {
        if (!class_exists('Gateway')) { self::askAmount($chatId, 'crypto'); return; }

        $g = Gateway::byId($gwId);
        if ($g === null || $g['kind'] !== 'crypto') {
            self::cryptoAssets($chatId, $msgId);
            return;
        }

        self::setState('wallet_amount', ['method' => 'crypto', 'gw' => (string)$g['id']]);

        $asset = (string)$g['asset'];
        $lim   = Gateway::limits($g);
        $min   = (int)$lim['min'];
        $max   = (int)$lim['max'];
        $gwFee = (float)($g['fee'] ?? 0);

        /* نرخ واحد همین ارز در لحظه */
        $unit = 0.0;
        try {
            $ref = 10000000;
            $q   = Gateway::qty($g, $ref);
            if ($q > 0) $unit = $ref / $q;
        } catch (Throwable $e) {
            $unit = 0.0;
        }

        $l = [];
        $l[] = '💰 <b>شارژ کیف پول</b>';
        $l[] = trim((string)$g['icon']) . ' ارز انتخابی: <b>' . h(Gateway::title($g)) . '</b>';
        $l[] = '<code>─────────────────</code>';
        if ($unit > 0) {
            $l[] = '📈 نرخ لحظه‌ای: <b>۱ ' . h($asset) . ' ≈ ' . money((int)round($unit)) . ' ' . currency() . '</b>';
        }
        $l[] = 'مبلغ مورد نظر را به <b>' . currency() . '</b> و فقط با عدد ارسال کنید.';
        if ($min > 0) $l[] = '⬇️ حداقل: <b>' . money($min) . ' ' . currency() . '</b>';
        if ($max > 0) $l[] = '⬆️ حداکثر: <b>' . money($max) . ' ' . currency() . '</b>';
        if ($gwFee > 0) {
            $l[] = '💠 کارمزد این شبکه: <b>' . fa_num((string)abs($gwFee)) . '٪</b> (به مقدار ارز اضافه می‌ش��د)';
        } elseif ($gwFee < 0) {
            $l[] = '🎁 تخفیف این درگاه: <b>' . fa_num((string)abs($gwFee)) . '٪</b>';
        }
        $l[] = '';
        $l[] = '📍 مقدار دقیق ' . h($asset) . ' و آدرس واریز، پس از ارسال مبلغ نمایش داده می‌شود.';

        $txt = implode("\n", $l);
        $rows = [
            [Tg::btn('🔁 تغییر ارز یا شبکه', 'wal:crypto')],
            Kb::backRow('menu:wallet'),
        ];
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    private static function cryptoChooser($chatId, $msgId, int $amount): void
    {
        if ($amount <= 0) {
            $d = self::stateData();
            $amount = (int)($d['amount'] ?? 0);
        }
        if ($amount <= 0) {
            Tg::send($chatId, '⚠️ مبلغ پیدا نشد. از کیف پول دوباره شروع کنید.', self::kbMain());
            return;
        }

        $gws = [];
        if (class_exists('Gateway')) {
            try { $gws = Gateway::crypto(self::isRs()); } catch (Throwable $e) { $gws = []; }
        }

        /* سازگاری با تنظیم تک‌آدرسی قدیمی */
        if ($gws === []) {
            $addr = trim((string)DB::setting('crypto_address', ''));
            if ($addr === '') {
                self::setState(null);
                Tg::send($chatId, '⚠️ هیچ درگاه ارزی توسط مدیر تنظیم نشده است.', self::kbMain());
                return;
            }
            self::setState('wallet_txid', ['amount' => $amount, 'method' => 'crypto']);
            $usd = Wallet::toUsd($amount);
            $txt = "🌐 <b>پرداخت ارزی</b>
"
                . "<code>─────────────────</code>
"
                . '🧾 مبلغ: <b>' . money($amount) . ' ' . currency() . "</b>
"
                . '💵 معادل دلاری: <b>' . fa_num((string)$usd) . " $</b>
"
                . '📥 آدرس کیف پول:' . "
<code>" . h($addr) . "</code>
"
                . self::tx('wallet_crypto_note', '✅ پس از واریز، هش تراکنش (TXID) را ارسال کنید.');
            $msgId ? Tg::edit($chatId, $msgId, $txt, Kb::cancel()) : Tg::send($chatId, $txt, Kb::cancel());
            return;
        }

        self::setState('wallet_txid', ['amount' => $amount, 'method' => 'crypto']);

        $l = [];
        $l[] = '🌐 <b>پرداخت ارزی (دستی)</b>';
        $l[] = '<code>─────────────────</code>';
        $l[] = '🧾 مبلغ: <b>' . money($amount) . ' ' . currency() . '</b>';
        $l[] = '';
        $l[] = '👇 ارز و شبکهٔ مورد نظر خود را انتخاب کنید:';
        $l[] = '⏱ مقدار دقیق پس از انتخاب، با نرخ <b>همان لحظه</b> محاسبه می‌شود.';

        $rows = [];
        $n = 0;
        foreach ($gws as $g) {
            if ($n >= 12) break;
            $rows[] = [Tg::btn(trim((string)$g['icon'] . ' ' . Gateway::title($g)), 'wgw:' . $g['id'] . ':' . $amount)];
            $n++;
        }
        $rows[] = Kb::backRow('menu:wallet');

        $txt = implode("
", $l);
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    /** نمایش جزئیات درگاه ارزی انتخاب‌شده */
    private static function cryptoGateway($chatId, $msgId, $cbId, string $gwId, int $amount): void
    {
        Tg::answerCb($cbId, '⏳ محاسبهٔ نرخ…');
        if (!class_exists('Gateway')) return;

        $g = Gateway::byId($gwId);
        if ($g === null || $g['kind'] !== 'crypto') {
            Tg::send($chatId, '⚠️ این درگاه دیگر در دسترس نیست.', self::kbMain());
            return;
        }
        if ($amount <= 0) {
            $d = self::stateData();
            $amount = (int)($d['amount'] ?? 0);
        }
        if ($amount <= 0) {
            Tg::send($chatId, '⚠️ مبلغ پیدا نشد. از کیف پول دوباره شروع کنید.', self::kbMain());
            return;
        }

        $asset = (string)$g['asset'];
        $qty   = Gateway::qty($g, $amount);
        $dec   = Gateway::decimals($asset);

        self::setState('wallet_txid', ['amount' => $amount, 'method' => 'crypto', 'gw' => (string)$g['id']]);

        $l = [];
        $l[] = '🌐 <b>پرداخت ارزی</b> — ' . h(Gateway::title($g));
        $l[] = '<code>─────────────────</code>';
        $l[] = '🧾 مبلغ سفارش: <b>' . money($amount) . ' ' . currency() . '</b>';
        $l[] = trim((string)$g['icon']) . ' ارز: <b>' . h($asset) . '</b>  •  شبکه: <code>'
            . h(Gateway::netLabel($asset, (string)$g['network'])) . '</code>';
        if ($qty > 0) {
            $l[] = '🔹 مقدار واریزی: <b>' . fa_num(number_format($qty, $dec, '.', '')) . ' ' . h($asset) . '</b>';
        }
        $l[] = '📥 آدرس کیف پول:';
        $l[] = '<code>' . h((string)$g['address']) . '</code>';
        if ((string)$g['memo'] !== '') {
            $l[] = '🏷 ممو / تگ: <code>' . h((string)$g['memo']) . '</code>';
            $l[] = '⚠️ بدون ممو، تراکنش قابل شناسایی نیست.';
        }
        if ((string)$g['note'] !== '') $l[] = 'ℹ️ ' . h((string)$g['note']);
        $l[] = '<code>────────────────����</code>';
        $l[] = '⏱ مقدار بالا با نرخ <b>همین لحظه</b> محاسبه شد.';
        $l[] = self::tx('wallet_crypto_note', '✅ پس از واریز، هش تراکنش (TXID) را همین‌جا ارسال کنید.');

        $rows = [
            [Tg::btn('🔁 تغییر ارز یا شبکه', 'wgwl:' . $amount)],
            Kb::backRow('menu:wallet'),
        ];

        $txt = implode("
", $l);
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    /** پرداخت هزینهٔ نمایندگی از موجودی کیف پول */
    private static function resellerPay($chatId): void
    {
        if (!class_exists('Reseller')) return;

        $u = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)self::$u['id']]) ?: (array)self::$u;
        $r = Reseller::payRequest($u, '');

        if (empty($r['ok'])) {
            $m = '❌ ' . (string)($r['message'] ?? 'انجام نشد.');
            if (!empty($r['need'])) {
                $m .= "
💰 کمبود موجودی: <b>" . money((int)$r['need']) . ' ' . currency() . '</b>';
            }
            Tg::send($chatId, $m, self::kbMain());
            return;
        }

        $l = [];
        if (!empty($r['approved'])) {
            $l[] = self::tx('rs_welcome', '🎉 <b>حساب شما به نمایندگی ارتقا یافت!</b>');
            $l[] = '🏷 سطح: <b>' . h(Reseller::levelLabel((int)($r['level'] ?? 1))) . '</b>';
        } else {
            $l[] = self::tx('rs_req_ok', '✅ درخواست نمایندگی شما ثبت شد و در انتظار بررسی است.');
        }
        if (!empty($r['fee']))    $l[] = '💰 پرداخت‌شده: ' . money((int)$r['fee']) . ' ' . currency();
        if (!empty($r['credit'])) $l[] = '🎁 برگشت به اعتبار شما: ' . money((int)$r['credit']) . ' ' . currency();

        Tg::send($chatId, implode("
", $l), self::kbMain());

        try {
            if (empty($r['approved'])) AdminBot::notifyResellerRequest((int)$u['id'], 'پرداخت هزینه انجام شد');
        } catch (Throwable $e) {
        }
    }

    public static function kbMain(): array
    {
        $isRs = false;
        if (class_exists('Reseller')) {
            $isRs = Reseller::isReseller((array)(self::$u ?? []));
        }
        return Kb::main(self::isAdmin(), $isRs, self::cusCfg() !== null);
    }

    public static function mainMenu($chatId, ?string $text = null): void
    {
        $title = (string)DB::setting('shop_title', 'فروشگاه کانفیگ');
        $body  = $text ?? ('<b>' . h($title) . "</b>\n\n" . (string)DB::setting('welcome_text', ''));
        Tg::send($chatId, $body, self::kbMain());

        /* منوی شیشه‌ای در صورت انتخاب مدیر */
        if (class_exists('Btn')) {
            try {
                $bmode = Btn::mode();
                if ($bmode === 'inline' || $bmode === 'both') {
                    $irows = Btn::inlineRows(self::isAdmin(), self::isRs());
                    if ($irows !== []) {
                        Tg::send($chatId, self::tx('start_help', '👇 یکی از گزینه‌ها را انتخاب کنید:'), Tg::ikb($irows));
                    }
                }
            } catch (Throwable $e) {
            }
        }

        /* معرفی مینی‌اپ – فقط در منوی اصلی */
        if ($text === null && ($mb = self::miniappBtn())) {
            Tg::send($chatId, '⚡️ <b>نسخهٔ اپلیکیشنی ربات</b> – سر����ع‌تر، زیباتر و کامل‌تر:',
                Tg::ikb([[$mb]]));
        }
    }

    /* ================= کالبک‌ها ================= */

    private static function onCallback(array $cb): void
    {
        $from    = $cb['from'] ?? [];
        $chatId  = $cb['message']['chat']['id'] ?? ($from['id'] ?? 0);
        $msgId   = $cb['message']['message_id'] ?? null;
        $data    = (string)($cb['data'] ?? '');
        $cbId    = $cb['id'] ?? '';
        if (!$from) return;

        self::touch($from);
        if ((int)self::$u['is_banned'] === 1) { Tg::answerCb($cbId, 'دسترسی مسدود است.', true); return; }

        [$key, $arg, $arg2] = array_pad(explode(':', $data, 3), 3, null);

        /* جوین اجباری روی دکمه‌های شیشه‌ای — حالت «همهٔ پیام‌ها» */
        if ($key !== 'fj' && !self::fjGate($chatId, 'cb')) { Tg::answerCb($cbId); return; }

        if (str_starts_with($data, 'adm:')) {
            if (!self::isAdmin()) { Tg::answerCb($cbId, 'دسترسی ندارید.', true); return; }
            AdminBot::callback($chatId, $msgId, $cbId, (string)$arg, (string)$arg2);
            return;
        }

        switch ($key) {
            case 'bmenu': {
                Tg::answerCb($cbId);
                self::openBtnMenu($chatId, $msgId, (string)$arg);
                return;
            }

            case 'btn': {
                Tg::answerCb($cbId);
                $b = class_exists('Btn') ? Btn::byId((string)$arg) : null;
                if ($b !== null) self::runButton($chatId, $msgId, $b);
                return;
            }

            case 'stk':
                self::stockCb($chatId, $msgId, $cbId, (string)$arg, (string)$arg2);
                return;

            case 'wgp':
                Tg::answerCb($cbId);
                self::cryptoAskAmount($chatId, $msgId, (string)$arg);
                return;

            case 'wgwl':
                Tg::answerCb($cbId);
                self::cryptoChooser($chatId, $msgId, (int)$arg);
                return;

            case 'wgw':
                self::cryptoGateway($chatId, $msgId, $cbId, (string)$arg, (int)$arg2);
                return;

            case 'rsp':
                Tg::answerCb($cbId);
                self::resellerPay($chatId);
                return;

            case 'menu':
                Tg::answerCb($cbId);
                if ($arg === 'main') { self::deleteAndMenu($chatId, $msgId); }
                elseif ($arg === 'products') self::sectionProducts($chatId, $msgId);
                elseif ($arg === 'services') self::sectionServices($chatId, $msgId);
                elseif ($arg === 'wallet') self::sectionWallet($chatId, $msgId);
                elseif ($arg === 'gift') {
                    self::setState('gift_code');
                    Tg::send($chatId, "🎁 <b>کد هدیه</b>\n\nکد هدیه خود را ارسال کنید تا مبلغ آن به کیف پول شما افزوده شود.", Kb::cancel());
                }
                elseif ($arg === 'ref') self::sectionReferral($chatId, $msgId);
                elseif ($arg === 'support') self::sectionSupport($chatId, $msgId);
                return;

            case 'ref':
                Tg::answerCb($cbId);
                if ($arg === 'list') self::referralList($chatId, $msgId);
                else self::sectionReferral($chatId, $msgId);
                return;

            case 'cat':  Tg::answerCb($cbId); self::showCategory($chatId, $msgId, (string)$arg); return;
            case 'pn':   Tg::answerCb($cbId); self::showPanel($chatId, $msgId, (int)$arg); return;
            case 'pc':   Tg::answerCb($cbId); self::showCatIdx($chatId, $msgId, (int)$arg, (int)$arg2); return;
            case 'p':    Tg::answerCb($cbId); self::showProduct($chatId, $msgId, (int)$arg); return;
            case 'tst':  Tg::answerCb($cbId); self::sectionTest($chatId, (int)$arg, $msgId); return;
            case 'rs':
                Tg::answerCb($cbId);
                if ($arg === 'req') self::resellerRequest($chatId);
                elseif ($arg === 'svcs') self::sectionResellerServices($chatId, $msgId);
                elseif ($arg === 'panel') self::sectionReseller($chatId);
                return;

            case 'rssvc':
                Tg::answerCb($cbId);
                self::showResellerService($chatId, $msgId, (int)$arg);
                return;

            case 'buy':  self::startPurchase($chatId, $msgId, $cbId, (int)$arg); return;
            case 'cus':  self::cusAction($chatId, $msgId, $cbId, (string)$arg, (string)$arg2); return;

            case 'fj':
                if ($arg === 'chk') {
                    $miss = self::fjMissing(true);
                    if ($miss) {
                        Tg::answerCb($cbId, '⛔️ هنوز عضو همهٔ کانال‌ها نشده‌اید.', true);
                        self::fjPrompt($chatId, $miss, $msgId);
                        return;
                    }
                    Tg::answerCb($cbId, '✅ عضویت شما تایید شد؛ خوش آمدید!');
                    /* آمار: عضویت‌های تاییدشده با دکمهٔ بررسی */
                    try { DB::setSetting('fj_stat_joined', (string)((int)DB::setting('fj_stat_joined', 0) + 1)); } catch (Throwable $e) { }
                    self::deleteAndMenu($chatId, $msgId);
                }
                return;
            case 'dc':
                Tg::answerCb($cbId);
                self::setState('discount_code', ['pid' => (int)$arg]);
                Tg::send($chatId, '🎟 کد تخفیف را ارسال کنید:', Kb::cancel());
                return;
            case 'dcrm':
                Tg::answerCb($cbId, 'کد تخفیف حذف شد.');
                self::setState(null);
                self::showProduct($chatId, $msgId, (int)$arg);
                return;

            case 'svc':     Tg::answerCb($cbId); self::showService($chatId, $msgId, (int)$arg); return;
            case 'svcsub':  self::sendSub($chatId, $cbId, (int)$arg); return;
            case 'svccfg':  self::sendConfig($chatId, $cbId, (int)$arg); return;
            case 'svcspec': self::sendSpec($chatId, $cbId, (int)$arg); return;
            case 'svcrn':   Tg::answerCb($cbId); self::renewOptions($chatId, $msgId, (int)$arg); return;
            case 'svcrnok': self::doRenew($chatId, $msgId, $cbId, (int)$arg, (int)$arg2); return;
            case 'svcsync': self::syncService($chatId, $msgId, $cbId, (int)$arg); return;
            case 'svcdev':     Tg::answerCb($cbId); self::devicesView($chatId, $msgId, (int)$arg); return;
            case 'svcdevdel':  self::deviceDel($chatId, $msgId, $cbId, (int)$arg, (int)$arg2); return;
            case 'svcdevclr':  self::deviceClear($chatId, $msgId, $cbId, (int)$arg); return;
            case 'svchapp': Tg::answerCb($cbId); self::happView($chatId, $msgId, (int)$arg); return;
            case 'svcdel':     Tg::answerCb($cbId); self::delOptions($chatId, $msgId, (int)$arg); return;
            case 'svcdelok':   self::doDelete($chatId, $msgId, $cbId, (int)$arg); return;
            case 'svcpurge':   Tg::answerCb($cbId); self::purgeDeadView($chatId, $msgId); return;
            case 'svcpurgeok': self::doPurgeDead($chatId, $msgId, $cbId); return;

            case 'test': self::sectionTest($chatId); Tg::answerCb($cbId); return;

            case 'wal':
                Tg::answerCb($cbId);
                /* دکمه‌های پیام‌های قدیم��: ��گر روش پرداخت بعدا خاموش شده باشد، ورود مسدود می‌شود */
                if (($arg === 'card' || $arg === 'cardgo') && !self::payMethodOn('card')) {
                    Tg::send($chatId, '⚠️ پرداخت کارت به کارت در حال حاضر غیرفعال است.');
                    return;
                }
                if (($arg === 'crypto' || $arg === 'hash') && !self::payMethodOn('crypto')) {
                    Tg::send($chatId, '⚠️ پرداخت ارزی در حال حاضر غیرفعال است.');
                    return;
                }
                if ($arg === 'card')   self::walletCardMenu($chatId, $msgId);
                elseif ($arg === 'cardgo') self::walletCardStart($chatId);
                elseif ($arg === 'crypto') self::cryptoAssets($chatId, $msgId);
                elseif ($arg === 'hash') self::askAmount($chatId, 'crypto_hash');
                elseif ($arg === 'np') self::askAmount($chatId, 'nowpay');
                elseif ($arg === 'chk') self::checkNowPay($chatId, (int)$arg2);
                elseif ($arg === 'hp') self::askAmount($chatId, 'hooshpay');
                elseif ($arg === 'hpchk') self::checkHooshPay($chatId, (int)$arg2);
                elseif ($arg === 'hist')  self::walletHistory($chatId, $msgId);
                return;

            /* احراز کارت بانکی */
            case 'crd':
                Tg::answerCb($cbId);
                if ($arg === 'menu')       self::cardMenu($chatId, $msgId);
                elseif ($arg === 'add')    self::cardAskPan($chatId);
                elseif ($arg === 'manual') self::cardAskPanText($chatId);
                elseif ($arg === 'del')   self::cardDelete($chatId, (int)$arg2);
                elseif ($arg === 'pick')  self::askAmount($chatId, 'card', (int)$arg2);
                return;

            /* fixed74: تمدید خودکار از کیف پول — روشن/خاموش توسط کاربر */
            case 'arn': {
                if (!class_exists('AutoRenew') || !AutoRenew::enabled()) { Tg::answerCb($cbId, 'این قابلیت فعلاً غیرفعال است.', true); return; }
                $new = AutoRenew::setUser((int)self::$u['id'], $arg === 'on' ? true : ($arg === 'off' ? false : null));
                Tg::answerCb($cbId, $new ? 'تمدید خودکار روشن شد ✅' : 'تمدید خودکار خاموش شد');
                if ($arg === 'on') {
                    Tg::send($chatId, "✅ <b>تمدید خودکار روشن شد</b>\n\nپیش از انقضای هر سرویس، هزینهٔ تمدید از کیف پول شما کسر و سرویس خودکار تمدید می‌شود. کافیست موجودی کیف پول را کافی نگه دارید.\n\nبرای خاموش کردن به «👤 حساب کاربری» بروید.", Tg::ikb([[Tg::btn('💳 شارژ کیف پول', 'menu:wallet')], Kb::backRow('menu:main')]));
                } else {
                    self::sectionAccount($chatId, $msgId);
                }
                return;
            }

            case 'acc':
                Tg::answerCb($cbId);
                if ($arg === 'phone') { self::setState('set_phone'); Tg::send($chatId, '📱 شماره تماس خود را ارسال کنید:', Kb::cancel()); }
                elseif ($arg === 'email') { self::setState('set_email'); Tg::send($chatId, '✉️ ایمیل خود را ارسال کنید:', Kb::cancel()); }
                elseif ($arg === 'name') { self::setState('set_name'); Tg::send($chatId, '👤 نام و نام خ��نوادگی خود را ارسال کنید:', Kb::cancel()); }
                return;

            case 'ver':
                Tg::answerCb($cbId);
                if ($arg === 'menu') { self::verifyMenu($chatId, $msgId); return; }
                if ($arg === 'email') {
                    self::setState('verify_email');
                    Tg::send($chatId, "\xf0\x9f\x93\xa7 <b>تایید با ایمیل</b>\n\n"
                        . "آدرس ایمیل خود را ارسال کنید تا کد تایید برایتان فرستاده شود.", Kb::cancel());
                    return;
                }
                if ($arg === 'phone') {
                    self::setState('verify_phone');
                    Tg::send($chatId, "\xf0\x9f\x93\xb1 <b>تایید با شماره موبایل</b>\n\n"
                        . "شماره موبایل خود را ارسال کنید تا کد تایید پیامک شود.", Kb::cancel());
                    return;
                }
                if ($arg === 'link')   { self::verifyLink($chatId); return; }
                if ($arg === 'resend') { self::verifyResend($chatId); return; }
                return;

            case 'tut':  Tg::answerCb($cbId); self::showTutorials($chatId, $msgId, (string)$arg); return;
            case 'tutx': Tg::answerCb($cbId); self::showTutorial($chatId, $msgId, (int)$arg); return;

            case 'tk':
                Tg::answerCb($cbId);
                if ($arg === 'new') {
                    self::setState('ticket_new');
                    Tg::send($chatId, "🆘 <b>پیام جدید به پشتیبانی</b>\n\n"
                        . "مشکل یا سوال خود را بنویسید.\n"
                        . "🖼 می‌توانید عکس یا فایل هم ارسال کنید (با یا بدون کپشن).", Kb::cancel());
                } else {
                    self::showTicket($chatId, $msgId, (int)$arg);
                }
                return;
            case 'tkr':
                Tg::answerCb($cbId);
                self::setState('ticket_reply', ['tid' => (int)$arg]);
                Tg::send($chatId, "✍️ پاسخ خود را بنویسید:\n🖼 امکان ارسال عکس و فایل هم وجود دارد.", Kb::cancel());
                return;

            case 'noop': Tg::answerCb($cbId); return;
        }
        Tg::answerCb($cbId);
    }

    private static function deleteAndMenu($chatId, $msgId): void
    {
        if ($msgId) Tg::deleteMsg($chatId, $msgId);
        self::mainMenu($chatId);
    }

    /* ================= حالت‌ها ================= */

    private static function handleState($chatId, string $state, string $text, array $msg): bool
    {
        $d = self::stateData();
        switch ($state) {
            case 'set_phone':
                DB::update('users', ['phone' => mb_substr(en_num($text), 0, 30)], 'id = :id', [':id' => (int)self::$u['id']]);
                self::setState(null);
                Tg::send($chatId, '✅ شماره تماس ذخیره شد.', self::kbMain());
                self::sectionAccount($chatId);
                return true;

            case 'verify_email':
            case 'verify_phone':
                if (!class_exists('Security')) { self::setState(null); return true; }
                $vk = $state === 'verify_email' ? 'email' : 'phone';
                $vu = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)self::$u['id']]) ?: [];
                $vr = Security::start($vu, $vk, $text);
                if (empty($vr['ok'])) { Tg::send($chatId, '❌ ' . (string)$vr['message']); return true; }
                self::setState('verify_code');
                Tg::send($chatId, '✅ ' . (string)$vr['message'] . "\n\n"
                    . '🔢 کد ' . fa_num((string)Security::codeLen()) . " رقمی را همینجا ارسال کنید.\n"
                    . '⏱ اعتبار کد: ' . fa_num((string)Security::ttlMin()) . ' دقیقه',
                    Tg::ikb([[Tg::btn('🔄 ارسال مجدد کد', 'ver:resend')], Kb::backRow('ver:menu')]));
                return true;

            case 'verify_code':
                if (!class_exists('Security')) { self::setState(null); return true; }
                $vu = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)self::$u['id']]) ?: [];
                $vr = Security::checkCode($vu, $text);
                if (empty($vr['ok'])) {
                    Tg::send($chatId, '❌ ' . (string)$vr['message'],
                        Tg::ikb([[Tg::btn('🔄 ارسال مجدد کد', 'ver:resend')], Kb::backRow('ver:menu')]));
                    return true;
                }
                self::setState(null);
                Tg::send($chatId, (string)$vr['message'], self::kbMain());
                self::sectionAccount($chatId);
                return true;

            case 'set_email':
                if (!filter_var($text, FILTER_VALIDATE_EMAIL)) { Tg::send($chatId, '❌ ایمیل معتبر نیست. دوباره تلاش کنید.'); return true; }
                DB::update('users', ['email' => mb_substr($text, 0, 120)], 'id = :id', [':id' => (int)self::$u['id']]);
                self::setState(null);
                Tg::send($chatId, '✅ ایمیل ذخیره شد.', self::kbMain());
                self::sectionAccount($chatId);
                return true;

            case 'set_name':
                $parts = preg_split('/\\s+/', trim($text), 2) ?: [];
                DB::update('users', [
                    'first_name' => mb_substr((string)($parts[0] ?? ''), 0, 120),
                    'last_name'  => mb_substr((string)($parts[1] ?? ''), 0, 120),
                ], 'id = :id', [':id' => (int)self::$u['id']]);
                self::setState(null);
                Tg::send($chatId, '✅ اطلاعات ذخیره شد.', self::kbMain());
                self::sectionAccount($chatId);
                return true;

            case 'gift_code':
                $r = Codes::redeemGift($text, self::$u);
                self::setState(null);
                Tg::send($chatId, ($r['ok'] ? '' : '❌ ') . $r['message'], self::kbMain());
                return true;

            case 'discount_code':
                $pid = (int)($d['pid'] ?? 0);
                $product = DB::one('SELECT * FROM {p}products WHERE id = :id AND active = 1', [':id' => $pid]);
                if (!$product) { self::setState(null); Tg::send($chatId, '❌ محصول یافت نشد.', self::kbMain()); return true; }
                $r = Codes::checkDiscount($text, self::$u, (int)$product['price'], $pid);
                if (!$r['ok']) { Tg::send($chatId, '❌ ' . $r['message']); return true; }
                self::setState(null, []);
                DB::update('users', ['state_data' => jenc(['dc' => strtoupper(trim(en_num($text))), 'pid' => $pid])], 'id = :id', [':id' => (int)self::$u['id']]);
                self::$u['state_data'] = jenc(['dc' => strtoupper(trim(en_num($text))), 'pid' => $pid]);
                Tg::send($chatId, '✅ ' . $r['message'] . "\nمبلغ تخفیف: " . money((int)$r['discount']) . ' ' . currency(), self::kbMain());
                self::showProduct($chatId, null, $pid);
                return true;

            case 'cus_gb': {
                $c = self::cusCfg();
                $o = $c ? self::cusOpt($c, (int)($d['pn'] ?? 0)) : null;
                if ($c && $o) { $L = self::cusLim($c, $o); $c['min_gb'] = $L[0]; $c['max_gb'] = $L[1]; $c['min_days'] = $L[2]; $c['max_days'] = $L[3]; }
                if (!$c || !$o) { self::setState(null); Tg::send($chatId, '⚠️ این بخش در حال حاضر غیرفعال است.', self::kbMain()); return true; }
                $gb = (int)preg_replace('/\D/', '', en_num($text));
                if ($gb < (int)$c['min_gb'] || $gb > (int)$c['max_gb']) {
                    Tg::send($chatId, '❌ حجم باید بین ' . fa_num($c['min_gb']) . ' تا ' . fa_num($c['max_gb']) . ' گیگابایت باشد. دوباره بفرستید:');
                    return true;
                }
                self::setState('cus_days', ['gb' => $gb, 'pn' => (int)$o['key']]);
                Tg::send($chatId, '⏳ حالا <b>مدت</b> دلخواه را به «روز» بفرستید (بین ' . fa_num($c['min_days']) . ' تا ' . fa_num($c['max_days']) . '):', Kb::cancel());
                return true;
            }

            case 'cus_days': {
                $c = self::cusCfg();
                $o = $c ? self::cusOpt($c, (int)($d['pn'] ?? 0)) : null;
                if ($c && $o) { $L = self::cusLim($c, $o); $c['min_gb'] = $L[0]; $c['max_gb'] = $L[1]; $c['min_days'] = $L[2]; $c['max_days'] = $L[3]; }
                if (!$c || !$o) { self::setState(null); Tg::send($chatId, '⚠️ این بخش در حال حاضر غیرفعال است.', self::kbMain()); return true; }
                $gb   = (int)($d['gb'] ?? 0);
                $days = (int)preg_replace('/\D/', '', en_num($text));
                if ($gb <= 0) { self::setState(null); Tg::send($chatId, '⚠️ لطفاً از ابتدا شروع کنید.', self::kbMain()); return true; }
                if ($days < (int)$c['min_days'] || $days > (int)$c['max_days']) {
                    Tg::send($chatId, '❌ مدت باید بین ' . fa_num($c['min_days']) . ' تا ' . fa_num($c['max_days']) . ' روز باشد. دوباره بفرستید:');
                    return true;
                }
                self::setState(null);
                $price = self::cusPrice($o, $gb, $days);
                $cc    = self::cusCamp($o, $price); /* fixed79: کمپین روی پلن دلخواه */
                $txt = "📐 <b>پیش‌فاکتور پلن دلخواه</b>\n<code>───────────────</code>\n"
                     . '📊 حجم: <b>' . fa_num($gb) . ' گیگابایت</b>' . "\n"
                     . '⏳ مدت: <b>' . fa_num($days) . ' روز</b>' . "\n"
                     . '🌍 سرور: <b>' . h((string)($o['panel']['name'] ?? '-')) . '</b>' . "\n"
                     . '💰 مبلغ: <b>' . money($cc['final']) . ' ' . currency() . '</b>' . $cc['line'] . "\n"
                     . '👛 موجودی شما: <b>' . money((int)self::$u['balance']) . ' ' . currency() . '</b>';
                $kb = [];
                if ((int)self::$u['balance'] >= $cc['final']) {
                    $kb[] = [Tg::btn('✅ تایید و ساخت سرویس', 'cus:ok:' . $gb . '-' . $days . '-' . (int)$o['key'])];
                } else {
                    $txt .= "\n\n⚠️ موجودی کیف پول کافی نیست؛ ابتدا کیف پول را شارژ کنید.";
                    $kb[] = [Tg::btn('💳 شارژ کیف پول', 'menu:wallet')];
                }
                $kb[] = [Tg::btn('❌ انصراف', 'menu:products')];
                Tg::send($chatId, $txt, Tg::ikb($kb));
                return true;
            }

            case 'cus_username': {
                $gb   = (int)($d['gb'] ?? 0);
                $days = (int)($d['days'] ?? 0);
                $pn   = (int)($d['pn'] ?? 0);
                $name = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', en_num($text)) ?? '');
                if (strlen($name) < 3 || strlen($name) > 20) {
                    Tg::send($chatId, '❌ نام کاربری باید ۳ تا ۲۰ کاراکتر و فقط حروف لاتین، عدد، خط تیره یا اندرلاین باشد.');
                    return true;
                }
                if (!Svc::usernameIsFree($name)) { Tg::send($chatId, '❌ این نام کاربری قبلاً استفاده شده. نام دیگری بفرستید.'); return true; }
                self::setState(null);
                self::cusFinalize($chatId, $gb, $days, $pn, $name);
                return true;
            }

            case 'buy_username':
                $pid  = (int)($d['pid'] ?? 0);
                $name = strtolower(preg_replace('/[^a-zA-Z0-9_\\-]/', '', en_num($text)) ?? '');
                if (strlen($name) < 3 || strlen($name) > 20) {
                    Tg::send($chatId, '❌ نام کاربری باید ۳ تا ۲۰ کاراکتر و فقط حروف لاتین، عدد، خط تیره یا اندرلاین باشد.');
                    return true;
                }
                if (!Svc::usernameIsFree($name)) { Tg::send($chatId, '❌ این نام کاربری قبلاً استفاده شده. نام دیگری بفرستید.'); return true; }
                self::setState(null);
                self::finalizePurchase($chatId, $pid, (string)($d['dc'] ?? ''), $name);
                return true;

            case 'wallet_amount':
                $method = (string)($d['method'] ?? 'card');
                $amount = (int)preg_replace('/\\D/', '', en_num($text));
                $min = (int)DB::setting('min_deposit', 0);
                $max = (int)DB::setting('max_deposit', 0);

                /* محدودیت اختصاصی درگاه انتخاب‌شده */
                if (class_exists('Gateway') && (string)($d['gw'] ?? '') !== '') {
                    try {
                        $gSel = Gateway::byId((string)$d['gw']);
                        if ($gSel !== null) {
                            $gLim = Gateway::limits($gSel);
                            $min  = (int)$gLim['min'];
                            $max  = (int)$gLim['max'];
                        }
                    } catch (Throwable $e) {
                    }
                }

                if ($amount <= 0) { Tg::send($chatId, '❌ مبلغ را فقط به عدد و به ' . currency() . ' ارسال کنید.'); return true; }
                if ($min > 0 && $amount < $min) { Tg::send($chatId, '❌ حداقل مبلغ شارژ ' . money($min) . ' ' . currency() . ' است.'); return true; }
                if ($max > 0 && $amount > $max) { Tg::send($chatId, '❌ حداکثر مبلغ شارژ ' . money($max) . ' ' . currency() . ' است.'); return true; }

                if ($method === 'card') {
                    self::setState('wallet_receipt', [
                        'amount'  => $amount,
                        'method'  => 'card',
                        'card_id' => (int)($d['card_id'] ?? 0),
                    ]);

                    /* کارت مخصوص نمایندگان در صورت تعریف شدن */
                    $cardNo = (string)DB::setting('card_number', '-');
                    $holder = (string)DB::setting('card_holder', '-');
                    $bank   = (string)DB::setting('card_bank', '-');
                    $cnote  = '';
                    $csheba = '';
                    if (class_exists('Gateway')) {
                        try {
                            $cg = Gateway::card(self::isRs());
                            if ($cg !== null) {
                                $cardNo = (string)$cg['number'];
                                if ((string)$cg['holder'] !== '') $holder = (string)$cg['holder'];
                                if ((string)$cg['bank'] !== '')   $bank   = (string)$cg['bank'];
                                if ((string)($cg['sheba'] ?? '') !== '') $csheba = "\n🏦 شبا: <code>" . h((string)$cg['sheba']) . '</code>';
                                if ((string)$cg['note'] !== '')   $cnote  = "\nℹ️ " . h((string)$cg['note']);
                            }
                        } catch (Throwable $e) {
                        }
                    }

                    $txt = "💳 <b>پرداخت کارت به کارت</b>\n\n"
                        . 'مبلغ: <b>' . money($amount) . ' ' . currency() . "</b>\n"
                        . '💳 شماره کارت: <code>' . h($cardNo) . "</code>\n"
                        . '👤 به نام: ' . h($holder) . "\n"
                        . '🏦 بانک: ' . h($bank) . $csheba . $cnote . "\n\n"
                        . self::tx('wallet_card_note', 'پس از واریز، <b>عکس رسید</b> را همین��ا ارسال کنید.');
                    Tg::send($chatId, $txt, Kb::cancel());
                } elseif ($method === 'nowpay') {
                    self::setState(null);
                    $waitId = self::waitMsg(
                        $chatId,
                        "⚡️ <b>در حال ساخت درگاه پرداخت خودکار…</b>\n\n⏳ اتصال به سرویس پرداخت و محاسبهٔ نرخ، چند لحظه صبر کنید.",
                        self::kbMain()
                    );
                    $txN = Wallet::createDeposit(self::$u, $amount, 'nowpay');
                    $inv = NowPay::createInvoice(self::$u, $amount, $txN);
                    if (empty($inv['ok'])) {
                        DB::update('transactions', [
                            'status' => 'rejected', 'decided_at' => now(),
                            'note' => mb_substr((string)($inv['message'] ?? 'خطا'), 0, 250),
                        ], 'id = :id', [':id' => $txN]);
                        self::waitEdit($chatId, $waitId, '❌ ' . (string)($inv['message'] ?? 'ساخت لینک پرداخت ناموفق بود.'));
                        Logs::send('errors', Logs::fmt('⚠ خطای ساخت فاکتور ارزی', [
                            'کاربر' => (int)self::$u['tg_id'],
                            'مبلغ'  => money($amount) . ' ' . currency(),
                            'خطا'   => (string)($inv['message'] ?? '-'),
                        ]));
                        return true;
                    }
                    $txt = "⚡️ <b>پرداخت ارزی خودکار</b>\n\n"
                        . 'مبلغ: <b>' . money($amount) . ' ' . currency() . "</b>\n"
                        . 'معادل: <b>' . fa_num((string)$inv['usd']) . " $</b>\n"
                        . 'نرخ محاسبه: ' . money(Wallet::rate()) . ' ' . currency() . "\n"
                        . 'شماره پیگیری: <code>#' . $txN . "</code>\n\n"
                        . "روی دکمهٔ زیر بزنید، ارز دلخواه را انتخاب و مبلغ را واریز کنید.\n"
                        . "پس از تایید شبکه، کیف پول شما <b>خودکار</b> شارژ می‌شود.";
                    self::waitEdit($chatId, $waitId, $txt, Tg::ikb([
                        [Tg::url('💠 صفحه پرداخت', (string)$inv['url'])],
                        [Tg::btn('🔄 بررسی وضعیت پرداخت', 'wal:chk:' . $txN)],
                    ]));
                    Logs::send('financial', Logs::fmt('⚡️ فاکتور ارزی جدید', [
                        'کاربر'  => (($u2 = self::$u)['first_name'] ?? '-') . ' (' . (int)$u2['tg_id'] . ')',
                        'مبلغ'   => money($amount) . ' ' . currency(),
                        'معادل'  => $inv['usd'] . ' $',
                        'فاکتور' => (string)$inv['order'],
                    ]));
                    return true;
                } elseif ($method === 'hooshpay') {
                    self::setState(null);
                    $waitId = self::waitMsg(
                        $chatId,
                        "🪙 <b>در حال ساخت لینک پرداخت…</b>\n\n⏳ اتصال به هوش‌پی و رزرو کارت دریافت، چند لحطه صبر کنید.",
                        self::kbMain()
                    );

                    if (!class_exists('HooshPay')) {
                        self::waitEdit($chatId, $waitId, '⚠️ درگاه هوش‌پی در دسترس نیست.');
                        return true;
                    }

                    $txH = Wallet::createDeposit(self::$u, $amount, 'hooshpay');
                    $inv = HooshPay::createInvoice(self::$u, $amount, $txH);

                    if (empty($inv['ok'])) {
                        DB::update('transactions', [
                            'status' => 'rejected', 'decided_at' => now(),
                            'note' => mb_substr((string)($inv['message'] ?? 'خطا'), 0, 250),
                        ], 'id = :id', [':id' => $txH]);
                        self::waitEdit($chatId, $waitId, '❌ ' . (string)($inv['message'] ?? 'ساخت لینک پرداخت ناموفق بود.'));
                        Logs::send('errors', Logs::fmt('⚠ خطای ساخت فاکتور هوش‌پی', [
                            'کاربر' => (int)self::$u['tg_id'],
                            'مبلغ'  => money($amount) . ' ' . currency(),
                            'خطا'   => (string)($inv['message'] ?? '-'),
                        ]));
                        return true;
                    }

                    $hpPay = (int)($inv['payable'] ?? 0);
                    $hpFee = HooshPay::FEE_SHORT[HooshPay::feeMode()] ?? '';
                    $hpCrd = is_array($inv['card'] ?? null) ? $inv['card'] : [];

                    $txt = "🪙 <b>پرداخت آنی کارت به کارت</b>\n\n"
                        . 'مبلغ فاکتور: <b>' . money($amount) . ' ' . currency() . "</b>\n";
                    if ($hpPay > 0) {
                        $txt .= 'مبلغ قابل پرداخت: <b>' . fa_num((string)$hpPay) . " تومان</b>\n";
                    }
                    if ($hpFee !== '') $txt .= 'کارم��د درگاه: ' . $hpFee . "\n";
                    if (trim((string)($hpCrd['bank_name'] ?? '')) !== '') {
                        $txt .= 'بانک مقصد: ' . h((string)$hpCrd['bank_name']) . "\n";
                    }
                    $txt .= 'شماره پیگیری: <code>#' . $txH . "</code>\n\n"
                        . "⚠️ <b>مهم:</b> دقیقاً همان مبلغی را واریز کنید که در صفحهٔ پرداخت نشان داده می‌شود؛ "
                        . "چند تومان اختلاف عمدی است و برای تشخیص خودکار واریز شما لازم است.\n\n"
                        . "پس از واریز، تایید <b>آنی</b> انجام می‌شود و کیف پول شما خودکار شارژ می‌گردد.";

                    self::waitEdit($chatId, $waitId, $txt, Tg::ikb([
                        [Tg::url('🪙 رفتن به صفحهٔ پرداخت', (string)$inv['url'])],
                        [Tg::btn('🔄 بررسی وضعیت پرداخت', 'wal:hpchk:' . $txH)],
                    ]));

                    Logs::send('financial', Logs::fmt('🪙 فاکتور هوش‌پی جدید', [
                        'کاربر'   => (($u3 = self::$u)['first_name'] ?? '-') . ' (' . (int)$u3['tg_id'] . ')',
                        'مبلغ'    => money($amount) . ' ' . currency(),
                        'پرداختی' => ($hpPay > 0 ? fa_num((string)$hpPay) . ' تومان' : '-'),
                        'فاکتور'  => (string)$inv['order'],
                    ]));
                    return true;
                } elseif ($method === 'crypto_hash') {
                    /* واریزی که قبلاً انجام شده (مثلاً از مینی‌اپ) */
                    self::setState('wallet_txid', ['amount' => $amount, 'method' => 'crypto']);
                    Tg::send(
                        $chatId,
                        "🔗 <b>ارسال هش تراکنش</b>\n"
                        . "<code>─────────────────</code>\n"
                        . '🧾 مبلغ واریزی: <b>' . money($amount) . ' ' . currency() . "</b>\n\n"
                        . self::tx('wallet_txid_ask', 'هش تراکنش (TXID) را ارسال کنید تا بررسی شود.'),
                        Kb::cancel()
                    );
                } else {
                    /* اگر کاربر پیش‌از این ارز را انتخاب کرده، نرخ و آدرس همان ارز */
                    $gwSel = trim((string)($d['gw'] ?? ''));
                    $gwOk  = false;
                    if ($gwSel !== '' && class_exists('Gateway')) {
                        try { $gwOk = Gateway::byId($gwSel) !== null; } catch (Throwable $e) { $gwOk = false; }
                    }
                    if ($gwOk) {
                        self::cryptoGateway($chatId, null, null, $gwSel, $amount);
                    } else {
                        /* انتخاب ارز و شبکه – نرخ دقیق در لحظهٔ انتخاب گرفته می‌شود */
                        self::cryptoChooser($chatId, null, $amount);
                    }
                }
                return true;

            case 'reseller_req': {
                $note = trim(clean_text($text));
                if ($note === '0' || $note === '۰') $note = '';
                self::setState(null);

                if (!class_exists('Reseller')) {
                    Tg::send($chatId, '⚠️ بخش نمایندگی در دسترس نیست.', self::kbMain());
                    return true;
                }

                $rr = Reseller::request((array)self::$u, $note);
                if (empty($rr['ok'])) {
                    Tg::send($chatId, '❌ ' . (string)$rr['message'], self::kbMain());
                    return true;
                }

                Tg::send(
                    $chatId,
                    "✅ <b>درخواست نمایندگی ش��ا ثبت شد</b>\n\n"
                    . "🕓 نتیجهٔ بررسی همینجا به شما اطلاع داده می‌شود.",
                    self::kbMain()
                );

                AdminBot::notifyResellerRequest((int)self::$u['id'], $note);
                return true;
            }

            /* ---------- ثبت کارت بانکی (احراز کارت) ---------- */
            case 'card_pan': {
                if (!class_exists('CardAuth')) { self::setState(null); return true; }
                $pan = CardAuth::normalizePan($text);
                if (strlen($pan) !== 16) {
                    Tg::send($chatId, '❌ شماره‌ی کارت باید دقیقاً <b>۱۶ رقم</b> باشد. دوباره ارسال کنید.', Kb::cancel());
                    return true;
                }
                if (CardAuth::checkLuhn() && !CardAuth::luhn($pan)) {
                    Tg::send($chatId, '❌ شماره‌ی کارت معتبر نیست؛ ارقام را دوباره بررسی کنید.', Kb::cancel());
                    return true;
                }

                $bank = CardAuth::bankOf($pan);
                $head = '💳 <code>' . CardAuth::pretty($pan) . '</code>' . ($bank !== '' ? "\n🏦 " . $bank : '');

                if (CardAuth::needHolder()) {
                    self::setState('card_holder', ['pan' => $pan]);
                    Tg::send($chatId, $head . "\n\n" . '👤 اکنون <b>نام و نام خانوادگی صاحب کارت</b> ر�� ارسال کنید:', Kb::cancel());
                    return true;
                }
                if (CardAuth::needSheba()) {
                    self::setState('card_sheba', ['pan' => $pan, 'holder' => '']);
                    Tg::send($chatId, $head . "\n\n" . '🏦 شماره‌ی <b>شبا</b> همین حساب را ارسال کنید (با یا بدون IR):', Kb::cancel());
                    return true;
                }
                self::cardSave($chatId, $pan);
                return true;
            }

            case 'card_holder': {
                if (!class_exists('CardAuth')) { self::setState(null); return true; }
                $holder = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
                if (mb_strlen($holder) < 5) {
                    Tg::send($chatId, '❌ نام و نام خانوادگی کامل صاحب کارت را ارسال کنید.', Kb::cancel());
                    return true;
                }
                $pan = (string)($d['pan'] ?? '');
                if (CardAuth::needSheba()) {
                    self::setState('card_sheba', ['pan' => $pan, 'holder' => $holder]);
                    Tg::send($chatId, '🏦 شماره‌ی <b>شبا</b> همین حساب را ارسال کنید (با یا بدون IR):', Kb::cancel());
                    return true;
                }
                self::cardSave($chatId, $pan, $holder);
                return true;
            }

            case 'card_sheba': {
                if (!class_exists('CardAuth')) { self::setState(null); return true; }
                $sheba = CardAuth::normalizeSheba($text);
                if (!CardAuth::shebaValid($sheba)) {
                    Tg::send($chatId, '❌ شبا معتبر نیست. ۲۴ رقم پس از IR را ارسال کنید.', Kb::cancel());
                    return true;
                }
                self::cardSave($chatId, (string)($d['pan'] ?? ''), (string)($d['holder'] ?? ''), $sheba);
                return true;
            }

            case 'wallet_receipt':
                $fileId = self::fileId($msg);
                if (!$fileId) { Tg::send($chatId, '❌ لطفاً <b>عکس رسید</b> را ارسال کنید.'); return true; }
                $cardId = (int)($d['card_id'] ?? 0);
                $txId = Wallet::createDeposit(self::$u, (int)($d['amount'] ?? 0), 'card', [
                    'receipt_file' => $fileId,
                    'card_id'      => $cardId,
                ]);
                if ($cardId > 0 && class_exists('CardAuth')) CardAuth::touch($cardId);
                self::setState(null);
                Tg::send($chatId, "✅ رسید شما ثبت شد و در انتظار تایید است.\nشماره پیگیری: <code>#" . $txId . '</code>', self::kbMain());
                AdminBot::notifyPayment($txId, $fileId);
                return true;

            case 'wallet_txid': {
                $txid = class_exists('TxCheck') ? TxCheck::normalize($text) : trim(en_num($text));
                if (strlen($txid) < 8) { Tg::send($chatId, '❌ هش تراکنش معتبر نیست. دوباره ارسال کنید.'); return true; }

                $amount = (int)($d['amount'] ?? 0);

                /* بررسی خودکار هش روی بلاک‌چین */
                $chk = ['ok' => false, 'manual' => true, 'message' => ''];
                if (class_exists('TxCheck')) $chk = TxCheck::verify($txid, $amount);

                $note = class_exists('TxCheck') ? TxCheck::summary($chk) : '';

                /* درگاهی که کاربر انتخاب کرده بود */
                $gwSel = (string)($d['gw'] ?? '');
                if ($gwSel !== '' && class_exists('Gateway')) {
                    try {
                        $gwRow = Gateway::byId($gwSel);
                        if ($gwRow !== null) $note = trim('درگاه: ' . Gateway::title($gwRow) . "\n" . $note);
                    } catch (Throwable $e) {
                    }
                }

                /* داوری خودکار: تایید، رد یا بررسی دستی */
                $dec = (class_exists('TxCheck') && method_exists('TxCheck', 'decide'))
                    ? TxCheck::decide($chk)
                    : (!empty($chk['ok']) ? 'approve' : 'manual');

                $txId2 = (int)Wallet::createDeposit(self::$u, $amount, 'crypto', [
                    'txid' => mb_substr($txid, 0, 180),
                    'note' => mb_substr($note, 0, 240),
                ]);
                self::setState(null);

                /* هش درست بود: تایید و شارژ */
                if ($dec === 'approve') {
                    $ap = Wallet::approve($txId2, null, false);
                    if (!empty($ap['ok'])) {
                        Tg::send($chatId, "\xe2\x9c\x85 <b>تراکنش شما به‌صورت خودکار تایید شد</b>\n"
                            . "\xf0\x9f\x94\x97 هش روی شبکه بررسی و تایید شد.\n"
                            . "\xf0\x9f\x92\xb0 مبلغ " . money($amount) . ' ' . currency() . " به کیف پول شما افزوده شد.\n"
                            . "\xf0\x9f\xa7\xbe شماره پیگیری: <code>#" . $txId2 . '</code>', self::kbMain());
                        /* تاییدهای خودکار فقط در کانال لاگ ثبت می‌شوند و به ربات مدیر نمی‌روند */
                        if (class_exists('Logs')) {
                            Logs::send('financial', Logs::fmt("\xe2\x9c\x85 تایید خودکار واریز ارزی", [
                                'کاربر'  => '<code>' . (int)self::$u['tg_id'] . '</code>',
                                'مبلغ'   => money($amount) . ' ' . currency(),
                                'هش'     => '<code>' . h(mb_substr($txid, 0, 64)) . '</code>',
                                'پیگیری' => '#' . $txId2,
                            ]));
                        }
                        return true;
                    }
                    $dec = 'manual';
                }

                /* هش اشتباه بود: رد خودکار */
                if ($dec === 'reject') {
                    $reason = trim(str_replace("\n", ' ', (string)($chk['message'] ?? '')));
                    $rj = Wallet::reject($txId2, null, "هش‌چکر: " . $reason, false);
                    if (!empty($rj['ok'])) {
                        Tg::send($chatId, "\xe2\x9d\x8c <b>تراکنش شما رد شد</b>\n"
                            . "\xf0\x9f\x94\x8e نتیجهٔ بررسی هش: " . $reason . "\n"
                            . "\xf0\x9f\xa7\xbe شماره پیگیری: <code>#" . $txId2 . "</code>\n\n"
                            . "اگر فکر می‌کنید اشتباهی رخ داده، با پشتیبانی تماس بگیرید.", self::kbMain());
                        AdminBot::notifyPayment($txId2, null, "\xe2\x9d\x8c رد خودکار توسط هش‌چکر: " . $reason);
                        return true;
                    }
                }

                /* فاز تایید دستی */
                Tg::send($chatId, "\xf0\x9f\x95\x93 <b>تراکنش شما ثبت شد و در انتظار تایید دستی است</b>\n"
                    . ((string)($chk['message'] ?? '') !== ''
                        ? "\xf0\x9f\x94\x8e نتیجهٔ بررسی خودکار: " . (string)$chk['message'] . "\n" : '')
                    . "\xf0\x9f\xa7\xbe شماره پیگیری: <code>#" . $txId2 . "</code>\n"
                    . "پشتیبانی در ا��لین فرصت بررسی می‌کند.", self::kbMain());
                AdminBot::notifyPayment($txId2, null, $note);
                return true;
            }

            case 'ticket_new': {
                $att = self::fileMeta($msg);
                if ($text === '' && !$att['id']) { Tg::send($chatId, '❌ لطف��ً متن پیام یا یک عکس ارسال کنید.'); return true; }
                $subject = $text !== '' ? $text : ($att['type'] === 'photo' ? '🖼 عکس جد��د' : '📎 فایل جدید');
                $tid = DB::insert('tickets', [
                    'user_id' => (int)self::$u['id'], 'tg_id' => (int)self::$u['tg_id'],
                    'subject' => mb_substr($subject, 0, 80),
                    'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::insert('ticket_messages', [
                    'ticket_id' => $tid, 'sender' => 'user', 'text' => $text,
                    'file_id' => $att['id'], 'file_type' => $att['type'], 'created_at' => now(),
                ]);
                self::setState(null);
                Tg::send($chatId, "✅ پیام شما با شماره <code>#$tid</code> ثبت شد.\n"
                    . ($att['id'] ? "📎 فایل پیوست شما هم برای پشتیبانی ارسال شد.\n" : '')
                    . 'پاسخ در همین ربات ارسال می‌شود.', self::kbMain());
                AdminBot::notifyTicket($tid, $text, $att['id'], $att['type']);
                Logs::send('tickets', Logs::fmt('🆕 تیکت جدید', [
                    'شماره' => '<code>#' . $tid . '</code>',
                    'کاربر'  => '<code>' . (int)self::$u['tg_id'] . '</code>',
                    'پیوست'  => $att['id'] ? 'دارد' : 'ندارد',
                ], h(mb_substr($text, 0, 400))));
                return true;
            }

            case 'ticket_reply': {
                $tid2 = (int)($d['tid'] ?? 0);
                $t = DB::one('SELECT * FROM {p}tickets WHERE id = :id AND user_id = :u', [':id' => $tid2, ':u' => (int)self::$u['id']]);
                if (!$t) { self::setState(null); Tg::send($chatId, '❌ تیکت یافت نشد.', self::kbMain()); return true; }
                $att2 = self::fileMeta($msg);
                if ($text === '' && !$att2['id']) { Tg::send($chatId, '❌ لطفاً متن پیام یا یک عکس ارسال کنید.'); return true; }
                DB::insert('ticket_messages', ['ticket_id' => $tid2, 'sender' => 'user', 'text' => $text,
                    'file_id' => $att2['id'], 'file_type' => $att2['type'], 'created_at' => now()]);
                DB::update('tickets', ['status' => 'open', 'updated_at' => now()], 'id = :id', [':id' => $tid2]);
                self::setState(null);
                Tg::send($chatId, '✅ پاسخ شما ارسال شد.' . ($att2['id'] ? "\n📎 فایل پیوست هم ارسال شد." : ''), self::kbMain());
                AdminBot::notifyTicket($tid2, $text, $att2['id'], $att2['type']);
                Logs::send('tickets', Logs::fmt('💬 پاسخ کاربر در تیکت', [
                    'شماره' => '<code>#' . $tid2 . '</code>',
                    'کاربر'  => '<code>' . (int)self::$u['tg_id'] . '</code>',
                    'پیوست'  => $att2['id'] ? 'دارد' : 'ندارد',
                ], h(mb_substr($text, 0, 400))));
                return true;
            }
        }

        if (str_starts_with($state, 'admin_')) {
            return AdminBot::handleState($chatId, $state, $text, $msg, self::$u, $d);
        }
        return false;
    }

    public static function fileId(array $msg): ?string
    {
        $m = self::fileMeta($msg);
        return $m['id'];
    }

    /**
     * پیوست رسید به آخرین درخواست شارژ در انتظار
     * درخواست‌هایی ک�� از مینی‌اپ ثبت می‌شوند حالت گفتگویی نمی‌سازند؛ بنابراین
     * اگر کاربر عکس یا فایل رسید بفرستد، همین‌جا به آخرین تراکنش در انتظار او
     * وصل می‌شود و برای مدیر ارسال می‌گردد.
     */
    private static function attachLooseReceipt($chatId, array $msg): bool
    {
        $fileId = self::fileId($msg);
        if ($fileId === null || $fileId === '') return false;

        try {
            $tx = DB::one("SELECT * FROM {p}transactions
                           WHERE user_id = :u AND status = 'pending' AND amount > 0
                             AND (receipt_file IS NULL OR receipt_file = '')
                           ORDER BY id DESC LIMIT 1", [':u' => (int)self::$u['id']]);
        } catch (Throwable $e) {
            return false;
        }
        if (!$tx) return false;

        DB::update('transactions', ['receipt_file' => $fileId], 'id = :id', [':id' => (int)$tx['id']]);

        Tg::send($chatId,
            "\xe2\x9c\x85 <b>رسید شما دریافت شد</b>\n\n"
            . "\xf0\x9f\xa7\xbe شماره پیگیری: <code>#" . (int)$tx['id'] . "</code>\n"
            . "\xf0\x9f\x92\xb0 مبلغ: " . money((int)$tx['amount']) . ' ' . currency() . "\n\n"
            . "\xe2\x8f\xb3 پس از بررسی توسط پشتیبانی، کیف پول شما شارژ می‌شود.",
            self::kbMain());

        try {
            AdminBot::notifyPayment((int)$tx['id']);
        } catch (Throwable $e) {
            app_log('bot', 'notifyPayment error: ' . $e->getMessage());
        }
        return true;
    }

    /**
     * استخراج شناسه و نوع فایل پیوست از پیام تلگرام
     * @return array{id: ?string, type: ?string}
     */
    public static function fileMeta(array $msg): array
    {
        if (!empty($msg['photo']) && is_array($msg['photo'])) {
            $p = end($msg['photo']);
            $id = (string)($p['file_id'] ?? '');
            if ($id !== '') return ['id' => $id, 'type' => 'photo'];
        }
        foreach (['document', 'video', 'voice', 'audio', 'animation', 'video_note'] as $t) {
            if (!empty($msg[$t]['file_id'])) return ['id' => (string)$msg[$t]['file_id'], 'type' => $t];
        }
        return ['id' => null, 'type' => null];
    }

    /* ================= محصولات ================= */

    private static function shopPanels(): array
    {
        return DB::all("SELECT pr.panel_id AS id, MAX(pa.name) AS name,
                COUNT(*) AS n, MIN(pr.price) AS minp
            FROM {p}products pr
            LEFT JOIN {p}panels pa ON pa.id = pr.panel_id
            WHERE pr.active = 1 AND pr.stock <> 0
            GROUP BY pr.panel_id
            ORDER BY n DESC, name ASC");
    }

    /** دسته‌بندی‌های یک سرور (مرحلهٔ ۲ فروشگاه) */
    private static function shopCats(int $panelId): array
    {
        return DB::all("SELECT COALESCE(NULLIF(category,''),'عمومی') AS c,
                COUNT(*) AS n, MIN(price) AS minp
            FROM {p}products
            WHERE active = 1 AND stock <> 0 AND panel_id = :p
            GROUP BY c ORDER BY n DESC, c ASC", [':p' => $panelId]);
    }

    private static function panelName(int $panelId): string
    {
        $n = trim((string)DB::val('SELECT name FROM {p}panels WHERE id = :id', [':id' => $panelId], ''));
        return $n !== '' ? $n : ('سرور ' . fa_num($panelId));
    }

    /** پرچم حدسی از روی نام سرور */
    private static function panelFlag(string $name): string
    {
        $n = mb_strtolower($name);
        $map = [
            'ترکیه' => '🇹🇷', 'turk' => '🇹🇷',
            'آلمان' => '🇩🇪', 'german' => '🇩🇪',
            'هلند' => '🇳🇱', 'nether' => '🇳🇱',
            'فرانس' => '🇫🇷', 'france' => '🇫🇷',
            'امارات' => '🇦🇪', 'dubai' => '🇦🇪',
            'انگل' => '🇬🇧', 'london' => '🇬🇧',
            'امریکا' => '🇺🇸', 'usa' => '🇺🇸',
            'فینلاند' => '🇫🇮', 'finland' => '🇫🇮',
            'مولتی' => '🌐', 'multi' => '🌐', 'ایرا��' => '🇮🇷',
        ];
        foreach ($map as $k => $v) {
            if (mb_strpos($n, mb_strtolower((string)$k)) !== false) return $v;
        }
        return '🖥';
    }

    private static function catIndex(int $panelId, string $cat): int
    {
        $i = 0;
        foreach (self::shopCats($panelId) as $c) {
            if ((string)$c['c'] === $cat) return $i;
            $i++;
        }
        return 0;
    }

    /** مرحلهٔ ۱ از ۳: انتخاب سرور / لوکیشن */
    private static function sectionProducts($chatId, $msgId = null): void
    {
        $panels = self::shopPanels();
        if (!$panels) {
            $txt = "🛒 <b>فروشگ��ه</b>

فعلاً محصولی برای فروش موجود نیست.
کمی بعد دوباره سر بزنید 🙏";
            $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb([Kb::backRow()]))
                   : Tg::send($chatId, $txt, self::kbMain());
            return;
        }
        if (count($panels) === 1) { self::showPanel($chatId, $msgId, (int)$panels[0]['id']); return; }

        $tot  = 0;
        $rows = [];
        foreach ($panels as $pa) {
            $tot += (int)$pa['n'];
            $nm = trim((string)($pa['name'] ?? ''));
            if ($nm === '') $nm = 'سرور ' . fa_num((int)$pa['id']);
            $rows[] = [Tg::btn(
                self::panelFlag($nm) . ' ' . $nm,
                'pn:' . (int)$pa['id']
            )];
        }
        /* fixed78: دکمهٔ «حجم و زمان دلخواه» فقط بعد از انتخاب سرور نمایش داده می‌شود (نه در مرحلهٔ ۱) */
        $rows[] = Kb::backRow();

        $l   = [];
        $l[] = '🛒 <b>فروشگاه — مرحلهٔ ۱ از ۳</b>';
        $l[] = '<code>───────────────</code>';
        $l[] = '🌍 اول <b>سرور یا لوکیشن</b> را انتخاب کنید؛';
        $l[] = 'سپس دسته‌بندی و در نهایت محصول.';
        $l[] = '';
        $l[] = '🗂 ' . fa_num(count($panels)) . ' سرور فعال • ' . fa_num($tot) . ' طرح آماده';
        $l[] = '👛 کیف پول شما: <b>' . money((int)self::$u['balance']) . ' ' . currency() . '</b>';
        /* fixed74: بنر کمپین تخفیف */
        if (class_exists('Campaign') && ($cbn = Campaign::banner()) !== '') {
            $l[] = '<code>───────────────</code>';
            $l[] = $cbn;
        }

        $txt = implode("
", $l);
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    /** مرحلهٔ ۲ از ۳: دسته‌بندی‌های سرور انتخاب‌شده */
    private static function showPanel($chatId, $msgId, int $panelId): void
    {
        $cats = self::shopCats($panelId);
        if (!$cats) {
            $txt  = '⚠️ روی این سرور فعلاً محصولی نیست.';
            $rows = [
                [Tg::btn('🌍 انتخاب سرور دیگر', 'menu:products')],
                [Tg::btn('🏠 منوی اصلی', 'menu:main')],
            ];
            $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
            return;
        }

        $multi = count(self::shopPanels()) > 1;
        if (count($cats) === 1) { self::showCategory($chatId, $msgId, (string)$cats[0]['c'], $panelId); return; }

        $nm   = self::panelName($panelId);
        $rows = [];
        $tot  = 0;
        $i    = 0;
        foreach ($cats as $c) {
            $tot += (int)$c['n'];
            $rows[] = [Tg::btn(
                '📂 ' . (string)$c['c'],
                'pc:' . $panelId . ':' . $i
            )];
            $i++;
        }
        $cc = self::cusCfg();
        if ($cc !== null && !empty($cc['by_product'])) {
            /* fixed76: محصولات دلخواه این سرور */
            foreach (self::cusForPanel($cc, $panelId) as $cpo) {
                $rows[] = [Tg::btn('📐 ' . (string)($cpo['name'] !== '' ? $cpo['name'] : 'حجم و زمان دلخواه'), 'cus:pn:' . (int)$cpo['key'])];
            }
        } elseif ($cc !== null && self::cusOpt($cc, $panelId) !== null) {
            $rows[] = [Tg::btn('📐 حجم و زمان دلخواه این سرور', 'cus:pn:' . $panelId)];
        }

        $nav = [];
        if ($multi) $nav[] = Tg::btn('⬅️ سرورها', 'menu:products');
        $nav[]  = Tg::btn('🏠 منوی اصلی', 'menu:main');
        $rows[] = $nav;

        $l   = [];
        $l[] = '📂 <b>دسته‌بندی‌ها — مرحلهٔ ۲ از ۳</b>';
        $l[] = '<code>───────────────</code>';
        $l[] = self::panelFlag($nm) . ' سرور: <b>' . h($nm) . '</b>';
        $l[] = '🗂 ' . fa_num(count($cats)) . ' دسته • ' . fa_num($tot) . ' طرح';
        $l[] = '';
        $l[] = 'یک دسته را انتخاب کنید 👇';

        $txt = implode("
", $l);
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    /** باز کردن دسته با شماره (تا callback_data کوتاه بماند) */
    private static function showCatIdx($chatId, $msgId, int $panelId, int $idx): void
    {
        $cats = self::shopCats($panelId);
        if (!$cats) { self::sectionProducts($chatId, $msgId); return; }
        if (!isset($cats[$idx])) $idx = 0;
        self::showCategory($chatId, $msgId, (string)$cats[$idx]['c'], $panelId);
    }

    /**
     * برچسب دکمهٔ محصول
     * اگر خودِ نام محصول حجم/مدت را داشته باشد، دوباره تکرار نمی‌شود (رفع دابل‌نیم)
     */
    private static function productLabel(array $p): string
    {
        $name  = trim((string)($p['name'] ?? ''));
        $price = money((int)($p['price'] ?? 0));
        if ($name === '') $name = 'محصول';

        /* fixed76: محصول «حجم و زمان دلخواه» — قیمت هر گیگ / هر روز */
        if (self::isCusProduct($p)) {
            $pg = (int)($p['price_gb'] ?? 0);
            $pd = (int)($p['price_day'] ?? 0);
            $parts = [];
            if ($pg > 0) $parts[] = 'هر گیگ ' . money($pg);
            if ($pd > 0) $parts[] = 'هر روز ' . money($pd);
            return '📐 ' . $name . ($parts ? ' | ' . implode(' • ', $parts) : '');
        }

        $hasSpec = false;
        foreach (['|', 'گیگ', 'روز', 'ماه', 'نامحدود', 'GB', 'gb', 'Gb'] as $needle) {
            if (mb_strpos($name, $needle) !== false) { $hasSpec = true; break; }
        }
        if ($hasSpec) return $name . ' | ' . $price;

        $vol = (float)($p['volume_gb'] ?? 0) > 0
            ? fa_num((string)round((float)$p['volume_gb'])) . 'گیگ'
            : 'نامحدود';
        $day = (int)($p['days'] ?? 0) > 0 ? fa_num((int)$p['days']) . 'روز' : 'بدون انقضا';

        return $name . ' | ' . $vol . ' | ' . $day . ' | ' . $price;
    }

    private static function showCategory($chatId, $msgId, string $cat, int $panelId = 0): void
    {
        $sql = "SELECT * FROM {p}products WHERE active = 1 AND stock <> 0
            AND COALESCE(NULLIF(category,''),'عمومی') = :c";
        $par = [':c' => $cat];
        if ($panelId > 0) { $sql .= ' AND panel_id = :p'; $par[':p'] = $panelId; }
        $sql .= ' ORDER BY sort ASC, price ASC';

        $rows_db = DB::all($sql, $par);
        if (!$rows_db) {
            $txt  = '⚠️ در این دسته محصولی نیست.';
            $rows = [
                [Tg::btn('⬅️ بازگشت', $panelId > 0 ? ('pn:' . $panelId) : 'menu:products')],
                [Tg::btn('🏠 منوی اصلی', 'menu:main')],
            ];
            $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
            return;
        }

        $rows = [];
        $min  = 0;
        foreach ($rows_db as $p) {
            $pr = (int)($p['price'] ?? 0);
            if ($min === 0 || $pr < $min) $min = $pr;
            /* fixed76: محصول «حجم و زمان دلخواه» مستقیماً به مسیر انتخاب حجم/مدت می‌رود */
            $rows[] = [Tg::btn(self::productLabel($p), (self::isCusProduct($p) ? 'cus:pn:' : 'p:') . $p['id'])];
        }

        $cc = self::cusCfg();
        if ($cc !== null && empty($cc['by_product'])) {
            $rows[] = [($panelId > 0 && self::cusOpt($cc, $panelId) !== null)
                ? Tg::btn('📐 حجم و زمان دلخواه این سرور', 'cus:pn:' . $panelId)
                : Tg::btn('📐 حجم و زمان دلخواه', 'cus:start')];
        }

        $nav   = [];
        $nav[] = Tg::btn('⬅️ دسته‌ها', $panelId > 0 ? ('pn:' . $panelId) : 'menu:products');
        $nav[] = Tg::btn('🏠 منوی اصلی', 'menu:main');
        $rows[] = $nav;
        if ($panelId > 0 && count(self::shopPanels()) > 1) {
            $rows[] = [Tg::btn('🌍 تغییر سرور', 'menu:products')];
        }

        $l   = [];
        $l[] = '🛍 <b>محصولات — مرحلهٔ ۳ از ۳</b>';
        $l[] = '<code>───────────────</code>';
        if ($panelId > 0) {
            $nm  = self::panelName($panelId);
            $l[] = self::panelFlag($nm) . ' سرور: <b>' . h($nm) . '</b>';
        }
        $l[] = '📂 دسته: <b>' . h($cat) . '</b>';
        $l[] = '📦 ' . fa_num(count($rows_db)) . ' محصول • شروع از ' . money($min) . ' ' . currency();
        $l[] = '';
        $l[] = 'محصول مورد نظر را انتخاب کنید 👇';

        $txt = implode("
", $l);
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    private static function showProduct($chatId, $msgId, int $pid): void
    {
        $p = DB::one('SELECT * FROM {p}products WHERE id = :id AND active = 1', [':id' => $pid]);
        if (!$p) { Tg::send($chatId, '❌ محصول یافت نشد.'); return; }

        $d  = jdec(self::$u['state_data'] ?? null, []);
        $dc = ((int)($d['pid'] ?? 0) === $pid) ? (string)($d['dc'] ?? '') : '';

        /* fixed76: محصول دلخواه → مسیر انتخاب حجم/مدت */
        if (self::isCusProduct($p)) {
            $cc = self::cusCfg();
            $co = $cc ? self::cusOpt($cc, $pid) : null;
            if ($cc && $co) { if (self::fjGate($chatId, 'buy')) self::cusBegin($chatId, $cc, $co); }
            else Tg::send($chatId, '⚠️ این محصول در حال حاضر در دسترس نیست.');
            return;
        }

        $price    = (int)$p['price'];
        $discount = 0;
        if ($dc !== '') {
            $chk = Codes::checkDiscount($dc, self::$u, $price, $pid);
            if ($chk['ok']) $discount = (int)$chk['discount'];
            else $dc = '';
        }
        /* fixed74: کمپین تخفیف — اگر بیشتر از کد باشد، کمپین اعمال می‌شود */
        $campSrc = '';
        if (class_exists('Campaign')) {
            $cb = Campaign::best($price, $pid, $discount, 'new');
            if ($cb['source'] === 'camp') { $discount = (int)$cb['discount']; $campSrc = 'camp'; }
        }

        $pnl = (int)($p['panel_id'] ?? 0);
        $cat = trim((string)($p['category'] ?? ''));
        if ($cat === '') $cat = 'عمومی';

        $pay  = max(0, $price - $discount);
        $bal  = (int)self::$u['balance'];
        $need = max(0, $pay - $bal);
        $vol  = (float)$p['volume_gb'] > 0
            ? fa_num((string)round((float)$p['volume_gb'], 2)) . ' گیگابایت'
            : '♾ نامحدود';
        $old  = (int)($p['old_price'] ?? 0);

        $l   = [];
        $l[] = '📦 <b>' . h((string)$p['name']) . '</b>';
        $l[] = '<code>───────────────</code>';
        if ($pnl > 0) {
            $nm  = self::panelName($pnl);
            $l[] = self::panelFlag($nm) . ' سرور: <b>' . h($nm) . '</b>';
        }
        $l[] = '📂 دسته: ' . h($cat);
        $l[] = '📊 حجم: <b>' . $vol . '</b>';
        $l[] = '📅 مدت: <b>' . ((int)$p['days'] > 0 ? fa_num((int)$p['days']) . ' روز' : '♾ نامحدود') . '</b>';
        $l[] = '👥 اتصال همزمان: ' . ((int)$p['ip_limit'] > 0 ? fa_num((int)$p['ip_limit']) . ' دستگاه' : '♾ بدون محدودیت');
        if ((int)$p['stock'] > 0)          $l[] = '🏷 موجودی: ' . fa_num((int)$p['stock']) . ' عدد';
        if ((int)($p['sold'] ?? 0) > 0)    $l[] = '🔥 فروش رفته: ' . fa_num((int)$p['sold']) . ' عدد';
        if (trim((string)$p['description']) !== '') {
            $l[] = '';
            $l[] = '📝 ' . h((string)$p['description']);
        }
        $l[] = '<code>───────────────</code>';
        if ($discount > 0 && $campSrc === 'camp') {
            $camp = Campaign::active();
            $l[] = '💰 قیمت: <s>' . money($price) . '</s> → <b>' . money($pay) . ' ' . currency() . '</b>';
            $l[] = '🔥 کمپین «' . h((string)($camp['title'] ?? 'تخفیف ویژه')) . '» اعمال شد (−' . money($discount) . ') • ⏳ ' . Campaign::remaining($camp);
            if ($dc !== '') $l[] = 'ℹ️ کد <code>' . h($dc) . '</code> کمتر از کمپین بود و استفاده نمی‌شود.';
        } elseif ($discount > 0) {
            $l[] = '💰 قیمت: <s>' . money($price) . '</s> → <b>' . money($pay) . ' ' . currency() . '</b>';
            $l[] = '🎟 کد <code>' . h($dc) . '</code> اعمال شد (−' . money($discount) . ')';
        } else {
            $l[] = '💰 قیمت: <b>' . money($price) . ' ' . currency() . '</b>'
                 . ($old > $price ? ' به‌جای <s>' . money($old) . '</s>' : '');
        }
        $l[] = '👛 کیف پول: ' . money($bal) . ' ' . currency();
        $l[] = $need > 0
            ? '⚠️ <b>' . money($need) . ' ' . currency() . '</b> کم دارید'
            : '✅ موجودی شما کافی است';

        $rows = [];
        if ($need > 0) {
            $rows[] = [Tg::btn('💳 شارژ کیف پول', 'menu:wallet')];
            $rows[] = [Tg::btn('🛒 تلاش برای خرید', 'buy:' . $pid)];
        } else {
            $rows[] = [Tg::btn('✅ خرید و دریافت کانفیگ', 'buy:' . $pid)];
        }
        $rows[] = $discount > 0
            ? [Tg::btn('🗑 حذف کد تخفیف', 'dcrm:' . $pid)]
            : [Tg::btn('🎟 دارم کد تخفیف', 'dc:' . $pid)];

        $nav = [];
        if ($pnl > 0) $nav[] = Tg::btn('⬅️ ' . mb_substr($cat, 0, 16), 'pc:' . $pnl . ':' . self::catIndex($pnl, $cat));
        else          $nav[] = Tg::btn('⬅️ محصولات', 'menu:products');
        $nav[]  = Tg::btn('🌍 سرورها', 'menu:products');
        $rows[] = $nav;
        $rows[] = [Tg::btn('🏠 منوی اصلی', 'menu:main')];

        $txt = implode("
", $l);
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    /* ==================== حجم و زمان دلخواه (پلن سفارشی مشتری) ==================== */

    /** تنظیمات پلن دلخواه؛ اگر غیرفعال یا ناقص باشد null برمی‌گرداند */
    /** fixed76: محصولات نوع «حجم و زمان دلخواه» (type = custom در صفحهٔ محصولات) — اگر ستون type نباشد، آرایهٔ خالی */
    public static function cusProducts(): array
    {
        static $memo = null;
        if ($memo !== null) return $memo;
        $memo = [];
        try {
            if (class_exists('Migrate') && method_exists('Migrate', 'hasColumn') && !Migrate::hasColumn('products', 'type')) return $memo;
            $rows = DB::all("SELECT * FROM {p}products WHERE active = 1 AND stock <> 0 AND type = 'custom' ORDER BY sort ASC, id ASC") ?: [];
        } catch (Throwable $e) {
            $rows = [];
        }
        foreach ($rows as $p) {
            if ((int)($p['price_gb'] ?? 0) <= 0 && (int)($p['price_day'] ?? 0) <= 0) continue;
            $memo[] = $p;
        }
        return $memo;
    }

    /** fixed76: آیا این ردیف محصول از نوع «حجم و زمان دلخواه» است؟ */
    public static function isCusProduct(?array $p): bool
    {
        return is_array($p) && (string)($p['type'] ?? 'fixed') === 'custom';
    }

    /** fixed76: بازهٔ مجاز [min_gb, max_gb, min_days, max_days] — محصول دلخواه بازهٔ خودش را دارد، تنظیمات قدیمی بازهٔ کلی */
    private static function cusLim(array $c, ?array $o): array
    {
        return [
            max(1, (int)($o['min_gb']   ?? $c['min_gb']   ?? 1)),
            max(1, (int)($o['max_gb']   ?? $c['max_gb']   ?? 100)),
            max(1, (int)($o['min_days'] ?? $c['min_days'] ?? 1)),
            max(1, (int)($o['max_days'] ?? $c['max_days'] ?? 90)),
        ];
    }

    /** fixed76: گزینه‌های محصول دلخواه مربوط به یک سرور */
    private static function cusForPanel(array $c, int $panelId): array
    {
        $out = [];
        foreach ($c['opts'] ?? [] as $o) {
            if ((int)($o['panel']['id'] ?? 0) === $panelId) $out[] = $o;
        }
        return $out;
    }

    /** fixed76: دکمهٔ «💰 تعرفه‌ها» — فهرست متنی محصولات فعال به تفکیک سرور */
    /** fixed79: اعمال کمپین فعال روی قیمت پلن دلخواه — ['final','discount','line'] (همان منطق finalizePurchase) */
    private static function cusCamp(array $o, int $price): array
    {
        $pid = (int)($o['product']['id'] ?? 0);
        $b   = class_exists('Campaign') ? Campaign::best($price, $pid, 0, 'new') : ['final' => $price, 'discount' => 0, 'source' => ''];
        $fin = (int)($b['final'] ?? $price);
        $dis = (int)($b['discount'] ?? 0);
        $line = $dis > 0
            ? "\n" . '🔥 تخفیف کمپین: <b>' . money($dis) . ' ' . currency() . '</b> (قیمت اصلی <s>' . money($price) . '</s>)'
            : '';
        return ['final' => $fin, 'discount' => $dis, 'line' => $line];
    }

    /** fixed79: دکمهٔ «🧾 سوابق خرید» — ۸ خرید و ۸ تراکنش آخر کاربر */
    private static function sectionOrders($chatId): void
    {
        $uid = (int)self::$u['id'];
        try {
            $orders = DB::all("SELECT o.*, pr.name AS pr_name FROM {p}orders o LEFT JOIN {p}products pr ON pr.id = o.product_id
                WHERE o.user_id = :u ORDER BY o.id DESC LIMIT 8", [':u' => $uid]) ?: [];
        } catch (Throwable $e) { $orders = []; }
        try {
            $txs = DB::all('SELECT * FROM {p}transactions WHERE user_id = :u ORDER BY id DESC LIMIT 8', [':u' => $uid]) ?: [];
        } catch (Throwable $e) { $txs = []; }

        $oSt = ['paid' => '✅', 'done' => '✅', 'completed' => '✅', 'pending' => '⏳', 'failed' => '❌', 'canceled' => '❌', 'cancelled' => '❌', 'refunded' => '↩️'];
        $tSt = ['approved' => '✅', 'done' => '✅', 'paid' => '✅', 'pending' => '⏳', 'rejected' => '❌', 'failed' => '❌', 'canceled' => '❌'];
        $tTy = ['deposit' => 'شارژ کیف پول', 'withdraw' => 'برداشت', 'gift' => 'هدیه', 'refund' => 'بازگشت وجه', 'purchase' => 'خرید', 'admin' => 'تغییر توسط مدیر', 'reseller' => 'نمایندگی'];

        $l = ['🧾 <b>سوابق خرید و پرداخت</b>', '<code>───────────────</code>', ''];
        $l[] = '🛒 <b>خریدهای اخیر</b>';
        if (!$orders) $l[] = '▫️ هنوز خریدی ثبت نشده است.';
        foreach ($orders as $o) {
            $st  = strtolower((string)($o['status'] ?? ''));
            $amt = (int)($o['final_amount'] ?? $o['amount'] ?? 0);
            $nm  = trim((string)($o['pr_name'] ?? ''));
            if ($nm === '') $nm = ((string)($o['type'] ?? '') === 'renew') ? 'تمدید سرویس' : 'سرویس';
            $l[] = ($oSt[$st] ?? '▫️') . ' ' . h($nm) . ' — <b>' . money($amt) . ' ' . currency() . '</b>'
                 . ((int)($o['discount_amount'] ?? 0) > 0 ? ' (تخفیف ' . money((int)$o['discount_amount']) . ')' : '')
                 . ' • ' . to_jalali((string)$o['created_at']);
        }
        $l[] = '';
        $l[] = '💳 <b>تراکنش‌های کیف پول</b>';
        if (!$txs) $l[] = '▫️ تراکنشی ثبت نشده است.';
        foreach ($txs as $t) {
            $st = strtolower((string)($t['status'] ?? ''));
            $ty = strtolower((string)($t['type'] ?? ''));
            $l[] = ($tSt[$st] ?? '▫️') . ' ' . ($tTy[$ty] ?? h($ty)) . ' — <b>' . money((int)($t['amount'] ?? 0)) . ' ' . currency() . '</b>'
                 . ((string)($t['method'] ?? '') !== '' ? ' (' . h((string)$t['method']) . ')' : '')
                 . ' • ' . to_jalali((string)$t['created_at']);
        }
        $l[] = '';
        $l[] = '👛 موجودی فعلی: <b>' . money((int)self::$u['balance']) . ' ' . currency() . '</b>';
        $txt = implode("\n", $l);
        if (mb_strlen($txt) > 3900) $txt = mb_substr($txt, 0, 3900) . '…';
        Tg::send($chatId, $txt, Tg::ikb([[Tg::btn('💼 سرویس‌های من', 'menu:services'), Tg::btn('💳 شارژ کیف پول', 'menu:wallet')], [Tg::btn('🏠 منوی اصلی', 'menu:main')]]));
    }

    private static function priceList($chatId): void
    {
        try {
            $rows = DB::all("SELECT pr.*, pa.name AS pn_name FROM {p}products pr LEFT JOIN {p}panels pa ON pa.id = pr.panel_id
                WHERE pr.active = 1 AND pr.stock <> 0 ORDER BY pa.sort ASC, pa.id ASC, pr.sort ASC, pr.price ASC") ?: [];
        } catch (Throwable $e) {
            $rows = [];
        }
        if (!$rows) { Tg::send($chatId, '⚠️ در حال حاضر محصولی برای فروش ثبت نشده است.'); return; }
        $l   = ['💰 <b>تعرفهٔ سرویس‌ها</b>', '<code>───────────────</code>'];
        $cur = null;
        $n   = 0;
        foreach ($rows as $p) {
            $pn = trim((string)($p['pn_name'] ?? ''));
            if ($pn !== $cur) {
                $cur = $pn;
                $l[] = '';
                $l[] = self::panelFlag($pn) . ' <b>' . h($pn !== '' ? $pn : 'سرور') . '</b>';
            }
            $l[] = '▫️ ' . h(self::productLabel($p)) . ' ' . currency();
            if (++$n >= 60) { $l[] = '…'; break; }
        }
        $l[] = '';
        $l[] = 'برای خرید، وارد فروشگاه شوید 👇';
        $txt = implode("\n", $l);
        if (mb_strlen($txt) > 3900) $txt = mb_substr($txt, 0, 3900) . '…';
        Tg::send($chatId, $txt, Tg::ikb([[Tg::btn('🛍 ورود به فروشگاه', 'menu:products')], [Tg::btn('🏠 منوی اصلی', 'menu:main')]]));
    }

    /** fixed76: دکمهٔ «🔥 پیشنهاد ویژه» — بنر کمپین فعال + ورود به فروشگاه */
    private static function sectionCampaign($chatId): void
    {
        $t = '';
        if (class_exists('Campaign')) {
            try { $t = trim((string)Campaign::banner()); } catch (Throwable $e) { $t = ''; }
        }
        if ($t === '') {
            $t = "🔥 <b>پیشنهاد ویژه</b>\n<code>───────────────</code>\n"
               . 'در حال حاضر کمپین تخفیف فعالی نداریم؛ ولی می‌توانید محصولات و تعرفه‌ها را ببینید 👇';
        }
        Tg::send($chatId, $t, Tg::ikb([[Tg::btn('🛍 فروشگاه', 'menu:products')], [Tg::btn('🏠 منوی اصلی', 'menu:main')]]));
    }

    private static function cusCfg(): ?array
    {
        /* کش داخل همین آپدیت تا کوئری تکراری اجرا نشود */
        static $memo = false;
        if ($memo !== false) return $memo;
        $memo = null;

        /* fixed76: «حجم و زمان دلخواه» به‌عنوان محصول (نوع custom) — بر تنظیمات قدیمی اولویت دارد */
        $cp = self::cusProducts();
        if ($cp) {
            $opts = [];
            foreach ($cp as $p) {
                $pnRow = DB::one('SELECT * FROM {p}panels WHERE id = :id AND active = 1', [':id' => (int)$p['panel_id']]);
                if (!$pnRow) continue;
                $opts[] = [
                    'key'       => (int)$p['id'],
                    'product'   => $p,
                    'panel'     => $pnRow,
                    'price_gb'  => (int)($p['price_gb'] ?? 0),
                    'price_day' => (int)($p['price_day'] ?? 0),
                    'min_gb'    => max(1, (int)($p['min_gb'] ?? 1)),
                    'max_gb'    => max(1, (int)($p['max_gb'] ?? 100)),
                    'min_days'  => max(1, (int)($p['min_days'] ?? 1)),
                    'max_days'  => max(1, (int)($p['max_days'] ?? 90)),
                    'name'      => (string)($p['name'] ?? ''),
                ];
            }
            if ($opts) {
                return $memo = [
                    'min_gb'     => min(array_column($opts, 'min_gb')),
                    'max_gb'     => max(array_column($opts, 'max_gb')),
                    'min_days'   => min(array_column($opts, 'min_days')),
                    'max_days'   => max(array_column($opts, 'max_days')),
                    'opts'       => $opts,
                    'by_product' => true,
                ];
            }
        }

        if ((int)DB::setting('cus_enabled', 0) !== 1) return null;
        $minG = max(1, (int)DB::setting('cus_min_gb', 5));
        $maxG = max($minG, (int)DB::setting('cus_max_gb', 100));
        $minD = max(1, (int)DB::setting('cus_min_days', 7));
        $maxD = max($minD, (int)DB::setting('cus_max_days', 90));
        $defG = max(0, (int)DB::setting('cus_price_gb', 0));
        $defD = max(0, (int)DB::setting('cus_price_day', 0));

        /* محصول پایهٔ هر پنل: انتخاب مدیر یا به‌طور خودکار اولین محصول فعال همان پنل */
        $pick = static function (int $panelId, int $pid): ?array {
            if ($pid > 0) {
                $p = DB::one('SELECT * FROM {p}products WHERE id = :id AND active = 1', [':id' => $pid]);
                if ($p && ($panelId <= 0 || (int)$p['panel_id'] === $panelId)) return $p;
            }
            if ($panelId > 0) {
                $p = DB::one('SELECT * FROM {p}products WHERE panel_id = :pn AND active = 1 ORDER BY sort ASC, price ASC, id ASC LIMIT 1', [':pn' => $panelId]);
                return $p ?: null;
            }
            return null;
        };

        /* ردیف‌های قیمت اختصاصی هر پنل — کلید هر ردیف شناسهٔ پنل است */
        $opts = [];
        $map  = jdec((string)DB::setting('cus_panels', ''), []);
        if (is_array($map)) {
            foreach ($map as $k => $row) {
                if (!is_array($row) || (int)($row['on'] ?? 0) !== 1) continue;
                $pnId = (int)$k;
                if ($pnId <= 0) $pnId = (int)($row['pn'] ?? 0);
                $panel = DB::one('SELECT * FROM {p}panels WHERE id = :id AND active = 1', [':id' => $pnId]);
                if (!$panel) continue;
                $p = $pick($pnId, (int)($row['pid'] ?? 0));
                if (!$p) continue;
                $pg = max(0, (int)($row['pg'] ?? 0));
                $pd = max(0, (int)($row['pd'] ?? 0));
                if ($pg <= 0 && $pd <= 0) { $pg = $defG; $pd = $defD; }
                if ($pg <= 0 && $pd <= 0) continue;
                $opts[(int)$panel['id']] = [
                    'key' => (int)$panel['id'], 'product' => $p, 'panel' => $panel,
                    'price_gb' => $pg, 'price_day' => $pd,
                ];
            }
        }

        /* بدون ردیف پنلی فعال: اول محصول پایهٔ سراسری، وگرنه همهٔ سرورهای دارای محصول */
        if (!$opts) {
            $pid = (int)DB::setting('cus_product_id', 0);
            if ($pid > 0 && ($defG > 0 || $defD > 0)) {
                $p = DB::one('SELECT * FROM {p}products WHERE id = :id AND active = 1', [':id' => $pid]);
                if ($p) {
                    $panel = DB::one('SELECT * FROM {p}panels WHERE id = :id AND active = 1', [':id' => (int)$p['panel_id']]);
                    if ($panel) {
                        $opts[(int)$panel['id']] = [
                            'key' => (int)$panel['id'], 'product' => $p, 'panel' => $panel,
                            'price_gb' => $defG, 'price_day' => $defD,
                        ];
                    }
                }
            }
        }
        if (!$opts && ($defG > 0 || $defD > 0)) {
            foreach (DB::all('SELECT * FROM {p}panels WHERE active = 1 ORDER BY sort ASC, id ASC') as $panel) {
                $p = $pick((int)$panel['id'], 0);
                if (!$p) continue;
                $opts[(int)$panel['id']] = [
                    'key' => (int)$panel['id'], 'product' => $p, 'panel' => $panel,
                    'price_gb' => $defG, 'price_day' => $defD,
                ];
            }
        }
        if (!$opts) return null;

        return $memo = [
            'min_gb' => $minG, 'max_gb' => $maxG,
            'min_days' => $minD, 'max_days' => $maxD,
            'opts' => array_values($opts),
        ];
    }

    /** گزینه انتخاب‌شده بر اساس شناسه پنل — اگر فقط یک گزینه موجود باشد همان برمی‌گردد */
    private static function cusOpt(array $c, int $key): ?array
    {
        $opts = $c['opts'] ?? [];
        if (!$opts) return null;
        if ($key > 0) {
            foreach ($opts as $o) if ((int)$o['key'] === $key) return $o;
            return null;
        }
        return count($opts) === 1 ? $opts[0] : null;
    }

    /** شروع دریافت حجم برای گزینه انتخاب‌شده */
    private static function cusBegin($chatId, array $c, array $o): void
    {
        self::setState('cus_gb', ['pn' => (int)$o['key']]);
        $L = self::cusLim($c, $o);
        $c['min_gb'] = $L[0]; $c['max_gb'] = $L[1]; $c['min_days'] = $L[2]; $c['max_days'] = $L[3];
        $t = "📐 <b>حجم و زمان دلخواه</b>\n<code>───────────────</code>\n"
           . "سرویس را دقیقاً با حجم و مدتی که لازم دارید بسازید 👇\n\n"
           . '🌍 سرور: <b>' . h((string)($o['panel']['name'] ?? '-')) . "</b>\n"
           . '💵 قیمت هر گیگ: <b>' . money((int)$o['price_gb']) . ' ' . currency() . "</b>\n"
           . '💵 قیمت هر روز: <b>' . money((int)$o['price_day']) . ' ' . currency() . "</b>\n\n"
           . '📊 اول <b>حجم</b> دلخواه را به «گیگابایت» بفرستید (بین ' . fa_num($c['min_gb']) . ' تا ' . fa_num($c['max_gb']) . '):';
        if (!empty($o['name'])) $t = str_replace('<b>حجم و زمان دلخواه</b>', '<b>' . h((string)$o['name']) . '</b>', $t);
        Tg::send($chatId, $t, Kb::cancel());
    }

    /** قیمت پلن دلخواه: حجم × قیمت هر گیگ + مدت × قیمت هر روز */
    private static function cusPrice(array $c, int $gb, int $days): int
    {
        return max(0, $gb * (int)$c['price_gb'] + $days * (int)$c['price_day']);
    }

    /** بخش مستقل «حجم و زمان دلخواه» — از منوی اصلی یا فروشگاه */
    private static function cusMenu($chatId): void
    {
        $c = self::cusCfg();
        if (!$c) { Tg::send($chatId, '⚠️ بخش «حجم و زمان دلخواه» در ��ال حاضر غیرفعال است.'); return; }
        if (!self::fjGate($chatId, 'buy')) return;
        if (class_exists('Security')) {
            $gu = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)self::$u['id']]) ?: [];
            if ($gu && Security::needs($gu, 'buy')) {
                Tg::send($chatId, Security::blockMessage());
                self::verifyMenu($chatId);
                return;
            }
        }
        if (count($c['opts']) > 1) {
            $kb = [];
            $ln = [];
            foreach ($c['opts'] as $o) {
                $nm = trim((string)($o['panel']['name'] ?? ''));
                if ($nm === '') $nm = 'سرور ' . fa_num((int)$o['key']);
                $lbl  = self::panelFlag($nm) . ' ' . $nm . (!empty($o['name']) ? ' — ' . (string)$o['name'] : '');
                $kb[] = [Tg::btn(mb_substr($lbl, 0, 60), 'cus:pn:' . (int)$o['key'])];
                $ln[] = '▫️ <b>' . h($nm) . '</b> — هر گیگ ' . money((int)$o['price_gb']) . ' • هر روز ' . money((int)$o['price_day']) . ' ' . currency();
            }
            $kb[] = [Tg::btn('🏠 منوی اصلی', 'menu:main')];
            $t = "📐 <b>حجم و زمان دلخواه</b>\n<code>───────────────</code>\n"
               . "سرویس را دقیقاً با حجم و مدتی که لازم دارید بسازید.\n\n"
               . "💰 <b>تعرفهٔ سرورها:</b>\n" . implode("\n", $ln) . "\n\n"
               . '📊 حجم از ' . fa_num($c['min_gb']) . ' تا ' . fa_num($c['max_gb']) . ' گیگ ��� مدت از ' . fa_num($c['min_days']) . ' تا ' . fa_num($c['max_days']) . " روز\n\n"
               . 'ابتدا <b>سرور</b> موردنظر را انتخاب کنید 👇';
            Tg::send($chatId, $t, Tg::ikb($kb));
            return;
        }
        self::cusBegin($chatId, $c, $c['opts'][0]);
    }

    /** مسیر دکمه‌های «حجم و زمان دلخواه» — cus:start و cus:pn:PANEL و cus:ok:GB-DAYS-PANEL */
    private static function cusAction($chatId, $msgId, $cbId, string $arg, string $arg2): void
    {
        $c = self::cusCfg();
        if (!$c) { Tg::answerCb($cbId, '⚠️ این بخش در حال حاضر غیرفعال است.', true); return; }
        if (!self::fjGate($chatId, 'buy')) { Tg::answerCb($cbId); return; }

        if ($arg === 'start') {
            Tg::answerCb($cbId);
            self::cusMenu($chatId);
            return;
        }

        if ($arg === 'pn') {
            $o = self::cusOpt($c, (int)$arg2);
            if (!$o) { Tg::answerCb($cbId, '⚠️ این سرور در دسترس نیست.', true); return; }
            Tg::answerCb($cbId);
            self::cusBegin($chatId, $c, $o);
            return;
        }

        if ($arg === 'ok') {
            $pp   = array_pad(array_map('intval', explode('-', $arg2)), 3, 0);
            $gb   = max(0, (int)$pp[0]);
            $days = max(0, (int)$pp[1]);
            $o    = self::cusOpt($c, (int)$pp[2]);
            if ($o) { $L = self::cusLim($c, $o); $c['min_gb'] = $L[0]; $c['max_gb'] = $L[1]; $c['min_days'] = $L[2]; $c['max_days'] = $L[3]; }
            if (!$o) { Tg::answerCb($cbId, '⚠️ این سرور در دسترس نیست؛ لطفاً دوباره شروع کنید.', true); return; }
            if ($gb < (int)$c['min_gb'] || $gb > (int)$c['max_gb'] || $days < (int)$c['min_days'] || $days > (int)$c['max_days']) {
                Tg::answerCb($cbId, '⚠️ مقادیر معتبر نیست؛ لطفاً دوباره شروع کنید.', true);
                return;
            }
            $price = self::cusCamp($o, self::cusPrice($o, $gb, $days))['final']; /* fixed79: با کمپین */
            if ((int)self::$u['balance'] < $price) {
                Tg::answerCb($cbId, 'موجودی کافی نیست.', true);
                Tg::send($chatId, "👛 موجودی کیف پول کافی نیست.\nکمبود: <b>" . money($price - (int)self::$u['balance']) . ' ' . currency() . '</b>',
                    Tg::ikb([[Tg::btn('💳 شارژ کیف پول', 'menu:wallet')], [Tg::btn('🏠 منوی اصلی', 'menu:main')]]));
                return;
            }
            if (Svc::usernameNeedsInput($o['panel'])) {
                Tg::answerCb($cbId, 'در حال پردازش...');
                self::setState('cus_username', ['gb' => $gb, 'days' => $days, 'pn' => (int)$o['key']]);
                Tg::send($chatId, "✏️ <b>انتخاب نام کاربری</b>\nنام دلخواه خود را با حروف لاتین (۳ تا ۲۰ کاراکتر) ارسال کنید:", Kb::cancel());
                return;
            }
            Tg::answerCb($cbId, 'در حال ساخت سرویس...');
            self::cusFinalize($chatId, $gb, $days, (int)$o['key'], null);
            return;
        }

        Tg::answerCb($cbId);
    }

    /** ساخت نهایی پلن دلخواه: مشخصات محصول پایه با حجم/مدت/قیمت انتخابی جایگزین می‌شود */
    private static function cusFinalize($chatId, int $gb, int $days, int $pn, ?string $username): void
    {
        $c = self::cusCfg();
        $o = $c ? self::cusOpt($c, $pn) : null;
        if ($c && $o) { $L = self::cusLim($c, $o); $c['min_gb'] = $L[0]; $c['max_gb'] = $L[1]; $c['min_days'] = $L[2]; $c['max_days'] = $L[3]; }
        if (!$c || !$o) { Tg::send($chatId, '⚠️ این بخش در حال حاضر غیرفعال است.', self::kbMain()); return; }
        $gb   = max((int)$c['min_gb'], min((int)$c['max_gb'], $gb));
        $days = max((int)$c['min_days'], min((int)$c['max_days'], $days));
        self::finalizePurchase($chatId, (int)$o['product']['id'], '', $username, [
            'volume_gb' => $gb,
            'days'      => $days,
            'price'     => self::cusPrice($o, $gb, $days),
            'name'      => 'پلن دلخواه ' . fa_num($gb) . ' گیگ ' . fa_num($days) . ' روزه',
        ]);
    }

    private static function startPurchase($chatId, $msgId, $cbId, int $pid): void
    {
        if (!self::fjGate($chatId, 'buy')) { Tg::answerCb($cbId); return; }
        /* تایید حساب پیش از خرید */
        if (class_exists('Security')) {
            $gu = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)self::$u['id']]) ?: [];
            if ($gu && Security::needs($gu, 'buy')) {
                Tg::answerCb($cbId, 'ابتدا حساب خود را تایید کنید.', true);
                Tg::send($chatId, Security::blockMessage());
                self::verifyMenu($chatId);
                return;
            }
        }

        $p = DB::one('SELECT * FROM {p}products WHERE id = :id AND active = 1', [':id' => $pid]);
        if (!$p) { Tg::answerCb($cbId, 'محصول یافت نشد.', true); return; }
        if (self::isCusProduct($p)) { self::cusAction($chatId, $msgId, $cbId, 'pn', (string)$pid); return; }
        if ((int)$p['stock'] === 0) { Tg::answerCb($cbId, 'موجودی این محصول تمام شده است.', true); return; }

        $d  = jdec(self::$u['state_data'] ?? null, []);
        $dc = ((int)($d['pid'] ?? 0) === $pid) ? (string)($d['dc'] ?? '') : '';
        $price = (int)$p['price'];
        $discount = 0;
        if ($dc !== '') {
            $chk = Codes::checkDiscount($dc, self::$u, $price, $pid);
            if ($chk['ok']) $discount = (int)$chk['discount']; else $dc = '';
        }
        $final = max(0, $price - $discount);

        if ((int)self::$u['balance'] < $final) {
            Tg::answerCb($cbId, 'موجودی کافی نیست.', true);
            $need = $final - (int)self::$u['balance'];
            Tg::send($chatId, "👛 موجودی کیف پول کافی نیست.\nکمبود: <b>" . money($need) . ' ' . currency() . '</b>',
                Tg::ikb([[Tg::btn('💳 شارژ کیف پول', 'menu:wallet')], Kb::navRow('menu:products')]));
            return;
        }

        $panel = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)$p['panel_id']]);
        if (!$panel) { Tg::answerCb($cbId, 'سرور این محصول تنظیم نشده است.', true); return; }

        Tg::answerCb($cbId, 'در حال پردازش...');

        if (Svc::usernameNeedsInput($panel)) {
            self::setState('buy_username', ['pid' => $pid, 'dc' => $dc]);
            Tg::send($chatId, "✏️ <b>انتخاب نام کاربری</b>\nنام دلخواه خود را با حروف لاتین (۳ تا ۲۰ کاراکتر) ارسال کنید:", Kb::cancel());
            return;
        }
        self::finalizePurchase($chatId, $pid, $dc, null);
    }

    private static function finalizePurchase($chatId, int $pid, string $dc, ?string $username, ?array $ov = null): void
    {
        $p = DB::one('SELECT * FROM {p}products WHERE id = :id AND active = 1', [':id' => $pid]);
        if (!$p) { Tg::send($chatId, '❌ محصول یافت نشد.', self::kbMain()); return; }
        if ($ov !== null) {
            /* خرید «حجم و زمان دلخواه»: محصول پایه فقط سرور، اینباند و تنظیمات ساخت را تعیین می‌کند */
            $p['volume_gb'] = (float)($ov['volume_gb'] ?? $p['volume_gb']);
            $p['days']      = (int)($ov['days'] ?? $p['days']);
            $p['price']     = (int)($ov['price'] ?? $p['price']);
            $p['name']      = (string)($ov['name'] ?? $p['name']);
            $dc = '';
        }
        $panel = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)$p['panel_id']]);
        if (!$panel) { Tg::send($chatId, '❌ سرور محصول یافت نشد.', self::kbMain()); return; }

        $user = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)self::$u['id']]);
        $price = (int)$p['price'];
        $discount = 0; $codeRow = null;
        if ($dc !== '') {
            $chk = Codes::checkDiscount($dc, $user, $price, $pid);
            if ($chk['ok']) { $discount = (int)$chk['discount']; $codeRow = $chk['code']; }
        }
        /* fixed74: کمپین تخفیف زمان‌دار — بیشترین تخفیف (کمپین یا کد) اعمال می‌شود */
        $orderCode = $dc !== '' ? $dc : null;
        if (class_exists('Campaign')) {
            $b = Campaign::best($price, $pid, $discount, 'new');
            if ($b['source'] === 'camp') { $discount = (int)$b['discount']; $codeRow = null; $orderCode = 'CAMPAIGN'; }
        }
        $final = max(0, $price - $discount);
        if ((int)$user['balance'] < $final) {
            Tg::send($chatId, '❌ موجودی کیف پول کافی نیست.', self::kbMain());
            return;
        }

        if (!Wallet::debit((int)$user['id'], $final, 'خرید: ' . $p['name'])) {
            Tg::send($chatId, '❌ کسر از کیف پول انجام نشد.', self::kbMain());
            return;
        }

        $orderId = DB::insert('orders', [
            'user_id' => (int)$user['id'], 'tg_id' => (int)$user['tg_id'], 'product_id' => $pid,
            'type' => 'new', 'amount' => $price, 'discount_code' => $orderCode,
            'discount_amount' => $discount, 'final_amount' => $final, 'status' => 'pending', 'created_at' => now(),
        ]);

        Tg::send($chatId, '⏳ در حال ساخت کانفیگ... لطفاً چند لحظه صبر کنید.');

        try {
            $res = Svc::create($user, $panel, [
                'volume_gb' => (float)$p['volume_gb'],
                'days'      => (int)$p['days'],
                'ip_limit'  => (int)$p['ip_limit'],
                'product'   => $p,
                'username'  => $username,
                'inbound_id'=> (int)($p['inbound_id'] ?? 0),
            ]);
        } catch (Throwable $e) {
            app_log('purchase', 'create exception: ' . $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine()]);
            $res = ['ok' => false, 'message' => 'خطای سیستمی در ساخت سرویس: ' . $e->getMessage()];
        }

        if (!$res['ok']) {
            Wallet::credit((int)$user['id'], $final, 'refund', 'wallet', 'عدم موفقیت در ساخت سرویس #' . $orderId);
            DB::update('orders', ['status' => 'failed'], 'id = :id', [':id' => $orderId]);
            Tg::send($chatId, "❌ " . $res['message'] . "\n💰 مبلغ پرداختی به کیف پول شما بازگردانده شد.", self::kbMain());
            AdminBot::notifyAdmins("⚠️ خطا در ساخت سرویس\nکاربر: <code>" . (int)$user['tg_id'] . "</code>\nمحصول: " . h((string)$p['name']) . "\nخطا: " . h($res['message']));
            Logs::send('errors', Logs::fmt('❌ خطا در ساخت سرویس', [
                'سفارش'  => '<code>#' . $orderId . '</code>',
                'کاربر'   => '<code>' . (int)$user['tg_id'] . '</code>',
                'محصول'  => h((string)$p['name']),
                'پنل'     => h((string)($panel['name'] ?? '')),
                'مبلغ'    => money($final) . ' ' . currency(),
            ], 'خطا: ' . h((string)$res['message']) . ' – مبلغ به کیف پول بازگشت.'));
            return;
        }

        $service = $res['service'];
        try {
            DB::update('orders', ['status' => 'paid', 'service_id' => (int)$service['id']], 'id = :id', [':id' => $orderId]);
            if ($codeRow) Codes::useDiscount($codeRow, $user, $orderId);
            if ((int)$p['stock'] > 0) DB::q('UPDATE {p}products SET stock = stock - 1 WHERE id = :id', [':id' => $pid]);
        } catch (Throwable $e) {
            app_log('purchase', 'post-create: ' . $e->getMessage(), ['order' => $orderId]);
        }
        self::setState(null);

        Tg::send($chatId, "🎉 <b>خرید با موفقیت انجام شد</b>\n\n" . '📦 ' . h((string)$p['name']) . "\n", self::kbMain());

        /* کارت مشخصات کامل و لینک‌ها همین‌جا تحویل داده می‌شود */
        try {
            self::deliver($chatId, $service);
        } catch (Throwable $e) {
            app_log('purchase', 'deliver: ' . $e->getMessage(), ['svc' => (int)($service['id'] ?? 0)]);
            Tg::send($chatId,
                "⚠️ ارسال خودکار لینک‌ها انجام نشد.
سرویس ساخته شده و از بخش سرویس‌های من در دسترس است.",
                Tg::ikb([[Tg::btn('📦 سرویس‌های من', 'menu:services')]]));
        }
        AdminBot::notifyAdmins("💵 <b>فروش جدید</b>\nکاربر: <code>" . (int)$user['tg_id'] . "</code>\nمحصول: " . h((string)$p['name']) . "\nمبلغ: " . money($final) . ' ' . currency());

        Logs::send('purchases', Logs::fmt('🛒 خرید جدید', [
            'سفارش'      => '<code>#' . $orderId . '</code>',
            'کاربر'       => '<code>' . (int)$user['tg_id'] . '</code>',
            'محصول'      => h((string)$p['name']),
            'مبلغ پلن'    => money($price) . ' ' . currency(),
            'تخفیف'       => $discount > 0 ? money($discount) . ' ' . currency() . ($dc !== '' ? ' (' . h($dc) . ')' : '') : '—',
            'پرداختی'     => money($final) . ' ' . currency(),
            'مانده کیف پول' => money(Wallet::balance((int)$user['id'])) . ' ' . currency(),
        ]));

        Logs::send('services', Logs::fmt('🚀 سرویس جدید ساخته شد', [
            'کاربر'    => '<code>' . (int)$user['tg_id'] . '</code>',
            'نام کاربری' => '<code>' . h((string)$service['client_email']) . '</code>',
            'پنل'      => h((string)($panel['name'] ?? '')),
            'اینباند'  => fa_num((string)(int)$service['inbound_id']),
            'حجم'      => (float)$service['volume_gb'] > 0 ? fa_num((string)(float)$service['volume_gb']) . ' گیگ' : 'نامحدود',
            'مدت'      => (int)$service['days'] > 0 ? fa_num((string)(int)$service['days']) . ' روز' : 'نامحدود',
        ]));
    }

    /**
     * کارت مشخصات سرویس – متن زیبا و مرتب برای نمایش به کاربر
     */
    public static function specCard(array $service, bool $withTitle = true): string
    {
        $vol = (float)($service['volume_gb'] ?? 0);
        $ipL = (int)($service['ip_limit'] ?? 0);
        $days = (int)($service['days'] ?? 0);
        $used = (int)($service['used_bytes'] ?? 0);
        $left = $vol > 0 ? max(0, round($vol - bytes2gb($used), 2)) : 0;

        $panelName = '';
        if (!empty($service['panel_id'])) {
            $panelName = (string)DB::val('SELECT name FROM {p}panels WHERE id = :id',
                [':id' => (int)$service['panel_id']], '');
        }
        $productName = '';
        if (!empty($service['product_id'])) {
            $productName = (string)DB::val('SELECT name FROM {p}products WHERE id = :id',
                [':id' => (int)$service['product_id']], '');
        }

        $statusMap = ['active' => '✅ فعال', 'expired' => '⏳ منقضی شده', 'disabled' => '⛔️ غیرفعال', 'deleted' => '🗑 حذف شده'];

        $l = [];
        if ($withTitle) {
            $l[] = '✨ <b>مشخصات سرویس شما</b>';
            $l[] = '<code>─────────────────</code>';
        }
        $tags = [];
        $tags[] = $statusMap[(string)($service['status'] ?? 'active')] ?? (string)($service['status'] ?? '');
        if (!empty($service['is_test']))     $tags[] = '🧪 اکانت تست';
        if (!empty($service['is_reseller'])) $tags[] = '🧩 نمایندگی';
        $l[] = implode('  •  ', $tags);
        $l[] = '';
        $l[] = '👤 <b>نام کاربری</b>';
        $l[] = '<code>' . h((string)$service['client_email']) . '</code>';
        if ($productName !== '') $l[] = '🎁 <b>طرح:</b> ' . h($productName);
        if ($panelName !== '')   $l[] = '🖥 <b>سرور:</b> ' . h($panelName);
        $l[] = '';
        if ($vol > 0) {
            $pct = (int)min(100, max(0, round(bytes2gb($used) / $vol * 100)));
            $l[] = '📊 <b>مصرف حجم</b>';
            $l[] = '<code>' . self::gauge($pct) . '</code>  ' . fa_num((string)$pct) . '%';
            $l[] = '���️ مصرف' . chr(8204) . 'شده: ' . fa_num(human_bytes($used)) . ' از ' . fa_num((string)round($vol, 2)) . ' گیگابایت';
            $l[] = '▫️ باقی' . chr(8204) . 'مانده: <b>' . fa_num((string)$left) . ' گیگابایت</b>';
        } else {
            $l[] = '📊 <b>حجم:</b> ♾ نامحدود';
            $l[] = '▫️ مصرف' . chr(8204) . 'شده: ' . fa_num(human_bytes($used));
        }
        $l[] = '';
        $l[] = '⏰ <b>تاریخ انقضا:</b> ' . (!empty($service['expire_at'])
            ? to_jalali((string)$service['expire_at'], true)
            : '♾ نامحدود');
        $l[] = '⏳ <b>زمان باقی' . chr(8204) . 'مانده:</b> ' . (!empty($service['expire_at'])
            ? remaining_human((string)$service['expire_at'])
            : '♾ نامحدود');
        $l[] = '📅 <b>مدت طرح:</b> ' . ($days > 0 ? fa_num((string)$days) . ' روز' : '♾ نامحدود');
        $l[] = '👥 <b>دستگاه همزمان:</b> ' . ($ipL > 0 ? fa_num((string)$ipL) . ' دستگاه' : '♾ بدون محدودیت');

        return implode("\n", $l);
    }

    /**
     * ارسال لینک اشتراک و کانفیگ‌ها به کاربر
     * بدون بارکد QR – فقط لینک ساب و کانفیگ‌ها همراه با کارت مشخصات
     */
    /**
     * پیام تحویل فقط وقتی فرستاده می‌شود که برای آن مسیر روشن باشد.
     * کلیدها: deliver_msg_miniapp و deliver_msg_reseller (پیش‌فرض خاموش)
     */
    public static function deliverCtx(string $ctx, $chatId, array $service): void
    {
        $key = 'deliver_msg_' . (string)preg_replace('/[^a-z]/', '', strtolower($ctx));
        if ((string)DB::setting($key, '0') !== '1') return;
        self::deliver($chatId, $service);
    }

    /** فهرست کانفیگ‌های یک سرویس: ذخیره‌شده یا زنده از پنل */
    public static function cfgList(array $service): array
    {
        $raw = trim((string)($service['config_link'] ?? ''));
        /* سازگاری با ردیف‌های قدیمی که جداکنندهٔ متنی داشتند */
        $raw = str_replace(['\r\n', '\n'], "\n", $raw);

        $list = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: [])));
        if (!$list) {
            /* اگر چیزی ذخیره نشده باشد مستقیم از پنل خوانده می‌شود */
            try { $list = Svc::liveConfigs($service); }
            catch (Throwable $e) { $list = []; }
        }
        return array_values(array_filter(array_map('trim', $list)));
    }

    /** ارسال کانفیگ‌ها در پیام‌های جدا تا محدودیت طول تلگرام رعایت شود */
    public static function sendCfgList($chatId, array $service, array $list): void
    {
        $n = count($list);
        if ($n === 0) return;

        $head = ($n > 1 ? "⚙️ <b>کانفیگ‌های مستقیم</b>\n" : "⚙️ <b>کانفیگ مستقیم</b>\n")
            . '👤 <code>' . h((string)($service['client_email'] ?? '')) . "</code>\n"
            . "<code>─────────────────</code>\n\n";

        $txt = $head;
        foreach ($list as $i => $one) {
            $part = ($n > 1 ? '▫️ <b>کانفیگ ' . fa_num((string)($i + 1)) . ' از ' . fa_num((string)$n) . "</b>\n" : '')
                . '<code>' . h($one) . "</code>\n\n";
            if (mb_strlen($txt . $part) > 3500) { Tg::send($chatId, $txt); $txt = $head; }
            $txt .= $part;
        }
        $txt .= '👆 روی هر کانفیگ بزنید تا کپی شود.';
        Tg::send($chatId, $txt);
    }

    public static function deliver($chatId, array $service): void
    {
        /* تازه‌ترین وضعیت ردیف؛ لینک ساب ممکن است همین ح��لا ترمیم شده باشد */
        $sid = (int)($service['id'] ?? 0);
        if ($sid > 0) {
            try { $fresh = Svc::find($sid); if ($fresh) $service = array_merge($service, $fresh); }
            catch (Throwable $e) { }
        }

        $mode = Svc::deliverMode($service);
        $sub  = Svc::subUrl($service);
        $list = self::cfgList($service);
        $n    = count($list);

        /* طبق تنظیم محصول: ساب، کانفیگ یا هردو — اگر یکی نبود، همان دیگری فرستاده می‌شود */
        /* 0.0.2 #happ-only-bot: محصول «فقط لینک هپ» */
        $happ = Svc::wantsHapp($mode) ? Svc::happLink($service) : '';
        $wantSub = Svc::wantsSub($mode) && $sub !== '';
        $wantCfg = Svc::wantsCfg($mode) && $n > 0;
        if ($happ !== '') { $wantSub = false; $wantCfg = false; }
        if (!$wantSub && !$wantCfg && $happ === '') {
            $wantSub = ($sub !== '');
            $wantCfg = ($n > 0);
        }

        $txt  = "🎉 <b>سرویس شما آماده است</b>\n\n";
        $txt .= self::specCard($service, false) . "\n";

        if ($wantSub) {
            $txt .= "\n<code>────────────────</code>\n";
            $txt .= $wantCfg
                ? "🔗 <b>لینک اشتراک (پیشنهادی)</b>\n"
                : "🔗 <b>لینک اشتراک</b>\n";
            $txt .= "<i>همهٔ کانفیگ‌ها با یک لینک و به‌روزرسانی خودکار</i>\n";
            $txt .= '<code>' . h($sub) . "</code>\n";
        }

        if ($happ !== '') { /* 0.0.2 #happ-only-txt */
            $txt .= "\n<code>────────────────</code>\n";
            $txt .= "⚡ <b>لینک اختصاصی Happ</b>\n";
            $txt .= "<i>تنظیمات سرور (محدودیت دستگاه، مسیریابی و…) روی همین لینک اعمال می‌شود.</i>\n";
            $txt .= '<code>' . h($happ) . "</code>\n";
        }

        if ($wantCfg && !$wantSub) {
            $txt .= "\n<code>────────────────</code>\n";
            $txt .= "⚙️ <b>کانفیگ‌های مستقیم در پیام بعدی ارسال می‌شود.</b>\n";
        }

        if (!$wantSub && !$wantCfg) {
            $txt .= "\n⚠️ <b>لینک اتصال ثبت نشده است.</b>\nلطفاً با پشتیبانی تماس بگیرید.\n";
        }

        $txt .= "\n<code>────────────────</code>\n";
        $txt .= "💡 <b>راهنمای سریع</b>\n";
        $txt .= ($wantSub || $happ !== '') /* 0.0.2 #happ-only-tip */
            ? "۱) روی لینک بالا بزنید تا کپی شود.\n"
            : "۱) روی کانفیگ بزنید تا کپی شود.\n";
        $txt .= "۲) وارد برنامه شوید و گزینهٔ «افزودن از کلیپ‌بورد» را بزنید.\n";
        $txt .= "۳) به سرور متصل شوید. 🚀";

        $rows = [];
        if ($sid > 0) {
            $br = [];
            if ($wantSub) $br[] = Tg::btn('🔗 لینک اشتراک', 'svcsub:' . $sid);
            if ($wantCfg) $br[] = Tg::btn('⚙️ کانفیگ‌ها', 'svccfg:' . $sid);
            if ($happ !== '') $br[] = Tg::btn('⚡ افزودن به Happ', 'svchapp:' . $sid); /* 0.0.2 #happ-only-btn */
            if ($br) $rows[] = $br;
        }
        $rows[] = [Tg::btn('📦 سرویس‌های من', 'menu:services'), Tg::btn('🎓 راهنمای اتصال', 'tut:menu')];
        $rows[] = [Tg::btn('🆘 پشتیبانی', 'tk:new')];
        Tg::send($chatId, $txt, Tg::ikb($rows));

        /* کانفیگ‌های مستقیم در پیام جدا تا کارت مشخصات بریده نشود */
        if ($wantCfg) self::sendCfgList($chatId, $service, $list);
    }

    /* ================= سرویس‌های من ================= */

    private static function sectionServices($chatId, $msgId = null): void
    {
        $list = Svc::forUser((int)self::$u['id']);
        if (!$list) {
            $txt = "📦 شما هنوز ��رویسی ندارید.\nاز بخش محصولات یک سرویس تهیه کنید.";
            $kb = Tg::ikb([[Tg::btn('🛒 محصولات', 'menu:products')]]);
            $msgId ? Tg::edit($chatId, $msgId, $txt, $kb) : Tg::send($chatId, $txt, $kb);
            return;
        }
        $rows = [];
        foreach ($list as $s) {
            $icon = $s['status'] === 'active' ? '✅' : ($s['status'] === 'expired' ? '⏳' : '⛔️');
            $left = (float)$s['volume_gb'] > 0
                ? fa_num((string)max(0, round((float)$s['volume_gb'] - bytes2gb((int)$s['used_bytes']), 1))) . ' گیگ'
                : 'نامحدود';
            $rows[] = [Tg::btn($icon . ' ' . $s['client_email'] . ' | ' . $left . ' | ' . remaining_human($s['expire_at']), 'svc:' . $s['id'])];
        }
        /* fixed83: پاک‌سازی گروهی کانفیگ‌های قطع‌شده */
        $deadN = 0;
        foreach ($list as $d) if (Svc::isDead($d)) $deadN++;
        if ($deadN > 0 && Svc::deadDelEnabled()) {
            $rows[] = [Tg::btn('🧹 حذف کانفیگ‌های قطع (' . fa_num((string)$deadN) . ')', 'svcpurge:0')];
        }
        $rows[] = Kb::backRow();
        $txt = '📦 <b>سرویس‌های من</b> (' . fa_num(count($list)) . ")\nبرای دیدن جزئیات انتخاب کنید:";
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    private static function myService(int $id): ?array
    {
        return DB::one('SELECT * FROM {p}services WHERE id = :id AND user_id = :u AND status <> :d
            AND COALESCE(is_reseller, 0) = 0',
            [':id' => $id, ':u' => (int)self::$u['id'], ':d' => 'deleted']);
    }

    private static function showService($chatId, $msgId, int $id): void
    {
        $s = self::myService($id);
        if (!$s) { Tg::send($chatId, '⚠️ سرویس یافت نشد.'); return; }
        $txt = "📦 <b>جزئیات سرویس</b>\n" . '<code>─────────────────</code>' . "\n" . self::specCard($s, false);
        /* فقط مسیری که برای این محصول فعال است نشان داده می‌شود */
        $mode = Svc::deliverMode($s);
        /* 0.0.2 #happ-only-view: محصول «فقط لینک هپ» */
        $hOnly = Svc::wantsHapp($mode) && class_exists('Links') && Links::supported($s);
        $top  = [];
        if ($hOnly) $top[] = Tg::btn('⚡ افزودن به Happ', 'svchapp:' . $id);
        if (Svc::wantsSub($mode)) $top[] = Tg::btn('🔗 لینک اشتراک', 'svcsub:' . $id);
        if (Svc::wantsCfg($mode)) $top[] = Tg::btn('⚙️ کانفیگ‌ها', 'svccfg:' . $id);
        if (!$top) $top[] = Tg::btn('🔗 لینک اشتراک', 'svcsub:' . $id);

        $rows = [
            $top,
            [Tg::btn('📋 مشخصات کامل', 'svcspec:' . $id), Tg::btn('🔄 به‌روزرسانی مصرف', 'svcsync:' . $id)],
            [Tg::btn('♻️ تمدید سرویس', 'svcrn:' . $id)],
        ];
        /* 0.0.2 #happ-btn: لینک اختصاصی Happ — فقط پنل نسل جدید سنایی */
        if (!$hOnly && class_exists('Links') && Links::supported($s)) {
            $rows[] = [Tg::btn('⚡ افزودن به Happ', 'svchapp:' . $id)];
        }
        /* 0.0.2 #dev-btn: مدیریت دستگاه‌های ثبت‌شده (HWID) — فقط پنل نسل جدید سنایی */
        if (class_exists('Devices') && Devices::supported($s)) {
            $rows[] = [Tg::btn('📱 دستگاه‌های من', 'svcdev:' . $id)];
        }
        /* fixed83: حذف سرویس و عودت وجه — کانفیگ قطع‌شده اجازهٔ جداگانه دارد */
        $dead = Svc::isDead($s);
        if (Svc::userDelEnabled() || ($dead && Svc::deadDelEnabled())) {
            $rows[] = [Tg::btn($dead ? '🗑 حذف کانفیگ قطع‌شده و عودت وجه' : '🗑 حذف سرویس و عودت وجه', 'svcdel:' . $id)];
        }
        $rows[] = [Tg::btn('⬅️ سرویس‌های من', 'menu:services')];
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    /* ============ 0.0.2: دستگاه‌های ثبت‌شده (HWID) — پنل 3x-ui ============ */

    /* ============ 0.0.2 #happ-links: لینک Happ و لینک‌های خارجی ============ */

    private static function happView($chatId, $msgId, int $id): void
    {
        $s = self::myService($id);
        if (!$s) { Tg::send($chatId, '⚠️ سرویس یافت نشد.'); return; }
        if (!class_exists('Links') || !Links::supported($s)) {
            Tg::send($chatId, 'ℹ️ این سرویس لینک Happ ندارد.');
            return;
        }

        $happ = Links::happ($s);
        $ext  = Links::external($s);

        $txt = "⚡ <b>افزودن به Happ</b>\n"
            . '<code>─────────────────</code>' . "\n";
        if ($happ !== '') {
            $txt .= "لینک زیر را کپی کنید و در اپلیکیشن Happ بزنید روی «+» ← Import from clipboard:\n\n"
                . '<code>' . h($happ) . '</code>' . "\n";
        } else {
            $txt .= "لینک Happ برای این سرویس در دسترس نیست.\n";
        }
        if ($ext) {
            $txt .= "\n🔗 <b>لینک‌های دیگر</b>\n";
            $n = 0;
            foreach ($ext as $e) {
                $link = trim((string)($e['link'] ?? ''));
                if ($link === '') continue;
                $title = trim((string)($e['title'] ?? ''));
                $txt .= '• ' . ($title !== '' ? h($title) . ': ' : '') . '<code>' . h($link) . '</code>' . "\n";
                if (++$n >= 5) break;
            }
        }

        $rows = [
            [Tg::btn('🔄 به‌روزرسانی', 'svchapp:' . $id)],
            [Tg::btn('⬅️ بازگشت', 'svc:' . $id)],
        ];
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    private static function devicesView($chatId, $msgId, int $id): void
    {
        $s = self::myService($id);
        if (!$s) { Tg::send($chatId, '⚠️ سرویس یافت نشد.'); return; }
        if (!class_exists('Devices') || !Devices::supported($s)) {
            Tg::send($chatId, 'ℹ️ این سرویس از مدیریت دستگاه پشتیبانی نمی‌کند.');
            return;
        }

        $items = Devices::listFor($s);
        $limit = Devices::limitOf($s);

        $txt = "📱 <b>دستگاه‌های من</b>
"
            . '<code>─────────────────</code>' . "
"
            . '👤 <code>' . h((string)$s['client_email']) . "</code>
"
            . '🔢 ثبت‌شده: ' . fa_num((string)count($items))
            . ($limit > 0 ? (' از ' . fa_num((string)$limit)) : ' (بدون محدودیت)') . "
";

        $rows = [];
        if (!$items) {
            $txt .= "
هنوز دستگاهی ثبت نشده است.";
        } else {
            $txt .= "
برای آزاد کردن ظرفیت، روی دستگاه بزنید:";
            foreach ($items as $d) {
                $label = mb_substr((string)$d['title'], 0, 26);
                $rows[] = [Tg::btn('🗑 ' . $label . ' | ' . (string)$d['seen_txt'],
                    'svcdevdel:' . $id . ':' . (int)$d['id'])];
            }
            $rows[] = [Tg::btn('🧹 حذف همهٔ دستگاه‌ها', 'svcdevclr:' . $id)];
        }
        $rows[] = [Tg::btn('🔄 به‌روزرسانی', 'svcdev:' . $id)];
        $rows[] = [Tg::btn('⬅️ بازگشت', 'svc:' . $id)];

        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    private static function deviceDel($chatId, $msgId, $cbId, int $id, int $dev): void
    {
        $s = self::myService($id);
        if (!$s) { Tg::answerCb($cbId, 'سرویس یافت نشد.', true); return; }
        if (!class_exists('Devices') || !Devices::supported($s) || $dev <= 0) {
            Tg::answerCb($cbId, 'امکان حذف نیست.', true);
            return;
        }
        $ok = Devices::remove($s, $dev);
        Tg::answerCb($cbId, $ok ? '✅ دستگاه حذف شد.' : '❌ حذف انجام نشد.', !$ok);
        self::devicesView($chatId, $msgId, $id);
    }

    private static function deviceClear($chatId, $msgId, $cbId, int $id): void
    {
        $s = self::myService($id);
        if (!$s) { Tg::answerCb($cbId, 'سرویس یافت نشد.', true); return; }
        if (!class_exists('Devices') || !Devices::supported($s)) {
            Tg::answerCb($cbId, 'امکان حذف نیست.', true);
            return;
        }
        $n = Devices::clear($s);
        Tg::answerCb($cbId, $n > 0
            ? ('✅ ' . fa_num((string)$n) . ' دستگاه حذف شد.')
            : 'دستگاهی برای حذف نبود.');
        self::devicesView($chatId, $msgId, $id);
    }

    private static function sendSub($chatId, $cbId, int $id): void
    {
        /* 0.0.2 #happ-only-guard: محصول «فقط لینک هپ» لینک ساب یا کانفیگ مستقیم نمی\u200cدهد */
        $gS = self::myService($id);
        if ($gS && Svc::wantsHapp(Svc::deliverMode($gS)) && Svc::happLink($gS) !== '') {
            Tg::answerCb($cbId);
            self::happView($chatId, null, $id);
            return;
        }
        $s = self::myService($id);
        if (!$s) { Tg::answerCb($cbId, 'سرویس یافت نشد.', true); return; }
        Tg::answerCb($cbId);

        $sub = Svc::subUrl($s);
        if ($sub === '') {
            /* اگر لینک اشتراک ثبت نشده، کانفیگ‌های مستقیم فرستاده می‌شود */
            $list = self::cfgList($s);
            if ($list) { self::sendCfgList($chatId, $s, $list); return; }
            Tg::send($chatId, '⚠️ برای این سرویس لینک اشتراک تنظیم نشده است.');
            return;
        }

        Tg::send($chatId,
            "🔗 <b>لینک اشتراک شما</b>\n"
            . '👤 <code>' . h((string)$s['client_email']) . "</code>\n"
            . "<code>─────────────────</code>\n"
            . '<code>' . h($sub) . "</code>\n\n"
            . '👆 روی لینک بزنید تا کپی شود، سپس در برنامه «افزودن از کلیپ‌بورد» را بزنید.',
            Tg::ikb([
                [Tg::btn('⚙️ کانفیگ‌ها', 'svccfg:' . $id)],
                [Tg::btn('⬅️ بازگشت', 'svc:' . $id)],
            ]));
    }

    private static function sendConfig($chatId, $cbId, int $id): void
    {
        /* 0.0.2 #happ-only-guard2: محصول «فقط لینک هپ» لینک ساب یا کانفیگ مستقیم نمی\u200cدهد */
        $gS = self::myService($id);
        if ($gS && Svc::wantsHapp(Svc::deliverMode($gS)) && Svc::happLink($gS) !== '') {
            Tg::answerCb($cbId);
            self::happView($chatId, null, $id);
            return;
        }
        $s = self::myService($id);
        if (!$s) { Tg::answerCb($cbId, 'سرویس یافت نشد.', true); return; }
        Tg::answerCb($cbId);

        $list = self::cfgList($s);
        if (!$list) {
            Tg::send($chatId, '⚠️ کانفیگ مستقیم در دسترس نیست؛ از لینک اشتراک استفاده کنید.',
                Tg::ikb([[Tg::btn('🔗 لینک اشتراک', 'svcsub:' . $id)]]));
            return;
        }
        self::sendCfgList($chatId, $s, $list);
    }

    /** ارسال کار�� مشخصات کامل سرویس */
    private static function sendSpec($chatId, $cbId, int $id): void
    {
        $s = self::myService($id);
        if (!$s) { Tg::answerCb($cbId, 'سرویس یافت نشد.', true); return; }
        Tg::answerCb($cbId);
        Tg::send($chatId, self::specCard($s, true), Tg::ikb([
            [Tg::btn('🔗 دریافت لینک اشتراک', 'svcsub:' . $id)],
            [Tg::btn('⬅️ بازگشت', 'svc:' . $id)],
        ]));
    }

    private static function syncService($chatId, $msgId, $cbId, int $id): void
    {
        $s = self::myService($id);
        if (!$s) { Tg::answerCb($cbId, 'سرویس یافت نشد.', true); return; }
        Tg::answerCb($cbId, 'در حال به‌روزرسانی...');
        Svc::sync($s);
        self::showService($chatId, $msgId, $id);
    }

    /* ================= حذف سرویس / کانفیگ قطع‌شده و عودت وجه ================= */

    private static function delOptions($chatId, $msgId, int $id): void
    {
        $s = self::myService($id);
        if (!$s) { Tg::send($chatId, '⚠️ سرویس یافت نشد.'); return; }

        $dead = Svc::isDead($s);
        if (!Svc::userDelEnabled() && !($dead && Svc::deadDelEnabled())) {
            Tg::send($chatId, '⚠️ حذف سرویس توسط مدیر غیرفعال شده است.',
                Tg::ikb([[Tg::btn('⬅️ بازگشت', 'svc:' . $id)]]));
            return;
        }

        $q   = Svc::deleteQuote($s);
        $cur = currency();
        $txt = "🗑 <b>حذف سرویس و عودت وجه</b>\n" . '<code>─────────────────</code>' . "\n"
            . '👤 نام کاربری: <code>' . h((string)$s['client_email']) . "</code>\n"
            . '⚙️ وضعیت: ' . ($dead ? Svc::deadLabel($s) : '✅ فعال') . "\n"
            . '📈 حجم مصرف‌نشده: ' . ((float)$s['volume_gb'] > 0 ? Svc::volLabel((float)$q['left_gb']) : 'نامحدود') . "\n"
            . '⏱ زمان باقی‌مانده: ' . fa_num((string)(int)$q['left_days']) . " روز\n"
            . '💳 پرداختی این سرویس: ' . money((int)$q['pool']) . ' ' . $cur . "\n";
        if ((int)$q['fee'] > 0) $txt .= '➖ کارمزد حذف: ' . money((int)$q['fee']) . ' ' . $cur . "\n";
        $txt .= '💰 مبلغ عودتی به کیف پول: <b>' . money((int)$q['refund']) . ' ' . $cur . "</b>\n";
        if (trim((string)$q['note']) !== '') $txt .= 'ℹ️ ' . h((string)$q['note']) . "\n";
        $txt .= "\n⚠️ با حذف، اتصال این کانفیگ از سرور پاک می‌شود و بازگشتی ندارد.";

        $rows = [
            [Tg::btn('🗑 تایید حذف' . ((int)$q['refund'] > 0 ? ' و دریافت ' . money((int)$q['refund']) . ' ' . $cur : ''), 'svcdelok:' . $id)],
            [Tg::btn('⬅️ بازگشت', 'svc:' . $id)],
        ];
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    private static function doDelete($chatId, $msgId, $cbId, int $id): void
    {
        $s = self::myService($id);
        if (!$s) { Tg::answerCb($cbId, 'سرویس یافت نشد.', true); return; }

        Tg::answerCb($cbId, 'در حال حذف...');
        $user = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)self::$u['id']]) ?: self::$u;
        try {
            $r = Svc::userDelete($user, $id);
        } catch (Throwable $e) {
            app_log('bot', 'svcdel: ' . $e->getMessage(), ['svc' => $id]);
            Tg::send($chatId, '❌ حذف انجام نشد؛ دوباره تلاش کنید.');
            return;
        }
        if (empty($r['ok'])) {
            Tg::send($chatId, '❌ ' . (string)($r['message'] ?? 'حذف انجام نشد.'),
                Tg::ikb([[Tg::btn('📦 سرویس‌های من', 'menu:services')]]));
            return;
        }
        Tg::send($chatId, (string)$r['message'], Tg::ikb([[Tg::btn('📦 سرویس‌های من', 'menu:services')]]));
    }

    private static function purgeDeadView($chatId, $msgId): void
    {
        if (!Svc::deadDelEnabled()) {
            Tg::send($chatId, '⚠️ حذف کانفیگ‌های قطع توسط مدیر غیرفعال شده است.',
                Tg::ikb([[Tg::btn('📦 سرویس‌های من', 'menu:services')]]));
            return;
        }

        $dead = Svc::deadForUser((int)self::$u['id']);
        if (!$dead) {
            $txt = "✨ کانفیگ قطع‌شده‌ای ندارید.\nهمهٔ سرویس‌های شما فعال هستند.";
            $kb  = Tg::ikb([[Tg::btn('📦 سرویس‌های من', 'menu:services')]]);
            $msgId ? Tg::edit($chatId, $msgId, $txt, $kb) : Tg::send($chatId, $txt, $kb);
            return;
        }

        $cur = currency();
        $sum = 0;
        $lines = [];
        foreach ($dead as $s) {
            $q = Svc::deleteQuote($s);
            $sum += (int)$q['refund'];
            $lines[] = '• <code>' . h((string)$s['client_email']) . '</code> — ' . Svc::deadLabel($s)
                . ((int)$q['refund'] > 0 ? ' — 💰 ' . money((int)$q['refund']) . ' ' . $cur : ' — بدون عودت');
        }

        $txt = "🧹 <b>حذف کانفیگ‌های قطع</b>\n" . '<code>─────────────────</code>' . "\n"
            . '🔢 تعداد: ' . fa_num((string)count($dead)) . "\n\n"
            . implode("\n", $lines)
            . "\n\n" . '💰 جمع مبلغ عودتی: <b>' . money($sum) . ' ' . $cur . '</b>'
            . "\n⚠️ این کانفیگ‌ها از سرور پاک می‌شوند و بازگشتی ندارند.";

        $rows = [
            [Tg::btn('🧹 تایید حذف ' . fa_num((string)count($dead)) . ' کانفیگ', 'svcpurgeok:0')],
            [Tg::btn('⬅️ سرویس‌های من', 'menu:services')],
        ];
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    private static function doPurgeDead($chatId, $msgId, $cbId): void
    {
        Tg::answerCb($cbId, 'در حال حذف...');
        $user = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)self::$u['id']]) ?: self::$u;
        try {
            $r = Svc::purgeDeadForUser($user);
        } catch (Throwable $e) {
            app_log('bot', 'svcpurge: ' . $e->getMessage());
            Tg::send($chatId, '❌ حذف انجام نشد؛ دوباره تلاش کنید.');
            return;
        }
        Tg::send($chatId, (empty($r['ok']) ? '⚠️ ' : '') . (string)($r['message'] ?? ''),
            Tg::ikb([[Tg::btn('📦 سرویس‌های من', 'menu:services')]]));
    }

    private static function renewOptions($chatId, $msgId, int $id): void
    {
        $s = self::myService($id);
        if (!$s) { Tg::send($chatId, '❌ سرویس یافت نشد.'); return; }
        $panel = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => (int)$s['panel_id']]);
        $mode  = Svc::RENEW_MODES[(string)($panel['renew_mode'] ?? 'reset_extend')] ?? '';
        $prods = DB::all('SELECT * FROM {p}products WHERE active = 1 AND stock <> 0 AND panel_id = :p ORDER BY sort ASC, price ASC',
            [':p' => (int)$s['panel_id']]);
        if (!$prods) { Tg::send($chatId, '⚠️ طرحی برای تمدید این سرویس موجود نیست.'); return; }
        $rows = [];
        foreach ($prods as $p) {
            if (self::isCusProduct($p)) continue; /* fixed76: محصول دلخواه طرح تمدید نیست */
            $rows[] = [Tg::btn(self::productLabel($p), 'svcrnok:' . $id . ':' . $p['id'])];
        }
        if (!$rows) { Tg::send($chatId, '⚠️ طرحی برای تمدید این سرویس موجود نیست.'); return; }
        $rows[] = [Tg::btn('⬅️ بازگشت', 'svc:' . $id)];
        $txt = "♻️ <b>تمدید سرویس</b>\nروش تمدید این سرور: <b>" . h($mode) . "</b>\nحالت فعلی: " . h((string)$s['client_email']) . "\n\nطرح تمدید را انتخاب کنید:";
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    private static function doRenew($chatId, $msgId, $cbId, int $sid, int $pid): void
    {
        $s = self::myService($sid);
        $p = DB::one('SELECT * FROM {p}products WHERE id = :id AND active = 1', [':id' => $pid]);
        if (!$s || !$p) { Tg::answerCb($cbId, 'اطلاعات نامعتبر است.', true); return; }
        if (self::isCusProduct($p)) { Tg::answerCb($cbId, 'این طرح برای تمدید قابل استفاده نیست.', true); return; }
        $price = (int)$p['price'];
        /* fixed74: کمپین روی تمدید (اگر فعال و شامل تمدید باشد) */
        $rb = class_exists('Campaign') ? Campaign::best($price, $pid, 0, 'renew') : ['final' => $price, 'discount' => 0, 'source' => ''];
        $pay = (int)$rb['final'];
        $user  = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)self::$u['id']]);
        if ((int)$user['balance'] < $pay) {
            Tg::answerCb($cbId, 'موجودی کافی نیست.', true);
            Tg::send($chatId, '👛 موجودی کیف پول کافی نیست.', Tg::ikb([[Tg::btn('💳 شارژ کیف پول', 'menu:wallet')], Kb::navRow('menu:services')]));
            return;
        }
        Tg::answerCb($cbId, 'در حال تمدید...');
        if (!Wallet::debit((int)$user['id'], $pay, 'تمدید: ' . $p['name'])) {
            Tg::send($chatId, '❌ کسر از کیف پول انجام نشد.');
            return;
        }
        $orderId = DB::insert('orders', [
            'user_id' => (int)$user['id'], 'tg_id' => (int)$user['tg_id'], 'product_id' => $pid, 'service_id' => $sid,
            'type' => 'renew', 'amount' => $price, 'discount_code' => $rb['source'] === 'camp' ? 'CAMPAIGN' : null,
            'discount_amount' => (int)$rb['discount'], 'final_amount' => $pay,
            'status' => 'pending', 'created_at' => now(),
        ]);
        $r = Svc::renew($s, $p);
        if (!$r['ok']) {
            Wallet::credit((int)$user['id'], $pay, 'refund', 'wallet', 'تمدید ناموفق #' . $orderId);
            DB::update('orders', ['status' => 'failed'], 'id = :id', [':id' => $orderId]);
            Tg::send($chatId, '❌ ' . $r['message'] . "\n💰 مبلغ بازگردانده شد.");
            return;
        }
        DB::update('orders', ['status' => 'paid'], 'id = :id', [':id' => $orderId]);
        $svc = $r['service'];
        Tg::send($chatId, "✅ <b>تمدید انجام شد</b>\n\n" . Svc::summary($svc));
        if (!empty($r['recreated'])) self::deliver($chatId, $svc);
        AdminBot::notifyAdmins("♻️ <b>تمدید سرویس</b>\nکاربر: <code>" . (int)$user['tg_id'] . "</code>\nطرح: " . h((string)$p['name']));
        Logs::send('services', Logs::fmt('♻️ تمدید سرویس', [
            'سفارش'      => '<code>#' . $orderId . '</code>',
            'کاربر'       => '<code>' . (int)$user['tg_id'] . '</code>',
            'نام کاربری'   => '<code>' . h((string)$svc['client_email']) . '</code>',
            'طرح'         => h((string)$p['name']),
            'مبلغ'        => money($price) . ' ' . currency(),
            'انقضای جدید' => $svc['expire_at'] ? to_jalali((string)$svc['expire_at']) : 'نامحدود',
        ]));
    }

    /* ================= اکانت تست ================= */

    private static function sectionTest($chatId, int $panelId = 0, $msgId = null): void
    {
        if (!self::fjGate($chatId, 'buy')) return;
        /* تایید حساب پیش از دریافت اکانت تست */
        if (class_exists('Security')) {
            $gu = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)self::$u['id']]) ?: [];
            if ($gu && Security::needs($gu, 'test')) {
                Tg::send($chatId, Security::blockMessage());
                self::verifyMenu($chatId);
                return;
            }
        }

        $panels = DB::all('SELECT * FROM {p}panels WHERE active = 1 AND test_enabled = 1 ORDER BY sort ASC, id ASC');
        if (!$panels) { Tg::send($chatId, '⚠️ اکانت تست فعلاً فعال نیست.'); return; }
        $user = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)self::$u['id']]);
        /* fixed77: انتخاب سرور اکانت تست توسط کاربر (وقتی بیش از یک سرور تست فعال است) */
        $panel = null;
        if ($panelId > 0) {
            foreach ($panels as $pp) if ((int)$pp['id'] === $panelId) { $panel = $pp; break; }
            if (!$panel) { Tg::send($chatId, '⚠️ این سرور دیگر برای اکانت تست فعال نیست.', self::kbMain()); return; }
        } elseif (count($panels) > 1) {
            self::testPanelMenu($chatId, $panels, $user, $msgId);
            return;
        } else {
            $panel = $panels[0];
        }
        if ($msgId) {
            try { Tg::edit($chatId, $msgId, '🧪 در حال ساخت اکانت تست روی <b>' . h((string)($panel['name'] ?? '')) . '</b> …'); } catch (Throwable $e) { }
        }
        $r = Svc::createTest($user, $panel);
        if (!$r['ok']) { Tg::send($chatId, '❌ ' . $r['message'], self::kbMain()); return; }
        $s = $r['service'];
        Tg::send($chatId, "🧪 <b>اکانت تست ساخته شد</b>\n\n" . Svc::summary($s), self::kbMain());
        self::deliver($chatId, $s);
        Logs::send('test', Logs::fmt('🔑 اکانت تست جدید', [
            'کاربر'      => '<code>' . (int)$user['tg_id'] . '</code>',
            'نام کاربری'  => '<code>' . h((string)$s['client_email']) . '</code>',
            'پنل'        => h((string)($panel['name'] ?? '')),
            'حجم'        => fa_num((string)(float)$s['volume_gb']) . ' گیگ',
            'انقضا'      => $s['expire_at'] ? to_jalali((string)$s['expire_at'], true) : '—',
            'تعداد تست کاربر' => fa_num((string)((int)$user['test_count'] + 1)),
        ]));
    }

    /* ================= حساب کاربری ================= */

    /** fixed77: فهرست سرورهایی که اکانت تست‌شان فعال است تا کاربر یکی را انتخاب کند */
    private static function testPanelMenu($chatId, array $panels, array $user, $msgId = null): void
    {
        $kb = []; $ln = [];
        foreach ($panels as $p) {
            $nm = trim((string)($p['name'] ?? ''));
            if ($nm === '') $nm = 'سرور ' . fa_num((int)$p['id']);
            $spec = [];
            if (($tvl = Svc::testVolumeLabel($p)) !== '') $spec[] = $tvl; /* fixed79: مگابایت/گیگ */
            $d = (int)($p['test_days'] ?? 0); $hh = (int)($p['test_hours'] ?? 0);
            if ($d > 0)  $spec[] = fa_num($d) . ' روز';
            if ($hh > 0) $spec[] = fa_num($hh) . ' ساعت';
            $limit = (int)($p['test_limit'] ?? 1);
            $used  = (int)DB::val('SELECT COUNT(*) FROM {p}services WHERE user_id = :u AND is_test = 1 AND panel_id = :p',
                [':u' => (int)$user['id'], ':p' => (int)$p['id']], 0);
            $left  = $limit < 0 ? null : max(0, $limit - $used);
            $lbl   = self::panelFlag($nm) . ' ' . $nm . ($spec ? ' — ' . implode(' / ', $spec) : '');
            if ($left === 0) $lbl = '⛔️ ' . $lbl;
            $kb[]  = [Tg::btn(mb_substr($lbl, 0, 60), 'tst:' . (int)$p['id'])];
            $ln[]  = '▫️ <b>' . h($nm) . '</b>' . ($spec ? ' — ' . implode(' • ', $spec) : '')
                   . ($left === null ? '' : ' • سهمیهٔ باقی‌مانده: ' . fa_num($left));
        }
        $kb[] = [Tg::btn('🔙 بازگشت', 'menu:main')];
        $txt = "🧪 <b>اکانت تست</b>\n\nسروری را که می‌خواهید اکانت تست آن را دریافت کنید انتخاب کنید:\n\n" . implode("\n", $ln);
        if ($msgId) Tg::edit($chatId, $msgId, $txt, Tg::ikb($kb));
        else Tg::send($chatId, $txt, Tg::ikb($kb));
    }

    private static function sectionAccount($chatId, $msgId = null): void
    {
        $u = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)self::$u['id']]);
        $services = (int)DB::val('SELECT COUNT(*) FROM {p}services WHERE user_id = :u AND status <> :d',
            [':u' => (int)$u['id'], ':d' => 'deleted'], 0);
        $active = (int)DB::val("SELECT COUNT(*) FROM {p}services WHERE user_id = :u AND status = 'active'", [':u' => (int)$u['id']], 0);
        $orders = (int)DB::val("SELECT COUNT(*) FROM {p}orders WHERE user_id = :u AND status = 'paid'", [':u' => (int)$u['id']], 0);
        $botUser = (string)cfg('bot.username', '');
        $ref = $botUser !== '' ? 'https://t.me/' . $botUser . '?start=ref' . (int)$u['tg_id'] : '-';

        $lines = [
            '👤 <b>حساب کاربری</b>',
            '',
            'نام: ' . h(trim(((string)$u['first_name']) . ' ' . ((string)$u['last_name'])) ?: '-'),
            'آیدی عددی: <code>' . (int)$u['tg_id'] . '</code>',
            'یوزرنیم: ' . ($u['username'] ? '@' . h((string)$u['username']) : '-'),
            'شماره تماس: ' . ($u['phone'] ? fa_num((string)$u['phone']) : 'ثبت نشده'),
            'ایمیل: ' . ($u['email'] ? h((string)$u['email']) : 'ثبت نشده'),
            'وضعیت تایید: ' . ((class_exists('Security') && Security::isVerified($u)) ? '✅ تایید شده' : '⚠️ تایید نشده'),
            '',
            '👛 موجودی کیف پول: <b>' . money((int)$u['balance']) . ' ' . currency() . '</b>',
            '💳 جمع پرداخت‌ها: ' . money((int)$u['total_paid']) . ' ' . currency(),
            '📦 سرویس‌ها: ' . fa_num($services) . ' (فعال: ' . fa_num($active) . ')',
            '🧾 خریدهای موفق: ' . fa_num($orders),
            '📅 تاریخ عضویت: ' . to_jalali((string)$u['created_at']),
            '',
            '🔗 لینک معرفی شما:',
            '<code>' . h($ref) . '</code>',
        ];
        $rows = [
            [Tg::btn('📱 ثبت شماره تماس', 'acc:phone'), Tg::btn('✉️ ثبت ایمیل', 'acc:email')],
            [Tg::btn('✏️ ویرایش نام', 'acc:name'), Tg::btn('💳 شارژ کیف پول', 'menu:wallet')],
            [Tg::btn('📜 تاریخچه تراکنش‌ها', 'wal:hist')],
        ];
        /* fixed74: تمدید خودکار از کیف پول (انتخاب کاربر) */
        if (class_exists('AutoRenew') && AutoRenew::enabled()) {
            $arnOn = AutoRenew::userOn($u);
            $lines[] = '';
            $lines[] = '🔁 تمدید خودکار از کیف پول: ' . ($arnOn ? '<b>✅ روشن</b>' : '❌ خاموش')
                     . ($arnOn ? '' : "\n<i>با روشن کردن، سرویس‌ها پیش از انقضا به‌صورت خودکار از موجودی کیف پول تمدید می‌شوند.</i>");
            $rows[] = [Tg::btn($arnOn ? '🔁 تمدید خودکار: ✅ روشن (خاموش کن)' : '🔁 تمدید خودکار: ❌ خاموش (روشن کن)', 'arn:toggle')];
        }
        if (class_exists('Security') && Security::enabled() && !Security::isVerified($u)) {
            array_unshift($rows, [Tg::btn('🔐 تایید حساب کاربری', 'ver:menu')]);
        }
        if ($mb = self::miniappSectionBtn()) array_unshift($rows, [$mb]);
        $rows[] = Kb::backRow('menu:main');
        $txt = implode("\n", $lines);
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    /* ================= کیف پول ================= */

    private static function sectionWallet($chatId, $msgId = null): void
    {
        $u   = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)self::$u['id']]);
        $uid = (int)self::$u['id'];

        $inTotal = (int)DB::val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions
            WHERE user_id = :u AND status = 'approved' AND amount > 0", [':u' => $uid], 0);
        $outTotal = (int)DB::val("SELECT COALESCE(SUM(ABS(amount)),0) FROM {p}transactions
            WHERE user_id = :u AND status = 'approved' AND amount < 0", [':u' => $uid], 0);
        $pendCnt = (int)DB::val("SELECT COUNT(*) FROM {p}transactions
            WHERE user_id = :u AND status = 'pending'", [':u' => $uid], 0);
        $last = DB::one("SELECT * FROM {p}transactions WHERE user_id = :u ORDER BY id DESC LIMIT 1", [':u' => $uid]);

        $l = [];
        $l[] = '👛 <b>کیف پول شما</b>';
        $l[] = '<code>─────────────────</code>';
        $l[] = '💰 <b>موجودی قابل استفاده</b>';
        $l[] = '▪️ <b>' . money((int)$u['balance']) . ' ' . currency() . '</b>';
        $l[] = '';
        $l[] = '📈 مجموع شارژ: ' . money($inTotal) . ' ' . currency();
        $l[] = '📉 مجموع خرید: ' . money($outTotal) . ' ' . currency();
        if ($pendCnt > 0) $l[] = '⏳ در انتظار تایید: ' . fa_num($pendCnt) . ' تراکنش';
        if ($last) {
            $l[] = '🕒 آخرین تراکنش: ' . to_jalali((string)$last['created_at']);
        }
        $l[] = '<code>─────────────────</code>';
        $l[] = '👇 روش شارژ کیف پول را انتخاب کنید:';

        $npRs = false;
        try { $npRs = class_exists('Reseller') && Reseller::isReseller(self::$u); } catch (Throwable $e) { $npRs = false; }

        /* وضعیت واقعی روش‌ها: تنظیم سراسری + وضعیت درگاه‌ها در «درگاه‌های پرداخت» */
        $cardOn = self::payMethodOn('card', $npRs);
        $cryOn  = self::payMethodOn('crypto', $npRs);
        $npOn = (class_exists('Gateway') && method_exists('Gateway', 'nowpayOn'))
            ? Gateway::nowpayOn($npRs) : NowPay::enabled();
        $hpOn = (class_exists('Gateway') && method_exists('Gateway', 'hooshpayOn'))
            ? Gateway::hooshpayOn($npRs) : (class_exists('HooshPay') && HooshPay::enabled());

        /*
         * چیدمان این زیرمنو از بخش «مدیریت دکمه‌ها» خوانده می‌شود； هرچه در گروه
         * «کیف پول» روشن و مرتب شده باشد دقیقا همینجا دیده می‌شود و روی کیبورد
         * منوی اصلی نمی‌رود. درگاه‌های خاموش هم کنار گذاشته می‌شوند.
         */
        $skip = [];
        if (!$cardOn) $skip[] = 'wal_card';
        if (!$cryOn)  { $skip[] = 'wal_crypto'; $skip[] = 'wal_hash'; }
        if (!$npOn)   $skip[] = 'wal_np';
        if (!$hpOn)   $skip[] = 'wal_hp';

        $rows = [];
        try {
            if (class_exists('Btn') && method_exists('Btn', 'subRows')) {
                $rows = Btn::subRows('wallet', self::isAdmin(), self::isRs(), $skip);
            }
        } catch (Throwable $e) { $rows = []; }

        if ($rows === []) {
            /* حالت پیش‌فرض: هنوز هیچ دکمه‌ای برای این زیرمنو روشن نشده است */
            if ($cardOn) $rows[] = [Tg::btn('💳 کارت به کارت (ریالی)', 'wal:card')];
            if ($npOn) {
                $npLb = (class_exists('Gateway') && method_exists('Gateway', 'nowpayLabel'))
                    ? Gateway::nowpayLabel($npRs) : "\xe2\x9a\xa1\xef\xb8\x8f پرداخت ارزی خودکار";
                $rows[] = [Tg::btn($npLb, 'wal:np')];
            }
            if ($hpOn) {
                $hpLb = (class_exists('Gateway') && method_exists('Gateway', 'hooshpayLabel'))
                    ? Gateway::hooshpayLabel($npRs) : '🪙 پرداخت آنی کارت به کارت';
                $rows[] = [Tg::btn($hpLb, 'wal:hp')];
            }
            if ($cryOn) {
                $rows[] = [Tg::btn('🌐 پرداخت کریپتو (دستی)', 'wal:crypto')];
                $rows[] = [Tg::btn('🔗 ارسال هش تراکنش (واریز انجام‌شده)', 'wal:hash')];
            }
            $rows[] = [Tg::btn('📜 تاریخچه تراکنش‌ها', 'wal:hist'), Tg::btn('🎁 کد هدیه', 'menu:gift')];
            if (class_exists('Referral') && Referral::enabled()) {
                $rows[] = [Tg::btn('👥 دعوت از دوستان و پورسانت', 'menu:ref')];
            }
        } else {
            /*
             * درگاه‌های روشنی که در چیدمان «مدیریت دکمه‌ها» دکمه‌ای ندارن��
             * خودکار اضافه می‌شوند؛ وگرنه کاربر هیچ راهی به آن‌ها ندارد.
             */
            foreach (self::walletMissingRows($cardOn, $cryOn, $npOn, $hpOn, $npRs) as $mrow) {
                $rows[] = $mrow;
            }

            $extra = [Tg::btn('🎁 کد هدیه', 'menu:gift')];
            if (class_exists('Referral') && Referral::enabled()) {
                $extra[] = Tg::btn('👥 دعوت از دوستان', 'menu:ref');
            }
            $rows[] = $extra;
        }

        if (!$cardOn && !$cryOn && !$npOn && !$hpOn) $l[] = "\n⚠️ در حال حاضر هیچ روش پرداختی فعال نیست.";

        if ($mb = self::miniappSectionBtn()) $rows[] = [$mb];
        $rows[] = Kb::backRow();

        $txt = implode("\n", $l);
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    /* ==================== احراز کارت بانکی ====================
       پیش از هر واریز کارت به کارت، کاربر باید کارت خودش را ثبت کند.
       هیچ داده‌ی حساسی (CVV2 / ��مز دوم / رمز پویا / انقضا) پرسیده نمی‌شود.
       ================================================================= */

    /**
     * منوی «شارژ کارت به کارت» — دو گزینه‌ی اصلی:
     *   ۱) ثبت کارت بانکی
     *   ۲) انجام عملیات شارژ
     * تا وقتی کارتی ثبت نشده باشد، گزینه‌ی شارژ اصلاً نشان داده نمی‌شود.
     */
    private static function walletCardMenu($chatId, $msgId = null): void
    {
        /* اگر احراز کارت خاموش باشد، همان مسیر قدیمی (پرسیدن مبلغ) اجرا می‌شود */
        if (!class_exists('CardAuth') || !CardAuth::enabled()) {
            self::walletCardStart($chatId);
            return;
        }

        $uid      = (int)self::$u['id'];
        $cards    = CardAuth::cards($uid);
        $approved = CardAuth::approved($uid);
        $required = CardAuth::required();

        $pending = 0;
        $reject  = 0;
        foreach ($cards as $c) {
            $s = (string)($c['status'] ?? '');
            if ($s === 'pending')  $pending++;
            if ($s === 'rejected') $reject++;
        }

        /* دکمه‌ی شارژ فقط وقتی دیده می‌شود که کارتی ثبت (و در حالت اجباری، تایید) شده باشد */
        $canPay = $required ? ($approved !== []) : ($cards !== []);

        $l   = [];
        $l[] = '💳 <b>شارژ کیف پول — کارت به کارت</b>';
        $l[] = '<code>─────────────────</code>';

        if ($approved !== []) {
            $l[] = '✅ کارت تاییدشده: <b>' . fa_num(count($approved)) . '</b>';
            foreach ($approved as $c) {
                $row = '   💳 <code>' . CardAuth::mask((string)$c['pan']) . '</code>';
                if ((string)($c['bank'] ?? '') !== '') $row .= ' • ' . h((string)$c['bank']);
                $l[] = $row;
            }
        }
        if ($pending > 0) $l[] = '⏳ در انتظار تایید مدیر: <b>' . fa_num($pending) . '</b> کارت';
        if ($reject > 0)  $l[] = '⛔️ ردشده: <b>' . fa_num($reject) . '</b> کارت';

        $l[] = '';
        if ($canPay) {
            $l[] = '✅ همه چیز آماده است؛ برای واریز، دکمه‌ی «انجام عملیات شارژ» را بزنید.';
        } elseif ($pending > 0) {
            $l[] = '⏳ کارت شما در انتظار تایید مدیر است؛';
            $l[] = 'پس از تایید، دکمه‌ی «انجام عملیات شارژ» همین‌جا ظاهر می‌شو��.';
        } else {
            $l[] = '🔐 برای واریز کارت به ک��رت، اول باید کارتی که با آن واریز می‌کنید ثبت شود.';
            $l[] = 'پس از ثبت کارت، گزینه‌ی «انجام عملیات شارژ» به همین منو اضافه می‌شود.';
        }
        $l[] = '';
        $l[] = '⛔️ هرگز <b>CVV2</b>، <b>رمز دوم</b>، <b>رمز پویا</b> یا <b>تاریخ انقضا</b> را برای هیچ‌کس ارسال نکنید.';

        $rows = [];
        if (CardAuth::canAdd($uid)) {
            $rows[] = [Tg::btn($cards === [] ? '💳 ثبت کارت بانکی' : '➕ ثبت کارت جدید', 'crd:add')];
        }
        if ($canPay) {
            $rows[] = [Tg::btn('💰 انجام عملیات شارژ', 'wal:cardgo')];
        }
        if ($cards !== []) {
            $rows[] = [Tg::btn('📋 کارت‌های من', 'crd:menu')];
        }
        $rows[] = Kb::backRow();

        $txt = implode("\n", $l);
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    /** شروع مسیر واریز کارت به کارت (با بررسی احراز کارت) */
    private static function walletCardStart($chatId): void
    {
        if (!class_exists('CardAuth') || !CardAuth::enabled()) {
            self::askAmount($chatId, 'card');
            return;
        }

        $g = CardAuth::gate(self::$u);
        if (empty($g['ok'])) {
            $rows = [];
            if ((string)($g['reason'] ?? '') === 'none') $rows[] = [Tg::btn('💳 ثبت کارت بانکی', 'crd:add')];
            $rows[] = [Tg::btn('📋 کارت‌های من', 'crd:menu')];
            $rows[] = Kb::backRow();
            Tg::send($chatId, (string)$g['msg'], Tg::ikb($rows));
            return;
        }

        $cards = array_values((array)($g['cards'] ?? []));
        if (count($cards) > 1) {
            $rows = [];
            foreach ($cards as $c) {
                $lb = '💳 ' . CardAuth::pretty((string)$c['pan']);
                if ((string)($c['bank'] ?? '') !== '') $lb .= ' • ' . $c['bank'];
                $rows[] = [Tg::btn($lb, 'crd:pick:' . (int)$c['id'])];
            }
            $rows[] = [Tg::btn('➕ ثبت کارت جدید', 'crd:add')];
            $rows[] = Kb::backRow();
            Tg::send($chatId, "💳 <b>با کدام کارت واریز می‌کنید؟</b>\n\n"
                . 'واریز باید از همان کارتی انجام شود که انتخاب می‌کنید؛ وگرنه رسید تایید نمی‌شود.',
                Tg::ikb($rows));
            return;
        }

        self::askAmount($chatId, 'card', (int)($cards[0]['id'] ?? 0));
    }

    /** فهرست کارت‌های کاربر */
    private static function cardMenu($chatId, $msgId = null): void
    {
        if (!class_exists('CardAuth') || !CardAuth::enabled()) {
            Tg::send($chatId, '⚠️ احراز کارت در حال حاضر غیرفعال است.');
            return;
        }

        $cards = CardAuth::cards((int)self::$u['id']);
        $l     = ['💳 <b>کارت‌های بانکی من</b>', ''];
        $rows  = [];

        if (!$cards) {
            $l[] = 'هنوز کارتی ثبت نکرده‌اید.';
            $l[] = '';
            $l[] = CardAuth::guide();
        } else {
            foreach ($cards as $c) {
                $l[] = CardAuth::label((string)$c['status']) . ' — <code>' . CardAuth::pretty((string)$c['pan']) . '</code>';
                if ((string)($c['bank'] ?? '') !== '')   $l[] = '   🏦 ' . $c['bank'];
                if ((string)($c['holder'] ?? '') !== '') $l[] = '   👤 ' . $c['holder'];
                if ((string)$c['status'] === 'rejected' && (string)($c['note'] ?? '') !== '') {
                    $l[] = '   📝 ' . $c['note'];
                }
                $l[]    = '';
                $rows[] = [Tg::btn('🗑 حذف ' . CardAuth::mask((string)$c['pan']), 'crd:del:' . (int)$c['id'])];
            }
        }

        $l[] = '📦 ظرفیت شما: <b>' . fa_num(CardAuth::activeCount((int)self::$u['id'])) . '</b> از <b>' . fa_num(CardAuth::maxCards()) . '</b> کارت فعال';
        $l[] = '';
        $l[] = '⛔️ هرگز <b>CVV2</b>، <b>رمز دوم</b>، <b>رمز پویا</b> یا <b>تاریخ انقضا</b> را برای هیچ‌کس ارسال نکنید.';

        if (CardAuth::canAdd((int)self::$u['id'])) {
            $rows[] = [Tg::btn('➕ ثبت کارت جدید', 'crd:add')];
        }
        $rows[] = Kb::backRow();

        $txt = implode("\n", $l);
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    /**
     * درخواست ثبت کارت
     * مسیر اصلی: پیامی با دکمهٔ مینی‌اپ که صفحهٔ ��وشمند ثبت کارت را باز می‌کند.
     * اگر مینی‌اپ خاموش باشد، خودکار به روش متنی برمی‌گردد.
     */
    private static function cardAskPan($chatId): void
    {
        if (!class_exists('CardAuth') || !CardAuth::enabled()) {
            Tg::send($chatId, '⚠️ احراز کارت در حال حاضر غیرفعال است.');
            return;
        }
        if (!CardAuth::canAdd((int)self::$u['id'])) {
            Tg::send($chatId, '⚠️ سقف تعداد کارت شما (' . fa_num(CardAuth::maxCards()) . ' کارت) پر شده است؛ یکی از کارت‌های قبلی را حذف کنید.');
            self::cardMenu($chatId);
            return;
        }

        $url = self::cardAppUrl();
        if (self::miniappOn() && stripos($url, 'https://') === 0) {
            self::setState(null);
            Tg::send($chatId,
                "💳 <b>ثبت کارت بانکی</b>\n\n"
                . "برای ثبت کارت، صفحهٔ هوشمند زیر را باز کنید:\n"
                . "• 🏦 تشخیص خودکار بانک از روی شماره‌کارت\n"
                . "• ✅ بررسی لحظه‌ای صحت شماره‌کارت و شبا\n"
                . "• 📷 امکان پیوست تصویر کارت\n\n"
                . '📦 ظرفیت باقی‌مانده: <b>' . fa_num(CardAuth::remaining((int)self::$u['id'])) . '</b> از ' . fa_num(CardAuth::maxCards()) . " کارت\n\n"
                . '⛔️ در آن صفحه هم فقط شماره‌کارت لازم است؛ <b>CVV2</b>، <b>رمز دوم</b>، <b>رمز پویا</b> و <b>تاریخ انقضا</b> هرگز پرسیده نمی‌شود.',
                Tg::ikb([
                    [['text' => '💳 باز کردن صفحهٔ ثبت کارت', 'web_app' => ['url' => $url]]],
                    [Tg::btn('⌨️ ثبت با پیام متنی', 'crd:manual')],
                    [Tg::btn('↩️ بازگشت', 'crd:menu')],
                ]));
            return;
        }

        self::cardAskPanText($chatId);
    }

    /** مسیر جایگزین: دریافت شماره‌کارت به‌صورت متنی داخل چت */
    private static function cardAskPanText($chatId): void
    {
        if (!class_exists('CardAuth') || !CardAuth::enabled()) {
            Tg::send($chatId, '⚠️ احراز کارت در حال حاضر غیرفعال است.');
            return;
        }
        if (!CardAuth::canAdd((int)self::$u['id'])) {
            Tg::send($chatId, '⚠️ سقف تعداد کارت شما (' . fa_num(CardAuth::maxCards()) . ' کارت) پر شده است؛ یکی از کارت‌های قبلی را ��ذف کنید.');
            self::cardMenu($chatId);
            return;
        }

        self::setState('card_pan', []);
        Tg::send($chatId,
            "💳 <b>ثبت کارت بانکی</b>\n\n"
            . CardAuth::guide() . "\n\n"
            . "💳 شماره‌ی <b>۱۶ رقمی</b> کارت خود را ارسال کنید:\n"
            . "<i>مانند: 6037 9911 2233 4455</i>\n\n"
            . '⛔️ فقط شماره‌کارت لازم است؛ CVV2، رمز دوم، رمز پویا و تاریخ انقضا هرگز پرسیده نمی‌شود.',
            Kb::cancel());
    }

    /** حذف کارت توسط خود کاربر */
    private static function cardDelete($chatId, int $id): void
    {
        if (!class_exists('CardAuth')) return;
        $ok = CardAuth::remove($id, (int)self::$u['id']);
        Tg::send($chatId, $ok ? '🗑 کارت حذف شد.' : '❌ کارت یافت نشد.');
        self::cardMenu($chatId);
    }

    /** ذخیره‌ی نهایی کارت */
    private static function cardSave($chatId, string $pan, string $holder = '', string $sheba = ''): void
    {
        $r = CardAuth::add(self::$u, $pan, $holder, $sheba);
        self::setState(null);

        if (empty($r['ok'])) {
            Tg::send($chatId, (string)($r['msg'] ?? 'ثبت کارت انجام نشد.'), self::kbMain());
            return;
        }

        Tg::send($chatId,
            (string)$r['msg'] . "\n\n💳 <code>" . CardAuth::pretty($pan) . '</code>',
            self::kbMain());

        if ((string)($r['status'] ?? '') === 'approved') {
            Tg::send($chatId, '💰 اکنون می‌توانید شارژ کارت به کارت را شروع کنید.',
                Tg::ikb([[Tg::btn('💳 شارژ کارت به کارت', 'wal:cardgo')], Kb::backRow()]));
        }
    }

    private static function askAmount($chatId, string $method, int $cardId = 0): void
    {
        if ($method === 'card') {
            $hasCard = (string)DB::setting('card_number', '') !== '';
            if (!$hasCard && class_exists('Gateway')) {
                try { $hasCard = Gateway::card(self::isRs()) !== null; } catch (Throwable $e) {}
            }
            if (!$hasCard) {
                Tg::send($chatId, '⚠️ اطلاعات کارت توسط مدیر تنظیم نشده است.');
                return;
            }
        }
        if ($method === 'crypto' || $method === 'crypto_hash') {
            $hasGw = (string)DB::setting('crypto_address', '') !== '';
            if (!$hasGw && class_exists('Gateway')) {
                try { $hasGw = Gateway::crypto(self::isRs()) !== []; } catch (Throwable $e) {}
            }
            if (!$hasGw) {
                Tg::send($chatId, '⚠️ هیچ درگاه ارزی توسط مدیر تنظیم نشده است.');
                return;
            }
        }
        if ($method === 'nowpay' && !NowPay::enabled()) {
            Tg::send($chatId, '⚠️ درگاه پرداخت خودکار ارزی فعال نیست.');
            return;
        }
        if ($method === 'hooshpay' && (!class_exists('HooshPay') || !HooshPay::enabled())) {
            Tg::send($chatId, '⚠️ درگاه پرداخت آنی هوش‌پی فعال نیست.');
            return;
        }
        self::setState('wallet_amount', ['method' => $method, 'card_id' => $cardId]);

        $labels = [
            'card'        => '💳 کارت به کارت',
            'crypto'      => '🌐 پرداخت کریپتو',
            'crypto_hash' => '🔗 ارسال هش واریز انجام‌شده',
            'nowpay'      => '⚡️ پرداخت ارزی خودکار',
            'hooshpay'    => '🪙 پرداخت آنی کارت به کارت',
        ];
        $min = (int)DB::setting('min_deposit', 0);
        $max = (int)DB::setting('max_deposit', 0);

        $l = [];
        $l[] = '💰 <b>شارژ کیف پول</b>';
        $l[] = '🔹 روش انتخابی: ' . ($labels[$method] ?? $method);
        $l[] = '<code>─────────────────</code>';
        $l[] = 'مبلغ مورد نظر را به <b>' . currency() . '</b> و فقط با عدد ارسال کنید.';
        if ($min > 0) $l[] = '⬇️ حداقل: <b>' . money($min) . ' ' . currency() . '</b>';
        if ($max > 0) $l[] = '⬆️ حداکثر: <b>' . money($max) . ' ' . currency() . '</b>';
        $l[] = '';
        $l[] = '💡 مثال: <code>' . fa_num((string)($min > 0 ? $min : 100000)) . '</code>';

        Tg::send($chatId, implode("\n", $l), Kb::cancel());
    }

    /** بررسی دستی وضعیت پرداخت خودکار ارزی */
    private static function checkNowPay($chatId, int $txId): void
    {
        $tx = DB::one('SELECT * FROM {p}transactions WHERE id = :i AND user_id = :u',
            [':i' => $txId, ':u' => (int)self::$u['id']]);
        if (!$tx) { Tg::send($chatId, '❌ تراکنش یافت نشد.'); return; }
        if ($tx['status'] === 'approved') {
            Tg::send($chatId, "✅ این پرداخت قبلاً تایید و کیف پول شارژ شده است.\nموجودی: <b>"
                . money(Wallet::balance((int)self::$u['id'])) . ' ' . currency() . '</b>');
            return;
        }
        if ($tx['status'] === 'rejected') { Tg::send($chatId, '❌ این پرداخت رد یا منقضی شده است. لطفاً مجدد اقدام کنید.'); return; }

        $res = NowPay::poll($tx);
        $ok  = !empty($res['ok']) && empty($res['pending']);
        Tg::send($chatId, ($ok ? '🔄 ' : '⏳ ') . (string)($res['message'] ?? 'هنوز پرداختی ثبت نشده است.'));
    }

    /** بررسی دستی ��ضعیت پرداخت هوش‌پی */
    /**
     * دکمهٔ درگاه‌هایی که روشن‌اند ولی در چیدمان «مدیریت دکمه‌ها» جایی ندارند
     *
     * درگاه پرداخت با صفحهٔ «درگاه‌های پرداخت» روشن می‌شود؛ صفحهٔ «مدیریت
     * دکمه‌ها» فقط محل نمایش دکمه را تعیین می‌کند. اگر درگاهی روشن باشد ولی
     * دکمه‌اش در چیدمان روشن نباشد (مثل درگاه‌های تازه)، کاربر هیچ راهی برای
     * رسیدن به آن ندارد؛ پس دکمه همین‌جا خودکار ساخته می‌شود.
     */
    /** وضعیت واقعی یک روش پرداخت: تنظیم سراسری + درگاه‌های تعریف‌شده و روشن در «درگاه‌های پرداخت» */
    private static function payMethodOn(string $kind, ?bool $isRs = null): bool
    {
        if ($isRs === null) {
            try { $isRs = class_exists('Reseller') && Reseller::isReseller(self::$u); } catch (Throwable $e) { $isRs = false; }
        }
        try {
            if ($kind === 'card' && class_exists('Gateway') && method_exists('Gateway', 'cardOn')) {
                return Gateway::cardOn($isRs);
            }
            if ($kind === 'crypto' && class_exists('Gateway') && method_exists('Gateway', 'cryptoOn')) {
                return Gateway::cryptoOn($isRs);
            }
        } catch (Throwable $e) {
        }
        $key = $kind === 'card' ? 'card_enabled' : 'crypto_enabled';
        return (string)DB::setting($key, '1') === '1';
    }

    private static function walletMissingRows(bool $cardOn, bool $cryOn, bool $npOn, bool $hpOn, bool $isRs): array
    {
        $on = static function (string $key): bool {
            try {
                if (!class_exists('Btn') || !method_exists('Btn', 'isOn')) return true;
                return Btn::isOn($key, self::isAdmin(), self::isRs(), 'wallet');
            } catch (Throwable $e) {
                return true;
            }
        };

        $out = [];

        if ($cardOn && !$on('wal_card')) {
            $out[] = [Tg::btn('💳 کارت به کارت (ریالی)', 'wal:card')];
        }

        if ($npOn && !$on('wal_np')) {
            $lb = '⚡️ پرداخت ارزی خودکار';
            if (class_exists('Gateway') && method_exists('Gateway', 'nowpayLabel')) {
                $lb = Gateway::nowpayLabel($isRs);
            }
            $out[] = [Tg::btn($lb, 'wal:np')];
        }

        if ($hpOn && !$on('wal_hp')) {
            $lb = '🪙 پرداخت آنی کارت به کارت';
            if (class_exists('Gateway') && method_exists('Gateway', 'hooshpayLabel')) {
                $lb = Gateway::hooshpayLabel($isRs);
            }
            $out[] = [Tg::btn($lb, 'wal:hp')];
        }

        if ($cryOn && !$on('wal_crypto')) {
            $out[] = [Tg::btn('🌐 پرداخت کریپتو (دستی)', 'wal:crypto')];
        }

        if ($cryOn && !$on('wal_hash')) {
            $out[] = [Tg::btn('🔗 ارسال هش تراکنش (واریز انجام‌شده)', 'wal:hash')];
        }

        return $out;
    }

    private static function checkHooshPay($chatId, int $txId): void
    {
        $tx = DB::one('SELECT * FROM {p}transactions WHERE id = :i AND user_id = :u',
            [':i' => $txId, ':u' => (int)self::$u['id']]);
        if (!$tx) { Tg::send($chatId, '❌ تراکنش یافت نشد.'); return; }

        if ($tx['status'] === 'approved') {
            Tg::send($chatId, "✅ این پرداخت قبلاً تایید و کیف پول شارژ شده است.\nموجودی: <b>"
                . money(Wallet::balance((int)self::$u['id'])) . ' ' . currency() . '</b>');
            return;
        }
        if ($tx['status'] === 'rejected') {
            Tg::send($chatId, '❌ این پرداخت رد یا منقضی شده است. لطفاً مجدد اقدام کنید.');
            return;
        }
        if (!class_exists('HooshPay')) {
            Tg::send($chatId, '⚠️ درگاه هوش‌پی در دسترس نیست.');
            return;
        }

        $res = HooshPay::poll($tx);
        $ok  = !empty($res['ok']) && !empty($res['paid']);
        Tg::send($chatId, ($ok ? '✅ ' : '⏳ ') . (string)($res['message'] ?? 'هنوز پرداختی ثبت نشده است.'));
    }

    private static function walletHistory($chatId, $msgId = null): void
    {
        $rows = DB::all('SELECT * FROM {p}transactions WHERE user_id = :u ORDER BY id DESC LIMIT 12', [':u' => (int)self::$u['id']]);
        $kb   = Tg::ikb([[Tg::btn('⬅️ بازگشت به کیف پول', 'menu:wallet')]]);
        if (!$rows) {
            $txt = "📜 <b>تاریخچه تراکنش‌ها</b>\n\nه��وز تراکنشی ثبت نشده است.";
            $msgId ? Tg::edit($chatId, $msgId, $txt, $kb) : Tg::send($chatId, $txt, $kb);
            return;
        }
        $typeMap = [
            'deposit'  => '💳 شارژ کیف پول',
            'purchase' => '🛒 خرید سرویس',
            'refund'   => '↩️ بازگشت وجه',
            'gift'     => '🎁 کد هدیه',
            'admin'    => '🛠 توسط مدیر',
            'referral' => '👥 پاداش معرفی',
        ];
        $stMap = ['pending' => '⏳ در انتظار', 'approved' => '✅ تایید شده', 'rejected' => '❌ رد شده'];

        $out = [
            '📜 <b>تاریخچه تراکنش‌ها</b>',
            '<i>' . fa_num(count($rows)) . ' تراکنش اخیر</i>',
            '<code>─────────────────</code>',
            '',
        ];
        foreach ($rows as $t) {
            $amt  = (int)$t['amount'];
            $sign = $amt >= 0 ? '🟢 +' : '🔴 −';
            $out[] = '▫️ <b>#' . $t['id'] . '</b> – ' . ($typeMap[$t['type']] ?? $t['type']);
            $out[] = '   ' . $sign . money(abs($amt)) . ' ' . currency();
            $out[] = '   ' . ($stMap[$t['status']] ?? $t['status']) . ' • ' . to_jalali((string)$t['created_at'], true);
            $out[] = '';
        }
        $txt = implode("\n", $out);
        $msgId ? Tg::edit($chatId, $msgId, $txt, $kb) : Tg::send($chatId, $txt, $kb);
    }

    /* ================= آموزش ================= */

    private static function sectionTutorials($chatId, $msgId = null): void
    {
        $rows = [];
        foreach (Kb::platforms() as $key => $label) {
            $n = (int)DB::val('SELECT COUNT(*) FROM {p}tutorials WHERE active = 1 AND platform = :p', [':p' => $key], 0);
            if ($n > 0) $rows[] = [Tg::btn($label . ' (' . fa_num($n) . ')', 'tut:' . $key)];
        }
        if (!$rows) { Tg::send($chatId, '🎓 فعلاً مطلب آموزشی ثبت نشده است.'); return; }
        $rows[] = Kb::backRow();
        $txt = "🎓 <b>آموزش اتصال</b>\nدستگاه خود را انتخاب کنید:";
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    private static function showTutorials($chatId, $msgId, string $platform): void
    {
        $list = DB::all('SELECT * FROM {p}tutorials WHERE active = 1 AND platform = :p ORDER BY sort ASC, id ASC', [':p' => $platform]);
        if (!$list) { Tg::send($chatId, 'مطلبی برای این دستگاه نیست.'); return; }
        $rows = [];
        foreach ($list as $t) $rows[] = [Tg::btn((string)$t['title'], 'tutx:' . $t['id'])];
        $rows[] = [Tg::btn('⬅️ دستگاه‌ها', 'tut:menu')];
        $txt = '🎓 <b>' . h(Kb::platformLabel($platform)) . "</b>\nیک مورد را انتخاب کنید:";
        if ($platform === 'menu') { self::sectionTutorials($chatId, $msgId); return; }
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    private static function showTutorial($chatId, $msgId, int $id): void
    {
        $t = DB::one('SELECT * FROM {p}tutorials WHERE id = :id AND active = 1', [':id' => $id]);
        if (!$t) { Tg::send($chatId, '❌ مطلب یافت نشد.'); return; }
        $txt = '🎓 <b>' . h((string)$t['title']) . "</b>\n\n" . (string)$t['content'];
        $rows = [];
        if (trim((string)$t['link']) !== '') $rows[] = [Tg::url('🔗 دریافت برنامه', (string)$t['link'])];
        $rows[] = [Tg::btn('⬅️ بازگشت', 'tut:' . $t['platform'])];
        if (trim((string)$t['file_id']) !== '') {
            Tg::api('sendPhoto', ['chat_id' => $chatId, 'photo' => (string)$t['file_id'], 'caption' => mb_substr($txt, 0, 1000), 'parse_mode' => 'HTML', 'reply_markup' => jenc(Tg::ikb($rows))]);
            return;
        }
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    /* ================= پشتیبانی ================= */

    private static function sectionSupport($chatId, $msgId = null): void
    {
        $tickets = DB::all('SELECT * FROM {p}tickets WHERE user_id = :u ORDER BY id DESC LIMIT 8', [':u' => (int)self::$u['id']]);
        $rows = [[Tg::btn('✍️ ارسال پیام جدید به پشتیبانی', 'tk:new')]];
        foreach ($tickets as $t) {
            $icon = $t['status'] === 'answered' ? '✅' : ($t['status'] === 'closed' ? '🔒' : '🕓');
            $rows[] = [Tg::btn($icon . ' #' . $t['id'] . ' – ' . mb_substr((string)$t['subject'], 0, 28), 'tk:' . $t['id'])];
        }
        $sup = trim((string)DB::setting('support_username', ''));
        if ($sup !== '') $rows[] = [Tg::url('💬 گفتگوی مستقیم با پشتیبان', 'https://t.me/' . ltrim($sup, '@'))];
        $rows[] = Kb::backRow();
        $txt = "🆘 <b>پشتیبانی</b>\n\nسوال یا مشکل خود را ارسال کنید؛ پاسخ در همین ربات برای شما ارسال می‌شود.";
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    private static function showTicket($chatId, $msgId, int $id): void
    {
        $t = DB::one('SELECT * FROM {p}tickets WHERE id = :id AND user_id = :u', [':id' => $id, ':u' => (int)self::$u['id']]);
        if (!$t) { Tg::send($chatId, '❌ تیکت یافت نشد.'); return; }
        $msgs = DB::all('SELECT * FROM {p}ticket_messages WHERE ticket_id = :t ORDER BY id ASC LIMIT 20', [':t' => $id]);
        $stMap = ['open' => '🕘 در انتظار پاسخ', 'answered' => '✅ پاسخ داده شده', 'closed' => '🔒 بسته شده'];
        $out = [
            '🎫 <b>تیکت #' . $id . '</b>',
            '📝 ' . h((string)$t['subject']),
            '🔰 ' . ($stMap[(string)$t['status']] ?? (string)$t['status']),
            '<code>─────────────────</code>',
            '',
        ];
        $attachments = [];
        foreach ($msgs as $m) {
            $who = $m['sender'] === 'admin' ? '🛠 <b>پشتیبانی</b>' : '👤 <b>شما</b>';
            $out[] = $who . ' – <i>' . to_jalali((string)$m['created_at'], true) . '</i>';
            if (trim((string)$m['text']) !== '') $out[] = h(mb_substr((string)$m['text'], 0, 500));
            if (!empty($m['file_id'])) {
                $out[] = '📎 <i>فایل پیوست – در پیام بعدی ارسال می‌شود</i>';
                $attachments[] = $m;
            }
            $out[] = '';
        }
        $rows = [[Tg::btn('✍️ ارسال پاسخ', 'tkr:' . $id)], [Tg::btn('⬅️ بازگشت', 'menu:main')]];
        $txt = implode("\n", $out);
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
        foreach (array_slice($attachments, -5) as $m) {
            Tg::media($chatId, (string)$m['file_id'], (string)($m['file_type'] ?? ''),
                '📎 پیوست تیکت #' . $id . ' – ' . ($m['sender'] === 'admin' ? 'پشتیبانی' : 'شما'));
        }
    }

    /* ==================== تایید حساب کاربری ==================== */

    /** منوی تایید حساب */
    public static function verifyMenu($chatId, $msgId = null): void
    {
        if (!class_exists('Security')) return;

        $u = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)self::$u['id']]) ?: [];
        if (!$u) return;

        $l = [];
        $l[] = '🔐 <b>تایید حساب کاربری</b>';
        $l[] = '<code>─────────────────</code>';

        if (Security::isVerified($u)) {
            $l[] = '✅ حساب شما <b>تایید شده</b> است.';
            $l[] = '';
            $l[] = '📧 ایمیل: ' . ((int)($u['email_verified'] ?? 0) === 1 ? '✅ تایید شده' : '—');
            $l[] = '📱 موبایل: ' . ((int)($u['phone_verified'] ?? 0) === 1 ? '✅ تایید شده' : '—');
            if (!empty($u['verified_at'])) $l[] = '📅 تاریخ تایید: ' . to_jalali((string)$u['verified_at'], true);
            $rows = [Kb::backRow('menu:main')];
            $txt = implode("\n", $l);
            $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
            return;
        }

        $kinds = Security::activeKinds();
        if (!$kinds) {
            $l[] = 'ℹ️ در حال حاضر هیچ روش تاییدی توسط مدیر فعا�� نشده است.';
            $rows = [Kb::backRow('menu:main')];
            $txt = implode("\n", $l);
            $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
            return;
        }

        $l[] = Security::mode() === 'required'
            ? '⚠️ برای استفاده از این بخش، تایید حساب <b>اجباری</b> است.'
            : 'با تایید حساب، امنیت خرید و پشتیبانی شما بالاتر می‌رود.';
        $l[] = '';
        $l[] = '👇 یکی از روش‌های زیر را انتخاب کنید:';

        $rows = [];
        foreach ($kinds as $k => $m) $rows[] = [Tg::btn((string)$m['label'], 'ver:' . $k)];
        $rows[] = Kb::backRow('menu:main');

        $txt = implode("\n", $l);
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    /** لینک تایید یک‌بارمصرف */
    public static function verifyLink($chatId): void
    {
        if (!class_exists('Security')) return;
        $u = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)self::$u['id']]) ?: [];
        if (!$u) return;

        $r = Security::start($u, 'link', (string)($u['email'] ?? ''));
        if (empty($r['ok'])) { Tg::send($chatId, '❌ ' . (string)$r['message']); return; }

        self::setState(null);
        Tg::send($chatId, "\xf0\x9f\x94\x97 <b>لینک تایید حساب</b>\n\n"
            . "روی دکمهٔ زیر بزنید تا حساب شما فوری تایید شود.\n"
            . '⏱ اعتبار لینک: ' . fa_num((string)Security::ttlMin()) . ' دقیقه',
            Tg::ikb([[Tg::url('✅ تایید حساب من', (string)($r['link'] ?? ''))], Kb::backRow('ver:menu')]));
    }

    /** ارسال مجدد کد تایید */
    public static function verifyResend($chatId): void
    {
        if (!class_exists('Security')) return;
        $u = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)self::$u['id']]) ?: [];
        if (!$u) return;

        $kind   = (string)($u['verify_kind'] ?? '');
        $target = (string)($u['verify_target'] ?? '');
        if ($kind === '') { self::verifyMenu($chatId); return; }
        if ($kind === 'link') { self::verifyLink($chatId); return; }

        $r = Security::start($u, $kind, $target);
        Tg::send($chatId, (empty($r['ok']) ? '❌ ' : '✅ ') . (string)$r['message']);
        if (!empty($r['ok'])) self::setState('verify_code');
    }

    /* ==================== نمایندگی ==================== */

    /** شروع ثبت درخواست نمایندگی – یک توضیح اختیاری از کاربر گرفته می‌شود */
    private static function resellerRequest($chatId): void
    {
        if (!class_exists('Reseller') || !Reseller::enabled() || !Reseller::requestsOpen()) {
            Tg::send($chatId, '⚠️ ثبت درخواست نمایندگی فعلاً امکان‌پذیر نیست.', self::kbMain());
            return;
        }

        $u = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)self::$u['id']]) ?: (array)self::$u;

        if (Reseller::isReseller($u)) {
            Tg::send($chatId, '✅ شما از قبل نماینده هستید.', self::kbMain());
            return;
        }
        if (Reseller::hasRequest($u)) {
            Tg::send($chatId, '🕓 درخواست شما قبلاً ثبت شده و در انتظار بررسی است.', self::kbMain());
            return;
        }

        $fee = Reseller::reqFee();

        if ($fee > 0) {
            $bal  = (int)($u['balance'] ?? 0);
            $back = Reseller::reqFeeCredit();
            $auto = Reseller::reqAuto();

            $l = [];
            $l[] = '🏷 <b>درخواست نمایندگی</b>';
            $l[] = '<code>─────────────────</code>';
            $l[] = '💰 هزینهٔ فعال‌سازی: <b>' . money($fee) . ' ' . currency() . '</b>';
            $l[] = '👛 موجودی شما: <b>' . money($bal) . ' ' . currency() . '</b>';
            $l[] = '';
            $l[] = $back
                ? '🎁 این مبلغ پس از فعال‌سازی <b>به اعتبار شما برگردانده می‌شود</b>.'
                : 'ℹ️ این مبلغ هزینهٔ فعال‌سازی است و بازگردانده نمی‌شود.';
            $l[] = $auto
                ? '⚡️ پس از پرداخت، نمایندگی <b>فوراً</b> فعال می‌شود.'
                : '🕓 پس از پرداخت، درخواست شما برای بررسی مدیر ثبت می‌شود.';

            $fnote = trim(self::tx('rs_fee_note', ''));
            if ($fnote !== '') { $l[] = ''; $l[] = $fnote; }

            $rows = [];
            if ($bal >= $fee) {
                $rows[] = [Tg::btn('✅ پرداخت و ثبت درخواست', 'rsp:go')];
            } else {
                $l[] = '';
                $l[] = '⚠️ موجودی کافی نیست؛ اول کیف پول را شارژ کنید.';
                $rows[] = [Tg::btn('💳 شارژ کیف پول', 'menu:wallet')];
            }
            $rows[] = Kb::backRow('menu:main');

            Tg::send($chatId, implode("\n", $l), Tg::ikb($rows));
            return;
        }

        self::setState('reseller_req', []);

        $txt = "📝 <b>درخواست نمایندگی</b>\n"
            . "<code>─────────────────</code>\n"
            . "در یک پیام کوتاه بنویسید:\n\n"
            . "• حدود فروش ماهانهٔ مورد ا��تظار\n"
            . "• روش جذب مشتری (کانال، اینستاگرام، دوستان و …)\n"
            . "• هر توضیحی که فکر می‌کنید لازم است\n\n"
            . "در صورتی که توضیحی ندارید، عدد <b>۰</b> را بفرستید.";

        Tg::send($chatId, $txt, Kb::cancel());
    }

    private static function sectionReseller($chatId): void
    {
        if (!class_exists('Reseller') || !Reseller::enabled()) {
            Tg::send($chatId, '⚠️ بخش نمایندگی فعلاً غیرفعال است.', self::kbMain());
            return;
        }

        $u = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)self::$u['id']]) ?: (array)self::$u;

        if (!Reseller::isReseller($u)) {
            $sup = trim((string)DB::setting('support_username', ''));
            $txt = "🏷 <b>نمایندگی</b>\n\n"
                . "شما هنوز نماینده نیستید.\n"
                . "با نمایندگی می‌توانید خودتان هر حجم و مدتی که خواستید کانفیگ بسازید:\n\n"
                . "🥈 <b>سطح ۱</b> – ساخت کانفیگ با تخفیف، فقط با موجودی کیف پول\n"
                . "🥇 <b>سطح ۲</b> – تخفیف بیشتر + امکان بدهکار شدن تا سقف مشخص\n\n"
                . 'برای دریافت نمایندگی با پشتیبانی هماهنگ کنید.';

            $rows = [];

            if (Reseller::hasRequest($u)) {
                $txt .= "\n\n🕓 <b>درخواست شما ثبت شده و در انتظار بررسی مدیر است.</b>";
            } elseif (Reseller::requestsOpen()) {
                $txt .= "\n\n👇 برای ثبت درخواست نمایندگی دکمهٔ زیر را بزنید.";
                $rows[] = [Tg::btn('📝 درخواست نمایندگی', 'rs:req')];
            }

            if ($sup !== '') $rows[] = [Tg::url('🆘 تماس با پشتیبانی', 'https://t.me/' . ltrim($sup, '@'))];
            $rows[] = [Tg::btn('🎫 ارسال تیکت', 'tk:new')];

            $rows[] = Kb::backRow('menu:main');
            Tg::send($chatId, $txt, Tg::ikb($rows));
            return;
        }

        $level = Reseller::level($u);
        $q     = Reseller::quota($u);
        $st    = Reseller::stats($u);
        $pct   = Reseller::userDiscount($u);

        $txt = "🏷 <b>پنل نمایندگی</b>\n\n"
            . '🎖 سطح شما: <b>' . Reseller::levelLabel($level) . "</b>\n"
            . '💰 موجودی: <b>' . $q['balance_txt'] . ' ' . currency() . "</b>\n";

        if ($level >= 2) {
            $txt .= '🧾 سقف بدهی مجاز: ' . $q['credit_txt'] . ' ' . currency() . "\n"
                . '✅ قابل ��ستفاده: <b>' . $q['avail_txt'] . ' ' . currency() . "</b>\n";
            if ((int)$q['debt'] > 0) {
                $txt .= '⚠️ بدهی فعلی: <b>' . $q['debt_txt'] . ' ' . currency() . "</b>\n";
            }
        } else {
            $txt .= "ℹ️ در سطح ۱ ساخت کانفیگ فقط با موجودی کافی امکان‌پذیر است.\n";
        }

        $txt .= "\n💵 <b>تعرفهٔ شما</b>\n";

        /* اگر سرورها تعرفهٔ جداگانه دارند، همه را لیست می‌کنیم */
        $srvT  = method_exists('Reseller', 'panelCards') ? Reseller::panelCards($u) : [];
        $manyT = false;
        foreach ($srvT as $scT) {
            if (!empty($scT['custom'])) { $manyT = true; break; }
        }

        if ($manyT) {
            $txt .= "🌍 <b>قیمت هر سرور جداگانه است:</b>\n";
            foreach (array_slice($srvT, 0, 8) as $scT) {
                $txt .= '• <b>' . h((string)$scT['name']) . '</b>: '
                    . money((int)$scT['gb']) . ' / گیگ · '
                    . money((int)$scT['day']) . " / روز\n";
                if ((int)$scT['off'] > 0) {
                    $txt .= '   🎁 ' . fa_num((int)$scT['off']) . "٪ تخفیف ویژهٔ این سرور\n";
                }
            }
            $txt .= '▪️ واحد: ' . currency() . "\n";
        } else {
            $txt .= '• هر گیگابایت: ' . money(Reseller::priceGb()) . ' ' . currency() . "\n"
                . '• هر روز: ' . money(Reseller::priceDay()) . ' ' . currency() . "\n";
        }

        if ($pct > 0) $txt .= '• تخفیف نمایندگی: ' . fa_num((int)$pct) . "٪\n";

        $txt .= "\n📊 ساخته‌شده: " . fa_num((int)$st['services']) . ' سرویس (فعال: ' . fa_num((int)$st['active']) . ")\n"
            . "\n👇 برای ساخت کانفیگ، پنل نمایندگی را باز کنید:";

        $rows = [];
        if (self::miniappOn()) {
            $rows[] = [['text' => '🏷 باز کردن پنل نمایندگی', 'web_app' => ['url' => self::rsAppUrl()]]];
        } else {
            $txt .= "\n\n⚠️ مینی‌اپ فعال نیست؛ از مدیر بخواهید آدرس مینی‌اپ را با HTTPS تنظیم کند.";
        }
        $rows[] = [Tg::btn('🧩 کانفیگ‌های نمایندگی', 'rs:svcs'), Tg::btn('💳 شارژ کیف پول', 'menu:wallet')];

        $rows[] = Kb::backRow('menu:main');
        Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    /* ==================== مینی‌اپ تلگرام ==================== */

    /** متن دکمهٔ مینی‌اپ */
    public static function miniappLabel(): string
    {
        $t = trim((string)DB::setting('miniapp_button', '🚀 اپلیکیشن'));
        return $t !== '' ? mb_substr($t, 0, 30) : '🚀 اپلیکیشن';
    }

    /** آدرس کامل مینی‌اپ */
    public static function miniappUrl(string $tab = ''): string
    {
        $u = app_url('miniapp/');
        return $tab !== '' ? $u . '#' . $tab : $u;
    }

    /** آدرس پنل اختصاصی نمایندگی (مینی‌اپ جداگانه) */
    public static function rsAppUrl(): string
    {
        $u = trim((string)DB::setting('rs_app_url', ''));
        return $u !== '' ? $u : app_url('miniapp/reseller.php');
    }

    /** آدرس صفحهٔ هوشمند ثبت کارت (مینی‌اپ جداگانه) */
    public static function cardAppUrl(): string
    {
        $u = trim((string)DB::setting('card_app_url', ''));
        return $u !== '' ? $u : app_url('miniapp/card.php');
    }

    /** آیا مینی‌اپ فعال است؟ */
    public static function miniappOn(): bool
    {
        if ((string)DB::setting('miniapp_enabled', '1') !== '1') return false;
        return stripos(self::miniappUrl(), 'https://') === 0;
    }

    /**
     * دکمهٔ شیشه‌ای مینی‌اپ برای منوها
     * اگر غیرفعال باشد null برمی‌گرداند
     */
    /**
     * پیام حالت «فقط مینی‌اپ» (سکوت ربات)
     * وقتی ربات در سکوت است و کاربر /start می‌زند، تنها یک متن کوتاه
     * با دکمهٔ ورود به اپ می‌فرستیم؛ نمایندگان یک دکمهٔ جداگانه برای
     * پنل نمایندگی هم می‌گیرند.
     */
    public static function muteStart(int $tgId): void
    {
        if ($tgId <= 0) return;

        $txt = trim((string)DB::setting('mute_start_text', ''));
        if ($txt === '') {
            $txt = "\xf0\x9f\x91\x8b <b>خوش آمدید</b>\n\n"
                . "\xf0\x9f\x9a\x80 همهٔ خدمات این فروشگاه از داخل اپلیکیشن انجام می‌شود.\n"
                . "برای خرید سرویس، شارژ کیف پول و مدیریت کانفیگ‌ها دکمهٔ زیر را بزنید.";
        }

        /* کیبورد قدیمی ربات باید پاک شود، وگرنه دکمه‌های بی‌کار روی صفحه می‌مانند */
        self::dropReplyKb($tgId);

        $rows = self::muteRows($tgId);
        Tg::send($tgId, $txt, $rows ? Tg::ikb($rows) : null);
    }

    /**
     * دکمه‌های شیشه‌ای حالت «فقط مینی‌اپ»
     *
     * ۱) اپلیکیشن
     * ۲) پنل نمایندگی برای نمایندگان یا «درخواست نمایندگی» برای بقیه
     * ۳) پنل مدیریت برای مدیران
     */
    public static function muteRows(int $tgId): array
    {
        $rows = [];

        if (self::miniappOn()) {
            $rows[] = [['text' => self::miniappLabel(), 'web_app' => ['url' => self::miniappUrl()]]];
        }

        try {
            $ru = DB::one('SELECT * FROM {p}users WHERE tg_id = :t', [':t' => $tgId]);
            if ($ru && class_exists('Reseller') && Reseller::enabled()) {
                if (Reseller::isReseller($ru)) {
                    $rsUrl = self::rsAppUrl();
                    if ($rsUrl !== '') {
                        $rows[] = [['text' => '🏷 پنل نمایندگی', 'web_app' => ['url' => $rsUrl]]];
                    }
                } elseif (Reseller::requestsOpen()) {
                    $rows[] = [Tg::btn(
                        Reseller::hasRequest($ru) ? '🕓 وضعیت درخواست نمایندگی' : '🏷 درخواست نمایندگی',
                        'mute:rsreq'
                    )];
                }
            }
        } catch (Throwable $e) {
            /* نبود جدول یا بخش نمایندگی نباید پیام خوش‌آمد را خراب کند */
        }

        if (is_admin_id($tgId)) {
            $adm = (string)app_url('/admin/');
            if (preg_match('~^https?://~i', $adm)) {
                $rows[] = [Tg::url('🛠 پنل مدیریت', $adm)];
            }
        }

        return $rows;
    }

    /** پاک کردن کیبورد دکمه‌ای قدیمی با یک پیام موقت */
    private static function dropReplyKb(int $tgId): void
    {
        try {
            $r = Tg::send($tgId, '⌛️', Tg::removeKb(), ['disable_notification' => true]);
            $mid = (int)($r['result']['message_id'] ?? 0);
            if ($mid > 0) Tg::deleteMsg($tgId, $mid);
        } catch (Throwable $e) {
        }
    }

    /**
     * مسیریابی دکمه‌های حالت سکوت (فقط پیشوند mute:)
     *
     * در این حالت بقیهٔ ربات خاموش است؛ پس همهٔ کار درون همین تابع و فقط با
     * دکمه‌های شیشه‌ای انجام می‌شود (بدون کیبورد و بدون دریافت متن).
     */
    public static function muteRoute(string $data, array $cb): void
    {
        $tgId   = (int)($cb['from']['id'] ?? 0);
        $chatId = (int)($cb['message']['chat']['id'] ?? $tgId);
        $msgId  = (int)($cb['message']['message_id'] ?? 0);
        $cbId   = (string)($cb['id'] ?? '');
        if ($tgId <= 0) return;

        $act  = substr($data, 5);
        $back = [Tg::btn('⬅️ بازگشت', 'mute:home')];

        if ($act === 'home') {
            if ($cbId !== '') Tg::answerCb($cbId);
            self::muteStart($tgId);
            return;
        }

        if (!class_exists('Reseller') || !Reseller::enabled() || !Reseller::requestsOpen()) {
            if ($cbId !== '') Tg::answerCb($cbId, '⚠️ ثبت درخواست ن��ایندگی فعلاً امکان‌پذیر نیست.', true);
            return;
        }

        $u = DB::one('SELECT * FROM {p}users WHERE tg_id = :t', [':t' => $tgId]);
        if (!$u) {
            if ($cbId !== '') Tg::answerCb($cbId, 'ابتدا دستور /start را بزنید.', true);
            return;
        }

        if (Reseller::isReseller($u)) {
            if ($cbId !== '') Tg::answerCb($cbId, '✅ شما از قبل نماینده هستید.', true);
            return;
        }

        if ($cbId !== '') Tg::answerCb($cbId);

        if (Reseller::hasRequest($u)) {
            $t = "🕓 <b>درخواست نمایندگی</b>\n"
               . '<code>─────────────────</code>' . "\n"
               . "درخواست شما ثبت شده و در انتظار بررسی مدیر است.\n"
               . 'نتیجه از همین ربات به شما اطلاع داده می‌شود.';
            Tg::edit($chatId, $msgId, $t, Tg::ikb([$back]));
            return;
        }

        $fee = Reseller::reqFee();
        $bal = (int)($u['balance'] ?? 0);

        if ($act === 'rsgo') {
            $res = $fee > 0
                ? Reseller::payRequest($u, 'ثبت از حالت فقط اپلیکیشن')
                : Reseller::request($u, 'ثبت از حالت فقط اپلیکیشن');

            $okv = !empty($res['ok']);
            $msg = trim((string)($res['message'] ?? ''));
            if ($msg === '') $msg = $okv ? '✅ درخواست شما ثبت شد.' : '⚠️ انجام نشد؛ بعداً تلاش کنید.';
            Tg::edit($chatId, $msgId, $msg, Tg::ikb([$back]));
            return;
        }

        /* mute:rsreq – کارت معرفی و پرداخت */
        $l = [];
        $l[] = '🏷 <b>درخواست ن��ایندگی</b>';
        $l[] = '<code>─────────────────</code>';
        if ($fee > 0) {
            $l[] = '💰 هزینهٔ فعال‌سازی: <b>' . money($fee) . ' ' . currency() . '</b>';
            $l[] = '👛 موجودی شما: <b>' . money($bal) . ' ' . currency() . '</b>';
            $l[] = '';
            $l[] = Reseller::reqFeeCredit()
                ? '🎁 این مبلغ پس از فعال‌سازی به اعتبار شما برگردانده می‌شود.'
                : 'ℹ️ این مبلغ هزینهٔ فعال‌سازی است و بازگردانده نمی‌شود.';
            $l[] = Reseller::reqAuto()
                ? '⚡️ پس از پرداخت، نمایندگی فوراً فعال می‌شود.'
                : '🕓 پس از پرداخت، درخواست شما برای بررسی مدیر ثبت می‌شود.';
        } else {
            $l[] = 'با ثبت درخواست، مدیر آن را بررسی می‌کند و نتیجه به شما اطلاع داده می‌شود.';
        }

        $rows = [];
        if ($fee > 0 && $bal < $fee) {
            $l[] = '';
            $l[] = '⚠️ موجودی کافی نیست؛ اول از داخل اپلیکیشن کیف پول را شارژ کنید.';
            if (self::miniappOn()) {
                $rows[] = [['text' => '💳 شارژ کیف پول', 'web_app' => ['url' => self::miniappUrl()]]];
            }
        } else {
            $rows[] = [Tg::btn($fee > 0 ? '✅ پرداخت و ثبت درخواست' : '✅ ثبت درخواست', 'mute:rsgo')];
        }
        $rows[] = $back;

        Tg::edit($chatId, $msgId, implode("\n", $l), Tg::ikb($rows));
    }

    /** آدرس مینی اپ پنل نمایندگی */
    public static function resellerAppUrl(): string
    {
        $u = class_exists('Btn') ? Btn::miniappUrl() : '';
        if ($u === '' && function_exists('app_url')) {
            $base = rtrim((string)app_url(), '/');
            if ($base !== '') $u = $base . '/miniapp/';
        }
        if (strpos($u, 'https://') !== 0) return '';

        /* اگر آدرس به فایل خاصی اشاره دارد، همان پوشه را ملاک می گیریم */
        $u = preg_replace('~/[^/]*\.php(\?.*)?$~', '/', $u) ?? $u;
        return rtrim($u, '/') . '/reseller.php';
    }

    public static function miniappBtn(): ?array
    {
        if (!self::miniappOn()) return null;
        if ((string)DB::setting('miniapp_show_menu', '1') !== '1') return null;
        return ['text' => self::miniappLabel(), 'web_app' => ['url' => self::miniappUrl()]];
    }

    /**
     * دکمهٔ مینی‌اپ برای بخش‌های داخلی (کیف پول، حساب کاربری و …)
     *
     * کلید miniapp_show_menu فقط مربوط به «منوی اصلی» است؛ اگر همان را اینجا هم
     * ملاک بگیریم، دکمهٔ «اپلیکیشن» داخل منوی شارژ کیف پول و حساب کاربری
     * هم ظاهر می‌شود که اضافی است. پس کلید جداگانه دارد و پیش‌فرض خاموش است.
     */
    public static function miniappSectionBtn(): ?array
    {
        if ((string)DB::setting('miniapp_in_sections', '0') !== '1') return null;
        return self::miniappBtn();
    }

    /** ثبت خودکار دکمهٔ منوی ربات روی مینی‌اپ */
    public static function setMenuButton(): array
    {
        if (!self::miniappOn()) {
            return (array)Tg::api('setChatMenuButton', ['menu_button' => ['type' => 'commands']]);
        }
        return (array)Tg::api('setChatMenuButton', [
            'menu_button' => [
                'type'    => 'web_app',
                'text'    => self::miniappLabel(),
                'web_app' => ['url' => self::miniappUrl()],
            ],
        ]);
    }

    /* ==================== سیستم معرفی (رفرال) ==================== */

    /* ==================== انبار ملی ==================== */

    public static function sectionStock($chatId, $msgId = null): void
    {
        if (!class_exists('Stock') || !Stock::enabled()) {
            Tg::send($chatId, '⚠️ این بخش در حال حاضر غیرفعال است.', self::kbMain());
            return;
        }

        $cats = Stock::shopCats();
        $l    = [];
        $l[]  = Stock::icon() . ' <b>' . h(Stock::title()) . '</b>';

        $note = trim(Stock::note());
        if ($note !== '') { $l[] = ''; $l[] = h($note); }

        $rows = [];
        if ($cats === []) {
            $l[] = '';
            $l[] = '📭 در حال حاضر مو��ودی برای فروش وجود ندارد.';
        } else {
            $l[] = '';
            $l[] = 'یکی از بسته‌های زیر را انتخاب کنید:';
            foreach ($cats as $c) {
                $free = Stock::freeCount((int)$c['id']);
                if ($free < 1 && !Stock::showEmpty()) continue;
                $ic  = trim((string)($c['icon'] ?? '')) !== '' ? (string)$c['icon'] : Stock::kindIcon((string)$c['kind']);
                $lbl = $ic . ' ' . (string)$c['name'] . ' — ' . money((int)$c['price']) . ' ' . currency();
                if ($free < 1) $lbl .= ' (ناموجود)';
                $rows[] = [Tg::btn($lbl, 'stk:c:' . (int)$c['id'])];
            }
        }

        $rows[] = [Tg::btn('🎒 خریدهای من', 'stk:my')];
        $rows[] = [Tg::btn('🏠 منوی اصلی', 'menu:main')];

        $txt = implode("\n", $l);
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    public static function stockCat($chatId, $msgId, int $catId): void
    {
        if (!class_exists('Stock') || !Stock::enabled()) return;

        $c = Stock::cat($catId);
        if ($c === null || (int)($c['active'] ?? 0) !== 1) {
            Tg::send($chatId, '⚠️ این بسته در دسترس نیست.', self::kbMain());
            return;
        }

        $free = Stock::freeCount($catId);
        $ic   = trim((string)($c['icon'] ?? '')) !== '' ? (string)$c['icon'] : Stock::kindIcon((string)$c['kind']);

        $l   = [];
        $l[] = $ic . ' <b>' . h((string)$c['name']) . '</b>';
        $l[] = '';
        $l[] = '🏷 نوع: ' . Stock::kindLabel((string)$c['kind']);
        if ((float)($c['volume_gb'] ?? 0) > 0) $l[] = '📦 حجم: ' . fa_num((string)(float)$c['volume_gb']) . ' گیگابایت';
        if ((int)($c['days'] ?? 0) > 0)        $l[] = '⏳ مدت: ' . fa_num((string)(int)$c['days']) . ' روز';
        $l[] = '💰 قیمت: ' . money((int)$c['price']) . ' ' . currency();
        $l[] = '📊 موجودی: ' . ($free > 0 ? fa_num((string)$free) . ' عدد' : 'ناموجود');

        $desc = trim((string)($c['description'] ?? ''));
        if ($desc !== '') { $l[] = ''; $l[] = h($desc); }

        $l[] = '';
        $l[] = '👛 موجودی شما: ' . money((int)(self::$u['balance'] ?? 0)) . ' ' . currency();

        $rows = [];
        if ($free > 0) $rows[] = [Tg::btn('🛒 خرید و دریافت فوری', 'stk:b:' . $catId)];
        $rows[] = [Tg::btn('🔙 بازگشت', 'stk:home')];

        $txt = implode("\n", $l);
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    public static function stockBuy($chatId, $msgId, $cbId, int $catId): void
    {
        if (!class_exists('Stock') || !Stock::enabled()) {
            Tg::answerCb($cbId, 'این بخش غیرفعال است.', true);
            return;
        }

        $r = Stock::buy((array)self::$u, $catId);

        if (empty($r['ok'])) {
            Tg::answerCb($cbId, mb_substr((string)($r['message'] ?? 'خرید انجام نشد.'), 0, 190), true);
            if ((string)($r['need'] ?? '') === 'charge') self::sectionWallet($chatId);
            return;
        }

        Tg::answerCb($cbId, '✅ خرید با موفقیت انجام شد.');
        self::$u['balance'] = max(0, (int)(self::$u['balance'] ?? 0) - (int)($r['price'] ?? 0));

        if (!empty($r['item'])) {
            Stock::deliver($chatId, (array)$r['item'], isset($r['cat']) ? (array)$r['cat'] : null);
        }

        self::sectionStock($chatId);
    }

    public static function stockMine($chatId, $msgId = null): void
    {
        if (!class_exists('Stock')) return;

        $uid  = (int)(self::$u['id'] ?? 0);
        $list = $uid > 0 ? Stock::myItems($uid, 30) : [];

        $l   = [];
        $l[] = '🎒 <b>خریدهای من</b>';
        $l[] = '';

        $rows = [];
        if ($list === []) {
            $l[] = 'هنوز از این بخش خریدی نداشته‌اید.';
        } else {
            $l[] = 'برای دریافت مجدد، روی هر مورد بزنید:';
            foreach ($list as $it) {
                $nm = trim((string)($it['title'] ?? ''));
                if ($nm === '') $nm = trim((string)($it['cat_name'] ?? ''));
                if ($nm === '') $nm = Stock::kindLabel((string)$it['kind']);
                $rows[] = [Tg::btn(Stock::kindIcon((string)$it['kind']) . ' ' . mb_substr($nm, 0, 40), 'stk:r:' . (int)$it['id'])];
            }
        }

        $rows[] = [Tg::btn('🔙 بازگشت', 'stk:home')];

        $txt = implode("\n", $l);
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    public static function stockResend($chatId, $cbId, int $itemId): void
    {
        if (!class_exists('Stock')) return;

        $it  = Stock::item($itemId);
        $uid = (int)(self::$u['id'] ?? 0);

        if ($it === null || $uid < 1 || (int)($it['user_id'] ?? 0) !== $uid) {
            Tg::answerCb($cbId, 'این مورد در دسترس شما نیست.', true);
            return;
        }

        Tg::answerCb($cbId, '📤 ارسال شد.');
        Stock::deliver($chatId, $it, Stock::cat((int)($it['cat_id'] ?? 0)));
    }

    public static function stockCb($chatId, $msgId, $cbId, $arg, $arg2): void
    {
        $a = (string)$arg;

        if ($a === 'c')  { Tg::answerCb($cbId); self::stockCat($chatId, $msgId, (int)$arg2); return; }
        if ($a === 'b')  { self::stockBuy($chatId, $msgId, $cbId, (int)$arg2); return; }
        if ($a === 'my') { Tg::answerCb($cbId); self::stockMine($chatId, $msgId); return; }
        if ($a === 'r')  { self::stockResend($chatId, $cbId, (int)$arg2); return; }

        Tg::answerCb($cbId);
        self::sectionStock($chatId, $msgId);
    }

    public static function sectionReferral($chatId, $msgId = null): void
    {
        if (!class_exists('Referral') || !Referral::enabled()) {
            Tg::send($chatId, '⚠️ سیستم معرفی در حال حاضر غیرفعال است.', self::kbMain());
            return;
        }

        $st = Referral::stats((array)self::$u);

        $l   = [];
        $l[] = '👥 <b>د��وت از دوستان</b>';
        $l[] = '';

        if (!empty($st['link'])) {
            $l[] = '🔗 لینک اختصاصی شما:';
            $l[] = '<code>' . h((string)$st['link']) . '</code>';
            $l[] = '';
            $l[] = 'هر کس با این لینک ربات را استارت کند، زیرمجموعهٔ شما می‌شو��.';
        } else {
            $l[] = '⚠️ لینک معرفی در دسترس نیست. مدیر باید نام کاربری ربات را تنظیم کند.';
        }

        $l[] = '';
        $l[] = '📊 <b>آمار شما</b>';
        $l[] = '• زیرمجموعه: ' . fa_num((string)(int)($st['count'] ?? 0)) . ' نفر';
        $l[] = '• شارژ‌کرده: ' . fa_num((string)(int)($st['active'] ?? 0)) . ' نفر';
        if ((int)($st['l2'] ?? 0) > 0) {
            $l[] = '• غیرمستقیم (سطح ۲): ' . fa_num((string)(int)$st['l2']) . ' نفر';
        }
        $l[] = '• درآمد معرفی: ' . money((int)($st['earned'] ?? 0)) . ' ' . currency();

        $l[] = '';
        $l[] = '🎁 <b>شرایط پاداش</b>';
        $any = false;
        if ((float)($st['bonus'] ?? 0) > 0) {
            $l[] = '• پاداش اولین شارژ هر زیرمجموعه: ' . fa_num((string)$st['bonus']) . '٪';
            $any = true;
        }
        if ((float)($st['pct'] ?? 0) > 0) {
            $l[] = '• پورسانت دائمی سطح ۱: ' . fa_num((string)$st['pct']) . '٪ ��ز هر شارژ';
            $any = true;
        }
        if ((float)($st['pct_l2'] ?? 0) > 0) {
            $l[] = '• پورسانت سطح ۲: ' . fa_num((string)$st['pct_l2']) . '٪';
            $any = true;
        }
        if ((int)($st['min'] ?? 0) > 0) {
            $l[] = '• حداقل مبلغ شارژ برای پورسانت: ' . money((int)$st['min']) . ' ' . currency();
        }
        if (!empty($st['first_only'])) {
            $l[] = '• پورسانت درصدی فقط روی اولین شارژ پرداخت می‌شود.';
        }
        if (!$any) $l[] = '• فعلا پاداشی تعیین نشده است.';

        $rows = [];
        if (!empty($st['share'])) $rows[] = [Tg::url('📤 ارسال به دوستان', (string)$st['share'])];
        $rows[] = [Tg::btn('👥 لیست زیرمجموعه‌ها', 'ref:list')];
        if ($mb = self::miniappSectionBtn()) $rows[] = [$mb];
        $rows[] = Kb::backRow('menu:wallet');

        $txt = implode(chr(10), $l);
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows))
               : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    public static function referralList($chatId, $msgId = null): void
    {
        $inv = class_exists('Referral')
            ? Referral::invitees((int)(self::$u['tg_id'] ?? 0), 20)
            : [];

        $back = Tg::ikb([Kb::backRow('menu:ref')]);

        if (!$inv) {
            $txt = '📫 هنوز کسی با لینک معرفی شما ثبت‌نام نکرده است.';
            $msgId ? Tg::edit($chatId, $msgId, $txt, $back) : Tg::send($chatId, $txt, $back);
            return;
        }

        $l = ['👥 <b>زیرمجموعه‌های شما</b>', ''];
        $i = 0;
        foreach ($inv as $v) {
            $i++;
            $l[] = fa_num((string)$i) . '. ' . h((string)($v['name'] ?? '-'))
                 . (!empty($v['active']) ? ' ✅' : ' ⏳')
                 . ' — ' . h((string)($v['paid_txt'] ?? '0')) . ' ' . currency();
        }
        $l[] = '';
        $l[] = '✅ = حداقل یک شارژ تاییدشده دارد';

        $txt = implode(chr(10), $l);
        $msgId ? Tg::edit($chatId, $msgId, $txt, $back) : Tg::send($chatId, $txt, $back);
    }
    /* ============ کانفیگ‌های نمایندگی (جدا از سرویس‌های شخصی) ============ */

    private static function gauge(int $pct): string
    {
        $pct  = max(0, min(100, $pct));
        $full = (int)round($pct / 10);
        if ($full < 0)  $full = 0;
        if ($full > 10) $full = 10;
        return str_repeat('▓', $full) . str_repeat('░', 10 - $full);
    }

    private static function resellerService(int $id): ?array
    {
        return DB::one('SELECT * FROM {p}services WHERE id = :id AND user_id = :u AND status <> :d
            AND COALESCE(is_reseller, 0) = 1',
            [':id' => $id, ':u' => (int)self::$u['id'], ':d' => 'deleted']);
    }

    private static function sectionResellerServices($chatId, $msgId = null): void
    {
        $list = Svc::resellerForUser((int)self::$u['id']);
        if (!$list) {
            $txt = "🧩 <b>کانفیگ‌های نمایندگی</b>\n\nهنوز از پنل نمایندگی کانفیگی نساخته‌اید.\nبرای ساخت کانفیگ، پنل نمایندگی را باز کنید.";
            $kb  = Tg::ikb([Kb::navRow('rs:panel')]);
            $msgId ? Tg::edit($chatId, $msgId, $txt, $kb) : Tg::send($chatId, $txt, $kb);
            return;
        }
        $rows = [];
        $act  = 0;
        foreach ($list as $sv) {
            if ((string)($sv['status'] ?? '') === 'active') $act++;
            $icon = $sv['status'] === 'active' ? '✅' : ($sv['status'] === 'expired' ? '⏳' : '⛔️');
            $left = (float)$sv['volume_gb'] > 0
                ? fa_num((string)max(0, round((float)$sv['volume_gb'] - bytes2gb((int)$sv['used_bytes']), 1))) . 'گیگ'
                : 'نامحدود';
            $rows[] = [Tg::btn($icon . ' ' . $sv['client_email'] . ' | ' . $left . ' | ' . remaining_human($sv['expire_at']), 'rssvc:' . $sv['id'])];
        }
        $rows[] = Kb::navRow('rs:panel');
        $txt  = "🧩 <b>کانفیگ‌های نمایندگی من</b>\n";
        $txt .= '<code>' . str_repeat('─', 16) . '</code>' . "\n";
        $txt .= '📦 مجموع: ' . fa_num(count($list)) . '   ✅ فعال: ' . fa_num($act) . "\n\n";
        $txt .= 'برای دیدن جزئیات، یکی از کانفیگ‌ها را انتخاب کنید:';
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    private static function showResellerService($chatId, $msgId, int $id): void
    {
        $sv = self::resellerService($id);
        if (!$sv) { Tg::send($chatId, '⚠️ این کانفیگ در پنل نمایندگی شما پیدا نشد.'); return; }
        $txt  = "🧩 <b>جزئیات کانفیگ نمایندگی</b>\n";
        $txt .= '<code>' . str_repeat('─', 16) . '</code>' . "\n";
        $txt .= self::specCard($sv, false) . "\n";
        $sub = Svc::subUrl($sv);
        if ($sub !== '') {
            $txt .= "\n🔗 <b>لینک اشتراک این کانفیگ</b>\n" . '<code>' . h($sub) . '</code>' . "\n";
        }
        $txt .= "\n<i>ویرایش حجم، حذف و عودت وجه این کانفیگ از پنل نمایندگی انجام می‌شود.</i>";
        $rows = [
            [Tg::btn('🧩 همهٔ کانفیگ‌های نمایندگی', 'rs:svcs')],
            Kb::navRow('rs:panel'),
        ];
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

}
