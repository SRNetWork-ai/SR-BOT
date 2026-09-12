#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Add "delete disconnected configs" + "refund on delete" to the Telegram bot and the mini-app.
Idempotent; run from the repository root.

Bot: per-service delete with refund quote + bulk cleanup of dead configs.
Mini-app: dead-config cleanup sheet + refund-aware delete button.
Admin: new setting usr_dead_del.
"""
import json
import os
import pathlib
import re

changed = []


def read(rel):
    return pathlib.Path(rel).read_text(encoding="utf-8")


def write(rel, text):
    pathlib.Path(rel).write_text(text, encoding="utf-8")
    changed.append(rel)


NEW_BUILD = os.environ.get("NEW_BUILD", "fixed83").strip() or "fixed83"

# ----------------------------------------------------------------- app/Service/Svc.php
SVC = "app/Service/Svc.php"
s = read(SVC)
orig = s

SVC_HELPERS = """
    /** fixed83: اجازهٔ حذف «کانفیگ‌های قطع» توسط خود کاربر (مستقل از حذف سرویس فعال) */
    public static function deadDelEnabled(): bool { return (string)DB::setting('usr_dead_del', '1') === '1'; }

    /** آیا این کانفیگ «قطع» است؟ منقضی، غیرفعال، حذف‌شده از پنل یا حجم تمام‌شده */
    public static function isDead(array $s): bool
    {
        $st = (string)($s['status'] ?? '');
        if ($st === 'deleted') return false;
        if (in_array($st, ['expired', 'disabled', 'missing'], true)) return true;

        $exp = strtotime((string)($s['expire_at'] ?? '')) ?: 0;
        if ($exp > 0 && $exp <= time()) return true;

        $tot = (float)($s['volume_gb'] ?? 0);
        if ($tot > 0 && (float)bytes2gb((int)($s['used_bytes'] ?? 0), 4) >= $tot) return true;

        return false;
    }

    /** برچسب دلیل قطع‌شدن برای نمایش به کاربر */
    public static function deadLabel(array $s): string
    {
        $st = (string)($s['status'] ?? '');
        if ($st === 'missing') return '🚫 حذف‌شده از پنل';
        if ($st === 'disabled') return '⛔️ غیرفعال';

        $exp = strtotime((string)($s['expire_at'] ?? '')) ?: 0;
        if ($st === 'expired' || ($exp > 0 && $exp <= time())) return '⏳ منقضی';

        $tot = (float)($s['volume_gb'] ?? 0);
        if ($tot > 0 && (float)bytes2gb((int)($s['used_bytes'] ?? 0), 4) >= $tot) return '📉 حجم تمام‌شده';

        return '⛔️ قطع';
    }

    /** کانفیگ‌های قطع‌شدهٔ یک کاربر — کانفیگ نمایندگی جدا مدیریت می‌شود */
    public static function deadForUser(int $userId, bool $includeReseller = false): array
    {
        $out = [];
        foreach (self::forUser($userId, false, $includeReseller) as $s) {
            if (self::isDead($s)) $out[] = $s;
        }
        return $out;
    }

    /** حذف گروهی کانفیگ‌های قطع‌شده با عودت وجه طبق سیاست مدیر */
    public static function purgeDeadForUser(array $user, array $ids = []): array
    {
        if (!self::deadDelEnabled()) {
            return ['ok' => false, 'count' => 0, 'failed' => 0, 'refund' => 0,
                'refund_txt' => money(0), 'names' => [],
                'message' => 'حذف کانفیگ‌های قطع توسط مدیر غیرفعال شده است.'];
        }

        $want = [];
        foreach ($ids as $i) { $i = (int)$i; if ($i > 0) $want[$i] = true; }

        $done = 0; $fail = 0; $back = 0; $names = [];
        foreach (self::deadForUser((int)($user['id'] ?? 0)) as $s) {
            $sid = (int)($s['id'] ?? 0);
            if ($want && !isset($want[$sid])) continue;
            try {
                $r = self::userDelete($user, $sid);
            } catch (Throwable $e) {
                $fail++;
                app_log('svc', 'purgeDead: ' . $e->getMessage(), ['svc' => $sid]);
                continue;
            }
            if (!empty($r['ok'])) {
                $done++;
                $back += (int)($r['refund'] ?? 0);
                $names[] = (string)($s['client_email'] ?? '');
            } else {
                $fail++;
            }
        }

        if ($done === 0) {
            return ['ok' => false, 'count' => 0, 'failed' => $fail, 'refund' => 0,
                'refund_txt' => money(0), 'names' => [],
                'message' => $fail > 0
                    ? 'حذف کانفیگ‌های قطع انجام نشد؛ دوباره تلاش کنید.'
                    : 'کانفیگ قطع‌شده‌ای ندارید.'];
        }

        $msg = '✅ ' . en_num((string)$done) . ' کانفیگ قطع‌شده حذف شد.';
        if ($back > 0) $msg .= chr(10) . '💰 مبلغ ' . money($back) . ' ' . currency() . ' به کیف پول شما برگشت داده شد.';
        if ($fail > 0) $msg .= chr(10) . '⚠️ ' . en_num((string)$fail) . ' مورد حذف نشد؛ بعداً دوباره تلاش کنید.';
        $msg .= chr(10) . '🗑 موارد حذف‌شده تا ' . en_num((string)self::trashDays()) . ' روز در سطل زباله می‌مانند.';

        if (class_exists('Logs')) {
            try {
                Logs::send('services', Logs::fmt('🧹 حذف کانفیگ‌های قطع توسط کاربر', [
                    'کاربر'  => (string)($user['tg_id'] ?? ''),
                    'تعداد'  => (string)$done,
                    'عودت'   => money($back) . ' ' . currency(),
                ]));
            } catch (Throwable $e) {
            }
        }

        return ['ok' => true, 'count' => $done, 'failed' => $fail, 'refund' => $back,
            'refund_txt' => money($back), 'names' => $names, 'message' => $msg];
    }
