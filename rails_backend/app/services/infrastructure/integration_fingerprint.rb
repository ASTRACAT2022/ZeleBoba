require "digest"
require "json"

module Infrastructure
  class IntegrationFingerprint
    FIELDS = {
      "telegram" => %w[APP_URL TELEGRAM_BOT_TOKEN TELEGRAM_BOT_USERNAME TELEGRAM_WEBHOOK_SECRET TELEGRAM_API_BASE],
      "platega" => %w[APP_ENV PLATEGA_MERCHANT_ID PLATEGA_SECRET PLATEGA_API_BASE],
      "remnawave" => %w[REMNAWAVE_URL REMNAWAVE_TOKEN REMNAWAVE_SQUAD_UUID]
    }.freeze

    def self.call(values, integration)
      fields = FIELDS.fetch(integration.to_s)
      encoded = JSON.generate(values.slice(*fields), ascii_only: true).gsub("/") { "\\/" }
      Digest::SHA256.hexdigest(encoded)
    end
  end
end
