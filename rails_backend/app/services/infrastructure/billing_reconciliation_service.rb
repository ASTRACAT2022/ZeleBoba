require "erb"

module Infrastructure
  # Read-only payment anomaly detection plus the deterministic subscription
  # expiry cleanup shared with the PHP Reconciler. Alerts are durable Ops rows;
  # no payment, wallet, or subscription is created or credited here.
  class BillingReconciliationService
    def run(now: Time.now.to_i)
      expired = self_heal_expired_subscriptions(now)
      paid_without_subscription = detect_paid_without_subscription
      delivery_gaps = detect_delivery_gaps
      resolved_cases = resolve_recovered_cases(now)

      { expired_subscriptions: expired, paid_without_subscription: paid_without_subscription,
        delivery_gaps: delivery_gaps, resolved_cases: resolved_cases }
    end

    private

    def self_heal_expired_subscriptions(now)
      count = Subscription.where("expires_at <= ?", now)
        .where("status = ? OR lifecycle_status IN (?)", "active", %w[active grace])
        .update_all(status: "expired", lifecycle_status: "expired")

      Subscription.where(status: "expired", auto_renew: 1).update_all(auto_renew: 0)
      count
    end

    def detect_paid_without_subscription
      created = 0
      after = ""
      loop do
        rows = ApplicationRecord.connection.exec_query(ApplicationRecord.sanitize_sql_array([<<~SQL, after])).to_a
          SELECT o.id AS order_id, o.user_id, o.provider, o.plan_id, o.price_minor, o.status
          FROM orders o
          WHERE o.id > ? AND o.status = 'paid' AND o.paid_at IS NOT NULL
            AND EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id AND p.status = 'succeeded')
            AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.order_id = o.id)
          ORDER BY o.id LIMIT 50
        SQL
        break if rows.empty?

        rows.each do |row|
          id = row.fetch("order_id")
          op = create_alert(
            type: "payment.paid_no_subscription", correlation_id: "paid-no-sub:#{id}",
            refs: { user_id: row.fetch("user_id"), order_id: id,
                    metadata: { provider: row.fetch("provider"), plan_id: row.fetch("plan_id"),
                                amount_minor: row.fetch("price_minor").to_i, status: row.fetch("status") } },
            event_type: "payment.paid_no_subscription.detected",
            message: "Клиент заплатил (#{money(row.fetch('price_minor'))} ₽), но подписка не создана (заказ #{id.to_s.first(8)}). Проверьте и зачислите вручную.",
            completion: "Ожидает ручного зачисления"
          )
          next unless op

          created += 1
          notify_admin(id)
        end
        break if rows.length < 50
        after = rows.last.fetch("order_id")
      end
      created
    end

    def detect_delivery_gaps
      created = 0
      after = ""
      loop do
        rows = ApplicationRecord.connection.exec_query(ApplicationRecord.sanitize_sql_array([<<~SQL, after])).to_a
          SELECT o.id AS order_id, o.user_id, o.provider, o.provider_payment_id, o.status,
            COALESCE(p.amount_minor, o.price_minor) AS amount_minor
          FROM payments p JOIN orders o ON o.id = p.order_id
          WHERE o.id > ? AND p.status = 'succeeded' AND o.status NOT IN ('paid', 'fulfilled')
            AND p.id = (SELECT MIN(p2.id) FROM payments p2 WHERE p2.order_id = o.id AND p2.status = 'succeeded')
          ORDER BY o.id LIMIT 200
        SQL
        break if rows.empty?

        rows.each do |row|
          id = row.fetch("order_id")
          created += 1 if create_alert(
            type: "payment.delivery_gap", correlation_id: "delivery-gap:#{id}",
            refs: { user_id: row.fetch("user_id"), order_id: id,
                    metadata: { provider: row.fetch("provider"), provider_payment_id: row["provider_payment_id"],
                                amount_minor: row.fetch("amount_minor").to_i, order_status: row.fetch("status") } },
            event_type: "payment.delivery_gap.detected",
            message: "Клиент заплатил, но услуга не выдана (заказ #{id.to_s.first(8)}, #{money(row.fetch('amount_minor'))} ₽, провайдер #{row.fetch('provider')}). Проверьте и зачислите вручную.",
            completion: "Ожидает ручного зачисления"
          ) ? 1 : 0
        end
        break if rows.length < 200
        after = rows.last.fetch("order_id")
      end
      created
    end

    # Returns true only when this invocation created the alert, so retries do
    # not duplicate event history or admin email notifications.
    def create_alert(type:, correlation_id:, refs:, event_type:, message:, completion:)
      now = Time.now.to_i
      trace_id = SecureRandom.hex(16)
      operation_id = "op_#{IdGenerator.call}"

      ApplicationRecord.transaction do
        existing = Operation.find_by(correlation_id: correlation_id)
        next false if existing

        operation = Operation.create!(id: operation_id, correlation_id: correlation_id,
          trace_id: trace_id, type: type, status: "processing", user_id: refs[:user_id],
          order_id: refs[:order_id], started_at: now, metadata: JSON.generate(refs[:metadata] || {}))
        create_event(operation, "#{type}.started", "processing", "Operation started", {}, now)
        create_event(operation, event_type, "failed", message, refs[:metadata] || {}, now)
        operation.update!(status: "failed", completed_at: now, last_error: completion)
        create_event(operation, "operation.completed", "failed", completion, refs[:metadata] || {}, now)
        true
      end
    rescue ActiveRecord::RecordNotUnique
      false
    end

    def create_event(operation, type, status, message, metadata, now)
      OperationEvent.create!(id: IdGenerator.call, operation_id: operation.id,
        correlation_id: operation.correlation_id, trace_id: operation.trace_id,
        user_id: operation.user_id, order_id: operation.order_id, type: type,
        status: status, message: message.to_s.first(255), metadata: JSON.generate(metadata),
        occurred_at: now, created_at: now)
    end

    def resolve_recovered_cases(now)
      expired = OperationalCase.where(status: "open", title: "Активная подписка с истёкшим сроком")
        .where.not(subscription_id: nil)
        .where("NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.id = operational_cases.subscription_id AND s.lifecycle_status = 'active' AND s.expires_at <= ?)", now)
        .update_all(status: "resolved", resolved_at: now)

      paid = OperationalCase.where(status: "open", title: "Оплата/заказ без выдачи подписки")
        .where(<<~SQL.squish)
          EXISTS (
            SELECT 1 FROM orders o JOIN subscriptions s ON s.order_id = o.id
            WHERE operational_cases.details LIKE '%"order_id":"' || o.id || '"%'
          )
        SQL
        .update_all(status: "resolved", resolved_at: now)
      expired + paid
    end

    def notify_admin(order_id)
      mailer = Integrations::Mailer.new
      return unless mailer.enabled?

      recipient = User.where(role: "admin").where.not(email: nil).order(:created_at).pick(:email)
      return if recipient.blank?

      short_id = ERB::Util.html_escape(order_id.to_s.first(8))
      mailer.queue(to_email: recipient,
        subject: "[ASTRACAT] Оплачен заказ без подписки: #{order_id.to_s.first(8)}",
        body_html: "<p>Клиент заплатил, но подписка не создана. Проверьте операцию <code>#{short_id}</code> в Ops и вручную восстановите выдачу.</p>")
    rescue StandardError
      # An unavailable notification channel must not prevent periodic repair.
      nil
    end

    def money(amount_minor)
      Payments::Amounts.decimal(amount_minor)
    end
  end
end
