-- 029_protection_framework.sql
-- Stage 1 hardening: kill switches / safe mode, optimistic-lock CAS columns,
-- rate-limit counters, webhook replay/dedup, circuit-breaker state.
-- (PostgreSQL; applied manually as billing_owner.)

-- 1) Optimistic-lock version columns on critical entities (CAS guard).
ALTER TABLE orders         ADD COLUMN IF NOT EXISTS version BIGINT NOT NULL DEFAULT 1;
ALTER TABLE subscriptions  ADD COLUMN IF NOT EXISTS version BIGINT NOT NULL DEFAULT 1;
ALTER TABLE topups         ADD COLUMN IF NOT EXISTS version BIGINT NOT NULL DEFAULT 1;
ALTER TABLE transactions   ADD COLUMN IF NOT EXISTS version BIGINT NOT NULL DEFAULT 1;
ALTER TABLE payments       ADD COLUMN IF NOT EXISTS version BIGINT NOT NULL DEFAULT 1;
ALTER TABLE withdrawal_requests ADD COLUMN IF NOT EXISTS version BIGINT NOT NULL DEFAULT 1;

-- 2) Kill switches / safe mode (global + per-provider + financial freeze).
CREATE TABLE IF NOT EXISTS kill_switches (
    name      VARCHAR(100) PRIMARY KEY,
    enabled   SMALLINT NOT NULL DEFAULT 0,
    actor     VARCHAR(64) NOT NULL,
    reason    TEXT NOT NULL DEFAULT '',
    created_at BIGINT NOT NULL
);
INSERT INTO kill_switches(name,enabled,actor,reason,created_at) VALUES
  ('global_purchases',0,'system','Stage1 init',extract(epoch from now())::bigint),
  ('freekassa',0,'system','Stage1 init',extract(epoch from now())::bigint),
  ('yookassa',0,'system','Stage1 init',extract(epoch from now())::bigint),
  ('remnawave_provision',0,'system','Stage1 init',extract(epoch from now())::bigint),
  ('financial_freeze',0,'system','Stage1 init',extract(epoch from now())::bigint)
ON CONFLICT(name) DO NOTHING;

-- Safe-mode row in app_settings is created/owned by code (SafeMode service).

-- 3) Rate-limit counters (token bucket per key, sliding window).
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket    VARCHAR(160) PRIMARY KEY,
    limit_hits BIGINT NOT NULL,
    window_start BIGINT NOT NULL,
    window_end   BIGINT NOT NULL
);

-- 4) Webhook replay protection + duplicate detector (per provider+event id).
CREATE TABLE IF NOT EXISTS webhook_events (
    id          VARCHAR(64) PRIMARY KEY,     -- provider event id
    provider    VARCHAR(40) NOT NULL,
    payload_sha256 VARCHAR(64) NOT NULL,
    processed   SMALLINT NOT NULL DEFAULT 0,
    created_at  BIGINT NOT NULL,
    processed_at BIGINT,
    UNIQUE(provider, id)
);

-- 5) Circuit-breaker state per upstream (freekassa_api, remnawave_api, ...).
CREATE TABLE IF NOT EXISTS circuit_breakers (
    name      VARCHAR(80) PRIMARY KEY,
    state     VARCHAR(20) NOT NULL DEFAULT 'closed',  -- closed | open | half_open
    failures  BIGINT NOT NULL DEFAULT 0,
    opened_at BIGINT,
    last_success BIGINT,
    last_failure  BIGINT,
    updated_at BIGINT NOT NULL
);
INSERT INTO circuit_breakers(name,state,failures,opened_at,last_success,last_failure,updated_at) VALUES
  ('freekassa_api','closed',0,NULL,NULL,NULL,0),
  ('remnawave_api','closed',0,NULL,NULL,NULL,0)
ON CONFLICT(name) DO NOTHING;

-- 6) Stuck-job / watchdog heartbeat registry.
CREATE TABLE IF NOT EXISTS worker_heartbeats (
    worker     VARCHAR(80) PRIMARY KEY,
    last_beat  BIGINT NOT NULL,
    job        VARCHAR(160) NOT NULL DEFAULT '',
    updated_at BIGINT NOT NULL
);

-- 7) Four-eyes (admin approval) queue for sensitive ops.
CREATE TABLE IF NOT EXISTS approval_queue (
    id          VARCHAR(32) PRIMARY KEY,
    action      VARCHAR(100) NOT NULL,
    actor       VARCHAR(64) NOT NULL,
    payload     TEXT NOT NULL DEFAULT '{}',
    status      VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending|approved|rejected|expired
    requested_at BIGINT NOT NULL,
    decided_at   BIGINT,
    decided_by   VARCHAR(64),
    UNIQUE(action, actor)
);

-- Grants: billing role can use these tables.
GRANT ALL ON ALL TABLES IN SCHEMA public TO billing;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO billing;
