<?php
declare(strict_types=1);

/**
 * انتشار «لیست تغییرات» در کانال یا گروه تلگرام
 *
 *  ۱) ربات را در کانال/گروه ادمین کنید
 *  ۲) شناسهٔ چت را در تنظیمات ‹ کانال انتشار › وارد کنید
 *  ۳) اگر گروه تاپیک‌دار باشد، یک تاپیک مخصوص ساخته می‌شود
 *  ۴) هر بار به‌روزرسانی نصب شود، پیام زیبا و ساختارمند منتشر می‌شود
 *
 * چهار قالب پیام با ظاهر کاملاً متفاوت پشتیبانی می‌شود.
 */
class Release
{
    /* ---------- کلید تنظیمات ---------- */
    public const S_ENABLED = 'rel_enabled';
    public const S_CHAT    = 'rel_chat_id';
    public const S_TOPIC   = 'rel_topic_id';
    public const S_TNAME   = 'rel_topic_name';
    public const S_TCOLOR  = 'rel_topic_color';
    public const S_AUTO    = 'rel_auto';
    public const S_STYLE   = 'rel_style';
    public const S_PIN     = 'rel_pin';
    public const S_SILENT  = 'rel_silent';
    public const S_TITLE   = 'rel_title';
    public const S_FOOTER  = 'rel_footer';
    public const S_BTXT    = 'rel_btn_text';
    public const S_BURL    = 'rel_btn_url';
    public const S_DRAFT   = 'rel_draft';
    public const S_HIST    = 'rel_history';
    public const S_LASTV   = 'rel_last_ver';
    public const S_LASTAT  = 'rel_last_at';
    public const S_LASTMID = 'rel_last_msg';

    /** قالب‌های پیام: کلید => [نام، توضیح] */
    public const STYLES = [
        'hero'    => ['🚀 بنر حرفه‌ای', 'کادر تزئینی، نسخهٔ برجسته و تغییرات دسته‌بندی‌شده — پیشنهاد ما'],
        'card'    => ['🗂 کارت اطلاعات', 'قالب کلید-مقدار مرتب، شبیه گزارش‌های رسمی'],
        'minimal' => ['✨ ساده و تمیز', 'کوتاه و بی‌حاشیه، مناسب کانال‌های پرترافیک'],
        'dev'     => ['🛠 فنی / دولوپر', 'قالب تک‌عرض با برچسب نوع تغییر، شبیه CHANGELOG'],
    ];

    /** رنگ آیکون تاپیک (مقادیر مجاز تلگرام) */
    public const COLORS = [
        '9367192'  => '🟢 سبز',
        '7322096'  => '🔵 آبی',
        '13338331' => '🟣 بنفش',
        '16766590' => '🟡 زرد',
        '16749490' => '🌸 صورتی',
        '16478047' => '🔴 قرمز',
    ];

    /** نوع تغییر: کلید => [آیکون، عنوان فارسی، برچسب فنی] */
    public const TYPES = [
        'feat'  => ['✨', 'قابلیت‌های جدید', 'feat'],
        'fix'   => ['🐞', 'رفع اشکال',        'fix'],
        'sec'   => ['🛡', 'امنیت',            'sec'],
        'perf'  => ['🚀', 'بهینه‌سازی',        'perf'],
        'ui'    => ['🎨', 'ظاهر و رابط کاربری', 'ui'],
        'up'    => ['⚡️', 'بهبود و ارتقا',    'impr'],
        'del'   => ['🗑', 'حذف‌شده‌ها',        'del'],
        'doc'   => ['📘', 'مستندات و راهنما',  'docs'],
        'other' => ['🔹', 'سایر تغییرات',     'misc'],
    ];

