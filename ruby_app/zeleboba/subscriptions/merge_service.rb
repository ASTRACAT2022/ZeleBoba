# frozen_string_literal: true

require_relative "../billing/error"
require_relative "../infrastructure/database"

module Zeleboba
  module Subscriptions
    # Transfers an entitlement without deleting financial/audit evidence.
    class MergeService
      def initialize(db, outbox)
        @db = db
        @outbox = outbox
      end

      def merge(user_id, source_id, target_id, now: Time.now.to_i)
        raise Billing::Error, "Нельзя объединить подписку с самой собой." if source_id.to_s == target_id.to_s
        validate_id!(source_id)
        validate_id!(target_id)

        @db.transaction do
          first_id, second_id = [source_id, target_id].sort
          first = locked_subscription(first_id)
          second = locked_subscription(second_id)
          source = first["id"] == source_id ? first : second
          target = first["id"] == target_id ? first : second
          assert_mergeable!(source, user_id, "исходная")
          assert_mergeable!(target, user_id, "целевая")
          raise Billing::Error, "Целевая подписка уже истекла." if target["expires_at"].to_i <= now

          remaining_days = [(source["expires_at"].to_i - now + 86_399) / 86_400, 0].max
          expiry = target["expires_at"].to_i + remaining_days * 86_400
          traffic_limit, purchased = merged_traffic(source, target)
          devices = merged_devices(source, target)
          used = source["traffic_used_gb"].to_f + target["traffic_used_gb"].to_f
          traffic_bytes = traffic_limit.zero? ? 0 : (traffic_limit + purchased) * 1_073_741_824

          @db.execute(
            "UPDATE subscriptions SET expires_at=?,traffic_limit_gb=?,purchased_traffic_gb=?,device_limit=?,traffic_used_gb=?,traffic_limit_bytes=?,status='active',lifecycle_status='active',updated_at=?,version=version+1 WHERE id=?",
            [expiry, traffic_limit, purchased, devices, used, traffic_bytes, now, target_id]
          )
          # Soft cancellation keeps a complete audit/financial graph. Remote
          # revocation is executed by a worker and is therefore retryable.
          @db.execute("UPDATE subscriptions SET status='disabled',lifecycle_status='cancelled',auto_renew=0,renew_at=NULL,updated_at=? WHERE id=?", [now, source_id])
          @db.execute("UPDATE provisioning_accounts SET state='failed',last_error='merged_into:#{target_id}',updated_at=? WHERE subscription_id=? AND state<>'active'", [now, source_id])
          @outbox.enqueue("subscription.extend", "merge-extend:#{target_id}:#{source_id}", { "subscription_id" => target_id })
          @outbox.enqueue("subscription.revoke", "merge-revoke:#{source_id}:#{target_id}", { "subscription_id" => source_id })
          audit(user_id, source_id, target_id, remaining_days)
          @db.one("SELECT * FROM subscriptions WHERE id=?", [target_id])
        end
      end

      private

      def locked_subscription(id)
        @db.one("SELECT * FROM subscriptions WHERE id=?#{@db.lock}", [id]) || raise(Billing::Error, "Подписка не найдена.")
      end

      def assert_mergeable!(subscription, user_id, label)
        raise Billing::Error, "#{label.capitalize} подписка принадлежит другому аккаунту." unless subscription["user_id"] == user_id
        allowed = %w[pending provisioning active trial]
        state = subscription["lifecycle_status"].to_s.empty? ? subscription["status"] : subscription["lifecycle_status"]
        raise Billing::Error, "#{label.capitalize} подписка недоступна для объединения." unless allowed.include?(state)
      end

      def merged_traffic(source, target)
        source_limit = source["traffic_limit_gb"].to_i
        target_limit = target["traffic_limit_gb"].to_i
        return [0, 0] if source_limit.zero? || target_limit.zero?

        [source_limit + target_limit, source["purchased_traffic_gb"].to_i + target["purchased_traffic_gb"].to_i]
      end

      def merged_devices(source, target)
        source_devices = source["device_limit"].to_i
        target_devices = target["device_limit"].to_i
        source_devices.zero? || target_devices.zero? ? 0 : source_devices + target_devices
      end

      def validate_id!(value)
        raise Billing::Error, "Некорректный идентификатор подписки." unless value.to_s.match?(/\A[a-f0-9]{32}\z/)
      end

      def audit(user_id, source_id, target_id, days)
        @db.execute("INSERT INTO audit_log VALUES(?,?,?,?,?)", [Infrastructure::Database.id, user_id, "subscription.merged", "#{source_id}:#{target_id}:#{days}d", Time.now.to_i])
      end
    end
  end
end
