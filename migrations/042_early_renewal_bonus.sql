ALTER TABLE orders ADD COLUMN early_renewal_bonus_days INTEGER NOT NULL DEFAULT 0 CHECK(early_renewal_bonus_days BETWEEN 0 AND 365);
