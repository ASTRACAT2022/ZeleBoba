module Billing
  module SubscriptionTerms
    module_function

    def expiry_after(base_time, duration_days, duration_months = 0)
      base = Time.at(base_time.to_i).utc
      if duration_months.to_i.positive?
        (base.to_date >> duration_months.to_i).to_time.to_i
      else
        base.to_i + duration_days.to_i.days.to_i
      end
    end
  end
end
