# frozen_string_literal: true

module Zeleboba
  module Infrastructure
    class Scheduler
      def initialize(db, outbox, subscriptions, renewals, trials)
        @db = db
        @outbox = outbox
        @subscriptions = subscriptions
        @renewals = renewals
        @trials = trials
      end

      def run_once(now: Time.now.to_i)
        @subscriptions.expire_due(now: now)
        @trials.expire_overdue(now: now)
        due = @db.all("SELECT id FROM subscriptions WHERE auto_renew=1 AND status='active' AND expires_at>? AND renew_order_id IS NULL AND renew_at IS NOT NULL AND renew_at<=?", [now, now])
        due.each do |subscription|
          @outbox.enqueue("subscription.renew", "renew:#{subscription["id"]}:#{now / 60}", { "subscription_id" => subscription["id"] })
        end
        heartbeat("scheduler", now)
        due.length
      end

      private

      def heartbeat(name, now)
        @db.execute("INSERT INTO runtime_heartbeats(name,seen_at) VALUES(?,?) ON CONFLICT(name) DO UPDATE SET seen_at=excluded.seen_at", [name, now])
      end
    end
  end
end
