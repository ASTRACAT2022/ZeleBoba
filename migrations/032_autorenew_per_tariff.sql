-- Per-tariff auto-renew tuning.
-- NULL = fall back to the global AUTORENEW_DAYS_BEFORE / AUTORENEW_MAX_FAILS
-- app_settings (or their code defaults of 3 / 3). Non-NULL overrides per plan.
ALTER TABLE plans ADD COLUMN autorenew_days_before INT;
ALTER TABLE plans ADD COLUMN autorenew_max_fails INT;
