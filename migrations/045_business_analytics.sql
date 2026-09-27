-- Analytics is additive and deliberately separate from the billing state machine.
ALTER TABLE payment_attempts ADD COLUMN redirected_at BIGINT;
ALTER TABLE payment_attempts ADD COLUMN returned_at BIGINT;
ALTER TABLE payment_attempts ADD COLUMN succeeded_at BIGINT;
ALTER TABLE payment_attempts ADD COLUMN cancelled_at BIGINT;
ALTER TABLE payment_attempts ADD COLUMN expired_at BIGINT;
ALTER TABLE payment_attempts ADD COLUMN user_cancelled INTEGER CHECK(user_cancelled IN (0,1));
ALTER TABLE payment_attempts ADD COLUMN payment_method VARCHAR(80);
ALTER TABLE payment_attempts ADD COLUMN plan_id VARCHAR(32) REFERENCES plans(id);
ALTER TABLE payment_attempts ADD COLUMN provider_status VARCHAR(80);
ALTER TABLE payment_attempts ADD COLUMN provider_error_code VARCHAR(100);
ALTER TABLE payment_attempts ADD COLUMN provider_error_message VARCHAR(500);
UPDATE payment_attempts SET succeeded_at=completed_at WHERE status='succeeded' AND succeeded_at IS NULL;
UPDATE payment_attempts SET cancelled_at=completed_at WHERE status='cancelled' AND cancelled_at IS NULL;
UPDATE payment_attempts SET expired_at=completed_at WHERE status='expired' AND expired_at IS NULL;
UPDATE payment_attempts SET provider_error_message=last_error WHERE status IN ('failed','cancelled','expired') AND last_error IS NOT NULL;
CREATE INDEX payment_attempts_status_time ON payment_attempts(status,created_at);

ALTER TABLE payments ADD COLUMN payment_purpose VARCHAR(30);
ALTER TABLE subscriptions ADD COLUMN subscription_origin VARCHAR(30);
UPDATE subscriptions SET subscription_origin=CASE WHEN order_id IS NOT NULL THEN 'purchase' ELSE 'remnawave_import' END WHERE subscription_origin IS NULL;

CREATE TABLE analytics_attribution (
 anonymous_id VARCHAR(64) PRIMARY KEY,
 user_id VARCHAR(32) REFERENCES users(id),
 first_source VARCHAR(120), first_medium VARCHAR(120), first_campaign VARCHAR(160), first_content VARCHAR(160), first_term VARCHAR(160),
 first_landing_page VARCHAR(500), first_referral_code VARCHAR(100), first_click_id VARCHAR(160), first_campaign_id VARCHAR(100), first_touch_at BIGINT NOT NULL,
 last_source VARCHAR(120), last_medium VARCHAR(120), last_campaign VARCHAR(160), last_content VARCHAR(160), last_term VARCHAR(160),
 last_landing_page VARCHAR(500), last_referral_code VARCHAR(100), last_click_id VARCHAR(160), last_campaign_id VARCHAR(100), last_touch_at BIGINT NOT NULL,
 created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL
);
CREATE INDEX analytics_attribution_user ON analytics_attribution(user_id,first_touch_at);
CREATE INDEX analytics_attribution_campaign ON analytics_attribution(last_source,last_medium,last_campaign);

CREATE TABLE analytics_events (
 id VARCHAR(32) PRIMARY KEY,
 event_key VARCHAR(200) UNIQUE,
 event_name VARCHAR(80) NOT NULL,
 user_id VARCHAR(32) REFERENCES users(id),
 anonymous_id VARCHAR(64), session_id VARCHAR(64),
 source VARCHAR(120), medium VARCHAR(120), campaign VARCHAR(160),
 properties TEXT NOT NULL DEFAULT '{}',
 occurred_at BIGINT NOT NULL, created_at BIGINT NOT NULL
);
CREATE INDEX analytics_events_name_time ON analytics_events(event_name,occurred_at);
CREATE INDEX analytics_events_user_time ON analytics_events(user_id,occurred_at);
CREATE INDEX analytics_events_campaign_time ON analytics_events(campaign,occurred_at);
CREATE INDEX analytics_events_anon_time ON analytics_events(anonymous_id,occurred_at);

CREATE TABLE marketing_campaigns (
 id VARCHAR(32) PRIMARY KEY,
 campaign_key VARCHAR(160) NOT NULL UNIQUE,
 name VARCHAR(255) NOT NULL,
 source VARCHAR(120) NOT NULL,
 medium VARCHAR(120),
 started_at BIGINT,
 ended_at BIGINT,
 spend_minor BIGINT NOT NULL DEFAULT 0 CHECK(spend_minor>=0),
 currency VARCHAR(3) NOT NULL DEFAULT 'RUB',
 created_by VARCHAR(32) REFERENCES users(id),
 created_at BIGINT NOT NULL
);
CREATE INDEX marketing_campaigns_source ON marketing_campaigns(source,medium,campaign_key);

INSERT INTO analytics_events(id,event_key,event_name,user_id,source,medium,campaign,properties,occurred_at,created_at)
SELECT substr(p.id,1,30)||'an','payment_succeeded:'||p.id,'payment_succeeded',p.user_id,'','','',
       '{"history":"backfilled","purpose":"unknown"}',COALESCE(p.paid_at,p.created_at),COALESCE(p.paid_at,p.created_at)
FROM payments p WHERE p.status='succeeded' AND p.provider_payment_id NOT LIKE 'balance_%'
ON CONFLICT(event_key) DO NOTHING;

INSERT INTO analytics_events(id,event_key,event_name,user_id,source,medium,campaign,properties,occurred_at,created_at)
SELECT substr(t.id,1,30)||'tp','topup_succeeded:'||t.id,'topup_succeeded',t.user_id,'','','',
       '{"history":"backfilled","purpose":"balance_topup"}',COALESCE(t.paid_at,t.created_at),COALESCE(t.paid_at,t.created_at)
FROM topups t WHERE t.status='paid' AND t.provider='platega'
ON CONFLICT(event_key) DO NOTHING;
