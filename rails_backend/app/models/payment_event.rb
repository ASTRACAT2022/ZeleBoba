class PaymentEvent < ApplicationRecord
  string_primary_key

  self.table_name = "payment_events"
end
