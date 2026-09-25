class Topup < ApplicationRecord
  string_primary_key

  belongs_to :user

  def pending?
    status == "pending"
  end
end
