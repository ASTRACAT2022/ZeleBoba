# frozen_string_literal: true

require_relative "error"
require_relative "../infrastructure/database"

module Zeleboba
  module Billing
    class Wallet
      TYPES = %w[
        balance_topup subscription_purchase subscription_renewal subscription_daily trial_conversion
        referral_reward referral_withdrawal traffic_topup device_addon gift_purchase promo_credit manual_adjust refund
      ].freeze

      def initialize(db)
        @db = db
      end

      def credit(user_id, amount_kopeks, type, description, payment_method = nil, external_id = nil)
        change(user_id, amount_kopeks, type, description, payment_method, external_id)
      end

      def debit(user_id, amount_kopeks, type, description, payment_method = nil, external_id = nil)
        change(user_id, -amount_kopeks, type, description, payment_method, external_id)
      end

      def balance(user_id)
        row = @db.one("SELECT balance_kopeks FROM users WHERE id=?", [user_id])
        { "balance_kopeks" => row&.fetch("balance_kopeks", 0).to_i }
      end

      def history(user_id, limit = 50)
        @db.all("SELECT * FROM transactions WHERE user_id=? ORDER BY seq DESC LIMIT ?", [user_id, limit])
      end

      private

      def change(user_id, delta, type, description, payment_method, external_id)
        amount = delta.abs
        raise Error, "Сумма должна быть положительной." unless amount.positive?
        raise Error, "Некорректный тип операции." unless TYPES.include?(type)

        @db.transaction do
          external_id = normalize_external_id(external_id)
          return balance(user_id) if external_id && idempotent_replay?(user_id, type, external_id, delta)

          user = @db.one("SELECT balance_kopeks FROM users WHERE id=?#{@db.lock}", [user_id])
          raise Error, "Аккаунт не найден." unless user
          raise Error, "Недостаточно средств на балансе." if delta.negative? && user["balance_kopeks"].to_i < amount

          @db.execute("UPDATE users SET balance_kopeks = balance_kopeks + ? WHERE id=?", [delta, user_id])
          now = Time.now.to_i
          transaction_id = Infrastructure::Database.id
          @db.execute(
            "INSERT INTO transactions(id,seq,user_id,type,amount_kopeks,description,payment_method,external_id,is_completed,created_at,completed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
            [transaction_id, next_seq, user_id, type, delta, description, payment_method, external_id, 1, now, now]
          )
          record_ledger(transaction_id, user_id, type, delta, now) if table_exists?("wallet_ledger_entries")
          balance(user_id)
        end
      end

      def normalize_external_id(external_id)
        value = external_id.to_s.strip
        return nil if value.empty?
        raise Error, "Некорректный ключ операции." if value.length > 100

        value
      end

      def idempotent_replay?(user_id, type, external_id, amount)
        existing = @db.one("SELECT amount_kopeks FROM transactions WHERE user_id=? AND type=? AND external_id=?", [user_id, type, external_id])
        return false unless existing
        raise Error, "Этот ключ уже использован для другой суммы." unless existing["amount_kopeks"].to_i == amount

        true
      end

      def next_seq
        @db.one("SELECT COALESCE(MAX(seq),0)+1 AS s FROM transactions").fetch("s", 1).to_i
      end

      def record_ledger(transaction_id, user_id, type, amount, created_at)
        contra = amount.positive? ? "wallet:source:#{type}" : "wallet:sink:#{type}"
        [["wallet:user:#{user_id}", amount, "u"], [contra, -amount, "c"]].each do |account, ledger_amount, suffix|
          @db.execute(
            "INSERT INTO wallet_ledger_entries(id,transaction_id,account,amount_kopeks,currency,created_at) VALUES(?,?,?,?,?,?) ON CONFLICT(transaction_id,account) DO NOTHING",
            ["#{transaction_id}#{suffix}", transaction_id, account, ledger_amount, "RUB", created_at]
          )
        end
      end

      def table_exists?(name)
        !!@db.one("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [name])
      end
    end
  end
end
