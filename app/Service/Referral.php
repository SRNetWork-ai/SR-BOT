<?php
/**
 * Referral — سیستم معرفی (رفرال) پیشرفته
 *
 * قابلیت‌ها:
 *  - لینک اختصاصی معرفی برای هر کاربر: t.me/<bot>?start=ref<tg_id>
 *  - پاداش درصدی اولین شارژ (referral_bonus) — در Wallet::approve
 *  - پورسانت دائمی دوسطحی (referral_percent / referral_l2_percent)
 *  - آمار کامل، لیست زیرمجموعه‌ها و گزارش مدیریتی
 *
 * ⚠️ قرارداد دیتابیس: ستون users.referrer_id مقدار tg_id معرف را نگه می‌دارد (نه users.id).
 * همهٔ پرس‌وجوهای این کلاس بر همین اساس نوشته شده‌اند.
 */
class Referral
{
    /* ==================== تنظیمات ==================== */

    public static function enabled(): bool
    {
        return (string)DB::setting('referral_enabled', '1') === '1';
    }

    /** درصد پاداش اولین شارژ (توسط Wallet::approve اعمال می‌شود) */
    public static function bonus(): float
    {
        return max(0.0, (float)DB::setting('referral_bonus', 0));
    }

    public static function percent(): float
    {
        return max(0.0, min(50.0, (float)DB::setting('referral_percent', 0)));
    }

    public static function percentL2(): float
    {
        return max(0.0, min(50.0, (float)DB::setting('referral_l2_percent', 0)));
    }

    public static function firstOnly(): bool
    {
        return (string)DB::setting('referral_first_only', '0') === '1';
    }

    public static function minAmount(): int
    {
        return max(0, (int)DB::setting('referral_min_amount', 0));
    }

    /** سقف پورسانت هر تراکنش (۰ = بی‌نهایت) */
    public static function capPerTx(): int
    {
        return max(0, (int)DB::setting('referral_cap', 0));
    }

    public static function active(): bool
    {
        return self::enabled() && (self::bonus() > 0 || self::percent() > 0 || self::percentL2() > 0);
    }

    /* ==================== لینک معرفی ==================== */

    /** نام کاربری ربات — یک‌بار از getMe خوانده و در تنظیمات کش می‌شود */
    public static function botUsername(): string
    {
        $u = ltrim(trim((string)DB::setting('bot_username', '')), '@');
        if ($u !== '') return $u;

        try {
            $me = Tg::getMe();
            $un = ltrim(trim((string)($me['result']['username'] ?? '')), '@');
            if ($un !== '') {
                DB::setSetting('bot_username', $un);
                return $un;
            }
        } catch (Throwable $e) {
            app_log('referral', 'getMe failed: ' . $e->getMessage());
        }
        return '';
    }

    /** کد معرفی کاربر — بر پایهٔ tg_id */
    public static function code(array $u): string
    {
        return 'ref' . (int)($u['tg_id'] ?? 0);
    }

    public static function link(array $u): string
    {
        $bot = self::botUsername();
        if ($bot === '' || (int)($u['tg_id'] ?? 0) <= 0) return '';
        return 'https://t.me/' . $bot . '?start=' . self::code($u);
    }

    /** متن آمادهٔ اشتراک‌گذاری */
    public static function shareText(array $u): string
    {
        $shop = (string)DB::setting('shop_title', APP_BRAND);
        $link = self::link($u);

        $txt = '🚀 ' . $shop . chr(10) . 'اینترنت پرسرعت، پرداخت ریالی و تحویل فوری.';
        if (self::bonus() > 0) {
            $txt .= chr(10) . chr(10) . '🎁 با این لینک ثبت‌نام کنید.';
        }
        if ($link !== '') $txt .= chr(10) . chr(10) . $link;
        return $txt;
    }

    /** لینک اشتراک‌گذاری تلگرام */
    public static function shareUrl(array $u): string
    {
        $link = self::link($u);
        if ($link === '') return '';
        return 'https://t.me/share/url?url=' . rawurlencode($link)
            . '&text=' . rawurlencode(self::shareText($u));
    }

    /* ==================== آمار ==================== */

