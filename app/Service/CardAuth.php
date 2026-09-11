<?php

declare(strict_types=1);

/**
 * CardAuth — احراز مالکیت کارت بانکی برای پرداخت کارت به کارت
 *
 * منطق کار: کاربر پیش از واریز، شماره کارتی که قرار است با آن پول بفرستد
 * را ثبت می‌کند؛ پس از تایید مدیر (یا تایید خودکار) می‌تواند واریز کند.
 *
 * ⚠️ امنیت — این سرویس هرگز اطلاعات حساس را نمی‌گیرد و ذخیره نمی‌کند:
 *   ✖ CVV2 / cvv ✖ رمز دوم و پویا ✖ تاریخ انقضا ✖ رمز عابربانکی ✖ کد ملی
 * فقط این موارد نگهداری می‌شود: شماره‌کارت ۱۶ رقمی، نام صاحب کارت،
 * شبا (اختیاری) و نام بانک که از روی شش رقم اول تشخیص داده می‌شود.
 */
class CardAuth
{
    /** وضعیت‌های مجاز */
    public const ST = ['pending', 'approved', 'rejected'];

    /** عنوان فارسی وضعیت‌ها */
    public const ST_LABEL = [
        'pending'  => '⏳ در انتظار تایید',
        'approved' => '✅ تایید شده',
        'rejected' => '⛔️ رد شده',
    ];

    /**
     * جدول BIN بانک‌های ایران (شش رقم اول کارت)
     */
    public const BINS = [
        '603799' => 'ملی ایران',
        '589210' => 'سپه',
        '627648' => 'توسعه صادرات',
        '207177' => 'توسعه صادرات',
        '627961' => 'صنعت و معدن',
        '603770' => 'کشاورزی',
        '639217' => 'کشاورزی',
        '628023' => 'مسکن',
        '627760' => 'پست بانک',
        '502908' => 'توسعه تعاون',
        '627412' => 'اقتصاد نوین',
        '622106' => 'پارسیان',
        '639194' => 'پارسیان',
        '627884' => 'پارسیان',
        '502229' => 'پاسارگاد',
        '639347' => 'پاسارگاد',
        '621986' => 'سامان',
        '639346' => 'سینا',
        '639607' => 'سرمایه',
        '636214' => 'آینده',
        '502806' => 'شهر',
        '504706' => 'شهر',
        '502938' => 'دی',
        '603769' => 'صادرات',
        '610433' => 'ملت',
        '991975' => 'ملت',
        '589463' => 'رفاه کارگران',
        '627353' => 'تجارت',
        '585983' => 'تجارت',
        '627381' => 'انصار',
        '639370' => 'مهر اقتصاد',
        '606373' => 'قرض‌الحسنه مهر ایران',
        '628157' => 'توسعه اعتباری',
        '505801' => 'کوثر',
        '606256' => 'ملل',
        '507677' => 'نور',
        '636949' => 'حکمت ایرانیان',
        '505416' => 'گردشگری',
        '505426' => 'گردشگری',
        '636795' => 'مرکزی',
        '505785' => 'ایران زمین',
        '581874' => 'ایران زمین',
        '504172' => 'رسالت',
        '606737' => 'قرض‌الحسنه رسالت',
        '628961' => 'انصار',
        '507412' => 'رسالت',
        '585949' => 'خاورمیانه',
        '627488' => 'کارافرین',
        '502910' => 'کارافرین',
        '639599' => 'قوامین',
    ];

    /* ==================== تنظیمات ==================== */

    /** قابلیت احراز کارت روشن است؟ */
    public static function enabled(): bool
    {
        return (string)DB::setting('cardauth_enabled', '1') === '1';
    }

    /** احراز اجباری است؟ (بدون کارت تاییدشده اجازه‌ی واریز نیست) */
    public static function required(): bool
    {
        return self::enabled() && (string)DB::setting('cardauth_required', '1') === '1';
    }

    /** تایید خودکار پس از ثبت؟ */
    public static function autoApprove(): bool
    {
        return (string)DB::setting('cardauth_auto', '0') === '1';
    }

