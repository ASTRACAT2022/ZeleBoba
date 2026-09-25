module Payments
  require "erb"
  require "net/http"
  require "uri"

  class PlategaProvider
    DEFAULT_BASE = "https://app.platega.io"

    def id = "platega"

    def config
      @config ||= Infrastructure::RuntimeConfig.new
    end

    def configured?
      enabled = config.fetch("PLATEGA_ENABLED", "0") == "1" || config.fetch("PAYMENT_DRIVER", "") == "platega"
      enabled && config.fetch("PLATEGA_MERCHANT_ID", "").present? && config.fetch("PLATEGA_SECRET", "").present?
    end

    def create_order(order, user)
      app = config.fetch("APP_URL", "").sub(%r{/*\z}, "")
      create(order, user, "Подписка: #{order.plan_name.presence || "VPN"}", "#{app}/orders/#{order.id}", "#{app}/orders/#{order.id}")
    end

    def create_topup(topup, user)
      app = config.fetch("APP_URL", "").sub(%r{/*\z}, "")
      create(topup, user, "Пополнение баланса", "#{app}/balance", "#{app}/balance")
    end

    def verify(payment_id)
      res = request_json(:get, "transaction/#{ERB::Util.url_encode(payment_id)}")
      status = res.fetch("status", "").to_s.upcase
      amount_minor = Amounts.minor(Amounts.normalize(res.dig("paymentDetails", "amount") || "0"))
      commission_minor = Amounts.minor(Amounts.normalize(res["comission"] || "0"))
      raise Billing::BillingError, "Platega: некорректная комиссия." if commission_minor > amount_minor

      actual_id = res["id"] || res["transactionId"]
      raise Billing::BillingError, "Platega: несовпадение идентификатора платежа." unless actual_id == payment_id

      metadata = parse_json(res["payload"])
      if !metadata.key?("order_id") && !metadata.key?("topup_id") && res["orderId"].present?
        metadata = { "order_id" => res["orderId"].to_s, "topup_id" => res["orderId"].to_s }
      end

      {
        "status" => status == "CONFIRMED" ? "paid" : (%w[FAILED EXPIRED CANCELED].include?(status) ? "canceled" : "pending"),
        "amount_kopeks" => amount_minor - commission_minor,
        "currency" => res.dig("paymentDetails", "currency").presence || "RUB",
        "payment_id" => actual_id,
        "metadata" => metadata
      }
    end

    def handle_webhook(request)
      merchant = config.fetch("PLATEGA_MERCHANT_ID", "")
      secret = config.fetch("PLATEGA_SECRET", "")
      incoming = request.headers["X-MerchantId"].presence || request.headers["X-Merchant-Id"].to_s
      return nil if merchant.blank? || secret.blank?
      return nil unless ActiveSupport::SecurityUtils.secure_compare(merchant, incoming)
      return nil unless ActiveSupport::SecurityUtils.secure_compare(secret, request.headers["X-Secret"].to_s)

      data = JSON.parse(request.raw_post)
      payment_id = (data["transactionId"] || data["id"] || data["payment_id"]).to_s
      return nil if payment_id.blank?

      status = data["status"].to_s.upcase
      order_id = (data["orderId"] || data["order_id"]).to_s
      {
        "payment_id" => payment_id,
        "status" => status == "CONFIRMED" ? "paid" : (%w[FAILED EXPIRED CANCELED].include?(status) ? "canceled" : "pending"),
        "event_id" => "#{payment_id}:#{status}",
        "metadata" => { "order_id" => order_id, "topup_id" => order_id }
      }
    rescue JSON::ParserError
      nil
    end

    private

    def create(entity, user, description, return_url, failed_url)
      amount_minor = entity.respond_to?(:amount_kopeks) ? entity.amount_kopeks.to_i : entity.price_minor.to_i
      raise Billing::BillingError, "Platega: некорректная сумма заказа." if amount_minor < 100

      res = request_json(:post, "v2/transaction/process", {
        paymentDetails: { amount: Amounts.decimal(amount_minor), currency: "RUB" },
        description: description,
        return: return_url,
        failedUrl: failed_url,
        orderId: entity.id,
        payload: JSON.generate(entity.respond_to?(:amount_kopeks) ? { topup_id: entity.id } : { order_id: entity.id }),
        metadata: metadata(user)
      })
      checkout_url = res["url"].to_s
      payment_id = res["transactionId"].to_s
      raise Billing::BillingError, "Platega не вернул ссылку оплаты." unless checkout_url.start_with?("https://")
      raise Billing::BillingError, "Platega не вернул идентификатор платежа." if payment_id.blank? || payment_id.length > 100

      { "payment_id" => payment_id, "checkout_url" => checkout_url }
    end

    def metadata(user)
      {
        userId: (user.telegram_id.presence || user.id).to_s,
        userName: (user.try(:username).presence || user.email).to_s
      }.compact_blank
    end

    def request_json(method, endpoint, body = nil)
      raise Billing::BillingError, "Platega: не заполнены ключи (merchant_id/secret)." unless configured?

      uri = URI("#{config.fetch("PLATEGA_API_BASE", DEFAULT_BASE).sub(%r{/*\z}, "")}/#{endpoint}")
      http = Net::HTTP.new(uri.host, uri.port)
      http.use_ssl = uri.scheme == "https"
      http.open_timeout = 10
      http.read_timeout = 20
      klass = method == :post ? Net::HTTP::Post : Net::HTTP::Get
      req = klass.new(uri)
      req["X-MerchantId"] = config.fetch("PLATEGA_MERCHANT_ID")
      req["X-Secret"] = config.fetch("PLATEGA_SECRET")
      req["Content-Type"] = "application/json"
      req.body = JSON.generate(body) if body
      res = http.request(req)
      raise Billing::BillingError, "Platega returned HTTP #{res.code}." unless res.code.to_i.between?(200, 299)
      JSON.parse(res.body)
    rescue JSON::ParserError
      raise Billing::BillingError, "Platega: некорректный JSON от провайдера."
    end

    def parse_json(value)
      parsed = JSON.parse(value.to_s.presence || "{}")
      parsed.is_a?(Hash) ? parsed : {}
    rescue JSON::ParserError
      {}
    end
  end
end
