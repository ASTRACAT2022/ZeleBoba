ALTER TABLE subscriptions ADD COLUMN sync_status VARCHAR(20) NOT NULL DEFAULT 'pending'
    CHECK(sync_status IN ('synced','pending','error'));
ALTER TABLE subscriptions ADD COLUMN sync_error VARCHAR(255);
ALTER TABLE subscriptions ADD COLUMN synced_at BIGINT;
CREATE INDEX subscriptions_sync_pending ON subscriptions(sync_status,updated_at)
    WHERE sync_status IN ('pending','error');
