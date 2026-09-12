# Backup and restore rehearsal

1. Take a PostgreSQL dump with the database owner role and record the source
   commit, schema version, checksum and timestamp.
2. Create an isolated empty PostgreSQL instance; restore using
   `pg_restore --exit-on-error`. Never rehearse over the active production DB.
3. Deploy an isolated Laravel instance with workers disabled and the original
   application secrets required to read encrypted data.
4. Run schema/cutover compatibility checks, consistency checks, read-only login
   smoke tests and timeline reads. Do not permit the restored worker to call
   live payment, Telegram or provisioning providers.
5. Compare table counts and financial invariants (orders, receipts, ledger,
   topups, subscriptions, audit and timeline) against the dump manifest.
6. Capture elapsed restore time and the results in the release artifact. Only a
   successful isolated rehearsal may produce `restore.json`.

No production-copy restore rehearsal has been provided for this repository;
the current readiness status remains NOT READY.
