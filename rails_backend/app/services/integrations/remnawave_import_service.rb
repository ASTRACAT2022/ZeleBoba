require "time"
require "set"

module Integrations
  # Explicit reverse import for active panel users whose Telegram identity
  # already belongs to a local account. Writes are opt-in and require the
  # shared auto-heal feature flag; this service is intentionally not scheduled.
  class RemnawaveImportService
    MAX_BATCH_SIZE = 500
    GIB = 1_073_741_824

    def initialize(client: nil)
      @client = client
    end

    def run(limit: 200, start_page: 1, fix: false)
      limit = [[Integer(limit), 1].max, MAX_BATCH_SIZE].min
      start_page = Integer(start_page)
      raise ArgumentError unless start_page.between?(1, 10_000)
      report = { scanned: 0, matched_tg: 0, imported: 0, skipped_known: 0, skipped_inactive: 0,
                 skipped_no_account: 0, errors: 0, details: [], start_page: start_page, next_page: nil }
      return report.merge(skipped: "auto_heal_disabled") if fix && !auto_heal_enabled?

      known_remote_ids = known_remote_ids_set
      page = client.list_active_users(limit: limit, start_page: start_page)
      report[:panel_rows_scanned] = page[:scanned]
      report[:next_page] = page[:next_page]
      page[:users].each do |remote|
        report[:scanned] += 1
        unless remote.is_a?(Hash) && remote["status"] == "ACTIVE"
          report[:skipped_inactive] += 1
          next
        end
        panel_id = positive_panel_id(remote["id"])
        unless panel_id
          report[:errors] += 1
          report[:details] << "invalid panel user id"
          next
        end

        if known_remote_ids.include?(panel_id)
          report[:skipped_known] += 1
          next
        end

        telegram_id = telegram_id(remote)
        user = telegram_id.present? ? User.find_by(telegram_id: telegram_id) : nil
        unless user
          report[:skipped_no_account] += 1
          next
        end

        report[:matched_tg] += 1
        unless fix
          report[:details] << "panel_id=#{panel_id} user_id=#{user.id} would import"
          next
        end

        begin
          imported = import_one!(remote, panel_id, telegram_id)
          if imported
            known_remote_ids << panel_id
            report[:imported] += 1
            report[:details] << "panel_id=#{panel_id} imported"
          else
            report[:skipped_known] += 1
            known_remote_ids << panel_id
          end
        rescue StandardError => error
          report[:errors] += 1
          report[:details] << "panel_id=#{panel_id} error=#{error.class.name}"
        end
      end
      Rails.logger.info("remnawave.import #{report.except(:details).to_json}")
      report
    rescue ArgumentError, TypeError
      raise Billing::BillingError, "Некорректный размер Remnawave import batch."
    end

    private

    def client
      @client ||= RemnawaveClient.new
    end

    def auto_heal_enabled?
      (FeatureFlag.find_by(name: "reconciliation.auto_heal")&.enabled || 0).to_i == 1
    end

    def known_remote_ids_set
      Subscription.where.not(remnawave_id: nil).pluck(:remnawave_id).filter_map { |id| id.to_i if id.to_i.positive? }.to_set.tap do |ids|
        Subscription.where.not(remote_id: nil).pluck(:remote_id).each do |id|
          ids << id.to_i if id.to_s.match?(/\A\d+\z/) && id.to_i.positive?
        end
      end
    end

    def positive_panel_id(value)
      text = value.to_s
      return unless text.match?(/\A\d+\z/)

      id = text.to_i
      id if id.positive? && id <= 9_223_372_036_854_775_807
    end

    def telegram_id(remote)
      value = remote["telegramId"] || remote["telegram_id"] || remote["telegram"]
      text = value.to_s
      text if text.match?(/\A\d{1,30}\z/)
    end

    def import_one!(remote, panel_id, telegram_id)
      expiration = parse_expiration(remote["expireAt"])
      raise Billing::BillingError, "Invalid expiration on Remnawave user." unless expiration&.positive?

      traffic_bytes = nonnegative_integer(remote["trafficLimitBytes"], default: 0)
      if traffic_bytes > 2_147_483_647 * GIB
        raise Billing::BillingError, "Remnawave traffic limit exceeds local column range."
      end
      device_limit = nonnegative_integer(remote["hwidDeviceLimit"], default: 1)
      device_limit = 1 if device_limit.zero?
      now = Time.now.to_i
      user = User.find_by!(telegram_id: telegram_id)

      Subscription.transaction do
        acquire_panel_id_lock(panel_id)
        user.lock!
        if remote_id_exists?(panel_id)
          next false
        end

        subscription = Subscription.create!(
          id: Infrastructure::IdGenerator.call,
          order_id: nil,
          user_id: user.id,
          status: "active",
          lifecycle_status: "active",
          expires_at: expiration,
          created_at: now,
          updated_at: now,
          starts_at: now,
          start_date: now,
          traffic_limit_gb: traffic_bytes / GIB,
          traffic_limit_bytes: traffic_bytes,
          purchased_traffic_gb: 0,
          traffic_used_gb: 0,
          device_limit: device_limit,
          is_trial: 0,
          autopay_enabled: 0,
          is_daily_paused: 0,
          modem_enabled: 0,
          auto_renew: 0,
          renew_fail_count: 0,
          version: 0,
          remote_id: panel_id.to_s,
          remnawave_id: panel_id,
          remnawave_uuid: remote["vlessUuid"].to_s.presence,
          remnawave_short_uuid: remote["shortUuid"].to_s.presence,
          subscription_url: secure_url(remote["subscriptionUrl"])
        )
        ProvisioningAccount.create!(
          id: Infrastructure::IdGenerator.call,
          subscription_id: subscription.id,
          provider: "remnawave",
          external_user_id: panel_id.to_s,
          state: "active",
          last_synced_at: now,
          created_at: now,
          updated_at: now
        )
        true
      end
    end

    def acquire_panel_id_lock(panel_id)
      return unless ApplicationRecord.connection.adapter_name.downcase.include?("postgres")

      ApplicationRecord.connection.execute("SELECT pg_advisory_xact_lock(#{panel_id})")
    end

    def remote_id_exists?(panel_id)
      Subscription.where(remnawave_id: panel_id).or(Subscription.where(remote_id: panel_id.to_s)).exists?
    end

    def parse_expiration(value)
      return if value.blank?

      (Time.iso8601(value.to_s).to_f).to_i
    rescue ArgumentError
      Integer(value, 10) if value.to_s.match?(/\A\d+\z/)
    end

    def nonnegative_integer(value, default:)
      number = Integer(value || default)
      raise Billing::BillingError, "Invalid Remnawave numeric value." if number.negative?

      number
    rescue ArgumentError, TypeError
      raise Billing::BillingError, "Invalid Remnawave numeric value."
    end

    def secure_url(value)
      text = value.to_s
      uri = URI.parse(text)
      uri.is_a?(URI::HTTPS) && uri.host ? text : nil
    rescue URI::InvalidURIError
      nil
    end
  end
end
