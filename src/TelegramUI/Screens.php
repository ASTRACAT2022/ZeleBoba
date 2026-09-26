<?php
declare(strict_types=1);
namespace App\TelegramUI;

/** Static sample content used by the review prototype. No application services are called. */
final class Screens
{
    private static function nav(): array
    {
        return [
            Blocks::buttons([Blocks::button('Подписка', 'ui:subscription'), Blocks::button('Серверы', 'ui:servers')]),
            Blocks::buttons([Blocks::button('Платежи', 'ui:payments'), Blocks::button('Рефералы', 'ui:referrals')]),
            Blocks::buttons([Blocks::button('Профиль', 'ui:profile'), Blocks::button('Настройки', 'ui:settings')]),
            Blocks::buttons([Blocks::button('Поддержка', 'ui:support')]),
        ];
    }

    private static function footer(): array
    {
        return Blocks::footer('ASTRACAT  •  status.astracat.network');
    }

    public static function buildHomeScreen(array $user = [], ?array $subscription = null): array
    {
        $active = $subscription !== null && in_array(($subscription['status'] ?? ''), ['active','trial'], true) && (int)($subscription['expires_at'] ?? 0) > time();
        $status = $active ? '🟢 Активна' : ($subscription ? 'Истекла' : 'Нет подписки');
        $until = $subscription ? gmdate('d.m.Y', (int)$subscription['expires_at']) : '—';
        $days = $active ? max(0, (int)ceil(((int)$subscription['expires_at'] - time()) / 86400)).' дней' : '—';
        $used = $subscription ? (float)($subscription['traffic_used_gb'] ?? 0) : 0;
        $limit = $subscription ? (float)($subscription['traffic_limit_gb'] ?? 0) : 0;
        $traffic = !$subscription ? '—' : ($limit <= 0 ? number_format($used, 0, ',', ' ').' GB · безлимит' : number_format($used, 0, ',', ' ').' / '.number_format($limit, 0, ',', ' ').' GB');
        return ['blocks' => [
            Blocks::heading('ASTRACAT VPN', 1),
            Blocks::paragraph($active ? '🟢  Всё работает' : 'Подписка не активна'),
            Blocks::table([
                ['ПОДПИСКА', $status],
                ['ДО', $until],
                ['ОСТАЛОСЬ', $days],
                ['ТРАФИК', $traffic],
            ], true, false, true, null, false),
            Blocks::divider(),
            Blocks::buttons([Blocks::button('Подключить VPN', 'ui:connect', 'primary')]),
            Blocks::buttons([Blocks::button('Продлить подписку', 'ui:plans', 'success')]),
            ...self::nav(),
            self::footer(),
        ]];
    }

