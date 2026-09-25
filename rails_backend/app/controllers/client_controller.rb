class ClientController < ApplicationController
  def trial_availability
    render json: { available: Billing::TrialService.new.available?(current_user.id) }
  end

  def start_trial
    subscription = Billing::TrialService.new.start(user_id: current_user.id, plan_id: params[:plan_id])
    render json: { id: subscription.id, status: subscription.status, expires_at: subscription.expires_at }, status: :created
  end

  def convert_trial
    plan = Plan.find_by(id: params[:plan_id], active: 1)
    raise Billing::BillingError, "Тариф недоступен." unless plan
    subscription = Billing::TrialService.new.convert_to_paid(
      user_id: current_user.id, subscription_id: params[:id], plan_id: plan.id,
      price_kopeks: params[:price_kopeks], payment_method: "balance"
    )
    render json: { id: subscription.id, status: subscription.status, expires_at: subscription.expires_at }
  end

  def activate_promocode
    result = Billing::PromoCodeService.new.activate(user_id: current_user.id, code: params[:code])
    render json: result, status: result[:success] ? :ok : :unprocessable_entity
  end

  def register_campaign
    campaign = Billing::CampaignService.new.register(current_user.id, params[:start_parameter])
    render json: { registered: campaign.present?, campaign: campaign&.as_json(only: %i[id name bonus_type]) },
      status: campaign.present? ? :ok : :unprocessable_entity
  end

  def submit_poll
    render json: Billing::PollService.new.submit(user_id: current_user.id, poll_id: params[:id], answers: params[:answers])
  end

  def attempt_contest
    render json: Billing::ContestService.new.attempt(user_id: current_user.id, round_id: params[:round_id])
  end

  def referral
    render json: Billing::ReferralService.new.stats(current_user.id)
  end

  def attach_referral
    referrer_id = Billing::ReferralService.new.attach_referrer(current_user.id, params[:code])
    render json: { attached: referrer_id.present? }, status: referrer_id.present? ? :ok : :unprocessable_entity
  end

  def referral_withdrawals
    render json: Billing::ReferralService.new.withdrawals(current_user.id)
  end

  def request_referral_withdrawal
    withdrawal = Billing::ReferralService.new.request_withdrawal(current_user.id, params[:amount_kopeks], params[:payment_details])
    render json: withdrawal.as_json(only: %i[id amount_kopeks status risk_score created_at]), status: :created
  end

  def gifts
    service = Billing::GiftService.new
    render json: {
      enabled: service.enabled?,
      bought: service.bought_by(current_user.id).map { |gift| gift_json(gift, service) },
      received: service.received_by(current_user.id).map { |gift| gift_json(gift, service) },
      plans: Plan.active.order(:price_minor).as_json(only: %i[id name price_minor currency duration_days traffic_bytes devices])
    }
  end

  def buy_gift
    service = Billing::GiftService.new
    gift = service.purchase_from_balance(
      buyer_id: current_user.id, plan_id: params[:plan_id].to_s,
      idempotency_key: params[:idempotency_key].to_s,
      recipient_type: params[:recipient_type], recipient_value: params[:recipient_value],
      message: params[:message]
    )
    render json: gift_json(gift, service), status: :created
  end

  def claim_gift
    service = Billing::GiftService.new
    gift = service.claim(claimant_id: current_user.id, input: params[:code])
    render json: gift_json(gift, service)
  end

  def creator_dashboard
    data = Billing::CreatorService.new.dashboard(current_user.id)
    raise ActiveRecord::RecordNotFound unless data
    render json: data
  end

  def creator_payout
    payout = Billing::CreatorService.new.request_payout(current_user.id, params[:amount_minor], params[:details])
    render json: payout.as_json(only: %i[id amount_minor status requested_at]), status: :created
  end

  def merge_subscriptions
    target = Billing::SubscriptionMergeService.new.merge(
      user_id: current_user.id, source_id: params[:source_id].to_s,
      target_id: params[:target_id].to_s
    )
    render json: target.as_json(only: %i[id user_id status lifecycle_status expires_at traffic_limit_gb purchased_traffic_gb traffic_limit_bytes traffic_used_gb device_limit updated_at version])
  end

  def me
    render json: {
      user: current_user.as_json(only: %i[id email telegram_id balance_kopeks created_at]),
      csrf: current_session.csrf
    }
  end

  def create_telegram_link
    token = Identity::TelegramAuthentication.new.issue_link_code(user_id: current_user.id)
    render json: { code: token, expires_in: 600, command: "/link #{token}" }, status: :created
  end

  def subscriptions
    rows = Subscription.where(user_id: current_user.id).order(created_at: :desc).limit(100)
    render json: rows.as_json(only: %i[id plan_id order_id status lifecycle_status starts_at expires_at traffic_limit_gb purchased_traffic_gb traffic_used_gb device_limit subscription_url created_at auto_renew renew_plan_id renew_price_minor renew_at renew_failed_at renew_fail_count])
  end

  def set_auto_renew
    enabled = ActiveModel::Type::Boolean.new.cast(params[:enabled])
    subscription = Billing::AutoRenewService.new.set(user_id: current_user.id, subscription_id: params[:id], enabled: enabled)
    render json: subscription.as_json(only: %i[id auto_renew renew_plan_id renew_price_minor renew_at renew_failed_at renew_fail_count])
  end

  def orders
    rows = Order.where(user_id: current_user.id).order(created_at: :desc).limit(100)
    render json: rows.as_json(only: %i[id plan_name price_minor currency status provider checkout_url created_at paid_at])
  end

  def renew_subscription
    subscription = Subscription.find_by(id: params[:id], user_id: current_user.id, status: "active")
    raise Billing::BillingError, "Подписка не найдена." unless subscription
    raise Billing::BillingError, "Продлить можно только активную подписку." unless subscription.expires_at.to_i > Time.now.to_i

    key = params[:idempotency_key].to_s.presence || Infrastructure::IdGenerator.call
    raise Billing::BillingError, "Некорректный ключ операции." unless key.match?(/\A[a-zA-Z0-9:_-]{1,64}\z/)
    plan_id = subscription.plan_id
    order = Billing::OrderService.new.create(user_id: current_user.id, plan_id: plan_id,
      idempotency_key: "renew:#{subscription.id}:#{key}", provider: params[:provider].presence,
      receipt_email: params[:receipt_email], client_ip: request.remote_ip,
      renew_subscription_id: subscription.id)
    render json: { id: order.id, status: order.status }, status: :created
  end

  def buy_from_balance
    key = params[:idempotency_key].to_s
    raise Billing::BillingError, "Некорректный ключ операции." unless key.match?(/\A[a-zA-Z0-9:_-]{8,128}\z/)
    order = Billing::WalletPurchaseService.new.purchase(user_id: current_user.id,
      plan_id: params[:plan_id].to_s, idempotency_key: key)
    render json: { id: order.id, status: order.status, subscription_id: order.renewal_subscription_id }, status: :created
  end

  def balance
    render json: {
      balance_kopeks: current_user.balance_kopeks.to_i,
      transactions: TransactionRecord.where(user_id: current_user.id).order(created_at: :desc).limit(100)
        .as_json(only: %i[id type amount_kopeks description payment_method is_completed created_at completed_at])
    }
  end

  private

  def gift_json(gift, service)
    gift.as_json(only: %i[id token gift_recipient_type gift_recipient_value gift_message plan_id period_days traffic_bytes device_limit amount_kopeks currency status user_id created_at paid_at delivered_at])
        .merge(public_code: service.public_code(gift.token), claim_url: service.cabinet_claim_url(gift.token), bot_url: service.bot_claim_url(gift.token))
  end
end
