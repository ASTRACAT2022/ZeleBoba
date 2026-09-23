# frozen_string_literal: true

require_relative "../billing/error"
require_relative "../infrastructure/state_machine"
require_relative "platega_client"

module Zeleboba
  module Payments
    class PaymentService
      def initialize(db, billing, topups, event_store, config, client: nil, webhook_guard: nil)
        @db = db
        @billing = billing
        @topups = topups
        @events = event_store
        @config = config
        @client = client || PlategaClient.new(config)
        @webhook_guard = webhook_guard
      end

      def create_order(order_id)
        order = @db.one("SELECT * FROM orders WHERE id=?", [order_id])
        return {} unless order && order["status"] == "pending"

        create_checkout(order, "order")
      end

      def create_topup(topup_id)
        topup = @db.one("SELECT * FROM topups WHERE id=?", [topup_id])
        return {} unless topup && topup["status"] == "pending"

        create_checkout(topup, "topup")
      end

      def process_event(event_id)
        event = @events.claim(event_id)
        return false unless event

        begin
          process_claimed_event(event)
          @events.processed(event["id"], event.fetch("lock_token"))
          @webhook_guard&.mark_processed(event["provider"], event["provider_event_id"])
          true
        rescue Billing::Error => e
          @events.failed(event["id"], event.fetch("lock_token"), Infrastructure::JobPermanentFailure.new(e.message))
          false
        rescue StandardError => e
          @events.failed(event["id"], event.fetch("lock_token"), e)
          raise
        end
      end

      private

      def create_checkout(entity, kind)
        return { "payment_id" => entity["provider_payment_id"], "checkout_url" => entity["checkout_url"] } if entity["checkout_url"]

        if entity["provider"] == "demo"
          raise Billing::Error, "Демоплатёж запрещён." if @config["APP_ENV"] == "prod"

          payment_id = "demo_#{entity["id"]}"
          checkout_url = kind == "topup" ? "/balance/topup/#{entity["id"]}" : "/orders/#{entity["id"]}"
        elsif entity["provider"] == "platega"
          user = @db.one("SELECT * FROM users WHERE id=?", [entity.fetch("user_id")]) || {}
          result = @client.create(entity: entity, user: user, kind: kind)
          payment_id = result.fetch("payment_id")
          checkout_url = result.fetch("checkout_url")
        else
          raise Billing::Error, "Платёжный провайдер не поддерживается."
        end

        table = kind == "topup" ? "topups" : "orders"
        @db.execute("UPDATE #{table} SET provider_payment_id=?,checkout_url=? WHERE id=? AND status='pending' AND checkout_url IS NULL", [payment_id, checkout_url, entity["id"]])
        { "payment_id" => payment_id, "checkout_url" => checkout_url }
      end

      def process_claimed_event(event)
        raise Infrastructure::JobPermanentFailure, "Unknown payment provider." unless event["provider"] == "platega"

        verified = @client.verify(event.fetch("payment_id"))
        case verified.fetch("status")
        when "paid"
          settle_verified(event, verified)
        when "canceled"
          cancel_verified(event, verified)
        when "pending"
          raise Infrastructure::JobDeferred.new("Payment is still pending", delay: 60)
        else
          raise Infrastructure::JobPermanentFailure, "Unknown provider payment status."
        end
      end

      def settle_verified(event, verified)
        payment_id = verified.fetch("payment_id")
        orders = @db.all("SELECT * FROM orders WHERE provider=? AND provider_payment_id=?", [event["provider"], payment_id])
        topups = @db.all("SELECT * FROM topups WHERE provider=? AND provider_payment_id=?", [event["provider"], payment_id])
        raise Billing::Error, "Неоднозначная привязка платежа." unless orders.length + topups.length == 1

        if (order = orders.first)
          @billing.settle(order["id"], event["provider"], payment_id, verified.fetch("amount_kopeks"), verified.fetch("currency"))
        else
          @topups.settle(topups.first["id"], event["provider"], payment_id, verified.fetch("amount_kopeks"), verified.fetch("currency"))
        end
      end

      def cancel_verified(event, verified)
        payment_id = verified.fetch("payment_id")
        order = @db.one("SELECT * FROM orders WHERE provider=? AND provider_payment_id=?", [event["provider"], payment_id])
        topup = @db.one("SELECT * FROM topups WHERE provider=? AND provider_payment_id=?", [event["provider"], payment_id])
        raise Billing::Error, "Неоднозначная привязка платежа." if order && topup
        entity = order || topup
        return unless entity && entity["status"] == "pending"

        Infrastructure::StateMachine.assert!(order ? "order" : "topup", entity["status"], "canceled")
        @db.execute("UPDATE #{order ? "orders" : "topups"} SET status='canceled' WHERE id=? AND status='pending'", [entity["id"]])
      end
    end
  end
end
