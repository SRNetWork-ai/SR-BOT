-- 0017: تشخیص کانفیگ‌هایی که دستی از پنل x-ui حذف شده‌اند
-- شمارندهٔ پیدا نشدن در پنل؛ پس از دو بار تایید، وضعیت سرویس missing می‌شود.
-- idempotent

SET @c1 := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}services' AND COLUMN_NAME = 'panel_miss');
SET @s1 := IF(@c1 = 0, 'ALTER TABLE `{p}services` ADD COLUMN `panel_miss` TINYINT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st1 FROM @s1; EXECUTE st1; DEALLOCATE PREPARE st1;

SET @c2 := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}services' AND INDEX_NAME = 'idx_svc_miss');
SET @s2 := IF(@c2 = 0, 'ALTER TABLE `{p}services` ADD INDEX `idx_svc_miss` (`panel_miss`)', 'SELECT 1');
PREPARE st2 FROM @s2; EXECUTE st2; DEALLOCATE PREPARE st2;
