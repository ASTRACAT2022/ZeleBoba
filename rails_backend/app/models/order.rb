class Order < ApplicationRecord
  string_primary_key

  belongs_to :user
  belongs_to :plan, optional: true
  has_many :payments

  def pending?
    status == "pending"
  end
end
