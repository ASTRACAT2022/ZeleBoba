# frozen_string_literal: true

require "json"
require "net/http"
require "uri"

require_relative "../infrastructure/job_error"

module Zeleboba
  module Integration
    class RemnawaveClient
      def initialize(config, requester: nil)
        @config = config
        @requester = requester || method(:net_request)
      end

      def provision(subscription)
        username = username_for(subscription.fetch("id"))
        user = find_by_username(username)
        user ||= create_user(subscription, username)
        validate_user!(user, username)

        { "id" => (user["id"] || user["shortUuid"] || user["uuid"]).to_s, "url" => user.fetch("subscriptionUrl") }
      end

      def extend(subscription)
        update_user(subscription, active: true)
      end

      def set_traffic(subscription)
        update_user(subscription, active: true)
      end

      def set_devices(subscription)
        update_user(subscription, active: true)
      end

      def disable(subscription)
        user = find_by_username(username_for(subscription.fetch("id")))
        return unless user

        patch_user(user.fetch("id"), { status: "DISABLED" })
      end

      private

      def create_user(subscription, username)
        response = request("POST", "/api/users", user_payload(subscription, username))
        return find_by_username(username) if response[:status] == 409

        response.fetch(:body).fetch("response", {})
      end

      def update_user(subscription, active:)
        user = find_by_username(username_for(subscription.fetch("id")))
        raise "Remnawave user is missing for subscription #{subscription["id"]}" unless user

        patch_user(user.fetch("id"), user_payload(subscription, nil, active: active))
      end

      def patch_user(id, payload)
        response = request("PATCH", "/api/users", payload.merge(id: Integer(id)))
        raise "Remnawave update failed: HTTP #{response[:status]}" unless response[:status].between?(200, 299)

        response[:body].fetch("response", {})
      end

      def find_by_username(username)
        response = request("GET", "/api/users/by-username/#{URI.encode_uri_component(username)}")
        return nil if response[:status] == 404
        raise "Remnawave lookup failed: HTTP #{response[:status]}" unless response[:status].between?(200, 299)

        response[:body].fetch("response", nil)
      end

      def user_payload(subscription, username, active: true)
        traffic = subscription.fetch("traffic_bytes", 0).to_i
        devices = subscription.fetch("devices", 1).to_i
        payload = {
          expireAt: Time.at(subscription.fetch("expires_at").to_i).utc.strftime("%Y-%m-%dT%H:%M:%SZ"),
          trafficLimitBytes: traffic,
          trafficLimitStrategy: "NO_RESET",
          hwidDeviceLimit: devices,
          status: active ? "ACTIVE" : "DISABLED"
        }
        if username
          squad = subscription["squad_uuid"].to_s.empty? ? @config["REMNAWAVE_SQUAD_UUID"].to_s : subscription["squad_uuid"].to_s
          raise Infrastructure::JobPermanentFailure, "REMNAWAVE_SQUAD_UUID is not configured" if squad.empty?

          payload[:username] = username
          payload[:activeInternalSquads] = [squad]
        end
        payload
      end

      def validate_user!(user, expected_username)
        remote_id = user["id"] || user["shortUuid"] || user["uuid"]
        valid_url = user["subscriptionUrl"].to_s.start_with?("https://")
        raise "Invalid Remnawave response" unless user["username"] == expected_username && remote_id && valid_url
      end

      def request(method, path, payload = nil)
        ensure_configured!
        response = @requester.call(method, endpoint(path), request_headers, payload)
        response.is_a?(Hash) ? response : normalize_response(response)
      rescue Infrastructure::JobPermanentFailure
        raise
      rescue StandardError => e
        raise e if e.message.start_with?("Remnawave")

        raise "Remnawave request failed: #{e.class}: #{e.message}"
      end

      def ensure_configured!
        base = @config["REMNAWAVE_URL"].to_s
        raise Infrastructure::JobPermanentFailure, "REMNAWAVE_URL must be an HTTPS URL" unless base.start_with?("https://")
        raise Infrastructure::JobPermanentFailure, "REMNAWAVE_TOKEN is not configured" if @config["REMNAWAVE_TOKEN"].to_s.empty?
      end

      def endpoint(path)
        URI.parse("#{@config.fetch("REMNAWAVE_URL").to_s.sub(%r{/+\z}, "")}#{path}")
      end

      def request_headers
        { "Authorization" => "Bearer #{@config.fetch("REMNAWAVE_TOKEN")}", "Content-Type" => "application/json", "Accept" => "application/json" }
      end

      def net_request(method, uri, headers, payload)
        http = Net::HTTP.new(uri.host, uri.port)
        http.use_ssl = uri.scheme == "https"
        http.open_timeout = 5
        http.read_timeout = 15
        request_class = Net::HTTP.const_get(method.capitalize)
        request = request_class.new(uri, headers)
        request.body = JSON.generate(payload) if payload
        http.start { |connection| connection.request(request) }
      end

      def normalize_response(response)
        { status: response.code.to_i, body: JSON.parse(response.body.to_s) }
      rescue JSON::ParserError
        { status: response.code.to_i, body: {} }
      end

      def username_for(subscription_id)
        "zb_#{subscription_id}"
      end
    end
  end
end
