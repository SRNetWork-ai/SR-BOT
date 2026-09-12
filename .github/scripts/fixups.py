#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
fixed84 - SR-BOT 0.0.1 BETA

1) receipts approved/rejected in the bot no longer stay in the web panel queue
2) automatic gateway invoices (HooshPay / NowPayments) leave the manual receipt
   queue and get their own "gateway" tab + auto poll + stale-invoice cleanup
3) after approve/reject the inline buttons disappear and the admin card is
   stamped with "approved" / "rejected" (works from bot AND from the web panel)

Idempotent: safe to run more than once.
"""
import json
import os
import re
import sys

CACHE = {}
ERRORS = []
NEW = {}


def load(path):
    if path not in CACHE:
        if not os.path.exists(path):
            ERRORS.append("missing file: %s" % path)
            CACHE[path] = None
        else:
            with open(path, encoding="utf-8") as f:
                CACHE[path] = f.read()
    return CACHE[path]


def rep(path, old, new, expect=1, marker=None):
    s = load(path)
    if s is None:
        return
    if marker and marker in s:
        return
    n = s.count(old)
    if n != expect:
        ERRORS.append("%s: literal anchor x%d (want %d): %r" % (path, n, expect, old[:70]))
        return
    CACHE[path] = s.replace(old, new, expect)


def rep_re(path, pattern, new, expect=1, flags=0, marker=None):
    s = load(path)
    if s is None:
        return
    if marker and marker in s:
        return
    hits = list(re.finditer(pattern, s, flags))
    if len(hits) != expect:
        ERRORS.append("%s: regex x%d (want %d): %r" % (path, len(hits), expect, pattern[:70]))
        return

    def _r(m):
        out = new
        for i in range(1, (m.re.groups or 0) + 1):
            out = out.replace("\\%d" % i, m.group(i) or "")
        return out

    CACHE[path] = re.sub(pattern, _r, s, count=expect, flags=flags)


def append_class(path, code, marker):
    """insert code just before the final closing brace of the file (end of class)"""
    s = load(path)
    if s is None:
        return
    if marker in s:
        return
    i = s.rstrip().rfind("\n}")
    if i < 0:
        ERRORS.append("%s: class closing brace not found" % path)
        return
    CACHE[path] = s[:i] + "\n" + code + s[i:]


def notify_false(path, needle, expect=1):
    """append the new `notify` argument (false) to an existing approve/reject call"""
    s = load(path)
    if s is None:
        return
    out, n, done = [], 0, 0
    for line in s.split("\n"):
        if needle in line:
            if ", false)" in line:
                done += 1
            else:
                body = line.rstrip()
                j = body.rfind(");")
                if j > 0:
                    line = body[:j] + ", false);" + body[j + 2:]
                    n += 1
        out.append(line)
    if n == 0 and done >= expect:
        return
    if n != expect:
        ERRORS.append("%s: notify anchor x%d (want %d): %r" % (path, n, expect, needle))
        return
    CACHE[path] = "\n".join(out)


# =====================================================================
# 1) app/Tg.php - caption / markup editing + stamp()
# =====================================================================
TG = "app/Tg.php"
append_class(TG, r"""    /* ==================== fixed84: مهر زدن روی پیام‌ها ==================== */

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
""", marker="function stamp(")


# =====================================================================
# 2) app/Service/Wallet.php
# =====================================================================
WA = "app/Service/Wallet.php"

rep(WA, "    public static function balance(int $userId): int",
    r"""    /* fixed84: روش‌های پرداخت خودکار (درگاه) — رسید دستی ندارند و نباید در صف تایید مدیر بیایند */
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

    public static function balance(int $userId): int""",
    marker="AUTO_METHODS")

rep(WA, "            WHERE t.status = :s AND t.type = :t ORDER BY t.id DESC LIMIT ' . (int)$limit,",
    """            WHERE t.status = :s AND t.type = :t
              AND t.method NOT IN (' . self::autoSqlList() . ')
            ORDER BY t.id DESC LIMIT ' . (int)$limit,""",
    marker="AND t.method NOT IN (' . self::autoSqlList()")

