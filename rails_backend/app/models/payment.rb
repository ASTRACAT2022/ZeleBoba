class Payment < ApplicationRecord
  string_primary_key

  belongs_to :order, optional: true
  belongs_to :user
  has_one :creator_commission
end
