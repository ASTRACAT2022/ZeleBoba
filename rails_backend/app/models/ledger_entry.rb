class LedgerEntry < ApplicationRecord
  string_primary_key

  self.table_name = "ledger_entries"
end
