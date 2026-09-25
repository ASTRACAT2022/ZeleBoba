class OutboxJob < ApplicationRecord
  string_primary_key

  self.table_name = "outbox"
end
