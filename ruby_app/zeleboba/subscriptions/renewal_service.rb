# frozen_string_literal: true

require_relative "../billing/error"
require_relative "../infrastructure/database"

module Zeleboba
  module Subscriptions
    class RenewalService
      def initialize(db, wallet, billing, outbox, config)
        @db = db
        @wallet = wallet
        @billing = billing
        @outbox = outbox
        @config = config
      end

      def set_auto_renew(user_id, subscription_id, enabled)
        @db.transaction do
          subscription = @db.one("SELECT * FROM subscriptions WHERE id=? AND user_id=?#{@db.lock}", [subscription_id, user_id])
          raise Billing::Error, "Подписка не найдена." unless subscription
          raise Billing::Error, "Автопродление доступно только для активной подписки." unless subscription["status"] == "active" && subscription["expires_at"].to_i > Time.now.to_i

          if enabled
            raise Billing::Error, "Автопродление отключено администратором." unless auto_renew_enabled?
            plan = active_plan(subscription["plan_id"])
            @db.execute("UPDATE subscriptions SET auto_renew=1,renew_plan_id=?,renew_price_minor=?,renew_at=?,renew_failed_at=NULL,renew_fail_count=0 WHERE id=?", [plan["id"], plan["price_minor"].to_i, renew_at(subscription["expires_at"].to_i, plan["duration_days"].to_i), subscription_id])
          else
            @db.execute("UPDATE subscriptions SET auto_renew=0,renew_at=NULL,renew_failed_at=NULL WHERE id=?", [subscription_id])
          end
          audit(user_id, enabled ? "subscription.autorenew_on" : "subscription.autorenew_off", subscription_id)
          @db.one("SELECT * FROM subscriptions WHERE id=?", [subscription_id])
        end
      end

      # Returns true only when a new renewal was charged and settled.
      def charge_due(subscription_id, now: Time.now.to_i)
        @db.transaction do
          subscription = @db.one("SELECT * FROM subscriptions WHERE id=?#{@db.lock}", [subscription_id])
          return false unless due?(subscription, now)
          plan = active_plan(subscription["renew_plan_id"] || subscription["plan_id"])
          price = subscription["renew_price_minor"].to_i.positive? ? subscription["renew_price_minor"].to_i : plan["price_minor"].to_i
          user = @db.one("SELECT * FROM users WHERE id=? AND disabled=0#{@db.lock}", [subscription["user_id"]])
          unless user && user["balance_kopeks"].to_i >= price
            defer_for_balance(subscription, now)
            return false
          end

          key = "autorenew:#{subscription_id}:#{subscription["expires_at"]}"
          existing = @db.one("SELECT * FROM orders WHERE user_id=? AND idempotency_key=?", [subscription["user_id"], key])
          return %w[paid fulfilled].include?(existing["status"]) if existing

          order_id = Infrastructure::Database.id
          @db.execute(
            "INSERT INTO orders(id,user_id,plan_id,idempotency_key,price_minor,currency,plan_name,duration_days,traffic_bytes,devices,status,provider,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,'pending',?,?)",
            [order_id, subscription["user_id"], plan["id"], key, price, plan["currency"], plan["name"], plan["duration_days"].to_i, plan["traffic_bytes"].to_i, plan["devices"].to_i, subscription_provider(subscription), now]
          )
          @db.execute("UPDATE subscriptions SET renew_order_id=?,renew_at=NULL WHERE id=?", [order_id, subscription_id])
          @wallet.debit(subscription["user_id"], price, "subscription_renewal", "Автопродление: #{plan["name"]}", "balance", order_id)
          @billing.settle(order_id, subscription_provider(subscription), "balance_#{order_id}", price, plan["currency"])
          audit("system", "subscription.autorenew_debited", subscription_id)
          true
        end
      end

      def charge_daily(subscription_id, now: Time.now.to_i)
        @db.transaction do
          subscription = @db.one("SELECT * FROM subscriptions WHERE id=?#{@db.lock}", [subscription_id])
          return false unless subscription && subscription["auto_renew"].to_i == 1 && subscription["status"] == "active"
          plan = active_plan(subscription["renew_plan_id"] || subscription["plan_id"])
          period = [1, plan["duration_days"].to_i].max * 86_400
          return false if subscription["last_daily_charge_at"].to_i.positive? && now - subscription["last_daily_charge_at"].to_i < period
          price = subscription["renew_price_minor"].to_i.positive? ? subscription["renew_price_minor"].to_i : plan["price_minor"].to_i
          user = @db.one("SELECT balance_kopeks FROM users WHERE id=?#{@db.lock}", [subscription["user_id"]])
          unless user && user["balance_kopeks"].to_i >= price
            defer_for_balance(subscription, now)
            return false
          end

          expiry = [now, subscription["expires_at"].to_i].max + period
          external_id = "daily:#{subscription_id}:#{now / period}"
          @wallet.debit(subscription["user_id"], price, "subscription_daily", "Ежедневное автосписание: #{plan["name"]}", "balance", external_id)
          @db.execute("UPDATE subscriptions SET expires_at=?,last_daily_charge_at=?,renew_at=?,renew_failed_at=NULL,updated_at=? WHERE id=?", [expiry, now, now + period, now, subscription_id])
          @outbox.enqueue("subscription.extend", "daily-extend:#{subscription_id}:#{expiry}", { "subscription_id" => subscription_id })
          audit("system", "subscription.daily_debited", subscription_id)
          true
        end
      end

      private

      def due?(subscription, now)
        subscription && subscription["auto_renew"].to_i == 1 && subscription["status"] == "active" && subscription["expires_at"].to_i > now && subscription["renew_order_id"].to_s.empty? && subscription["renew_at"].to_i <= now
      end

      def active_plan(id)
        @db.one("SELECT * FROM plans WHERE id=? AND active=1", [id]) || raise(Billing::Error, "Тариф подписки больше недоступен.")
      end

      def subscription_provider(subscription)
        order = subscription["order_id"] && @db.one("SELECT provider FROM orders WHERE id=?", [subscription["order_id"]])
        order&.fetch("provider", nil) || @config.fetch("PAYMENT_DRIVER")
      end

      def defer_for_balance(subscription, now)
        @db.execute("UPDATE subscriptions SET renew_failed_at=?,renew_at=? WHERE id=?", [now, [subscription["expires_at"].to_i, now + 3600].min, subscription["id"]])
        audit("system", "subscription.autorenew_waiting_balance", subscription["id"])
      end

      def auto_renew_enabled?
        stored = @db.one("SELECT value FROM app_settings WHERE name='AUTORENEW_ENABLED'")
        stored ? stored["value"] == "1" : @config.fetch("AUTORENEW_ENABLED", "0") == "1"
      end

      def renew_at(expires_at, days)
        period = [1, days].max * 86_400
        lead = [[@config.fetch("AUTORENEW_DAYS_BEFORE", "3").to_i.clamp(1, 14) * 86_400, period / 3].min, 300].max
        [Time.now.to_i + 60, expires_at - lead].max
      end

      def audit(actor, action, subject)
        @db.execute("INSERT INTO audit_log VALUES(?,?,?,?,?)", [Infrastructure::Database.id, actor, action, subject, Time.now.to_i])
      end
    end
  end
end
