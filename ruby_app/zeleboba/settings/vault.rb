# frozen_string_literal: true

require "base64"
require "openssl"
require "securerandom"

module Zeleboba
  module Settings
    # Encrypts high-value application secrets at rest. Production requires an
    # externally managed key; development and tests may use an ephemeral key.
    class Vault
      def initialize(config)
        @config = config
        @ephemeral_key = nil
      end

      def seal(context, plaintext)
        nonce = SecureRandom.random_bytes(12)
        cipher = OpenSSL::Cipher.new("aes-256-gcm")
        cipher.encrypt
        cipher.key = key
        cipher.iv = nonce
        cipher.auth_data = context.to_s
        encrypted = cipher.update(plaintext.to_s) + cipher.final
        Base64.strict_encode64(nonce + cipher.auth_tag + encrypted)
      end

      def open(context, ciphertext)
        blob = Base64.strict_decode64(ciphertext.to_s)
        raise "Invalid encrypted value" if blob.bytesize < 29

        cipher = OpenSSL::Cipher.new("aes-256-gcm")
        cipher.decrypt
        cipher.key = key
        cipher.iv = blob[0, 12]
        cipher.auth_tag = blob[12, 16]
        cipher.auth_data = context.to_s
        cipher.update(blob[28..]) + cipher.final
      rescue ArgumentError, OpenSSL::Cipher::CipherError
        raise "Cannot decrypt protected value"
      end

      private

      def key
        encoded = @config["MFA_ENCRYPTION_KEY"].to_s
        return decode_key(encoded) unless encoded.empty?
        raise "MFA_ENCRYPTION_KEY is required in production." if @config["APP_ENV"] == "prod"

        @ephemeral_key ||= SecureRandom.random_bytes(32)
      end

      def decode_key(value)
        raw = Base64.strict_decode64(value)
        raise "MFA_ENCRYPTION_KEY must contain 32 bytes." unless raw.bytesize == 32

        raw
      rescue ArgumentError
        raise "MFA_ENCRYPTION_KEY must be Base64 encoded."
      end
    end
  end
end
