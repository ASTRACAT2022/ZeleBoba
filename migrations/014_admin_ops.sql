CREATE TABLE admin_roles (
 id VARCHAR(32) PRIMARY KEY,
 name VARCHAR(100) UNIQUE NOT NULL,
 description TEXT,
 level INTEGER NOT NULL DEFAULT 0,
 permissions TEXT NOT NULL DEFAULT '[]',
 color VARCHAR(7),
 icon VARCHAR(50),
 is_system INTEGER NOT NULL DEFAULT 0 CHECK(is_system IN (0,1)),
 is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
 created_by VARCHAR(32) REFERENCES users(id),
 created_at BIGINT NOT NULL
);

CREATE TABLE user_roles (
 id VARCHAR(32) PRIMARY KEY,
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 role_id VARCHAR(32) NOT NULL REFERENCES admin_roles(id),
 assigned_by VARCHAR(32) REFERENCES users(id),
 assigned_at BIGINT NOT NULL,
 expires_at BIGINT,
 is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
 UNIQUE(user_id, role_id)
);

CREATE TABLE access_policies (
 id VARCHAR(32) PRIMARY KEY,
 name VARCHAR(200) NOT NULL,
 description TEXT,
 role_id VARCHAR(32) REFERENCES admin_roles(id),
 priority INTEGER NOT NULL DEFAULT 0,
 effect VARCHAR(10) NOT NULL CHECK(effect IN ('allow','deny')),
 conditions TEXT NOT NULL DEFAULT '{}',
 resource VARCHAR(100) NOT NULL,
 actions TEXT NOT NULL DEFAULT '[]',
 is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
 created_by VARCHAR(32) REFERENCES users(id),
 created_at BIGINT NOT NULL
);

CREATE TABLE admin_audit_log (
 id VARCHAR(32) PRIMARY KEY,
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 action VARCHAR(100) NOT NULL,
 resource_type VARCHAR(50),
 resource_id VARCHAR(100),
 details TEXT,
 ip_address VARCHAR(45),
 user_agent TEXT,
 status VARCHAR(20) NOT NULL,
 request_method VARCHAR(10),
 request_path TEXT,
 created_at BIGINT NOT NULL
);
CREATE INDEX admin_audit_user_created ON admin_audit_log(user_id, created_at);
CREATE INDEX admin_audit_created ON admin_audit_log(created_at);

CREATE TABLE monitoring_logs (
 id VARCHAR(32) PRIMARY KEY,
 event_type VARCHAR(100) NOT NULL,
 message TEXT NOT NULL,
 data TEXT,
 is_success INTEGER NOT NULL DEFAULT 1 CHECK(is_success IN (0,1)),
 created_at BIGINT NOT NULL
);
CREATE INDEX monitoring_logs_created ON monitoring_logs(created_at);

CREATE TABLE system_error_events (
 id VARCHAR(32) PRIMARY KEY,
 error_type VARCHAR(100) NOT NULL,
 message TEXT,
 context TEXT,
 count INTEGER NOT NULL DEFAULT 1,
 first_seen BIGINT NOT NULL,
 last_seen BIGINT NOT NULL
);
CREATE INDEX system_errors_last_seen ON system_error_events(last_seen);

CREATE TABLE email_queue_items (
 id VARCHAR(32) PRIMARY KEY,
 user_id VARCHAR(32) REFERENCES users(id),
 to_email VARCHAR(254) NOT NULL,
 subject VARCHAR(255) NOT NULL,
 body TEXT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','sent','failed')),
 attempts INTEGER NOT NULL DEFAULT 0,
 created_at BIGINT NOT NULL,
 sent_at BIGINT
);
