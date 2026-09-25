class WalletLedgerEntry < ApplicationRecord
  string_primary_key

  self.table_name = "wallet_ledger_entries"
end
