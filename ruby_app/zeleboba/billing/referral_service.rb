# frozen_string_literal: true

require "securerandom"

require_relative "error"
require_relative "../infrastructure/database"

module Zeleboba
  module Billing
    class ReferralService
      def initialize(db, outbox, wallet, config)
        @db = db
        @outbox = outbox
        @wallet = wallet
        @config = config
      end

      def ensure_code(user_id)
        current = @db.one("SELECT referral_code FROM users WHERE id=?", [user_id])
        return current["referral_code"] if current && !current["referral_code"].to_s.empty?

        loop do
          code = SecureRandom.hex(4).upcase
          next if @db.one("SELECT id FROM users WHERE referral_code=?", [code])

          @db.execute("UPDATE users SET referral_code=? WHERE id=?", [code, user_id])
          return code
        end
      end

      def attach_referrer(user_id, code)
        user = @db.one("SELECT * FROM users WHERE id=?", [user_id])
        return nil unless user && user["referred_by_id"].nil?
        referrer = @db.one("SELECT * FROM users WHERE referral_code=?", [code.to_s.strip.upcase])
        return nil unless referrer && referrer["id"] != user_id

        @db.execute("UPDATE users SET referred_by_id=? WHERE id=? AND referred_by_id IS NULL", [referrer["id"], user_id])
        referrer["id"]
      end

      def process_settled_topup(topup_id)
        @db.transaction do
          topup = @db.one("SELECT * FROM topups WHERE id=?#{@db.lock}", [topup_id])
          return unless topup && topup["status"] == "paid" && topup["referral_processed"].to_i.zero?

          process_topup(topup["user_id"], topup["amount_kopeks"].to_i, topup["referral_first"].to_i == 1)
          @db.execute("UPDATE topups SET referral_processed=1 WHERE id=?", [topup_id])
        end
      end

      def stats(user_id)
        code = ensure_code(user_id)
        referrals = @db.all("SELECT id,email,telegram_id,created_at,has_made_first_topup FROM users WHERE referred_by_id=? ORDER BY created_at DESC", [user_id])
        earnings = @db.one("SELECT COALESCE(SUM(amount_kopeks),0) AS s FROM referral_earnings WHERE user_id=?", [user_id]).fetch("s", 0).to_i
        { "code" => code, "referrals" => referrals, "earnings_kopeks" => earnings, "paid_referrals" => referrals.count { |row| row["has_made_first_topup"].to_i == 1 } }
      end

      def withdrawals(user_id)
        @db.all("SELECT * FROM withdrawal_requests WHERE user_id=? ORDER BY created_at DESC LIMIT 20", [user_id])
      end

      def request_withdrawal(user_id, amount_kopeks, details)
        raise Error, "Вывод средств отключён." unless @config.fetch("REFERRAL_WITHDRAWAL_ENABLED", "0") == "1"
        minimum = @config.fetch("REFERRAL_WITHDRAWAL_MIN_AMOUNT_KOPEKS", "100000").to_i
        raise Error, "Минимальная сумма вывода: #{minimum / 100} ₽." if amount_kopeks < minimum
        raise Error, "Укажите реквизиты для вывода (5-500 символов)." unless details.to_s.strip.length.between?(5, 500)

        @db.transaction do
          existing = @db.one("SELECT created_at FROM withdrawal_requests WHERE user_id=? AND status IN ('pending','approved') ORDER BY created_at DESC LIMIT 1", [user_id])
          cooldown = @config.fetch("REFERRAL_WITHDRAWAL_COOLDOWN_DAYS", "30").to_i * 86_400
          raise Error, "Заявка на вывод уже подана. Попробуйте позже." if existing && Time.now.to_i - existing["created_at"].to_i < cooldown
          earned = @db.one("SELECT COALESCE(SUM(amount_kopeks),0) AS s FROM referral_earnings WHERE user_id=?", [user_id]).fetch("s", 0).to_i
          reserved = @db.one("SELECT COALESCE(SUM(amount_kopeks),0) AS s FROM withdrawal_requests WHERE user_id=? AND status IN ('pending','approved','paid')", [user_id]).fetch("s", 0).to_i
          raise Error, "Недостаточно реферального баланса." if amount_kopeks > earned - reserved

          @wallet.debit(user_id, amount_kopeks, "referral_withdrawal", "Резерв средств на вывод")
          id = Infrastructure::Database.id
          now = Time.now.to_i
          @db.execute("INSERT INTO withdrawal_requests(id,user_id,amount_kopeks,status,payment_details,risk_score,created_at,updated_at,wallet_reserved) VALUES(?,?,?,'pending',?,0,?,?,1)", [id, user_id, amount_kopeks, details.to_s.strip, now, now])
          @db.one("SELECT * FROM withdrawal_requests WHERE id=?", [id])
        end
      end

      private

      def process_topup(user_id, amount, first)
        user = @db.one("SELECT * FROM users WHERE id=?", [user_id])
        referrer = user && user["referred_by_id"] && @db.one("SELECT * FROM users WHERE id=?", [user["referred_by_id"]])
        return unless referrer

        percent = referrer["referral_commission_percent"].to_i
        percent = @config.fetch("REFERRAL_COMMISSION_PERCENT", "25").to_i if percent.zero?
        commission = amount * percent / 100
        bonus = first && amount >= @config.fetch("REFERRAL_MINIMUM_TOPUP_KOPEKS", "10000").to_i ? @config.fetch("REFERRAL_INVITER_BONUS_KOPEKS", "10000").to_i : 0
        total = commission + bonus
        return if total.zero?

        @wallet.credit(referrer["id"], total, "referral_reward", "Вознаграждение за пополнение реферала")
        @db.execute("INSERT INTO referral_earnings VALUES(?,?,?,?,?,?)", [Infrastructure::Database.id, referrer["id"], user_id, total, first ? "referral_first_topup" : "referral_commission_topup", Time.now.to_i])
      end
    end
  end
end
