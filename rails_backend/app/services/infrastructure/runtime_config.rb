module Infrastructure
  class RuntimeConfig
    DEFAULTS = {
      "APP_ENV" => "dev", "APP_URL" => "http://127.0.0.1:8080", "SITE_NAME" => "ZeleBoba",
      "SUPPORT_URL" => "", "BRAND_LOGO" => "", "BRAND_FAVICON" => "", "BRAND_COLOR" => "#635bff",
      "BRAND_COLOR_ACCENT" => "#e9e7ff", "BRAND_FOOTER_TEXT" => "", "BRAND_WELCOME_TEXT" => "",
      "BRAND_HELP_TEXT" => "", "PURCHASES_ENABLED" => "0", "REGISTRATION_ENABLED" => "1",
      "PAYMENT_DRIVER" => "demo", "PROVISION_DRIVER" => "demo", "PLATEGA_ENABLED" => "0",
      "PLATEGA_MERCHANT_ID" => "", "PLATEGA_SECRET" => "", "PLATEGA_API_BASE" => "https://app.platega.io",
      "REFERRAL_PROGRAM_ENABLED" => "1", "REFERRAL_MINIMUM_TOPUP_KOPEKS" => "10000",
      "REFERRAL_FIRST_TOPUP_BONUS_KOPEKS" => "10000", "REFERRAL_INVITER_BONUS_KOPEKS" => "10000",
      "REFERRAL_COMMISSION_PERCENT" => "25", "REFERRAL_FIRST_PAYMENT_COMMISSION_PERCENT" => "",
      "REFERRAL_RECURRING_COMMISSION_TIERS" => "", "REFERRAL_MAX_COMMISSION_PAYMENTS" => "0",
      "REFERRAL_WITHDRAWAL_ENABLED" => "0", "REFERRAL_WITHDRAWAL_MIN_AMOUNT_KOPEKS" => "100000",
      "REFERRAL_WITHDRAWAL_COOLDOWN_DAYS" => "30", "REFERRAL_WITHDRAWAL_SUSPICIOUS_MIN_DEPOSIT_KOPEKS" => "50000",
      "REFERRAL_WITHDRAWAL_SUSPICIOUS_MAX_DEPOSITS_PER_MONTH" => "10", "CABINET_GIFT_ENABLED" => "0",
      "TRIAL_DURATION_DAYS" => "3", "TRIAL_TRAFFIC_LIMIT_GB" => "10", "TRIAL_DEVICE_LIMIT" => "2",
      "TRIAL_ADD_REMAINING_DAYS_TO_PAID" => "0", "TRIAL_PAYMENT_ENABLED" => "0", "TRIAL_ACTIVATION_PRICE" => "0",
      "AUTORENEW_ENABLED" => "0", "AUTORENEW_DAYS_BEFORE" => "3", "AUTORENEW_MAX_FAILS" => "3",
      "REMNAWAVE_URL" => "", "REMNAWAVE_TOKEN" => "", "REMNAWAVE_SQUAD_UUID" => "",
      "TELEGRAM_BOT_TOKEN" => "", "TELEGRAM_BOT_USERNAME" => "", "TELEGRAM_WEBHOOK_SECRET" => "",
      "TELEGRAM_API_BASE" => "https://astracattg.netlify.app", "SMTP_ENABLED" => "0",
      "SMTP_HOST" => "smtp.gmail.com", "SMTP_PORT" => "587", "SMTP_USER" => "",
      "SMTP_PASSWORD" => "", "SMTP_FROM" => "", "SMTP_FROM_NAME" => "ASTRACAT"
    }.freeze
    SECRETS = %w[REMNAWAVE_TOKEN TELEGRAM_BOT_TOKEN TELEGRAM_WEBHOOK_SECRET PLATEGA_SECRET SMTP_PASSWORD].freeze

    def self.fetch(name, fallback = nil)
      new.fetch(name, fallback)
    end

    def fetch(name, fallback = nil)
      key = name.to_s
      values = overrides
      if values.key?(key)
        stored = values[key]
        return Identity::Vault.new.open(key, stored) if SECRETS.include?(key)
        return stored
      end

      ENV.fetch(key, DEFAULTS.fetch(key, fallback).to_s)
    end

    def values
      DEFAULTS.to_h { |key, default| [key, fetch(key, default)] }
    end

    private

    def overrides
      @overrides ||= AppSetting.pluck(:name, :value).to_h
    end
  end
end
