-- ============================================================
--  مایگریشن 0003
--  اکانت تست چنداینباندی + پیام همگانی پیشرفته
--  + بخش امنیت و تایید حساب + نرخ ارز چندمنبعی + مینی‌اپ
--  قابل اجرای مجدد (idempotent)
-- ============================================================

-- 1) اکانت تست چنداینباندی (دقیقا مثل محصولات)
ALTER TABLE {p}panels ADD COLUMN `test_inbound_ids` VARCHAR(190) NULL AFTER `test_enabled`;
ALTER TABLE {p}panels ADD COLUMN `test_single_inbound` TINYINT(1) NOT NULL DEFAULT 0 AFTER `test_inbound_ids`;

-- انتقال مقدار قدیمی تک‌اینباندی به ستون جدید
UPDATE {p}panels SET `test_inbound_ids` = CAST(`test_inbound_id` AS CHAR)
  WHERE (`test_inbound_ids` IS NULL OR `test_inbound_ids` = '')
    AND `test_inbound_id` IS NOT NULL AND `test_inbound_id` > 0;

-- 2) ستون‌های تایید حساب کاربر (ایمیل / شماره / لینک تایید)
ALTER TABLE {p}users ADD COLUMN `email_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `email`;
ALTER TABLE {p}users ADD COLUMN `phone_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `phone`;
ALTER TABLE {p}users ADD COLUMN `verify_kind` VARCHAR(12) NULL;
ALTER TABLE {p}users ADD COLUMN `verify_target` VARCHAR(190) NULL;
ALTER TABLE {p}users ADD COLUMN `verify_code` VARCHAR(12) NULL;
ALTER TABLE {p}users ADD COLUMN `verify_token` VARCHAR(64) NULL;
ALTER TABLE {p}users ADD COLUMN `verify_at` DATETIME NULL;
ALTER TABLE {p}users ADD COLUMN `verify_tries` INT NOT NULL DEFAULT 0;
ALTER TABLE {p}users ADD COLUMN `verified_at` DATETIME NULL;
ALTER TABLE {p}users ADD COLUMN `miniapp_at` DATETIME NULL;

-- 3) آرشیو پیام‌های همگانی
CREATE TABLE IF NOT EXISTS {p}broadcasts (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) تنظیمات جدید
INSERT IGNORE INTO {p}settings (`k`,`v`) VALUES
('sec_verify_mode','off'),
('sec_verify_email','0'),
('sec_verify_phone','0'),
('sec_verify_link','0'),
('sec_verify_required_for','buy'),
('sec_code_ttl','10'),
('sec_code_length','5'),
('sec_max_tries','5'),
('sec_resend_wait','90'),
('sec_mail_from','no-reply@localhost'),
('sec_mail_from_name','فروشگاه کانفیگ'),
('sec_mail_driver','mail'),
('sec_smtp_host',''),
('sec_smtp_port','587'),
('sec_smtp_user',''),
('sec_smtp_pass',''),
('sec_smtp_secure','tls'),
('sec_sms_driver','off'),
('sec_sms_url',''),
('sec_sms_method','GET'),
('sec_sms_body',''),
('sec_sms_headers',''),
('sec_block_multi_account','0'),
('sec_min_account_age','0'),
('sec_force_join_before_test','1'),
('sec_login_alert','1'),
('sec_login_max_fail','5'),
('sec_login_lock_min','15'),
('rate_failover','1'),
('rate_live','1'),
('rate_assets','USDT,TON,TRX'),
('rate_custom_url',''),
('rate_custom_path',''),
('rate_custom_headers',''),
('rate_custom_unit','toman'),
('rate_custom_scale','1'),
('rate_src_used',''),
('crypto_addr_USDT',''),
('crypto_net_USDT','TRC20'),
('crypto_addr_TON',''),
('crypto_net_TON','TON'),
('crypto_addr_TRX',''),
('crypto_net_TRX','TRON'),
('miniapp_enabled','1'),
('miniapp_title','فروشگاه کانفیگ'),
('miniapp_accent','#3b82f6'),
('miniapp_button','🚀 اپلیکیشن'),
('miniapp_show_menu','1'),
('bc_batch','25'),
('bc_sleep','1');
