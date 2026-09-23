# frozen_string_literal: true

module Zeleboba
  module Settings
    class Settings
      DEFAULTS = {
        "APP_ENV" => "dev",
        "APP_URL" => "http://127.0.0.1:8080",
        "SITE_NAME" => "ZeleBoba",
        "SUPPORT_URL" => "",
        "PURCHASES_ENABLED" => "0",
        "REGISTRATION_ENABLED" => "1",
        "PAYMENT_DRIVER" => "demo",
        "PROVISION_DRIVER" => "demo",
        "REMNAWAVE_URL" => "",
        "REMNAWAVE_TOKEN" => "",
        "REMNAWAVE_SQUAD_UUID" => "",
        "TELEGRAM_BOT_TOKEN" => "",
        "TELEGRAM_BOT_USERNAME" => "",
        "TELEGRAM_WEBHOOK_SECRET" => "",
        "TELEGRAM_API_BASE" => "https://api.telegram.org",
        "MFA_ENCRYPTION_KEY" => "",
        "PLATEGA_ENABLED" => "0",
        "PLATEGA_API_BASE" => "https://app.platega.io",
        "PLATEGA_MERCHANT_ID" => "",
        "PLATEGA_SECRET" => "",
        "CABINET_GIFT_ENABLED" => "1",
        "REFERRAL_COMMISSION_PERCENT" => "25",
        "REFERRAL_MINIMUM_TOPUP_KOPEKS" => "10000",
        "REFERRAL_FIRST_TOPUP_BONUS_KOPEKS" => "10000",
        "REFERRAL_INVITER_BONUS_KOPEKS" => "10000",
        "REFERRAL_WITHDRAWAL_ENABLED" => "1",
        "REFERRAL_WITHDRAWAL_MIN_AMOUNT_KOPEKS" => "100000",
        "REFERRAL_WITHDRAWAL_COOLDOWN_DAYS" => "30"
      }.freeze

      def initialize(db)
        @db = db
      end

      def installed?
        if @db.postgres?
          row = @db.one("SELECT to_regclass('app_settings') AS name")
          !row.nil? && !row["name"].nil?
        else
          !@db.one("SELECT name FROM sqlite_master WHERE type='table' AND name='app_settings'").nil?
        end
      end

      def values
        settings = DEFAULTS.dup
        return settings unless installed?

        @db.all("SELECT name,value FROM app_settings").each do |row|
          settings[row["name"]] = row["value"]
        end
        settings
      end

      def overrides
        return {} unless installed?

        @db.all("SELECT name,value FROM app_settings").to_h { |row| [row["name"], row["value"]] }
      end

      def revision
        @db.one("SELECT revision FROM settings_revision WHERE id=1").fetch("revision", 0).to_i
      rescue StandardError
        0
      end
    end
  end
end
