CREATE TABLE users (
 id VARCHAR(32) PRIMARY KEY, email VARCHAR(254) UNIQUE, password_hash VARCHAR(255),
 telegram_id VARCHAR(30) UNIQUE, role VARCHAR(20) NOT NULL DEFAULT 'customer' CHECK(role IN ('customer','admin')),
 created_at BIGINT NOT NULL
);
CREATE TABLE plans (
 id VARCHAR(32) PRIMARY KEY, name VARCHAR(100) NOT NULL, price_minor BIGINT NOT NULL CHECK(price_minor > 0),
 currency VARCHAR(3) NOT NULL CHECK(currency = 'RUB'), duration_days INTEGER NOT NULL CHECK(duration_days BETWEEN 1 AND 3650),
 traffic_bytes BIGINT NOT NULL CHECK(traffic_bytes >= 0), devices INTEGER NOT NULL CHECK(devices BETWEEN 1 AND 20),
 active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1))
);
CREATE TABLE orders (
 id VARCHAR(32) PRIMARY KEY, user_id VARCHAR(32) NOT NULL REFERENCES users(id), plan_id VARCHAR(32) NOT NULL REFERENCES plans(id),
 idempotency_key VARCHAR(128) NOT NULL, price_minor BIGINT NOT NULL CHECK(price_minor > 0), currency VARCHAR(3) NOT NULL,
 plan_name VARCHAR(100) NOT NULL, duration_days INTEGER NOT NULL, traffic_bytes BIGINT NOT NULL, devices INTEGER NOT NULL,
 status VARCHAR(20) NOT NULL CHECK(status IN ('pending','paid','fulfilled','canceled')),
 provider VARCHAR(30) NOT NULL, provider_payment_id VARCHAR(100) UNIQUE, checkout_url TEXT,
 created_at BIGINT NOT NULL, paid_at BIGINT, UNIQUE(user_id, idempotency_key)
);
CREATE INDEX orders_user_created ON orders(user_id, created_at);
CREATE TABLE payment_receipts (
 provider VARCHAR(30) NOT NULL, payment_id VARCHAR(100) NOT NULL,
 order_id VARCHAR(32) NOT NULL UNIQUE REFERENCES orders(id), amount_minor BIGINT NOT NULL CHECK(amount_minor > 0),
 currency VARCHAR(3) NOT NULL, created_at BIGINT NOT NULL, PRIMARY KEY(provider, payment_id)
);
CREATE TABLE ledger_entries (
 id VARCHAR(32) PRIMARY KEY, order_id VARCHAR(32) NOT NULL REFERENCES orders(id),
 account VARCHAR(30) NOT NULL CHECK(account IN ('provider_clearing','subscription_sales')),
 amount_minor BIGINT NOT NULL CHECK(amount_minor <> 0), currency VARCHAR(3) NOT NULL, created_at BIGINT NOT NULL,
 UNIQUE(order_id, account)
);
CREATE TABLE subscriptions (
 id VARCHAR(32) PRIMARY KEY, order_id VARCHAR(32) NOT NULL UNIQUE REFERENCES orders(id), user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 status VARCHAR(20) NOT NULL CHECK(status IN ('provisioning','active','expired')),
 expires_at BIGINT NOT NULL, remote_id VARCHAR(100), subscription_url TEXT, created_at BIGINT NOT NULL
);
CREATE INDEX subscriptions_user ON subscriptions(user_id, created_at);
CREATE TABLE outbox (
 id VARCHAR(32) PRIMARY KEY, topic VARCHAR(50) NOT NULL, dedup_key VARCHAR(150) NOT NULL UNIQUE, payload TEXT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','done','dead')),
 attempts INTEGER NOT NULL DEFAULT 0, available_at BIGINT NOT NULL, locked_until BIGINT, lock_token VARCHAR(32),
 last_error VARCHAR(255), created_at BIGINT NOT NULL
);
CREATE INDEX outbox_ready ON outbox(status, available_at, locked_until);
CREATE TABLE audit_log (
 id VARCHAR(32) PRIMARY KEY, actor VARCHAR(100) NOT NULL, action VARCHAR(100) NOT NULL,
 subject VARCHAR(100) NOT NULL, created_at BIGINT NOT NULL
);
CREATE TABLE telegram_updates (update_id BIGINT PRIMARY KEY, created_at BIGINT NOT NULL);
CREATE TABLE telegram_links (token_hash VARCHAR(64) PRIMARY KEY, user_id VARCHAR(32) NOT NULL REFERENCES users(id), expires_at BIGINT NOT NULL);
CREATE TABLE sessions (id VARCHAR(64) PRIMARY KEY, user_id VARCHAR(32) NOT NULL REFERENCES users(id), csrf VARCHAR(64) NOT NULL, expires_at BIGINT NOT NULL);
CREATE INDEX sessions_expiry ON sessions(expires_at);
CREATE TABLE rate_limits (bucket VARCHAR(64) PRIMARY KEY, hits INTEGER NOT NULL, expires_at BIGINT NOT NULL);
