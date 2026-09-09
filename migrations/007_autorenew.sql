ALTER TABLE subscriptions ADD COLUMN auto_renew INTEGER NOT NULL DEFAULT 0;
ALTER TABLE subscriptions ADD COLUMN renew_plan_id VARCHAR(32);
ALTER TABLE subscriptions ADD COLUMN renew_price_minor BIGINT;
ALTER TABLE subscriptions ADD COLUMN renew_at BIGINT;
ALTER TABLE subscriptions ADD COLUMN renew_order_id VARCHAR(32);
ALTER TABLE subscriptions ADD COLUMN renew_failed_at BIGINT;
ALTER TABLE subscriptions ADD COLUMN renew_fail_count INTEGER NOT NULL DEFAULT 0;
CREATE INDEX subscriptions_renew ON subscriptions(auto_renew, renew_at);