rep(WA, "    public static function approve(int $txId, $adminId = null): array",
    "    public static function approve(int $txId, $adminId = null, bool $notify = true): array",
    marker="function approve(int $txId, $adminId = null, bool $notify")

rep(WA, """            $n = DB::q("UPDATE {p}transactions SET `status` = 'approved', `admin_id` = :ad, `decided_at` = :dt
                        WHERE `id` = :id AND `status` = 'pending'",
                [':ad' => $adminId, ':dt' => now(), ':id' => $txId])->rowCount();""",
    """            try {
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
            }""",
    marker="approve admin_id fallback")

rep_re(WA,
    r"self::logDecision\(\$tx, 'approved', \$adminId\);\n        return \['ok' => true, 'message' => ([^\n]*?), 'tx' => \$tx\];",
    r"""self::logDecision($tx, 'approved', $adminId);
        /* fixed84: مهر «تایید شده» روی کارت رسید + حذف دکمه‌ها + خبر دادن به کاربر */
        $stamped = self::stampAdminCards($tx, 'approved', $adminId);
        if ($notify) self::notifyDecision($tx, 'approved');
        return ['ok' => true, 'message' => \1, 'tx' => $tx, 'stamped' => $stamped];""",
    marker="stampAdminCards($tx, 'approved'")

rep(WA, "    public static function reject(int $txId, $adminId = null, string $reason = ''): array",
    "    public static function reject(int $txId, $adminId = null, string $reason = '', bool $notify = true): array",
    marker="string $reason = '', bool $notify")

rep_re(WA,
    r"self::logDecision\(\$tx, 'rejected', \$adminId, \$reason\);\n        return \['ok' => true, 'message' => ([^\n]*?), 'tx' => \$tx\];",
    r"""self::logDecision($tx, 'rejected', $adminId, $reason);
        /* fixed84: مهر «رد شده» روی کارت رسید + حذف دکمه‌ها + خبر دادن به کاربر */
        $stamped = self::stampAdminCards($tx, 'rejected', $adminId, $reason);
        if ($notify) self::notifyDecision($tx, 'rejected', $reason);
        return ['ok' => true, 'message' => \1, 'tx' => $tx, 'stamped' => $stamped];""",
    marker="stampAdminCards($tx, 'rejected'")

rep_re(WA,
    r"\n        return \['ok' => true, 'message' => ([^\n]*?), 'tx' => \$tx, 'balance' => \$bal\];",
    r"""
        self::stampAdminCards($tx, 'reverted', $adminId);
        return ['ok' => true, 'message' => \1, 'tx' => $tx, 'balance' => $bal];""",
    marker="stampAdminCards($tx, 'reverted'")

append_class(WA, r"""    /* ==================== fixed84: کارت رسید مدیر ==================== */

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
""", marker="function stampAdminCards(")


# =====================================================================
# 3) app/Bot/AdminBot.php
# =====================================================================
AB = "app/Bot/AdminBot.php"

rep_re(AB, r"            case 'ok': \{\n.*?\n            \}",
    r"""            case 'ok': {
                /* fixed84: پیام کاربر و مهر خوردن کارت رسید در Wallet انجام می‌شود */
                $r = Wallet::approve((int)$arg2, (int)Bot::$u['tg_id']);
                if (class_exists('Audit')) Audit::log('wallet.approve', ['tx' => (int)$arg2, 'ok' => !empty($r['ok']), 'amount' => (int)($r['tx']['amount'] ?? 0)]);
                Tg::answerCb($cbId, $r['message'], true);
                if (!empty($r['ok']) && empty($r['stamped']) && $msgId) {
                    Tg::stamp($chatId, $msgId, '✅ <b>تایید شده</b>' . "\n" . '🧾 تراکنش <code>#' . (int)$arg2 . '</code> تایید و کیف پول شارژ شد.');
                }
                return;
            }""",
    flags=re.S, marker="مهر خوردن کارت رسید در Wallet")

