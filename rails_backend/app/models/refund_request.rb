class RefundRequest < ApplicationRecord
  string_primary_key
  self.table_name = "refund_requests"
  belongs_to :payment
  belongs_to :order
  belongs_to :user
end
