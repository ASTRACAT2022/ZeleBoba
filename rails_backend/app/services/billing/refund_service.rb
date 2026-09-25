module Billing
  class RefundService
    def initialize(wallet: WalletService.new)
      @wallet = wallet
    end

    def request(payment_id:, amount_minor:, reason:, actor:)
      amount = Integer(amount_minor)
      reason = reason.to_s.strip
      raise BillingError, "Сумма возврата должна быть положительной." unless amount.positive?
      raise BillingError, "Укажите причину возврата." if reason.blank? || reason.length > 500

      ApplicationRecord.transaction do
        Infrastructure::Locks.advisory!("refund:#{payment_id}")
        payment = Payment.lock.find_by(id: payment_id, status: "succeeded")
        raise BillingError, "Успешный платёж не найден." unless payment

        key = "refund:#{payment.id}:#{Digest::SHA256.hexdigest("#{reason}:#{amount}")}"
        existing = RefundRequest.find_by(idempotency_key: key)
        next existing if existing

        refunded = RefundRequest.where(payment_id: payment.id, status: %w[pending processing unknown succeeded]).sum(:amount_minor)
        raise BillingError, "Сумма возвратов превышает платёж." if amount > payment.amount_minor.to_i - refunded.to_i

        now = Time.now.to_i
        id = Infrastructure::IdGenerator.call
        correlation = "refund:#{Digest::SHA256.hexdigest("#{payment.id}:#{id}")[0, 40]}"
        refund = RefundRequest.create!(
          id: id, payment_id: payment.id, order_id: payment.order_id, user_id: payment.user_id,
          amount_minor: amount, currency: payment.currency, reason: reason, status: "succeeded",
          idempotency_key: key, provider_refund_id: id, correlation_id: correlation,
          created_at: now, updated_at: now, completed_at: now
        )
        @wallet.credit(payment.user_id, amount, "refund", "Возврат по платежу #{payment.id}: #{reason}",
                       payment_method: "internal_balance", external_id: refund.id)
        AuditEvent.create!(
          id: Infrastructure::IdGenerator.call, entity_type: "refund", entity_id: refund.id,
          event_type: "refund.completed", actor_type: "user", actor_id: actor,
          reason: reason, correlation_id: correlation, created_at: now
        )
        refund
      end
    rescue ArgumentError, TypeError
      raise BillingError, "Некорректная сумма возврата."
    end

    def history(limit: 50)
      RefundRequest.order(created_at: :desc).limit([[limit.to_i, 1].max, 100].min)
    end
  end
end
