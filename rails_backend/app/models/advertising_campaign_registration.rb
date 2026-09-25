class AdvertisingCampaignRegistration < ApplicationRecord
  string_primary_key
  belongs_to :campaign, class_name: "AdvertisingCampaign", foreign_key: :campaign_id
  belongs_to :user
end
