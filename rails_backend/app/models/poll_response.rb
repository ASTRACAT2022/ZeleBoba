class PollResponse < ApplicationRecord
  string_primary_key
  belongs_to :poll
  belongs_to :user
end