    /** @param array $u ردیف کامل کاربر (نیاز به id و tg_id) */
    public static function stats(array $u): array
    {
        $uid = (int)($u['id'] ?? 0);
        $tg  = (int)($u['tg_id'] ?? 0);

        $out = [
            'enabled'    => self::enabled(),
            'count'      => 0,
            'active'     => 0,
            'l2'         => 0,
            'earned'     => 0,
            'earned_txt' => money(0),
            'bonus'      => self::bonus(),
            'pct'        => self::percent(),
            'pct_l2'     => self::percentL2(),
            'min'        => self::minAmount(),
            'min_txt'    => money(self::minAmount()),
            'first_only' => self::firstOnly(),
            'code'       => self::code($u),
            'link'       => self::link($u),
            'share'      => self::shareUrl($u),
        ];
        if ($tg <= 0) return $out;

        try {
            $out['count'] = (int)DB::val(
                'SELECT COUNT(*) FROM {p}users WHERE referrer_id = :r', [':r' => $tg], 0);

            $out['active'] = (int)DB::val(
                'SELECT COUNT(DISTINCT u.id) FROM {p}users u
                 INNER JOIN {p}transactions t ON t.user_id = u.id
                 WHERE u.referrer_id = :r AND t.type = :d AND t.status = :s',
                [':r' => $tg, ':d' => 'deposit', ':s' => 'approved'], 0);

            $out['l2'] = (int)DB::val(
                'SELECT COUNT(*) FROM {p}users c
                 INNER JOIN {p}users p ON c.referrer_id = p.tg_id
                 WHERE p.referrer_id = :r', [':r' => $tg], 0);

            if ($uid > 0) {
                $out['earned'] = (int)DB::val(
                    'SELECT COALESCE(SUM(amount), 0) FROM {p}transactions
                     WHERE user_id = :u AND type = :t AND amount > 0',
                    [':u' => $uid, ':t' => 'referral'], 0);
                $out['earned_txt'] = money($out['earned']);
            }
        } catch (Throwable $e) {
            app_log('referral', 'stats failed: ' . $e->getMessage());
        }

        return $out;
    }

    /** لیست زیرمجموعه‌ها با مجموع شارژهای تاییدشده */
    public static function invitees(int $tgId, int $limit = 20): array
    {
        $tgId  = (int)$tgId;
        $limit = max(1, min(100, $limit));
        if ($tgId <= 0) return [];

        try {
            $rows = DB::all(
                'SELECT u.id, u.tg_id, u.username, u.first_name, u.created_at,
                        TRIM(CONCAT_WS(\' \', u.first_name, u.last_name)) AS name,
                        (SELECT COALESCE(SUM(t.amount), 0) FROM {p}transactions t
                          WHERE t.user_id = u.id AND t.type = :d AND t.status = :s) AS paid
                 FROM {p}users u
                 WHERE u.referrer_id = :r
                 ORDER BY u.id DESC LIMIT ' . $limit,
                [':r' => $tgId, ':d' => 'deposit', ':s' => 'approved']);
        } catch (Throwable $e) {
            app_log('referral', 'invitees failed: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ((array)$rows as $r) {
            $nm = trim((string)($r['name'] ?? ''));
            if ($nm === '') $nm = trim((string)($r['first_name'] ?? ''));
            if ($nm === '') $nm = trim((string)($r['username'] ?? ''));
            if ($nm === '') $nm = 'کاربر ' . (int)($r['id'] ?? 0);

            $paid = (int)($r['paid'] ?? 0);
            $out[] = [
                'id'       => (int)($r['id'] ?? 0),
                'name'     => mb_substr($nm, 0, 40),
                'paid'     => $paid,
                'paid_txt' => money($paid),
                'joined'   => to_jalali((string)($r['created_at'] ?? '')),
                'active'   => $paid > 0,
            ];
        }
        return $out;
    }

    /* ==================== پرداخت پورسانت ==================== */

    /**
     * پس از تایید شارژ یک کاربر فراخوانی می‌شود و تا دو سطح بالا پورسانت می‌دهد.
     * پاداش ثابت اولین شارژ در Wallet مدیریت می‌شود و اینجا تکرار نمی‌شود.
     *
     * @return array{ok:bool,paid:int}
     */
    public static function reward(int $userId, int $amount, bool $isFirstDeposit = false): array
    {
        $paid = 0;
        if (!self::enabled()) return ['ok' => true, 'paid' => 0];

        $userId = max(0, (int)$userId);
        $amount = (int)$amount;
        if ($userId <= 0 || $amount <= 0) return ['ok' => true, 'paid' => 0];

        if (self::firstOnly() && !$isFirstDeposit) return ['ok' => true, 'paid' => 0];

        $min = self::minAmount();
        if ($min > 0 && $amount < $min) return ['ok' => true, 'paid' => 0];

        $lvl = [self::percent(), self::percentL2()];
        if ($lvl[0] <= 0 && $lvl[1] <= 0) return ['ok' => true, 'paid' => 0];

        $cap = self::capPerTx();

        try {
            $cur = DB::one('SELECT id, tg_id, referrer_id FROM {p}users WHERE id = :i', [':i' => $userId]);
            if (!$cur) return ['ok' => true, 'paid' => 0];

            for ($i = 0; $i < 2; $i++) {
                $refTg = (int)($cur['referrer_id'] ?? 0);
                if ($refTg <= 0) break;

                $ref = DB::one('SELECT id, tg_id, referrer_id FROM {p}users WHERE tg_id = :t', [':t' => $refTg]);
                if (!$ref || (int)$ref['id'] === $userId) break;

                $pct = (float)$lvl[$i];
                if ($pct > 0) {
                    $share = (int)floor($amount * $pct / 100);
                    if ($cap > 0 && $share > $cap) $share = $cap;

                    if ($share > 0) {
                        $note = 'پورسانت معرفی سطح ' . ($i + 1) . ' — ' . fa_num((string)$pct) . '٪ از ' . money($amount);
                        Wallet::credit((int)$ref['id'], $share, 'referral', 'admin', $note);
                        $paid += $share;

                        try {
                            Tg::send((int)$ref['tg_id'],
                                "🎁 پورسانت معرفی\nمبلغ " . money($share) . ' ' . currency()
                                . " به کیف پول شما افزوده شد.\nسطح: " . fa_num((string)($i + 1)));
                        } catch (Throwable $e) {
                            app_log('referral', 'notify failed: ' . $e->getMessage());
                        }

                        if (class_exists('Logs')) {
                            Logs::send('referral', Logs::fmt('🎁 پورسانت درصدی معرفی', [
                                'معرف'        => '<code>' . (int)$ref['tg_id'] . '</code>',
                                'سطح'         => fa_num((string)($i + 1)),
                                'درصد'        => fa_num((string)$pct) . '٪',
                                'مبلغ شارژ'  => money($amount) . ' ' . currency(),
                                'پورسانت'     => money($share) . ' ' . currency(),
                            ]));
                        }
                    }
                }

                $cur = $ref;
            }
        } catch (Throwable $e) {
            app_log('referral', 'reward failed: ' . $e->getMessage());
            return ['ok' => false, 'paid' => $paid];
        }

        return ['ok' => true, 'paid' => $paid];
    }

    /* ==================== گزارش مدیریتی ==================== */

    /** برترین معرف‌ها */
    public static function top(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        try {
            return (array)DB::all(
                'SELECT r.id, r.tg_id, r.first_name, r.username,
                        TRIM(CONCAT_WS(\' \', r.first_name, r.last_name)) AS name,
                        COUNT(u.id) AS invites,
                        (SELECT COALESCE(SUM(t.amount), 0) FROM {p}transactions t
                          WHERE t.user_id = r.id AND t.type = :t AND t.amount > 0) AS earned
                 FROM {p}users r
                 INNER JOIN {p}users u ON u.referrer_id = r.tg_id
                 GROUP BY r.id, r.tg_id, r.first_name, r.last_name, r.username
                 ORDER BY invites DESC, earned DESC LIMIT ' . $limit, [':t' => 'referral']);
        } catch (Throwable $e) {
            app_log('referral', 'top failed: ' . $e->getMessage());
            return [];
        }
    }

    /** خلاصهٔ کلی برای داشبورد مدیر */
    public static function overview(): array
    {
        try {
            $linked = (int)DB::val('SELECT COUNT(*) FROM {p}users WHERE referrer_id IS NOT NULL AND referrer_id > 0', [], 0);
            $refs   = (int)DB::val('SELECT COUNT(DISTINCT referrer_id) FROM {p}users WHERE referrer_id IS NOT NULL AND referrer_id > 0', [], 0);
            $paid   = (int)DB::val('SELECT COALESCE(SUM(amount), 0) FROM {p}transactions WHERE type = :t AND amount > 0', [':t' => 'referral'], 0);
        } catch (Throwable $e) {
            app_log('referral', 'overview failed: ' . $e->getMessage());
            return ['linked' => 0, 'referrers' => 0, 'paid' => 0, 'paid_txt' => money(0)];
        }

        return ['linked' => $linked, 'referrers' => $refs, 'paid' => $paid, 'paid_txt' => money($paid)];
    }
}
