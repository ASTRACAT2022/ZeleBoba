class OperationRetry < ApplicationRecord
  string_primary_key

  self.table_name = "operation_retries"
end