"""

if "deadDelEnabled" not in s:
    anchor = re.compile(
        r"(    public static function userDelFeePct\(\): float\n    \{\n[^\n]*\n    \}\n)"
    )
    s, n = anchor.subn(lambda m: m.group(1) + SVC_HELPERS, s, count=1)
    assert n == 1, "Svc: userDelFeePct anchor not found"

# gate: allow deleting a dead config even when plain user-delete is off
if "self::deadDelEnabled() && self::isDead($s)" not in s:
    gate = re.compile(
        r"        if \(!self::userDelEnabled\(\)\) return \[[^\n]*\];\n\n"
        r"        (\$s = self::find\(\$svcId\);\n"
        r"        if \(!\$s \|\| \(int\)\(\$s\['user_id'\] \?\? 0\) !== \(int\)\(\$user\['id'\] \?\? 0\)\) \{\n"
        r"[^\n]*\n        \}\n)"
    )
    NEW_GATE = (
        "        \\1\n"
        "        /* fixed83: سرویس فعال با اجازهٔ مدیر، کانفیگ قطع‌شده با اجازهٔ «حذف کانفیگ‌های قطع» */\n"
        "        if (!self::userDelEnabled() && !(self::deadDelEnabled() && self::isDead($s))) {\n"
        "            return ['ok' => false, 'message' => 'حذف سرویس توسط مدیر غیرفعال شده است.'];\n"
        "        }\n"
    )
    s, n = gate.subn(NEW_GATE, s, count=1)
    assert n == 1, "Svc: userDelete gate anchor not found"

if s != orig:
    write(SVC, s)

# ----------------------------------------------------------------- app/Bot/Bot.php
BOT = "app/Bot/Bot.php"
b = read(BOT)
orig = b

if "'svcdelok'" not in b:
    router = "            case 'svcsync': self::syncService($chatId, $msgId, $cbId, (int)$arg); return;\n"
    assert b.count(router) == 1, "Bot: router anchor not unique"
    b = b.replace(
        router,
        router
        + "            case 'svcdel':     Tg::answerCb($cbId); self::delOptions($chatId, $msgId, (int)$arg); return;\n"
        + "            case 'svcdelok':   self::doDelete($chatId, $msgId, $cbId, (int)$arg); return;\n"
        + "            case 'svcpurge':   Tg::answerCb($cbId); self::purgeDeadView($chatId, $msgId); return;\n"
        + "            case 'svcpurgeok': self::doPurgeDead($chatId, $msgId, $cbId); return;\n",
        1,
    )

if "svcpurge:0" not in b:
    lst = re.compile(
        r"(            \$rows\[\] = \[Tg::btn\(\$icon[^\n]*'svc:' \. \$s\['id'\]\)\];\n        \}\n)"
    )
    BULK_BTN = (
        "        /* fixed83: پاک‌سازی گروهی کانفیگ‌های قطع‌شده */\n"
        "        $deadN = 0;\n"
        "        foreach ($list as $d) if (Svc::isDead($d)) $deadN++;\n"
        "        if ($deadN > 0 && Svc::deadDelEnabled()) {\n"
        "            $rows[] = [Tg::btn('🧹 حذف کانفیگ‌های قطع (' . fa_num((string)$deadN) . ')', 'svcpurge:0')];\n"
        "        }\n"
    )
    b, n = lst.subn(lambda m: m.group(1) + BULK_BTN, b, count=1)
    assert n == 1, "Bot: sectionServices anchor not found"

if "'svcdel:' . $id" not in b:
    rows = re.compile(
        r"(        \$rows = \[\n            \$top,\n[^\n]*svcspec[^\n]*\n[^\n]*svcrn[^\n]*\n)"
        r"([^\n]*menu:services[^\n]*\n)        \];\n"
    )
    DEL_BTN = (
        "        /* fixed83: حذف سرویس و عودت وجه — کانفیگ قطع‌شده اجازهٔ جداگانه دارد */\n"
        "        $dead = Svc::isDead($s);\n"
        "        if (Svc::userDelEnabled() || ($dead && Svc::deadDelEnabled())) {\n"
        "            $rows[] = [Tg::btn($dead ? '🗑 حذف کانفیگ قطع‌شده و عودت وجه' : '🗑 حذف سرویس و عودت وجه', 'svcdel:' . $id)];\n"
        "        }\n"
    )

    def _rows(m):
        back = m.group(2).strip().rstrip(",")
        return m.group(1) + "        ];\n" + DEL_BTN + "        $rows[] = " + back + ";\n"

    b, n = rows.subn(_rows, b, count=1)
    assert n == 1, "Bot: showService rows anchor not found"

BOT_METHODS = """    /* ================= حذف سرویس / کانفیگ قطع‌شده و عودت وجه ================= */

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
        $txt = "🗑 <b>حذف سرویس و عودت وجه</b>\\n" . '<code>─────────────────</code>' . "\\n"
            . '👤 نام کاربری: <code>' . h((string)$s['client_email']) . "</code>\\n"
            . '⚙️ وضعیت: ' . ($dead ? Svc::deadLabel($s) : '✅ فعال') . "\\n"
            . '📈 حجم مصرف‌نشده: ' . ((float)$s['volume_gb'] > 0 ? Svc::volLabel((float)$q['left_gb']) : 'نامحدود') . "\\n"
            . '⏱ زمان باقی‌مانده: ' . fa_num((string)(int)$q['left_days']) . " روز\\n"
            . '💳 پرداختی این سرویس: ' . money((int)$q['pool']) . ' ' . $cur . "\\n";
        if ((int)$q['fee'] > 0) $txt .= '➖ کارمزد حذف: ' . money((int)$q['fee']) . ' ' . $cur . "\\n";
        $txt .= '💰 مبلغ عودتی به کیف پول: <b>' . money((int)$q['refund']) . ' ' . $cur . "</b>\\n";
        if (trim((string)$q['note']) !== '') $txt .= 'ℹ️ ' . h((string)$q['note']) . "\\n";
        $txt .= "\\n⚠️ با حذف، اتصال این کانفیگ از سرور پاک می‌شود و بازگشتی ندارد.";

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
            $txt = "✨ کانفیگ قطع‌شده‌ای ندارید.\\nهمهٔ سرویس‌های شما فعال هستند.";
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

        $txt = "🧹 <b>حذف کانفیگ‌های قطع</b>\\n" . '<code>─────────────────</code>' . "\\n"
            . '🔢 تعداد: ' . fa_num((string)count($dead)) . "\\n\\n"
            . implode("\\n", $lines)
            . "\\n\\n" . '💰 جمع مبلغ عودتی: <b>' . money($sum) . ' ' . $cur . '</b>'
            . "\\n⚠️ این کانفیگ‌ها از سرور پاک می‌شوند و بازگشتی ندارند.";

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

