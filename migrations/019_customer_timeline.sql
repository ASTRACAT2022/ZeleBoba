-- Immutable, customer-facing operational history for the admin profile.
CREATE TABLE customer_timeline (
 id VARCHAR(32) PRIMARY KEY,
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 event_type VARCHAR(80) NOT NULL,
 payload TEXT NOT NULL DEFAULT '{}',
 occurred_at BIGINT NOT NULL,
 recorded_at BIGINT NOT NULL
);
CREATE INDEX customer_timeline_user_recorded ON customer_timeline(user_id, recorded_at);