rep_re(AB, r"            case 'no': \{\n.*?\n            \}",
    r"""            case 'no': {
                if (class_exists('Audit')) Audit::log('wallet.reject', ['tx' => (int)$arg2]);
                $r = Wallet::reject((int)$arg2, (int)Bot::$u['tg_id'], 'رد توسط مدیر');
                Tg::answerCb($cbId, $r['message'], true);
                if (!empty($r['ok']) && empty($r['stamped']) && $msgId) {
                    Tg::stamp($chatId, $msgId, '❌ <b>رد شده</b>' . "\n" . '🧾 تراکنش <code>#' . (int)$arg2 . '</code> رد شد و به کاربر اطلاع داده شد.');
                }
                return;
            }""",
    flags=re.S, marker="'❌ <b>رد شده</b>'")

rep_re(AB, r"\$method = \$tx\['method'\] === 'crypto' \? [^\n]*;",
    r"""$mLbl   = ['card' => '💳 کارت به کارت', 'crypto' => '🌐 ارزی', 'wallet' => '👛 کیف پول',
                   'hooshpay' => '🪙 هوش‌پی (خودکار)', 'nowpay' => '🤖 نوپیمنتس (خودکار)'];
        $method = $mLbl[(string)$tx['method']] ?? (string)$tx['method'];""",
    marker="$mLbl   = [")

rep(AB, """        if (!empty($tx['receipt_file'])) {
            Tg::api('sendPhoto', ['chat_id' => $chatId, 'photo' => (string)$tx['receipt_file'],
                'caption' => $txt, 'parse_mode' => 'HTML', 'reply_markup' => jenc($kb)]);
        } else {
            Tg::send($chatId, $txt, $kb);
        }""",
    """        if (!empty($tx['receipt_file'])) {
            $res = Tg::api('sendPhoto', ['chat_id' => $chatId, 'photo' => (string)$tx['receipt_file'],
                'caption' => $txt, 'parse_mode' => 'HTML', 'reply_markup' => jenc($kb)]);
        } else {
            $res = Tg::send($chatId, $txt, $kb);
        }
        /* fixed84: شناسهٔ این پیام را نگه می‌داریم تا هنگام تایید/رد (از ربات یا پنل وب)
           همین کارت مهر بخورد و دکمه‌های تایید/رد حذف شوند */
        Wallet::rememberAdminCard((int)($tx['id'] ?? 0), $chatId, (int)($res['result']['message_id'] ?? 0));""",
    marker="rememberAdminCard")

rep(AB, """        $tx = DB::one('SELECT t.*, u.username FROM {p}transactions t JOIN {p}users u ON u.id = t.user_id WHERE t.id = :id', [':id' => $txId]);
        if (!$tx) return;""",
    """        $tx = DB::one('SELECT t.*, u.username FROM {p}transactions t JOIN {p}users u ON u.id = t.user_id WHERE t.id = :id', [':id' => $txId]);
        if (!$tx) return;
        /* fixed84: فاکتور درگاه خودکار رسید دستی نیست و به صف تایید مدیر نمی‌رود */
        if (class_exists('Wallet') && Wallet::isAuto((string)($tx['method'] ?? ''))) return;""",
    marker="فاکتور درگاه خودکار رسید دستی نیست")


# =====================================================================
# 4) admin/pages/payments.php
# =====================================================================
PAY = "admin/pages/payments.php"

rep(PAY, "$METHODS = [\n",
    """$METHODS = [
    /* fixed84: درگاه‌های خودکار هم برچسب درست داشته باشند */
    'hooshpay' => ['هوش‌پی (خودکار)', '🪙'],
    'nowpay'   => ['نوپیمنتس (خودکار)', '🤖'],
""", marker="'hooshpay' => ['")

rep(PAY, "['pending', 'all', 'orders', 'stats']", "['pending', 'gateway', 'all', 'orders', 'stats']",
    marker="'pending', 'gateway'")

rep(PAY, "$buildWhere = function (bool $onlyPending)",
    "$buildWhere = function (bool $onlyPending, string $scope = '')",
    marker="function (bool $onlyPending, string $scope")

