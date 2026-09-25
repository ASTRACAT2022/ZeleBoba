<?php
declare(strict_types=1);
namespace App\Settings;
final class Branding
{
    public function __construct(private array $config) {}
    public function name(): string
    {
        return (string)($this->config['SITE_NAME'] ?? 'ZeleBoba');
    }
    public function logo(): string
    {
        return (string)($this->config['BRAND_LOGO'] ?? '');
    }
    public function favicon(): string
    {
        return (string)($this->config['BRAND_FAVICON'] ?? '');
    }
    public function color(): string
    {
        $c = (string)($this->config['BRAND_COLOR'] ?? '');
        return preg_match('/^#[0-9a-fA-F]{6}$/D', $c) ? $c : '#0f5c46';
    }
    public function accent(): string
    {
        $c = (string)($this->config['BRAND_COLOR_ACCENT'] ?? '');
        return preg_match('/^#[0-9a-fA-F]{6}$/D', $c) ? $c : '#d8f784';
    }
    public function footerText(): string
    {
        return (string)($this->config['BRAND_FOOTER_TEXT'] ?? '');
    }
    public function welcomeText(): string
    {
        $t = (string)($this->config['BRAND_WELCOME_TEXT'] ?? '');
        if ($t !== '') return $t;
        return "Привет! Это дублер веб-кабинета.\nЗдесь можно купить подписку, оплатить и получить доступ — всё как на сайте.\n\nКабинет: ".rtrim((string)($this->config['APP_URL'] ?? ''), '/');
    }
    public function helpText(): string
    {
        $t = (string)($this->config['BRAND_HELP_TEXT'] ?? '');
        if ($t !== '') return $t;
        return "Команды:\n/plans — тарифы\n/buy <id> — купить\n/status — подписки + pending заказы\n/orders — мои заказы\n/subs — мои подписки\n/cabinet — открыть веб-кабинет\n/login — вход в кабинет\n/support — поддержка\n\nКабинет и бот работают в тандеме: заказы и подписки общие.";
    }
    /** CSS-переменные для кабинета. Только брендовые токены — базовая тема
     *  (цвет текста, фон, линии, тёмная тема) живёт в app.css/stripe.css. */
    public function cssVars(): string
    {
        return '--brand-color:'.$this->color().';--brand-accent:'.$this->accent();
    }
}
