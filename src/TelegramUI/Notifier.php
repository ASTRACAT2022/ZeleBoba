<?php
declare(strict_types=1);
namespace App\TelegramUI;

use App\Infrastructure\{Database,Outbox};

/** Updates an order screen after async provisioning, while keeping chat history tidy. */
final class Notifier
{
    public function __construct(private Database $db, private Outbox $outbox) {}

    /** Return true when this account already has the persistent rich UI card. */
    public function orderChanged(string $userId, string $orderId): bool
    {
        $state = $this->db->one('SELECT * FROM telegram_ui_state WHERE user_id=?', [$userId]);
        if (!$state) return false;
        if ($state['screen'] !== 'order' || $state['context_id'] !== $orderId) return true;
        $order = $this->db->one('SELECT * FROM orders WHERE id=? AND user_id=?', [$orderId,$userId]);
        if (!$order) return true;
        $subscription = $this->db->one('SELECT * FROM subscriptions WHERE order_id=? AND user_id=?', [$orderId,$userId]);
        $payload = [
            'chat_id'=>$state['chat_id'], 'message_id'=>(int)$state['message_id'],
            'rich_message'=>Screens::buildOrderScreen($order,$subscription),
            '_ui_user_id'=>$userId, '_ui_screen'=>'order', '_ui_context_id'=>$orderId,
        ];
        $this->outbox->enqueue('telegram.rich.edit','rich-order:'.$orderId.':'.$order['status'].':'.(int)($subscription['updated_at'] ?? 0),$payload);
        return true;
    }
}
