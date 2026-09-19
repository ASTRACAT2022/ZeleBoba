CREATE TABLE feature_flags (
 name VARCHAR(80) PRIMARY KEY,
 enabled INTEGER NOT NULL CHECK(enabled IN (0,1)),
 rollout_percent INTEGER NOT NULL DEFAULT 100 CHECK(rollout_percent BETWEEN 0 AND 100),
 updated_by VARCHAR(32) REFERENCES users(id),
 updated_at BIGINT NOT NULL
);
INSERT INTO feature_flags(name,enabled,rollout_percent,updated_at) VALUES
 ('provisioning.enabled',1,100,0),('reconciliation.auto_heal',0,100,0),('notifications.telegram',1,100,0);
CREATE TABLE incidents (
 id VARCHAR(32) PRIMARY KEY,
 code VARCHAR(32) NOT NULL UNIQUE,
 title VARCHAR(200) NOT NULL,
 status VARCHAR(20) NOT NULL CHECK(status IN ('open','resolved')),
 created_by VARCHAR(32) REFERENCES users(id),
 created_at BIGINT NOT NULL,
 resolved_at BIGINT
);
CREATE TABLE incident_operations (incident_id VARCHAR(32) NOT NULL REFERENCES incidents(id),operation_id VARCHAR(40) NOT NULL REFERENCES operations(id),PRIMARY KEY(incident_id,operation_id));
CREATE TABLE consistency_checks (id VARCHAR(32) PRIMARY KEY,kind VARCHAR(40) NOT NULL,status VARCHAR(20) NOT NULL,details TEXT NOT NULL,checked_at BIGINT NOT NULL);
