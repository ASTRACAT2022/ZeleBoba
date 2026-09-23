# frozen_string_literal: true

require "digest"
require "securerandom"

require_relative "../billing/error"
require_relative "../infrastructure/database"

module Zeleboba
  module Identity
    class TelegramLogin
      def initialize(db, auth)
        @db = db
        @auth = auth
      end

      def begin
        token = SecureRandom.hex(24)
        browser = SecureRandom.hex(32)
        @db.execute(
          "INSERT INTO login_challenges(token_hash,browser_hash,kind,state,expires_at,created_at) VALUES(?,?,'browser','pending',?,?)",
          [sha(token), sha(browser), Time.now.to_i + 300, Time.now.to_i]
        )
        { "token" => token, "browser" => browser }
      end

      def approve(token, telegram_id)
        @db.transaction do
          row = @db.one(
            "SELECT * FROM login_challenges WHERE token_hash=? AND kind='browser' AND state='pending' AND expires_at>?#{@db.lock}",
            [sha(token), Time.now.to_i]
          )
          return false unless row

          user_id = telegram_user(telegram_id)
          @db.execute("UPDATE login_challenges SET state='approved',user_id=? WHERE token_hash=?", [user_id, row["token_hash"]])
          true
        end
      end

      def ready?(browser)
        !!@db.one(
          "SELECT token_hash FROM login_challenges WHERE browser_hash=? AND kind='browser' AND state='approved' AND expires_at>?",
          [sha(browser.to_s), Time.now.to_i]
        )
      end

      def magic(telegram_id)
        @db.transaction do
          user_id = telegram_user(telegram_id)
          token = SecureRandom.hex(32)
          @db.execute("UPDATE login_challenges SET state='consumed' WHERE user_id=? AND kind='magic' AND state='approved'", [user_id])
          @db.execute(
            "INSERT INTO login_challenges(token_hash,user_id,kind,state,expires_at,created_at) VALUES(?,?,'magic','approved',?,?)",
            [sha(token), user_id, Time.now.to_i + 300, Time.now.to_i]
          )
          token
        end
      end

      def consume(proof, kind)
        raise Billing::Error, "Ссылка входа недействительна." unless %w[browser magic].include?(kind) && proof.to_s.match?(/\A[a-f0-9]{64}\z/)

        @db.transaction do
          column = kind == "browser" ? "browser_hash" : "token_hash"
          row = @db.one(
            "SELECT * FROM login_challenges WHERE #{column}=? AND kind=? AND state='approved' AND expires_at>?#{@db.lock}",
            [sha(proof), kind, Time.now.to_i]
          )
          raise Billing::Error, "Ссылка уже использована или истекла. Получите новую в боте." unless row

          user = @db.one("SELECT disabled FROM users WHERE id=?", [row["user_id"]])
          raise Billing::Error, "Аккаунт отключён." unless user && user["disabled"].to_i.zero?

          session = @auth.issue(row["user_id"])
          @db.execute("UPDATE login_challenges SET state='consumed' WHERE token_hash=?", [row["token_hash"]])
          @db.execute("INSERT INTO audit_log VALUES(?,?,?,?,?)", [Infrastructure::Database.id, row["user_id"], "auth.telegram", row["user_id"], Time.now.to_i])
          session
        end
      end

      def telegram_user(telegram_id)
        telegram_id = telegram_id.to_s.strip
        raise Billing::Error, "Некорректный Telegram ID." unless telegram_id.match?(/\A[1-9][0-9]{0,19}\z/)

        user_id = identity_user_id("telegram", telegram_id)
        user_id ||= @db.one("SELECT id FROM users WHERE telegram_id=?", [telegram_id])&.fetch("id", nil)
        unless user_id
          user_id = Infrastructure::Database.id
          @db.execute("INSERT INTO users(id,telegram_id,created_at) VALUES(?,?,?)", [user_id, telegram_id, Time.now.to_i])
        end
        attach_identity(user_id, "telegram", telegram_id)
        user = @db.one("SELECT id,disabled FROM users WHERE id=?", [user_id])
        raise Billing::Error, "Аккаунт отключён." if user["disabled"].to_i == 1

        user["id"]
      end

      private

      def sha(value)
        Digest::SHA256.hexdigest(value.to_s)
      end

      def identity_user_id(type, external_id)
        return nil unless table_exists?("user_identities")

        @db.one("SELECT user_id FROM user_identities WHERE type=? AND external_id=?", [type, external_id])&.fetch("user_id", nil)
      end

      def attach_identity(user_id, type, external_id)
        return unless table_exists?("user_identities")

        @db.execute(
          "INSERT INTO user_identities(id,user_id,type,external_id,verified_at,created_at) VALUES(?,?,?,?,?,?) ON CONFLICT(type,external_id) DO NOTHING",
          [Infrastructure::Database.id, user_id, type, external_id, Time.now.to_i, Time.now.to_i]
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
