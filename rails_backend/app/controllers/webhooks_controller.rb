class WebhooksController < ApplicationController
  def platega
    handled = Payments::PaymentService.new.handle_webhook("platega", request)
    return head :unauthorized unless handled

    render json: { ok: true }, status: :accepted
  end

end
