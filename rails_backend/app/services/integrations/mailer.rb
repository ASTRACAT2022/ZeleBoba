require "base64"
require "ipaddr"
require "net/smtp"
require "openssl"
require "securerandom"
require "socket"
require "time"
require "timeout"
require "uri"
require "uri/mailto"

module Integrations
  class Mailer
    MAX_ATTEMPTS = 5
    MAX_BATCH_SIZE = 500
    MAX_BODY_BYTES = 1_048_576

    class DeliveryError < StandardError; end

    def initialize(config: Infrastructure::RuntimeConfig.new)
      @config = config
    end

    def enabled?
      config.fetch("SMTP_ENABLED", "0") == "1" &&
        config.fetch("SMTP_HOST", "").present? &&
        config.fetch("SMTP_USER", "").present? &&
        config.fetch("SMTP_PASSWORD", "").present?
    end

    # Persist an email for later delivery. This performs no network access.
    def queue(to_email:, subject:, body_html:, user_id: nil)
      recipient = to_email.to_s.strip
      subject = subject.to_s
      body = body_html.to_s
      validate_email!(recipient, "recipient")
      raise ArgumentError, "Invalid email subject" if subject.blank? || subject.bytesize > 255 || subject.match?(/[\x00-\x1f\x7f]/)
      raise ArgumentError, "Email body is too large" if body.bytesize > MAX_BODY_BYTES

      EmailQueueItem.create!(
        id: Infrastructure::IdGenerator.call,
        user_id: user_id,
        to_email: recipient,
        subject: subject,
        body: body,
        status: "pending",
        attempts: 0,
        created_at: Time.now.to_i
      ).id
    end

    # Deliver queued messages. The row lock is held while SMTP runs because the
    # shared schema has no processing/lease state; this prevents two workers
    # from sending the same pending message concurrently.
    def flush_queue(limit: 50)
      batch_size = Integer(limit)
      raise ArgumentError, "Invalid email queue batch size" unless batch_size.between?(1, MAX_BATCH_SIZE)

      ids = EmailQueueItem.where(status: "pending").where("attempts < ?", MAX_ATTEMPTS)
        .order(:created_at, :id).limit(batch_size).pluck(:id)
      sent = 0
      failed = 0

      ids.each do |id|
        result = deliver_queued_item(id)
        sent += 1 if result == :sent
        failed += 1 if result == :failed
      end

      { sent: sent, failed: failed }
    end

    # Send one message immediately. Credentials and settings are read from the
    # shared, decrypted RuntimeConfig; no secrets are copied into this service.
    def send(to_email:, subject:, body_html:)
      raise DeliveryError, "SMTP is not configured." unless enabled?

      recipient = to_email.to_s.strip
      validate_email!(recipient, "recipient")
      subject = subject.to_s
      body = body_html.to_s
      raise ArgumentError, "Invalid email subject" if subject.blank? || subject.bytesize > 255 || subject.match?(/[\x00-\x1f\x7f]/)
      raise ArgumentError, "Email body is too large" if body.bytesize > MAX_BODY_BYTES

      host = validated_host
      port = validated_port
      user = config.fetch("SMTP_USER", "").to_s
      password = config.fetch("SMTP_PASSWORD", "").to_s
      raise DeliveryError, "SMTP credentials are invalid." if user.match?(/[\x00-\x1f\x7f]/) || password.match?(/[\x00-\x1f\x7f]/)

      from = config.fetch("SMTP_FROM", "").to_s.strip
      from = user if from.empty?
      validate_email!(from, "sender")
      from_name = config.fetch("SMTP_FROM_NAME", "").to_s
      raise ArgumentError, "Invalid sender name" if from_name.bytesize > 240 || from_name.match?(/[\x00-\x1f\x7f]/)

      domain = app_domain
      smtp = Net::SMTP.new(host, port)
      smtp.open_timeout = 15
      smtp.read_timeout = 15
      tls_context = OpenSSL::SSL::SSLContext.new
      tls_context.set_params(verify_mode: OpenSSL::SSL::VERIFY_PEER)
      smtp.enable_starttls(tls_context)
      smtp.start(domain, user, password, :login) do |connection|
        connection.send_message(message(from, from_name, recipient, subject, body, domain), from, recipient)
      end
      nil
    rescue Net::SMTPAuthenticationError
      raise DeliveryError, "SMTP authentication failed."
    rescue Net::SMTPError, SocketError, SystemCallError, Timeout::Error, OpenSSL::SSL::SSLError => error
      raise DeliveryError, "SMTP delivery failed (#{error.class.name})."
    end

    private

    attr_reader :config

    def deliver_queued_item(id)
      EmailQueueItem.transaction do
        item = EmailQueueItem.lock.find_by(id: id)
        next :skipped unless item && item.status == "pending" && item.attempts.to_i < MAX_ATTEMPTS

        begin
          send(to_email: item.to_email, subject: item.subject, body_html: item.body)
          item.update!(status: "sent", sent_at: Time.now.to_i)
          :sent
        rescue StandardError
          attempts = item.attempts.to_i + 1
          item.update!(attempts: attempts, status: attempts >= MAX_ATTEMPTS ? "failed" : "pending")
          :failed
        end
      end
    end

    def validated_host
      host = config.fetch("SMTP_HOST", "").to_s.strip
      valid = host.bytesize <= 253 && !host.match?(/[\s\r\n\/\\@]/) &&
        (host.match?(/\A(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*\z/) || valid_ip?(host))
      raise DeliveryError, "SMTP host is invalid." unless valid

      host
    end

    def valid_ip?(host)
      IPAddr.new(host)
      true
    rescue IPAddr::InvalidAddressError
      false
    end

    def validated_port
      raw = config.fetch("SMTP_PORT", "587").to_s
      raise DeliveryError, "SMTP port is invalid." unless raw.match?(/\A[0-9]{1,5}\z/) && raw.to_i.between?(1, 65_535)

      raw.to_i
    end

    def validate_email!(email, label)
      unless email.bytesize <= 254 && email.match?(URI::MailTo::EMAIL_REGEXP) && !email.match?(/[\r\n]/)
        raise ArgumentError, "Invalid email #{label}"
      end
    end

    def app_domain
      url = config.fetch("APP_URL", "").to_s
      uri = URI.parse(url)
      host = uri.host
      raise DeliveryError, "Application URL is invalid." if host.blank? || host.match?(/[\r\n]/)

      host
    rescue URI::InvalidURIError
      raise DeliveryError, "Application URL is invalid."
    end

    def message(from, from_name, recipient, subject, body, domain)
      display_from = from_name.present? ? "#{encoded_header(from_name)} <#{from}>" : from
      headers = [
        "From: #{display_from}",
        "To: <#{recipient}>",
        "Subject: #{encoded_header(subject)}",
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "Content-Transfer-Encoding: base64",
        "Date: #{Time.now.rfc2822}",
        "Message-ID: <#{SecureRandom.hex(16)}@#{domain}>"
      ]
      encoded_body = Base64.strict_encode64(body).scan(/.{1,76}/).join("\r\n")
      (headers.join("\r\n") + "\r\n\r\n" + encoded_body + "\r\n")
    end

    def encoded_header(value)
      return value if value.ascii_only? && !value.match?(/[\x00-\x1f\x7f]/)

      Base64.strict_encode64(value.encode(Encoding::UTF_8)).scan(/.{1,44}/)
        .map { |chunk| "=?UTF-8?B?#{chunk}?=" }.join(" ")
    end
  end
end
