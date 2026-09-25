module Operations
  class IntelligenceService
    def time_travel(user_id:, at:)
      timestamp = Integer(at)
      raise Billing::BillingError, "Можно исследовать только прошедший момент времени." unless timestamp.positive? && timestamp <= Time.now.to_i

      user = User.find_by(id: user_id)
      raise Billing::BillingError, "Пользователь не найден." unless user

      balance = WalletLedgerEntry.where(account: "wallet:user:#{user.id}").where("created_at <= ?", timestamp).sum(:amount_kopeks)
      subscriptions = Subscription.where(user_id: user.id).where("created_at <= ?", timestamp).order(created_at: :desc).map do |subscription|
        attrs = subscription.as_json
        attrs["historical_status"] = subscription.expires_at.to_i > timestamp ? (subscription.lifecycle_status.presence || subscription.status) : "expired"
        attrs["provisioning"] = ProvisioningAccount.find_by(subscription_id: subscription.id)&.as_json(only: %i[state last_synced_at last_error])
        attrs
      end
      payments = Payment.where(user_id: user.id).where("created_at <= ?", timestamp).order(created_at: :desc).limit(10)
        .as_json(only: %i[provider provider_payment_id amount_minor status paid_at created_at])
      events = CustomerTimeline.where(user_id: user.id).where("occurred_at <= ?", timestamp).order(occurred_at: :desc).limit(15)
        .as_json(only: %i[event_type payload occurred_at])
      after = CustomerTimeline.where(user_id: user.id).where("occurred_at > ?", timestamp).order(occurred_at: :asc).limit(15)
        .as_json(only: %i[event_type payload occurred_at])

      { user: user.as_json(only: %i[id email telegram_id created_at]), at: timestamp,
        balance: { amount: balance }, subs: subscriptions, payments: payments, events: events, after: after }
    rescue ArgumentError, TypeError
      raise Billing::BillingError, "Укажите корректное время для расследования."
    end

    def simulate(user_id:, plan_id:, promo_percent:, action: "renew")
      user = User.find_by(id: user_id)
      plan = Plan.find_by(id: plan_id, active: 1)
      raise Billing::BillingError, "Пользователь или активный тариф не найден." unless user && plan

      promo = [[Integer(promo_percent || 0), 0].max, 99].min
      charge = plan.price_minor.to_i * (100 - promo) / 100
      subscription = Subscription.where(user_id: user.id).order(expires_at: :desc).first
      expires_after = [Time.now.to_i, subscription&.expires_at.to_i].max + plan.duration_days.to_i * 86_400
      balance = user.balance_kopeks.to_i

      { action: action.to_s, plan: plan.as_json, promo_percent: promo, charge_minor: charge,
        balance_minor: balance, balance_after_minor: balance - charge,
        sufficient: balance >= charge, subscription_before: subscription&.as_json,
        expires_after: expires_after, will_change: balance >= charge }
    rescue ArgumentError, TypeError
      raise Billing::BillingError, "Некорректная скидка."
    end

    def graph(subscription_id:)
      subscription = Subscription.includes(:user).find_by(id: subscription_id)
      return nil unless subscription

      order = Order.find_by(id: subscription.order_id) if subscription.order_id.present?
      payment = Payment.where(order_id: order.id).order(created_at: :desc).first if order
      { user: { id: subscription.user_id,
                label: subscription.user.email.presence || "TG #{subscription.user.telegram_id}" },
        subscription: subscription.as_json, order: order&.as_json, payment: payment&.as_json,
        ledger: order ? LedgerEntry.where(order_id: order.id).as_json : [],
        provisioning: ProvisioningAccount.find_by(subscription_id: subscription.id)&.as_json }
    end

    def blast_radius(service:)
      accounts = ProvisioningAccount.joins(:subscription)
      accounts = accounts.where(provider: "remnawave") if service == "remnawave"
      delayed = accounts.where(state: %w[pending processing retry failed])
      { service: service, affected_users: delayed.distinct.count("subscriptions.user_id"),
        active_subscriptions: accounts.where(subscriptions: { lifecycle_status: "active" }).count,
        payments_waiting: Payment.where(status: "pending").count,
        money_at_risk_minor: 0, provisioning_delayed: delayed.count }
    end

    def overview
      checks = invariants(create_cases: false)
      cases = OperationalCase.where(status: "open")
        .order(Arel.sql("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 ELSE 2 END"), created_at: :desc).limit(20)
      maintenance = ServiceMaintenanceWindow.where("ends_at > ?", Time.now.to_i).order(:starts_at).limit(20)
      providers = ApplicationRecord.connection.exec_query(ApplicationRecord.sanitize_sql_array([
        "SELECT provider, COUNT(*) total, SUM(CASE WHEN status='succeeded' THEN 1 ELSE 0 END) succeeded, " \
        "SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failed FROM payments WHERE created_at > ? GROUP BY provider ORDER BY total DESC",
        Time.now.to_i - 86_400
      ])).to_a
      { checks: checks, cases: cases, maintenance: maintenance, providers: providers,
        blast: blast_radius(service: "remnawave"), canary: CanaryRun.order(created_at: :desc).first,
        migrations: MigrationRun.order(updated_at: :desc).limit(10),
        safety: AppSetting.find_by(name: "GLOBAL_SAFETY_MODE")&.value == "1" }
    end

    def invariants(create_cases:)
      money = ConsistencyService.new.run
      paid_without_sub = ApplicationRecord.connection.exec_query(<<~SQL).to_a
        SELECT o.id, o.user_id FROM orders o
        LEFT JOIN subscriptions s ON s.order_id = o.id
        WHERE o.workflow_status IN ('paid','fulfilled')
          AND NOT EXISTS (SELECT 1 FROM outbox ox WHERE ox.topic = 'subscription.extend' AND ox.dedup_key LIKE '%' || o.id || '%')
        GROUP BY o.id, o.user_id HAVING COUNT(s.id) = 0
      SQL
      active_expired = Subscription.where(lifecycle_status: "active").where("expires_at <= ?", Time.now.to_i)
        .pluck(:id, :user_id)
      violations = { financial: money[:count], paid_without_fulfillment: paid_without_sub.length,
        active_expired: active_expired.length }
      if create_cases
        paid_without_sub.each do |row|
          case_once("high", "Оплата/заказ без выдачи подписки", row.fetch("user_id"), nil, nil, order_id: row.fetch("id"))
        end
        active_expired.each do |id, user_id|
          case_once("medium", "Активная подписка с истёкшим сроком", user_id, nil, id, {})
        end
      end
      { checked: violations.values.sum + 1, ok: violations.values.sum.zero?, violations: violations, money: money }
    end

    def set_safety(enabled:, actor:)
      value = enabled ? "1" : "0"
      now = Time.now.to_i
      ApplicationRecord.transaction do
        setting = AppSetting.find_or_initialize_by(name: "GLOBAL_SAFETY_MODE")
        setting.update!(value: value, updated_at: now)
        AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor,
          action: enabled ? "safety_mode.enabled" : "safety_mode.disabled", subject: "global", created_at: now)
      end
      enabled
    end

    private

    def case_once(severity, title, user_id, payment_id, subscription_id, details)
      signature = Digest::SHA256.hexdigest([title, user_id, payment_id, subscription_id].join("|"))
      return if OperationalCase.where(status: "open").where("details LIKE ?", "%#{signature}%").exists?

      payload = details.merge(signature: signature)
      OperationalCase.create!(id: Infrastructure::IdGenerator.call,
        code: "OPS-#{Time.now.utc.year}-#{SecureRandom.random_number(90_000) + 10_000}",
        severity: severity, status: "open", title: title, user_id: user_id,
        payment_id: payment_id, subscription_id: subscription_id,
        details: JSON.generate(payload), created_at: Time.now.to_i)
    end

    def set_maintenance_window(service:, starts_at:, ends_at:, note:, actor:)
      service = service.to_s
      starts_at = Integer(starts_at)
      ends_at = Integer(ends_at)
      note = note.to_s.strip
      unless %w[remnawave payments worker].include?(service) && starts_at >= Time.now.to_i - 3600 &&
          ends_at > starts_at && note.length.between?(3, 300)
        raise Billing::BillingError, "Проверьте параметры технических работ."
      end

      ServiceMaintenanceWindow.create!(id: Infrastructure::IdGenerator.call, service: service,
        starts_at: starts_at, ends_at: ends_at, note: note, created_by: actor, created_at: Time.now.to_i)
    rescue ArgumentError, TypeError
      raise Billing::BillingError, "Проверьте параметры технических работ."
    end
  end
end
