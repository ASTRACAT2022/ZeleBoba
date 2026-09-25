module Infrastructure
  class JobDeferred < StandardError
    attr_reader :delay_seconds

    def initialize(delay_seconds = 60)
      @delay_seconds = [delay_seconds.to_i, 1].max
      super("Job deferred by an operational control")
    end
  end
end
