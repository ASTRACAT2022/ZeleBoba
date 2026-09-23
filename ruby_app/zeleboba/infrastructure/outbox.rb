# frozen_string_literal: true

require "json"

require_relative "database"
require_relative "job_error"

module Zeleboba
  module Infrastructure
    class Outbox
      PRIORITY = {
        "payment.create" => 90,
        "subscription.provision" => 80,
        "telegram.send" => 60
      }.freeze

      def initialize(db)
        @db = db
      end

      def enqueue(topic, key, payload, delay: 0)
        raise ArgumentError, "Outbox topic is required" if topic.to_s.empty?
        raise ArgumentError, "Outbox deduplication key is required" if key.to_s.empty?

        @db.execute(
          "INSERT INTO outbox(id,topic,dedup_key,payload,priority,available_at,created_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(dedup_key) DO NOTHING",
          [Database.id, topic, key, JSON.generate(payload), priority(topic), Time.now.to_i + delay, Time.now.to_i]
        )
      end

      def run_one
        job = @db.transaction do
          reclaim_expired_leases!
          row = @db.one(
            "SELECT * FROM outbox WHERE status='pending' AND available_at<=? ORDER BY priority DESC, created_at,id LIMIT 1#{@db.lock}",
            [Time.now.to_i]
          )
          next nil unless row

          token = Database.id
          @db.execute(
            "UPDATE outbox SET status='processing', attempts=attempts+1, locked_until=?, lock_token=? WHERE id=? AND status='pending'",
            [Time.now.to_i + lease_seconds, token, row["id"]]
          )
          row.merge("lock_token" => token)
        end
        return false unless job

        yield(job["topic"], JSON.parse(job["payload"], symbolize_names: false))
        @db.execute("UPDATE outbox SET status='done',locked_until=NULL,last_error=NULL,payload='{}' WHERE id=? AND lock_token=?", [job["id"], job["lock_token"]])
        true
      rescue JobDeferred => e
        retry_job(job, e.message, delay: e.delay) if job
        false
      rescue JobPermanentFailure => e
        dead_letter(job, e.message) if job
        false
      rescue StandardError => e
        if job
          attempt = job["attempts"].to_i + 1
          attempt >= max_attempts ? dead_letter(job, "#{e.class}: #{e.message}") : retry_job(job, "#{e.class}: #{e.message}", delay: backoff(attempt))
        end
        raise
      end

      def priority(topic)
        PRIORITY.fetch(topic, 30)
      end

      private

      def reclaim_expired_leases!
        @db.execute(
          "UPDATE outbox SET status='pending',locked_until=NULL,lock_token=NULL,last_error=COALESCE(last_error,'worker lease expired') WHERE status='processing' AND locked_until<?",
          [Time.now.to_i]
        )
      end

      def retry_job(job, message, delay:)
        @db.execute(
          "UPDATE outbox SET status='pending',available_at=?,locked_until=NULL,lock_token=NULL,last_error=? WHERE id=? AND lock_token=?",
          [Time.now.to_i + delay, message.to_s[0, 500], job["id"], job["lock_token"]]
        )
      end

      def dead_letter(job, message)
        @db.execute(
          "UPDATE outbox SET status='dead',locked_until=NULL,lock_token=NULL,last_error=? WHERE id=? AND lock_token=?",
          [message.to_s[0, 500], job["id"], job["lock_token"]]
        )
      end

      def lease_seconds = 120
      def max_attempts = 8
      def backoff(attempt) = [3600, 2**attempt].min
    end
  end
end
