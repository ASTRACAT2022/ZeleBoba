class MfaEnrollment < ApplicationRecord
  self.table_name = "mfa_enrollments"
  self.primary_key = "user_id"
  belongs_to :user
end
