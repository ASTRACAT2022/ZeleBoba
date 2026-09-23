# frozen_string_literal: true

require_relative "error"
require_relative "../infrastructure/database"

module Zeleboba
  module Billing
    class PromoCodeService
      TYPES = %w[balance subscription_days trial_subscription discount balance_and_days].freeze

      def initialize(db, outbox, wallet)
        @db = db
        @outbox = outbox
        @wallet = wallet
      end

      def activate(user_id, code)
        normalized = code.to_s.strip.upcase
        return failure("not_found") unless normalized.match?(/\A[A-Z0-9_-]{3,50}\z/)

        @db.transaction do
          user = @db.one("SELECT * FROM users WHERE id=?#{@db.lock}", [user_id])
          return failure("user_not_found") unless user
          promo = @db.one("SELECT * FROM promocodes WHERE code=?#{@db.lock}", [normalized])
          return failure("not_found") unless promo
          now = Time.now.to_i
          return failure("inactive") unless promo["is_active"].to_i == 1
          return failure("used") if promo["current_uses"].to_i >= promo["max_uses"].to_i
          return failure("not_yet_valid") if promo["valid_from"].to_i > now
          return failure("expired") if promo["valid_until"] && promo["valid_until"].to_i < now
          return failure("already_used_by_user") if @db.one("SELECT id FROM promocode_uses WHERE user_id=? AND promocode_id=?", [user_id, promo["id"]])
          return failure("daily_limit") if @db.one("SELECT COUNT(*) AS c FROM promocode_uses WHERE user_id=? AND used_at>?", [user_id, now - 86_400]).fetch("c", 0).to_i >= 5
          return failure("not_first_purchase") if promo["first_purchase_only"].to_i == 1 && user["has_had_paid_subscription"].to_i == 1

          @db.execute("UPDATE promocodes SET current_uses=current_uses+1 WHERE id=?", [promo["id"]])
          @db.execute("INSERT INTO promocode_uses VALUES(?,?,?,?)", [Infrastructure::Database.id, promo["id"], user_id, now])
          description = apply_effects(user, promo, now)
          @db.execute("INSERT INTO audit_log VALUES(?,?,?,?,?)", [Infrastructure::Database.id, user_id, "promocode.activated", normalized, now])
          { "success" => true, "description" => description }
        rescue Error => e
          { "success" => false, "error" => e.message }
        end
      end

      def create(input, actor)
        code = input.fetch("code", "").to_s.strip.upcase
        type = input.fetch("type", "").to_s
        balance = input.fetch("balance_bonus_kopeks", 0).to_i
        days = input.fetch("subscription_days", 0).to_i
        max_uses = input.fetch("max_uses", 1).to_i
        raise Error, "Код: 3-50 символов A-Z, 0-9, _ или -." unless code.match?(/\A[A-Z0-9_-]{3,50}\z/)
        raise Error, "Некорректный тип промокода." unless TYPES.include?(type)
        raise Error, "Проверьте параметры промокода." if balance.negative? || days.negative? || max_uses < 1

        @db.transaction do
          raise Error, "Такой код уже существует." if @db.one("SELECT id FROM promocodes WHERE code=?", [code])
          id = Infrastructure::Database.id
          now = Time.now.to_i
          @db.execute("INSERT INTO promocodes(id,code,type,balance_bonus_kopeks,subscription_days,traffic_gb,max_uses,current_uses,valid_from,valid_until,is_active,first_purchase_only,plan_id,created_by,created_at) VALUES(?,?,?,?,?,?,?,0,?,?,1,?,?,?,?)", [id, code, type, balance, days, input.fetch("traffic_gb", 0).to_i, max_uses, now, blank_to_nil(input["valid_until"]), input.fetch("first_purchase_only", 0).to_i, blank_to_nil(input["plan_id"]), actor, now])
          @db.one("SELECT * FROM promocodes WHERE id=?", [id])
        end
      end

      def list(limit = 100)
        @db.all("SELECT * FROM promocodes ORDER BY created_at DESC LIMIT ?", [limit])
      end

      def toggle(id, active, actor)
        @db.execute("UPDATE promocodes SET is_active=? WHERE id=?", [active ? 1 : 0, id])
        @db.execute("INSERT INTO audit_log VALUES(?,?,?,?,?)", [Infrastructure::Database.id, actor, active ? "promocode.enabled" : "promocode.disabled", id, Time.now.to_i])
      end

      private

      def apply_effects(user, promo, now)
        effects = []
        type = promo["type"]
        if type == "discount"
          raise Error, "active_discount_exists" if user["promo_offer_discount_percent"].to_i.positive? && (user["promo_offer_discount_expires_at"].nil? || user["promo_offer_discount_expires_at"].to_i > now)

          percent = promo["balance_bonus_kopeks"].to_i
          raise Error, "Некорректная скидка." unless percent.between?(1, 99)
          hours = promo["subscription_days"].to_i
          @db.execute("UPDATE users SET promo_offer_discount_percent=?,promo_offer_discount_source=?,promo_offer_discount_expires_at=? WHERE id=?", [percent, "promocode:#{promo["code"]}", hours.positive? ? now + hours * 3600 : nil, user["id"]])
          effects << "Скидка #{percent}%#{hours.positive? ? " на #{hours} ч." : " до первой покупки"}"
        end

        if %w[subscription_days balance_and_days].include?(type) && promo["subscription_days"].to_i.positive?
          subscription = @db.one("SELECT * FROM subscriptions WHERE user_id=? AND status IN ('active','trial') ORDER BY created_at DESC LIMIT 1", [user["id"]])
          raise Error, "no_subscription_for_days" unless subscription

          expires = [now, subscription["expires_at"].to_i].max + promo["subscription_days"].to_i * 86_400
          @db.execute("UPDATE subscriptions SET expires_at=?,status='active',updated_at=? WHERE id=?", [expires, now, subscription["id"]])
          @outbox.enqueue("subscription.extend", "extend:#{subscription["id"]}:#{promo["id"]}", { "subscription_id" => subscription["id"] })
          effects << "Подписка продлена на #{promo["subscription_days"]} дн."
        end

        if %w[balance balance_and_days].include?(type) && promo["balance_bonus_kopeks"].to_i.positive?
          @wallet.credit(user["id"], promo["balance_bonus_kopeks"].to_i, "promo_credit", "Бонус по промокоду #{promo["code"]}", "promo", promo["id"])
          effects << "Баланс пополнен на #{promo["balance_bonus_kopeks"].to_i / 100} ₽"
        end

        if type == "trial_subscription"
          plan = @db.one("SELECT * FROM plans WHERE id=? AND active=1", [promo["plan_id"]])
          raise Error, "trial_subscription_exists" unless plan && promo["subscription_days"].to_i.positive?
          existing = @db.one("SELECT * FROM subscriptions WHERE user_id=? AND status IN ('active','trial') ORDER BY created_at DESC LIMIT 1", [user["id"]])
          if existing
            @db.execute("UPDATE subscriptions SET expires_at=? WHERE id=?", [[now, existing["expires_at"].to_i].max + promo["subscription_days"].to_i * 86_400, existing["id"]])
          else
            id = Infrastructure::Database.id
            @db.execute("INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,plan_id,traffic_limit_gb,device_limit,is_trial,start_date,traffic_limit_bytes,updated_at,lifecycle_status) VALUES(?,?,?,'provisioning',?,?,?,?,?,1,?,?,?,'pending')", [id, nil, user["id"], now + promo["subscription_days"].to_i * 86_400, now, plan["id"], plan["traffic_bytes"].to_i / 1_073_741_824, plan["devices"].to_i, now, plan["traffic_bytes"].to_i, now])
            @db.execute("INSERT INTO provisioning_accounts(id,subscription_id,provider,state,created_at,updated_at) VALUES(?,?,?,'pending',?,?) ON CONFLICT(subscription_id,provider) DO NOTHING", [Infrastructure::Database.id, id, "demo", now, now])
            @outbox.enqueue("subscription.provision", "provision:#{id}", { "subscription_id" => id })
          end
          effects << "Активирована тестовая подписка на #{promo["subscription_days"]} дн."
        end
        effects.empty? ? "Промокод активирован" : effects.join(". ")
      end

      def failure(error)
        { "success" => false, "error" => error }
      end

      def blank_to_nil(value)
        value.to_s.strip.empty? ? nil : value
      end
    end
  end
end
