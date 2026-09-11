-- 0015: سطل زباله کانفیگ ها + تنظیم لینک ساب برای همه سرویس ها
-- idempotent

SET @c1 := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}services' AND COLUMN_NAME = 'deleted_at');
SET @s1 := IF(@c1 = 0, 'ALTER TABLE `{p}services` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE st1 FROM @s1; EXECUTE st1; DEALLOCATE PREPARE st1;

SET @c2 := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}services' AND INDEX_NAME = 'idx_svc_deleted');
SET @s2 := IF(@c2 = 0, 'ALTER TABLE `{p}services` ADD INDEX `idx_svc_deleted` (`status`, `deleted_at`)', 'SELECT 1');
PREPARE st2 FROM @s2; EXECUTE st2; DEALLOCATE PREPARE st2;

-- ردیف های حذف شده قدیمی: تاریخ حذف نامشخص است، از تاریخ ساخت استفاده می شود
UPDATE `{p}services` SET `deleted_at` = COALESCE(`deleted_at`, `created_at`, NOW())
  WHERE `status` = 'deleted' AND `deleted_at` IS NULL;

INSERT INTO `{p}settings` (`k`, `v`) VALUES ('trash_days', '7')   ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('sub_deliver', 'local') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('btn_max_per_row', '3') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('rs_del_mode', 'fair')  ON DUPLICATE KEY UPDATE `k` = `k`;
