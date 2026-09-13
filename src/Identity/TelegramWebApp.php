<?php
declare(strict_types=1);
namespace App\Identity;

use App\Billing\BillingError;
use App\Infrastructure\Database;

/** Verifies Telegram Web App initData before creating a normal cabinet session. */
final class TelegramWebApp
{
    public function __construct(private Database $db, private Auth $auth, private string $botToken) {}

    public function authenticate(string $initData): string
    {
        if ($this->botToken === '' || strlen($initData) > 8192) throw new BillingError('Mini App пока не настроен.');
        parse_str($initData, $data);
        $hash = $data['hash'] ?? null;
        $authDate = filter_var($data['auth_date'] ?? null, FILTER_VALIDATE_INT);
        $rawUser = $data['user'] ?? null;
        if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash) || $authDate === false || !is_string($rawUser)) throw new BillingError('Telegram не передал данные для входа. Откройте приложение из бота.');
        if ($authDate > time() + 60 || $authDate < time() - 86400) throw new BillingError('Данные Telegram устарели. Закройте и откройте приложение снова.');
        unset($data['hash']);
        ksort($data, SORT_STRING);
        $check = implode("\n", array_map(static fn(string $key, mixed $value): string => $key.'='.$value, array_keys($data), $data));
        $secret = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        if (!hash_equals(hash_hmac('sha256', $check, $secret), $hash)) throw new BillingError('Не удалось подтвердить вход через Telegram.');
        try { $user = json_decode($rawUser, true, 16, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new BillingError('Некорректные данные пользователя Telegram.'); }
        $telegramId = $user['id'] ?? null;
        if (!is_int($telegramId) && !(is_string($telegramId) && preg_match('/^[1-9][0-9]{0,19}$/D', $telegramId))) throw new BillingError('Некорректный Telegram ID.');
        $this->db->execute('INSERT INTO users(id,telegram_id,created_at) VALUES(?,?,?) ON CONFLICT(telegram_id) DO NOTHING', [Database::id(), (string)$telegramId, time()]);
        $account = $this->db->one('SELECT id,disabled FROM users WHERE telegram_id=?', [(string)$telegramId]);
        if (!$account || (int)$account['disabled'] === 1) throw new BillingError('Этот аккаунт недоступен.');
        return $this->auth->issue($account['id']);
    }
}
