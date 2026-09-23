# frozen_string_literal: true

require "digest"
require "securerandom"

require_relative "../billing/error"
require_relative "../infrastructure/database"

module Zeleboba
  module Identity
    class Mfa
      BASE32 = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567"

      def initialize(db, vault)
        @db = db
        @vault = vault
      end

      def begin_enrollment(user_id)
        @db.transaction do
          user = @db.one("SELECT totp_secret FROM users WHERE id=?#{@db.lock}", [user_id])
          raise Billing::Error, "Аккаунт не найден." unless user
          raise Billing::Error, "Двухфакторная защита уже включена." unless user["totp_secret"].to_s.empty?

          secret = Array.new(32) { BASE32[SecureRandom.random_number(BASE32.length)] }.join
          @db.execute(
            "INSERT INTO mfa_enrollments(user_id,secret,expires_at) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET secret=excluded.secret,expires_at=excluded.expires_at",
            [user_id, @vault.seal("mfa:#{user_id}", secret), Time.now.to_i + 600]
          )
          secret
        end
      end

      def enroll(user_id, code)
        @db.transaction do
          user = @db.one("SELECT totp_secret FROM users WHERE id=?#{@db.lock}", [user_id])
          raise Billing::Error, "Аккаунт не найден." unless user
          raise Billing::Error, "Двухфакторная защита уже включена." unless user["totp_secret"].to_s.empty?
          enrollment = @db.one("SELECT * FROM mfa_enrollments WHERE user_id=? AND expires_at>?", [user_id, Time.now.to_i])
          raise Billing::Error, "Настройка истекла. Начните снова." unless enrollment

          secret = @vault.open("mfa:#{user_id}", enrollment["secret"])
          step = valid_step(secret, code, 0)
          @db.execute("UPDATE users SET totp_secret=?,totp_last_step=? WHERE id=?", [enrollment["secret"], step, user_id])
          @db.execute("DELETE FROM mfa_enrollments WHERE user_id=?", [user_id])
          recovery_codes = Array.new(8) { SecureRandom.hex(8) }
          recovery_codes.each do |recovery_code|
            @db.execute("INSERT INTO mfa_recovery(user_id,code_hash) VALUES(?,?)", [user_id, Digest::SHA256.hexdigest(recovery_code)])
          end
          recovery_codes
        end
      end

      def verify(user_id, code)
        @db.transaction do
          user = @db.one("SELECT totp_secret,totp_last_step FROM users WHERE id=?#{@db.lock}", [user_id])
          raise Billing::Error, "Сначала включите двухфакторную защиту." unless user && !user["totp_secret"].to_s.empty?

          recovery_hash = Digest::SHA256.hexdigest(code.to_s)
          recovery = @db.one("SELECT code_hash FROM mfa_recovery WHERE user_id=? AND code_hash=?", [user_id, recovery_hash])
          if recovery
            @db.execute("DELETE FROM mfa_recovery WHERE user_id=? AND code_hash=?", [user_id, recovery_hash])
            return true
          end

          secret = @vault.open("mfa:#{user_id}", user["totp_secret"])
          step = valid_step(secret, code, user["totp_last_step"].to_i)
          @db.execute("UPDATE users SET totp_last_step=? WHERE id=?", [step, user_id])
          true
        end
      end

      def step_up(raw_session, seconds: 900)
        @db.execute("UPDATE sessions SET admin_verified_until=? WHERE id=?", [Time.now.to_i + Integer(seconds).clamp(60, 3600), Digest::SHA256.hexdigest(raw_session.to_s)])
      end

      def self.code(secret, step = Time.now.to_i / 30)
        key = decode_base32(secret)
        digest = OpenSSL::HMAC.digest("SHA1", key, [step.to_i].pack("Q>"))
        offset = digest.getbyte(19) & 0x0f
        value = digest[offset, 4].unpack1("N") & 0x7fff_ffff
        format("%06d", value % 1_000_000)
      end

      private

      def valid_step(secret, code, last_step)
        current = Time.now.to_i / 30
        [current - 1, current, current + 1].each do |step|
          return step if step > last_step && secure_equal?(self.class.code(secret, step), code.to_s)
        end
        raise Billing::Error, "Неверный или уже использованный код. Дождитесь нового кода."
      end

      def secure_equal?(left, right)
        left.bytesize == right.bytesize && OpenSSL.fixed_length_secure_compare(left, right)
      end

      def self.decode_base32(value)
        bits = value.to_s.upcase.chars.map do |character|
          index = BASE32.index(character)
          raise ArgumentError, "Invalid Base32 secret" unless index

          index.to_s(2).rjust(5, "0")
        end.join
        bits.scan(/.{8}/).map { |octet| octet.to_i(2) }.pack("C*")
      end
    end
  end
end
