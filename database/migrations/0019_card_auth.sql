-- ============================================================
-- 0019 — احراز کارت بانکی برای پرداخت کارت به کارت
-- هیچ اطلاع حساسی (CVV2 / رمز دوم / تاریخ انقضا) ذخیره نمی‌شود
-- ============================================================

CREATE TABLE IF NOT EXISTS `{p}user_cards` (
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
  `admin_id` INT NULL,
  `created_at` DATETIME NOT NULL,
  `decided_at` DATETIME NULL,
  `last_used_at` DATETIME NULL,
  KEY `idx_uc_user` (`user_id`),
  KEY `idx_uc_status` (`status`),
  KEY `idx_uc_pan` (`pan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- اتصال تراکنش واریز به کارت احرازشده
ALTER TABLE `{p}transactions` ADD COLUMN `card_id` INT UNSIGNED NULL;

INSERT INTO `{p}settings` (`k`, `v`) VALUES
  ('cardauth_enabled',  '1'),
  ('cardauth_required', '1'),
  ('cardauth_auto',     '0'),
  ('cardauth_max',      '3'),
  ('cardauth_holder',   '1'),
  ('cardauth_sheba',    '0'),
  ('cardauth_luhn',     '1'),
  ('cardauth_note',     'شماره‌ی کارتی که قرار است با آن واریز کنید را ثبت کنید. واریز از کارت دیگران پذیرفته نیست.')
ON DUPLICATE KEY UPDATE `v` = `v`;
