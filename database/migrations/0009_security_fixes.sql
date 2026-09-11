-- 0009: اصلاحات امنیتی و ساختاری (قابل اجرای مجدد)
--   ۱) services.notified باید عددی (بیت‌مسک) باشد، نه VARCHAR
--   ۲) panels.password باید جای کافی برای رمز رمزنگاری‌شده داشته باشد
--   ۳) panels.ssl_verify برای کنترل بررسی گواهی SSL هر پنل
--   ۴) تنظیم مهلت بی‌فعالیتی نشست پنل مدیریت

-- ---------- ۱) پاک‌سازی مقدارهای نامعتبر پیش از تغییر نوع ----------
UPDATE `{p}services` SET `notified` = '0'
  WHERE `notified` IS NULL OR `notified` NOT REGEXP '^[0-9]+$';

SET @t1 := (SELECT DATA_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}services' AND COLUMN_NAME = 'notified');
SET @s1 := IF(@t1 IS NULL OR @t1 = 'int', 'SELECT 1',
              'ALTER TABLE `{p}services` MODIFY `notified` INT NOT NULL DEFAULT 0');
PREPARE st1 FROM @s1;
EXECUTE st1;
DEALLOCATE PREPARE st1;

-- ---------- ۲) طول ستون رمز پنل ----------
SET @l2 := (SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}panels' AND COLUMN_NAME = 'password');
SET @s2 := IF(@l2 IS NULL OR @l2 >= 255, 'SELECT 1',
              'ALTER TABLE `{p}panels` MODIFY `password` VARCHAR(255) NOT NULL');
PREPARE st2 FROM @s2;
EXECUTE st2;
DEALLOCATE PREPARE st2;

-- ---------- ۳) ستون ssl_verify برای پنل‌ها ----------
SET @c3 := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{p}panels' AND COLUMN_NAME = 'ssl_verify');
SET @s3 := IF(@c3 = 0,
              'ALTER TABLE `{p}panels` ADD COLUMN `ssl_verify` TINYINT(1) NOT NULL DEFAULT 0',
              'SELECT 1');
PREPARE st3 FROM @s3;
EXECUTE st3;
DEALLOCATE PREPARE st3;

-- ---------- ۴) تنظیمات تازه ----------
INSERT INTO `{p}settings` (`k`, `v`) VALUES
  ('sec_session_idle_min', '120')
ON DUPLICATE KEY UPDATE `k` = `k`;
