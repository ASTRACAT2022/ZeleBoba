# frozen_string_literal: true

module Zeleboba
  module Infrastructure
    class JobDeferred < StandardError
      attr_reader :delay

      def initialize(message = "Job deferred", delay: 60)
        super(message)
        @delay = Integer(delay).clamp(1, 86_400)
      end
    end

    class JobPermanentFailure < StandardError; end
  end
end
