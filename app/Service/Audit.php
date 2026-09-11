<?php
/**
 * Audit — لاگ اقدامات مدیران (fixed75)
 *
 * هر کار مدیریتی (پنل وب، منوی مدیریت ربات، کرون) با «کی / چه کاری / با چه جزئیاتی»
 * در جدول {p}logs ثبت می‌شود. جدول از قبل در schema.sql وجود دارد (actor, action, meta, created_at).
 *
 *  actor:  web:<username> | bot:<tg_id> | cron | system
 *  action: کلید کوتاه مانند user.ban ، wallet.approve ، setting.toggle ، web.panels.add
 *  meta:   JSON (مقادیر حساس مثل رمز/توکن خودکار ستاره می‌شوند)
 */
class Audit
{
    public const MAX_META = 4000;

    /* ---------------- ثبت ---------------- */

    /** عاملِ جاری */
    public static function actor(): string
    {
        $a = $GLOBALS['ADMIN'] ?? null;
        if (is_array($a)) return 'web:' . (string)($a['username'] ?? ('#' . (int)($a['id'] ?? 0)));
        if (class_exists('Bot', false) && isset(Bot::$u['tg_id'])) return 'bot:' . (int)Bot::$u['tg_id'];
        if (PHP_SAPI === 'cli') return 'cron';
        return 'system';
    }

