# frozen_string_literal: true

require_relative "error"

module Zeleboba
  module Billing
    class TrialService
      def initialize(db, subscriptions, wallet)
        @db = db
        @subscriptions = subscriptions
        @wallet = wallet
      end

      def available?(user_id)
        user = @db.one("SELECT id,disabled FROM users WHERE id=?", [user_id])
        return false unless user && user["disabled"].to_i.zero?
        return false if @db.one("SELECT id FROM users WHERE id=? AND has_had_paid_subscription=1", [user_id])

        !@db.one("SELECT id FROM subscriptions WHERE user_id=? AND (is_trial=1 OR status IN ('active','trial','provisioning')) LIMIT 1", [user_id])
      end

      def start(user_id, plan_id, days: nil)
        raise Error, "Триал недоступен: у вас уже была подписка." unless available?(user_id)
        plan = @db.one("SELECT * FROM plans WHERE id=? AND active=1 AND is_trial_available=1", [plan_id])
        raise Error, "Триал на этом тарифе недоступен." unless plan

        duration = days || plan["trial_duration_days"].to_i
        duration = 3 if duration.to_i < 1
        @subscriptions.create_trial(user_id, plan_id, duration)
      end

      def convert_to_paid(user_id, subscription_id, plan_id)
        @db.transaction do
          subscription = @db.one("SELECT * FROM subscriptions WHERE id=? AND user_id=?#{@db.lock}", [subscription_id, user_id])
          raise Error, "Подписка не найдена." unless subscription
          raise Error, "Подписка не является активным триалом." unless subscription["is_trial"].to_i == 1 && %w[active trial].include?(subscription["status"])

          plan = @db.one("SELECT * FROM plans WHERE id=? AND active=1", [plan_id])
          raise Error, "Тариф недоступен." unless plan

          @wallet.debit(user_id, plan["price_minor"].to_i, "subscription_purchase", "Покупка подписки: #{plan["name"]}", "balance", "trial-convert:#{subscription_id}")
          now = Time.now.to_i
          expires_at = now + plan["duration_days"].to_i * 86_400
          @db.execute("UPDATE subscriptions SET status='active',lifecycle_status='active',is_trial=0,expires_at=?,plan_id=?,traffic_limit_gb=?,traffic_limit_bytes=?,device_limit=?,updated_at=? WHERE id=?", [expires_at, plan["id"], plan["traffic_bytes"].to_i / 1_073_741_824, plan["traffic_bytes"].to_i, plan["devices"].to_i, now, subscription_id])
          @db.execute("UPDATE users SET has_had_paid_subscription=1 WHERE id=?", [user_id])
          @db.execute("INSERT INTO subscription_conversions(id,user_id,converted_at,trial_duration_days,payment_method,first_payment_amount_kopeks,first_paid_period_days,created_at) VALUES(?,?,?,?,?,?,?,?)", [Infrastructure::Database.id, user_id, now, subscription["expires_at"].to_i > 0 ? ((subscription["expires_at"].to_i - subscription["created_at"].to_i) / 86_400).round : nil, "balance", plan["price_minor"].to_i, plan["duration_days"].to_i, now])
          @db.execute("INSERT INTO audit_log VALUES(?,?,?,?,?)", [Infrastructure::Database.id, user_id, "trial.converted", subscription_id, now])
          @subscriptions.synchronize(subscription_id, dedup_key: "trial-convert:#{subscription_id}:#{now}")
          @db.one("SELECT * FROM subscriptions WHERE id=?", [subscription_id])
        end
      end

      def expire_overdue(now: Time.now.to_i)
        rows = @db.all("SELECT id FROM subscriptions WHERE is_trial=1 AND status IN ('active','trial') AND expires_at<=?", [now])
        rows.each do |subscription|
          @db.execute("UPDATE subscriptions SET status='expired',lifecycle_status='expired',updated_at=? WHERE id=?", [now, subscription["id"]])
        end
        rows.length
      end
    end
  end
end
