# frozen_string_literal: true

require "digest"
require "securerandom"

require_relative "../billing/error"
require_relative "../infrastructure/database"

module Zeleboba
  module Integration
    # Receives only private Telegram updates. All responses are persisted in the
    # outbox, so Telegram webhooks stay fast and delivery is retried by Worker.
    class TelegramBot
      def initialize(db:, outbox:, config:, telegram_login:, billing:, wallet:, subscriptions:, renewals:, gifts:, promocodes:, referrals:, topups:, trials:)
        @db = db
        @outbox = outbox
        @config = config
        @telegram_login = telegram_login
        @billing = billing
        @wallet = wallet
        @subscriptions = subscriptions
        @renewals = renewals
        @gifts = gifts
        @promocodes = promocodes
        @referrals = referrals
        @topups = topups
        @trials = trials
      end

      def receive(update)
        update_id = integer(update["update_id"])
        return false unless update_id

        event = private_event(update)
        return false unless event
        return false unless reserve_update(update_id)

        enqueue_callback_answer(update_id, event[:callback_query_id]) if event[:callback_query_id]
        dispatch(update_id, event)
        true
      rescue Billing::Error => e
        reply(update_id, event[:chat_id], e.message, main_menu) if update_id && event
        true
      end

      private

      def private_event(update)
        callback = update["callback_query"]
        message = update["message"] || callback&.fetch("message", nil)
        sender = update.dig("message", "from") || callback&.fetch("from", nil)
        return nil unless message.is_a?(Hash) && sender.is_a?(Hash)
        return nil unless message.dig("chat", "type") == "private"

        chat_id = message.dig("chat", "id").to_s
        telegram_id = sender["id"].to_s
        return nil unless valid_telegram_id?(chat_id) && chat_id == telegram_id

        {
          chat_id: chat_id,
          telegram_id: telegram_id,
          text: update.dig("message", "text").to_s.strip,
          callback_data: callback&.fetch("data", "").to_s,
          callback_query_id: callback&.fetch("id", nil).to_s
        }
      end

      def reserve_update(update_id)
        return false if @db.one("SELECT update_id FROM telegram_updates WHERE update_id=?", [update_id])

        @db.execute("INSERT INTO telegram_updates(update_id,created_at) VALUES(?,?)", [update_id, Time.now.to_i])
        true
      rescue Infrastructure::Database::ConstraintError
        false
      end

      def dispatch(update_id, event)
        if event[:callback_data].empty?
          dispatch_message(update_id, event)
        else
          dispatch_callback(update_id, event)
        end
      end

      def dispatch_message(update_id, event)
        command, argument = command_parts(event[:text])
        start_parameter = command == "/start" ? argument : nil

        if (login_match = start_parameter&.match(/\Alogin_([a-f0-9]{48})\z/i))
          approved = @telegram_login.approve(login_match[1], event[:telegram_id])
          return reply(update_id, event[:chat_id], approved ? "Вход подтверждён. Вернитесь в браузер." : "Запрос входа уже обработан или истёк.")
        end

        if start_parameter&.start_with?("ref_")
          user = user_for(event[:telegram_id])
          @referrals.attach_referrer(user["id"], start_parameter.delete_prefix("ref_"))
          return welcome(update_id, event[:chat_id])
        end

        if start_parameter&.start_with?("GIFT_")
          user = user_for(event[:telegram_id])
          gift = @gifts.claim(user["id"], start_parameter)
          return reply(update_id, event[:chat_id], "Подарок активирован. Подписка на #{gift["period_days"]} дн. оформляется.", main_menu)
        end

        return welcome(update_id, event[:chat_id]) if ["/start", "start"].include?(command)
        return help(update_id, event[:chat_id]) if ["/help", "help"].include?(command)
        return link_account(update_id, event[:telegram_id], event[:chat_id], argument) if command == "/link"

        user = user_for(event[:telegram_id])
        case command
        when "/login", "login", "/cabinet", "cabinet"
          send_magic_link(update_id, event[:chat_id], event[:telegram_id])
        when "/plans", "plans", "/tariffs"
          send_plans(update_id, event[:chat_id])
        when "/buy"
          argument.empty? ? send_plans(update_id, event[:chat_id], "Выберите тариф для покупки:") : buy_plan(update_id, event[:chat_id], user, argument)
        when "/status", "status", "/subs", "subs", "/mysubs"
          send_subscriptions(update_id, event[:chat_id], user)
        when "/orders", "orders"
          send_orders(update_id, event[:chat_id], user)
        when "/balance", "balance", "/topup", "topup"
          argument.empty? ? send_balance(update_id, event[:chat_id], user) : create_topup(update_id, event[:chat_id], user, argument)
        when "/promo"
          activate_promo(update_id, event[:chat_id], user, argument)
        when "/gift_buy"
          buy_gift(update_id, event[:chat_id], user, argument)
        when "/gift_claim"
          claim_gift(update_id, event[:chat_id], user, argument)
        when "/gifts", "gifts", "/gift", "gift"
          send_gifts(update_id, event[:chat_id], user)
        when "/referral", "referral", "/ref", "ref"
          send_referral(update_id, event[:chat_id], user)
        when "/trial"
          start_trial(update_id, event[:chat_id], user, argument)
        when "/support", "support"
          reply(update_id, event[:chat_id], "Поддержка: #{support_url}\nКабинет: #{app_url}", main_menu)
        when "/myid", "myid", "/id", "id"
          reply(update_id, event[:chat_id], "Ваш Telegram chat_id: #{event[:telegram_id]}", main_menu)
        else
          help(update_id, event[:chat_id])
        end
      end

      def dispatch_callback(update_id, event)
        data = event[:callback_data]
        if (login_match = data.match(/\Alogin:([a-f0-9]{48})\z/i))
          approved = @telegram_login.approve(login_match[1], event[:telegram_id])
          return reply(update_id, event[:chat_id], approved ? "Вход подтверждён. Вернитесь в браузер." : "Запрос входа уже обработан или истёк.")
        end

        user = user_for(event[:telegram_id])
        case data
        when "menu:main" then welcome(update_id, event[:chat_id])
        when "menu:plans" then send_plans(update_id, event[:chat_id])
        when "menu:subs" then send_subscriptions(update_id, event[:chat_id], user)
        when "menu:orders" then send_orders(update_id, event[:chat_id], user)
        when "menu:balance" then send_balance(update_id, event[:chat_id], user)
        when "menu:cabinet" then send_magic_link(update_id, event[:chat_id], event[:telegram_id])
        when "menu:help" then help(update_id, event[:chat_id])
        else
          dispatch_action_callback(update_id, event[:chat_id], user, data)
        end
      end

      def dispatch_action_callback(update_id, chat_id, user, data)
        if (match = data.match(/\Aplan:([a-zA-Z0-9_-]{1,32})\z/))
          plan = active_plan(match[1])
          return reply(update_id, chat_id, plan_text(plan), { "inline_keyboard" => [[{ "text" => "Купить · #{money(plan["price_minor"])}", "callback_data" => "buy:#{plan["id"]}" }], [{ "text" => "Назад", "callback_data" => "menu:plans" }]] })
        end
        if (match = data.match(/\Abuy:([a-zA-Z0-9_-]{1,32})\z/))
          return buy_plan(update_id, chat_id, user, match[1])
        end
        if (match = data.match(/\Aorder:([a-f0-9]{32})\z/))
          order = @db.one("SELECT * FROM orders WHERE id=? AND user_id=?", [match[1], user["id"]])
          return reply(update_id, chat_id, order ? order_text(order) : "Заказ не найден.", main_menu)
        end
        if (match = data.match(/\Aautorenew:([a-f0-9]{32})\z/))
          subscription = @db.one("SELECT auto_renew FROM subscriptions WHERE id=? AND user_id=?", [match[1], user["id"]])
          return reply(update_id, chat_id, "Подписка не найдена.", main_menu) unless subscription

          updated = @renewals.set_auto_renew(user["id"], match[1], subscription["auto_renew"].to_i.zero?)
          return reply(update_id, chat_id, "Автопродление #{updated["auto_renew"].to_i == 1 ? "включено" : "выключено"}.", main_menu)
        end
        if (match = data.match(/\Atopup:([a-f0-9]{32})\z/))
          topup = @db.one("SELECT * FROM topups WHERE id=? AND user_id=?", [match[1], user["id"]])
          return reply(update_id, chat_id, topup ? topup_text(topup) : "Пополнение не найдено.", main_menu)
        end

        reply(update_id, chat_id, "Действие больше недоступно.", main_menu)
      end

      def welcome(update_id, chat_id)
        reply(update_id, chat_id, "Добро пожаловать в #{site_name}. Управляйте подпиской и балансом прямо в Telegram.", main_menu)
      end

      def help(update_id, chat_id)
        reply(update_id, chat_id, "Команды:\n/plans - тарифы\n/buy <тариф> - купить\n/status - подписки\n/orders - заказы\n/balance - баланс\n/topup <сумма> - пополнить\n/promo <код> - промокод\n/gift_buy <тариф> - подарок\n/gift_claim <код> - активировать подарок\n/referral - реферальная программа\n/cabinet - вход в кабинет", main_menu)
      end

      def send_plans(update_id, chat_id, prefix = nil)
        plans = @db.all("SELECT * FROM plans WHERE active=1 ORDER BY price_minor ASC LIMIT 20")
        return reply(update_id, chat_id, "Сейчас нет доступных тарифов.", main_menu) if plans.empty?

        keyboard = plans.map { |plan| [{ "text" => "#{plan["name"]} · #{money(plan["price_minor"])}", "callback_data" => "plan:#{plan["id"]}" }] }
        keyboard << [{ "text" => "Меню", "callback_data" => "menu:main" }]
        reply(update_id, chat_id, [prefix, "Тарифы:", *plans.map { |plan| plan_text(plan) }].compact.join("\n\n"), { "inline_keyboard" => keyboard })
      end

      def buy_plan(update_id, chat_id, user, plan_id)
        plan = active_plan(plan_id)
        order = @billing.order(user["id"], plan["id"], "telegram:order:#{update_id}:#{plan["id"]}")
        reply(update_id, chat_id, "Заказ создан. #{order_text(order)}\nСсылка на оплату появится в кабинете после обработки.", { "inline_keyboard" => [[{ "text" => "Открыть кабинет", "url" => "#{app_url}/orders/#{order["id"]}" }], [{ "text" => "Мои заказы", "callback_data" => "menu:orders" }]] })
      end

      def send_subscriptions(update_id, chat_id, user)
        subscriptions = @db.all("SELECT s.*,COALESCE(o.plan_name,p.name) AS plan_name FROM subscriptions s LEFT JOIN orders o ON o.id=s.order_id LEFT JOIN plans p ON p.id=s.plan_id WHERE s.user_id=? ORDER BY s.created_at DESC LIMIT 10", [user["id"]])
        return reply(update_id, chat_id, "Подписок пока нет. Выберите тариф.", { "inline_keyboard" => [[{ "text" => "Тарифы", "callback_data" => "menu:plans" }], [{ "text" => "Меню", "callback_data" => "menu:main" }]] }) if subscriptions.empty?

        text = "Ваши подписки:\n" + subscriptions.map { |subscription| subscription_text(subscription) }.join("\n\n")
        keyboard = subscriptions.filter_map do |subscription|
          next unless subscription["status"] == "active" && subscription["expires_at"].to_i > Time.now.to_i

          [{ "text" => subscription["auto_renew"].to_i == 1 ? "Выключить автопродление" : "Включить автопродление", "callback_data" => "autorenew:#{subscription["id"]}" }]
        end
        keyboard << [{ "text" => "Меню", "callback_data" => "menu:main" }]
        reply(update_id, chat_id, text, { "inline_keyboard" => keyboard })
      end

      def send_orders(update_id, chat_id, user)
        orders = @db.all("SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 10", [user["id"]])
        return reply(update_id, chat_id, "Заказов пока нет.", main_menu) if orders.empty?

        keyboard = orders.map { |order| [{ "text" => "#{order["plan_name"]} · #{order["status"]}", "callback_data" => "order:#{order["id"]}" }] }
        keyboard << [{ "text" => "Меню", "callback_data" => "menu:main" }]
        reply(update_id, chat_id, "Ваши заказы:\n" + orders.map { |order| order_text(order) }.join("\n"), { "inline_keyboard" => keyboard })
      end

      def send_balance(update_id, chat_id, user)
        balance = @wallet.balance(user["id"])["balance_kopeks"]
        reply(update_id, chat_id, "Баланс: #{money(balance)}\nПополнение: /topup <сумма в рублях>", { "inline_keyboard" => [[{ "text" => "Кабинет", "url" => "#{app_url}/balance" }], [{ "text" => "Меню", "callback_data" => "menu:main" }]] })
      end

      def create_topup(update_id, chat_id, user, amount)
        kopeks = rubles_to_kopeks(amount)
        topup = @topups.create(user["id"], kopeks, "telegram:topup:#{update_id}")
        reply(update_id, chat_id, "#{topup_text(topup)}\nСсылка на оплату появится в кабинете после обработки.", { "inline_keyboard" => [[{ "text" => "Открыть оплату", "url" => "#{app_url}/balance/topup/#{topup["id"]}" }], [{ "text" => "Статус", "callback_data" => "topup:#{topup["id"]}" }]] })
      end

      def activate_promo(update_id, chat_id, user, code)
        return reply(update_id, chat_id, "Использование: /promo <код>", main_menu) if code.empty?

        result = @promocodes.activate(user["id"], code)
        reply(update_id, chat_id, result["success"] ? result["description"] : promo_error(result["error"]), main_menu)
      end

      def buy_gift(update_id, chat_id, user, plan_id)
        return reply(update_id, chat_id, "Использование: /gift_buy <id тарифа>", main_menu) if plan_id.empty?

        gift = @gifts.purchase_from_balance(user["id"], plan_id, "telegram:gift:#{update_id}:#{plan_id}")
        reply(update_id, chat_id, "Подарок куплен. Код: #{@gifts.public_code(gift["token"])}\nПередайте его получателю.", main_menu)
      end

      def claim_gift(update_id, chat_id, user, code)
        return reply(update_id, chat_id, "Использование: /gift_claim <код>", main_menu) if code.empty?

        gift = @gifts.claim(user["id"], code)
        reply(update_id, chat_id, "Подарок активирован. Подписка на #{gift["period_days"]} дн. оформляется.", main_menu)
      end

      def send_gifts(update_id, chat_id, user)
        bought = @gifts.bought_by(user["id"])
        received = @gifts.received_by(user["id"])
        text = "Подарки: куплено #{bought.length}, получено #{received.length}.\nКупить: /gift_buy <id тарифа>\nАктивировать: /gift_claim <код>"
        reply(update_id, chat_id, text, main_menu)
      end

      def send_referral(update_id, chat_id, user)
        stats = @referrals.stats(user["id"])
        link = telegram_username.empty? ? "Код: #{stats["code"]}" : "https://t.me/#{telegram_username}?start=ref_#{stats["code"]}"
        reply(update_id, chat_id, "Реферальная ссылка: #{link}\nПриглашено: #{stats["referrals"].length}\nС оплатой: #{stats["paid_referrals"]}\nЗаработано: #{money(stats["earnings_kopeks"])}", main_menu)
      end

      def start_trial(update_id, chat_id, user, plan_id)
        return reply(update_id, chat_id, "Использование: /trial <id тарифа>", main_menu) if plan_id.empty?

        subscription = @trials.start(user["id"], plan_id)
        reply(update_id, chat_id, "Триал активирован до #{date(subscription["expires_at"])}.", main_menu)
      end

      def send_magic_link(update_id, chat_id, telegram_id)
        token = @telegram_login.magic(telegram_id)
        reply(update_id, chat_id, "Одноразовая ссылка действует 5 минут. Не пересылайте её.", { "inline_keyboard" => [[{ "text" => "Открыть кабинет", "url" => "#{app_url}/telegram/magic?token=#{token}" }]] })
      end

      def link_account(update_id, telegram_id, chat_id, token)
        return reply(update_id, chat_id, "Использование: /link <код из веб-кабинета>", main_menu) unless token.match?(/\A[a-f0-9]{48}\z/i)

        @db.transaction do
          link = @db.one("SELECT * FROM telegram_links WHERE token_hash=? AND expires_at>?#{@db.lock}", [Digest::SHA256.hexdigest(token), Time.now.to_i])
          return reply(update_id, chat_id, "Код недействителен. Получите новый в веб-кабинете.", main_menu) unless link

          target = @db.one("SELECT * FROM users WHERE id=?#{@db.lock}", [link["user_id"]])
          existing = @db.one("SELECT user_id FROM user_identities WHERE type='telegram' AND external_id=?", [telegram_id])
          if !target || (existing && existing["user_id"] != target["id"]) || (!target["telegram_id"].to_s.empty? && target["telegram_id"] != telegram_id)
            return reply(update_id, chat_id, "Этот Telegram уже привязан к другому аккаунту.", main_menu)
          end

          @db.execute("UPDATE users SET telegram_id=? WHERE id=?", [telegram_id, target["id"]])
          @db.execute("INSERT INTO user_identities(id,user_id,type,external_id,verified_at,created_at) VALUES(?,?,?,?,?,?) ON CONFLICT(type,external_id) DO NOTHING", [Infrastructure::Database.id, target["id"], "telegram", telegram_id, Time.now.to_i, Time.now.to_i])
          @db.execute("DELETE FROM telegram_links WHERE token_hash=?", [link["token_hash"]])
          reply(update_id, chat_id, "Telegram подключён к веб-кабинету.", main_menu)
        end
      end

      def user_for(telegram_id)
        user_id = @telegram_login.telegram_user(telegram_id)
        @db.one("SELECT * FROM users WHERE id=?", [user_id]) || raise(Billing::Error, "Аккаунт не найден.")
      end

      def reply(update_id, chat_id, text, reply_markup = nil)
        @outbox.enqueue("telegram.send", "telegram:reply:#{update_id}", { "chat_id" => chat_id, "text" => text, "reply_markup" => reply_markup }.compact)
      end

      def enqueue_callback_answer(update_id, callback_query_id)
        return if callback_query_id.to_s.empty?

        @outbox.enqueue("telegram.answer", "telegram:answer:#{update_id}", { "callback_query_id" => callback_query_id })
      end

      def main_menu
        { "inline_keyboard" => [
          [{ "text" => "Тарифы", "callback_data" => "menu:plans" }, { "text" => "Подписки", "callback_data" => "menu:subs" }],
          [{ "text" => "Заказы", "callback_data" => "menu:orders" }, { "text" => "Баланс", "callback_data" => "menu:balance" }],
          [{ "text" => "Кабинет", "callback_data" => "menu:cabinet" }, { "text" => "Помощь", "callback_data" => "menu:help" }]
        ] }
      end

      def command_parts(text)
        value = text.to_s.strip
        command, argument = value.split(/\s+/, 2)
        command = command.to_s.downcase.sub(/@[^\s]+\z/, "")
        [command, argument.to_s.strip]
      end

      def active_plan(plan_id)
        @db.one("SELECT * FROM plans WHERE id=? AND active=1", [plan_id]) || raise(Billing::Error, "Тариф недоступен.")
      end

      def plan_text(plan)
        traffic = plan["traffic_bytes"].to_i.zero? ? "безлимит" : "#{plan["traffic_bytes"].to_i / 1_073_741_824} ГБ"
        "#{plan["name"]} · #{money(plan["price_minor"])} · #{plan["duration_days"]} дн. · #{traffic} · до #{plan["devices"]} устр."
      end

      def order_text(order)
        "#{order["plan_name"]} · #{money(order["price_minor"])} · #{order["status"]}"
      end

      def topup_text(topup)
        "Пополнение на #{money(topup["amount_kopeks"])} · #{topup["status"]}"
      end

      def subscription_text(subscription)
        "#{subscription["plan_name"] || subscription["plan_id"]}: #{subscription["status"]} до #{date(subscription["expires_at"])}#{subscription["auto_renew"].to_i == 1 ? " · автопродление включено" : ""}"
      end

      def promo_error(code)
        {
          "not_found" => "Промокод не найден.", "inactive" => "Промокод отключён.", "used" => "Лимит промокода исчерпан.",
          "expired" => "Срок промокода истёк.", "already_used_by_user" => "Вы уже использовали этот промокод.",
          "no_subscription_for_days" => "Нет активной подписки для начисления дней."
        }.fetch(code.to_s, "Не удалось активировать промокод.")
      end

      def rubles_to_kopeks(input)
        value = input.to_s.tr(",", ".")
        raise Billing::Error, "Укажите сумму в рублях, например /topup 500." unless value.match?(/\A\d+(?:\.\d{1,2})?\z/)

        (value.to_f * 100).round
      end

      def valid_telegram_id?(value)
        value.to_s.match?(/\A[1-9][0-9]{0,19}\z/)
      end

      def integer(value)
        Integer(value, exception: false)
      end

      def money(kopeks)
        format("%.2f ₽", kopeks.to_i / 100.0).sub(".00", "")
      end

      def date(timestamp)
        Time.at(timestamp.to_i).utc.strftime("%d.%m.%Y %H:%M UTC")
      end

      def app_url
        @config.fetch("APP_URL").to_s.sub(%r{/+\z}, "")
      end

      def support_url
        @config["SUPPORT_URL"].to_s.empty? ? "напишите администратору" : @config["SUPPORT_URL"]
      end

      def telegram_username
        @config["TELEGRAM_BOT_USERNAME"].to_s.delete_prefix("@")
      end

      def site_name
        @config.fetch("SITE_NAME", "ZeleBoba")
      end
    end
  end
end
