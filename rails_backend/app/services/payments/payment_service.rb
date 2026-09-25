module Payments
  class PaymentService
    def initialize(
      settlement: Billing::SettlementService.new,
      topups: Billing::TopupService.new,
      attempts: PaymentAttemptStore.new,
      events: PaymentEventStore.new,
      outbox: Infrastructure::OutboxService.new,
      webhook_guard: WebhookGuard.new
    )
      @settlement = settlement
      @topups = topups
      @attempts = attempts
      @events = events
      @outbox = outbox
      @webhook_guard = webhook_guard
    end

    def create_order(order_id)
      order = Order.find_by(id: order_id)
      return { "payment_id" => "", "checkout_url" => "" } unless order
      Infrastructure::SafetyControls.new.assert_can_purchase!(order.provider)
      return { "payment_id" => order.provider_payment_id.to_s, "checkout_url" => order.checkout_url.to_s } unless order.pending? && order.checkout_url.blank?

      attempt = @attempts.begin_attempt("order", order)
      return existing_attempt_result(order, attempt) unless attempt.created?

      if order.provider == "demo"
        raise Billing::BillingError, "Демоплатёж запрещён." if production?
        return attach_order_checkout(order, attempt, "demo_#{order.id}", "/orders/#{order.id}")
      end

      provider = provider_for(order.provider)
      result = provider.create_order(order, order.user)
      attach_order_checkout(order, attempt, result["payment_id"], result["checkout_url"])
    rescue StandardError => e
      @attempts.unknown(attempt.id, e) if defined?(attempt) && attempt
      raise
    end

    def create_topup(topup_id)
      topup = Topup.find_by(id: topup_id)
      return { "payment_id" => "", "checkout_url" => "" } unless topup
      Infrastructure::SafetyControls.new.assert_can_purchase!(topup.provider)
      return { "payment_id" => topup.provider_payment_id.to_s, "checkout_url" => topup.checkout_url.to_s } unless topup.pending? && topup.checkout_url.blank?

      attempt = @attempts.begin_attempt("topup", topup)
      return existing_attempt_result(topup, attempt) unless attempt.created?

      if topup.provider == "demo"
        raise Billing::BillingError, "Демоплатёж запрещён." if production?
        return attach_topup_checkout(topup, attempt, "demo_#{topup.id}", "/balance/topup/#{topup.id}")
      end

      provider = provider_for(topup.provider)
      result = provider.create_topup(topup, topup.user)
      attach_topup_checkout(topup, attempt, result["payment_id"], result["checkout_url"])
    rescue StandardError => e
      @attempts.unknown(attempt.id, e) if defined?(attempt) && attempt
      raise
    end

    def verify(payment_id, provider_id = nil, correlation_id: nil)
      raise Billing::BillingError, "Некорректный платёж." if payment_id.blank? || payment_id.length > 100

      orders = scoped_payment_lookup(Order, payment_id, provider_id)
      topups = scoped_payment_lookup(Topup, payment_id, provider_id)
      raise Billing::BillingError, "Неоднозначная привязка платежа." if orders.size + topups.size > 1

      entity = orders.first || topups.first
      provider_id ||= entity&.provider
      return if provider_id.blank? || provider_id == "demo"

      result = provider_for(provider_id).verify(payment_id)
      status = result["status"]
      unless %w[paid canceled].include?(status)
        raise Infrastructure::JobDeferred, 60 if correlation_id.present?
        return
      end

      entity ||= recover_entity(provider_id, result["metadata"] || {})
      raise Infrastructure::JobDeferred, 60 if entity.nil? && correlation_id.present?
      raise Billing::BillingError, "Локальная привязка платежа ещё не готова." unless entity

      order_entity = entity.is_a?(Order)
      bound_by_id = entity.provider_payment_id == result["payment_id"]
      expected = result.dig("metadata", order_entity ? "order_id" : "topup_id")
      if !bound_by_id && provider_id == "platega" && expected != entity.id
        raise Billing::BillingError, "Платёж относится к другому заказу."
      end

      if order_entity && entity.provider_account.present? &&
         entity.provider_account != Infrastructure::RuntimeConfig.fetch("PLATEGA_MERCHANT_ID", "")
        raise Billing::BillingError, "Несовпадение магазина."
      end

      actual_id = result["payment_id"].to_s
      if actual_id.blank? || actual_id.length > 100 || (entity.provider_payment_id.present? && entity.provider_payment_id != actual_id)
        raise Billing::BillingError, "Несовпадение платежа."
      end

      if status == "paid"
        raise Billing::BillingError, "Тестовый платёж запрещён в production." if production? && result["test"]
        if order_entity
          @settlement.settle_order(entity.id, provider_id, actual_id, result["amount_kopeks"], result["currency"], correlation_id: correlation_id)
        else
          @topups.settle(entity.id, provider_id, actual_id, result["amount_kopeks"], result["currency"])
        end
        @attempts.completed(provider_id, actual_id, "paid")
      else
        entity.class.where(id: entity.id, provider: provider_id, status: "pending").update_all(status: "canceled")
        @attempts.completed(provider_id, actual_id, "canceled")
      end
    end

    def handle_webhook(provider_id, request)
      provider = provider_for(provider_id)
      return false unless provider.configured?

      result = provider.handle_webhook(request)
      return false unless result

      payment_id = result["payment_id"].to_s
      return false if payment_id.blank? || payment_id.length > 100

      status = result["status"]
      return true unless %w[paid canceled].include?(status)

      ApplicationRecord.transaction do
        event_id = result["event_id"].presence || "#{payment_id}:#{status}"
        # The webhook guard schema has a 64-character primary key. Keep the
        # provider event id stable while accepting valid long transaction IDs.
        event_id = Digest::SHA256.hexdigest(event_id) if event_id.length > 64
        begin
          claim = @webhook_guard.claim(provider_id, event_id, result)
        rescue WebhookTamperError => error
          Rails.logger.warn("webhook.tamper #{provider_id}/#{event_id} #{error.message}")
          next
        end
        next if claim == "duplicate"

        id = @events.receive(provider_id, event_id, payment_id, result, signature_valid: true, headers: request.headers.to_h)
        already = PaymentEvent.find(id)
        next if already.status == "processed"

        @outbox.enqueue("payment.event.process", "payment-event:#{id}", { event_id: id }, correlation_id: id)
      end
      true
    rescue Billing::BillingError
      false
    end

    def process_event(event_id)
      event = @events.claim(event_id)
      return unless event

      verify(event.payment_id, event.provider, correlation_id: event.id)
      @events.processed(event.id, event.lock_token)
      @webhook_guard.processed(event.provider, event.provider_event_id)
    rescue Infrastructure::JobDeferred => e
      @events.failed(event.id, event.lock_token, e) if event
      raise
    rescue Billing::BillingError
      if event
        @events.processed(event.id, event.lock_token)
        @webhook_guard.processed(event.provider, event.provider_event_id)
      end
    rescue StandardError => e
      @events.failed(event.id, event.lock_token, e) if event
      raise
    end

    # Reconcile external payments that cannot rely on a provider webhook and
    # replay webhook events whose outbox delivery or lease expired.
    def reconcile_pending(limit: 100, now: Time.now.to_i)
      provider = PlategaProvider.new
      if provider.configured?
        bucket = now / 300
        enqueue_pending_verifications(Order, "reconcile", bucket, limit)
        enqueue_pending_verifications(Topup, "reconcile-topup", bucket, limit)
      end

      stale_total = 0
      after = ""
      loop do
        stale_events = PaymentEvent.where(signature_valid: 1).where(
          "((status IN ('pending','retry') AND COALESCE(next_attempt_at, received_at) <= ?) OR (status = 'processing' AND locked_until <= ?)) AND id > ?",
          now, now, after
        ).order(:id).limit(limit)
        break if stale_events.empty?

        stale_events.each do |event|
          @outbox.enqueue("payment.event.process", "payment-event-recover:#{event.id}:#{now / 300}",
            { event_id: event.id }, correlation_id: event.id)
        end
        stale_total += stale_events.length
        break if stale_events.length < limit

        after = stale_events.last.id
      end
      stale_total
    end

    private

    def enqueue_pending_verifications(model, prefix, bucket, batch_size)
      relation = model.where(provider: "platega", status: "pending").where.not(provider_payment_id: [nil, ""])
      after = ""
      loop do
        rows = relation.where("id > ?", after).order(:id).limit(batch_size).pluck(:id, :provider_payment_id)
        break if rows.empty?

        rows.each do |id, payment_id|
          @outbox.enqueue("payment.verify", "#{prefix}:#{id}:#{bucket}",
            { payment_id: payment_id, provider: "platega" })
        end
        break if rows.length < batch_size

        after = rows.last.first
      end
    end

    def production?
      Rails.env.production? || Infrastructure::RuntimeConfig.fetch("APP_ENV", "") == "prod"
    end

    def provider_for(provider_id)
      return PlategaProvider.new if provider_id == "platega"

      raise Billing::BillingError, "Провайдер не поддерживается."
    end

    def scoped_payment_lookup(model, payment_id, provider_id)
      scope = model.where("provider_payment_id = ? OR id = ?", payment_id, payment_id)
      provider_id.present? ? scope.where(provider: provider_id).to_a : scope.to_a
    end

    def recover_entity(provider_id, metadata)
      order = Order.find_by(id: metadata["order_id"], provider: provider_id)
      topup = Topup.find_by(id: metadata["topup_id"], provider: provider_id)
      raise Billing::BillingError, "Неоднозначная привязка платежа." if order && topup

      order || topup
    end

    def existing_attempt_result(entity, attempt)
      if attempt.provider_payment_id.present? && attempt.checkout_url.present?
        entity.update!(provider_payment_id: attempt.provider_payment_id, checkout_url: attempt.checkout_url) if entity.checkout_url.blank?
        return { "payment_id" => attempt.provider_payment_id, "checkout_url" => attempt.checkout_url }
      end

      raise Billing::BillingError, "Платёжный checkout уже создаётся. Повторите позже."
    end

    def attach_order_checkout(order, attempt, payment_id, checkout_url)
      order.update!(provider_payment_id: payment_id, checkout_url: checkout_url)
      @attempts.attached(attempt.id, payment_id, checkout_url)
      { "payment_id" => payment_id, "checkout_url" => checkout_url }
    end

    def attach_topup_checkout(topup, attempt, payment_id, checkout_url)
      topup.update!(provider_payment_id: payment_id, checkout_url: checkout_url)
      @attempts.attached(attempt.id, payment_id, checkout_url)
      { "payment_id" => payment_id, "checkout_url" => checkout_url }
    end
  end
end
