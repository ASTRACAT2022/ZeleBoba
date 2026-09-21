-- [PG]
-- 036_subscriptions_traffic_used_default.sql
-- subscriptions.traffic_used_gb is NOT NULL but lost its DEFAULT (009 had
-- DEFAULT 0). Every INSERT that doesn't explicitly pass the column then fails
-- with SQLSTATE 23502, which dead-lettered payment.verify (settle creating a
-- new subscription) and blocked autorenew/daily-extension paths that rely on
-- the settle INSERT. Restore the default so those inserts work again.
ALTER TABLE subscriptions ALTER COLUMN traffic_used_gb SET DEFAULT 0;
-- [/PG]
