module Operations
  class ControlPlaneService
    FLAG_NAME = /\A[a-z][a-z0-9_.-]{2,79}\z/
    SWITCH_NAME = /\A[a-z][a-z0-9_.-]{2,63}\z/

    def flags
      FeatureFlag.order(:name).as_json
    end

    def set_flag(name:, enabled:, rollout:, actor:)
      name = name.to_s
      rollout = Integer(rollout)
      raise Billing::BillingError, "Некорректный feature flag." unless name.match?(FLAG_NAME) && rollout.between?(0, 100)

      now = Time.now.to_i
      flag = FeatureFlag.find_or_initialize_by(name: name)
      flag.assign_attributes(enabled: enabled ? 1 : 0, rollout_percent: rollout,
        updated_by: actor, updated_at: now)
      flag.save!
      AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor,
        action: "feature_flag.updated", subject: name, created_at: now)
      flag
    rescue ArgumentError, TypeError
      raise Billing::BillingError, "Некорректный feature flag."
    end

    def switches
      { safe_mode: AppSetting.find_by(name: "SAFE_MODE")&.value == "1",
        switches: KillSwitch.order(:name).as_json,
        breakers: %w[remnawave_api].map { |name| breaker_state(name) } }
    end

    def set_switch(name:, enabled:, reason:, actor:)
      name = name.to_s
      reason = reason.to_s.strip
      if name == "__safe_mode__"
        ApplicationRecord.transaction do
          setting = AppSetting.find_or_initialize_by(name: "SAFE_MODE")
          setting.update!(value: enabled ? "1" : "0", updated_at: Time.now.to_i)
          audit(actor, enabled ? "safe_mode.on" : "safe_mode.off", reason)
        end
        return { safe_mode: enabled }
      end
      raise Billing::BillingError, "Некорректное имя переключателя." unless name.match?(SWITCH_NAME)

      now = Time.now.to_i
      switch = KillSwitch.find_or_initialize_by(name: name)
      switch.assign_attributes(enabled: enabled ? 1 : 0, actor: actor.to_s,
        reason: reason, created_at: now)
      ApplicationRecord.transaction do
        switch.save!
        audit(actor, "kill_switch.#{enabled ? 'on' : 'off'}.#{name}", reason)
        audit(actor, "kill_switch.updated", name)
      end
      switch
    end

    def reset_breaker(name:, actor:)
      raise Billing::BillingError, "Неизвестный circuit breaker." unless name.to_s == "remnawave_api"
      breaker = CircuitBreakerState.find_or_initialize_by(name: name.to_s)
      breaker.assign_attributes(state: "closed", failures: 0, opened_at: nil, updated_at: Time.now.to_i)
      breaker.save!
      AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor,
        action: "circuit_breaker.reset", subject: name.to_s, created_at: Time.now.to_i)
      breaker
    end

    def approvals
      { pending: ApprovalQueue.where(status: "pending").order(:requested_at).limit(100).map { |row| approval_json(row) },
        history: ApprovalQueue.order(requested_at: :desc).limit(50).map { |row| approval_json(row, include_payload: false) } }
    end

    def decide_approval(id:, decision:, actor:)
      raise Billing::BillingError, "Недопустимое решение." unless %w[approve reject].include?(decision.to_s)
      ApplicationRecord.transaction do
        row = ApprovalQueue.lock.find_by(id: id, status: "pending")
        raise Billing::BillingError, "Заявка не найдена или уже решена." unless row
        raise Billing::BillingError, "Нельзя подтверждать собственное действие." if decision == "approve" && row.actor.to_s == actor.to_s
        row.update!(status: decision == "approve" ? "approved" : "rejected",
          decided_at: Time.now.to_i, decided_by: actor.to_s)
        AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor.to_s,
          action: "approval.#{decision}", subject: row.id, created_at: Time.now.to_i)
        row
      end
    end

    def create_incident(title:, actor:)
      title = title.to_s.strip
      raise Billing::BillingError, "Заголовок: 1–200 символов." unless title.length.between?(1, 200)
      now = Time.now.to_i
      incident = Incident.create!(id: Infrastructure::IdGenerator.call,
        code: "INC-#{Time.now.utc.year}-#{SecureRandom.random_number(90_000) + 10_000}",
        title: title, status: "open", created_by: actor, created_at: now)
      AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor,
        action: "incident.created", subject: incident.id, created_at: now)
      incident
    end

    def incidents
      Incident.order(Arel.sql("CASE status WHEN 'open' THEN 0 ELSE 1 END"), created_at: :desc).limit(100)
    end

    private

    def breaker_state(name)
      CircuitBreakerState.find_by(name: name)&.as_json ||
        { "name" => name, "state" => "closed", "failures" => 0, "opened_at" => nil,
          "last_success" => nil, "last_failure" => nil, "updated_at" => 0 }
    end

    def approval_json(row, include_payload: true)
      data = row.as_json(only: %i[id action actor status requested_at decided_at decided_by])
      data["payload"] = JSON.parse(row.payload.presence || "{}") if include_payload
      data
    rescue JSON::ParserError
      data.merge("payload" => {})
    end

    def audit(actor, action, subject)
      AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor.to_s,
        action: action, subject: subject.to_s, created_at: Time.now.to_i)
    end
  end
end
