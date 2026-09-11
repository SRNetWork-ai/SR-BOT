-- 0008: طرح‌های آمادهٔ نمایندگی، مارک نماینده و محدودیت دستگاه/سرعت (قابل اجرای مجدد)

SET @c1 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}users' AND COLUMN_NAME = 'reseller_mark');
SET @s1 := IF(@c1 = 0, 'ALTER TABLE `{p}users` ADD COLUMN `reseller_mark` VARCHAR(24) NULL', 'SELECT 1');
PREPARE st1 FROM @s1;
EXECUTE st1;
DEALLOCATE PREPARE st1;

SET @c2 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}products' AND COLUMN_NAME = 'device_limit');
SET @s2 := IF(@c2 = 0, 'ALTER TABLE `{p}products` ADD COLUMN `device_limit` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st2 FROM @s2;
EXECUTE st2;
DEALLOCATE PREPARE st2;

SET @c3 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}products' AND COLUMN_NAME = 'speed_up');
SET @s3 := IF(@c3 = 0, 'ALTER TABLE `{p}products` ADD COLUMN `speed_up` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st3 FROM @s3;
EXECUTE st3;
DEALLOCATE PREPARE st3;

SET @c4 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}products' AND COLUMN_NAME = 'speed_down');
SET @s4 := IF(@c4 = 0, 'ALTER TABLE `{p}products` ADD COLUMN `speed_down` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st4 FROM @s4;
EXECUTE st4;
DEALLOCATE PREPARE st4;

INSERT INTO `{p}settings` (`k`, `v`) VALUES
  ('rs_plans', '[]'),
  ('rs_plans_on', '1')
ON DUPLICATE KEY UPDATE `k` = `k`;
