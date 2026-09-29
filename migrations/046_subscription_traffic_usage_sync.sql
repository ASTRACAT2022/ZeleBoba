-- Cache the last read of Remnawave traffic separately from billing limits.
ALTER TABLE subscriptions ADD COLUMN traffic_usage_checked_at BIGINT;
ALTER TABLE subscriptions ADD COLUMN traffic_usage_synced_at BIGINT;
CREATE INDEX subscriptions_traffic_usage_due
    ON subscriptions(traffic_usage_checked_at, status, expires_at);
