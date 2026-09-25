module Billing
  class OrderService
    ID_KEY = /\A[a-zA-Z0-9:_-]{8,128}\z/

    def initialize(outbox: Infrastructure::OutboxService.new)
      @outbox = outbox
    end

    def create(user_id:, plan_id:, idempotency_key:, provider: nil, receipt_email: nil, client_ip: nil, renew_subscription_id: nil, landing_slug: nil, balance_purchase: false)
      config = Infrastructure::RuntimeConfig.new
      provider ||= config.fetch("PAYMENT_DRIVER", "demo")
      Infrastructure::SafetyControls.new.assert_can_purchase!(provider)
      raise BillingError, "Некорректный платёжный провайдер." unless %w[demo platega].include?(provider)
      raise BillingError, "Демоплатёж запрещён." if provider == "demo" && production? && !balance_purchase
      provision_driver = config.fetch("PROVISION_DRIVER", "demo")
      raise BillingError, "Некорректный сервис выдачи." unless %w[demo remnawave].include?(provision_driver)
      raise BillingError, "Демо-выдача запрещена." if provision_driver == "demo" && production?
      raise BillingError, "Некорректный ключ операции." unless ID_KEY.match?(idempotency_key.to_s)

      ApplicationRecord.transaction do
        user = User.lock.find_by(id: user_id, disabled: 0)
        raise BillingError, "Аккаунт не найден." unless user

        existing = Order.find_by(user_id: user_id, idempotency_key: idempotency_key)
        if existing
          raise BillingError, "Этот ключ уже использован для другого тарифа." if existing.plan_id != plan_id || existing.renewal_subscription_id != renew_subscription_id
          next existing
        end

        plan = Plan.active.find_by(id: plan_id)
        raise BillingError, "Тариф недоступен." unless plan

        if renew_subscription_id.present?
          renewal = Subscription.lock.find_by(id: renew_subscription_id, user_id: user_id, status: "active")
          raise BillingError, "Продлить можно только активную подписку." unless renewal && renewal.expires_at.to_i > Time.now.to_i
          raise BillingError, "Для продления выберите тариф подписки." unless renewal.plan_id == plan.id
        end

        landing_price = nil
        if landing_slug.present?
          landing = LandingPage.find_by(slug: landing_slug.to_s, is_active: 1)
          raise BillingError, "Предложение больше недоступно." unless landing
          landing_price = landing_price_for(landing, plan)
        end

        version = PlanVersion.where(plan_id: plan_id).order(version_number: :desc).first
        order = Order.create!(
          id: Infrastructure::IdGenerator.call,
          user_id: user_id,
          plan_id: plan_id,
          plan_version_id: version&.id,
          idempotency_key: idempotency_key,
          price_minor: [price_for(user, plan), landing_price || plan.price_minor.to_i].min,
          currency: plan.currency,
          plan_name: plan.name,
          duration_days: plan.duration_days,
          duration_months: plan.respond_to?(:duration_months) ? plan.duration_months.to_i : 0,
          traffic_bytes: plan.traffic_bytes,
          devices: plan.devices,
          status: "pending",
          provider: provider,
          provision_driver: provision_driver,
          squad_uuid: plan.try(:squad_uuid).presence || config.fetch("REMNAWAVE_SQUAD_UUID", ""),
          provider_account: provider == "platega" ? config.fetch("PLATEGA_MERCHANT_ID", "") : "",
          receipt_email: receipt_email.presence || user.email.presence || (user.telegram_id.present? ? "#{user.telegram_id}@telegram.org" : nil),
          receipt_enabled: 0,
          vat_code: 1,
          tax_system: nil,
          client_ip: normalize_ip(client_ip),
          return_url: "#{config.fetch("APP_URL", "http://127.0.0.1:8080").sub(%r{/*\z}, "")}/orders/%ORDER%",
          renewal_subscription_id: renew_subscription_id,
          created_at: Time.now.to_i
        )
        order.update!(return_url: order.return_url.sub("%ORDER%", order.id))

        create_order_item(order, plan)
        # Keep the existing renew_order_id projection synchronized with the
        # renewal_subscription_id snapshot. Auto-renew scheduling uses this
        # pointer as its concurrency gate and otherwise may charge while a
        # customer already has a manual renewal checkout in flight.
        renewal&.update!(renew_order_id: order.id, renew_at: nil)
        @outbox.enqueue("payment.create", "checkout:#{order.id}", { order_id: order.id }) unless balance_purchase
        AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: user_id, action: "order.created", subject: order.id, created_at: Time.now.to_i)
        order
      end
    end

    private

    def production?
      Rails.env.production? || Infrastructure::RuntimeConfig.fetch("APP_ENV", "") == "prod"
    end

    def price_for(user, plan)
      price = plan.price_minor.to_i
      percent = user.promo_offer_discount_percent.to_i
      expires = user.promo_offer_discount_expires_at
      return price unless percent.positive? && (expires.nil? || expires.to_i > Time.now.to_i)
      raise BillingError, "Скидка 100% требует бесплатной выдачи подписки администратором." if percent >= 100

      [1, price * (100 - percent) / 100].max
    end

    def landing_price_for(landing, plan)
      discount = landing.discount_percent.to_i
      starts_at = landing.discount_starts_at&.to_i
      ends_at = landing.discount_ends_at&.to_i
      now = Time.now.to_i
      return plan.price_minor.to_i unless discount.positive? &&
        (starts_at.nil? || starts_at <= now) && (ends_at.nil? || ends_at >= now)

      plan.price_minor.to_i * (100 - discount) / 100
    end

    def normalize_ip(ip)
      normalized = ip.to_s.strip
      return nil if normalized.empty? || normalized.length > 45

      normalized
    end

    def create_order_item(order, plan)
      return unless ApplicationRecord.connection.data_source_exists?("order_items")

      OrderItem.create!(
        id: Infrastructure::IdGenerator.call,
        order_id: order.id,
        product_type: "subscription",
        plan_id: plan.id,
        quantity: 1,
        unit_price_minor: order.price_minor,
        total_minor: order.price_minor,
        metadata: JSON.generate(duration_days: order.duration_days.to_i),
        created_at: Time.now.to_i
      )
    end
  end
end
