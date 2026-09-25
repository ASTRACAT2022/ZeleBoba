class CompensationGrant < ApplicationRecord
  string_primary_key
  belongs_to :compensation
  belongs_to :user
end
