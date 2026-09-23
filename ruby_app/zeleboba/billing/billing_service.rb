# frozen_string_literal: true

require "json"

require_relative "error"
require_relative "../infrastructure/database"
require_relative "../infrastructure/state_machine"

module Zeleboba
  module Billing
    class BillingService
      def initialize(db, outbox, wallet, config, subscriptions = nil)
        @db = db
        @outbox = outbox
        @wallet = wallet
        @config = config
        @subscriptions = subscriptions
      end

      def order(user_id, plan_id, key)
        raise Error, "Покупки временно приостановлены." unless @config["PURCHASES_ENABLED"] == "1"
        raise Error, "Некорректный ключ операции." unless key.to_s.match?(/\A[a-zA-Z0-9:_-]{8,128}\z/)

        @db.transaction do
          raise Error, "Аккаунт не найден." unless @db.one("SELECT id FROM users WHERE id=? AND COALESCE(disabled,0)=0#{@db.lock}", [user_id])

          existing = @db.one("SELECT * FROM orders WHERE user_id=? AND idempotency_key=?", [user_id, key])
          return existing if existing && existing["plan_id"] == plan_id
          raise Error, "Этот ключ уже использован для другого тарифа." if existing

          plan = @db.one("SELECT * FROM plans WHERE id=? AND active=1", [plan_id])
          raise Error, "Тариф недоступен." unless plan

          id = Infrastructure::Database.id
          @db.execute(
            "INSERT INTO orders(id,user_id,plan_id,idempotency_key,price_minor,currency,plan_name,duration_days,traffic_bytes,devices,status,provider,provision_driver,squad_uuid,created_at) " \
            "VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
              id, user_id, plan_id, key, plan["price_minor"].to_i, plan["currency"], plan["name"],
              plan["duration_days"].to_i, plan["traffic_bytes"].to_i, plan["devices"].to_i,
              "pending", @config["PAYMENT_DRIVER"], @config["PROVISION_DRIVER"], plan["squad_uuid"].to_s.empty? ? @config.fetch("REMNAWAVE_SQUAD_UUID", "") : plan["squad_uuid"], Time.now.to_i
            ]
          )
          enqueue_payment_create(id)
          audit(user_id, "order.created", id)
          @db.one("SELECT * FROM orders WHERE id=?", [id])
        end
      end

      def settle(order_id, provider, payment_id, amount, currency)
        raise Error, "Некорректный платёж." if payment_id.to_s.empty? || amount.to_i <= 0

        @db.transaction do
          order = @db.one("SELECT * FROM orders WHERE id=?#{@db.lock}", [order_id])
          unless order && order["provider"] == provider && order["price_minor"].to_i == amount.to_i && order["currency"] == currency
            raise Error, "Платёж не соответствует заказу."
          end
          receipt = @db.one("SELECT * FROM payment_receipts WHERE provider=? AND payment_id=?", [provider, payment_id])
          return if receipt && receipt["order_id"] == order_id
          raise Error, "Платёж уже принадлежит другому заказу." if receipt
          Infrastructure::StateMachine.assert!("order", order["status"], "paid")

          now = Time.now.to_i
          @db.execute("INSERT INTO payment_receipts VALUES(?,?,?,?,?,?)", [provider, payment_id, order_id, amount, currency, now])
          @db.execute("INSERT INTO ledger_entries VALUES(?,?,?,?,?,?)", [Infrastructure::Database.id, order_id, "provider_clearing", amount, currency, now])
          @db.execute("INSERT INTO ledger_entries VALUES(?,?,?,?,?,?)", [Infrastructure::Database.id, order_id, "subscription_sales", -amount, currency, now])
          @db.execute("UPDATE orders SET status='paid',provider_payment_id=?,paid_at=? WHERE id=?", [payment_id, now, order_id])
          renewal = @db.one("SELECT * FROM subscriptions WHERE renew_order_id=?#{@db.lock}", [order_id])
          renewal ? renew_subscription(renewal, order, now) : create_subscription(order, now)
          @db.execute("UPDATE users SET has_had_paid_subscription=1 WHERE id=?", [order["user_id"]])
          audit("provider:#{provider}", "payment.settled", order_id)
        end
      end

      def purchase_from_balance(user_id, plan_id, key)
        @db.transaction do
          order = order(user_id, plan_id, key)
          return order if %w[paid fulfilled].include?(order["status"])

          @wallet.debit(user_id, order["price_minor"].to_i, "subscription_purchase", "Покупка подписки: #{order["plan_name"]}", "balance", order["id"])
          settle(order["id"], order["provider"], "balance_#{order["id"]}", order["price_minor"].to_i, order["currency"])
          @db.one("SELECT * FROM orders WHERE id=?", [order["id"]])
        end
      end

      def renewal_order(user_id, subscription_id, key)
        raise Error, "Некорректный ключ операции." unless key.to_s.match?(/\A[a-zA-Z0-9:_-]{8,64}\z/)

        @db.transaction do
          subscription = @db.one("SELECT * FROM subscriptions WHERE id=? AND user_id=?#{@db.lock}", [subscription_id, user_id])
          raise Error, "Подписка не найдена." unless subscription
          raise Error, "Продлить можно только активную подписку." unless subscription["status"] == "active" && subscription["expires_at"].to_i > Time.now.to_i

          plan = @db.one("SELECT id FROM plans WHERE id=? AND active=1", [subscription["plan_id"]])
          raise Error, "Тариф подписки больше недоступен." unless plan

          order = order(user_id, plan["id"], "renew:#{subscription_id}:#{key}")
          @db.execute("UPDATE subscriptions SET renew_order_id=? WHERE id=? AND renew_order_id IS NULL", [order["id"], subscription_id])
          @db.one("SELECT * FROM orders WHERE id=?", [order["id"]])
        end
      end

      def audit(actor, action, subject)
        @db.execute("INSERT INTO audit_log VALUES(?,?,?,?,?)", [Infrastructure::Database.id, actor, action, subject, Time.now.to_i])
      end

      private

      def enqueue_payment_create(order_id)
        @outbox.enqueue("payment.create", "checkout:#{order_id}", { "order_id" => order_id })
      end

      def create_subscription(order, now)
        return @subscriptions.create_from_order(order, now: now) if @subscriptions

        subscription_id = Infrastructure::Database.id
        @db.execute(
          "INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,traffic_limit_gb,device_limit,traffic_used_gb,plan_id,lifecycle_status,starts_at,traffic_limit_bytes,updated_at) " \
          "VALUES(?,?,?,'active',?,?,?, ?,0,?,'active',?,?,?)",
          [
            subscription_id, order["id"], order["user_id"], now + order["duration_days"].to_i * 86_400, now,
            order["traffic_bytes"].to_i / 1_073_741_824, order["devices"].to_i, order["plan_id"],
            now, order["traffic_bytes"].to_i, now
          ]
        )
        @db.execute("UPDATE orders SET status='fulfilled' WHERE id=?", [order["id"]])
        subscription_id
      rescue StandardError
        @db.execute(
          "INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at) VALUES(?,?,?,'active',?,?)",
          [subscription_id, order["id"], order["user_id"], now + order["duration_days"].to_i * 86_400, now]
        )
        @db.execute("UPDATE orders SET status='fulfilled' WHERE id=?", [order["id"]])
        subscription_id
      end

      def renew_subscription(subscription, order, now)
        expires_at = [now, subscription["expires_at"].to_i].max + order["duration_days"].to_i * 86_400
        renew_at = now + [60, order["duration_days"].to_i * 86_400 * 2 / 3].max
        @db.execute("UPDATE subscriptions SET expires_at=?,renew_order_id=NULL,renew_failed_at=NULL,renew_fail_count=0,renew_at=?,updated_at=? WHERE id=?", [expires_at, subscription["auto_renew"].to_i == 1 ? renew_at : nil, now, subscription["id"]])
        @db.execute("UPDATE orders SET status='fulfilled' WHERE id=?", [order["id"]])
        @outbox.enqueue("subscription.extend", "renew-extend:#{subscription["id"]}:#{order["id"]}", { "subscription_id" => subscription["id"], "order_id" => order["id"] })
        subscription["id"]
      end
    end
  end
end
