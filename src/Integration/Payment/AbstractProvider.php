<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use App\Infrastructure\CircuitBreaker;
use Symfony\Contracts\HttpClient\HttpClientInterface;
abstract class AbstractProvider implements ProviderInterface
{
    public function __construct(protected HttpClientInterface $http, protected array $config, protected ?CircuitBreaker $breaker = null) {}
    public function name(): string { return $this->id(); }
    public static function decimal(int $minor): string { return intdiv($minor,100).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT); }
    public static function minor(string $decimal): int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,9})\.([0-9]{2})$/D',$decimal,$m)) throw new BillingError('Некорректная сумма.');
        return (int)$m[1]*100+(int)$m[2];
    }
    public static function normalizeAmount(string $amount): string
    {
        $amount=trim($amount);
        if (!preg_match('/^(0|[1-9][0-9]{0,9})(\.([0-9]{1,2}))?$/D',$amount,$m)) throw new BillingError('Некорректная сумма.');
        $frac=$m[3]??'';
        if ($frac==='') $frac='00';
        elseif (strlen($frac)===1) $frac.='0';
        return $m[1].'.'.$frac;
    }

    /** @return string Circuit-breaker key for this provider's upstream API calls. */
    private function breakerName(): string
    {
        return $this->id().'_api';
    }

    /**
     * Single outbound HTTP path shared by every provider. Circuit-breaker is
     * applied here so a failing or slow upstream (Platega, YooKassa, Lava, …)
     * is short-circuited fast once the breaker opens, instead of letting every
     * checkout/verify burn attempts against a dead gateway.
     */
    protected function json(string $method, string $url, array $options = []): array
    {
        $options = array_merge(['timeout'=>10,'max_duration'=>20,'max_redirects'=>0], $options);
        if ($this->breaker !== null && !$this->breaker->allow($this->breakerName())) {
            throw new BillingError($this->name().' временно недоступна. Повторите позже.');
        }
        try {
            $resp = $this->http->request($method, $url, $options)->toArray();
        } catch (\Throwable $e) {
            $this->breaker?->failure($this->breakerName());
            throw $e;
        }
        $this->breaker?->success($this->breakerName());
        return $resp;
    }
}
