ALTER TABLE users ADD COLUMN balance_kopeks BIGINT NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN has_made_first_topup INTEGER NOT NULL DEFAULT 0 CHECK(has_made_first_topup IN (0,1));

CREATE TABLE transactions (
 id VARCHAR(32) PRIMARY KEY,
 seq INTEGER NOT NULL,
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 type VARCHAR(50) NOT NULL,
 amount_kopeks BIGINT NOT NULL CHECK(amount_kopeks <> 0),
 description TEXT,
 payment_method VARCHAR(50),
 external_id VARCHAR(100),
 is_completed INTEGER NOT NULL DEFAULT 1 CHECK(is_completed IN (0,1)),
 created_at BIGINT NOT NULL,
 completed_at BIGINT
);
CREATE INDEX transactions_user_created ON transactions(user_id, created_at);
CREATE INDEX transactions_type_created ON transactions(type, created_at);

CREATE TABLE topups (
 id VARCHAR(32) PRIMARY KEY,
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 amount_kopeks BIGINT NOT NULL CHECK(amount_kopeks > 0),
 currency VARCHAR(3) NOT NULL CHECK(currency = 'RUB'),
 status VARCHAR(20) NOT NULL CHECK(status IN ('pending','paid','canceled')),
 provider VARCHAR(30) NOT NULL,
 provider_payment_id VARCHAR(100) UNIQUE,
 checkout_url TEXT,
 idempotency_key VARCHAR(128) NOT NULL,
 created_at BIGINT NOT NULL,
 paid_at BIGINT,
 UNIQUE(user_id, idempotency_key)
);
CREATE INDEX topups_user_created ON topups(user_id, created_at);

CREATE TABLE carts (
 user_id VARCHAR(32) PRIMARY KEY REFERENCES users(id),
 data TEXT NOT NULL,
 intent INTEGER NOT NULL DEFAULT 0 CHECK(intent IN (0,1)),
 updated_at BIGINT NOT NULL
);