    /** سقف تعداد کارت فعال برای هر کاربر (۱ تا ۲۰) */
    public static function maxCards(): int
    {
        $n = (int)DB::setting('cardauth_max', 3);
        if ($n < 1) $n = 3;
        return min($n, 20);
    }

    /** نام صاحب کارت اجباری است؟ */
    public static function needHolder(): bool
    {
        return (string)DB::setting('cardauth_holder', '1') === '1';
    }

    /** شماره‌ی شبا اجباری است؟ */
    public static function needSheba(): bool
    {
        return (string)DB::setting('cardauth_sheba', '0') === '1';
    }

    /** تایید کنترل لون (صحت ریاضی شماره‌کارت) */
    public static function checkLuhn(): bool
    {
        return (string)DB::setting('cardauth_luhn', '1') === '1';
    }

    /**
     * فقط یک کارت فعال برای هر کاربر؟
     * سقف تعداد کارت (maxCards) همیشه حرف آخر را می‌زند؛
     * اگر مدیر سقف را بیشتر از ۱ گذاشته باشد، این قفل هرگز فعال نمی‌شود.
     */
    public static function singleCard(): bool
    {
        if (self::maxCards() > 1) return false;
        return (string)DB::setting('cardauth_single', '0') === '1';
    }

    /** تعداد کارت‌های فعال کاربر (تاییدشده + در انتظار)؛ کارت ردشده ظرفیت اشغال نمی‌کند */
    public static function activeCount(int $userId): int
    {
        $n = 0;
        foreach (self::cards($userId) as $c) {
            $s = (string)$c['status'];
            if ($s === 'approved' || $s === 'pending') $n++;
        }
        return $n;
    }

    /** آیا کاربر هنوز ظرفیت ثبت کارت تازه دارد؟ */
    public static function canAdd(int $userId): bool
    {
        if (!self::enabled()) return false;
        return self::activeCount($userId) < self::maxCards();
    }

    /** ظرفیت باقی‌ماندهٔ کاربر */
    public static function remaining(int $userId): int
    {
        $n = self::maxCards() - self::activeCount($userId);
        return $n > 0 ? $n : 0;
    }

    /** امکان بارگذاری تصویر کارت روشن است؟ */
    public static function wantPhoto(): bool
    {
        return (string)DB::setting('cardauth_photo', '1') === '1';
    }

    /** بارگذاری تصویر کارت اجباری است؟ */
    public static function photoRequired(): bool
    {
        return self::wantPhoto() && (string)DB::setting('cardauth_photo_req', '0') === '1';
    }

    /** پوشهٔ امن تصاویر کارت */
    public static function photoDir(): string
    {
        $d = APP_ROOT . '/storage/cards';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        $ht = $d . '/.htaccess';
        if (!is_file($ht)) @file_put_contents($ht, "Deny from all\n");
        return $d;
    }

    /** ذخیرهٔ تصویر کارت از دیتای بیسٔ۶۴ — نام فایل را برمی‌گرداند */
    public static function savePhoto(int $uid, string $data): string
    {
        $data = trim($data);
        if ($data === '') return '';
        if (!preg_match('~^data:image/(png|jpe?g|webp);base64,~i', $data, $m)) return '';
        $ext = strtolower($m[1]);
        if ($ext === 'jpeg') $ext = 'jpg';
        $raw = base64_decode(substr($data, strlen($m[0])), true);
        if ($raw === false) return '';
        $len = strlen($raw);
        if ($len < 256 || $len > 4194304) return '';
        try { $name = 'c' . $uid . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext; }
        catch (Throwable $e) { $name = 'c' . $uid . '-' . date('Ymd-His') . '-' . mt_rand(1000, 9999) . '.' . $ext; }
        $p = self::photoDir() . '/' . $name;
        if (@file_put_contents($p, $raw) === false) return '';
        @chmod($p, 0644);
        return $name;
    }

