ALTER TABLE compensations ADD COLUMN plan_id VARCHAR(32) REFERENCES plans(id);
ALTER TABLE compensations ADD COLUMN plan_traffic_gb BIGINT;
ALTER TABLE compensations ADD COLUMN plan_devices INTEGER;
ALTER TABLE compensations ADD COLUMN queued_count INTEGER NOT NULL DEFAULT 0;
ALTER TABLE compensations ADD COLUMN last_queued_user_id VARCHAR(32) NOT NULL DEFAULT '';
ALTER TABLE compensations ADD COLUMN skipped_count INTEGER NOT NULL DEFAULT 0;
ALTER TABLE compensations ADD COLUMN failed_count INTEGER NOT NULL DEFAULT 0;
ALTER TABLE compensations ADD COLUMN completed_at BIGINT;
ALTER TABLE compensations ADD COLUMN queue_error INTEGER NOT NULL DEFAULT 0;

CREATE TABLE compensation_targets (
 compensation_id VARCHAR(32) NOT NULL REFERENCES compensations(id),
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','applied','skipped','failed')),
 detail VARCHAR(100),
 completed_at BIGINT,
 PRIMARY KEY(compensation_id,user_id)
);
CREATE INDEX compensation_targets_status ON compensation_targets(compensation_id,status);

-- Preserve receipts from jobs started before this migration so a replay
-- cannot issue the same compensation a second time.
INSERT INTO compensation_targets(compensation_id,user_id,status,completed_at)
SELECT compensation_id,user_id,'applied',created_at FROM compensation_grants WHERE 1=1
ON CONFLICT(compensation_id,user_id) DO NOTHING;
