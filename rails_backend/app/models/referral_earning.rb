class ReferralEarning < ApplicationRecord
  string_primary_key
  belongs_to :user
  belongs_to :referral, class_name: "User"
end
