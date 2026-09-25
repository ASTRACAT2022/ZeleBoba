module Integrations
  # Processes Telegram updates without making network calls. All outbound bot
  # API work is represented by durable outbox jobs.
  class TelegramUpdateService
    APP_URL = -> { Infrastructure::RuntimeConfig.fetch("APP_URL", "http://127.0.0.1:8080").to_s.sub(%r{/*\z}, "") }

    def initialize
      @outbox = Infrastructure::OutboxService.new
      @auth = Identity::TelegramAuthentication.new
    end

    def receive(update)
      raise Billing::BillingError, "Invalid update" unless update.is_a?(Hash)
      update_id = update["update_id"]
      raise Billing::BillingError, "Invalid update" unless update_id.is_a?(Integer) && update_id >= 0

      message = update["message"]
      callback = update["callback_query"]
      return unless valid_private_message?(message) || valid_private_callback?(callback)

      ApplicationRecord.transaction do
        inserted = TelegramUpdate.insert_all(
          [{ update_id: update_id, created_at: Time.now.to_i }],
          unique_by: %i[update_id],
          returning: %w[update_id]
        ).rows.any?
        next unless inserted

        callback ? handle_callback(update_id, callback) : handle_message(update_id, message)
      end
    end

    private

    def valid_private_message?(message)
      return false unless message.is_a?(Hash) && message.dig("chat", "type") == "private"
      sender = message.dig("from", "id")
      sender.is_a?(Integer) && sender.positive? && sender == message.dig("chat", "id")
    end

    def valid_private_callback?(callback)
      return false unless callback.is_a?(Hash) && callback.dig("message", "chat", "type") == "private"
      sender = callback.dig("from", "id")
      sender.is_a?(Integer) && sender.positive? && sender == callback.dig("message", "chat", "id")
    end

    def handle_message(update_id, message)
      telegram_id = message.dig("from", "id").to_s
      text = message["text"].to_s.strip
      command, argument = text.split(/\s+/, 2)
      command = command.to_s.downcase
      argument = argument.to_s.strip

      if text.start_with?("/start login_")
        token = text.delete_prefix("/start login_").split(/\s/).first
        user = @auth.telegram_user!(telegram_id)
        return gate_message(update_id, telegram_id, user) if gate_blocked?(user)
        return unless @auth.pending?(token)
        return reply(update_id, telegram_id,
          "Подтвердите вход на #{URI.parse(APP_URL.call).host}. Подтверждайте только запрос, который вы только что начали в браузере.",
          { inline_keyboard: [[{ text: "Это я, войти", callback_data: "login:#{token}" }]] })
      end

      # A web-issued link code is proof that the user is linking this Telegram
      # account. Resolve it before telegram_user! creates a standalone account
      # and claims the unique Telegram identity for the wrong user.
      if text.start_with?("/link ")
        ok = @auth.consume_link_code(token: argument, telegram_id: telegram_id)
        return reply(update_id, telegram_id, ok ? "Telegram подключён к веб-кабинету." : "Код недействителен. Получите новый в веб-кабинете.", menu)
      end

      user = @auth.telegram_user!(telegram_id)
      return if user.disabled.to_i != 0
      return gate_message(update_id, telegram_id, user) if gate_blocked?(user)

      if text.start_with?("/start ref_")
        Billing::ReferralService.new.attach_referrer(user.id, text.delete_prefix("/start ref_").split(/\s/).first)
        return welcome(update_id, telegram_id)
      end

      if text.start_with?("/start GIFT_")
        return claim_gift(update_id, telegram_id, user, text.delete_prefix("/start ").split(/\s/).first)
      end

      if text.start_with?("/start ")
        param = text.delete_prefix("/start ").split(/\s/).first
        campaign = Billing::CampaignService.new.register(user.id, param) rescue nil
        return reply(update_id, telegram_id, "🎁 Бонус кампании «#{campaign.name}» активирован!", menu) if campaign
      end

      return welcome(update_id, telegram_id) if %w[/start start].include?(command)
      return help(update_id, telegram_id) if %w[/help help].include?(command)
      return cabinet(update_id, telegram_id) if %w[/login login /cabinet cabinet].include?(command)
      return send_plans(update_id, telegram_id) if %w[/plans plans /tariffs].include?(command)
      return create_order(update_id, telegram_id, user, argument) if command == "/buy"
      return send_status(update_id, telegram_id, user) if %w[/status status].include?(command)
      return send_orders(update_id, telegram_id, user) if %w[/orders orders].include?(command)
      return send_subscriptions(update_id, telegram_id, user) if %w[/subs /mysubs subs].include?(command)
      return reply(update_id, telegram_id, "Поддержка: #{support_url}\nКабинет: #{APP_URL.call}", menu) if %w[/support support].include?(command)
      return activate_promo(update_id, telegram_id, user, argument) if command == "/promo"
      return start_trial(update_id, telegram_id, user, argument) if command == "/trial"
      return claim_gift(update_id, telegram_id, user, argument) if command == "/gift_claim"
      return purchase_gift(update_id, telegram_id, user, argument) if command == "/gift_buy"
      return balance(update_id, telegram_id, user) if %w[/balance balance /topup topup].include?(command) && argument.blank?
      return create_topup(update_id, telegram_id, user, argument) if command == "/topup"
      return referral(update_id, telegram_id, user) if %w[/ref /referral referral].include?(command)

      help(update_id, telegram_id)
    end

    def handle_callback(update_id, callback)
      telegram_id = callback.dig("from", "id").to_s
      data = callback["data"].to_s
      query_id = callback["id"].to_s
      @outbox.enqueue("telegram.answer", "answer:#{update_id}", { callback_query_id: query_id }) if query_id.present?

      if data.start_with?("login:")
        ok = @auth.approve_login(token: data.delete_prefix("login:"), telegram_id: telegram_id)
        return reply(update_id, telegram_id, ok ? "Вход подтверждён. Вернитесь в браузер и нажмите «Войти в кабинет»." : "Запрос уже обработан или истёк. Начните вход заново.")
      end

      user = @auth.telegram_user!(telegram_id)
      return reply(update_id, telegram_id, "Аккаунт отключён.") if user.disabled.to_i != 0
      if data.start_with?("chk:")
        channel_id = data.delete_prefix("chk:")
        channel = RequiredChannel.find_by(channel_id: channel_id, is_active: 1)
        return gate_message(update_id, telegram_id, user) unless channel
        if Rails.env.production? && ENV["RAILS_ONLY_TOPICS_ENABLED"] != "1"
          return reply(update_id, telegram_id, "Проверка подписки ещё не подключена. Повторите попытку позже.")
        end

        @outbox.enqueue("telegram.membership_check", "membership-check:#{update_id}", {
          update_id: update_id, user_id: user.id, telegram_id: telegram_id, channel_id: channel.channel_id
        })
        return reply(update_id, telegram_id, "Проверяю подписку. Напишу сюда, как только получу ответ от Telegram.")
      end
      return gate_message(update_id, telegram_id, user) if gate_blocked?(user)

      case data
      when "menu:main" then reply(update_id, telegram_id, "Меню. Кабинет: #{APP_URL.call}", menu)
      when "menu:plans" then send_plans(update_id, telegram_id)
      when "menu:subs" then send_subscriptions(update_id, telegram_id, user)
      when "menu:orders" then send_orders(update_id, telegram_id, user)
      when "menu:cabinet" then cabinet(update_id, telegram_id)
      when "menu:help" then help(update_id, telegram_id)
      else
        if data.start_with?("buy:")
          create_order(update_id, telegram_id, user, data.delete_prefix("buy:"))
        elsif data.start_with?("order:")
          order_details(update_id, telegram_id, user, data.delete_prefix("order:"))
        elsif data.start_with?("topup:")
          topup_details(update_id, telegram_id, user, data.delete_prefix("topup:"))
        elsif data.start_with?("plan:")
          plan_details(update_id, telegram_id, data.delete_prefix("plan:"))
        else
          reply(update_id, telegram_id, "Команда недоступна. Отправьте /help.", menu)
        end
      end
    end

    def enqueue_send(update_id, chat_id, text, markup = nil)
      payload = { chat_id: chat_id, text: text }
      payload[:reply_markup] = markup if markup
      @outbox.enqueue("telegram.send", "reply:#{update_id}", payload)
    end

    def reply(update_id, chat_id, text, markup = nil) = enqueue_send(update_id, chat_id, text, markup)

    def menu
      { inline_keyboard: [
        [{ text: "Тарифы", callback_data: "menu:plans" }, { text: "Мои подписки", callback_data: "menu:subs" }],
        [{ text: "Мои заказы", callback_data: "menu:orders" }, { text: "Кабинет", callback_data: "menu:cabinet" }],
        [{ text: "Помощь", callback_data: "menu:help" }]
      ] }
    end

    def welcome(update_id, chat_id)
      text = Infrastructure::RuntimeConfig.fetch("BRAND_WELCOME_TEXT", "").to_s
      text = "Привет! Это бот ZeleBoba.\n\nКабинет: #{APP_URL.call}" if text.blank?
      reply(update_id, chat_id, text, menu)
    end

    def help(update_id, chat_id)
      text = Infrastructure::RuntimeConfig.fetch("BRAND_HELP_TEXT", "").to_s
      text = "Команды:\n/plans — тарифы\n/buy <id> — создать заказ\n/status — подписки и ожидающие заказы\n/orders — мои заказы\n/subs — мои подписки\n/cabinet — кабинет\n/login — одноразовая ссылка входа\n/link <код> — привязать Telegram\n/promo <код> — промокод\n/topup <сумма> — пополнить баланс\n/support — поддержка" if text.blank?
      reply(update_id, chat_id, text, menu)
    end

    def send_plans(update_id, chat_id)
      plans = Plan.active.order(:price_minor).limit(30)
      return reply(update_id, chat_id, "Пока нет доступных тарифов.", menu) if plans.empty?
      lines = plans.map { |plan| "#{plan.name} · #{money(plan.price_minor)} ₽ / #{plan.duration_days} дн. · #{traffic(plan.traffic_bytes)} · #{plan.devices.to_i.zero? ? 'безлимит устройств' : "до #{plan.devices} устройств"}" }
      keyboard = plans.map { |plan| [{ text: "Купить #{plan.name} · #{money(plan.price_minor)} ₽", callback_data: "buy:#{plan.id}" }] }
      keyboard << [{ text: "Мои подписки", callback_data: "menu:subs" }, { text: "Меню", callback_data: "menu:main" }]
      reply(update_id, chat_id, "#{lines.join("\n")}\n\nВыберите тариф или отправьте /buy <id>.", { inline_keyboard: keyboard })
    end

    def plan_details(update_id, chat_id, plan_id)
      plan = Plan.active.find_by(id: plan_id)
      return reply(update_id, chat_id, "Тариф недоступен.", menu) unless plan
      text = "#{plan.name} · #{money(plan.price_minor)} ₽ / #{plan.duration_days} дн. · #{traffic(plan.traffic_bytes)}"
      reply(update_id, chat_id, text, { inline_keyboard: [[{ text: "Купить", callback_data: "buy:#{plan.id}" }], [{ text: "Назад", callback_data: "menu:plans" }]] })
    end

    def create_order(update_id, chat_id, user, plan_id)
      return send_plans(update_id, chat_id) if plan_id.blank?
      raise Billing::BillingError, "Некорректный тариф." unless plan_id.match?(/\A[a-zA-Z0-9:_-]{1,64}\z/)
      order = Billing::OrderService.new.create(user_id: user.id, plan_id: plan_id, idempotency_key: "telegram:#{update_id}")
      order.reload
      text = "Заказ #{order.plan_name} · #{money(order.price_minor)} ₽ · #{order_status(order.status)}"
      text += order.checkout_url.present? ? "\nОплатите по ссылке ниже." : "\nСсылка на оплату готовится. Обновите статус позже."
      markup = order.checkout_url.present? ?
        { inline_keyboard: [[{ text: "Оплатить", url: order.checkout_url }], [{ text: "Обновить статус", callback_data: "order:#{order.id}" }]] } :
        { inline_keyboard: [[{ text: "Обновить статус", callback_data: "order:#{order.id}" }, { text: "Мои заказы", callback_data: "menu:orders" }]] }
      reply(update_id, chat_id, "#{text}\nКабинет: #{APP_URL.call}/orders/#{order.id}", markup)
    rescue Billing::BillingError => error
      reply(update_id, chat_id, error.message, menu)
    end

    def order_details(update_id, chat_id, user, id)
      order = Order.find_by(id: id, user_id: user.id)
      return reply(update_id, chat_id, "Заказ не найден.", menu) unless order
      text = "Заказ #{order.plan_name} · #{money(order.price_minor)} ₽ · #{order_status(order.status)}"
      text += "\nОплата получена, настраиваем подписку." if order.status == "paid"
      if order.status == "fulfilled"
        sub = Subscription.find_by(order_id: order.id)
        text += "\nДоступ: #{sub.subscription_url}" if sub&.subscription_url.present?
        text += "\nДо #{Time.at(sub&.expires_at.to_i).utc.strftime('%d.%m.%Y %H:%M')} UTC."
      elsif order.pending?
        text += order.checkout_url.present? ? "\nОплатите по ссылке ниже." : "\nСсылка на оплату готовится."
      end
      markup = order.pending? && order.checkout_url.present? ?
        { inline_keyboard: [[{ text: "Оплатить", url: order.checkout_url }], [{ text: "Мои заказы", callback_data: "menu:orders" }]] } : menu
      reply(update_id, chat_id, "#{text}\nКабинет: #{APP_URL.call}/orders/#{order.id}", markup)
    end

    def send_status(update_id, chat_id, user)
      subs = Subscription.where(user_id: user.id).order(created_at: :desc).limit(5)
      lines = subs.map { |sub| "Подписка #{sub.plan&.name || 'VPN'}: #{sub.expires_at.to_i <= Time.now.to_i ? 'истекла' : sub.status} до #{Time.at(sub.expires_at.to_i).utc.strftime('%d.%m.%Y')}#{sub.status == 'active' && sub.expires_at.to_i > Time.now.to_i && sub.subscription_url.present? ? " · #{sub.subscription_url}" : ''}" }
      lines = ["Подписок пока нет."] if lines.empty?
      orders = Order.where(user_id: user.id, status: "pending").order(created_at: :desc).limit(3)
      keyboard = orders.map { |order| [{ text: "#{order.plan_name}: #{order.checkout_url.present? ? 'оплатить' : 'ссылка готовится'}", callback_data: "order:#{order.id}" }] }
      keyboard << [{ text: "Тарифы", callback_data: "menu:plans" }, { text: "Меню", callback_data: "menu:main" }]
      lines << "Неоплаченные заказы — нажмите, чтобы открыть:" if orders.exists?
      reply(update_id, chat_id, lines.join("\n"), { inline_keyboard: keyboard })
    end

    def send_orders(update_id, chat_id, user)
      orders = Order.where(user_id: user.id).order(created_at: :desc).limit(10)
      return reply(update_id, chat_id, "Заказов пока нет. Выберите тариф:", { inline_keyboard: [[{ text: "Тарифы", callback_data: "menu:plans" }]] }) if orders.empty?
      lines = orders.map { |order| "#{order.plan_name} · #{money(order.price_minor)} ₽ · #{order_status(order.status)}" }
      keyboard = orders.map { |order| [{ text: "#{order.plan_name} · #{order_status(order.status)}", callback_data: "order:#{order.id}" }] }
      keyboard << [{ text: "Меню", callback_data: "menu:main" }]
      reply(update_id, chat_id, "Ваши заказы (общие с веб-кабинетом):\n#{lines.join("\n")}", { inline_keyboard: keyboard })
    end

    def send_subscriptions(update_id, chat_id, user)
      subscriptions = Subscription.where(user_id: user.id).order(created_at: :desc).limit(10)
      return reply(update_id, chat_id, "Подписок пока нет. Выберите тариф:", { inline_keyboard: [[{ text: "Тарифы", callback_data: "menu:plans" }, { text: "Меню", callback_data: "menu:main" }]] }) if subscriptions.empty?
      lines = subscriptions.map { |sub| "#{sub.plan&.name || 'Подписка'}: #{sub.status} до #{Time.at(sub.expires_at.to_i).utc.strftime('%d.%m.%Y %H:%M')} UTC#{sub.subscription_url.present? && sub.expires_at.to_i > Time.now.to_i ? " · #{sub.subscription_url}" : ''}" }
      reply(update_id, chat_id, "Ваши подписки (общие с веб-кабинетом):\n#{lines.join("\n")}", menu)
    end

    def cabinet(update_id, chat_id)
      token = @auth.issue_magic_link(telegram_id: chat_id)
      reply(update_id, chat_id, "Одноразовая ссылка действует 5 минут. Не пересылайте её.",
        { inline_keyboard: [[{ text: "Открыть кабинет", url: "#{APP_URL.call}/telegram/magic##{token}" }]] })
    end

    def activate_promo(update_id, chat_id, user, code)
      return reply(update_id, chat_id, "Отправьте /promo <код>.", menu) if code.blank?
      result = Billing::PromoCodeService.new.activate(user_id: user.id, code: code)
      message = result[:success] ? result[:description] : result[:error].to_s
      reply(update_id, chat_id, message, menu)
    rescue Billing::BillingError => error
      reply(update_id, chat_id, error.message, menu)
    end

    def start_trial(update_id, chat_id, user, plan_id)
      return reply(update_id, chat_id, "Отправьте /trial <id тарифа>.", menu) if plan_id.blank?
      subscription = Billing::TrialService.new.start(user_id: user.id, plan_id: plan_id)
      reply(update_id, chat_id, "🎁 Триал активирован до #{Time.at(subscription.expires_at.to_i).utc.strftime('%d.%m.%Y')} UTC.", menu)
    rescue Billing::BillingError => error
      reply(update_id, chat_id, error.message, menu)
    end

    def claim_gift(update_id, chat_id, user, code)
      return reply(update_id, chat_id, "Отправьте /gift_claim <код>.", menu) if code.blank?
      purchase = Billing::GiftService.new.claim(claimant_id: user.id, input: code)
      reply(update_id, chat_id, "🎁 Подарок активирован! Подписка на #{purchase.period_days} дней оформляется. Отправьте /status для проверки.", menu)
    rescue Billing::BillingError => error
      reply(update_id, chat_id, "🎁 #{error.message}", menu)
    end

    def purchase_gift(update_id, chat_id, user, plan_id)
      return reply(update_id, chat_id, "Отправьте /gift_buy <id тарифа>.", menu) if plan_id.blank?
      purchase = Billing::GiftService.new.purchase_from_balance(buyer_id: user.id, plan_id: plan_id,
        idempotency_key: "tg-gift:#{update_id}:#{plan_id}", source: "bot")
      code = Billing::GiftService.new.public_code(purchase.token)
      reply(update_id, chat_id, "🎁 Подарок куплен! Код: <code>#{code}</code>\nОтправьте его другу. Активировать собственный подарок нельзя.", menu)
    rescue Billing::BillingError => error
      reply(update_id, chat_id, error.message, menu)
    end

    def balance(update_id, chat_id, user)
      amount = User.where(id: user.id).pick(:balance_kopeks).to_i
      reply(update_id, chat_id, "💰 Баланс: #{money(amount)} ₽\n\nОтправьте /topup <сумма>, например /topup 500.", menu)
    end

    def create_topup(update_id, chat_id, user, raw_amount)
      amount = Integer(raw_amount, 10)
      raise Billing::BillingError, "Сумма пополнения: от 1 до 1 000 000 ₽. Пример: /topup 500" unless amount.between?(1, 1_000_000)
      provider = AppSetting.find_by(name: "TOPUP_PROVIDER")&.value.presence || Infrastructure::RuntimeConfig.fetch("PAYMENT_DRIVER", "demo")
      topup = Billing::TopupService.new.create(user_id: user.id, amount_kopeks: amount * 100,
        idempotency_key: "telegram:#{update_id}", provider: provider)
      if topup.provider == "demo"
        raise Billing::BillingError, "Демо-пополнение доступно только вне production." if Rails.env.production? || Infrastructure::RuntimeConfig.fetch("APP_ENV", "") == "prod"
        Billing::TopupService.new.settle(topup.id, "demo", "demo_#{topup.id}", topup.amount_kopeks.to_i, topup.currency)
        return reply(update_id, chat_id, "Баланс пополнен на #{money(topup.amount_kopeks)} ₽ (демо).", menu)
      end
      refreshed = topup.reload
      keyboard = []
      keyboard << [{ text: "Оплатить", url: refreshed.checkout_url }] if refreshed.checkout_url.present?
      keyboard << [{ text: "Обновить статус", callback_data: "topup:#{topup.id}" }]
      reply(update_id, chat_id, "Пополнение на #{money(topup.amount_kopeks)} ₽ создано.#{refreshed.checkout_url.present? ? "\nОплатите по ссылке ниже." : "\nГотовим ссылку — нажмите «Обновить статус»."}", { inline_keyboard: keyboard })
    rescue ArgumentError, Billing::BillingError => error
      reply(update_id, chat_id, error.message.presence || "Укажите сумму в рублях, например /topup 500.", menu)
    end

    def topup_details(update_id, chat_id, user, id)
      topup = Topup.find_by(id: id, user_id: user.id)
      return reply(update_id, chat_id, "Пополнение не найдено.", menu) unless topup
      message = "Пополнение на #{money(topup.amount_kopeks)} ₽ · #{topup.status}"
      keyboard = []
      keyboard << [{ text: "Оплатить", url: topup.checkout_url }] if topup.pending? && topup.checkout_url.present?
      keyboard << [{ text: "Обновить статус", callback_data: "topup:#{topup.id}" }] if topup.pending?
      reply(update_id, chat_id, message, { inline_keyboard: keyboard.presence || menu[:inline_keyboard] })
    end

    def referral(update_id, chat_id, user)
      stats = Billing::ReferralService.new.stats(user.id)
      username = Infrastructure::RuntimeConfig.fetch("TELEGRAM_BOT_USERNAME", "").to_s.delete_prefix("@").strip
      text = "👥 Реферальная программа\n\nВаш код: <b>#{stats[:code]}</b>\nПриглашено: #{stats[:referrals].length}\nОплативших: #{stats[:paid_referrals]}\nЗаработано: #{money(stats[:earnings_kopeks])} ₽"
      text += "\n\nСсылка: https://t.me/#{username}?start=ref_#{stats[:code]}" if username.present?
      reply(update_id, chat_id, text, menu)
    end

    def gate_blocked?(user)
      channels = RequiredChannel.where(is_active: 1).order(:sort_order).pluck(:channel_id)
      return false if channels.empty?
      return true unless ApplicationRecord.connection.data_source_exists?("user_channel_subscriptions")
      cached = ApplicationRecord.connection.select_values(
        "SELECT channel_id FROM user_channel_subscriptions WHERE user_id = #{ApplicationRecord.connection.quote(user.id)} AND is_subscribed = 1"
      )
      (channels - cached).any?
    end

    def gate_message(update_id, chat_id, user)
      channels = RequiredChannel.where(is_active: 1).order(:sort_order)
      missing = channels.reject do |channel|
        ApplicationRecord.connection.data_source_exists?("user_channel_subscriptions") &&
          ApplicationRecord.connection.select_value("SELECT 1 FROM user_channel_subscriptions WHERE user_id = #{ApplicationRecord.connection.quote(user.id)} AND channel_id = #{ApplicationRecord.connection.quote(channel.channel_id)} AND is_subscribed = 1 LIMIT 1")
      end
      keyboard = missing.map do |channel|
        url = channel.channel_link.presence || "https://t.me/#{channel.channel_id.to_s.delete_prefix('@')}"
        [{ text: "📢 Подписаться: #{channel.title.presence || 'канал'}", url: url }]
      end
      keyboard << [{ text: "✅ Я подписался", callback_data: "chk:#{missing.first&.channel_id}" }]
      names = missing.map { |channel| channel.title.presence || channel.channel_id }.join(", ")
      reply(update_id, chat_id, "Чтобы пользоваться ботом, подпишитесь на обязательные каналы, затем нажмите проверку. #{names}", { inline_keyboard: keyboard })
    end

    def support_url = Infrastructure::RuntimeConfig.fetch("SUPPORT_URL", "").presence || "напишите администратору"
    def money(minor) = format("%.2f", minor.to_i / 100.0).sub(/\.00\z/, "")
    def traffic(bytes) = bytes.to_i.zero? ? "безлимит" : "#{(bytes.to_i / 1.gigabyte.to_f).round} ГБ"

    def order_status(status)
      { "pending" => "ожидает оплаты", "paid" => "оплачен, готовим доступ", "fulfilled" => "выполнен", "canceled" => "отменён" }.fetch(status.to_s, status.to_s)
    end
  end
end
