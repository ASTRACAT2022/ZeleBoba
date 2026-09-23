# frozen_string_literal: true

require "digest"
require "securerandom"
require "base64"
require "openssl"

require_relative "../billing/error"
require_relative "../infrastructure/database"

module Zeleboba
  module Identity
    class Auth
      PBKDF2_ITERATIONS = 210_000
      DUMMY_HASH = "pbkdf2_sha256$#{PBKDF2_ITERATIONS}$#{Base64.strict_encode64("dummy-salt-16b")}$#{Base64.strict_encode64(OpenSSL::PKCS5.pbkdf2_hmac("dummy-password", "dummy-salt-16b", PBKDF2_ITERATIONS, 32, "sha256"))}"

      def initialize(db)
        @db = db
      end

      def register(email, password)
        email = email.to_s.strip.downcase
        unless email.match?(/\A[^@\s]+@[^@\s]+\.[^@\s]+\z/) && email.length <= 254 && password.to_s.length.between?(12, 128)
          raise Billing::Error, "Введите корректную почту и пароль от 12 до 128 символов."
        end

        id = Infrastructure::Database.id
        @db.transaction do
          @db.execute(
            "INSERT INTO users(id,email,password_hash,created_at) VALUES(?,?,?,?)",
            [id, email, digest(password), Time.now.to_i]
          )
          attach_identity(id, "email", email)
        end
        id
      rescue Infrastructure::Database::ConstraintError
        raise Billing::Error, "Не удалось создать аккаунт с этой почтой."
      end

      def login(email, password)
        user = @db.one("SELECT * FROM users WHERE email=?", [email.to_s.strip.downcase])
        hash = user&.fetch("password_hash", nil) || DUMMY_HASH
        ok = verify_password(password, hash)
        raise Billing::Error, "Неверная почта или пароль." unless ok && user && user.fetch("disabled", 0).to_i.zero?

        # Existing development builds stored a SHA-256 digest. Upgrade it on
        # login instead of forcing users to reset passwords after deployment.
        @db.execute("UPDATE users SET password_hash=? WHERE id=?", [digest(password), user["id"]]) unless hash.start_with?("pbkdf2_sha256$")

        user["id"]
      end

      def issue(user_id)
        raw = SecureRandom.hex(32)
        @db.execute(
          "INSERT INTO sessions(id,user_id,csrf,expires_at) VALUES(?,?,?,?)",
          [Digest::SHA256.hexdigest(raw), user_id, SecureRandom.hex(32), Time.now.to_i + 86_400]
        )
        raw
      end

      def session(raw)
        return nil if raw.to_s.empty?

        @db.one(
          "SELECT u.id,u.email,u.telegram_id,u.role,u.balance_kopeks,s.csrf,s.admin_verified_until,CASE WHEN u.totp_secret IS NULL THEN 0 ELSE 1 END AS mfa_enabled " \
          "FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.expires_at>? AND COALESCE(u.disabled,0)=0",
          [Digest::SHA256.hexdigest(raw), Time.now.to_i]
        )
      end

      def logout(raw)
        @db.execute("DELETE FROM sessions WHERE id=?", [Digest::SHA256.hexdigest(raw.to_s)])
      end

      def throttle(key, limit, seconds = 900)
        hits = @db.transaction do
          bucket = Digest::SHA256.hexdigest(key)
          now = Time.now.to_i
          @db.execute("INSERT INTO rate_limits VALUES(?,0,?) ON CONFLICT(bucket) DO NOTHING", [bucket, now + seconds])
          row = @db.one("SELECT * FROM rate_limits WHERE bucket=?#{@db.lock}", [bucket])
          next_hits = row["expires_at"].to_i <= now ? 1 : row["hits"].to_i + 1
          expiry = row["expires_at"].to_i <= now ? now + seconds : row["expires_at"].to_i
          @db.execute("UPDATE rate_limits SET hits=?,expires_at=? WHERE bucket=?", [next_hits, expiry, bucket])
          next_hits
        end
        raise Billing::Error, "Слишком много запросов. Попробуйте позже." if hits > limit
      end

      private

      def digest(password)
        salt = SecureRandom.random_bytes(16)
        derived = OpenSSL::PKCS5.pbkdf2_hmac(password.to_s, salt, PBKDF2_ITERATIONS, 32, "sha256")
        ["pbkdf2_sha256", PBKDF2_ITERATIONS, Base64.strict_encode64(salt), Base64.strict_encode64(derived)].join("$")
      end

      def verify_password(password, encoded)
        type, iterations, salt, expected = encoded.to_s.split("$", 4)
        if type == "pbkdf2_sha256" && iterations.to_s.match?(/\A\d+\z/)
          count = iterations.to_i
          return false unless count.between?(100_000, 2_000_000)

          raw_salt = Base64.strict_decode64(salt)
          raw_expected = Base64.strict_decode64(expected)
          actual = OpenSSL::PKCS5.pbkdf2_hmac(password.to_s, raw_salt, count, raw_expected.bytesize, "sha256")
          return secure_compare(raw_expected, actual)
        end

        secure_compare(encoded.to_s, Digest::SHA256.hexdigest(password.to_s))
      rescue ArgumentError
        false
      end

      def secure_compare(left, right)
        return false unless left.bytesize == right.bytesize

        left.bytes.zip(right.bytes).reduce(0) { |acc, (a, b)| acc | (a ^ b) }.zero?
      end

      def attach_identity(user_id, provider, subject)
        return unless table_exists?("user_identities")

        @db.execute(
          "INSERT INTO user_identities(id,user_id,type,external_id,verified_at,created_at) VALUES(?,?,?,?,?,?) ON CONFLICT(type,external_id) DO NOTHING",
          [Infrastructure::Database.id, user_id, provider, subject, Time.now.to_i, Time.now.to_i]
        )
      end

      def table_exists?(name)
        if @db.postgres?
          !!@db.one("SELECT to_regclass(?) AS name", [name])&.fetch("name", nil)
        else
          !!@db.one("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [name])
        end
      end
    end
  end
end
