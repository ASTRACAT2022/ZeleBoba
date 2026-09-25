require "net/http"
require "json"

module Integrations
  class RemnawaveClient
    def initialize(base_url: nil, token: nil, squad: nil)
      config = Infrastructure::RuntimeConfig.new
      base_url ||= config.fetch("REMNAWAVE_URL", "")
      token ||= config.fetch("REMNAWAVE_TOKEN", "")
      squad ||= config.fetch("REMNAWAVE_SQUAD_UUID", "")
      @base_url, @token, @squad = base_url.to_s, token.to_s, squad.to_s
      uri = URI.parse(@base_url)
      raise Billing::BillingError, "Remnawave must use HTTPS." unless uri.is_a?(URI::HTTPS) && uri.host && @token.present?
      @origin = uri
    end

    # Read a bounded slice of panel users for explicit reconciliation tasks.
    # The remote API is paginated; cap both page size and total rows inspected
    # so a bad/misconfigured panel cannot turn an admin operation into an
    # unbounded request loop.
    def list_active_users(limit: 200, start_page: 1)
      max_rows = [[Integer(limit), 1].max, 500].min
      start_page = Integer(start_page)
      raise ArgumentError unless start_page.between?(1, 10_000)
      page_size = [max_rows, 100].min
      page = start_page
      scanned = 0
      active = []
      total = 0
      next_page = nil

      # Keep one fixed page size: changing it between requests would move the
      # page offset and silently skip or duplicate panel users. A final partial
      # budget is left for the next explicit batch.
      while scanned + page_size <= max_rows && page < start_page + 50
        query = URI.encode_www_form(page: page, pageSize: page_size)
        data = request(:get, "/api/users?#{query}")
        users = data.is_a?(Hash) ? data["users"] : nil
        raise Billing::BillingError, "Remnawave users response is malformed." unless users.is_a?(Array)
        total = data["total"].to_i
        if users.empty?
          raise Billing::BillingError, "Remnawave users page is unexpectedly empty." if total.positive? && (page - 1) * page_size < total
          next_page = nil
          break
        end
        raise Billing::BillingError, "Remnawave returned an oversized users page." if users.length > page_size

        scanned += users.length
        active.concat(users.select { |user| user.is_a?(Hash) && user["status"] == "ACTIVE" })
        if (total.positive? && page * page_size >= total) || users.length < page_size
          next_page = nil
          break
        end

        next_page = page + 1
        page += 1
      end

      { users: active, scanned: scanned, total: total, next_page: next_page }
    rescue ArgumentError, TypeError
      raise Billing::BillingError, "Некорректный размер Remnawave import batch."
    end

    def provision(subscription)
      username = "zb_#{subscription.fetch(:id)}"
      user = request(:get, "/api/users/by-username/#{URI.encode_uri_component(username)}", missing_ok: true)
      if user.nil?
        user = request(:post, "/api/users", {
          username: username, status: "ACTIVE", expireAt: iso(subscription.fetch(:expires_at)),
          trafficLimitBytes: subscription.fetch(:traffic_bytes), trafficLimitStrategy: "NO_RESET",
          hwidDeviceLimit: subscription.fetch(:devices), activeInternalSquads: [subscription.fetch(:squad_uuid, @squad)].reject(&:blank?)
        })
      else
        update(user, subscription)
      end
      validate_user!(user, username)
      { id: (user["id"] || user["shortUuid"] || user["uuid"]).to_s, url: user.fetch("subscriptionUrl") }
    end

    def resolve(subscription)
      remote_id = subscription[:remnawave_id].presence || subscription[:remote_id].presence
      return request(:get, "/api/users/#{Integer(remote_id)}", missing_ok: true) if remote_id.to_s.match?(/\A\d+\z/)

      short_uuid = subscription[:remnawave_short_uuid].to_s
      if short_uuid.match?(/\A[A-Za-z0-9_-]+\z/)
        user = request(:get, "/api/users/by-short-uuid/#{URI.encode_uri_component(short_uuid)}", missing_ok: true)
        return user if user && user["shortUuid"] == short_uuid
      end
      request(:get, "/api/users/by-username/zb_#{URI.encode_uri_component(subscription.fetch(:id))}", missing_ok: true)
    end

    def sync(subscription)
      user = resolve(subscription)
      raise Billing::BillingError, "Remnawave user was not found." unless user && user["id"]
      request(:patch, "/api/users", {
        id: Integer(user.fetch("id")), expireAt: iso(subscription.fetch(:expires_at)),
        trafficLimitBytes: subscription.fetch(:traffic_bytes), hwidDeviceLimit: subscription.fetch(:devices), status: "ACTIVE"
      })
      user
    end

    def set_traffic(subscription)
      user = resolve(subscription)
      raise Billing::BillingError, "Remnawave user was not found." unless user && user["id"]
      request(:patch, "/api/users", {
        id: Integer(user.fetch("id")), trafficLimitBytes: subscription.fetch(:traffic_bytes)
      })
    end

    def set_devices(subscription)
      user = resolve(subscription)
      raise Billing::BillingError, "Remnawave user was not found." unless user && user["id"]
      request(:patch, "/api/users", {
        id: Integer(user.fetch("id")), hwidDeviceLimit: subscription.fetch(:devices)
      })
    end

    # Deletion is idempotent: absent remote users and DELETE 404s both mean
    # the desired state has been reached. Resolve legacy identifiers first,
    # then try the canonical username in case a stored panel id is stale.
    def remove(subscription)
      user = resolve_for_removal(subscription)
      return true unless user

      panel_id = user["id"].to_s
      raise Billing::BillingError, "Invalid Remnawave user id for removal." unless panel_id.match?(/\A\d+\z/)

      request(:delete, "/api/users/#{Integer(panel_id)}", missing_ok: true)
      true
    end

    def disable(subscription)
      user = resolve(subscription)
      return unless user && user["id"].to_s.match?(/\A\d+\z/)
      request(:patch, "/api/users", { id: Integer(user.fetch("id")), status: "DISABLED" })
      true
    end

    private

    def resolve_for_removal(subscription)
      %i[remnawave_id remote_id].each do |key|
        id = subscription[key].to_s
        next unless id.match?(/\A\d+\z/) && id.to_i.positive?

        user = request(:get, "/api/users/#{Integer(id)}", missing_ok: true)
        return user if user
      end

      short_uuid = subscription[:remnawave_short_uuid].to_s
      if short_uuid.match?(/\A[A-Za-z0-9_-]+\z/)
        user = request(:get, "/api/users/by-short-uuid/#{URI.encode_uri_component(short_uuid)}", missing_ok: true)
        return user if user && user["shortUuid"] == short_uuid
      end

      subscription_id = subscription[:id].to_s
      if subscription_id.present?
        username = "zb_#{subscription_id}"
        user = request(:get, "/api/users/by-username/#{URI.encode_uri_component(username)}", missing_ok: true)
        return user if user && user["username"] == username
      end

      raw_id = subscription[:remote_id].to_s
      if raw_id.present? && !raw_id.match?(/\A\d+\z/) && raw_id.match?(/\A[A-Za-z0-9_-]+\z/)
        request(:get, "/api/users/#{URI.encode_uri_component(raw_id)}", missing_ok: true)
      end
    end

    def update(user, subscription)
      request(:patch, "/api/users", {
        id: Integer(user.fetch("id")), expireAt: iso(subscription.fetch(:expires_at)),
        trafficLimitBytes: subscription.fetch(:traffic_bytes), hwidDeviceLimit: subscription.fetch(:devices), status: "ACTIVE"
      })
    end

    def validate_user!(user, username)
      unless user.is_a?(Hash) && user["username"] == username && user["id"].present? && user["subscriptionUrl"].to_s.start_with?("https://")
        raise Billing::BillingError, "Invalid Remnawave user response."
      end
    end

    def iso(epoch)
      Time.at(Integer(epoch)).utc.iso8601
    end

    def request(method, path, payload = nil, missing_ok: false)
      uri = @origin.dup
      uri.path, uri.query = path.split("?", 2)
      http = Net::HTTP.new(uri.host, uri.port)
      http.use_ssl = true
      http.open_timeout = 5
      http.read_timeout = 15
      klass = { get: Net::HTTP::Get, post: Net::HTTP::Post, patch: Net::HTTP::Patch, delete: Net::HTTP::Delete }.fetch(method)
      request = klass.new(uri)
      request["Authorization"] = "Bearer #{@token}"
      request["Accept"] = "application/json"
      if payload
        request["Content-Type"] = "application/json"
        request.body = JSON.generate(payload)
      end
      response = http.request(request)
      return if response.code == "404" && missing_ok
      raise Billing::BillingError, "Remnawave returned HTTP #{response.code}." unless response.code.to_i.between?(200, 299)
      data = response.body.to_s.empty? ? {} : JSON.parse(response.body)
      data.fetch("response", data)
    rescue JSON::ParserError, KeyError, ArgumentError, IOError, SystemCallError, Timeout::Error => error
      raise Billing::BillingError, "Remnawave request failed (#{error.class})."
    end
  end
end
