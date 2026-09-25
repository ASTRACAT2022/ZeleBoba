module Billing
  class AddonPurchaseService
    def initialize(wallet: WalletService.new, outbox: Infrastructure::OutboxService.new)
      @wallet, @outbox = wallet, outbox
    end

    def purchase_traffic(user_id:, subscription_id:, gigabytes:, price_kopeks:)
      gigabytes = gigabytes.to_i
      price_kopeks = price_kopeks.to_i
      raise BillingError, "Некорректный пакет трафика." unless gigabytes.positive? && price_kopeks.positive?

      ApplicationRecord.transaction do
        raise BillingError, "Аккаунт не найден." unless User.lock.exists?(id: user_id)
        sub = Subscription.lock.find_by(id: subscription_id, user_id: user_id, status: "active")
        raise BillingError, "Подписка не найдена." unless sub

        @wallet.debit(user_id, price_kopeks, "traffic_topup", "Докупка трафика: #{gigabytes} ГБ")
        sub.update!(purchased_traffic_gb: sub.purchased_traffic_gb.to_i + gigabytes)
        @outbox.enqueue("subscription.traffic", "traffic:#{subscription_id}:#{Infrastructure::IdGenerator.call}",
          { subscription_id: subscription_id, traffic_gb: gigabytes })
      end
    end

    def purchase_devices(user_id:, subscription_id:, devices:, price_kopeks:)
      devices = devices.to_i
      price_kopeks = price_kopeks.to_i
      raise BillingError, "Некорректное количество устройств." unless devices.positive? && price_kopeks.positive?

      ApplicationRecord.transaction do
        raise BillingError, "Аккаунт не найден." unless User.lock.exists?(id: user_id)
        sub = Subscription.lock.find_by(id: subscription_id, user_id: user_id, status: "active")
        raise BillingError, "Подписка не найдена." unless sub

        @wallet.debit(user_id, price_kopeks, "device_addon", "Докупка устройств: +#{devices}")
        sub.update!(device_limit: sub.device_limit.to_i + devices)
        @outbox.enqueue("subscription.devices", "devices:#{subscription_id}:#{Infrastructure::IdGenerator.call}",
          { subscription_id: subscription_id, devices: devices })
      end
    end
  end
end
