CREATE TABLE app_settings (name VARCHAR(100) PRIMARY KEY, value TEXT NOT NULL, updated_at BIGINT NOT NULL);
CREATE TABLE settings_revision (id INTEGER PRIMARY KEY CHECK(id=1), revision INTEGER NOT NULL);
INSERT INTO settings_revision VALUES(1,0);
CREATE TABLE integration_checks (integration VARCHAR(40) PRIMARY KEY, config_hash VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, checked_at BIGINT NOT NULL);
CREATE TABLE login_challenges (
 token_hash VARCHAR(64) PRIMARY KEY, browser_hash VARCHAR(64), user_id VARCHAR(32) REFERENCES users(id),
 kind VARCHAR(20) NOT NULL CHECK(kind IN ('browser','magic')), state VARCHAR(20) NOT NULL CHECK(state IN ('pending','approved','consumed')),
 expires_at BIGINT NOT NULL, created_at BIGINT NOT NULL
);
CREATE INDEX login_challenges_expiry ON login_challenges(expires_at);
ALTER TABLE users ADD COLUMN totp_secret TEXT;
ALTER TABLE users ADD COLUMN totp_last_step BIGINT NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN disabled INTEGER NOT NULL DEFAULT 0 CHECK(disabled IN (0,1));
ALTER TABLE sessions ADD COLUMN admin_verified_until BIGINT NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN provision_driver VARCHAR(30) NOT NULL DEFAULT 'demo';
ALTER TABLE orders ADD COLUMN squad_uuid VARCHAR(100) NOT NULL DEFAULT '';
ALTER TABLE orders ADD COLUMN provider_account VARCHAR(100) NOT NULL DEFAULT '';
ALTER TABLE plans ADD COLUMN squad_uuid VARCHAR(100) NOT NULL DEFAULT '';
CREATE TABLE runtime_heartbeats (name VARCHAR(30) PRIMARY KEY, seen_at BIGINT NOT NULL);
CREATE TABLE webhook_inbox (id VARCHAR(100) PRIMARY KEY, payment_id VARCHAR(100) NOT NULL, state VARCHAR(20) NOT NULL DEFAULT 'pending', created_at BIGINT NOT NULL);
