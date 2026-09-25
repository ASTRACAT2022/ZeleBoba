module Payments
  module Amounts
    module_function

    def decimal(minor)
      "#{minor.to_i / 100}.#{(minor.to_i % 100).to_s.rjust(2, "0")}"
    end

    def normalize(value)
      amount = value.to_s.strip
      match = /\A(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/.match(amount)
      raise Billing::BillingError, "Некорректная сумма." unless match

      "#{match[1]}.#{(match[2] || "").ljust(2, "0")}"
    end

    def minor(decimal)
      match = /\A(0|[1-9][0-9]{0,9})\.([0-9]{2})\z/.match(decimal.to_s)
      raise Billing::BillingError, "Некорректная сумма." unless match

      match[1].to_i * 100 + match[2].to_i
    end
  end
end
