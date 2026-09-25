require "rbnacl"

module Identity
  class Vault
    def initialize
      @path = ENV.fetch("ZELEBOBA_MASTER_KEY_FILE", Rails.root.parent.join("var/master.key").to_s)
    end

    def seal(name, value)
      key = load_key(create: true)
      cipher = RbNaCl::AEAD::XChaCha20Poly1305IETF.new(key)
      nonce = RbNaCl::Random.random_bytes(RbNaCl::AEAD::XChaCha20Poly1305IETF.nonce_bytes)
      Base64.strict_encode64(nonce + cipher.encrypt(nonce, value, name))
    end

    def open(name, encoded)
      raw = Base64.strict_decode64(encoded)
      nonce_size = RbNaCl::AEAD::XChaCha20Poly1305IETF.nonce_bytes
      raise Billing::BillingError, "Encrypted MFA secret is invalid." if raw.bytesize < nonce_size + 16

      RbNaCl::AEAD::XChaCha20Poly1305IETF.new(load_key(create: false)).decrypt(raw.byteslice(0, nonce_size), raw.byteslice(nonce_size..), name)
    rescue ArgumentError, RbNaCl::CryptoError
      raise Billing::BillingError, "Cannot decrypt MFA secret; restore the original master key."
    end

    private

    def load_key(create:)
      if ENV["ZELEBOBA_MASTER_KEY_BASE64"].present?
        key = Base64.strict_decode64(ENV.fetch("ZELEBOBA_MASTER_KEY_BASE64"))
        raise Billing::BillingError, "Master key must contain 32 bytes." unless key.bytesize == 32
        return key
      end

      if !File.exist?(@path) && create
        FileUtils.mkdir_p(File.dirname(@path), mode: 0o700)
        File.open(@path, File::WRONLY | File::CREAT | File::EXCL, 0o600) { |file| file.write(RbNaCl::Random.random_bytes(32)) }
      end
      raise Billing::BillingError, "Master key is missing." unless File.file?(@path)

      key = File.binread(@path)
      raise Billing::BillingError, "Master key must contain 32 bytes." unless key.bytesize == 32
      key
    end
  end
end
