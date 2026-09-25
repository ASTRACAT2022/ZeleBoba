class TelegramLink < ApplicationRecord
  self.table_name = "telegram_links"
  self.primary_key = "token_hash"

  belongs_to :user
end
