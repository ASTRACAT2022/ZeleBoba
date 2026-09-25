class Compensation < ApplicationRecord
  string_primary_key
  has_many :compensation_targets, foreign_key: :compensation_id
  has_many :compensation_grants, foreign_key: :compensation_id
end
