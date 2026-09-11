-- انبار ملی
CREATE TABLE IF NOT EXISTS {p}stock_cats (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `icon` VARCHAR(16) NULL,
  `kind` VARCHAR(16) NOT NULL DEFAULT 'config',
  `description` TEXT NULL,
  `guide` TEXT NULL,
  `price` BIGINT NOT NULL DEFAULT 0,
  `old_price` BIGINT NULL,
  `days` INT NOT NULL DEFAULT 0,
  `volume_gb` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `sold` INT NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  KEY `idx_sc_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}stock_items (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `cat_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `kind` VARCHAR(16) NOT NULL DEFAULT 'config',
  `title` VARCHAR(120) NULL,
  `payload` LONGTEXT NULL,
  `file_id` VARCHAR(255) NULL,
  `file_name` VARCHAR(120) NULL,
  `note` VARCHAR(255) NULL,
  `price` BIGINT NOT NULL DEFAULT 0,
  `status` VARCHAR(16) NOT NULL DEFAULT 'free',
  `user_id` INT UNSIGNED NULL,
  `tg_id` BIGINT NULL,
  `order_id` INT UNSIGNED NULL,
  `admin_id` INT NULL,
  `sold_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  KEY `idx_si_cat` (`cat_id`),
  KEY `idx_si_status` (`status`),
  KEY `idx_si_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
