require "net/http"
require "json"

module Integrations
  class TelegramClient
    PERMANENT_CODES = [400, 403, 404].freeze
    PERMANENT_CALLBACK_CODES = (PERMANENT_CODES + [409, 410]).freeze

    def send_message(payload)
      call("sendMessage", payload)
    end

    def answer_callback(payload)
      call("answerCallbackQuery", payload)
    end

    def check_membership(channel_id:, user_id:)
      config = Infrastructure::RuntimeConfig.new
      token = config.fetch("TELEGRAM_BOT_TOKEN", "").strip
      raise Billing::BillingError, "Telegram is not configured." if token.empty?
      base = config.fetch("TELEGRAM_API_BASE", "https://astracattg.netlify.app").sub(%r{/*\z}, "")
      uri = URI.parse("#{base}/bot#{token}/getChatMember")
      raise Billing::BillingError, "Telegram API must use HTTPS." unless uri.is_a?(URI::HTTPS)
      uri.query = URI.encode_www_form(chat_id: channel_id, user_id: user_id)
      request = Net::HTTP::Get.new(uri)
      request["Accept"] = "application/json"
      response = http_request(uri, request)
      result = JSON.parse(response.body.presence || "{}")
      return false unless result["ok"] && response.code.to_i.between?(200, 299)

      %w[creator administrator member].include?(result.dig("result", "status").to_s)
    rescue JSON::ParserError, URI::InvalidURIError
      raise IOError, "Telegram membership check returned an invalid response."
    end

    private

    def call(method, payload)
      config = Infrastructure::RuntimeConfig.new
      token = config.fetch("TELEGRAM_BOT_TOKEN", "").strip
      raise Billing::BillingError, "Telegram is not configured." if token.empty?
      base = config.fetch("TELEGRAM_API_BASE", "https://astracattg.netlify.app").sub(%r{/*\z}, "")
      uri = URI.parse("#{base}/bot#{token}/#{method}")
      raise Billing::BillingError, "Telegram API must use HTTPS." unless uri.is_a?(URI::HTTPS)
      request = Net::HTTP::Post.new(uri)
      request["Content-Type"] = "application/json"
      request["Accept"] = "application/json"
      request.body = JSON.generate(payload)
      response = http_request(uri, request)
      result = JSON.parse(response.body.presence || "{}")
      status = result["error_code"].to_i.nonzero? || response.code.to_i
      if !result["ok"] || !response.code.to_i.between?(200, 299)
        permanent_codes = method == "answerCallbackQuery" ? PERMANENT_CALLBACK_CODES : PERMANENT_CODES
        raise Infrastructure::JobPermanentFailure, "Telegram permanently refused (#{status})." if permanent_codes.include?(status)
        raise IOError, "Telegram temporary failure (#{status})."
      end
      result
    rescue JSON::ParserError, URI::InvalidURIError, IOError, SystemCallError, Timeout::Error => error
      raise if error.is_a?(Infrastructure::JobPermanentFailure)
      raise IOError, "Telegram request failed (#{error.class})."
    end

    def http_request(uri, request)
      http = Net::HTTP.new(uri.host, uri.port)
      http.use_ssl = true
      http.open_timeout = 5
      http.read_timeout = 20
      http.write_timeout = 10 if http.respond_to?(:write_timeout=)
      http.request(request)
    end
  end
end
