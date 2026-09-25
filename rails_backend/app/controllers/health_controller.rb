class HealthController < ApplicationController
  def live
    render json: { status: "ok" }
  end

  def ready
    connection = ApplicationRecord.connection
    connection.select_value("SELECT 1")
    required_tables = %w[
      users user_identities plans plan_versions orders order_items topups payments payment_receipts
      payment_attempts payment_events incoming_webhooks outbox sessions login_challenges password_resets
      telegram_links telegram_updates email_queue_items subscriptions provisioning_accounts
      wallet_ledger_entries transactions ledger_entries audit_log app_settings required_channels
      user_channel_subscriptions carts withdrawal_requests referral_earnings
    ]
    missing = required_tables.reject { |table| connection.data_source_exists?(table) }
    raise ActiveRecord::StatementInvalid, "Required schema tables missing: #{missing.join(',')}" if missing.any?
    raise ActiveRecord::StatementInvalid, "Production requires PostgreSQL." if Rails.env.production? && connection.adapter_name != "PostgreSQL"
    if Rails.env.production?
      config = Infrastructure::RuntimeConfig.new
      raise ActiveRecord::StatementInvalid, "Platega is not configured." unless config.fetch("PAYMENT_DRIVER", "") == "platega" && Payments::PlategaProvider.new.configured?
      remnawave_uri = URI.parse(config.fetch("REMNAWAVE_URL", ""))
      raise ActiveRecord::StatementInvalid, "Remnawave is not configured." unless config.fetch("PROVISION_DRIVER", "") == "remnawave" && remnawave_uri.is_a?(URI::HTTPS) && config.fetch("REMNAWAVE_TOKEN", "").present? && config.fetch("REMNAWAVE_SQUAD_UUID", "").present?
      master_key_path = ENV.fetch("ZELEBOBA_MASTER_KEY_FILE", Rails.root.parent.join("var/master.key").to_s)
      env_master_key = begin
        Base64.strict_decode64(ENV.fetch("ZELEBOBA_MASTER_KEY_BASE64", "")).bytesize == 32
      rescue ArgumentError
        false
      end
      valid_master_key = env_master_key || (File.file?(master_key_path) && File.size(master_key_path) == 32)
      raise ActiveRecord::StatementInvalid, "MFA master key is missing." unless valid_master_key
      telegram_api = URI.parse(config.fetch("TELEGRAM_API_BASE", ""))
      app_url = URI.parse(config.fetch("APP_URL", ""))
      telegram_username = config.fetch("TELEGRAM_BOT_USERNAME", "").to_s.delete_prefix("@")
      raise ActiveRecord::StatementInvalid, "Telegram bot configuration is incomplete." unless
        config.fetch("TELEGRAM_BOT_TOKEN", "").present? && telegram_username.match?(/\A[a-zA-Z0-9_]{5,32}\z/) &&
        config.fetch("TELEGRAM_WEBHOOK_SECRET", "").match?(/\A[A-Za-z0-9_-]{32,256}\z/) && telegram_api.is_a?(URI::HTTPS) &&
        app_url.is_a?(URI::HTTPS) && app_url.host.present? && app_url.userinfo.nil? && app_url.query.nil? && app_url.fragment.nil?
    end

    render json: { status: "ready" }
  rescue ActiveRecord::StatementInvalid, ActiveRecord::NoDatabaseError, URI::InvalidURIError
    render json: { status: "not_ready" }, status: :service_unavailable
  end
end
