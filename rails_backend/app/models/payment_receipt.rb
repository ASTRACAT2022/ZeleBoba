class PaymentReceipt < ApplicationRecord
  self.table_name = "payment_receipts"
  self.primary_key = %i[provider payment_id]
end
