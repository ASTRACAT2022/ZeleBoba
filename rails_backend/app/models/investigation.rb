class Investigation < ApplicationRecord
  string_primary_key

  has_many :investigation_notes, dependent: :restrict_with_error
end