rep(PAY, "    if ($fMe !== '') { $w[] = 't.method = :me'; $p[':me'] = $fMe; }",
    """    /* fixed84: صف رسیدهای دستی از فاکتورهای درگاه خودکار جدا شد */
    $autoList = class_exists('Wallet') ? Wallet::autoSqlList() : "'hooshpay','nowpay'";
    if ($scope === 'manual') $w[] = 't.method NOT IN (' . $autoList . ')';
    if ($scope === 'auto')   $w[] = 't.method IN (' . $autoList . ')';
    if ($fMe !== '') { $w[] = 't.method = :me'; $p[':me'] = $fMe; }""",
    marker="$autoList = class_exists('Wallet')")

rep(PAY, "    [$where, $params] = $buildWhere(true);",
    "    [$where, $params] = $buildWhere(true, 'manual');",
    marker="$buildWhere(true, 'manual')")

rep(PAY, """$cntPend  = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE status = 'pending'", [], 0);
$sumPend  = (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE status = 'pending'", [], 0);""",
    """/* fixed84: رسیدهای دستی و فاکتورهای درگاه جدا شمرده می‌شوند */
$autoIn   = class_exists('Wallet') ? Wallet::autoSqlList() : "'hooshpay','nowpay'";
$cntPend  = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE status = 'pending' AND method NOT IN ($autoIn)", [], 0);
$sumPend  = (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE status = 'pending' AND method NOT IN ($autoIn)", [], 0);
$cntAuto  = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE status = 'pending' AND method IN ($autoIn)", [], 0);""",
    marker="$cntAuto  = (int)DB::val")

rep(PAY, """$oldPend = DB::one("SELECT created_at FROM {p}transactions WHERE status = 'pending' ORDER BY id ASC LIMIT 1");""",
    """$oldPend = DB::one("SELECT created_at FROM {p}transactions WHERE status = 'pending' AND method NOT IN ($autoIn) ORDER BY id ASC LIMIT 1");""",
    marker="AND method NOT IN ($autoIn) ORDER BY id ASC LIMIT 1")

rep(PAY, """  <a class="pay-nv <?= $tab === 'all' ? 'on' : '' ?>" href="index.php?p=payments&amp;tab=all">""",
    """  <a class="pay-nv <?= $tab === 'gateway' ? 'on' : '' ?>" href="index.php?p=payments&amp;tab=gateway">
    <span class="i">🪙</span> درگاه خودکار <span class="n"><?= fa_num($cntAuto) ?></span></a>
  <a class="pay-nv <?= $tab === 'all' ? 'on' : '' ?>" href="index.php?p=payments&amp;tab=all">""",
    marker="tab=gateway")

rep(PAY, "    if ($act === 'bulk') {",
    """    /* fixed84: استعلام وضعیت فاکتور از درگاه خودکار */
    if ($act === 'poll' && $id) { need('payments.approve', 'payments');
        $tx = DB::one('SELECT * FROM {p}transactions WHERE id = :id', [':id' => $id]);
        if (!$tx) { flash('err', 'تراکنش پیدا نشد.'); back('payments', $ret); }
        $mth = strtolower((string)$tx['method']);
        $pr  = ['ok' => false, 'message' => 'استعلام برای این روش پرداخت ممکن نیست.'];
        if ($mth === 'hooshpay' && class_exists('HooshPay'))   $pr = HooshPay::poll($tx);
        elseif ($mth === 'nowpay' && class_exists('NowPay'))   $pr = NowPay::poll($tx);
        flash(!empty($pr['ok']) ? 'ok' : 'err', h((string)($pr['message'] ?? '-')));
        back('payments', $ret);
    }

    if ($act === 'bulk') {""",
    marker="$act === 'poll'")

