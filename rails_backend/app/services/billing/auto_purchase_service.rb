module Billing
  class AutoPurchaseService
    def initialize(carts: CartService.new, orders: OrderService.new, settlement: SettlementService.new,
      wallet: WalletService.new, gifts: GiftService.new, addons: AddonPurchaseService.new,
      outbox: Infrastructure::OutboxService.new)
      @carts, @orders, @settlement, @wallet = carts, orders, settlement, wallet
      @gifts, @addons, @outbox = gifts, addons, outbox
    end

    # Runs inside the durable topup.after outbox claim. The user and cart locks
    # serialize duplicate topup notifications; purchase rows and wallet ledger
    # changes commit atomically with clearing the cart intent.
    def after_topup(user_id)
      ApplicationRecord.transaction do
        User.lock.find_by(id: user_id)
        Cart.lock.find_by(user_id: user_id)
        process_cart(user_id)
      end
    end

    private

    def process_cart(user_id)
      cart = @carts.get(user_id)
      return unless cart && cart["_intent"]

      @carts.clear_intent(user_id)
      begin
        ApplicationRecord.transaction(requires_new: true) do
          case cart["kind"] || "subscription"
          when "subscription"
            assert_purchases_allowed!
            purchase_subscription(user_id, cart)
          when "gift"
            assert_purchases_allowed!
            purchase_gift(user_id, cart)
          when "traffic"
            assert_purchases_allowed!
            purchase_traffic(user_id, cart)
          when "devices"
            assert_purchases_allowed!
            purchase_devices(user_id, cart)
          end
        end
      rescue BillingError => error
        @carts.save(user_id, cart, intent: true)
        notify_purchase_failure(user_id, error)
      end
    end

    def purchase_subscription(user_id, cart)
      plan_id = cart["plan_id"].to_s
      idempotency_key = cart["idempotency_key"].presence || "cart:#{user_id}:#{plan_id}"
      order = @orders.create(user_id: user_id, plan_id: plan_id, idempotency_key: idempotency_key, balance_purchase: true)

      if %w[paid fulfilled].include?(order.status) && order.provider_payment_id == "balance_#{order.id}"
        @carts.delete(user_id)
        return
      end
      raise BillingError, "Заказ уже передан на оплату." unless order.status == "pending" && order.provider_payment_id.blank?

      @wallet.debit(user_id, order.price_minor.to_i, "subscription_purchase", "Покупка подписки: #{order.plan_name}",
        payment_method: "balance", external_id: order.id)
      @settlement.settle_from_balance(order.id, order.price_minor.to_i, order.currency)
      @carts.delete(user_id)
    end

    def purchase_gift(user_id, cart)
      plan_id = cart["plan_id"].to_s
      key = cart["idempotency_key"].presence || Infrastructure::IdGenerator.call
      @gifts.purchase_from_balance(buyer_id: user_id, plan_id: plan_id, idempotency_key: key,
        recipient_type: cart["recipient_type"], recipient_value: cart["recipient_value"],
        message: cart["message"], source: "bot")
      @carts.delete(user_id)
    end

    def purchase_traffic(user_id, cart)
      @addons.purchase_traffic(user_id: user_id, subscription_id: cart["subscription_id"].to_s,
        gigabytes: cart["traffic_gb"], price_kopeks: cart["price_kopeks"])
      @carts.delete(user_id)
    end

    def purchase_devices(user_id, cart)
      @addons.purchase_devices(user_id: user_id, subscription_id: cart["subscription_id"].to_s,
        devices: cart["devices"], price_kopeks: cart["price_kopeks"])
      @carts.delete(user_id)
    end

    def notify_purchase_failure(user_id, error)
      chat_id = User.where(id: user_id).pick(:telegram_id).to_s
      return if chat_id.blank?

      @outbox.enqueue("telegram.send", "cart-failed:#{user_id}:#{Time.now.to_i}", {
        chat_id: chat_id,
        text: "Не удалось завершить покупку после пополнения: #{error.message} Пополните баланс или повторите покупку в кабинете."
      })
    end

    def assert_purchases_allowed!
      provider = Infrastructure::RuntimeConfig.fetch("PAYMENT_DRIVER", "demo")
      Infrastructure::SafetyControls.new.assert_can_purchase!(provider)
    end
  end
end
