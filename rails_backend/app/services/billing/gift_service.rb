module Billing
  class GiftService
    TOKEN_PREFIX_LENGTH = 59

    def enabled?
      Infrastructure::RuntimeConfig.fetch("CABINET_GIFT_ENABLED", "0") == "1"
    end

    def purchase_from_balance(buyer_id:, plan_id:, idempotency_key:, recipient_type: nil, recipient_value: nil, message: nil, source: "cabinet")
      raise BillingError, "Подарки отключены." unless enabled?
      raise BillingError, "Некорректный ключ операции." unless idempotency_key.to_s.match?(/\A[a-zA-Z0-9:_-]{8,64}\z/)
      recipient_type = recipient_type.to_s.presence
      recipient_value = recipient_value.to_s.strip.presence
      message = message.to_s.strip.presence
      raise BillingError, "Некорректный получатель." if recipient_type.present? && !%w[email telegram].include?(recipient_type)
      raise BillingError, "Сообщение слишком длинное." if message.to_s.length > 1000
      raise BillingError, "Некорректный источник." unless %w[cabinet bot].include?(source)

      ApplicationRecord.transaction do
        existing = GuestPurchase.lock.find_by(idempotency_key: idempotency_key)
        if existing
          raise BillingError, "Ключ уже использован с другими параметрами." if existing.buyer_user_id != buyer_id || existing.plan_id != plan_id
          next existing
        end
        plan = Plan.active.lock.find_by(id: plan_id)
        raise BillingError, "Тариф недоступен." unless plan
        buyer = User.lock.find_by(id: buyer_id, disabled: 0)
        raise BillingError, "Аккаунт не найден." unless buyer

        now = Time.now.to_i
        purchase = GuestPurchase.create!(
          id: Infrastructure::IdGenerator.call,
          token: SecureRandom.urlsafe_base64(48, false),
          contact_type: buyer.email.present? ? "email" : "telegram",
          contact_value: buyer.email.presence || buyer.telegram_id.to_s,
          is_gift: 1, source: source, buyer_user_id: buyer.id,
          gift_recipient_type: recipient_type, gift_recipient_value: recipient_value,
          gift_message: message, plan_id: plan.id,
          period_days: plan.duration_days.to_i,
          traffic_bytes: plan.traffic_bytes.to_i,
          device_limit: plan.devices.to_i,
          amount_kopeks: plan.price_minor.to_i, currency: plan.currency.presence || "RUB",
          payment_method: "balance", status: "paid", created_at: now, paid_at: now,
          idempotency_key: idempotency_key
        )
        WalletService.new.debit(buyer.id, purchase.amount_kopeks, "gift_purchase", "Покупка подарочной подписки: #{plan.name}")
        audit(buyer.id, "gift.purchased", purchase.id, now)
        purchase
      end
    rescue ActiveRecord::RecordNotUnique
      existing = GuestPurchase.find_by(idempotency_key: idempotency_key)
      raise BillingError, "Ключ уже использован с другими параметрами." unless existing&.buyer_user_id == buyer_id && existing&.plan_id == plan_id
      existing
    end

    def claim(claimant_id:, input:)
      prefix = parse_claim_input(input)
      raise BillingError, "Подарок не найден." unless prefix && prefix.match?(/\A[a-zA-Z0-9_-]{59,64}\z/)

      ApplicationRecord.transaction do
        purchase = GuestPurchase.gifts.lock.find_by("substring(token from 1 for ?) = ?", prefix.length, prefix)
        raise BillingError, "Подарок не найден." unless purchase
        raise BillingError, "Нельзя активировать собственный подарок." if purchase.buyer_user_id == claimant_id
        raise BillingError, "Подарок уже активирован другим пользователем." if purchase.user_id.present? && purchase.user_id != claimant_id
        next purchase if purchase.status == "delivered" && purchase.user_id == claimant_id
        raise BillingError, "Подарок не может быть активирован." unless %w[paid pending_activation].include?(purchase.status)

        now = Time.now.to_i
        subscription = Subscription.create!(
          id: Infrastructure::IdGenerator.call, order_id: nil, user_id: claimant_id,
          status: "provisioning", lifecycle_status: "pending", starts_at: now,
          expires_at: now + purchase.period_days.to_i.days.to_i, created_at: now,
          updated_at: now, plan_id: purchase.plan_id,
          traffic_limit_gb: purchase.traffic_bytes.to_i / 1.gigabyte,
          traffic_limit_bytes: purchase.traffic_bytes.to_i, traffic_used_gb: 0,
          device_limit: purchase.device_limit.to_i, is_trial: 0, start_date: now
        )
        purchase.update!(status: "delivered", user_id: claimant_id, delivered_at: now)
        Infrastructure::OutboxService.new.enqueue("subscription.provision", "provision:#{subscription.id}", { subscription_id: subscription.id })
        audit(claimant_id, "gift.claimed", purchase.id, now)
        purchase
      end
    end

    def bought_by(user_id)
      GuestPurchase.gifts.where(buyer_user_id: user_id).order(created_at: :desc).limit(100)
    end

    def received_by(user_id)
      GuestPurchase.gifts.where(user_id: user_id).order(created_at: :desc).limit(100)
    end

    def public_code(token) = "GIFT_#{token.to_s.first(TOKEN_PREFIX_LENGTH)}"

    def bot_claim_url(token)
      username = Infrastructure::RuntimeConfig.fetch("TELEGRAM_BOT_USERNAME", "").delete_prefix("@").strip
      username.present? ? "https://t.me/#{username}?start=#{public_code(token)}" : ""
    end

    def cabinet_claim_url(token)
      "#{Infrastructure::RuntimeConfig.fetch('APP_URL', '').sub(%r{/*\z}, '')}/buy/gift/#{token}"
    end

    private

    def parse_claim_input(value)
      cleaned = value.to_s.strip
      return nil if cleaned.blank?
      candidate = cleaned.start_with?("t.me/", "www.t.me/") ? "https://#{cleaned}" : cleaned
      if candidate.match?(%r{\A(?:https?://|tg://)}i)
        uri = URI.parse(candidate)
        start = URI.decode_www_form(uri.query.to_s).to_h["start"].to_s
        return start[5..] if start.match?(/\AGIFT[_-]/i)
        return start if start.match?(/\A[a-zA-Z0-9_-]{64}\z/)
        path = uri.path.to_s
        return path.split("/buy/gift/", 2).last if path.include?("/buy/gift/")
        return nil
      end
      return cleaned[5..] if cleaned.match?(/\AGIFT[_-]/i)
      return cleaned[10..] if cleaned.match?(/\Agiftclaim[_-]/i)
      cleaned if cleaned.match?(/\A[a-zA-Z0-9_-]{8,64}\z/)
    rescue URI::InvalidURIError, ArgumentError
      nil
    end

    def audit(actor, action, subject, now)
      AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor, action: action, subject: subject, created_at: now)
    end
  end
end
