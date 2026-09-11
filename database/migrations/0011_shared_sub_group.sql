-- 0011: ساب مشترک چندپنلی + اطمینان از ستون‌های محدودیت سرعت محصولات

-- گروه سرویس: چند کانفیگ از چند پنل با یک حجم مشترک و یک لینک ساب
SET @c1 := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}services' AND COLUMN_NAME = 'group_key');
SET @s1 := IF(@c1 = 0, 'ALTER TABLE `{p}services` ADD COLUMN `group_key` VARCHAR(32) NULL', 'SELECT 1');
PREPARE st1 FROM @s1; EXECUTE st1; DEALLOCATE PREPARE st1;

SET @c2 := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}services' AND COLUMN_NAME = 'group_quota_gb');
SET @s2 := IF(@c2 = 0, 'ALTER TABLE `{p}services` ADD COLUMN `group_quota_gb` DECIMAL(10,2) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st2 FROM @s2; EXECUTE st2; DEALLOCATE PREPARE st2;

SET @i1 := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}services' AND INDEX_NAME = 'idx_group_key');
SET @s3 := IF(@i1 = 0, 'ALTER TABLE `{p}services` ADD INDEX `idx_group_key` (`group_key`)', 'SELECT 1');
PREPARE st3 FROM @s3; EXECUTE st3; DEALLOCATE PREPARE st3;

-- محدودیت دستگاه و سرعت محصولات (اگر مایگریشن ۰۰۰۸ اجرا نشده باشد)
SET @c4 := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}products' AND COLUMN_NAME = 'device_limit');
SET @s4 := IF(@c4 = 0, 'ALTER TABLE `{p}products` ADD COLUMN `device_limit` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st4 FROM @s4; EXECUTE st4; DEALLOCATE PREPARE st4;

SET @c5 := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}products' AND COLUMN_NAME = 'speed_up');
SET @s5 := IF(@c5 = 0, 'ALTER TABLE `{p}products` ADD COLUMN `speed_up` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st5 FROM @s5; EXECUTE st5; DEALLOCATE PREPARE st5;

SET @c6 := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}products' AND COLUMN_NAME = 'speed_down');
SET @s6 := IF(@c6 = 0, 'ALTER TABLE `{p}products` ADD COLUMN `speed_down` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st6 FROM @s6; EXECUTE st6; DEALLOCATE PREPARE st6;

-- تنظیمات تازه
INSERT INTO `{p}settings` (`k`, `v`) VALUES
  ('sub_local', '1'),
  ('miniapp_rs_tab', '0'),
  ('mute_start_text', ''),
  ('wallet_receipt_any', '1')
ON DUPLICATE KEY UPDATE `k` = `k`;
