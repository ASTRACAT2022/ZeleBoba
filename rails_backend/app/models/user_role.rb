class UserRole < ApplicationRecord
  self.table_name = "user_roles"
  string_primary_key
  belongs_to :user
  belongs_to :admin_role, foreign_key: :role_id
end
