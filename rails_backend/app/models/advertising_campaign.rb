class AdvertisingCampaign < ApplicationRecord
  string_primary_key
  belongs_to :partner, class_name: "User", foreign_key: :partner_user_id, optional: true
  belongs_to :creator, class_name: "User", foreign_key: :created_by, optional: true
  belongs_to :plan, optional: true
  has_many :registrations, class_name: "AdvertisingCampaignRegistration", foreign_key: :campaign_id
end
