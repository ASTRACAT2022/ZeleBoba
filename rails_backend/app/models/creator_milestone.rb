class CreatorMilestone < ApplicationRecord
  string_primary_key
  has_many :creator_milestone_awards, foreign_key: :milestone_id
end