    public static function buildSubscriptionScreen(array $subscriptions = []): array
    {
        if (!$subscriptions) return ['blocks' => [
            Blocks::heading('Подписка', 1),
            Blocks::paragraph('Пока нет оформленной подписки.'),
            Blocks::buttons([Blocks::button('Выбрать тариф', 'ui:plans', 'success')]),
            Blocks::buttons([Blocks::button('← На главную', 'ui:home')]),
            self::footer(),
        ]];
        $primary = $subscriptions[0];
        $limit = (float)($primary['traffic_limit_gb'] ?? 0);
        $used = (float)($primary['traffic_used_gb'] ?? 0);
        $active = in_array(($primary['status'] ?? ''), ['active','trial'], true) && (int)$primary['expires_at'] > time();
        $traffic = $limit <= 0 ? 'Безлимит' : number_format($used, 0, ',', ' ').' / '.number_format($limit, 0, ',', ' ').' GB';
        $rows = [
            ['Параметр', 'Значение'],
            ['Статус', $active ? '🟢 Активна' : 'Истекла'],
            ['Тариф', (string)($primary['plan_name'] ?? 'Подписка')],
            ['Окончание', gmdate('d.m.Y', (int)$primary['expires_at'])],
            ['Трафик', $traffic],
        ];
        $startedAt = (int)($primary['starts_at'] ?? $primary['created_at'] ?? 0);
        if ($startedAt > 0) $rows[] = ['Начало', gmdate('d.m.Y', $startedAt)];
        $actions = [];
        foreach ($subscriptions as $subscription) {
            if (in_array(($subscription['status'] ?? ''), ['active','trial'], true) && (int)$subscription['expires_at'] > time()) {
                $actions[] = Blocks::buttons([Blocks::button('Продлить · '.(string)($subscription['plan_name'] ?? 'подписку'), 'renew:'.$subscription['id'], 'success')]);
                $actions[] = Blocks::buttons([Blocks::button(((int)($subscription['auto_renew'] ?? 0) === 1 ? 'Выключить' : 'Включить').' автопродление', 'autorenew:'.$subscription['id'])]);
            }
        }
        return ['blocks' => [
            Blocks::heading('Подписка', 1),
            Blocks::table($rows, true, false, true),
            ...$actions,
            ...($active && !empty($primary['subscription_url']) ? [Blocks::buttons([Blocks::urlButton('Подключиться', (string)$primary['subscription_url'], 'primary')]), Blocks::buttons([Blocks::copyButton('Скопировать ссылку', (string)$primary['subscription_url'])])] : []),
            Blocks::details('Что такое трафик?', [Blocks::paragraph($limit > 0 ? number_format($limit, 0, ',', ' ').' GB — объём данных, доступный в рамках текущего периода подписки.' : 'Текущий тариф не ограничивает объём трафика.')]),
            Blocks::buttons([Blocks::button('Выбрать тариф', 'ui:plans', 'success')]),
            Blocks::buttons([Blocks::button('← На главную', 'ui:home')]),
            self::footer(),
        ]];
    }

    public static function buildServersScreen(array $servers = []): array
    {
        $rows = [['Сервер', 'Ping', 'Load', 'Статус']];
        foreach ($servers as $server) $rows[] = [(string)$server['name'], (string)$server['ping'], (string)$server['load'], (string)$server['status']];
        return ['blocks' => [
            Blocks::heading('ASTRACAT Network', 1),
            Blocks::paragraph($servers ? '🌍  '.count($servers).' локаций' : 'Данные о локациях пока недоступны.'),
            ...($servers ? [Blocks::table($rows, true, true, true)] : []),
            Blocks::details('⚙ Техническая информация', [Blocks::paragraph('Параметры подключения определяются профилем пользователя.')]),
            Blocks::buttons([Blocks::button('Подключиться', 'ui:connect', 'primary')]),
            Blocks::buttons([Blocks::button('← На главную', 'ui:home')]),
            self::footer(),
        ]];
    }

    public static function buildConnectionScreen(array $subscription = []): array
    {
        return ['blocks' => [
            Blocks::heading('Подключить ASTRACAT', 1),
            Blocks::paragraph('Выберите устройство — покажем короткую инструкцию.'),
            Blocks::buttons([Blocks::button('iPhone', 'ui:connect:iphone'), Blocks::button('Android', 'ui:connect:android')]),
            Blocks::buttons([Blocks::button('Windows', 'ui:connect:windows'), Blocks::button('macOS', 'ui:connect:macos')]),
            Blocks::buttons([Blocks::button('Linux', 'ui:connect:linux'), Blocks::button('OpenWrt', 'ui:connect:openwrt')]),
            Blocks::buttons([Blocks::button('← На главную', 'ui:home')]),
            self::footer(),
        ]];
    }

    public static function buildConnectionGuideScreen(string $device = 'iPhone', ?string $subscriptionUrl = null): array
    {
        return ['blocks' => [
            Blocks::heading('Подключение · '.$device, 1),
            Blocks::list([
                ['value'=>1, 'blocks'=>[Blocks::paragraph('Установите VPN-клиент для '.$device.'.')]],
                ['value'=>2, 'blocks'=>[Blocks::paragraph('Нажмите «Добавить ASTRACAT».')]],
                ['value'=>3, 'blocks'=>[Blocks::paragraph('Подтвердите добавление конфигурации.')]],
                ['value'=>4, 'blocks'=>[Blocks::paragraph('Включите VPN в приложении.')]],
            ]),
            Blocks::paragraph('Подписка готова к подключению.'),
            ...($subscriptionUrl ? [Blocks::buttons([Blocks::urlButton('Добавить ASTRACAT', $subscriptionUrl, 'primary')]), Blocks::buttons([Blocks::copyButton('Скопировать ссылку', $subscriptionUrl)])] : [Blocks::paragraph('Ссылка подключения появится после активации подписки.')]),
            Blocks::details('⚙ Ручная настройка', [Blocks::paragraph($subscriptionUrl ?? 'Ссылка подключения появится после активации подписки.')]),
            Blocks::buttons([Blocks::button('← Выбрать устройство', 'ui:connect')]),
            self::footer(),
        ]];
    }

