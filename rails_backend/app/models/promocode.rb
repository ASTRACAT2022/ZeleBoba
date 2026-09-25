class Promocode < ApplicationRecord
  string_primary_key
  has_many :promocode_uses
end
