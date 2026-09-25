class AuditEvent < ApplicationRecord
  string_primary_key
  self.table_name = "audit_events"
end
