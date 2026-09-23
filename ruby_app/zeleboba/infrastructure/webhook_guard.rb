# frozen_string_literal: true

require "digest"
require "json"

module Zeleboba
  module Infrastructure
    # Durable replay/tamper guard. A duplicate event is harmless; reusing the
    # same provider event id with a different body is never silently accepted.
    class WebhookGuard
      def initialize(db)
        @db = db
      end

      def claim(provider, event_id, payload)
        validate_identifier!(provider, event_id)
        digest = Digest::SHA256.hexdigest(JSON.generate(payload))
        @db.transaction do
          existing = @db.one("SELECT * FROM webhook_events WHERE provider=? AND id=?#{@db.lock}", [provider, event_id])
          unless existing
            @db.execute("INSERT INTO webhook_events(id,provider,payload_sha256,processed,created_at) VALUES(?,?,?,0,?)", [event_id, provider, digest, Time.now.to_i])
            next "new"
          end
          raise Billing::Error, "Webhook payload mismatch for #{provider}/#{event_id}." unless existing["payload_sha256"] == digest

          existing["processed"].to_i == 1 ? "duplicate" : "same_payload"
        end
      end

      def mark_processed(provider, event_id)
        @db.execute("UPDATE webhook_events SET processed=1,processed_at=? WHERE provider=? AND id=?", [Time.now.to_i, provider, event_id])
      end

      private

      def validate_identifier!(provider, event_id)
        raise Billing::Error, "Некорректный webhook provider." unless provider.to_s.match?(/\A[a-z0-9_-]{2,30}\z/)
        raise Billing::Error, "Некорректный webhook event id." unless event_id.to_s.match?(/\A[a-zA-Z0-9:_-]{1,160}\z/)
      end
    end
  end
end
