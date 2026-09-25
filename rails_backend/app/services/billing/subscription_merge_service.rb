module Billing
  class SubscriptionMergeService
    MERGEABLE_STATES = %w[active provisioning trial pending].freeze

    def merge(user_id:, source_id:, target_id:)
      raise BillingError, "Некорректный идентификатор подписки." if source_id.to_s.blank? || target_id.to_s.blank? || source_id.to_s.length > 32 || target_id.to_s.length > 32
      raise BillingError, "Нельзя объединить подписку с самой собой." if source_id == target_id

      source_sync = nil
      result = ApplicationRecord.transaction do
        ids = [source_id, target_id].sort
        locked = Subscription.where(id: ids).order(:id).lock.index_by(&:id)
        source = locked[source_id]
        target = locked[target_id]
        assert_mergeable!(source, user_id, "исходная")
        assert_mergeable!(target, user_id, "целевая")
        raise BillingError, "Целевая подписка уже истекла." if target.expires_at.to_i <= Time.now.to_i && target.expires_at.to_i != 0

        now = Time.now.to_i
        remaining_days = [0, (source.expires_at.to_i - now + 86_399) / 86_400].max
        expiry = remaining_days.positive? ? SubscriptionTerms.expiry_after([now, target.expires_at.to_i].max, remaining_days) : target.expires_at.to_i
        source_limit = source.traffic_limit_gb.to_i
        target_limit = target.traffic_limit_gb.to_i
        unlimited_traffic = source_limit.zero? || target_limit.zero?
        merged_limit = unlimited_traffic ? 0 : source_limit + target_limit
        merged_purchased = unlimited_traffic ? 0 : source.purchased_traffic_gb.to_i + target.purchased_traffic_gb.to_i
        source_devices = source.device_limit.to_i
        target_devices = target.device_limit.to_i
        merged_devices = source_devices.zero? || target_devices.zero? ? 0 : source_devices + target_devices

        target.update!(expires_at: expiry, traffic_limit_gb: merged_limit,
          purchased_traffic_gb: merged_purchased,
          traffic_limit_bytes: merged_limit.zero? ? 0 : (merged_limit + merged_purchased) * 1.gigabyte,
          traffic_used_gb: source.traffic_used_gb.to_s.to_f + target.traffic_used_gb.to_s.to_f,
          device_limit: merged_devices, status: "active", lifecycle_status: "active",
          updated_at: now, version: target.version.to_i + 1)

        source_sync = {
          id: source.id, remote_id: source.remote_id, remnawave_id: source.remnawave_id,
          remnawave_short_uuid: source.remnawave_short_uuid
        }
        remove_source_jobs(source_id)
        source.destroy!

        Infrastructure::OutboxService.new.enqueue(
          "subscription.admin_sync", "merge-sync:#{target.id}:#{source_id}", { subscription_id: target.id }
        )
        AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: "user:#{user_id}",
          action: "subscription.merged", subject: "#{target_id}:#{remaining_days}d", created_at: now)
        target
      end
      if source_sync[:remote_id].present? || source_sync[:remnawave_id].present? || source_sync[:remnawave_short_uuid].present?
        begin
          ProvisioningService.new.disable_remote(source_sync)
        rescue StandardError => error
          Rails.logger.error("merge.source_disable_failed subscription_id=#{source_id} error=#{error.class}")
        end
      end
      result
    end

    private

    def assert_mergeable!(subscription, user_id, label)
      raise BillingError, "Подписка не найдена." unless subscription && subscription.user_id == user_id
      state = subscription.lifecycle_status.presence || subscription.status
      raise BillingError, "#{label.capitalize} подписка недоступна для объединения." unless MERGEABLE_STATES.include?(state)
    end

    def remove_source_jobs(source_id)
      topics = %w[subscription.provision subscription.extend subscription.renew subscription.daily]
      OutboxJob.where(topic: topics).find_each do |job|
        payload = JSON.parse(job.payload.presence || "{}")
        next unless payload["subscription_id"].to_s == source_id
        job.destroy!
      rescue JSON::ParserError
        next
      end
    end
  end
end