    /** واژه‌های تشخیص نوع — ترتیب بررسی مهم است */
    private const RULES = [
        'sec'  => ['امنیت', 'رمزنگاری', 'نشت', 'آسیب‌پذیر', 'دسترسی غیرمجاز', 'هک', 'security', 'secure', 'vuln', 'xss', 'csrf', 'inject', 'leak'],
        'fix'  => ['رفع', 'فیکس', 'باگ', 'اشکال', 'خطا', 'مشکل', 'تصحیح', 'درست شد', 'fix', 'bug', 'patch', 'resolve', 'hotfix'],
        'feat' => ['افزودن', 'اضافه', 'جدید', 'قابلیت', 'امکان', 'اضافه شد', 'feat', 'add', 'new', 'implement', 'introduce', 'support'],
        'ui'   => ['ظاهر', 'زیباساز', 'رابط', 'طراحی', 'تم ', 'رنگ', 'چیدمان', 'آیکون', 'ui', 'ux', 'design', 'theme', 'style', 'layout'],
        'perf' => ['سرعت', 'بهینه', 'کش ', 'سبک‌ساز', 'کاهش حجم', 'perf', 'optimiz', 'faster', 'speed', 'cache'],
        'del'  => ['حذف', 'برداشته', 'منسوخ', 'remove', 'delete', 'drop', 'deprecat'],
        'doc'  => ['مستند', 'راهنما', 'آموزش', 'توضیحات', 'docs', 'readme', 'guide', 'tutorial'],
        'up'   => ['بهبود', 'ارتقا', 'به‌روز', 'بروزرسان', 'بازنویسی', 'گسترش', 'improve', 'enhance', 'update', 'upgrade', 'refactor', 'tweak'],
    ];

    /* ==================== خواندن تنظیمات ==================== */

    public static function enabled(): bool { return (string)DB::setting(self::S_ENABLED, '0') === '1'; }
    public static function chat(): string  { return trim((string)DB::setting(self::S_CHAT, '')); }
    public static function topicId(): int  { return max(0, (int)DB::setting(self::S_TOPIC, 0)); }
    public static function autoOn(): bool  { return (string)DB::setting(self::S_AUTO, '1') === '1'; }
    public static function pinOn(): bool    { return (string)DB::setting(self::S_PIN, '0') === '1'; }
    public static function silentOn(): bool { return (string)DB::setting(self::S_SILENT, '0') === '1'; }
    public static function footer(): string { return trim((string)DB::setting(self::S_FOOTER, '')); }
    public static function draft(): string  { return (string)DB::setting(self::S_DRAFT, ''); }

    public static function topicName(): string
    {
        $n = trim((string)DB::setting(self::S_TNAME, ''));
        return $n !== '' ? $n : '🚀 آپدیت‌ها و تغییرات';
    }

    public static function topicColor(): int
    {
        $c = (string)DB::setting(self::S_TCOLOR, '9367192');
        return isset(self::COLORS[$c]) ? (int)$c : 9367192;
    }

    public static function style(): string
    {
        $s = (string)DB::setting(self::S_STYLE, 'hero');
        return isset(self::STYLES[$s]) ? $s : 'hero';
    }

    public static function title(): string
    {
        $t = trim((string)DB::setting(self::S_TITLE, ''));
        if ($t !== '') return $t;
        return (defined('APP_BRAND') && (string)APP_BRAND !== '') ? (string)APP_BRAND : 'ربات';
    }

    public static function version(): string
    {
        return defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';
    }

    /** آماده برای انتشار؟ */
    public static function ready(): bool { return self::enabled() && self::chat() !== ''; }

    /** دکمهٔ شیشه‌ای زیر پیام */
    public static function button(): ?array
    {
        $t = trim((string)DB::setting(self::S_BTXT, ''));
        $u = trim((string)DB::setting(self::S_BURL, ''));
        if ($t === '' || $u === '') return null;
        if (!preg_match('~^(https?://|tg://)~i', $u)) return null;
        return ['text' => mb_substr($t, 0, 60), 'url' => $u];
    }

    /* ==================== وضعیت چت ==================== */

