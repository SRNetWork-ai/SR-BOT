-- ============================================================
--  مایگریشن ۰۰۰۴
--  سیستم نمایندگی + تشخیص خودکار هش تراکنش + حذف خودکار نصاب
-- ============================================================

-- ۱) ستون‌های نمایندگی روی کاربران
ALTER TABLE {p}users ADD COLUMN `reseller_level` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE {p}users ADD COLUMN `reseller_credit` BIGINT NOT NULL DEFAULT 0;
ALTER TABLE {p}users ADD COLUMN `reseller_discount` TINYINT(2) NOT NULL DEFAULT 0;
ALTER TABLE {p}users ADD COLUMN `reseller_at` DATETIME NULL;

-- ۲) نشان‌گذاری سرویس‌های ساخته‌شده توسط نماینده
ALTER TABLE {p}services ADD COLUMN `is_reseller` TINYINT(1) NOT NULL DEFAULT 0;

-- ۳) ایندکس هش تراکنش برای تشخیص هش تکراری
ALTER TABLE {p}transactions ADD INDEX `idx_txid` (`txid`);

-- ۴) تنظیمات جدید
INSERT IGNORE INTO {p}settings (`k`,`v`) VALUES
('install_autodelete','1'),
('admin_miniapp','1'),
('txc_enabled','1'),
('txc_auto_approve','1'),
('txc_tolerance','3'),
('txc_timeout','15'),
('txc_bscscan_key',''),
('txc_etherscan_key',''),
('txc_tonapi_key',''),
('rs_enabled','1'),
('rs_price_gb','2000'),
('rs_price_day','500'),
('rs_base_fee','0'),
('rs_min_gb','1'),
('rs_max_gb','500'),
('rs_min_days','1'),
('rs_max_days','365'),
('rs_ip_limit','2'),
('rs_round','1000'),
('rs_l1_discount','10'),
('rs_l2_discount','20'),
('rs_l2_credit','500000'),
('rs_panels',''),
('rs_note','');
