-- Step-level operation tracking with parent/child hierarchy, HTTP details, retries.
-- This migration is additive only; it never modifies existing data.
CREATE TABLE operation_steps (
  id VARCHAR(32) PRIMARY KEY,
  operation_id VARCHAR(40) NOT NULL REFERENCES operations(id),
  parent_step_id VARCHAR(32) REFERENCES operation_steps(id),
  name VARCHAR(255) NOT NULL,
  category VARCHAR(50) NOT NULL,
  status VARCHAR(20) NOT NULL CHECK(status IN ('pending','queued','processing','success','failed','retrying','cancelled')),
  started_at BIGINT,
  finished_at BIGINT,
  duration_ms INTEGER,
  attempt INTEGER NOT NULL DEFAULT 1,
  max_attempts INTEGER NOT NULL DEFAULT 1,
  http_method VARCHAR(10),
  http_url TEXT,
  http_status INTEGER,
  error_code VARCHAR(100),
  error_message TEXT,
  request_metadata TEXT NOT NULL DEFAULT '{}',
  response_metadata TEXT NOT NULL DEFAULT '{}',
  occurred_at BIGINT NOT NULL,
  created_at BIGINT NOT NULL
);
CREATE INDEX op_steps_operation ON operation_steps(operation_id, occurred_at);
CREATE INDEX op_steps_parent ON operation_steps(parent_step_id);
CREATE INDEX op_steps_operation_status ON operation_steps(operation_id, status);
CREATE INDEX op_steps_http_status ON operation_steps(http_status) WHERE http_status IS NOT NULL;

-- Operation-level retry tracking.
CREATE TABLE operation_retries (
  id VARCHAR(32) PRIMARY KEY,
  operation_id VARCHAR(40) NOT NULL REFERENCES operations(id),
  step_id VARCHAR(32) REFERENCES operation_steps(id),
  attempt INTEGER NOT NULL,
  http_status INTEGER,
  duration_ms INTEGER,
  error_code VARCHAR(100),
  error_message TEXT,
  started_at BIGINT NOT NULL,
  finished_at BIGINT,
  metadata TEXT NOT NULL DEFAULT '{}',
  created_at BIGINT NOT NULL
);
CREATE INDEX op_retries_operation ON operation_retries(operation_id, attempt);
