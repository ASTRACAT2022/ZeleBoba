class AuditLog < ApplicationRecord
  string_primary_key

  self.table_name = "audit_log"
end
