module Admin
  # Admin operations on customer accounts and subscriptions. All local changes,
  # wallet ledger entries, audit records, and outbox messages commit together.
  class UserAdminService
    def initialize(wallet: Billing::WalletService.new, outbox: Infrastructure::OutboxService.new)
      @wallet = wallet
      @outbox = outbox
    end

    def profile(user_id)
      user = User.find_by(id: user_id)
      raise Billing::BillingError, "Пользователь не найден." unless user

      promo_uses = PromocodeUse.joins(:promocode).where(user_id: user.id)
        .order(used_at: :desc).map do |use|
          use.attributes.slice("used_at").merge(use.promocode.attributes.slice(
            "code", "type", "balance_bonus_kopeks", "subscription_days"))
        end
      spent = TransactionRecord.where(user_id: user.id, type: %w[
        subscription_purchase subscription_renewal gift_purchase traffic_topup device_addon
      ]).where("amount_kopeks < 0").sum(:amount_kopeks)

      {
        user: user,
        subscriptions: Subscription.where(user_id: user.id).order(created_at: :desc).map do |sub|
          sub.as_json.merge("plan_name" => sub.order&.plan_name || Plan.find_by(id: sub.plan_id)&.name)
        end,
        orders: Order.where(user_id: user.id).order(created_at: :desc).limit(50),
        topups: Topup.where(user_id: user.id).order(created_at: :desc).limit(20),
        transactions: TransactionRecord.where(user_id: user.id).order(seq: :desc).limit(30),
        promo_uses: promo_uses,
        referrals: User.where(referred_by_id: user.id).order(created_at: :desc)
          .as_json(only: %i[id email telegram_id created_at has_made_first_topup]),
        referrer: user.referred_by_id.present? ? User.where(id: user.referred_by_id)
          .as_json(only: %i[id email telegram_id]).first : nil,
        spent_kopeks: -spent.to_i,
        gifts: GuestPurchase.where(buyer_user_id: user.id, is_gift: 1).order(created_at: :desc),
        timeline: CustomerTimeline.where(user_id: user.id).order(recorded_at: :asc).limit(100)
          .map { |event| event.as_json(only: %i[event_type payload occurred_at]) }
      }
    end

    def adjust_balance(user_id:, amount_kopeks:, reason:, actor:)
      amount = integer!(amount_kopeks, "Сумма не может быть нулевой.")
      reason = reason.to_s.strip
      raise Billing::BillingError, "Сумма не может быть нулевой." if amount.zero?
      raise Billing::BillingError, "Сумма слишком большая." if amount.abs > 100_000_000
      raise Billing::BillingError, "Причина: 3–200 символов." unless reason.length.between?(3, 200)

      ApplicationRecord.transaction do
        user_exists!(user_id)
        if amount.positive?
          @wallet.credit(user_id, amount, "manual_adjust", "Ручная корректировка: #{reason}")
        else
          @wallet.debit(user_id, -amount, "manual_adjust", "Ручная корректировка: #{reason}")
        end
        audit(actor, "user.balance_adjusted", user_id)
      end
      true
    end

    def grant_days(user_id:, days:, plan_id: nil, actor:)
      days = bounded_integer!(days, 1..3650, "Дни: 1–3650.")
      ApplicationRecord.transaction do
        user = user_exists!(user_id)
        sub = Subscription.lock.where(user_id: user.id, status: %w[active trial provisioning])
          .order(created_at: :desc).first
        if sub
          base = [Time.now.to_i, sub.expires_at.to_i].max
          provisioning = sub.status == "provisioning"
          sub.update!(expires_at: base + days * 86_400,
            status: provisioning ? "provisioning" : "active", updated_at: Time.now.to_i)
          enqueue_subscription(provisioning ? "subscription.provision" : "subscription.extend", sub.id,
            "admin-grant:#{Infrastructure::IdGenerator.call}")
        else
          plan = plan_id.present? ? Plan.active.find_by(id: plan_id) : nil
          raise Billing::BillingError, "Для новой подписки выберите активный тариф." unless plan
          now = Time.now.to_i
          sub = Subscription.create!(id: Infrastructure::IdGenerator.call, order_id: nil,
            user_id: user.id, status: "provisioning", expires_at: now + days * 86_400,
            created_at: now, plan_id: plan.id,
            traffic_limit_gb: plan.traffic_bytes.to_i / 1.gigabyte,
            device_limit: plan.devices, is_trial: 0, start_date: now, updated_at: now)
          enqueue_subscription("subscription.provision", sub.id, "provision:#{sub.id}")
        end
        audit(actor, "user.days_granted", user.id)
        sub
      end
    end

    def set_discount(user_id:, percent:, hours:, actor:)
      percent = bounded_integer!(percent, 1..99, "Скидка: 1–99%.")
      hours = bounded_integer!(hours, 0..8760, "Часы: 0–8760.")
      ApplicationRecord.transaction do
        user = user_exists!(user_id)
        user.update!(promo_offer_discount_percent: percent,
          promo_offer_discount_source: "admin:#{actor}",
          promo_offer_discount_expires_at: hours.positive? ? Time.now.to_i + hours * 3600 : nil)
        audit(actor, "user.discount_set", user.id)
      end
      true
    end

    def clear_discount(user_id:, actor:)
      ApplicationRecord.transaction do
        user = user_exists!(user_id)
        user.update!(promo_offer_discount_percent: 0, promo_offer_discount_source: nil,
          promo_offer_discount_expires_at: nil)
        audit(actor, "user.discount_cleared", user.id)
      end
      true
    end

    def subscription(subscription_id)
      sub = Subscription.includes(:order, :user).find_by(id: subscription_id)
      raise Billing::BillingError, "Подписка не найдена." unless sub

      {
        sub: sub.as_json.merge("plan_name" => sub.order&.plan_name || Plan.find_by(id: sub.plan_id)&.name,
          "email" => sub.user.email, "telegram_id" => sub.user.telegram_id),
        panel: ProvisioningAccount.find_by(subscription_id: sub.id)&.as_json,
        panel_traffic_used_gb: sub.traffic_used_gb.to_f,
        plans: Plan.order(:display_order, :name).as_json(only: %i[id name duration_days traffic_bytes devices])
      }
    end

    def update_subscription_traffic(user_id:, subscription_id:, traffic_gb:, actor:)
      value = bounded_integer!(traffic_gb, 0..100_000, "Трафик 0–100000 ГБ.")
      update_owned_subscription(user_id, subscription_id, actor, "user.subscription_traffic_changed") do |sub|
        sub.update!(traffic_limit_gb: value, updated_at: Time.now.to_i)
      end
    end

    def update_subscription_devices(user_id:, subscription_id:, devices:, actor:)
      value = bounded_integer!(devices, 0..20, "Устройства 0–20 (0 = безлимит).")
      update_owned_subscription(user_id, subscription_id, actor, "user.subscription_devices_changed") do |sub|
        sub.update!(device_limit: value, updated_at: Time.now.to_i)
      end
    end

    def extend_subscription(user_id:, subscription_id:, days:, actor:)
      value = bounded_integer!(days, 1..3650, "Дни: 1–3650.")
      update_owned_subscription(user_id, subscription_id, actor, "user.subscription_extended") do |sub|
        base = [Time.now.to_i, sub.expires_at.to_i].max
        sub.update!(expires_at: base + value * 86_400, status: "active", updated_at: Time.now.to_i)
      end
    end

    def set_subscription_expiry(user_id:, subscription_id:, expires_at:, actor:)
      value = integer!(expires_at, "Укажите дату окончания правильно.")
      now = Time.now.to_i
      raise Billing::BillingError, "Дата окончания не может быть в прошлом." if value < now - 3600
      raise Billing::BillingError, "Дата окончания слишком далеко (макс. 10 лет)." if value > now + 3650 * 86_400
      update_owned_subscription(user_id, subscription_id, actor, "user.subscription_expiry_set") do |sub|
        sub.update!(expires_at: value, status: "active", updated_at: now)
      end
    end

    def reset_subscription_traffic(user_id:, subscription_id:, actor:)
      update_owned_subscription(user_id, subscription_id, actor, "user.subscription_traffic_reset") do |sub|
        sub.update!(traffic_used_gb: 0, updated_at: Time.now.to_i)
      end
    end

    # Mark the row disabled and enqueue remote removal. Keeping the local record
    # preserves order/payment references and avoids a network request inside a DB transaction.
    def remove_subscription(user_id:, subscription_id:, actor:)
      ApplicationRecord.transaction do
        sub = owned_subscription!(user_id, subscription_id)
        sub.update!(status: "disabled", lifecycle_status: "cancelled", updated_at: Time.now.to_i)
        OutboxJob.where(topic: %w[
          subscription.provision subscription.extend subscription.renew subscription.daily
          subscription.traffic subscription.devices subscription.admin_sync
        ], status: "pending").find_each do |job|
          next unless JSON.parse(job.payload.to_s).fetch("subscription_id", nil).to_s == subscription_id.to_s
          job.update!(status: "done", payload: "{}")
        rescue JSON::ParserError
          # Leave malformed jobs to the worker's existing poison-message policy.
        end
        @outbox.enqueue("subscription.remove", "admin-remove:#{subscription_id}:#{Infrastructure::IdGenerator.call}",
          { subscription_id: subscription_id, requested_by: actor.to_s })
        audit(actor, "user.subscription_removed", user_id)
      end
      true
    end

    def search(query, limit: 50)
      q = query.to_s.strip
      return [] if q.empty?
      limit = [[Integer(limit), 1].max, 200].min
      pattern = "%#{ActiveRecord::Base.sanitize_sql_like(q)}%"
      User.where("LOWER(COALESCE(email, '')) LIKE LOWER(:q) OR LOWER(COALESCE(telegram_id, '')) LIKE LOWER(:q) OR LOWER(COALESCE(referral_code, '')) LIKE LOWER(:q)", q: pattern)
        .order(created_at: :desc).limit(limit)
        .as_json(only: %i[id email telegram_id role disabled balance_kopeks created_at])
    rescue ArgumentError, TypeError
      raise Billing::BillingError, "Некорректный лимит поиска."
    end

    private

    def update_owned_subscription(user_id, subscription_id, actor, action)
      ApplicationRecord.transaction do
        sub = owned_subscription!(user_id, subscription_id)
        yield sub
        enqueue_subscription("subscription.admin_sync", sub.id, "admin-sync:#{Infrastructure::IdGenerator.call}")
        audit(actor, action, user_id)
        sub
      end
    end

    def owned_subscription!(user_id, subscription_id)
      sub = Subscription.lock.find_by(id: subscription_id)
      raise Billing::BillingError, "Подписка не найдена." unless sub && sub.user_id == user_id.to_s
      sub
    end

    def user_exists!(user_id)
      User.lock.find_by(id: user_id).tap do |user|
        raise Billing::BillingError, "Пользователь не найден." unless user
      end
    end

    def enqueue_subscription(topic, subscription_id, key)
      @outbox.enqueue(topic, key, { subscription_id: subscription_id })
    end

    def audit(actor, action, subject)
      AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor.to_s,
        action: action, subject: subject.to_s, created_at: Time.now.to_i)
    end

    def integer!(value, error)
      parsed = Integer(value, exception: false)
      raise Billing::BillingError, error if parsed.nil?
      parsed
    end

    def bounded_integer!(value, range, error)
      parsed = integer!(value, error)
      raise Billing::BillingError, error unless range.cover?(parsed)
      parsed
    end
  end
end
