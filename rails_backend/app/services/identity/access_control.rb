module Identity
  class AccessControl
    PERMISSIONS = %w[
      admin.view admin.users admin.compensations admin.plans admin.promocodes admin.broadcasts
      admin.channels admin.landings admin.contests admin.polls admin.campaigns admin.withdrawals
      admin.settings admin.sync admin.backup admin.reports admin.roles admin.audit admin.monitoring
      admin.maintenance operations.view operations.view_technical operations.view_financial
      operations.retry_provisioning operations.reconcile sentry.view
    ].freeze

    def permissions(user)
      return [] unless user
      return PERMISSIONS if user.role == "admin"

      UserRole.joins(:admin_role).where(user_id: user.id, is_active: 1)
              .where("user_roles.expires_at IS NULL OR user_roles.expires_at > ?", Time.now.to_i)
              .where(admin_roles: { is_active: 1 }).pluck("admin_roles.permissions").flat_map do |value|
        JSON.parse(value.presence || "[]")
      end.uniq & PERMISSIONS
    end

    def allowed?(user, permission)
      permissions(user).include?(permission)
    end
  end
end
