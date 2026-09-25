module Billing
  class ProvisioningService
    def initialize(client: nil)
      @client = client
    end

    def activate(subscription_id)
      Infrastructure::SafetyControls.new.assert_can_provision!
      sub = Subscription.find_by(id: subscription_id)
      return unless sub && %w[provisioning active trial].include?(sub.status) && sub.expires_at.to_i > Time.now.to_i

      order = Order.find_by(id: sub.order_id)
      driver = order&.provision_driver.presence || Infrastructure::RuntimeConfig.fetch("PROVISION_DRIVER", "demo")
      if driver == "demo"
        raise Billing::BillingError, "Демо-выдача запрещена в production." if Rails.env.production? || Infrastructure::RuntimeConfig.fetch("APP_ENV", "") == "prod"
        ApplicationRecord.transaction do
          sub.update!(status: "active", lifecycle_status: "active", remote_id: "demo_#{sub.id}", updated_at: Time.now.to_i)
          order&.update!(status: "fulfilled", workflow_status: "fulfilled") if order&.status == "paid"
          ProvisioningAccount.where(subscription_id: sub.id).update_all(state: "active", external_user_id: "demo_#{sub.id}", last_synced_at: Time.now.to_i, updated_at: Time.now.to_i)
        end
        return
      end
      details = desired_state(sub, order)
      account = ProvisioningAccount.find_or_create_by!(subscription_id: sub.id, provider: driver) do |row|
        row.id = Infrastructure::IdGenerator.call
        row.state = "pending"
        row.created_at = Time.now.to_i
        row.updated_at = Time.now.to_i
      end
      account.update!(state: "processing", updated_at: Time.now.to_i)
      remote = client.provision(details)
      ApplicationRecord.transaction do
        sub.lock!
        next if %w[cancelled expired].include?(sub.lifecycle_status)
        sub.update!(status: "active", lifecycle_status: "active", remote_id: remote.fetch(:id), subscription_url: remote.fetch(:url), updated_at: Time.now.to_i)
        order&.update!(status: "fulfilled", workflow_status: "fulfilled") if order&.status == "paid"
        account.update!(external_user_id: remote.fetch(:id), state: "active", last_synced_at: Time.now.to_i, last_error: nil, updated_at: Time.now.to_i)
      end
    rescue Infrastructure::JobDeferred
      raise
    rescue StandardError => error
      account&.update(state: "retry", last_error: error.class.name, updated_at: Time.now.to_i)
      raise
    end

    def extend(subscription_id, order_id = nil)
      Infrastructure::SafetyControls.new.assert_can_provision!
      sub = Subscription.find_by(id: subscription_id)
      return unless sub && sub.status == "active"
      order = Order.find_by(id: sub.order_id)
      driver = order&.provision_driver.presence || Infrastructure::RuntimeConfig.fetch("PROVISION_DRIVER", "demo")
      if driver == "demo"
        Order.where(id: order_id, status: "paid").update_all(status: "fulfilled", workflow_status: "fulfilled") if order_id
        return
      end

      details = desired_state(sub, order)
      remote = client.sync(details)
      ApplicationRecord.transaction do
        Order.where(id: order_id, status: "paid").update_all(status: "fulfilled", workflow_status: "fulfilled") if order_id
      ProvisioningAccount.where(subscription_id: sub.id, provider: driver).update_all(
          state: "active", external_user_id: remote.fetch("id").to_s,
          last_synced_at: Time.now.to_i, last_error: nil, updated_at: Time.now.to_i
        )
      end
    rescue Infrastructure::JobDeferred
      raise
    rescue StandardError => error
      ProvisioningAccount.where(subscription_id: subscription_id).update_all(state: "retry", last_error: error.class.name, updated_at: Time.now.to_i)
      raise
    end

    def sync(subscription_id)
      Infrastructure::SafetyControls.new.assert_can_provision!
      sub = Subscription.find_by(id: subscription_id)
      return unless sub && sub.status == "active"
      order = Order.find_by(id: sub.order_id)
      driver = order&.provision_driver.presence || Infrastructure::RuntimeConfig.fetch("PROVISION_DRIVER", "demo")
      return if driver == "demo"

      remote = client.sync(desired_state(sub, order))
      ProvisioningAccount.where(subscription_id: sub.id, provider: driver).update_all(
        state: "active", external_user_id: remote.fetch("id").to_s,
        last_synced_at: Time.now.to_i, last_error: nil, updated_at: Time.now.to_i
      )
    rescue Infrastructure::JobDeferred
      raise
    rescue StandardError => error
      ProvisioningAccount.where(subscription_id: subscription_id).update_all(state: "retry", last_error: error.class.name, updated_at: Time.now.to_i)
      raise
    end

    # Outbox topic subscription.traffic mirrors the legacy worker's narrow
    # traffic-only update. A full sync here could reset expiry/device settings
    # and reactivate an account that an operator disabled in Remnawave.
    def sync_traffic(subscription_id)
      sync_single_field(subscription_id) do |client, desired|
        client.set_traffic(desired)
      end
    end

    # Keep the worker's device update scoped to the device limit for the same
    # reason as sync_traffic; subscription.admin_sync remains the full sync.
    def sync_devices(subscription_id)
      sync_single_field(subscription_id) do |client, desired|
        client.set_devices(desired)
      end
    end

    # Called only by the durable subscription.remove outbox job. Keep the
    # disabled/cancelled subscription row for financial history and clear its
    # panel pointers only after Remnawave confirms deletion (404 is success).
    def remove_remote_for_worker(subscription_id)
      sub = Subscription.find_by(id: subscription_id)
      return unless sub

      order = Order.find_by(id: sub.order_id)
      driver = order&.provision_driver.presence || Infrastructure::RuntimeConfig.fetch("PROVISION_DRIVER", "demo")
      remote_reference = sub.remote_id.present? || sub.remnawave_id.to_i.positive? || sub.remnawave_short_uuid.present?
      if driver != "demo" || remote_reference
        client.remove(desired_state(sub, order))
      end

      ApplicationRecord.transaction do
        sub.lock!
        sub.update!(remote_id: nil, remnawave_id: nil, remnawave_short_uuid: nil,
          subscription_url: nil, updated_at: Time.now.to_i)
        ProvisioningAccount.where(subscription_id: sub.id).update_all(
          state: "removed", external_user_id: nil, last_error: nil,
          last_synced_at: Time.now.to_i, updated_at: Time.now.to_i
        )
      end
      true
    end

    def disable_remote(subscription)
      client.disable(subscription.transform_keys(&:to_sym))
    end

    private

    def client
      @client ||= Integrations::RemnawaveClient.new
    end

    def sync_single_field(subscription_id)
      Infrastructure::SafetyControls.new.assert_can_provision!
      sub = Subscription.find_by(id: subscription_id)
      return unless sub && sub.status == "active"

      order = Order.find_by(id: sub.order_id)
      driver = order&.provision_driver.presence || Infrastructure::RuntimeConfig.fetch("PROVISION_DRIVER", "demo")
      return if driver == "demo"
      raise Infrastructure::JobDeferred, 30 if sub.remote_id.nil?

      yield client, desired_state(sub, order)
    rescue Infrastructure::JobDeferred
      raise
    rescue StandardError => error
      ProvisioningAccount.where(subscription_id: subscription_id).update_all(state: "retry", last_error: error.class.name, updated_at: Time.now.to_i)
      raise
    end

    def desired_state(sub, order)
      plan = Plan.find_by(id: sub.plan_id)
      traffic_gb = sub.respond_to?(:traffic_limit_gb) ? sub.traffic_limit_gb.to_i : 0
      purchased_gb = sub.respond_to?(:purchased_traffic_gb) ? sub.purchased_traffic_gb.to_i : 0
      devices = sub.respond_to?(:device_limit) ? sub.device_limit.to_i : (order&.devices || plan&.devices || 1).to_i
      {
        id: sub.id, expires_at: sub.expires_at.to_i,
        traffic_bytes: (traffic_gb.zero? ? 0 : traffic_gb + purchased_gb) * 1_073_741_824,
        devices: devices, squad_uuid: order&.squad_uuid.presence || plan&.squad_uuid.to_s,
        remote_id: sub.remote_id, remnawave_id: sub.remnawave_id,
        remnawave_short_uuid: sub.remnawave_short_uuid
      }
    end
  end
end
