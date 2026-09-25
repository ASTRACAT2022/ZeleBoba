class LoginChallenge < ApplicationRecord
  self.table_name = "login_challenges"
  self.primary_key = "token_hash"
  belongs_to :user, optional: true
end
