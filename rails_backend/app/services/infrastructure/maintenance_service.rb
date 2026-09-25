module Infrastructure
  class MaintenanceService
    SETTING = "MAINTENANCE_MODE".freeze

    def status
      AppSetting.find_by(name: SETTING)&.value == "1"
    end

    def set(enabled:, actor:)
      value = enabled ? "1" : "0"
      now = Time.now.to_i

      ApplicationRecord.transaction do
        setting = AppSetting.find_or_initialize_by(name: SETTING)
        setting.assign_attributes(value: value, updated_at: now)
        setting.save!
        AuditLog.create!(id: IdGenerator.call, actor: actor,
          action: enabled ? "maintenance.on" : "maintenance.off", subject: "", created_at: now)
      end
      status
    end
  end
end
