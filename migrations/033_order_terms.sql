-- Persist all purchased time terms and the renewal target in the order.
ALTER TABLE orders ADD COLUMN duration_months INTEGER NOT NULL DEFAULT 0 CHECK(duration_months BETWEEN 0 AND 120);
ALTER TABLE orders ADD COLUMN renewal_subscription_id VARCHAR(32) REFERENCES subscriptions(id);
UPDATE orders SET duration_months=COALESCE(
 (SELECT duration_months FROM plan_versions WHERE plan_versions.id=orders.plan_version_id),
 (SELECT duration_months FROM plans WHERE plans.id=orders.plan_id),0);
UPDATE orders SET renewal_subscription_id=(SELECT id FROM subscriptions WHERE subscriptions.renew_order_id=orders.id);
CREATE INDEX orders_renewal_subscription ON orders(renewal_subscription_id);
