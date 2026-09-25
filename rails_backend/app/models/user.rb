class User < ApplicationRecord
  string_primary_key

  has_many :orders
  has_many :topups
  has_many :subscriptions
  has_many :transactions, class_name: "TransactionRecord"
  has_many :user_identities
  has_many :sessions
end
