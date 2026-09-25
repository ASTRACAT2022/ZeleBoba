class TransactionRecord < ApplicationRecord
  string_primary_key

  self.table_name = "transactions"
  self.inheritance_column = :_type_disabled
  belongs_to :user
end
