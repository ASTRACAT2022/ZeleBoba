module Billing
  class PromoCodeService
    TYPES = %w[balance subscription_days trial_subscription discount balance_and_days].freeze

    def create(input:, actor:)
      input = input.to_h.symbolize_keys
      code = input[:code].to_s.strip.upcase
      type = input[:type].to_s
      raise BillingError, "Код: 3–50 символов A-Z, 0-9, _ или -." unless code.match?(/\A[A-Z0-9_-]{3,50}\z/)
      raise BillingError, "Некорректный тип промокода." unless TYPES.include?(type)
      bonus = input[:balance_bonus_kopeks].to_i
      days = input[:subscription_days].to_i
      traffic = input[:traffic_gb].to_i
      max_uses = input[:max_uses].to_i
      valid_from = input[:valid_from].presence&.to_i || Time.now.to_i
      valid_until = input[:valid_until].presence&.to_i
      first_only = input[:first_purchase_only].to_i
      plan_id = input[:plan_id].presence
      raise BillingError, "Бонус: от 0 до 1 000 000 ₽." unless bonus.between?(0, 99_000_000)
      raise BillingError, "Дни: от 0 до 3650." unless days.between?(0, 3650)
      raise BillingError, "Трафик: от 0 до 100 000 ГБ." unless traffic.between?(0, 100_000)
      raise BillingError, "Лимит использований: от 1 до 1 000 000." unless max_uses.between?(1, 1_000_000)
      raise BillingError, "Для скидки укажите процент 1–99." if type == "discount" && !bonus.between?(1, 99)
      raise BillingError, "Для триала укажите дни." if type == "trial_subscription" && days < 1
      raise BillingError, "Тариф не найден." if plan_id && !Plan.exists?(id: plan_id)

      ApplicationRecord.transaction do
        raise BillingError, "Такой код уже существует." if Promocode.exists?(code: code)
        promo = Promocode.create!(
          id: Infrastructure::IdGenerator.call, code: code, type: type, balance_bonus_kopeks: bonus,
          subscription_days: days, traffic_gb: traffic, max_uses: max_uses, current_uses: 0,
          valid_from: valid_from, valid_until: valid_until, is_active: 1,
          first_purchase_only: first_only, plan_id: plan_id, created_by: actor, created_at: Time.now.to_i
        )
        AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor, action: "promocode.created", subject: code, created_at: Time.now.to_i)
        promo
      end
    rescue ActiveRecord::RecordNotUnique
      raise BillingError, "Такой код уже существует."
    end

    def activate(user_id:, code:)
      normalized = code.to_s.strip.upcase
      return { success: false, error: "not_found" } unless normalized.match?(/\A[A-Z0-9_-]{3,50}\z/)

      ApplicationRecord.transaction do
        user = User.lock.find_by(id: user_id)
        next({ success: false, error: "user_not_found" }) unless user
        promo = Promocode.lock.find_by(code: normalized)
        next({ success: false, error: "not_found" }) unless promo
        now = Time.now.to_i
        next({ success: false, error: "inactive" }) unless promo.is_active.to_i == 1
        next({ success: false, error: "used" }) if promo.current_uses.to_i >= promo.max_uses.to_i
        next({ success: false, error: "not_yet_valid" }) if promo.valid_from.to_i > now
        next({ success: false, error: "expired" }) if promo.valid_until && promo.valid_until.to_i < now
        next({ success: false, error: "already_used_by_user" }) if PromocodeUse.exists?(user_id: user_id, promocode_id: promo.id)
        recent = PromocodeUse.where(user_id: user_id).where("used_at > ?", now - 86_400).count
        next({ success: false, error: "daily_limit" }) if recent >= 5
        next({ success: false, error: "not_first_purchase" }) if promo.first_purchase_only.to_i == 1 && user.has_had_paid_subscription.to_i == 1

        claimed = Promocode.where(id: promo.id).where("current_uses < max_uses").update_all("current_uses = current_uses + 1")
        next({ success: false, error: "used" }) if claimed.zero?
        PromocodeUse.create!(id: Infrastructure::IdGenerator.call, promocode_id: promo.id, user_id: user_id, used_at: now)

        begin
          description = ApplicationRecord.transaction(requires_new: true) { apply_effects(user, promo, now) }
        rescue BillingError => error
          Promocode.where(id: promo.id).update_all("current_uses = current_uses - 1")
          PromocodeUse.where(user_id: user_id, promocode_id: promo.id).delete_all
          next({ success: false, error: error.message })
        end
        AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: user_id, action: "promocode.activated", subject: normalized, created_at: now)
        { success: true, description: description }
      end
    end

    def toggle(id:, active:, actor:)
      Promocode.where(id: id).update_all(is_active: active ? 1 : 0)
      AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor,
                       action: active ? "promocode.enabled" : "promocode.disabled", subject: id, created_at: Time.now.to_i)
    end

    def list(limit: 100)
      Promocode.order(created_at: :desc).limit([[limit.to_i, 1].max, 500].min)
    end

    private

    def apply_effects(user, promo, now)
      effects = []
      if promo.type == "discount"
        current = user.promo_offer_discount_percent.to_i
        expiry = user.promo_offer_discount_expires_at
        raise BillingError, "active_discount_exists" if current.positive? && (expiry.nil? || expiry.to_i > now)
        hours = promo.subscription_days.to_i
        user.update!(
          promo_offer_discount_percent: promo.balance_bonus_kopeks.to_i,
          promo_offer_discount_source: "promocode:#{promo.code}",
          promo_offer_discount_expires_at: hours.positive? ? now + hours * 3600 : nil
        )
        effects << (hours.positive? ? "💸 Получена скидка #{promo.balance_bonus_kopeks}% (действует #{hours} ч.)" : "💸 Получена скидка #{promo.balance_bonus_kopeks}% до первой покупки")
      end

      target = nil
      if %w[subscription_days balance_and_days].include?(promo.type) && promo.subscription_days.to_i.positive?
        target = target_subscription(user)
        extend_subscription(target, promo.subscription_days.to_i)
        effects << "⏰ Подписка продлена на #{promo.subscription_days} дней"
      end
      if promo.type == "balance_and_days" && promo.traffic_gb.to_i.positive?
        target ||= target_subscription(user)
        raise BillingError, "traffic_not_applicable" if target.traffic_limit_gb.to_i.zero?
        target.update!(purchased_traffic_gb: target.purchased_traffic_gb.to_i + promo.traffic_gb.to_i)
        Infrastructure::OutboxService.new.enqueue("subscription.traffic", "traffic:#{target.id}:#{promo.id}", { subscription_id: target.id, traffic_gb: promo.traffic_gb.to_i })
        effects << "📦 Трафик пополнен на #{promo.traffic_gb} ГБ"
      end
      if %w[balance balance_and_days].include?(promo.type) && promo.balance_bonus_kopeks.to_i.positive?
        WalletService.new.credit(user.id, promo.balance_bonus_kopeks.to_i, "promo_credit", "Бонус по промокоду #{promo.code}")
        effects << "💰 Баланс пополнен на #{promo.balance_bonus_kopeks.to_i / 100.0} ₽"
      end
      if promo.type == "trial_subscription"
        plan = Plan.find_by(id: promo.plan_id, active: 1)
        raise BillingError, "trial_subscription_exists" unless plan
        existing = Subscription.where(user_id: user.id, status: %w[active trial]).order(created_at: :desc).first
        if existing
          extend_subscription(existing, promo.subscription_days.to_i)
          effects << "⏰ Подписка продлена на #{promo.subscription_days} дней"
        else
          id = Infrastructure::IdGenerator.call
          sub = Subscription.create!(
            id: id, order_id: nil, user_id: user.id, status: "trial", lifecycle_status: "active",
            expires_at: now + promo.subscription_days.to_i.days.to_i, created_at: now, updated_at: now,
            plan_id: plan.id, traffic_limit_gb: plan.traffic_bytes.to_i / 1.gigabyte,
            traffic_limit_bytes: plan.traffic_bytes, device_limit: plan.devices, is_trial: 1,
            starts_at: now, start_date: now
          )
          Infrastructure::OutboxService.new.enqueue("subscription.provision", "provision:#{sub.id}", { subscription_id: sub.id })
          effects << "🎁 Активирована тестовая подписка на #{promo.subscription_days} дней"
        end
      end
      User.where(id: user.id).update_all(has_had_paid_subscription: 1) if %w[subscription_days balance_and_days].include?(promo.type) && promo.subscription_days.to_i.positive?
      effects.any? ? effects.join("\n") : "✅ Промокод активирован"
    end

    def target_subscription(user)
      Subscription.where(user_id: user.id, status: %w[active trial]).order(created_at: :desc).first ||
        raise(BillingError, "no_subscription_for_days")
    end

    def extend_subscription(sub, days)
      sub.update!(expires_at: [Time.now.to_i, sub.expires_at.to_i].max + days * 86_400, status: "active", lifecycle_status: "active", updated_at: Time.now.to_i)
      Infrastructure::OutboxService.new.enqueue("subscription.extend", "extend:#{sub.id}:#{Infrastructure::IdGenerator.call}", { subscription_id: sub.id })
    end
  end
end