    /** بررسی دسترسی ربات به کانال/گروه */
    public static function chatInfo(): array
    {
        $chat = self::chat();
        if ($chat === '') {
            return ['ok' => false, 'message' => 'ابتدا شناسهٔ کانال را وارد و ذخیره کنید.'];
        }

        $r = Tg::api('getChat', ['chat_id' => $chat]);
        if (empty($r['ok'])) {
            return [
                'ok' => false,
                'message' => 'ربات به این چت دسترسی ندارد — ' . (string)($r['description'] ?? 'خطای نامشخص')
                    . ' | مطمئن شوید ربات را عضو و «ادمین» کرده‌اید و شناسه درست است.',
            ];
        }

        $c    = $r['result'] ?? [];
        $type = (string)($c['type'] ?? '');
        $out  = [
            'ok'         => true,
            'title'      => (string)($c['title'] ?? '—'),
            'type'       => $type,
            'username'   => (string)($c['username'] ?? ''),
            'is_forum'   => !empty($c['is_forum']),
            'is_channel' => $type === 'channel',
            'admin'      => false,
            'can_post'   => false,
            'can_topics' => false,
        ];

        $me  = Tg::getMe();
        $bid = (int)($me['result']['id'] ?? 0);
        if ($bid > 0) {
            $m = Tg::api('getChatMember', ['chat_id' => $chat, 'user_id' => $bid]);
            if (!empty($m['ok'])) {
                $res = $m['result'] ?? [];
                $st  = (string)($res['status'] ?? '');
                $out['admin'] = in_array($st, ['administrator', 'creator'], true);
                if ($st === 'creator') {
                    $out['can_post']   = true;
                    $out['can_topics'] = true;
                } elseif ($st === 'administrator') {
                    $out['can_post']   = $out['is_channel'] ? !empty($res['can_post_messages']) : true;
                    $out['can_topics'] = !empty($res['can_manage_topics']);
                }
            }
        }

        $kind = $out['is_channel'] ? 'کانال' : (($type === 'supergroup' || $type === 'group') ? 'گروه' : $type);
        $msg  = $kind . ' «' . $out['title'] . '»';
        $msg .= $out['admin'] ? ' • ربات ادمین است ✅' : ' • ربات ادمین نیست ⚠️';
        if ($out['is_channel']) {
            $msg .= $out['can_post'] ? ' • اجازهٔ ارسال دارد ✅' : ' • اجازهٔ ارسال پیام ندارد ⚠️';
            $msg .= ' • کانال‌ها تاپیک ندارند، پیام مستقیم منتشر می‌شود';
        } else {
            $msg .= $out['is_forum'] ? ' • حالت تاپیک فعال ✅' : ' • حالت تاپیک خاموش است (از تنظیمات گروه Topics را روشن کنید)';
        }
        $out['message'] = $msg;
        return $out;
    }

    /** ساخت تاپیک مخصوص انتشار آپدیت‌ها */
    public static function setupTopic(bool $force = false): array
    {
        $info = self::chatInfo();
        if (empty($info['ok'])) return ['ok' => false, 'message' => (string)$info['message']];

        if (!empty($info['is_channel'])) {
            DB::setSetting(self::S_TOPIC, '0');
            return ['ok' => true, 'message' => 'این یک کانال است و تاپیک ندارد؛ پیام‌ها مستقیم در خودِ کانال منتشر می‌شوند. اگر تاپیک می‌خواهید، از یک گروه با حالت Topics استفاده کنید.'];
        }
        if (empty($info['is_forum'])) {
            return ['ok' => false, 'message' => 'حالت تاپیک (Topics) در این گروه خاموش است. تنظیمات گروه ‹ Topics › را روشن کنید و دوباره تلاش کنید.'];
        }
        if (empty($info['admin']) || empty($info['can_topics'])) {
            return ['ok' => false, 'message' => 'ربات دسترسی «مدیریت تاپیک‌ها» ندارد. در تنظیمات ادمینِ ربات، گزینهٔ Manage Topics را روشن کنید.'];
        }
        if (!$force && self::topicId() > 0) {
            return ['ok' => true, 'message' => 'تاپیک از قبل ساخته شده است (شناسه ' . fa_num((string)self::topicId()) . '). برای ساخت مجدد گزینهٔ «ساخت دوباره» را بزنید.'];
        }

        $r = Tg::api('createForumTopic', [
            'chat_id'    => self::chat(),
            'name'       => mb_substr(self::topicName(), 0, 120),
            'icon_color' => self::topicColor(),
        ]);
        $tid = (int)($r['result']['message_thread_id'] ?? 0);
        if (empty($r['ok']) || $tid <= 0) {
            return ['ok' => false, 'message' => 'ساخت تاپیک ناموفق بود — ' . (string)($r['description'] ?? 'خطای نامشخص')];
        }

        DB::setSetting(self::S_TOPIC, (string)$tid);
        DB::loadSettings(true);

        self::push(self::welcome());
        return ['ok' => true, 'message' => '✅ تاپیک «' . self::topicName() . '» ساخته شد (شناسه ' . fa_num((string)$tid) . ').', 'topic' => $tid];
    }

