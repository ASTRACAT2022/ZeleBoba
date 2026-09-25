class Plan < ApplicationRecord
  string_primary_key

  scope :active, -> { where(active: 1) }
end
