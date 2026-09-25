class UserChannelSubscription < ApplicationRecord
  self.table_name = "user_channel_subscriptions"
  string_primary_key

  belongs_to :user
end
