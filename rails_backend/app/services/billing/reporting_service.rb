module Billing
  class ReportingService
    SPEND_TYPES = %w[subscription_purchase subscription_renewal gift_purchase traffic_topup device_addon trial_conversion].freeze

    def sales_stats(days:)
      since = Time.now.to_i - days * 86_400
      revenue = LedgerEntry.where(account: "provider_clearing").where("created_at > ?", since).sum(:amount_minor).to_i
      orders = Order.where(status: %w[paid fulfilled]).where("created_at > ?", since).count
      topups = Topup.where(status: "paid").where("created_at > ?", since).count
      new_users = User.where("created_at > ?", since).count
      active_subs = Subscription.where(status: %w[active trial]).where("expires_at > ?", Time.now.to_i).count
      trials = Subscription.where(is_trial: 1, status: %w[active trial]).count
      gifts = GuestPurchase.where(is_gift: 1, status: %w[paid pending_activation delivered]).where("created_at > ?", since).count
      { revenue_kopeks: revenue, orders: orders, topups: topups, new_users: new_users,
        active_subscriptions: active_subs, active_trials: trials, gifts: gifts,
        avg_check_kopeks: orders.positive? ? revenue / orders : 0,
        conversion_percent: new_users.positive? ? (orders * 100.0 / new_users).round : 0 }
    end

    def earnings_overview
      now = Time.now
      day_start = now.beginning_of_day.to_i
      periods = { today: ["Сегодня", day_start], week: ["7 дней", day_start - 6 * 86_400],
        month: ["Месяц", now.beginning_of_month.to_i], year: ["Год", now.beginning_of_year.to_i] }
      periods.to_h do |key, (label, since)|
        revenue = LedgerEntry.where(account: "provider_clearing").where("created_at >= ?", since).sum(:amount_minor).to_i
        orders = Order.where(status: %w[paid fulfilled]).where("created_at >= ?", since).count
        topups = Topup.where(status: "paid").where("created_at >= ?", since).count
        [key, { label: label, revenue_kopeks: revenue, orders: orders, topups: topups }]
      end
    end

    def daily_revenue(days:)
      since = Time.now.to_i - days * 86_400
      LedgerEntry.where(account: "provider_clearing").where("created_at > ?", since).order(:created_at).pluck(:created_at, :amount_minor)
        .each_with_object({}) { |(created_at, amount), rows| key = Time.at(created_at).utc.strftime("%Y-%m-%d"); rows[key] = rows.fetch(key, 0) + amount.to_i }
    end

    def revenue_by_provider(days:)
      paid_order_groups(days, :provider)
    end

    def revenue_by_plan(days:)
      paid_order_groups(days, :plan_name)
    end

    def revenue_by_type(days:)
      since = Time.now.to_i - days * 86_400
      topups = Topup.where(status: "paid").where("created_at > ?", since).sum(:amount_kopeks).to_i
      spend = TransactionRecord.where(type: SPEND_TYPES).where("amount_kopeks < 0 AND created_at > ?", since)
      { topups_kopeks: topups,
        purchases_kopeks: -spend.where(type: %w[subscription_purchase subscription_renewal trial_conversion]).sum(:amount_kopeks).to_i,
        gifts_kopeks: -spend.where(type: "gift_purchase").sum(:amount_kopeks).to_i,
        addons_kopeks: -spend.where(type: %w[traffic_topup device_addon]).sum(:amount_kopeks).to_i }
    end

    def top_customers(days:, limit: 10)
      since = Time.now.to_i - days * 86_400
      TransactionRecord.joins(:user).where(type: SPEND_TYPES).where("transactions.amount_kopeks < 0 AND transactions.created_at > ?", since)
        .group("users.id", "users.email", "users.telegram_id")
        .order(Arel.sql("COALESCE(SUM(-transactions.amount_kopeks), 0) DESC")).limit(limit).pluck("users.id", "users.email", "users.telegram_id", Arel.sql("COALESCE(SUM(-transactions.amount_kopeks), 0)"))
        .map { |id, email, telegram_id, spent| { id: id, email: email, telegram_id: telegram_id, spent: spent.to_i } }
    end

    def top_referrers(limit: 10)
      ReferralEarning.joins(:user).group("users.id", "users.email", "users.telegram_id")
        .order(Arel.sql("SUM(referral_earnings.amount_kopeks) DESC")).limit(limit)
        .pluck("users.id", "users.email", "users.telegram_id", Arel.sql("SUM(referral_earnings.amount_kopeks)"), Arel.sql("COUNT(DISTINCT referral_earnings.referral_id)"))
        .map { |id, email, telegram_id, total, referrals| { id: id, email: email, telegram_id: telegram_id, total: total.to_i, referrals: referrals.to_i } }
    end

    def user_spending(user_id)
      spent = TransactionRecord.where(user_id: user_id, type: SPEND_TYPES).where("amount_kopeks < 0").sum(:amount_kopeks).to_i
      topups = Topup.where(user_id: user_id, status: "paid").sum(:amount_kopeks).to_i
      { spent_kopeks: -spent, topups_kopeks: topups }
    end

    private

    def paid_order_groups(days, column)
      since = Time.now.to_i - days * 86_400
      Order.where(status: %w[paid fulfilled]).where("created_at > ?", since).group(column)
        .order(Arel.sql("SUM(price_minor) DESC")).pluck(column, Arel.sql("COUNT(*)"), Arel.sql("SUM(price_minor)"))
        .map { |label, count, total| { column => label, cnt: count.to_i, total: total.to_i } }
    end
  end
end