    private static function welcome(): string
    {
        $b = self::title();
        return "🎊 <b>" . h($b) . " — کانال آپدیت‌ها</b>\n"
            . "از این پس تمام تغییرات و نسخه‌های جدید همین‌جا منتشر می‌شود.\n\n"
            . '• نسخهٔ فعلی: <code>' . h(self::version()) . "</code>\n"
            . '🕒 ' . h(to_jalali(now(), true));
    }

    /* ==================== تحلیل لیست تغییرات ==================== */

    /** تشخیص نوع یک خط تغییر */
    public static function classify(string $line): string
    {
        $l = ' ' . mb_strtolower(trim($line)) . ' ';

        /* برچسب صریح در ابتدای خط، مثل: [fix] یا feat: */
        if (preg_match('~^\s*[\[(]?([a-z]{3,6})[\])]?\s*[:\-–]~i', $line, $m)) {
            $tag = strtolower($m[1]);
            $map = ['feat' => 'feat', 'add' => 'feat', 'new' => 'feat', 'fix' => 'fix', 'bug' => 'fix',
                    'sec' => 'sec', 'perf' => 'perf', 'ui' => 'ui', 'ux' => 'ui', 'style' => 'ui',
                    'impr' => 'up', 'up' => 'up', 'chore' => 'up', 'refac' => 'up',
                    'del' => 'del', 'rem' => 'del', 'docs' => 'doc', 'doc' => 'doc'];
            if (isset($map[$tag])) return $map[$tag];
        }

        foreach (self::RULES as $type => $words) {
            foreach ($words as $w) {
                if (mb_strpos($l, mb_strtolower($w)) !== false) return $type;
            }
        }
        return 'other';
    }

    /** متن خام یا آرایه → آرایهٔ دسته‌بندی‌شده [type => [متن‌ها]] */
    public static function parse($raw): array
    {
        $lines = [];
        if (is_array($raw)) {
            foreach ($raw as $r) $lines[] = (string)$r;
        } else {
            $lines = preg_split('~\r\n|\r|\n~', (string)$raw) ?: [];
        }

        $groups = [];
        foreach ($lines as $ln) {
            $t = trim((string)$ln);
            $t = preg_replace('~^\s*[\-\*•▪◦–]+\s*~u', '', $t) ?? $t;
            $t = trim($t);
            if ($t === '') continue;
            $type = self::classify($t);
            /* حذف برچسب صریح از متن نمایشی */
            $t = preg_replace('~^\s*[\[(]?[a-z]{3,6}[\])]?\s*[:\-–]\s*~i', '', $t) ?? $t;
            $t = trim($t);
            if ($t === '') continue;
            $groups[$type][] = mb_substr($t, 0, 220);
        }

        /* مرتب‌سازی بر اساس ترتیب TYPES */
        $out = [];
        foreach (array_keys(self::TYPES) as $k) {
            if (!empty($groups[$k])) $out[$k] = $groups[$k];
        }
        return $out;
    }

    /** لیست تغییرات آماده: پیش‌نویس ادمین ← تنظیم مخزن ← version.json محلی */
    public static function pending(): array
    {
        $d = self::draft();
        if (trim($d) !== '') return self::parse($d);

        $c = jdec((string)DB::setting('update_changelog', '[]'), []);
        if (is_array($c) && $c) return self::parse($c);

        /* fixed79: بخش بالای CHANGELOG.md (بستهٔ fixedNN فعلی) قبل از version.json */
        $b = self::localBuild();
        if ($b && $b['lines']) return self::parse($b['lines']);

        return self::parse(self::localChangelog());
    }

