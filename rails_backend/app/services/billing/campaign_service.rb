module Billing
  class CampaignService
    def create(input:, actor:)
      attrs = input.to_h.symbolize_keys
      name = attrs[:name].to_s.strip
      parameter = attrs[:start_parameter].to_s.strip
      raise BillingError, "Название: 1–255 символов." unless name.length.between?(1, 255)
      raise BillingError, "Параметр: 2–64 символа a-z, 0-9, _ или -." unless parameter.match?(/\A[a-zA-Z0-9_-]{2,64}\z/)
      type = attrs[:bonus_type].presence || "balance"
      raise BillingError, "Некорректный тип бонуса." unless %w[balance subscription].include?(type)
      balance = attrs[:balance_bonus_kopeks].to_i
      days = attrs[:subscription_duration_days].to_i
      plan_id = attrs[:plan_id].to_s
      if type == "balance"
        raise BillingError, "Бонус: 1–1 000 000 ₽." unless balance.between?(1, 100_000_000)
      else
        raise BillingError, "Для подписки укажите 1–3650 дней и активный тариф." unless days.between?(1, 3650) && Plan.active.exists?(id: plan_id)
      end
      now = Time.now.to_i
      AdvertisingCampaign.transaction do
        campaign = AdvertisingCampaign.create!(
          id: Infrastructure::IdGenerator.call, name: name, start_parameter: parameter,
          bonus_type: type, balance_bonus_kopeks: type == "balance" ? balance : 0,
          subscription_duration_days: type == "subscription" ? days : nil,
          subscription_traffic_gb: nil, subscription_device_limit: nil,
          plan_id: type == "subscription" ? plan_id : nil, is_active: 1,
          partner_user_id: attrs[:partner_user_id].presence, created_by: actor, created_at: now
        )
        audit(actor, "campaign.created", campaign.id, now)
        campaign
      end
    rescue ActiveRecord::RecordNotUnique
      raise BillingError, "Параметр кампании уже используется."
    end

    def list
      AdvertisingCampaign.order(created_at: :desc).limit(500)
    end

    def register(user_id, start_parameter)
      AdvertisingCampaign.transaction do
        campaign = AdvertisingCampaign.lock.find_by(start_parameter: start_parameter.to_s, is_active: 1)
        next nil unless campaign
        user = User.find_by(id: user_id)
        raise BillingError, "Аккаунт недоступен." unless user && user.disabled.to_i.zero?
        existing = AdvertisingCampaignRegistration.find_by(campaign_id: campaign.id, user_id: user_id)
        next campaign if existing
        registration = AdvertisingCampaignRegistration.create!(id: Infrastructure::IdGenerator.call,
          campaign_id: campaign.id, user_id: user_id, bonus_granted: 0, created_at: Time.now.to_i)
        grant(user_id, campaign)
        registration.update!(bonus_granted: 1)
        campaign
      end
    end

    private

    def grant(user_id, campaign)
      if campaign.bonus_type == "balance" && campaign.balance_bonus_kopeks.to_i.positive?
        WalletService.new.credit(user_id, campaign.balance_bonus_kopeks.to_i, "promo_credit", "Бонус кампании: #{campaign.name}")
      elsif campaign.bonus_type == "subscription" && campaign.subscription_duration_days.to_i.positive?
        days = campaign.subscription_duration_days.to_i
        subscription = Subscription.where(user_id: user_id, status: %w[active trial]).order(created_at: :desc).lock.first
        if subscription
          expiry = [Time.now.to_i, subscription.expires_at.to_i].max + days * 86_400
          subscription.update!(expires_at: expiry, status: "active", lifecycle_status: "active", updated_at: Time.now.to_i)
          Infrastructure::OutboxService.new.enqueue("subscription.extend", "campaign-extend:#{campaign.id}:#{subscription.id}", { subscription_id: subscription.id })
        else
          plan = Plan.find_by(id: campaign.plan_id)
          raise BillingError, "Тариф кампании больше недоступен." unless plan
          now = Time.now.to_i
          sub = Subscription.create!(id: Infrastructure::IdGenerator.call, order_id: nil, user_id: user_id,
            status: "provisioning", lifecycle_status: "pending", expires_at: now + days * 86_400,
            created_at: now, updated_at: now, starts_at: now, plan_id: plan.id,
            traffic_limit_gb: plan.traffic_bytes.to_i / 1.gigabyte,
            traffic_limit_bytes: plan.traffic_bytes.to_i, device_limit: plan.devices,
            is_trial: 0, start_date: now)
          Infrastructure::OutboxService.new.enqueue("subscription.provision", "provision:#{sub.id}", { subscription_id: sub.id })
        end
      else
        raise BillingError, "Бонус кампании не настроен."
      end
    end

    def audit(actor, action, subject, now)
      AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor, action: action, subject: subject, created_at: now)
    end
  end
end
