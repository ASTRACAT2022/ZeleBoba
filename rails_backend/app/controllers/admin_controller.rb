class AdminController < ApplicationController
  before_action :require_recent_admin_mfa!

  def overview
    return unless require_permission!("admin.view")
    render json: {
      users: User.count,
      active_subscriptions: Subscription.where(status: "active").count,
      pending_orders: Order.where(status: "pending").count,
      paid_total_minor: Payment.where(status: "succeeded").sum(:amount_minor)
    }
  end

  def users
    return unless require_permission!("admin.users")
    query = params[:q].to_s.strip
    if query.present?
      render json: Admin::UserAdminService.new.search(query, limit: page_limit)
    else
      scope = User.order(created_at: :desc)
      render json: scope.limit(page_limit).offset(page_offset).as_json(only: %i[id email telegram_id role disabled balance_kopeks created_at])
    end
  end

  def adjust_user_balance
    return unless require_permission!("admin.users")
    Admin::UserAdminService.new.adjust_balance(user_id: params[:id], amount_kopeks: params[:amount_kopeks],
      reason: params[:reason], actor: current_user.id)
    head :no_content
  end

  def grant_user_days
    return unless require_permission!("admin.users")
    record = Admin::UserAdminService.new.grant_days(user_id: params[:id], days: params[:days],
      plan_id: params[:plan_id], actor: current_user.id)
    render json: record.as_json, status: :created
  end

  def set_user_discount
    return unless require_permission!("admin.users")
    Admin::UserAdminService.new.set_discount(user_id: params[:id], percent: params[:percent],
      hours: params[:hours], actor: current_user.id)
    head :no_content
  end

  def clear_user_discount
    return unless require_permission!("admin.users")
    Admin::UserAdminService.new.clear_discount(user_id: params[:id], actor: current_user.id)
    head :no_content
  end

  def admin_subscription
    return unless require_permission!("admin.users")
    render json: Admin::UserAdminService.new.subscription(params[:id])
  end

  def admin_update_subscription_traffic
    return unless require_permission!("admin.users")
    Admin::UserAdminService.new.update_subscription_traffic(user_id: params[:user_id],
      subscription_id: params[:subscription_id], traffic_gb: params[:traffic_gb], actor: current_user.id)
    head :no_content
  end

  def admin_update_subscription_devices
    return unless require_permission!("admin.users")
    Admin::UserAdminService.new.update_subscription_devices(user_id: params[:user_id],
      subscription_id: params[:subscription_id], devices: params[:devices], actor: current_user.id)
    head :no_content
  end

  def admin_extend_subscription
    return unless require_permission!("admin.users")
    Admin::UserAdminService.new.extend_subscription(user_id: params[:user_id],
      subscription_id: params[:subscription_id], days: params[:days], actor: current_user.id)
    head :no_content
  end

  def admin_set_subscription_expiry
    return unless require_permission!("admin.users")
    Admin::UserAdminService.new.set_subscription_expiry(user_id: params[:user_id],
      subscription_id: params[:subscription_id], expires_at: params[:expires_at], actor: current_user.id)
    head :no_content
  end

  def admin_reset_subscription_traffic
    return unless require_permission!("admin.users")
    Admin::UserAdminService.new.reset_subscription_traffic(user_id: params[:user_id],
      subscription_id: params[:subscription_id], actor: current_user.id)
    head :no_content
  end

  def admin_remove_subscription
    return unless require_permission!("admin.users")
    Admin::UserAdminService.new.remove_subscription(user_id: params[:user_id],
      subscription_id: params[:subscription_id], actor: current_user.id)
    head :accepted
  end

  def user
    return unless require_permission!("admin.users")
    render json: Admin::UserAdminService.new.profile(params[:id])
  end

  def disable_user
    return unless require_permission!("admin.users")
    reason = params[:reason].to_s.strip
    raise Billing::BillingError, "Укажите причину." if reason.blank? || reason.length > 500
    target = User.find(params[:id])
    raise Billing::BillingError, "Нельзя отключить собственный аккаунт." if target.id == current_user.id
    raise Billing::BillingError, "Нельзя отключить аккаунт администратора." if target.role == "admin"

    ApplicationRecord.transaction do
      target.update!(disabled: ActiveModel::Type::Boolean.new.cast(params[:disabled]) ? 1 : 0)
      Session.where(user_id: target.id).delete_all if target.disabled.to_i == 1
      AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: current_user.id,
                       action: target.disabled.to_i == 1 ? "user.disabled" : "user.enabled",
                       subject: target.id, created_at: Time.now.to_i)
      write_admin_audit!("user.access_changed", "user", target.id, reason)
    end
    head :no_content
  end

  def plans
    return unless require_permission!("admin.plans")
    render json: Plan.order(:price_minor).as_json(only: %i[id name price_minor currency duration_days duration_months traffic_bytes devices active squad_uuid])
  end

  def settings
    return unless require_permission!("admin.settings")
    render json: Infrastructure::SettingsService.new.form
  end

  def save_settings
    return unless require_permission!("admin.settings")
    accepted = params.require(:settings).permit(*Infrastructure::SettingsService::DEFAULTS.keys, :revision)
    revision = accepted.delete(:revision)
    form = Infrastructure::SettingsService.new.save(input: accepted.to_h, actor: current_user.id, revision: revision)
    render json: form
  end

  def maintenance
    return unless require_permission!("admin.maintenance")
    enabled = Infrastructure::MaintenanceService.new.set(
      enabled: ActiveModel::Type::Boolean.new.cast(params[:enabled]), actor: current_user.id
    )
    render json: { enabled: enabled }
  end

  def maintenance_status
    return unless require_permission!("admin.maintenance")
    render json: { enabled: Infrastructure::MaintenanceService.new.status }
  end

  def user_state_at
    return unless require_permission!("operations.view")
    render json: Operations::IntelligenceService.new.time_travel(user_id: params[:id], at: params[:at])
  end

  def simulate_user_action
    return unless require_permission!("operations.view")
    render json: Operations::IntelligenceService.new.simulate(user_id: params[:id],
      plan_id: params[:plan_id], promo_percent: params[:promo_percent], action: params[:action_name].presence || "renew")
  end

  def subscription_graph
    return unless require_permission!("operations.view")
    graph = Operations::IntelligenceService.new.graph(subscription_id: params[:id])
    return not_found unless graph
    render json: graph
  end

  def blast_radius
    return unless require_permission!("operations.view")
    render json: Operations::IntelligenceService.new.blast_radius(service: params[:service].presence || "all")
  end

  def intelligence
    return unless require_permission!("operations.view")
    render json: Operations::IntelligenceService.new.overview
  end

  def run_invariants
    return unless require_permission!("admin.settings")
    render json: Operations::IntelligenceService.new.invariants(create_cases: true)
  end

  def feature_flags
    return unless require_permission!("admin.settings")
    render json: { flags: Operations::ControlPlaneService.new.flags }
  end

  def set_feature_flag
    return unless require_permission!("admin.settings")
    flag = Operations::ControlPlaneService.new.set_flag(name: params[:name],
      enabled: ActiveModel::Type::Boolean.new.cast(params[:enabled]), rollout: params.fetch(:rollout, 100), actor: current_user.id)
    render json: flag.as_json
  end

  def switches
    return unless require_permission!("admin.settings")
    render json: Operations::ControlPlaneService.new.switches
  end

  def set_switch
    return unless require_permission!("admin.settings")
    result = Operations::ControlPlaneService.new.set_switch(name: params[:name],
      enabled: ActiveModel::Type::Boolean.new.cast(params[:enabled]), reason: params[:reason], actor: current_user.id)
    render json: result.is_a?(Hash) ? result : result.as_json
  end

  def reset_circuit_breaker
    return unless require_permission!("admin.settings")
    render json: Operations::ControlPlaneService.new.reset_breaker(name: params[:name], actor: current_user.id).as_json
  end

  def approvals
    return unless require_permission!("admin.settings")
    render json: Operations::ControlPlaneService.new.approvals
  end

  def decide_approval
    return unless require_permission!("admin.settings")
    record = Operations::ControlPlaneService.new.decide_approval(id: params[:id],
      decision: params[:decision], actor: current_user.id)
    render json: record.as_json(only: %i[id action actor status requested_at decided_at decided_by])
  end

  def incidents
    return unless require_permission!("operations.view")
    render json: { incidents: Operations::ControlPlaneService.new.incidents }
  end

  def create_incident
    return unless require_permission!("operations.view")
    record = Operations::ControlPlaneService.new.create_incident(title: params[:title], actor: current_user.id)
    render json: record.as_json, status: :created
  end

  def set_safety_mode
    return unless require_permission!("admin.settings")
    enabled = Operations::IntelligenceService.new.set_safety(
      enabled: ActiveModel::Type::Boolean.new.cast(params[:enabled]), actor: current_user.id
    )
    render json: { enabled: enabled }
  end

  def create_maintenance_window
    return unless require_permission!("admin.settings")
    window = Operations::IntelligenceService.new.set_maintenance_window(
      service: params[:service], starts_at: params[:starts_at], ends_at: params[:ends_at],
      note: params[:note], actor: current_user.id
    )
    render json: window.as_json, status: :created
  end

  def investigations
    return unless require_permission!("operations.view")
    service = Operations::InvestigationService.new
    render json: { investigations: service.list, queues: service.smart_queues }
  end

  def investigation
    return unless require_permission!("operations.view")
    record = Operations::InvestigationService.new.detail(params[:id])
    return not_found unless record
    render json: record
  end

  def start_investigation
    return unless require_permission!("operations.view")
    record = Operations::InvestigationService.new.start(type: params[:subject_type],
      subject_id: params[:subject_id], title: params[:title], actor: current_user.id)
    render json: record.as_json, status: :created
  end

  def add_investigation_note
    return unless require_permission!("operations.view")
    Operations::InvestigationService.new.note(case_id: params[:id], body: params[:body], actor: current_user.id)
    head :created
  end

  def resolve_investigation
    return unless require_permission!("operations.view")
    Operations::InvestigationService.new.resolve(case_id: params[:id], actor: current_user.id)
    head :no_content
  end

  def expected_actual
    return unless require_permission!("operations.view")
    result = Operations::InvestigationService.new.expected_actual(params[:id])
    return not_found unless result
    render json: result
  end

  def why_not_renewed
    return unless require_permission!("operations.view")
    render json: Operations::InvestigationService.new.why_not_renewed(params[:id])
  end

  def operations
    return unless require_permission!("operations.view")
    result = Operations::OperationQueryService.new.search(query: params[:q], type: params[:type],
      status: params[:status], client: params[:client], provider: params[:provider],
      http_status: params[:http_status], period: params[:period].presence || "7d")
    render json: { operations: result }
  end

  def events
    return unless require_permission!("operations.view")
    result = Operations::OperationQueryService.new.search(query: params[:q], type: params[:type],
      status: params[:status], client: params[:client], period: params[:period].presence || "7d")
    render json: { events: result }
  end

  def operation
    return unless require_permission!("operations.view")
    service = Operations::OperationQueryService.new
    record = service.detail(params[:id])
    return not_found unless record
    render json: record.merge(support_summary: service.support_summary(params[:id]))
  end

  def provisioning_accounts
    return unless require_permission!("operations.view")
    render json: { accounts: Operations::OperationQueryService.new.provisioning_accounts }
  end

  def retry_provisioning
    return unless require_permission!("operations.retry_provisioning")
    reason = params[:reason].to_s.strip
    raise Billing::BillingError, "Укажите причину повторной синхронизации (до 200 символов)." if reason.blank? || reason.length > 200

    ApplicationRecord.transaction do
      account = ProvisioningAccount.lock.find_by(id: params[:id])
      raise ActiveRecord::RecordNotFound unless account
      topic = account.external_user_id.blank? ? "subscription.provision" : "subscription.extend"
      now = Time.now.to_i
      account.update!(state: "retry", last_error: nil, updated_at: now)
      correlation_id = "cor_#{Infrastructure::IdGenerator.call}"
      Infrastructure::OutboxService.new.enqueue(topic,
        "manual-retry:#{account.subscription_id}:#{Infrastructure::IdGenerator.call}",
        { subscription_id: account.subscription_id, requested_by: current_user.id, reason: reason },
        correlation_id: correlation_id)
      AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: current_user.id,
        action: "provisioning.retry_requested", subject: account.subscription_id, created_at: now)
    end
    head :accepted
  end

  def explain_subscription
    return unless require_permission!("operations.view")
    result = Operations::OperationQueryService.new.explain_subscription(params[:id])
    return not_found unless result
    render json: result
  end

  def preview_subscription_extension
    return unless require_permission!("operations.view")
    months = Integer(params[:months] || 1)
    raise Billing::BillingError, "1–36 месяцев." unless months.between?(1, 36)
    sub = Subscription.find_by(id: params[:id])
    return not_found unless sub
    after = Billing::SubscriptionTerms.expiry_after([Time.now.to_i, sub.expires_at.to_i].max, 0, months)
    render json: { sub: sub.as_json, months: months, after: after }
  rescue ArgumentError, TypeError
    raise Billing::BillingError, "1–36 месяцев."
  end

  def sync_remnawave
    return unless require_permission!("admin.sync")
    config = Infrastructure::RuntimeConfig.new
    raise Billing::BillingError, "Сначала настройте Remnawave и реальный платёжный драйвер." if
      config.fetch("PAYMENT_DRIVER", "demo") == "demo" || config.fetch("REMNAWAVE_URL", "").blank? || config.fetch("REMNAWAVE_TOKEN", "").blank?
    scope = Subscription.where(status: "active", lifecycle_status: "active").where("expires_at > ?", Time.now.to_i)
      .order(updated_at: :asc).limit(100)
    ids = scope.pluck(:id)
    ApplicationRecord.transaction do
      ids.each do |id|
        Infrastructure::OutboxService.new.enqueue("subscription.admin_sync",
          "admin-sync:#{id}:#{Time.now.to_i / 300}", { subscription_id: id })
      end
      AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: current_user.id,
        action: "remnawave.sync_queued", subject: ids.length.to_s, created_at: Time.now.to_i)
    end
    render json: { queued: ids.length, subscriptions: ids }, status: :accepted
  end

  def import_remnawave_missing
    return unless require_permission!("admin.sync")
    config = Infrastructure::RuntimeConfig.new
    raise Billing::BillingError, "Сначала настройте Remnawave и реальный платёжный драйвер." if
      config.fetch("PAYMENT_DRIVER", "demo") == "demo" || config.fetch("REMNAWAVE_URL", "").blank? || config.fetch("REMNAWAVE_TOKEN", "").blank?

    fix = params[:fix].to_s == "1"
    report = Integrations::RemnawaveImportService.new.run(limit: params[:limit].presence || 200,
      start_page: params[:start_page].presence || 1, fix: fix)
    write_admin_audit!(fix ? "remnawave.import_missing" : "remnawave.import_preview", "remnawave",
      "panel", "scanned=#{report[:scanned]} imported=#{report[:imported]} errors=#{report[:errors]}")
    render json: report
  end

  def retry_dead_job
    return unless require_permission!("admin.monitoring")
    changed = OutboxJob.where(id: params[:id], status: "dead").update_all(
      status: "pending", attempts: 0, available_at: Time.now.to_i,
      locked_until: nil, lock_token: nil, last_error: nil)
    raise Billing::BillingError, "Задача не найдена или не находится в dead-letter." if changed.zero?
    audit_admin_event!("job.retried", params[:id])
    head :accepted
  end

  def check_integration
    return unless require_permission!("admin.settings")
    result = Integrations::IntegrationCheckService.new.check(params[:integration],
      register_webhook: ActiveModel::Type::Boolean.new.cast(params[:register_webhook]))
    render json: result
  end

  def create_plan
    return unless require_permission!("admin.plans")
    attrs = plan_params
    plan = Billing::PlanAdminService.new.create(input: attrs, actor: current_user.id)
    render json: plan.as_json(only: %i[id name price_minor currency duration_days duration_months traffic_bytes devices active squad_uuid autorenew_days_before autorenew_max_fails]), status: :created
  end

  def update_plan
    return unless require_permission!("admin.plans")
    plan = Billing::PlanAdminService.new.update(id: params[:id], input: plan_params, actor: current_user.id)
    render json: plan.as_json(only: %i[id name price_minor currency duration_days duration_months traffic_bytes devices active squad_uuid autorenew_days_before autorenew_max_fails])
  end

  def refund
    return unless require_permission!("admin.compensations")
    record = Billing::RefundService.new.request(
      payment_id: params[:payment_id], amount_minor: params[:amount_minor],
      reason: params[:reason], actor: current_user.id
    )
    render json: record.as_json(only: %i[id payment_id order_id user_id amount_minor currency reason status created_at completed_at])
  end

  def refunds
    return unless require_permission!("admin.compensations")
    render json: Billing::RefundService.new.history(limit: params[:limit]).as_json(
      only: %i[id payment_id order_id user_id amount_minor currency reason status created_at completed_at]
    )
  end

  def promocodes
    return unless require_permission!("admin.promocodes")
    render json: Billing::PromoCodeService.new.list(limit: params[:limit]).as_json(
      only: %i[id code type balance_bonus_kopeks subscription_days traffic_gb max_uses current_uses valid_from valid_until is_active first_purchase_only plan_id created_at]
    )
  end

  def create_promocode
    return unless require_permission!("admin.promocodes")
    input = params.permit(:code, :type, :balance_bonus_kopeks, :subscription_days, :traffic_gb,
                          :max_uses, :valid_from, :valid_until, :first_purchase_only, :plan_id).to_h
    promo = Billing::PromoCodeService.new.create(input: input, actor: current_user.id)
    render json: promo.as_json, status: :created
  end

  def toggle_promocode
    return unless require_permission!("admin.promocodes")
    active = ActiveModel::Type::Boolean.new.cast(params[:active])
    Billing::PromoCodeService.new.toggle(id: params[:id], active: active, actor: current_user.id)
    head :no_content
  end

  def referral_withdrawals
    return unless require_permission!("admin.withdrawals")
    scope = WithdrawalRequest.includes(:user).order(created_at: :asc)
    scope = scope.where(status: params[:status]) if params[:status].present?
    render json: scope.limit(page_limit).map { |row|
      row.as_json(only: %i[id user_id amount_kopeks status payment_details risk_score created_at])
        .merge(user: row.user.as_json(only: %i[email telegram_id]))
    }
  end

  def process_referral_withdrawal
    return unless require_permission!("admin.withdrawals")
    row = Billing::ReferralService.new.process_withdrawal(
      params[:id], params[:status].to_s, current_user.id, comment: params[:comment]
    )
    render json: row.as_json(only: %i[id user_id amount_kopeks status risk_score processed_by processed_at admin_comment])
  end

  def creators
    return unless require_permission!("admin.withdrawals")
    render json: Creator.order(created_at: :desc).limit(page_limit).map do |creator|
      creator.as_json(only: %i[id user_id name code status first_percent recurring_percent recurring_days hold_days attribution_days created_at updated_at])
        .merge(earned_minor: CreatorLedger.where(creator_id: creator.id).sum(:amount_minor).to_i)
    end
  end

  def set_creator
    return unless require_permission!("admin.withdrawals")
    attrs = params.permit(:code, :first_percent, :recurring_percent, :recurring_days, :hold_days, :attribution_days)
    creator = Billing::CreatorService.new.activate(params[:user_id], attrs.to_h, current_user.id)
    write_admin_audit!("creator.updated", "creator", creator.id, "Admin updated creator program settings")
    render json: creator.as_json(only: %i[id user_id name code status first_percent recurring_percent recurring_days hold_days attribution_days])
  end

  def suspend_creator
    return unless require_permission!("admin.withdrawals")
    Billing::CreatorService.new.suspend(params[:user_id], current_user.id)
    head :no_content
  end

  def reconcile_creators
    return unless require_permission!("admin.withdrawals")
    render json: Billing::CreatorService.new.reconcile(actor: current_user.id)
  end

  def creator_payouts
    return unless require_permission!("admin.withdrawals")
    scope = CreatorPayout.includes(:creator).order(requested_at: :asc)
    scope = scope.where(status: params[:status]) if params[:status].present?
    render json: scope.limit(page_limit).as_json(only: %i[id creator_id amount_minor details status requested_at processed_at processed_by operation_id comment])
  end

  def process_creator_payout
    return unless require_permission!("admin.withdrawals")
    payout = Billing::CreatorService.new.process_payout(
      params[:id], params[:status].to_s, current_user.id,
      operation_id: params[:operation_id], comment: params[:comment]
    )
    render json: payout.as_json(only: %i[id creator_id amount_minor status processed_at processed_by operation_id comment])
  end

  def broadcasts
    return unless require_permission!("admin.broadcasts")
    render json: Billing::BroadcastService.new.list(limit: params[:limit]).as_json(
      only: %i[id target_type message_text total_count sent_count failed_count blocked_count status admin_id admin_name category created_at completed_at]
    )
  end

  def create_broadcast
    return unless require_permission!("admin.broadcasts")
    broadcast = Billing::BroadcastService.new.create(
      target_type: params[:target_type].to_s, text: params[:text].to_s,
      admin_id: current_user.id, admin_name: current_user.email.presence || current_user.id,
      category: params[:category].presence || "system"
    )
    render json: broadcast.as_json, status: :created
  end

  def compensations
    return unless require_permission!("admin.compensations")
    render json: Billing::CompensationService.new.list(limit: params[:limit])
  end

  def create_compensation
    return unless require_permission!("admin.compensations")
    compensation = Billing::CompensationService.new.create(
      segment: params[:segment].to_s, kind: params[:kind].to_s, value: params[:value],
      reason: params[:reason], admin_id: current_user.id,
      admin_name: current_user.email.presence || current_user.id,
      plan_id: params[:plan_id], request_key: params[:request_key]
    )
    render json: compensation.as_json, status: :created
  end

  def campaigns
    return unless require_permission!("admin.campaigns")
    render json: Billing::CampaignService.new.list.as_json(
      only: %i[id name start_parameter bonus_type balance_bonus_kopeks subscription_duration_days plan_id partner_user_id is_active created_by created_at]
    )
  end

  def create_campaign
    return unless require_permission!("admin.campaigns")
    attrs = params.permit(:name, :start_parameter, :bonus_type, :balance_bonus_kopeks,
                          :subscription_duration_days, :plan_id, :partner_user_id)
    campaign = Billing::CampaignService.new.create(input: attrs.to_h, actor: current_user.id)
    render json: campaign.as_json, status: :created
  end

  def toggle_campaign
    return unless require_permission!("admin.campaigns")
    campaign = AdvertisingCampaign.find(params[:id])
    campaign.update!(is_active: ActiveModel::Type::Boolean.new.cast(params[:active]) ? 1 : 0)
    write_admin_audit!("campaign.toggled", "campaign", campaign.id, "Admin changed campaign status")
    head :no_content
  end

  def polls
    return unless require_permission!("admin.polls")
    render json: Billing::PollService.new.list.map do |poll|
      poll.as_json(only: %i[id title description reward_enabled reward_amount_kopeks created_by created_at])
        .merge(questions: poll.questions.order(:order).as_json(only: %i[id text options order]))
    end
  end

  def create_poll
    return unless require_permission!("admin.polls")
    poll = Billing::PollService.new.create(input: params.permit(:title, :description, :reward_amount_kopeks, questions: [:text, { options: [] }]).to_h, actor: current_user.id)
    render json: poll.as_json, status: :created
  end

  def contests
    return unless require_permission!("admin.contests")
    render json: ContestTemplate.includes(:rounds).order(created_at: :desc).limit(500).map do |template|
      template.as_json(only: %i[id name slug description prize_type prize_value max_winners attempts_per_user times_per_day cooldown_hours is_enabled created_at])
        .merge(rounds: template.rounds.order(created_at: :desc).limit(25).as_json(only: %i[id status starts_at ends_at created_at]))
    end
  end

  def create_contest
    return unless require_permission!("admin.contests")
    attrs = params.permit(:name, :slug, :description, :prize_type, :prize_value, :max_winners, :times_per_day, :cooldown_hours).to_h.symbolize_keys
    name = attrs[:name].to_s.strip
    slug = attrs[:slug].to_s.strip
    raise Billing::BillingError, "Название: 1–100 символов." unless name.length.between?(1, 100)
    raise Billing::BillingError, "Слаг: 2–50 символов a-z, 0-9, -." unless slug.match?(/\A[a-z0-9-]{2,50}\z/)
    type = attrs[:prize_type].presence || "days"
    maximum = { "balance" => 100_000_000, "days" => 3650, "traffic" => 100_000 }[type]
    value = attrs[:prize_value].to_s
    raise Billing::BillingError, "Некорректное значение приза." unless maximum && value.match?(/\A\d+\z/) && value.to_i.between?(1, maximum)
    winners = (attrs[:max_winners].presence || 1).to_i
    per_day = (attrs[:times_per_day].presence || 1).to_i
    cooldown = (attrs[:cooldown_hours].presence || 24).to_i
    raise Billing::BillingError, "Некорректные ограничения конкурса." unless winners.between?(1, 100_000) && per_day.between?(1, 100) && cooldown.between?(0, 8760)
    template = ContestTemplate.create!(id: Infrastructure::IdGenerator.call, name: name, slug: slug,
      description: attrs[:description], prize_type: type, prize_value: value, max_winners: winners,
      attempts_per_user: 1, times_per_day: per_day, cooldown_hours: cooldown, is_enabled: 1, created_at: Time.now.to_i)
    AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: current_user.id, action: "contest.created", subject: template.id, created_at: Time.now.to_i)
    render json: template.as_json, status: :created
  rescue ActiveRecord::RecordNotUnique
    raise Billing::BillingError, "Слаг конкурса уже используется."
  end

  def start_contest_round
    return unless require_permission!("admin.contests")
    template = ContestTemplate.find(params[:id])
    now = Time.now.to_i
    round = ContestRound.create!(id: Infrastructure::IdGenerator.call, template_id: template.id, status: "running", starts_at: now, created_at: now)
    AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: current_user.id, action: "contest.round_started", subject: round.id, created_at: now)
    render json: round.as_json, status: :created
  end

  def finish_contest_round
    return unless require_permission!("admin.contests")
    finished = Billing::ContestService.new.finish_round(round_id: params[:id], actor: current_user.id)
    render json: { finished: finished }
  end

  def channels
    return unless require_permission!("admin.channels")
    render json: RequiredChannel.order(:sort_order, :created_at).as_json(
      only: %i[id channel_id channel_link title is_active sort_order disable_trial_on_leave disable_paid_on_leave created_at]
    )
  end

  def create_channel
    return unless require_permission!("admin.channels")
    channel_id = params[:channel_id].to_s.strip
    link = params[:channel_link].to_s.strip
    title = params[:title].to_s.strip
    valid_id = channel_id.match?(/\A-?[0-9]{5,20}\z/) || channel_id.match?(/\A@[A-Za-z0-9_]{4,32}\z/)
    raise Billing::BillingError, "Некорректный ID канала (число или @username)." unless valid_id
    raise Billing::BillingError, "Ссылка: https://t.me/..." if link.present? && !link.start_with?("https://t.me/")
    channel = RequiredChannel.create!(id: Infrastructure::IdGenerator.call, channel_id: channel_id,
      channel_link: link.presence, title: title.presence, is_active: 1, sort_order: 0, created_at: Time.now.to_i)
    audit_admin_event!("channel.added", channel.id)
    render json: channel.as_json, status: :created
  rescue ActiveRecord::RecordNotUnique
    raise Billing::BillingError, "Канал уже добавлен."
  end

  def toggle_channel
    return unless require_permission!("admin.channels")
    channel = RequiredChannel.find(params[:id])
    active = ActiveModel::Type::Boolean.new.cast(params[:active])
    channel.update!(is_active: active ? 1 : 0)
    audit_admin_event!(active ? "channel.enabled" : "channel.disabled", channel.id)
    head :no_content
  end

  def remove_channel
    return unless require_permission!("admin.channels")
    channel = RequiredChannel.find(params[:id])
    audit_admin_event!("channel.removed", channel.id)
    channel.destroy!
    head :no_content
  end

  def landings
    return unless require_permission!("admin.landings")
    render json: LandingPage.order(:display_order, :created_at).as_json
  end

  def save_landing
    return unless require_permission!("admin.landings")
    attrs = params.permit(:slug, :is_active, :title, :subtitle, :features, :footer_text,
      :allowed_plan_ids, :payment_methods, :gift_enabled, :custom_css, :meta_title,
      :meta_description, :display_order, :discount_percent, :discount_starts_at, :discount_ends_at).to_h.symbolize_keys
    slug = attrs[:slug].to_s.strip
    title = attrs[:title].to_s.strip
    raise Billing::BillingError, "Слаг: 2–100 символов a-z, 0-9, -." unless slug.match?(/\A[a-z0-9-]{2,100}\z/)
    raise Billing::BillingError, "Заголовок: 1–200 символов." unless title.length.between?(1, 200)
    discount = attrs[:discount_percent].presence&.to_i
    raise Billing::BillingError, "Скидка: 1–99%." if discount && !discount.between?(1, 99)
    now = Time.now.to_i
    landing = LandingPage.find_or_initialize_by(slug: slug)
    landing.assign_attributes(attrs.except(:slug).merge(
      id: landing.id || Infrastructure::IdGenerator.call,
      is_active: attrs.key?(:is_active) ? (ActiveModel::Type::Boolean.new.cast(attrs[:is_active]) ? 1 : 0) : 1,
      gift_enabled: attrs.key?(:gift_enabled) ? (ActiveModel::Type::Boolean.new.cast(attrs[:gift_enabled]) ? 1 : 0) : 1,
      discount_percent: discount,
      discount_starts_at: attrs[:discount_starts_at].presence&.to_i,
      discount_ends_at: attrs[:discount_ends_at].presence&.to_i,
      display_order: attrs[:display_order].presence&.to_i || 0,
      title: title,
      created_at: landing.created_at || now
    ))
    landing.save!
    audit_admin_event!("landing.saved", slug)
    render json: landing.as_json, status: landing.previously_new_record? ? :created : :ok
  rescue ActiveRecord::RecordNotUnique
    raise Billing::BillingError, "Слаг лендинга уже используется."
  end

  def toggle_landing
    return unless require_permission!("admin.landings")
    landing = LandingPage.find_by!(slug: params[:id])
    active = ActiveModel::Type::Boolean.new.cast(params[:active])
    landing.update!(is_active: active ? 1 : 0)
    audit_admin_event!(active ? "landing.enabled" : "landing.disabled", landing.slug)
    head :no_content
  end

  def reports
    return unless require_permission!("admin.reports")
    days = params[:days].to_i
    days = 30 unless days.between?(1, 365)
    service = Billing::ReportingService.new
    render json: {
      stats: service.sales_stats(days: days),
      daily: service.daily_revenue(days: [days, 30].min),
      by_provider: service.revenue_by_provider(days: days),
      by_plan: service.revenue_by_plan(days: days),
      by_type: service.revenue_by_type(days: days),
      top_customers: service.top_customers(days: days),
      top: service.top_referrers,
      days: days,
      earnings: service.earnings_overview
    }
  end

  def monitoring
    return unless require_permission!("admin.monitoring")
    anomalies = Subscription.joins(:user).where(status: %w[active trial])
      .where("subscriptions.traffic_limit_gb > 0 AND subscriptions.traffic_used_gb > subscriptions.traffic_limit_gb * 1.5")
      .order(traffic_used_gb: :desc).limit(20)
      .as_json(only: %i[id user_id traffic_used_gb traffic_limit_gb expires_at])
    render json: { events: MonitoringLog.order(created_at: :desc).limit(50).as_json,
      errors: SystemErrorEvent.order(last_seen: :desc).limit(50).as_json, anomalies: anomalies }
  end

  def clear_monitoring_errors
    return unless require_permission!("admin.monitoring")
    SystemErrorEvent.delete_all
    audit_admin_event!("monitoring.errors_cleared", "all")
    head :no_content
  end

  def roles
    return unless require_permission!("admin.roles")
    render json: {
      roles: AdminRole.order(level: :desc, created_at: :asc).as_json(only: %i[id name description level permissions color icon is_system is_active created_by created_at]),
      users: User.order(created_at: :desc).limit(100).as_json(only: %i[id email telegram_id])
    }
  end

  def create_role
    return unless require_permission!("admin.roles")
    attrs = params.permit(:name, :description, :level, permissions: [])
    name = attrs[:name].to_s.strip
    permissions = attrs[:permissions]
    raise Billing::BillingError, "Название роли: 1–100 символов." unless name.length.between?(1, 100)
    raise Billing::BillingError, "Некорректный список прав." unless permissions.is_a?(Array)
    invalid = permissions.find { |permission| !Identity::AccessControl::PERMISSIONS.include?(permission) }
    raise Billing::BillingError, "Некорректное право: #{invalid}" if invalid
    role = AdminRole.create!(id: Infrastructure::IdGenerator.call, name: name,
      description: attrs[:description].presence, level: attrs[:level].to_i,
      permissions: permissions.uniq.to_json, is_system: 0, is_active: 1,
      created_by: current_user.id, created_at: Time.now.to_i)
    AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: current_user.id,
      action: "role.created", subject: role.id, created_at: Time.now.to_i)
    render json: role.as_json, status: :created
  rescue ActiveRecord::RecordNotUnique
    raise Billing::BillingError, "Роль с таким названием уже существует."
  end

  def assign_role
    return unless require_permission!("admin.roles")
    user = User.find(params[:user_id])
    role = AdminRole.find_by!(id: params[:role_id], is_active: 1)
    expires = params[:expires_at].presence&.to_i
    raise Billing::BillingError, "Срок действия должен быть датой в будущем." if expires && expires <= Time.now.to_i
    row = UserRole.find_or_initialize_by(user_id: user.id, role_id: role.id)
    row.assign_attributes(id: row.id || Infrastructure::IdGenerator.call, assigned_by: current_user.id,
      assigned_at: Time.now.to_i, expires_at: expires, is_active: 1)
    row.save!
    AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: current_user.id,
      action: "role.assigned", subject: user.id, created_at: Time.now.to_i)
    head :no_content
  end

  def revoke_role
    return unless require_permission!("admin.roles")
    user_id = params[:user_id].to_s
    role_id = params[:role_id].to_s
    User.find(user_id)
    AdminRole.find(role_id)
    UserRole.where(user_id: user_id, role_id: role_id).update_all(is_active: 0)
    AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: current_user.id,
      action: "role.revoked", subject: user_id, created_at: Time.now.to_i)
    head :no_content
  end

  def admin_audit
    return unless require_permission!("admin.audit")
    render json: AdminAuditLog.order(created_at: :desc).limit(page_limit).offset(page_offset).as_json
  end

  def retry_compensation
    return unless require_permission!("admin.compensations")
    count = Billing::CompensationService.new.retry_failed(params[:id], current_user.id)
    render json: { retried: count }
  end

  private

  def require_permission!(permission)
    return true if Identity::AccessControl.new.allowed?(current_user, permission)

    render json: { error: "Недостаточно прав." }, status: :forbidden
    false
  end

  def require_recent_admin_mfa!
    admin = Identity::AccessControl.new.allowed?(current_user, "admin.view")
    enabled = current_user&.totp_secret.present?
    recent = current_session&.admin_verified_until.to_i > Time.now.to_i
    return if admin && enabled && recent

    render json: { error: "Требуется актуальная проверка MFA и право администратора." }, status: :forbidden
  end

  def write_admin_audit!(action, resource_type, resource_id, reason)
    AdminAuditLog.create!(
      id: Infrastructure::IdGenerator.call, user_id: current_user.id, action: action,
      resource_type: resource_type, resource_id: resource_id,
      details: JSON.generate(reason: reason), ip_address: request.remote_ip.to_s.first(45),
      user_agent: request.user_agent.to_s.first(1000), status: "success",
      request_method: request.request_method, request_path: request.path, created_at: Time.now.to_i
    )
  end

  def audit_admin_event!(action, subject)
    AuditLog.create!(id: Infrastructure::IdGenerator.call, actor: current_user.id,
      action: action, subject: subject, created_at: Time.now.to_i)
  end

  def plan_params
    params.permit(:name, :price_minor, :duration_days, :devices, :traffic_gb,
      :squad_uuid, :autorenew_days_before, :autorenew_max_fails, :active).to_h
  end

  def page_limit = [[params[:limit].to_i, 50].min, 1].max
  def page_offset = [params[:offset].to_i, 0].max
end
