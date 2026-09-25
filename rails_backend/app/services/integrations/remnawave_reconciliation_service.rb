require "time"

module Integrations
  # Hourly bounded drift scan. The two shared advisory leases coordinate both
  # Rails replicas and the legacy PHP Reconciler during a staged cutover.
  class RemnawaveReconciliationService
    LEASE_NAMES = %w[reconcile remnawave-sync].freeze
    LEASE_SECONDS = 3600
    MAX_BATCH_SIZE = 100
    DRIFT_SECONDS = 60

    def initialize(client: nil)
      @client = client
    end

    def run(limit: 50, fix: nil, now: Time.now.to_i)
      limit = [[Integer(limit), 1].max, MAX_BATCH_SIZE].min
      report = { checked: 0, fixed: 0, disabled: 0, reprovisioned: 0, missing: 0, errors: 0, details: [] }
      config = Infrastructure::RuntimeConfig.new
      return report.merge(skipped: "remnawave_not_configured") if config.fetch("REMNAWAVE_URL", "").blank? || config.fetch("REMNAWAVE_TOKEN", "").blank?

      token = Infrastructure::IdGenerator.call
      return report.merge(skipped: "scheduler_lease_busy") unless acquire_leases(token, now)
      @lease_token = token

      begin
        auto_heal_enabled = (FeatureFlag.find_by(name: "reconciliation.auto_heal")&.enabled || 0).to_i == 1
        heal = auto_heal_enabled && (fix.nil? || !!fix)
        scan(limit: limit, fix: heal, report: report, now: now)
        Rails.logger.info("remnawave.sync #{report.slice(:checked, :fixed, :disabled, :reprovisioned, :missing, :errors).to_json}")
        report
      rescue StandardError => error
        report[:errors] += 1
        Rails.logger.error("remnawave.sync.failed #{error.class.name}")
        report
      ensure
        release_leases(token)
        @lease_token = nil
      end
    rescue ArgumentError, TypeError
      raise Billing::BillingError, "Некорректный размер Remnawave reconciliation batch."
    end

    private

    def scan(limit:, fix:, report:, now:)
      subscriptions(limit, now).each do |subscription|
        heartbeat_leases
        report[:checked] += 1
        username = "zb_#{subscription.id}"
        begin
          details = desired_state(subscription)
          remote = client.resolve(details)
          local_expired = subscription.status == "expired" || subscription.expires_at.to_i <= now
          if local_expired
            disable_if_needed(subscription, details, remote, fix, report, username)
            next
          end

          if remote.nil?
            report[:missing] += 1
            if fix
              reprovision(subscription, details, report, username, now)
            else
              report[:details] << "#{username}: missing on panel"
            end
            next
          end

          drift = drift_seconds(remote, subscription.expires_at.to_i)
          needs_fix = drift > DRIFT_SECONDS ||
            remote["trafficLimitBytes"].to_i != details[:traffic_bytes] ||
            remote["hwidDeviceLimit"].to_i != details[:devices] ||
            remote["status"] != "ACTIVE"

          if needs_fix
            if fix
              client.sync(details)
              verified = client.resolve(details)
              verify_remote!(verified, details)
              current = Subscription.find_by(id: subscription.id)
              if current && current.status == "active" && current.expires_at.to_i > Time.now.to_i
                persist_remote(current, verified, Time.now.to_i)
              else
                client.disable(details.merge(remote_id: verified["id"]))
                next
              end
              report[:fixed] += 1
              report[:details] << "#{username}: fixed drift=#{drift}s"
            else
              report[:details] << "#{username}: drift #{drift}s would fix"
            end
          elsif fix
            persist_remote(subscription, remote, now)
          end
        rescue StandardError => error
          report[:errors] += 1
          report[:details] << "#{username}: error #{error.class.name}"
        end
      end
    end

    def subscriptions(limit, now)
      Subscription.joins("LEFT JOIN orders ON orders.id = subscriptions.order_id")
        .joins("LEFT JOIN plans ON plans.id = subscriptions.plan_id")
        .joins("LEFT JOIN provisioning_accounts ON provisioning_accounts.subscription_id = subscriptions.id AND provisioning_accounts.provider = 'remnawave'")
        .where(status: %w[active expired])
        .where("COALESCE(orders.provision_driver, provisioning_accounts.provider, ?) = 'remnawave'",
          Infrastructure::RuntimeConfig.fetch("PROVISION_DRIVER", "demo"))
        .order(created_at: :desc, id: :desc).limit(limit)
        .select("subscriptions.*")
    end

    def desired_state(subscription)
      order = Order.find_by(id: subscription.order_id)
      plan = Plan.find_by(id: subscription.plan_id)
      traffic_gb = subscription.traffic_limit_gb.to_i
      purchased_gb = subscription.purchased_traffic_gb.to_i
      devices = subscription.device_limit.to_i
      devices = 1 if devices < 0
      {
        id: subscription.id,
        expires_at: subscription.expires_at.to_i,
        traffic_bytes: traffic_gb.zero? ? 0 : (traffic_gb + purchased_gb) * 1_073_741_824,
        devices: devices,
        squad_uuid: order&.squad_uuid.presence || plan&.squad_uuid.presence || Infrastructure::RuntimeConfig.fetch("REMNAWAVE_SQUAD_UUID", ""),
        remote_id: subscription.remote_id,
        remnawave_id: subscription.remnawave_id,
        remnawave_short_uuid: subscription.remnawave_short_uuid
      }
    end

    def disable_if_needed(subscription, details, remote, fix, report, username)
      return unless remote && remote["status"] == "ACTIVE"

      unless fix
        report[:details] << "#{username}: would disable expired"
        return
      end

      current = Subscription.find_by(id: subscription.id)
      return if current && current.status == "active" && current.expires_at.to_i > Time.now.to_i

      client.disable(details.merge(remote_id: remote["id"], remnawave_id: remote["id"]))
      current = Subscription.find_by(id: subscription.id)
      if current && current.status == "active" && current.expires_at.to_i > Time.now.to_i
        refreshed = desired_state(current)
        client.sync(refreshed)
        verify_remote!(client.resolve(refreshed), refreshed)
        return
      end
      verified = client.resolve(details.merge(remote_id: remote["id"], remnawave_id: remote["id"]))
      raise Billing::BillingError, "Remnawave disable readback failed." if verified && verified["status"] == "ACTIVE"

      report[:disabled] += 1
      report[:details] << "#{username}: disabled expired"
    end

    def reprovision(subscription, details, report, username, now)
      remote_result = client.provision(details)
      remote = client.resolve(details.merge(remote_id: remote_result[:id], remnawave_id: remote_result[:id]))
      verify_remote!(remote, details)

      persisted = ApplicationRecord.transaction do
        locked = Subscription.lock.find_by(id: subscription.id)
        next false unless eligible_for_service?(locked)

        locked.update!(
          status: "active", lifecycle_status: "active",
          remote_id: remote_result[:id].to_s,
          remnawave_id: remote_result[:id].to_s.match?(/\A\d+\z/) ? remote_result[:id].to_i : nil,
          subscription_url: remote_result[:url], updated_at: now
        )
        update_provisioning_account(locked, remote_result[:id].to_s, now)
        true
      end
      unless persisted
        client.disable(details.merge(remote_id: remote_result[:id], remnawave_id: remote_result[:id]))
        return
      end
      report[:reprovisioned] += 1
      report[:details] << "#{username}: re-provisioned"
    end

    def verify_remote!(remote, details)
      raise Billing::BillingError, "Remnawave sync readback failed." unless remote.is_a?(Hash) && remote["status"] == "ACTIVE"
      raise Billing::BillingError, "Remnawave sync readback failed." unless drift_seconds(remote, details[:expires_at]).to_i <= DRIFT_SECONDS
      raise Billing::BillingError, "Remnawave sync readback failed." unless remote["trafficLimitBytes"].to_i == details[:traffic_bytes]
      raise Billing::BillingError, "Remnawave sync readback failed." unless remote["hwidDeviceLimit"].to_i == details[:devices]
    end

    def drift_seconds(remote, expected_expiry)
      value = remote["expireAt"].to_s
      return 86_400_000 if value.blank?

      (Time.iso8601(value).to_i - expected_expiry).abs
    rescue ArgumentError
      86_400_000
    end

    def persist_remote(subscription, remote, now)
      remote_id = remote["id"].to_s
      return unless remote_id.match?(/\A\d+\z/)

      ApplicationRecord.transaction do
        locked = Subscription.lock.find_by(id: subscription.id)
        next unless eligible_for_service?(locked)
        locked.update!(
          remote_id: remote_id,
          remnawave_id: remote_id.to_i,
          subscription_url: remote["subscriptionUrl"].presence || locked.subscription_url,
          updated_at: now
        )
        update_provisioning_account(locked, remote_id, now)
      end
    end

    def update_provisioning_account(subscription, external_id, now)
      account = ProvisioningAccount.find_or_initialize_by(subscription_id: subscription.id, provider: "remnawave")
      account.id ||= Infrastructure::IdGenerator.call
      account.created_at ||= now
      account.update!(
        external_user_id: external_id, state: "active", last_synced_at: now,
        last_error: nil, updated_at: now
      )
    end

    def eligible_for_service?(subscription)
      subscription && subscription.status == "active" &&
        !%w[cancelled expired].include?(subscription.lifecycle_status) &&
        subscription.expires_at.to_i > Time.now.to_i
    end

    def client
      @client ||= RemnawaveClient.new
    end

    def acquire_leases(token, now)
      acquired = []
      LEASE_NAMES.each do |name|
        connection = ApplicationRecord.connection
        connection.execute(
          "INSERT INTO advisory_leases(name,token,expires_at) VALUES(#{connection.quote(name)},#{connection.quote(token)},0) ON CONFLICT(name) DO NOTHING"
        )
        changed = connection.update(
          "UPDATE advisory_leases SET token=#{connection.quote(token)},expires_at=#{now + LEASE_SECONDS} " \
          "WHERE name=#{connection.quote(name)} AND expires_at <= #{now}"
        )
        unless changed == 1
          release_leases(token)
          return false
        end
        acquired << name
      end
      true
    end

    def heartbeat_leases
      now = Time.now.to_i
      connection = ApplicationRecord.connection
      LEASE_NAMES.each do |name|
        connection.update(
          "UPDATE advisory_leases SET expires_at=#{now + LEASE_SECONDS} " \
          "WHERE name=#{connection.quote(name)} AND token=#{connection.quote(@lease_token)}"
        )
      end
    end

    def release_leases(token)
      connection = ApplicationRecord.connection
      # `remnawave-sync` is the hourly cadence marker shared with PHP. Keep
      # its expiry after a completed scan; only release the mutual-exclusion
      # lease so another reconcile pass can run.
      connection.delete(
        "DELETE FROM advisory_leases WHERE name=#{connection.quote('reconcile')} AND token=#{connection.quote(token)}"
      )
    end
  end
end
