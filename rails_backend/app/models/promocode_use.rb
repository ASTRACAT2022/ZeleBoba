class PromocodeUse < ApplicationRecord
  string_primary_key
  belongs_to :promocode
  belongs_to :user
end
