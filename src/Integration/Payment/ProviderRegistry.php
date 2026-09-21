<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Contracts\HttpClient\HttpClientInterface;
final class ProviderRegistry
{
    /** @var array<string,ProviderInterface> */
    private array $providers = [];
    public function __construct(private HttpClientInterface $http, private array $config) {}
    public function register(ProviderInterface $provider): void
    {
        $this->providers[$provider->id()] = $provider;
    }
    public function get(string $id): ProviderInterface
    {
        if (!isset($this->providers[$id])) throw new BillingError('Платёжный провайдер не найден: '.$id);
        return $this->providers[$id];
    }
    public function has(string $id): bool { return isset($this->providers[$id]); }
    /** Providers that are configured and enabled. */
    public function enabled(): array
    {
        $result = [];
        foreach ($this->providers as $id => $provider) {
            if ($provider->configured()) $result[$id] = $provider;
        }
        return $result;
    }
    public function all(): array { return $this->providers; }
}
