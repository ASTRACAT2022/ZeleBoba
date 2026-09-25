module Infrastructure
  module Locks
    module_function

    def provider_payment!(provider, payment_id)
      advisory!("#{provider}:#{payment_id}")
    end

    def advisory!(key)
      return unless ApplicationRecord.connection.adapter_name.downcase.include?("postgres")

      quoted = ApplicationRecord.connection.quote(key)
      ApplicationRecord.connection.execute("SELECT pg_advisory_xact_lock(hashtextextended(#{quoted}, 0))")
    end

    def transaction_sequence!
      return unless ApplicationRecord.connection.adapter_name.downcase.include?("postgres")

      ApplicationRecord.connection.execute("SELECT pg_advisory_xact_lock(hashtextextended('zeleboba:transactions:seq', 0))")
    end
  end
end
