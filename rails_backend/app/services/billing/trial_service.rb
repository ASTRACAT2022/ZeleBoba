module Billing
  class TrialService
    def available?(user_id)
      user = User.find_by(id: user_id)
      return false unless user && user.disabled.to_i.zero? && user.has_had_paid_subscription.to_i.zero?

      !Subscription.where(user_id: user_id).where("is_trial = 1 OR status IN ('active','trial','limited','provisioning')").exists?
    end

    def start(user_id:, plan_id:)
      ApplicationRecord.transaction do
        User.lock.find(user_id)
        raise BillingError, "Триал недоступен: у вас уже была подписка." unless available?(user_id)
        plan = Plan.lock.find_by(id: plan_id, active: 1, is_trial_available: 1)
        raise BillingError, "Триал на этом тарифе недоступен." unless plan

        days = (plan.trial_duration_days || Infrastructure::RuntimeConfig.fetch("TRIAL_DURATION_DAYS", "3")).to_i
        days = 3 if days < 1
        price = plan.trial_price_kopeks.to_i
        @wallet ||= WalletService.new
        @wallet.debit(user_id, price, "trial_conversion", "Активация триальной подписки") if price.positive?
        now = Time.now.to_i
        id = Infrastructure::IdGenerator.call
        subscription = Subscription.create!(
          id: id, order_id: nil, user_id: user_id, status: "active", lifecycle_status: "active",
          expires_at: now + days.days.to_i, created_at: now, updated_at: now,
          plan_id: plan.id, traffic_limit_gb: plan.traffic_bytes.to_i / 1.gigabyte,
          traffic_limit_bytes: plan.traffic_bytes, traffic_used_gb: 0, device_limit: plan.devices,
          is_trial: 1, starts_at: now, start_date: now
        )
        OutboxJob.transaction do
          Infrastructure::OutboxService.new.enqueue("subscription.provision", "provision:#{id}", { subscription_id: id })
        end
        AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: user_id, action: "trial.started", subject: id, created_at: now)
        subscription
      end
    end

    def convert_to_paid(user_id:, subscription_id:, plan_id:, price_kopeks:, payment_method: "balance")
      ApplicationRecord.transaction do
        sub = Subscription.lock.find_by(id: subscription_id)
        raise BillingError, "Подписка не найдена." unless sub && sub.user_id == user_id
        raise BillingError, "Подписка не является активным триалом." unless sub.is_trial.to_i == 1 && %w[active trial limited].include?(sub.status)
        plan = Plan.find_by(id: plan_id, active: 1)
        raise BillingError, "Тариф недоступен." unless plan
        raise BillingError, "Цена тарифа изменилась. Обновите страницу." unless price_kopeks.to_i == plan.price_minor.to_i

        @wallet ||= WalletService.new
        @wallet.debit(user_id, price_kopeks.to_i, "subscription_purchase", "Покупка подписки: #{plan.name}", payment_method: payment_method)
        now = Time.now.to_i
        base = [now, sub.expires_at.to_i].max
        carry = Infrastructure::RuntimeConfig.fetch("TRIAL_ADD_REMAINING_DAYS_TO_PAID", "0") == "1"
        duration = plan.duration_days.to_i * 86_400
        expiry = carry ? base + duration : now + duration
        trial_days = sub.starts_at ? ((sub.expires_at.to_i - sub.starts_at.to_i) / 86_400.0).round : nil
        sub.update!(
          status: "active", lifecycle_status: "active", is_trial: 0, expires_at: expiry,
          plan_id: plan.id, traffic_limit_gb: plan.traffic_bytes.to_i / 1.gigabyte,
          traffic_limit_bytes: plan.traffic_bytes, device_limit: plan.devices, updated_at: now
        )
        User.where(id: user_id).update_all(has_had_paid_subscription: 1)
        conversion = SubscriptionConversion.create!(
          id: Infrastructure::IdGenerator.call, user_id: user_id, converted_at: now,
          trial_duration_days: trial_days, payment_method: payment_method,
          first_payment_amount_kopeks: price_kopeks.to_i, first_paid_period_days: plan.duration_days,
          created_at: now
        )
        Infrastructure::OutboxService.new.enqueue("subscription.extend", "trial-convert:#{conversion.id}", { subscription_id: sub.id })
        AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: user_id, action: "trial.converted", subject: sub.id, created_at: now)
        sub
      end
    end

    def expire_overdue
      now = Time.now.to_i
      Subscription.where(is_trial: 1, status: %w[active trial]).where("expires_at <= ?", now)
                  .update_all(status: "expired", lifecycle_status: "expired", updated_at: now)
    end
  end
end