    public static function buildPlansScreen(array $plans = []): array
    {
        if (!$plans) return ['blocks' => [
            Blocks::heading('Продлить ASTRACAT', 1),
            Blocks::paragraph('Сейчас нет доступных тарифов.'),
            Blocks::buttons([Blocks::button('← На главную', 'ui:home')]),
            self::footer(),
        ]];
        $rows = [['Период', 'Трафик', 'Цена']];
        $actions = [];
        foreach ($plans as $plan) {
            $rows[] = [(string)$plan['duration'], (string)$plan['traffic'], (string)$plan['price']];
            $actions[] = Blocks::buttons([Blocks::button((string)$plan['duration'].' · '.(string)$plan['price'], 'ui:plan:'.$plan['id'], count($actions) === 0 ? 'success' : null)]);
        }
        return ['blocks' => [
            Blocks::heading('Продлить ASTRACAT', 1),
            Blocks::paragraph('Выберите период — стоимость и объём трафика сразу видны в таблице.'),
            Blocks::table($rows, true, true, true),
            ...$actions,
            Blocks::buttons([Blocks::button('← На главную', 'ui:home')]),
            self::footer(),
        ]];
    }

    public static function buildSelectedPlanScreen(array $plan): array
    {
        $traffic = (string)($plan['traffic'] ?? 'Безлимит');
        return ['blocks' => [
            Blocks::heading('Продление подписки', 1),
            Blocks::table([
                ['Период', 'Трафик'],
                [(string)$plan['duration'], $traffic],
            ], true, false, true, null, false),
            Blocks::heading((string)$plan['price'], 2),
            Blocks::paragraph('Подписка будет продлена после подтверждения оплаты.'),
            Blocks::buttons([Blocks::button('Продолжить · '.(string)$plan['price'], 'buy:'.$plan['id'], 'success')]),
            Blocks::buttons([Blocks::button('← Выбрать период', 'ui:plans')]),
            self::footer(),
        ]];
    }

    public static function buildOrderScreen(array $order, ?array $subscription = null): array
    {
        $status = (string)($order['status'] ?? 'pending');
        $statusLabel = match ($status) {
            'paid' => 'Получен · готовим доступ',
            'fulfilled' => 'Выполнен',
            'canceled', 'cancelled' => 'Отменён',
            default => 'Ожидает оплаты',
        };
        $rows = [
            ['Платёж', 'Детали'],
            ['Тариф', (string)($order['plan_name'] ?? 'Подписка')],
            ['Сумма', number_format((int)($order['price_minor'] ?? 0) / 100, 2, ',', ' ').' ₽'],
            ['Статус', $statusLabel],
        ];
        if ($subscription && isset($subscription['expires_at'])) $rows[] = ['Действует до', gmdate('d.m.Y', (int)$subscription['expires_at'])];
        $blocks = [Blocks::heading($status === 'fulfilled' ? 'Подписка готова' : 'Заказ', 1), Blocks::table($rows)];
        if ($status === 'pending' && !empty($order['checkout_url'])) $blocks[] = Blocks::buttons([Blocks::urlButton('Оплатить', (string)$order['checkout_url'], 'primary')]);
        if ($status === 'pending') $blocks[] = Blocks::buttons([Blocks::button('Обновить статус', 'order:'.$order['id'])]);
        if ($subscription && !empty($subscription['subscription_url'])) {
            $blocks[] = Blocks::buttons([Blocks::urlButton('Подключиться', (string)$subscription['subscription_url'], 'primary')]);
            $blocks[] = Blocks::buttons([Blocks::copyButton('Скопировать ссылку', (string)$subscription['subscription_url'])]);
        }
        $blocks[] = Blocks::buttons([Blocks::button('Мои платежи', 'ui:payments'), Blocks::button('На главную', 'ui:home')]);
        $blocks[] = self::footer();
        return ['blocks' => $blocks];
    }

