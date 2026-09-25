module Infrastructure
  class OutboxRunner
    SUPPORTED_TOPICS = %w[payment.create topup.create topup.after referral.topup payment.verify payment.event.process subscription.provision subscription.extend subscription.traffic subscription.devices subscription.admin_sync subscription.remove subscription.renew subscription.daily compensation.run compensation.grant broadcast.run broadcast.send telegram.send telegram.answer telegram.membership_check gift.create].freeze

    def initialize(payment_service: Payments::PaymentService.new, provisioning: nil)
      @payment_service = payment_service
      @provisioning = provisioning
      @last_recovery_at = 0
      @last_scheduler_at = 0
    end

    def run_one
      run_recovery_if_due
      job = claim_job
      return false unless job

      payload = JSON.parse(job.payload.presence || "{}")
      handle(job.topic, payload)
      complete(job)
      true
    rescue JobDeferred => e
      defer_job(job, e) if job
      true
    rescue JobPermanentFailure => e
      mark_permanent(job, e) if job
      true
    rescue StandardError => e
      fail_job(job, e) if job
      raise
    end

    private

    def claim_job
      ApplicationRecord.transaction do
        now = Time.now.to_i
        claimable = OutboxJob.where(topic: SUPPORTED_TOPICS)
          .where("(status = 'pending' AND available_at <= ?) OR (status = 'processing' AND locked_until <= ?)", now, now)
          .order(priority: :desc, created_at: :asc, id: :asc)
        claimable = if ApplicationRecord.connection.adapter_name.match?(/postgres/i)
          claimable.lock("FOR UPDATE SKIP LOCKED")
        else
          claimable.lock
        end
        job = claimable.first
        next nil unless job

        job.update!(status: "processing", attempts: job.attempts.to_i + 1, locked_until: now + 120, lock_token: IdGenerator.call)
        job
      end
    end

    def handle(topic, payload)
      case topic
      when "payment.create"
        @payment_service.create_order(payload.fetch("order_id"))
      when "topup.create"
        @payment_service.create_topup(payload.fetch("topup_id"))
      when "topup.after"
        Billing::AutoPurchaseService.new.after_topup(payload.fetch("user_id"))
        Billing::AutoRenewService.new.wake_for_user(payload.fetch("user_id"))
      when "referral.topup"
        if payload["topup_id"].present?
          Billing::ReferralService.new.process_settled_topup(payload.fetch("topup_id"))
        else
          Billing::ReferralService.new.process_topup(payload.fetch("user_id"), payload.fetch("amount_kopeks"))
        end
      when "compensation.run"
        Billing::CompensationService.new.run(payload.fetch("compensation_id"))
      when "compensation.grant"
        Billing::CompensationService.new.grant(payload.fetch("compensation_id"), payload.fetch("user_id"))
      when "broadcast.run"
        Billing::BroadcastService.new.run(payload.fetch("broadcast_id"))
      when "broadcast.send"
        Integrations::TelegramClient.new.send_message(payload.slice("chat_id", "text"))
      when "telegram.send"
        Integrations::TelegramClient.new.send_message(payload)
      when "telegram.answer"
        Integrations::TelegramClient.new.answer_callback(payload)
      when "telegram.membership_check"
        Integrations::TelegramMembershipCheckService.new.perform(payload)
      when "gift.create"
        AuditLog.create!(id: IdGenerator.call, actor: payload.fetch("user_id"), action: "gift.created",
          subject: payload.fetch("plan_id"), created_at: Time.now.to_i)
      when "payment.verify"
        @payment_service.verify(payload.fetch("payment_id"), payload["provider"])
      when "payment.event.process"
        @payment_service.process_event(payload.fetch("event_id"))
      when "subscription.provision"
        provisioning.activate(payload.fetch("subscription_id"))
      when "subscription.extend"
        provisioning.extend(payload.fetch("subscription_id"), payload["order_id"])
      when "subscription.renew", "subscription.daily"
        Billing::AutoRenewService.new.process(payload.fetch("subscription_id"))
      when "subscription.traffic"
        provisioning.sync_traffic(payload.fetch("subscription_id"))
      when "subscription.devices"
        provisioning.sync_devices(payload.fetch("subscription_id"))
      when "subscription.admin_sync"
        provisioning.sync(payload.fetch("subscription_id"))
      when "subscription.remove"
        provisioning.remove_remote_for_worker(payload.fetch("subscription_id"))
      else
        raise "Unsupported Rails outbox topic: #{topic}"
      end
    end

    def provisioning
      @provisioning ||= Billing::ProvisioningService.new
    end

    def complete(job)
      changed = OutboxJob.where(id: job.id, status: "processing", lock_token: job.lock_token)
        .update_all(status: "done", locked_until: nil, lock_token: nil, last_error: nil, payload: "{}")
      record_broadcast_outcome(job, true) if changed.positive?
    end

    def fail_job(job, error)
      attempts = job.attempts.to_i
      dead = attempts >= 8
      changed = OutboxJob.where(id: job.id, status: "processing", lock_token: job.lock_token).update_all(
        status: dead ? "dead" : "pending",
        available_at: Time.now.to_i + [3600, 2**attempts].min + rand(0..5),
        locked_until: nil,
        lock_token: nil,
        last_error: error.class.name
      )
      return if changed.zero?
      payload = JSON.parse(job.payload.presence || "{}")
      if %w[subscription.provision subscription.extend].include?(job.topic) && payload["subscription_id"].present?
        ProvisioningAccount.where(subscription_id: payload["subscription_id"], state: %w[pending processing retry])
          .update_all(state: dead ? "failed" : "retry", last_error: error.class.name, updated_at: Time.now.to_i)
      end
      return unless dead

      record_broadcast_outcome(job, false)
      if job.topic == "compensation.grant" && payload["compensation_id"] && payload["user_id"]
        Billing::CompensationService.new.mark_failed(payload["compensation_id"], payload["user_id"])
      elsif job.topic == "compensation.run" && payload["compensation_id"]
        Billing::CompensationService.new.mark_run_failed(payload["compensation_id"])
      end
    end

    def run_recovery_if_due
      now = Time.now.to_i
      return if now - @last_recovery_at < 10

      @last_recovery_at = now
      run_recovery_task("autorenew") { Billing::AutoRenewService.new.schedule_due }
      run_recovery_task("creators") { Billing::CreatorService.new.release_due }
      run_recovery_task("compensation") { Billing::CompensationService.new.reconcile_status }
      run_recovery_task("payments_and_provisioning") { RecoveryScheduler.new(payment_service: @payment_service).run(now: now) }
      if ENV["RAILS_SCHEDULER_ENABLED"] == "1" && now - @last_scheduler_at >= 60
        @last_scheduler_at = now
        run_recovery_task("scheduled_maintenance") { ScheduledMaintenance.new.run(now: now) }
        run_recovery_task("remnawave_drift") { Integrations::RemnawaveReconciliationService.new.run(now: now) }
      end
    end

    def run_recovery_task(name)
      yield
    rescue StandardError => error
      # A monitoring/reconciliation failure must not stop delivery of already
      # claimed payment and provisioning commands in the shared outbox.
      Rails.logger.error("rails.recovery.#{name}.failed #{error.class.name}")
    end

    def defer_job(job, error)
      OutboxJob.where(id: job.id, status: "processing", lock_token: job.lock_token).update_all(
        status: "pending",
        attempts: [job.attempts.to_i - 1, 0].max,
        available_at: Time.now.to_i + error.delay_seconds,
        locked_until: nil,
        lock_token: nil,
        last_error: nil
      )
    end

    def mark_permanent(job, error)
      changed = OutboxJob.where(id: job.id, status: "processing", lock_token: job.lock_token).update_all(
        status: "done", locked_until: nil, lock_token: nil,
        last_error: "permanent:#{error.class.name}", payload: "{}"
      )
      record_broadcast_outcome(job, false) if changed.positive?
    end

    def record_broadcast_outcome(job, ok)
      return unless job.topic == "broadcast.send"
      payload = JSON.parse(job.payload.presence || "{}")
      return unless payload["broadcast_id"] && payload["chat_id"]
      Billing::BroadcastService.new.mark_sent(payload["broadcast_id"], payload["chat_id"], ok)
    rescue JSON::ParserError
      nil
    end
  end
end
