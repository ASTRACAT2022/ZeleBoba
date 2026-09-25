module Integrations
  class TelegramMembershipCheckService
    def initialize(telegram: TelegramClient.new, outbox: Infrastructure::OutboxService.new)
      @telegram = telegram
      @outbox = outbox
    end

    def perform(payload)
      channel = RequiredChannel.find_by(channel_id: payload.fetch("channel_id"), is_active: 1)
      user = User.find_by(id: payload.fetch("user_id"), telegram_id: payload.fetch("telegram_id"))
      return unless channel && user && user.disabled.to_i.zero?

      subscribed = @telegram.check_membership(channel_id: channel.channel_id, user_id: user.telegram_id)
      now = Time.now.to_i
      row = UserChannelSubscription.find_or_initialize_by(user_id: user.id, channel_id: channel.channel_id)
      row.id ||= Infrastructure::IdGenerator.call
      row.assign_attributes(is_subscribed: subscribed ? 1 : 0, checked_at: now)
      row.save!

      missing = RequiredChannel.where(is_active: 1).where.not(channel_id: UserChannelSubscription.where(user_id: user.id, is_subscribed: 1).select(:channel_id)).order(:sort_order)
      markup = missing.map do |required|
        link = required.channel_link.presence || "https://t.me/#{required.channel_id.to_s.delete_prefix('@')}"
        [{ text: "📢 Подписаться: #{required.title.presence || 'канал'}", url: link }]
      end
      if missing.exists?
        next_channel = missing.first
        markup << [{ text: "✅ Я подписался", callback_data: "chk:#{next_channel.channel_id}" }]
        message = subscribed ? "✅ Подписка подтверждена. Подпишитесь на оставшиеся каналы и нажмите проверку." : "Подписка на канал не найдена. Вступите в канал и нажмите проверку ещё раз."
      else
        markup = [[{ text: "Открыть кабинет", url: Infrastructure::RuntimeConfig.fetch("APP_URL", "http://127.0.0.1:8080") }]]
        message = "✅ Подписка подтверждена. Доступ к боту открыт."
      end

      @outbox.enqueue("telegram.send", "membership-result:#{payload.fetch('update_id')}", {
        chat_id: user.telegram_id, text: message, reply_markup: { inline_keyboard: markup }
      })
    end
  end
end
