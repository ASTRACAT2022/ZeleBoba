class PaymentsController < ApplicationController
  def create_order
    order = Billing::OrderService.new.create(
      user_id: current_user.id, plan_id: params[:plan_id].to_s,
      idempotency_key: params[:idempotency_key].to_s,
      provider: params[:provider].presence || Infrastructure::RuntimeConfig.fetch("PAYMENT_DRIVER", "demo"),
      receipt_email: params[:receipt_email], client_ip: request.remote_ip,
      landing_slug: params[:landing_slug], renew_subscription_id: params[:renew_subscription_id]
    )
    render json: { id: order.id, status: order.status }, status: :created
  end

  def create_topup
    topup = Billing::TopupService.new.create(
      user_id: current_user.id, amount_kopeks: params[:amount_kopeks],
      idempotency_key: params[:idempotency_key].to_s,
      provider: params[:provider].presence || Infrastructure::RuntimeConfig.fetch("PAYMENT_DRIVER", "demo")
    )
    render json: { id: topup.id, status: topup.status }, status: :created
  end

  def show_order
    order = Order.find_by(id: params[:id])
    require_owned_record!(order)
    render json: order.as_json(only: %i[id plan_name price_minor currency status provider checkout_url created_at paid_at])
  end

  def show_topup
    topup = Topup.find_by(id: params[:id])
    require_owned_record!(topup)
    render json: topup.as_json(only: %i[id amount_kopeks currency status provider checkout_url created_at paid_at])
  end

  def create_order_checkout
    require_owned_record!(Order.find_by(id: params[:id]))
    result = Payments::PaymentService.new.create_order(params[:id])
    render json: result
  end

  def create_topup_checkout
    require_owned_record!(Topup.find_by(id: params[:id]))
    result = Payments::PaymentService.new.create_topup(params[:id])
    render json: result
  end

  def demo_pay_order
    order = Order.find_by(id: params[:id])
    require_owned_record!(order)
    raise ActiveRecord::RecordNotFound if production? || order.provider != "demo"
    Billing::SettlementService.new.settle_order(order.id, "demo", "demo_#{order.id}",
      order.price_minor.to_i, order.currency)
    head :accepted
  end

  def demo_pay_topup
    topup = Topup.find_by(id: params[:id])
    require_owned_record!(topup)
    raise ActiveRecord::RecordNotFound if production? || topup.provider != "demo"
    Billing::TopupService.new.settle(topup.id, "demo", "demo_#{topup.id}",
      topup.amount_kopeks.to_i, topup.currency)
    head :accepted
  end

  def verify
    payment_id = params[:payment_id].to_s
    owned = Order.where(user_id: current_user.id).where("provider_payment_id = ? OR id = ?", payment_id, payment_id).exists? ||
            Topup.where(user_id: current_user.id).where("provider_payment_id = ? OR id = ?", payment_id, payment_id).exists?
    raise ActiveRecord::RecordNotFound unless owned
    Payments::PaymentService.new.verify(payment_id, params[:provider])
    head :accepted
  end

  private

  def production?
    Rails.env.production? || Infrastructure::RuntimeConfig.fetch("APP_ENV", "") == "prod"
  end
end