    /** مسیر کامل تصویر یک کارت (یا خالی) */
    public static function photoPath(string $name): string
    {
        $name = basename(trim($name));
        if ($name === '' || !preg_match('~^[A-Za-z0-9._-]{4,120}$~', $name)) return '';
        $p = self::photoDir() . '/' . $name;
        return is_file($p) ? $p : '';
    }

    public static function guide(): string
    {
        return trim((string)DB::setting(
            'cardauth_note',
            'شماره‌ی کارتی که قرار است با آن واریز کنید را ثبت کنید. واریز از کارت دیگران پذیرفته نیست.'
        ));
    }

    /* ==================== ابزارها ==================== */

    /** فقط ارقام لاتین */
    public static function digits(string $s): string
    {
        $s = function_exists('en_num') ? en_num($s) : $s;
        return (string)preg_replace('/\D+/', '', $s);
    }

    /** نرمال‌سازی شماره‌کارت */
    public static function normalizePan(string $s): string
    {
        return substr(self::digits($s), 0, 19);
    }

    /** نرمال‌سازی شبا (با یا بدون IR) */
    public static function normalizeSheba(string $s): string
    {
        $s = strtoupper(trim(function_exists('en_num') ? en_num($s) : $s));
        $s = (string)preg_replace('/[^0-9A-Z]/', '', $s);
        if (strpos($s, 'IR') === 0) $s = substr($s, 2);
        $s = (string)preg_replace('/\D+/', '', $s);
        return $s === '' ? '' : 'IR' . substr($s, 0, 24);
    }

    /** اعتبارسنجی شبا (طول و باقیمانده‌ی ۹۷) */
    public static function shebaValid(string $sheba): bool
    {
        $s = self::normalizeSheba($sheba);
        if (!preg_match('/^IR\d{24}$/', $s)) return false;
        $re  = substr($s, 4) . '1827' . substr($s, 2, 2);
        $mod = 0;
        for ($i = 0; $i < strlen($re); $i++) {
            $mod = ($mod * 10 + (int)$re[$i]) % 97;
        }
        return $mod === 1;
    }

