ALTER TABLE plans ADD COLUMN is_trial_available INTEGER NOT NULL DEFAULT 0 CHECK(is_trial_available IN (0,1));
ALTER TABLE plans ADD COLUMN trial_duration_days INTEGER;
ALTER TABLE plans ADD COLUMN trial_price_kopeks BIGINT NOT NULL DEFAULT 0;
ALTER TABLE plans ADD COLUMN show_in_gift INTEGER NOT NULL DEFAULT 1 CHECK(show_in_gift IN (0,1));
ALTER TABLE plans ADD COLUMN allow_traffic_topup INTEGER NOT NULL DEFAULT 1 CHECK(allow_traffic_topup IN (0,1));
ALTER TABLE plans ADD COLUMN traffic_topup_enabled INTEGER NOT NULL DEFAULT 0 CHECK(traffic_topup_enabled IN (0,1));
ALTER TABLE plans ADD COLUMN traffic_topup_packages TEXT;
ALTER TABLE plans ADD COLUMN max_topup_traffic_gb INTEGER NOT NULL DEFAULT 0;
ALTER TABLE plans ADD COLUMN custom_days_enabled INTEGER NOT NULL DEFAULT 0 CHECK(custom_days_enabled IN (0,1));
ALTER TABLE plans ADD COLUMN price_per_day_kopeks BIGINT NOT NULL DEFAULT 0;
ALTER TABLE plans ADD COLUMN min_days INTEGER NOT NULL DEFAULT 1;
ALTER TABLE plans ADD COLUMN max_days INTEGER NOT NULL DEFAULT 365;
ALTER TABLE plans ADD COLUMN custom_traffic_enabled INTEGER NOT NULL DEFAULT 0 CHECK(custom_traffic_enabled IN (0,1));
ALTER TABLE plans ADD COLUMN traffic_price_per_gb_kopeks BIGINT NOT NULL DEFAULT 0;
ALTER TABLE plans ADD COLUMN min_traffic_gb INTEGER NOT NULL DEFAULT 1;
ALTER TABLE plans ADD COLUMN max_traffic_gb INTEGER NOT NULL DEFAULT 1000;
ALTER TABLE plans ADD COLUMN device_price_kopeks BIGINT;
ALTER TABLE plans ADD COLUMN max_device_limit INTEGER;
ALTER TABLE plans ADD COLUMN period_prices TEXT;
ALTER TABLE plans ADD COLUMN highlight_period_days INTEGER;
ALTER TABLE plans ADD COLUMN is_highlighted INTEGER NOT NULL DEFAULT 0 CHECK(is_highlighted IN (0,1));
ALTER TABLE plans ADD COLUMN tier_level INTEGER NOT NULL DEFAULT 1;
ALTER TABLE plans ADD COLUMN description TEXT;
ALTER TABLE plans ADD COLUMN display_order INTEGER NOT NULL DEFAULT 0;
ALTER TABLE plans ADD COLUMN is_daily INTEGER NOT NULL DEFAULT 0 CHECK(is_daily IN (0,1));
ALTER TABLE plans ADD COLUMN daily_price_kopeks BIGINT NOT NULL DEFAULT 0;
ALTER TABLE plans ADD COLUMN lava_product_id VARCHAR(255);
ALTER TABLE plans ADD COLUMN traffic_reset_mode VARCHAR(20);
ALTER TABLE plans ADD COLUMN server_traffic_limits TEXT;
ALTER TABLE plans ADD COLUMN allowed_squads TEXT;

CREATE TABLE subscription_conversions (
 id VARCHAR(32) PRIMARY KEY,
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 converted_at BIGINT NOT NULL,
 trial_duration_days INTEGER,
 payment_method VARCHAR(50),
 first_payment_amount_kopeks BIGINT,
 first_paid_period_days INTEGER,
 created_at BIGINT NOT NULL
);
CREATE INDEX sub_conversions_user ON subscription_conversions(user_id);
