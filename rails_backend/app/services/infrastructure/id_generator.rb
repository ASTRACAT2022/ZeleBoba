module Infrastructure
  require "securerandom"

  module IdGenerator
    module_function

    def call
      SecureRandom.hex(16)
    end
  end
end
