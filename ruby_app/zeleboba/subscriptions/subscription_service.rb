# frozen_string_literal: true

require_relative "../billing/error"
require_relative "../infrastructure/database"
require_relative "../infrastructure/state_machine"

module Zeleboba
  module Subscriptions
    class SubscriptionService
      def initialize(db, outbox)
        @db = db
        @outbox = outbox
      end

      def create_from_order(order, now: Time.now.to_i)
        existing = @db.one("SELECT * FROM subscriptions WHERE order_id=?", [order.fetch("id")])
        return existing if existing

        id = Infrastructure::Database.id
        @db.execute(
          "INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,plan_id,traffic_limit_gb,device_limit,is_trial,starts_at,traffic_limit_bytes,updated_at,lifecycle_status) VALUES(?,?,?,'provisioning',?,?,?,?,?,0,?,?,?,'pending')",
          [id, order.fetch("id"), order.fetch("user_id"), now + order.fetch("duration_days").to_i * 86_400, now, order.fetch("plan_id"), order.fetch("traffic_bytes").to_i / 1_073_741_824, order.fetch("devices").to_i, now, order.fetch("traffic_bytes").to_i, now]
        )
        provisioning_account(id, order.fetch("provision_driver", "demo"), now)
        @outbox.enqueue("subscription.provision", "provision:#{id}", { "subscription_id" => id })
        @db.one("SELECT * FROM subscriptions WHERE id=?", [id])
      rescue Infrastructure::Database::ConstraintError
        @db.one("SELECT * FROM subscriptions WHERE order_id=?", [order.fetch("id")]) || raise
      end

      def create_trial(user_id, plan_id, days, now: Time.now.to_i)
        raise Billing::Error, "Срок триала должен быть положительным." unless days.to_i.between?(1, 3650)

        @db.transaction do
          plan = @db.one("SELECT * FROM plans WHERE id=? AND active=1#{@db.lock}", [plan_id])
          raise Billing::Error, "Тариф недоступен." unless plan
          active = @db.one("SELECT id FROM subscriptions WHERE user_id=? AND is_trial=1 AND status IN ('provisioning','active','trial')", [user_id])
          raise Billing::Error, "Триал уже был активирован." if active

          id = Infrastructure::Database.id
          @db.execute(
            "INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,plan_id,traffic_limit_gb,device_limit,is_trial,starts_at,traffic_limit_bytes,updated_at,lifecycle_status) VALUES(?,?,?,'provisioning',?,?,?,?,?,1,?,?,?,'pending')",
            [id, nil, user_id, now + days.to_i * 86_400, now, plan_id, plan["traffic_bytes"].to_i / 1_073_741_824, plan["devices"].to_i, now, plan["traffic_bytes"].to_i, now]
          )
          provisioning_account(id, "demo", now)
          @outbox.enqueue("subscription.provision", "provision:#{id}", { "subscription_id" => id })
          @db.one("SELECT * FROM subscriptions WHERE id=?", [id])
        end
      end

      def activate(subscription_id, remote_id:, subscription_url:, now: Time.now.to_i)
        @db.transaction do
          subscription = find_locked(subscription_id)
          return subscription if subscription["status"] == "active" && subscription["remote_id"] == remote_id
          Infrastructure::StateMachine.assert!("subscription", subscription["lifecycle_status"].to_s.empty? ? "pending" : subscription["lifecycle_status"], "active")
          @db.execute("UPDATE subscriptions SET status='active',lifecycle_status='active',remote_id=?,subscription_url=?,updated_at=? WHERE id=?", [remote_id, subscription_url, now, subscription_id])
          @db.execute("UPDATE provisioning_accounts SET external_user_id=?,state='active',last_synced_at=?,last_error=NULL,updated_at=? WHERE subscription_id=?", [remote_id, now, now, subscription_id])
          @db.execute("UPDATE orders SET status='fulfilled',workflow_status='fulfilled' WHERE id=? AND status='paid'", [subscription["order_id"]]) if subscription["order_id"]
          @db.one("SELECT * FROM subscriptions WHERE id=?", [subscription_id])
        end
      end

      def extend(subscription_id, days, now: Time.now.to_i)
        raise Billing::Error, "Срок продления должен быть положительным." unless days.to_i.positive?

        @db.transaction do
          subscription = find_locked(subscription_id)
          raise Billing::Error, "Нельзя продлить завершённую подписку." if %w[expired cancelled].include?(subscription["lifecycle_status"])
          expires_at = [now, subscription["expires_at"].to_i].max + days.to_i * 86_400
          @db.execute("UPDATE subscriptions SET expires_at=?,updated_at=? WHERE id=?", [expires_at, now, subscription_id])
          @outbox.enqueue("subscription.extend", "extend:#{subscription_id}:#{expires_at}", { "subscription_id" => subscription_id })
          @db.one("SELECT * FROM subscriptions WHERE id=?", [subscription_id])
        end
      end

      def synchronize(subscription_id, dedup_key: nil)
        subscription = find_locked(subscription_id)
        @outbox.enqueue(
          "subscription.extend",
          dedup_key || "sync:#{subscription_id}:#{subscription["updated_at"]}",
          { "subscription_id" => subscription_id }
        )
      end

      def expire_due(now: Time.now.to_i)
        @db.all("SELECT id,lifecycle_status FROM subscriptions WHERE expires_at<=? AND lifecycle_status IN ('pending','provisioning','active','grace')", [now]).each do |row|
          next unless Infrastructure::StateMachine.allowed("subscription", row["lifecycle_status"]).include?("expired")

          @db.execute("UPDATE subscriptions SET status='expired',lifecycle_status='expired',updated_at=? WHERE id=? AND lifecycle_status=?", [now, row["id"], row["lifecycle_status"]])
        end
      end

      private

      def find_locked(id)
        @db.one("SELECT * FROM subscriptions WHERE id=?#{@db.lock}", [id]) || raise(Billing::Error, "Подписка не найдена.")
      end

      def provisioning_account(subscription_id, provider, now)
        @db.execute(
          "INSERT INTO provisioning_accounts(id,subscription_id,provider,state,created_at,updated_at) VALUES(?,?,?,'pending',?,?) ON CONFLICT(subscription_id,provider) DO NOTHING",
          [Infrastructure::Database.id, subscription_id, provider, now, now]
        )
      end
    end
  end
end
