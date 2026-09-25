module Payments
  class PaymentAttemptStore
    def begin_attempt(type, entity)
      key = "checkout:#{type}:#{entity.id}"
      now = Time.now.to_i
      ApplicationRecord.transaction do
        existing = PaymentAttempt.lock.find_by(idempotency_key: key)
        if existing
          existing.define_singleton_method(:created?) { false }
          next existing
        end

        attempt = PaymentAttempt.create!(
          id: Infrastructure::IdGenerator.call,
          entity_type: type,
          entity_id: entity.id,
          user_id: entity.user_id,
          provider: entity.provider,
          amount_minor: type == "order" ? entity.price_minor : entity.amount_kopeks,
          currency: entity.currency,
          status: "creating",
          idempotency_key: key,
          created_at: now,
          updated_at: now,
          correlation_id: key
        )
        attempt.define_singleton_method(:created?) { true }
        attempt
      end
    end

    def attached(id, payment_id, checkout_url)
      PaymentAttempt.where(id: id, status: %w[creating unknown pending]).update_all(
        provider_payment_id: payment_id,
        checkout_url: checkout_url,
        status: "pending",
        updated_at: Time.now.to_i,
        last_error: nil
      )
    end

    def unknown(id, error)
      PaymentAttempt.where(id: id, status: "creating").update_all(status: "unknown", last_error: error.class.name, updated_at: Time.now.to_i)
    end

    def completed(provider, payment_id, status)
      terminal = status == "paid" ? "succeeded" : (status == "canceled" ? "cancelled" : status)
      PaymentAttempt.where(provider: provider, provider_payment_id: payment_id)
                    .where.not(status: %w[succeeded cancelled failed expired])
                    .update_all(status: terminal, completed_at: Time.now.to_i, updated_at: Time.now.to_i)
    end
  end
end
