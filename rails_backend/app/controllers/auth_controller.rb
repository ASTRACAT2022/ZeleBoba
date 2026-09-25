class AuthController < ApplicationController
  def register
    raise Billing::BillingError, "Регистрация временно закрыта." unless Infrastructure::RuntimeConfig.fetch("REGISTRATION_ENABLED", "1") == "1"
    throttle!("register:#{request.remote_ip}", 8)
    user_id = Identity::Authentication.new.register(email: params[:email], password: params[:password])
    Billing::ReferralService.new.attach_referrer(user_id, params[:referral_code]) if params[:referral_code].present?
    Billing::CreatorService.new.attach_registration(user_id, request.cookies["creator_attr"]) if request.cookies["creator_attr"].present?
    Billing::CampaignService.new.register(user_id, params[:start_parameter]) if params[:start_parameter].present?
    issue_session(user_id)
  end

  def login
    throttle!("login:#{request.remote_ip}", 30)
    throttle!("login-email:#{params[:email].to_s.strip.downcase}", 12)
    user = Identity::Authentication.new.login(email: params[:email], password: params[:password])
    if user.totp_secret.present?
      raise Billing::BillingError, "Введите код двухфакторной защиты." if params[:code].blank?
      issue_mfa_session(user.id, params[:code].to_s)
      return
    end
    issue_session(user.id)
  end

  def logout
    token = request.authorization.to_s.delete_prefix("Bearer ").presence || request.cookies["zb_session"].to_s
    Identity::Authentication.new.logout(token)
    parts = ["zb_session=", "Path=/", "HttpOnly", "SameSite=Lax", "Max-Age=0"]
    parts << "Secure" if Rails.env.production?
    response.set_header("Set-Cookie", parts.join("; "))
    head :no_content
  end

  def forgot_password
    throttle!("forgot:#{request.remote_ip}", 20)
    email = params[:email].to_s.strip.downcase
    if email.present? && Integrations::Mailer.new.enabled?
      token = Identity::Authentication.new.reset_token(email: email)
      if token
        app_url = Infrastructure::RuntimeConfig.fetch("APP_URL", "").sub(%r{/*\z}, "")
        url = "#{app_url}/reset/#{token}"
        body = "<p>Здравствуйте!</p><p>Чтобы восстановить пароль, откройте ссылку:</p><p><a href=\"#{ERB::Util.html_escape(url)}\">Восстановить пароль</a></p><p>Если вы не запрашивали восстановление, проигнорируйте это письмо.</p>"
        Integrations::Mailer.new.queue(to_email: email,
          subject: "ASTRACAT — восстановление пароля", body_html: body)
      end
    end
    render json: { accepted: true }, status: :accepted
  end

  def reset_password
    throttle!("reset:#{request.remote_ip}", 20)
    applied = Identity::Authentication.new.apply_reset(token: params[:token], password: params[:password])
    raise Billing::BillingError, "Ссылка недействительна или истекла. Запросите восстановление заново." unless applied
    head :no_content
  end

  private

  def issue_session(user_id)
    auth = Identity::Authentication.new
    token = auth.issue(user_id)
    session = auth.session_for(token)
    response.set_header("Set-Cookie", cookie_header(token))
    render json: { user: { id: user_id }, csrf: session.csrf }, status: :ok
  end

  def issue_mfa_session(user_id, code)
    auth = Identity::Authentication.new
    token = auth.issue(user_id)
    session_id = Digest::SHA256.hexdigest(token)
    begin
      Identity::Mfa.new.verify(user_id, code, session_id: session_id)
    rescue StandardError
      auth.logout(token)
      raise
    end
    session = auth.session_for(token)
    response.set_header("Set-Cookie", cookie_header(token))
    render json: { user: { id: user_id }, csrf: session.csrf }, status: :ok
  end

  def cookie_header(token)
    parts = ["zb_session=#{token}", "Path=/", "HttpOnly", "SameSite=Lax", "Max-Age=86400"]
    parts << "Secure" if Rails.env.production?
    parts.join("; ")
  end

  def throttle!(bucket, limit)
    allowed = Infrastructure::RequestRateLimiter.new.allow?(bucket, limit: limit, window: 60)
    raise Billing::BillingError, "Слишком много запросов. Повторите позже." unless allowed
  end
end
