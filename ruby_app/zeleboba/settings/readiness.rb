# frozen_string_literal: true

module Zeleboba
  module Settings
    class Readiness
      def self.report(container)
        config = container.config
        now = Time.now.to_i
        checks = []
        add = ->(name, ok, detail) { checks << { "name" => name, "ok" => ok, "detail" => detail } }

        add.call("Production mode", config["APP_ENV"] == "prod", "Set APP_ENV=prod only after integration checks pass.")
        add.call("PostgreSQL", container.db.postgres?, "SQLite is development-only.")
        add.call("HTTPS", config["APP_URL"].to_s.start_with?("https://"), "APP_URL must be the public HTTPS address.")
        add.call("Payment provider", config["PAYMENT_DRIVER"] == "platega" && config["PLATEGA_ENABLED"] == "1", "Use Platega in production.")
        add.call("Payment credentials", !config["PLATEGA_MERCHANT_ID"].to_s.empty? && !config["PLATEGA_SECRET"].to_s.empty?, "Provide Platega merchant credentials.")
        add.call("Provisioning provider", config["PROVISION_DRIVER"] == "remnawave", "Demo provisioning is not production-safe.")
        add.call("MFA encryption", !config["MFA_ENCRYPTION_KEY"].to_s.empty?, "Provide a Base64 32-byte MFA_ENCRYPTION_KEY.")
        add.call("Worker queue", container.db.one("SELECT id FROM outbox WHERE status='dead' LIMIT 1").nil?, "Resolve dead-letter jobs before launch.")
        add.call("Plans", !container.db.one("SELECT id FROM plans WHERE active=1 LIMIT 1").nil?, "At least one active plan is required.")
        add.call("Workers", heartbeat_ok?(container, "worker", now) && heartbeat_ok?(container, "scheduler", now), "Worker and scheduler heartbeats must be newer than three minutes.") if table_exists?(container, "runtime_heartbeats")
        checks
      end

      def self.ready?(container)
        report(container).all? { |check| check["ok"] }
      end

      def self.heartbeat_ok?(container, name, now)
        container.db.one("SELECT seen_at FROM runtime_heartbeats WHERE name=?", [name])&.fetch("seen_at", 0).to_i > now - 180
      end
      private_class_method :heartbeat_ok?

      def self.table_exists?(container, name)
        if container.db.postgres?
          !!container.db.one("SELECT to_regclass(?) AS name", [name])&.fetch("name", nil)
        else
          !!container.db.one("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [name])
        end
      end
      private_class_method :table_exists?
    end
  end
end
