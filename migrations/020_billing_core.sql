-- Billing core v2.  Legacy columns remain during the rolling migration so old
-- web and bot clients can continue to read their snapshots.
CREATE TABLE user_identities (
 id VARCHAR(32) PRIMARY KEY,
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 type VARCHAR(20) NOT NULL CHECK(type IN ('telegram','email')),
 external_id VARCHAR(254) NOT NULL,
 verified_at BIGINT,
 created_at BIGINT NOT NULL,
 UNIQUE(type, external_id)
);
CREATE INDEX user_identities_user ON user_identities(user_id);

-- Backfill the immutable Telegram integration id and verified email identity.
INSERT INTO user_identities(id,user_id,type,external_id,verified_at,created_at)
SELECT substr(id,1,30) || 'tg', id, 'telegram', telegram_id, created_at, created_at
FROM users WHERE telegram_id IS NOT NULL AND telegram_id <> ''
ON CONFLICT(type,external_id) DO NOTHING;
INSERT INTO user_identities(id,user_id,type,external_id,verified_at,created_at)
SELECT substr(id,1,30) || 'em', id, 'email', lower(email), created_at, created_at
FROM users WHERE email IS NOT NULL AND email <> ''
ON CONFLICT(type,external_id) DO NOTHING;

ALTER TABLE plans ADD COLUMN duration_months INTEGER NOT NULL DEFAULT 0 CHECK(duration_months BETWEEN 0 AND 120);
ALTER TABLE subscriptions ADD COLUMN lifecycle_status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK(lifecycle_status IN ('pending','active','grace','suspended','cancelled','expired'));
ALTER TABLE subscriptions ADD COLUMN starts_at BIGINT;
ALTER TABLE subscriptions ADD COLUMN traffic_limit_bytes BIGINT;
ALTER TABLE subscriptions ADD COLUMN version INTEGER NOT NULL DEFAULT 0;
UPDATE subscriptions SET plan_id=(SELECT plan_id FROM orders WHERE orders.id=subscriptions.order_id), starts_at=created_at, updated_at=created_at, traffic_limit_bytes=traffic_limit_gb*1073741824, lifecycle_status=CASE WHEN status='expired' THEN 'expired' WHEN status='active' THEN 'active' ELSE 'pending' END;

ALTER TABLE orders ADD COLUMN workflow_status VARCHAR(30) NOT NULL DEFAULT 'pending_payment' CHECK(workflow_status IN ('draft','pending_payment','paid','fulfilled','cancelled','expired'));
ALTER TABLE orders ADD COLUMN expires_at BIGINT;
UPDATE orders SET workflow_status=CASE status WHEN 'pending' THEN 'pending_payment' WHEN 'paid' THEN 'paid' WHEN 'fulfilled' THEN 'fulfilled' ELSE 'cancelled' END;
CREATE TABLE order_items (
 id VARCHAR(32) PRIMARY KEY,
 order_id VARCHAR(32) NOT NULL REFERENCES orders(id),
 product_type VARCHAR(30) NOT NULL,
 plan_id VARCHAR(32) REFERENCES plans(id),
 subscription_id VARCHAR(32) REFERENCES subscriptions(id),
 quantity INTEGER NOT NULL DEFAULT 1 CHECK(quantity > 0),
 unit_price_minor BIGINT NOT NULL CHECK(unit_price_minor >= 0),
 total_minor BIGINT NOT NULL CHECK(total_minor >= 0),
 metadata TEXT NOT NULL DEFAULT '{}',
 created_at BIGINT NOT NULL
);
CREATE INDEX order_items_order ON order_items(order_id);
INSERT INTO order_items(id,order_id,product_type,plan_id,quantity,unit_price_minor,total_minor,metadata,created_at)
SELECT id,id,'subscription',plan_id,1,price_minor,price_minor,'{}',created_at FROM orders;

CREATE TABLE payments (
 id VARCHAR(32) PRIMARY KEY,
 order_id VARCHAR(32) NOT NULL REFERENCES orders(id),
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 provider VARCHAR(30) NOT NULL,
 provider_payment_id VARCHAR(100) NOT NULL,
 amount_minor BIGINT NOT NULL CHECK(amount_minor > 0),
 currency VARCHAR(3) NOT NULL,
 status VARCHAR(30) NOT NULL CHECK(status IN ('pending','succeeded','failed','cancelled','partially_refunded','refunded')),
 created_at BIGINT NOT NULL,
 paid_at BIGINT,
 UNIQUE(provider,provider_payment_id)
);
CREATE INDEX payments_order ON payments(order_id);
INSERT INTO payments(id,order_id,user_id,provider,provider_payment_id,amount_minor,currency,status,created_at,paid_at)
SELECT substr(o.id,1,30) || 'pm',o.id,o.user_id,r.provider,r.payment_id,r.amount_minor,r.currency,'succeeded',r.created_at,o.paid_at
FROM payment_receipts r JOIN orders o ON o.id=r.order_id
ON CONFLICT(provider,provider_payment_id) DO NOTHING;

CREATE TABLE payment_events (
 id VARCHAR(32) PRIMARY KEY,
 provider VARCHAR(30) NOT NULL,
 provider_event_id VARCHAR(160) NOT NULL,
 payment_id VARCHAR(100),
 payload TEXT NOT NULL,
 signature_valid INTEGER NOT NULL CHECK(signature_valid IN (0,1)),
 received_at BIGINT NOT NULL,
 processed_at BIGINT,
 processing_error VARCHAR(255),
 UNIQUE(provider,provider_event_id)
);
CREATE INDEX payment_events_pending ON payment_events(processed_at,received_at);

CREATE TABLE provisioning_accounts (
 id VARCHAR(32) PRIMARY KEY,
 subscription_id VARCHAR(32) NOT NULL REFERENCES subscriptions(id),
 provider VARCHAR(30) NOT NULL,
 external_user_id VARCHAR(100),
 state VARCHAR(20) NOT NULL CHECK(state IN ('pending','processing','active','retry','failed')),
 last_synced_at BIGINT,
 last_error VARCHAR(255),
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL,
 UNIQUE(subscription_id,provider)
);
CREATE INDEX provisioning_accounts_sync ON provisioning_accounts(state,updated_at);
INSERT INTO provisioning_accounts(id,subscription_id,provider,external_user_id,state,last_synced_at,created_at,updated_at)
SELECT substr(s.id,1,30) || 'pa',s.id,COALESCE(o.provision_driver,'demo'),s.remote_id,
       CASE WHEN s.remote_id IS NULL THEN 'pending' ELSE 'active' END,
       CASE WHEN s.remote_id IS NULL THEN NULL ELSE s.updated_at END,s.created_at,COALESCE(s.updated_at,s.created_at)
FROM subscriptions s LEFT JOIN orders o ON o.id=s.order_id
ON CONFLICT(subscription_id,provider) DO NOTHING;

ALTER TABLE outbox ADD COLUMN correlation_id VARCHAR(64);
-- Kept separately to preserve compatibility with the legacy five-column log.
CREATE TABLE audit_context (
 audit_id VARCHAR(32) PRIMARY KEY REFERENCES audit_log(id),
 before_json TEXT,
 after_json TEXT,
 reason TEXT,
 correlation_id VARCHAR(64)
);
