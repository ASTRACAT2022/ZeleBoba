require "uri/mailto"

module Identity
  class Authentication
    SESSION_TTL = 86_400
    RESET_TTL = 86_400

    def register(email:, password:)
      email = email.to_s.strip.downcase
      raise Billing::BillingError, "Введите корректную почту и пароль от 12 до 128 символов." unless email.match?(URI::MailTo::EMAIL_REGEXP) && email.length <= 254 && password.to_s.length.between?(12, 128)

      ApplicationRecord.transaction do
        id = Infrastructure::IdGenerator.call
        User.create!(id: id, email: email, password_hash: Argon2::Password.create(password), created_at: Time.now.to_i)
        UserIdentity.create!(id: Infrastructure::IdGenerator.call, user_id: id, type: "email", external_id: email, verified_at: Time.now.to_i, created_at: Time.now.to_i)
        id
      end
    rescue ActiveRecord::RecordNotUnique
      raise Billing::BillingError, "Не удалось создать аккаунт с этой почтой."
    end

    def login(email:, password:)
      user = User.find_by(email: email.to_s.strip.downcase)
      encoded = user&.password_hash
      ok = encoded.present? && verify_password(password.to_s, encoded)
      raise Billing::BillingError, "Неверная почта или пароль." unless ok && user.disabled.to_i.zero?

      if encoded.start_with?("pbkdf2_sha256$")
        user.update!(password_hash: Argon2::Password.create(password))
      end
      user
    end

    def issue(user_id)
      raw = SecureRandom.hex(32)
      Session.create!(id: Digest::SHA256.hexdigest(raw), user_id: user_id, csrf: SecureRandom.hex(32), expires_at: Time.now.to_i + SESSION_TTL)
      raw
    end

    def authenticate(raw)
      session_for(raw)&.user
    end

    def session_for(raw)
      return if raw.blank? || raw.length > 128

      session = Session.includes(:user).find_by(id: Digest::SHA256.hexdigest(raw))
      session if session && session.expires_at.to_i > Time.now.to_i && session.user.disabled.to_i.zero?
    end

    def logout(raw)
      Session.where(id: Digest::SHA256.hexdigest(raw.to_s)).delete_all
    end

    def reset_token(email:)
      user = User.find_by(email: email.to_s.strip.downcase)
      return unless user

      raw = SecureRandom.hex(32)
      PasswordReset.create!(id: Infrastructure::IdGenerator.call, user_id: user.id,
                            token_hash: Digest::SHA256.hexdigest(raw), expires_at: Time.now.to_i + RESET_TTL,
                            created_at: Time.now.to_i)
      raw
    end

    def apply_reset(token:, password:)
      raise Billing::BillingError, "Пароль должен быть от 12 до 128 символов." unless password.to_s.length.between?(12, 128)
      return false unless token.to_s.match?(/\A[a-f0-9]{64}\z/)

      ApplicationRecord.transaction do
        reset = PasswordReset.lock.find_by(token_hash: Digest::SHA256.hexdigest(token), consumed_at: nil)
        next false unless reset && reset.expires_at.to_i > Time.now.to_i
        user = User.lock.find_by(id: reset.user_id, disabled: false)
        next false unless user

        user.update!(password_hash: Argon2::Password.create(password))
        PasswordReset.where(user_id: user.id, consumed_at: nil).update_all(consumed_at: Time.now.to_i)
        Session.where(user_id: user.id).delete_all
        LoginChallenge.where(user_id: user.id).update_all(state: "consumed")
        true
      end
    end

    private

    def verify_password(password, encoded)
      return Argon2::Password.verify_password(password, encoded) unless encoded.start_with?("pbkdf2_sha256$")

      algorithm, rounds, salt, encoded_digest = encoded.split("$", 4)
      return false unless algorithm == "pbkdf2_sha256" && rounds.to_i.between?(1, 5_000_000) && salt.present? && encoded_digest.present?

      expected = Base64.strict_decode64(encoded_digest)
      actual = OpenSSL::KDF.pbkdf2_hmac(password, salt: salt, iterations: rounds.to_i, length: expected.bytesize, hash: "sha256")
      ActiveSupport::SecurityUtils.secure_compare(actual, expected)
    rescue ArgumentError
      false
    end
  end
end
