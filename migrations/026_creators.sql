CREATE TABLE creators (
 id VARCHAR(32) PRIMARY KEY,
 user_id VARCHAR(32) UNIQUE REFERENCES users(id),
 name VARCHAR(120) NOT NULL,
 code VARCHAR(32) NOT NULL UNIQUE,
 status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK(status IN ('active','suspended')),
 first_percent INTEGER NOT NULL DEFAULT 10 CHECK(first_percent BETWEEN 0 AND 100),
 recurring_percent INTEGER NOT NULL DEFAULT 5 CHECK(recurring_percent BETWEEN 0 AND 100),
 recurring_days INTEGER NOT NULL DEFAULT 180 CHECK(recurring_days BETWEEN 0 AND 3650),
 hold_days INTEGER NOT NULL DEFAULT 14 CHECK(hold_days BETWEEN 0 AND 365),
 created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL
);
CREATE TABLE creator_attributions (
 id VARCHAR(32) PRIMARY KEY,
 creator_id VARCHAR(32) NOT NULL REFERENCES creators(id),
 visitor_token VARCHAR(64) NOT NULL UNIQUE,
 campaign VARCHAR(120),
 user_id VARCHAR(32) UNIQUE REFERENCES users(id),
 clicked_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, attributed_at BIGINT,
 source_ip_hash VARCHAR(64)
);
CREATE INDEX creator_attributions_creator ON creator_attributions(creator_id,clicked_at);
CREATE TABLE creator_commissions (
 id VARCHAR(32) PRIMARY KEY,
 creator_id VARCHAR(32) NOT NULL REFERENCES creators(id),
 customer_id VARCHAR(32) NOT NULL REFERENCES users(id),
 payment_id VARCHAR(32) NOT NULL UNIQUE REFERENCES payments(id),
 amount_minor BIGINT NOT NULL CHECK(amount_minor > 0),
 commission_minor BIGINT NOT NULL CHECK(commission_minor > 0),
 kind VARCHAR(20) NOT NULL CHECK(kind IN ('first','recurring')),
 status VARCHAR(20) NOT NULL CHECK(status IN ('pending','available','reversed','paid')),
 available_at BIGINT NOT NULL, created_at BIGINT NOT NULL, reversed_at BIGINT
);
CREATE INDEX creator_commissions_creator ON creator_commissions(creator_id,status,created_at);
CREATE TABLE creator_ledger (
 id VARCHAR(32) PRIMARY KEY,
 creator_id VARCHAR(32) NOT NULL REFERENCES creators(id),
 entry_type VARCHAR(30) NOT NULL CHECK(entry_type IN ('commission','milestone','payout_reserve','payout_release','reversal','manual_adjustment')),
 amount_minor BIGINT NOT NULL CHECK(amount_minor <> 0),
 commission_id VARCHAR(32) UNIQUE REFERENCES creator_commissions(id),
 payout_id VARCHAR(32), metadata TEXT NOT NULL DEFAULT '{}', created_at BIGINT NOT NULL
);
CREATE INDEX creator_ledger_creator ON creator_ledger(creator_id,created_at);
CREATE TABLE creator_milestones (id VARCHAR(32) PRIMARY KEY, threshold INTEGER NOT NULL UNIQUE, bonus_minor BIGINT NOT NULL CHECK(bonus_minor > 0), active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)));
INSERT INTO creator_milestones VALUES ('creator_m100',100,200000,1),('creator_m250',250,500000,1),('creator_m500',500,1000000,1),('creator_m1000',1000,2500000,1) ON CONFLICT(id) DO NOTHING;
CREATE TABLE creator_milestone_awards (id VARCHAR(32) PRIMARY KEY, creator_id VARCHAR(32) NOT NULL REFERENCES creators(id), milestone_id VARCHAR(32) NOT NULL REFERENCES creator_milestones(id), ledger_id VARCHAR(32) NOT NULL REFERENCES creator_ledger(id), created_at BIGINT NOT NULL, UNIQUE(creator_id,milestone_id));
CREATE TABLE creator_payouts (id VARCHAR(32) PRIMARY KEY, creator_id VARCHAR(32) NOT NULL REFERENCES creators(id), amount_minor BIGINT NOT NULL CHECK(amount_minor > 0), details TEXT NOT NULL, status VARCHAR(20) NOT NULL CHECK(status IN ('requested','processing','paid','rejected','cancelled')), requested_at BIGINT NOT NULL, processed_at BIGINT, processed_by VARCHAR(32) REFERENCES users(id), operation_id VARCHAR(160), comment TEXT);
CREATE TABLE creator_fraud_flags (id VARCHAR(32) PRIMARY KEY, creator_id VARCHAR(32) NOT NULL REFERENCES creators(id), customer_id VARCHAR(32) REFERENCES users(id), reason VARCHAR(100) NOT NULL, details TEXT NOT NULL DEFAULT '{}', created_at BIGINT NOT NULL, resolved_at BIGINT, resolved_by VARCHAR(32) REFERENCES users(id));
CREATE TABLE creator_reconciliation_runs (id VARCHAR(32) PRIMARY KEY, started_at BIGINT NOT NULL, completed_at BIGINT, missing_count INTEGER NOT NULL DEFAULT 0, inconsistent_count INTEGER NOT NULL DEFAULT 0, actor VARCHAR(100) NOT NULL);
