-- 0006_gateways_texts_buttons.sql
-- درگاه‌های چندگانهٔ پرداخت ، متن‌های قابل ویرایش ربات ، دکمه‌های سفارشی و هزینهٔ درخواست نمایندگی
-- این مایگریشن فقط کلید تنظیمات می‌سازد و قابل اجرای مجدد است (INSERT IGNORE)

INSERT IGNORE INTO {p}settings (`k`,`v`) VALUES
  ('pay_gateways',      '[]'),
  ('pay_gw_migrated',   '0'),
  ('bot_texts',         '{}'),
  ('bot_text_rules',    '[]'),
  ('bot_buttons',       '[]'),
  ('btn_mode',          'reply'),
  ('btn_per_row',       '2'),
  ('rs_req_fee',        '0'),
  ('rs_req_fee_credit', '0'),
  ('rs_req_fee_target', 'balance'),
  ('rs_req_auto',       '0'),
  ('rs_req_level',      '1');
