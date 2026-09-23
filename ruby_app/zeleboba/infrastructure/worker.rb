# frozen_string_literal: true

require_relative "job_error"

module Zeleboba
  module Infrastructure
    # Thin, explicit dispatcher. Handlers are injected rather than reaching
    # into global state, which makes retry behaviour deterministic in tests.
    class Worker
      def initialize(outbox, handlers)
        @outbox = outbox
        @handlers = handlers.transform_keys(&:to_s).freeze
      end

      def run_once
        @outbox.run_one do |topic, payload|
          handler = @handlers[topic]
          raise JobPermanentFailure, "Unknown outbox topic: #{topic}" unless handler

          handler.call(payload)
        end
      end

      def run_until_idle(limit: 100)
        Integer(limit).times.take_while { run_once }.count
      end
    end
  end
end
