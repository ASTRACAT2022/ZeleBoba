class Session < ApplicationRecord
  self.table_name = "sessions"
  belongs_to :user
end
