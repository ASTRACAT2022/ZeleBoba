-- Rebuild users with the full Bedolaga-compatible column set (SQLite cannot ALTER ADD UNIQUE).
CREATE TABLE users_new (
 id VARCHAR(32) PRIMARY KEY,
 email VARCHAR(254) UNIQUE,
 password_hash VARCHAR(255),
 telegram_id VARCHAR(30) UNIQUE,
 role VARCHAR(20) NOT NULL DEFAULT 'customer' CHECK(role IN ('customer','admin')),
 created_at BIGINT NOT NULL,
 totp_secret TEXT,
 totp_last_step BIGINT NOT NULL DEFAULT 0,
 disabled INTEGER NOT NULL DEFAULT 0 CHECK(disabled IN (0,1)),
 balance_kopeks BIGINT NOT NULL DEFAULT 0,
 has_made_first_topup INTEGER NOT NULL DEFAULT 0 CHECK(has_made_first_topup IN (0,1)),
 promo_offer_discount_percent INTEGER NOT NULL DEFAULT 0,
 promo_offer_discount_source VARCHAR(100),
 promo_offer_discount_expires_at BIGINT,
 has_had_paid_subscription INTEGER NOT NULL DEFAULT 0 CHECK(has_had_paid_subscription IN (0,1)),
 referred_by_id VARCHAR(32) REFERENCES users(id),
 referral_code VARCHAR(20) UNIQUE,
 referral_commission_percent INTEGER,
 referral_days_subscription_id VARCHAR(32),
 referral_reward_preference VARCHAR(10),
 username VARCHAR(255),
 first_name VARCHAR(255),
 last_name VARCHAR(255),
 status VARCHAR(20) NOT NULL DEFAULT 'active',
 language VARCHAR(5) NOT NULL DEFAULT 'ru',
 used_promocodes INTEGER NOT NULL DEFAULT 0,
 last_activity BIGINT,
 remnawave_id BIGINT,
 remnawave_uuid VARCHAR(255),
 email_verified INTEGER NOT NULL DEFAULT 0,
 email_verified_at BIGINT,
 email_verification_source VARCHAR(32),
 email_verification_token VARCHAR(255),
 email_verification_expires BIGINT,
 password_reset_token VARCHAR(255),
 password_reset_expires BIGINT,
 cabinet_last_login BIGINT,
 pending_campaign_slug VARCHAR(64),
 email_change_new VARCHAR(255),
 email_change_code VARCHAR(6),
 email_change_expires BIGINT,
 google_id VARCHAR(255) UNIQUE,
 yandex_id VARCHAR(255) UNIQUE,
 discord_id VARCHAR(255) UNIQUE,
 vk_id BIGINT UNIQUE,
 lifetime_used_traffic_bytes BIGINT NOT NULL DEFAULT 0,
 auto_promo_group_assigned INTEGER NOT NULL DEFAULT 0,
 auto_promo_group_threshold_kopeks BIGINT NOT NULL DEFAULT 0,
 trojan_password VARCHAR(255),
 vless_uuid VARCHAR(255),
 ss_password VARCHAR(255),
 last_remnawave_sync BIGINT
);
INSERT INTO users_new(id,email,password_hash,telegram_id,role,created_at,totp_secret,totp_last_step,disabled,balance_kopeks,has_made_first_topup,promo_offer_discount_percent,promo_offer_discount_source,promo_offer_discount_expires_at,has_had_paid_subscription)
 SELECT id,email,password_hash,telegram_id,role,created_at,totp_secret,totp_last_step,disabled,balance_kopeks,has_made_first_topup,promo_offer_discount_percent,promo_offer_discount_source,promo_offer_discount_expires_at,has_had_paid_subscription FROM users;
-- PostgreSQL: drop FKs referencing users before dropping the table, then restore them.
ALTER TABLE login_challenges DROP CONSTRAINT IF EXISTS login_challenges_user_id_fkey;
ALTER TABLE mfa_enrollments DROP CONSTRAINT IF EXISTS mfa_enrollments_user_id_fkey;
ALTER TABLE mfa_recovery DROP CONSTRAINT IF EXISTS mfa_recovery_user_id_fkey;
ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_user_id_fkey;
ALTER TABLE sessions DROP CONSTRAINT IF EXISTS sessions_user_id_fkey;
ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_user_id_fkey;
ALTER TABLE telegram_links DROP CONSTRAINT IF EXISTS telegram_links_user_id_fkey;
-- users_new itself references users via referred_by_id; drop before dropping users.
ALTER TABLE users_new DROP CONSTRAINT IF EXISTS users_new_referred_by_id_fkey;
-- Tables created by migrations 008/009 also reference users; drop their FKs too.
ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_user_id_fkey;
ALTER TABLE topups DROP CONSTRAINT IF EXISTS topups_user_id_fkey;
ALTER TABLE carts DROP CONSTRAINT IF EXISTS carts_user_id_fkey;
ALTER TABLE promocodes DROP CONSTRAINT IF EXISTS promocodes_created_by_fkey;
ALTER TABLE promocode_uses DROP CONSTRAINT IF EXISTS promocode_uses_user_id_fkey;
ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_new_user_id_fkey;
DROP TABLE users;
ALTER TABLE users_new RENAME TO users;
-- Restore the self-referential referred_by_id FK on the rebuilt users table.
ALTER TABLE users ADD CONSTRAINT users_referred_by_id_fkey FOREIGN KEY (referred_by_id) REFERENCES users(id);
-- Restore FKs from 008/009 tables now pointing at the rebuilt users table.
ALTER TABLE transactions ADD CONSTRAINT transactions_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id);
ALTER TABLE topups ADD CONSTRAINT topups_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id);
ALTER TABLE carts ADD CONSTRAINT carts_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id);
ALTER TABLE promocodes ADD CONSTRAINT promocodes_created_by_fkey FOREIGN KEY (created_by) REFERENCES users(id);
ALTER TABLE promocode_uses ADD CONSTRAINT promocode_uses_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id);
ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_new_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id);
-- Restore FKs now pointing at the rebuilt users table.
ALTER TABLE login_challenges ADD CONSTRAINT login_challenges_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id);
ALTER TABLE mfa_enrollments ADD CONSTRAINT mfa_enrollments_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id);
ALTER TABLE mfa_recovery ADD CONSTRAINT mfa_recovery_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id);
ALTER TABLE orders ADD CONSTRAINT orders_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id);
ALTER TABLE sessions ADD CONSTRAINT sessions_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id);
ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id);
ALTER TABLE telegram_links ADD CONSTRAINT telegram_links_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id);

CREATE TABLE referral_earnings (
 id VARCHAR(32) PRIMARY KEY,
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 referral_id VARCHAR(32) NOT NULL REFERENCES users(id),
 amount_kopeks BIGINT NOT NULL,
 reason VARCHAR(50) NOT NULL,
 created_at BIGINT NOT NULL
);
CREATE INDEX referral_earnings_user ON referral_earnings(user_id, created_at);

CREATE TABLE withdrawal_requests (
 id VARCHAR(32) PRIMARY KEY,
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 amount_kopeks BIGINT NOT NULL CHECK(amount_kopeks > 0),
 status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','paid')),
 payment_details TEXT,
 risk_score INTEGER NOT NULL DEFAULT 0,
 risk_analysis TEXT,
 processed_by VARCHAR(32) REFERENCES users(id),
 processed_at BIGINT,
 admin_comment TEXT,
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL
);
CREATE INDEX withdrawal_requests_user ON withdrawal_requests(user_id, created_at);
CREATE INDEX withdrawal_requests_status ON withdrawal_requests(status);
