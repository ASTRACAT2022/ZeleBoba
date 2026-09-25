class ContestAttempt < ApplicationRecord
  string_primary_key
  belongs_to :round, class_name: "ContestRound", foreign_key: :round_id
  belongs_to :user
end
