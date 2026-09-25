class Operation < ApplicationRecord
  string_primary_key

  has_many :operation_events
  belongs_to :user, optional: true
  belongs_to :subscription, optional: true
  belongs_to :order, optional: true
  belongs_to :payment, optional: true
  has_many :operation_steps
  has_many :operation_retries
end
