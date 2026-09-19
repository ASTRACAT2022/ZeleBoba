-- Payment webhooks are an at-least-once inbox.  These additive columns keep
-- old rows readable during a rolling deployment and allow a worker to claim
-- an event without two consumers executing its business transition.
ALTER TABLE payment_events ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','processed','retry','dead'));
ALTER TABLE payment_events ADD COLUMN attempts INTEGER NOT NULL DEFAULT 0 CHECK(attempts >= 0);
ALTER TABLE payment_events ADD COLUMN next_attempt_at BIGINT;
ALTER TABLE payment_events ADD COLUMN locked_until BIGINT;
ALTER TABLE payment_events ADD COLUMN lock_token VARCHAR(32);
UPDATE payment_events SET status=CASE WHEN processed_at IS NULL THEN 'pending' ELSE 'processed' END, next_attempt_at=CASE WHEN processed_at IS NULL THEN received_at ELSE NULL END;
CREATE INDEX payment_events_ready ON payment_events(status,next_attempt_at,locked_until,received_at);
