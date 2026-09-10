CREATE TABLE required_channels (
 id VARCHAR(32) PRIMARY KEY,
 channel_id VARCHAR(100) UNIQUE NOT NULL,
 channel_link VARCHAR(500),
 title VARCHAR(255),
 is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
 sort_order INTEGER NOT NULL DEFAULT 0,
 disable_trial_on_leave INTEGER NOT NULL DEFAULT 1 CHECK(disable_trial_on_leave IN (0,1)),
 disable_paid_on_leave INTEGER NOT NULL DEFAULT 0 CHECK(disable_paid_on_leave IN (0,1)),
 created_at BIGINT NOT NULL
);

CREATE TABLE user_channel_subscriptions (
 id VARCHAR(32) PRIMARY KEY,
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 channel_id VARCHAR(100) NOT NULL,
 is_subscribed INTEGER NOT NULL DEFAULT 0 CHECK(is_subscribed IN (0,1)),
 checked_at BIGINT NOT NULL,
 UNIQUE(user_id, channel_id)
);

CREATE TABLE broadcast_history (
 id VARCHAR(32) PRIMARY KEY,
 target_type VARCHAR(100) NOT NULL,
 message_text TEXT,
 total_count INTEGER NOT NULL DEFAULT 0,
 sent_count INTEGER NOT NULL DEFAULT 0,
 failed_count INTEGER NOT NULL DEFAULT 0,
 blocked_count INTEGER NOT NULL DEFAULT 0,
 status VARCHAR(50) NOT NULL DEFAULT 'in_progress',
 admin_id VARCHAR(32) REFERENCES users(id),
 admin_name VARCHAR(255),
 category VARCHAR(20) NOT NULL DEFAULT 'system',
 created_at BIGINT NOT NULL,
 completed_at BIGINT
);

CREATE TABLE landing_pages (
 id VARCHAR(32) PRIMARY KEY,
 slug VARCHAR(100) UNIQUE NOT NULL,
 is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
 title TEXT NOT NULL,
 subtitle TEXT,
 features TEXT,
 footer_text TEXT,
 allowed_plan_ids TEXT,
 payment_methods TEXT,
 gift_enabled INTEGER NOT NULL DEFAULT 1 CHECK(gift_enabled IN (0,1)),
 custom_css TEXT,
 meta_title TEXT,
 meta_description TEXT,
 display_order INTEGER NOT NULL DEFAULT 0,
 discount_percent INTEGER,
 discount_starts_at BIGINT,
 discount_ends_at BIGINT,
 created_at BIGINT NOT NULL
);

CREATE TABLE contest_templates (
 id VARCHAR(32) PRIMARY KEY,
 name VARCHAR(100) NOT NULL,
 slug VARCHAR(50) UNIQUE NOT NULL,
 description TEXT,
 prize_type VARCHAR(20) NOT NULL DEFAULT 'days',
 prize_value VARCHAR(50) NOT NULL DEFAULT '1',
 max_winners INTEGER NOT NULL DEFAULT 1,
 attempts_per_user INTEGER NOT NULL DEFAULT 1,
 times_per_day INTEGER NOT NULL DEFAULT 1,
 cooldown_hours INTEGER NOT NULL DEFAULT 24,
 is_enabled INTEGER NOT NULL DEFAULT 1 CHECK(is_enabled IN (0,1)),
 created_at BIGINT NOT NULL
);

CREATE TABLE contest_rounds (
 id VARCHAR(32) PRIMARY KEY,
 template_id VARCHAR(32) NOT NULL REFERENCES contest_templates(id),
 status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
 starts_at BIGINT NOT NULL,
 ends_at BIGINT,
 created_at BIGINT NOT NULL
);

CREATE TABLE contest_attempts (
 id VARCHAR(32) PRIMARY KEY,
 round_id VARCHAR(32) NOT NULL REFERENCES contest_rounds(id),
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 won INTEGER NOT NULL DEFAULT 0 CHECK(won IN (0,1)),
 prize_value VARCHAR(50),
 created_at BIGINT NOT NULL,
 UNIQUE(round_id, user_id)
);

CREATE TABLE polls (
 id VARCHAR(32) PRIMARY KEY,
 title VARCHAR(255) NOT NULL,
 description TEXT,
 reward_enabled INTEGER NOT NULL DEFAULT 0 CHECK(reward_enabled IN (0,1)),
 reward_amount_kopeks BIGINT NOT NULL DEFAULT 0,
 created_by VARCHAR(32) REFERENCES users(id),
 created_at BIGINT NOT NULL
);

CREATE TABLE poll_questions (
 id VARCHAR(32) PRIMARY KEY,
 poll_id VARCHAR(32) NOT NULL REFERENCES polls(id),
 text VARCHAR(500) NOT NULL,
 options TEXT NOT NULL,
 "order" INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE poll_responses (
 id VARCHAR(32) PRIMARY KEY,
 poll_id VARCHAR(32) NOT NULL REFERENCES polls(id),
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 answers TEXT NOT NULL,
 reward_paid INTEGER NOT NULL DEFAULT 0 CHECK(reward_paid IN (0,1)),
 created_at BIGINT NOT NULL,
 UNIQUE(poll_id, user_id)
);

CREATE TABLE advertising_campaigns (
 id VARCHAR(32) PRIMARY KEY,
 name VARCHAR(255) NOT NULL,
 start_parameter VARCHAR(64) UNIQUE NOT NULL,
 bonus_type VARCHAR(20) NOT NULL,
 balance_bonus_kopeks BIGINT NOT NULL DEFAULT 0,
 subscription_duration_days INTEGER,
 subscription_traffic_gb INTEGER,
 subscription_device_limit INTEGER,
 plan_id VARCHAR(32) REFERENCES plans(id),
 is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
 partner_user_id VARCHAR(32) REFERENCES users(id),
 created_by VARCHAR(32) REFERENCES users(id),
 created_at BIGINT NOT NULL
);

CREATE TABLE advertising_campaign_registrations (
 id VARCHAR(32) PRIMARY KEY,
 campaign_id VARCHAR(32) NOT NULL REFERENCES advertising_campaigns(id),
 user_id VARCHAR(32) NOT NULL REFERENCES users(id),
 bonus_granted INTEGER NOT NULL DEFAULT 0 CHECK(bonus_granted IN (0,1)),
 created_at BIGINT NOT NULL,
 UNIQUE(campaign_id, user_id)
);
