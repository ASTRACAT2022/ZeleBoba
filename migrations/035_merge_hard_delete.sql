-- [PG]
-- 035_merge_hard_delete.sql
-- Subscription merge now HARD-deletes the source subscription instead of
-- soft-cancelling it. Repoint the FK on subscriptions so a DELETE works:
--   * runtime/provisioning rows  -> CASCADE  (no financial value, safe to drop)
--   * financial/history rows      -> SET NULL (audit preserved, link cleared)
-- All dependent subscription_id columns are already nullable except the two
-- provisioning tables, which must keep a row-lifetime link to the sub.

ALTER TABLE operation_events
    DROP CONSTRAINT operation_events_subscription_id_fkey,
    ADD CONSTRAINT operation_events_subscription_id_fkey
        FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL;

ALTER TABLE operations
    DROP CONSTRAINT operations_subscription_id_fkey,
    ADD CONSTRAINT operations_subscription_id_fkey
        FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL;

ALTER TABLE order_items
    DROP CONSTRAINT order_items_subscription_id_fkey,
    ADD CONSTRAINT order_items_subscription_id_fkey
        FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL;

ALTER TABLE orders
    DROP CONSTRAINT orders_renewal_subscription_id_fkey,
    ADD CONSTRAINT orders_renewal_subscription_id_fkey
        FOREIGN KEY (renewal_subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL;

ALTER TABLE provisioning_accounts
    DROP CONSTRAINT provisioning_accounts_subscription_id_fkey,
    ADD CONSTRAINT provisioning_accounts_subscription_id_fkey
        FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE;

ALTER TABLE provisioning_operations
    DROP CONSTRAINT provisioning_operations_subscription_id_fkey,
    ADD CONSTRAINT provisioning_operations_subscription_id_fkey
        FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE;
-- [/PG]
