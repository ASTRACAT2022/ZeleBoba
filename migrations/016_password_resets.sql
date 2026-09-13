-- Password reset tokens (email-based, like Django PendingPasswordReset)
CREATE TABLE IF NOT EXISTS password_resets (
    id VARCHAR(32) PRIMARY KEY,
    user_id VARCHAR(32) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    expires_at BIGINT NOT NULL,
    created_at BIGINT NOT NULL,
    consumed_at BIGINT
);
CREATE INDEX IF NOT EXISTS password_resets_expiry ON password_resets(expires_at);
