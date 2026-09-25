module Identity
  class Mfa
    ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567"

    def initialize(vault: Vault.new)
      @vault = vault
    end

    def begin_enrollment(user_id)
      user = User.find(user_id)
      raise Billing::BillingError, "Двухфакторная защита уже включена." if user.totp_secret.present?
      secret = Array.new(32) { ALPHABET[SecureRandom.random_number(32)] }.join
      MfaEnrollment.upsert({ user_id: user_id, secret: @vault.seal("mfa:#{user_id}", secret), expires_at: Time.now.to_i + 600 }, unique_by: :user_id)
      secret
    end

    def enroll(user_id, code)
      ApplicationRecord.transaction do
        user = User.lock.find(user_id)
        raise Billing::BillingError, "Защита уже включена." if user.totp_secret.present?
        row = MfaEnrollment.find_by(user_id: user_id)
        raise Billing::BillingError, "Настройка истекла. Начните снова." unless row && row.expires_at.to_i > Time.now.to_i
        secret = @vault.open("mfa:#{user_id}", row.secret)
        step = valid_step(secret, code, 0)
        user.update!(totp_secret: row.secret, totp_last_step: step)
        row.destroy!
        Array.new(8) do
          raw = SecureRandom.hex(8)
          MfaRecovery.create!(user_id: user_id, code_hash: Digest::SHA256.hexdigest(raw))
          raw
        end
      end
    end

    def verify(user_id, code, session_id: nil)
      ApplicationRecord.transaction do
        user = User.lock.find(user_id)
        raise Billing::BillingError, "Сначала включите двухфакторную защиту." if user.totp_secret.blank?
        if code.to_s.length == 16
          deleted = MfaRecovery.where(user_id: user_id, code_hash: Digest::SHA256.hexdigest(code.to_s)).delete_all
          raise Billing::BillingError, "Неверный код." if deleted.zero?
        else
          secret = @vault.open("mfa:#{user_id}", user.totp_secret)
          step = valid_step(secret, code.to_s, user.totp_last_step.to_i)
          user.update!(totp_last_step: step)
        end
        Session.where(id: session_id).update_all(admin_verified_until: Time.now.to_i + 900) if session_id
      end
    end

    def self.code(secret, step)
      bits = secret.each_char.map do |char|
        index = ALPHABET.index(char) or raise ArgumentError, "invalid base32"
        index.to_s(2).rjust(5, "0")
      end.join
      key = bits.scan(/.{8}/).map { |byte| byte.to_i(2).chr }.join
      counter = [step >> 32, step & 0xffffffff].pack("N2")
      digest = OpenSSL::HMAC.digest("SHA1", key, counter)
      offset = digest.getbyte(19) & 15
      value = digest.byteslice(offset, 4).unpack1("N") & 0x7fffffff
      (value % 1_000_000).to_s.rjust(6, "0")
    end

    private

    def valid_step(secret, code, last_step)
      now = Time.now.to_i / 30
      [now, now - 1, now + 1].each do |step|
        return step if step > last_step && ActiveSupport::SecurityUtils.secure_compare(self.class.code(secret, step), code)
      end
      raise Billing::BillingError, "Неверный или уже использованный код. Дождитесь нового кода."
    end
  end
end
