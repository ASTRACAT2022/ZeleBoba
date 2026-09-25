module Billing
  class TopupService
    ID_KEY = /\A[a-zA-Z0-9:_-]{8,128}\z/
    PROVIDERS = %w[demo platega].freeze

    def initialize(outbox: Infrastructure::OutboxService.new, wallet: WalletService.new)
      @outbox = outbox
      @wallet = wallet
    end

    def create(user_id:, amount_kopeks:, idempotency_key:, provider: nil)
      provider ||= Infrastructure::RuntimeConfig.fetch("PAYMENT_DRIVER", "demo")
      amount = amount_kopeks.to_i
      raise BillingError, "Сумма пополнения: от 1 до 1 000 000 ₽." unless amount.between?(100, 100_000_000)
      raise BillingError, "Некорректный ключ операции." unless ID_KEY.match?(idempotency_key.to_s)
      raise BillingError, "Некорректный платёжный провайдер." unless PROVIDERS.include?(provider)
      Infrastructure::SafetyControls.new.assert_can_purchase!(provider)
      raise BillingError, "Демоплатёж запрещён." if provider == "demo" && (Rails.env.production? || Infrastructure::RuntimeConfig.fetch("APP_ENV", "") == "prod")

      ApplicationRecord.transaction do
        user = User.lock.find_by(id: user_id, disabled: 0)
        raise BillingError, "Аккаунт не найден." unless user

        existing = Topup.find_by(user_id: user_id, idempotency_key: idempotency_key)
        if existing
          raise BillingError, "Этот ключ уже использован для другой суммы." if existing.amount_kopeks.to_i != amount || existing.provider != provider
          next existing
        end

        topup = Topup.create!(
          id: Infrastructure::IdGenerator.call,
          user_id: user_id,
          amount_kopeks: amount,
          currency: "RUB",
          status: "pending",
          provider: provider,
          idempotency_key: idempotency_key,
          created_at: Time.now.to_i
        )
        @outbox.enqueue("topup.create", "topup-checkout:#{topup.id}", { topup_id: topup.id })
        AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: user_id, action: "topup.created", subject: topup.id, created_at: Time.now.to_i)
        topup
      end
    end

    def settle(topup_id, provider, payment_id, amount_minor, currency)
      ApplicationRecord.transaction do
        Infrastructure::Locks.provider_payment!(provider, payment_id)
        topup = Topup.lock.find_by(id: topup_id)
        raise BillingError, "Платёж не соответствует пополнению." unless topup && topup.provider == provider && topup.amount_kopeks.to_i == amount_minor.to_i && topup.currency == currency
        raise BillingError, "Несовпадение платежа пополнения." if payment_id.blank? || payment_id.length > 100 || (topup.provider_payment_id.present? && topup.provider_payment_id != payment_id)
        raise BillingError, "Платёж уже использован для заказа." if PaymentReceipt.where(provider: provider, payment_id: payment_id).exists?

        next unless topup.status == "pending"
        if Topup.where(provider: provider, provider_payment_id: payment_id).where.not(id: topup.id).exists?
          raise BillingError, "Платёж уже принадлежит другому пополнению."
        end

        user = User.lock.find(topup.user_id)
        now = Time.now.to_i
        topup.update!(status: "paid", provider_payment_id: payment_id, paid_at: now, referral_first: user.has_made_first_topup.to_i.zero? ? 1 : 0)
        @wallet.credit(topup.user_id, amount_minor.to_i, "balance_topup", "Пополнение баланса", payment_method: provider, external_id: payment_id)
        user.update!(has_made_first_topup: 1)
        @outbox.enqueue("topup.after", "topup-after:#{topup.id}", { topup_id: topup.id, user_id: topup.user_id })
        @outbox.enqueue("referral.topup", "referral-topup:#{topup.id}", { topup_id: topup.id, user_id: topup.user_id, amount_kopeks: amount_minor.to_i })
        ReferralService.new.process_topup(topup.user_id, amount_minor.to_i, first_payment: topup.referral_first.to_i == 1)
        topup.update!(referral_processed: 1)
        AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: "provider:#{provider}", action: "topup.settled", subject: topup.id, created_at: now)
      end
    end
  end
end
