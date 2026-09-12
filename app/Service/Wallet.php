<?php
declare(strict_types=1);

/**
 * کیف پول و پرداخت‌ها (کارت به کارت / ارزی)
 */
class Wallet
{
    /* fixed84: روش‌های پرداخت خودکار (درگاه) — رسید دستی ندارند و نباید در صف تایید مدیر بیایند */
    public const AUTO_METHODS = ['hooshpay', 'nowpay'];

    /** آیا این روش پرداخت، درگاه خودکار است؟ */
    public static function isAuto(?string $method): bool
    {
        return in_array(strtolower(trim((string)$method)), self::AUTO_METHODS, true);
    }

    /** لیست روش‌های خودکار برای شرط‌های SQL */
    public static function autoSqlList(): string
    {
        return "'" . implode("','", self::AUTO_METHODS) . "'";
    }

    public static function balance(int $userId): int
    {
        return (int)DB::val('SELECT balance FROM {p}users WHERE id = :id', [':id' => $userId], 0);
    }

    public static function credit(int $userId, int $amount, string $type = 'deposit', string $method = 'admin', string $note = '', ?int $adminId = null): int
    {
        $pdo = DB::pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            // قفل ردیف کاربر تا شارژهای هم‌زمان روی هم نیفتند
            $u = DB::one('SELECT * FROM {p}users WHERE id = :id FOR UPDATE', [':id' => $userId]);
            if (!$u) { if ($own) $pdo->rollBack(); return 0; }

            DB::q('UPDATE {p}users SET balance = balance + :a WHERE id = :id', [':a' => $amount, ':id' => $userId]);
            if ($type === 'deposit') {
                DB::q('UPDATE {p}users SET total_paid = total_paid + :a WHERE id = :id', [':a' => $amount, ':id' => $userId]);
            }
            $txId = DB::insert('transactions', [
                'user_id' => $userId, 'tg_id' => (int)$u['tg_id'], 'type' => $type, 'method' => $method,
                'amount' => $amount, 'status' => 'approved', 'note' => mb_substr($note, 0, 250),
                'admin_id' => $adminId, 'ref' => 'CR-' . strtoupper(rnd(8)), 'created_at' => now(), 'decided_at' => now(),
            ]);
            if ($own) $pdo->commit();
            return $txId;
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            app_log('wallet', 'credit failed: ' . $e->getMessage());
            return 0;
        }
    }

    public static function debit(int $userId, int $amount, string $note = '', string $type = 'purchase'): bool
    {
        if ($amount <= 0) return false;

        $pdo = DB::pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            // قفل ردیف کاربر تا دو خرید هم‌زمان موجودی را منفی نکند (race condition)
            $u = DB::one('SELECT * FROM {p}users WHERE id = :id FOR UPDATE', [':id' => $userId]);
            if (!$u || (int)$u['balance'] < $amount) { if ($own) $pdo->rollBack(); return false; }

            // شرط balance >= :a2 لایهٔ دوم محافظت در سطح خودِ دیتابیس است
            $n = DB::q('UPDATE {p}users SET balance = balance - :a WHERE id = :id AND balance >= :a2',
                [':a' => $amount, ':id' => $userId, ':a2' => $amount])->rowCount();
            if ($n !== 1) { if ($own) $pdo->rollBack(); return false; }

            DB::insert('transactions', [
                'user_id' => $userId, 'tg_id' => (int)$u['tg_id'], 'type' => $type, 'method' => 'wallet',
                'amount' => -1 * $amount, 'status' => 'approved', 'note' => mb_substr($note, 0, 250),
                'ref' => 'DB-' . strtoupper(rnd(8)), 'created_at' => now(), 'decided_at' => now(),
            ]);
            if ($own) $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            app_log('wallet', 'debit failed: ' . $e->getMessage());
            return false;
        }
    }

    /** درخواست شارژ در انتظار تایید */
    public static function createDeposit(array $user, int $amount, string $method, array $extra = []): int
    {
        return DB::insert('transactions', [
            'user_id' => (int)$user['id'], 'tg_id' => (int)$user['tg_id'], 'type' => 'deposit',
            'method' => $method, 'amount' => $amount, 'status' => 'pending',
            'ref' => strtoupper(($method === 'nowpay' ? 'NP-' : ($method === 'crypto' ? 'CX-' : 'CA-')) . rnd(8)),
            'txid' => $extra['txid'] ?? null,
            'receipt_file' => $extra['receipt_file'] ?? null,
            'note' => $extra['note'] ?? null,
            /* کارت احرازشده‌ای که واریز با آن انجام شده */
            'card_id' => ((int)($extra['card_id'] ?? 0)) ?: null,
            'created_at' => now(),
        ]);
    }

    public static function pending(int $limit = 50): array
    {
        return DB::all('SELECT t.*, u.tg_id, u.first_name, u.username FROM {p}transactions t
            JOIN {p}users u ON u.id = t.user_id
            WHERE t.status = :s AND t.type = :t
              AND t.method NOT IN (' . self::autoSqlList() . ')
            ORDER BY t.id DESC LIMIT ' . (int)$limit,
            [':s' => 'pending', ':t' => 'deposit']);
    }

    public static function approve(int $txId, $adminId = null, bool $notify = true): array
    {
        /* آیدی عددی تلگرامِ مدیر ممکن است از ظرفیت ستون INT در دیتابیس‌های قدیمی بزرگ‌تر باشد؛
           در آن حالت UPDATE با خطای Out of range برمی‌گشت و تراکنش «در انتظار» می‌ماند. */
        if (is_numeric($adminId) && (float)$adminId > 2147483647) $adminId = 0;

        $tx = DB::one('SELECT * FROM {p}transactions WHERE id = :id', [':id' => $txId]);
        if (!$tx) return ['ok' => false, 'message' => 'تراکنش یافت نشد.'];
        if ($tx['status'] !== 'pending') return ['ok' => false, 'message' => 'این تراکنش قبلاً بررسی شده است.'];

        // تغییر وضعیت و شارژ کیف پول داخل «یک تراکنش دیتابیس» انجام می‌شود تا اگر
        // اجرا وسط کار قطع شود، تراکنشِ «تاییدشده ولی شارژنشده» باقی نماند.
        $pdo = DB::pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            // تغییر وضعیت به‌صورت اتمیک: اگر مدیر دیگری هم‌زمان همین تراکنش را تایید کند،
            // rowCount صفر می‌شود و کیف پول دوبار شارژ نمی‌گردد.
            try {
                $n = DB::q("UPDATE {p}transactions SET `status` = 'approved', `admin_id` = :ad, `decided_at` = :dt
                            WHERE `id` = :id AND `status` = 'pending'",
                    [':ad' => $adminId, ':dt' => now(), ':id' => $txId])->rowCount();
            } catch (Throwable $eAd) {
                /* fixed84: اگر ستون admin_id قدیمی (INT) باشد، تایید نباید شکست بخورد
                   و رسید در پنل وب «در انتظار» بماند */
                app_log('wallet', 'approve admin_id fallback: ' . $eAd->getMessage());
                $n = DB::q("UPDATE {p}transactions SET `status` = 'approved', `admin_id` = NULL, `decided_at` = :dt
                            WHERE `id` = :id AND `status` = 'pending'",
                    [':dt' => now(), ':id' => $txId])->rowCount();
            }
            if ($n !== 1) {
                if ($own && $pdo->inTransaction()) $pdo->rollBack();
                return ['ok' => false, 'message' => 'این تراکنش هم‌اکنون توسط مدیر دیگری بررسی شد.'];
            }

            DB::q('UPDATE {p}users SET balance = balance + :a, total_paid = total_paid + :a2 WHERE id = :id',
                [':a' => (int)$tx['amount'], ':a2' => (int)$tx['amount'], ':id' => (int)$tx['user_id']]);

            if ($own) $pdo->commit();
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            app_log('wallet', 'approve failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'خطا در ثبت تایید تراکنش؛ لطفاً دوباره تلاش کنید.'];
        }

        // پاداش معرفی
        $bonus = (int)DB::setting('referral_bonus', 0);
        if ($bonus > 0) {
            $u = DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => (int)$tx['user_id']]);
            $firstDeposit = (int)DB::val('SELECT COUNT(*) FROM {p}transactions WHERE user_id = :u AND type = :t AND status = :s',
                [':u' => (int)$tx['user_id'], ':t' => 'deposit', ':s' => 'approved'], 0);
            if ($u && !empty($u['referrer_id']) && $firstDeposit <= 1) {
                $ref = DB::one('SELECT * FROM {p}users WHERE tg_id = :t', [':t' => (int)$u['referrer_id']]);
                if ($ref) {
                    $amount = (int)round((int)$tx['amount'] * $bonus / 100);
                    if ($amount > 0) {
                        self::credit((int)$ref['id'], $amount, 'referral', 'admin', 'پاداش معرفی کاربر');
                        Tg::send((int)$ref['tg_id'], "🎁 پاداش معرفی\nمبلغ " . money($amount) . ' ' . currency() . ' به کیف پول شما افزوده شد.');
                        Logs::send('referral', Logs::fmt('🎁 پرداخت پورسانت معرفی', [
                            'معرف' => ($ref['first_name'] ?? '-') . ' (' . (int)$ref['tg_id'] . ')',
                            'کاربر جدید' => (int)$u['tg_id'],
                            'مبلغ پورسانت' => money($amount) . ' ' . currency(),
                            'درصد' => fa_num((string)$bonus) . '٪',
                        ]));
                    }
                }
            }
        }
        // پورسانت درصدی دوسطحی سیستم معرفی (جدا از پاداش اولین شارژ)
        if (class_exists('Referral')) {
            try {
                $depN = (int)DB::val(
                    'SELECT COUNT(*) FROM {p}transactions WHERE user_id = :u AND type = :t AND status = :s',
                    [':u' => (int)$tx['user_id'], ':t' => 'deposit', ':s' => 'approved'], 0);
                Referral::reward((int)$tx['user_id'], (int)$tx['amount'], $depN <= 1);
            } catch (Throwable $e) {
                app_log('referral', 'reward hook failed: ' . $e->getMessage());
            }
        }

        self::logDecision($tx, 'approved', $adminId);
        /* fixed84: مهر «تایید شده» روی کارت رسید + حذف دکمه‌ها + خبر دادن به کاربر */
        $stamped = self::stampAdminCards($tx, 'approved', $adminId);
        if ($notify) self::notifyDecision($tx, 'approved');
        return ['ok' => true, 'message' => 'تراکنش تایید و کیف پول شارژ شد.', 'tx' => $tx, 'stamped' => $stamped];
    }

    public static function reject(int $txId, $adminId = null, string $reason = '', bool $notify = true): array
    {
        if (is_numeric($adminId) && (float)$adminId > 2147483647) $adminId = 0;

        $tx = DB::one('SELECT * FROM {p}transactions WHERE id = :id', [':id' => $txId]);
        if (!$tx) return ['ok' => false, 'message' => 'تراکنش یافت نشد.'];
        if ($tx['status'] !== 'pending') return ['ok' => false, 'message' => 'این تراکنش قبلاً بررسی شده است.'];
        DB::update('transactions', [
            'status' => 'rejected', 'admin_id' => $adminId, 'decided_at' => now(),
            'note' => mb_substr(trim(((string)$tx['note']) . ' | رد: ' . $reason), 0, 250),
        ], 'id = :id', [':id' => $txId]);
        self::logDecision($tx, 'rejected', $adminId, $reason);
        /* fixed84: مهر «رد شده» روی کارت رسید + حذف دکمه‌ها + خبر دادن به کاربر */
        $stamped = self::stampAdminCards($tx, 'rejected', $adminId, $reason);
        if ($notify) self::notifyDecision($tx, 'rejected', $reason);
        return ['ok' => true, 'message' => 'تراکنش رد شد.', 'tx' => $tx, 'stamped' => $stamped];
    }

    /* ==================== گزارش تصمیم تراکنش در گروه لاگ ==================== */

    /** دکمه‌های مدیریتی زیر گزارش تراکنش */
    public static function txButtons(array $tx, string $action): array
    {
        $uid  = (int)($tx['user_id'] ?? 0);
        $usr  = $uid ? DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => $uid]) : null;
        $un   = trim((string)($usr['username'] ?? ($tx['username'] ?? '')));
        $tgId = (int)($usr['tg_id'] ?? ($tx['tg_id'] ?? 0));
        $link = $un !== '' ? 'https://t.me/' . ltrim($un, '@') : 'tg://user?id=' . $tgId;

        $rows = [];
        if ($action === 'approved') {
            $rows[] = [Tg::btn('↩️ لغو تایید و برگشت مبلغ', 'adm:undo:' . (int)($tx['id'] ?? 0))];
        }
        $rows[] = [
            Tg::btn('🚫 مسدود کردن کاربر', 'adm:ban:' . $uid),
            Tg::url('💬 ارتباط با کاربر', $link),
        ];
        return $rows;
    }

    /** ثبت تایید/رد تراکنش در تاپیک مالی همراه دکمه‌های اقدام */
    public static function logDecision(array $tx, string $action, $adminId = null, string $reason = ''): void
    {
        if (!class_exists('Logs')) return;
        try {
            $uid = (int)($tx['user_id'] ?? 0);
            $usr = $uid ? DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => $uid]) : null;

            $methods = [
                'card'    => 'کارت به کارت',
                'crypto'  => 'ارزی (دستی)',
                'nowpay'  => 'نوپیمنتس (خودکار)',
                'wallet'  => 'کیف پول',
            ];
            $mk = (string)($tx['method'] ?? '');
            $by = $adminId ? ('مدیر ' . $adminId) : '🤖 هش‌چکر خودکار';

            $kv = [
                'شناسه تراکنش' => '#' . (int)($tx['id'] ?? 0),
                'کاربر'        => (string)($usr['first_name'] ?? '-') . ' (' . (int)($usr['tg_id'] ?? ($tx['tg_id'] ?? 0)) . ')',
                'یوزرنیم'      => trim((string)($usr['username'] ?? '')) !== '' ? '@' . (string)$usr['username'] : '-',
                'مبلغ'         => money((int)($tx['amount'] ?? 0)) . ' ' . currency(),
                'روش'          => $methods[$mk] ?? $mk,
                'کد رهگیری'    => (string)($tx['ref'] ?? '-'),
                'تصمیم‌گیرنده' => $by,
            ];
            if (trim((string)($tx['txid'] ?? '')) !== '') {
                $kv['هش تراکنش'] = mb_substr((string)$tx['txid'], 0, 60);
            }
            if ($action === 'rejected' && trim($reason) !== '') {
                $kv['علت رد'] = mb_substr($reason, 0, 120);
            }
            if ($usr) {
                $kv['موجودی فعلی'] = money((int)($usr['balance'] ?? 0)) . ' ' . currency();
            }

            $title  = $action === 'approved' ? '✅ تراکنش تایید شد' : '❌ تراکنش رد شد';
            $footer = $action === 'approved'
                ? '⚠️ اگر این پرداخت اشتباه تایید شده، با دکمهٔ زیر آن ��ا لغو کنید.'
                : 'ℹ️ مبلغی به کیف پول کاربر اضافه نشد.';

            Logs::send('financial', Logs::fmt($title, $kv, $footer), [
                'reply_markup' => jenc(Tg::ikb(self::txButtons($tx, $action))),
            ]);
        } catch (Throwable $e) {
            app_log('wallet_log_decision: ' . $e->getMessage());
        }
    }

    /** لغو تاییدِ اشتباه و برگشت مبلغ از کیف پول */
    public static function undo(int $txId, $adminId = null): array
    {
        if (is_numeric($adminId) && (float)$adminId > 2147483647) $adminId = 0;

        $tx = DB::one('SELECT * FROM {p}transactions WHERE id = :id', [':id' => $txId]);
        if (!$tx)                                  return ['ok' => false, 'message' => 'تراکنش یافت نشد.'];
        if ((string)$tx['status'] !== 'approved')  return ['ok' => false, 'message' => 'فقط تراکنش تاییدشده قابل لغو است.'];
        if ((string)$tx['type'] !== 'deposit')     return ['ok' => false, 'message' => 'فقط شارژ کیف پول قابل لغو است.'];

        $amt = (int)$tx['amount'];
        if ($amt <= 0) return ['ok' => false, 'message' => 'مبلغ این تراکنش قابل برگشت نیست.'];

        // کل عملیات لغو (تغییر وضعیت + کسر موجودی) داخل یک تراکنش دیتابیس اتمیک است
        $pdo = DB::pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            // قفل اتمیک: اگر دو مدیر هم‌زمان لغو کنند، مبلغ دوبار کسر نمی‌شود
            $locked = DB::q("UPDATE {p}transactions SET `status` = 'reverting' WHERE `id` = :id AND `status` = 'approved'",
                [':id' => $txId])->rowCount();
            if ($locked !== 1) {
                if ($own && $pdo->inTransaction()) $pdo->rollBack();
                return ['ok' => false, 'message' => 'این تراکنش هم‌اکنون توسط مدیر دیگری تغییر کرد.'];
            }

            DB::q('UPDATE {p}users SET balance = balance - :a, total_paid = GREATEST(0, total_paid - :a2) WHERE id = :id',
                [':a' => $amt, ':a2' => $amt, ':id' => (int)$tx['user_id']]);

            DB::update('transactions', [
                'status'     => 'rejected',
                'admin_id'   => $adminId,
                'decided_at' => now(),
                'note'       => mb_substr(trim(((string)$tx['note']) . ' | لغو تایید توسط مدیر'), 0, 250),
            ], 'id = :id', [':id' => $txId]);

            if ($own) $pdo->commit();
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            app_log('wallet', 'undo failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'خطا در لغو تایید؛ لطفاً دوباره تلاش کنید.'];
        }

        $bal = (int)DB::val('SELECT balance FROM {p}users WHERE id = :id', [':id' => (int)$tx['user_id']], 0);

        if (class_exists('Logs')) {
            Logs::send('financial', Logs::fmt('↩️ تایید تراکنش لغو شد', [
                'شناسه تراکنش' => '#' . $txId,
                'مبلغ برگشتی'  => money($amt) . ' ' . currency(),
                'موجودی جدید'  => money($bal) . ' ' . currency(),
                'لغوکننده'     => $adminId ? ('مدیر ' . $adminId) : 'سیستم',
            ]));
        }

        self::stampAdminCards($tx, 'reverted', $adminId);
        return ['ok' => true, 'message' => 'تایید لغو شد و مبلغ از کیف پول کسر گردید.', 'tx' => $tx, 'balance' => $bal];
    }

    /**
     * نرخ فعلی هر دلار (خودکار یا دستی)
     * با $fresh = true نرخ همین لحظه از زنجیرهٔ API‌ها گرفته می‌شود (نه کش ساعتی).
     */
    public static function rate(bool $fresh = false): int
    {
        if (class_exists('Rates')) return $fresh ? Rates::liveRate() : Rates::current();
        return max(1, (int)DB::setting('usd_rate', 100000));
    }

    /** تبدیل مبلغ فروشگاه به دلار و برعکس */
    public static function toUsd(int $amount, bool $fresh = false): float
    {
        return round($amount / max(1, self::rate($fresh)), 2);
    }

    public static function fromUsd(float $usd): int
    {
        return (int)round($usd * self::rate());
    }
    /* ==================== fixed84: کارت رسید مدیر ==================== */

    /** شناسهٔ پیام کارت رسیدی که برای مدیر ارسال شده را نگه می‌دارد */
    public static function rememberAdminCard(int $txId, $chatId, $msgId): void
    {
        $msgId = (int)$msgId;
        if ($txId <= 0 || $msgId <= 0) return;
        try {
            $row  = DB::one('SELECT `admin_msg` FROM {p}transactions WHERE id = :i', [':i' => $txId]);
            $list = jdec((string)($row['admin_msg'] ?? ''), []);
            if (!is_array($list)) $list = [];
            foreach ($list as $m) {
                if ((string)($m['c'] ?? '') === (string)$chatId && (int)($m['m'] ?? 0) === $msgId) return;
            }
            $list[] = ['c' => (string)$chatId, 'm' => $msgId];
            if (count($list) > 20) $list = array_slice($list, -20);
            DB::update('transactions', ['admin_msg' => jenc($list)], 'id = :i', [':i' => $txId]);
        } catch (Throwable $e) {
            /* ستون admin_msg در دیتابیس‌های به‌روزنشده وجود ندارد — بی‌اهمیت */
        }
    }

    /**
     * روی همهٔ کارت‌های ارسال‌شده به مدیران مهر «تایید شده / رد شده» می‌زند و
     * دکمه‌های ✅/❌ را حذف می‌کند — چه تصمیم از ربات گرفته شده باشد، چه از پنل وب یا درگاه.
     * @return int تعداد پیام‌هایی که مهر خوردند
     */
    public static function stampAdminCards(array $tx, string $action, $adminId = null, string $reason = ''): int
    {
        $txId = (int)($tx['id'] ?? 0);
        if ($txId <= 0) return 0;

        try {
            $row  = DB::one('SELECT `admin_msg` FROM {p}transactions WHERE id = :i', [':i' => $txId]);
            $list = jdec((string)($row['admin_msg'] ?? ''), []);
        } catch (Throwable $e) {
            return 0;
        }
        if (!is_array($list) || !$list) return 0;

        if ($action === 'approved')     $head = '✅ <b>تایید شده</b>';
        elseif ($action === 'reverted') $head = '↩️ <b>تایید لغو شد</b>';
        else                            $head = '❌ <b>رد شده</b>';

        $by = ((int)$adminId > 0) ? ('مدیر <code>' . (int)$adminId . '</code>') : '🤖 سیستم خودکار';

        $lines = [
            $head,
            '🧾 درخواست شارژ: <code>#' . $txId . '</code>',
            '👤 کاربر: <code>' . (int)($tx['tg_id'] ?? 0) . '</code>',
            '💰 مبلغ: <b>' . money((int)($tx['amount'] ?? 0)) . ' ' . currency() . '</b>',
            '🛠 توسط: ' . $by,
            '🕒 ' . to_jalali(now(), true),
        ];
        if ($action === 'approved') $lines[] = '💳 کیف پول کاربر شارژ شد.';
        if ($action === 'rejected') $lines[] = '🚫 مبلغی به کیف پول اضافه نشد.';
        if ($action === 'reverted') $lines[] = '💸 مبلغ از کیف پول کاربر کسر شد.';
        if (trim($reason) !== '')   $lines[] = '📝 دلیل: ' . h(mb_substr(trim($reason), 0, 150));

        $txt = implode("\n", $lines);
        $kb  = ['inline_keyboard' => ($action === 'approved'
            ? [[Tg::btn('↩️ لغو تایید و برگشت مبلغ', 'adm:undo:' . $txId)]]
            : [])];

        $n = 0;
        foreach ($list as $m) {
            $c = (string)($m['c'] ?? '');
            $i = (int)($m['m'] ?? 0);
            if ($c === '' || $i <= 0) continue;
            try {
                if (Tg::stamp($c, $i, $txt, $kb)) $n++;
            } catch (Throwable $e) {
                /* پیام قدیمی یا حذف‌شده */
            }
        }
        return $n;
    }

    /** اعلام نتیجهٔ بررسی رسید به کاربر — از هر مسیری که تصمیم گرفته شده باشد */
    public static function notifyDecision(array $tx, string $action, string $reason = ''): void
    {
        $chat = (int)($tx['tg_id'] ?? 0);
        if ($chat <= 0) return;
        try {
            if ($action === 'approved') {
                Tg::send($chat, "✅ <b>پرداخت شما تایید شد</b>\n"
                    . '💰 مبلغ ' . money((int)($tx['amount'] ?? 0)) . ' ' . currency() . " به کیف پول شما افزوده شد.\n"
                    . '🧾 شماره پیگیری: <code>#' . (int)($tx['id'] ?? 0) . '</code>');
                return;
            }
            Tg::send($chat, "❌ <b>پرداخت شما تایید نشد</b>\n"
                . (trim($reason) !== '' ? '🔎 علت: ' . h(mb_substr(trim($reason), 0, 150)) . "\n" : '')
                . '🧾 شماره پیگیری: <code>#' . (int)($tx['id'] ?? 0) . "</code>\n"
                . 'در صورت نیاز با پشتیبانی در تماس باشید.');
        } catch (Throwable $e) {
            /* ارسال پیام نباید مانع ثبت تصمیم شود */
        }
    }

}
