-- 0023 : مهر «تایید شده / رد شده» روی کارت رسید در ربات + عمر فاکتور درگاه خودکار
ALTER TABLE `{p}transactions` ADD COLUMN `admin_msg` TEXT NULL AFTER `note`;

INSERT INTO `{p}settings` (`k`, `v`) VALUES
  ('gw_stale_hours','6')
ON DUPLICATE KEY UPDATE `k` = `k`;
