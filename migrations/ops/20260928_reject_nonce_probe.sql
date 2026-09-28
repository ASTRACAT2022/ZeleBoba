-- 2026-09-28: DB-level guard against the external FreeKassa nonce-probe.
-- The probe writes raw INSERTs into orders (idempotency noncechk_*, plan_name
-- 'Nonce Check', client_ip 8.8.8.8) bypassing the web app, so an app-level
-- guard alone cannot stop it. Applied to prod as billing_owner on 2026-09-28.
CREATE OR REPLACE FUNCTION reject_nonce_probe_order() RETURNS trigger AS $$
BEGIN
  IF NEW.idempotency_key LIKE 'noncechk_%'
     OR (NEW.plan_name = 'Nonce Check' AND NEW.client_ip = '8.8.8.8') THEN
    RAISE EXCEPTION 'rejected: external nonce probe order';
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_reject_nonce_probe ON orders;
CREATE TRIGGER trg_reject_nonce_probe
  BEFORE INSERT ON orders
  FOR EACH ROW EXECUTE FUNCTION reject_nonce_probe_order();
