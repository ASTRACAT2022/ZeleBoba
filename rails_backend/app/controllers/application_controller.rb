class ApplicationController < ActionController::API
  rescue_from Billing::BillingError, with: :billing_error
  rescue_from ActiveRecord::RecordNotFound, with: :not_found

  before_action :authenticate_user!, unless: :public_request?
  before_action :verify_cookie_csrf!, if: -> { request.post? && !public_request? && request.authorization.blank? }
  before_action :enforce_body_limit
  after_action :set_security_headers

  def current_user
    @current_user
  end

  def current_session
    @current_session
  end

  private

  def authenticate_user!
    token = request.authorization.to_s.delete_prefix("Bearer ")
    token = request.cookies["zb_session"] if token.blank?
    @current_session = Identity::Authentication.new.session_for(token)
    @current_user = @current_session&.user
    render(json: { error: "Требуется вход." }, status: :unauthorized) unless @current_user
  end

  def verify_cookie_csrf!
    expected = @current_session&.csrf.to_s
    supplied = params[:_csrf].presence || request.headers["X-CSRF-Token"].to_s
    unless expected.present? && ActiveSupport::SecurityUtils.secure_compare(expected, supplied)
      render json: { error: "Сессия формы устарела." }, status: :forbidden
    end
  end

  def webhook_request?
    controller_name == "webhooks"
  end

  def public_request?
      webhook_request? || controller_name == "health" ||
      (controller_name == "telegram_auth" && %w[start status finish magic].include?(action_name)) ||
      (controller_name == "auth" && %w[login register forgot_password reset_password].include?(action_name)) ||
      (controller_name == "plans" && %w[index landing].include?(action_name)) || (controller_name == "creator" && action_name == "capture")
  end

  def enforce_body_limit
    head :payload_too_large if request.content_length.to_i > 65_536
  end

  def set_security_headers
    response.headers["X-Content-Type-Options"] = "nosniff"
    response.headers["Referrer-Policy"] = "no-referrer"
    response.headers["Cache-Control"] = "no-store"
    response.headers["X-Frame-Options"] = "DENY"
  end

  def require_owned_record!(record)
    raise ActiveRecord::RecordNotFound unless record && record.user_id == current_user.id
  end

  def billing_error(error)
    render json: { error: error.message }, status: :unprocessable_entity
  end

  def not_found
    render json: { error: "Не найдено." }, status: :not_found
  end
end
