class PollQuestion < ApplicationRecord
  string_primary_key
  belongs_to :poll
end
