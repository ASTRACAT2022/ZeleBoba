require "uri/mailto"

class EmailQueueItem < ApplicationRecord
  MAX_ATTEMPTS = 5

  belongs_to :user, optional: true

  validates :to_email, presence: true, length: { maximum: 254 },
    format: { with: URI::MailTo::EMAIL_REGEXP }
  validates :subject, presence: true, length: { maximum: 255 },
    format: { without: /[\r\n]/ }
  validates :body, presence: true
  validates :status, inclusion: { in: %w[pending sent failed] }
  validates :attempts, numericality: { only_integer: true, greater_than_or_equal_to: 0 }
end
