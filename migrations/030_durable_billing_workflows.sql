-- Durable state for fulfillment.  These tables are additive: legacy orders,
-- subscriptions and outbox rows remain valid during the rolling migration.
CREATE TABLE workflows (
 id VARCHAR(32) PRIMARY KEY,
 workflow_type VARCHAR(40) NOT NULL,
 entity_type VARCHAR(40) NOT NULL,
 entity_id VARCHAR(32) NOT NULL,
 state VARCHAR(30) NOT NULL CHECK(state IN ('payment_confirmed','fulfillment_required','provisioning','verifying','completed','failed_needs_attention','cancelled')),
 desired_state TEXT NOT NULL DEFAULT '{}',
 attempts INTEGER NOT NULL DEFAULT 0 CHECK(attempts >= 0),
 max_attempts INTEGER NOT NULL DEFAULT 8 CHECK(max_attempts > 0),
 next_attempt_at BIGINT,
 leased_by VARCHAR(80),
 lease_until BIGINT,
 last_error VARCHAR(255),
 correlation_id VARCHAR(64) NOT NULL,
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL,
 completed_at BIGINT,
 UNIQUE(workflow_type,entity_type,entity_id),
 UNIQUE(correlation_id)
);
CREATE INDEX workflows_ready ON workflows(state,next_attempt_at,lease_until,updated_at);

CREATE TABLE provisioning_operations (
 id VARCHAR(32) PRIMARY KEY,
 subscription_id VARCHAR(32) NOT NULL REFERENCES subscriptions(id),
 order_id VARCHAR(32) REFERENCES orders(id),
 operation_type VARCHAR(30) NOT NULL CHECK(operation_type IN ('activate','extend','sync')),
 status VARCHAR(30) NOT NULL CHECK(status IN ('pending','running','verifying','succeeded','retry','unknown','failed_needs_attention','cancelled')),
 desired_state TEXT NOT NULL DEFAULT '{}',
 actual_state TEXT,
 idempotency_key VARCHAR(160) NOT NULL UNIQUE,
 external_operation_id VARCHAR(100),
 attempts INTEGER NOT NULL DEFAULT 0 CHECK(attempts >= 0),
 max_attempts INTEGER NOT NULL DEFAULT 8 CHECK(max_attempts > 0),
 next_attempt_at BIGINT,
 leased_by VARCHAR(80),
 lease_until BIGINT,
 started_at BIGINT,
 completed_at BIGINT,
 last_error VARCHAR(255),
 correlation_id VARCHAR(64) NOT NULL,
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL,
 UNIQUE(subscription_id,operation_type,order_id)
);
CREATE INDEX provisioning_operations_ready ON provisioning_operations(status,next_attempt_at,lease_until,updated_at);

-- Raw deliveries are retained separately from the normalized legacy payment
-- event. This makes headers/payload available to audits without trusting them.
CREATE TABLE incoming_webhooks (
 id VARCHAR(32) PRIMARY KEY,
 provider VARCHAR(40) NOT NULL,
 provider_event_id VARCHAR(160) NOT NULL,
 headers TEXT NOT NULL DEFAULT '{}',
 payload TEXT NOT NULL,
 received_at BIGINT NOT NULL,
 processed_at BIGINT,
 status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','processed','retry','dead')),
 attempts INTEGER NOT NULL DEFAULT 0,
 last_error VARCHAR(255),
 correlation_id VARCHAR(64) NOT NULL,
 UNIQUE(provider,provider_event_id)
);
CREATE INDEX incoming_webhooks_pending ON incoming_webhooks(status,received_at);

CREATE TABLE audit_events (
 id VARCHAR(32) PRIMARY KEY,
 entity_type VARCHAR(40) NOT NULL,
 entity_id VARCHAR(32) NOT NULL,
 event_type VARCHAR(80) NOT NULL,
 old_state VARCHAR(40),
 new_state VARCHAR(40),
 actor_type VARCHAR(40) NOT NULL,
 actor_id VARCHAR(100),
 reason TEXT,
 metadata TEXT NOT NULL DEFAULT '{}',
 correlation_id VARCHAR(64),
 created_at BIGINT NOT NULL
);
CREATE INDEX audit_events_entity ON audit_events(entity_type,entity_id,created_at);
