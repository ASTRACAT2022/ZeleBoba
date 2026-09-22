<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\{Database,Outbox};
final class LandingService
{
    public function __construct(private Database $db, private Outbox $outbox) {}
    /** Create or update a landing page. */
    public function save(array $input, string $actor): array
    {
        $slug = trim((string)($input['slug'] ?? ''));
        if (!preg_match('/^[a-z0-9-]{2,100}$/D', $slug)) throw new BillingError('Слаг: 2–100 символов a-z, 0-9, -.');
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) throw new BillingError('Заголовок: 1–200 символов.');
        $discount = ($input['discount_percent'] ?? '') !== '' ? (int)$input['discount_percent'] : null;
        if ($discount !== null && ($discount < 1 || $discount > 99)) throw new BillingError('Скидка: 1–99%.');
        $id = Database::id();
        $now = time();
        $this->db->execute(
            'INSERT INTO landing_pages(id,slug,is_active,title,subtitle,features,footer_text,allowed_plan_ids,payment_methods,gift_enabled,custom_css,meta_title,meta_description,display_order,discount_percent,discount_starts_at,discount_ends_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(slug) DO UPDATE SET is_active=excluded.is_active,title=excluded.title,subtitle=excluded.subtitle,features=excluded.features,footer_text=excluded.footer_text,allowed_plan_ids=excluded.allowed_plan_ids,payment_methods=excluded.payment_methods,gift_enabled=excluded.gift_enabled,custom_css=excluded.custom_css,meta_title=excluded.meta_title,meta_description=excluded.meta_description,display_order=excluded.display_order,discount_percent=excluded.discount_percent,discount_starts_at=excluded.discount_starts_at,discount_ends_at=excluded.discount_ends_at',
            [$id, $slug, (int)($input['is_active'] ?? 1), $title, $input['subtitle'] ?? null, $input['features'] ?? null, $input['footer_text'] ?? null, $input['allowed_plan_ids'] ?? null, $input['payment_methods'] ?? null, (int)($input['gift_enabled'] ?? 1), $input['custom_css'] ?? null, $input['meta_title'] ?? null, $input['meta_description'] ?? null, (int)($input['display_order'] ?? 0), $discount, ($input['discount_starts_at'] ?? '') !== '' ? (int)$input['discount_starts_at'] : null, ($input['discount_ends_at'] ?? '') !== '' ? (int)$input['discount_ends_at'] : null, $now]
        );
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'landing.saved', $slug, $now]);
        return $this->db->one('SELECT * FROM landing_pages WHERE slug=?', [$slug]);
    }
    public function get(string $slug): ?array
    {
        return $this->db->one('SELECT * FROM landing_pages WHERE slug=? AND is_active=1', [$slug]);
    }
    public function list(): array
    {
        return $this->db->all('SELECT * FROM landing_pages ORDER BY display_order, created_at');
    }
    public function toggle(string $slug, bool $active, string $actor): void
    {
        $this->db->execute('UPDATE landing_pages SET is_active=? WHERE slug=?', [(int)$active, $slug]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, $active ? 'landing.enabled' : 'landing.disabled', $slug, time()]);
    }
    /** Effective price for a plan on this landing (with discount). */
    public function effectivePrice(array $landing, array $plan): int
    {
        $price = (int)$plan['price_minor'];
        $now = time();
        $discount = (int)($landing['discount_percent'] ?? 0);
        if ($discount > 0) {
            $starts = $landing['discount_starts_at'];
            $ends = $landing['discount_ends_at'];
            if (($starts === null || (int)$starts <= $now) && ($ends === null || (int)$ends >= $now)) {
                $price = intdiv($price * (100 - $discount), 100);
            }
        }
        return $price;
    }
}
