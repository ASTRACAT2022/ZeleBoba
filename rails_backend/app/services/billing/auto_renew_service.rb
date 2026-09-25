module Billing
  class AutoRenewService
    def enabled?
      setting("AUTORENEW_ENABLED", Infrastructure::RuntimeConfig.fetch("AUTORENEW_ENABLED", "0")) == "1"
    end

    def set(user_id:, subscription_id:, enabled:)
      ApplicationRecord.transaction do
        subscription = Subscription.lock.find_by(id: subscription_id, user_id: user_id)
        raise BillingError, "Подписка не найдена." unless subscription
        raise BillingError, "Автопродление доступно только для активной подписки." unless subscription.status == "active" && subscription.expires_at.to_i > Time.now.to_i
        if enabled
          raise BillingError, "Автопродление отключено администратором." unless self.enabled?
          plan_id = Order.find_by(id: subscription.order_id)&.plan_id || subscription.plan_id
          plan = Plan.active.find_by(id: plan_id)
          raise BillingError, "Тариф подписки больше недоступен." unless plan
          days = plan.autorenew_days_before.to_i.positive? ? plan.autorenew_days_before.to_i : setting("AUTORENEW_DAYS_BEFORE", "3").to_i
          renew_at = renewal_at(expires_at: subscription.expires_at.to_i,
            duration_days: plan.duration_days.to_i, days_before: days)
          subscription.update!(auto_renew: 1, renew_plan_id: plan.id,
            renew_price_minor: plan.price_minor, renew_at: renew_at,
            renew_failed_at: nil, renew_fail_count: 0)
          audit(user_id, "subscription.autorenew_on", subscription.id)
        else
          subscription.update!(auto_renew: 0, renew_at: nil)
          audit(user_id, "subscription.autorenew_off", subscription.id)
        end
        subscription
      end
    end

    def schedule_due
      return 0 unless enabled?
      now = Time.now.to_i
      queued = 0
      max_fails = setting("AUTORENEW_MAX_FAILS", "3").to_i
      reconcile_failed_orders(now, max_fails)
      Subscription.joins("INNER JOIN plans ON plans.id = COALESCE(subscriptions.renew_plan_id, subscriptions.plan_id)")
        .where(auto_renew: 1, status: "active").where("subscriptions.expires_at > ?", now)
        .where("(subscriptions.renew_order_id IS NULL OR subscriptions.renew_order_id = '')")
        .where("subscriptions.renew_at IS NOT NULL AND subscriptions.renew_at <= ? AND plans.duration_days > 1", now)
        .where("subscriptions.renew_fail_count < COALESCE(NULLIF(plans.autorenew_max_fails, 0), ?)", max_fails)
        .order(:renew_at).limit(100).pluck("subscriptions.id").each do |id|
          Infrastructure::OutboxService.new.enqueue("subscription.renew", "renew:#{id}:#{now / 60}", { subscription_id: id })
          queued += 1
        end
      Subscription.joins("INNER JOIN plans ON plans.id = COALESCE(subscriptions.renew_plan_id, subscriptions.plan_id)")
        .where(auto_renew: 1, status: "active").where("subscriptions.expires_at > ? AND plans.duration_days <= 1", now)
        .where("(subscriptions.renew_order_id IS NULL OR subscriptions.renew_order_id = '')")
        .where("subscriptions.last_daily_charge_at IS NULL OR subscriptions.last_daily_charge_at + plans.duration_days * 86400 <= ?", now)
        .order(:expires_at).limit(200).pluck("subscriptions.id").each do |id|
          Infrastructure::OutboxService.new.enqueue("subscription.daily", "daily:#{id}:#{now / 3600}", { subscription_id: id })
          queued += 1
        end
      queued
    end

    def wake_for_user(user_id)
      return 0 unless enabled?
      now = Time.now.to_i
      rows = Subscription.joins("INNER JOIN plans ON plans.id = COALESCE(subscriptions.renew_plan_id, subscriptions.plan_id)")
        .where(user_id: user_id, auto_renew: 1, status: "active")
        .where("subscriptions.expires_at > ? AND (subscriptions.renew_order_id IS NULL OR subscriptions.renew_order_id = '')", now)
        .where("subscriptions.renew_at <= ? OR subscriptions.renew_failed_at IS NOT NULL", now)
        .limit(100).pluck("subscriptions.id", "plans.duration_days")
      rows.each do |id, duration_days|
        Subscription.where(id: id, renew_order_id: nil).update_all(renew_at: now)
        topic, prefix = duration_days.to_i <= 1 ? ["subscription.daily", "daily"] : ["subscription.renew", "renew"]
        bucket = duration_days.to_i <= 1 ? now / 3600 : now / 60
        Infrastructure::OutboxService.new.enqueue(topic, "#{prefix}:#{id}:#{bucket}", { subscription_id: id })
      end
      rows.length
    end

    def renewal_at(expires_at:, duration_days:, days_before: nil)
      period = [1, duration_days.to_i].max * 86_400
      configured_days = days_before.to_i.positive? ? days_before.to_i : setting("AUTORENEW_DAYS_BEFORE", "3").to_i
      configured = [[configured_days, 1].max, 14].min * 86_400
      lead = [configured, [period / 3, 300].max].min
      [Time.now.to_i + 60, expires_at.to_i - lead].max
    end

    def process(subscription_id)
      subscription = Subscription.find_by(id: subscription_id)
      return false unless subscription
      plan_id = subscription.renew_plan_id.presence || subscription.plan_id
      plan = Plan.find_by(id: plan_id)
      return false unless plan
      plan.duration_days.to_i <= 1 ? daily_charge(subscription_id) : renew_from_balance(subscription_id)
    end

    def renew_from_balance(subscription_id)
      ApplicationRecord.transaction do
        now = Time.now.to_i
        sub = Subscription.lock.find_by(id: subscription_id)
        next false unless sub && sub.auto_renew.to_i == 1 && sub.status == "active" && sub.expires_at.to_i > now
        next false if sub.renew_order_id.present? || sub.renew_at.to_s.to_i > now
        plan = Plan.active.find_by(id: sub.renew_plan_id.presence || sub.plan_id)
        unless plan
          defer(sub, now, "subscription.autorenew_plan_unavailable")
          next false
        end
        price = sub.renew_price_minor.to_i.positive? ? sub.renew_price_minor.to_i : plan.price_minor.to_i
        user = User.lock.find_by(id: sub.user_id, disabled: 0)
        unless user && user.balance_kopeks.to_i >= price
          defer(sub, now, "subscription.autorenew_waiting_balance")
          next false
        end
        original = Order.find_by(id: sub.order_id)
        raise BillingError, "Не найден исходный заказ подписки." unless original

        order_id = Infrastructure::IdGenerator.call
        idempotency_key = "autorenew-balance:#{sub.id}:#{sub.expires_at.to_i}"
        version = PlanVersion.where(plan_id: plan.id).order(version_number: :desc).first
        order = Order.create!(
          id: order_id, user_id: sub.user_id, plan_id: plan.id, plan_version_id: version&.id,
          idempotency_key: idempotency_key, price_minor: price, currency: plan.currency,
          plan_name: plan.name, duration_days: plan.duration_days,
          duration_months: plan.duration_months.to_i, traffic_bytes: plan.traffic_bytes,
          devices: plan.devices, status: "pending", provider: original.provider,
          provision_driver: original.provision_driver, squad_uuid: original.squad_uuid,
          provider_account: original.provider_account, receipt_email: original.receipt_email,
          receipt_enabled: original.receipt_enabled, vat_code: original.vat_code,
          tax_system: original.tax_system, client_ip: original.client_ip,
          return_url: original.return_url, provider_test: original.provider_test,
          renewal_subscription_id: sub.id, created_at: now
        )
        OrderItem.create!(id: Infrastructure::IdGenerator.call, order_id: order.id,
          product_type: "subscription", plan_id: plan.id, quantity: 1,
          unit_price_minor: price, total_minor: price,
          metadata: JSON.generate(duration_days: plan.duration_days.to_i, duration_months: plan.duration_months.to_i),
          created_at: now)
        sub.update!(renew_order_id: order.id, renew_at: nil)
        WalletService.new.debit(sub.user_id, price, "subscription_renewal", "Автопродление: #{plan.name}", external_id: order.id)
        SettlementService.new.settle_order(order.id, order.provider, "balance_#{order.id}", price, plan.currency)
        audit("system", "subscription.autorenew_debited", sub.id)
        true
      end
    end

    def daily_charge(subscription_id)
      ApplicationRecord.transaction do
        now = Time.now.to_i
        sub = Subscription.lock.find_by(id: subscription_id)
        next false unless sub && sub.auto_renew.to_i == 1 && sub.status == "active"
        next false if sub.renew_order_id.present?
        plan = Plan.active.find_by(id: sub.renew_plan_id.presence || sub.plan_id)
        next false unless plan
        period = [1, plan.duration_days.to_i].max * 86_400
        last_charge = sub.last_daily_charge_at.to_s.to_i
        next false if last_charge.positive? && now - last_charge < period
        price = sub.renew_price_minor.to_i.positive? ? sub.renew_price_minor.to_i : plan.price_minor.to_i
        user = User.lock.find_by(id: sub.user_id, disabled: 0)
        unless user && user.balance_kopeks.to_i >= price
          defer(sub, now, "subscription.daily_waiting_balance")
          next false
        end
        expiry = [now, sub.expires_at.to_i].max + period
        sub.update!(expires_at: expiry, last_daily_charge_at: now,
          renew_at: now + period, renew_failed_at: nil, updated_at: now,
          version: sub.version.to_i + 1)
        WalletService.new.debit(sub.user_id, price, "subscription_daily", "Ежедневное автосписание: #{plan.name}", external_id: "daily:#{sub.id}:#{last_charge}")
        Infrastructure::OutboxService.new.enqueue("subscription.extend", "daily-extend:#{sub.id}:#{expiry}", { subscription_id: sub.id })
        audit("system", "subscription.daily_debited", sub.id)
        true
      end
    end

    private

    # PHP Reconciler releases an auto-renew slot after its checkout is
    # definitively canceled. Without this, renew_order_id remains set forever
    # and schedule_due permanently skips the subscription after one failure.
    def reconcile_failed_orders(now, default_max_fails)
      ids = Subscription.joins("INNER JOIN orders failed_orders ON failed_orders.id = subscriptions.renew_order_id")
        .where(auto_renew: 1, status: "active", failed_orders: { status: "canceled" })
        .order("subscriptions.id").limit(100).pluck("subscriptions.id")

      ids.each do |id|
        ApplicationRecord.transaction do
          subscription = Subscription.lock.find_by(id: id, auto_renew: 1, status: "active")
          next unless subscription

          order = Order.lock.find_by(id: subscription.renew_order_id, status: "canceled")
          next unless order

          plan = Plan.find_by(id: subscription.renew_plan_id.presence || subscription.plan_id)
          plan_limit = plan&.autorenew_max_fails.to_i
          limit = plan_limit.positive? ? plan_limit : default_max_fails
          next if subscription.renew_fail_count.to_i >= limit

          subscription.update!(renew_order_id: nil, renew_failed_at: now,
            renew_fail_count: subscription.renew_fail_count.to_i + 1, renew_at: now + 3600)
        end
      end
    end

    def setting(name, fallback)
      AppSetting.find_by(name: name)&.value || fallback
    end

    def defer(subscription, now, action)
      subscription.update!(renew_failed_at: now, renew_at: [subscription.expires_at.to_i, now + 3600].min)
      audit("system", action, subscription.id)
    end

    def audit(actor, action, subject)
      AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor, action: action, subject: subject, created_at: Time.now.to_i)
    end
  end
end
