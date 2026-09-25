class MfaController < ApplicationController
  def start_enrollment
    secret = Identity::Mfa.new.begin_enrollment(current_user.id)
    render json: { secret: secret, otpauth_uri: "otpauth://totp/ZeleBoba:#{ERB::Util.url_encode(current_user.email || current_user.id)}?secret=#{secret}&issuer=ZeleBoba&algorithm=SHA1&digits=6&period=30" }
  end

  def enroll
    recovery_codes = Identity::Mfa.new.enroll(current_user.id, params[:code].to_s)
    render json: { recovery_codes: recovery_codes }
  end

  def verify
    session_id = current_session&.id
    Identity::Mfa.new.verify(current_user.id, params[:code].to_s, session_id: session_id)
    head :no_content
  end
end
