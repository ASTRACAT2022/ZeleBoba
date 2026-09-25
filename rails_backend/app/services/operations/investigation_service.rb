module Operations
  class InvestigationService
    SUBJECT_TYPES = %w[user subscription payment incident].freeze

    def list
      Investigation.order(Arel.sql("CASE status WHEN 'open' THEN 0 ELSE 1 END"), created_at: :desc).limit(100)
        .as_json(include: { investigation_notes: { only: %i[id author_id body created_at] } })
    end

    def detail(id)
      record = Investigation.find_by(id: id)
      return unless record

      data = record.as_json
      data["notes"] = InvestigationNote.where(investigation_id: record.id).joins("LEFT JOIN users ON users.id = investigation_notes.author_id")
        .order(created_at: :asc).pluck("investigation_notes.id", "investigation_notes.author_id", "investigation_notes.body",
          "investigation_notes.created_at", "users.email", "users.telegram_id").map do |note_id, author_id, body, created_at, email, telegram_id|
          { id: note_id, author_id: author_id, body: body, created_at: created_at, email: email, telegram_id: telegram_id }
        end
      data["context"] = context(record.subject_type, record.subject_id)
      data
    end

    def start(type:, subject_id:, title:, actor:)
      type = type.to_s
      subject_id = subject_id.to_s.strip
      raise Billing::BillingError, "Некорректный объект расследования." unless SUBJECT_TYPES.include?(type) && subject_id.present? && subject_exists?(type, subject_id)

      existing = Investigation.find_by(subject_type: type, subject_id: subject_id, status: "open")
      return existing if existing

      now = Time.now.to_i
      Investigation.create!(id: Infrastructure::IdGenerator.call,
        code: "INV-#{Time.now.utc.year}-#{SecureRandom.random_number(90_000) + 10_000}",
        status: "open", subject_type: type, subject_id: subject_id,
        title: title.to_s.strip.presence&.first(200) || "Расследование",
        created_by: actor, created_at: now)
    rescue ActiveRecord::RecordNotUnique
      Investigation.find_by!(subject_type: type, subject_id: subject_id, status: "open")
    end

    def note(case_id:, body:, actor:)
      body = body.to_s.strip
      raise Billing::BillingError, "Заметка должна содержать от 2 до 2000 символов." unless body.length.between?(2, 2000)

      ApplicationRecord.transaction do
        record = Investigation.lock.find_by(id: case_id, status: "open")
        raise Billing::BillingError, "Расследование закрыто или не найдено." unless record
        InvestigationNote.create!(id: Infrastructure::IdGenerator.call, investigation_id: record.id,
          author_id: actor, body: body, created_at: Time.now.to_i)
      end
    end

    def resolve(case_id:, actor:)
      ApplicationRecord.transaction do
        record = Investigation.lock.find_by(id: case_id, status: "open")
        raise Billing::BillingError, "Расследование закрыто или не найдено." unless record
        now = Time.now.to_i
        record.update!(status: "resolved", resolved_at: now)
        AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor,
          action: "investigation.resolved", subject: record.id, created_at: now)
      end
    end

    def smart_queues
      { need_attention: ProvisioningAccount.where(state: %w[retry failed]).count,
        payment_issues: Payment.where(status: %w[pending failed]).count,
        provisioning: ProvisioningAccount.where(state: %w[pending processing retry]).count,
        manual_review: OperationalCase.where(status: "open").count }
    end

    def expected_actual(subscription_id)
      subscription = Subscription.includes(:user).find_by(id: subscription_id)
      return unless subscription

      account = ProvisioningAccount.find_by(subscription_id: subscription.id)
      remote = nil
      error = "Сверка с Remnawave не настроена"
      config = Infrastructure::RuntimeConfig.new
      if config.fetch("REMNAWAVE_URL", "").present? && config.fetch("REMNAWAVE_TOKEN", "").present?
        begin
          remote = Integrations::RemnawaveClient.new.resolve(subscription.attributes.symbolize_keys)
          error = "Пользователь не найден в Remnawave" unless remote
        rescue StandardError
          error = "Не удалось получить текущие данные Remnawave"
        end
      end

      expiry = remote && Time.iso8601(remote["expireAt"].to_s).to_i rescue nil
      expected_bytes = subscription.traffic_limit_gb.to_i.zero? ? 0 :
        (subscription.traffic_limit_gb.to_i + subscription.purchased_traffic_gb.to_i) * 1_073_741_824
      actual_bytes = remote && remote["trafficLimitBytes"]&.to_i
      rows = [
        { name: "Срок действия", expected: Time.at(subscription.expires_at.to_i).utc.strftime("%d.%m.%Y %H:%M UTC"),
          actual: expiry ? Time.at(expiry).utc.strftime("%d.%m.%Y %H:%M UTC") : "Не проверено",
          ok: expiry.present? && (expiry - subscription.expires_at.to_i).abs <= 60 },
        { name: "Лимит трафика", expected: expected_bytes.zero? ? "Безлимит" : "#{(expected_bytes / 1_073_741_824.0).round} ГБ",
          actual: actual_bytes.nil? ? "Не проверено" : (actual_bytes.zero? ? "Безлимит" : "#{(actual_bytes / 1_073_741_824.0).round} ГБ"),
          ok: !actual_bytes.nil? && actual_bytes == expected_bytes },
        { name: "Статус VPN", expected: "ACTIVE", actual: remote&.dig("status") || "Не проверено",
          ok: remote&.dig("status") == "ACTIVE" }
      ]
      { subscription: subscription.as_json(include: :user), rows: rows, freshness: remote ? 0 : nil, error: remote ? nil : error }
    end

    def why_not_renewed(subscription_id)
      subscription = Subscription.includes(:user).find_by(id: subscription_id)
      raise Billing::BillingError, "Подписка не найдена." unless subscription
      plan = Plan.find_by(id: subscription.renew_plan_id.presence || subscription.plan_id)
      price_minor = plan&.price_minor.to_i
      balance = subscription.user.balance_kopeks.to_i
      reasons = if subscription.auto_renew.to_i.zero?
        [{ ok: false, text: "Автопродление выключено пользователем или оператором." }]
      elsif subscription.expires_at.to_i > Time.now.to_i
        [{ ok: false, text: "Срок продления ещё не наступил." }]
      elsif balance < price_minor
        [{ ok: false, text: "Недостаточно средств: доступно #{format_minor(balance)}, требуется #{format_minor(price_minor)}." }]
      elsif subscription.renew_order_id.present?
        [{ ok: true, text: "Заказ на продление уже создан и ожидает обработку." }]
      else
        [{ ok: false, text: "Не найдено автоматического заказа: требуется проверка планировщика." }]
      end
      { subscription: subscription.as_json, reasons: reasons }
    end

    private

    def subject_exists?(type, id)
      { "user" => User, "subscription" => Subscription, "payment" => Payment, "incident" => Incident }.fetch(type).exists?(id: id)
    end

    def context(type, id)
      case type
      when "user" then { user: User.where(id: id).pick(:id, :email, :telegram_id) }
      when "subscription" then { subscription: expected_actual(id) }
      when "payment" then { payment: Payment.find_by(id: id)&.as_json }
      when "incident" then { incident: Incident.find_by(id: id)&.as_json }
      else {}
      end
    end

    def format_minor(amount)
      "#{format('%.2f', amount.to_i / 100.0).tr('.', ',')} ₽"
    end
  end
end
