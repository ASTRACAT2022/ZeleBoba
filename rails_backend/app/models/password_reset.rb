class PasswordReset < ApplicationRecord
  self.table_name = "password_resets"
  belongs_to :user
end
