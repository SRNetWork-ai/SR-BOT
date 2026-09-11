-- مقادیر پیش‌فرض سیستم پشتیبان‌گیری و به‌روزرسانی (قابل اجرای مجدد)
INSERT IGNORE INTO {p}settings (`k`,`v`) VALUES
('backup_auto','1'),
('backup_hours','24'),
('backup_keep','7'),
('backup_type','db'),
('backup_send_tg','1'),
('update_repo','SRNetWork-ai/SR-BOT'),
('update_branch','main'),
('update_token',''),
('update_auto_check','1');
