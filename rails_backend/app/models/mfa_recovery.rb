class MfaRecovery < ApplicationRecord
  self.table_name = "mfa_recovery"
  self.primary_key = "code_hash"
  belongs_to :user
end
