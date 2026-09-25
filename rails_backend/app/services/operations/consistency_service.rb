module Operations
  class ConsistencyService
    def run
      now = Time.now.to_i
      missing = rows(<<~SQL)
        SELECT p.id FROM payments p
        LEFT JOIN ledger_entries l ON l.order_id = p.order_id
        LEFT JOIN orders o ON o.id = p.order_id
        WHERE p.status = 'succeeded'
        GROUP BY p.id, o.status
        HAVING COUNT(l.id) <> 2 OR COALESCE(SUM(l.amount_minor), 0) <> 0 OR o.status NOT IN ('paid','fulfilled')
      SQL
      creator_drift = rows(<<~SQL)
        SELECT c.id FROM creator_commissions c
        LEFT JOIN creator_ledger l ON l.commission_id = c.id AND l.entry_type = 'commission' AND l.amount_minor = c.commission_minor
        GROUP BY c.id HAVING COUNT(l.id) <> 1
        UNION
        SELECT l.id FROM creator_ledger l
        LEFT JOIN creator_commissions c ON c.id = l.commission_id
        WHERE l.entry_type = 'commission' AND c.id IS NULL
        UNION
        SELECT c.id FROM creator_commissions c
        LEFT JOIN creator_ledger l ON l.creator_id = c.creator_id AND l.entry_type = 'reversal' AND l.metadata LIKE '%' || c.id || '%'
        WHERE c.status = 'reversed' GROUP BY c.id HAVING COUNT(l.id) <> 1
      SQL
      workflow_cutover = Workflow.minimum(:created_at).to_i
      workflow_drift = workflow_cutover.positive? ? rows(<<~SQL, workflow_cutover) : []
        SELECT o.id FROM orders o
        LEFT JOIN workflows w ON w.workflow_type = 'subscription_fulfillment' AND w.entity_type = 'order' AND w.entity_id = o.id
        WHERE o.status = 'paid' AND o.paid_at IS NOT NULL AND o.created_at >= ? AND w.id IS NULL
      SQL
      provisioning_drift = rows(<<~SQL)
        SELECT p.id FROM provisioning_operations p
        LEFT JOIN subscriptions s ON s.id = p.subscription_id
        WHERE p.status = 'succeeded' AND (p.actual_state IS NULL OR s.id IS NULL)
      SQL
      wallet_ledger_drift = rows(<<~SQL)
        SELECT transaction_id AS id FROM wallet_ledger_entries
        GROUP BY transaction_id HAVING COUNT(id) <> 2 OR COALESCE(SUM(amount_kopeks), 0) <> 0
      SQL
      wallet_balance_drift = rows(<<~SQL)
        SELECT u.id FROM users u
        LEFT JOIN wallet_ledger_entries l ON l.account = 'wallet:user:' || u.id
        GROUP BY u.id, u.balance_kopeks HAVING COALESCE(SUM(l.amount_kopeks), 0) <> u.balance_kopeks
      SQL
      details = {
        critical_payment_drift: missing.map { |row| row.fetch("id") },
        critical_creator_drift: creator_drift.map { |row| row.fetch("id") },
        wallet_ledger_drift: wallet_ledger_drift.map { |row| row.fetch("id") },
        wallet_balance_drift: wallet_balance_drift.map { |row| row.fetch("id") },
        paid_without_workflow: workflow_drift.map { |row| row.fetch("id") },
        provisioning_without_actual_state: provisioning_drift.map { |row| row.fetch("id") }
      }
      count = details.values.sum(&:length)
      status = count.zero? ? "ok" : "critical"
      ConsistencyCheck.create!(id: Infrastructure::IdGenerator.call, kind: "money",
        status: status, details: JSON.generate(details), checked_at: now)
      { status: status, count: count, details: details }
    end

    private

    def rows(sql, *binds)
      statement = ApplicationRecord.sanitize_sql_array([sql, *binds])
      ApplicationRecord.connection.exec_query(statement).to_a
    end
  end
end
