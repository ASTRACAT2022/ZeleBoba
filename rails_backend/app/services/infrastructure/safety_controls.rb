module Infrastructure
  class SafetyControls
    def assert_can_purchase!(provider = nil)
      settings = AppSetting.where(name: ["SAFE_MODE", "GLOBAL_SAFETY_MODE", "PURCHASES_ENABLED", "MAINTENANCE_MODE"]).pluck(:name, :value).to_h
      purchases_enabled = settings.fetch("PURCHASES_ENABLED") { RuntimeConfig.fetch("PURCHASES_ENABLED", "0") }
      raise Billing::BillingError, "Покупки временно приостановлены." if settings["SAFE_MODE"] == "1" || settings["GLOBAL_SAFETY_MODE"] == "1" || settings["MAINTENANCE_MODE"] == "1" || purchases_enabled != "1"
      raise Billing::BillingError, "Покупки временно отключены." if enabled?("global_purchases")
      raise Billing::BillingError, "Оплата через этот способ временно недоступна." if provider.present? && enabled?("provider.#{provider}")
    end

    def assert_can_provision!
      settings = AppSetting.where(name: %w[SAFE_MODE GLOBAL_SAFETY_MODE]).pluck(:name, :value).to_h
      raise JobDeferred, 60 if settings["SAFE_MODE"] == "1" || settings["GLOBAL_SAFETY_MODE"] == "1"
      raise JobDeferred, 60 if enabled?("remnawave_provision") || enabled?("global_purchases")
      window = ServiceMaintenanceWindow.where(service: "remnawave").where("starts_at <= ? AND ends_at > ?", Time.now.to_i, Time.now.to_i).order(ends_at: :desc).first
      raise JobDeferred, [window.ends_at.to_i - Time.now.to_i + 5, 30].max if window
    end

    private

    def enabled?(name)
      KillSwitch.where(name: name).pick(:enabled).to_i == 1
    end
  end
end
