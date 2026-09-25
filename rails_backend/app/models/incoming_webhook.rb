class IncomingWebhook < ApplicationRecord
  string_primary_key

  self.table_name = "incoming_webhooks"
end
