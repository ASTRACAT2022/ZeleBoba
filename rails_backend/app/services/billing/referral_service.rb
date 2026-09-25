module Billing
  class ReferralService
    def initialize(config: Infrastructure::RuntimeConfig.new.values)
      @config = config
    end

    def ensure_code(user_id)
      User.transaction do
        user = User.lock.find(user_id)
        next user.referral_code if user.referral_code.present?
        loop do
          code = SecureRandom.hex(4).upcase
          next if User.exists?(referral_code: code)
          user.update!(referral_code: code)
          break code
        end
      end
    end

    def attach_referrer(user_id, code)
      User.transaction do
        user = User.lock.find_by(id: user_id)
        next nil unless user && user.referred_by_id.blank?
        referrer = User.find_by(referral_code: code.to_s.strip.upcase)
        next nil unless referrer && referrer.id != user.id
        next nil if referrer.email.present? && user.email.present? && referrer.email.casecmp?(user.email)
        user.update!(referred_by_id: referrer.id)
        audit(user.id, "referral.attached", referrer.id)
        referrer.id
      end
    end

    def stats(user_id)
      code = ensure_code(user_id)
      { code: code,
        referrals: User.where(referred_by_id: user_id).order(created_at: :desc).limit(100)
          .as_json(only: %i[id email telegram_id created_at has_made_first_topup]),
        earnings_kopeks: ReferralEarning.where(user_id: user_id).sum(:amount_kopeks).to_i,
        paid_referrals: User.where(referred_by_id: user_id, has_made_first_topup: 1).count }
    end

    def process_topup(user_id, amount_kopeks, first_payment: nil)
      User.transaction do
        user = User.lock.find_by(id: user_id)
        next unless user&.referred_by_id
        referrer = User.lock.find_by(id: user.referred_by_id)
        next unless referrer
        is_first_topup = first_payment.nil? ? user.has_made_first_topup.to_i.zero? : first_payment
        eligible_first = amount_kopeks.to_i >= config_int("REFERRAL_MINIMUM_TOPUP_KOPEKS", 10_000)
        is_first = is_first_topup && eligible_first
        if is_first && first_payment.nil?
          user.update!(has_made_first_topup: 1)
        end
        percent = commission_percent(referrer, is_first_topup)
        commission = amount_kopeks.to_i * percent / 100
        if is_first
          award(user.id, config_int("REFERRAL_FIRST_TOPUP_BONUS_KOPEKS", 10_000), user.id, "Бонус за первое пополнение по реферальной программе")
          total = config_int("REFERRAL_INVITER_BONUS_KOPEKS", 10_000) + commission
          if total.positive?
            award(referrer.id, total, user.id, "Бонус за первое пополнение реферала")
            ReferralEarning.create!(id: Infrastructure::IdGenerator.call, user_id: referrer.id, referral_id: user.id, amount_kopeks: total, reason: "referral_first_topup", created_at: Time.now.to_i)
          end
        elsif commission.positive? && !commission_limit_reached?(referrer.id, user.id)
          award(referrer.id, commission, user.id, "Комиссия #{percent}% с пополнения")
          ReferralEarning.create!(id: Infrastructure::IdGenerator.call, user_id: referrer.id, referral_id: user.id, amount_kopeks: commission, reason: "referral_commission_topup", created_at: Time.now.to_i)
        end
      end
    end

    def process_settled_topup(topup_id)
      Topup.transaction do
        topup = Topup.lock.find_by(id: topup_id)
        next unless topup && topup.status == "paid" && topup.referral_processed.to_i != 1
        process_topup(topup.user_id, topup.amount_kopeks.to_i, first_payment: topup.referral_first.to_i == 1)
        topup.update!(referral_processed: 1)
      end
    end

    def withdrawals(user_id)
      WithdrawalRequest.where(user_id: user_id).order(created_at: :desc).limit(20)
    end

    def request_withdrawal(user_id, amount_kopeks, details)
      raise BillingError, "Вывод средств отключён." unless @config["REFERRAL_WITHDRAWAL_ENABLED"] == "1"
      raise BillingError, "Минимальная сумма вывода: #{config_int('REFERRAL_WITHDRAWAL_MIN_AMOUNT_KOPEKS', 100_000) / 100.0} ₽." if amount_kopeks.to_i < config_int("REFERRAL_WITHDRAWAL_MIN_AMOUNT_KOPEKS", 100_000)
      details = details.to_s.strip
      raise BillingError, "Укажите реквизиты для вывода (5–500 символов)." unless details.length.between?(5, 500)

      User.transaction do
        User.lock.find(user_id)
        cooldown = config_int("REFERRAL_WITHDRAWAL_COOLDOWN_DAYS", 30) * 86_400
        recent = WithdrawalRequest.where(user_id: user_id, status: %w[pending approved]).order(created_at: :desc).first
        raise BillingError, "Заявка на вывод уже подана. Попробуйте позже." if recent && Time.now.to_i - recent.created_at.to_i < cooldown
        earnings = ReferralEarning.where(user_id: user_id).sum(:amount_kopeks).to_i
        reserved = WithdrawalRequest.where(user_id: user_id, status: %w[pending approved paid]).sum(:amount_kopeks).to_i
        raise BillingError, "Недостаточно реферального баланса. Доступно: #{(earnings - reserved) / 100.0} ₽." if amount_kopeks.to_i > earnings - reserved
        Billing::WalletService.new.debit(user_id, amount_kopeks, "referral_withdrawal", "Резерв средств на вывод")
        now = Time.now.to_i
        row = WithdrawalRequest.create!(id: Infrastructure::IdGenerator.call, user_id: user_id, amount_kopeks: amount_kopeks, status: "pending", payment_details: details, risk_score: risk_score(user_id), wallet_reserved: 1, created_at: now, updated_at: now)
        audit(user_id, "withdrawal.requested", row.id)
        row
      end
    end

    def process_withdrawal(id, status, admin_id, comment: nil)
      raise BillingError, "Некорректный статус." unless %w[approved rejected paid].include?(status)
      WithdrawalRequest.transaction do
        row = WithdrawalRequest.lock.find_by(id: id)
        raise BillingError, "Заявка не найдена." unless row
        next row if row.status == status
        raise BillingError, "Заявка уже обработана." unless %w[pending approved].include?(row.status) && !(row.status == "approved" && status == "approved")
        # Legacy requests may predate balance reservation. Reserve their funds
        # exactly once before approval/payment, matching the PHP flow. The row
        # lock and transaction make the debit and marker update atomic; a retry
        # after approval sees wallet_reserved=1 and cannot debit again.
        if status != "rejected" && row.wallet_reserved.to_i.zero?
          Billing::WalletService.new.debit(row.user_id, row.amount_kopeks,
            "referral_withdrawal", "Резерв средств на вывод")
          row.update!(wallet_reserved: 1)
        end
        if status == "rejected" && row.wallet_reserved.to_i == 1
          Billing::WalletService.new.credit(row.user_id, row.amount_kopeks, "refund", "Возврат резерва отклонённой заявки")
        end
        row.update!(status: status, processed_by: admin_id, processed_at: Time.now.to_i, admin_comment: comment, updated_at: Time.now.to_i)
        audit(admin_id, "withdrawal.#{status}", row.id)
        row
      end
    end

    private

    def commission_percent(referrer, first)
      first_rate = @config["REFERRAL_FIRST_PAYMENT_COMMISSION_PERCENT"]
      return first_rate.to_i if first && first_rate.present?
      base = referrer.referral_commission_percent.presence || config_int("REFERRAL_COMMISSION_PERCENT", 25)
      tiers = @config["REFERRAL_RECURRING_COMMISSION_TIERS"].to_s
      return base.to_i if tiers.blank?
      paid = ReferralEarning.where(user_id: referrer.id, reason: %w[referral_first_topup referral_commission_topup]).select(:referral_id).distinct.count
      selected = base.to_i
      tiers.split(",").each do |tier|
        threshold, percent = tier.split(":", 2).map(&:to_i)
        break if paid < threshold
        selected = percent
      end
      selected
    end

    def commission_limit_reached?(user_id, referral_id)
      max = config_int("REFERRAL_MAX_COMMISSION_PAYMENTS", 0)
      max.positive? && ReferralEarning.where(user_id: user_id, referral_id: referral_id, reason: "referral_commission_topup").count >= max
    end

    def award(user_id, amount, referral_id, description)
      return unless amount.to_i.positive?
      Billing::WalletService.new.credit(user_id, amount, "referral_reward", description)
    end

    def risk_score(user_id)
      score = 0
      User.where(referred_by_id: user_id).pluck(:id).each do |referral_id|
        paid_topups = Topup.where(user_id: referral_id, status: "paid")
        count = paid_topups.where("paid_at > ?", Time.now.to_i - 30.days.to_i).count
        score += 30 if count > config_int("REFERRAL_WITHDRAWAL_SUSPICIOUS_MAX_DEPOSITS_PER_MONTH", 10)
        total = paid_topups.sum(:amount_kopeks).to_i
        score += 20 if total >= config_int("REFERRAL_WITHDRAWAL_SUSPICIOUS_MIN_DEPOSIT_KOPEKS", 50_000)
      end
      [score, 100].min
    end

    def config_int(key, fallback)
      @config[key].presence&.to_i || fallback
    end

    def audit(actor, action, subject)
      AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor, action: action, subject: subject, created_at: Time.now.to_i)
    end
  end
end
