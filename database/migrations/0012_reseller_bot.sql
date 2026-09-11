-- 0012: reseller own-bot offer + shrink refund flag
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('rs_bot_enabled', '0') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('rs_bot_price', '0') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('rs_bot_note', '') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('rs_shrink_refund', '0') ON DUPLICATE KEY UPDATE `k` = `k`;
INSERT INTO `{p}settings` (`k`, `v`) VALUES ('referral_bonus', '0') ON DUPLICATE KEY UPDATE `k` = `k`;
