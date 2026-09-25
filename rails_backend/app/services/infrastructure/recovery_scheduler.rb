module Infrastructure
  # Periodically recreates disposable delivery commands from durable billing
  # state. It never repeats a provider checkout creation request.
  class RecoveryScheduler
    LEASE_NAME = "rails-outbox-recovery"

    def initialize(payment_service: Payments::PaymentService.new, outbox: OutboxService.new)
      @payment_service = payment_service
      @outbox = outbox
    end

    def run(now: Time.now.to_i)
      token = IdGenerator.call
      return false unless acquire_lease(token, now)

      @payment_service.reconcile_pending(now: now)
      recover_provisioning(now: now)
      true
    end

    private

    def acquire_lease(token, now)
      connection = ApplicationRecord.connection
      connection.execute(
        "INSERT INTO advisory_leases(name,token,expires_at) VALUES(#{connection.quote(LEASE_NAME)},#{connection.quote(token)},0) ON CONFLICT(name) DO NOTHING"
      )
      changed = connection.update(
        "UPDATE advisory_leases SET token=#{connection.quote(token)},expires_at=#{now + 60} " \
        "WHERE name=#{connection.quote(LEASE_NAME)} AND expires_at <= #{now}"
      )
      changed == 1
    end

    def recover_provisioning(now:)
      bucket = now / 300
      after = ""
      loop do
        ids = Subscription.where(status: "provisioning", lifecycle_status: %w[pending active grace])
          .where("expires_at > ? AND (remote_id IS NULL OR remote_id = '') AND id > ?", now, after)
          .order(:id).limit(100).pluck(:id)
        break if ids.empty?
        ids.each do |subscription_id|
          @outbox.enqueue("subscription.provision", "reconcile-provision:#{subscription_id}:#{bucket}", { subscription_id: subscription_id })
        end
        break if ids.length < 100
        after = ids.last
      end

      after = ""
      loop do
        accounts = ProvisioningAccount.joins(:subscription)
          .where(state: %w[retry failed])
          .where("subscriptions.expires_at > ? AND subscriptions.lifecycle_status IN ('pending','active','grace') AND provisioning_accounts.subscription_id > ?", now, after)
          .order("provisioning_accounts.subscription_id").limit(100)
        break if accounts.empty?
        accounts.each do |account|
          topic = account.subscription.remote_id.present? ? "subscription.extend" : "subscription.provision"
          @outbox.enqueue(topic, "reconcile-account:#{account.subscription_id}:#{bucket}", { subscription_id: account.subscription_id })
        end
        break if accounts.length < 100
        after = accounts.last.subscription_id
      end
    end
  end
end
