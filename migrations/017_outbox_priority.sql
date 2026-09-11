-- 017: outbox priority (personal telegram replies/answers outrank mass broadcasts).
ALTER TABLE outbox ADD COLUMN priority INTEGER NOT NULL DEFAULT 30;
CREATE INDEX outbox_priority ON outbox(priority DESC, status, available_at);
-- Backfill priorities for rows already queued so old broadcast tails cannot
-- outrank personal messages.
UPDATE outbox SET priority = CASE topic
  WHEN 'payment.verify' THEN 100
  WHEN 'payment.create' THEN 90
  WHEN 'topup.create' THEN 90
  WHEN 'topup.after' THEN 90
  WHEN 'referral.topup' THEN 90
  WHEN 'subscription.provision' THEN 80
  WHEN 'subscription.extend' THEN 80
  WHEN 'subscription.renew' THEN 80
  WHEN 'subscription.traffic' THEN 80
  WHEN 'subscription.devices' THEN 80
  WHEN 'gift.create' THEN 70
  WHEN 'telegram.send' THEN 60
  WHEN 'telegram.answer' THEN 60
  WHEN 'compensation.run' THEN 50
  WHEN 'compensation.grant' THEN 50
  WHEN 'broadcast.send' THEN 10
  WHEN 'broadcast.run' THEN 5
  ELSE priority
END;
