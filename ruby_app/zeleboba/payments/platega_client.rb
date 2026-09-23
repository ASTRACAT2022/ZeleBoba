# frozen_string_literal: true

require "json"
require "net/http"
require "uri"

require_relative "../billing/error"
require_relative "../infrastructure/job_error"

module Zeleboba
  module Payments
    class PlategaClient
      def initialize(config, http: Net::HTTP)
        @config = config
        @http = http
      end

      def configured?
        @config["PLATEGA_ENABLED"] == "1" && !merchant_id.empty? && !secret.empty?
      end

      def create(entity:, user:, kind:)
        ensure_configured!
        amount = entity.fetch(kind == "topup" ? "amount_kopeks" : "price_minor").to_i
        raise Billing::Error, "Некорректная сумма заказа." unless amount >= 100
        app_url = @config.fetch("APP_URL").sub(%r{/\z}, "")
        entity_id = entity.fetch("id")
        response = request("POST", "/v2/transaction/process", {
          "paymentDetails" => { "amount" => format("%.2f", amount / 100.0), "currency" => "RUB" },
          "description" => kind == "topup" ? "Пополнение баланса" : "Подписка: #{entity["plan_name"] || "VPN"}",
          "return" => kind == "topup" ? "#{app_url}/balance" : "#{app_url}/orders/#{entity_id}",
          "failedUrl" => kind == "topup" ? "#{app_url}/balance" : "#{app_url}/orders/#{entity_id}",
          "orderId" => entity_id,
          "payload" => JSON.generate(kind == "topup" ? { "topup_id" => entity_id } : { "order_id" => entity_id }),
          "metadata" => { "userId" => (user["telegram_id"] || user["id"]).to_s, "userName" => (user["username"] || user["email"] || "").to_s }.reject { |_key, value| value.empty? }
        })
        payment_id = response["transactionId"].to_s
        checkout_url = response["url"].to_s
        raise Billing::Error, "Platega не вернул идентификатор платежа." unless payment_id.match?(/\A[a-zA-Z0-9:_-]{1,100}\z/)
        raise Billing::Error, "Platega не вернул ссылку оплаты." unless checkout_url.start_with?("https://")

        { "payment_id" => payment_id, "checkout_url" => checkout_url }
      end

      def verify(payment_id)
        ensure_configured!
        response = request("GET", "/transaction/#{URI::DEFAULT_PARSER.escape(payment_id)}")
        actual_id = (response["id"] || response["transactionId"]).to_s
        raise Billing::Error, "Platega вернул другой идентификатор платежа." unless actual_id == payment_id

        amount = minor(response.dig("paymentDetails", "amount"))
        commission = minor(response["comission"])
        raise Billing::Error, "Platega вернул некорректную комиссию." if commission > amount
        status = response["status"].to_s.upcase
        payload = JSON.parse(response.fetch("payload", "{}")) rescue {}
        { "payment_id" => actual_id, "status" => status == "CONFIRMED" ? "paid" : (%w[FAILED EXPIRED CANCELED].include?(status) ? "canceled" : "pending"), "amount_kopeks" => amount - commission, "currency" => response.dig("paymentDetails", "currency").to_s.empty? ? "RUB" : response.dig("paymentDetails", "currency"), "metadata" => payload }
      end

      private

      def request(method, path, body = nil)
        uri = URI("#{@config.fetch("PLATEGA_API_BASE", "https://app.platega.io").sub(%r{/\z}, "")}#{path}")
        request = method == "POST" ? Net::HTTP::Post.new(uri) : Net::HTTP::Get.new(uri)
        request["X-MerchantId"] = merchant_id
        request["X-Secret"] = secret
        request["Content-Type"] = "application/json"
        request.body = JSON.generate(body) if body
        response = @http.start(uri.host, uri.port, use_ssl: uri.scheme == "https", open_timeout: 5, read_timeout: 15) { |client| client.request(request) }
        raise Infrastructure::JobDeferred.new("Platega returned #{response.code}", delay: 60) if response.code.to_i >= 500 || response.code.to_i == 429
        raise Billing::Error, "Platega ответил HTTP #{response.code}." unless response.code.to_i.between?(200, 299)

        JSON.parse(response.body)
      rescue JSON::ParserError
        raise Billing::Error, "Platega вернул некорректный ответ."
      rescue Net::OpenTimeout, Net::ReadTimeout, SocketError => e
        raise Infrastructure::JobDeferred.new("Platega unavailable: #{e.class}", delay: 60)
      end

      def minor(value)
        text = value.to_s
        raise Billing::Error, "Platega вернул некорректную сумму." unless text.match?(/\A\d+(?:\.\d{1,2})?\z/)

        whole, fraction = text.split(".", 2)
        whole.to_i * 100 + fraction.to_s.ljust(2, "0")[0, 2].to_i
      end

      def merchant_id = @config["PLATEGA_MERCHANT_ID"].to_s
      def secret = @config["PLATEGA_SECRET"].to_s

      def ensure_configured!
        raise Billing::Error, "Platega не настроен." unless configured?
      end
    end
  end
end
