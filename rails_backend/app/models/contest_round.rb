class ContestRound < ApplicationRecord
  string_primary_key
  belongs_to :template, class_name: "ContestTemplate", foreign_key: :template_id
  has_many :attempts, class_name: "ContestAttempt", foreign_key: :round_id
end
