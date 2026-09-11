-- 0018: ربات اختصاصی نمایندگان (راه‌اندازی خودکار)
CREATE TABLE IF NOT EXISTS `{p}rs_bots` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `tg_id` BIGINT NOT NULL DEFAULT 0,
  `owner_id` BIGINT NOT NULL DEFAULT 0,
  `bot_id` BIGINT NOT NULL DEFAULT 0,
  `username` VARCHAR(64) NULL,
  `title` VARCHAR(120) NULL,
  `token` VARCHAR(120) NOT NULL,
  `secret` VARCHAR(64) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `paid` INT NOT NULL DEFAULT 0,
  `tx_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `hook_at` DATETIME NULL,
  `last_at` DATETIME NULL,
  `updates` INT UNSIGNED NOT NULL DEFAULT 0,
  `err` VARCHAR(400) NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rsbot_secret` (`secret`),
  UNIQUE KEY `uk_rsbot_bot` (`bot_id`),
  KEY `idx_rsbot_user` (`user_id`),
  KEY `idx_rsbot_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{p}rs_bot_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id` INT UNSIGNED NOT NULL,
  `tg_id` BIGINT NOT NULL,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rsbu` (`bot_id`, `tg_id`),
  KEY `idx_rsbu_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `{p}settings` (`k`, `v`) VALUES ('rs_bot_auto', '1') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('rs_bot_max', '1') ON DUPLICATE KEY UPDATE `k` = `k`;