    public static function buildPaymentSuccessScreen(array $payment = []): array
    {
        return ['blocks' => [
            Blocks::heading('Оплата прошла', 1),
            Blocks::paragraph('Платёж успешно обработан. Подписка продлена.'),
            Blocks::table([
                ['Платёж', 'Детали'],
                ['Сумма', '199 ₽'],
                ['Подписка', '+30 дней'],
                ['Действует до', '24 ноября'],
                ['Статус', '✅ Оплачено'],
            ]),
            Blocks::buttons([Blocks::button('Подключиться', 'ui:connect', 'primary')]),
            Blocks::buttons([Blocks::button('На главную', 'ui:home')]),
            self::footer(),
        ]];
    }

    public static function buildPaymentsScreen(array $payments = []): array
    {
        $rows = [['Дата', 'Покупка', 'Сумма']];
        foreach ($payments as $payment) $rows[] = [(string)$payment['date'], (string)$payment['product'], (string)$payment['amount']];
        return ['blocks' => [
            Blocks::heading('Платежи', 1),
            ...($payments ? [Blocks::table($rows, true, true, true)] : [Blocks::paragraph('Платежей пока нет.')]),
            Blocks::buttons([Blocks::button('Продлить подписку', 'ui:plans', 'success')]),
            Blocks::buttons([Blocks::button('← На главную', 'ui:home')]),
            self::footer(),
        ]];
    }

    public static function buildNetworkStatusScreen(array $systems = []): array
    {
        $rows = [['Сервис', 'Состояние']];
        foreach ($systems as $system) $rows[] = [(string)$system['name'], (string)$system['status']];
        $hasWarning = (bool)array_filter($systems, static fn(array $system): bool => str_contains((string)($system['status'] ?? ''), '🟡') || str_contains((string)($system['status'] ?? ''), '🔴'));
        return ['blocks' => [
            Blocks::heading('ASTRACAT Network', 1),
            Blocks::paragraph($systems ? 'Состояние компонентов backend' : 'Сводка состояния сети сейчас недоступна.'),
            ...($systems ? [Blocks::table($rows, true, false, true)] : []),
            ...($hasWarning ? [Blocks::expandableQuote('Статус компонента обновляется фоновым процессом. Если предупреждение сохраняется, напишите в поддержку.', 'Справка ASTRACAT')] : []),
            Blocks::buttons([Blocks::button('Серверы', 'ui:servers')]),
            Blocks::buttons([Blocks::button('← На главную', 'ui:home')]),
            self::footer(),
        ]];
    }

    public static function buildReferralScreen(array $referral = []): array
    {
        $count = (int)($referral['referral_count'] ?? 0);
        $paid = (int)($referral['paid_referrals'] ?? 0);
        $earned = number_format((int)($referral['earnings_kopeks'] ?? 0) / 100, 2, ',', ' ').' ₽';
        $rows = [
            ['Показатель', 'Всего'],
            ['Приглашено', (string)$count],
            ['Оплатили', (string)$paid],
            ['Заработано', $earned],
        ];
        if (!empty($referral['link'])) $rows[] = ['Ваша ссылка', (string)$referral['link']];
        return ['blocks' => [
            Blocks::heading('Рефералы', 1),
            Blocks::paragraph('Приглашайте друзей и получайте бонусы за их подписки.'),
            Blocks::table($rows, true, false, true),
            ...(!empty($referral['link']) ? [Blocks::buttons([Blocks::urlButton('Пригласить друга', (string)$referral['link'], 'primary')]), Blocks::buttons([Blocks::copyButton('Скопировать ссылку', (string)$referral['link'])])] : []),
            Blocks::details('Как начисляется бонус?', [Blocks::paragraph('Бонус начисляется после успешной оплаты приглашённого пользователя согласно условиям программы.')]),
            Blocks::buttons([Blocks::button('← На главную', 'ui:home')]),
            self::footer(),
        ]];
    }

