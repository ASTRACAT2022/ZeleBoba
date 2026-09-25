class TelegramUpdate < ApplicationRecord
  self.table_name = "telegram_updates"
  self.primary_key = "update_id"
end
