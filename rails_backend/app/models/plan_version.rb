class PlanVersion < ApplicationRecord
  string_primary_key

  self.table_name = "plan_versions"
end
