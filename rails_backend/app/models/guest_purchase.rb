class GuestPurchase < ApplicationRecord
  self.table_name = "guest_purchases"
  string_primary_key

  belongs_to :buyer, class_name: "User", foreign_key: :buyer_user_id, optional: true
  belongs_to :user, optional: true
  belongs_to :plan, optional: true

  scope :gifts, -> { where(is_gift: 1) }
end
