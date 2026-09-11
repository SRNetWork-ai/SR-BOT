-- درخواست نمایندگی توسط کاربر + تنظیم باز/بسته بودن ثبت درخواست
ALTER TABLE {p}users ADD COLUMN `reseller_req` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE {p}users ADD COLUMN `reseller_req_at` DATETIME NULL;
ALTER TABLE {p}users ADD COLUMN `reseller_req_note` VARCHAR(400) NULL;
INSERT IGNORE INTO {p}settings (`k`,`v`) VALUES ('rs_requests_open','1');
