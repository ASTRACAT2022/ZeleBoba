class OperationEvent < ApplicationRecord
  string_primary_key

  belongs_to :operation
end
