ALTER TABLE referral_subscription_rewards ADD COLUMN paid_count_at_award INTEGER NOT NULL DEFAULT 0;
ALTER TABLE referral_subscription_rewards ADD COLUMN months_awarded INTEGER NOT NULL DEFAULT 1;

UPDATE referral_subscription_rewards
SET paid_count_at_award=milestone*5
WHERE paid_count_at_award=0;

CREATE TABLE referral_subscription_progress (
 referrer_id VARCHAR(32) PRIMARY KEY REFERENCES users(id),
 paid_in_cycle INTEGER NOT NULL DEFAULT 0 CHECK(paid_in_cycle>=0),
 referrals_required INTEGER NOT NULL CHECK(referrals_required BETWEEN 1 AND 100),
 months_per_reward INTEGER NOT NULL CHECK(months_per_reward BETWEEN 1 AND 12),
 updated_at BIGINT NOT NULL
);

INSERT INTO referral_subscription_progress(referrer_id,paid_in_cycle,referrals_required,months_per_reward,updated_at)
SELECT rp.referrer_id,
       COUNT(*)-COALESCE((SELECT MAX(rr.paid_count_at_award) FROM referral_subscription_rewards rr WHERE rr.referrer_id=rp.referrer_id),0),
       5,1,MAX(rp.paid_at)
FROM referral_subscription_payments rp
GROUP BY rp.referrer_id
ON CONFLICT(referrer_id) DO NOTHING;
