class CreatorAttribution < ApplicationRecord
  string_primary_key
  belongs_to :creator
  belongs_to :user, optional: true
end
