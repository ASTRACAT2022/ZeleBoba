CREATE TABLE guest_purchases (
 id VARCHAR(32) PRIMARY KEY,
 token VARCHAR(64) UNIQUE NOT NULL,
 contact_type VARCHAR(20) NOT NULL,
 contact_value VARCHAR(255) NOT NULL,
 is_gift INTEGER NOT NULL DEFAULT 0 CHECK(is_gift IN (0,1)),
 source VARCHAR(20) NOT NULL DEFAULT 'bot',
 buyer_user_id VARCHAR(32) REFERENCES users(id),
 gift_recipient_type VARCHAR(20),
 gift_recipient_value VARCHAR(255),
 gift_message TEXT,
 plan_id VARCHAR(32) REFERENCES plans(id),
 period_days INTEGER NOT NULL,
 amount_kopeks BIGINT NOT NULL,
 currency VARCHAR(3) NOT NULL DEFAULT 'RUB',
 payment_method VARCHAR(50),
 payment_id VARCHAR(255),
 status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','paid','pending_activation','delivered','failed','refunded')),
 subscription_url TEXT,
 user_id VARCHAR(32) REFERENCES users(id),
 created_at BIGINT NOT NULL,
 paid_at BIGINT,
 delivered_at BIGINT,
 idempotency_key VARCHAR(64) UNIQUE,
 retry_count INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX guest_purchases_status ON guest_purchases(status);
CREATE INDEX guest_purchases_user_gift ON guest_purchases(user_id, is_gift, status);
CREATE INDEX guest_purchases_buyer ON guest_purchases(buyer_user_id);
