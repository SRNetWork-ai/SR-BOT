-- 0014: عودت وجه حذف کانفیگ نماینده، اعداد انگلیسی، حالت صفحه ساب و فونت
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('rs_del_refund', '1') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('rs_del_fee_pct', '0') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('num_style', 'en') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('sub_page_mode', 'own') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('sub_show_main', '1') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('ui_font', 'vazir') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('btn_max_per_row', '3') ON DUPLICATE KEY UPDATE `k` = `k`;

-- ستون‌های محدودیت سرعت محصول (اگر نبودند ساخته می‌شوند)
SET @c1 := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}products' AND COLUMN_NAME = 'speed_up');
SET @s1 := IF(@c1 = 0, 'ALTER TABLE `{p}products` ADD COLUMN `speed_up` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st1 FROM @s1; EXECUTE st1; DEALLOCATE PREPARE st1;

SET @c2 := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}products' AND COLUMN_NAME = 'speed_down');
SET @s2 := IF(@c2 = 0, 'ALTER TABLE `{p}products` ADD COLUMN `speed_down` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st2 FROM @s2; EXECUTE st2; DEALLOCATE PREPARE st2;

SET @c3 := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}products' AND COLUMN_NAME = 'device_limit');
SET @s3 := IF(@c3 = 0, 'ALTER TABLE `{p}products` ADD COLUMN `device_limit` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st3 FROM @s3; EXECUTE st3; DEALLOCATE PREPARE st3;

-- محدودیت سرعت روی سرویس برای اعمال مجدد در تمدید
SET @c4 := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}services' AND COLUMN_NAME = 'speed_up');
SET @s4 := IF(@c4 = 0, 'ALTER TABLE `{p}services` ADD COLUMN `speed_up` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st4 FROM @s4; EXECUTE st4; DEALLOCATE PREPARE st4;

SET @c5 := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}services' AND COLUMN_NAME = 'speed_down');
SET @s5 := IF(@c5 = 0, 'ALTER TABLE `{p}services` ADD COLUMN `speed_down` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st5 FROM @s5; EXECUTE st5; DEALLOCATE PREPARE st5;
