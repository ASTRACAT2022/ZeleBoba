class BroadcastDelivery < ApplicationRecord
  self.primary_key = %i[broadcast_id chat_id]
  belongs_to :broadcast_history, foreign_key: :broadcast_id
end
