-- An order may have several checkout attempts. The legacy order columns keep
-- the currently presented checkout for old clients; this table is the durable
-- history and the reconciliation source for new attempts.
CREATE TABLE payment_attempts (
 id VARCHAR(32) PRIMARY KEY,
 entity_type VARCHAR(20) NOT NULL CHECK(entity_type IN ('order','topup')),
 entity_id VARCHAR(32) NOT NULL,
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 provider VARCHAR(40) NOT NULL,
 provider_payment_id VARCHAR(100),
 amount_minor BIGINT NOT NULL CHECK(amount_minor > 0),
 currency VARCHAR(3) NOT NULL,
 status VARCHAR(20) NOT NULL CHECK(status IN ('creating','pending','unknown','succeeded','failed','cancelled','expired')),
 idempotency_key VARCHAR(160) NOT NULL UNIQUE,
 checkout_url TEXT,
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL,
 completed_at BIGINT,
 last_error VARCHAR(255),
 correlation_id VARCHAR(64) NOT NULL,
 UNIQUE(provider,provider_payment_id)
);
CREATE INDEX payment_attempts_entity ON payment_attempts(entity_type,entity_id,created_at);
CREATE INDEX payment_attempts_recovery ON payment_attempts(status,updated_at);

-- Refund intent is durable even when a provider call is delayed or unavailable.
CREATE TABLE refund_requests (
 id VARCHAR(32) PRIMARY KEY,
 payment_id VARCHAR(32) NOT NULL REFERENCES payments(id),
 order_id VARCHAR(32) NOT NULL REFERENCES orders(id),
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 amount_minor BIGINT NOT NULL CHECK(amount_minor > 0),
 currency VARCHAR(3) NOT NULL,
 reason TEXT,
 status VARCHAR(30) NOT NULL CHECK(status IN ('pending','processing','unknown','succeeded','failed_needs_attention','cancelled')),
 idempotency_key VARCHAR(160) NOT NULL UNIQUE,
 provider_refund_id VARCHAR(100),
 attempts INTEGER NOT NULL DEFAULT 0,
 next_attempt_at BIGINT,
 lease_until BIGINT,
 last_error VARCHAR(255),
 correlation_id VARCHAR(64) NOT NULL,
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL,
 completed_at BIGINT,
 UNIQUE(payment_id,idempotency_key)
);
CREATE INDEX refund_requests_ready ON refund_requests(status,next_attempt_at,lease_until);
