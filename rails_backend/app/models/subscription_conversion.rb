class SubscriptionConversion < ApplicationRecord
  string_primary_key
  belongs_to :user
end
