<?php

namespace App\Domain\Admin;

enum Permission: string
{
    case UsersView = 'users.view';
    case UsersEdit = 'users.edit';
    case UsersBlock = 'users.block';
    case PaymentsView = 'payments.view';
    case PaymentsRefund = 'payments.refund';
    case PaymentsManage = 'payments.manage';
    case BalanceView = 'balance.view';
    case BalanceAdjust = 'balance.adjust';
    case SubscriptionsView = 'subscriptions.view';
    case SubscriptionsEdit = 'subscriptions.edit';
    case SubscriptionsCancel = 'subscriptions.cancel';
    case ResourcesView = 'resources.view';
    case ResourcesCreate = 'resources.create';
    case ResourcesDelete = 'resources.delete';
    case PromocodesManage = 'promocodes.manage';
    case WithdrawalsView = 'withdrawals.view';
    case WithdrawalsApprove = 'withdrawals.approve';
    case ProvidersManage = 'providers.manage';
    case SystemSettings = 'system.settings';
    case AuditView = 'audit.view';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
