class CreatorLedger < ApplicationRecord
  self.table_name = "creator_ledger"
  string_primary_key
  belongs_to :creator
  belongs_to :commission, class_name: "CreatorCommission", foreign_key: :commission_id, optional: true
end
