# frozen_string_literal: true

require "cgi"
require "digest"
require "json"
require "securerandom"
require "sinatra/base"

require_relative "../container"

module Zeleboba
  module Web
    class Application < Sinatra::Base
      set :root, File.expand_path("../..", __dir__)
      set :views, File.expand_path("../../views", __dir__)
      set :public_folder, File.expand_path("../../../public", __dir__)
      set :container, nil
      set :raise_errors, false
      set :show_exceptions, false

      before do
        headers(
          "Cache-Control" => "no-store",
          "Content-Security-Policy" => "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; img-src 'self' data: https:; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
          "Referrer-Policy" => "no-referrer",
          "X-Content-Type-Options" => "nosniff"
        )
      end

      helpers do
        def app_container
          settings.respond_to?(:container) && settings.container || settings.container = Zeleboba::Container.new
        end

        def current_user
          @current_user ||= app_container.auth.session(request.cookies["zb_session"].to_s)
        end

        def require_user!
          return if current_user

          redirect "/login"
        end

        def csrf_token
          current_user&.fetch("csrf", nil) || guest_token
        end

        def require_csrf!
          return if Rack::Utils.secure_compare(params.fetch("_csrf", ""), csrf_token.to_s)

          halt 403, erb(:error, locals: { message: "Сессия формы устарела. Обновите страницу." })
        end

        def guest_token
          return @guest_token if @guest_token

          token = request.cookies["zb_guest"]
          return @guest_token = token if token.to_s.match?(/\A[a-f0-9]{64}\z/)

          token = SecureRandom.hex(32)
          response.set_cookie("zb_guest", value: token, path: "/", httponly: true, same_site: :lax)
          @guest_token = token
        end

        def rub(amount)
          whole, cents = amount.to_i.divmod(100)
          cents.zero? ? "#{whole} ₽" : "#{whole},#{format("%02d", cents)} ₽"
        end

        def h(value)
          CGI.escapeHTML(value.to_s)
        end

        def active_nav?(path)
          request.path_info == path || (path != "/" && request.path_info.start_with?(path))
        end

        def status_class(status)
          case status.to_s
          when "active", "fulfilled", "paid", "done"
            "is-ok"
          when "pending", "provisioning", "processing"
            "is-warn"
          when "expired", "canceled", "dead"
            "is-danger"
          else
            "is-muted"
          end
        end

        def status_label(status)
          {
            "active" => "Активна",
            "provisioning" => "Выдача",
            "expired" => "Истекла",
            "pending" => "Ожидает оплаты",
            "paid" => "Оплачен",
            "fulfilled" => "Исполнен",
            "canceled" => "Отменён"
          }.fetch(status.to_s, status.to_s)
        end

        def short_id(value)
          text = value.to_s
          text.length > 10 ? "#{text[0, 6]}…#{text[-4, 4]}" : text
        end

        def date_time(timestamp)
          return "—" if timestamp.to_i.zero?

          Time.at(timestamp.to_i).utc.strftime("%d.%m.%Y")
        end

        def days_left(timestamp)
          left = ((timestamp.to_i - Time.now.to_i) / 86_400.0).ceil
          return "сегодня" if left.zero?
          return "истекла" if left.negative?

          "#{left} дн."
        end

        def table_exists?(name)
          if app_container.db.postgres?
            !!app_container.db.one("SELECT to_regclass(?) AS name", [name])&.fetch("name", nil)
          else
            !!app_container.db.one("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [name])
          end
        end

        def count_rows(table, where = "1=1", params = [])
          return 0 unless table_exists?(table)

          app_container.db.one("SELECT COUNT(*) AS c FROM #{table} WHERE #{where}", params).fetch("c", 0).to_i
        end

        def telegram_enabled?
          app_container.config["TELEGRAM_BOT_USERNAME"].to_s != "" || app_container.config["APP_ENV"] != "prod"
        end

        def telegram_bot_username
          app_container.config["TELEGRAM_BOT_USERNAME"].to_s.empty? ? "zeleboba_demo_bot" : app_container.config["TELEGRAM_BOT_USERNAME"]
        end

        def promo_error(code)
          {
            "not_found" => "Промокод не найден.", "inactive" => "Промокод неактивен.", "used" => "Лимит активаций исчерпан.",
            "not_yet_valid" => "Промокод ещё не действует.", "expired" => "Срок действия промокода истёк.",
            "already_used_by_user" => "Вы уже использовали этот промокод.", "daily_limit" => "Слишком много активаций за сутки.",
            "not_first_purchase" => "Промокод действует только для первой покупки.", "active_discount_exists" => "У вас уже есть активная скидка.",
            "no_subscription_for_days" => "Нет активной подписки для начисления дней.", "trial_subscription_exists" => "Триал сейчас недоступен."
          }.fetch(code.to_s, "Не удалось активировать промокод.")
        end
      end

      get "/health/live" do
        content_type :json
        { status: "ok" }.to_json
      end

      get "/health/ready" do
        app_container.db.one("SELECT version FROM migrations LIMIT 1")
        content_type :json
        { status: "ready" }.to_json
      end

      post "/webhooks/telegram" do
        expected_secret = app_container.config["TELEGRAM_WEBHOOK_SECRET"].to_s
        supplied_secret = request.env["HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN"].to_s
        unless expected_secret.empty? || (supplied_secret.bytesize == expected_secret.bytesize && Rack::Utils.secure_compare(supplied_secret, expected_secret))
          halt 403, { ok: false, error: "invalid webhook secret" }.to_json
        end

        update = JSON.parse(request.body.read)
        app_container.telegram_bot.receive(update)
        content_type :json
        { ok: true }.to_json
      rescue JSON::ParserError
        halt 400, { ok: false, error: "malformed update" }.to_json
      rescue Billing::Error => e
        halt 422, { ok: false, error: e.message }.to_json
      end

      post "/webhooks/platega" do
        merchant = app_container.config["PLATEGA_MERCHANT_ID"].to_s
        secret = app_container.config["PLATEGA_SECRET"].to_s
        supplied_merchant = request.env["HTTP_X_MERCHANTID"].to_s.empty? ? request.env["HTTP_X_MERCHANT_ID"].to_s : request.env["HTTP_X_MERCHANTID"].to_s
        supplied_secret = request.env["HTTP_X_SECRET"].to_s
        halt 404, "Not found" unless app_container.config["PLATEGA_ENABLED"] == "1" && merchant != "" && secret != ""
        halt 403, { ok: false, error: "invalid webhook credentials" }.to_json unless secure_equal?(merchant, supplied_merchant) && secure_equal?(secret, supplied_secret)

        payload = JSON.parse(request.body.read)
        payment_id = (payload["transactionId"] || payload["id"] || payload["payment_id"]).to_s
        status = payload["status"].to_s.upcase
        halt 400, { ok: false, error: "malformed payment event" }.to_json unless payment_id.match?(/\A[a-zA-Z0-9:_-]{1,100}\z/) && !status.empty?

        event_id = request.env["HTTP_X_WEBHOOK_ID"].to_s
        event_id = "#{payment_id}:#{status}" if event_id.empty?
        claim = app_container.webhook_guard.claim("platega", event_id, payload)
        if claim != "duplicate"
          app_container.payment_events.receive(provider: "platega", event_id: event_id, payment_id: payment_id, payload: payload, signature_valid: true)
        end
        content_type :json
        { ok: true }.to_json
      rescue JSON::ParserError
        halt 400, { ok: false, error: "malformed JSON" }.to_json
      rescue Billing::Error => e
        halt 422, { ok: false, error: e.message }.to_json
      end

      get "/register" do
        halt 404, "Not found" unless app_container.config["REGISTRATION_ENABLED"] == "1"
        erb :auth, locals: { mode: "register", error: nil }
      end

      post "/register" do
        require_csrf!
        halt 404, "Not found" unless app_container.config["REGISTRATION_ENABLED"] == "1"
        app_container.auth.throttle("register:#{request.ip}", 10, 900)
        uid = app_container.auth.register(params[:email], params[:password])
        app_container.referrals.attach_referrer(uid, params[:ref]) unless params[:ref].to_s.empty?
        session = app_container.auth.issue(uid)
        response.set_cookie("zb_session", value: session, path: "/", httponly: true, same_site: :lax)
        redirect "/", 303
      rescue Billing::Error => e
        status 422
        erb :auth, locals: { mode: "register", error: e.message }
      end

      get "/login" do
        erb :auth, locals: { mode: "login", error: nil }
      end

      post "/login" do
        require_csrf!
        app_container.auth.throttle("login:#{request.ip}", 20, 900)
        uid = app_container.auth.login(params[:email], params[:password])
        session = app_container.auth.issue(uid)
        response.set_cookie("zb_session", value: session, path: "/", httponly: true, same_site: :lax)
        redirect "/", 303
      rescue Billing::Error => e
        status 422
        erb :auth, locals: { mode: "login", error: e.message }
      end

      post "/logout" do
        require_user!
        require_csrf!
        app_container.auth.logout(request.cookies["zb_session"].to_s)
        response.delete_cookie("zb_session", path: "/")
        redirect "/login", 303
      end

      post "/telegram/start" do
        require_csrf!
        halt 422, erb(:error, locals: { message: "Вход через Telegram ещё не настроен." }) unless telegram_enabled?

        challenge = app_container.telegram_login.begin
        response.set_cookie("zb_tg", value: challenge["browser"], path: "/", httponly: true, same_site: :lax, expires: Time.now + 300)
        deep_link = "https://t.me/#{telegram_bot_username}?start=login_#{challenge["token"]}"
        erb :telegram_login, locals: { deep_link: deep_link, token: challenge["token"], error: nil }
      rescue Billing::Error => e
        status 422
        erb :auth, locals: { mode: "login", error: e.message }
      end

      get "/telegram/status" do
        content_type :json
        { ready: app_container.telegram_login.ready?(request.cookies["zb_tg"].to_s) }.to_json
      end

      post "/telegram/dev-approve" do
        require_csrf!
        halt 404, "Not found" if app_container.config["APP_ENV"] == "prod"

        app_container.telegram_login.approve(params[:token].to_s, params.fetch("telegram_id", "100001"))
        redirect "/telegram/finish", 303
      rescue Billing::Error => e
        status 422
        erb :telegram_login, locals: { deep_link: "#", token: params[:token].to_s, error: e.message }
      end

      get "/telegram/finish" do
        proof = request.cookies["zb_tg"].to_s
        session = app_container.telegram_login.consume(proof, "browser")
        response.set_cookie("zb_session", value: session, path: "/", httponly: true, same_site: :lax)
        response.delete_cookie("zb_tg", path: "/")
        redirect "/", 303
      rescue Billing::Error => e
        status 422
        erb :auth, locals: { mode: "login", error: e.message }
      end

      get "/telegram/magic" do
        erb :telegram_magic, locals: { error: nil, token: params[:token].to_s }
      end

      post "/telegram/magic" do
        require_csrf!
        session = app_container.telegram_login.consume(params[:token].to_s, "magic")
        response.set_cookie("zb_session", value: session, path: "/", httponly: true, same_site: :lax)
        redirect "/", 303
      rescue Billing::Error => e
        status 422
        erb :telegram_magic, locals: { error: e.message, token: params[:token].to_s }
      end

      get "/" do
        require_user!
        db = app_container.db
        subscriptions = db.all("SELECT s.*,COALESCE(o.plan_name,p.name) AS plan_name FROM subscriptions s LEFT JOIN orders o ON o.id=s.order_id LEFT JOIN plans p ON p.id=s.plan_id WHERE s.user_id=? ORDER BY s.created_at DESC LIMIT 50", [current_user["id"]])
        orders = db.all("SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 5", [current_user["id"]])
        balance = app_container.wallet.balance(current_user["id"]).fetch("balance_kopeks")
        active_subscriptions = subscriptions.count { |sub| sub["status"] == "active" }
        pending_orders = orders.count { |order| order["status"] == "pending" }
        next_subscription = subscriptions.select { |sub| sub["status"] == "active" }.min_by { |sub| sub["expires_at"].to_i }
        erb :home, locals: {
          subscriptions: subscriptions,
          orders: orders,
          balance: balance,
          active_subscriptions: active_subscriptions,
          pending_orders: pending_orders,
          next_subscription: next_subscription
        }
      end

      get "/plans" do
        require_user!
        erb :plans, locals: { plans: app_container.db.all("SELECT * FROM plans WHERE active=1 ORDER BY price_minor"), key: Infrastructure::Database.id }
      end

      post "/orders" do
        require_user!
        require_csrf!
        order = app_container.billing.order(current_user["id"], params[:plan_id].to_s, params[:idempotency_key].to_s)
        redirect "/orders/#{order["id"]}", 303
      rescue Billing::Error => e
        status 422
        erb :error, locals: { message: e.message }
      end

      get "/orders" do
        require_user!
        erb :orders, locals: { orders: app_container.db.all("SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 100", [current_user["id"]]) }
      end

      get "/orders/:id" do
        require_user!
        order = app_container.db.one("SELECT * FROM orders WHERE id=? AND user_id=?", [params[:id], current_user["id"]])
        halt 404, erb(:error, locals: { message: "Заказ не найден." }) unless order

        erb :order, locals: { order: order, subscription: app_container.db.one("SELECT * FROM subscriptions WHERE order_id=?", [order["id"]]) }
      end

      post "/orders/:id/demo-pay" do
        require_user!
        require_csrf!
        order = app_container.db.one("SELECT * FROM orders WHERE id=? AND user_id=?", [params[:id], current_user["id"]])
        halt "Not found", 404 unless order && order["provider"] == "demo" && app_container.config["APP_ENV"] != "prod"

        app_container.billing.settle(order["id"], "demo", "demo_#{order["id"]}", order["price_minor"].to_i, order["currency"])
        redirect "/orders/#{order["id"]}", 303
      rescue Billing::Error => e
        status 422
        erb :error, locals: { message: e.message }
      end

      post "/subscriptions/:id/autorenew" do
        require_user!
        require_csrf!
        app_container.renewals.set_auto_renew(current_user["id"], params[:id], params[:enable].to_s == "1")
        redirect "/", 303
      rescue Billing::Error => e
        status 422
        erb :error, locals: { message: e.message }
      end

      post "/subscriptions/:id/renew" do
        require_user!
        require_csrf!
        order = app_container.billing.renewal_order(current_user["id"], params[:id], params.fetch(:idempotency_key, Infrastructure::Database.id))
        redirect "/orders/#{order["id"]}", 303
      rescue Billing::Error => e
        status 422
        erb :error, locals: { message: e.message }
      end

      post "/subscriptions/merge" do
        require_user!
        require_csrf!
        app_container.merger.merge(current_user["id"], params[:source_id], params[:target_id])
        redirect "/", 303
      rescue Billing::Error => e
        status 422
        erb :error, locals: { message: e.message }
      end

      post "/trial" do
        require_user!
        require_csrf!
        app_container.trials.start(current_user["id"], params[:plan_id])
        redirect "/", 303
      rescue Billing::Error => e
        status 422
        erb :error, locals: { message: e.message }
      end

      post "/trial/:id/convert" do
        require_user!
        require_csrf!
        app_container.trials.convert_to_paid(current_user["id"], params[:id], params[:plan_id])
        redirect "/", 303
      rescue Billing::Error => e
        status 422
        erb :error, locals: { message: e.message }
      end

      get "/balance" do
        require_user!
        erb :balance, locals: {
          balance: app_container.wallet.balance(current_user["id"]).fetch("balance_kopeks"),
          history: app_container.wallet.history(current_user["id"]),
          topups: app_container.db.all("SELECT * FROM topups WHERE user_id=? ORDER BY created_at DESC LIMIT 10", [current_user["id"]]),
          key: Infrastructure::Database.id
        }
      end

      post "/balance/topup" do
        require_user!
        require_csrf!
        amount = Integer(params[:amount].to_s, 10)
        topup = app_container.topups.create(current_user["id"], amount * 100, params[:idempotency_key].to_s, params[:provider])
        redirect "/balance/topup/#{topup["id"]}", 303
      rescue ArgumentError
        status 422
        erb :error, locals: { message: "Укажите сумму целым числом в рублях." }
      rescue Billing::Error => e
        status 422
        erb :error, locals: { message: e.message }
      end

      get "/balance/topup/:id" do
        require_user!
        topup = app_container.db.one("SELECT * FROM topups WHERE id=? AND user_id=?", [params[:id], current_user["id"]])
        halt 404, erb(:error, locals: { message: "Пополнение не найдено." }) unless topup

        erb :topup, locals: { topup: topup }
      end

      post "/balance/topup/:id/demo-pay" do
        require_user!
        require_csrf!
        topup = app_container.db.one("SELECT * FROM topups WHERE id=? AND user_id=?", [params[:id], current_user["id"]])
        halt 404, "Not found" unless topup && topup["provider"] == "demo" && app_container.config["APP_ENV"] != "prod"

        app_container.topups.settle(topup["id"], "demo", "demo_#{topup["id"]}", topup["amount_kopeks"].to_i, topup["currency"])
        app_container.referrals.process_settled_topup(topup["id"])
        redirect "/balance", 303
      rescue Billing::Error => e
        status 422
        erb :error, locals: { message: e.message }
      end

      get "/promo" do
        require_user!
        erb :promo, locals: { result: nil, error: nil }
      end

      post "/promo" do
        require_user!
        require_csrf!
        result = app_container.promocodes.activate(current_user["id"], params[:code])
        status 422 unless result["success"]
        erb :promo, locals: { result: result["success"] ? result : nil, error: result["success"] ? nil : promo_error(result["error"]) }
      end

      get "/settings" do
        require_user!
        erb :settings, locals: {
          user: current_user,
          config: app_container.config,
          session_count: app_container.db.all("SELECT id FROM sessions WHERE user_id=?", [current_user["id"]]).count,
          identities: table_exists?("user_identities") ? app_container.db.all("SELECT * FROM user_identities WHERE user_id=? ORDER BY created_at DESC", [current_user["id"]]) : []
        }
      end

      post "/settings/telegram" do
        require_user!
        require_csrf!
        token = SecureRandom.hex(24)
        app_container.db.transaction do
          app_container.db.execute("DELETE FROM telegram_links WHERE user_id=?", [current_user["id"]])
          app_container.db.execute("INSERT INTO telegram_links(token_hash,user_id,expires_at) VALUES(?,?,?)", [Digest::SHA256.hexdigest(token), current_user["id"], Time.now.to_i + 600])
        end
        erb :settings, locals: {
          user: current_user, config: app_container.config,
          session_count: app_container.db.all("SELECT id FROM sessions WHERE user_id=?", [current_user["id"]]).count,
          identities: table_exists?("user_identities") ? app_container.db.all("SELECT * FROM user_identities WHERE user_id=? ORDER BY created_at DESC", [current_user["id"]]) : [],
          link_token: token
        }
      end

      get "/security" do
        require_user!
        erb :security, locals: {
          user: current_user,
          sessions: app_container.db.all("SELECT * FROM sessions WHERE user_id=? ORDER BY expires_at DESC", [current_user["id"]]),
          audits: app_container.db.all("SELECT * FROM audit_log WHERE actor=? OR subject=? ORDER BY created_at DESC LIMIT 20", [current_user["id"], current_user["id"]])
        }
      end

      get "/referral" do
        require_user!
        stats = app_container.referrals.stats(current_user["id"])
        erb :referral, locals: {
          stats: stats,
          withdrawals: app_container.referrals.withdrawals(current_user["id"]),
          withdrawal_enabled: app_container.config["REFERRAL_WITHDRAWAL_ENABLED"] == "1",
          min_withdrawal: app_container.config.fetch("REFERRAL_WITHDRAWAL_MIN_AMOUNT_KOPEKS", "100000").to_i,
          telegram_username: telegram_bot_username
        }
      end

      post "/referral/withdraw" do
        require_user!
        require_csrf!
        amount = Integer(params[:amount].to_s, 10)
        app_container.referrals.request_withdrawal(current_user["id"], amount * 100, params[:payment_details])
        redirect "/referral", 303
      rescue ArgumentError
        status 422
        erb :error, locals: { message: "Укажите сумму целым числом в рублях." }
      rescue Billing::Error => e
        status 422
        erb :error, locals: { message: e.message }
      end

      get "/gifts" do
        require_user!
        erb :gifts, locals: {
          bought: app_container.gifts.bought_by(current_user["id"]),
          received: app_container.gifts.received_by(current_user["id"]),
          plans: app_container.db.all("SELECT * FROM plans WHERE active=1 ORDER BY price_minor"),
          gift_enabled: app_container.gifts.enabled?
        }
      end

      post "/gifts/buy" do
        require_user!
        require_csrf!
        app_container.gifts.purchase_from_balance(
          current_user["id"], params[:plan_id].to_s, params[:idempotency_key].to_s,
          recipient_type: params[:recipient_type], recipient_value: params[:recipient_value], message: params[:gift_message]
        )
        redirect "/gifts", 303
      rescue Billing::Error => e
        status 422
        erb :error, locals: { message: e.message }
      end

      get "/gifts/claim" do
        require_user!
        erb :gift_claim, locals: { code: "", error: nil }
      end

      get "/buy/gift/:id" do
        require_user!
        erb :gift_claim, locals: { code: params[:id], error: nil }
      end

      post "/gifts/claim" do
        require_user!
        require_csrf!
        gift = app_container.gifts.claim(current_user["id"], params[:code])
        erb :gift_claimed, locals: { gift: gift }
      rescue Billing::Error => e
        status 422
        erb :gift_claim, locals: { code: params[:code].to_s, error: e.message }
      end

      get "/admin" do
        require_user!
        halt 403, erb(:error, locals: { message: "Недостаточно прав." }) unless current_user["role"] == "admin"

        erb :admin, locals: {
          users_count: count_rows("users"),
          orders_count: count_rows("orders"),
          subscriptions_count: count_rows("subscriptions"),
          outbox_pending: count_rows("outbox", "status IN ('pending','processing')"),
          latest_orders: app_container.db.all("SELECT * FROM orders ORDER BY created_at DESC LIMIT 10")
        }
      end

      post "/orders/balance" do
        require_user!
        require_csrf!
        order = app_container.billing.purchase_from_balance(current_user["id"], params[:plan_id].to_s, params[:idempotency_key].to_s)
        redirect "/orders/#{order["id"]}", 303
      rescue Billing::Error => e
        status 422
        erb :error, locals: { message: e.message }
      end

      not_found do
        status 404
        erb :error, locals: { message: "Страница не найдена." }
      end

      error do
        warn env["sinatra.error"].full_message
        status 503
        erb :error, locals: { message: "Не удалось выполнить запрос. Повторите позже." }
      end

      private

      def secure_equal?(expected, actual)
        expected.bytesize == actual.bytesize && Rack::Utils.secure_compare(expected, actual)
      end
    end
  end
end
