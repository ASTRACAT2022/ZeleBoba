-- Durable business-operation history. It is deliberately independent from
-- OpenTelemetry/Sentry: losing either external system cannot lose this trail.
CREATE TABLE operations (
 id VARCHAR(40) PRIMARY KEY,
 correlation_id VARCHAR(48) NOT NULL UNIQUE,
 trace_id VARCHAR(64) NOT NULL,
 type VARCHAR(50) NOT NULL,
 status VARCHAR(20) NOT NULL CHECK(status IN ('processing','success','warning','failed')),
 user_id VARCHAR(32) REFERENCES users(id),
 subscription_id VARCHAR(32) REFERENCES subscriptions(id),
 order_id VARCHAR(32) REFERENCES orders(id),
 payment_id VARCHAR(32) REFERENCES payments(id),
 started_at BIGINT NOT NULL,
 completed_at BIGINT,
 last_error VARCHAR(255),
 metadata TEXT NOT NULL DEFAULT '{}'
);
CREATE INDEX operations_started ON operations(started_at);
CREATE INDEX operations_user ON operations(user_id,started_at);
CREATE INDEX operations_order ON operations(order_id,started_at);
CREATE INDEX operations_subscription ON operations(subscription_id,started_at);
CREATE INDEX operations_payment ON operations(payment_id,started_at);

CREATE TABLE operation_events (
 id VARCHAR(32) PRIMARY KEY,
 operation_id VARCHAR(40) NOT NULL REFERENCES operations(id),
 correlation_id VARCHAR(48) NOT NULL,
 trace_id VARCHAR(64) NOT NULL,
 span_id VARCHAR(32),
 user_id VARCHAR(32) REFERENCES users(id),
 subscription_id VARCHAR(32) REFERENCES subscriptions(id),
 order_id VARCHAR(32) REFERENCES orders(id),
 payment_id VARCHAR(32) REFERENCES payments(id),
 type VARCHAR(80) NOT NULL,
 status VARCHAR(20) NOT NULL CHECK(status IN ('success','processing','warning','failed')),
 message VARCHAR(255) NOT NULL,
 metadata TEXT NOT NULL DEFAULT '{}',
 occurred_at BIGINT NOT NULL,
 created_at BIGINT NOT NULL
);
CREATE INDEX operation_events_operation ON operation_events(operation_id,occurred_at);
CREATE INDEX operation_events_correlation ON operation_events(correlation_id,occurred_at);
CREATE INDEX operation_events_user ON operation_events(user_id,occurred_at);
