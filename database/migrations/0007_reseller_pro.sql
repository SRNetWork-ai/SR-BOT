-- 0007: تعرفهٔ اختصاصی نمایندگان (قابل اجرای مجدد)

SET @c1 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}users' AND COLUMN_NAME = 'reseller_price_gb');
SET @s1 := IF(@c1 = 0, 'ALTER TABLE `{p}users` ADD COLUMN `reseller_price_gb` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st1 FROM @s1;
EXECUTE st1;
DEALLOCATE PREPARE st1;

SET @c2 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}users' AND COLUMN_NAME = 'reseller_price_day');
SET @s2 := IF(@c2 = 0, 'ALTER TABLE `{p}users` ADD COLUMN `reseller_price_day` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st2 FROM @s2;
EXECUTE st2;
DEALLOCATE PREPARE st2;

SET @c3 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}users' AND COLUMN_NAME = 'reseller_max_gb');
SET @s3 := IF(@c3 = 0, 'ALTER TABLE `{p}users` ADD COLUMN `reseller_max_gb` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st3 FROM @s3;
EXECUTE st3;
DEALLOCATE PREPARE st3;

SET @c4 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}users' AND COLUMN_NAME = 'reseller_max_days');
SET @s4 := IF(@c4 = 0, 'ALTER TABLE `{p}users` ADD COLUMN `reseller_max_days` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st4 FROM @s4;
EXECUTE st4;
DEALLOCATE PREPARE st4;

SET @c5 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}users' AND COLUMN_NAME = 'reseller_panels');
SET @s5 := IF(@c5 = 0, 'ALTER TABLE `{p}users` ADD COLUMN `reseller_panels` VARCHAR(190) NULL', 'SELECT 1');
PREPARE st5 FROM @s5;
EXECUTE st5;
DEALLOCATE PREPARE st5;

SET @c6 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}users' AND COLUMN_NAME = 'reseller_note');
SET @s6 := IF(@c6 = 0, 'ALTER TABLE `{p}users` ADD COLUMN `reseller_note` VARCHAR(400) NULL', 'SELECT 1');
PREPARE st6 FROM @s6;
EXECUTE st6;
DEALLOCATE PREPARE st6;
