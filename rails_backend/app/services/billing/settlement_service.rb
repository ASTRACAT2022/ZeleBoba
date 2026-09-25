module Billing
  class SettlementService
    def initialize(outbox: Infrastructure::OutboxService.new)
      @outbox = outbox
    end

    def settle_order(order_id, provider, payment_id, amount_minor, currency, correlation_id: nil)
      raise BillingError, "Некорректный платёж." if payment_id.blank? || payment_id.length > 100 || amount_minor.to_i <= 0

      ApplicationRecord.transaction do
        Infrastructure::Locks.provider_payment!(provider, payment_id)
        order = Order.lock.find_by(id: order_id)
        validate_order_payment!(order, provider, payment_id, amount_minor.to_i, currency)
        raise BillingError, "Платёж уже использован для пополнения." if Topup.where(provider: provider, provider_payment_id: payment_id, status: "paid").exists?

        receipt = PaymentReceipt.find_by(provider: provider, payment_id: payment_id)
        if receipt
          raise BillingError, "Платёж уже принадлежит другому заказу." if receipt.order_id != order_id
          next Payment.find_by(provider: provider, provider_payment_id: payment_id)
        end

        raise BillingError, "Заказ уже обработан." unless order.status == "pending"

        now = Time.now.to_i
        PaymentReceipt.create!(provider: provider, payment_id: payment_id, order_id: order_id, amount_minor: amount_minor.to_i, currency: currency, created_at: now)
        Payment.insert_all(
          [{
            id: Infrastructure::IdGenerator.call,
            order_id: order_id,
            user_id: order.user_id,
            provider: provider,
            provider_payment_id: payment_id,
            amount_minor: amount_minor.to_i,
            currency: currency,
            status: "succeeded",
            created_at: now,
            paid_at: now
          }],
          unique_by: %i[provider provider_payment_id]
        )
        payment = Payment.find_by!(provider: provider, provider_payment_id: payment_id)
        CreatorService.new.record_payment(payment.id)

        LedgerEntry.create!(id: Infrastructure::IdGenerator.call, order_id: order_id, account: "provider_clearing", amount_minor: amount_minor.to_i, currency: currency, created_at: now)
        LedgerEntry.create!(id: Infrastructure::IdGenerator.call, order_id: order_id, account: "subscription_sales", amount_minor: -amount_minor.to_i, currency: currency, created_at: now)
        order.update!(status: "paid", workflow_status: "paid", provider_payment_id: payment_id, paid_at: now)
        User.where(id: order.user_id).update_all(has_had_paid_subscription: 1)
        record_purchase_transaction(order, provider, payment_id, amount_minor.to_i, now) unless payment_id.start_with?("balance_")

        if order.renewal_subscription_id.present?
          extend_subscription(order, now)
        else
          create_subscription(order, now, correlation_id)
        end

        AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: "provider:#{provider}", action: "payment.settled", subject: order_id, created_at: now)
        payment
      end
    end

    def settle_from_balance(order_id, amount_minor, currency)
      order = Order.find_by(id: order_id)
      settle_order(order_id, order&.provider || "balance", "balance_#{order_id}", amount_minor, currency)
    end

    private

    def validate_order_payment!(order, provider, payment_id, amount_minor, currency)
      unless order && order.provider == provider && order.price_minor.to_i == amount_minor && order.currency == currency
        raise BillingError, "Платёж не соответствует заказу."
      end
      if order.provider_payment_id.present? && order.provider_payment_id != payment_id
        raise BillingError, "Платёж не соответствует заказу."
      end
    end

    def record_purchase_transaction(order, provider, payment_id, amount_minor, now)
      TransactionRecord.create!(
        id: Infrastructure::IdGenerator.call,
        seq: next_tx_seq,
        user_id: order.user_id,
        type: order.renewal_subscription_id.present? ? "subscription_renewal" : "subscription_purchase",
        amount_kopeks: -amount_minor,
        description: "Оплата заказа: #{order.plan_name}",
        payment_method: provider,
        external_id: payment_id,
        is_completed: 1,
        created_at: now,
        completed_at: now
      )
    end

    def next_tx_seq
      Infrastructure::Locks.transaction_sequence!
      TransactionRecord.maximum(:seq).to_i + 1
    end

    def extend_subscription(order, now)
      subscription = Subscription.lock.find(order.renewal_subscription_id)
      base = [subscription.expires_at.to_i, now].max
      new_expiry = SubscriptionTerms.expiry_after(base, order.duration_days.to_i, order.duration_months.to_i)
      renewal_plan = Plan.find_by(id: subscription.renew_plan_id.presence || subscription.plan_id)
      next_renewal_at = if subscription.auto_renew.to_i == 1
        AutoRenewService.new.renewal_at(expires_at: new_expiry,
          duration_days: order.duration_days.to_i,
          days_before: renewal_plan&.autorenew_days_before)
      end
      subscription.update!(expires_at: new_expiry, status: "active", lifecycle_status: "active",
        renew_order_id: nil, renew_at: next_renewal_at, renew_failed_at: nil,
        renew_fail_count: 0, updated_at: now, version: subscription.version.to_i + 1)
      order.update!(status: "fulfilled")
      @outbox.enqueue("subscription.extend", "extend:#{subscription.id}:#{order.id}", { subscription_id: subscription.id }, correlation_id: order.try(:correlation_id))
      AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: "provider:#{order.provider}", action: "subscription.renewed", subject: subscription.id, created_at: now)
      subscription
    end

    def create_subscription(order, now, correlation_id)
      expiry = SubscriptionTerms.expiry_after(now, order.duration_days.to_i, order.duration_months.to_i)
      subscription = Subscription.create!(
        id: Infrastructure::IdGenerator.call,
        order_id: order.id,
        user_id: order.user_id,
        status: "provisioning",
        lifecycle_status: "pending",
        expires_at: expiry,
        created_at: now,
        updated_at: now,
        starts_at: now,
        plan_id: order.plan_id,
        plan_version_id: order.try(:plan_version_id),
        traffic_limit_gb: order.traffic_bytes.to_i / 1.gigabyte,
        traffic_limit_bytes: order.traffic_bytes.to_i,
        traffic_used_gb: 0,
        device_limit: order.devices.to_i
      )
      if order.duration_days.to_i <= 1 && AutoRenewService.new.enabled?
        subscription.update!(auto_renew: 1, renew_plan_id: order.plan_id,
          renew_price_minor: order.price_minor.to_i, last_daily_charge_at: now)
      end
      ProvisioningAccount.create!(
        id: Infrastructure::IdGenerator.call,
        subscription_id: subscription.id,
        provider: order.provision_driver.presence || "demo",
        state: "pending",
        created_at: now,
        updated_at: now
      )
      @outbox.enqueue("subscription.provision", "provision:#{subscription.id}", { subscription_id: subscription.id }, correlation_id: correlation_id)
      subscription
    end
  end
end
