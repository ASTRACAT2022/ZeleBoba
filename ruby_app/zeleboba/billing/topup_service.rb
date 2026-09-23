# frozen_string_literal: true

require_relative "error"
require_relative "../infrastructure/database"

module Zeleboba
  module Billing
    class TopupService
      def initialize(db, outbox, wallet, config)
        @db = db
        @outbox = outbox
        @wallet = wallet
        @config = config
      end

      def create(user_id, amount_kopeks, key, provider = nil)
        raise Error, "Сумма пополнения: от 1 до 1 000 000 ₽." unless amount_kopeks.between?(100, 100_000_000)
        raise Error, "Некорректный ключ операции." unless key.to_s.match?(/\A[a-zA-Z0-9:_-]{8,128}\z/)
        effective_provider = provider.to_s.empty? ? @config["PAYMENT_DRIVER"] : provider.to_s
        raise Error, "Некорректный платёжный провайдер." unless %w[demo platega].include?(effective_provider)
        raise Error, "Демоплатёж запрещён." if effective_provider == "demo" && @config["APP_ENV"] == "prod"

        @db.transaction do
          raise Error, "Аккаунт не найден." unless @db.one("SELECT id FROM users WHERE id=? AND disabled=0#{@db.lock}", [user_id])
          existing = @db.one("SELECT * FROM topups WHERE user_id=? AND idempotency_key=?", [user_id, key])
          if existing
            return existing if existing["amount_kopeks"].to_i == amount_kopeks && existing["provider"] == effective_provider

            raise Error, "Этот ключ уже использован для другой суммы."
          end

          id = Infrastructure::Database.id
          now = Time.now.to_i
          @db.execute("INSERT INTO topups(id,user_id,amount_kopeks,currency,status,provider,idempotency_key,created_at) VALUES(?,?,?,'RUB','pending',?,?,?)", [id, user_id, amount_kopeks, effective_provider, key, now])
          @outbox.enqueue("topup.create", "topup-checkout:#{id}", { "topup_id" => id })
          audit(user_id, "topup.created", id)
          @db.one("SELECT * FROM topups WHERE id=?", [id])
        end
      end

      def settle(topup_id, provider, payment_id, amount, currency)
        @db.transaction do
          topup = @db.one("SELECT * FROM topups WHERE id=?#{@db.lock}", [topup_id])
          valid = topup && topup["provider"] == provider && topup["amount_kopeks"].to_i == amount.to_i && topup["currency"] == currency
          raise Error, "Платёж не соответствует пополнению." unless valid
          return if topup["status"] == "paid"
          raise Error, "Пополнение уже отменено." unless topup["status"] == "pending"
          raise Error, "Платёж уже использован для заказа." if @db.one("SELECT order_id FROM payment_receipts WHERE provider=? AND payment_id=?", [provider, payment_id])
          duplicate = @db.one("SELECT id FROM topups WHERE provider=? AND provider_payment_id=? AND id<>?", [provider, payment_id, topup_id])
          raise Error, "Платёж уже принадлежит другому пополнению." if duplicate

          now = Time.now.to_i
          first = @db.one("SELECT has_made_first_topup FROM users WHERE id=?", [topup["user_id"]]).fetch("has_made_first_topup", 0).to_i.zero? ? 1 : 0
          @db.execute("UPDATE topups SET status='paid',provider_payment_id=?,paid_at=?,referral_first=? WHERE id=?", [payment_id, now, first, topup_id])
          @wallet.credit(topup["user_id"], amount.to_i, "balance_topup", "Пополнение баланса", provider, payment_id)
          @db.execute("UPDATE users SET has_made_first_topup=1 WHERE id=?", [topup["user_id"]])
          @outbox.enqueue("topup.after", "topup-after:#{topup_id}", { "topup_id" => topup_id, "user_id" => topup["user_id"] })
          audit("provider:#{provider}", "topup.settled", topup_id)
          @db.one("SELECT * FROM topups WHERE id=?", [topup_id])
        end
      end

      private

      def audit(actor, action, subject)
        @db.execute("INSERT INTO audit_log VALUES(?,?,?,?,?)", [Infrastructure::Database.id, actor, action, subject, Time.now.to_i])
      end
    end
  end
end
