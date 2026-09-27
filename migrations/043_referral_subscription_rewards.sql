CREATE TABLE referral_subscription_payments (
 id VARCHAR(32) PRIMARY KEY,
 referrer_id VARCHAR(32) NOT NULL REFERENCES users(id),
 referral_id VARCHAR(32) NOT NULL UNIQUE REFERENCES users(id),
 order_id VARCHAR(32) NOT NULL UNIQUE REFERENCES orders(id),
 paid_at BIGINT NOT NULL
);
CREATE INDEX referral_subscription_payments_referrer ON referral_subscription_payments(referrer_id,paid_at);

CREATE TABLE referral_subscription_rewards (
 id VARCHAR(32) PRIMARY KEY,
 referrer_id VARCHAR(32) NOT NULL REFERENCES users(id),
 milestone INTEGER NOT NULL CHECK(milestone > 0),
 subscription_id VARCHAR(32) NOT NULL REFERENCES subscriptions(id),
 created_at BIGINT NOT NULL,
 UNIQUE(referrer_id,milestone)
);
CREATE INDEX referral_subscription_rewards_referrer ON referral_subscription_rewards(referrer_id,created_at);
