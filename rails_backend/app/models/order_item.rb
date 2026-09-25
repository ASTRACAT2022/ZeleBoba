class OrderItem < ApplicationRecord
  string_primary_key

  self.table_name = "order_items"
end
