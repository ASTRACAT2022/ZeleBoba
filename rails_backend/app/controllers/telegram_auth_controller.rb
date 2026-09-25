class TelegramAuthController < ApplicationController
  def start
    return unless verify_guest_csrf!
    throttle!("telegram-login:#{request.remote_ip}", 12)
    username = Infrastructure::RuntimeConfig.fetch("TELEGRAM_BOT_USERNAME", "").to_s.strip.delete_prefix("@")
    raise Billing::BillingError, "Вход через Telegram временно недоступен." unless username.match?(/\A[a-zA-Z0-9_]{5,32}\z/)

    challenge = Identity::TelegramAuthentication.new.begin_login
    response.set_header("Set-Cookie", browser_cookie(challenge.fetch(:browser)))
    render json: { bot_url: "https://t.me/#{username}?start=login_#{challenge.fetch(:token)}", expires_in: 300 }
  end

  def status
    ready = Identity::TelegramAuthentication.new.ready?(request.cookies["zb_tg_login"])
    render json: { ready: ready }
  end

  def finish
    return unless verify_guest_csrf!
    throttle!("telegram-finish:#{request.remote_ip}", 20)
    session_token = Identity::TelegramAuthentication.new.consume_login(
      proof: request.cookies["zb_tg_login"], kind: "browser"
    )
    auth = Identity::Authentication.new
    session = auth.session_for(session_token)
    raise Billing::BillingError, "Сессия не создана. Повторите вход." unless session

    response.set_header("Set-Cookie", [browser_cookie(nil), session_cookie(session_token)])
    render json: { user: { id: session.user_id }, csrf: session.csrf }, status: :ok
  end

  def magic
    return unless verify_guest_csrf!
    throttle!("telegram-magic:#{request.remote_ip}", 20)
    session_token = Identity::TelegramAuthentication.new.consume_login(
      proof: params[:token].to_s, kind: "magic"
    )
    auth = Identity::Authentication.new
    session = auth.session_for(session_token)
    raise Billing::BillingError, "Сессия не создана. Повторите вход." unless session

    response.set_header("Set-Cookie", session_cookie(session_token))
    render json: { user: { id: session.user_id }, csrf: session.csrf }, status: :ok
  end

  private

  # These actions are public so guests can begin Telegram auth. Keep the
  # legacy double-submit guest token check for every POST; the magic token
  # alone must not let a third-party page silently sign a browser into an
  # attacker's account (login CSRF).
  def verify_guest_csrf!
    cookie = request.cookies["zb_guest"].to_s
    supplied = params[:_csrf].presence || request.headers["X-CSRF-Token"].to_s
    valid = cookie.match?(/\A[a-f0-9]{64}\z/) && supplied.match?(/\A[a-f0-9]{64}\z/)
    valid &&= ActiveSupport::SecurityUtils.secure_compare(cookie, supplied)
    return true if valid

    render json: { error: "Обновите страницу входа и повторите." }, status: :forbidden
    false
  end

  def throttle!(bucket, limit)
    return if Infrastructure::RequestRateLimiter.new.allow?(bucket, limit: limit, window: 60)

    raise Billing::BillingError, "Слишком много запросов. Повторите позже."
  end

  def browser_cookie(value)
    parts = ["zb_tg_login=#{value}", "Path=/", "HttpOnly", "SameSite=Strict", "Max-Age=#{value ? 300 : 0}"]
    parts << "Secure" if Rails.env.production?
    parts.join("; ")
  end

  def session_cookie(token)
    parts = ["zb_session=#{token}", "Path=/", "HttpOnly", "SameSite=Lax", "Max-Age=86400"]
    parts << "Secure" if Rails.env.production?
    parts.join("; ")
  end
end
