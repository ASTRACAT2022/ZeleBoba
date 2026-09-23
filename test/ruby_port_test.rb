# frozen_string_literal: true

ENV["APP_ENV"] = "test"

require "minitest/autorun"
require "rack/test"

require_relative "../ruby_app/zeleboba"

class RubyPortTest < Minitest::Test
  include Rack::Test::Methods

  def app
    Zeleboba::Web::Application
  end

  def setup
    @container = Zeleboba::Container.new(
      "APP_ENV" => "test",
      "PURCHASES_ENABLED" => "1",
      "DATABASE_DSN" => "sqlite::memory:",
      "PAYMENT_DRIVER" => "demo",
      "PROVISION_DRIVER" => "demo"
    )
    @container.db.migrate(File.expand_path("../migrations", __dir__))
    @container.db.execute(
      "INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active,is_trial_available,trial_duration_days) VALUES('basic','Basic',19900,'RUB',30,0,3,1,1,7)"
    )
    app.set :container, @container
    clear_cookies
  end

  def test_health
    get "/health/live"
    assert_equal 200, last_response.status
    assert_includes last_response.body, "ok"
  end

  def test_registration_and_demo_purchase
    get "/register"
    assert_equal 200, last_response.status
    csrf = last_response.body[/name="_csrf" value="([^"]+)"/, 1]

    post "/register", email: "a@example.org", password: "correct-horse-battery", _csrf: csrf
    assert_equal 303, last_response.status

    get "/plans"
    assert_equal 200, last_response.status
    csrf = last_response.body[/name="_csrf" value="([^"]+)"/, 1]
    key = last_response.body[/name="idempotency_key" value="([^"]+)"/, 1]

    post "/orders", plan_id: "basic", idempotency_key: key, _csrf: csrf
    assert_equal 303, last_response.status
    order_path = last_response["Location"]

    get order_path
    assert_equal 200, last_response.status
    csrf = last_response.body[/name="_csrf" value="([^"]+)"/, 1]

    post "#{order_path}/demo-pay", _csrf: csrf
    assert_equal 303, last_response.status
    @container.worker.run_until_idle
    assert_equal "fulfilled", @container.db.one("SELECT status FROM orders")["status"]
    assert_equal "active", @container.db.one("SELECT status FROM subscriptions")["status"]
  end

  def test_post_requires_csrf
    uid = @container.auth.register("b@example.org", "correct-horse-battery")
    set_cookie "zb_session=#{@container.auth.issue(uid)}"

    post "/orders", plan_id: "basic", idempotency_key: "request-key"
    assert_equal 403, last_response.status
    assert_empty @container.db.all("SELECT * FROM orders")
  end

  def test_expanded_cabinet_pages_render
    uid = @container.auth.register("pages@example.org", "correct-horse-battery")
    set_cookie "zb_session=#{@container.auth.issue(uid)}"

    %w[/ /plans /orders /balance /settings /security /referral /gifts].each do |path|
      get path
      assert_equal 200, last_response.status, path
    end
  end

  def test_telegram_dev_login_flow
    get "/login"
    csrf = last_response.body[/name="_csrf" value="([^"]+)"/, 1]
    post "/telegram/start", _csrf: csrf
    assert_equal 200, last_response.status
    assert_includes last_response.body, "Подтвердите вход"
    token = last_response.body[/name="token" value="([^"]+)"/, 1]

    post "/telegram/dev-approve", _csrf: csrf, token: token, telegram_id: "123456"
    assert_equal 303, last_response.status
    follow_redirect!
    assert_equal 303, last_response.status
    follow_redirect!
    assert_equal 200, last_response.status
    assert_includes last_response.body, "Рабочий стол"
    assert_equal "123456", @container.db.one("SELECT telegram_id FROM users WHERE telegram_id='123456'")["telegram_id"]
  end

  def test_telegram_webhook_approves_browser_login
    @container.config["TELEGRAM_WEBHOOK_SECRET"] = "webhook-secret"
    challenge = @container.telegram_login.begin
    payload = {
      update_id: 1,
      message: {
        chat: { id: 778899, type: "private" },
        from: { id: 778899 },
        text: "/start login_#{challenge["token"]}"
      }
    }.to_json

    post "/webhooks/telegram", payload, "CONTENT_TYPE" => "application/json", "HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN" => "webhook-secret"
    assert_equal 200, last_response.status
    assert @container.telegram_login.ready?(challenge["browser"])
    assert_equal "778899", @container.db.one("SELECT telegram_id FROM users WHERE telegram_id='778899'")["telegram_id"]
    reply = @container.db.one("SELECT payload FROM outbox WHERE dedup_key='telegram:reply:1'")
    assert_includes reply["payload"], "Вход подтверждён"
  end

  def test_telegram_bot_queues_private_commands_once
    @container.config["TELEGRAM_WEBHOOK_SECRET"] = "webhook-secret"
    payload = {
      update_id: 42,
      message: {
        chat: { id: 778899, type: "private" },
        from: { id: 778899 },
        text: "/plans"
      }
    }.to_json

    2.times do
      post "/webhooks/telegram", payload, "CONTENT_TYPE" => "application/json", "HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN" => "webhook-secret"
      assert_equal 200, last_response.status
    end

    rows = @container.db.all("SELECT * FROM outbox WHERE dedup_key='telegram:reply:42'")
    assert_equal 1, rows.length
    assert_includes rows.first["payload"], "Тарифы"
    assert_equal 1, @container.db.all("SELECT * FROM telegram_updates WHERE update_id=42").length
  end

  def test_remnawave_client_provisions_and_syncs_a_deterministic_user
    calls = []
    requester = lambda do |method, uri, headers, payload|
      calls << [method, uri.to_s, headers, payload]
      case method
      when "GET"
        { status: 404, body: {} }
      when "POST"
        { status: 201, body: { "response" => { "id" => 77, "username" => payload[:username], "subscriptionUrl" => "https://panel.example/sub/77" } } }
      else
        { status: 200, body: { "response" => {} } }
      end
    end
    client = Zeleboba::Integration::RemnawaveClient.new(
      { "REMNAWAVE_URL" => "https://panel.example", "REMNAWAVE_TOKEN" => "token", "REMNAWAVE_SQUAD_UUID" => "squad" },
      requester: requester
    )
    subscription = { "id" => "a" * 32, "expires_at" => Time.now.to_i + 86_400, "traffic_bytes" => 1_073_741_824, "devices" => 3 }

    remote = client.provision(subscription)
    assert_equal "77", remote["id"]
    assert_equal "https://panel.example/sub/77", remote["url"]
    assert_equal "POST", calls.last.first
    assert_equal "Bearer token", calls.last[2]["Authorization"]
  end

  def test_remnawave_provisioning_activates_subscription_and_persists_remote_identity
    user_id = @container.auth.register("remote@example.org", "correct-horse-battery")
    subscription_id = "b" * 32
    now = Time.now.to_i
    @container.db.execute(
      "INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,plan_id,traffic_limit_gb,device_limit,is_trial,starts_at,traffic_limit_bytes,updated_at,lifecycle_status) VALUES(?,?,?,'provisioning',?,?,?,?,?,0,?,?,?,'pending')",
      [subscription_id, nil, user_id, now + 86_400, now, "basic", 0, 3, now, 0, now]
    )
    client = Zeleboba::Integration::RemnawaveClient.new(
      { "REMNAWAVE_URL" => "https://panel.example", "REMNAWAVE_TOKEN" => "token", "REMNAWAVE_SQUAD_UUID" => "squad" },
      requester: lambda { |method, _uri, _headers, payload|
        method == "GET" ? { status: 404, body: {} } : { status: 201, body: { "response" => { "id" => 901, "username" => payload[:username], "subscriptionUrl" => "https://panel.example/sub/901" } } }
      }
    )
    config = @container.config.merge("PROVISION_DRIVER" => "remnawave", "REMNAWAVE_URL" => "https://panel.example", "REMNAWAVE_TOKEN" => "token", "REMNAWAVE_SQUAD_UUID" => "squad")
    service = Zeleboba::Integration::ProvisioningService.new(@container.db, @container.subscriptions, @container.outbox, config, remnawave: client)

    service.provision(subscription_id)

    subscription = @container.db.one("SELECT * FROM subscriptions WHERE id=?", [subscription_id])
    assert_equal "active", subscription["status"]
    assert_equal "901", subscription["remote_id"]
    assert_equal "https://panel.example/sub/901", subscription["subscription_url"]
    assert_equal "active", @container.db.one("SELECT state FROM provisioning_accounts WHERE subscription_id=?", [subscription_id])["state"]
  end

  def test_renewal_order_and_trial_conversion_keep_subscription_workflows_consistent
    user_id = @container.auth.register("flows@example.org", "correct-horse-battery")
    initial = @container.billing.order(user_id, "basic", "initial-flow-order")
    @container.billing.settle(initial["id"], "demo", "demo_#{initial["id"]}", 19_900, "RUB")
    @container.worker.run_until_idle
    subscription = @container.db.one("SELECT * FROM subscriptions WHERE order_id=?", [initial["id"]])

    renewal = @container.billing.renewal_order(user_id, subscription["id"], "renew-flow-request")
    assert_equal renewal["id"], @container.db.one("SELECT renew_order_id FROM subscriptions WHERE id=?", [subscription["id"]])["renew_order_id"]
    assert_equal "pending", renewal["status"]

    trial_user = @container.auth.register("trial-convert@example.org", "correct-horse-battery")
    @container.wallet.credit(trial_user, 30_000, "balance_topup", "Тестовый баланс", "test", "trial-convert-balance")
    trial = @container.trials.start(trial_user, "basic")
    @container.worker.run_until_idle
    converted = @container.trials.convert_to_paid(trial_user, trial["id"], "basic")
    assert_equal 0, converted["is_trial"].to_i
    assert_equal "active", converted["status"]
    assert @container.db.one("SELECT id FROM outbox WHERE topic='subscription.extend' AND dedup_key LIKE 'trial-convert:%'")
  end

  def test_topup_and_gift_claim_flow
    buyer = @container.auth.register("buyer@example.org", "correct-horse-battery")
    recipient = @container.auth.register("recipient@example.org", "correct-horse-battery")
    @container.wallet.credit(buyer, 50_000, "balance_topup", "Тестовый баланс", "test", "seed-buyer")

    topup = @container.topups.create(buyer, 10_000, "topup-request-1", "demo")
    @container.topups.settle(topup["id"], "demo", "demo-#{topup["id"]}", 10_000, "RUB")
    assert_equal 60_000, @container.wallet.balance(buyer)["balance_kopeks"]

    gift = @container.gifts.purchase_from_balance(buyer, "basic", "gift-request-1")
    assert_equal "paid", gift["status"]
    claimed = @container.gifts.claim(recipient, @container.gifts.public_code(gift["token"]))
    assert_equal "delivered", claimed["status"]
    assert_equal "provisioning", @container.db.one("SELECT status FROM subscriptions WHERE user_id=?", [recipient])["status"]
  end

  def test_promocode_and_referral_flow
    inviter = @container.auth.register("inviter@example.org", "correct-horse-battery")
    code = @container.referrals.ensure_code(inviter)
    invited = @container.auth.register("invited@example.org", "correct-horse-battery")
    assert_equal inviter, @container.referrals.attach_referrer(invited, code)

    @container.promocodes.create({ "code" => "BONUS100", "type" => "balance", "balance_bonus_kopeks" => 10_000, "max_uses" => 2 }, inviter)
    result = @container.promocodes.activate(invited, "bonus100")
    assert result["success"]
    assert_equal 10_000, @container.wallet.balance(invited)["balance_kopeks"]

    topup = @container.topups.create(invited, 20_000, "topup-request-2", "demo")
    @container.topups.settle(topup["id"], "demo", "demo-#{topup["id"]}", 20_000, "RUB")
    @container.referrals.process_settled_topup(topup["id"])
    assert_operator @container.referrals.stats(inviter)["earnings_kopeks"], :>, 0
  end

  def test_subscription_lifecycle_and_trial_are_guarded
    uid = @container.auth.register("trial@example.org", "correct-horse-battery")
    trial = @container.trials.start(uid, "basic", days: 7)
    assert_equal "provisioning", trial["status"]
    assert_equal "pending", trial["lifecycle_status"]

    active = @container.subscriptions.activate(trial["id"], remote_id: "demo-#{trial["id"]}", subscription_url: "https://example.test/sub")
    assert_equal "active", active["status"]
    assert_equal "active", active["lifecycle_status"]
    assert_raises(Zeleboba::Billing::Error) { @container.subscriptions.activate(trial["id"], remote_id: "another", subscription_url: "https://example.test/other") }
  end

  def test_outbox_reclaims_expired_lease_and_dead_letters_permanent_failure
    now = Time.now.to_i
    @container.outbox.enqueue("unknown.topic", "unknown-job", { "value" => 1 })
    row = @container.db.one("SELECT id FROM outbox WHERE dedup_key='unknown-job'")
    @container.db.execute("UPDATE outbox SET status='processing',locked_until=?,lock_token='stale' WHERE id=?", [now - 1, row["id"]])

    worker = Zeleboba::Infrastructure::Worker.new(@container.outbox, {})
    refute worker.run_once
    assert_equal "dead", @container.db.one("SELECT status FROM outbox WHERE id=?", [row["id"]])["status"]
  end

  def test_platega_webhook_is_authenticated_and_durable
    @container.config["PLATEGA_ENABLED"] = "1"
    @container.config["PLATEGA_MERCHANT_ID"] = "merchant"
    @container.config["PLATEGA_SECRET"] = "secret"
    payload = { transactionId: "tx_123", status: "CONFIRMED" }.to_json

    post "/webhooks/platega", payload, "CONTENT_TYPE" => "application/json", "HTTP_X_MERCHANTID" => "merchant", "HTTP_X_SECRET" => "secret", "HTTP_X_WEBHOOK_ID" => "event_123"
    assert_equal 200, last_response.status
    assert_equal "pending", @container.db.one("SELECT status FROM payment_events WHERE provider_event_id='event_123'")["status"]
    assert_equal "pending", @container.db.one("SELECT status FROM outbox WHERE topic='payment.event.process'")["status"]

    post "/webhooks/platega", payload, "CONTENT_TYPE" => "application/json", "HTTP_X_MERCHANTID" => "merchant", "HTTP_X_SECRET" => "wrong", "HTTP_X_WEBHOOK_ID" => "event_123"
    assert_equal 403, last_response.status
  end

  def test_mfa_encrypts_secret_prevents_replay_and_supports_recovery
    uid = @container.auth.register("mfa@example.org", "correct-horse-battery")
    secret = @container.mfa.begin_enrollment(uid)
    recovery_codes = @container.mfa.enroll(uid, Zeleboba::Identity::Mfa.code(secret))
    refute_equal secret, @container.db.one("SELECT totp_secret FROM users WHERE id=?", [uid])["totp_secret"]

    next_code = Zeleboba::Identity::Mfa.code(secret, Time.now.to_i / 30 + 1)
    assert @container.mfa.verify(uid, next_code)
    assert_raises(Zeleboba::Billing::Error) { @container.mfa.verify(uid, next_code) }
    assert @container.mfa.verify(uid, recovery_codes.first)
    assert_raises(Zeleboba::Billing::Error) { @container.mfa.verify(uid, recovery_codes.first) }
  end

  def test_production_refuses_unsafe_configuration
    error = assert_raises(RuntimeError) do
      Zeleboba::Container.new(
        "APP_ENV" => "prod",
        "DATABASE_DSN" => "sqlite::memory:",
        "APP_URL" => "http://example.test",
        "PAYMENT_DRIVER" => "demo",
        "PROVISION_DRIVER" => "demo"
      )
    end
    assert_includes error.message, "PostgreSQL"
  end

  def test_auto_renewal_charges_once_and_extends_existing_subscription
    @container.config["AUTORENEW_ENABLED"] = "1"
    uid = @container.auth.register("renew@example.org", "correct-horse-battery")
    order = @container.billing.order(uid, "basic", "initial-renew-order")
    @container.billing.settle(order["id"], "demo", "demo_#{order["id"]}", 19_900, "RUB")
    @container.worker.run_until_idle
    subscription = @container.db.one("SELECT * FROM subscriptions WHERE order_id=?", [order["id"]])
    before_expiry = subscription["expires_at"].to_i
    @container.wallet.credit(uid, 50_000, "balance_topup", "Тестовое пополнение", "test", "renew-balance")
    @container.renewals.set_auto_renew(uid, subscription["id"], true)
    @container.db.execute("UPDATE subscriptions SET renew_at=? WHERE id=?", [Time.now.to_i - 1, subscription["id"]])

    assert @container.renewals.charge_due(subscription["id"])
    renewed = @container.db.one("SELECT * FROM subscriptions WHERE id=?", [subscription["id"]])
    assert_operator renewed["expires_at"].to_i, :>, before_expiry
    assert_nil renewed["renew_order_id"]
    assert_equal 1, @container.db.one("SELECT COUNT(*) AS c FROM subscriptions WHERE user_id=?", [uid])["c"].to_i
    assert_equal 2, @container.db.one("SELECT COUNT(*) AS c FROM orders WHERE user_id=?", [uid])["c"].to_i
    refute @container.renewals.charge_due(subscription["id"])
  end

  def test_merge_transfers_entitlements_and_preserves_source_history
    uid = @container.auth.register("merge@example.org", "correct-horse-battery")
    first_order = @container.billing.order(uid, "basic", "merge-order-one")
    second_order = @container.billing.order(uid, "basic", "merge-order-two")
    [first_order, second_order].each { |order| @container.billing.settle(order["id"], "demo", "demo_#{order["id"]}", 19_900, "RUB") }
    @container.worker.run_until_idle
    subscriptions = @container.db.all("SELECT * FROM subscriptions WHERE user_id=? ORDER BY created_at", [uid])
    source, target = subscriptions
    original_target_expiry = target["expires_at"].to_i

    merged = @container.merger.merge(uid, source["id"], target["id"])
    assert_equal target["id"], merged["id"]
    assert_operator merged["expires_at"].to_i, :>, original_target_expiry
    assert_equal "disabled", @container.db.one("SELECT status FROM subscriptions WHERE id=?", [source["id"]])["status"]
    assert_equal 6, merged["device_limit"].to_i
  end

  def test_topup_completes_saved_subscription_cart_once
    uid = @container.auth.register("cart@example.org", "correct-horse-battery")
    @container.carts.save(uid, { "kind" => "subscription", "plan_id" => "basic", "idempotency_key" => "saved-cart-purchase" }, intent: true)
    topup = @container.topups.create(uid, 20_000, "cart-topup-key", "demo")
    @container.topups.settle(topup["id"], "demo", "cart-payment", 20_000, "RUB")
    @container.worker.run_until_idle

    assert_nil @container.carts.fetch(uid)
    assert_equal "fulfilled", @container.db.one("SELECT status FROM orders WHERE user_id=?", [uid])["status"]
    assert_equal "active", @container.db.one("SELECT status FROM subscriptions WHERE user_id=?", [uid])["status"]
    assert_equal 100, @container.wallet.balance(uid)["balance_kopeks"]
  end
end