    /** ثبت یک اقدام؛ هیچ‌وقت خطا به بیرون نمی‌دهد */
    public static function log(string $action, array $meta = [], ?string $actor = null): void
    {
        try {
            $meta = self::clean($meta);
            $json = $meta ? jenc($meta) : null;
            if ($json !== null && strlen($json) > self::MAX_META) $json = mb_substr($json, 0, self::MAX_META);
            DB::insert('logs', [
                'actor'      => mb_substr($actor ?? self::actor(), 0, 64),
                'action'     => mb_substr($action, 0, 64),
                'meta'       => $json,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            app_log('audit', 'insert failed: ' . $e->getMessage(), ['action' => $action]);
        }
    }

    /** حذف/کوتاه‌کردن مقادیر حساس یا طولانی */
    public static function clean(array $meta): array
    {
        $out = [];
        foreach ($meta as $k => $v) {
            $lk = strtolower((string)$k);
            if ($lk === '_t' || preg_match('/pass|secret|token|api_?key|cookie|otp|pin|hash/', $lk)) {
                $out[$k] = '***';
                continue;
            }
            if (is_array($v)) {
                $v = self::clean($v);
            } elseif (is_string($v)) {
                $v = trim($v);
                if (mb_strlen($v) > 300) $v = mb_substr($v, 0, 300) . '…';
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * ثبت خودکار درخواست‌های POST پنل وب — از shutdown صدا می‌شود تا حتی بعد از redirect هم ثبت شود.
     * نتیجهٔ کار از آخرین پیام flash خوانده می‌شود (ok / err / warn).
     */
    public static function webHook(string $page, string $act): void
    {
        $meta = ['ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '')];
        $n = 0;
        foreach ($_POST as $k => $v) {
            if ($k === 'act' || $k === '_t') continue;
            if ($n++ >= 25) { $meta['_more'] = true; break; }
            $meta[(string)$k] = is_array($v) ? (count($v) . ' مورد') : (string)$v;
        }
        if (!empty($_FILES)) $meta['files'] = implode(',', array_keys($_FILES));
        $fl = $_SESSION['vs_flash'] ?? [];
        if (is_array($fl) && $fl) {
            $last = end($fl);
            $meta['result'] = (string)($last['type'] ?? '');
            $meta['msg']    = mb_substr(trim(strip_tags((string)($last['msg'] ?? ''))), 0, 200);
        }
        self::log('web.' . $page . '.' . $act, $meta);
    }

    /* ---------------- خواندن ---------------- */

    private static function where(string $actor, string $q, int $days, array &$b): string
    {
        $w = ['1=1'];
        if ($actor !== '') { $w[] = 'actor = :a'; $b[':a'] = $actor; }
        if ($q !== '') {
            $w[] = '(action LIKE :q1 OR meta LIKE :q2 OR actor LIKE :q3)';
            $b[':q1'] = '%' . $q . '%'; $b[':q2'] = '%' . $q . '%'; $b[':q3'] = '%' . $q . '%';
        }
        if ($days > 0) $w[] = 'created_at >= DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY)';
        return implode(' AND ', $w);
    }

    public static function recent(int $limit = 50, string $actor = '', string $q = '', int $days = 0, int $page = 1): array
    {
        $b = [];
        $w = self::where($actor, $q, $days, $b);
        $limit = max(1, min(500, $limit));
        $off   = max(0, ($page - 1) * $limit);
        $rows  = DB::all('SELECT * FROM {p}logs WHERE ' . $w . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $off, $b);
        return $rows ?: [];
    }

    public static function count(string $actor = '', string $q = '', int $days = 0): int
    {
        $b = [];
        $w = self::where($actor, $q, $days, $b);
        return (int)DB::val('SELECT COUNT(*) FROM {p}logs WHERE ' . $w, $b);
    }

    /** فهرست عامل‌ها با تعداد اقدام */
    public static function actors(int $days = 30): array
    {
        $rows = DB::all('SELECT actor, COUNT(*) AS cnt FROM {p}logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL ' . max(1, $days) . ' DAY)'
            . ' GROUP BY actor ORDER BY cnt DESC LIMIT 30');
        return $rows ?: [];
    }

    /** آمار سریع برای هاب ربات و کارت‌های پنل */
    public static function stats(): array
    {
        $today = (int)DB::val('SELECT COUNT(*) FROM {p}logs WHERE created_at >= CURDATE()');
        $week  = (int)DB::val('SELECT COUNT(*) FROM {p}logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)');
        $total = (int)DB::val('SELECT COUNT(*) FROM {p}logs');
        $top   = DB::one('SELECT actor, COUNT(*) AS cnt FROM {p}logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY actor ORDER BY cnt DESC LIMIT 1');
        return [
            'today' => $today,
            'week'  => $week,
            'total' => $total,
            'top'   => $top ? (string)$top['actor'] : '',
            'top_n' => $top ? (int)$top['cnt'] : 0,
        ];
    }

    /** حذف لاگ‌های قدیمی‌تر از N روز */
    public static function prune(int $days): int
    {
        $days = max(1, $days);
        $n = (int)DB::val('SELECT COUNT(*) FROM {p}logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)');
        DB::q('DELETE FROM {p}logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)');
        return $n;
    }

    /* ---------------- نمایش ---------------- */

    /** برچسب خوانا برای کلید اقدام */
    public static function label(string $action): string
    {
        static $map = [
            'user.ban'          => '⛔️ مسدودکردن کاربر',
            'user.unban'        => '✅ رفع مسدودی کاربر',
            'wallet.credit'     => '💰 شارژ دستی کیف پول',
            'wallet.debit'      => '💸 کسر دستی از کیف پول',
            'wallet.approve'    => '✅ تایید رسید پرداخت',
            'wallet.reject'     => '❌ رد رسید پرداخت',
            'wallet.undo'       => '↩️ لغو تایید پرداخت',
            'broadcast.send'    => '📣 پیام همگانی',
            'reseller.approve'  => '🏷 تایید نمایندگی',
            'reseller.reject'   => '🏷 رد نمایندگی',
            'setting.toggle'    => '⚙️ تغییر کلید تنظیمات',
            'setting.number'    => '🔢 تغییر مقدار تنظیمات',
            'maintenance'       => '🚧 حالت تعمیر',
            'campaign.start'    => '🔥 شروع کمپین',
            'campaign.stop'     => '⏹ توقف کمپین',
            'bulk.gift'         => '🎁 هدیهٔ گروهی',
            'autorenew.toggle'  => '🔁 تمدید خودکار',
            'autorenew.run'     => '🔁 اجرای دستی تمدید خودکار',
            'svc.autodel'       => '🧹 حذف تمام‌شده‌ها',
            'svc.purge'         => '♻️ پاکسازی سطل زباله',
            'svc.clearips'      => '📵 پاک‌کردن IPهای سرویس',
            'export.csv'        => '📤 خروجی CSV',
            'audit.prune'       => '🧽 پاکسازی لاگ اقدامات',
            'login.ok'          => '🔐 ورود به پنل وب',
            'login.fail'        => '🚫 ورود ناموفق',
            'test.blocked'      => '🧪 جلوگیری از سوءاستفادهٔ تست',
        ];
        if (isset($map[$action])) return $map[$action];
        if (strpos($action, 'web.') === 0) {
            $parts = explode('.', $action, 3);
            $pg  = $parts[1] ?? '';
            $act = $parts[2] ?? '';
            $pages = $GLOBALS['PAGES'] ?? [];
            $pt = isset($pages[$pg]) ? (string)$pages[$pg][0] : $pg;
            return '🌐 ' . $pt . ' › ' . self::actLabel($act);
        }
        return $action;
    }

    private static function actLabel(string $act): string
    {
        static $m = [
            'add' => 'افزودن', 'edit' => 'ویرایش', 'del' => 'حذف', 'delete' => 'حذف', 'save' => 'ذخیره',
            'toggle' => 'روشن/خاموش', 'sync' => 'همگام‌سازی', 'test' => 'تست', 'run' => 'اجرا',
            'approve' => 'تایید', 'reject' => 'رد', 'ok' => 'تایید', 'no' => 'رد', 'ban' => 'مسدود', 'unban' => 'رفع مسدودی',
            'bal' => 'تغییر موجودی', 'reply' => 'پاسخ', 'close' => 'بستن', 'upload' => 'آپلود', 'restore' => 'بازگردانی',
            'backup' => 'بکاپ', 'migrate' => 'مایگریت', 'rollback' => 'بازگشت نسخه', 'check' => 'بررسی', 'cfg' => 'پیکربندی',
            'prune' => 'پاکسازی', 'hook' => 'ثبت وب‌هوک', 'recover' => 'بازیابی سفارش‌ها', 'sort' => 'مرتب‌سازی',
        ];
        return $m[$act] ?? $act;
    }

    /** نام خوانای عامل */
    public static function actorLabel(string $actor): string
    {
        if (strpos($actor, 'web:') === 0) return '🌐 ' . substr($actor, 4);
        if (strpos($actor, 'bot:') === 0) return '🤖 ' . substr($actor, 4);
        if ($actor === 'cron') return '⏱ کرون';
        return '⚙️ ' . $actor;
    }

    /** خلاصهٔ یک‌خطی متادیتا */
    public static function metaLine(?string $meta, int $max = 140): string
    {
        $m = jdec($meta, []);
        if (!is_array($m) || !$m) return '';
        $parts = [];
        foreach ($m as $k => $v) {
            if ($k === 'ip' || $k === '_more') continue;
            if (is_array($v)) $v = jenc($v);
            if (is_bool($v)) $v = $v ? 'بله' : 'خیر';
            $parts[] = $k . '=' . (string)$v;
        }
        $s = implode(' • ', $parts);
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
    }
}