rep_re(PAY, r"(<\?php\n)(/\*[^\n]*\n)(elseif \(\$tab === 'all'\):)",
    """\\1/* fixed84 ==================== tab: gateway invoices ==================== */
elseif ($tab === 'gateway'):
    [$where, $params] = $buildWhere(true, 'auto');
    $rows = DB::all("SELECT t.*, u.first_name, u.username, u.tg_id AS utg
                     FROM {p}transactions t LEFT JOIN {p}users u ON u.id = t.user_id
                     $where ORDER BY t.id DESC LIMIT 200", $params);
    $gwHours = max(1, (int)DB::setting('gw_stale_hours', '6'));
?>
  <div class="card mt3">
    <div class="card-head">
      <div><div class="card-title">🪙 فاکتورهای درگاه خودکار</div>
        <div class="card-sub">این‌ها رسید دستی نیستند و تایید مدیر لازم ندارند؛ با پرداخت کاربر، کیف پول خودکار شارژ می‌شود.</div></div>
    </div>
    <div class="hint">فاکتورهای پرداخت‌نشده پس از <?= fa_num($gwHours) ?> ساعت خودکار لغو می‌شوند. با «استعلام» وضعیت همین لحظه از درگاه گرفته می‌شود.</div>
    <?php if (!$rows): ?>
      <div class="empty"><div class="ic">🎉</div>فاکتور بازمانده‌ای از درگاه‌ها نیست.</div>
    <?php else: ?>
      <div class="table-wrap"><table class="responsive">
        <thead><tr><th>#</th><th>کاربر</th><th>درگاه</th><th>مبلغ</th><th>شناسه فاکتور</th><th>ثبت</th><th>توضیح</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $t): ?>
          <tr>
            <td class="mono"><?= fa_num((int)$t['id']) ?></td>
            <td>
              <a href="index.php?p=users&amp;u=<?= (int)$t['user_id'] ?>"><b><?= h((string)($t['first_name'] ?: 'کاربر')) ?></b></a>
              <div class="muted mono" style="font-size:11.5px"><?= fa_num((string)$t['utg']) ?></div>
            </td>
            <td style="font-size:12px"><?= h($methodLabel((string)$t['method'])) ?></td>
            <td><b><?= money((float)$t['amount']) ?></b></td>
            <td class="mono" style="font-size:11px;max-width:150px;word-break:break-all"><?= h(mb_substr((string)($t['txid'] ?? ''), 0, 28)) ?></td>
            <td class="muted" style="font-size:11.5px"><?= h(to_jalali((string)$t['created_at'], true)) ?></td>
            <td class="muted" style="font-size:11.5px"><?= h(mb_substr((string)($t['note'] ?? '—'), 0, 40)) ?></td>
            <td class="acts">
              <?php if (can('payments.approve')): ?>
                <button class="btn btn-sm btn-primary" type="button" data-pay-one="poll" data-id="<?= (int)$t['id'] ?>">🔁 استعلام</button>
              <?php endif; ?>
              <?php if (can('payments.reject')): ?>
                <button class="btn btn-sm btn-red" type="button" data-pay-one="reject" data-id="<?= (int)$t['id'] ?>">❌ لغو فاکتور</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>

      <form method="post" id="payOneForm" class="hidden-form" style="display:none">
        <?= csrf_field() ?>
        <input type="hidden" name="act" id="po_act" value="">
        <input type="hidden" name="id"  id="po_id"  value="">
        <input type="hidden" name="apply" id="po_apply" value="">
        <input type="hidden" name="reason" id="po_reason" value="">
      </form>
    <?php endif; ?>
  </div>

<?php
\\2\\3""",
    marker="tab: gateway invoices")

notify_false(PAY, "$w = Wallet::approve($id, (int)$ADMIN['id']")
notify_false(PAY, "$w = Wallet::reject($id, (int)$ADMIN['id'],")


# =====================================================================
# 5) call sites that already notify the user themselves
# =====================================================================
notify_false("app/Service/TxCheck.php", "$r = Wallet::approve($txId, null")
notify_false("app/Service/TxCheck.php", "$r = Wallet::reject($txId, null,")
notify_false("app/Service/HooshPay.php", "$res = Wallet::approve((int)$tx['id'], null")
notify_false("app/Service/HooshPay.php", "Wallet::reject((int)$tx['id'], null,")
notify_false("app/Service/NowPay.php", "$res = Wallet::approve((int)$tx['id'], null")
notify_false("app/Service/NowPay.php", "Wallet::reject((int)$tx['id'], null,")
notify_false("app/Bot/Bot.php", "$ap = Wallet::approve($txId2, null")
notify_false("app/Bot/Bot.php", "$rj = Wallet::reject($txId2, null,")
notify_false("miniapp/api.php", "$ap = Wallet::approve($txNew, null")
notify_false("miniapp/api.php", "$rj = Wallet::reject($txNew, null,")
notify_false("miniapp/api.php", "Wallet::reject($txHp, null,")


