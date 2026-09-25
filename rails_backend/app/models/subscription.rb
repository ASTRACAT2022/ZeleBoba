class Subscription < ApplicationRecord
  string_primary_key

  belongs_to :user
  belongs_to :order, optional: true
end
