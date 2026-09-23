# frozen_string_literal: true

require "json"
require "net/http"
require "uri"

require_relative "../infrastructure/job_error"

module Zeleboba
  module Integration
    class TelegramClient
      MAX_MESSAGE_LENGTH = 4096

      def initialize(config, http: Net::HTTP)
        @config = config
        @http = http
      end

      def send_message(chat_id:, text:, reply_markup: nil)
        payload = {
          chat_id: valid_chat_id(chat_id),
          text: text.to_s[0, MAX_MESSAGE_LENGTH],
          disable_web_page_preview: true
        }
        raise Infrastructure::JobPermanentFailure, "Telegram message is empty" if payload[:text].strip.empty?

        payload[:reply_markup] = reply_markup if reply_markup
        request("sendMessage", payload)
      end

      def answer_callback_query(callback_query_id:, text: nil)
        payload = { callback_query_id: callback_query_id.to_s[0, 200] }
        payload[:text] = text.to_s[0, 200] unless text.to_s.empty?
        raise Infrastructure::JobPermanentFailure, "Telegram callback query id is missing" if payload[:callback_query_id].empty?

        request("answerCallbackQuery", payload)
      end

      def set_webhook(url:, secret_token:)
        webhook_url = URI.parse(url.to_s)
        raise Infrastructure::JobPermanentFailure, "Telegram webhook must use HTTPS" unless webhook_url.is_a?(URI::HTTPS)
        raise Infrastructure::JobPermanentFailure, "TELEGRAM_WEBHOOK_SECRET is not configured" if secret_token.to_s.empty?

        request(
          "setWebhook",
          {
            url: webhook_url.to_s,
            secret_token: secret_token.to_s,
            allowed_updates: %w[message callback_query],
            drop_pending_updates: false
          }
        )
      rescue URI::InvalidURIError => e
        raise Infrastructure::JobPermanentFailure, "Invalid Telegram webhook URL: #{e.message}"
      end

      private

      def request(method, payload)
        token = @config["TELEGRAM_BOT_TOKEN"].to_s
        raise Infrastructure::JobPermanentFailure, "TELEGRAM_BOT_TOKEN is not configured" if token.empty?

        base = @config.fetch("TELEGRAM_API_BASE", "https://api.telegram.org").to_s.sub(%r{/+\z}, "")
        uri = URI.parse("#{base}/bot#{token}/#{method}")
        response = @http.post(uri, JSON.generate(payload), "Content-Type" => "application/json")
        body = parse_json(response.body)
        return body.fetch("result", {}) if response.is_a?(Net::HTTPSuccess) && body["ok"] == true

        description = body["description"].to_s
        if response.code.to_i == 429
          retry_after = body.dig("parameters", "retry_after").to_i
          raise Infrastructure::JobDeferred.new("Telegram rate limit: #{description}", delay: retry_after.positive? ? retry_after : 60)
        end
        if response.code.to_i.between?(400, 499)
          raise Infrastructure::JobPermanentFailure, "Telegram rejected #{method}: #{description.empty? ? response.code : description}"
        end

        raise "Telegram #{method} failed: HTTP #{response.code} #{description}".strip
      rescue URI::InvalidURIError => e
        raise Infrastructure::JobPermanentFailure, "Invalid TELEGRAM_API_BASE: #{e.message}"
      end

      def parse_json(body)
        JSON.parse(body.to_s)
      rescue JSON::ParserError
        {}
      end

      def valid_chat_id(value)
        chat_id = value.to_s
        raise Infrastructure::JobPermanentFailure, "Invalid Telegram chat id" unless chat_id.match?(/\A-?[1-9][0-9]{0,19}\z/)

        chat_id
      end
    end
  end
end
