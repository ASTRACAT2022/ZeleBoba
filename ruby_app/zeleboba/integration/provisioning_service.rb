# frozen_string_literal: true

require_relative "../infrastructure/job_error"
require_relative "remnawave_client"

module Zeleboba
  module Integration
    class ProvisioningService
      def initialize(db, subscriptions, outbox, config, remnawave: nil)
        @db = db
        @subscriptions = subscriptions
        @outbox = outbox
        @config = config
        @remnawave = remnawave || RemnawaveClient.new(config)
      end

      def provision(subscription_id)
        subscription = load(subscription_id)
        return unless subscription && %w[provisioning active trial].include?(subscription["status"])
        return if subscription["status"] == "active" && !subscription["remote_id"].to_s.empty?
        return if subscription["expires_at"].to_i <= Time.now.to_i

        mark(subscription, "processing")
        remote = driver(subscription) == "remnawave" ? @remnawave.provision(subscription) : demo_remote(subscription)
        @subscriptions.activate(subscription["id"], remote_id: remote.fetch("id"), subscription_url: remote["url"])
        notify(subscription, "Подписка готова. Откройте веб-кабинет или отправьте /status.")
      rescue StandardError => e
        mark(subscription, "retry", e.message) if subscription
        raise
      end

      def extend(subscription_id, order_id: nil)
        subscription = load(subscription_id)
        return unless subscription && subscription["status"] == "active"

        mark(subscription, "processing")
        if driver(subscription) == "remnawave"
          subscription["remote_id"].to_s.empty? ? provision(subscription_id) : @remnawave.extend(subscription)
        end
        @db.execute("UPDATE orders SET status='fulfilled',workflow_status='fulfilled' WHERE id=? AND status='paid'", [order_id]) if order_id
        mark(subscription, "active")
        notify(subscription, "Подписка продлена до #{Time.at(subscription["expires_at"].to_i).utc.strftime("%d.%m.%Y %H:%M UTC")}.")
      rescue StandardError => e
        mark(subscription, "retry", e.message) if subscription
        raise
      end

      def revoke(subscription_id)
        subscription = load(subscription_id)
        return unless subscription

        @remnawave.disable(subscription) if driver(subscription) == "remnawave"
        @db.execute("UPDATE provisioning_accounts SET state='failed',last_error='subscription revoked',updated_at=? WHERE subscription_id=?", [Time.now.to_i, subscription_id])
      end

      def sync_traffic(subscription_id)
        subscription = load(subscription_id)
        return unless subscription && subscription["status"] == "active" && driver(subscription) == "remnawave"

        @remnawave.set_traffic(subscription)
        mark(subscription, "active")
      end

      def sync_devices(subscription_id)
        subscription = load(subscription_id)
        return unless subscription && subscription["status"] == "active" && driver(subscription) == "remnawave"

        @remnawave.set_devices(subscription)
        mark(subscription, "active")
      end

      private

      def load(subscription_id)
        subscription = @db.one(
          "SELECT s.*,COALESCE(s.traffic_limit_bytes,0) AS traffic_bytes,COALESCE(s.device_limit,o.devices,1) AS devices,COALESCE(o.provision_driver,?) AS provision_driver,COALESCE(o.squad_uuid,p.squad_uuid,?) AS squad_uuid FROM subscriptions s LEFT JOIN orders o ON o.id=s.order_id LEFT JOIN plans p ON p.id=s.plan_id WHERE s.id=?",
          [@config.fetch("PROVISION_DRIVER"), @config.fetch("REMNAWAVE_SQUAD_UUID", ""), subscription_id]
        )
        return nil unless subscription

        @db.execute(
          "INSERT INTO provisioning_accounts(id,subscription_id,provider,state,created_at,updated_at) VALUES(?,?,?,'pending',?,?) ON CONFLICT(subscription_id,provider) DO NOTHING",
          [Infrastructure::Database.id, subscription_id, driver(subscription), Time.now.to_i, Time.now.to_i]
        )
        @db.execute("UPDATE provisioning_accounts SET provider=? WHERE subscription_id=?", [driver(subscription), subscription_id])
        subscription
      end

      def driver(subscription)
        value = subscription["provision_driver"].to_s
        %w[demo remnawave].include?(value) ? value : @config.fetch("PROVISION_DRIVER")
      end

      def mark(subscription, state, error = nil)
        @db.execute("UPDATE provisioning_accounts SET state=?,last_error=?,last_synced_at=?,updated_at=? WHERE subscription_id=?", [state, error&.to_s&.slice(0, 500), state == "active" ? Time.now.to_i : nil, Time.now.to_i, subscription["id"]])
      end

      def demo_remote(subscription)
        { "id" => "demo-#{subscription["id"]}", "url" => "#{@config.fetch("APP_URL").to_s.sub(%r{/+\z}, "")}/subscriptions/#{subscription["id"]}" }
      end

      def notify(subscription, text)
        telegram_id = @db.one("SELECT telegram_id FROM users WHERE id=?", [subscription["user_id"]])&.fetch("telegram_id", nil)
        return if telegram_id.to_s.empty?

        @outbox.enqueue("telegram.send", "telegram:subscription:#{subscription["id"]}:#{subscription["expires_at"]}", { "chat_id" => telegram_id, "text" => text })
      end
    end
  end
end
