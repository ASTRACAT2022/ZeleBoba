class TelegramWebhookController < ActionController::API
  skip_before_action :verify_authenticity_token, raise: false

  def create
    # Telegram posts a bounded JSON body with Content-Length. Reject unknown
    # lengths instead of treating chunked requests as zero bytes, and also
    # check the actual body size in case the header is inaccurate.
    content_length = request.content_length.to_i
    return head :payload_too_large if content_length <= 0 || content_length > 65_536

    expected = Infrastructure::RuntimeConfig.fetch("TELEGRAM_WEBHOOK_SECRET", "").to_s
    supplied = request.headers["X-Telegram-Bot-Api-Secret-Token"].to_s
    return head :unauthorized if expected.blank? || supplied.blank? || !secure_equal?(expected, supplied)

    raw_body = request.raw_post
    return head :payload_too_large if raw_body.bytesize > 65_536

    update = JSON.parse(raw_body)
    Integrations::TelegramUpdateService.new.receive(update)
    head :ok
  rescue JSON::ParserError, Billing::BillingError, ActiveRecord::RecordInvalid
    head :bad_request
  end

  private

  def secure_equal?(expected, supplied)
    expected.bytesize == supplied.bytesize && ActiveSupport::SecurityUtils.secure_compare(expected, supplied)
  end
end
