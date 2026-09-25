module Payments
  class WebhookTamperError < StandardError; end

  require "digest"

  class WebhookGuard
    def claim(provider, event_id, payload)
      digest = Digest::SHA256.hexdigest(JSON.generate(payload))
      now = Time.now.to_i
      ApplicationRecord.transaction do
        row = ApplicationRecord.connection.exec_query(
          "SELECT * FROM webhook_events WHERE provider = #{ApplicationRecord.connection.quote(provider)} AND id = #{ApplicationRecord.connection.quote(event_id)}"
        ).first
        unless row
          inserted = ApplicationRecord.connection.exec_query(
            "INSERT INTO webhook_events(id,provider,payload_sha256,processed,created_at) VALUES(#{ApplicationRecord.connection.quote(event_id)},#{ApplicationRecord.connection.quote(provider)},#{ApplicationRecord.connection.quote(digest)},0,#{now}) ON CONFLICT(provider,id) DO NOTHING RETURNING id"
          )
          next "new" if inserted.rows.any?
          row = ApplicationRecord.connection.exec_query(
            "SELECT * FROM webhook_events WHERE provider = #{ApplicationRecord.connection.quote(provider)} AND id = #{ApplicationRecord.connection.quote(event_id)}"
          ).first
        end
        raise WebhookTamperError, "Webhook payload mismatch" unless row
        raise WebhookTamperError, "Webhook payload mismatch" if row["payload_sha256"] != digest

        row["processed"].to_i == 1 ? "duplicate" : "same_payload"
      end
    end

    def processed(provider, event_id)
      now = Time.now.to_i
      ApplicationRecord.connection.execute(
        "UPDATE webhook_events SET processed=1, processed_at=#{now} WHERE provider = #{ApplicationRecord.connection.quote(provider)} AND id = #{ApplicationRecord.connection.quote(event_id)}"
      )
    end
  end
end