    /** خواندن changelog از version.json کنار پروژه */
    public static function localChangelog(): array
    {
        $f = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/version.json';
        if (!is_file($f)) return [];
        $d = jdec((string)@file_get_contents($f), []);
        $c = $d['changelog'] ?? [];
        if (is_array($c)) return $c;
        return $c !== '' ? [(string)$c] : [];
    }

    public static function countItems(array $groups): int
    {
        $n = 0;
        foreach ($groups as $rows) $n += count($rows);
        return $n;
    }

    /* ==================== ساخت متن پیام ==================== */

    /**
     * @param array $d from, to, files, changes(array|string), style, auto
     */
    public static function render(array $d = []): string
    {
        $style  = (string)($d['style'] ?? self::style());
        if (!isset(self::STYLES[$style])) $style = 'hero';

        $from   = trim((string)($d['from'] ?? ''));
        $to     = trim((string)($d['to'] ?? self::version()));
        if ($to === '') $to = self::version();
        $files  = max(0, (int)($d['files'] ?? 0));

        $ch = $d['changes'] ?? null;
        $groups = ($ch === null) ? self::pending() : (is_array($ch) ? (isset($ch[0]) ? self::parse($ch) : $ch) : self::parse($ch));
        $items  = self::countItems($groups);

        if ($style === 'card')    return self::rCard($from, $to, $files, $groups, $items);
        if ($style === 'minimal') return self::rMin($from, $to, $files, $groups, $items);
        if ($style === 'dev')     return self::rDev($from, $to, $files, $groups, $items);
        return self::rHero($from, $to, $files, $groups, $items);
    }

    /* قالب ۱ — بنر حرفه‌ای */
    private static function rHero(string $from, string $to, int $files, array $groups, int $items): string
    {
        $brand = h(self::title());
        $o  = "╭─────────────────────╮\n";
        $o .= "    🚀 <b>" . $brand . "</b>\n";
        $o .= '    <b>نسخهٔ ' . h($to) . "</b>\n";
        $o .= "╰─────────────────────╯\n\n";
        $o .= "🎉 <b>به‌روزرسانی جدید منتشر شد!</b>\n";
        if ($from !== '' && $from !== $to) {
            $o .= '<code>' . h($from) . '</code>  ⟶  <code>' . h($to) . "</code>\n";
        }
        $o .= "\n━━━━━━━━━━━━━━━━━━━━\n";

        if (!$groups) {
            $o .= "\n📄 فهرست تغییرات ثبت نشده است.\n";
        }
        foreach ($groups as $type => $rows) {
            [$icon, $label] = self::TYPES[$type] ?? self::TYPES['other'];
            $o .= "\n" . $icon . ' <b>' . h($label) . '</b>  <i>(' . fa_num((string)count($rows)) . ")</i>\n";
            foreach ($rows as $r) $o .= '  ┗ ' . h($r) . "\n";
        }

        $o .= "\n━━━━━━━━━━━━━━━━━━━━\n";
        $o .= '📌 مجموع تغییرات: <b>' . fa_num((string)$items) . "</b>\n";
        if ($files > 0) $o .= '📦 فایل‌های به‌روزشده: <b>' . fa_num((string)$files) . "</b>\n";
        $o .= '🕒 ' . h(to_jalali(now(), true)) . "\n";
        return $o . self::tail();
    }

    /* قالب ۲ — کارت اطلاعات */
    private static function rCard(string $from, string $to, int $files, array $groups, int $items): string
    {
        $line = "――――――――――――――――\n";
        $o  = '⬆️ <b>به‌روزرسانی ' . h(self::title()) . "</b>\n" . $line;
        $o .= '• نسخهٔ پیشین: <code>' . h($from !== '' ? $from : '—') . "</code>\n";
        $o .= '• نسخهٔ جدید: <b>' . h($to) . "</b>\n";
        $o .= '• تعداد تغییرات: <b>' . fa_num((string)$items) . "</b>\n";
        if ($files > 0) $o .= '• فایل‌های نوشته‌شده: <b>' . fa_num((string)$files) . "</b>\n";
        $o .= '• تاریخ انتشار: <b>' . h(to_jalali(now(), true)) . "</b>\n";
        $o .= $line;

        foreach ($groups as $type => $rows) {
            [$icon, $label] = self::TYPES[$type] ?? self::TYPES['other'];
            $o .= "\n◾️ " . $icon . ' <b>' . h($label) . "</b>\n";
            foreach ($rows as $r) $o .= '     • ' . h($r) . "\n";
        }
        if (!$groups) $o .= "\n◾️ فهرست تغییرات ثبت نشده است.\n";

        return $o . $line . self::tail();
    }