"""

if "function purgeDeadView" not in b:
    sig = "    private static function renewOptions($chatId, $msgId, int $id): void\n"
    assert b.count(sig) == 1, "Bot: renewOptions anchor not unique"
    b = b.replace(sig, BOT_METHODS + sig, 1)

if b != orig:
    write(BOT, b)

# ----------------------------------------------------------------- miniapp/api.php
API = "miniapp/api.php"
a = read(API)
orig = a

if "Svc::deadDelEnabled() && Svc::isDead($s)" not in a:
    old = "        'can_del'    => class_exists('Svc') && method_exists('Svc', 'userDelEnabled') && Svc::userDelEnabled()\n"
    assert a.count(old) == 1, "api: can_del anchor not unique"
    a = a.replace(
        old,
        "        'can_del'    => class_exists('Svc') && method_exists('Svc', 'userDelEnabled')\n"
        "            && (Svc::userDelEnabled()\n"
        "                || (method_exists('Svc', 'deadDelEnabled') && Svc::deadDelEnabled() && Svc::isDead($s)))\n",
        1,
    )

if "'is_dead'" not in a:
    old = "        'can_sync'   => (string)DB::setting('ma_btn_sync', '1') === '1',\n"
    assert a.count(old) == 1, "api: can_sync anchor not unique"
    a = a.replace(
        old,
        "        'is_dead'    => class_exists('Svc') && method_exists('Svc', 'isDead') ? Svc::isDead($s) : false,\n"
        "        'dead_txt'   => class_exists('Svc') && method_exists('Svc', 'deadLabel') && Svc::isDead($s) ? Svc::deadLabel($s) : '',\n"
        + old,
        1,
    )

if "!(Svc::deadDelEnabled() && Svc::isDead($s))" not in a:
    gate = re.compile(r"        if \(!Svc::userDelEnabled\(\)\) ma_fail\((?:'[^']*')\);\n")
    a, n = gate.subn(
        "        if (!Svc::userDelEnabled() && !(Svc::deadDelEnabled() && Svc::isDead($s))) "
        "ma_fail('حذف سرویس توسط مدیر غیرفعال شده است.');\n",
        a,
        count=1,
    )
    assert n == 1, "api: svc_del_quote gate not found"

if "'dead_on'" not in a:
    old = (
        "        foreach ($rows as $s) $out[] = ma_service_row($s);\n"
        "        ma_out(['ok' => true, 'services' => $out]);\n"
    )
    assert a.count(old) == 1, "api: services output anchor not unique"
    a = a.replace(
        old,
        "        foreach ($rows as $s) $out[] = ma_service_row($s);\n"
        "        /* fixed83: شمارش کانفیگ‌های قطع برای دکمهٔ پاک‌سازی گروهی */\n"
        "        $deadN = 0;\n"
        "        if (method_exists('Svc', 'isDead')) foreach ($rows as $s) if (Svc::isDead($s)) $deadN++;\n"
        "        ma_out(['ok' => true, 'services' => $out, 'dead' => $deadN,\n"
        "            'dead_on' => method_exists('Svc', 'deadDelEnabled') ? Svc::deadDelEnabled() : false]);\n",
        1,
    )

API_ACTIONS = """    /* fixed83: کانفیگ‌های قطع — فهرست و مبلغ عودتی */
    case 'svc_dead': {
        $items = []; $sum = 0;
        foreach (Svc::deadForUser($UID) as $s) {
            $q = Svc::deleteQuote($s);
            $sum += (int)$q['refund'];
            $items[] = [
                'id'         => (int)$s['id'],
                'name'       => (string)$s['client_email'],
                'status_txt' => Svc::deadLabel($s),
                'refund'     => (int)$q['refund'],
                'refund_txt' => ma_money((int)$q['refund']),
                'note'       => (string)$q['note'],
            ];
        }
        ma_out([
            'ok'         => true,
            'enabled'    => Svc::deadDelEnabled(),
            'count'      => count($items),
            'items'      => $items,
            'refund'     => $sum,
            'refund_txt' => ma_money($sum),
        ]);
    }

    /* fixed83: حذف گروهی کانفیگ‌های قطع با عودت وجه */
    case 'svc_purge_dead': {
        if ((string)($in['confirm'] ?? '') !== 'yes') ma_fail('برای حذف، تایید لازم است.');

        $me  = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        $ids = [];
        if (isset($in['ids']) && is_array($in['ids'])) foreach ($in['ids'] as $i) $ids[] = (int)$i;

        try {
            $r = Svc::purgeDeadForUser($me, $ids);
        } catch (Throwable $e) {
            app_log('miniapp', 'svc_purge_dead: ' . $e->getMessage());
            ma_fail('حذف انجام نشد.');
        }
        if (empty($r['ok'])) ma_fail((string)($r['message'] ?? 'کانفیگ قطع‌شده‌ای حذف نشد.'));

        $fresh = DB::one('SELECT * FROM {p}users WHERE id = :i', [':i' => $UID]) ?: [];
        ma_out([
            'ok'      => true,
            'message' => (string)$r['message'],
            'count'   => (int)$r['count'],
            'refund'  => (int)$r['refund'],
            'balance' => ma_money((int)($fresh['balance'] ?? 0)),
        ]);
    }

