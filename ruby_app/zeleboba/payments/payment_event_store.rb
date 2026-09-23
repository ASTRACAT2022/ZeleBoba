# frozen_string_literal: true

require "json"

require_relative "../billing/error"
require_relative "../infrastructure/database"
require_relative "../infrastructure/job_error"

module Zeleboba
  module Payments
    # Inbox for a provider callback. Receiving and scheduling are one local
    # transaction; the provider can safely retry when a process dies mid-way.
    class PaymentEventStore
      def initialize(db, outbox)
        @db = db
        @outbox = outbox
      end

      def receive(provider:, event_id:, payment_id:, payload:, signature_valid:)
        raise Billing::Error, "Некорректный платёж." unless payment_id.to_s.match?(/\A[a-zA-Z0-9:_-]{1,100}\z/)

        @db.transaction do
          existing = @db.one("SELECT * FROM payment_events WHERE provider=? AND provider_event_id=?#{@db.lock}", [provider, event_id])
          return existing if existing

          id = Infrastructure::Database.id
          now = Time.now.to_i
          status = signature_valid ? "pending" : "dead"
          @db.execute(
            "INSERT INTO payment_events(id,provider,provider_event_id,payment_id,payload,signature_valid,received_at,status,next_attempt_at) VALUES(?,?,?,?,?,?,?,?,?)",
            [id, provider, event_id, payment_id, JSON.generate(payload), signature_valid ? 1 : 0, now, status, signature_valid ? now : nil]
          )
          @outbox.enqueue("payment.event.process", "payment-event:#{id}", { "event_id" => id }) if signature_valid
          @db.one("SELECT * FROM payment_events WHERE id=?", [id])
        end
      end

      def claim(id)
        @db.transaction do
          event = @db.one("SELECT * FROM payment_events WHERE id=?#{@db.lock}", [id])
          return nil unless event && event["signature_valid"].to_i == 1
          return nil if %w[processed dead].include?(event["status"])
          now = Time.now.to_i
          return nil if event["status"] == "processing" && event["locked_until"].to_i > now
          return nil if %w[pending retry].include?(event["status"]) && event["next_attempt_at"].to_i > now

          token = Infrastructure::Database.id
          @db.execute("UPDATE payment_events SET status='processing',attempts=attempts+1,locked_until=?,lock_token=? WHERE id=?", [now + 120, token, id])
          event.merge("lock_token" => token, "attempts" => event["attempts"].to_i + 1, "status" => "processing")
        end
      end

      def processed(id, token)
        @db.execute("UPDATE payment_events SET status='processed',processed_at=?,processing_error=NULL,next_attempt_at=NULL,locked_until=NULL,lock_token=NULL WHERE id=? AND status='processing' AND lock_token=?", [Time.now.to_i, id, token])
      end

      def failed(id, token, error)
        @db.transaction do
          event = @db.one("SELECT attempts FROM payment_events WHERE id=? AND status='processing' AND lock_token=?#{@db.lock}", [id, token])
          return unless event
          dead = error.is_a?(Infrastructure::JobPermanentFailure) || event["attempts"].to_i >= 8
          @db.execute(
            "UPDATE payment_events SET status=?,processing_error=?,next_attempt_at=?,locked_until=NULL,lock_token=NULL WHERE id=? AND lock_token=?",
            [dead ? "dead" : "retry", "#{error.class}: #{error.message}"[0, 255], dead ? nil : Time.now.to_i + [3600, 2**event["attempts"].to_i].min, id, token]
          )
        end
      end
    end
  end
end
