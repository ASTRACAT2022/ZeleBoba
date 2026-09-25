module Billing
  class PollService
    def create(input:, actor:)
      attrs = input.to_h.symbolize_keys
      title = attrs[:title].to_s.strip
      reward = attrs[:reward_amount_kopeks].to_i
      questions = attrs[:questions]
      raise BillingError, "Заголовок: 1–255 символов." unless title.length.between?(1, 255)
      raise BillingError, "Награда: от 0 до 1 000 000 ₽." unless reward.between?(0, 100_000_000)
      raise BillingError, "Добавьте 1–10 вопросов." unless questions.is_a?(Array) && questions.length.between?(1, 10)

      Poll.transaction do
        poll = Poll.create!(id: Infrastructure::IdGenerator.call, title: title,
          description: attrs[:description], reward_enabled: reward.positive? ? 1 : 0,
          reward_amount_kopeks: reward, created_by: actor, created_at: Time.now.to_i)
        questions.each_with_index do |raw, index|
          q = raw.to_h.symbolize_keys
          text = q[:text].to_s.strip
          options = q[:options]
          raise BillingError, "Проверьте вопрос и его варианты." unless text.length.between?(1, 500) && options.is_a?(Array) && options.length.between?(2, 20)
          options = options.map { |option| raise(BillingError, "Варианты ответа должны быть текстом.") unless option.is_a?(String); option.strip }
          raise BillingError, "Варианты ответа должны быть уникальными и непустыми." if options.any? { |option| option.empty? || option.length > 500 } || options.uniq.length != options.length
          PollQuestion.create!(id: Infrastructure::IdGenerator.call, poll_id: poll.id, text: text,
            options: options.to_json, order: index)
        end
        AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: actor, action: "poll.created", subject: poll.id, created_at: Time.now.to_i)
        poll
      end
    end

    def list
      Poll.includes(:questions).order(created_at: :desc).limit(500)
    end

    def submit(user_id:, poll_id:, answers:)
      Poll.transaction do
        poll = Poll.lock.find_by(id: poll_id)
        raise BillingError, "Опрос не найден." unless poll
        user = User.find_by(id: user_id)
        raise BillingError, "Аккаунт недоступен." unless user && user.disabled.to_i.zero?
        raise BillingError, "Вы уже участвовали в этом опросе." if PollResponse.exists?(poll_id: poll.id, user_id: user_id)
        questions = poll.questions.order(:order).to_a
        normalized = questions.each_with_index.map do |question, index|
          answer = answers.is_a?(Array) ? answers[index] : nil
          options = JSON.parse(question.options)
          raise BillingError, "Выберите один из вариантов ответа." unless answer.is_a?(String) && options.include?(answer)
          answer
        end
        reward = poll.reward_enabled.to_i == 1 ? poll.reward_amount_kopeks.to_i : 0
        WalletService.new.credit(user_id, reward, "referral_reward", "Награда за опрос: #{poll.title}") if reward.positive?
        PollResponse.create!(id: Infrastructure::IdGenerator.call, poll_id: poll.id, user_id: user_id,
          answers: normalized.to_json, reward_paid: reward.positive? ? 1 : 0, created_at: Time.now.to_i)
        { reward: reward }
      end
    end
  end
end
