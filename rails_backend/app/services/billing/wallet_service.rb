module Billing
  class WalletService
    TYPES = %w[
      balance_topup subscription_purchase subscription_renewal subscription_daily
      trial_conversion referral_reward referral_withdrawal traffic_topup device_addon
      gift_purchase promo_credit manual_adjust refund
    ].freeze

    def credit(user_id, amount_kopeks, type, description, payment_method: nil, external_id: nil, completed: true)
      mutate(:credit, user_id, amount_kopeks, type, description, payment_method, external_id, completed)
    end

    def debit(user_id, amount_kopeks, type, description, payment_method: nil, external_id: nil)
      mutate(:debit, user_id, amount_kopeks, type, description, payment_method, external_id, true)
    end

    def balance(user_id)
      { balance_kopeks: User.find_by(id: user_id)&.balance_kopeks.to_i }
    end

    private

    def mutate(direction, user_id, amount_kopeks, type, description, payment_method, external_id, completed)
      raise BillingError, "Сумма должна быть положительной." unless amount_kopeks.to_i.positive?
      raise BillingError, "Некорректный тип операции." unless TYPES.include?(type)

      external_id = normalize_external_id(external_id)
      signed_amount = direction == :credit ? amount_kopeks.to_i : -amount_kopeks.to_i

      ApplicationRecord.transaction do
        if external_id && idempotent_replay?(user_id, type, external_id, signed_amount)
          next balance(user_id)
        end

        user = User.lock.find_by(id: user_id)
        raise BillingError, "Аккаунт не найден." unless user
        raise BillingError, "Недостаточно средств на балансе." if direction == :debit && user.balance_kopeks.to_i < amount_kopeks.to_i

        user.update!(balance_kopeks: user.balance_kopeks.to_i + signed_amount)
        tx = TransactionRecord.create!(
          id: Infrastructure::IdGenerator.call,
          seq: next_seq,
          user_id: user_id,
          type: type,
          amount_kopeks: signed_amount,
          description: description,
          payment_method: payment_method,
          external_id: external_id,
          is_completed: completed ? 1 : 0,
          created_at: Time.now.to_i,
          completed_at: completed ? Time.now.to_i : nil
        )
        record_ledger(tx.id, user_id, type, signed_amount, tx.created_at)
        balance(user_id)
      end
    end

    def normalize_external_id(value)
      normalized = value.to_s.strip
      return nil if normalized.empty?
      raise BillingError, "Некорректный ключ операции." if normalized.length > 100

      normalized
    end

    def idempotent_replay?(user_id, type, external_id, amount_kopeks)
      existing = TransactionRecord.find_by(user_id: user_id, type: type, external_id: external_id)
      return false unless existing
      raise BillingError, "Этот ключ уже использован для другой суммы." unless existing.amount_kopeks.to_i == amount_kopeks

      true
    end

    def next_seq
      Infrastructure::Locks.transaction_sequence!
      TransactionRecord.maximum(:seq).to_i + 1
    end

    def record_ledger(transaction_id, user_id, type, amount_kopeks, created_at)
      contra = amount_kopeks.positive? ? "wallet:source:#{type}" : "wallet:sink:#{type}"
      [
        ["wallet:user:#{user_id}", amount_kopeks, "u"],
        [contra, -amount_kopeks, "c"]
      ].each do |account, amount, suffix|
        WalletLedgerEntry.insert_all(
          [{
            id: "#{transaction_id}#{suffix}",
            transaction_id: transaction_id,
            account: account,
            amount_kopeks: amount,
            currency: "RUB",
            created_at: created_at
          }],
          unique_by: %i[transaction_id account]
        )
      end
    end
  end
end
