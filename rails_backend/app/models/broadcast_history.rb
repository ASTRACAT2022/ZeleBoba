class BroadcastHistory < ApplicationRecord
  self.table_name = "broadcast_history"
  string_primary_key
  has_many :broadcast_deliveries, foreign_key: :broadcast_id
end