    public static function buildProfileScreen(array $user = []): array
    {
        $rows = [['Поле', 'Данные']];
        $rows[] = ['Аккаунт', (string)($user['email'] ?? 'Telegram')];
        if (!empty($user['telegram_id'])) $rows[] = ['Telegram ID', (string)$user['telegram_id']];
        $rows[] = ['Баланс', number_format((int)($user['balance_kopeks'] ?? 0) / 100, 2, ',', ' ').' ₽'];
        return ['blocks' => [
            Blocks::heading('Профиль', 1),
            Blocks::table($rows, true, false, true),
            Blocks::buttons([Blocks::button('Настройки', 'ui:settings')]),
            Blocks::buttons([Blocks::button('← На главную', 'ui:home')]),
            self::footer(),
        ]];
    }

    public static function buildSettingsScreen(array $settings = []): array
    {
        $rows = [['Параметр', 'Состояние']];
        foreach ($settings as $setting) $rows[] = [(string)$setting['name'], (string)$setting['value']];
        return ['blocks' => [
            Blocks::heading('Настройки', 1),
            ...($settings ? [Blocks::table($rows, true, false, true)] : [Blocks::paragraph('Дополнительные настройки пока не передаются ботом.')]),
            Blocks::details('Уведомления', [Blocks::paragraph('Сообщаем об оплатах и скором окончании подписки.')]),
            Blocks::details('Конфиденциальность', [Blocks::paragraph('Данные аккаунта используются только для работы подписки и поддержки.')]),
            Blocks::buttons([Blocks::button('Поддержка', 'ui:support')]),
            Blocks::buttons([Blocks::button('← На главную', 'ui:home')]),
            self::footer(),
        ]];
    }

    public static function buildSupportScreen(array $support = []): array
    {
        return ['blocks' => [
            Blocks::heading('Поддержка', 1),
            Blocks::paragraph('Мы поможем с оплатой, настройкой подключения и вопросами по подписке.'),
            ...(!empty($support['url']) ? [Blocks::buttons([Blocks::urlButton('Написать в поддержку', (string)$support['url'], 'primary')])] : [Blocks::paragraph('Ссылка поддержки не настроена.')]),
            Blocks::buttons([Blocks::button('← На главную', 'ui:home')]),
            self::footer(),
        ]];
    }

