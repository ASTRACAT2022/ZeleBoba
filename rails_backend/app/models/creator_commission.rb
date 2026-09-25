class CreatorCommission < ApplicationRecord
  string_primary_key
  belongs_to :creator
  belongs_to :customer, class_name: "User"
  belongs_to :payment
end
