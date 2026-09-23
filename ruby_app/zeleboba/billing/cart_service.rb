# frozen_string_literal: true

require "json"

require_relative "error"

module Zeleboba
  module Billing
    class CartService
      def initialize(db)
        @db = db
      end

      def save(user_id, data, intent: false)
        raise Error, "Некорректная корзина." unless data.is_a?(Hash)
        payload = data.transform_keys(&:to_s).slice("kind", "plan_id", "subscription_id", "traffic_gb", "devices", "price_kopeks", "recipient_type", "recipient_value", "message", "idempotency_key")
        @db.execute(
          "INSERT INTO carts(user_id,data,intent,updated_at) VALUES(?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET data=excluded.data,intent=excluded.intent,updated_at=excluded.updated_at",
          [user_id, JSON.generate(payload), intent ? 1 : 0, Time.now.to_i]
        )
      end

      def fetch(user_id)
        row = @db.one("SELECT * FROM carts WHERE user_id=?", [user_id])
        return nil unless row

        JSON.parse(row["data"]).merge("_intent" => row["intent"].to_i == 1)
      rescue JSON::ParserError
        raise Error, "Корзина повреждена. Создайте покупку заново."
      end

      def clear_intent(user_id)
        @db.execute("UPDATE carts SET intent=0,updated_at=? WHERE user_id=?", [Time.now.to_i, user_id])
      end

      def delete(user_id)
        @db.execute("DELETE FROM carts WHERE user_id=?", [user_id])
      end
    end
  end
end
