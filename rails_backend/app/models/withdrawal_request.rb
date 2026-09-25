class WithdrawalRequest < ApplicationRecord
  string_primary_key
  belongs_to :user
  belongs_to :processor, class_name: "User", foreign_key: :processed_by, optional: true
end
