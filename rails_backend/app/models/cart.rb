class Cart < ApplicationRecord
  self.table_name = "carts"
  self.primary_key = "user_id"

  belongs_to :user
end
