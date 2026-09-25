class UserIdentity < ApplicationRecord
  self.table_name = "user_identities"
  self.inheritance_column = :_type_disabled
  belongs_to :user
end
