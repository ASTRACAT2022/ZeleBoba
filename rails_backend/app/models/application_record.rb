class ApplicationRecord < ActiveRecord::Base
  primary_abstract_class

  self.implicit_order_column = "created_at"

  def self.string_primary_key
    self.primary_key = "id"
  end
end