    /* قالب ۳ — ساده و تمیز */
    private static function rMin(string $from, string $to, int $files, array $groups, int $items): string
    {
        $o = '<b>' . h(self::title()) . ' ' . h($to) . '</b>  <i>· ' . fa_num((string)$items) . " تغییر</i>\n\n";
        foreach ($groups as $type => $rows) {
            [$icon] = self::TYPES[$type] ?? self::TYPES['other'];
            foreach ($rows as $r) $o .= $icon . ' ' . h($r) . "\n";
        }
        if (!$groups) $o .= "🔹 بدون فهرست تغییرات\n";
        $o .= "\n<i>" . h(to_jalali(now(), false));
        if ($from !== '' && $from !== $to) $o .= ' · از ' . h($from);
        $o .= "</i>\n";
        return $o . self::tail();
    }

    /* قالب ۴ — فنی / دولوپر */
    private static function rDev(string $from, string $to, int $files, array $groups, int $items): string
    {
        $o  = '🛠 <b>release · v' . h($to) . "</b>\n";
        $b  = "CHANGELOG\n";
        $b .= '=========================' . "\n";
        $b .= 'from : ' . ($from !== '' ? $from : '-') . "\n";
        $b .= 'to   : ' . $to . "\n";
        $b .= 'date : ' . date('Y-m-d H:i') . "\n";
        $b .= 'items: ' . $items . ($files > 0 ? '   files: ' . $files : '') . "\n";
        $b .= '-------------------------' . "\n";
        foreach ($groups as $type => $rows) {
            $tag = self::TYPES[$type][2] ?? 'misc';
            $pad = str_pad($tag, 5, ' ', STR_PAD_RIGHT);
            foreach ($rows as $r) $b .= '[' . $pad . '] ' . self::plain($r) . "\n";
        }
        if (!$groups) $b .= "(no entries)\n";
        $b .= '=========================' . "\n";
        $o .= '<pre>' . h($b) . '</pre>';
        return $o . self::tail();
    }

    private static function tail(): string
    {
        $f = self::footer();
        return $f !== '' ? "\n" . $f . "\n" : '';
    }

