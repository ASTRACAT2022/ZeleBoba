require "net/http"
require "json"

module Integrations
  class IntegrationCheckService
    def check(name, register_webhook: false)
      config = Infrastructure::RuntimeConfig.new.values
      case name.to_s
      when "telegram"
        check_telegram(config, register_webhook: register_webhook)
      when "platega"
        check_platega(config)
      when "remnawave"
        check_remnawave(config)
      else
        raise Billing::BillingError, "Неизвестная интеграция."
      end
      record(config, name.to_s, "ok")
      { integration: name.to_s, status: "ok", checked_at: Time.now.to_i }
    rescue StandardError => error
      record(config, name.to_s, "failed") if defined?(config) && config && %w[telegram platega remnawave].include?(name.to_s)
      raise error if error.is_a?(Billing::BillingError)
      raise Billing::BillingError, "Проверка #{name} не прошла (#{error.class}). Секреты не записаны в журнал."
    end

    private

    def check_telegram(config, register_webhook:)
      token = config["TELEGRAM_BOT_TOKEN"].to_s
      username = config["TELEGRAM_BOT_USERNAME"].to_s.delete_prefix("@")
      raise Billing::BillingError, "Сначала сохраните токен и username бота." if token.blank? || username.blank?
      secret = config["TELEGRAM_WEBHOOK_SECRET"].to_s
      raise Billing::BillingError, "Задайте секрет Telegram webhook длиной от 32 до 256 символов (буквы, цифры, _ и -)." unless secret.match?(/\A[A-Za-z0-9_-]{32,256}\z/)
      base = config["TELEGRAM_API_BASE"].to_s.sub(%r{/*\z}, "")
      api = "#{base}/bot#{token}"
      bot = request_json(:get, "#{api}/getMe")
      raise Billing::BillingError, "Username не соответствует токену бота." unless bot.dig("result", "username").to_s.casecmp?(username)
      expected = "#{config["APP_URL"].to_s.sub(%r{/*\z}, "")}/rails/telegram/webhook"
      if register_webhook
        if Rails.env.production? && ENV["RAILS_ONLY_TOPICS_ENABLED"] != "1"
          raise Billing::BillingError, "Сначала остановите PHP worker и включите RAILS_ONLY_TOPICS_ENABLED=1 только после проверки Rails worker."
        end
        raise Billing::BillingError, "APP_URL должен быть HTTPS перед регистрацией Telegram webhook." unless URI.parse(config["APP_URL"].to_s).is_a?(URI::HTTPS)
        request_json(:post, "#{api}/setWebhook", {
          url: expected, secret_token: secret, allowed_updates: %w[message callback_query], drop_pending_updates: false
        })
      end
      info = request_json(:get, "#{api}/getWebhookInfo")
      raise Billing::BillingError, "Webhook бота не указывает на Rails endpoint. Зарегистрируйте его после проверки DNS/HTTPS." unless info.dig("result", "url") == expected
    end

    def check_platega(config)
      merchant = config["PLATEGA_MERCHANT_ID"].to_s
      secret = config["PLATEGA_SECRET"].to_s
      raise Billing::BillingError, "Заполните merchant_id и секрет Platega." if merchant.blank? || secret.blank?
      base = config["PLATEGA_API_BASE"].to_s.sub(%r{/*\z}, "")
      uri = URI("#{base}/v2/transaction/process")
      response = request_raw(:post, uri, "{}", "X-MerchantId" => merchant, "X-Secret" => secret)
      raise Billing::BillingError, "Platega не принял ключи (#{response.code})." if %w[401 403].include?(response.code)
      raise Billing::BillingError, "API Platega по адресу не найден." if response.code == "404"
      raise Billing::BillingError, "API Platega недоступен (#{response.code})." if response.code.to_i >= 500
      raise Billing::BillingError, "По указанному адресу ответил не API Platega." if response["content-type"].to_s.include?("text/html")
    end

    def check_remnawave(config)
      base = config["REMNAWAVE_URL"].to_s.sub(%r{/*\z}, "")
      token = config["REMNAWAVE_TOKEN"].to_s
      squad = config["REMNAWAVE_SQUAD_UUID"].to_s
      raise Billing::BillingError, "Заполните URL, токен и UUID группы." if base.blank? || token.blank? || squad.blank?
      uri = URI("#{base}/api/internal-squads")
      response = request_raw(:get, uri, nil, "Authorization" => "Bearer #{token}")
      data = parse_success(response)
      squads = data.dig("response", "internalSquads") || data["internalSquads"] || []
      raise Billing::BillingError, "Панель ответила, но выбранная группа не найдена." unless squads.any? { |row| row["uuid"] == squad }
    end

    def request_json(method, url, body = nil)
      uri = URI(url)
      response = request_raw(method, uri, body && JSON.generate(body), "Content-Type" => "application/json")
      data = parse_success(response)
      raise Billing::BillingError, "Telegram API отклонил запрос." if data.key?("ok") && data["ok"] != true
      data
    end

    def request_raw(method, uri, body = nil, headers = {})
      raise Billing::BillingError, "Проверяемые интеграции должны использовать HTTPS." unless uri.is_a?(URI::HTTPS)
      klass = { get: Net::HTTP::Get, post: Net::HTTP::Post }.fetch(method)
      request = klass.new(uri)
      headers.each { |key, value| request[key] = value }
      request.body = body if body
      http = Net::HTTP.new(uri.host, uri.port)
      http.use_ssl = true
      http.open_timeout = 5
      http.read_timeout = 10
      http.write_timeout = 5 if http.respond_to?(:write_timeout=)
      http.max_retries = 0 if http.respond_to?(:max_retries=)
      http.request(request)
    end

    def parse_success(response)
      raise Billing::BillingError, "Интеграция вернула HTTP #{response.code}." unless response.code.to_i.between?(200, 299)
      JSON.parse(response.body.presence || "{}")
    rescue JSON::ParserError
      raise Billing::BillingError, "Интеграция вернула некорректный ответ."
    end

    def record(config, name, status)
      fingerprint = Infrastructure::IntegrationFingerprint.call(config, name)
      IntegrationCheck.upsert({ integration: name, config_hash: fingerprint, status: status, checked_at: Time.now.to_i }, unique_by: :integration)
    end
  end
end
