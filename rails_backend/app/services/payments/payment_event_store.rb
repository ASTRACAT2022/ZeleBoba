module Payments
  require "digest"

  class PaymentEventStore
    SAFE_HEADERS = %w[content-type x-request-id x-signature x-signature-sha256 x-webhook-id x-merchantid x-merchant-id].freeze

    def receive(provider, event_id, payment_id, payload, signature_valid:, headers: {})
      ApplicationRecord.transaction do
        id = Infrastructure::IdGenerator.call
        raw_id = Infrastructure::IdGenerator.call
        now = Time.now.to_i
        json = JSON.generate(payload)
        correlation = "cor_#{Digest::SHA256.hexdigest("#{provider}:#{payment_id || event_id}")[0, 40]}"

        IncomingWebhook.insert_all(
          [{
            id: raw_id,
            provider: provider,
            provider_event_id: event_id,
            headers: JSON.generate(safe_headers(headers)),
            payload: json,
            received_at: now,
            status: signature_valid ? "pending" : "dead",
            correlation_id: correlation
          }],
          unique_by: %i[provider provider_event_id]
        )
        PaymentEvent.insert_all(
          [{
            id: id,
            provider: provider,
            provider_event_id: event_id,
            payment_id: payment_id,
            payload: json,
            signature_valid: signature_valid ? 1 : 0,
            received_at: now,
            status: signature_valid ? "pending" : "dead",
            next_attempt_at: signature_valid ? now : nil
          }],
          unique_by: %i[provider provider_event_id]
        )
        PaymentEvent.find_by!(provider: provider, provider_event_id: event_id).id
      end
    end

    def claim(id)
      ApplicationRecord.transaction do
        event = PaymentEvent.lock.find_by(id: id)
        next nil unless event
        next nil if event.signature_valid.to_i != 1 || %w[processed dead].include?(event.status)

        now = Time.now.to_i
        next nil if event.status == "processing" && event.locked_until.to_i > now
        next nil if %w[pending retry].include?(event.status) && event.next_attempt_at.to_i > now

        token = Infrastructure::IdGenerator.call
        event.update!(status: "processing", attempts: event.attempts.to_i + 1, locked_until: now + 120, lock_token: token)
        event
      end
    end

    def processed(id, token)
      ApplicationRecord.transaction do
        event = PaymentEvent.lock.find_by(id: id)
        next unless event && event.status == "processing" && event.lock_token == token

        now = Time.now.to_i
        event.update!(status: "processed", processed_at: now, processing_error: nil, next_attempt_at: nil, locked_until: nil, lock_token: nil)
        IncomingWebhook.where(provider: event.provider, provider_event_id: event.provider_event_id).update_all(status: "processed", processed_at: now, last_error: nil)
      end
    end

    def failed(id, token, error)
      ApplicationRecord.transaction do
        event = PaymentEvent.lock.find_by(id: id, status: "processing", lock_token: token)
        next unless event

        attempts = event.attempts.to_i
        dead = attempts >= 8
        event.update!(
          status: dead ? "dead" : "retry",
          processing_error: error.class.name,
          next_attempt_at: dead ? nil : Time.now.to_i + [3600, 2**attempts].min + rand(0..5),
          locked_until: nil,
          lock_token: nil
        )
        IncomingWebhook.where(provider: event.provider, provider_event_id: event.provider_event_id)
                       .update_all(status: dead ? "dead" : "retry", attempts: attempts, last_error: error.class.name)
      end
    end

    private

    def safe_headers(headers)
      headers.each_with_object({}) do |(name, value), result|
        key = name.to_s.downcase
        result[key] = Array(value).map(&:to_s) if SAFE_HEADERS.include?(key)
      end
    end
  end
end