    /** الگوریتم لون */
    public static function luhn(string $pan): bool
    {
        $pan = self::digits($pan);
        $n   = strlen($pan);
        if ($n < 12) return false;
        $sum = 0;
        for ($i = 0; $i < $n; $i++) {
            $d = (int)$pan[$n - 1 - $i];
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) $d -= 9;
            }
            $sum += $d;
        }
        return $sum % 10 === 0;
    }

    /** ماسک نمایشی: 6037-99**-****-1234 */
    public static function mask(string $pan): string
    {
        $p = self::digits($pan);
        if (strlen($p) < 12) return $p;
        return substr($p, 0, 6) . str_repeat('*', max(0, strlen($p) - 10)) . substr($p, -4);
    }

    /** نمایش چهارتایی کامل (برای مدیر) */
    public static function pretty(string $pan): string
    {
        $p = self::digits($pan);
        return trim(chunk_split($p, 4, '-'), '-');
    }

    /** تشخیص بانک از روی BIN */
    public static function bankOf(string $pan): string
    {
        $p = self::digits($pan);
        return self::BINS[substr($p, 0, 6)] ?? '';
    }

    /** عنوان وضعیت */
    public static function label(string $status): string
    {
        return self::ST_LABEL[$status] ?? $status;
    }

    /* ==================== خواندن ==================== */

    /** تمام کارت‌های یک کاربر */
    public static function cards(int $userId): array
    {
        if ($userId <= 0) return [];
        try {
            return DB::all(
                'SELECT * FROM {p}user_cards WHERE user_id = :u ORDER BY (status = \'approved\') DESC, id DESC',
                [':u' => $userId]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /** کارت‌های تاییدشده */
    public static function approved(int $userId): array
    {
        if ($userId <= 0) return [];
        try {
            return DB::all(
                'SELECT * FROM {p}user_cards WHERE user_id = :u AND status = \'approved\' ORDER BY id DESC',
                [':u' => $userId]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /** دست‌کم یک کارت تاییدشده دارد؟ */
    public static function hasApproved(int $userId): bool
    {
        if ($userId <= 0) return false;
        try {
            return (int)DB::val(
                'SELECT COUNT(*) FROM {p}user_cards WHERE user_id = :u AND status = \'approved\'',
                [':u' => $userId],
                0
            ) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** کارت در انتظار دارد؟ */
    public static function hasPending(int $userId): bool
    {
        if ($userId <= 0) return false;
        try {
            return (int)DB::val(
                'SELECT COUNT(*) FROM {p}user_cards WHERE user_id = :u AND status = \'pending\'',
                [':u' => $userId],
                0
            ) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** یک کارت */
    public static function find(int $id): ?array
    {
        if ($id <= 0) return null;
        try {
            return DB::one('SELECT * FROM {p}user_cards WHERE id = :i', [':i' => $id]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** جستجوی کارت براساس شماره */
    public static function byPan(string $pan, int $userId = 0): ?array
    {
        $p = self::digits($pan);
        if ($p === '') return null;
        try {
            if ($userId > 0) {
                return DB::one('SELECT * FROM {p}user_cards WHERE pan = :p AND user_id = :u', [':p' => $p, ':u' => $userId]);
            }
            return DB::one('SELECT * FROM {p}user_cards WHERE pan = :p ORDER BY id DESC', [':p' => $p]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** کارت‌های در انتظار تایید */
    public static function pending(int $limit = 50): array
    {
        try {
            return DB::all(
                'SELECT c.*, u.username, u.first_name, u.tg_id AS utg
                   FROM {p}user_cards c LEFT JOIN {p}users u ON u.id = c.user_id
                  WHERE c.status = \'pending\' ORDER BY c.id ASC LIMIT ' . max(1, min(200, $limit))
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /** شمارش در انتظار */
    public static function pendingCount(): int
    {
        try {
            return (int)DB::val('SELECT COUNT(*) FROM {p}user_cards WHERE status = \'pending\'', [], 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * لیست مدیریتی با فیلتر
     *
     * @param array{status?:string,q?:string,limit?:int,offset?:int} $f
     */
    public static function search(array $f = []): array
    {
        $w  = [];
        $pr = [];
        $st = (string)($f['status'] ?? '');
        if (in_array($st, self::ST, true)) { $w[] = 'c.status = :st'; $pr[':st'] = $st; }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $w[]      = '(c.pan LIKE :q OR c.pan_mask LIKE :q OR c.holder LIKE :q OR c.sheba LIKE :q'
                . ' OR u.username LIKE :q OR CAST(c.tg_id AS CHAR) LIKE :q)';
            $pr[':q'] = '%' . $q . '%';
        }
        $sql = 'SELECT c.*, u.username, u.first_name, u.balance
                  FROM {p}user_cards c LEFT JOIN {p}users u ON u.id = c.user_id';
        if ($w) $sql .= ' WHERE ' . implode(' AND ', $w);
        $sql .= ' ORDER BY (c.status = \'pending\') DESC, c.id DESC LIMIT '
            . max(1, min(500, (int)($f['limit'] ?? 200)));
        try {
            return DB::all($sql, $pr);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** آمار کلی */
    public static function stats(): array
    {
        $out = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'users' => 0, 'today' => 0];
        try {
            $out['total']    = (int)DB::val('SELECT COUNT(*) FROM {p}user_cards', [], 0);
            $out['pending']  = (int)DB::val('SELECT COUNT(*) FROM {p}user_cards WHERE status = \'pending\'', [], 0);
            $out['approved'] = (int)DB::val('SELECT COUNT(*) FROM {p}user_cards WHERE status = \'approved\'', [], 0);
            $out['rejected'] = (int)DB::val('SELECT COUNT(*) FROM {p}user_cards WHERE status = \'rejected\'', [], 0);
            $out['users']    = (int)DB::val('SELECT COUNT(DISTINCT user_id) FROM {p}user_cards', [], 0);
            $out['today']    = (int)DB::val('SELECT COUNT(*) FROM {p}user_cards WHERE DATE(created_at) = CURDATE()', [], 0);
        } catch (Throwable $e) {
        }
        return $out;
    }

    /* ==================== نو��تن ==================== */

    /**
     * ثبت کارت توسط کاربر
     *
     * @return array{ok:bool,msg:string,id?:int,status?:string}
     */
    public static function add(array $user, string $pan, string $holder = '', string $sheba = '', string $photo = ''): array
    {
        $uid = (int)($user['id'] ?? 0);
        if ($uid <= 0) return ['ok' => false, 'msg' => 'کاربر نامشخص است.'];
        if (!self::enabled()) return ['ok' => false, 'msg' => 'احراز کارت غیرفعال است.'];

        $p = self::normalizePan($pan);
        if (strlen($p) !== 16) {
            return ['ok' => false, 'msg' => '❌ شماره‌ی کارت باید دقیقاً ۱۶ رقم باشد.'];
        }
        if (self::checkLuhn() && !self::luhn($p)) {
            return ['ok' => false, 'msg' => '❌ شماره‌ی کارت معتبر نیست؛ دوباره بررسی کنید.'];
        }

        $holder = trim(preg_replace('/\s+/u', ' ', $holder) ?? '');
        if (mb_strlen($holder) > 80) $holder = mb_substr($holder, 0, 80);
        if (self::needHolder() && mb_strlen($holder) < 5) {
            return ['ok' => false, 'msg' => '❌ نام و نام خانوادگی صاحب کارت را کامل بنویسید.'];
        }

        $sh = $sheba !== '' ? self::normalizeSheba($sheba) : '';
        if (self::needSheba() && $sh === '') {
            return ['ok' => false, 'msg' => '❌ شماره‌ی شبا الزامی است.'];
        }
        if ($sh !== '' && !self::shebaValid($sh)) {
            return ['ok' => false, 'msg' => '❌ شماره‌ی شبا معتبر نیست.'];
        }

        /* ظرفیت — پیش از ذخیرهٔ تصویر بررسی می‌شود تا فایل یتیم روی دیسک نماند */
        if (!self::byPan($p, $uid) && self::activeCount($uid) >= self::maxCards()) {
            return ['ok' => false, 'msg' => '⛔️ سقف ثبت کارت (' . self::maxCards() . ' عدد) پر شده است؛ یکی از کارت‌های قبلی را حذف کنید.'];
        }

        /* تکراری بودن */
        $ph = '';
        if ($photo !== '' && self::wantPhoto()) {
            $ph = self::savePhoto($uid, $photo);
            if ($ph === '') {
                return ['ok' => false, 'msg' => '❌ تصویر کارت معتبر نیست (فقط JPG یا PNG تا ۴ مگابایت).'];
            }
        }
        if (self::photoRequired() && $ph === '') {
            return ['ok' => false, 'msg' => '❌ بارگذاری تصویر کارت الزامی است.'];
        }

        /* قفل تک‌کارت — فقط وقتی مدیر سقف را روی ۱ گذاشته باشد */
        if (self::singleCard()) {
            foreach (self::cards($uid) as $cc) {
                if (self::normalizePan((string)$cc['pan']) === $p) continue;
                $sx = (string)$cc['status'];
                if ($sx === 'approved' || $sx === 'pending') {
                    return ['ok' => false, 'msg' => '⛔️ سقف ثبت کارت شما ۱ عدد است؛ برای ثبت کارت جدید ابتدا کارت قبلی را حذف کنید.'];
                }
            }
        }

        $mine = self::byPan($p, $uid);
        if ($mine) {
            $st = (string)$mine['status'];
            if ($st === 'approved') return ['ok' => false, 'msg' => 'ℹ️ این کارت قبلاً تایید شده است.'];
            if ($st === 'pending')  return ['ok' => false, 'msg' => '⏳ این کارت در انتظار تایید است.'];
            /* ردشده بوده ← باز هم می‌تواند دوباره درخواست دهد */
            try {
                DB::update('user_cards', [
                    'status'     => self::autoApprove() ? 'approved' : 'pending',
                    'holder'     => $holder,
                    'sheba'      => $sh,
                    'bank'       => self::bankOf($p),
                    'note'       => '',
                    'photo'      => $ph !== '' ? $ph : (string)($mine['photo'] ?? ''),
                    'admin_id'   => null,
                    'created_at' => now(),
                    'decided_at' => self::autoApprove() ? now() : null,
                ], 'id = :i', [':i' => (int)$mine['id']]);
            } catch (Throwable $e) {
                return ['ok' => false, 'msg' => '⛔️ خطا در ثبت کارت.'];
            }
            self::notifyAdmins((int)$mine['id']);
            return [
                'ok'     => true,
                'id'     => (int)$mine['id'],
                'status' => self::autoApprove() ? 'approved' : 'pending',
                'msg'    => self::autoApprove()
                    ? '✅ کارت شما ثبت و تایید شد.'
                    : '⏳ درخواست ثبت کارت ارسال شد و در انتظار تایید مدیر است.',
            ];
        }

        /* کارت متعلق به کاربر دیگری تایید شده باشد */
        $other = self::byPan($p, 0);
        if ($other && (int)$other['user_id'] !== $uid && (string)$other['status'] === 'approved') {
            return ['ok' => false, 'msg' => '⛔️ این کارت به نام کاربر دیگری ثبت شده است.'];
        }

        if (self::activeCount($uid) >= self::maxCards()) {
            return ['ok' => false, 'msg' => '⛔️ سقف ثبت کارت (' . self::maxCards() . ' عدد) پر شده است؛ یکی از کارت‌های قبلی را حذف کنید.'];
        }

        $auto = self::autoApprove();
        try {
            $id = DB::insert('user_cards', [
                'user_id'    => $uid,
                'tg_id'      => (int)($user['tg_id'] ?? 0),
                'pan'        => $p,
                'pan_mask'   => self::mask($p),
                'holder'     => $holder,
                'bank'       => self::bankOf($p),
                'sheba'      => $sh,
                'status'     => $auto ? 'approved' : 'pending',
                'note'       => '',
                'uses'       => 0,
                'photo'      => $ph,
                'created_at' => now(),
                'decided_at' => $auto ? now() : null,
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => '⛔️ خطا در ذخیره‌ی کارت.'];
        }

        self::log('🆕 ثبت کارت جدید', [
            'کاربر' => (string)($user['tg_id'] ?? '-'),
            'کارت'  => self::mask($p),
            'وضعیت' => $auto ? 'approved' : 'pending',
        ]);
        if (!$auto) self::notifyAdmins((int)$id);

        return [
            'ok'     => true,
            'id'     => (int)$id,
            'status' => $auto ? 'approved' : 'pending',
            'msg'    => $auto
                ? '✅ کارت شما ثبت و تایید شد؛ اکنون می‌توانید واریز کنید.'
                : '⏳ درخواست ثبت کارت ارسال شد و در انتظار تایید مدیر است.',
        ];
    }

    /** حذف کارت (اگر userId بدهید، فقط کارت خودش حذف می‌شود) */
    public static function remove(int $id, int $userId = 0): bool
    {
        if ($id <= 0) return false;
        try {
            if ($userId > 0) {
                return DB::delete('user_cards', 'id = :i AND user_id = :u', [':i' => $id, ':u' => $userId]) > 0;
            }
            return DB::delete('user_cards', 'id = :i', [':i' => $id]) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** تایید / رد توسط مدیر */
    public static function decide(int $id, string $status, int $adminId = 0, string $note = ''): bool
    {
        if (!in_array($status, ['approved', 'rejected', 'pending'], true)) return false;
        $card = self::find($id);
        if (!$card) return false;

        try {
            DB::update('user_cards', [
                'status'     => $status,
                'note'       => mb_substr($note, 0, 250),
                'admin_id'   => $adminId > 0 ? $adminId : null,
                'decided_at' => now(),
            ], 'id = :i', [':i' => $id]);
        } catch (Throwable $e) {
            return false;
        }

        self::log($status === 'approved' ? '✅ تایید کارت' : '⛔️ رد کارت', [
            'کارت'  => (string)$card['pan_mask'],
            'کاربر' => (string)$card['tg_id'],
            'توضیح' => $note !== '' ? $note : '-',
        ]);

        /* اطلاع به کاربر */
        $tg = (int)$card['tg_id'];
        if ($tg > 0 && class_exists('Tg')) {
            $msg = $status === 'approved'
                ? "✅ <b>کارت شما تایید شد</b>\n\n💳 <code>" . $card['pan_mask'] . "</code>\nاکنون می‌توانید از این کارت برای واریز استفاده کنید."
                : "⛔️ <b>درخواست کارت شما رد شد</b>\n\n💳 <code>" . $card['pan_mask'] . '</code>'
                    . ($note !== '' ? "\n📝 دلیل: " . h($note) : '');
            try { Tg::send($tg, $msg); } catch (Throwable $e) { }
        }

        return true;
    }

    public static function approve(int $id, int $adminId = 0): bool
    {
        return self::decide($id, 'approved', $adminId);
    }

    public static function reject(int $id, int $adminId = 0, string $note = ''): bool
    {
        return self::decide($id, 'rejected', $adminId, $note);
    }

    /** ثبت استفاده از کارت در یک واریز */
    public static function touch(int $id): void
    {
        if ($id <= 0) return;
        try {
            DB::q('UPDATE {p}user_cards SET uses = uses + 1, last_used_at = :t WHERE id = :i', [
                ':t' => now(), ':i' => $id,
            ]);
        } catch (Throwable $e) {
        }
    }

    /* ==================== دروازه‌ی واریز ==================== */

    /**
     * بررسی اینکه کاربر مجاز به واریز کارت به کارت هست یا نه
     *
     * @return array{ok:bool,reason:string,msg:string,cards:array}
     */
    public static function gate(array $user): array
    {
        $uid   = (int)($user['id'] ?? 0);
        $cards = self::approved($uid);

        if (!self::required()) {
            return ['ok' => true, 'reason' => 'off', 'msg' => '', 'cards' => $cards];
        }
        if ($cards !== []) {
            return ['ok' => true, 'reason' => 'ok', 'msg' => '', 'cards' => $cards];
        }
        if (self::hasPending($uid)) {
            return [
                'ok'     => false,
                'reason' => 'pending',
                'msg'    => "⏳ <b>کارت شما در انتظار تایید مدیر است.</b>\n"
                    . 'پس از تایید، امکان واریز کارت به کارت فعال می‌شود.',
                'cards'  => [],
            ];
        }
        return [
            'ok'     => false,
            'reason' => 'none',
            'msg'    => "🔐 <b>احراز کارت بانکی لازم است</b>\n\n"
                . 'برای واریز کارت به کارت، اول باید کارتی که با آن واریز می‌کنید را ثبت و تایید کنید.'
                . "\n\n" . self::guide()
                . "\n\n✅ فقط شماره‌کارت و نام صاحب کارت لازم است."
                . "\n⛔️ هرگز CVV2، رمز دوم، رمز پویا یا تاریخ انقضا را برای هیچ‌کس ارسال نکنید.",
            'cards'  => [],
        ];
    }

    /* ==================== داخلی ==================== */

    private static function log(string $title, array $rows): void
    {
        if (!class_exists('Logs')) return;
        try {
            Logs::send('pay', Logs::fmt($title, $rows));
        } catch (Throwable $e) {
        }
    }

    private static function notifyAdmins(int $cardId): void
    {
        if (!class_exists('AdminBot') || !method_exists('AdminBot', 'notifyCardRequest')) return;
        try {
            AdminBot::notifyCardRequest($cardId);
        } catch (Throwable $e) {
        }
    }
}