    /** All screens rendered by the clickable local review prototype. */
    public static function buildPrototypeScreens(): array
    {
        $demoSubscription = [
            'id' => 'demo-subscription', 'plan_name' => 'ASTRACAT VPN · 650 GB', 'status' => 'active',
            'starts_at' => time() - 2 * 86400, 'expires_at' => time() + 28 * 86400,
            'traffic_limit_gb' => 650, 'traffic_used_gb' => 412,
            'subscription_url' => 'https://example.invalid/subscription/demo',
        ];
        $demoPlans = [
            ['id' => 'month-1', 'duration' => '1 месяц', 'traffic' => '650 GB', 'price' => '199 ₽'],
            ['id' => 'month-3', 'duration' => '3 месяца', 'traffic' => '1 950 GB', 'price' => '597 ₽'],
            ['id' => 'month-6', 'duration' => '6 месяцев', 'traffic' => '3 900 GB', 'price' => '1 194 ₽'],
            ['id' => 'month-12', 'duration' => '12 месяцев', 'traffic' => '7 800 GB', 'price' => '2 388 ₽'],
        ];
        $demoServers = [
            ['name' => '🇳🇱 NL', 'ping' => '24 ms', 'load' => '31%', 'status' => '🟢'],
            ['name' => '🇩🇪 DE', 'ping' => '32 ms', 'load' => '18%', 'status' => '🟢'],
            ['name' => '🇫🇷 FR', 'ping' => '39 ms', 'load' => '67%', 'status' => '🟡'],
            ['name' => '🇬🇷 GR', 'ping' => '58 ms', 'load' => '23%', 'status' => '🟢'],
            ['name' => '🇧🇪 BE', 'ping' => '35 ms', 'load' => '29%', 'status' => '🟢'],
        ];
        return [
            'home' => ['label' => 'Главная', 'rich_message' => self::buildHomeScreen([], $demoSubscription)],
            'subscription' => ['label' => 'Подписка', 'rich_message' => self::buildSubscriptionScreen([$demoSubscription])],
            'servers' => ['label' => 'Серверы', 'rich_message' => self::buildServersScreen($demoServers)],
            'connect' => ['label' => 'Подключение', 'rich_message' => self::buildConnectionScreen()],
            'connect_iphone' => ['label' => 'Инструкция · iPhone', 'rich_message' => self::buildConnectionGuideScreen('iPhone', $demoSubscription['subscription_url'])],
            'connect_android' => ['label' => 'Инструкция · Android', 'rich_message' => self::buildConnectionGuideScreen('Android', $demoSubscription['subscription_url'])],
            'connect_windows' => ['label' => 'Инструкция · Windows', 'rich_message' => self::buildConnectionGuideScreen('Windows', $demoSubscription['subscription_url'])],
            'connect_macos' => ['label' => 'Инструкция · macOS', 'rich_message' => self::buildConnectionGuideScreen('macOS', $demoSubscription['subscription_url'])],
            'connect_linux' => ['label' => 'Инструкция · Linux', 'rich_message' => self::buildConnectionGuideScreen('Linux', $demoSubscription['subscription_url'])],
            'connect_openwrt' => ['label' => 'Инструкция · OpenWrt', 'rich_message' => self::buildConnectionGuideScreen('OpenWrt', $demoSubscription['subscription_url'])],
            'plans' => ['label' => 'Продление', 'rich_message' => self::buildPlansScreen($demoPlans)],
            'plan_1' => ['label' => '1 месяц · 199 ₽', 'rich_message' => self::buildSelectedPlanScreen($demoPlans[0])],
            'plan_3' => ['label' => '3 месяца · 597 ₽', 'rich_message' => self::buildSelectedPlanScreen($demoPlans[1])],
            'plan_6' => ['label' => '6 месяцев · 1 194 ₽', 'rich_message' => self::buildSelectedPlanScreen($demoPlans[2])],
            'plan_12' => ['label' => '12 месяцев · 2 388 ₽', 'rich_message' => self::buildSelectedPlanScreen($demoPlans[3])],
            'payment_success' => ['label' => 'Оплата прошла', 'rich_message' => self::buildPaymentSuccessScreen()],
            'payments' => ['label' => 'Платежи', 'rich_message' => self::buildPaymentsScreen([
                ['date' => '24 сен', 'product' => 'VPN · 1 месяц', 'amount' => '199 ₽'],
                ['date' => '24 авг', 'product' => 'VPN · 1 месяц', 'amount' => '199 ₽'],
                ['date' => '24 июл', 'product' => 'VPN · 3 месяца', 'amount' => '597 ₽'],
            ])],
            'network' => ['label' => 'Состояние сети', 'rich_message' => self::buildNetworkStatusScreen([
                ['name' => 'VPN', 'status' => '🟢 Operational'], ['name' => 'DNS', 'status' => '🟢 Operational'],
                ['name' => 'Payments', 'status' => '🟢 Operational'], ['name' => 'API', 'status' => '🟡 Degraded · demo'],
            ])],
            'referrals' => ['label' => 'Рефералы', 'rich_message' => self::buildReferralScreen([
                'referral_count' => 8, 'paid_referrals' => 5, 'earnings_kopeks' => 124000,
                'link' => 'https://t.me/astracat_bot?start=ref_demo',
            ])],
            'profile' => ['label' => 'Профиль', 'rich_message' => self::buildProfileScreen([
                'email' => 'astracat member', 'telegram_id' => '123456789', 'balance_kopeks' => 0,
            ])],
            'settings' => ['label' => 'Настройки', 'rich_message' => self::buildSettingsScreen([
                ['name' => 'Уведомления', 'value' => 'Включены'], ['name' => 'Автопродление', 'value' => 'Выключено'],
                ['name' => 'Язык', 'value' => 'Русский'],
            ])],
            'support' => ['label' => 'Поддержка', 'rich_message' => self::buildSupportScreen()],
        ];
    }
}
