<?php
/**
 * Migrate – تکمیل هوشمند ساختار دیتابیس
 *
 * فایل‌های مایگریشن با «ALTER TABLE ... ADD COLUMN» نوشته شده‌اند و اگر دو بار
 * اجرا شوند خطا می‌دهند. این کلاس جای آن‌ها را می‌گیرد: اول با information_schema
 * می‌بیند چه چیزی «نیست» و فقط همان را می‌سازد. پس اجرای چندباره کاملا بی‌خطر است.
 */
class Migrate
{
    /** ستون‌های لازم: جدول => [نام ستون => تعریف] */
    public const COLUMNS = [
        'discount_codes' => [
            'max_discount' => 'BIGINT NOT NULL DEFAULT 0',
            'starts_at'    => 'DATETIME NULL',
            'note'         => 'VARCHAR(190) NULL',
        ],
        'gift_codes' => [
            'note' => 'VARCHAR(190) NULL',
        ],
        'users' => [
            'reseller_domain'   => "VARCHAR(120) NULL",
            'reseller_brand'    => "VARCHAR(80) NULL",
            'reseller_note_pub' => "VARCHAR(255) NULL",
            'email_verified'    => 'TINYINT(1) NOT NULL DEFAULT 0',
            'phone_verified'    => 'TINYINT(1) NOT NULL DEFAULT 0',
            'verify_kind'       => 'VARCHAR(12) NULL',
            'verify_target'     => 'VARCHAR(190) NULL',
            'verify_code'       => 'VARCHAR(12) NULL',
            'verify_token'      => 'VARCHAR(64) NULL',
            'verify_at'         => 'DATETIME NULL',
            'verify_tries'      => 'INT NOT NULL DEFAULT 0',
            'verified_at'       => 'DATETIME NULL',
            'miniapp_at'        => 'DATETIME NULL',
            'test_ip'           => 'VARCHAR(64) NULL',   /* 0.0.2 #19 */
            'test_dev'          => 'VARCHAR(64) NULL',   /* 0.0.2 #19 */
            'test_at'           => 'DATETIME NULL',      /* 0.0.2 #19 */
            'reseller_level'    => 'TINYINT(1) NOT NULL DEFAULT 0',
            'reseller_credit'   => 'BIGINT NOT NULL DEFAULT 0',
            'reseller_discount' => 'TINYINT(2) NOT NULL DEFAULT 0',
            'reseller_at'       => 'DATETIME NULL',
            'reseller_req'      => 'TINYINT(1) NOT NULL DEFAULT 0',
            'reseller_req_at'   => 'DATETIME NULL',
            'reseller_req_note' => 'VARCHAR(400) NULL',
            'reseller_price_gb'  => 'INT NOT NULL DEFAULT 0',
            'reseller_price_day' => 'INT NOT NULL DEFAULT 0',
            'reseller_max_gb'    => 'INT NOT NULL DEFAULT 0',
            'reseller_max_days'  => 'INT NOT NULL DEFAULT 0',
            'reseller_panels'    => 'VARCHAR(190) NULL',
            'reseller_note'      => 'VARCHAR(400) NULL',
            'reseller_mark'      => 'VARCHAR(24) NULL',
            'auto_renew'         => 'TINYINT(1) NOT NULL DEFAULT 0',
        ],
        'admins' => [
            'perms'    => 'TEXT NULL',
            'tg_id'    => 'BIGINT NULL',
            'active'   => 'TINYINT(1) NOT NULL DEFAULT 1',
            'note'     => 'VARCHAR(255) NULL',
            'last_ip'  => 'VARCHAR(64) NULL',
        ],
        'panels' => [
            'test_volume_mb' => 'INT NOT NULL DEFAULT 0', /* fixed79: حجم تست به مگابایت */
            'test_inbound_ids'    => 'VARCHAR(190) NULL',
            'test_single_inbound' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ssl_verify'          => 'TINYINT(1) NOT NULL DEFAULT 0',
            /* توکن API نسل جدید 3x-ui — مانند password رمزنگاری‌شده ذخیره می‌شود */
            'api_token'           => 'VARCHAR(500) NULL',
        ],
        'products' => [
            'group_ids' => 'VARCHAR(190) NULL', /* fixed79: گروه‌های اختصاصی پاسارگارد */
            /* نحوهٔ تحویل: '' = پیروی از پیش‌فرض کلی · sub · config · both */
            'deliver_mode' => "VARCHAR(8) NOT NULL DEFAULT ''",
            'device_limit' => 'INT NOT NULL DEFAULT 0',
            /* 0.0.2: ریست خودکار دوره‌ای حجم (روز) — ۰ = خاموش */
            'reset_days'   => 'INT NOT NULL DEFAULT 0',
            'speed_up'     => 'INT NOT NULL DEFAULT 0',
            'speed_down'   => 'INT NOT NULL DEFAULT 0',
            /* fixed76: نوع محصول — fixed (حجم/مدت ثابت) یا custom (حجم و زمان دلخواه با قیمت هر گیگ/هر روز) */
            'type'         => "VARCHAR(8) NOT NULL DEFAULT 'fixed'",
            'price_gb'     => 'BIGINT NOT NULL DEFAULT 0',
            'price_day'    => 'BIGINT NOT NULL DEFAULT 0',
            'min_gb'       => 'INT NOT NULL DEFAULT 1',
            'max_gb'       => 'INT NOT NULL DEFAULT 100',
            'min_days'     => 'INT NOT NULL DEFAULT 1',
            'max_days'     => 'INT NOT NULL DEFAULT 90',
        ],
        'services' => [
            'is_reseller' => 'TINYINT(1) NOT NULL DEFAULT 0',
            /* لحظهٔ تمام‌شدن (زمان یا حجم) — مبنای حذف خودکار پس از N روز */
            'expired_at'  => 'DATETIME NULL',
        ],
        'stock_cats' => [
            /* قیمت ویژهٔ نماینده — ۰ یعنی همان قیمت عمومی */
            'rs_price' => 'BIGINT NOT NULL DEFAULT 0',
        ],
        'tickets' => [
            /* 0.0.2 #21: پشتیبانی حرفه‌ای — اولویت، دسته‌بندی و مدیر مسئول */
            'priority'  => "VARCHAR(8) NOT NULL DEFAULT 'normal'",
            'category'  => 'VARCHAR(32) NULL',
            'admin_id'  => 'INT NULL',
            'closed_at' => 'DATETIME NULL',
        ],
        'ticket_messages' => [
            'file_type' => 'VARCHAR(16) NULL',
        ],
        'transactions' => [
            /* کارت احرازشده‌ای که واریز با آن انجام شده است */
            'card_id' => 'INT UNSIGNED NULL',
            /* fixed84: شناسهٔ پیام کارت رسید در ربات مدیر (برای مهر تایید/رد) */
            'admin_msg' => 'TEXT NULL',
        ],
    ];

