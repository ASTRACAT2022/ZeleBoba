class PaymentAttempt < ApplicationRecord
  string_primary_key

  self.table_name = "payment_attempts"
end
