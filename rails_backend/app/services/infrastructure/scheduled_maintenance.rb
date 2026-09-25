module Infrastructure
  # Mail and temporary-token cleanup are scheduler duties, not outbox duties.
  # The shared `reconcile` lease also coordinates with the PHP scheduler.
  class ScheduledMaintenance
    LEASE_NAME = "reconcile"
    LEASE_SECONDS = 300
    MAIL_BATCH_SIZE = 3

    def run(now: Time.now.to_i)
      return false unless ENV["RAILS_SCHEDULER_ENABLED"] == "1"

      token = IdGenerator.call
      return false unless acquire_lease(token, now)

      begin
        errors = []
        run_task("mail", errors) do
          mailer = Integrations::Mailer.new
          mailer.flush_queue(limit: MAIL_BATCH_SIZE) if mailer.enabled?
        end
        run_task("billing_reconciliation", errors) { BillingReconciliationService.new.run(now: now) }
        run_task("financial_consistency", errors) { Operations::ConsistencyService.new.run }
        run_task("cleanup", errors) { cleanup_expired(now) }
        raise errors.first if errors.any?
        true
      ensure
        release_lease(token)
      end
    end

    private

    def run_task(name, errors)
      yield
    rescue StandardError => error
      Rails.logger.error("rails.scheduler.#{name}.failed #{error.class.name}")
      errors << error
    end

    def acquire_lease(token, now)
      connection = ApplicationRecord.connection
      connection.execute(
        "INSERT INTO advisory_leases(name,token,expires_at) VALUES(#{connection.quote(LEASE_NAME)},#{connection.quote(token)},0) ON CONFLICT(name) DO NOTHING"
      )
      connection.update(
        "UPDATE advisory_leases SET token=#{connection.quote(token)},expires_at=#{now + LEASE_SECONDS} " \
        "WHERE name=#{connection.quote(LEASE_NAME)} AND expires_at <= #{now}"
      ) == 1
    end

    def release_lease(token)
      connection = ApplicationRecord.connection
      connection.delete(
        "DELETE FROM advisory_leases WHERE name=#{connection.quote(LEASE_NAME)} AND token=#{connection.quote(token)}"
      )
    end

    def cleanup_expired(now)
      Session.where("expires_at <= ?", now).delete_all
      TelegramLink.where("expires_at <= ?", now).delete_all
      LoginChallenge.where("expires_at <= ?", now).delete_all
      MfaEnrollment.where("expires_at <= ?", now).delete_all
      PasswordReset.where("expires_at <= ?", now).delete_all
      OutboxJob.where(status: "done").where("created_at < ?", now - 30 * 86_400).delete_all
      TelegramUpdate.where("created_at < ?", now - 7 * 86_400).delete_all
      connection = ApplicationRecord.connection
      connection.delete("DELETE FROM rate_limits WHERE expires_at <= #{now}")
    end
  end
end
