class ContestTemplate < ApplicationRecord
  string_primary_key
  has_many :rounds, class_name: "ContestRound", foreign_key: :template_id
end
