module Billing
  class CompensationService
    SEGMENTS = %w[all active inactive paid trial telegram].freeze
    KINDS = %w[balance days traffic].freeze
    BATCH_SIZE = 200

    def initialize(outbox: Infrastructure::OutboxService.new, wallet: WalletService.new)
      @outbox, @wallet = outbox, wallet
    end

    def create(segment:, kind:, value:, reason:, admin_id:, admin_name:, plan_id: nil, request_key: nil)
      if request_key.present?
        raise BillingError, "Обновите форму компенсации." unless request_key.match?(/\A[a-f0-9]{32}\z/)
        existing = Compensation.find_by(id: request_key)
        return existing if existing
      end
      raise BillingError, "Некорректный сегмент." unless SEGMENTS.include?(segment)
      raise BillingError, "Некорректный тип компенсации." unless KINDS.include?(kind)
      maximum = { "balance" => 100_000_000, "days" => 3650, "traffic" => 100_000 }.fetch(kind)
      value = value.to_i
      raise BillingError, "Значение компенсации вне допустимого диапазона." unless value.between?(1, maximum)
      reason = reason.to_s.strip
      raise BillingError, "Причина: 3–200 символов." unless reason.length.between?(3, 200)
      plan = nil
      if kind == "days" && plan_id.present?
        plan = Plan.active.find_by(id: plan_id)
        raise BillingError, "Выберите активный тариф для новых подписок." unless plan
      end
      now = Time.now.to_i
      id = request_key || Infrastructure::IdGenerator.call

      ApplicationRecord.transaction do
        campaign = Compensation.create!(id: id, segment: segment, kind: kind, value: value,
          reason: reason, total_count: 0, processed_count: 0, status: "in_progress",
          admin_id: admin_id, admin_name: admin_name, created_at: now, plan_id: plan&.id,
          plan_traffic_gb: plan ? plan.traffic_bytes.to_i / 1.gigabyte : nil,
          plan_devices: plan&.devices)
        snapshot(campaign)
        campaign.update!(total_count: CompensationTarget.where(compensation_id: id).count)
        @outbox.enqueue("compensation.run", "compensation:#{id}:0", { compensation_id: id })
        audit(admin_id, "compensation.created", id, now)
        campaign
      end
    rescue ActiveRecord::RecordNotUnique
      Compensation.find_by(id: id) || raise
    end

    def run(id)
      ApplicationRecord.transaction do
        campaign = Compensation.lock.find_by(id: id)
        next unless campaign && %w[in_progress running].include?(campaign.status)
        if campaign.status == "in_progress" && campaign.total_count.to_i.zero?
          snapshot(campaign)
          campaign.update!(total_count: CompensationTarget.where(compensation_id: id).count)
        end
        if campaign.total_count.to_i.zero?
          campaign.update!(status: "completed", completed_at: Time.now.to_i)
          next
        end
        rows = CompensationTarget.where(compensation_id: id).where("user_id > ?", campaign.last_queued_user_id.to_s)
          .order(:user_id).limit(BATCH_SIZE).pluck(:user_id)
        rows.each do |user_id|
          @outbox.enqueue("compensation.grant", "cgrant:#{id}:#{user_id}", { compensation_id: id, user_id: user_id })
        end
        queued = campaign.queued_count.to_i + rows.length
        campaign.update!(queued_count: queued, last_queued_user_id: rows.last || campaign.last_queued_user_id.to_s,
          status: "running", queue_error: 0)
        @outbox.enqueue("compensation.run", "compensation:#{id}:#{queued}", { compensation_id: id }) if queued < campaign.total_count.to_i
      end
    end

    def grant(compensation_id, user_id)
      ApplicationRecord.transaction do
        target = CompensationTarget.lock.find_by(compensation_id: compensation_id, user_id: user_id)
        campaign = Compensation.find_by(id: compensation_id)
        next unless campaign&.status == "running"
        if !target && campaign.queued_count.to_i.zero? && campaign.total_count.to_i.positive?
          CompensationTarget.insert_all([{ compensation_id: compensation_id, user_id: user_id, status: "pending" }], unique_by: %i[compensation_id user_id])
          target = CompensationTarget.lock.find_by(compensation_id: compensation_id, user_id: user_id)
        end
        next unless target&.status == "pending"

        user = User.find_by(id: user_id)
        detail = if !user || user.disabled.to_i != 0
          "account_disabled"
        elsif campaign.kind == "balance"
          @wallet.credit(user_id, campaign.value.to_i, "manual_adjust", "Компенсация: #{campaign.reason}", external_id: "compensation:#{compensation_id}")
          nil
        elsif campaign.kind == "days"
          grant_days(campaign, user_id)
        elsif campaign.kind == "traffic"
          grant_traffic(campaign, user_id)
        else
          raise BillingError, "Некорректный тип компенсации."
        end
        status = detail.nil? ? "applied" : "skipped"
        if status == "applied"
          CompensationGrant.insert_all([{ id: Infrastructure::IdGenerator.call, compensation_id: compensation_id,
            user_id: user_id, created_at: Time.now.to_i }], unique_by: %i[compensation_id user_id])
        end
        target.update!(status: status, detail: detail, completed_at: Time.now.to_i)
        campaign.increment!(status == "applied" ? :processed_count : :skipped_count)
        complete_if_finished(campaign)
      end
    end

    def mark_failed(compensation_id, user_id)
      ApplicationRecord.transaction do
        campaign = Compensation.find_by(id: compensation_id)
        if campaign&.status == "running" && campaign.queued_count.to_i.zero? && campaign.total_count.to_i.positive?
          CompensationTarget.insert_all([{ compensation_id: compensation_id, user_id: user_id, status: "pending" }], unique_by: %i[compensation_id user_id])
        end
        changed = CompensationTarget.where(compensation_id: compensation_id, user_id: user_id, status: "pending")
          .update_all(status: "failed", detail: "worker_retry_exhausted", completed_at: Time.now.to_i)
        Compensation.where(id: compensation_id).update_all("failed_count = failed_count + 1") if changed.positive?
      end
    end

    def mark_run_failed(compensation_id)
      Compensation.where(id: compensation_id, status: %w[in_progress running]).update_all(queue_error: 1)
    end

    def retry_failed(compensation_id, admin_id)
      ApplicationRecord.transaction do
        campaign = Compensation.lock.find_by(id: compensation_id)
        raise BillingError, "Компенсация не найдена." unless campaign
        failed = CompensationTarget.where(compensation_id: compensation_id, status: "failed").pluck(:user_id)
        retried = 0
        failed.each do |user_id|
          job = OutboxJob.where(dedup_key: "cgrant:#{compensation_id}:#{user_id}", status: "dead")
            .update_all(status: "pending", attempts: 0, available_at: Time.now.to_i,
              locked_until: nil, lock_token: nil, last_error: nil)
          next if job.zero?
          CompensationTarget.where(compensation_id: compensation_id, user_id: user_id)
            .update_all(status: "pending", detail: nil, completed_at: nil)
          retried += 1
        end
        run_jobs = OutboxJob.where(topic: "compensation.run", status: "dead")
          .where("dedup_key LIKE ?", "compensation:#{compensation_id}:%")
          .update_all(status: "pending", attempts: 0, available_at: Time.now.to_i,
            locked_until: nil, lock_token: nil, last_error: nil)
        sync_jobs = OutboxJob.where(topic: %w[subscription.extend subscription.provision subscription.traffic], status: "dead")
          .where("dedup_key LIKE ? OR dedup_key LIKE ?", "comp-days:#{compensation_id}:%", "comp-traffic:#{compensation_id}:%")
          .update_all(status: "pending", attempts: 0, available_at: Time.now.to_i,
            locked_until: nil, lock_token: nil, last_error: nil)
        if retried.positive?
          campaign.update!(failed_count: [campaign.failed_count.to_i - retried, 0].max, status: "running", completed_at: nil)
        end
        campaign.update!(queue_error: 0) if run_jobs.positive?
        audit(admin_id, "compensation.retried", compensation_id, Time.now.to_i) if retried.positive? || run_jobs.positive? || sync_jobs.positive?
        retried + run_jobs + sync_jobs
      end
    end

    def reconcile_status
      Compensation.where(status: "running").where("processed_count + skipped_count = total_count AND failed_count = 0")
        .update_all(status: "completed", completed_at: Time.now.to_i)
    end

    def list(limit: 50)
      Compensation.order(created_at: :desc).limit([[limit.to_i, 1].max, 100].min).map do |row|
        prefix_days = "comp-days:#{row.id}:%"
        prefix_traffic = "comp-traffic:#{row.id}:%"
        sync = OutboxJob.where(topic: %w[subscription.extend subscription.provision subscription.traffic])
          .where("dedup_key LIKE ? OR dedup_key LIKE ?", prefix_days, prefix_traffic)
        row.as_json.merge(sync_pending_count: sync.where(status: %w[pending processing]).count,
                          sync_failed_count: sync.where(status: "dead").count)
      end
    end

    private

    def snapshot(campaign)
      now = campaign.created_at.to_i
      users = User.where(disabled: 0)
      users = case campaign.segment
      when "active"
        users.where(id: Subscription.where(status: %w[active trial]).where("expires_at > ?", now).select(:user_id))
      when "inactive"
        users.where.not(id: Subscription.where(status: %w[active trial]).where("expires_at > ?", now).select(:user_id))
      when "paid"
        users.where(has_had_paid_subscription: 1)
      when "trial"
        users.where(id: Subscription.where(is_trial: 1, status: %w[active trial]).where("expires_at > ?", now).select(:user_id))
      when "telegram"
        users.where.not(telegram_id: [nil, ""])
      when "all"
        users
      else
        raise BillingError, "Некорректный сегмент."
      end
      if campaign.kind == "traffic"
        users = users.where(id: Subscription.where(status: %w[active provisioning]).where("expires_at > ? AND traffic_limit_gb > 0", now).select(:user_id))
      end
      rows = users.pluck(:id).map { |user_id| { compensation_id: campaign.id, user_id: user_id, status: "pending" } }
      rows.each_slice(1000) { |batch| CompensationTarget.insert_all(batch, unique_by: %i[compensation_id user_id]) } if rows.any?
    end

    def grant_days(campaign, user_id)
      now = Time.now.to_i
      subscription = Subscription.where(user_id: user_id, status: %w[active trial provisioning])
        .where("expires_at > ?", now).order(expires_at: :desc).lock.first
      if subscription
        status = subscription.status == "provisioning" ? "provisioning" : "active"
        lifecycle = status == "provisioning" ? "pending" : "active"
        subscription.update!(expires_at: subscription.expires_at.to_i + campaign.value.to_i.days.to_i,
          status: status, lifecycle_status: lifecycle, updated_at: now, version: subscription.version.to_i + 1)
        topic = status == "provisioning" ? "subscription.provision" : "subscription.extend"
        @outbox.enqueue(topic, "comp-days:#{campaign.id}:#{subscription.id}", { subscription_id: subscription.id })
      else
        return "no_active_subscription" unless campaign.plan_id.present?
        subscription = Subscription.create!(id: Infrastructure::IdGenerator.call, order_id: nil, user_id: user_id,
          status: "provisioning", lifecycle_status: "pending", expires_at: now + campaign.value.to_i.days.to_i,
          created_at: now, updated_at: now, starts_at: now, plan_id: campaign.plan_id,
          traffic_limit_gb: campaign.plan_traffic_gb.to_i, traffic_limit_bytes: campaign.plan_traffic_gb.to_i.gigabytes,
          device_limit: campaign.plan_devices.to_i, is_trial: 0, start_date: now)
        @outbox.enqueue("subscription.provision", "comp-days:#{campaign.id}:#{subscription.id}", { subscription_id: subscription.id })
      end
      audit("system", "compensation.days", user_id, now)
      nil
    end

    def grant_traffic(campaign, user_id)
      now = Time.now.to_i
      subscription = Subscription.where(user_id: user_id, status: %w[active provisioning])
        .where("expires_at > ? AND traffic_limit_gb > 0", now).order(expires_at: :desc).lock.first
      return "no_limited_active_subscription" unless subscription
      subscription.update!(purchased_traffic_gb: subscription.purchased_traffic_gb.to_i + campaign.value.to_i, updated_at: now)
      @outbox.enqueue("subscription.traffic", "comp-traffic:#{campaign.id}:#{subscription.id}",
        { subscription_id: subscription.id, traffic_gb: campaign.value.to_i })
      audit("system", "compensation.traffic", user_id, now)
      nil
    end

    def complete_if_finished(campaign)
      campaign.reload
      if campaign.status == "running" && campaign.processed_count.to_i + campaign.skipped_count.to_i == campaign.total_count.to_i && campaign.failed_count.to_i.zero?
        campaign.update!(status: "completed", completed_at: Time.now.to_i)
      end
    end

    def audit(actor, action, subject, now)
      AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor, action: action, subject: subject, created_at: now)
    end
  end
end