# =====================================================================
# 6) cron/tasks.php - poll HooshPay + cancel stale gateway invoices
# =====================================================================
CR = "cron/tasks.php"
rep(CR, "            'sync_fail' => 0, 'nowpay' => 0, 'recovered' => 0,",
    "            'sync_fail' => 0, 'nowpay' => 0, 'recovered' => 0, 'hooshpay' => 0, 'gw_expired' => 0,",
    marker="'gw_expired' => 0")

rep(CR, """        usleep(250000);
    }
}

/* ---------------------------------------------------------------""",
    """        usleep(250000);
    }
}

/* fixed84: استعلام خودکار فاکتورهای هوش‌پی تا صف رسیدهای پنل وب پر نشود */
if (class_exists('HooshPay') && HooshPay::enabled()) {
    $hpWait = DB::all("SELECT * FROM {p}transactions
                       WHERE method = 'hooshpay' AND status = 'pending'
                         AND created_at > DATE_SUB(NOW(), INTERVAL 2 DAY)
                       ORDER BY id ASC LIMIT 25");
    foreach ($hpWait as $tx) {
        try {
            $pr = HooshPay::poll($tx);
            if (!empty($pr['ok'])) {
                $report['hooshpay']++;
                cron_say('hooshpay tx #' . (int)$tx['id'] . ' => ' . (string)($pr['message'] ?? 'done'));
            }
        } catch (Throwable $e) {
            cron_say('hooshpay poll failed: ' . $e->getMessage());
        }
        usleep(250000);
    }
}

/* fixed84: فاکتورهای درگاه که کاربر هرگز پرداخت نکرده، پس از چند ساعت لغو می‌شوند */
try {
    $gwHours = max(1, (int)DB::setting('gw_stale_hours', '6'));
    $gwAuto  = class_exists('Wallet') ? Wallet::autoSqlList() : "'hooshpay','nowpay'";
    $gwStale = DB::all("SELECT id FROM {p}transactions
                        WHERE status = 'pending' AND type = 'deposit' AND method IN ($gwAuto)
                          AND created_at < DATE_SUB(NOW(), INTERVAL $gwHours HOUR)
                        ORDER BY id ASC LIMIT 200");
    foreach ($gwStale as $g) {
        $rj = Wallet::reject((int)$g['id'], null, 'فاکتور درگاه بدون پرداخت منقضی شد', false);
        if (!empty($rj['ok'])) $report['gw_expired']++;
    }
    if ($report['gw_expired'] > 0) cron_say('gateway invoices canceled: ' . (int)$report['gw_expired']);
} catch (Throwable $e) {
    cron_say('gateway cleanup failed: ' . $e->getMessage());
}

/* ---------------------------------------------------------------""",
    marker="hooshpay poll failed")


# =====================================================================
# 7) admin/pages/dashboard.php
# =====================================================================
DASH = "admin/pages/dashboard.php"
rep(DASH, """$m_payPending = (int)$d_val("SELECT COUNT(*) FROM {p}transactions WHERE status = 'pending'");
$m_paySum     = (float)$d_val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE status = 'pending'");""",
    """/* fixed84: فاکتورهای درگاه خودکار جزو «رسیدهای در انتظار» نیستند */
$m_payPending = (int)$d_val("SELECT COUNT(*) FROM {p}transactions WHERE status = 'pending' AND method NOT IN ('hooshpay','nowpay')");
$m_paySum     = (float)$d_val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE status = 'pending' AND method NOT IN ('hooshpay','nowpay')");""",
    marker="جزو «رسیدهای در انتظار» نیستند")

