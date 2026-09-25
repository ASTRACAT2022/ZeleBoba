class KillSwitch < ApplicationRecord
  self.table_name = "kill_switches"
  self.primary_key = "name"
end
