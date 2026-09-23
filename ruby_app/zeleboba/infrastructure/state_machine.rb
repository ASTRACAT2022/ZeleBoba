# frozen_string_literal: true

require_relative "../billing/error"

module Zeleboba
  module Infrastructure
    # Keeps money and entitlement state changes explicit and auditable.
    class StateMachine
      TRANSITIONS = {
        "order" => {
          "pending" => %w[paid canceled], "payment_unavailable" => %w[pending canceled],
          "paid" => ["fulfilled"], "fulfilled" => [], "canceled" => []
        },
        "topup" => { "pending" => %w[paid canceled], "paid" => [], "canceled" => [] },
        "payment" => { "pending" => %w[succeeded failed], "succeeded" => [], "failed" => [] },
        "subscription" => {
          "pending" => %w[provisioning active expired cancelled],
          "provisioning" => %w[active expired cancelled], "active" => %w[grace expired cancelled],
          "grace" => %w[active expired cancelled], "trial" => %w[active expired cancelled],
          "expired" => [], "cancelled" => []
        }
      }.freeze

      def self.allowed(entity, from)
        TRANSITIONS.fetch(entity.to_s) { raise ArgumentError, "Unknown state-machine entity: #{entity}" }.fetch(from.to_s, [])
      end

      def self.assert!(entity, from, to)
        return if allowed(entity, from).include?(to.to_s)

        allowed_states = allowed(entity, from)
        suffix = allowed_states.empty? ? "терминальное состояние" : "допустимо: #{allowed_states.join(', ')}"
        raise Billing::Error, "Недопустимый переход статуса: #{entity} #{from} -> #{to} (#{suffix})."
      end
    end
  end
end
