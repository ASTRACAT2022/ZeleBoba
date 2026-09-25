module Infrastructure
  class SettingsService
    DEFAULTS = RuntimeConfig::DEFAULTS
    SECRETS = RuntimeConfig::SECRETS
    LOCKED_IDENTITY = %w[PLATEGA_MERCHANT_ID REMNAWAVE_URL TELEGRAM_BOT_USERNAME].freeze

    def initialize(vault: Identity::Vault.new)
      @vault = vault
    end

    def form
      values = RuntimeConfig.new.values
      set = SECRETS.to_h { |key| [key, values[key].present?] }
      SECRETS.each { |key| values[key] = "" }
      { values: values, secrets_set: set, revision: SettingsRevision.find_by(id: 1)&.revision.to_i }
    end

    def save(input:, actor:, revision:)
      ApplicationRecord.transaction do
        current = SettingsRevision.lock.find_by(id: 1)
        raise Billing::BillingError, "Настройки не инициализированы." unless current
        raise Billing::BillingError, "Настройки изменились в другой вкладке. Обновите страницу." unless current.revision.to_i == revision.to_i
        old = RuntimeConfig.new.values
        values = old.dup
        attrs = input.to_h.stringify_keys
        DEFAULTS.each_key do |key|
          next unless attrs.key?(key)
          value = attrs[key]
          raise Billing::BillingError, "Некорректная настройка: #{key}" unless value.is_a?(String) && value.bytesize <= 2048
          value = value.strip
          next if SECRETS.include?(key) && value.empty?
          values[key] = value
        end
        validate!(values)
        LOCKED_IDENTITY.each do |key|
          if old[key].present? && old[key] != values[key]
            raise Billing::BillingError, "Замена магазина, панели или бота требует отдельной миграции. Обновить ключ доступа можно здесь."
          end
        end
        now = Time.now.to_i
        values.each do |key, value|
          next if old[key] == value
          stored = SECRETS.include?(key) ? @vault.seal(key, value) : value
          AppSetting.upsert({ name: key, value: stored, updated_at: now }, unique_by: :name)
          AuditLog.create!(id: IdGenerator.call, actor: actor, action: "setting.changed", subject: key, created_at: now)
        end
        current.update!(revision: current.revision.to_i + 1)
      end
      form
    end

    private

    def validate!(v)
      { "APP_ENV" => %w[dev prod], "PAYMENT_DRIVER" => %w[demo platega],
        "PROVISION_DRIVER" => %w[demo remnawave], "PURCHASES_ENABLED" => %w[0 1],
        "PLATEGA_ENABLED" => %w[0 1],
        "REGISTRATION_ENABLED" => %w[0 1], "AUTORENEW_ENABLED" => %w[0 1],
        "SMTP_ENABLED" => %w[0 1], "REFERRAL_PROGRAM_ENABLED" => %w[0 1],
        "REFERRAL_WITHDRAWAL_ENABLED" => %w[0 1], "CABINET_GIFT_ENABLED" => %w[0 1],
        "TRIAL_ADD_REMAINING_DAYS_TO_PAID" => %w[0 1], "TRIAL_PAYMENT_ENABLED" => %w[0 1] }.each do |key, allowed|
        raise Billing::BillingError, "Некорректная настройка #{key}" unless allowed.include?(v[key])
      end
      app = parsed_uri(v["APP_URL"])
      unless app && %w[http https].include?(app.scheme) && app.userinfo.nil? && app.query.nil? && app.fragment.nil? && ["", "/"].include?(app.path.to_s)
        raise Billing::BillingError, "Укажите корневой URL кабинета, без пути и параметров."
      end
      raise Billing::BillingError, "Название: от 1 до 60 символов." unless v["SITE_NAME"].length.between?(1, 60)
      %w[BRAND_LOGO BRAND_FAVICON].each do |key|
        raise Billing::BillingError, "#{key}: нужен HTTPS URL картинки." if v[key].present? && !https_url?(v[key])
      end
      %w[BRAND_COLOR BRAND_COLOR_ACCENT].each do |key|
        raise Billing::BillingError, "#{key}: формат #RRGGBB." if v[key].present? && !v[key].match?(/\A#[0-9a-fA-F]{6}\z/)
      end
      raise Billing::BillingError, "Текст подвала: до 200 символов." if v["BRAND_FOOTER_TEXT"].length > 200
      %w[BRAND_WELCOME_TEXT BRAND_HELP_TEXT].each { |key| raise Billing::BillingError, "#{key}: до 2000 символов." if v[key].length > 2000 }
      %w[REMNAWAVE_URL SUPPORT_URL].each do |key|
        next if v[key].blank?
        parsed = parsed_uri(v[key])
        raise Billing::BillingError, "#{key}: нужен HTTPS URL без логина и фрагмента." unless parsed&.scheme == "https" && parsed.userinfo.nil? && parsed.fragment.nil?
        if key == "REMNAWAVE_URL" && (!["", "/"].include?(parsed.path.to_s) || parsed.query)
          raise Billing::BillingError, "URL панели указывается без /api и параметров."
        end
      end
      raise Billing::BillingError, "Введите username бота без @." if v["TELEGRAM_BOT_USERNAME"].present? && !v["TELEGRAM_BOT_USERNAME"].match?(/\A[a-zA-Z0-9_]{2,29}bot\z/i)
      raise Billing::BillingError, "Некорректный токен Telegram." if v["TELEGRAM_BOT_TOKEN"].present? && !v["TELEGRAM_BOT_TOKEN"].match?(/\A[0-9]+:[a-zA-Z0-9_-]{20,}\z/)
      raise Billing::BillingError, "Секрет webhook: 32–256 символов." if v["TELEGRAM_WEBHOOK_SECRET"].present? && !v["TELEGRAM_WEBHOOK_SECRET"].match?(/\A[a-zA-Z0-9_-]{32,256}\z/)
      telegram_api = parsed_uri(v["TELEGRAM_API_BASE"])
      if v["TELEGRAM_API_BASE"].present? && (telegram_api&.scheme != "https" || telegram_api.userinfo || telegram_api.query || telegram_api.fragment || !["", "/"].include?(telegram_api.path.to_s))
        raise Billing::BillingError, "TELEGRAM_API_BASE: нужен корневой HTTPS URL без параметров."
      end
      raise Billing::BillingError, "SMTP включён, но не заполнены хост, логин и пароль." if v["SMTP_ENABLED"] == "1" && %w[SMTP_HOST SMTP_USER SMTP_PASSWORD].any? { |key| v[key].blank? }
      smtp_port = v["SMTP_PORT"]
      raise Billing::BillingError, "SMTP_PORT: число от 1 до 65535." if smtp_port.present? && (!smtp_port.match?(/\A[0-9]{1,5}\z/) || !smtp_port.to_i.between?(1, 65_535))
      raise Billing::BillingError, "SMTP_FROM: нужен корректный email." if v["SMTP_FROM"].present? && !v["SMTP_FROM"].match?(URI::MailTo::EMAIL_REGEXP)
      raise Billing::BillingError, "SMTP_FROM_NAME: до 60 символов." if v["SMTP_FROM_NAME"].length > 60
      raise Billing::BillingError, "Platega включена, но не заполнены ключи." if v["PLATEGA_ENABLED"] == "1" && %w[PLATEGA_MERCHANT_ID PLATEGA_SECRET].any? { |key| v[key].blank? }
      platega_api = parsed_uri(v["PLATEGA_API_BASE"])
      raise Billing::BillingError, "PLATEGA_API_BASE: нужен HTTPS URL." if v["PLATEGA_API_BASE"].present? && (platega_api&.scheme != "https" || platega_api.host.blank?)
      validate_numbers!(v)
      production = Rails.env.production? || v["APP_ENV"] == "prod"
      if production
        raise Billing::BillingError, "Боевой режим требует PostgreSQL и HTTPS." unless ApplicationRecord.connection.adapter_name == "PostgreSQL" && v["APP_URL"].start_with?("https://")
        if v["PURCHASES_ENABLED"] == "1"
          raise Billing::BillingError, "Покупки через Rails пока закрыты: обработчик Telegram ещё работает в PHP."
        end
      end
      validate_purchases!(v, production: production) if v["PURCHASES_ENABLED"] == "1"
    end

    def validate_numbers!(v)
      %w[TRIAL_DURATION_DAYS TRIAL_TRAFFIC_LIMIT_GB TRIAL_DEVICE_LIMIT TRIAL_ACTIVATION_PRICE].each do |key|
        raise Billing::BillingError, "Некорректная настройка #{key}" unless v[key].match?(/\A[0-9]{1,6}\z/)
      end
      %w[REFERRAL_MINIMUM_TOPUP_KOPEKS REFERRAL_FIRST_TOPUP_BONUS_KOPEKS REFERRAL_INVITER_BONUS_KOPEKS REFERRAL_WITHDRAWAL_MIN_AMOUNT_KOPEKS REFERRAL_WITHDRAWAL_COOLDOWN_DAYS REFERRAL_WITHDRAWAL_SUSPICIOUS_MIN_DEPOSIT_KOPEKS REFERRAL_WITHDRAWAL_SUSPICIOUS_MAX_DEPOSITS_PER_MONTH].each do |key|
        raise Billing::BillingError, "Некорректная настройка #{key}" unless v[key].match?(/\A[0-9]{1,10}\z/)
      end
      commission = v["REFERRAL_COMMISSION_PERCENT"]
      raise Billing::BillingError, "Комиссия: 0–100%." unless commission.match?(/\A[0-9]{1,3}\z/) && commission.to_i <= 100
      first = v["REFERRAL_FIRST_PAYMENT_COMMISSION_PERCENT"]
      raise Billing::BillingError, "Комиссия за первый платёж: 0–100%." if first.present? && (!first.match?(/\A[0-9]{1,3}\z/) || first.to_i > 100)
      tiers = v["REFERRAL_RECURRING_COMMISSION_TIERS"]
      raise Billing::BillingError, "Некорректный формат ступеней комиссии." if tiers.present? && !tiers.match?(/\A[0-9]{1,6}:[0-9]{1,3}(,[0-9]{1,6}:[0-9]{1,3})*\z/)
      raise Billing::BillingError, "Автопродление: за сколько дней — от 1 до 14." unless v["AUTORENEW_DAYS_BEFORE"].match?(/\A[0-9]{1,2}\z/) && v["AUTORENEW_DAYS_BEFORE"].to_i.between?(1, 14)
      raise Billing::BillingError, "Автопродление: максимум попыток — от 1 до 10." unless v["AUTORENEW_MAX_FAILS"].match?(/\A[0-9]{1,2}\z/) && v["AUTORENEW_MAX_FAILS"].to_i.between?(1, 10)
    end

    def validate_purchases!(v, production:)
      raise Billing::BillingError, "В боевом режиме демоадаптеры запрещены." if production && (v["PAYMENT_DRIVER"] == "demo" || v["PROVISION_DRIVER"] == "demo")
      if v["PAYMENT_DRIVER"] == "platega"
        raise Billing::BillingError, "Не заполнено: PLATEGA_MERCHANT_ID" if v["PLATEGA_MERCHANT_ID"].blank?
        raise Billing::BillingError, "Не заполнено: PLATEGA_SECRET" if v["PLATEGA_SECRET"].blank?
      end
      if v["PROVISION_DRIVER"] == "remnawave"
        %w[REMNAWAVE_URL REMNAWAVE_TOKEN REMNAWAVE_SQUAD_UUID].each { |key| raise Billing::BillingError, "Не заполнено: #{key}" if v[key].blank? }
      end
      return unless production
      %w[platega remnawave telegram].each do |integration|
        check = IntegrationCheck.find_by(integration: integration)
        expected = IntegrationFingerprint.call(v, integration)
        unless check&.status == "ok" && ActiveSupport::SecurityUtils.secure_compare(check.config_hash, expected) && check.checked_at.to_i >= Time.now.to_i - 86_400
          raise Billing::BillingError, "Сначала сохраните настройки с выключенными продажами и проверьте #{integration}."
        end
      end
    end

    def parsed_uri(value)
      URI.parse(value.to_s)
    rescue URI::InvalidURIError
      nil
    end

    def https_url?(value)
      uri = parsed_uri(value)
      uri&.scheme == "https" && uri.host.present? && uri.userinfo.nil?
    end
  end
end
