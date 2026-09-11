-- 0013_referral.sql — سیستم معرفی پیشرفته و ربات اختصاصی نماینده

INSERT INTO `{p}settings` (`k`, `v`) VALUES ('referral_enabled',    '1') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('referral_percent',    '0') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('referral_l2_percent', '0') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('referral_first_only', '0') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('referral_min_amount', '0') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('referral_cap',        '0') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('bot_username',         '') ON DUPLICATE KEY UPDATE `k` = `k`;

-- ایندکس معرف برای گزارش‌های سریع
SET @ri := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}users' AND INDEX_NAME = 'idx_referrer');
SET @rs := IF(@ri = 0, 'ALTER TABLE `{p}users` ADD INDEX `idx_referrer` (`referrer_id`)', 'SELECT 1');
PREPARE stR FROM @rs; EXECUTE stR; DEALLOCATE PREPARE stR;
