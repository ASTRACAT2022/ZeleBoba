-- Durable operations intelligence.  This migration only adds append-only or
-- reference data; it never rewrites financial or subscription history.
CREATE TABLE plan_versions (
 id VARCHAR(32) PRIMARY KEY,
 plan_id VARCHAR(32) NOT NULL REFERENCES plans(id),
 version_number INTEGER NOT NULL,
 name VARCHAR(100) NOT NULL,
 price_minor BIGINT NOT NULL,
 currency VARCHAR(3) NOT NULL,
 duration_days INTEGER NOT NULL,
 duration_months INTEGER NOT NULL DEFAULT 0,
 entitlements_json TEXT NOT NULL DEFAULT '{}',
 created_at BIGINT NOT NULL,
 retired_at BIGINT,
 UNIQUE(plan_id,version_number)
);
INSERT INTO plan_versions(id,plan_id,version_number,name,price_minor,currency,duration_days,duration_months,entitlements_json,created_at)
SELECT substr(id,1,30) || 'pv',id,1,name,price_minor,currency,duration_days,duration_months,
       '{"vpn_access":true,"traffic_bytes":' || traffic_bytes || ',"devices":' || devices || '}',0
FROM plans;
ALTER TABLE subscriptions ADD COLUMN plan_version_id VARCHAR(32);
ALTER TABLE orders ADD COLUMN plan_version_id VARCHAR(32);
UPDATE subscriptions SET plan_version_id='pv' || plan_id WHERE plan_id IS NOT NULL;
UPDATE orders SET plan_version_id='pv' || plan_id WHERE plan_id IS NOT NULL;
CREATE INDEX plan_versions_plan ON plan_versions(plan_id,version_number);

CREATE TABLE service_maintenance_windows (
 id VARCHAR(32) PRIMARY KEY,
 service VARCHAR(40) NOT NULL,
 starts_at BIGINT NOT NULL,
 ends_at BIGINT NOT NULL,
 note VARCHAR(300) NOT NULL,
 created_by VARCHAR(32) REFERENCES users(id),
 created_at BIGINT NOT NULL,
 CHECK(ends_at > starts_at)
);
CREATE INDEX maintenance_active ON service_maintenance_windows(service,starts_at,ends_at);

CREATE TABLE operational_cases (
 id VARCHAR(32) PRIMARY KEY,
 code VARCHAR(32) NOT NULL UNIQUE,
 severity VARCHAR(12) NOT NULL CHECK(severity IN ('low','medium','high','critical')),
 status VARCHAR(16) NOT NULL CHECK(status IN ('open','resolved')),
 title VARCHAR(200) NOT NULL,
 user_id VARCHAR(32) REFERENCES users(id),
 payment_id VARCHAR(32),
 subscription_id VARCHAR(32),
 details TEXT NOT NULL,
 created_at BIGINT NOT NULL,
 resolved_at BIGINT
);
CREATE INDEX operational_cases_open ON operational_cases(status,severity,created_at);

CREATE TABLE canary_runs (
 id VARCHAR(32) PRIMARY KEY,
 user_id VARCHAR(32) REFERENCES users(id),
 status VARCHAR(16) NOT NULL CHECK(status IN ('passed','failed','running')),
 stage VARCHAR(40) NOT NULL,
 details TEXT NOT NULL,
 release VARCHAR(80),
 created_at BIGINT NOT NULL,
 completed_at BIGINT
);
CREATE TABLE migration_runs (
 id VARCHAR(32) PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 status VARCHAR(16) NOT NULL CHECK(status IN ('draft','dry_run','running','completed','failed')),
 source_name VARCHAR(80) NOT NULL,
 totals_json TEXT NOT NULL,
 drift_count INTEGER NOT NULL DEFAULT 0,
 rollback_plan TEXT NOT NULL,
 created_by VARCHAR(32) REFERENCES users(id),
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL
);
