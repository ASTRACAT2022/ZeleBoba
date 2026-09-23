# frozen_string_literal: true

require_relative "error"

module Zeleboba
  module Billing
    class AutoPurchaseService
      def initialize(db, carts, billing, gifts, wallet, outbox)
        @db = db
        @carts = carts
        @billing = billing
        @gifts = gifts
        @wallet = wallet
        @outbox = outbox
      end

      def after_topup(user_id)
        cart = nil
        @db.transaction do
          cart = @carts.fetch(user_id)
          return false unless cart && cart["_intent"]

          # Clear first. On failure the exact cart is restored, which prevents
          # duplicate completions when a worker is retried after a crash.
          @carts.clear_intent(user_id)
          process(user_id, cart)
          @carts.delete(user_id)
          true
        rescue Error => e
          @carts.save(user_id, cart.reject { |key, _| key == "_intent" }, intent: true) if cart
          notify_failure(user_id, e.message)
          false
        end
      end

      private

      def process(user_id, cart)
        case cart.fetch("kind", "subscription")
        when "subscription"
          @billing.purchase_from_balance(user_id, cart.fetch("plan_id"), key(cart, user_id))
        when "gift"
          @gifts.purchase_from_balance(user_id, cart.fetch("plan_id"), key(cart, user_id), recipient_type: cart["recipient_type"], recipient_value: cart["recipient_value"], message: cart["message"])
        when "traffic"
          purchase_traffic(user_id, cart)
        when "devices"
          purchase_devices(user_id, cart)
        else
          raise Error, "Неизвестный тип отложенной покупки."
        end
      end

      def purchase_traffic(user_id, cart)
        gb = Integer(cart.fetch("traffic_gb"))
        price = Integer(cart.fetch("price_kopeks"))
        raise Error, "Некорректный пакет трафика." unless gb.positive? && price.positive?
        subscription = active_subscription(user_id, cart.fetch("subscription_id"))
        @wallet.debit(user_id, price, "traffic_topup", "Докупка трафика: #{gb} ГБ", "balance", key(cart, user_id))
        @db.execute("UPDATE subscriptions SET purchased_traffic_gb=purchased_traffic_gb+?,updated_at=? WHERE id=?", [gb, Time.now.to_i, subscription["id"]])
        @outbox.enqueue("subscription.traffic", "traffic:#{subscription["id"]}:#{key(cart, user_id)}", { "subscription_id" => subscription["id"], "traffic_gb" => gb })
      end

      def purchase_devices(user_id, cart)
        count = Integer(cart.fetch("devices"))
        price = Integer(cart.fetch("price_kopeks"))
        raise Error, "Некорректное количество устройств." unless count.positive? && price.positive?
        subscription = active_subscription(user_id, cart.fetch("subscription_id"))
        @wallet.debit(user_id, price, "device_addon", "Докупка устройств: +#{count}", "balance", key(cart, user_id))
        @db.execute("UPDATE subscriptions SET device_limit=device_limit+?,updated_at=? WHERE id=?", [count, Time.now.to_i, subscription["id"]])
        @outbox.enqueue("subscription.devices", "devices:#{subscription["id"]}:#{key(cart, user_id)}", { "subscription_id" => subscription["id"], "devices" => count })
      end

      def active_subscription(user_id, subscription_id)
        @db.one("SELECT * FROM subscriptions WHERE id=? AND user_id=? AND status='active'#{@db.lock}", [subscription_id, user_id]) || raise(Error, "Подписка не найдена.")
      end

      def key(cart, user_id)
        value = cart["idempotency_key"].to_s
        raise Error, "Некорректный ключ операции." unless value.match?(/\A[a-zA-Z0-9:_-]{8,128}\z/)

        value
      end

      def notify_failure(user_id, message)
        telegram_id = @db.one("SELECT telegram_id FROM users WHERE id=?", [user_id])&.fetch("telegram_id", nil)
        return if telegram_id.to_s.empty?

        @outbox.enqueue("telegram.send", "cart-failed:#{user_id}:#{Time.now.to_i}", { "chat_id" => telegram_id, "text" => "Не удалось завершить покупку после пополнения: #{message}" })
      end
    end
  end
end
