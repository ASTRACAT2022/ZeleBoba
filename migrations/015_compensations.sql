CREATE TABLE compensations (
 id VARCHAR(32) PRIMARY KEY,
 segment VARCHAR(20) NOT NULL,
 kind VARCHAR(20) NOT NULL CHECK(kind IN ('balance','days','traffic')),
 value BIGINT NOT NULL CHECK(value > 0),
 reason VARCHAR(200) NOT NULL,
 total_count INTEGER NOT NULL DEFAULT 0,
 processed_count INTEGER NOT NULL DEFAULT 0,
 status VARCHAR(20) NOT NULL DEFAULT 'in_progress' CHECK(status IN ('in_progress','running','completed')),
 admin_id VARCHAR(32) REFERENCES users(id),
 admin_name VARCHAR(255),
 created_at BIGINT NOT NULL
);
CREATE INDEX compensations_created ON compensations(created_at);

CREATE TABLE compensation_grants (
 id VARCHAR(32) PRIMARY KEY,
 compensation_id VARCHAR(32) NOT NULL REFERENCES compensations(id),
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 created_at BIGINT NOT NULL,
 UNIQUE(compensation_id, user_id)
);
CREATE INDEX compensation_grants_user ON compensation_grants(user_id);
