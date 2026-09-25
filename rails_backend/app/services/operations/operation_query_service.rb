module Operations
  class OperationQueryService
    def search(query: "", type: "", status: "", since: 0, client: "", provider: "", http_status: "", period: "")
      scope = Operation.left_joins(:user, :payment)
      scope = scope.where(type: type) if type.present?
      scope = scope.where(status: status) if status.present?
      cutoff = Integer(since || 0)
      cutoff = period_start(period) if period.present?
      scope = scope.where("operations.started_at >= ?", cutoff) if cutoff.positive?
      scope = scope.where("operations.user_id = :client OR users.email = :client", client: client) if client.present?
      scope = scope.where(payments: { provider: provider }) if provider.present?
      scope = scope.joins("INNER JOIN operation_steps filter_steps ON filter_steps.operation_id = operations.id")
        .where("filter_steps.http_status = ?", Integer(http_status)) if http_status.present?

      if query.present?
        pattern = "%#{ActiveRecord::Base.sanitize_sql_like(query.to_s.downcase)}%"
        scope = scope.where(
          "LOWER(operations.id) LIKE :q OR LOWER(operations.correlation_id) LIKE :q OR LOWER(operations.trace_id) LIKE :q OR " \
          "LOWER(COALESCE(operations.user_id, '')) LIKE :q OR LOWER(COALESCE(operations.subscription_id, '')) LIKE :q OR " \
          "LOWER(COALESCE(operations.order_id, '')) LIKE :q OR LOWER(COALESCE(payments.provider_payment_id, '')) LIKE :q",
          q: pattern
        )
      end

      scope.distinct.order(started_at: :desc).limit(100).map { |row| operation_summary(row) }
    rescue ArgumentError, TypeError
      raise Billing::BillingError, "Некорректный фильтр операций."
    end

    def detail(id)
      operation = Operation.includes(:user, :subscription, :order, :payment).find_by(id: id)
      return unless operation

      data = operation_summary(operation)
      data[:metadata] = parse_json(operation.metadata)
      data[:events] = operation.operation_events.order(:occurred_at, :id).map do |event|
        event.as_json(only: %i[id correlation_id trace_id span_id user_id subscription_id order_id payment_id type status message occurred_at created_at])
          .merge("metadata" => parse_json(event.metadata))
      end
      data[:steps] = step_tree(OperationStep.where(operation_id: operation.id).order(:occurred_at, :id).map { |step| step_json(step) })
      data[:retries] = OperationRetry.where(operation_id: operation.id).order(:attempt).map do |retry_row|
        retry_row.as_json.merge("metadata" => parse_json(retry_row.metadata))
      end
      data[:outbox] = OutboxJob.where(correlation_id: operation.correlation_id).order(:created_at)
        .as_json(only: %i[id topic status attempts available_at last_error created_at])
      data[:provisioning] = ProvisioningAccount.find_by(subscription_id: operation.subscription_id)&.as_json if operation.subscription_id.present?
      data
    end

    def support_summary(id)
      detail = self.detail(id)
      return unless detail
      nodes = flatten_steps(detail[:steps] || [])
      summary = { payment: nil, invoice: nil, subscription: nil, provisioning: nil,
        notification: nil, problem: nodes.find { |step| step["status"] == "failed" }, last_retry: detail[:retries]&.last }
      nodes.each do |step|
        category = step["category"].to_s.downcase
        summary[:payment] = step if category.include?("payment") || category.include?("webhook")
        summary[:invoice] = step if category.include?("invoice")
        summary[:subscription] = step if category.include?("subscription")
        summary[:provisioning] = step if category.include?("provisioning")
        summary[:notification] = step if category.include?("notification") || category.include?("email")
      end
      summary
    end

    def provisioning_accounts
      ProvisioningAccount.joins(subscription: :user).order(
        Arel.sql("CASE provisioning_accounts.state WHEN 'failed' THEN 0 WHEN 'retry' THEN 1 ELSE 2 END"),
        updated_at: :desc
      ).limit(200).map do |account|
        account.as_json.merge("expires_at" => account.subscription.expires_at,
          "lifecycle_status" => account.subscription.lifecycle_status,
          "email" => account.subscription.user.email,
          "telegram_id" => account.subscription.user.telegram_id)
      end
    end

    def explain_subscription(id)
      subscription = Subscription.find_by(id: id)
      return unless subscription
      events = OperationEvent.where(subscription_id: id).order(occurred_at: :desc).limit(8).reverse
      reasons = events.map { |event| { at: event.occurred_at, text: event.message, status: event.status } }
      reasons = [{ at: subscription.updated_at || subscription.created_at,
        text: "No recorded business event for this legacy subscription", status: "warning" }] if reasons.empty?
      { status: subscription.lifecycle_status.presence || subscription.status,
        expires_at: subscription.expires_at, reasons: reasons,
        provisioning: ProvisioningAccount.find_by(subscription_id: id)&.as_json(only: %i[state last_synced_at last_error]) }
    end

    private

    def operation_summary(operation)
      payment = operation.payment
      user = operation.user
      { id: operation.id, correlation_id: operation.correlation_id, trace_id: operation.trace_id,
        type: operation.type, status: operation.status, user_id: operation.user_id,
        subscription_id: operation.subscription_id, order_id: operation.order_id,
        payment_id: operation.payment_id, started_at: operation.started_at,
        completed_at: operation.completed_at, last_error: operation.last_error,
        duration_ms: operation.completed_at ? (operation.completed_at.to_i - operation.started_at.to_i) * 1000 : nil,
        email: user&.email, telegram_id: user&.telegram_id, provider: payment&.provider,
        provider_payment_id: payment&.provider_payment_id, amount_minor: payment&.amount_minor,
        currency: payment&.currency, metadata: parse_json(operation.metadata) }
    end

    def period_start(period)
      now = Time.now.to_i
      case period.to_s
      when "today" then Time.now.utc.beginning_of_day.to_i
      when "24h" then now - 86_400
      when "7d" then now - 7 * 86_400
      when "30d" then now - 30 * 86_400
      else 0
      end
    end

    def parse_json(value)
      JSON.parse(value.presence || "{}")
    rescue JSON::ParserError
      {}
    end

    def step_json(step)
      step.as_json.merge("request_metadata" => parse_json(step.request_metadata),
        "response_metadata" => parse_json(step.response_metadata), "children" => [])
    end

    def step_tree(rows, parent_id = nil)
      rows.select { |row| row["parent_step_id"] == parent_id }.map do |row|
        row.merge("children" => step_tree(rows, row["id"]))
      end
    end

    def flatten_steps(rows)
      rows.flat_map { |row| [row] + flatten_steps(row["children"] || []) }
    end
  end
end
