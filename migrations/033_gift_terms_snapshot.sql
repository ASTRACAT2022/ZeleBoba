-- A gift is a purchased product. Its entitlement must remain independent of
-- future edits or deactivation of the referenced plan.
ALTER TABLE guest_purchases ADD COLUMN IF NOT EXISTS traffic_bytes BIGINT NOT NULL DEFAULT 0;
ALTER TABLE guest_purchases ADD COLUMN IF NOT EXISTS device_limit INTEGER NOT NULL DEFAULT 1;

-- Existing unclaimed gifts receive a snapshot during upgrade.  Subsequent
-- claims never read mutable plan limits again.
UPDATE guest_purchases
SET traffic_bytes = COALESCE((SELECT traffic_bytes FROM plans WHERE plans.id = guest_purchases.plan_id), 0),
    device_limit = COALESCE((SELECT devices FROM plans WHERE plans.id = guest_purchases.plan_id), 1)
WHERE is_gift = 1 AND traffic_bytes = 0 AND device_limit = 1;
