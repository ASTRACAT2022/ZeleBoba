module Billing
  # Purchase a plan from the internal wallet using the same immutable order,
  # payment receipt, ledger and subscription fulfillment path as external payers.
  class WalletPurchaseService
    def purchase(user_id:, plan_id:, idempotency_key:, renew_subscription_id: nil)
      ApplicationRecord.transaction do
        order = OrderService.new.create(user_id: user_id, plan_id: plan_id,
          idempotency_key: idempotency_key, renew_subscription_id: renew_subscription_id,
          balance_purchase: true)
        order = Order.lock.find(order.id)
        if %w[paid fulfilled].include?(order.status) && order.provider_payment_id == "balance_#{order.id}"
          next order
        end
        raise BillingError, "Заказ уже передан на оплату." unless order.status == "pending" && order.provider_payment_id.blank?

        WalletService.new.debit(user_id, order.price_minor.to_i, "subscription_purchase",
          "Покупка подписки: #{order.plan_name}", payment_method: "balance", external_id: order.id)
        SettlementService.new.settle_from_balance(order.id, order.price_minor.to_i, order.currency)
        Order.find(order.id)
      end
    end
  end
end
