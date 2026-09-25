class CircuitBreakerState < ApplicationRecord
  self.table_name = "circuit_breakers"
  self.primary_key = "name"
end