    /**
     * ستون‌هایی که نوعشان اشتباه است و باید اصلاح شود
     * قالب: جدول => [ستون => [تعریف درست، پیشوندِ نوع‌های غلط قدیمی]]
     */
    public const MODIFY = [
        'services' => [
            // notified یک بیت‌مسک است (& 1 ، & 2 ، & 4) و باید عددی باشد نه رشته
            'notified' => ['INT NOT NULL DEFAULT 0', ['varchar', 'char', 'text']],
            /* fixed79: دقت مگابایتی برای اکانت تست (۵۰۰MB = 0.4883GB) */
            'volume_gb' => ['DECIMAL(12,4) NOT NULL DEFAULT 0', ['decimal(10,2)']],
        ],
        'panels' => [
            // جای کافی برای رمز رمزنگاری‌شده (AES-256-GCM + base64)
            'password' => ['VARCHAR(255) NOT NULL', ['varchar(190)', 'varchar(128)']],
        ],
        'transactions' => [
            // آیدی عددی تلگرامِ مدیر در ستون INT قدیمی جا نمی‌شد (Out of range)
            // و رسیدِ تاییدشده از بات در پنل وب «در انتظار» می‌ماند
            'admin_id' => ['BIGINT NULL', ['int']],
        ],
    ];

    /** ایندکس‌های لازم: جدول => [نام ایندکس => ستون‌ها] */
    public const INDEXES = [
        /* 0.0.2 #33: ایندکس‌های کارایی — پیش از ساخت، وجود ستون و ایندکس مشابه بررسی می‌شود */
        'transactions' => [
            'idx_txid'       => '(`txid`)',
            'idx_tx_user'    => '(`user_id`)',
            'idx_tx_status'  => '(`status`)',
            'idx_tx_created' => '(`created_at`)',
        ],
        'services' => [
            'idx_sv_user'    => '(`user_id`)',
            'idx_sv_status'  => '(`status`)',
            'idx_sv_expire'  => '(`expire_at`)',
            'idx_sv_panel'   => '(`panel_id`)',
            'idx_sv_test'    => '(`is_test`)',
        ],
        'orders' => [
            'idx_or_user'    => '(`user_id`)',
            'idx_or_status'  => '(`status`)',
            'idx_or_created' => '(`created_at`)',
        ],
        'users' => [
            'idx_us_tg'      => '(`tg_id`)',
            'idx_us_created' => '(`created_at`)',
        ],
        'tickets' => [
            'idx_tk_user'     => '(`user_id`)',
            'idx_tk_updated'  => '(`updated_at`)',
            'idx_tk_priority' => '(`priority`)',
        ],
        'ticket_messages' => [
            'idx_tm_created' => '(`created_at`)',
        ],
        'discount_uses' => [
            'idx_du_user'    => '(`user_id`)',
        ],
        'stock_items' => [
            'idx_si_order'   => '(`order_id`)',
        ],
    ];

