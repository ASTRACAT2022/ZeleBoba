<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\{Database,Outbox};
final class AutoPurchaseService
{
    public function __construct(private Database $db, private Outbox $outbox, private Wallet $wallet, private CartService $carts, private BillingService $billing) {}
    /**
     * After a successful topup, if the user saved a cart with topup intent,
     * charge the balance and complete the purchase. Idempotent.
     */
    public function afterTopup(string $userId): void
    {
        $cart = $this->carts->get($userId);
        if (!$cart || empty($cart['_intent'])) return;
        $this->carts->clearIntent($userId);
        $kind = $cart['kind'] ?? 'subscription';
        try {
            if ($kind === 'subscription') {
                $this->purchaseSubscription($userId, $cart);
            } elseif ($kind === 'gift') {
                $this->purchaseGift($userId, $cart);
            } elseif ($kind === 'traffic') {
                $this->purchaseTraffic($userId, $cart);
            } elseif ($kind === 'devices') {
                $this->purchaseDevices($userId, $cart);
            }
        } catch (BillingError $e) {
            // Keep the cart so the user can retry; notify via outbox.
            $this->carts->save($userId, $cart, true);
            $this->outbox->enqueue('telegram.send', 'cart-failed:' . $userId . ':' . time(), [
                'chat_id' => $this->telegramId($userId),
                'text' => 'Не удалось завершить покупку после пополнения: ' . $e->getMessage() . ' Пополните баланс или повторите покупку в кабинете.',
            ]);
        }
    }
    private function purchaseSubscription(string $userId, array $cart): void
    {
        $planId = (string)($cart['plan_id'] ?? '');
        $key = (string)($cart['idempotency_key'] ?? 'cart:' . $userId . ':' . $planId);
        $plan = $this->db->one('SELECT * FROM plans WHERE id=? AND active=1', [$planId]);
        if (!$plan) throw new BillingError('Тариф больше недоступен.');
        $this->db->transaction(function () use ($userId, $plan, $key) {
            $this->wallet->debit($userId, (int)$plan['price_minor'], 'subscription_purchase', 'Покупка подписки: ' . $plan['name']);
            $order = $this->billing->order($userId, $plan['id'], $key, null, null);
            $this->billing->settleFromBalance($order['id'], (int)$plan['price_minor'], 'RUB');
        });
        $this->carts->delete($userId);
    }
    private function purchaseGift(string $userId, array $cart): void
    {
        $planId = (string)($cart['plan_id'] ?? '');
        $plan = $this->db->one('SELECT * FROM plans WHERE id=? AND active=1', [$planId]);
        if (!$plan) throw new BillingError('Тариф для подарка больше недоступен.');
        $this->db->transaction(function () use ($userId, $plan, $cart) {
            $this->wallet->debit($userId, (int)$plan['price_minor'], 'gift_purchase', 'Покупка подарочной подписки: ' . $plan['name']);
            $this->outbox->enqueue('gift.create', 'gift:' . $userId . ':' . $plan['id'] . ':' . time(), [
                'user_id' => $userId,
                'plan_id' => $plan['id'],
                'price_minor' => (int)$plan['price_minor'],
                'currency' => 'RUB',
            ]);
        });
        $this->carts->delete($userId);
    }
    private function purchaseTraffic(string $userId, array $cart): void
    {
        $subscriptionId = (string)($cart['subscription_id'] ?? '');
        $gb = (int)($cart['traffic_gb'] ?? 0);
        $price = (int)($cart['price_kopeks'] ?? 0);
        if ($gb < 1 || $price < 1) throw new BillingError('Некорректный пакет трафика.');
        $this->db->transaction(function () use ($userId, $subscriptionId, $gb, $price) {
            $sub = $this->db->one('SELECT * FROM subscriptions WHERE id=? AND user_id=? AND status=\'active\'' . $this->db->lock(), [$subscriptionId, $userId]);
            if (!$sub) throw new BillingError('Подписка не найдена.');
            $this->wallet->debit($userId, $price, 'traffic_topup', 'Докупка трафика: ' . $gb . ' ГБ');
            $this->db->execute('UPDATE subscriptions SET purchased_traffic_gb = purchased_traffic_gb + ? WHERE id=?', [$gb, $subscriptionId]);
            $this->outbox->enqueue('subscription.traffic', 'traffic:' . $subscriptionId . ':' . $gb, ['subscription_id' => $subscriptionId, 'traffic_gb' => $gb]);
        });
        $this->carts->delete($userId);
    }
    private function purchaseDevices(string $userId, array $cart): void
    {
        $subscriptionId = (string)($cart['subscription_id'] ?? '');
        $count = (int)($cart['devices'] ?? 0);
        $price = (int)($cart['price_kopeks'] ?? 0);
        if ($count < 1 || $price < 1) throw new BillingError('Некорректное количество устройств.');
        $this->db->transaction(function () use ($userId, $subscriptionId, $count, $price) {
            $sub = $this->db->one('SELECT * FROM subscriptions WHERE id=? AND user_id=? AND status=\'active\'' . $this->db->lock(), [$subscriptionId, $userId]);
            if (!$sub) throw new BillingError('Подписка не найдена.');
            $this->wallet->debit($userId, $price, 'device_addon', 'Докупка устройств: +' . $count);
            $this->db->execute('UPDATE subscriptions SET device_limit = device_limit + ? WHERE id=?', [$count, $subscriptionId]);
            $this->outbox->enqueue('subscription.devices', 'devices:' . $subscriptionId . ':' . $count, ['subscription_id' => $subscriptionId, 'devices' => $count]);
        });
        $this->carts->delete($userId);
    }
    private function telegramId(string $userId): string
    {
        return (string)($this->db->one('SELECT telegram_id FROM users WHERE id=?', [$userId])['telegram_id'] ?? '');
    }
}
