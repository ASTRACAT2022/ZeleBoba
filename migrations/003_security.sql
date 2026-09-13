CREATE UNIQUE INDEX login_browser_hash ON login_challenges(browser_hash);
CREATE TABLE mfa_enrollments (user_id VARCHAR(32) PRIMARY KEY REFERENCES users(id), secret TEXT NOT NULL, expires_at BIGINT NOT NULL);
CREATE TABLE mfa_recovery (user_id VARCHAR(32) NOT NULL REFERENCES users(id), code_hash VARCHAR(64) NOT NULL PRIMARY KEY);