    /** جدول‌های لازم: نام => دستور ساخت */
    public const TABLES = [
        /* انبار ملی — دسته‌های فروش دستی */
        'stock_cats' => "CREATE TABLE IF NOT EXISTS {p}stock_cats (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `icon` VARCHAR(16) NULL,
  `kind` VARCHAR(16) NOT NULL DEFAULT 'config',
  `description` TEXT NULL,
  `guide` TEXT NULL,
  `price` BIGINT NOT NULL DEFAULT 0,
  `rs_price` BIGINT NOT NULL DEFAULT 0,
  `old_price` BIGINT NULL,
  `days` INT NOT NULL DEFAULT 0,
  `volume_gb` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `sold` INT NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  KEY `idx_sc_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'stock_items' => "CREATE TABLE IF NOT EXISTS {p}stock_items (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `cat_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `kind` VARCHAR(16) NOT NULL DEFAULT 'config',
  `title` VARCHAR(120) NULL,
  `payload` LONGTEXT NULL,
  `file_id` VARCHAR(255) NULL,
  `file_name` VARCHAR(120) NULL,
  `note` VARCHAR(255) NULL,
  `price` BIGINT NOT NULL DEFAULT 0,
  `status` VARCHAR(16) NOT NULL DEFAULT 'free',
  `user_id` INT UNSIGNED NULL,
  `tg_id` BIGINT NULL,
  `order_id` INT UNSIGNED NULL,
  `admin_id` INT NULL,
  `sold_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  KEY `idx_si_cat` (`cat_id`),
  KEY `idx_si_status` (`status`),
  KEY `idx_si_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        /* احراز کارت بانکی — بدون ذخیره‌ی هیچ داده‌ی حساس (CVV2 / رمز / انقضا) */
        'user_cards' => "CREATE TABLE IF NOT EXISTS {p}user_cards (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `tg_id` BIGINT NULL,
  `pan` VARCHAR(19) NOT NULL,
  `pan_mask` VARCHAR(24) NULL,
  `holder` VARCHAR(80) NULL,
  `bank` VARCHAR(64) NULL,
  `sheba` VARCHAR(30) NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `note` VARCHAR(255) NULL,
  `uses` INT NOT NULL DEFAULT 0,
  `photo` VARCHAR(255) NULL,
  `admin_id` INT NULL,
  `created_at` DATETIME NOT NULL,
  `decided_at` DATETIME NULL,
  `last_used_at` DATETIME NULL,
  KEY `idx_uc_user` (`user_id`),
  KEY `idx_uc_status` (`status`),
  KEY `idx_uc_pan` (`pan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'broadcasts' => "CREATE TABLE IF NOT EXISTS {p}broadcasts (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(190) NULL,
  `kind` VARCHAR(16) NOT NULL DEFAULT 'text',
  `text` TEXT NULL,
  `file_id` VARCHAR(255) NULL,
  `file_type` VARCHAR(16) NULL,
  `buttons` TEXT NULL,
  `segment` VARCHAR(32) NOT NULL DEFAULT 'all',
  `pin` TINYINT(1) NOT NULL DEFAULT 0,
  `silent` TINYINT(1) NOT NULL DEFAULT 0,
  `total` INT NOT NULL DEFAULT 0,
  `sent` INT NOT NULL DEFAULT 0,
  `failed` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(16) NOT NULL DEFAULT 'done',
  `admin_id` INT NULL,
  `created_at` DATETIME NOT NULL,
  KEY `idx_bc_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    /** اگر خواندن schema.sql ممکن نبود، حداقل این کلیدها باید باشند */
    public const SETTINGS_FALLBACK = [
        'stock_enabled' => '1', 'stock_title' => 'انبار ملی', 'stock_icon' => '🏪',
        'stock_low_alert' => '3', 'stock_show_empty' => '0', 'stock_per_user' => '0', 'stock_note' => '',
        'backup_hours'   => '6',
        'backup_auto'    => '1',
        'backup_send_tg' => '1',
        'panel_density' => 'comfortable', 'panel_default_theme' => 'dark',
        'sec_verify_mode' => 'off', 'sec_code_ttl' => '10', 'sec_code_length' => '5',
        'sec_max_tries' => '5', 'sec_resend_wait' => '90',
        'rate_failover' => '1', 'rate_live' => '1', 'rate_assets' => 'USDT,TON,TRX',
        'miniapp_enabled' => '1', 'miniapp_accent' => '#3b82f6',
        'install_autodelete' => '1', 'admin_miniapp' => '1',
        'txc_enabled' => '1', 'txc_auto_approve' => '1', 'txc_tolerance' => '3', 'txc_timeout' => '15',
        'txc_auto_reject' => '1', 'txc_reject_low' => '1', 'txc_reject_notfound' => '0',
        'rs_app_title' => '', 'nowpay_min_usd' => '5',
        'rs_enabled' => '1', 'rs_price_gb' => '2000', 'rs_price_day' => '500',
        'rs_min_gb' => '1', 'rs_max_gb' => '500', 'rs_min_days' => '1', 'rs_max_days' => '365',
        'rs_ip_limit' => '2', 'rs_round' => '1000', 'rs_l1_discount' => '10',
        'rs_l2_discount' => '20', 'rs_l2_credit' => '500000', 'rs_requests_open' => '1',
        'rs_req_fee' => '0', 'rs_req_fee_credit' => '0', 'rs_req_fee_target' => 'balance',
        'rs_req_auto' => '0', 'rs_req_level' => '1',
        'rs_plans' => '[]', 'rs_plans_on' => '1',
        'pay_gateways' => '[]', 'pay_gw_migrated' => '0',
        'hp_enabled' => '0', 'hp_api_key' => '', 'hp_secret' => '',
        'hp_fee_mode' => 'seller', 'hp_unit' => 'toman', 'hp_fee_percent' => '20',
        'hp_min' => '0', 'hp_max' => '0', 'hp_label' => '', 'hp_icon' => '',
        'hp_desc' => '', 'hp_audience' => 'all', 'hp_return_url' => '',
        'sub_domain' => '', 'sub_domain_on' => '0', 'sub_domain_https' => '1',
        'sub_domain_path' => '', 'sub_domain_rs' => '1',
        'rel_enabled' => '0', 'rel_chat_id' => '', 'rel_topic_id' => '0',
        'rel_topic_name' => '', 'rel_topic_color' => '9367192', 'rel_style' => 'hero',
        'rel_auto' => '1', 'rel_pin' => '0', 'rel_silent' => '0',
        'rel_title' => '', 'rel_footer' => '', 'rel_btn_text' => '', 'rel_btn_url' => '',
        'rel_draft' => '', 'rel_history' => '[]', 'rel_last_ver' => '',
        'rel_last_at' => '', 'rel_last_msg' => '0',
        'bot_texts' => '{}', 'bot_text_rules' => '[]',
        'bot_buttons' => '[]', 'btn_mode' => 'reply', 'btn_per_row' => '2',
        /* احراز کارت بانکی */
        'cardauth_enabled' => '1', 'cardauth_required' => '1', 'cardauth_auto' => '0',
        'cardauth_max' => '3', 'cardauth_holder' => '1', 'cardauth_sheba' => '0',
        'cardauth_luhn' => '1',
        'cardauth_single' => '0', 'cardauth_photo' => '1', 'cardauth_photo_req' => '0',
        'cardauth_note' => 'شماره‌ی کارتی که قرار است با آن واریز کنید را ثبت کنید. واریز از کارت دیگران پذیرفته نیست.',
        /* نمایندگی — گزینه‌های گسترده */
        'rs_wallet_only' => '0', 'rs_daily_limit' => '0', 'rs_month_limit' => '0',
        'rs_service_cap' => '0', 'rs_min_charge' => '0', 'rs_auto_suspend' => '0',
        'rs_sub_domain' => '', 'rs_welcome' => '', 'rs_support_id' => '',
        'rs_allow_test' => '0', 'rs_allow_rename' => '1', 'rs_allow_delete' => '1',
        'rs_allow_renew' => '1', 'rs_allow_edit' => '1', 'rs_show_price' => '1',
        'rs_hide_panel' => '1', 'rs_force_verify' => '0', 'rs_tos' => '',
    ];

    /* ==================== بررسی وجود ==================== */

    public static function hasTable(string $table): bool
    {
        return (int)DB::val(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [DB::prefix() . $table], 0
        ) > 0;
    }

    public static function hasColumn(string $table, string $col): bool
    {
        return (int)DB::val(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::prefix() . $table, $col], 0
        ) > 0;
    }

    /** نوع فعلی یک ستون مانند varchar(190) */
    public static function columnType(string $table, string $col): string
    {
        try {
            return strtolower((string)DB::val(
                'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [DB::prefix() . $table, $col], ''
            ));
        } catch (Throwable $e) { return ''; }
    }

    /** آیا نوع این ستون باید اصلاح شود؟ */
    public static function needsModify(string $table, string $col, array $badTypes): bool
    {
        if (!self::hasColumn($table, $col)) return false;
        $cur = self::columnType($table, $col);
        if ($cur === '') return false;
        foreach ($badTypes as $bad) {
            if (strpos($cur, strtolower((string)$bad)) === 0) return true;
        }
        return false;
    }

    public static function hasIndex(string $table, string $idx): bool
    {
        return (int)DB::val(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [DB::prefix() . $table, $idx], 0
        ) > 0;
    }

    /** 0.0.2 #33: آیا ایندکسی وجود دارد که با این ستون شروع شود؟ */
    public static function hasIndexOn(string $table, string $col): bool
    {
        try {
            return (int)DB::val(
                'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1',
                [DB::prefix() . $table, $col], 0
            ) > 0;
        } catch (Throwable $e) { return false; }
    }

    /** نام ستون‌های داخل تعریف ایندکس */
    public static function indexCols(string $def): array
    {
        if (!preg_match_all('/`([A-Za-z0-9_]+)`/', $def, $m)) return [];
        return $m[1];
    }

    /** ایندکس فقط وقتی ساخته می‌شود که ستون‌هایش موجود باشند و ایندکس مشابه نباشد */
    public static function indexReady(string $table, string $def): bool
    {
        $cols = self::indexCols($def);
        if (!$cols) return false;
        foreach ($cols as $c) {
            if (!self::hasColumn($table, $c)) return false;
        }
        return !self::hasIndexOn($table, (string)$cols[0]);
    }

    /* ==================== کلیدهای تنظیمات ==================== */

    /** کلیدهای پیش‌فرض را از database/schema.sql می‌خواند */
    public static function wantedSettings(): array
    {
        $out  = [];
        $file = APP_ROOT . '/database/schema.sql';
        if (is_file($file)) {
            $sql = (string)file_get_contents($file);
            $pos = stripos($sql, 'INTO {p}settings');
            if ($pos !== false) {
                $tail = substr($sql, $pos);
                if (preg_match_all("/\\(\\s*'([A-Za-z0-9_]+)'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*\\)/", $tail, $m, PREG_SET_ORDER)) {
                    foreach ($m as $one) {
                        $out[$one[1]] = str_replace(["\\'", '\\\\'], ["'", '\\'], $one[2]);
                    }
                }
            }
        }
        if (count($out) < 10) $out = self::SETTINGS_FALLBACK;
        return $out;
    }

    private static function existingSettings(): array
    {
        $keys = [];
        try {
            foreach (DB::all('SELECT `k` FROM {p}settings') as $r) $keys[(string)$r['k']] = true;
        } catch (Throwable $e) { /* جدول تنظیمات هنوز نیست */ }
        return $keys;
    }

    /* ==================== نقشه کار ==================== */

    /** فهرست چیزهایی که هنو�� ساخته نشده‌اند */
    public static function plan(): array
    {
        $miss = ['tables' => [], 'columns' => [], 'indexes' => [], 'settings' => [], 'modify' => [], 'error' => ''];

        try {
            foreach (self::TABLES as $t => $ddl) {
                if (!self::hasTable($t)) $miss['tables'][] = $t;
            }
            foreach (self::COLUMNS as $t => $cols) {
                if (!self::hasTable($t)) continue;
                foreach ($cols as $c => $def) {
                    if (!self::hasColumn($t, $c)) $miss['columns'][] = $t . '.' . $c;
                }
            }
            foreach (self::MODIFY as $t => $cols) {
                if (!self::hasTable($t)) continue;
                foreach ($cols as $c => $spec) {
                    if (self::needsModify($t, $c, (array)$spec[1])) $miss['modify'][] = $t . '.' . $c;
                }
            }
            foreach (self::INDEXES as $t => $ixs) {
                if (!self::hasTable($t)) continue;
                foreach ($ixs as $i => $cols) {
                    if (self::hasIndex($t, $i)) continue;
                    if (!self::indexReady($t, (string)$cols)) continue; /* 0.0.2 #33 */
                    $miss['indexes'][] = $t . '.' . $i;
                }
            }
            $have = self::existingSettings();
            foreach (self::wantedSettings() as $k => $v) {
                if (!isset($have[$k])) $miss['settings'][] = $k;
            }
        } catch (Throwable $e) {
            $miss['error'] = $e->getMessage();
        }

        return $miss;
    }

    /** تعداد کل موارد ناقص */
    public static function missingCount(?array $plan = null): int
    {
        $p = $plan ?? self::plan();
        return count($p['tables']) + count($p['columns']) + count($p['indexes'])
            + count($p['settings']) + count($p['modify'] ?? []);
    }

    /* ==================== اجرا ==================== */

    /** فقط موارد ناقص را می‌سازد؛ اجرای چندباره بی‌خطر است */
    public static function run(): array
    {
        $lines = [];
        $done  = 0;
        $fail  = 0;

        $exec = function (string $sql, string $label) use (&$lines, &$done, &$fail): void {
            try {
                DB::pdo()->exec(DB::raw($sql));
                $lines[] = '✅ ' . $label;
                $done++;
            } catch (Throwable $e) {
                $lines[] = '⛔️ ' . $label . ' → ' . mb_substr($e->getMessage(), 0, 160);
                $fail++;
            }
        };

        // ۱) جدول‌ها
        foreach (self::TABLES as $t => $ddl) {
            if (self::hasTable($t)) continue;
            $exec($ddl, 'ساخت جدول ' . $t);
        }

        // ۲) ستون‌ها
        foreach (self::COLUMNS as $t => $cols) {
            if (!self::hasTable($t)) { $lines[] = '⚠️ جدول ' . $t . ' وجود ندارد'; continue; }
            foreach ($cols as $c => $def) {
                if (self::hasColumn($t, $c)) continue;
                $exec('ALTER TABLE {p}' . $t . ' ADD COLUMN `' . $c . '` ' . $def, 'ستون ' . $t . '.' . $c);
            }
        }

        // ۲.۵) اصلاح نوع ستون‌های نادرست
        foreach (self::MODIFY as $t => $cols) {
            if (!self::hasTable($t)) continue;
            foreach ($cols as $c => $spec) {
                if (!self::needsModify($t, $c, (array)$spec[1])) continue;
                if ($t === 'services' && $c === 'notified') {
                    // مقدارهای غیرعددی/NULL را پیش از تغییر نوع صفر می‌کنیم
                    $exec("UPDATE {p}services SET `notified` = '0' WHERE `notified` IS NULL OR `notified` NOT REGEXP '^[0-9]+$'",
                        'پاک‌سازی مقدارهای نامعتبر services.notified');
                }
                $exec('ALTER TABLE {p}' . $t . ' MODIFY `' . $c . '` ' . $spec[0], 'اصلاح نوع ستون ' . $t . '.' . $c);
            }
        }

        // ۳) ایندکس‌ها
        foreach (self::INDEXES as $t => $ixs) {
            if (!self::hasTable($t)) continue;
            foreach ($ixs as $i => $cols) {
                if (self::hasIndex($t, $i)) continue;
                if (!self::indexReady($t, (string)$cols)) continue; /* 0.0.2 #33 */
                $exec('ALTER TABLE {p}' . $t . ' ADD INDEX `' . $i . '` ' . $cols, 'ایندکس ' . $t . '.' . $i);
            }
        }

        // ۴) کلیدهای تنظیمات
        if (self::hasTable('settings')) {
            $have = self::existingSettings();
            $add  = 0;
            foreach (self::wantedSettings() as $k => $v) {
                if (isset($have[$k])) continue;
                try {
                    DB::q('INSERT IGNORE INTO {p}settings (`k`,`v`) VALUES (?,?)', [$k, $v]);
                    $add++;
                } catch (Throwable $e) { $fail++; }
            }
            if ($add > 0) { $lines[] = '✅ ' . $add . ' تنظیم پیش‌فرض اضافه شد'; $done += $add; }
        }

        // ۵) اصلاح‌های داده‌ای (همیشه بی‌خطر)
        foreach (self::legacy() as $l) $lines[] = $l;

        try { DB::loadSettings(true); } catch (Throwable $e) { /* بی‌اهمیت */ }

        $ok  = $fail === 0;
        $msg = $done === 0 && $fail === 0
            ? '✅ ساختار دیتابیس کامل است؛ چیزی برای تغییر نبود.'
            : ($ok ? '✅ ساختار دیتابیس تکمیل شد (' . $done . ' مورد).'
                   : '⚠️ ' . $done . ' مورد انجام شد و ' . $fail . ' مورد خطا داد.');

        if (function_exists('app_log')) app_log('migrate', $msg);

        return ['ok' => $ok, 'message' => $msg, 'lines' => $lines, 'applied' => $done, 'failed' => $fail];
    }

    /** اصلاح‌های داده‌ای منسوخ؛ چندبار اجرا شدن اشکالی ندارد */
    public static function legacy(): array
    {
        $out = [];

        /* نوع پنل: x-ui ← سنایی قدیم ، 3x-ui ← سنایی جدید */
        try {
            $n  = (int)DB::q("UPDATE {p}panels SET `type` = 'sanaei-old'
                WHERE LOWER(`type`) IN ('x-ui', 'xui', 'sanaei_old')")->rowCount();
            $n += (int)DB::q("UPDATE {p}panels SET `type` = 'sanaei'
                WHERE LOWER(`type`) IN ('3x-ui', '3xui', 'x3-ui', 'sanaei-new', 'sanaei_new')")->rowCount();
            if ($n > 0) $out[] = '🔁 نوع ' . $n . ' پنل به سنایی (قدیم/جدید) تبدیل شد';
        } catch (Throwable $e) { /* بی‌اهمیت */ }

        // تنظیم منسوخ بارکد
        try {
            $n = DB::delete('settings', '`k` = ?', ['qr_enabled']);
            if ($n > 0) $out[] = '🧹 تنظیم منسوخ qr_enabled حذف شد';
        } catch (Throwable $e) { /* بی‌اهمیت */ }

        // انتقال اینباند تک‌گانه اکانت تست به ستون چندگانه
        try {
            if (self::hasColumn('panels', 'test_inbound_id') && self::hasColumn('panels', 'test_inbound_ids')) {
                $n = DB::q("UPDATE {p}panels SET `test_inbound_ids` = CAST(`test_inbound_id` AS CHAR)
                    WHERE (`test_inbound_ids` IS NULL OR `test_inbound_ids` = '')
                      AND `test_inbound_id` IS NOT NULL AND `test_inbound_id` > 0")->rowCount();
                if ($n > 0) $out[] = '🔁 اینباند اکانت تست ' . $n . ' پنل منتقل شد';
            }
        } catch (Throwable $e) { /* بی‌اهمیت */ }

        // مدیر اول همیشه دسترسی کامل دارد
        try {
            if (self::hasColumn('admins', 'perms')) {
                // نقش owner/root که نصب‌کننده می‌سازد باید به super تبدیل شود
                $n = DB::q("UPDATE {p}admins SET `role` = 'super' WHERE LOWER(`role`) IN ('owner', 'root')")->rowCount();
                if ($n > 0) $out[] = '👑 نقش owner به super تبدیل شد (' . $n . ' مدیر)';

                $id = (int)DB::val('SELECT MIN(`id`) FROM {p}admins', [], 0);
                if ($id > 0) {
                    $n = DB::q("UPDATE {p}admins SET `role` = 'super' WHERE `id` = ? AND `role` <> 'super'", [$id])->rowCount();
                    if ($n > 0) $out[] = '👑 نقش مدیر اصلی روی super تنظیم شد';
                }
                // مدیر کل بدون لیست دسترسی، دسترسی کامل بگیرد
                DB::q("UPDATE {p}admins SET `perms` = ? WHERE `role` = 'super' AND (`perms` IS NULL OR `perms` = '')", [jenc([Perm::ALL])]);
            }
        } catch (Throwable $e) { /* بی‌اهمیت */ }

        return $out;
    }
}
