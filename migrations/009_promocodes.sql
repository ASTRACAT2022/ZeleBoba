CREATE TABLE promocodes (
 id VARCHAR(32) PRIMARY KEY,
 code VARCHAR(50) UNIQUE NOT NULL,
 type VARCHAR(30) NOT NULL CHECK(type IN ('balance','subscription_days','trial_subscription','discount','balance_and_days')),
 balance_bonus_kopeks BIGINT NOT NULL DEFAULT 0,
 subscription_days INTEGER NOT NULL DEFAULT 0,
 traffic_gb INTEGER NOT NULL DEFAULT 0,
 max_uses INTEGER NOT NULL DEFAULT 1,
 current_uses INTEGER NOT NULL DEFAULT 0,
 valid_from BIGINT NOT NULL,
 valid_until BIGINT,
 is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
 first_purchase_only INTEGER NOT NULL DEFAULT 0 CHECK(first_purchase_only IN (0,1)),
 plan_id VARCHAR(32) REFERENCES plans(id),
 created_by VARCHAR(32) REFERENCES users(id),
 created_at BIGINT NOT NULL
);
CREATE INDEX promocodes_code ON promocodes(code);

CREATE TABLE promocode_uses (
 id VARCHAR(32) PRIMARY KEY,
 promocode_id VARCHAR(32) NOT NULL REFERENCES promocodes(id),
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 used_at BIGINT NOT NULL,
 UNIQUE(user_id, promocode_id)
);

ALTER TABLE users ADD COLUMN promo_offer_discount_percent INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN promo_offer_discount_source VARCHAR(100);
ALTER TABLE users ADD COLUMN promo_offer_discount_expires_at BIGINT;
ALTER TABLE users ADD COLUMN has_had_paid_subscription INTEGER NOT NULL DEFAULT 0 CHECK(has_had_paid_subscription IN (0,1));

-- Rebuild subscriptions: order_id becomes nullable (trial subscriptions have no order),
-- and the table gains the full Bedolaga-compatible column set.
CREATE TABLE subscriptions_new (
 id VARCHAR(32) PRIMARY KEY,
 order_id VARCHAR(32) UNIQUE REFERENCES orders(id),
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 status VARCHAR(20) NOT NULL CHECK(status IN ('provisioning','active','expired','trial','limited','disabled')),
 expires_at BIGINT NOT NULL,
 remote_id VARCHAR(100),
 subscription_url TEXT,
 created_at BIGINT NOT NULL,
 plan_id VARCHAR(32) REFERENCES plans(id),
 traffic_limit_gb INTEGER NOT NULL DEFAULT 0,
 purchased_traffic_gb INTEGER NOT NULL DEFAULT 0,
 device_limit INTEGER NOT NULL DEFAULT 1,
 is_trial INTEGER NOT NULL DEFAULT 0 CHECK(is_trial IN (0,1)),
 start_date BIGINT,
 updated_at BIGINT,
 last_webhook_update_at BIGINT,
 last_revoke_at BIGINT,
 grace_candidate_reason VARCHAR(16),
 grace_candidate_at BIGINT,
 grace_suppressed_until BIGINT,
 remnawave_short_uuid VARCHAR(255),
 remnawave_id BIGINT,
 remnawave_uuid VARCHAR(255),
 remnawave_short_id VARCHAR(16),
 autopay_enabled INTEGER NOT NULL DEFAULT 0,
 autopay_days_before INTEGER NOT NULL DEFAULT 3,
 autopay_period_days INTEGER,
 is_daily_paused INTEGER NOT NULL DEFAULT 0,
 last_daily_charge_at BIGINT,
 traffic_reset_at BIGINT,
 subscription_crypto_link TEXT,
 modem_enabled INTEGER NOT NULL DEFAULT 0,
 connected_squads TEXT,
 traffic_used_gb REAL NOT NULL DEFAULT 0,
 auto_renew INTEGER NOT NULL DEFAULT 0,
 renew_plan_id VARCHAR(32),
 renew_price_minor BIGINT,
 renew_at BIGINT,
 renew_order_id VARCHAR(32),
 renew_failed_at BIGINT,
 renew_fail_count INTEGER NOT NULL DEFAULT 0
);
INSERT INTO subscriptions_new(id,order_id,user_id,status,expires_at,remote_id,subscription_url,created_at,auto_renew,renew_plan_id,renew_price_minor,renew_at,renew_order_id,renew_failed_at,renew_fail_count)
 SELECT id,order_id,user_id,status,expires_at,remote_id,subscription_url,created_at,auto_renew,renew_plan_id,renew_price_minor,renew_at,renew_order_id,renew_failed_at,renew_fail_count FROM subscriptions;
DROP TABLE subscriptions;
ALTER TABLE subscriptions_new RENAME TO subscriptions;
CREATE INDEX subscriptions_user ON subscriptions(user_id, created_at);
CREATE INDEX subscriptions_renew ON subscriptions(auto_renew, renew_at);
CREATE INDEX subscriptions_user_status ON subscriptions(user_id, status);
