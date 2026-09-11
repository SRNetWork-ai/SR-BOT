-- 0022 : تصویر کارت + قفل تک‌کارت
ALTER TABLE `{p}user_cards` ADD COLUMN `photo` VARCHAR(255) NULL AFTER `uses`;

INSERT INTO `{p}settings` (`k`, `v`) VALUES
  ('cardauth_single','1'),
  ('cardauth_photo','1'),
  ('cardauth_photo_req','0')
ON DUPLICATE KEY UPDATE `k` = `k`;
