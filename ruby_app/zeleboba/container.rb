# frozen_string_literal: true

require "dotenv/load"

require_relative "billing/billing_service"
require_relative "billing/cart_service"
require_relative "billing/auto_purchase_service"
require_relative "billing/error"
require_relative "billing/gift_service"
require_relative "billing/promo_code_service"
require_relative "billing/referral_service"
require_relative "billing/topup_service"
require_relative "billing/trial_service"
require_relative "billing/wallet"
require_relative "identity/auth"
require_relative "identity/mfa"
require_relative "identity/telegram_login"
require_relative "integration/telegram_bot"
require_relative "integration/telegram_client"
require_relative "integration/provisioning_service"
require_relative "infrastructure/database"
require_relative "infrastructure/outbox"
require_relative "infrastructure/state_machine"
require_relative "infrastructure/scheduler"
require_relative "infrastructure/webhook_guard"
require_relative "infrastructure/worker"
require_relative "payments/payment_event_store"
require_relative "payments/payment_service"
require_relative "settings/settings"
require_relative "settings/readiness"
require_relative "settings/vault"
require_relative "subscriptions/subscription_service"
require_relative "subscriptions/renewal_service"
require_relative "subscriptions/merge_service"

module Zeleboba
  class Container
    attr_reader :auth, :auto_purchase, :billing, :carts, :config, :db, :gifts, :mfa, :outbox, :payment_events, :payments, :promocodes, :provisioning, :referrals, :renewals, :scheduler, :settings, :subscriptions, :merger, :telegram_bot, :telegram_client, :telegram_login, :topups, :trials, :wallet, :webhook_guard, :worker

    def initialize(config = {})
      defaults = {
        "DATABASE_DSN" => "sqlite:var/billing-ruby.sqlite",
        "DATABASE_USER" => "",
        "DATABASE_PASSWORD" => ""
      }
      env = ENV.to_h.slice(*defaults.keys, *Settings::Settings::DEFAULTS.keys)
      @config = defaults.merge(Settings::Settings::DEFAULTS).merge(env).merge(config.transform_keys(&:to_s))
      @db = Infrastructure::Database.new(
        dsn: @config["DATABASE_DSN"],
        user: @config["DATABASE_USER"],
        password: @config["DATABASE_PASSWORD"]
      )
      @settings = Settings::Settings.new(@db)
      @config = @config.merge(@settings.overrides)
      validate_runtime!
      @outbox = Infrastructure::Outbox.new(@db)
      @wallet = Billing::Wallet.new(@db)
      @subscriptions = Subscriptions::SubscriptionService.new(@db, @outbox)
      @billing = Billing::BillingService.new(@db, @outbox, @wallet, @config, @subscriptions)
      @renewals = Subscriptions::RenewalService.new(@db, @wallet, @billing, @outbox, @config)
      @merger = Subscriptions::MergeService.new(@db, @outbox)
      @gifts = Billing::GiftService.new(@db, @outbox, @wallet, @config)
      @carts = Billing::CartService.new(@db)
      @auto_purchase = Billing::AutoPurchaseService.new(@db, @carts, @billing, @gifts, @wallet, @outbox)
      @promocodes = Billing::PromoCodeService.new(@db, @outbox, @wallet)
      @referrals = Billing::ReferralService.new(@db, @outbox, @wallet, @config)
      @topups = Billing::TopupService.new(@db, @outbox, @wallet, @config)
      @trials = Billing::TrialService.new(@db, @subscriptions, @wallet)
      @webhook_guard = Infrastructure::WebhookGuard.new(@db)
      @payment_events = Payments::PaymentEventStore.new(@db, @outbox)
      @payments = Payments::PaymentService.new(@db, @billing, @topups, @payment_events, @config, webhook_guard: @webhook_guard)
      @provisioning = Integration::ProvisioningService.new(@db, @subscriptions, @outbox, @config)
      @auth = Identity::Auth.new(@db)
      @mfa = Identity::Mfa.new(@db, Settings::Vault.new(@config))
      @telegram_login = Identity::TelegramLogin.new(@db, @auth)
      @telegram_client = Integration::TelegramClient.new(@config)
      @telegram_bot = Integration::TelegramBot.new(
        db: @db, outbox: @outbox, config: @config, telegram_login: @telegram_login,
        billing: @billing, wallet: @wallet, subscriptions: @subscriptions, renewals: @renewals,
        gifts: @gifts, promocodes: @promocodes, referrals: @referrals, topups: @topups, trials: @trials
      )
      @worker = Infrastructure::Worker.new(@outbox, worker_handlers)
      @scheduler = Infrastructure::Scheduler.new(@db, @outbox, @subscriptions, @renewals, @trials)
    end

    private

    def worker_handlers
      {
        "payment.create" => ->(payload) { @payments.create_order(payload.fetch("order_id")) },
        "topup.create" => ->(payload) { @payments.create_topup(payload.fetch("topup_id")) },
        "payment.event.process" => ->(payload) { @payments.process_event(payload.fetch("event_id")) },
        "topup.after" => ->(payload) { @auto_purchase.after_topup(payload.fetch("user_id")) },
        "subscription.provision" => ->(payload) { @provisioning.provision(payload.fetch("subscription_id")) },
        "subscription.renew" => ->(payload) { @renewals.charge_due(payload.fetch("subscription_id")) },
        "subscription.daily" => ->(payload) { @renewals.charge_daily(payload.fetch("subscription_id")) },
        "subscription.revoke" => ->(payload) { @provisioning.revoke(payload.fetch("subscription_id")) },
        "subscription.extend" => ->(payload) { @provisioning.extend(payload.fetch("subscription_id"), order_id: payload["order_id"]) },
        "subscription.traffic" => ->(payload) { @provisioning.sync_traffic(payload.fetch("subscription_id")) },
        "subscription.devices" => ->(payload) { @provisioning.sync_devices(payload.fetch("subscription_id")) },
        "telegram.send" => ->(payload) { @telegram_client.send_message(**payload.transform_keys(&:to_sym)) },
        "telegram.answer" => ->(payload) { @telegram_client.answer_callback_query(**payload.transform_keys(&:to_sym)) }
      }
    end

    def validate_runtime!
      raise "Invalid APP_ENV" unless %w[dev test prod].include?(@config["APP_ENV"])
      raise "Unknown payment driver" unless %w[demo platega].include?(@config["PAYMENT_DRIVER"])
      raise "Unknown provision driver" unless %w[demo remnawave].include?(@config["PROVISION_DRIVER"])
      return unless @config["APP_ENV"] == "prod"

      raise "Production requires PostgreSQL and HTTPS" unless @db.postgres? && @config["APP_URL"].start_with?("https://")
      raise "Production requires Platega" unless @config["PAYMENT_DRIVER"] == "platega" && @config["PLATEGA_ENABLED"] == "1"
      raise "Production requires Remnawave" unless @config["PROVISION_DRIVER"] == "remnawave"
      raise "Production requires Remnawave credentials" if %w[REMNAWAVE_URL REMNAWAVE_TOKEN REMNAWAVE_SQUAD_UUID].any? { |name| @config[name].to_s.empty? }
      raise "Production requires Platega credentials" if @config["PLATEGA_MERCHANT_ID"].to_s.empty? || @config["PLATEGA_SECRET"].to_s.empty?
      raise "Production requires MFA_ENCRYPTION_KEY" if @config["MFA_ENCRYPTION_KEY"].to_s.empty?
      telegram_settings = %w[TELEGRAM_BOT_TOKEN TELEGRAM_BOT_USERNAME TELEGRAM_WEBHOOK_SECRET]
      if telegram_settings.any? { |name| !@config[name].to_s.empty? } && telegram_settings.any? { |name| @config[name].to_s.empty? }
        raise "Production Telegram configuration requires bot token, username, and webhook secret"
      end
    end
  end
end