"""

if "case 'svc_dead'" not in a:
    sig = "    case 'wallet': {\n"
    assert a.count(sig) == 1, "api: wallet case anchor not unique"
    a = a.replace(sig, API_ACTIONS + sig, 1)

if a != orig:
    write(API, a)

# ----------------------------------------------------------------- miniapp/index.php
IDX = "miniapp/index.php"
i = read(IDX)
orig = i

if "data-dead=" not in i:
    old = "    h += '<button type=\"button\" class=\"btn gh w\" data-go=\"shop\" style=\"margin-top:4px\">🛒 خرید سرویس تازه</button>';\n"
    assert i.count(old) == 1, "index: shop button anchor not unique"
    i = i.replace(
        old,
        "    /* fixed83: پاک‌سازی گروهی کانفیگ‌های قطع (منقضی/غیرفعال/حجم تمام‌شده) */\n"
        "    var deadN = list.filter(function (s) { return s.is_dead && s.can_del !== false; }).length;\n"
        "    if (deadN > 0) {\n"
        "      h += '<button type=\"button\" class=\"btn gh w\" data-dead=\"1\" style=\"margin-top:4px\">"
        "🧹 حذف کانفیگ‌های قطع (' + fa(deadN) + ')</button>';\n"
        "    }\n"
        + old,
        1,
    )

IDX_JS = """  /* ================= حذف کانفیگ‌های قطع (fixed83) ================= */
  function deadSheet() {
    api('svc_dead').then(function (r) {
      if (!r.ok) { toast(r.message, 'err'); return; }
      if (!r.enabled) { toast('حذف کانفیگ‌های قطع توسط مدیر غیرفعال است.', 'err'); return; }
      if (!r.count) { toast('کانفیگ قطع‌شده‌ای ندارید.', 'ok'); return; }

      var h = '<div class="card tight">';
      r.items.forEach(function (it) {
        h += row('⛔️', it.name, it.status_txt || '', it.refund > 0 ? esc(it.refund_txt) : 'بدون عودت');
      });
      h += '</div>' +
        '<div class="card tight" style="margin-top:8px">' +
          row('🔢', 'تعداد کانفیگ قطع', '', fa(r.count)) +
          row('💰', 'جمع مبلغ عودتی به کیف پول', '', esc(r.refund_txt)) +
        '</div>' +
        '<div class="alert e">این کانفیگ‌ها از سرور پاک می‌شوند و بازگشتی ندارد.</div>' +
        '<button type="button" class="btn" style="width:100%" data-deadok="1">🧹 تایید حذف ' + fa(r.count) +
        ' کانفیگ' + (r.refund > 0 ? ' و دریافت ' + esc(r.refund_txt) : '') + '</button>';
      sheet('🧹 حذف کانفیگ‌های قطع', h);
    });
  }

  function doPurgeDead(btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spin"></span>'; }
    api('svc_purge_dead', { confirm: 'yes' }).then(function (r) {
      if (!r.ok) {
        toast(r.message, 'err');
        if (btn) { btn.disabled = false; btn.textContent = '🧹 تلاش دوباره'; }
        return;
      }
      toast(r.message, 'ok');
      closeSheet();
      try { go('services'); } catch (e) {}
    });
  }

