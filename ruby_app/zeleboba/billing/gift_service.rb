# frozen_string_literal: true

require "securerandom"

require_relative "error"
require_relative "../infrastructure/database"

module Zeleboba
  module Billing
    class GiftService
      TOKEN_LENGTH = 64
      PUBLIC_LENGTH = 59

      def initialize(db, outbox, wallet, config)
        @db = db
        @outbox = outbox
        @wallet = wallet
        @config = config
      end

      def enabled?
        @config.fetch("CABINET_GIFT_ENABLED", "0") == "1"
      end

      def purchase_from_balance(buyer_id, plan_id, key, recipient_type: nil, recipient_value: nil, message: nil)
        raise Error, "Подарки отключены." unless enabled?
        raise Error, "Некорректный ключ операции." unless key.to_s.match?(/\A[a-zA-Z0-9:_-]{8,64}\z/)

        @db.transaction do
          existing = @db.one("SELECT * FROM guest_purchases WHERE idempotency_key=?", [key])
          if existing
            return existing if existing["buyer_user_id"] == buyer_id && existing["plan_id"] == plan_id

            raise Error, "Ключ уже использован с другими параметрами."
          end

          plan = @db.one("SELECT * FROM plans WHERE id=? AND active=1#{@db.lock}", [plan_id])
          raise Error, "Тариф недоступен." unless plan
          buyer = @db.one("SELECT * FROM users WHERE id=?#{@db.lock}", [buyer_id])
          raise Error, "Аккаунт не найден." unless buyer

          price = plan["price_minor"].to_i
          raise Error, "Недостаточно средств на балансе." if buyer["balance_kopeks"].to_i < price

          @wallet.debit(buyer_id, price, "gift_purchase", "Подарочная подписка: #{plan["name"]}", "balance", key)
          id = Infrastructure::Database.id
          token = SecureRandom.urlsafe_base64(48, false)
          now = Time.now.to_i
          contact_value = buyer["email"].to_s.empty? ? buyer["telegram_id"].to_s : buyer["email"]
          raise Error, "Добавьте e-mail или Telegram для покупки подарка." if contact_value.empty?

          @db.execute(
            "INSERT INTO guest_purchases(id,token,contact_type,contact_value,is_gift,source,buyer_user_id,gift_recipient_type,gift_recipient_value,gift_message,plan_id,period_days,traffic_bytes,device_limit,amount_kopeks,currency,payment_method,status,created_at,paid_at,idempotency_key) VALUES(?,?,?,?,1,'cabinet',?,?,?,?,?,?,?,?,?,'RUB','balance','paid',?,?,?)",
            [id, token, buyer["email"].to_s.empty? ? "telegram" : "email", contact_value, buyer_id, blank_to_nil(recipient_type), blank_to_nil(recipient_value), blank_to_nil(message), plan["id"], plan["duration_days"].to_i, plan["traffic_bytes"].to_i, plan["devices"].to_i, price, now, now, key]
          )
          audit(buyer_id, "gift.purchased", id)
          @db.one("SELECT * FROM guest_purchases WHERE id=?", [id])
        end
      end

      def claim(claimant_id, input)
        prefix = parse_claim_input(input)
        raise Error, "Подарок не найден." unless prefix&.match?(/\A[a-zA-Z0-9_-]{59}(?:[a-zA-Z0-9_-]{5})?\z/)

        @db.transaction do
          gift = @db.one("SELECT * FROM guest_purchases WHERE is_gift=1 AND substr(token,1,?)=?#{@db.lock}", [prefix.length, prefix])
          raise Error, "Подарок не найден." unless gift
          raise Error, "Нельзя активировать собственный подарок." if gift["buyer_user_id"] == claimant_id
          raise Error, "Подарок уже активирован другим пользователем." if gift["user_id"] && gift["user_id"] != claimant_id
          return gift if gift["status"] == "delivered" && gift["user_id"] == claimant_id
          raise Error, "Подарок нельзя активировать." unless %w[paid pending_activation].include?(gift["status"])

          now = Time.now.to_i
          subscription_id = Infrastructure::Database.id
          @db.execute(
            "INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,plan_id,traffic_limit_gb,device_limit,is_trial,start_date,traffic_limit_bytes,updated_at,lifecycle_status) VALUES(?,?,?,'provisioning',?,?,?,?,?,0,?,?,?,'pending')",
            [subscription_id, nil, claimant_id, now + gift["period_days"].to_i * 86_400, now, gift["plan_id"], gift["traffic_bytes"].to_i / 1_073_741_824, gift["device_limit"].to_i, now, gift["traffic_bytes"].to_i, now]
          )
          @db.execute("INSERT INTO provisioning_accounts(id,subscription_id,provider,state,created_at,updated_at) VALUES(?,?,?,'pending',?,?) ON CONFLICT(subscription_id,provider) DO NOTHING", [Infrastructure::Database.id, subscription_id, "demo", now, now])
          @db.execute("UPDATE guest_purchases SET status='delivered',user_id=?,delivered_at=? WHERE id=?", [claimant_id, now, gift["id"]])
          @outbox.enqueue("subscription.provision", "provision:#{subscription_id}", { "subscription_id" => subscription_id })
          audit(claimant_id, "gift.claimed", gift["id"])
          @db.one("SELECT * FROM guest_purchases WHERE id=?", [gift["id"]])
        end
      end

      def bought_by(user_id)
        @db.all("SELECT * FROM guest_purchases WHERE buyer_user_id=? AND is_gift=1 ORDER BY created_at DESC", [user_id])
      end

      def received_by(user_id)
        @db.all("SELECT * FROM guest_purchases WHERE user_id=? AND is_gift=1 ORDER BY created_at DESC", [user_id])
      end

      def public_code(token)
        "GIFT_#{token.to_s[0, PUBLIC_LENGTH]}"
      end

      private

      def parse_claim_input(value)
        raw = value.to_s.strip
        return nil if raw.empty?
        raw = raw.split("start=", 2).last.split(/[&#]/, 2).first if raw.include?("start=")
        raw = raw.sub(/\Agiftclaim[_-]/i, "")
        raw = raw.sub(/\AGIFT[_-]/i, "")
        raw.match?(/\A[a-zA-Z0-9_-]{59}(?:[a-zA-Z0-9_-]{5})?\z/) ? raw : nil
      end

      def blank_to_nil(value)
        text = value.to_s.strip
        text.empty? ? nil : text[0, 500]
      end

      def audit(actor, action, subject)
        @db.execute("INSERT INTO audit_log VALUES(?,?,?,?,?)", [Infrastructure::Database.id, actor, action, subject, Time.now.to_i])
      end
    end
  end
end
