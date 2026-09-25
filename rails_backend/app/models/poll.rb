class Poll < ApplicationRecord
  string_primary_key
  belongs_to :creator, class_name: "User", foreign_key: :created_by, optional: true
  has_many :questions, class_name: "PollQuestion", foreign_key: :poll_id
  has_many :responses, class_name: "PollResponse", foreign_key: :poll_id
end