"""

if "function deadSheet" not in i:
    sig = re.compile(r"  /\* =+ کیف پول =+ \*/\n")
    i, n = sig.subn(lambda m: IDX_JS + m.group(0), i, count=1)
    assert n == 1, "index: wallet section anchor not found"

if "data-deadok" not in i:
    old = "    if ((el = t.closest('[data-delok]'))) { haptic(); doDel(+el.getAttribute('data-delok'), el); return; }\n"
    assert i.count(old) == 1, "index: click router anchor not unique"
    i = i.replace(
        old,
        old
        + "    if ((el = t.closest('[data-dead]'))) { haptic(); deadSheet(); return; }\n"
        + "    if ((el = t.closest('[data-deadok]'))) { haptic(); doPurgeDead(el); return; }\n",
        1,
    )

if i != orig:
    write(IDX, i)

# ----------------------------------------------------------------- admin/pages/subs.php
ADM = "admin/pages/subs.php"
d = read(ADM)
orig = d

if "usr_dead_del" not in d:
    item = re.compile(r"(            \['k' => 'usr_del_enabled'[^\n]*\n[^\n]*\n)")
    NEW_ITEM = (
        "            ['k' => 'usr_dead_del', 'l' => 'کاربر بتواند کانفیگ‌های قطع را حذف کند', 't' => 'bool', 'd' => '1',\n"
        "                'n' => 'کانفیگ منقضی/غیرفعال/حجم‌تمام‌شده؛ مستقل از گزینهٔ بالا کار می‌کند و عودت طبق همان سیاست حساب می‌شود.'],\n"
    )
    d, n = item.subn(lambda m: m.group(1) + NEW_ITEM, d, count=1)
    assert n == 1, "admin: usr_del_enabled item not found"

    quick = "'usr_del_enabled', 'usr_del_refund'];"
    if quick in d:
        d = d.replace(quick, "'usr_del_enabled', 'usr_dead_del', 'usr_del_refund'];", 1)

if d != orig:
    write(ADM, d)

# ----------------------------------------------------------------- version.json
VER = "version.json"
v = read(VER)
orig = v

v = re.sub(r'("build"\s*:\s*)"[^"]*"', lambda m: m.group(1) + '"' + NEW_BUILD + '"', v, count=1)

ENTRY = (
    "🧹 حذف کانفیگ‌های قطع و عودت وجه در ربات و مینی‌اپ: دکمهٔ «حذف کانفیگ‌های قطع» برای پاک‌سازی گروهی "
    "کانفیگ‌های منقضی/غیرفعال/حجم‌تمام‌شده و دکمهٔ «حذف سرویس و عودت وجه» در جزئیات سرویس ربات، "
    "با محاسبهٔ مبلغ عودتی طبق سیاست مدیر و گزینهٔ جدید «usr_dead_del» در پنل"
)
if ENTRY[:40] not in v:
    m = re.search(r'("changelog"\s*:\s*\[\n)(\s+)', v)
    assert m, "version.json: changelog anchor not found"
    indent = m.group(2)
    v = v[: m.end(1)] + indent + json.dumps(ENTRY, ensure_ascii=False) + ",\n" + v[m.end(1) :]

json.loads(v)
if v != orig:
    write(VER, v)

print("changed files:", len(changed))
for c in changed:
    print(" -", c)
