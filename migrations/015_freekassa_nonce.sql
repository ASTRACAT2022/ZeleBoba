-- FreeKassa requires strictly increasing nonces. Wall-clock based nonces
-- (microtime) are NOT safe: if the host clock is ever slightly ahead (NTP
-- correction, container with a faster clock, FreeKassa-side skew), the next
-- call produces a nonce *backwards* relative to what FreeKassa already
-- recorded -> "Request with same (or bigger) nonce already exist".
--
-- The reliable fix is a PostgreSQL SEQUENCE: strictly monotonic, atomic across
-- all worker processes, survives restarts. Seeded above the highest nonce
-- Django ever sent (~9.2e18) so it is always larger than anything FreeKassa
-- has already recorded.
-- [PG]
CREATE SEQUENCE IF NOT EXISTS freekassa_nonce_seq START 9200000000000004000;
SELECT setval('freekassa_nonce_seq', GREATEST(last_value, 9200000000000004000::bigint), true) FROM freekassa_nonce_seq;
GRANT USAGE, SELECT ON SEQUENCE freekassa_nonce_seq TO billing;
-- [/PG]
