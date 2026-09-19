CREATE TABLE investigations (
 id VARCHAR(32) PRIMARY KEY,
 code VARCHAR(32) NOT NULL UNIQUE,
 status VARCHAR(16) NOT NULL CHECK(status IN ('open','resolved')),
 subject_type VARCHAR(24) NOT NULL CHECK(subject_type IN ('user','subscription','payment','incident')),
 subject_id VARCHAR(64) NOT NULL,
 title VARCHAR(200) NOT NULL,
 created_by VARCHAR(32) REFERENCES users(id),
 created_at BIGINT NOT NULL,
 resolved_at BIGINT
);
CREATE INDEX investigations_subject ON investigations(subject_type,subject_id,status);
CREATE TABLE investigation_notes (
 id VARCHAR(32) PRIMARY KEY,
 investigation_id VARCHAR(32) NOT NULL REFERENCES investigations(id),
 author_id VARCHAR(32) REFERENCES users(id),
 body VARCHAR(2000) NOT NULL,
 created_at BIGINT NOT NULL
);
CREATE INDEX investigation_notes_case ON investigation_notes(investigation_id,created_at);
CREATE TABLE saved_views (
 id VARCHAR(32) PRIMARY KEY,
 owner_id VARCHAR(32) REFERENCES users(id),
 name VARCHAR(100) NOT NULL,
 query_json TEXT NOT NULL,
 created_at BIGINT NOT NULL,
 UNIQUE(owner_id,name)
);
