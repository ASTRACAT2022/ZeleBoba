class Creator < ApplicationRecord
  string_primary_key
  belongs_to :user, optional: true
  has_many :creator_attributions
  has_many :creator_commissions
  has_many :creator_payouts
end
