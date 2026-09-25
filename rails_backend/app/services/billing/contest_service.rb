module Billing
  class ContestService
    def attempt(user_id:, round_id:)
      ApplicationRecord.transaction do
        round = ContestRound.lock.find_by(id: round_id)
        raise BillingError, "Раунд не активен." unless round&.status == "running"
        template = ContestTemplate.lock.find_by(id: round.template_id)
        raise BillingError, "Конкурс отключён." unless template && template.is_enabled.to_i == 1
        value = template.prize_value.to_s
        maximum = { "balance" => 100_000_000, "days" => 3650, "traffic" => 100_000 }[template.prize_type]
        raise BillingError, "Приз конкурса не настроен." unless maximum && value.match?(/\A\d+\z/) && value.to_i.between?(1, maximum)
        existing = ContestAttempt.find_by(round_id: round.id, user_id: user_id)
        next({ won: existing.won.to_i == 1, prize: existing.prize_value, already: true }) if existing
        user = User.find_by(id: user_id)
        raise BillingError, "Аккаунт недоступен." unless user && user.disabled.to_i.zero?

        now = Time.now.to_i
        user_attempts = ContestAttempt.joins(:round)
          .where(user_id: user_id, contest_rounds: { template_id: template.id })
        raise BillingError, "Лимит попыток на сегодня исчерпан." if user_attempts.where("contest_attempts.created_at > ?", now - 86_400).count >= template.times_per_day.to_i
        latest = user_attempts.maximum(:created_at)
        if latest && latest.to_i + template.cooldown_hours.to_i * 3600 > now
          raise BillingError, "Дождитесь окончания перерыва между попытками."
        end

        subscription = eligible_subscription(user_id, template)
        winners = ContestAttempt.where(round_id: round.id, won: 1).count
        won = winners < template.max_winners.to_i && SecureRandom.random_number(100).between?(0, 19)
        prize = won ? value : nil
        ContestAttempt.create!(id: Infrastructure::IdGenerator.call, round_id: round.id,
          user_id: user_id, won: won ? 1 : 0, prize_value: prize, created_at: now)
        award(user_id, round, template, value.to_i, subscription) if won
        { won: won, prize: prize, already: false }
      end
    rescue ActiveRecord::RecordNotUnique
      prior = ContestAttempt.find_by(round_id: round_id, user_id: user_id)
      raise BillingError, "Попытка конкурса уже обработана." unless prior
      { won: prior.won.to_i == 1, prize: prior.prize_value, already: true }
    end

    def finish_round(round_id:, actor:)
      ApplicationRecord.transaction do
        round = ContestRound.lock.find_by(id: round_id)
        raise BillingError, "Раунд не найден." unless round
        next false unless round.status == "running"
        now = Time.now.to_i
        round.update!(status: "finished", ends_at: now)
        AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor,
          action: "contest.round_finished", subject: round.id, created_at: now)
        true
      end
    end

    private

    def eligible_subscription(user_id, template)
      return unless %w[days traffic].include?(template.prize_type)
      scope = Subscription.where(user_id: user_id, status: %w[active trial]).where("expires_at > ?", Time.now.to_i)
      scope = scope.where("traffic_limit_gb > 0") if template.prize_type == "traffic"
      sub = scope.order(expires_at: :desc).lock.first
      raise BillingError, template.prize_type == "traffic" ? "Для приза нужен действующий тариф с лимитом трафика." : "Для приза нужна действующая подписка." unless sub
      sub
    end

    def award(user_id, round, template, value, subscription)
      case template.prize_type
      when "balance"
        WalletService.new.credit(user_id, value, "referral_reward", "Приз за конкурс: #{template.name}")
      when "days"
        now = Time.now.to_i
        subscription.update!(expires_at: [now, subscription.expires_at.to_i].max + value * 86_400,
          status: "active", lifecycle_status: "active", updated_at: now)
        Infrastructure::OutboxService.new.enqueue("subscription.extend",
          "contest-extend:#{round.id}:#{user_id}:#{subscription.id}", { subscription_id: subscription.id })
      when "traffic"
        subscription.update!(purchased_traffic_gb: subscription.purchased_traffic_gb.to_i + value, updated_at: Time.now.to_i)
        Infrastructure::OutboxService.new.enqueue("subscription.traffic",
          "contest-traffic:#{round.id}:#{user_id}:#{subscription.id}", { subscription_id: subscription.id, traffic_gb: value })
      else
        raise BillingError, "Приз конкурса не настроен."
      end
    end
  end
end
