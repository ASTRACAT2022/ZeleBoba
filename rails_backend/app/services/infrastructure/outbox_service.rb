module Infrastructure
  class OutboxService
    RAILS_ONLY_TOPICS = %w[subscription.remove telegram.membership_check].freeze
    PRIORITY = {
      "payment.verify" => 100,
      "payment.event.process" => 100,
      "payment.create" => 90,
      "topup.create" => 90,
      "topup.after" => 90,
      "referral.topup" => 90,
      "subscription.provision" => 80,
      "subscription.extend" => 80,
      "subscription.renew" => 80,
      "subscription.traffic" => 80,
      "subscription.devices" => 80,
      "subscription.admin_sync" => 80,
      "subscription.remove" => 85,
      "gift.create" => 70,
      "telegram.send" => 60,
      "telegram.answer" => 60,
      "telegram.membership_check" => 60,
      "compensation.run" => 50,
      "compensation.grant" => 50,
      "broadcast.send" => 10,
      "broadcast.run" => 5
    }.freeze

    def enqueue(topic, key, payload, delay: 0, correlation_id: nil)
      if RAILS_ONLY_TOPICS.include?(topic.to_s) && ENV["RAILS_ONLY_TOPICS_ENABLED"] != "1"
        raise Billing::BillingError, "Rails-only worker topics are disabled until PHP workers are drained."
      end

      OutboxJob.insert_all(
        [{
          id: IdGenerator.call,
          topic: topic,
          dedup_key: key,
          payload: JSON.generate(payload),
          priority: PRIORITY.fetch(topic, 30),
          available_at: Time.now.to_i + delay,
          created_at: Time.now.to_i,
          correlation_id: correlation_id
        }],
        unique_by: :dedup_key
      )
    end
  end
end
