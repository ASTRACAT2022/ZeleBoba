ALTER TABLE topups ADD COLUMN referral_first INTEGER NOT NULL DEFAULT 0 CHECK(referral_first IN (0,1));
ALTER TABLE topups ADD COLUMN referral_processed INTEGER NOT NULL DEFAULT 0 CHECK(referral_processed IN (0,1));
ALTER TABLE withdrawal_requests ADD COLUMN wallet_reserved INTEGER NOT NULL DEFAULT 0 CHECK(wallet_reserved IN (0,1));
