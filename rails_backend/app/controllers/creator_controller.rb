class CreatorController < ApplicationController
  def capture
    allowed = Infrastructure::RequestRateLimiter.new.allow?("creator-click:#{request.remote_ip}", limit: 120, window: 60)
    return head :too_many_requests unless allowed
    token = Billing::CreatorService.new.capture(params[:ref], campaign: params[:campaign], ip: request.remote_ip)
    if token
      response.set_header("Set-Cookie", "creator_attr=#{token}; Path=/; Max-Age=2592000; HttpOnly; SameSite=Lax#{'; Secure' if Rails.env.production?}")
    end
    render json: { captured: token.present? }
  end
end
