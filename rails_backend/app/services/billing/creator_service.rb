require "digest"

module Billing
  class CreatorService
    def capture(code, campaign: nil, ip: nil)
      creator = Creator.find_by(code: code.to_s.strip.upcase, status: "active")
      return nil unless creator
      token = SecureRandom.hex(24)
      now = Time.now.to_i
      CreatorAttribution.create!(
        id: Infrastructure::IdGenerator.call, creator_id: creator.id,
        visitor_token: token, campaign: campaign.to_s.first(120).presence,
        clicked_at: now, expires_at: now + creator.attribution_days.to_i.days.to_i,
        source_ip_hash: ip.present? ? Digest::SHA256.hexdigest(ip.to_s) : nil
      )
      token
    end

    def attach_registration(user_id, token)
      return unless token.to_s.match?(/\A[a-f0-9]{48}\z/)
      ApplicationRecord.transaction do
        attribution = CreatorAttribution.lock.find_by(visitor_token: token)
        next unless attribution && attribution.user_id.nil? && attribution.expires_at.to_i >= Time.now.to_i
        creator = Creator.find_by(id: attribution.creator_id, status: "active")
        next unless creator && creator.user_id != user_id
        attribution.update!(user_id: user_id, attributed_at: Time.now.to_i)
        audit(user_id, "referral.attributed", creator.id)
      end
    end

    def record_payment(payment_id)
      ApplicationRecord.transaction do
        payment = Payment.find_by(id: payment_id, status: "succeeded")
        next unless payment
        attribution = CreatorAttribution.find_by(user_id: payment.user_id)
        creator = Creator.find_by(id: attribution&.creator_id, status: "active")
        next unless creator
        next if CreatorCommission.exists?(payment_id: payment_id)
        creator.lock!
        prior = CreatorCommission.where(creator_id: creator.id, customer_id: payment.user_id).where.not(status: "reversed").count
        if prior.zero?
          percent = creator.first_percent.to_i
          kind = "first"
        else
          first = CreatorCommission.where(creator_id: creator.id, customer_id: payment.user_id, kind: "first").order(:created_at).first
          next unless first && first.created_at.to_i + creator.recurring_days.to_i.days.to_i >= Time.now.to_i
          percent = creator.recurring_percent.to_i
          kind = "recurring"
        end
        amount = payment.amount_minor.to_i * percent / 100
        next unless amount.positive?
        now = Time.now.to_i
        commission = CreatorCommission.create!(
          id: Infrastructure::IdGenerator.call, creator_id: creator.id,
          customer_id: payment.user_id, payment_id: payment.id,
          amount_minor: payment.amount_minor, commission_minor: amount,
          kind: kind, status: "pending", available_at: now + creator.hold_days.to_i.days.to_i,
          created_at: now
        )
        write_ledger(creator.id, "commission", amount, commission_id: commission.id,
                     metadata: { payment_id: payment.id, percent: percent })
        audit("system", "commission.created", commission.id)
        award_milestones(creator.id)
        commission
      end
    rescue ActiveRecord::RecordNotUnique
      nil
    end

    def release_due
      now = Time.now.to_i
      CreatorCommission.transaction do
        CreatorCommission.where(status: "pending").where("available_at <= ?", now)
          .order(:available_at).limit(500).lock("FOR UPDATE SKIP LOCKED").each do |commission|
            commission.update!(status: "available")
            audit("system", "commission.available", commission.id)
          end
      end
    end

    def reverse_payment(payment_id)
      ApplicationRecord.transaction do
        commission = CreatorCommission.lock.find_by(payment_id: payment_id)
        next unless commission && !%w[reversed paid].include?(commission.status)
        commission.update!(status: "reversed", reversed_at: Time.now.to_i)
        write_ledger(commission.creator_id, "reversal", -commission.commission_minor.to_i,
                     metadata: { commission_id: commission.id, payment_id: payment_id })
        audit("system", "commission.reversed", commission.id)
      end
    end

    def balance(creator_id)
      total = CreatorLedger.where(creator_id: creator_id).sum(:amount_minor).to_i
      pending_ids = CreatorCommission.where(creator_id: creator_id, status: "pending").select(:id)
      pending = CreatorLedger.where(creator_id: creator_id, entry_type: "commission", commission_id: pending_ids).sum(:amount_minor).to_i
      { total: total, pending: pending, available: total - pending }
    end

    def dashboard(user_id)
      creator = Creator.find_by(user_id: user_id, status: "active")
      return nil unless creator
      stats = CreatorCommission.where(creator_id: creator.id).where.not(status: "reversed")
      { creator: creator, balance: balance(creator.id),
        pending: stats.where(status: "pending").sum(:commission_minor).to_i,
        stats: { payments: stats.count, revenue: stats.sum(:amount_minor).to_i },
        clicks: CreatorAttribution.where(creator_id: creator.id).count,
        commissions: stats.order(created_at: :desc).limit(100) }
    end

    def profile(user_id) = Creator.find_by(user_id: user_id)

    def request_payout(user_id, amount_minor, details)
      amount = amount_minor.to_i
      details = details.to_s.strip
      raise BillingError, "Проверьте сумму и реквизиты выплаты." unless amount.between?(1, 1_000_000_000) && details.length.between?(5, 500)
      Creator.transaction do
        creator = Creator.lock.find_by(user_id: user_id, status: "active")
        raise BillingError, "Creator Mode недоступен." unless creator
        raise BillingError, "Недостаточно доступных средств." if amount > balance(creator.id)[:available]
        now = Time.now.to_i
        payout = CreatorPayout.create!(
          id: Infrastructure::IdGenerator.call, creator_id: creator.id, amount_minor: amount,
          details: details, status: "requested", requested_at: now
        )
        write_ledger(creator.id, "payout_reserve", -amount, payout_id: payout.id, metadata: { status: "requested" })
        audit(user_id, "payout.requested", payout.id)
        payout
      end
    end

    def process_payout(id, status, admin_id, operation_id: nil, comment: nil)
      raise BillingError, "Некорректный статус выплаты." unless %w[processing paid rejected cancelled].include?(status)
      CreatorPayout.transaction do
        payout = CreatorPayout.lock.find_by(id: id)
        raise BillingError, "Выплата не найдена." unless payout
        next payout if payout.status == status
        allowed = payout.status == "requested" || (payout.status == "processing" && status == "paid")
        raise BillingError, "Недопустимый переход статуса." unless allowed
        if %w[rejected cancelled].include?(status)
          write_ledger(payout.creator_id, "payout_release", payout.amount_minor.to_i,
                       payout_id: payout.id, metadata: { reason: status })
        end
        payout.update!(status: status, processed_at: Time.now.to_i, processed_by: admin_id,
                       operation_id: operation_id, comment: comment)
        audit(admin_id, "payout.#{status}", payout.id)
        payout
      end
    end

    def reconcile(actor: "scheduler")
      run = CreatorReconciliationRun.create!(id: Infrastructure::IdGenerator.call, started_at: Time.now.to_i, actor: actor)
      missing = Payment.joins("INNER JOIN creator_attributions ON creator_attributions.user_id = payments.user_id")
        .joins("INNER JOIN creators ON creators.id = creator_attributions.creator_id AND creators.status = 'active'")
        .left_joins(:creator_commission).where(payments: { status: "succeeded" }, creator_commissions: { id: nil }).limit(500).pluck("payments.id")
      missing.each { |payment_id| record_payment(payment_id) }
      inconsistent = CreatorCommission.joins(:payment).where.not(payments: { status: "succeeded" }).count
      run.update!(completed_at: Time.now.to_i, missing_count: missing.length, inconsistent_count: inconsistent)
      { missing: missing.length, inconsistent: inconsistent }
    end

    def activate(user_id, input, admin_id)
      attrs = input.to_h.symbolize_keys
      code = attrs[:code].to_s.strip.upcase
      raise BillingError, "Код Creator: CAT- и 5–24 латинских букв или цифр." unless code.match?(/\ACAT-[A-Z0-9]{5,24}\z/)
      bounds = { first_percent: 0..100, recurring_percent: 0..100, recurring_days: 0..3650, attribution_days: 1..365, hold_days: 0..365 }
      bounds.each { |key, range| raise BillingError, "Проверьте настройки Creator Program." unless attrs[key].to_s.match?(/\A\d+\z/) && range.cover?(attrs[key].to_i) }
      Creator.transaction do
        User.lock.find(user_id)
        creator = Creator.lock.find_by(user_id: user_id)
        now = Time.now.to_i
        old_status = creator&.status
        name = User.find(user_id).email.presence || User.find(user_id).telegram_id.presence || "Creator"
        creator ||= Creator.new(id: Infrastructure::IdGenerator.call, user_id: user_id, name: name, created_at: now)
        creator.assign_attributes(code: code, status: "active", first_percent: attrs[:first_percent].to_i,
          recurring_percent: attrs[:recurring_percent].to_i, recurring_days: attrs[:recurring_days].to_i,
          hold_days: attrs[:hold_days].to_i, attribution_days: attrs[:attribution_days].to_i, updated_at: now)
        creator.save!
        action = old_status == "suspended" ? "creator.reactivated" : (old_status ? "creator.settings_updated" : "creator.enabled")
        audit(admin_id, action, creator.id)
        creator
      end
    end

    def suspend(user_id, admin_id)
      Creator.transaction do
        creator = Creator.lock.find_by(user_id: user_id)
        next unless creator
        creator.update!(status: "suspended", updated_at: Time.now.to_i)
        audit(admin_id, "creator.suspended", creator.id)
      end
    end

    private

    def award_milestones(creator_id)
      paid = CreatorCommission.where(creator_id: creator_id).where.not(status: "reversed").distinct.count(:customer_id)
      CreatorMilestone.where(active: 1).where("threshold <= ?", paid).order(:threshold).each do |milestone|
        ApplicationRecord.transaction(requires_new: true) do
          ledger_id = write_ledger(creator_id, "milestone", milestone.bonus_minor.to_i, metadata: { milestone_id: milestone.id })
          CreatorMilestoneAward.create!(id: Infrastructure::IdGenerator.call, creator_id: creator_id,
            milestone_id: milestone.id, ledger_id: ledger_id, created_at: Time.now.to_i)
          audit("system", "milestone.achieved", milestone.id)
        end
      rescue ActiveRecord::RecordNotUnique
        next
      end
    end

    def write_ledger(creator_id, type, amount, commission_id: nil, payout_id: nil, metadata: {})
      CreatorLedger.create!(id: Infrastructure::IdGenerator.call, creator_id: creator_id, entry_type: type,
        amount_minor: amount, commission_id: commission_id, payout_id: payout_id,
        metadata: JSON.generate(metadata), created_at: Time.now.to_i).id
    end

    def audit(actor, action, subject)
      AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor, action: action, subject: subject, created_at: Time.now.to_i)
    end
  end
end
