class CompensationTarget < ApplicationRecord
  self.primary_key = %i[compensation_id user_id]
  belongs_to :compensation
  belongs_to :user
end