rep(DASH, "      WHERE t.status = 'pending' ORDER BY t.id DESC LIMIT 5\");",
    "      WHERE t.status = 'pending' AND t.method NOT IN ('hooshpay','nowpay') ORDER BY t.id DESC LIMIT 5\");",
    marker="AND t.method NOT IN ('hooshpay','nowpay') ORDER BY t.id DESC LIMIT 5")


# =====================================================================
# 8) database: admin_msg column + gw_stale_hours setting
# =====================================================================
MIG = "app/Service/Migrate.php"
rep(MIG, "            'card_id' => 'INT UNSIGNED NULL',",
    """            'card_id' => 'INT UNSIGNED NULL',
            /* fixed84: شناسهٔ پیام کارت رسید در ربات مدیر (برای مهر تایید/رد) */
            'admin_msg' => 'TEXT NULL',""",
    marker="'admin_msg' => 'TEXT NULL'")

SCH = "database/schema.sql"
rep(SCH, "  `receipt_file` VARCHAR(255) NULL,\n  `note` VARCHAR(255) NULL,",
    "  `receipt_file` VARCHAR(255) NULL,\n  `note` VARCHAR(255) NULL,\n  `admin_msg` TEXT NULL,",
    marker="`admin_msg` TEXT NULL,")
rep(SCH, " ('rs_requests_open','1');", " ('rs_requests_open','1'),\n ('gw_stale_hours','6');",
    marker="('gw_stale_hours','6')")

NEW["database/migrations/0023_tx_admin_msg.sql"] = """-- 0023 : مهر «تایید شده / رد شده» روی کارت رسید در ربات + عمر فاکتور درگاه خودکار
ALTER TABLE `{p}transactions` ADD COLUMN `admin_msg` TEXT NULL AFTER `note`;

INSERT INTO `{p}settings` (`k`, `v`) VALUES
  ('gw_stale_hours','6')
ON DUPLICATE KEY UPDATE `k` = `k`;
"""


# =====================================================================
# 9) version.json
# =====================================================================
BUILD = (os.environ.get("NEW_BUILD") or "fixed84").strip()
ENTRY = ("🧾 صف رسیدها اصلاح شد: رسیدی که در ربات تایید/رد می‌شود دیگر در «در انتظار» پنل وب نمی‌ماند، "
         "فاکتورهای درگاه خودکار (هوش‌پی/نوپیمنتس) از صف رسیدهای دستی جدا و به تب «درگاه خودکار» منتقل شدند "
         "(با استعلام دستی، استعلام خودکار کرون و لغو فاکتورهای پرداخت‌نشده)، و بعد از تصمیم‌گیری دکمه‌های "
         "تایید/رد از کارت ربات حذف شده و «✅ تایید شده» یا «❌ رد شده» روی همان پیام مهر می‌خورد؛ "
         "تایید از پنل وب هم به کاربر اطلاع می‌دهد.")

if os.path.exists("version.json"):
    with open("version.json", encoding="utf-8") as f:
        vj = json.load(f)
    vj["build"] = BUILD
    cl = vj.get("changelog") or []
    if ENTRY not in cl:
        cl.insert(0, ENTRY)
    vj["changelog"] = cl
    NEW["version.json"] = json.dumps(vj, ensure_ascii=False, indent=2) + "\n"
else:
    ERRORS.append("missing file: version.json")


# =====================================================================
# flush
# =====================================================================
if ERRORS:
    print("ABORTED - anchors not found:")
    for e in ERRORS:
        print("  -", e)
    sys.exit(1)

changed = []
for path, text in CACHE.items():
    if text is None:
        continue
    with open(path, encoding="utf-8") as f:
        if f.read() == text:
            continue
    with open(path, "w", encoding="utf-8") as f:
        f.write(text)
    changed.append(path)

for path, text in NEW.items():
    old = None
    if os.path.exists(path):
        with open(path, encoding="utf-8") as f:
            old = f.read()
    if old == text:
        continue
    d = os.path.dirname(path)
    if d:
        os.makedirs(d, exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        f.write(text)
    changed.append(path)

print("build:", BUILD)
print("changed files:", len(changed))
for c in sorted(changed):
    print("  *", c)
