class ProvisioningAccount < ApplicationRecord
  string_primary_key

  belongs_to :subscription
end