    private static function plain(string $s): string
    {
        $s = str_replace(['<br>', '<br/>', '<br />'], "\n", $s);
        $s = strip_tags($s);
        return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /* ==================== انتشار ==================== */

    /** ارسال خام در چت/تاپیک تنظیم‌شده */
    private static function push(string $text, array $extra = []): array
    {
        $chat = self::chat();
        if ($chat === '') return ['ok' => false, 'description' => 'chat not set'];

        $p = array_merge([
            'chat_id'    => $chat,
            'text'       => mb_substr($text, 0, 3900),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra);

        $tid = self::topicId();
        if ($tid > 0) $p['message_thread_id'] = $tid;
        if (self::silentOn()) $p['disable_notification'] = true;

        $btn = self::button();
        if ($btn !== null) $p['reply_markup'] = ['inline_keyboard' => [[$btn]]];

        $r = Tg::api('sendMessage', $p);

        /* تاپیک حذف یا گم شده — یک‌بار بدون تاپیک */
        if (empty($r['ok']) && $tid > 0) {
            $d = strtolower((string)($r['description'] ?? ''));
            if (strpos($d, 'thread') !== false || strpos($d, 'topic') !== false) {
                DB::setSetting(self::S_TOPIC, '0');
                unset($p['message_thread_id']);
                $r = Tg::api('sendMessage', $p);
            }
        }

        /* HTML نامعتبر — بدون قالب‌بندی تلاش می‌کنیم */
        if (empty($r['ok'])) {
            $d = strtolower((string)($r['description'] ?? ''));
            if (strpos($d, 'parse') !== false || strpos($d, 'entit') !== false || strpos($d, 'tag') !== false) {
                unset($p['parse_mode']);
                $p['text'] = mb_substr(self::plain($text), 0, 3900);
                $r = Tg::api('sendMessage', $p);
            }
        }
        return $r;
    }

    /**
     * انتشار پیام تغییرات
     * @param array $d from, to, files, changes, style, pin, record
     */
    public static function publish(array $d = []): array
    {
        if (self::chat() === '') {
            return ['ok' => false, 'message' => 'شناسهٔ کانال تنظیم نشده است.'];
        }

        $text = self::render($d);
        $r    = self::push($text);

        if (empty($r['ok'])) {
            $desc = (string)($r['description'] ?? 'خطای نامشخص');
            if (function_exists('app_log')) app_log('release', 'publish failed', ['desc' => $desc]);
            return ['ok' => false, 'message' => 'انتشار ناموفق بود — ' . $desc, 'text' => $text];
        }

        $mid = (int)($r['result']['message_id'] ?? 0);

        $wantPin = array_key_exists('pin', $d) ? (bool)$d['pin'] : self::pinOn();
        if ($wantPin && $mid > 0) {
            Tg::api('pinChatMessage', [
                'chat_id' => self::chat(),
                'message_id' => $mid,
                'disable_notification' => true,
            ]);
        }

        if (!array_key_exists('record', $d) || !empty($d['record'])) {
            $to = trim((string)($d['to'] ?? self::version()));
            DB::setSetting(self::S_LASTV, $to !== '' ? $to : self::version());
            DB::setSetting(self::S_LASTAT, now());
            DB::setSetting(self::S_LASTMID, (string)$mid);
            self::addHistory([
                'at'    => now(),
                'from'  => (string)($d['from'] ?? ''),
                'to'    => $to,
                'items' => self::countItems(self::parse($d['changes'] ?? self::draft())),
                'files' => (int)($d['files'] ?? 0),
                'style' => (string)($d['style'] ?? self::style()),
                'msg'   => $mid,
                'auto'  => !empty($d['auto']),
            ]);
            /* پیش‌نویس مصرف شد */
            if (self::draft() !== '') DB::setSetting(self::S_DRAFT, '');
            DB::loadSettings(true);
        }

        return ['ok' => true, 'message' => '✅ لیست تغییرات در کانال منتشر شد.', 'msg' => $mid, 'text' => $text];
    }

    /** پیام آزمایشی */
    public static function test(): array
    {
        if (self::chat() === '') return ['ok' => false, 'message' => 'شناسهٔ کانال تنظیم نشده است.'];

        $sample = [
            'افزودن سربرگ کانال انتشار آپدیت‌ها در تنظیمات',
            'رفع اشکال نمایش قیمت در پنل نماینده',
            'زیباسازی کامل بخش تنظیمات و مدیریت ساب',
            'بهبود سرعت بارگذاری فهرست سرویس‌ها',
            'امنیت: جلوگیری از نشت توکن در مسیر رسید پرداخت',
        ];
        $r = self::push("🧪 <b>پیام آزمایشی</b>\n\n" . self::render([
            'from' => self::version(), 'to' => self::version(),
            'changes' => $sample, 'files' => 0,
        ]));

        return empty($r['ok'])
            ? ['ok' => false, 'message' => 'ارسال آزمایشی ناموفق بود — ' . (string)($r['description'] ?? '')]
            : ['ok' => true, 'message' => '✅ پیام آزمایشی ارسال شد؛ کانال را ببینید.'];
    }

    /** فراخوانی خودکار از Updater پس از نصب موفق به‌روزرسانی */
    public static function onUpdate(string $from, string $to, int $files = 0, array $log = []): bool
    {
        if (!self::ready() || !self::autoOn()) return false;

        /* جلوگیری از انتشار تکراری برای همان نسخه */
        $lastV = (string)DB::setting(self::S_LASTV, '');
        if ($to !== '' && $lastV === $to && $from === $to) return false;

        $changes = self::pending();
        if (!$changes && $log) {
            $changes = self::parse(array_slice($log, 0, 20));
        }

        $r = self::publish([
            'from' => $from, 'to' => $to, 'files' => $files,
            'changes' => $changes, 'auto' => true,
        ]);
        return !empty($r['ok']);
    }

    /* ==================== تاریخچه ==================== */

    /* ==================== fixed79: اعلام خودکار بسته‌های fixedNN از CHANGELOG.md ==================== */
    public const S_LASTB = 'rel_last_build';

    /** بالاترین بخش CHANGELOG.md کنار پروژه: ['id' => 'fixed79', 'title' => '...', 'lines' => [...]] یا null */
    public static function localBuild(): ?array
    {
        $f = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/CHANGELOG.md';
        if (!is_file($f)) return null;
        $raw = (string)@file_get_contents($f);
        if ($raw === '') return null;
        if (!preg_match('~^##\s+(fixed\d+)\s*[—\-–:]*\s*([^\n]*)\n(.*?)(?=^##\s|\z)~msu', $raw, $m)) return null;
        $lines = [];
        foreach (preg_split('~\r\n|\r|\n~', (string)$m[3]) ?: [] as $ln) {
            $t = trim((string)$ln);
            if ($t === '' || strpos($t, '#') === 0) continue;
            $lines[] = $t;
        }
        return ['id' => (string)$m[1], 'title' => trim((string)$m[2]), 'lines' => $lines];
    }

    /** آیا بستهٔ فعلی هنوز در تاپیک اعلام نشده است؟ */
    public static function buildPending(): bool
    {
        $b = self::localBuild();
        return $b !== null && (string)DB::setting(self::S_LASTB, '') !== $b['id'];
    }

    /**
     * اعلام بستهٔ فعلی در کانال/تاپیک انتشار — برای هر fixedNN فقط یک‌بار.
     * از کرانجاب صدا زده می‌شود؛ $force = انتشار مجدد دستی از پنل وب.
     */
    public static function announceBuild(bool $force = false): array
    {
        $b = self::localBuild();
        if ($b === null) return ['ok' => false, 'skipped' => true, 'message' => 'فایل CHANGELOG.md پیدا نشد.'];
        $last = (string)DB::setting(self::S_LASTB, '');
        if (!$force && $last === $b['id']) {
            return ['ok' => false, 'skipped' => true, 'message' => 'بستهٔ ' . $b['id'] . ' قبلاً اعلام شده است.'];
        }
        if (!self::ready()) return ['ok' => false, 'skipped' => true, 'message' => 'کانال انتشار فعال نیست (تنظیمات ← کانال انتشار آپدیت).'];
        if (!$force && !self::autoOn()) return ['ok' => false, 'skipped' => true, 'message' => 'انتشار خودکار خاموش است.'];

        $changes = self::parse($b['lines']);
        if (!$changes && $b['title'] !== '') $changes = self::parse([$b['title']]);
        $r = self::publish([
            'from'    => $last !== '' ? self::version() . ' ' . $last : '',
            'to'      => self::version() . ' ' . $b['id'],
            'changes' => $changes,
            'auto'    => !$force,
        ]);
        if (!empty($r['ok'])) {
            DB::setSetting(self::S_LASTB, $b['id']);
            return ['ok' => true, 'message' => 'بستهٔ ' . $b['id'] . ' در تاپیک اعلام شد (' . fa_num((string)self::countItems($changes)) . ' مورد).'];
        }
        return ['ok' => false, 'message' => (string)($r['message'] ?? 'انتشار ناموفق بود.')];
    }

    public static function history(): array
    {
        $h = jdec((string)DB::setting(self::S_HIST, '[]'), []);
        return is_array($h) ? $h : [];
    }

    public static function addHistory(array $row): void
    {
        $rows = self::history();
        array_unshift($rows, $row);
        DB::setSetting(self::S_HIST, jenc(array_slice($rows, 0, 30)));
    }

    public static function clearHistory(): void
    {
        DB::setSetting(self::S_HIST, '[]');
    }
}
