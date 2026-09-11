-- 0020: دامنهٔ اختصاصی و برند نماینده (خودِ نماینده تنظیم می‌کند)
ALTER TABLE `{p}users` ADD COLUMN `reseller_domain` VARCHAR(120) NULL;
ALTER TABLE `{p}users` ADD COLUMN `reseller_brand` VARCHAR(80) NULL;
ALTER TABLE `{p}users` ADD COLUMN `reseller_note_pub` VARCHAR(255) NULL;
