-- Wallet double-entry ledger.
--
-- `users.balance_kopeks` stays as a fast projection for reads, but every
-- balance-affecting Wallet operation now also writes two balanced entries:
--   wallet:user:<id>        +/- amount
--   wallet:source|sink:<type> opposite amount
--
-- External provider purchases may still create `transactions` rows for
-- analytics without touching the internal wallet, so wallet entries live in a
-- dedicated table rather than overloading `ledger_entries`.
CREATE TABLE IF NOT EXISTS wallet_ledger_entries (
 id VARCHAR(40) PRIMARY KEY,
 transaction_id VARCHAR(32) NOT NULL REFERENCES transactions(id),
 account VARCHAR(180) NOT NULL,
 amount_kopeks BIGINT NOT NULL CHECK(amount_kopeks <> 0),
 currency VARCHAR(3) NOT NULL DEFAULT 'RUB' CHECK(currency = 'RUB'),
 created_at BIGINT NOT NULL,
 UNIQUE(transaction_id, account)
);
CREATE INDEX IF NOT EXISTS wallet_ledger_tx ON wallet_ledger_entries(transaction_id);
CREATE INDEX IF NOT EXISTS wallet_ledger_account ON wallet_ledger_entries(account, created_at);

-- Hard idempotency guarantee for wallet operations that carry a business key
-- (provider payment id, order id, refund id, etc.). NULL/empty external ids
-- remain allowed for purely manual one-off adjustments.
CREATE UNIQUE INDEX IF NOT EXISTS transactions_user_type_external_unique
ON transactions(user_id, type, external_id)
WHERE external_id IS NOT NULL AND external_id <> '';

-- Backfill historical wallet-affecting transactions. Provider-card purchases
-- recorded for analytics use payment_method != 'balance' and are deliberately
-- excluded because they never changed the internal wallet balance.
INSERT INTO wallet_ledger_entries(id, transaction_id, account, amount_kopeks, currency, created_at)
SELECT id || 'u', id, 'wallet:user:' || user_id, amount_kopeks, 'RUB', created_at
FROM transactions
WHERE amount_kopeks > 0 OR payment_method IS NULL OR payment_method = 'balance'
ON CONFLICT(transaction_id, account) DO NOTHING;

INSERT INTO wallet_ledger_entries(id, transaction_id, account, amount_kopeks, currency, created_at)
SELECT id || 'c', id,
       CASE WHEN amount_kopeks > 0 THEN 'wallet:source:' || type ELSE 'wallet:sink:' || type END,
       -amount_kopeks, 'RUB', created_at
FROM transactions
WHERE amount_kopeks > 0 OR payment_method IS NULL OR payment_method = 'balance'
ON CONFLICT(transaction_id, account) DO NOTHING;
