module Identity
  # Implements the existing browser challenge, one-time magic link, and
  # Telegram account-link code flows against the shared legacy schema.
  class TelegramAuthentication
    CHALLENGE_TTL = 5.minutes
    LINK_TTL = 10.minutes
    TELEGRAM_ID = /\A[1-9][0-9]{0,19}\z/

    def initialize(authentication: Authentication.new)
      @authentication = authentication
    end

    # Returns the plaintext bot start token and the browser-only proof cookie.
    # Persisted values are hashes, so a database read cannot impersonate either.
    def begin_login
      token = SecureRandom.hex(24)
      browser = SecureRandom.hex(32)
      now = Time.now.to_i
      LoginChallenge.create!(token_hash: digest(token), browser_hash: digest(browser), kind: "browser",
                             state: "pending", expires_at: now + CHALLENGE_TTL.to_i, created_at: now)
      { token: token, browser: browser }
    end

    def pending?(token)
      return false unless token.to_s.match?(/\A[a-f0-9]{48}\z/)

      LoginChallenge.exists?(token_hash: digest(token), kind: "browser", state: "pending",
                             expires_at: Time.now.to_i..)
    end

    # Called only after a private Telegram callback has authenticated the sender.
    def approve_login(token:, telegram_id:)
      return false unless token.to_s.match?(/\A[a-f0-9]{48}\z/)

      ApplicationRecord.transaction do
        challenge = LoginChallenge.lock.find_by(token_hash: digest(token), kind: "browser", state: "pending")
        next false unless challenge && challenge.expires_at.to_i > Time.now.to_i

        user = telegram_user!(telegram_id)
        challenge.update!(state: "approved", user_id: user.id)
        true
      end
    end

    def ready?(browser)
      return false unless browser.to_s.match?(/\A[a-f0-9]{64}\z/)

      LoginChallenge.exists?(browser_hash: digest(browser), kind: "browser", state: "approved",
                             expires_at: Time.now.to_i..)
    end

    # `kind` is browser for the HttpOnly browser proof, magic for a bot link.
    # Challenge state change and session creation share a transaction, making
    # concurrent replay attempts single-use.
    def consume_login(proof:, kind:)
      raise Billing::BillingError, "Ссылка входа недействительна." unless %w[browser magic].include?(kind.to_s) && proof.to_s.match?(/\A[a-f0-9]{64}\z/)

      ApplicationRecord.transaction do
        column = kind.to_s == "browser" ? :browser_hash : :token_hash
        challenge = LoginChallenge.lock.find_by(column => digest(proof), kind: kind.to_s, state: "approved")
        raise Billing::BillingError, "Ссылка уже использована или истекла. Получите новую в боте." unless challenge && challenge.expires_at.to_i > Time.now.to_i

        user = User.lock.find_by(id: challenge.user_id)
        raise Billing::BillingError, "Аккаунт отключён." unless user && user.disabled.to_i.zero?

        session = @authentication.issue(user.id)
        challenge.update!(state: "consumed")
        AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: user.id, action: "auth.telegram",
                         subject: user.id, created_at: Time.now.to_i)
        session
      end
    end

    # Telegram /login and the bot's cabinet action use an independently
    # generated five-minute magic token; older approved links are invalidated.
    def issue_magic_link(telegram_id:)
      ApplicationRecord.transaction do
        user = telegram_user!(telegram_id)
        # Consume locks a magic challenge before touching its user. Keep the
        # same row-lock order here to prevent a consume/new-link deadlock.
        LoginChallenge.where(user_id: user.id, kind: "magic", state: "approved").lock.load
        LoginChallenge.where(user_id: user.id, kind: "magic", state: "approved").update_all(state: "consumed")
        token = SecureRandom.hex(32)
        now = Time.now.to_i
        LoginChallenge.create!(token_hash: digest(token), user_id: user.id, kind: "magic", state: "approved",
                               expires_at: now + CHALLENGE_TTL.to_i, created_at: now)
        token
      end
    end

    # Issue plaintext link code to an authenticated web session; only its hash
    # is persisted. Reissuing replaces every previous code for this account.
    def issue_link_code(user_id:)
      token = SecureRandom.hex(24)
      now = Time.now.to_i
      ApplicationRecord.transaction do
        # Match consume_link_code's link-then-user lock order to avoid a
        # deadlock when a user requests a new code while Telegram redeems one.
        TelegramLink.where(user_id: user_id).lock.load
        user = User.lock.find_by(id: user_id)
        raise Billing::BillingError, "Аккаунт отключён." unless user && user.disabled.to_i.zero?

        TelegramLink.where(user_id: user.id).delete_all
        TelegramLink.create!(token_hash: digest(token), user_id: user.id, expires_at: now + LINK_TTL.to_i)
      end
      token
    end

    # Called from the Telegram bot's private `/link CODE` handler. The identity
    # mapping and legacy users.telegram_id projection are kept in sync.
    def consume_link_code(token:, telegram_id:)
      raise Billing::BillingError, "Некорректный Telegram ID." unless telegram_id.to_s.match?(TELEGRAM_ID)
      return false unless token.to_s.match?(/\A[a-f0-9]{48}\z/)

      ApplicationRecord.transaction do
        link = TelegramLink.lock.find_by(token_hash: digest(token))
        next false unless link && link.expires_at.to_i > Time.now.to_i

        target = User.lock.find_by(id: link.user_id)
        raise Billing::BillingError, "Аккаунт отключён." unless target && target.disabled.to_i.zero?

        identity = UserIdentity.lock.find_by(type: "telegram", external_id: telegram_id.to_s)
        already_linked = target.telegram_id.to_s
        if (identity && identity.user_id != target.id) || (already_linked.present? && already_linked != telegram_id.to_s)
          raise Billing::BillingError, "Telegram уже связан с аккаунтом. Обратитесь к администратору для переноса данных."
        end

        attach_telegram_identity!(target, telegram_id.to_s)
        target.update!(telegram_id: telegram_id.to_s)
        link.destroy!
        true
      end
    rescue ActiveRecord::RecordNotUnique
      raise Billing::BillingError, "Telegram уже связан с аккаунтом. Обратитесь к администратору для переноса данных."
    end

    # Resolves the shared identity registry, backfills legacy telegram_id rows,
    # and creates Telegram-only accounts exactly as the PHP implementation does.
    def telegram_user!(telegram_id)
      tg = telegram_id.to_s
      raise Billing::BillingError, "Некорректный Telegram ID." unless tg.match?(TELEGRAM_ID)

      user = UserIdentity.find_by(type: "telegram", external_id: tg)&.user
      user ||= User.find_by(telegram_id: tg)
      if user
        raise Billing::BillingError, "Аккаунт отключён." unless user.disabled.to_i.zero?

        attach_telegram_identity!(user, tg)
        return user
      end

      now = Time.now.to_i
      connection = ApplicationRecord.connection
      values = [Infrastructure::IdGenerator.call, tg, now].map { |value| connection.quote(value) }
      connection.execute("INSERT INTO users (id, telegram_id, created_at) VALUES (#{values.join(', ')}) ON CONFLICT (telegram_id) DO NOTHING")
      user = User.find_by!(telegram_id: tg)
      attach_telegram_identity!(user, tg)
      user
    end

    private

    def attach_telegram_identity!(user, telegram_id)
      existing = UserIdentity.lock.find_by(type: "telegram", external_id: telegram_id)
      if existing && existing.user_id != user.id
        raise Billing::BillingError, "Эта identity уже связана с другим аккаунтом."
      end
      return existing if existing

      connection = ApplicationRecord.connection
      values = [Infrastructure::IdGenerator.call, user.id, "telegram", telegram_id, Time.now.to_i, Time.now.to_i].map do |value|
        connection.quote(value)
      end
      connection.execute("INSERT INTO user_identities (id, user_id, type, external_id, verified_at, created_at) VALUES (#{values.join(', ')}) ON CONFLICT (type, external_id) DO NOTHING")
      existing = UserIdentity.find_by!(type: "telegram", external_id: telegram_id)
      raise Billing::BillingError, "Эта identity уже связана с другим аккаунтом." unless existing.user_id == user.id

      existing
    end

    def digest(value)
      Digest::SHA256.hexdigest(value.to_s)
    end
  end
end
