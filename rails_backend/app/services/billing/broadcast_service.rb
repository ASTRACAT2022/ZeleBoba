module Billing
  class BroadcastService
    CHUNK_SIZE = 150
    CHUNK_DELAY = 45
    TARGETS = %w[all active inactive paid trial telegram email].freeze

    def initialize(outbox: Infrastructure::OutboxService.new)
      @outbox = outbox
    end

    def create(target_type:, text:, admin_id:, admin_name:, category: "system")
      text = text.to_s
      raise BillingError, "Текст рассылки: 1–4000 символов." unless text.length.between?(1, 4000)
      raise BillingError, "Некорректный сегмент." unless TARGETS.include?(target_type)
      now = Time.now.to_i
      ApplicationRecord.transaction do
        row = BroadcastHistory.create!(id: Infrastructure::IdGenerator.call, target_type: target_type,
          message_text: text, total_count: 0, status: "in_progress", admin_id: admin_id,
          admin_name: admin_name, category: category, created_at: now)
        @outbox.enqueue("broadcast.run", "broadcast:#{row.id}:1", { broadcast_id: row.id })
        audit(admin_id, "broadcast.created", row.id, now)
        row
      end
    end

    def run(broadcast_id)
      broadcast = BroadcastHistory.find_by(id: broadcast_id)
      return unless broadcast && %w[in_progress running].include?(broadcast.status)
      recipients = recipients_for(broadcast.target_type)
      if broadcast.status == "in_progress"
        broadcast.update!(total_count: recipients.length, status: "running")
      end
      remaining = recipients.reject do |chat_id|
        OutboxJob.exists?(dedup_key: "bsend:#{broadcast_id}:#{chat_id}") ||
          BroadcastDelivery.exists?(broadcast_id: broadcast_id, chat_id: chat_id)
      end
      batch = remaining.first(CHUNK_SIZE)
      batch.each do |chat_id|
        @outbox.enqueue("broadcast.send", "bsend:#{broadcast_id}:#{chat_id}",
          { broadcast_id: broadcast_id, chat_id: chat_id, text: broadcast.message_text })
      end
      if batch.empty?
        complete_if_finished(broadcast_id)
      elsif remaining.length > batch.length
        next_batch = OutboxJob.where(topic: "broadcast.run").where("dedup_key LIKE ?", "broadcast:#{broadcast_id}:%").count + 1
        @outbox.enqueue("broadcast.run", "broadcast:#{broadcast_id}:#{next_batch}",
          { broadcast_id: broadcast_id }, delay: CHUNK_DELAY)
      end
    end

    def mark_sent(broadcast_id, chat_id, ok)
      ApplicationRecord.transaction do
        now = Time.now.to_i
        inserted = BroadcastDelivery.insert_all(
          [{ broadcast_id: broadcast_id, chat_id: chat_id, outcome: ok ? "sent" : "failed", completed_at: now }],
          unique_by: %i[broadcast_id chat_id]
        ).count
        next if inserted.zero?
        column = ok ? :sent_count : :failed_count
        BroadcastHistory.where(id: broadcast_id).update_all("#{column} = #{column} + 1")
        complete_if_finished(broadcast_id)
      end
    end

    def list(limit: 50)
      BroadcastHistory.order(created_at: :desc).limit([[limit.to_i, 1].max, 100].min)
    end

    private

    def recipients_for(target)
      now = Time.now.to_i
      users = User.where(disabled: 0).where.not(telegram_id: [nil, ""])
      case target
      when "active"
        users.joins(:subscriptions).where(subscriptions: { status: %w[active trial] })
          .where("subscriptions.expires_at > ?", now).distinct.pluck(:telegram_id)
      when "inactive"
        users.where.not(id: Subscription.where(status: %w[active trial]).where("expires_at > ?", now).select(:user_id)).pluck(:telegram_id)
      when "paid"
        users.where(has_had_paid_subscription: 1).pluck(:telegram_id)
      when "trial"
        users.joins(:subscriptions).where(subscriptions: { is_trial: 1, status: %w[active trial] })
          .where("subscriptions.expires_at > ?", now).distinct.pluck(:telegram_id)
      when "all", "telegram"
        users.pluck(:telegram_id)
      else
        []
      end.compact.map(&:to_s).reject(&:blank?).uniq
    end

    def complete_if_finished(id)
      row = BroadcastHistory.find_by(id: id)
      return unless row && row.status == "running" && row.sent_count.to_i + row.failed_count.to_i >= row.total_count.to_i
      row.update!(status: "completed", completed_at: Time.now.to_i)
    end

    def audit(actor, action, subject, now)
      AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor, action: action, subject: subject, created_at: now)
    end
  end
end
