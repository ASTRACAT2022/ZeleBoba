class CreatorMilestoneAward < ApplicationRecord
  string_primary_key
  belongs_to :creator
  belongs_to :milestone, class_name: "CreatorMilestone"
  belongs_to :ledger_entry, class_name: "CreatorLedger", foreign_key: :ledger_id
end
