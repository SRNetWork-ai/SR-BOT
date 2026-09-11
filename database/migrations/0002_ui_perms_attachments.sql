-- ============================================================
--  مایگریشن ۰۰۰۲ – سیستم دسترسی سفارشی مدیران + پیوست تیکت
--  قابل اجرای مجدد (idempotent)
-- ============================================================

-- ۱) نوع فایل پیوست در پیام‌های تیکت (photo / document / video / voice ...)
ALTER TABLE {p}ticket_messages ADD COLUMN `file_type` VARCHAR(16) NULL AFTER `file_id`;

-- ۲) ستون‌های سیستم دسترسی سفارشی مدیران پنل وب
ALTER TABLE {p}admins ADD COLUMN `perms` TEXT NULL AFTER `role`;
ALTER TABLE {p}admins ADD COLUMN `tg_id` BIGINT NULL AFTER `perms`;
ALTER TABLE {p}admins ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `tg_id`;
ALTER TABLE {p}admins ADD COLUMN `note` VARCHAR(255) NULL AFTER `active`;
ALTER TABLE {p}admins ADD COLUMN `last_ip` VARCHAR(64) NULL AFTER `last_login`;

-- ۳) مدیر اصلی همیشه نقش super دارد (اولین مدیر ساخته‌شده)
UPDATE {p}admins SET `role` = 'super' WHERE `id` = (SELECT * FROM (SELECT MIN(`id`) FROM {p}admins) AS x);

-- ۴) حذف تنظیم منسوخ QR (دیگر بارکد ارسال نمی‌شود)
DELETE FROM {p}settings WHERE `k` = 'qr_enabled';

-- ۵) تنظیمات جدید ظاهر پنل
INSERT IGNORE INTO {p}settings (`k`,`v`) VALUES
('panel_density','comfortable'),
('panel_default_theme','dark');
