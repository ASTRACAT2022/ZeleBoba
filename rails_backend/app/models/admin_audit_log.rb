class AdminAuditLog < ApplicationRecord
  self.table_name = "admin_audit_log"
  string_primary_key
end
