class PlansController < ApplicationController
  def index
    render json: Plan.where(active: 1).order(:price_minor).as_json(
      only: %i[id name price_minor currency duration_days duration_months traffic_bytes devices]
    )
  end

  def landing
    landing = LandingPage.find_by(slug: params[:slug].to_s, is_active: 1)
    raise ActiveRecord::RecordNotFound unless landing
    plans = Plan.active.order(:price_minor)
    discount = landing.discount_percent.to_i
    starts_at = landing.discount_starts_at&.to_i
    ends_at = landing.discount_ends_at&.to_i
    now = Time.now.to_i
    rows = plans.map do |plan|
      landing_price = if discount.positive? && (starts_at.nil? || starts_at <= now) && (ends_at.nil? || ends_at >= now)
        plan.price_minor.to_i * (100 - discount) / 100
      else
        plan.price_minor.to_i
      end
      plan.as_json(only: %i[id name price_minor currency duration_days duration_months traffic_bytes devices])
        .merge(price_minor: landing_price)
    end
    render json: { landing: landing.as_json, plans: rows }
  end
end
