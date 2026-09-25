module Billing
  class PlanAdminService
    def create(input:, actor:)
      attrs = normalize(input, creating: true)
      ApplicationRecord.transaction do
        plan = Plan.create!(id: Infrastructure::IdGenerator.call, name: attrs.fetch(:name),
          price_minor: attrs.fetch(:price_minor), currency: "RUB", duration_days: attrs.fetch(:duration_days),
          duration_months: 0, traffic_bytes: attrs.fetch(:traffic_bytes), devices: attrs.fetch(:devices),
          active: 1, squad_uuid: attrs.fetch(:squad_uuid), autorenew_days_before: attrs[:autorenew_days_before],
          autorenew_max_fails: attrs[:autorenew_max_fails])
        create_version(plan)
        audit(actor, "plan.created", plan.id)
        plan
      end
    rescue ActiveRecord::RecordNotUnique
      raise BillingError, "Не удалось создать тариф с такими параметрами."
    end

    def update(id:, input:, actor:)
      attrs = normalize(input, creating: false)
      ApplicationRecord.transaction do
        plan = Plan.lock.find_by(id: id)
        raise BillingError, "Тариф не найден." unless plan
        plan.update!(name: attrs.fetch(:name), price_minor: attrs.fetch(:price_minor),
          duration_days: attrs.fetch(:duration_days), devices: attrs.fetch(:devices),
          traffic_bytes: attrs.fetch(:traffic_bytes), squad_uuid: attrs.fetch(:squad_uuid),
          autorenew_days_before: attrs[:autorenew_days_before], autorenew_max_fails: attrs[:autorenew_max_fails],
          active: attrs.fetch(:active) ? 1 : 0)
        create_version(plan)
        audit(actor, "plan.version_created", plan.id)
        plan
      end
    end

    private

    def normalize(input, creating:)
      attrs = input.to_h.symbolize_keys
      name = attrs[:name].to_s.strip
      price = Integer(attrs[:price_minor], exception: false)
      days = Integer(attrs[:duration_days], exception: false)
      devices = Integer(attrs[:devices], exception: false)
      traffic = Integer(attrs[:traffic_gb], exception: false)
      squad = attrs[:squad_uuid].to_s.strip
      daily = attrs[:autorenew_days_before].presence
      failures = attrs[:autorenew_max_fails].presence
      daily = Integer(daily, exception: false) unless daily.nil?
      failures = Integer(failures, exception: false) unless failures.nil?
      valid_squad = squad.empty? || squad.match?(/\A[0-9a-f-]{36}\z/i)
      valid_devices = creating ? devices&.between?(1, 20) : devices&.between?(0, 20)
      unless name.present? && name.length <= 100 && price&.between?(100, 100_000_000) &&
          days&.between?(1, 3650) && valid_devices && traffic&.between?(0, 100_000) && valid_squad &&
          (daily.nil? || daily.between?(1, 14)) && (failures.nil? || failures.between?(1, 10))
        raise BillingError, "Проверьте параметры тарифа. Цена указывается в копейках."
      end
      { name: name, price_minor: price, duration_days: days, devices: devices,
        traffic_bytes: traffic * 1.gigabyte, squad_uuid: squad,
        autorenew_days_before: daily, autorenew_max_fails: failures,
        active: ActiveModel::Type::Boolean.new.cast(attrs.fetch(:active, true)) }
    end

    def create_version(plan)
      number = PlanVersion.where(plan_id: plan.id).maximum(:version_number).to_i + 1
      PlanVersion.where(plan_id: plan.id, retired_at: nil).update_all(retired_at: Time.now.to_i)
      PlanVersion.create!(id: Infrastructure::IdGenerator.call, plan_id: plan.id,
        version_number: number, name: plan.name, price_minor: plan.price_minor,
        currency: plan.currency, duration_days: plan.duration_days, duration_months: plan.duration_months.to_i,
        entitlements_json: JSON.generate(vpn_access: true, traffic_bytes: plan.traffic_bytes, devices: plan.devices),
        created_at: Time.now.to_i)
    end

    def audit(actor, action, subject)
      AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor,
        action: action, subject: subject, created_at: Time.now.to_i)
    end
  end
end
