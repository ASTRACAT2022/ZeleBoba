class InvestigationNote < ApplicationRecord
  string_primary_key

  belongs_to :investigation
end
