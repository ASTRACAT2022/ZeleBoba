module Infrastructure
  class RequestRateLimiter
    def allow?(key, limit:, window:)
      bucket = Digest::SHA256.hexdigest(key)
      now = Time.now.to_i
      ApplicationRecord.transaction do
        row = ApplicationRecord.connection.exec_query(
          "INSERT INTO rate_limits(bucket,hits,expires_at) VALUES(#{ApplicationRecord.connection.quote(bucket)},1,#{now + window}) " +          "ON CONFLICT(bucket) DO UPDATE SET " +          "hits=CASE WHEN rate_limits.expires_at <= #{now} THEN 1 ELSE rate_limits.hits + 1 END, " +          "expires_at=CASE WHEN rate_limits.expires_at <= #{now} THEN #{now + window} ELSE rate_limits.expires_at END " +          "RETURNING hits"
        ).first
        row && row["hits"].to_i <= limit
      end
    end
  end
end
