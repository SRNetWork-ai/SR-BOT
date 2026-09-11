-- 0010: سرویس‌های نمایندگی + حالت سکوت ربات (قابل اجرای مجدد)
--   ۱) rs_bundles      : تعریف سرویس‌های آماده (چند پنل / چند اینباند) به صورت JSON
--   ۲) bot_mute        : وقتی روشن باشد ربات به هیچ پیامی پاسخ نمی‌دهد (فقط مینی‌اپ)
--   ۳) bot_mute_admins : مدیران از حالت سکوت مستثنا باشند

INSERT INTO `{p}settings` (`k`, `v`) VALUES
  ('rs_bundles', ''),
  ('bot_mute', '0'),
  ('bot_mute_admins', '1'),
  ('miniapp_in_sections', '0')
ON DUPLICATE KEY UPDATE `k` = `k`;

-- ۴) رفع قفل مدیر اصلی: نصب‌کننده نقش owner می‌ساخت ولی سامانهٔ دسترسی super می‌شناسد
UPDATE `{p}admins` SET `role` = 'super' WHERE LOWER(`role`) IN ('owner', 'root');
UPDATE `{p}admins` SET `perms` = '["*"]'
  WHERE `role` = 'super' AND (`perms` IS NULL OR `perms` = '');
